<?php
// GET shows a confirmation button (so mail scanners that prefetch links cannot unsubscribe people); POST performs it.
require_once __DIR__ . '/helpers.php';
$token = (string)($_REQUEST['token'] ?? '');
$done = false; $valid = newsletter_valid_token($token);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $valid) {
  try { if (db_table_exists('subscribers')) $done = newsletter_unsubscribe(db(), $token) || true; } catch (Throwable $e) { $done = false; }
}
$title = 'Unsubscribe';
require __DIR__ . '/layout_top.php';
echo '<div style="max-width:640px">';
if ($done) echo '<div class="successBox" role="status"><h1 style="margin-top:0">You are unsubscribed</h1><p>You will not receive further emails from AIHubpedia.</p></div>';
elseif (!$valid) echo '<div class="noticeBox" role="alert"><h1 style="margin-top:0">This unsubscribe link is not valid</h1><p>Email <a href="mailto:contact@aihubpedia.com">contact@aihubpedia.com</a> and we will remove you.</p></div>';
else echo '<h1>Unsubscribe from AIHubpedia emails?</h1><form method="post" action="/unsubscribe.php"><input type="hidden" name="token" value="' . h($token) . '"><button class="btn" type="submit">Yes, unsubscribe me</button> <a class="btn secondary" href="/">Keep me subscribed</a></form>';
echo '</div>';
require __DIR__ . '/layout_bottom.php';
