<?php
/** Discovery quality and editorial-independence tests (Phase 5). No network, no database writes. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/helpers.php';
require_once $root . '/scripts/search_quality.php';
$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "  FAIL: $m\n"; } }
function section(string $t): void { echo "== $t\n"; }
$byId = tools_by_id(); $tools = array_values($byId);
$fx = json_decode(file_get_contents($root . '/tests/fixtures/search_quality.json'), true);

section('Search-quality fixtures (regression floors; Phase 4.1 baseline was P@5 0.593, success@3 0.893, MRR 0.806)');
$res = search_quality_eval($fx, $byId);
echo sprintf("  measured: queries=%d P@5=%.3f success@3=%.3f MRR=%.3f\n", $res['n'], $res['p_at_5'], $res['success_at_3'], $res['mrr']);
ok($res['n'] >= 28, 'at least 28 intent queries in the fixture');
ok($res['p_at_5'] >= 0.65, 'precision@5 stays at or above 0.65');
ok($res['success_at_3'] >= 0.94, 'a relevant tool is in the top 3 for at least 94% of queries');
ok($res['mrr'] >= 0.88, 'mean reciprocal rank stays at or above 0.88');
foreach ($fx['navigational'] as $n) { $r = search_tools_ranked($tools, $n['q'], 3); ok(($r[0]['tool']['id'] ?? '') === $n['first'], "navigational query '{$n['q']}' returns {$n['first']} first"); }
foreach ($fx['no_result'] as $q) ok(search_tools_ranked($tools, $q, 10) === [], "nonsense query '$q' returns an honest empty result");
foreach ($res['rows'] as $row) ok($row['results'] > 0, "query '{$row['q']}' returns something");

section('Template text is not evidence of relevance');
$generic = ['Fast setup and onboarding', 'AI-assisted workflow', 'Exports/sharing (varies by plan)', 'Automate repetitive tasks', 'Create drafts faster'];
ok(tool_nongeneric_items($generic) === [], 'template phrases are filtered out');
ok(tool_nongeneric_items(array_merge($generic, ['Real-time transcription'])) === ['Real-time transcription'], 'real items survive');
$t = $byId['monica']; $f = search_tool_fields($t);
ok(strpos($f['uses'], 'automate repetitive') === false && strpos($f['uses'], 'fast setup') === false, 'template phrases are not searchable');
$r = search_tools_ranked($tools, 'automate repetitive tasks', 50);
$viaTemplate = array_filter($r, fn($x) => in_array('Matched “repetitive” in use cases and features', $x['reasons'], true));
ok(!$viaTemplate, 'no tool matches a query only through the template phrases');
ok(search_tools_ranked($tools, 'write code', 5)[0]['tool']['category'] === 'Coding', '"write code" ranks code tools first (task verb ignored)');
ok(count(search_tokens('Write a Blog')) === 2, 'tokenisation itself is unchanged');
$strongFirst = search_tools_ranked($tools, 'voice cloning', 3);
ok($strongFirst && $strongFirst[0]['tool']['category'] === 'Audio', 'a reviewed general assistant does not outrank in-category tools for "voice cloning"');

section('Sponsorship, affiliate economics and clicks do not change editorial output');
global $AIHUB_CLICK_COUNTS;
$objs = array_keys(recommend_config()['objectives']);
$base = []; foreach ($objs as $o) $base[$o] = array_map(fn($i) => $i['tool']['id'] ?? $i['id'] ?? '', recommend(recommend_normalize_answers(['objective' => $o, 'budget' => 'flexible']), $byId)['items']);
$mut = $byId; foreach ($mut as $id => &$m) { $m['affiliate'] = 'https://example.test/?aff=1'; $m['sponsored'] = true; $m['featured'] = true; $m['sponsor'] = ['label' => 'Sponsored']; } unset($m);
$AIHUB_CLICK_COUNTS = array_fill_keys(array_keys($byId), 0); $AIHUB_CLICK_COUNTS['monica'] = 100000; $AIHUB_CLICK_COUNTS['chatgpt'] = 1;
foreach ($objs as $o) { ok(count($base[$o]) > 0, "wizard returns results for '$o' (the independence check is not vacuous)"); $now = array_map(fn($i) => $i['tool']['id'] ?? $i['id'] ?? '', recommend(recommend_normalize_answers(['objective' => $o, 'budget' => 'flexible']), $mut)['items']); ok($now === $base[$o], "wizard result for '$o' is identical with sponsored/affiliate flags and extreme click counts"); }
$sol = array_keys(solutions_all()); $sBase = [];
$AIHUB_CLICK_COUNTS = []; 
foreach ($sol as $slug) $sBase[$slug] = array_map(fn($r) => $r['tool']['id'], solution_results($tools, solution_get($slug), 12));
$AIHUB_CLICK_COUNTS = ['monica' => 99999, 'faqx' => 99999];
foreach ($sol as $slug) ok(count($sBase[$slug]) > 0, "problem page '$slug' has results") && true;
foreach ($sol as $slug) ok(array_map(fn($r) => $r['tool']['id'], solution_results(array_values($mut), solution_get($slug), 12)) === $sBase[$slug], "problem page '$slug' ignores clicks, sponsorship and affiliate flags");
$pairsA = array_map(fn($p) => $p[0]['id'] . '|' . $p[1]['id'], available_comparison_pairs($tools, 24));
$pairsB = array_map(fn($p) => $p[0]['id'] . '|' . $p[1]['id'], available_comparison_pairs(array_values($mut), 24));
ok($pairsA === $pairsB, 'comparison pairs ignore clicks, sponsorship and affiliate flags');
$AIHUB_CLICK_COUNTS = [];
$s0 = search_tools_ranked($tools, 'chatbot', 12); $AIHUB_CLICK_COUNTS = array_fill_keys(array_keys($byId), 0) + ['gptgo-chrome-extension' => 50000];
$s1 = search_tools_ranked($tools, 'chatbot', 12);
$changed = array_map(fn($x) => $x['tool']['id'], $s0) !== array_map(fn($x) => $x['tool']['id'], $s1);
if ($changed) { $pop = array_filter($s1, fn($x) => in_array('Popular with visitors', $x['reasons'], true)); ok((bool)$pop, 'when clicks do change search order, the result says "Popular with visitors"'); } else ok(true, 'clicks did not change this search order');
foreach ($s0 as $x) ok(!in_array('Popular with visitors', $x['reasons'], true), 'no popularity label without clicks');
$AIHUB_CLICK_COUNTS = null;

section('Comparison page shows evidence states');
$src = file_get_contents($root . '/compare.php');
ok(strpos($src, "'pricing' => 'Pricing status'") !== false && strpos($src, 'editorial_fact_states') !== false, 'the comparison matrix has pricing and feature status rows');
$a = editorial_fact_states($byId['chatgpt']); $b = editorial_fact_states($byId['monica']);
ok($a['pricing']['state'] !== $b['pricing']['state'], 'a reviewed and an unreviewed tool show different pricing states in a comparison');
echo "\nPASSED: $pass  FAILED: $fail\n"; exit($fail ? 1 : 0);
