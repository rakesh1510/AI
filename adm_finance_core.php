<?php
if (isset($_SERVER["SCRIPT_FILENAME"]) && realpath($_SERVER["SCRIPT_FILENAME"]) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
// Phase 8D Finance core. PURE logic over arrays plus the action engine; no database, no network. The store (adm_finance_store.php) supplies the arrays.
// Rules: owner only; money is integer minor units; currencies are never added together or converted; synthetic records are excluded from every total; CRM is read
// only (its own functions compute MRR and recognized revenue); recorded amounts are manual bookkeeping (not bank-verified); VAT is not tracked; nothing is deleted
// (voided with a reason); missing information is "unavailable", never zero.
require_once __DIR__ . '/crm_money.php';

const ADMF_CATEGORIES = ['hosting_infrastructure' => 'Hosting and infrastructure', 'software_tools' => 'Software and tools', 'contractors' => 'Contractors', 'marketing' => 'Marketing', 'legal_accounting' => 'Legal and accounting', 'payment_fees' => 'Payment fees', 'other' => 'Other'];
const ADMF_LADDER = [10000, 50000, 100000, 250000, 500000, 979000];   // owner-approved EUR per month; aspirational targets, not forecasts
const ADMF_TARGET_START = '2026-12';                                   // monitoring starts December 2026
const ADMF_PRIMARY = 'EUR';
const ADMF_MAX_MINOR = 10000000000;                                    // 100 million units per entry
const ADMF_STREAMS = ['featured' => 'Featured placements', 'newsletter' => 'Newsletter sponsorship', 'category' => 'Category sponsorship', 'advertising' => 'Other advertising campaigns', 'partnership' => 'Affiliate and partnerships', 'project' => 'Enterprise AI projects', 'retainer' => 'Retainers and managed services', 'unclassified' => 'Unclassified'];
const ADMF_STREAMS_NO_FIELD = ['Custom AI agents and automation', 'AI vendor subscriptions', 'Enterprise software integration services'];   // the CRM has no field that separates these: shown as "No record yet", never estimated
const ADMF_STATES = ['recorded' => 'Recorded manually, not bank-verified', 'synthetic' => 'Synthetic, excluded', 'estimate' => 'Estimate', 'target' => 'Target', 'unavailable' => 'Unavailable', 'reconstructed' => 'Reconstructed, approximate', 'hypothetical' => 'Hypothetical', 'terms' => 'From contract terms'];
const ADMF_VAT = 'VAT: Not tracked. Amounts are as recorded; they are not assumed to be net or gross.';

function admf_today(): string { return (string)($GLOBALS['ADMF_TODAY_OVERRIDE'] ?? gmdate('Y-m-d')); }   // tests only; never set from a request
function admf_ym(string $date): string { return substr($date, 0, 7); }
function admf_add_months(string $ym, int $n): string { $t = (int)substr($ym, 0, 4) * 12 + (int)substr($ym, 5, 2) - 1 + $n; return sprintf('%04d-%02d', intdiv($t, 12), $t % 12 + 1); }
/** The $n months ending with $endYm, oldest first. */
function admf_months(string $endYm, int $n): array { $o = []; for ($i = $n - 1; $i >= 0; $i--) $o[] = admf_add_months($endYm, -$i); return $o; }
function admf_month_end(string $ym): string { return gmdate('Y-m-t', strtotime($ym . '-01')); }
function admf_money(int $minor, string $cur): string { return crm_money_format($minor, $cur); }
function admf_valid_ym($v): bool { return is_string($v) && (bool)preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v); }
function admf_date($v): ?string { $t = is_string($v) ? crm_date($v) : null; return $t === null ? null : gmdate('Y-m-d', $t); }
/** One line of text with markup removed (output is escaped as well). */
function admf_line($v, int $max): string { return crm_line(strip_tags(is_scalar($v) ? (string)$v : ''), $max); }
function admf_real(array $rows): array { return array_values(array_filter($rows, fn($r) => empty($r['is_synthetic']))); }

