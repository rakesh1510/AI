<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly (defence in depth if web-server rules are missing)
/**
 * Security primitives shared by every endpoint (Phase 4).
 *
 *  - Trusted-proxy aware client IP. Forwarded headers are NEVER trusted unless the TCP peer (REMOTE_ADDR) is listed in
 *    AIHUB_TRUSTED_PROXIES. Without that setting the peer address is always used, so spoofed X-Forwarded-For / CF-Connecting-IP
 *    headers cannot change rate limits or hashes.
 *  - Safe redirect validators (local paths and external http(s) URLs).
 *  - Stateless CSRF tokens + same-origin check for anonymous write endpoints.
 *  - Small file-backed rate limiter (works with or without a database).
 *  - Security log: one JSON line per event, no raw IP addresses, tokens, emails or message bodies.
 *  - Secret loading with no hard-coded fallbacks.
 */

// ---------------------------------------------------------------------------
// Configuration sources (environment first, then an optional untracked config.local.php)
// ---------------------------------------------------------------------------
function sec_local_config(): array {
  static $cfg = null;
  if ($cfg !== null) return $cfg;
  $cfg = [];
  $file = getenv('AIHUB_CONFIG_FILE') ?: __DIR__ . '/config.local.php';
  if (is_file($file)) {
    $v = (static function ($f) { return include $f; })($file);
    if (is_array($v)) $cfg = $v;
  }
  // Optional private mail settings kept OUTSIDE the web root and outside Git (default /etc/aihubpedia/mail.php, or AIHUB_MAIL_CONFIG_FILE).
  // The file returns ['mail' => [...]] (or the mail array itself). Its values win over the mail block of config.local.php; the rest of config.local.php is untouched.
  $mailFile = getenv('AIHUB_MAIL_CONFIG_FILE') ?: '/etc/aihubpedia/mail.php';
  if (is_string($mailFile) && $mailFile !== '' && @is_file($mailFile) && @is_readable($mailFile)) {
    $mv = (static function ($f) { return include $f; })($mailFile);
    if (is_array($mv)) {
      $mm = (isset($mv['mail']) && is_array($mv['mail'])) ? $mv['mail'] : $mv;
      $base = (isset($cfg['mail']) && is_array($cfg['mail'])) ? $cfg['mail'] : [];
      $cfg['mail'] = array_merge($base, $mm);
    }
  }
  return $cfg;
}

/** Read a setting from the environment, falling back to config.local.php (never to a hard-coded value). */
function sec_setting(string $env, string $localKey = '', string $default = ''): string {
  $v = getenv($env);
  if ($v !== false && trim($v) !== '') return trim($v);
  if ($localKey !== '') {
    $cfg = sec_local_config();
    foreach (explode('.', $localKey) as $part) { if (!is_array($cfg) || !isset($cfg[$part])) { $cfg = null; break; } $cfg = $cfg[$part]; }
    if (is_scalar($cfg) && trim((string)$cfg) !== '') return trim((string)$cfg);
  }
  return $default;
}

// ---------------------------------------------------------------------------
// Trusted proxies and client IP
// ---------------------------------------------------------------------------
function sec_ip_in_cidr(string $ip, string $cidr): bool {
  $cidr = trim($cidr);
  if ($cidr === '') return false;
  $bits = null;
  if (strpos($cidr, '/') !== false) { [$net, $bits] = explode('/', $cidr, 2); $bits = (int)$bits; } else { $net = $cidr; }
  $a = @inet_pton($ip); $b = @inet_pton($net);
  if ($a === false || $b === false || strlen($a) !== strlen($b)) return false;
  $max = strlen($a) * 8;
  if ($bits === null) $bits = $max;
  if ($bits < 0 || $bits > $max) return false;
  $bytes = intdiv($bits, 8); $rem = $bits % 8;
  if ($bytes > 0 && substr($a, 0, $bytes) !== substr($b, 0, $bytes)) return false;
  if ($rem === 0) return true;
  $mask = (0xFF << (8 - $rem)) & 0xFF;
  return ((ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask));
}

