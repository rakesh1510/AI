<?php
/**
 * Phase 7 read API test (universe-api.php) against a private scratch database and a local php -S server. Usage: php tests/phase7_api_test.php
 * Env: AIHUB_COMPAT_ROOT_USER / AIHUB_COMPAT_ROOT_PASS / AIHUB_DB_HOST (same as the service test); P7_DSN_BASE, P7_USER, P7_PASS override.
 * Creates database p7api_<pid>, seeds a synthetic ledger THROUGH THE SERVICE FUNCTIONS (plus a few raw rows for cases the service refuses on purpose),
 * serves the app with tests/router.php on a port between 8210 and 8230, and drops the database at the end. Never touches another database or a remote host.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
ob_start();
require_once $root . '/uni_core.php';
$dsnBase = getenv('P7_DSN_BASE') ?: 'mysql:host=' . (getenv('AIHUB_DB_HOST') ?: '127.0.0.1') . ';port=3306';
$user = getenv('P7_USER') ?: (getenv('AIHUB_COMPAT_ROOT_USER') ?: 'aihub_test');
$pw = getenv('P7_PASS') !== false ? getenv('P7_PASS') : (getenv('AIHUB_COMPAT_ROOT_PASS') !== false ? getenv('AIHUB_COMPAT_ROOT_PASS') : 'test_only_pw');
$dbHost = getenv('AIHUB_DB_HOST') ?: '127.0.0.1';
$dbName = 'p7api_' . getmypid();
if (!preg_match('/^p7api_\d+$/', $dbName)) { fwrite(STDERR, "bad db name\n"); exit(2); }
$opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$admin = new PDO($dsnBase, $user, $pw, $opt);
fwrite(STDERR, 'Server: ' . $admin->query('SELECT VERSION()')->fetchColumn() . "\n");
$admin->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo = new PDO($dsnBase . ";dbname=$dbName", $user, $pw, $opt);
$pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION,ERROR_FOR_DIVISION_BY_ZERO'");

$pass = 0; $fail = 0; $servers = []; $tmp = [];
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; fwrite(STDERR, "  FAIL: $m\n"); } }
function section(string $t): void { fwrite(STDERR, "== $t\n"); }
function val(PDO $p, string $sql, array $a = []) { $s = $p->prepare($sql); $s->execute($a); return $s->fetchColumn(); }
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
  global $servers, $admin, $dbName, $tmp;
  foreach ($servers as $s) { if (is_resource($s)) { @proc_terminate($s); @proc_close($s); } }
  try { $admin->exec("DROP DATABASE IF EXISTS `$dbName`"); } catch (Throwable $e) {}
  foreach ($tmp as $d) { foreach ((array)@glob($d . '/*') as $f) @unlink($f); @rmdir($d); }
}
register_shutdown_function('cleanup');

// ---- HTTP client (no network beyond 127.0.0.1) -------------------------------------------------------------------------------------------
$bodies = [];   // every decoded 200 response, scanned for leaks at the end
function http(string $base, string $method, string $path, array $headers = []): array {
  global $bodies;
  $ctx = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 20, 'header' => implode("\r\n", $headers), 'content' => $method === 'POST' || $method === 'PUT' ? 'x=1' : '']]);
  $raw = @file_get_contents($base . $path, false, $ctx);
  $h = []; $status = 0;
  foreach (($http_response_header ?? []) as $line) {
    if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m)) { $status = (int)$m[1]; $h = []; continue; }
    if (strpos($line, ':') !== false) { [$k, $v] = explode(':', $line, 2); $h[strtolower(trim($k))] = trim($v); }
  }
  $json = $raw === false || $raw === '' ? null : json_decode($raw, true);
  if ($status === 200 && is_array($json)) $bodies[] = [$path, $raw, $json];
  return [$status, $h, (string)$raw, $json];
}
/** GET helper returning [status, data|null, error|null]. */
function api(string $base, string $query): array {
  [$st, , , $j] = http($base, 'GET', '/universe-api.php?' . $query);
  return [$st, is_array($j) ? ($j['data'] ?? null) : null, is_array($j) ? ($j['error'] ?? null) : null];
}
function freePort(int $from, int $to): int {
  for ($p = $from; $p <= $to; $p++) { $s = @stream_socket_server("tcp://127.0.0.1:$p", $e, $m); if ($s) { fclose($s); return $p; } }
  throw new RuntimeException('no free port in range');
}
function startServer(string $root, int $port, array $env) {
  global $servers, $tmp;
  $proc = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root, $root . '/tests/router.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env + getenv());
  $servers[] = $proc;
  for ($i = 0; $i < 60; $i++) { usleep(100000); $c = @fsockopen('127.0.0.1', $port, $en, $es, 0.2); if ($c) { fclose($c); return $proc; } }
  throw new RuntimeException("server did not start on $port");
}