/** Revenue stream of a CRM contract, derived ONLY from existing CRM fields (opportunity kind, campaign package, billing period). */
function admf_stream(array $c): string {
  $kind = (string)($c['opp_kind'] ?? ''); $pkg = (string)($c['package'] ?? ''); $bp = (string)($c['billing_period'] ?? '');
  if ($kind === 'advertising') return ['featured_placement' => 'featured', 'newsletter_sponsorship' => 'newsletter', 'category_sponsorship' => 'category'][$pkg] ?? 'advertising';
  if ($kind === 'partnership') return 'partnership';
  if ($kind === 'development') return in_array($bp, ['monthly', 'annual'], true) ? 'retainer' : 'project';
  return 'unclassified';
}

/** Expense amount paid in each month up to $asOf (cash basis). One-off: its month. Monthly: every month. Annual: every 12th month from the start. */
function admf_expense_months(array $e, string $asOfYm): array {
  $start = admf_ym((string)$e['entry_date']); $end = $asOfYm; if (!empty($e['recurrence_end']) && admf_ym((string)$e['recurrence_end']) < $end) $end = admf_ym((string)$e['recurrence_end']);
  $rec = (string)($e['recurrence'] ?? 'none'); $out = []; if ($start > $end) return $out;
  if ($rec === 'none') { if ($start <= $asOfYm) $out[$start] = (int)$e['amount_minor']; return $out; }
  for ($m = $start, $i = 0; $m <= $end && $i < 600; $m = admf_add_months($m, 1), $i++) if ($rec === 'monthly' || ($rec === 'annual' && $i % 12 === 0)) $out[$m] = (int)$e['amount_minor'];
  return $out;
}

/**
 * The finance report. $d keys: contracts, revenue, adjustments, expenses, legacy, pipeline, campaigns (arrays of rows). Synthetic rows are counted and excluded.
 * All money is per currency; nothing is converted or summed across currencies.
 */
