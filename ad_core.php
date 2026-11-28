<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
/**
 * Advertising and sponsorship booking engine (Phase 7F), built ON the existing records: a booking belongs to a CRM campaign (the advertising enquiry) and a CRM company;
 * agreed money lives in the existing CRM opportunity > proposal > contract chain and its revenue entries (so Finance already reports it, and nothing is entered twice);
 * public placements use the existing tool-management placement mechanism (adm_tool_feature mirrored into the tool's featured level, always labelled "Sponsored").
 *
 * Rules: nothing is published without the owner's explicit approval; expired campaigns drop out of public placements automatically (the placement ends on the campaign end date);
 * overlapping bookings that exceed a package's capacity (or a second placement on one tool) are refused; paid placement never touches editorial profiles, reviews or organic
 * scores (only the featured level and its end date are written); received money is separate from expected money; currencies are never converted. Nothing is ever sent to anyone.
 */
require_once __DIR__ . '/crm_money.php';
require_once __DIR__ . '/crm_growth.php';

const AD_HOLDS = ['awaiting_payment', 'scheduled', 'active', 'paused'];   // statuses that reserve inventory
function ad_statuses(): array { return ['draft' => 'Draft', 'enquiry_received' => 'Enquiry received', 'proposal_sent' => 'Proposal sent', 'awaiting_payment' => 'Awaiting payment', 'scheduled' => 'Scheduled', 'active' => 'Active', 'paused' => 'Paused', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'expired' => 'Expired']; }
function ad_transitions(): array {
  return ['draft' => ['enquiry_received', 'proposal_sent', 'cancelled'], 'enquiry_received' => ['proposal_sent', 'cancelled'], 'proposal_sent' => ['awaiting_payment', 'enquiry_received', 'cancelled'], 'awaiting_payment' => ['scheduled', 'proposal_sent', 'cancelled'],
    'scheduled' => ['active', 'awaiting_payment', 'expired', 'cancelled'], 'active' => ['paused', 'completed', 'expired', 'cancelled'], 'paused' => ['active', 'completed', 'cancelled'], 'completed' => [], 'cancelled' => [], 'expired' => []];
}
function ad_kinds(): array { return ['featured_tool' => 'Featured tool', 'category_sponsor' => 'Category sponsor', 'homepage_spotlight' => 'Homepage spotlight', 'newsletter_sponsor' => 'Newsletter sponsor', 'enterprise' => 'Custom enterprise', 'other' => 'Other']; }
function ad_billings(): array { return ['one_off' => 'One-off', 'monthly' => 'Monthly', 'per_issue' => 'Per issue', 'custom' => 'Custom']; }
function ad_deny(?array $user, string $perm): bool { return !crm_can($user['role'] ?? null, $perm); }
function ad_ready(PDO $pdo): bool {
  static $memo = []; $k = spl_object_id($pdo); if (isset($memo[$k])) return $memo[$k];
  try { foreach (['ad_package', 'ad_booking', 'ad_booking_history', 'ad_advertiser', 'ad_advertiser_tool'] as $t) $pdo->query("SELECT 1 FROM $t LIMIT 1"); return $memo[$k] = true; } catch (Throwable $e) { return false; }
}
function ad_today(?int $nowTs = null): string { return gmdate('Y-m-d', $nowTs ?? time()); }
function ad_minor(int $m): string { return number_format($m / 100, 2, '.', ''); }
function ad_catalogue(): array { try { return function_exists('tools_by_id') ? tools_by_id() : []; } catch (Throwable $e) { return []; } }
function ad_tool_store(PDO $pdo): ?AdmToolPdoStore { return class_exists('AdmToolPdoStore') && AdmToolPdoStore::ready($pdo) ? new AdmToolPdoStore($pdo) : null; }

