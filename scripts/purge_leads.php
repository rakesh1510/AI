<?php
/**
 * Data-retention helper: deletes leads older than N days (default dry-run).
 * Usage: php scripts/purge_leads.php --days=365 [--synthetic] [--apply]
 * --synthetic restricts the purge to synthetic rows. Without --apply nothing is deleted; it prints what would be.
 * Retention length is a legal decision for the site owner; 12 months is a common starting point for unanswered leads.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../helpers.php';
$days = 365; foreach ($argv as $a) if (strpos($a, '--days=') === 0) $days = max(1, (int)substr($a, 7));
$apply = in_array('--apply', $argv, true); $syn = in_array('--synthetic', $argv, true);
db_connection_available();
$pdo = db();
$where = 'created_at < DATE_SUB(NOW(), INTERVAL ' . (int)$days . ' DAY)' . ($syn && db_column_exists($pdo, 'leads', 'is_synthetic') ? ' AND is_synthetic = 1' : '');
$n = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE $where")->fetchColumn();
if (!$apply) { echo "Dry run: $n lead(s) older than $days days" . ($syn ? ' (synthetic only)' : '') . " would be deleted. Re-run with --apply.\n"; exit(0); }
if (db_table_exists('lead_status_history')) $pdo->exec("DELETE h FROM lead_status_history h JOIN leads l ON l.id = h.lead_id WHERE $where");
$d = $pdo->exec("DELETE FROM leads WHERE $where");
echo "Deleted $d lead(s).\n";