function admf_report(array $d, ?string $asOf = null): array {
  $asOf = $asOf ?? admf_today(); $nowYm = admf_ym($asOf); $months = admf_months($nowYm, 12); $yearStart = substr($asOf, 0, 4) . '-01';
  $syn = []; $real = []; foreach (['contracts', 'revenue', 'adjustments', 'expenses', 'legacy', 'pipeline', 'campaigns'] as $k) { $rows = $d[$k] ?? []; $real[$k] = admf_real($rows); $syn[$k] = count($rows) - count($real[$k]); }
  $blank = fn() => ['invoiced' => 0, 'received' => 0, 'refunds' => 0, 'credits' => 0, 'expenses' => 0, 'mrr' => 0, 'arr' => 0, 'recognized' => 0, 'contracted' => 0, 'forecast' => 0, 'ytd_income' => 0, 'ytd_expenses' => 0, 'month' => array_fill_keys($months, ['received' => 0, 'refunds' => 0, 'expenses' => 0]), 'mrr_trend' => array_fill_keys($months, 0), 'streams' => [], 'has_revenue' => false, 'has_expenses' => false, 'has_contracts' => false];
  $cur = []; $C = function (string $c) use (&$cur, $blank) { if (!isset($cur[$c])) $cur[$c] = $blank(); return $c; };
  $byContract = []; foreach ($real['contracts'] as $k) $byContract[(int)$k['id']] = $k;
  foreach ($real['contracts'] as $k) {
    $c = $C((string)$k['currency']); $m = crm_contract_measures($k, $asOf); $st = admf_stream($k); $confirmed = in_array($k['status'], ['active', 'cancelled'], true) && !empty($k['confirmed_at']);
    $cur[$c]['has_contracts'] = true; $cur[$c]['mrr'] += $m['mrr_minor']; $cur[$c]['arr'] += $m['arr_minor']; $cur[$c]['recognized'] += $m['recognized_minor']; if ($confirmed && $m['contracted_minor'] !== null) $cur[$c]['contracted'] += $m['contracted_minor'];
    $s = &$cur[$c]['streams'][$st]; $s = $s ?? ['contracts' => 0, 'received' => 0, 'mrr' => 0, 'contracted' => 0]; $s['contracts']++; $s['mrr'] += $m['mrr_minor']; if ($confirmed && $m['contracted_minor'] !== null) $s['contracted'] += $m['contracted_minor']; unset($s);
    foreach ($months as $mo) $cur[$c]['mrr_trend'][$mo] += crm_contract_measures($k, min(admf_month_end($mo), $asOf))['mrr_minor'];   // RECONSTRUCTED from current contract records
  }
  foreach ($real['revenue'] as $r) {
    $k = $byContract[(int)$r['contract_id']] ?? null; if ($k === null) continue; $c = $C((string)$r['currency']); $cur[$c]['has_revenue'] = true; $ym = admf_ym((string)$r['entry_date']);
    if ($r['kind'] === 'invoiced') $cur[$c]['invoiced'] += (int)$r['amount_minor'];
    elseif ($r['kind'] === 'received') { $cur[$c]['received'] += (int)$r['amount_minor']; if (isset($cur[$c]['month'][$ym])) $cur[$c]['month'][$ym]['received'] += (int)$r['amount_minor']; if ($ym >= $yearStart && $ym <= $nowYm) $cur[$c]['ytd_income'] += (int)$r['amount_minor'];
      $st = admf_stream($k); $cur[$c]['streams'][$st] = $cur[$c]['streams'][$st] ?? ['contracts' => 0, 'received' => 0, 'mrr' => 0, 'contracted' => 0]; $cur[$c]['streams'][$st]['received'] += (int)$r['amount_minor']; }
  }
  foreach ($real['adjustments'] as $a) {
    if (!empty($a['voided_at'])) continue; $c = $C((string)$a['currency']); $cur[$c]['has_revenue'] = true; $ym = admf_ym((string)$a['entry_date']);
    if ($a['kind'] === 'credit') $cur[$c]['credits'] += (int)$a['amount_minor'];
    elseif ($a['kind'] === 'refund') { $cur[$c]['refunds'] += (int)$a['amount_minor']; if (isset($cur[$c]['month'][$ym])) $cur[$c]['month'][$ym]['refunds'] += (int)$a['amount_minor']; if ($ym >= $yearStart && $ym <= $nowYm) $cur[$c]['ytd_income'] -= (int)$a['amount_minor']; }
  }
  foreach ($real['expenses'] as $e) {
    if (!empty($e['voided_at'])) continue; $c = $C((string)$e['currency']); $cur[$c]['has_expenses'] = true;
    foreach (admf_expense_months($e, $nowYm) as $ym => $amt) { $cur[$c]['expenses'] += $amt; if (isset($cur[$c]['month'][$ym])) $cur[$c]['month'][$ym]['expenses'] += $amt; if ($ym >= $yearStart) $cur[$c]['ytd_expenses'] += $amt; }
  }
  $w = function_exists('crm_stage_weights_bp') ? crm_stage_weights_bp() : [];
  foreach ($real['pipeline'] as $p) { $c = $C((string)$p['currency']); $cur[$c]['forecast'] += crm_div_round((int)$p['amount_minor'] * ($w[$p['stage']] ?? 0), 10000); }
  ksort($cur);
  foreach ($cur as $c => &$x) {
    $x['net_invoiced'] = $x['invoiced'] - $x['credits']; $x['net_income'] = $x['received'] - $x['refunds']; $x['outstanding'] = max(0, $x['net_invoiced'] - $x['received']); $x['profit'] = $x['net_income'] - $x['expenses']; $x['ytd_profit'] = $x['ytd_income'] - $x['ytd_expenses'];
    $x['series'] = []; foreach ($months as $mo) { $m = $x['month'][$mo]; $x['series'][$mo] = ['income' => $m['received'] - $m['refunds'], 'received' => $m['received'], 'refunds' => $m['refunds'], 'expenses' => $m['expenses'], 'profit' => $m['received'] - $m['refunds'] - $m['expenses'], 'mrr' => $x['mrr_trend'][$mo]]; }
  } unset($x);
  // legacy listing payments: kept apart, never added to CRM totals (they might also be recorded in the CRM)
  $legacy = []; foreach ($real['legacy'] as $l) { $c = (string)($l['currency'] ?? ''); if ($c === '') continue; $legacy[$c] = $legacy[$c] ?? ['paid_count' => 0, 'paid_minor' => 0, 'unpaid_count' => 0]; if (($l['payment_status'] ?? '') === 'paid' && (int)$l['price'] > 0) { $legacy[$c]['paid_count']++; $legacy[$c]['paid_minor'] += (int)$l['price'] * 100; } elseif ((int)($l['price'] ?? 0) > 0) $legacy[$c]['unpaid_count']++; }
  ksort($legacy);
  $camp = ['by_status' => [], 'quotes' => []]; foreach ($real['campaigns'] as $p) { $camp['by_status'][$p['status']] = ($camp['by_status'][$p['status']] ?? 0) + 1; if (!empty($p['quote_minor']) && !in_array($p['status'], ['rejected', 'cancelled', 'completed'], true)) { $qc = (string)($p['quote_currency'] ?: ADMF_PRIMARY); $camp['quotes'][$qc] = ($camp['quotes'][$qc] ?? 0) + (int)$p['quote_minor']; } } ksort($camp['quotes']);
  $renewals = []; foreach ($real['contracts'] as $k) if ($k['status'] === 'active' && !empty($k['renewal_date']) && $k['renewal_date'] >= $asOf && $k['renewal_date'] <= gmdate('Y-m-d', strtotime($asOf . ' +60 days'))) $renewals[] = $k;
  usort($renewals, fn($a, $b) => strcmp($a['renewal_date'], $b['renewal_date']));
  $conc = null; $inForce = []; foreach ($real['contracts'] as $k) { $m = crm_contract_measures($k, $asOf); if ($m['mrr_minor'] > 0) $inForce[$k['currency']][] = $m['mrr_minor']; }
  foreach ($inForce as $c => $list) if (count($list) >= 2 && array_sum($list) > 0) $conc[$c] = round(max($list) / array_sum($list) * 100, 1);   // only meaningful with two or more contracts
  return ['as_of' => $asOf, 'months' => $months, 'currencies' => $cur, 'legacy' => $legacy, 'campaigns' => $camp, 'renewals' => $renewals, 'concentration' => $conc, 'synthetic_excluded' => $syn, 'counts' => array_map('count', $real)];
}

