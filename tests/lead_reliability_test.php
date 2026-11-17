<?php
/**
 * B2B lead workflow reliability with MOCK mail only (local SMTP sink, closed ports and a file outbox; nothing leaves the machine).
 * Covers: submission, validation, storage, notification success and failure, failure visibility for the administrator without sensitive
 * detail, retry, status changes and reporting access.
 * Usage: php tests/lead_reliability_test.php http://127.0.0.1:8002     (needs the isolated test DB, SMTP sink at AIHUB_SMTP_LOG, AIHUB_RATE_DIR)
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8002', '/');
$root = realpath(__DIR__ . '/..');
require_once $root . '/helpers.php';
if (stripos((string)sec_setting('AIHUB_DB_NAME', 'db.name'), 'test') === false) { fwrite(STDERR, "Refusing: database name must contain 'test'\n"); exit(2); }
db_connection_available(); $pdo = db();
$smtpLog = getenv('AIHUB_SMTP_LOG') ?: '/tmp/smtp_sink.log'; $rateDir = getenv('AIHUB_RATE_DIR') ?: ''; $adminPw = getenv('AIHUB_TEST_ADMIN_PW') ?: 'ci-admin-test-pw';
$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "  FAIL: $m\n"; } }
function section(string $t): void { echo "== $t\n"; }
function http(string $m, string $u, $body = null, array $h = [], ?string $jar = null): array {
  $ch = curl_init($u); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CUSTOMREQUEST => $m, CURLOPT_HTTPHEADER => $h, CURLOPT_FOLLOWLOCATION => false]);
  if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
  if ($jar) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
  $r = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
  return ['code' => $code, 'head' => substr($r, 0, $hs), 'body' => substr($r, $hs), 'loc' => preg_match('/^Location:\s*(.+)$/mi', substr($r, 0, $hs), $mm) ? trim($mm[1]) : ''];
}
function clear_rate(): void { global $rateDir; foreach (glob($rateDir . '/*') ?: [] as $f) @unlink($f); }
function token(string $html, string $n = 'form_token'): string { return preg_match('/name="' . preg_quote($n, '/') . '" value="([^"]+)"/', $html, $m) ? html_entity_decode($m[1]) : ''; }
$host = parse_url($base, PHP_URL_HOST) . ':' . parse_url($base, PHP_URL_PORT); $own = ['Origin: http://' . $host];
function lead(string $b, array $over = [], string $path = '/business.php', ?string $h = null): array {
  global $own; $pageHtml = http('GET', $b . $path)['body']; $tok = token($pageHtml); sleep(2);
  return http('POST', $b . '/inquiry.php', $over + ['lead_type' => 'consultation', 'name' => 'Rel Tester', 'email' => 'rel' . bin2hex(random_bytes(3)) . '@example.test', 'company' => 'Test Co', 'message' => 'We would like to discuss a custom AI agent for support triage.', 'consent' => '1', 'form_token' => $tok], $own);
}
$lastLead = fn() => $pdo->query('SELECT * FROM leads ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);

section('Submission, validation and storage');
clear_rate(); $pdo->exec('DELETE FROM lead_status_history'); $pdo->exec('DELETE FROM leads'); @file_put_contents($smtpLog, '');
$email = 'happy' . bin2hex(random_bytes(3)) . '@example.test';
$r = lead($base, ['email' => $email, 'name' => "O'Brien <b>& Sons</b>", 'package_interest' => 'featured']);
$row = $lastLead();
ok($r['code'] === 303 && preg_match('#/inquiry\.php\?sent=consultation#', $r['loc']) && $row && $row['email'] === $email, 'a valid consultation request is stored and the visitor is redirected to the thank-you page');
ok($row['name'] === "O'Brien <b>& Sons</b>" && $row['status'] === 'new', 'special characters are stored verbatim as data (they are escaped on output, not mangled)');
$ty = http('GET', $base . $r['loc']); ok($ty['code'] === 200 && stripos($ty['body'], 'received') !== false && strpos($ty['body'], $email) === false, 'the thank-you page confirms receipt without echoing personal data');
usleep(800000); $log = (string)@file_get_contents($smtpLog);
ok(preg_match('/^Subject: (.+)$/m', $log, $sm) && strpos(iconv_mime_decode($sm[1], 0, 'UTF-8'), '[AIHubpedia lead]') === 0 && strpos($log, 'Reply-To: ' . $email) !== false && strpos($log, 'To: inbox@example.test') !== false, 'the owner notification reached the mock mail server with the visitor as Reply-To');
ok(strpos($log, "Email: $email\n") !== false && strpos($log, "Message:\nWe would like") !== false, 'the plain-text part is properly formatted, one field per line');
ok(strpos($log, '<b>') === false || strpos($log, '&lt;b&gt;') !== false, 'markup in the lead name is escaped in the notification email');
ok($row['notify_status'] === 'sent' && (int)$row['notify_attempts'] === 1 && $row['notify_error'] === '' && $row['notified_at'] !== null, 'delivery is recorded as sent');
$bad = ['bad email' => ['email' => 'nope'], 'no consent' => ['consent' => ''], 'short message' => ['message' => 'too short'], 'no name' => ['name' => ''], 'bad website' => ['website' => 'javascript:alert(1)']];
$cnt = (int)$pdo->query('SELECT COUNT(*) FROM leads')->fetchColumn();
foreach ($bad as $label => $o) {
  $r = lead($base, $o);
  ok($r['code'] === 422 && stripos($r['body'], 'class="fieldError"') !== false, "validation: $label is refused with a visible field error (HTTP {$r['code']})");
  if ($label === 'bad email') ok(strpos($r['body'], 'value="nope"') !== false, 'validation: the visitor\'s input is kept so they can correct it');
}
ok((int)$pdo->query('SELECT COUNT(*) FROM leads')->fetchColumn() === $cnt, 'refused submissions store nothing and send nothing');
clear_rate();

section('Notification failure: stored, visitor unaffected, admin informed');
$fport = 8095; $tmp = sys_get_temp_dir() . '/aihub_rel_' . getmypid(); @mkdir($tmp, 0700, true); @mkdir("$tmp/rate", 0700, true);
$hash = password_hash($adminPw, PASSWORD_DEFAULT);
$envDb = 'AIHUB_DB_HOST=' . escapeshellarg(sec_setting('AIHUB_DB_HOST', 'db.host', '127.0.0.1')) . ' AIHUB_DB_NAME=' . escapeshellarg(sec_setting('AIHUB_DB_NAME', 'db.name')) . ' AIHUB_DB_USER=' . escapeshellarg(sec_setting('AIHUB_DB_USER', 'db.user')) . ' AIHUB_DB_PASS=' . escapeshellarg(sec_setting('AIHUB_DB_PASS', 'db.pass'));
$start = function (int $port, string $extra) use ($root, $tmp, $envDb, $hash) { return (int)trim((string)shell_exec("cd " . escapeshellarg($root) . " && $envDb AIHUB_RATE_DIR=" . escapeshellarg("$tmp/rate") . " AIHUB_CACHE_DIR=" . escapeshellarg("$tmp/cache") . " AIHUB_SECURITY_LOG=" . escapeshellarg("$tmp/sec.log") . " AIHUB_ADMIN_PASS_HASH=" . escapeshellarg($hash) . " $extra php -d log_errors=1 -d error_log=" . escapeshellarg("$tmp/php$port.log") . " -S 127.0.0.1:$port -t . tests/router.php > /dev/null 2>&1 & echo $!")); };
$pidF = $start($fport, 'AIHUB_SMTP_HOST=127.0.0.1 AIHUB_SMTP_PORT=1 AIHUB_SMTP_SECURE= AIHUB_SMTP_USER=secret-user-name AIHUB_SMTP_PASS=secret-pass-word'); usleep(1300000);
$fb = "http://127.0.0.1:$fport"; $fown = ['Origin: http://127.0.0.1:' . $fport];
$femail = 'down' . bin2hex(random_bytes(3)) . '@example.test';
$home = http('GET', "$fb/business.php"); sleep(2);
$r = http('POST', "$fb/inquiry.php", ['lead_type' => 'ai_agent', 'name' => 'Down Tester', 'email' => $femail, 'message' => 'The mail server is unreachable when this arrives.', 'consent' => '1', 'form_token' => token($home['body'])], $fown);
$row = $pdo->query("SELECT * FROM leads WHERE email = " . $pdo->quote($femail))->fetch(PDO::FETCH_ASSOC);
ok($r['code'] === 303 && $row, 'with the mail server down the lead is still stored and the visitor still sees success (no lost lead)');
ok($row['notify_status'] === 'failed' && $row['notify_error'] === 'smtp_connect' && (int)$row['notify_attempts'] === 1, 'the failure is recorded with a safe reason code (' . ($row['notify_error'] ?? '?') . ')');
$ty = http('GET', "$fb" . $r['loc']); ok(strpos($ty['body'], 'smtp') === false && strpos($ty['body'], '127.0.0.1') === false && strpos($ty['body'], 'secret-') === false, 'the visitor never sees mail-server details');
$sec = (string)@file_get_contents("$tmp/sec.log");
ok(strpos($sec, 'lead_notification_failed') !== false && strpos($sec, $femail) === false && strpos($sec, 'secret-user-name') === false && strpos($sec, 'secret-pass-word') === false && strpos($sec, 'Down Tester') === false, 'the security log records the failure by lead id and reason only (no email address, name, host or credentials)');
$php = (string)@file_get_contents("$tmp/php$fport.log"); ok(strpos($php, 'secret-pass-word') === false && strpos($php, 'secret-user-name') === false, 'credentials never appear in the PHP error log');
// admin sees it
$jar = tempnam($tmp, 'a'); $lg = http('GET', "$base/admin.php", null, [], $jar); $lt = token($lg['body'], '_login_token'); sleep(2);
http('POST', "$base/admin.php", ['admin_action' => 'login', '_login_token' => $lt, 'password' => $adminPw], $own, $jar);
$list = http('GET', "$base/admin-leads.php", null, [], $jar);
ok(stripos($list['body'], 'could not be announced by email') !== false && stripos($list['body'], 'The mail server could not be reached') !== false, 'the admin page warns that a lead could not be announced and says why in plain words');
ok(strpos($list['body'], 'secret-') === false && strpos($list['body'], '127.0.0.1') === false && !preg_match('/smtp_connect|Connection refused|PHPMailer|SMTP connect\(\)/i', $list['body']), 'the admin sees no raw error text, host, port or credentials');
ok(strpos($list['body'], $femail) === false && strpos($list['body'], 'do***@example.test') !== false, 'the list still masks email addresses');
preg_match('/[?&;]id=(\d+)"[^>]*>#' . $row['id'] . '</', $list['body'], $mm);
$detail = http('GET', "$base/admin-leads.php?id={$row['id']}", null, [], $jar); $csrf = token($detail['body'], '_csrf');
ok(stripos($detail['body'], 'Not sent') !== false && stripos($detail['body'], 'Retry notification') !== false, 'the lead detail shows "Not sent" and offers a retry');
$r1 = http('POST', "$base/admin-leads.php", ['admin_action' => 'retry_notify', 'id' => $row['id']], $own, $jar); ok($r1['code'] === 403, 'retry without a CSRF token is refused');
$anon = http('POST', "$base/admin-leads.php", ['admin_action' => 'retry_notify', 'id' => $row['id'], '_csrf' => $csrf], $own); ok(stripos($anon['body'], 'Admin sign-in') !== false && (int)$pdo->query("SELECT notify_attempts FROM leads WHERE id = {$row['id']}")->fetchColumn() === 1, 'an anonymous visitor cannot trigger a retry');
$r2 = http('POST', "$base/admin-leads.php", ['admin_action' => 'retry_notify', 'id' => $row['id'], '_csrf' => $csrf], $own, $jar);
$after = $pdo->query("SELECT notify_status, notify_error, notify_attempts FROM leads WHERE id = {$row['id']}")->fetch(PDO::FETCH_ASSOC);
ok($r2['code'] === 303 && $after['notify_status'] === 'sent' && (int)$after['notify_attempts'] === 2, 'after mail is working again (main server) the admin retry sends the notification and records it');
$rep = http('GET', "$base/admin-leads.php", null, [], $jar); ok(stripos($rep['body'], 'could not be announced') === false, 'the warning disappears once every notification has been sent');
$bogus = http('POST', "$base/admin-leads.php", ['admin_action' => 'retry_notify', 'id' => 99999999, '_csrf' => $csrf], $own, $jar); ok($bogus['code'] === 303 && strpos($bogus['loc'], 'notify=not_found') !== false, 'retrying an unknown lead id is refused cleanly');

section('Other failure modes');
@mkdir("$tmp/outbox", 0500); shell_exec('kill ' . (int)$pidF . ' 2>/dev/null');
$pid2 = $start($fport, 'AIHUB_MAIL_TRANSPORT=file AIHUB_MAIL_OUTBOX=' . escapeshellarg('/dev/null/denied')); usleep(1300000);
$e2 = 'noout' . bin2hex(random_bytes(3)) . '@example.test'; $home = http('GET', "$fb/business.php"); sleep(2);
$r = http('POST', "$fb/inquiry.php", ['lead_type' => 'software_dev', 'name' => 'Outbox Tester', 'email' => $e2, 'message' => 'The mock outbox cannot be written for this one.', 'consent' => '1', 'form_token' => token($home['body'])], $fown);
$row2 = $pdo->query("SELECT notify_status, notify_error FROM leads WHERE email = " . $pdo->quote($e2))->fetch(PDO::FETCH_ASSOC);
ok($r['code'] === 303 && $row2['notify_status'] === 'failed' && $row2['notify_error'] === 'outbox_unwritable', 'a failing mock transport is recorded as such, and the lead is still stored');
shell_exec('kill ' . (int)$pid2 . ' 2>/dev/null');
// unit: header injection and code mapping
putenv('AIHUB_MAIL_TRANSPORT=file'); putenv('AIHUB_MAIL_OUTBOX=' . "$tmp/ob2");
$c = ['lead_type' => 'consultation', 'name' => "Evil\r\nBcc: victim@example.test", 'email' => 'inj@example.test', 'company' => '', 'website' => '', 'budget' => '', 'timeline' => '', 'message' => 'm', 'source_page' => '/x'];
$okSend = lead_notify_owner($c); $files = glob("$tmp/ob2/*.json") ?: [];
$msg = $files ? json_decode((string)file_get_contents($files[0]), true) : [];
ok($okSend && $files && strpos($msg['subject'], "\n") === false && strpos($msg['subject'], "\r") === false && !isset($msg['headers']['Bcc']), 'a CRLF/Bcc injection in the lead name cannot add headers or recipients to the notification');
putenv('AIHUB_MAIL_TRANSPORT'); putenv('AIHUB_MAIL_OUTBOX');
foreach (['SMTP connect() failed.' => 'smtp_connect', 'Could not authenticate' => 'smtp_auth', 'SMTP Error: The following recipients failed' => 'smtp_rejected', 'weird' => 'smtp_other'] as $m => $code) ok(mail_classify_error($m) === $code, "error classification: “{$m}” → $code");
foreach (['not_configured', 'smtp_connect', 'smtp_auth', 'smtp_rejected', 'smtp_other', 'invalid_recipient', 'library_missing', 'outbox_unwritable'] as $code) ok(mail_error_label($code) !== '' && !preg_match('/\d+\.\d+\.\d+|@|password|:\d{2,5}/i', mail_error_label($code)), "label for $code is plain language without technical detail");

section('Status changes and reporting access');
$r = lead($base, ['email' => 'flow' . bin2hex(random_bytes(3)) . '@example.test', 'lead_type' => 'ai_agent']); $fl = $lastLead();
$d = http('GET', "$base/admin-leads.php?id={$fl['id']}", null, [], $jar); $csrf = token($d['body'], '_csrf');
foreach (['contacted', 'qualified', 'proposal_sent', 'won'] as $st) { http('POST', "$base/admin-leads.php", ['admin_action' => 'set_status', 'id' => $fl['id'], 'status' => $st, '_csrf' => $csrf], $own, $jar); }
$hist = $pdo->query("SELECT new_status FROM lead_status_history WHERE lead_id = {$fl['id']} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
ok($hist === ['contacted', 'qualified', 'proposal_sent', 'won'] && $pdo->query("SELECT status FROM leads WHERE id = {$fl['id']}")->fetchColumn() === 'won', 'a lead moves new → contacted → qualified → proposal sent → won with a history entry for each step');
http('POST', "$base/admin-leads.php", ['admin_action' => 'set_status', 'id' => $fl['id'], 'status' => 'new', '_csrf' => $csrf], $own, $jar);
ok($pdo->query("SELECT status FROM leads WHERE id = {$fl['id']}")->fetchColumn() === 'won', 'an invalid transition (won → new) is refused');
$rep = http('GET', "$base/admin-leads.php?days=30", null, [], $jar);
ok(stripos($rep['body'], 'Total leads:') !== false && preg_match('/Total leads: <strong>(\d+)<\/strong>/', $rep['body'], $tm) && (int)$tm[1] === (int)$pdo->query('SELECT COUNT(*) FROM leads WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)')->fetchColumn(), 'the report total equals the number of stored leads');
foreach (['/admin-leads.php', '/admin-leads.php?id=' . $fl['id']] as $p) { $a = http('GET', $base . $p); ok(stripos($a['body'], 'Admin sign-in') !== false && strpos($a['body'], '@example.test') === false, "anonymous access to $p shows only the sign-in form"); }
$sec = (string)@file_get_contents(getenv('AIHUB_SECURITY_LOG') ?: '/tmp/aihub_seclog_dev.log');
ok(!preg_match('/@example\.test/', $sec), 'no email address of a lead appears in the security log');
section('Mock campaign funnel: landing parameters -> form -> stored attribution -> report (Phase 5)');
clear_rate(); $pdo->exec('DELETE FROM lead_status_history'); $pdo->exec('DELETE FROM leads');
$landing = '/advertise.php?utm_source=mockletter&utm_medium=email&utm_campaign=p5-test&package=featured&tool=chatgpt';
$lp = http('GET', $base . $landing)['body'];
$hidden = fn(string $n) => preg_match('/name="' . preg_quote($n, '/') . '"[^>]*value="([^"]*)"/', $lp, $mm) ? html_entity_decode($mm[1]) : (preg_match('/value="([^"]*)"[^>]*name="' . preg_quote($n, '/') . '"/', $lp, $mm) ? html_entity_decode($mm[1]) : null);
ok($hidden('attr_source') === 'mockletter' && $hidden('attr_medium') === 'email' && $hidden('attr_campaign') === 'p5-test', 'the landing URL parameters are carried into the form as short slugs');
$mk = fn(array $o) => lead($base, $o, $landing);
$r1 = $mk(['lead_type' => 'advertiser', 'package_interest' => 'featured', 'attr_source' => $hidden('attr_source'), 'attr_medium' => $hidden('attr_medium'), 'attr_campaign' => $hidden('attr_campaign'), 'ref_tool' => 'chatgpt', 'campaign_goal' => 'signups']);
$row = $lastLead();
ok($r1['code'] === 303 && $row['attr_source'] === 'mockletter' && $row['attr_campaign'] === 'p5-test' && $row['package_interest'] === 'featured' && $row['ref_tool'] === 'chatgpt', 'the lead is stored with its campaign attribution and package interest');
$r2 = $mk(['lead_type' => 'advertiser', 'package_interest' => 'homepage', 'attr_source' => 'mockletter', 'attr_campaign' => 'p5-test']);
$r3 = $mk(['lead_type' => 'consultation', 'attr_source' => 'tool-page', 'ref_tool' => 'claude']);
$r4 = $mk(['lead_type' => 'advertiser', 'attr_source' => '<script>alert(1)</script>', 'attr_campaign' => str_repeat('x', 300), 'package_interest' => 'nonexistent']);
$l4 = $lastLead();
ok($l4['attr_source'] === 'scriptalert1script' || $l4['attr_source'] === '' || preg_match('/^[a-z0-9._-]*$/', $l4['attr_source']), 'hostile attribution values are reduced to a safe slug');
ok(strlen($l4['attr_campaign']) <= 60 && $l4['package_interest'] === '', 'oversize campaign values are truncated and unknown packages are dropped');
$rep = leads_report($pdo, 30);
$src = array_column($rep['by_source'], 'n', 'attr_source');
ok(($src['mockletter'] ?? 0) === 2 && ($src['tool-page'] ?? 0) === 1, 'the report counts leads by source (raw counts: mockletter 2, tool-page 1)');
$pk = array_column($rep['by_package'], 'n', 'package_interest');
ok(($pk['featured'] ?? 0) === 1 && ($pk['homepage'] ?? 0) === 1 && !isset($pk['nonexistent']), 'the report counts package interest');
ok($rep['total'] === 4 && !array_key_exists('conversion_rate', $rep) && !array_key_exists('revenue', $rep), 'the report holds counts only: no conversion rate and no revenue');
$rr = http('GET', "$base/advertise.php?utm_source=%3Cscript%3E", null, []);
ok(strpos($rr['body'], '<script>alert') === false, 'attribution parameters are never reflected as markup');
$pdo->exec('DELETE FROM lead_status_history'); $pdo->exec('DELETE FROM leads'); shell_exec('rm -rf ' . escapeshellarg($tmp)); @unlink($jar);
echo "\nPASSED: $pass  FAILED: $fail\n";
exit($fail ? 1 : 0);
