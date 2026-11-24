<?php
// Newsletter signup with explicit consent and double opt-in. Stores and confirms subscribers; it does not send newsletter issues.
require_once __DIR__ . '/helpers.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Location: /', true, 302); exit; }
$email = trim((string)($_POST['email'] ?? ''));
$consent = !empty($_POST['consent']);
$tokenOk = form_token_valid((string)($_POST['form_token'] ?? ''), 'newsletter', 2);
$bot = !empty($_POST['website_url_confirm']);
$source = preg_match('#^/[a-z0-9._/?=&%-]{0,100}$#i', (string)($_POST['source_page'] ?? '')) ? (string)$_POST['source_page'] : '';
$status = 'error'; $message = ''; $code = 200;
if ($bot || !$tokenOk) { $message = 'Your form expired or was submitted too quickly. Please go back and try again.'; $code = 400; }
elseif (!$consent) { $message = 'Please tick the box to confirm you want to receive our emails.'; $code = 422; }
elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $message = 'Please enter a valid email address.'; $code = 422; }
else {
  try {
    if (!db_table_exists('subscribers')) throw new RuntimeException('no table');
    $r = newsletter_subscribe(db(), $email, $source, conversion_ip_hash());
    if ($r['status'] === 'rate_limited') { $message = 'Too many attempts from your network. Please try again later.'; $code = 429; }
    elseif (in_array($r['status'], ['created', 'resend'], true)) {
      $sent = newsletter_send_confirmation(mb_strtolower($email), $r['token']);
      event_record_server('newsletter_signup', '', $source === '/' ? 'home' : trim(str_replace('/', '_', trim($source, '/')), '_'));
      $status = $sent ? 'ok' : 'unsent';
    } else { $status = 'ok'; }          // already confirmed or throttled: same message, no account enumeration
  } catch (Throwable $e) { $message = 'Subscriptions are temporarily unavailable. Please try again later.'; $code = 503; }
}
http_response_code($code);
$title = 'Subscribe to AIHubpedia Updates';
$main_class = 'fullbleed';
require __DIR__ . '/layout_top.php';
echo '<div class="wrap pgBody">';
if ($status === 'ok') {
  echo state_box(['tone' => 'ok', 'art' => 'mail', 'eyebrow' => 'Almost done', 'title' => 'Check your inbox', 'text' => 'If this address can be subscribed, we have sent a confirmation link. You will not receive updates until you confirm.', 'actions' => '<a class="btn btn--accent" href="/">Back to AIHubpedia</a>']);
} elseif ($status === 'unsent') {
  echo state_box(['art' => 'lost', 'eyebrow' => 'Not sent', 'title' => 'We saved your request, but the email did not send', 'text' => 'The confirmation email could not be sent right now. Please try again later or email <a href="mailto:contact@aihubpedia.com" style="text-decoration:underline">contact@aihubpedia.com</a>.', 'actions' => '<a class="btn btn--accent" href="/">Back to AIHubpedia</a>']);
} else {
  echo state_box(['art' => 'lost', 'eyebrow' => 'Could not subscribe', 'title' => 'We could not subscribe you', 'text' => h($message), 'actions' => '<a class="btn btn--accent" href="/">Back to AIHubpedia</a>']);
}
echo '</div>';
require __DIR__ . '/layout_bottom.php';
