<?php
// Unified Admin Control Center, U4, DATABASE-FREE: owner-only "record payment received" action on a submission (manual confirmation, audited, never implies bank verification) and the
// legacy-password switch. In-memory store, synthetic data. The MySQL store's SQL is checked statically only (not run).
$root = dirname(__DIR__); chdir($root);
ob_start(); require_once $root . '/helpers.php'; require_once $root . '/adm_core.php'; require_once $root . '/adm_tools_views.php'; ob_end_clean();
$pass = 0; $fail = 0; function ok($c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; fwrite(STDERR, "  FAIL: $m\n"); } }
$owner = ['id' => 1, 'username' => 'own', 'role' => 'owner', 'display_name' => 'Olivia Owner']; $audit = []; $au = function (string $a, string $s, string $x) use (&$audit) { $audit[] = [$a, $s, $x]; };
$subs = [['id' => 1, 'name' => 'Paid Tool', 'category' => 'Coding', 'website' => 'https://paid.example.com/', 'short_desc' => 'A paid synthetic tool for payment tests.', 'plan' => 'featured', 'price' => 49, 'currency' => 'EUR', 'payment_status' => 'unpaid'],
  ['id' => 2, 'name' => 'Free Tool', 'category' => 'Writing', 'website' => 'https://free.example.com/', 'short_desc' => 'A free synthetic tool for payment tests.', 'plan' => 'free', 'price' => 0, 'payment_status' => 'not_required'],
  ['id' => 3, 'name' => 'Decided Tool', 'category' => 'Writing', 'website' => 'https://dec.example.com/', 'short_desc' => 'A decided synthetic tool for payment tests.', 'plan' => 'featured', 'price' => 49, 'currency' => 'EUR', 'payment_status' => 'unpaid', 'status' => 'rejected']];
