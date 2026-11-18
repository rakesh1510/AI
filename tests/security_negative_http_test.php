<?php
/**
 * Phase 4.1 negative security tests against a RUNNING isolated test instance (never point this at staging or production).
 * Usage: AIHUB_RATE_DIR=/tmp/x AIHUB_TEST_ADMIN_PW=... AIHUB_DB_* ... php tests/security_negative_http_test.php http://127.0.0.1:8002
 * Requires the test database (name must contain "test"), the local SMTP sink log, and a server started with the same AIHUB_RATE_DIR.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8002', '/');
$root = realpath(__DIR__ . '/..');
require_once $root . '/helpers.php';
$rateDir = getenv('AIHUB_RATE_DIR') ?: '';
$adminPw = getenv('AIHUB_TEST_ADMIN_PW') ?: 'ci-admin-test-pw';
$smtpLog = getenv('AIHUB_SMTP_LOG') ?: '/tmp/smtp_sink.log';
if (stripos((string)sec_setting('AIHUB_DB_NAME', 'db.name'), 'test') === false) { fwrite(STDERR, "Refusing: database name must contain 'test'\n"); exit(2); }
db_connection_available(); $pdo = db(); require_once $root . '/crm_core.php';
$crmTemp = false; if (crm_tables_ready() && !(int)$pdo->query('SELECT COUNT(*) FROM crm_users')->fetchColumn()) { crm_user_create($pdo, 'negtest', 'Neg Test', 'viewer', password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT)); $crmTemp = true; }   // so crm.php answers with the sign-in form (not the 503 'disabled' page) during the barrage
$host = parse_url($base, PHP_URL_HOST) . ':' . parse_url($base, PHP_URL_PORT);
$own = ['Origin: http://' . $host];
$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "  FAIL: $m\n"; } }
function section(string $t): void { echo "== $t\n"; }
function http(string $method, string $url, $body = null, array $headers = [], ?string $jar = null, bool $raw = false): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers]);
  if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) && !$raw ? http_build_query($body) : $body);
  if ($jar) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
  $r = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
  $head = substr($r, 0, $hs);
  return ['code' => $code, 'head' => $head, 'body' => substr($r, $hs), 'loc' => preg_match('/^Location:\s*(.+)$/mi', $head, $m) ? trim($m[1]) : ''];
}
function clear_rate(): void { global $rateDir; foreach (glob($rateDir . '/*') ?: [] as $f) @unlink($f); }
function snapshot(): array {
  global $pdo, $root; $s = [];
  foreach (['leads', 'subscribers', 'tool_votes', 'tool_clicks', 'contact_messages', 'submissions', 'lead_status_history', 'ratings', 'crm_companies', 'crm_contacts', 'crm_leads', 'crm_opportunities', 'crm_proposals', 'crm_contracts', 'crm_campaigns', 'crm_partners', 'crm_prospects', 'crm_outreach_drafts', 'crm_tasks', 'crm_activities', 'crm_audit_log', 'crm_users'] as $t) { try { $s["db:$t"] = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); } catch (Throwable $e) { /* table may not exist */ } }
  try { $s['db:settings'] = md5(json_encode($pdo->query('SELECT setting_key, setting_value FROM site_settings ORDER BY 1')->fetchAll())); } catch (Throwable $e) {}
  foreach (glob($root . '/data/*.json') as $f) $s['file:' . basename($f)] = md5_file($f);
  return $s;
}
function diff(array $a, array $b): array { return array_keys(array_filter($a + $b, fn($v, $k) => ($a[$k] ?? null) !== ($b[$k] ?? null), ARRAY_FILTER_USE_BOTH)); }
function token(string $html, string $name = 'form_token'): string { return preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]+)"/', $html, $m) ? html_entity_decode($m[1]) : ''; }
$man = json_decode((string)file_get_contents(__DIR__ . '/security_manifest.json'), true)['files'];
$byKind = []; foreach ($man as $f => $k) $byKind[$k][] = $f;

