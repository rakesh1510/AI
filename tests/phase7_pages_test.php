<?php
declare(strict_types=1);
/**
 * Phase 7 public AI Universe pages over HTTP: universe-heatmap.php, universe-lineage.php, universe-arena.php (and the sitemap rules for them).
 * Scratch databases p7pg_<pid>, p7pg_<pid>_e (schema, no rows) and p7pg_<pid>_n (no uni_* tables); own php -S servers on ports 8270-8290 with tests/router.php.
 * The ledger is SYNTHETIC (tests/fixtures/phase7_pages_seed.php), written through the uni_core.php service layer; nothing real is used.
 * Covers: verified-and-current data only (no proposed, in-review, rejected or superseded text anywhere), empty states, no invented fill, coarse date precision listed apart,
 * separate lineage relationship types, "Unavailable" fields, XSS escaping, hostile parameters never 500, canonical / robots / JSON-LD, sitemap rules, no identities.
 * Usage: php tests/phase7_pages_test.php   Env: AIHUB_COMPAT_ROOT_USER / AIHUB_COMPAT_ROOT_PASS / AIHUB_DB_HOST (as the other Phase 7 tests).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
ob_start(); require_once $root . '/uni_core.php'; require_once __DIR__ . '/fixtures/phase7_pages_seed.php'; ob_end_clean();
$dbHost = getenv('AIHUB_DB_HOST') ?: '127.0.0.1';
$dbUser = getenv('AIHUB_COMPAT_ROOT_USER') ?: 'root';
$dbPass = getenv('AIHUB_COMPAT_ROOT_PASS') !== false ? (string)getenv('AIHUB_COMPAT_ROOT_PASS') : 'root_test_only';
$opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$admin = new PDO("mysql:host=$dbHost;port=3306", $dbUser, $dbPass, $opt);
$names = ['p7pg_' . getmypid(), 'p7pg_' . getmypid() . '_e', 'p7pg_' . getmypid() . '_n']; $servers = []; $tmp = [];
foreach ($names as $n) { if (!preg_match('/^p7pg_\d+(_e|_n)?$/', $n)) { fwrite(STDERR, "bad db name\n"); exit(2); } $admin->exec("CREATE DATABASE `$n` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); }
register_shutdown_function(function () use (&$servers, $admin, $names, &$tmp) {
  foreach ($servers as $p) if (is_resource($p)) { proc_terminate($p); proc_close($p); }
  foreach ($names as $n) { try { $admin->exec("DROP DATABASE IF EXISTS `$n`"); } catch (Throwable $e) {} }
  foreach ($tmp as $d) { foreach ((array)glob($d . '/*') as $f) @unlink($f); @rmdir($d); }
});
$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; fwrite(STDERR, "  FAIL: $m\n"); } }
function section(string $t): void { fwrite(STDERR, "== $t\n"); }
$bodies = [];
function http(string $base, string $method, string $path, array $h = []): array {
  global $bodies;
  $ch = curl_init($base . $path); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h, CURLOPT_FOLLOWLOCATION => false]);
  $r = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
  $head = substr($r, 0, $hs); $body = substr($r, $hs);
  if ($code === 200) $bodies[] = [$path, $body];
  return ['code' => $code, 'head' => $head, 'body' => $body, 'loc' => preg_match('/^Location:\s*(.+)$/mi', $head, $m) ? trim($m[1]) : ''];
}
function meta(string $html): array {
  $d = new DOMDocument(); libxml_use_internal_errors(true); $d->loadHTML('<?xml encoding="utf-8"?>' . $html); libxml_clear_errors(); $x = new DOMXPath($d);
  $first = fn($q) => ($n = $x->query($q))->length ? trim($n->item(0)->nodeValue) : null;
  $ld = []; foreach ($x->query("//script[@type='application/ld+json']") as $s) $ld[] = json_decode($s->nodeValue, true);
  return ['title' => $first('//head/title'), 'robots' => $first("//meta[@name='robots']/@content"), 'canonical' => $first("//link[@rel='canonical']/@href"), 'h1' => $x->query('//h1')->length, 'desc' => $first("//meta[@name='description']/@content"), 'ld' => $ld, 'canon_n' => $x->query("//link[@rel='canonical']")->length];
}
function indexable(array $m): bool { return stripos((string)$m['robots'], 'noindex') === false; }
function main_text(string $html): string {
  $part = preg_match('#<main.*?</main>#s', $html, $mm) ? $mm[0] : $html;
  return trim(html_entity_decode(preg_replace('/\s+/', ' ', preg_replace('/<[^>]+>/', ' ', preg_replace('#<(script|style)\b.*?</\1>#s', '', $part))), ENT_QUOTES));
}
function freePort(int $from, int $to): int { for ($p = $from; $p <= $to; $p++) { $s = @stream_socket_server("tcp://127.0.0.1:$p", $e, $m); if ($s) { fclose($s); return $p; } } throw new RuntimeException('no free port'); }
function startServer(string $root, string $db, string $dbHost, string $dbUser, string $dbPass, array $extra = []): string {
  global $servers, $tmp; $port = freePort(8270, 8290);
  $dir = sys_get_temp_dir() . '/p7pg_' . getmypid() . '_' . $port; @mkdir($dir, 0700); $tmp[] = $dir;
  $env = array_merge(getenv(), ['AIHUB_DB_HOST' => $dbHost, 'AIHUB_DB_NAME' => $db, 'AIHUB_DB_USER' => $dbUser, 'AIHUB_DB_PASS' => $dbPass, 'AIHUB_RATE_DIR' => $dir, 'AIHUB_SECURITY_LOG' => "$dir/sec.log", 'AIHUB_FORM_SECRET' => 'p7pg-secret-not-for-production', 'AIHUB_UNIVERSE_PAGE_RATE_LIMIT' => '10000', 'AIHUB_UNIVERSE_PUBLIC' => '1'], $extra);   // public mode: the evidence gates are what these tests cover; hidden default: universe_hidden_test.php
  $servers[] = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$dir/php.log", '-d', 'error_reporting=32767', '-d', 'output_buffering=0', '-S', "127.0.0.1:$port", '-t', $root, "$root/tests/router.php"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env);
  for ($i = 0; $i < 60; $i++) { $c = @fsockopen('127.0.0.1', $port); if ($c) { fclose($c); break; } usleep(100000); }
  return "http://127.0.0.1:$port";
}
function phplog(string $base): string { global $tmp; foreach ($tmp as $d) if (str_ends_with($base, substr($d, strrpos($d, '_') + 1))) return (string)@file_get_contents("$d/php.log"); return ''; }

// ---- seed ----------------------------------------------------------------------------------------------------------------------------------
section('seeding the SYNTHETIC ledger through the service layer');
$pdo = new PDO("mysql:host=$dbHost;port=3306;dbname={$names[0]}", $dbUser, $dbPass, $opt); $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION,ERROR_FOR_DIVISION_BY_ZERO'");
p7s_schema($pdo, $root); $T = p7_seed($pdo);
$pdoE = new PDO("mysql:host=$dbHost;port=3306;dbname={$names[1]}", $dbUser, $dbPass, $opt); p7s_schema($pdoE, $root);
$A = startServer($root, $names[0], $dbHost, $dbUser, $dbPass); $E = startServer($root, $names[1], $dbHost, $dbUser, $dbPass); $N = startServer($root, $names[2], $dbHost, $dbUser, $dbPass);
$leakScan = function (string $html, string $where) use ($T) { foreach ($T['leaks'] as $l) ok(stripos($html, $l) === false, "$where leaks '$l'"); foreach ($T['identities'] as $i) ok(stripos($html, $i) === false, "$where exposes the identity '$i'"); };

// ---- heatmap -------------------------------------------------------------------------------------------------------------------------------
section('heatmap: default year view');
$r = http($A, 'GET', '/universe-heatmap.php'); $m = meta($r['body']); $txt = main_text($r['body']);
ok($r['code'] === 200 && $m['h1'] === 1 && indexable($m) && $m['canonical'] === 'https://aihubpedia.com/universe-heatmap.php' && $m['canon_n'] === 1, "base page: 200, one h1, indexable, canonical (robots {$m['robots']})");
ok(strlen((string)$m['title']) >= 10 && strlen((string)$m['title']) <= 80 && strlen((string)$m['desc']) >= 30 && strlen((string)$m['desc']) <= 160, 'title and description lengths');
ok(substr_count($r['body'], 'class="uhMonth"') === 12 && substr_count($r['body'], '<table class="uhCal"') === 12, 'the year view has twelve month calendars');
preg_match_all('#<a class="uhCell uhLv(\d)[^"]*" href="[^"]*" data-date="(\d{4}-\d\d-\d\d)"[^>]*aria-label="([^"]*)"#', $r['body'], $cells, PREG_SET_ORDER);
$got = []; foreach ($cells as $c) { preg_match('/: (\d+) verified/', $c[3], $mm); $got[$c[2]] = (int)$mm[1]; }
$want = $T['day_counts']; ksort($want); ksort($got); ok($got === $want, 'every active day cell and its count equals the verified day-precision truth ' . json_encode($got));
ok(substr_count($r['body'], 'class="uhCell uhEmpty"') > 300 && !preg_match('#uhEmpty[^>]*uhLv#', $r['body']), 'days without verified activity are empty cells (never filled with a level)');
ok(preg_match('#<span class="uhCell uhEmpty" data-date="2024-05-01">#', $r['body']) === 1, 'a month-precision event (May 2024, stored as 2024-05-01) is NOT drawn on a single day');
ok(preg_match('#<span class="uhCell uhEmpty" data-date="2024-03-17">#', $r['body']) === 1, 'a day without events is an empty span, not a link');
ok(strpos($r['body'], 'Model X May update') !== false && strpos($txt, 'May 2024 (month only)') !== false, 'the month-precision event is listed separately with its precision');
ok(strpos($txt, 'Acme acquires something') === false, 'a year-precision event of 2023 is not shown in 2024');
ok(strpos($txt, 'Verified events per month') !== false && strpos($txt, 'Days with verified activity') !== false, 'the accessible table alternative is present');
ok(preg_match('#class="uhCell uhLv(\d)"[^>]*aria-label="March 15, 2024: 3 verified events"#', $r['body'], $mm) === 1 && $mm[1] === '4', 'the busiest day is level 4 and its aria-label states the count');
ok(strpos($r['body'], 'uhN') !== false && strpos($txt, 'the exact count is printed in every cell') !== false, 'the colour scale is not the only encoding (counts printed, patterns and borders)');
$ldTypes = []; foreach ($m['ld'] as $b) foreach (($b['@graph'] ?? []) as $n) $ldTypes[] = $n['@type'] ?? ''; ok(in_array('WebPage', $ldTypes, true) && in_array('BreadcrumbList', $ldTypes, true), 'JSON-LD has WebPage and BreadcrumbList');
ok(strpos(json_encode($m['ld']), 'Model X') === false && strpos(json_encode($m['ld']), 'aggregateRating') === false, 'JSON-LD claims no event facts or ratings');
$leakScan($r['body'], 'heatmap');
ok(strpos($r['body'], '<a class="skipLink"') !== false && strpos($r['body'], 'href="#uh-table"') !== false, 'skip links (site and table alternative)');
ok(strpos($r['body'], 'src="/assets/js/universe.js') !== false && strpos($r['body'], 'onclick=') === false && !preg_match('#<script(?![^>]*src=)[^>]*>#', preg_match('#<main.*?</main>#s', $r['body'], $mx) ? $mx[0] : ''), 'no inline script or handler other than JSON-LD (CSP friendly)');

section('heatmap: other years, months and weeks');
$r = http($A, 'GET', '/universe-heatmap.php?year=2023'); $m = meta($r['body']); $txt = main_text($r['body']);
ok($r['code'] === 200 && !indexable($m) && strpos($r['body'], 'class="uhCell uhLv') === false && strpos($txt, 'Acme acquires something') !== false && strpos($txt, '2023 (year only)') !== false, '2023: no day is drawn, the year-only event is listed apart, URL with params is noindex');
$r = http($A, 'GET', '/universe-heatmap.php?view=month&month=2024-03'); $txt = main_text($r['body']);
ok($r['code'] === 200 && substr_count($r['body'], '<table class="uhCal"') === 1 && strpos($txt, 'Model X launches') !== false && strpos($txt, 'Acme Day corrected') !== false && strpos($txt, 'Model X May update') === false, 'month view lists the verified events of March only');
$r = http($A, 'GET', '/universe-heatmap.php?view=month&month=2024-05'); $txt = main_text($r['body']);
ok(strpos($txt, 'Model X May update') !== false && strpos($txt, 'class="uhCell uhLv') === false && preg_match('#uhCell uhLv#', $r['body']) === 0, 'May 2024 month view: only the month-precision event, no day drawn');
$r = http($A, 'GET', '/universe-heatmap.php?view=week&week=2024-03-13'); $txt = main_text($r['body']);
ok($r['code'] === 200 && strpos($txt, 'Week of March 11, 2024') !== false && strpos($r['body'], 'data-date="2024-03-15"') !== false && strpos($txt, 'Model X launches') !== false && strpos($txt, 'Model Y released') === false, 'week view shows Monday March 11 to Sunday March 17 only');
$r = http($A, 'GET', '/universe-heatmap.php?view=week&week=2024-05-01'); ok(strpos(main_text($r['body']), 'cannot belong to a single week') !== false, 'week view explains that coarse dates are not shown');

section('heatmap: day panel, filters, range');
$r = http($A, 'GET', '/universe-heatmap.php?day=2024-03-15'); $m = meta($r['body']); $txt = main_text($r['body']);
foreach (['Model X launches', 'Acme announces', 'Prod Z ships'] as $t) ok(strpos($txt, $t) !== false, "day panel lists '$t'");
ok(strpos($txt, 'Model Y released') === false && strpos($txt, 'Synthetic Test Publisher') !== false && strpos($txt, 'retrieved 2025-01-15') !== false && strpos($r['body'], 'href="https://example.test/synthetic/') !== false && strpos($r['body'], 'rel="nofollow noopener noreferrer"') !== false, 'day panel: only that day, sources with publisher, retrieved date and safe link attributes');
ok(strpos($txt, 'Related entities') !== false && strpos($txt, 'Model X is developed by Acme Labs') !== false, 'day panel shows related entities from documented relationships');
ok(strpos($r['body'], 'aria-current="date"') !== false && !indexable($m), 'the selected day is marked and a day URL is noindex');
$leakScan($r['body'], 'heatmap day');
$r = http($A, 'GET', '/universe-heatmap.php?day=2024-03-17'); ok($r['code'] === 200 && strpos(main_text($r['body']), 'No verified activity on this day') !== false, 'a day without events says so (200, nothing filled)');
$r = http($A, 'GET', '/universe-heatmap.php?entity=modelx'); preg_match_all('#data-date="(\d{4}-\d\d-\d\d)"[^>]*aria-label="[^"]*: (\d+) verified#', $r['body'], $mm, PREG_SET_ORDER); $g = []; foreach ($mm as $c) $g[$c[1]] = (int)$c[2];
ok($g === ['2024-03-15' => 1, '2024-04-02' => 1, '2024-04-20' => 1], 'entity filter: only Model X events are counted ' . json_encode($g));
$r = http($A, 'GET', '/universe-heatmap.php?category=launch'); preg_match_all('#data-date="(\d{4}-\d\d-\d\d)"[^>]*aria-label="[^"]*: (\d+) verified#', $r['body'], $mm, PREG_SET_ORDER); $g = []; foreach ($mm as $c) $g[$c[1]] = (int)$c[2];
ok($g === ['2024-03-15' => 1, '2024-03-16' => 1], 'category filter: only launches are counted ' . json_encode($g));
$r = http($A, 'GET', '/universe-heatmap.php?from=2024-04-01&to=2024-06-30'); preg_match_all('#data-date="(\d{4}-\d\d-\d\d)"[^>]*aria-label="[^"]*: (\d+) verified#', $r['body'], $mm, PREG_SET_ORDER); $g = []; foreach ($mm as $c) $g[$c[1]] = (int)$c[2];
ok($g === ['2024-04-02' => 1, '2024-04-20' => 1, '2024-06-10' => 2] && strpos($r['body'], 'uhOut') !== false, 'date range clips the calendar; outside days are dimmed and not links ' . json_encode($g));
$r = http($A, 'GET', '/universe-heatmap.php?entity=lonely'); ok($r['code'] === 200 && strpos(main_text($r['body']), '0 verified events on specific days') !== false && strpos($r['body'], 'class="uhCell uhLv') === false, 'a verified entity without events: everything empty');

section('heatmap: hostile input never 500, nothing reflected');
$bad = ['view=bogus', 'entity=' . rawurlencode('<script>alert(1)</script>'), 'entity=nope-not-there', 'entity=' . rawurlencode("../../etc/passwd"), 'category=evil', 'day=2024-02-30', 'day=' . rawurlencode("2024-03-15'; DROP TABLE uni_event;--"), 'year=abc', 'year=1800', 'month=2024-13', 'week=notadate',
  'from=2024-05-01&to=2024-01-01', 'year[]=1', 'entity[]=a', 'view=' . str_repeat('A', 500), 'day=' . rawurlencode("2024-03-15\x00"), 'q=%ff%fe', 'entity=' . rawurlencode(str_repeat('a', 2500))];
foreach ($bad as $q) { $r = http($A, 'GET', '/universe-heatmap.php?' . $q); ok(in_array($r['code'], [400, 404, 200], true) && $r['code'] !== 500 && strpos($r['body'], '<script>alert(1)</script>') === false && strpos($r['body'], 'DROP TABLE') === false, "hostile '" . substr($q, 0, 40) . "' answers " . $r['code'] . ' safely'); }
foreach (['view=bogus', 'entity[]=a', 'day=2024-02-30', 'from=2024-05-01&to=2024-01-01'] as $q) { $r = http($A, 'GET', '/universe-heatmap.php?' . $q); $m = meta($r['body']); ok($r['code'] === 400 && !indexable($m) && $m['h1'] === 1, "'$q' is a 400, noindex, with one h1"); }
ok(http($A, 'GET', '/universe-heatmap.php?entity=nope-not-there')['code'] === 404, 'unknown entity is 404');
foreach (['POST', 'PUT', 'DELETE'] as $mth) ok(http($A, $mth, '/universe-heatmap.php')['code'] === 405, "$mth is 405");
ok(!str_contains(phplog($A), 'PHP Warning') && !str_contains(phplog($A), 'Fatal') && !str_contains(phplog($A), 'Notice'), 'the server log has no PHP warning, notice or fatal after the heatmap tests: ' . substr(phplog($A), 0, 300));

// ---- lineage -------------------------------------------------------------------------------------------------------------------------------
section('lineage: index and entity pages');
$r = http($A, 'GET', '/universe-lineage.php'); $m = meta($r['body']); $txt = main_text($r['body']);
ok($r['code'] === 200 && indexable($m) && $m['canonical'] === 'https://aihubpedia.com/universe-lineage.php' && $m['h1'] === 1 && strpos($txt, 'Model X') !== false && strpos($txt, 'Model Y') !== false && strpos($txt, 'Lonely Model') === false, 'index lists only families with verified releases, indexable');
$leakScan($r['body'], 'lineage index');
$r = http($A, 'GET', '/universe-lineage.php?entity=modelx'); $m = meta($r['body']); $txt = main_text($r['body']);
ok($r['code'] === 200 && indexable($m) && $m['canonical'] === 'https://aihubpedia.com/universe-lineage.php?entity=modelx' && $m['h1'] === 1, 'entity page: 200, indexable, self canonical');
ok(strpos($r['body'], '<svg class="ulSvg"') !== false && substr_count($r['body'], '<svg class="ulSvg"') === 2, 'both SVG graphs are rendered by the server (no JavaScript needed)');
ok(preg_match_all('#<li class="ulCommit" id="rel-([0-9a-f]{32})">#', $r['body']) === 2 && strpos($txt, 'v1.0') !== false && strpos($txt, 'v2.0') !== false && strpos($txt, 'Initial public release of Model X.') !== false && strpos($txt, 'Released January 10, 2024') !== false, 'commit history: verified releases with dates and descriptions');
ok(strpos($txt, 'context window 8k') !== false && strpos($txt, 'retrieved 2025-01-15') !== false && strpos($txt, 'Documented parent release: v1.0') !== false, 'commit history: recorded changes, parent link and source with retrieved date');
foreach (['Technical ancestry', 'Product succession', 'Documented release parent'] as $l) ok(strpos($txt, $l) !== false, "legend names '$l'");
ok(strpos($txt, 'Not drawn as lineage: developed by, integrates with, hosted on, acquired by, and version of') !== false, 'the legend says which relationships are never drawn as lineage');
// relationship types: each drawn entity edge keeps its own kind; ancestry only for derived_from/built_on, succession only for successor_of/renamed_from
preg_match_all('#<g data-kind="([a-z_]+)"><path class="ulEdge ulEdge(\w+)"[^>]*/><text class="ulEdgeLabel"[^>]*>([^<]*)</text><text class="ulEdgeLabel2"[^>]*>([^<]*)</text></g>#', $r['body'], $es, PREG_SET_ORDER);
$pairs = []; foreach ($es as $e) $pairs[] = $e[1] . ':' . $e[4]; sort($pairs);
ok($pairs === ['technical_ancestry:built on', 'technical_ancestry:derived from'], 'Model X graph draws exactly derived_from and built_on as technical ancestry ' . json_encode($pairs));
foreach ($es as $e) ok(($e[1] === 'technical_ancestry' && $e[2] === 'Tech' && in_array($e[4], ['built on', 'derived from'], true)) || ($e[1] === 'product_succession' && $e[2] === 'Succ' && in_array($e[4], ['successor of', 'renamed from'], true)), "edge '{$e[4]}' is drawn with its own kind and style");
$svg2 = preg_match('#<svg class="ulSvg"[^>]*aria-labelledby="ul-eg-t.*?</svg>#s', $r['body'], $sm) ? $sm[0] : '';
foreach (['developed by', 'integrates with', 'hosted on', 'acquired by', 'developed_by', 'integrates_with', 'hosted_on', 'acquired_by', 'version_of'] as $nonLineage) ok(stripos($svg2, $nonLineage) === false, "non-lineage relationship '$nonLineage' is not drawn in the lineage graph");
$rel = preg_match('#<section id="relationships".*?</section>#s', $r['body'], $sm) ? main_text($sm[0]) : '';
ok(strpos($rel, 'Model Y is derived from Model X') !== false && strpos($rel, 'Prod Z is built on Model X') !== false && strpos($rel, 'hosted') === false && strpos($rel, 'acquired') === false && strpos($rel, 'Technical ancestry') !== false, 'relationship table: sentences with the direction of each documented relationship');
$r2 = http($A, 'GET', '/universe-lineage.php?entity=modely'); $es = []; preg_match_all('#<g data-kind="([a-z_]+)">.*?<text class="ulEdgeLabel2"[^>]*>([^<]*)</text></g>#s', $r2['body'], $es, PREG_SET_ORDER); $pairs = []; foreach ($es as $e) $pairs[] = $e[1] . ':' . $e[2]; sort($pairs);
ok($pairs === ['product_succession:renamed from', 'product_succession:successor of', 'technical_ancestry:derived from'], 'Model Y graph keeps ancestry and succession apart ' . json_encode($pairs));
ok(strpos(main_text($r2['body']), 'Documented parent release: v2.0 (Model X)') !== false && strpos($r2['body'], 'universe-lineage.php?entity=modelx#rel-') !== false && strpos(main_text($r2['body']), 'Description: Unavailable: no verified data') !== false, 'a cross-entity release parent links to the other family; a missing description is shown as unavailable');
ok(strpos($r['body'], 'ulEdgeRel') !== false && strpos($txt, 'Documented release parents') !== false, 'release-parent lines exist and are tabulated');
ok(strpos($txt, '2024') !== false && strpos($txt, 'Evolution timeline') !== false && strpos($txt, 'Pricing change') !== false && strpos($txt, 'Model X May update') !== false, 'timeline across years lists releases and events');
foreach ([$r, $r2] as $x) $leakScan($x['body'], 'lineage entity');
ok(strpos($r2['body'], 'Y2-OLDLEAK') === false && strpos(main_text($r2['body']), 'Y2') !== false, 'superseded release label is hidden, the current one shown');
$ld = meta($r['body'])['ld']; $relNames = []; foreach ($ld as $b) foreach (($b['@graph'] ?? []) as $n) if (($n['@type'] ?? '') === 'ItemList' && isset($n['itemListElement'])) foreach ($n['itemListElement'] as $it) $relNames[] = $it['name'];
ok(in_array('v1.0', $relNames, true) && in_array('v2.0', $relNames, true) && count(array_filter($relNames, fn($n) => stripos($n, 'LEAK') !== false)) === 0, 'JSON-LD lists only verified releases');
$r = http($A, 'GET', '/universe-lineage.php?entity=lonely'); $m = meta($r['body']); ok($r['code'] === 200 && !indexable($m) && strpos(main_text($r['body']), 'No verified history yet') !== false, 'an entity with no verified releases, relationships or events: empty state and noindex');
$r = http($A, 'GET', '/universe-lineage.php?entity=acme'); ok($r['code'] === 200 && strpos($r['body'], 'ulCommit') === false && strpos(main_text($r['body']), 'Unavailable: no verified data') !== false, 'a company without releases shows unavailable instead of invented releases');

