<?php
declare(strict_types=1);
/**
 * Phase 7 editor / verifier web UI over HTTP (universe-editor.php on top of uni_core.php). Private scratch database p7ed_<pid>, own php -S server on a port in 8240-8260.
 * Covers: authorization matrix (every role and anonymous, every page and POST action), CSRF / same-origin / GET inertness, maker != checker end to end,
 * second verifier, AI-assisted human review path, escaping (XSS payloads, javascript: URLs), hostile input never 500, rate limiting, no enumeration of other users.
 * Usage: php tests/phase7_editor_http_test.php   Env: AIHUB_COMPAT_ROOT_USER / AIHUB_COMPAT_ROOT_PASS / AIHUB_DB_HOST (as the other Phase 7 tests).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
ob_start(); require_once $root . '/crm_core.php'; ob_end_clean();
$dbHost = getenv('AIHUB_DB_HOST') ?: '127.0.0.1';
$dbUser = getenv('AIHUB_COMPAT_ROOT_USER') ?: 'root';
$dbPass = getenv('AIHUB_COMPAT_ROOT_PASS') !== false ? (string)getenv('AIHUB_COMPAT_ROOT_PASS') : 'root_test_only';
$dbName = 'p7ed_' . getmypid();
if (!preg_match('/^p7ed_\d+$/', $dbName)) { fwrite(STDERR, "bad db name\n"); exit(2); }
$opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$admin = new PDO("mysql:host=$dbHost;port=3306", $dbUser, $dbPass, $opt);
$admin->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo = new PDO("mysql:host=$dbHost;port=3306;dbname=$dbName", $dbUser, $dbPass, $opt);
$pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION,ERROR_FOR_DIVISION_BY_ZERO'");
function sqlStatements(string $file): array {
  $delim = ';'; $buf = ''; $out = [];
  foreach (preg_split('/\R/', (string)file_get_contents($file)) as $line) {
    $t = trim($line);
    if ($buf === '' && ($t === '' || str_starts_with($t, '--'))) continue;
    if (preg_match('/^DELIMITER\s+(\S+)$/i', $t, $m)) { $delim = $m[1]; continue; }
    $buf .= $line . "\n";
    if (str_ends_with(rtrim($buf), $delim)) { $out[] = substr(rtrim($buf), 0, -strlen($delim)); $buf = ''; }
  }
  return $out;
}
foreach (['phase6_crm.sql', 'phase7_universe.sql'] as $f) foreach (sqlStatements("$root/database/$f") as $s) $pdo->exec($s);

$rateDir = sys_get_temp_dir() . '/p7ed_rate_' . getmypid(); @mkdir($rateDir, 0700, true); $logFile = sys_get_temp_dir() . '/p7ed_php_' . getmypid() . '.log'; @unlink($logFile);
$port = 0; foreach (range(8240, 8260) as $p) { $s = @stream_socket_server("tcp://127.0.0.1:$p", $en, $es); if ($s) { fclose($s); $port = $p; break; } }
if (!$port) { $admin->exec("DROP DATABASE `$dbName`"); fwrite(STDERR, "no free port in 8240-8260\n"); exit(2); }
$env = array_merge(getenv(), ['AIHUB_DB_HOST' => $dbHost, 'AIHUB_DB_NAME' => $dbName, 'AIHUB_DB_USER' => $dbUser, 'AIHUB_DB_PASS' => $dbPass, 'AIHUB_RATE_DIR' => $rateDir, 'AIHUB_SECURITY_LOG' => sys_get_temp_dir() . '/p7ed_sec_' . getmypid() . '.log']);
$proc = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $logFile, '-d', 'error_reporting=32767', '-d', 'output_buffering=0', '-S', "127.0.0.1:$port", '-t', $root, "$root/tests/router.php"], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, $root, $env);
$base = "http://127.0.0.1:$port"; $own = ['Origin: ' . $base]; $foreign = ['Origin: http://evil.example'];
register_shutdown_function(function () use ($proc, $admin, $dbName, $rateDir) { if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); } $admin->exec("DROP DATABASE IF EXISTS `$dbName`"); foreach (glob($rateDir . '/*') ?: [] as $f) @unlink($f); @rmdir($rateDir); });
for ($i = 0; $i < 50; $i++) { $c = @fsockopen('127.0.0.1', $port); if ($c) { fclose($c); break; } usleep(100000); }

$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; fwrite(STDERR, "  FAIL: $m\n"); } }
function section(string $t): void { fwrite(STDERR, "== $t\n"); if (function_exists('clear_rate')) clear_rate(); }
function http(string $m, string $u, $body = null, array $h = [], ?string $jar = null): array {
  $ch = curl_init($u); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CUSTOMREQUEST => $m, CURLOPT_HTTPHEADER => $h, CURLOPT_FOLLOWLOCATION => false]);
  if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
  if ($jar) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
  $r = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
  return ['code' => $code, 'head' => substr($r, 0, $hs), 'body' => substr($r, $hs), 'loc' => preg_match('/^Location:\s*(.+)$/mi', substr($r, 0, $hs), $mm) ? trim($mm[1]) : ''];
}
function clear_rate(): void { global $rateDir; foreach (glob($rateDir . '/*') ?: [] as $f) @unlink($f); }
function val(string $sql, array $a = []) { global $pdo; $s = $pdo->prepare($sql); $s->execute($a); return $s->fetchColumn(); }
function hidden(string $html, string $n): string { return preg_match('/name="' . preg_quote($n, '/') . '" value="([^"]*)"/', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES) : ''; }
$H = password_hash('test-only-password-1', PASSWORD_DEFAULT); $tables = ['uni_entity', 'uni_event', 'uni_edge', 'uni_release', 'uni_source', 'uni_review', 'uni_audit_log'];
$snapshot = function () use ($tables): string { $o = []; foreach ($tables as $t) $o[] = $t . '=' . val("SELECT COUNT(*) FROM $t") . ':' . val("SELECT COALESCE(SUM(CRC32(CONCAT_WS('|', id))), 0) FROM $t"); $o[] = 'states=' . val("SELECT GROUP_CONCAT(state ORDER BY id) FROM (SELECT id, state FROM uni_entity UNION ALL SELECT id + 100000, state FROM uni_event UNION ALL SELECT id + 200000, state FROM uni_edge UNION ALL SELECT id + 300000, state FROM uni_release) x"); return implode('|', $o); };

// ---- users and sessions --------------------------------------------------------------------------------------------------------------------
$U = [];
foreach (['owner' => 'owner', 'editor1' => 'editor', 'editor2' => 'editor', 'edver' => 'editor', 'verifier1' => 'verifier', 'verifier2' => 'verifier', 'verifier3' => 'verifier', 'sales' => 'sales', 'viewer' => 'viewer'] as $n => $role) {
  [$okU, $id] = crm_user_create($pdo, "u-$n-test", 'Display ' . ucfirst($n) . ' Secretname', $role, $H); if (!$okU) { fwrite(STDERR, "user $n: $id\n"); exit(2); } $U[$n] = ['id' => (int)$id, 'role' => $role, 'name' => $n, 'display' => 'Display ' . ucfirst($n) . ' Secretname'];
}
$tmp = sys_get_temp_dir() . '/p7ed_jars_' . getmypid(); @mkdir($tmp, 0700, true);
$anonJar = "$tmp/anon"; $r = http('GET', "$base/universe-editor.php", null, [], $anonJar); $loginTok = hidden($r['body'], '_login_token'); sleep(2);
$S = [];
foreach ($U as $n => $u) {
  clear_rate(); $jar = "$tmp/$n";
  $r = http('POST', "$base/universe-editor.php", ['act' => 'login', 'username' => "u-$n-test", 'password' => 'test-only-password-1', '_login_token' => $loginTok], $own, $jar);
  $pg = http('GET', "$base/universe-editor.php", null, [], $jar); $S[$n] = ['jar' => $jar, 'csrf' => hidden($pg['body'], '_csrf'), 'role' => $u['role'], 'id' => $u['id']];
  ok($r['code'] === 303 && $S[$n]['csrf'] !== '', "sign-in of $n works with the existing CRM login (HTTP {$r['code']})");
}
clear_rate();
function get(array $s, string $path, array $h = []): array { global $base; return http('GET', $base . $path, null, $h, $s['jar']); }
function post(array $s, array $f, ?array $h = null, bool $csrf = true): array { global $base, $own; if ($csrf && !array_key_exists('_csrf', $f)) $f['_csrf'] = $s['csrf']; return http('POST', "$base/universe-editor.php", $f, $h ?? $own, $s['jar']); }
function item_from(string $loc): array { return preg_match('/[?&]t=(\w+)&id=(\d+)/', $loc, $m) ? [$m[1], (int)$m[2]] : ['', 0]; }
function src(string $tag, array $o = []): array { return $o + ['src_url' => "https://example.test/$tag", 'src_publisher' => 'Example Publisher', 'src_retrieved' => '2025-01-15', 'src_quote' => "Literal quote $tag supports the claim.", 'src_origin' => 'human']; }
function entity_form(string $slug, array $o = []): array { return $o + src($slug) + ['act' => 'propose', 't' => 'entity', 'entity_type' => 'company', 'slug' => $slug, 'name' => "Entity $slug", 'summary' => 'synthetic', 'origin' => 'human']; }
function review(array $s, string $t, int $id, string $dec = 'accept', array $o = []): array { return post($s, $o + ['act' => 'review', 't' => $t, 'id' => $id, 'decision' => $dec, 'note' => 'Checked the original source.', 'opened_source' => '1']); }
function submit_it(array $s, string $t, int $id): array { return post($s, ['act' => 'submit', 't' => $t, 'id' => $id]); }
function publish_entity(string $slug): int { global $S; $r = post($S['editor1'], entity_form($slug)); [, $id] = item_from($r['loc']); submit_it($S['editor1'], 'entity', $id); review($S['verifier1'], 'entity', $id); review($S['verifier2'], 'entity', $id); return $id; }
function leaks(string $body, string $who): bool { global $U; foreach ($U as $n => $u) if ($n !== $who && (strpos($body, "u-$n-test") !== false || strpos($body, $u['display']) !== false)) return true; return false; }
$noindex = fn(array $r) => stripos($r['head'], 'X-Robots-Tag: noindex, nofollow') !== false && stripos($r['head'], 'Cache-Control: no-store') !== false;

// ---- 1. anonymous ------------------------------------------------------------------------------------------------------------------------
section('anonymous access');
$before = $snapshot();
foreach (['home', 'mine', 'new', 'queue', 'overview', 'item&t=entity&id=1'] as $v) { $r = http('GET', "$base/universe-editor.php?v=$v"); ok($r['code'] === 200 && stripos($r['body'], 'CRM sign-in') !== false && strpos($r['body'], 'Editorial workflow sections') === false && $noindex($r), "anonymous GET v=$v shows only the sign-in form, noindex, no-store"); }
foreach (['propose', 'review', 'submit', 'attach_source', 'correct'] as $a) { $r = http('POST', "$base/universe-editor.php", entity_form('anon-x', ['act' => $a, 'id' => 1, 'decision' => 'accept', 'note' => 'n', 'opened_source' => '1']), $own); ok($r['code'] !== 303 && stripos($r['body'], 'CRM sign-in') !== false, "anonymous POST act=$a is not processed"); }
ok($snapshot() === $before, 'anonymous requests changed nothing');

// ---- 2. authorization matrix: pages ------------------------------------------------------------------------------------------------------
section('authorization matrix, pages');
$allow = ['editor1' => ['home', 'mine', 'new'], 'verifier1' => ['home', 'queue'], 'owner' => ['home', 'overview'], 'sales' => [], 'viewer' => []];
foreach ($allow as $who => $pages) foreach (['home', 'mine', 'new', 'queue', 'overview'] as $v) {
  $r = get($S[$who], "/universe-editor.php?v=$v"); $exp = in_array($v, $pages, true) ? 200 : 403;
  ok($r['code'] === $exp && $noindex($r), "$who GET v=$v -> $exp (got {$r['code']}) with noindex and no-store");
  if ($exp === 403) ok(strpos($r['body'], 'name="act" value="propose"') === false && strpos($r['body'], 'name="act" value="review"') === false, "$who sees no action form on the refused page");
}
ok(strpos(get($S['editor1'], '/universe-editor.php?v=home')['body'], 'value="review"') === false, 'the editor home has no review form');
$r = http('PUT', "$base/universe-editor.php", 'x', $own, $S['editor1']['jar']); ok($r['code'] === 405, 'other HTTP methods are refused (405)');
$r = http('GET', "$base/uni_views.php"); ok($r['code'] === 404, 'the library is not directly reachable');

// ---- 3. authorization matrix: POST actions -------------------------------------------------------------------------------------------------
section('authorization matrix, POST');
$before = $snapshot();
foreach (['owner', 'verifier1', 'sales', 'viewer'] as $who) {
  foreach (['propose', 'attach_source', 'submit', 'correct'] as $a) { $r = post($S[$who], entity_form("deny-$who-$a", ['act' => $a, 'id' => 1])); ok($r['code'] === 403, "$who POST $a -> 403 (got {$r['code']})"); }
}
ok($snapshot() === $before, 'refused writes left no trace');
$r = post($S['editor1'], entity_form('matrix-one')); [$t, $E0] = item_from($r['loc']); ok($r['code'] === 303 && $t === 'entity' && $E0 > 0, 'editor POST propose -> 303 to the item page');
$r2 = submit_it($S['editor1'], 'entity', $E0); ok($r2['code'] === 303 && val('SELECT state FROM uni_entity WHERE id = ?', [$E0]) === 'in_review', 'editor submits -> in_review');
foreach (['editor1', 'editor2', 'owner', 'sales', 'viewer'] as $who) { $b = $snapshot(); $r = review($S[$who], 'entity', $E0); ok($r['code'] === 403 && $snapshot() === $b, "$who cannot review (403, no change; got {$r['code']})"); }
ok(val('SELECT COUNT(*) FROM uni_review') == 0, 'no review exists yet');
$r = post($S['verifier1'], ['act' => 'propose'] + entity_form('v-prop')); ok($r['code'] === 403 && val("SELECT COUNT(*) FROM uni_entity WHERE slug = 'v-prop'") == 0, 'a verifier cannot propose');
$r = post($S['editor1'], ['act' => 'bogus', 't' => 'entity']); ok($r['code'] === 403, 'unknown action is refused');

// ---- 4. CSRF, origin, GET inertness -------------------------------------------------------------------------------------------------------
section('CSRF, origin, GET inertness');
$before = $snapshot();
$cases = ['missing token' => [null, $own, false], 'wrong token' => [['_csrf' => str_repeat('a', 32)], $own, true], 'foreign origin' => [[], $foreign, true], 'origin null' => [[], ['Origin: null'], true], 'foreign referer' => [[], ['Origin: ' . $base, 'Referer: http://evil.example/x'], true]];
foreach ($cases as $label => [$extra, $hdr, $withTok]) { $f = entity_form('csrf-' . md5($label)) + ($extra ?? []); $r = post($S['editor1'], $f, $hdr, $withTok); ok($r['code'] === 403, "$label -> 403 (got {$r['code']})"); }
foreach (['verifier1' => 'review', 'editor1' => 'submit'] as $who => $a) { $r = post($S[$who], ['act' => $a, 't' => 'entity', 'id' => $E0, 'decision' => 'accept', 'note' => 'x', 'opened_source' => '1'], $foreign); ok($r['code'] === 403, "foreign origin on $a -> 403"); }
$r = post($S['verifier1'], ['act' => 'review', 't' => 'entity', 'id' => $E0, 'decision' => 'accept', 'note' => 'x', 'opened_source' => '1', '_csrf' => $S['editor1']['csrf']]); ok($r['code'] === 403, "another user's token is rejected");
ok($snapshot() === $before, 'rejected CSRF/origin requests changed nothing');
$qs = http_build_query(entity_form('get-x') + ['v' => 'new', 'decision' => 'accept', 'id' => $E0, 'note' => 'n', 'opened_source' => '1']);
foreach (['editor1', 'verifier1', 'owner'] as $who) { $r = get($S[$who], "/universe-editor.php?$qs&act=review"); ok(in_array($r['code'], [200, 403], true) && $snapshot() === $before, "GET with action parameters is inert for $who"); }
foreach (['review', 'submit', 'propose'] as $a) { get($S['verifier1'], "/universe-editor.php?v=item&t=entity&id=$E0&act=$a&decision=accept"); } ok($snapshot() === $before, 'GET on an item page with act= changes nothing');

// ---- 5. workflow, maker != checker, second verifier -------------------------------------------------------------------------------------
section('maker-checker and second verifier over HTTP');
$page = get($S['verifier1'], "/universe-editor.php?v=item&t=entity&id=$E0"); $pb = $page['body'];
ok($page['code'] === 200 && strpos($pb, 'Literal quote matrix-one supports the claim.') !== false && preg_match('#<a class="btn sm uvOpen" href="https://example.test/matrix-one" target="_blank" rel="noopener noreferrer">Open the original source#', $pb) === 1, 'verifier sees the literal quote and the open-original-source link (https, target=_blank, rel=noopener noreferrer)');
ok(strpos($pb, 'name="opened_source"') !== false && strpos($pb, 'name="decision"') !== false && strpos($pb, 'Verification checklist') !== false, 'the review form has the decision, the checklist and the note');
ok(!leaks($pb, 'verifier1'), 'no other user name or username appears in the verifier page');
$q = get($S['verifier1'], '/universe-editor.php?v=queue'); ok(strpos($q['body'], "id=$E0") !== false && !leaks($q['body'], 'verifier1'), 'the item is in the verifier queue, with no editor identity');
$r = review($S['verifier1'], 'entity', $E0, 'accept', ['note' => '']); ok($r['code'] === 422 && strpos($r['body'], 'A note is mandatory') !== false && strpos($r['body'], 'id="errSummary"') !== false && val('SELECT COUNT(*) FROM uni_review') == 0, 'accept without a note is refused with an error summary');
$r = review($S['verifier1'], 'entity', $E0, 'reject', ['note' => '   ']); ok($r['code'] === 422 && val('SELECT state FROM uni_entity WHERE id = ?', [$E0]) === 'in_review', 'reject without a note is refused');
$r = review($S['verifier1'], 'entity', $E0, 'accept', ['opened_source' => '']); ok($r['code'] === 422 && strpos($r['body'], 'opened the original source') !== false && val('SELECT COUNT(*) FROM uni_review') == 0, 'accept without the checklist confirmation is refused');
$r = review($S['verifier1'], 'entity', $E0, 'maybe'); ok($r['code'] === 422, 'unknown decision refused');
$r = review($S['verifier1'], 'entity', $E0); ok($r['code'] === 303 && strpos($r['loc'], 'm=accepted_waiting') !== false && val('SELECT state FROM uni_entity WHERE id = ?', [$E0]) === 'in_review', 'first accept: the item waits for a second verifier (sampling)');
$pg = get($S['verifier1'], "/universe-editor.php?v=item&t=entity&id=$E0")['body']; ok(strpos($pg, 'You already reviewed this item') !== false && strpos($pg, 'value="review"') === false, 'the same verifier sees no review form again');
$r = review($S['verifier1'], 'entity', $E0); ok($r['code'] === 422 && val("SELECT COUNT(*) FROM uni_review WHERE reviewer_id = ?", [$S['verifier1']['id']]) == 1, 'the same verifier cannot review twice');
$pg = get($S['verifier2'], "/universe-editor.php?v=item&t=entity&id=$E0")['body']; ok(strpos($pg, 'second, different verifier must also accept') !== false || strpos($pg, 'Accepts so far: 1') !== false, 'the second-review requirement is shown');
$q2 = get($S['verifier2'], '/universe-editor.php?v=queue')['body']; ok(strpos($q2, 'Waiting for a second verifier') !== false, 'the queue marks items waiting for a second verifier');
$r = review($S['verifier2'], 'entity', $E0); ok($r['code'] === 303 && strpos($r['loc'], 'm=verified') !== false && val('SELECT state FROM uni_entity WHERE id = ?', [$E0]) === 'verified', 'second distinct verifier completes the verification');
$r = review($S['verifier3'], 'entity', $E0); ok($r['code'] === 422 && val('SELECT COUNT(*) FROM uni_review WHERE target_id = ? AND target_type = ?', [$E0, 'entity']) == 2, 'a verified item cannot be reviewed again');
// own proposals: a user who proposed and later holds the verifier role
$r = post($S['edver'], entity_form('edver-own')); [, $EO] = item_from($r['loc']); submit_it($S['edver'], 'entity', $EO);
$pdo->exec("UPDATE crm_users SET role = 'verifier' WHERE id = " . (int)$S['edver']['id']);
$b = $snapshot(); $r = review($S['edver'], 'entity', $EO); ok($r['code'] === 403 && $snapshot() === $b && val('SELECT state FROM uni_entity WHERE id = ?', [$EO]) === 'in_review', 'a proposer who is now a verifier cannot review their own proposal (server refuses, nothing changes)');
$pg = get($S['edver'], "/universe-editor.php?v=item&t=entity&id=$EO"); ok($pg['code'] === 200 && strpos($pg['body'], 'cannot review it') !== false && strpos($pg['body'], 'value="review"') === false, 'the UI hides the review form for the own proposal');
ok(strpos(get($S['edver'], '/universe-editor.php?v=queue')['body'], "id=$EO") === false, 'the own proposal is not in that verifier queue');
$r = review($S['verifier1'], 'entity', $EO); review($S['verifier2'], 'entity', $EO); ok(val('SELECT state FROM uni_entity WHERE id = ?', [$EO]) === 'verified', 'other verifiers can verify it');
$pdo->exec("UPDATE crm_users SET role = 'editor' WHERE id = " . (int)$S['edver']['id']);

// ---- 6. editor rules, events, duplicates, edges, releases, corrections --------------------------------------------------------------------
section('editor flows');
$ENT = publish_entity('acme-co'); $ENT2 = publish_entity('acme-model'); ok(val('SELECT state FROM uni_entity WHERE id = ?', [$ENT]) === 'verified', 'entities verified via HTTP');
$ev = ['act' => 'propose', 't' => 'event', 'entity_id' => $ENT, 'event_type' => 'launch', 'date_kind' => 'announced', 'event_date' => '2024-05-01', 'title' => 'Acme launches Model One', 'summary' => 's', 'origin' => 'human'] + src('ev1');
$r = post($S['editor1'], $ev); [$t, $EV1] = item_from($r['loc']); ok($r['code'] === 303 && $t === 'event', 'event proposed with its first source in one step');
$r = post($S['editor1'], ['title' => 'acme launches   model one'] + $ev + src('ev2')); [, $EV2] = item_from($r['loc']); $pg = get($S['editor1'], "/universe-editor.php?v=item&t=event&id=$EV2")['body'];
ok(strpos($pg, 'Possible duplicate (flagged, not merged)') !== false, 'the editor sees the duplicate flag');
$r = post($S['editor1'], ['event_date' => '2024-06-01', 'title' => 'Acme launches Model One'] + $ev + src('ev3')); [, $EV3] = item_from($r['loc']); ok(strpos(get($S['editor1'], "/universe-editor.php?v=item&t=event&id=$EV3")['body'], 'Possible date conflict') !== false, 'the editor sees the date-conflict flag');
$r = post($S['editor1'], ['entity_id' => $ENT2, 'title' => 'Other', 'src_url' => 'https://example.test/x y'] + $ev); ok($r['code'] === 422 && strpos($r['body'], 'full http or https address') !== false && strpos($r['body'], 'href="#propose_src_url"') !== false, 'an invalid source URL is refused with an error summary that links to the field');
ok(val("SELECT COUNT(*) FROM uni_event WHERE title = 'Other'") == 0 && val("SELECT COUNT(*) FROM uni_source WHERE url LIKE '%x y%'") == 0, 'the failed proposal left no event and no orphan source (atomic)');
submit_it($S['editor1'], 'event', $EV1); $b = $snapshot();
$r = post($S['editor2'], ['act' => 'attach_source', 't' => 'event', 'id' => $EV1] + src('other')); ok($r['code'] === 403 && $snapshot() === $b, 'another editor cannot attach to an item they did not propose');
$r = submit_it($S['editor2'], 'event', $EV3); ok($r['code'] === 403, 'another editor cannot submit it');
ok(get($S['editor2'], "/universe-editor.php?v=item&t=event&id=$EV3")['code'] === 404, 'another editor cannot open an unverified item they do not own (404)');
$r = post($S['editor1'], ['act' => 'attach_source', 't' => 'event', 'id' => $EV1] + src('ev1b')); ok($r['code'] === 303 && val('SELECT COUNT(*) FROM uni_event_source WHERE event_id = ?', [$EV1]) == 2, 'the proposer attaches a second source');
$r = review($S['verifier1'], 'event', $EV1, 'needs_second_source', ['note' => 'Please find another source.', 'opened_source' => '']); ok($r['code'] === 303 && val('SELECT state FROM uni_event WHERE id = ?', [$EV1]) === 'in_review' && val("SELECT decision FROM uni_review WHERE target_id = ? AND target_type = 'event'", [$EV1]) === 'needs_second_source', 'needs_second_source needs only a note and keeps the item in review');
review($S['verifier2'], 'event', $EV1); review($S['verifier3'], 'event', $EV1); ok(val('SELECT state FROM uni_event WHERE id = ?', [$EV1]) === 'verified', 'the event is verified by two other verifiers');
submit_it($S['editor1'], 'event', $EV3); $r = review($S['verifier1'], 'event', $EV3, 'reject', ['note' => 'Date not supported by the source.', 'opened_source' => '']); ok($r['code'] === 303 && val('SELECT state FROM uni_event WHERE id = ?', [$EV3]) === 'rejected', 'reject with a note works without the accept checklist');
$r = post($S['editor1'], ['act' => 'propose', 't' => 'edge', 'from_entity_id' => $ENT2, 'to_entity_id' => $ENT, 'edge_type' => 'developed_by', 'valid_from' => '', 'valid_to' => '', 'notes' => '', 'origin' => 'human'] + src('edge1')); [$t, $ED] = item_from($r['loc']); ok($r['code'] === 303 && $t === 'edge', 'edge proposed');
$r = post($S['editor1'], ['act' => 'propose', 't' => 'edge', 'from_entity_id' => $ENT, 'to_entity_id' => $ENT, 'edge_type' => 'developed_by', 'origin' => 'human'] + src('edge2')); ok($r['code'] === 422 && strpos($r['body'], 'two different entities') !== false, 'self edge refused');
$r = post($S['editor1'], ['act' => 'propose', 't' => 'release', 'entity_id' => $ENT2, 'version_label' => 'v1.0', 'release_date' => '2024-05', 'event_id' => '', 'origin' => 'human'] + src('rel1')); [$t, $RL] = item_from($r['loc']); ok($r['code'] === 303 && $t === 'release' && (int)val('SELECT source_id FROM uni_release WHERE id = ?', [$RL]) > 0, 'release proposed with its mandatory source');
submit_it($S['editor1'], 'release', $RL); review($S['verifier1'], 'release', $RL); review($S['verifier2'], 'release', $RL); ok(val('SELECT state FROM uni_release WHERE id = ?', [$RL]) === 'verified', 'release verified');
$r = post($S['editor1'], ['act' => 'correct', 't' => 'entity', 'id' => $ENT, 'name' => 'Acme Corporation', 'summary' => '', 'origin' => 'human']); [, $CN] = item_from($r['loc']);
ok($r['code'] === 303 && $CN !== $ENT && val('SELECT state FROM uni_entity WHERE id = ?', [$CN]) === 'proposed' && val('SELECT state FROM uni_entity WHERE id = ?', [$ENT]) === 'verified' && val('SELECT summary FROM uni_entity WHERE id = ?', [$CN]) === 'synthetic', 'a correction is a new draft version; the verified one stays; empty fields keep their value');
$r = post($S['editor1'], ['act' => 'correct', 't' => 'entity', 'id' => $ENT, 'name' => 'Again', 'origin' => 'human']); ok($r['code'] === 422 && strpos($r['body'], 'already waiting') !== false, 'a second pending correction is refused');
$r = post($S['editor1'], ['act' => 'correct', 't' => 'entity', 'id' => $E0 + 9999, 'name' => 'x', 'origin' => 'human']); ok($r['code'] === 404, 'correcting a missing item -> 404');
$r = post($S['editor1'], entity_form('import-x', ['origin' => 'import'])); ok($r['code'] === 422 && val("SELECT COUNT(*) FROM uni_entity WHERE slug = 'import-x'") == 0, 'the UI cannot create an import-origin item');

// ---- 7. AI-assisted ---------------------------------------------------------------------------------------------------------------------------
section('AI-assisted items');
$r = post($S['editor2'], entity_form('ai-item', ['origin' => 'ai_assisted', 'src_origin' => 'ai_assisted'])); [, $AI] = item_from($r['loc']); submit_it($S['editor2'], 'entity', $AI);
foreach (['editor2' => $S['editor2'], 'verifier1' => $S['verifier1'], 'owner' => $S['owner']] as $who => $sess) { $pg = get($sess, "/universe-editor.php?v=item&t=entity&id=$AI")['body']; ok(strpos($pg, 'AI-assisted: human verification required') !== false, "$who sees the AI-assisted label on the item"); }
ok(strpos(get($S['verifier1'], '/universe-editor.php?v=queue')['body'], 'AI-assisted: human verification required') !== false, 'the queue shows the AI-assisted label');
ok(strpos(get($S['verifier1'], "/universe-editor.php?v=item&t=entity&id=$AI")['body'], 'name="human_checked"') !== false && strpos(get($S['verifier1'], "/universe-editor.php?v=item&t=entity&id=$E0")['body'], 'name="human_checked"') === false, 'only AI-assisted items have the extra human-check box');
$b = $snapshot(); $r = review($S['editor2'], 'entity', $AI, 'accept', ['human_checked' => '1']); ok($r['code'] === 403 && $snapshot() === $b, 'the proposer (editor) cannot verify an AI-assisted item');
$r = review($S['verifier1'], 'entity', $AI); ok($r['code'] === 422 && strpos($r['body'], 'checked the AI-assisted claim yourself') !== false && val('SELECT COUNT(*) FROM uni_review WHERE target_id = ? AND target_type = ?', [$AI, 'entity']) == 0, 'accepting an AI-assisted item without the human-check confirmation is refused');
$r = review($S['verifier1'], 'entity', $AI, 'accept', ['human_checked' => '1']); review($S['verifier2'], 'entity', $AI, 'accept', ['human_checked' => '1']); ok(val('SELECT state FROM uni_entity WHERE id = ?', [$AI]) === 'verified' && (int)val('SELECT COUNT(DISTINCT reviewer_id) FROM uni_review WHERE target_id = ? AND target_type = ?', [$AI, 'entity']) === 2, 'AI-assisted item verified only through human reviews by two distinct verifiers');
$r = post($S['editor2'], entity_form('ai-src', ['src_origin' => 'ai_assisted'])); [, $AS] = item_from($r['loc']); ok(strpos(get($S['editor2'], "/universe-editor.php?v=item&t=entity&id=$AS")['body'], 'AI-assisted: human verification required') !== false, 'an AI-assisted SOURCE also labels the item');

// ---- 8. owner read-only -------------------------------------------------------------------------------------------------------------------------
section('owner read-only overview');
$ov = get($S['owner'], '/universe-editor.php?v=overview'); ok($ov['code'] === 200 && strpos($ov['body'], 'Overview (read-only)') !== false && preg_match('/<th scope="row">Entity<\/th><td>\d+<\/td>/', $ov['body']) === 1, 'owner sees counts');
$pg = get($S['owner'], "/universe-editor.php?v=item&t=entity&id=$AI")['body']; ok(strpos($pg, 'name="act" value="review"') === false && strpos($pg, 'name="act" value="submit"') === false && strpos($pg, 'name="act" value="correct"') === false, 'owner item page has no action forms');
ok(!leaks($ov['body'], 'owner'), 'owner overview shows no user identities');
$cnt = (int)val("SELECT COUNT(*) FROM uni_entity WHERE state = 'verified'"); ok(preg_match('/<th scope="row">Entity<\/th><td>\d+<\/td><td>\d+<\/td><td>' . $cnt . '<\/td>/', $ov['body']) === 1, "owner overview verified-entity count equals the database ($cnt)");

// ---- 9. escaping and URL safety -----------------------------------------------------------------------------------------------------------------
section('escaping');
$x1 = '<script>alert(1)</script>'; $x2 = '"><img src=x onerror=alert(2)>'; $x3 = "'; DROP TABLE uni_entity;--<svg/onload=alert(3)>";
$r = post($S['editor1'], entity_form('xss-ent', ['name' => $x1 . $x2, 'summary' => $x3, 'src_publisher' => $x2, 'src_quote' => $x1 . "\n" . $x3, 'src_url' => 'https://example.test/?a=1&b=2']));
[, $XS] = item_from($r['loc']); submit_it($S['editor1'], 'entity', $XS);
foreach (['editor1' => "/universe-editor.php?v=item&t=entity&id=$XS", 'verifier1' => "/universe-editor.php?v=item&t=entity&id=$XS", 'verifier2' => '/universe-editor.php?v=queue', 'owner' => '/universe-editor.php?v=overview', 'editor1b' => '/universe-editor.php?v=mine'] as $who => $path) {
  $b = get($S[rtrim($who, 'b') === 'editor1' ? 'editor1' : $who], $path)['body']; ok(strpos($b, '<script>alert') === false && strpos($b, '<img src=x') === false && strpos($b, '<svg/onload') === false, "no raw payload in $who $path");
}
$b = get($S['verifier1'], "/universe-editor.php?v=item&t=entity&id=$XS")['body']; ok(strpos($b, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false && strpos($b, 'href="https://example.test/?a=1&amp;b=2"') !== false, 'payloads are shown escaped and the URL ampersand is escaped');
$bad = ['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,<script>alert(1)</script>', 'https://example.test/"onmouseover="alert(1)', "https://example.test/'><script>", 'ftp://example.test/x', ' javascript:alert(1)', '//evil.example/x', 'https://', 'https://nodot'];
$b4 = $snapshot(); foreach ($bad as $u) { $r = post($S['editor1'], entity_form('bad-' . substr(md5($u), 0, 6), ['src_url' => $u])); ok($r['code'] === 422 && strpos($r['body'], '<script>alert') === false && strpos($r['body'], '><script>') === false && strpos($r['body'], 'onmouseover="alert') === false, 'unsafe URL refused: ' . substr($u, 0, 30)); }
ok($snapshot() === $b4, 'refused URLs left no rows');
$pdo->exec('ALTER TABLE uni_source DROP CHECK chk_uni_source_url');   // scratch database only: simulate a row that reached the table by another route
$pdo->prepare("UPDATE uni_source SET url = 'javascript:alert(1)' WHERE id = (SELECT source_id FROM uni_entity_source WHERE entity_id = ? LIMIT 1)")->execute([$XS]);
$b = get($S['verifier1'], "/universe-editor.php?v=item&t=entity&id=$XS")['body']; ok(stripos($b, 'href="javascript') === false && strpos($b, 'Link not shown') !== false, 'a javascript: URL that reached the database is never rendered as a link');
$pdo->prepare("UPDATE uni_source SET url = 'https://example.test/restored' WHERE url = 'javascript:alert(1)'")->execute();
$f = entity_form('xss-echo', ['slug' => '"><script>alert(9)</script>']); $r = post($S['editor1'], $f); ok($r['code'] === 422 && strpos($r['body'], '<script>alert(9)') === false && strpos($r['body'], '&quot;&gt;&lt;script&gt;alert(9)') !== false, 'a refused form re-renders the typed value escaped');

// ---- 10. hostile input ---------------------------------------------------------------------------------------------------------------------------
section('hostile input never 500');
$codes = []; $b = $snapshot();
$hostile = [entity_form('h-arr', ['name' => ['a', 'b'], 'slug' => ['x']]), entity_form('h-big', ['name' => str_repeat('A', 100000), 'src_quote' => str_repeat('Q', 200000)]), entity_form('h-utf', ['name' => "bad\xff\xfeutf"]), entity_form('h-nul', ['name' => "nul\0byte", 'slug' => "sl\0ug"]),
  ['act' => 'propose', 't' => ['x'], 'name' => 'a'], ['act' => ['review'], 't' => 'entity'], ['act' => 'review', 't' => 'entity', 'id' => '-1', 'decision' => 'accept'], ['act' => 'review', 't' => 'entity', 'id' => '99999999999999999999', 'decision' => 'accept', 'note' => 'n', 'opened_source' => '1'],
  ['act' => 'review', 't' => 'entity', 'id' => ['1'], 'decision' => ['accept']], ['act' => 'propose', 't' => 'event', 'entity_id' => '1 OR 1=1', 'event_type' => "x' OR '1'='1", 'event_date' => '9999-99-99'] + src('h1'), ['act' => 'propose', 't' => 'edge', 'from_entity_id' => '-5', 'to_entity_id' => '1e3'] + src('h2'),
  ['act' => 'propose', 't' => 'release', 'entity_id' => $ENT, 'version_label' => 'v', 'release_date' => '2024', 'event_id' => 'abc'] + src('h3'), ['act' => 'correct', 't' => 'edge', 'id' => $ED, 'valid_from' => ['x'], 'origin' => ['y']], ['act' => 'attach_source', 't' => 'release', 'id' => $RL] + src('h4'), ['act' => 'submit', 't' => 'nonsense', 'id' => 1]];
foreach ($hostile as $i => $f) { foreach (['editor1', 'verifier1', 'owner'] as $who) { $r = post($S[$who], $f); $codes[$r['code']] = ($codes[$r['code']] ?? 0) + 1; ok($r['code'] < 500, "hostile POST #$i as $who not a 5xx (got {$r['code']})"); } }
foreach (['/universe-editor.php?v[]=x', '/universe-editor.php?v=item&t[]=x&id[]=1', '/universe-editor.php?v=item&t=entity&id=-1', '/universe-editor.php?v=item&t=entity&id=99999999999999999999', '/universe-editor.php?v=item&t=entity%00&id=1', '/universe-editor.php?v=%00%ff', '/universe-editor.php?m[]=x&m=<script>alert(7)</script>', '/universe-editor.php?v=new&t=%3Cscript%3Ealert(7)%3C/script%3E', '/universe-editor.php?v=item&t=entity&id=1%27+OR+1%3D1', '/universe-editor.php?v=home&m=' . str_repeat('A', 9000)] as $path) {
  foreach (['editor1', 'verifier1', 'owner', 'sales'] as $who) { $r = get($S[$who], $path); ok($r['code'] < 500 && strpos($r['body'], '<script>alert') === false, "hostile GET $path as $who -> {$r['code']}"); }
}
$log = is_file($logFile) ? (string)file_get_contents($logFile) : ''; ok(!preg_match('/PHP (Fatal|Warning|Notice|Deprecated|Parse)|Uncaught/i', $log), 'the PHP error log has no fatal, warning, notice or deprecation entries' . (preg_match('/PHP (Fatal|Warning|Notice|Deprecated|Parse)[^\n]*/i', $log, $mm) ? ' (' . $mm[0] . ')' : ''));
ok(val('SELECT COUNT(*) FROM uni_entity') > 0 && (int)val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'uni_entity'") === 1, 'tables intact after the hostile barrage');

// ---- 11. audit trail, no enumeration, rate limit, sign-out ----------------------------------------------------------------------------------
section('audit, enumeration, rate limit, sign-out');
ok((int)val("SELECT COUNT(*) FROM uni_audit_log WHERE action = 'propose' AND actor_id = ?", [$S['editor1']['id']]) > 0 && (int)val("SELECT COUNT(*) FROM uni_audit_log WHERE action IN ('verified','review_accept_first') AND actor_id IN (?, ?)", [$S['verifier1']['id'], $S['verifier2']['id']]) > 0, 'audit rows come from uni_core with the real user ids');
ok((int)val("SELECT COUNT(*) FROM uni_audit_log WHERE actor_id IS NULL AND action <> 'import'") === 0, 'no audit row without an actor');
ok(strpos(implode(' ', array_column($pdo->query('SELECT summary FROM uni_audit_log')->fetchAll(), 'summary')), '@') === false, 'audit summaries hold no e-mail address');
foreach (['verifier1', 'owner', 'editor2'] as $who) { $all = ''; foreach (["/universe-editor.php?v=home", "/universe-editor.php?v=item&t=entity&id=$AI", "/universe-editor.php?v=item&t=entity&id=$E0"] as $p) $all .= get($S[$who], $p)['body']; $other = array_filter(array_keys($U), fn($n) => $n !== $who); $leak = false; foreach ($other as $n) if (strpos($all, "u-$n-test") !== false || strpos($all, $U[$n]['display']) !== false) $leak = true; ok(!$leak, "$who pages do not reveal other users' usernames or display names"); }
clear_rate(); $limited = 0; $firstLimit = 0; for ($i = 1; $i <= 70; $i++) { $r = post($S['editor2'], ['act' => 'bogus_' . $i, 't' => 'entity']); if ($r['code'] === 429) { $limited++; $firstLimit = $firstLimit ?: $i; } }
ok($limited >= 5 && $firstLimit > 50 && $firstLimit <= 62, "writes are rate limited per user (first 429 at request $firstLimit, $limited limited)");
$r = post($S['editor2'], ['act' => 'bogus', 't' => 'entity']); ok(stripos($r['head'], 'Retry-After') !== false || $r['code'] === 429, 'the limiter answers 429');
$r = get($S['editor1'], '/universe-editor.php?v=mine'); ok($r['code'] === 200, 'other users are not affected by one user\'s limit');
clear_rate();
$out = post($S['editor1'], ['act' => 'logout']); $after = get($S['editor1'], '/universe-editor.php?v=mine'); ok($out['code'] === 303 && stripos($after['body'], 'CRM sign-in') !== false, 'sign-out ends the session');
$pdo->exec("UPDATE crm_users SET active = 0 WHERE id = " . (int)$S['verifier3']['id']); $r = get($S['verifier3'], '/universe-editor.php?v=queue'); ok(stripos($r['body'], 'CRM sign-in') !== false, 'a deactivated user is signed out at once');

foreach (glob("$tmp/*") ?: [] as $f) @unlink($f); @rmdir($tmp); @unlink($logFile);
fwrite(STDERR, "\nPASSED: $pass  FAILED: $fail\n"); echo "PASSED: $pass  FAILED: $fail\n"; exit($fail ? 1 : 0);
