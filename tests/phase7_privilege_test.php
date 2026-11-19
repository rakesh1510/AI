<?php
declare(strict_types=1);
/**
 * Phase 7 database PRIVILEGE MODEL test. Usage: php tests/phase7_privilege_test.php
 * Env: AIHUB_COMPAT_ROOT_USER / AIHUB_COMPAT_ROOT_PASS / AIHUB_DB_HOST (the connecting account must be able to CREATE USER and GRANT, like the other Phase 7 tests' root);
 *      P7_DSN_BASE, P7_USER, P7_PASS override. Creates database p7priv_<pid> and scratch accounts p7*_<pid>@'%'; drops all of them at the end (also on failure).
 *
 * Verifies EMPIRICALLY (on whatever MySQL version it runs on; CI runs 8.0 and 8.4) the account model in docs/phase7/DB_PRIVILEGE_MODEL.md:
 *   - TRUNCATE needs the DROP privilege (DELETE is not enough, DROP alone is enough)                   - the application account cannot TRUNCATE, DROP, ALTER, DELETE, CREATE/DROP TRIGGER ...
 *   - the service layer (propose, attach, submit, review, publish, correct, reject, public reads) works end to end for the least-privilege application account
 *     WITHOUT triggers, WITH triggers installed by the root/admin account and WITH triggers installed by a non-SUPER migrator account; triggers fire for the app account
 *   - DEFINER hazard: dropping the account that installed the triggers breaks every write (error 1449) until they are reinstalled
 *   - an INSERT-only importer cannot update/select/delete, and (column-level INSERT grant) cannot write state or verification columns
 *   - a read-only public account cannot write and cannot read the review, audit or user tables
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
ob_start();
require_once $root . '/uni_core.php';
$dsnBase = getenv('P7_DSN_BASE') ?: 'mysql:host=' . (getenv('AIHUB_DB_HOST') ?: '127.0.0.1') . ';port=3306';
$user = getenv('P7_USER') ?: (getenv('AIHUB_COMPAT_ROOT_USER') ?: 'aihub_test');
$pw = getenv('P7_PASS') !== false ? getenv('P7_PASS') : (getenv('AIHUB_COMPAT_ROOT_PASS') !== false ? getenv('AIHUB_COMPAT_ROOT_PASS') : 'test_only_pw');
$pid = getmypid(); $db = "p7priv_$pid";
if (!preg_match('/^p7priv_\d+$/', $db)) { fwrite(STDERR, "bad db name\n"); exit(2); }
$opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$admin = new PDO($dsnBase, $user, $pw, $opt);
$version = (string)$admin->query('SELECT VERSION()')->fetchColumn();
fwrite(STDERR, "Server: $version\n");

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
/** Runs a statement; returns null when it succeeded, otherwise the MySQL error number. */
function errno(PDO $p, string $sql, array $a = []): ?int { try { $s = $p->prepare($sql); $s->execute($a); $s->closeCursor(); return null; } catch (PDOException $e) { return (int)($e->errorInfo[1] ?? -1); } }
/** Statement must be refused for lack of privilege (1142 table, 1143 column, 1044 database, 1227 global, 1370 routine, 1045). */
function denied(PDO $p, string $sql, string $m): void { $e = errno($p, $sql); ok($e !== null && in_array($e, [1142, 1143, 1044, 1227, 1370, 1045, 1410], true), "$m (error " . var_export($e, true) . ')'); }
function allowed(PDO $p, string $sql, string $m, array $a = []): void { $e = errno($p, $sql, $a); ok($e === null, "$m (error " . var_export($e, true) . ')'); }

$tag = (string)$pid; $PW = 'pw_' . bin2hex(random_bytes(8));
$acct = ['app' => "p7app_$tag", 'appw' => "p7appw_$tag", 'imp' => "p7imp_$tag", 'pub' => "p7pub_$tag", 'mig' => "p7mig_$tag", 'mig2' => "p7mig2_$tag", 'tdel' => "p7tdel_$tag", 'tdrop' => "p7tdrop_$tag"];
function dropAccounts(PDO $admin, array $acct): void { foreach ($acct as $u) { try { $admin->exec("DROP USER IF EXISTS '$u'@'%'"); } catch (Throwable $e) { fwrite(STDERR, "cleanup: $u: " . $e->getMessage() . "\n"); } } }
function connectAs(string $u, string $db, string $dsnBase, string $pw): PDO { global $opt; return new PDO($dsnBase . ";dbname=$db", $u, $pw, $opt); }

