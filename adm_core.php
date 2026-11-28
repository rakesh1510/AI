<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
/**
 * AIHubpedia Control Center foundation (Phase 8A). One sign-in (the existing CRM accounts), role-based navigation, honest KPI tiles.
 * Rules: no table is created or altered; no figure is invented (a tile without records says so); audit rows go to the existing crm_audit_log;
 * the primary reporting currency is EUR (other currencies are never converted or summed with it). Later phases add modules to adm_modules().
 */
require_once __DIR__ . '/crm_dashboard.php';
require_once __DIR__ . '/adm_nav.php';

const ADM_REPORT_CURRENCY = 'EUR';

/** Control Center permissions per CRM role. Deliberately separate from crm_roles() (Phase 6/7 tests assert that list). Unknown roles get nothing. */
function adm_role_perms(): array {
  return [
    'owner'    => ['center', 'account', 'crm', 'universe', 'legacy', 'analytics', 'tools', 'tools_publish', 'finance', 'advertising', 'operations', 'performance', 'access'],
    'sales'    => ['center', 'account', 'crm', 'advertising'],
    'viewer'   => ['center', 'account', 'crm'],
    'editor'   => ['center', 'account', 'universe', 'tools'],
    'verifier' => ['center', 'account', 'universe'],
  ];
}
function adm_can(?string $role, string $perm): bool { return $role !== null && in_array($perm, adm_role_perms()[$role] ?? [], true); }

/** Module registry: existing tools are linked, later phases are listed as planned (no data, no fake pages). */
function adm_modules(): array {
  return [
    'account'     => ['label' => 'Account and password', 'href' => '/admin-center.php?m=account', 'live' => true, 'internal' => true, 'perm' => 'account', 'group' => 'Account', 'note' => 'Who you are signed in as, your modules, and your password.'],
    'dashboard'   => ['label' => 'Overview', 'href' => '/admin-center.php', 'perm' => 'center', 'live' => true, 'group' => 'Control Center'],
    'crm'         => ['label' => 'CRM and leads', 'href' => '/admin-center.php?m=crm', 'perm' => 'crm', 'live' => true, 'internal' => true, 'group' => 'Business', 'note' => 'Leads, pipeline, proposals, contracts, campaigns, partners.'],
    'universe'    => ['label' => 'AI Universe review', 'href' => '/admin-center.php?m=universe', 'perm' => 'universe', 'live' => true, 'internal' => true, 'group' => 'Business', 'note' => 'Propose, adopt and independently verify AI Universe records.'],
    'editorial'   => ['label' => 'Tool profile verification', 'href' => '/admin-center.php?m=editorial', 'perm' => 'universe', 'live' => true, 'internal' => true, 'group' => 'Business', 'note' => 'Evidence ledger, research notes and independent verification of tool profiles. Replaces the shared-password editorial page.'],
    'access'      => ['label' => 'Users, roles and audit', 'href' => '/admin-center.php?m=access', 'perm' => 'access', 'live' => true, 'internal' => true, 'group' => 'Operations', 'note' => 'Read-only user list, role matrix, audit log and system health.'],
    'legacy'      => ['label' => 'Legacy admin', 'href' => '/admin.php', 'perm' => 'legacy', 'live' => true, 'group' => 'Business', 'note' => 'Submissions, featured plans, leads and editorial pages. Uses its own sign-in until they move here.'],
    'analytics'   => ['label' => 'Analytics', 'href' => '/admin-center.php?m=analytics', 'perm' => 'analytics', 'live' => true, 'internal' => true, 'group' => 'Growth', 'note' => 'Visitors, page views, tool clicks, sources, countries and conversions.'],
    'tools'       => ['label' => 'AI tool management', 'href' => '/admin-center.php?m=tools', 'live' => true, 'internal' => true, 'perm' => 'tools', 'group' => 'Growth', 'scope' => ['Add, edit, publish, unpublish and soft-delete tools with restore', 'Search, filters, bulk actions, categories and tags', 'Submission moderation and featured placements']],
    'finance'     => ['label' => 'Finance and revenue', 'href' => '/admin-center.php?m=finance', 'live' => true, 'internal' => true, 'perm' => 'finance', 'group' => 'Money', 'scope' => ['Income and expense entries, invoices and payment status (paid, pending, refunded, overdue)', 'Monthly recurring revenue, profit and loss, CSV export', 'EUR primary reporting currency with later multi-currency support']],
    'advertising' => ['label' => 'Advertising', 'href' => '/admin-center.php?m=advertising', 'phase' => '8E', 'live' => true, 'internal' => true, 'perm' => 'advertising', 'group' => 'Money', 'note' => 'Read-only hub: campaigns, featured placements and paid listing submissions. Booking, renewals and reminders arrive in Phase 8E.', 'scope' => ['Advertiser profiles, campaigns, featured listing subscriptions, sponsored newsletter bookings', 'Renewal tracking and follow-up reminders', 'Outbound messages only after human approval']],
    'operations'  => ['label' => 'Operations', 'phase' => '8F', 'perm' => 'operations', 'group' => 'Operations', 'scope' => ['AI Universe verification progress (independent human review is unchanged)', 'Development phases and tasks, release history', 'Error monitoring, backup status and system health']],
    'performance' => ['label' => 'Performance', 'phase' => '8G', 'perm' => 'performance', 'group' => 'Operations', 'scope' => ['Core Web Vitals (LCP, INP, CLS) and database and cache performance', 'Search Console where authorized', 'Scheduled business reports and operational alerts']],
  ];
}

