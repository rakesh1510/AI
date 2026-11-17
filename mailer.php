<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly (defence in depth if web-server rules are missing)
/** Minimal PHPMailer wrapper using the site's mail settings. Returns false (never throws) when mail is unavailable. */
function mail_configured(): bool {
  $m = (site_cfg()['mail'] ?? []);
  return is_array($m) && !empty($m['smtp_host']) && !empty($m['from_email']);
}

/**
 * Mail transport. Default is SMTP via PHPMailer. With AIHUB_MAIL_TRANSPORT=file every message is written as a JSON file into
 * AIHUB_MAIL_OUTBOX instead of being sent (mock delivery for tests and rehearsals; nothing leaves the machine).
 */
function mail_transport(): string { return strtolower(sec_setting('AIHUB_MAIL_TRANSPORT', 'mail_transport', 'smtp')) === 'file' ? 'file' : 'smtp'; }

function mail_outbox_write(string $to, string $subject, string $html, string $text, string $replyTo, array $headers): bool {
  $dir = sec_setting('AIHUB_MAIL_OUTBOX', 'mail_outbox');
  if ($dir === '' || (!is_dir($dir) && !@mkdir($dir, 0700, true))) return false;
  $name = rtrim($dir, '/') . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
  return @file_put_contents($name, json_encode(['to' => $to, 'subject' => $subject, 'html' => $html, 'text' => $text, 'reply_to' => $replyTo, 'headers' => $headers, 'mock' => true], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false;
}

/**
 * Why the last send_mail() failed, as a short code (never the SMTP server's text, which can contain host names, user names or addresses).
 * Codes: '' (no failure), not_configured, invalid_recipient, library_missing, smtp_connect, smtp_auth, smtp_rejected, smtp_other, outbox_unwritable.
 */
function mail_last_error(?string $set = null): string { static $code = ''; if ($set !== null) $code = $set; return $code; }

function mail_error_label(string $code): string {
  return ['' => '', 'not_configured' => 'Mail is not configured', 'invalid_recipient' => 'The notification address is invalid', 'library_missing' => 'The mail library is not installed',
    'smtp_connect' => 'The mail server could not be reached', 'smtp_auth' => 'The mail server rejected the login', 'smtp_rejected' => 'The mail server rejected the message',
    'smtp_other' => 'The mail server reported an error', 'outbox_unwritable' => 'The mock outbox is not writable'][$code] ?? 'Unknown mail error';
}

/** Maps a PHPMailer exception message to a safe code. */
function mail_classify_error(string $msg): string {
  $m = strtolower($msg);
  if (strpos($m, 'connect') !== false || strpos($m, 'timed out') !== false || strpos($m, 'getaddrinfo') !== false) return 'smtp_connect';
  if (strpos($m, 'authenticate') !== false || strpos($m, 'username') !== false || strpos($m, 'password') !== false) return 'smtp_auth';
  if (strpos($m, 'recipient') !== false || strpos($m, 'data not accepted') !== false || strpos($m, 'invalid address') !== false) return 'smtp_rejected';
  return 'smtp_other';
}

function send_mail(string $to, string $subject, string $html, string $text = '', string $replyTo = '', array $headers = []): bool {
  mail_last_error('');
  if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { mail_last_error('invalid_recipient'); return false; }
  $subject = preg_replace('/[\r\n]+/', ' ', $subject);
  if (mail_transport() === 'file') { $ok = mail_outbox_write($to, $subject, $html, $text !== '' ? $text : trim(strip_tags($html)), $replyTo, $headers); if (!$ok) mail_last_error('outbox_unwritable'); return $ok; }
  if (!mail_configured()) { mail_last_error('not_configured'); return false; }
  $autoload = __DIR__ . '/vendor/autoload.php';
  if (!is_file($autoload)) { mail_last_error('library_missing'); return false; }
  require_once $autoload;
  $m = site_cfg()['mail'];
  try {
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = (string)$m['smtp_host'];
    $mail->Port = (int)($m['smtp_port'] ?? 587);
    $user = (string)($m['smtp_user'] ?? ''); $pass = (string)($m['smtp_pass'] ?? '');
    $mail->SMTPAuth = $user !== '' && $pass !== '';
    $mail->Username = $user; $mail->Password = $pass;
    $mail->SMTPSecure = (string)($m['smtp_secure'] ?? '');
    $mail->SMTPAutoTLS = (string)($m['smtp_secure'] ?? '') !== '';
    $mail->Timeout = 8;
    $mail->Hostname = (string)parse_url(AIHUB_SITE_URL, PHP_URL_HOST);   // Message-ID and HELO must not be built from the (forgeable) request Host header
    $mail->CharSet = 'UTF-8';
    $mail->setFrom((string)$m['from_email'], (string)($m['from_name'] ?? 'AIHubpedia'));
    $mail->addAddress($to);
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $mail->addReplyTo($replyTo);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    foreach ($headers as $hk => $hv) { if (preg_match('/^[A-Za-z-]+$/', (string)$hk)) $mail->addCustomHeader((string)$hk, preg_replace('/[\r\n]+/', ' ', (string)$hv)); }
    $mail->Body = $html;
    $mail->AltBody = $text !== '' ? $text : trim(strip_tags($html));
    $sent = $mail->send();
    if (!$sent) mail_last_error(mail_classify_error((string)$mail->ErrorInfo));
    return $sent;
  } catch (Throwable $e) {
    mail_last_error(mail_classify_error($e->getMessage()));
    return false;
  }
}

function site_owner_email(): string { return (string)((site_cfg()['mail'] ?? [])['to_email'] ?? ''); }
