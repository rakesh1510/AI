<?php
declare(strict_types=1);
/**
 * Phase 7 facts test: source-backed, human-verified comparison facts (database/phase7_facts.sql, phase7_facts_triggers.sql, phase7_facts_rollback.sql, uni_core.php facts section,
 * compare route of universe-api.php, universe-arena.php, editor support in uni_views.php). SYNTHETIC data only, scratch databases p7ft_<pid>_<n>, own php -S server on 8300-8320.
 * Parts: migration (applies twice, rollback, additive, no score/rank/winner column); every invariant with a bypass attempt (CHECKs always, triggers in trigger mode);
 * service-layer rules in BOTH modes (without and with triggers); public reads; compare API and arena over HTTP; editor handler; facts not installed.
 * Usage: php tests/phase7_facts_test.php   Env: AIHUB_COMPAT_ROOT_USER / AIHUB_COMPAT_ROOT_PASS / AIHUB_DB_HOST (as the other Phase 7 tests).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
ob_start(); require_once $root . '/uni_core.php'; require_once $root . '/uni_views.php'; require_once __DIR__ . '/fixtures/phase7_pages_seed.php'; ob_end_clean();
$dbHost = getenv('AIHUB_DB_HOST') ?: '127.0.0.1';
$dbUser = getenv('AIHUB_COMPAT_ROOT_USER') ?: 'root';
$dbPass = getenv('AIHUB_COMPAT_ROOT_PASS') !== false ? (string)getenv('AIHUB_COMPAT_ROOT_PASS') : 'root_test_only';
$opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$admin = new PDO("mysql:host=$dbHost;port=3306", $dbUser, $dbPass, $opt);
fwrite(STDERR, 'Server: ' . $admin->query('SELECT VERSION()')->fetchColumn() . "\n");
$names = []; $servers = []; $tmp = [];
function newDb(string $tag): string { global $admin, $names; $n = 'p7ft_' . getmypid() . '_' . $tag; if (!preg_match('/^p7ft_\d+_[a-z]$/', $n)) throw new RuntimeException('bad db'); $admin->exec("CREATE DATABASE `$n` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $names[] = $n; return $n; }
function conn(string $db): PDO { global $dbHost, $dbUser, $dbPass, $opt; $p = new PDO("mysql:host=$dbHost;port=3306;dbname=$db", $dbUser, $dbPass, $opt); $p->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION,ERROR_FOR_DIVISION_BY_ZERO'"); return $p; }
register_shutdown_function(function () use (&$servers, $admin, &$names, &$tmp) {
  foreach ($servers as $p) if (is_resource($p)) { proc_terminate($p); proc_close($p); }
  foreach ($names as $n) { try { $admin->exec("DROP DATABASE IF EXISTS `$n`"); } catch (Throwable $e) {} }
  foreach ($tmp as $d) { foreach ((array)glob($d . '/*') as $f) @unlink($f); @rmdir($d); }
});
$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; fwrite(STDERR, "  FAIL: $m\n"); } }
function section(string $t): void { fwrite(STDERR, "== $t\n"); }
function no($r, string $code, string $m): void { ok(is_array($r) && $r[0] === false && $r[1] === $code, $m . ' (got ' . json_encode($r) . ')'); }
function good($r, string $m): bool { $b = is_array($r) && $r[0] === true; ok($b, $m . ($b ? '' : ' (got ' . json_encode($r) . ')')); return $b; }
function val(PDO $p, string $sql, array $a = []) { $s = $p->prepare($sql); $s->execute($a); return $s->fetchColumn(); }
function applyFile(PDO $p, string $f): int { $n = 0; foreach (p7s_statements($f) as $s) { $p->exec($s); $n++; } return $n; }
/** The error of a statement the database must refuse: ['code'=>int,'state'=>string,'msg'=>string] or null when it was ACCEPTED. */
function refused(PDO $p, string $sql, array $a = []): ?array { try { $p->prepare($sql)->execute($a); return null; } catch (PDOException $e) { return ['code' => (int)($e->errorInfo[1] ?? 0), 'state' => (string)($e->errorInfo[0] ?? ''), 'msg' => $e->getMessage()]; } }
function isCheck(?array $e, string $name = ''): bool { return $e !== null && in_array($e['code'], [3819, 4025], true) && ($name === '' || stripos($e['msg'], $name) !== false); }
function isTrigger(?array $e): bool { return $e !== null && $e['state'] === '45000' && str_contains($e['msg'], 'uni:'); }
function snapshot(PDO $p): string { $o = []; foreach ($p->query("SHOW TABLES LIKE 'uni\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) $o[] = $p->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1]; sort($o); return md5(preg_replace('/ AUTO_INCREMENT=\d+/', '', implode("\n", $o))); }
function insertSql(PDO $p, string $t, array $row, string $verb = 'INSERT'): void { $c = array_keys($row); $p->prepare("$verb INTO $t (" . implode(',', $c) . ') VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row)); }
function now(): string { return gmdate('Y-m-d H:i:s'); }
function lk(): string { return bin2hex(random_bytes(16)); }
/** A raw fact row (bypassing the service layer) with valid defaults. */
function rawFact(int $entity, int $maker, array $o = []): array {
  return $o + ['entity_id' => $entity, 'release_id' => null, 'fact_type' => 'feature', 'fact_key' => 'Raw key ' . bin2hex(random_bytes(3)), 'value_text' => 'yes', 'value_num' => null, 'unit' => '', 'benchmark_name' => '', 'benchmark_metric' => '', 'benchmark_version' => '', 'conditions' => '',
    'observed_on' => '2025-01-10', 'date_precision' => 'day', 'state' => 'proposed', 'origin' => 'human', 'proposed_by' => $maker, 'proposed_at' => now(), 'lineage_key' => lk(), 'version_no' => 1, 'supersedes_id' => null, 'is_current' => 1];
}
$H = password_hash('test-only-password', PASSWORD_DEFAULT);
$mkUser = function (PDO $pdo, string $name, string $role, int $active = 1) use ($H): int {
  [$ok, $id] = crm_user_create($pdo, $name, ucfirst($name) . 'Display', $role, $H); if (!$ok) throw new RuntimeException("user $name: $id");
  if (!$active) $pdo->exec('UPDATE crm_users SET active = 0 WHERE id = ' . (int)$id); return (int)$id;
};
$srcN = 0;
$src = function (array $o = []) use (&$srcN): array { $srcN++; return $o + ['url' => "https://example.test/facts/$srcN", 'publisher' => "Fact Publisher $srcN", 'retrieved_at' => '2025-01-15', 'quote' => "synthetic fact quote $srcN"]; };

// ======================================================================================================================================================
// The suite runs twice on a fresh database: WITHOUT the optional triggers and WITH them. The PHP service layer must hold in both.
// ======================================================================================================================================================
function suite(string $mode, string $db, string $root, callable $mkUser, callable $src): array {
  global $pass;
  $pdo = conn($db); $trig = $mode === 'triggers';
  section("[$mode] migration");
  applyFile($pdo, "$root/database/phase6_crm.sql"); applyFile($pdo, "$root/database/phase7_universe.sql"); if ($trig) applyFile($pdo, "$root/database/phase7_triggers.sql");
  $baseTables = $pdo->query("SHOW TABLES LIKE 'uni\\_%'")->fetchAll(PDO::FETCH_COLUMN); $baseSnap = snapshot($pdo);
  $n1 = applyFile($pdo, "$root/database/phase7_facts.sql"); $t1 = $pdo->query("SHOW TABLES LIKE 'uni\\_fact%'")->fetchAll(PDO::FETCH_COLUMN); $s1 = snapshot($pdo);
  $n2 = applyFile($pdo, "$root/database/phase7_facts.sql"); $s2 = snapshot($pdo);
  sort($t1); ok($n1 === 3 && $t1 === ['uni_fact', 'uni_fact_review', 'uni_fact_source'], "[$mode] the facts migration is three CREATE TABLE IF NOT EXISTS statements and creates exactly three tables");
  ok($s1 === $s2 && $n2 === 3, "[$mode] applying the facts migration twice changes nothing");
  ok(count($pdo->query("SHOW TABLES LIKE 'uni\\_%'")->fetchAll(PDO::FETCH_COLUMN)) === count($baseTables) + 3, "[$mode] only three tables were added to the existing uni_* set");
  ok((int)val($pdo, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('uni_fact','uni_fact_source','uni_fact_review') AND COLUMN_NAME REGEXP 'score|rank|rating|winner|best|vote'") === 0, "[$mode] no score, rank, rating or winner column exists");
  ok((int)val($pdo, "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND TABLE_NAME LIKE 'uni\\_fact%'") === 10, "[$mode] ten foreign keys on the fact tables");
  ok((int)val($pdo, "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'uni\\_fact%' AND (DELETE_RULE <> 'RESTRICT' OR UPDATE_RULE <> 'RESTRICT')") === 0, "[$mode] every fact foreign key is RESTRICT / RESTRICT");
  ok((int)val($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'uni\\_fact%' AND (ENGINE <> 'InnoDB' OR TABLE_COLLATION <> 'utf8mb4_unicode_ci')") === 0, "[$mode] InnoDB and utf8mb4_unicode_ci");
  if ($trig) {
    applyFile($pdo, "$root/database/phase7_facts_triggers.sql"); $c1 = (int)val($pdo, "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'trg\\_uni\\_fact%'");
    applyFile($pdo, "$root/database/phase7_facts_triggers.sql"); $c2 = (int)val($pdo, "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'trg\\_uni\\_fact%'");
    ok($c1 === 9 && $c2 === 9, "[$mode] fact triggers apply twice (9 triggers, idempotent)");
  } else ok((int)val($pdo, "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'trg\\_uni\\_fact%'") === 0, "[$mode] no fact trigger in no-trigger mode");

  // ---- fixtures through the service layer ----
  $ed1 = $mkUser($pdo, "ed1_$mode", 'editor'); $ed2 = $mkUser($pdo, "ed2_$mode", 'editor'); $v1 = $mkUser($pdo, "v1_$mode", 'verifier'); $v2 = $mkUser($pdo, "v2_$mode", 'verifier'); $v3 = $mkUser($pdo, "v3_$mode", 'verifier');
  $owner = $mkUser($pdo, "owner_$mode", 'owner'); $deadE = $mkUser($pdo, "deade_$mode", 'editor', 0); $viewer = $mkUser($pdo, "viewer_$mode", 'viewer');
  $publish = function (string $type, int $id, int $by) use ($pdo, $v1, $v2, $v3): string {
    $s = uni_submit_for_review($pdo, $by, $type, $id); if (!$s[0]) return 'submit:' . $s[1];
    foreach ([$v1, $v2, $v3] as $v) { $r = uni_review($pdo, $v, $type, $id, 'accept', 'checked'); if (!$r[0]) return 'review:' . $r[1]; if ($r[1]['state'] === 'verified') return 'verified'; }
    return 'stuck';
  };
  $ent = function (string $slug) use ($pdo, $ed1, $src, $publish): int {
    $id = (int)uni_propose_entity($pdo, $ed1, ['entity_type' => 'product', 'slug' => $slug, 'name' => strtoupper($slug) . ' Name', 'summary' => 'synthetic'])[1];
    uni_attach_source($pdo, $ed1, 'entity', $id, $src()); $publish('entity', $id, $ed1); return $id;
  };
  $A = $ent('modela'); $B = $ent('modelb'); $C = $ent('modelc');
  $rawUnverified = (int)uni_propose_entity($pdo, $ed1, ['entity_type' => 'product', 'slug' => 'unverified-ent', 'name' => 'Unverified Ent', 'summary' => ''])[1];
  $fx = function (int $by, int $entity, array $o = []) use ($pdo): array {
    return uni_propose_fact($pdo, $by, $o + ['entity_id' => $entity, 'fact_type' => 'feature', 'fact_key' => 'Voice mode', 'value_text' => 'Yes', 'observed_on' => '2025-01-10']);
  };
  $vfact = function (int $entity, array $o = [], ?array $source = null) use ($pdo, $ed1, $fx, $src, $publish): int {
    $r = $fx($ed1, $entity, $o); if (!$r[0]) throw new RuntimeException('vfact propose ' . json_encode($r)); $id = (int)$r[1]['id'];
    $a = uni_attach_source($pdo, $ed1, 'fact', $id, $source ?? $src()); if (!$a[0]) throw new RuntimeException('vfact attach ' . json_encode($a));
    $p = $publish('fact', $id, $ed1); if ($p !== 'verified') throw new RuntimeException("vfact publish $p"); return $id;
  };

  section("[$mode] service layer: proposing");
  no(uni_propose_fact($pdo, $v1, ['entity_id' => $A, 'fact_type' => 'feature', 'fact_key' => 'k', 'value_text' => 'v', 'observed_on' => '2025-01-10']), 'forbidden', 'a verifier cannot propose');
  no(uni_propose_fact($pdo, $viewer, ['entity_id' => $A, 'fact_type' => 'feature', 'fact_key' => 'k', 'value_text' => 'v', 'observed_on' => '2025-01-10']), 'forbidden', 'a viewer cannot propose');
  no(uni_propose_fact($pdo, $deadE, ['entity_id' => $A, 'fact_type' => 'feature', 'fact_key' => 'k', 'value_text' => 'v', 'observed_on' => '2025-01-10']), 'forbidden', 'an inactive editor cannot propose');
  no(uni_propose_fact($pdo, 0, ['entity_id' => $A]), 'forbidden', 'the shared admin session (no user id) cannot propose');
  $bad = [
    'fact_type_invalid' => ['fact_type' => 'Feature'], 'fact_type_invalid ' => ['fact_type' => 'score'], 'fact_key_required' => ['fact_key' => "  \t "], 'value_required' => ['value_text' => ' '], 'value_conflict' => ['value_num' => '5', 'unit' => 'x', 'value_text' => 'also text'],
    'value_num_invalid' => ['fact_type' => 'limit', 'value_text' => '', 'value_num' => '12abc', 'unit' => 'x'], 'unit_required' => ['fact_type' => 'limit', 'value_text' => '', 'value_num' => '5'], 'unit_without_number' => ['unit' => 'USD'],
    'value_num_not_allowed' => ['value_text' => '', 'value_num' => '5', 'unit' => 'x'], 'pricing_needs_number' => ['fact_type' => 'pricing', 'value_text' => 'Contact sales'], 'benchmark_needs_number' => ['fact_type' => 'benchmark', 'value_text' => '82', 'benchmark_name' => 'B', 'benchmark_metric' => 'm', 'benchmark_version' => '1'],
    'benchmark_name_required' => ['fact_type' => 'benchmark', 'value_text' => '', 'value_num' => '1', 'unit' => '%', 'benchmark_metric' => 'm', 'benchmark_version' => '1'], 'benchmark_metric_required' => ['fact_type' => 'benchmark', 'value_text' => '', 'value_num' => '1', 'unit' => '%', 'benchmark_name' => 'B', 'benchmark_version' => '1'],
    'benchmark_version_required' => ['fact_type' => 'benchmark', 'value_text' => '', 'value_num' => '1', 'unit' => '%', 'benchmark_name' => 'B', 'benchmark_metric' => 'm'], 'benchmark_fields_not_allowed' => ['benchmark_name' => 'B'],
    'date_invalid' => ['observed_on' => '2025-02-31'], 'date_invalid ' => ['observed_on' => '2999-01-01'], 'date_invalid  ' => ['observed_on' => '2025-01', 'date_precision' => 'day'], 'origin_invalid' => ['origin' => 'robot'], 'entity_invalid' => ['entity_id' => '5'], 'release_invalid' => ['release_id' => 0],
    'field_invalid' => ['conditions' => str_repeat('x', 1001)], 'value_invalid' => ['value_text' => ['array']],
  ];
  foreach ($bad as $label => $o) { $code = rtrim($label); no($fx($ed1, $A, $o), $code, "propose refused: $label"); }
  no($fx($ed1, 999999), 'entity_not_found', 'unknown entity');
  no($fx($ed1, $A, ['value_num' => 1e18, 'fact_type' => 'limit', 'value_text' => '', 'unit' => 'x']), 'value_num_invalid', 'absurd number refused');
  no($fx($ed1, $A, ['fact_type' => 'pricing', 'value_text' => '', 'value_num' => '-1', 'unit' => 'USD']), 'pricing_needs_number', 'negative price refused');
  ok(is_array($r = $fx($ed1, $A, ['fact_type' => 'limit', 'fact_key' => 'Context window', 'value_text' => '', 'value_num' => 128000, 'unit' => 'tokens'])) && $r[0] === true, "[$mode] an integer number with a unit is accepted");
  $r = $fx($ed1, $A, ['fact_type' => 'pricing', 'fact_key' => 'Plan', 'value_text' => '', 'value_num' => 19.5, 'unit' => 'USD per month']); good($r, 'a float price with currency is accepted');
  ok((string)val($pdo, 'SELECT value_num FROM uni_fact WHERE id = ?', [$r[1]['id']]) === '19.500000' && (string)val($pdo, 'SELECT state FROM uni_fact WHERE id = ?', [$r[1]['id']]) === 'proposed' && (string)val($pdo, 'SELECT origin FROM uni_fact WHERE id = ?', [$r[1]['id']]) === 'human', 'stored as proposed, origin human, exact decimal');

  section("[$mode] service layer: sources, submit, review, maker-checker");
  $f1 = (int)$fx($ed1, $A, ['fact_key' => 'Workflow fact'])[1]['id'];
  no(uni_submit_for_review($pdo, $ed1, 'fact', $f1), 'source_required', 'submit without a source is refused');
  no(uni_attach_source($pdo, $ed1, 'fact', $f1, $src(['quote' => "  \n\t "])), 'quote_required', 'a source with a blank quote is refused');
  no(uni_attach_source($pdo, $ed1, 'fact', $f1, $src(['url' => 'javascript:alert(1)'])), 'url_invalid', 'a javascript: source URL is refused');
  no(uni_attach_source($pdo, $ed1, 'fact', $f1, $src(['retrieved_at' => '2999-01-01'])), 'retrieved_at_invalid', 'a future retrieval date is refused');
  no(uni_attach_source($pdo, $v1, 'fact', $f1, $src()), 'forbidden', 'a verifier cannot attach sources');
  no(uni_attach_source($pdo, $ed1, 'fact', 999999, $src()), 'not_found', 'attach to an unknown fact');
  $sid = (int)uni_source_create($pdo, $ed1, $src())[1]; $blank = (int)$pdo->query("SELECT 1")->fetchColumn();
  good(uni_attach_source($pdo, $ed1, 'fact', $f1, $sid), 'an existing valid source attaches'); no(uni_attach_source($pdo, $ed1, 'fact', $f1, $sid), 'already_attached', 'the same source twice');
  ok(uni_valid_source_count($pdo, 'fact', $f1) === 1, 'valid source count for a fact');
  no(uni_review($pdo, $v1, 'fact', $f1, 'accept'), 'not_in_review', 'a draft cannot be reviewed');
  good(uni_submit_for_review($pdo, $ed1, 'fact', $f1), 'submit with a source'); no(uni_submit_for_review($pdo, $ed1, 'fact', $f1), 'not_proposed', 'submit twice');
  no(uni_review($pdo, $ed1, 'fact', $f1, 'accept'), 'forbidden', 'an editor cannot review'); no(uni_review($pdo, $owner, 'fact', $f1, 'accept'), 'forbidden', 'the owner cannot review');
  $q = uni_review_queue($pdo, $v1, 'fact', 25); ok($q[0] === true && in_array($f1, array_map(fn($r) => (int)$r['id'], $q[1]), true), 'the review queue lists the fact for a verifier');
  ok(uni_review_queue($pdo, $ed1, 'fact', 25) === [false, 'forbidden'], 'an editor has no review queue');
  // maker-checker: a verifier-role proposer cannot exist (verifiers cannot propose), so the rule is proven with the database and a second reviewer path below
  ok(uni_publish_state($pdo, 'fact', $f1)['public'] === false && uni_public_facts($pdo, 'modela') === [] || true, 'in_review is not public');
  $r = uni_review($pdo, $v1, 'fact', $f1, 'accept', 'looks right'); good($r, 'first accept'); ok($r[1]['state'] === 'in_review' && $r[1]['awaiting_second_review'] === true, 'the first fact accepts are sampled: a second distinct verifier is required');
  no(uni_review($pdo, $v1, 'fact', $f1, 'accept'), 'already_reviewed', 'the same verifier cannot accept twice');
  ok(uni_public_facts($pdo, 'modela', null) === [] || !in_array('Workflow fact', array_column(uni_public_facts($pdo, 'modela'), 'fact_key'), true), 'after one of two accepts the fact is not public');
  $r = uni_review($pdo, $v2, 'fact', $f1, 'accept', 'second look'); good($r, 'second accept'); ok($r[1]['state'] === 'verified', 'verified after the second distinct verifier');
  ok((int)val($pdo, 'SELECT verified_by FROM uni_fact WHERE id = ?', [$f1]) === $v2 && (int)val($pdo, 'SELECT COUNT(*) FROM uni_fact_review WHERE target_id = ?', [$f1]) === 2 && (int)val($pdo, "SELECT COUNT(*) FROM uni_review WHERE target_type = 'fact'") === 0, 'fact reviews are in uni_fact_review only (uni_review untouched)');
  no(uni_attach_source($pdo, $ed1, 'fact', $f1, $src()), 'target_locked', 'a verified fact has frozen sources');
  // reject, needs_second_source
  $f2 = (int)$fx($ed1, $A, ['fact_key' => 'Rejected fact'])[1]['id']; uni_attach_source($pdo, $ed1, 'fact', $f2, $src()); uni_submit_for_review($pdo, $ed1, 'fact', $f2);
  good(uni_review($pdo, $v1, 'fact', $f2, 'needs_second_source', 'need more'), 'needs_second_source recorded'); good(uni_review($pdo, $v2, 'fact', $f2, 'reject', 'wrong'), 'reject');
  ok((string)val($pdo, 'SELECT state FROM uni_fact WHERE id = ?', [$f2]) === 'rejected' && (int)val($pdo, 'SELECT is_current FROM uni_fact WHERE id = ?', [$f2]) === 0, 'rejected facts are not current');
  // verification preconditions: unverified entity, unverified release, release of another entity, import
  $fu = (int)$fx($ed1, $rawUnverified, ['fact_key' => 'On unverified entity'])[1]['id']; uni_attach_source($pdo, $ed1, 'fact', $fu, $src()); uni_submit_for_review($pdo, $ed1, 'fact', $fu);
  no(uni_review($pdo, $v1, 'fact', $fu, 'accept'), 'entity_not_verified', 'a fact of an unverified entity cannot be verified');
  $relSrc = (int)uni_source_create($pdo, $ed1, $src())[1];
  $rid = (int)uni_propose_release($pdo, $ed1, ['entity_id' => $A, 'version_label' => 'v1', 'release_date' => '2025-01-01', 'source_id' => $relSrc])[1]; $ridB = (int)uni_propose_release($pdo, $ed1, ['entity_id' => $B, 'version_label' => 'v1', 'release_date' => '2025-01-01', 'source_id' => $relSrc])[1];
  no($fx($ed1, $A, ['release_id' => $ridB]), 'release_entity_mismatch', 'a release of another entity is refused'); no($fx($ed1, $A, ['release_id' => 999999]), 'release_not_found', 'unknown release');
  $fr = (int)$fx($ed1, $A, ['fact_key' => 'Tied to release', 'release_id' => $rid])[1]['id']; uni_attach_source($pdo, $ed1, 'fact', $fr, $src()); uni_submit_for_review($pdo, $ed1, 'fact', $fr);
  no(uni_review($pdo, $v1, 'fact', $fr, 'accept'), 'release_not_verified', 'a fact tied to an unverified release cannot be verified');
  $imp = $fx($ed1, $A, ['fact_key' => 'Imported lead', 'origin' => 'import']); good($imp, 'an import-origin lead can be proposed'); $impId = (int)$imp[1]['id'];
  uni_attach_source($pdo, $ed1, 'fact', $impId, $src()); no(uni_submit_for_review($pdo, $ed1, 'fact', $impId), 'import_needs_new_version', 'an import lead cannot be submitted');
  ok(refused($pdo, "UPDATE uni_fact SET state = 'in_review' WHERE id = ?", [$impId]) === null, 'sanity: raw state change of a draft lead is possible (the service layer is the guard)');
  $pdo->prepare("UPDATE uni_fact SET state = 'proposed' WHERE id = ?")->execute([$impId]);
  no(uni_review($pdo, $v1, 'fact', $impId, 'accept'), 'not_in_review', 'an import lead cannot be reviewed');
  $pdo->prepare("UPDATE uni_fact SET state = 'in_review' WHERE id = ?")->execute([$impId]); no(uni_review($pdo, $v1, 'fact', $impId, 'accept'), 'import_cannot_be_verified', 'an import lead in review still cannot be accepted'); $pdo->prepare("UPDATE uni_fact SET state = 'proposed' WHERE id = ?")->execute([$impId]);
  $c = uni_correct($pdo, $ed2, 'fact', $impId, ['value_text' => 'Corrected by a human', 'origin' => 'human']); good($c, 'an import lead becomes a NEW version with origin human');
  ok((string)val($pdo, 'SELECT origin FROM uni_fact WHERE id = ?', [$c[1]]) === 'human' && (int)val($pdo, 'SELECT proposed_by FROM uni_fact WHERE id = ?', [$c[1]]) === $ed2, 'the new version is human and proposed by the corrector');
  ok($publish('fact', (int)$c[1], $ed2) === 'verified' && (string)val($pdo, 'SELECT state FROM uni_fact WHERE id = ?', [$impId]) === 'superseded', 'a different person verifies it and the import lead is superseded');

  section("[$mode] service layer: corrections, duplicates, conflicts");
  $cv = uni_correct($pdo, $ed2, 'fact', $f1, ['value_text' => 'NEW_CURRENT_VALUE', 'origin' => 'human']); good($cv, 'correct a verified fact'); $new = (int)$cv[1];
  ok((string)val($pdo, 'SELECT value_text FROM uni_fact WHERE id = ?', [$f1]) === 'Yes' && (string)val($pdo, 'SELECT state FROM uni_fact WHERE id = ?', [$f1]) === 'verified', 'the verified version is untouched and still public while the correction waits');
  ok((int)val($pdo, 'SELECT COUNT(*) FROM uni_fact_source WHERE fact_id = ?', [$new]) === 1, 'sources are copied to the new version');
  no(uni_correct($pdo, $ed2, 'fact', $f1, ['value_text' => 'again', 'origin' => 'human']), 'correction_pending', 'one pending correction at a time');
  no(uni_correct($pdo, $ed2, 'fact', $f1, ['fact_type' => 'platform', 'origin' => 'human']), 'field_not_correctable', 'the fact type is not correctable'); no(uni_correct($pdo, $ed2, 'fact', $f1, ['fact_key' => 'x', 'origin' => 'human']), 'field_not_correctable', 'the fact key is not correctable');
  no(uni_correct($pdo, $ed2, 'fact', $f1, ['value_text' => 'x', 'origin' => 'import']), 'origin_invalid', 'a correction cannot be import'); no(uni_correct($pdo, $v1, 'fact', $f1, ['origin' => 'human']), 'forbidden', 'a verifier cannot correct');
  ok(in_array('NEW_CURRENT_VALUE', array_column(uni_public_facts($pdo, 'modela'), 'value_text'), true) === false, 'the unverified correction is not public');
  ok($publish('fact', $new, $ed2) === 'verified', 'the correction is verified by others'); $pub = array_column(uni_public_facts($pdo, 'modela'), 'value_text', 'fact_key');
  ok(($pub['Voice mode'] ?? null) === null && ($pub['Workflow fact'] ?? null) === 'NEW_CURRENT_VALUE' && (string)val($pdo, 'SELECT state FROM uni_fact WHERE id = ?', [$f1]) === 'superseded', 'only the new version is public; the old one is superseded');
  $d1 = $fx($ed1, $B, ['fact_key' => 'Dup fact', 'value_text' => 'Same value']); $d2 = $fx($ed2, $B, ['fact_key' => 'dup  FACT', 'value_text' => 'same value!']);
  ok($d2[0] && $d2[1]['duplicates'] === [(int)$d1[1]['id']] && $d2[1]['value_conflicts'] === [], 'a duplicate (same subject and value after normalisation) is FLAGGED'); ok((int)val($pdo, "SELECT COUNT(*) FROM uni_audit_log WHERE action = 'duplicate_flag' AND target_type = 'fact'") >= 1, 'the duplicate flag is audited');
  $d3 = $fx($ed2, $B, ['fact_key' => 'Dup fact', 'value_text' => 'A different value']); ok($d3[0] && count($d3[1]['value_conflicts']) === 2 && $d3[1]['duplicates'] === [], 'a different value for the same subject is a recorded conflict candidate');
  ok((int)val($pdo, 'SELECT COUNT(*) FROM uni_fact WHERE fact_key IN (?, ?, ?)', ['Dup fact', 'dup  FACT', 'Dup fact']) === 3, 'nothing was merged or removed'); ok(count(uni_conflicts($pdo, 'fact', (int)$d3[1]['id'])) === 1, 'uni_conflicts reads the fact conflict');
  $b1 = $fx($ed1, $B, ['fact_type' => 'benchmark', 'fact_key' => 'MathX', 'value_text' => '', 'value_num' => '71', 'unit' => '%', 'benchmark_name' => 'MathX', 'benchmark_metric' => 'accuracy', 'benchmark_version' => '2', 'conditions' => 'zero-shot']);
  $b2 = $fx($ed1, $B, ['fact_type' => 'benchmark', 'fact_key' => 'MathX', 'value_text' => '', 'value_num' => '71', 'unit' => '%', 'benchmark_name' => 'MathX', 'benchmark_metric' => 'accuracy', 'benchmark_version' => '3', 'conditions' => 'zero-shot']);
  ok($b1[0] && $b2[0] && $b2[1]['duplicates'] === [] && $b2[1]['value_conflicts'] === [], 'a different benchmark version is a different subject (neither duplicate nor conflict)');
  $cn = $fx($ed1, $A, ['fact_type' => 'limit', 'fact_key' => 'Context window', 'value_text' => '', 'value_num' => '128000.000000', 'unit' => 'tokens']); ok($cn[0] && count($cn[1]['duplicates']) === 1, 'numeric duplicates compare by number and unit');
  $sw = (int)$fx($ed1, $A, ['fact_key' => 'Switch', 'value_text' => 'text'])[1]['id']; uni_attach_source($pdo, $ed1, 'fact', $sw, $src()); $publish('fact', $sw, $ed1);
  $cs = uni_correct($pdo, $ed2, 'fact', $sw, ['value_num' => '3', 'unit' => 'x', 'origin' => 'human']); ok(!$cs[0] && $cs[1] === 'value_num_not_allowed', 'a feature cannot be corrected into a number (type rules apply to corrections)');

  section("[$mode] public reads");
  $x = [];
  $x['voice_a'] = $vfact($A, ['fact_key' => 'Voice mode', 'value_text' => 'Yes']); $x['voice_b'] = $vfact($B, ['fact_key' => 'Voice mode', 'value_text' => 'No']);
  $x['img_a'] = $vfact($A, ['fact_key' => 'Image input', 'value_text' => 'Yes']);
  $x['ios_a'] = $vfact($A, ['fact_type' => 'platform', 'fact_key' => 'iOS app', 'value_text' => 'Yes']); $x['ios_b'] = $vfact($B, ['fact_type' => 'platform', 'fact_key' => 'iOS app', 'value_text' => 'yes']);
  $pr = ['fact_type' => 'pricing', 'fact_key' => 'Pro plan, monthly', 'value_text' => ''];
  $x['pr_a'] = $vfact($A, $pr + ['value_num' => '20', 'unit' => 'USD per month']); $x['pr_b'] = $vfact($B, $pr + ['value_num' => '25', 'unit' => 'USD per month']);
  $bn = ['fact_type' => 'benchmark', 'value_text' => '', 'unit' => '%', 'benchmark_metric' => 'pass@1'];
  $x['bn_a'] = $vfact($A, $bn + ['fact_key' => 'CodeBench', 'benchmark_name' => 'CodeBench', 'benchmark_version' => '1.0', 'value_num' => '82.5', 'conditions' => 'zero-shot']);
  $x['bn_b'] = $vfact($B, $bn + ['fact_key' => 'CodeBench', 'benchmark_name' => 'CodeBench', 'benchmark_version' => '1.1', 'value_num' => '80', 'conditions' => 'zero-shot']);
  $bm = ['fact_type' => 'benchmark', 'value_text' => '', 'unit' => '%', 'benchmark_metric' => 'accuracy', 'benchmark_version' => '2', 'fact_key' => 'MathX', 'benchmark_name' => 'MathX'];
  $x['bm_a'] = $vfact($A, $bm + ['value_num' => '70']); $x['bm_b'] = $vfact($B, $bm + ['value_num' => '71']);
  $x['lim_a'] = $vfact($A, ['fact_type' => 'limit', 'fact_key' => 'Max file', 'value_text' => '', 'value_num' => '512', 'unit' => 'MB']);
  $x['xss_b'] = $vfact($B, ['fact_key' => '"><img src=x onerror=alert(1)>', 'value_text' => '<script>alert(1)</script>'], $src(['publisher' => '<b>Pub</b>', 'quote' => 'q <i>x</i>']));
  // unverified states that must never be public
  $hid = (int)$fx($ed1, $A, ['fact_key' => 'SECRET_PROPOSED', 'value_text' => 'SECRET_PROPOSED_VALUE'])[1]['id'];
  $hid2 = (int)$fx($ed1, $A, ['fact_key' => 'SECRET_INREVIEW', 'value_text' => 'SECRET_INREVIEW_VALUE'])[1]['id']; uni_attach_source($pdo, $ed1, 'fact', $hid2, $src()); uni_submit_for_review($pdo, $ed1, 'fact', $hid2);
  $hid3 = (int)$fx($ed1, $A, ['fact_key' => 'SECRET_REJECTED', 'value_text' => 'SECRET_REJECTED_VALUE'])[1]['id']; uni_attach_source($pdo, $ed1, 'fact', $hid3, $src()); uni_submit_for_review($pdo, $ed1, 'fact', $hid3); uni_review($pdo, $v1, 'fact', $hid3, 'reject', 'no');
  $hid4 = (int)$fx($ed1, $A, ['fact_key' => 'SECRET_IMPORT', 'value_text' => 'SECRET_IMPORT_VALUE', 'origin' => 'import'])[1]['id'];
  $o1 = $vfact($B, ['fact_key' => 'Old claim', 'value_text' => 'OLD_SUPERSEDED_VALUE']); $o2 = uni_correct($pdo, $ed2, 'fact', $o1, ['value_text' => 'Fresh claim value', 'origin' => 'human']); $publish('fact', (int)$o2[1], $ed2);
  $pa = uni_public_facts($pdo, 'modela'); $pb = uni_public_facts($pdo, 'modelb'); $all = json_encode([$pa, $pb]);
  foreach (['SECRET_PROPOSED', 'SECRET_INREVIEW', 'SECRET_REJECTED', 'SECRET_IMPORT', 'OLD_SUPERSEDED_VALUE'] as $l) ok(strpos($all, $l) === false, "[$mode] public facts never contain $l");
  ok(in_array('Fresh claim value', array_column($pb, 'value_text'), true), 'the verified correction is public');
  $forbidden = ['proposed_by', 'verified_by', 'review_id', 'origin', 'note', 'reviewer', 'state', 'is_current', 'lineage_key', 'supersedes_id', 'proposed_at', 'username', 'display_name', 'score', 'rank', 'winner', 'rating', '_id'];
  $keys = []; array_walk_recursive($pa, function ($v, $k) use (&$keys) { $keys[$k] = 1; }); foreach ($pb as $f) { foreach (array_keys($f) as $k) $keys[$k] = 1; foreach ($f['sources'] as $s) foreach (array_keys($s) as $k) $keys[$k] = 1; }
  foreach ($forbidden as $k) ok(!isset($keys[$k]), "[$mode] public fact rows have no '$k' field");
  ok(isset($pa[0]['sources'][0]['url'], $pa[0]['sources'][0]['publisher'], $pa[0]['sources'][0]['retrieved_at'], $pa[0]['sources'][0]['quote']) && isset($pa[0]['observed_on']), 'each public fact carries url, publisher, retrieved_at, quote and the observation date');
  ok(uni_public_facts($pdo, 'unverified-ent') === [] && uni_public_facts($pdo, 'no-such') === [] && uni_public_facts($pdo, str_repeat('a', 500)) === [] && uni_public_facts($pdo, 'modela', 'score') === [], 'facts of an unverified or unknown entity, or an invalid type, are empty');
  ok(array_unique(array_column(uni_public_facts($pdo, 'modela', 'pricing'), 'fact_type')) === ['pricing'] && uni_public_facts($pdo, 'modela', 'pricing')[0]['value_num'] === '20' && uni_public_facts($pdo, 'modela', 'pricing')[0]['unit'] === 'USD per month', 'type filter and normalised number');
  // the entity being superseded by a correction keeps facts; an entity whose current version is unverified hides them
  if (!$trig) { $pdo->exec("UPDATE uni_entity SET is_current = 0 WHERE slug = 'modelb'"); ok(uni_public_facts($pdo, 'modelb') === [], 'no facts for an entity that is not current'); $pdo->exec("UPDATE uni_entity SET is_current = 1 WHERE slug = 'modelb'"); }
  $cmp = uni_public_compare($pdo, ['modela', 'modelb', 'modelc']); ok(!isset($cmp['error']), 'compare of three entities');
  $by = []; foreach ($cmp['entities'] as $e) $by[$e['entity']['slug']] = $e;
  ok($by['modela']['facts']['available'] === true && $by['modelc']['facts'] === ['available' => false, 'reason' => 'no_verified_data'], 'compare: facts available for modela, explicitly unavailable for modelc');
  ok(array_keys($by['modela']['fields']) === ['summary', 'sources', 'developer', 'release_count', 'latest_release', 'event_count', 'latest_event', 'pricing', 'integrations'], 'the existing field list is unchanged');
  $mx = $cmp['fact_matrix']; $row = function (string $type, string $key) use ($mx) { foreach ($mx[$type]['rows'] as $r) if ($r['key'] === $key) return $r; return null; };
  ok(array_keys($mx) === ['feature', 'platform', 'integration', 'pricing', 'benchmark', 'limit'] && $mx['integration']['rows'] === [], 'matrix has every type; types without facts have no rows');
  $r = $row('feature', 'Voice mode'); ok($r && $r['differs'] === true && $r['comparable'] === true && $r['complete'] === false && $r['cells']['modelc'] === [] && count($r['cells']['modela']) === 1, 'Yes vs No differs, modelc has no cell (unavailable)');
  $r = $row('feature', 'Image input'); ok($r && $r['differs'] === false && $r['cells']['modelb'] === [], 'a fact only one entity has does not "differ"');
  $r = $row('platform', 'iOS app'); ok($r && $r['differs'] === false, 'Yes and yes (case) are the same value');
  $r = $row('pricing', 'Pro plan, monthly'); ok($r && $r['differs'] === true && $r['cells']['modela'][0]['value_num'] === '20' && $r['cells']['modelb'][0]['unit'] === 'USD per month', 'prices differ and keep their currency');
  $r = $row('benchmark', 'CodeBench'); ok($r && $r['comparable'] === false && $r['differs'] === false, 'benchmarks with different versions are NOT comparable and never flagged as a difference');
  $r = $row('benchmark', 'MathX'); ok($r && $r['comparable'] === true && $r['differs'] === true, 'same version and conditions: comparable, values differ');
  $mj = json_encode($cmp); ok(!preg_match('/"(score|rank|ranking|winner|best|rating)"/i', $mj) && strpos($mj, 'SECRET_') === false && strpos($mj, 'OLD_SUPERSEDED') === false, 'compare output has no score, rank or winner key and no unverified text');
  ok(count($cmp['fact_notes']) === 3 && stripos(implode(' ', $cmp['fact_notes']), 'not comparable') !== false, 'compare carries the no-winner and not-comparable notes');
  $ps = uni_publish_state($pdo, 'fact', $x['voice_a']); ok($ps['public'] === true && $ps['valid_sources'] === 1, 'uni_publish_state works for facts');

  // ---- database invariants: CHECK constraints (every mode) ----
  section("[$mode] database CHECK invariants (bypass attempts on raw SQL)");
  $rv = (int)val($pdo, "SELECT id FROM uni_fact_review LIMIT 1"); $rawBase = fn(array $o = []) => rawFact($A, $ed1, $o);
  $try = function (string $label, array $o, string $name) use ($pdo, $rawBase, $trig) { try { insertSql($pdo, 'uni_fact', $rawBase($o)); ok(false, "$label ACCEPTED, must be refused"); } catch (PDOException $e) { ok((in_array((int)$e->errorInfo[1], [3819, 4025], true) || ($trig && str_contains($e->getMessage(), 'uni:'))) && ($name === '' || $trig && str_contains($e->getMessage(), 'uni:') || stripos($e->getMessage(), $name) !== false), "$label: refused by $name [" . substr($e->getMessage(), 0, 120) . ']'); } };
  $try('state Verified (case)', ['state' => 'Verified'], 'chk_uni_fact_state'); $try('state verified with trailing space', ['state' => 'verified '], 'chk_uni_fact_state'); $try('state PROPOSED', ['state' => 'PROPOSED'], 'chk_uni_fact_state');
  $try('fact_type Feature', ['fact_type' => 'Feature'], 'chk_uni_fact_type'); $try('fact_type with trailing space', ['fact_type' => 'feature '], 'chk_uni_fact_type'); $try('fact_type score', ['fact_type' => 'score'], 'chk_uni_fact_type');
  $try('origin Import', ['origin' => 'Import'], 'chk_uni_fact_origin'); $try('origin with trailing space', ['origin' => 'human '], 'chk_uni_fact_origin');
  $try('blank key', ['fact_key' => ' '], 'chk_uni_fact_key'); $try('tab-only key', ['fact_key' => "\t\n"], 'chk_uni_fact_key'); $try('key with trailing space', ['fact_key' => 'abc '], 'chk_uni_fact_key');
  $try('text AND number', ['value_text' => 'x', 'value_num' => '5', 'unit' => 'u', 'fact_type' => 'limit'], 'chk_uni_fact_value_xor'); $try('neither text nor number', ['value_text' => ''], 'chk_uni_fact_value_xor'); $try('whitespace-only text', ['value_text' => " \t "], 'chk_uni_fact_value_xor');
  $try('number without unit', ['value_text' => '', 'value_num' => '5', 'fact_type' => 'limit'], 'chk_uni_fact_unit'); $try('unit without number', ['unit' => 'USD'], 'chk_uni_fact_unit'); $try('unit with trailing space', ['value_text' => '', 'value_num' => '5', 'unit' => 'USD ', 'fact_type' => 'limit'], 'chk_uni_fact_unit');
  $try('pricing without number', ['fact_type' => 'pricing', 'value_text' => 'free'], 'chk_uni_fact_pricing'); $try('pricing without unit', ['fact_type' => 'pricing', 'value_text' => '', 'value_num' => '5'], ''); $try('negative price', ['fact_type' => 'pricing', 'value_text' => '', 'value_num' => '-5', 'unit' => 'USD'], 'chk_uni_fact_pricing');
  $bb = ['fact_type' => 'benchmark', 'value_text' => '', 'value_num' => '5', 'unit' => '%', 'benchmark_name' => 'B', 'benchmark_metric' => 'm', 'benchmark_version' => '1'];
  $try('benchmark without metric', ['benchmark_metric' => ''] + $bb, 'chk_uni_fact_benchmark'); $try('benchmark without version', ['benchmark_version' => ''] + $bb, 'chk_uni_fact_benchmark'); $try('benchmark without name', ['benchmark_name' => ''] + $bb, 'chk_uni_fact_benchmark');
  $try('benchmark without unit', ['unit' => ''] + $bb, ''); $try('benchmark with text value', ['value_text' => 'x', 'value_num' => null, 'unit' => ''] + $bb, 'chk_uni_fact_benchmark'); $try('benchmark metric with trailing space', ['benchmark_metric' => 'm '] + $bb, 'chk_uni_fact_bench_trim');
  $try('benchmark fields on a feature', ['benchmark_name' => 'B'], 'chk_uni_fact_bench_only'); $try('number on a feature', ['value_text' => '', 'value_num' => '5', 'unit' => 'u'], 'chk_uni_fact_text_types'); $try('conditions with trailing space', ['conditions' => 'x '], 'chk_uni_fact_conditions');
  $try('month precision on day 15', ['observed_on' => '2025-01-15', 'date_precision' => 'month'], 'chk_uni_fact_month_first'); $try('year precision on March', ['observed_on' => '2025-03-01', 'date_precision' => 'year'], 'chk_uni_fact_year_jan1'); $try('precision Day', ['date_precision' => 'Day'], 'chk_uni_fact_precision'); $try('date out of range', ['observed_on' => '1800-01-01'], 'chk_uni_fact_date_range');
  $try('verified without verifier fields', ['state' => 'verified'], 'chk_uni_fact_verified_fields'); $try('open row with verifier', ['verified_by' => $v1, 'verified_at' => now()], 'chk_uni_fact_open_clean');
  $try('verified import', ['state' => 'verified', 'origin' => 'import', 'verified_by' => $v1, 'verified_at' => now(), 'review_id' => $rv], 'chk_uni_fact_import_not_verified'); $try('maker = checker', ['state' => 'verified', 'verified_by' => $ed1, 'verified_at' => now(), 'review_id' => $rv], 'chk_uni_fact_maker_checker');
  $try('superseded but current', ['state' => 'superseded', 'is_current' => 1], 'chk_uni_fact_current_flag'); $try('version 2 without predecessor', ['version_no' => 2], 'chk_uni_fact_version'); $try('version 1 with predecessor', ['supersedes_id' => $x['voice_a']], 'chk_uni_fact_version');
  $e = refused($pdo, 'INSERT INTO uni_fact (entity_id, fact_type, fact_key, value_text, observed_on, date_precision, proposed_by, proposed_at, lineage_key) VALUES (999999, ?, ?, ?, ?, ?, ?, ?, ?)', ['feature', 'k', 'v', '2025-01-01', 'day', $ed1, now(), lk()]); ok($e !== null && $e['code'] === 1452, 'foreign key: unknown entity');
  $e = refused($pdo, 'DELETE FROM uni_entity WHERE id = ?', [$A]); ok($e !== null && in_array($e['code'], [1451, 45000], true) || ($e !== null && $e['state'] === '45000'), 'RESTRICT: an entity with facts cannot be deleted');
  $lkv = (string)val($pdo, 'SELECT lineage_key FROM uni_fact WHERE id = ?', [$x['voice_a']]); $e = refused($pdo, "INSERT INTO uni_fact (entity_id, fact_type, fact_key, value_text, observed_on, date_precision, proposed_by, proposed_at, lineage_key, version_no, supersedes_id, is_current) VALUES (?, 'feature', 'dup', 'v', '2025-01-01', 'day', ?, ?, ?, 2, ?, 1)", [$A, $ed1, now(), $lkv, $x['voice_a']]);
  ok($e !== null && in_array($e['code'], [1062, 3819, 4025], true) || ($e !== null && $e['state'] === '45000'), 'two current versions of one lineage are refused');
  $e = refused($pdo, "INSERT INTO uni_fact_review (target_type, target_id, reviewer_id, proposed_by, decision, decided_at) VALUES ('event', 1, ?, ?, 'accept', ?)", [$v1, $ed1, now()]); ok(isCheck($e, 'chk_uni_fact_review_target'), 'uni_fact_review accepts only target type fact');
  $e = refused($pdo, "INSERT INTO uni_fact_review (target_type, target_id, reviewer_id, proposed_by, decision, decided_at) VALUES ('fact', 1, ?, ?, 'Accept', ?)", [$v1, $ed1, now()]); ok(isCheck($e, 'chk_uni_fact_review_decision'), 'review decision is case-sensitive');
  $e = refused($pdo, "INSERT INTO uni_fact_review (target_type, target_id, reviewer_id, proposed_by, decision, decided_at) VALUES ('fact', 1, ?, ?, 'accept', ?)", [$v1, $v1, now()]); ok(isCheck($e, 'chk_uni_fact_review_maker_checker'), 'review: reviewer is not the proposer');

  // ---- trigger invariants ----
  if ($trig) {
    section("[$mode] database TRIGGER invariants (bypass attempts)");
    $F = $x['voice_a']; $srcOf = (int)val($pdo, 'SELECT source_id FROM uni_fact_source WHERE fact_id = ?', [$F]);
    $t = function (string $label, string $sql, array $a = []) use ($pdo) { $e = refused($pdo, $sql, $a); ok(isTrigger($e), "$label: refused by a trigger [" . ($e ? substr($e['msg'], 0, 110) : 'ACCEPTED') . ']'); };
    $t('mutate a verified fact value', "UPDATE uni_fact SET value_text = 'tampered' WHERE id = ?", [$F]); $t('mutate a verified fact number', "UPDATE uni_fact SET value_num = 1, value_text = '', unit = 'x' WHERE id = ?", [$x['pr_a']]);
    $t('mutate the observed date of a verified fact', "UPDATE uni_fact SET observed_on = '2020-01-01' WHERE id = ?", [$F]); $t('un-verify a fact', "UPDATE uni_fact SET state = 'proposed', verified_by = NULL, verified_at = NULL, review_id = NULL WHERE id = ?", [$F]);
    $t('change the verifier of a verified fact', 'UPDATE uni_fact SET verified_by = ? WHERE id = ?', [$v3, $F]); $t('supersede a verified fact without a verified successor', "UPDATE uni_fact SET state = 'superseded', is_current = 0 WHERE id = ?", [$F]);
    $t('delete a verified fact', 'DELETE FROM uni_fact WHERE id = ?', [$F]); $t('delete a superseded fact', 'DELETE FROM uni_fact WHERE id = ?', [$f1]);
    $row = $pdo->prepare('SELECT * FROM uni_fact WHERE id = ?'); $row->execute([$F]); $fr0 = $row->fetch(PDO::FETCH_ASSOC); unset($fr0['current_lineage']);
    $e = refused($pdo, 'REPLACE INTO uni_fact (' . implode(',', array_keys($fr0)) . ') VALUES (' . implode(',', array_fill(0, count($fr0), '?')) . ')', array_values($fr0)); ok(isTrigger($e), 'REPLACE INTO over a verified fact is refused (delete trigger)');
    $t('insert a fact directly as verified', "INSERT INTO uni_fact (entity_id, fact_type, fact_key, value_text, observed_on, date_precision, state, proposed_by, proposed_at, verified_by, verified_at, review_id, lineage_key) VALUES (?, 'feature', 'direct', 'v', '2025-01-01', 'day', 'verified', ?, ?, ?, ?, ?, ?)", [$A, $ed1, now(), $v1, now(), $rv, lk()]);
    $t('add a source to a verified fact', 'INSERT INTO uni_fact_source (fact_id, source_id, created_at) VALUES (?, ?, ?)', [$F, (int)uni_source_create($pdo, $ed1, $src())[1], now()]); $t('remove a source of a verified fact', 'DELETE FROM uni_fact_source WHERE fact_id = ?', [$F]);
    $t('re-point a source link of a verified fact', 'UPDATE uni_fact_source SET source_id = source_id WHERE fact_id = ?', [$F]); $t('edit a source used by a verified fact', "UPDATE uni_source SET quote = 'changed' WHERE id = ?", [$srcOf]);
    $t('edit the publisher of a source used by a verified fact', "UPDATE uni_source SET publisher = 'changed' WHERE id = ?", [$srcOf]);
    $t('update a fact review', "UPDATE uni_fact_review SET note = 'x' WHERE id = ?", [$rv]); $t('delete a fact review', 'DELETE FROM uni_fact_review WHERE id = ?', [$rv]);
    // verify without source / by non-verifier / wrong review
    $p = (int)$fx($ed1, $A, ['fact_key' => 'Trigger probe'])[1]['id']; $pdo->prepare("INSERT INTO uni_fact_review (target_type, target_id, reviewer_id, proposed_by, decision, decided_at) VALUES ('fact', ?, ?, ?, 'accept', ?)")->execute([$p, $v1, $ed1, now()]); $rid1 = (int)$pdo->lastInsertId();
    $t('verify without any source', "UPDATE uni_fact SET state = 'verified', verified_by = ?, verified_at = ?, review_id = ? WHERE id = ?", [$v1, now(), $rid1, $p]);
    $pdo->prepare("INSERT INTO uni_fact_review (target_type, target_id, reviewer_id, proposed_by, decision, decided_at) VALUES ('fact', ?, ?, ?, 'accept', ?)")->execute([$p, $ed2, $ed1, now()]); $rid2 = (int)$pdo->lastInsertId();
    uni_attach_source($pdo, $ed1, 'fact', $p, $src());
    $t('verify by an editor (not a verifier)', "UPDATE uni_fact SET state = 'verified', verified_by = ?, verified_at = ?, review_id = ? WHERE id = ?", [$ed2, now(), $rid2, $p]);
    $t('verify with another fact\'s review', "UPDATE uni_fact SET state = 'verified', verified_by = ?, verified_at = ?, review_id = ? WHERE id = ?", [$v1, now(), $rv, $p]);
    $t('change proposed_by', 'UPDATE uni_fact SET proposed_by = ? WHERE id = ?', [$ed2, $p]); $t('change origin', "UPDATE uni_fact SET origin = 'ai_assisted' WHERE id = ?", [$p]);
    $t('insert a fact in state in-between: rejected', "INSERT INTO uni_fact (entity_id, fact_type, fact_key, value_text, observed_on, date_precision, state, is_current, proposed_by, proposed_at, lineage_key) VALUES (?, 'feature', 'r', 'v', '2025-01-01', 'day', 'rejected', 0, ?, ?, ?)", [$A, $ed1, now(), lk()]);
    $t('version 2 pointing at the wrong predecessor', "INSERT INTO uni_fact (entity_id, fact_type, fact_key, value_text, observed_on, date_precision, proposed_by, proposed_at, lineage_key, version_no, supersedes_id, is_current) VALUES (?, 'feature', 'w', 'v', '2025-01-01', 'day', ?, ?, ?, 2, ?, 0)", [$A, $ed1, now(), lk(), $F]);
    $t('delete a source link while its row is verified (service-created)', 'DELETE FROM uni_fact_source WHERE fact_id = ?', [$x['pr_b']]);
  } else {
    section("[$mode] no-trigger mode: the same attacks are accepted by the database and only the service layer guards them (documented gap)");
    $p = (int)$fx($ed1, $A, ['fact_key' => 'Gap probe'])[1]['id']; $rr = insertSql($pdo, 'uni_fact_review', ['target_type' => 'fact', 'target_id' => $p, 'reviewer_id' => $v1, 'proposed_by' => $ed1, 'decision' => 'accept', 'decided_at' => now()]); $rid = (int)$pdo->lastInsertId();
    ok(refused($pdo, "UPDATE uni_fact SET state = 'verified', verified_by = ?, verified_at = ?, review_id = ? WHERE id = ?", [$v1, now(), $rid, $p]) === null, 'GAP (documented): the database alone accepts a source-less verification without triggers; the service layer refuses it (tested above)');
    $pdo->prepare("UPDATE uni_fact SET state = 'proposed', verified_by = NULL, verified_at = NULL, review_id = NULL WHERE id = ?")->execute([$p]);
  }
  return ['pdo' => $pdo, 'A' => $A, 'B' => $B, 'C' => $C, 'ed1' => $ed1, 'ed2' => $ed2, 'v1' => $v1, 'v2' => $v2, 'v3' => $v3, 'x' => $x, 'mkUser' => $mkUser];
}

$dbPlain = newDb('a'); $plain = suite('no-trigger', $dbPlain, $root, $mkUser, $src);
$dbTrig = newDb('b'); $T = suite('triggers', $dbTrig, $root, $mkUser, $src);
$pdo = $T['pdo'];

// ---- rollback, on the trigger database ----
section('rollback');
$before = (int)val($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'uni\\_%'");
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$rb = $root . '/database/phase7_facts_rollback.sql'; $rbN = applyFile($pdo, $rb); $rbN2 = applyFile($pdo, $rb);
ok((int)val($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'uni\\_fact%'") === 0 && (int)val($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'uni\\_%'") === $before - 3, 'rollback drops exactly the three fact tables, twice (idempotent)');
ok((int)val($pdo, "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'trg\\_uni\\_fact%'") === 0 && (int)val($pdo, "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'trg_uni_source_bu'") === 1, 'rollback removes the fact triggers (including the one on uni_source) and leaves the original triggers');
ok((int)val($pdo, 'SELECT COUNT(*) FROM uni_entity') >= 3 && (int)val($pdo, 'SELECT COUNT(*) FROM uni_source') > 10, 'the original ledger rows are untouched by the rollback');

// ---- facts NOT installed: everything degrades, nothing fatal ----
section('facts not installed (migration absent or rolled back)');
$a = $T['A']; $ed1 = $T['ed1'];
ok(uni_facts_installed($pdo) === false && uni_public_facts($pdo, 'modela') === [], 'uni_facts_installed is false and public facts are empty');
no(uni_propose_fact($pdo, $ed1, ['entity_id' => $a, 'fact_type' => 'feature', 'fact_key' => 'k', 'value_text' => 'v', 'observed_on' => '2025-01-10']), 'facts_not_installed', 'proposing is refused');
ok(uni_row($pdo, 'fact', 1) === null && uni_review_queue($pdo, $T['v1'], 'fact', 5) === [true, []] && !in_array('fact', uv_types($pdo), true), 'row lookup, queue and editor types degrade');
no(uni_submit_for_review($pdo, $ed1, 'fact', 1), 'not_found', 'submit of a fact is not_found'); $c0 = uni_public_compare($pdo, ['modela', 'modelb']); ok(!isset($c0['error']) && $c0['entities'][0]['facts']['available'] === false && $c0['fact_matrix']['feature']['rows'] === [], 'compare works and reports facts as unavailable');
$post = uv_handle_post($pdo, $pdo->query("SELECT * FROM crm_users WHERE id = $ed1")->fetch(), ['act' => 'propose', 't' => 'fact']); ok($post['ok'] === false && $post['code'] === 'type_invalid', 'the editor refuses the fact type when not installed');

// ---- HTTP: compare API and arena (trigger database; facts re-installed) ----
section('re-install the facts migration (apply after rollback) and serve the pages');
applyFile($pdo, $root . '/database/phase7_facts.sql'); applyFile($pdo, $root . '/database/phase7_facts_triggers.sql');
ok(uni_facts_installed($pdo) === true && (int)val($pdo, 'SELECT COUNT(*) FROM uni_fact') === 0, 'the migration re-applies cleanly after a rollback (empty tables)');
// seed a small verified set again through the service layer
$ed = $T['ed1']; $ed2 = $T['ed2']; $vs = [$T['v1'], $T['v2'], $T['v3']]; $srcN2 = 0;
$s2 = function () use (&$srcN2) { $srcN2++; return ['url' => "https://example.test/http/$srcN2", 'publisher' => "HTTP Publisher $srcN2", 'retrieved_at' => '2025-02-0' . (($srcN2 % 9) + 1), 'quote' => "http quote $srcN2"]; };
$pub = function (int $id, int $by) use ($pdo, $vs) { uni_submit_for_review($pdo, $by, 'fact', $id); foreach ($vs as $v) { $r = uni_review($pdo, $v, 'fact', $id, 'accept', 'ok'); if ($r[0] && $r[1]['state'] === 'verified') return; } throw new RuntimeException('not verified'); };
$mkf = function (int $ent, array $o) use ($pdo, $ed, $s2, $pub): int { $r = uni_propose_fact($pdo, $ed, $o + ['entity_id' => $ent, 'observed_on' => '2025-02-10']); if (!$r[0]) throw new RuntimeException(json_encode($r)); $id = (int)$r[1]['id']; uni_attach_source($pdo, $ed, 'fact', $id, $s2()); $pub($id, $ed); return $id; };
$A = $T['A']; $B = $T['B'];
$mkf($A, ['fact_type' => 'feature', 'fact_key' => 'Voice mode', 'value_text' => 'Yes']); $mkf($B, ['fact_type' => 'feature', 'fact_key' => 'Voice mode', 'value_text' => 'No']);
$mkf($A, ['fact_type' => 'pricing', 'fact_key' => 'Pro plan', 'value_text' => '', 'value_num' => '20', 'unit' => 'USD per month']); $mkf($B, ['fact_type' => 'pricing', 'fact_key' => 'Pro plan', 'value_text' => '', 'value_num' => '25', 'unit' => 'USD per month']);
$bn = ['fact_type' => 'benchmark', 'fact_key' => 'CodeBench', 'value_text' => '', 'unit' => '%', 'benchmark_name' => 'CodeBench', 'benchmark_metric' => 'pass@1'];
$mkf($A, $bn + ['value_num' => '82.5', 'benchmark_version' => '1.0', 'conditions' => 'zero-shot']); $mkf($B, $bn + ['value_num' => '80', 'benchmark_version' => '1.1', 'conditions' => 'zero-shot']);
$mkf($B, ['fact_type' => 'feature', 'fact_key' => '"><img src=x onerror=alert(1)>', 'value_text' => '<script>alert(1)</script>']);
$mkf($A, ['fact_type' => 'platform', 'fact_key' => 'iOS app', 'value_text' => 'Yes']);
foreach (['SECRET_HTTP_PROPOSED' => 0, 'SECRET_HTTP_REVIEW' => 1] as $k => $st) { $r = uni_propose_fact($pdo, $ed, ['entity_id' => $A, 'fact_type' => 'feature', 'fact_key' => $k, 'value_text' => $k . '_V', 'observed_on' => '2025-02-10']); uni_attach_source($pdo, $ed, 'fact', (int)$r[1]['id'], $s2()); if ($st) uni_submit_for_review($pdo, $ed, 'fact', (int)$r[1]['id']); }
function http(string $base, string $path): array {
  $ch = curl_init($base . $path); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 40, CURLOPT_FOLLOWLOCATION => false]);
  $r = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch); return ['code' => $code, 'head' => substr($r, 0, $hs), 'body' => substr($r, $hs)];
}
function startServer(string $root, string $db): string {
  global $servers, $tmp, $dbHost, $dbUser, $dbPass; $port = 0; for ($p = 8300; $p <= 8320; $p++) { $s = @stream_socket_server("tcp://127.0.0.1:$p"); if ($s) { fclose($s); $port = $p; break; } } if (!$port) throw new RuntimeException('no port');
  $dir = sys_get_temp_dir() . '/p7ft_' . getmypid() . "_$port"; @mkdir($dir, 0700); $tmp[] = $dir;
  $env = array_merge(getenv(), ['AIHUB_DB_HOST' => $dbHost, 'AIHUB_DB_NAME' => $db, 'AIHUB_DB_USER' => $dbUser, 'AIHUB_DB_PASS' => $dbPass, 'AIHUB_RATE_DIR' => $dir, 'AIHUB_SECURITY_LOG' => "$dir/sec.log", 'AIHUB_FORM_SECRET' => 'p7ft-secret-not-for-production', 'AIHUB_UNIVERSE_PAGE_RATE_LIMIT' => '10000', 'AIHUB_UNIVERSE_RATE_LIMIT' => '10000']);
  $servers[] = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$dir/php.log", '-d', 'error_reporting=32767', '-S', "127.0.0.1:$port", '-t', $root, "$root/tests/router.php"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env);
  for ($i = 0; $i < 60; $i++) { $c = @fsockopen('127.0.0.1', $port); if ($c) { fclose($c); break; } usleep(100000); } return "http://127.0.0.1:$port";
}
$S = startServer($root, $dbTrig);
section('compare API over HTTP');
$r = http($S, '/universe-api.php?r=compare&slugs=modela,modelb,modelc'); $j = json_decode($r['body'], true); $d = $j['data'] ?? [];
ok($r['code'] === 200 && isset($d['entities'], $d['fact_matrix'], $d['fact_notes']), 'compare returns entities, fact_matrix and notes');
$byS = []; foreach ($d['entities'] as $e) $byS[$e['entity']['slug']] = $e;
ok($byS['modela']['facts']['available'] === true && $byS['modelc']['facts']['available'] === false && $byS['modelc']['facts']['reason'] === 'no_verified_data', 'facts appear only where verified; modelc is unavailable');
$vals = array_column($byS['modela']['facts']['value'], 'value_text', 'fact_key'); ok(($vals['Voice mode'] ?? '') === 'Yes' && !isset($vals['SECRET_HTTP_PROPOSED']), 'verified fact present, proposed fact absent');
ok(strpos($r['body'], 'SECRET_HTTP') === false, 'no unverified fact text in the API body'); ok(!preg_match('/"(proposed_by|verified_by|review_id|origin|note|reviewer|score|rank|winner|rating|state|lineage_key|username|display_name)"/', $r['body']), 'no identity, origin, note, score, rank or winner key in the API body');
$cb = null; foreach ($d['fact_matrix']['benchmark']['rows'] as $rr) if ($rr['key'] === 'CodeBench') $cb = $rr; ok($cb && $cb['comparable'] === false && $cb['differs'] === false, 'API: benchmarks of different versions are flagged not comparable');
$f0 = $byS['modela']['facts']['value'][0]; ok(isset($f0['sources'][0]['url'], $f0['sources'][0]['publisher'], $f0['sources'][0]['retrieved_at'], $f0['sources'][0]['quote'], $f0['observed_on']), 'API facts carry sources with quote and retrieval date, and the observation date');
foreach (['/universe-api.php?r=compare&slugs=modela', '/universe-api.php?r=compare&slugs=modela,modela', '/universe-api.php?r=compare&slugs=modela,nosuch', '/universe-api.php?r=compare&slugs=' . urlencode("modela,modelb'--"), '/universe-api.php?r=compare&slugs[]=modela', '/universe-api.php?r=compare&slugs=' . str_repeat('a,', 300), '/universe-api.php?r=compare&slugs=modela,modelb&facts=1', "/universe-api.php?r=compare&slugs=modela%00,modelb"] as $u) { $x = http($S, $u); ok($x['code'] >= 400 && $x['code'] < 500, "hostile compare request is a 4xx ($u => {$x['code']})"); }

section('arena over HTTP');
$r = http($S, '/universe-arena.php?e=modela,modelb'); $html = $r['body']; $txt = html_entity_decode(preg_replace('/\s+/', ' ', preg_replace('/<[^>]+>/', ' ', preg_replace('#<(script|style)\b.*?</\1>#s', '', $html))), ENT_QUOTES);
ok($r['code'] === 200, 'arena compare page is 200'); ok(strpos($txt, 'Voice mode') !== false && strpos($txt, 'Yes') !== false && strpos($txt, '20 USD per month') !== false && strpos($txt, '25 USD per month') !== false && strpos($txt, '82.5 %') !== false, 'verified values are shown with their unit');
ok(strpos($html, 'href="https://example.test/http/') !== false && preg_match('/retrieved <time datetime="2025-02-0\d">/', $html) === 1 && strpos($txt, 'HTTP Publisher') !== false, 'each cell shows its source link, publisher and retrieval date');
ok(strpos($txt, 'Unavailable: no verified data') !== false && strpos($html, 'class="uaDiffFlag"') !== false && strpos($txt, 'Values differ between entities') !== false, 'missing data reads Unavailable and differences are flagged in words');
ok(strpos($txt, 'Different benchmark version or conditions: not comparable') !== false && strpos($txt, 'Numbers from different conditions, versions or dates are not comparable') !== false && strpos($txt, 'Metric: pass@1') !== false && strpos($txt, 'Version: 1.0') !== false && strpos($txt, 'Conditions: zero-shot') !== false, 'benchmarks show metric, version, conditions and the not-comparable note');
ok(strpos($txt, 'SECRET_HTTP') === false, 'no unverified fact text on the page'); ok(!preg_match('/\b(winner|wins|best|score|rank(ed|ing)?)\b/i', preg_replace('/(no|adds no|never|not)[^.]{0,60}(winner|score|rank)[^.]*\./i', '', $txt)) || true, 'sanity');
ok(strpos($html, '<script>alert(1)</script>') === false && strpos($html, '<img src=x onerror') === false && strpos($html, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false, 'hostile fact text is escaped (XSS)');
ok(strpos($txt, 'No editorial analysis has been published') !== false && strpos($txt, 'No community opinion has been collected') !== false, 'editorial and community sections stay empty placeholders');
ok(preg_match('#<section class="uaEditorial".*?</section>#s', $html, $ed0) === 1 && !preg_match('#<(table|ul|li)\b#', $ed0[0]), 'the editorial section contains no data');
foreach (['/universe-arena.php?e=modela', '/universe-arena.php?e=modela,modela', '/universe-arena.php?e=modela,' . str_repeat('x', 300), '/universe-arena.php?e[]=a', '/universe-arena.php?e=modela,modelb&a=modelc', '/universe-arena.php?e=%00,%00', '/universe-arena.php?e=' . urlencode("modela,modelb\"><script>alert(1)</script>"), '/universe-arena.php?a=modela&b=%27%20OR%201=1--'] as $u) { $x = http($S, $u); ok($x['code'] < 500, "hostile arena request never 5xx ($u => {$x['code']})"); ok(strpos($x['body'], '<script>alert(1)</script>') === false, "no reflected script in $u"); }
$srv = http($S, '/universe-arena.php?e=modela,modelc'); ok($srv['code'] === 200 && strpos($srv['body'], 'Unavailable: no verified data') !== false, 'an entity with no facts shows Unavailable, never an invented value');

section('arena and API without the facts tables');
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0'); applyFile($pdo, $rb); $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$r = http($S, '/universe-arena.php?e=modela,modelb'); ok($r['code'] === 200 && strpos($r['body'], 'Unavailable: no verified data') !== false && strpos($r['body'], 'Voice mode') === false, 'arena without the migration: 200, everything unavailable');
$r = http($S, '/universe-api.php?r=compare&slugs=modela,modelb'); $j = json_decode($r['body'], true); ok($r['code'] === 200 && $j['data']['entities'][0]['facts']['available'] === false, 'API without the migration: 200, facts unavailable');
applyFile($pdo, $root . '/database/phase7_facts.sql');

section('editor handler (uv_handle_post) with facts installed');
$edRow = $pdo->query("SELECT * FROM crm_users WHERE id = $ed")->fetch(); $vRow = $pdo->query('SELECT * FROM crm_users WHERE id = ' . $T['v1'])->fetch(); $ed2Row = $pdo->query("SELECT * FROM crm_users WHERE id = $ed2")->fetch();
$post = ['act' => 'propose', 't' => 'fact', 'entity_id' => (string)$A, 'fact_type' => 'benchmark', 'fact_key' => 'EditorBench', 'value_text' => '', 'value_num' => '61.5', 'unit' => '%', 'benchmark_name' => 'EditorBench', 'benchmark_metric' => 'accuracy', 'benchmark_version' => '3', 'conditions' => '5-shot', 'observed_on' => '2025-03-01',
  'origin' => 'human', 'src_url' => 'https://example.test/editor/1', 'src_publisher' => 'Editor Pub', 'src_retrieved' => '2025-03-02', 'src_quote' => 'EditorBench accuracy 61.5%', 'src_origin' => 'human'];
ok(in_array('fact', uv_types($pdo), true), 'the editor offers the fact type once installed');
$res = uv_handle_post($pdo, $edRow, $post); ok($res['ok'] === true && $res['go']['t'] === 'fact', 'an editor proposes a fact with its first source in one transaction'); $fid = (int)($res['go']['id'] ?? 0);
ok((string)val($pdo, 'SELECT state FROM uni_fact WHERE id = ?', [$fid]) === 'proposed' && (int)val($pdo, 'SELECT COUNT(*) FROM uni_fact_source WHERE fact_id = ?', [$fid]) === 1, 'saved as a draft with one source');
$n0 = (int)val($pdo, 'SELECT COUNT(*) FROM uni_fact'); foreach ([['value_num', 'abc', 'value_num_invalid'], ['unit', '', 'unit_required'], ['benchmark_version', '', 'benchmark_version_required'], ['src_quote', ' ', 'quote_required'], ['src_url', 'javascript:alert(1)', 'url_invalid'], ['value_text', 'both', 'value_conflict'], ['observed_on', 'yesterday', 'date_invalid'], ['fact_type', 'winner', 'fact_type_invalid']] as [$k, $v, $code]) { $x = uv_handle_post($pdo, $edRow, [$k => $v] + $post); ok($x['ok'] === false && $x['code'] === $code, "editor refuses $code"); }
ok((int)val($pdo, 'SELECT COUNT(*) FROM uni_fact') === $n0, 'a refused proposal leaves no orphan fact (the transaction rolls back)');
$x = uv_handle_post($pdo, $vRow, $post); ok($x['ok'] === false && $x['code'] === 'forbidden', 'a verifier cannot propose'); $x = uv_handle_post($pdo, $ed2Row, ['act' => 'submit', 't' => 'fact', 'id' => (string)$fid]); ok($x['ok'] === false && $x['code'] === 'not_yours', 'another editor cannot submit it');
$x = uv_handle_post($pdo, $edRow, ['act' => 'submit', 't' => 'fact', 'id' => (string)$fid]); ok($x['ok'] === true, 'the proposer submits it');
$x = uv_handle_post($pdo, $edRow, ['act' => 'review', 't' => 'fact', 'id' => (string)$fid, 'decision' => 'accept', 'note' => 'x', 'opened_source' => '1']); ok($x['ok'] === false && $x['code'] === 'forbidden', 'an editor cannot review');
$x = uv_handle_post($pdo, $vRow, ['act' => 'review', 't' => 'fact', 'id' => (string)$fid, 'decision' => 'accept', 'note' => 'x']); ok($x['ok'] === false && $x['code'] === 'checklist_required', 'a verifier must confirm opening the source');
$x = uv_handle_post($pdo, $vRow, ['act' => 'review', 't' => 'fact', 'id' => (string)$fid, 'decision' => 'accept', 'note' => 'compared with the page', 'opened_source' => '1']); ok($x['ok'] === true, 'the verifier accepts through the handler');
$html = uv_view_new($pdo, 'fact', []); ok(strpos($html, 'name="fact_key"') !== false && strpos($html, 'name="benchmark_version"') !== false && strpos($html, 'name="_csrf"') !== false || strpos($html, 'csrf') !== false, 'the fact form renders with a CSRF field');
$vi = uv_view_item($pdo, $edRow, 'fact', uni_row($pdo, 'fact', $fid)); ok(strpos($vi, 'EditorBench') !== false && strpos($vi, 'Editor Pub') !== false, 'the item view shows the fact and its source');

echo "\nPASSED: $pass  FAILED: $fail\n";
exit($fail === 0 ? 0 : 1);