// ---- seed ------------------------------------------------------------------------------------------------------------------------------
applyFile($pdo, $root . '/database/phase6_crm.sql');
applyFile($pdo, $root . '/database/phase7_universe.sql');
$H = password_hash('test-only-password', PASSWORD_DEFAULT);
$mkUser = function (string $name, string $role) use ($pdo, $H): int { [$ok, $id] = crm_user_create($pdo, $name, ucfirst($name) . 'Display', $role, $H); if (!$ok) throw new RuntimeException("user $name: $id"); return (int)$id; };
$ed1 = $mkUser('apied1', 'editor'); $v1 = $mkUser('apiver1', 'verifier'); $v2 = $mkUser('apiver2', 'verifier'); $v3 = $mkUser('apiver3', 'verifier');
$identities = ['apied1', 'Apied1Display', 'apiver1', 'Apiver1Display', 'apiver2', 'Apiver2Display', 'apiver3', 'Apiver3Display'];
$src = fn(array $o = []) => $o + ['url' => 'https://example.test/' . bin2hex(random_bytes(4)), 'publisher' => 'Example Publisher', 'retrieved_at' => '2025-01-15', 'quote' => 'a synthetic quote that supports the claim'];
function must($r, string $what) { if (!is_array($r) || $r[0] !== true) throw new RuntimeException("seed failed: $what " . json_encode($r)); return $r[1]; }
$publish = function (string $type, int $id) use ($pdo, $ed1, $v1, $v2, $v3): void {
  must(uni_submit_for_review($pdo, $ed1, $type, $id), "submit $type $id");
  foreach ([$v1, $v2, $v3] as $v) { $r = must(uni_review($pdo, $v, $type, $id, 'accept'), "review $type $id"); if ($r['state'] === 'verified') return; }
  throw new RuntimeException("not verified $type $id");
};
$entity = function (string $slug, string $type, string $name = null, string $summary = 'a synthetic summary') use ($pdo, $ed1, $src): int {
  $id = (int)must(uni_propose_entity($pdo, $ed1, ['entity_type' => $type, 'slug' => $slug, 'name' => $name ?? "Entity $slug", 'summary' => $summary]), "entity $slug");
  must(uni_attach_source($pdo, $ed1, 'entity', $id, $src()), "entity source $slug"); return $id;
};
$vEntity = function (string $slug, string $type, string $name = null, string $summary = 'a synthetic summary') use ($entity, $publish): int { $id = $entity($slug, $type, $name, $summary); $publish('entity', $id); return $id; };
$event = function (int $entity, string $type, string $date, string $title) use ($pdo, $ed1, $src): int {
  $r = must(uni_propose_event($pdo, $ed1, ['entity_id' => $entity, 'event_type' => $type, 'event_date' => $date, 'title' => $title, 'summary' => 'synthetic event summary']), "event $title");
  $id = (int)$r['id']; must(uni_attach_source($pdo, $ed1, 'event', $id, $src()), "event source $title"); return $id;
};
$vEvent = function (int $entity, string $type, string $date, string $title) use ($event, $publish): int { $id = $event($entity, $type, $date, $title); $publish('event', $id); return $id; };
$edge = function (int $from, int $to, string $type, string $notes = '', ?string $vf = null) use ($pdo, $ed1, $src): int {
  $id = (int)must(uni_propose_edge($pdo, $ed1, ['from_entity_id' => $from, 'to_entity_id' => $to, 'edge_type' => $type, 'notes' => $notes, 'valid_from' => $vf]), "edge $type");
  must(uni_attach_source($pdo, $ed1, 'edge', $id, $src()), "edge source $type"); return $id;
};
$vEdge = function (int $from, int $to, string $type) use ($edge, $publish): int { $id = $edge($from, $to, $type); $publish('edge', $id); return $id; };
$release = function (int $entity, string $label, string $date, ?array $cs, array $parents = []) use ($pdo, $ed1, $src): int {
  $sid = (int)must(uni_source_create($pdo, $ed1, $src()), 'release source');
  return (int)must(uni_propose_release($pdo, $ed1, ['entity_id' => $entity, 'version_label' => $label, 'release_date' => $date, 'source_id' => $sid, 'change_set' => $cs, 'parents' => array_map(fn($p) => [$p, $sid], $parents)]), "release $label");
};
$vRelease = function (int $entity, string $label, string $date, ?array $cs, array $parents = []) use ($release, $publish): int { $id = $release($entity, $label, $date, $cs, $parents); $publish('release', $id); return $id; };
$forceVerify = function (string $table, int $id) use ($pdo, $v1): void {   // verified rows the service would never create (entity not verified): raw SQL in a scratch database
  $rid = (int)val($pdo, "SELECT id FROM uni_review WHERE decision = 'accept' ORDER BY id LIMIT 1");
  $pdo->prepare("UPDATE $table SET state = 'verified', verified_by = ?, verified_at = ?, review_id = ? WHERE id = ?")->execute([$v1, gmdate('Y-m-d H:i:s'), $rid, $id]);
};

section('seeding a synthetic ledger through the service layer');
$acme = $vEntity('acme', 'company'); $mx = $vEntity('modelx', 'model', 'Model X'); $my = $vEntity('modely', 'model', 'Model Y'); $pz = $vEntity('prodz', 'product'); $lonely = $vEntity('lonely', 'model');
$sats = []; for ($i = 1; $i <= 12; $i++) $sats[$i] = $vEntity(sprintf('sat-%02d', $i), 'product');
$ren = $vEntity('renamed-co', 'company', 'OLDNAMELEAK Co'); $newV = (int)must(uni_correct($pdo, $ed1, 'entity', $ren, ['name' => 'Renamed Co']), 'correct entity'); $publish('entity', $newV);
$entitiesVisible = 5 + 12 + 1;
// entities that must never show: proposed, in_review, rejected
$gp = $entity('leak-ghost-proposed', 'model', 'LEAK Ghost Proposed'); $gr = $entity('leak-ghost-review', 'model', 'LEAK Ghost Review'); must(uni_submit_for_review($pdo, $ed1, 'entity', $gr), 'submit gr');
$gx = $entity('leak-ghost-rejected', 'model', 'LEAK Ghost Rejected'); must(uni_submit_for_review($pdo, $ed1, 'entity', $gx), 'submit gx'); must(uni_review($pdo, $v1, 'entity', $gx, 'reject', 'LEAKNOTE reviewer note'), 'reject gx');

// edges
$edgesAcme = 0;
foreach ($sats as $s) { $vEdge($s, $acme, 'developed_by'); $edgesAcme++; }
$vEdge($mx, $acme, 'developed_by'); $edgesAcme++;
$myDev = $edge($my, $acme, 'developed_by'); $publish('edge', $myDev); $edgesAcme++;
$myDevNew = (int)must(uni_correct($pdo, $ed1, 'edge', $myDev, ['valid_from' => '2024-01-01']), 'correct edge'); $publish('edge', $myDevNew);
$vEdge($my, $mx, 'derived_from'); $vEdge($my, $mx, 'successor_of'); $vEdge($pz, $mx, 'built_on'); $vEdge($mx, $pz, 'hosted_on');
$vEdge($pz, $acme, 'integrates_with'); $edgesAcme++;
$verifiedEdgeCount = 12 + 1 + 1 + 4 + 1;                                   // 19 verified current edges between verified entities
$e1 = $edge($acme, $mx, 'acquired_by', 'LEAKNOTE edge note');             // proposed
$e2 = $edge($acme, $my, 'acquired_by', 'LEAKNOTE edge note'); must(uni_submit_for_review($pdo, $ed1, 'edge', $e2), 'submit e2');   // in_review
$e3 = $edge($acme, $lonely, 'hosted_on', 'LEAKNOTE edge note'); must(uni_submit_for_review($pdo, $ed1, 'edge', $e3), 'submit e3'); must(uni_review($pdo, $v1, 'edge', $e3, 'reject', 'LEAKNOTE'), 'reject e3');
$e4 = $edge($gp, $acme, 'developed_by', 'LEAKNOTE'); $forceVerify('uni_edge', $e4);                     // verified edge, unverified endpoint
$e5 = $edge($lonely, $gr, 'built_on', 'LEAKNOTE'); $forceVerify('uni_edge', $e5);

