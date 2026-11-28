<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
/**
 * Affiliate revenue engine (Phase 7E): partnerships, links, commissions, opportunities and the revenue dashboard for the owner.
 * Built on the existing catalogue (tools_by_id), outbound-click analytics (adm_tool_daily), the tool's own affiliate URL, the CRM audit log and the finance report.
 *
 * Rules: nothing is invented (no partnerships, rates, approvals or estimated earnings); applications are made by a person outside this system; an affiliate link
 * only replaces the official link when the owner activates it, after confirming the program terms; no automated clicking of tracking links; money is per
 * currency and never converted; "approved" is not "received"; zero earnings and unavailable data are different states. Owner only (the 'revenue' permission).
 */
require_once __DIR__ . '/crm_money.php';
require_once __DIR__ . '/aff_public.php';

const AFF_VERIFY_DAYS = 30;
const AFF_FOLLOWUP_DAYS = 14;
const AFF_MAX_IMPORT_ROWS = 2000;
const AFF_MAX_IMPORT_BYTES = 2097152;

function aff_statuses(): array { return ['researching' => 'Researching', 'not_applied' => 'Not applied', 'applied' => 'Applied', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'active' => 'Active', 'suspended' => 'Suspended', 'expired' => 'Expired']; }
function aff_transitions(): array {
  return ['researching' => ['not_applied', 'applied'], 'not_applied' => ['researching', 'applied'], 'applied' => ['pending', 'approved', 'rejected'], 'pending' => ['applied', 'approved', 'rejected'], 'approved' => ['active', 'suspended', 'expired'],
    'active' => ['suspended', 'expired'], 'suspended' => ['active', 'expired'], 'rejected' => ['researching'], 'expired' => ['researching', 'applied']];
}
function aff_commission_types(): array { return ['unknown' => 'Unknown (not verified)', 'recurring' => 'Recurring', 'one_time' => 'One-time']; }
function aff_commission_statuses(): array { return ['pending' => 'Pending', 'approved' => 'Approved (not yet received)', 'paid' => 'Paid (received)', 'rejected' => 'Rejected', 'reversed' => 'Reversed']; }
function aff_commission_transitions(): array { return ['pending' => ['approved', 'rejected'], 'approved' => ['paid', 'reversed'], 'paid' => ['reversed'], 'rejected' => [], 'reversed' => []]; }
function aff_deny(?array $user): bool { return !crm_can($user['role'] ?? null, 'revenue'); }
function aff_ready(PDO $pdo): bool {
  static $memo = []; $k = spl_object_id($pdo); if (isset($memo[$k])) return $memo[$k];
  try { foreach (['aff_network', 'aff_partner', 'aff_partner_history', 'aff_link', 'aff_link_history', 'aff_commission', 'aff_commission_event'] as $t) $pdo->query("SELECT 1 FROM $t LIMIT 1"); return $memo[$k] = true; } catch (Throwable $e) { return false; }
}
function aff_catalogue(): array { try { return function_exists('tools_by_id') ? tools_by_id() : []; } catch (Throwable $e) { return []; } }
function aff_today(?int $nowTs = null): string { return gmdate('Y-m-d', $nowTs ?? time()); }
function aff_date(string $s, ?int $nowTs = null, bool $allowFuture = false): ?string { $t = crm_date($s); if ($t === null) return null; $d = gmdate('Y-m-d', $t); return (!$allowFuture && $d > aff_today($nowTs)) ? null : $d; }

// ---- link validation (pure, structural; no network call is ever made) ------------------------------------------------------------------------------
/** Problems with an affiliate URL as typed. An empty list means structurally acceptable (it does NOT prove the link works: a person verifies that in a browser). */
function aff_link_problems(string $url, string $official = ''): array {
  $u = trim($url); $p = [];
  if ($u === '') return ['The link is empty.']; if (strlen($u) > 1000) $p[] = 'The link is longer than 1000 characters.';
  if (sec_safe_external_url($u) === null) return array_merge($p, ['The link is not a plain public http(s) URL (no credentials, spaces, quotes or odd schemes).']);
  $parts = parse_url($u); $host = strtolower((string)($parts['host'] ?? ''));
  if (strtolower((string)($parts['scheme'] ?? '')) !== 'https') $p[] = 'Use https: an http affiliate link is not served to visitors.';
  if (filter_var($host, FILTER_VALIDATE_IP) || $host === 'localhost' || !str_contains($host, '.')) $p[] = 'The host must be a public domain name, not an IP address or a local name.';
  if (in_array($host, ['bit.ly', 'tinyurl.com', 't.co', 'goo.gl', 'ow.ly', 'is.gd', 'buff.ly', 'rebrand.ly', 'cutt.ly'], true)) $p[] = 'URL shorteners hide the destination and are not allowed (no cloaking). Use the link your program provides.';
  if ($official !== '' && crm_domain($official) !== '' && crm_domain($u) === crm_domain($official) && !isset($parts['query'])) $p[] = 'This is the official website without any tracking parameter, so it is not an affiliate link.';
  return $p;
}

