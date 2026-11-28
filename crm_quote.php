<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
/**
 * Professional quotations (Phase 7C), built ON the existing proposal / contract modules (crm_money.php): a quotation IS a proposal.
 * Adds: per-year automatic numbering, configurable tax presets and label, payment milestones, customer-facing intro and terms, a printable branded page
 * (browser "Save as PDF"), sent / accepted / declined records (who, when, how, why), validity state and one-step conversion of an accepted proposal to a draft contract.
 * INTERNAL RECORD KEEPING: nothing here sends a quotation, e-mails a customer, invoices or charges. Requires database/phase7c_quotations.sql; before that the
 * proposal screens behave exactly as in Phase 6 (every function below degrades or reports 'not_migrated').
 */
require_once __DIR__ . '/crm_money.php';

const CRM_QUOTE_MAX_MILESTONES = 6;
function crm_quote_channels(): array { return ['email' => 'E-mail', 'signed_document' => 'Signed document', 'meeting' => 'Meeting', 'call' => 'Phone call', 'other' => 'Other']; }
function crm_quote_due_kinds(): array { return ['on_acceptance' => 'On acceptance', 'days_after_acceptance' => 'Days after acceptance', 'on_delivery' => 'On delivery', 'date' => 'On a fixed date']; }

function crm_quote_ready(PDO $pdo): bool {
  static $memo = []; $k = spl_object_id($pdo); if (isset($memo[$k])) return $memo[$k];
  try { foreach (['crm_proposal_extra', 'crm_proposal_milestones', 'crm_proposal_events', 'crm_quote_counter'] as $t) $pdo->query("SELECT 1 FROM $t LIMIT 1"); return $memo[$k] = true; } catch (Throwable $e) { return false; }
}

// ---- configuration (owner settings; nothing is invented when a value is missing) --------------------------------------------------------
function crm_quote_setting(string $env, string $key, string $default = ''): string { return function_exists('sec_setting') ? trim(sec_setting($env, $key, $default)) : $default; }
function crm_quote_prefix(): string { $p = strtoupper(preg_replace('/[^A-Za-z]/', '', crm_quote_setting('AIHUB_QUOTE_PREFIX', 'quote_prefix', 'Q'))); return $p !== '' ? substr($p, 0, 4) : 'Q'; }
/** Seller details printed on the quotation. Each value comes from AIHUB_QUOTE_* settings; empty ones are simply left out of the print. */
function crm_quote_brand(): array {
  $name = crm_quote_setting('AIHUB_QUOTE_NAME', 'quote_name') ?: (getenv('AIHUB_BUSINESS_NAME') ?: 'TrustNova Tech');
  return ['name' => $name, 'address' => array_values(array_filter(array_map('trim', explode(';', crm_quote_setting('AIHUB_QUOTE_ADDRESS', 'quote_address'))))), 'vat_id' => crm_quote_setting('AIHUB_QUOTE_VAT_ID', 'quote_vat_id'),
    'email' => crm_quote_setting('AIHUB_QUOTE_EMAIL', 'quote_email'), 'phone' => crm_quote_setting('AIHUB_QUOTE_PHONE', 'quote_phone'), 'website' => crm_quote_setting('AIHUB_QUOTE_WEBSITE', 'quote_website'),
    'bank' => crm_quote_setting('AIHUB_QUOTE_BANK', 'quote_bank'), 'footer' => crm_quote_setting('AIHUB_QUOTE_FOOTER', 'quote_footer'), 'default_terms' => crm_quote_setting('AIHUB_QUOTE_DEFAULT_TERMS', 'quote_default_terms'),
    'validity_days' => max(1, min(365, (int)(crm_quote_setting('AIHUB_QUOTE_VALIDITY_DAYS', 'quote_validity_days') ?: 30)))];
}
/** Tax presets, owner-configurable: AIHUB_QUOTE_TAX_PRESETS="VAT 19%=19;VAT 7%=7;Reverse charge (no VAT)=0". Defaults are common German rates and are only a convenience, not tax advice. @return array<int,array{label:string,bp:int}> */
function crm_quote_tax_presets(): array {
  $raw = crm_quote_setting('AIHUB_QUOTE_TAX_PRESETS', 'quote_tax_presets') ?: 'VAT 19%=19;VAT 7%=7;No tax shown=0'; $o = [];
  foreach (explode(';', $raw) as $part) { if (!str_contains($part, '=')) continue; [$l, $r] = array_map('trim', explode('=', $part, 2)); $bp = crm_percent_to_bp($r); if ($l !== '' && $bp !== null && count($o) < 12) $o[] = ['label' => crm_line($l, 60), 'bp' => $bp]; }
  return $o;
}
/** Maps the form's tax selection to [percent string, label]. 'custom' (or anything unknown) keeps the typed percent and label. */
function crm_quote_apply_tax_preset(string $choice, string $typedPct, string $typedLabel): array {
  if (ctype_digit($choice)) { $p = crm_quote_tax_presets()[(int)$choice] ?? null; if ($p) return [rtrim(rtrim(number_format($p['bp'] / 100, 2, '.', ''), '0'), '.') ?: '0', $p['label']]; }
  return [$typedPct, crm_line($typedLabel, 60)];
}

