<?php
require_once __DIR__ . '/helpers.php';
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

// Counting is rate limited per network and tool: a script or a third-party page must not be able to inflate popularity by looping this URL.
if(!sec_rate_limited('click:' . $id, 20, 600)){
  // Click counting is best effort: a missing, unreachable or outdated database must never break the visitor's outbound link.
  try { if(interactions_writable()) record_click($id); if(!analytics_classify_agent((string)($_SERVER['HTTP_USER_AGENT'] ?? ''))['is_bot']) event_record_server('outbound_click', $id, is_affiliate_link($t) ? 'yes' : 'no', is_featured_tool($t) ? 'sponsored' : 'organic'); }
  catch(Throwable $e){ sec_log_exception($e, 'redirect.php:click'); }
}
$u = tool_url($t);
$safe = ($u === '#') ? null : sec_safe_external_url($u);
if($safe === null){
  if($u !== '#') sec_log('unsafe_outbound_url_blocked', ['tool' => substr($id, 0, 80)]);
  header('Location: /tool.php?id=' . urlencode($id));
  exit;
}
header('Location: ' . $safe);