/** @return string[] trusted proxy IPs/CIDRs from AIHUB_TRUSTED_PROXIES (comma separated). Empty = trust nothing. */
function sec_trusted_proxies(): array {
  $raw = sec_setting('AIHUB_TRUSTED_PROXIES', 'trusted_proxies');
  if ($raw === '') return [];
  return array_values(array_filter(array_map('trim', explode(',', $raw)), 'strlen'));
}

function sec_is_trusted_proxy(string $ip, ?array $proxies = null): bool {
  foreach (($proxies ?? sec_trusted_proxies()) as $p) if (sec_ip_in_cidr($ip, $p)) return true;
  return false;
}

function sec_valid_ip(string $ip): bool { return filter_var($ip, FILTER_VALIDATE_IP) !== false; }

/**
 * The visitor's IP address as far as it can be established safely.
 * Forwarded headers are only consulted when the immediate peer is a configured trusted proxy.
 * AIHUB_PROXY_HEADER selects the header: CF-Connecting-IP, X-Real-IP or X-Forwarded-For (default).
 */
function sec_client_ip(?array $server = null, ?array $proxies = null): string {
  $server = $server ?? $_SERVER;
  $peer = trim((string)($server['REMOTE_ADDR'] ?? ''));
  if (!sec_valid_ip($peer)) return '0.0.0.0';
  $proxies = $proxies ?? sec_trusted_proxies();
  if (!$proxies || !sec_is_trusted_proxy($peer, $proxies)) return $peer;

  $header = strtolower(sec_setting('AIHUB_PROXY_HEADER', 'proxy_header', 'X-Forwarded-For'));
  $key = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
  $value = trim((string)($server[$key] ?? ''));
  if ($value === '') return $peer;

  if ($header !== 'x-forwarded-for') {          // single-address headers set by the proxy itself
    return sec_valid_ip($value) ? $value : $peer;
  }
  // X-Forwarded-For: walk from the right (closest hop) and skip our own trusted proxies; the first other address is the client.
  $chain = array_map('trim', explode(',', $value));
  for ($i = count($chain) - 1; $i >= 0; $i--) {
    $hop = $chain[$i];
    if (!sec_valid_ip($hop)) return $peer;      // malformed chain: do not guess
    if (sec_is_trusted_proxy($hop, $proxies)) continue;
    return $hop;
  }
  return $peer;
}

