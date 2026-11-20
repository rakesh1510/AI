<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Analytics install verification (CLI, READ ONLY: SELECT and SHOW statements and local checks; it writes nothing, creates nothing, prints no secret).
 *   php scripts/analytics_verify.php --phase=pre  [--expect-db=aihub]     before installation: environment, schema compatibility, fingerprint of tool_clicks
 *   php scripts/analytics_verify.php --phase=post [--expect-db=aihub] [--fingerprint=N,MAXID,FP]    after installation: the seven tables, tool_clicks unchanged
 *   --allow-missing-tool-clicks   (pre phase only, staging) a missing tool_clicks table is reported as INFO because the staging installer creates it EMPTY; in the post phase the table is always required
 * Exit code 0 = no FAIL, 1 = at least one FAIL, 2 = refused (wrong database name or no connection). WARN and INFO never fail the run.
 */
require_once __DIR__ . '/../helpers.php'; require_once __DIR__ . '/../db.php'; require_once __DIR__ . '/../adm_analytics.php';
function av_arg(string $n): ?string { foreach ($GLOBALS['argv'] as $a) if (strpos($a, "--$n=") === 0) return substr($a, strlen($n) + 3); return null; }
$phase = av_arg('phase') ?? 'pre'; $allowMissing = in_array('--allow-missing-tool-clicks', $GLOBALS['argv'], true); if (!in_array($phase, ['pre', 'post'], true)) { fwrite(STDERR, "refused: --phase=pre|post\n"); exit(2); }
$res = []; $fails = 0; $say = function (string $lvl, string $name, string $detail = '') use (&$res, &$fails) { $res[] = [$lvl, $name, $detail]; if ($lvl === 'FAIL') $fails++; printf("%-5s %-58s %s\n", $lvl, $name, $detail); };
try { $pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); } catch (Throwable $e) { fwrite(STDERR, "refused: cannot connect to the database (" . get_class($e) . ")\n"); exit(2); }
$dbname = (string)$pdo->query('SELECT DATABASE()')->fetchColumn(); $exp = av_arg('expect-db'); if ($exp !== null && $exp !== $dbname) { fwrite(STDERR, "refused: connected to '$dbname', expected '$exp'\n"); exit(2); }
$say('INFO', 'database', $dbname); $ver = (string)$pdo->query('SELECT VERSION()')->fetchColumn(); $say('INFO', 'server version', $ver);
$major = (int)$ver; $isMaria = stripos($ver, 'mariadb') !== false; $say($isMaria || $major >= 8 ? 'PASS' : 'FAIL', 'window functions available (MySQL 8.0+ or MariaDB)', $isMaria ? 'MariaDB' : 'MySQL ' . $major);
$say('INFO', 'PHP', PHP_VERSION . ' tz=' . date_default_timezone_get()); $say(gmdate('Y-m-d') === gmdate('Y-m-d', time()) ? 'PASS' : 'FAIL', 'UTC clock available', gmdate('c'));
$tz = $pdo->query('SELECT @@global.time_zone, @@session.time_zone, NOW(), UTC_TIMESTAMP()')->fetch(PDO::FETCH_NUM); $say('INFO', 'database time zone (global, session, now, utc)', implode(' | ', $tz));
$say('INFO', 'AIHUB_ENV_LABEL', sec_setting('AIHUB_ENV_LABEL', 'env_label') ?: '(not set)'); $say(function_exists('analytics_enabled') && analytics_enabled() ? 'WARN' : 'PASS', 'tracking is OFF (AIHUB_ANALYTICS)', analytics_enabled() ? 'tracking is ON' : 'off');
$say(analytics_salt_ok() ? 'INFO' : 'INFO', 'visitor hash salt configured and strong', analytics_salt_ok() ? 'yes (value not shown)' : 'no (required before tracking is enabled)');
$say(is_file(__DIR__ . '/../assets/build.json') ? 'INFO' : 'WARN', 'application build fingerprint', is_file(__DIR__ . '/../assets/build.json') ? substr(sha1_file(__DIR__ . '/../assets/build.json'), 0, 12) : 'assets/build.json missing');
foreach (['outbound_click.php', 'adm_an2_views.php', 'analytics_retention.php'] as $f) $say(is_file(__DIR__ . '/../' . $f) ? 'PASS' : 'FAIL', "deployed code includes $f", '');
$track = (string)@file_get_contents(__DIR__ . '/../track-click.php'); $say(strpos($track, 'record_click') === false ? 'PASS' : 'FAIL', 'track-click.php does not write (single-count fix deployed)', '');
$hl = (string)@file_get_contents(__DIR__ . '/../helpers.php'); $say(preg_match('/function record_click\(.*?\{.*?VALUES \(\?, \'\', \'\'/s', $hl) ? 'PASS' : 'FAIL', 'record_click stores no visitor id and no IP hash (privacy fix deployed)', '');
// ---- tool_clicks schema compatibility
$lc = fn(array $rows) => array_map(fn($r) => array_change_key_case($r, CASE_LOWER), $rows);   // MySQL 8 returns information_schema column labels in UPPER case
$cols = $lc($pdo->query("SELECT column_name AS column_name, column_type AS column_type, is_nullable AS is_nullable FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'tool_clicks' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_ASSOC));
$tcMissing = !$cols; if ($tcMissing && $phase === 'post') $say('FAIL', 'tool_clicks table exists', 'missing'); elseif ($tcMissing && $allowMissing) $say('INFO', 'tool_clicks is missing (staging without historical clicks)', 'the installer creates it EMPTY; nothing is copied from production');
$names = array_column($cols, 'column_name'); if (!$tcMissing) $say($names === ['id', 'tool_slug', 'visitor_id', 'ip_hash', 'created_at'] ? 'PASS' : 'FAIL', 'tool_clicks columns are exactly id,tool_slug,visitor_id,ip_hash,created_at', implode(',', $names));
elseif (!$allowMissing && $phase === 'pre') $say('FAIL', 'tool_clicks columns', 'table missing (use --allow-missing-tool-clicks on staging)');
if (!$tcMissing) foreach (['visitor_id', 'ip_hash', 'tool_slug', 'created_at'] as $c) $say(in_array($c, $names, true) ? 'PASS' : 'FAIL', "tool_clicks has column $c", '');
if (!$tcMissing) {
$u = $pdo->query("SELECT index_name AS index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'tool_clicks' AND non_unique = 0 AND index_name <> 'PRIMARY' GROUP BY index_name")->fetchAll(PDO::FETCH_ASSOC); $u = $lc($u);
$bad = array_filter($u, fn($r) => strpos($r['cols'], 'visitor_id') !== false || strpos($r['cols'], 'ip_hash') !== false); $say($bad ? 'FAIL' : 'PASS', 'no UNIQUE index involving visitor_id or ip_hash (anonymous clicks would fail)', $bad ? json_encode(array_values($bad)) : 'none');
$ct = array_column($cols, 'column_type', 'column_name'); $say('INFO', 'created_at type', $ct['created_at'] ?? '?');
$f = $pdo->query("SELECT COUNT(*) n, COALESCE(MAX(id),0) max_id, COALESCE(SUM(CRC32(CONCAT_WS('|', id, tool_slug, visitor_id, ip_hash, created_at))),0) fp FROM tool_clicks")->fetch(PDO::FETCH_ASSOC);
$say('INFO', 'tool_clicks rows', (string)$f['n']); $say('INFO', 'tool_clicks first and last click', implode(' .. ', $pdo->query('SELECT MIN(created_at), MAX(created_at) FROM tool_clicks')->fetch(PDO::FETCH_NUM)));
$say('INFO', 'tool_clicks fingerprint (n, max_id, crc-sum)', $f['n'] . ',' . $f['max_id'] . ',' . $f['fp']);
$wid = $pdo->query("SELECT SUM(visitor_id <> '') v, SUM(ip_hash <> '') i FROM tool_clicks")->fetch(PDO::FETCH_ASSOC); $say('INFO', 'historical rows still holding a visitor id / ip hash', ($wid['v'] ?? 0) . ' / ' . ($wid['i'] ?? 0) . ' (cleanup is a separate approval)');
$fpArg = av_arg('fingerprint'); if ($fpArg !== null) { [$n0, $m0, $c0] = array_pad(explode(',', $fpArg), 3, ''); $g = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(CRC32(CONCAT_WS('|', id, tool_slug, visitor_id, ip_hash, created_at))),0) fp FROM tool_clicks WHERE id <= ?"); $g->execute([(int)$m0]); $r = $g->fetch(PDO::FETCH_ASSOC); $say((string)$r['n'] === $n0 && (string)$r['fp'] === $c0 ? 'PASS' : 'FAIL', 'historical click records identical to the preflight fingerprint', "n={$r['n']} fp={$r['fp']}"); }
}
// ---- seven tables
$seven = ['page_views', 'events', 'adm_traffic_daily', 'adm_traffic_dim_daily', 'adm_tool_daily', 'adm_event_daily', 'adm_rollup_run']; $have = []; foreach ($seven as $t) $have[$t] = adma_table($pdo, $t);
foreach ($seven as $t) { if ($phase === 'post') $say($have[$t] ? 'PASS' : 'FAIL', "table $t exists", ''); else $say($have[$t] ? 'WARN' : 'INFO', "table $t " . ($have[$t] ? 'already exists (installation would be a no-op for it)' : 'is missing (expected before installation)'), ''); }
$say(!adma_table($pdo, 'leads') && !adma_table($pdo, 'subscribers') ? 'INFO' : 'INFO', 'leads/subscribers tables (not part of this install)', (adma_table($pdo, 'leads') ? 'leads present ' : 'leads absent ') . (adma_table($pdo, 'subscribers') ? 'subscribers present' : 'subscribers absent'));
if ($phase === 'post') { foreach (['page_views', 'events'] as $t) { $n = (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn(); $say('INFO', "$t rows", (string)$n); } $say(adma_storage_ready($pdo) ? 'PASS' : 'FAIL', 'dashboard storage check (adma_storage_ready)', ''); $say(adma_column($pdo, 'page_views', 'device') ? 'INFO' : 'INFO', 'optional device column', adma_column($pdo, 'page_views', 'device') ? 'present' : 'absent (optional)'); }
// ---- grants (text only)
try { foreach ($pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN) as $g) $say('INFO', 'grant', preg_replace('/IDENTIFIED BY.*$/i', '', (string)$g)); } catch (Throwable $e) { $say('WARN', 'SHOW GRANTS not permitted', ''); }
// ---- queries the dashboard runs (read only, bounded)
$q = [['tool_clicks lifetime counter', 'SELECT tool_slug, COUNT(*) FROM tool_clicks GROUP BY tool_slug ORDER BY 2 DESC LIMIT 5'], ['legacy daily window query', "SELECT COUNT(*) FROM (SELECT LAG(created_at) OVER (PARTITION BY visitor_id, tool_slug ORDER BY created_at, id) p FROM tool_clicks WHERE created_at >= '2026-11-19' ) t"]];
foreach ($q as [$n, $sql]) { if ($tcMissing) { $say('INFO', "dashboard query skipped (no tool_clicks): $n", ''); continue; } try { $pdo->query($sql)->fetchAll(); $say('PASS', "dashboard query runs: $n", ''); } catch (Throwable $e) { $say('FAIL', "dashboard query: $n", get_class($e)); } }
echo "\n" . count($res) . " lines, $fails FAIL\n"; exit($fails ? 1 : 0);