// ---- targets ------------------------------------------------------------------------------------------------------------------------
/** The approved objective ladder against recorded EUR figures for the current month. 'no_data' is NOT zero: it means nothing is recorded to compare. */
function admf_ladder(array $report, array $targets = []): array {
  $asOf = $report['as_of']; $ym = admf_ym($asOf); $e = $report['currencies'][ADMF_PRIMARY] ?? null; $active = $ym >= ADMF_TARGET_START;
  $income = ($e && $e['has_revenue']) ? ($e['series'][$ym]['income'] ?? 0) : null; $mrr = ($e && $e['has_contracts']) ? $e['mrr'] : null; $rows = [];
  foreach (ADMF_LADDER as $eur) { $t = $eur * 100;
    $rows[] = ['eur' => $eur, 'target_minor' => $t, 'monitoring' => $active ? 'active' : 'starts_' . ADMF_TARGET_START, 'income_state' => !$active ? 'not_started' : ($income === null ? 'no_data' : ($income >= $t ? 'reached' : 'not_reached')), 'mrr_state' => !$active ? 'not_started' : ($mrr === null ? 'no_data' : ($mrr >= $t ? 'reached' : 'not_reached'))]; }
  $next = null; foreach (ADMF_LADDER as $eur) { if ($income === null || $income < $eur * 100) { $next = $eur; break; } }
  $custom = []; foreach ($targets as $t) if (empty($t['superseded_at'])) $custom[] = $t;
  return ['rows' => $rows, 'income_minor' => $income, 'mrr_minor' => $mrr, 'next_eur' => $next, 'active' => $active, 'start' => ADMF_TARGET_START, 'custom' => $custom];
}

