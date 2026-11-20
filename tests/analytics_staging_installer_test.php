<?php
/**
 * MANUAL rehearsal of scripts/analytics_staging_install.sh on a DISPOSABLE server (never staging, never production). Needs a MySQL/MariaDB server where you can create databases and users, and the mysql and mysqldump clients.
 * Builds a decoy "aihub" (production stand-in with tool_clicks) and "aihub_phase6_test" (staging stand-in with CRM-like and AI-Universe-like tables, NO tool_clicks), a staging-only account,
 * then runs the installer scenarios and asserts nothing outside the intended additive change happens. Synthetic data only.
 * Usage: AIHUB_COMPAT_ROOT_USER=root AIHUB_COMPAT_ROOT_PASS=... AIHUB_DB_HOST=127.0.0.1 AIHUB_DB_PORT=3306 php tests/analytics_staging_installer_test.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__); $H = getenv('AIHUB_DB_HOST') ?: '127.0.0.1'; $PORT = getenv('AIHUB_DB_PORT') ?: '3399'; $U = getenv('AIHUB_COMPAT_ROOT_USER') ?: 'root'; $PW = getenv('AIHUB_COMPAT_ROOT_PASS') ?: 'rootpw';
$pass = 0; $fail = 0; function ok($c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; fwrite(STDERR, "  FAIL: $m\n"); } }
$adm = new PDO("mysql:host=$H;port=$PORT;charset=utf8mb4", $U, $PW, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); echo 'server: ' . $adm->query('SELECT VERSION()')->fetchColumn() . "\n";
$STG = 'aihub_phase6_test'; $PROD = 'aihub'; $acct = 'stg_inst'; $host = 'localhost';
$seq = fn(int $n) => "(SELECT ROW_NUMBER() OVER () AS seq FROM information_schema.columns a CROSS JOIN information_schema.columns b LIMIT $n) s";
$fp = fn(string $db) => $adm->query("SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS('|', id, tool_slug, visitor_id, ip_hash, created_at))),0) FROM `$db`.tool_clicks")->fetch(PDO::FETCH_NUM);
$setup = function (bool $stgClicks, ?string $stgClicksDdl = null) use ($adm, $STG, $PROD, $acct, $host, $seq) {
  foreach ([$STG, $PROD] as $d) { $adm->exec("DROP DATABASE IF EXISTS `$d`"); $adm->exec("CREATE DATABASE `$d` CHARACTER SET utf8mb4"); }
  $adm->exec("CREATE TABLE `$PROD`.tool_clicks (id INT AUTO_INCREMENT PRIMARY KEY, tool_slug VARCHAR(255) NOT NULL, visitor_id VARCHAR(64) DEFAULT NULL, ip_hash CHAR(64) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)"); $adm->exec("INSERT INTO `$PROD`.tool_clicks (tool_slug, visitor_id, ip_hash) SELECT CONCAT('p', seq % 5), MD5(seq), MD5(seq) FROM " . $seq(3000) . "");
  $adm->exec("CREATE TABLE `$STG`.crm_users (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(40), role VARCHAR(16))"); $adm->exec("INSERT INTO `$STG`.crm_users (username, role) VALUES ('owner1','owner'),('sales1','sales'),('ver1','verifier')");
  $adm->exec("CREATE TABLE `$STG`.crm_leads (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80))"); $adm->exec("INSERT INTO `$STG`.crm_leads (name) SELECT CONCAT('lead', seq) FROM " . $seq(250) . "");
  $adm->exec("CREATE TABLE `$STG`.uni_entity (id INT AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(80), state VARCHAR(16))"); $adm->exec("INSERT INTO `$STG`.uni_entity (slug, state) SELECT CONCAT('e', seq), 'verified' FROM " . $seq(90) . "");
  if ($stgClicksDdl !== null) { $adm->exec($stgClicksDdl); $mode = $adm->query('SELECT @@SESSION.sql_mode')->fetchColumn(); $adm->exec("SET SESSION sql_mode = ''"); $adm->exec("INSERT INTO `$STG`.tool_clicks (tool_slug) VALUES ('keep-me-1'),('keep-me-2')"); $adm->exec('SET SESSION sql_mode = ' . $adm->quote($mode)); }
  $adm->exec("DROP USER IF EXISTS '$acct'@'$host'"); $adm->exec("CREATE USER '$acct'@'$host' IDENTIFIED BY 'pw'"); $adm->exec("GRANT ALL ON `$STG`.* TO '$acct'@'$host'"); $adm->exec("GRANT ALL ON `aihub\\_phase6\\_test\\_bkverify\\_%`.* TO '$acct'@'$host'"); $adm->exec('FLUSH PRIVILEGES');
};
$run = function (string $args = '', array $env = []) use ($root, $H, $PORT, $acct) { $out = sys_get_temp_dir() . '/stginst_' . bin2hex(random_bytes(3)); $e = ''; foreach (array_merge(['STAGING_DB_HOST' => $H, 'STAGING_DB_PORT' => $PORT, 'STAGING_DB_USER' => $acct, 'MYSQL_PWD' => 'pw', 'OUT_DIR' => $out], $env) as $k => $v) $e .= $k . '=' . escapeshellarg($v) . ' '; exec("cd " . escapeshellarg($root) . " && $e scripts/analytics_staging_install.sh $args 2>&1", $o, $rc); return [$rc, implode("\n", $o), $out]; };
$tables = fn(string $db) => $adm->query("SELECT table_name FROM information_schema.tables WHERE table_schema = '$db' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN); $cnt = fn(string $db, string $t) => (int)$adm->query("SELECT COUNT(*) FROM `$db`.`$t`")->fetchColumn();
try {
  // ---- scenario A: staging has CRM and AI Universe tables but NO tool_clicks (the real situation)
  $setup(false); $prodBefore = $fp($PROD); $stgTablesBefore = $tables($STG);
  [$rc, $o, $dir] = $run('--restore-test'); ok($rc === 0 && strpos($o, 'tool_clicks does NOT exist') !== false && strpos($o, 'DRY RUN COMPLETE') !== false, 'A1 dry run: missing tool_clicks is reported and the run completes (no longer a hard stop)' . ($rc ? "\n$o" : ''));
  ok($tables($STG) === $stgTablesBefore, 'A1 the dry run created nothing in staging'); ok(strpos($o, 'restore test passed: every table restores with identical row counts') !== false, 'A1 the full backup restores into a scratch database with identical row counts');
  ok(is_file("$dir/aihub_phase6_test-full.sql.gz") && !is_file("$dir/tool_clicks.sql.gz") && is_file("$dir/backups.sha256"), 'A1 a full backup and checksum exist; no tool_clicks dump (nothing to dump)');
  ok(count(array_filter($adm->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN), fn($d) => strpos($d, 'bkverify') !== false)) === 0, 'A1 the scratch database was dropped');
  [$rc, $o] = $run('--apply'); ok($rc === 2 && strpos($o, 'CONFIRM_INSTALL') !== false && $tables($STG) === $stgTablesBefore, 'A2 --apply without the confirmation variable is refused and changes nothing');
  [$rc, $o, $dir] = $run('--apply', ['CONFIRM_INSTALL' => $STG]); ok($rc === 0 && strpos($o, 'tool_clicks created EMPTY') !== false && strpos($o, 'DONE. Tracking is still OFF') !== false, 'A3 apply: creates the empty five-column tool_clicks and the seven tables' . ($rc ? "\n$o" : ''));
  $cols = $adm->query("SELECT GROUP_CONCAT(column_name ORDER BY ordinal_position) FROM information_schema.columns WHERE table_schema = '$STG' AND table_name = 'tool_clicks'")->fetchColumn(); ok($cols === 'id,tool_slug,visitor_id,ip_hash,created_at' && $cnt($STG, 'tool_clicks') === 0, 'A3 tool_clicks has exactly the five columns and zero rows (nothing copied)');
  $new = array_diff($tables($STG), $stgTablesBefore); sort($new); ok($new === ['adm_event_daily', 'adm_rollup_run', 'adm_tool_daily', 'adm_traffic_daily', 'adm_traffic_dim_daily', 'events', 'page_views', 'tool_clicks'], 'A3 exactly tool_clicks plus the seven analytics tables were added (leads, subscribers, tool_votes not created)');
  ok($cnt($STG, 'crm_users') === 3 && $cnt($STG, 'crm_leads') === 250 && $cnt($STG, 'uni_entity') === 90, 'A3 CRM and AI Universe records are untouched'); ok(strpos($o, 'identical row counts after the install') !== false, 'A3 the installer itself verified every pre-existing table count');
  ok($fp($PROD) === $prodBefore && $cnt($PROD, 'tool_clicks') === 3000 && $tables($PROD) === ['tool_clicks'], 'A3 production stand-in is byte-identical and gained no tables');
  ok(file_exists("$dir/verify-post.txt") && !preg_match('/^FAIL/m', file_get_contents("$dir/verify-post.txt")), 'A3 post-install verification reports no FAIL');
  [$rc, $o] = $run('--apply', ['CONFIRM_INSTALL' => $STG]); ok($rc === 0 && strpos($o, 'tool_clicks EXISTS') !== false && strpos($o, 'was not altered') !== false, 'A4 re-running is a harmless no-op (the now-existing table is verified, not recreated)');
  // ---- scenario B: staging already has a five-column tool_clicks with rows: never altered, never emptied
  $setup(true, "CREATE TABLE `$STG`.tool_clicks (id INT AUTO_INCREMENT PRIMARY KEY, tool_slug VARCHAR(255) NOT NULL, visitor_id CHAR(32) NOT NULL, ip_hash CHAR(16) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(tool_slug))");
  $before = $fp($STG); [$rc, $o] = $run('--apply', ['CONFIRM_INSTALL' => $STG]); ok($rc === 0 && $fp($STG) === $before && $cnt($STG, 'tool_clicks') === 2, 'B an existing tool_clicks (even with different column types) is preserved untouched');
  // ---- scenario C: wrong columns / unique index: stop, nothing changed
  $setup(true, "CREATE TABLE `$STG`.tool_clicks (id INT AUTO_INCREMENT PRIMARY KEY, tool_slug VARCHAR(255) NOT NULL, ip_address VARCHAR(45), user_agent VARCHAR(1000), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)"); $t0 = $tables($STG);
  [$rc, $o] = $run('--apply', ['CONFIRM_INSTALL' => $STG]); ok($rc === 2 && strpos($o, 'unexpected tool_clicks columns') !== false && $tables($STG) === $t0, 'C an old-shape tool_clicks stops the run before any change');
  $setup(true, "CREATE TABLE `$STG`.tool_clicks (id INT AUTO_INCREMENT PRIMARY KEY, tool_slug VARCHAR(255) NOT NULL, visitor_id VARCHAR(64) DEFAULT NULL, ip_hash CHAR(64) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY u (tool_slug, visitor_id))");
  [$rc, $o] = $run('--apply', ['CONFIRM_INSTALL' => $STG]); ok($rc === 2 && strpos($o, 'UNIQUE index') !== false, 'C a unique index involving visitor_id stops the run');
  // ---- scenario D: optional tool_votes
  $setup(false); [$rc, $o] = $run('--apply --create-tool-votes', ['CONFIRM_INSTALL' => $STG]); ok($rc === 0 && in_array('tool_votes', $tables($STG), true) && $cnt($STG, 'tool_votes') === 0, 'D --create-tool-votes creates an EMPTY tool_votes only when asked');
  $setup(false); [$rc, $o] = $run('--apply', ['CONFIRM_INSTALL' => $STG]); ok(!in_array('tool_votes', $tables($STG), true) && strpos($o, 'NOTE: tool_votes is absent') !== false, 'D without the flag tool_votes is not created and the consequence is explained');
  // ---- scenario E: privilege isolation
  $setup(false); $adm->exec("GRANT SELECT ON `$PROD`.* TO '$acct'@'$host'"); [$rc, $o] = $run('', []); ok($rc === 2 && (strpos($o, 'privileges that reach production') !== false || strpos($o, 'isolation not proven') !== false), 'E an account with any privilege on aihub is refused'); $adm->exec("REVOKE SELECT ON `$PROD`.* FROM '$acct'@'$host'");
  [$rc, $o] = $run('', ['STAGING_DB_USER' => $U, 'MYSQL_PWD' => $PW]); ok($rc === 2, 'E a broad administrator account is refused');
  // ---- the hard-coded target
  $src = file_get_contents($root . '/scripts/analytics_staging_install.sh'); ok(strpos($src, 'DB=aihub_phase6_test') !== false && !preg_match('/\bDROP\s+TABLE|TRUNCATE|DELETE\s+FROM|UPDATE\s+\w+\s+SET/i', preg_replace('/^\s*#.*$/m', '', $src)) , 'the installer target is hard-coded and contains no DROP TABLE, TRUNCATE, DELETE or UPDATE');
} finally { foreach ([$STG, $PROD] as $d) $adm->exec("DROP DATABASE IF EXISTS `$d`"); $adm->exec("DROP USER IF EXISTS '$acct'@'$host'"); foreach (glob(sys_get_temp_dir() . '/stginst_*') ?: [] as $d) @exec('rm -rf ' . escapeshellarg($d)); }
echo "$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
