<?php
// Analytics configuration pipeline, DATABASE-FREE: the private config file alone must switch tracking on and set the environment label for a web worker with NO inherited environment;
// the staging config tool repairs the earlier misplaced keys safely (dry run by default, refuses production, never prints secrets, keeps permissions, backs up, idempotent).
$root = dirname(__DIR__); $pass = 0; $fail = 0; function ok($c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; fwrite(STDERR, "  FAIL: $m\n"); } }
$tmp = sys_get_temp_dir() . '/aihub_cfg_test_' . getmypid(); @mkdir($tmp, 0700, true);
$probe = function (string $cfgFile, array $env = []) use ($root) { $code = 'ob_start(); require ' . var_export($root . '/helpers.php', true) . '; require ' . var_export($root . '/adm_nav.php', true) . '; ob_end_clean(); echo json_encode(["on" => analytics_enabled(), "salt" => analytics_salt_ok(), "env" => adm_env()["key"], "label" => adm_env()["label"], "win" => sec_setting("AIHUB_ANALYTICS_HASH_WINDOW", "analytics_hash_window")]);';
  $e = 'env -i PATH=' . escapeshellarg(getenv('PATH')) . ' ' . ($cfgFile !== '' ? 'AIHUB_CONFIG_FILE=' . escapeshellarg($cfgFile) . ' ' : ''); foreach ($env as $k => $v) $e .= $k . '=' . escapeshellarg($v) . ' '; return json_decode((string)shell_exec($e . 'php -d display_errors=0 -r ' . escapeshellarg($code) . ' 2>/dev/null'), true) ?: []; };
