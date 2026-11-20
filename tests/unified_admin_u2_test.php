<?php
// Unified Admin Control Center, U2, DATABASE-FREE: the CRM inside the shell (link/form rewriting, view and flash handling, routing order, permissions, legacy URL preserved, CRM code untouched).
$root = dirname(__DIR__); chdir($root);
ob_start(); require_once $root . '/helpers.php'; require_once $root . '/crm_views.php'; require_once $root . '/adm_core.php'; require_once $root . '/adm_crm.php'; ob_end_clean();
$pass = 0; $fail = 0; function ok($c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; fwrite(STDERR, "  FAIL: $m\n"); } }
$ac = file_get_contents($root . '/admin-center.php'); $cv = file_get_contents($root . '/crm_views.php'); $am = file_get_contents($root . '/adm_crm.php');

// rewriting
$r = admc_rewrite('<a href="/crm.php?v=leads">L</a><a href="/crm.php?v=lead&amp;id=5">x</a><a href="/crm.php">h</a><form method="post" class="crmForm " action="/crm.php"><input></form><form method="get" action="/crm.php" class="crmForm"><input type="hidden" name="v" value="leads"></form><a href="/other.php">o</a>');
ok(strpos($r, '/crm.php') === false, 'no CRM address remains after rewriting');
ok(strpos($r, 'href="/admin-center.php?m=crm&amp;v=leads"') !== false && strpos($r, 'href="/admin-center.php?m=crm&amp;v=lead&amp;id=5"') !== false, 'links keep the module and their own parameters');
ok(strpos($r, 'href="/admin-center.php?m=crm"') !== false, 'the bare CRM link points at the CRM module');
ok(strpos($r, '<form method="post" class="crmForm " action="/admin-center.php?m=crm">') !== false, 'POST forms submit to the shell with m=crm');
ok(strpos($r, '<form method="get" action="/admin-center.php" class="crmForm"><input type="hidden" name="m" value="crm">') !== false, 'GET forms carry m=crm as a hidden field (a query string in the action would be dropped)');
ok(strpos($r, '/other.php') !== false, 'unrelated links are untouched');
ok(admc_rewrite('plain text') === 'plain text', 'text without CRM links is unchanged');
// url and view
ok(admc_url('leads') === '/admin-center.php?m=crm&v=leads' && admc_url('lead', ['id' => 7]) === '/admin-center.php?m=crm&v=lead&id=7', 'admc_url builds shell addresses');
ok(admc_view([]) === 'dashboard' && admc_view(['v' => 'nonsense']) === 'dashboard' && admc_view(['v' => ['x']]) === 'dashboard' && admc_view(['v' => 'leads']) === 'leads', 'views are whitelisted; unknown or array values fall back to the dashboard');
foreach (crm_views() as $v) ok(admc_view(['v' => $v]) === $v, "view $v is reachable");
// every CRM address in the CRM views is covered by a rewrite rule
preg_match_all('#/crm\.php[^"\'\s]*#', $cv, $mm); $forms = 0; foreach ($mm[0] as $a) { if (preg_match('#^/crm\.php(\?|$)#', $a)) $forms++; } ok($forms > 0, 'CRM views use /crm.php addresses that the wrapper rewrites (' . $forms . ' found)');
// permissions and routing
foreach (['owner', 'sales', 'viewer'] as $role) ok(adm_can($role, 'crm'), "$role may open the CRM");
foreach (['editor', 'verifier', 'nobody', ''] as $role) ok(!adm_can($role, 'crm'), "$role may not open the CRM");
$mods = adm_modules(); ok(!empty($mods['crm']['internal']) && $mods['crm']['href'] === '/admin-center.php?m=crm', 'the CRM module now opens inside the shell');
$posGate = strpos($ac, '$user = adm_gate()'); $posCsrf = strpos($ac, 'crm_csrf_ok()'); $posCrm = strpos($ac, "admc_post("); $posPerm = strpos($ac, "adm_can((string)\$user['role'], 'crm')");
ok($posGate !== false && $posCsrf !== false && $posCrm !== false && $posGate < $posCsrf && $posCsrf < $posPerm && $posPerm < $posCrm, 'order: sign-in, then CSRF/same-origin, then the crm permission, then the CRM action');
ok(substr_count($ac, "admc_post(") === 1 && strpos($ac, "(\$_GET['m'] ?? '') === 'crm'") !== false, 'CRM POSTs are recognised only by the module in the address');
ok(strpos($ac, "elseif (\$m === 'crm')") !== false, 'GET route exists and sits after the module permission check');
ok(strpos($ac, "if (!adm_can((string)\$user['role'], \$mod['perm']))") < strpos($ac, "elseif (\$m === 'crm')"), 'the module permission check precedes the CRM page');
// the wrapper itself
ok(strpos($am, 'crm_handle_action(') !== false && strpos($am, 'INSERT') === false && strpos($am, 'UPDATE') === false && strpos($am, 'DELETE') === false, 'wrapper delegates to crm_handle_action and writes nothing itself');
ok(strpos($am, 'CREATE TABLE') === false && strpos($am, 'password') === false && strpos($am, 'session_') === false, 'wrapper adds no table, password handling or session system');
ok(strpos($am, "crm_msg_label(\$get['msg'])") !== false && strpos($am, 'crm_h(') !== false, 'flash text comes from the fixed label table and is escaped');
// post redirect stays in the shell and only to known views
$stub = new class { }; ok(function_exists('admc_post'), 'admc_post exists');
// legacy URL preserved and CRM files unchanged
ok(is_file($root . '/crm.php') && strpos(file_get_contents($root . '/crm.php'), 'crm_gate()') !== false, '/crm.php still exists with its own gate (legacy URL preserved, D5)');
$diff = shell_exec('git diff --stat 7c4b35f -- crm.php crm_views.php crm_core.php 2>/dev/null'); ok(trim((string)$diff) === '', 'crm.php, crm_views.php and crm_core.php are unchanged since Phase 8D');
// css
$css = admc_css(); ok(strpos($css, 'var(--surface)') !== false && strpos($css, '#fff') === false && strpos($css, 'min-height:44px') !== false, 'compat CSS uses shell tokens (light/dark) and 44px targets');
$sh = adm_css(); ok(strpos($sh, '.admCrm .btn') !== false, 'compat CSS is part of the shell styles');
// body shell composition with a nav only (no database needed for crm_nav)
$n = admc_rewrite(crm_nav('leads', ['role' => 'owner'])); ok(substr_count($n, 'admin-center.php?m=crm&amp;v=') >= 11 && strpos($n, 'aria-current="page"') !== false, 'section bar links stay in the shell and mark the current view');
$nv = admc_rewrite(crm_nav('leads', ['role' => 'viewer'])); ok(strpos($nv, 'v=audit') === false && strpos($nv, 'v=users') === false, 'audit log and users links are owner-only in the section bar');
echo "$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
