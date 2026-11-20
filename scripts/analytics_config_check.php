<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * What would the WEB SERVER see? (CLI, read-only, prints no secret.) Unlike a plain `php -r`, this check IGNORES the three Analytics environment variables of the calling shell
 * (PHP-FPM workers do not inherit a shell's environment), so a PASS means the private config file alone makes the web application behave correctly.
 *   php scripts/analytics_config_check.php [--config=/path/config.local.php] [--expect-db=aihub_phase6_test] [--expect-env=staging]
 *   sudo -u www-data php scripts/analytics_config_check.php ...      run as the web user to prove file permissions too
 * Exit 0 = all PASS, 1 = at least one FAIL.
 */
foreach (['AIHUB_ENV_LABEL', 'AIHUB_ANALYTICS', 'AIHUB_ANALYTICS_SALT', 'AIHUB_ANALYTICS_HASH_WINDOW'] as $k) { putenv($k); unset($_ENV[$k], $_SERVER[$k]); }
function cc_arg(string $n): ?string { foreach ($GLOBALS['argv'] as $a) if (strpos($a, "--$n=") === 0) return substr($a, strlen($n) + 3); return null; }
if (($c = cc_arg('config')) !== null) putenv('AIHUB_CONFIG_FILE=' . $c);
ob_start(); require_once __DIR__ . '/../helpers.php'; require_once __DIR__ . '/../adm_nav.php'; ob_end_clean();
$fail = 0; $say = function (bool $ok, string $name, string $detail = '') use (&$fail) { if (!$ok) $fail++; printf("%-5s %-52s %s\n", $ok ? 'PASS' : 'FAIL', $name, $detail); };
$file = getenv('AIHUB_CONFIG_FILE') ?: dirname(__DIR__) . '/config.local.php'; $user = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : '?';
echo "running as: $user | PHP " . PHP_VERSION . " | config file: $file\n";
$say(is_file($file), 'config file exists'); $say(is_readable($file), 'config file readable by this user', is_file($file) ? sprintf('mode %04o', fileperms($file) & 0777) : '');
$cfg = sec_local_config(); $say(isset($cfg['db']) && is_array($cfg['db']), 'config has a db array'); $dbn = (string)($cfg['db']['name'] ?? ''); $say($dbn !== '', 'database name configured', $dbn); if (($e = cc_arg('expect-db')) !== null) $say($dbn === $e, "database is $e", "actual: $dbn"); $say($dbn !== 'aihub', "this is not the production database 'aihub'");
$stray = []; foreach (($cfg['db'] ?? []) as $k => $v) if (is_string($k) && preg_match('/analytics|env_?label/i', $k)) $stray[] = "db.$k"; foreach ($cfg as $k => $v) if (is_string($k) && preg_match('/^AIHUB_|^ANALYTICS|^ENV_LABEL/', $k)) $stray[] = $k; $say(!$stray, 'no misplaced or upper-case settings keys', implode(', ', $stray));
$env = adm_env(); $say($env['key'] !== 'unset', 'environment label is set (banner)', $env['label']); if (($e = cc_arg('expect-env')) !== null) $say($env['key'] === $e, "environment is $e", $env['key']);
$say(analytics_enabled(), 'analytics_enabled() is true from the config file alone'); $say(analytics_salt_ok(), 'analytics salt present and strong (value not shown)', 'length ' . strlen(sec_setting('AIHUB_ANALYTICS_SALT', 'analytics_salt')));
try { $pdo = db(); $cur = (string)$pdo->query('SELECT DATABASE()')->fetchColumn(); $say(true, 'database connection works as this user', $cur); $say($dbn === $cur, 'connected database equals the configured name'); foreach (['page_views', 'events', 'tool_clicks', 'crm_users'] as $t) $say((bool)db_table_exists($t), "table $t visible", ''); $say(tool_clicks_writable(), 'outbound clicks can be written (tool_clicks_writable, independent of tool_votes)'); $say(function_exists('analytics_device_column_ready') ? true : false, 'recorder code loaded'); }
catch (Throwable $e) { $say(false, 'database connection', get_class($e)); }
$px = sec_trusted_proxies(); printf("%-5s %-52s %s\n", 'INFO', 'trusted proxies configured (AIHUB_TRUSTED_PROXIES / trusted_proxies)', count($px) . ($px ? '' : ' (if the site sits behind nginx, a load balancer or Cloudflare, every visitor may share the proxy\'s address, so daily visitor counts would be too low: configure it)'));
echo "\n" . ($fail ? "$fail FAIL" : 'ALL PASS') . "\n"; exit($fail ? 1 : 0);
