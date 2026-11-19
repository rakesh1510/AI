<?php
// AIHubpedia Control Center (Phase 8A). Sign-in = the existing CRM accounts (no default accounts, CLI-created). Role-based modules, honest KPI tiles, audit in crm_audit_log.
// Writes: POST sign-out only (session CSRF token + same-origin). Nothing here changes data, tables or settings.
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/crm_views.php';
require_once __DIR__ . '/adm_core.php';
require_once __DIR__ . '/adm_analytics.php';
require_once __DIR__ . '/adm_tools_views.php';
$user = crm_gate();                  // renders the sign-in form / disabled notice and exits when there is no signed-in user
$pdo = crm_pdo();
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'POST') {
  if (!crm_csrf_ok()) { sec_log('admin_center_csrf_rejected'); http_response_code(403); exit('Security check failed.'); }
  if (($_POST['act'] ?? '') === 'logout') { adm_audit($pdo, $user, 'admin_center_logout', 'signed out'); crm_logout(); header('Location: /admin-center.php', true, 303); exit; }
  if (strpos((string)($_POST['act'] ?? ''), 'tool_') === 0) {   // Phase 8C tool management: role check, validation, stale-edit check, revision and audit all happen in admt_apply()
    if (!AdmToolPdoStore::ready($pdo)) { adm_page($user, 'tools', 'AI tool management', admt_not_installed(), 503); exit; }
    $store = new AdmToolPdoStore($pdo);
    $res = admt_handle_post($store, $user, $_POST, fn(string $a, string $slug, string $sum) => adm_audit($pdo, $user, $a, $slug . ' ' . $sum));
    if (isset($res['redirect'])) { header('Location: ' . sec_safe_local_path((string)$res['redirect'], '/admin-center.php?m=tools'), true, 303); exit; }
    adm_page($user, 'tools', 'AI tool management', admt_body($store, $user, $res['get'], $res['form'], $res['errs'], $res['top']), $res['code']); exit;
  }
  http_response_code(400); exit('Unknown action.');
}
if (!in_array($method, ['GET', 'HEAD'], true)) { header('Allow: GET, HEAD, POST'); http_response_code(405); exit('Method not allowed.'); }
$mods = adm_modules(); $m = $_GET['m'] ?? 'dashboard';
if (!is_string($m) || !isset($mods[$m])) { adm_page($user, 'dashboard', 'Not found', '<h1>Page not found</h1><p><a href="/admin-center.php">Back to the overview</a></p>', 404); exit; }
$mod = $mods[$m];
if (!adm_can((string)$user['role'], $mod['perm'])) {
  adm_audit($pdo, $user, 'admin_center_denied', 'module ' . $m);
  adm_page($user, 'dashboard', 'Not allowed', '<h1>Not allowed</h1><p>Your role does not include this section.</p><p><a href="/admin-center.php">Back to the overview</a></p>', 403); exit;
}
if (!empty($mod['live']) && empty($mod['internal']) && $m !== 'dashboard') { header('Location: ' . sec_safe_local_path((string)$mod['href'], '/admin-center.php'), true, 302); exit; }   // existing tools open on their own pages
if ($m === 'analytics' && isset($_GET['export'])) {   // read-only CSV download: owner only (checked above), every export is audited
  $which = is_string($_GET['export']) ? $_GET['export'] : ''; $x = in_array($which, ['daily', 'pages', 'referrers', 'countries', 'tools', 'conversions'], true) && adma_storage_ready($pdo) ? adma_export($pdo, $which, adma_range($_GET)) : null;
  if ($x === null) { adm_page($user, 'analytics', 'Export not available', '<h1>Export not available</h1><p>Unknown export, or the analytics storage is not installed.</p><p><a href="/admin-center.php?m=analytics">Back to Analytics</a></p>', 404); exit; }
  adm_audit($pdo, $user, 'admin_center_export', 'analytics ' . $which . ' ' . $x[0]);
  header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $x[0]) . '"'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); header('X-Robots-Tag: noindex, nofollow'); echo $x[1]; exit;
}
crm_session_start();
if (empty($_SESSION['adm_view_at']) || time() - (int)$_SESSION['adm_view_at'] > 1800) { $_SESSION['adm_view_at'] = time(); adm_audit($pdo, $user, 'admin_center_view', 'overview opened'); }   // one audit row per 30 minutes, not per page load
if ($m === 'dashboard') adm_page($user, 'dashboard', 'Overview', adm_dashboard_body($pdo, $user));
elseif ($m === 'analytics') adm_page($user, 'analytics', 'Analytics', adma_body($pdo, $_GET));
elseif ($m === 'tools') adm_page($user, 'tools', 'AI tool management', AdmToolPdoStore::ready($pdo) ? admt_body(new AdmToolPdoStore($pdo), $user, $_GET) : admt_not_installed());
else adm_page($user, $m, $mod['label'], adm_planned_body($m, $mod));