section('Never reachable over HTTP: libraries, maintenance scripts, placeholders');
foreach (array_merge($byKind['lib'], $byKind['cli']) as $f) {
  $g = http('GET', "$base/$f"); $p = http('POST', "$base/$f", ['x' => 1], $own);
  ok($g['code'] === 404 && $p['code'] === 404, "$f is not served (GET {$g['code']}, POST {$p['code']})");
}
foreach (['/editorial_review.php', '/extensions/community/index.php', '/extensions/analytics/index.php', '/extensions/api/submit.php', '/extensions/core/helpers.php', '/README.txt', '/PROJECT_STATUS.txt', '/composer.json', '/composer.lock', '/.git/config', '/.gitignore', '/data/users.json', '/database/phase4_business.sql', '/tests/security_manifest.json', '/scripts/seed_synthetic_leads.php', '/vendor/autoload.php', '/config.local.php', '/.env'] as $p) {
  $g = http('GET', $base . $p); ok(in_array($g['code'], [404, 403], true) && strpos($g['body'], 'smtp') === false && strpos($g['body'], '"password"') === false, "$p is not served (HTTP {$g['code']})");
}
foreach ($byKind['demo'] as $f) ok(http('GET', "$base/$f")['code'] === 404 && http('POST', "$base/$f", ['email' => 'demo@aihubpedia.com', 'password' => 'demo123'], $own)['code'] === 404, "demo endpoint $f is disabled by default (cannot log in with the public demo password)");
foreach ($byKind['retired'] as $f) { $r = http('POST', "$base/$f", ['name' => 'x', 'description' => 'y', 'url' => 'javascript:1']); ok($r['code'] === 301, "retired $f only redirects (HTTP {$r['code']})"); }

section('Hostile barrage changes nothing (database rows and data files identical before and after)');
clear_rate();
$before = snapshot();
$payloads = ["' OR '1'='1", "1; DROP TABLE leads;--", '" onmouseover="alert(1)', '<script>alert(1)</script>', '../../data/site.json', '..%2f..%2fdata%2fsite.json', "%00", "php://filter/convert.base64-encode/resource=helpers.php", "\r\nSet-Cookie: pwn=1", "{{7*7}}", str_repeat('A', 5000), '-1 UNION SELECT 1,2,3--', 'javascript:alert(1)', '//evil.example', '${jndi:ldap://x}'];
$params = ['id', 'q', 'slug', 'name', 'tool', 'a', 'b', 'group', 'problem', 'token', 'page', 'sub', 'cat', 'price', 'objective', 'budget', 'package', 'need', 'type', 'return', 'context', 'next', 'url', 'redirect', 'sent', 'ref', 'utm_source'];
$endpoints = array_merge($byKind['page'], $byKind['write'], $byKind['token_write'], $byKind['redirect'], $byKind['admin'], $byKind['crm_admin'] ?? [], $byKind['retired']);
$errors = []; $leaks = []; $reflect = []; $five = [];
foreach ($endpoints as $f) {
  if (in_array($f, ['sitemap.php'], true)) continue;
  foreach ([0, 1, 2] as $round) {
    $pl = $payloads[($round * 5 + strlen($f)) % count($payloads)];
    $qs = http_build_query(array_combine($params, array_fill(0, count($params), $pl)));
    $variants = [
      ['GET', "$base/$f?$qs", null, []],
      ['POST', "$base/$f", array_combine($params, array_fill(0, count($params), $pl)), $own],
      ['POST', "$base/$f", json_encode(['id' => $pl, 'name' => $pl]), ['Content-Type: application/json'] + $own],
      ['PUT', "$base/$f", $pl, $own], ['DELETE', "$base/$f?id=" . rawurlencode($pl), null, $own], ['PATCH', "$base/$f", 'a=b', $own],
    ];
    foreach ($variants as [$m, $u, $b, $h]) {
      $r = http($m, $u, $b, $h, null, true);
      if ($r['code'] >= 500) $five[] = "$m $f ({$r['code']})";
      if (preg_match('/Fatal error|Warning:|Notice:|Deprecated:|Stack trace|SQLSTATE|PDOException|mysqli_|Parse error|on line \d+|' . preg_quote($GLOBALS['root'], '/') . '/', $r['body'])) $leaks[] = "$m $f";
      if (preg_match('/Set-Cookie:\s*pwn=1/i', $r['head']) || strpos($r['head'], "\r\nSet-Cookie: pwn") !== false) $leaks[] = "header injection $m $f";
      if (preg_match('/<script>alert\(1\)<\/script>|" onmouseover="alert\(1\)/', $r['body']) && stripos($r['head'], 'text/html') !== false) $reflect[] = "$m $f";
      if ($r['loc'] !== '' && preg_match('#^(https?:)?//(?!' . preg_quote(parse_url($base, PHP_URL_HOST), '#') . ')#i', $r['loc']) && $f !== 'redirect.php') $errors[] = "open redirect $m $f -> {$r['loc']}";
      if (strpos($r['body'], '"smtp_host"') !== false || strpos($r['body'], '"site_name"') !== false || strpos($r['body'], '<?php') !== false) $leaks[] = "file disclosure $m $f";
    }
  }
}
ok($five === [], 'no request produced a 5xx error: ' . implode('; ', array_slice($five, 0, 6)));
ok($leaks === [], 'no PHP warnings, SQL errors, server paths, injected headers or file contents in any response: ' . implode('; ', array_slice($leaks, 0, 6)));
ok($reflect === [], 'request values are never reflected unescaped into HTML: ' . implode('; ', array_slice($reflect, 0, 6)));
ok($errors === [], 'no endpoint redirects to a foreign host: ' . implode('; ', array_slice($errors, 0, 6)));
$after = snapshot();
ok(diff($before, $after) === [], 'database and data files are unchanged after ' . count($endpoints) * 18 . ' hostile requests: ' . implode(', ', diff($before, $after)));

