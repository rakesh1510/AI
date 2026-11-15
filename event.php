<?php
// Anonymous event collector (POST, JSON). Accepts events only when AIHUB_ANALYTICS=1, the visitor opted in, and the request is same-origin.
require_once __DIR__ . '/helpers.php';
header('X-Robots-Tag: noindex');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); header('Allow: POST'); exit; }
if (!analytics_enabled()) { http_response_code(204); exit; }          // off by default: nothing is stored
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
// Compare hostnames only: the Host header may carry a port (staging, local), which Origin parsing strips.
$norm = fn($h) => preg_replace('/^www\./', '', strtolower((string)$h));
$hostOnly = fn($h) => (string)parse_url('//' . trim((string)$h), PHP_URL_HOST);
$oh = $norm(parse_url($origin, PHP_URL_HOST));
$allowed = [$norm($hostOnly($_SERVER['HTTP_HOST'] ?? '')), $norm(parse_url(AIHUB_SITE_URL, PHP_URL_HOST))];
if ($oh === '' || !in_array($oh, $allowed, true)) { http_response_code(403); exit; }
if (($_SERVER['HTTP_DNT'] ?? '') === '1' || ($_SERVER['HTTP_SEC_GPC'] ?? '') === '1') { http_response_code(204); exit; }
$raw = (string)file_get_contents('php://input', false, null, 0, 2048);
$in = json_decode($raw, true);
if (!is_array($in) || empty($in['consent'])) { http_response_code(204); exit; }   // no opt-in recorded in the browser
$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
if (analytics_classify_agent($ua)['is_bot']) { http_response_code(204); exit; }
$row = event_normalize($in, true);
if ($row === null) { http_response_code(400); exit; }
try {
  if (!db_table_exists('events')) { http_response_code(204); exit; }
  $ip = (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''));
  event_insert(db(), $row, analytics_visitor_hash($ip, $ua, analytics_hash_period(time())), !empty($in['returning']));
} catch (Throwable $e) { /* never surface errors to visitors */ }
http_response_code(204);
