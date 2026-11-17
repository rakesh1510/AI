<?php
/**
 * Phase 4 security regression tests against a RUNNING instance (isolated test server only).
 * Usage: AIHUB_RATE_DIR=/tmp/x AIHUB_TEST_ADMIN_PW=... php tests/security_http_test.php http://127.0.0.1:8002
 * The server must have been started with the same AIHUB_RATE_DIR and AIHUB_ADMIN_PASS_HASH = password_hash(AIHUB_TEST_ADMIN_PW).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8002', '/');
$adminPw = getenv('AIHUB_TEST_ADMIN_PW') ?: 'ci-admin-test-pw';
$rateDir = getenv('AIHUB_RATE_DIR') ?: '';
$host = parse_url($base, PHP_URL_HOST) . ':' . parse_url($base, PHP_URL_PORT);
$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "  FAIL: $m\n"; } }
function section(string $t): void { echo "== $t\n"; }

function http(string $method, string $url, array $post = [], array $headers = [], ?string $cookieJar = null): array {
  $ch = curl_init($url);
  $h = $headers;
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30,
    CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h]);
  if ($method === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
  if ($cookieJar) { curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar); curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar); }
  $raw = (string)curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  curl_close($ch);
  $head = substr($raw, 0, $hs);
  return ['code' => $code, 'head' => $head, 'body' => substr($raw, $hs), 'loc' => preg_match('/^Location:\s*(.+)$/mi', $head, $m) ? trim($m[1]) : ''];
}
function meta_token(string $html): string { return preg_match('/<meta name="csrf-token" content="([^"]+)"/', $html, $m) ? html_entity_decode($m[1]) : ''; }
function field_token(string $html, string $nearAction): string {
  $i = strpos($html, $nearAction); if ($i === false) return '';
  return preg_match('/name="_csrf" value="([^"]+)"/', substr($html, $i, 1500), $m) ? html_entity_decode($m[1]) : '';
}
function votes_text(string $html): string { return preg_match('/Likes:\s*\d+\s*•\s*Dislikes:\s*\d+/u', $html, $m) ? $m[0] : ''; }

$tool = http('GET', "$base/tool.php?id=chatgpt");
$tok = meta_token($tool['body']);
$own = ['Origin: http://' . $host];

section('Baseline headers');
ok(stripos($tool['head'], 'X-Frame-Options: SAMEORIGIN') !== false, 'X-Frame-Options set on pages');
ok(stripos($tool['head'], 'Permissions-Policy:') !== false, 'Permissions-Policy set on pages');
ok($tok !== '', 'CSRF meta token present in page head');

section('State-changing GET requests are inert');
$before = votes_text($tool['body']);
$r = http('GET', "$base/vote-tool.php?id=chatgpt&type=up&return=https://evil.example");
ok($r['code'] === 303 && strpos($r['loc'], 'evil') === false && strpos($r['loc'], '/tool.php?id=chatgpt') === 0, 'vote-tool.php GET redirects locally: ' . $r['loc']);
$after = votes_text(http('GET', "$base/tool.php?id=chatgpt")['body']);
ok($before !== '' && $before === $after, "GET vote link changed nothing ($before -> $after)");
foreach (['/rate.php', '/vote-tool-ajax.php', '/track-click.php', '/submit-comment.php', '/toggle-bookmark.php'] as $p) {
  $r = http('GET', $base . $p . '?id=chatgpt&vote=up');
  ok(in_array($r['code'], [405, 303], true), "GET $p does not perform an action (HTTP {$r['code']})");
}

section('CSRF and origin checks');
$r = http('POST', "$base/vote-tool-ajax.php", ['id' => 'chatgpt', 'type' => 'up'], $own);
ok($r['code'] === 403, 'vote without token rejected (' . $r['code'] . ')');
$r = http('POST', "$base/vote-tool-ajax.php", ['id' => 'chatgpt', 'type' => 'up'], ['X-CSRF-Token: ' . $tok, 'Origin: https://evil.example']);
ok($r['code'] === 403, 'vote with valid token but foreign Origin rejected (' . $r['code'] . ')');
$r = http('POST', "$base/vote-tool-ajax.php", ['id' => 'chatgpt', 'type' => 'up'], ['X-CSRF-Token: ' . substr($tok, 0, -1) . '0', 'Origin: http://' . $host]);
ok($r['code'] === 403, 'vote with tampered token rejected');
$r = http('POST', "$base/vote-tool-ajax.php", ['id' => 'chatgpt', 'type' => 'up'], ['X-CSRF-Token: ' . $tok, 'Origin: http://' . $host]);
ok($r['code'] === 200 && (json_decode($r['body'], true)['likes'] ?? -1) >= 0, 'vote with token and same origin accepted');
$r = http('POST', "$base/vote-tool-ajax.php", ['id' => 'no-such-tool-xyz', 'type' => 'up'], ['X-CSRF-Token: ' . $tok, 'Origin: http://' . $host]);
ok($r['code'] === 422, 'unknown tool id rejected (' . $r['code'] . ')');
$r = http('POST', "$base/track-click.php", ['id' => 'chatgpt'], $own);
ok($r['code'] === 403, 'click tracking without token rejected');
$r = http('POST', "$base/track-click.php", ['id' => 'chatgpt'], ['X-CSRF-Token: ' . $tok, 'Origin: http://' . $host]);
ok($r['code'] === 200, 'click tracking with token accepted');
$rateTok = field_token($tool['body'], 'action="/rate.php"');
$r = http('POST', "$base/rate.php", ['id' => 'chatgpt', 'stars' => 5], $own);
ok($r['code'] === 403 && stripos($r['body'], 'Fatal') === false && stripos($r['body'], 'Stack trace') === false, 'rating without token rejected, no error details');
$r = http('POST', "$base/rate.php", ['id' => 'chatgpt', 'stars' => 4, '_csrf' => $rateTok, 'context' => 'https://evil.example'], $own);
ok($r['code'] === 303 && strpos($r['loc'], 'evil') === false, 'rating accepted and redirect stays local: ' . $r['loc']);
$r = http('POST', "$base/rate.php", ['id' => 'chatgpt', 'vote' => 'up', '_csrf' => $rateTok, 'context' => '//evil.example/x'], $own);
ok(in_array($r['code'], [303], true) && strpos($r['loc'], 'evil') === false, 'protocol-relative context neutralised: ' . $r['loc']);
$r = http('POST', "$base/rate.php", ['id' => 'chatgpt', 'stars' => 4, '_csrf' => $rateTok], ['Origin: https://evil.example']);
ok($r['code'] === 403, 'cross-origin form post rejected even with a valid token');

section('Comments: validation, honeypot, rate limit, spoofed forwarding headers');
$ctok = field_token($tool['body'], 'action="/submit-comment.php"');
$r = http('POST', "$base/submit-comment.php", ['tool_id' => 'chatgpt', 'name' => 'x', 'comment' => 'no token'], $own);
ok($r['code'] === 403, 'comment without token rejected');
$r = http('POST', "$base/submit-comment.php", ['tool_id' => 'chatgpt', 'name' => 'Bot', 'comment' => 'honeypot filled', '_csrf' => $ctok, 'website_url_confirm' => 'http://spam.example'], $own);
ok($r['code'] === 303 && strpos(http('GET', "$base/tool.php?id=chatgpt")['body'], 'honeypot filled') === false, 'honeypot submissions are dropped');
$xss = '<script>alert(1)</script>';
$r = http('POST', "$base/submit-comment.php", ['tool_id' => 'chatgpt', 'name' => $xss, 'comment' => 'Security test ' . $xss . ' ' . uniqid(), '_csrf' => $ctok], $own);
$page = http('GET', "$base/tool.php?id=chatgpt")['body'];
ok($r['code'] === 303 && strpos($page, $xss) === false && strpos($page, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false, 'stored comment is output-escaped (no raw <script>)');
$r = http('POST', "$base/submit-comment.php", ['tool_id' => 'does-not-exist', 'name' => 'x', 'comment' => 'unknown tool comment', '_csrf' => $ctok], $own);
ok($r['code'] === 303 && strpos($r['loc'], 'does-not-exist') !== false, 'unknown tool: nothing stored');

if ($rateDir !== '' && is_dir($rateDir)) {
  foreach (glob($rateDir . '/*') ?: [] as $f) @unlink($f);
  $codes = [];
  for ($i = 0; $i < 9; $i++) {
    $r = http('POST', "$base/submit-comment.php", ['tool_id' => 'chatgpt', 'name' => 'RL', 'comment' => "rate limit probe $i " . uniqid(), '_csrf' => $ctok], $own + ['X-Forwarded-For: 198.51.100.' . (10 + $i), 'CF-Connecting-IP: 203.0.113.' . (10 + $i), 'X-Real-IP: 192.0.2.' . (10 + $i)]);
    $codes[] = $r['code'];
  }
  ok(count(array_filter($codes, fn($c) => $c === 429)) >= 3 && $codes[0] === 303, 'rate limit applies even when X-Forwarded-For / CF-Connecting-IP / X-Real-IP are rotated: ' . implode(',', $codes));
  foreach (glob($rateDir . '/*') ?: [] as $f) @unlink($f);
} else {
  ok(false, 'AIHUB_RATE_DIR must point at the server rate directory for the rate-limit tests');
}

section('Outbound redirects');
$r = http('GET', "$base/redirect.php?id=chatgpt&url=https://evil.example&to=https://evil.example&redirect=//evil.example");
ok($r['code'] === 302 && preg_match('#^https?://#i', $r['loc']) && stripos($r['loc'], 'evil.example') === false, 'redirect target comes only from the catalogue: ' . $r['loc']);
ok(stripos($r['head'], 'X-Robots-Tag: noindex') !== false && stripos($r['head'], 'Referrer-Policy: no-referrer') !== false, 'redirect responses are noindex and send no referrer');
$r = http('GET', "$base/redirect.php?id=" . rawurlencode('../../etc/passwd'));
ok($r['code'] === 302 && $r['loc'] === '/index.php', 'unknown id goes to the homepage');
$r = http('GET', "$base/signup-redirect?next=https://evil.example");
ok($r['code'] === 404, '/signup-redirect does not exist in this codebase (404)');
$r = http('GET', "$base/compare-tools.php?a=x&b=" . rawurlencode('https://evil.example'));
ok(preg_match('#^compare\.php(\?|$)#', $r['loc']) === 1, 'compare-tools.php only redirects to a relative compare.php URL: ' . $r['loc']);

section('Sensitive and maintenance files are not served');
foreach (['/install-database.php', '/install-full-mysql.php', '/import-json-to-mysql.php', '/compare_install.php', '/generate_compare_pages.php', '/security.php', '/admin_auth.php', '/db.php', '/config.local.php', '/vote_debug.php', '/data/site.json', '/data/.form_secret', '/database/phase3_business.sql', '/tests/security_test.php', '/scripts/business_report.php', '/composer.json'] as $p) {
  $r = http('GET', $base . $p);
  ok($r['code'] === 404, "$p is not served (HTTP {$r['code']})");
}
$r = http('POST', "$base/submit_tool_save.php", ['name' => 'Injected', 'description' => 'x', 'url' => 'javascript:alert(1)']);
ok($r['code'] === 301 && $r['loc'] === '/submit.php', 'legacy catalogue writer no longer writes (HTTP ' . $r['code'] . ')');
$cat = http('GET', "$base/search.php?q=Injected")['body'];
ok(strpos($cat, 'javascript:alert(1)') === false, 'nothing was injected into the catalogue');

section('Submit form protection');
$s = http('GET', "$base/submit.php");
$stok = field_token($s['body'], '<form method="post">');
$r = http('POST', "$base/submit.php", ['name' => 'T', 'category' => 'Writing', 'website' => 'javascript:alert(1)', 'short' => 'x', 'plan' => 'free'], $own);
ok($r['code'] === 200 && stripos($r['body'], 'expired') !== false, 'submission without token is refused');
$r = http('POST', "$base/submit.php", ['name' => 'T', 'category' => 'Writing', 'website' => 'javascript:alert(1)', 'short' => 'x', 'plan' => 'free', '_csrf' => $stok], $own);
ok($r['code'] === 200 && stripos($r['body'], 'valid public website') !== false, 'javascript: website refused');
$r = http('POST', "$base/submit.php", ['name' => 'T', 'category' => 'Writing', 'website' => 'http://169.254.169.254/', 'short' => 'x', 'plan' => 'free', '_csrf' => $stok], $own);
ok($r['code'] === 200 && stripos($r['body'], 'valid public website') !== false, 'link-local/metadata address refused');

section('Admin');
$jar = tempnam(sys_get_temp_dir(), 'adm');
$r = http('GET', "$base/admin.php", [], [], $jar);
ok(in_array($r['code'], [200, 503], true) && stripos($r['body'], 'admin123') === false && stripos($r['body'], '?pass=') === false, 'admin page never reveals a password or ?pass= hint');
ok(stripos($r['head'], 'X-Robots-Tag: noindex') !== false && stripos($r['head'], 'Cache-Control: no-store') !== false, 'admin is noindex and not cacheable');
$r = http('GET', "$base/admin.php?pass=admin123&action=approve&i=0", [], [], $jar);
ok(stripos($r['body'], 'Sign out') === false && stripos($r['body'], 'Tool submissions') === false, 'legacy ?pass= access no longer works');
if ($r['code'] === 503) {
  ok(false, 'server has no AIHUB_ADMIN_PASS_HASH; start it with a test hash to exercise the login flow');
} else {
  $login = http('GET', "$base/admin.php", [], [], $jar);
  preg_match('/name="_login_token" value="([^"]+)"/', $login['body'], $m); $lt = $m[1] ?? '';
  sleep(2);   // the login form has a 1-second time trap against instant bot posts
  $r = http('POST', "$base/admin.php", ['admin_action' => 'login', '_login_token' => $lt, 'password' => 'wrong'], $own, $jar);
  ok($r['code'] === 200 && stripos($r['body'], 'Sign-in failed') !== false, 'wrong password rejected');
  $r = http('POST', "$base/admin.php", ['admin_action' => 'login', '_login_token' => 'bad', 'password' => $adminPw], $own, $jar);
  ok(stripos($r['body'], 'Sign out') === false, 'login without a valid form token is refused');
  $r = http('POST', "$base/admin.php", ['admin_action' => 'login', '_login_token' => $lt, 'password' => $adminPw], ['Origin: https://evil.example'], $jar);
  ok(stripos($r['body'], 'Sign out') === false && $r['code'] !== 303, 'cross-origin login refused');
  $r = http('POST', "$base/admin.php", ['admin_action' => 'login', '_login_token' => $lt, 'password' => $adminPw], $own, $jar);
  ok($r['code'] === 303 && preg_match('/Set-Cookie:\s*aihub_admin=[^;]+;.*HttpOnly/i', $r['head']) && stripos($r['head'], 'SameSite=Strict') !== false, 'correct password signs in; cookie is HttpOnly + SameSite=Strict');
  $r = http('GET', "$base/admin.php", [], [], $jar);
  preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $m); $at = $m[1] ?? '';
  ok(stripos($r['body'], 'Sign out') !== false && $at !== '', 'signed-in admin sees the dashboard with a session CSRF token');
  $r = http('POST', "$base/admin.php", ['admin_action' => 'reject', 'i' => 0], $own, $jar);
  ok($r['code'] === 403, 'admin action without CSRF token rejected');
  $r = http('POST', "$base/admin.php", ['admin_action' => 'reject', 'i' => 0, '_csrf' => $at], ['Origin: https://evil.example'], $jar);
  ok($r['code'] === 403, 'admin action from a foreign origin rejected');
  $r = http('GET', "$base/admin.php?action=reject&i=0&_csrf=$at", [], [], $jar);
  ok($r['code'] === 200, 'admin actions cannot be triggered via GET');
  // Lead admin prototype (needs synthetic leads: php scripts/seed_synthetic_leads.php on the test database)
  $lr = http('GET', "$base/admin-leads.php", [], [], $jar);
  ok($lr['code'] === 200 && stripos($lr['body'], 'Leads <span') !== false, 'signed-in admin can open the lead admin');
  ok(stripos($lr['body'], 'Synthetic test data') !== false, 'page warns that the data is synthetic');
  ok(!preg_match('/synthetic\d+@example\.test/', $lr['body']) && preg_match('/sy\*\*\*@example\.test/', $lr['body']), 'list view masks email addresses');
  ok(!preg_match('/\bROI\b|\d+(\.\d+)?\s?%|[$€]\s?\d/i', strip_tags($lr['body'])), 'report shows counts only: no percentages, ROI or currency amounts');
  preg_match('/href="[^"]*[?&;]id=(\d+)"/', $lr['body'], $mm); $leadId = $mm[1] ?? '';
  $ld = http('GET', "$base/admin-leads.php?id=$leadId", [], [], $jar);
  ok($leadId !== '' && preg_match('/synthetic\d+@example\.test/', $ld['body']) === 1, 'detail view shows the full address');
  preg_match('/name="_csrf" value="([^"]+)"/', $ld['body'], $m3); $lt2 = $m3[1] ?? '';
  $r = http('POST', "$base/admin-leads.php", ['admin_action' => 'set_status', 'id' => $leadId, 'status' => 'contacted'], $own, $jar);
  ok($r['code'] === 403, 'status change without CSRF token rejected');
  $r = http('POST', "$base/admin-leads.php", ['admin_action' => 'set_status', 'id' => $leadId, 'status' => 'contacted', '_csrf' => $lt2], ['Origin: https://evil.example'], $jar);
  ok($r['code'] === 403, 'status change from a foreign origin rejected');
  $r = http('GET', "$base/admin-leads.php?admin_action=set_status&id=$leadId&status=spam&_csrf=$lt2", [], [], $jar);
  ok(stripos($r['head'], 'Location:') === false, 'status cannot be changed with GET');
  $r = http('POST', "$base/admin-leads.php", ['admin_action' => 'set_status', 'id' => $leadId, 'status' => 'bogus', '_csrf' => $lt2], $own, $jar);
  ok($r['code'] === 303 && strpos($r['loc'], 'error=') !== false, 'invalid status is refused with an error');
  $anon = http('GET', "$base/admin-leads.php");
  ok(stripos($anon['body'], 'Admin sign-in') !== false && !preg_match('/example\.test/', $anon['body']), 'anonymous visitors see only the sign-in form and no lead data');
  $r = http('POST', "$base/admin.php", ['admin_action' => 'logout', '_csrf' => $at], $own, $jar);
  $r = http('GET', "$base/admin.php", [], [], $jar);
  ok(stripos($r['body'], 'Sign out') === false, 'logout ends the session');
}
@unlink($jar);

section('Leads and newsletter keep working with the stricter limits');
$biz = http('GET', "$base/business.php");
ok($biz['code'] === 200 && strpos($biz['body'], 'name="form_token"') !== false, 'lead form still carries its token');

echo "\nPASSED: $pass  FAILED: $fail\n";
exit($fail ? 1 : 0);
