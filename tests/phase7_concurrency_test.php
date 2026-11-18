<?php
declare(strict_types=1);
/**
 * Phase 7 TRUE concurrency test for uni_core.php. Usage: php tests/phase7_concurrency_test.php [iterations]   (default 30 per scenario)
 *
 * Every race runs in separate OS processes (proc_open of THIS file in --worker mode), each with its own PDO connection. Workers connect, announce
 * readiness with a file, then spin on a barrier file; the parent creates the barrier once all are ready so the calls start within about a millisecond.
 * Scenarios (each repeated N times, whole suite in no-trigger mode and in trigger mode):
 *   a  two or three verifiers accept the same in_review item         b  one verifier submits two accepts / accept+reject at once
 *   c  proposer-and-verifier tricks (role flip, self review)         d  two corrections of the same verified row (entity, event, edge, release)
 *   e  accept of version N+1 racing with a correction of version N   f  attach_source racing with submit_for_review and with verification
 *   g  slug / edge-triple / duplicate-event races on propose         h  deadlock handling (forced lock-order inversion) and a mixed random-operation storm
 * After every race the ledger invariants are re-checked (one current row per lineage, contiguous version chain, verified rows backed by an accept
 * review of a different verifier, at least one valid source, one 'verified' audit entry). A PHP fatal, an uncaught exception or a service code of
 * 'error' in any worker is a failure. Creates database p7cc_<pid>, never touches another database, drops it at the end.
 * Env: AIHUB_COMPAT_ROOT_USER / AIHUB_COMPAT_ROOT_PASS / AIHUB_DB_HOST (as the other Phase 7 tests); P7_DSN_BASE, P7_USER, P7_PASS override.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);

// ---------------------------------------------------------------------------------------------------------------- worker side
if (($argv[1] ?? '') === '--worker') {
  $cfg = json_decode((string)($argv[2] ?? ''), true);
  ob_start();
  set_error_handler(function ($no, $str) { throw new ErrorException($str, 0, $no); });
  try {
    require_once $root . '/uni_core.php';
    $pdo = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->query('SELECT 1')->fetchColumn();                       // connection fully established before the barrier
    touch($cfg['ready']);
    $deadline = microtime(true) + 30;
    while (!file_exists($cfg['barrier'])) { if (microtime(true) > $deadline) throw new RuntimeException('barrier timeout'); usleep(50); }
    $t0 = microtime(true); $out = [];
    foreach ($cfg['calls'] as $c) {
      $fn = $c[0]; $args = array_slice($c, 1);
      if ($fn === '__flip') { $pdo->prepare("UPDATE crm_users SET role = 'verifier' WHERE id = ?")->execute([$args[0]]); $out[] = [true, 'flipped']; continue; }
      if ($fn === '__deadlock') {                                  // lock row A, wait, lock row B; the partner does B then A
        [$a, $b, $ms] = $args;
        $out[] = uni_tx($pdo, function () use ($pdo, $a, $b, $ms) { uni_row($pdo, 'entity', $a, true); usleep($ms * 1000); uni_row($pdo, 'entity', $b, true); return [true, 'both_locked']; });
        continue;
      }
      if (!function_exists($fn) || strpos($fn, 'uni_') !== 0) throw new RuntimeException("bad fn $fn");
      $out[] = $fn($pdo, ...$args);
    }
    ob_end_clean();
    echo json_encode(['r' => $out, 't' => $t0], JSON_UNESCAPED_SLASHES);
  } catch (Throwable $e) {
    @ob_end_clean();
    echo json_encode(['fatal' => get_class($e) . ': ' . $e->getMessage()]);
  }
  exit(0);
}

// ---------------------------------------------------------------------------------------------------------------- parent side
ob_start();
require_once $root . '/uni_core.php';
$N = max(1, (int)($argv[1] ?? 30));
$dsnBase = getenv('P7_DSN_BASE') ?: 'mysql:host=' . (getenv('AIHUB_DB_HOST') ?: '127.0.0.1') . ';port=3306';
$user = getenv('P7_USER') ?: (getenv('AIHUB_COMPAT_ROOT_USER') ?: 'aihub_test');
$pw = getenv('P7_PASS') !== false ? getenv('P7_PASS') : (getenv('AIHUB_COMPAT_ROOT_PASS') !== false ? getenv('AIHUB_COMPAT_ROOT_PASS') : 'test_only_pw');
$dbName = 'p7cc_' . getmypid();
if (!preg_match('/^p7cc_\d+$/', $dbName)) { fwrite(STDERR, "bad db name\n"); exit(2); }
$opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$admin = new PDO($dsnBase, $user, $pw, $opt);
fwrite(STDERR, 'Server: ' . $admin->query('SELECT VERSION()')->fetchColumn() . "\n");
$admin->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo = new PDO($dsnBase . ";dbname=$dbName", $user, $pw, $opt);
$pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION,ERROR_FOR_DIVISION_BY_ZERO'");
$WORKER_CFG = ['dsn' => $dsnBase . ";dbname=$dbName", 'user' => $user, 'pass' => $pw];

$pass = 0; $fail = 0; $races = 0; $maxSkewMs = 0.0; $codeTally = [];
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
function val(PDO $p, string $sql, array $a = []) { $s = $p->prepare($sql); $s->execute($a); return $s->fetchColumn(); }
function rows(PDO $p, string $sql, array $a = []): array { $s = $p->prepare($sql); $s->execute($a); return $s->fetchAll(PDO::FETCH_ASSOC); }
function code($r): string { return is_array($r) && array_key_exists(0, $r) ? ($r[0] ? 'ok' : (string)$r[1]) : 'malformed'; }

/** Starts one process per job, releases them together through a barrier file, returns per-worker ['r' => [results], 't' => start time] (or ['fatal' => text]). */
function race(array $jobs): array {
  global $WORKER_CFG, $maxSkewMs, $races, $codeTally, $fail;
  $races++; static $seq = 0; $seq++;
  $dir = sys_get_temp_dir() . '/p7cc_' . getmypid() . '_' . $seq; mkdir($dir, 0700);
  $barrier = "$dir/go"; $procs = []; $ready = [];
  foreach ($jobs as $i => $calls) {
    $cfg = $WORKER_CFG + ['ready' => "$dir/ready$i", 'barrier' => $barrier, 'calls' => $calls]; $ready[] = $cfg['ready'];
    $p = proc_open([PHP_BINARY, __FILE__, '--worker', json_encode($cfg)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]); $procs[$i] = [$p, $pipes];
  }
  $deadline = microtime(true) + 30;
  while (true) { $n = 0; foreach ($ready as $f) if (file_exists($f)) $n++; if ($n === count($ready) || microtime(true) > $deadline) break; usleep(500); }
  touch($barrier);
  $out = [];
  foreach ($procs as $i => [$p, $pipes]) {
    $so = stream_get_contents($pipes[1]); $se = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $rc = proc_close($p);
    $d = json_decode((string)$so, true);
    if (!is_array($d)) $d = ['fatal' => "no parsable output (exit $rc): " . substr(trim($so . ' ' . $se), 0, 300)];
    if ($se !== '' && !isset($d['fatal'])) $d['fatal'] = 'stderr output: ' . substr($se, 0, 300);
    $out[$i] = $d;
    if (isset($d['r'])) foreach ($d['r'] as $r) { $c = code($r); $codeTally[$c] = ($codeTally[$c] ?? 0) + 1; }
  }
  $ts = array_filter(array_map(fn($d) => $d['t'] ?? null, $out));
  if (count($ts) > 1) $maxSkewMs = max($maxSkewMs, (max($ts) - min($ts)) * 1000);
  foreach (glob("$dir/*") ?: [] as $f) @unlink($f); @rmdir($dir);
  foreach ($out as $d) if (isset($d['fatal'])) { fwrite(STDERR, '  FAIL: worker fatal: ' . $d['fatal'] . "\n"); $fail++; }
  return $out;
}
/** First result of worker $i. */
function res(array $o, int $i = 0) { return $o[$i]['r'][0] ?? [false, 'missing']; }
function codes(array $o): array { $c = []; foreach ($o as $d) foreach ($d['r'] ?? [] as $r) $c[] = code($r); return $c; }
function allowed(array $o, array $set, string $m): void { $bad = array_values(array_diff(codes($o), $set)); ok($bad === [], "$m: only clean codes (" . implode('/', $set) . ") returned" . ($bad ? ', got ' . implode(',', $bad) : '')); }