// ---- packages (configurable; nothing here changes a public price) ---------------------------------------------------------------------------------
function ad_package_get(PDO $pdo, int $id): ?array { $s = $pdo->prepare('SELECT * FROM ad_package WHERE id = ?'); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC) ?: null; }
function ad_packages(PDO $pdo): array { return ad_ready($pdo) ? $pdo->query('SELECT * FROM ad_package ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC) : []; }
/** The existing public listed packages (data/sponsorship_packages.json): shown for reference only, never edited or priced here. */
function ad_existing_listed_packages(): array { $f = __DIR__ . '/data/sponsorship_packages.json'; $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : null; return is_array($j['packages'] ?? null) ? $j['packages'] : []; }
function ad_package_clean(array $in): array {
  $name = crm_line($in['name'] ?? '', 100); if ($name === '') return [false, 'name_required']; $kind = (string)($in['kind'] ?? 'other'); if (!isset(ad_kinds()[$kind])) return [false, 'kind_invalid'];
  $price = trim((string)($in['price'] ?? '')); $pm = null; $cur = trim((string)($in['currency'] ?? '')); if ($price !== '') { $pm = crm_money_parse($price); if ($pm === null || $pm > 100000000000) return [false, 'price_invalid']; if (!crm_currency_ok($cur)) return [false, 'currency_invalid']; } elseif ($cur !== '' && !crm_currency_ok($cur)) return [false, 'currency_invalid'];
  $dur = trim((string)($in['duration_days'] ?? '')); if ($dur !== '' && (!preg_match('/^\d{1,4}$/', $dur) || (int)$dur < 1 || (int)$dur > 1095)) return [false, 'duration_invalid']; $bill = (string)($in['billing'] ?? 'one_off'); if (!isset(ad_billings()[$bill])) return [false, 'billing_invalid'];
  $slots = (string)($in['slots'] ?? '1'); if (!preg_match('/^\d{1,3}$/', $slots) || (int)$slots < 1) return [false, 'slots_invalid']; $scope = (string)($in['scope'] ?? 'global'); if (!in_array($scope, ['global', 'category', 'none'], true)) return [false, 'scope_invalid'];
  $slot = (string)($in['public_slot'] ?? 'none'); if (!in_array($slot, ['none', 'level1', 'level2'], true)) return [false, 'public_slot_invalid'];
  return [true, ['name' => $name, 'kind' => $kind, 'price_minor' => $pm, 'currency' => $pm === null && $cur === '' ? null : ($cur ?: null), 'duration_days' => $dur === '' ? null : (int)$dur, 'billing' => $bill, 'placement' => crm_line($in['placement'] ?? '', 255), 'description' => crm_text($in['description'] ?? '', 2000), 'slots' => (int)$slots, 'exclusive' => !empty($in['exclusive']) ? 1 : 0, 'scope' => $scope, 'public_slot' => $slot, 'available' => !empty($in['available']) ? 1 : 0]];
}
function ad_package_save(PDO $pdo, ?array $user, ?int $id, array $in): array {
  if (ad_deny($user, 'approve')) return [false, 'forbidden']; if (!ad_ready($pdo)) return [false, 'not_migrated']; [$ok, $c] = ad_package_clean($in); if (!$ok) return [false, (string)$c]; $now = crm_now();
  if ($id === null) { $code = substr(strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', $c['name'])), 0, 32); $code = trim($code, '_') ?: 'package'; $e = $pdo->prepare('SELECT 1 FROM ad_package WHERE code = ?'); $e->execute([$code]); if ($e->fetchColumn()) $code .= '_' . substr(bin2hex(random_bytes(2)), 0, 4);
    $pdo->prepare('INSERT INTO ad_package (code, name, kind, price_minor, currency, duration_days, billing, placement, description, slots, exclusive, scope, public_slot, available, published, proposed, sort_order, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,0,100,?,?)')->execute([$code, $c['name'], $c['kind'], $c['price_minor'], $c['currency'], $c['duration_days'], $c['billing'], $c['placement'], $c['description'], $c['slots'], $c['exclusive'], $c['scope'], $c['public_slot'], $c['available'], $now, $now]); $id = (int)$pdo->lastInsertId(); }
  else { if (!ad_package_get($pdo, $id)) return [false, 'not_found']; $pdo->prepare('UPDATE ad_package SET name=?, kind=?, price_minor=?, currency=?, duration_days=?, billing=?, placement=?, description=?, slots=?, exclusive=?, scope=?, public_slot=?, available=?, proposed=0, updated_at=? WHERE id=?')->execute([$c['name'], $c['kind'], $c['price_minor'], $c['currency'], $c['duration_days'], $c['billing'], $c['placement'], $c['description'], $c['slots'], $c['exclusive'], $c['scope'], $c['public_slot'], $c['available'], $now, $id]); }
  crm_audit($pdo, $user, 'ad_package_save', 'advertising', $id, $c['name']); return [true, (string)$id];
}

// ---- inventory and conflicts ----------------------------------------------------------------------------------------------------------------------
function ad_overlap(?string $s1, ?string $e1, ?string $s2, ?string $e2): bool { if (!$s1 || !$e1 || !$s2 || !$e2) return false; return $s1 <= $e2 && $s2 <= $e1; }
/**
 * Bookings that would exceed capacity or collide with the proposed one. Rules: (1) the package capacity (slots) for overlapping bookings of the same package, per category
 * for category packages; an exclusive package holds one booking per scope; (2) one tool cannot carry two overlapping public placements (the existing tool-management rule).
 * @return array<int,array{id:int,why:string}>
 */
function ad_conflicts(PDO $pdo, int $packageId, string $tool, string $category, ?string $start, ?string $end, ?int $exceptId = null): array {
  $p = ad_package_get($pdo, $packageId); if (!$p || !$start || !$end) return []; $out = [];
  $s = $pdo->prepare("SELECT b.id, b.package_id, b.tool_slug, b.category, b.start_date, b.end_date, b.status, p.public_slot FROM ad_booking b LEFT JOIN ad_package p ON p.id = b.package_id WHERE b.status IN ('awaiting_payment','scheduled','active','paused') AND b.start_date IS NOT NULL AND b.end_date IS NOT NULL" . ($exceptId ? ' AND b.id <> ' . (int)$exceptId : '')); $s->execute();
  $same = 0; foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $b) {
    if (!ad_overlap($start, $end, $b['start_date'], $b['end_date'])) continue;
    if ((int)$b['package_id'] === $packageId && ($p['scope'] !== 'category' || strcasecmp($b['category'], $category) === 0)) { $same++; if ($same >= ($p['exclusive'] ? 1 : (int)$p['slots'])) $out[] = ['id' => (int)$b['id'], 'why' => $p['exclusive'] ? 'exclusive package already booked for these dates' . ($p['scope'] === 'category' ? ' in this category' : '') : 'all ' . (int)$p['slots'] . ' slots are booked for these dates']; }
    if ($tool !== '' && $b['tool_slug'] === $tool && $p['public_slot'] !== 'none' && ($b['public_slot'] ?? 'none') !== 'none' && !in_array($b['id'], array_column($out, 'id'), true)) $out[] = ['id' => (int)$b['id'], 'why' => 'this tool already has a public placement booked for these dates'];
  }
  return $out;
}
/** Capacity today and over the next 90 days per package. Packages that are not available (or have no public slot) are reported as such, not as free. */
function ad_inventory(PDO $pdo, ?int $nowTs = null): array {
  $today = ad_today($nowTs); $end = gmdate('Y-m-d', strtotime($today . ' UTC') + 90 * 86400); $o = [];
  foreach (ad_packages($pdo) as $p) {
    $s = $pdo->prepare("SELECT id, category, tool_slug, start_date, end_date, status FROM ad_booking WHERE package_id = ? AND status IN ('awaiting_payment','scheduled','active','paused') AND start_date <= ? AND end_date >= ?"); $s->execute([$p['id'], $end, $today]); $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    $now = array_values(array_filter($rows, fn($b) => $b['start_date'] <= $today && $b['end_date'] >= $today)); $cap = $p['exclusive'] ? 1 : (int)$p['slots'];
    $o[] = ['package' => $p, 'available' => (bool)$p['available'], 'capacity' => $cap, 'scope' => $p['scope'], 'booked_now' => count($now), 'booked_90d' => count($rows), 'free_now' => $p['scope'] === 'category' ? null : max(0, $cap - count($now)), 'categories_booked' => $p['scope'] === 'category' ? array_values(array_unique(array_column($now, 'category'))) : [], 'public' => $p['public_slot'] !== 'none'];
  }
  return $o;
}

// ---- advertisers --------------------------------------------------------------------------------------------------------------------------------
function ad_advertiser_ensure(PDO $pdo, int $companyId, ?int $manager = null): void { $now = crm_now(); $pdo->prepare('INSERT IGNORE INTO ad_advertiser (company_id, account_manager, created_at, updated_at) VALUES (?,?,?,?)')->execute([$companyId, $manager, $now, $now]); }
function ad_advertiser_update(PDO $pdo, ?array $user, int $companyId, ?int $manager, string $notes): array {
  if (ad_deny($user, 'write')) return [false, 'forbidden']; if (!ad_ready($pdo)) return [false, 'not_migrated']; if (!crm_company_get($pdo, $companyId)) return [false, 'company_invalid']; if ($manager !== null && !crm_user_get($pdo, $manager)) return [false, 'manager_invalid'];
  ad_advertiser_ensure($pdo, $companyId); $pdo->prepare('UPDATE ad_advertiser SET account_manager = ?, notes = ?, updated_at = ? WHERE company_id = ?')->execute([$manager, crm_text($notes, 4000), crm_now(), $companyId]); crm_audit($pdo, $user, 'ad_advertiser_update', 'company', $companyId, 'profile updated'); return [true, 'ok'];
}
function ad_advertiser_tool_add(PDO $pdo, ?array $user, int $companyId, string $slug): array {
  if (ad_deny($user, 'write')) return [false, 'forbidden']; if (!ad_ready($pdo)) return [false, 'not_migrated']; if (!crm_company_get($pdo, $companyId)) return [false, 'company_invalid']; if (!isset(ad_catalogue()[$slug])) return [false, 'tool_unknown'];
  ad_advertiser_ensure($pdo, $companyId); $s = $pdo->prepare('INSERT IGNORE INTO ad_advertiser_tool (company_id, tool_slug, created_at) VALUES (?,?,?)'); $s->execute([$companyId, $slug, crm_now()]); if ($s->rowCount() !== 1) return [false, 'already_linked']; crm_audit($pdo, $user, 'ad_advertiser_tool', 'company', $companyId, $slug); return [true, 'ok'];
}
function ad_advertiser_tools(PDO $pdo, int $companyId): array { $s = $pdo->prepare('SELECT tool_slug FROM ad_advertiser_tool WHERE company_id = ? ORDER BY tool_slug'); $s->execute([$companyId]); return $s->fetchAll(PDO::FETCH_COLUMN); }

// ---- bookings -----------------------------------------------------------------------------------------------------------------------------------
function ad_booking_get(PDO $pdo, int $id): ?array {
  if (!ad_ready($pdo)) return null; $s = $pdo->prepare('SELECT b.*, c.name AS company_name, c.website AS company_website, g.status AS campaign_status, g.title AS campaign_title, g.disclosure_label, g.landing_url, g.lead_id, g.contact_id, p.name AS package_name, p.kind AS package_kind, p.public_slot, p.scope AS package_scope, u.display_name AS manager_name FROM ad_booking b LEFT JOIN crm_companies c ON c.id = b.company_id LEFT JOIN crm_campaigns g ON g.id = b.campaign_id LEFT JOIN ad_package p ON p.id = b.package_id LEFT JOIN crm_users u ON u.id = b.account_manager WHERE b.id = ?');
  $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}
function ad_bookings_list(PDO $pdo, array $f = [], int $limit = 300): array {
  if (!ad_ready($pdo)) return []; $w = []; $a = []; if (!empty($f['status']) && isset(ad_statuses()[$f['status']])) { $w[] = 'b.status = ?'; $a[] = $f['status']; } if (!empty($f['company'])) { $w[] = 'b.company_id = ?'; $a[] = (int)$f['company']; }
  $s = $pdo->prepare('SELECT b.id FROM ad_booking b' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY b.id DESC LIMIT ' . max(1, min(500, $limit))); $s->execute($a); $o = []; foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) { $b = ad_booking_get($pdo, (int)$id); if ($b) $o[] = $b; } return $o;
}
function ad_status_from_campaign(string $s): string { return ['inquiry' => 'enquiry_received', 'in_review' => 'enquiry_received', 'approved' => 'proposal_sent', 'scheduled' => 'scheduled', 'live' => 'active', 'completed' => 'completed', 'cancelled' => 'cancelled', 'rejected' => 'cancelled'][$s] ?? 'draft'; }
function ad_clean_booking(PDO $pdo, array $in, ?array $cur = null): array {
  $pkgId = ($in['package_id'] ?? '') === '' ? null : (int)$in['package_id']; $pkg = $pkgId ? ad_package_get($pdo, $pkgId) : null; if ($pkgId && !$pkg) return [false, 'package_invalid'];
  $tool = trim((string)($in['tool_slug'] ?? '')); if ($tool !== '' && !isset(ad_catalogue()[$tool])) return [false, 'tool_unknown']; $cat = crm_line($in['category'] ?? '', 120);
  $price = trim((string)($in['agreed_price'] ?? '')); $pm = null; $cur2 = trim((string)($in['currency'] ?? '')); if ($price !== '') { $pm = crm_money_parse($price); if ($pm === null || $pm > 100000000000) return [false, 'price_invalid']; if (!crm_currency_ok($cur2)) return [false, 'currency_invalid']; }
  $d = []; foreach (['start_date', 'end_date', 'renewal_date'] as $k) { $v = trim((string)($in[$k] ?? '')); if ($v === '') { $d[$k] = null; continue; } $t = crm_date($v); if ($t === null) return [false, $k . '_invalid']; $d[$k] = gmdate('Y-m-d', $t); }
  if ($d['start_date'] && $d['end_date'] && $d['end_date'] < $d['start_date']) return [false, 'dates_invalid']; $bill = (string)($in['billing'] ?? ($pkg['billing'] ?? 'one_off')); if (!isset(ad_billings()[$bill])) return [false, 'billing_invalid'];
  $pl = trim((string)($in['payment_link'] ?? '')); if ($pl !== '' && (sec_safe_external_url($pl) === null || stripos($pl, 'https://') !== 0)) return [false, 'payment_link_invalid']; $mgr = ($in['account_manager'] ?? '') === '' ? null : (int)$in['account_manager']; if ($mgr !== null && !crm_user_get($pdo, $mgr)) return [false, 'manager_invalid'];
  return [true, ['package_id' => $pkgId, 'tool_slug' => $tool, 'category' => $cat, 'agreed_price_minor' => $pm, 'currency' => $pm === null && $cur2 === '' ? null : ($cur2 ?: null), 'billing' => $bill, 'start_date' => $d['start_date'], 'end_date' => $d['end_date'], 'renewal_date' => $d['renewal_date'], 'payment_link' => $pl, 'account_manager' => $mgr, 'requirements' => crm_text($in['requirements'] ?? '', 3000), 'notes' => crm_text($in['notes'] ?? '', 4000)]];
}
function ad_history(PDO $pdo, int $id, ?string $from, string $to, string $note, ?array $user, ?int $nowTs = null): void { $pdo->prepare('INSERT INTO ad_booking_history (booking_id, from_status, to_status, note, changed_by, changed_at) VALUES (?,?,?,?,?,?)')->execute([$id, $from, $to, crm_line($note, 255), $user['id'] ?? null, crm_now($nowTs)]); }
/** Creates a booking for a CRM company: reuses an existing enquiry (CRM campaign) when campaign_id is given, otherwise creates the CRM campaign first. One booking per campaign. */
function ad_booking_create(PDO $pdo, ?array $user, int $companyId, array $in, ?int $campaignId = null): array {
  if (ad_deny($user, 'write')) return [false, 'forbidden']; if (!ad_ready($pdo)) return [false, 'not_migrated']; if (!crm_company_get($pdo, $companyId)) return [false, 'company_invalid']; [$ok, $c] = ad_clean_booking($pdo, $in); if (!$ok) return [false, (string)$c];
  $own = !$pdo->inTransaction(); if ($own) $pdo->beginTransaction();
  try {
    $status = 'draft'; if ($campaignId !== null) { $g = crm_campaign_get($pdo, $campaignId); if (!$g || (int)$g['company_id'] !== $companyId) { if ($own) $pdo->rollBack(); return [false, 'campaign_invalid']; } $x = $pdo->prepare('SELECT id FROM ad_booking WHERE campaign_id = ?'); $x->execute([$campaignId]); if ($x->fetchColumn()) { if ($own) $pdo->rollBack(); return [false, 'booking_exists']; } $status = ad_status_from_campaign($g['status']); }
    else { $pk = $c['package_id'] ? ad_package_get($pdo, $c['package_id']) : null; $crmPkg = ['featured_tool' => 'featured_placement', 'category_sponsor' => 'category_sponsorship', 'homepage_spotlight' => 'featured_placement', 'newsletter_sponsor' => 'newsletter_sponsorship', 'enterprise' => 'custom_campaign'][$pk['kind'] ?? ''] ?? 'custom_campaign';
      [$kok, $cid] = crm_campaign_create($pdo, $user, $companyId, $crmPkg, ($pk['name'] ?? 'Advertising') . ($c['tool_slug'] !== '' ? ': ' . $c['tool_slug'] : '')); if (!$kok) { if ($own) $pdo->rollBack(); return [false, (string)$cid]; } $campaignId = (int)$cid; }
    $now = crm_now(); $pdo->prepare('INSERT INTO ad_booking (campaign_id, company_id, package_id, tool_slug, category, status, agreed_price_minor, currency, billing, start_date, end_date, renewal_date, payment_link, account_manager, requirements, notes, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$campaignId, $companyId, $c['package_id'], $c['tool_slug'], $c['category'], $status, $c['agreed_price_minor'], $c['currency'], $c['billing'], $c['start_date'], $c['end_date'], $c['renewal_date'], $c['payment_link'], $c['account_manager'], $c['requirements'], $c['notes'], $user['id'] ?? null, $now, $now]);
    $id = (int)$pdo->lastInsertId(); ad_history($pdo, $id, null, $status, 'Booking created', $user); ad_advertiser_ensure($pdo, $companyId, $c['account_manager']); if ($c['tool_slug'] !== '') $pdo->prepare('INSERT IGNORE INTO ad_advertiser_tool (company_id, tool_slug, created_at) VALUES (?,?,?)')->execute([$companyId, $c['tool_slug'], $now]);
    crm_audit($pdo, $user, 'ad_booking_create', 'campaign', $campaignId, 'booking #' . $id); if ($own) $pdo->commit(); return [true, (string)$id];
  } catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); sec_log_exception($e, 'ad_booking_create'); return [false, 'storage_error']; }
}
function ad_booking_update(PDO $pdo, ?array $user, int $id, array $in): array {
  if (ad_deny($user, 'write')) return [false, 'forbidden']; $b = ad_booking_get($pdo, $id); if (!$b) return [false, 'not_found']; if (in_array($b['status'], ['completed', 'cancelled', 'expired'], true)) return [false, 'closed'];
  [$ok, $c] = ad_clean_booking($pdo, $in + ['billing' => $b['billing']], $b); if (!$ok) return [false, (string)$c];
  if (in_array($b['status'], AD_HOLDS, true) && $c['package_id'] && $c['start_date'] && $c['end_date']) { $cf = ad_conflicts($pdo, (int)$c['package_id'], $c['tool_slug'], $c['category'], $c['start_date'], $c['end_date'], $id); if ($cf) return [false, 'booking_conflict']; }
  if ($b['contract_id'] && $c['agreed_price_minor'] !== null && (int)$c['agreed_price_minor'] !== (int)$b['agreed_price_minor']) return [false, 'price_locked_by_agreement'];
  $pdo->prepare('UPDATE ad_booking SET package_id=?, tool_slug=?, category=?, agreed_price_minor=?, currency=?, billing=?, start_date=?, end_date=?, renewal_date=?, payment_link=?, account_manager=?, requirements=?, notes=?, updated_at=? WHERE id=?')->execute([$c['package_id'], $c['tool_slug'], $c['category'], $c['agreed_price_minor'], $c['currency'], $c['billing'], $c['start_date'], $c['end_date'], $c['renewal_date'], $c['payment_link'], $c['account_manager'], $c['requirements'], $c['notes'], crm_now(), $id]);
  if ($c['start_date'] || $c['end_date']) $pdo->prepare('UPDATE crm_campaigns SET start_date = ?, end_date = ?, updated_at = ? WHERE id = ?')->execute([$c['start_date'], $c['end_date'], crm_now(), $b['campaign_id']]);
  crm_audit($pdo, $user, 'ad_booking_update', 'campaign', (int)$b['campaign_id'], 'booking #' . $id); return [true, 'ok'];
}

// ---- money: derived from the existing contract and revenue entries (nothing is stored twice) ----------------------------------------------------------
/** @return array{agreed:?int,currency:?string,invoiced:int,received:int,outstanding:int,refunds:int,net_received:int,state:string,has_contract:bool,contract_status:?string} */
function ad_payment_summary(PDO $pdo, array $b): array {
  $o = ['agreed' => $b['agreed_price_minor'] === null ? null : (int)$b['agreed_price_minor'], 'currency' => $b['currency'], 'invoiced' => 0, 'received' => 0, 'outstanding' => 0, 'refunds' => 0, 'net_received' => 0, 'state' => 'no_agreement', 'has_contract' => false, 'contract_status' => null];
  if (empty($b['contract_id'])) return $o; $k = crm_contract_get($pdo, (int)$b['contract_id']); if (!$k) return $o; $o['has_contract'] = true; $o['contract_status'] = $k['status']; $o['currency'] = $k['currency']; if ($o['agreed'] === null) $o['agreed'] = (int)$k['amount_minor'];
  $s = $pdo->prepare("SELECT kind, COALESCE(SUM(amount_minor),0) FROM crm_revenue_entries WHERE contract_id = ? AND is_synthetic = 0 GROUP BY kind"); $s->execute([(int)$b['contract_id']]); $m = $s->fetchAll(PDO::FETCH_KEY_PAIR); $o['invoiced'] = (int)($m['invoiced'] ?? 0); $o['received'] = (int)($m['received'] ?? 0); $o['outstanding'] = max(0, $o['invoiced'] - $o['received']);
  try { $r = $pdo->prepare("SELECT COALESCE(SUM(amount_minor),0) FROM fin_adjustment WHERE contract_id = ? AND kind = 'refund' AND voided_at IS NULL"); $r->execute([(int)$b['contract_id']]); $o['refunds'] = (int)$r->fetchColumn(); } catch (Throwable $e) {}
  $o['net_received'] = $o['received'] - $o['refunds']; $o['state'] = $o['refunds'] > 0 && $o['net_received'] <= 0 ? 'refunded' : ($o['received'] > 0 && $o['agreed'] !== null && $o['received'] >= $o['agreed'] ? 'paid' : ($o['received'] > 0 ? 'partly_paid' : ($o['invoiced'] > 0 ? 'awaiting_payment' : ($k['status'] === 'active' ? 'not_invoiced' : 'agreement_not_confirmed')))); return $o;
}
function ad_payment_state_label(string $s): string { return ['no_agreement' => 'No agreement yet', 'agreement_not_confirmed' => 'Agreement not confirmed', 'not_invoiced' => 'Not invoiced yet', 'awaiting_payment' => 'Awaiting payment', 'partly_paid' => 'Partly paid', 'paid' => 'Paid', 'refunded' => 'Refunded'][$s] ?? $s; }
/**
 * Creates the CRM chain for an agreed booking: advertising opportunity > proposal (one line) > accepted > DRAFT contract, using the existing functions, exactly once per booking.
 * Owner action: it records that the advertiser accepted. A draft contract is not revenue; the owner confirms the signed agreement separately (ad_booking_confirm_agreement).
 */
function ad_booking_create_agreement(PDO $pdo, ?array $user, int $id, bool $confirmed, ?int $nowTs = null): array {
  if (ad_deny($user, 'revenue')) return [false, 'forbidden']; if (!$confirmed) return [false, 'confirmation_required']; $b = ad_booking_get($pdo, $id); if (!$b) return [false, 'not_found']; if ($b['contract_id']) return [false, 'agreement_exists'];
  if (!in_array($b['status'], ['proposal_sent', 'awaiting_payment', 'scheduled', 'active', 'paused'], true)) return [false, 'status_not_ready']; if ($b['agreed_price_minor'] === null || (int)$b['agreed_price_minor'] <= 0 || !$b['currency']) return [false, 'price_required']; if (!$b['start_date'] || !$b['end_date']) return [false, 'dates_required'];
  $own = !$pdo->inTransaction(); if ($own) $pdo->beginTransaction();
  try {
    $amt = ad_minor((int)$b['agreed_price_minor']); [$ok, $oppId] = crm_opp_create($pdo, $user, (int)$b['company_id'], $b['contact_id'] ? (int)$b['contact_id'] : null, 'advertising', ($b['package_name'] ?? 'Advertising') . ' ' . $b['company_name'], $amt, (string)$b['currency'], null, $b['lead_id'] ? (int)$b['lead_id'] : null, 'negotiation'); if (!$ok) { if ($own) $pdo->rollBack(); return [false, (string)$oppId]; }
    $bp = in_array($b['billing'], ['monthly'], true) ? 'monthly' : 'one_off'; [$pk, $propId] = crm_proposal_create($pdo, $user, (int)$oppId, ['currency' => $b['currency'], 'discount' => '', 'tax' => '', 'billing_period' => $bp, 'valid_until' => gmdate('Y-m-d', strtotime(ad_today($nowTs) . ' UTC') + 30 * 86400), 'terms' => 'Advertising booking #' . $id, 'items' => [['description' => ($b['package_name'] ?? 'Advertising') . ($b['tool_slug'] !== '' ? ' - ' . $b['tool_slug'] : '') . ' (' . $b['start_date'] . ' to ' . $b['end_date'] . ')', 'qty' => '1', 'unit' => $amt]]]); if (!$pk) { if ($own) $pdo->rollBack(); return [false, (string)$propId]; }
    foreach (['pending_approval', 'approved', 'accepted'] as $to) { [$sk, $sc] = crm_proposal_set_status($pdo, $user, (int)$propId, $to); if (!$sk) { if ($own) $pdo->rollBack(); return [false, (string)$sc]; } }
    [$ck, $cid] = crm_contract_create($pdo, $user, (int)$propId, (string)$b['start_date'], (string)$b['end_date'], $b['renewal_date'] ?: null, false); if (!$ck) { if ($own) $pdo->rollBack(); return [false, (string)$cid]; }
    $u = $pdo->prepare('UPDATE ad_booking SET opportunity_id = ?, proposal_id = ?, contract_id = ?, updated_at = ? WHERE id = ? AND contract_id IS NULL'); $u->execute([(int)$oppId, (int)$propId, (int)$cid, crm_now($nowTs), $id]); if ($u->rowCount() !== 1) { if ($own) $pdo->rollBack(); return [false, 'agreement_exists']; }
    crm_audit($pdo, $user, 'ad_booking_agreement', 'campaign', (int)$b['campaign_id'], 'contract #' . $cid . ' (draft)'); if ($own) $pdo->commit(); return [true, (string)$cid];
  } catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); sec_log_exception($e, 'ad_booking_create_agreement'); return [false, 'storage_error']; }
}
function ad_booking_confirm_agreement(PDO $pdo, ?array $user, int $id): array { if (ad_deny($user, 'revenue')) return [false, 'forbidden']; $b = ad_booking_get($pdo, $id); if (!$b || !$b['contract_id']) return [false, 'no_agreement']; return crm_contract_confirm($pdo, $user, (int)$b['contract_id']); }
/** Records an invoice or a payment through the existing revenue entries. A reference is mandatory and unique per contract and kind, so the same invoice or payment cannot be entered twice. */
function ad_record_payment(PDO $pdo, ?array $user, int $id, string $kind, string $amount, string $date, string $reference, string $note = ''): array {
  if (ad_deny($user, 'revenue')) return [false, 'forbidden']; $b = ad_booking_get($pdo, $id); if (!$b || !$b['contract_id']) return [false, 'no_agreement']; $ref = crm_line($reference, 60); if ($ref === '') return [false, 'reference_required'];
  $d = $pdo->prepare('SELECT 1 FROM crm_revenue_entries WHERE contract_id = ? AND kind = ? AND reference = ?'); $d->execute([(int)$b['contract_id'], $kind, $ref]); if ($d->fetchColumn()) return [false, 'duplicate_entry'];
  $r = crm_revenue_record($pdo, $user, (int)$b['contract_id'], $kind, $amount, $date, $ref, $note); if ($r[0]) $pdo->prepare('UPDATE ad_booking SET updated_at = ? WHERE id = ?')->execute([crm_now(), $id]); return $r;
}

