<?php
/**
 * Phase 3 logic tests: discovery/search, form tokens, leads, newsletter, event privacy rules, card rendering.
 * Usage: php tests/ux_logic_test.php   (database checks run only against a database whose name contains "test")
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../helpers.php';
$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "  FAIL: $m\n"; } }
function section(string $t): void { echo "== $t\n"; }

$data = load_tools(); $tools = $data['tools'];
$names = fn(array $r) => array_map(fn($x) => $x['tool']['name'], $r);

section('Search ranking');
ok(search_normalize('  Chat-GPT!! ') === 'chat gpt', 'normalize strips punctuation and case');
ok(search_tokens('a b') === [] && search_tokens('Write  a Blog') === ['write', 'blog'], 'tokens ignore 1-char words');
$r = search_tools_ranked($tools, 'chatgpt', 5);
ok(($names($r)[0] ?? '') === 'ChatGPT', 'exact name ranks first');
$r = search_tools_ranked($tools, 'chat', 10);
ok(count($r) >= 3 && stripos($names($r)[0], 'chat') === 0, 'name-prefix matches rank first');
ok(in_array('ChatGPT', $names($r), true), 'prefix query finds ChatGPT');
$r = search_tools_ranked($tools, 'transcription', 8);
ok(count(array_filter($r, fn($x) => !empty($x['tool']['curated']))) >= 3, 'reviewed transcription tools are found');
$first = $r[0]['tool'];
ok(!empty($first['curated']), 'a reviewed profile outranks unreviewed matches');
$r = search_tools_ranked($tools, 'perplexty', 3);
ok(($names($r)[0] ?? '') === 'Perplexity' && !empty($r[0]['fuzzy']), 'typo tolerance finds Perplexity from "perplexty"');
$r = search_tools_ranked($tools, 'write blog', 5);
ok(count($r) > 0 && !empty($r[0]['partial']), 'multi-word queries fall back to partial matches');
ok(search_tools_ranked($tools, 'xqzvwk', 5) === [], 'nonsense query returns nothing');
ok(search_tools_ranked($tools, '', 5) === [] && search_tools_ranked($tools, 'a', 5) === [], 'empty and 1-char queries return nothing');
$r = search_tools_ranked($tools, 'ai', 50, ['category' => 'Chatbots']);
ok($r && count(array_filter($r, fn($x) => $x['tool']['category'] !== 'Chatbots')) === 0, 'category filter is applied');
$r = search_tools_ranked($tools, 'chat', 50, ['pricing_type' => 'Freemium']);
ok(count(array_filter($r, fn($x) => strcasecmp($x['tool']['pricing_type'], 'Freemium') !== 0)) === 0, 'pricing filter is applied');
$xss = search_tools_ranked($tools, '<script>alert(1)</script>', 5);
ok(is_array($xss), 'markup in a query is handled safely');

section('Suggestions payload');
$p = search_suggest_payload($tools, 'meeting');
ok(isset($p['items']) && count($p['items']) > 0, 'suggestions returned for "meeting"');
$types = array_unique(array_column($p['items'], 'type'));
ok(in_array('tool', $types, true), 'suggestions include tools');
ok(in_array('category', $types, true) || in_array('guide', $types, true), 'suggestions include categories or guides');
foreach ($p['items'] as $it) ok(!empty($it['url']) && $it['url'][0] === '/' && !empty($it['label']), 'suggestion has a root-relative url and label');
$json = json_encode(search_suggest_payload($tools, '<img src=x onerror=1>'));
ok($json !== false, 'suggestions JSON-encode safely for hostile input');
ok(search_suggest_payload($tools, 'zzzzzz')['items'] === [], 'no suggestions for nonsense');

section('Goal entry points');
$intents = discovery_intents($tools);
ok(count($intents) >= 8, 'at least 8 goal entry points (' . count($intents) . ')');
foreach ($intents as $i) { ok($i['count'] > 0 && strpos($i['url'], '/category.php?name=') === 0, "intent {$i['label']} has tools and a category URL"); ok(isset(ui_icon_paths()[$i['icon']]), "intent {$i['label']} icon exists"); }
foreach (taxonomy_categories() as $c) { if ($c !== CATALOG_UNCLASSIFIED) ok(isset(ui_icon_paths()[ui_category_icon($c)]), "category $c has an icon"); }

section('Tool card rendering');
$chat = find_tool('chatgpt');
$html = render_tool_card($chat, ['placement' => 'test']);
ok(strpos($html, 'data-tool-id="chatgpt"') !== false && strpos($html, 'Reviewed') !== false, 'reviewed card carries id and badge');
ok(strpos($html, 'rel="nofollow noopener"') !== false && strpos($html, 'noopener sponsored') === false, 'organic outbound link is nofollow, not rel=sponsored');
ok(strpos($html, 'class="adLabel"') === false, 'organic card has no ad label');
$sp = render_tool_card($chat, ['sponsored' => true]);
ok(strpos($sp, 'Sponsored') !== false && strpos($sp, '/disclosure.php') !== false && strpos($sp, 'rel="nofollow noopener sponsored"') !== false, 'sponsored card is labelled, links to disclosure and uses rel=sponsored');
$aff = $chat; $aff['affiliate'] = 'https://example.test/ref?x=1';
$ah = render_tool_card($aff);
ok(is_affiliate_link($aff) && strpos($ah, 'Affiliate link') !== false && strpos($ah, 'rel="nofollow noopener sponsored"') !== false, 'affiliate link is disclosed and marked sponsored');
ok(!is_affiliate_link($chat), 'tools without affiliate URLs are not treated as affiliate links');
$evil = $chat; $evil['name'] = '<b>"x"</b>'; $evil['short'] = '<script>alert(1)</script>';
$eh = render_tool_card($evil);
ok(strpos($eh, '<script>') === false && strpos($eh, '&lt;script&gt;') !== false, 'card output is escaped');
ok(substr_count($html, '<button') === 2, 'card has exactly the favorite and compare buttons');

section('Built assets');
$build = json_decode((string)@file_get_contents(__DIR__ . '/../assets/build.json'), true)['sources'] ?? [];
foreach (['assets/css/style.css', 'assets/css/ui.css', 'assets/js/app.js', 'assets/js/interactions.js'] as $src) {
  ok(($build[$src] ?? '') === sha1_file(__DIR__ . '/../' . $src), "built assets are current for $src (run: php scripts/build_assets.php)");
}
foreach (['assets/css/site.min.css', 'assets/js/app.min.js', 'assets/js/interactions.min.js'] as $out) ok(is_file(__DIR__ . '/../' . $out) && filesize(__DIR__ . '/../' . $out) > 500, "$out exists");
$minCss = (string)file_get_contents(__DIR__ . '/../assets/css/site.min.css');
ok(strpos($minCss, '.tCard') !== false && strpos($minCss, '.siteHeader') !== false && strpos($minCss, '.reveal') !== false && substr_count($minCss, '{') === substr_count($minCss, '}'), 'merged CSS contains both stylesheets with balanced braces');

section('Form tokens (stateless CSRF + time trap)');
$now = 1800000000;
$tk = form_token('lead', $now - 10);
ok(form_token_valid($tk, 'lead', 2, $now), 'valid token accepted');
ok(!form_token_valid($tk, 'newsletter', 2, $now), 'token for another purpose rejected');
ok(!form_token_valid(form_token('lead', $now - 1), 'lead', 2, $now), 'instant submission rejected (time trap)');
ok(!form_token_valid(form_token('lead', $now - 90000), 'lead', 2, $now), 'expired token rejected');
ok(!form_token_valid(substr($tk, 0, -3) . 'abc', 'lead', 2, $now), 'tampered token rejected');
ok(!form_token_valid('', 'lead') && !form_token_valid('garbage', 'lead'), 'empty and malformed tokens rejected');
ok(strlen(conversion_ip_hash()) === 16, 'rate-limit hash is 16 hex chars');

section('Lead validation');
$good = ['lead_type' => 'consultation', 'name' => 'Ada Test', 'email' => 'ada@example.test', 'message' => 'We want an agent that triages support email.', 'consent' => '1', 'form_token' => form_token('lead', $now - 10), 'source_page' => '/business.php'];
$v = lead_validate($good, ['consultation', 'ai_agent'], $now);
ok($v['errors'] === [], 'valid lead passes' . ($v['errors'] ? ' ' . json_encode($v['errors']) : ''));
$cases = [
  'name' => ['name' => 'A'], 'email' => ['email' => 'not-an-email'], 'message' => ['message' => 'too short'], 'consent' => ['consent' => ''],
  'lead_type' => ['lead_type' => 'advertiser'], '_bot' => ['website_url_confirm' => 'http://spam.test'], '_token' => ['form_token' => 'bad'], 'website' => ['website' => 'http://no spaces allowed'],
];
foreach ($cases as $field => $override) { $r = lead_validate(array_merge($good, $override), ['consultation', 'ai_agent'], $now); ok(isset($r['errors'][$field]), "invalid $field is rejected"); }
$r = lead_validate(array_merge($good, ['website' => 'example.test', 'budget' => 'bogus', 'timeline' => 'whenever']), ['consultation'], $now);
ok($r['clean']['website'] === 'https://example.test' && $r['clean']['budget'] === '' && $r['clean']['timeline'] === '', 'website gets a scheme; unknown budget/timeline are dropped');
$r = lead_validate(array_merge($good, ['message' => str_repeat('x', 9000)]), ['consultation'], $now);
ok(mb_strlen($r['clean']['message']) === 4000, 'message length is capped');
$r = lead_validate(array_merge($good, ['source_page' => 'https://evil.test/x']), ['consultation'], $now);
ok($r['clean']['source_page'] === '', 'off-site source_page is discarded');
$formHtml = render_lead_form(['types' => ['consultation', 'ai_agent'], 'id' => 't', 'budget' => true, 'source' => '/business.php']);
ok(substr_count($formHtml, '<label') >= 7 && strpos($formHtml, 'name="consent"') !== false && strpos($formHtml, 'name="website_url_confirm"') !== false, 'lead form has labels, consent and honeypot');
$errHtml = render_lead_form(['types' => ['consultation'], 'id' => 't', 'errors' => ['email' => 'Bad <email>'], 'values' => ['email' => '"><script>x</script>']]);
ok(strpos($errHtml, '<script>') === false && strpos($errHtml, 'aria-invalid="true"') !== false, 'form re-render escapes values and sets aria-invalid');

section('Event privacy rules');
ok(event_normalize(['name' => 'search_submit', 'props' => ['source' => 'hero', 'results' => '6to20']]) === ['name' => 'search_submit', 'ref' => '', 'prop1' => 'hero', 'prop2' => '6to20', 'page_type' => ''], 'valid client event normalised');
ok(event_normalize(['name' => 'lead_submit']) === null, 'server-only events cannot be sent by the browser');
ok(event_normalize(['name' => 'newsletter_signup']) === null && event_normalize(['name' => 'newsletter_confirmed']) === null, 'newsletter events are server-only');
ok(event_normalize(['name' => 'drop_table']) === null, 'unknown event rejected');
$e = event_normalize(['name' => 'outbound_click', 'ref' => 'chatgpt', 'props' => ['affiliate' => 'no', 'placement' => 'card', 'email' => 'x@y.z']]);
ok($e['ref'] === 'chatgpt' && $e['prop1'] === 'no' && strpos(json_encode($e), 'x@y.z') === false, 'unlisted properties (e.g. an email) are dropped');
$e = event_normalize(['name' => 'outbound_click', 'ref' => 'a b@c.d', 'props' => ['affiliate' => str_repeat('x', 80)]]);
ok($e['ref'] === '' && $e['prop1'] === '', 'values with spaces/@ or excessive length are dropped');
ok(event_normalize(['name' => 'favorite_add', 'ref' => 'chatgpt'])['ref'] === 'chatgpt', 'tool id accepted as ref');
foreach (event_schema() as $n => $keys) ok(count($keys) <= 2, "event $n uses at most two properties");
ok(!analytics_enabled(), 'analytics (and therefore all events) are off unless AIHUB_ANALYTICS=1');

$db = (string)getenv('AIHUB_DB_NAME'); $reach = false;
if (strpos($db, 'test') !== false) { try { db()->query('SELECT 1'); $reach = true; } catch (Throwable $e) {} }
if ($reach) {
  section('Newsletter state machine (isolated test DB)');
  $pdo = db(); $mail = 'qa-unit-' . bin2hex(random_bytes(3)) . '@example.test'; $ip = 'unit' . bin2hex(random_bytes(4));
  ok(db_table_exists('subscribers') && db_table_exists('leads') && db_table_exists('events'), 'phase 3 tables exist');
  ok(newsletter_subscribe($pdo, 'nope', '/', $ip)['status'] === 'invalid', 'invalid email rejected');
  $r = newsletter_subscribe($pdo, strtoupper($mail), '/', $ip);
  ok($r['status'] === 'created' && newsletter_valid_token($r['token']), 'new subscription is created pending with a token');
  $row = $pdo->query('SELECT status, consent_text, email FROM subscribers WHERE token = ' . $pdo->quote($r['token']))->fetch();
  ok($row['status'] === 'pending' && $row['email'] === $mail && strlen($row['consent_text']) > 20, 'stored pending, lower-cased, with the consent text');
  ok(newsletter_subscribe($pdo, $mail, '/', $ip)['status'] === 'throttled', 'immediate re-subscribe is throttled (no email bombing)');
  ok(!newsletter_confirm($pdo, 'not-a-token') && !newsletter_confirm($pdo, str_repeat('0', 32)), 'invalid or unknown tokens do not confirm');
  ok(newsletter_confirm($pdo, $r['token']), 'valid token confirms');
  ok($pdo->query('SELECT status FROM subscribers WHERE token = ' . $pdo->quote($r['token']))->fetchColumn() === 'confirmed', 'status is confirmed');
  ok(newsletter_subscribe($pdo, $mail, '/', $ip)['status'] === 'already_confirmed', 'confirmed subscriber re-subscribing is a no-op');
  ok(newsletter_unsubscribe($pdo, $r['token']) && $pdo->query('SELECT status FROM subscribers WHERE token = ' . $pdo->quote($r['token']))->fetchColumn() === 'unsubscribed', 'unsubscribe works');
  ok(!newsletter_unsubscribe($pdo, $r['token']), 'unsubscribing twice reports nothing to do');
  for ($i = 0; $i < 5; $i++) newsletter_subscribe($pdo, "rl$i-" . $mail, '/', $ip . 'rl');
  ok(newsletter_subscribe($pdo, "rl9-$mail", '/', $ip . 'rl')['status'] === 'rate_limited', 'per-network rate limit applies after 5 signups an hour');
  $pdo->exec("DELETE FROM subscribers WHERE email LIKE '%qa-unit-%@example.test'");

  section('Lead storage and events (isolated test DB)');
  $c = $v['clean']; $c['email'] = 'lead-unit@example.test';
  $id = lead_store($pdo, $c, 'unitleadhash');
  ok($id > 0, 'lead stored');
  $row = $pdo->query("SELECT lead_type, consent_at, status, ip_hash FROM leads WHERE id = $id")->fetch();
  ok($row['lead_type'] === 'consultation' && $row['status'] === 'new' && $row['consent_at'] !== null && $row['ip_hash'] === 'unitleadhash', 'lead has type, new status, consent timestamp and only a hash');
  $cols = array_column($pdo->query('SHOW COLUMNS FROM leads')->fetchAll(), 'Field');
  ok(!array_intersect($cols, ['ip', 'ip_address', 'user_agent']), 'leads table stores no raw IP or user agent');
  $cols = array_column($pdo->query('SHOW COLUMNS FROM events')->fetchAll(), 'Field');
  ok(!array_intersect($cols, ['ip', 'ip_address', 'user_agent', 'email']), 'events table stores no IP, user agent or email');
  for ($i = 0; $i < 5; $i++) lead_store($pdo, $c, 'ratelimit-unit');
  ok(lead_rate_limited($pdo, 'leads', 'ratelimit-unit', 5) && !lead_rate_limited($pdo, 'leads', 'other-hash', 5), 'lead rate limit is per network hash');
  $pdo->exec("DELETE FROM leads WHERE email = 'lead-unit@example.test'");
  event_insert($pdo, ['name' => 'favorite_add', 'ref' => 'unit-test', 'prop1' => '', 'prop2' => '', 'page_type' => 'home'], '', false);
  ok((int)$pdo->query("SELECT COUNT(*) FROM events WHERE ref = 'unit-test'")->fetchColumn() === 1, 'event inserted');
  $pdo->exec("DELETE FROM events WHERE ref = 'unit-test'");
  ok((int)$pdo->query("SELECT COUNT(*) FROM events")->fetchColumn() >= 0, 'events table queryable');
} else { echo "== (database checks skipped: not an isolated test database)\n"; }

echo "\nPASSED: $pass  FAILED: $fail\n";
exit($fail ? 1 : 0);