// ---- networks ---------------------------------------------------------------------------------------------------------------------------------
function aff_networks(PDO $pdo): array { return aff_ready($pdo) ? $pdo->query('SELECT * FROM aff_network ORDER BY active DESC, name')->fetchAll(PDO::FETCH_ASSOC) : []; }
function aff_network_add(PDO $pdo, ?array $user, string $name, string $website = '', string $subidParam = ''): array {
  if (aff_deny($user)) return [false, 'forbidden']; if (!aff_ready($pdo)) return [false, 'not_migrated']; $name = crm_line($name, 80); if ($name === '') return [false, 'name_required']; $code = substr(strtolower(preg_replace('/[^A-Za-z0-9]+/', '', $name)), 0, 24); if ($code === '') return [false, 'name_required'];
  $w = trim($website); if ($w !== '' && sec_safe_external_url($w) === null) return [false, 'website_invalid']; $sp = trim($subidParam); if ($sp !== '' && !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $sp)) return [false, 'subid_param_invalid'];
  $s = $pdo->prepare('INSERT IGNORE INTO aff_network (code, name, website, subid_param, created_at) VALUES (?,?,?,?,?)'); $s->execute([$code, $name, $w, $sp, crm_now()]); if ($s->rowCount() !== 1) return [false, 'network_exists'];
  crm_audit($pdo, $user, 'aff_network_add', 'affiliate', (int)$pdo->lastInsertId(), $name); return [true, (string)$pdo->lastInsertId()];
}
/** The parameter name a network uses for a per-link tracking id (e.g. its "sub id"). Only the owner enters it, from the network's own documentation: it is never guessed. */
function aff_network_set_subid(PDO $pdo, ?array $user, int $id, string $param): array {
  if (aff_deny($user)) return [false, 'forbidden']; if (!aff_ready($pdo)) return [false, 'not_migrated']; $param = trim($param); if ($param !== '' && !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $param)) return [false, 'subid_param_invalid'];
  $s = $pdo->prepare('UPDATE aff_network SET subid_param = ? WHERE id = ?'); $s->execute([$param, $id]); $ok = $s->rowCount() === 1 || (bool)$pdo->query('SELECT 1 FROM aff_network WHERE id = ' . (int)$id)->fetchColumn(); if (!$ok) return [false, 'not_found'];
  aff_public_cache_clear(); crm_audit($pdo, $user, 'aff_network_subid', 'affiliate', $id, 'tracking parameter set'); return [true, 'ok'];
}

