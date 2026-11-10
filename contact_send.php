<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

session_start();

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

/* Honeypot spam protection */
if(!empty($_POST['company'])){
  fail("Spam detected.");
}

$name    = trim($_POST['name'] ?? '');
$email   = trim($_POST['email'] ?? '');
$subject = trim($_POST['subject'] ?? '');
$message = trim($_POST['message'] ?? '');

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
  ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
  ':ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '',0,255)
]);

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
  fail("Mail error: ".$e->getMessage());
}

/* SUCCESS */
header("Location: contact.php?sent=1");
exit;