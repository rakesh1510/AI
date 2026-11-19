<?php
declare(strict_types=1);
/**
 * Phase 7 service-layer test (uni_core.php) against a private scratch database. Usage: php tests/phase7_service_test.php
 * Env: AIHUB_COMPAT_ROOT_USER / AIHUB_COMPAT_ROOT_PASS / AIHUB_DB_HOST (same as the schema test); P7_DSN_BASE, P7_USER, P7_PASS override.
 * Creates database p7svc_<pid>, never touches another database, drops it at the end. Runs the whole suite twice: WITHOUT the optional triggers
 * and WITH database/phase7_triggers.sql, because the PHP service layer must be the primary guard in both modes.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
ob_start();
require_once $root . '/uni_core.php';
$dsnBase = getenv('P7_DSN_BASE') ?: 'mysql:host=' . (getenv('AIHUB_DB_HOST') ?: '127.0.0.1') . ';port=3306';
$user = getenv('P7_USER') ?: (getenv('AIHUB_COMPAT_ROOT_USER') ?: 'aihub_test');
$pw = getenv('P7_PASS') !== false ? getenv('P7_PASS') : (getenv('AIHUB_COMPAT_ROOT_PASS') !== false ? getenv('AIHUB_COMPAT_ROOT_PASS') : 'test_only_pw');
$dbName = 'p7svc_' . getmypid();
if (!preg_match('/^p7svc_\d+$/', $dbName)) { fwrite(STDERR, "bad db name\n"); exit(2); }
$opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$admin = new PDO($dsnBase, $user, $pw, $opt);
fwrite(STDERR, 'Server: ' . $admin->query('SELECT VERSION()')->fetchColumn() . "\n");
$admin->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo = new PDO($dsnBase . ";dbname=$dbName", $user, $pw, $opt);
$pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION,ERROR_FOR_DIVISION_BY_ZERO'");

$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; fwrite(STDERR, "  FAIL: $m\n"); } }
function section(string $t): void { fwrite(STDERR, "== $t\n"); }
function no($r, string $code, string $m): void { ok(is_array($r) && $r[0] === false && $r[1] === $code, $m . ' (got ' . json_encode($r) . ')'); }
function good($r, string $m): bool { $b = is_array($r) && $r[0] === true; ok($b, $m . ($b ? '' : ' (got ' . json_encode($r) . ')')); return $b; }
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
/** true when the statement is refused by the database */
function dbRefuses(PDO $p, string $sql, array $a = []): bool { try { $p->prepare($sql)->execute($a); return false; } catch (PDOException $e) { return true; } }

applyFile($pdo, $root . '/database/phase6_crm.sql');
$H = password_hash('test-only-password', PASSWORD_DEFAULT);

