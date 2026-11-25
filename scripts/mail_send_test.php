<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Sends ONE test message through the configured SMTP account, to the configured owner address only (never to a customer or any other address).
 *   php scripts/mail_send_test.php --yes
 * Without --yes it only prints what it would do. Output: ok / a safe reason code. Never prints a password, user name or full address.
 * "ok" means the SMTP server ACCEPTED the message. Delivery is confirmed only when it shows up in the owner's mailbox (check Inbox and Spam).
 */
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
$to = site_owner_email(); $r = mail_config_report();
printf("transport=%s host=%s port=%d encryption=%s auth=%s recipient_domain=%s\n", $r['transport'], $r['smtp_host'], $r['smtp_port'], mail_smtp_secure_mode((array)(site_cfg()['mail'] ?? [])) ?: 'none', ($r['smtp_user_set'] && $r['smtp_pass_set']) ? 'on' : 'OFF', $r['to_domain']);
if (!in_array('--yes', $argv, true)) { echo "dry run: add --yes to send one test message to the configured owner address\n"; exit(0); }
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { echo "FAILED: owner address missing or invalid\n"; exit(1); }
$stamp = gmdate('Y-m-d H:i:s') . ' UTC';
$ok = send_mail($to, 'AIHubpedia SMTP test ' . $stamp, '<p>This is a test message from the AIHubpedia staging mail system.</p><p>Sent ' . htmlspecialchars($stamp, ENT_QUOTES) . '. If you can read it, SMTP login and delivery to this mailbox work.</p>', "AIHubpedia SMTP test.\nSent $stamp.\n");
echo $ok ? "ok: accepted by the SMTP server. Now check the mailbox (Inbox and Spam) to confirm delivery.\n" : 'FAILED: ' . mail_last_error() . ' - ' . mail_error_label(mail_last_error()) . "\n";
exit($ok ? 0 : 1);
