<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly (defence in depth if web-server rules are missing)
/** Minimal PHPMailer wrapper using the site's mail settings. Returns false (never throws) when mail is unavailable. */
function mail_configured(): bool {
  $m = (site_cfg()['mail'] ?? []);
  return is_array($m) && !empty($m['smtp_host']) && !empty($m['from_email']);
}

/**
 * Mail transport. Default is SMTP via PHPMailer. With AIHUB_MAIL_TRANSPORT=file every message is written as a JSON file into
 * AIHUB_MAIL_OUTBOX instead of being sent (mock delivery for tests and rehearsals; nothing leaves the machine).
 */
function mail_transport(): string { return strtolower(sec_setting('AIHUB_MAIL_TRANSPORT', 'mail_transport', 'smtp')) === 'file' ? 'file' : 'smtp'; }

function mail_outbox_write(string $to, string $subject, string $html, string $text, string $replyTo, array $headers): bool {
  $dir = sec_setting('AIHUB_MAIL_OUTBOX', 'mail_outbox');
  if ($dir === '' || (!is_dir($dir) && !@mkdir($dir, 0700, true))) return false;
  $name = rtrim($dir, '/') . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
  return @file_put_contents($name, json_encode(['to' => $to, 'subject' => $subject, 'html' => $html, 'text' => $text, 'reply_to' => $replyTo, 'headers' => $headers, 'mock' => true], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false;
}

/**
 * Why the last send_mail() failed, as a short code (never the SMTP server's text, which can contain host names, user names or addresses).
 * Codes: '' (no failure), not_configured, invalid_recipient, library_missing, smtp_connect, smtp_auth, smtp_rejected, smtp_other, outbox_unwritable.
 */
function mail_last_error(?string $set = null): string { static $code = ''; if ($set !== null) $code = $set; return $code; }

function mail_error_label(string $code): string {
  return ['' => '', 'not_configured' => 'Mail is not configured', 'invalid_recipient' => 'The notification address is invalid', 'library_missing' => 'The mail library is not installed',
    'smtp_connect' => 'The mail server could not be reached', 'smtp_auth' => 'The mail server rejected the login', 'smtp_rejected' => 'The mail server rejected the message',
    'smtp_other' => 'The mail server reported an error', 'outbox_unwritable' => 'The mock outbox is not writable'][$code] ?? 'Unknown mail error';
}

/** Maps a PHPMailer exception message to a safe code. */
function mail_classify_error(string $msg): string {
  $m = strtolower($msg);
  if (strpos($m, 'connect') !== false || strpos($m, 'timed out') !== false || strpos($m, 'getaddrinfo') !== false) return 'smtp_connect';
  if (strpos($m, 'authenticate') !== false || strpos($m, 'username') !== false || strpos($m, 'password') !== false) return 'smtp_auth';
  if (strpos($m, 'recipient') !== false || strpos($m, 'data not accepted') !== false || strpos($m, 'invalid address') !== false) return 'smtp_rejected';
  return 'smtp_other';
}

/**
 * Encryption mode actually used for the configured settings. The stored value is never changed; only its interpretation is made forgiving:
 *   'ssl' / 'smtps' / 'implicit'  -> implicit TLS (normally port 465)      'tls' / 'starttls' (any letter case) -> STARTTLS (normally port 587)
 *   '' (empty)                    -> port 465 = implicit TLS, port 587 = STARTTLS, anything else = none (local relays, test sinks)
 * Before, an empty value on port 587 silently disabled STARTTLS, which most providers refuse ("must issue a STARTTLS command first").
 */
function mail_smtp_secure_mode(array $m): string {
  $s = strtolower(trim((string)($m['smtp_secure'] ?? ''))); $port = (int)($m['smtp_port'] ?? 587);
  if (in_array($s, ['ssl', 'smtps', 'implicit', 'tls-implicit'], true)) return 'ssl';
  if (in_array($s, ['tls', 'starttls', 'auto'], true)) return 'tls';
  if ($s === '' || $s === 'none' || $s === 'false' || $s === '0') { return $port === 465 ? 'ssl' : ($port === 587 ? 'tls' : ''); }
  return '';
}

/** Applies host, port, authentication and encryption to a PHPMailer instance (shared by sending and the connection probe). Credentials are only read, never logged. */
function mail_smtp_configure($mail, array $m): void {
  $mail->isSMTP();
  $mail->Host = (string)$m['smtp_host'];
  $mail->Port = (int)($m['smtp_port'] ?? 587);
  $user = (string)($m['smtp_user'] ?? ''); $pass = (string)($m['smtp_pass'] ?? '');
  $mail->SMTPAuth = $user !== '' && $pass !== '';
  $mail->Username = $user; $mail->Password = $pass;
  $mode = mail_smtp_secure_mode($m);
  $mail->SMTPSecure = $mode;
  $mail->SMTPAutoTLS = $mode !== '';
  $mail->Timeout = 12;
}

function send_mail(string $to, string $subject, string $html, string $text = '', string $replyTo = '', array $headers = []): bool {
  mail_last_error('');
  if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { mail_last_error('invalid_recipient'); return false; }
  $subject = preg_replace('/[\r\n]+/', ' ', $subject);
  if (mail_transport() === 'file') { $ok = mail_outbox_write($to, $subject, $html, $text !== '' ? $text : trim(strip_tags($html)), $replyTo, $headers); if (!$ok) mail_last_error('outbox_unwritable'); return $ok; }
  if (!mail_configured()) { mail_last_error('not_configured'); return false; }
  $autoload = __DIR__ . '/vendor/autoload.php';
  if (!is_file($autoload)) { mail_last_error('library_missing'); return false; }
  require_once $autoload;
  $m = site_cfg()['mail'];
  try {
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    mail_smtp_configure($mail, $m);
    $mail->Hostname = (string)parse_url(AIHUB_SITE_URL, PHP_URL_HOST);   // Message-ID and HELO must not be built from the (forgeable) request Host header
    $mail->CharSet = 'UTF-8';
    $mail->setFrom((string)$m['from_email'], (string)($m['from_name'] ?? 'AIHubpedia'));
    $mail->addAddress($to);
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $mail->addReplyTo($replyTo);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    foreach ($headers as $hk => $hv) { if (preg_match('/^[A-Za-z-]+$/', (string)$hk)) $mail->addCustomHeader((string)$hk, preg_replace('/[\r\n]+/', ' ', (string)$hv)); }
    $mail->Body = $html;
    $mail->AltBody = $text !== '' ? $text : trim(strip_tags($html));
    $sent = $mail->send();
    if (!$sent) mail_last_error(mail_classify_error((string)$mail->ErrorInfo));
    return $sent;
  } catch (Throwable $e) {
    mail_last_error(mail_classify_error($e->getMessage()));
    return false;
  }
}

function site_owner_email(): string { return (string)((site_cfg()['mail'] ?? [])['to_email'] ?? ''); }

// ---------------------------------------------------------------------------------------------------------------------------------------------------
// Reliable delivery: nothing is silently lost. When a send fails the message is kept in a private spool and retried by scripts/mail_spool_flush.php (cron).
// The spool lives in data/mail_spool (data/ is blocked from the web by .htaccess/router), directory mode 0700, files 0600. Successful retries delete the file;
// messages that exhaust their retries move to dead/ and stay there for the owner. No SMTP password is ever written to the spool or the logs.
// ---------------------------------------------------------------------------------------------------------------------------------------------------
function mail_spool_dir(): string { $d = sec_setting('AIHUB_MAIL_SPOOL', 'mail_spool'); return $d !== '' ? rtrim($d, '/') : __DIR__ . '/data/mail_spool'; }
function mail_spool_max_attempts(): int { return 12; }
/** Minutes to wait before attempt N+1 (attempt N just failed). */
function mail_spool_backoff(int $attempts): int { return [5, 15, 60, 180, 360, 720, 1440][min(max($attempts, 1), 7) - 1]; }

function mail_spool_add(string $to, string $subject, string $html, string $text, string $replyTo, array $headers, array $meta, string $error): bool {
  $dir = mail_spool_dir();
  if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false;
  @chmod($dir, 0700);
  $name = $dir . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
  $row = ['to' => $to, 'subject' => $subject, 'html' => $html, 'text' => $text, 'reply_to' => $replyTo, 'headers' => $headers, 'meta' => $meta, 'created' => time(), 'attempts' => 0, 'next_try' => time() + 300, 'last_error' => $error];
  $ok = @file_put_contents($name, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
  if ($ok) @chmod($name, 0600);
  return $ok;
}

/**
 * send_mail() plus a safety net: when delivery fails (and the address itself is valid) the message is spooled for retry.
 * Returns true only when the message was delivered now. $meta identifies the record ('kind' => owner|customer|contact|submission|newsletter, 'lead_id', 'ref').
 */
function send_mail_reliable(string $to, string $subject, string $html, string $text = '', string $replyTo = '', array $headers = [], array $meta = []): bool {
  if (send_mail($to, $subject, $html, $text, $replyTo, $headers)) return true;
  $err = mail_last_error();
  if ($err === 'invalid_recipient') { sec_log('mail_not_sent', ['kind' => $meta['kind'] ?? '', 'reason' => $err]); return false; }
  $spooled = mail_spool_add($to, preg_replace('/[\r\n]+/', ' ', $subject), $html, $text !== '' ? $text : trim(strip_tags($html)), $replyTo, $headers, $meta, $err);
  sec_log('mail_failed_spooled', ['kind' => $meta['kind'] ?? '', 'ref' => $meta['ref'] ?? '', 'reason' => $err, 'spooled' => $spooled ? 1 : 0]);
  mail_last_error($err);   // the caller still sees why the first attempt failed
  return false;
}

/**
 * Retries spooled messages that are due. @return array{sent:int,failed:int,dead:int,waiting:int}
 * $sender is injectable for tests (default: send_mail). $afterSent receives ($meta) so the caller can update lead tracking; $stillNeeded($meta) returning false
 * drops a message that no longer needs sending (for example the owner already re-sent that lead from the admin screen), so nobody gets a duplicate.
 */
function mail_spool_flush(int $limit = 50, ?int $now = null, ?callable $sender = null, ?callable $afterSent = null, ?callable $stillNeeded = null, bool $force = false): array {
  $now = $now ?? time(); $out = ['sent' => 0, 'failed' => 0, 'dead' => 0, 'waiting' => 0];
  $dir = mail_spool_dir(); if (!is_dir($dir)) return $out;
  $files = glob($dir . '/*.json') ?: []; sort($files); $sender = $sender ?? 'send_mail'; $n = 0;
  foreach ($files as $f) {
    if ($n >= $limit) break;
    $fh = @fopen($f, 'c+'); if (!$fh) continue;
    if (!flock($fh, LOCK_EX | LOCK_NB)) { fclose($fh); continue; }
    $row = json_decode((string)stream_get_contents($fh), true);
    $rto = is_array($row) ? (string)(($row['to'] ?? '') !== '' ? $row['to'] : site_owner_email()) : '';   // owner mails spooled while no owner address was configured use the address configured now
    if (!is_array($row) || $rto === '') { $out['waiting']++; flock($fh, LOCK_UN); fclose($fh); continue; }
    if (!$force && ($row['next_try'] ?? 0) > $now) { $out['waiting']++; flock($fh, LOCK_UN); fclose($fh); continue; }
    if ($stillNeeded && !$stillNeeded((array)($row['meta'] ?? []))) { flock($fh, LOCK_UN); fclose($fh); @unlink($f); continue; }
    $n++;
    $ok = false; try { $ok = (bool)$sender($rto, (string)$row['subject'], (string)$row['html'], (string)($row['text'] ?? ''), (string)($row['reply_to'] ?? ''), (array)($row['headers'] ?? [])); } catch (Throwable $e) { mail_last_error('smtp_other'); }
    if ($ok) {
      $out['sent']++; if ($afterSent) { try { $afterSent((array)($row['meta'] ?? [])); } catch (Throwable $e) { sec_log_exception($e, 'mail_spool_after_sent'); } }
      sec_log('mail_spool_sent', ['kind' => $row['meta']['kind'] ?? '', 'attempts' => (int)$row['attempts'] + 1]);
      flock($fh, LOCK_UN); fclose($fh); @unlink($f); continue;
    }
    $row['attempts'] = (int)($row['attempts'] ?? 0) + 1; $row['last_error'] = mail_last_error();
    $dead = $row['attempts'] >= mail_spool_max_attempts() || ($now - (int)($row['created'] ?? $now)) > 7 * 86400;
    $row['next_try'] = $now + mail_spool_backoff($row['attempts']) * 60;
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); fflush($fh); flock($fh, LOCK_UN); fclose($fh);
    if ($dead) {
      $dd = $dir . '/dead'; if (!is_dir($dd)) @mkdir($dd, 0700, true);
      if (@rename($f, $dd . '/' . basename($f))) { $out['dead']++; sec_log('mail_spool_dead', ['kind' => $row['meta']['kind'] ?? '', 'ref' => $row['meta']['ref'] ?? '', 'reason' => $row['last_error']]); }
    } else $out['failed']++;
  }
  return $out;
}

/** Counts for health checks. */
function mail_spool_counts(): array {
  $dir = mail_spool_dir();
  return ['pending' => is_dir($dir) ? count(glob($dir . '/*.json') ?: []) : 0, 'dead' => is_dir($dir . '/dead') ? count(glob($dir . '/dead/*.json') ?: []) : 0];
}

/** Configuration report with booleans only: no password, user name or full address is ever included. */
function mail_config_report(): array {
  $m = (site_cfg()['mail'] ?? []); $to = (string)($m['to_email'] ?? ''); $at = strpos($to, '@');
  $dir = mail_spool_dir(); $parent = is_dir($dir) ? $dir : dirname($dir);
  return [
    'transport' => mail_transport(), 'smtp_host_set' => !empty($m['smtp_host']), 'smtp_host' => (string)($m['smtp_host'] ?? ''), 'smtp_port' => (int)($m['smtp_port'] ?? 0), 'smtp_secure' => (string)($m['smtp_secure'] ?? ''),
    'smtp_user_set' => !empty($m['smtp_user']), 'smtp_pass_set' => !empty($m['smtp_pass']), 'from_set' => !empty($m['from_email']),
    'to_set' => $to !== '', 'to_valid' => (bool)filter_var($to, FILTER_VALIDATE_EMAIL), 'to_domain' => $at !== false ? substr($to, $at + 1) : '',
    'phpmailer_installed' => is_file(__DIR__ . '/vendor/autoload.php'), 'spool_dir_writable' => is_writable($parent), 'spool' => mail_spool_counts(),
    'customer_confirmation' => mail_customer_confirmation_enabled(),
  ];
}
/**
 * Which layer supplies each mail setting. NEVER returns a value, only the source label.
 * Effective precedence (highest first):  environment variable (AIHUB_SMTP_*, AIHUB_MAIL_*)  >  config.local.php ['mail'=>[...]]  >  database site_settings 'mail' row
 * (if the site_settings table has ANY rows, data/site.json is ignored completely)  >  data/site.json.
 * @return array<string,string> setting => env|config.local.php|database|site.json|unset
 */
function mail_config_sources(): array {
  $map = ['smtp_host' => 'AIHUB_SMTP_HOST', 'smtp_port' => 'AIHUB_SMTP_PORT', 'smtp_secure' => 'AIHUB_SMTP_SECURE', 'smtp_user' => 'AIHUB_SMTP_USER', 'smtp_pass' => 'AIHUB_SMTP_PASS', 'from_email' => 'AIHUB_MAIL_FROM', 'to_email' => 'AIHUB_MAIL_TO'];
  $dbMail = null; $dbRows = false;
  try { if (db_table_exists('site_settings')) { $rows = db()->query("SELECT setting_key, setting_value FROM site_settings")->fetchAll(); $dbRows = (bool)$rows; foreach ($rows as $r) { if ((string)$r['setting_key'] === 'mail') { $d = json_decode((string)$r['setting_value'], true); $dbMail = is_array($d) ? $d : []; } } } } catch (Throwable $e) { }
  $json = read_json('site.json', []); $jsonMail = (isset($json['mail']) && is_array($json['mail'])) ? $json['mail'] : [];
  $baseMail = $dbRows ? ($dbMail ?? []) : $jsonMail; $baseName = $dbRows ? 'database' : 'site.json';
  $local = sec_local_config(); $localMail = (isset($local['mail']) && is_array($local['mail'])) ? $local['mail'] : [];
  $out = [];
  foreach ($map as $k => $env) {
    $ev = getenv($env);
    if ($ev !== false && trim($ev) !== '') $out[$k] = 'env';
    elseif (isset($localMail[$k]) && is_scalar($localMail[$k]) && trim((string)$localMail[$k]) !== '') $out[$k] = 'config.local.php';
    elseif (isset($baseMail[$k]) && is_scalar($baseMail[$k]) && trim((string)$baseMail[$k]) !== '') $out[$k] = $baseName;
    else $out[$k] = 'unset';
  }
  $out['_site_settings_rows_present'] = $dbRows ? 'yes (site.json mail block is IGNORED)' : 'no (site.json is used)';
  $out['_database_mail_row'] = $dbMail === null ? 'absent' : 'present';
  $out['_json_mail_block'] = $jsonMail ? 'present' : 'absent';
  $out['_mail_transport_source'] = (getenv('AIHUB_MAIL_TRANSPORT') !== false && trim((string)getenv('AIHUB_MAIL_TRANSPORT')) !== '') ? 'env' : (isset($local['mail_transport']) ? 'config.local.php' : 'default(smtp)');
  $out['_effective_encryption'] = mail_smtp_secure_mode((array)(site_cfg()['mail'] ?? [])) ?: 'none';
  return $out;
}
function mail_customer_confirmation_enabled(): bool { return sec_setting('AIHUB_MAIL_CUSTOMER_CONFIRM', 'mail.customer_confirm', '1') !== '0'; }

/** Connects and authenticates to the SMTP server WITHOUT sending any message. @return string 'ok' or a safe error code (see mail_error_label()). */
function mail_smtp_probe(): string {
  if (!mail_configured()) return 'not_configured';
  $autoload = __DIR__ . '/vendor/autoload.php'; if (!is_file($autoload)) return 'library_missing';
  require_once $autoload; $m = site_cfg()['mail'];
  try {
    $mail = new PHPMailer\PHPMailer\PHPMailer(true); mail_smtp_configure($mail, $m);
    $ok = $mail->smtpConnect(); $mail->smtpClose();
    return $ok ? 'ok' : 'smtp_other';
  } catch (Throwable $e) { return mail_classify_error($e->getMessage()); }
}