$VERSIONED = ['uni_entity', 'uni_event', 'uni_edge', 'uni_release'];
$APPEND = ['uni_source', 'uni_entity_source', 'uni_event_source', 'uni_edge_source', 'uni_release_parent', 'uni_review', 'uni_audit_log'];
$ALLUNI = array_merge($VERSIONED, $APPEND, ['uni_correction']);
$READABLE_PUBLIC = ['uni_entity', 'uni_event', 'uni_edge', 'uni_release', 'uni_source', 'uni_entity_source', 'uni_event_source', 'uni_edge_source', 'uni_release_parent'];

/** (Re)issues every grant. Idempotent: table-level grants survive DROP TABLE, so they are re-issued after every schema reset anyway. */
function grants(PDO $admin, string $db, array $acct, array $V, array $A, array $pubTables): void {
  $q = fn(string $t) => "`$db`.`$t`";
  foreach ($acct as $u) { $admin->exec("DROP USER IF EXISTS '$u'@'%'"); $admin->exec("CREATE USER '$u'@'%' IDENTIFIED BY " . $admin->quote($GLOBALS['PW'])); }
  // application account (least privilege): SELECT, INSERT, UPDATE on the four versioned tables; SELECT, INSERT only on append-only tables; SELECT on crm_users; nothing else
  foreach ($V as $t) $admin->exec("GRANT SELECT, INSERT, UPDATE ON {$q($t)} TO '{$acct['app']}'@'%'");
  foreach ($A as $t) $admin->exec("GRANT SELECT, INSERT ON {$q($t)} TO '{$acct['app']}'@'%'");
  $admin->exec("GRANT SELECT ON {$q('uni_correction')} TO '{$acct['app']}'@'%'");
  $admin->exec("GRANT SELECT ON {$q('crm_users')} TO '{$acct['app']}'@'%'");
  // wider variant that follows the sentence in database/phase7_universe.sql literally (SELECT, INSERT, UPDATE on uni_* except review/audit)
  foreach (array_merge($V, ['uni_source', 'uni_entity_source', 'uni_event_source', 'uni_edge_source', 'uni_release_parent', 'uni_correction']) as $t) $admin->exec("GRANT SELECT, INSERT, UPDATE ON {$q($t)} TO '{$acct['appw']}'@'%'");
  foreach (['uni_review', 'uni_audit_log'] as $t) $admin->exec("GRANT SELECT, INSERT ON {$q($t)} TO '{$acct['appw']}'@'%'");
  $admin->exec("GRANT SELECT ON {$q('crm_users')} TO '{$acct['appw']}'@'%'");
  // public read-only account
  foreach ($pubTables as $t) $admin->exec("GRANT SELECT ON {$q($t)} TO '{$acct['pub']}'@'%'");
  // importer: INSERT only, and on the versioned tables only the columns that cannot carry state or verification
  foreach (['uni_source', 'uni_entity_source', 'uni_event_source', 'uni_edge_source', 'uni_release_parent'] as $t) $admin->exec("GRANT INSERT ON {$q($t)} TO '{$acct['imp']}'@'%'");
  foreach ($V as $t) {
    $s = $admin->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND EXTRA NOT LIKE '%GENERATED%' AND COLUMN_NAME NOT IN ('state','verified_by','verified_at','review_id','is_current','version_no','supersedes_id') ORDER BY ORDINAL_POSITION");
    $s->execute([$db, $t]); $cols = $s->fetchAll(PDO::FETCH_COLUMN);
    $admin->exec("GRANT INSERT (" . implode(', ', array_map(fn($c) => "`$c`", $cols)) . ") ON {$q($t)} TO '{$acct['imp']}'@'%'");
  }
  // migrator: everything inside the one database, nothing global, no GRANT OPTION
  $admin->exec("GRANT ALL PRIVILEGES ON `$db`.* TO '{$acct['mig']}'@'%'");
  $admin->exec("GRANT ALL PRIVILEGES ON `$db`.* TO '{$acct['mig2']}'@'%'");
}