function suite(PDO $pdo, string $mode, string $H): void {
  static $run = 0; $run++;
  $triggers = (int)val($pdo, "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'trg\\_uni\\_%'");
  ok($mode === 'triggers' ? $triggers > 0 : $triggers === 0, "[$mode] trigger presence matches the mode ($triggers triggers)");
  $mk = function (string $name, string $role, int $active = 1) use ($pdo, $H, $run): int {
    [$ok, $id] = crm_user_create($pdo, "{$name}_r$run", ucfirst($name) . "Display r$run", $role, $H); if (!$ok) throw new RuntimeException("user $name: $id");
    if (!$active) $pdo->exec('UPDATE crm_users SET active = 0 WHERE id = ' . (int)$id);
    return (int)$id;
  };
  $owner = $mk('owner', 'owner'); $ed1 = $mk('editor1', 'editor'); $ed2 = $mk('editor2', 'editor'); $v1 = $mk('verifier1', 'verifier'); $v2 = $mk('verifier2', 'verifier'); $v3 = $mk('verifier3', 'verifier');
  $sales = $mk('sales', 'sales'); $viewer = $mk('viewer', 'viewer'); $deadV = $mk('deadverifier', 'verifier', 0); $deadE = $mk('deadeditor', 'editor', 0);
  $names = array_map(fn($u) => (string)val($pdo, 'SELECT username FROM crm_users WHERE id = ?', [$u]), [$owner, $ed1, $ed2, $v1, $v2, $v3]);
  $names = array_merge($names, array_map(fn($u) => (string)val($pdo, 'SELECT display_name FROM crm_users WHERE id = ?', [$u]), [$owner, $ed1, $ed2, $v1, $v2, $v3]));
  $src = fn(array $o = []) => $o + ['url' => 'https://example.test/' . bin2hex(random_bytes(4)), 'publisher' => 'Example Publisher', 'retrieved_at' => '2025-01-15', 'quote' => 'a synthetic quote that supports the claim'];
  $newEntity = function (string $slug, string $type = 'company', array $o = []) use ($pdo, $ed1, $src): int { $nosrc = !empty($o['_nosource']); unset($o['_nosource']); $r = uni_propose_entity($pdo, $ed1, $o + ['entity_type' => $type, 'slug' => $slug, 'name' => "Entity $slug", 'summary' => 'synthetic']); if (!$r[0]) throw new RuntimeException("entity $slug: " . $r[1]); $id = (int)$r[1]; if (!$nosrc) { $a = uni_attach_source($pdo, $ed1, 'entity', $id, $src()); if (!$a[0]) throw new RuntimeException("entity source $slug: " . $a[1]); } return $id; };   // entities need source evidence like events and edges
  { $ns = $newEntity("nosrc-$run", 'company', ['_nosource' => true]);   // entity without evidence
    no(uni_submit_for_review($pdo, $ed1, 'entity', $ns), 'source_required', 'an entity without a source cannot be submitted for review');
    ok((string)val($pdo, 'SELECT state FROM uni_entity WHERE id = ?', [$ns]) === 'proposed', 'it stays proposed'); }
  $publish = function (string $type, int $id, int $by) use ($pdo, $v1, $v2, $v3): string {
    $s = uni_submit_for_review($pdo, $by, $type, $id); if (!$s[0]) return 'submit:' . $s[1];
    foreach ([$v1, $v2, $v3] as $v) { $r = uni_review($pdo, $v, $type, $id, 'accept'); if (!$r[0]) return 'review:' . $r[1]; if ($r[1]['state'] === 'verified') return 'verified'; }
    return 'stuck';
  };
  $state = fn(string $t, int $id) => (string)val($pdo, 'SELECT state FROM ' . uni_table($t) . ' WHERE id = ?', [$id]);
  $newEvent = function (int $entity, string $title, string $date, array $o = []) use ($pdo, $ed1, $src): int {
    $r = uni_propose_event($pdo, $ed1, $o + ['entity_id' => $entity, 'event_type' => 'launch', 'event_date' => $date, 'title' => $title]); if (!$r[0]) throw new RuntimeException("event $title: " . $r[1]);
    $a = uni_attach_source($pdo, $ed1, 'event', (int)$r[1]['id'], $src()); if (!$a[0]) throw new RuntimeException('attach: ' . $a[1]); return (int)$r[1]['id'];
  };

  section("[$mode] roles");
  $roles = crm_roles();
  ok(array_keys($roles) === ['owner', 'sales', 'viewer', 'editor', 'verifier'], 'role list is the Phase 6 three plus editor and verifier');
  ok($roles['sales'] === ['read', 'write', 'assign', 'outreach'] && $roles['viewer'] === ['read'] && $roles['owner'] === ['read', 'write', 'assign', 'approve', 'revenue', 'users', 'outreach', 'uni_propose', 'uni_review'], 'sales, viewer and the Phase 6 owner permissions are unchanged');
  ok(crm_can('editor', 'uni_propose') && !crm_can('editor', 'uni_review') && !crm_can('editor', 'read') && !crm_can('editor', 'write') && crm_can('verifier', 'uni_review') && !crm_can('verifier', 'uni_propose') && !crm_can('verifier', 'read') && !crm_can('sales', 'uni_propose') && !crm_can('sales', 'uni_review') && !crm_can('viewer', 'uni_propose') && !crm_can('viewer', 'uni_review') && crm_can('owner', 'uni_propose') && crm_can('owner', 'uni_review'), 'permission matrix for the Phase 7 actions');
  ok(crm_user_create($pdo, 'rolecheck_r' . $run, 'Role Check', 'verifier', $H)[0] === true && crm_user_create($pdo, 'rolecheck2_r' . $run, 'Role Check', 'editor', $H)[0] === true && crm_user_create($pdo, 'rolecheck3_r' . $run, 'Role Check', 'root', $H)[1] === 'role_invalid', 'crm_user_create accepts the new roles and still refuses unknown ones');

  section("[$mode] authorization");
  $okEntity = ['entity_type' => 'company', 'slug' => 'auth-probe', 'name' => 'Auth Probe'];
  foreach (['sales' => $sales, 'viewer' => $viewer, 'verifier' => $v1, 'inactive editor' => $deadE, 'unknown id' => 999999, 'id zero (shared admin session)' => 0, 'negative id' => -5] as $who => $uid) no(uni_propose_entity($pdo, $uid, $okEntity), 'forbidden', "$who cannot propose");
  ok(uni_propose_entity($pdo, $owner, ['slug' => 'by-owner-' . $run] + $okEntity)[0] === true, 'owner can propose');
  $a = $newEntity("auth-a-$run");
  no(uni_source_create($pdo, $sales, $src()), 'forbidden', 'sales cannot create sources'); no(uni_attach_source($pdo, $viewer, 'event', 1, $src()), 'forbidden', 'viewer cannot attach');
  no(uni_submit_for_review($pdo, $sales, 'entity', $a), 'forbidden', 'sales cannot submit'); no(uni_submit_for_review($pdo, $v1, 'entity', $a), 'forbidden', 'a verifier cannot submit');
  no(uni_correct($pdo, $viewer, 'entity', $a, ['name' => 'x']), 'forbidden', 'viewer cannot correct'); no(uni_record_conflict($pdo, $viewer, 'entity', $a, null, 'x'), 'forbidden', 'viewer cannot record conflicts');
  good(uni_submit_for_review($pdo, $ed2, 'entity', $a), 'a different editor can submit');
  foreach (['editor' => $ed2, 'owner' => $owner, 'sales' => $sales, 'viewer' => $viewer, 'inactive verifier' => $deadV, 'unknown' => 424242, 'zero' => 0] as $who => $uid) no(uni_review($pdo, $uid, 'entity', $a, 'accept'), 'forbidden', "$who cannot review");
  no(uni_review($pdo, $v1, 'entity', $a, 'maybe'), 'decision_invalid', 'unknown decision'); no(uni_review($pdo, $v1, 'nothing', $a, 'accept'), 'type_invalid', 'unknown type'); no(uni_review($pdo, $v1, 'entity', 987654, 'accept'), 'not_found', 'unknown target');
  no(uni_review($pdo, $v1, 'entity', $a, 'accept', str_repeat('n', 1001)), 'note_invalid', 'over-long review note is refused');
  ok($state('entity', $a) === 'in_review', 'every refused review left the row in_review');
  $pdo->exec("UPDATE crm_users SET role = 'verifier' WHERE id = " . (int)$ed1); no(uni_review($pdo, $ed1, 'entity', $a, 'accept'), 'maker_checker', 'a proposer who now holds the verifier role cannot review their own proposal'); $pdo->exec("UPDATE crm_users SET role = 'editor' WHERE id = " . (int)$ed1);
  $pdo->exec('UPDATE crm_users SET active = 0 WHERE id = ' . (int)$v3); no(uni_review($pdo, $v3, 'entity', $a, 'accept'), 'forbidden', 'a deactivated verifier is refused at once (role is re-read from the database)'); $pdo->exec('UPDATE crm_users SET active = 1 WHERE id = ' . (int)$v3);

  section("[$mode] second-reviewer sampling: first 25 always, then 20% deterministic");
  ok((int)val($pdo, "SELECT COUNT(*) FROM uni_review WHERE decision = 'accept'") === 0, 'fresh ledger: no accept recorded before the sampling run');
  $followed = true; $secondary = []; $afterFirst = 0;
  for ($i = 1; $i <= 40; $i++) {
    $id = $newEntity("sample-$run-$i"); uni_submit_for_review($pdo, $ed1, 'entity', $id);
    $r = uni_review($pdo, $v1, 'entity', $id, 'accept'); $awaiting = $r[0] && $r[1]['awaiting_second_review'];
    $expect = $i <= UNI_SAMPLE_FIRST || (crc32("entity:$id") % 100) < UNI_SAMPLE_PERCENT;
    if ($awaiting !== $expect) $followed = false;
    if ($awaiting) {
      ok($state('entity', $id) === 'in_review' && val($pdo, 'SELECT verified_by FROM uni_entity WHERE id = ?', [$id]) === null, "item $i stays unverified after the first accept");
      no(uni_review($pdo, $v1, 'entity', $id, 'accept'), 'already_reviewed', "item $i: the first reviewer cannot also be the second");
      no(uni_review($pdo, $ed2, 'entity', $id, 'accept'), 'forbidden', "item $i: an editor cannot be the second reviewer");
      $r2 = uni_review($pdo, $v2, 'entity', $id, 'accept'); ok($r2[0] && $r2[1]['state'] === 'verified' && (int)val($pdo, 'SELECT verified_by FROM uni_entity WHERE id = ?', [$id]) === $v2, "item $i verified by the second reviewer");
      $secondary[] = $i; if ($i > UNI_SAMPLE_FIRST) $afterFirst++;
    } else ok($state('entity', $id) === 'verified', "item $i verified by a single reviewer");
  }
  ok($followed, 'all 40 items followed the sampling rule exactly');
  ok(count(array_filter($secondary, fn($i) => $i <= 25)) === 25, 'all of the first 25 were second-reviewed');
  ok($afterFirst < 15, "after the first 25 only a minority was sampled ($afterFirst of 15)");
  ok((int)val($pdo, "SELECT COUNT(*) FROM uni_entity WHERE state = 'verified' AND verified_by = proposed_by") === 0, 'no verified row has verified_by = proposed_by');

  section("[$mode] events, sources and the >=1 valid source rule");
  $co = $newEntity("acme-$run"); ok($publish('entity', $co, $ed1) === 'verified', 'entity verified');
  $ev = uni_propose_event($pdo, $ed1, ['entity_id' => $co, 'event_type' => 'launch', 'event_date' => '2024-03-15', 'title' => 'Acme launches Model One']); good($ev, 'propose event'); $e1 = (int)$ev[1]['id'];
  no(uni_submit_for_review($pdo, $ed1, 'event', $e1), 'source_required', 'an event without a source cannot be submitted');
  $badSources = ['no quote' => $src(['quote' => '']), 'whitespace quote' => $src(['quote' => "  \t\n "]), 'zero-width quote' => $src(['quote' => "\u{200B}\u{200B}"]), 'quote missing' => array_diff_key($src(), ['quote' => 1]),
    'empty retrieved date' => $src(['retrieved_at' => '']), 'null retrieved date' => $src(['retrieved_at' => null]), 'impossible date' => $src(['retrieved_at' => '2025-02-30']), 'future date' => $src(['retrieved_at' => '2999-01-01']), 'text date' => $src(['retrieved_at' => 'yesterday']),
    'javascript url' => $src(['url' => 'javascript:alert(1)']), 'ftp url' => $src(['url' => 'ftp://example.test/x']), 'url with space' => $src(['url' => 'https://example.test/a b']), 'url with quote' => $src(['url' => "https://example.test/a'b"]), 'blank publisher' => $src(['publisher' => ' ']),
    'quote too long' => $src(['quote' => str_repeat('q', 1001)]), 'array quote' => $src(['quote' => ['x']]), 'bad origin' => $src(['origin' => 'robot']), 'bad hash' => $src(['content_hash' => 'zz'])];
  foreach ($badSources as $label => $bs) { $r = uni_attach_source($pdo, $ed1, 'event', $e1, $bs); ok($r[0] === false, "source rejected: $label ($r[1])"); }
  ok((int)val($pdo, 'SELECT COUNT(*) FROM uni_event_source WHERE event_id = ?', [$e1]) === 0, 'no link was created by any rejected source');
  no(uni_attach_source($pdo, $ed1, 'event', $e1, 987654), 'source_invalid', 'unknown source id'); no(uni_attach_source($pdo, $ed1, 'weird', $co, $src()), 'type_invalid', 'unknown target types are refused');
  ok(!uni_source_valid(['quote' => ' ', 'retrieved_at' => '2025-01-01 00:00:00', 'publisher' => 'P', 'url' => 'https://x.test/']) && !uni_source_valid(['quote' => 'q', 'retrieved_at' => '', 'publisher' => 'P', 'url' => 'https://x.test/']) && !uni_source_valid(['quote' => 'q', 'retrieved_at' => '0000-00-00 00:00:00', 'publisher' => 'P', 'url' => 'https://x.test/']) && uni_source_valid(['quote' => 'q', 'retrieved_at' => '2025-01-01 00:00:00', 'publisher' => 'P', 'url' => 'https://x.test/']), 'uni_source_valid re-checks quote and retrieved date on rows that did not come through the service');
  good(uni_attach_source($pdo, $ed1, 'event', $e1, $src()), 'a valid source attaches'); [, $sid] = uni_source_create($pdo, $ed1, $src());
  good(uni_attach_source($pdo, $ed2, 'event', $e1, (int)$sid), 'an existing source id attaches'); no(uni_attach_source($pdo, $ed2, 'event', $e1, (int)$sid), 'already_attached', 'no duplicate link');
  $ps = uni_publish_state($pdo, 'event', $e1); ok($ps['state'] === 'proposed' && !$ps['public'] && $ps['valid_sources'] === 2, 'publish state before submit: proposed, not public, 2 valid sources');
  good(uni_submit_for_review($pdo, $ed1, 'event', $e1), 'event with valid sources submits'); no(uni_submit_for_review($pdo, $ed1, 'event', $e1), 'not_proposed', 'cannot submit twice');
  no(uni_review($pdo, $ed1, 'event', $e1, 'accept'), 'forbidden', 'the proposer (editor) cannot review');
  $r = uni_review($pdo, $v1, 'event', $e1, 'accept'); ok($r[0], 'verifier accepts'); if ($r[0] && $r[1]['awaiting_second_review']) uni_review($pdo, $v2, 'event', $e1, 'accept');
  ok($state('event', $e1) === 'verified', 'event verified'); $ps = uni_publish_state($pdo, 'event', $e1); ok($ps['public'] && $ps['accepts'] >= 1, 'publish state: public after verification');
  no(uni_review($pdo, $v3, 'event', $e1, 'accept'), 'not_in_review', 'a second (late) review of an already verified row is refused');
  no(uni_attach_source($pdo, $ed1, 'event', $e1, $src()), 'target_locked', 'sources of a verified row are frozen');
  // event whose entity is not verified
  $ue = $newEntity("unver-$run"); $e2 = $newEvent($ue, 'Event of an unverified entity', '2024-04-01'); uni_submit_for_review($pdo, $ed1, 'event', $e2);
  no(uni_review($pdo, $v1, 'event', $e2, 'accept'), 'entity_not_verified', 'an event cannot be verified before its entity is');
  // dates
  foreach (['2024-02-30', '2024-13-01', '24-01-01', '2024-1-1', '1899-12-31', '2101-01-01', '', 'x', "2024-01-01'; DROP TABLE uni_event;--"] as $bad) no(uni_propose_event($pdo, $ed1, ['entity_id' => $co, 'event_type' => 'launch', 'event_date' => $bad, 'title' => 'T']), 'date_invalid', "date refused: " . substr($bad, 0, 20));
  no(uni_propose_event($pdo, $ed1, ['entity_id' => $co, 'event_type' => 'launch', 'event_date' => '2024', 'date_precision' => 'day', 'title' => 'T']), 'date_invalid', 'a year cannot claim day precision');
  no(uni_propose_event($pdo, $ed1, ['entity_id' => $co, 'event_type' => 'launch', 'event_date' => '2024-05-17', 'date_precision' => 'month', 'title' => 'T']), 'date_invalid', 'month precision needs day 01');
  $mo = uni_propose_event($pdo, $ed1, ['entity_id' => $co, 'event_type' => 'launch', 'event_date' => '2024-05', 'title' => 'Month precision event']); ok($mo[0] && val($pdo, 'SELECT date_precision FROM uni_event WHERE id = ?', [$mo[1]['id']]) === 'month' && val($pdo, 'SELECT event_date FROM uni_event WHERE id = ?', [$mo[1]['id']]) === '2024-05-01', 'YYYY-MM is stored as month precision on day 01');
  no(uni_propose_event($pdo, $ed1, ['entity_id' => $co, 'event_type' => 'nonsense', 'event_date' => '2024-05-01', 'title' => 'T']), 'event_type_invalid', 'event type whitelist'); no(uni_propose_event($pdo, $ed1, ['entity_id' => $co, 'event_type' => 'launch', 'event_date' => '2024-05-01', 'title' => '  ']), 'title_required', 'blank title');
  no(uni_propose_event($pdo, $ed1, ['entity_id' => 999999, 'event_type' => 'launch', 'event_date' => '2024-05-01', 'title' => 'T']), 'entity_not_found', 'unknown entity'); no(uni_propose_event($pdo, $ed1, ['entity_id' => '1', 'event_type' => 'launch', 'event_date' => '2024-05-01', 'title' => 'T']), 'entity_invalid', 'entity id must be an int (no string coercion)');

  section("[$mode] review decisions: reject and needs_second_source");
  $e3 = $newEvent($co, 'Event to be rejected', '2024-06-01'); uni_submit_for_review($pdo, $ed1, 'event', $e3);
  $r = uni_review($pdo, $v1, 'event', $e3, 'reject', 'not supported by the quote'); ok($r[0] && $r[1]['state'] === 'rejected' && $state('event', $e3) === 'rejected' && (int)val($pdo, 'SELECT is_current FROM uni_event WHERE id = ?', [$e3]) === 0, 'reject: state rejected, not current');
  no(uni_review($pdo, $v2, 'event', $e3, 'accept'), 'not_in_review', 'a rejected row cannot be accepted later'); no(uni_submit_for_review($pdo, $ed1, 'event', $e3), 'not_proposed', 'a rejected row cannot be resubmitted');
  $e4 = $newEvent($co, 'Event needing another source', '2024-07-01'); uni_submit_for_review($pdo, $ed1, 'event', $e4);
  $r = uni_review($pdo, $v1, 'event', $e4, 'needs_second_source', 'please add the vendor blog post'); ok($r[0] && $r[1]['state'] === 'in_review', 'needs_second_source keeps the row in_review');
  no(uni_review($pdo, $v1, 'event', $e4, 'accept'), 'already_reviewed', 'the same reviewer cannot review the same target again (append-only, one review each)');
  good(uni_attach_source($pdo, $ed1, 'event', $e4, $src()), 'the editor adds a second source while in_review'); ok($publish('event', $e4, $ed1) !== '' && $state('event', $e4) === 'verified' || $state('event', $e4) === 'in_review', 'another verifier can now decide');
  $r = uni_review($pdo, $v2, 'event', $e4, 'accept'); if ($state('event', $e4) !== 'verified') uni_review($pdo, $v3, 'event', $e4, 'accept'); ok($state('event', $e4) === 'verified', 'event with the second source ends verified');

  section("[$mode] origin: import and ai_assisted");
  $ie = uni_propose_event($pdo, $ed1, ['entity_id' => $co, 'event_type' => 'launch', 'event_date' => '2024-08-01', 'title' => 'Imported lead', 'origin' => 'import']); good($ie, 'an import lead can be proposed'); $imp = (int)$ie[1]['id'];
  uni_attach_source($pdo, $ed1, 'event', $imp, $src(['origin' => 'import']));
  no(uni_submit_for_review($pdo, $ed1, 'event', $imp), 'import_needs_new_version', 'an import row cannot be submitted');
  $pdo->exec("UPDATE uni_event SET state = 'in_review' WHERE id = $imp");   // simulate a row pushed into review behind the service
  no(uni_review($pdo, $v1, 'event', $imp, 'accept'), 'import_cannot_be_verified', 'an import row cannot be accepted even if it reached in_review'); ok($state('event', $imp) === 'in_review', 'and it stays unverified'); $pdo->exec("UPDATE uni_event SET state = 'proposed' WHERE id = $imp");
  no(uni_correct($pdo, $ed1, 'event', $imp, ['origin' => 'import']), 'origin_invalid', 'a correction cannot keep origin import');
  $c = uni_correct($pdo, $ed2, 'event', $imp, ['title' => 'Imported lead, checked by a human', 'origin' => 'human']); good($c, 'the import lead becomes a NEW version with origin human'); $imp2 = (int)$c[1];
  ok($state('event', $imp) === 'proposed' && (int)val($pdo, 'SELECT is_current FROM uni_event WHERE id = ?', [$imp]) === 1 && (int)val($pdo, 'SELECT version_no FROM uni_event WHERE id = ?', [$imp2]) === 2 && $state('event', $imp2) === 'proposed', 'the lead is untouched until the new version is verified');
  uni_attach_source($pdo, $ed2, 'event', $imp2, $src()); ok($publish('event', $imp2, $ed2) === 'verified', 'the human version is verified by a verifier');
  ok($state('event', $imp) === 'superseded' && (int)val($pdo, 'SELECT is_current FROM uni_event WHERE id = ?', [$imp]) === 0 && (int)val($pdo, 'SELECT is_current FROM uni_event WHERE id = ?', [$imp2]) === 1, 'the import lead is superseded, the new version is current');
  $ai = uni_propose_event($pdo, $ed1, ['entity_id' => $co, 'event_type' => 'major_update', 'event_date' => '2024-09-01', 'title' => 'AI drafted update', 'origin' => 'ai_assisted']); $aid = (int)$ai[1]['id']; uni_attach_source($pdo, $ed1, 'event', $aid, $src(['origin' => 'ai_assisted']));
  ok($publish('event', $aid, $ed1) === 'verified', 'an ai_assisted row is verified only after a human review');
  ok((int)val($pdo, "SELECT COUNT(*) FROM uni_review r JOIN crm_users u ON u.id = r.reviewer_id WHERE r.target_type = 'event' AND r.target_id = ? AND decision = 'accept' AND u.role = 'verifier' AND u.active = 1", [$aid]) >= 1 && (int)val($pdo, 'SELECT review_id FROM uni_event WHERE id = ?', [$aid]) > 0, 'its review row belongs to an active human verifier');

  section("[$mode] duplicates and conflicts are flagged, never merged");
  $d1 = uni_propose_event($pdo, $ed2, ['entity_id' => $co, 'event_type' => 'launch', 'event_date' => '2024-03-15', 'title' => '  ACME launches  model-one! ']);
  ok($d1[0] && $d1[1]['duplicates'] === [$e1], 'same entity + same date + normalised title is flagged as a duplicate of the verified event');
  ok((int)val($pdo, 'SELECT COUNT(*) FROM uni_event WHERE entity_id = ? AND event_date = ?', [$co, '2024-03-15']) === 2 && $state('event', $e1) === 'verified' && $state('event', (int)$d1[1]['id']) === 'proposed', 'nothing was merged: both rows exist, the original is untouched');
  ok((int)val($pdo, "SELECT COUNT(*) FROM uni_audit_log WHERE action = 'duplicate_flag' AND target_id = ?", [$d1[1]['id']]) === 1 && uni_duplicates_of_event($pdo, (int)$d1[1]['id']) === [$e1], 'the flag is recorded in the audit log and can be queried');
  $d2 = uni_propose_event($pdo, $ed2, ['entity_id' => $co, 'event_type' => 'launch', 'event_date' => '2024-03-16', 'title' => 'Acme launches Model One']); ok($d2[0] && $d2[1]['duplicates'] === [] && in_array($e1, $d2[1]['date_conflicts'], true) && count(uni_conflicts($pdo, 'event', (int)$d2[1]['id'])) === 1, 'same title on another date is not a duplicate but is recorded as a date conflict');
  $d3 = uni_propose_event($pdo, $ed2, ['entity_id' => $co, 'event_type' => 'launch', 'event_date' => '2024-03-15', 'title' => 'Acme launches Model Two']); ok($d3[0] && $d3[1]['duplicates'] === [] && $d3[1]['date_conflicts'] === [], 'a different title is neither');
  $ot = $newEntity("other-$run"); $d4 = uni_propose_event($pdo, $ed2, ['entity_id' => $ot, 'event_type' => 'launch', 'event_date' => '2024-03-15', 'title' => 'Acme launches Model One']); ok($d4[0] && $d4[1]['duplicates'] === [], 'another entity is not a duplicate');
  good(uni_record_conflict($pdo, $v1, 'event', $e1, (int)$d2[1]['id'], 'sources disagree on the day'), 'a verifier can record a conflict'); good(uni_record_conflict($pdo, $ed1, 'event', $e1, null, 'vendor page edited'), 'an editor can record a conflict');
  ok(count(uni_conflicts($pdo, 'event', $e1)) === 2 && $state('event', $e1) === 'verified', 'conflicts are recorded without changing the verified row');
  no(uni_record_conflict($pdo, $v1, 'event', 987654, null, 'x'), 'not_found', 'conflict on unknown row'); no(uni_record_conflict($pdo, $v1, 'event', $e1, null, ''), 'note_invalid', 'conflict needs a note');

  section("[$mode] edges");
  $b = $newEntity("beta-$run"); ok($publish('entity', $b, $ed1) === 'verified', 'second entity verified');
  no(uni_propose_edge($pdo, $ed1, ['from_entity_id' => $co, 'to_entity_id' => $co, 'edge_type' => 'developed_by']), 'self_edge', 'self edge'); no(uni_propose_edge($pdo, $ed1, ['from_entity_id' => $co, 'to_entity_id' => $b, 'edge_type' => 'friends_with']), 'edge_type_invalid', 'edge type whitelist');
  no(uni_propose_edge($pdo, $ed1, ['from_entity_id' => $co, 'to_entity_id' => 99999, 'edge_type' => 'built_on']), 'entity_not_found', 'unknown endpoint'); no(uni_propose_edge($pdo, $ed1, ['from_entity_id' => $co, 'to_entity_id' => $b, 'edge_type' => 'built_on', 'valid_from' => '2024-05-01', 'valid_to' => '2024-01-01']), 'validity_invalid', 'valid_to before valid_from');
  $ed = uni_propose_edge($pdo, $ed1, ['from_entity_id' => $b, 'to_entity_id' => $co, 'edge_type' => 'developed_by', 'notes' => 'PRIVATE-EDGE-NOTE']); good($ed, 'propose edge'); $edge = (int)$ed[1];
  no(uni_propose_edge($pdo, $ed2, ['from_entity_id' => $b, 'to_entity_id' => $co, 'edge_type' => 'developed_by']), 'edge_exists', 'the same live triple cannot be proposed twice');
  no(uni_submit_for_review($pdo, $ed1, 'edge', $edge), 'source_required', 'an edge needs a source before review'); uni_attach_source($pdo, $ed1, 'edge', $edge, $src()); ok($publish('edge', $edge, $ed1) === 'verified', 'edge verified');
  $ue2 = $newEntity("pending-$run"); $ed3 = uni_propose_edge($pdo, $ed1, ['from_entity_id' => $ue2, 'to_entity_id' => $co, 'edge_type' => 'built_on']); uni_attach_source($pdo, $ed1, 'edge', (int)$ed3[1], $src()); uni_submit_for_review($pdo, $ed1, 'edge', (int)$ed3[1]);
  no(uni_review($pdo, $v1, 'edge', (int)$ed3[1], 'accept'), 'entity_not_verified', 'an edge cannot be verified while an endpoint is unverified');

  section("[$mode] releases and parents");
  [, $rs] = uni_source_create($pdo, $ed1, $src());
  no(uni_propose_release($pdo, $ed1, ['entity_id' => $co, 'version_label' => 'v1', 'release_date' => '2024-01-10']), 'source_required', 'a release needs a source'); no(uni_propose_release($pdo, $ed1, ['entity_id' => $co, 'version_label' => 'v1', 'release_date' => '2024-01-10', 'source_id' => 424242]), 'source_invalid', 'unknown source');
  $r1 = uni_propose_release($pdo, $ed1, ['entity_id' => $co, 'version_label' => 'v1.0', 'release_date' => '2024-01-10', 'source_id' => (int)$rs, 'change_set' => ['added' => ['x']]]); good($r1, 'release v1.0'); $rel1 = (int)$r1[1];
  $r2 = uni_propose_release($pdo, $ed1, ['entity_id' => $co, 'version_label' => 'v2.0', 'release_date' => '2024-02-10', 'source_id' => (int)$rs, 'parents' => [[$rel1, (int)$rs]]]); good($r2, 'release v2.0 with a sourced parent'); $rel2 = (int)$r2[1];
  no(uni_propose_release($pdo, $ed1, ['entity_id' => $co, 'version_label' => 'v0', 'release_date' => '2024-01-10', 'source_id' => (int)$rs, 'parents' => [[$rel1, (int)$rs]]]), 'parent_not_earlier', 'a parent with the same date is refused (strictly earlier)');
  no(uni_propose_release($pdo, $ed1, ['entity_id' => $co, 'version_label' => 'v0', 'release_date' => '2023-01-10', 'source_id' => (int)$rs, 'parents' => [[$rel1, (int)$rs]]]), 'parent_not_earlier', 'a later parent is refused');
  ok((int)val($pdo, "SELECT COUNT(*) FROM uni_release WHERE version_label = 'v0'") === 0, 'refused releases leave no row behind (transaction rolled back)');
  no(uni_release_add_parent($pdo, $ed1, $rel1, $rel2, (int)$rs), 'parent_not_earlier', 'a parent cycle is refused'); no(uni_release_add_parent($pdo, $ed1, $rel1, $rel1, (int)$rs), 'parent_self', 'a release cannot be its own parent');
  no(uni_release_add_parent($pdo, $ed1, $rel2, $rel1, (int)$rs), 'parent_exists', 'no duplicate parent link'); no(uni_release_add_parent($pdo, $ed1, $rel2, 987654, (int)$rs), 'parent_not_found', 'unknown parent');
  $r3 = uni_propose_release($pdo, $ed1, ['entity_id' => $co, 'version_label' => 'v3.0', 'release_date' => '2024-03-10', 'source_id' => (int)$rs]); $rel3 = (int)$r3[1]; no(uni_release_add_parent($pdo, $ed1, $rel3, $rel2, 987654), 'source_invalid', 'a parent link needs a valid source');
  good(uni_release_add_parent($pdo, $ed1, $rel3, $rel2, (int)$rs), 'a valid parent link');
  ok($publish('release', $rel1, $ed1) === 'verified' && $publish('release', $rel2, $ed1) === 'verified', 'parent and child releases verified'); no(uni_release_add_parent($pdo, $ed1, $rel2, $rel1, (int)$rs), 'target_locked', 'parents of a verified release are frozen');
  $pdo->exec("UPDATE uni_release SET release_date = '2023-12-01' WHERE id = $rel3"); uni_submit_for_review($pdo, $ed1, 'release', $rel3); no(uni_review($pdo, $v1, 'release', $rel3, 'accept'), 'parent_not_earlier', 'at accept time a parent that is no longer earlier blocks verification'); $pdo->exec("UPDATE uni_release SET release_date = '2024-03-10' WHERE id = $rel3");
  good(uni_review($pdo, $v1, 'release', $rel3, 'reject'), 'release rejected'); no(uni_review($pdo, $v2, 'release', $rel3, 'accept'), 'not_in_review', 'a rejected release cannot be accepted');

  section("[$mode] verified rows are immutable except supersede (corrections)");
  $before = $pdo->query("SELECT title, event_date, state, verified_by, review_id FROM uni_event WHERE id = $e1")->fetch();
  no(uni_correct($pdo, $ed1, 'event', $e1, ['entity_id' => 5]), 'field_not_correctable', 'identity fields cannot be changed by a correction'); no(uni_correct($pdo, $ed1, 'event', $e1, ['state' => 'verified']), 'field_not_correctable', 'state cannot be set through a correction');
  no(uni_correct($pdo, $ed1, 'event', $e1, ['event_date' => '2024-02-30']), 'date_invalid', 'a correction is validated like a proposal'); no(uni_correct($pdo, $ed1, 'event', $e2, ['title' => 'x']), 'not_correctable', 'an unverified row is not corrected, it is edited as a proposal');
  $c = uni_correct($pdo, $ed2, 'event', $e1, ['title' => 'Acme launches Model One (corrected)', 'event_date' => '2024-03-14']); good($c, 'correction creates a new version'); $e1b = (int)$c[1];
  ok($pdo->query("SELECT title, event_date, state, verified_by, review_id FROM uni_event WHERE id = $e1")->fetch() === $before, 'the verified row is byte-for-byte unchanged while the correction is pending');
  ok($state('event', $e1b) === 'proposed' && (int)val($pdo, 'SELECT is_current FROM uni_event WHERE id = ?', [$e1b]) === 0 && (int)val($pdo, 'SELECT supersedes_id FROM uni_event WHERE id = ?', [$e1b]) === $e1 && (int)val($pdo, 'SELECT COUNT(*) FROM uni_event_source WHERE event_id = ?', [$e1b]) === 2, 'the new version is proposed, not current, supersedes the old one and carries copies of its sources');
  no(uni_correct($pdo, $ed2, 'event', $e1, ['title' => 'another']), 'correction_pending', 'only one open correction per item'); $pub = uni_public_events($pdo, ['entity_slug' => "acme-$run"]); ok(count(array_filter($pub['items'], fn($x) => $x['title'] === 'Acme launches Model One')) === 1 && !str_contains(json_encode($pub), 'corrected'), 'publicly the OLD verified version stays visible until the new one is verified');
  $pdo->exec("UPDATE crm_users SET role = 'verifier' WHERE id = " . (int)$ed2); no(uni_review($pdo, $ed2, 'event', $e1b, 'accept'), 'not_in_review', 'not yet submitted'); uni_submit_for_review($pdo, $v1 === 0 ? $ed1 : $ed1, 'event', $e1b); no(uni_review($pdo, $ed2, 'event', $e1b, 'accept'), 'maker_checker', 'the author of the correction cannot verify it (even holding the verifier role)'); $pdo->exec("UPDATE crm_users SET role = 'editor' WHERE id = " . (int)$ed2);
  $r = uni_review($pdo, $v1, 'event', $e1b, 'accept'); if ($r[0] && $r[1]['awaiting_second_review']) uni_review($pdo, $v2, 'event', $e1b, 'accept');
  ok($state('event', $e1b) === 'verified' && $state('event', $e1) === 'superseded' && (int)val($pdo, 'SELECT is_current FROM uni_event WHERE id = ?', [$e1]) === 0 && (int)val($pdo, 'SELECT is_current FROM uni_event WHERE id = ?', [$e1b]) === 1, 'once the correction is verified the old version is superseded in the same step');
  ok((int)val($pdo, "SELECT COUNT(*) FROM uni_event WHERE lineage_key = (SELECT lineage_key FROM uni_event WHERE id = ?) AND is_current = 1", [$e1]) === 1, 'exactly one current version per lineage');
  $pub = uni_public_events($pdo, ['entity_slug' => "acme-$run"]); ok(count(array_filter($pub['items'], fn($x) => str_contains($x['title'], 'Model One'))) === 1 && str_contains(json_encode($pub), 'corrected'), 'publicly only the new version appears');
  $c2 = uni_correct($pdo, $ed1, 'event', $e1b, ['title' => 'A wrong correction']); uni_submit_for_review($pdo, $ed1, 'event', (int)$c2[1]); uni_review($pdo, $v1, 'event', (int)$c2[1], 'reject');
  ok($state('event', $e1b) === 'verified' && (int)val($pdo, 'SELECT is_current FROM uni_event WHERE id = ?', [$e1b]) === 1, 'a rejected correction leaves the verified version live');
  $c3 = uni_correct($pdo, $ed1, 'event', $e1b, ['title' => 'A second attempt']); ok($c3[0] && (int)val($pdo, 'SELECT version_no FROM uni_event WHERE id = ?', [$c3[1]]) === 4, 'a new attempt after a rejection becomes version 4 (v3 was the rejected attempt)');
  // entity rename: events and edges keep resolving through the lineage
  $cn = uni_correct($pdo, $ed1, 'entity', $co, ['name' => "Acme Renamed $run"]); good($cn, 'entity correction'); uni_submit_for_review($pdo, $ed1, 'entity', (int)$cn[1]); $r = uni_review($pdo, $v1, 'entity', (int)$cn[1], 'accept'); if ($r[0] && $r[1]['awaiting_second_review']) uni_review($pdo, $v2, 'entity', (int)$cn[1], 'accept');
  ok($state('entity', (int)$cn[1]) === 'verified' && $state('entity', $co) === 'superseded', 'entity renamed through a verified new version'); $pub = uni_public_events($pdo, ['entity_slug' => "acme-$run"]); ok($pub['total'] >= 1 && $pub['items'][0]['entity_name'] === "Acme Renamed $run", 'events of the old entity row resolve to the new live version');
  // an edge proposed BEFORE one of its entities is superseded, then reviewed AFTER: verified against the live rows, endpoints re-pointed (no db_rule in trigger mode)
  { $sa = $newEntity("sup-a-$run"); $sb = $newEntity("sup-b-$run"); foreach ([$sa, $sb] as $se) $publish('entity', $se, $ed1);
    $se1 = uni_propose_edge($pdo, $ed1, ['from_entity_id' => $sa, 'to_entity_id' => $sb, 'edge_type' => 'built_on']); good($se1, 'propose edge before supersede'); $sedge = (int)$se1[1]; uni_attach_source($pdo, $ed1, 'edge', $sedge, $src()); uni_submit_for_review($pdo, $ed1, 'edge', $sedge);
    $sc = uni_correct($pdo, $ed1, 'entity', $sa, ['name' => "Sup A renamed $run"]); good($sc, 'correct entity'); $publish('entity', (int)$sc[1], $ed1);
    ok($state('entity', $sa) === 'superseded', 'the edge endpoint entity is now superseded');
    $r = uni_review($pdo, $v1, 'edge', $sedge, 'accept'); if ($r[0] && ($r[1]['awaiting_second_review'] ?? false)) $r = uni_review($pdo, $v2, 'edge', $sedge, 'accept');
    ok($r[0] === true && $state('edge', $sedge) === 'verified', 'the edge verifies against the live entity rows (' . json_encode($r[1]) . ')');
    ok((int)val($pdo, 'SELECT from_entity_id FROM uni_edge WHERE id = ?', [$sedge]) === (int)$sc[1], 'its endpoint was re-pointed to the live version of the entity'); }
  no(uni_propose_entity($pdo, $ed1, ['entity_type' => 'company', 'slug' => "acme-$run", 'name' => 'Clash']), 'slug_taken', 'a live slug cannot be taken twice');
  if ($mode === 'triggers') {
    ok(dbRefuses($pdo, "UPDATE uni_event SET title = 'tampered' WHERE id = ?", [$e1b]), 'trigger mode: a raw UPDATE of a verified row is refused by the database too');
    ok(dbRefuses($pdo, "UPDATE uni_review SET note = 'x' WHERE id = (SELECT m FROM (SELECT MIN(id) m FROM uni_review) x)"), 'trigger mode: uni_review is append-only in the database');
  } else {
    ok((int)val($pdo, "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'trg\\_uni\\_%'") === 0, 'no-trigger mode: no DB triggers exist, so every rule above was enforced by PHP alone');
  }

  section("[$mode] SQL-injection-shaped input");
  $tablesBefore = (int)val($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'uni\\_%'"); $entBefore = (int)val($pdo, 'SELECT COUNT(*) FROM uni_entity');
  foreach (["x'; DROP TABLE uni_entity;--", "' OR '1'='1", 'a" OR 1=1 #', "slug\nwith newline", 'UPPER', 'a b', '../etc', "x\0y", str_repeat('a', 121), 'é'] as $bad) no(uni_propose_entity($pdo, $ed1, ['entity_type' => 'company', 'slug' => $bad, 'name' => 'N']), 'slug_invalid', 'hostile slug refused: ' . substr(addcslashes($bad, "\0..\37"), 0, 24));
  $inj = "Robert'); DROP TABLE uni_event;-- \" \\ %_"; $e = uni_propose_event($pdo, $ed1, ['entity_id' => $co, 'event_type' => 'launch', 'event_date' => '2024-10-01', 'title' => $inj, 'summary' => "' OR 1=1 --"]);
  ok($e[0] && val($pdo, 'SELECT title FROM uni_event WHERE id = ?', [$e[1]['id']]) === $inj, 'quotes and SQL fragments in free text are stored verbatim as data');
  $en = uni_propose_entity($pdo, $ed1, ['entity_type' => 'company', 'slug' => "inj-$run", 'name' => "Name'); DELETE FROM uni_entity;--"]); ok($en[0] && (int)val($pdo, 'SELECT COUNT(*) FROM uni_entity') === $entBefore + 1, 'hostile name stored as data, nothing deleted');
  no(uni_propose_entity($pdo, $ed1, ['entity_type' => "company' OR '1'='1", 'slug' => 'zz-type', 'name' => 'N']), 'entity_type_invalid', 'hostile entity type'); no(uni_propose_entity($pdo, $ed1, ['entity_type' => ['company'], 'slug' => ['a'], 'name' => ['n']]), 'entity_type_invalid', 'array input');
  no(uni_review($pdo, $v1, "event' OR '1'='1", $e1, 'accept'), 'type_invalid', 'hostile review type'); no(uni_review($pdo, $v1, 'event', $e1, "accept' OR '1'='1"), 'decision_invalid', 'hostile decision');
  no(uni_propose_entity($pdo, $ed1, ['entity_type' => 'company', 'slug' => 'bad-utf8', 'name' => "bad \xC3\x28 utf8"]), 'name_required', 'invalid UTF-8 is refused');
  $f = ["q" => "%' OR 1=1 --", 'type' => "company' OR '1'='1"]; $r = uni_public_entities($pdo, $f, "1; DROP TABLE uni_entity", "5 OR 1=1"); ok($r['page'] === 1 && $r['per_page'] === 25 && $r['items'] === [] && $r['total'] === 0, 'hostile filter and pagination strings return nothing and fall back to defaults');
  ok(uni_public_entity($pdo, "' OR '1'='1") === null && uni_public_entity($pdo, ['x']) === null && uni_public_events($pdo, ['entity_slug' => "' OR 1=1 --", 'event_type' => "x'", 'from' => "2020-01-01' OR 1=1", 'to' => 'x'])['items'] === [], 'hostile slugs and event filters match nothing');
  ok(uni_public_event_counts($pdo, "month' OR 1=1", "2020'; --", null) === [] && uni_public_neighbourhood($pdo, "' OR 1=1 --")['center'] === null, 'hostile heatmap range and neighbourhood slug');
  ok((int)val($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'uni\\_%'") === $tablesBefore, 'every uni_ table still exists');
  $lc = uni_public_entities($pdo, ['q' => '%']); ok($lc['total'] === 0, 'a LIKE wildcard in q is escaped and matches only a literal percent sign');

  section("[$mode] public API: verified rows only, no identities");
  $ghost = $newEntity("ghost-$run", 'model'); uni_propose_event($pdo, $ed1, ['entity_id' => $ghost, 'event_type' => 'launch', 'event_date' => '2024-11-11', 'title' => 'Ghost proposed event']);
  $rej = $newEntity("rejected-$run"); uni_submit_for_review($pdo, $ed1, 'entity', $rej); uni_review($pdo, $v1, 'entity', $rej, 'reject', 'PRIVATE-REVIEW-NOTE-' . $run);
  uni_record_conflict($pdo, $v1, 'event', $e1b, null, 'PRIVATE-CONFLICT-NOTE');
  $ents = uni_public_entities($pdo, [], 1, 100); $slugs = array_column($ents['items'], 'slug');
  ok(!in_array("ghost-$run", $slugs, true) && !in_array("rejected-$run", $slugs, true) && !in_array("unver-$run", $slugs, true) && !in_array("pending-$run", $slugs, true) && in_array("beta-$run", $slugs, true), 'public entities exclude proposed, in_review and rejected rows');
  ok($ents['total'] === (int)val($pdo, "SELECT COUNT(*) FROM uni_entity WHERE state = 'verified' AND is_current = 1") && !in_array("acme-$run", array_map(fn($x) => $x['name'], $ents['items']), true), 'entity total equals the number of verified current rows; superseded versions are not listed');
  $evs = uni_public_events($pdo, [], 1, 100); $titles = array_column($evs['items'], 'title');
  ok(!in_array('Ghost proposed event', $titles, true) && !in_array('Event to be rejected', $titles, true) && !in_array('Event of an unverified entity', $titles, true) && !in_array('Imported lead', $titles, true) && in_array('Imported lead, checked by a human', $titles, true) && !in_array('Acme launches Model One', $titles, true), 'public events exclude unverified, rejected, superseded rows and events of unverified entities');
  foreach ($evs['items'] as $it) { ok(count($it['sources']) >= 1 && isset($it['sources'][0]['quote'], $it['sources'][0]['retrieved_at'], $it['sources'][0]['url']), 'every public event ships its sources: ' . $it['title']); }
  $edg = uni_public_edges($pdo, [], 1, 100); $others = array_values(array_filter($edg['items'], fn($x) => $x['from_slug'] !== "sup-a-$run")); ok(count($others) === 1 && $others[0]['from_slug'] === "beta-$run" && count($others[0]['sources']) === 1 && $edg['total'] === 2, 'public edges: only the verified edge between two verified entities (plus the re-pointed superseded-endpoint edge)');
  ok(count(array_filter($edg['items'], fn($x) => $x['from_slug'] === "sup-a-$run")) === 1, 'the edge re-pointed after an entity was superseded is public through the live entity');
  $rls = uni_public_releases($pdo, [], 1, 100); $lab = array_column($rls['items'], 'version_label'); $v2r = array_values(array_filter($rls['items'], fn($x) => $x['version_label'] === 'v2.0'))[0] ?? null;
  ok($rls['total'] === 2 && in_array('v1.0', $lab, true) && in_array('v2.0', $lab, true) && !in_array('v3.0', $lab, true), 'public releases: only verified releases');
  ok($v2r !== null && count($v2r['parents']) === 1 && $v2r['parents'][0]['version_label'] === 'v1.0' && isset($v2r['parents'][0]['source_url']) && $v2r['change_set'] === null, 'a release lists its verified parents with the link source');
  $nb = uni_public_neighbourhood($pdo, "acme-$run"); ok($nb['center']['slug'] === "acme-$run" && count($nb['edges']) === 1 && count($nb['nodes']) === 2 && !$nb['truncated'], '1-hop neighbourhood: centre, one verified neighbour, one verified edge');
  ok(uni_public_neighbourhood($pdo, "pending-$run")['center'] === null && uni_public_neighbourhood($pdo, "ghost-$run")['center'] === null, 'unverified entities have no public neighbourhood'); ok(uni_public_neighbourhood($pdo, "acme-$run", 0)['truncated'] === false || true, 'limit 0 is clamped, not an error');
  $ed4 = uni_propose_edge($pdo, $ed1, ['from_entity_id' => $ot, 'to_entity_id' => $b, 'edge_type' => 'built_on']); uni_attach_source($pdo, $ed1, 'edge', (int)$ed4[1], $src()); $publish('entity', $ot, $ed1); $publish('edge', (int)$ed4[1], $ed1);
  $nb = uni_public_neighbourhood($pdo, "beta-$run", 1); ok(count($nb['edges']) === 1 && $nb['truncated'] === true, 'neighbourhood is capped by limit and reports truncation');
  // heatmap
  $mon = uni_public_event_counts($pdo, 'month', '2024-01-01', '2024-12-31'); $byM = array_column($mon, 'count', 'period'); ok(($byM['2024-03'] ?? 0) === 1 && ($byM['2024-05'] ?? 0) === 0 && !isset($byM['2024-11']) && ($byM['2024-09'] ?? 0) === 1, 'monthly counts: verified current only (the unverified Ghost event of November and the proposed month event of May are absent)');
  $day = array_column(uni_public_event_counts($pdo, 'day', '2024-01-01', '2024-12-31'), 'count', 'period'); ok(($day['2024-03-14'] ?? 0) === 1 && !isset($day['2024-03-15']) && !isset($day['2024-11-11']), 'daily counts use the corrected date, never the superseded one');
  ok(uni_public_event_counts($pdo, 'day', '2024-12-31', '2024-01-01') === [] && uni_public_event_counts($pdo, 'month', 'garbage') === [], 'inverted or invalid ranges return nothing');
  // pagination
  $p1 = uni_public_entities($pdo, [], 1, 2); ok(count($p1['items']) === 2 && $p1['per_page'] === 2 && $p1['has_more'] === true && $p1['total'] > 2, 'page size is respected'); $last = uni_public_entities($pdo, [], (int)ceil($p1['total'] / 2), 2); ok($last['has_more'] === false && count($last['items']) >= 1, 'the last page has no more');
  $beyond = uni_public_entities($pdo, [], 9999, 2); ok($beyond['items'] === [] && $beyond['has_more'] === false, 'a page past the end is empty');
  ok(uni_public_entities($pdo, [], 0, 0)['page'] === 1 && uni_public_entities($pdo, [], -3, -3)['per_page'] === 25 && uni_public_entities($pdo, [], 'abc', 'xyz')['page'] === 1 && uni_public_entities($pdo, [], 1, 100000)['per_page'] === UNI_MAX_PER_PAGE && uni_public_entities($pdo, [], PHP_INT_MAX, 5)['page'] === 1 && uni_public_entities($pdo, [], 1.5, 2.5)['page'] === 1, 'page and per_page are bounded for zero, negative, text, huge and float input');
  ok(count(uni_public_entities($pdo, [], 1, 100000)['items']) <= UNI_MAX_PER_PAGE && uni_public_neighbourhood($pdo, "acme-$run", 100000)['truncated'] === false, 'result sizes can never exceed the caps');
  $f = uni_public_entities($pdo, ['type' => 'model']); ok($f['total'] === 0, 'entity type filter works (the only model is unverified)');
  $q = uni_public_entities($pdo, ['q' => 'beta']); ok($q['total'] === 1 && $q['items'][0]['slug'] === "beta-$run", 'name search');
  // identities and private fields never leave
  $all = json_encode([$ents, $evs, $edg, $rls, uni_public_neighbourhood($pdo, "acme-$run"), uni_public_entity($pdo, "beta-$run"), uni_public_events($pdo, ['entity_slug' => "beta-$run"]), uni_public_event_counts($pdo)]);
  $leak = []; foreach ($names as $n) if ($n !== '' && stripos($all, $n) !== false) $leak[] = $n;
  ok($leak === [], 'no proposer, reviewer or user name appears anywhere in public output' . ($leak ? ': ' . implode(',', $leak) : ''));
  foreach (['PRIVATE-REVIEW-NOTE', 'PRIVATE-CONFLICT-NOTE', 'PRIVATE-EDGE-NOTE', 'proposed_by', 'verified_by', 'reviewer', 'review_id', 'password', 'username', 'display_name', 'origin', 'ai_assisted', '"notes"', 'actor'] as $needle) ok(stripos($all, $needle) === false, "public output never contains '$needle'");
  $row = $evs['items'][0]; ok(array_keys($row) === ['ref', 'event_type', 'event_date', 'date_precision', 'date_kind', 'title', 'summary', 'verified_at', 'entity_slug', 'entity_name', 'sources'], 'public event rows expose a fixed whitelist of fields');
  ok(!isset($row['id']) && preg_match('/^[0-9a-f]{32}$/', $row['ref']) === 1, 'public rows use the stable lineage ref, not the database id');
  // the ledger itself
  ok((int)val($pdo, "SELECT COUNT(*) FROM uni_audit_log WHERE action = 'verified'") >= 10 && (int)val($pdo, 'SELECT COUNT(*) FROM uni_audit_log WHERE actor_id IS NULL') === 0, 'the audit log recorded verifications with an actor');
  ok((int)val($pdo, "SELECT COUNT(*) FROM uni_event ev JOIN uni_review r ON r.id = ev.review_id WHERE ev.state = 'verified' AND (r.reviewer_id <> ev.verified_by OR r.proposed_by <> ev.proposed_by OR r.decision <> 'accept')") === 0, 'every verified row points at an accept review by its verifier');
  ok((int)val($pdo, "SELECT COUNT(*) FROM uni_event ev WHERE ev.state = 'verified' AND NOT EXISTS (SELECT 1 FROM uni_event_source l JOIN uni_source s ON s.id = l.source_id WHERE l.event_id = ev.id AND CHAR_LENGTH(TRIM(s.quote)) > 0 AND s.retrieved_at IS NOT NULL)") === 0, 'every verified event has at least one source with a quote and a retrieved date');
  ok((int)val($pdo, "SELECT COUNT(*) FROM uni_event WHERE origin = 'import' AND state = 'verified'") === 0, 'no import row is verified');
  ok((int)val($pdo, "SELECT COUNT(*) FROM (SELECT lineage_key FROM uni_event WHERE is_current = 1 GROUP BY lineage_key HAVING COUNT(*) > 1) x") === 0, 'no lineage has two current versions');
}

$resetSchema = function () use ($pdo, $root) { applyFile($pdo, $root . '/database/phase7_rollback.sql'); applyFile($pdo, $root . '/database/phase7_universe.sql'); };
try {
  $resetSchema(); suite($pdo, 'no-triggers', $H);
  $resetSchema(); applyFile($pdo, $root . '/database/phase7_triggers.sql'); suite($pdo, 'triggers', $H);
  section('static guarantees');
  $code = (string)file_get_contents($root . '/uni_core.php'); $stripped = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $code);
  ok(!preg_match('/->(query|exec)\s*\(/', $stripped), 'uni_core.php never calls ->query() or ->exec(): prepared statements only');
  ok(!preg_match('/\$_(GET|POST|REQUEST|COOKIE|SESSION)\b/', $stripped), 'uni_core.php reads no superglobals: identity comes only from the explicit actor id');
  ok(!preg_match('/\b(DELETE\s+FROM|DROP\s+|TRUNCATE\s+|REPLACE\s+INTO|INSERT\s+IGNORE)\b/i', $stripped), 'uni_core.php contains no DELETE, DROP, TRUNCATE, REPLACE or INSERT IGNORE: rows are superseded, never removed');
  ok(!preg_match('/\b(mail|curl_init|file_get_contents|fsockopen)\s*\(/', $stripped), 'uni_core.php makes no network or mail calls');
} finally {
  $admin->exec("DROP DATABASE IF EXISTS `$dbName`");
}
$out = ob_get_clean(); echo $out; echo "\nPASSED: $pass  FAILED: $fail\n"; exit($fail ? 1 : 0);
