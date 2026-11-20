<?php
if (isset($_SERVER["SCRIPT_FILENAME"]) && realpath($_SERVER["SCRIPT_FILENAME"]) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
/**
 * Analytics A1: data minimisation. Pure SQL helpers used by scripts/analytics_purge.php (CLI, dry run by default, never scheduled by the application).
 *  - page_views and events: rows older than the cut-off day are DELETED (the daily rollups keep the aggregates; they contain no hashes).
 *  - tool_clicks: rows are KEPT (lifetime popularity counter) but the identifiers (visitor_id cookie value, ip_hash) are blanked for rows older than the cut-off.
 * Nothing runs unless an administrator runs the script with --apply and an explicit retention period.
 */
const ANALYTICS_RETENTION_MIN_DAYS = 30;
const ANALYTICS_RETENTION_MAX_DAYS = 800;
const ANALYTICS_ID_RETENTION_MIN_DAYS = 7;
const ANALYTICS_ROLLUP_MIN_DAYS = 365;   // aggregates carry no hash, IP or cookie; at least a full year is kept for year-on-year comparison
const ANALYTICS_ROLLUP_TABLES = ['adm_traffic_daily', 'adm_traffic_dim_daily', 'adm_tool_daily', 'adm_event_daily'];

function analytics_retention_cutoff(int $days, ?int $now = null): string { return gmdate('Y-m-d', ($now ?? time()) - $days * 86400); }
function analytics_retention_days_ok(int $days): bool { return $days >= ANALYTICS_RETENTION_MIN_DAYS && $days <= ANALYTICS_RETENTION_MAX_DAYS; }
function analytics_retention_table(PDO $pdo, string $t): bool { try { $pdo->query('SELECT 1 FROM ' . $t . ' LIMIT 1'); return true; } catch (Throwable $e) { return false; } }

/** Days older than the cut-off that still have raw rows but no rollup row: deleting them would lose history. Empty = safe. Needs the adm_traffic_daily table (returns null when it is missing). */
function analytics_retention_unrolled_days(PDO $pdo, int $rawDays, ?int $now = null): ?array {
  if (!analytics_retention_table($pdo, 'adm_traffic_daily')) return null; if (!analytics_retention_table($pdo, 'page_views')) return [];
  $q = $pdo->prepare('SELECT DISTINCT day FROM page_views WHERE day < ? AND day NOT IN (SELECT day FROM adm_traffic_daily) ORDER BY day LIMIT 50'); $q->execute([analytics_retention_cutoff($rawDays, $now)]); return array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN));
}

/** Counts what WOULD be removed or blanked. @return array<string,int|null> null = table missing */
function analytics_retention_plan(PDO $pdo, int $rawDays, int $idDays, ?int $now = null, ?int $rollupDays = null): array {
  $raw = analytics_retention_cutoff($rawDays, $now); $id = analytics_retention_cutoff($idDays, $now); $out = [];
  if ($rollupDays !== null) foreach (ANALYTICS_ROLLUP_TABLES as $t) { if (!analytics_retention_table($pdo, $t)) { $out[$t . '_delete'] = null; continue; } $q = $pdo->prepare("SELECT COUNT(*) FROM $t WHERE day < ?"); $q->execute([analytics_retention_cutoff($rollupDays, $now)]); $out[$t . '_delete'] = (int)$q->fetchColumn(); }
  foreach (['page_views' => ['day', 'page_views_delete'], 'events' => ['day', 'events_delete']] as $t => [$col, $k]) {
    if (!analytics_retention_table($pdo, $t)) { $out[$k] = null; continue; } $q = $pdo->prepare("SELECT COUNT(*) FROM $t WHERE $col < ?"); $q->execute([$raw]); $out[$k] = (int)$q->fetchColumn();
  }
  if (!analytics_retention_table($pdo, 'tool_clicks')) $out['tool_clicks_blank'] = null;
  else { $q = $pdo->prepare("SELECT COUNT(*) FROM tool_clicks WHERE created_at < ? AND (COALESCE(visitor_id, '') <> '' OR COALESCE(ip_hash, '') <> '')"); $q->execute([$id . ' 00:00:00']); $out['tool_clicks_blank'] = (int)$q->fetchColumn(); }
  return $out + ['raw_cutoff' => $raw, 'id_cutoff' => $id];
}

/** Applies the plan in bounded batches inside the caller's chosen environment. @return array<string,int> rows affected */
function analytics_retention_apply(PDO $pdo, int $rawDays, int $idDays, ?int $now = null, ?int $rollupDays = null): array {
  $raw = analytics_retention_cutoff($rawDays, $now); $id = analytics_retention_cutoff($idDays, $now); $n = ['page_views' => 0, 'events' => 0, 'tool_clicks_blanked' => 0];
  $un = analytics_retention_unrolled_days($pdo, $rawDays, $now);
  if ($un === null || $un) return ['refused' => $un === null ? 'The rollup tables are not installed, so raw rows would be deleted without any aggregate being kept.' : 'Raw rows exist for days that have no rollup yet (' . implode(', ', array_slice($un, 0, 5)) . (count($un) > 5 ? ', ...' : '') . '). Run scripts/adm_rollup.php --since-first first.'] + $n;
  foreach (['page_views', 'events'] as $t) if (analytics_retention_table($pdo, $t)) { $s = $pdo->prepare("DELETE FROM $t WHERE day < ?"); $s->execute([$raw]); $n[$t] = $s->rowCount(); }
  if (analytics_retention_table($pdo, 'tool_clicks')) { $s = $pdo->prepare("UPDATE tool_clicks SET visitor_id = '', ip_hash = '' WHERE created_at < ? AND (COALESCE(visitor_id, '') <> '' OR COALESCE(ip_hash, '') <> '')"); $s->execute([$id . ' 00:00:00']); $n['tool_clicks_blanked'] = $s->rowCount(); }
  if ($rollupDays !== null) foreach (ANALYTICS_ROLLUP_TABLES as $t) if (analytics_retention_table($pdo, $t)) { $s = $pdo->prepare("DELETE FROM $t WHERE day < ?"); $s->execute([analytics_retention_cutoff($rollupDays, $now)]); $n[$t] = $s->rowCount(); }
  return $n;
}