// ---- numbering ---------------------------------------------------------------------------------------------------------------------------
/** Next quotation number, e.g. Q-2026-0001: sequential per calendar year (UTC), atomic (row lock), unique by the existing unique key on crm_proposals.number. */
function crm_quote_next_number(PDO $pdo, ?int $year = null): ?string {
  if (!crm_quote_ready($pdo)) return null; $y = $year ?? (int)gmdate('Y');
  $s = $pdo->prepare('INSERT INTO crm_quote_counter (year_no, last_seq) VALUES (?, 1) ON DUPLICATE KEY UPDATE last_seq = LAST_INSERT_ID(last_seq + 1)'); $s->execute([$y]);
  $seq = $s->rowCount() === 1 ? 1 : (int)$pdo->lastInsertId();
  return sprintf('%s-%d-%04d', crm_quote_prefix(), $y, $seq);
}

// ---- payment milestones (pure helpers) -----------------------------------------------------------------------------------------------------
/** Splits $total over percentages in basis points; every amount is half-up rounded and the LAST one takes the remainder, so the parts always add up to the total exactly. */
function crm_quote_split(int $total, array $bps): array {
  $out = []; $used = 0; $n = count($bps);
  foreach (array_values($bps) as $i => $bp) { $a = $i === $n - 1 ? $total - $used : crm_div_round($total * (int)$bp, 10000); $out[] = $a; $used += $a; }
  return $out;
}
/** @return array{0:bool,1:array|string} rows -> milestone list, or an error code. Empty rows are ignored; if any row is used the percentages must add up to exactly 100. */
function crm_quote_milestones_from_input(array $rows, int $totalMinor): array {
  $m = []; foreach ($rows as $r) {
    if (!is_array($r)) continue; $label = crm_line($r['label'] ?? '', 120); $pct = trim((string)($r['pct'] ?? '')); if ($label === '' && $pct === '') continue;
    if ($label === '' || $pct === '') return [false, 'milestone_invalid']; $bp = crm_percent_to_bp($pct); if ($bp === null || $bp <= 0) return [false, 'milestone_invalid'];
    $kind = (string)($r['due_kind'] ?? 'on_acceptance'); if (!isset(crm_quote_due_kinds()[$kind])) return [false, 'milestone_invalid']; $days = null; $date = null;
    if ($kind === 'days_after_acceptance') { $d = trim((string)($r['due_days'] ?? '')); if (!preg_match('/^\d{1,3}$/', $d) || (int)$d < 1 || (int)$d > 365) return [false, 'milestone_invalid']; $days = (int)$d; }
    if ($kind === 'date') { $t = crm_date((string)($r['due_date'] ?? '')); if ($t === null) return [false, 'milestone_invalid']; $date = gmdate('Y-m-d', $t); }
    $m[] = ['label' => $label, 'bp' => $bp, 'kind' => $kind, 'days' => $days, 'date' => $date];
  }
  if (!$m) return [true, []]; if (count($m) > CRM_QUOTE_MAX_MILESTONES) return [false, 'milestone_invalid'];
  if (array_sum(array_column($m, 'bp')) !== 10000) return [false, 'milestones_not_100'];
  foreach (crm_quote_split($totalMinor, array_column($m, 'bp')) as $i => $a) $m[$i]['amount'] = $a;
  return [true, $m];
}
function crm_quote_due_text(array $m): string {
  return match ($m['due_kind'] ?? '') { 'days_after_acceptance' => (int)$m['due_days'] . ' days after acceptance', 'on_delivery' => 'On delivery', 'date' => 'On ' . ($m['due_date'] ?? ''), default => 'On acceptance' };
}

