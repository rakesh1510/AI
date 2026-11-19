<?php
/**
 * Phase 8A Control Center test: authorization matrix per role, CSRF, no invented figures, honest empty states, audit rows, escaping, headers, hostile input.
 * Scratch DB p8a_<pid> (phase6 + phase7 SQL, synthetic users), php -S on 8380-8399, nothing else touched.
 * Usage: php tests/phase8a_admin_center_test.php   (env as the other tests: AIHUB_COMPAT_ROOT_USER/PASS, AIHUB_DB_HOST). Optional: AXE_PATH + playwright => browser/axe check.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
ob_start(); require_once $root . '/crm_core.php'; ob_end_clean();
$dsnBase = getenv('P7_DSN_BASE') ?: 'mysql:host=' . (getenv('AIHUB_DB_HOST') ?: '127.0.0.1') . ';port=3306';
$user = getenv('P7_USER') ?: (getenv('AIHUB_COMPAT_ROOT_USER') ?: 'aihub_test');
$pw = getenv('P7_PASS') !== false ? getenv('P7_PASS') : (getenv('AIHUB_COMPAT_ROOT_PASS') !== false ? getenv('AIHUB_COMPAT_ROOT_PASS') : 'test_only_pw');
$dbHost = getenv('AIHUB_DB_HOST') ?: '127.0.0.1'; $db = 'p8a_' . getmypid();
$opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$admin = new PDO($dsnBase, $user, $pw, $opt); $admin->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo = new PDO($dsnBase . ";dbname=$db", $user, $pw, $opt);
$pass = 0; $fail = 0; $proc = null; $tmp = sys_get_temp_dir() . '/p8a_' . getmypid(); @mkdir("$tmp/rate", 0775, true);
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; fwrite(STDERR, "  FAIL: $m\n"); } }
function section(string $t): void { fwrite(STDERR, "== $t\n"); }
register_shutdown_function(function () use ($admin, $db, &$proc, $tmp) { if (is_resource($proc)) { @proc_terminate($proc); @proc_close($proc); } try { $admin->exec("DROP DATABASE IF EXISTS `$db`"); } catch (Throwable $e) {} shell_exec('rm -rf ' . escapeshellarg($tmp)); });
function sqlStatements(string $file): array { $d = ';'; $b = ''; $o = []; foreach (preg_split('/\R/', (string)file_get_contents($file)) as $l) { $t = trim($l); if ($b === '' && ($t === '' || str_starts_with($t, '--'))) continue; if (preg_match('/^DELIMITER\s+(\S+)$/i', $t, $m)) { $d = $m[1]; continue; } $b .= $l . "\n"; if (str_ends_with(rtrim($b), $d)) { $o[] = substr(rtrim($b), 0, -strlen($d)); $b = ''; } } return $o; }
foreach (['phase6_crm.sql', 'phase7_universe.sql'] as $f) foreach (sqlStatements("$root/database/$f") as $s) $pdo->exec($s);

$H = password_hash('admin-center-test-pw-1', PASSWORD_DEFAULT);
$mk = function (string $n, string $dn, string $r) use ($pdo, $H): int { [$o, $id] = crm_user_create($pdo, $n, $dn, $r, $H); if (!$o) throw new RuntimeException("user $n: " . json_encode($id)); return (int)$id; };
foreach (['owner' => 'owner', 'sales' => 'sales', 'viewer' => 'viewer', 'editor' => 'editor', 'verifier' => 'verifier'] as $n => $r) $mk("t8$n", ucfirst($n) . ' Test', $r);
$mk('t8xss', '<script>alert(1)</script> O\'Brien', 'viewer'); $mk('t8off', 'Inactive', 'owner'); $pdo->exec("UPDATE crm_users SET active = 0 WHERE username = 't8off'");

$port = 8380; for (; $port < 8400; $port++) { $s = @stream_socket_server("tcp://127.0.0.1:$port"); if ($s) { fclose($s); break; } }
$env = ['AIHUB_DB_HOST' => $dbHost, 'AIHUB_DB_NAME' => $db, 'AIHUB_DB_USER' => $user, 'AIHUB_DB_PASS' => $pw, 'AIHUB_FORM_SECRET' => 'p8a-test-secret-not-for-production', 'AIHUB_SECURITY_LOG' => "$tmp/sec.log", 'AIHUB_RATE_DIR' => "$tmp/rate"];
foreach (['AIHUB_ANALYTICS', 'AIHUB_ANALYTICS_SALT'] as $k) putenv($k);   // analytics is NOT enabled for this server
$proc = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root, "$root/tests/router.php"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp, $root, $env + getenv());
for ($i = 0; $i < 60; $i++) { usleep(100000); if (@fsockopen('127.0.0.1', $port)) break; }
$base = "http://127.0.0.1:$port"; $own = ["Origin: $base"];
function http(string $m, string $u, $body = null, array $h = [], ?string $jar = null): array {
  $ch = curl_init($u); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CUSTOMREQUEST => $m, CURLOPT_HTTPHEADER => $h, CURLOPT_FOLLOWLOCATION => false]);
  if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
  if ($jar) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
  $r = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
  return ['code' => $code, 'head' => substr($r, 0, $hs), 'body' => substr($r, $hs), 'loc' => preg_match('/^Location:\s*(.+)$/mi', substr($r, 0, $hs), $mm) ? trim($mm[1]) : ''];
}
function tok(string $html, string $name): string { return preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $m) ? html_entity_decode($m[1]) : ''; }
function login(string $base, array $own, string $username, string $password = 'admin-center-test-pw-1'): string {
  global $tmp; foreach ((array)glob("$tmp/rate/*") as $f) @unlink($f);   // the sign-in limiter (5 per 15 minutes) is tested elsewhere; keep it from throttling this test's many sign-ins
  $jar = tempnam(sys_get_temp_dir(), 'p8a'); $p = http('GET', "$base/admin-center.php", null, [], $jar); $t = tok($p['body'], '_login_token'); usleep(1300000);
  http('POST', "$base/admin-center.php", ['act' => 'login', 'username' => $username, 'password' => $password, '_login_token' => $t], $own, $jar); return $jar;
}
$jars = []; foreach (['owner', 'sales', 'viewer', 'editor', 'verifier', 'xss'] as $r) $jars[$r] = login($base, $own, "t8$r");
$page = fn(string $r, string $q = '') => http('GET', "$base/admin-center.php$q", null, [], $jars[$r]);

section('sign-in required, no data for anonymous or inactive users');
$a = http('GET', "$base/admin-center.php"); ok(stripos($a['body'], 'sign-in') !== false && stripos($a['body'], 'Recurring revenue') === false && stripos($a['body'], 'Control Center sections') === false, 'anonymous sees only the sign-in form');
$off = login($base, $own, 't8off'); $r = http('GET', "$base/admin-center.php", null, [], $off); ok(stripos($r['body'], 'Control Center sections') === false, 'an inactive account cannot sign in');
$bad = login($base, $own, 't8owner', 'wrong-password-xx'); $r = http('GET', "$base/admin-center.php", null, [], $bad); ok(stripos($r['body'], 'Control Center sections') === false, 'a wrong password does not sign in');
$r = http('GET', "$base/admin-center.php?m=finance"); ok(stripos($r['body'], 'Planned for Phase') === false, 'anonymous cannot open a module page');

section('authorization matrix: navigation and tiles per role');
$exp = ['owner' => ['CRM and leads', 'AI Universe review', 'Legacy admin', 'Analytics', 'AI tool management', 'Finance and revenue', 'Advertising', 'Operations', 'Performance', 'System health'], 'sales' => ['CRM and leads', 'Advertising'], 'viewer' => ['CRM and leads'], 'editor' => ['AI Universe review', 'AI tool management'], 'verifier' => ['AI Universe review']];
$all = ['CRM and leads', 'AI Universe review', 'Legacy admin', 'Analytics', 'AI tool management', 'Finance and revenue', 'Advertising', 'Operations', 'Performance', 'System health'];
foreach ($exp as $role => $has) { $r = $page($role); ok($r['code'] === 200, "$role gets the overview (HTTP {$r['code']})"); foreach ($all as $label) { $present = stripos($r['body'], $label) !== false; $want = in_array($label, $has, true); ok($present === $want, "$role " . ($want ? 'sees' : 'does not see') . " '$label'"); } }
$r = $page('editor'); ok(stripos($r['body'], 'Recurring revenue') === false && stripos($r['body'], 'Verified entities') !== false, 'an editor sees AI Universe tiles and no revenue');
$r = $page('viewer'); ok(stripos($r['body'], 'Recurring revenue') !== false && stripos($r['body'], 'Verified entities') === false, 'a viewer sees CRM tiles and no AI Universe tiles');
foreach (['finance' => ['sales', 'viewer', 'editor', 'verifier'], 'operations' => ['sales', 'viewer', 'editor', 'verifier'], 'analytics' => ['sales', 'viewer', 'editor', 'verifier'], 'advertising' => ['viewer', 'editor', 'verifier']] as $mod => $denied) foreach ($denied as $role) { $r = $page($role, "?m=$mod"); ok($r['code'] === 403 && stripos($r['body'], 'Planned for Phase') === false, "$role is refused $mod (403)"); }
$r = $page('owner', '?m=finance'); ok($r['code'] === 200 && stripos($r['body'], 'Planned for Phase 8D') !== false && stripos($r['body'], 'Nothing is collected or shown here yet') !== false, 'a planned module says it is planned and shows no data');
$r = $page('sales', '?m=advertising'); ok($r['code'] === 200 && stripos($r['body'], 'Phase 8E') !== false, 'sales may open the advertising placeholder');
$r = $page('owner', '?m=crm'); ok($r['code'] === 302 && $r['loc'] === '/crm.php', 'existing tools open on their own pages');
$r = $page('owner', '?m=nope'); ok($r['code'] === 404, 'an unknown module is a 404');
foreach (["?m[]=x", "?m=" . str_repeat('a', 5000), "?m=../../etc/passwd", "?m='%20OR%201=1"] as $q) { $r = $page('owner', $q); ok($r['code'] === 404 || $r['code'] === 200, "hostile module parameter never 500 ($q)"); }

section('no invented figures');
$r = $page('owner'); $b = $r['body'];
ok(stripos($b, 'Not available yet') !== false && stripos($b, 'Visitors and page views') !== false, 'visitors and clicks are marked not available yet');
ok(!preg_match('/>\s*[1-9][0-9,]*\s*<\/div>/', preg_replace('/\s+/', ' ', explode('Coming in later phases', $b)[1] ?? '')), 'no number is shown for the later-phase tiles');
ok(stripos($b, 'No data yet') !== false, 'tiles without records say "No data yet" (MRR and revenue)');
ok(stripos($b, 'Not enabled on this server') !== false, 'tracking is shown as not enabled, not assumed');
ok(!str_contains($b, '1,240') && !str_contains($b, '382') && !str_contains($b, '€199'), 'the illustrative example figures are not shown anywhere');
$pdo->exec("INSERT INTO crm_companies (name, domain, source, created_at, updated_at, is_synthetic) VALUES ('Synthetic Co', '', 'test', NOW(), NOW(), 1)"); $cid = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO crm_contracts (company_id, status, billing_period, currency, amount_minor, start_date, end_date, confirmed_by, confirmed_at, created_at, is_synthetic) VALUES ($cid, 'active', 'monthly', 'EUR', 19900, '2025-01-01', '2099-12-31', 1, NOW(), NOW(), 1)");
$r = $page('owner'); ok(stripos($r['body'], '199.00') !== false || stripos($r['body'], '199,00') !== false || stripos($r['body'], '€199') !== false, 'a recorded EUR contract appears as MRR (from the record, formatted in EUR)');

section('security: CSRF, methods, headers, escaping, audit');
$h = $page('owner')['head']; ok(preg_match('/X-Robots-Tag:\s*noindex, nofollow/i', $h) === 1 && preg_match('/Cache-Control:.*no-store/i', $h) === 1 && stripos($h, 'X-Frame-Options') !== false && stripos($h, 'X-Content-Type-Options: nosniff') !== false, 'noindex, no-store and security headers');
$page1 = $page('owner')['body']; $csrf = tok($page1, '_csrf'); ok($csrf !== '', 'the sign-out form carries a CSRF token');
$r = http('POST', "$base/admin-center.php", ['act' => 'logout'], $own, $jars['owner']); ok($r['code'] === 403, 'sign-out without a CSRF token is refused'); $r = $page('owner'); ok(stripos($r['body'], 'Control Center sections') !== false, 'the session survived the refused request');
$r = http('POST', "$base/admin-center.php", ['act' => 'logout', '_csrf' => $csrf], ['Origin: https://evil.example'], $jars['owner']); ok($r['code'] === 403, 'sign-out from a foreign origin is refused');
$r = http('POST', "$base/admin-center.php", ['act' => 'delete_everything', '_csrf' => $csrf], $own, $jars['owner']); ok($r['code'] === 400, 'an unknown POST action is refused');
foreach (['PUT', 'DELETE', 'PATCH'] as $m) { $r = http($m, "$base/admin-center.php", null, $own, $jars['owner']); ok($r['code'] === 405 || $r['code'] === 403, "$m is not accepted ({$r['code']})"); }
$before = (int)$pdo->query('SELECT COUNT(*) FROM crm_contracts')->fetchColumn(); $page('owner', '?m=finance'); ok((int)$pdo->query('SELECT COUNT(*) FROM crm_contracts')->fetchColumn() === $before, 'GET never changes data');
$r = $page('xss'); ok(strpos($r['body'], '<script>alert(1)</script>') === false && stripos($r['body'], '&lt;script&gt;alert(1)&lt;/script&gt;') !== false, 'a hostile display name is escaped: ' . (preg_match('/admTop.*?<\/header>/s', $r['body'], $mm) ? substr(strip_tags($mm[0]), 0, 120) : ''));
$aud = $pdo->query("SELECT action, COUNT(*) n FROM crm_audit_log GROUP BY action")->fetchAll(PDO::FETCH_KEY_PAIR);
ok(($aud['admin_center_view'] ?? 0) >= 1 && ($aud['admin_center_denied'] ?? 0) >= 1 && ($aud['login'] ?? 0) >= 5, 'sign-ins, overview views and denied attempts are in the audit log (' . json_encode($aud) . ')');
$views = (int)$pdo->query("SELECT COUNT(*) FROM crm_audit_log WHERE action = 'admin_center_view'")->fetchColumn(); for ($i = 0; $i < 3; $i++) $page('owner'); ok((int)$pdo->query("SELECT COUNT(*) FROM crm_audit_log WHERE action = 'admin_center_view'")->fetchColumn() === $views, 'repeated page loads do not flood the audit log');
$r = http('POST', "$base/admin-center.php", ['act' => 'logout', '_csrf' => $csrf], $own, $jars['owner']); ok($r['code'] === 303, 'sign-out with the token works'); $r = http('GET', "$base/admin-center.php", null, [], $jars['owner']); ok(stripos($r['body'], 'Control Center sections') === false, 'after sign-out the page asks for sign-in again');
$bf = http('GET', "$base/admin-center.php", null, [], null); ok(stripos($bf['body'], 'Control Center sections') === false, 'no cookie, no content');
ok(!in_array('admin-center.php', array_map('trim', file("$root/sitemap.xml", FILE_IGNORE_NEW_LINES) ?: []), true) && strpos((string)@file_get_contents("$root/robots.txt"), 'Disallow: /admin-center.php') !== false, 'robots.txt disallows the Control Center');
ok(http('GET', "$base/adm_core.php")['code'] !== 200 || stripos(http('GET', "$base/adm_core.php")['body'], 'adm_role_perms') === false, 'the library is not executable or readable through the web');

section('permission map is separate from the CRM roles');
require_once $root . '/adm_core.php';
ok(array_keys(adm_role_perms()) === ['owner', 'sales', 'viewer', 'editor', 'verifier'] && !adm_can('nobody', 'center') && !adm_can(null, 'center') && !adm_can('viewer', 'finance'), 'unknown roles and missing permissions are denied');
ok(crm_roles()['editor'] === ['uni_propose'] && crm_roles()['viewer'] === ['read'], 'crm_roles() is unchanged');

// optional browser accessibility check (needs playwright + axe-core): desktop, tablet, mobile, light and dark
$axe = getenv('AXE_PATH'); if ($axe && is_file("$root/tests/browser_phase8a_test.js")) {
  section('browser: axe, keyboard, responsive');
  exec('cd ' . escapeshellarg($root) . ' && node tests/browser_phase8a_test.js ' . escapeshellarg($base) . ' t8owner admin-center-test-pw-1 2>&1', $o, $rc);
  $line = (string)end($o); ok($rc === 0, 'browser checks passed: ' . substr(implode(' | ', $o), 0, 1800));
} else fwrite(STDERR, "  (browser/axe check skipped: set AXE_PATH and NODE_PATH to run it)\n");
echo "\nPASSED: $pass  FAILED: $fail\n"; exit($fail ? 1 : 0);