// ---- partnerships -----------------------------------------------------------------------------------------------------------------------------
function aff_partner_clean(array $in, ?int $nowTs = null): array {
  $company = crm_line($in['company'] ?? '', 190); if ($company === '') return [false, 'company_required'];
  $tool = trim((string)($in['tool_slug'] ?? '')); if ($tool !== '' && !isset(aff_catalogue()[$tool])) return [false, 'tool_unknown'];
  $net = ($in['network_id'] ?? '') === '' ? null : (int)$in['network_id']; $type = (string)($in['commission_type'] ?? 'unknown') ?: 'unknown'; if (!isset(aff_commission_types()[$type])) return [false, 'commission_type_invalid'];
  $cur = trim((string)($in['commission_currency'] ?? '')); if ($cur !== '' && !crm_currency_ok($cur)) return [false, 'currency_invalid'];
  $cookie = trim((string)($in['cookie_days'] ?? '')); if ($cookie !== '' && (!preg_match('/^\d{1,4}$/', $cookie) || (int)$cookie > 3650)) return [false, 'cookie_days_invalid'];
  $thr = trim((string)($in['payment_threshold'] ?? '')); $thrMinor = null; if ($thr !== '') { $thrMinor = crm_money_parse($thr); if ($thrMinor === null) return [false, 'threshold_invalid']; if ($cur === '') return [false, 'currency_required_with_amount']; }
  foreach (['application_url', 'program_url'] as $k) { $v = trim((string)($in[$k] ?? '')); if ($v !== '' && sec_safe_external_url($v) === null) return [false, $k . '_invalid']; }
  $ver = null; if (trim((string)($in['verified_at'] ?? '')) !== '') { $ver = aff_date((string)$in['verified_at'], $nowTs); if ($ver === null) return [false, 'verified_at_invalid']; }
  $fu = null; if (trim((string)($in['follow_up_at'] ?? '')) !== '') { $fu = aff_date((string)$in['follow_up_at'], $nowTs, true); if ($fu === null) return [false, 'follow_up_invalid']; }
  $chk = null; if (trim((string)($in['terms_checked_at'] ?? '')) !== '') { $chk = aff_date((string)$in['terms_checked_at'], $nowTs); if ($chk === null) return [false, 'terms_checked_invalid']; }
  if (($ver !== null || $chk !== null) && crm_line($in['verified_source'] ?? '', 255) === '' && $ver !== null) return [false, 'verified_source_required'];
  return [true, ['company' => $company, 'tool_slug' => $tool, 'network_id' => $net, 'application_url' => trim((string)($in['application_url'] ?? '')), 'program_url' => trim((string)($in['program_url'] ?? '')), 'commission_structure' => crm_line($in['commission_structure'] ?? '', 255), 'commission_type' => $type, 'commission_currency' => $cur === '' ? null : $cur,
    'cookie_days' => $cookie === '' ? null : (int)$cookie, 'payment_threshold_minor' => $thrMinor, 'payment_schedule' => crm_line($in['payment_schedule'] ?? '', 120), 'terms' => crm_text($in['terms'] ?? '', 4000), 'restrictions' => crm_text($in['restrictions'] ?? '', 4000), 'terms_checked_at' => $chk, 'verified_at' => $ver,
    'verified_source' => crm_line($in['verified_source'] ?? '', 255), 'follow_up_at' => $fu, 'notes' => crm_text($in['notes'] ?? '', 4000)]];
}
function aff_partner_get(PDO $pdo, int $id): ?array { if (!aff_ready($pdo)) return null; $s = $pdo->prepare('SELECT p.*, n.name AS network_name, n.code AS network_code, n.subid_param FROM aff_partner p LEFT JOIN aff_network n ON n.id = p.network_id WHERE p.id = ?'); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC) ?: null; }
function aff_partners_list(PDO $pdo, array $f = []): array {
  if (!aff_ready($pdo)) return []; $w = []; $a = []; if (!empty($f['status']) && isset(aff_statuses()[$f['status']])) { $w[] = 'p.status = ?'; $a[] = $f['status']; } if (!empty($f['network'])) { $w[] = 'p.network_id = ?'; $a[] = (int)$f['network']; } if (!empty($f['q'])) { $w[] = '(p.company LIKE ? OR p.tool_slug LIKE ?)'; $l = '%' . addcslashes(crm_text($f['q'], 80), '%_\\') . '%'; array_push($a, $l, $l); }
  $s = $pdo->prepare('SELECT p.*, n.name AS network_name FROM aff_partner p LEFT JOIN aff_network n ON n.id = p.network_id' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY p.id DESC LIMIT 500'); $s->execute($a); return $s->fetchAll(PDO::FETCH_ASSOC);
}
function aff_partner_create(PDO $pdo, ?array $user, array $in): array {
  if (aff_deny($user)) return [false, 'forbidden']; if (!aff_ready($pdo)) return [false, 'not_migrated']; [$ok, $c] = aff_partner_clean($in); if (!$ok) return [false, (string)$c];
  $d = $pdo->prepare('SELECT id FROM aff_partner WHERE LOWER(company) = LOWER(?) AND tool_slug = ? AND COALESCE(network_id, 0) = ?'); $d->execute([$c['company'], $c['tool_slug'], (int)$c['network_id']]); if ($d->fetchColumn()) return [false, 'duplicate_partner'];
  $now = crm_now(); $pdo->prepare("INSERT INTO aff_partner (tool_slug, company, network_id, status, application_url, program_url, commission_structure, commission_type, commission_currency, cookie_days, payment_threshold_minor, payment_schedule, terms, restrictions, terms_checked_at, verified_at, verified_source, follow_up_at, notes, created_by, created_at, updated_at) VALUES (?,?,?,'researching',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([$c['tool_slug'], $c['company'], $c['network_id'], $c['application_url'], $c['program_url'], $c['commission_structure'], $c['commission_type'], $c['commission_currency'], $c['cookie_days'], $c['payment_threshold_minor'], $c['payment_schedule'], $c['terms'], $c['restrictions'], $c['terms_checked_at'], $c['verified_at'], $c['verified_source'], $c['follow_up_at'], $c['notes'], $user['id'] ?? null, $now, $now]);
  $id = (int)$pdo->lastInsertId(); $pdo->prepare('INSERT INTO aff_partner_history (partner_id, from_status, to_status, note, changed_by, changed_at) VALUES (?,?,?,?,?,?)')->execute([$id, null, 'researching', 'Recorded', $user['id'] ?? null, $now]); crm_audit($pdo, $user, 'aff_partner_create', 'affiliate', $id, $c['company']); return [true, (string)$id];
}
function aff_partner_update(PDO $pdo, ?array $user, int $id, array $in): array {
  if (aff_deny($user)) return [false, 'forbidden']; if (!aff_ready($pdo)) return [false, 'not_migrated']; if (!aff_partner_get($pdo, $id)) return [false, 'not_found']; [$ok, $c] = aff_partner_clean($in); if (!$ok) return [false, (string)$c];
  $pdo->prepare('UPDATE aff_partner SET tool_slug=?, company=?, network_id=?, application_url=?, program_url=?, commission_structure=?, commission_type=?, commission_currency=?, cookie_days=?, payment_threshold_minor=?, payment_schedule=?, terms=?, restrictions=?, terms_checked_at=?, verified_at=?, verified_source=?, follow_up_at=?, notes=?, updated_at=? WHERE id=?')
    ->execute([$c['tool_slug'], $c['company'], $c['network_id'], $c['application_url'], $c['program_url'], $c['commission_structure'], $c['commission_type'], $c['commission_currency'], $c['cookie_days'], $c['payment_threshold_minor'], $c['payment_schedule'], $c['terms'], $c['restrictions'], $c['terms_checked_at'], $c['verified_at'], $c['verified_source'], $c['follow_up_at'], $c['notes'], crm_now(), $id]);
  aff_public_cache_clear(); crm_audit($pdo, $user, 'aff_partner_update', 'affiliate', $id, $c['company']); return [true, 'ok'];
}
/** Status change by a person. Approved, active, rejected, suspended and expired need a note naming the source (e.g. "approval e-mail from the network, 2026-12-01"): approvals are never assumed. Suspended, expired or rejected partnerships switch their links off (official-link fallback). */
function aff_partner_move(PDO $pdo, ?array $user, int $id, string $to, string $note = '', ?int $nowTs = null): array {
  if (aff_deny($user)) return [false, 'forbidden']; if (!aff_ready($pdo)) return [false, 'not_migrated']; $p = aff_partner_get($pdo, $id); if (!$p) return [false, 'not_found'];
  if (!isset(aff_statuses()[$to]) || !in_array($to, aff_transitions()[$p['status']] ?? [], true)) return [false, 'transition_invalid']; $note = crm_line($note, 255); if (in_array($to, ['approved', 'active', 'rejected', 'suspended', 'expired'], true) && $note === '') return [false, 'note_required'];
  $own = !$pdo->inTransaction(); if ($own) $pdo->beginTransaction();
  try {
    $pdo->prepare('UPDATE aff_partner SET status = ?, applied_at = COALESCE(applied_at, ?), updated_at = ? WHERE id = ?')->execute([$to, $to === 'applied' ? aff_today($nowTs) : null, crm_now($nowTs), $id]);
    $pdo->prepare('INSERT INTO aff_partner_history (partner_id, from_status, to_status, note, changed_by, changed_at) VALUES (?,?,?,?,?,?)')->execute([$id, $p['status'], $to, $note, $user['id'] ?? null, crm_now($nowTs)]);
    $off = 0; if (in_array($to, ['suspended', 'expired', 'rejected'], true)) { $ls = $pdo->prepare("SELECT id, url FROM aff_link WHERE partner_id = ? AND status = 'active'"); $ls->execute([$id]); foreach ($ls->fetchAll(PDO::FETCH_ASSOC) as $l) { aff_link_set_inactive($pdo, $user, (int)$l['id'], 'Partnership ' . $to . ': official link restored', $nowTs); $off++; } }
    crm_audit($pdo, $user, 'aff_partner_' . $to, 'affiliate', $id, $p['status'] . ' -> ' . $to . ($off ? '; ' . $off . ' link(s) switched off' : '')); if ($own) $pdo->commit(); aff_public_cache_clear(); return [true, 'ok'];
  } catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); sec_log_exception($e, 'aff_partner_move'); return [false, 'storage_error']; }
}

