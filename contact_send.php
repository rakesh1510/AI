<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

sec_session_start();

function fail($msg){
  header("Location: contact.php?err=" . urlencode($msg));
  exit;
}

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
  header("Location: contact.php");
  exit;
}

/* CSRF check */
if(empty($_POST['csrf']) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])){
  fail("Security check failed.");
}

if(!sec_same_origin()){ sec_log('contact_origin_rejected'); fail("Security check failed."); }
if(sec_rate_limited('contact', 5, 3600)){ sec_log('rate_limited', ['scope'=>'contact']); fail("Too many messages. Please try again later."); }

/* Honeypot spam protection */
if(!empty($_POST['company'])){
  fail("Spam detected.");
}

$name    = mb_substr(trim($_POST['name'] ?? ''), 0, 120);
$email   = mb_substr(trim($_POST['email'] ?? ''), 0, 190);
$subject = mb_substr(preg_replace('/[\r\n]+/', ' ', trim($_POST['subject'] ?? '')), 0, 160);
$message = mb_substr(trim($_POST['message'] ?? ''), 0, 4000);

if($name==='' || $email==='' || $subject==='' || $message===''){
  fail("Please fill in all fields.");
}

if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
  fail("Invalid email address.");
}

if(strlen($message) < 10){
  fail("Message too short.");
}

/* --------------------------------
   SAVE MESSAGE INTO MYSQL
-------------------------------- */

try {
$stmt = db()->prepare("
INSERT INTO contact_messages
(name,email,subject,message,ip_address,user_agent)
VALUES
(:name,:email,:subject,:message,:ip,:ua)
");

$stmt->execute([
  ':name' => $name,
  ':email' => $email,
  ':subject' => $subject,
  ':message' => $message,
  ':ip' => sec_ip_hash('contact'),   // salted hash only: no raw IP address or user agent is stored
  ':ua' => ''
]);
} catch(Throwable $e){ sec_log_exception($e, 'contact_send.php:save'); fail("Sorry, your message could not be saved. Please try again later."); }

/* --------------------------------
   EMAIL SEND (PHPMailer)
-------------------------------- */

$cfg = site_cfg();
$mailCfg = $cfg['mail'] ?? [];

$toEmail   = $mailCfg['to_email'] ?? '';
$fromEmail = $mailCfg['from_email'] ?? '';
$fromName  = $mailCfg['from_name'] ?? 'AIHubpedia';

$smtpHost   = $mailCfg['smtp_host'] ?? '';
$smtpPort   = (int)($mailCfg['smtp_port'] ?? 587);
$smtpSecure = $mailCfg['smtp_secure'] ?? 'tls';
$smtpUser   = $mailCfg['smtp_user'] ?? '';
$smtpPass   = $mailCfg['smtp_pass'] ?? '';

if($toEmail==='' || $smtpUser==='' || $smtpPass===''){
  fail("Mail configuration missing.");
}

/* PHPMailer */
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

try {

  $mail = new PHPMailer(true);

  $mail->isSMTP();
  $mail->Host = $smtpHost;
  $mail->SMTPAuth = true;
  $mail->Username = $smtpUser;
  $mail->Password = $smtpPass;
  $mail->SMTPSecure = $smtpSecure;
  $mail->Port = $smtpPort;

  $mail->setFrom($fromEmail, $fromName);
  $mail->addAddress($toEmail);
  $mail->addReplyTo($email, $name);

  $mail->isHTML(true);
  $mail->Subject = "[AIHubpedia Contact] ".$subject;

  $safeMsg = nl2br(h($message));

  $mail->Body = "
  <h2>New Contact Message</h2>
  <p><b>Name:</b> ".h($name)."</p>
  <p><b>Email:</b> ".h($email)."</p>
  <p><b>Subject:</b> ".h($subject)."</p>
  <p><b>Message:</b><br>{$safeMsg}</p>
  ";

  $mail->AltBody =
  "Name: $name\nEmail: $email\nSubject: $subject\n\n$message";

  $mail->send();

} catch (Exception $e) {
  sec_log_exception($e, 'contact_send.php');
  fail("Your message was saved, but the notification email could not be sent.");
}

/* SUCCESS */
header("Location: contact.php?sent=1");
exit;