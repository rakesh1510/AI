<?php
if (isset($_SERVER["SCRIPT_FILENAME"]) && realpath($_SERVER["SCRIPT_FILENAME"]) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
// Unified Control Center, milestone U5: Users and roles (READ ONLY), Audit log viewer (READ ONLY) and System health (read only). Owner only (permission 'access').
// Users are still created, deactivated and given roles from the command line (scripts/crm_user.php), never from the web: this page changes nothing and never reads or shows password hashes.

interface AdmAccessStore {
  /** Rows: id, username, display_name, role, active (0/1), created_at, last_login_at (or ''). Never a password hash. */
  public function users(): array;
  /** Newest first. $f: user, action (prefix), from, to (YYYY-MM-DD). Returns ['rows' => [at, username, action, entity_type, entity_id, summary], 'total' => int]. */
  public function audit(array $f, int $page, int $per): array;
}
final class AdmAccessMemoryStore implements AdmAccessStore {
  public function __construct(private array $users = [], private array $log = []) {}
  public function users(): array { return $this->users; }
  public function audit(array $f, int $page, int $per): array {
    $rows = array_values(array_filter(array_reverse($this->log), function ($r) use ($f) {
      if ($f['user'] !== '' && $r['username'] !== $f['user']) return false; if ($f['action'] !== '' && strpos($r['action'], $f['action']) !== 0) return false;
      $d = substr((string)$r['at'], 0, 10); return ($f['from'] === '' || $d >= $f['from']) && ($f['to'] === '' || $d <= $f['to']);
    }));
    return ['rows' => array_slice($rows, max(0, ($page - 1) * $per), $per), 'total' => count($rows)];
  }
}
final class AdmAccessPdoStore implements AdmAccessStore {
  public function __construct(private PDO $pdo) {}
  public function users(): array { return $this->pdo->query('SELECT id, username, display_name, role, active, created_at, COALESCE(last_login_at, \'\') AS last_login_at FROM crm_users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC); }
  public function audit(array $f, int $page, int $per): array {
    $w = []; $p = [];
    if ($f['user'] !== '') { $w[] = 'username = ?'; $p[] = $f['user']; }
    if ($f['action'] !== '') { $w[] = 'action LIKE ?'; $p[] = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['action']) . '%'; }
    if ($f['from'] !== '') { $w[] = 'at >= ?'; $p[] = $f['from'] . ' 00:00:00'; }
    if ($f['to'] !== '') { $w[] = 'at <= ?'; $p[] = $f['to'] . ' 23:59:59'; }
    $where = $w ? ' WHERE ' . implode(' AND ', $w) : '';
    $c = $this->pdo->prepare('SELECT COUNT(*) FROM crm_audit_log' . $where); $c->execute($p); $total = (int)$c->fetchColumn();
    $q = $this->pdo->prepare('SELECT at, username, action, entity_type, entity_id, summary FROM crm_audit_log' . $where . ' ORDER BY id DESC LIMIT ' . (int)$per . ' OFFSET ' . (int)max(0, ($page - 1) * $per)); $q->execute($p);
    return ['rows' => $q->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
  }
}

function admx_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function admx_url(array $q = []): string { return '/admin-center.php?' . http_build_query(['m' => 'access'] + $q); }
function admx_views(): array { return ['users' => 'Users and roles', 'audit' => 'Audit log', 'health' => 'System health']; }
function admx_view(array $get): string { $v = $get['view'] ?? 'users'; return is_string($v) && isset(admx_views()[$v]) ? $v : 'users'; }

/** Audit filters from the request: bounded, whitelisted characters, real dates only. */
function admx_filters(array $get): array {
  $t = fn($k, $n) => is_string($get[$k] ?? null) ? substr(preg_replace('/[^A-Za-z0-9_.-]/', '', $get[$k]), 0, $n) : '';
  $d = function ($k) use ($get) { $v = is_string($get[$k] ?? null) ? trim($get[$k]) : ''; if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return ''; [$y, $m, $dd] = array_map('intval', explode('-', $v)); return checkdate($m, $dd, $y) ? $v : ''; };
  $page = is_string($get['page'] ?? null) && ctype_digit($get['page']) && strlen($get['page']) <= 5 ? max(1, (int)$get['page']) : 1;
  return ['user' => $t('user', 40), 'action' => $t('action', 40), 'from' => $d('from'), 'to' => $d('to'), 'page' => $page];
}

function admx_table(string $caption, array $head, array $rows): string {
  if (!$rows) return '<p class="small">Nothing to show.</p>';
  $o = '<div class="tableWrap" tabindex="0" role="region" aria-label="' . admx_h($caption) . ' (scrollable)"><table class="table"><caption class="sr">' . admx_h($caption) . '</caption><thead><tr>'; foreach ($head as $h) $o .= '<th scope="col">' . admx_h($h) . '</th>'; $o .= '</tr></thead><tbody>';
  foreach ($rows as $r) { $o .= '<tr>'; foreach ($r as $c) $o .= '<td>' . $c . '</td>'; $o .= '</tr>'; } return $o . '</tbody></table></div>';
}

function admx_users_view(AdmAccessStore $s): string {
  $rows = []; foreach ($s->users() as $u) $rows[] = [admx_h($u['username']), admx_h($u['display_name']), admx_h($u['role']), $u['active'] ? 'Active' : 'Deactivated', admx_h($u['created_at']), $u['last_login_at'] !== '' ? admx_h($u['last_login_at']) : 'Never'];
  $o = '<h2>Users</h2><p class="small">Read only. Users are created, deactivated and given roles from the command line (<code>php scripts/crm_user.php</code>), never from the web, so a web compromise cannot create an administrator. Passwords are never shown here; each person changes their own on the Account page.</p>' . admx_table('Users', ['User', 'Name', 'Role', 'Status', 'Created', 'Last sign-in'], $rows);
  $o .= '<h2>What each role can open</h2><p class="small">Server-side permissions, the same ones the menu uses. A menu item is never shown to a role that cannot open it.</p>'; $mx = [];
  foreach (['owner', 'sales', 'viewer', 'editor', 'verifier'] as $role) { $mods = []; foreach (adm_modules() as $id => $m) if (adm_can($role, $m['perm'])) $mods[] = admx_h($m['label']); $mx[] = ['<strong>' . admx_h($role) . '</strong>', implode(', ', $mods)]; }
  return $o . admx_table('Roles and modules', ['Role', 'Can open'], $mx);
}

function admx_audit_view(AdmAccessStore $s, array $get): string {
  $f = admx_filters($get); $per = 50; $r = $s->audit($f, $f['page'], $per); $pages = max(1, (int)ceil($r['total'] / $per)); $q = fn(array $x) => admx_url(['view' => 'audit'] + array_filter($x, fn($v) => $v !== '' && $v !== 1));
  $o = '<h2>Audit log</h2><p class="small">Who did what, newest first. Read only; entries cannot be edited or deleted here. Summaries never contain email addresses or credentials.</p>'
    . '<form method="get" action="/admin-center.php" class="crmForm"><input type="hidden" name="m" value="access"><input type="hidden" name="view" value="audit">'
    . '<div class="field"><label for="au_user">User</label><input class="input" id="au_user" name="user" value="' . admx_h($f['user']) . '" maxlength="40"></div><div class="field"><label for="au_act">Action starts with</label><input class="input" id="au_act" name="action" value="' . admx_h($f['action']) . '" maxlength="40"></div>'
    . '<div class="field"><label for="au_from">From (YYYY-MM-DD)</label><input class="input" id="au_from" name="from" value="' . admx_h($f['from']) . '" maxlength="10" placeholder="2026-11-01"></div><div class="field"><label for="au_to">To (YYYY-MM-DD)</label><input class="input" id="au_to" name="to" value="' . admx_h($f['to']) . '" maxlength="10"></div><button class="btn sm">Filter</button></form>';
  $rows = []; foreach ($r['rows'] as $a) $rows[] = [admx_h($a['at']), admx_h($a['username']), admx_h($a['action']), admx_h($a['entity_type'] . ($a['entity_id'] ? ' #' . $a['entity_id'] : '')), admx_h($a['summary'])];
  $o .= '<p class="small">' . (int)$r['total'] . ' matching entries, page ' . (int)$f['page'] . ' of ' . $pages . '.</p>' . admx_table('Audit log', ['When (UTC)', 'User', 'Action', 'Entity', 'Summary'], $rows);
  $nav = ''; if ($f['page'] > 1) $nav .= '<a class="btn sm secondary" href="' . admx_h($q(['user' => $f['user'], 'action' => $f['action'], 'from' => $f['from'], 'to' => $f['to'], 'page' => $f['page'] - 1 ?: ''])) . '">Newer</a> ';
  if ($f['page'] < $pages) $nav .= '<a class="btn sm secondary" href="' . admx_h($q(['user' => $f['user'], 'action' => $f['action'], 'from' => $f['from'], 'to' => $f['to'], 'page' => $f['page'] + 1])) . '">Older</a>';
  return $o . ($nav !== '' ? '<p>' . $nav . '</p>' : '');
}

function admx_health_view(array $health): string {
  $rows = []; foreach ($health as $h) $rows[] = [admx_h($h[0]), admx_h($h[1]), $h[2] === true ? 'OK' : ($h[2] === false ? 'Attention' : 'Information')];
  return '<h2>System health</h2><p class="small">Read only checks of this server, with no secrets shown. Backup status, error monitoring and release history arrive with Phase 8F.</p>' . admx_table('System health', ['Check', 'Result', 'State'], $rows);
}

function admx_body(AdmAccessStore $s, array $health, array $get): string {
  $view = admx_view($get); $nav = '<nav aria-label="Users, roles and audit sections" class="crmNav">';
  foreach (admx_views() as $k => $l) $nav .= '<a class="btn sm' . ($k === $view ? '' : ' secondary') . '" href="' . admx_h(admx_url(['view' => $k])) . '"' . ($k === $view ? ' aria-current="page"' : '') . '>' . admx_h($l) . '</a>';
  $inner = $view === 'audit' ? admx_audit_view($s, $get) : ($view === 'health' ? admx_health_view($health) : admx_users_view($s));
  return '<div class="admCrm admAcc"><h1>Users, roles and audit</h1><p class="small">Owner only. Nothing on these pages changes data.</p>' . $nav . $inner . '</div>';
}
