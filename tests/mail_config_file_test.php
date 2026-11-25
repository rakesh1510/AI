<?php
/**
 * The optional private mail file (outside the web root and Git) overrides the mail block, leaves every other setting alone and never leaks into the health output.
 * Uses FAKE values only. Runs the checks in child processes so each one has its own environment. Writes and removes only its own scratch directory.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = realpath(__DIR__ . '/..'); $pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "  FAIL: $m\n"; } }
$tmp = sys_get_temp_dir() . '/mailcfg_' . getmypid(); if (strlen($tmp) < 12 || !@mkdir($tmp, 0700, true)) { fwrite(STDERR, "cannot create scratch dir\n"); exit(2); }
$mailFile = "$tmp/mail.php"; $localFile = "$tmp/config.local.php";
file_put_contents($mailFile, "<?php\nreturn ['mail' => ['smtp_host' => 'smtp.example.invalid', 'smtp_port' => 587, 'smtp_secure' => 'tls', 'smtp_user' => 'fake-user@example.invalid', 'smtp_pass' => 'FAKE-PASSWORD-123', 'from_email' => 'from@example.invalid', 'from_name' => 'Test Sender', 'to_email' => 'owner@example.invalid']];\n");
file_put_contents($localFile, "<?php\nreturn ['mail' => ['smtp_host' => 'smtp.old-provider.invalid', 'smtp_user' => 'old', 'smtp_pass' => 'old', 'from_email' => 'old@example.invalid', 'to_email' => 'old-owner@example.invalid'], 'trusted_proxies' => '10.0.0.1'];\n");
$probe = $tmp . '/probe.php';
file_put_contents($probe, "<?php require '$root/helpers.php'; \$m = site_cfg()['mail'] ?? []; echo json_encode(['host' => \$m['smtp_host'] ?? '', 'port' => \$m['smtp_port'] ?? 0, 'secure' => \$m['smtp_secure'] ?? '', 'name' => \$m['from_name'] ?? '', 'to' => \$m['to_email'] ?? '', 'from' => \$m['from_email'] ?? '', 'user_set' => !empty(\$m['smtp_user']), 'proxies' => sec_setting('AIHUB_TRUSTED_PROXIES', 'trusted_proxies'), 'mode' => mail_smtp_secure_mode(\$m)]);");
$run = function (array $env) use ($probe) {
  $e = ''; foreach ($env as $k => $v) $e .= $k . '=' . escapeshellarg($v) . ' ';
  return json_decode((string)shell_exec("env -u AIHUB_SMTP_HOST -u AIHUB_SMTP_USER -u AIHUB_SMTP_PASS -u AIHUB_MAIL_TO -u AIHUB_MAIL_FROM $e php " . escapeshellarg($probe) . ' 2>/dev/null'), true) ?: [];
};
$r = $run(['AIHUB_CONFIG_FILE' => $localFile, 'AIHUB_MAIL_CONFIG_FILE' => $mailFile]);
ok(($r['host'] ?? '') === 'smtp.example.invalid' && ($r['port'] ?? 0) === 587 && ($r['secure'] ?? '') === 'tls' && ($r['mode'] ?? '') === 'tls', 'the private mail file replaces the old provider settings (host, port, STARTTLS)');
ok(($r['to'] ?? '') === 'owner@example.invalid' && ($r['from'] ?? '') === 'from@example.invalid' && ($r['name'] ?? '') === 'Test Sender' && !empty($r['user_set']), 'recipient, sender address, sender name and login come from the private file');
ok(($r['proxies'] ?? '') === '10.0.0.1', 'unrelated settings in config.local.php are untouched');
$r = $run(['AIHUB_CONFIG_FILE' => $localFile, 'AIHUB_MAIL_CONFIG_FILE' => "$tmp/absent.php"]);
ok(($r['host'] ?? '') === 'smtp.old-provider.invalid', 'with no private file the existing configuration keeps working exactly as before');
$r = $run(['AIHUB_CONFIG_FILE' => $localFile, 'AIHUB_MAIL_CONFIG_FILE' => $mailFile, 'AIHUB_SMTP_HOST' => 'env.example.invalid']);
ok(($r['host'] ?? '') === 'env.example.invalid', 'an environment variable still has the highest priority');
$health = (string)shell_exec("AIHUB_CONFIG_FILE=" . escapeshellarg($localFile) . " AIHUB_MAIL_CONFIG_FILE=" . escapeshellarg($mailFile) . " php " . escapeshellarg($root . '/scripts/mail_health.php') . ' 2>&1');
ok(strpos($health, 'FAKE-PASSWORD-123') === false && strpos($health, 'fake-user') === false && strpos($health, 'owner@example.invalid') === false && strpos($health, 'smtp.example.invalid') !== false, 'the health report shows the host but never the password, user name or full address');
$send = (string)shell_exec("AIHUB_CONFIG_FILE=" . escapeshellarg($localFile) . " AIHUB_MAIL_CONFIG_FILE=" . escapeshellarg($mailFile) . " php " . escapeshellarg($root . '/scripts/mail_send_test.php') . ' 2>&1');
ok(strpos($send, 'dry run') !== false && strpos($send, 'FAKE-PASSWORD-123') === false && strpos($send, 'fake-user') === false, 'the send-test script is a dry run without --yes and prints no credential');
foreach ([$mailFile, $localFile, $probe] as $f) @unlink($f); @rmdir($tmp);
echo "mail config file: $pass passed, $fail failed\n"; exit($fail ? 1 : 0);