// ---- storage (called from crm_proposal_save_version) ---------------------------------------------------------------------------------------
function crm_quote_save_extras(PDO $pdo, int $versionId, array $in, array $totals): array {
  if (!crm_quote_ready($pdo)) return [true, 'skipped'];
  [$ok, $ms] = crm_quote_milestones_from_input((array)($in['milestones'] ?? []), (int)$totals['total_minor']); if (!$ok) return [false, (string)$ms];
  $pdo->prepare('INSERT INTO crm_proposal_extra (version_id, tax_label, intro, customer_terms) VALUES (?,?,?,?)')->execute([$versionId, crm_line($in['tax_label'] ?? '', 60), crm_text($in['intro'] ?? '', 2000), crm_text($in['customer_terms'] ?? '', 4000)]);
  $ins = $pdo->prepare('INSERT INTO crm_proposal_milestones (version_id, position, label, pct_bp, amount_minor, due_kind, due_days, due_date) VALUES (?,?,?,?,?,?,?,?)');
  foreach ($ms as $i => $m) $ins->execute([$versionId, $i + 1, $m['label'], $m['bp'], $m['amount'], $m['kind'], $m['days'], $m['date']]);
  return [true, 'ok'];
}
function crm_quote_extra(PDO $pdo, int $versionId): array { if (!crm_quote_ready($pdo)) return []; $s = $pdo->prepare('SELECT * FROM crm_proposal_extra WHERE version_id = ?'); $s->execute([$versionId]); return $s->fetch(PDO::FETCH_ASSOC) ?: []; }
function crm_quote_milestones(PDO $pdo, int $versionId): array { if (!crm_quote_ready($pdo)) return []; $s = $pdo->prepare('SELECT * FROM crm_proposal_milestones WHERE version_id = ? ORDER BY position'); $s->execute([$versionId]); return $s->fetchAll(PDO::FETCH_ASSOC); }
function crm_quote_events(PDO $pdo, int $proposalId): array { if (!crm_quote_ready($pdo)) return []; $s = $pdo->prepare('SELECT e.*, u.display_name AS who FROM crm_proposal_events e LEFT JOIN crm_users u ON u.id = e.recorded_by WHERE e.proposal_id = ? ORDER BY e.id'); $s->execute([$proposalId]); return $s->fetchAll(PDO::FETCH_ASSOC); }

// ---- validity --------------------------------------------------------------------------------------------------------------------------
/** @return array{state:string,days:?int} state: none (no date) | valid | expiring (7 days or fewer) | expired. A closed proposal (accepted, declined, withdrawn) is not judged by its validity. */
function crm_quote_validity(array $p, ?int $nowTs = null): array {
  if (empty($p['valid_until'])) return ['state' => 'none', 'days' => null]; if (in_array($p['status'] ?? '', ['accepted', 'declined', 'withdrawn', 'expired'], true)) return ['state' => $p['status'] === 'expired' ? 'expired' : 'none', 'days' => null];
  $today = strtotime(gmdate('Y-m-d', $nowTs ?? time()) . ' UTC'); $end = strtotime($p['valid_until'] . ' UTC'); $d = (int)round(($end - $today) / 86400);
  return ['state' => $d < 0 ? 'expired' : ($d <= 7 ? 'expiring' : 'valid'), 'days' => $d];
}

