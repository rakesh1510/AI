<?php
/**
 * Phase 7 graph page test (universe-graph.php + assets/universe/) against private scratch databases and local php -S servers.
 * Usage: php tests/phase7_graph_page_test.php
 * Env: AIHUB_COMPAT_ROOT_USER / AIHUB_COMPAT_ROOT_PASS / AIHUB_DB_HOST (same as the other Phase 7 tests); P7_DSN_BASE, P7_USER, P7_PASS override.
 * Creates databases p7gr_<pid> (synthetic ledger seeded THROUGH THE SERVICE FUNCTIONS, plus raw rows for cases the service refuses on purpose) and p7gr_<pid>_e (schema only),
 * serves the app with tests/router.php on ports 8300-8320 and drops both databases at the end. Never touches another database or a remote host.
 * Checks: only verified + current data reaches the server-rendered page and the JSON the graph script reads, empty / unavailable / not-found states, XSS escaping,
 * hostile parameters never produce a 500, no synthetic data or review mode on the production page, built assets served and identical to their sources, sitemap rules.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
ob_start();
require_once $root . '/uni_core.php';
$dsnBase = getenv('P7_DSN_BASE') ?: 'mysql:host=' . (getenv('AIHUB_DB_HOST') ?: '127.0.0.1') . ';port=3306';
$user = getenv('P7_USER') ?: (getenv('AIHUB_COMPAT_ROOT_USER') ?: 'aihub_test');
$pw = getenv('P7_PASS') !== false ? getenv('P7_PASS') : (getenv('AIHUB_COMPAT_ROOT_PASS') !== false ? getenv('AIHUB_COMPAT_ROOT_PASS') : 'test_only_pw');
$dbHost = getenv('AIHUB_DB_HOST') ?: '127.0.0.1';
$dbName = 'p7gr_' . getmypid(); $dbEmpty = $dbName . '_e';
if (!preg_match('/^p7gr_\d+$/', $dbName)) { fwrite(STDERR, "bad db name\n"); exit(2); }
$opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$admin = new PDO($dsnBase, $user, $pw, $opt);
fwrite(STDERR, 'Server: ' . $admin->query('SELECT VERSION()')->fetchColumn() . "\n");
foreach ([$dbName, $dbEmpty] as $n) $admin->exec("CREATE DATABASE `$n` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo = new PDO($dsnBase . ";dbname=$dbName", $user, $pw, $opt);
$pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION,ERROR_FOR_DIVISION_BY_ZERO'");
$pdoE = new PDO($dsnBase . ";dbname=$dbEmpty", $user, $pw, $opt);

$pass = 0; $fail = 0; $servers = []; $tmp = [];
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; fwrite(STDERR, "  FAIL: $m\n"); } }
function section(string $t): void { fwrite(STDERR, "== $t\n"); }
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
function applyFile(PDO $p, string $f): void { foreach (sqlStatements($f) as $s) $p->exec($s); }
function cleanup(): void {
  global $servers, $admin, $dbName, $dbEmpty, $tmp;
  foreach ($servers as $s) { if (is_resource($s)) { @proc_terminate($s); @proc_close($s); } }
  foreach ([$dbName, $dbEmpty] as $n) { try { $admin->exec("DROP DATABASE IF EXISTS `$n`"); } catch (Throwable $e) {} }
  foreach ($tmp as $d) { foreach ((array)@glob($d . '/*') as $f) @unlink($f); @rmdir($d); }
}
register_shutdown_function('cleanup');

/** GET (no network beyond 127.0.0.1). @return array [status, headers(lowercase), body] */
function get(string $base, string $path): array {
  $ctx = stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 30, 'follow_location' => 0]]);
  $raw = @file_get_contents($base . $path, false, $ctx);
  $h = []; $status = 0;
  foreach (($http_response_header ?? []) as $line) {
    if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m)) { $status = (int)$m[1]; $h = []; continue; }
    if (strpos($line, ':') !== false) { [$k, $v] = explode(':', $line, 2); $h[strtolower(trim($k))] = trim($v); }
  }
  return [$status, $h, $raw === false ? '' : $raw];
}
function freePort(int $from, int $to): int {
  for ($p = $from; $p <= $to; $p++) { $s = @stream_socket_server("tcp://127.0.0.1:$p", $e, $m); if ($s) { fclose($s); return $p; } }
  throw new RuntimeException('no free port in range');
}
function startServer(string $root, int $port, array $env) {
  global $servers;
  $proc = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root, $root . '/tests/router.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env + getenv());
  $servers[] = $proc;
  for ($i = 0; $i < 60; $i++) { usleep(100000); $c = @fsockopen('127.0.0.1', $port, $en, $es, 0.2); if ($c) { fclose($c); return $proc; } }
  throw new RuntimeException("server did not start on $port");
}
function robotsOf(string $html): string { return preg_match('/<meta name="robots" content="([^"]*)"/', $html, $m) ? $m[1] : ''; }
function canonicalOf(string $html): string { return preg_match('/<link rel="canonical" href="([^"]*)"/', $html, $m) ? html_entity_decode($m[1]) : ''; }
function must($r, string $what) { if (!is_array($r) || $r[0] !== true) throw new RuntimeException("seed failed: $what " . json_encode($r)); return $r[1]; }