section('lineage: version diff');
$r = http($A, 'GET', '/universe-lineage.php?entity=modelx&a=v1.0&b=v2.0'); $m = meta($r['body']); $txt = main_text($r['body']);
ok($r['code'] === 200 && !indexable($m) && strpos($txt, 'v1.0 to v2.0') !== false, 'diff by version labels: 200, noindex');
ok(strpos($txt, 'context window Changed 8k 32k') !== false && strpos($txt, 'new key Added') !== false && strpos($txt, 'legacy flag Removed') !== false && strpos($txt, '1 recorded field is identical') !== false, 'diff lists changed, added and removed fields and the unchanged count');
ok(strpos($txt, 'Pricing Status in the release data: changed') !== false && strpos($txt, 'Model X pricing update') !== false && strpos($txt, 'Model X integration') !== false, 'diff shows the pricing and integration facets with the verified events between the releases');
preg_match_all('#<option value="([0-9a-f]{32})"#', $r['body'], $om); $refs = array_values(array_unique($om[1]));
$r3 = http($A, 'GET', '/universe-lineage.php?entity=modelx&a=' . $refs[1] . '&b=' . $refs[0]); ok($r3['code'] === 200 && strpos(main_text($r3['body']), 'shown in date order') !== false, 'diff by release reference, reversed order is shown chronologically');
$r3 = http($A, 'GET', '/universe-lineage.php?entity=modely&a=Y1&b=Y2'); $t3 = main_text($r3['body']);
ok($r3['code'] === 200 && strpos($t3, 'Unavailable: no verified data') !== false && strpos($t3, 'At least one of the two releases has no recorded change set') !== false, 'a release without change set: unavailable fields are explicit');
ok(http($A, 'GET', '/universe-lineage.php?entity=modelx&a=v1.0&b=v1.0')['code'] === 400, 'diff of a release with itself is 400');
ok(http($A, 'GET', '/universe-lineage.php?entity=modelx&a=v1.0&b=nope')['code'] === 404, 'unknown release is 404');
ok(http($A, 'GET', '/universe-lineage.php?entity=modelx&a=v1.0')['code'] === 400, 'only one release chosen is 400');
ok(http($A, 'GET', '/universe-lineage.php?entity=modelx&a=LEAK-REL-PROPOSED&b=v2.0')['code'] === 404, 'an unverified release cannot be diffed');
ok(http($A, 'GET', '/universe-lineage.php?entity=modelx&a=v1.0&b=Y1')['code'] === 404, 'a release of another entity cannot be diffed');
$leakScan(http($A, 'GET', '/universe-lineage.php?entity=modelx&a=v1.0&b=v2.0')['body'], 'lineage diff');

