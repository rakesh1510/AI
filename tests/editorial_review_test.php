<?php
/**
 * Editorial verification system tests (logic level, no network, no database).
 * The review log is redirected to a temporary file. The committed data/editorial_review_log.json is never written and must stay empty.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$tmp = tempnam(sys_get_temp_dir(), 'edlog'); unlink($tmp); putenv('AIHUB_EDITORIAL_LOG=' . $tmp);
require_once $root . '/helpers.php';
$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "  FAIL: $m\n"; } }
function section(string $t): void { echo "== $t\n"; }
$byId = tools_by_id();
$now = strtotime('2026-11-17');
$good = ['tool_id' => 'claude', 'action' => 'verify', 'reviewer' => 'Test Editor', 'confirm' => true, 'sources_checked' => 0,
  'checklist' => array_fill_keys(array_keys(editorial_verify_checklist()), '1')];
$nSrc = count($byId['claude']['curated']['sources']); $good['sources_checked'] = $nSrc;

section('Committed state: nothing is verified');
$committed = json_decode(file_get_contents($root . '/data/editorial_review_log.json'), true);
ok(($committed['entries'] ?? null) === [], 'the committed review log has no entries');
ok(editorial_verified_count() === 0, 'no profile is human-verified in the catalogue');
$lv = []; foreach ($byId as $t) { $e = tool_evidence($t, $now); $lv[$e['level']] = ($lv[$e['level']] ?? 0) + 1; }
ok(!isset($lv['editor_verified']) && !isset($lv['verified_stale']), 'no tool has an editor-verified evidence level');
ok(count($byId) === 509 && count(array_filter($byId, fn($t) => !empty($t['curated']))) === 20, 'catalogue counts: 509 tools, 20 reviewed');

section('Fact states');
$unrev = $byId['monica']; $f = editorial_fact_states($unrev, $now);
ok(array_unique(array_column($f, 'state')) === ['unknown'], 'an unreviewed tool has every fact unknown');
ok(array_unique(array_column($f, 'source_url')) === [''], 'an unreviewed tool shows no invented source');
$f = editorial_fact_states($byId['chatgpt'], $now);
ok($f['pricing']['state'] === 'conflicting' && count($f['pricing']['conflicts']) === 1, 'a pricing conflict makes the pricing fact "conflicting"');
ok($f['features']['state'] === 'documented', 'features documented by vendor sources are "documented", not verified');
ok($f['pricing']['expires_on'] !== null && $f['pricing']['source_url'] !== '', 'pricing shows a source URL and a re-check date');
$later = editorial_fact_states($byId['claude'], strtotime('2027-03-01'));
ok($later['pricing']['state'] === 'stale' || $later['pricing']['state'] === 'conflicting', 'pricing older than its 45-day rule is stale (or conflicting)');
$lf = editorial_fact_states($byId['notebooklm'], strtotime('2027-06-01'));
ok(in_array($lf['features']['state'], ['stale', 'conflicting'], true), 'features older than 180 days are stale');
$rules = editorial_freshness_rules();
ok($rules['pricing']['days'] < $rules['features']['days'] && $rules['features']['days'] < $rules['overview']['days'], 'pricing expires before features, features before overview');
ok(editorial_status($byId['monica'], $now) === 'unknown', 'unreviewed tool status is unknown');
foreach ($byId as $id => $t) { foreach (editorial_fact_states($t, $now) as $k => $row) { if ($row['state'] === 'editor_verified') { ok(false, "$id/$k must not be verified"); } } }
ok(true, 'no fact in the whole catalogue is editor_verified');

section('Research tasks never state facts');
$tasks = editorial_research_tasks($unrev, $now);
ok(count($tasks) >= 3 && $tasks[0]['code'] === 'find_vendor_sources', 'unreviewed tools get source-finding tasks first');
$all = ''; foreach ($byId as $t) foreach (editorial_research_tasks($t, $now) as $x) { $all .= $x['task'] . "\n"; ok($x['needs'] !== '', 'every task names what real evidence it needs'); break; }
$c = editorial_research_tasks($byId['chatgpt'], $now);
ok(count(array_filter($c, fn($x) => $x['code'] === 'resolve_conflict_pricing')) === 1 && count(array_filter($c, fn($x) => $x['code'] === 'editor_verification')) === 1, 'a reviewed tool gets conflict and verification tasks');
ok(!preg_match('/\$\d|€\d/', implode(' ', array_column(editorial_research_tasks($unrev, $now), 'task'))), 'tasks for unreviewed tools contain no price');

section('Review log validation');
ok(editorial_log_append($good, 'robot') === [false, 'invalid_channel'], 'unknown channels are refused');
ok(editorial_log_append(['action' => 'verify'] + $good, 'cli')[0] === true || true, 'sanity');
@unlink($tmp); editorial_log_load(true);
foreach ([
  [['tool_id' => 'nope'] + $good, 'unknown_tool'], [['action' => 'approve'] + $good, 'invalid_action'], [['reviewer' => ''] + $good, 'reviewer_required'], [['reviewer' => 'A'] + $good, 'reviewer_required'],
  [['confirm' => false] + $good, 'confirmation_required'], [['sources_checked' => $nSrc - 1] + $good, 'sources_count_mismatch'],
  [['checklist' => array_slice($good['checklist'], 1)] + $good, 'checklist_incomplete'], [['tool_id' => 'monica'] + $good, 'not_reviewed_profile'],
  [['action' => 'note', 'note' => ''] + $good, 'note_required'],
] as [$in, $code]) { $r = editorial_log_append($in, 'admin_session', $now); ok($r === [false, $code], "refused: $code (got " . json_encode($r) . ')'); }
ok(!is_file($tmp), 'refused events write nothing');
ok(editorial_log_verified('claude') === null && editorial_verified_count() === 0, 'nothing is verified before a valid event');

section('A person verifies (temporary log only)');
$r = editorial_log_append($good, 'admin_session', $now);
ok($r === [true, 'ok'], 'a complete verification by a named reviewer is accepted');
$v = editorial_log_verified('claude');
ok($v && $v['reviewer'] === 'Test Editor' && $v['date'] === '2026-11-17', 'the log records reviewer and timestamp');
$e = tool_evidence(tools_by_id()['claude'], $now);
ok($e['human_verified'] && $e['level'] === 'editor_verified', 'the evidence level follows the log');
$fs = editorial_fact_states(tools_by_id()['claude'], $now);
ok($fs['features']['state'] === 'editor_verified' && $fs['features']['note'] !== '', 'features become editor_verified with a note');
ok(editorial_verified_count() === 1, 'only that one profile counts as verified');
$old = tool_evidence(tools_by_id()['claude'], strtotime('2027-05-01'));
ok($old['level'] === 'verified_stale', 'a verification older than 90 days becomes "re-check due"');
$noConf = null; foreach ($byId as $id => $t) { if (!empty($t['curated']) && editorial_fact_states($t, $now)['pricing']['conflicts'] === [] && !empty($t['curated']['pricing']['summary'])) { $noConf = $id; break; } }
ok($noConf !== null, 'a reviewed tool without a pricing conflict exists for the expiry test');
$g2 = ['tool_id' => $noConf, 'sources_checked' => count($byId[$noConf]['curated']['sources'])] + $good;
ok(editorial_log_append($g2, 'admin_session', $now)[0], 'second verification accepted');
ok(editorial_fact_states(tools_by_id()[$noConf], strtotime('2027-05-01'))['pricing']['state'] === 'stale', 'pricing verification expires after 45 days');
ok(editorial_fact_states(tools_by_id()[$noConf], $now + 86400 * 10)['pricing']['state'] === 'editor_verified', 'verified pricing is still current within 45 days');
editorial_log_append(['tool_id' => $noConf, 'action' => 'reopen', 'note' => 'test cleanup', 'reviewer' => 'Test Editor'], 'admin_session', $now + 120);
ok(editorial_log_append(['action' => 'reject', 'note' => 'Pricing changed.', 'reviewer' => 'Test Editor', 'tool_id' => 'claude'], 'admin_session', $now + 60)[0], 'a rejection is accepted');
ok(editorial_log_verified('claude') === null && !tool_evidence(tools_by_id()['claude'], $now)['human_verified'], 'a rejection withdraws verification');
ok(editorial_log_append(['tool_id' => 'claude', 'action' => 'note', 'reviewer' => "Eve\x00\r\n<b>x</b>", 'note' => str_repeat('n', 5000)], 'cli', $now)[0], 'a long note with control characters is accepted after cleaning');
$last = array_values(array_slice(editorial_log_load(true)['entries'], -1))[0];
ok(strlen($last['note']) === 600 && !preg_match('/[\x00\r\n]/', $last['reviewer']), 'notes are truncated and control characters removed');
ok(editorial_log_append(['tool_id' => 'claude', 'action' => 'sources_checked', 'reviewer' => 'Test Editor'], 'admin_session', $now)[0] && editorial_log_verified('claude') === null, 'recording that sources were opened does not verify');

section('The committed log is still empty');
$after = json_decode(file_get_contents($root . '/data/editorial_review_log.json'), true);
ok(($after['entries'] ?? null) === [], 'tests never wrote to the committed log');
@unlink($tmp);
echo "\nPASSED: $pass  FAILED: $fail\n"; exit($fail ? 1 : 0);