// ---- seed ------------------------------------------------------------------------------------------------------------------------------
foreach ([$pdo, $pdoE] as $p) { applyFile($p, $root . '/database/phase6_crm.sql'); applyFile($p, $root . '/database/phase7_universe.sql'); }
$H = password_hash('test-only-password', PASSWORD_DEFAULT);
$mkUser = function (string $name, string $role) use ($pdo, $H): int { [$ok, $id] = crm_user_create($pdo, $name, ucfirst($name) . 'Display', $role, $H); if (!$ok) throw new RuntimeException("user $name: $id"); return (int)$id; };
$ed1 = $mkUser('pged1', 'editor'); $v1 = $mkUser('pgver1', 'verifier'); $v2 = $mkUser('pgver2', 'verifier'); $v3 = $mkUser('pgver3', 'verifier');
$identities = ['pged1', 'Pged1Display', 'pgver1', 'Pgver1Display', 'pgver2', 'Pgver2Display', 'pgver3', 'Pgver3Display'];
$src = fn(array $o = []) => $o + ['url' => 'https://example.test/' . bin2hex(random_bytes(4)), 'publisher' => 'Example Publisher', 'retrieved_at' => '2025-01-15', 'quote' => 'a synthetic quote that supports the claim'];
$publish = function (string $type, int $id) use ($pdo, $ed1, $v1, $v2, $v3): void {
  must(uni_submit_for_review($pdo, $ed1, $type, $id), "submit $type $id");
  foreach ([$v1, $v2, $v3] as $v) { $r = must(uni_review($pdo, $v, $type, $id, 'accept'), "review $type $id"); if ($r['state'] === 'verified') return; }
  throw new RuntimeException("not verified $type $id");
};
$entity = function (string $slug, string $type, string $name, string $summary = 'a synthetic summary') use ($pdo, $ed1, $src): int {
  $id = (int)must(uni_propose_entity($pdo, $ed1, ['entity_type' => $type, 'slug' => $slug, 'name' => $name, 'summary' => $summary]), "entity $slug");
  must(uni_attach_source($pdo, $ed1, 'entity', $id, $src()), "entity source $slug"); return $id;
};
$vEntity = function (string $slug, string $type, string $name, string $summary = 'a synthetic summary') use ($entity, $publish): int { $id = $entity($slug, $type, $name, $summary); $publish('entity', $id); return $id; };
$edge = function (int $from, int $to, string $type, string $notes = '', array $srcOver = []) use ($pdo, $ed1, $src): int {
  $id = (int)must(uni_propose_edge($pdo, $ed1, ['from_entity_id' => $from, 'to_entity_id' => $to, 'edge_type' => $type, 'notes' => $notes]), "edge $type");
  must(uni_attach_source($pdo, $ed1, 'edge', $id, $src($srcOver)), "edge source $type"); return $id;
};
$vEdge = function (int $from, int $to, string $type, array $srcOver = []) use ($edge, $publish): int { $id = $edge($from, $to, $type, '', $srcOver); $publish('edge', $id); return $id; };
$forceVerify = function (string $table, int $id) use ($pdo, $v1): void {   // verified rows the service would never create (endpoint not verified): raw SQL in a scratch database
  $rid = (int)val($pdo, "SELECT id FROM uni_review WHERE decision = 'accept' ORDER BY id LIMIT 1");
  $pdo->prepare("UPDATE $table SET state = 'verified', verified_by = ?, verified_at = ?, review_id = ? WHERE id = ?")->execute([$v1, gmdate('Y-m-d H:i:s'), $rid, $id]);
};
function val(PDO $p, string $sql, array $a = []) { $s = $p->prepare($sql); $s->execute($a); return $s->fetchColumn(); }