/**
 * Scenario calculator. Every input is typed by the owner (nothing is stored, nothing is assumed); results are arithmetic, labelled HYPOTHETICAL.
 * $in: v_customer, v_contract, v_subscription, v_partnership (monthly EUR value of one), m_* (mix percent, optional), close_rate (percent, optional).
 */
function admf_scenario(array $in): array {
  $types = ['customer' => 'Customers', 'contract' => 'Contracts (projects and retainers)', 'subscription' => 'Subscriptions', 'partnership' => 'Partnerships']; $errs = []; $unit = []; $mix = []; $mixSum = 0;
  foreach ($types as $k => $label) {
    $raw = trim((string)($in['v_' . $k] ?? '')); if ($raw !== '') { $m = crm_money_parse($raw); if ($m === null || $m < 100 || $m > 1000000000) $errs['v_' . $k] = 'Enter a monthly value of at least 1.00 and at most 10,000,000.'; else $unit[$k] = $m; }
    $mr = trim((string)($in['m_' . $k] ?? '')); if ($mr !== '') { if (!preg_match('/^\d{1,3}$/', $mr) || (int)$mr > 100) $errs['m_' . $k] = 'Enter a whole percent from 0 to 100.'; else { $mix[$k] = (int)$mr; $mixSum += (int)$mr; } }
  }
  if ($mix && $mixSum !== 100) $errs['mix'] = 'The mix must add up to 100 percent (now ' . $mixSum . ').';
  $close = null; $cr = trim((string)($in['close_rate'] ?? '')); if ($cr !== '') { if (!preg_match('/^\d{1,2}$/', $cr) || (int)$cr < 1 || (int)$cr > 100) $errs['close_rate'] = 'Enter a whole percent from 1 to 100.'; else $close = (int)$cr; }
  $rows = []; if (!$errs && $unit) foreach (ADMF_LADDER as $eur) { $t = $eur * 100; $single = []; foreach ($unit as $k => $u) { $n = intdiv($t + $u - 1, $u); $single[$k] = ['count' => $n, 'leads' => $close ? intdiv($n * 100 + $close - 1, $close) : null]; }
    $mixed = []; if ($mix) foreach ($mix as $k => $pct) if ($pct > 0 && isset($unit[$k])) { $share = intdiv($t * $pct + 99, 100); $n = intdiv($share + $unit[$k] - 1, $unit[$k]); $mixed[$k] = ['count' => $n, 'leads' => $close ? intdiv($n * 100 + $close - 1, $close) : null]; }
    $rows[] = ['eur' => $eur, 'single' => $single, 'mixed' => $mixed]; }
  return ['types' => $types, 'errors' => $errs, 'unit' => $unit, 'mix' => $mix, 'close' => $close, 'rows' => $rows, 'state' => 'hypothetical'];
}

