<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
/**
 * Executive dashboard metrics (Phase 6). Every figure is computed from stored records; nothing is estimated except the clearly labelled
 * forecast. A rate whose denominator is zero is null (shown as "not enough data"), never 0% or an invented number.
 */
require_once __DIR__ . '/crm_growth.php';

function crm_rate(int $num, int $den): ?float { return $den > 0 ? round($num / $den * 100, 1) : null; }
function crm_dashboard(PDO $pdo, int $days = 90, ?int $now = null): array {
  $now = $now ?? time(); $since = crm_now($now - $days * 86400); $q = function (string $sql, array $a = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($a); return $s; };
  $d = ['days' => $days, 'since' => $since];
  $d['inquiries'] = (int)$q('SELECT COUNT(*) FROM crm_leads WHERE created_at >= ?', [$since])->fetchColumn();
  $d['inquiries_by_kind'] = array_fill_keys(array_keys(crm_lead_kinds()), 0); foreach ($q('SELECT kind, COUNT(*) n FROM crm_leads WHERE created_at >= ? GROUP BY kind', [$since])->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $n) $d['inquiries_by_kind'][$k] = (int)$n;
  $by = $q('SELECT status, COUNT(*) n FROM crm_leads WHERE created_at >= ? GROUP BY status', [$since])->fetchAll(PDO::FETCH_KEY_PAIR);
  $d['qualified_leads'] = (int)($by['qualified'] ?? 0) + (int)($by['converted'] ?? 0); $d['disqualified_leads'] = (int)($by['disqualified'] ?? 0); $d['converted_leads'] = (int)($by['converted'] ?? 0);
  $d['qualification_rate'] = crm_rate($d['qualified_leads'], $d['inquiries']); $d['conversion_rate'] = crm_rate($d['converted_leads'], $d['inquiries']);
  $d['possible_duplicates'] = (int)$q('SELECT COUNT(*) FROM crm_leads WHERE created_at >= ? AND duplicate_of IS NOT NULL', [$since])->fetchColumn();
  $d['unassigned_open_leads'] = (int)$pdo->query("SELECT COUNT(*) FROM crm_leads WHERE assigned_to IS NULL AND status IN ('new','qualified')")->fetchColumn();
  $d['open_opportunities'] = (int)$pdo->query("SELECT COUNT(*) FROM crm_opportunities WHERE stage NOT IN ('won','lost')")->fetchColumn();
  $d['pipeline_by_stage'] = array_fill_keys(crm_open_stages(), ['count' => 0, 'value' => []]);
  foreach ($pdo->query("SELECT stage, currency, COUNT(*) n, SUM(amount_minor) s FROM crm_opportunities WHERE stage NOT IN ('won','lost') GROUP BY stage, currency") as $r) { $d['pipeline_by_stage'][$r['stage']]['count'] += (int)$r['n']; $d['pipeline_by_stage'][$r['stage']]['value'][$r['currency']] = (int)$r['s']; }
  $d['pipeline_value'] = []; foreach ($d['pipeline_by_stage'] as $st) foreach ($st['value'] as $c => $v) $d['pipeline_value'][$c] = ($d['pipeline_value'][$c] ?? 0) + $v;
  $w = $q("SELECT outcome, COUNT(*) n FROM crm_opportunities WHERE closed_at >= ? GROUP BY outcome", [$since])->fetchAll(PDO::FETCH_KEY_PAIR);
  $d['won'] = (int)($w['won'] ?? 0); $d['lost'] = (int)($w['lost'] ?? 0); $d['win_rate'] = crm_rate($d['won'], $d['won'] + $d['lost']);
  $d['won_value'] = []; foreach ($q("SELECT currency, SUM(amount_minor) s FROM crm_opportunities WHERE outcome = 'won' AND closed_at >= ? GROUP BY currency", [$since]) as $r) $d['won_value'][$r['currency']] = (int)$r['s'];
  $p = $pdo->query("SELECT status, COUNT(*) n FROM crm_proposals GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
  $decided = (int)($p['accepted'] ?? 0) + (int)($p['declined'] ?? 0) + (int)($p['expired'] ?? 0); $d['proposals'] = ['by_status' => $p, 'acceptance_rate' => crm_rate((int)($p['accepted'] ?? 0), $decided), 'decided' => $decided];
  $d['revenue'] = crm_revenue_summary($pdo, gmdate('Y-m-d', $now));
  $d['mrr'] = array_map(fn($r) => $r['mrr'], $d['revenue']); $d['arr'] = array_map(fn($r) => $r['arr'], $d['revenue']);
  $d['sources'] = $q("SELECT CASE WHEN attr_source = '' THEN '(none)' ELSE attr_source END AS src, COUNT(*) n FROM crm_leads WHERE created_at >= ? GROUP BY src ORDER BY n DESC LIMIT 15", [$since])->fetchAll(PDO::FETCH_KEY_PAIR);
  $d['campaign_attribution'] = $q("SELECT attr_campaign AS code, COUNT(*) n FROM crm_leads WHERE created_at >= ? AND attr_campaign <> '' GROUP BY attr_campaign ORDER BY n DESC LIMIT 15", [$since])->fetchAll(PDO::FETCH_KEY_PAIR);
  $d['campaigns'] = $pdo->query('SELECT status, COUNT(*) n FROM crm_campaigns GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
  $d['partners'] = $pdo->query('SELECT status, COUNT(*) n FROM crm_partners GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
  $d['tasks_open'] = (int)$pdo->query('SELECT COUNT(*) FROM crm_tasks WHERE done_at IS NULL')->fetchColumn();
  $d['tasks_overdue'] = (int)$q('SELECT COUNT(*) FROM crm_tasks WHERE done_at IS NULL AND due_at < ?', [crm_now($now)])->fetchColumn();
  $d['renewals_60d'] = count(crm_contracts_renewing($pdo, 60, $now));
  $d['synthetic_rows'] = (int)$pdo->query('SELECT (SELECT COUNT(*) FROM crm_leads WHERE is_synthetic = 1) + (SELECT COUNT(*) FROM crm_opportunities WHERE is_synthetic = 1) + (SELECT COUNT(*) FROM crm_contracts WHERE is_synthetic = 1)')->fetchColumn();
  $d['has_data'] = $d['inquiries'] > 0 || $d['open_opportunities'] > 0 || $d['won'] + $d['lost'] > 0 || $d['revenue'] !== [];
  return $d;
}