section('seeding a synthetic ledger through the service layer');
$XSS_NAME = '<img src=x onerror=alert(1)>XSSNAME'; $QUOTE_NAME = 'O\'Brien & "Sons" <b>Ltd</b>'; $XSS_PUB = '<script>alert(3)</script>PUBXSS';
$acme = $vEntity('acme', 'company', 'Acme Labs', 'Acme summary <i>text</i>');
$xssCo = $vEntity('xss-co', 'company', $XSS_NAME); $quoteCo = $vEntity('quote-co', 'company', $QUOTE_NAME);
$mx = $vEntity('modelx', 'model', 'Model X'); $my = $vEntity('modely', 'model', 'Model Y'); $pz = $vEntity('prodz', 'product', 'Prod Z'); $ver = $vEntity('modelx-v2', 'version', 'Model X v2'); $lonely = $vEntity('lonely', 'model', 'Lonely Model');
$sats = []; for ($i = 1; $i <= 52; $i++) $sats[$i] = $vEntity(sprintf('sat-%02d', $i), 'product', sprintf('Satellite %02d', $i));   // enough entities for a second list page
$ren = $vEntity('renamed-co', 'company', 'OLDNAMELEAK Co'); $newV = (int)must(uni_correct($pdo, $ed1, 'entity', $ren, ['name' => 'Renamed Co']), 'correct entity'); $publish('entity', $newV);
$visible = 3 + 5 + 52 + 1;   // acme, xss-co, quote-co; modelx, modely, prodz, modelx-v2, lonely; 52 satellites; the corrected renamed-co
$gp = $entity('leak-ghost-proposed', 'model', 'LEAK Ghost Proposed'); $gr = $entity('leak-ghost-review', 'model', 'LEAK Ghost Review'); must(uni_submit_for_review($pdo, $ed1, 'entity', $gr), 'submit gr');
$gx = $entity('leak-ghost-rejected', 'model', 'LEAK Ghost Rejected'); must(uni_submit_for_review($pdo, $ed1, 'entity', $gx), 'submit gx'); must(uni_review($pdo, $v1, 'entity', $gx, 'reject', 'LEAKNOTE reviewer note'), 'reject gx');
$hiddenSlugs = ['leak-ghost-proposed', 'leak-ghost-review', 'leak-ghost-rejected'];

for ($i = 1; $i <= 12; $i++) $vEdge($sats[$i], $acme, 'developed_by');
$vEdge($mx, $acme, 'developed_by'); $vEdge($my, $acme, 'developed_by'); $vEdge($pz, $acme, 'integrates_with');
$vEdge($ver, $mx, 'version_of'); $vEdge($my, $mx, 'derived_from'); $vEdge($my, $mx, 'successor_of'); $vEdge($pz, $mx, 'built_on'); $vEdge($mx, $pz, 'hosted_on');
$vEdge($xssCo, $acme, 'acquired_by', ['publisher' => $XSS_PUB]); $vEdge($quoteCo, $acme, 'renamed_from');
$acmeEdges = 12 + 2 + 1 + 1 + 1;   // verified edges touching acme: 12 satellites + modelx + modely (developed_by), prodz integrates_with, xss-co acquired_by, quote-co renamed_from
$e1 = $edge($acme, $mx, 'acquired_by', 'LEAKNOTE edge note');                                                    // proposed
$e2 = $edge($acme, $my, 'acquired_by', 'LEAKNOTE edge note'); must(uni_submit_for_review($pdo, $ed1, 'edge', $e2), 'submit e2');   // in review
$e3 = $edge($acme, $lonely, 'hosted_on', 'LEAKNOTE edge note'); must(uni_submit_for_review($pdo, $ed1, 'edge', $e3), 'submit e3'); must(uni_review($pdo, $v1, 'edge', $e3, 'reject', 'LEAKNOTE'), 'reject e3');
$e4 = $edge($gp, $acme, 'developed_by', 'LEAKNOTE'); $forceVerify('uni_edge', $e4);                                // verified edge, unverified endpoint
$e5 = $edge($lonely, $gr, 'built_on', 'LEAKNOTE'); $forceVerify('uni_edge', $e5);
fwrite(STDERR, "seeded: $visible visible entities, $acmeEdges verified edges at acme\n");
ok((int)val($pdo, "SELECT COUNT(*) FROM uni_entity WHERE state = 'verified' AND is_current = 1") === $visible, 'seed holds exactly the expected number of verified current entities');