$mk = fn() => new AdmToolMemoryStore([], ['Coding', 'Writing'], $subs);
// permission
foreach (['editor', 'sales', 'viewer', 'verifier', 'nobody'] as $role) { $s = $mk(); $audit = []; $r = admt_apply($s, ['username' => 'x', 'role' => $role], 'submission_record_payment', ['id' => 1, 'confirm' => '1'], $au); ok(!$r['ok'] && $r['code'] === 403 && $s->submission(1)['payment_status'] === 'unpaid', "$role cannot record a payment (403, nothing changed)"); }
ok(admt_action_perm('submission_record_payment') === 'tools_publish' && adm_can('owner', 'tools_publish') && !adm_can('editor', 'tools_publish'), 'the action needs the owner-only permission');
// explicit confirmation
$s = $mk(); $audit = []; $r = admt_apply($s, $owner, 'submission_record_payment', ['id' => 1], $au); ok(!$r['ok'] && $r['code'] === 422 && $s->submission(1)['payment_status'] === 'unpaid' && !$audit, 'no confirmation: refused, nothing recorded, nothing audited');
$r = admt_apply($s, $owner, 'submission_record_payment', ['id' => 1, 'confirm' => 'yes'], $au); ok(!$r['ok'] && $s->submission(1)['payment_status'] === 'unpaid', 'only the explicit value 1 counts as confirmation');
// success
$r = admt_apply($s, $owner, 'submission_record_payment', ['id' => 1, 'confirm' => '1', 'note' => 'Transfer ref 123 <b>x</b>'], $au);
ok($r['ok'] && $s->submission(1)['payment_status'] === 'paid' && $r['msg'] === 'sub_paid', 'owner records the payment: paid, success message');
ok(count($audit) === 1 && $audit[0][0] === 'tool_submission_payment_recorded' && strpos($audit[0][2], 'own') !== false && strpos($audit[0][2], 'not bank-verified') !== false && strpos($audit[0][2], '<b>') === false, 'audited with the actor and the "not bank-verified" wording; markup stripped from the reference');
ok(strpos(admt_messages()['sub_paid'], 'not verified against a bank') !== false && stripos(admt_messages()['sub_paid'], 'bank-verified') === false, 'the message never implies bank verification');
// idempotence and limits
$audit = []; $r = admt_apply($s, $owner, 'submission_record_payment', ['id' => 1, 'confirm' => '1'], $au); ok(!$r['ok'] && $r['code'] === 409 && !$audit, 'a second confirmation is refused (409) and writes no audit row');
$r = admt_apply($s, $owner, 'submission_record_payment', ['id' => 2, 'confirm' => '1'], $au); ok(!$r['ok'] && $r['code'] === 409 && $s->submission(2)['payment_status'] === 'not_required', 'a free submission cannot be marked paid');
$r = admt_apply($s, $owner, 'submission_record_payment', ['id' => 3, 'confirm' => '1'], $au); ok(!$r['ok'] && $r['code'] === 409 && $s->submission(3)['payment_status'] === 'unpaid', 'a decided submission cannot be marked paid');
$r = admt_apply($s, $owner, 'submission_record_payment', ['id' => 99, 'confirm' => '1'], $au); ok(!$r['ok'] && $r['code'] === 404, 'unknown submission: 404');
$r = admt_apply($s, $owner, 'submission_record_payment', ['id' => 0, 'confirm' => '1'], $au); ok(!$r['ok'] && $r['code'] === 404, 'missing id: 404');
// integrity with the approval rule (8C): approval of a paid plan is blocked until payment is confirmed
$s2 = $mk(); ok(admt_submission_blockers($s2->submission(1)) !== [], 'before confirmation, approval stays blocked (existing rule)');
admt_apply($s2, $owner, 'submission_record_payment', ['id' => 1, 'confirm' => '1'], $au); ok(admt_submission_blockers($s2->submission(1)) === [], 'after the owner confirms, the existing blocker clears (no other rule changed)');
// screens
$h = admt_submission_view($mk(), $owner, 1, [], [], null); ok(strpos($h, 'tool_submission_record_payment') !== false && strpos($h, 'not verified against a bank') !== false && strpos($h, 'name="confirm"') !== false && strpos($h, 'Nothing here takes payment') !== false, 'owner sees the payment panel with the explicit confirmation and the honest wording');
$h = admt_submission_view($s2, $owner, 1, [], [], null); ok(strpos($h, 'tool_submission_record_payment') === false, 'once paid, the form is gone');
$h = admt_submission_view($mk(), $owner, 2, [], [], null); ok(strpos($h, 'tool_submission_record_payment') === false, 'no payment form for a free submission');
$h = admt_submission_view($mk(), ['username' => 'ed', 'role' => 'editor'], 1, [], [], null); ok(strpos($h, 'tool_submission_record_payment') === false, 'an editor never sees the payment form');
// static: SQL only touches payment_status, guarded by the current value
$core = file_get_contents($root . '/adm_tools_core.php'); ok(strpos($core, "UPDATE submissions SET payment_status = 'paid' WHERE id = ? AND payment_status = 'unpaid'") !== false, 'MySQL store updates only payment_status, and only from unpaid');
ok(substr_count($core, "SET payment_status") === 1, 'payment_status is written in exactly one place');
// Finance integrity: legacy payments stay separate from CRM revenue (8D unchanged)
ok(trim((string)shell_exec('git diff --stat 7c4b35f -- adm_finance_core.php adm_finance_store.php adm_finance_views.php 2>/dev/null')) === '', 'Finance files unchanged: the action only changes a submission row, never CRM revenue');
// ---- tool profile verification inside the shell (reviewer = signed-in account; roles; independence) ----
require_once $root . '/adm_editorial.php';
$ed = ['id' => 2, 'username' => 'edie', 'display_name' => 'Edie Editor', 'role' => 'editor']; $v1 = ['id' => 3, 'username' => 'verifier1', 'display_name' => 'Verifier One', 'role' => 'verifier']; $v2 = ['id' => 4, 'username' => 'verifier2', 'display_name' => 'Vera Two', 'role' => 'verifier'];
$calls = []; $app = function (array $in, string $via) use (&$calls) { $calls[] = $in; return [true, 'ok']; }; $aud = []; $ad = function (string $a, string $x) use (&$aud) { $aud[] = [$a, $x]; };
$run = function (array $u, array $post, ?array $log = null) use (&$calls, $app, $ad) { $calls = []; return admed_run($u, 'POST', [], $post, $app, $ad); };
$r = $run($ed, ['tool_id' => 't1', 'action' => 'note', 'note' => 'research note', 'reviewer' => 'Somebody Else']);
ok(isset($r['redirect']) && count($calls) === 1 && $calls[0]['reviewer'] === 'Edie Editor (edie)', 'the reviewer is the signed-in account; a posted "reviewer" name is ignored');
$r = $run($ed, ['tool_id' => 't1', 'action' => 'verify', 'confirm' => '1']); ok(!$calls && strpos($r['redirect'], 'msg=wrong_role') !== false, 'an editor cannot verify (the ledger is not even called)');
foreach (['reject', 'reopen'] as $a) { $r = $run($ed, ['tool_id' => 't1', 'action' => $a, 'note' => 'x']); ok(!$calls && strpos($r['redirect'], 'wrong_role') !== false, "an editor cannot $a"); }
foreach (['note', 'research_needed', 'sources_checked'] as $a) { $r = $run($v1, ['tool_id' => 't1', 'action' => $a, 'note' => 'x']); ok(!$calls && strpos($r['redirect'], 'wrong_role') !== false, "a verifier does not do preparer action $a (stays independent)"); }
$r = $run($v1, ['tool_id' => 't1', 'action' => 'verify', 'confirm' => '1', 'reviewer' => 'Fake Name']); ok(count($calls) === 1 && $calls[0]['reviewer'] === 'Verifier One (verifier1)' && $calls[0]['action'] === 'verify', 'a verifier verifies as themselves');
$owner['display_name'] = 'Olivia Owner'; foreach (['note', 'verify', 'reject'] as $a) { $r = $run($owner, ['tool_id' => 't1', 'action' => $a, 'note' => 'x']); ok(!$calls && strpos($r['redirect'], 'read_only') !== false, "the owner cannot $a (read only)"); }
foreach (['sales', 'viewer', 'nobody'] as $role) { $r = admed_run(['id' => 9, 'username' => 'q', 'display_name' => 'Q', 'role' => $role], 'POST', [], ['tool_id' => 't1', 'action' => 'note'], $app, $ad); ok($r['status'] === 403, "$role is refused"); }
ok(admed_independent([['reviewer' => 'Edie Editor (edie)', 'action' => 'note']], 'Vera Two (verifier2)') && !admed_independent([['reviewer' => 'Vera Two (verifier2)', 'action' => 'sources_checked']], 'Vera Two (verifier2)') && admed_independent([['reviewer' => 'Vera Two (verifier2)', 'action' => 'reject']], 'Vera Two (verifier2)'), 'independence: preparing a profile blocks verifying it yourself');
ok(count(array_filter($aud, fn($x) => $x[0] === 'editorial_denied')) >= 8 && count(array_filter($aud, fn($x) => $x[0] === 'editorial_review')) >= 2, 'denials and reviews are audited');
$ids = array_keys(tools_by_id()); $tid = $ids[0] ?? ''; $o = admed_view($ed, '', ''); ok(strpos($o, 'Queue') !== false && strpos($o, 'Edie Editor (edie)') !== false, 'queue view shows the identity');
$o = admed_view($ed, $tid, ''); ok(strpos($o, 'name="reviewer"') === false && strpos($o, 'value="note"') !== false && strpos($o, 'value="verify"') === false && strpos($o, '_csrf') !== false, 'editor form: no typed reviewer field, only preparer actions, CSRF token');
$o = admed_view($v1, $tid, ''); ok(strpos($o, 'name="reviewer"') === false && strpos($o, 'value="verify"') !== false && strpos($o, 'value="note"') === false, 'verifier form: only decision actions');
$o = admed_view($owner, $tid, ''); ok(strpos($o, '<form') === false, 'the owner sees no form');
$o = admed_view($ed, $tid, 'not_independent'); ok(strpos($o, 'cannot also verify it') !== false, 'refusal messages are shown');
$o = admed_view($ed, $tid, '<script>'); ok(strpos($o, '<script>') === false, 'unknown message code is not echoed');
$es = file_get_contents($root . '/adm_editorial.php'); ok(strpos($es, 'editorial_log_append') !== false && substr_count($es, "'admin_session'") === 1, 'one write path, through the unchanged ledger');
ok(trim((string)shell_exec('git diff --stat 7c4b35f -- editorial_review.php admin-editorial.php 2>/dev/null')) === '', 'the ledger and the legacy editorial page are unchanged (the legacy URL still works)');
ok(adm_can('editor', 'universe') && adm_can('verifier', 'universe') && adm_can('owner', 'universe') && !adm_can('sales', 'universe'), 'module permission is the existing universe permission');
$ac = file_get_contents($root . '/admin-center.php'); $pG = strpos($ac, '$user = adm_gate()'); $pC = strpos($ac, 'crm_csrf_ok()'); $pE = strpos($ac, "=== 'editorial') {"); $pR = strpos($ac, "admed_run(\$user, 'POST'");
ok($pG < $pC && $pC < $pE && $pE < $pR, 'order: sign-in, CSRF, then the editorial route');
// ---- legacy password switch (D2): off by default, nothing removed ----
require_once $root . '/admin_auth.php';
putenv('AIHUB_LEGACY_ADMIN'); ok(admin_legacy_retired() === false, 'default: the legacy pages stay available');
putenv('AIHUB_LEGACY_ADMIN=on'); ok(admin_legacy_retired() === false, 'on: available'); putenv('AIHUB_LEGACY_ADMIN=maybe'); ok(admin_legacy_retired() === false, 'an unknown value never retires the pages');
putenv('AIHUB_LEGACY_ADMIN=off'); ok(admin_legacy_retired() === true, 'off: retired'); putenv('AIHUB_LEGACY_ADMIN');
$aa = file_get_contents($root . '/admin_auth.php'); ok(strpos($aa, 'AIHUB_ADMIN_PASS_HASH') !== false && strpos($aa, 'function admin_attempt_login') !== false, 'the legacy login code is still present (nothing removed before the owner confirms a working personal login)');
ok(strpos($aa, 'http_response_code(410)') !== false && strpos($aa, '/admin-center.php') !== false, 'retired pages say where to go');
echo "$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