/** Ledger invariants over the whole database. Returns a list of violation texts (empty = consistent). */
function invariants(PDO $p): array {
  $v = [];
  foreach (['uni_entity' => 'entity', 'uni_event' => 'event', 'uni_edge' => 'edge', 'uni_release' => 'release'] as $t => $type) {
    foreach (rows($p, "SELECT lineage_key FROM $t WHERE is_current = 1 GROUP BY lineage_key HAVING COUNT(*) > 1") as $r) $v[] = "$t: two current rows in lineage {$r['lineage_key']}";
    foreach (rows($p, "SELECT lineage_key FROM $t WHERE state = 'verified' GROUP BY lineage_key HAVING COUNT(*) > 1") as $r) $v[] = "$t: two verified rows in lineage {$r['lineage_key']}";
    foreach (rows($p, "SELECT lineage_key FROM $t GROUP BY lineage_key HAVING MAX(version_no) <> COUNT(*) OR MIN(version_no) <> 1") as $r) $v[] = "$t: version numbers not contiguous in lineage {$r['lineage_key']}";
    foreach (rows($p, "SELECT c.id FROM $t c LEFT JOIN $t pr ON pr.id = c.supersedes_id WHERE c.version_no > 1 AND (pr.id IS NULL OR pr.lineage_key <> c.lineage_key OR pr.version_no <> c.version_no - 1)") as $r) $v[] = "$t id {$r['id']}: broken supersedes chain";
    foreach (rows($p, "SELECT x.id FROM $t x WHERE x.state = 'superseded' AND NOT EXISTS (SELECT 1 FROM $t s WHERE s.lineage_key = x.lineage_key AND s.version_no > x.version_no AND s.state = 'verified')") as $r) $v[] = "$t id {$r['id']}: superseded without a verified successor";
    foreach (rows($p, "SELECT x.id FROM $t x WHERE x.state = 'verified' AND x.is_current = 0") as $r) $v[] = "$t id {$r['id']}: verified but not current (and not superseded)";
    foreach (rows($p, "SELECT x.id FROM $t x LEFT JOIN uni_review r ON r.id = x.review_id WHERE x.state = 'verified' AND (r.id IS NULL OR r.decision <> 'accept' OR r.target_type <> '$type' OR r.target_id <> x.id OR r.reviewer_id <> x.verified_by OR r.reviewer_id = x.proposed_by)") as $r) $v[] = "$t id {$r['id']}: verified without a matching accept review by a different person";
    foreach (rows($p, "SELECT x.id FROM $t x WHERE x.state = 'verified' AND (SELECT COUNT(*) FROM uni_review r WHERE r.target_type = '$type' AND r.target_id = x.id AND r.decision = 'accept') < 1") as $r) $v[] = "$t id {$r['id']}: verified without any accept";
    foreach (rows($p, "SELECT target_id FROM uni_audit_log WHERE action = 'verified' AND target_type = '$type' GROUP BY target_id HAVING COUNT(*) > 1") as $r) $v[] = "$t id {$r['target_id']}: verified twice in the audit log";
    foreach (rows($p, "SELECT x.id FROM $t x WHERE x.state = 'verified' AND NOT EXISTS (SELECT 1 FROM uni_audit_log a WHERE a.action = 'verified' AND a.target_type = '$type' AND a.target_id = x.id)") as $r) $v[] = "$t id {$r['id']}: verified without an audit entry";
    foreach (rows($p, "SELECT x.id FROM $t x WHERE x.state IN ('in_review','verified')") as $r) { if ($type !== 'release' && uni_valid_source_count($p, $type, (int)$r['id']) < 1) $v[] = "$t id {$r['id']}: in_review/verified with no valid source"; }
  }
  foreach (rows($p, "SELECT target_type, target_id, reviewer_id FROM uni_review GROUP BY target_type, target_id, reviewer_id HAVING COUNT(*) > 1") as $r) $v[] = 'duplicate review by one reviewer';
  foreach (rows($p, "SELECT target_type, target_id FROM uni_review WHERE decision = 'accept' GROUP BY target_type, target_id HAVING COUNT(*) > 2") as $r) $v[] = "more than two accepts on {$r['target_type']} {$r['target_id']}";
  return $v;
}
function checkInv(PDO $p, string $label): void { $v = invariants($p); ok($v === [], "$label: ledger invariants hold" . ($v ? ' (' . implode('; ', array_slice($v, 0, 4)) . ')' : '')); }