// ---- sent / accepted / declined records ----------------------------------------------------------------------------------------------------
function crm_quote_event_date(string $s, ?int $nowTs = null): ?string { $t = crm_date($s); if ($t === null || $t > strtotime(gmdate('Y-m-d', $nowTs ?? time()) . ' UTC')) return null; return gmdate('Y-m-d', $t); }
/** Records that an approved proposal was handed to the customer (outside this system). */
function crm_quote_mark_sent(PDO $pdo, ?array $user, int $id, string $date, string $channel, string $contact = '', ?int $nowTs = null): array {
  if (crm_deny($user, 'write')) return [false, 'forbidden']; if (!crm_quote_ready($pdo)) return [false, 'not_migrated'];
  $p = crm_proposal_get($pdo, $id); if (!$p) return [false, 'not_found']; if ($p['status'] !== 'approved') return [false, 'send_needs_approved'];
  $d = crm_quote_event_date($date, $nowTs); if ($d === null) return [false, 'date_invalid']; if (!isset(crm_quote_channels()[$channel])) return [false, 'channel_invalid'];
  $pdo->prepare("INSERT INTO crm_proposal_events (proposal_id, version, kind, event_date, channel, contact, recorded_by, recorded_at) VALUES (?,?,'sent',?,?,?,?,?)")->execute([$id, (int)$p['current_version'], $d, $channel, crm_line($contact, 120), $user['id'] ?? null, crm_now($nowTs)]);
  crm_audit($pdo, $user, 'proposal_sent', 'proposal', $id, $p['number'] . ' v' . $p['current_version'] . ' via ' . $channel); return [true, 'ok'];
}
/** Records the customer's decision with date, channel, contact and reference; declining needs a reason; accepting after the validity date is refused. Updates the proposal status. */
function crm_quote_record_decision(PDO $pdo, ?array $user, int $id, string $decision, string $date, string $channel, string $contact = '', string $reference = '', string $reason = '', ?int $nowTs = null): array {
  if (crm_deny($user, 'revenue')) return [false, 'forbidden']; if (!crm_quote_ready($pdo)) return [false, 'not_migrated']; if (!in_array($decision, ['accepted', 'declined'], true)) return [false, 'decision_invalid'];
  $p = crm_proposal_get($pdo, $id); if (!$p) return [false, 'not_found']; if (!proposal_can_move($p['status'], $decision)) return [false, 'transition_invalid'];
  $d = crm_quote_event_date($date, $nowTs); if ($d === null) return [false, 'date_invalid']; if ($d < substr($p['created_at'], 0, 10)) return [false, 'date_before_proposal']; if (!isset(crm_quote_channels()[$channel])) return [false, 'channel_invalid'];
  $reason = crm_line($reason, 255); if ($decision === 'declined' && $reason === '') return [false, 'reason_required']; if ($decision === 'accepted' && $p['valid_until'] !== null && $d > $p['valid_until']) return [false, 'decision_after_validity'];
  $own = !$pdo->inTransaction(); if ($own) $pdo->beginTransaction();
  try {
    [$ok, $code] = crm_proposal_set_status($pdo, $user, $id, $decision); if (!$ok) { if ($own) $pdo->rollBack(); return [false, $code]; }
    $pdo->prepare('INSERT INTO crm_proposal_events (proposal_id, version, kind, event_date, channel, contact, reference, reason, recorded_by, recorded_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id, (int)$p['current_version'], $decision, $d, $channel, crm_line($contact, 120), crm_line($reference, 190), $reason, $user['id'] ?? null, crm_now($nowTs)]);
    if ($own) $pdo->commit(); return [true, 'ok'];
  } catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); sec_log_exception($e, 'crm_quote_record_decision'); return [false, 'storage_error']; }
}