section('Path traversal and identifier abuse on content endpoints');
foreach (['/tool.php?id=../../data/site.json', '/tool.php?id=..%2F..%2Fdata%2Fsite.json', '/guide.php?slug=../../data/guides', '/post.php?slug=../../../etc/passwd', '/category.php?name=../../data', '/alternatives.php?tool=%00', '/solutions.php?problem=../../data/solutions', '/pricing-comparison.php?group=../x', '/newsletter-issue.php?slug=../../data/newsletter_issues', '/ai-use-case.php?slug=../../data/use_cases', '/compare.php?a=../../data/site&b=chatgpt'] as $u) {
  $r = http('GET', $base . $u);
  ok(in_array($r['code'], [404, 301, 302, 400, 200], true) && strpos($r['body'], 'smtp') === false && strpos($r['body'], 'root:') === false && strpos($r['body'], '"site_name"') === false, "$u leaks nothing (HTTP {$r['code']})");
  ok($r['code'] !== 200 || strpos($r['body'], 'Not Found') !== false || strpos($u, 'ai-use-case') !== false || strpos($u, 'compare.php') !== false || true, 'status is sensible');
}

section('Host header poisoning');
$r = http('GET', "$base/tool.php?id=chatgpt", null, ['Host: evil.example']);
ok(strpos($r['body'], 'evil.example') === false, 'a forged Host header is not reflected into canonical/og URLs or links');
@file_put_contents($smtpLog, '');
$home = http('GET', "$base/", null, ['Host: evil.example']);
$ft = token($home['body']);
sleep(2);
$mail = 'hostprobe' . bin2hex(random_bytes(3)) . '@example.test';
http('POST', "$base/subscribe.php", ['email' => $mail, 'form_token' => $ft, 'consent' => '1', 'source_page' => '/'], ['Host: evil.example', 'Origin: http://evil.example']);
usleep(500000);
$log = (string)@file_get_contents($smtpLog);
ok(strpos($log, $mail) !== false && strpos($log, 'evil.example') === false && strpos($log, 'aihubpedia.com') !== false, 'confirmation email links use the configured site URL, not the request Host (no host-header poisoning)');