// ---------------------------------------------------------------------------------------------------------------- fixtures
$H = password_hash('test-only-password', PASSWORD_DEFAULT);
$U = []; $ctr = 0; $mode = '';
function src(): array { return ['url' => 'https://example.test/cc/' . bin2hex(random_bytes(5)), 'publisher' => 'Example Publisher', 'retrieved_at' => '2025-01-15', 'quote' => 'a synthetic quote that supports the claim']; }
function mkUsers(PDO $pdo, string $H, string $tag): array {
  $u = [];
  foreach (['e1' => 'editor', 'e2' => 'editor', 'e3' => 'editor', 'v1' => 'verifier', 'v2' => 'verifier', 'v3' => 'verifier', 'x' => 'editor'] as $n => $role) {
    [$ok, $id] = crm_user_create($pdo, "{$n}_$tag", "Display $n $tag", $role, $H); if (!$ok) throw new RuntimeException("user $n: $id"); $u[$n] = (int)$id;
  }
  return $u;
}
/** Proposes an item of $type (never submitted). $e = verified entity id for events/edges/releases. Returns its id. */
function mkProposed(PDO $pdo, string $type, int $e, bool $withSource = true, string $by = 'e1'): int {
  global $U, $ctr; $ctr++; $a = $U[$by];
  if ($type === 'entity') { $r = uni_propose_entity($pdo, $a, ['entity_type' => 'company', 'slug' => "ent-$ctr-" . getmypid(), 'name' => "Entity $ctr", 'summary' => 's']); $id = (int)$r[1]; }
  elseif ($type === 'event') { $r = uni_propose_event($pdo, $a, ['entity_id' => $e, 'event_type' => 'launch', 'event_date' => gmdate('Y-m-d', 86400 * (10000 + $ctr)), 'title' => "Event $ctr"]); if (!$r[0]) throw new RuntimeException('mkProposed event ' . $r[1]); $id = (int)$r[1]['id']; }
  elseif ($type === 'edge') { $to = mkVerified($pdo, 'entity', 0); $r = uni_propose_edge($pdo, $a, ['from_entity_id' => $e, 'to_entity_id' => $to, 'edge_type' => 'built_on']); $id = (int)$r[1]; }
  else { $s = uni_source_create($pdo, $a, src()); $r = uni_propose_release($pdo, $a, ['entity_id' => $e, 'version_label' => "v$ctr", 'release_date' => gmdate('Y-m-d', 86400 * (10000 + $ctr)), 'source_id' => (int)$s[1]]); $id = (int)$r[1]; }
  if (!($r[0] ?? false)) throw new RuntimeException("mkProposed $type: " . json_encode($r));
  if ($withSource && $type !== 'release') { $q = uni_attach_source($pdo, $a, $type, $id, src()); if (!$q[0]) throw new RuntimeException("attach: {$q[1]}"); }
  return $id;
}
function mkInReview(PDO $pdo, string $type, int $e, string $by = 'e1'): int {
  global $U; $id = mkProposed($pdo, $type, $e, true, $by); $s = uni_submit_for_review($pdo, $U[$by], $type, $id); if (!$s[0]) throw new RuntimeException("submit: {$s[1]}"); return $id;
}
function publishId(PDO $pdo, string $type, int $id): void {
  global $U; foreach (['v1', 'v2', 'v3'] as $v) { $r = uni_review($pdo, $U[$v], $type, $id, 'accept'); if (!$r[0]) throw new RuntimeException("publish $type $id: {$r[1]}"); if ($r[1]['state'] === 'verified') return; }
  throw new RuntimeException("publish $type $id stuck");
}
function mkVerified(PDO $pdo, string $type, int $e): int {
  global $U; $id = mkProposed($pdo, $type, $e); $s = uni_submit_for_review($pdo, $U['e1'], $type, $id); if (!$s[0]) throw new RuntimeException("submit: {$s[1]}"); publishId($pdo, $type, $id); return $id;
}
function chg(string $type, int $n): array { return ['entity' => ['summary' => "c$n"], 'event' => ['summary' => "c$n"], 'edge' => ['notes' => "c$n"], 'release' => ['version_label' => "c$n"]][$type]; }
function stateOf(PDO $p, string $type, int $id): string { return (string)val($p, 'SELECT state FROM ' . uni_table($type) . ' WHERE id = ?', [$id]); }
function accepts(PDO $p, string $type, int $id): int { return (int)val($p, "SELECT COUNT(*) FROM uni_review WHERE target_type = ? AND target_id = ? AND decision = 'accept'", [$type, $id]); }

