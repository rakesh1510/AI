<?php
/**
 * Phase 8D Finance HTTP verification (manual only; never part of routine CI): a real PHP server, real sessions, CSRF and audit rows, on a DISPOSABLE local scratch database.
 * Checks owner-only access for every page and export, CSRF and same-origin, methods, audit logging, validation, refunds, expenses, targets, escaping, headers,
 * and (with AXE_PATH and Playwright) accessibility and responsive checks of the real Finance pages.
 * Usage: P7_USER=root P7_PASS=... php tests/phase8d_finance_http_test.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__); chdir($root); ob_start(); require_once $root . '/crm_core.php'; ob_end_clean();
$dbHost = getenv('AIHUB_DB_HOST') ?: '127.0.0.1'; if (!in_array($dbHost, ['127.0.0.1', 'localhost'], true)) { fwrite(STDERR, "Refusing: local disposable server only.\n"); exit(2); }
$user = getenv('P7_USER') ?: (getenv('AIHUB_COMPAT_ROOT_USER') ?: 'root'); $pw = getenv('P7_PASS') !== false ? getenv('P7_PASS') : (getenv('AIHUB_COMPAT_ROOT_PASS') !== false ? getenv('AIHUB_COMPAT_ROOT_PASS') : '');
$db = 'p8dh_' . getmypid() . '_test'; $opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$admin = new PDO("mysql:host=$dbHost;port=3306", $user, $pw, $opt); if (stripos((string)$admin->query('SELECT VERSION()')->fetchColumn(), 'mariadb') !== false) { fwrite(STDERR, "Refusing: MariaDB.\n"); exit(2); }
$admin->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $pdo = new PDO("mysql:host=$dbHost;port=3306;dbname=$db", $user, $pw, $opt);
$pass = 0; $fail = 0; $proc = null; $tmp = sys_get_temp_dir() . '/p8dh_' . getmypid(); @mkdir("$tmp/rate", 0775, true);
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; fwrite(STDERR, "  FAIL: $m\n"); } } function section(string $t): void { fwrite(STDERR, "== $t\n"); }
register_shutdown_function(function () use ($admin, $db, &$proc, $tmp) { if (is_resource($proc)) { @proc_terminate($proc); @proc_close($proc); } try { $admin->exec("DROP DATABASE IF EXISTS `$db`"); } catch (Throwable $e) {} shell_exec('rm -rf ' . escapeshellarg($tmp)); });
function stmts(string $file): array { $b = ''; $o = []; foreach (preg_split('/\R/', (string)file_get_contents($file)) as $l) { $t = trim($l); if ($b === '' && ($t === '' || str_starts_with($t, '--'))) continue; $b .= $l . "\n"; if (str_ends_with(rtrim($b), ';')) { $o[] = substr(rtrim($b), 0, -1); $b = ''; } } return $o; }
foreach (stmts("$root/database/phase6_crm.sql") as $s) $pdo->exec($s); foreach (stmts("$root/database/phase8d_finance.sql") as $s) $pdo->exec($s);
$sub = file_get_contents("$root/database/full_mysql_schema.sql"); preg_match('/CREATE TABLE IF NOT EXISTS submissions \(.*?\n\);/s', $sub, $m); $pdo->exec(rtrim($m[0], ';'));
$H = password_hash('admin-center-test-pw-1', PASSWORD_DEFAULT); $mkU = function (string $n, string $dn, string $r) use ($pdo, $H) { [$o, $id] = crm_user_create($pdo, $n, $dn, $r, $H); if (!$o) throw new RuntimeException("user $n"); return (int)$id; };
foreach (['owner', 'sales', 'viewer', 'editor', 'verifier'] as $r) $mkU("t8$r", ucfirst($r) . ' Test', $r);
// synthetic CRM data with hostile text, EUR and USD
$pdo->exec("INSERT INTO crm_companies (name, created_at, updated_at) VALUES ('<script>alert(1)</script> Ltd', NOW(), NOW()), ('Plain Co', NOW(), NOW())");
$pdo->exec("INSERT INTO crm_opportunities (company_id, kind, title, stage, created_at, updated_at) VALUES (1, 'development', 'x', 'won', NOW(), NOW()), (2, 'advertising', 'y', 'won', NOW(), NOW())");
$pdo->exec("INSERT INTO crm_contracts (opportunity_id, company_id, status, start_date, billing_period, amount_minor, currency, confirmed_at, created_at) VALUES (1, 1, 'active', '2026-06-01', 'monthly', 500000, 'EUR', '2026-05-01 10:00:00', NOW()), (2, 2, 'active', '2026-09-01', 'annual', 120000, 'USD', '2026-08-01 10:00:00', NOW())");
$pdo->exec("INSERT INTO crm_revenue_entries (contract_id, kind, amount_minor, currency, entry_date, created_at) VALUES (1,'invoiced',500000,'EUR','2026-10-02',NOW()),(1,'received',500000,'EUR','2026-10-12',NOW()),(1,'invoiced',500000,'EUR','2026-11-02',NOW()),(2,'invoiced',120000,'USD','2026-09-03',NOW())");
$port = 8420; for (; $port < 8440; $port++) { $s = @stream_socket_server("tcp://127.0.0.1:$port"); if ($s) { fclose($s); break; } }
$env = ['AIHUB_DB_HOST' => $dbHost, 'AIHUB_DB_NAME' => $db, 'AIHUB_DB_USER' => $user, 'AIHUB_DB_PASS' => $pw, 'AIHUB_FORM_SECRET' => 'p8d-test-secret-not-for-production', 'AIHUB_SECURITY_LOG' => "$tmp/sec.log", 'AIHUB_RATE_DIR' => "$tmp/rate"]; foreach (['AIHUB_ANALYTICS', 'AIHUB_ANALYTICS_SALT'] as $k) putenv($k);
$proc = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root, "$root/tests/router.php"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', "$tmp/server.err", 'w']], $pp, $root, $env + getenv()); for ($i = 0; $i < 60; $i++) { usleep(100000); if (@fsockopen('127.0.0.1', $port)) break; }
$base = "http://127.0.0.1:$port"; $own = ["Origin: $base"];
function http(string $m, string $u, $body = null, array $h = [], ?string $jar = null): array { $ch = curl_init($u); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CUSTOMREQUEST => $m, CURLOPT_HTTPHEADER => $h, CURLOPT_FOLLOWLOCATION => false]); if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body); if ($jar) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); } $r = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch); return ['code' => $code, 'head' => substr($r, 0, $hs), 'body' => substr($r, $hs), 'loc' => preg_match('/^Location:\s*(.+)$/mi', substr($r, 0, $hs), $mm) ? trim($mm[1]) : '']; }
function tok(string $html, string $name): string { return preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $m) ? html_entity_decode($m[1]) : ''; }
function login(string $base, array $own, string $u): string { global $tmp; foreach ((array)glob("$tmp/rate/*") as $f) @unlink($f); $jar = tempnam(sys_get_temp_dir(), 'p8d'); $p = http('GET', "$base/admin-center.php", null, [], $jar); $t = tok($p['body'], '_login_token'); usleep(1300000); http('POST', "$base/admin-center.php", ['act' => 'login', 'username' => $u, 'password' => 'admin-center-test-pw-1', '_login_token' => $t], $own, $jar); return $jar; }
$jars = []; foreach (['owner', 'sales', 'viewer', 'editor', 'verifier'] as $r) $jars[$r] = login($base, $own, "t8$r");
$page = fn(string $r, string $q = '') => http('GET', "$base/admin-center.php$q", null, [], $jars[$r]); $csrf = fn(string $r) => tok($page($r)['body'], '_csrf');
$audits = fn(string $like) => (int)$pdo->query("SELECT COUNT(*) FROM crm_audit_log WHERE action LIKE '$like'")->fetchColumn(); $exp = fn() => (int)$pdo->query('SELECT COUNT(*) FROM fin_expense_entry')->fetchColumn();
$today = gmdate('Y-m-d'); $views = ['', '&view=revenue', '&view=advertising', '&view=projects', '&view=expenses', '&view=refunds', '&view=targets', '&view=exports'];

section('anonymous');
$a = http('GET', "$base/admin-center.php?m=finance"); ok(stripos($a['body'], 'sign-in') !== false && stripos($a['body'], 'Net income') === false && stripos($a['body'], 'Finance sections') === false, 'anonymous sees only the sign-in form');
$x = http('POST', "$base/admin-center.php?m=finance", ['act' => 'fin_expense_add'] + ['entry_date' => '2026-12-01'], $own); ok($exp() === 0 && !str_contains($x['body'], 'Expense recorded'), 'an anonymous POST records nothing');

section('owner-only: every page and every role');
foreach ($views as $v) { $o = $page('owner', "?m=finance$v"); ok($o['code'] === 200 && strpos($o['body'], 'Finance sections') !== false && strpos($o['body'], 'Not tracked') !== false && strpos($o['body'], '<script>alert') === false, "owner opens finance$v (escaped hostile company name)"); }
foreach (['sales', 'viewer', 'editor', 'verifier'] as $r) { foreach ($views as $v) { $x = $page($r, "?m=finance$v"); ok($x['code'] === 403 && strpos($x['body'], 'Finance sections') === false && stripos($x['body'], 'Net income') === false && strpos($x['body'], 'Plain Co') === false, "$r gets 403 and no figures for finance$v"); } }
ok($audits('admin_center_denied') >= 4 * 8, 'every denied attempt is written to the audit log');
foreach (array_keys(['revenue' => 1, 'streams' => 1, 'expenses' => 1, 'pnl' => 1, 'adjustments' => 1, 'targets' => 1]) as $k) { $c = $page('owner', "?m=finance&export=$k"); ok($c['code'] === 200 && stripos($c['head'], 'text/csv') !== false && stripos($c['head'], 'attachment') !== false && stripos($c['head'], 'no-store') !== false && stripos($c['head'], 'noindex') !== false && strpos($c['body'], 'data_state') !== false, "owner export $k: csv, attachment, no-store, noindex"); foreach (['editor', 'sales'] as $r) { $x = $page($r, "?m=finance&export=$k"); ok($x['code'] === 403 && strpos($x['body'], 'data_state') === false, "$r cannot export $k"); } }
ok($page('owner', '?m=finance&export=nope')['code'] === 404 && $page('owner', '?m=finance&export[]=x')['code'] === 404, 'unknown or malformed export is 404');
ok($audits('admin_center_export') === 6, 'each export is audited');
$o = $page('owner', '?m=finance'); ok(stripos($o['head'], 'noindex') !== false || stripos($o['body'], 'noindex') !== false, 'finance pages are noindex'); ok(stripos($o['head'], 'no-store') !== false || stripos($o['head'], 'no-cache') !== false || stripos($o['head'], 'private') !== false, 'finance pages are not cacheable by shared caches');

section('CSRF, same-origin and methods');
$good = ['act' => 'fin_expense_add', 'entry_date' => $today, 'category' => 'marketing', 'amount' => '120.50', 'currency' => 'EUR', 'description' => 'Ads <b>test</b>', 'recurrence' => 'none']; $t = $csrf('owner'); ok($t !== '', 'the owner page carries a CSRF token');
$x = http('POST', "$base/admin-center.php?m=finance", $good, $own, $jars['owner']); ok($x['code'] === 403 && $exp() === 0, 'POST without a token: 403, nothing recorded');
$x = http('POST', "$base/admin-center.php?m=finance", $good + ['_csrf' => 'wrong'], $own, $jars['owner']); ok($x['code'] === 403 && $exp() === 0, 'POST with a wrong token: 403');
$x = http('POST', "$base/admin-center.php?m=finance", $good + ['_csrf' => $t], ['Origin: https://evil.example'], $jars['owner']); ok($x['code'] === 403 && $exp() === 0, 'POST with the right token from another origin: 403');
$x = http('POST', "$base/admin-center.php?m=finance", $good + ['_csrf' => $csrf('editor')], $own, $jars['owner']); ok($x['code'] === 403 && $exp() === 0, 'a token from another session is rejected');
$x = http('PUT', "$base/admin-center.php?m=finance", $good + ['_csrf' => $t], $own, $jars['owner']); ok($x['code'] === 405 && $exp() === 0, 'other methods: 405');
$x = http('GET', "$base/admin-center.php?m=finance&act=fin_expense_add&amount=5&category=other&entry_date=2026-12-01&currency=EUR&description=getattack", null, [], $jars['owner']); ok($exp() === 0, 'a GET never changes data');
$x = http('POST', "$base/admin-center.php?m=finance", $good + ['_csrf' => $t], $own, $jars['owner']); ok($x['code'] === 303 && strpos($x['loc'], 'view=expenses') !== false && strpos($x['loc'], 'msg=expense_added') !== false && $exp() === 1, 'owner POST with token: 303 and one expense (got ' . $x['code'] . ' ' . substr(trim(preg_replace('/\s+/', ' ', strip_tags($x['body']))), 0, 160) . ')');
$row = $pdo->query('SELECT * FROM fin_expense_entry')->fetch(); ok($row['amount_minor'] == 12050 && strpos($row['description'], '<b>') === false && $row['created_by'] !== null, 'stored in minor units, markup stripped, creator recorded');
ok($audits('finance_expense_add') === 1 && strpos((string)$pdo->query("SELECT summary FROM crm_audit_log WHERE action = 'finance_expense_add'")->fetchColumn(), '120.50') !== false && (string)$pdo->query("SELECT username FROM crm_audit_log WHERE action = 'finance_expense_add'")->fetchColumn() === 't8owner', 'the audit row names the owner, the action and the amount');
foreach (['editor', 'sales', 'viewer', 'verifier'] as $r) { $x = http('POST', "$base/admin-center.php?m=finance", $good + ['_csrf' => $csrf($r)], $own, $jars[$r]); ok($x['code'] === 403 && $exp() === 1, "$r POST with a valid token of their own session is still 403"); }
ok($audits('finance_denied') >= 4, 'non-owner write attempts are audited as denied');

section('validation and workflows over HTTP');
$bad = http('POST', "$base/admin-center.php?m=finance", ['amount' => 'abc'] + $good + ['_csrf' => $t], $own, $jars['owner']); ok($bad['code'] === 422 && strpos($bad['body'], 'role="alert"') !== false && strpos($bad['body'], 'aria-invalid="true"') !== false && $exp() === 1, 'a validation error re-renders the form accessibly and stores nothing');
$id = (int)$pdo->query('SELECT id FROM fin_expense_entry')->fetchColumn(); $x = http('POST', "$base/admin-center.php?m=finance", ['act' => 'fin_expense_void', 'id' => $id, 'reason' => 'entered twice', '_csrf' => $t], $own, $jars['owner']); ok($x['code'] === 303 && $pdo->query("SELECT voided_at FROM fin_expense_entry WHERE id = $id")->fetchColumn() !== null && $exp() === 1, 'void keeps the row');
$x = http('POST', "$base/admin-center.php?m=finance", ['act' => 'fin_adjustment_add', 'kind' => 'refund', 'contract_id' => 1, 'amount' => '100', 'entry_date' => $today, 'reference' => 'RF-1', '_csrf' => $t], $own, $jars['owner']); ok($x['code'] === 303 && (int)$pdo->query('SELECT COUNT(*) FROM fin_adjustment')->fetchColumn() === 1 && $audits('finance_adjustment_add') === 1, 'refund recorded over HTTP and audited');
$x = http('POST', "$base/admin-center.php?m=finance", ['act' => 'fin_adjustment_add', 'kind' => 'refund', 'contract_id' => 1, 'amount' => '999999', 'entry_date' => $today, '_csrf' => $t], $own, $jars['owner']); ok($x['code'] === 422 && (int)$pdo->query('SELECT COUNT(*) FROM fin_adjustment')->fetchColumn() === 1, 'a refund above what was received is refused (422)');
foreach ([5, 6] as $_) http('POST', "$base/admin-center.php?m=finance", ['act' => 'fin_target_set', 'month' => '2026-12', 'metric' => 'received', 'amount' => (string)($_ * 1000), '_csrf' => $t], $own, $jars['owner']); ok((int)$pdo->query("SELECT COUNT(*) FROM fin_target WHERE superseded_at IS NULL")->fetchColumn() === 1 && (int)$pdo->query('SELECT COUNT(*) FROM fin_target')->fetchColumn() === 2, 'target set twice over HTTP: two rows, one active');
$p = $page('owner', '?m=finance&view=targets&calc=1&v_contract=2000&m_contract=100&close_rate=20'); ok(strpos($p['body'], 'Scenario results') !== false && strpos($p['body'], '>490<') !== false && strpos($p['body'], 'Hypothetical') !== false, 'the scenario calculator renders over HTTP');
$p = $page('owner', '?m=finance&view=refunds'); ok(strpos($p['body'], 'RF-1') !== false && strpos($p['body'], 'fin_adjustment_void') !== false, 'the refund appears with a void form');
$p = $page('owner', '?m=finance'); ok(strpos($p['body'], '5,000.00 EUR') !== false && strpos($p['body'], 'EUR') !== false && strpos($p['body'], 'USD') !== false, 'the overview shows EUR MRR (5,000.00) from the real CRM contract, and USD is mentioned separately');
$u = $page('owner', '?m=finance&view=revenue'); ok(strpos($u['body'], 'id="rv-EUR"') !== false && strpos($u['body'], 'id="rv-USD"') !== false, 'revenue shows EUR and USD as separate sections');
$p = $page('owner', '?m=finance&view=expenses'); ok(strpos($p['body'], '<script>alert') === false && strpos($p['body'], 'Voided') !== false, 'voided expense is shown as voided');

section('direct access and headers');
foreach (['adm_finance_core.php', 'adm_finance_store.php', 'adm_finance_charts.php', 'adm_finance_views.php', 'database/phase8d_finance.sql', 'tests/phase8d_finance_test.php'] as $f) { $x = http('GET', "$base/$f"); ok($x['code'] !== 200 || (strpos($x['body'], 'AdmFinance') === false && strpos($x['body'], 'CREATE TABLE') === false), "direct request for $f does not expose code or SQL (HTTP {$x['code']})"); }
$sv = file_exists("$tmp/server.err") ? (string)file_get_contents("$tmp/server.err") : ''; ok(strpos($sv, 'Fatal') === false && strpos($sv, 'Warning') === false && strpos($sv, 'Notice') === false, 'the server logged no PHP warnings or errors' . (strpos($sv, 'Warning') !== false ? ': ' . substr($sv, 0, 300) : ''));
$sl = file_exists("$tmp/sec.log") ? (string)file_get_contents("$tmp/sec.log") : ''; ok(strpos($sl, 'admin-center-test-pw') === false && strpos($sl, 'wrong') === false, 'no secrets or tokens in the security log');

$axe = getenv('AXE_PATH'); if ($axe && is_file("$root/tests/browser_phase8a_test.js")) {
  section('browser: axe, keyboard and responsive checks of the REAL finance pages (3 sizes, light and dark)'); $o = []; putenv('EXTRA_PATHS=' . implode(',', array_map(fn($v) => "/admin-center.php?m=finance$v", $views)));
  exec('cd ' . escapeshellarg($root) . ' && node tests/browser_phase8a_test.js ' . escapeshellarg($base) . ' t8owner admin-center-test-pw-1 2>&1', $o, $rc); $line = ''; foreach ($o as $l) { if (strpos($l, 'FAIL') !== false) fwrite(STDERR, "$l\n"); if (strpos($l, 'browser:') !== false) $line = $l; }
  ok($rc === 0 && strpos($line, 'FAILED 0') !== false, 'real finance pages pass axe, overflow and target checks: ' . $line);
}
echo "\nPASSED: $pass  FAILED: $fail\n"; exit($fail ? 1 : 0);