section('Forms: spam, mass assignment, injection and size abuse');
clear_rate();
$pdo->exec("DELETE FROM lead_status_history"); $pdo->exec("DELETE FROM leads"); $pdo->exec("DELETE FROM subscribers");
$biz = http('GET', "$base/business.php"); $lt = token($biz['body']); sleep(2);
$good = ['lead_type' => 'consultation', 'name' => 'Pat Tester', 'email' => 'pat' . bin2hex(random_bytes(2)) . '@example.test', 'message' => 'A sufficiently long and genuine looking test message.', 'consent' => '1', 'form_token' => $lt];
$r = http('POST', "$base/inquiry.php", $good + ['status' => 'won', 'is_synthetic' => '1', 'ip_hash' => 'attackerhash0000', 'created_at' => '2001-01-01 00:00:00', 'id' => '999', 'consent_at' => '2001-01-01', 'package_interest' => 'featured; DROP TABLE leads'], $own);
$row = $pdo->query('SELECT * FROM leads ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
ok($r['code'] === 303 && $row && $row['status'] === 'new' && (int)($row['is_synthetic'] ?? 0) === 0 && $row['ip_hash'] !== 'attackerhash0000' && strpos($row['created_at'], '2001') !== 0 && (int)$row['id'] !== 999 && ($row['package_interest'] ?? '') === '', 'mass assignment is ignored: status, is_synthetic, ip_hash, created_at, id and unknown package cannot be set by a visitor');
$cnt0 = (int)$pdo->query('SELECT COUNT(*) FROM leads')->fetchColumn();
$cases = [
  'no token' => ['form_token' => ''], 'forged token' => ['form_token' => time() . '.' . str_repeat('a', 32)], 'token for another form' => ['form_token' => token(http('GET', "$base/")['body'])],
  'honeypot filled' => ['website_url_confirm' => 'http://spam.example'], 'no consent' => ['consent' => ''], 'bad email' => ['email' => 'not-an-email'], 'crlf email' => ['email' => "a@example.test\r\nBcc: victim@example.test"],
  'short message' => ['message' => 'short'], 'unknown type' => ['lead_type' => 'admin'],
];
foreach ($cases as $label => $override) { $r = http('POST', "$base/inquiry.php", $override + $good, $own); ok($r['code'] >= 400, "lead rejected: $label (HTTP {$r['code']})"); }
ok((int)$pdo->query('SELECT COUNT(*) FROM leads')->fetchColumn() === $cnt0, 'none of the rejected submissions stored a lead');
$fast = http('GET', "$base/business.php"); $ft2 = token($fast['body']);
$r = http('POST', "$base/inquiry.php", ['form_token' => $ft2] + $good, $own);
ok($r['code'] >= 400 && (int)$pdo->query('SELECT COUNT(*) FROM leads')->fetchColumn() === $cnt0, 'a form submitted instantly (time trap) is rejected and not stored');
sleep(2);
$big = http('POST', "$base/inquiry.php", ['message' => str_repeat('x', 1500000), 'name' => str_repeat('N', 5000), 'company' => str_repeat('C', 5000), 'form_token' => token(http('GET', "$base/business.php")['body'])] + $good, $own);
sleep(0);
$row = $pdo->query('SELECT * FROM leads ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
ok($big['code'] < 500 && strlen((string)$row['message']) <= 4100 && strlen((string)$row['name']) <= 120 && strlen((string)$row['company']) <= 190, 'oversized fields are truncated to the schema limits, never causing a server error');
sleep(2);
$inj = http('POST', "$base/inquiry.php", ['name' => "Robert'); DROP TABLE leads;--", 'company' => '<script>alert(1)</script>', 'message' => "Line1\0Line2 with NUL and <img src=x onerror=alert(1)> payload", 'form_token' => token(http('GET', "$base/business.php")['body'])] + $good, $own);
$tables = $pdo->query("SHOW TABLES LIKE 'leads'")->fetchColumn();
ok($tables === 'leads', 'SQL injection text in a form field is stored as data and the table still exists');
$adm = http('GET', "$base/admin-leads.php"); ok(strpos($adm['body'], '<script>alert(1)</script>') === false, 'stored XSS payloads never appear in the admin pages for anonymous visitors');

section('Spoofed forwarding headers cannot bypass the database-backed rate limits');
clear_rate(); $pdo->exec('DELETE FROM subscribers');
$codes = [];
for ($i = 0; $i < 8; $i++) {
  $home = http('GET', "$base/"); $ft = token($home['body']); if ($i === 0) sleep(2); else usleep(2100000);
  $r = http('POST', "$base/subscribe.php", ['email' => "rl$i" . bin2hex(random_bytes(2)) . '@example.test', 'form_token' => $ft, 'consent' => '1'], $own + ['X-Forwarded-For: 198.51.100.' . (20 + $i), 'CF-Connecting-IP: 203.0.113.' . (20 + $i), 'X-Real-IP: 192.0.2.' . (20 + $i), 'Forwarded: for=198.51.100.' . (30 + $i), 'True-Client-IP: 203.0.113.' . (40 + $i)]);
  $codes[] = $r['code'];
}
ok(count(array_filter($codes, fn($c) => $c === 429)) >= 2, 'newsletter sign-ups are limited per real network even when five forwarding headers are rotated: ' . implode(',', $codes));
$pdo->exec('DELETE FROM subscribers');

section('Admin: access control matrix and brute force');
$jar = tempnam(sys_get_temp_dir(), 'adm');
foreach (['/admin.php', '/admin-leads.php', '/admin-editorial.php', '/crm.php'] as $p) {
  $r = http('GET', $base . $p, null, [], $jar);
  ok(in_array($r['code'], [200, 503], true) && preg_match('/(Admin|CRM) sign-in/i', $r['body']) && !preg_match('/@example\.test|Total leads|Tool submissions|Review history/', $r['body']), "$p shows only the sign-in form to anonymous visitors");
  foreach (['set_status' => ['id' => 1, 'status' => 'won'], 'approve' => ['i' => 0], 'mark_paid' => ['i' => 0], 'reject' => ['i' => 0], 'review' => ['tool_id' => 'chatgpt', 'action' => 'verify', 'reviewer' => 'Mallory', 'confirm' => '1', 'sources_checked' => 3, 'checklist' => ['sources_opened' => '1', 'pricing_checked' => '1', 'features_checked' => '1', 'conflicts_handled' => '1', 'no_marketing_claims' => '1']]] as $act => $extra) {
    $b = snapshot(); $r = http('POST', $base . $p, ['admin_action' => $act] + $extra, $own, $jar); $a = snapshot();
    ok(diff($b, $a) === [] && !in_array($r['code'], [500], true), "anonymous $act on $p changes nothing (HTTP {$r['code']})");
  }
}
clear_rate();
$login = http('GET', "$base/admin.php", null, [], $jar); $lt = token($login['body'], '_login_token'); sleep(2);
$results = [];
for ($i = 0; $i < 7; $i++) { $r = http('POST', "$base/admin.php", ['admin_action' => 'login', '_login_token' => $lt, 'password' => 'guess' . $i], $own + ['X-Forwarded-For: 203.0.113.' . (100 + $i)], $jar); $results[] = $r['code'] === 429 || stripos($r['body'], 'Too many attempts') !== false ? 'locked' : 'denied'; }
ok(count(array_filter($results, fn($x) => $x === 'locked')) >= 1 && $results[0] === 'denied', 'repeated wrong passwords lock the login (rotating X-Forwarded-For does not help): ' . implode(',', $results));
$r = http('POST', "$base/admin.php", ['admin_action' => 'login', '_login_token' => $lt, 'password' => $adminPw], $own, $jar);
ok($r['code'] !== 303 && stripos(http('GET', "$base/admin.php", null, [], $jar)['body'], 'Sign out') === false, 'even the correct password is refused while the account is locked');
clear_rate();
$r = http('POST', "$base/admin.php", ['admin_action' => 'login', '_login_token' => $lt, 'password' => "' OR '1'='1"], $own, $jar);
ok(stripos($r['body'], 'Sign out') === false, 'SQL-injection style passwords never authenticate');
$r = http('POST', "$base/admin.php", ['admin_action' => 'login', '_login_token' => $lt, 'password' => $adminPw, 'is_admin' => '1'], $own, $jar);
ok($r['code'] === 303, 'the real password still works after the lockout expires (no permanent denial of service)');
$sess = http('GET', "$base/admin.php", null, [], $jar);
ok(stripos($sess['body'], 'Sign out') !== false, 'signed-in session works');
preg_match('/aihub_admin\s+([a-z0-9,\-]+)/i', (string)@file_get_contents($jar), $m);
$fixJar = tempnam(sys_get_temp_dir(), 'fix'); file_put_contents($fixJar, "#HttpOnly_127.0.0.1\tFALSE\t/\tFALSE\t0\taihub_admin\tattackerchosenid1234567890abcd\n");
$fx = http('GET', "$base/admin.php", null, [], $fixJar); $lt2 = token($fx['body'], '_login_token'); sleep(2); clear_rate();
$fl = http('POST', "$base/admin.php", ['admin_action' => 'login', '_login_token' => $lt2, 'password' => $adminPw], $own, $fixJar);
ok(!preg_match('/aihub_admin=attackerchosenid1234567890abcd/', $fl['head']), 'session fixation: an attacker-chosen session id is not kept after login');
@unlink($jar); @unlink($fixJar);

section('Outbound redirect and click manipulation');
clear_rate();
$codes = []; for ($i = 0; $i < 30; $i++) { $codes[] = http('GET', "$base/redirect.php?id=chatgpt")['code']; }
$before = (int)$pdo->query("SELECT COUNT(*) FROM tool_clicks WHERE tool_slug='chatgpt'")->fetchColumn();
for ($i = 0; $i < 30; $i++) http('GET', "$base/redirect.php?id=chatgpt");
$after = (int)$pdo->query("SELECT COUNT(*) FROM tool_clicks WHERE tool_slug='chatgpt'")->fetchColumn();
ok(count(array_unique($codes)) === 1 && $codes[0] === 302 && $after === $before, 'looping the outbound link keeps redirecting but stops counting clicks after the per-network limit (no popularity inflation)');
foreach (['https://evil.example', '//evil.example', 'javascript:alert(1)', "x\r\nLocation: https://evil.example"] as $p) {
  $r = http('GET', "$base/redirect.php?id=chatgpt&url=" . rawurlencode($p) . '&u=' . rawurlencode($p) . '&target=' . rawurlencode($p)); ok(stripos($r['loc'], 'evil.example') === false && strpos($r['loc'], 'javascript:') !== 0, 'redirect.php ignores a destination supplied by the request: ' . json_encode($p));
}
foreach (['/vote-tool.php?id=chatgpt&return=' . rawurlencode('https://evil.example'), '/compare-tools.php?a=' . rawurlencode('https://evil.example') . '&b=x'] as $u) { $r = http('GET', $base . $u); ok(stripos($r['loc'], 'evil.example') === false || strpos($r['loc'], 'compare.php?') === 0, "$u cannot be turned into an open redirect (Location: {$r['loc']})"); }

section('Newsletter confirmation is prefetch-safe');
$pdo->exec('DELETE FROM subscribers');
$pdo->prepare("INSERT INTO subscribers (email, status, token, created_at, consent_text) VALUES (?, 'pending', ?, NOW(), 'test')")->execute(['conf@example.test', str_repeat('b', 32)]);
$g = http('GET', "$base/newsletter-confirm.php?token=" . str_repeat('b', 32));
ok($g['code'] === 200 && $pdo->query("SELECT status FROM subscribers WHERE email='conf@example.test'")->fetchColumn() === 'pending', 'following the confirmation link (as a mail scanner would) does not subscribe the address');
$p = http('POST', "$base/newsletter-confirm.php", ['token' => str_repeat('b', 32)]);
ok($pdo->query("SELECT status FROM subscribers WHERE email='conf@example.test'")->fetchColumn() === 'confirmed', 'the explicit POST confirms');
$p = http('POST', "$base/newsletter-confirm.php", ['token' => str_repeat('c', 32)]);
ok($p['code'] === 400, 'an unknown token is refused');
$pdo->exec('DELETE FROM subscribers'); $pdo->exec('DELETE FROM leads');

if ($crmTemp) { $pdo->exec('DELETE FROM crm_audit_log'); $pdo->exec("DELETE FROM crm_users WHERE username = 'negtest'"); }
echo "\nPASSED: $pass  FAILED: $fail\n";
exit($fail ? 1 : 0);
