<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
/**
 * Proposals, contracts and revenue classification (Phase 6). INTERNAL RECORD KEEPING ONLY: nothing here sends a proposal, issues an invoice,
 * charges a card or creates a subscription. All amounts are integer minor units; rounding is half-up on integers; currencies are never mixed.
 *
 * Revenue vocabulary (kept apart on purpose):
 *   proposed    value of proposals that are draft / pending approval / approved (not accepted)
 *   forecast    open opportunity value × stage weight: a HYPOTHETICAL planning figure, never revenue
 *   contracted  value of contracts a person confirmed as signed (status active) over their term
 *   invoiced    amounts a person recorded as invoiced outside this system
 *   received    amounts a person recorded as received outside this system
 *   recognized  contracted value earned to date on a simple, conservative schedule (whole elapsed months; one-off after its end date)
 *   MRR / ARR   recurring contracts that are active and not cancelled on the report date
 * This is a management view, not accounting or tax advice.
 */
require_once __DIR__ . '/crm_sales.php';

const CRM_MAX_UNIT_MINOR = 1000000000;    // 10 million currency units per unit price
const CRM_MAX_QTY_X100 = 1000000;         // 10,000 units
const CRM_MAX_ITEMS = 50;                 // with the two limits above, subtotal × 10000 stays below PHP_INT_MAX (no silent float overflow)
/** Half-up integer division for n >= 0 and an EVEN divisor d (100, 10000, 12 are the only ones used). Adding d/2 avoids doubling n, which would overflow. */
function crm_div_round(int $n, int $d): int { return intdiv($n + intdiv($d, 2), $d); }

/**
 * @param array<int,array{description?:string,qty_x100:int,unit_minor:int,discount_bp?:int}> $items
 * @return array{ok:bool,error?:string,lines?:array,subtotal_minor?:int,discount_minor?:int,tax_minor?:int,total_minor?:int}
 */
function proposal_totals(array $items, int $discountBp = 0, int $taxBp = 0): array {
  if (!$items) return ['ok' => false, 'error' => 'no_items'];
  if (count($items) > CRM_MAX_ITEMS) return ['ok' => false, 'error' => 'too_many_items'];
  if ($discountBp < 0 || $discountBp > 10000) return ['ok' => false, 'error' => 'discount_invalid'];
  if ($taxBp < 0 || $taxBp > 10000) return ['ok' => false, 'error' => 'tax_invalid'];
  $lines = []; $sub = 0;
  foreach (array_values($items) as $i => $it) {
    $q = (int)($it['qty_x100'] ?? 0); $u = (int)($it['unit_minor'] ?? -1); $d = (int)($it['discount_bp'] ?? 0);
    if ($q <= 0 || $q > CRM_MAX_QTY_X100) return ['ok' => false, 'error' => 'qty_invalid'];
    if ($u < 0 || $u > CRM_MAX_UNIT_MINOR) return ['ok' => false, 'error' => 'unit_invalid'];
    if ($d < 0 || $d > 10000) return ['ok' => false, 'error' => 'line_discount_invalid'];
    $gross = crm_div_round($q * $u, 100); $net = $gross - crm_div_round($gross * $d, 10000);
    $lines[] = ['position' => $i + 1, 'description' => (string)($it['description'] ?? ''), 'qty_x100' => $q, 'unit_minor' => $u, 'discount_bp' => $d, 'line_total_minor' => $net]; $sub += $net;
  }
  $disc = crm_div_round($sub * $discountBp, 10000); $taxable = $sub - $disc; $tax = crm_div_round($taxable * $taxBp, 10000);
  return ['ok' => true, 'lines' => $lines, 'subtotal_minor' => $sub, 'discount_minor' => $disc, 'tax_minor' => $tax, 'total_minor' => $taxable + $tax];
}
/** Parses form rows (description, qty "2", unit "1500.00", discount "10") into proposal_totals() items. */
function proposal_items_from_input(array $rows): array {
  $items = [];
  foreach ($rows as $r) {
    if (!is_array($r)) continue; $desc = crm_line($r['description'] ?? '', 255); if ($desc === '' && ($r['unit'] ?? '') === '') continue;
    $qty = crm_money_parse((string)($r['qty'] ?? '1')); $unit = crm_money_parse((string)($r['unit'] ?? '')); $disc = crm_percent_to_bp((string)($r['discount'] ?? ''));
    if ($desc === '' || $qty === null || $unit === null || $disc === null) return [false, 'item_invalid'];
    $items[] = ['description' => $desc, 'qty_x100' => $qty, 'unit_minor' => $unit, 'discount_bp' => $disc];
  }
  return [true, $items];
}
function proposal_statuses(): array { return ['draft' => 'Draft', 'pending_approval' => 'Pending approval', 'approved' => 'Approved (internal)', 'accepted' => 'Accepted by customer', 'declined' => 'Declined', 'expired' => 'Expired', 'withdrawn' => 'Withdrawn']; }
function proposal_can_move(string $from, string $to): bool {
  return in_array($to, ['draft' => ['pending_approval', 'withdrawn'], 'pending_approval' => ['approved', 'draft', 'withdrawn'], 'approved' => ['accepted', 'declined', 'expired', 'withdrawn'], 'accepted' => [], 'declined' => [], 'expired' => [], 'withdrawn' => []][$from] ?? [], true);
}

