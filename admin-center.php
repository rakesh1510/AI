<?php
// AIHubpedia Control Center (Phase 8A). Sign-in = the existing CRM accounts (no default accounts, CLI-created). Role-based modules, honest KPI tiles, audit in crm_audit_log.
// Writes: POST sign-out only (session CSRF token + same-origin). Nothing here changes data, tables or settings.
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/crm_views.php';
require_once __DIR__ . '/adm_core.php';
require_once __DIR__ . '/adm_analytics.php';
require_once __DIR__ . '/adm_tools_views.php';
require_once __DIR__ . '/adm_finance_views.php';
require_once __DIR__ . '/adm_crm.php';
require_once __DIR__ . '/adm_universe.php';
require_once __DIR__ . '/adm_editorial.php';
require_once __DIR__ . '/adm_access.php';
require_once __DIR__ . '/adm_advertising.php';
require_once __DIR__ . '/adm_an2_store.php';
require_once __DIR__ . '/adm_an2_views.php';
require_once __DIR__ . '/adm_auth.php';
require_once __DIR__ . '/adm_account.php';
$user = adm_gate();                  // ONE sign-in for every admin area: renders the Control Center sign-in page / setup notice and exits when there is no signed-in user (uses the existing CRM accounts)
$pdo = crm_pdo();
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'POST') {
  if (!crm_csrf_ok()) { sec_log('admin_center_csrf_rejected'); http_response_code(403); exit('Security check failed.'); }
  if (($_POST['act'] ?? '') === 'logout') { adm_audit($pdo, $user, 'admin_center_logout', 'signed out'); crm_logout(); header('Location: /admin-center.php', true, 303); exit; }
  if (($_GET['m'] ?? '') === 'crm') {   // U2: CRM actions inside the shell. Permission checked here; role rules, validation, stale-edit checks and audit stay in crm_handle_action()
    if (!adm_can((string)$user['role'], 'crm')) { adm_audit($pdo, $user, 'admin_center_denied', 'crm action'); http_response_code(403); exit('Not allowed.'); }
    header('Location: ' . sec_safe_local_path(admc_post($pdo, $user, $_POST), '/admin-center.php?m=crm'), true, 303); exit;
  }
  if (($_GET['m'] ?? '') === 'editorial') {   // U4: tool profile verification; reviewer = signed-in account, role rules and independence in admed_run()
    if (!adm_can((string)$user['role'], 'universe')) { adm_audit($pdo, $user, 'admin_center_denied', 'editorial action'); http_response_code(403); exit('Not allowed.'); }
    $r = admed_run($user, 'POST', [], $_POST, null, fn(string $a, string $sum) => adm_audit($pdo, $user, $a, $sum));
    header('Location: ' . sec_safe_local_path($r['redirect'] ?? admed_url(), '/admin-center.php?m=editorial'), true, 303); exit;
  }
  if (($_GET['m'] ?? '') === 'universe') {   // U3: editorial workflow inside the shell. Maker-checker, independent verification and owner read-only are enforced in admu_run() / uv_handle_post() exactly as on /universe-editor.php
    if (!adm_can((string)$user['role'], 'universe')) { adm_audit($pdo, $user, 'admin_center_denied', 'universe action'); http_response_code(403); exit('Not allowed.'); }
    $r = admu_run($pdo, $user, 'POST', [], $_POST);
    if (isset($r['redirect'])) { header('Location: ' . sec_safe_local_path($r['redirect'], '/admin-center.php?m=universe'), true, 303); exit; }
    if ((int)$r['status'] === 429) header('Retry-After: 600');
    adm_page($user, 'universe', 'AI Universe review', admu_compose($r['body']), (int)$r['status']); exit;
  }
  if (strpos((string)($_POST['act'] ?? ''), 'account_') === 0) {   // the signed-in person's OWN password only: account comes from the session, never from the request
    $res = adm_account_change_password(new AdmAccountPdoStore($pdo), $user, $_POST, fn(string $a, string $sum) => adm_audit($pdo, $user, $a, $sum), fn(string $b) => sec_rate_limited($b, 5, 900));
    if ($res['ok']) { crm_session_start(); session_regenerate_id(true); $_SESSION['crm_csrf'] = bin2hex(random_bytes(16)); header('Location: /admin-center.php?m=account&msg=pw_changed', true, 303); exit; }
    adm_page($user, 'account', 'Account and password', adm_account_body($user, [], $res['errors'], (string)($res['errors']['_'] ?? '')), $res['code']); exit;
  }
  if (strpos((string)($_POST['act'] ?? ''), 'tool_') === 0) {   // Phase 8C tool management: role check, validation, stale-edit check, revision and audit all happen in admt_apply()
    if (!AdmToolPdoStore::ready($pdo)) { adm_page($user, 'tools', 'AI tool management', admt_not_installed(), 503); exit; }
    $store = new AdmToolPdoStore($pdo);
    $res = admt_handle_post($store, $user, $_POST, fn(string $a, string $slug, string $sum) => adm_audit($pdo, $user, $a, $slug . ' ' . $sum));
    if (isset($res['redirect'])) { header('Location: ' . sec_safe_local_path((string)$res['redirect'], '/admin-center.php?m=tools'), true, 303); exit; }
    adm_page($user, 'tools', 'AI tool management', admt_render_result($store, $user, $res), $res['code']); exit;
  }
  if (strpos((string)($_POST['act'] ?? ''), 'fin_') === 0) {   // Phase 8D Finance: owner only, validated and audited in admf_apply()
    $fs = new AdmFinancePdoStore($pdo); $res = admf_handle_post($fs, $user, $_POST, fn(string $a, string $sum) => adm_audit($pdo, $user, $a, $sum));
    if (isset($res['redirect'])) { header('Location: ' . sec_safe_local_path((string)$res['redirect'], '/admin-center.php?m=finance'), true, 303); exit; }
    adm_page($user, 'finance', 'Finance', admf_body($fs, $user, $res['get'], $res['form'], $res['errs'], $res['top']), $res['code']); exit;
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
  $which = is_string($_GET['export']) ? $_GET['export'] : ''; $x = null;
  if (isset(anz_export_names()[$which]) && adma_storage_ready($pdo)) { $as = new AdmAnPdoStore($pdo); $ast = $as->status(); $x = anz_export($as, $which, anz_range($_GET, gmdate('Y-m-d'), $ast['first_day'] ?? null), $_GET, anz_catalogue()); }   // A5 exports
  elseif (in_array($which, ['daily', 'pages', 'referrers', 'countries', 'tools', 'conversions'], true) && adma_storage_ready($pdo)) $x = adma_export($pdo, $which, adma_range($_GET));
  if ($x === null) { adm_page($user, 'analytics', 'Export not available', '<h1>Export not available</h1><p>Unknown export, or the analytics storage is not installed.</p><p><a href="/admin-center.php?m=analytics">Back to Analytics</a></p>', 404); exit; }
  adm_audit($pdo, $user, 'admin_center_export', 'analytics ' . $which . ' ' . $x[0]);
  header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $x[0]) . '"'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); header('X-Robots-Tag: noindex, nofollow'); echo $x[1]; exit;
}
if ($m === 'finance' && isset($_GET['export'])) {   // owner only (checked above); every export is audited; CSV is formula-safe and never cached
  $fs = new AdmFinancePdoStore($pdo); $d = admf_load($fs); $x = is_string($_GET['export']) ? admf_export($_GET['export'], admf_report($d), $d) : null;
  if ($x === null) { adm_page($user, 'finance', 'Export not available', '<h1>Export not available</h1><p>Unknown export.</p><p><a href="/admin-center.php?m=finance&amp;view=exports">Back to Exports</a></p>', 404); exit; }
  adm_audit($pdo, $user, 'admin_center_export', 'finance ' . $_GET['export'] . ' ' . $x[0]);
  header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $x[0]) . '"'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); header('X-Robots-Tag: noindex, nofollow'); echo $x[1]; exit;
}
crm_session_start();
if (empty($_SESSION['adm_view_at']) || time() - (int)$_SESSION['adm_view_at'] > 1800) { $_SESSION['adm_view_at'] = time(); adm_audit($pdo, $user, 'admin_center_view', 'overview opened'); }   // one audit row per 30 minutes, not per page load
if ($m === 'dashboard') adm_page($user, 'dashboard', 'Overview', adm_dashboard_body($pdo, $user));
elseif ($m === 'analytics') adm_page($user, 'analytics', 'Analytics', anz_body(new AdmAnPdoStore($pdo), $_GET, anz_catalogue(), null, fn() => adma_body($pdo, $_GET)));
elseif ($m === 'advertising') {   // U6: read-only hub over existing records (CRM campaigns, 8C placements, paid submissions); the tool-management views are owner only
  $camps = (array)adm_safe(fn() => crm_campaigns_list($pdo, '', 200), []); $toolsOk = adm_can((string)$user['role'], 'tools_publish') && AdmToolPdoStore::ready($pdo); $ts = $toolsOk ? new AdmToolPdoStore($pdo) : null;
  adm_page($user, 'advertising', 'Advertising', admv_body($user, $_GET, $camps, $ts ? $ts->placements() : null, $ts ? array_values(array_filter($ts->submissions(), fn($x) => (int)$x['price'] > 0)) : null));
}
elseif ($m === 'access') adm_page($user, 'access', 'Users, roles and audit', admx_body(new AdmAccessPdoStore($pdo), adm_health($pdo), $_GET));
elseif ($m === 'editorial') { $r = admed_run($user, 'GET', $_GET, []); adm_page($user, 'editorial', 'Tool profile verification', $r['body'], (int)$r['status']); }
elseif ($m === 'universe') { $r = admu_run($pdo, $user, 'GET', $_GET, []); adm_page($user, 'universe', 'AI Universe review', admu_compose($r['body']), (int)$r['status']); }
elseif ($m === 'crm') adm_page($user, 'crm', 'CRM and leads', admc_body($pdo, $user, $_GET));
elseif ($m === 'account') adm_page($user, 'account', 'Account and password', adm_account_body($user, $_GET));
elseif ($m === 'finance') adm_page($user, 'finance', 'Finance', admf_body(new AdmFinancePdoStore($pdo), $user, $_GET));
elseif ($m === 'tools') adm_page($user, 'tools', 'AI tool management', AdmToolPdoStore::ready($pdo) ? admt_body(new AdmToolPdoStore($pdo), $user, $_GET) : admt_not_installed());
else adm_page($user, $m, $mod['label'], adm_planned_body($m, $mod));
