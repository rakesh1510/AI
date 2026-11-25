<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Read-only e-mail health check. Prints booleans and counts only: never a password, user name, full address or message text.
 *   php scripts/mail_health.php            configuration report + spool counts + lead/submission delivery counts
 *   php scripts/mail_health.php --probe    also connects and authenticates to the SMTP server (sends NO message)
 * Note: the CLI sees the same config.local.php and database as the web, but NOT environment variables set only for the web server/PHP-FPM pool.
 */
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../conversion.php';
$r = mail_config_report();
echo "== configuration (booleans only) ==\n";
foreach ($r as $k => $v) { if ($k === 'spool') continue; printf("%-24s %s\n", $k, is_bool($v) ? ($v ? 'yes' : 'NO') : (string)$v); }
printf("%-24s pending=%d dead=%d\n", 'spool', $r['spool']['pending'], $r['spool']['dead']);
echo "\n== where each setting comes from (labels only, no values) ==\n";
foreach (mail_config_sources() as $k => $v) printf("%-28s %s\n", $k, $v);
$problems = [];
if ($r['transport'] === 'file') $problems[] = 'transport is "file": messages are only written to a mock outbox, nothing is sent';
if (!$r['smtp_host_set'] || !$r['from_set']) $problems[] = 'SMTP host or from address missing';
if (!$r['smtp_user_set'] || !$r['smtp_pass_set']) $problems[] = 'SMTP user and/or password not set: most providers (Gmail, Microsoft 365) reject unauthenticated sends';
if (!$r['to_valid']) $problems[] = 'owner notification address missing or invalid';
if (!$r['phpmailer_installed']) $problems[] = 'vendor/autoload.php (PHPMailer) missing';
if (!$r['spool_dir_writable']) $problems[] = 'spool directory not writable: failed messages could not be kept for retry';
echo "\n== findings ==\n" . ($problems ? '- ' . implode("\n- ", $problems) : 'none') . "\n";
try {
  $pdo = db();
  echo "\n== lead delivery (leads table) ==\n";
  if (db_table_exists('leads') && db_column_exists($pdo, 'leads', 'notify_status')) {
    foreach ($pdo->query("SELECT COALESCE(NULLIF(notify_status,''),'(never attempted)') s, COUNT(*) n, MAX(notify_attempts) maxatt FROM leads WHERE COALESCE(is_synthetic,0)=0 GROUP BY 1")->fetchAll() as $x) printf("%-20s %d (max attempts %d)\n", $x['s'], $x['n'], $x['maxatt']);
    echo "latest failures (id, created, type, reason code):\n";
    foreach ($pdo->query("SELECT id, created_at, lead_type, notify_error FROM leads WHERE notify_status='failed' ORDER BY id DESC LIMIT 5")->fetchAll() as $x) printf("  %d  %s  %s  %s\n", $x['id'], $x['created_at'], $x['lead_type'], $x['notify_error']);
  } else echo "leads table or notification columns not present\n";
  foreach (['contact_messages' => 'contact messages', 'submissions' => 'tool submissions', 'subscribers' => 'newsletter subscribers'] as $t => $label) printf("%-24s %s\n", $label, db_table_exists($t) ? (string)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn() : 'TABLE MISSING');
} catch (Throwable $e) { echo "database not reachable from the CLI\n"; }
if (in_array('--probe', $argv, true)) { $p = mail_smtp_probe(); echo "\n== SMTP probe (connect + authenticate, no message sent) ==\n" . $p . ($p === 'ok' ? '' : ' - ' . mail_error_label($p)) . "\n"; }