section('lineage: hostile input never 500');
foreach (['entity=nope-nope', 'entity=' . rawurlencode('<script>alert(1)</script>'), 'entity=' . rawurlencode("x' OR 1=1--"), 'entity[]=modelx', 'entity=modelx&a[]=1&b=2', 'entity=modelx&a=' . str_repeat('A', 200) . '&b=v1.0', 'entity=modelx&a=%ff&b=v1.0', 'a=v1.0&b=v2.0', 'entity=' . str_repeat('z', 3000), 'entity=leak-ghost-proposed', 'entity=MODELX'] as $q) {
  $r = http($A, 'GET', '/universe-lineage.php?' . $q); ok($r['code'] !== 500 && in_array($r['code'], [200, 400, 404], true) && strpos($r['body'], '<script>alert(1)</script>') === false, "hostile '" . substr($q, 0, 45) . "' answers " . $r['code']); }
foreach (['entity=nope-nope', 'entity=leak-ghost-proposed', 'entity=' . rawurlencode('<script>alert(1)</script>')] as $q) { $r = http($A, 'GET', '/universe-lineage.php?' . $q); $m = meta($r['body']); ok(in_array($r['code'], [400, 404], true) && !indexable($m) && $m['h1'] === 1, "'" . substr($q, 0, 30) . "' is a 4xx noindex page with one h1"); }
foreach (['POST', 'DELETE'] as $mth) ok(http($A, $mth, '/universe-lineage.php')['code'] === 405, "$mth is 405");
ok(!str_contains(phplog($A), 'PHP Warning') && !str_contains(phplog($A), 'Fatal') && !str_contains(phplog($A), 'Notice'), 'the server log has no PHP warning, notice or fatal after the lineage tests: ' . substr(phplog($A), 0, 300));

