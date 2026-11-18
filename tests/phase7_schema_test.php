<?php
declare(strict_types=1);
/**
 * Phase 7 schema test (scratch only). Usage: php schema_test.php
 * Env: P7_DSN_BASE (default mysql:host=127.0.0.1;port=3306), P7_USER, P7_PASS, P7_PHASE6 (path to phase6_crm.sql),
 *      P7_ALLOW_CREATE_USER=1 (only on a private scratch server: enables the "host denies triggers" simulation).
 * Creates database p7_scratch_<pid>, never touches another database, drops it at the end.
 */
const SQLDIR = __DIR__ . '/../database';
$dsnBase = getenv('P7_DSN_BASE') ?: 'mysql:host=' . (getenv('AIHUB_DB_HOST') ?: '127.0.0.1') . ';port=3306';
$user = getenv('P7_USER') ?: (getenv('AIHUB_COMPAT_ROOT_USER') ?: 'aihub_test');
$pass = getenv('P7_PASS') !== false ? getenv('P7_PASS') : (getenv('AIHUB_COMPAT_ROOT_PASS') !== false ? getenv('AIHUB_COMPAT_ROOT_PASS') : 'test_only_pw');
$phase6 = getenv('P7_PHASE6') ?: __DIR__ . '/../database/phase6_crm.sql';
$dbName = 'p7_scratch_' . getmypid();
if (!preg_match('/^p7_scratch_\d+$/', $dbName)) { fwrite(STDERR, "bad db name\n"); exit(2); }

$opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$admin = new PDO($dsnBase, $user, $pass, $opt);
echo "Server: " . $admin->query('SELECT VERSION()')->fetchColumn() . "\n";
$admin->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo = new PDO($dsnBase . ";dbname=$dbName", $user, $pass, $opt);
$pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION,ERROR_FOR_DIVISION_BY_ZERO'");

$pass_n = 0; $fail_n = 0; $gap_n = 0; $skip_n = 0; $section = '';
function sec(string $s): void { global $section; $section = $s; echo "\n== $s ==\n"; }
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass_n, $fail_n;
    if ($cond) { $pass_n++; echo "  PASS  $label\n"; } else { $fail_n++; echo "  FAIL  $label" . ($detail ? "  [$detail]" : '') . "\n"; }
}
function expectOk(string $label, callable $fn): void {
    try { $fn(); ok($label, true); } catch (Throwable $e) { ok($label, false, 'unexpected: ' . $e->getMessage()); }
}
/** kind: check|fk|unique|notnull|trigger ; needle: constraint/key name or trigger message fragment */
function expectReject(string $label, callable $fn, string $kind, string $needle): void {
    try { $fn(); ok($label, false, 'ACCEPTED, must be rejected'); return; } catch (PDOException $e) {
        $code = (int)($e->errorInfo[1] ?? 0); $st = (string)($e->errorInfo[0] ?? ''); $msg = $e->getMessage();
        $good = match ($kind) {
            'check' => in_array($code, [3819, 4025], true),
            'fk' => in_array($code, [1451, 1452], true),
            'unique' => $code === 1062,
            'notnull' => in_array($code, [1048, 1364], true),
            'value' => in_array($code, [1292, 1525], true),
            'trigger' => $st === '45000' && str_contains($msg, 'uni:'),
            default => false,
        };
        $good = $good && ($needle === '' || stripos($msg, $needle) !== false);
        ok($label, $good, $good ? '' : "wrong rejection: [$st/$code] " . substr($msg, 0, 160));
    } catch (Throwable $e) { ok($label, false, 'non-PDO error ' . $e->getMessage()); }
}
function gap(string $label, callable $fn): void { // documents what is NOT DB-enforced without triggers
    global $gap_n;
    try { $fn(); $gap_n++; echo "  GAP   $label (accepted by DB: only the PHP service layer guards this without triggers)\n"; }
    catch (Throwable $e) { ok("GAP expectation changed: $label", false, $e->getMessage()); }
}

/** Splits a SQL file honouring DELIMITER lines and -- comment lines. */
function sqlStatements(string $file): array {
    $delim = ';'; $buf = ''; $out = [];
    foreach (preg_split('/\R/', file_get_contents($file)) as $line) {
        $t = trim($line);
        if ($buf === '' && ($t === '' || str_starts_with($t, '--'))) continue;
        if (preg_match('/^DELIMITER\s+(\S+)$/i', $t, $m)) { $delim = $m[1]; continue; }
        $buf .= $line . "\n";
        if (str_ends_with(rtrim($buf), $delim)) { $out[] = substr(rtrim($buf), 0, -strlen($delim)); $buf = ''; }
    }
    if (trim($buf) !== '') $out[] = $buf;
    return $out;
}
function applyFile(PDO $p, string $file): int { $n = 0; foreach (sqlStatements($file) as $s) { $p->exec($s); $n++; } return $n; }
function one(PDO $p, string $sql, array $a = []) { $s = $p->prepare($sql); $s->execute($a); return $s->fetchColumn(); }
function insertRow(PDO $p, string $t, array $row): int {
    $cols = array_keys($row);
    $s = $p->prepare("INSERT INTO $t (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
    $s->execute(array_values($row));
    return (int)$p->lastInsertId();
}
function snapshot(PDO $p): string {
    $out = [];
    foreach ($p->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as [$t]) {
        if (str_starts_with($t, 'uni_')) { $r = $p->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM); $out[] = $r[1]; }
    }
    sort($out); return md5(preg_replace('/ AUTO_INCREMENT=\d+/', '', implode("\n", $out)));
}
function uniTables(PDO $p): array {
    return array_map(fn($r) => $r[0], $p->query("SHOW TABLES LIKE 'uni\\_%'")->fetchAll(PDO::FETCH_NUM));
}
function triggerCount(PDO $p): int { return (int)one($p, "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'trg\\_uni\\_%'"); }

