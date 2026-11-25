<?php
/**
 * End-to-end audit of every public form and its e-mail workflow, with MOCK mail only (file outbox; a closed SMTP port for the failure case; nothing leaves the machine).
 * For each form: validation, storage, unique reference, owner e-mail, customer acknowledgement, duplicate-submission protection, failure handling and retry.
 * Usage: php tests/forms_email_e2e_test.php      (needs the isolated test DB: AIHUB_DB_* with a name containing "test", AIHUB_COMPAT_ROOT_USER/PASS for nothing: no scratch DB is created)
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = realpath(__DIR__ . '/..');
require_once $root . '/helpers.php';
if (stripos((string)sec_setting('AIHUB_DB_NAME', 'db.name'), 'test') === false) { fwrite(STDERR, "Refusing: database name must contain 'test'\n"); exit(2); }
db_connection_available(); $pdo = db();
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
$tmp = sys_get_temp_dir() . '/fe2e_' . getmypid(); @mkdir($tmp, 0700, true); $servers = [];
function freePort(): int { for ($p = 8310; $p < 8340; $p++) { $s = @stream_socket_server("tcp://127.0.0.1:$p"); if ($s) { fclose($s); return $p; } } throw new RuntimeException('no port'); }
function startServer(array $extra): array {
  global $root, $servers, $tmp; $port = freePort(); $name = 's' . $port; $d = "$tmp/$name"; @mkdir("$d/rate", 0700, true); @mkdir("$d/out", 0700, true); @mkdir("$d/spool", 0700, true);
  $env = array_merge(getenv(), ['AIHUB_RATE_DIR' => "$d/rate", 'AIHUB_SECURITY_LOG' => "$d/sec.log", 'AIHUB_MAIL_OUTBOX' => "$d/out", 'AIHUB_MAIL_SPOOL' => "$d/spool", 'AIHUB_FORM_SECRET' => 'fe2e-secret-not-for-production',
    'AIHUB_MAIL_TO' => 'owner@example.test', 'AIHUB_MAIL_FROM' => 'noreply@example.test'], $extra);
  $servers[] = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'output_buffering=0', '-S', "127.0.0.1:$port", '-t', $root, "$root/tests/router.php"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env);
  for ($i = 0; $i < 60; $i++) { $c = @fsockopen('127.0.0.1', $port); if ($c) { fclose($c); break; } usleep(100000); }
  return ['base' => "http://127.0.0.1:$port", 'dir' => $d, 'env' => $env, 'origin' => "Origin: http://127.0.0.1:$port"];
}
function outbox(array $S): array { $o = []; foreach (glob($S['dir'] . '/out/*.json') ?: [] as $f) $o[] = json_decode((string)file_get_contents($f), true); return $o; }
function spool(array $S): array { return glob($S['dir'] . '/spool/*.json') ?: []; }
function token(string $html, string $n = 'form_token'): string { return preg_match('/name="' . preg_quote($n, '/') . '" value="([^"]+)"/', $html, $m) ? html_entity_decode($m[1]) : ''; }
function to_of(array $msgs, string $addr): array { return array_values(array_filter($msgs, fn($m) => ($m['to'] ?? '') === $addr)); }
register_shutdown_function(function () { global $servers, $tmp; foreach ($servers as $p) { if (is_resource($p)) { proc_terminate($p); proc_close($p); } } exec('rm -rf ' . escapeshellarg($tmp)); });

$A = startServer(['AIHUB_MAIL_TRANSPORT' => 'file']);                                    // delivery works (mock outbox)
$B = startServer(['AIHUB_SMTP_HOST' => '127.0.0.1', 'AIHUB_SMTP_PORT' => '1', 'AIHUB_SMTP_SECURE' => '']);   // SMTP unreachable (closed port)

$leadPost = function (array $S, array $over = [], string $page = '/business.php'): array {
  $tok = token(http('GET', $S['base'] . $page)['body']); sleep(2);
  return http('POST', $S['base'] . '/inquiry.php', $over + ['lead_type' => 'consultation', 'name' => 'E2E Tester', 'email' => 'cust@example.test', 'company' => 'Test Co', 'message' => 'We would like to discuss a custom integration between our CRM and billing system.', 'consent' => '1', 'form_token' => $tok, 'source_page' => $page, 'website_url_confirm' => ''], [$S['origin']]);
};

section('Business / consultation / advertiser enquiry (inquiry.php): delivery works');
$pdo->exec('DELETE FROM leads'); $pdo->exec('DELETE FROM contact_messages'); $pdo->exec('DELETE FROM submissions'); $pdo->exec('DELETE FROM subscribers');   // isolated test DB only (name guard above)
$n0 = (int)$pdo->query('SELECT COUNT(*) FROM crm_leads')->fetchColumn();
$r = $leadPost($A);
ok($r['code'] === 303 && preg_match('#/inquiry\.php\?sent=consultation&ref=(AHP-L-\d{6}-[A-Z])#', $r['loc'], $m), 'valid enquiry is stored and the visitor is sent to a thank-you page carrying a reference (' . $r['loc'] . ')');
$ref = $m[1] ?? ''; $row = $pdo->query('SELECT * FROM leads ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
ok($row && enquiry_reference('L', (int)$row['id']) === $ref, 'the reference is derived from the stored record and unique to it');
ok((int)$pdo->query('SELECT COUNT(*) FROM crm_leads')->fetchColumn() === $n0 + 1, 'the enquiry is mirrored into the CRM');
$ty = http('GET', $A['base'] . $r['loc']); ok($ty['code'] === 200 && strpos($ty['body'], $ref) !== false && strpos($ty['body'], 'cust@example.test') === false, 'the thank-you page shows the reference and no personal data');
$out = outbox($A); $own = to_of($out, 'owner@example.test'); $cus = to_of($out, 'cust@example.test');
ok(count($own) === 1 && strpos($own[0]['subject'], $ref) !== false && $own[0]['reply_to'] === 'cust@example.test', 'the owner receives one notification with the reference in the subject and the sender as Reply-To');
ok(count($cus) === 1 && strpos($cus[0]['subject'], $ref) !== false && strpos($cus[0]['text'], 'do not promise a response time') !== false && strpos(strtolower($cus[0]['text']), 'unsubscribe') === false, 'the sender receives one acknowledgement with the reference, no response-time promise and no marketing');
ok($row['notify_status'] === 'sent' && (int)$row['notify_attempts'] === 1, 'the lead records that the owner notification was sent');
ok(count(spool($A)) === 0, 'nothing is waiting in the retry spool when delivery succeeds');
$r2 = $leadPost($A);   // identical enquiry again: a double click / refresh
ok($r2['code'] === 303 && strpos($r2['loc'], $ref) !== false && (int)$pdo->query('SELECT COUNT(*) FROM leads')->fetchColumn() === 1 && count(outbox($A)) === 2, 'an identical enquiry within 15 minutes is acknowledged once: same reference, no second record, no second email');
$bad = $leadPost($A, ['email' => 'not-an-email', 'name' => 'X']); ok($bad['code'] === 422 && strpos($bad['body'], 'fieldError') !== false && count(outbox($A)) === 2, 'invalid input is refused with field errors, stores nothing and sends nothing');
$bot = $leadPost($A, ['website_url_confirm' => 'http://spam.example', 'email' => 'bot@example.test']); ok($bot['code'] === 422 && count(to_of(outbox($A), 'bot@example.test')) === 0, 'the honeypot refuses bots without emailing anyone');
$noTok = http('POST', $A['base'] . '/inquiry.php', ['lead_type' => 'consultation', 'name' => 'No Token', 'email' => 'nt@example.test', 'message' => str_repeat('x', 30), 'consent' => '1'], [$A['origin']]); ok($noTok['code'] === 422 && count(to_of(outbox($A), 'nt@example.test')) === 0, 'a missing or forged form token is refused (CSRF/time-trap)');

section('Other enquiry types share the same pipeline (advertiser, partnership, project)');
$adv = $leadPost($A, ['lead_type' => 'advertiser', 'email' => 'adv@example.test', 'package_interest' => 'featured', 'message' => 'We want a featured placement for our product next month, please advise.'], '/advertise.php');
ok($adv['code'] === 303 && strpos($adv['loc'], 'sent=advertiser') !== false && count(to_of(outbox($A), 'owner@example.test')) === 2 && count(to_of(outbox($A), 'adv@example.test')) === 1, 'advertiser enquiry: stored, owner emailed, sender acknowledged');
$tok = token(http('GET', $A['base'] . '/services.php')['body']); sleep(2);
$proj = http('POST', $A['base'] . '/project-inquiry.php', ['name' => 'Proj Person', 'email' => 'proj@example.test', 'message' => 'We need an API integration between our CRM and our billing system.', 'consent' => '1', 'form_token' => $tok, 'source_page' => '/services.php', 'website_url_confirm' => ''], [$A['origin']]);
ok($proj['code'] === 303 && preg_match('/ref=AHP-L-/', $proj['loc']) && count(to_of(outbox($A), 'proj@example.test')) === 1, 'Discuss Your Project / custom solutions (project-inquiry.php): stored with reference, owner emailed, sender acknowledged');
$tok = token(http('GET', $A['base'] . '/partners.php')['body']); sleep(2);
$par = http('POST', $A['base'] . '/partner-apply.php', ['partner_category' => 'saas', 'company' => 'Acme SaaS', 'website' => 'https://acme.example.com', 'name' => 'Pat Partner', 'email' => 'partner@example.test', 'message' => 'We propose a collaboration: an integration guide plus a co-marketing page about our product.', 'consent' => '1', 'form_token' => $tok, 'source_page' => '/partners.php', 'website_url_confirm' => ''], [$A['origin']]);
ok($par['code'] === 303 && preg_match('/ref=AHP-L-/', $par['loc']) && count(to_of(outbox($A), 'partner@example.test')) === 1, 'partnership application (partner-apply.php): stored with reference, owner emailed, applicant acknowledged');

section('Contact form (contact_send.php)');
$jar = "$tmp/jar"; $pg = http('GET', $A['base'] . '/contact.php', null, [], $jar); $csrf = token($pg['body'], 'csrf');
$post = fn(array $o = []) => http('POST', $A['base'] . '/contact_send.php', $o + ['csrf' => $csrf, 'name' => 'Contact Tester', 'email' => 'contact@example.test', 'subject' => 'Tool update', 'message' => 'Please update the pricing link for our tool, thank you.', 'company' => ''], [$A['origin']], $jar);
$before = (int)$pdo->query('SELECT COUNT(*) FROM contact_messages')->fetchColumn(); $c1 = $post();
ok($c1['code'] === 302 && preg_match('#contact\.php\?sent=1&ref=(AHP-C-\d{6}-[A-Z])#', $c1['loc'], $cm) && (int)$pdo->query('SELECT COUNT(*) FROM contact_messages')->fetchColumn() === $before + 1, 'contact message is stored and the sender gets a reference (' . $c1['loc'] . ')');
$cOwn = array_values(array_filter(to_of(outbox($A), 'owner@example.test'), fn($m) => strpos($m['subject'], '[AIHubpedia Contact]') === 0));
ok(count($cOwn) === 1 && strpos($cOwn[0]['subject'], $cm[1]) !== false && count(to_of(outbox($A), 'contact@example.test')) === 1, 'the owner is emailed with the reference and the sender receives an acknowledgement');
$c2 = $post(); ok($c2['code'] === 302 && strpos($c2['loc'], $cm[1]) !== false && (int)$pdo->query('SELECT COUNT(*) FROM contact_messages')->fetchColumn() === $before + 1, 'an identical contact message is acknowledged once (no duplicate row or email)');
$c3 = $post(['csrf' => 'forged']); ok($c3['code'] === 302 && strpos($c3['loc'], 'err=') !== false, 'a forged CSRF token is refused');
$c4 = $post(['company' => 'bot']); ok(strpos($c4['loc'], 'err=') !== false, 'the honeypot field refuses bots');
$c5 = $post(['email' => 'bad']); ok(strpos($c5['loc'], 'err=') !== false, 'an invalid email address is refused');
$pgOk = http('GET', $A['base'] . '/' . $c1['loc'], null, [], $jar); ok(strpos($pgOk['body'], $cm[1]) !== false, 'the contact page shows the reference after sending');

section('Submit an AI tool (submit.php)');
$jar2 = "$tmp/jar2"; $sp = http('GET', $A['base'] . '/submit.php', null, [], $jar2); $stok = token($sp['body'], '_csrf'); $sb = (int)$pdo->query('SELECT COUNT(*) FROM submissions')->fetchColumn();
$subm = fn(array $o = []) => http('POST', $A['base'] . '/submit.php', $o + ['_csrf' => $stok, 'name' => 'E2E Tool', 'category' => 'Writing', 'website' => 'https://e2e-tool.example.com', 'short' => 'An end to end test tool.', 'plan' => 'free', 'website_url_confirm' => ''], [$A['origin']], $jar2);
$s1 = $subm(); preg_match('/(AHP-S-\d{6}-[A-Z])/', $s1['body'], $sm);
ok($s1['code'] === 200 && !empty($sm[1]) && (int)$pdo->query('SELECT COUNT(*) FROM submissions')->fetchColumn() === $sb + 1, 'a tool submission is stored and shows a reference');
$sOwn = array_values(array_filter(to_of(outbox($A), 'owner@example.test'), fn($m) => strpos($m['subject'], '[AIHubpedia submission]') === 0));
ok(count($sOwn) === 1 && strpos($sOwn[0]['subject'], $sm[1] ?? 'x') !== false, 'the owner is emailed about every new tool submission (previously nobody was told)');
$s2 = $subm(); ok((int)$pdo->query('SELECT COUNT(*) FROM submissions')->fetchColumn() === $sb + 1, 'an identical submission is stored once (double click / refresh)');
$s3 = $subm(['_csrf' => 'forged']); ok(stripos($s3['body'], 'expired') !== false, 'a forged CSRF token is refused');

section('Newsletter subscription and confirmation');
$ntok = token(http('GET', $A['base'] . '/')['body']); sleep(2); $nb = (int)$pdo->query("SELECT COUNT(*) FROM subscribers")->fetchColumn();
$ns = http('POST', $A['base'] . '/subscribe.php', ['email' => 'news@example.test', 'consent' => '1', 'form_token' => $ntok, 'source_page' => '/', 'website_url_confirm' => ''], [$A['origin']]);
$conf = to_of(outbox($A), 'news@example.test');
ok($ns['code'] === 200 && (int)$pdo->query("SELECT COUNT(*) FROM subscribers")->fetchColumn() === $nb + 1 && count($conf) === 1 && strpos($conf[0]['text'], '/newsletter-confirm.php?token=') !== false, 'subscribing stores a pending subscriber and sends a double-opt-in confirmation link');
ok(stripos($ns['body'], 'Check your inbox') !== false, 'the visitor is told to check their inbox');
preg_match('/token=([a-f0-9]{32})/', $conf[0]['text'] ?? '', $tm);
$cf = http('POST', $A['base'] . '/newsletter-confirm.php', ['token' => $tm[1] ?? ''], [$A['origin']]); ok(stripos($cf['body'], 'subscribed') !== false, 'the confirmation link confirms the subscription');
$badtok = http('POST', $A['base'] . '/subscribe.php', ['email' => 'x@example.test', 'consent' => '1', 'form_token' => 'forged'], [$A['origin']]); ok($badtok['code'] !== 200 || stripos($badtok['body'], 'Check your inbox') === false, 'a forged newsletter token does not subscribe anyone');

section('SMTP unreachable: nothing is lost, the visitor is not misled, retries work');
$pdo->exec('DELETE FROM leads'); $r = $leadPost($B, ['email' => 'down@example.test']);
$row = $pdo->query('SELECT * FROM leads ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
ok($r['code'] === 303 && $row && $row['email'] === 'down@example.test', 'the enquiry is stored and the visitor still gets the thank-you page when the mail server is down');
ok($row['notify_status'] === 'failed' && $row['notify_error'] === 'smtp_connect', 'the lead is flagged "failed" with a safe reason code (smtp_connect), visible to the administrator');
$sp = spool($B); ok(count($sp) >= 2, 'the owner notification AND the customer acknowledgement are kept in the spool for retry (' . count($sp) . ' files)');
$sec = (string)@file_get_contents($B['dir'] . '/sec.log'); ok(strpos($sec, 'lead_notification_failed') !== false && strpos($sec, 'mail_failed_spooled') !== false, 'the failure is written to the security log');
ok(strpos($sec, 'down@example.test') === false && stripos($sec, 'password') === false, 'the log contains no email address and no credential');
$jarB = "$tmp/jarB"; $csrfB = token(http('GET', $B['base'] . '/contact.php', null, [], $jarB)['body'], 'csrf');
$cb = http('POST', $B['base'] . '/contact_send.php', ['csrf' => $csrfB, 'name' => 'Down Contact', 'email' => 'dc@example.test', 'subject' => 'Hello', 'message' => 'This message must not be lost while mail is down.', 'company' => ''], [$B['origin']], $jarB);
ok($cb['code'] === 302 && strpos($cb['loc'], 'sent=1') !== false && strpos($cb['loc'], 'err=') === false, 'a contact message saved while mail is down is acknowledged as received (not shown as an error that would make people resubmit)');
$before = count(spool($B));
// retry from cron with a working (mock) transport
$cmd = 'php ' . escapeshellarg($root . '/scripts/mail_spool_flush.php') . ' --force';
$envFlush = array_merge($B['env'], ['AIHUB_MAIL_TRANSPORT' => 'file', 'AIHUB_SMTP_HOST' => '', 'AIHUB_SMTP_PORT' => '']);
$p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $envFlush); $flushOut = stream_get_contents($pipes[1]); proc_close($p);
ok(preg_match('/sent=(\d+)/', $flushOut, $fm) && (int)$fm[1] === $before && count(spool($B)) === 0, 'the cron flush delivers every spooled message and empties the spool (' . trim($flushOut) . ')');
$out = outbox($B); ok(count(to_of($out, 'owner@example.test')) >= 2 && count(to_of($out, 'down@example.test')) === 1 && count(to_of($out, 'dc@example.test')) === 1, 'owner notifications and customer acknowledgements all arrived after the retry');
$row = $pdo->query('SELECT * FROM leads ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC); ok($row['notify_status'] === 'sent', 'the lead is marked "sent" after the retry succeeded');
// nothing is sent twice if the owner already re-sent that lead from the admin screen
$pdo->exec("UPDATE leads SET notify_status='failed' WHERE id=" . (int)$row['id']); $r = $leadPost($B, ['email' => 'down2@example.test']); $lid = (int)$pdo->query('SELECT MAX(id) FROM leads')->fetchColumn();
$pdo->exec("UPDATE leads SET notify_status='sent' WHERE id=" . $lid);   // as if the admin re-sent it
$ownerBefore = count(to_of(outbox($B), 'owner@example.test'));
$p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $envFlush); stream_get_contents($pipes[1]); proc_close($p);
ok(count(to_of(outbox($B), 'owner@example.test')) === $ownerBefore, 'a spooled owner notification is dropped when the lead was already re-sent by the administrator (no duplicate)');

section('Health report exposes no secret');
$p = proc_open('php ' . escapeshellarg($root . '/scripts/mail_health.php'), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, array_merge($A['env'], ['AIHUB_SMTP_USER' => 'SECRET-USER-VALUE', 'AIHUB_SMTP_PASS' => 'SECRET-PASS-VALUE'])); $hr = stream_get_contents($pipes[1]); proc_close($p);
ok(strpos($hr, 'configuration') !== false && strpos($hr, 'SECRET-USER-VALUE') === false && strpos($hr, 'SECRET-PASS-VALUE') === false && strpos($hr, 'owner@example.test') === false, 'mail_health.php prints booleans and counts only: no user name, password or full address');
echo "forms/email e2e: $pass passed, $fail failed\n"; exit($fail ? 1 : 0);
