<?php
/**
 * Phase 7B notification reliability (ISOLATED test database + a private mock SMTP sink on a local port; nothing leaves the machine):
 * parallel cron runs, SMTP failure, spooling, retry, duplicate prevention for digest, alerts and reminders.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$spool = sys_get_temp_dir() . '/crm7b_rel_spool_' . getmypid(); $sinkLog = sys_get_temp_dir() . '/crm7b_rel_sink_' . getmypid() . '.log'; @mkdir($spool, 0700, true); @unlink($sinkLog);
putenv('AIHUB_MAIL_SPOOL=' . $spool); $_ENV['AIHUB_MAIL_SPOOL'] = $spool; ob_start(); $root = dirname(__DIR__);
require_once $root . '/helpers.php'; require_once $root . '/mailer.php'; require_once $root . '/crm_dashboard.php'; ob_end_clean();
$pass = 0; $fail = 0; function ok($c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; fwrite(STDERR, "  FAIL: $m\n"); } } function section(string $t): void { fwrite(STDERR, "== $t\n"); }
if (stripos((string)sec_setting('AIHUB_DB_NAME', 'db.name'), 'test') === false) { fwrite(STDERR, "Refusing: database name must contain 'test'\n"); exit(2); }
db_connection_available(); $pdo = crm_pdo();
if (!crm_response_ready($pdo)) { fwrite(STDERR, "Apply database/phase7b_lead_management.sql to the test database first\n"); exit(2); }
$mailRaw = (string)$pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='mail'")->fetchColumn(); $mail = json_decode($mailRaw, true) ?: [];
$setMail = function (int $port) use ($pdo, $mail) { $m = $mail; $m['smtp_host'] = '127.0.0.1'; $m['smtp_port'] = $port; $m['to_email'] = 'owner@example.test'; $pdo->prepare("UPDATE site_settings SET setting_value = ? WHERE setting_key='mail'")->execute([json_encode($m)]); };
$port = 2600 + getmypid() % 300; $sink = null; $startSink = function () use (&$sink, $port, $sinkLog, $root) { $sink = proc_open(['python3', $root . '/tests/smtp_sink.py', (string)$port, $sinkLog], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p); usleep(700000); };
register_shutdown_function(function () use (&$sink, $pdo, $mailRaw, $spool, $sinkLog) { if (is_resource($sink)) { proc_terminate($sink); proc_close($sink); } try { $pdo->prepare("UPDATE site_settings SET setting_value = ? WHERE setting_key='mail'")->execute([$mailRaw]); } catch (Throwable $e) {} foreach (glob($spool . '/*') ?: [] as $f) @unlink($f); @rmdir($spool); @unlink($sinkLog); });
$sunk = fn() => is_file($sinkLog) ? substr_count((string)file_get_contents($sinkLog), "\n----\n") : 0; $spooled = fn() => count(glob($spool . '/*.json') ?: []);
$now = gmmktime(9, 0, 0, 3, 9, 2026);   // Monday 10:00 Berlin
$cli = function (string $script, array $args, int $n, array $env = []) use ($root, $now, $spool) {   // n parallel processes started together
  $procs = []; $base = array_merge(getenv(), ['AIHUB_TEST_NOW' => (string)$now, 'AIHUB_MAIL_SPOOL' => $spool], $env);
  for ($i = 0; $i < $n; $i++) { $procs[$i] = proc_open(array_merge([PHP_BINARY, $root . '/scripts/' . $script], $args), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, $root, $base); $pipes[$i] = $pp; stream_set_blocking($pp[1], true); }
  $out = []; foreach ($procs as $i => $p) { $out[] = trim((string)stream_get_contents($pipes[$i][1])); fclose($pipes[$i][1]); fclose($pipes[$i][2]); proc_close($p); } return $out; };
$count = fn(array $out, string $needle) => count(array_filter($out, fn($l) => str_contains($l, $needle)));

foreach (['crm_notification_log', 'crm_consultations', 'crm_lead_response'] as $t) $pdo->exec("DELETE FROM $t"); foreach (['crm_tasks', 'crm_activities', 'crm_leads', 'crm_contacts', 'crm_companies'] as $t) { try { $pdo->exec("DELETE FROM $t"); } catch (Throwable $e) {} }
foreach (glob($spool . '/*') ?: [] as $f) @unlink($f);
$ids = []; foreach ([['development', 'a@acme-corp.example', 'Acme GmbH', '20k-50k', 'asap'], ['advertising', 'b@brand-co.example', 'Brand Co', '5k-20k', '1-3-months']] as [$k, $e, $c, $b, $t]) { [, $id] = crm_lead_ingest($pdo, ['kind' => $k, 'name' => 'N', 'email' => $e, 'company' => $c, 'website' => 'https://x.example', 'budget' => $b, 'timeline' => $t, 'message' => str_repeat('detail ', 20), 'source' => 'test']); $ids[] = (int)$id; }
foreach ($ids as $id) { $pdo->exec("UPDATE crm_leads SET created_at='2026-03-05 09:00:00' WHERE id=$id"); $pdo->exec("UPDATE crm_lead_response SET created_at='2026-03-05 09:00:00', due_at='2026-03-05 13:00:00' WHERE lead_id=$id"); }
$pdo->exec("UPDATE crm_tasks SET due_at='2026-03-05 13:00:00'");

section('SMTP down: 6 parallel digest runs -> exactly one digest, spooled, owner only');
$setMail(1); $out = $cli('crm_reminders.php', ['digest'], 6);
ok($count($out, 'digest: sent') === 1 && $count($out, 'already_sent') === 5, 'one run sends, five see already_sent (' . implode(' | ', array_map(fn($l) => substr($l, 26), $out)) . ')');
ok($spooled() === 1, 'the failed delivery sits in the spool exactly once'); ok((int)$pdo->query("SELECT COUNT(*) FROM crm_notification_log WHERE kind='digest'")->fetchColumn() === 1, 'one digest log row');
$msg = json_decode((string)file_get_contents(glob($spool . '/*.json')[0]), true); ok($msg['to'] === 'owner@example.test' && str_contains($msg['subject'], 'daily digest') && !str_contains(json_encode($msg), 'acme-corp.example'), 'spooled mail is owner-only and holds no customer address');

section('retry while SMTP is still down: no duplicate, attempts counted');
$o = $cli('mail_spool_flush.php', ['--force'], 1); $msg = json_decode((string)file_get_contents(glob($spool . '/*.json')[0]), true); ok($spooled() === 1 && (int)$msg['attempts'] >= 1, 'forced retry fails, message stays queued with attempts=' . ($msg['attempts'] ?? '?'));
$out = $cli('crm_reminders.php', ['digest'], 1); ok($count($out, 'already_sent') === 1 && $spooled() === 1, 're-running the digest does not queue a second message');

section('SMTP back: 6 parallel spool flushes deliver exactly once');
$startSink(); $setMail($port); $out = $cli('mail_spool_flush.php', ['--force'], 6);
ok($sunk() === 1, 'the sink received exactly one message (got ' . $sunk() . ')'); ok($spooled() === 0, 'spool is empty after delivery'); $cli('mail_spool_flush.php', ['--force'], 2); ok($sunk() === 1, 'later flushes send nothing more');
$out = $cli('crm_reminders.php', ['digest'], 3); ok($count($out, 'already_sent') === 3 && $sunk() === 1, 'same-day digest still not sent again after delivery');

section('alerts: 6 parallel runs with SMTP up -> one e-mail, one log row per lead');
$out = $cli('crm_reminders.php', ['alerts'], 6); ok($count($out, 'alerts: sent') === 1 && $count($out, 'alerts: sent') + $count($out, 'alerts: none') + $count($out, 'alerts: busy') === 6, 'exactly one run sends the alert (' . implode(' | ', array_map(fn($l) => substr($l, 26), $out)) . ')');
ok($sunk() === 2, 'sink has digest + one alert'); ok((int)$pdo->query("SELECT COUNT(*) FROM crm_notification_log WHERE kind='lead_overdue'")->fetchColumn() === count($ids), 'one alert row per overdue lead'); $out = $cli('crm_reminders.php', ['alerts'], 2); ok($count($out, 'alerts: sent') === 0 && $sunk() === 2, 'no repeat alert');

section('reminders: 6 parallel runs -> one e-mail per week window');
$out = $cli('crm_reminders.php', ['reminders'], 6); ok($count($out, 'reminders: sent') === 1 && $sunk() === 3, 'one reminder e-mail'); $out = $cli('crm_reminders.php', ['reminders'], 2); ok($count($out, 'reminders: sent') === 0 && $sunk() === 3, 'no repeat reminder in the same week');

section('SMTP failure during an alert: spooled once, never re-alerted');
$pdo->exec("DELETE FROM crm_notification_log WHERE kind='lead_overdue'"); $setMail(1); $out = $cli('crm_reminders.php', ['alerts'], 4); ok($count($out, 'alerts: sent') === 1 && $spooled() === 1, 'failed alert is queued exactly once'); $out = $cli('crm_reminders.php', ['alerts'], 2); ok($count($out, 'alerts: sent') === 0 && $spooled() === 1, 'no second alert is queued while the first is waiting');
$setMail($port); $cli('mail_spool_flush.php', ['--force'], 3); ok($spooled() === 0 && $sunk() === 4, 'queued alert delivered once after SMTP recovers');

section('non-working times send nothing');
$sat = gmmktime(8, 0, 0, 3, 7, 2026); $out = $cli('crm_reminders.php', ['digest'], 1, ['AIHUB_TEST_NOW' => (string)$sat]); ok($count($out, 'not_workday') === 1, 'no digest on Saturday'); $out = $cli('crm_reminders.php', ['alerts'], 1, ['AIHUB_TEST_NOW' => (string)$sat]); ok($count($out, 'outside_hours') === 1, 'no alert outside hours'); ok($sunk() === 4 && $spooled() === 0, 'nothing sent');
fwrite(STDERR, "\ncrm_notify_reliability_test: $pass passed, $fail failed\n"); echo "crm_notify_reliability_test: $pass passed, $fail failed\n"; exit($fail ? 1 : 0);
