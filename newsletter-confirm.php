<?php
// Double opt-in confirmation. GET only shows a button (mail scanners and link prefetchers follow links and must not subscribe anyone);
// POST performs the confirmation. The token is a private 128-bit value, so no CSRF token is needed.
require_once __DIR__ . '/helpers.php';
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
$token = (string)($_REQUEST['token'] ?? '');
$valid = newsletter_valid_token($token);
$ok = false; $posted = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
if ($posted && $valid) {
  try { if (db_table_exists('subscribers')) $ok = newsletter_confirm(db(), $token); } catch (Throwable $e) { sec_log_exception($e, 'newsletter-confirm'); $ok = false; }
  if ($ok) event_record_server('newsletter_confirmed');
}
$title = 'Confirm Subscription';
http_response_code(($posted && !$ok) || !$valid ? 400 : 200);
require __DIR__ . '/layout_top.php';
echo '<div style="max-width:640px">';
if ($ok) echo '<div class="successBox" role="status"><h1 style="margin-top:0">You are subscribed</h1><p>Thanks for confirming. Every email includes an unsubscribe link.</p></div>';
elseif (!$valid || $posted) echo '<div class="noticeBox" role="alert"><h1 style="margin-top:0">This confirmation link is not valid</h1><p>It may be incomplete or already used. You can subscribe again from the footer of any page.</p></div>';
else echo '<h1>Confirm your subscription</h1><p>Press the button to confirm that you want to receive AIHubpedia emails.</p><form method="post" action="/newsletter-confirm.php"><input type="hidden" name="token" value="' . h($token) . '"><button class="btn" type="submit">Yes, confirm my subscription</button></form>';
echo '<p style="margin-top:18px"><a class="btn secondary" href="/">Back to AIHubpedia</a></p></div>';
require __DIR__ . '/layout_bottom.php';
