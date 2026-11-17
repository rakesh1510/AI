<?php
/**
 * Database compatibility scenarios using ONLY synthetic, throw-away databases (names contain "test") built from the repository's own SQL
 * files. For each scenario the read-only preflight (scripts/db_preflight.php) must report the expected gaps, and a private php -S
 * instance running against that database must keep every page and endpoint away from HTTP 500 (graceful degradation).
 * Never touches the live "aihub" database. Usage: php tests/db_compat_test.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = realpath(__DIR__ . '/..');
$H = getenv('AIHUB_DB_HOST') ?: '127.0.0.1'; $U = getenv('AIHUB_DB_USER') ?: 'aihub_test'; $P = getenv('AIHUB_DB_PASS') ?: 'test_only_pw';
$RU = getenv('AIHUB_COMPAT_ROOT_USER') ?: $U; $RP = getenv('AIHUB_COMPAT_ROOT_PASS') ?: $P;   // CI: the test user cannot create databases, the throw-away service root can
$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "  FAIL: $m\n"; } }
function section(string $t): void { echo "== $t\n"; }
$mysql = fn(string $sql, string $db = '') => shell_exec('mysql -h' . escapeshellarg($H) . ' -u' . escapeshellarg($RU) . ' -p' . escapeshellarg($RP) . ($db !== '' ? ' ' . escapeshellarg($db) : '') . ' -e ' . escapeshellarg($sql) . ' 2>&1');
$mysqlFile = fn(string $file, string $db) => shell_exec('mysql -h' . escapeshellarg($H) . ' -u' . escapeshellarg($RU) . ' -p' . escapeshellarg($RP) . ' ' . escapeshellarg($db) . ' < ' . escapeshellarg($file) . ' 2>&1');
function sqlOf(string $file, ?string $from = null, ?string $to = null): string { $s = (string)file_get_contents($file); if ($from !== null) { $i = strpos($s, $from); $s = $i === false ? '' : substr($s, $i); } return $s; }
$coreSchema = function () use ($root) { $s = (string)file_get_contents("$root/database/full_mysql_schema.sql"); $a = substr($s, 0, strpos($s, 'CREATE TABLE IF NOT EXISTS tool_clicks')); $b = substr($s, strpos($s, 'CREATE TABLE IF NOT EXISTS submissions')); return $a . "\n" . $b; };
$tmp = sys_get_temp_dir() . '/aihub_compat_' . getmypid(); @mkdir($tmp, 0700, true);
$write = function (string $name, string $sql) use ($tmp) { file_put_contents("$tmp/$name", $sql); return "$tmp/$name"; };
$useCaseCols = "ALTER TABLE use_cases ADD icon VARCHAR(20) NULL, ADD description TEXT NULL, ADD hero_text TEXT NULL, ADD audience TEXT NULL, ADD popular_tasks LONGTEXT NULL, ADD example_tools LONGTEXT NULL, ADD benefits LONGTEXT NULL;";
$fixture = (string)file_get_contents("$root/tests/fixtures/interactions_current.sql");
$contactOnly = substr($fixture, strpos($fixture, 'CREATE TABLE IF NOT EXISTS contact_messages'));
$files = fn(array $names) => implode("\n", array_map(fn($n) => (string)file_get_contents("$root/database/$n"), $names));

$scenarios = [
  's1_repo_install_only' => ['label' => 'A database created only from the committed install SQL', 'sql' => fn() => $coreSchema() . "\nCREATE TABLE IF NOT EXISTS tool_clicks (id INT AUTO_INCREMENT PRIMARY KEY, tool_slug VARCHAR(255) NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(1000) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);\n" . (string)file_get_contents("$root/database/aihub_interactions.sql"),
    'expect' => ['tool_clicks' => 'incompatible', 'tool_votes' => 'incompatible', 'leads' => 'missing_table', 'subscribers' => 'missing_table', 'events' => 'missing_table', 'contact_messages' => 'missing_table', 'compare_tool_profiles' => 'missing_table', 'categories' => 'ok', 'tools' => 'ok'], 'broken' => true, 'import' => true],
  's2_phase2_like' => ['label' => 'Plausible Phase 2 state: Phase 1/2 SQL plus the votes migration, old clicks table', 'sql' => fn() => $coreSchema() . "\n" . $useCaseCols . "\nCREATE TABLE IF NOT EXISTS tool_clicks (id INT AUTO_INCREMENT PRIMARY KEY, tool_slug VARCHAR(255) NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(1000) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);\n" . (string)file_get_contents("$root/database/aihub_interactions.sql") . "\n" . $files(['install_compare_tables.sql', 'phase2_analytics.sql']) . "\n" . $contactOnly,
    'expect' => ['tool_clicks' => 'incompatible', 'tool_votes' => 'incompatible', 'page_views' => 'ok', 'leads' => 'missing_table', 'contact_messages' => 'ok', 'compare_tool_profiles' => 'ok'], 'broken' => true, 'import' => true],
  's1p_repo_install_after_plan' => ['label' => 'Scenario 1 after applying the preflight migration plan (synthetic copy)', 'sql_from' => 's1_repo_install_only', 'apply_plan' => true,
    'expect' => ['tool_clicks' => 'ok', 'tool_votes' => 'ok', 'leads' => 'missing_table'], 'broken' => false, 'import' => true],
  's2p_phase2_after_plan' => ['label' => 'Scenario 2 after applying the preflight migration plan (synthetic copy)', 'sql_from' => 's2_phase2_like', 'apply_plan' => true,
    'expect' => ['tool_clicks' => 'ok', 'tool_votes' => 'ok', 'contact_messages' => 'ok'], 'broken' => false, 'import' => true],
  's3_phase3_applied' => ['label' => 'Current shape with Phase 3 SQL, without Phase 4', 'sql' => fn() => $coreSchema() . "\n" . $useCaseCols . "\n" . $fixture . "\n" . $files(['install_compare_tables.sql', 'phase2_analytics.sql', 'phase3_business.sql']),
    'expect' => ['tool_clicks' => 'ok', 'tool_votes' => 'ok', 'leads' => 'needs_phase4_migration', 'lead_status_history' => 'missing_table', 'subscribers' => 'ok', 'events' => 'ok'], 'broken' => false, 'import' => true],
  's4_current_all' => ['label' => 'Current shape with every SQL file (control)', 'sql' => fn() => $coreSchema() . "\n" . $useCaseCols . "\n" . $fixture . "\n" . $files(['install_compare_tables.sql', 'phase2_analytics.sql', 'phase3_business.sql', 'phase4_business.sql']),
    'expect' => ['tool_clicks' => 'ok', 'tool_votes' => 'ok', 'leads' => 'ok', 'lead_status_history' => 'ok', 'subscribers' => 'ok'], 'broken' => false, 'import' => true],
  's5_empty_database' => ['label' => 'Empty database (no tables)', 'sql' => fn() => '', 'expect' => ['tools' => 'missing_table', 'leads' => 'missing_table'], 'broken' => true, 'import' => false],
];

function http(string $m, string $u, $body = null, array $h = [], ?string $jar = null): array {
  $ch = curl_init($u); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $m, CURLOPT_HTTPHEADER => $h, CURLOPT_FOLLOWLOCATION => false]);
  if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
  if ($jar) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
  $r = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
  return ['code' => $code, 'head' => substr($r, 0, $hs), 'body' => substr($r, $hs)];
}
$port = 8090;
foreach ($scenarios as $key => $sc) {
  $db = 'aihub_compat_' . substr($key, 0, 2) . '_test'; $port++;
  section("{$sc['label']} ($db)");
  $mysql("DROP DATABASE IF EXISTS `$db`; CREATE DATABASE `$db` CHARACTER SET utf8mb4;");
  if ($RU !== $U) $mysql("GRANT ALL ON `$db`.* TO '" . $U . "'@'%'; FLUSH PRIVILEGES;");
  $sql = isset($sc['sql_from']) ? ($scenarios[$sc['sql_from']]['sql'])() : ($sc['sql'])();
  if ($sql !== '') { $out = (string)$mysqlFile($write("$key.sql", $sql), $db); ok(strpos($out, 'ERROR') === false, "scenario SQL applies cleanly" . ($out ? ": $out" : '')); }
  $env = "AIHUB_DB_HOST=" . escapeshellarg($H) . " AIHUB_DB_NAME=$db AIHUB_DB_USER=" . escapeshellarg($U) . " AIHUB_DB_PASS=" . escapeshellarg($P);
  if ($sc['import']) shell_exec("cd " . escapeshellarg($root) . " && $env php import-json-to-mysql.php 2>&1");
  if (!empty($sc['apply_plan'])) {
    $pl = json_decode((string)shell_exec("cd " . escapeshellarg($root) . " && $env php scripts/db_preflight.php --json 2>/dev/null"), true)['plan'] ?? [];
    $stmts = array_values(array_filter($pl, fn($l) => strpos(ltrim($l), '--') !== 0));
    if (getenv('AIHUB_COMPAT_PRINT_PLAN')) echo "-- plan for $key:\n" . implode("\n", $pl) . "\n";
    ok(count($stmts) >= 3, 'the plan contains executable SQL for the interaction tables (' . count($stmts) . ' statements)');
    $out = (string)$mysqlFile($write("$key-plan.sql", implode("\n", $stmts)), $db); ok(strpos($out, 'ERROR') === false, 'the generated plan applies cleanly to the synthetic copy' . ($out ? ": $out" : ''));
  }
  $pre = json_decode((string)shell_exec("cd " . escapeshellarg($root) . " && $env php scripts/db_preflight.php --json 2>/dev/null"), true);
  ok(is_array($pre) && ($pre['read_only'] ?? false) === true, 'preflight ran and declares itself read-only');
  $by = $pre ? array_column($pre['rows'], null, 'table') : [];
  foreach ($sc['expect'] as $t => $st) ok(($by[$t]['status'] ?? '?') === $st, "preflight: $t is $st (got " . ($by[$t]['status'] ?? '?') . ')');
  ok(($pre && $pre['broken'] > 0) === $sc['broken'], 'preflight verdict: ' . ($sc['broken'] ? 'required features broken' : 'all required features compatible'));
  if ($key === 's1_repo_install_only') { $rep = (string)shell_exec("cd " . escapeshellarg($root) . " && $env php scripts/db_preflight.php 2>&1"); ok(strpos($rep, 'visitor_id') !== false && strpos($rep, 'Nothing was changed') !== false, 'the text report names the missing columns and states that nothing was changed'); }
  // server for this scenario
  $rate = "$tmp/rate_$key"; @mkdir($rate, 0700, true);
  $hash = password_hash('compat-admin-pw', PASSWORD_DEFAULT);
  $cmd = "cd " . escapeshellarg($root) . " && $env AIHUB_RATE_DIR=" . escapeshellarg($rate) . " AIHUB_CACHE_DIR=" . escapeshellarg("$tmp/cache_$key") . " AIHUB_ADMIN_PASS_HASH=" . escapeshellarg($hash) . " AIHUB_ANALYTICS=1 php -d log_errors=1 -d error_log=" . escapeshellarg("$tmp/err_$key.log") . " -S 127.0.0.1:$port -t . tests/router.php > /dev/null 2>&1 & echo $!";
  $pid = (int)trim((string)shell_exec($cmd)); usleep(1200000);
  $b = "http://127.0.0.1:$port";
  foreach (['/', '/tool.php?id=chatgpt', '/search.php?q=pdf', '/category.php?name=Chatbots', '/compare.php?a=chatgpt&b=claude', '/recommend.php?go=1&objective=meetings&budget=flexible', '/solutions.php', '/solutions.php?problem=transcribe-meetings-and-interviews', '/advertise.php', '/business.php', '/changelog.php', '/sitemap.xml', '/top.php', '/leaderboard.php', '/blog.php', '/ai-use-case-explorer.php', '/favorites.php'] as $u) {
    $r = http('GET', $b . $u);
    ok($r['code'] === 200 && !preg_match('/Fatal error|Uncaught|SQLSTATE|Stack trace/', $r['body']), "GET $u works (HTTP {$r['code']})");
  }
  $r = http('GET', "$b/redirect.php?id=chatgpt"); ok($r['code'] === 302 && preg_match('#^Location: https?://#mi', $r['head']), "outbound redirect still works (HTTP {$r['code']})");
  $page = http('GET', "$b/tool.php?id=chatgpt"); preg_match('/name="csrf-token" content="([^"]+)"/', $page['body'], $m); $tok = $m[1] ?? '';
  $own = ['Origin: http://127.0.0.1:' . $port, 'X-CSRF-Token: ' . $tok];
  $r = http('POST', "$b/track-click.php", ['id' => 'chatgpt'], $own); ok(in_array($r['code'], [200, 503], true) && json_decode($r['body'], true) !== null, "click tracking answers 200 or 503, never 500 (HTTP {$r['code']})");
  $r = http('POST', "$b/vote-tool-ajax.php", ['id' => 'chatgpt', 'type' => 'up'], $own); ok(in_array($r['code'], [200, 503], true) && json_decode($r['body'], true) !== null, "voting answers 200 or 503, never 500 (HTTP {$r['code']})");
  $expect503 = in_array($sc['expect']['tool_votes'] ?? 'ok', ['incompatible', 'missing_table'], true) || $key === 's5_empty_database';
  if (!empty($sc['apply_plan'])) { ok($r['code'] === 200, 'after the plan, voting works again (HTTP ' . $r['code'] . ')'); $rc = http('POST', "$b/track-click.php", ['id' => 'chatgpt'], $own); ok($rc['code'] === 200, 'after the plan, click tracking works again (HTTP ' . $rc['code'] . ')'); $n = (int)trim((string)$mysql('SELECT COUNT(*) FROM tool_votes', $db) . ''); }
  if ($expect503) ok($r['code'] === 503, 'incompatible/missing interaction tables give a clear 503 instead of a failing write');
  $biz = http('GET', "$b/business.php"); preg_match('/name="form_token" value="([^"]+)"/', $biz['body'], $m); sleep(2);
  $r = http('POST', "$b/inquiry.php", ['lead_type' => 'consultation', 'name' => 'Compat Tester', 'email' => 'compat@example.test', 'message' => 'A sufficiently long compatibility test message.', 'consent' => '1', 'form_token' => $m[1] ?? ''], ['Origin: http://127.0.0.1:' . $port]);
  ok(in_array($r['code'], [303, 503], true) && !preg_match('/Fatal error|SQLSTATE/', $r['body']), "lead form: stored (303) or a friendly 503, never a crash (HTTP {$r['code']})");
  if (in_array($sc['expect']['leads'] ?? 'ok', ['missing_table'], true)) ok($r['code'] === 503 && strpos($r['body'], 'could not save') !== false, 'a missing leads table shows the email fallback message');
  $r = http('POST', "$b/subscribe.php", ['email' => 'sub' . bin2hex(random_bytes(2)) . '@example.test', 'consent' => '1', 'form_token' => ($m2 = (preg_match('/name="form_token" value="([^"]+)"/', http('GET', "$b/")['body'], $mm) ? $mm[1] : ''))], ['Origin: http://127.0.0.1:' . $port]);
  ok(in_array($r['code'], [200, 400, 503], true) && !preg_match('/Fatal error|SQLSTATE/', $r['body']), "newsletter signup never crashes (HTTP {$r['code']})");
  $jar = tempnam($tmp, 'j'); $lg = http('GET', "$b/admin-leads.php", null, [], $jar); preg_match('/name="_login_token" value="([^"]+)"/', $lg['body'], $lt); sleep(2);
  $lr = http('POST', "$b/admin.php", ['admin_action' => 'login', '_login_token' => $lt[1] ?? '', 'password' => 'compat-admin-pw'], ['Origin: http://127.0.0.1:' . $port], $jar);
  $adm = http('GET', "$b/admin-leads.php", null, [], $jar);
  ok($adm['code'] === 200 && !preg_match('/Fatal error|SQLSTATE|Stack trace/', $adm['body']), "admin lead page renders (HTTP {$adm['code']})");
  if (($sc['expect']['leads'] ?? '') === 'missing_table') ok(strpos($adm['body'], 'table is not available') !== false, 'admin sees a clear "leads table is not available" notice');
  @unlink($jar);
  $log = (string)@file_get_contents("$tmp/err_$key.log");
  ok(strpos($log, 'Uncaught') === false && strpos($log, 'Fatal error') === false, 'PHP error log contains no uncaught exceptions or fatal errors' . (strpos($log, 'Uncaught') !== false ? ': ' . substr($log, strpos($log, 'Uncaught'), 160) : ''));
  if ($pid > 0) shell_exec('kill ' . $pid . ' 2>/dev/null');
  $mysql("DROP DATABASE IF EXISTS `$db`;");
}
section('No database configured at all');
$rate = "$tmp/rate_none"; @mkdir($rate, 0700, true);
$pid = (int)trim((string)shell_exec("cd " . escapeshellarg($root) . " && env -i PATH=" . escapeshellarg(getenv('PATH')) . " AIHUB_CONFIG_FILE=/nonexistent AIHUB_RATE_DIR=" . escapeshellarg($rate) . " AIHUB_CACHE_DIR=" . escapeshellarg("$tmp/cache_none") . " php -d log_errors=1 -d error_log=" . escapeshellarg("$tmp/err_none.log") . " -S 127.0.0.1:8099 -t . tests/router.php > /dev/null 2>&1 & echo $!")); usleep(1200000);
foreach (['/', '/tool.php?id=chatgpt', '/search.php?q=pdf', '/recommend.php?go=1&objective=meetings&budget=flexible', '/solutions.php', '/advertise.php', '/sitemap.xml'] as $u) { $r = http('GET', "http://127.0.0.1:8099$u"); ok($r['code'] === 200, "no-database mode: GET $u works (HTTP {$r['code']})"); }
$r = http('GET', 'http://127.0.0.1:8099/redirect.php?id=chatgpt'); ok($r['code'] === 302, "no-database mode: outbound redirect works instead of failing with HTTP 500 (HTTP {$r['code']})");
$page = http('GET', 'http://127.0.0.1:8099/tool.php?id=chatgpt'); preg_match('/name="csrf-token" content="([^"]+)"/', $page['body'], $m);
$r = http('POST', 'http://127.0.0.1:8099/vote-tool-ajax.php', ['id' => 'chatgpt', 'type' => 'up'], ['Origin: http://127.0.0.1:8099', 'X-CSRF-Token: ' . ($m[1] ?? '')]); ok($r['code'] === 503, "no-database mode: voting says it is temporarily unavailable (HTTP {$r['code']})");
$log = (string)@file_get_contents("$tmp/err_none.log"); ok(strpos($log, 'Uncaught') === false, 'no-database mode: no uncaught exceptions in the PHP log');
if ($pid > 0) shell_exec('kill ' . $pid . ' 2>/dev/null');
shell_exec('rm -rf ' . escapeshellarg($tmp));
echo "\nPASSED: $pass  FAILED: $fail\n";
exit($fail ? 1 : 0);
