<?php
if (isset($_SERVER["SCRIPT_FILENAME"]) && realpath($_SERVER["SCRIPT_FILENAME"]) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
// Unified Admin Control Center, U1: the signed-in person's own account page. Shows identity, role and permitted modules, and lets the person change THEIR OWN password.
// Rules: the account is always the signed-in session user (never an id from the request); the current password must be re-entered; strong-password policy; rate limited; audited;
// the session id is regenerated. No role can be changed here and no other account can be touched. Two-factor sign-in is PREPARED (see docs) but not enabled.

interface AdmAccountStore { public function hash(int $uid): ?string; public function setHash(int $uid, string $hash): bool; }
final class AdmAccountMemoryStore implements AdmAccountStore {
  public array $hashes = []; public function __construct(array $h = []) { $this->hashes = $h; }
  public function hash(int $uid): ?string { return $this->hashes[$uid] ?? null; }
  public function setHash(int $uid, string $hash): bool { if (!isset($this->hashes[$uid])) return false; $this->hashes[$uid] = $hash; return true; }
}
final class AdmAccountPdoStore implements AdmAccountStore {
  public function __construct(private PDO $pdo) {}
  public function hash(int $uid): ?string { $s = $this->pdo->prepare('SELECT password_hash FROM crm_users WHERE id = ? AND active = 1'); $s->execute([$uid]); $v = $s->fetchColumn(); return $v === false ? null : (string)$v; }
  public function setHash(int $uid, string $hash): bool { $s = $this->pdo->prepare('UPDATE crm_users SET password_hash = ? WHERE id = ? AND active = 1'); $s->execute([$hash, $uid]); return $s->rowCount() === 1; }
}

const ADM_PW_MIN = 12; const ADM_PW_MAX = 200;
/** Problems with a proposed new password; empty = acceptable. Length-based (not composition rules) plus the obvious weak choices. */
function adm_password_errors(string $new, string $confirm, string $current, string $username, string $display = ''): array {
  $e = []; $len = mb_strlen($new);
  if ($new === '' ) $e['new'] = 'Enter a new password.';
  elseif ($len < ADM_PW_MIN) $e['new'] = 'Use at least ' . ADM_PW_MIN . ' characters. A long phrase of several words works well.';
  elseif ($len > ADM_PW_MAX) $e['new'] = 'Use at most ' . ADM_PW_MAX . ' characters.';
  elseif ($new === $current) $e['new'] = 'The new password must be different from the current one.';
  elseif (count(array_unique(preg_split('//u', $new, -1, PREG_SPLIT_NO_EMPTY))) < 5) $e['new'] = 'That password has too little variety. Choose something less repetitive.';
  elseif (stripos($new, $username) !== false || ($display !== '' && mb_strlen($display) >= 4 && stripos($new, $display) !== false)) $e['new'] = 'Do not include your username or name in the password.';
  elseif (in_array(strtolower($new), ['password1234', 'password12345', 'qwertyuiop12', '123456789012', 'administrator', 'aihubpedia123', 'letmein123456', 'welcome12345'], true) || preg_match('/^(?:password|aihubpedia|admin)/i', $new) && $len < 16) $e['new'] = 'That password is too easy to guess.';
  if (!isset($e['new']) && $new !== $confirm) $e['confirm'] = 'The two new passwords do not match.';
  return $e;
}
/**
 * Change the signed-in user's own password. $limited(bucket) tells whether too many attempts were made. Returns ['ok','code','msg','errors'].
 * The account is taken from $user['id'] only. Wrong current password and rate limiting are reported without revealing anything else.
 */
function adm_account_change_password(AdmAccountStore $s, array $user, array $post, callable $audit, callable $limited): array {
  $fail = fn(int $c, string $m, array $e = []) => ['ok' => false, 'code' => $c, 'msg' => $m, 'errors' => $e + ['_' => $m]]; $uid = (int)($user['id'] ?? 0);
  if ($uid <= 0) return $fail(403, 'You must be signed in.');
  if ($limited('pw_change:' . $uid)) { $audit('account_password_rate_limited', ''); return $fail(429, 'Too many attempts. Try again in 15 minutes.'); }
  $cur = (string)($post['current'] ?? ''); $new = (string)($post['new'] ?? ''); $conf = (string)($post['confirm'] ?? ''); $hash = $s->hash($uid);
  if ($hash === null || $cur === '' || !password_verify($cur, $hash)) { $audit('account_password_failed', 'wrong current password'); return $fail(422, 'The current password is not correct.', ['current' => 'The current password is not correct.']); }
  $errs = adm_password_errors($new, $conf, $cur, (string)($user['username'] ?? ''), (string)($user['display_name'] ?? '')); if ($errs) return $fail(422, 'Please correct the highlighted fields.', $errs);
  if (!$s->setHash($uid, password_hash($new, PASSWORD_DEFAULT))) return $fail(500, 'The password could not be saved.');
  $audit('account_password_changed', 'own password'); return ['ok' => true, 'code' => 303, 'msg' => 'pw_changed', 'errors' => []];
}

function adm_account_messages(): array { return ['pw_changed' => 'Your password was changed. Use the new password the next time you sign in.']; }
/** The account page body. $errs/$top carry a failed password change back to the form (the password fields are never echoed). */
function adm_account_body(array $user, array $get = [], array $errs = [], ?string $top = null): string {
  $role = (string)($user['role'] ?? ''); $flash = isset($get['msg']) && is_string($get['msg']) ? (adm_account_messages()[$get['msg']] ?? null) : null;
  $o = '<h1>Account and password</h1><p class="lead">Who you are signed in as, what you can open, and your password.</p>';
  if ($flash) $o .= '<p class="panel admOk" role="status">' . adm_h($flash) . '</p>'; if ($top) $o .= '<p class="panel admErr" role="alert">' . adm_h($top) . '</p>';
  $o .= '<section class="panel" aria-labelledby="ac-id"><h2 id="ac-id" style="margin-top:0">Signed in as</h2><dl style="display:grid;grid-template-columns:minmax(110px,170px) minmax(0,1fr);gap:6px 14px;margin:0"><dt>Name</dt><dd style="margin:0">' . adm_h($user['display_name'] ?? '') . '</dd><dt>Username</dt><dd style="margin:0"><code>' . adm_h($user['username'] ?? '') . '</code></dd><dt>Role</dt><dd style="margin:0">' . adm_h($role) . '</dd></dl>'
    . '<p class="small" style="color:var(--muted)">Your role is set by an administrator and cannot be changed here. Everything you do in the Control Center is recorded under your name.</p></section>';
  $o .= '<section class="panel" aria-labelledby="ac-mod"><h2 id="ac-mod" style="margin-top:0">Your modules</h2><ul>';
  foreach (adm_modules() as $id => $m) if (adm_can($role, $m['perm']) && $id !== 'account') $o .= '<li>' . adm_h($m['label']) . (empty($m['live']) ? ' <span class="badge">Phase ' . adm_h($m['phase'] ?? '') . '</span>' : '') . '</li>';
  $o .= '</ul><p class="small" style="color:var(--muted)">Each module also checks your permission itself. A link you do not see is not available to your role.</p></section>';
  $f = function (string $name, string $label, string $auto) use ($errs) { $e = $errs[$name] ?? null; return '<label for="pw_' . $name . '" style="display:block;font-weight:600;margin:.8rem 0 .25rem">' . adm_h($label) . '</label><input id="pw_' . $name . '" name="' . $name . '" type="password" autocomplete="' . $auto . '" required style="width:100%;max-width:420px;min-height:44px;padding:.5rem .65rem;border:1px solid var(--line);border-radius:10px;background:var(--bg);color:var(--ink);font:inherit"' . ($e ? ' aria-invalid="true" aria-describedby="pw_' . $name . '_e"' : '') . '>' . ($e ? '<span class="admErr" id="pw_' . $name . '_e" style="display:block">' . adm_h($e) . '</span>' : ''); };
  $o .= '<section class="panel" aria-labelledby="ac-pw"><h2 id="ac-pw" style="margin-top:0">Change your password</h2><form method="post" action="/admin-center.php?m=account" novalidate>' . crm_csrf_field() . '<input type="hidden" name="act" value="account_password">'
    . $f('current', 'Current password', 'current-password') . $f('new', 'New password (at least ' . ADM_PW_MIN . ' characters)', 'new-password') . $f('confirm', 'Repeat the new password', 'new-password')
    . '<p><button class="admBtn" type="submit" style="background:var(--brand);color:var(--brandInk);border-color:transparent;font-weight:600">Change password</button></p></form>'
    . '<p class="small" style="color:var(--muted)">Choose a long phrase you do not use anywhere else. Do not share it. Other devices stay signed in until their session ends; if you suspect misuse, ask an administrator to deactivate the account.</p></section>';
  $o .= '<section class="panel" aria-labelledby="ac-mfa"><h2 id="ac-mfa" style="margin-top:0">Two-factor sign-in</h2><p>Not enabled. The Control Center is prepared for an optional one-time-code second factor (see <code>docs/admin/MFA_PREPARATION.md</code>). It will only be switched on after the owner approves it and a recovery process exists, so nobody can be locked out.</p></section>';
  $o .= '<section class="panel" aria-labelledby="ac-out"><h2 id="ac-out" style="margin-top:0">Sign out</h2><form method="post" action="/admin-center.php">' . crm_csrf_field() . '<input type="hidden" name="act" value="logout"><button class="admBtn" type="submit">Sign out of this browser</button></form></section>';
  return $o;
}
