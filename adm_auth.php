<?php
if (isset($_SERVER["SCRIPT_FILENAME"]) && realpath($_SERVER["SCRIPT_FILENAME"]) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
// Unified Admin Control Center, U1: ONE sign-in experience. This is NOT a new authentication system: it renders the Control Center's own sign-in page and then calls the
// existing CRM account functions (crm_login, crm_current_user, rate limit, session id regeneration, idle and maximum timeouts, CSRF). No account is created, promoted or assumed.

/** Standalone sign-in or notice page in the Control Center design (not the public site layout). */
function adm_auth_page(string $title, string $inner, int $status = 200): void {
  if (function_exists('sec_send_headers')) sec_send_headers();
  header('Cache-Control: no-store'); header('X-Robots-Tag: noindex, nofollow'); header('Content-Type: text/html; charset=UTF-8'); http_response_code($status);
  echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex, nofollow"><meta name="color-scheme" content="light dark"><title>' . adm_h($title) . ' | AIHubpedia Control Center</title><style>' . adm_css() . '</style></head><body>'
    . '<a class="skip" href="#main">Skip to content</a>' . adm_env_html() . '<main id="main" class="admAuth" tabindex="-1"><p class="admBrand" style="margin:0 0 10px">AIHubpedia Control Center</p><div class="panel">' . $inner . '</div></main></body></html>';
  exit;
}
function adm_signin_html(string $msg = '', string $token = ''): string {
  return '<h1>Sign in</h1><p class="lead">Use your personal Control Center account. There is one sign-in for every administration area.</p>' . ($msg !== '' ? '<p class="err" role="alert">' . adm_h($msg) . '</p>' : '')
    . '<form method="post" autocomplete="off"><input type="hidden" name="act" value="login"><input type="hidden" name="_login_token" value="' . adm_h($token) . '">'
    . '<label for="admUser">Username</label><input id="admUser" name="username" autocomplete="username" required autocapitalize="none" spellcheck="false"><label for="admPw">Password</label><input id="admPw" name="password" type="password" autocomplete="current-password" required>'
    . '<button class="admBtn" type="submit">Sign in</button></form><p class="small" style="color:var(--muted);margin-bottom:0">Accounts are created by an administrator. There are no default accounts and no shared passwords.</p>';
}
/** Returns the signed-in user, or renders the sign-in page (or a setup notice) and exits. Must run before any output. */
function adm_gate(): array {
  header('Cache-Control: no-store'); header('X-Robots-Tag: noindex, nofollow');
  if (!crm_tables_ready()) adm_auth_page('Not set up', '<h1>Control Center is not set up</h1><p>The account tables are not installed on this server. The administrator must apply <code>database/phase6_crm.sql</code> to a reviewed database first.</p>', 503);
  $pdo = crm_pdo();
  if ((int)$pdo->query('SELECT COUNT(*) FROM crm_users WHERE active = 1')->fetchColumn() === 0) adm_auth_page('No accounts', '<h1>Control Center is disabled</h1><p>No account exists yet. An administrator creates the first owner account on the server with <code>php scripts/crm_user.php</code>. There are no default accounts.</p>', 503);
  $msg = '';
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['act'] ?? '') === 'login') {
    if (!sec_same_origin() || !sec_token_valid((string)($_POST['_login_token'] ?? ''), 'crm-login', 1)) $msg = 'Please reload the page and try again.';
    else { [$code] = crm_login($pdo, (string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''));
      if ($code === 'ok') { header('Location: ' . sec_safe_local_path($_SERVER['REQUEST_URI'] ?? '/admin-center.php', '/admin-center.php'), true, 303); exit; }
      if ($code === 'rate_limited') http_response_code(429); $msg = $code === 'rate_limited' ? 'Too many attempts. Try again in 15 minutes.' : 'Sign-in failed. Check the username and password.'; }
  }
  $u = crm_current_user($pdo); if ($u) return $u;
  adm_auth_page('Sign in', adm_signin_html($msg, sec_token('crm-login')), http_response_code() === 429 ? 429 : 200);
  return [];
}
