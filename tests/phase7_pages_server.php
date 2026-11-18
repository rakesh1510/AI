<?php
/**
 * Fixture for tests/browser_phase7_test.js: builds a scratch database p7bt_<pid>, seeds the SYNTHETIC ledger (tests/fixtures/phase7_pages_seed.php) through the service layer,
 * creates synthetic CRM users for the editor and verifier pages, serves the app with tests/router.php on a free port in 8270-8290 and prints one JSON line
 * {"port":..,"db":..,"users":{..},"password":..}. It then waits until its standard input is closed, stops the server and drops the database.
 * Env: AIHUB_COMPAT_ROOT_USER / AIHUB_COMPAT_ROOT_PASS / AIHUB_DB_HOST. Never touches another database or a remote host.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
ob_start(); require_once $root . '/uni_core.php'; require_once __DIR__ . '/fixtures/phase7_pages_seed.php'; ob_end_clean();
$dbHost = getenv('AIHUB_DB_HOST') ?: '127.0.0.1';
$dbUser = getenv('AIHUB_COMPAT_ROOT_USER') ?: 'root';
$dbPass = getenv('AIHUB_COMPAT_ROOT_PASS') !== false ? (string)getenv('AIHUB_COMPAT_ROOT_PASS') : 'root_test_only';
$dbName = 'p7bt_' . getmypid();
$opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$admin = new PDO("mysql:host=$dbHost;port=3306", $dbUser, $dbPass, $opt);
$admin->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo = new PDO("mysql:host=$dbHost;port=3306;dbname=$dbName", $dbUser, $dbPass, $opt);
$proc = null; $rateDir = sys_get_temp_dir() . '/p7bt_rate_' . getmypid(); @mkdir($rateDir, 0700, true);
$cleanup = function () use (&$proc, $admin, $dbName, $rateDir) {
  if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); $proc = null; }
  try { $admin->exec("DROP DATABASE IF EXISTS `$dbName`"); } catch (Throwable $e) {}
  foreach (glob($rateDir . '/*') ?: [] as $f) @unlink($f); @rmdir($rateDir);
};
register_shutdown_function($cleanup);
if (function_exists('pcntl_signal')) { pcntl_async_signals(true); foreach ([SIGTERM, SIGINT] as $s) pcntl_signal($s, function () use ($cleanup) { $cleanup(); exit(0); }); }
try {
  p7s_schema($pdo, $root); p7_seed($pdo);
  $H = password_hash('browser-test-password-1', PASSWORD_DEFAULT); $users = [];
  foreach (['owner' => 'owner', 'editor' => 'editor', 'verifier' => 'verifier'] as $n => $role) { [$ok, $id] = crm_user_create($pdo, "pgbt-$n", "Browser $n", $role, $H); if (!$ok) throw new RuntimeException("user $n: $id"); $users[$n] = "pgbt-$n"; }
  $port = 0; foreach (range(8270, 8290) as $p) { $s = @stream_socket_server("tcp://127.0.0.1:$p", $en, $es); if ($s) { fclose($s); $port = $p; break; } }
  if (!$port) throw new RuntimeException('no free port in 8270-8290');
  $env = array_merge(getenv(), ['AIHUB_DB_HOST' => $dbHost, 'AIHUB_DB_NAME' => $dbName, 'AIHUB_DB_USER' => $dbUser, 'AIHUB_DB_PASS' => $dbPass, 'AIHUB_RATE_DIR' => $rateDir, 'AIHUB_SECURITY_LOG' => sys_get_temp_dir() . '/p7bt_sec_' . getmypid() . '.log', 'AIHUB_FORM_SECRET' => 'p7bt-secret-not-for-production', 'AIHUB_UNIVERSE_PAGE_RATE_LIMIT' => '10000']);
  $proc = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'output_buffering=0', '-S', "127.0.0.1:$port", '-t', $root, "$root/tests/router.php"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env);
  for ($i = 0; $i < 60; $i++) { $c = @fsockopen('127.0.0.1', $port); if ($c) { fclose($c); break; } usleep(100000); }
  echo json_encode(['port' => $port, 'db' => $dbName, 'users' => $users, 'password' => 'browser-test-password-1']) . "\n"; fflush(STDOUT);
  stream_set_blocking(STDIN, false);
  while (true) { $r = fread(STDIN, 1024); if ($r === '' && feof(STDIN)) break; usleep(200000); }
} catch (Throwable $e) { fwrite(STDERR, 'fixture failed: ' . $e->getMessage() . "\n"); $cleanup(); exit(1); }
$cleanup();