$write = function (string $name, array $cfg, int $mode = 0640) use ($tmp) { $f = "$tmp/$name"; file_put_contents($f, "<?php\nreturn " . var_export($cfg, true) . ";\n"); chmod($f, $mode); return $f; };
$good = ['db' => ['host' => 'localhost', 'name' => 'aihub_phase6_test', 'user' => 'stg_user', 'pass' => 'PW-SECRET-do-not-print'], 'env_label' => 'staging', 'analytics' => '1', 'analytics_salt' => str_repeat('k', 40)];
// ---- 1. the web worker sees only the config file (empty environment)
$r = $probe($write('good.php', $good)); ok($r['on'] === true && $r['salt'] === true && $r['env'] === 'staging' && $r['label'] === 'STAGING', 'with an EMPTY environment, config.local.php alone enables tracking, validates the salt and shows STAGING');
$r = $probe($write('none.php', ['db' => $good['db']])); ok($r['on'] === false && $r['salt'] === false && $r['env'] === 'unset', 'no settings in the file: tracking OFF (the safe default) and the banner says the environment is not set');
$r = $probe($write('misplaced.php', ['db' => $good['db'] + ['AIHUB_ANALYTICS' => '1', 'analytics' => '1', 'env_label' => 'staging']])); ok($r['on'] === false && $r['env'] === 'unset', 'REPRODUCES the staging fault: settings placed inside the db array (or upper-case) are ignored');
$r = $probe($write('upper.php', ['db' => $good['db'], 'AIHUB_ANALYTICS' => '1', 'AIHUB_ENV_LABEL' => 'staging'])); ok($r['on'] === false && $r['env'] === 'unset', 'REPRODUCES the staging fault: upper-case top-level keys are ignored');
foreach (['0', '', 'no', 'yes', 'on', 'enabled', '2'] as $v) { $r = $probe($write('v.php', ['analytics' => $v, 'analytics_salt' => str_repeat('k', 40)])); ok($r['on'] === false, "analytics value '$v' is OFF (only 1 or true enable)"); }
foreach (['1', 'true', 'TRUE'] as $v) { $r = $probe($write('v.php', ['analytics' => $v])); ok($r['on'] === true, "analytics value '$v' is ON"); }
$r = $probe($write('v.php', ['analytics' => 1])); ok($r['on'] === true, 'an integer 1 in the file is ON');
$r = $probe($write('short.php', ['analytics' => '1', 'analytics_salt' => 'short'])); ok($r['on'] === true && $r['salt'] === false, 'a weak salt is rejected (tracking on, but no visitor hash will be written)');
$r = $probe($write('dflt.php', ['analytics' => '1', 'analytics_salt' => 'default-' . str_repeat('x', 40)])); ok($r['salt'] === false, 'a salt containing "default" is rejected');
$r = $probe($write('win.php', $good + ['analytics_hash_window' => 'week'])); ok($r['win'] === 'week', 'hash window is configurable from the file');
$r = $probe($write('envwin.php', ['analytics' => '1']), ['AIHUB_ANALYTICS' => '0']); ok($r['on'] === false, 'an explicit environment value still wins (0 switches tracking off even if the file says 1)');
$r = $probe('/nonexistent/config.php'); ok($r['on'] === false && $r['env'] === 'unset', 'a missing config file: everything OFF, no error');
// ---- 2. the staging config tool
$run = function (array $args) use ($root) { $o = []; exec('php ' . escapeshellarg($root . '/scripts/staging_config_set.php') . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $o, $rc); return [$rc, implode("\n", $o)]; };
$bad = ['db' => ['host' => 'localhost', 'name' => 'aihub_phase6_test', 'user' => 'stg_user', 'pass' => 'PW-SECRET-do-not-print', 'AIHUB_ANALYTICS' => '1', 'AIHUB_ANALYTICS_SALT' => 'tooshort', 'env_label' => 'staging'], 'AIHUB_ENV_LABEL' => 'staging', 'site' => ['name' => 'x']];
$f = $write('bad.php', $bad, 0640); $before = file_get_contents($f); [$rc, $o] = $run(["--config=$f"]); ok($rc === 0 && file_get_contents($f) === $before && strpos($o, 'No change was made') !== false, 'dry run changes nothing');
ok(strpos($o, 'PW-SECRET') === false && strpos($o, 'tooshort') === false, 'the dry run prints no secret');
[$rc, $o] = $run(["--config=$f", '--apply']); $new = include $f; ok($rc === 0 && $new['env_label'] === 'staging' && $new['analytics'] === '1' && strlen($new['analytics_salt']) >= 32 && !isset($new['db']['AIHUB_ANALYTICS']) && !isset($new['db']['env_label']) && !isset($new['AIHUB_ENV_LABEL']), 'apply: settings moved to top-level keys, misplaced ones removed, strong salt generated');
ok($new['db']['host'] === 'localhost' && $new['db']['name'] === 'aihub_phase6_test' && $new['db']['user'] === 'stg_user' && $new['db']['pass'] === 'PW-SECRET-do-not-print' && $new['site'] === ['name' => 'x'], 'database credentials and every other key are preserved');
ok(strpos($o, 'PW-SECRET') === false && strpos($o, $new['analytics_salt']) === false && strpos($o, 'tooshort') === false, 'apply prints no secret (no password, no old or new salt)');
ok((fileperms($f) & 0777) === 0640, 'file mode is unchanged (never widened)'); $bk = glob("$f.bak-*"); ok(count($bk) === 1 && file_get_contents($bk[0]) === $before && (fileperms($bk[0]) & 0777) === 0640, 'a backup with the original content and mode was made');
$r = $probe($f); ok($r['on'] === true && $r['salt'] === true && $r['env'] === 'staging', 'the repaired file works for a web worker with an empty environment');
$salt1 = $new['analytics_salt']; [$rc, $o] = $run(["--config=$f", '--apply']); $again = include $f; ok($rc === 0 && strpos($o, 'nothing to change') !== false && $again['analytics_salt'] === $salt1 && count(glob("$f.bak-*")) === 1, 'idempotent: a second run changes nothing, keeps the salt, makes no new backup');
[$rc, $o] = $run(["--config=$f", '--apply', '--disable']); $off = include $f; ok($rc === 0 && $off['analytics'] === '0' && $off['analytics_salt'] === $salt1, 'rollback switch: --disable sets analytics to 0 and keeps everything else');
$r = $probe($f); ok($r['on'] === false, 'after --disable tracking is OFF for the web worker');
// refusals
$prod = $write('prod.php', ['db' => ['name' => 'aihub', 'user' => 'u', 'pass' => 'p']]); [$rc, $o] = $run(["--config=$prod", '--apply']); ok($rc === 2 && strpos($o, 'staging site') !== false && !isset((include $prod)['analytics']), 'a config pointing at the production database name is refused and not modified');
[$rc, $o] = $run(["--config=$prod", '--staging-db=aihub', '--apply']); ok($rc === 2 && strpos($o, "never be the production database") !== false, '--staging-db=aihub is refused');
$other = $write('other.php', ['db' => ['name' => 'something_else']]); [$rc, $o] = $run(["--config=$other", '--apply']); ok($rc === 2, 'a config for any other database name is refused');
[$rc, $o] = $run(['--config=' . $tmp . '/missing.php']); ok($rc === 2, 'a missing file is refused');
// ---- 3. the config check script sees what a web worker sees
$f2 = $write('chk.php', $good); exec('env AIHUB_ANALYTICS=1 AIHUB_ENV_LABEL=production AIHUB_ANALYTICS_SALT=' . str_repeat('z', 40) . ' php ' . escapeshellarg($root . '/scripts/analytics_config_check.php') . ' --config=' . escapeshellarg($f2) . ' --expect-env=staging --expect-db=aihub_phase6_test 2>&1', $o2, $rc2); $t = implode("\n", $o2);
ok(preg_match('/PASS\s+environment is staging/', $t) && preg_match('/PASS\s+analytics_enabled\(\) is true from the config file alone/', $t), 'the check ignores the calling shell\'s environment (a shell with production values still reports the file\'s staging settings)');
ok(strpos($t, 'PW-SECRET') === false && strpos($t, str_repeat('k', 40)) === false && strpos($t, str_repeat('z', 40)) === false, 'the check prints no secret');
$f3 = $write('chk2.php', ['db' => ['host' => '127.0.0.1:1', 'name' => 'aihub_phase6_test', 'user' => 'u', 'pass' => 'PW-SECRET-do-not-print'], 'env_label' => 'staging', 'analytics' => '1', 'analytics_salt' => str_repeat('k', 40)]); exec('php ' . escapeshellarg($root . '/scripts/analytics_config_check.php') . ' --config=' . escapeshellarg($f3) . ' 2>&1', $o3, $rc3); $t3 = implode("\n", $o3);
ok(preg_match('/FAIL\s+database connection\s+PDOException/', $t3) && strpos($t3, 'Undefined function') === false && strpos($t3, 'PW-SECRET') === false, 'the checker initialises the database layer itself: an unreachable database is reported as a connection error (PDOException), not as an undefined-function Error');
// ---- 4. code that must read configuration through sec_setting
$an = file_get_contents($root . '/analytics.php'); ok(strpos($an, "getenv('AIHUB_ANALYTICS")  === false && substr_count($an, 'sec_setting(') >= 4, 'analytics.php no longer reads AIHUB_ANALYTICS* with getenv()');
foreach (glob($root . '/*.php') as $fx) { $c = file_get_contents($fx); if (basename($fx) === 'security.php') continue; ok(!preg_match("/getenv\('AIHUB_(ANALYTICS|ENV_LABEL)/", preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $c)), basename($fx) . ' reads no Analytics/environment setting with raw getenv()'); }
exec('rm -rf ' . escapeshellarg($tmp));
echo "$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