// ---------- fixtures ----------
$seq = 0;
function now(): string { return date('Y-m-d H:i:s'); }
function lk(): string { return bin2hex(random_bytes(16)); }
function mkUser(PDO $p, string $role, int $active = 1): int {
    static $n = 0; $n++;
    return insertRow($p, 'crm_users', ['username' => "u$n" . bin2hex(random_bytes(3)), 'display_name' => "U$n", 'role' => $role, 'password_hash' => 'x', 'active' => $active, 'created_at' => now()]);
}
function mkSource(PDO $p, int $by, array $o = []): int {
    return insertRow($p, 'uni_source', $o + ['url' => 'https://example.test/' . bin2hex(random_bytes(4)), 'publisher' => 'Example Publisher', 'retrieved_at' => now(), 'quote' => 'synthetic quote for tests', 'origin' => 'human', 'created_by' => $by, 'created_at' => now()]);
}
function prov(int $maker, array $o = []): array {
    return $o + ['state' => 'proposed', 'origin' => 'human', 'proposed_by' => $maker, 'proposed_at' => now()];
}
function ver(array $o = []): array { return $o + ['lineage_key' => lk(), 'version_no' => 1, 'supersedes_id' => null, 'is_current' => 1]; }
function mkEntity(PDO $p, int $maker, array $o = []): int {
    static $n = 0; $n++;
    return insertRow($p, 'uni_entity', $o + prov($maker) + ver() + ['entity_type' => 'company', 'slug' => "ent-$n-" . bin2hex(random_bytes(3)), 'name' => "Synthetic Entity $n", 'summary' => '']);
}
function mkEvent(PDO $p, int $maker, int $entity, array $o = []): int {
    return insertRow($p, 'uni_event', $o + prov($maker) + ver() + ['entity_id' => $entity, 'event_type' => 'launch', 'event_date' => '2024-03-15', 'date_precision' => 'day', 'date_kind' => 'announced', 'title' => 'Synthetic event', 'summary' => '']);
}
function mkEdge(PDO $p, int $maker, int $from, int $to, array $o = []): int {
    return insertRow($p, 'uni_edge', $o + prov($maker) + ver() + ['from_entity_id' => $from, 'to_entity_id' => $to, 'edge_type' => 'developed_by', 'valid_from' => null, 'valid_to' => null, 'notes' => '']);
}
function mkRelease(PDO $p, int $maker, int $entity, int $source, array $o = []): int {
    return insertRow($p, 'uni_release', $o + prov($maker) + ver() + ['entity_id' => $entity, 'event_id' => null, 'version_label' => 'v1', 'release_date' => '2024-03-15', 'date_precision' => 'day', 'source_id' => $source, 'change_set' => null]);
}
function mkCorrection(PDO $p, int $maker, int $source, array $o = []): int {
    return insertRow($p, 'uni_correction', $o + prov($maker) + ['target_type' => 'event', 'target_id' => 1, 'proposed_change' => '{"title":"x"}', 'source_id' => $source, 'submitter_label' => 'synthetic']);
}
function mkReview(PDO $p, string $type, int $target, int $reviewer, int $proposer, string $decision = 'accept'): int {
    return insertRow($p, 'uni_review', ['target_type' => $type, 'target_id' => $target, 'reviewer_id' => $reviewer, 'proposed_by' => $proposer, 'decision' => $decision, 'decided_at' => now()]);
}
function verifyRow(PDO $p, string $t, int $id, int $verifier, int $review): void {
    $s = $p->prepare("UPDATE $t SET state='verified', verified_by=?, verified_at=NOW(), review_id=? WHERE id=?"); $s->execute([$verifier, $review, $id]);
}
function linkSrc(PDO $p, string $t, string $col, int $id, int $src): void { insertRow($p, $t, [$col => $id, 'source_id' => $src, 'created_at' => now()]); }