function sec_is_https(?array $server = null): bool {
  $server = $server ?? $_SERVER;
  if (!empty($server['HTTPS']) && strtolower((string)$server['HTTPS']) !== 'off') return true;
  $peer = (string)($server['REMOTE_ADDR'] ?? '');
  if (sec_valid_ip($peer) && sec_is_trusted_proxy($peer) && strtolower((string)($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') return true;
  return false;
}

// ---------------------------------------------------------------------------
// Secrets
// ---------------------------------------------------------------------------
/** Signing secret for tokens and hashes: environment/config, else a generated untracked file, else an ephemeral per-process value (fail closed). */
function sec_secret(): string {
  static $secret = null;
  if ($secret !== null) return $secret;
  $env = sec_setting('AIHUB_FORM_SECRET', 'form_secret');
  if ($env !== '') return $secret = $env;
  $file = __DIR__ . '/data/.form_secret';
  if (is_file($file) && ($v = trim((string)@file_get_contents($file))) !== '') return $secret = $v;
  $v = bin2hex(random_bytes(32));
  if (@file_put_contents($file, $v, LOCK_EX) !== false) { @chmod($file, 0600); return $secret = $v; }
  // Assign BEFORE logging. sec_log() builds its network hash with sec_ip_hash(), which calls sec_secret() again; with $secret still null that
  // recursed until the 30 s execution limit and every page answered HTTP 500 when the web server user could not write data/.form_secret.
  $secret = $v;
  sec_log('secret_not_persisted', ['hint' => 'set AIHUB_FORM_SECRET or make data/ writable by the web server user']);
  return $secret;   // tokens will not validate on the next request: forms fail closed instead of using a guessable key
}

/** Short salted hash of the client network, used only for rate limiting and abuse counters. IPv6 is truncated to /64. */
function sec_ip_hash(string $purpose = 'rate', ?string $ip = null): string {
  $ip = $ip ?? sec_client_ip();
  if (strpos($ip, ':') !== false && ($bin = @inet_pton($ip)) !== false) $ip = bin2hex(substr($bin, 0, 8)) . '::/64';
  $salt = sec_setting('AIHUB_ANALYTICS_SALT', 'analytics_salt') ?: sec_secret();
  return substr(hash_hmac('sha256', $purpose . '|' . $ip, $salt), 0, 16);
}

// ---------------------------------------------------------------------------
// Safe redirects
// ---------------------------------------------------------------------------
/** A same-site path (optionally with query/fragment) or $default. Rejects schemes, //host, backslashes and control characters. */
function sec_safe_local_path($target, string $default = '/'): string {
  if (!is_string($target)) return $default;
  $t = trim($target);
  if ($t === '' || strlen($t) > 2000) return $default;
  if (preg_match('/[\x00-\x1f\x7f\\\\]/', $t)) return $default;
  if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $t)) return $default;            // any scheme: javascript:, data:, https:, //…
  if (strncmp($t, '//', 2) === 0) return $default;
  if ($t[0] !== '/' && $t[0] !== '?' && $t[0] !== '#') $t = '/' . $t;         // legacy relative targets such as tool.php?id=x
  if (strncmp($t, '//', 2) === 0 || strncmp($t, '/\\', 2) === 0) return $default;
  $decoded = rawurldecode($t);
  if (preg_match('/[\x00-\x1f\x7f\\\\]/', $decoded) || strncmp($decoded, '//', 2) === 0) return $default;
  return $t;
}

/** Normalised http(s) URL for outbound links, or null. No credentials, no local/private hosts, no whitespace or control characters. */
function sec_safe_external_url($url): ?string {
  if (!is_string($url)) return null;
  $u = trim($url);
  if ($u === '' || strlen($u) > 2048 || preg_match('/[\x00-\x20\x7f\\\\<>"\']/', $u)) return null;
  $p = parse_url($u);
  if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) return null;
  if (!in_array(strtolower($p['scheme']), ['http', 'https'], true)) return null;
  if (isset($p['user']) || isset($p['pass'])) return null;
  $host = strtolower(trim($p['host'], '[]'));
  if ($host === 'localhost' || preg_match('/\.(local|localhost|internal|lan|home|corp|invalid)$/', $host)) return null;
  if (sec_valid_ip($host)) {
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return null;
  } elseif (!preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/i', $host) && !preg_match('/^xn--/i', $host)) {
    return null;                                                               // single-label or odd hostnames
  }
  if (isset($p['port']) && ($p['port'] < 1 || $p['port'] > 65535)) return null;
  return $u;
}

// ---------------------------------------------------------------------------
// CSRF (anonymous endpoints): stateless HMAC token + same-origin check
// ---------------------------------------------------------------------------
function sec_token(string $purpose, ?int $ts = null): string {
  $ts = $ts ?? time();
  return $ts . '.' . substr(hash_hmac('sha256', $purpose . '|' . $ts, sec_secret()), 0, 32);
}

function sec_token_valid(string $token, string $purpose, int $minAge = 0, ?int $now = null, int $maxAge = 86400): bool {
  $now = $now ?? time();
  if (!preg_match('/^(\d{9,11})\.([a-f0-9]{32})$/', $token, $m)) return false;
  $ts = (int)$m[1];
  if (!hash_equals(substr(hash_hmac('sha256', $purpose . '|' . $ts, sec_secret()), 0, 32), $m[2])) return false;
  $age = $now - $ts;
  return $age >= $minAge && $age <= $maxAge;
}

function sec_csrf_token(string $scope = 'interact'): string { return sec_token('csrf:' . $scope); }

function sec_csrf_field(string $scope = 'interact'): string {
  return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(sec_csrf_token($scope), ENT_QUOTES) . '">';
}

