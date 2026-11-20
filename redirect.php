<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/outbound_click.php';
// Outbound tool links. The destination always comes from the catalogue record (never from the request) and is only followed
// when it is a plain public http(s) URL, so a bad or poisoned record can never produce javascript:, data:, file: or credentialed URLs.
$id = trim((string)($_GET['id'] ?? ''));
$t = $id !== '' ? find_tool($id) : null;
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

if(!$t){
  header('Location: /index.php');
  exit;
}

// Counting happens exactly once, here (outbound_click.php). A failure to count never blocks the visitor's link.
try { outbound_click_record($id, $t, $_SERVER); } catch(Throwable $e){ sec_log_exception($e, 'redirect.php:click'); }
$u = tool_url($t);
$safe = ($u === '#') ? null : sec_safe_external_url($u);
if($safe === null){
  if($u !== '#') sec_log('unsafe_outbound_url_blocked', ['tool' => substr($id, 0, 80)]);
  header('Location: /tool.php?id=' . urlencode($id));
  exit;
}
header('Location: ' . $safe);
