<?php
/**
 * Browser-test fixture for the AI Universe graph page: creates the scratch database p7gj_<pid>, seeds a SYNTHETIC ledger through the uni_core.php service functions
 * (verified entities and relationships plus unverified, rejected and superseded rows that must never show), starts `php -S` with tests/router.php on a port between
 * 8300 and 8320 and prints one line `READY <port> <json>` on stdout. It then waits until stdin is closed (or the process is terminated) and drops the database.
 * Usage (used by prototypes/universe-graph/http-source-test.js): php tests/phase7_graph_fixture.php
 * Env: AIHUB_COMPAT_ROOT_USER / AIHUB_COMPAT_ROOT_PASS / AIHUB_DB_HOST. Never touches another database or a remote host.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
ob_start();
require_once $root . '/uni_core.php';
$dsnBase = 'mysql:host=' . (getenv('AIHUB_DB_HOST') ?: '127.0.0.1') . ';port=3306';
$user = getenv('AIHUB_COMPAT_ROOT_USER') ?: 'aihub_test';
$pw = getenv('AIHUB_COMPAT_ROOT_PASS') !== false ? getenv('AIHUB_COMPAT_ROOT_PASS') : 'test_only_pw';
$dbHost = getenv('AIHUB_DB_HOST') ?: '127.0.0.1';
$dbName = 'p7gj_' . getmypid();
$opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$admin = new PDO($dsnBase, $user, $pw, $opt);
$admin->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$server = null; $tmp = [];
register_shutdown_function(function () use (&$server, $admin, $dbName, &$tmp) {
  if (is_resource($server)) { @proc_terminate($server); @proc_close($server); }
  try { $admin->exec("DROP DATABASE IF EXISTS `$dbName`"); } catch (Throwable $e) {}
  foreach ($tmp as $d) { foreach ((array)@glob($d . '/*') as $f) @unlink($f); @rmdir($d); }
});
$pdo = new PDO($dsnBase . ";dbname=$dbName", $user, $pw, $opt);
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
foreach (['phase6_crm.sql', 'phase7_universe.sql'] as $f) foreach (sqlStatements($root . '/database/' . $f) as $s) $pdo->exec($s);
function must($r, string $what) { if (!is_array($r) || $r[0] !== true) throw new RuntimeException("seed failed: $what " . json_encode($r)); return $r[1]; }
$H = password_hash('test-only-password', PASSWORD_DEFAULT);
$mkUser = function (string $name, string $role) use ($pdo, $H): int { [$ok, $id] = crm_user_create($pdo, $name, ucfirst($name) . 'Display', $role, $H); if (!$ok) throw new RuntimeException("user $name: $id"); return (int)$id; };
$ed = $mkUser('fxed', 'editor'); $vs = [$mkUser('fxv1', 'verifier'), $mkUser('fxv2', 'verifier'), $mkUser('fxv3', 'verifier')];
$src = fn(array $o = []) => $o + ['url' => 'https://example.test/' . bin2hex(random_bytes(4)), 'publisher' => 'Example Publisher', 'retrieved_at' => '2025-01-15', 'quote' => 'a synthetic quote that supports the claim'];
$publish = function (string $type, int $id) use ($pdo, $ed, $vs): void {
  must(uni_submit_for_review($pdo, $ed, $type, $id), "submit $type $id");
  foreach ($vs as $v) { $r = must(uni_review($pdo, $v, $type, $id, 'accept'), "review $type $id"); if ($r['state'] === 'verified') return; }
  throw new RuntimeException("not verified $type $id");
};
$vEntity = function (string $slug, string $type, string $name, string $summary = 'a synthetic summary') use ($pdo, $ed, $src, $publish): int {
  $id = (int)must(uni_propose_entity($pdo, $ed, ['entity_type' => $type, 'slug' => $slug, 'name' => $name, 'summary' => $summary]), "entity $slug");
  must(uni_attach_source($pdo, $ed, 'entity', $id, $src()), "entity source $slug"); $publish('entity', $id); return $id;
};
$edge = function (int $from, int $to, string $type, string $notes = '') use ($pdo, $ed, $src): int {
  $id = (int)must(uni_propose_edge($pdo, $ed, ['from_entity_id' => $from, 'to_entity_id' => $to, 'edge_type' => $type, 'notes' => $notes]), "edge $type");
  must(uni_attach_source($pdo, $ed, 'edge', $id, $src()), "edge source $type"); return $id;
};
$vEdge = function (int $from, int $to, string $type) use ($edge, $publish): void { $publish('edge', $edge($from, $to, $type)); };

$XSS = '<img src=x onerror="window.__xss=1">HOSTILE';
$acme = $vEntity('acme', 'company', 'Acme Labs', 'Acme summary'); $globex = $vEntity('globex', 'company', 'Globex Corp'); $hostile = $vEntity('hostile-co', 'company', $XSS);
$mx = $vEntity('modelx', 'model', 'Model X'); $my = $vEntity('modely', 'model', 'Model Y'); $pz = $vEntity('prodz', 'product', 'Prod Z'); $v2 = $vEntity('modelx-v2', 'version', 'Model X v2'); $lonely = $vEntity('lonely', 'model', 'Lonely Model');
$SATS = 105; $sats = [];
for ($i = 1; $i <= $SATS; $i++) { $sats[$i] = $vEntity(sprintf('sat-%03d', $i), 'product', sprintf('Satellite %03d', $i)); $vEdge($sats[$i], $acme, 'developed_by'); }
foreach ([[$mx, $acme, 'developed_by'], [$my, $acme, 'developed_by'], [$pz, $globex, 'developed_by'], [$pz, $acme, 'integrates_with'], [$v2, $mx, 'version_of'], [$my, $mx, 'derived_from'], [$my, $mx, 'successor_of'], [$pz, $mx, 'built_on'], [$mx, $pz, 'hosted_on'], [$hostile, $acme, 'acquired_by'], [$globex, $acme, 'renamed_from']] as [$f, $t, $ty]) $vEdge($f, $t, $ty);
$acmeEdges = $SATS + 2 + 1 + 1 + 1;
// rows that must never be visible
$gp = (int)must(uni_propose_entity($pdo, $ed, ['entity_type' => 'model', 'slug' => 'leak-ghost', 'name' => 'LEAK Ghost Entity', 'summary' => 'LEAK']), 'ghost');
must(uni_attach_source($pdo, $ed, 'entity', $gp, $src()), 'ghost source');
$edge($acme, $mx, 'acquired_by', 'LEAKNOTE'); $e2 = $edge($acme, $my, 'acquired_by', 'LEAKNOTE'); must(uni_submit_for_review($pdo, $ed, 'edge', $e2), 'submit e2');
$e3 = $edge($acme, $lonely, 'hosted_on', 'LEAKNOTE'); must(uni_submit_for_review($pdo, $ed, 'edge', $e3), 'submit e3'); must(uni_review($pdo, $vs[0], 'edge', $e3, 'reject', 'LEAKNOTE'), 'reject e3');

$tryPort = function (): int { for ($p = 8300; $p <= 8320; $p++) { $s = @stream_socket_server("tcp://127.0.0.1:$p", $e, $m); if ($s) { fclose($s); return $p; } } throw new RuntimeException('no free port'); };
$port = $tryPort();
$mkdir = function (string $p) use (&$tmp): string { $d = sys_get_temp_dir() . '/' . $p . '_' . getmypid(); @mkdir($d, 0700); $tmp[] = $d; return $d; };
$env = ['AIHUB_DB_HOST' => $dbHost, 'AIHUB_DB_NAME' => $dbName, 'AIHUB_DB_USER' => $user, 'AIHUB_DB_PASS' => $pw, 'AIHUB_FORM_SECRET' => 'fixture-secret-not-for-production',
  'AIHUB_SECURITY_LOG' => $mkdir('p7gj_log') . '/sec.log', 'AIHUB_RATE_DIR' => $mkdir('p7gj_rate'), 'AIHUB_UNIVERSE_RATE_LIMIT' => '10000'];
$server = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root, $root . '/tests/router.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env + getenv());
for ($i = 0; $i < 60; $i++) { usleep(100000); $c = @fsockopen('127.0.0.1', $port, $en, $es, 0.2); if ($c) { fclose($c); break; } }
$total = (int)$pdo->query("SELECT COUNT(*) FROM uni_entity WHERE state = 'verified' AND is_current = 1")->fetchColumn();
ob_end_clean();
fwrite(STDOUT, 'READY ' . $port . ' ' . json_encode(['entities' => $total, 'acmeEdges' => $acmeEdges, 'hostileName' => $XSS, 'sats' => $SATS]) . "\n");
while (!feof(STDIN)) { if (fgets(STDIN) === false) break; }   // returns when the parent closes stdin