// ---- links ------------------------------------------------------------------------------------------------------------------------------------
function aff_link_get(PDO $pdo, int $id): ?array { if (!aff_ready($pdo)) return null; $s = $pdo->prepare('SELECT l.*, p.status AS partner_status, p.company, p.terms_checked_at, n.subid_param FROM aff_link l JOIN aff_partner p ON p.id = l.partner_id LEFT JOIN aff_network n ON n.id = p.network_id WHERE l.id = ?'); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC) ?: null; }
function aff_links_list(PDO $pdo, ?int $partnerId = null): array { if (!aff_ready($pdo)) return []; $s = $pdo->prepare('SELECT l.*, p.status AS partner_status, p.company, n.subid_param FROM aff_link l JOIN aff_partner p ON p.id = l.partner_id LEFT JOIN aff_network n ON n.id = p.network_id' . ($partnerId ? ' WHERE l.partner_id = ?' : '') . ' ORDER BY l.id DESC LIMIT 500'); $s->execute($partnerId ? [$partnerId] : []); return $s->fetchAll(PDO::FETCH_ASSOC); }
function aff_link_history(PDO $pdo, int $linkId): array { $s = $pdo->prepare('SELECT h.*, u.display_name FROM aff_link_history h LEFT JOIN crm_users u ON u.id = h.changed_by WHERE h.link_id = ? ORDER BY h.id DESC'); $s->execute([$linkId]); return $s->fetchAll(PDO::FETCH_ASSOC); }
function aff_link_log(PDO $pdo, ?array $user, int $id, string $action, ?string $old, ?string $new, string $note, ?int $nowTs = null): void { $pdo->prepare('INSERT INTO aff_link_history (link_id, action, old_url, new_url, note, changed_by, changed_at) VALUES (?,?,?,?,?,?,?)')->execute([$id, $action, $old, $new, crm_line($note, 255), $user['id'] ?? null, crm_now($nowTs)]); }
function aff_tracking_ok(string $t): bool { return $t === '' || (bool)preg_match('/^[A-Za-z0-9_-]{1,40}$/', $t); }
/** Records a link as INACTIVE. Nothing changes for visitors until the owner activates it. */
function aff_link_add(PDO $pdo, ?array $user, int $partnerId, string $url, string $trackingId = '', ?int $nowTs = null): array {
  if (aff_deny($user)) return [false, 'forbidden']; if (!aff_ready($pdo)) return [false, 'not_migrated']; $p = aff_partner_get($pdo, $partnerId); if (!$p) return [false, 'not_found']; if ($p['tool_slug'] === '') return [false, 'partner_needs_tool'];
  $tool = aff_catalogue()[$p['tool_slug']] ?? null; $pr = aff_link_problems($url, (string)($tool['website'] ?? '')); if ($pr) return [false, 'link_invalid']; if (!aff_tracking_ok(trim($trackingId))) return [false, 'tracking_id_invalid'];
  $now = crm_now($nowTs); $pdo->prepare("INSERT INTO aff_link (partner_id, tool_slug, url, tracking_id, status, created_by, created_at, updated_at) VALUES (?,?,?,?, 'inactive', ?,?,?)")->execute([$partnerId, $p['tool_slug'], trim($url), trim($trackingId), $user['id'] ?? null, $now, $now]); $id = (int)$pdo->lastInsertId();
  aff_link_log($pdo, $user, $id, 'created', null, trim($url), 'Recorded (inactive)', $nowTs); crm_audit($pdo, $user, 'aff_link_add', 'affiliate', $id, $p['tool_slug']); return [true, (string)$id];
}
/** Changing the URL or tracking id keeps the old value in the history and clears the verification (the link must be checked again). */
function aff_link_update(PDO $pdo, ?array $user, int $id, string $url, string $trackingId = '', ?int $nowTs = null): array {
  if (aff_deny($user)) return [false, 'forbidden']; $l = aff_link_get($pdo, $id); if (!$l) return [false, 'not_found']; $tool = aff_catalogue()[$l['tool_slug']] ?? null; if (aff_link_problems($url, (string)($tool['website'] ?? ''))) return [false, 'link_invalid']; if (!aff_tracking_ok(trim($trackingId))) return [false, 'tracking_id_invalid'];
  if (trim($url) === $l['url'] && trim($trackingId) === $l['tracking_id']) return [true, 'unchanged'];
  $pdo->prepare('UPDATE aff_link SET url = ?, tracking_id = ?, verify_result = NULL, last_verified_at = NULL, verify_note = ?, updated_at = ? WHERE id = ?')->execute([trim($url), trim($trackingId), '', crm_now($nowTs), $id]); aff_link_log($pdo, $user, $id, 'changed', $l['url'], trim($url), 'URL or tracking id changed; verification cleared', $nowTs); aff_public_cache_clear(); crm_audit($pdo, $user, 'aff_link_update', 'affiliate', $id, $l['tool_slug']); return [true, 'ok'];
}
function aff_link_set_inactive(PDO $pdo, ?array $user, int $id, string $note, ?int $nowTs = null): void { $pdo->prepare("UPDATE aff_link SET status = 'inactive', active_tool = NULL, updated_at = ? WHERE id = ?")->execute([crm_now($nowTs), $id]); aff_link_log($pdo, $user, $id, 'deactivated', null, null, $note, $nowTs); crm_audit($pdo, $user, 'aff_link_deactivate', 'affiliate', $id, $note); aff_public_cache_clear(); }
/**
 * APPROVAL STEP. Replaces the official link for visitors, so it needs: the owner, a partnership that is approved or active, program terms the owner has read (terms_checked_at),
 * a structurally valid link, and the explicit confirmation flag. At most one link per tool can be active (database unique key); another active link must be switched off first.
 */