// ---- start the servers -------------------------------------------------------------------------------------------------------------------
$mkdir = function (string $p) use (&$tmp): string { $d = sys_get_temp_dir() . '/' . $p . '_' . getmypid(); @mkdir($d, 0700); $tmp[] = $d; return $d; };
$mk = fn(string $db, string $tag) => ['AIHUB_DB_HOST' => $dbHost, 'AIHUB_DB_NAME' => $db, 'AIHUB_DB_USER' => $user, 'AIHUB_DB_PASS' => $pw, 'AIHUB_FORM_SECRET' => 'graph-test-secret-not-for-production',
  'AIHUB_SECURITY_LOG' => $mkdir("p7gr_log$tag") . '/sec.log', 'AIHUB_RATE_DIR' => $mkdir("p7gr_rate$tag"), 'AIHUB_UNIVERSE_RATE_LIMIT' => '10000'];
$port = freePort(8300, 8320); startServer($root, $port, $mk($dbName, 'a')); $B = "http://127.0.0.1:$port";
$portE = freePort($port + 1, 8320); startServer($root, $portE, $mk($dbEmpty, 'b')); $BE = "http://127.0.0.1:$portE";
$portX = freePort($portE + 1, 8320); startServer($root, $portX, $mk('p7gr_does_not_exist', 'c')); $BX = "http://127.0.0.1:$portX";

