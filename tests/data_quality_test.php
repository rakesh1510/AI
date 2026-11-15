<?php
/**
 * Data-quality, classifier and logic tests (no web server needed).
 * Usage: php tests/data_quality_test.php
 * DB checks run only when AIHUB_DB_NAME contains "test" (isolated database); otherwise they are skipped.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../sitemap_lib.php';
require_once __DIR__ . '/../scripts/indexnow_lib.php';

$pass = 0; $fail = 0;
function ok(bool $c, string $msg): void { global $pass, $fail; if ($c) { $pass++; } else { $fail++; echo "  FAIL: $msg\n"; } }
function section(string $t): void { echo "== $t\n"; }

$data = load_tools();
$tools = $data['tools']; $byId = []; foreach ($tools as $t) $byId[$t['id']] = $t;
$raw = json_decode(file_get_contents(__DIR__ . '/../data/tools.json'), true)['tools'];
$rawById = []; foreach ($raw as $t) $rawById[$t['id']] = $t;

section('Taxonomy');
$tax = taxonomy()['categories'];
ok(count($tax) >= 12, 'taxonomy has at least 12 categories');
foreach ($tax as $cat => $def) {
  ok(trim((string)($def['description'] ?? '')) !== '', "$cat has a description");
  ok(!empty($def['subcategories']), "$cat has subcategories");
}
ok(isset($tax[CATALOG_UNCLASSIFIED]), 'Other bucket exists');
foreach (taxonomy()['legacy_redirects'] as $old => $new) ok(isset($tax[$new]) && !isset($tax[$old]), "legacy redirect $old → $new is valid");
ok(taxonomy_categories()[count(taxonomy_categories()) - 1] === CATALOG_UNCLASSIFIED, 'Other sorts last');
ok(subcategory_resolve('Productivity', 'meetings-transcription') === 'Meetings & transcription', 'subcategory slug resolves');
ok(subcategory_resolve('Productivity', 'nonsense') === null, 'unknown subcategory resolves to null');

section('Classification coverage');
$cls = classification_overrides();
ok(count($cls) === count($raw), 'every raw tool has a classification entry (' . count($cls) . '/' . count($raw) . ')');
foreach ($cls as $id => $c) {
  if (!isset($rawById[$id])) { ok(false, "classification for unknown tool $id"); continue; }
  $good = isset($tax[$c['category']]) && ($c['subcategory'] === '' || in_array($c['subcategory'], taxonomy_subcategories($c['category']), true));
  if (!$good) ok(false, "$id has invalid category/subcategory {$c['category']} / {$c['subcategory']}");
}
ok(true, 'classification entries valid');
$otherShare = count(array_filter($cls, fn($c) => $c['category'] === CATALOG_UNCLASSIFIED)) / max(1, count($cls));
ok($otherShare < 0.45, sprintf('unclassified share %.0f%% is below 45%%', $otherShare * 100));
$stillAuto = count(array_filter($rawById, fn($t) => $t['category'] === 'Automation'));
$nowAuto = count(array_filter($byId, fn($t) => $t['category'] === 'Automation'));
ok($nowAuto < $stillAuto * 0.2, "Automation bucket shrank from $stillAuto to $nowAuto tools");

section('Classifier accuracy on hand-labelled gold set');
$gold = json_decode(file_get_contents(__DIR__ . '/fixtures/classifier_gold.json'), true)['labels'];
$tot = $hi = $hiOk = $med = $medOk = $allOk = 0;
foreach ($gold as $id => $accept) {
  $c = classify_tool($rawById[$id]);
  $final = in_array($c['confidence'], ['high', 'medium'], true) ? $c['category'] : CATALOG_UNCLASSIFIED;
  $good = in_array($final, $accept, true);
  $tot++; if ($good) $allOk++;
  if ($c['confidence'] === 'high') { $hi++; if ($good) $hiOk++; }
  if ($c['confidence'] === 'medium') { $med++; if ($in = in_array($c['category'], $accept, true)) $medOk++; }
}
printf("  high-confidence precision %d/%d, medium precision %d/%d, overall %d/%d\n", $hiOk, $hi, $medOk, $med, $allOk, $tot);
ok($hi >= 15 && $hiOk / $hi >= 0.95, 'high-confidence precision >= 95%');
ok($med >= 10 && $medOk / $med >= 0.80, 'medium-confidence precision >= 80%');
ok($allOk / $tot >= 0.65, 'overall gold accuracy >= 65%');
$c1 = classify_text('Resume Worded', 'Boost your resume and LinkedIn with AI feedback');
ok($c1['category'] === 'Business' && $c1['subcategory'] === 'HR & careers', 'classifier: resume tool → Business / HR & careers');
$c2 = classify_text('Zoom AI Companion', 'Streamline meetings with AI summaries, chat assistance, and more');
ok($c2['category'] === 'Productivity', 'classifier: "companion" in a meeting tool does not become a chat companion');
ok(classify_text('Mystery', 'Does things')['category'] === CATALOG_UNCLASSIFIED, 'classifier: no signal → Other');

section('Curated profiles');
$profiles = curated_profiles();
ok(count($profiles) >= 20, 'at least 20 curated profiles (' . count($profiles) . ')');
$allIds = array_keys($rawById);
foreach ($profiles as $id => $p) {
  $errs = curated_profile_errors($id, $p, $allIds);
  ok(!$errs && isset($rawById[$id]), "profile $id valid" . ($errs ? ': ' . implode('; ', $errs) : ''));
  ok(!empty($byId[$id]['curated']) && tool_quality_tier($byId[$id]) === 'reviewed', "$id loads as a reviewed tool");
  ok(tool_seo_title($byId[$id]) !== '' && mb_strlen(tool_seo_title($byId[$id])) <= 70, "$id title length ok");
  ok(strpos(tool_seo_description($byId[$id]), 'See features, pricing, tags, and alternatives') === false, "$id description is not boilerplate");
}
ok(!array_filter($profiles, fn($p) => !empty($p['review']['human_verified'])), 'no profile is marked human-verified without an editor (none were verified in this phase)');
ok(curated_profile_errors('x', ['category' => 'Chatbots'], ['x']) !== [], 'validator rejects an incomplete profile');
$bad = $profiles['chatgpt']; $bad['sources'][0]['url'] = 'http://insecure.example';
ok(in_array('sources[0].url must be https', curated_profile_errors('chatgpt', $bad, $allIds), true), 'validator rejects non-https sources');
$bad = $profiles['chatgpt']; $bad['review']['human_verified'] = true; $bad['review']['verified_on'] = null;
ok(curated_profile_errors('chatgpt', $bad, $allIds) !== [], 'validator rejects verified without date');
ok(profile_age_days($profiles['chatgpt'], strtotime('2026-12-15')) === 30, 'profile age calculation');
ok(profile_review_label($profiles['chatgpt']) !== '' && strpos(profile_review_label($profiles['chatgpt']), 'pending editor verification') !== false, 'review label states pending verification');

section('Overlay regressions');
ok(isset($byId['notebooklm']), 'NotebookLM is present (was hidden by the "Research" exclusion before Phase 2)');
ok(($byId['perplexity']['category'] ?? '') === 'Research' && ($byId['perplexity']['subcategory'] ?? '') === 'AI search & answers', 'Perplexity moved from SEO to Research / AI search');
ok(($byId['deepseek']['category'] ?? '') === 'Chatbots', 'DeepSeek moved from Coding to Chatbots');
ok(($byId['chatpdf']['category'] ?? '') === 'Productivity', 'ChatPDF moved from Audio to Productivity');
ok(($byId['motion']['category'] ?? '') === 'Productivity', 'Motion moved from Video to Productivity');
ok(count($byId) === count($tools), 'tool ids are unique');
ok(array_search(CATALOG_UNCLASSIFIED, $data['categories'], true) === count($data['categories']) - 1, 'categories list ends with Other');
foreach ($tools as $t) { if (!isset($tax[$t['category']])) { ok(false, "tool {$t['id']} has category outside taxonomy: {$t['category']}"); break; } }
ok(true, 'all loaded tools use taxonomy categories');
ok(find_tool('chatgpt')['category'] === 'Chatbots' && !empty(find_tool('chatgpt')['curated']), 'find_tool returns overlaid records');
ok(thin_tool_policy() === 'index', 'thin-content policy defaults to index (Phase 1 behaviour preserved)');

section('Guides and alternatives');
foreach (guides_all() as $slug => $g) {
  ok(count($g['tools']) >= 3, "$slug compares at least 3 tools");
  foreach ($g['tools'] as $tid) ok(!empty($byId[$tid]['curated']), "$slug: $tid has a curated profile");
  foreach ($g['picks'] as $pick) ok(in_array($pick['tool'], $g['tools'], true), "$slug: pick {$pick['tool']} is in the compared set");
  ok(count($g['criteria']) >= 3 && count($g['faq']) >= 2 && count($g['checklist']) >= 3, "$slug has criteria, FAQ and checklist");
  ok(preg_match('/^\d{4}-\d{2}-\d{2}$/', $g['updated']) && $g['updated'] >= $g['published'], "$slug dates valid");
  ok(mb_strlen(seo_title($g['title'])) <= 70 && mb_strlen($g['description']) <= 200, "$slug title/description lengths");
  ok(stripos(json_encode($g), 'guarantee') === false && stripos(json_encode($g), 'best overall') === false, "$slug avoids unsupported superlatives");
}
$elig = alternatives_eligible($byId);
ok(count($elig) >= 10, 'at least 10 alternatives pages are eligible (' . count($elig) . ')');
foreach ($elig as $eid) ok(count(curated_alternatives($byId[$eid], $byId, 8)) >= 3, "$eid has >= 3 curated alternatives");
ok(!in_array('grammarly', $elig, true), 'tools without enough reviewed alternatives get no page');

section('Sitemap invariants');
$entries = sitemap_entries(); $locs = array_column($entries, 'loc');
ok(count($locs) === count(array_unique($locs)), 'sitemap URLs are unique');
ok(!array_filter($locs, fn($l) => strpos($l, 'name=Other') !== false), 'catch-all Other category is not in the sitemap');
ok(count(array_filter($locs, fn($l) => strpos($l, '/guide.php?slug=') !== false)) === count(guides_all()), 'every guide is in the sitemap');
ok(count(array_filter($locs, fn($l) => strpos($l, '/alternatives.php?tool=') !== false)) === count($elig), 'every eligible alternatives page is in the sitemap');
ok((bool)array_filter($locs, fn($l) => strpos($l, '&sub=') !== false), 'subcategory landing pages are in the sitemap');
ok(in_array(site_url('/methodology.php'), $locs, true), 'methodology page is in the sitemap');

section('Analytics classification');
$agents = [
  'Mozilla/5.0 (Windows NT 10.0) Chrome/120 Safari/537.36' => [false, ''],
  'Mozilla/5.0 (iPhone; CPU iPhone OS 17) Safari/604.1' => [false, ''],
  'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.1; +https://openai.com/gptbot)' => [true, 'ai_crawler'],
  'Mozilla/5.0 (compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot)' => [true, 'ai_crawler'],
  'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)' => [true, 'ai_crawler'],
  'Mozilla/5.0 (compatible; Claude-SearchBot/1.0)' => [true, 'ai_crawler'],
  'Mozilla/5.0 (compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)' => [true, 'ai_crawler'],
  'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' => [true, 'search_crawler'],
  'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)' => [true, 'search_crawler'],
  'Mozilla/5.0 (compatible; AhrefsBot/7.0)' => [true, 'seo_tool'],
  'facebookexternalhit/1.1' => [true, 'social_preview'],
  'curl/8.5.0' => [true, 'other_bot'],
  'python-requests/2.31' => [true, 'other_bot'],
  'Mozilla/5.0 HeadlessChrome/120' => [true, 'other_bot'],
  '' => [true, 'other_bot'],
];
foreach ($agents as $ua => [$isBot, $type]) { $r = analytics_classify_agent($ua); ok($r['is_bot'] === $isBot && $r['type'] === $type, "agent '" . substr($ua, 0, 50) . "' → " . ($isBot ? $type : 'human') . " (got " . ($r['is_bot'] ? $r['type'] : 'human') . ')'); }
$refs = [
  ['https://www.google.com/', '', 'search_google'], ['https://www.google.de/search?q=x', '', 'search_google'], ['https://www.bing.com/', '', 'search_bing'],
  ['https://duckduckgo.com/', '', 'search_duckduckgo'], ['https://search.brave.com/', '', 'search_brave'], ['https://search.yahoo.com/', '', 'search_yahoo'],
  ['https://chatgpt.com/', '', 'ai_chatgpt'], ['https://chat.openai.com/', '', 'ai_chatgpt'], ['https://www.perplexity.ai/', '', 'ai_perplexity'],
  ['https://claude.ai/', '', 'ai_claude'], ['https://gemini.google.com/', '', 'ai_gemini'], ['https://copilot.microsoft.com/', '', 'ai_copilot'],
  ['', 'chatgpt.com', 'ai_chatgpt'], ['', 'perplexity', 'ai_perplexity'], ['', '', 'direct'], ['https://aihubpedia.com/x', '', 'internal'],
  ['https://www.reddit.com/r/x', '', 'social'], ['https://news.ycombinator.com/', '', 'social'], ['https://example.org/', '', 'other'],
];
foreach ($refs as [$ref, $utm, $want]) ok(analytics_classify_referrer($ref, 'aihubpedia.com', $utm)['class'] === $want, "referrer '$ref' utm '$utm' → $want");
$h1 = analytics_visitor_hash('1.2.3.4', 'UA', '2026-11-15'); $h2 = analytics_visitor_hash('1.2.3.4', 'UA', '2026-11-16'); $h3 = analytics_visitor_hash('1.2.3.5', 'UA', '2026-11-15');
ok(strlen($h1) === 16 && $h1 !== $h2 && $h1 !== $h3 && $h1 === analytics_visitor_hash('1.2.3.4', 'UA', '2026-11-15'), 'visitor hash is stable within a period, rotates across periods and differs per visitor');
ok(strpos($h1, '1.2.3.4') === false, 'visitor hash does not contain the IP');
$base = ['HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/120', 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_HOST' => 'aihubpedia.com', 'SCRIPT_NAME' => '/tool.php', 'HTTP_REFERER' => 'https://www.google.com/'];
$row = analytics_build_row($base, [], 200, strtotime('2026-11-15 12:00:00'), '/tool.php?id=claude');
ok($row['is_bot'] === 0 && $row['visitor_hash'] !== '' && $row['referrer_class'] === 'search_google' && $row['page_type'] === 'tool' && $row['country'] === 'XX', 'human row built correctly');
ok(!in_array('203.0.113.9', $row, true) && strpos(json_encode($row), '203.0.113.9') === false, 'stored row contains no IP address');
ok(strpos(json_encode($row), 'Chrome') === false, 'stored row contains no user-agent string');
$dnt = analytics_build_row($base + ['HTTP_DNT' => '1'], [], 200, time(), '/');
ok($dnt['visitor_hash'] === '' && $dnt['is_bot'] === 0, 'Do-Not-Track: counted as a view but gets no visitor hash');
$gpc = analytics_build_row($base + ['HTTP_SEC_GPC' => '1'], [], 200, time(), '/');
ok($gpc['visitor_hash'] === '', 'Global Privacy Control honoured');
$bot = analytics_build_row(['HTTP_USER_AGENT' => 'GPTBot/1.1', 'REMOTE_ADDR' => '1.1.1.1', 'SCRIPT_NAME' => '/index.php'], [], 200, time(), '/');
ok($bot['is_bot'] === 1 && $bot['bot_type'] === 'ai_crawler' && $bot['visitor_hash'] === '', 'bot row has no visitor hash');
ok(analytics_build_row($base + ['HTTP_CF_IPCOUNTRY' => 'de'], [], 200, time(), '/')['country'] === 'DE', 'country header normalised');
ok(analytics_build_row($base + ['HTTP_CF_IPCOUNTRY' => 'T1'], [], 200, time(), '/')['country'] === 'XX', 'invalid country code → XX');
ok(!analytics_enabled(), 'analytics is disabled unless AIHUB_ANALYTICS=1');

section('Website check classification');
$wc = [
  [200, 'https://a.com', 'https://a.com/', 'ok'], [200, 'https://a.com', 'https://www.a.com/home', 'redirected'], [301, 'https://a.com', 'https://b.com/', 'moved_domain'],
  [404, 'https://a.com', 'https://a.com', 'dead'], [410, 'https://a.com', 'https://a.com', 'dead'], [503, 'https://a.com', 'https://a.com', 'dead'],
  [403, 'https://a.com', 'https://a.com', 'blocked'], [429, 'https://a.com', 'https://a.com', 'blocked'], [0, 'https://a.com', '', 'error'],
];
foreach ($wc as [$code, $o, $f, $want]) ok(website_check_classify($code, $o, $f)['status'] === $want, "website check HTTP $code $o → $f is $want");
ok(strpos(website_check_classify(0, 'https://a.com', '', 'Could not resolve host')['note'], 'resolve') !== false, 'website check keeps the network error message');

section('IndexNow helpers');
ok(indexnow_valid_key('a1b2c3d4e5f6a7b8') && !indexnow_valid_key('short') && !indexnow_valid_key('has space in key!!'), 'key validation');
$pl = indexnow_payload('aihubpedia.com', 'a1b2c3d4e5f6a7b8', ['https://aihubpedia.com/a', 'https://aihubpedia.com/a', 'https://other.example/b']);
ok($pl['urlList'] === ['https://aihubpedia.com/a'] && $pl['keyLocation'] === 'https://aihubpedia.com/a1b2c3d4e5f6a7b8.txt', 'payload de-duplicates and keeps only own-host URLs');
ok(strlen(indexnow_generate_key()) === 32, 'generated key length');

$db = (string)getenv('AIHUB_DB_NAME');
$dbReachable = false;
if (strpos($db, 'test') !== false) { try { db()->query('SELECT 1'); $dbReachable = true; } catch (Throwable $e) { $dbReachable = false; } }
if ($dbReachable) {
  section('Analytics database round-trip (isolated test DB)');
  try {
    $pdo = db();
    ok(db_table_exists('page_views'), 'page_views table exists');
    $pdo->exec("DELETE FROM page_views WHERE path = '/__dq_test__'");
    analytics_insert($pdo, analytics_build_row($base, [], 200, time(), '/__dq_test__'));
    analytics_insert($pdo, analytics_build_row(['HTTP_USER_AGENT' => 'ClaudeBot/1.0', 'SCRIPT_NAME' => '/index.php'], [], 200, time(), '/__dq_test__'));
    $r = $pdo->query("SELECT SUM(is_bot=0) humans, SUM(is_bot=1) bots, MAX(bot_name) bn FROM page_views WHERE path='/__dq_test__'")->fetch();
    ok((int)$r['humans'] === 1 && (int)$r['bots'] === 1 && $r['bn'] === 'ClaudeBot', 'rows stored and separable into humans and bots');
    $pdo->exec("DELETE FROM page_views WHERE path = '/__dq_test__'");
  } catch (Throwable $e) { ok(false, 'database round-trip: ' . $e->getMessage()); }
} else {
  echo "== (database round-trip skipped: not an isolated test database)\n";
}

echo "\nPASSED: $pass  FAILED: $fail\n";
exit($fail ? 1 : 0);
