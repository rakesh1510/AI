<?php
// Guided TrustNova project inquiry (POST). Validation, honeypot and time trap come from lead_validate(); the inquiry is stored in the public
// leads table (existing notification and reports) and mirrored into the CRM. No data is sent anywhere except the owner notification.
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/crm_intake.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Location: /services.php', true, 302); exit; }
$v = project_validate($_POST); $errors = $v['errors']; $clean = $v['clean']; $ok = !$errors; $unavailable = false;
if ($ok) {
  try {
    if (!db_table_exists('leads')) throw new RuntimeException('leads table missing');
    $pdo = db(); $ipHash = conversion_ip_hash();
    if (lead_rate_limited($pdo, 'leads', $ipHash, 5)) { http_response_code(429); $errors['_bot'] = 'Too many requests from your network. Please try again later or email contact@aihubpedia.com.'; $ok = false; }
    else {
      $leadId = lead_store($pdo, $clean, $ipHash);
      try { if (crm_tables_ready()) crm_intake_record($pdo, $clean, $leadId); } catch (Throwable $e) { sec_log_exception($e, 'project:crm'); }
      $sent = false; try { $sent = lead_notify_owner($clean); } catch (Throwable $e) { mail_last_error('smtp_other'); sec_log_exception($e, 'project:notify'); }
      try { lead_record_notification($pdo, $leadId, $sent, mail_last_error()); } catch (Throwable $e) { sec_log_exception($e, 'project:notify-record'); }
      if (!$sent) sec_log('lead_notification_failed', ['lead' => $leadId, 'reason' => mail_last_error()]);
      event_record_server('lead_submit', 'dev_project');
      header('Location: /inquiry.php?sent=dev_project', true, 303); exit;
    }
  } catch (Throwable $e) { $unavailable = true; $ok = false; http_response_code(503); }
}
if (!$ok && !$unavailable && $errors && http_response_code() === 200) http_response_code(422);
$title = 'Check Your Project Inquiry'; require __DIR__ . '/layout_top.php';
if ($unavailable) echo '<div class="noticeBox" role="alert"><strong>We could not save your inquiry right now.</strong> Please try again later or email <a href="mailto:contact@aihubpedia.com">contact@aihubpedia.com</a>.</div>';
else echo '<h1>Please check your inquiry</h1><p class="small">Some details need attention. Nothing was sent yet.</p><div class="panel" style="max-width:760px">' . render_project_form(['values' => $_POST, 'errors' => $errors]) . '</div>';
require __DIR__ . '/layout_bottom.php';