section('graph page with verified data');
[$st, $h, $html] = get($B, '/universe-graph.php');
ok($st === 200, "page answers 200 (got $st)");
ok(strncmp($html, '<!doctype html>', 15) === 0, 'starts with the doctype (no leading output)');
ok(substr_count($html, '<h1') === 1, 'exactly one h1'); ok(substr_count($html, '<title>') === 1, 'exactly one title');
ok(stripos(robotsOf($html), 'noindex') === false && stripos($h['x-robots-tag'] ?? '', 'noindex') === false, 'indexable when verified data exists (robots: ' . robotsOf($html) . ')');
ok(canonicalOf($html) === 'https://aihubpedia.com/universe-graph.php', 'canonical is the clean URL (' . canonicalOf($html) . ')');
ok(strpos($html, 'Acme Labs') !== false && strpos($html, 'Model X') !== false, 'verified entities are server-rendered in the list');
ok(strpos($html, '<table') !== false && strpos($html, 'scope="row"') !== false && strpos($html, '<caption>') !== false, 'accessible table with caption and row headers');
foreach (['LEAK', 'OLDNAMELEAK', 'LEAKNOTE', 'leak-ghost'] as $leak) ok(strpos($html, $leak) === false, "page never contains '$leak'");
foreach ($identities as $idn) ok(stripos($html, $idn) === false, "page never contains the identity '$idn'");
ok(strpos($html, 'Renamed Co') !== false, 'the corrected (current) entity version is shown');
ok(strpos($html, 'No verified data yet') === false, 'no empty-state text when data exists');
ok(substr_count($html, '<a href="/universe-graph.php?slug=') >= 40, 'entity names are real deep links (no JavaScript needed)');
ok(strpos($html, 'rel="next"') !== false && strpos($html, 'page=2') !== false && strpos($html, 'rel="prev"') === false, 'first list page links to page 2 only');
// escaping
ok(strpos($html, $XSS_NAME) === false && strpos($html, '&lt;img src=x onerror=alert(1)&gt;XSSNAME') !== false, 'entity name with markup is escaped in the list');
ok(strpos($html, '<img src=x') === false && strpos($html, '<b>Ltd</b>') === false, 'no raw markup from entity names reaches the page');
ok(strpos($html, 'O&#039;Brien &amp; &quot;Sons&quot; &lt;b&gt;Ltd&lt;/b&gt;') !== false, 'quotes and ampersands are escaped');
// production page offers no synthetic data and no review mode
ok(stripos($html, 'synthetic') === false, 'production page never mentions synthetic data');
ok(strpos($html, 'data-gen.js') === false && strpos($html, 'srcSel') === false && strpos($html, 'reviewBanner') === false && strpos($html, 'UNIVERSE_REVIEW_ENABLED') === false, 'no synthetic generator, source selector or review switch on the page');
ok(preg_match('/window\.UNIVERSE_GRAPH_CONFIG=(\{[^<]*\});<\/script>/', $html, $m) === 1, 'graph config is present');
$cfg = json_decode($m[1] ?? 'null', true);
ok(is_array($cfg) && $cfg['api'] === '/universe-api.php' && array_keys($cfg) === ['api', 'slug', 'syncQuery', 'staticId'] && $cfg['slug'] === null, 'config holds only the API path and the deep-link slug (no data)');
ok(strpos($html, 'id="ugapp" hidden') !== false && strpos($html, '<details id="uni-static"') !== false, 'app is hidden until scripts run; the plain list is the default (progressive enhancement)');
// scripts in the page
preg_match_all('/<script[^>]*>(.*?)<\/script>/s', $html, $inline); $inlineJs = implode("\n", $inline[1]);
foreach (['Acme', 'Model X', 'XSSNAME', 'sat-01'] as $needle) ok(strpos($inlineJs, $needle) === false, "no entity data inside page scripts ('$needle')");
// assets
section('built assets are served and identical to their sources');
preg_match_all('#(?:src|href)="(/assets/universe/[^"?]+)\?v=(\d+)"#', $html, $as);
$assetPaths = array_unique($as[1]);
ok(count($assetPaths) === 14, 'page references graph.css, 11 d3 files, data-source.js and graph.js (' . count($assetPaths) . ')');
foreach ($assetPaths as $ap) {
  [$s2, $h2, $body] = get($B, $ap);
  $srcFile = $root . '/prototypes/universe-graph/' . substr($ap, strlen('/assets/universe/'));
  ok($s2 === 200 && is_file($srcFile) && sha1($body) === sha1_file($srcFile), "$ap is served and identical to prototypes/universe-graph/" . substr($ap, 17));
  ok(strpos($h2['content-type'] ?? '', strpos($ap, '.css') !== false ? 'text/css' : 'javascript') !== false || strpos($h2['content-type'] ?? '', 'text/') === 0, "$ap content type (" . ($h2['content-type'] ?? '') . ')');
}
[$s2, , $body] = get($B, '/assets/universe/layout-worker.js'); ok($s2 === 200 && strpos($body, 'importScripts') !== false, 'layout worker is served');
foreach (['/assets/universe/vendor/d3-force.min.js', '/assets/universe/vendor/d3-force.LICENSE'] as $f) { [$s2] = get($B, $f); ok($s2 === 200, "$f is served"); }
ok(!is_file($root . '/assets/universe/data-gen.js') && !glob($root . '/assets/universe/*gen*') && !glob($root . '/assets/universe/*synthetic*'), 'assets/universe contains no synthetic data generator');
$gjs = (string)file_get_contents($root . '/assets/universe/graph.js'); $dsjs = (string)file_get_contents($root . '/assets/universe/data-source.js');
ok(strpos($gjs, 'UNIVERSE_GRAPH_CONFIG') !== false && preg_match('/IS_HTTP = CFG \? true/', $gjs) === 1, 'graph.js forces the HTTP source when the page config is present');
ok(preg_match('/mode: \(!IS_HTTP && window\.UNIVERSE_REVIEW_ENABLED === true/', $gjs) === 1, 'review mode can never be enabled for the HTTP source');
preg_match_all('/\.innerHTML\s*=\s*([^;]*);/', preg_replace('#/\*.*?\*/#s', '', $gjs), $ih);
ok(count($ih[1]) === 4 && strpos($dsjs, 'innerHTML') === false, 'innerHTML is assigned at exactly 4 sites in graph.js and never in data-source.js (' . count($ih[1]) . ')');
foreach ($ih[1] as $rhs) ok(preg_match('/^(swatch\(rel\)|typeSwatch\(ty\)|\'<input type="checkbox" checked data-t="\' \+ t \+ \'">\' \+ typeSwatch\(t\) \+ t|h)\s*(\/\/.*)?$/', trim($rhs)) === 1, 'innerHTML source is a static swatch / taxonomy-built string: ' . substr(trim($rhs), 0, 60));
foreach (['p7gr_', 'LEAK'] as $x) ok(strpos($gjs . $dsjs, $x) === false, "no test strings in shipped JS ($x)");