function adm_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function adm_n(int $n): string { return number_format($n); }
function adm_eur(int $minor): string { return crm_money_format($minor, ADM_REPORT_CURRENCY); }

/** Writes a Control Center audit row to the existing crm_audit_log (never throws into the page). */
function adm_audit(PDO $pdo, array $user, string $action, string $summary = ''): void {
  try { crm_audit($pdo, $user, $action, 'admin_center', null, $summary); } catch (Throwable $e) { sec_log_exception($e, 'admin-center audit'); }
}

/* ---- KPI tiles: every tile is ['label', 'value' (string|null), 'note', 'state' ok|none|na]. null value = no record to show. ---- */
function adm_tile(string $label, ?string $value, string $note = '', string $state = 'ok'): array { return ['label' => $label, 'value' => $value, 'note' => $note, 'state' => $value === null ? ($state === 'ok' ? 'none' : $state) : $state]; }
function adm_safe(callable $f, $fallback = null) { try { return $f(); } catch (Throwable $e) { if (!($e instanceof PDOException) || !in_array((string)$e->getCode(), ['42S02', '42S22'], true)) sec_log_exception($e, 'admin-center tile'); return $fallback; } }

function adm_tiles_crm(PDO $pdo): array {
  $d = adm_safe(fn() => crm_dashboard($pdo, 30));
  if (!$d) return [adm_tile('CRM', null, 'The CRM could not be read.', 'na')];
  return [adm_tile('Open opportunities', adm_n((int)$d['open_opportunities']), 'A count of CRM records in stages that are not won or lost.')];
}

