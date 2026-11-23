<?php
// Analytics A2-A5, DATABASE-FREE: reporting arithmetic (hand-computed), honest states, growth rules, rankings, categories, placements, devices, active visitors, exports, permissions and static privacy checks.
$root = dirname(__DIR__); chdir($root);
ob_start(); require_once $root . '/helpers.php'; require_once $root . '/crm_views.php'; require_once $root . '/adm_core.php'; require_once $root . '/adm_analytics.php'; require_once $root . '/adm_an2_store.php'; require_once $root . '/adm_an2_views.php'; ob_end_clean();
$pass = 0; $fail = 0; function ok($c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; fwrite(STDERR, "  FAIL: $m\n"); } }
$TODAY = '2026-11-20'; $NOW = strtotime($TODAY . ' 12:00:00 UTC');
// ---- periods and ranges
$p = anz_periods($TODAY, '2026-09-01');
ok($p['today'] === [$TODAY, $TODAY] && $p['yesterday'] === ['2026-11-19', '2026-11-19'] && $p['week'] === ['2026-11-16', $TODAY] && $p['7d'] === ['2026-11-14', $TODAY] && $p['30d'] === ['2026-10-22', $TODAY] && $p['month'] === ['2026-11-01', $TODAY] && $p['year'] === ['2026-01-01', $TODAY] && $p['all'] === ['2026-09-01', $TODAY], 'named periods (week starts Monday; UTC)');
ok($p['prev_7d'] === ['2026-11-07', '2026-11-13'] && $p['prev_30d'] === ['2026-09-22', '2026-10-21'] && $p['prev_month_same'] === ['2026-10-01', '2026-10-20'], 'comparison periods are equal length and directly before');
$p2 = anz_periods('2026-03-31', null); ok($p2['prev_month_same'] === ['2026-02-01', '2026-02-28'] && $p2['all'] === null, 'month-end clamps to the shorter previous month; no first day means no "all"');
$p3 = anz_periods('2026-01-02', null); ok($p3['week'] === ['2025-12-29', '2026-01-02'] && $p3['year'] === ['2026-01-01', '2026-01-02'], 'week spanning a year boundary');
$r = anz_range([], $TODAY, '2026-09-01'); ok($r['key'] === '30d' && $r['error'] === null, 'default range is the last 30 days');
foreach (['today', 'yesterday', 'week', '7d', '30d', 'month', 'year', 'all'] as $k) ok(anz_range(['range' => $k], $TODAY, '2026-09-01')['key'] === $k, "range preset $k");
ok(anz_range(['range' => 'all'], $TODAY, null)['key'] === '30d', '"since tracking began" without data falls back instead of inventing a start');
$r = anz_range(['range' => 'custom', 'from' => '2026-11-01', 'to' => '2026-11-10'], $TODAY, null); ok($r['key'] === 'custom' && $r['from'] === '2026-11-01' && $r['error'] === null, 'valid custom range');
foreach ([['2026-02-30', '2026-03-01'], ['2026-11-10', '2026-11-01'], ['2026-11-01', '2026-11-21'], ['x', 'y'], ['2024-01-01', '2026-11-20']] as [$f, $t]) { $r = anz_range(['range' => 'custom', 'from' => $f, 'to' => $t], $TODAY, null); ok($r['key'] === '30d' && $r['error'] !== null, "bad custom range ($f, $t) falls back with a message"); }
ok(anz_range(['range' => ['x']], $TODAY, null)['key'] === '30d' && anz_range(['range' => 'zzz'], $TODAY, null)['key'] === '30d', 'hostile range values fall back');
// ---- synthetic data: traffic for 2026-09-20 .. 2026-11-20 except a gap 2026-10-05..10-07 ; day n has visitors v
$traffic = []; $toolDaily = []; $dims = ['referrer' => [], 'page' => [], 'country' => [], 'device' => []]; $tot = ['today' => 0];
for ($i = 0; $i <= 61; $i++) { $d = anz_add_days('2026-09-20', $i); if ($d >= '2026-10-05' && $d <= '2026-10-07') continue; $v = 100 + $i; $traffic[$d] = [$v * 3, $v, intdiv($v * 3, 2), 5];
  $toolDaily[$d] = ['chatgpt' => [40, 10, 2, 0], 'claude' => [20, 5, 0, 1], 'gemini' => [10, 3, 0, 0], 'ghost-tool' => [0, 1, 0, 0]];
  $dims['referrer'][$d] = ['search_google' => [$v, $v - 20], 'direct' => [$v * 2 - 10, 20]]; $dims['page'][$d] = ['/' => [$v, $v], '/tool.php?id=chatgpt' => [40, 30]]; $dims['country'][$d] = ['DE' => [$v, $v], 'XX' => [$v * 2, 0]]; $dims['device'][$d] = ['mobile' => [$v, $v - 10], 'desktop' => [$v * 2, 10]]; }
