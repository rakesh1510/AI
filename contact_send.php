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
   The message is stored first. A mail problem never turns a stored message into an error for the sender (they would resubmit and we would get
   duplicates); instead the notification is retried from the mail spool and the failure is logged. If the table is missing, the message is
   kept in the private spool so it is never lost.
-------------------------------- */
require_once __DIR__ . '/conversion.php';
$id = 0; $stored = false; $dupId = 0;
try {
  $pdo = db();
  $d = $pdo->prepare('SELECT id FROM contact_messages WHERE email = ? AND subject = ? AND message = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) ORDER BY id DESC LIMIT 1');
  $d->execute([$email, $subject, $message]); $dupId = (int)$d->fetchColumn();
  if ($dupId === 0) {
    $stmt = $pdo->prepare("
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
    $id = (int)$pdo->lastInsertId(); $stored = $id > 0;
  }
} catch(Throwable $e){ sec_log_exception($e, 'contact_send.php:save'); }

if ($dupId > 0) {   // double click or refresh: acknowledge without storing or emailing again
  sec_log('contact_duplicate_ignored', ['id' => $dupId]);
  header('Location: contact.php?sent=1&ref=' . rawurlencode(enquiry_reference('C', $dupId))); exit;
}

$ref = $stored ? enquiry_reference('C', $id) : '';
$toEmail = site_owner_email();
$subj = '[AIHubpedia Contact] ' . $subject . ($ref !== '' ? ' (' . $ref . ')' : '');
$body = '<h2>New Contact Message</h2>' . ($ref !== '' ? '<p><b>Reference:</b> ' . h($ref) . '</p>' : '')
  . '<p><b>Name:</b> ' . h($name) . '</p><p><b>Email:</b> ' . h($email) . '</p><p><b>Subject:</b> ' . h($subject) . '</p><p><b>Message:</b><br>' . nl2br(h($message)) . '</p>'
  . ($stored ? '' : '<p><b>Note:</b> this message could not be saved to the database; this email and the mail spool are the only copies.</p>');
$alt = ($ref !== '' ? "Reference: $ref\n" : '') . "Name: $name\nEmail: $email\nSubject: $subject\n\n$message";

$notified = false;
try { $notified = $toEmail !== '' ? send_mail_reliable($toEmail, $subj, $body, $alt, $email, [], ['kind' => 'contact', 'ref' => $ref]) : false; } catch (Throwable $e) { sec_log_exception($e, 'contact_send.php:notify'); }
if ($toEmail === '') { mail_last_error('not_configured'); mail_spool_add('', $subj, $body, $alt, $email, [], ['kind' => 'contact', 'ref' => $ref], 'not_configured'); }
if (!$notified) sec_log('contact_notification_failed', ['ref' => $ref, 'stored' => $stored ? 1 : 0, 'reason' => mail_last_error()]);
if (!$stored && !$notified) {
  // Nothing could be stored AND nothing could be emailed: only the spool copy exists (if even that failed, tell the sender honestly).
  if (mail_spool_counts()['pending'] === 0) fail('Sorry, your message could not be saved. Please email contact@aihubpedia.com instead.');
}
if ($stored) {   // acknowledgement to the sender (retried from the spool if it fails; never an error for the sender)
  try { lead_notify_customer(['name' => $name, 'email' => $email, 'label' => 'contact message'], $ref); } catch (Throwable $e) { sec_log_exception($e, 'contact_send.php:customer'); }
}

/* SUCCESS */
header('Location: contact.php?sent=1' . ($ref !== '' ? '&ref=' . rawurlencode($ref) : ''));