/** True when an Origin/Referer header, if present, names this host. Missing headers are allowed (privacy tools strip them). */
function sec_same_origin(?array $server = null): bool {
  $server = $server ?? $_SERVER;
  $host = strtolower(preg_replace('/:\d+$/', '', (string)($server['HTTP_HOST'] ?? '')));
  foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $k) {
    $v = (string)($server[$k] ?? '');
    if ($v === '' || $v === 'null') { if ($v === 'null') return false; continue; }
    $h = strtolower((string)parse_url($v, PHP_URL_HOST));
    if ($h === '' || $h !== $host) return false;
  }
  return true;
}

/** Validate the CSRF token of the current request (form field _csrf or X-CSRF-Token header) and the origin. */
function sec_csrf_ok(string $scope = 'interact', ?array $post = null, ?array $server = null): bool {
  $post = $post ?? $_POST; $server = $server ?? $_SERVER;
  if (!sec_same_origin($server)) return false;
  $tok = (string)($post['_csrf'] ?? ($server['HTTP_X_CSRF_TOKEN'] ?? ''));
  return sec_token_valid($tok, 'csrf:' . $scope);
}

/** Terminate with a plain JSON or text error. */
function sec_fail(int $status, string $message, bool $json = true): void {
  http_response_code($status);
  header('Cache-Control: no-store');
  if ($json) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => $message]); }
  else { header('Content-Type: text/plain; charset=UTF-8'); echo $message; }
  exit;
}

/** Guard for state-changing endpoints: POST only, same origin, valid token, rate limit. Exits on failure. */
function sec_guard_write(string $scope, int $limit = 30, int $window = 600, bool $json = true): void {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); sec_log('write_wrong_method', ['scope' => $scope]); sec_fail(405, 'POST required', $json); }
  if (!sec_csrf_ok($scope)) { sec_log('csrf_rejected', ['scope' => $scope]); sec_fail(403, 'Security check failed. Please reload the page and try again.', $json); }
  if (sec_rate_limited('write:' . $scope . ':' . basename((string)($_SERVER['SCRIPT_NAME'] ?? 'cli')), $limit, $window)) { sec_log('rate_limited', ['scope' => $scope]); header('Retry-After: ' . $window); sec_fail(429, 'Too many requests. Please try again later.', $json); }
}

// ---------------------------------------------------------------------------
// Rate limiting (file-backed, per client-network hash)
// ---------------------------------------------------------------------------
/**
 * A directory only this user can write: created 0700, not a symlink, owned by the current user, not group/world writable.
 * Returns '' when it cannot be made safe (another local user could otherwise pre-create it in /tmp and tamper with its contents).
 */
function sec_private_dir(string $dir): string {
  if (is_link($dir)) return '';
  if (!is_dir($dir)) @mkdir($dir, 0700, true);
  if (!is_dir($dir) || is_link($dir)) return '';
  if (function_exists('posix_geteuid') && @fileowner($dir) !== posix_geteuid()) return '';
  if ((@fileperms($dir) & 0022) !== 0) { @chmod($dir, 0700); clearstatcache(true, $dir); if ((@fileperms($dir) & 0022) !== 0) return ''; }
  return $dir;
}

/** Default private directory name: one per OS user, so a CLI run by one account cannot disable (or poison) the web account's directory. */
function sec_default_private_dir(string $name): string { return rtrim(sys_get_temp_dir(), '/\\') . '/' . $name . '_' . (function_exists('posix_geteuid') ? posix_geteuid() : get_current_user()); }

/**
 * Directory for rate-limit counters. A configured AIHUB_RATE_DIR that is not private to this user is NOT silently trusted and NOT used to
 * switch limiting off: the per-user default directory is used instead. Only if that also fails does limiting fail open (and is logged).
 */
function sec_rate_dir(): string {
  static $warned = false;
  $configured = sec_setting('AIHUB_RATE_DIR', 'rate_dir');
  if ($configured !== '') { $d = sec_private_dir($configured); if ($d !== '') return $d; if (!$warned) { $warned = true; sec_log('rate_dir_configured_unsafe', ['fallback' => 'per-user default']); } }
  return sec_private_dir(sec_default_private_dir('aihub_rate'));
}

