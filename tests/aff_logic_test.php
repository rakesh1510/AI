<?php
/** Phase 7E pure logic tests (no database, no network): link validation, final URL, status machines, CSV parsing, partner validation. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start(); $root = dirname(__DIR__); require_once $root . '/helpers.php'; require_once $root . '/aff_report.php'; ob_end_clean();
$pass = 0; $fail = 0; function ok(bool $c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; fwrite(STDERR, "  FAIL: $m\n"); } }

fwrite(STDERR, "== link validation (structure only)\n");
ok(aff_link_problems('https://partners.example/go/abc?ref=aihub') === [], 'a normal https affiliate link passes'); ok(aff_link_problems('') !== [] && aff_link_problems('   ') !== [], 'empty refused');
ok(aff_link_problems('http://partners.example/x?a=1') !== [], 'http refused'); ok(aff_link_problems('javascript:alert(1)') !== [] && aff_link_problems('data:text/html,x') !== [] && aff_link_problems('ftp://x.example/a') !== [], 'unsafe schemes refused');
ok(aff_link_problems('https://user:pw@partners.example/x') !== [], 'credentials in the URL refused'); ok(aff_link_problems('https://127.0.0.1/x') !== [] && aff_link_problems('https://localhost/x') !== [] && aff_link_problems('https://intranet/x') !== [], 'IP, localhost and dotless hosts refused');
foreach (['bit.ly', 'tinyurl.com', 't.co', 'goo.gl', 'ow.ly', 'is.gd', 'buff.ly', 'rebrand.ly', 'cutt.ly'] as $h) ok(aff_link_problems("https://$h/abc") !== [], "shortener $h refused (no cloaking)");
ok(aff_link_problems('https://tool.example/', 'https://www.tool.example') !== [] && aff_link_problems('https://tool.example/?via=aihub', 'https://www.tool.example') === [], 'the plain official site is not an affiliate link; with a tracking parameter it is accepted');
ok(aff_link_problems('https://p.example/' . str_repeat('a', 1001)) !== [], 'over-long link refused'); ok(aff_link_problems("https://p.example/a b") !== [] && aff_link_problems('https://p.example/a"b') !== [], 'spaces and quotes refused');

fwrite(STDERR, "== final URL with tracking id\n");
ok(aff_final_url('https://p.example/go', 'aihub-monica', 'subid') === 'https://p.example/go?subid=aihub-monica', 'appended as the first parameter'); ok(aff_final_url('https://p.example/go?x=1', 'aihub-monica', 'subid') === 'https://p.example/go?x=1&subid=aihub-monica', 'appended after existing parameters');
ok(aff_final_url('https://p.example/go#top', 't1', 'subid') === 'https://p.example/go?subid=t1#top', 'fragment kept at the end'); ok(aff_final_url('https://p.example/go', '', 'subid') === 'https://p.example/go' && aff_final_url('https://p.example/go', 'x', '') === 'https://p.example/go', 'no id or no parameter name: unchanged (nothing is guessed)');
ok(aff_final_url('https://p.example/go', 'x', 'bad param!') === 'https://p.example/go', 'an invalid parameter name is ignored'); ok(aff_tracking_ok('aihub-monica_1') && aff_tracking_ok('') && !aff_tracking_ok('a b') && !aff_tracking_ok('x&y=1') && !aff_tracking_ok(str_repeat('a', 41)), 'tracking id characters limited');

fwrite(STDERR, "== status machines\n");
$st = aff_statuses(); ok(array_keys($st) === ['researching', 'not_applied', 'applied', 'pending', 'approved', 'rejected', 'active', 'suspended', 'expired'], 'the nine required partnership statuses'); $tr = aff_transitions();
ok(!in_array('approved', $tr['researching'], true) && !in_array('active', $tr['applied'], true) && in_array('approved', $tr['pending'], true), 'no approval without applying; no activation without approval'); foreach ($tr as $f => $ts) foreach ($ts as $t) if (!isset($st[$t])) ok(false, "unknown target $t");
ok(in_array('researching', $tr['rejected'], true) && !isset($tr['active']) === false, 'a rejection can be reopened as research'); $cs = aff_commission_statuses(); ok(array_keys($cs) === ['pending', 'approved', 'paid', 'rejected', 'reversed'], 'commission statuses'); $ct = aff_commission_transitions();
ok(!in_array('paid', $ct['pending'], true) && in_array('paid', $ct['approved'], true) && in_array('reversed', $ct['paid'], true) && $ct['rejected'] === [] && $ct['reversed'] === [], 'a commission cannot be paid before approval; rejected and reversed are final');
ok(str_contains($cs['approved'], 'not yet received') && str_contains($cs['paid'], 'received'), 'approved is labelled as not yet received');

fwrite(STDERR, "== CSV\n");
ok(aff_csv_cell('=SUM(A1)') === "'=SUM(A1)" && aff_csv_cell('+1') === "'+1" && aff_csv_cell('@x') === "'@x" && aff_csv_cell('ref-1') === 'ref-1', 'formula injection guarded');
[$o, $d] = aff_csv_parse("\xEF\xBB\xBFTransaction Ref,Partner,Commission Amount\nT-1,\"Acme, Inc\",12.50\n\nT-2,Beta,3\n"); ok($o && $d['header'] === ['transaction_ref', 'partner', 'commission_amount'] && count($d['rows']) === 2 && $d['rows'][0]['partner'] === 'Acme, Inc' && $d['rows'][1]['_line'] === 4, 'BOM, quotes, header normalisation, blank lines');
ok(aff_csv_parse('')[1] === 'csv_empty' && aff_csv_parse("a\n\xff")[1] === 'csv_encoding' && aff_csv_parse(str_repeat('x', AFF_MAX_IMPORT_BYTES + 1))[1] === 'csv_too_large' && aff_csv_parse("t\n" . str_repeat("a\n", AFF_MAX_IMPORT_ROWS + 1))[1] === 'csv_too_many_rows', 'empty, encoding, size and row limits');
ok(aff_csv_parse("transaction_ref;partner\nT1;Acme\n")[1]['rows'][0]['partner'] === 'Acme', 'semicolon delimiter');

fwrite(STDERR, "== partner validation\n");
$cat = aff_catalogue(); $tool = (string)array_key_first($cat); ok($tool !== '' && count($cat) > 100, 'the catalogue is available to the validator (' . count($cat) . ' tools)');
$good = ['company' => 'Acme AI', 'tool_slug' => $tool, 'commission_type' => 'recurring', 'commission_currency' => 'USD', 'cookie_days' => '30', 'payment_threshold' => '50', 'application_url' => 'https://acme.example/partners', 'verified_at' => '2026-11-01', 'verified_source' => 'Program page', 'follow_up_at' => '2027-01-01'];
[$o, $c] = aff_partner_clean($good, gmmktime(0, 0, 0, 11, 28, 2026)); ok($o && $c['cookie_days'] === 30 && $c['payment_threshold_minor'] === 5000 && $c['commission_currency'] === 'USD' && $c['verified_at'] === '2026-11-01' && $c['follow_up_at'] === '2027-01-01', 'valid partner cleaned');
$er = fn(array $x) => aff_partner_clean($x + $good, gmmktime(0, 0, 0, 11, 28, 2026))[1];
ok($er(['company' => '']) === 'company_required' && $er(['tool_slug' => 'no-such-tool']) === 'tool_unknown' && $er(['commission_type' => 'weekly']) === 'commission_type_invalid' && $er(['commission_currency' => 'XXX']) === 'currency_invalid', 'enumerations validated');
ok($er(['cookie_days' => '99999']) === 'cookie_days_invalid' && $er(['cookie_days' => 'abc']) === 'cookie_days_invalid' && $er(['payment_threshold' => 'x']) === 'threshold_invalid' && $er(['payment_threshold' => '10', 'commission_currency' => '']) === 'currency_required_with_amount', 'numbers validated; a threshold needs a currency');
ok($er(['application_url' => 'javascript:x']) === 'application_url_invalid' && $er(['program_url' => 'ftp://x']) === 'program_url_invalid', 'URLs validated'); ok($er(['verified_at' => '2027-12-01']) === 'verified_at_invalid' && $er(['verified_source' => '']) === 'verified_source_required', 'a verification date cannot be in the future and needs its source');
ok(aff_partner_clean(['company' => 'Bare Co'])[0] === true, 'everything except the company is optional (nothing is invented)');
fwrite(STDERR, "== period\n"); [$f, $t, $dd] = aff_period(90, gmmktime(12, 0, 0, 11, 28, 2026)); ok($t === '2026-11-28' && $f === '2026-08-31' && $dd === 90, 'a 90-day period is inclusive'); ok(aff_period(7)[2] === 90, 'unknown periods fall back to 90 days');
echo "aff_logic_test: $pass passed, $fail failed\n"; fwrite(STDERR, "\naff_logic_test: $pass passed, $fail failed\n"); exit($fail ? 1 : 0);
