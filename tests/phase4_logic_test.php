<?php
/**
 * Phase 4 logic tests (no server needed): discovery explanations and synonyms, evidence levels, capability matrix,
 * alternatives, problem pages. Sections for P2–P5 are appended below as those features land.
 * Usage: php tests/phase4_logic_test.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('AIHUB_RATE_DIR=' . sys_get_temp_dir() . '/aihub_rate_p4_' . getmypid());
putenv('AIHUB_FORM_SECRET=phase4-test-secret');
require_once __DIR__ . '/../helpers.php';
$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "  FAIL: $m\n"; } }
function section(string $t): void { echo "== $t\n"; }

$data = load_tools(); $tools = $data['tools']; $byId = tools_by_id();
$names = fn(array $r) => array_map(fn($x) => $x['tool']['name'], $r);

section('P1 search: relevance explanations');
foreach (['chatgpt', 'transcription', 'pdf', 'image generator', 'customer support', 'perplexty', 'chat zebrafish'] as $q) {
  $r = search_tools_ranked($tools, $q, 20);
  $allReasons = $r && count(array_filter($r, fn($x) => !empty($x['reasons']))) === count($r);
  ok(!$r || $allReasons, "every result for “{$q}” carries at least one reason");
}
$r = search_tools_ranked($tools, 'chatgpt', 3);
ok(in_array('Exact name match', $r[0]['reasons'], true), 'exact name match is explained');
ok(in_array('Has a reviewed, sourced profile', $r[0]['reasons'], true), 'reviewed-profile boost is disclosed as a reason');
$r = search_tools_ranked($tools, 'perplexty', 3);
ok(!empty($r[0]['fuzzy']) && strpos(implode(' ', $r[0]['reasons']), 'possible typo') !== false, 'typo matches are labelled as such');

section('P1 search: synonyms and intent');
$direct = search_tools_ranked($tools, 'transcription', 40);
$viaSyn = search_tools_ranked($tools, 'transcribe', 40);
$related = array_filter($viaSyn, fn($x) => strpos(implode(' ', $x['reasons']), 'Related term') !== false);
ok(count($viaSyn) >= count($direct) / 2 && count($related) >= 1, 'a different word for the same task still finds tools, labelled “Related term”');
ok(in_array('Otter.ai', $names($viaSyn), true), 'transcribe finds Otter.ai through the synonym group');
$o = search_tools_ranked($tools, 'speech to text', 12);
ok(count($o) > 0 && count(array_filter($o, fn($x) => strpos(implode(' ', $x['reasons']), '“to”') !== false)) === 0, 'filler word “to” does not create matches');
ok(search_tokens('best tools to transcribe') === ['transcribe'], 'filler/generic words are dropped when real words remain');
ok(search_tokens('tools') === ['tools'], 'a query of only generic words is kept rather than emptied');
$ex = search_expansions('speech to text', search_tokens('speech to text'));
ok(isset($ex['speech']) && !isset($ex['text']) && isset($ex['__covered']), 'a phrase is one concept anchored on its first word');
$exact = search_tools_ranked($tools, 'otter', 3);
ok(($names($exact)[0] ?? '') === 'Otter.ai', 'direct name matches still outrank related-term matches');
$noSyn = search_tools_ranked($tools, 'zzqxv', 3);
ok($noSyn === [], 'nonsense queries return nothing');

section('P1 evidence levels');
$now = strtotime('2026-12-01');
$mk = fn(array $review, array $extra = []) => ['id' => 'x', 'name' => 'X', 'curated' => array_replace_recursive([
  'review' => $review, 'sources' => [['label' => 'a', 'url' => 'https://example.com/a', 'type' => 'vendor', 'retrieved' => '2026-11-15'], ['label' => 'b', 'url' => 'https://example.com/b', 'type' => 'vendor', 'retrieved' => '2026-11-15']],
  'pricing' => ['type' => 'Freemium', 'label' => 'Freemium', 'summary' => 'Free plus paid.', 'as_of' => '2026-11-15', 'confidence' => 'vendor pages'], 'key_features' => ['Code assistance'], 'limitations' => ['No image creation on the free tier']], $extra)];
$e = tool_evidence($mk(['status' => 'evidence-collected', 'reviewed_on' => '2026-11-15', 'human_verified' => false]), $now);
ok($e['level'] === 'evidence_collected' && !$e['human_verified'] && $e['source_count'] === 2, 'two sources and no conflicts: sourced, awaiting verification');
$e = tool_evidence($mk(['reviewed_on' => '2026-11-15', 'human_verified' => true, 'verified_on' => '2026-11-20']), $now);
ok($e['level'] === 'editor_verified' && $e['verified_on'] === '2026-11-20', 'human-verified within 90 days is verified');
$e = tool_evidence($mk(['reviewed_on' => '2026-01-01', 'human_verified' => true, 'verified_on' => '2026-01-01']), $now);
ok($e['level'] === 'verified_stale' && $e['stale'], 'verified more than 90 days ago is due for a re-check');
$e = tool_evidence($mk(['reviewed_on' => '2026-11-15', 'human_verified' => true]), $now);
ok($e['level'] !== 'editor_verified', 'human_verified without a verified_on date is not accepted as verified');
$e = tool_evidence($mk(['reviewed_on' => '2026-11-15'], ['known_source_conflicts' => ['Prices disagree']]), $now);
ok($e['level'] === 'limited' && count($e['conflicts']) === 1, 'conflicting sources lower confidence');
$e = tool_evidence($mk(['reviewed_on' => '2026-11-15'], ['pricing' => ['confidence' => 'vendor pages disagree']]), $now);
ok($e['level'] === 'limited', 'a weak pricing confidence note lowers the level');
$t1 = $mk(['reviewed_on' => '2026-11-15']); $t1['curated']['sources'] = [['label' => 'a', 'url' => 'javascript:alert(1)'], ['label' => 'b', 'url' => 'https://example.com/b']];
$e = tool_evidence($t1, $now);
ok($e['source_count'] === 1 && $e['level'] === 'limited', 'unsafe source URLs are not counted or linked');
$e = tool_evidence($mk(['reviewed_on' => '2026-11-15']), strtotime('2027-03-01'));
ok($e['pricing_stale'] && $e['stale'], 'old pricing dates are flagged stale');
$e = tool_evidence(['id' => 'y', 'name' => 'Y'], $now);
ok($e['level'] === 'unreviewed' && !$e['curated'] && $e['sources'] === [], 'no curated profile: not reviewed, no invented sources');
foreach ($byId as $id => $t) { if (empty($t['curated'])) continue; $ev = tool_evidence($t); ok($ev['level'] !== 'editor_verified', "$id is not claimed as editor verified (no human verification exists in the data)"); }
ok(strpos(render_evidence_badge(['id' => 'z', 'name' => 'Z']), 'Not reviewed') !== false, 'badge renders for unreviewed tools');

section('P1 capabilities: documented vs unknown, never invented');
$cap = tool_capabilities($mk(['reviewed_on' => '2026-11-15']));
ok($cap['code_assistance']['state'] === 'documented' && $cap['code_assistance']['evidence'] === 'Code assistance', 'documented capability carries its evidence text');
ok($cap['image_generation']['state'] === 'unknown', 'a capability mentioned only under limitations is NOT documented (unknown)');
ok($cap['free_plan']['state'] === 'documented', 'freemium pricing documents a free plan');
$paid = $mk(['reviewed_on' => '2026-11-15'], ['pricing' => ['type' => 'Paid', 'label' => 'Paid']]);
ok(tool_capabilities($paid)['free_plan']['state'] === 'none_listed', 'paid tool: “none listed”, not “no”');
$un = tool_capabilities(['id' => 'u', 'name' => 'U', 'pricing_type' => 'Freemium']);
ok($un['code_assistance']['state'] === 'not_reviewed' && $un['free_plan']['state'] === 'listing', 'unreviewed tools never get documented capabilities');
foreach ($byId as $id => $t) {
  foreach (tool_capabilities($t) as $k => $c) {
    if ($c['state'] === 'documented') ok($c['evidence'] !== '' && $c['field'] !== '', "$id/$k documented capability has evidence");
    ok(in_array($c['state'], ['documented', 'unknown', 'not_reviewed', 'none_listed', 'listing'], true), "$id/$k has a valid state");
  }
}
ok(capability_state_help('unknown') !== '' && stripos(capability_state_help('unknown'), 'does not mean') !== false, 'unknown is explained as not meaning “unsupported”');

section('P1 feature matrix');
$a = $byId['chatgpt']; $b = $byId['claude'];
$rows = capability_matrix([$a, $b]);
ok(count($rows) > 0 && count(array_filter($rows, fn($r) => count($r['cells']) === 2)) === count($rows), 'matrix rows have one cell per tool');
$u1 = ['id' => 'u1', 'name' => 'U1']; $u2 = ['id' => 'u2', 'name' => 'U2'];
ok(capability_matrix([$u1, $u2]) === [], 'two unreviewed tools: no rows (nothing is known)');
$allRows = capability_matrix([$a, $u1], false);
ok(count($allRows) === count(capability_defs()), 'full matrix lists every capability');
$flag = array_filter($rows, fn($r) => $r['differs']);
ok(count($flag) >= 1, 'rows where tools differ are flagged');
$mixed = capability_matrix([$a, $u1]);
ok(count(array_filter($mixed, fn($r) => $r['cells'][1]['state'] === 'not_reviewed')) === count($mixed), 'an unreviewed tool shows “not reviewed” in every row, never “no”');

section('P1 alternatives with reasons');
$alts = tool_alternatives_ranked($byId['otter-ai'], $byId, 6);
ok(count($alts) >= 2, 'Otter.ai has documented alternatives');
ok(count(array_filter($alts, fn($x) => $x['reasons'] === [])) === 0, 'every alternative states why it is listed');
ok(count(array_filter($alts, fn($x) => $x['tool']['id'] === 'otter-ai')) === 0, 'a tool is never its own alternative');
ok(count(array_filter($alts, fn($x) => empty($x['tool']['curated']))) === 0, 'only reviewed tools are offered with documented reasons');
ok(in_array('fireflies-ai', array_map(fn($x) => $x['tool']['id'], $alts), true), 'same-subcategory reviewed tool (Fireflies.ai) is an alternative');
ok(tool_alternatives_ranked(['id' => 'n', 'name' => 'N', 'category' => 'Zed'], $byId, 5) === [], 'a tool with no documented data gets no fabricated alternatives');

section('P1 problem pages and the evidence gate');
$probs = solutions_all();
ok(count($probs) >= 8, 'problem catalogue loaded (' . count($probs) . ')');
foreach ($probs as $slug => $p) {
  ok(!empty($p['title']) && !empty($p['query']) && !empty($p['look_for']) && !empty($p['ask_vendors']), "$slug has title, query, criteria and vendor questions");
  ok(!preg_match('/best|guarantee|#1|top-rated|\d+%/i', $p['problem'] . ' ' . implode(' ', $p['look_for'])), "$slug text makes no ranking or performance claims");
}
$trans = solution_results($tools, $probs['transcribe-meetings-and-interviews']);
ok(solution_indexable($trans), 'transcription problem has ≥3 reviewed tools: indexable');
$cs = solution_results($tools, $probs['answer-customer-questions']);
ok(!solution_indexable($cs), 'problem with <3 reviewed tools: not indexable (evidence gate)');
foreach ($probs as $slug => $p) ok(solution_indexable(solution_results($tools, $p)) === in_array($slug, solutions_indexable_slugs($tools), true), "$slug: page gate and sitemap gate agree");
$slugs = solutions_indexable_slugs($tools);
ok(in_array('transcribe-meetings-and-interviews', $slugs, true) && !in_array('answer-customer-questions', $slugs, true), 'sitemap list contains only gated pages');
ok(solution_get('nope') === null && solution_get('transcribe-meetings-and-interviews')['slug'] === 'transcribe-meetings-and-interviews', 'solution_get lookup');

// === P2–P5 sections are appended below ===

echo "\nPASSED: $pass  FAILED: $fail\n";
exit($fail ? 1 : 0);