function crm_proposal_save_version(PDO $pdo, ?array $user, int $proposalId, int $version, array $in): array {
  $cur = (string)($in['currency'] ?? ''); if (!crm_currency_ok($cur)) return [false, 'currency_invalid'];
  [$ok, $items] = proposal_items_from_input((array)($in['items'] ?? [])); if (!$ok) return [false, (string)$items];
  $disc = crm_percent_to_bp((string)($in['discount'] ?? '')); $tax = crm_percent_to_bp((string)($in['tax'] ?? '')); if ($disc === null) return [false, 'discount_invalid']; if ($tax === null) return [false, 'tax_invalid'];
  $period = in_array($in['billing_period'] ?? 'one_off', ['one_off', 'monthly', 'annual'], true) ? $in['billing_period'] : 'one_off';
  $valid = null; if (($in['valid_until'] ?? '') !== '') { $t = crm_date((string)$in['valid_until']); if ($t === null) return [false, 'valid_until_invalid']; if ($t < strtotime('today UTC')) return [false, 'valid_until_past']; $valid = gmdate('Y-m-d', $t); }
  $tot = proposal_totals($items, $disc, $tax); if (!$tot['ok']) return [false, $tot['error']];
  $pdo->prepare('INSERT INTO crm_proposal_versions (proposal_id, version, currency, valid_until, discount_bp, tax_bp, subtotal_minor, discount_minor, tax_minor, total_minor, billing_period, terms, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$proposalId, $version, $cur, $valid, $disc, $tax, $tot['subtotal_minor'], $tot['discount_minor'], $tot['tax_minor'], $tot['total_minor'], $period, crm_text($in['terms'] ?? '', 4000), $user['id'] ?? null, crm_now()]);
  $vid = (int)$pdo->lastInsertId(); $ins = $pdo->prepare('INSERT INTO crm_proposal_items (version_id, position, description, qty_x100, unit_minor, discount_bp, line_total_minor) VALUES (?,?,?,?,?,?,?)');
  foreach ($tot['lines'] as $l) $ins->execute([$vid, $l['position'], $l['description'], $l['qty_x100'], $l['unit_minor'], $l['discount_bp'], $l['line_total_minor']]);
  if (function_exists('crm_quote_save_extras')) { [$xo, $xe] = crm_quote_save_extras($pdo, $vid, $in, $tot); if (!$xo) return [false, $xe]; }   // Phase 7C: tax label, intro, customer terms, payment milestones
  return [true, (string)$vid];
}
function crm_proposal_create(PDO $pdo, ?array $user, int $oppId, array $in): array {
  if (crm_deny($user, 'write')) return [false, 'forbidden'];
  if (!crm_opp_get($pdo, $oppId)) return [false, 'opportunity_invalid'];
  $own = !$pdo->inTransaction(); if ($own) $pdo->beginTransaction();
  try {
    $now = crm_now(); $tmp = 'T' . bin2hex(random_bytes(8));
    $pdo->prepare("INSERT INTO crm_proposals (opportunity_id, number, status, current_version, created_by, created_at, updated_at) VALUES (?,?,'draft',1,?,?,?)")->execute([$oppId, $tmp, $user['id'] ?? null, $now, $now]);
    $id = (int)$pdo->lastInsertId(); $number = (function_exists('crm_quote_next_number') ? crm_quote_next_number($pdo) : null) ?? 'P-' . gmdate('Y') . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);   // Phase 7C: sequential per year once migrated
    $pdo->prepare('UPDATE crm_proposals SET number = ? WHERE id = ?')->execute([$number, $id]);
    [$ok, $err] = crm_proposal_save_version($pdo, $user, $id, 1, $in); if (!$ok) { if ($own) $pdo->rollBack(); return [false, $err]; }
    crm_audit($pdo, $user, 'proposal_create', 'proposal', $id, $number); if ($own) $pdo->commit(); return [true, (string)$id];
  } catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); sec_log_exception($e, 'crm_proposal_create'); return [false, 'storage_error']; }
}
/** A change creates a NEW version; earlier versions stay readable and the proposal returns to draft (approval is needed again). */
function crm_proposal_revise(PDO $pdo, ?array $user, int $id, array $in): array {
  if (crm_deny($user, 'write')) return [false, 'forbidden'];
  $p = crm_proposal_get($pdo, $id); if (!$p) return [false, 'not_found'];
  if (!in_array($p['status'], ['draft', 'pending_approval', 'approved'], true)) return [false, 'proposal_closed'];
  $own = !$pdo->inTransaction(); if ($own) $pdo->beginTransaction();
  try {
    $v = (int)$p['current_version'] + 1; [$ok, $err] = crm_proposal_save_version($pdo, $user, $id, $v, $in); if (!$ok) { if ($own) $pdo->rollBack(); return [false, $err]; }
    $pdo->prepare("UPDATE crm_proposals SET current_version = ?, status = 'draft', updated_at = ? WHERE id = ?")->execute([$v, crm_now(), $id]);
    crm_audit($pdo, $user, 'proposal_revise', 'proposal', $id, $p['number'] . ' v' . $v); if ($own) $pdo->commit(); return [true, (string)$v];
  } catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); sec_log_exception($e, 'crm_proposal_revise'); return [false, 'storage_error']; }
}
function crm_proposal_get(PDO $pdo, int $id): ?array {
  $s = $pdo->prepare('SELECT p.*, v.currency, v.valid_until, v.discount_bp, v.tax_bp, v.subtotal_minor, v.discount_minor, v.tax_minor, v.total_minor, v.billing_period, v.terms, v.id AS version_id FROM crm_proposals p JOIN crm_proposal_versions v ON v.proposal_id = p.id AND v.version = p.current_version WHERE p.id = ?');
  $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}
