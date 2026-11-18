<?php
/**
 * READ-ONLY database compatibility preflight.
 * Compares a database's actual schema with what the application code expects (database/expected_schema.json and the Phase 2/3/4 and
 * compare SQL files) and prints, per feature, whether it will work, degrade to the JSON fallback, or break, plus the SQL that WOULD
 * close each gap. It executes only SELECTs on information_schema. It never writes, never reads application rows, never runs migrations.
 *
 * Usage (point it at a COPY or a test database, e.g. a restored backup on a staging machine you control):
 *   AIHUB_DB_HOST=... AIHUB_DB_NAME=... AIHUB_DB_USER=<read-only user> AIHUB_DB_PASS=... php scripts/db_preflight.php [--json] [--plan]
 * --plan prints, as text, the SQL a person would review and run on the COPY to close the gaps (it is never executed by this script).
 * Exit code: 0 = compatible, 1 = something required is missing or incompatible.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../helpers.php';
$json = in_array('--json', $argv, true); $plan = in_array('--plan', $argv, true);
db_connection_available();
$pdo = db();
$dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$root = dirname(__DIR__);

/** type family used for comparison, so int(11) vs bigint or varchar(190) vs varchar(255) are not reported as incompatible */
function ty(string $t): string {
  $t = strtolower(trim($t));
  if (preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint)/', $t)) return 'int';
  if (preg_match('/^(varchar|char)/', $t)) return 'varchar';
  if (preg_match('/^(tinytext|text|mediumtext|longtext|json)/', $t)) return 'text';
  if (preg_match('/^(datetime|timestamp)/', $t)) return 'datetime';
  if (preg_match('/^date/', $t)) return 'date';
  if (preg_match('/^enum/', $t)) return 'enum';
  return $t;
}
/** parse CREATE TABLE / ALTER TABLE ... ADD COLUMN statements of a SQL file into table => column => type family */
function parse_sql(string $file): array {
  $sql = (string)file_get_contents($file); $out = [];
  if (preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?\s+`?(\w+)`?\s*\((.*?)\)\s*ENGINE/is', $sql, $m, PREG_SET_ORDER)) foreach ($m as $t) {
    foreach (preg_split('/,\s*\n/', $t[2]) as $line) { $line = trim($line); if ($line === '' || preg_match('/^(PRIMARY|UNIQUE|KEY|INDEX|CONSTRAINT)/i', $line)) continue; if (preg_match('/^`?(\w+)`?\s+([a-z]+(?:\(\d+(?:,\d+)?\))?)/i', $line, $c)) $out[$t[1]][$c[1]] = ty($c[2]); }
  }
  if (preg_match_all('/ALTER TABLE\s+`?(\w+)`?\s+(.*?);/is', $sql, $m, PREG_SET_ORDER)) foreach ($m as $t) { if (preg_match_all('/ADD COLUMN\s+`?(\w+)`?\s+([a-z]+(?:\(\d+(?:,\d+)?\))?)/i', $t[2], $cs, PREG_SET_ORDER)) foreach ($cs as $c) $out[$t[1]][$c[1]] = ty($c[2]); }
  return $out;
}
$exp = json_decode((string)file_get_contents("$root/database/expected_schema.json"), true)['tables'];
$expected = [];
foreach ($exp as $t => $d) $expected[$t] = ['feature' => $d['feature'], 'degrade' => $d['if_missing_or_incompatible'], 'required' => $d['required'], 'columns' => $d['columns'], 'notes' => $d['notes'], 'file' => implode('; ', $d['defined_in']), 'ddl' => $d['ddl'] ?? []];
$phase = [
  'database/phase2_analytics.sql' => ['page_views' => ['traffic statistics (optional; off unless AIHUB_ANALYTICS=1)', 'no statistics are recorded']],
  'database/phase3_business.sql' => ['leads' => ['lead capture', 'forms show "temporarily unavailable" with an email fallback'], 'subscribers' => ['newsletter double opt-in', 'signup shows "temporarily unavailable"'], 'events' => ['consent-gated events', 'no events are recorded']],
  'database/phase4_business.sql' => ['leads' => ['lead qualification, attribution and status (additive columns)', 'extra answers are appended to the message; status/attribution unavailable'], 'lead_status_history' => ['lead status history', 'status changes work but history is not kept']],
  'database/phase6_crm.sql' => [],
  'database/install_compare_tables.sql' => ['compare_tool_profiles' => ['source-backed comparison data', 'comparison pages use basic catalogue data'], 'compare_tool_points' => ['source-backed comparison data', 'comparison pages use basic catalogue data'], 'compare_tool_sources' => ['source-backed comparison data', 'comparison pages use basic catalogue data']],
];
foreach ($phase as $file => $tables) foreach (parse_sql("$root/$file") as $t => $cols) {
  $meta = $tables[$t] ?? ($file === 'database/phase6_crm.sql' ? ['CRM, sales pipeline, proposals and revenue records (Phase 6)', 'the CRM at /crm.php shows "not set up"; public forms and every earlier feature keep working'] : ['feature', 'degrades']);
  if (isset($expected[$t])) { $expected[$t]['columns'] += $cols; $expected[$t]['file'] .= '; ' . $file; $ext = ($file === 'database/phase4_business.sql'); if ($ext) $expected[$t]['extension_columns'] = array_keys($cols); continue; }
  $expected[$t] = ['feature' => $meta[0], 'degrade' => $meta[1], 'required' => false, 'columns' => $cols, 'notes' => '', 'file' => $file];
}
$q = $pdo->prepare('SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?'); $q->execute([$dbName]);
$have = []; foreach ($q->fetchAll(PDO::FETCH_NUM) as [$t, $c, $dt]) $have[strtolower($t)][strtolower($c)] = ty($dt);
$q = $pdo->prepare('SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?'); $q->execute([$dbName]);
$meta = []; foreach ($q->fetchAll(PDO::FETCH_NUM) as [$t, $e, $c]) $meta[strtolower($t)] = ['engine' => $e, 'collation' => $c];
$report = []; $broken = 0; $degraded = 0;
foreach ($expected as $t => $d) {
  $row = ['table' => $t, 'feature' => $d['feature'], 'defined_in' => $d['file'], 'status' => 'ok', 'missing_columns' => [], 'type_mismatches' => [], 'extra_columns' => [], 'notes' => $d['notes'], 'if_not_ok' => $d['degrade']];
  if (!isset($have[$t])) { $row['status'] = 'missing_table'; }
  else {
    foreach ($d['columns'] as $c => $tf) {
      if (!isset($have[$t][$c])) $row['missing_columns'][] = $c;
      elseif ($have[$t][$c] !== $tf && !(in_array($tf, ['varchar', 'text']) && in_array($have[$t][$c], ['varchar', 'text', 'enum']))) $row['type_mismatches'][] = "$c: has {$have[$t][$c]}, code expects $tf";
    }
    $row['extra_columns'] = array_values(array_diff(array_keys($have[$t]), array_keys($d['columns'])));
    if ($row['missing_columns'] || $row['type_mismatches']) $row['status'] = 'incompatible';
    $ext = $d['extension_columns'] ?? [];
    if ($ext && !array_diff($row['missing_columns'], $ext) && !$row['type_mismatches'] && $row['missing_columns']) $row['status'] = 'needs_phase4_migration';
    $row['engine'] = $meta[$t]['engine'] ?? ''; $row['collation'] = $meta[$t]['collation'] ?? '';
    if ($row['status'] === 'ok' && !empty($row['collation']) && strpos($row['collation'], 'utf8mb4') !== 0) $row['status'] = 'charset_warning';
  }
  if ($row['status'] !== 'ok') { if ($d['required'] && in_array($row['status'], ['missing_table', 'incompatible'], true)) $broken++; else $degraded++; }
  $row['required'] = $d['required'];
  $report[] = $row;
}
// ---- migration plan (text only) ----------------------------------------------------------------------------------------------------------
$steps = [];
foreach (array_keys(parse_sql("$root/database/phase6_crm.sql")) as $__t6) $sqlFileFor[$__t6] = 'phase6_crm.sql';
$sqlFileFor = ($sqlFileFor ?? []) + ['page_views' => 'phase2_analytics.sql', 'leads' => 'phase3_business.sql', 'subscribers' => 'phase3_business.sql', 'events' => 'phase3_business.sql', 'lead_status_history' => 'phase4_business.sql', 'compare_tool_profiles' => 'install_compare_tables.sql', 'compare_tool_points' => 'install_compare_tables.sql', 'compare_tool_sources' => 'install_compare_tables.sql', 'categories' => 'full_mysql_schema.sql', 'tools' => 'full_mysql_schema.sql', 'posts' => 'full_mysql_schema.sql', 'use_cases' => 'full_mysql_schema.sql', 'site_settings' => 'full_mysql_schema.sql', 'submissions' => 'full_mysql_schema.sql'];
$idx = []; $q = $pdo->prepare('SELECT TABLE_NAME, INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ?'); $q->execute([$dbName]); foreach ($q->fetchAll(PDO::FETCH_NUM) as [$t, $i]) $idx[strtolower($t)][$i] = true;
$voteType = ''; if (isset($have['tool_votes']['vote_type'])) { $q = $pdo->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'tool_votes' AND COLUMN_NAME = 'vote_type'"); $q->execute([$dbName]); $voteType = strtolower((string)$q->fetchColumn()); }
foreach ($report as $r) {
  $t = $r['table'];
  if ($r['status'] === 'missing_table') { if (in_array($t, ['tool_clicks', 'tool_votes'], true)) $steps[] = "-- $t is missing: create it with tests/fixtures/interactions_current.sql (the current shape; the install SQL files are outdated)"; elseif ($t === 'contact_messages') $steps[] = "-- contact_messages is missing: no production SQL file creates it; use the CREATE TABLE in tests/fixtures/interactions_current.sql"; elseif (isset($sqlFileFor[$t])) $steps[] = "-- $t is missing: run database/{$sqlFileFor[$t]} (CREATE TABLE IF NOT EXISTS, safe to repeat)"; continue; }
  if ($t === 'tool_votes' && strpos($voteType, 'enum') === 0 && strpos($voteType, "'up'") === false) { $steps[] = "ALTER TABLE tool_votes MODIFY vote_type VARCHAR(10) NOT NULL;"; $steps[] = "UPDATE tool_votes SET vote_type = 'up' WHERE vote_type = 'like';"; $steps[] = "UPDATE tool_votes SET vote_type = 'down' WHERE vote_type = 'dislike';"; }
  foreach ($r['missing_columns'] as $c) { $ddl = $expected[$t]['ddl'][$c] ?? null; if ($ddl) $steps[] = "ALTER TABLE `$t` ADD COLUMN `$c` $ddl;"; elseif ($r['status'] === 'needs_phase4_migration' || $t === 'leads') { $steps[] = "-- `$t`.`$c` belongs to database/phase4_business.sql (run the whole ALTER once; it is additive)"; } else $steps[] = "-- `$t`.`$c` is missing and has no automatic fix: compare with database/expected_schema.json"; }
  if ($t === 'tool_votes' && in_array('visitor_id', $r['missing_columns'], true)) { $steps[] = "UPDATE tool_votes SET visitor_id = MD5(CONCAT(COALESCE(ip_address, ''), ':', id)) WHERE visitor_id IS NULL OR visitor_id = '';"; if (!empty($idx['tool_votes']['uniq_tool_vote_ip'])) $steps[] = "ALTER TABLE tool_votes DROP INDEX uniq_tool_vote_ip, ADD UNIQUE KEY uniq_tool_vote_visitor (tool_slug, visitor_id);"; }
}
$steps = array_values(array_unique($steps));
$out = ['database' => $dbName, 'tables_checked' => count($report), 'broken' => $broken, 'degraded' => $degraded, 'rows' => $report, 'read_only' => true, 'plan' => $steps];
if ($json) { echo json_encode($out, JSON_PRETTY_PRINT), "\n"; exit($broken ? 1 : 0); }
echo "Database compatibility preflight for '$dbName' (read-only)\n";
printf("%-24s %-22s %s\n", 'table', 'status', 'detail');
foreach ($report as $r) {
  $detail = $r['status'] === 'missing_table' ? "missing. If absent: {$r['if_not_ok']}" : trim(($r['missing_columns'] ? 'missing columns: ' . implode(', ', $r['missing_columns']) . '. ' : '') . ($r['type_mismatches'] ? 'type: ' . implode('; ', $r['type_mismatches']) . '. ' : '') . ($r['status'] !== 'ok' ? 'If not fixed: ' . $r['if_not_ok'] : ''));
  printf("%-24s %-22s %s%s\n", $r['table'], $r['status'], $r['required'] ? '[required] ' : '', $detail);
}
echo "\nRequired features broken: $broken   Optional/degraded: $degraded\n";
if ($plan) { echo "\n-- Migration plan (NOT executed; review, then run on a COPY after taking a backup)\n" . ($steps ? implode("\n", $steps) : "-- nothing to do") . "\n"; }
echo "Nothing was changed. See docs/phase4/DATABASE_COMPATIBILITY_REPORT.md for the migration order.\n";
exit($broken ? 1 : 0);
