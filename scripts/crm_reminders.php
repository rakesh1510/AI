<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Owner-only CRM notifications (Phase 7B). Never e-mails a customer. Uses the existing SMTP configuration and the mail spool (retried by scripts/mail_spool_flush.php).
 *   php scripts/crm_reminders.php digest      daily digest, working days, once per Berlin date (cron 08:30)
 *   php scripts/crm_reminders.php alerts      enquiries newly past the 4-business-hour target, once per lead (cron every 30 min in business hours)
 *   php scripts/crm_reminders.php reminders   overdue tasks and stale proposals, once per item per week (cron 10:00)
 * Flags: --dry-run (print, send and record nothing), --force (digest only: also on a non-working day; never bypasses the once-per-day guard).
 * Cron (staging example; CRON_TZ keeps the schedule in Berlin time):
 *   CRON_TZ=Europe/Berlin
 *   30 8 * * 1-5   www-data php /var/www/html/AI-PHASE3-TEST/scripts/crm_reminders.php digest >> /var/log/aihub_crm_reminders.log 2>&1
 *   *\/30 9-16 * * 1-5 www-data php /var/www/html/AI-PHASE3-TEST/scripts/crm_reminders.php alerts >> /var/log/aihub_crm_reminders.log 2>&1
 *   0 10 * * 1-5   www-data php /var/www/html/AI-PHASE3-TEST/scripts/crm_reminders.php reminders >> /var/log/aihub_crm_reminders.log 2>&1
 * Output: status words and counts only (no addresses, no message text). Exit code 0 unless misused (2) or the database is unreachable (1).
 */
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../mailer.php';
require_once __DIR__ . '/../crm_core.php';
$mode = $argv[1] ?? ''; if (!in_array($mode, ['digest', 'alerts', 'reminders'], true)) { fwrite(STDERR, "usage: crm_reminders.php digest|alerts|reminders [--dry-run] [--force]\n"); exit(2); }
$dry = in_array('--dry-run', $argv, true); $force = in_array('--force', $argv, true); $now = (int)(getenv('AIHUB_TEST_NOW') ?: time());   // AIHUB_TEST_NOW is for tests only
try { $pdo = db(); } catch (Throwable $e) { echo date('c') . " crm_reminders $mode: database unavailable\n"; exit(1); }
if (!crm_response_ready($pdo)) { echo date('c') . " crm_reminders $mode: skipped (Phase 7B migration not applied)\n"; exit(0); }
$st = $mode === 'digest' ? crm_send_digest($pdo, $now, $dry, $force) : ($mode === 'alerts' ? crm_send_alerts($pdo, $now, $dry) : crm_send_reminders($pdo, $now, $dry));
echo date('c') . " crm_reminders $mode: $st\n";
