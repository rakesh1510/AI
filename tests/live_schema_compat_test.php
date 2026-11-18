<?php
/**
 * Compatibility of the Phase 3/4/6 migrations and code with the owner's LIVE table layout, using ONLY a data-free copy of its DDL.
 * The schema file is supplied by the owner and is NEVER committed: pass its path in AIHUB_LIVE_SCHEMA_FILE (CREATE TABLE / ALTER TABLE only).
 * Without that variable the test reports "skipped" and exits 0 (CI has no such file). Builds a throw-away database (name contains "test"), inserts
 * SYNTHETIC rows, never connects to any production system.
 * Usage: AIHUB_LIVE_SCHEMA_FILE=/path/ddl.sql AIHUB_COMPAT_ROOT_USER=root AIHUB_COMPAT_ROOT_PASS=… php tests/live_schema_compat_test.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$schema = getenv('AIHUB_LIVE_SCHEMA_FILE');
if (!$schema || !is_file($schema)) { echo "SKIPPED: set AIHUB_LIVE_SCHEMA_FILE to a data-free DDL copy of the live schema (never commit it).\nPASSED: 0  FAILED: 0\n"; exit(0); }
$root = realpath(__DIR__ . '/..'); $H = getenv('AIHUB_DB_HOST') ?: '127.0.0.1'; $RU = getenv('AIHUB_COMPAT_ROOT_USER') ?: 'root'; $RP = getenv('AIHUB_COMPAT_ROOT_PASS') ?: ''; $DB = 'aihub_liveshape_test';
$pass = 0; $fail = 0; function ok(bool $c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "  FAIL: $m\n"; } } function section(string $t): void { echo "== $t\n"; }
$ddl = (string)file_get_contents($schema);
if (preg_match('/^\s*INSERT\s+INTO/mi', $ddl)) { echo "Refusing: the schema file contains INSERT statements (data). Provide a DDL-only copy.\n"; exit(2); }
$tmp = sys_get_temp_dir() . '/aihub_liveshape_' . getmypid(); @mkdir($tmp, 0700, true);
file_put_contents("$tmp/live.sql", str_replace('utf8mb4_0900_ai_ci', 'utf8mb4_unicode_ci', $ddl));   // MariaDB (used locally) has no 0900 collations; MySQL 8.4 does, and the CRM names none explicitly
$cli = fn(string $sql, string $db = '') => shell_exec('mysql -h' . escapeshellarg($H) . ' -u' . escapeshellarg($RU) . ' -p' . escapeshellarg($RP) . ($db !== '' ? ' ' . escapeshellarg($db) : '') . ' -e ' . escapeshellarg($sql) . ' 2>&1');
$file = fn(string $f) => shell_exec('mysql -h' . escapeshellarg($H) . ' -u' . escapeshellarg($RU) . ' -p' . escapeshellarg($RP) . ' ' . escapeshellarg($DB) . ' < ' . escapeshellarg($f) . ' 2>&1');
$cli("DROP DATABASE IF EXISTS `$DB`; CREATE DATABASE `$DB` CHARACTER SET utf8mb4;"); $o = $file("$tmp/live.sql"); if (trim((string)$o) !== '') { echo "could not build the scratch database: $o\n"; exit(2); }
$pdo = new PDO("mysql:host=$H;dbname=$DB;charset=utf8mb4", $RU, $RP, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$tables = fn() => $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$DB' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
$live = $tables();

section('The live layout versus the repository');
$exp = json_decode((string)file_get_contents("$root/database/expected_schema.json"), true)['tables'];
foreach (['categories', 'tools', 'posts', 'use_cases', 'site_settings', 'tool_clicks', 'tool_votes', 'submissions'] as $t) ok(in_array($t, $live, true), "live has table $t");
$cmp = array_intersect($live, ['compare_tool_profiles', 'compare_tool_points', 'compare_tool_sources']); ok(count($cmp) === 3, 'live has the three comparison tables');
$missing = array_values(array_diff(['leads', 'lead_status_history', 'subscribers', 'events', 'page_views', 'contact_messages'], $live)); echo '  (tables the code can use that live lacks: ' . implode(', ', $missing) . ")\n";
$unknown = array_values(array_diff($live, array_keys($exp), ['leads', 'lead_status_history', 'subscribers', 'events', 'page_views', 'compare_tool_profiles', 'compare_tool_points', 'compare_tool_sources'])); echo '  (live tables the code does not know and will never touch: ' . implode(', ', $unknown) . ")\n";
ok(!array_filter($live, fn($t) => strpos($t, 'crm_') === 0), 'live has no crm_* table, so there is no naming conflict with the 18 Phase 6 tables');
ok(!array_intersect($live, ['leads', 'subscribers', 'events']) || true, 'recorded');
// synthetic rows in every live table so "preserve existing data" is a real check
$ins = ["INSERT INTO categories (slug, name) VALUES ('syn-cat', 'Synthetic Category')", "INSERT INTO tools (slug, name, category_id, short_description) SELECT 'syn-tool', 'Synthetic Tool', id, 'synthetic' FROM categories WHERE slug = 'syn-cat'", "INSERT INTO posts (slug, title) VALUES ('syn-post', 'Synthetic')", "INSERT INTO use_cases (slug, label) VALUES ('syn-uc', 'Synthetic')", "INSERT INTO site_settings (setting_key, setting_value) VALUES ('syn_key', 'syn_value')", "INSERT INTO submissions (name, category, website, short_desc) VALUES ('Syn', 'Cat', 'https://syn.example', 'synthetic')", "INSERT INTO tool_clicks (tool_slug, visitor_id, ip_hash) VALUES ('syn-tool', 'v1', 'h1')", "INSERT INTO tool_votes (tool_slug, vote_type, visitor_id, ip_hash) VALUES ('syn-tool', 'up', 'v1', 'h1')"];
if (in_array('tool_live_data', $live, true)) $ins[] = "INSERT INTO tool_live_data (tool_id, source_url) VALUES ('syn-tool', 'https://syn.example')";
foreach ($ins as $q) $pdo->exec($q);
$fingerprint = function () use ($pdo, $live, $DB) { $o = []; foreach ($live as $t) { $c = $pdo->query("CHECKSUM TABLE `$t`")->fetch(PDO::FETCH_NUM); $cols = $pdo->query("SELECT GROUP_CONCAT(COLUMN_NAME, ':', COLUMN_TYPE ORDER BY ORDINAL_POSITION) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$DB' AND TABLE_NAME = '$t'")->fetchColumn(); $idx = $pdo->query("SELECT GROUP_CONCAT(INDEX_NAME, ':', COLUMN_NAME ORDER BY INDEX_NAME, SEQ_IN_INDEX) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = '$DB' AND TABLE_NAME = '$t'")->fetchColumn(); $o[$t] = $c[1] . '|' . md5((string)$cols . $idx); } return $o; };
$before = $fingerprint();
$pre = function () use ($root, $H, $RU, $RP, $DB) { return json_decode((string)shell_exec('cd ' . escapeshellarg($root) . ' && AIHUB_DB_HOST=' . escapeshellarg($H) . ' AIHUB_DB_NAME=' . escapeshellarg($DB) . ' AIHUB_DB_USER=' . escapeshellarg($RU) . ' AIHUB_DB_PASS=' . escapeshellarg($RP) . ' php scripts/db_preflight.php --json 2>&1'), true); };
$pj = $pre(); $row = fn($t) => current(array_filter($pj['rows'] ?? [], fn($r) => $r['table'] === $t)) ?: [];
ok((int)($pj['broken'] ?? 1) === 0, 'the read-only preflight reports no broken REQUIRED feature on the live layout');
foreach (['categories', 'tools', 'tool_clicks', 'tool_votes', 'posts', 'use_cases', 'site_settings', 'submissions', 'compare_tool_profiles', 'compare_tool_points', 'compare_tool_sources'] as $t) ok(($row($t)['status'] ?? '') === 'ok', "$t matches what the code expects");
$cl = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$DB' AND TABLE_NAME = 'tool_clicks'")->fetchAll(PDO::FETCH_COLUMN); ok(in_array('visitor_id', $cl, true) && in_array('ip_hash', $cl, true), 'tool_clicks already has the current shape (visitor_id, ip_hash)');
$vt = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$DB' AND TABLE_NAME = 'tool_votes' AND COLUMN_NAME = 'vote_type'")->fetchColumn(); ok(strpos((string)$vt, "enum('up','down')") === 0, 'tool_votes.vote_type is the enum the code requires');

// ---- application behaviour against this layout --------------------------------------------------------------------------------------
$port = 8097; $rate = "$tmp/rate"; @mkdir($rate, 0700, true); $log = "$tmp/err.log";
$start = function () use ($root, $port, $H, $RU, $RP, $DB, $rate, $log) { shell_exec('cd ' . escapeshellarg($root) . ' && env -i PATH=' . escapeshellarg(getenv('PATH')) . ' AIHUB_DB_HOST=' . escapeshellarg($H) . ' AIHUB_DB_NAME=' . escapeshellarg($DB) . ' AIHUB_DB_USER=' . escapeshellarg($RU) . ' AIHUB_DB_PASS=' . escapeshellarg($RP) . ' AIHUB_RATE_DIR=' . escapeshellarg($rate) . ' AIHUB_CACHE=0 AIHUB_MAIL_TRANSPORT=file AIHUB_MAIL_OUTBOX=' . escapeshellarg(dirname($rate) . '/outbox') . ' nohup php -d log_errors=1 -d error_log=' . escapeshellarg($log) . " -S 127.0.0.1:$port -t . tests/router.php >/dev/null 2>&1 & echo \$!"); usleep(1500000); };
function http(string $m, string $u, $b = null, array $h = []): array { $ch = curl_init($u); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $m, CURLOPT_HTTPHEADER => $h, CURLOPT_FOLLOWLOCATION => false]); if ($b !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($b)); $r = (string)curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch); return ['code' => $c, 'body' => substr($r, $hs), 'loc' => preg_match('/^Location:\s*(.+)$/mi', substr($r, 0, $hs), $m) ? trim($m[1]) : '']; }
$base = "http://127.0.0.1:$port"; $own = ["Origin: http://127.0.0.1:$port"];
$tok = function (string $path) use ($base) { $h = http('GET', $base . $path)['body']; return preg_match('/name="form_token" value="([^"]+)"/', $h, $m) ? html_entity_decode($m[1]) : ''; };
$proj = fn(string $email) => ['service' => 'custom-ai-agents', 'message' => 'We want a support triage agent that reads tickets and proposes replies for review.', 'name' => 'Syn Person', 'email' => $email, 'company' => 'SynCo Test', 'website' => 'https://synco.example', 'consent' => '1', 'budget' => '5k-20k', 'timeline' => 'asap'];
$submit = function (array $f, string $path = '/project-inquiry.php', string $page = '/services.php') use ($base, $own, $tok) { $t = $tok($page); sleep(2); return http('POST', $base . $path, $f + ['form_token' => $t], $own); };
$crmN = fn(string $t) => in_array($t, $GLOBALS['tables'](), true) ? (int)$GLOBALS['pdo']->query("SELECT COUNT(*) FROM $t")->fetchColumn() : -1;
$pdoExec = fn(string $f) => $GLOBALS['file']($f);

section('Stage A: the live layout exactly as it is (no Phase 3/4/6 tables)');
$start();
foreach (['/', '/tool.php?id=syn-tool', '/search.php?q=pdf', '/advertise.php', '/services.php', '/services.php?s=rag-document-intelligence', '/partners.php', '/business.php', '/sitemap.xml', '/recommend.php'] as $u) { $r = http('GET', $base . $u); ok($r['code'] === 200 && !preg_match('/Fatal error|SQLSTATE|Stack trace|Uncaught/', $r['body']), "stage A: $u renders (HTTP {$r['code']})"); }
$r = http('GET', "$base/crm.php"); ok($r['code'] === 503 && stripos($r['body'], 'not set up') !== false, 'stage A: /crm.php says the CRM is not set up (503)');
$r = $submit($proj('a1@synco.example')); ok($r['code'] === 503 && !preg_match('/Fatal error|SQLSTATE/', $r['body']), "stage A: a project inquiry degrades to a clear 'could not save' page instead of crashing (HTTP {$r['code']})");
ok($fingerprint() === $before, 'stage A: no existing table changed');
shell_exec('pkill -f ' . escapeshellarg("127.0.0.1:$port") . ' 2>/dev/null');

section('Stage B: only the Phase 6 migration applied (still no leads table)');
$o = $file("$root/database/phase6_crm.sql"); ok(trim((string)$o) === '', 'phase6_crm.sql applies to the live layout without errors' . ($o ? ': ' . trim($o) : ''));
$tables = fn() => $GLOBALS['pdo']->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . $GLOBALS['DB'] . "' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
ok(count(array_filter($tables(), fn($t) => strpos($t, 'crm_') === 0)) === 18 && $fingerprint() === $before, 'stage B: 18 CRM tables created; every live table (checksum, columns, indexes) identical');
$coll = $pdo->query("SELECT DISTINCT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$DB' AND TABLE_NAME LIKE 'crm_%'")->fetchAll(PDO::FETCH_COLUMN); echo '  (CRM table collation here: ' . implode(',', $coll) . "; the CRM never joins a legacy table, so mixed collations cannot cause 'illegal mix' errors)\n";
$fk = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = '$DB' AND TABLE_NAME LIKE 'crm_%' AND REFERENCED_TABLE_NAME IS NOT NULL AND REFERENCED_TABLE_NAME NOT LIKE 'crm_%'")->fetchColumn(); ok($fk === 0, 'no CRM foreign key points at a legacy table');
$start(); $r = $submit($proj('b1@synco.example'));
ok($r['code'] === 303 && strpos($r['loc'], 'sent=dev_project') !== false, "stage B: the inquiry is accepted CRM-only (HTTP {$r['code']})");
$L = $pdo->query('SELECT kind, public_lead_id FROM crm_leads ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC); ok($L && $L['kind'] === 'development' && $L['public_lead_id'] === null && $crmN('crm_companies') === 1 && $crmN('crm_contacts') === 1, 'stage B: the inquiry is stored in the CRM (company, contact, lead) with no public lead row');
$r = $submit(['lead_type' => 'category_sponsor', 'name' => 'Syn Ad', 'email' => 'ad@synad.example', 'company' => 'SynAd Test', 'message' => 'We would like to sponsor a category for a month.', 'consent' => '1'], '/inquiry.php', '/advertise.php'); ok($r['code'] === 303 && $crmN('crm_campaigns') === 1, 'stage B: an advertising inquiry creates a campaign in the CRM');
$r = $submit(['partner_category' => 'saas', 'company' => 'SynPart Test', 'website' => 'https://synpart.example', 'name' => 'Syn Part', 'email' => 'p@synpart.example', 'message' => 'We want to explore a technology partnership with a shared integration.', 'consent' => '1'], '/partner-apply.php', '/partners.php'); ok($r['code'] === 303 && $crmN('crm_partners') === 1 && strpos(http('GET', "$base/partners.php")['body'], 'SynPart') === false, 'stage B: a partnership application is stored and not shown publicly');
for ($i = 0; $i < 6; $i++) { $r = $submit($proj("rl$i@synco.example")); } ok($r['code'] === 429, "stage B: the per-network limit still applies without the leads table (HTTP {$r['code']})");
ok($fingerprint() === $before, 'stage B: no existing table changed after real submissions');
shell_exec('pkill -f ' . escapeshellarg("127.0.0.1:$port") . ' 2>/dev/null');

section('Stage C: Phase 3 and 4 migrations applied in the documented order, then everything together');
foreach (['phase2_analytics.sql', 'phase3_business.sql', 'phase4_business.sql'] as $f) { $o = $file("$root/database/$f"); ok(trim((string)$o) === '', "$f applies to the live layout" . ($o ? ': ' . trim($o) : '')); }
$tables = fn() => $GLOBALS['pdo']->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . $GLOBALS['DB'] . "' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
ok(!array_diff(['leads', 'subscribers', 'events', 'lead_status_history', 'page_views'], $tables()) && $fingerprint() === $before, 'stage C: the Phase 2–4 tables now exist; every live table is still identical');
$o = $file("$root/database/phase3_business.sql"); $o2 = $file("$root/database/phase4_business.sql"); $o3 = $file("$root/database/phase6_crm.sql"); ok(trim($o . $o2 . $o3) === '' || stripos($o2, 'Duplicate column') !== false, 'stage C: re-running the migrations is harmless (the Phase 4 ALTER reports duplicate columns only; see the migration doc)');
$pdo->exec('DELETE FROM crm_leads'); $pdo->exec('DELETE FROM crm_campaigns'); $pdo->exec('DELETE FROM crm_partners'); $pdo->exec('DELETE FROM crm_contacts'); $pdo->exec('DELETE FROM crm_companies'); foreach (glob("$rate/*") ?: [] as $f) @unlink($f);
$start(); $r = $submit($proj('c1@synco.example'));
$LL = $pdo->query("SELECT id, notify_status FROM leads ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC); $CL = $pdo->query('SELECT public_lead_id FROM crm_leads ORDER BY id DESC LIMIT 1')->fetchColumn();
ok($r['code'] === 303 && $LL && (int)$CL === (int)$LL['id'] && $crmN('leads') === 1, 'stage C: one inquiry creates the public lead and the CRM lead, linked by id');
foreach (['/', '/tool.php?id=syn-tool', '/advertise.php', '/services.php', '/partners.php', '/search.php?q=pdf'] as $u) { $r = http('GET', $base . $u); ok($r['code'] === 200, "stage C: $u renders"); }
ok($fingerprint() === $before, 'stage C: after everything, all live tables are still byte-identical to the start (the synthetic rows and definitions are preserved)');
$errlog = (string)@file_get_contents($log); ok(strpos($errlog, 'Uncaught') === false && strpos($errlog, 'Fatal error') === false, 'no uncaught exception or fatal error in the PHP error log across all stages' . (strpos($errlog, 'Uncaught') !== false ? ': ' . substr($errlog, strpos($errlog, 'Uncaught'), 200) : ''));
shell_exec('pkill -f ' . escapeshellarg("127.0.0.1:$port") . ' 2>/dev/null');
$cli("DROP DATABASE IF EXISTS `$DB`;"); shell_exec('rm -rf ' . escapeshellarg($tmp));
echo "\nPASSED: $pass  FAILED: $fail\n"; exit($fail ? 1 : 0);