// ---------------------------------------------------------------------------------------------------------------- scenarios
function suite(PDO $pdo, string $mode, string $H, int $N): void {
  global $U, $ctr, $ctrlTag; static $run = 0; $run++;
  $triggers = (int)val($pdo, "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'trg\\_uni\\_%'");
  ok($mode === 'triggers' ? $triggers > 0 : $triggers === 0, "[$mode] trigger presence matches the mode ($triggers triggers)");
  $U = mkUsers($pdo, $H, "r$run");
  $E = mkVerified($pdo, 'entity', 0);                           // the shared verified entity
  $types = ['event', 'edge', 'release', 'entity'];

  section("[$mode] regression: a corrected release can be verified (trigger trg_uni_release_bu used to refuse the is_current 0 to 1 flip of a verified successor)");
  { $old = mkVerified($pdo, 'release', $E); $c = uni_correct($pdo, $U['e1'], 'release', $old, ['version_label' => 'regression']); $okc = $c[0];
    ok($okc, 'release correction proposed'); if ($okc) { uni_submit_for_review($pdo, $U['e1'], 'release', (int)$c[1]); try { publishId($pdo, 'release', (int)$c[1]); $published = true; } catch (Throwable $e) { $published = false; fwrite(STDERR, '  ' . $e->getMessage() . "\n"); }
      ok($published && stateOf($pdo, 'release', (int)$c[1]) === 'verified' && stateOf($pdo, 'release', $old) === 'superseded', 'release successor verified, predecessor superseded'); } }

  section("[$mode] a: simultaneous accepts of one in_review item");
  for ($i = 0; $i < $N; $i++) {
    $t = $types[$i % 3]; $id = mkInReview($pdo, $t, $E); $three = $i % 2 === 1;
    $o = race(array_merge([[['uni_review', $U['v1'], $t, $id, 'accept']], [['uni_review', $U['v2'], $t, $id, 'accept']]], $three ? [[['uni_review', $U['v3'], $t, $id, 'accept']]] : []));
    allowed($o, ['ok', 'not_in_review'], "a#$i");
    $acc = accepts($pdo, $t, $id); $need = uni_second_review_required($pdo, $t, $id) ? 2 : 1; $okN = count(array_filter(codes($o), fn($c) => $c === 'ok'));
    ok(stateOf($pdo, $t, $id) === 'verified', "a#$i $t verified exactly once-final");
    ok($acc === $need, "a#$i accept rows ($acc) equal the sampling requirement ($need)");
    ok($okN === $need, "a#$i successful reviews ($okN) equal the requirement ($need); the loser got not_in_review");
    ok((int)val($pdo, "SELECT COUNT(*) FROM uni_audit_log WHERE action = 'verified' AND target_type = ? AND target_id = ?", [$t, $id]) === 1, "a#$i exactly one verified transition");
  }
  checkInv($pdo, "[$mode] a");

  section("[$mode] b: one verifier, two decisions at once");
  for ($i = 0; $i < $N; $i++) {
    $t = $types[$i % 3]; $id = mkInReview($pdo, $t, $E); $mixed = $i % 3 === 2;
    $o = race([[['uni_review', $U['v1'], $t, $id, 'accept']], [['uni_review', $U['v1'], $t, $id, $mixed ? 'reject' : 'accept']]]);
    allowed($o, ['ok', 'already_reviewed', 'not_in_review'], "b#$i");
    ok((int)val($pdo, 'SELECT COUNT(*) FROM uni_review WHERE target_type = ? AND target_id = ? AND reviewer_id = ?', [$t, $id, $U['v1']]) === 1, "b#$i exactly one review row for the verifier");
    ok(count(array_filter(codes($o), fn($c) => $c === 'ok')) === 1, "b#$i exactly one of the two calls succeeded");
    ok(in_array(stateOf($pdo, $t, $id), ['in_review', 'verified', 'rejected'], true) && (stateOf($pdo, $t, $id) !== 'verified' || accepts($pdo, $t, $id) === 1), "b#$i never verified on a single reviewer's two submissions beyond the sampling rule");
    if (stateOf($pdo, $t, $id) === 'in_review') { $r = uni_review($pdo, $U['v2'], $t, $id, 'accept'); ok($r[0] && $r[1]['state'] === 'verified', "b#$i a second distinct verifier finishes it"); }
  }
  checkInv($pdo, "[$mode] b");

  section("[$mode] c: proposer and verifier tricks");
  for ($i = 0; $i < $N; $i++) {
    $t = $types[$i % 3]; $pdo->exec("UPDATE crm_users SET role = 'editor' WHERE id = " . (int)$U['x']);
    $id = mkInReview($pdo, $t, $E, 'x');
    $o = race([[['__flip', $U['x']], ['uni_review', $U['x'], $t, $id, 'accept']], [['uni_review', $U['v1'], $t, $id, 'accept']], [['uni_review', $U['x'], $t, $id, 'accept']]]);
    allowed($o, ['ok', 'flipped', 'maker_checker', 'forbidden', 'not_in_review'], "c#$i");
    ok((int)val($pdo, 'SELECT COUNT(*) FROM uni_review WHERE target_type = ? AND target_id = ? AND reviewer_id = proposed_by', [$t, $id]) === 0, "c#$i the proposer never reviewed their own item");
    ok((int)val($pdo, 'SELECT COUNT(*) FROM ' . uni_table($t) . ' WHERE id = ? AND verified_by = proposed_by', [$id]) === 0, "c#$i verified_by is never the proposer");
    $r = uni_review($pdo, $U['x'], $t, $id, 'accept'); ok(!$r[0], "c#$i the proposer still cannot review afterwards (" . code($r) . ')');
  }
  $pdo->exec("UPDATE crm_users SET role = 'editor' WHERE id = " . (int)$U['x']);
  checkInv($pdo, "[$mode] c");

  section("[$mode] d: two corrections of the same verified row");
  for ($i = 0; $i < $N; $i++) {
    $t = ['event', 'edge', 'release', 'entity'][$i % 4]; $id = mkVerified($pdo, $t, $E); $tb = uni_table($t); $lin = (string)val($pdo, "SELECT lineage_key FROM $tb WHERE id = ?", [$id]);
    $o = race([[['uni_correct', $U['e1'], $t, $id, chg($t, 1)]], [['uni_correct', $U['e2'], $t, $id, chg($t, 2)]]] + ($i % 2 ? [2 => [['uni_correct', $U['e3'], $t, $id, chg($t, 3)]]] : []));
    allowed($o, ['ok', 'correction_pending'], "d#$i");
    ok(count(array_filter(codes($o), fn($c) => $c === 'ok')) === 1, "d#$i exactly one correction accepted");
    $vs = array_map('intval', array_column(rows($pdo, "SELECT version_no FROM $tb WHERE lineage_key = ? ORDER BY version_no", [$lin]), 'version_no'));
    ok($vs === [1, 2], "d#$i version numbers are exactly 1,2 (got " . implode(',', $vs) . ')');
    ok((int)val($pdo, "SELECT COUNT(*) FROM $tb WHERE lineage_key = ? AND is_current = 1", [$lin]) === 1, "d#$i exactly one current row");
    $new = (int)val($pdo, "SELECT id FROM $tb WHERE lineage_key = ? AND version_no = 2", [$lin]); $s = uni_submit_for_review($pdo, $U['e1'], $t, $new);
    ok($s[0] || $s[1] === 'source_required', "d#$i the successor can proceed"); if ($s[0]) publishId($pdo, $t, $new);
    if ($s[0]) ok(stateOf($pdo, $t, $id) === 'superseded' && stateOf($pdo, $t, $new) === 'verified', "d#$i chain closes: v1 superseded, v2 verified");
  }
  checkInv($pdo, "[$mode] d");

  section("[$mode] e: accept of version N+1 racing with a correction of version N");
  for ($i = 0; $i < $N; $i++) {
    $t = ['event', 'edge', 'release', 'entity'][$i % 4]; $old = mkVerified($pdo, $t, $E); $tb = uni_table($t);
    $c = uni_correct($pdo, $U['e1'], $t, $old, chg($t, 9)); if (!$c[0]) { ok(false, "e#$i setup correct: {$c[1]}"); continue; } $new = (int)$c[1];
    $s = uni_submit_for_review($pdo, $U['e1'], $t, $new); if (!$s[0]) { ok(false, "e#$i setup submit: {$s[1]}"); continue; }
    $lin = (string)val($pdo, "SELECT lineage_key FROM $tb WHERE id = ?", [$new]); $all = [];
    foreach (['v1', 'v2', 'v3'] as $vv) {
      if (stateOf($pdo, $t, $new) !== 'in_review') break;
      $o = race([[['uni_review', $U[$vv], $t, $new, 'accept']], [['uni_correct', $U['e2'], $t, $old, chg($t, 8)]], [['uni_correct', $U['e3'], $t, $new, chg($t, 7)]]]); $all[] = $o;
      allowed($o, ['ok', 'correction_pending', 'not_correctable', 'not_current', 'not_in_review', 'already_reviewed'], "e#$i");
    }
    ok(stateOf($pdo, $t, $new) === 'verified' && stateOf($pdo, $t, $old) === 'superseded', "e#$i N+1 verified and N superseded");
    $nv = (int)val($pdo, "SELECT COUNT(*) FROM $tb WHERE lineage_key = ?", [$lin]);
    ok($nv === 2 || ($nv === 3 && stateOf($pdo, $t, $new) === 'verified'), "e#$i a third version exists only as the legal correction of the verified N+1 (versions: $nv)");
    ok((int)val($pdo, "SELECT COUNT(*) FROM $tb WHERE lineage_key = ? AND is_current = 1", [$lin]) === 1, "e#$i exactly one current row");
  }
  checkInv($pdo, "[$mode] e");

  section("[$mode] f: attach_source racing with submit and with verification");
  for ($i = 0; $i < $N; $i++) {
    $t = ['event', 'edge', 'entity'][$i % 3]; $id = mkProposed($pdo, $t, $E, false);
    $o = race([[['uni_attach_source', $U['e1'], $t, $id, src()]], [['uni_submit_for_review', $U['e1'], $t, $id]]]);
    allowed($o, ['ok', 'source_required', 'not_proposed', 'target_locked'], "f1#$i");
    $st = stateOf($pdo, $t, $id);
    ok(code(res($o, 0)) === 'ok', "f1#$i the attach itself succeeded (row was proposed or in_review)");
    ok($st !== 'in_review' || uni_valid_source_count($pdo, $t, $id) >= 1, "f1#$i in_review always has a source");
    ok(($st === 'in_review') === (code(res($o, 1)) === 'ok'), "f1#$i submit result matches the final state ($st)");
    if ($st === 'proposed') { $s = uni_submit_for_review($pdo, $U['e1'], $t, $id); ok($s[0], "f1#$i submit succeeds once the source is there"); }
  }
  for ($i = 0; $i < $N; $i++) {
    $t = ['event', 'edge', 'entity'][$i % 3]; $id = mkInReview($pdo, $t, $E); $tb = uni_table($t); $before = (int)val($pdo, "SELECT COUNT(*) FROM " . ['event' => 'uni_event_source', 'edge' => 'uni_edge_source', 'entity' => 'uni_entity_source'][$t] . ' WHERE ' . $t . '_id = ?', [$id]);
    $o = race([[['uni_attach_source', $U['e1'], $t, $id, src()]], [['uni_review', $U['v1'], $t, $id, 'accept']], [['uni_review', $U['v2'], $t, $id, 'accept']]]);
    allowed($o, ['ok', 'target_locked', 'not_in_review'], "f2#$i");
    $links = (int)val($pdo, "SELECT COUNT(*) FROM " . ['event' => 'uni_event_source', 'edge' => 'uni_edge_source', 'entity' => 'uni_entity_source'][$t] . ' WHERE ' . $t . '_id = ?', [$id]);
    $attachCode = code(res($o, 0)); $aid = (int)val($pdo, "SELECT MIN(id) FROM uni_audit_log WHERE action = 'source_attach' AND target_type = ? AND target_id = ?", [$t, $id]); $vid = (int)val($pdo, "SELECT MIN(id) FROM uni_audit_log WHERE action = 'verified' AND target_type = ? AND target_id = ?", [$t, $id]);
    if ($attachCode === 'ok') ok($links === $before + 1 && ($vid === 0 || $aid < $vid), "f2#$i an attach that succeeded happened strictly before verification");
    else ok($attachCode === 'target_locked' && $links === $before && stateOf($pdo, $t, $id) === 'verified', "f2#$i an attach that lost the race left the verified row's sources untouched");
    $late = uni_attach_source($pdo, $U['e1'], $t, $id, src()); ok(stateOf($pdo, $t, $id) !== 'verified' || (!$late[0] && $late[1] === 'target_locked'), "f2#$i after verification a later attach is refused");
  }
  checkInv($pdo, "[$mode] f");

  section("[$mode] g: slug, edge-triple and duplicate-event races");
  for ($i = 0; $i < $N; $i++) {
    $slug = "race-$run-$i-" . getmypid(); $in = ['entity_type' => 'product', 'slug' => $slug, 'name' => 'Raced', 'summary' => ''];
    $o = race([[['uni_propose_entity', $U['e1'], $in]], [['uni_propose_entity', $U['e2'], $in]], [['uni_propose_entity', $U['e3'], $in]], [['uni_propose_entity', $U['e1'], $in]]]);
    allowed($o, ['ok', 'slug_taken'], "g1#$i");
    ok(count(array_filter(codes($o), fn($c) => $c === 'ok')) === 1, "g1#$i exactly one proposal got the slug");
    ok((int)val($pdo, 'SELECT COUNT(*) FROM uni_entity WHERE slug = ? AND is_current = 1', [$slug]) === 1, "g1#$i one current row for the slug");
  }
  for ($i = 0; $i < $N; $i++) {
    $to = mkVerified($pdo, 'entity', 0); $in = ['from_entity_id' => $E, 'to_entity_id' => $to, 'edge_type' => 'integrates_with'];
    $o = race([[['uni_propose_edge', $U['e1'], $in]], [['uni_propose_edge', $U['e2'], $in]], [['uni_propose_edge', $U['e3'], $in]]]);
    allowed($o, ['ok', 'edge_exists'], "g2#$i");
    ok(count(array_filter(codes($o), fn($c) => $c === 'ok')) === 1, "g2#$i exactly one edge with that triple");
    ok((int)val($pdo, "SELECT COUNT(*) FROM uni_edge WHERE from_entity_id = ? AND to_entity_id = ? AND edge_type = 'integrates_with' AND is_current = 1 AND state <> 'rejected'", [$E, $to]) === 1, "g2#$i one live edge row for the triple");
  }
  for ($i = 0; $i < $N; $i++) {
    $in = ['entity_id' => $E, 'event_type' => 'launch', 'event_date' => '2024-03-' . str_pad((string)(1 + $i % 28), 2, '0', STR_PAD_LEFT), 'title' => "Same Title $run $i"];
    $o = race([[['uni_propose_event', $U['e1'], $in]], [['uni_propose_event', $U['e2'], $in]]]);
    allowed($o, ['ok'], "g3#$i");
    ok(count(array_filter(codes($o), fn($c) => $c === 'ok')) === 2, "g3#$i duplicate events are both kept (flagged, never merged or lost)");
  }
  checkInv($pdo, "[$mode] g");

  section("[$mode] h: deadlock handling and an operation storm");
  $ea = mkVerified($pdo, 'entity', 0); $eb = mkVerified($pdo, 'entity', 0);
  for ($i = 0; $i < $N; $i++) {
    $o = race([[['__deadlock', $ea, $eb, 120]], [['__deadlock', $eb, $ea, 120]]]);
    allowed($o, ['ok'], "h1#$i lock-order inversion is retried inside uni_tx");
  }
  $storm = 0;
  for ($i = 0; $i < $N; $i++) {
    mt_srand(1000 + $i); $pool = [];
    foreach (['event', 'edge', 'release', 'event'] as $k => $t) { $pool[] = [$t, $k < 2 ? mkInReview($pdo, $t, $E) : mkVerified($pdo, $t, $E)]; }
    $pool[] = ['event', mkProposed($pdo, 'event', $E, true)];
    $jobs = [];
    for ($w = 0; $w < 5; $w++) {
      $calls = [];
      for ($k = 0; $k < 6; $k++) {
        [$t, $id] = $pool[mt_rand(0, count($pool) - 1)]; $ed = $U[['e1', 'e2', 'e3'][mt_rand(0, 2)]]; $ve = $U[['v1', 'v2', 'v3'][mt_rand(0, 2)]];
        switch (mt_rand(0, 6)) {
          case 0: case 1: $calls[] = ['uni_review', $ve, $t, $id, 'accept']; break;
          case 2: $calls[] = ['uni_correct', $ed, $t, $id, chg($t, mt_rand(1, 99999))]; break;
          case 3: $calls[] = ['uni_submit_for_review', $ed, $t, $id]; break;
          case 4: if ($t !== 'release') $calls[] = ['uni_attach_source', $ed, $t, $id, src()]; else $calls[] = ['uni_publish_state', $id]; break;
          case 5: $calls[] = ['uni_review', $ve, $t, $id, mt_rand(0, 9) === 0 ? 'reject' : 'needs_second_source']; break;
          default: $calls[] = ['uni_propose_event', $ed, ['entity_id' => $E, 'event_type' => 'launch', 'event_date' => '2023-0' . mt_rand(1, 9) . '-0' . mt_rand(1, 9), 'title' => 'Storm ' . mt_rand(1, 5)]];
        }
      }
      $calls = array_values(array_filter($calls, fn($c) => $c[0] !== 'uni_publish_state'));
      $jobs[] = $calls;
    }
    $o = race($jobs); $storm += count(codes($o));
    $bad = array_values(array_filter(codes($o), fn($c) => in_array($c, ['error', 'malformed', 'missing', 'db_constraint', 'db_rule', 'reference_invalid'], true)));
    ok($bad === [], "h2#$i storm: no internal error or database rule violation leaked out" . ($bad ? ' (' . implode(',', $bad) . ')' : ''));
    $v = invariants($pdo); ok($v === [], "h2#$i storm: ledger invariants hold" . ($v ? ' (' . implode('; ', array_slice($v, 0, 3)) . ')' : ''));
  }
  fwrite(STDERR, "   storm: $storm operations across $N rounds\n");
  checkInv($pdo, "[$mode] h");
}

applyFile($pdo, $root . '/database/phase6_crm.sql');
$resetSchema = function () use ($pdo, $root) { applyFile($pdo, $root . '/database/phase7_rollback.sql'); applyFile($pdo, $root . '/database/phase7_universe.sql'); };
try {
  $resetSchema(); suite($pdo, 'no-triggers', $H, $N);
  $resetSchema(); applyFile($pdo, $root . '/database/phase7_triggers.sql'); suite($pdo, 'triggers', $H, $N);
  section('static guarantees');
  $code = (string)file_get_contents($root . '/uni_core.php'); $stripped = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $code);
  ok(strpos($stripped, '1213') !== false, 'uni_core.php handles deadlock error 1213 explicitly');
} finally {
  $admin->exec("DROP DATABASE IF EXISTS `$dbName`");
}
ksort($codeTally);
fwrite(STDERR, "Races run: $races (each a set of separate OS processes with separate PDO connections); max start skew between workers of one race: " . round($maxSkewMs, 2) . " ms\n");
fwrite(STDERR, 'Result codes observed: ' . json_encode($codeTally) . "\n");
$out = ob_get_clean(); echo $out; echo "\nPASSED: $pass  FAILED: $fail\n"; exit($fail ? 1 : 0);