// ---- CSV (formula-injection safe) ----------------------------------------------------------------------------------------------------
function admf_csv_cell($v): string { $s = (string)$v; if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) $s = "'" . $s; return '"' . str_replace('"', '""', str_replace(["\r\n", "\r", "\n"], ' ', $s)) . '"'; }
function admf_csv(array $header, array $rows): string { $o = implode(',', array_map('admf_csv_cell', $header)) . "\r\n"; foreach ($rows as $r) $o .= implode(',', array_map('admf_csv_cell', $r)) . "\r\n"; return $o; }
function admf_major(int $minor): string { $neg = $minor < 0; $a = abs($minor); return ($neg ? '-' : '') . intdiv($a, 100) . '.' . str_pad((string)($a % 100), 2, '0', STR_PAD_LEFT); }
/** [filename, content] or null for an unknown export. Every row says what kind of figure it is; amounts are plain numbers per currency (never mixed). */
function admf_export(string $kind, array $report, array $d): ?array {
  $rec = ADMF_STATES['recorded']; $vat = 'Not tracked'; $stamp = $report['as_of'];
  if ($kind === 'revenue') { $r = []; foreach ($report['currencies'] as $c => $x) foreach ($report['months'] as $m) { $s = $x['series'][$m]; $r[] = [$m, $c, admf_major($s['received']), admf_major($s['refunds']), admf_major($s['income']), $rec, $vat]; } return ["finance-revenue-$stamp.csv", admf_csv(['month', 'currency', 'received', 'refunds', 'net_income', 'data_state', 'vat'], $r)]; }
  if ($kind === 'streams') { $r = []; foreach ($report['currencies'] as $c => $x) foreach ($x['streams'] as $k => $s) $r[] = [ADMF_STREAMS[$k] ?? $k, $c, $s['contracts'], admf_major($s['received']), admf_major($s['mrr']), $rec . ' / ' . ADMF_STATES['terms'], $vat]; return ["finance-streams-$stamp.csv", admf_csv(['stream', 'currency', 'contracts', 'received', 'mrr_now', 'data_state', 'vat'], $r)]; }
  if ($kind === 'expenses') { $r = []; foreach (admf_real($d['expenses'] ?? []) as $e) $r[] = [$e['id'], $e['entry_date'], ADMF_CATEGORIES[$e['category']] ?? $e['category'], ($e['vendor'] ?? ''), $e['description'], $e['currency'], admf_major((int)$e['amount_minor']), $e['recurrence'], !empty($e['voided_at']) ? 'voided' : 'active', ($e['reference'] ?? ''), 'Recorded manually', $vat]; return ["finance-expenses-$stamp.csv", admf_csv(['id', 'date', 'category', 'vendor', 'description', 'currency', 'amount', 'recurrence', 'status', 'reference', 'data_state', 'vat'], $r)]; }
  if ($kind === 'pnl') { $r = []; foreach ($report['currencies'] as $c => $x) foreach ($report['months'] as $m) { $s = $x['series'][$m]; $r[] = [$m, $c, admf_major($s['income']), admf_major($s['expenses']), admf_major($s['profit']), 'Cash basis, recorded manually', $vat]; } return ["finance-profit-and-loss-$stamp.csv", admf_csv(['month', 'currency', 'net_income', 'expenses', 'profit', 'data_state', 'vat'], $r)]; }
  if ($kind === 'adjustments') { $r = []; foreach (admf_real($d['adjustments'] ?? []) as $a) $r[] = [$a['id'], $a['entry_date'], $a['kind'], $a['contract_id'], $a['currency'], admf_major((int)$a['amount_minor']), !empty($a['voided_at']) ? 'voided' : 'active', ($a['reference'] ?? ''), 'Recorded manually', $vat]; return ["finance-refunds-credits-$stamp.csv", admf_csv(['id', 'date', 'kind', 'contract_id', 'currency', 'amount', 'status', 'reference', 'data_state', 'vat'], $r)]; }
  if ($kind === 'targets') { $r = []; foreach (ADMF_LADDER as $eur) $r[] = [$eur, 'EUR', ADMF_TARGET_START, 'Target (aspirational, not a forecast)']; return ["finance-targets-$stamp.csv", admf_csv(['monthly_target', 'currency', 'monitoring_starts', 'data_state'], $r)]; }
  return null;
}
const ADMF_EXPORTS = ['revenue' => 'Revenue by month', 'streams' => 'Revenue by stream', 'expenses' => 'Expenses', 'pnl' => 'Profit and loss (cash basis)', 'adjustments' => 'Refunds and credit notes', 'targets' => 'Target ladder'];