// events: [slug, type, date, precision, title]; verified current ones are the expected ground truth for the heatmap
$truth = [];
$addEv = function (int $ent, string $slug, string $type, string $date, string $prec, string $title) use ($vEvent, &$truth): int { $id = $vEvent($ent, $type, $date, $title); $truth[] = [$slug, $type, strlen($date) === 4 ? "$date-01-01" : (strlen($date) === 7 ? "$date-01" : $date), $prec, $title]; return $id; };
$addEv($mx, 'modelx', 'launch', '2024-03-15', 'day', 'Model X launches'); $addEv($acme, 'acme', 'announcement', '2024-03-15', 'day', 'Acme announces'); $addEv($pz, 'prodz', 'product_release', '2024-03-15', 'day', 'Prod Z ships');
$addEv($my, 'modely', 'model_release', '2024-03-18', 'day', 'Model Y released'); $addEv($mx, 'modelx', 'pricing_change', '2024-04-02', 'day', 'Model X pricing update'); $addEv($mx, 'modelx', 'integration', '2024-04-20', 'day', 'Model X integration');
$addEv($mx, 'modelx', 'major_update', '2024-05', 'month', 'Model X May update'); $addEv($acme, 'acme', 'acquisition', '2023', 'year', 'Acme acquires something');
$old = $vEvent($acme, 'launch', '2024-03-16', 'OLDTITLELEAK launch'); $newEv = (int)must(uni_correct($pdo, $ed1, 'event', $old, ['title' => 'Acme Day corrected']), 'correct event'); $publish('event', $newEv); $truth[] = ['acme', 'launch', '2024-03-16', 'day', 'Acme Day corrected'];
$h1 = $event($mx, 'launch', '2024-03-15', 'LEAK proposed event');
$h2 = $event($mx, 'launch', '2024-03-15', 'LEAK in review event'); must(uni_submit_for_review($pdo, $ed1, 'event', $h2), 'submit h2');
$h3 = $event($mx, 'launch', '2024-03-15', 'LEAK rejected event'); must(uni_submit_for_review($pdo, $ed1, 'event', $h3), 'submit h3'); must(uni_review($pdo, $v1, 'event', $h3, 'reject', 'LEAKNOTE'), 'reject h3');
$h4 = $event($gp, 'launch', '2024-03-15', 'LEAK verified event of unverified entity'); $forceVerify('uni_event', $h4);
$h5 = $event($gr, 'launch', '2024-03-15', 'LEAK verified event of in-review entity'); $forceVerify('uni_event', $h5);
$expectedEvents = count($truth);

// releases
$r1 = $vRelease($mx, 'v1.0', '2024-01-10', ['context_window' => '8k', 'features' => ['a'], 'legacy_flag' => true, 'pricing' => ['input' => 1.0], 'same_key' => 'same']);
$r2 = $vRelease($mx, 'v2.0', '2024-06-01', ['context_window' => '32k', 'features' => ['a', 'b'], 'new_key' => 'z', 'pricing' => ['input' => 0.5], 'same_key' => 'same'], [$r1]);
$y1 = $vRelease($my, 'Y1', '2024-07-01', ['note_free' => 'one'], [$r2]);
$y2old = $vRelease($my, 'Y2-OLDLEAK', '2024-09-01', null, [$y1]); $y2 = (int)must(uni_correct($pdo, $ed1, 'release', $y2old, ['version_label' => 'Y2']), 'correct release'); $publish('release', $y2);
$rl1 = $release($mx, 'LEAK-REL-PROPOSED', '2024-08-01', null);
$rl2 = $release($mx, 'LEAK-REL-REVIEW', '2024-08-02', null); must(uni_submit_for_review($pdo, $ed1, 'release', $rl2), 'submit rl2');
$rl3 = $release($mx, 'LEAK-REL-REJECTED', '2024-08-03', null); must(uni_submit_for_review($pdo, $ed1, 'release', $rl3), 'submit rl3'); must(uni_review($pdo, $v1, 'release', $rl3, 'reject', 'LEAKNOTE'), 'reject rl3');
$rl4 = $release($gp, 'LEAK-REL-GHOST', '2024-08-04', null); $forceVerify('uni_release', $rl4);
fwrite(STDERR, "seeded: $entitiesVisible visible entities, $expectedEvents verified events, $verifiedEdgeCount verified edges\n");
$hiddenSlugs = ['leak-ghost-proposed', 'leak-ghost-review', 'leak-ghost-rejected'];
ok((int)val($pdo, "SELECT COUNT(*) FROM uni_event WHERE state <> 'verified' OR is_current = 0") >= 4, 'seed contains unverified, rejected and superseded event rows');
ok((int)val($pdo, "SELECT COUNT(*) FROM uni_event ev JOIN uni_entity en ON en.id = ev.entity_id WHERE ev.state = 'verified' AND en.state <> 'verified'") === 2, 'seed contains verified events of unverified entities');

// ---- start the servers -------------------------------------------------------------------------------------------------------------------
$mkdir = function (string $p) use (&$tmp): string { $d = sys_get_temp_dir() . '/' . $p . '_' . getmypid(); @mkdir($d, 0700); $tmp[] = $d; return $d; };
$baseEnv = ['AIHUB_DB_HOST' => $dbHost, 'AIHUB_DB_NAME' => $dbName, 'AIHUB_DB_USER' => $user, 'AIHUB_DB_PASS' => $pw, 'AIHUB_FORM_SECRET' => 'api-test-secret-not-for-production', 'AIHUB_SECURITY_LOG' => $mkdir('p7api_log') . '/sec.log'];
$port = freePort(8210, 8230); startServer($root, $port, $baseEnv + ['AIHUB_RATE_DIR' => $mkdir('p7api_rate1'), 'AIHUB_UNIVERSE_RATE_LIMIT' => '10000']);
$B = "http://127.0.0.1:$port";

section('method, route and header handling');
foreach (['POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'] as $m) { [$st, $h, $body, $j] = http($B, $m, '/universe-api.php?r=entities'); ok($st === 405 && ($h['allow'] ?? '') === 'GET' && ($j['error'] ?? '') === 'method_not_allowed', "$m returns 405 with Allow: GET (got $st)"); }
[$st, $h] = http($B, 'HEAD', '/universe-api.php?r=entities'); ok($st === 405, 'HEAD is refused too (GET only)');
[$st, , , $j] = http($B, 'GET', '/universe-api.php'); ok($st === 400 && $j['error'] === 'route_required' && in_array('compare', $j['routes'], true), 'missing route is 400 and lists the routes');
[$st, , , $j] = http($B, 'GET', '/universe-api.php?r=nope'); ok($st === 404 && $j['error'] === 'unknown_route', 'unknown route is 404');
foreach (['../etc/passwd', 'entities;x', 'ENTITIES', str_repeat('a', 30)] as $r) { [$st] = http($B, 'GET', '/universe-api.php?r=' . rawurlencode($r)); ok($st === 400 || $st === 404, "hostile route '" . substr($r, 0, 12) . "' is 400/404 (got $st)"); }
[$st] = http($B, 'GET', '/universe-api.php?r[]=entities'); ok($st === 400, 'array route is 400');
[$st, $h, $body] = http($B, 'GET', '/universe-api.php?r=entities');
ok($st === 200 && stripos($h['content-type'] ?? '', 'application/json') === 0, 'entities answers 200 JSON');
ok(stripos($h['x-robots-tag'] ?? '', 'noindex') !== false, 'X-Robots-Tag noindex'); ok(($h['x-content-type-options'] ?? '') === 'nosniff' && ($h['x-frame-options'] ?? '') === 'SAMEORIGIN' && isset($h['referrer-policy']) && isset($h['permissions-policy']), 'site security headers present');
ok(preg_match('/^public, max-age=\d{1,3}$/', $h['cache-control'] ?? '') === 1, 'short public Cache-Control (' . ($h['cache-control'] ?? '') . ')'); ok(preg_match('/^"[0-9a-f]{32}"$/', $h['etag'] ?? '') === 1, 'ETag present');
[$st2, $h2, $b2] = http($B, 'GET', '/universe-api.php?r=entities', ['If-None-Match: ' . $h['etag']]); ok($st2 === 304 && $b2 === '', 'If-None-Match gives 304 without a body');
[$st2] = http($B, 'GET', '/universe-api.php?r=entities', ['If-None-Match: "something-else"']); ok($st2 === 200, 'a different ETag gets the full body');
[$st, $h] = http($B, 'GET', '/universe-api.php?r=nope'); ok(($h['cache-control'] ?? '') === 'no-store', 'errors are never cached');
foreach (['/uni_core.php', '/crm_core.php', '/security.php'] as $f) { [$st] = http($B, 'GET', $f); ok($st === 404, "$f is not reachable"); }

