<?php
/**
 * Phase 6 migration test (isolated, throw-away database whose name contains "test"; synthetic rows only; nothing touches a live database).
 * Proves: additive (existing tables byte-for-byte unchanged), idempotent, foreign-key behaviour, rollback removes only crm_* tables, the preflight
 * reports and plans correctly before/after, and the CRM stays fast on a synthetic 20,000-lead dataset.
 * Usage: php tests/phase6_migration_test.php   (needs AIHUB_COMPAT_ROOT_USER/PASS able to create databases, like tests/db_compat_test.php)
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = realpath(__DIR__ . '/..');
$H = getenv('AIHUB_DB_HOST') ?: '127.0.0.1'; $U = getenv('AIHUB_DB_USER') ?: 'aihub_test'; $P = getenv('AIHUB_DB_PASS') ?: 'test_only_pw';
$RU = getenv('AIHUB_COMPAT_ROOT_USER') ?: $U; $RP = getenv('AIHUB_COMPAT_ROOT_PASS') ?: $P; $DB = 'aihub_p6mig_test';
$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "  FAIL: $m\n"; } }
function section(string $t): void { echo "== $t\n"; }
// Credentials go in a private option file, never on the command line: the MySQL 8 client prints "[Warning] Using a password on the command line
// interface can be insecure" to stderr, which would be indistinguishable from a real migration message. The assertions below still require the
// client's output to be EMPTY, so any genuine warning or error from the SQL is still caught.
$tmp = sys_get_temp_dir() . '/aihub_p6mig_' . getmypid(); @mkdir($tmp, 0700, true);
file_put_contents("$tmp/client.cnf", "[client]\nhost=$H\nuser=$RU\npassword=$RP\n"); chmod("$tmp/client.cnf", 0600);
$cli = fn(string $sql, string $db = '') => shell_exec('mysql --defaults-extra-file=' . escapeshellarg("$tmp/client.cnf") . ($db !== '' ? ' ' . escapeshellarg($db) : '') . ' -e ' . escapeshellarg($sql) . ' 2>&1');
$file = fn(string $f) => shell_exec('mysql --defaults-extra-file=' . escapeshellarg("$tmp/client.cnf") . ' ' . escapeshellarg($DB) . ' < ' . escapeshellarg($f) . ' 2>&1');
$cli("DROP DATABASE IF EXISTS `$DB`; CREATE DATABASE `$DB` CHARACTER SET utf8mb4;");
// Baseline = everything before Phase 6, built exactly like tests/setup_test_db.sh does
$s = (string)file_get_contents("$root/database/full_mysql_schema.sql");
file_put_contents("$tmp/core.sql", substr($s, 0, strpos($s, 'CREATE TABLE IF NOT EXISTS tool_clicks')) . substr($s, strpos($s, 'CREATE TABLE IF NOT EXISTS submissions')));
foreach (["$tmp/core.sql", "$root/tests/fixtures/interactions_current.sql", "$root/database/install_compare_tables.sql", "$root/database/phase2_analytics.sql", "$root/database/phase3_business.sql", "$root/database/phase4_business.sql"] as $f) { $o = $file($f); if (trim((string)$o) !== '' && stripos($o, 'ERROR') !== false) { echo "baseline build failed: $o\n"; exit(2); } }
$cli("INSERT INTO leads (created_at, lead_type, name, email, message, consent_at) VALUES (NOW(), 'advertiser', 'Base Line', 'base@example.test', 'baseline row for the migration test', NOW()), (NOW(), 'consultation', 'Two', 'two@example.test', 'second baseline row for the migration test', NOW());", $DB);
$pdo = new PDO("mysql:host=$H;dbname=$DB;charset=utf8mb4", $RU, $RP, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$tables = fn() => $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$DB' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
$existing = array_values(array_filter($tables(), fn($t) => strpos($t, 'crm_') !== 0));
$fingerprint = function () use ($pdo, $existing, $DB) { $o = []; foreach ($existing as $t) { $c = $pdo->query("CHECKSUM TABLE `$t`")->fetch(PDO::FETCH_NUM); $cols = $pdo->query("SELECT GROUP_CONCAT(COLUMN_NAME, ':', COLUMN_TYPE ORDER BY ORDINAL_POSITION) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$DB' AND TABLE_NAME = '$t'")->fetchColumn(); $o[$t] = $c[1] . '|' . md5((string)$cols) . '|' . (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); } return $o; };
$before = $fingerprint(); $plan = fn() => shell_exec('cd ' . escapeshellarg($root) . ' && AIHUB_DB_HOST=' . escapeshellarg($H) . ' AIHUB_DB_NAME=' . escapeshellarg($DB) . ' AIHUB_DB_USER=' . escapeshellarg($RU) . ' AIHUB_DB_PASS=' . escapeshellarg($RP) . ' php scripts/db_preflight.php --json 2>&1');

section('Before the migration: a Phase 4 database');
ok(count($existing) >= 17 && !array_filter($tables(), fn($t) => strpos($t, 'crm_') === 0), 'the baseline has the earlier tables and no CRM tables (' . count($existing) . ' tables)');
$pj = json_decode((string)$plan(), true); $crmRows = array_values(array_filter($pj['rows'] ?? [], fn($r) => strpos($r['table'], 'crm_') === 0));
ok(count($crmRows) === 18 && count(array_filter($crmRows, fn($r) => $r['status'] === 'missing_table')) === 18 && count(array_filter($crmRows, fn($r) => $r['required'])) === 0, 'the preflight reports 18 CRM tables as missing and none of them as required');
ok((int)($pj['broken'] ?? 1) === 0 && strpos((string)$plan(), 'crm_users') !== false, 'the missing CRM tables do not make the verdict "broken" (the CRM is optional until migrated)');
$pl = (string)shell_exec('cd ' . escapeshellarg($root) . ' && AIHUB_DB_HOST=' . escapeshellarg($H) . ' AIHUB_DB_NAME=' . escapeshellarg($DB) . ' AIHUB_DB_USER=' . escapeshellarg($RU) . ' AIHUB_DB_PASS=' . escapeshellarg($RP) . ' php scripts/db_preflight.php --plan 2>&1');
ok(strpos($pl, 'phase6_crm.sql') !== false && strpos($pl, 'CREATE TABLE IF NOT EXISTS') !== false || strpos($pl, 'phase6_crm.sql') !== false, 'the generated plan tells the owner to run database/phase6_crm.sql');
ok(preg_match('/\bDROP\b|\bDELETE\b|\bTRUNCATE\b/i', preg_replace('/^--.*$/m', '', $pl)) === 0, 'the plan contains no destructive statement');

section('Applying phase6_crm.sql');
$out = $file("$root/database/phase6_crm.sql"); ok(trim((string)$out) === '', 'the migration applies without errors' . ($out ? ': ' . trim($out) : ''));
$crm = array_values(array_filter($tables(), fn($t) => strpos($t, 'crm_') === 0)); ok(count($crm) === 18, '18 CRM tables exist');
ok($fingerprint() === $before, 'every pre-existing table is unchanged (checksum, column definitions and row counts identical)');
$out2 = $file("$root/database/phase6_crm.sql"); ok(trim((string)$out2) === '' && $fingerprint() === $before && count(array_filter($tables(), fn($t) => strpos($t, 'crm_') === 0)) === 18, 'applying it a second time is harmless (idempotent)');
ok((int)$pdo->query("SELECT COUNT(*) FROM leads")->fetchColumn() === 2, 'the two existing lead rows are intact');
$engines = $pdo->query("SELECT DISTINCT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$DB' AND TABLE_NAME LIKE 'crm_%'")->fetchAll(PDO::FETCH_NUM); ok(count($engines) === 1 && $engines[0][0] === 'InnoDB' && strpos($engines[0][1], 'utf8mb4') === 0, 'all CRM tables are InnoDB utf8mb4');
$pj = json_decode((string)$plan(), true); ok(count(array_filter($pj['rows'] ?? [], fn($r) => strpos($r['table'], 'crm_') === 0 && $r['status'] === 'ok')) === 18, 'after the migration the preflight reports all 18 CRM tables ok');
$money = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$DB' AND TABLE_NAME LIKE 'crm_%' AND (COLUMN_NAME LIKE '%minor' OR COLUMN_NAME LIKE '%_bp')")->fetchAll(PDO::FETCH_NUM);
ok(count($money) >= 12 && count(array_filter($money, fn($r) => !in_array($r[2], ['bigint', 'int'], true))) === 0, 'all money and rate columns are integers (' . count($money) . ' columns; no float or decimal)');
$idx = $pdo->query("SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = '$DB' AND TABLE_NAME LIKE 'crm_%'")->fetchAll(PDO::FETCH_NUM); $have = []; foreach ($idx as [$t, $i, $nu]) $have["$t.$i"] = (int)$nu;
foreach (['crm_users.uniq_crm_users_username' => 0, 'crm_contacts.uniq_crm_contacts_email' => 0, 'crm_proposals.uniq_crm_proposal_number' => 0, 'crm_campaigns.uniq_crm_campaign_code' => 0, 'crm_proposal_versions.uniq_crm_pv' => 0, 'crm_leads.idx_crm_leads_status' => 1, 'crm_opportunities.idx_crm_opp_stage' => 1, 'crm_companies.idx_crm_companies_domain' => 1, 'crm_tasks.idx_crm_tasks_due' => 1, 'crm_audit_log.idx_crm_audit_at' => 1] as $k => $nu) ok(($have[$k] ?? -1) === $nu, "index $k exists" . ($nu === 0 ? ' and is unique' : ''));

section('Relationships behave safely');
$now = "'2026-01-01 00:00:00'"; $pdo->exec("INSERT INTO crm_companies (name, domain, created_at, updated_at, is_synthetic) VALUES ('Co A', 'a.example', $now, $now, 1)");
$pdo->exec("INSERT INTO crm_contacts (company_id, name, email, email_norm, created_at, is_synthetic) VALUES (1, 'C', 'c@a.example', 'c@a.example', $now, 1)");
$pdo->exec("INSERT INTO crm_leads (kind, company_id, contact_id, message, created_at, updated_at, is_synthetic) VALUES ('advertising', 1, 1, 'm', $now, $now, 1)");
$pdo->exec("INSERT INTO crm_opportunities (lead_id, company_id, kind, title, created_at, updated_at, is_synthetic) VALUES (1, 1, 'advertising', 'T', $now, $now, 1)");
$pdo->exec("INSERT INTO crm_stage_history (opportunity_id, from_stage, to_stage, at) VALUES (1, '', 'new', $now)");
$dupe = false; try { $pdo->exec("INSERT INTO crm_contacts (company_id, name, email, email_norm, created_at) VALUES (1, 'D', 'c@a.example', 'c@a.example', $now)"); } catch (PDOException $e) { $dupe = true; } ok($dupe, 'a duplicate contact email is refused by the database (unique key)');
$blocked = false; try { $pdo->exec('DELETE FROM crm_companies WHERE id = 1'); } catch (PDOException $e) { $blocked = true; } ok($blocked && (int)$pdo->query('SELECT COUNT(*) FROM crm_opportunities')->fetchColumn() === 1, 'a company with opportunities cannot be deleted (RESTRICT)');
$pdo->exec('DELETE FROM crm_contacts WHERE id = 1'); ok($pdo->query('SELECT contact_id FROM crm_leads WHERE id = 1')->fetchColumn() === null && (int)$pdo->query('SELECT COUNT(*) FROM crm_leads')->fetchColumn() === 1, 'deleting a contact keeps the lead (contact link set to NULL)');
$pdo->exec('DELETE FROM crm_opportunities WHERE id = 1'); ok((int)$pdo->query('SELECT COUNT(*) FROM crm_stage_history')->fetchColumn() === 0, 'deleting an opportunity removes its stage history (CASCADE)');
$orphan = false; try { $pdo->exec("INSERT INTO crm_opportunities (company_id, kind, title, created_at, updated_at) VALUES (999, 'advertising', 'x', $now, $now)"); } catch (PDOException $e) { $orphan = true; } ok($orphan, 'an opportunity cannot reference a company that does not exist');
foreach (['crm_audit_log', 'crm_leads', 'crm_companies'] as $t) $pdo->exec("DELETE FROM $t");

section('Scale: 20,000 synthetic leads');
$t0 = microtime(true); // ordinary helper tables: MySQL refuses to open a TEMPORARY table more than once in one query (error 1137), MariaDB allows it
$pdo->exec('CREATE TABLE p6_d10 (d TINYINT)'); $pdo->exec('INSERT INTO p6_d10 VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9)'); $pdo->exec('CREATE TABLE p6_nums (seq INT PRIMARY KEY)'); $pdo->exec('INSERT INTO p6_nums SELECT a.d + b.d * 10 + c.d * 100 + e.d * 1000 + f.d * 10000 + 1 FROM p6_d10 a, p6_d10 b, p6_d10 c, p6_d10 e, p6_d10 f WHERE a.d + b.d * 10 + c.d * 100 + e.d * 1000 + f.d * 10000 < 20000');
$pdo->exec("INSERT INTO crm_companies (name, domain, created_at, updated_at, is_synthetic) SELECT CONCAT('Company ', seq), CONCAT('c', seq, '.example'), NOW(), NOW(), 1 FROM p6_nums");
$pdo->exec("INSERT INTO crm_contacts (company_id, name, email, email_norm, created_at, is_synthetic) SELECT id, CONCAT('Contact ', id), CONCAT('p', id, '@c', id, '.example'), CONCAT('p', id, '@c', id, '.example'), NOW(), 1 FROM crm_companies");
$pdo->exec("INSERT INTO crm_leads (kind, subtype, company_id, contact_id, attr_source, status, message, created_at, updated_at, is_synthetic) SELECT ELT(1 + (id % 3), 'advertising', 'partnership', 'development'), 'x', company_id, id, ELT(1 + (id % 4), 'newsletter', 'search', 'direct', 'partner'), ELT(1 + (id % 4), 'new', 'qualified', 'disqualified', 'converted'), 'synthetic', NOW() - INTERVAL (id % 80) DAY, NOW(), 1 FROM crm_contacts");
$pdo->exec("INSERT INTO crm_opportunities (lead_id, company_id, kind, title, stage, amount_minor, currency, created_at, updated_at, is_synthetic) SELECT id, company_id, kind, CONCAT('Opp ', id), ELT(1 + (id % 6), 'new', 'qualified', 'contacted', 'discovery', 'proposal', 'negotiation'), 100000 + id, 'EUR', NOW(), NOW(), 1 FROM crm_leads WHERE id % 4 = 0");
echo '  (seeded in ' . round(microtime(true) - $t0, 1) . " s)\n";
$run = fn(string $code) => json_decode((string)shell_exec('cd ' . escapeshellarg($root) . ' && AIHUB_DB_HOST=' . escapeshellarg($H) . ' AIHUB_DB_NAME=' . escapeshellarg($DB) . ' AIHUB_DB_USER=' . escapeshellarg($RU) . ' AIHUB_DB_PASS=' . escapeshellarg($RP) . ' php -r ' . escapeshellarg('require "helpers.php"; require "crm_dashboard.php"; db_connection_available(); $pdo = crm_pdo(); ' . $code) . ' 2>&1'), true);
$m = $run('$t = microtime(true); $a = crm_leads_list($pdo, ["status" => "qualified", "kind" => "advertising"]); $t1 = microtime(true) - $t; $t = microtime(true); $b = crm_leads_list($pdo, ["q" => "company 12"]); $t2 = microtime(true) - $t; $t = microtime(true); $c = crm_dashboard($pdo, 90); $t3 = microtime(true) - $t; $t = microtime(true); $d = crm_find_duplicates($pdo, "p77@c77.example", "Company 77", "https://c77.example"); $t4 = microtime(true) - $t; echo json_encode(["filter_ms" => round($t1 * 1000), "search_ms" => round($t2 * 1000), "dashboard_ms" => round($t3 * 1000), "dedupe_ms" => round($t4 * 1000), "filter_total" => $a["total"], "dash_inq" => $c["inquiries"], "open_opps" => $c["open_opportunities"], "dupes" => count($d)]);');
echo '  measured: ' . json_encode($m) . "\n";
ok(is_array($m) && $m['filter_total'] > 1000 && $m['dash_inq'] === 20000 && $m['open_opps'] === 5000 && $m['dupes'] >= 2, 'the CRM reads 20,000 leads and 5,000 opportunities correctly: ' . json_encode($m));
ok(is_array($m) && $m['filter_ms'] < 1500 && $m['search_ms'] < 1500 && $m['dashboard_ms'] < 3000 && $m['dedupe_ms'] < 500, 'list, search, dashboard and duplicate check stay fast at that size (ms: filter ' . ($m['filter_ms'] ?? '?') . ', search ' . ($m['search_ms'] ?? '?') . ', dashboard ' . ($m['dashboard_ms'] ?? '?') . ', dedupe ' . ($m['dedupe_ms'] ?? '?') . ')');

$pdo->exec('DROP TABLE p6_nums'); $pdo->exec('DROP TABLE p6_d10');
section('Rollback');
$out = $file("$root/database/phase6_rollback.sql"); ok(trim((string)$out) === '', 'the rollback script runs without errors' . ($out ? ': ' . trim($out) : ''));
ok(!array_filter($tables(), fn($t) => strpos($t, 'crm_') === 0) && $fingerprint() === $before, 'rollback removes only the CRM tables; every other table is unchanged');
$out = $file("$root/database/phase6_crm.sql"); ok(trim((string)$out) === '' && count(array_filter($tables(), fn($t) => strpos($t, 'crm_') === 0)) === 18, 'the migration can be re-applied after a rollback');
$cli("DROP DATABASE IF EXISTS `$DB`;"); shell_exec('rm -rf ' . escapeshellarg($tmp));
echo "\nPASSED: $pass  FAILED: $fail\n"; exit($fail ? 1 : 0);
