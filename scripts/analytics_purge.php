<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Analytics data minimisation (CLI only). DRY RUN by default: it only prints what would change. Nothing is scheduled by the application; an administrator decides.
 *
 *   php scripts/analytics_purge.php --raw-days=N --id-days=M            dry run
 *   php scripts/analytics_purge.php --raw-days=N --id-days=M --apply    really delete / blank (take a backup first)
 *
 * --raw-days  page_views and events older than N days are DELETED (N between 30 and 800). Daily rollups keep the aggregates.
 * --id-days   tool_clicks keep the row (lifetime counter) but lose visitor_id and ip_hash when older than M days (M between 7 and N).
 * There is no default: the retention period is a decision, not a setting we pick for you. Exit codes: 0 ok, 2 refused.
 */
require_once __DIR__ . '/../helpers.php'; require_once __DIR__ . '/../db.php'; require_once __DIR__ . '/../analytics_retention.php';
function ap_arg(string $n): ?string { foreach ($GLOBALS['argv'] as $a) if (strpos($a, "--$n=") === 0) return substr($a, strlen($n) + 3); return null; }
$raw = ap_arg('raw-days'); $id = ap_arg('id-days');
if ($raw === null || $id === null || !ctype_digit($raw) || !ctype_digit($id)) { fwrite(STDERR, "refused: give --raw-days=N and --id-days=M (no default retention is assumed)\n"); exit(2); }
$raw = (int)$raw; $id = (int)$id;
if (!analytics_retention_days_ok($raw) || $id < ANALYTICS_ID_RETENTION_MIN_DAYS || $id > $raw) { fwrite(STDERR, "refused: --raw-days must be 30..800 and --id-days must be 7..raw-days\n"); exit(2); }
$pdo = db(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
echo json_encode(['mode' => in_array('--apply', $argv, true) ? 'apply' : 'dry_run'] + analytics_retention_plan($pdo, $raw, $id)) . "\n";
if (in_array('--apply', $argv, true)) echo json_encode(['applied' => analytics_retention_apply($pdo, $raw, $id)]) . "\n";