// ---- workflow -----------------------------------------------------------------------------------------------------------------------------------
function ad_set_campaign_status(PDO $pdo, int $campaignId, string $to): void { $pdo->prepare('UPDATE crm_campaigns SET status = ?, updated_at = ? WHERE id = ?')->execute([$to, crm_now(), $campaignId]); }
function ad_placement_actor(?array $user): string { return (string)($user['username'] ?? 'system'); }
function ad_placement_start(PDO $pdo, ?array $user, array $b, ?int $nowTs = null): array {
  $lvl = ['level1' => 1, 'level2' => 2][(string)($b['public_slot'] ?? 'none')] ?? 0; if ($lvl === 0) return [true, null];   // no public slot for this package: nothing is published
  $st = ad_tool_store($pdo); if (!$st) return [false, 'tool_management_missing']; $t = $b['tool_slug'] !== '' ? $st->get($b['tool_slug']) : null; if (!$t) return [false, 'tool_unknown']; if ($t['status'] !== 'published') return [false, 'tool_not_published'];
  $today = ad_today($nowTs); foreach ($st->placements($b['tool_slug']) as $p) if (($p['status'] ?? '') === 'active' && $p['ends_on'] >= $today) return [false, 'tool_already_placed'];
  $r = $st->addPlacement($b['tool_slug'], $lvl, $today, (string)$b['end_date'], ad_placement_actor($user), 'Advertising booking #' . $b['id'], (int)$t['_version']); if (!$r['ok']) return [false, 'placement_failed'];
  $row = $pdo->prepare("SELECT id FROM adm_tool_feature WHERE tool_slug = ? AND status = 'active' ORDER BY id DESC LIMIT 1"); $row->execute([$b['tool_slug']]); $pid = (int)$row->fetchColumn(); $pdo->prepare("UPDATE adm_tool_feature SET source = 'campaign' WHERE id = ?")->execute([$pid]); return [true, $pid];
}
function ad_placement_stop(PDO $pdo, ?array $user, array $b, string $note): void {
  if (empty($b['placement_id'])) return; $st = ad_tool_store($pdo); if (!$st) return; $t = $st->get((string)$b['tool_slug']); if (!$t) return; try { $st->cancelPlacement((int)$b['placement_id'], ad_placement_actor($user), $note, (int)$t['_version']); } catch (Throwable $e) { sec_log_exception($e, 'ad_placement_stop'); }
}
/**
 * Moves a booking along the lifecycle. Rules per target: proposal_sent needs a package; awaiting_payment needs the owner-approved campaign (existing approval gate: disclosure label + safe landing URL),
 * dates, an agreed price and no inventory conflict; scheduled needs the agreement confirmed and payment received, or an owner override reason, plus no conflict; active is reached only through
 * ad_booking_activate() or the sweep; pausing, completing, cancelling or expiring takes the public placement down.
 */
