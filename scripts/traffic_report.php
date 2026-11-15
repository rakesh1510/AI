<?php
/**
 * Traffic report from the first-party page_views table (enable with AIHUB_ANALYTICS=1).
 * Usage: php scripts/traffic_report.php [--days=30] [--json] [--prune-days=400]
 * "Humans" = requests whose user agent is not a known bot (heuristic). Visitor counts are distinct rotating hashes per day
 * (or per week with AIHUB_ANALYTICS_HASH_WINDOW=week); they cannot link visits across periods by design.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../helpers.php';
$days = 30; $json = in_array('--json', $argv, true); $prune = null;
foreach ($argv as $a) { if (strpos($a, '--days=') === 0) $days = max(1, (int)substr($a, 7)); if (strpos($a, '--prune-days=') === 0) $prune = max(30, (int)substr($a, 13)); }
if (!db_table_exists('page_views')) { fwrite(STDERR, "page_views table not found. Apply database/phase2_analytics.sql and set AIHUB_ANALYTICS=1.\n"); exit(2); }
$pdo = db();
if ($prune) { $n = $pdo->exec("DELETE FROM page_views WHERE day < DATE_SUB(CURDATE(), INTERVAL $prune DAY)"); echo "Pruned $n rows older than $prune days.\n"; }
$since = date('Y-m-d', strtotime("-$days days"));
$q = function (string $sql) use ($pdo, $since) { $s = $pdo->prepare($sql); $s->execute([$since]); return $s->fetchAll(PDO::FETCH_ASSOC); };

$r = [];
$r['period'] = ['from' => $since, 'days' => $days];
$r['daily_humans'] = $q("SELECT day, COUNT(DISTINCT NULLIF(visitor_hash,'')) AS visitors, COUNT(*) AS pageviews FROM page_views WHERE is_bot=0 AND status<400 AND day>=? GROUP BY day ORDER BY day");
$r['totals_humans'] = $q("SELECT COUNT(*) AS pageviews, COUNT(DISTINCT NULLIF(visitor_hash,'')) AS distinct_hashes, SUM(visitor_hash='') AS no_hash_views FROM page_views WHERE is_bot=0 AND status<400 AND day>=?")[0];
$r['referrers'] = $q("SELECT referrer_class, COUNT(*) AS views FROM page_views WHERE is_bot=0 AND status<400 AND referrer_class<>'internal' AND day>=? GROUP BY referrer_class ORDER BY views DESC");
$r['ai_referrals'] = $q("SELECT referrer_class, COUNT(*) AS views FROM page_views WHERE is_bot=0 AND referrer_class LIKE 'ai\\_%' AND day>=? GROUP BY referrer_class ORDER BY views DESC");
$r['top_pages'] = $q("SELECT path, page_type, COUNT(*) AS views FROM page_views WHERE is_bot=0 AND status<400 AND day>=? GROUP BY path, page_type ORDER BY views DESC LIMIT 25");
$r['countries'] = $q("SELECT country, COUNT(*) AS views FROM page_views WHERE is_bot=0 AND status<400 AND day>=? GROUP BY country ORDER BY views DESC LIMIT 20");
$r['organic_landing_pages'] = $q("SELECT path, COUNT(*) AS views FROM page_views WHERE is_bot=0 AND status<400 AND referrer_class LIKE 'search\\_%' AND day>=? GROUP BY path ORDER BY views DESC LIMIT 15");
$r['crawlers'] = $q("SELECT bot_type, bot_name, COUNT(*) AS hits, SUM(status>=400) AS errors FROM page_views WHERE is_bot=1 AND day>=? GROUP BY bot_type, bot_name ORDER BY hits DESC LIMIT 30");
$r['human_404s'] = $q("SELECT path, COUNT(*) AS hits FROM page_views WHERE is_bot=0 AND status=404 AND day>=? GROUP BY path ORDER BY hits DESC LIMIT 15");
if ($json) { echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n"; exit(0); }

$tbl = function (string $title, array $rows) { echo "\n$title\n" . str_repeat('-', strlen($title)) . "\n"; if (!$rows) { echo "  (no data)\n"; return; } foreach ($rows as $row) echo '  ' . implode('   ', array_map('strval', $row)) . "\n"; };
echo "Traffic report: last $days days (since $since)\n";
echo "Human page views: {$r['totals_humans']['pageviews']}   distinct visitor hashes: {$r['totals_humans']['distinct_hashes']}   views without hash (DNT/GPC): " . (int)$r['totals_humans']['no_hash_views'] . "\n";
$tbl('Daily human visitors / page views (day, visitors, views)', $r['daily_humans']);
$tbl('Referrer classes (humans)', $r['referrers']);
$tbl('AI-app referrals (humans)', $r['ai_referrals']);
$tbl('Top pages (humans)', $r['top_pages']);
$tbl('Organic-search landing pages', $r['organic_landing_pages']);
$tbl('Countries (country header, XX = unknown)', $r['countries']);
$tbl('Crawler activity (type, name, hits, errors)', $r['crawlers']);
$tbl('Broken URLs hit by humans', $r['human_404s']);
echo "\nNote: bot filtering is user-agent based and heuristic. Cross-check with Google Search Console and server logs.\n";
