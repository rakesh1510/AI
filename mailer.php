<?php
/** Minimal PHPMailer wrapper using the site's mail settings. Returns false (never throws) when mail is unavailable. */
function mail_configured(): bool {
  $m = (site_cfg()['mail'] ?? []);
  return is_array($m) && !empty($m['smtp_host']) && !empty($m['from_email']);
}

function send_mail(string $to, string $subject, string $html, string $text = '', string $replyTo = ''): bool {
  if (!mail_configured() || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
  $autoload = __DIR__ . '/vendor/autoload.php';
  if (!is_file($autoload)) return false;
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
    $mail->CharSet = 'UTF-8';
    $mail->setFrom((string)$m['from_email'], (string)($m['from_name'] ?? 'AIHubpedia'));
    $mail->addAddress($to);
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $mail->addReplyTo($replyTo);
    $mail->isHTML(true);
    $mail->Subject = preg_replace('/[\r\n]+/', ' ', $subject);
    $mail->Body = $html;
    $mail->AltBody = $text !== '' ? $text : trim(strip_tags($html));
    return $mail->send();
  } catch (Throwable $e) {
    return false;
  }
}

function site_owner_email(): string { return (string)((site_cfg()['mail'] ?? [])['to_email'] ?? ''); }
