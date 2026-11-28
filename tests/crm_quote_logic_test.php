<?php
/** Phase 7C pure logic tests (no database, no network): milestone split and validation, tax presets, validity state. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start(); $root = dirname(__DIR__); require_once $root . '/helpers.php'; require_once $root . '/crm_money.php'; ob_end_clean();
$pass = 0; $fail = 0; function ok(bool $c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; fwrite(STDERR, "  FAIL: $m\n"); } }

fwrite(STDERR, "== milestone split\n");
ok(crm_quote_split(100000, [3000, 3000, 4000]) === [30000, 30000, 40000], '30/30/40 of 1,000.00');
$a = crm_quote_split(10001, [3333, 3333, 3334]); ok(array_sum($a) === 10001, 'parts always add up to the total (' . implode(',', $a) . ')');
foreach ([[1, [5000, 5000]], [99999, [3333, 3333, 3334]], [7, [10000]], [1000000007, [2500, 2500, 2500, 2500]]] as [$t, $b]) ok(array_sum(crm_quote_split($t, $b)) === $t, "exact sum for $t");
ok(crm_quote_split(5, [10000]) === [5], 'single milestone = whole total');

fwrite(STDERR, "== milestone validation\n");
[$o, $m] = crm_quote_milestones_from_input([['label' => 'Deposit', 'pct' => '30', 'due_kind' => 'on_acceptance'], ['label' => 'Delivery', 'pct' => '70', 'due_kind' => 'days_after_acceptance', 'due_days' => '14']], 250000);
ok($o && count($m) === 2 && $m[0]['amount'] === 75000 && $m[1]['amount'] === 175000 && $m[1]['days'] === 14, '30/70 split of 2,500.00 with a 14-day rule');
ok(crm_quote_milestones_from_input([], 100) === [true, []], 'no milestones is allowed'); ok(crm_quote_milestones_from_input([['label' => '', 'pct' => ''], []], 100) === [true, []], 'empty rows are ignored');
ok(crm_quote_milestones_from_input([['label' => 'A', 'pct' => '50'], ['label' => 'B', 'pct' => '40']], 100)[1] === 'milestones_not_100', 'percentages must total 100');
ok(crm_quote_milestones_from_input([['label' => 'A', 'pct' => '100.5']], 100)[1] === 'milestone_invalid', 'over 100% rejected');
ok(crm_quote_milestones_from_input([['label' => 'A', 'pct' => '0'], ['label' => 'B', 'pct' => '100']], 100)[1] === 'milestone_invalid', '0% milestone rejected');
ok(crm_quote_milestones_from_input([['label' => 'A', 'pct' => '100', 'due_kind' => 'days_after_acceptance', 'due_days' => '0']], 100)[1] === 'milestone_invalid', 'days must be 1-365');
ok(crm_quote_milestones_from_input([['label' => 'A', 'pct' => '100', 'due_kind' => 'date', 'due_date' => '2026-02-30']], 100)[1] === 'milestone_invalid', 'impossible date rejected');
ok(crm_quote_milestones_from_input([['label' => 'A', 'pct' => '100', 'due_kind' => 'bogus']], 100)[1] === 'milestone_invalid', 'unknown due kind rejected'); ok(crm_quote_milestones_from_input([['label' => '', 'pct' => '100']], 100)[1] === 'milestone_invalid', 'label required');
ok(crm_quote_milestones_from_input(array_fill(0, 7, ['label' => 'x', 'pct' => '10']), 100)[1] === 'milestone_invalid', 'at most 6 milestones');
ok(crm_quote_due_text(['due_kind' => 'days_after_acceptance', 'due_days' => 30]) === '30 days after acceptance' && crm_quote_due_text(['due_kind' => 'date', 'due_date' => '2026-12-01']) === 'On 2026-12-01', 'due text');

fwrite(STDERR, "== tax presets\n");
putenv('AIHUB_QUOTE_TAX_PRESETS'); $d = crm_quote_tax_presets(); ok(count($d) === 3 && $d[0]['bp'] === 1900 && $d[1]['bp'] === 700 && $d[2]['bp'] === 0, 'defaults: 19, 7, none');
putenv('AIHUB_QUOTE_TAX_PRESETS=Reverse charge (no VAT)=0;Swiss VAT 8.1%=8.1;bad;=5;X=200'); $c = crm_quote_tax_presets(); ok(count($c) === 2 && $c[1]['bp'] === 810 && $c[0]['label'] === 'Reverse charge (no VAT)', 'configured presets parsed; malformed and out-of-range entries ignored');
ok(crm_quote_apply_tax_preset('1', '5', 'typed') === ['8.1', 'Swiss VAT 8.1%'], 'preset overrides typed values'); ok(crm_quote_apply_tax_preset('0', '', '') === ['0', 'Reverse charge (no VAT)'], 'zero preset');
ok(crm_quote_apply_tax_preset('custom', '12.5', 'Local tax') === ['12.5', 'Local tax'] && crm_quote_apply_tax_preset('99', '3', 'x') === ['3', 'x'], 'custom or unknown choice keeps typed values'); putenv('AIHUB_QUOTE_TAX_PRESETS');
ok(crm_quote_prefix() === 'Q', 'default prefix Q'); putenv('AIHUB_QUOTE_PREFIX=ab1c'); ok(crm_quote_prefix() === 'ABC', 'prefix sanitised to letters'); putenv('AIHUB_QUOTE_PREFIX');

fwrite(STDERR, "== validity\n");
$now = gmmktime(10, 0, 0, 6, 10, 2026); $v = fn($vu, $st = 'approved') => crm_quote_validity(['valid_until' => $vu, 'status' => $st], $now);
ok($v('2026-06-30')['state'] === 'valid' && $v('2026-06-30')['days'] === 20, 'valid, 20 days'); ok($v('2026-06-17')['state'] === 'expiring' && $v('2026-06-10')['state'] === 'expiring' && $v('2026-06-10')['days'] === 0, 'expiring within 7 days, last day still valid');
ok($v('2026-06-09')['state'] === 'expired', 'day after the validity date = expired'); ok($v(null)['state'] === 'none', 'no date'); ok($v('2026-01-01', 'accepted')['state'] === 'none' && $v('2026-01-01', 'declined')['state'] === 'none', 'closed proposals are not judged by validity'); ok($v('2026-01-01', 'expired')['state'] === 'expired', 'expired status');
ok(crm_quote_event_date('2026-06-10', $now) === '2026-06-10' && crm_quote_event_date('2026-06-11', $now) === null && crm_quote_event_date('nope', $now) === null, 'event dates: today ok, future and garbage refused');
echo "crm_quote_logic_test: $pass passed, $fail failed\n"; fwrite(STDERR, "\ncrm_quote_logic_test: $pass passed, $fail failed\n"); exit($fail ? 1 : 0);