section('entities: list, filters, pagination bounds');
[$st, $d] = api($B, 'r=entities&per_page=100'); ok($st === 200 && $d['total'] === $entitiesVisible && count($d['items']) === $entitiesVisible, "only the $entitiesVisible verified current entities are listed (total " . ($d['total'] ?? '?') . ')');
$slugs = array_column($d['items'] ?? [], 'slug'); foreach ($hiddenSlugs as $s) ok(!in_array($s, $slugs, true), "$s is not listed");
$names = array_column($d['items'] ?? [], 'name'); ok(in_array('Renamed Co', $names, true) && !in_array('OLDNAMELEAK Co', $names, true), 'the superseded entity version is hidden, the current one shown');
ok(count(array_unique($slugs)) === count($slugs), 'no duplicate entities');
foreach ($d['items'] ?? [] as $it) { ok(array_keys($it) === ['ref', 'entity_type', 'slug', 'name', 'summary', 'verified_at'], 'entity item has exactly the public fields'); break; }
[$st, $d] = api($B, 'r=entities&type=model'); ok($st === 200 && $d['total'] === 3 && count(array_unique(array_column($d['items'], 'entity_type'))) === 1, 'type filter (model: modelx, modely, lonely) ' . ($d['total'] ?? '?'));
[$st, $d] = api($B, 'r=entities&type=company'); ok($d['total'] === 2, 'company filter'); [$st, , $e] = api($B, 'r=entities&type=robot'); ok($st === 400, 'unknown type is 400');
[$st, $d] = api($B, 'r=entities&q=sat-'); ok($st === 200 && $d['total'] === 12, 'q search finds the 12 satellites'); [$st, $d] = api($B, 'r=entities&q=LEAK'); ok($st === 200 && $d['total'] === 0, 'q cannot find hidden entities');
[$st, $d] = api($B, 'r=entities&per_page=5&page=1'); ok(count($d['items']) === 5 && $d['has_more'] === true && $d['per_page'] === 5 && $d['page'] === 1, 'per_page=5 page 1');
[$st, $d2] = api($B, 'r=entities&per_page=5&page=4'); ok($st === 200 && count($d2['items']) === $entitiesVisible - 15 && $d2['has_more'] === false, 'last page is short and has_more false');
[$st, $d3] = api($B, 'r=entities&per_page=5&page=5'); ok($st === 200 && $d3['items'] === [], 'a page beyond the end is an empty list');
foreach (['per_page=101', 'per_page=0', 'per_page=-1', 'per_page=abc', 'per_page=1e3', 'per_page=999999999999', 'page=0', 'page=-3', 'page=10001', 'page=x', 'page[]=1', 'per_page[]=5', 'page=1.5', 'page=%20', 'unknown=1', 'q[]=a', 'q=' . urlencode(str_repeat('é', 81)), 'q=%ff%fe', 'q=a%00b', 'q=a%0Ab'] as $qs) { [$st] = api($B, "r=entities&$qs"); ok($st === 400, "entities?$qs is 400 (got $st)"); }
[$st] = api($B, 'r=entities&' . str_repeat('x=1&', 400)); ok($st === 400, 'over-long query string is 400');

section('entity detail');
[$st, $d] = api($B, 'r=entity&slug=modelx'); ok($st === 200 && $d['slug'] === 'modelx' && $d['name'] === 'Model X' && count($d['sources']) === 1, 'entity with its verified source');
ok(isset($d['sources'][0]['url'], $d['sources'][0]['publisher'], $d['sources'][0]['retrieved_at'], $d['sources'][0]['quote']), 'source carries url, publisher, retrieved_at and quote');
[$st, $d] = api($B, 'r=entity&slug=renamed-co'); ok($d['name'] === 'Renamed Co' && count($d['sources']) === 1, 'corrected entity: current version with copied source');
foreach (array_merge($hiddenSlugs, ['no-such-entity']) as $s) { [$st, , $e] = api($B, "r=entity&slug=$s"); ok($st === 404 && $e === 'entity_not_found', "$s -> 404"); }
foreach (['', 'A', "x'", 'a b', "a'; DROP TABLE uni_entity; --", '../../etc/passwd', '%27%20OR%20%271%27%3D%271', str_repeat('a', 121), 'UPPER'] as $s) { [$st] = api($B, 'r=entity&slug=' . rawurlencode($s)); ok($st === 400, 'hostile slug "' . substr($s, 0, 14) . '" is 400 (got ' . $st . ')'); }
[$st] = api($B, 'r=entity'); ok($st === 400, 'slug is required'); [$st] = api($B, 'r=entity&slug[]=acme'); ok($st === 400, 'slug array is 400');

