<?php
// Partnership application (POST). Stored as a public lead, mirrored into the CRM as a lead plus a partner application (status "applied", never public).
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/crm_intake.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Location: /partners.php', true, 302); exit; }
$v = partner_validate($_POST); $errors = $v['errors']; $clean = $v['clean']; $ok = !$errors; $unavailable = false;
if ($ok) {
  try {
    if (!db_table_exists('leads')) throw new RuntimeException('leads table missing');
    $pdo = db(); $ipHash = conversion_ip_hash();
    if (lead_rate_limited($pdo, 'leads', $ipHash, 5)) { http_response_code(429); $errors['_bot'] = 'Too many requests from your network. Please try again later or email contact@aihubpedia.com.'; $ok = false; }
    else {
      $leadId = lead_store($pdo, $clean, $ipHash);
      try { if (crm_tables_ready()) crm_intake_record($pdo, $clean, $leadId); } catch (Throwable $e) { sec_log_exception($e, 'partner:crm'); }
      $sent = false; try { $sent = lead_notify_owner($clean); } catch (Throwable $e) { mail_last_error('smtp_other'); sec_log_exception($e, 'partner:notify'); }
      try { lead_record_notification($pdo, $leadId, $sent, mail_last_error()); } catch (Throwable $e) { sec_log_exception($e, 'partner:notify-record'); }
      if (!$sent) sec_log('lead_notification_failed', ['lead' => $leadId, 'reason' => mail_last_error()]);
      event_record_server('lead_submit', 'partnership_application');
      header('Location: /inquiry.php?sent=partnership_application', true, 303); exit;
    }
  } catch (Throwable $e) { $unavailable = true; $ok = false; http_response_code(503); }
}
if (!$ok && !$unavailable && $errors && http_response_code() === 200) http_response_code(422);
$title = 'Check Your Partnership Application'; require __DIR__ . '/layout_top.php';
if ($unavailable) echo '<div class="noticeBox" role="alert"><strong>We could not save your application right now.</strong> Please try again later or email <a href="mailto:contact@aihubpedia.com">contact@aihubpedia.com</a>.</div>';
else echo '<h1>Please check your application</h1><p class="small">Some details need attention. Nothing was sent yet.</p><div class="panel" style="max-width:760px">' . render_partner_form(['values' => $_POST, 'errors' => $errors]) . '</div>';
require __DIR__ . '/layout_bottom.php';
