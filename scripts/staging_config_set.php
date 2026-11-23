<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * STAGING private-configuration setter (CLI, run by the server administrator; the web never runs it).
 * Puts the three settings the Analytics pipeline needs where the application reads them: TOP-LEVEL keys of config.local.php (the private file loaded by security.php::sec_local_config()):
 *     env_label      'staging'     (banner and Control Center)
 *     analytics      '1'           (tracking switch; read by analytics_enabled())
 *     analytics_salt <random>      (32+ characters; read by analytics_salt_ok() and the hashing)
 * It also REPAIRS the earlier mistake of putting these inside the 'db' array or under upper-case names (they are moved to the right place; every other key, including the database credentials, is kept byte-for-byte).
 * Safety: DRY RUN unless --apply. Refuses unless config db.name equals --staging-db (default aihub_phase6_test) and that name is not the production name 'aihub'. Never prints a secret (only key names, lengths and
 * "set/unchanged/generated"). Makes a backup copy first, writes atomically, keeps the file's owner, group and mode (never widens permissions), and validates the result.
 *
 *   php scripts/staging_config_set.php --config=/var/www/html/AI-PHASE3-TEST/config.local.php            dry run
 *   php scripts/staging_config_set.php --config=... --apply                                               apply
 * Rollback: copy the printed backup file over config.local.php (or run with --disable to switch tracking off: sets analytics to '0').
 * Exit codes: 0 ok, 2 refused.
 */
function sc_arg(string $n): ?string { foreach ($GLOBALS['argv'] as $a) if (strpos($a, "--$n=") === 0) return substr($a, strlen($n) + 3); return null; }
function sc_has(string $n): bool { return in_array("--$n", $GLOBALS['argv'], true); }
$file = sc_arg('config') ?? dirname(__DIR__) . '/config.local.php'; $stg = sc_arg('staging-db') ?? 'aihub_phase6_test'; $apply = sc_has('apply'); $disable = sc_has('disable');
$refuse = function (string $why) { fwrite(STDERR, "refused: $why\n"); exit(2); };
if ($stg === 'aihub' || $stg === '') $refuse("--staging-db must be the staging database and can never be the production database 'aihub'");
if (!is_file($file) || !is_readable($file)) $refuse("config file not found or not readable: $file");
$cfg = (static function ($f) { return include $f; })($file); if (!is_array($cfg)) $refuse('config file does not return an array');
$db = isset($cfg['db']) && is_array($cfg['db']) ? $cfg['db'] : []; $name = (string)($db['name'] ?? $db['NAME'] ?? '');
if ($name !== $stg) $refuse("the configured database is '" . ($name === '' ? '(none)' : $name) . "', not '$stg': this tool only configures the staging site");
$map = ['AIHUB_ENV_LABEL' => 'env_label', 'ENV_LABEL' => 'env_label', 'env_label' => 'env_label', 'AIHUB_ANALYTICS' => 'analytics', 'ANALYTICS' => 'analytics', 'analytics' => 'analytics', 'AIHUB_ANALYTICS_SALT' => 'analytics_salt', 'ANALYTICS_SALT' => 'analytics_salt', 'analytics_salt' => 'analytics_salt', 'AIHUB_ANALYTICS_HASH_WINDOW' => 'analytics_hash_window', 'analytics_hash_window' => 'analytics_hash_window'];
$moved = []; $found = [];
foreach (['db', null] as $where) { $arr = $where === 'db' ? ($cfg['db'] ?? []) : $cfg; foreach ($arr as $k => $v) { if (!is_string($k) || !isset($map[$k])) continue; if ($where === null && $k === $map[$k]) { $found[$map[$k]] = $v; continue; } $found[$map[$k]] = $found[$map[$k]] ?? $v; $moved[] = ($where === 'db' ? 'db.' : '') . $k . ' -> ' . $map[$k]; if ($where === 'db') unset($cfg['db'][$k]); else unset($cfg[$k]); } }
$new = $cfg; $report = [];
$new['env_label'] = 'staging'; $report[] = 'env_label = staging';
$new['analytics'] = $disable ? '0' : '1'; $report[] = 'analytics = ' . ($disable ? '0 (tracking OFF)' : '1 (tracking ON, staging only)');
$salt = isset($found['analytics_salt']) && is_scalar($found['analytics_salt']) ? (string)$found['analytics_salt'] : '';
if (strlen($salt) >= 32 && stripos($salt, 'default') === false) $report[] = 'analytics_salt = unchanged (existing value kept, length ' . strlen($salt) . ')'; else { $salt = bin2hex(random_bytes(32)); $report[] = 'analytics_salt = generated (64 random hex characters; not shown)'; }
$new['analytics_salt'] = $salt; if (isset($found['analytics_hash_window'])) $new['analytics_hash_window'] = (string)$found['analytics_hash_window'];
$changed = $new !== (static function ($f) { return include $f; })($file);
echo ($apply ? 'APPLY' : 'DRY RUN') . " for $file (database '$name')\n"; foreach ($report as $r) echo "  - $r\n"; foreach ($moved as $m) echo "  - repaired misplaced key: $m\n"; echo '  - other keys preserved: ' . implode(', ', array_map(fn($k) => (string)$k, array_keys(array_diff_key($new, array_flip(['env_label', 'analytics', 'analytics_salt', 'analytics_hash_window']))))) . " (values not shown)\n";
if (!$changed) echo "  - nothing to change: the file already has the correct settings\n";
if (!$apply) { echo "No change was made. Re-run with --apply.\n"; exit(0); }
if (!$changed) exit(0);
$mode = fileperms($file) & 0777; $owner = fileowner($file); $group = filegroup($file); $backup = $file . '.bak-' . gmdate('Ymd\THis\Z');
if (!copy($file, $backup)) $refuse('cannot write the backup copy next to the config file'); chmod($backup, $mode); @chown($backup, $owner); @chgrp($backup, $group);
$php = "<?php\n// Private configuration (NOT in Git). Settings are TOP-LEVEL keys; database credentials live under 'db'. Edited by scripts/staging_config_set.php.\nreturn " . var_export($new, true) . ";\n";
$tmp = $file . '.tmp' . getmypid(); if (file_put_contents($tmp, $php, LOCK_EX) === false) $refuse('cannot write the temporary file'); chmod($tmp, $mode); @chown($tmp, $owner); @chgrp($tmp, $group);
$check = (static function ($f) { return include $f; })($tmp); if (!is_array($check) || ($check['db']['name'] ?? '') !== $stg || ($check['env_label'] ?? '') !== 'staging') { @unlink($tmp); $refuse('validation of the new file failed; nothing was changed'); }
if (!rename($tmp, $file)) { @unlink($tmp); $refuse('cannot replace the config file'); }
if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
printf("Written. owner=%s group=%s mode=%04o (unchanged). Backup: %s\nNext: reload PHP-FPM (for example: systemctl reload php*-fpm), then run: php scripts/analytics_config_check.php --config=%s\n", (function_exists('posix_getpwuid') ? (posix_getpwuid($owner)['name'] ?? $owner) : $owner), (function_exists('posix_getgrgid') ? (posix_getgrgid($group)['name'] ?? $group) : $group), $mode, $backup, $file);
