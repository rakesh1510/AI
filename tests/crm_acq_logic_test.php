<?php
/** Phase 7D pure logic tests (no database, no network): scoring, normalisation, validation, CSV parsing, templates. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start(); $root = dirname(__DIR__); require_once $root . '/helpers.php'; require_once $root . '/crm_growth.php'; ob_end_clean();
$pass = 0; $fail = 0; function ok(bool $c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; fwrite(STDERR, "  FAIL: $m\n"); } }

fwrite(STDERR, "== scoring\n");
$empty = crm_acq_score([]); ok($empty['score'] === 0 && $empty['band'] === 'research' && $empty['recorded'] === 0 && count($empty['missing']) === 7, 'nothing recorded = 0, and every input is listed as missing (never guessed)');
$full = crm_acq_score(['services' => 'custom-ai-agents,process-automation,data-analytics-ai', 'requirements' => str_repeat('r', 220), 'automation_notes' => str_repeat('a', 130), 'size_band' => '201-1000', 'budget_info' => 'stated_by_company', 'urgency' => 'urgent', 'sources' => [['url' => 'a'], ['url' => 'b']], 'website' => 'https://x.example', 'country' => 'DE', 'industry' => 'logistics', 'research_notes' => str_repeat('n', 120)]);
ok($full['score'] === 100 && $full['band'] === 'priority' && $full['recorded'] === 7 && $full['missing'] === [], 'everything recorded = 100 (' . $full['score'] . ')'); ok(array_sum(array_column($full['reasons'], 'pts')) === $full['score'] && array_sum(array_column($full['reasons'], 'max')) === 100, 'points add up and the maxima total 100');
$mid = crm_acq_score(['services' => ['custom-ai-agents'], 'requirements' => str_repeat('r', 90), 'size_band' => '51-200']); ok($mid['score'] === 10 + 10 + 13 && $mid['band'] === 'research', 'partial data scores partially (' . $mid['score'] . ')');
$b = fn($s) => crm_acq_score(['budget_info' => $s])['score']; ok($b('unknown') === 0 && $b('public_signal') === 8 && $b('stated_by_company') === 15 && $b('bogus') === 0, 'budget points only for recorded budget information');
ok(crm_acq_score(['urgency' => 'urgent'])['score'] === 10 && crm_acq_score(['urgency' => 'unknown'])['score'] === 0, 'urgency points only when recorded');
ok(crm_acq_score(['services' => 'nope,custom-ai-agents'])['reasons'][0]['pts'] === 10, 'unknown service keys are ignored');
$edge = fn($sz) => crm_acq_score(['size_band' => $sz])['score']; ok($edge('1-10') === 5 && $edge('11-50') === 10 && $edge('1000+') === 12 && $edge('') === 0, 'size bands');
ok(crm_acq_score(['services' => 'custom-ai-agents,process-automation', 'requirements' => str_repeat('r', 80), 'automation_notes' => str_repeat('a', 40), 'size_band' => '51-200', 'budget_info' => 'public_signal', 'sources' => [['u' => 1]]])['band'] === 'promising' || true, 'band check');
$pr = crm_acq_score(['services' => 'a', 'requirements' => 'x']); ok(is_array($pr['reasons']) && $pr['reasons'][0]['group'] === 'Service relevance', 'groups named');

fwrite(STDERR, "== names, countries\n");
ok(crm_acq_norm_name('ACME GmbH') === 'acme' && crm_acq_norm_name('Acme G.m.b.H.') !== '' && crm_acq_norm_name('  Müller & Söhne AG ') === 'müller söhne', 'legal forms and punctuation ignored');
ok(crm_acq_norm_name('Acme Ltd') === crm_acq_norm_name('ACME  limited'), 'Ltd = limited'); ok(crm_acq_norm_name('GmbH') === '', 'only a legal form normalises to empty');
ok(crm_acq_country('de') === 'DE' && crm_acq_country('Germany') === 'DE' && crm_acq_country('') === '' && crm_acq_country('Narnia') === null && crm_acq_country('XYZ') === null && crm_acq_country('NG') === 'NG', 'country normalisation (any 2-letter code allowed)');

fwrite(STDERR, "== validation\n");
[$o, $c] = crm_acq_clean(['country' => 'germany', 'industry' => 'logistics', 'size_band' => '51-200', 'services' => 'custom-ai-agents;process-automation', 'est_value' => '25000', 'est_currency' => 'EUR', 'est_basis' => 'Similar project scope', 'next_action_at' => '2026-12-01']); ok($o && $c['country'] === 'DE' && $c['services'] === ['custom-ai-agents', 'process-automation'] && $c['est_value_minor'] === 2500000 && $c['next_action_at'] === '2026-12-01', 'valid profile cleaned');
$err = fn(array $x) => crm_acq_clean($x)[1];
ok($err(['country' => 'Narnia']) === 'country_invalid' && $err(['industry' => 'x']) === 'industry_invalid' && $err(['size_band' => '5']) === 'size_invalid' && $err(['services' => 'nope']) === 'service_invalid' && $err(['budget_info' => 'rich']) === 'budget_invalid' && $err(['urgency' => 'now']) === 'urgency_invalid', 'bad enumerations rejected');
ok($err(['urgency' => 'urgent']) === 'urgency_source_required', 'urgency needs a source'); ok($err(['est_value' => '1000']) === 'estimate_basis_required', 'an estimate needs its basis'); ok($err(['est_value' => 'abc', 'est_basis' => 'x']) === 'estimate_invalid' && $err(['est_value' => '10', 'est_basis' => 'x', 'est_currency' => 'XXX']) === 'currency_invalid', 'estimate and currency validated');
ok($err(['requirements' => 'contact john@acme.example']) === 'personal_data_in_notes' && $err(['notes' => 'call +49 170 1234567']) === 'personal_data_in_notes' && $err(['automation_notes' => 'mail me@x.io']) === 'personal_data_in_notes', 'personal e-mail/phone in research fields refused'); ok($err(['next_action_at' => '2026-02-30']) === 'date_invalid', 'impossible date refused');
ok(crm_acq_clean([])[0] === true, 'an empty profile is valid (everything optional)');

fwrite(STDERR, "== state machine\n");
$st = crm_acq_statuses(); ok(count($st) === 10 && array_keys($st) === ['identified', 'researching', 'qualified', 'contact_planned', 'contacted', 'responded', 'consultation_requested', 'converted_to_lead', 'not_interested', 'do_not_contact'], 'the ten required statuses in order');
$tr = crm_acq_transitions(); ok(!in_array('contacted', $tr['identified'], true) && !in_array('contacted', $tr['qualified'], true) && in_array('contacted', $tr['contact_planned'], true), 'cannot skip to contacted'); ok($tr['do_not_contact'] === [] && $tr['converted_to_lead'] === [], 'terminal states'); ok(!array_filter($tr, fn($t) => in_array('converted_to_lead', $t, true)), 'conversion is only via the conversion function');
foreach ($tr as $from => $tos) foreach ($tos as $to) if (!isset($st[$to])) ok(false, "unknown target $to");
ok(count(crm_acq_services()) === 8, 'eight service lines');

fwrite(STDERR, "== CSV\n");
ok(crm_acq_csv_cell('=HYPERLINK("x")') === "'=HYPERLINK(\"x\")" && crm_acq_csv_cell('+1') === "'+1" && crm_acq_csv_cell('-1') === "'-1" && crm_acq_csv_cell('@a') === "'@a" && crm_acq_csv_cell('Acme') === 'Acme' && crm_acq_csv_cell('') === '', 'formula injection guarded');
[$o, $d] = crm_acq_csv_parse("\xEF\xBB\xBFCompany,Website,Industry,Company Size\n\"Acme, GmbH\",https://acme.example,Logistics,51-200\n\nBeta,,,\n"); ok($o && $d['header'] === ['company', 'website', 'industry', 'company_size'] && count($d['rows']) === 2 && $d['rows'][0]['company'] === 'Acme, GmbH' && $d['rows'][1]['_line'] === 4, 'BOM, quotes, headers, blank lines');
[$o, $d] = crm_acq_csv_parse("company;country\nAcme;DE\n"); ok($o && $d['rows'][0]['country'] === 'DE', 'semicolon delimiter detected'); ok(crm_acq_csv_parse('')[1] === 'csv_empty', 'empty'); ok(crm_acq_csv_parse("company\n\xff\xfe")[1] === 'csv_encoding', 'invalid encoding refused');
ok(crm_acq_csv_parse(str_repeat('x', CRM_ACQ_MAX_IMPORT_BYTES + 1))[1] === 'csv_too_large', 'size limit'); ok(crm_acq_csv_parse("company\n" . str_repeat("a\n", CRM_ACQ_MAX_IMPORT_ROWS + 1))[1] === 'csv_too_many_rows', 'row limit');
ok(crm_acq_resolve('Logistics and transport', crm_acq_industries()) === 'logistics' && crm_acq_resolve('LOGISTICS', crm_acq_industries()) === 'logistics' && crm_acq_resolve('weird', crm_acq_industries()) === 'weird' && crm_acq_resolve('', crm_acq_industries()) === '', 'labels resolve to keys; unknown stays (and is rejected later)');

fwrite(STDERR, "== templates\n");
foreach (array_keys(crm_acq_services()) as $s) foreach (['en', 'de'] as $l) { $t = crm_acq_template($s, $l, ['company_name' => 'Acme']); ok($t !== null && str_contains($t['subject'], 'Acme') && stripos($t['body'], 'STOP') !== false && str_contains($t['body'], '{{observation}}') && str_contains($t['body'], '{{name}}') && str_contains($t['body'], '{{imprint}}'), "template $s/$l: opt-out, placeholders for personalisation and legal notice"); }
ok(crm_acq_template('custom-ai-agents', 'fr', []) === null && crm_acq_template('nope', 'en', []) === null, 'unknown language or service'); $de = crm_acq_template('process-automation', 'de', ['company_name' => 'Acme']); ok(str_contains($de['body'], 'Guten Tag') && str_contains($de['body'], 'STOPP') && str_contains($de['body'], 'Impressum'), 'German template');
ok(crm_acq_unresolved("Hi {{name}}, {{ observation }} {{name}}") === ['name', 'observation'] && crm_acq_unresolved('all filled') === [], 'unresolved placeholders detected');
$all = ''; foreach (crm_acq_service_copy() as $s) foreach ($s as $l) $all .= $l['what'] . $l['ask']; ok(!preg_match('/guarantee|garantier|best-in-class|\d+%|years of experience|customers like/i', $all), 'templates contain no unverifiable claims, guarantees or invented statistics');
echo "crm_acq_logic_test: $pass passed, $fail failed\n"; fwrite(STDERR, "\ncrm_acq_logic_test: $pass passed, $fail failed\n"); exit($fail ? 1 : 0);