section('the JSON the graph script reads is verified-only');
$api = function (string $q) use ($B): array { [$s, , $b] = get($B, '/universe-api.php?' . $q); return [$s, json_decode($b, true), $b]; };
$allSlugs = []; $allRefs = []; $payloads = '';
for ($pg = 1; $pg <= 2; $pg++) { [$s, $j, $b] = $api("r=entities&page=$pg&per_page=100"); ok($s === 200, "entities page $pg"); $payloads .= $b; foreach ($j['data']['items'] ?? [] as $it) { $allSlugs[] = $it['slug']; $allRefs[$it['ref']] = $it['slug']; } }
ok(count($allSlugs) === $visible && count(array_unique($allSlugs)) === $visible, "the entity pages carry exactly the $visible verified entities");
foreach ($hiddenSlugs as $hs) ok(!in_array($hs, $allSlugs, true), "$hs never reaches the JSON");
$edgeTotal = 0; $checked = 0;
foreach (['acme', 'modelx', 'prodz', 'xss-co', 'lonely', 'sat-01'] as $sl) {
  [$s, $j, $b] = $api('r=graph&slug=' . $sl . '&limit=200'); $payloads .= $b; ok($s === 200, "graph for $sl");
  foreach ($j['data']['nodes'] ?? [] as $n) ok(isset($allRefs[$n['ref']]) && $allRefs[$n['ref']] === $n['slug'], "graph node {$n['slug']} is a verified entity");
  foreach ($j['data']['edges'] ?? [] as $e) { $checked++; ok(isset($allRefs[$e['from']]) && isset($allRefs[$e['to']]) && $e['sources'] !== [] && !empty($e['sources'][0]['retrieved_at']) && !empty($e['sources'][0]['url']), 'graph edge connects verified entities and carries a source with a retrieval date'); }
  if ($sl === 'acme') { $edgeTotal = $j['data']['total_edges']; ok($edgeTotal === $acmeEdges, "acme has $acmeEdges verified relationships (got $edgeTotal)"); }
  if ($sl === 'lonely') ok(($j['data']['edges'] ?? null) === [] && $j['data']['total_edges'] === 0, 'an entity with only unverified relationships has none in the graph');
}
[$s, $j, $b] = $api('r=entity&slug=acme'); $payloads .= $b; ok($s === 200 && !empty($j['data']['sources'][0]['url']) && !empty($j['data']['verified_at']), 'entity detail has sources and a verification date');
foreach (['LEAK', 'OLDNAMELEAK', 'LEAKNOTE'] as $leak) ok(strpos($payloads, $leak) === false, "no '$leak' in any JSON the script reads");
foreach ($identities as $idn) ok(stripos($payloads, $idn) === false, "no identity '$idn' in the JSON");
ok($checked > 20, "checked $checked edges");

