<?php
/**
 * Phase 4 security regression tests (no server, no database needed).
 * Usage: php tests/security_test.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('AIHUB_RATE_DIR=' . sys_get_temp_dir() . '/aihub_rate_test_' . getmypid());
putenv('AIHUB_SECURITY_LOG=' . sys_get_temp_dir() . '/aihub_seclog_test_' . getmypid() . '.log');
putenv('AIHUB_FORM_SECRET=unit-test-secret-not-for-production');
putenv('AIHUB_TRUSTED_PROXIES');                // start with "trust nothing"
putenv('AIHUB_PROXY_HEADER');
require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../admin_auth.php';

$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "  FAIL: $m\n"; } }
function section(string $t): void { echo "== $t\n"; }
$root = realpath(__DIR__ . '/..');

section('CIDR matching');
ok(sec_ip_in_cidr('10.1.2.3', '10.0.0.0/8'), 'IPv4 inside /8');
ok(!sec_ip_in_cidr('11.1.2.3', '10.0.0.0/8'), 'IPv4 outside /8');
ok(sec_ip_in_cidr('192.168.1.5', '192.168.1.5'), 'single IP match');
ok(sec_ip_in_cidr('2001:db8::1', '2001:db8::/32'), 'IPv6 inside /32');
ok(!sec_ip_in_cidr('2001:db9::1', '2001:db8::/32'), 'IPv6 outside /32');
ok(!sec_ip_in_cidr('10.0.0.1', '2001:db8::/32'), 'mixed families never match');
ok(!sec_ip_in_cidr('10.0.0.1', '10.0.0.0/40'), 'invalid prefix rejected');
ok(!sec_ip_in_cidr('not-an-ip', '10.0.0.0/8'), 'garbage IP rejected');
ok(sec_ip_in_cidr('172.17.0.9', '172.16.0.0/12'), 'non-byte-aligned prefix (/12)');
ok(!sec_ip_in_cidr('172.32.0.9', '172.16.0.0/12'), 'non-byte-aligned prefix boundary');

section('Client IP: forwarded headers are never trusted by default');
$spoof = ['REMOTE_ADDR' => '203.0.113.50', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4', 'HTTP_CF_CONNECTING_IP' => '5.6.7.8', 'HTTP_X_REAL_IP' => '9.9.9.9', 'HTTP_CLIENT_IP' => '8.8.8.8'];
ok(sec_client_ip($spoof) === '203.0.113.50', 'spoofed headers ignored when no proxy is configured');
ok(sec_client_ip($spoof, ['10.0.0.0/8']) === '203.0.113.50', 'spoofed headers ignored when the peer is not a trusted proxy');
ok(sec_client_ip(['REMOTE_ADDR' => 'garbage']) === '0.0.0.0', 'invalid REMOTE_ADDR does not crash');
ok(sec_client_ip(['REMOTE_ADDR' => '2001:db8::7']) === '2001:db8::7', 'IPv6 peer is returned as is');
$viaProxy = ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9'];
ok(sec_client_ip($viaProxy, ['10.0.0.0/8']) === '198.51.100.9', 'trusted proxy: client taken from X-Forwarded-For');
$chain = ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.9, 10.0.0.7'];
ok(sec_client_ip($chain, ['10.0.0.0/8']) === '198.51.100.9', 'client-supplied leftmost entries are not used (right-to-left walk)');
ok(sec_client_ip(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => 'zzz, 198.51.100.9'], ['10.0.0.0/8']) === '198.51.100.9', 'garbage left of the first untrusted hop is ignored');
ok(sec_client_ip(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9, zzz'], ['10.0.0.0/8']) === '10.0.0.2', 'malformed hop next to our proxy falls back to the peer');
ok(sec_client_ip(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '10.0.0.3, 10.0.0.4'], ['10.0.0.0/8']) === '10.0.0.2', 'all-internal chain falls back to the peer');
ok(sec_client_ip(['REMOTE_ADDR' => '10.0.0.2'], ['10.0.0.0/8']) === '10.0.0.2', 'trusted proxy without header uses the peer');
putenv('AIHUB_PROXY_HEADER=CF-Connecting-IP');
ok(sec_client_ip(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_CF_CONNECTING_IP' => '198.51.100.77', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1'], ['10.0.0.0/8']) === '198.51.100.77', 'single-address header mode (Cloudflare style)');
ok(sec_client_ip(['REMOTE_ADDR' => '203.0.113.5', 'HTTP_CF_CONNECTING_IP' => '198.51.100.77'], ['10.0.0.0/8']) === '203.0.113.5', 'CF-Connecting-IP ignored from an untrusted peer');
ok(sec_client_ip(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_CF_CONNECTING_IP' => 'bad'], ['10.0.0.0/8']) === '10.0.0.2', 'invalid single-address header ignored');
putenv('AIHUB_PROXY_HEADER');
putenv('AIHUB_TRUSTED_PROXIES=10.0.0.0/8, 2001:db8::/32');
ok(sec_trusted_proxies() === ['10.0.0.0/8', '2001:db8::/32'], 'AIHUB_TRUSTED_PROXIES parsed');
ok(sec_client_ip($viaProxy) === '198.51.100.9', 'environment-configured trusted proxy works end to end');
putenv('AIHUB_TRUSTED_PROXIES');
ok(sec_is_https(['HTTPS' => 'on']) && !sec_is_https(['HTTPS' => 'off']), 'HTTPS detection');
ok(!sec_is_https(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_PROTO' => 'https']), 'X-Forwarded-Proto ignored from untrusted peers');

section('IP hashing for rate limits');
$h1 = sec_ip_hash('rate', '203.0.113.50'); $h2 = sec_ip_hash('rate', '203.0.113.51');
ok(strlen($h1) === 16 && $h1 !== $h2, 'hash is 16 hex and differs per address');
ok(sec_ip_hash('rate', '203.0.113.50') === $h1, 'hash is stable');
ok(sec_ip_hash('rate', '203.0.113.50') !== sec_ip_hash('log', '203.0.113.50'), 'purposes are separated');
ok(sec_ip_hash('rate', '2001:db8:1:2:aaaa::1') === sec_ip_hash('rate', '2001:db8:1:2:bbbb::2'), 'IPv6 addresses in the same /64 share a hash');
ok($h1 !== '203.0.113.50' && !preg_match('/\./', $h1), 'hash is not the raw address');

section('Safe local redirects');
$bad = ['//evil.com', '/\\evil.com', '\\\\evil.com', 'https://evil.com', 'http://evil.com/x', 'javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,x', "/ok\r\nSet-Cookie: a=b", "/ok\nLocation: https://evil.com", ' //evil.com', "\t//evil.com", '/%0d%0aSet-Cookie:a=b', '/%5Cevil.com', 'ftp://x', 'mailto:a@b.c', '', 'vbscript:x', '/..//evil.com//', "//\u{0}evil.com", '/\\/evil.com', 'file:///etc/passwd'];
foreach ($bad as $b) { $r = sec_safe_local_path($b, '/fallback'); ok($r === '/fallback' || (strncmp($r, '//', 2) !== 0 && strpos($r, ':') === false && !preg_match('/[\r\n\\\\]/', $r)), 'unsafe target neutralised: ' . json_encode($b) . ' -> ' . json_encode($r)); }
foreach (['//evil.com', 'https://evil.com', 'javascript:alert(1)', '/\\evil.com', "/a\r\nb"] as $b) ok(sec_safe_local_path($b, '/fb') === '/fb', 'rejected outright: ' . json_encode($b));
ok(sec_safe_local_path('/tool.php?id=chatgpt#community') === '/tool.php?id=chatgpt#community', 'normal path with query and fragment kept');
ok(sec_safe_local_path('tool.php?id=chatgpt') === '/tool.php?id=chatgpt', 'legacy relative target becomes root-relative');
ok(sec_safe_local_path(null, '/x') === '/x' && sec_safe_local_path(['a'], '/x') === '/x', 'non-strings fall back');
ok(sec_safe_local_path(str_repeat('a', 3000), '/x') === '/x', 'overlong target falls back');

section('Safe external URLs');
foreach (['https://example.com', 'http://example.com/path?q=1#x', 'https://sub.example.co.uk:8443/a', 'https://xn--bcher-kva.example/'] as $u) ok(sec_safe_external_url($u) === $u, 'accepted: ' . $u);
foreach (['javascript:alert(1)', 'data:text/html;base64,AAA', 'file:///etc/passwd', 'ftp://example.com', '//example.com', 'example.com', 'https://', 'https://user:pw@example.com', 'https://localhost/', 'http://127.0.0.1/', 'http://10.0.0.1/', 'http://192.168.0.1/', 'http://169.254.169.254/latest/meta-data', 'http://[::1]/', 'https://intranet/', 'https://printer.local/', "https://example.com/a b", "https://example.com/\"onmouseover=\"x", "https://example.com/<script>", "https://exa\tmple.com", 'https://example.com\\@evil.com', 'https://example.com:99999/', 'HTTPS://', 'https://-bad-.com/', 'https://x.invalid/'] as $u) ok(sec_safe_external_url($u) === null, 'rejected: ' . json_encode($u));
ok(sec_safe_external_url(str_repeat('a', 3000)) === null && sec_safe_external_url(null) === null, 'overlong/null rejected');
ok(sec_safe_external_url('HTTPS://Example.com/') === 'HTTPS://Example.com/', 'scheme case-insensitive');

section('Tokens and CSRF');
$t = sec_csrf_token('interact');
ok(sec_token_valid($t, 'csrf:interact'), 'fresh CSRF token validates');
ok(!sec_token_valid($t, 'csrf:comment'), 'token is bound to its scope');
ok(!sec_token_valid($t . 'x', 'csrf:interact') && !sec_token_valid('', 'csrf:interact') && !sec_token_valid('abc', 'csrf:interact'), 'tampered/empty tokens rejected');
$old = sec_token('csrf:interact', time() - 90000);
ok(!sec_token_valid($old, 'csrf:interact'), 'tokens older than 24h expire');
ok(sec_token_valid(sec_token('p', time() - 5), 'p', 2) && !sec_token_valid(sec_token('p', time()), 'p', 2), 'minimum age (time trap) works');
$srv = ['HTTP_HOST' => 'aihubpedia.com'];
ok(sec_csrf_ok('interact', ['_csrf' => $t], $srv), 'valid token accepted from form field');
ok(sec_csrf_ok('interact', [], $srv + ['HTTP_X_CSRF_TOKEN' => $t]), 'valid token accepted from X-CSRF-Token header');
ok(!sec_csrf_ok('interact', [], $srv), 'missing token rejected');
ok(!sec_csrf_ok('interact', ['_csrf' => $t], $srv + ['HTTP_ORIGIN' => 'https://evil.example']), 'cross-origin Origin rejected even with a token');
ok(!sec_csrf_ok('interact', ['_csrf' => $t], $srv + ['HTTP_ORIGIN' => 'null']), 'Origin: null rejected');
ok(sec_csrf_ok('interact', ['_csrf' => $t], $srv + ['HTTP_ORIGIN' => 'https://aihubpedia.com']), 'same Origin accepted');
ok(sec_csrf_ok('interact', ['_csrf' => $t], ['HTTP_HOST' => 'aihubpedia.com:8443', 'HTTP_ORIGIN' => 'https://aihubpedia.com:8443']), 'host with port accepted');
ok(!sec_csrf_ok('interact', ['_csrf' => $t], $srv + ['HTTP_REFERER' => 'https://evil.example/page']), 'cross-origin Referer rejected');
ok(sec_same_origin([]), 'no Origin/Referer headers is allowed (privacy tools strip them)');

section('Rate limiting');
$now = 1000000;
$a = sec_ip_hash('rate', '203.0.113.1'); $b = sec_ip_hash('rate', '203.0.113.2');
$res = []; for ($i = 0; $i < 6; $i++) $res[] = sec_rate_limited('unit', 5, 60, $a, $now + $i);
ok($res === [false, false, false, false, false, true], 'sixth hit within the window is limited');
ok(!sec_rate_limited('unit', 5, 60, $b, $now), 'another network is not affected');
ok(!sec_rate_limited('unit2', 5, 60, $a, $now), 'another bucket is not affected');
ok(!sec_rate_limited('unit', 5, 60, $a, $now + 120), 'window expiry frees the bucket');
$fakeXff = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '9.9.9.1'];
$other = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '9.9.9.2'];
ok(sec_ip_hash('rate', sec_client_ip($fakeXff)) === sec_ip_hash('rate', sec_client_ip($other)), 'rotating X-Forwarded-For does not change the rate-limit identity');

section('Security log');
$logf = getenv('AIHUB_SECURITY_LOG'); @unlink($logf);
sec_log('unit_event', ['why' => "line1\nline2\r\nforged", 'secret_ok' => 'x', 'arr' => ['nested'], 'n' => 5]);
$line = trim((string)@file_get_contents($logf));
$j = json_decode($line, true);
ok(is_array($j) && ($j['event'] ?? '') === 'unit_event', 'log line is valid JSON');
ok(strpos($line, "\n") === false && strpos((string)$j['why'], "\n") === false, 'newlines cannot forge extra log lines');
ok(!isset($j['arr']) && ($j['n'] ?? 0) === 5, 'non-scalar context dropped, scalars kept');
ok(strlen((string)($j['net'] ?? '')) > 0 && !preg_match('/\d+\.\d+\.\d+\.\d+/', $line), 'no raw IP addresses in the log');
ok(strpos($line, 'unit-test-secret') === false, 'secret never logged');
@unlink($logf);

section('Admin authentication');
putenv('AIHUB_ADMIN_PASS_HASH');
ok(!admin_enabled(), 'admin disabled without a configured hash (no default password)');
putenv('AIHUB_ADMIN_PASS_HASH=admin123');
ok(!admin_enabled(), 'a plain-text value is not accepted as a hash');
putenv('AIHUB_ADMIN_PASS_HASH=' . password_hash('correct horse', PASSWORD_DEFAULT));
ok(admin_enabled(), 'bcrypt/argon hash enables admin');
ok(admin_attempt_login('wrong') === 'invalid', 'wrong password rejected');
$r = []; for ($i = 0; $i < 6; $i++) $r[] = admin_attempt_login('wrong');
ok(in_array('rate_limited', $r, true), 'repeated failures are rate limited');
putenv('AIHUB_ADMIN_PASS_HASH');

section('No hard-coded secrets or unsafe patterns in source');
$files = []; $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) { $p = $f->getPathname(); if (substr($p, -4) === '.php' && !preg_match('#/(vendor|tests|extensions|node_modules|\.git)/#', $p)) $files[] = $p; }
$rel = fn($p) => ltrim(substr($p, strlen($root)), '/');
$hits = fn(string $re) => array_values(array_filter($files, fn($p) => preg_match($re, (string)file_get_contents($p))));
ok($hits('/Romil@|pmaadmin/') === [], 'old database password / username not present in code: ' . implode(',', array_map($rel, $hits('/Romil@|pmaadmin/'))));
ok($hits('/admin123/') === [], 'default admin password not present in code: ' . implode(',', array_map($rel, $hits('/admin123/'))));
ok($hits('/ini_set\(\s*[\'"]display_errors[\'"]\s*,\s*(1|true|[\'"]1[\'"]|[\'"]on[\'"])/i') === [], 'display_errors is never enabled in code: ' . implode(',', array_map($rel, $hits('/ini_set\(\s*[\'"]display_errors[\'"]\s*,\s*(1|true)/i'))));
$fwd = array_filter($hits('/HTTP_(CF_CONNECTING_IP|X_FORWARDED_FOR|X_REAL_IP|CLIENT_IP)/'), fn($p) => !in_array(basename($p), ['security.php', 'helpersold.php'], true));
ok($fwd === [], 'forwarded-IP headers are only read in security.php: ' . implode(',', array_map($rel, $fwd)));
$rawIp = array_filter($hits('/\$_SERVER\[[\'"]REMOTE_ADDR[\'"]\]/'), fn($p) => basename($p) !== 'security.php');
ok($rawIp === [], 'REMOTE_ADDR is only read in security.php: ' . implode(',', array_map($rel, $rawIp)));
$leak = array_filter($hits('/(echo|json_encode)[^;]*\$e->getMessage\(\)/'), fn($p) => strpos((string)file_get_contents($p), "PHP_SAPI !== 'cli'") === false);   // CLI-only scripts may print errors to the operator
ok($leak === [], 'exception messages are not echoed to visitors: ' . implode(',', array_map($rel, $leak)));
foreach (['install-database.php', 'install-full-mysql.php', 'import-json-to-mysql.php', 'compare_install.php', 'compare_sync_catalog.php', 'generate_compare_pages.php', 'compare_refresh_all.php', 'compare_fetch_source.php', 'compare_enrich_tool.php'] as $f) {
  ok(preg_match("/PHP_SAPI\s*!==\s*'cli'/", (string)file_get_contents("$root/$f")) === 1, "$f refuses non-CLI requests");
}
ok(!file_exists("$root/vote_debug.php"), 'debug endpoint that printed visitor IP/cookies is removed');
// every endpoint that reads $_POST must carry a CSRF/token/auth guard
$guards = '/sec_guard_write\(|sec_csrf_ok\(|form_token_valid\(|lead_validate\(|admin_csrf_ok\(|admin_require\(|sec_token_valid\(|\$_SESSION\[[\'"]csrf[\'"]\]/';
$unguarded = [];
foreach ($files as $p) { $s = (string)file_get_contents($p); if (preg_match('/\$_POST/', $s) && !preg_match($guards, $s)) $unguarded[] = $rel($p); }
$unguarded = array_values(array_filter($unguarded, fn($f) => !in_array($f, ['layout_top.php', 'layout_bottom.php'], true)));
ok($unguarded === [], 'all $_POST consumers carry a CSRF/token guard: ' . implode(',', $unguarded));
// state-changing GET is gone: legacy vote/bookmark endpoints do not touch storage on GET
ok(strpos((string)file_get_contents("$root/vote-tool.php"), 'vote_tool_action') === false, 'vote-tool.php GET no longer records votes');
ok(strpos((string)file_get_contents("$root/toggle-bookmark.php"), '$_GET') === false, 'toggle-bookmark.php does not read $_GET');
ok(strpos((string)file_get_contents("$root/admin.php"), '$_GET[\'pass\']') === false, 'admin.php no longer accepts a password in the URL');

section('Database credentials: no fallback');
$cmd = 'cd ' . escapeshellarg($root) . ' && env -i PATH=' . escapeshellarg(getenv('PATH')) . ' AIHUB_CONFIG_FILE=/nonexistent php -r ' . escapeshellarg('require "db.php"; try { db(); echo "CONNECTED"; } catch (Throwable $e) { echo get_class($e), ":", substr($e->getMessage(), 0, 40); }');
$out = (string)shell_exec($cmd);
ok(strpos($out, 'RuntimeException:Database credentials are not configured') === 0, 'db() throws instead of using a built-in password (got: ' . $out . ')');

echo "\nPASSED: $pass  FAILED: $fail\n";
exit($fail ? 1 : 0);