// ======================================================================
sec('0. Prerequisite: Phase 6 schema (crm_users) applied to scratch DB');
$n6 = applyFile($pdo, $phase6);
ok("phase6_crm.sql applied ($n6 statements)", one($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='crm_users'") == 1);
$crmBefore = $pdo->query("SHOW TABLES LIKE 'crm\\_%'")->fetchAll(PDO::FETCH_COLUMN);

sec('1. Migration applies twice (additive, idempotent)');
$n1 = applyFile($pdo, SQLDIR . '/phase7_universe.sql');
$tables1 = uniTables($pdo); $snap1 = snapshot($pdo);
ok("first apply ($n1 statements) creates 11 uni_* tables", count($tables1) === 11, implode(',', $tables1));
$m = $maker = mkUser($pdo, 'editor');
$u1 = mkSource($pdo, $maker); // data present before 2nd apply
$n2 = applyFile($pdo, SQLDIR . '/phase7_universe.sql');
ok('second apply succeeds with no error', true);
ok('second apply leaves schema byte-identical (SHOW CREATE TABLE hash)', snapshot($pdo) === $snap1);
ok('second apply preserves existing rows', (int)one($pdo, 'SELECT COUNT(*) FROM uni_source') === 1);
ok('crm_* tables untouched by Phase 7 apply', $pdo->query("SHOW TABLES LIKE 'crm\\_%'")->fetchAll(PDO::FETCH_COLUMN) === $crmBefore);
$txt = file_get_contents(SQLDIR . '/phase7_universe.sql');
ok('migration contains only CREATE TABLE IF NOT EXISTS (no ALTER/DROP/UPDATE/DELETE/INSERT)', !preg_match('/^\s*(ALTER|DROP|UPDATE|DELETE|INSERT|TRUNCATE|RENAME)\b/mi', $txt));
ok('migration has no CREATE TRIGGER (triggers are in the optional file)', stripos(preg_replace('/^--.*$/m', '', $txt), 'CREATE TRIGGER') === false);

sec('2. Structure: engine, charset/collation, FKs');
$rows = $pdo->query("SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'uni\\_%'")->fetchAll();
ok('all uni_* tables are InnoDB', count(array_filter($rows, fn($r) => $r['ENGINE'] === 'InnoDB')) === 11);
ok('all uni_* tables use utf8mb4_unicode_ci', count(array_filter($rows, fn($r) => $r['TABLE_COLLATION'] === 'utf8mb4_unicode_ci')) === 11);
$fks = $pdo->query("SELECT CONSTRAINT_NAME, DELETE_RULE, UPDATE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'uni\\_%'")->fetchAll();
ok('uni_* has foreign keys (' . count($fks) . ')', count($fks) >= 30);
ok('EVERY uni_* FK is ON DELETE RESTRICT', count(array_filter($fks, fn($r) => $r['DELETE_RULE'] === 'RESTRICT')) === count($fks), json_encode(array_filter($fks, fn($r) => $r['DELETE_RULE'] !== 'RESTRICT')));
ok('EVERY uni_* FK is ON UPDATE RESTRICT', count(array_filter($fks, fn($r) => $r['UPDATE_RULE'] === 'RESTRICT')) === count($fks));
$chk = (int)one($pdo, "SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME LIKE 'chk\\_uni\\_%'");
ok("CHECK constraints registered ($chk)", $chk >= 60);
$unindexedFk = $pdo->query("SELECT k.TABLE_NAME, k.COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE k WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME LIKE 'uni\\_%' AND k.REFERENCED_TABLE_NAME IS NOT NULL AND NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS s WHERE s.TABLE_SCHEMA = k.TABLE_SCHEMA AND s.TABLE_NAME = k.TABLE_NAME AND s.COLUMN_NAME = k.COLUMN_NAME AND s.SEQ_IN_INDEX = 1)")->fetchAll();
ok('every FK column leads an index', count($unindexedFk) === 0, json_encode($unindexedFk));

// ======================================================================
sec('3. Invariants enforced by the schema alone (CHECK / FK / UNIQUE): each bypass must be rejected');
$maker = mkUser($pdo, 'editor'); $checker = mkUser($pdo, 'verifier'); $checker2 = mkUser($pdo, 'verifier'); $plain = mkUser($pdo, 'editor');
$src = mkSource($pdo, $maker); $ent = mkEntity($pdo, $maker); $ent2 = mkEntity($pdo, $maker);
$tn = 0;
$verRow = function (string $t, array $extra = []) use ($pdo, $maker, $ent, $ent2, $src, &$tn): array {
    // returns a row array for table $t, merged with $extra overrides, never inserted here
    $tn++;
    return match ($t) {
        'uni_entity' => prov($maker) + ver() + ['entity_type' => 'product', 'slug' => "r$tn-" . bin2hex(random_bytes(3)), 'name' => 'N', 'summary' => ''],
        'uni_event' => prov($maker) + ver() + ['entity_id' => $ent, 'event_type' => 'launch', 'event_date' => '2024-03-15', 'date_precision' => 'day', 'date_kind' => 'announced', 'title' => 'T', 'summary' => ''],
        'uni_edge' => prov($maker) + ver() + ['from_entity_id' => $ent, 'to_entity_id' => mkEntity($pdo, $maker), 'edge_type' => 'built_on', 'valid_from' => null, 'valid_to' => null, 'notes' => ''],
        'uni_release' => prov($maker) + ver() + ['entity_id' => $ent, 'event_id' => null, 'version_label' => 'v', 'release_date' => '2024-03-15', 'date_precision' => 'day', 'source_id' => $src, 'change_set' => null],
        'uni_correction' => prov($maker) + ['target_type' => 'event', 'target_id' => 1, 'proposed_change' => '{}', 'source_id' => $src, 'submitter_label' => ''],
    };
};
$mk = fn(string $t, array $o) => fn() => insertRow($pdo, $t, $o + $verRow($t));
foreach (['uni_entity', 'uni_event', 'uni_edge', 'uni_release', 'uni_correction'] as $t) {
    $short = substr($t, 4);
    expectOk("[$short] control: valid proposed row accepted", $mk($t, []));
    expectReject("[$short] state 'draft' rejected (one vocabulary)", $mk($t, ['state' => 'draft']), 'check', "chk_{$t}_state");
    expectReject("[$short] state 'editor_ok' rejected (Phase 5 style name)", $mk($t, ['state' => 'editor_ok']), 'check', "chk_{$t}_state");
    expectReject("[$short] origin NULL rejected", $mk($t, ['origin' => null]), 'notnull', '');
    expectReject("[$short] origin 'robot' rejected", $mk($t, ['origin' => 'robot']), 'check', "chk_{$t}_origin");
    $rv = mkReview($pdo, $short === 'correction' ? 'correction' : $short, 900000 + $tn, $checker, $maker);
    expectReject("[$short] verified without verified_by/at/review_id rejected", $mk($t, ['state' => 'verified']), 'check', "chk_{$t}_verified_fields");
    expectReject("[$short] verified missing review_id only rejected", $mk($t, ['state' => 'verified', 'verified_by' => $checker, 'verified_at' => now()]), 'check', "chk_{$t}_verified_fields");
    expectReject("[$short] verified missing verified_at only rejected", $mk($t, ['state' => 'verified', 'verified_by' => $checker, 'review_id' => $rv]), 'check', "chk_{$t}_verified_fields");
    expectReject("[$short] origin=import directly verified rejected", $mk($t, ['state' => 'verified', 'origin' => 'import', 'verified_by' => $checker, 'verified_at' => now(), 'review_id' => $rv]), 'check', "chk_{$t}_import_not_verified");
    expectReject("[$short] maker = checker (verified_by = proposed_by) rejected", $mk($t, ['state' => 'verified', 'verified_by' => $maker, 'verified_at' => now(), 'review_id' => $rv]), 'check', "chk_{$t}_maker_checker");
    expectReject("[$short] proposed row carrying a verifier rejected", $mk($t, ['state' => 'proposed', 'verified_by' => $checker, 'verified_at' => now()]), 'check', "chk_{$t}_open_clean");
    expectReject("[$short] nonexistent proposed_by FK rejected", $mk($t, ['proposed_by' => 99999999]), 'fk', 'fk_');
    if ($t !== 'uni_correction') {
        expectReject("[$short] superseded row still flagged current rejected", $mk($t, ['state' => 'superseded', 'is_current' => 1]), 'check', "chk_{$t}_current_flag");
        expectReject("[$short] rejected row flagged current rejected", $mk($t, ['state' => 'rejected', 'is_current' => 1]), 'check', "chk_{$t}_current_flag");
        expectReject("[$short] version 2 without supersedes_id rejected", $mk($t, ['version_no' => 2, 'supersedes_id' => null, 'is_current' => 0]), 'check', "chk_{$t}_version");
        expectReject("[$short] version 1 with supersedes_id rejected", $mk($t, ['version_no' => 1, 'supersedes_id' => 1]), 'check', "chk_{$t}_version");
        expectReject("[$short] version_no 0 rejected", $mk($t, ['version_no' => 0]), 'check', "chk_{$t}_version");
        expectReject("[$short] is_current = 2 rejected", $mk($t, ['is_current' => 2]), 'check', "chk_{$t}_current_flag");
    }
}
expectOk('[correction] control: valid JSON accepted', fn() => mkCorrection($pdo, $maker, $src));
expectReject('[correction] invalid JSON proposed_change rejected', fn() => mkCorrection($pdo, $maker, $src, ['proposed_change' => '{not json']), 'check', 'chk_uni_correction_change');
expectReject('[correction] missing source (NULL) rejected', fn() => mkCorrection($pdo, $maker, $src, ['source_id' => null]), 'notnull', '');
expectReject('[correction] nonexistent source FK rejected', fn() => mkCorrection($pdo, $maker, $src, ['source_id' => 99999999]), 'fk', 'fk_uni_correction_source');
expectReject('[correction] bad target_type rejected', fn() => mkCorrection($pdo, $maker, $src, ['target_type' => 'source']), 'check', 'chk_uni_correction_target');

// I7 date precision (event + release)
foreach (['uni_event' => 'event_date', 'uni_release' => 'release_date'] as $t => $col) {
    $s = substr($t, 4);
    expectOk("[$s] day precision any date accepted", $mk($t, [$col => '2024-07-19', 'date_precision' => 'day']));
    expectOk("[$s] month precision on day 1 accepted", $mk($t, [$col => '2024-07-01', 'date_precision' => 'month']));
    expectOk("[$s] year precision on 1 Jan accepted", $mk($t, [$col => '2024-01-01', 'date_precision' => 'year']));
    expectReject("[$s] year precision 2024-06-30 rejected (padded to look exact)", $mk($t, [$col => '2024-06-30', 'date_precision' => 'year']), 'check', "chk_{$t}_year_jan1");
    expectReject("[$s] year precision 2024-01-02 rejected", $mk($t, [$col => '2024-01-02', 'date_precision' => 'year']), 'check', "chk_{$t}_year_jan1");
    expectReject("[$s] year precision 2024-02-01 rejected", $mk($t, [$col => '2024-02-01', 'date_precision' => 'year']), 'check', "chk_{$t}_year_jan1");
    expectReject("[$s] month precision 2024-07-19 rejected", $mk($t, [$col => '2024-07-19', 'date_precision' => 'month']), 'check', "chk_{$t}_month_first");
    expectReject("[$s] precision 'week' rejected", $mk($t, [$col => '2024-07-01', 'date_precision' => 'week']), 'check', "chk_{$t}_precision");
    expectReject("[$s] precision NULL rejected", $mk($t, ['date_precision' => null]), 'notnull', '');
    expectReject("[$s] date before 1900 rejected", $mk($t, [$col => '1800-01-01', 'date_precision' => 'day']), 'check', "chk_{$t}_date_range");
    expectReject("[$s] zero date rejected (even if sql_mode lacks NO_ZERO_DATE)", $mk($t, [$col => '0000-00-00', 'date_precision' => 'day']), 'check', "chk_{$t}_date_range");
}

// sources (I2 columns)
expectOk('[source] control: valid source accepted', fn() => mkSource($pdo, $maker));
expectReject('[source] empty quote rejected', fn() => mkSource($pdo, $maker, ['quote' => '']), 'check', 'chk_uni_source_quote');
expectReject('[source] spaces-only quote rejected', fn() => mkSource($pdo, $maker, ['quote' => '    ']), 'check', 'chk_uni_source_quote');
expectReject('[source] tab/newline-only quote rejected', fn() => mkSource($pdo, $maker, ['quote' => " \t\r\n "]), 'check', 'chk_uni_source_quote');
expectReject('[source] NULL quote rejected', fn() => mkSource($pdo, $maker, ['quote' => null]), 'notnull', '');
expectReject('[source] blank publisher rejected', fn() => mkSource($pdo, $maker, ['publisher' => '  ']), 'check', 'chk_uni_source_publisher');
expectReject('[source] NULL retrieved_at rejected', fn() => mkSource($pdo, $maker, ['retrieved_at' => null]), 'notnull', '');
expectReject('[source] NULL url rejected', fn() => mkSource($pdo, $maker, ['url' => null]), 'notnull', '');
expectReject('[source] javascript: url rejected', fn() => mkSource($pdo, $maker, ['url' => 'javascript:alert(1)']), 'check', 'chk_uni_source_url');
expectReject('[source] bare text url rejected', fn() => mkSource($pdo, $maker, ['url' => 'example.test/x']), 'check', 'chk_uni_source_url');
expectReject('[source] bad origin rejected', fn() => mkSource($pdo, $maker, ['origin' => 'x']), 'check', 'chk_uni_source_origin');

// review (maker != checker on the review row)
expectOk('[review] control: reviewer <> proposer accepted', fn() => mkReview($pdo, 'event', 123456, $checker, $maker));
expectReject('[review] reviewer = proposer rejected (maker != checker)', fn() => mkReview($pdo, 'event', 123457, $maker, $maker), 'check', 'chk_uni_review_maker_checker');
expectReject('[review] same reviewer twice on one target rejected (double review)', fn() => mkReview($pdo, 'event', 123456, $checker, $maker), 'unique', 'uq_uni_review_once');
expectOk('[review] a second, different reviewer on the same target accepted (20% sample)', fn() => mkReview($pdo, 'event', 123456, $checker2, $maker));
expectReject('[review] unknown decision rejected', fn() => mkReview($pdo, 'event', 123458, $checker, $maker, 'maybe'), 'check', 'chk_uni_review_decision');
expectReject('[review] unknown target_type rejected', fn() => mkReview($pdo, 'source', 1, $checker, $maker), 'check', 'chk_uni_review_target');
expectReject('[review] reviewer_id FK to crm_users enforced', fn() => mkReview($pdo, 'event', 123459, 99999999, $maker), 'fk', 'fk_uni_review_reviewer');

// edges
expectOk('[edge] control: valid edge accepted', fn() => mkEdge($pdo, $maker, $ent, $ent2));
expectReject('[edge] self-edge rejected', fn() => mkEdge($pdo, $maker, $ent, $ent, ['edge_type' => 'successor_of']), 'check', 'chk_uni_edge_no_self');
expectReject('[edge] unknown type rejected', fn() => mkEdge($pdo, $maker, $ent, $ent2, ['edge_type' => 'likes']), 'check', 'chk_uni_edge_type');
expectReject('[edge] valid_to before valid_from rejected', fn() => mkEdge($pdo, $maker, $ent, $ent2, ['edge_type' => 'hosted_on', 'valid_from' => '2024-05-01', 'valid_to' => '2024-04-01']), 'check', 'chk_uni_edge_validity');
expectReject('[edge] duplicate current (from,to,type) with new lineage rejected', fn() => mkEdge($pdo, $maker, $ent, $ent2), 'unique', 'uq_uni_edge_current_triple');
expectReject('[edge] nonexistent entity FK rejected', fn() => mkEdge($pdo, $maker, $ent, 99999999, ['edge_type' => 'hosted_on']), 'fk', 'fk_uni_edge_to');

// versioning / current-row uniqueness (generated column)
$lin = lk();
$v1 = mkEvent($pdo, $maker, $ent, ['lineage_key' => $lin]);
expectReject('[version] second CURRENT row in same lineage rejected (generated-column unique)', fn() => mkEvent($pdo, $maker, $ent, ['lineage_key' => $lin, 'version_no' => 2, 'supersedes_id' => $v1, 'is_current' => 1]), 'unique', 'uq_uni_event_current');
expectReject('[version] duplicate (lineage, version_no) rejected', fn() => mkEvent($pdo, $maker, $ent, ['lineage_key' => $lin, 'is_current' => 0]), 'unique', 'uq_uni_event_lineage_ver');
$v2 = 0;
expectOk('[version] control: v2 proposed as NON-current alongside current v1 accepted', function () use ($pdo, $maker, $ent, $lin, $v1, &$v2) { $v2 = mkEvent($pdo, $maker, $ent, ['lineage_key' => $lin, 'version_no' => 2, 'supersedes_id' => $v1, 'is_current' => 0]); });
expectOk('[version] control: atomic swap (supersede v1, then flip v2 current) accepted', function () use ($pdo, $v1, $v2) {
    $pdo->beginTransaction();
    $pdo->exec("UPDATE uni_event SET state='superseded', is_current=0 WHERE id=$v1");
    $pdo->exec("UPDATE uni_event SET is_current=1 WHERE id=$v2");
    $pdo->commit();
});
ok('[version] exactly one current row for the lineage', (int)one($pdo, 'SELECT COUNT(*) FROM uni_event WHERE lineage_key=? AND is_current=1', [$lin]) === 1);
$sl = 'dup-slug-' . bin2hex(random_bytes(3)); mkEntity($pdo, $maker, ['slug' => $sl]);
expectReject('[version] two current entities with the same slug rejected', fn() => mkEntity($pdo, $maker, ['slug' => $sl]), 'unique', 'uq_uni_entity_current_slug');
expectReject('[entity] unknown entity_type rejected', fn() => mkEntity($pdo, $maker, ['entity_type' => 'planet']), 'check', 'chk_uni_entity_type');
expectReject('[event] unknown event_type rejected', fn() => mkEvent($pdo, $maker, $ent, ['event_type' => 'party']), 'check', 'chk_uni_event_type');
expectReject('[event] nonexistent entity FK rejected', fn() => mkEvent($pdo, $maker, 99999999), 'fk', 'fk_uni_event_entity');

// release parents (I5): mandatory source, no self-parent, join table
$r1 = mkRelease($pdo, $maker, $ent, $src, ['release_date' => '2023-01-10']); $r2 = mkRelease($pdo, $maker, $ent, $src, ['release_date' => '2024-01-10']);
expectOk('[parent] control: parent link with source accepted', fn() => insertRow($pdo, 'uni_release_parent', ['release_id' => $r2, 'parent_release_id' => $r1, 'source_id' => $src, 'created_at' => now()]));
expectReject('[parent] self-parent rejected', fn() => insertRow($pdo, 'uni_release_parent', ['release_id' => $r2, 'parent_release_id' => $r2, 'source_id' => $src, 'created_at' => now()]), 'check', 'chk_uni_release_parent_no_self');
expectReject('[parent] missing source_id (NULL) rejected', fn() => insertRow($pdo, 'uni_release_parent', ['release_id' => $r1, 'parent_release_id' => $r2, 'source_id' => null, 'created_at' => now()]), 'notnull', '');
expectReject('[parent] omitted source_id rejected', fn() => $pdo->exec("INSERT INTO uni_release_parent (release_id, parent_release_id, created_at) VALUES ($r1, $r2, NOW())"), 'notnull', '');
expectReject('[parent] nonexistent source FK rejected', fn() => insertRow($pdo, 'uni_release_parent', ['release_id' => $r1, 'parent_release_id' => $r2, 'source_id' => 99999999, 'created_at' => now()]), 'fk', 'fk_uni_release_parent_source');
expectReject('[parent] duplicate parent pair rejected', fn() => insertRow($pdo, 'uni_release_parent', ['release_id' => $r2, 'parent_release_id' => $r1, 'source_id' => $src, 'created_at' => now()]), 'unique', 'PRIMARY');
expectReject('[release] source_id NULL rejected', fn() => mkRelease($pdo, $maker, $ent, $src, ['source_id' => null]), 'notnull', '');
expectReject('[release] invalid change_set JSON rejected', fn() => mkRelease($pdo, $maker, $ent, $src, ['change_set' => '{bad']), 'check', 'chk_uni_release_change_set');

// audit log
expectOk('[audit] control: audit row accepted', fn() => insertRow($pdo, 'uni_audit_log', ['at' => '2026-01-01 00:00:00.000001', 'actor_id' => $maker, 'action' => 'propose', 'target_type' => 'event', 'target_id' => 1, 'summary' => 's', 'detail' => '{"a":1}']));
expectReject('[audit] invalid JSON detail rejected', fn() => insertRow($pdo, 'uni_audit_log', ['at' => now(), 'actor_id' => $maker, 'action' => 'x', 'detail' => '{x']), 'check', 'chk_uni_audit_detail');
expectReject('[audit] unknown actor FK rejected', fn() => insertRow($pdo, 'uni_audit_log', ['at' => now(), 'actor_id' => 99999999, 'action' => 'x']), 'fk', 'fk_uni_audit_actor');

// ON DELETE RESTRICT behaviour
$vch = mkUser($pdo, 'verifier'); $de = mkEntity($pdo, $maker); $dev = mkEvent($pdo, $maker, $de); $dsrc = mkSource($pdo, $maker); linkSrc($pdo, 'uni_event_source', 'event_id', $dev, $dsrc);
expectReject('[restrict] DELETE crm_user referenced by uni_* rejected', fn() => $pdo->exec("DELETE FROM crm_users WHERE id = $maker"), 'fk', '');
expectReject('[restrict] DELETE entity with events rejected', fn() => $pdo->exec("DELETE FROM uni_entity WHERE id = $de"), 'fk', '');
expectReject('[restrict] DELETE source linked to an event rejected', fn() => $pdo->exec("DELETE FROM uni_source WHERE id = $dsrc"), 'fk', '');
expectReject('[restrict] DELETE event with source links rejected', fn() => $pdo->exec("DELETE FROM uni_event WHERE id = $dev"), 'fk', '');
expectReject('[restrict] DELETE release that is a parent rejected', fn() => $pdo->exec("DELETE FROM uni_release WHERE id = $r1"), 'fk', '');
$rvd = mkReview($pdo, 'event', $dev, $vch, $maker); verifyRow($pdo, 'uni_event', $dev, $vch, $rvd);
expectReject('[restrict] DELETE review referenced by a verified row rejected', fn() => $pdo->exec("DELETE FROM uni_review WHERE id = $rvd"), 'fk', '');
expectReject('[restrict] UPDATE crm_users.id referenced by uni_* rejected', fn() => $pdo->exec("UPDATE crm_users SET id = id + 500000 WHERE id = $maker"), 'fk', '');

sec('3b. Cross-table rules WITHOUT triggers (documents the service-layer-only gap; not counted as pass or fail)');
$gm = mkUser($pdo, 'editor'); $gv = mkUser($pdo, 'verifier'); $gn = mkUser($pdo, 'editor');
$ge = mkEvent($pdo, $gm, $ent); $grv = mkReview($pdo, 'event', $ge, $gv, $gm);
gap('verify an event that has ZERO source links', fn() => verifyRow($pdo, 'uni_event', $ge, $gv, $grv));
$ge2 = mkEvent($pdo, $gm, $ent); $grv2 = mkReview($pdo, 'event', $ge2, $gn, $gm);
gap('verify with a reviewer who lacks the verifier role', fn() => verifyRow($pdo, 'uni_event', $ge2, $gn, $grv2));
gap('edit the title of a verified event', fn() => $pdo->exec("UPDATE uni_event SET title='silently changed' WHERE id=$ge"));
gap('UPDATE an append-only review row', fn() => $pdo->exec("UPDATE uni_review SET note='tampered' WHERE id=$grv"));
gap('DELETE an audit-log row', fn() => $pdo->exec('DELETE FROM uni_audit_log LIMIT 1'));
gap('parent release dated later than the child', fn() => insertRow($pdo, 'uni_release_parent', ['release_id' => $r1, 'parent_release_id' => $r2, 'source_id' => $src, 'created_at' => now()]));

// ======================================================================
sec('4. Rollback');
$crmRows = (int)one($pdo, 'SELECT COUNT(*) FROM crm_users');
applyFile($pdo, SQLDIR . '/phase7_rollback.sql');
ok('rollback removes every uni_* table', count(uniTables($pdo)) === 0, implode(',', uniTables($pdo)));
ok('rollback leaves crm_* tables untouched', $pdo->query("SHOW TABLES LIKE 'crm\\_%'")->fetchAll(PDO::FETCH_COLUMN) === $crmBefore);
ok('rollback leaves crm_users rows untouched', (int)one($pdo, 'SELECT COUNT(*) FROM crm_users') === $crmRows);
applyFile($pdo, SQLDIR . '/phase7_rollback.sql');
ok('rollback is idempotent (second run succeeds)', true);
ok('FOREIGN_KEY_CHECKS restored to 1 after rollback', (int)one($pdo, 'SELECT @@foreign_key_checks') === 1);
applyFile($pdo, SQLDIR . '/phase7_universe.sql');
ok('re-apply after rollback recreates identical schema', snapshot($pdo) === $snap1);

// ======================================================================
sec('5. Optional triggers: apply twice, then cross-table bypass attempts (defence in depth)');
$t1 = applyFile($pdo, SQLDIR . '/phase7_triggers.sql'); $c1 = triggerCount($pdo);
$t2 = applyFile($pdo, SQLDIR . '/phase7_triggers.sql'); $c2 = triggerCount($pdo);
ok("triggers applied ($t1 statements), $c1 triggers present", $c1 >= 20);
ok('second trigger apply is idempotent (same trigger count)', $c1 === $c2);
$maker = mkUser($pdo, 'editor'); $v1u = mkUser($pdo, 'verifier'); $v2u = mkUser($pdo, 'verifier'); $plain = mkUser($pdo, 'editor'); $dead = mkUser($pdo, 'verifier', 0);
$src = mkSource($pdo, $maker); $ent = mkEntity($pdo, $maker); $ent2 = mkEntity($pdo, $maker);
$goodEvent = function (array $o = []) use ($pdo, $maker, $ent, $src, $v1u): int {
    $id = mkEvent($pdo, $maker, $ent, $o); linkSrc($pdo, 'uni_event_source', 'event_id', $id, $src); return $id;
};
expectOk('control: legitimate path propose -> source -> review -> verify accepted', function () use ($pdo, $goodEvent, $maker, $v1u) {
    $e = $goodEvent(); $r = mkReview($pdo, 'event', $e, $v1u, $maker); verifyRow($pdo, 'uni_event', $e, $v1u, $r);
    if (one($pdo, 'SELECT state FROM uni_event WHERE id=?', [$e]) !== 'verified') throw new Exception('not verified');
});
expectOk('control: ai_assisted row can be verified through review (I3 only blocks import)', function () use ($pdo, $goodEvent, $maker, $v1u) {
    $e = $goodEvent(['origin' => 'ai_assisted']); $r = mkReview($pdo, 'event', $e, $v1u, $maker); verifyRow($pdo, 'uni_event', $e, $v1u, $r);
});
expectOk('control: edge with source verified through review', function () use ($pdo, $maker, $v1u, $ent, $ent2, $src) {
    $e = mkEdge($pdo, $maker, $ent, $ent2, ['edge_type' => 'hosted_on']); linkSrc($pdo, 'uni_edge_source', 'edge_id', $e, $src);
    $r = mkReview($pdo, 'edge', $e, $v1u, $maker); verifyRow($pdo, 'uni_edge', $e, $v1u, $r);
});

// direct INSERT as verified
$rvx = mkReview($pdo, 'event', 777001, $v1u, $maker);
expectReject('[trigger] INSERT event directly as verified rejected (bypass of workflow)', fn() => mkEvent($pdo, $maker, $ent, ['state' => 'verified', 'verified_by' => $v1u, 'verified_at' => now(), 'review_id' => $rvx]), 'trigger', 'inserted as proposed');
foreach (['uni_entity' => 'mkEntity', 'uni_edge' => null, 'uni_release' => null] as $t => $_) {
    $row = match ($t) {
        'uni_entity' => prov($maker, ['state' => 'verified', 'verified_by' => $v1u, 'verified_at' => now(), 'review_id' => $rvx]) + ver() + ['entity_type' => 'company', 'slug' => 'bypass-' . bin2hex(random_bytes(3)), 'name' => 'n', 'summary' => ''],
        'uni_edge' => prov($maker, ['state' => 'verified', 'verified_by' => $v1u, 'verified_at' => now(), 'review_id' => $rvx]) + ver() + ['from_entity_id' => $ent, 'to_entity_id' => $ent2, 'edge_type' => 'integrates_with', 'valid_from' => null, 'valid_to' => null, 'notes' => ''],
        'uni_release' => prov($maker, ['state' => 'verified', 'verified_by' => $v1u, 'verified_at' => now(), 'review_id' => $rvx]) + ver() + ['entity_id' => $ent, 'event_id' => null, 'version_label' => 'v', 'release_date' => '2024-01-01', 'date_precision' => 'day', 'source_id' => $src, 'change_set' => null],
    };
    expectReject("[trigger] INSERT " . substr($t, 4) . " directly as verified rejected", fn() => insertRow($pdo, $t, $row), 'trigger', 'inserted as proposed');
}
expectReject('[trigger] INSERT correction directly as verified rejected', fn() => mkCorrection($pdo, $maker, $src, ['state' => 'verified', 'verified_by' => $v1u, 'verified_at' => now(), 'review_id' => $rvx]), 'trigger', 'inserted as proposed');
expectReject('[trigger] INSERT as superseded rejected', fn() => mkEvent($pdo, $maker, $ent, ['state' => 'superseded', 'is_current' => 0]), 'trigger', 'inserted as proposed');

// I2: no source -> cannot verify
$e = mkEvent($pdo, $maker, $ent); $r = mkReview($pdo, 'event', $e, $v1u, $maker);
expectReject('[trigger] verify event with ZERO sources rejected (I2)', fn() => verifyRow($pdo, 'uni_event', $e, $v1u, $r), 'trigger', 'at least one source');
$eg = mkEdge($pdo, $maker, $ent2, $ent, ['edge_type' => 'derived_from']); $rg = mkReview($pdo, 'edge', $eg, $v1u, $maker);
expectReject('[trigger] verify edge with ZERO sources rejected (I2)', fn() => verifyRow($pdo, 'uni_edge', $eg, $v1u, $rg), 'trigger', 'at least one source');
expectOk('control: same event verifies once a source is linked', function () use ($pdo, $e, $src, $v1u, $r) { linkSrc($pdo, 'uni_event_source', 'event_id', $e, $src); verifyRow($pdo, 'uni_event', $e, $v1u, $r); });

// I1 role / review integrity
$e = $goodEvent(); $rp = mkReview($pdo, 'event', $e, $plain, $maker);
expectReject('[trigger] verify by user WITHOUT verifier role rejected (I1)', fn() => verifyRow($pdo, 'uni_event', $e, $plain, $rp), 'trigger', 'verifier role');
$e = $goodEvent(); $rd = mkReview($pdo, 'event', $e, $dead, $maker);
expectReject('[trigger] verify by INACTIVE verifier rejected', fn() => verifyRow($pdo, 'uni_event', $e, $dead, $rd), 'trigger', 'verifier role');
$e = $goodEvent(); $other = $goodEvent(); $ro = mkReview($pdo, 'event', $other, $v1u, $maker);
expectReject('[trigger] verify using a review that belongs to a DIFFERENT row rejected', fn() => verifyRow($pdo, 'uni_event', $e, $v1u, $ro), 'trigger', 'accept review of this row');
$e = $goodEvent(); $rr = mkReview($pdo, 'event', $e, $v1u, $maker, 'reject');
expectReject('[trigger] verify using a REJECT review rejected', fn() => verifyRow($pdo, 'uni_event', $e, $v1u, $rr), 'trigger', 'accept review of this row');
$e = $goodEvent(); $rs = mkReview($pdo, 'event', $e, $v1u, $maker, 'needs_second_source');
expectReject('[trigger] verify using needs_second_source review rejected', fn() => verifyRow($pdo, 'uni_event', $e, $v1u, $rs), 'trigger', 'accept review of this row');
$e = $goodEvent(); $rm = mkReview($pdo, 'event', $e, $v1u, $maker);
expectReject('[trigger] verified_by differs from the reviewer on the review rejected', fn() => verifyRow($pdo, 'uni_event', $e, $v2u, $rm), 'trigger', 'accept review of this row');
$e = $goodEvent(); $rw = mkReview($pdo, 'event', $e, $v1u, $plain);
expectReject('[trigger] review recorded against a different proposer rejected', fn() => verifyRow($pdo, 'uni_event', $e, $v1u, $rw), 'trigger', 'accept review of this row');
$e = $goodEvent(); $rt = mkReview($pdo, 'edge', $e, $v1u, $maker);
expectReject('[trigger] review with wrong target_type (edge review for an event) rejected', fn() => verifyRow($pdo, 'uni_event', $e, $v1u, $rt), 'trigger', 'accept review of this row');
// rejected -> verified resurrection
$e = $goodEvent(); $pdo->exec("UPDATE uni_event SET state='rejected', is_current=0 WHERE id=$e"); $rj = mkReview($pdo, 'event', $e, $v1u, $maker);
expectReject('[trigger] verifying a REJECTED row directly rejected', fn() => verifyRow($pdo, 'uni_event', $e, $v1u, $rj), 'trigger', 'only proposed or in_review');
// entity & correction & release verify checks
$en = mkEntity($pdo, $maker); $rn = mkReview($pdo, 'entity', $en, $plain, $maker);
expectReject('[trigger] verify ENTITY by non-verifier rejected', fn() => verifyRow($pdo, 'uni_entity', $en, $plain, $rn), 'trigger', 'verifier role');
$rl = mkRelease($pdo, $maker, $ent, $src); $rrl = mkReview($pdo, 'release', $rl, $plain, $maker);
expectReject('[trigger] verify RELEASE by non-verifier rejected', fn() => verifyRow($pdo, 'uni_release', $rl, $plain, $rrl), 'trigger', 'verifier role');
$co = mkCorrection($pdo, $maker, $src); $rco = mkReview($pdo, 'correction', $co, $plain, $maker);
expectReject('[trigger] verify CORRECTION by non-verifier rejected', fn() => verifyRow($pdo, 'uni_correction', $co, $plain, $rco), 'trigger', 'verifier role');
$co2 = mkCorrection($pdo, $maker, $src); $rco2 = mkReview($pdo, 'correction', $co2, $v1u, $maker);
expectOk('control: correction verified by a verifier with matching review', fn() => verifyRow($pdo, 'uni_correction', $co2, $v1u, $rco2));
expectReject('[trigger] decided correction is immutable', fn() => $pdo->exec("UPDATE uni_correction SET proposed_change='{\"x\":1}' WHERE id=$co2"), 'trigger', 'immutable');

// I6 verified immutable / history retained
$ev = $goodEvent(); $rv = mkReview($pdo, 'event', $ev, $v1u, $maker); verifyRow($pdo, 'uni_event', $ev, $v1u, $rv);
expectReject('[trigger] edit title of verified event rejected (I6)', fn() => $pdo->exec("UPDATE uni_event SET title='rewritten history' WHERE id=$ev"), 'trigger', 'immutable');
expectReject('[trigger] change date of verified event rejected', fn() => $pdo->exec("UPDATE uni_event SET event_date='2020-02-02' WHERE id=$ev"), 'trigger', 'immutable');
expectReject('[trigger] change origin of verified event rejected', fn() => $pdo->exec("UPDATE uni_event SET origin='ai_assisted' WHERE id=$ev"), 'trigger', 'immutable');
expectReject('[trigger] change case only of verified title rejected (binary compare)', fn() => $pdo->exec("UPDATE uni_event SET title=UPPER(title) WHERE id=$ev"), 'trigger', 'immutable');
expectReject('[trigger] re-point verified_by on verified event rejected', fn() => $pdo->exec("UPDATE uni_event SET verified_by=$v2u WHERE id=$ev"), 'trigger', 'immutable');
expectReject('[trigger] demote verified event back to proposed rejected', fn() => $pdo->exec("UPDATE uni_event SET state='proposed', verified_by=NULL, verified_at=NULL, review_id=NULL WHERE id=$ev"), 'trigger', 'immutable');
expectReject('[trigger] remove source link of verified event rejected', fn() => $pdo->exec("DELETE FROM uni_event_source WHERE event_id=$ev"), 'trigger', 'cannot be removed');
expectReject('[trigger] re-point source link of verified event rejected', fn() => $pdo->exec("UPDATE uni_event_source SET source_id=" . mkSource($pdo, $maker) . " WHERE event_id=$ev"), 'trigger', 'immutable');
expectReject('[trigger] edit quote of a source used by a verified event rejected', fn() => $pdo->exec("UPDATE uni_source SET quote='different quote' WHERE id=$src"), 'trigger', 'source used by a verified');
expectReject('[trigger] edit url of a source used by a verified event rejected', fn() => $pdo->exec("UPDATE uni_source SET url='https://evil.test/' WHERE id=$src"), 'trigger', 'source used by a verified');
expectReject('[trigger] change-case-only edit of source publisher rejected', fn() => $pdo->exec("UPDATE uni_source SET publisher=UPPER(publisher) WHERE id=$src"), 'trigger', 'source used by a verified');
$free = mkSource($pdo, $maker);
expectOk('control: a source used by no verified row can still be corrected', fn() => $pdo->exec("UPDATE uni_source SET quote='fixed typo' WHERE id=$free"));
expectOk('control: verified row can be superseded (state change only)', fn() => $pdo->exec("UPDATE uni_event SET state='superseded', is_current=0 WHERE id=$ev"));
expectReject('[trigger] superseded row is immutable afterwards', fn() => $pdo->exec("UPDATE uni_event SET title='x' WHERE id=$ev"), 'trigger', 'immutable');
expectReject('[trigger] superseded row cannot be resurrected', fn() => $pdo->exec("UPDATE uni_event SET state='proposed' WHERE id=$ev"), 'trigger', 'immutable');

// version chain integrity
$a = mkEvent($pdo, $maker, $ent); $lA = one($pdo, 'SELECT lineage_key FROM uni_event WHERE id=?', [$a]); $b = mkEvent($pdo, $maker, $ent);
expectReject('[trigger] supersedes_id pointing at ANOTHER lineage rejected', fn() => mkEvent($pdo, $maker, $ent, ['lineage_key' => lk(), 'version_no' => 2, 'supersedes_id' => $a, 'is_current' => 0]), 'trigger', 'previous version of the same lineage');
expectReject('[trigger] version 3 skipping version 2 rejected', fn() => mkEvent($pdo, $maker, $ent, ['lineage_key' => $lA, 'version_no' => 3, 'supersedes_id' => $a, 'is_current' => 0]), 'trigger', 'previous version of the same lineage');
expectOk('control: proper v2 (same lineage, previous version) accepted', fn() => mkEvent($pdo, $maker, $ent, ['lineage_key' => $lA, 'version_no' => 2, 'supersedes_id' => $a, 'is_current' => 0]));

// I5 lineage
$p1 = mkRelease($pdo, $maker, $ent, $src, ['release_date' => '2022-05-05']); $c1r = mkRelease($pdo, $maker, $ent, $src, ['release_date' => '2023-05-05']); $same = mkRelease($pdo, $maker, $ent, $src, ['release_date' => '2022-05-05']);
expectOk('control: earlier parent accepted', fn() => insertRow($pdo, 'uni_release_parent', ['release_id' => $c1r, 'parent_release_id' => $p1, 'source_id' => $src, 'created_at' => now()]));
expectReject('[trigger] parent dated LATER than child rejected (also blocks cycles)', fn() => insertRow($pdo, 'uni_release_parent', ['release_id' => $p1, 'parent_release_id' => $c1r, 'source_id' => $src, 'created_at' => now()]), 'trigger', 'strictly earlier');
expectReject('[trigger] parent with SAME date rejected', fn() => insertRow($pdo, 'uni_release_parent', ['release_id' => $same, 'parent_release_id' => $p1, 'source_id' => $src, 'created_at' => now()]), 'trigger', 'strictly earlier');
expectReject('[trigger] re-pointing a parent link rejected', fn() => $pdo->exec("UPDATE uni_release_parent SET parent_release_id=$same WHERE release_id=$c1r"), 'trigger', 'immutable');
$vr = mkRelease($pdo, $maker, $ent, $src, ['release_date' => '2025-05-05']); insertRow($pdo, 'uni_release_parent', ['release_id' => $vr, 'parent_release_id' => $c1r, 'source_id' => $src, 'created_at' => now()]);
$rvr = mkReview($pdo, 'release', $vr, $v1u, $maker); verifyRow($pdo, 'uni_release', $vr, $v1u, $rvr);
expectReject('[trigger] add a parent to a VERIFIED release rejected', fn() => insertRow($pdo, 'uni_release_parent', ['release_id' => $vr, 'parent_release_id' => $p1, 'source_id' => $src, 'created_at' => now()]), 'trigger', 'verified release');
expectReject('[trigger] remove a parent from a VERIFIED release rejected', fn() => $pdo->exec("DELETE FROM uni_release_parent WHERE release_id=$vr"), 'trigger', 'verified release');
expectReject('[trigger] edit a source used as a verified release parent-link source rejected', fn() => $pdo->exec("UPDATE uni_source SET quote='changed' WHERE id=$src"), 'trigger', 'source used by a verified');

// append-only
$ar = mkReview($pdo, 'event', 888001, $v1u, $maker); $pdo->exec("INSERT INTO uni_audit_log (at, actor_id, action, summary) VALUES (NOW(6), $maker, 'propose', 's')");
expectReject('[trigger] UPDATE uni_review rejected (append-only)', fn() => $pdo->exec("UPDATE uni_review SET decision='reject' WHERE id=$ar"), 'trigger', 'append-only');
expectReject('[trigger] DELETE uni_review rejected (append-only)', fn() => $pdo->exec("DELETE FROM uni_review WHERE id=$ar"), 'trigger', 'append-only');
expectReject('[trigger] UPDATE uni_audit_log rejected (append-only)', fn() => $pdo->exec("UPDATE uni_audit_log SET summary='edited' LIMIT 1"), 'trigger', 'append-only');
expectReject('[trigger] DELETE uni_audit_log rejected (append-only)', fn() => $pdo->exec('DELETE FROM uni_audit_log LIMIT 1'), 'trigger', 'append-only');
expectReject('[trigger] bulk DELETE of audit rows rejected', fn() => $pdo->exec('DELETE FROM uni_audit_log WHERE id > 0'), 'trigger', 'append-only');
expectOk('control: INSERT into audit log still works', fn() => $pdo->exec("INSERT INTO uni_audit_log (at, actor_id, action) VALUES (NOW(6), $maker, 'x')"));
ok('schema CHECK rules still enforced with triggers present (year precision)', (function () use ($pdo, $maker, $ent) { try { mkEvent($pdo, $maker, $ent, ['event_date' => '2024-05-05', 'date_precision' => 'year']); return false; } catch (PDOException $e) { return in_array((int)$e->errorInfo[1], [3819, 4025], true); } })());

// ======================================================================
sec('6. Host that DENIES trigger creation: feature detection, schema still works');
if (getenv('P7_ALLOW_CREATE_USER') === '1') {
    applyFile($pdo, SQLDIR . '/phase7_rollback.sql');
    $nt = 'p7_notrig_' . getmypid();
    foreach (['%', 'localhost'] as $h) {
        $admin->exec("DROP USER IF EXISTS '$nt'@'$h'");
        $admin->exec("CREATE USER '$nt'@'$h' IDENTIFIED BY 'x'");
        $admin->exec("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES ON `$dbName`.* TO '$nt'@'$h'");
    }
    $lim = new PDO($dsnBase . ";dbname=$dbName", $nt, 'x', $opt);
    applyFile($lim, SQLDIR . '/phase7_universe.sql');
    ok('schema applies for a user without TRIGGER privilege', count(uniTables($lim)) === 11);
    $denied = null;
    try { applyFile($lim, SQLDIR . '/phase7_triggers.sql'); } catch (PDOException $e) { $denied = (int)($e->errorInfo[1] ?? 0); }
    ok("triggers file is rejected cleanly for that user (error $denied) and detectable", in_array($denied, [1142, 1227, 1419, 1044], true));
    ok('no half-installed triggers remain', triggerCount($pdo) === 0);
    $m2 = mkUser($lim, 'editor'); $sx = mkSource($lim, $m2); $ex = mkEntity($lim, $m2);
    expectReject('CHECK rules still guard in no-trigger mode (year precision)', fn() => mkEvent($lim, $m2, $ex, ['event_date' => '2024-05-05', 'date_precision' => 'year']), 'check', 'chk_uni_event_year_jan1');
    foreach (['%', 'localhost'] as $h) { $admin->exec("DROP USER IF EXISTS '$nt'@'$h'"); }
} else { $skip_n++; echo "  SKIP  set P7_ALLOW_CREATE_USER=1 on a private scratch server to run the no-TRIGGER-privilege simulation\n"; }

sec('7. Final rollback removes triggers too');
applyFile($pdo, SQLDIR . '/phase7_rollback.sql');
applyFile($pdo, SQLDIR . '/phase7_universe.sql'); applyFile($pdo, SQLDIR . '/phase7_triggers.sql');
$had = triggerCount($pdo);
applyFile($pdo, SQLDIR . '/phase7_rollback.sql');
ok("rollback drops tables and their triggers ($had -> 0)", $had > 0 && triggerCount($pdo) === 0 && count(uniTables($pdo)) === 0);

// cleanup
$pdo = null;
$admin->exec("DROP DATABASE `$dbName`");
ok("scratch database $dbName dropped", (int)one($admin, 'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$dbName]) === 0);
echo "\n=====================================\n";
echo "RESULT: $pass_n passed, $fail_n failed, $gap_n documented gaps (no-trigger mode), $skip_n skipped\n";
exit($fail_n === 0 ? 0 : 1);