function aff_link_activate(PDO $pdo, ?array $user, int $id, bool $confirmed, ?int $nowTs = null): array {
  if (aff_deny($user)) return [false, 'forbidden']; if (!$confirmed) return [false, 'confirmation_required']; $l = aff_link_get($pdo, $id); if (!$l) return [false, 'not_found']; if ($l['status'] === 'active') return [false, 'already_active'];
  if (!in_array($l['partner_status'], ['approved', 'active'], true)) return [false, 'partner_not_approved']; if (empty($l['terms_checked_at'])) return [false, 'terms_not_checked'];
  $tool = aff_catalogue()[$l['tool_slug']] ?? null; if (!$tool) return [false, 'tool_unknown']; if (aff_link_problems($l['url'], (string)($tool['website'] ?? ''))) return [false, 'link_invalid']; if (($l['verify_result'] ?? '') === 'broken') return [false, 'link_marked_broken'];
  $o = $pdo->prepare("SELECT id FROM aff_link WHERE active_tool = ? AND id <> ?"); $o->execute([$l['tool_slug'], $id]); if ($o->fetchColumn()) return [false, 'another_active_link'];
  try { $pdo->prepare("UPDATE aff_link SET status = 'active', active_tool = ?, activated_at = ?, updated_at = ? WHERE id = ? AND status <> 'active'")->execute([$l['tool_slug'], crm_now($nowTs), crm_now($nowTs), $id]); } catch (Throwable $e) { return [false, 'another_active_link']; }
  aff_link_log($pdo, $user, $id, 'activated', null, $l['url'], 'Approved by the owner; replaces the official link for this tool', $nowTs); crm_audit($pdo, $user, 'aff_link_activate', 'affiliate', $id, $l['tool_slug']); aff_public_cache_clear(); return [true, 'ok'];
}
function aff_link_deactivate(PDO $pdo, ?array $user, int $id, string $note = '', ?int $nowTs = null): array { if (aff_deny($user)) return [false, 'forbidden']; $l = aff_link_get($pdo, $id); if (!$l) return [false, 'not_found']; if ($l['status'] !== 'active') return [false, 'not_active']; aff_link_set_inactive($pdo, $user, $id, crm_line($note, 255) ?: 'Switched off by the owner: visitors get the official link again', $nowTs); return [true, 'ok']; }
/** A person opened the link in a browser and records what they saw. No server-side request is made (it could count as a click with the network). A broken result switches an active link off. */
function aff_link_verify(PDO $pdo, ?array $user, int $id, string $result, string $note = '', ?int $nowTs = null): array {
  if (aff_deny($user)) return [false, 'forbidden']; if (!in_array($result, ['working', 'broken'], true)) return [false, 'result_invalid']; $l = aff_link_get($pdo, $id); if (!$l) return [false, 'not_found']; $note = crm_line($note, 255); if ($result === 'broken' && $note === '') return [false, 'note_required'];
  $pdo->prepare('UPDATE aff_link SET verify_result = ?, last_verified_at = ?, verify_note = ?, updated_at = ? WHERE id = ?')->execute([$result, aff_today($nowTs), $note, crm_now($nowTs), $id]); aff_link_log($pdo, $user, $id, 'verified_' . $result, null, null, $note, $nowTs);
  if ($result === 'broken' && $l['status'] === 'active') aff_link_set_inactive($pdo, $user, $id, 'Reported broken: official link restored. ' . $note, $nowTs); crm_audit($pdo, $user, 'aff_link_verify', 'affiliate', $id, $result); return [true, 'ok'];
}

