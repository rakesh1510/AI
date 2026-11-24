<?php
// Guided TrustNova project inquiry (POST). Validation, honeypot and time trap come from lead_validate(); the inquiry is stored in the public
// leads table (existing notification and reports) and mirrored into the CRM. No data is sent anywhere except the owner notification.
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/crm_intake.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Location: /services.php', true, 302); exit; }
$v = project_validate($_POST); $errors = $v['errors']; $clean = $v['clean']; $ok = !$errors; $unavailable = false;
if ($ok) {
  $res = crm_public_submit($clean, 'project');
  if ($res['status'] === 'rate_limited') { http_response_code(429); $errors['_bot'] = 'Too many requests from your network. Please try again later or email contact@aihubpedia.com.'; $ok = false; }
  elseif ($res['status'] === 'unavailable') { $unavailable = true; $ok = false; http_response_code(503); }
  else { header('Location: /inquiry.php?sent=dev_project', true, 303); exit; }
}
if (!$ok && !$unavailable && $errors && http_response_code() === 200) http_response_code(422);
$title = 'Check Your Project Inquiry'; $main_class = 'fullbleed';
require __DIR__ . '/layout_top.php';
echo '<div class="wrap pgBody">';
if ($unavailable) echo '<div class="noticeBox" role="alert"><strong>We could not save your inquiry right now.</strong> Please try again later or email <a href="mailto:contact@aihubpedia.com">contact@aihubpedia.com</a>.</div>';
else echo '<h1>Please check your inquiry</h1><p class="small">Some details need attention. Nothing was sent yet.</p><div class="panel" style="max-width:760px">' . render_project_form(['values' => $_POST, 'errors' => $errors]) . '</div>';
echo '</div>';
require __DIR__ . '/layout_bottom.php';