// ---- arena ---------------------------------------------------------------------------------------------------------------------------------
section('arena: selector and comparison');
$r = http($A, 'GET', '/universe-arena.php'); $m = meta($r['body']); $txt = main_text($r['body']);
ok($r['code'] === 200 && indexable($m) && $m['canonical'] === 'https://aihubpedia.com/universe-arena.php' && $m['h1'] === 1 && substr_count($r['body'], '<select') === 4 && substr_count($r['body'], '<label for="ua-') === 4, 'selector: indexable, four labelled selects');
ok(strpos($r['body'], 'leak-ghost') === false && strpos($r['body'], 'value="modelx"') !== false && strpos($r['body'], 'value="renamed-co"') !== false, 'selector lists only verified current entities');
$leakScan($r['body'], 'arena selector');
$r = http($A, 'GET', '/universe-arena.php?a=modely&b=modelx&c=&d='); ok($r['code'] === 303 && urldecode($r['loc']) === '/universe-arena.php?e=modely,modelx', 'the form redirects to the shareable ?e= address, keeping the order (' . $r['loc'] . ')');
$r = http($A, 'GET', '/universe-arena.php?e=modelx,modely'); $m = meta($r['body']); $txt = main_text($r['body']); $t1 = $r['body'];
ok($r['code'] === 200 && indexable($m) && $m['canonical'] === 'https://aihubpedia.com/universe-arena.php?e=modelx%2Cmodely' && $m['h1'] === 1, 'comparison: 200, indexable, canonical');
$r2 = http($A, 'GET', '/universe-arena.php?e=modely,modelx'); ok(meta($r2['body'])['canonical'] === $m['canonical'], 'the reversed order has the same (sorted) canonical');
foreach (['Overview', 'Releases and activity', 'Pricing', 'Features', 'Platforms', 'Integrations', 'Benchmarks'] as $s) ok(preg_match('#<h3 id="ua-[a-z]+-h">' . $s . '</h3>#', $r['body']) === 1, "section '$s' present");
ok(substr_count($txt, 'Unavailable: no verified data') >= 5, 'missing fields are shown as "Unavailable: no verified data"');
$sec = fn(string $id) => preg_match('#<section aria-labelledby="' . $id . '">.*?</section>#s', $t1, $x) ? main_text($x[0]) : '';
ok(strpos($sec('ua-feat-h'), 'a, b') !== false && strpos($sec('ua-feat-h'), 'Unavailable: no verified data') !== false, 'features: Model X from its latest verified release, Model Y unavailable');
ok(strpos($sec('ua-plat-h'), 'web, api') !== false && strpos($sec('ua-bench-h'), 'synthetic_bench') !== false && strpos($sec('ua-bench-h'), 'Unavailable: no verified data') !== false, 'platforms and benchmarks: only recorded values, unavailable otherwise');
ok(strpos($sec('ua-price-h'), 'Unavailable: no verified data') !== false && strpos($sec('ua-price-h'), 'input: 0.5') !== false, 'pricing: Model X from the release data, Model Y unavailable');
ok(strpos($sec('ua-int-h'), 'Prod Z') !== false && strpos($sec('ua-ov-h'), 'Acme Labs') !== false && strpos($txt, 'retrieved 2025-01-15') !== false, 'integrations and developer from verified edges, with sources and retrieved dates');
$claims = preg_replace('/(No winner is declared\.?|AIHubpedia adds no score, rank or winner|does not score or rank entities here|we never declare a winner|never invent a value)/i', '', $txt);
ok(!preg_match('/\b(winner|wins the|better than|outperforms|best overall)\b/i', $claims), 'no winner or superiority is claimed anywhere');
ok(preg_match('#<h2 id="ua-fact-h">Factual verified evidence</h2>.*<h2 id="ua-ed-h">Editorial analysis</h2>.*<h2 id="ua-com-h">Community opinion</h2>#s', $t1) === 1, 'factual evidence, editorial analysis and community opinion are separate sections, in that order');
ok(strpos($txt, 'No editorial analysis has been published for this comparison yet') !== false && strpos($txt, 'No community opinion has been collected for these entities yet') !== false, 'editorial and community sections have honest empty states');
ok(strpos($r['body'], 'universe-lineage.php?entity=modelx#diff') !== false && strpos($txt, 'Compare two versions') !== false && strpos($r['body'], 'universe-lineage.php?entity=modely#diff') !== false, 'release-version diff entry points');
ok(strpos($r['body'], 'id="ua-share"') !== false && strpos($r['body'], 'value="https://aihubpedia.com/universe-arena.php?e=modelx%2Cmodely"') !== false, 'shareable address field');
$ld = json_encode(meta($r['body'])['ld']); ok(strpos($ld, 'Model X') !== false && strpos($ld, 'LEAK') === false && strpos($ld, 'aggregateRating') === false , 'JSON-LD names only the compared verified entities');
$leakScan($r['body'], 'arena comparison');
$r = http($A, 'GET', '/universe-arena.php?e=prodz,lonely'); $txt = main_text($r['body']);
ok($r['code'] === 200 && substr_count($txt, 'Unavailable: no verified data') >= 8 && strpos($txt, 'Needs at least two verified releases') !== false, 'two entities with almost no data: unavailable everywhere, no invented value');
$r = http($A, 'GET', '/universe-arena.php?e=acme,evil-co,modelx,modely'); $m = meta($r['body']); ok($r['code'] === 200 && substr_count($r['body'], '<th scope="col">') >= 4, 'four entities compare side by side');
ok(strpos($r['body'], 'Evil &lt;b&gt;Co&lt;/b&gt; &quot;quoted&quot; &amp; friends') !== false && strpos($r['body'], '<b>Co</b>') === false && strpos($r['body'], '<script>alert(9)</script>') === false, 'hostile entity names and summaries are escaped');
$r = http($A, 'GET', '/universe-arena.php?e=modelx'); $m = meta($r['body']); ok($r['code'] === 200 && !indexable($m) && strpos(main_text($r['body']), 'Choose at least two different entities') !== false, 'one entity: asks for another one, noindex');
foreach ([['e=modelx,leak-ghost-proposed', 404], ['e=modelx,nope-nope', 404], ['e=modelx,modelx', 400], ['e=a,b,c,d,e', 400], ['e=modelx,modely,modelz,prodz,acme', 400], ['e=' . rawurlencode('<script>alert(1)</script>,modelx'), 400], ['e=modelx&a=modely', 400], ['a[]=modelx', 400], ['e=MODELX,modely', 400], ['e=' . rawurlencode(str_repeat('x', 3000)), 400]] as [$q, $code]) {
  $r = http($A, 'GET', '/universe-arena.php?' . $q); $m = meta($r['body']); ok($r['code'] === $code && !indexable($m) && $m['h1'] === 1 && strpos($r['body'], '<script>alert(1)</script>') === false, "'" . substr($q, 0, 40) . "' is $code and noindex (got {$r['code']})"); }