/* ---- Phase 7B CEO overview: counts and recorded figures only. Actual results and estimated opportunities are separate sections with separate labels. ---- */
function adm_dur(?int $min): string { return $min === null ? '' : ($min >= 60 ? intdiv($min, 60) . ' h ' . ($min % 60) . ' min' : $min . ' min'); }
function adm_tiles_attention(PDO $pdo): array {
  $o = adm_safe(fn() => crm_response_overview($pdo)); if (!$o) return [adm_tile('Enquiries', null, 'The CRM could not be read.', 'na')];
  $unready = 'Needs the Phase 7B database migration (database/phase7b_lead_management.sql).';
  $t = [adm_tile('New enquiries, last 7 days', $o['new_7d'] === null ? null : adm_n($o['new_7d']), 'Real enquiries only; test records excluded.')];
  $t[] = adm_tile('Unanswered enquiries', $o['ready'] ? adm_n($o['unanswered']) : null, $o['ready'] ? 'No human reply yet. Automatic acknowledgements do not count.' : $unready, $o['ready'] ? 'ok' : 'na');
  $t[] = adm_tile('Past the 4-hour target', $o['ready'] ? adm_n($o['overdue']) : null, $o['ready'] ? 'Four business hours, Mon-Fri 09:00-17:00 Europe/Berlin, holidays excluded.' : $unready, $o['ready'] ? 'ok' : 'na');
  $t[] = adm_tile('High-priority, unanswered', $o['ready'] ? adm_n($o['high_unanswered']) : null, $o['ready'] ? 'Score 60 or more; the reasons are shown on each lead.' : $unready, $o['ready'] ? 'ok' : 'na');
  $t[] = adm_tile('Upcoming consultations (14 days)', $o['ready'] ? adm_n(count($o['consultations'])) : null, $o['ready'] ? ($o['consultations'] ? 'Next: ' . crm_fmt_berlin($o['consultations'][0]['scheduled_at']) . ' (Berlin).' : 'None scheduled.') : $unready, $o['ready'] ? 'ok' : 'na');
  $t[] = adm_tile('Follow-ups overdue', $o['tasks_overdue'] === null ? null : adm_n($o['tasks_overdue']), 'Open tasks past their due time.');
  $t[] = adm_tile('Pending proposals', $o['pending_proposals'] === null ? null : adm_n($o['pending_proposals']), $o['stale_proposals'] ? adm_n($o['stale_proposals']) . ' waiting more than ' . CRM_STALE_PROPOSAL_DAYS . ' days.' : 'Pending approval or approved, not yet accepted.');
  $t[] = adm_tile('Average first response (30 days)', $o['ready'] && $o['avg_minutes'] !== null ? adm_dur($o['avg_minutes']) : null, $o['ready'] ? ($o['avg_minutes'] !== null ? $o['within_target_pct'] . '% of ' . $o['answered_30d'] . ' answered within target. Business minutes.' : 'No answered enquiries in the period yet.') : $unready, $o['ready'] ? 'ok' : 'na');
  return $t;
}
function adm_attention_list(PDO $pdo): string {
  $o = adm_safe(fn() => crm_response_overview($pdo)); if (!$o || empty($o['unanswered_list'])) return '';
  $h = '<div class="panel scroll" role="region" tabindex="0" aria-label="Unanswered enquiries"><table><caption class="sr" style="position:absolute;left:-999px">Unanswered enquiries, most urgent first</caption><thead><tr><th scope="col">Enquiry</th><th scope="col">Priority</th><th scope="col">Status</th></tr></thead><tbody>';
  foreach ($o['unanswered_list'] as $r) $h .= '<tr><th scope="row"><a class="admLink" href="/crm.php?v=lead&amp;id=' . (int)$r['id'] . '">#' . (int)$r['id'] . ' ' . adm_h($r['company'] ?: 'individual') . '</a></th><td>' . adm_h(crm_priority_label($r['priority_band'])) . ' (' . (int)$r['priority_score'] . ')</td><td>' . ($r['state']['state'] === 'overdue' ? 'Overdue since ' : 'Due ') . adm_h(crm_fmt_berlin($r['due_at'])) . '</td></tr>';
  return $h . '</tbody></table></div><p><a class="admLink" href="/crm.php?v=leads&amp;response=unanswered">All unanswered enquiries</a></p>';
}
/** @return array{estimate:array,actual:array,targets:array} tiles for the three finance-driven sections; each says "Not available" when the finance tables or records are missing. */
function adm_tiles_finance(PDO $pdo): array {
  $store = adm_safe(fn() => class_exists('AdmFinancePdoStore') ? new AdmFinancePdoStore($pdo) : null);
  $d = adm_safe(fn() => crm_dashboard($pdo, 30)); $cur = ADM_REPORT_CURRENCY; $pv = $d['pipeline_value'][$cur] ?? null; $others = $d ? array_diff(array_keys($d['pipeline_value']), [$cur]) : [];
  $rep = $store ? adm_safe(fn() => admf_report(admf_load($store))) : null; $e = $rep['currencies'][$cur] ?? null; $ym = $rep ? admf_ym($rep['as_of']) : '';
  $est = [adm_tile('Pipeline value, open opportunities (' . $cur . ')', $pv !== null ? adm_eur((int)$pv) : null, 'ESTIMATE: expected deal values typed into the CRM, not revenue and not a forecast of cash.' . ($others ? ' Other currencies (' . implode(', ', $others) . ') are not converted.' : ''), $d ? 'ok' : 'na'),
          adm_tile('Weighted pipeline (' . $cur . ')', $e && $e['forecast'] > 0 ? adm_eur((int)$e['forecast']) : null, 'ESTIMATE: stage weights are planning assumptions (5-70%), not measured win rates.' . ($e && $e['forecast'] > 0 ? '' : ' No open opportunities with a value.'), $rep ? 'ok' : 'na')];
  // Zero vs missing: a figure is shown (including 0.00) only when records exist to back it; no records = "No data yet"; finance storage missing = "Not available yet".
  $ready = $store && adm_safe(fn() => $store->finReady()) === true; $st = $ready && $rep ? 'ok' : 'na'; $why = $ready ? '' : 'Not available yet: the finance migration (Phase 8D) is not applied on this server.';
  $rv = $d['revenue'][$cur] ?? null; $crmK = $rv && ($rv['mrr'] > 0 || $rv['contracted'] > 0 || $rv['invoiced'] > 0 || $rv['received'] > 0 || $rv['recognized'] > 0); $crmR = $rv && ($rv['invoiced'] > 0 || $rv['received'] > 0);
  $hasR = $ready && $e && $e['has_revenue']; $hasX = $ready && $e && $e['has_expenses']; $m = $e['series'][$ym] ?? null;
  $act = [adm_tile('Revenue received, this month (' . $cur . ')', $hasR && $m ? adm_eur((int)$m['income']) : null, $why ?: ($hasR ? 'ACTUAL: payments recorded against contracts, less refunds' . ($m && (int)$m['income'] === 0 ? '. Zero here means records exist but none fall in this month.' : '.') . ' Recorded manually, not bank-verified.' : 'No payments recorded yet (this is missing data, not a confirmed zero).'), $st),
          adm_tile('Expenses, this month (' . $cur . ')', $hasX && $m ? adm_eur((int)$m['expenses']) : null, $why ?: ($hasX ? 'ACTUAL: recorded expenses (cash basis).' : 'No expenses recorded yet.'), $st),
          adm_tile('Profit, this month (' . $cur . ')', ($hasR || $hasX) && $m ? adm_eur((int)$m['profit']) : null, $why ?: (($hasR && $hasX) ? 'ACTUAL: received minus expenses; only as complete as the records.' : (($hasR || $hasX) ? 'INCOMPLETE: only ' . ($hasR ? 'revenue' : 'expenses') . ' are recorded, so this is not a full profit figure.' : 'Needs recorded revenue and expenses.')), $st),
          adm_tile('Year to date (' . $cur . ')', $e && ($hasR || $hasX) ? 'Revenue ' . ($hasR ? adm_eur((int)$e['ytd_income']) : 'not recorded') . ' / expenses ' . ($hasX ? adm_eur((int)$e['ytd_expenses']) : 'not recorded') . ' / profit ' . ($hasR && $hasX ? adm_eur((int)$e['ytd_profit']) : 'incomplete') : null, $why ?: 'ACTUAL, cash basis, recorded entries only. "Not recorded" means no entries exist, not zero.', $st),
          adm_tile('Recurring revenue (MRR, ' . $cur . ')', $crmK ? adm_eur((int)$rv['mrr']) : null, $crmK ? 'Active recurring contracts recorded in the CRM.' : 'No contracts recorded yet.'),
          adm_tile('Revenue received, all time (' . $cur . ')', $crmR ? adm_eur((int)$rv['received']) : null, $crmR ? 'Payments recorded against CRM contracts.' : 'No invoices or payments recorded yet (missing data, not a confirmed zero).'),
          adm_tile('Renewals due in 60 days', $crmK && $d ? adm_n((int)$d['renewals_60d']) : null, $crmK ? 'Active contracts reaching their end date.' : 'No contracts recorded yet.')];
  $tg = []; if ($rep && $store) { $lad = adm_safe(fn() => admf_ladder($rep, $store->targets()));
    if ($lad) { $month = null; foreach ($lad['custom'] as $c) if ($c['month'] === $ym && $c['metric'] === 'received') $month = $c;
      $tg[] = $month ? adm_tile('Monthly revenue target (' . $ym . ')', adm_eur((int)$month['amount_minor']), 'TARGET set by you' . ($lad['income_minor'] !== null ? '. Recorded so far: ' . adm_eur((int)$lad['income_minor']) . ' (' . ($month['amount_minor'] > 0 ? round($lad['income_minor'] / $month['amount_minor'] * 100) : 0) . '%).' : '. No recorded income to compare yet.'))
                      : adm_tile('Monthly revenue target (' . $ym . ')', null, 'No custom target set for this month. Set one in Finance, Targets.');
      $tg[] = adm_tile('Next ladder step', $lad['next_eur'] !== null ? adm_eur($lad['next_eur'] * 100) . ' / month' : 'All steps reached', 'TARGET (aspirational, not a forecast)' . ($lad['active'] ? '' : '; monitoring starts ' . $lad['start']) . ($lad['income_minor'] !== null ? '. Recorded income this month: ' . adm_eur((int)$lad['income_minor']) . '.' : '. No recorded income to compare yet.')); } }
  return ['estimate' => $est, 'actual' => $act, 'targets' => $tg];
}

