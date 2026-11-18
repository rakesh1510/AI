<?php
/**
 * Regression test: pages must keep working when the web server user cannot persist the signing secret (staging incident: HTTP 500 on every page).
 * The CLI skips the failing branch (sec_log() uses the literal 'cli' instead of an IP hash), so this test serves a COPY of the application over HTTP
 * with the secret file path blocked (data/.form_secret is a directory, which fails even for root) and no AIHUB_FORM_SECRET / AIHUB_ANALYTICS_SALT.
 * Usage: php tests/secret_init_test.php   (needs only PHP; no database)
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = realpath(__DIR__ . '/..'); $pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "  FAIL: $m\n"; } }
$tmp = sys_get_temp_dir() . '/aihub_secret_init_' . getmypid(); @mkdir($tmp, 0700, true); $app = "$tmp/app";
shell_exec('mkdir -p ' . escapeshellarg($app) . ' && cd ' . escapeshellarg($root) . ' && tar --exclude=.git --exclude=node_modules --exclude=qa-screenshots-local -cf - . | tar -xf - -C ' . escapeshellarg($app));
@unlink("$app/data/.form_secret"); @mkdir("$app/data/.form_secret");
function serve(string $app, string $log, array $env, int $port): int { $e = ''; foreach ($env as $k => $v) $e .= $k . '=' . escapeshellarg($v) . ' '; return (int)trim((string)shell_exec('cd ' . escapeshellarg($app) . ' && env -i PATH=' . escapeshellarg(getenv('PATH')) . ' ' . $e . 'nohup php -d display_errors=0 -d log_errors=1 -d error_log=' . escapeshellarg("$log.php") . " -S 127.0.0.1:$port -t . tests/router.php >/dev/null 2>&1 & echo \$!")); }
function get(string $u): array { $t = microtime(true); $ch = curl_init($u); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]); $b = (string)curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return ['code' => $c, 'body' => $b, 'secs' => microtime(true) - $t]; }
$port = 8099 + (getmypid() % 40); $log = "$tmp/sec.log";
$pid = serve($app, $log, ['AIHUB_SECURITY_LOG' => $log, 'AIHUB_RATE_DIR' => "$tmp/rate", 'AIHUB_CACHE' => '0'], $port); usleep(1500000);
foreach (['/', '/advertise.php', '/contact.php', '/services.php', '/tool.php?id=chatgpt', '/search.php?q=pdf'] as $u) { $r = get("http://127.0.0.1:$port$u"); ok($r['code'] === 200 && $r['secs'] < 5, "unwritable secret: $u answers HTTP 200 quickly (got {$r['code']} in " . round($r['secs'], 2) . ' s)'); }
$r = get("http://127.0.0.1:$port/advertise.php"); ok(strpos($r['body'], 'name="form_token"') !== false, 'forms still render (they fail closed on submit because the secret cannot be persisted)');
$entries = array_filter(explode("\n", (string)@file_get_contents($log))); $ev = array_filter($entries, fn($l) => strpos($l, 'secret_not_persisted') !== false);
ok(count($ev) >= 1 && strpos(implode("\n", $ev), '"hint"') !== false, 'the condition is logged as secret_not_persisted with a fix hint');
ok(strpos((string)@file_get_contents("$log.php"), 'Maximum execution time') === false && strpos((string)@file_get_contents("$log.php"), 'Allowed memory size') === false, 'no recursion (no execution-time or memory fatal) in the PHP error log');
shell_exec('kill ' . $pid . ' 2>/dev/null'); usleep(300000);
@unlink($log); $pid = serve($app, $log, ['AIHUB_SECURITY_LOG' => $log, 'AIHUB_RATE_DIR' => "$tmp/rate", 'AIHUB_CACHE' => '0', 'AIHUB_FORM_SECRET' => str_repeat('a', 64)], $port); usleep(1500000);
$r = get("http://127.0.0.1:$port/advertise.php"); ok($r['code'] === 200 && strpos((string)@file_get_contents($log), 'secret_not_persisted') === false, 'control: with AIHUB_FORM_SECRET set (the recommended production setup) nothing is logged and the page works');
shell_exec('kill ' . $pid . ' 2>/dev/null'); shell_exec('rm -rf ' . escapeshellarg($tmp));
echo "\nPASSED: $pass  FAILED: $fail\n"; exit($fail ? 1 : 0);
