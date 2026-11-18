<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
/**
 * CRM sales (Phase 6): companies, contacts, leads, qualification, assignment, duplicate detection, opportunities and the pipeline.
 * Every mutating function takes the acting user and refuses when the role lacks the permission (central authorization, not only in the UI).
 * Pipeline: new → qualified → contacted → discovery → proposal → negotiation → won | lost.
 */
require_once __DIR__ . '/crm_core.php';

function crm_stages(): array { return ['new' => 'New', 'qualified' => 'Qualified', 'contacted' => 'Contacted', 'discovery' => 'Discovery', 'proposal' => 'Proposal', 'negotiation' => 'Negotiation', 'won' => 'Won', 'lost' => 'Lost']; }
function crm_open_stages(): array { return ['new', 'qualified', 'contacted', 'discovery', 'proposal', 'negotiation']; }
/** Planning weights for the forecast. They are ASSUMPTIONS, not measured win rates; the dashboard labels forecasts as hypothetical. */
function crm_stage_weights_bp(): array { return ['new' => 500, 'qualified' => 1500, 'contacted' => 2500, 'discovery' => 3500, 'proposal' => 5000, 'negotiation' => 7000]; }
function crm_lead_kinds(): array { return ['advertising' => 'Advertising', 'partnership' => 'Partnership', 'development' => 'TrustNova development']; }
function crm_lead_statuses(): array { return ['new' => 'New', 'qualified' => 'Qualified', 'disqualified' => 'Disqualified', 'converted' => 'Converted']; }
function crm_deny(?array $user, string $perm): bool { return !crm_can($user['role'] ?? null, $perm); }

// ---- companies and contacts ----------------------------------------------------------------------------------------------------------
function crm_company_find_or_create(PDO $pdo, string $name, string $website = '', string $source = '', bool $synthetic = false, string $emailDomain = ''): array {
  $name = crm_line($name, 190); $website = crm_text($website, 255);
  $domain = crm_domain($website); if ($domain === '' && $emailDomain !== '' && !crm_is_free_mail_domain($emailDomain)) $domain = $emailDomain;
  if ($name === '') $name = $domain !== '' ? $domain : 'Unknown company';
  if ($domain !== '') { $s = $pdo->prepare('SELECT id FROM crm_companies WHERE domain = ? ORDER BY id LIMIT 1'); $s->execute([$domain]); if ($id = $s->fetchColumn()) return [(int)$id, false]; }
  $s = $pdo->prepare('SELECT id FROM crm_companies WHERE LOWER(name) = LOWER(?) AND (domain = \'\' OR ? = \'\') ORDER BY id LIMIT 1'); $s->execute([$name, $domain]); if ($id = $s->fetchColumn()) return [(int)$id, false];
  $now = crm_now();
  $pdo->prepare('INSERT INTO crm_companies (name, domain, website, source, created_at, updated_at, is_synthetic) VALUES (?,?,?,?,?,?,?)')->execute([$name, $domain, $website !== '' && crm_domain($website) !== '' ? $website : '', crm_line($source, 60), $now, $now, $synthetic ? 1 : 0]);
  return [(int)$pdo->lastInsertId(), true];
}
function crm_contact_find_or_create(PDO $pdo, ?int $companyId, string $name, string $email, string $roleTitle = '', string $pref = 'email', string $consentSource = '', bool $synthetic = false): array {
  $norm = crm_email_norm($email);
  $s = $pdo->prepare('SELECT id, company_id FROM crm_contacts WHERE email_norm = ?'); $s->execute([$norm]); $row = $s->fetch(PDO::FETCH_ASSOC);
  if ($row) { if ($companyId && !$row['company_id']) $pdo->prepare('UPDATE crm_contacts SET company_id = ? WHERE id = ?')->execute([$companyId, $row['id']]); return [(int)$row['id'], false]; }
  $pref = in_array($pref, ['email', 'video_call', 'phone'], true) ? $pref : 'email'; $now = crm_now();
  // The person wrote to us: that is a basis to reply to that inquiry only. It is NOT consent to marketing or outreach.
  $pdo->prepare('INSERT INTO crm_contacts (company_id, name, email, email_norm, role_title, contact_pref, consent_status, consent_at, consent_source, created_at, is_synthetic) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$companyId, crm_line($name, 120), $norm, $norm, crm_line($roleTitle, 80), $pref, 'inbound_inquiry', $now, crm_line($consentSource, 80), $now, $synthetic ? 1 : 0]);
  return [(int)$pdo->lastInsertId(), true];
}
/** Possible duplicates for a new inquiry. Detection only: nothing is merged automatically. */
function crm_find_duplicates(PDO $pdo, string $email, string $companyName, string $website): array {
  $out = []; $norm = crm_email_norm($email); $domain = crm_domain($website); $name = crm_line($companyName, 190);
  $s = $pdo->prepare('SELECT id FROM crm_contacts WHERE email_norm = ?'); $s->execute([$norm]); foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) $out[] = ['type' => 'contact', 'id' => (int)$id, 'reason' => 'same email address'];
  if ($domain !== '') { $s = $pdo->prepare('SELECT id FROM crm_companies WHERE domain = ?'); $s->execute([$domain]); foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) $out[] = ['type' => 'company', 'id' => (int)$id, 'reason' => 'same website domain']; }
  if ($name !== '') { $s = $pdo->prepare('SELECT id FROM crm_companies WHERE LOWER(name) = LOWER(?)'); $s->execute([$name]); foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) if (!in_array($id, array_column(array_filter($out, fn($o) => $o['type'] === 'company'), 'id'), true)) $out[] = ['type' => 'company', 'id' => (int)$id, 'reason' => 'same company name']; }
  return $out;
}
function crm_company_get(PDO $pdo, int $id): ?array { $s = $pdo->prepare('SELECT * FROM crm_companies WHERE id = ?'); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC) ?: null; }
function crm_contact_get(PDO $pdo, int $id): ?array { $s = $pdo->prepare('SELECT * FROM crm_contacts WHERE id = ?'); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC) ?: null; }
function crm_companies_list(PDO $pdo, string $q = '', int $limit = 50): array {
  $sql = 'SELECT c.*, (SELECT COUNT(*) FROM crm_contacts k WHERE k.company_id = c.id) AS contacts, (SELECT COUNT(*) FROM crm_opportunities o WHERE o.company_id = c.id) AS opportunities FROM crm_companies c';
  $args = []; if ($q !== '') { $sql .= ' WHERE c.name LIKE ? OR c.domain LIKE ?'; $like = '%' . addcslashes(crm_text($q, 80), '%_\\') . '%'; $args = [$like, $like]; }
  $s = $pdo->prepare($sql . ' ORDER BY c.name LIMIT ' . max(1, min(200, $limit))); $s->execute($args); return $s->fetchAll(PDO::FETCH_ASSOC);
}