function adm_tiles_universe(PDO $pdo): array {
  $cnt = fn(string $sql) => adm_safe(fn() => (int)$pdo->query($sql)->fetchColumn());
  $ent = $cnt("SELECT COUNT(*) FROM uni_entity WHERE state = 'verified' AND is_current = 1");
  if ($ent === null) return [adm_tile('AI Universe', null, 'The AI Universe tables are not installed on this server.', 'na')];
  $rev = ($cnt("SELECT COUNT(*) FROM uni_entity WHERE state = 'in_review'") ?? 0) + ($cnt("SELECT COUNT(*) FROM uni_event WHERE state = 'in_review'") ?? 0) + ($cnt("SELECT COUNT(*) FROM uni_edge WHERE state = 'in_review'") ?? 0);
  $imp = ($cnt("SELECT COUNT(*) FROM uni_entity WHERE origin = 'import' AND state = 'proposed' AND is_current = 1") ?? 0) + ($cnt("SELECT COUNT(*) FROM uni_event WHERE origin = 'import' AND state = 'proposed' AND is_current = 1") ?? 0) + ($cnt("SELECT COUNT(*) FROM uni_edge WHERE origin = 'import' AND state = 'proposed' AND is_current = 1") ?? 0);
  return [
    adm_tile('Verified entities', adm_n($ent), 'Companies, products, models and versions that a second person verified.'),
    adm_tile('Verified events', adm_n((int)$cnt("SELECT COUNT(*) FROM uni_event WHERE state = 'verified' AND is_current = 1")), 'Dated, sourced and verified.'),
    adm_tile('Verified relationships', adm_n((int)$cnt("SELECT COUNT(*) FROM uni_edge WHERE state = 'verified' AND is_current = 1")), 'Each with its own source.'),
    adm_tile('Awaiting independent review', adm_n($rev), 'Entities, events and relationships in review. Only a different human verifier can approve them.'),
    adm_tile('Imported leads not yet adopted', adm_n($imp), 'Proposed, private and unverified until a human editor adopts them.'),
  ];
}

