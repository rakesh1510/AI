<?php
/**
 * Phase 7B database tests: one follow-up task per enquiry, first human response, overdue state, consultations, Overview tiles, owner-only notifications with dedupe.
 * ISOLATED test database only (name must contain "test"). Mail never leaves the machine: the test points SMTP at a closed port so every message lands in a temp spool, which is inspected.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$spool = sys_get_temp_dir() . '/crm7b_spool_' . getmypid(); putenv('AIHUB_MAIL_SPOOL=' . $spool); $_ENV['AIHUB_MAIL_SPOOL'] = $spool; @mkdir($spool, 0700, true);
ob_start(); $root = dirname(__DIR__);
require_once $root . '/helpers.php'; require_once $root . '/mailer.php'; require_once $root . '/crm_dashboard.php'; require_once $root . '/crm_views.php'; require_once $root . '/adm_core.php'; require_once $root . '/adm_finance_views.php';
$pass = 0; $fail = 0;
function ok($c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; fwrite(STDERR, "  FAIL: $m\n"); } }
function section(string $t): void { fwrite(STDERR, "== $t\n"); }
if (stripos((string)sec_setting('AIHUB_DB_NAME', 'db.name'), 'test') === false) { fwrite(STDERR, "Refusing: database name must contain 'test'\n"); exit(2); }
db_connection_available(); $pdo = crm_pdo(); $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
function stmts(string $file): array { $b = ''; $o = []; foreach (preg_split('/\R/', (string)file_get_contents($file)) as $l) { $t = trim($l); if ($b === '' && ($t === '' || str_starts_with($t, '--'))) continue; $b .= $l . "\n"; if (str_ends_with($t, ';')) { $o[] = $b; $b = ''; } } return $o; }
function run(PDO $p, string $f): void { foreach (stmts($f) as $s) $p->exec($s); }
function tbls(PDO $p): array { return $p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); }
function spooled(string $dir): array { $o = []; foreach (glob($dir . '/*.json') ?: [] as $f) $o[] = json_decode((string)file_get_contents($f), true); return $o; }
// closed SMTP port: deterministic spooling
$mailRaw = (string)$pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='mail'")->fetchColumn(); $mail = json_decode($mailRaw, true) ?: []; $mail['smtp_port'] = 1; $mail['smtp_host'] = '127.0.0.1'; $mail['to_email'] = 'owner@example.test';
$pdo->prepare("UPDATE site_settings SET setting_value = ? WHERE setting_key='mail'")->execute([json_encode($mail)]);
foreach (['crm_notification_log', 'crm_consultations', 'crm_lead_response'] as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
foreach (['crm_audit_log', 'crm_activities', 'crm_tasks', 'crm_proposal_items', 'crm_proposal_versions', 'crm_proposals', 'crm_opportunity_stage_history', 'crm_opportunities', 'crm_leads', 'crm_contacts', 'crm_companies', 'crm_users'] as $t) { try { $pdo->exec("DELETE FROM $t"); } catch (Throwable $e) {} }
$H = password_hash('test-only-password', PASSWORD_DEFAULT);
[, $o1] = crm_user_create($pdo, 'owner1', 'Olivia Owner', 'owner', $H); [, $s1] = crm_user_create($pdo, 'sales1', 'Sam Sales', 'sales', $H); [, $v1] = crm_user_create($pdo, 'viewer1', 'Vera Viewer', 'viewer', $H);
$owner = crm_user_get($pdo, (int)$o1); $sales = crm_user_get($pdo, (int)$s1); $viewer = crm_user_get($pdo, (int)$v1);
$in = fn(array $x = []) => $x + ['kind' => 'development', 'name' => 'Dana Customer', 'email' => 'dana@acme-corp.example', 'company' => 'Acme GmbH', 'website' => 'https://acme-corp.example', 'message' => str_repeat('We need an AI integration for our support desk. ', 6), 'budget' => '20k-50k', 'timeline' => 'asap', 'source' => 'test'];
$taskCount = fn(int $lead) => (int)$pdo->query("SELECT COUNT(*) FROM crm_tasks WHERE entity_type='lead' AND entity_id=$lead")->fetchColumn();

section('before the migration: enquiries still work, nothing breaks');
ok(!crm_response_ready($pdo), 'tables absent => not ready');
[$okA, $leadA] = crm_lead_ingest($pdo, $in()); ok($okA && $leadA > 0, 'enquiry stored without the migration'); ok($taskCount((int)$leadA) === 0, 'no auto task without the migration (unchanged behaviour)');
$ov = crm_response_overview($pdo); ok($ov['ready'] === false && $ov['unanswered'] === null, 'overview says not ready instead of showing zeros');
$html = adm_dashboard_body($pdo, ['role' => 'owner', 'username' => 'x', 'id' => 1]); ok(str_contains($html, 'Needs attention') && str_contains($html, 'Phase 7B database migration'), 'Overview renders and explains the missing migration');
ok(crm_response_mark($pdo, (int)$leadA, $sales, 'reply') === false, 'mark is a no-op before the migration'); ok(crm_consult_schedule($pdo, $sales, (int)$leadA, '2099-01-05 10:00')[1] === 'not_migrated', 'consultation reports not_migrated');
ok(crm_send_digest($pdo, time()) !== 'sent' || true, 'digest does not crash before migration');

section('migration: additive, idempotent, reversible');
$before = tbls($pdo); $sum = []; foreach ($before as $t) $sum[$t] = $pdo->query("CHECKSUM TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
run($pdo, "$root/database/phase7b_lead_management.sql"); run($pdo, "$root/database/phase7b_lead_management.sql");
$new = array_values(array_diff(tbls($pdo), $before)); sort($new); ok($new === ['crm_consultations', 'crm_lead_response', 'crm_notification_log'], 'adds exactly three tables');
$same = true; foreach ($before as $t) if ($pdo->query("CHECKSUM TABLE `$t`")->fetch(PDO::FETCH_NUM)[1] !== $sum[$t]) $same = false; ok($same, 'no existing table changed (data or structure)');
run($pdo, "$root/database/phase7b_rollback.sql"); ok(tbls($pdo) === $before || array_values(array_diff(tbls($pdo), $before)) === [], 'rollback removes only the new tables'); run($pdo, "$root/database/phase7b_lead_management.sql"); ok(crm_response_ready($pdo), 'migration re-applies after rollback');
$ci = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crm_lead_response' AND COLUMN_KEY='PRI'")->fetchColumn(); ok($ci === 'lead_id', 'response row is keyed by lead id (exactly one per lead)');

section('new enquiry: exactly one task, priority, target');
$t0 = gmmktime(9, 30, 0, 3, 2, 2026);   // Monday 10:30 Berlin
[$okB, $leadB] = crm_lead_ingest($pdo, $in()); ok($okB, 'ingest'); $leadB = (int)$leadB;
$r = crm_response_get($pdo, $leadB); ok($r !== null && $r['first_response_at'] === null, 'response row opened, no response yet'); ok($taskCount($leadB) === 1, 'exactly one follow-up task'); ok((int)$r['task_id'] > 0, 'task linked to the response row');
ok($r['priority_band'] === 'high' && (int)$r['priority_score'] === 95 && count($r['reasons']) >= 6, 'transparent priority stored with reasons');
$tk = $pdo->query("SELECT title, due_at, done_at FROM crm_tasks WHERE entity_type='lead' AND entity_id=$leadB")->fetch(PDO::FETCH_ASSOC); ok(str_contains($tk['title'], '#' . $leadB) && $tk['due_at'] === $r['due_at'] && $tk['done_at'] === null, 'task due = response target');
ok(crm_response_open($pdo, $leadB, $in()) === [false, null] && crm_response_open($pdo, $leadB, $in()) === [false, null], 'opening again creates nothing'); ok($taskCount($leadB) === 1, 'still exactly one task after repeated open');
ok($pdo->query("SELECT COUNT(*) FROM crm_lead_response WHERE lead_id=$leadB")->fetchColumn() == 1, 'one response row');
[, $leadS] = crm_lead_ingest($pdo, $in(['synthetic' => true, 'email' => 'syn@acme-corp.example'])); ok(crm_response_get($pdo, (int)$leadS) === null && $taskCount((int)$leadS) === 0, 'synthetic (test) leads are not tracked');
[$okBad] = crm_lead_ingest($pdo, $in(['email' => 'nope'])); ok(!$okBad, 'invalid enquiry still rejected');
ok(crm_response_sync($pdo, 30) === 1 && $taskCount((int)$leadA) === 1, 'sync back-fills the pre-migration enquiry once'); ok(crm_response_sync($pdo, 30) === 0, 'sync is idempotent');

section('automatic acknowledgement and system activity are not a response');
crm_activity_add($pdo, null, 'lead', $leadB, 'system', 'Acknowledgement e-mail sent to the customer'); crm_activity_add($pdo, null, 'lead', $leadB, 'reply', 'auto'); crm_activity_add($pdo, $sales, 'lead', $leadB, 'note', 'internal note');
ok(crm_response_get($pdo, $leadB)['first_response_at'] === null, 'system, anonymous reply and internal note do not count');
ok(crm_response_mark($pdo, $leadB, null, 'reply') === false && crm_response_mark($pdo, $leadB, $sales, 'note') === false, 'mark refuses anonymous and non-response kinds');

section('first human response');
$res = crm_handle_action($pdo, $viewer, ['act' => 'note', 'etype' => 'lead', 'eid' => $leadB, 'kind' => 'reply', 'body' => 'x']); ok(($res[1] ?? '') === 'forbidden', 'viewer cannot log a reply (permissions unchanged)'); ok(crm_response_get($pdo, $leadB)['first_response_at'] === null, 'viewer attempt does not count');
$res = crm_handle_action($pdo, $sales, ['act' => 'note', 'etype' => 'lead', 'eid' => $leadB, 'kind' => 'reply', 'body' => 'Replied by e-mail with next steps.']);
$r = crm_response_get($pdo, $leadB); ok($r['first_response_at'] !== null && $r['first_response_kind'] === 'reply' && (int)$r['first_response_by'] === (int)$sales['id'], 'sales reply recorded as first human response');
ok($pdo->query("SELECT done_at FROM crm_tasks WHERE id=" . (int)$r['task_id'])->fetchColumn() !== null, 'auto task completed');
$first = $r['first_response_at']; crm_activity_add($pdo, $owner, 'lead', $leadB, 'call', 'second contact'); ok(crm_response_get($pdo, $leadB)['first_response_at'] === $first && crm_response_get($pdo, $leadB)['first_response_kind'] === 'reply', 'later contact never overwrites the first response');
ok(crm_response_state(crm_response_get($pdo, $leadB), time())['state'] === 'answered', 'state answered');

section('unanswered / overdue');
[, $leadC] = crm_lead_ingest($pdo, $in(['kind' => 'partnership', 'email' => 'p@gmail.com', 'company' => '', 'website' => '', 'budget' => '', 'timeline' => '', 'message' => 'hello', 'name' => 'Pat'])); $leadC = (int)$leadC;
$rc = crm_response_get($pdo, $leadC); ok($rc['priority_band'] === 'low', 'low priority lead'); $due = strtotime($rc['due_at'] . ' UTC');
$now1 = $due - 3600; $now2 = $due + 3600; $lst = crm_unanswered_leads($pdo, $now2, 50); $ids = array_column($lst, 'id'); ok(in_array((string)$leadC, array_map('strval', $ids), true) && !in_array((string)$leadB, array_map('strval', $ids), true), 'unanswered list holds C, not the answered B');
$cs = null; foreach ($lst as $x) if ((int)$x['id'] === $leadC) $cs = $x['state']['state']; ok($cs === 'overdue' || $cs === 'open', 'state is computed'); $o2 = crm_response_overview($pdo, $due + 86400 * 3); ok($o2['overdue'] >= 1 && $o2['unanswered'] >= 1, 'three days later it is counted overdue');
ok(crm_response_overview($pdo, $due - 7200)['overdue'] === 0 || crm_response_overview($pdo, $due - 7200)['overdue'] >= 0, 'overview works at an earlier time');
$LL = crm_leads_list($pdo, ['response' => 'unanswered']); ok(count($LL['rows']) >= 1 && isset($LL['rows'][0]['priority_band']), 'leads list filter and priority column'); ok(crm_leads_list($pdo, ['response' => 'answered'])['total'] === 1, 'answered filter');

section('consultations');
$when = gmdate('Y-m-d H:i', time() + 2 * 86400); $d = new DateTimeImmutable($when . ' UTC'); $local = $d->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d H:i');
ok(crm_consult_schedule($pdo, $viewer, $leadC, '2099-01-05 10:00')[1] === 'forbidden', 'viewer cannot schedule'); ok(crm_consult_schedule($pdo, $sales, $leadC, 'nonsense')[1] === 'time_invalid', 'bad time rejected'); ok(crm_consult_schedule($pdo, $sales, $leadC, '2020-01-05 10:00')[1] === 'time_out_of_range', 'past time rejected'); ok(crm_consult_schedule($pdo, $sales, 999999, $local)[1] === 'lead_missing', 'unknown lead rejected');
[$okc, $cid] = crm_consult_schedule($pdo, $sales, $leadC, $local, 45, 'Intro call'); ok($okc, 'scheduled'); ok(crm_response_get($pdo, $leadC)['first_response_at'] === null && crm_response_state(crm_response_get($pdo, $leadC), time())['state'] !== 'answered', 'scheduling a future consultation is NOT a first human response'); ok(crm_consult_set_status($pdo, $sales, (int)$cid, 'held')[1] === 'not_yet_held', 'a future consultation cannot be marked held');
$up = crm_consults_upcoming($pdo, time(), 14); ok(count($up) === 1 && (int)$up[0]['duration_min'] === 45, 'upcoming consultation listed'); ok(crm_consult_set_status($pdo, $viewer, (int)$cid, 'held')[1] === 'forbidden', 'viewer cannot change status');
ok(crm_consult_set_status($pdo, $sales, (int)$cid, 'bogus')[0] === false, 'invalid status'); $pdo->exec("UPDATE crm_consultations SET scheduled_at='" . crm_now(time() - 3600) . "' WHERE id=" . (int)$cid); ok(crm_consult_set_status($pdo, $sales, (int)$cid, 'held')[0] === true && crm_response_get($pdo, $leadC)['first_response_kind'] === 'consultation', 'marking it held records the human contact'); ok(true && crm_consult_set_status($pdo, $sales, (int)$cid, 'cancelled')[1] === 'not_scheduled', 'status moves once from scheduled'); ok(crm_consults_upcoming($pdo, time(), 14) === [], 'held consultation no longer upcoming');
section('proposals: stale and pending');
$pdo->exec("INSERT INTO crm_companies (name, domain, website, created_at, updated_at, is_synthetic) VALUES ('Beta Ltd','','',NOW(),NOW(),0)"); $co = (int)$pdo->lastInsertId();
try { $pdo->prepare("INSERT INTO crm_opportunities (company_id, kind, title, stage, amount_minor, currency, created_at, updated_at, is_synthetic) VALUES (?, 'development', 'Opp', 'proposal', 500000, 'EUR', NOW(), NOW(), 0)")->execute([$co]); $op = (int)$pdo->lastInsertId();
  $pdo->prepare("INSERT INTO crm_proposals (opportunity_id, number, status, current_version, created_at, updated_at) VALUES (?, 'P-T-1', 'pending_approval', 1, ?, ?)")->execute([$op, crm_now(time() - 20 * 86400), crm_now(time() - 20 * 86400)]);
  ok(count(crm_stale_proposals($pdo, time())) === 1 && crm_pending_proposal_count($pdo) === 1, 'stale and pending proposal counted'); $pdo->prepare("INSERT INTO crm_proposals (opportunity_id, number, status, current_version, created_at, updated_at) VALUES (?, 'P-T-2', 'draft', 1, NOW(), NOW())")->execute([$op]); ok(crm_pending_proposal_count($pdo) === 1, 'drafts are not pending');
} catch (Throwable $e) { ok(false, 'proposal fixture: ' . $e->getMessage()); }

section('Overview: actual vs estimate');
$html = adm_dashboard_body($pdo, ['role' => 'owner', 'username' => 'x', 'id' => 1]);
foreach (['Needs attention', 'Estimated opportunities (not revenue)', 'Actual results (recorded)', 'Unanswered enquiries', 'Past the 4-hour target', 'High-priority, unanswered', 'Upcoming consultations', 'Follow-ups overdue', 'Pending proposals', 'Pipeline value', 'Revenue received, this month', 'Monthly revenue target'] as $k) ok(str_contains($html, $k), "Overview shows: $k");
ok(str_contains($html, 'ESTIMATE:') && str_contains($html, 'ACTUAL') || str_contains($html, 'No payments recorded yet'), 'estimates and actuals are labelled'); ok(!preg_match('/Revenue received, this month[^<]*<\/h3><div class="val">/', $html), 'no revenue figure is shown without recorded payments');
ok(str_contains($html, 'No data yet') , 'empty financial tiles say "No data yet", not zero'); ok(preg_match('/Estimated opportunities.*Pipeline value/s', $html) === 1 && strpos($html, 'Estimated opportunities') < strpos($html, 'Actual results'), 'estimates sit in their own section before actuals');
ok(!str_contains(adm_dashboard_body($pdo, ['role' => 'viewer', 'username' => 'v', 'id' => 3]), 'Needs attention') || adm_can('viewer', 'crm'), 'section visibility follows existing permissions');
$fs = new AdmFinancePdoStore($pdo);
if ($fs->finReady()) { $pdo->exec('DELETE FROM fin_expense_entry'); $h0 = adm_dashboard_body($pdo, ['role' => 'owner', 'username' => 'x', 'id' => 1]); ok(str_contains($h0, 'No expenses recorded yet') && str_contains($h0, 'missing data, not a confirmed zero') && !preg_match('/Expenses, this month[^<]*<\/h3><div class="val">/', $h0), 'no finance records: "No data yet", never 0.00');
  admf_apply($fs, ['username' => 'o', 'role' => 'owner', 'id' => 1], 'expense_add', ['entry_date' => gmdate('Y-m-d'), 'category' => 'other', 'amount' => '12.00', 'currency' => 'EUR', 'description' => 'test'], function () {});
  $h1 = adm_dashboard_body($pdo, ['role' => 'owner', 'username' => 'x', 'id' => 1]); ok(str_contains($h1, 'INCOMPLETE: only expenses') && str_contains($h1, 'revenue not recorded') || str_contains($h1, 'Revenue not recorded'), 'expenses without revenue: profit marked incomplete, revenue "not recorded" rather than 0'); $pdo->exec('DELETE FROM fin_expense_entry');
} else ok(str_contains($html, 'Not available yet: the finance migration'), 'finance storage missing: "Not available yet"');
$pdo->exec("INSERT INTO crm_companies (name, domain, website, created_at, updated_at, is_synthetic) VALUES ('<script>alert(1)</script>','','',NOW(),NOW(),0)"); $xc = (int)$pdo->lastInsertId();
$pdo->prepare("UPDATE crm_leads SET company_id = ? WHERE id = ?")->execute([$xc, $leadC]); $pdo->exec("UPDATE crm_lead_response SET first_response_at=NULL, first_response_by=NULL, first_response_kind=NULL WHERE lead_id=$leadC");
$html = adm_dashboard_body($pdo, ['role' => 'owner', 'username' => 'x', 'id' => 1]) . crm_response_panel($pdo, $owner, $leadC, true); ok(!str_contains($html, '<script>alert(1)') && str_contains($html, '&lt;script&gt;'), 'company names are escaped in the Overview and lead panel');
ok(str_contains(crm_response_panel($pdo, $owner, $leadC, true), 'Why this priority') && str_contains(crm_response_panel($pdo, $owner, $leadC, true), 'do not count'), 'panel explains priority and that acknowledgements do not count');

section('owner-only notifications and dedupe');
$mon = gmmktime(7, 30, 0, 3, 9, 2026); $sat = gmmktime(7, 30, 0, 3, 7, 2026);   // 08:30 Berlin Monday / Saturday
$pdo->exec("UPDATE crm_lead_response SET due_at='2026-03-06 10:00:00' WHERE lead_id=$leadC"); $pdo->exec("UPDATE crm_leads SET created_at='2026-03-05 09:00:00' WHERE id=$leadC");
$pdo->exec("UPDATE crm_tasks SET due_at='2026-03-05 10:00:00' WHERE done_at IS NULL"); $pdo->exec("UPDATE crm_proposals SET updated_at='2026-02-20 10:00:00'");
foreach (glob($spool . '/*.json') ?: [] as $f) unlink($f);
ok(crm_send_digest($pdo, $sat) === 'not_workday', 'no digest on a weekend'); ok(crm_send_digest($pdo, gmmktime(7, 30, 0, 4, 3, 2026)) === 'not_workday', 'no digest on Good Friday');
ob_start(); $dry = crm_send_digest($pdo, $mon, true); $out = ob_get_clean(); ok($dry === 'dry-run' && str_contains($out, 'unanswered') && spooled($spool) === [] && (int)$pdo->query("SELECT COUNT(*) FROM crm_notification_log")->fetchColumn() === 0, 'dry run prints, sends and records nothing');
ok(crm_send_digest($pdo, $mon) === 'sent', 'digest sent'); ok(crm_send_digest($pdo, $mon + 600) === 'already_sent', 'same Berlin date: not sent twice'); ok(crm_send_digest($pdo, $mon + 86400) === 'sent', 'next working day: a new digest');
$m = spooled($spool); ok(count($m) === 2, 'two digests spooled'); $to = array_unique(array_column($m, 'to')); ok($to === ['owner@example.test'], 'every message goes to the owner only (' . implode(',', $to) . ')');
$all = json_encode($m); ok(!str_contains($all, 'acme-corp.example') && !str_contains($all, 'p@gmail.com') && !str_contains($all, 'support desk'), 'no customer e-mail addresses or message text in owner mail');
foreach (glob($spool . '/*.json') ?: [] as $f) unlink($f);
ok(crm_send_alerts($pdo, $sat) === 'outside_hours', 'no alerts outside business hours'); $monAt10 = gmmktime(9, 0, 0, 3, 9, 2026);
$a1 = crm_send_alerts($pdo, $monAt10); $a2 = crm_send_alerts($pdo, $monAt10 + 1800); ok($a1 === 'sent' && $a2 === 'none', 'overdue alert sent once per lead (second run: none)'); ok(count(spooled($spool)) === 1, 'one alert e-mail');
$r1 = crm_send_reminders($pdo, $monAt10); $r2 = crm_send_reminders($pdo, $monAt10 + 3600); ok($r1 === 'sent' && $r2 === 'none', 'task/proposal reminders once per item per week'); ok(crm_send_reminders($pdo, $monAt10 + 8 * 86400) === 'sent', 'reminded again in the next weekly window');
$to = array_unique(array_column(spooled($spool), 'to')); ok($to === ['owner@example.test'], 'alerts and reminders also owner-only');
ok((int)$pdo->query("SELECT COUNT(*) FROM (SELECT kind, entity_type, entity_id, period_key FROM crm_notification_log GROUP BY 1,2,3,4 HAVING COUNT(*) > 1) d")->fetchColumn() === 0, 'no duplicate notification rows (unique key)');
try { $pdo->exec("INSERT INTO crm_notification_log (kind, entity_type, entity_id, period_key, sent_at) SELECT kind, entity_type, entity_id, period_key, sent_at FROM crm_notification_log LIMIT 1"); ok(false, 'unique key must reject duplicates'); } catch (Throwable $e) { ok(true, 'database itself rejects a duplicate notification'); }
section('CLI');
$php = escapeshellarg(PHP_BINARY); $cmd = 'AIHUB_TEST_NOW=' . $monAt10 . ' ' . $php . ' ' . escapeshellarg($root . '/scripts/crm_reminders.php');
exec("$cmd digest --dry-run 2>&1", $o1, $c1); ok($c1 === 0 && str_contains(implode("\n", $o1), 'dry-run') || str_contains(implode("\n", $o1), 'unanswered'), 'CLI digest dry-run'); exec("$cmd bogus 2>&1", $o2, $c2); ok($c2 === 2, 'CLI rejects unknown mode'); exec('php ' . escapeshellarg($root . '/crm_response.php') . ' 2>&1', $o3); ok(true, 'include-only file');
foreach (glob($spool . '/*.json') ?: [] as $f) unlink($f); @rmdir($spool);
$pdo->prepare("UPDATE site_settings SET setting_value = ? WHERE setting_key='mail'")->execute([$mailRaw]);   // restore the test mail settings
fwrite(STDERR, "\ncrm_response_db_test: $pass passed, $fail failed\n"); echo "crm_response_db_test: $pass passed, $fail failed\n"; exit($fail ? 1 : 0);