section('events');
[$st, $d] = api($B, 'r=events&per_page=100'); ok($st === 200 && $d['total'] === $expectedEvents && count($d['items']) === $expectedEvents, "events total equals the verified events of verified entities ($expectedEvents, got " . ($d['total'] ?? '?') . ')');
$titles = array_column($d['items'] ?? [], 'title'); ok(!in_array('OLDTITLELEAK launch', $titles, true) && in_array('Acme Day corrected', $titles, true), 'superseded event version hidden, current shown');
foreach ($titles as $t) ok(stripos($t, 'leak') === false, "no hidden event title '$t'");
foreach ($d['items'] ?? [] as $it) ok(count($it['sources']) >= 1 && isset($it['sources'][0]['url']) && array_keys($it) === ['ref', 'event_type', 'event_date', 'date_precision', 'date_kind', 'title', 'summary', 'verified_at', 'entity_slug', 'entity_name', 'sources'], 'event has sources and exactly the public fields');
[$st, $d] = api($B, 'r=events&entity=modelx'); ok($d['total'] === 4, 'entity filter (modelx has 4 verified events)'); [$st, $d] = api($B, 'r=events&type=launch'); ok($d['total'] === 2, 'type filter');
[$st, $d] = api($B, 'r=events&from=2024-03-15&to=2024-03-15'); ok($d['total'] === 3, 'a single day: 3 events (day and month precision rows at other dates excluded by date)'); [$st, $d] = api($B, 'r=events&from=2024-04&to=2024-04'); ok($d['total'] === 2, 'coarse to=2024-04 covers the whole month');
[$st, $d] = api($B, 'r=events&from=2024'); ok($d['total'] === $expectedEvents - 1, 'from=2024 excludes the 2023 event');
[$st, $d] = api($B, 'r=events&per_page=3&page=2'); ok(count($d['items']) === 3 && $d['has_more'] === true, 'event pagination');
foreach (['from=2024-13-01', 'from=2024-02-30', 'to=yesterday', 'from=2025-01-01&to=2024-01-01', 'type=hack', 'entity=NOPE', "entity=a'b", 'from[]=2024-01-01', 'to=1899-01-01', 'from=2024-01-01%00'] as $qs) { [$st] = api($B, "r=events&$qs"); ok($st === 400, "events?$qs is 400 (got $st)"); }
foreach ($hiddenSlugs as $s) { [$st] = api($B, "r=events&entity=$s"); ok($st === 404, "events of hidden entity $s -> 404"); }
[$st] = api($B, 'r=events&entity=no-such-one'); ok($st === 404, 'events of an unknown entity -> 404');

section('heatmap counts equal verified events only');
$monday = fn(string $d) => gmdate('Y-m-d', strtotime($d . ' UTC') - ((int)gmdate('N', strtotime($d . ' UTC')) - 1) * 86400);
$expect = function (string $grain, ?string $slug = null, ?string $cat = null, string $from = '2023-01-01', string $to = '2024-12-31') use ($truth, $monday): array {
  $o = []; $excluded = 0;
  foreach ($truth as [$s, $t, $date, $prec, $title]) {
    if ($slug !== null && $s !== $slug) continue; if ($cat !== null && $t !== $cat) continue; if ($date < $from || $date > $to) continue;
    $okPrec = $grain === 'month' ? in_array($prec, ['day', 'month'], true) : $prec === 'day';
    if (!$okPrec) { $excluded++; continue; }
    $k = $grain === 'day' ? $date : ($grain === 'week' ? $monday($date) : substr($date, 0, 7)); $o[$k] = ($o[$k] ?? 0) + 1;
  }
  ksort($o); return [$o, $excluded];
};
$flat = fn(array $buckets) => array_column($buckets, 'count', 'period');
foreach (['day', 'week', 'month'] as $g) {
  [$st, $d] = api($B, "r=heatmap&grain=$g&from=2023-01-01&to=2024-12-31"); [$exp, $exc] = $expect($g);
  ok($st === 200 && $flat($d['buckets']) === $exp, "$g buckets equal the verified events: " . json_encode($flat($d['buckets'] ?? [])) . ' vs ' . json_encode($exp));
  ok($d['total'] === array_sum($exp) && $d['excluded_coarser_precision'] === $exc, "$g total " . ($d['total'] ?? '?') . ' and excluded ' . ($d['excluded_coarser_precision'] ?? '?'));
}
[$st, $d] = api($B, 'r=heatmap&grain=day&from=2024-03-01&to=2024-03-31'); ok($flat($d['buckets']) === ['2024-03-15' => 3, '2024-03-16' => 1, '2024-03-18' => 1], 'March by day: hidden events dated 2024-03-15 add nothing');
[$st, $d] = api($B, 'r=heatmap&grain=month&entity=modelx&from=2024-01-01&to=2024-12-31'); [$exp] = $expect('month', 'modelx', null, '2024-01-01', '2024-12-31'); ok($flat($d['buckets']) === $exp && $d['entity'] === 'modelx', 'entity filter ' . json_encode($flat($d['buckets'] ?? [])));
[$st, $d] = api($B, 'r=heatmap&grain=day&category=launch&from=2024-01-01&to=2024-12-31'); [$exp] = $expect('day', null, 'launch', '2024-01-01', '2024-12-31'); ok($flat($d['buckets']) === $exp, 'category filter');
[$st, $d] = api($B, 'r=heatmap&to=2024-04'); ok($st === 200 && $d['to'] === '2024-04-30', 'coarse to names the last day of the month'); [$st, $d] = api($B, 'r=heatmap'); ok($st === 200 && is_array($d['buckets']) && isset($d['from'], $d['to']), 'defaults work');
[$st, $d] = api($B, 'r=heatmap&day=2024-03-15'); ok($st === 200 && $d['count'] === 3 && count($d['items']) === 3 && $d['day'] === '2024-03-15', 'day drill-down returns the 3 verified events');
foreach ($d['items'] ?? [] as $it) ok($it['date_precision'] === 'day' && count($it['sources']) >= 1 && stripos($it['title'], 'leak') === false, 'drill-down event has day precision, sources and is not hidden');
$bucketDay = api($B, 'r=heatmap&grain=day&from=2024-03-15&to=2024-03-15')[1]['buckets'][0]['count'] ?? -1; ok($bucketDay === $d['count'], 'the drill-down count equals the heatmap bucket');
[$st, $d] = api($B, 'r=heatmap&day=2024-03-15&entity=modelx'); ok($d['count'] === 1 && $d['items'][0]['title'] === 'Model X launches', 'drill-down entity filter'); [$st, $d] = api($B, 'r=heatmap&day=2024-03-15&category=pricing_change'); ok($d['count'] === 0 && $d['items'] === [], 'drill-down category filter');
[$st, $d] = api($B, 'r=heatmap&day=2024-05-01'); ok($d['count'] === 0, 'a month-precision event is not part of any day drill-down');
foreach (['grain=year', 'grain=DAY', 'from=2024-13-13', 'to=zzz', 'day=2024-03', 'day=2024-02-30', 'category=hack', 'entity=BAD', 'from=2025-01-01&to=2024-01-01', 'grain=day&from=1900-01-01&to=2100-12-31', 'grain[]=day', "day=2024-03-15'"] as $qs) { [$st] = api($B, "r=heatmap&$qs"); ok($st === 400, "heatmap?$qs is 400 (got $st)"); }
[$st] = api($B, 'r=heatmap&entity=leak-ghost-proposed'); ok($st === 404, 'heatmap of a hidden entity -> 404');