function adm_tiles_pending(): array {
  return [adm_tile('Website performance (Core Web Vitals)', null, 'Not available yet: arrives with Phase 8G.', 'na')];
}

function adm_health(PDO $pdo): array {
  $h = [];
  $h[] = ['Database', adm_safe(fn() => (bool)$pdo->query('SELECT 1')->fetchColumn(), false) ? 'Reachable' : 'Not reachable', true];
  $h[] = ['CRM schema', function_exists('crm_tables_ready') && adm_safe(fn() => crm_tables_ready(), false) ? 'Installed' : 'Missing', true];
  $h[] = ['AI Universe schema', adm_safe(fn() => $pdo->query('SELECT 1 FROM uni_entity LIMIT 1') !== false, false) ? 'Installed' : 'Not installed', null];
  $e = function_exists('adm_env') ? adm_env() : null; $h[] = ['Environment label (AIHUB_ENV_LABEL)', $e ? ($e['key'] === 'unset' ? 'NOT SET: banner shows "ENVIRONMENT NOT SET"' : $e['label']) : 'unknown', $e && $e['key'] !== 'unset' ? true : null];
  $cf = getenv('AIHUB_CONFIG_FILE') ?: __DIR__ . '/config.local.php'; $h[] = ['Private config file (read by this web worker)', is_file($cf) ? (is_readable($cf) ? 'Found and readable' : 'Found but NOT readable by the web server user') : 'Not found', is_file($cf) && is_readable($cf) ? true : false];
  $h[] = ['Web worker user', function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown') : 'unknown', null];
  $an = function_exists('analytics_enabled') ? analytics_enabled() : false;
  $h[] = ['Visitor tracking (AIHUB_ANALYTICS)', $an ? 'Enabled on this server' : 'Not enabled on this server', null];
  if ($an) $h[] = ['Visitor hash salt (AIHUB_ANALYTICS_SALT)', function_exists('analytics_salt_ok') && analytics_salt_ok() ? 'Set (strong)' : 'WARNING: missing or shorter than 32 characters - no visitor hashes are recorded', null];
  $h[] = ['Signing secret', getenv('AIHUB_FORM_SECRET') ? 'Set in the environment' : 'Not set in the environment (file fallback in use)', null];
  $h[] = ['PHP version', PHP_VERSION, null];
  return $h;
}

/* ---- rendering ---- */
function adm_css(): string {
  return ':root{--bg:#f4f6fa;--surface:#fff;--ink:#14213d;--muted:#5b6472;--line:#d9dee7;--brand:#0b5fff;--brandInk:#fff;--ok:#0a7a3d;--na:#6b4b00;--navBg:#10203f;--navInk:#e8eefc}'
    . '@media (prefers-color-scheme:dark){:root{--bg:#0c1220;--surface:#151d2e;--ink:#e8eefc;--muted:#a9b4c7;--line:#2a3550;--brand:#5b9bff;--brandInk:#06142e;--ok:#5fd493;--na:#f0c36b;--navBg:#0a1020;--navInk:#e8eefc}}'
    . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}'
    . '.skip{position:absolute;left:-999px;top:0;background:var(--brand);color:var(--brandInk);padding:.6rem 1rem;z-index:60}.skip:focus{left:8px;top:8px}'
    . 'a{color:var(--brand)}a:focus-visible,button:focus-visible,summary:focus-visible{outline:3px solid var(--brand);outline-offset:2px}'
    . '.admEnv{display:flex;flex-wrap:wrap;gap:4px 12px;align-items:center;justify-content:center;padding:6px 12px;font-size:.88rem;text-align:center}.admEnv strong{letter-spacing:.08em}'
    . '.admEnv-staging{background:#fde68a;color:#451a03;border-bottom:3px solid #d97706}.admEnv-production{background:#b91c1c;color:#fff;border-bottom:3px solid #7f1d1d}.admEnv-unset,.admEnv-development,.admEnv-local{background:#e5e7eb;color:#1f2937;border-bottom:3px solid #6b7280}'
    . '.admTop{position:sticky;top:0;z-index:40;display:flex;align-items:center;justify-content:space-between;gap:10px 14px;flex-wrap:wrap;padding:8px 16px;background:var(--navBg);color:var(--navInk)}'
    . '.admBrandWrap{display:flex;align-items:center;gap:12px}.admBrand{font-weight:700;letter-spacing:.2px}a.admBrand{display:inline-flex;align-items:center;min-height:44px;color:inherit;text-decoration:none}.admWho{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:.95rem}.admWho form{margin:0}'
    . '.admId{display:inline-flex;align-items:center;gap:8px;min-height:44px;padding:0 .75rem;border:1px solid rgba(255,255,255,.28);border-radius:999px;color:var(--navInk);text-decoration:none}.admId .role{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;border:1px solid currentColor;border-radius:999px;padding:0 .45rem}'
    . '.admBtn{min-height:44px;min-width:44px;padding:.5rem .9rem;border:1px solid var(--line);border-radius:8px;background:var(--surface);color:var(--ink);font:inherit;cursor:pointer}.admMenuBtn{display:none}'
    . '.admLayout{display:grid;grid-template-columns:272px minmax(0,1fr);min-height:calc(100vh - 64px)}'
    . '.admSide{background:var(--navBg);color:var(--navInk);padding:12px;overflow:auto}.admSideHead{display:none}.admScrim{display:none}'
    . '.admGroup{margin:0 0 6px}.admGroup>summary{display:flex;align-items:center;justify-content:space-between;min-height:44px;padding:.35rem .6rem;cursor:pointer;font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;font-weight:700;opacity:.85;list-style:none;border-radius:8px}.admGroup>summary::-webkit-details-marker{display:none}'
    . '.admGroup>summary::after{content:"";width:8px;height:8px;border-right:2px solid currentColor;border-bottom:2px solid currentColor;transform:rotate(45deg);margin-right:4px;transition:transform .15s}.admGroup[open]>summary::after{transform:rotate(-135deg)}.admGroup>summary:hover{background:rgba(255,255,255,.08)}'
    . '.admSide ul{list-style:none;margin:0;padding:0}.admSide a{display:flex;align-items:center;justify-content:space-between;gap:8px;min-height:44px;padding:.5rem .75rem;border-radius:8px;color:var(--navInk);text-decoration:none}.admSide a:hover{background:rgba(255,255,255,.1)}'
    . '.admItem.is-active>a{background:rgba(91,155,255,.22);font-weight:600}.admSide a[aria-current=page]{background:var(--brand);color:var(--brandInk);font-weight:600}.admSub{margin:2px 0 8px 14px!important;padding-left:10px!important;border-left:2px solid rgba(255,255,255,.2)}.admSub a{min-height:44px;font-size:.93rem}'
    . '.badge{font-size:.72rem;border:1px solid currentColor;border-radius:999px;padding:0 .5rem;white-space:nowrap}'
    . '.admCrumbs{margin:0 0 6px}.admCrumbs ol{display:flex;flex-wrap:wrap;gap:2px 0;list-style:none;margin:0;padding:0;font-size:.88rem;color:var(--muted)}.admCrumbs li+li::before{content:"/";margin:0 .5rem;opacity:.6}.admCrumbs a{display:inline-flex;align-items:center;min-height:44px;color:var(--brand)}.admCrumbs [aria-current]{color:var(--ink);font-weight:600}'
    . '.admMain{padding:20px;max-width:1200px;width:100%;min-width:0}.admMain h1{margin:.2rem 0 .3rem;font-size:1.6rem}.lead{color:var(--muted);margin:0 0 1rem}'
    . '.admGrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px;margin:8px 0 22px;padding:0;list-style:none}'
    . '.tile{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:14px}.tile h3{margin:0 0 .3rem;font-size:.9rem;color:var(--muted);font-weight:600}'
    . '.tile .val{font-size:1.7rem;font-weight:700;line-height:1.2}.tile .nv{font-size:1.05rem;font-weight:600;color:var(--na)}.tile p{margin:.4rem 0 0;font-size:.85rem;color:var(--muted)}'
    . '.panel{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:14px;margin-bottom:18px}table{border-collapse:collapse;width:100%}th,td{text-align:left;padding:.5rem .6rem;border-bottom:1px solid var(--line)}'
    . '.scroll{overflow-x:auto}.sec h2{font-size:1.15rem;margin:1.2rem 0 .2rem}'
    . 'th a,.admLink{display:inline-flex;align-items:center;min-height:44px;min-width:44px;padding:0 .25rem}.admMain input[type=date]{max-width:100%}'
    . '.admAuth{max-width:440px;margin:6vh auto;padding:0 16px}.admAuth .panel{padding:22px}.admAuth h1{margin-top:0}.admAuth label{display:block;font-weight:600;margin:.9rem 0 .25rem}.admAuth input{width:100%;min-height:44px;padding:.5rem .65rem;border:1px solid var(--line);border-radius:10px;background:var(--bg);color:var(--ink);font:inherit}.admAuth .admBtn{margin-top:1rem;background:var(--brand);color:var(--brandInk);border-color:transparent;font-weight:600;width:100%}.admErr,.admAuth .err{color:#b42318;font-weight:600}.admOk{border-left:6px solid var(--ok)}.panel.admErr{border-left:6px solid #b42318}'
    . '@media (prefers-color-scheme:dark){.admErr,.admAuth .err{color:#ff8a80}.panel.admErr{border-left-color:#ff8a80}}'
    . (function_exists('admt_css') ? admt_css() : '')
    . (function_exists('admf_css') ? admf_css() : '')
    . (function_exists('admc_css') ? admc_css() : '')
    . (function_exists('admu_css') ? admu_css() : '')
    . (function_exists('anz_css') ? anz_css() : '')
    . '@media (max-width:900px){.admLayout{grid-template-columns:minmax(0,1fr)}.admMain{padding:14px}.admSide{padding:8px}'
    . '.js .admMenuBtn{display:inline-flex;align-items:center;gap:6px}.js .admSide{position:fixed;inset:0 auto 0 0;width:min(88vw,320px);z-index:50;transform:translateX(-104%);visibility:hidden;transition:transform .2s ease,visibility 0s linear .2s;box-shadow:0 0 40px rgba(0,0,0,.5);padding-top:8px}'
    . '.js .admNavOpen .admSide{transform:none;visibility:visible;transition:transform .2s ease}.js .admSideHead{display:flex;justify-content:space-between;align-items:center;padding:4px 4px 8px;font-weight:700}.js .admScrim{display:block;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:45;opacity:0;pointer-events:none;transition:opacity .2s}.js .admNavOpen .admScrim{opacity:1;pointer-events:auto}.js .admNavOpen{overflow:hidden}}'
    . '@media (prefers-reduced-motion:reduce){*{transition:none!important;animation:none!important}}';
}