// ---- actions (owner only) ------------------------------------------------------------------------------------------------------------
const ADMF_ACTS = ['expense_add', 'expense_void', 'adjustment_add', 'adjustment_void', 'target_set'];
function admf_messages(): array { return ['expense_added' => 'Expense recorded.', 'expense_voided' => 'Expense voided. It stays in the history.', 'adjustment_added' => 'Recorded.', 'adjustment_voided' => 'Voided. It stays in the history.', 'target_set' => 'Target saved. The previous target for that month is kept as superseded.']; }
/** Apply one finance action. Order: owner permission, storage ready, known action, input, state rules, write, audit. Returns ['ok','code','msg','errors','goto']. */
function admf_apply(AdmFinanceStore $s, array $user, string $act, array $post, callable $audit): array {
  $fail = fn(int $c, string $m, array $e = [], array $get = []) => ['ok' => false, 'code' => $c, 'msg' => $m, 'errors' => $e + ['_' => $m], 'get' => $get];
  $ok = fn(string $msg, array $goto) => ['ok' => true, 'code' => 303, 'msg' => $msg, 'errors' => [], 'goto' => $goto];
  if (!function_exists('adm_can') || !adm_can((string)($user['role'] ?? ''), 'finance')) { $audit('finance_denied', $act); return $fail(403, 'Finance is for the owner only.'); }
  if (!in_array($act, ADMF_ACTS, true)) return $fail(400, 'Unknown action.');
  if (!$s->finReady()) return $fail(503, 'The finance storage is not installed on this server, so nothing can be recorded.');
  $actor = (string)($user['username'] ?? 'unknown'); $today = admf_today(); $maxDay = gmdate('Y-m-d', strtotime($today . ' +1 day'));
  $amount = function (string $key) use ($post, &$e) { $m = crm_money_parse((string)($post[$key] ?? '')); if ($m === null || $m <= 0 || $m > ADMF_MAX_MINOR) { $e[$key] = 'Enter an amount greater than 0 (up to 2 decimals).'; return 0; } return $m; };
  $e = [];
  if ($act === 'expense_add') {
    $get = ['view' => 'expenses']; $date = admf_date((string)($post['entry_date'] ?? '')); if ($date === null || $date > $maxDay) $e['entry_date'] = 'Enter a real date that is not in the future (YYYY-MM-DD).';
    $cat = (string)($post['category'] ?? ''); if (!isset(ADMF_CATEGORIES[$cat])) $e['category'] = 'Choose a category.';
    $desc = admf_line($post['description'] ?? '', 190); if (mb_strlen($desc) < 3) $e['description'] = 'Describe the expense (3 to 190 characters).';
    $minor = $amount('amount'); $cur = (string)($post['currency'] ?? ''); if (!crm_currency_ok($cur)) $e['currency'] = 'Choose a currency.';
    $rec = (string)($post['recurrence'] ?? 'none'); if (!in_array($rec, ['none', 'monthly', 'annual'], true)) $e['recurrence'] = 'Choose none, monthly or annual.';
    $end = null; if (trim((string)($post['recurrence_end'] ?? '')) !== '') { $end = admf_date((string)$post['recurrence_end']); if ($end === null || ($date !== null && $end < $date)) $e['recurrence_end'] = 'The end date must be a real date on or after the start.'; }
    if ($e) return $fail(422, 'Please correct the highlighted fields.', $e, $get);
    $r = $s->addExpense(['entry_date' => $date, 'category' => $cat, 'vendor' => admf_line($post['vendor'] ?? '', 80), 'description' => $desc, 'amount_minor' => $minor, 'currency' => $cur, 'recurrence' => $rec, 'recurrence_end' => $end, 'reference' => admf_line($post['reference'] ?? '', 60)], $actor);
    if (!$r['ok']) return $fail(500, 'The expense could not be saved.', [], $get); $audit('finance_expense_add', '#' . ($r['id'] ?? '') . ' ' . $cat . ' ' . admf_money($minor, $cur) . ' ' . $rec); return $ok('expense_added', $get);
  }
  if ($act === 'expense_void' || $act === 'adjustment_void') {
    $get = ['view' => $act === 'expense_void' ? 'expenses' : 'refunds']; $id = (int)($post['id'] ?? 0); $why = admf_line($post['reason'] ?? '', 190); if (mb_strlen($why) < 5) return $fail(422, 'Write a reason of at least 5 characters. It is saved with the voided record.', ['reason' => 'A reason is required.'], $get);
    $r = $act === 'expense_void' ? $s->voidExpense($id, $actor, $why) : $s->voidAdjustment($id, $actor, $why); if (!$r['ok']) return $fail($r['error'] === 'missing' ? 404 : 409, $r['error'] === 'missing' ? 'Record not found.' : 'That record is already voided.', [], $get);
    $audit('finance_' . $act, '#' . $id . ' ' . $why); return $ok($act === 'expense_void' ? 'expense_voided' : 'adjustment_voided', $get);
  }
  if ($act === 'adjustment_add') {
    $get = ['view' => 'refunds']; $kind = (string)($post['kind'] ?? ''); if (!in_array($kind, ['refund', 'credit'], true)) $e['kind'] = 'Choose refund or credit note.';
    $cid = (int)($post['contract_id'] ?? 0); $k = null; foreach ($s->contracts() as $c) if ((int)$c['id'] === $cid) $k = $c; if ($k === null || !empty($k['is_synthetic'])) $e['contract_id'] = 'Choose a contract.'; elseif (!in_array($k['status'], ['active', 'cancelled'], true) || empty($k['confirmed_at'])) $e['contract_id'] = 'Only a confirmed contract can have a refund or credit note.';
    $date = admf_date((string)($post['entry_date'] ?? '')); if ($date === null || $date > $maxDay) $e['entry_date'] = 'Enter a real date that is not in the future (YYYY-MM-DD).';
    $minor = $amount('amount');
    if (!$e && $k !== null) { $sum = function (array $rows, string $kd) use ($cid) { $t = 0; foreach ($rows as $r) if ((int)$r['contract_id'] === $cid && $r['kind'] === $kd && empty($r['voided_at']) && empty($r['is_synthetic'])) $t += (int)$r['amount_minor']; return $t; };
      $adj = $s->adjustments(); $rev = $s->revenueEntries();
      if ($kind === 'refund' && $minor > $sum($rev, 'received') - $sum($adj, 'refund')) $e['amount'] = 'A refund cannot be more than the amount recorded as received and not yet refunded for this contract.';
      if ($kind === 'credit' && $minor > $sum($rev, 'invoiced') - $sum($adj, 'credit')) $e['amount'] = 'A credit note cannot be more than the amount recorded as invoiced and not yet credited for this contract.'; }
    if ($e) return $fail(422, 'Please correct the highlighted fields.', $e, $get);
    $r = $s->addAdjustment(['contract_id' => $cid, 'kind' => $kind, 'amount_minor' => $minor, 'currency' => (string)$k['currency'], 'entry_date' => $date, 'reference' => admf_line($post['reference'] ?? '', 60), 'note' => admf_line($post['note'] ?? '', 190)], $actor);
    if (!$r['ok']) return $fail(500, 'It could not be saved.', [], $get); $audit('finance_adjustment_add', '#' . ($r['id'] ?? '') . ' ' . $kind . ' contract ' . $cid . ' ' . admf_money($minor, (string)$k['currency'])); return $ok('adjustment_added', $get);
  }
  if ($act === 'target_set') {
    $get = ['view' => 'targets']; $month = (string)($post['month'] ?? ''); if (!admf_valid_ym($month) || $month < ADMF_TARGET_START) $e['month'] = 'Choose a month from ' . ADMF_TARGET_START . ' onwards (YYYY-MM).';
    $metric = (string)($post['metric'] ?? ''); if (!in_array($metric, ['received', 'mrr'], true)) $e['metric'] = 'Choose recorded income or MRR.'; $minor = $amount('amount');
    if ($e) return $fail(422, 'Please correct the highlighted fields.', $e, $get);
    $r = $s->setTarget($month, $metric, $minor, admf_line($post['label'] ?? '', 80), $actor); if (!$r['ok']) return $fail(500, 'The target could not be saved.', [], $get);
    $audit('finance_target_set', $month . ' ' . $metric . ' ' . admf_money($minor, ADMF_PRIMARY)); return $ok('target_set', $get);
  }
  return $fail(400, 'Unknown action.');
}