section('deep link ?slug=');
[$st, $h, $html] = get($B, '/universe-graph.php?slug=acme');
ok($st === 200 && stripos(robotsOf($html), 'noindex') === false, 'verified entity deep link is 200 and indexable');
ok(canonicalOf($html) === 'https://aihubpedia.com/universe-graph.php?slug=acme', 'deep link canonical keeps ?slug= (' . canonicalOf($html) . ')');
ok(strpos($html, '<title>Acme Labs in the AI Universe graph') !== false, 'title names the entity');
ok(substr_count($html, '<h1') === 1, 'deep link has one h1');
ok(strpos($html, 'Acme summary &lt;i&gt;text&lt;/i&gt;') !== false, 'summary is escaped');
preg_match('#<table class="pretty">\s*<caption>Verified relationships of Acme Labs.*?</table>#s', $html, $tm);
$rows = isset($tm[0]) ? preg_match_all('#<tr>\s*<th scope="row">#', $tm[0]) : 0;
ok($rows === $acmeEdges, "relationship table lists exactly the $acmeEdges verified relationships ($rows)");
ok(isset($tm[0]) && strpos($tm[0], 'developed_by') !== false && strpos($tm[0], 'integrates_with') !== false && strpos($tm[0], 'acquired_by') !== false && strpos($tm[0], 'renamed_from') !== false, 'relationship types are kept separate');
ok(isset($tm[0]) && substr_count($tm[0], 'retrieved 2025-01-15') >= $acmeEdges && substr_count($tm[0], 'rel="nofollow noopener"') >= $acmeEdges, 'every relationship shows a source link and its retrieval date');
ok(strpos($html, $XSS_PUB) === false && strpos($html, '&lt;script&gt;alert(3)&lt;/script&gt;PUBXSS') !== false, 'hostile publisher text is escaped');
ok(strpos($html, 'LEAK') === false && strpos($html, 'acquired_by</th>') !== false, 'unverified acquired_by edges of acme are absent but the verified one is listed');
preg_match('/UNIVERSE_GRAPH_CONFIG=(\{[^<]*\});/', $html, $m); $cfg = json_decode($m[1] ?? 'null', true); ok(($cfg['slug'] ?? null) === 'acme', 'config carries the validated slug for the app');
[$st, , $html] = get($B, '/universe-graph.php?slug=lonely'); ok($st === 200 && strpos($html, 'No verified relationships yet.') !== false, 'entity without verified relationships says so honestly');
[$st, , $html] = get($B, '/universe-graph.php?slug=xss-co'); ok($st === 200 && strpos($html, '&lt;img src=x onerror=alert(1)&gt;XSSNAME') !== false && strpos($html, '<img src=x') === false, 'hostile entity name is escaped on its own page (h1/title/breadcrumbs)');
ok(strpos($html, '<title>&lt;img') !== false || strpos($html, '<title>') !== false && strpos($html, '<title><img') === false, 'title is escaped');
[$st, , $html] = get($B, '/universe-graph.php?page=2');
ok($st === 200 && strpos($html, 'rel="prev"') !== false && strpos($html, 'rel="next"') === false && stripos(robotsOf($html), 'noindex') === false, 'second list page links back and is indexable');
ok(canonicalOf($html) === 'https://aihubpedia.com/universe-graph.php?page=2', 'page 2 canonical keeps ?page=2');
[$st, $h, $html] = get($B, '/universe-graph.php?page=3');
ok($st === 404 && stripos(robotsOf($html), 'noindex') !== false, "page beyond the end is 404 noindex (got $st)");

section('unknown entity, hostile parameters');
foreach (['does-not-exist', 'leak-ghost-proposed', 'leak-ghost-review', 'leak-ghost-rejected', 'renamed-co-old'] as $sl) {
  [$st, $h, $html] = get($B, '/universe-graph.php?slug=' . $sl);
  ok($st === 404 && stripos(robotsOf($html), 'noindex') !== false && strpos($html, 'LEAK') === false && strpos($html, 'Not found among the verified entities') !== false, "$sl is 404 noindex and indistinguishable from unknown");
}
$hostile = ['slug=../../etc/passwd', 'slug=' . rawurlencode('<script>alert(1)</script>'), 'slug[]=acme', 'slug[a]=b', 'slug=' . str_repeat('a', 5000), 'slug=%00', 'slug=ACME', 'slug=' . rawurlencode("acme'--"), 'slug=' . rawurlencode('acme" onmouseover="x'),
  'slug=%C3%A9', 'slug=%FF%FE', 'page=abc', 'page=-1', 'page=0', 'page=99999999999999', 'page[]=1', 'page=1.5', 'page=1e3', 'page=' . str_repeat('9', 400), 'mode=review', 'mode=review&UNIVERSE_REVIEW_ENABLED=1', 'source=synthetic', 'foo[]=bar&foo[]=baz',
  'slug=acme&page=2', 'slug=acme&slug=modelx', 'q=' . rawurlencode('<img src=x>'), '%00=1', 'slug=acme%0d%0aX-Injected:1', str_repeat('a=1&', 600)];
$bad = [];
foreach ($hostile as $q) {
  [$st, $h, $html] = get($B, '/universe-graph.php?' . $q);
  if ($st >= 500 || !in_array($st, [200, 404], true)) $bad[] = "$q -> $st";
  if (strpos($html, '<img src=x') !== false || strpos($html, '<script>alert(1)') !== false || stripos($html, 'Fatal error') !== false || stripos($html, 'Stack trace') !== false || stripos($html, 'PDOException') !== false) $bad[] = "$q -> unsafe output";
  if (isset($h['x-injected'])) $bad[] = "$q -> header injection";
  if ($st === 404 && stripos(robotsOf($html), 'noindex') === false) $bad[] = "$q -> 404 without noindex";
}
ok($bad === [], 'hostile parameters: never 5xx, never unescaped output, 404s are noindex: ' . implode('; ', $bad));
[$st, , $html] = get($B, '/universe-graph.php?mode=review&UNIVERSE_REVIEW_ENABLED=1&source=synthetic');
ok(stripos($html, 'synthetic') === false && strpos($html, 'REVIEW MODE') === false && strpos($html, 'reviewBanner') === false, 'review mode and synthetic data cannot be requested through the URL');
[$st, $h] = get($B, '/universe-graph.php?slug=acme'); ok(preg_match('/^\d+$/', (string)$st) === 1 && !isset($h['set-cookie']), 'no cookie is set by the page');