/** Full page shell: environment banner, skip link, top bar (menu, identity, sign-out), grouped collapsible sidebar, breadcrumbs, main content. */
function adm_page(array $user, string $current, string $title, string $body, int $status = 200): void {
  if (function_exists('sec_send_headers')) sec_send_headers();
  header('Cache-Control: no-store'); header('X-Robots-Tag: noindex, nofollow'); header('Content-Type: text/html; charset=UTF-8');
  http_response_code($status);
  $role = (string)($user['role'] ?? ''); $get = is_array($_GET ?? null) ? $_GET : [];
  $nav = adm_nav_html(adm_nav_tree($role, $current, $get)); $who = adm_h($user['display_name'] ?? $user['username'] ?? '');
  echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex, nofollow"><meta name="color-scheme" content="light dark">'
    . '<title>' . adm_h($title) . ' | AIHubpedia Control Center</title><style>' . adm_css() . '</style><script>document.documentElement.classList.add("js")</script><script src="/assets/js/admin-shell.js" defer></script></head><body>'
    . '<a class="skip" href="#main">Skip to content</a>' . adm_env_html()
    . '<header class="admTop"><div class="admBrandWrap"><button class="admBtn admMenuBtn" id="admMenuBtn" type="button" aria-controls="admNav" aria-expanded="false">Menu</button><a class="admBrand" href="/admin-center.php">AIHubpedia Control Center</a></div>'
    . '<div class="admWho"><a class="admId" href="/admin-center.php?m=account" title="Account and password"><span>' . $who . '</span><span class="role">' . adm_h($role) . '</span></a>'
    . '<form method="post" action="/admin-center.php">' . crm_csrf_field() . '<input type="hidden" name="act" value="logout"><button class="admBtn" type="submit">Sign out</button></form></div></header>'
    . '<div class="admScrim" id="admScrim" aria-hidden="true"></div>'
    . '<div class="admLayout"><nav class="admSide" id="admNav" aria-label="Control Center sections"><div class="admSideHead"><span>Menu</span><button class="admBtn" type="button" id="admMenuClose">Close</button></div>' . $nav . '</nav>'
    . '<main id="main" class="admMain" tabindex="-1">' . adm_crumbs_html(adm_breadcrumbs($current, $get, $role)) . $body . '</main></div></body></html>';
}