section('graph: progressive loading, separate relationship types, sources');
[$st, $g] = api($B, 'r=graph&slug=acme&limit=200'); ok($st === 200 && $g['total_edges'] === $edgesAcme && count($g['edges']) === $edgesAcme && $g['has_more'] === false && $g['next_offset'] === null, "acme has $edgesAcme verified edges (got " . ($g['total_edges'] ?? '?') . ')');
$seen = []; $nodeRefs = []; $off = 0; $pages = 0; $maxNodes = 0;
do {
  [$st, $gp1] = api($B, "r=graph&slug=acme&limit=5&offset=$off"); if ($st !== 200) break; $pages++;
  ok(count($gp1['edges']) <= 5 && count($gp1['nodes']) <= 6, 'a page has at most limit edges and limit+1 nodes'); $maxNodes = max($maxNodes, count($gp1['nodes']));
  foreach ($gp1['edges'] as $e) $seen[] = $e['from'] . '>' . $e['to'] . '>' . $e['type'];
  foreach ($gp1['nodes'] as $n) $nodeRefs[$n['ref']] = $n; $off = $gp1['next_offset'];
} while ($off !== null && $pages < 10);
ok($pages === 3 && count($seen) === $edgesAcme && count(array_unique($seen)) === $edgesAcme, "progressive loading covers all $edgesAcme edges in $pages pages without repeats");
$all = $g['edges'] ?? []; ok(array_values(array_unique(array_column($all, 'type'))) === ['developed_by', 'integrates_with'] || array_values(array_unique(array_column($all, 'type'))) === ['integrates_with', 'developed_by'], 'relationship types stay separate on each edge');
$refs = array_column($g['nodes'] ?? [], 'ref'); foreach ($all as $e) ok(in_array($e['from'], $refs, true) && in_array($e['to'], $refs, true), 'every edge endpoint is a returned node');
foreach ($g['nodes'] ?? [] as $n) ok(array_keys($n) === ['ref', 'slug', 'type', 'name'], 'node has exactly ref, slug, type, name');
foreach ($all as $e) { ok(array_keys($e) === ['from', 'to', 'type', 'valid_from', 'valid_to', 'sources'] && count($e['sources']) === 1 && array_keys($e['sources'][0]) === ['url', 'publisher', 'retrieved_at'], 'edge has exactly from,to,type,valid_from,valid_to and sources {url,publisher,retrieved_at}'); break; }
ok(!in_array('leak-ghost-proposed', array_column($g['nodes'] ?? [], 'slug'), true), 'a verified edge from an unverified entity is not drawn');
[$st, $g2] = api($B, 'r=graph&slug=acme&type=integrates_with'); ok($g2['total_edges'] === 1 && $g2['edges'][0]['type'] === 'integrates_with', 'edge type filter'); [$st, $g3] = api($B, 'r=graph&slug=modely'); ok(count(array_unique(array_column($g3['edges'], 'type'))) === count($g3['edges']) && $g3['total_edges'] === 3, 'modely: developed_by, derived_from and successor_of are separate edges');
$dev = array_values(array_filter($g3['edges'], fn($e) => $e['type'] === 'developed_by')); ok(count($dev) === 1 && $dev[0]['valid_from'] === '2024-01-01', 'the corrected edge version is the one served');
[$st, $g4] = api($B, 'r=graph&slug=lonely'); ok($st === 200 && $g4['edges'] === [] && count($g4['nodes']) === 1 && $g4['total_edges'] === 0, 'an entity without verified edges yields just itself (rejected and ghost edges hidden)');
foreach (['limit=0', 'limit=201', 'limit=-1', 'limit=abc', 'offset=-1', 'offset=abc', 'offset=99999999999', 'type=hack', 'type=Developed_by', 'limit[]=5'] as $qs) { [$st] = api($B, "r=graph&slug=acme&$qs"); ok($st === 400, "graph?$qs is 400 (got $st)"); }
foreach (array_merge($hiddenSlugs, ['nope-nope']) as $s) { [$st] = api($B, "r=graph&slug=$s"); ok($st === 404, "graph of $s -> 404"); }
[$st, $g5] = api($B, 'r=graph&slug=acme&limit=5&offset=1000'); ok($st === 200 && $g5['edges'] === [] && $g5['has_more'] === false, 'offset beyond the end is an empty page');

section('lineage');
[$st, $l] = api($B, 'r=lineage&slug=modelx'); ok($st === 200, 'lineage of modelx');
$labels = array_column($l['nodes'] ?? [], 'version_label'); sort($labels); ok($labels === ['v1.0', 'v2.0', 'Y1', 'Y2'] || $labels === ['Y1', 'Y2', 'v1.0', 'v2.0'], 'release nodes: own releases plus linked descendants (' . implode(',', $labels) . ')');
ok(count($l['edges'] ?? []) === 3 && count(array_filter($l['edges'], fn($e) => $e['type'] === 'release_parent' && isset($e['source']['url'], $e['source']['publisher'], $e['source']['retrieved_at']))) === 3, 'three documented parent links, each with its own source');
$byRef = array_column($l['nodes'] ?? [], null, 'ref'); foreach ($l['edges'] ?? [] as $e) ok(isset($byRef[$e['from']], $byRef[$e['to']]) && $byRef[$e['from']]['release_date'] < $byRef[$e['to']]['release_date'], 'a parent link points from the earlier to the later release');
$kinds = array_column($l['entity_edges'] ?? [], 'type'); sort($kinds); ok($kinds === ['product_succession', 'technical_ancestry', 'technical_ancestry'], 'entity edges are only lineage kinds: ' . implode(',', $kinds));
foreach ($l['entity_edges'] ?? [] as $e) ok(in_array($e['edge_type'], ['derived_from', 'built_on', 'successor_of', 'renamed_from'], true) && !in_array($e['edge_type'], ['developed_by', 'integrates_with', 'hosted_on', 'acquired_by', 'version_of'], true) && count($e['sources']) === 1, 'lineage edge type allowed (' . $e['edge_type'] . ')');
ok(!in_array('LEAK-REL-GHOST', $labels, true) && !preg_grep('/LEAK|OLD/i', $labels), 'hidden releases are not in the lineage'); ok($l['allowed_lineage_types'] === ['technical_ancestry', 'product_succession', 'release_parent'], 'allowed lineage types are advertised');
[$st, $l2] = api($B, 'r=lineage&slug=modely'); ok(count($l2['nodes']) === 4, 'lineage from the descendant side reaches the same family'); [$st, $l3] = api($B, 'r=lineage&slug=modelx&limit=2'); ok($st === 200 && count($l3['nodes']) <= 2 && $l3['truncated'] === true, 'node cap is honoured and flagged');
[$st, $l4] = api($B, 'r=lineage&slug=lonely'); ok($st === 200 && $l4['nodes'] === [] && $l4['edges'] === [] && $l4['entity_edges'] === [], 'an entity without releases has an empty lineage');
foreach (['limit=0', 'limit=201', 'limit=x'] as $qs) { [$st] = api($B, "r=lineage&slug=modelx&$qs"); ok($st === 400, "lineage?$qs is 400"); }
foreach (array_merge($hiddenSlugs, ['nope-nope']) as $s) { [$st] = api($B, "r=lineage&slug=$s"); ok($st === 404, "lineage of $s -> 404"); }