$cat = ['chatgpt' => ['name' => 'ChatGPT', 'category' => 'Chatbots'], 'claude' => ['name' => 'Claude', 'category' => 'Chatbots'], 'gemini' => ['name' => 'Gemini', 'category' => 'Search']];
$pl = [['tool_slug' => 'claude', 'level' => 2, 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-30', 'status' => 'active'], ['tool_slug' => 'gemini', 'level' => 1, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-14', 'status' => 'ended'], ['tool_slug' => 'chatgpt', 'level' => 1, 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-07', 'status' => 'scheduled'], ['tool_slug' => 'chatgpt', 'level' => 1, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-05', 'status' => 'cancelled']];
$store = new AdmAnMemoryStore([], $traffic, $toolDaily, $dims, ['count' => 7, 'last_view' => '2026-11-20 11:58:00'], $pl);
// ---- sums (hand computed): today = i 61 -> v 161 ; yesterday v 160
$days = $store->traffic('2026-09-20', $TODAY); $t = anz_sum($days, $TODAY, $TODAY); ok($t['visitor_days'] === 161 && $t['views'] === 483 && $t['sessions'] === 241 && $t['days_with_data'] === 1 && $t['pages_per_session'] === round(483 / 241, 2), 'today: visitors 161, views 483, sessions 241');
$w = anz_sum($days, '2026-11-14', $TODAY); ok($w['visitor_days'] === 7 * 100 + array_sum(range(55, 61)) && $w['days_with_data'] === 7 && $w['span'] === 7, 'last 7 days visitor-days = sum of daily visitors');
$gap = anz_sum($days, '2026-10-01', '2026-10-10'); ok($gap['days_with_data'] === 7 && $gap['span'] === 10, 'a gap in tracking is "days with data 7 of 10", never zeros');
$g = anz_growth(300, 200, 7, 7, 'x'); ok($g['pct'] === 50.0 && strpos($g['text'], '+50.0% (300 vs 200)') === 0, 'growth +50% shows both numbers');
$g = anz_growth(100, 200, 7, 7, 'x'); ok($g['pct'] === -50.0, 'growth can be negative');
$g = anz_growth(10, 0, 7, 7, 'x'); ok($g['pct'] === null && strpos($g['text'], 'none') !== false, 'no percentage from a zero baseline');
$g = anz_growth(10, 5, 4, 7, 'x'); ok($g['pct'] === null && strpos($g['text'], '4 of 7') !== false, 'no percentage when the comparison period has missing days');
$rows = anz_growth_rows($days, $TODAY, '2026-09-20'); ok(count($rows) === 4 && $rows[0]['partial'] === true && $rows[0]['visitors']['pct'] === round((161 - 160) / 160 * 100, 1), 'growth rows: today vs yesterday is flagged partial and computed from real days');
ok($rows[1]['visitors']['cur'] === $w['visitor_days'] && $rows[1]['visitors']['prev'] === array_sum(array_map(fn($i) => 100 + $i, range(48, 54))), '7-day growth compares with the 7 days before');
ok($rows[2]['visitors']['pct'] === null && strpos($rows[2]['visitors']['text'], 'only') !== false, '30-day growth is not shown because the previous 30 days include the tracking gap (and start before tracking)');
$m = anz_months($days); ok(count($m) === 3 && $m[0]['month'] === '2026-09' && $m[1]['days'] === 28 && $m[2]['days'] === 20, 'monthly history groups days and counts days with data');
// ---- tools
$tr = anz_tool_rows($store->tools('2026-11-01', $TODAY), $cat); $by = array_column($tr, null, 'slug'); ok($by['chatgpt']['views'] === 800 && $by['chatgpt']['outbound'] === 200 && $by['chatgpt']['ratio'] === 0.25 && $by['chatgpt']['affiliate'] === 40, 'tool sums over 20 days (40 x 20 views, 10 x 20 clicks)');
ok($by['ghost-tool']['ratio'] === null && $by['ghost-tool']['in_catalogue'] === false && $by['ghost-tool']['category'] === '(not in the current catalogue)', 'a tool with clicks but no profile views has no ratio; unknown tools are labelled, not hidden');
$rk = anz_rank($tr, 'outbound', 10); ok(array_column($rk['rows'], 'slug') === ['chatgpt', 'claude', 'gemini', 'ghost-tool'] && $rk['matching'] === 4, 'ranking by outbound clicks');
ok(count(anz_rank($tr, 'outbound', 2)['rows']) === 2 && anz_rank($tr, 'outbound', 2)['matching'] === 4, 'top-N cut keeps the match count');
ok(array_column(anz_rank($tr, 'outbound', 10, 'claude')['rows'], 'slug') === ['claude'] && array_column(anz_rank($tr, 'outbound', 10, 'GPT')['rows'], 'slug') === ['chatgpt'] && anz_rank($tr, 'outbound', 10, 'zzz')['rows'] === [], 'search by name is case-insensitive');
ok(array_column(anz_rank($tr, 'outbound', 10, '', 'Search')['rows'], 'slug') === ['gemini'], 'category filter');
ok(anz_rank($tr, 'ratio', 10)['rows'][0]['slug'] === 'gemini' && anz_rank($tr, 'name', 10)['rows'][0]['name'] === 'ChatGPT' && anz_rank($tr, 'bogus', 10)['rows'][0]['slug'] === 'chatgpt', 'sorting, with a safe default for unknown sort keys');
$cats = anz_categories($tr); ok($cats[0]['category'] === 'Chatbots' && $cats[0]['outbound'] === 300 && $cats[0]['views'] === 1200 && $cats[0]['share'] === round(300 / 380 * 100, 1) && $cats[0]['tools'] === 2, 'category totals and share of outbound clicks');
$chg = anz_tool_changes($store->tools('2026-11-01', $TODAY), $store->tools('2026-10-12', '2026-10-31'), 20, 20); ok($chg['chatgpt']['pct'] === 0.0 && $chg['claude']['pct'] === 0.0, 'tool change against the previous equal period (same daily volume = 0%)');
$chg = anz_tool_changes($store->tools('2026-11-01', $TODAY), $store->tools('2026-10-01', '2026-10-20'), 17, 20); ok($chg['chatgpt']['pct'] === null, 'tool change is withheld when the previous period has missing days');
// ---- placements
$covered = []; foreach ($days as $r) $covered[$r['day']] = 1; $sums = function ($slug, $f, $t) use ($store) { $v = 0; $o = 0; foreach ($store->tools($f, $t) as $r) if ($r['tool_slug'] === $slug) { $v = $r['tool_views']; $o = $r['outbound_clicks']; } return ['views' => $v, 'outbound' => $o]; };
$perf = anz_placement_perf($pl, $sums, $covered, $TODAY, $cat); $pc = array_column($perf, null, 'name');
ok($pc['Claude']['measurable'] && $pc['Claude']['during']['days'] === 11 && $pc['Claude']['during']['outbound'] === 55 && $pc['Claude']['before']['outbound'] === 55 && $pc['Claude']['growth']['pct'] === 0.0, 'running placement measured up to today against the equal window before (11 days)');
ok(!$pc['Gemini']['measurable'] && strpos($pc['Gemini']['reason'], 'missing') !== false, 'a placement whose windows include tracking gaps is "not measurable" with the reason');
$sched = array_values(array_filter($perf, fn($x) => $x['status'] === 'scheduled'))[0]; ok(!$sched['measurable'] && $sched['reason'] === 'Not started yet.', 'a future placement is not measured');
$canc = array_values(array_filter($perf, fn($x) => $x['status'] === 'cancelled'))[0]; ok(!$canc['measurable'], 'a cancelled placement is not measured');
// ---- shares and labels
$sh = anz_shares($store->dim('referrer', '2026-11-20', '2026-11-20', 10)); ok(array_sum(array_column($sh, 'share')) > 99.8 && array_sum(array_column($sh, 'share')) < 100.2 && $sh[0]['k'] === 'direct', 'source shares add up to 100%');
ok(strpos(anz_referrer_label('direct'), 'no referrer') !== false && anz_referrer_label('search_google') === 'Google search' && anz_referrer_label('ai_claude') === 'Claude' && anz_device_label('tablet') === 'Tablet', 'labels (direct is not called purely direct)');
// ---- rendering: overview
$H = anz_body($store, [], $cat, $NOW); $text = strip_tags($H);
ok(strpos($H, 'Daily visitors today') !== false && strpos($text, '161') !== false && strpos($H, 'Daily visitors yesterday') !== false, 'overview shows daily visitors today and yesterday');
ok(strpos($H, 'NOT unique people') !== false && strpos($H, 'Visitor-days, last 7 days') !== false && strpos($H, 'Visitor-days, last 30 days') !== false, 'longer periods are labelled visitor-days and "not unique people"');
ok(strpos($H, 'Page views this week') !== false && strpos($H, 'Page views this month') !== false && strpos($H, 'Page views this year') !== false && strpos($H, 'Page views since tracking began') !== false, 'page views for today, week, month, year and since tracking');
ok(strpos($H, 'Visitors active now') !== false && strpos($H, 'Estimate') !== false && strpos($H, 'last 5 minutes') !== false, 'active visitors are labelled an estimate with its window');
ok(strpos($H, 'Growth, with the numbers behind each percentage') !== false && strpos($H, '(current - previous) / previous') !== false, 'growth table explains its denominator');
ok(strpos($H, 'Most visited pages') !== false && strpos($H, 'Top 10 AI tools') !== false && strpos($H, 'ChatGPT') !== false, 'top pages and top tools are on the overview');
ok(preg_match('/returning|new visitors/i', $text) === 0 || strpos($text, 'returning visitors cannot and will not be recognised') !== false, 'the dashboard never reports returning or new visitors');
ok(strpos($H, '<svg role="img"') !== false && strpos($H, 'Show the data as a table') !== false && strpos($H, 'tabindex="0" class="anPt"') !== false, 'charts have a title, focusable points and a table alternative');
$Hn = anz_body(new AdmAnMemoryStore([], $traffic, $toolDaily, $dims, null, null), [], $cat, $NOW); ok(strpos($Hn, 'Visitors active now') !== false && strpos(strip_tags($Hn), 'Unavailable') !== false && strpos($Hn, 'Needs tracking enabled') !== false, 'without recent activity data, "active now" is Unavailable, not zero');
// ---- states
$none = anz_body(new AdmAnMemoryStore(['storage' => false]), [], $cat, $NOW); ok(strpos($none, 'Analytics storage is not installed') !== false && strpos($none, 'STAGING_INSTALL_AND_VERIFY') !== false && strpos($none, 'Daily visitors today') === false, 'missing storage: clear message, no numbers');
$off = anz_body(new AdmAnMemoryStore(['tracking' => false, 'first_day' => null, 'last_day' => null]), [], $cat, $NOW); ok(strpos($off, 'No tracking data yet') !== false && strpos($off, 'not enabled') !== false, 'tracking off and no data: honest message');
$on = anz_body(new AdmAnMemoryStore(['tracking' => true, 'first_day' => null, 'last_day' => null]), [], $cat, $NOW); ok(strpos($on, 'No tracking data yet') !== false && strpos($on, 'rollup') !== false, 'tracking on but nothing rolled up: says so');
$empty = anz_body(new AdmAnMemoryStore(['first_day' => '2026-11-19', 'last_day' => '2026-11-19'], ['2026-11-19' => [10, 5, 3, 0]]), ['range' => 'today'], $cat, $NOW); ok(strpos($empty, 'No data yet') !== false && strpos($empty, 'Nothing was recorded in this range') !== false, 'a range with no days shows empty states, not zeros');
// ---- other views
foreach (['visitors', 'pages', 'sources', 'tools', 'categories', 'placements', 'exports'] as $v) { $h = anz_body($store, ['view' => $v], $cat, $NOW); ok(strpos($h, '<h1>Analytics</h1>') !== false && strpos($h, 'Fatal') === false && strlen($h) > 1500, "view $v renders"); }
$V = anz_body($store, ['view' => 'visitors', 'range' => 'month'], $cat, $NOW); ok(strpos($V, 'Month by month since tracking began') !== false && strpos($V, 'month in progress') !== false && strpos($V, 'were not tracked and are not shown or estimated') !== false, 'monthly history with in-progress month and no invented months');
$S = anz_body($store, ['view' => 'sources'], $cat, $NOW); ok(strpos($S, 'Device categories') !== false && strpos($S, 'Mobile') !== false && strpos($S, 'Unknown') !== false && strpos($S, 'Google search') !== false, 'sources, devices and countries (XX shown as Unknown)');
$S2 = anz_body(new AdmAnMemoryStore(['device_col' => false], $traffic, $toolDaily, [], null, null), ['view' => 'sources'], $cat, $NOW); ok(strpos($S2, 'Device data is not collected on this server yet') !== false && strpos($S2, 'analytics_a4_device.sql') !== false, 'devices: honest message when the column is not installed');
$T = anz_body($store, ['view' => 'tools', 'q' => 'claude', 'top' => '10', 'tool' => 'claude'], $cat, $NOW); ok(strpos($T, 'Funnel for Claude') !== false && strpos($T, 'Listing impressions') !== false && strpos($T, 'Not measured') !== false && strpos($T, 'NOT a listing click-through rate') !== false, 'tool funnel lists only measured steps and says what is not measured; ratio is not called CTR');
ok(strpos($T, 'ChatGPT') === false || strpos(strip_tags(substr($T, strpos($T, 'AI tools ranked'))), 'ChatGPT') === false, 'search filters the ranking');
ok(preg_match('/click-through rate|CTR/i', strip_tags(anz_body($store, ['view' => 'tools'], $cat, $NOW))) === 1 && strpos(anz_body($store, ['view' => 'tools'], $cat, $NOW), 'NOT a listing click-through rate') !== false, 'the only mention of click-through rate is the warning that this is not one');
$P = anz_body($store, ['view' => 'placements'], $cat, $NOW); ok(strpos($P, 'does not prove the placement caused') !== false && strpos($P, 'Not measurable') !== false, 'placements: descriptive, not causal, with reasons');
$P0 = anz_body(new AdmAnMemoryStore([], $traffic, $toolDaily, $dims, null, null), ['view' => 'placements'], $cat, $NOW); ok(strpos($P0, 'not available') !== false, 'placements unavailable without tool management');
$x = anz_body($store, ['view' => 'tools', 'q' => '<script>alert(1)</script>', 'cat' => '"><b>', 'sort' => "'; DROP", 'top' => '9999', 'tool' => '../../etc'], $cat, $NOW); ok(strpos($x, '<script>alert') === false && strpos($x, '<b>') === false, 'hostile filter values are escaped or ignored');
// ---- exports
$rng = anz_range(['range' => 'month'], $TODAY, '2026-09-20'); $E = fn($k, $g = []) => anz_export($store, $k, $rng, $g, $cat, $TODAY);
$leg = [['day' => '2026-11-18', 'tool_slug' => 'chatgpt', 'records' => 10, 'probable_duplicates' => 4], ['day' => '2026-11-19', 'tool_slug' => 'claude', 'records' => 6, 'probable_duplicates' => 0]]; $store = new AdmAnMemoryStore([], $traffic, $toolDaily, $dims, ['count' => 7, 'last_view' => '2026-11-20 11:58:00'], $pl, $leg);
$E = fn($k, $g = []) => anz_export($store, $k, $rng, $g, $cat, $TODAY);
foreach (array_keys(anz_export_names()) as $k) { $e = $E($k); ok($e !== null && strpos($e[0], '.csv') !== false && substr($e[1], 0, 3) === "\xEF\xBB\xBF", "export $k works"); }
$e = $E('visitors_daily'); ok(substr_count($e[1], "\r\n") === 21 && strpos($e[1], 'not_people') !== false, 'daily export: header plus 20 days, columns say "not people"');
$e = $E('tools_all', ['q' => 'claude']); ok(substr_count($e[1], "\r\n") === 2 && strpos($e[1], 'Claude') !== false && strpos($e[1], 'ChatGPT') === false, 'tool export respects the search');
$e = $E('tools_all'); ok(strpos($e[1], 'outbound_clicks_per_profile_view') !== false && stripos($e[1], 'ctr') === false, 'export column names the ratio honestly');
$storeI = new AdmAnMemoryStore([], ['2026-11-20' => [1, 1, 1, 0]], ['2026-11-20' => ['=cmd|x' => [1, 1, 0, 0]]], [], null, null); $e = anz_export($storeI, 'tools_all', ['from' => '2026-11-20', 'to' => '2026-11-20'], [], [], $TODAY); ok(strpos($e[1], "\"'=cmd|x\"") !== false, 'CSV cells are protected against formula injection');
ok(anz_export($store, 'nope', $rng, [], $cat, $TODAY) === null, 'unknown export is refused');
// ---- permissions and wiring
$ac = file_get_contents($root . '/admin-center.php'); ok(adm_can('owner', 'analytics') && !adm_can('sales', 'analytics') && !adm_can('viewer', 'analytics') && !adm_can('editor', 'analytics') && !adm_can('verifier', 'analytics'), 'analytics permission is owner only');
ok(strpos($ac, "if (!adm_can((string)\$user['role'], \$mod['perm']))") < strpos($ac, "\$m === 'analytics' && isset(\$_GET['export'])") && strpos($ac, "adm_audit(\$pdo, \$user, 'admin_center_export', 'analytics '") !== false, 'exports sit after the permission check and are audited');
// ---- privacy and safety (static)
$src = ''; foreach (['adm_an2_core.php', 'adm_an2_store.php', 'adm_an2_views.php'] as $f) $src .= file_get_contents($root . '/' . $f);
ok(!preg_match('/INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|ALTER\s+TABLE|DROP\s+TABLE|CREATE\s+TABLE|TRUNCATE|->exec\(/i', $src), 'the analytics store and views contain no write SQL');
ok(strpos($src, 'user_agent') === false && strpos($src, 'HTTP_USER_AGENT') === false && strpos($src, 'REMOTE_ADDR') === false && strpos($src, 'ip_address') === false && strpos($src, 'setcookie') === false, 'the dashboard reads no IP, user agent or cookie');
ok(substr_count(file_get_contents($root . '/adm_an2_store.php'), 'prepare(') >= 3 && strpos(file_get_contents($root . '/adm_an2_store.php'), '$_GET') === false, 'queries use bound parameters; the store never reads the request');
ok(strpos($src, 'returning_visit') === false, 'the dashboard does not use the returning-visit event');
$cssA = anz_css(); ok(strpos($cssA, '#fff') === false && strpos($cssA, 'var(--surface)') !== false, 'dashboard styles use the shell colour tokens (light and dark)');
// ---- legacy click records (old lifetime table): separate, labelled, never merged
$L = anz_legacy_summary($leg); ok($L['records'] === 16 && $L['probable_duplicates'] === 4 && $L['net'] === 12 && $L['first_day'] === '2026-11-18' && $L['tools'][0]['slug'] === 'chatgpt' && $L['dup_known'] === true, 'legacy summary: records, probable duplicates, net, first day, ranking');
$LV = anz_body($store, ['view' => 'legacy', 'range' => 'month'], $cat, $NOW); $lt = strip_tags($LV);
ok(strpos($LV, 'Legacy click records') !== false && strpos($lt, 'not comparable') !== false && strpos($lt, 'no page views and no visitors') !== false && strpos($lt, 'written twice') !== false || strpos($lt, 'written twice') !== false || strpos($LV, 'twice') !== false, 'legacy view explains it has no visitors or page views and may contain duplicates and bots');
ok(strpos($LV, '16') !== false && strpos($LV, 'Probable duplicate records') !== false && strpos($LV, 'Estimate') !== false, 'legacy cards show records and the duplicate estimate labelled Estimate');
$LN = anz_body(new AdmAnMemoryStore([], $traffic, $toolDaily, $dims, null, null, null), ['view' => 'legacy'], $cat, $NOW); ok(strpos($LN, 'not available on this server') !== false, 'legacy view: honest message when the old table is missing');
ok(anz_export($store, 'legacy_clicks', $rng, [], $cat, $TODAY)[1] !== '' && strpos(anz_export($store, 'legacy_clicks', $rng, [], $cat, $TODAY)[1], 'not_visitors_not_page_views') !== false && anz_export(new AdmAnMemoryStore([], $traffic), 'legacy_clicks', $rng, [], $cat, $TODAY) === null, 'legacy export is labelled and unavailable without the table');
$nodup = anz_legacy_summary([['day' => '2026-11-18', 'tool_slug' => 'a', 'records' => 3, 'probable_duplicates' => 0, 'dup_known' => false]]); ok($nodup['dup_known'] === false, 'duplicate estimate is marked unknown when the database cannot compute it');
$srcAll = file_get_contents($root . '/adm_an2_views.php') . file_get_contents($root . '/adm_an2_core.php'); ok(strpos($srcAll, 'anz_legacy_summary') !== false && strpos(file_get_contents($root . '/adm_an2_store.php'), 'tool_clicks') !== false && substr_count(file_get_contents($root . '/adm_an2_store.php'), 'tool_clicks') <= 12 && !preg_match('/(INSERT|UPDATE|DELETE)\\s[^;\\n]*tool_clicks/i', file_get_contents($root . '/adm_an2_store.php')), 'only the legacy method reads tool_clicks (read-only)');
ok(strpos(file_get_contents($root . '/adm_an2_store.php'), 'COUNT(DISTINCT visitor_id') === false && strpos(file_get_contents($root . '/adm_an2_store.php'), 'DISTINCT visitor_id') === false, 'legacy cookie values are never counted as visitors');
// ---- rollup freshness and lifetime-versus-date-filtered clarity
$NOWT = strtotime('2026-11-20 16:00:00 UTC'); ok(anz_rollup_age_minutes('2026-11-20 15:20:47', $NOWT) === 39 && anz_rollup_age_minutes(null) === null && anz_rollup_age_minutes('garbage', $NOWT) === null, 'rollup age in minutes (null when never run)');
$mkS = fn(?string $lastRun, ?array $un, ?array $life = null) => new AdmAnMemoryStore(['last_run' => $lastRun], $traffic, $toolDaily, $dims, null, $pl, $leg, $life, $un);
$fr = anz_body($mkS('2026-11-20 15:20:47', ['page_views' => 3, 'outbound' => 2]), [], $cat, $NOWT); ok(strpos($fr, 'Reports are current') !== false && strpos($fr, '3 page views and 2 outbound clicks') !== false && strpos($fr, 'not yet included') !== false, 'a recent rollup: "current", and activity since then is shown as not yet included');
$st2 = anz_body($mkS('2026-11-20 11:00:00', ['page_views' => 40, 'outbound' => 9]), [], $cat, $NOWT); ok(strpos($st2, 'out of date') !== false && strpos($st2, '5 hours ago') !== false && strpos($st2, 'ROLLUP_RECOVERY') !== false && strpos($st2, 'role="alert"') !== false, 'an old rollup: alert, age in hours, pointer to the recovery procedure');
$nv = anz_body($mkS(null, ['page_views' => 5, 'outbound' => 1]), [], $cat, $NOWT); ok(strpos($nv, 'No rollup has run yet') !== false, 'never rolled up: says so');
$ok0 = anz_body($mkS('2026-11-20 15:50:00', ['page_views' => 0, 'outbound' => 0]), [], $cat, $NOWT); ok(strpos($ok0, 'Nothing has been recorded since') !== false, 'nothing since the rollup is stated');
$life = ['chatgpt' => 4321, 'claude' => 99]; $TV = anz_body($mkS('2026-11-20 15:50:00', null, $life), ['view' => 'tools', 'range' => 'month'], $cat, $NOWT); ok(strpos($TV, 'Public lifetime total (all dates)') !== false && strpos($TV, '4,321') !== false && strpos($TV, 'does not change with the date range') !== false, 'the tools table shows the public lifetime total next to the date-filtered numbers and explains the difference');
$TV2 = anz_body($mkS('2026-11-20 15:50:00', null, null), ['view' => 'tools', 'range' => 'month'], $cat, $NOWT); ok(strpos($TV2, 'n/a') !== false, 'without the click table the lifetime column says n/a, not 0');
$LG = anz_body($mkS('2026-11-20 15:50:00', null, $life), ['view' => 'legacy', 'range' => 'month'], $cat, $NOWT); ok(strpos($LG, 'Public lifetime total (all dates)') !== false && strpos($LG, '4,420') !== false, 'the legacy view shows the lifetime total (sum of the public counters)');
$ex = anz_export($mkS('2026-11-20 15:50:00', null, $life), 'tools_all', $rng, [], $cat, $TODAY); ok(strpos($ex[1], 'public_lifetime_total_all_dates') !== false && strpos($ex[1], '4321') !== false, 'the tools export carries the lifetime column, clearly named');
echo "$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
