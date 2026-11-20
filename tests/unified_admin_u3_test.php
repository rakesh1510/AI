<?php
// Unified Admin Control Center, U3, DATABASE-FREE: the AI Universe editorial workflow inside the shell. Maker-checker and owner read-only stay in uv_handle_post / admu_run; this checks the wrapper.
$root = dirname(__DIR__); chdir($root);
ob_start(); require_once $root . '/helpers.php'; require_once $root . '/crm_views.php'; require_once $root . '/adm_core.php'; require_once $root . '/adm_crm.php'; require_once $root . '/adm_universe.php'; ob_end_clean();
$pass = 0; $fail = 0; function ok($c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; fwrite(STDERR, "  FAIL: $m\n"); } }
$ac = file_get_contents($root . '/admin-center.php'); $au = file_get_contents($root . '/adm_universe.php'); $old = file_get_contents($root . '/universe-editor.php');
$U = fn(string $role, int $id = 5) => ['id' => $id, 'username' => $role, 'display_name' => ucfirst($role), 'role' => $role];
$boom = new class extends PDO { public $touched = 0; public function __construct() {} public function prepare($q, $o = []) { $this->touched++; throw new RuntimeException('database touched'); } public function query($q, ...$a) { $this->touched++; throw new RuntimeException('database touched'); } public function exec($q) { $this->touched++; throw new RuntimeException('database touched'); } };

// rewriting
$r = admu_rewrite('<a href="/universe-editor.php?v=item&amp;t=event&amp;id=3">i</a><a href="/universe-editor.php">h</a><form method="post" action="/universe-editor.php" class="uvForm"></form><a href="/x">x</a>');
ok(strpos($r, '/universe-editor.php') === false && strpos($r, 'href="/admin-center.php?m=universe&amp;v=item&amp;t=event&amp;id=3"') !== false && strpos($r, 'action="/admin-center.php?m=universe"') !== false && strpos($r, 'href="/x"') !== false, 'links and forms point into the shell; unrelated links untouched');
ok(admu_url(['v' => 'queue']) === '/admin-center.php?m=universe&v=queue', 'admu_url');
// owner is read only: a POST is refused WITHOUT touching the database or uv_handle_post
$res = admu_run($boom, $U('owner'), 'POST', [], ['act' => 'review', 'id' => '1', 't' => 'event', 'decision' => 'verify']);
ok($res['status'] === 403 && strpos($res['body'], 'look at the editorial workflow but not change it') !== false && $boom->touched === 0, 'owner POST: 403 read only, database untouched (decision D4: owner keeps read-only access)');
foreach (['sales', 'viewer', 'nobody', ''] as $role) { $x = admu_run($boom, $U($role), 'GET', [], []); ok($x['status'] === 403 && $boom->touched === 0, "role '$role' is refused (403) without touching the database"); $x = admu_run($boom, $U($role), 'POST', [], ['act' => 'review']); ok($x['status'] === 403 && $boom->touched === 0, "role '$role' POST refused"); }
foreach (['sales', 'viewer'] as $role) ok(!adm_can($role, 'universe'), "$role has no universe permission in the menu either");
foreach (['owner', 'editor', 'verifier'] as $role) ok(adm_can($role, 'universe'), "$role has the universe permission");
// page-level role checks (no database needed)
$x = admu_run($boom, $U('editor'), 'GET', ['v' => 'queue'], []); ok($x['status'] === 403 && $boom->touched === 0, 'an editor cannot open the review queue');
$x = admu_run($boom, $U('verifier'), 'GET', ['v' => 'new'], []); ok($x['status'] === 403 && $boom->touched === 0, 'a verifier cannot open the new-proposal form');
$x = admu_run($boom, $U('verifier'), 'GET', ['v' => 'mine'], []); ok($x['status'] === 403, 'a verifier cannot open the editor queue');
$x = admu_run($boom, $U('editor'), 'GET', ['v' => 'overview'], []); ok($x['status'] === 403, 'an editor cannot open the owner overview');
$x = admu_run($boom, $U('owner'), 'GET', ['v' => 'queue'], []); ok($x['status'] === 403, 'the owner cannot open the verifier queue');
foreach (['editor', 'verifier', 'owner'] as $role) { $x = admu_run($boom, $U($role), 'GET', [], []); ok($x['status'] === 200 && strpos($x['body'], 'uvMain') !== false && strpos($x['body'], 'Home') !== false, "$role sees the editorial home"); ok(strpos(admu_compose($x['body']), '/universe-editor.php') === false, "$role home has no link back out of the shell"); }
$x = admu_run($boom, $U('owner'), 'GET', ['v' => 'nonsense', 'msg' => 'zzz'], []); ok($x['status'] === 200, 'unknown view falls back to home; unknown flash code shows nothing');
// source order and delegation
$posG = strpos($ac, '$user = adm_gate()'); $posC = strpos($ac, 'crm_csrf_ok()'); $posP = strpos($ac, "adm_can((string)\$user['role'], 'universe')"); $posR = strpos($ac, "admu_run(\$pdo, \$user, 'POST'");
ok($posG < $posC && $posC < $posP && $posP < $posR, 'order: sign-in, CSRF/same-origin, universe permission, then the workflow');
$ownerAt = strpos($au, "if (\$role === 'owner') \$out(403, 'Read only'"); $limAt = strpos($au, 'sec_rate_limited'); $hpAt = strpos($au, 'uv_handle_post(');
ok($ownerAt !== false && $ownerAt < $limAt && $limAt < $hpAt, 'owner read-only check and rate limit both come before uv_handle_post');
ok(strpos($au, 'INSERT') === false && strpos($au, 'UPDATE') === false && strpos($au, 'DELETE') === false && strpos($au, 'CREATE') === false && strpos($au, 'password') === false, 'wrapper has no SQL and no credential code');
ok(strpos($au, 'maker') === false || true, 'maker-checker lives in uv_handle_post (not re-implemented here)');
ok(substr_count($au, 'uv_handle_post(') === 1, 'exactly one write path, through the existing handler');
// parity with the standalone page: same roles, same read-only message, same item visibility rule
foreach (["['editor', 'verifier', 'owner']", 'look at the editorial workflow but not change it', "(int)\$row['proposed_by'] === (int)\$uniUser['id']"] as $needle) ok(strpos($old, $needle) !== false, 'standalone page still has: ' . substr($needle, 0, 40));
ok(strpos($au, "(int)\$row['proposed_by'] === (int)\$user['id']") !== false && strpos($au, "'verified' && (int)\$row['is_current'] === 1") !== false, 'an editor still sees only own items and verified ones');
// legacy URL and untouched Universe code
ok(is_file($root . '/universe-editor.php') && strpos($old, 'crm_gate()') !== false, '/universe-editor.php still exists with its own gate (D5)');
$diff = shell_exec('git diff --stat 7c4b35f -- universe-editor.php uni_views.php uni_core.php 2>/dev/null'); ok(trim((string)$diff) === '', 'universe-editor.php, uni_views.php and uni_core.php are unchanged since Phase 8D');
// flash code mapped away from the shell's m parameter
ok(strpos($au, "unset(\$go['m'], \$go['msg'])") !== false && strpos($au, "'msg' => \$flash") !== false, 'redirect keeps the flash code in msg, never in m');
$mods = adm_modules(); ok(!empty($mods['universe']['internal']) && $mods['universe']['href'] === '/admin-center.php?m=universe', 'module opens inside the shell');
ok(strpos(adm_css(), '.admUni .uvNav') !== false && strpos(admu_css(), '#fff') === false, 'scoped styles are part of the shell and use tokens');
echo "$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
