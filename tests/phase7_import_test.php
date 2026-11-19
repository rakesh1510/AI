<?php
declare(strict_types=1);
/**
 * Phase 7 IMPORTER test (scripts/uni_import.php, run as a subprocess). Usage: php tests/phase7_import_test.php
 * Env: AIHUB_COMPAT_ROOT_USER / AIHUB_COMPAT_ROOT_PASS / AIHUB_DB_HOST (an account that can CREATE DATABASE, CREATE USER and GRANT, like the other Phase 7 tests).
 * Creates database p7im_<pid> and the scratch account p7imu_<pid>@'%'; drops both at the end (also on failure). Everything is synthetic.
 *
 * Proves: dry run is the default and writes nothing; --apply creates only proposed / origin import rows and never a verified one; only an active editor may run it;
 * hostile or malformed input is rejected with line/record numbers and without partial writes; re-running creates nothing; duplicates are flagged and skipped, never
 * merged; imported rows cannot be verified (service layer, CHECK constraints, triggers) while a human verifier flow through a NEW version still works; the importer runs
 * with a least-privilege MySQL account (column-limited INSERT grants without verified_by / verified_at / review_id) and what that account can NOT do.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
ob_start();
require_once $root . '/uni_core.php';
$host = getenv('AIHUB_DB_HOST') ?: '127.0.0.1';
$user = getenv('AIHUB_COMPAT_ROOT_USER') ?: 'aihub_test';
$pw = getenv('AIHUB_COMPAT_ROOT_PASS') !== false ? getenv('AIHUB_COMPAT_ROOT_PASS') : 'test_only_pw';
$dsnBase = 'mysql:host=' . $host . ';port=3306';
$pid = getmypid(); $db = "p7im_$pid"; $lpUser = "p7imu_$pid"; $LPPW = 'pw_' . bin2hex(random_bytes(8));
$opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$admin = new PDO($dsnBase, $user, $pw, $opt);
fwrite(STDERR, 'Server: ' . $admin->query('SELECT VERSION()')->fetchColumn() . "\n");
$scratch = sys_get_temp_dir() . "/p7im_$pid"; @mkdir($scratch, 0700);

$pass = 0; $fail = 0;
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
function errno(PDO $p, string $sql, array $a = []): ?int { try { $s = $p->prepare($sql); $s->execute($a); $s->closeCursor(); return null; } catch (PDOException $e) { return (int)($e->errorInfo[1] ?? -1); } }

const UNI = ['uni_entity', 'uni_event', 'uni_edge', 'uni_release', 'uni_source', 'uni_entity_source', 'uni_event_source', 'uni_edge_source', 'uni_release_parent', 'uni_review', 'uni_audit_log', 'uni_correction'];
function counts(PDO $p): array { $o = []; foreach (UNI as $t) $o[$t] = (int)val($p, "SELECT COUNT(*) FROM $t"); return $o; }
function rowsHash(PDO $p): string { $h = ''; foreach (['uni_entity', 'uni_event', 'uni_edge', 'uni_source'] as $t) { $h .= md5(json_encode($p->query("SELECT * FROM $t ORDER BY id")->fetchAll(PDO::FETCH_NUM))); } return $h; }

/** Runs scripts/uni_import.php as a separate process. @return array{rc:int,out:string,err:string} */
function imp(array $args, array $env = []): array {
  global $root, $host, $db, $pw, $user;
  $e = array_merge(getenv(), ['AIHUB_DB_HOST' => $host, 'AIHUB_DB_NAME' => $db, 'AIHUB_DB_USER' => $user, 'AIHUB_DB_PASS' => $pw, 'AIHUB_IMPORT_DB_USER' => '', 'AIHUB_IMPORT_DB_PASS' => ''], $env);
  $p = proc_open(array_merge([PHP_BINARY, $root . '/scripts/uni_import.php'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $e);
  $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
  return ['rc' => proc_close($p), 'out' => (string)$out, 'err' => (string)$err];
}
function wf(string $name, string $content): string { global $scratch; $p = "$scratch/$name"; file_put_contents($p, $content); return $p; }
function wj(string $name, array $doc): string { return wf($name, json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
function rj(array $r): array { $j = json_decode($r['out'], true); return is_array($j) ? $j : []; }

/** A small valid document; $t makes slugs unique per test. */
function doc(string $t = 'a'): array {
  return ['format' => 'aihubpedia-import-v1', 'synthetic' => true,
    'sources' => [['id' => 's1', 'url' => "https://example.test/p7im/$t/one", 'publisher' => 'Synthetic Publisher', 'retrieved_at' => '2025-02-01 10:00:00', 'quote' => 'SYNTHETIC quote one']],
    'entities' => [['entity_type' => 'company', 'slug' => "imp-co-$t", 'name' => "Import Co $t (synthetic)", 'summary' => 'synthetic', 'sources' => ['s1']], ['entity_type' => 'model', 'slug' => "imp-model-$t", 'name' => "Import Model $t (synthetic)", 'sources' => ['s1']]],
    'events' => [['entity' => "imp-model-$t", 'event_type' => 'model_release', 'event_date' => '2024-05-06', 'title' => "Import Model $t released (synthetic)", 'sources' => ['s1']]],
    'edges' => [['from' => "imp-model-$t", 'to' => "imp-co-$t", 'edge_type' => 'developed_by', 'sources' => ['s1']]]];
}

// ---------------------------------------------------------------------------------------------------------------------------------------------
try {
  $admin->exec("DROP USER IF EXISTS '$lpUser'@'%'"); $admin->exec("DROP DATABASE IF EXISTS `$db`");
  $admin->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
  $adm = new PDO($dsnBase . ";dbname=$db", $user, $pw, $opt);
  applyFile($adm, $root . '/database/phase6_crm.sql');
  $H = password_hash('test-only-password', PASSWORD_DEFAULT);
  $mk = function (string $name, string $role, int $active = 1) use ($adm, $H): int { [$o, $id] = crm_user_create($adm, $name, ucfirst($name) . ' Display', $role, $H); if (!$o) throw new RuntimeException("user $name: $id"); if (!$active) $adm->exec('UPDATE crm_users SET active = 0 WHERE id = ' . (int)$id); return (int)$id; };
  $ed = $mk('imp_editor', 'editor'); $ed2 = $mk('imp_editor2', 'editor'); $ver = $mk('imp_ver1', 'verifier'); $ver2 = $mk('imp_ver2', 'verifier'); $ver3 = $mk('imp_ver3', 'verifier');
  $mk('imp_viewer', 'viewer'); $mk('imp_sales', 'sales'); $mk('imp_owner', 'owner'); $mk('imp_dead', 'editor', 0);
  $reset = function (bool $triggers) use ($adm, $root) { applyFile($adm, $root . '/database/phase7_rollback.sql'); applyFile($adm, $root . '/database/phase7_universe.sql'); if ($triggers) applyFile($adm, $root . '/database/phase7_triggers.sql'); };
  $A = ['--user=imp_editor'];

  // ----------------------------------------------------------------------------------------------------------------------------- static properties
  section('CLI only, no network code, no secrets in the source');
  $src = (string)file_get_contents($root . '/scripts/uni_import.php');
  ok(preg_match("/^<\?php\s+declare\(strict_types=1\);\s+if \(PHP_SAPI !== 'cli'\) \{ http_response_code\(404\); exit; \}/", $src) === 1, 'the script refuses non-CLI SAPIs before anything else');
  ok(preg_match('/curl_|fsockopen|pfsockopen|stream_socket_client|gethostbyname|SoapClient|get_headers|file_get_contents|fopen\(\s*[\'"]https?:|allow_url_fopen/i', $src) !== 1, 'the importer contains no network or URL-opening code');
  ok(preg_match('/password\s*=\s*[\'"][^\'"]+[\'"]/i', $src) !== 1, 'no credential in the source');
  $port = 18000 + ($pid % 1000); $web = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $wp, $root);
  $code = 0; for ($i = 0; $i < 40; $i++) { $h = @fopen("http://127.0.0.1:$port/scripts/uni_import.php", 'r', false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 2]])); if ($h) { $meta = stream_get_meta_data($h); preg_match('#HTTP/\S+ (\d+)#', (string)($meta['wrapper_data'][0] ?? ''), $m); $code = (int)($m[1] ?? 0); $body = stream_get_contents($h); fclose($h); break; } usleep(100000); }
  proc_terminate($web); proc_close($web);
  ok($code === 404 && stripos((string)($body ?? ''), 'created') === false, "requesting the importer over HTTP gives 404 and runs nothing (status $code)");

  foreach ([false, true] as $triggers) {
    $mode = $triggers ? 'triggers' : 'no-triggers';
    $reset($triggers);

    // --------------------------------------------------------------------------------------------------------------------------- dry run
    section("[$mode] dry run is the default and writes nothing");
    $f = wj("main_$mode.json", doc('main'));
    $before = counts($adm); $h0 = rowsHash($adm);
    $r = imp(array_merge(['--file=' . $f], $A));
    ok($r['rc'] === 0 && str_contains($r['out'], 'would_create=4') && str_contains($r['out'], 'nothing was written'), "[$mode] default run is a dry run reporting would_create=4 (rc {$r['rc']})");
    ok(counts($adm) === $before && rowsHash($adm) === $h0, "[$mode] dry run changed no row in any uni table, audit log included");
    $r = imp(array_merge(['--file=' . $f, '--dry-run'], $A)); ok($r['rc'] === 0 && counts($adm) === $before, "[$mode] explicit --dry-run also writes nothing");
    $r = imp(array_merge(['--file=' . $f, '--dry-run', '--apply'], $A)); ok($r['rc'] === 2 && counts($adm) === $before, "[$mode] --apply together with --dry-run is refused");
    $r = imp(['--file=' . $f]); ok($r['rc'] === 2 && str_contains($r['out'], 'user_required'), "[$mode] the user must be named explicitly (no default)");
    $r = imp(['--user=imp_editor']); ok($r['rc'] === 2 && str_contains($r['out'], 'file_required'), "[$mode] the file must be named explicitly");

    // --------------------------------------------------------------------------------------------------------------------------- roles
    section("[$mode] only an active editor may run the importer");
    foreach (['imp_viewer', 'imp_sales', 'imp_ver1', 'imp_owner', 'imp_dead', 'imp_nobody', 'Imp_Editor', "imp_editor'--", ''] as $bad) {
      $r = imp(['--file=' . $f, '--apply', '--user=' . $bad]);
      ok($r['rc'] === 2 && counts($adm) === $before && !str_contains($r['out'], 'created=4'), "[$mode] user '" . imp_clean_t($bad) . "' is refused with exit 2 and no write (rc {$r['rc']})");
    }

    // --------------------------------------------------------------------------------------------------------------------------- apply
    section("[$mode] apply creates only proposed, origin import rows");
    $r = imp(array_merge(['--file=' . $f, '--apply', '--json'], $A)); $j = rj($r);
    ok($r['rc'] === 0 && ($j['totals']['created'] ?? -1) === 4 && $j['errors'] === [], "[$mode] apply created 4 records (rc {$r['rc']})");
    $c = counts($adm);
    ok($c['uni_entity'] === 2 && $c['uni_event'] === 1 && $c['uni_edge'] === 1 && $c['uni_source'] === 1 && $c['uni_entity_source'] === 2 && $c['uni_event_source'] === 1 && $c['uni_edge_source'] === 1, "[$mode] row counts: 2 entities, 1 event, 1 edge, 1 shared source, 4 source links");
    ok($c['uni_review'] === 0 && $c['uni_release'] === 0, "[$mode] no review and no release rows exist");
    foreach (['uni_entity', 'uni_event', 'uni_edge'] as $t) ok((int)val($adm, "SELECT COUNT(*) FROM $t WHERE state = 'proposed' AND origin = 'import' AND proposed_by = ? AND verified_by IS NULL AND verified_at IS NULL AND review_id IS NULL AND version_no = 1 AND supersedes_id IS NULL", [$ed]) === (int)val($adm, "SELECT COUNT(*) FROM $t"), "[$mode] every $t row is proposed, origin import, proposed by the named editor, with no verifier");
    ok((int)val($adm, "SELECT COUNT(*) FROM uni_source WHERE origin = 'import' AND created_by = ?", [$ed]) === 1, "[$mode] the source row is origin import and created by the editor");
    ok((int)val($adm, "SELECT COUNT(*) FROM uni_entity WHERE state <> 'proposed' OR is_current <> 1") === 0 && (int)val($adm, "SELECT COUNT(*) FROM uni_event e JOIN uni_entity n ON n.id = e.entity_id WHERE e.state = 'verified'") === 0, "[$mode] nothing is verified and nothing is public");
    ok((int)val($adm, "SELECT COUNT(*) FROM uni_audit_log WHERE action = 'import_record'") === 4 && (int)val($adm, "SELECT COUNT(*) FROM uni_audit_log WHERE action = 'import_run'") === 1 && (int)val($adm, "SELECT COUNT(*) FROM uni_audit_log WHERE action = 'propose' AND actor_id = ?", [$ed]) === 4, "[$mode] the service wrote the audit rows (4 proposals, 4 import records, 1 run)");
    ok(!str_contains($r['out'], 'test-only-password') && !str_contains($r['out'] . $r['err'], $pw), "[$mode] output contains no password");
    [$pub, $pubRows] = [uni_publish_state($adm, 'entity', (int)val($adm, "SELECT id FROM uni_entity WHERE slug = 'imp-co-main'")), null];
    ok($pub !== null && $pub['public'] === false && $pub['valid_sources'] === 1, "[$mode] the imported entity is not public and has its source");

    // --------------------------------------------------------------------------------------------------------------------------- idempotency
    section("[$mode] idempotency: re-running creates nothing");
    $before = counts($adm); $h0 = rowsHash($adm);
    $r = imp(array_merge(['--file=' . $f, '--apply', '--json'], $A)); $j = rj($r);
    ok($r['rc'] === 0 && ($j['totals']['created'] ?? -1) === 0 && ($j['totals']['skipped_already_imported'] ?? -1) === 4, "[$mode] second run: created 0, skipped_already_imported 4");
    ok(counts($adm) === $before && rowsHash($adm) === $h0, "[$mode] no row changed or added by the re-run (audit log included)");
    $d2 = doc('main'); $d2['entities'][0]['name'] = 'CHANGED NAME'; $d2['entities'][0]['summary'] = 'changed';
    $r = imp(array_merge(['--file=' . wj("main2_$mode.json", $d2), '--apply', '--json'], $A)); $j = rj($r);
    ok($r['rc'] === 0 && ($j['totals']['created'] ?? -1) === 0 && rowsHash($adm) === $h0, "[$mode] the same record key with different content is skipped, never overwritten");
    $d3 = doc('main'); $d3['entities'][0]['key'] = 'stable-co'; $d3['entities'][0]['slug'] = 'imp-co-main-renamed';   // explicit key: first import under this key, then a rename must not create a second row
    $d4 = doc('keyed'); $d4['entities'][0]['key'] = 'stable-key-1'; $d5 = $d4; $d5['entities'][0]['slug'] = 'imp-co-keyed-renamed';
    $r = imp(array_merge(['--file=' . wj("k1_$mode.json", $d4), '--apply'], $A)); $n1 = (int)val($adm, 'SELECT COUNT(*) FROM uni_entity');
    $r2 = imp(array_merge(['--file=' . wj("k2_$mode.json", $d5), '--apply', '--json'], $A)); $j2 = rj($r2);
    ok($r['rc'] === 0 && $r2['rc'] === 0 && (int)val($adm, 'SELECT COUNT(*) FROM uni_entity') === $n1 && (int)val($adm, "SELECT COUNT(*) FROM uni_entity WHERE slug = 'imp-co-keyed-renamed'") === 0, "[$mode] an explicit key makes a renamed record idempotent too");

    // --------------------------------------------------------------------------------------------------------------------------- duplicates
    section("[$mode] duplicates are flagged and skipped, never merged");
    [$ok1, $existing] = uni_propose_entity($adm, $ed2, ['entity_type' => 'company', 'slug' => 'pre-existing-co', 'name' => 'Pre Existing Co', 'summary' => 'human made']); ok($ok1, "[$mode] pre-existing human entity");
    [$ok2, $evo] = uni_propose_event($adm, $ed2, ['entity_id' => (int)$existing, 'event_type' => 'launch', 'event_date' => '2022-02-02', 'title' => 'Pre Existing launch']); ok($ok2, "[$mode] pre-existing human event");
    [$ok3, $other] = uni_propose_entity($adm, $ed2, ['entity_type' => 'model', 'slug' => 'pre-existing-model', 'name' => 'Pre Existing Model', 'summary' => '']); ok($ok3, "[$mode] second pre-existing entity");
    [$ok4, $edge0] = uni_propose_edge($adm, $ed2, ['from_entity_id' => (int)$other, 'to_entity_id' => (int)$existing, 'edge_type' => 'developed_by']); ok($ok4, "[$mode] pre-existing human edge");
    $dd = doc('dup'); $dd['entities'] = [['entity_type' => 'company', 'slug' => 'pre-existing-co', 'name' => 'Totally Different Name', 'summary' => 'overwrite attempt', 'sources' => ['s1']],
      ['entity_type' => 'company', 'slug' => 'pre-existing-co-two', 'name' => 'PRE-existing   Co!', 'sources' => ['s1']]];
    $dd['events'] = [['entity' => 'pre-existing-co', 'event_type' => 'launch', 'event_date' => '2022-02-02', 'title' => 'pre existing LAUNCH', 'sources' => ['s1']], ['entity' => 'pre-existing-co', 'event_type' => 'launch', 'event_date' => '2022-03-03', 'title' => 'Pre Existing launch', 'sources' => ['s1']]];
    $dd['edges'] = [['from' => 'pre-existing-model', 'to' => 'pre-existing-co', 'edge_type' => 'developed_by', 'sources' => ['s1']]];
    $snap = json_encode([$adm->query("SELECT * FROM uni_entity WHERE id = " . (int)$existing)->fetch(), $adm->query("SELECT * FROM uni_event WHERE id = " . (int)$evo['id'])->fetch(), $adm->query("SELECT * FROM uni_edge WHERE id = " . (int)$edge0)->fetch()]);
    $r = imp(array_merge(['--file=' . wj("dup_$mode.json", $dd), '--apply', '--json'], $A)); $j = rj($r); $t = $j['totals'] ?? [];
    ok($r['rc'] === 0 && ($t['skipped_duplicate'] ?? -1) === 3 && ($t['created'] ?? -1) === 2 && ($t['flagged'] ?? -1) === 1, "[$mode] 3 duplicates skipped (slug, event, edge), 2 created (name-similar entity flagged, other-date event) (rc {$r['rc']})");
    ok(json_encode([$adm->query("SELECT * FROM uni_entity WHERE id = " . (int)$existing)->fetch(), $adm->query("SELECT * FROM uni_event WHERE id = " . (int)$evo['id'])->fetch(), $adm->query("SELECT * FROM uni_edge WHERE id = " . (int)$edge0)->fetch()]) === $snap, "[$mode] the existing rows are byte-for-byte unchanged (nothing merged or overwritten)");
    ok((int)val($adm, "SELECT COUNT(*) FROM uni_audit_log WHERE action = 'duplicate_flag' AND target_type = 'entity'") === 1 && (int)val($adm, "SELECT COUNT(*) FROM uni_audit_log WHERE action = 'conflict_recorded'") === 1, "[$mode] the name-similar entity and the date conflict are recorded in the audit log");
    ok((int)val($adm, "SELECT COUNT(*) FROM uni_entity WHERE slug = 'pre-existing-co' AND is_current = 1") === 1, "[$mode] exactly one current row keeps the duplicate slug");

    // --------------------------------------------------------------------------------------------------------------------------- imports cannot be verified
    section("[$mode] imported rows can never be verified; a human verifier flow works through a NEW version");
    $imEnt = (int)val($adm, "SELECT id FROM uni_entity WHERE slug = 'imp-co-main'"); $imMod = (int)val($adm, "SELECT id FROM uni_entity WHERE slug = 'imp-model-main'");
    $imEv = (int)val($adm, "SELECT id FROM uni_event WHERE entity_id = $imMod"); $imEdge = (int)val($adm, "SELECT id FROM uni_edge WHERE from_entity_id = $imMod");
    $no = fn($rr, string $code, string $m) => ok(is_array($rr) && $rr[0] === false && $rr[1] === $code, $m . ' (got ' . json_encode($rr) . ')');
    $no(uni_submit_for_review($adm, $ed, 'entity', $imEnt), 'import_needs_new_version', "[$mode] service: an import row cannot be submitted for review");
    $adm->exec("UPDATE uni_entity SET state = 'in_review' WHERE id = $imEnt");   // admin forces the state to prove the reviewer path refuses too
    $no(uni_review($adm, $ver, 'entity', $imEnt, 'accept'), 'import_cannot_be_verified', "[$mode] service: a verifier cannot accept an import row even in review");
    $adm->exec("UPDATE uni_entity SET state = 'proposed' WHERE id = $imEnt");
    $adm->exec("INSERT INTO uni_review (target_type, target_id, reviewer_id, proposed_by, decision, decided_at) VALUES ('entity', $imEnt, $ver, $ed, 'accept', NOW())"); $rid = (int)$adm->lastInsertId();
    foreach (['uni_entity' => $imEnt, 'uni_event' => $imEv, 'uni_edge' => $imEdge] as $t => $id) {
      $e = errno($adm, "UPDATE $t SET state = 'verified', verified_by = ?, verified_at = NOW(), review_id = ? WHERE id = ?", [$ver, $rid, $id]);
      ok($e !== null && (string)val($adm, "SELECT state FROM $t WHERE id = ?", [$id]) === 'proposed', "[$mode] raw SQL cannot mark an import row in $t verified (CHECK constraint, error " . var_export($e, true) . ')');
    }
    $e = errno($adm, "INSERT INTO uni_entity (entity_type, slug, name, summary, state, origin, proposed_by, proposed_at, verified_by, verified_at, review_id, lineage_key) VALUES ('company', 'raw-verified-import', 'x', '', 'verified', 'import', ?, NOW(), ?, NOW(), ?, ?)", [$ed, $ver, $rid, bin2hex(random_bytes(16))]);
    ok($e !== null, "[$mode] raw SQL cannot INSERT a verified import row either (error " . var_export($e, true) . ')');
    errno($adm, "DELETE FROM uni_review WHERE id = $rid");   // refused when the append-only trigger is installed; the stray review row is harmless (it targets the import row)
    $no(uni_correct($adm, $ed2, 'entity', $imEnt, ['name' => 'x', 'origin' => 'import']), 'origin_invalid', "[$mode] a correction cannot keep origin import");
    $publish = function (string $type, int $id, int $by) use ($adm, $ver, $ver2, $ver3): string {
      $s = uni_submit_for_review($adm, $by, $type, $id); if (!$s[0]) return 'submit:' . $s[1];
      foreach ([$ver, $ver2, $ver3] as $v) { $r = uni_review($adm, $v, $type, $id, 'accept'); if (!$r[0]) return 'review:' . $r[1]; if ($r[1]['state'] === 'verified') return 'verified'; }
      return 'stuck';
    };
    $newIds = [];
    foreach (['entity' => [$imEnt, $imMod], ] as $type => $ids) foreach ($ids as $id) {
      [$o, $nid] = uni_correct($adm, $ed2, 'entity', $id, ['origin' => 'human', 'summary' => 'checked by a human editor']); ok($o, "[$mode] editor 2 proposes a human version of import entity $id");
      ok($o && $publish('entity', (int)$nid, $ed2) === 'verified', "[$mode] a different human verifier verifies the new entity version");
      $newIds[$id] = (int)$nid;
    }
    ok((string)val($adm, 'SELECT state FROM uni_entity WHERE id = ?', [$imEnt]) === 'superseded' && (string)val($adm, 'SELECT origin FROM uni_entity WHERE id = ?', [$newIds[$imEnt]]) === 'human', "[$mode] the import row is superseded, the verified row has origin human");
    [$o, $nev] = uni_correct($adm, $ed2, 'event', $imEv, ['origin' => 'human']); ok($o && $publish('event', (int)$nev, $ed2) === 'verified', "[$mode] the import event becomes verified through a new human version");
    [$o, $ned] = uni_correct($adm, $ed2, 'edge', $imEdge, ['origin' => 'human']); ok($o && ($pr = $publish('edge', (int)$ned, $ed2)) === 'verified' || fwrite(STDERR, "   edge publish: " . json_encode([$o, $ned, $pr ?? null]) . "\n") && false, "[$mode] the import edge becomes verified through a new human version");
    // regression for the uni_core.php fix: correcting a verified edge after one of its endpoint entities received a newer verified version must re-point the edge at the live rows
    [$o, $nent2] = uni_correct($adm, $ed2, 'entity', $newIds[$imEnt], ['origin' => 'human', 'summary' => 'second human revision']); ok($o && $publish('entity', (int)$nent2, $ed2) === 'verified', "[$mode] the verified entity gets a further verified version");
    [$o, $ned2] = uni_correct($adm, $ed2, 'edge', (int)$ned, ['origin' => 'human', 'notes' => 'after entity revision']); ok($o && ($pr2 = $publish('edge', (int)$ned2, $ed2)) === 'verified' || fwrite(STDERR, '   edge publish 2: ' . json_encode([$o, $ned2, $pr2 ?? null]) . "\n") && false, "[$mode] the verified edge can be corrected and re-verified after its endpoint entity was superseded");
    ok($o && (int)val($adm, 'SELECT to_entity_id FROM uni_edge WHERE id = ?', [$ned2]) === (int)$nent2, "[$mode] the new edge version points at the live entity row");
    ok((int)val($adm, "SELECT COUNT(*) FROM uni_entity WHERE origin = 'import' AND state = 'verified'") + (int)val($adm, "SELECT COUNT(*) FROM uni_event WHERE origin = 'import' AND state = 'verified'") + (int)val($adm, "SELECT COUNT(*) FROM uni_edge WHERE origin = 'import' AND state = 'verified'") === 0, "[$mode] no verified row with origin import exists anywhere");
    ok((int)val($adm, "SELECT COUNT(*) FROM uni_entity WHERE state = 'verified' AND verified_by = proposed_by") === 0 && (int)val($adm, "SELECT COUNT(*) FROM uni_entity WHERE state = 'verified' AND proposed_by IN (SELECT id FROM crm_users WHERE role <> 'editor')") === 0, "[$mode] verified rows have a verifier distinct from the proposer");
    $r = imp(array_merge(['--file=' . $f, '--apply', '--json'], $A)); $j = rj($r);
    ok($r['rc'] === 0 && ($j['totals']['created'] ?? -1) === 0, "[$mode] re-importing after human verification still creates nothing (key remembered in the audit log)");
  }

  // ------------------------------------------------------------------------------------------------------------------------------- hostile input
  section('hostile and malformed input is rejected without writes');
  $reset(false);
  $imp0 = imp(array_merge(['--file=' . wj('seed.json', doc('seed')), '--apply'], $A)); ok($imp0['rc'] === 0, 'seed import for the hostile tests');
  $before = counts($adm); $h0 = rowsHash($adm);
  $rej = function (string $label, string $file, string $expect, int $rc = 1, ?array $extra = null) use ($A, $adm, &$before, &$h0) {
    $r = imp(array_merge(['--file=' . $file, '--apply'], $extra ?? $A));
    ok($r['rc'] === $rc && str_contains($r['out'], $expect) && counts($adm) === $before && rowsHash($adm) === $h0, "$label -> rc $rc, '$expect', nothing written (rc {$r['rc']}; " . substr(trim(str_replace("\n", ' | ', $r['out'])), 0, 160) . ')');
    return $r;
  };
  $m = function (callable $f, string $t) { $d = doc($t); $f($d); return $d; };
  $rej('huge file', wf('huge.json', str_repeat(' ', 2097152 + 10) . '{}'), 'file_too_large');
  $rej('bad UTF-8', wf('badutf.json', "{\"format\":\"aihubpedia-import-v1\",\"description\":\"\xC3\x28\"}"), 'invalid_utf8_or_nul');
  $rej('NUL byte', wf('nul.json', "{\"format\":\"aihubpedia-import-v1\"}\0"), 'invalid_utf8_or_nul');
  $rej('NUL via escape', wf('nul2.json', '{"format":"aihubpedia-import-v1","description":"a\u0000b"}'), 'nul_character');
  $rej('lone surrogate escape', wf('sur.json', '{"format":"aihubpedia-import-v1","description":"\ud800"}'), 'lone_surrogate');
  $rej('deep nesting', wf('deep.json', '{"format":"aihubpedia-import-v1","description":' . str_repeat('[', 40) . str_repeat(']', 40) . '}'), 'nesting_too_deep');
  $rej('trailing garbage', wf('trail.json', json_encode(doc('t1')) . ' x'), 'trailing_content');
  $rej('not JSON', wf('nj.json', 'hello'), 'unexpected_character');
  $rej('truncated JSON', wf('tr.json', substr(json_encode(doc('t2')), 0, 120)), 'unterminated_string');
  $rej('top-level list', wf('list.json', '[1,2]'), 'top_level_must_be_object');
  $rej('control character in string', wf('ctl.json', "{\"format\":\"aihubpedia-import-v1\",\"description\":\"a\nb\"}"), 'control_character_in_string');
  $dk = wf('dupkey.json', "{\n\"format\": \"aihubpedia-import-v1\",\n\"sources\": [],\n\"sources\": []\n}"); $r = $rej('duplicate JSON object key', $dk, 'duplicate_key'); ok(str_contains($r['out'], 'line 4'), 'the duplicate key error names line 4');
  $rej('unknown top-level field', wj('unk.json', doc('u1') + ['extra' => 1]), 'unknown_field');
  $rej('wrong format tag', wj('fmt.json', ['format' => 'other'] + doc('u2')), 'format_unsupported');
  $rej('no records at all', wj('norec.json', ['format' => 'aihubpedia-import-v1', 'sources' => []]), 'no_records');
  $rej('javascript: URL', wj('js.json', $m(function (&$d) { $d['sources'][0]['url'] = 'javascript:alert(1)'; }, 'h1')), 'url_invalid');
  $rej('data: URL', wj('data.json', $m(function (&$d) { $d['sources'][0]['url'] = 'data:text/html;base64,PHNjcmlwdD4='; }, 'h2')), 'url_invalid');
  $rej('URL with credentials', wj('cred.json', $m(function (&$d) { $d['sources'][0]['url'] = 'https://user:secret@example.test/x'; }, 'h3')), 'url_invalid');
  $rej('URL without a dotted host', wj('host.json', $m(function (&$d) { $d['sources'][0]['url'] = 'http://localhost/x'; }, 'h4')), 'url_invalid');
  $rej('URL with a quote character', wj('q.json', $m(function (&$d) { $d['sources'][0]['url'] = 'https://example.test/"onmouseover=x'; }, 'h5')), 'url_invalid');
  $rej('missing quote', wj('noq.json', $m(function (&$d) { unset($d['sources'][0]['quote']); }, 'h6')), 'missing');
  $rej('empty quote', wj('eq.json', $m(function (&$d) { $d['sources'][0]['quote'] = '   '; }, 'h7')), 'empty');
  $rej('zero-width-only quote', wj('zw.json', $m(function (&$d) { $d['sources'][0]['quote'] = "\u{200B}\u{200D}\u{FEFF}"; }, 'h8')), 'blank');
  $rej('missing publisher', wj('np.json', $m(function (&$d) { $d['sources'][0]['publisher'] = ''; }, 'h9')), 'empty');
  $rej('bad retrieved_at', wj('ra.json', $m(function (&$d) { $d['sources'][0]['retrieved_at'] = '2025-13-45'; }, 'h10')), 'retrieved_at_invalid');
  $rej('future retrieved_at', wj('fut.json', $m(function (&$d) { $d['sources'][0]['retrieved_at'] = '2099-01-01'; }, 'h11')), 'retrieved_at_invalid');
  $rej('bad content_hash', wj('hash.json', $m(function (&$d) { $d['sources'][0]['content_hash'] = 'xyz'; }, 'h12')), 'hash_invalid');
  $rej('record without sources', wj('nosrc.json', $m(function (&$d) { unset($d['entities'][0]['sources']); }, 'h13')), 'source_required');
  $rej('record with empty sources', wj('nosrc2.json', $m(function (&$d) { $d['events'][0]['sources'] = []; }, 'h14')), 'source_required');
  $rej('unknown source reference', wj('badref.json', $m(function (&$d) { $d['edges'][0]['sources'] = ['nope']; }, 'h15')), 'unknown_source_reference');
  $rej('duplicate source id', wj('dsid.json', $m(function (&$d) { $d['sources'][] = $d['sources'][0]; }, 'h16')), 'duplicate_id');
  $rej('SQL-injection-shaped slug', wj('sqli.json', $m(function (&$d) { $d['entities'][0]['slug'] = "x'); DROP TABLE uni_entity;--"; }, 'h17')), 'slug_invalid');
  $rej('uppercase slug', wj('up.json', $m(function (&$d) { $d['entities'][0]['slug'] = 'Upper-Case'; }, 'h18')), 'slug_invalid');
  $rej('bad entity_type', wj('et.json', $m(function (&$d) { $d['entities'][0]['entity_type'] = 'verified'; }, 'h19')), 'invalid_value');
  $rej('name is not a string', wj('ns.json', $m(function (&$d) { $d['entities'][0]['name'] = ['a']; }, 'h20')), 'not_a_string');
  $rej('name too long', wj('nl.json', $m(function (&$d) { $d['entities'][0]['name'] = str_repeat('n', 191); }, 'h21')), 'too_long');
  $rej('name with control character', wj('nc.json', $m(function (&$d) { $d['entities'][0]['name'] = "a\x07b"; }, 'h22')), 'control_characters');
  $rej('name with bidi override', wj('bidi.json', $m(function (&$d) { $d['entities'][0]['name'] = "a\u{202E}b"; }, 'h23')), 'bidi_control_characters');
  $rej('record tries to set state', wj('st.json', $m(function (&$d) { $d['entities'][0]['state'] = 'verified'; }, 'h24')), 'unknown_field');
  $rej('record tries to set origin', wj('or.json', $m(function (&$d) { $d['entities'][0]['origin'] = 'human'; }, 'h25')), 'unknown_field');
  $rej('record tries to name a verifier', wj('vb.json', $m(function (&$d) { $d['events'][0]['verified_by'] = 3; }, 'h26')), 'unknown_field');
  $rej('bad event date', wj('ed.json', $m(function (&$d) { $d['events'][0]['event_date'] = '2024-02-30'; }, 'h27')), 'date_invalid');
  $rej('year date with day precision', wj('ep.json', $m(function (&$d) { $d['events'][0]['event_date'] = '2024'; $d['events'][0]['date_precision'] = 'day'; }, 'h28')), 'date_invalid');
  $rej('bad event_type', wj('evt.json', $m(function (&$d) { $d['events'][0]['event_type'] = 'x'; }, 'h29')), 'invalid_value');
  $rej('self edge', wj('self.json', $m(function (&$d) { $d['edges'][0]['to'] = $d['edges'][0]['from']; }, 'h30')), 'self_edge');
  $rej('edge validity reversed', wj('val.json', $m(function (&$d) { $d['edges'][0]['valid_from'] = '2024-05-01'; $d['edges'][0]['valid_to'] = '2023-01-01'; }, 'h31')), 'validity_invalid');
  $rej('duplicate slug in the file', wj('ds.json', $m(function (&$d) { $d['entities'][1]['slug'] = $d['entities'][0]['slug']; }, 'h32')), 'duplicate_slug_in_file');
  $rej('duplicate event in the file', wj('de.json', $m(function (&$d) { $d['events'][] = $d['events'][0]; }, 'h33')), 'duplicate_in_file');
  $rej('duplicate explicit key in the file', wj('dk.json', $m(function (&$d) { $d['entities'][0]['key'] = 'k'; $d['entities'][1]['key'] = 'k'; }, 'h34')), 'duplicate_key_in_file');
  $rej('invalid key', wj('ik.json', $m(function (&$d) { $d['entities'][0]['key'] = "a b'"; }, 'h35')), 'key_invalid');
  $rej('too many sources per record', wj('tms.json', $m(function (&$d) { for ($i = 2; $i <= 12; $i++) { $d['sources'][] = ['id' => "x$i", 'url' => "https://example.test/x/$i", 'publisher' => 'P', 'retrieved_at' => '2025-01-01', 'quote' => 'q']; $d['entities'][0]['sources'][] = "x$i"; } }, 'h36')), 'too_many_sources');
  $big = doc('big'); $big['entities'] = []; for ($i = 0; $i < 2001; $i++) $big['entities'][] = ['entity_type' => 'company', 'slug' => "bulk-$i", 'name' => "Bulk $i", 'sources' => ['s1']]; $big['events'] = []; $big['edges'] = [];
  $rej('too many records', wj('many.json', $big), 'too_many_records');
  $r = $rej('line numbers in errors', wf('lines.json', "{\n \"format\": \"aihubpedia-import-v1\",\n \"sources\": [\n  {\"id\": \"s1\", \"url\": \"https://example.test/a\", \"publisher\": \"P\", \"retrieved_at\": \"2025-01-01\", \"quote\": \"q\"}\n ],\n \"entities\": [\n  {\"entity_type\": \"company\", \"slug\": \"fine-one\", \"name\": \"Fine\", \"sources\": [\"s1\"]},\n  {\"entity_type\": \"company\", \"slug\": \"BAD SLUG\", \"name\": \"Bad\", \"sources\": [\"s1\"]}\n ]\n}"), 'slug_invalid');
  ok(str_contains($r['out'], 'entities[1]') && str_contains($r['out'], 'line 8') && !str_contains($r['out'], 'BAD SLUG'), 'the error names entities[1], line 8 and does not echo the offending value');
  $mixed = doc('mixed'); $mixed['edges'][0]['edge_type'] = 'nonsense'; $rej('valid records plus one invalid record', wj('mixed.json', $mixed), 'invalid_value');
  $rej('terminal escape in a field is not echoed', wj('esc.json', $m(function (&$d) { $d['entities'][0]['bogus' . "\x1b[31m"] = 1; }, 'h37')), 'unknown_field');
  ok(!str_contains(imp(array_merge(['--file=' . wj('esc2.json', $m(function (&$d) { $d['entities'][0]["bogus\x1b[31m"] = 1; }, 'h38')), '--apply'], $A))['out'], "\x1b"), 'error output contains no raw escape characters');

  section('database-stage failure rolls the whole transaction back');
  $d = doc('rb'); $d['events'] = []; $d['edges'] = []; $d['events'][] = ['entity' => 'no-such-entity-anywhere', 'event_type' => 'launch', 'event_date' => '2020-01-01', 'title' => 'orphan', 'sources' => ['s1']];
  $r = imp(array_merge(['--file=' . wj('rb.json', $d), '--apply', '--json'], $A)); $j = rj($r);
  ok($r['rc'] === 1 && counts($adm) === $before && rowsHash($adm) === $h0 && ($j['rolled_back'] ?? false) === true && ($j['totals']['created'] ?? -1) === 0, 'an event for an unknown entity fails after earlier records were inserted: everything rolled back, created reported as 0');
  ok(count(array_filter($j['records'] ?? [], fn($x) => $x['status'] === 'rolled_back')) === 2 && ($j['records'][count($j['records']) - 1]['detail'] ?? '') === 'entity_not_found', 'the report marks the earlier rows rolled_back and names entity_not_found');
  $r = imp(array_merge(['--file=' . wj('rb2.json', $d)], $A)); ok($r['rc'] === 1 && str_contains($r['out'], 'entity_not_found') && counts($adm) === $before, 'the dry run finds the same problem');
  $r = imp(array_merge(['--file=' . wj('rb3.json', $d), '--apply', '--batch-size=1', '--json'], $A)); $j = rj($r);
  ok($r['rc'] === 1 && (int)val($adm, 'SELECT COUNT(*) FROM uni_entity') === $before['uni_entity'] + 2 && ($j['totals']['created'] ?? -1) === 2, 'with --batch-size=1 earlier committed batches stay (2 entities) and the report says so');
  $r = imp(array_merge(['--file=' . wj('rb3.json', $d), '--apply', '--json'], $A)); $j = rj($r);
  ok($r['rc'] === 1 && ($j['totals']['skipped_already_imported'] ?? -1) === 2, 're-running a half-committed batch file skips what was committed');
  $before = counts($adm); $h0 = rowsHash($adm);

  section('SQL-injection-shaped text in free-text fields is stored literally');
  $inj = "Robert'); DROP TABLE uni_entity;-- \" OR 1=1 /*";
  $d = doc('inj'); $d['entities'][0]['name'] = $inj; $d['sources'][0]['quote'] = "'; DELETE FROM uni_source; --"; $d['sources'][0]['publisher'] = '<script>alert(1)</script>';
  $r = imp(array_merge(['--file=' . wj('inj.json', $d), '--apply'], $A));
  ok($r['rc'] === 0 && (string)val($adm, "SELECT name FROM uni_entity WHERE slug = 'imp-co-inj'") === $inj && (string)val($adm, "SELECT quote FROM uni_source WHERE publisher = '<script>alert(1)</script>'") === "'; DELETE FROM uni_source; --" && counts($adm)['uni_source'] === $before['uni_source'] + 1 && counts($adm)['uni_entity'] === $before['uni_entity'] + 2, 'prepared statements: the text is stored as data and no table was touched');

  $before = counts($adm); $h0 = rowsHash($adm);
  section('path handling');
  $good = wj('path_ok.json', doc('path'));
  $rej('path traversal', "$scratch/../" . basename($scratch) . '/path_ok.json', 'path_traversal_refused');
  $rej('path traversal in the middle', "$scratch/sub/../path_ok.json", 'path_traversal_refused');
  $rej('missing file', "$scratch/nope.json", 'file_not_found');
  @mkdir("$scratch/adir.json"); $rej('a directory', "$scratch/adir.json", 'not_a_regular_file');
  @symlink($good, "$scratch/link.json"); $rej('a symlink to a valid file', "$scratch/link.json", 'symlink_refused');
  @symlink('/etc/passwd', "$scratch/pw.json"); $rej('a symlink to /etc/passwd', "$scratch/pw.json", 'symlink_refused');
  $rej('wrong extension', wf('x.txt', json_encode(doc('ext'))), 'format_invalid', 2);
  $rej('outside --base-dir', $good, 'outside_base_dir', 1, array_merge($A, ['--base-dir=/usr']));
  $r = imp(array_merge(['--file=' . $good, '--base-dir=' . $scratch], $A)); ok($r['rc'] === 0, 'inside --base-dir the same file is accepted (dry run)');
  $rej('CSV without --kind', wf('nokind.csv', "entity\n"), 'csv_kind_required', 2);
  $rej('--kind with JSON', $good, 'kind_only_for_csv', 2, array_merge($A, ['--kind=events']));
  $rej('bad batch size', $good, 'batch_size_invalid', 2, array_merge($A, ['--batch-size=-3']));

  section('CSV input');
  $csvHead = 'slug,entity_type,name,source_url,source_publisher,source_retrieved_at,source_quote';
  $rejC = function (string $label, string $content, string $expect) use ($A, $adm, &$before) { $r = imp(array_merge(['--file=' . wf('t_' . md5($label) . '.csv', $content), '--kind=entities', '--apply'], $A)); ok($r['rc'] === 1 && str_contains($r['out'], $expect) && counts($adm) === $before, "CSV $label -> $expect (rc {$r['rc']}; " . substr(trim(str_replace("\n", ' | ', $r['out'])), 0, 120) . ')'); };
  $rejC('unterminated quote', "$csvHead\nc-one,company,\"Name,https://example.test/c,P,2025-01-01,q\n", 'csv_unterminated_quote');
  $rejC('unknown column', "$csvHead,state\nc-one,company,N,https://example.test/c,P,2025-01-01,q,verified\n", 'csv_unknown_column');
  $rejC('missing source column', "slug,entity_type,name\nc-one,company,N\n", 'csv_missing_source_column');
  $rejC('column count', "$csvHead\nc-one,company,N\n", 'csv_column_count');
  $rejC('javascript URL', "$csvHead\nc-one,company,N,javascript:alert(1),P,2025-01-01,q\n", 'url_invalid');
  $rejC('blank quote', "$csvHead\nc-one,company,N,https://example.test/c,P,2025-01-01,\n", 'missing');
  $rejC('formula-looking slug', "$csvHead\n=cmd|' /C calc'!A0,company,N,https://example.test/c,P,2025-01-01,q\n", 'slug_invalid');
  $rejC('duplicate header', "slug,slug,entity_type,name,source_url,source_publisher,source_retrieved_at,source_quote\nx,y,company,N,https://example.test/c,P,2025-01-01,q\n", 'csv_duplicate_column');
  $base = counts($adm);
  $r = imp(array_merge(['--file=' . $root . '/tests/fixtures/uni_import_synthetic.json', '--apply', '--json'], $A)); $j = rj($r);
  ok($r['rc'] === 0 && ($j['totals']['created'] ?? -1) === 6, 'the synthetic JSON fixture imports 6 records');
  $r = imp(array_merge(['--file=' . $root . '/tests/fixtures/uni_import_synthetic_events.csv', '--kind=events', '--apply', '--json'], $A)); $j = rj($r);
  ok($r['rc'] === 0 && ($j['totals']['created'] ?? -1) === 1 && (int)val($adm, "SELECT COUNT(*) FROM uni_source WHERE quote LIKE '%\"quoted\" and with a comma%'") === 1, 'the synthetic CSV fixture imports 1 event with its inline source (quotes and commas preserved)');
  $r = imp(array_merge(['--file=' . $root . '/tests/fixtures/uni_import_synthetic_events.csv', '--kind=events', '--apply', '--json'], $A)); $j = rj($r);
  ok($r['rc'] === 0 && ($j['totals']['created'] ?? -1) === 0, 'the CSV re-run is idempotent');
  ok(str_contains((string)file_get_contents($root . '/tests/fixtures/uni_import_synthetic.json'), 'SYNTHETIC TEST DATA ONLY'), 'the fixture is labelled synthetic');

  section('concurrency guard, output hygiene');
  $adm2 = new PDO($dsnBase . ";dbname=$db", $user, $pw, $opt); $adm2->query("SELECT GET_LOCK('aihub_uni_import', 0)")->fetchColumn();
  $before = counts($adm); $r = imp(array_merge(['--file=' . wj('lock.json', doc('lock')), '--apply'], $A));
  ok($r['rc'] === 2 && str_contains($r['out'], 'another_import_is_running') && counts($adm) === $before, 'a second importer refuses to run while one holds the lock');
  $adm2->query("SELECT RELEASE_LOCK('aihub_uni_import')")->fetchColumn(); unset($adm2);
  $r = imp(array_merge(['--file=' . $good], $A), ['AIHUB_DB_PASS' => 'S3cretPw-never-print']);
  ok($r['rc'] === 1 && !str_contains($r['out'] . $r['err'], 'S3cretPw-never-print') && !str_contains($r['out'] . $r['err'], $host) && str_contains($r['out'], 'database_error_'), 'a failed database login reports only a generic error: no password, no host');

  // ------------------------------------------------------------------------------------------------------------------------------- least privilege
  foreach ([false, true] as $triggers) {
    $mode = $triggers ? 'triggers' : 'no-triggers';
    section("[$mode] importer under a least-privilege database account");
    $reset($triggers);
    $admin->exec("DROP USER IF EXISTS '$lpUser'@'%'"); $admin->exec("CREATE USER '$lpUser'@'%' IDENTIFIED BY " . $admin->quote($LPPW));
    $q = fn(string $t) => "`$db`.`$t`";
    foreach (['uni_entity', 'uni_event', 'uni_edge'] as $t) {
      $s = $admin->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND EXTRA NOT LIKE '%GENERATED%' AND COLUMN_NAME NOT IN ('verified_by','verified_at','review_id') ORDER BY ORDINAL_POSITION");
      $s->execute([$db, $t]); $cols = $s->fetchAll(PDO::FETCH_COLUMN);
      $admin->exec('GRANT INSERT (' . implode(', ', array_map(fn($c) => "`$c`", $cols)) . ") ON {$q($t)} TO '$lpUser'@'%'");
      $admin->exec("GRANT SELECT ON {$q($t)} TO '$lpUser'@'%'");
    }
    foreach (['uni_source', 'uni_entity_source', 'uni_event_source', 'uni_edge_source', 'uni_audit_log'] as $t) $admin->exec("GRANT INSERT ON {$q($t)} TO '$lpUser'@'%'");
    $admin->exec("GRANT SELECT ON {$q('uni_audit_log')} TO '$lpUser'@'%'");
    $admin->exec("GRANT SELECT (`id`, `username`, `display_name`, `role`, `active`) ON {$q('crm_users')} TO '$lpUser'@'%'");
    $lpEnv = ['AIHUB_IMPORT_DB_USER' => $lpUser, 'AIHUB_IMPORT_DB_PASS' => $LPPW];
    $f = wj("lp_$mode.json", doc('lp')); $before = counts($adm);
    $r = imp(array_merge(['--file=' . $f, '--json'], $A), $lpEnv); $j = rj($r);
    ok($r['rc'] === 0 && ($j['totals']['would_create'] ?? -1) === 4 && counts($adm) === $before, "[$mode] dry run works under the least-privilege account and writes nothing");
    $r = imp(array_merge(['--file=' . $f, '--apply', '--json'], $A), $lpEnv); $j = rj($r);
    ok($r['rc'] === 0 && ($j['totals']['created'] ?? -1) === 4 && (int)val($adm, "SELECT COUNT(*) FROM uni_entity WHERE state = 'proposed' AND origin = 'import' AND verified_by IS NULL") === 2, "[$mode] apply works under the least-privilege account (rc {$r['rc']}: " . substr(trim(str_replace("\n", ' | ', $r['out'])), 0, 200) . ')');
    $before = counts($adm); $r = imp(array_merge(['--file=' . $f, '--apply', '--json'], $A), $lpEnv); $j = rj($r);
    ok($r['rc'] === 0 && ($j['totals']['skipped_already_imported'] ?? -1) === 4 && counts($adm) === $before, "[$mode] the re-run under that account is idempotent");
    $dd = doc('lp'); $dd['entities'][0]['slug'] = 'imp-co-lp'; $dd['events'][0]['event_date'] = '2024-05-06';
    $lp = new PDO($dsnBase . ";dbname=$db", $lpUser, $LPPW, $opt);
    ok(errno($lp, "UPDATE uni_entity SET name = 'x'") !== null, "[$mode] the account cannot UPDATE");
    ok(errno($lp, 'DELETE FROM uni_entity') !== null && errno($lp, 'TRUNCATE TABLE uni_audit_log') !== null, "[$mode] the account cannot DELETE or TRUNCATE");
    ok(errno($lp, 'SELECT password_hash FROM crm_users') !== null, "[$mode] the account cannot read password hashes");
    ok(errno($lp, 'SELECT COUNT(*) FROM uni_review') !== null && errno($lp, "INSERT INTO uni_review (target_type, target_id, reviewer_id, proposed_by, decision, decided_at) VALUES ('entity', 1, $ver, $ed, 'accept', NOW())") !== null, "[$mode] the account can neither read nor write reviews");
    $e = errno($lp, "INSERT INTO uni_entity (entity_type, slug, name, summary, state, origin, proposed_by, proposed_at, verified_by, lineage_key) VALUES ('company', 'lp-raw1', 'x', '', 'verified', 'human', ?, NOW(), ?, ?)", [$ed, $ver, bin2hex(random_bytes(16))]);
    ok($e === 1143 || $e === 1142, "[$mode] the account cannot even name the verified_by column (error " . var_export($e, true) . ')');
    $e = errno($lp, "INSERT INTO uni_entity (entity_type, slug, name, summary, state, origin, proposed_by, proposed_at, lineage_key) VALUES ('company', 'lp-raw2', 'x', '', 'verified', 'human', ?, NOW(), ?)", [$ed, bin2hex(random_bytes(16))]);
    ok($e !== null && (int)val($adm, "SELECT COUNT(*) FROM uni_entity WHERE slug = 'lp-raw2'") === 0, "[$mode] a verified row without verified_by / verified_at / review_id is refused by the CHECK constraint (error " . var_export($e, true) . ')');
    $e = errno($lp, "INSERT INTO uni_entity (entity_type, slug, name, summary, state, origin, proposed_by, proposed_at, lineage_key) VALUES ('company', 'lp-raw3', 'x', '', 'proposed', 'human', ?, NOW(), ?)", [$ed, bin2hex(random_bytes(16))]);
    ok($e === null, "[$mode] documented residual: the account CAN insert a proposed row with origin human (a column grant cannot restrict values; the importer code always writes import)");
    $e = errno($lp, "INSERT INTO uni_entity (entity_type, slug, name, summary, state, origin, proposed_by, proposed_at, lineage_key) VALUES ('company', 'lp-raw4', 'x', '', 'verified', 'import', ?, NOW(), ?)", [$ed, bin2hex(random_bytes(16))]);
    ok($e !== null, "[$mode] and a verified import row is refused");
    $tid = (int)val($adm, "SELECT id FROM uni_event LIMIT 1"); $oldLog = ini_set('error_log', '/dev/null'); $ra = uni_attach_source($lp, $ed, 'event', $tid, ['url' => 'https://example.test/x', 'publisher' => 'P', 'retrieved_at' => '2025-01-01', 'quote' => 'q']); ini_set('error_log', (string)$oldLog);
    ok($ra[0] === false, "[$mode] limits: uni_attach_source (SELECT ... FOR UPDATE) and uni_submit_for_review need UPDATE rights and fail for this INSERT-only account (" . json_encode($ra) . '); the importer avoids them');
    $admin->exec("DROP USER IF EXISTS '$lpUser'@'%'");
  }
} catch (Throwable $e) {
  $fail++; fwrite(STDERR, 'FATAL: ' . get_class($e) . ': ' . $e->getMessage() . ' at line ' . $e->getLine() . "\n");
} finally {
  try { $admin->exec("DROP USER IF EXISTS '$lpUser'@'%'"); $admin->exec("DROP DATABASE IF EXISTS `$db`"); } catch (Throwable $e) { fwrite(STDERR, 'cleanup: ' . $e->getMessage() . "\n"); }
  if (is_dir($scratch)) { foreach (glob("$scratch/*") ?: [] as $x) { if (is_dir($x) && !is_link($x)) @rmdir($x); else @unlink($x); } @rmdir($scratch); }
}
function imp_clean_t(string $s): string { return preg_replace('/[^\x20-\x7E]/', '?', $s) ?? ''; }
ob_end_clean();
fwrite(STDERR, "\nImporter tests: $pass passed, $fail failed\n");
echo "Importer tests: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
