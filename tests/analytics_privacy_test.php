<?php
// Analytics privacy fix (decision D4, option B), DATABASE-FREE: an outbound click sets no cookie and stores no visitor identifier and no IP hash; exactly one record per legitimate click;
// bots and prefetches excluded; rate limiting intact (real limiter, temporary directory); affiliate and sponsored attribution kept; redirects unchanged; UTC writers.
$root = dirname(__DIR__); chdir($root);
putenv('AIHUB_RATE_DIR=' . ($rate = sys_get_temp_dir() . '/aihub_rate_test_' . getmypid())); @mkdir($rate, 0700, true);
ob_start(); require_once $root . '/helpers.php'; require_once $root . '/outbound_click.php'; ob_end_clean();
$pass = 0; $fail = 0; function ok($c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; fwrite(STDERR, "  FAIL: $m\n"); } }
$strip = fn(string $s) => preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $s); $fnSrc = function (string $name) { $r = new ReflectionFunction($name); return implode('', array_slice(file($r->getFileName()), $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1)); };
$human = ['REQUEST_METHOD' => 'GET', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124 Safari/537.36'];

// ---- 1 and 2: behaviour of record_click on an in-memory table shaped like the live one (columns NOT NULL)
$pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE tool_clicks (id INTEGER PRIMARY KEY, tool_slug TEXT NOT NULL, visitor_id TEXT NOT NULL, ip_hash TEXT NOT NULL, created_at TEXT NOT NULL)");
$_COOKIE = []; $_SERVER['REMOTE_ADDR'] = '203.0.113.77'; $_SERVER['HTTP_USER_AGENT'] = 'UnitTestAgent/1.0'; $headersBefore = headers_list();
$n1 = record_click('alpha', $pdo); $n2 = record_click('alpha', $pdo); $nb = record_click('beta', $pdo);
ok($n1 === 1 && $n2 === 2 && $nb === 1, 'record_click still returns the running total per tool (lifetime counter intact)');
$rows = $pdo->query('SELECT * FROM tool_clicks')->fetchAll(PDO::FETCH_ASSOC); ok(count($rows) === 3, 'each call writes exactly one row');
ok(array_unique(array_column($rows, 'visitor_id')) === [''] && array_unique(array_column($rows, 'ip_hash')) === [''], 'no visitor identifier and no IP hash is stored with a click (both columns empty)');
$blob = json_encode($rows); ok(strpos($blob, '203.0.113.77') === false && strpos($blob, 'UnitTestAgent') === false, 'neither the IP address nor the user agent appears in the stored row');
ok($_COOKIE === [] && !array_filter(headers_list(), fn($h) => stripos($h, 'set-cookie') === 0) && headers_list() === $headersBefore, 'recording a click sets no cookie and reads none');
ok(record_click('  ', $pdo) === 0 && (int)$pdo->query('SELECT COUNT(*) FROM tool_clicks')->fetchColumn() === 3, 'an empty tool id writes nothing');
$src = $strip($fnSrc('record_click')); foreach (['get_visitor_id', 'visitor_id()', 'get_ip_hash', 'sec_ip_hash', 'setcookie', 'REMOTE_ADDR', 'HTTP_USER_AGENT', 'sec_client_ip', '$_COOKIE'] as $bad) ok(strpos($src, $bad) === false, "record_click does not use $bad");
// ---- static: the whole click path
foreach (['outbound_click.php', 'redirect.php', 'track-click.php'] as $f) { $code = $strip(file_get_contents($root . '/' . $f)); foreach (['get_visitor_id', 'visitor_id(', 'get_ip_hash', 'sec_ip_hash', 'setcookie', 'aih_visitor', 'aihub_visitor_id', '$_COOKIE', 'REMOTE_ADDR', 'sec_client_ip'] as $bad) ok(strpos($code, $bad) === false, "$f does not use $bad"); }
ok(preg_match_all('/HTTP_USER_AGENT/', $strip(file_get_contents($root . '/outbound_click.php'))) === 1, 'the user agent is read once, only to exclude bots, and never stored');
$votes = $strip($fnSrc('vote_tool_action')); ok(strpos($votes, 'get_visitor_id') !== false, 'the vote feature keeps its own cookie (unchanged, set only when someone votes)');
// ---- 3: exactly one record per legitimate action (shared recorder)
$mk = function (array $over = []) { $c = new ArrayObject(['click' => 0, 'event' => []]); return [$c, ['limit' => function ($b) use ($over) { foreach ($over as $p) if (strpos($b, $p) === 0) return true; return false; }, 'writable' => fn() => true, 'click' => function () use ($c) { $c['click'] = $c['click'] + 1; return 1; }, 'event' => function ($i, $a, $pl) use ($c) { $c['event'] = array_merge($c['event'], [[$i, $a, $pl]]); }]]; };
[$c, $io] = $mk(); $r = outbound_click_record('alpha', ['id' => 'alpha'], $human, $io); ok($r === 'counted' && $c['click'] === 1 && count($c['event']) === 1, 'one legitimate click: one lifetime row and one event');
// ---- 4: bots and prefetch
foreach (['Googlebot/2.1 (+http://www.google.com/bot.html)', 'curl/8.4.0', 'Mozilla/5.0 (compatible; GPTBot/1.0)', ''] as $ua) { [$c, $io] = $mk(); $r = outbound_click_record('alpha', ['id' => 'alpha'], ['HTTP_USER_AGENT' => $ua] + $human, $io); ok($r === 'bot' && $c['click'] === 0 && !$c['event'], 'bot not counted: ' . ($ua === '' ? '(empty)' : substr($ua, 0, 22))); }
foreach (['HTTP_SEC_PURPOSE' => 'prefetch', 'HTTP_PURPOSE' => 'prerender', 'HTTP_X_PURPOSE' => 'preview'] as $h => $v) { [$c, $io] = $mk(); $r = outbound_click_record('alpha', ['id' => 'alpha'], [$h => $v] + $human, $io); ok($r === 'prefetch' && $c['click'] === 0, "prefetch header $h not counted"); }
[$c, $io] = $mk(); ok(outbound_click_record('alpha', ['id' => 'alpha'], ['REQUEST_METHOD' => 'HEAD'] + $human, $io) === 'head' && $c['click'] === 0, 'HEAD not counted');
// ---- 5: rate limiting with the REAL limiter (temporary directory); the 5-second duplicate guard is bypassed here to reach the 20-per-10-minutes guard
$id = 'rl' . bin2hex(random_bytes(4)); $counted = 0; $limited = 0; $clicks = new ArrayObject(['n' => 0]);
$real = ['limit' => function ($b, $m, $w) { static $k = 0; if (strpos($b, 'clickdup:') === 0) return false; return sec_rate_limited($b, $m, $w, 'testnet0000000000'); }, 'writable' => fn() => true, 'click' => function () use ($clicks) { $clicks['n'] = $clicks['n'] + 1; return 1; }, 'event' => function () {}];
for ($i = 0; $i < 25; $i++) { $r = outbound_click_record($id, ['id' => $id], $human, $real); if ($r === 'counted') $counted++; if ($r === 'rate_limited') $limited++; }
ok($counted === 20 && $limited === 5 && $clicks['n'] === 20, 'real limiter: the first 20 clicks per 10 minutes count, the next 5 do not');
$dup = ['limit' => function ($b, $m, $w) { if (strpos($b, 'click:') === 0) return false; return sec_rate_limited($b, $m, $w, 'testnet0000000001'); }, 'writable' => fn() => true, 'click' => fn() => 1, 'event' => function () {}]; $id2 = 'dp' . bin2hex(random_bytes(4));
ok(outbound_click_record($id2, ['id' => $id2], $human, $dup) === 'counted' && outbound_click_record($id2, ['id' => $id2], $human, $dup) === 'duplicate', 'real limiter: a second request within 5 seconds is a duplicate');
// ---- 6: affiliate and sponsored attribution
$ev = []; $io = ['limit' => fn() => false, 'writable' => fn() => true, 'click' => fn() => 1, 'event' => function ($i, $a, $p) use (&$ev) { $ev[] = [$i, $a, $p]; }];
outbound_click_record('t1', ['id' => 't1', 'affiliate' => 'https://aff.example.com/?ref=x', 'featured_level' => 2, 'featured_until' => '2099-01-01'], $human, $io);
outbound_click_record('t2', ['id' => 't2', 'featured_level' => 0], $human, $io);
ok($ev[0] === ['t1', 'yes', 'sponsored'] && $ev[1] === ['t2', 'no', 'organic'], 'affiliate yes/no and sponsored/organic are recorded exactly as before');
// ---- 7: existing links and redirects (real redirect.php in a subprocess, no database)
$port = 8700 + (getmypid() % 90); $proc = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50; $i++) { $f = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($f) { fclose($f); break; } usleep(100000); }
$run = function (string $q) use ($port) { $ctx = stream_context_create(['http' => ['method' => 'GET', 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 5, 'header' => "User-Agent: Mozilla/5.0 Chrome/124 Safari/537.36\r\n"]]); @file_get_contents("http://127.0.0.1:$port/redirect.php?$q", false, $ctx); return $http_response_header ?? []; };
$unknown = $run('id=no-such-tool-xyz'); ok(in_array('Location: /index.php', $unknown, true) && !array_filter($unknown, fn($h) => stripos($h, 'set-cookie') === 0), 'unknown tool still redirects to the home page, no cookie');
$tools = tools_by_id(); $tid = ''; foreach ($tools as $k => $t) if (tool_url($t) !== '#' && sec_safe_external_url(tool_url($t)) !== null) { $tid = (string)$k; break; }
$known = $run('id=' . urlencode($tid)); $loc = array_values(array_filter($known, fn($h) => stripos($h, 'location:') === 0)); ok($tid !== '' && count($loc) === 1 && stripos($loc[0], 'Location: http') === 0, 'a catalogue tool still redirects to its official website');
ok(in_array('Referrer-Policy: no-referrer', $known, true) && in_array('X-Robots-Tag: noindex, nofollow', $known, true) && in_array('Cache-Control: no-store', $known, true) && !array_filter($known, fn($h) => stripos($h, 'set-cookie') === 0), 'redirect keeps its privacy headers and sets no cookie');
$legacy = $run('id=' . urlencode($tid) . '&tracked=1'); $loc2 = array_values(array_filter($legacy, fn($h) => stripos($h, 'location:') === 0)); ok(count($loc2) === 1 && $loc2[0] === $loc[0], 'an old link with tracked=1 redirects to the same place');
$bad = $run('id=' . urlencode('javascript:alert(1)')); ok(in_array('Location: /index.php', $bad, true), 'a hostile id is not followed');
if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
// ---- UTC writers
$pv = $strip(file_get_contents($root . '/analytics.php')); ok(!preg_match('/(?<!gm)date\\(/', $pv) && strpos($pv, "gmdate('Y-m-d H:i:s', \$ts)") !== false, 'page views are stamped in UTC');
$cv = $strip(file_get_contents($root . '/conversion.php')); ok(strpos($cv, "gmdate('Y-m-d H:i:s', \$ts), gmdate('Y-m-d', \$ts)") !== false, 'events are stamped in UTC');
putenv('TZ=Asia/Tokyo'); date_default_timezone_set('Asia/Tokyo'); $ts = strtotime('2026-11-20 23:30:00 UTC'); $row = analytics_build_row(['HTTP_USER_AGENT' => $human['HTTP_USER_AGENT'], 'HTTP_HOST' => 'aihubpedia.com'], [], 200, $ts, '/');
ok($row['day'] === '2026-11-20' && $row['viewed_at'] === '2026-11-20 23:30:00', 'on a server set to Tokyo time the row still carries the UTC day and time'); date_default_timezone_set('UTC');
$sq = new PDO('sqlite::memory:'); $sq->exec('CREATE TABLE events (id INTEGER PRIMARY KEY, occurred_at TEXT, day TEXT, name TEXT, page_type TEXT, ref TEXT, prop1 TEXT, prop2 TEXT, visitor_hash TEXT, returning_user INT)'); date_default_timezone_set('Asia/Tokyo'); event_insert($sq, ['name' => 'outbound_click', 'ref' => 'x'], '', false, $ts); date_default_timezone_set('UTC');
ok($sq->query('SELECT day FROM events')->fetchColumn() === '2026-11-20', 'event rows carry the UTC day regardless of server timezone');
echo "$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