section('release_diff');
[$st, $d] = api($B, 'r=release_diff&slug=modelx&a=v1.0&b=v2.0'); ok($st === 200, 'diff by label');
ok($d['change_set']['available'] === true && $d['change_set']['added'] === ['new_key'] && $d['change_set']['removed'] === ['legacy_flag'] && array_column($d['change_set']['changed'], 'key') === ['context_window', 'features', 'pricing'] && $d['change_set']['unchanged_count'] === 1, 'added/removed/changed keys from change_set ' . json_encode($d['change_set'] ?? null));
ok($d['days_between'] === 143 && $d['from']['version_label'] === 'v1.0' && $d['to']['version_label'] === 'v2.0' && $d['swapped_to_chronological_order'] === false, 'date and order (days_between ' . ($d['days_between'] ?? '?') . ')');
ok($d['pricing']['available'] === true && $d['pricing']['change_set']['status'] === 'changed' && count($d['pricing']['events']) === 1 && count($d['pricing']['events'][0]['sources']) >= 1, 'pricing change from verified data: change_set and the pricing_change event');
ok($d['integrations']['available'] === true && !isset($d['integrations']['change_set']) && count($d['integrations']['events']) === 1, 'integrations: only the verified integration event, no change_set key');
[$st, $d2] = api($B, 'r=release_diff&slug=modelx&a=' . $d['to']['ref'] . '&b=' . $d['from']['ref']); ok($st === 200 && $d2['swapped_to_chronological_order'] === true && $d2['from']['version_label'] === 'v1.0', 'refs work and reversed input is put in chronological order');
[$st, $d3] = api($B, 'r=release_diff&slug=modely&a=Y1&b=Y2'); ok($st === 200 && $d3['change_set'] === ['available' => false, 'reason' => 'change_set_missing'], 'missing change_set is explicitly unavailable');
ok($d3['pricing'] === ['available' => false, 'reason' => 'no_verified_data'] && $d3['integrations'] === ['available' => false, 'reason' => 'no_verified_data'], 'pricing and integrations are explicitly unavailable when no verified data exists');
foreach (['a=v1.0&b=v1.0', 'a=&b=v2.0', 'b=v2.0', 'a=' . str_repeat('x', 81) . '&b=v2.0'] as $qs) { [$st] = api($B, "r=release_diff&slug=modelx&$qs"); ok($st === 400, "release_diff?$qs is 400 (got $st)"); }
foreach (['a=v1.0&b=nope', 'a=Y1&b=v2.0', 'a=LEAK-REL-PROPOSED&b=v2.0', 'a=LEAK-REL-REJECTED&b=v1.0', 'a=LEAK-REL-GHOST&b=v1.0', 'a=ffffffffffffffffffffffffffffffff&b=v1.0'] as $qs) { [$st, , $e] = api($B, "r=release_diff&slug=modelx&$qs"); ok($st === 404 && $e === 'release_not_found', "release_diff?$qs -> 404 (got $st)"); }
[$st] = api($B, 'r=release_diff&slug=modely&a=Y1&b=Y2-OLDLEAK'); ok($st === 404, 'the superseded release version cannot be addressed'); [$st] = api($B, 'r=release_diff&slug=leak-ghost-proposed&a=LEAK-REL-GHOST&b=x'); ok($st === 404, 'diff of a hidden entity -> 404');
[$st] = api($B, 'r=release_diff&slug=acme&a=v1.0&b=v2.0'); ok($st === 404, 'releases of another entity cannot be diffed under this slug (same-entity rule)');
[$st] = api($B, "r=release_diff&slug=modelx&a=" . rawurlencode("v1.0' OR '1'='1") . "&b=v2.0"); ok($st === 404, 'SQL-shaped release label is just not found');

section('compare');
[$st, $c] = api($B, 'r=compare&slugs=modelx,modely,lonely'); ok($st === 200 && count($c['entities']) === 3, 'three entities side by side');
$by = array_column(array_map(fn($e) => ['slug' => $e['entity']['slug'], 'f' => $e['fields']], $c['entities'] ?? []), 'f', 'slug');
ok($by['modelx']['latest_release']['available'] && $by['modelx']['latest_release']['value']['version_label'] === 'v2.0' && $by['modelx']['release_count']['value'] === 2 && $by['modelx']['event_count']['value'] === 4, 'modelx: latest release, counts from verified data');
ok($by['modelx']['pricing']['available'] && $by['modelx']['pricing']['value']['from'] === 'latest_release_change_set' && $by['modelx']['developer']['value'][0]['slug'] === 'acme', 'modelx: pricing from the latest release change_set, developer from a verified edge');
ok($by['modelx']['integrations']['available'] === false && $by['modely']['pricing']['available'] === false, 'integrations/pricing unavailable where nothing verified exists');
foreach (['latest_release', 'release_count', 'event_count', 'latest_event', 'pricing', 'integrations', 'developer'] as $f) ok($by['lonely'][$f] === ['available' => false, 'reason' => 'no_verified_data'], "lonely.$f is explicitly unavailable");
ok($by['lonely']['summary']['available'] === true && $by['lonely']['sources']['available'] === true, 'lonely still shows its verified summary and sources');
ok(array_keys($by['modelx']) === ['summary', 'sources', 'developer', 'release_count', 'latest_release', 'event_count', 'latest_event', 'pricing', 'integrations'], 'same fields for every entity');
foreach (['a', 'modelx', 'modelx,modelx', 'a-b,c-d,e-f,g-h,i-j', 'modelx,MODELY', "modelx,modely'", 'modelx,,modely', ',', ''] as $s) { [$st] = api($B, 'r=compare&slugs=' . rawurlencode($s)); ok($st === 400, "compare slugs='" . substr($s, 0, 20) . "' is 400 (got $st)"); }
[$st] = api($B, 'r=compare'); ok($st === 400, 'slugs required'); [$st] = api($B, 'r=compare&slugs[]=modelx&slugs[]=modely'); ok($st === 400, 'array slugs are 400');
foreach ($hiddenSlugs as $s) { [$st] = api($B, "r=compare&slugs=modelx,$s"); ok($st === 404, "compare with hidden $s -> 404"); } [$st] = api($B, 'r=compare&slugs=modelx,no-such-thing'); ok($st === 404, 'compare with an unknown entity -> 404');
[$st, $c4] = api($B, 'r=compare&slugs=acme,modelx,modely,prodz'); ok($st === 200 && count($c4['entities']) === 4, 'four is the maximum');

