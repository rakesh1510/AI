<?php
// Internal CRM (Phase 6). Not public: sign-in required, no default accounts. All changes are POST with a session CSRF token and a same-origin check.
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/crm_views.php';
$crmUser = crm_gate();          // renders the sign-in form / disabled notice and exits when there is no signed-in user
$pdo = crm_pdo();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  if (!crm_csrf_ok()) { sec_log('crm_csrf_rejected'); http_response_code(403); exit('Security check failed.'); }
  if (($_POST['act'] ?? '') === 'logout') { crm_audit($pdo, $crmUser, 'logout', 'user', (int)$crmUser['id'], 'signed out'); crm_logout(); header('Location: /crm.php', true, 303); exit; }
  [$go, $code] = crm_handle_action($pdo, $crmUser, $_POST);
  parse_str($go, $gq); if (!in_array($gq['v'] ?? '', crm_views(), true)) $gq = ['v' => 'dashboard'];
  header('Location: /crm.php?' . http_build_query($gq + ['m' => $code]), true, 303); exit;
}
$crmView = (string)($_GET["v"] ?? "dashboard"); if (!in_array($crmView, crm_views(), true)) $crmView = "dashboard";   // not $v: layout_top.php overwrites $v
$title = 'CRM — AIHubpedia';
require __DIR__ . '/layout_top.php';
?>
<h1>CRM <span class="small">(internal)</span></h1>
<form method="post" action="/crm.php" style="float:right"><?= crm_csrf_field() ?><input type="hidden" name="act" value="logout"><button class="btn secondary sm">Sign out <?= crm_h($crmUser['display_name']) ?> (<?= crm_h($crmUser['role']) ?>)</button></form>
<p class="small">Internal records only. Nothing here sends email, invoices or payments. Personal data is limited to business contact details; opening a lead is logged.</p>
<?= crm_nav($crmView, $crmUser) ?>
<?php if (isset($_GET['m'])): $m = (string)$_GET['m']; ?><div class="noteBox" role="status"><?= crm_h(crm_msg_label($m)) ?></div><?php endif; ?>
<?php crm_render_view($pdo, $crmUser, $crmView, $_GET); ?>
<?php require __DIR__ . '/layout_bottom.php'; ?>
