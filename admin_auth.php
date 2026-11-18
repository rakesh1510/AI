<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly (defence in depth if web-server rules are missing)
/**
 * Admin authentication (Phase 4). Replaces the old "?pass=" URL password.
 *
 *  - Disabled until AIHUB_ADMIN_PASS_HASH (a password_hash() value) is configured. There is no default password.
 *  - Session cookie: HttpOnly, SameSite=Strict, Secure on HTTPS; id regenerated at login; 30 min idle / 8 h absolute timeout.
 *  - Login attempts rate limited per client network (5 per 15 minutes) and logged without secrets.
 *  - All admin actions are POST with a session-bound CSRF token.
 *  - Generate a hash with:  php -r 'echo password_hash(trim(fgets(STDIN)), PASSWORD_DEFAULT), "\n";'
 */
require_once __DIR__ . '/security.php';

const ADMIN_IDLE_SECONDS = 1800;
const ADMIN_MAX_SECONDS = 28800;

function admin_hash(): string { return sec_setting('AIHUB_ADMIN_PASS_HASH', 'admin_pass_hash'); }

function admin_enabled(): bool {
  $h = admin_hash();
  return $h !== '' && !empty(password_get_info($h)['algo']);
}

function admin_session_start(): void {
  if (session_status() === PHP_SESSION_ACTIVE) return;
  ini_set('session.use_strict_mode', '1');
  ini_set('session.use_only_cookies', '1');
  session_name('aihub_admin');
  session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => sec_is_https(), 'httponly' => true, 'samesite' => 'Strict']);
  @session_start();
}

function admin_logged_in(?int $now = null): bool {
  admin_session_start();
  $now = $now ?? time();
  if (empty($_SESSION['admin_at']) || empty($_SESSION['admin_login'])) return false;
  if ($now - (int)$_SESSION['admin_at'] > ADMIN_IDLE_SECONDS || $now - (int)$_SESSION['admin_login'] > ADMIN_MAX_SECONDS) { admin_logout(); return false; }
  $_SESSION['admin_at'] = $now;
  return true;
}

/** @return string 'ok' | 'disabled' | 'rate_limited' | 'invalid' */
function admin_attempt_login(string $password): string {
  if (!admin_enabled()) return 'disabled';
  if (sec_rate_limited('admin_login', 5, 900)) { sec_log('admin_login_rate_limited'); return 'rate_limited'; }
  if (!password_verify($password, admin_hash())) { sec_log('admin_login_failed'); return 'invalid'; }
  admin_session_start();
  session_regenerate_id(true);
  $_SESSION['admin_login'] = $_SESSION['admin_at'] = time();
  $_SESSION['admin_csrf'] = bin2hex(random_bytes(16));
  sec_log('admin_login_ok');
  return 'ok';
}

function admin_logout(): void {
  admin_session_start();
  $_SESSION = [];
  if (ini_get('session.use_cookies')) { $p = session_get_cookie_params(); setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Strict']); }
  @session_destroy();
}

function admin_csrf_token(): string {
  admin_session_start();
  if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(16));
  return (string)$_SESSION['admin_csrf'];
}

function admin_csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(admin_csrf_token(), ENT_QUOTES) . '">'; }

function admin_csrf_ok(?array $post = null): bool {
  admin_session_start();
  $post = $post ?? $_POST;
  $t = (string)($post['_csrf'] ?? '');
  return $t !== '' && !empty($_SESSION['admin_csrf']) && hash_equals((string)$_SESSION['admin_csrf'], $t) && sec_same_origin();
}

/**
 * Gate for admin pages. Renders the login form (or a "disabled" notice) and exits unless an authenticated session exists.
 * Pages must call this before producing output. Uses layout_top/bottom for presentation.
 */
function admin_require(string $title = 'Admin'): void {
  header('Cache-Control: no-store');
  header('X-Robots-Tag: noindex, nofollow');
  if (!admin_enabled()) {
    http_response_code(503);
    $GLOBALS['title'] = $title . ' — AIHubpedia'; $robots = 'noindex, nofollow'; /* local variable: this function includes the layout, which reads $robots from the including scope */
    require __DIR__ . '/layout_top.php';
    echo '<section class="panel"><h1>Admin is disabled</h1><p class="small">No administrator password has been configured on this server. Set <code>AIHUB_ADMIN_PASS_HASH</code> to enable it. There is no default password.</p></section>';
    require __DIR__ . '/layout_bottom.php';
    exit;
  }
  $msg = '';
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['admin_action'] ?? '') === 'login') {
    if (!sec_same_origin() || !sec_token_valid((string)($_POST['_login_token'] ?? ''), 'admin-login', 1)) { $msg = 'Please reload the page and try again.'; }
    else {
      $r = admin_attempt_login((string)($_POST['password'] ?? ''));
      if ($r === 'ok') { header('Location: ' . sec_safe_local_path($_SERVER['REQUEST_URI'] ?? '/admin.php', '/admin.php'), true, 303); exit; }
      $msg = $r === 'rate_limited' ? 'Too many attempts. Try again in 15 minutes.' : 'Sign-in failed.';
      if ($r === 'rate_limited') http_response_code(429);
    }
  }
  if (admin_logged_in()) return;
  $GLOBALS['title'] = $title . ' — AIHubpedia'; $robots = 'noindex, nofollow'; /* local variable: this function includes the layout, which reads $robots from the including scope */
  require __DIR__ . '/layout_top.php';
  ?>
  <section class="panel" style="max-width:420px;margin:24px auto">
    <h1 style="margin-top:0">Admin sign-in</h1>
    <?php if ($msg !== ''): ?><div class="noteBox" role="alert"><?= htmlspecialchars($msg, ENT_QUOTES) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="admin_action" value="login">
      <input type="hidden" name="_login_token" value="<?= htmlspecialchars(sec_token('admin-login'), ENT_QUOTES) ?>">
      <label for="adminPw">Password</label>
      <input class="input" id="adminPw" name="password" type="password" autocomplete="current-password" required>
      <p style="margin-top:14px"><button class="btn">Sign in</button></p>
    </form>
  </section>
  <?php
  require __DIR__ . '/layout_bottom.php';
  exit;
}
