<?php
// Unified Admin Control Center, U5, DATABASE-FREE: Users and roles (read only), Audit log viewer, System health. Owner only. In-memory store, synthetic data.
$root = dirname(__DIR__); chdir($root);
ob_start(); require_once $root . '/helpers.php'; require_once $root . '/crm_views.php'; require_once $root . '/adm_core.php'; require_once $root . '/adm_access.php'; ob_end_clean();
$pass = 0; $fail = 0; function ok($c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; fwrite(STDERR, "  FAIL: $m\n"); } }
$src = file_get_contents($root . '/adm_access.php'); $ac = file_get_contents($root . '/admin-center.php');
$users = [['id' => 1, 'username' => 'owner1', 'display_name' => 'Olivia <b>Owner</b>', 'role' => 'owner', 'active' => 1, 'created_at' => '2026-10-01 10:00:00', 'last_login_at' => '2026-11-19 08:00:00'], ['id' => 2, 'username' => 'verifier1', 'display_name' => 'Verifier One', 'role' => 'verifier', 'active' => 0, 'created_at' => '2026-10-02 10:00:00', 'last_login_at' => '']];
$log = []; for ($i = 1; $i <= 120; $i++) $log[] = ['at' => sprintf('2026-11-%02d 10:%02d:00', 1 + intdiv($i, 10), $i % 60), 'username' => $i % 2 ? 'owner1' : 'verifier1', 'action' => $i % 3 ? 'tool_edited' : 'account_password_changed', 'entity_type' => 'admin_center', 'entity_id' => null, 'summary' => 'entry ' . $i . ' <script>x</script>'];
$s = new AdmAccessMemoryStore($users, $log);
// permission
ok(adm_can('owner', 'access'), 'owner holds access'); foreach (['sales', 'viewer', 'editor', 'verifier', 'nobody', ''] as $r) ok(!adm_can($r, 'access'), "$r does not hold access");
$m = adm_modules()['access']; ok(!empty($m['internal']) && $m['perm'] === 'access', 'module is internal and needs the access permission');
foreach (['sales', 'viewer', 'editor', 'verifier'] as $r) { $l = []; foreach (adm_nav_tree($r) as $g) foreach ($g['items'] as $i) $l[] = $i['id']; ok(!in_array('access', $l, true), "$r never sees the menu item"); }
$l = []; foreach (adm_nav_tree('owner', 'access', ['view' => 'audit']) as $g) foreach ($g['items'] as $i) if ($i['id'] === 'access') foreach ($i['children'] as $c) $l[$c['label']] = $c['active']; ok(array_keys($l) === ['Users and roles', 'Audit log', 'System health'] && $l['Audit log'] === true, 'owner menu has three views and marks the current one');
// users view
$h = admx_users_view($s); ok(strpos($h, 'owner1') !== false && strpos($h, 'Deactivated') !== false && strpos($h, 'Never') !== false, 'users listed with status and last sign-in');
ok(strpos($h, '<b>') === false && strpos($h, '&lt;b&gt;Owner') !== false, 'display names are escaped');
ok(strpos($h, 'password') === false || strpos($h, 'Passwords are never shown') !== false, 'no password data shown');
ok(strpos($src, 'password_hash') === false, 'the store never selects password hashes');
ok(strpos($h, 'sales') !== false && strpos($h, 'Finance and revenue') !== false && strpos($h, 'What each role can open') !== false, 'role matrix is present');
$rowOf = function (string $role) use ($h) { preg_match('#<strong>' . $role . '</strong></td><td>(.*?)</td>#', $h, $mm); return $mm[1] ?? ''; };
ok(strpos($rowOf('verifier'), 'Finance') === false && strpos($rowOf('sales'), 'Finance') === false && strpos($rowOf('owner'), 'Finance') !== false, 'the matrix matches the real permissions (Finance is owner only)');
ok(strpos($h, '<form') === false, 'the users view has no form');
// audit view
$f = admx_filters(['user' => 'own<er>1', 'action' => 'tool_%', 'from' => '2026-02-30', 'to' => '2026-11-05', 'page' => '0']); ok($f['user'] === 'owner1' && $f['action'] === 'tool_' && $f['from'] === '' && $f['to'] === '2026-11-05' && $f['page'] === 1, 'filters are sanitised: characters whitelisted, impossible dates dropped, page at least 1');
ok(admx_filters(['page' => '99999999'])['page'] === 1 && admx_filters(['user' => ['x']])['user'] === '', 'oversized page and array values fall back safely');
$r = $s->audit(admx_filters([]), 1, 50); ok($r['total'] === 120 && count($r['rows']) === 50 && $r['rows'][0]['summary'][6] === '1' && strpos($r['rows'][0]['summary'], 'entry 120') === 0, 'newest first, 50 per page');
$r = $s->audit(admx_filters(['user' => 'owner1']), 1, 50); ok($r['total'] === 60, 'filter by user');
$r = $s->audit(admx_filters(['action' => 'account']), 1, 50); ok($r['total'] === 40, 'filter by action prefix');
$h = admx_audit_view($s, ['view' => 'audit', 'page' => '2']); ok(strpos($h, 'page 2 of 3') !== false && strpos($h, 'Newer') !== false && strpos($h, 'Older') !== false, 'paging');
ok(strpos($h, '<script>x') === false && strpos($h, '&lt;script&gt;') !== false, 'summaries are escaped');
ok(strpos($h, '<form method="get"') !== false && strpos($h, 'name="m" value="access"') !== false && strpos($h, 'method="post"') === false, 'filter form is GET only and keeps the module parameter');
ok(strpos(admx_audit_view($s, ['user' => 'nobody']), 'Nothing to show') !== false, 'empty result is explained');
// health
$h = admx_health_view([['Database', 'Reachable', true], ['Signing secret', 'Not set in the environment (file fallback in use)', null], ['X', 'Bad', false]]); ok(strpos($h, 'OK') !== false && strpos($h, 'Attention') !== false && strpos($h, 'Information') !== false && strpos($h, 'Phase 8F') !== false, 'health states are labelled; backups and monitoring say they arrive later');
// body and views
$b = admx_body($s, [], ['view' => 'nonsense']); ok(strpos($b, 'Users and roles') !== false && strpos($b, 'aria-current="page"') !== false, 'unknown view falls back to users');
foreach (array_keys(admx_views()) as $v) ok(strpos(admx_body($s, [['Database', 'Reachable', true]], ['view' => $v]), 'Users, roles and audit') !== false, "view $v renders");
// source checks
ok(!preg_match('/INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|ALTER\s+TABLE|DROP\s+TABLE|CREATE\s+TABLE|TRUNCATE/i', preg_replace('#//.*$#m', '', $src)), 'no write SQL anywhere in the module');
ok(strpos($src, "LIMIT ' . (int)\$per . ' OFFSET ' . (int)") !== false && strpos($src, 'prepare(') !== false, 'audit query is prepared; limit and offset are integers');
ok(strpos($src, "str_replace(['\\\\', '%', '_']") !== false, 'LIKE wildcards in the action filter are escaped');
ok(substr_count($ac, "elseif (\$m === 'access')") === 1 && strpos($ac, "if (!adm_can((string)\$user['role'], \$mod['perm']))") < strpos($ac, "elseif (\$m === 'access')"), 'route sits after the module permission check');
ok(strpos($ac, "=== 'access'") !== false && !preg_match("#m'\] \?\? ''\) === 'access'\) \{[^}]*POST#", $ac), 'there is no POST route for the access module (read only)');
echo "$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