foreach (['POST', 'PUT'] as $mth) ok(http($A, $mth, '/universe-arena.php')['code'] === 405, "$mth is 405");
ok(!str_contains(phplog($A), 'PHP Warning') && !str_contains(phplog($A), 'Fatal') && !str_contains(phplog($A), 'Notice'), 'the server log has no PHP warning, notice or fatal after the arena tests: ' . substr(phplog($A), 0, 300));

// ---- sitemap, empty ledger, missing schema ------------------------------------------------------------------------------------------
section('sitemap and robots');
$sm = http($A, 'GET', '/sitemap.xml')['body'];
foreach (['/universe-heatmap.php', '/universe-lineage.php', '/universe-lineage.php?entity=modelx', '/universe-lineage.php?entity=modely', '/universe-arena.php'] as $u) ok(strpos($sm, '<loc>https://aihubpedia.com' . $u . '</loc>') !== false, "sitemap lists $u (verified data exists)");
ok(strpos($sm, 'entity=lonely') === false && strpos($sm, 'entity=acme') === false && strpos($sm, 'leak') === false && strpos($sm, 'universe-arena.php?') === false && strpos($sm, 'universe-heatmap.php?') === false, 'sitemap lists no entity without releases, no leak and no parameterised heatmap or arena URL');
$smE = http($E, 'GET', '/sitemap.xml')['body']; ok(strpos($smE, 'universe-') === false && strpos($smE, '<urlset') !== false, 'sitemap on an empty ledger lists none of the pages');
$smN = http($N, 'GET', '/sitemap.xml'); ok($smN['code'] === 200 && strpos($smN['body'], 'universe-') === false, 'sitemap without the uni_* tables still works and lists none of the pages');
$rb = (string)file_get_contents($root . '/robots.txt'); ok(strpos($rb, 'Disallow: /universe-heatmap.php?*') !== false && strpos($rb, 'Disallow: /universe-api.php') !== false, 'robots.txt blocks the endless parameter space of the heatmap and still blocks the API');

