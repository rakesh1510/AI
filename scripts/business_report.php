<?php
/**
 * Business measurement report: search engagement, profile visits, outbound/affiliate clicks, advertiser and consultation
 * inquiries, newsletter signups and returning-user engagement.
 * Usage: php scripts/business_report.php [--days=30] [--json]
 *
 * Reads only aggregate tables (events, page_views, leads, subscribers, tool_clicks). It prints raw counts, never projections.
 * Client-side events exist only for visitors who opted in through "Privacy choices", so they undercount by design;
 * server-side counts (leads, subscribers, redirect clicks, page views) are complete for the period they were enabled.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../helpers.php';
$days = 30; $json = in_array('--json', $argv, true);
foreach ($argv as $a) if (strpos($a, '--days=') === 0) $days = max(1, (int)substr($a, 7));
$since = date('Y-m-d', strtotime("-$days days"));
db_connection_available();   // loads db.php
$pdo = db();
$has = fn($t) => db_table_exists($t);
$q = function (string $sql, array $args = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($args); return $s->fetchAll(PDO::FETCH_ASSOC); };
$r = ['period' => ['from' => $since, 'days' => $days]];

if ($has('events')) {
  $r['search'] = [
    'submits_by_source_and_results' => $q("SELECT prop1 AS source, prop2 AS results, COUNT(*) AS n FROM events WHERE name='search_submit' AND day>=? GROUP BY prop1, prop2 ORDER BY n DESC", [$since]),
    'suggestion_clicks_by_kind' => $q("SELECT prop1 AS kind, COUNT(*) AS n FROM events WHERE name='suggest_click' AND day>=? GROUP BY prop1 ORDER BY n DESC", [$since]),
    'filters_used' => $q("SELECT prop1 AS kind, COUNT(*) AS n FROM events WHERE name='filter_use' AND day>=? GROUP BY prop1", [$since]),
  ];
  $r['outbound_clicks_optin'] = $q("SELECT prop1 AS affiliate, prop2 AS placement, COUNT(*) AS n FROM events WHERE name='outbound_click' AND day>=? AND visitor_hash<>'' GROUP BY prop1, prop2 ORDER BY n DESC", [$since]);
  $r['outbound_clicks_server'] = $q("SELECT prop1 AS affiliate, prop2 AS sponsored_or_organic, COUNT(*) AS n FROM events WHERE name='outbound_click' AND day>=? AND visitor_hash='' GROUP BY prop1, prop2 ORDER BY n DESC", [$since]);
  $r['top_outbound_tools'] = $q("SELECT ref AS tool, COUNT(*) AS n FROM events WHERE name='outbound_click' AND visitor_hash='' AND day>=? GROUP BY ref ORDER BY n DESC LIMIT 15", [$since]);
  $r['engagement_optin'] = $q("SELECT name, COUNT(*) AS n FROM events WHERE name IN ('compare_start','compare_open','favorite_add','theme_toggle') AND day>=? GROUP BY name ORDER BY n DESC", [$since]);
  $r['returning_optin'] = $q("SELECT prop1 AS visits_bucket, COUNT(*) AS n FROM events WHERE name='returning_visit' AND day>=? GROUP BY prop1 ORDER BY n DESC", [$since]);
  $r['newsletter_events'] = $q("SELECT name, COUNT(*) AS n FROM events WHERE name IN ('newsletter_signup','newsletter_confirmed') AND day>=? GROUP BY name", [$since]);
}
if ($has('page_views')) {
  $r['profile_visits_top'] = $q("SELECT path, COUNT(*) AS views FROM page_views WHERE page_type='tool' AND is_bot=0 AND status<400 AND day>=? GROUP BY path ORDER BY views DESC LIMIT 20", [$since]);
  $r['profile_visits_total'] = $q("SELECT COUNT(*) AS views FROM page_views WHERE page_type='tool' AND is_bot=0 AND status<400 AND day>=?", [$since])[0];
  $r['business_page_views'] = $q("SELECT path, COUNT(*) AS views FROM page_views WHERE path IN ('/business.php','/advertise.php','/disclosure.php') AND is_bot=0 AND status<400 AND day>=? GROUP BY path", [$since]);
}
if ($has('leads')) {
  $r['leads_by_type'] = $q("SELECT lead_type, COUNT(*) AS n, SUM(status='new') AS still_new FROM leads WHERE created_at>=? GROUP BY lead_type ORDER BY n DESC", [$since]);
}
if ($has('subscribers')) {
  $r['subscribers_by_status'] = $q("SELECT status, COUNT(*) AS n FROM subscribers WHERE created_at>=? GROUP BY status", [$since]);
}
if (db_table_exists('tool_clicks')) {
  $r['redirect_clicks_total'] = $q("SELECT COUNT(*) AS n FROM tool_clicks WHERE created_at>=?", [$since . ' 00:00:00'])[0];
}
if ($json) { echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n"; exit(0); }

$tbl = function (string $title, ?array $rows) { echo "\n$title\n" . str_repeat('-', strlen($title)) . "\n"; if (!$rows) { echo "  (no data)\n"; return; } foreach ($rows as $row) echo '  ' . implode('   ', array_map(fn($v) => (string)$v, $row)) . "\n"; };
echo "Business report: last $days days (since $since)\n";
echo "Counts only. Opt-in client events undercount real usage; no rates or projections are computed here.\n";
$tbl('Search submits (source, results bucket, count) [opt-in]', $r['search']['submits_by_source_and_results'] ?? null);
$tbl('Suggestion clicks by kind [opt-in]', $r['search']['suggestion_clicks_by_kind'] ?? null);
$tbl('Filters used [opt-in]', $r['search']['filters_used'] ?? null);
$tbl('Tool profile visits (top 20, humans)', $r['profile_visits_top'] ?? null);
if (isset($r['profile_visits_total'])) echo "  total profile views: {$r['profile_visits_total']['views']}\n";
$tbl('Outbound clicks via redirect (affiliate?, sponsored/organic, count) [server]', $r['outbound_clicks_server'] ?? null);
$tbl('Top outbound tools [server]', $r['top_outbound_tools'] ?? null);
$tbl('Outbound clicks by placement [opt-in]', $r['outbound_clicks_optin'] ?? null);
$tbl('Advertiser, partner and business inquiries by type (leads table)', $r['leads_by_type'] ?? null);
$tbl('Business page views (humans)', $r['business_page_views'] ?? null);
$tbl('Newsletter: signup requests vs confirmations (events)', $r['newsletter_events'] ?? null);
$tbl('Newsletter subscribers by status', $r['subscribers_by_status'] ?? null);
$tbl('Engagement actions [opt-in]', $r['engagement_optin'] ?? null);
$tbl('Returning-user visits by bucket [opt-in]', $r['returning_optin'] ?? null);
echo "\n";