// ---- commissions ------------------------------------------------------------------------------------------------------------------------------
function aff_commission_clean(PDO $pdo, array $in, ?int $nowTs = null): array {
  $pid = (int)($in['partner_id'] ?? 0); $p = $pid ? aff_partner_get($pdo, $pid) : null; if (!$p) return [false, 'partner_unknown']; $ref = crm_line($in['transaction_ref'] ?? '', 120); if ($ref === '') return [false, 'reference_required'];
  $d = aff_date((string)($in['transaction_date'] ?? ''), $nowTs); if ($d === null) return [false, 'date_invalid']; $m = crm_money_parse((string)($in['amount'] ?? '')); if ($m === null || $m <= 0 || $m > 100000000000) return [false, 'amount_invalid']; $cur = (string)($in['currency'] ?? ''); if (!crm_currency_ok($cur)) return [false, 'currency_invalid'];
  $st = (string)($in['status'] ?? 'pending') ?: 'pending'; if (!isset(aff_commission_statuses()[$st])) return [false, 'status_invalid']; $tool = trim((string)($in['tool_slug'] ?? '')) ?: $p['tool_slug']; if ($tool !== '' && !isset(aff_catalogue()[$tool])) return [false, 'tool_unknown'];
  $sd = null; if ($st === 'paid' || $st === 'reversed') { $sd = aff_date((string)($in['status_date'] ?? ''), $nowTs); if ($sd === null) return [false, $st === 'paid' ? 'paid_date_required' : 'reversed_date_required']; if ($sd < $d) return [false, 'status_date_before_transaction']; }
  return [true, ['partner_id' => $pid, 'tool_slug' => $tool, 'ref' => $ref, 'date' => $d, 'amount' => $m, 'currency' => $cur, 'status' => $st, 'status_date' => $sd, 'payout_ref' => crm_line($in['payout_ref'] ?? '', 120), 'note' => crm_line($in['note'] ?? '', 255)]];
}
function aff_commission_add(PDO $pdo, ?array $user, array $in, string $source = 'manual', ?int $nowTs = null): array {
  if (aff_deny($user)) return [false, 'forbidden']; if (!aff_ready($pdo)) return [false, 'not_migrated']; [$ok, $c] = aff_commission_clean($pdo, $in, $nowTs); if (!$ok) return [false, (string)$c]; $now = crm_now($nowTs);
  $own = !$pdo->inTransaction(); if ($own) $pdo->beginTransaction();
  try {
    $s = $pdo->prepare('INSERT IGNORE INTO aff_commission (partner_id, tool_slug, transaction_ref, transaction_date, amount_minor, currency, status, paid_at, reversed_at, payout_ref, source, note, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $s->execute([$c['partner_id'], $c['tool_slug'], $c['ref'], $c['date'], $c['amount'], $c['currency'], $c['status'], $c['status'] === 'paid' ? $c['status_date'] : null, $c['status'] === 'reversed' ? $c['status_date'] : null, $c['payout_ref'], $source === 'csv' ? 'csv' : 'manual', $c['note'], $user['id'] ?? null, $now, $now]);
    if ($s->rowCount() !== 1) { if ($own) $pdo->rollBack(); return [false, 'duplicate_transaction']; } $id = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO aff_commission_event (commission_id, from_status, to_status, note, changed_by, changed_at) VALUES (?,?,?,?,?,?)')->execute([$id, null, $c['status'], 'Recorded (' . $source . ')', $user['id'] ?? null, $now]); if ($own) { crm_audit($pdo, $user, 'aff_commission_add', 'affiliate', $id, $c['ref'] . ' ' . $c['status']); $pdo->commit(); } return [true, (string)$id];
  } catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); sec_log_exception($e, 'aff_commission_add'); return [false, 'storage_error']; }
}
/** Moves a commission along pending -> approved -> paid (money received) or to rejected / reversed. Paid and reversed need their date; a commission cannot be paid before it was approved. */
function aff_commission_move(PDO $pdo, ?array $user, int $id, string $to, string $date = '', string $note = '', ?int $nowTs = null): array {
  if (aff_deny($user)) return [false, 'forbidden']; if (!aff_ready($pdo)) return [false, 'not_migrated']; $s = $pdo->prepare('SELECT * FROM aff_commission WHERE id = ?'); $s->execute([$id]); $c = $s->fetch(PDO::FETCH_ASSOC); if (!$c) return [false, 'not_found'];
  if (!isset(aff_commission_statuses()[$to]) || !in_array($to, aff_commission_transitions()[$c['status']] ?? [], true)) return [false, 'transition_invalid']; $d = null;
  if ($to === 'paid' || $to === 'reversed') { $d = aff_date($date, $nowTs); if ($d === null) return [false, $to === 'paid' ? 'paid_date_required' : 'reversed_date_required']; if ($d < $c['transaction_date']) return [false, 'status_date_before_transaction']; }
  $note = crm_line($note, 255); if (in_array($to, ['rejected', 'reversed'], true) && $note === '') return [false, 'note_required'];
  $pdo->prepare('UPDATE aff_commission SET status = ?, paid_at = ?, reversed_at = ?, updated_at = ? WHERE id = ?')->execute([$to, $to === 'paid' ? $d : $c['paid_at'], $to === 'reversed' ? $d : $c['reversed_at'], crm_now($nowTs), $id]);
  $pdo->prepare('INSERT INTO aff_commission_event (commission_id, from_status, to_status, note, changed_by, changed_at) VALUES (?,?,?,?,?,?)')->execute([$id, $c['status'], $to, $note, $user['id'] ?? null, crm_now($nowTs)]); crm_audit($pdo, $user, 'aff_commission_' . $to, 'affiliate', $id, $c['transaction_ref']); return [true, 'ok'];
}
function aff_commissions_list(PDO $pdo, array $f = [], int $limit = 300): array {
  if (!aff_ready($pdo)) return []; $w = []; $a = []; if (!empty($f['status']) && isset(aff_commission_statuses()[$f['status']])) { $w[] = 'c.status = ?'; $a[] = $f['status']; } if (!empty($f['partner'])) { $w[] = 'c.partner_id = ?'; $a[] = (int)$f['partner']; }
  $s = $pdo->prepare('SELECT c.*, p.company FROM aff_commission c JOIN aff_partner p ON p.id = c.partner_id' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY c.transaction_date DESC, c.id DESC LIMIT ' . max(1, min(1000, $limit))); $s->execute($a); return $s->fetchAll(PDO::FETCH_ASSOC);
}

// ---- CSV import / export -----------------------------------------------------------------------------------------------------------------------
function aff_csv_columns(): array { return ['transaction_ref', 'partner', 'tool', 'transaction_date', 'commission_amount', 'currency', 'status', 'status_date', 'payout_ref', 'note']; }
function aff_csv_cell($v): string { $s = (string)$v; return $s !== '' && strpos("=+-@\t\r", $s[0]) !== false ? "'" . $s : $s; }
function aff_csv_parse(string $csv): array {
  if (strlen($csv) > AFF_MAX_IMPORT_BYTES) return [false, 'csv_too_large']; $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv; if (!mb_check_encoding($csv, 'UTF-8')) return [false, 'csv_encoding']; $first = strtok($csv, "\n") ?: ''; $d = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
  $f = fopen('php://temp', 'w+'); fwrite($f, $csv); rewind($f); $head = fgetcsv($f, 0, $d, '"', '\\'); if (!$head) { fclose($f); return [false, 'csv_empty']; } $head = array_map(fn($x) => strtolower(trim(str_replace([' ', '-'], '_', (string)$x))), $head); $rows = []; $line = 1;
  while (($r = fgetcsv($f, 0, $d, '"', '\\')) !== false) { $line++; if (!array_filter($r, fn($x) => trim((string)$x) !== '')) continue; $a = []; foreach ($head as $i => $k) if ($k !== '') $a[$k] = trim((string)($r[$i] ?? '')); $a['_line'] = $line; $rows[] = $a; if (count($rows) > AFF_MAX_IMPORT_ROWS) { fclose($f); return [false, 'csv_too_many_rows']; } }
  fclose($f); return [true, ['header' => $head, 'rows' => $rows]];
}
/**
 * Dry run by default. New transactions are created; a transaction whose (partner, reference) already exists is NEVER created twice: in mode 'new' it is skipped as a duplicate,
 * in mode 'update' its status may advance along the allowed transitions (amount and currency must match, otherwise it is reported as a conflict and left unchanged).
 * The partner column takes a partner name or "id:12"; an ambiguous or unknown partner is an error.
 */
function aff_commission_import(PDO $pdo, ?array $user, string $csv, bool $dry = true, string $mode = 'new', ?int $nowTs = null): array {
  if (aff_deny($user)) return ['ok' => false, 'error' => 'forbidden']; if (!aff_ready($pdo)) return ['ok' => false, 'error' => 'not_migrated']; [$ok, $d] = aff_csv_parse($csv); if (!$ok) return ['ok' => false, 'error' => (string)$d];
  foreach (['transaction_ref', 'partner', 'transaction_date', 'commission_amount', 'currency'] as $need) if (!in_array($need, $d['header'], true)) return ['ok' => false, 'error' => 'csv_column_missing_' . $need]; if (!in_array($mode, ['new', 'update'], true)) $mode = 'new';
  $byName = []; foreach ($pdo->query('SELECT id, company FROM aff_partner')->fetchAll(PDO::FETCH_ASSOC) as $p) $byName[mb_strtolower($p['company'])][] = (int)$p['id'];
  $res = ['ok' => true, 'dry' => $dry, 'mode' => $mode, 'rows' => [], 'created' => 0, 'updated' => 0, 'duplicates' => 0, 'errors' => 0]; $seen = [];
  foreach ($d['rows'] as $r) {
    $line = (int)$r['_line']; $ref = (string)($r['transaction_ref'] ?? ''); $add = function (string $st, string $msg = '') use (&$res, $line, $ref) { $res['rows'][] = ['line' => $line, 'ref' => $ref, 'result' => $st, 'message' => $msg]; if ($st === 'duplicate') $res['duplicates']++; elseif ($st === 'error') $res['errors']++; elseif ($st === 'update' || $st === 'updated') $res['updated']++; else $res['created']++; };
    $pn = trim((string)($r['partner'] ?? '')); $pid = 0; if (preg_match('/^id:(\d+)$/i', $pn, $m)) $pid = (int)$m[1]; else { $c = $byName[mb_strtolower($pn)] ?? []; if (count($c) > 1) { $add('error', 'partner_ambiguous'); continue; } $pid = $c[0] ?? 0; }
    $in = ['partner_id' => $pid, 'transaction_ref' => $ref, 'tool_slug' => $r['tool'] ?? '', 'transaction_date' => $r['transaction_date'] ?? '', 'amount' => $r['commission_amount'] ?? '', 'currency' => strtoupper((string)($r['currency'] ?? '')), 'status' => strtolower((string)($r['status'] ?? '')) ?: 'pending', 'status_date' => $r['status_date'] ?? '', 'payout_ref' => $r['payout_ref'] ?? '', 'note' => $r['note'] ?? ''];
    [$cok, $c] = aff_commission_clean($pdo, $in, $nowTs); if (!$cok) { $add('error', (string)$c); continue; } $key = $c['partner_id'] . '|' . $c['ref']; if (isset($seen[$key])) { $add('duplicate', 'repeated in this file'); continue; } $seen[$key] = 1;
    $e = $pdo->prepare('SELECT * FROM aff_commission WHERE partner_id = ? AND transaction_ref = ?'); $e->execute([$c['partner_id'], $c['ref']]); $ex = $e->fetch(PDO::FETCH_ASSOC);
    if ($ex) {
      if ($mode === 'new') { $add('duplicate', 'already recorded (#' . $ex['id'] . ')'); continue; }
      if ((int)$ex['amount_minor'] !== $c['amount'] || $ex['currency'] !== $c['currency']) { $add('error', 'conflict: amount or currency differs from the recorded transaction'); continue; } if ($ex['status'] === $c['status']) { $add('duplicate', 'already recorded with this status'); continue; }
      if (!in_array($c['status'], aff_commission_transitions()[$ex['status']] ?? [], true)) { $add('error', 'status change ' . $ex['status'] . ' -> ' . $c['status'] . ' is not allowed'); continue; }
      if ($dry) { $add('update', $ex['status'] . ' -> ' . $c['status']); continue; } $x = aff_commission_move($pdo, $user, (int)$ex['id'], $c['status'], (string)$c['status_date'], 'Updated by CSV import', $nowTs); $add($x[0] ? 'updated' : 'error', $x[0] ? $ex['status'] . ' -> ' . $c['status'] : (string)$x[1]); continue;
    }
    if ($dry) { $add('would_create'); continue; } $x = aff_commission_add($pdo, $user, $in, 'csv', $nowTs); $add($x[0] ? 'created' : ($x[1] === 'duplicate_transaction' ? 'duplicate' : 'error'), $x[0] ? '' : (string)$x[1]);
  }
  if (!$dry) crm_audit($pdo, $user, 'aff_commission_import', 'affiliate', null, $res['created'] . ' created, ' . $res['updated'] . ' updated, ' . $res['duplicates'] . ' duplicates, ' . $res['errors'] . ' errors'); return $res;
}
function aff_commission_export_csv(PDO $pdo): string {
  $f = fopen('php://temp', 'w+'); fwrite($f, "\xEF\xBB\xBF"); fputcsv($f, ['id', 'transaction_ref', 'partner', 'tool', 'transaction_date', 'commission_amount', 'currency', 'status', 'status_date', 'payout_ref', 'source', 'note'], ',', '"', '\\');
  foreach (aff_commissions_list($pdo, [], 1000) as $c) fputcsv($f, array_map('aff_csv_cell', [$c['id'], $c['transaction_ref'], $c['company'], $c['tool_slug'], $c['transaction_date'], number_format($c['amount_minor'] / 100, 2, '.', ''), $c['currency'], $c['status'], $c['status'] === 'paid' ? $c['paid_at'] : ($c['status'] === 'reversed' ? $c['reversed_at'] : ''), $c['payout_ref'], $c['source'], $c['note']]), ',', '"', '\\'); rewind($f); $o = (string)stream_get_contents($f); fclose($f); return $o;
}

// ---- finance feed (income actually received; commissions are never also entered as contract revenue) -------------------------------------------------
/** Rows for the finance report: money received (status paid) in the month it was paid, and later reversals as negative income in the month reversed. @return array<int,array{currency:string,amount_minor:int,date:string,kind:string}> */
function aff_finance_rows(PDO $pdo): array {
  if (!aff_ready($pdo)) return []; $o = [];
  foreach ($pdo->query("SELECT currency, amount_minor, paid_at, reversed_at, status FROM aff_commission WHERE (status = 'paid' AND paid_at IS NOT NULL) OR (status = 'reversed' AND paid_at IS NOT NULL)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $o[] = ['currency' => $r['currency'], 'amount_minor' => (int)$r['amount_minor'], 'date' => $r['paid_at'], 'kind' => 'received']; if ($r['status'] === 'reversed' && $r['reversed_at'] !== null) $o[] = ['currency' => $r['currency'], 'amount_minor' => (int)$r['amount_minor'], 'date' => $r['reversed_at'], 'kind' => 'reversal'];
  }
  return $o;
}