section('empty ledger and missing schema: empty states, noindex, never an error');
foreach ([[$E, 'empty ledger'], [$N, 'no uni_* tables']] as [$S, $label]) {
  foreach (['/universe-heatmap.php' => 'No verified activity yet', '/universe-lineage.php' => 'No verified releases yet', '/universe-arena.php' => 'Not enough verified entities yet'] as $path => $msg) {
    $r = http($S, 'GET', $path); $m = meta($r['body']);
    ok($r['code'] === 200 && !indexable($m) && $m['h1'] === 1 && strpos(main_text($r['body']), $msg) !== false, "$label: $path shows '$msg', noindex, 200");
    ok(strpos($r['body'], 'class="uhCell uhLv') === false && !preg_match('/Fatal error|Warning:|Notice:|PDOException|SQLSTATE/', $r['body']), "$label: $path draws nothing and leaks no error");
  }
  foreach (['/universe-heatmap.php?year=2024', '/universe-lineage.php?entity=modelx', '/universe-arena.php?e=modelx,modely'] as $path) { $r = http($S, 'GET', $path); ok(in_array($r['code'], [200, 404], true) && !indexable(meta($r['body'])), "$label: $path is not a 500 and is noindex (got {$r['code']})"); }
}
$r = http($N, 'GET', '/universe-lineage.php?entity=modelx'); ok(!preg_match('/Fatal error|Warning:|Notice:|PDOException|SQLSTATE/', $r['body']), 'no database error text on a missing schema');

