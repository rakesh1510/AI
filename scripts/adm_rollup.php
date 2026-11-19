<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Control Center analytics rollup (CLI only; schedule it, e.g. hourly). Rebuilds the adm_* tables from the real rows of page_views and events (idempotent per day).
 * Reads only; writes only the five adm_* tables created by database/phase8b_analytics.sql. It never changes page_views, events, tool_clicks or any other table.
 *
 *   php scripts/adm_rollup.php                      yesterday and today (the normal scheduled run)
 *   php scripts/adm_rollup.php --days=N             the last N days (1..400)
 *   php scripts/adm_rollup.php --from=YYYY-MM-DD --to=YYYY-MM-DD
 *   php scripts/adm_rollup.php --since-first        every day from the first recorded row (a back-fill of REAL rows only)
 * Exit codes: 0 ok, 1 failed, 2 refused (bad options or the storage tables are not installed).
 */
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../adm_analytics.php';
function ar_arg(string $n): ?string { foreach ($GLOBALS['argv'] as $a) if (strpos($a, "--$n=") === 0) return substr($a, strlen($n) + 3); return null; }
$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (!adma_storage_ready($pdo)) { fwrite(STDERR, "The Phase 8B storage tables are missing. Apply database/phase8b_analytics.sql after review.\n"); exit(2); }
$today = gmdate('Y-m-d'); $from = $to = null;
$d = fn(?string $s) => $s !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && checkdate((int)substr($s, 5, 2), (int)substr($s, 8, 2), (int)substr($s, 0, 4));
if (in_array('--since-first', $argv, true)) {
  $mins = []; foreach (['page_views', 'events'] as $t) if (adma_table($pdo, $t)) { $m = $pdo->query("SELECT MIN(day) FROM $t")->fetchColumn(); if ($m) $mins[] = (string)$m; }
  if (!$mins) { echo json_encode(['status' => 'nothing_to_do', 'note' => 'no tracking rows exist']) . "\n"; exit(0); }
  sort($mins); $from = $mins[0]; $to = $today;
} elseif (ar_arg('from') !== null || ar_arg('to') !== null) {
  $from = ar_arg('from'); $to = ar_arg('to') ?? $today;
  if (!$d($from) || !$d($to) || $from > $to || $to > $today) { fwrite(STDERR, "refused: --from/--to must be real dates, from <= to <= today\n"); exit(2); }
} else {
  $n = ar_arg('days') !== null ? (int)ar_arg('days') : 2; if ($n < 1 || $n > 400) { fwrite(STDERR, "refused: --days must be 1..400\n"); exit(2); }
  $from = gmdate('Y-m-d', time() - ($n - 1) * 86400); $to = $today;
}
try { $r = adma_rollup_range($pdo, $from, $to); echo json_encode(['status' => 'ok', 'from' => $from, 'to' => $to] + $r) . "\n"; exit(0); }
catch (Throwable $e) { fwrite(STDERR, 'rollup failed: ' . get_class($e) . "\n"); exit(1); }