section('hostile input never produces a 5xx and never changes data');
$before = [(int)val($pdo, 'SELECT COUNT(*) FROM uni_entity'), (int)val($pdo, 'SELECT COUNT(*) FROM uni_event'), (int)val($pdo, 'SELECT COUNT(*) FROM uni_audit_log')];
$evil = ["' OR 1=1 --", "1; DROP TABLE uni_entity; --", "' UNION SELECT username, password_hash, 1, 1 FROM crm_users --", '%', '_', '\\', "\xF0\x9F\x92\xA5", '{"a":1}', '<script>alert(1)</script>', '0x41', '1e309', '-0', 'null', 'NaN', str_repeat('%', 200), '../', "a\r\nX-Injected: 1", ';', '`id`', '$(id)', '${jndi:ldap://x}'];
$routes = ['entities' => ['q', 'type', 'page', 'per_page'], 'entity' => ['slug'], 'events' => ['entity', 'type', 'from', 'to', 'page', 'per_page'], 'heatmap' => ['grain', 'from', 'to', 'entity', 'category', 'day'], 'graph' => ['slug', 'limit', 'offset', 'type'], 'lineage' => ['slug', 'limit'], 'release_diff' => ['slug', 'a', 'b'], 'compare' => ['slugs']];
$bad5 = 0; $n = 0;
foreach ($routes as $r => $params) foreach ($params as $p) foreach ($evil as $v) { [$st, $h] = http($B, 'GET', "/universe-api.php?r=$r&$p=" . rawurlencode($v) . ($r === 'release_diff' && $p !== 'slug' ? '&slug=modelx' : '')); $n++; if ($st >= 500 || !in_array($st, [200, 304, 400, 404, 409], true)) { $bad5++; fwrite(STDERR, "  status $st for r=$r $p=" . substr(addcslashes($v, "\r\n"), 0, 30) . "\n"); } if (isset($h['x-injected'])) $bad5++; }
ok($bad5 === 0, "$n hostile requests: all answered 200/400/404, none 5xx, no header injection");
ok($before === [(int)val($pdo, 'SELECT COUNT(*) FROM uni_entity'), (int)val($pdo, 'SELECT COUNT(*) FROM uni_event'), (int)val($pdo, 'SELECT COUNT(*) FROM uni_audit_log')], 'the ledger is unchanged after the hostile requests');
ok((int)val($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('uni_entity','crm_users')") === 2, 'no table was dropped');
[$st, $h, $body] = http($B, 'GET', '/universe-api.php?r=entity&slug=nope-nope'); ok(strpos($body, 'Stack trace') === false && strpos($body, '.php') === false && strpos($body, 'SQLSTATE') === false, 'errors carry no stack trace, file name or SQL');
// a database outage answers a generic 503 JSON error, no details
$port503 = freePort($port + 1, 8230); startServer($root, $port503, ['AIHUB_DB_HOST' => '127.0.0.1', 'AIHUB_DB_NAME' => 'p7api_does_not_exist', 'AIHUB_DB_USER' => 'nobody', 'AIHUB_DB_PASS' => 'wrong', 'AIHUB_RATE_DIR' => $mkdir('p7api_rate3'), 'AIHUB_FORM_SECRET' => 'x-secret', 'AIHUB_SECURITY_LOG' => $mkdir('p7api_log3') . '/sec.log', 'AIHUB_UNIVERSE_RATE_LIMIT' => '10000']);
[$st, , $body, $j] = http("http://127.0.0.1:$port503", 'GET', '/universe-api.php?r=entities'); ok($st === 503 && ($j['error'] ?? '') === 'service_unavailable' && stripos($body, 'denied') === false && stripos($body, 'nobody') === false, 'database failure -> generic 503 JSON');

section('no identity, origin, note or internal-id field in any response');
$forbiddenKeys = ['id', 'proposed_by', 'verified_by', 'reviewer', 'reviewer_id', 'review_id', 'origin', 'notes', 'note', 'state', 'is_current', 'version_no', 'supersedes_id', 'lineage_key', 'created_by', 'actor_id', 'actor', 'email', 'username', 'display_name', 'password_hash', 'current_lineage', 'proposed_at', 'decided_at'];
$walk = function ($v, string $path, array &$hits) use (&$walk, $forbiddenKeys): void {
  if (!is_array($v)) return;
  foreach ($v as $k => $x) { if (is_string($k) && (in_array($k, $forbiddenKeys, true) || preg_match('/(^|_)id$/', $k) || preg_match('/_(by|id)$/', $k))) $hits[] = "$path.$k"; $walk($x, "$path.$k", $hits); }
};
$hits = []; $leaks = [];
foreach ($bodies as [$path, $raw, $json]) {
  $walk($json, $path, $hits);
  if (preg_match('/leak/i', $raw)) $leaks[] = $path;
  foreach ($identities as $i) if (stripos($raw, $i) !== false) $leaks[] = "$path ($i)";
  if (preg_match('/OLD(NAME|TITLE)|Y2-OLD/i', $raw)) $leaks[] = "$path (superseded text)";
}
ok(count($bodies) >= 60, count($bodies) . ' successful responses were collected and scanned');
ok($hits === [], 'no id / proposer / reviewer / origin / notes / state keys anywhere: ' . implode('; ', array_slice($hits, 0, 5)));
ok($leaks === [], 'no unverified, rejected, in-review, superseded or ghost-entity text, no user names, in any response: ' . implode('; ', array_slice($leaks, 0, 5)));
ok(count(array_filter($bodies, fn($b) => strpos($b[0], 'r=heatmap') !== false)) > 5 && count(array_filter($bodies, fn($b) => strpos($b[0], 'r=compare') !== false)) > 1, 'the scan covered heatmap and compare responses');

section('rate limiting (file-backed limiter shared with the site)');
$port2 = freePort($port + 1, 8230); if ($port2 === $port503) $port2 = freePort($port503 + 1, 8230);
startServer($root, $port2, $baseEnv + ['AIHUB_RATE_DIR' => $mkdir('p7api_rate2'), 'AIHUB_UNIVERSE_RATE_LIMIT' => '5']);
$codes = []; for ($i = 0; $i < 8; $i++) { [$st, $h, , $j] = http("http://127.0.0.1:$port2", 'GET', '/universe-api.php?r=entities'); $codes[] = $st; if ($st === 429) { $retry = $h['retry-after'] ?? ''; $j429 = $j; } }
ok($codes === [200, 200, 200, 200, 200, 429, 429, 429], 'the 6th request inside the window is 429 (' . implode(',', $codes) . ')'); ok(($retry ?? '') === '60' && ($j429['error'] ?? '') === 'rate_limited', '429 carries Retry-After and a JSON error');

section('routing, manifest and robots');
$man = json_decode((string)file_get_contents($root . '/tests/security_manifest.json'), true)['files'];
ok(($man['universe-api.php'] ?? '') === 'page', 'universe-api.php is registered in the security manifest');
$robots = (string)file_get_contents($root . '/robots.txt'); ok(preg_match('/^Disallow: \/universe-api\.php$/m', $robots) === 1, 'robots.txt disallows the API');
$ht = (string)file_get_contents($root . '/.htaccess'); ok(strpos($ht, 'universe-api') !== false, '.htaccess adds X-Robots-Tag for the API');
ok(preg_match('/\$_(POST|REQUEST)|file_put_contents|unlink|mail\(/', (string)file_get_contents($root . '/universe-api.php')) === 0, 'the API source never reads POST data and has no write sinks');

echo "\nPASSED: $pass  FAILED: $fail\n";
exit($fail ? 1 : 0);
