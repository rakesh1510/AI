<?php
/**
 * Create or deactivate CRM users (CLI only). There are no default accounts and users cannot be created from the web.
 *   php scripts/crm_user.php add <username> "<Display Name>" <owner|sales|viewer>     (the password is read from standard input, never from arguments)
 *   php scripts/crm_user.php deactivate <username>
 *   php scripts/crm_user.php list
 * Example:  read -s -p "Password: " PW; echo; printf '%s' "$PW" | php scripts/crm_user.php add olivia "Olivia Owner" owner
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../crm_core.php';
if (!crm_tables_ready()) { fwrite(STDERR, "CRM tables are missing. Apply database/phase6_crm.sql to a reviewed database first.\n"); exit(2); }
$pdo = crm_pdo(); $cmd = $argv[1] ?? '';
if ($cmd === 'add') {
  [$user, $name, $role] = [$argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? ''];
  $pw = rtrim((string)stream_get_contents(STDIN), "\r\n");
  if (mb_strlen($pw) < 12) { fwrite(STDERR, "The password (standard input) must be at least 12 characters.\n"); exit(2); }
  [$ok, $res] = crm_user_create($pdo, $user, $name, $role, password_hash($pw, PASSWORD_DEFAULT));
  if (!$ok) { fwrite(STDERR, "Not created: $res\n"); exit(1); }
  crm_audit($pdo, null, 'user_create', 'user', (int)$res, "role $role (created from the command line)"); echo "Created user '" . crm_slug($user, 40) . "' with role $role.\n"; exit(0);
}
if ($cmd === 'deactivate') { $s = $pdo->prepare('UPDATE crm_users SET active = 0 WHERE username = ?'); $s->execute([crm_slug($argv[2] ?? '', 40)]); crm_audit($pdo, null, 'user_deactivate', 'user', null, 'deactivated from the command line'); echo $s->rowCount() ? "Deactivated.\n" : "No such user.\n"; exit(0); }
if ($cmd === 'list') { foreach (crm_users_list($pdo) as $u) echo $u['username'] . "\t" . $u['role'] . "\t" . $u['display_name'] . "\n"; exit(0); }
fwrite(STDERR, "Usage: add <username> \"<Display Name>\" <owner|sales|viewer> (password on stdin) | deactivate <username> | list\n"); exit(2);