function crm_proposal_items(PDO $pdo, int $versionId): array { $s = $pdo->prepare('SELECT * FROM crm_proposal_items WHERE version_id = ? ORDER BY position'); $s->execute([$versionId]); return $s->fetchAll(PDO::FETCH_ASSOC); }
function crm_proposal_versions(PDO $pdo, int $id): array { $s = $pdo->prepare('SELECT version, currency, total_minor, valid_until, created_at FROM crm_proposal_versions WHERE proposal_id = ? ORDER BY version DESC'); $s->execute([$id]); return $s->fetchAll(PDO::FETCH_ASSOC); }
function crm_proposals_list(PDO $pdo, ?int $oppId = null, int $limit = 100): array {
  $s = $pdo->prepare('SELECT p.id, p.number, p.status, p.current_version, p.opportunity_id, v.currency, v.total_minor, v.valid_until, v.billing_period, o.title AS opportunity FROM crm_proposals p JOIN crm_proposal_versions v ON v.proposal_id = p.id AND v.version = p.current_version JOIN crm_opportunities o ON o.id = p.opportunity_id' . ($oppId ? ' WHERE p.opportunity_id = ?' : '') . ' ORDER BY p.id DESC LIMIT ' . max(1, min(500, $limit)));
  $s->execute($oppId ? [$oppId] : []); return $s->fetchAll(PDO::FETCH_ASSOC);
}
/** Status change. Approval needs the "approve" permission (owner); recording the customer's decision needs "revenue". */
function crm_proposal_set_status(PDO $pdo, ?array $user, int $id, string $to): array {
  $p = crm_proposal_get($pdo, $id); if (!$p) return [false, 'not_found'];
  if (!isset(proposal_statuses()[$to]) || !proposal_can_move($p['status'], $to)) return [false, 'transition_invalid'];
  $need = ['approved' => 'approve', 'accepted' => 'revenue', 'declined' => 'revenue', 'expired' => 'revenue']; if (crm_deny($user, $need[$to] ?? 'write')) return [false, 'forbidden'];
  if ($to === 'approved' && $p['valid_until'] !== null && crm_date($p['valid_until']) < strtotime('today UTC')) return [false, 'proposal_expired'];
  $pdo->prepare('UPDATE crm_proposals SET status = ?, updated_at = ? WHERE id = ?')->execute([$to, crm_now(), $id]);
  crm_audit($pdo, $user, 'proposal_' . $to, 'proposal', $id, $p['number'] . ' ' . $p['status'] . ' → ' . $to); return [true, 'ok'];
}