function adm_tiles_html(array $tiles): string {
  $o = '<ul class="admGrid">';
  foreach ($tiles as $t) {
    $o .= '<li class="tile"><h3>' . adm_h($t['label']) . '</h3>' . ($t['value'] !== null ? '<div class="val">' . adm_h($t['value']) . '</div>' : '<div class="nv">' . ($t['state'] === 'na' ? 'Not available yet' : 'No data yet') . '</div>')
      . ($t['note'] !== '' ? '<p>' . adm_h($t['note']) . '</p>' : '') . '</li>';
  }
  return $o . '</ul>';
}

function adm_dashboard_body(PDO $pdo, array $user): string {
  $role = (string)$user['role']; $o = '<h1>Overview</h1><p class="lead">Every figure comes from stored records; where there is nothing to show, the tile says so. Reporting currency: ' . ADM_REPORT_CURRENCY . '.</p>';
  if (adm_can($role, 'crm')) {
    $f = adm_tiles_finance($pdo);
    $o .= '<section class="sec" aria-labelledby="s-att"><h2 id="s-att">Needs attention</h2>' . adm_tiles_html(adm_tiles_attention($pdo)) . adm_attention_list($pdo) . '</section>';
    $o .= '<section class="sec" aria-labelledby="s-est"><h2 id="s-est">Estimated opportunities (not revenue)</h2>' . adm_tiles_html($f['estimate']) . '</section>';
    $o .= '<section class="sec" aria-labelledby="s-act"><h2 id="s-act">Actual results (recorded)</h2>' . adm_tiles_html($f['actual']) . adm_tiles_html(adm_tiles_crm($pdo)) . '</section>';
    if ($f['targets']) $o .= '<section class="sec" aria-labelledby="s-tgt"><h2 id="s-tgt">Targets</h2>' . adm_tiles_html($f['targets']) . '</section>';
  }
  if (adm_can($role, 'universe')) $o .= '<section class="sec" aria-labelledby="s-uni"><h2 id="s-uni">AI Universe</h2>' . adm_tiles_html(adm_tiles_universe($pdo)) . '</section>';
  if (adm_can($role, 'analytics') && function_exists('adma_overview_tiles')) $o .= '<section class="sec" aria-labelledby="s-traf"><h2 id="s-traf">Traffic (last 7 days)</h2>' . adm_tiles_html(adma_overview_tiles($pdo)) . '<p><a class="admLink" href="/admin-center.php?m=analytics">Open the full analytics report</a></p></section>';
  if (adm_can($role, 'analytics')) $o .= '<section class="sec" aria-labelledby="s-pend"><h2 id="s-pend">Coming in later phases</h2>' . adm_tiles_html(adm_tiles_pending()) . '</section>';
  if ($role === 'owner') {
    $o .= '<section class="sec" aria-labelledby="s-health"><h2 id="s-health">System health</h2><div class="panel scroll" role="region" tabindex="0" aria-label="System health checks"><table><caption class="sr" style="position:absolute;left:-999px">System health checks</caption><thead><tr><th scope="col">Check</th><th scope="col">Status</th></tr></thead><tbody>';
    foreach (adm_health($pdo) as [$k, $v]) $o .= '<tr><th scope="row">' . adm_h($k) . '</th><td>' . adm_h($v) . '</td></tr>';
    $o .= '</tbody></table></div></section>';
  }
  return $o;
}

function adm_planned_body(string $id, array $m): string {
  $o = '<h1>' . adm_h($m['label']) . '</h1><p class="lead">Planned for Phase ' . adm_h($m['phase']) . '. Nothing is collected or shown here yet.</p><div class="panel"><h2 style="margin-top:0;font-size:1.05rem">Scope</h2><ul>';
  foreach ($m['scope'] as $s) $o .= '<li>' . adm_h($s) . '</li>';
  return $o . '</ul><p class="lead" style="margin-bottom:0">This module is added only after your approval of Phase ' . adm_h($m['phase']) . '.</p></div>';
}
