<?php
// Lead capture handler (advertiser, affiliate partner, consultation, custom AI agent, software development).
require_once __DIR__ . '/helpers.php';
$allTypes = array_keys(lead_types());

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
  $sent = (string)($_GET['sent'] ?? '');
  if (!in_array($sent, $allTypes, true)) { header('Location: /business.php', true, 302); exit; }
  $title = 'Request Received';
  $group = lead_types()[$sent]['group'];
  $main_class = 'fullbleed';
require __DIR__ . '/layout_top.php';
  $back = ['advertise' => '/advertise.php', 'project' => '/services.php', 'partner' => '/partners.php'][$group] ?? '/business.php';
  echo '<div class="wrap pgBody">' . state_box(['tone' => 'ok', 'art' => 'mail', 'eyebrow' => 'Request received', 'title' => 'Thank you, your request was received', 'text' => 'We read every request and will reply by email if it is a fit. We do not promise a response time.', 'actions' => '<a class="btn btn--accent" href="/">Back to AIHubpedia</a><a class="btn secondary" href="' . h($back) . '">Back to the form page</a>']) . '</div>';
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
  require_once __DIR__ . '/crm_intake.php';
  $res = crm_public_submit($clean, 'inquiry');
  if ($res['status'] === 'rate_limited') { http_response_code(429); $errors['_bot'] = 'Too many requests from your network. Please try again later or email contact@aihubpedia.com.'; $ok = false; }
  elseif ($res['status'] === 'unavailable') { $unavailable = true; $ok = false; http_response_code(503); }
  else { header('Location: /inquiry.php?sent=' . rawurlencode($clean['lead_type']), true, 303); exit; }
}
if (!$ok && !$unavailable && $errors) http_response_code(isset($errors['_bot']) && http_response_code() === 429 ? 429 : 422);
$title = 'Check Your Request';
$page = $group === 'advertise' ? '/advertise.php' : '/business.php';
require __DIR__ . '/layout_top.php';
echo '<div class="wrap pgBody">';
if ($unavailable) {
  echo '<div class="noticeBox" role="alert"><strong>We could not save your request right now.</strong> Please try again later or email <a href="mailto:contact@aihubpedia.com">contact@aihubpedia.com</a>.</div>';
} else {
  echo '<h1>Please check your request</h1><p class="small">Some details need attention. Nothing was sent yet.</p><div class="panel" style="max-width:760px">'
     . render_lead_form(['types' => $allowed, 'values' => $_POST, 'errors' => $errors, 'id' => 'lead', 'source' => $clean['source_page'] ?: $page, 'budget' => $group === 'business', 'packages' => $group === 'advertise', 'message_label' => 'Message']) . '</div>';
}
echo '</div>';
require __DIR__ . '/layout_bottom.php';
