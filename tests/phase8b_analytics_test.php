<?php
/**
 * Phase 8B analytics test (scratch DB p8b_<pid>, php -S on 8400-8419, SYNTHETIC rows only): migration + rollback, rollup numbers checked by hand, honest "not installed / no data / before tracking started"
 * states, separation of incompatible metrics (no click-through rate), owner-only access, safe CSV, hostile parameters, bot filtering of new outbound-click events, scale, browser/axe.
 * Usage: php tests/phase8b_analytics_test.php   (env: AIHUB_COMPAT_ROOT_USER/PASS, AIHUB_DB_HOST; optional AXE_PATH + playwright for the browser part)
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
ob_start(); require_once $root . '/crm_core.php'; require_once $root . '/adm_analytics.php'; ob_end_clean();
$dsnBase = getenv('P7_DSN_BASE') ?: 'mysql:host=' . (getenv('AIHUB_DB_HOST') ?: '127.0.0.1') . ';port=3306';
$user = getenv('P7_USER') ?: (getenv('AIHUB_COMPAT_ROOT_USER') ?: 'aihub_test');
$pw = getenv('P7_PASS') !== false ? getenv('P7_PASS') : (getenv('AIHUB_COMPAT_ROOT_PASS') !== false ? getenv('AIHUB_COMPAT_ROOT_PASS') : 'test_only_pw');
$dbHost = getenv('AIHUB_DB_HOST') ?: '127.0.0.1'; $db = 'p8b_' . getmypid();
$opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$admin = new PDO($dsnBase, $user, $pw, $opt); $admin->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo = new PDO($dsnBase . ";dbname=$db", $user, $pw, $opt);
$pass = 0; $fail = 0; $proc = null; $tmp = sys_get_temp_dir() . '/p8b_' . getmypid(); @mkdir("$tmp/rate", 0775, true);
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; fwrite(STDERR, "  FAIL: $m\n"); } }
function section(string $t): void { fwrite(STDERR, "== $t\n"); }
register_shutdown_function(function () use ($admin, $db, &$proc, $tmp) { if (is_resource($proc)) { @proc_terminate($proc); @proc_close($proc); } try { $admin->exec("DROP DATABASE IF EXISTS `$db`"); } catch (Throwable $e) {} shell_exec('rm -rf ' . escapeshellarg($tmp)); });
function sqlStatements(string $file): array { $d = ';'; $b = ''; $o = []; foreach (preg_split('/\R/', (string)file_get_contents($file)) as $l) { $t = trim($l); if ($b === '' && ($t === '' || str_starts_with($t, '--'))) continue; if (preg_match('/^DELIMITER\s+(\S+)$/i', $t, $m)) { $d = $m[1]; continue; } $b .= $l . "\n"; if (str_ends_with(rtrim($b), $d)) { $o[] = substr(rtrim($b), 0, -strlen($d)); $b = ''; } } return $o; }
function applyFile(PDO $p, string $f): void { foreach (sqlStatements($f) as $s) $p->exec($s); }
foreach (['phase2_analytics.sql', 'phase3_business.sql', 'phase6_crm.sql'] as $f) applyFile($pdo, "$root/database/$f");
$pdo->exec('CREATE TABLE IF NOT EXISTS tool_clicks (id INT AUTO_INCREMENT PRIMARY KEY, tool_slug VARCHAR(255) NOT NULL, visitor_id CHAR(32) NULL, ip_hash VARCHAR(64) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_tc (tool_slug))');

section('migration: additive, idempotent, reversible, existing tables untouched');
$cols = fn() => $pdo->query("SELECT TABLE_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('page_views','events','tool_clicks','crm_leads') GROUP BY TABLE_NAME")->fetchAll(PDO::FETCH_KEY_PAIR);
$before = $cols(); ok(!adma_storage_ready($pdo), 'storage is reported as not installed before the migration');
applyFile($pdo, "$root/database/phase8b_analytics.sql"); applyFile($pdo, "$root/database/phase8b_analytics.sql");
ok(adma_storage_ready($pdo) && (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'adm\\_%'")->fetchColumn() === 5, 'five adm_* tables exist after applying twice');
ok($cols() === $before, 'page_views, events, tool_clicks and crm_leads have exactly the same columns');
applyFile($pdo, "$root/database/phase8b_rollback.sql"); ok(!adma_storage_ready($pdo) && (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'adm\\_%'")->fetchColumn() === 0 && $cols() === $before, 'rollback removes only the five tables');
applyFile($pdo, "$root/database/phase8b_analytics.sql");

section('rollup numbers (hand-computed from synthetic rows)');
$D1 = gmdate('Y-m-d', time() - 86400); $D0 = gmdate('Y-m-d');
$pv = $pdo->prepare('INSERT INTO page_views (viewed_at, day, path, page_type, status, referrer_class, referrer_host, country, visitor_hash, is_bot, bot_type, bot_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
$view = fn(string $t, string $path, string $ptype, int $st, string $ref, string $host, string $cc, string $hash, int $bot = 0) => $pv->execute(["$D1 $t", $D1, $path, $ptype, $st, $ref, $host, $cc, $hash, $bot, $bot ? 'search' : '', $bot ? 'Googlebot' : '']);
$A = 'aaaaaaaaaaaaaaaa'; $B = 'bbbbbbbbbbbbbbbb';
$view('10:00:00', '/', 'home', 200, 'search', 'google.com', 'DE', $A); $view('10:10:00', '/tool.php?id=alpha', 'tool', 200, 'internal', '', 'DE', $A); $view('11:00:00', '/tool.php?id=alpha', 'tool', 200, 'internal', '', 'DE', $A);
$view('12:00:00', '/tool.php?id=beta', 'tool', 200, 'direct', '', 'FR', $B); $view('12:05:00', '/', 'home', 200, 'direct', '', 'XX', ''); $view('12:06:00', '/missing', 'not_found', 404, 'direct', '', 'XX', $B);
foreach (['13:00:00', '13:00:01', '13:00:02'] as $t) $view($t, '/', 'home', 200, 'direct', '', 'XX', '', 1);
$ev = $pdo->prepare('INSERT INTO events (occurred_at, day, name, page_type, ref, prop1, prop2, visitor_hash, returning_user) VALUES (?,?,?,?,?,?,?,?,0)');
$event = fn(string $name, string $ref, string $p1, string $p2, string $hash) => $ev->execute(["$D1 12:30:00", $D1, $name, '', $ref, $p1, $p2, $hash]);
$event('outbound_click', 'alpha', 'yes', 'sponsored', $A); $event('outbound_click', 'alpha', 'no', 'organic', $A); $event('outbound_click', 'beta', 'no', 'organic', $B);
$event('lead_submit', 'advertiser', '', '', $A); $event('newsletter_signup', '', '', '', $B); $event('newsletter_signup', '', '', '', '');
for ($i = 0; $i < 5; $i++) $pdo->prepare('INSERT INTO tool_clicks (tool_slug, visitor_id, ip_hash) VALUES (?,?,?)')->execute(['alpha', 'v', 'h']);
$r = adma_rollup_range($pdo, $D1, $D0); ok($r['days'] === 2 && $r['rows'] > 0, 'rollup of two days runs');
$t = $pdo->prepare('SELECT * FROM adm_traffic_daily WHERE day = ?'); $t->execute([$D1]); $row = $t->fetch();
ok((int)$row['views_human'] === 5 && (int)$row['views_bot'] === 3 && (int)$row['views_no_id'] === 1 && (int)$row['views_error'] === 1, 'human views 5, bot views 3 (excluded), views without id 1, error views 1 (' . json_encode($row) . ')');
ok((int)$row['visitors'] === 2, 'unique visitors that day = 2 distinct hashes (bots, opt-outs and the 404 are not counted)');
ok((int)$row['sessions'] === 3, 'approximate sessions = 3 (A has a 50 minute gap, B one view)');
$t->execute([$D0]); ok($t->fetch() === false, 'a day without any rows gets no row (unavailable is not turned into zeros)');
$tl = $pdo->query("SELECT * FROM adm_tool_daily WHERE day = '$D1' ORDER BY tool_slug")->fetchAll();
ok(count($tl) === 2 && (int)$tl[0]['tool_views'] === 2 && (int)$tl[0]['outbound_clicks'] === 2 && (int)$tl[0]['affiliate_clicks'] === 1 && (int)$tl[0]['sponsored_clicks'] === 1 && (int)$tl[1]['tool_views'] === 1 && (int)$tl[1]['outbound_clicks'] === 1, 'tool rows: alpha 2 views / 2 clicks (1 affiliate, 1 sponsored), beta 1 / 1');
$eo = $pdo->query("SELECT events, visitors FROM adm_event_daily WHERE day = '$D1' AND name = 'outbound_click' AND ref = ''")->fetch(); ok((int)$eo['events'] === 3 && (int)$eo['visitors'] === 2, 'outbound click events: 3 events from 2 visitors');
$at = $pdo->query("SELECT dim, dim_key, views FROM adm_traffic_dim_daily WHERE day = '$D1' AND dim LIKE 'attr:%' ORDER BY dim, dim_key")->fetchAll();
$atm = []; foreach ($at as $a) $atm[$a['dim'] . '|' . $a['dim_key']] = (int)$a['views'];
ok(($atm['attr:lead_submit|search'] ?? 0) === 1 && ($atm['attr:newsletter_signup|direct'] ?? 0) === 1 && ($atm['attr:newsletter_signup|(no visitor id)'] ?? 0) === 1, 'attribution: lead -> first referrer class that day (search); signups -> direct and "(no visitor id)" (' . json_encode($atm) . ')');
$pg = $pdo->query("SELECT SUM(views) FROM adm_traffic_dim_daily WHERE day = '$D1' AND dim = 'page'")->fetchColumn(); ok((int)$pg === 5, 'the pages dimension reconciles to the human views');
$cnt = (int)$pdo->query('SELECT (SELECT COUNT(*) FROM adm_traffic_dim_daily) + (SELECT COUNT(*) FROM adm_tool_daily) + (SELECT COUNT(*) FROM adm_event_daily) + (SELECT COUNT(*) FROM adm_traffic_daily)')->fetchColumn(); adma_rollup_range($pdo, $D1, $D0); ok($cnt === (int)$pdo->query('SELECT (SELECT COUNT(*) FROM adm_traffic_dim_daily) + (SELECT COUNT(*) FROM adm_tool_daily) + (SELECT COUNT(*) FROM adm_event_daily) + (SELECT COUNT(*) FROM adm_traffic_daily)')->fetchColumn(), 'running the rollup again changes nothing (idempotent)');
ok((int)$pdo->query('SELECT COUNT(*) FROM page_views')->fetchColumn() === 9 && (int)$pdo->query('SELECT COUNT(*) FROM events')->fetchColumn() === 6, 'the raw tables are untouched by the rollup');

section('ranges, CSV safety');
$now = strtotime('2026-03-15 12:00:00 UTC'); $rg = fn(array $g) => adma_range($g, $now);
ok($rg(['range' => 'today'])['from'] === '2026-03-15' && $rg(['range' => 'yesterday'])['from'] === '2026-03-14' && $rg(['range' => '7d'])['from'] === '2026-03-09' && $rg(['range' => '30d'])['from'] === '2026-02-14' && $rg([])['key'] === '7d', 'presets: today, yesterday, 7 and 30 days, default 7 days');
ok($rg(['range' => 'custom', 'from' => '2026-03-01', 'to' => '2026-03-10'])['key'] === 'custom' && $rg(['range' => 'custom', 'from' => '2026-03-01', 'to' => '2026-03-10'])['error'] === null, 'a valid custom range is accepted');
foreach ([['2026-02-30', '2026-03-10'], ['2026-03-10', '2026-03-01'], ['2026-03-01', '2026-03-16'], ['2024-01-01', '2026-03-10'], ["2026-03-01' OR 1=1", '2026-03-10'], ['x', 'y'], [['a'], 'b']] as [$f, $to]) { $x = $rg(['range' => 'custom', 'from' => $f, 'to' => $to]); ok($x['key'] === '7d' && $x['error'] !== null, 'an invalid custom range falls back with a message: ' . json_encode($f)); }
ok(strpos(adma_csv_cell('=HYPERLINK("x")'), '"\'=HYPERLINK') === 0 && strpos(adma_csv_cell('+1'), '"\'+1') === 0 && strpos(adma_csv_cell('-1'), '"\'-1') === 0 && strpos(adma_csv_cell('@SUM(A1)'), '"\'@SUM') === 0 && adma_csv_cell('a"b') === '"a""b"' && adma_csv_cell("l1\nl2") === '"l1 l2"', 'CSV cells: formulas neutralised, quotes doubled, newlines flattened');

// ---- HTTP ----
$H = password_hash('analytics-test-pw-1', PASSWORD_DEFAULT);
$mk = function (string $n, string $r) use ($pdo, $H): void { [$o, $id] = crm_user_create($pdo, $n, ucfirst($n), $r, $H); if (!$o) throw new RuntimeException("user $n"); };
foreach (['owner', 'sales', 'viewer', 'editor'] as $r) $mk("t8b$r", $r);
$port = 8400; for (; $port < 8420; $port++) { $s = @stream_socket_server("tcp://127.0.0.1:$port"); if ($s) { fclose($s); break; } }
$env = ['AIHUB_DB_HOST' => $dbHost, 'AIHUB_DB_NAME' => $db, 'AIHUB_DB_USER' => $user, 'AIHUB_DB_PASS' => $pw, 'AIHUB_FORM_SECRET' => 'p8b-test-secret-not-for-production', 'AIHUB_SECURITY_LOG' => "$tmp/sec.log", 'AIHUB_RATE_DIR' => "$tmp/rate", 'AIHUB_ANALYTICS' => '1', 'AIHUB_ANALYTICS_SALT' => 'p8b-test-salt'];
$proc = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root, "$root/tests/router.php"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp, $root, $env + getenv());
for ($i = 0; $i < 60; $i++) { usleep(100000); if (@fsockopen('127.0.0.1', $port)) break; }
$base = "http://127.0.0.1:$port"; $own = ["Origin: $base"];
function http(string $m, string $u, $body = null, array $h = [], ?string $jar = null): array {
  $ch = curl_init($u); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CUSTOMREQUEST => $m, CURLOPT_HTTPHEADER => $h, CURLOPT_FOLLOWLOCATION => false]);
  if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
  if ($jar) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
  $r = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
  return ['code' => $code, 'head' => substr($r, 0, $hs), 'body' => substr($r, $hs), 'loc' => preg_match('/^Location:\s*(.+)$/mi', substr($r, 0, $hs), $mm) ? trim($mm[1]) : ''];
}
function tok(string $html, string $name): string { return preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $m) ? html_entity_decode($m[1]) : ''; }
function login(string $base, array $own, string $username): string {
  global $tmp; foreach ((array)glob("$tmp/rate/*") as $f) @unlink($f);
  $jar = tempnam(sys_get_temp_dir(), 'p8b'); $p = http('GET', "$base/admin-center.php", null, [], $jar); $t = tok($p['body'], '_login_token'); usleep(1300000);
  http('POST', "$base/admin-center.php", ['act' => 'login', 'username' => $username, 'password' => 'analytics-test-pw-1', '_login_token' => $t], $own, $jar); return $jar;
}
$jars = []; foreach (['owner', 'sales', 'viewer', 'editor'] as $r) $jars[$r] = login($base, $own, "t8b$r");
$page = fn(string $r, string $q = '') => http('GET', "$base/admin-center.php?m=analytics$q", null, [], $jars[$r]);

section('authorization: owner only, anonymous gets nothing');
foreach (['sales', 'viewer', 'editor'] as $r) { $x = $page($r); ok($x['code'] === 403 && stripos($x['body'], 'Page views (human)') === false, "$r is refused the analytics page"); $x = $page($r, '&export=daily'); ok($x['code'] === 403 && strpos($x['head'], 'text/csv') === false, "$r cannot export"); }
$x = http('GET', "$base/admin-center.php?m=analytics"); ok(stripos($x['body'], 'Page views (human)') === false && stripos($x['body'], 'sign-in') !== false, 'anonymous sees only the sign-in form');
$x = http('GET', "$base/admin-center.php?m=analytics&export=daily"); ok(strpos($x['head'], 'text/csv') === false && stripos($x['body'], 'date_utc') === false, 'anonymous cannot export');

section('report content and honest definitions');
$x = $page('owner', '&range=custom&from=' . $D1 . '&to=' . $D0); $b = $x['body']; ok($x['code'] === 200, 'owner opens the report');
$flat = preg_replace('/\s+/', ' ', preg_replace('/<[^>]+>/', ' ', $b));
ok(stripos($flat, 'Page views (human) 5') !== false && stripos($flat, 'Visitor-days 2') !== false && stripos($flat, 'Sessions (approximate) 3') !== false && stripos($flat, 'Bot views (not counted) 3') !== false && stripos($flat, 'Views without visitor id 1') !== false, 'the five headline figures match the hand-computed values');
ok(stripos($flat, 'NOT the number of different people') !== false, 'visitor-days are explained as not being people');
$sx = $page('owner', '&range=yesterday')['body']; ok(stripos(preg_replace('/\s+/', ' ', preg_replace('/<[^>]+>/', ' ', $sx)), 'Unique visitors (that day) 2') !== false, 'a single day says "Unique visitors (that day)", not visitor-days');
ok(preg_match('/click-through|\bCTR\b/i', preg_replace('/no click-through rate is calculated/i', '', $flat)) === 0 && stripos($flat, 'no click-through rate is calculated') !== false && stripos($flat, 'Listing-card clicks are not tracked') !== false || stripos($flat, 'Clicks on listing cards are not tracked') !== false, 'no click-through rate is shown; listing clicks are stated as not tracked');
ok(preg_match('/alpha\s*(?:<\/th>\s*)?<td>2<\/td>\s*<td>2<\/td>/i', $b) === 1 || (stripos($flat, 'alpha 2 2 1 1') !== false), 'tool row: alpha 2 detail views, 2 outbound click events, 1 affiliate, 1 sponsored');
ok(stripos($flat, 'Lifetime tool popularity counter') !== false && preg_match('/alpha\s*5\b/', $flat) === 1, 'the lifetime counter (5) is a separate table, not added to the 2 click events');
ok(stripos($flat, 'No member registration feature') !== false || stripos($flat, 'no member registration feature') !== false, 'registrations are stated as not existing');
ok(stripos($flat, 'Tracking data starts on ' . $D1) !== false, 'the start of tracking data is shown');
ok(stripos($flat, 'Newsletter sign-ups 2') !== false && stripos($flat, 'Inquiry forms submitted 1') !== false, 'tracked conversion events are listed');
ok(stripos($flat, 'No CRM inquiries were created') !== false, 'CRM inquiries are a separate source (none here)');
ok(stripos($flat, '(no visitor id)') !== false && stripos($flat, 'search') !== false, 'attribution shows referrer classes and the "(no visitor id)" bucket');
ok(stripos($flat, 'Countries (') !== false && stripos($flat, 'Unknown') !== false, 'countries show the share with a known country and "Unknown"');
$old = $page('owner', '&range=custom&from=' . gmdate('Y-m-d', time() - 40 * 86400) . '&to=' . gmdate('Y-m-d', time() - 30 * 86400))['body']; ok(stripos($old, 'unavailable, not zero') !== false && stripos($old, 'Page views (human)') === false, 'a range before tracking started says unavailable, not zero');
$wide = preg_replace('/\s+/', ' ', preg_replace('/<[^>]+>/', ' ', $page('owner', '&range=30d')['body'])); ok(stripos($wide, 'Days before the start are unavailable, not zero') !== false, 'a range that starts before tracking says so');

section('safe CSV export, audit, hostile input');
foreach (['daily' => 'date_utc', 'pages' => 'page_views', 'referrers' => 'referrer_class', 'countries' => 'country', 'tools' => 'tool_detail_page_views', 'conversions' => 'description'] as $k => $col) { $x = $page('owner', "&range=custom&from=$D1&to=$D0&export=$k"); ok($x['code'] === 200 && strpos($x['head'], 'text/csv') !== false && stripos($x['head'], 'attachment') !== false && strpos($x['body'], $col) !== false && stripos($x['head'], 'no-store') !== false, "export $k downloads as a CSV attachment"); }
$x = $page('owner', "&range=custom&from=$D1&to=$D0&export=tools"); ok(strpos($x['body'], 'alpha') !== false, 'tool export carries the real rows');
$x = $page('owner', '&export=../../etc/passwd'); ok($x['code'] === 404 && strpos($x['head'], 'text/csv') === false, 'an unknown export name is refused'); $x = $page('owner', '&export[]=daily'); ok($x['code'] === 404, 'an array export parameter is refused');
ok((int)$pdo->query("SELECT COUNT(*) FROM crm_audit_log WHERE action = 'admin_center_export'")->fetchColumn() >= 6, 'every export is in the audit log');
$pdo->prepare('INSERT INTO page_views (viewed_at, day, path, page_type, status, referrer_class, referrer_host, country, visitor_hash, is_bot) VALUES (?,?,?,?,?,?,?,?,?,0)')->execute(["$D1 14:00:00", $D1, '/tool.php?id=%3Cscript%3Ealert(1)%3C%2Fscript%3E', 'tool', 200, 'direct', '', 'DE', 'cccccccccccccccc']);
$pdo->prepare('INSERT INTO page_views (viewed_at, day, path, page_type, status, referrer_class, referrer_host, country, visitor_hash, is_bot) VALUES (?,?,?,?,?,?,?,?,?,0)')->execute(["$D1 14:01:00", $D1, '/=cmd|calc', 'other', 200, 'direct', '', 'DE', 'cccccccccccccccc']);
adma_rollup_range($pdo, $D1, $D1);
$b = $page('owner', "&range=custom&from=$D1&to=$D1")['body']; ok(strpos($b, '<script>alert(1)</script>') === false && strpos($b, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false, 'a hostile tool slug in tracking data is escaped');
$x = $page('owner', "&range=custom&from=$D1&to=$D1&export=pages"); ok(strpos($x['body'], '"\'/=cmd|calc"') === false && strpos($x['body'], '"/=cmd|calc"') !== false, 'a path that merely contains = is exported as data (only a leading = is neutralised)');
foreach (['&sort=' . rawurlencode('pages:views;DROP TABLE x') . '&dir=asc', "&sort[]=a&dir[]=b", '&sort=pages:nope', '&range[]=x', '&range=custom&from[]=1&to=2', "&range=custom&from=2026-01-01'--&to=x", '&range=' . str_repeat('a', 3000)] as $q) { $x = $page('owner', $q); ok($x['code'] === 200, 'hostile parameter never errors: ' . substr($q, 0, 50)); }
ok(adma_storage_ready($pdo), 'hostile input did not touch the storage');
$before = (int)$pdo->query('SELECT COUNT(*) FROM page_views')->fetchColumn(); $page('owner', "&range=30d"); $page('owner', "&range=7d&export=daily"); ok((int)$pdo->query('SELECT COUNT(*) FROM page_views')->fetchColumn() === $before, 'viewing and exporting never write tracking data');
$h = $page('owner')['head']; ok(preg_match('/X-Robots-Tag:\s*noindex, nofollow/i', $h) === 1 && stripos($h, 'no-store') !== false, 'noindex and no-store');
$ov = http('GET', "$base/admin-center.php", null, [], $jars['owner'])['body']; ok(stripos($ov, 'Traffic (last 7 days)') !== false && stripos($ov, 'visitor-days') !== false, 'the overview shows last-7-day traffic with the visitor-days caveat');

section('honest states: storage missing, no tracking rows');
$pdo2 = new PDO($dsnBase . ";dbname=$db", $user, $pw, $opt);
$pdo2->exec('RENAME TABLE adm_traffic_daily TO adm_traffic_daily_x'); $x = $page('owner'); ok(stripos($x['body'], 'storage is not installed') !== false && stripos($x['body'], 'not zero') !== false, 'missing storage says "not installed ... not zero"'); $ov = http('GET', "$base/admin-center.php", null, [], $jars['owner'])['body']; ok(stripos($ov, 'storage is not installed') !== false, 'the overview says so too'); $x2 = $page('owner', '&export=daily'); ok($x2['code'] === 404, 'export is refused without storage'); $pdo2->exec('RENAME TABLE adm_traffic_daily_x TO adm_traffic_daily');
$pdo2->exec('DELETE FROM adm_traffic_daily'); $x = $page('owner'); ok(stripos($x['body'], 'No tracking data yet') !== false && stripos($x['body'], 'not zero visitors') !== false, 'storage without data says "No tracking data yet ... not zero visitors"'); adma_rollup_range($pdo, $D1, $D1);

section('bot filtering for new outbound-click events');
$tid = null; ob_start(); require_once $root . '/helpers.php'; ob_end_clean(); try { $tools = load_tools()['tools'] ?? []; $tid = $tools[0]['id'] ?? null; } catch (Throwable $e) {}
if ($tid) {
  $c0 = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE name = 'outbound_click' AND ref = " . $pdo->quote((string)$tid))->fetchColumn();
  $r1 = http('GET', "$base/redirect.php?id=" . rawurlencode((string)$tid), null, ['User-Agent: Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)']);
  $c1 = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE name = 'outbound_click' AND ref = " . $pdo->quote((string)$tid))->fetchColumn();
  $r2 = http('GET', "$base/redirect.php?id=" . rawurlencode((string)$tid), null, ['User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36']);
  $c2 = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE name = 'outbound_click' AND ref = " . $pdo->quote((string)$tid))->fetchColumn();
  ok($r1['code'] === 302 && $r2['code'] === 302, 'both visitors are still redirected to the tool (HTTP ' . $r1['code'] . '/' . $r2['code'] . ')');
  ok($c1 === $c0, 'a bot following the outbound link is NOT recorded as an outbound-click event'); ok($c2 === $c0 + 1, 'a normal browser is recorded');
} else fwrite(STDERR, "  (no tool found in the catalogue fixture: bot filter HTTP check skipped)\n");

section('scale: 20,000 rows for one day');
$D2 = gmdate('Y-m-d', time() - 2 * 86400); $pdo->beginTransaction(); $ins = $pdo->prepare('INSERT INTO page_views (viewed_at, day, path, page_type, status, referrer_class, referrer_host, country, visitor_hash, is_bot) VALUES (?,?,?,?,?,?,?,?,?,?)');
for ($i = 0; $i < 20000; $i++) $ins->execute(["$D2 " . sprintf('%02d:%02d:%02d', intdiv($i, 1000) % 24, $i % 60, ($i * 7) % 60), $D2, '/tool.php?id=t' . ($i % 400), 'tool', 200, ['search', 'direct', 'social'][$i % 3], '', ['DE', 'FR', 'XX'][$i % 3], substr(hash('sha256', (string)($i % 3000)), 0, 16), $i % 20 === 0 ? 1 : 0]);
$pdo->commit(); $t0 = microtime(true); adma_rollup_range($pdo, $D2, $D2); $rt = microtime(true) - $t0;
ok($rt < 15, 'rolling up 20,000 page views takes ' . round($rt, 2) . ' s (limit 15 s)');
$t0 = microtime(true); $x = $page('owner', "&range=custom&from=$D2&to=$D0"); $dt = microtime(true) - $t0; ok($x['code'] === 200 && $dt < 3, 'the report for a day with 20,000 raw rows renders in ' . round($dt, 2) . ' s from the rollups (limit 3 s)');
$ex = $pdo->query("EXPLAIN SELECT dim_key, SUM(views) FROM adm_traffic_dim_daily WHERE dim = 'page' AND day BETWEEN '$D2' AND '$D0' GROUP BY dim_key")->fetch(); ok(!empty($ex['key']), 'the report query uses an index (' . ($ex['key'] ?? 'none') . ')');

// optional browser accessibility check on the real report (needs playwright + axe-core)
$axe = getenv('AXE_PATH'); if ($axe && is_file("$root/tests/browser_phase8a_test.js")) {
  section('browser: axe, keyboard, responsive on the analytics report');
  exec('cd ' . escapeshellarg($root) . ' && EXTRA_PATHS=' . escapeshellarg("/admin-center.php?m=analytics&range=custom&from=$D2&to=$D0") . ' node tests/browser_phase8a_test.js ' . escapeshellarg($base) . ' t8bowner analytics-test-pw-1 2>&1', $o, $rc);
  ok($rc === 0, 'browser checks passed: ' . substr(implode(' | ', $o), 0, 1800));
} else fwrite(STDERR, "  (browser/axe check skipped: set AXE_PATH and NODE_PATH to run it)\n");
echo "\nPASSED: $pass  FAILED: $fail\n"; exit($fail ? 1 : 0);
