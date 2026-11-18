<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
/**
 * CRM core (Phase 6): users and roles, sessions, CSRF, audit log, input helpers.
 *
 * Identity: CRM users live in the crm_users table (created only by scripts/crm_user.php; there are no default accounts). Passwords are
 * stored as password_hash() values. Every change records the acting user in crm_audit_log. The older single-password admin pages
 * (admin.php, admin-leads.php, admin-editorial.php) are unchanged and separate.
 * Roles: owner (everything incl. approvals, contracts, revenue), sales (create/edit CRM records, no approvals or revenue), viewer (read only).
 */
require_once __DIR__ . '/security.php';

const CRM_IDLE_SECONDS = 1800;
const CRM_MAX_SECONDS = 28800;

function crm_roles(): array {
  return [
    'owner'  => ['read', 'write', 'assign', 'approve', 'revenue', 'users', 'outreach'],
    'sales'  => ['read', 'write', 'assign', 'outreach'],
    'viewer' => ['read'],
  ];
}
function crm_can(?string $role, string $action): bool { return $role !== null && in_array($action, crm_roles()[$role] ?? [], true); }

function crm_currencies(): array { return ['EUR', 'USD', 'GBP', 'INR', 'CHF', 'CAD', 'AUD']; }
/** Strict date parsing: "YYYY-MM-DD" (or "YYYY-MM-DD HH:MM" when $time) that really exists. strtotime() is avoided because it accepts words such as "x" (a military time zone). */
function crm_date(string $s, bool $time = false): ?int {
  $s = trim($s);
  if (!preg_match($time ? '/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?$/' : '/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return null;
  if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[1] < 2000 || (int)$m[1] > 2100) return null;
  $h = (int)($m[4] ?? 0); $i = (int)($m[5] ?? 0); if ($h > 23 || $i > 59) return null;
  return gmmktime($h, $i, 0, (int)$m[2], (int)$m[3], (int)$m[1]);
}
function crm_now(?int $ts = null): string { return gmdate('Y-m-d H:i:s', $ts ?? time()); }

// ---- sanitising ----------------------------------------------------------------------------------------------------------------------
function crm_text($v, int $max): string {
  $s = is_scalar($v) ? (string)$v : '';
  $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
  $s = trim($s);
  return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
}
function crm_line($v, int $max): string { return trim(preg_replace('/\s+/u', ' ', crm_text($v, $max * 2)) ?? '') === '' ? '' : mb_substr(trim(preg_replace('/\s+/u', ' ', crm_text($v, $max * 2))), 0, $max); }
function crm_slug($v, int $max = 40): string { return substr(preg_replace('/[^a-z0-9._-]+/', '', strtolower(is_scalar($v) ? (string)$v : '')) ?? '', 0, $max); }
function crm_email_norm(string $e): string { return strtolower(trim($e)); }
function crm_valid_email(string $e): bool { return strlen($e) <= 190 && filter_var($e, FILTER_VALIDATE_EMAIL) !== false && !preg_match('/[\r\n]/', $e); }
/** Registrable-looking domain of a URL or host ("https://www.Example.com/x" → "example.com"); '' when none. */
function crm_domain(string $urlOrHost): string {
  $h = trim(strtolower($urlOrHost));
  if ($h === '') return '';
  if (strpos($h, '//') === false) $h = '//' . $h;
  $host = (string)parse_url($h, PHP_URL_HOST);
  $host = preg_replace('/^www\./', '', $host) ?? '';
  return preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $host) ? substr($host, 0, 190) : '';
}
/** Domains of free email providers must not be used to match companies. */
function crm_is_free_mail_domain(string $d): bool { return in_array($d, ['gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'yahoo.com', 'icloud.com', 'proton.me', 'protonmail.com', 'gmx.com', 'gmx.de', 'aol.com', 'live.com', 'mail.com', 'yandex.com', 'qq.com'], true); }
function crm_mask_email(string $e): string { $p = explode('@', $e, 2); return count($p) === 2 ? substr($p[0], 0, 1) . '***@' . $p[1] : '***'; }
/** Removes anything that looks like an email address so audit summaries never hold personal data. */
function crm_scrub(string $s): string { return preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[email]', $s) ?? ''; }

// ---- database ------------------------------------------------------------------------------------------------------------------------
function crm_tables_ready(): bool {
  try { return db_connection_available() && db_table_exists('crm_users') && db_table_exists('crm_leads') && db_table_exists('crm_opportunities'); } catch (Throwable $e) { return false; }
}
function crm_pdo(): PDO { $p = db(); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); return $p; }

// ---- users ---------------------------------------------------------------------------------------------------------------------------
function crm_user_create(PDO $pdo, string $username, string $display, string $role, string $hash): array {
  $username = crm_slug($username, 40); $display = crm_line($display, 80);
  if (strlen($username) < 3) return [false, 'username_invalid'];
  if ($display === '') return [false, 'display_required'];
  if (!isset(crm_roles()[$role])) return [false, 'role_invalid'];
  if (empty(password_get_info($hash)['algo'])) return [false, 'hash_invalid'];
  try {
    $pdo->prepare('INSERT INTO crm_users (username, display_name, role, password_hash, active, created_at) VALUES (?,?,?,?,1,?)')->execute([$username, $display, $role, $hash, crm_now()]);
  } catch (PDOException $e) { return [false, 'username_taken']; }
  return [true, (string)$pdo->lastInsertId()];
}
function crm_user_get(PDO $pdo, int $id): ?array { $s = $pdo->prepare('SELECT id, username, display_name, role, active FROM crm_users WHERE id = ?'); $s->execute([$id]); $r = $s->fetch(PDO::FETCH_ASSOC); return $r ?: null; }
function crm_users_list(PDO $pdo): array { return $pdo->query('SELECT id, username, display_name, role, active FROM crm_users WHERE active = 1 ORDER BY display_name')->fetchAll(PDO::FETCH_ASSOC); }

function crm_session_start(): void {
  if (session_status() === PHP_SESSION_ACTIVE) return;
  ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1');
  session_name('aihub_crm');
  session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => sec_is_https(), 'httponly' => true, 'samesite' => 'Strict']);
  @session_start();
}
/** @return array{0:string,1:?array} [code, user]  code: ok | disabled | rate_limited | invalid */
function crm_login(PDO $pdo, string $username, string $password): array {
  if (sec_rate_limited('crm_login', 5, 900)) { sec_log('crm_login_rate_limited'); return ['rate_limited', null]; }
  $s = $pdo->prepare('SELECT id, username, display_name, role, password_hash, active FROM crm_users WHERE username = ?'); $s->execute([crm_slug($username, 40)]);
  $u = $s->fetch(PDO::FETCH_ASSOC);
  // Same work for unknown users so timing does not reveal which usernames exist.
  $hash = $u['password_hash'] ?? '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ012345';
  $okPw = password_verify($password, $hash);
  if (!$u || !$okPw || !(int)$u['active']) { sec_log('crm_login_failed'); return ['invalid', null]; }
  crm_session_start(); session_regenerate_id(true);
  $_SESSION['crm_uid'] = (int)$u['id']; $_SESSION['crm_login'] = $_SESSION['crm_at'] = time(); $_SESSION['crm_csrf'] = bin2hex(random_bytes(16));
  $pdo->prepare('UPDATE crm_users SET last_login_at = ? WHERE id = ?')->execute([crm_now(), $u['id']]);
  crm_audit($pdo, ['id' => (int)$u['id'], 'username' => $u['username']], 'login', 'user', (int)$u['id'], 'signed in');
  unset($u['password_hash']);
  return ['ok', $u];
}
function crm_logout(): void {
  crm_session_start(); $_SESSION = [];
  if (ini_get('session.use_cookies')) { $p = session_get_cookie_params(); setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Strict']); }
  @session_destroy();
}
/** Current signed-in, still-active user (re-read from the database on every request so role changes and deactivation apply at once). */
function crm_current_user(PDO $pdo, ?int $now = null): ?array {
  crm_session_start(); $now = $now ?? time();
  if (empty($_SESSION['crm_uid']) || empty($_SESSION['crm_at']) || empty($_SESSION['crm_login'])) return null;
  if ($now - (int)$_SESSION['crm_at'] > CRM_IDLE_SECONDS || $now - (int)$_SESSION['crm_login'] > CRM_MAX_SECONDS) { crm_logout(); return null; }
  $u = crm_user_get($pdo, (int)$_SESSION['crm_uid']);
  if (!$u || !(int)$u['active']) { crm_logout(); return null; }
  $_SESSION['crm_at'] = $now;
  return $u;
}
function crm_csrf_token(): string { crm_session_start(); if (empty($_SESSION['crm_csrf'])) $_SESSION['crm_csrf'] = bin2hex(random_bytes(16)); return (string)$_SESSION['crm_csrf']; }
function crm_csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(crm_csrf_token(), ENT_QUOTES) . '">'; }
function crm_csrf_ok(?array $post = null): bool {
  crm_session_start(); $t = (string)(($post ?? $_POST)['_csrf'] ?? '');
  return $t !== '' && !empty($_SESSION['crm_csrf']) && hash_equals((string)$_SESSION['crm_csrf'], $t) && sec_same_origin();
}

// ---- audit ---------------------------------------------------------------------------------------------------------------------------
/** Writes an audit record. The summary is scrubbed of email addresses and truncated; it must describe the action, not hold personal data. */
function crm_audit(PDO $pdo, ?array $user, string $action, string $entity = '', ?int $entityId = null, string $summary = ''): void {
  try {
    $pdo->prepare('INSERT INTO crm_audit_log (at, user_id, username, action, entity_type, entity_id, summary) VALUES (?,?,?,?,?,?,?)')
        ->execute([crm_now(), $user['id'] ?? null, substr((string)($user['username'] ?? 'system'), 0, 40), substr($action, 0, 40), substr($entity, 0, 16), $entityId, mb_substr(crm_scrub($summary), 0, 255)]);
  } catch (Throwable $e) { sec_log_exception($e, 'crm_audit'); }
}
function crm_audit_recent(PDO $pdo, int $limit = 50): array { $s = $pdo->prepare('SELECT at, username, action, entity_type, entity_id, summary FROM crm_audit_log ORDER BY id DESC LIMIT ' . max(1, min(500, $limit))); $s->execute(); return $s->fetchAll(PDO::FETCH_ASSOC); }

// ---- activities and tasks ------------------------------------------------------------------------------------------------------------
function crm_entity_types(): array { return ['lead', 'opportunity', 'company', 'contact', 'campaign', 'partner', 'prospect', 'proposal', 'contract']; }
function crm_activity_add(PDO $pdo, ?array $user, string $type, int $id, string $kind, string $body): bool {
  $body = crm_text($body, 4000);
  if (!in_array($type, crm_entity_types(), true) || $body === '' || !in_array($kind, ['note', 'call', 'email_draft', 'meeting', 'system'], true)) return false;
  $pdo->prepare('INSERT INTO crm_activities (entity_type, entity_id, kind, body, user_id, created_at) VALUES (?,?,?,?,?,?)')->execute([$type, $id, $kind, $body, $user['id'] ?? null, crm_now()]);
  return true;
}
function crm_activities(PDO $pdo, string $type, int $id, int $limit = 100): array {
  $s = $pdo->prepare('SELECT a.kind, a.body, a.created_at, u.display_name AS who FROM crm_activities a LEFT JOIN crm_users u ON u.id = a.user_id WHERE a.entity_type = ? AND a.entity_id = ? ORDER BY a.id DESC LIMIT ' . max(1, min(500, $limit)));
  $s->execute([$type, $id]); return $s->fetchAll(PDO::FETCH_ASSOC);
}
function crm_task_add(PDO $pdo, ?array $user, string $type, int $id, string $title, string $dueAt, ?int $assignee = null): array {
  $title = crm_line($title, 190); $ts = crm_date($dueAt, true);
  if (!in_array($type, crm_entity_types(), true)) return [false, 'entity_invalid'];
  if ($title === '') return [false, 'title_required'];
  if (!$ts) return [false, 'due_invalid'];
  if ($assignee !== null && !crm_user_get($pdo, $assignee)) return [false, 'assignee_invalid'];
  $pdo->prepare('INSERT INTO crm_tasks (entity_type, entity_id, title, due_at, assignee_id, created_by, created_at) VALUES (?,?,?,?,?,?,?)')->execute([$type, $id, $title, crm_now($ts), $assignee, $user['id'] ?? null, crm_now()]);
  return [true, (string)$pdo->lastInsertId()];
}
function crm_task_done(PDO $pdo, int $taskId): bool { $s = $pdo->prepare('UPDATE crm_tasks SET done_at = ? WHERE id = ? AND done_at IS NULL'); $s->execute([crm_now(), $taskId]); return $s->rowCount() === 1; }
function crm_tasks_open(PDO $pdo, ?int $assignee = null, int $limit = 100): array {
  $sql = 'SELECT t.*, u.display_name AS assignee FROM crm_tasks t LEFT JOIN crm_users u ON u.id = t.assignee_id WHERE t.done_at IS NULL' . ($assignee ? ' AND t.assignee_id = ' . (int)$assignee : '') . ' ORDER BY t.due_at ASC LIMIT ' . max(1, min(500, $limit));
  return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

// ---- money (integer minor units; no floating point is used for amounts) --------------------------------------------------------------
/** "1234.56" / "1 234,56" / "1234" → minor units (123456), or null when not a valid non-negative amount with at most 2 decimals. */
function crm_money_parse(string $s): ?int {
  $s = trim(str_replace(["\xc2\xa0", ' ', "'"], '', $s));
  if ($s === '' || !preg_match('/^\d{1,12}([.,]\d{1,2})?$/', $s)) return null;
  $s = str_replace(',', '.', $s); [$w, $f] = array_pad(explode('.', $s, 2), 2, '');
  return (int)$w * 100 + (int)str_pad($f, 2, '0');
}
function crm_money_format(int $minor, string $currency): string {
  $neg = $minor < 0; $a = abs($minor);
  return ($neg ? '-' : '') . number_format(intdiv($a, 100), 0, '.', ',') . '.' . str_pad((string)($a % 100), 2, '0', STR_PAD_LEFT) . ' ' . $currency;
}
function crm_currency_ok(string $c): bool { return in_array($c, crm_currencies(), true); }
/** Basis points (1900 = 19.00%) from "19" or "19.5"; null if outside 0–100%. */
function crm_percent_to_bp(string $s): ?int {
  $s = trim(str_replace(',', '.', $s)); if ($s === '') return 0;
  if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $s)) return null;
  [$w, $f] = array_pad(explode('.', $s, 2), 2, ''); $bp = (int)$w * 100 + (int)str_pad($f, 2, '0');
  return $bp <= 10000 ? $bp : null;
}