// ---- conversion -----------------------------------------------------------------------------------------------------------------------------
function crm_quote_contract_for(PDO $pdo, int $proposalId): ?array { $s = $pdo->prepare("SELECT * FROM crm_contracts WHERE proposal_id = ? ORDER BY id LIMIT 1"); $s->execute([$proposalId]); return $s->fetch(PDO::FETCH_ASSOC) ?: null; }
/** The one conversion path (replaces a bare contract form): accepted proposal -> exactly one DRAFT contract (a draft is not revenue), opportunity moved to won when its rules allow. */
function crm_quote_convert(PDO $pdo, ?array $user, int $proposalId, string $start, ?string $end, ?string $renewal, bool $autoRenew = false): array {
  if (crm_deny($user, 'revenue')) return [false, 'forbidden']; $p = crm_proposal_get($pdo, $proposalId); if (!$p) return [false, 'not_found'];
  if ($p['status'] !== 'accepted') return [false, 'proposal_not_accepted']; if ($ex = crm_quote_contract_for($pdo, $proposalId)) return [false, 'already_converted'];
  if (trim($start) === '') { $start = gmdate('Y-m-d'); foreach (crm_quote_events($pdo, $proposalId) as $e) if ($e['kind'] === 'accepted') $start = $e['event_date']; }
  [$ok, $cid] = crm_contract_create($pdo, $user, $proposalId, $start, $end, $renewal, $autoRenew); if (!$ok) return [false, (string)$cid];
  $note = 'opportunity unchanged'; $o = crm_opp_get($pdo, (int)$p['opportunity_id']);
  if ($o && $o['stage'] !== 'won') { [$mv, $mc] = crm_opp_move($pdo, $user, (int)$o['id'], 'won'); $note = $mv ? 'opportunity moved to won' : 'opportunity not moved (' . $mc . ')'; }
  crm_audit($pdo, $user, 'proposal_convert', 'proposal', $proposalId, $p['number'] . ' -> contract #' . $cid . '; ' . $note); return [true, (string)$cid];
}

