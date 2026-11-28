<?php
/** Phase 7B pure logic tests (no database, no network, no mail): business hours, holidays, four-hour target, priority score, digest text. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start(); $root = dirname(__DIR__);
require_once $root . '/helpers.php'; require_once $root . '/crm_core.php';
$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; fwrite(STDERR, "  FAIL: $m\n"); } }
function B(string $s): DateTimeImmutable { return new DateTimeImmutable($s, new DateTimeZone('Europe/Berlin')); }
function f(DateTimeImmutable $d): string { return $d->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d H:i'); }
$X = [];   // no extra holidays

fwrite(STDERR, "== holidays\n");
foreach ([2024 => '03-31', 2025 => '04-20', 2026 => '04-05', 2027 => '03-28'] as $y => $md) { [$m, $d] = crm_bh_easter($y); ok(sprintf('%02d-%02d', $m, $d) === $md, "Easter $y");}
$h = crm_bh_holidays(2026, $X);
foreach (['2026-01-01', '2026-03-08', '2026-04-03', '2026-04-06', '2026-05-01', '2026-05-14', '2026-05-25', '2026-10-03', '2026-12-25', '2026-12-26'] as $d) ok(isset($h[$d]), "holiday $d");
ok(!isset($h['2026-12-24']) && !isset($h['2026-12-31']), 'Dec 24/31 are working days unless the owner configures them');
ok(!isset(crm_bh_holidays(2018, $X)['2018-03-08']), "Women's Day only from 2019");
ok(!crm_bh_is_workday(B('2026-03-08 10:00'), $X) , "2026-03-08 is a Sunday and a holiday"); ok(!crm_bh_is_workday(B('2027-03-08 10:00'), $X), "Women's Day 2027 (Monday) is not a business day");
ok(crm_bh_is_workday(B('2026-03-03 10:00'), $X), 'ordinary Tuesday'); ok(!crm_bh_is_workday(B('2026-03-07 10:00'), $X), 'Saturday');
$ex = ['2026-12-24' => 'x']; ok(!crm_bh_is_workday(B('2026-12-24 10:00'), $ex), 'owner-configured extra holiday');

fwrite(STDERR, "== business-hour arithmetic\n");
ok(f(crm_bh_add(B('2026-03-02 10:00'), 240, $X)) === '2026-03-02 14:00', 'Monday 10:00 + 4h');
ok(f(crm_bh_add(B('2026-03-02 13:00'), 240, $X)) === '2026-03-02 17:00', 'deadline exactly at close stays at 17:00');
ok(f(crm_bh_add(B('2026-03-02 13:01'), 240, $X)) === '2026-03-03 09:01' || f(crm_bh_add(B('2026-03-02 13:01'), 240, $X)) === '2026-03-03 09:01', 'one minute past rolls to next morning');
ok(f(crm_bh_add(B('2026-03-06 15:00'), 240, $X)) === '2026-03-09 11:00', 'Friday 15:00 -> Monday 11:00');
ok(f(crm_bh_add(B('2026-03-07 12:00'), 240, $X)) === '2026-03-09 13:00', 'Saturday noon -> Monday 13:00');
ok(f(crm_bh_add(B('2026-03-02 07:15'), 240, $X)) === '2026-03-02 13:00', 'before opening: clock starts at 09:00');
ok(f(crm_bh_add(B('2026-03-02 18:30'), 240, $X)) === '2026-03-03 13:00', 'after closing: next day 09:00 + 4h');
ok(f(crm_bh_add(B('2026-04-02 16:00'), 240, $X)) === '2026-04-07 12:00', 'over Good Friday, weekend and Easter Monday');
ok(f(crm_bh_add(B('2026-03-27 15:00'), 240, $X)) === '2026-03-30 11:00', 'across the DST change (29 Mar 2026)');
ok(f(crm_bh_add(B('2026-10-23 15:00'), 240, $X)) === '2026-10-26 11:00', 'across the autumn DST change (25 Oct 2026)');
ok(crm_bh_minutes(B('2026-03-06 16:00'), B('2026-03-09 10:00'), $X) === 120, 'Friday 16:00 to Monday 10:00 = 120 business minutes');
ok(crm_bh_minutes(B('2026-03-07 10:00'), B('2026-03-08 10:00'), $X) === 0, 'weekend = 0 minutes');
ok(crm_bh_minutes(B('2026-03-02 10:00'), B('2026-03-02 09:00'), $X) === 0, 'reversed range = 0');
ok(crm_bh_minutes(B('2026-03-02 09:00'), B('2026-03-03 09:00'), $X) === 480, 'one full business day = 480');
// UTC storage: created 2026-03-02 09:30 UTC (= 10:30 Berlin) is due 14:30 Berlin = 13:30 UTC
ok(gmdate('Y-m-d H:i', crm_response_due_ts(gmmktime(9, 30, 0, 3, 2, 2026))) === '2026-03-02 13:30', 'due time stored in UTC');

fwrite(STDERR, "== response state\n");
$row = ['created_at' => '2026-03-02 09:30:00', 'due_at' => '2026-03-02 13:30:00', 'first_response_at' => null];
$st = crm_response_state($row, gmmktime(12, 0, 0, 3, 2, 2026)); ok($st['state'] === 'open', 'open before due');
$st = crm_response_state($row, gmmktime(13, 31, 0, 3, 2, 2026)); ok($st['state'] === 'overdue', 'overdue one minute after due');
$st = crm_response_state($row, gmmktime(13, 30, 0, 3, 2, 2026)); ok($st['state'] === 'open', 'exactly at the deadline is still open');
$row['first_response_at'] = '2026-03-02 11:30:00'; $st = crm_response_state($row, time()); ok($st['state'] === 'answered' && $st['minutes'] === 120 && $st['within_target'], 'answered in 120 min');
$row['first_response_at'] = '2026-03-03 09:00:00'; $st = crm_response_state($row, time()); ok($st['minutes'] === 450 && !$st['within_target'], 'answered next morning: 450 business minutes, outside target');

fwrite(STDERR, "== priority\n");
$hi = crm_lead_priority(['kind' => 'development', 'email' => 'cto@acme-corp.example', 'company' => 'Acme GmbH', 'website' => 'https://acme-corp.example', 'budget' => '20k-50k', 'timeline' => 'asap', 'message' => str_repeat('We need an integration. ', 12)]);
ok($hi['score'] === 95 && $hi['band'] === 'high', 'high example scores 95 (got ' . $hi['score'] . ')'); ok(array_sum(array_column($hi['reasons'], 1)) === $hi['score'], 'every point has a stated reason');
$lo = crm_lead_priority(['kind' => 'partnership', 'email' => 'someone@gmail.com', 'company' => 'Sam (individual)', 'website' => '', 'budget' => '', 'timeline' => '', 'message' => 'hi']);
ok($lo['score'] === 15 && $lo['band'] === 'low', 'low example scores 15 (got ' . $lo['score'] . ')');
$md = crm_lead_priority(['kind' => 'advertising', 'email' => 'x@brand.example', 'company' => 'Brand', 'budget' => '5k-20k', 'timeline' => '3-6-months', 'message' => '']);
ok($md['score'] === 60 && $md['band'] === 'high', 'boundary 60 is high (got ' . $md['score'] . ')');
$b = crm_lead_priority(['kind' => 'advertising', 'email' => 'x@brand.example', 'company' => 'Brand', 'budget' => '5k-20k', 'timeline' => 'exploring', 'message' => '']); ok($b['score'] === 57 && $b['band'] === 'medium', 'medium band (got ' . $b['score'] . ')');
ok(crm_lead_priority([])['score'] === 0 && crm_lead_priority(['kind' => 'zzz', 'budget' => 'zzz'])['band'] === 'low', 'unknown or missing input scores 0, never errors');
ok(crm_lead_priority(['kind' => 'development', 'budget' => 'over-50k', 'timeline' => 'asap', 'email' => 'a@biz.example', 'company' => 'C', 'website' => 'w', 'message' => str_repeat('x', 300)])['score'] <= 100, 'score capped at 100');

fwrite(STDERR, "== digest text (owner only, no customer content)\n");
$d = ['unanswered' => [['id' => 7, 'company' => 'Acme GmbH', 'priority_band' => 'high', 'priority_score' => 82, 'due_at' => '2026-03-02 13:30:00', 'state' => ['state' => 'overdue']]], 'stale' => [['id' => 3, 'number' => 'P-2026-0003', 'company' => 'Beta', 'updated_at' => '2026-02-20 10:00:00']], 'consults' => [['lead_id' => 7, 'company' => 'Acme GmbH', 'scheduled_at' => '2026-03-04 09:00:00', 'duration_min' => 30]], 'tasks' => [['id' => 1, 'title' => 'Reply to enquiry #7', 'due_at' => '2026-03-02 13:30:00']], 'new_yesterday' => 2];
$m = crm_digest_compose($d, gmmktime(7, 30, 0, 3, 3, 2026));
ok(str_contains($m['subject'], '1 unanswered') && str_contains($m['subject'], '1 overdue') && str_contains($m['subject'], '2026-03-03'), 'subject has counts and Berlin date');
ok(str_contains($m['text'], '#7 Acme GmbH') && str_contains($m['text'], 'OVERDUE') && str_contains($m['text'], 'P-2026-0003') && str_contains($m['text'], 'Nothing was sent to any customer'), 'digest body lists items and the owner-only note');
ok(!str_contains($m['text'], '@'), 'no e-mail addresses in the digest');
ok(crm_digest_is_empty(['unanswered' => [], 'stale' => [], 'consults' => [], 'tasks' => []]) && !crm_digest_is_empty($d), 'empty digest is not sent');
fwrite(STDERR, "\ncrm_response_logic_test: $pass passed, $fail failed\n"); echo "crm_response_logic_test: $pass passed, $fail failed\n"; exit($fail ? 1 : 0);