// ---- leads ---------------------------------------------------------------------------------------------------------------------------
/**
 * Creates the CRM records for one inquiry in a single transaction (company, contact, lead, first activity).
 * @param array $in kind, subtype, name, email, company, website, message, budget, timeline, source, attr_source/medium/campaign, details[], public_lead_id, pref, synthetic
 * @return array{0:bool,1:string|int,2?:array} [ok, leadId|errorCode, info]
 */
function crm_lead_ingest(PDO $pdo, array $in): array {
  $kind = (string)($in['kind'] ?? ''); if (!isset(crm_lead_kinds()[$kind])) return [false, 'kind_invalid'];
  $email = trim((string)($in['email'] ?? '')); if (!crm_valid_email($email)) return [false, 'email_invalid'];
  $name = crm_line($in['name'] ?? '', 120); if ($name === '') return [false, 'name_required'];
  $syn = !empty($in['synthetic']); $dupes = crm_find_duplicates($pdo, $email, (string)($in['company'] ?? ''), (string)($in['website'] ?? ''));
  $own = false; if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $own = true; }
  try {
    $domain = (string)substr(strrchr($email, '@') ?: '', 1);
    [$companyId] = crm_company_find_or_create($pdo, (string)($in['company'] ?? ''), (string)($in['website'] ?? ''), (string)($in['source'] ?? ''), $syn, strtolower($domain));
    [$contactId, $newContact] = crm_contact_find_or_create($pdo, $companyId, $name, $email, '', (string)($in['pref'] ?? 'email'), (string)($in['source'] ?? ''), $syn);
    $dupOf = null;
    if (!$newContact) { $s = $pdo->prepare("SELECT id FROM crm_leads WHERE contact_id = ? AND status IN ('new','qualified') AND created_at >= ? ORDER BY id LIMIT 1"); $s->execute([$contactId, crm_now(time() - 30 * 86400)]); $dupOf = $s->fetchColumn() ?: null; }
    $now = crm_now(); $details = isset($in['details']) && is_array($in['details']) ? json_encode(array_map(fn($v) => is_scalar($v) ? crm_text($v, 2000) : '', $in['details']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $pdo->prepare('INSERT INTO crm_leads (kind, subtype, company_id, contact_id, source, attr_source, attr_medium, attr_campaign, status, budget_range, timeline, message, details, public_lead_id, duplicate_of, created_at, updated_at, is_synthetic) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$kind, crm_slug($in['subtype'] ?? '', 40), $companyId, $contactId, crm_line($in['source'] ?? '', 60), crm_slug($in['attr_source'] ?? '', 40), crm_slug($in['attr_medium'] ?? '', 40), crm_slug($in['attr_campaign'] ?? '', 60), 'new',
                   crm_slug($in['budget'] ?? '', 40), crm_slug($in['timeline'] ?? '', 40), crm_text($in['message'] ?? '', 4000), $details, isset($in['public_lead_id']) ? (int)$in['public_lead_id'] : null, $dupOf, $now, $now, $syn ? 1 : 0]);
    $leadId = (int)$pdo->lastInsertId();
    crm_activity_add($pdo, null, 'lead', $leadId, 'system', 'Inquiry received' . (($in['source'] ?? '') !== '' ? ' via ' . crm_line($in['source'], 60) : '') . ($dupOf ? '. Possible duplicate of lead #' . $dupOf . '.' : '.'));
    crm_audit($pdo, null, 'lead_ingest', 'lead', $leadId, $kind . '/' . crm_slug($in['subtype'] ?? '', 40) . ($dupOf ? ' (possible duplicate)' : ''));
    if ($own) $pdo->commit();
    return [true, $leadId, ['company_id' => $companyId, 'contact_id' => $contactId, 'duplicate_of' => $dupOf, 'duplicates' => $dupes]];
  } catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); sec_log_exception($e, 'crm_lead_ingest'); return [false, 'storage_error']; }
}
function crm_lead_get(PDO $pdo, int $id): ?array {
  $s = $pdo->prepare('SELECT l.*, c.name AS company_name, c.domain AS company_domain, k.name AS contact_name, k.email AS contact_email, k.consent_status, k.unsubscribed_at, u.display_name AS assignee FROM crm_leads l LEFT JOIN crm_companies c ON c.id = l.company_id LEFT JOIN crm_contacts k ON k.id = l.contact_id LEFT JOIN crm_users u ON u.id = l.assigned_to WHERE l.id = ?');
  $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}
