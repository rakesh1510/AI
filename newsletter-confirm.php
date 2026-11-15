<?php
require_once __DIR__ . '/helpers.php';
$token = (string)($_GET['token'] ?? '');
$ok = false;
try { if (db_table_exists('subscribers')) $ok = newsletter_confirm(db(), $token); } catch (Throwable $e) { $ok = false; }
if ($ok) event_record_server('newsletter_confirmed');
$title = 'Confirm Subscription';
http_response_code($ok ? 200 : 400);
require __DIR__ . '/layout_top.php';
echo '<div style="max-width:640px">' . ($ok
  ? '<div class="successBox" role="status"><h1 style="margin-top:0">You are subscribed</h1><p>Thanks for confirming. Every email includes an unsubscribe link.</p></div>'
  : '<div class="noticeBox" role="alert"><h1 style="margin-top:0">This confirmation link is not valid</h1><p>It may be incomplete or already used. You can subscribe again from the footer of any page.</p></div>')
  . '<p style="margin-top:18px"><a class="btn secondary" href="/">Back to AIHubpedia</a></p></div>';
require __DIR__ . '/layout_bottom.php';
