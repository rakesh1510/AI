<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Retries every spooled e-mail that is due (owner notifications, customer confirmations, contact messages, tool submissions, newsletter confirmations).
 * Run from cron every 5 minutes, as the web server's user:   *\/5 * * * *  php /var/www/html/AI-PHASE3-TEST/scripts/mail_spool_flush.php >> /path/to/mail_spool.log 2>&1
 * Output contains counts only: never an address, message text or credential.  Exit code 0 always (cron must not mail noise); failures are in the security log.
 * Flags: --force  retry every message now, ignoring the back-off schedule.
 * Safe to run concurrently (per-file locks). Never touches the database except to mark a lead's notification as sent after a successful retry.
 */
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../conversion.php';
$pdo = null; try { $pdo = db(); } catch (Throwable $e) { $pdo = null; }
$force = in_array('--force', $argv, true);   // ignore the retry back-off and try everything now (operator action)
$res = mail_spool_flush(100, null, null,
  function (array $meta) use (&$pdo) {   // after a successful retry of an owner notification: record it on the lead
    if ($pdo && ($meta['kind'] ?? '') === 'owner' && (int)($meta['lead_id'] ?? 0) > 0) lead_record_notification($pdo, (int)$meta['lead_id'], true, '');
  },
  function (array $meta) use (&$pdo): bool {   // skip owner mails for leads the owner already re-sent from the admin screen
    if ($pdo && ($meta['kind'] ?? '') === 'owner' && (int)($meta['lead_id'] ?? 0) > 0) {
      try { if (db_column_exists($pdo, 'leads', 'notify_status')) { $s = $pdo->prepare('SELECT notify_status FROM leads WHERE id = ?'); $s->execute([(int)$meta['lead_id']]); if ($s->fetchColumn() === 'sent') return false; } } catch (Throwable $e) { }
    }
    return true;
  }, $force);
$c = mail_spool_counts();
printf("%s mail spool: sent=%d failed=%d dead=%d waiting=%d | pending=%d dead_total=%d\n", date('c'), $res['sent'], $res['failed'], $res['dead'], $res['waiting'], $c['pending'], $c['dead']);
