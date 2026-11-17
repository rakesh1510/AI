<?php
// Lead capture handler (advertiser, affiliate partner, consultation, custom AI agent, software development).
require_once __DIR__ . '/helpers.php';
$allTypes = array_keys(lead_types());

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
  $sent = (string)($_GET['sent'] ?? '');
  if (!in_array($sent, $allTypes, true)) { header('Location: /business.php', true, 302); exit; }
  $title = 'Request Received';
  $group = lead_types()[$sent]['group'];
  require __DIR__ . '/layout_top.php';
  echo '<div class="successBox" role="status"><h1 style="margin-top:0">Thank you, your request was received</h1><p>We read every request and will reply by email if it is a fit. We do not promise a response time.</p><div class="actions"><a class="btn" href="/">Back to AIHubpedia</a><a class="btn secondary" href="' . ($group === 'advertise' ? '/advertise.php' : '/business.php') . '">Back</a></div></div>';
  require __DIR__ . '/layout_bottom.php';
  exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); header('Allow: GET, POST'); exit; }

$type = (string)($_POST['lead_type'] ?? '');
$group = lead_types()[$type]['group'] ?? 'business';
$allowed = array_keys(array_filter(lead_types(), fn($t) => $t['group'] === $group));
$v = lead_validate($_POST, $allowed);
$errors = $v['errors']; $clean = $v['clean'];
$ok = !$errors;
$ipHash = conversion_ip_hash();
$unavailable = false;
if ($ok) {
  try {
    if (!db_table_exists('leads')) throw new RuntimeException('leads table missing');
    $pdo = db();
    if (lead_rate_limited($pdo, 'leads', $ipHash, 5)) { http_response_code(429); $errors['_bot'] = 'Too many requests from your network. Please try again later or email contact@aihubpedia.com.'; $ok = false; }
    else {
      lead_store($pdo, $clean, $ipHash);
      lead_notify_owner($clean);                      // best effort: the lead is stored even if email is unavailable
      event_record_server('lead_submit', $clean['lead_type']);
      header('Location: /inquiry.php?sent=' . rawurlencode($clean['lead_type']), true, 303);
      exit;
    }
  } catch (Throwable $e) { $unavailable = true; $ok = false; http_response_code(503); }
}
if (!$ok && !$unavailable && $errors) http_response_code(isset($errors['_bot']) && http_response_code() === 429 ? 429 : 422);
$title = 'Check Your Request';
$page = $group === 'advertise' ? '/advertise.php' : '/business.php';
require __DIR__ . '/layout_top.php';
if ($unavailable) {
  echo '<div class="noticeBox" role="alert"><strong>We could not save your request right now.</strong> Please try again later or email <a href="mailto:contact@aihubpedia.com">contact@aihubpedia.com</a>.</div>';
} else {
  echo '<h1>Please check your request</h1><p class="small">Some details need attention. Nothing was sent yet.</p><div class="panel" style="max-width:720px">'
     . render_lead_form(['types' => $allowed, 'values' => $_POST, 'errors' => $errors, 'id' => 'lead', 'source' => $clean['source_page'] ?: $page, 'budget' => $group === 'business', 'packages' => $group === 'advertise', 'message_label' => 'Message']) . '</div>';
}
require __DIR__ . '/layout_bottom.php';