/** Records a hit and returns true when the caller exceeded $max hits within $window seconds. */
function sec_rate_limited(string $bucket, int $max, int $window, ?string $ipHash = null, ?int $now = null): bool {
  $now = $now ?? time();
  $key = hash('sha256', $bucket . '|' . ($ipHash ?? sec_ip_hash('rate')));
  $dir = sec_rate_dir();
  if ($dir === '') { sec_log('rate_dir_unsafe'); return false; }   // cannot store counters safely: fail open (and log) rather than lock everyone out
  $path = $dir . '/' . $key;
  $fh = @fopen($path, 'c+');
  if (!$fh) return false;                       // storage problem: do not lock everyone out, but log it
  flock($fh, LOCK_EX);
  $hits = json_decode((string)stream_get_contents($fh), true);
  $hits = is_array($hits) ? array_values(array_filter($hits, fn($t) => is_int($t) && $t > $now - $window)) : [];
  $limited = count($hits) >= $max;
  if (!$limited) $hits[] = $now;
  ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($hits));
  flock($fh, LOCK_UN); fclose($fh);
  if (mt_rand(1, 200) === 1) sec_rate_gc();
  return $limited;
}

function sec_rate_gc(int $maxAge = 86400): void {
  $dir = sec_rate_dir(); if ($dir === '') return;
  foreach ((array)@scandir($dir) as $f) {
    if ($f === '.' || $f === '..') continue;
    $p = $dir . '/' . $f;
    if (is_file($p) && filemtime($p) < time() - $maxAge) @unlink($p);
  }
}

// ---------------------------------------------------------------------------
// Security log (no raw IP, token, email or message content)
// ---------------------------------------------------------------------------
function sec_log(string $event, array $ctx = []): void {
  $clean = [];
  foreach ($ctx as $k => $v) {
    if (!is_scalar($v) && $v !== null) continue;
    $clean[preg_replace('/[^a-z0-9_]/i', '', (string)$k)] = is_string($v) ? substr(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $v), 0, 200) : $v;
  }
  $line = json_encode(['t' => gmdate('c'), 'event' => preg_replace('/[^a-z0-9_.]/i', '', $event), 'net' => (PHP_SAPI === 'cli' ? 'cli' : sec_ip_hash('log')),
    'path' => substr((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), 0, 120)] + $clean, JSON_UNESCAPED_SLASHES);
  $file = sec_setting('AIHUB_SECURITY_LOG', 'security_log');
  if ($file !== '' && @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX) !== false) return;
  error_log('aihub-security ' . $line);
}

/** Generic error response that never leaks exception text; the detail goes to the log. */
function sec_log_exception(Throwable $e, string $where): void {
  sec_log('exception', ['where' => $where, 'class' => get_class($e), 'msg' => substr($e->getMessage(), 0, 160)]);
}

/** Baseline response headers for HTML pages (idempotent). CSP is documented as a follow-up: pages still use inline styles/scripts. */
function sec_send_headers(): void {
  if (headers_sent()) return;
  header('X-Content-Type-Options: nosniff');
  header('X-Frame-Options: SAMEORIGIN');
  header('Referrer-Policy: strict-origin-when-cross-origin');
  header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), interest-cohort=()');
}

/** The legacy demo login/bookmarks feature exists only for local demos: it is off unless AIHUB_DEMO_LOGIN=1. */
function demo_login_enabled(): bool { return sec_setting('AIHUB_DEMO_LOGIN', 'demo_login') === '1'; }
function sec_require_demo(): void { if (!demo_login_enabled()) { header('X-Robots-Tag: noindex'); http_response_code(404); require __DIR__ . '/404.php'; exit; } }

/** Start the (non-admin) PHP session with safe cookie flags: HttpOnly, SameSite=Lax, Secure on HTTPS. */
function sec_session_start(): void {
  if (session_status() === PHP_SESSION_ACTIVE) return;
  ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1');
  session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => sec_is_https(), 'httponly' => true, 'samesite' => 'Lax']);
  @session_start();
}