function ad_booking_move(PDO $pdo, ?array $user, int $id, string $to, string $note = '', ?int $nowTs = null): array {
  if (!ad_ready($pdo)) return [false, 'not_migrated']; $b = ad_booking_get($pdo, $id); if (!$b) return [false, 'not_found']; if (!isset(ad_statuses()[$to]) || !in_array($to, ad_transitions()[$b['status']] ?? [], true)) return [false, 'transition_invalid'];
  if (ad_deny($user, 'write')) return [false, 'forbidden']; if (in_array($to, ['scheduled', 'active'], true) && ad_deny($user, 'approve')) return [false, 'forbidden']; $note = crm_line($note, 255);
  if ($to === 'active') return [false, 'use_activation'];
  if ($to === 'proposal_sent' && !$b['package_id']) return [false, 'package_required'];
  if ($to === 'awaiting_payment') {
    if (!in_array($b['campaign_status'], ['approved', 'scheduled', 'live'], true)) return [false, 'campaign_not_approved']; if (!$b['start_date'] || !$b['end_date'] || !$b['package_id']) return [false, 'dates_required']; if ($b['agreed_price_minor'] === null || !$b['currency']) return [false, 'price_required'];
    if (ad_conflicts($pdo, (int)$b['package_id'], (string)$b['tool_slug'], (string)$b['category'], $b['start_date'], $b['end_date'], $id)) return [false, 'booking_conflict'];
  }
  if ($to === 'scheduled') {
    if (ad_conflicts($pdo, (int)$b['package_id'], (string)$b['tool_slug'], (string)$b['category'], $b['start_date'], $b['end_date'], $id)) return [false, 'booking_conflict']; $pay = ad_payment_summary($pdo, $b); $paid = $pay['agreed'] === null || $pay['agreed'] <= 0 || ($pay['has_contract'] && $pay['received'] >= $pay['agreed']);
    if (!$paid) { if ($note === '') return [false, 'payment_or_override_required']; $pdo->prepare('UPDATE ad_booking SET payment_override = ? WHERE id = ?')->execute([$note, $id]); }
    if ($b['campaign_status'] === 'approved') ad_set_campaign_status($pdo, (int)$b['campaign_id'], 'scheduled');
  }
  if (in_array($to, ['paused', 'completed', 'expired', 'cancelled'], true) && !empty($b['placement_id'])) { ad_placement_stop($pdo, $user, $b, 'Booking #' . $id . ' ' . $to); $pdo->prepare('UPDATE ad_booking SET placement_id = NULL WHERE id = ?')->execute([$id]); }
  if ($to === 'completed') ad_set_campaign_status($pdo, (int)$b['campaign_id'], 'completed'); if ($to === 'cancelled') ad_set_campaign_status($pdo, (int)$b['campaign_id'], 'cancelled'); if ($to === 'expired') ad_set_campaign_status($pdo, (int)$b['campaign_id'], 'completed');
  $pdo->prepare('UPDATE ad_booking SET status = ?, updated_at = ? WHERE id = ?')->execute([$to, crm_now($nowTs), $id]); ad_history($pdo, $id, $b['status'], $to, $note, $user, $nowTs); crm_audit($pdo, $user, 'ad_booking_' . $to, 'campaign', (int)$b['campaign_id'], 'booking #' . $id . ' ' . $b['status'] . ' -> ' . $to); return [true, 'ok'];
}
/** OWNER APPROVAL. Publishes the placement now (or, if the start date is in the future, records the approval so the sweep can start it on the start date). Paused bookings resume with the same call. */
function ad_booking_activate(PDO $pdo, ?array $user, int $id, bool $confirmed, ?int $nowTs = null): array {
  if (ad_deny($user, 'approve')) return [false, 'forbidden']; if (!$confirmed) return [false, 'confirmation_required']; $b = ad_booking_get($pdo, $id); if (!$b) return [false, 'not_found']; if (!in_array($b['status'], ['scheduled', 'paused'], true)) return [false, 'transition_invalid'];
  if (!in_array($b['disclosure_label'], crm_disclosure_labels(), true)) return [false, 'disclosure_label_required']; if ($b['landing_url'] === '' || sec_safe_external_url($b['landing_url']) === null) return [false, 'landing_url_required']; $today = ad_today($nowTs);
  if (!$b['start_date'] || !$b['end_date'] || $b['end_date'] < $today) return [false, 'dates_invalid']; if (ad_conflicts($pdo, (int)$b['package_id'], (string)$b['tool_slug'], (string)$b['category'], $b['start_date'], $b['end_date'], $id)) return [false, 'booking_conflict'];
  $pdo->prepare('UPDATE ad_booking SET activation_approved_by = ?, activation_approved_at = ?, updated_at = ? WHERE id = ?')->execute([$user['id'] ?? null, crm_now($nowTs), crm_now($nowTs), $id]);
  if ($b['status'] === 'scheduled' && $b['start_date'] > $today) { ad_history($pdo, $id, 'scheduled', 'scheduled', 'Activation approved by the owner; starts on ' . $b['start_date'], $user, $nowTs); crm_audit($pdo, $user, 'ad_booking_approve_activation', 'campaign', (int)$b['campaign_id'], 'booking #' . $id); return [true, 'approved_for_start_date']; }
  return ad_start_now($pdo, $user, $b, $nowTs);
}
function ad_start_now(PDO $pdo, ?array $user, array $b, ?int $nowTs = null): array {
  [$ok, $pid] = ad_placement_start($pdo, $user, $b, $nowTs); if (!$ok) return [false, (string)$pid]; $id = (int)$b['id'];
  $pdo->prepare("UPDATE ad_booking SET status = 'active', placement_id = ?, updated_at = ? WHERE id = ?")->execute([$pid, crm_now($nowTs), $id]); if (in_array($b['campaign_status'], ['approved', 'scheduled'], true)) ad_set_campaign_status($pdo, (int)$b['campaign_id'], 'live');
  ad_history($pdo, $id, $b['status'], 'active', $pid ? 'Public placement started' : 'Active (this package has no public slot)', $user, $nowTs); crm_audit($pdo, $user, 'ad_booking_activate', 'campaign', (int)$b['campaign_id'], 'booking #' . $id . ($pid ? ' placement #' . $pid : '')); return [true, 'ok'];
}
/** Housekeeping, run by the owner (button) or manually from the command line; no scheduler is installed. Starts owner-approved scheduled bookings on their start date, ends active ones after their end date (status Expired, placement taken down), expires scheduled ones whose dates passed. */
function ad_sweep(PDO $pdo, ?array $user = null, ?int $nowTs = null): array {
  $r = ['started' => 0, 'expired' => 0, 'errors' => []]; if (!ad_ready($pdo)) return $r + ['not_ready' => true]; $today = ad_today($nowTs);
  foreach ($pdo->query("SELECT id FROM ad_booking WHERE status = 'active' AND end_date < " . $pdo->quote($today))->fetchAll(PDO::FETCH_COLUMN) as $id) { $x = ad_booking_move($pdo, $user ?? ['role' => 'owner', 'id' => null, 'username' => 'sweep'], (int)$id, 'expired', 'End date passed', $nowTs); $x[0] ? $r['expired']++ : $r['errors'][] = "#$id " . $x[1]; }
  foreach ($pdo->query("SELECT id FROM ad_booking WHERE status = 'scheduled' AND end_date < " . $pdo->quote($today))->fetchAll(PDO::FETCH_COLUMN) as $id) { $x = ad_booking_move($pdo, $user ?? ['role' => 'owner', 'id' => null, 'username' => 'sweep'], (int)$id, 'expired', 'Dates passed without activation', $nowTs); $x[0] ? $r['expired']++ : $r['errors'][] = "#$id " . $x[1]; }
  foreach ($pdo->query("SELECT id FROM ad_booking WHERE status = 'scheduled' AND activation_approved_at IS NOT NULL AND start_date <= " . $pdo->quote($today) . " AND end_date >= " . $pdo->quote($today))->fetchAll(PDO::FETCH_COLUMN) as $id) { $b = ad_booking_get($pdo, (int)$id); if (!$b || ad_conflicts($pdo, (int)$b['package_id'], (string)$b['tool_slug'], (string)$b['category'], $b['start_date'], $b['end_date'], (int)$id)) { $r['errors'][] = "#$id conflict"; continue; } $x = ad_start_now($pdo, $user, $b, $nowTs); $x[0] ? $r['started']++ : $r['errors'][] = "#$id " . $x[1]; }
  return $r;
}