section('AI Universe hub (universe.php): honest status, navigation, sitemap rules');
$r = http($A, 'GET', '/universe.php'); $m = meta($r['body']); $txt = main_text($r['body']);
ok($r['code'] === 200 && indexable($m) && $m['h1'] === 1 && $m['canonical'] === 'https://aihubpedia.com/universe.php' && $m['canon_n'] === 1, 'hub with data: 200, one h1, indexable, self canonical');
foreach (['/universe-graph.php', '/universe-heatmap.php', '/universe-lineage.php', '/universe-arena.php'] as $u) ok(strpos($r['body'], 'href="' . $u . '"') !== false, "hub links to $u");
ok(preg_match('/\d+ verified entit/', $txt) === 1 && preg_match('/\d+ verified events?/', $txt) === 1 && preg_match('/\d+ verified releases?/', $txt) === 1, 'hub shows verified counts taken from the ledger');
ok(strpos($txt, 'leak') === false && stripos($txt, 'proposed') === false, 'hub shows no unverified text');
ok(strpos($r['body'], 'class="uniSubnav"') !== false && strpos($r['body'], 'href="/universe.php" aria-current="page"') !== false, 'hub keeps its own sub-navigation and marks itself as the current page there (the feature is hidden from the public navigation)');
$r2 = http($A, 'GET', '/universe-heatmap.php');
ok(strpos($r2['body'], 'class="uniSubnav"') !== false && strpos($r2['body'], 'href="/universe-heatmap.php" aria-current="page"') !== false && strpos($r2['body'], 'href="/universe.php" aria-current="true"') !== false, 'an experience page marks itself and its section in the navigation');
ok(strpos($sm, '<loc>https://aihubpedia.com/universe.php</loc>') !== false, 'sitemap lists the hub when verified data exists');
foreach ([[$E, 'empty ledger'], [$N, 'no uni_* tables']] as [$S, $label]) {
  $r = http($S, 'GET', '/universe.php'); $m = meta($r['body']);
  ok($r['code'] === 200 && !indexable($m) && $m['h1'] === 1 && substr_count(main_text($r['body']), 'No verified data yet') >= 4 && !preg_match('/Fatal error|Warning:|Notice:|PDOException|SQLSTATE/', $r['body']), "$label: hub says 'No verified data yet' for each experience, noindex, no error");
}
ok(strpos($smE, '/universe.php') === false && strpos($smN['body'], '/universe.php') === false, 'sitemap lists the hub neither on an empty ledger nor without the tables');
foreach (['POST', 'DELETE'] as $mth) ok(http($A, $mth, '/universe.php')['code'] === 405, "hub: $mth is 405");

