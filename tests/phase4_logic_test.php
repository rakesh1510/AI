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
/** PHP source of a file without comments, docblocks and string literals (so wording in comments or user-facing text does not trip source scans). */
function code_only(string $file, bool $noStrings = true): string { $o = ''; foreach (token_get_all((string)file_get_contents($file)) as $t) { if (is_array($t) && in_array($t[0], $noStrings ? [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING] : [T_COMMENT, T_DOC_COMMENT], true)) continue; $o .= is_array($t) ? $t[1] : $t; } return $o; }

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
$mk = fn(array $review, array $extra = []) => ['id' => 'x', 'name' => 'X', 'curated' => array_replace([
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

section('P2 packages and public pricing');
$pk = sponsorship_packages();
ok(isset($pk['free'], $pk['priority'], $pk['featured'], $pk['homepage'], $pk['featured_monthly'], $pk['newsletter_sponsor']), 'package catalogue has the listed tiers and the two inquiry-only packages');
foreach (['featured_monthly', 'newsletter_sponsor'] as $k) ok(($pk[$k]['kind'] ?? '') === 'inquiry' && !isset($pk[$k]['price']) && !empty($pk[$k]['status_text']), "$k has no public price and states its status");
$tiers = pricing_tiers();
ok((int)$tiers['priority']['price'] === 19 && (int)$tiers['featured']['price'] === 49 && (int)$tiers['homepage']['price'] === 99, 'existing list prices ($19/$49/$99) are unchanged');
ok(strpos((string)file_get_contents(__DIR__ . '/../data/sponsorship_packages.json'), '€') === false, 'proposed euro prices are not in the public package file');
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['SCRIPT_NAME'] = '/advertise.php'; $_GET = [];
ob_start(); require __DIR__ . '/../advertise.php'; $adv = ob_get_clean();
ok(strpos($adv, '€') === false && !preg_match('/\b(199|499)\b/', $adv), 'advertise page shows no euro prices and no 199/499 figures');
ok(strpos($adv, 'Package comparison') !== false && substr_count($adv, 'class="faqItem"') >= 6, 'advertise page has the package comparison and the FAQ');
ok(!preg_match('/trusted by|testimonial|case stud|guarantee[sd]? (traffic|results|roi)|\d+(,\d{3})*\+? (monthly )?(visitors|subscribers|readers|users)\b/i', $adv), 'advertise page contains no testimonials, audience numbers or guarantees');
ok(stripos($adv, 'does not publish audience') !== false && stripos($adv, 'not yet') !== false, 'page says audience figures are not published and the newsletter is not available yet');
$_GET = ['package' => 'newsletter_sponsor']; ob_start(); require __DIR__ . '/../advertise.php'; $adv2 = ob_get_clean(); $_GET = [];
ok(strpos($adv2, 'value="newsletter_sponsor" selected') !== false, 'deep link preselects the newsletter sponsorship interest');
$_GET = ['package' => '<script>']; ob_start(); require __DIR__ . '/../advertise.php'; $adv3 = ob_get_clean(); $_GET = [];
ok(strpos($adv3, 'value="<script>') === false && strpos($adv3, 'package=<script>') === false && strpos($adv3, 'value="" selected') !== false, 'unknown package parameter is ignored safely');

section('P2 lead qualification and attribution');
$tok = form_token('lead', time() - 5);
$post = ['lead_type' => 'advertiser', 'name' => 'Pat Example', 'email' => 'pat@example.test', 'message' => 'We would like to talk about a featured placement.', 'consent' => '1', 'form_token' => $tok,
  'package_interest' => 'featured', 'campaign_goal' => 'signups', 'attr_source' => 'Newsletter', 'attr_medium' => 'email', 'attr_campaign' => 'Spring Launch!!', 'ref_tool' => 'chatgpt'];
$v = lead_validate($post, ['advertiser', 'affiliate_partner', 'newsletter_sponsor']);
ok($v['errors'] === [] && $v['clean']['package_interest'] === 'featured' && $v['clean']['campaign_goal'] === 'signups', 'valid qualification answers accepted');
ok($v['clean']['attr_source'] === 'newsletter' && $v['clean']['attr_campaign'] === '' && $v['clean']['ref_tool'] === 'chatgpt', 'attribution is normalised to slugs and unsafe values are dropped');
$v = lead_validate(['package_interest' => 'free-money', 'campaign_goal' => 'x'] + $post, ['advertiser']);
ok($v['clean']['package_interest'] === '' && $v['clean']['campaign_goal'] === '', 'unknown package or goal values are discarded');
$v = lead_validate(['lead_type' => 'newsletter_sponsor'] + $post, ['advertiser', 'affiliate_partner', 'newsletter_sponsor']);
ok($v['errors'] === [] && lead_types()['newsletter_sponsor']['group'] === 'advertise', 'newsletter sponsorship inquiry is an advertise-group lead');
ok(lead_attribution_from_request(['utm_source' => 'A<b>', 'tool' => str_repeat('a', 200)]) === ['attr_source' => '', 'attr_medium' => '', 'attr_campaign' => '', 'ref_tool' => ''], 'attribution rejects markup and over-long values');
ok(lead_attribution_from_request(['ref' => 'tool-page', 'tool' => 'otter-ai'])['attr_source'] === 'tool-page', 'ref parameter is used as the source when utm_source is absent');
$form = render_lead_form(['types' => ['advertiser'], 'id' => 'x', 'packages' => true]);
ok(strpos($form, 'name="package_interest"') !== false && strpos($form, 'name="attr_source"') !== false, 'advertiser form renders the package selector and attribution fields');
ok(strpos(render_lead_form(['types' => ['consultation'], 'id' => 'y', 'budget' => true]), 'package_interest') === false, 'business form does not show advertiser fields');

$dbName = sec_setting('AIHUB_DB_NAME', 'db.name');
if (stripos($dbName, 'test') !== false && db_connection_available()) {
  section('P2 lead storage, status workflow and reporting (isolated test DB)');
  $pdo = db();
  $pdo->exec("DELETE FROM lead_status_history"); $pdo->exec("DELETE FROM leads");
  $c = lead_validate($post, ['advertiser'])['clean'];
  $id = lead_store($pdo, $c, 'phase4test000000');
  $row = lead_get($pdo, $id);
  ok($row && $row['package_interest'] === 'featured' && $row['attr_source'] === 'newsletter' && $row['ref_tool'] === 'chatgpt' && (int)$row['is_synthetic'] === 0, 'qualification and attribution are stored in their own columns');
  $pdo->exec("DROP TABLE IF EXISTS leads_legacy");
  $pdo->exec("CREATE TABLE leads_legacy (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, created_at DATETIME NOT NULL, lead_type VARCHAR(32) NOT NULL, name VARCHAR(120) NOT NULL, email VARCHAR(190) NOT NULL, company VARCHAR(190) NOT NULL DEFAULT '', website VARCHAR(255) NOT NULL DEFAULT '', budget VARCHAR(40) NOT NULL DEFAULT '', timeline VARCHAR(40) NOT NULL DEFAULT '', message TEXT NOT NULL, source_page VARCHAR(120) NOT NULL DEFAULT '', consent_at DATETIME NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'new', ip_hash CHAR(16) NOT NULL DEFAULT '', PRIMARY KEY (id))");
  $lid = lead_store($pdo, $c, 'phase4test000000', false, 'leads_legacy');
  $m = (string)$pdo->query("SELECT message FROM leads_legacy WHERE id = $lid")->fetchColumn();
  ok($lid > 0 && strpos($m, '[Package interest: Featured listing]') !== false && strpos($m, '[Source: newsletter]') !== false, 'before the migration, qualification answers are appended to the message instead of being lost or crashing');
  $pdo->exec("DROP TABLE leads_legacy");
  $threw = false; try { lead_store($pdo, $c, 'x', false, 'users; DROP TABLE leads'); } catch (InvalidArgumentException $e) { $threw = true; }
  ok($threw, 'table name parameter is validated');

  ok(lead_can_move('new', 'contacted') && !lead_can_move('new', 'won') && lead_can_move('qualified', 'won') && !lead_can_move('won', 'lost') && lead_can_move('spam', 'new'), 'status transitions follow the workflow');
  ok(lead_set_status($pdo, $id, 'won') === 'invalid_transition', 'cannot jump from new straight to won');
  ok(lead_set_status($pdo, $id, 'nonsense') === 'unknown_status' && lead_set_status($pdo, 999999, 'contacted') === 'not_found', 'unknown status or lead is refused');
  ok(lead_set_status($pdo, $id, 'contacted') === 'ok' && lead_set_status($pdo, $id, 'qualified') === 'ok' && lead_set_status($pdo, $id, 'won') === 'ok', 'new → contacted → qualified → won is accepted');
  $row = lead_get($pdo, $id);
  ok($row['status'] === 'won' && count($row['history']) === 3 && $row['history'][2]['new_status'] === 'won' && $row['status_changed_at'] !== null, 'every change is written to the status history');

  ok(lead_mask_email('patricia@example.com') === 'pa***@example.com' && lead_mask_email('a@b.co') === 'a***@b.co' && lead_mask_email('nonsense') === '***', 'email masking for list views');

  $pdo->exec("DELETE FROM lead_status_history"); $pdo->exec("DELETE FROM leads");
  $mk = function (string $type, string $status, string $pkg = '', int $ageDays = 1, int $syn = 0, string $src = '') use ($pdo, $c) {
    $cc = ['lead_type' => $type, 'email' => 'q' . mt_rand(1, 99999) . '@example.test', 'package_interest' => $pkg, 'attr_source' => $src] + $c;
    $i = lead_store($pdo, $cc, 'phase4test000000', (bool)$syn);
    $pdo->prepare('UPDATE leads SET status = ?, created_at = DATE_SUB(NOW(), INTERVAL ? DAY) WHERE id = ?')->execute([$status, $ageDays, $i]);
    return $i;
  };
  $mk('advertiser', 'new', 'featured'); $mk('advertiser', 'qualified', 'homepage', 2, 0, 'newsletter'); $mk('consultation', 'won', '', 3, 1); $mk('ai_agent', 'lost', '', 4); $mk('software_dev', 'spam', '', 5); $mk('advertiser', 'new', 'featured', 200);
  $r = leads_report($pdo, 90);
  ok($r['total'] === 5 && array_sum(array_column($r['by_type'], 'n')) === 5 && array_sum(array_column($r['by_status'], 'n')) === 5, 'report totals add up and respect the period (old lead excluded)');
  ok($r['synthetic'] === 1, 'synthetic rows are counted so the page can warn');
  ok($r['stages']['new'] >= $r['stages']['contacted'] && $r['stages']['contacted'] >= $r['stages']['qualified'] && $r['stages']['qualified'] >= $r['stages']['won'], 'stage counts never increase down the funnel');
  ok(!array_filter(array_keys($r), fn($k) => preg_match('/rate|revenue|roi|conversion/i', $k)), 'report contains raw counts only: no rate, revenue, ROI or conversion fields');
  $l = leads_list($pdo, ['type' => 'advertiser', 'days' => 90]);
  ok($l['total'] === 2, 'list filters by type and period');
  ok(leads_list($pdo, ['status' => 'won', 'days' => 90])['total'] === 1 && leads_list($pdo, ['status' => 'bogus', 'days' => 90])['total'] === 5, 'status filter works and ignores unknown values');
  ok(leads_list($pdo, ['q' => "%' OR 1=1 --", 'days' => 90])['total'] === 0, 'search text is bound and wildcards are escaped (no injection)');
  ok(leads_list($pdo, ['days' => 90, 'page' => 99])['rows'] === [], 'out-of-range page is empty');
  $pdo->exec("DELETE FROM lead_status_history"); $pdo->exec("DELETE FROM leads");
}

section('P2 editorial independence: payment cannot change ranking');
$base = search_tools_ranked($tools, 'writing', 20);
$boosted = array_map(function ($t) { if ($t['id'] === 'grammarly') { $t['featured_level'] = 2; $t['featured_until'] = '2099-01-01'; $t['affiliate'] = 'https://example.com/aff'; } return $t; }, $tools);
$after = search_tools_ranked($boosted, 'writing', 20);
ok(array_map(fn($x) => $x['tool']['id'], $base) === array_map(fn($x) => $x['tool']['id'], $after), 'a featured/affiliate tool ranks identically in search');
$src = code_only(__DIR__ . '/../discovery.php') . code_only(__DIR__ . '/../evidence.php');
ok(!preg_match('/featured_level|featured_until|affiliate|sponsor/i', $src), 'search, problem pages and alternatives code never read any paid-placement field');

section('P3 recommendation wizard: configuration and inputs');
$cfg = recommend_config();
ok(count($cfg['objectives']) >= 6, 'wizard has at least six goals');
foreach ($cfg['objectives'] as $k => $o) {
  $w = $o['caps']; arsort($w); $main = array_key_first($w);
  $n = count(array_filter($byId, fn($t) => !empty($t['curated']) && (tool_capabilities($t)[$main]['state'] ?? '') === 'documented'));
  ok($n >= 2, "goal “{$k}” is offered only because ≥2 reviewed tools document its main capability ($main: $n)");
  ok(count($o['workflow']) >= 3 && !empty($o['subcategories']), "goal “{$k}” has a getting-started workflow");
}
$a = recommend_normalize_answers(['objective' => 'meetings', 'budget' => 'flexible']);
ok($a['complete'] && $a['role'] === 'other' && $a['experience'] === 'intermediate' && $a['team'] === 'solo' && $a['integrations'] === [], 'optional answers get neutral defaults');
$a = recommend_normalize_answers([]);
ok(!$a['complete'] && count($a['errors']) === 2, 'objective and budget are required');
$a = recommend_normalize_answers(['objective' => '<script>', 'budget' => "' OR 1=1", 'role' => 'ceo', 'integrations' => ['slack', '<img>', ['x'], 'slack']]);
ok(!$a['complete'] && $a['objective'] === '' && $a['role'] === 'other' && $a['integrations'] === ['slack'], 'invalid or hostile values are dropped, duplicates removed');
ok(recommend(recommend_normalize_answers([]), $byId)['status'] === 'incomplete', 'incomplete answers give no recommendation');

section('P3 recommendation wizard: results are deterministic and explained');
$ans = recommend_normalize_answers(['objective' => 'meetings', 'budget' => 'flexible', 'team' => 'small', 'role' => 'manager', 'integrations' => ['zoom']]);
$r1 = recommend($ans, $byId); $r2 = recommend($ans, $byId);
ok(json_encode($r1) === json_encode($r2), 'same answers give identical results');
ok($r1['status'] === 'ok' && count($r1['items']) >= 3 && count($r1['items']) <= 5, 'three to five tools are recommended when enough are documented (' . count($r1['items']) . ')');
$ids = array_map(fn($i) => $i['tool']['id'], $r1['items']);
ok(count(array_intersect($ids, ['otter-ai', 'fireflies-ai', 'trint', 'sonix'])) >= 3, 'transcription goal returns transcription tools');
foreach ($r1['items'] as $it) {
  ok(!empty($it['tool']['curated']), $it['tool']['id'] . ' is a reviewed tool');
  ok(count($it['reasons']) >= 2 && preg_match('/^\+\d/', $it['reasons'][0]) === 1, $it['tool']['id'] . ' has itemised reasons with points');
  ok($it['pricing']['summary'] !== '' && !empty($it['pricing']['as_of']) && $it['evidence']['source_count'] >= 1, $it['tool']['id'] . ' shows sourced pricing with its date');
  ok(strpos(implode(' ', $it['reasons']), '“') !== false, $it['tool']['id'] . ' reasons quote the documented evidence');
}
$scores = array_map(fn($i) => $i['score'], $r1['items']); $sorted = $scores; rsort($sorted);
ok($scores === $sorted, 'results are ordered by score');
ok(count($r1['workflow']) >= 5 && strpos(implode(' ', $r1['workflow']), $r1['items'][0]['tool']['name']) !== false, 'getting-started workflow names the recommended tools');
ok(strpos(implode(' ', $r1['workflow']), 'Pilot with two or three teammates') !== false, 'team size changes the workflow');
ok(strpos(end($r1['workflow']), 'pricing page and data policy yourself') !== false, 'workflow ends by sending the reader to the vendor for current prices');
ok(count($r1['explain']) >= 6, 'the page can explain how each answer was used');
$beg = recommend(recommend_normalize_answers(['objective' => 'code', 'budget' => 'flexible', 'experience' => 'beginner']), $byId);
ok(strpos($beg['workflow'][0], '30 minutes') !== false, 'beginner experience adds a simple first step');
$adv = recommend(recommend_normalize_answers(['objective' => 'code', 'budget' => 'flexible', 'experience' => 'advanced']), $byId);
ok(strpos(implode(' ', $adv['workflow']), 'API documentation') !== false, 'advanced experience adds an API step');
foreach ($r1['combos'] as $cb) ok($cb['a_for'] !== [] && $cb['b_for'] !== [], 'a combination exists only when each tool adds something the other does not documents');

section('P3 recommendation wizard: honesty rules');
$mkT = function (string $id, array $over = []) { return ['id' => $id, 'name' => strtoupper($id), 'category' => 'Productivity', 'subcategory' => 'Meetings & transcription', 'pricing_type' => 'Paid', 'website' => 'https://example.com', 'curated' => array_replace([
  'review' => ['reviewed_on' => '2026-11-15', 'human_verified' => false], 'sources' => [['label' => 'a', 'url' => 'https://example.com/a'], ['label' => 'b', 'url' => 'https://example.com/b']],
  'pricing' => ['type' => 'Paid', 'label' => 'Paid', 'summary' => 'Paid plans.', 'as_of' => '2026-11-15', 'confidence' => 'vendor pages'],
  'key_features' => ['Meeting transcription and notes', 'Works with Slack'], 'use_cases' => [], 'limitations' => ['Needs a paid plan'], 'best_for' => [], 'strengths' => [], 'summary' => 'A paid tool.', 'alternatives' => []], $over)]; };
$synthetic = ['paid' => $mkT('paid'), 'free' => $mkT('free', ['pricing' => ['type' => 'Freemium', 'label' => 'Freemium']]), 'unk' => $mkT('unk', ['key_features' => ['Meeting transcription and notes']])];
$free = recommend(recommend_normalize_answers(['objective' => 'meetings', 'budget' => 'free_only']), $synthetic);
ok(array_map(fn($i) => $i['tool']['id'], $free['items']) !== [] && !in_array('paid', array_map(fn($i) => $i['tool']['id'], $free['items']), true), 'free-only budget excludes tools with no documented free plan');
ok($free['excluded_free'] === 2 && count($free['notes']) === 1, 'tools left out because of the budget are counted and disclosed');
$flex = recommend(recommend_normalize_answers(['objective' => 'meetings', 'budget' => 'flexible']), $synthetic);
ok(count($flex['items']) === 3, 'flexible budget keeps tools without a free plan');
$withInt = recommend(recommend_normalize_answers(['objective' => 'translate', 'budget' => 'flexible', 'integrations' => ['slack']]), $synthetic);
$sc = []; foreach ($withInt['items'] as $it) $sc[$it['tool']['id']] = $it;
ok($sc['paid']['score'] - $sc['unk']['score'] === 2.0, 'a documented integration adds exactly 2 points; the undocumented tool is not penalised');
ok(strpos(implode(' ', $sc['paid']['reasons']), 'Slack is mentioned') !== false && strpos(implode(' ', $sc['unk']['unknown']), 'Slack integration is not documented') !== false, 'documented integration quotes the source text; missing one is listed as not documented');
$solo = recommend(recommend_normalize_answers(['objective' => 'meetings', 'budget' => 'flexible', 'team' => 'solo']), ['t' => $mkT('team', ['key_features' => ['Meeting transcription and notes', 'Team plans']])]);
$big = recommend(recommend_normalize_answers(['objective' => 'meetings', 'budget' => 'flexible', 'team' => 'large']), ['t' => $mkT('team', ['key_features' => ['Meeting transcription and notes', 'Team plans']])]);
ok($big['items'][0]['score'] - $solo['items'][0]['score'] === 3.0, 'larger teams add points only for documented team plans');
ok(recommend(recommend_normalize_answers(['objective' => 'images', 'budget' => 'flexible']), $synthetic)['status'] === 'none', 'when nothing documents the goal the answer is "none", not a guess');
ok(recommend(recommend_normalize_answers(['objective' => 'meetings', 'budget' => 'flexible']), ['plain' => ['id' => 'plain', 'name' => 'Plain', 'category' => 'X']])['items'] === [], 'unreviewed tools are never recommended');
$feat = $synthetic; $feat['paid']['featured_level'] = 2; $feat['paid']['affiliate'] = 'https://example.com/aff'; $feat['paid']['curated']['clicks'] = 999999;
ok(json_encode(array_map(fn($i) => [$i['tool']['id'], $i['score']], recommend(recommend_normalize_answers(['objective' => 'meetings', 'budget' => 'flexible']), $synthetic)['items'])) === json_encode(array_map(fn($i) => [$i['tool']['id'], $i['score']], recommend(recommend_normalize_answers(['objective' => 'meetings', 'budget' => 'flexible']), $feat)['items'])), 'sponsorship, affiliate links and click counts do not change the recommendation');
ok(!preg_match('/featured|affiliate|sponsor|click|vote|rating|popular/i', code_only(__DIR__ . '/../recommend_engine.php')), 'recommendation code never reads paid or popularity fields');
ok(!preg_match('/curl|file_get_contents\(\s*[\'"]http|openai|anthropic|api\.|fetch\(/i', code_only(__DIR__ . '/../recommend_engine.php')), 'recommendation code makes no external calls and uses no AI API');

section('P3 recommendation wizard: page');
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['SCRIPT_NAME'] = '/recommend.php';
$_GET = []; $robots = null; ob_start(); require __DIR__ . '/../recommend.php'; $pg0 = ob_get_clean();
ok(substr_count($pg0, '<fieldset>') === 6 && substr_count($pg0, 'type="radio"') >= 17 && strpos($pg0, 'id="results"') === false, 'empty form: six fieldsets, no results, no fabricated defaults');
ok(preg_match('/<legend>[^<]*(role|do|budget|technical|use it|integrations)/i', $pg0) === 1 && strpos($pg0, 'for="role"') !== false, 'questions have legends and labels');
$_GET = ['go' => '1', 'objective' => 'meetings', 'budget' => 'free_only', 'integrations' => ['zoom'], 'role' => '<b>x</b>'];
$robots = null; ob_start(); require __DIR__ . '/../recommend.php'; $pg1 = ob_get_clean();
ok(strpos($pg1, 'Why it matches') !== false && strpos($pg1, 'Not documented in our sources') !== false && strpos($pg1, 'Pricing, as stated in sources') !== false, 'result page shows reasons, unknowns and pricing sources');
ok(strpos($pg1, 'content="noindex, follow"') !== false, 'result URLs are noindex');
ok(strpos($pg1, '<b>x</b>') === false, 'request values are never echoed unescaped');
ok(strpos($pg1, 'Nothing you choose here is stored') !== false || strpos($pg0, 'Nothing you choose here is stored') !== false, 'privacy statement is on the page');
$_GET = ['go' => '1']; $robots = null; ob_start(); require __DIR__ . '/../recommend.php'; $pg2 = ob_get_clean();
ok(strpos($pg2, 'Choose what you want to do.') !== false && strpos($pg2, 'Why it matches') === false, 'missing required answers produce an error, not a shortlist');
$_GET = [];

section('P5 change log: verified entries only');
$good = ['tool' => 'otter-ai', 'kind' => 'pricing', 'date' => '2026-11-10', 'summary' => 'Pro plan price changed on the vendor page.', 'evidence' => ['https://otter.ai/pricing'], 'verified_by' => 'A. Editor', 'verified_on' => '2026-11-11'];
$now = strtotime('2026-11-17');
ok(changelog_entry_errors($good, $byId, $now) === [], 'a complete entry validates');
foreach ([['tool', 'nope'], ['kind', 'rumour'], ['date', '2026-13-40'], ['date', '2027-01-01'], ['summary', ''], ['summary', '<b>x</b>'], ['evidence', []], ['evidence', ['javascript:alert(1)']], ['verified_by', ''], ['verified_on', 'yesterday']] as [$f, $v]) {
  ok(changelog_entry_errors([$f => $v] + $good, $byId, $now) !== [], "invalid $f is rejected (" . json_encode($v) . ')');
}
$entries = changelog_entries($byId, $now);
ok(count($entries) >= 20 && count(array_filter($entries, fn($e) => !empty($e['verified']))) === 0, 'with no manual entries, the log has only derived markers and none is called verified');
ok(count(array_filter($entries, fn($e) => !empty($e['derived']) && strpos($e['summary'], 'not yet verified by an editor') !== false)) >= 15, 'derived entries say plainly that no editor has verified them');
$dates = array_column($entries, 'date'); $sortedD = $dates; rsort($sortedD);
ok($dates === $sortedD, 'entries are newest first');
ok(changelog_for_tool('otter-ai', $byId) !== [] && count(array_filter(changelog_for_tool('otter-ai', $byId), fn($e) => $e['tool'] !== 'otter-ai')) === 0, 'per-tool change log filters by tool');
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['SCRIPT_NAME'] = '/changelog.php'; $_GET = [];
ob_start(); require __DIR__ . '/../changelog.php'; $cl = ob_get_clean();
ok(strpos($cl, 'No editor-verified changes have been recorded yet') !== false && strpos($cl, 'Profile reviews') !== false, 'change log page states honestly that nothing is verified yet');

section('P5 newsletter archive, roundup and mock delivery');
ok(newsletter_issues_public() === [], 'no published issues exist: the archive is empty (newsletter has not launched)');
$issue = ['slug' => 'roundup-test', 'title' => 'Test roundup', 'status' => 'published', 'published_on' => '2026-11-17', 'editor' => 'A. Editor', 'summary' => 'Notes for the week.', 'items' => [['tool' => 'otter-ai', 'note' => 'Otter.ai pricing page updated.', 'source' => 'https://otter.ai/pricing']]];
ok(newsletter_issue_errors($issue) === [], 'a complete issue validates');
foreach ([['editor', ''], ['published_on', ''], ['items', []], ['items', [['note' => 'x', 'source' => 'javascript:1']]], ['items', [['note' => '', 'source' => 'https://example.com']]], ['sponsor', ['text' => 'x']], ['slug', 'Bad Slug!'], ['summary', 'We tested every tool in our tests show it wins.']] as [$f, $v]) {
  ok(newsletter_issue_errors([$f => $v] + $issue) !== [], "issue with invalid $f is blocked from publishing");
}
$draft = newsletter_roundup_draft($byId, '2026-W47', 30, $now);
ok($draft['status'] === 'draft' && $draft['editor'] === '' && $draft['published_on'] === '' && count($draft['items']) >= 10 && count($draft['checklist']) >= 4, 'roundup is always a draft with an editorial checklist');
ok(newsletter_issue_errors($draft) !== [], 'a fresh draft cannot be published until an editor, date and review are added');
foreach ($draft['items'] as $it) ok($it['source'] !== '' && sec_safe_external_url($it['source']) !== null, 'draft item for ' . $it['tool'] . ' carries a valid source');
$txt = newsletter_render_text($issue, 'https://aihubpedia.com/unsubscribe.php?token=' . str_repeat('a', 32));
ok(strpos($txt, 'Unsubscribe: https://aihubpedia.com/unsubscribe.php?token=') !== false && strpos($txt, 'Source: https://otter.ai/pricing') !== false, 'rendered text has sources and an unsubscribe link');
$withSponsor = newsletter_render_text($issue + ['sponsor' => ['label' => 'Acme', 'text' => 'Try Acme.']], 'https://x/u');
ok(strpos($withSponsor, '[Sponsored: Acme]') !== false, 'sponsor content is labelled in the email text');
$_SERVER['SCRIPT_NAME'] = '/newsletter-archive.php'; $robots = null;
ob_start(); require __DIR__ . '/../newsletter-archive.php'; $arch = ob_get_clean();
ok(strpos($arch, 'No issues published yet') !== false && strpos($arch, 'noindex') !== false, 'empty archive is honest and noindex (thin page)');

if (stripos($dbName, 'test') !== false && db_connection_available()) {
  $pdo = db();
  $pdo->exec("DELETE FROM subscribers");
  $ins = $pdo->prepare("INSERT INTO subscribers (email, status, token, created_at, consent_text) VALUES (?, ?, ?, NOW(), 'test')");
  foreach ([['ok1@example.test', 'confirmed'], ['ok2@example.test', 'confirmed'], ['pend@example.test', 'pending'], ['gone@example.test', 'unsubscribed']] as $i => [$em, $st]) $ins->execute([$em, $st, str_pad((string)$i, 32, 'a')]);
  $outbox = sys_get_temp_dir() . '/aihub_outbox_' . getmypid(); @mkdir($outbox, 0700, true); foreach (glob("$outbox/*") ?: [] as $f) @unlink($f);
  $issueFile = sys_get_temp_dir() . '/aihub_issue_' . getmypid() . '.json'; file_put_contents($issueFile, json_encode($issue));
  $env = 'AIHUB_MAIL_TRANSPORT=file AIHUB_MAIL_OUTBOX=' . escapeshellarg($outbox) . ' AIHUB_DB_HOST=' . escapeshellarg(getenv('AIHUB_DB_HOST') ?: '127.0.0.1') . ' AIHUB_DB_NAME=' . escapeshellarg(getenv('AIHUB_DB_NAME') ?: '') . ' AIHUB_DB_USER=' . escapeshellarg(getenv('AIHUB_DB_USER') ?: '') . ' AIHUB_DB_PASS=' . escapeshellarg(getenv('AIHUB_DB_PASS') ?: '');
  $out = (string)shell_exec("cd " . escapeshellarg(dirname(__DIR__)) . " && $env php scripts/newsletter_send_mock.php --file=" . escapeshellarg($issueFile) . " 2>&1");
  $files = glob("$outbox/*.json") ?: [];
  ok(count($files) === 2 && strpos($out, 'Nothing was sent') !== false, 'mock delivery writes one message per CONFIRMED subscriber only (' . count($files) . ')');
  $msgs = array_map(fn($f) => json_decode((string)file_get_contents($f), true), $files); $to = array_column($msgs, 'to'); sort($to);
  ok($to === ['ok1@example.test', 'ok2@example.test'], 'pending and unsubscribed addresses are skipped');
  ok(count(array_filter($msgs, fn($m) => !empty($m['mock']) && strpos($m['text'], 'Unsubscribe:') !== false && isset($m['headers']['List-Unsubscribe']))) === 2, 'every message has an unsubscribe link and a List-Unsubscribe header');
  $refuse = (string)shell_exec("cd " . escapeshellarg(dirname(__DIR__)) . " && AIHUB_MAIL_TRANSPORT=smtp php scripts/newsletter_send_mock.php --file=" . escapeshellarg($issueFile) . " 2>&1; echo EXIT=\$?");
  ok(strpos($refuse, 'Real newsletter sending is not enabled') !== false && strpos($refuse, 'EXIT=1') !== false, 'the script refuses to run with a real transport');
  putenv('AIHUB_MAIL_TRANSPORT=file'); putenv('AIHUB_MAIL_OUTBOX=' . $outbox);
  ok(mail_transport() === 'file' && send_mail('x@example.test', "Sub\r\nBcc: evil@example.test", '<p>x</p>') === true, 'file transport accepts a message');
  $last = json_decode((string)file_get_contents(array_values(array_diff(glob("$outbox/*.json"), $files))[0]), true);
  ok(strpos($last['subject'], "\n") === false && strpos($last['subject'], "\r") === false, 'header injection in the subject is neutralised');
  putenv('AIHUB_MAIL_TRANSPORT'); putenv('AIHUB_MAIL_OUTBOX');
  foreach (glob("$outbox/*") ?: [] as $f) @unlink($f); @rmdir($outbox); @unlink($issueFile);
  $pdo->exec("DELETE FROM subscribers");
}

section('P4 content templates, evidence gates and linter');
$tpls = content_templates();
ok(count($tpls) === 6 && isset($tpls['best-for-task'], $tpls['a-vs-b'], $tpls['alternatives'], $tpls['industry-workflow'], $tpls['agent-implementation'], $tpls['pricing-comparison']), 'all six required templates exist');
foreach ($tpls as $k => $t) ok(!empty($t['sections']) && !empty($t['never']) && isset($t['min_reviewed'], $t['min_sources_per_tool']), "template $k lists sections, forbidden claims and evidence minimums");
ok(editorial_gate('industry-workflow', ['tools' => array_values(array_filter($byId, fn($t) => !empty($t['curated'])))])['pass'] === false, 'industry workflows cannot be published without recorded hands-on testing and editor approval');
ok(editorial_gate('agent-implementation', ['tools' => array_values(array_filter($byId, fn($t) => !empty($t['curated']))), 'human_approved' => true])['pass'] === false, 'agent guides stay blocked without test evidence even when approved');
$three = array_values(array_filter($byId, fn($t) => in_array($t['id'], ['otter-ai', 'trint', 'sonix'], true)));
ok(editorial_gate('pricing-comparison', ['tools' => $three])['pass'] === true, 'pricing comparison passes for three reviewed, sourced tools');
ok(editorial_gate('pricing-comparison', ['tools' => array_slice($three, 0, 2)])['pass'] === false, 'pricing comparison is blocked with only two tools');
ok(editorial_gate('pricing-comparison', ['tools' => [$byId['otter-ai'], $byId['trint'], ['id' => 'raw', 'name' => 'Raw']]])['pass'] === false, 'an unreviewed tool does not count toward the minimum');
ok(editorial_gate('nope', [])['pass'] === false, 'unknown templates never pass');
$g = pricing_groups($byId);
ok(isset($g['meetings-transcription']) && isset($g['general-ai-assistants']) && !isset($g['image-generation-editing']), 'pricing groups exist only where three reviewed tools have sources');
ok(editorial_lint('We tested all of them and our tests show Acme wins.')['problems'] !== [], 'lint: unsupported testing claims');
ok(editorial_lint('We tested it.', ['acme'])['problems'] === [], 'lint: testing claims allowed when an evidence record exists');
ok(editorial_lint('The best AI writing tool, the number one choice.')['problems'] !== [], 'lint: superlatives without a methodology link');
ok(editorial_lint('The best-ranked tool, see our methodology for how we rank.')['problems'] === [], 'lint: superlatives allowed with a methodology reference');
ok(editorial_lint('Guaranteed results, 100% accurate and risk-free.')['problems'] !== [], 'lint: guaranteed/absolute claims');
ok(editorial_lint(str_repeat('transcription software transcription tools transcription app ', 12))['problems'] !== [], 'lint: keyword stuffing');
ok(editorial_lint('Short note about a tool and its plans.')['problems'] === [], 'lint: ordinary factual text passes');
ok(editorial_lint('<p>Plain <b>text</b> only</p>')['words'] === 3, 'lint: counts words without markup');
foreach (guides_all() as $slug => $guide) {
  $text = strip_tags(json_encode($guide));
  ok(!preg_match('/\b(we|our team) (tested|benchmarked)\b|\bhands-on\b|\bin our tests\b/i', $text), "existing guide $slug makes no testing claims");
}

section('P4 SEO/GEO: indexation controls and sitemap honesty');
$thin = ['content' => '<p>' . str_repeat('word ', 100) . '</p>']; $full = ['content' => '<p>' . str_repeat('word ', 260) . '</p>'];
ok(post_word_count($thin) === 100 && !post_is_indexable($thin) && post_is_indexable($full), 'posts under 250 words are not indexable');
ok(posts_indexable() === [], 'both existing posts are thin and therefore excluded (not deleted)');
require_once __DIR__ . '/../sitemap_lib.php';
$entries = sitemap_entries(); $locs = array_column($entries, 'loc');
ok(count(array_filter($locs, fn($l) => strpos($l, 'post.php') !== false || strpos($l, '/blog.php') !== false)) === 0, 'thin posts and the blog index are not in the sitemap');
$home = array_values(array_filter($entries, fn($e) => $e['loc'] === 'https://aihubpedia.com/'))[0];
ok(empty($home['lastmod']), 'homepage has no invented lastmod');
$today = date('Y-m-d');
ok(count(array_filter($entries, fn($e) => ($e['lastmod'] ?? '') === $today)) === 0, 'no sitemap entry claims it was modified today (lastmod only from real data)');
ok(count(array_filter($locs, fn($l) => strpos($l, '/solutions.php?problem=') !== false)) === count(solutions_indexable_slugs($tools)), 'sitemap lists exactly the evidence-gated problem pages');
ok(in_array('https://aihubpedia.com/recommend.php', $locs, true) && in_array('https://aihubpedia.com/changelog.php', $locs, true) && in_array('https://aihubpedia.com/pricing-comparison.php?group=meetings-transcription', $locs, true), 'new evidence-backed pages are in the sitemap');
ok(!in_array('https://aihubpedia.com/newsletter-archive.php', $locs, true), 'empty newsletter archive is not in the sitemap');
ok(count($locs) === count(array_unique($locs)), 'sitemap has no duplicate URLs');
foreach ($entries as $e) { if (!empty($e['lastmod']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $e['lastmod'])) { ok(false, 'bad lastmod ' . $e['loc']); } }
$cmp = array_filter($locs, fn($l) => strpos($l, '/compare.php?') !== false || preg_match('#/compare/#', $l));
foreach ($cmp as $l) { if (preg_match('/a=([^&]+)&b=([^&]+)/', $l, $m)) ok(!empty($byId[$m[1]]['curated']) && !empty($byId[$m[2]]['curated']), "sitemap comparison $m[1] vs $m[2] has two reviewed profiles"); }
$llms = (string)file_get_contents(__DIR__ . '/../llms.txt');
ok(strpos($llms, '/solutions.php') !== false && strpos($llms, '/recommend.php') !== false && strpos($llms, 'How to cite') !== false, 'llms.txt describes the new pages and how to cite evidence levels');
ok(preg_match('/(\d+) of (\d+) reviewed profiles have been verified by an editor/', $llms, $m) && (int)$m[1] === count(array_filter($byId, fn($t) => !empty($t['curated']['review']['human_verified']))), 'llms.txt states the true number of editor-verified profiles');
ok(preg_match('/directory of (\d+) AI tools/', $llms, $m) && (int)$m[1] === count($tools), 'llms.txt tool count matches the catalogue (regenerate with scripts/build-seo-files.php)');
$xml = (string)file_get_contents(__DIR__ . '/../sitemap.xml');
$jsonCount = (int)shell_exec('cd ' . escapeshellarg(dirname(__DIR__)) . ' && env -i PATH=' . escapeshellarg(getenv('PATH')) . ' AIHUB_CONFIG_FILE=/nonexistent php -r ' . escapeshellarg('require "sitemap_lib.php"; echo count(sitemap_entries());'));
ok($jsonCount > 500 && substr_count($xml, '<loc>') === $jsonCount, "committed sitemap.xml fallback is in sync with the generator in JSON mode ($jsonCount)");

section('P4 internal linking');
$rl = tool_related_links($byId['otter-ai'], $byId);
ok(count($rl) >= 4 && count(array_filter($rl, fn($x) => strpos($x['url'], '/solutions.php?problem=') === 0)) >= 1, 'reviewed tool pages link to the problem pages that list them');
foreach ($rl as $x) ok($x['why'] !== '' && $x['url'][0] === '/', 'related link has a reason and a local URL: ' . $x['url']);
foreach (array_filter($rl, fn($x) => strpos($x['url'], '/solutions.php?problem=') === 0) as $x) { parse_str((string)parse_url($x['url'], PHP_URL_QUERY), $q); ok(in_array('otter-ai', array_map(fn($r) => $r['tool']['id'], solution_results($tools, solution_get($q['problem']))), true), 'linked problem page really lists the tool: ' . $q['problem']); }
$plain = tool_related_links(['id' => 'x', 'name' => 'X'], $byId);
ok(count($plain) === 1 && $plain[0]['url'] === '/recommend.php', 'unreviewed tools get only the generic wizard link (no claims about relevance)');

section('4.1 derived-data cache: correctness and invalidation');
$cdir = sys_get_temp_dir() . '/aihub_cache_test_' . getmypid(); putenv('AIHUB_CACHE_DIR=' . $cdir); putenv('AIHUB_CACHE');
@mkdir($cdir, 0700, true);
$calls = 0; $compute = function () use (&$calls) { $calls++; return ['n' => $calls, 'text' => 'x']; };
[$v1, $st1] = cache_remember('unit', $compute, 900, 1000, 'catA', 'codeA');
[$v2, $st2] = cache_remember('unit', $compute, 900, 1100, 'catA', 'codeA');
ok($st1 === 'miss' && $st2 === 'hit' && $calls === 1 && $v2 === $v1, 'first call computes, second is served from the cache');
[$v3, $st3] = cache_remember('unit', $compute, 900, 1000 + 901, 'catA', 'codeA');
ok($st3 === 'miss' && $calls === 2, 'an entry older than the TTL is recomputed (click-popularity drift is bounded)');
[, $st4] = cache_remember('unit', $compute, 900, 2000, 'catB', 'codeA');
ok($st4 === 'miss', 'a different catalogue fingerprint never reuses another catalogue\'s entry');
[, $st5] = cache_remember('unit', $compute, 900, 2000, 'catB', 'codeB');
ok($st5 === 'miss', 'a different code fingerprint (new deployment) invalidates the entry');
[, $st6] = cache_remember('unit', $compute, 900, 2010, 'catB', 'codeB');
ok($st6 === 'hit', 'and the new entry is then reused');
foreach (glob("$cdir/unit-catB-codeB.json") as $f) file_put_contents($f, '{not json');
[, $st7] = cache_remember('unit', $compute, 900, 2020, 'catB', 'codeB');
ok($st7 === 'miss', 'a corrupted cache file is ignored and recomputed');
foreach (glob("$cdir/unit-catB-codeB.json") as $f) file_put_contents($f, json_encode(['schema' => 999, 'catalog' => 'catB', 'code' => 'codeB', 'at' => 2020, 'value' => ['evil' => 1]]));
[$vx, $st8] = cache_remember('unit', $compute, 900, 2030, 'catB', 'codeB');
ok($st8 === 'miss' && !isset($vx['evil']), 'an entry with a foreign schema version is never trusted');
foreach (glob("$cdir/unit-catB-codeB.json") as $f) file_put_contents($f, json_encode(['schema' => AIHUB_CACHE_SCHEMA, 'catalog' => 'catZ', 'code' => 'codeB', 'at' => 2030, 'value' => ['evil' => 1]]));
[$vy, $st9] = cache_remember('unit', $compute, 900, 2040, 'catB', 'codeB');
ok($st9 === 'miss' && !isset($vy['evil']), 'an entry whose embedded fingerprint disagrees with its file name is rejected');
ok(cache_remember('../evil', $compute, 900, 1, 'a', 'b')[1] === 'off', 'cache names are validated (no path tricks)');
putenv('AIHUB_CACHE=0'); ok(cache_remember('unit', $compute, 900, 2050, 'catB', 'codeB')[1] === 'off', 'AIHUB_CACHE=0 disables the cache'); putenv('AIHUB_CACHE');
$tmpd = sys_get_temp_dir() . '/aihub_unsafe_' . getmypid(); @mkdir($tmpd, 0777); @chmod($tmpd, 0777); putenv('AIHUB_CACHE_DIR=' . $tmpd);
$safe = sec_private_dir($tmpd); ok($safe === $tmpd && (fileperms($tmpd) & 0022) === 0, 'a group/world-writable cache directory is tightened to 0700 before use');
$link = sys_get_temp_dir() . '/aihub_link_' . getmypid(); @symlink($cdir, $link); ok(sec_private_dir($link) === '', 'a symlinked cache directory is refused (symlink tampering)');
putenv('AIHUB_CACHE_DIR=' . $cdir); @rmdir($tmpd); @unlink($link);
$ro = sys_get_temp_dir() . '/aihub_ro_' . getmypid(); @mkdir($ro, 0500); putenv('AIHUB_CACHE_DIR=' . $ro);
$called = 0; $r = cache_remember('unit', function () use (&$called) { $called++; return [1]; }, 900, 5, 'a', 'b');
ok($r[0] === [1] && $called === 1, 'an unwritable cache directory never breaks the page (computes live)'); putenv('AIHUB_CACHE_DIR=' . $cdir); @chmod($ro, 0700); @rmdir($ro);

$f1 = tempnam(sys_get_temp_dir(), 'fp'); $f2 = tempnam(sys_get_temp_dir(), 'fp'); file_put_contents($f1, '{"a":1}'); file_put_contents($f2, '{"b":2}');
$fpA = catalog_fingerprint([$f1, $f2], 'x'); ok($fpA === catalog_fingerprint([$f1, $f2], 'x'), 'fingerprint is stable for unchanged data');
file_put_contents($f1, '{"a":9}'); touch($f1, filemtime($f1)); ok(catalog_fingerprint([$f1, $f2], 'x') !== $fpA, 'changing file content with identical size and mtime still changes the fingerprint');
file_put_contents($f1, '{"a":1}'); $fpB = catalog_fingerprint([$f1, $f2], 'x'); touch($f1, time() + 100); ok(catalog_fingerprint([$f1, $f2], 'x') !== $fpB, 'touching a file changes the fingerprint');
ok(catalog_fingerprint([$f1, $f2], 'x') !== catalog_fingerprint([$f1, $f2], 'y'), 'a different database checksum changes the fingerprint');
@unlink($f2); ok(catalog_fingerprint([$f1, $f2], 'x') !== $fpB, 'a removed data file changes the fingerprint'); @unlink($f1);
ok(code_fingerprint() === code_fingerprint() && code_fingerprint([__FILE__]) !== code_fingerprint([__DIR__ . '/../discovery.php']), 'code fingerprint depends on the ranking code files');

section('4.1 cached results equal live results');
$tools = array_values($byId);
$sameRows = function (array $a, array $b) { return json_encode(array_map(fn($r) => [$r['tool']['id'], round($r['score'], 4), $r['reasons'], !empty($r['partial'])], $a)) === json_encode(array_map(fn($r) => [$r['tool']['id'], round($r['score'], 4), $r['reasons'], !empty($r['partial'])], $b)); };
cache_clear();
foreach (solutions_all() as $slug => $p) {
  $live = solution_results($tools, $p, SOLUTION_CACHE_ROWS);
  ok($sameRows($live, solution_results_cached($slug, SOLUTION_CACHE_ROWS)), "problem “{$slug}”: cached ranking, scores and reasons equal a live computation");
  ok($sameRows(array_slice($live, 0, SOLUTION_PAGE_LIMIT), solution_results_cached($slug, SOLUTION_PAGE_LIMIT)), "problem “{$slug}”: the page slice is the first 12 of the same ordering");
  ok(solution_indexable(array_slice($live, 0, SOLUTION_PAGE_LIMIT)) === solution_indexable(solution_results_cached($slug)), "problem “{$slug}”: the indexing decision is unchanged by caching");
}
ok(solutions_indexable_slugs() === array_values(array_filter(array_keys(solutions_all()), fn($sl) => solution_indexable(solution_results($tools, solution_get($sl), SOLUTION_PAGE_LIMIT)))), 'sitemap problem pages equal the live evidence gate');
$liveLinks = function (array $t) use ($tools, $byId) {
  $out = []; if (empty($t['curated'])) return [];
  foreach (solutions_all() as $slug => $p) { foreach (solution_results($tools, $p) as $r) { if ($r['tool']['id'] === $t['id']) { $out[] = $slug; break; } } if (count($out) >= 3) break; }
  return $out;
};
foreach ($byId as $id => $t) {
  if (empty($t['curated'])) continue;
  $cached = array_values(array_map(fn($x) => parse_url($x['url'], PHP_URL_QUERY), array_filter(tool_related_links($t, $byId), fn($x) => strpos($x['url'], '/solutions.php?') === 0)));
  $expected = array_map(fn($sl) => 'problem=' . rawurlencode($sl), $liveLinks($t));
  ok($cached === $expected, "related problem links for $id are identical with and without the cache");
}
ok(count(tool_related_links($byId['otter-ai'], $byId)) >= 4 && tool_related_links(['id' => 'raw', 'name' => 'Raw'], $byId) === tool_related_links(['id' => 'raw2', 'name' => 'Raw2'], $byId), 'unreviewed tools still get only the generic link');
[, $stT] = solution_pages_data(); ok(in_array($stT, ['hit', 'miss', 'off'], true), 'cache status is reported');
$t0 = hrtime(true); for ($i = 0; $i < 20; $i++) tool_related_links($byId['otter-ai'], $byId); $warm = (hrtime(true) - $t0) / 1e6 / 20;
ok($warm < 25, 'warm related-link lookup is fast (' . round($warm, 3) . ' ms, was ~170 ms before caching)');

$dbName2 = sec_setting('AIHUB_DB_NAME', 'db.name');
if (stripos($dbName2, 'test') !== false && db_connection_available() && mysql_tables_available_for_content()) {
  $pdo2 = db();
  $fpDb1 = catalog_fingerprint(); $row = $pdo2->query("SELECT slug, short_description FROM tools LIMIT 1")->fetch(PDO::FETCH_ASSOC);
  $pdo2->prepare("UPDATE tools SET short_description = ? WHERE slug = ?")->execute([($row['short_description'] ?? '') . ' (edited)', $row['slug']]);
  $fpDb2 = catalog_fingerprint();
  $pdo2->prepare("UPDATE tools SET short_description = ? WHERE slug = ?")->execute([$row['short_description'], $row['slug']]);
  ok($fpDb1 !== $fpDb2, 'database mode: editing a tool row changes the fingerprint, so the cache invalidates (a restored row may also invalidate: that error is on the safe side)');
}

section('4.1 synthetic catalogue: scaling and equivalence');
$syn = $tools; mt_srand(3);
for ($i = 0; $i < 1500; $i++) $syn[] = ['id' => 'syn-' . $i, 'name' => 'Syn ' . $i, 'category' => 'Productivity', 'subcategory' => 'Meetings & transcription', 'short' => 'AI transcription and meeting notes helper ' . $i, 'description' => 'synthetic', 'tags' => ['transcription'], 'features' => [], 'use_cases' => [], 'pricing' => 'Freemium', 'pricing_type' => 'Freemium'];
$t0 = hrtime(true); $a = search_tools_ranked($syn, 'transcription meeting', 24); $cold = (hrtime(true) - $t0) / 1e6;
$t0 = hrtime(true); $b = search_tools_ranked($syn, 'transcription meeting', 24); $warmS = (hrtime(true) - $t0) / 1e6;
ok(json_encode(array_map(fn($x) => [$x['tool']['id'], $x['reasons']], $a)) === json_encode(array_map(fn($x) => [$x['tool']['id'], $x['reasons']], $b)), 'memoised normalisation gives identical results on repeated queries over 2,000 tools');
ok($warmS < $cold * 1.2 + 5, 'repeating a query is not slower than the first (normalisation memo works): ' . round($cold, 1) . ' ms then ' . round($warmS, 1) . ' ms');
$mut = $syn; $mut[count($mut) - 1]['short'] = 'completely different words about video';
ok(count(array_filter(search_tools_ranked($mut, 'completely different words about video', 600), fn($x) => $x['tool']['id'] === 'syn-1499')) === 1, 'editing a tool\'s text is reflected immediately: the search memo is keyed by content, not by id alone');
$samples = ['pdf', 'chatgpt', 'perplexty', 'speech to text', 'write blog', 'zzqxv']; $eq = true;
foreach ($samples as $q) { $x = json_encode(search_tools_ranked($tools, $q, 10)); $y = json_encode(search_tools_ranked($tools, $q, 10)); if ($x !== $y) $eq = false; }
ok($eq, 'search results are deterministic across repeated calls');
cache_clear(); foreach (glob("$cdir/*") ?: [] as $f) @unlink($f); @rmdir($cdir); putenv('AIHUB_CACHE_DIR');

section('4.1 logos and third-party requests');
$layout = (string)file_get_contents(__DIR__ . '/../layout_top.php');
ok(strpos($layout, 'preconnect') === false && strpos($layout, 'dns-prefetch') === false, 'pages no longer open early connections to third-party hosts');
$lg = __DIR__ . '/../assets/logos'; @mkdir($lg, 0755, true); $f = $lg . '/zz-unit-test.png'; file_put_contents($f, 'x');
ok(tool_logo(['id' => 'zz-unit-test', 'website' => 'https://example.com']) === '/assets/logos/zz-unit-test.png', 'a locally hosted logo is preferred over the third-party favicon service');
@unlink($f); @rmdir($lg);
ok(strpos(tool_logo(['id' => 'nologo', 'website' => 'https://example.com']), 'google.com/s2/favicons') !== false, 'without a local file the favicon fallback still works');
ok(strpos(tool_logo(['id' => '../../etc/passwd', 'website' => 'https://example.com']), '/assets/logos/') === false, 'tool ids are sanitised before building a local logo path');
ok(strpos(render_tool_card($byId['otter-ai']), 'fetchpriority="low"') !== false && strpos(render_tool_card($byId['otter-ai']), 'loading="lazy"') !== false, 'card logos are lazy and low priority so they never compete with CSS and text');

// === P2–P5 sections are appended below ===

echo "\nPASSED: $pass  FAILED: $fail\n";
exit($fail ? 1 : 0);