/** @param array $f kind, status, assigned (user id or 'none'), source, q, page */
function crm_leads_list(PDO $pdo, array $f = [], int $perPage = 25): array {
  $w = []; $a = [];
  if (!empty($f['kind']) && isset(crm_lead_kinds()[$f['kind']])) { $w[] = 'l.kind = ?'; $a[] = $f['kind']; }
  if (!empty($f['status']) && isset(crm_lead_statuses()[$f['status']])) { $w[] = 'l.status = ?'; $a[] = $f['status']; }
  if (isset($f['assigned']) && $f['assigned'] === 'none') $w[] = 'l.assigned_to IS NULL'; elseif (!empty($f['assigned'])) { $w[] = 'l.assigned_to = ?'; $a[] = (int)$f['assigned']; }
  if (!empty($f['source'])) { $w[] = 'l.attr_source = ?'; $a[] = crm_slug($f['source'], 40); }
  if (!empty($f['duplicates'])) $w[] = 'l.duplicate_of IS NOT NULL';
  if (!empty($f['q'])) { $like = '%' . addcslashes(crm_text($f['q'], 80), '%_\\') . '%'; $w[] = '(c.name LIKE ? OR k.name LIKE ? OR k.email_norm LIKE ? OR l.message LIKE ?)'; array_push($a, $like, $like, $like, $like); }
  $where = $w ? ' WHERE ' . implode(' AND ', $w) : ''; $base = ' FROM crm_leads l LEFT JOIN crm_companies c ON c.id = l.company_id LEFT JOIN crm_contacts k ON k.id = l.contact_id LEFT JOIN crm_users u ON u.id = l.assigned_to' . $where;
  $s = $pdo->prepare('SELECT COUNT(*)' . $base); $s->execute($a); $total = (int)$s->fetchColumn();
  $page = max(1, (int)($f['page'] ?? 1)); $off = ($page - 1) * $perPage;
  $s = $pdo->prepare('SELECT l.id, l.kind, l.subtype, l.status, l.created_at, l.attr_source, l.attr_campaign, l.duplicate_of, l.is_synthetic, c.name AS company_name, k.name AS contact_name, k.email AS contact_email, u.display_name AS assignee' . $base . ' ORDER BY l.id DESC LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$off);
  $s->execute($a); return ['rows' => $s->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
}
function crm_lead_assign(PDO $pdo, ?array $user, int $leadId, ?int $assignee): array {
  if (crm_deny($user, 'assign')) return [false, 'forbidden'];
  if ($assignee !== null && !crm_user_get($pdo, $assignee)) return [false, 'assignee_invalid'];
  $s = $pdo->prepare('UPDATE crm_leads SET assigned_to = ?, updated_at = ? WHERE id = ?'); $s->execute([$assignee, crm_now(), $leadId]);
  if (!crm_lead_get($pdo, $leadId)) return [false, 'not_found'];
  crm_audit($pdo, $user, 'lead_assign', 'lead', $leadId, $assignee ? 'assigned to user #' . $assignee : 'unassigned'); return [true, 'ok'];
}
/** Qualification: four yes/no/unknown answers give a transparent score (0–4: one point per "yes"). The decision itself is made by a person. */
function crm_lead_qualify(PDO $pdo, ?array $user, int $leadId, array $answers, string $decision, string $reason = ''): array {
  if (crm_deny($user, 'write')) return [false, 'forbidden'];
  if (!in_array($decision, ['qualified', 'disqualified'], true)) return [false, 'decision_invalid'];
  $lead = crm_lead_get($pdo, $leadId); if (!$lead) return [false, 'not_found'];
  if ($lead['status'] === 'converted') return [false, 'already_converted'];
  $q = []; $score = 0; foreach (['need', 'budget', 'timeline', 'authority'] as $k) { $v = in_array($answers[$k] ?? '', ['yes', 'no', 'unknown'], true) ? $answers[$k] : 'unknown'; $q[$k] = $v; if ($v === 'yes') $score++; }
  $reason = crm_line($reason, 190); if ($decision === 'disqualified' && $reason === '') return [false, 'reason_required'];
  $pdo->prepare('UPDATE crm_leads SET status = ?, qualification = ?, updated_at = ? WHERE id = ?')->execute([$decision, json_encode(['answers' => $q, 'score' => $score, 'reason' => $reason, 'by' => $user['username'] ?? '', 'at' => crm_now()]), crm_now(), $leadId]);
  crm_activity_add($pdo, $user, 'lead', $leadId, 'system', ucfirst($decision) . " (score $score/4)" . ($reason !== '' ? ': ' . $reason : ''));
  crm_audit($pdo, $user, 'lead_' . $decision, 'lead', $leadId, "score $score/4"); return [true, (string)$score];
}
function crm_lead_convert(PDO $pdo, ?array $user, int $leadId, string $title, string $amount, string $currency, ?string $closeDate = null): array {
  if (crm_deny($user, 'write')) return [false, 'forbidden'];
  $lead = crm_lead_get($pdo, $leadId); if (!$lead) return [false, 'not_found'];
  if ($lead['status'] !== 'qualified') return [false, 'lead_not_qualified'];
  $own = !$pdo->inTransaction(); if ($own) $pdo->beginTransaction();
  try {
    [$ok, $oppId] = crm_opp_create($pdo, $user, (int)$lead['company_id'], $lead['contact_id'] ? (int)$lead['contact_id'] : null, $lead['kind'], $title !== '' ? $title : 'Opportunity from lead #' . $leadId, $amount, $currency, $closeDate, $leadId, 'qualified');
    if (!$ok) { if ($own) $pdo->rollBack(); return [false, (string)$oppId]; }
    $pdo->prepare("UPDATE crm_leads SET status = 'converted', updated_at = ? WHERE id = ?")->execute([crm_now(), $leadId]);
    crm_activity_add($pdo, $user, 'lead', $leadId, 'system', 'Converted to opportunity #' . $oppId); crm_audit($pdo, $user, 'lead_convert', 'lead', $leadId, 'opportunity #' . $oppId);
    if ($own) $pdo->commit(); return [true, (string)$oppId];
  } catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); sec_log_exception($e, 'crm_lead_convert'); return [false, 'storage_error']; }
}