section('rate limit and headers');
$R = startServer($root, $names[0], $dbHost, $dbUser, $dbPass, ['AIHUB_UNIVERSE_PAGE_RATE_LIMIT' => '30']); $codes = [];
for ($i = 0; $i < 40; $i++) $codes[] = http($R, 'GET', '/universe-arena.php')['code'];
ok($codes[0] === 200 && in_array(429, $codes, true) && !in_array(500, $codes, true), 'the page rate limit answers 429 after the allowance, never 500 ' . implode(',', array_unique($codes)));
$r = http($A, 'GET', '/universe-heatmap.php'); ok(stripos($r['head'], 'X-Content-Type-Options: nosniff') !== false && stripos($r['head'], 'X-Frame-Options') !== false, 'site security headers are present');
foreach (['/uni_pages.php', '/uni_core.php'] as $f) ok(http($A, 'GET', $f)['code'] === 404, "$f is not reachable");

section('no unverified, superseded or identity text anywhere (every 200 body)');
$checked = 0; foreach ($bodies as [$path, $body]) { if (!str_contains($path, 'universe-') || str_contains($path, 'sitemap')) continue; $checked++; foreach ($T['leaks'] as $l) if (stripos($body, $l) !== false) ok(false, "$path contains '$l'"); foreach ($T['identities'] as $i) if (stripos($body, $i) !== false) ok(false, "$path contains the identity '$i'"); }
ok($checked > 40, "scanned $checked page bodies for leaks and identities");

echo "\nPASSED: $pass  FAILED: $fail\n"; exit($fail ? 1 : 0);