section('empty ledger and unavailable data');
[$st, $h, $html] = get($BE, '/universe-graph.php');
ok($st === 200, "empty ledger answers 200 (got $st)");
ok(strpos($html, 'No verified data yet') !== false, 'honest empty state: "No verified data yet"');
ok(stripos(robotsOf($html), 'noindex') !== false && stripos($h['x-robots-tag'] ?? '', 'noindex') !== false, 'empty ledger is noindex (meta and header)');
ok(strpos($html, 'id="ugapp"') === false && strpos($html, '/assets/universe/') === false && strpos($html, '<table') === false, 'no graph app, no scripts, no tables without data');
ok(substr_count($html, '<h1') === 1, 'empty state has one h1');
[$st, , $html] = get($BE, '/universe-graph.php?slug=acme'); ok($st === 200 && strpos($html, 'No verified data yet') !== false && stripos(robotsOf($html), 'noindex') !== false, 'a slug on an empty ledger shows the empty state, noindex');
[$st, $h, $html] = get($BX, '/universe-graph.php');
ok($st === 503 && stripos(robotsOf($html), 'noindex') !== false && strpos($html, 'temporarily unavailable') !== false, "unreachable database: 503, noindex, honest message (got $st)");
ok(stripos($html, 'SQLSTATE') === false && stripos($html, 'p7gr_does_not_exist') === false && stripos($html, 'PDOException') === false, 'no database details leak');
// tables missing entirely (database exists but the Phase 7 schema was never applied)
$admin->exec("CREATE DATABASE IF NOT EXISTS `{$dbEmpty}_n` CHARACTER SET utf8mb4"); $tmpDb = $dbEmpty . '_n';
register_shutdown_function(function () use ($admin, $tmpDb) { try { $admin->exec("DROP DATABASE IF EXISTS `$tmpDb`"); } catch (Throwable $e) {} });
$portN = freePort($portX + 1, 8320); startServer($root, $portN, $mk($tmpDb, 'd'));
[$st, , $html] = get("http://127.0.0.1:$portN", '/universe-graph.php'); ok($st === 503 && stripos(robotsOf($html), 'noindex') !== false, "missing Phase 7 tables: 503 noindex (got $st)");

section('sitemap and robots rules');
[$st, , $xml] = get($B, '/sitemap.xml');
ok($st === 200 && strpos($xml, '<loc>https://aihubpedia.com/universe-graph.php</loc>') !== false && strpos($xml, 'universe-graph.php?slug=acme') !== false, "sitemap lists the graph page and verified entity pages when verified data exists (status $st)");
ok(strpos($xml, 'leak-ghost') === false && strpos($xml, 'renamed-co') !== false, 'sitemap lists no unverified entity');
[$st, , $xml] = get($BE, '/sitemap.xml'); ok($st === 200 && strpos($xml, 'universe-graph.php') === false, 'sitemap omits the graph page while there is no verified data');
[$st, , $xml] = get($BX, '/sitemap.xml'); ok(strpos($xml, 'universe-graph.php') === false, 'sitemap omits the graph page when the ledger cannot be read');
$robots = (string)file_get_contents($root . '/robots.txt');
ok(strpos($robots, 'universe-graph') === false && preg_match('#^Disallow:\s*/(assets|assets/universe)#m', $robots) === 0, 'robots.txt does not block the page or its assets (the page controls indexing with noindex)');
$man = json_decode((string)file_get_contents($root . '/tests/security_manifest.json'), true)['files'];
ok(($man['universe-graph.php'] ?? '') === 'page', 'universe-graph.php is registered in the security manifest as a page');
[$st, $h] = get($B, '/universe-graph.php'); ok(isset($h['x-content-type-options']) && isset($h['referrer-policy']), 'site security headers are sent');

$bufOut = ob_get_clean();
echo "\nPASSED: $pass  FAILED: $fail\n";
exit($fail ? 1 : 0);
