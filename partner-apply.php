<?php
// Partnership application (POST). Stored as a public lead, mirrored into the CRM as a lead plus a partner application (status "applied", never public).
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/crm_intake.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Location: /partners.php', true, 302); exit; }
$v = partner_validate($_POST); $errors = $v['errors']; $clean = $v['clean']; $ok = !$errors; $unavailable = false;
if ($ok) {
  $res = crm_public_submit($clean, 'partner');
  if ($res['status'] === 'rate_limited') { http_response_code(429); $errors['_bot'] = 'Too many requests from your network. Please try again later or email contact@aihubpedia.com.'; $ok = false; }
  elseif ($res['status'] === 'unavailable') { $unavailable = true; $ok = false; http_response_code(503); }
  else { header('Location: /inquiry.php?sent=partnership_application' . (!empty($res['ref']) ? '&ref=' . rawurlencode($res['ref']) : ''), true, 303); exit; }
}
if (!$ok && !$unavailable && $errors && http_response_code() === 200) http_response_code(422);
$title = 'Check Your Partnership Application'; $main_class = 'fullbleed';
require __DIR__ . '/layout_top.php';
echo '<div class="wrap pgBody">';
if ($unavailable) echo '<div class="noticeBox" role="alert"><strong>We could not save your application right now.</strong> Please try again later or email <a href="mailto:contact@aihubpedia.com">contact@aihubpedia.com</a>.</div>';
else echo '<h1>Please check your application</h1><p class="small">Some details need attention. Nothing was sent yet.</p><div class="panel" style="max-width:760px">' . render_partner_form(['values' => $_POST, 'errors' => $errors]) . '</div>';
echo '</div>';
require __DIR__ . '/layout_bottom.php';