$H = password_hash('test-only-password', PASSWORD_DEFAULT);
try {
  dropAccounts($admin, $acct);
  $admin->exec("DROP DATABASE IF EXISTS `$db`");
  $admin->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
  $adm = new PDO($dsnBase . ";dbname=$db", $user, $pw, $opt);
  applyFile($adm, $root . '/database/phase6_crm.sql');
  $reset = function (bool $withTriggers, ?PDO $installer = null) use ($adm, $root, $admin, $db, $acct, $VERSIONED, $APPEND, $READABLE_PUBLIC) {
    applyFile($adm, $root . '/database/phase7_rollback.sql'); applyFile($adm, $root . '/database/phase7_universe.sql');
    if ($withTriggers) applyFile($installer ?? $adm, $root . '/database/phase7_triggers.sql');
    grants($admin, $db, $acct, $VERSIONED, $APPEND, $READABLE_PUBLIC);
  };
  $reset(false);
  $conn = fn(string $k) => connectAs($acct[$k], $db, $dsnBase, $PW);

  // ------------------------------------------------------------------------------------------------------------------------- TRUNCATE needs DROP
  section('TRUNCATE requires the DROP privilege (tested, not assumed)');
  $adm->exec('CREATE TABLE p7_trunc (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB'); $adm->exec('INSERT INTO p7_trunc VALUES (1),(2),(3)');
  $adm->exec("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, TRIGGER, CREATE VIEW, SHOW VIEW ON `$db`.p7_trunc TO '{$acct['tdel']}'@'%'");   // everything except DROP
  $adm->exec("GRANT DROP ON `$db`.p7_trunc TO '{$acct['tdrop']}'@'%'");                                                                                                           // DROP and nothing else
  $tdel = $conn('tdel'); $tdrop = $conn('tdrop');
  allowed($tdel, 'DELETE FROM p7_trunc WHERE id = 3', 'account with DELETE (and every other table right except DROP) can DELETE rows');
  denied($tdel, 'TRUNCATE TABLE p7_trunc', 'TRUNCATE is refused for an account that has DELETE but not DROP');
  ok((int)val($adm, 'SELECT COUNT(*) FROM p7_trunc') === 2, 'the refused TRUNCATE removed nothing');
  denied($tdrop, 'DELETE FROM p7_trunc', 'account with only DROP cannot DELETE');
  allowed($tdrop, 'TRUNCATE TABLE p7_trunc', 'TRUNCATE succeeds for an account that has DROP and no DELETE');
  ok((int)val($adm, 'SELECT COUNT(*) FROM p7_trunc') === 0, 'the DROP-only account really emptied the table');
  $adm->exec('DROP TABLE p7_trunc');

  // ------------------------------------------------------------------------------------------------------------------------- grant shape
  section('grant inventory of every account');
  $show = function (string $u) use ($admin): string { return implode("\n", array_map(fn($r) => (string)array_values($r)[0], $admin->query("SHOW GRANTS FOR '$u'@'%'")->fetchAll(PDO::FETCH_NUM))); };
  $g = $show($acct['app']);
  foreach (['DELETE', 'DROP', 'ALTER', 'TRIGGER', 'REFERENCES', 'CREATE', 'INDEX', 'SUPER', 'ALL PRIVILEGES', 'GRANT OPTION', 'FILE', 'EXECUTE', 'LOCK TABLES', 'CREATE VIEW'] as $bad) ok(!preg_match('/\b' . preg_quote($bad, '/') . '\b/', preg_replace('/ON `[^`]+`\.`[^`]+`/', '', $g)), "application account holds no $bad");
  ok(substr_count($g, 'ON `' . $db . '`.`uni_review`') === 1 && preg_match('/GRANT SELECT, INSERT ON `' . $db . '`\.`uni_review`/', $g) === 1 && preg_match('/GRANT SELECT, INSERT ON `' . $db . '`\.`uni_audit_log`/', $g) === 1, 'application account has SELECT, INSERT only on uni_review and uni_audit_log');
  ok(preg_match('/UPDATE/', $show($acct['imp'])) !== 1 && preg_match('/\bSELECT\b/', $show($acct['imp'])) !== 1 && preg_match('/DELETE/', $show($acct['imp'])) !== 1, 'importer account holds INSERT only');
  ok(preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRIGGER)\b/', preg_replace('/ON `[^`]+`\.`[^`]+`/', '', $show($acct['pub']))) !== 1, 'public account holds SELECT only');
  ok(preg_match('/ON \*\.\*/', preg_replace('/GRANT USAGE ON \*\.\* TO [^\n]*/', '', $show($acct['mig']))) !== 1 && strpos($show($acct['mig']), 'WITH GRANT OPTION') === false, 'migrator account is limited to its one database and has no GRANT OPTION');

  // ------------------------------------------------------------------------------------------------------------------------- denials for the app account
  $denials = function (PDO $app, string $label, bool $triggers = false, bool $tight = true) use ($ALLUNI, $db, $adm) {
    section("application account denials ($label)");
    foreach ($ALLUNI as $t) { denied($app, "TRUNCATE TABLE $t", "[$label] TRUNCATE $t refused"); denied($app, "DELETE FROM $t", "[$label] DELETE FROM $t refused"); }
    denied($app, 'DROP TABLE uni_audit_log', "[$label] DROP TABLE refused");
    denied($app, 'ALTER TABLE uni_event ADD COLUMN p7_x INT NULL', "[$label] ALTER TABLE refused");
    denied($app, 'CREATE INDEX p7_ix ON uni_event (title)', "[$label] CREATE INDEX refused");
    denied($app, 'CREATE TABLE p7_new (id INT)', "[$label] CREATE TABLE refused");
    denied($app, 'RENAME TABLE uni_event TO uni_event_x', "[$label] RENAME TABLE refused");
    $e = errno($app, 'CREATE TRIGGER p7_trg BEFORE INSERT ON uni_event_source FOR EACH ROW SET @a = 1');
    ok($e !== null && in_array($e, [1142, 1227, 1419], true), "[$label] CREATE TRIGGER refused (error " . var_export($e, true) . '; 1419 = the binary-log SUPER check fires before the privilege check)');
    ok((int)val($adm, "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'p7_trg'") === 0, "[$label] and no such trigger exists");
    if ($triggers) {
      $n0 = (int)val($adm, "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()");
      denied($app, 'DROP TRIGGER trg_uni_event_bu', "[$label] DROP TRIGGER refused");
      ok((int)val($adm, "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()") === $n0, "[$label] every trigger is still installed");
    }
    denied($app, "UPDATE uni_review SET note = 'x'", "[$label] UPDATE uni_review refused (append-only by privilege)");
    denied($app, "UPDATE uni_audit_log SET summary = 'x'", "[$label] UPDATE uni_audit_log refused (append-only by privilege)");
    if ($tight) denied($app, "UPDATE uni_source SET quote = 'x'", "[$label] UPDATE uni_source refused (sources are insert-only for the tight profile)");
    denied($app, "INSERT INTO crm_users (username) VALUES ('p7x')", "[$label] INSERT crm_users refused");
    denied($app, "UPDATE crm_users SET role = 'verifier'", "[$label] UPDATE crm_users refused: the app cannot promote anyone");
    denied($app, 'SELECT user FROM mysql.user LIMIT 1', "[$label] mysql.user unreadable");
    denied($app, 'SET GLOBAL max_connections = 1', "[$label] SET GLOBAL refused");
    denied($app, "SELECT 1 INTO OUTFILE '/tmp/p7_outfile_$label'", "[$label] SELECT INTO OUTFILE refused (no FILE)");
    denied($app, "GRANT SELECT ON `$db`.uni_event TO 'p7nobody'@'%'", "[$label] GRANT refused");
    denied($app, 'CREATE DATABASE p7_other', "[$label] CREATE DATABASE refused");
    denied($app, 'DROP DATABASE ' . $db, "[$label] DROP DATABASE refused");
    denied($app, 'SELECT * FROM crm_leads LIMIT 1', "[$label] other application tables are not readable by the Universe account");
  };

  // ------------------------------------------------------------------------------------------------------------------------- service end to end
  $e2e = function (string $profile, string $label, bool $triggers) use ($adm, $conn, $H, $acct, $db, $dsnBase, $PW) {
    section("service layer end to end as '$profile' ($label)");
    $app = $conn($profile); $pub = $conn('pub'); static $n = 0; $n++; $t = preg_replace('/[^a-z0-9]/', '', strtolower($label)) . $n;
    $mk = function (string $nm, string $role) use ($adm, $H, $t): int { [$ok, $id] = crm_user_create($adm, "{$nm}_$t", "Display $nm $t", $role, $H); if (!$ok) throw new RuntimeException("user $nm: $id"); return (int)$id; };
    $ed = $mk('editor', 'editor'); $ed2 = $mk('editor2', 'editor'); $v1 = $mk('verifier1', 'verifier'); $v2 = $mk('verifier2', 'verifier'); $v3 = $mk('verifier3', 'verifier');
    $src = fn() => ['url' => 'https://example.test/' . bin2hex(random_bytes(4)), 'publisher' => 'Example Publisher', 'retrieved_at' => '2025-01-15', 'quote' => 'a synthetic quote'];
    $publish = function (string $type, int $id, int $by) use ($app, $v1, $v2, $v3): bool {
      $s = uni_submit_for_review($app, $by, $type, $id); if (!$s[0]) { fwrite(STDERR, "   submit $type: {$s[1]}\n"); return false; }
      foreach ([$v1, $v2, $v3] as $v) { $r = uni_review($app, $v, $type, $id, 'accept'); if (!$r[0]) { fwrite(STDERR, "   review $type: {$r[1]}\n"); return false; } if ($r[1]['state'] === 'verified') return true; }
      return false;
    };
    $r = uni_propose_entity($app, $ed, ['entity_type' => 'company', 'slug' => "co-$t", 'name' => "Company $t", 'summary' => 's']); ok($r[0], "[$label] propose entity " . json_encode($r)); $ent = (int)$r[1];
    $a = uni_attach_source($app, $ed, 'entity', $ent, $src()); ok($a[0], "[$label] attach source to entity (new source row + link row)");
    ok($publish('entity', $ent, $ed), "[$label] entity submitted, reviewed (sampling second review included) and published");
    $r = uni_propose_entity($app, $ed, ['entity_type' => 'product', 'slug' => "pr-$t", 'name' => "Product $t"]); $ent2 = (int)$r[1]; uni_attach_source($app, $ed, 'entity', $ent2, $src()); ok($publish('entity', $ent2, $ed), "[$label] second entity published");
    $r = uni_propose_event($app, $ed, ['entity_id' => $ent, 'event_type' => 'launch', 'event_date' => '2024-05-06', 'title' => "Launch $t"]); ok($r[0], "[$label] propose event"); $ev = (int)$r[1]['id'];
    ok(uni_attach_source($app, $ed, 'event', $ev, $src())[0], "[$label] attach source to event"); ok($publish('event', $ev, $ed), "[$label] event published");
    $r = uni_propose_edge($app, $ed, ['from_entity_id' => $ent2, 'to_entity_id' => $ent, 'edge_type' => 'developed_by']); ok($r[0], "[$label] propose edge"); $eg = (int)$r[1];
    uni_attach_source($app, $ed, 'edge', $eg, $src()); ok($publish('edge', $eg, $ed), "[$label] edge published");
    $s = uni_source_create($app, $ed, $src()); ok($s[0], "[$label] standalone source created");
    $r = uni_propose_release($app, $ed, ['entity_id' => $ent2, 'version_label' => '1.0', 'release_date' => '2024-06-01', 'source_id' => (int)$s[1]]); ok($r[0], "[$label] propose release"); $rl = (int)$r[1];
    ok($publish('release', $rl, $ed), "[$label] release published");
    $c = uni_correct($app, $ed2, 'event', $ev, ['summary' => 'corrected']); ok($c[0], "[$label] correct a verified event (new version, sources copied)");
    if ($c[0]) { ok($publish('event', (int)$c[1], $ed2), "[$label] corrected version published, predecessor superseded"); ok((string)val($adm, 'SELECT state FROM uni_event WHERE id = ?', [$ev]) === 'superseded', "[$label] predecessor is superseded"); }
    $c = uni_correct($app, $ed2, 'release', $rl, ['version_label' => '1.0.1']); ok($c[0] && $publish('release', (int)$c[1], $ed2), "[$label] corrected release published");
    $rej = uni_propose_event($app, $ed, ['entity_id' => $ent, 'event_type' => 'announcement', 'event_date' => '2024-07-07', 'title' => "Reject me $t"]); $rid = (int)$rej[1]['id']; uni_attach_source($app, $ed, 'event', $rid, $src()); uni_submit_for_review($app, $ed, 'event', $rid);
    $rr = uni_review($app, $v1, 'event', $rid, 'reject', 'no'); ok($rr[0] && $rr[1]['state'] === 'rejected', "[$label] reject path");
    ok(!uni_review($app, $ed, 'event', $rid, 'accept')[0], "[$label] an editor still cannot review");
    ok(uni_publish_state($app, 'event', $ev) !== null && is_array(uni_review_queue($app, $v1, 'event')), "[$label] staff-side state and queue reads");
    ok(uni_record_conflict($app, $ed, 'event', $ev, null, 'a conflict')[0], "[$label] conflict recorded (audit insert)");
    $evs = uni_public_events($app); $slugs = array_column($evs['items'], 'entity_slug');
    ok(in_array("co-$t", $slugs, true) && $evs['total'] >= 1, "[$label] public reads work for the application account");
    $pe = uni_public_events($pub); ok($pe['total'] === $evs['total'] && uni_public_entity($pub, "co-$t") !== null, "[$label] public reads work for the read-only account and agree");
    ok(count(uni_public_edges($pub)['items']) >= 1 && count(uni_public_releases($pub)['items']) >= 1 && uni_public_neighbourhood($pub, "co-$t")['center'] !== null, "[$label] edges, releases and the neighbourhood read for the read-only account");
    // triggers and the database: do they fire for an account without SUPER and without TRIGGER privilege?
    $ver = (int)val($adm, "SELECT id FROM uni_event WHERE state = 'verified' AND is_current = 1 AND title = ?", ["Launch $t"]);
    $tamper = errno($app, "UPDATE uni_event SET title = 'tampered' WHERE id = ?", [$ver]);
    if ($triggers) ok($tamper === 1644, "[$label] a raw UPDATE of a verified row by the application account is refused by the trigger (error " . var_export($tamper, true) . ')');
    else ok($tamper === null, "[$label] without triggers the same raw UPDATE succeeds: the PHP layer is the only guard (documented residual risk)");
    if (!$triggers) $adm->prepare("UPDATE uni_event SET title = ? WHERE id = ?")->execute(["Launch $t", $ver]);
    $rawReview = errno($app, "UPDATE uni_review SET note = 'x'");
    ok($rawReview !== null, "[$label] uni_review stays append-only for the app account whether or not triggers exist");
  };

  $profiles = ['app', 'appw'];
  $reset(false);
  foreach ($profiles as $p) { $denials($conn($p), "$p, no triggers", false, $p === 'app'); }
  foreach ($profiles as $p) $e2e($p, "$p/no-triggers", false);

  $reset(true);   // triggers installed by the admin account (DEFINER = that account)
  $adminDefiner = (string)val($adm, "SELECT DEFINER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'trg_uni_event_bu'");
  fwrite(STDERR, "   triggers installed by admin account, DEFINER = $adminDefiner\n");
  $denials($conn('app'), 'app, admin-installed triggers', true);
  foreach ($profiles as $p) $e2e($p, "$p/triggers-by-admin", true);

  // triggers installed by the (non-SUPER) migrator account
  $migPdo = $conn('mig'); $binlog = (string)val($adm, 'SELECT @@log_bin'); $trust = (string)val($adm, 'SELECT @@log_bin_trust_function_creators');
  $migSuper = false;
  applyFile($adm, $root . '/database/phase7_rollback.sql'); applyFile($adm, $root . '/database/phase7_universe.sql'); grants($admin, $db, $acct, $VERSIONED, $APPEND, $READABLE_PUBLIC); $migPdo = $conn('mig');
  $err = null; try { applyFile($migPdo, $root . '/database/phase7_triggers.sql'); } catch (PDOException $e) { $err = (int)($e->errorInfo[1] ?? 0); }
  fwrite(STDERR, "   migrator without SUPER: log_bin=$binlog log_bin_trust_function_creators=$trust, CREATE TRIGGER " . ($err === null ? 'succeeded' : "failed with error $err") . "\n");
  ok($err === null || $err === 1419, 'a non-SUPER migrator either installs the triggers or fails with the documented error 1419 (binary logging without SUPER): got ' . var_export($err, true));
  if ($err !== null) {                                                  // documented fallback: SUPER (global) for the installing account only, revoked again afterwards
    $admin->exec("GRANT SUPER ON *.* TO '{$acct['mig']}'@'%'"); $migSuper = true; $migPdo = $conn('mig');
    applyFile($adm, $root . '/database/phase7_rollback.sql'); applyFile($adm, $root . '/database/phase7_universe.sql'); grants($admin, $db, $acct, $VERSIONED, $APPEND, $READABLE_PUBLIC); $admin->exec("GRANT SUPER ON *.* TO '{$acct['mig']}'@'%'"); $migPdo = $conn('mig');
    $err2 = null; try { applyFile($migPdo, $root . '/database/phase7_triggers.sql'); } catch (PDOException $e) { $err2 = (int)($e->errorInfo[1] ?? 0); }
    ok($err2 === null, 'the migrator can install the triggers once it holds SUPER (error ' . var_export($err2, true) . ')');
  }
  $migDefiner = (string)val($adm, "SELECT DEFINER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'trg_uni_event_bu'");
  ok(strpos($migDefiner, $acct['mig']) === 0, "triggers carry the migrator as DEFINER ($migDefiner)");
  if ($migSuper) $admin->exec("REVOKE SUPER ON *.* FROM '{$acct['mig']}'@'%'");
  $denials($conn('app'), 'app, migrator-installed triggers', true);
  foreach ($profiles as $p) $e2e($p, "$p/triggers-by-migrator", true);

  // ------------------------------------------------------------------------------------------------------------------------- DEFINER hazard
  section('DEFINER hazard: the account that installed the triggers disappears');
  applyFile($adm, $root . '/database/phase7_rollback.sql'); applyFile($adm, $root . '/database/phase7_universe.sql'); grants($admin, $db, $acct, $VERSIONED, $APPEND, $READABLE_PUBLIC);
  if ($migSuper) $admin->exec("GRANT SUPER ON *.* TO '{$acct['mig2']}'@'%'");
  applyFile($conn('mig2'), $root . '/database/phase7_triggers.sql');
  if ($migSuper) $admin->exec("REVOKE SUPER ON *.* FROM '{$acct['mig2']}'@'%'");
  [$ok, $u1] = crm_user_create($adm, "dh_editor_$tag", 'DH Editor', 'editor', $H); $app = $conn('app');
  $good = uni_propose_entity($app, (int)$u1, ['entity_type' => 'company', 'slug' => 'dh-before', 'name' => 'Before']); ok($good[0], 'writes work while the DEFINER account exists');
  $admin->exec("DROP USER '{$acct['mig2']}'@'%'");
  $broken = errno($app, "UPDATE uni_entity SET name = 'x' WHERE slug = 'dh-before'");
  $broken2 = uni_propose_entity($app, (int)$u1, ['entity_type' => 'company', 'slug' => 'dh-after', 'name' => 'After']);
  ok($broken === 1449, 'after the DEFINER account is dropped a raw write fails with error 1449 (' . var_export($broken, true) . ')');
  ok($broken2[0] === false && $broken2[1] === 'error', 'the service layer reports a clean generic failure, not a PHP fatal (' . json_encode($broken2) . ')');
  $admin->exec("CREATE USER '{$acct['mig2']}'@'%' IDENTIFIED BY " . $admin->quote($PW)); $admin->exec("GRANT ALL PRIVILEGES ON `$db`.* TO '{$acct['mig2']}'@'%'");
  if ($migSuper) $admin->exec("GRANT SUPER ON *.* TO '{$acct['mig2']}'@'%'");
  applyFile($conn('mig2'), $root . '/database/phase7_triggers.sql');   // reinstalling (idempotent DROP IF EXISTS + CREATE) repairs it
  if ($migSuper) $admin->exec("REVOKE SUPER ON *.* FROM '{$acct['mig2']}'@'%'");
  ok(uni_propose_entity($app, (int)$u1, ['entity_type' => 'company', 'slug' => 'dh-repaired', 'name' => 'Repaired'])[0], 'reinstalling the triggers under an existing account repairs it');

  // ------------------------------------------------------------------------------------------------------------------------- importer
  $importerChecks = function (string $label) use ($conn, $adm, $H, $acct) {
    section("importer account ($label)");
    $imp = $conn('imp'); static $k = 0; $k++;
    [$o, $uid] = crm_user_create($adm, "imp_ed_{$label}_$k", 'Importer Editor', 'editor', $H); $uid = (int)$uid;
    [$o2, $vid] = crm_user_create($adm, "imp_ver_{$label}_$k", 'Importer Verifier', 'verifier', $H); $vid = (int)$vid;
    $lk = bin2hex(random_bytes(16));
    allowed($imp, "INSERT INTO uni_source (url, publisher, retrieved_at, quote, origin, created_by, created_at) VALUES ('https://example.test/imp', 'Imp', '2025-01-01 00:00:00', 'q', 'import', ?, NOW())", 'importer can INSERT a source (foreign keys need no REFERENCES or SELECT privilege)', [$uid]);
    allowed($imp, "INSERT INTO uni_entity (entity_type, slug, name, summary, origin, proposed_by, proposed_at, lineage_key) VALUES ('company', ?, 'Imported', '', 'import', ?, NOW(), ?)", 'importer can INSERT an import-origin entity (state defaults to proposed)', ["imp-$label-$k", $uid, $lk]);
    ok((string)val($adm, 'SELECT state FROM uni_entity WHERE lineage_key = ?', [$lk]) === 'proposed', 'the imported row is proposed, never verified');
    denied($imp, 'SELECT COUNT(*) FROM uni_entity', 'importer cannot SELECT');
    denied($imp, "UPDATE uni_entity SET name = 'x'", 'importer cannot UPDATE');
    denied($imp, 'DELETE FROM uni_entity', 'importer cannot DELETE');
    denied($imp, 'TRUNCATE TABLE uni_entity', 'importer cannot TRUNCATE');
    denied($imp, "INSERT INTO uni_review (target_type, target_id, reviewer_id, proposed_by, decision, decided_at) VALUES ('entity', 1, $vid, $uid, 'accept', NOW())", 'importer cannot INSERT reviews');
    denied($imp, "INSERT INTO uni_audit_log (at, action) VALUES (NOW(), 'x')", 'importer cannot write the audit log');
    $e = errno($imp, "INSERT INTO uni_entity (entity_type, slug, name, summary, origin, proposed_by, proposed_at, lineage_key, state) VALUES ('company', ?, 'Sneaky', '', 'human', ?, NOW(), ?, 'in_review')", ["imp-s-$label-$k", $uid, bin2hex(random_bytes(16))]);
    ok($e === 1143 || $e === 1142, "column-level grant: the importer cannot write the state column (error " . var_export($e, true) . ')');
    $e = errno($imp, "INSERT INTO uni_entity (entity_type, slug, name, summary, origin, proposed_by, proposed_at, lineage_key, review_id, verified_by, verified_at) VALUES ('company', ?, 'Sneaky2', '', 'human', ?, NOW(), ?, 1, ?, NOW())", ["imp-v-$label-$k", $uid, bin2hex(random_bytes(16)), $vid]);
    ok($e === 1143 || $e === 1142, "column-level grant: the importer cannot write verification columns (error " . var_export($e, true) . ')');
    ok((int)val($adm, "SELECT COUNT(*) FROM uni_entity WHERE name LIKE 'Sneaky%'") === 0, 'no row slipped in through the refused statements');
  };
  $reset(false); $importerChecks('no-triggers');
  $reset(true); $importerChecks('triggers');

  // ------------------------------------------------------------------------------------------------------------------------- public account
  section('public read-only account');
  $reset(false); $pub = $conn('pub');
  [$o, $pu] = crm_user_create($adm, "pub_ed_$tag", 'Pub Editor', 'editor', $H);
  foreach (['uni_entity', 'uni_event', 'uni_edge', 'uni_release', 'uni_source', 'uni_entity_source', 'uni_review', 'uni_audit_log'] as $t) { denied($pub, "DELETE FROM $t", "public cannot DELETE $t"); denied($pub, "TRUNCATE TABLE $t", "public cannot TRUNCATE $t"); }
  denied($pub, "INSERT INTO uni_entity (entity_type, slug, name, origin, proposed_by, proposed_at, lineage_key) VALUES ('company','x','x','human',1,NOW(),'k')", 'public cannot INSERT');
  denied($pub, "UPDATE uni_entity SET name = 'x'", 'public cannot UPDATE');
  denied($pub, 'SELECT * FROM uni_review LIMIT 1', 'public cannot read reviews (who reviewed what)');
  denied($pub, 'SELECT * FROM uni_audit_log LIMIT 1', 'public cannot read the audit log');
  denied($pub, 'SELECT * FROM crm_users LIMIT 1', 'public cannot read users');
  allowed($pub, 'SELECT COUNT(*) FROM uni_entity', 'public can SELECT the public-read tables');

  // ------------------------------------------------------------------------------------------------------------------------- migrator
  section('migrator account');
  $mg = $conn('mig');
  $adm->exec("INSERT INTO uni_audit_log (at, action) VALUES (NOW(), 'p7test')");
  allowed($mg, 'TRUNCATE TABLE uni_audit_log', 'the migrator (holder of DROP) CAN truncate a leaf table: keep it out of application config');
  $e = errno($mg, 'TRUNCATE TABLE uni_entity'); ok($e === 1701, 'even the migrator cannot TRUNCATE a table referenced by a foreign key (error 1701): FK restrict is a second barrier for parent tables, not for leaf tables (' . var_export($e, true) . ')');
  fwrite(STDERR, "   NOT run: MySQL 8.4, MariaDB, binlog-replicated setups, managed-hosting privilege models (see docs/phase7/DB_PRIVILEGE_MODEL.md)\n");
} finally {
  try { $admin->exec("DROP DATABASE IF EXISTS `$db`"); } catch (Throwable $e) { fwrite(STDERR, 'cleanup db: ' . $e->getMessage() . "\n"); }
  dropAccounts($admin, $acct);
}
$left = (int)val($admin, "SELECT COUNT(*) FROM mysql.user WHERE user LIKE ?", ["p7%\\_$tag"]);
ok($left === 0, 'all scratch accounts were dropped');
$out = ob_get_clean(); echo $out; echo "\nPASSED: $pass  FAILED: $fail\n"; exit($fail ? 1 : 0);