// ---- opportunities and pipeline ------------------------------------------------------------------------------------------------------
function crm_opp_create(PDO $pdo, ?array $user, int $companyId, ?int $contactId, string $kind, string $title, string $amount, string $currency, ?string $closeDate = null, ?int $leadId = null, string $stage = 'new'): array {
  if (crm_deny($user, 'write')) return [false, 'forbidden'];
  if (!isset(crm_lead_kinds()[$kind])) return [false, 'kind_invalid'];
  if (!crm_company_get($pdo, $companyId)) return [false, 'company_invalid'];
  $title = crm_line($title, 190); if ($title === '') return [false, 'title_required'];
  $minor = $amount === '' ? 0 : crm_money_parse($amount); if ($minor === null) return [false, 'amount_invalid'];
  if (!crm_currency_ok($currency)) return [false, 'currency_invalid'];
  if (!in_array($stage, crm_open_stages(), true)) return [false, 'stage_invalid'];
  $close = null; if ($closeDate !== null && $closeDate !== '') { $t = crm_date($closeDate); if ($t === null) return [false, 'close_date_invalid']; $close = gmdate('Y-m-d', $t); }
  $now = crm_now();
  $pdo->prepare('INSERT INTO crm_opportunities (lead_id, company_id, contact_id, kind, title, stage, amount_minor, currency, expected_close, owner_id, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$leadId, $companyId, $contactId, $kind, $title, $stage, $minor, $currency, $close, $user['id'] ?? null, $now, $now]);
  $id = (int)$pdo->lastInsertId();
  $pdo->prepare('INSERT INTO crm_stage_history (opportunity_id, from_stage, to_stage, user_id, at) VALUES (?,?,?,?,?)')->execute([$id, '', $stage, $user['id'] ?? null, $now]);
  crm_audit($pdo, $user, 'opportunity_create', 'opportunity', $id, $kind . ' ' . crm_money_format($minor, $currency)); return [true, (string)$id];
}
function crm_opp_get(PDO $pdo, int $id): ?array {
  $s = $pdo->prepare('SELECT o.*, c.name AS company_name, k.name AS contact_name, u.display_name AS owner FROM crm_opportunities o LEFT JOIN crm_companies c ON c.id = o.company_id LEFT JOIN crm_contacts k ON k.id = o.contact_id LEFT JOIN crm_users u ON u.id = o.owner_id WHERE o.id = ?');
  $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}
function crm_opp_can_move(string $from, string $to): bool {
  $open = crm_open_stages();
  if (in_array($from, ['won', 'lost'], true)) return false;
  if ($to === 'lost') return in_array($from, $open, true);
  if ($to === 'won') return $from === 'negotiation';
  $i = array_search($from, $open, true); $j = array_search($to, $open, true);
  return $i !== false && $j !== false && $j > $i;
}
function crm_opp_move(PDO $pdo, ?array $user, int $id, string $to, string $lostReason = ''): array {
  if (crm_deny($user, 'write')) return [false, 'forbidden'];
  $o = crm_opp_get($pdo, $id); if (!$o) return [false, 'not_found'];
  if (!isset(crm_stages()[$to]) || !crm_opp_can_move($o['stage'], $to)) return [false, 'transition_invalid'];
  if ($to === 'won' && ((int)$o['amount_minor'] <= 0 || !crm_currency_ok($o['currency']))) return [false, 'amount_required_for_won'];
  $lostReason = crm_line($lostReason, 190); if ($to === 'lost' && $lostReason === '') return [false, 'lost_reason_required'];
  $now = crm_now(); $closed = in_array($to, ['won', 'lost'], true);
  $own = !$pdo->inTransaction(); if ($own) $pdo->beginTransaction();
  try {
    $pdo->prepare('UPDATE crm_opportunities SET stage = ?, outcome = ?, lost_reason = ?, closed_at = ?, updated_at = ? WHERE id = ? AND stage = ?')->execute([$to, $closed ? $to : null, $to === 'lost' ? $lostReason : '', $closed ? $now : null, $now, $id, $o['stage']]);
    $pdo->prepare('INSERT INTO crm_stage_history (opportunity_id, from_stage, to_stage, user_id, at) VALUES (?,?,?,?,?)')->execute([$id, $o['stage'], $to, $user['id'] ?? null, $now]);
    crm_audit($pdo, $user, 'opportunity_' . ($closed ? $to : 'move'), 'opportunity', $id, $o['stage'] . ' → ' . $to);
    if ($own) $pdo->commit(); return [true, 'ok'];
  } catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); sec_log_exception($e, 'crm_opp_move'); return [false, 'storage_error']; }
}
function crm_opp_update(PDO $pdo, ?array $user, int $id, array $f): array {
  if (crm_deny($user, 'write')) return [false, 'forbidden'];
  $o = crm_opp_get($pdo, $id); if (!$o) return [false, 'not_found']; if (in_array($o['stage'], ['won', 'lost'], true)) return [false, 'closed'];
  $amount = isset($f['amount']) && $f['amount'] !== '' ? crm_money_parse((string)$f['amount']) : (int)$o['amount_minor']; if ($amount === null) return [false, 'amount_invalid'];
  $cur = (string)($f['currency'] ?? $o['currency']); if (!crm_currency_ok($cur)) return [false, 'currency_invalid'];
  $close = $o['expected_close']; if (array_key_exists('expected_close', $f)) { $close = null; if ($f['expected_close'] !== '') { $t = crm_date((string)$f['expected_close']); if ($t === null) return [false, 'close_date_invalid']; $close = gmdate('Y-m-d', $t); } }
  $title = crm_line($f['title'] ?? $o['title'], 190); if ($title === '') return [false, 'title_required'];
  $owner = array_key_exists('owner_id', $f) ? ($f['owner_id'] === '' || $f['owner_id'] === null ? null : (int)$f['owner_id']) : ($o['owner_id'] ? (int)$o['owner_id'] : null);
  if ($owner !== null && $owner !== (int)$o['owner_id'] && crm_deny($user, 'assign')) return [false, 'forbidden'];
  if ($owner !== null && !crm_user_get($pdo, $owner)) return [false, 'owner_invalid'];
  $pdo->prepare('UPDATE crm_opportunities SET title = ?, amount_minor = ?, currency = ?, expected_close = ?, owner_id = ?, updated_at = ? WHERE id = ?')->execute([$title, $amount, $cur, $close, $owner, crm_now(), $id]);
  crm_audit($pdo, $user, 'opportunity_update', 'opportunity', $id, crm_money_format($amount, $cur)); return [true, 'ok'];
}
function crm_opps_list(PDO $pdo, array $f = [], int $limit = 100): array {
  $w = []; $a = [];
  if (!empty($f['stage']) && isset(crm_stages()[$f['stage']])) { $w[] = 'o.stage = ?'; $a[] = $f['stage']; }
  if (!empty($f['kind']) && isset(crm_lead_kinds()[$f['kind']])) { $w[] = 'o.kind = ?'; $a[] = $f['kind']; }
  if (!empty($f['owner'])) { $w[] = 'o.owner_id = ?'; $a[] = (int)$f['owner']; }
  if (!empty($f['open'])) $w[] = "o.stage NOT IN ('won','lost')";
  if (!empty($f['q'])) { $w[] = '(o.title LIKE ? OR c.name LIKE ?)'; $like = '%' . addcslashes(crm_text($f['q'], 80), '%_\\') . '%'; array_push($a, $like, $like); }
  $s = $pdo->prepare('SELECT o.*, c.name AS company_name, u.display_name AS owner FROM crm_opportunities o LEFT JOIN crm_companies c ON c.id = o.company_id LEFT JOIN crm_users u ON u.id = o.owner_id' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY o.updated_at DESC, o.id DESC LIMIT ' . max(1, min(500, $limit)));
  $s->execute($a); return $s->fetchAll(PDO::FETCH_ASSOC);
}
function crm_opp_history(PDO $pdo, int $id): array { $s = $pdo->prepare('SELECT h.from_stage, h.to_stage, h.at, u.display_name AS who FROM crm_stage_history h LEFT JOIN crm_users u ON u.id = h.user_id WHERE h.opportunity_id = ? ORDER BY h.id'); $s->execute([$id]); return $s->fetchAll(PDO::FETCH_ASSOC); }