// ---- contracts -----------------------------------------------------------------------------------------------------------------------
function crm_contract_statuses(): array { return ['draft' => 'Draft', 'active' => 'Active (signed, confirmed)', 'cancelled' => 'Cancelled', 'expired' => 'Expired']; }
/** Creates a DRAFT contract from an ACCEPTED proposal. A draft is not revenue. */
function crm_contract_create(PDO $pdo, ?array $user, int $proposalId, string $start, ?string $end, ?string $renewal, bool $autoRenew = false): array {
  if (crm_deny($user, 'revenue')) return [false, 'forbidden'];
  $p = crm_proposal_get($pdo, $proposalId); if (!$p) return [false, 'not_found']; if ($p['status'] !== 'accepted') return [false, 'proposal_not_accepted'];
  $o = crm_opp_get($pdo, (int)$p['opportunity_id']); if (!$o) return [false, 'opportunity_invalid'];
  $s = crm_date($start); if ($s === null) return [false, 'start_invalid']; $e = null; if ($end !== null && $end !== '') { $e = crm_date($end); if ($e === null || $e < $s) return [false, 'end_invalid']; }
  $r = null; if ($renewal !== null && $renewal !== '') { $r = crm_date($renewal); if ($r === null) return [false, 'renewal_invalid']; }
  if ($p['billing_period'] !== 'one_off' && !$e && !$r) return [false, 'term_required'];
  $pdo->prepare("INSERT INTO crm_contracts (proposal_id, opportunity_id, company_id, status, start_date, end_date, billing_period, amount_minor, currency, renewal_date, auto_renew, created_by, created_at) VALUES (?,?,?,'draft',?,?,?,?,?,?,?,?,?)")
      ->execute([$proposalId, $o['id'], $o['company_id'], gmdate('Y-m-d', $s), $e ? gmdate('Y-m-d', $e) : null, $p['billing_period'], (int)$p['total_minor'], $p['currency'], $r ? gmdate('Y-m-d', $r) : null, $autoRenew ? 1 : 0, $user['id'] ?? null, crm_now()]);
  $id = (int)$pdo->lastInsertId(); crm_audit($pdo, $user, 'contract_create', 'contract', $id, $p['number']); return [true, (string)$id];
}
function crm_contract_get(PDO $pdo, int $id): ?array { $s = $pdo->prepare('SELECT k.*, c.name AS company_name FROM crm_contracts k LEFT JOIN crm_companies c ON c.id = k.company_id WHERE k.id = ?'); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC) ?: null; }
/** A person attests that a signed agreement exists. Only then does the contract count as contracted revenue. */
function crm_contract_confirm(PDO $pdo, ?array $user, int $id): array {
  if (crm_deny($user, 'revenue')) return [false, 'forbidden']; $k = crm_contract_get($pdo, $id); if (!$k) return [false, 'not_found']; if ($k['status'] !== 'draft') return [false, 'transition_invalid'];
  $pdo->prepare("UPDATE crm_contracts SET status = 'active', confirmed_by = ?, confirmed_at = ? WHERE id = ?")->execute([$user['id'] ?? null, crm_now(), $id]);
  crm_audit($pdo, $user, 'contract_confirm', 'contract', $id, 'signed agreement attested'); return [true, 'ok'];
}
function crm_contract_cancel(PDO $pdo, ?array $user, int $id, string $reason, ?string $effective = null): array {
  if (crm_deny($user, 'revenue')) return [false, 'forbidden']; $k = crm_contract_get($pdo, $id); if (!$k) return [false, 'not_found'];
  if (!in_array($k['status'], ['draft', 'active'], true)) return [false, 'transition_invalid']; $reason = crm_line($reason, 190); if ($reason === '') return [false, 'reason_required'];
  $t = $effective ? crm_date($effective) : time(); if ($t === null) return [false, 'date_invalid'];
  $pdo->prepare("UPDATE crm_contracts SET status = 'cancelled', cancelled_at = ?, cancel_reason = ? WHERE id = ?")->execute([crm_now($t), $reason, $id]);
  crm_audit($pdo, $user, 'contract_cancel', 'contract', $id, $reason); return [true, 'ok'];
}
function crm_contract_renew(PDO $pdo, ?array $user, int $id, string $newEnd): array {
  if (crm_deny($user, 'revenue')) return [false, 'forbidden']; $k = crm_contract_get($pdo, $id); if (!$k) return [false, 'not_found']; if ($k['status'] !== 'active') return [false, 'transition_invalid'];
  $t = crm_date($newEnd); if ($t === null || ($k['end_date'] && $t <= crm_date($k['end_date']))) return [false, 'end_invalid'];
  $pdo->prepare('UPDATE crm_contracts SET end_date = ?, renewal_date = ? WHERE id = ?')->execute([gmdate('Y-m-d', $t), gmdate('Y-m-d', $t), $id]);
  crm_audit($pdo, $user, 'contract_renew', 'contract', $id, 'extended to ' . gmdate('Y-m-d', $t)); return [true, 'ok'];
}
function crm_contracts_list(PDO $pdo, int $limit = 200): array { return $pdo->query('SELECT k.*, c.name AS company_name FROM crm_contracts k LEFT JOIN crm_companies c ON c.id = k.company_id ORDER BY k.id DESC LIMIT ' . max(1, min(500, $limit)))->fetchAll(PDO::FETCH_ASSOC); }
function crm_contracts_renewing(PDO $pdo, int $days = 60, ?int $now = null): array {
  $now = $now ?? time(); $s = $pdo->prepare("SELECT k.*, c.name AS company_name FROM crm_contracts k LEFT JOIN crm_companies c ON c.id = k.company_id WHERE k.status = 'active' AND k.renewal_date IS NOT NULL AND k.renewal_date BETWEEN ? AND ? ORDER BY k.renewal_date");
  $s->execute([gmdate('Y-m-d', $now), gmdate('Y-m-d', $now + $days * 86400)]); return $s->fetchAll(PDO::FETCH_ASSOC);
}
/** Manual bookkeeping record (invoiced or received). Nothing is issued or charged. Received can never exceed invoiced for a contract. */
function crm_revenue_record(PDO $pdo, ?array $user, int $contractId, string $kind, string $amount, string $date, string $reference = '', string $note = ''): array {
  if (crm_deny($user, 'revenue')) return [false, 'forbidden']; if (!in_array($kind, ['invoiced', 'received'], true)) return [false, 'kind_invalid'];
  $k = crm_contract_get($pdo, $contractId); if (!$k) return [false, 'not_found']; if ($k['status'] !== 'active') return [false, 'contract_not_active'];
  $minor = crm_money_parse($amount); if ($minor === null || $minor <= 0) return [false, 'amount_invalid']; $t = crm_date($date); if ($t === null || $t > time() + 86400) return [false, 'date_invalid'];
  $sum = fn(string $kd) => (int)$pdo->query("SELECT COALESCE(SUM(amount_minor),0) FROM crm_revenue_entries WHERE contract_id = " . (int)$contractId . " AND kind = '$kd'")->fetchColumn();
  if ($kind === 'received' && $sum('received') + $minor > $sum('invoiced')) return [false, 'received_exceeds_invoiced'];
  $pdo->prepare('INSERT INTO crm_revenue_entries (contract_id, kind, amount_minor, currency, entry_date, reference, note, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
      ->execute([$contractId, $kind, $minor, $k['currency'], gmdate('Y-m-d', $t), crm_line($reference, 60), crm_line($note, 190), $user['id'] ?? null, crm_now()]);
  crm_audit($pdo, $user, 'revenue_' . $kind, 'contract', $contractId, crm_money_format($minor, $k['currency'])); return [true, (string)$pdo->lastInsertId()];
}

// ---- revenue measures ----------------------------------------------------------------------------------------------------------------
function crm_months_between(string $from, string $to): int { $a = new DateTimeImmutable($from); $b = new DateTimeImmutable($to); if ($b < $a) return 0; $d = $a->diff($b); return $d->y * 12 + $d->m; }
/** Contract state on a date: is it in force, what is its monthly equivalent, what is recognized so far. */
function crm_contract_measures(array $k, ?string $asOf = null): array {
  $asOf = $asOf ?? gmdate('Y-m-d'); $amt = (int)$k['amount_minor']; $period = $k['billing_period'];
  $cancelAt = !empty($k['cancelled_at']) ? substr($k['cancelled_at'], 0, 10) : null;
  $inForce = $k['status'] === 'active' && $k['start_date'] <= $asOf && (empty($k['end_date']) || $k['end_date'] >= $asOf) && !($cancelAt && $cancelAt <= $asOf);
  if ($k['status'] === 'cancelled' && $cancelAt && $cancelAt > $asOf && $k['start_date'] <= $asOf && (empty($k['end_date']) || $k['end_date'] >= $asOf)) $inForce = true;   // cancellation takes effect later
  $monthly = $period === 'monthly' ? $amt : ($period === 'annual' ? crm_div_round($amt, 12) : 0);
  $arr = $period === 'monthly' ? $amt * 12 : ($period === 'annual' ? $amt : 0);
  $endBound = $asOf; foreach ([$k['end_date'] ?? null, $cancelAt] as $b) if ($b && $b < $endBound) $endBound = $b;
  $months = ($k['status'] === 'active' || $k['status'] === 'cancelled') && $k['start_date'] <= $endBound ? crm_months_between($k['start_date'], $endBound) : 0;
  $recognized = 0;
  if ($k['status'] === 'active' || ($k['status'] === 'cancelled' && !empty($k['confirmed_at']))) {
    if ($period === 'monthly') $recognized = $amt * $months; elseif ($period === 'annual') $recognized = crm_div_round($amt * $months, 12); elseif (!empty($k['end_date']) && $k['end_date'] <= $asOf) $recognized = $amt;
  }
  $termMonths = !empty($k['end_date']) ? max(1, crm_months_between($k['start_date'], $k['end_date']) + 1) : null;
  $contracted = $period === 'one_off' ? $amt : ($termMonths === null ? null : ($period === 'monthly' ? $amt * $termMonths : crm_div_round($amt * $termMonths, 12)));
  return ['in_force' => $inForce, 'mrr_minor' => $inForce ? $monthly : 0, 'arr_minor' => $inForce ? $arr : 0, 'recognized_minor' => $recognized, 'contracted_minor' => $contracted];
}
/** @return array<string,array<string,int>> per currency: proposed, forecast, contracted, invoiced, received, recognized, mrr, arr, expected_recurring */
function crm_revenue_summary(PDO $pdo, ?string $asOf = null): array {
  $asOf = $asOf ?? gmdate('Y-m-d'); $out = [];
  $add = function (string $cur, string $k, int $v) use (&$out) { $out[$cur] = $out[$cur] ?? array_fill_keys(['proposed', 'forecast', 'contracted', 'invoiced', 'received', 'recognized', 'mrr', 'arr', 'expected_recurring'], 0); $out[$cur][$k] += $v; };
  foreach ($pdo->query("SELECT v.currency, v.total_minor, v.billing_period FROM crm_proposals p JOIN crm_proposal_versions v ON v.proposal_id = p.id AND v.version = p.current_version WHERE p.status IN ('draft','pending_approval','approved')") as $r) {
    $add($r['currency'], 'proposed', (int)$r['total_minor']); if ($r['billing_period'] === 'monthly') $add($r['currency'], 'expected_recurring', (int)$r['total_minor'] * 12); elseif ($r['billing_period'] === 'annual') $add($r['currency'], 'expected_recurring', (int)$r['total_minor']); }
  $w = crm_stage_weights_bp(); foreach ($pdo->query("SELECT stage, currency, amount_minor FROM crm_opportunities WHERE stage NOT IN ('won','lost')") as $r) $add($r['currency'], 'forecast', crm_div_round((int)$r['amount_minor'] * ($w[$r['stage']] ?? 0), 10000));
  foreach ($pdo->query('SELECT * FROM crm_contracts') as $k) { $m = crm_contract_measures($k, $asOf);
    if (in_array($k['status'], ['active', 'cancelled'], true) && !empty($k['confirmed_at']) && $m['contracted_minor'] !== null) $add($k['currency'], 'contracted', $m['contracted_minor']);
    $add($k['currency'], 'recognized', $m['recognized_minor']); $add($k['currency'], 'mrr', $m['mrr_minor']); $add($k['currency'], 'arr', $m['arr_minor']); }
  foreach ($pdo->query('SELECT kind, currency, SUM(amount_minor) AS s FROM crm_revenue_entries GROUP BY kind, currency') as $r) $add($r['currency'], $r['kind'], (int)$r['s']);
  ksort($out); return $out;
}
require_once __DIR__ . '/crm_quote.php';   // Phase 7C (quotations); degrades gracefully until database/phase7c_quotations.sql is applied