// ---- printable quotation (standalone page; browser Print > Save as PDF) -----------------------------------------------------------------------
function crm_quote_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
/** Full HTML document for one quotation, or null if it does not exist. Every value is escaped. A proposal that is not approved or accepted is stamped so a draft cannot pass for an offer. */
function crm_quote_print_html(PDO $pdo, int $id, ?int $nowTs = null): ?string {
  $p = crm_proposal_get($pdo, $id); if (!$p) return null; $b = crm_quote_brand(); $items = crm_proposal_items($pdo, (int)$p['version_id']); $ex = crm_quote_extra($pdo, (int)$p['version_id']); $ms = crm_quote_milestones($pdo, (int)$p['version_id']);
  $o = crm_opp_get($pdo, (int)$p['opportunity_id']) ?: []; $company = ''; $contact = ''; if ($o) { $c = $pdo->prepare('SELECT c.name AS company, k.name AS contact FROM crm_companies c LEFT JOIN crm_contacts k ON k.id = ? WHERE c.id = ?'); $c->execute([$o['contact_id'] ?? 0, $o['company_id'] ?? 0]); $r = $c->fetch(PDO::FETCH_ASSOC) ?: []; $company = (string)($r['company'] ?? ''); $contact = (string)($r['contact'] ?? ''); }
  $ver = crm_proposal_versions($pdo, $id); $issued = $ver ? substr((string)$ver[0]['created_at'], 0, 10) : substr((string)$p['created_at'], 0, 10); $cur = $p['currency']; $m = fn($x) => crm_quote_h(crm_money_format((int)$x, $cur));
  $val = crm_quote_validity($p, $nowTs); $stamp = ''; $st = (string)$p['status'];
  if (!in_array($st, ['approved', 'accepted'], true)) $stamp = $st === 'declined' || $st === 'withdrawn' || $st === 'expired' ? strtoupper(proposal_statuses()[$st] ?? $st) : 'DRAFT - NOT APPROVED'; elseif ($val['state'] === 'expired') $stamp = 'VALIDITY ENDED';
  $per = ['one_off' => 'one-off', 'monthly' => 'per month', 'annual' => 'per year'][$p['billing_period']] ?? '';
  $h = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Quotation ' . crm_quote_h($p['number']) . ' - ' . crm_quote_h($b['name']) . '</title><style>'
    . ':root{--ink:#1c1740;--muted:#5f5a7a;--line:#e3def3;--brand:#4f2fd6}*{box-sizing:border-box}body{margin:0;background:#f4f2fb;color:var(--ink);font:14px/1.5 "Helvetica Neue",Arial,sans-serif}.bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;max-width:820px;margin:16px auto 0;padding:0 16px}.bar a,.bar button{font:inherit;min-height:44px;padding:0 16px;border-radius:10px;border:1px solid var(--brand);background:var(--brand);color:#fff;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center}.bar a.sec{background:#fff;color:var(--brand)}'
    . '.page{position:relative;max-width:820px;margin:12px auto 32px;background:#fff;padding:40px 44px;border:1px solid var(--line);border-radius:12px}.stamp{position:absolute;top:150px;right:30px;transform:rotate(8deg);border:3px solid #b3261e;color:#b3261e;font-weight:800;letter-spacing:.06em;padding:6px 14px;border-radius:6px;opacity:.85}'
    . '.head{display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap;border-bottom:3px solid var(--brand);padding-bottom:14px}.brand{font-size:24px;font-weight:800;color:var(--brand)}.seller{font-size:12px;color:var(--muted);text-align:right}h1{margin:22px 0 4px;font-size:26px}.meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px 24px;margin:14px 0 22px}.meta b{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}'
    . 'table{width:100%;border-collapse:collapse;margin:8px 0 14px}th,td{padding:8px 6px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}th{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}td.n,th.n{text-align:right;white-space:nowrap}.tot{max-width:340px;margin-left:auto}.tot td,.tot th{border:0;padding:4px 6px}.tot .g th,.tot .g td{border-top:2px solid var(--ink);font-size:16px;font-weight:800;padding-top:8px}'
    . 'h2{font-size:15px;margin:22px 0 6px}.small{font-size:12px;color:var(--muted)}pre{white-space:pre-wrap;font:inherit;margin:0}.sign{display:grid;grid-template-columns:1fr 1fr;gap:28px;margin-top:28px}.sign div{border-top:1px solid var(--ink);padding-top:6px;font-size:12px;color:var(--muted)}.foot{margin-top:26px;border-top:1px solid var(--line);padding-top:10px;font-size:11px;color:var(--muted)}'
    . '@media print{body{background:#fff}.bar{display:none}.page{border:0;border-radius:0;margin:0;padding:0;max-width:none}.stamp{top:120px}@page{size:A4;margin:16mm}}@media (max-width:600px){.page{padding:20px 16px}.sign{grid-template-columns:1fr}table{font-size:12px}th,td{padding:6px 4px}}.tw{overflow-x:auto}</style></head><body>'
    . '<div class="bar"><a class="sec" href="/crm.php?v=proposal&amp;id=' . (int)$id . '">Back to the proposal</a><button type="button" onclick="window.print()">Print or save as PDF</button></div><div class="page">' . ($stamp !== '' ? '<div class="stamp">' . crm_quote_h($stamp) . '</div>' : '')
    . '<div class="head"><div class="brand">' . crm_quote_h($b['name']) . '</div><div class="seller">' . implode('<br>', array_map('crm_quote_h', $b['address'])) . ($b['address'] ? '<br>' : '') . ($b['email'] ? crm_quote_h($b['email']) . '<br>' : '') . ($b['phone'] ? crm_quote_h($b['phone']) . '<br>' : '') . ($b['website'] ? crm_quote_h($b['website']) . '<br>' : '') . ($b['vat_id'] ? 'VAT ID: ' . crm_quote_h($b['vat_id']) : '') . '</div></div>'
    . '<h1>Quotation ' . crm_quote_h($p['number']) . '</h1><div class="small">Version ' . (int)$p['current_version'] . '</div><div class="meta"><div><b>Prepared for</b>' . crm_quote_h($company ?: '-') . ($contact !== '' ? '<br>' . crm_quote_h($contact) : '') . '</div><div><b>Date</b>' . crm_quote_h($issued) . '</div><div><b>Valid until</b>' . crm_quote_h($p['valid_until'] ?: 'on request') . '</div><div><b>Currency</b>' . crm_quote_h($cur) . ($per ? ' (' . crm_quote_h($per) . ')' : '') . '</div></div>'
    . (!empty($ex['intro']) ? '<pre>' . crm_quote_h($ex['intro']) . '</pre>' : '')
    . '<div class="tw" role="region" tabindex="0" aria-label="Quotation lines"><table><thead><tr><th>#</th><th>Description</th><th class="n">Qty</th><th class="n">Unit price</th><th class="n">Disc.</th><th class="n">Amount</th></tr></thead><tbody>';
  foreach ($items as $i) $h .= '<tr><td>' . (int)$i['position'] . '</td><td>' . crm_quote_h($i['description']) . '</td><td class="n">' . crm_quote_h(rtrim(rtrim(number_format($i['qty_x100'] / 100, 2, '.', ''), '0'), '.')) . '</td><td class="n">' . $m($i['unit_minor']) . '</td><td class="n">' . ((int)$i['discount_bp'] > 0 ? crm_quote_h(rtrim(rtrim(number_format($i['discount_bp'] / 100, 2, '.', ''), '0'), '.')) . '%' : '') . '</td><td class="n">' . $m($i['line_total_minor']) . '</td></tr>';
  $taxLbl = ($ex['tax_label'] ?? '') !== '' ? $ex['tax_label'] : 'Tax';
  $h .= '</tbody></table></div><table class="tot"><tbody><tr><th scope="row">Subtotal</th><td class="n">' . $m($p['subtotal_minor']) . '</td></tr>' . ((int)$p['discount_minor'] > 0 ? '<tr><th scope="row">Discount (' . crm_quote_h(rtrim(rtrim(number_format($p['discount_bp'] / 100, 2, '.', ''), '0'), '.')) . '%)</th><td class="n">-' . $m($p['discount_minor']) . '</td></tr>' : '')
    . ((int)$p['tax_bp'] > 0 || ($ex['tax_label'] ?? '') !== '' ? '<tr><th scope="row">' . crm_quote_h($taxLbl) . ((int)$p['tax_bp'] > 0 ? ' (' . crm_quote_h(rtrim(rtrim(number_format($p['tax_bp'] / 100, 2, '.', ''), '0'), '.')) . '%)' : '') . '</th><td class="n">' . $m($p['tax_minor']) . '</td></tr>' : '') . '<tr class="g"><th scope="row">Total</th><td class="n">' . $m($p['total_minor']) . '</td></tr></tbody></table>';
  if ($ms) { $h .= '<h2>Payment schedule</h2><div class="tw" role="region" tabindex="0" aria-label="Payment schedule"><table><thead><tr><th>Milestone</th><th>Due</th><th class="n">Share</th><th class="n">Amount</th></tr></thead><tbody>'; foreach ($ms as $x) $h .= '<tr><td>' . crm_quote_h($x['label']) . '</td><td>' . crm_quote_h(crm_quote_due_text($x)) . '</td><td class="n">' . crm_quote_h(rtrim(rtrim(number_format($x['pct_bp'] / 100, 2, '.', ''), '0'), '.')) . '%</td><td class="n">' . $m($x['amount_minor']) . '</td></tr>'; $h .= '</tbody></table></div>'; }
  if ($b['bank']) $h .= '<h2>Payment details</h2><div class="small">' . crm_quote_h($b['bank']) . '</div>';
  $terms = trim((string)($ex['customer_terms'] ?? '')); if ($terms !== '') $h .= '<h2>Terms</h2><pre class="small">' . crm_quote_h($terms) . '</pre>';
  $h .= '<h2>Acceptance</h2><div class="small">To accept this quotation, sign and return it' . ($p['valid_until'] ? ' by ' . crm_quote_h($p['valid_until']) : '') . '. Prices exclude anything not listed above.</div><div class="sign"><div>Name, company</div><div>Date and signature</div></div>'
    . '<div class="foot">' . crm_quote_h($b['footer'] ?: $b['name']) . ' - Quotation ' . crm_quote_h($p['number']) . '</div></div></body></html>';
  return $h;
}
