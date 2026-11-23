<?php
if (isset($_SERVER["SCRIPT_FILENAME"]) && realpath($_SERVER["SCRIPT_FILENAME"]) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
// Analytics A2-A5: the enhanced Analytics dashboard (owner only, inside ?m=analytics). Renders from an AdmAnStore; no output until called. Every number carries its state and denominator.
require_once __DIR__ . '/adm_an2_core.php';
require_once __DIR__ . '/adm_crm.php';   // shared compatibility styles (buttons, fields, tables) used by the dashboard markup

function anz_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function anz_n($n): string { return number_format((int)$n); }
function anz_ratio($r): string { return $r === null ? 'No profile views' : number_format($r * 100, 1) . '%'; }
function anz_views(): array { return ['overview' => 'Overview', 'visitors' => 'Visitors and history', 'pages' => 'Pages', 'sources' => 'Sources, devices, countries', 'tools' => 'AI tool clicks', 'categories' => 'Categories', 'placements' => 'Featured placements', 'legacy' => 'Legacy click records', 'exports' => 'Exports', 'details' => 'Conversions and lifetime counts']; }
function anz_view(array $get): string { $v = $get['view'] ?? 'overview'; return is_string($v) && isset(anz_views()[$v]) ? $v : 'overview'; }
function anz_url(array $range, array $extra = []): string {
  $q = ['m' => 'analytics', 'range' => $range['key']]; if ($range['key'] === 'custom') $q += ['from' => $range['from'], 'to' => $range['to']];
  return '/admin-center.php?' . http_build_query(array_filter($extra + $q, fn($v) => $v !== '' && $v !== null));
}
/** Current catalogue as slug => name and category (used to attach names and categories to tool slugs). */
function anz_catalogue(): array { $c = []; foreach (tools_by_id() as $id => $t) $c[(string)$id] = ['name' => (string)($t['name'] ?? $id), 'category' => (string)($t['category'] ?? '')]; return $c; }

function anz_card(string $label, ?string $value, string $state, string $note): string {
  return '<li class="tile anCard"><h3>' . anz_h($label) . '</h3>' . ($value !== null ? '<div class="val">' . anz_h($value) . '</div>' : '<div class="nv">' . ($state === ANZ_STATE_UNAVAILABLE ? 'Unavailable' : 'No data yet') . '</div>') . '<p class="small"><span class="anState anState-' . strtolower(anz_h($state)) . '">' . anz_h($state) . '</span> ' . anz_h($note) . '</p></li>';
}

/** Accessible line chart: titled, described, keyboard-focusable points (up to 62), gaps for days without data, and a data table alternative. */
function anz_chart(string $id, string $title, string $desc, string $from, string $to, array $series): string {
  $n = anz_days_between($from, $to); $hasAny = false; foreach ($series as $pts) if ($pts) $hasAny = true;
  if (!$hasAny) return '<figure class="anChart anEmpty"><figcaption><strong>' . anz_h($title) . '</strong></figcaption><p class="small">Nothing was recorded in this range, so there is nothing to draw.</p></figure>';
  $w = 720; $h = 220; $pl = 44; $pr = 12; $pt = 14; $pb = 30; $max = 1; foreach ($series as $pts) foreach ($pts as $v) $max = max($max, (int)$v);
  $x = fn(int $i) => $n > 1 ? $pl + ($w - $pl - $pr) * $i / ($n - 1) : $w / 2; $y = fn($v) => $h - $pb - ($h - $pt - $pb) * $v / $max; $cols = ['var(--brand)', 'var(--ok)', 'var(--na)'];
  $o = '<figure class="anChart" id="' . anz_h($id) . '"><figcaption><strong>' . anz_h($title) . '</strong><span class="small"> ' . anz_h($desc) . '</span></figcaption><svg role="img" aria-labelledby="' . anz_h($id) . '-t" viewBox="0 0 ' . $w . ' ' . $h . '"><title id="' . anz_h($id) . '-t">' . anz_h($title . '. ' . $desc) . '</title>';
  foreach ([0, .5, 1] as $g) { $gy = round($y($max * $g), 1); $o .= '<line x1="' . $pl . '" x2="' . ($w - $pr) . '" y1="' . $gy . '" y2="' . $gy . '" stroke="var(--line)"/><text x="' . ($pl - 6) . '" y="' . ($gy + 4) . '" font-size="11" text-anchor="end" fill="var(--muted)">' . anz_h(number_format((int)round($max * $g))) . '</text>'; }
  $o .= '<text x="' . $pl . '" y="' . ($h - 8) . '" font-size="11" fill="var(--muted)">' . anz_h($from) . '</text><text x="' . ($w - $pr) . '" y="' . ($h - 8) . '" font-size="11" text-anchor="end" fill="var(--muted)">' . anz_h($to) . '</text>';
  $k = 0;
  foreach ($series as $label => $pts) {
    $run = []; $flush = function () use (&$run, &$o, $cols, $k) { if (count($run) > 1) $o .= '<polyline fill="none" stroke="' . $cols[$k % 3] . '" stroke-width="2.5" stroke-linejoin="round" points="' . implode(' ', $run) . '"/>'; $run = []; };
    for ($i = 0; $i < $n; $i++) { $d = anz_add_days($from, $i); if (!isset($pts[$d])) { $flush(); continue; } $run[] = round($x($i), 1) . ',' . round($y((int)$pts[$d]), 1); if ($n <= 62 || isset($pts[$d]) && count($pts) <= 62) $o .= '<circle cx="' . round($x($i), 1) . '" cy="' . round($y((int)$pts[$d]), 1) . '" r="4" fill="' . $cols[$k % 3] . '" tabindex="0" class="anPt"><title>' . anz_h($label . ', ' . $d . ': ' . number_format((int)$pts[$d])) . '</title></circle>'; }
    $flush(); $k++;
  }
  $o .= '</svg><p class="small anLegend">'; $k = 0; foreach (array_keys($series) as $label) { $o .= '<span style="color:' . $cols[$k % 3] . ';font-weight:700">&#9632;</span> ' . anz_h($label) . ' &nbsp; '; $k++; }
  $o .= '</p><details class="anData"><summary>Show the data as a table</summary><div class="tableWrap" tabindex="0" role="region" aria-label="' . anz_h($title) . ' data (scrollable)"><table class="table"><caption class="sr">' . anz_h($title) . '</caption><thead><tr><th scope="col">Day (UTC)</th>';
  foreach (array_keys($series) as $label) $o .= '<th scope="col">' . anz_h($label) . '</th>'; $o .= '</tr></thead><tbody>';
  $days = []; foreach ($series as $pts) foreach (array_keys($pts) as $d) $days[$d] = 1; ksort($days);
  foreach (array_keys($days) as $d) { $o .= '<tr><th scope="row">' . anz_h($d) . '</th>'; foreach ($series as $pts) $o .= '<td>' . (isset($pts[$d]) ? anz_n($pts[$d]) : 'No data') . '</td>'; $o .= '</tr>'; }
  return $o . '</tbody></table></div></details></figure>';
}

/** $rows: list of lists of already-escaped HTML cells; first cell is a row header. */
function anz_table(string $id, string $caption, array $heads, array $rows, string $empty, string $note = ''): string {
  $o = '<section class="panel" aria-labelledby="h-' . anz_h($id) . '"><h3 id="h-' . anz_h($id) . '">' . anz_h($caption) . '</h3>' . ($note !== '' ? '<p class="small">' . anz_h($note) . '</p>' : '');
  if (!$rows) return $o . '<p class="small">' . anz_h($empty) . '</p></section>';
  $o .= '<div class="tableWrap" tabindex="0" role="region" aria-label="' . anz_h($caption) . ' (scrollable)"><table class="table"><caption class="sr">' . anz_h($caption) . '</caption><thead><tr>';
  foreach ($heads as $h) $o .= '<th scope="col">' . anz_h($h) . '</th>'; $o .= '</tr></thead><tbody>';
  foreach ($rows as $r) { $o .= '<tr>'; foreach ($r as $i => $c) $o .= $i === 0 ? '<th scope="row">' . $c . '</th>' : '<td>' . $c . '</td>'; $o .= '</tr>'; }
  return $o . '</tbody></table></div></section>';
}
function anz_bar(int $v, int $max): string { return '<span class="anBar" aria-hidden="true"><span style="width:' . ($max > 0 ? max(2, (int)round($v / $max * 100)) : 0) . '%"></span></span>'; }

function anz_range_form(array $range, string $view, array $keep): string {
  $o = '<form method="get" action="/admin-center.php" class="anRange" aria-label="Date range"><input type="hidden" name="m" value="analytics"><input type="hidden" name="view" value="' . anz_h($view) . '">';
  foreach ($keep as $k => $v) if ($v !== '') $o .= '<input type="hidden" name="' . anz_h($k) . '" value="' . anz_h($v) . '">';
  $o .= '<div class="field"><label for="an_range">Date range (UTC)</label><select class="input" id="an_range" name="range">';
  foreach (['today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'This week', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'month' => 'This month', 'year' => 'This year', 'all' => 'Since tracking began', 'custom' => 'Custom'] as $k => $l) $o .= '<option value="' . $k . '"' . ($range['key'] === $k ? ' selected' : '') . '>' . $l . '</option>';
  $o .= '</select></div><div class="field"><label for="an_from">From (custom)</label><input class="input" id="an_from" name="from" maxlength="10" placeholder="2026-11-01" value="' . anz_h($range['key'] === 'custom' ? $range['from'] : '') . '"></div><div class="field"><label for="an_to">To (custom)</label><input class="input" id="an_to" name="to" maxlength="10" placeholder="2026-11-20" value="' . anz_h($range['key'] === 'custom' ? $range['to'] : '') . '"></div><button class="btn sm">Apply</button></form>';
  return $o . ($range['error'] ? '<p class="noteBox" role="alert">' . anz_h($range['error']) . '</p>' : '');
}

function anz_status_notice(array $s): ?string {
  if (empty($s['storage'])) return '<section class="panel"><h2>Analytics storage is not installed</h2><p>The five reporting tables from Phase 8B have not been created on this server, so there is nothing to show. An administrator installs them after review, on staging first: see <code>docs/analytics/STAGING_INSTALL_AND_VERIFY.md</code>. Nothing is collected or estimated meanwhile.</p></section>';
  if (empty($s['first_day'])) return '<section class="panel"><h2>No tracking data yet</h2><p>' . (empty($s['tracking']) ? 'Visitor tracking is <strong>not enabled</strong> on this server (<code>AIHUB_ANALYTICS</code> is off), so nothing has been recorded.' : 'Tracking is enabled but no day has been rolled up yet. The hourly rollup creates the first day after the first recorded visit.') . ' No number on this dashboard is an estimate of past traffic.</p></section>';
  return null;
}

function anz_body(AdmAnStore $store, array $get, array $catalogue, ?int $now = null, ?callable $details = null): string {
  $now = $now ?? time(); $today = gmdate('Y-m-d', $now); $st = $store->status(); $view = anz_view($get);
  $nav = '<nav aria-label="Analytics sections" class="crmNav">'; $range0 = anz_range($get, $today, $st['first_day'] ?? null);
  foreach (anz_views() as $k => $l) $nav .= '<a class="btn sm' . ($k === $view ? '' : ' secondary') . '" href="' . anz_h(anz_url($range0, ['view' => $k])) . '"' . ($k === $view ? ' aria-current="page"' : '') . '>' . anz_h($l) . '</a>';
  $o = '<div class="admCrm admAn"><h1>Analytics</h1><p class="small">First-party and privacy-conscious: no IP address, no user agent and no cookie is stored for page views. A <strong>daily visitor</strong> is one anonymous, daily-rotating hash, <strong>not a person</strong>; over several days the sum is called <strong>visitor-days</strong>. Bots are excluded by a heuristic. Every date is UTC. Counts are a floor: visitors who block tracking or send Do-Not-Track are under-counted.</p>' . $nav;
  if ($notice = anz_status_notice($st)) return $o . $notice . '</div>';
  $o .= anz_range_form($range0, $view, array_intersect_key($get, ['q' => 1, 'cat' => 1, 'sort' => 1, 'top' => 1, 'tool' => 1]) ? array_map(fn($v) => is_string($v) ? $v : '', array_intersect_key($get, ['q' => 1, 'cat' => 1, 'sort' => 1, 'top' => 1, 'tool' => 1])) : []);
  $age = anz_rollup_age_minutes($st['last_run'] ?? null, $now); $un = $store->unrolled($st['last_run'] ?? null);
  $stale = $age === null || $age > ANZ_ROLLUP_STALE_MINUTES; $pend = $un ? $un['page_views'] + $un['outbound'] : 0;
  $o .= '<div class="noteBox" role="' . ($stale ? 'alert' : 'status') . '"><strong>' . ($age === null ? 'No rollup has run yet.' : ($stale ? 'The reports below are out of date.' : 'Reports are current.')) . '</strong> The date-filtered numbers on this page come from the hourly rollup'
    . ($age === null ? '.' : '; the last one finished at ' . anz_h($st['last_run']) . ' UTC (' . ($age >= 120 ? (int)floor($age / 60) . ' hours' : $age . ' minutes') . ' ago).')
    . ($un !== null && $pend > 0 ? ' Recorded since then and <strong>not yet included</strong>: ' . anz_n($un['page_views']) . ' page views and ' . anz_n($un['outbound']) . ' outbound clicks.' : ($un !== null ? ' Nothing has been recorded since.' : ''))
    . ($stale ? ' A rollup must be run (<code>php scripts/adm_rollup.php</code>) or scheduled: see <code>docs/analytics/ROLLUP_RECOVERY.md</code>.' : '') . '</div>';
  $o .= '<p class="small">Showing <strong>' . anz_h($range0['label']) . '</strong>, ' . anz_h($range0['from']) . ' to ' . anz_h($range0['to']) . '. Data recorded from ' . anz_h($st['first_day']) . '; last rolled-up day ' . anz_h($st['last_day'] ?? 'unknown') . ($st['last_run'] ? '; last rollup ' . anz_h($st['last_run']) . ' UTC' : '') . '. Today is partial until the next rollup.</p>';
  if ($view === 'details') return $o . ($details ? '<p class="small">The earlier Analytics page: conversions with same-day attribution and the lifetime tool click counter (not date-filtered).</p>' . $details() : '<p class="small">Not available.</p>') . '</div>';
  $fn = 'anz_view_' . $view; return $o . (function_exists($fn) ? $fn($store, $get, $catalogue, $range0, $st, $today) : '') . '</div>';
}

function anz_view_overview(AdmAnStore $s, array $get, array $cat, array $range, array $st, string $today): string {
  $p = anz_periods($today, $st['first_day']); $from = min($p['prev_30d'][0], $p['year'][0], $range['from']); $days = $s->traffic($st['first_day'] > $from ? $st['first_day'] : $from, $today);
  $all = $p['all'] ? anz_sum($s->traffic($p['all'][0], $today), $p['all'][0], $today) : null;
  $sum = fn(string $k) => anz_sum($days, $p[$k][0], $p[$k][1]); $t = $sum('today'); $y = $sum('yesterday'); $w = $sum('7d'); $m = $sum('30d'); $wk = $sum('week'); $mo = $sum('month'); $yr = $sum('year');
  $has = fn(array $x) => $x['days_with_data'] > 0; $act = $s->activeNow();
  $c = '<ul class="admGrid anCards">';
  $c .= anz_card('Daily visitors today', $has($t) ? anz_n($t['visitor_days']) : null, ANZ_STATE_APPROX, 'Distinct anonymous daily hashes since 00:00 UTC; not people. Partial day.');
  $c .= anz_card('Daily visitors yesterday', $has($y) ? anz_n($y['visitor_days']) : null, ANZ_STATE_APPROX, 'Distinct anonymous daily hashes; not people.');
  $c .= anz_card('Visitor-days, last 7 days', $has($w) ? anz_n($w['visitor_days']) : null, ANZ_STATE_APPROX, 'Sum of daily visitors over ' . $w['days_with_data'] . ' of 7 days with data. NOT unique people: the same person on two days counts twice.');
  $c .= anz_card('Visitor-days, last 30 days', $has($m) ? anz_n($m['visitor_days']) : null, ANZ_STATE_APPROX, 'Sum of daily visitors over ' . $m['days_with_data'] . ' of 30 days with data. NOT unique people.');
  $c .= anz_card('Page views today', $has($t) ? anz_n($t['views']) : null, ANZ_STATE_MEASURED, 'Human page views (bots and error pages excluded). Partial day.');
  $c .= anz_card('Page views this week', $has($wk) ? anz_n($wk['views']) : null, ANZ_STATE_MEASURED, 'Monday ' . $p['week'][0] . ' to today, ' . $wk['days_with_data'] . ' of ' . $wk['span'] . ' days with data.');
  $c .= anz_card('Page views this month', $has($mo) ? anz_n($mo['views']) : null, ANZ_STATE_MEASURED, $mo['days_with_data'] . ' of ' . $mo['span'] . ' days with data.');
  $c .= anz_card('Page views this year', $has($yr) ? anz_n($yr['views']) : null, ANZ_STATE_MEASURED, $yr['days_with_data'] . ' of ' . $yr['span'] . ' days with data; tracking began ' . $st['first_day'] . '.');
  $c .= anz_card('Page views since tracking began', $all ? anz_n($all['views']) : null, ANZ_STATE_MEASURED, $all ? 'Recorded ' . $all['days_with_data'] . ' days from ' . $st['first_day'] . '. Not an all-time figure for the site.' : 'No data.');
  $c .= anz_card('Sessions today', $has($t) && $t['sessions'] > 0 ? anz_n($t['sessions']) : null, ANZ_STATE_APPROX, 'A daily hash\'s views split after 30 minutes of inactivity. Visitors without a hash (Do-Not-Track) have no sessions.');
  $rs = anz_sum($s->traffic($range['from'], $range['to']), $range['from'], $range['to']);
  $c .= anz_card('Pages per session, ' . $range['label'], $rs['pages_per_session'] !== null ? number_format($rs['pages_per_session'], 2) : null, ANZ_STATE_APPROX, 'Human page views divided by approximate sessions in the selected range.');
  $c .= anz_card('Visitors active now', $act ? anz_n($act['count']) : null, $act ? ANZ_STATE_ESTIMATE : ANZ_STATE_UNAVAILABLE, $act ? 'Distinct anonymous hashes with a human page view in the last ' . ANZ_ACTIVE_WINDOW_MIN . ' minutes (last view ' . $act['last_view'] . ' server time). Excludes Do-Not-Track visitors; not a live count.' : 'Needs tracking enabled and a human page view in the last 24 hours; neither is recorded right now.');
  $c .= '</ul>'; $o = '<section aria-labelledby="h-kpi"><h2 id="h-kpi">Visitors and page views</h2>' . $c . '</section>';
  $rows = []; foreach (anz_growth_rows($days, $today, $st['first_day']) as $g) $rows[] = [anz_h($g['label']) . ($g['partial'] ? ' <span class="small">(today is partial)</span>' : ''), '<span class="small">' . anz_h($g['periods']) . '</span>', anz_h($g['visitors']['text']), anz_h($g['views']['text'])];
  $o .= anz_table('growth', 'Growth, with the numbers behind each percentage', ['Comparison', 'Periods (UTC)', 'Visitor-days', 'Page views'], $rows, 'No data.', 'Change = (current - previous) / previous. No percentage is shown when the previous period is zero or has days without data.');
  $series = ['Daily visitors' => [], 'Page views' => []]; foreach ($s->traffic($range['from'], $range['to']) as $r) { $series['Daily visitors'][$r['day']] = (int)$r['visitors']; $series['Page views'][$r['day']] = (int)$r['views_human']; }
  $o .= '<section class="panel">' . anz_chart('c-traffic', 'Daily visitors and page views', $range['label'] . '. Days without recorded data are gaps, not zeros.', $range['from'], $range['to'], $series) . '</section>';
  $pg = $s->dim('page', $range['from'], $range['to'], 10); $max = $pg ? (int)max(array_column($pg, 'views')) : 0; $rr = [];
  foreach ($pg as $r) $rr[] = [anz_h($r['k']), anz_n($r['views']) . ' ' . anz_bar($r['views'], $max), anz_n($r['visitor_days'])];
  $o .= anz_table('toppages', 'Most visited pages (top 10)', ['Page', 'Page views', 'Visitor-days'], $rr, 'No page views in this range.');
  $tools = anz_tool_rows($s->tools($range['from'], $range['to']), $cat); $rk = anz_rank($tools, 'outbound', 10); $rr = []; $max = $rk['rows'] ? $rk['rows'][0]['outbound'] : 0;
  foreach ($rk['rows'] as $i => $r) $rr[] = [anz_h($r['name']), anz_n($r['outbound']) . ' ' . anz_bar($r['outbound'], $max), anz_n($r['views']), anz_h(anz_ratio($r['ratio']))];
  $o .= anz_table('toptools', 'Top 10 AI tools by outbound clicks', ['Tool', 'Outbound clicks', 'Profile views', 'Outbound / profile views'], $rr, 'No outbound clicks in this range.', 'Outbound click = a visit to the tool\'s official website through our redirect, counted once per click. Bots, prefetches and repeat clicks within 5 seconds are not counted.');
  return $o . '<p class="small"><a href="' . anz_h(anz_url($range, ['view' => 'tools'])) . '">All tools, search and filters</a></p>';
}

function anz_view_visitors(AdmAnStore $s, array $get, array $cat, array $range, array $st, string $today): string {
  $days = $s->traffic($range['from'], $range['to']); $sum = anz_sum($days, $range['from'], $range['to']);
  $o = '<section class="panel"><h2>' . anz_h($range['label']) . '</h2><p><strong>' . anz_n($sum['views']) . '</strong> page views; <strong>' . anz_n($sum['visitor_days']) . '</strong> visitor-days (' . $sum['days_with_data'] . ' of ' . $sum['span'] . ' days have data); <strong>' . anz_n($sum['sessions']) . '</strong> approximate sessions' . ($sum['pages_per_session'] !== null ? '; ' . number_format($sum['pages_per_session'], 2) . ' pages per session' : '') . '.</p><p class="small">Visitor-days add up each day\'s distinct anonymous hashes. They are not the number of different people: a hash is new every day, and returning visitors cannot and will not be recognised across days.</p></section>';
  $series = ['Daily visitors' => [], 'Page views' => []]; foreach ($days as $r) { $series['Daily visitors'][$r['day']] = (int)$r['visitors']; $series['Page views'][$r['day']] = (int)$r['views_human']; }
  $o .= '<section class="panel">' . anz_chart('c-daily', 'Daily visitors and page views', 'Each point is one UTC day.', $range['from'], $range['to'], $series) . '</section>';
  $rows = []; foreach (array_reverse($days) as $r) $rows[] = [anz_h($r['day']), anz_n($r['visitors']), anz_n($r['views_human']), anz_n($r['sessions']), $r['sessions'] > 0 ? number_format($r['views_human'] / $r['sessions'], 2) : '—', anz_n($r['views_no_id'])];
  $o .= anz_table('daily', 'Day by day', ['Day (UTC)', 'Daily visitors', 'Page views', 'Sessions (approx.)', 'Pages per session', 'Views without visitor id'], $rows, 'No tracking data for this range.', 'Views without visitor id come from Do-Not-Track or Global Privacy Control requests and from servers without a configured salt; they count as page views but not as visitors or sessions.');
  $all = $st['first_day'] ? $s->traffic($st['first_day'], $today) : []; $mrows = [];
  foreach (anz_months($all) as $m) $mrows[] = [anz_h($m['month']), anz_n($m['visitor_days']), anz_n($m['views']), anz_n($m['sessions']), $m['days'] . ' of ' . (int)gmdate('t', strtotime($m['month'] . '-01 UTC')) . ($m['month'] === substr($today, 0, 7) ? ' (month in progress)' : '')];
  return $o . anz_table('monthly', 'Month by month since tracking began', ['Month', 'Visitor-days', 'Page views', 'Sessions (approx.)', 'Days with data'], $mrows, 'No history yet.', 'Months before ' . anz_h($st['first_day'] ?? '') . ' were not tracked and are not shown or estimated.');
}

function anz_view_pages(AdmAnStore $s, array $get, array $cat, array $range, array $st, string $today): string {
  $top = in_array((int)($get['top'] ?? 20), [10, 20, 50, 100], true) ? (int)($get['top'] ?? 20) : 20; $q = is_string($get['q'] ?? null) ? mb_strtolower(trim($get['q'])) : '';
  $rows = array_values(array_filter($s->dim('page', $range['from'], $range['to'], 5000), fn($r) => $q === '' || strpos(mb_strtolower($r['k']), $q) !== false)); $n = count($rows); $rows = array_slice($rows, 0, $top); $max = $rows ? (int)max(array_column($rows, 'views')) : 0;
  $f = '<form method="get" action="/admin-center.php" class="anRange" aria-label="Filter pages"><input type="hidden" name="m" value="analytics"><input type="hidden" name="view" value="pages"><input type="hidden" name="range" value="' . anz_h($range['key']) . '">' . ($range['key'] === 'custom' ? '<input type="hidden" name="from" value="' . anz_h($range['from']) . '"><input type="hidden" name="to" value="' . anz_h($range['to']) . '">' : '')
    . '<div class="field"><label for="p_q">Search pages</label><input class="input" id="p_q" name="q" maxlength="60" value="' . anz_h($q) . '"></div><div class="field"><label for="p_top">Show</label><select class="input" id="p_top" name="top">'; foreach ([10, 20, 50, 100] as $k) $f .= '<option value="' . $k . '"' . ($top === $k ? ' selected' : '') . '>Top ' . $k . '</option>'; $f .= '</select></div><button class="btn sm">Apply</button></form>';
  $rr = []; foreach ($rows as $r) $rr[] = [anz_h($r['k']), anz_n($r['views']) . ' ' . anz_bar($r['views'], $max), anz_n($r['visitor_days'])];
  return $f . anz_table('pages', 'Most visited pages', ['Page', 'Page views', 'Visitor-days'], $rr, 'No page views match.', $n . ' matching pages. The rollup keeps the busiest 300 pages per day; the rest are grouped as "(other pages)".');
}

function anz_view_sources(AdmAnStore $s, array $get, array $cat, array $range, array $st, string $today): string {
  $o = ''; $ref = anz_shares($s->dim('referrer', $range['from'], $range['to'], 50)); $rr = []; foreach ($ref as $r) $rr[] = [anz_h(anz_referrer_label($r['k'])), anz_n($r['views']), $r['share'] !== null ? number_format($r['share'], 1) . '%' : '—', anz_n($r['visitor_days'])];
  $o .= anz_table('refs', 'Traffic sources', ['Source', 'Page views', 'Share of views', 'Visitor-days'], $rr, 'No page views in this range.', 'Source = the referrer the browser sent. "Direct or unknown" includes typed addresses, bookmarks, apps and any visit whose referrer was stripped, so it is not purely direct traffic.');
  $hosts = $s->dim('refhost', $range['from'], $range['to'], 15); $hr = []; foreach ($hosts as $r) $hr[] = [anz_h($r['k']), anz_n($r['views'])]; $o .= anz_table('hosts', 'Top referring websites', ['Website', 'Page views'], $hr, 'No external referrers recorded.');
  if (empty($st['device_col'])) $o .= '<section class="panel"><h3>Devices</h3><p class="small">Device data is not collected on this server yet. It needs one optional, additive database column (<code>database/analytics_a4_device.sql</code>), applied by an administrator after review. Only the words mobile, tablet or desktop would be stored, never the user agent.</p></section>';
  else { $dv = anz_shares($s->dim('device', $range['from'], $range['to'], 10)); $dr = []; foreach ($dv as $r) $dr[] = [anz_h(anz_device_label($r['k'])), anz_n($r['views']), $r['share'] !== null ? number_format($r['share'], 1) . '%' : '—', anz_n($r['visitor_days'])]; $o .= anz_table('devices', 'Device categories', ['Device', 'Page views', 'Share', 'Visitor-days'], $dr, 'No device data in this range (page views recorded before the device column existed, or from bots, have none).', 'Coarse class derived from the user agent when the page was served; the user agent itself is not stored.'); }
  $co = $s->dim('country', $range['from'], $range['to'], 30); $cr = []; foreach ($co as $r) $cr[] = [anz_h($r['k'] === 'XX' ? 'Unknown' : $r['k']), anz_n($r['views']), anz_n($r['visitor_days'])];
  return $o . anz_table('countries', 'Countries', ['Country', 'Page views', 'Visitor-days'], $cr, 'No country data in this range.', 'Country comes from a header added by the CDN or proxy and is only as reliable as that header; "Unknown" means none was sent. No region or city is recorded.');
}

function anz_tool_filters(array $get, array $tools): array {
  $top = in_array((int)($get['top'] ?? 20), [10, 20, 50, 100], true) ? (int)($get['top'] ?? 20) : 20; $sort = in_array($get['sort'] ?? '', ['outbound', 'views', 'ratio', 'name'], true) ? $get['sort'] : 'outbound';
  $cats = array_unique(array_column($tools, 'category')); sort($cats); $c = is_string($get['cat'] ?? null) && in_array($get['cat'], $cats, true) ? $get['cat'] : '';
  return [$top, $sort, is_string($get['q'] ?? null) ? mb_substr(trim($get['q']), 0, 60) : '', $c, $cats];
}

function anz_view_tools(AdmAnStore $s, array $get, array $cat, array $range, array $st, string $today): string {
  $tools = anz_tool_rows($s->tools($range['from'], $range['to']), $cat); [$top, $sort, $q, $c, $cats] = anz_tool_filters($get, $tools);
  $span = anz_days_between($range['from'], $range['to']); $pf = anz_add_days($range['from'], -$span); $pt = anz_add_days($range['from'], -1); $prevDays = count($s->traffic($pf, $pt));
  $chg = anz_tool_changes($s->tools($range['from'], $range['to']), $s->tools($pf, $pt), $prevDays, $span);
  $tot = ['views' => 0, 'outbound' => 0, 'affiliate' => 0, 'sponsored' => 0]; foreach ($tools as $t) { $tot['views'] += $t['views']; $tot['outbound'] += $t['outbound']; $tot['affiliate'] += $t['affiliate']; $tot['sponsored'] += $t['sponsored']; }
  $o = '<ul class="admGrid anCards">' . anz_card('Outbound clicks, ' . $range['label'], $tot['outbound'] > 0 || $tot['views'] > 0 ? anz_n($tot['outbound']) : null, ANZ_STATE_MEASURED, 'Clicks through our redirect to official tool websites; each click counted once.')
    . anz_card('Tool profile page views', $tot['views'] > 0 ? anz_n($tot['views']) : null, ANZ_STATE_MEASURED, 'Human views of /tool.php pages (internal engagement, not clicks to other sites).')
    . anz_card('Outbound / profile views', $tot['views'] > 0 ? anz_ratio($tot['outbound'] / $tot['views']) : null, ANZ_STATE_MEASURED, 'A labelled ratio, NOT a listing click-through rate: outbound clicks can start on comparison, recommendation and card pages, so it can exceed 100%. Listing impressions are not measured.')
    . anz_card('Affiliate and sponsored clicks', $tot['outbound'] > 0 ? anz_n($tot['affiliate']) . ' / ' . anz_n($tot['sponsored']) : null, ANZ_STATE_MEASURED, 'Outbound clicks on affiliate links / on featured (sponsored) tools.') . '</ul>';
  $series = ['Outbound clicks' => [], 'Profile views' => []]; foreach ($s->toolDays($range['from'], $range['to']) as $r) { $series['Outbound clicks'][$r['day']] = (int)$r['outbound_clicks']; $series['Profile views'][$r['day']] = (int)$r['tool_views']; }
  $o .= '<section class="panel">' . anz_chart('c-tools', 'Daily outbound clicks and tool profile views', 'All tools together; internal profile views and outbound clicks are different things.', $range['from'], $range['to'], $series) . '</section>';
  $keep = ['range' => $range['key']] + ($range['key'] === 'custom' ? ['from' => $range['from'], 'to' => $range['to']] : []);
  $f = '<form method="get" action="/admin-center.php" class="anRange" aria-label="Filter tools"><input type="hidden" name="m" value="analytics"><input type="hidden" name="view" value="tools">'; foreach ($keep as $k => $v) $f .= '<input type="hidden" name="' . $k . '" value="' . anz_h($v) . '">';
  $f .= '<div class="field"><label for="t_q">Search tools (ChatGPT, Claude, Gemini, Perplexity, ...)</label><input class="input" id="t_q" name="q" maxlength="60" value="' . anz_h($q) . '"></div><div class="field"><label for="t_cat">Category</label><select class="input" id="t_cat" name="cat"><option value="">All categories</option>'; foreach ($cats as $k) $f .= '<option' . ($c === $k ? ' selected' : '') . '>' . anz_h($k) . '</option>';
  $f .= '</select></div><div class="field"><label for="t_top">Show</label><select class="input" id="t_top" name="top">'; foreach ([10, 20, 50, 100] as $k) $f .= '<option value="' . $k . '"' . ($top === $k ? ' selected' : '') . '>Top ' . $k . '</option>';
  $f .= '</select></div><div class="field"><label for="t_sort">Sort by</label><select class="input" id="t_sort" name="sort">'; foreach (['outbound' => 'Outbound clicks', 'views' => 'Profile views', 'ratio' => 'Outbound / profile views', 'name' => 'Name'] as $k => $l) $f .= '<option value="' . $k . '"' . ($sort === $k ? ' selected' : '') . '>' . $l . '</option>'; $f .= '</select></div><button class="btn sm">Apply</button></form>';
  $life = $s->lifetimeClicks(); $rk = anz_rank($tools, $sort, $top, $q, $c); $max = $rk['rows'] ? max(array_column($rk['rows'], 'outbound')) : 0; $rows = [];
  foreach ($rk['rows'] as $i => $r) { $g = $chg[$r['slug']] ?? null; $rows[] = ['<a href="' . anz_h(anz_url($range, ['view' => 'tools', 'tool' => $r['slug']])) . '">' . anz_h($r['name']) . '</a>' . ($r['in_catalogue'] ? '' : ' <span class="small">(not in current catalogue)</span>'), anz_h($r['category']), anz_n($r['outbound']) . ' ' . anz_bar($r['outbound'], $max), anz_n($r['views']), anz_h(anz_ratio($r['ratio'])), anz_n($r['affiliate']), anz_n($r['sponsored']), anz_h($g ? ($g['pct'] !== null ? $g['text'] : 'n/a') : 'n/a'), $life === null ? 'n/a' : anz_n($life[$r['slug']] ?? 0)]; }
  $o .= $f . anz_table('tools', 'AI tools ranked (' . $rk['matching'] . ' of ' . $rk['total'] . ' tools with activity)', ['Tool', 'Category', 'Outbound clicks', 'Profile views', 'Outbound / profile views', 'Affiliate', 'Sponsored', 'Change vs previous ' . $span . ' days', 'Public lifetime total (all dates)'], $rows, 'No tool activity matches in this range.', 'Change compares outbound clicks with the equal-length period before ' . $range['from'] . ' ('. $pf . ' to ' . $pt . ') and shows "n/a" where that period has no baseline or missing days. The LAST column is a different number: the lifetime total shown on the public site next to the eye icon (every row ever saved in the click table, all dates, including legacy records). It does not change with the date range, so it will not match the date-filtered columns.');
  $slug = is_string($get['tool'] ?? null) ? $get['tool'] : ''; foreach ($tools as $t) if ($t['slug'] === $slug) $o .= anz_funnel($s, $t, $range);
  return $o;
}

/** Tool-specific funnel: only the steps that are measured. */
function anz_funnel(AdmAnStore $s, array $t, array $range): string {
  $o = '<section class="panel" aria-labelledby="h-funnel"><h3 id="h-funnel">Funnel for ' . anz_h($t['name']) . ' (' . anz_h($range['label']) . ')</h3><div class="tableWrap" tabindex="0" role="region" aria-label="Funnel (scrollable)"><table class="table"><caption class="sr">Tool funnel</caption><thead><tr><th scope="col">Step</th><th scope="col">Count</th><th scope="col">State</th></tr></thead><tbody>'
    . '<tr><th scope="row">1. Tool profile page views</th><td>' . anz_n($t['views']) . '</td><td>Measured</td></tr><tr><th scope="row">2. Outbound clicks to the official website</th><td>' . anz_n($t['outbound']) . '</td><td>Measured</td></tr>'
    . '<tr><th scope="row">&nbsp;&nbsp;of which affiliate links</th><td>' . anz_n($t['affiliate']) . '</td><td>Measured</td></tr><tr><th scope="row">&nbsp;&nbsp;of which featured (sponsored)</th><td>' . anz_n($t['sponsored']) . '</td><td>Measured</td></tr>'
    . '<tr><th scope="row">Outbound / profile views</th><td>' . anz_h(anz_ratio($t['ratio'])) . '</td><td>Ratio</td></tr><tr><th scope="row">Listing impressions</th><td>Not measured</td><td>Unavailable</td></tr><tr><th scope="row">Purchases or sign-ups at the tool\'s own website</th><td>Not measured</td><td>Unavailable</td></tr></tbody></table></div>'
    . '<p class="small">Steps are totals, not the same visitors followed one by one: visitors are anonymous and daily hashes cannot be joined to a click without an identifier we deliberately do not keep.' . ($t['ratio'] !== null && $t['ratio'] > 1 ? ' The ratio is above 100% because outbound clicks also start from pages other than the profile page.' : '') . '</p>';
  $series = ['Outbound clicks' => [], 'Profile views' => []]; foreach ($s->toolDays($range['from'], $range['to'], $t['slug']) as $r) { $series['Outbound clicks'][$r['day']] = (int)$r['outbound_clicks']; $series['Profile views'][$r['day']] = (int)$r['tool_views']; }
  return $o . anz_chart('c-funnel', 'Daily trend for ' . $t['name'], 'Profile views and outbound clicks per day.', $range['from'], $range['to'], $series) . '</section>';
}

function anz_view_categories(AdmAnStore $s, array $get, array $cat, array $range, array $st, string $today): string {
  $cats = anz_categories(anz_tool_rows($s->tools($range['from'], $range['to']), $cat)); $max = $cats ? max(array_column($cats, 'outbound')) : 0; $rows = [];
  foreach ($cats as $c) $rows[] = [anz_h($c['category']), anz_n($c['tools']), anz_n($c['views']), anz_n($c['outbound']) . ' ' . anz_bar($c['outbound'], $max), $c['share'] !== null ? number_format($c['share'], 1) . '%' : '—', anz_h(anz_ratio($c['ratio'])), anz_n($c['affiliate']), anz_n($c['sponsored'])];
  return anz_table('cats', 'Category performance, ' . $range['label'], ['Category', 'Tools with activity', 'Profile views', 'Outbound clicks', 'Share of outbound clicks', 'Outbound / profile views', 'Affiliate', 'Sponsored'], $rows, 'No tool activity in this range.', 'Categories are those of the current catalogue, applied to the recorded tool clicks and views; a tool recategorised later is counted under its current category.');
}

function anz_view_placements(AdmAnStore $s, array $get, array $cat, array $range, array $st, string $today): string {
  $pl = $s->placements(); if ($pl === null) return '<section class="panel"><h2>Featured placements</h2><p>Featured placements are not available (tool management is not installed on this server).</p></section>';
  $covered = []; if ($pl) { $min = min(array_column($pl, 'starts_on')); foreach ($s->traffic(anz_add_days($min, -400), $today) as $r) $covered[$r['day']] = 1; }
  $sums = function (string $slug, string $f, string $t) use ($s) { $v = 0; $o = 0; foreach ($s->tools($f, $t) as $r) if ($r['tool_slug'] === $slug) { $v = (int)$r['tool_views']; $o = (int)$r['outbound_clicks']; } return ['views' => $v, 'outbound' => $o]; };
  $perf = anz_placement_perf($pl, $sums, $covered, $today, $cat); $rows = [];
  foreach ($perf as $p) $rows[] = [anz_h($p['name']), 'Level ' . (int)$p['level'], anz_h($p['starts_on'] . ' to ' . $p['ends_on']), anz_h(ucfirst($p['status'])), $p['measurable'] ? anz_n($p['during']['outbound']) . ' clicks, ' . anz_n($p['during']['views']) . ' views (' . $p['during']['days'] . ' days)' : '—', $p['measurable'] ? anz_n($p['before']['outbound']) . ' clicks, ' . anz_n($p['before']['views']) . ' views (' . anz_h($p['before']['from']) . ' to ' . anz_h($p['before']['to']) . ')' : '—', anz_h($p['measurable'] ? $p['growth']['text'] : $p['reason'])];
  return anz_table('place', 'Featured placement performance', ['Tool', 'Level', 'Placement dates', 'Status', 'During the placement', 'Equal period before', 'Change in outbound clicks'], $rows, 'No featured placements recorded.', 'Descriptive only: it compares the tool with itself before the placement and does not prove the placement caused the change (seasonality, news and other campaigns also move clicks). Measured only where tracking data exists for every day of both windows.');
}

function anz_view_legacy(AdmAnStore $s, array $get, array $cat, array $range, array $st, string $today): string {
  $rows = $s->legacyClicks($range['from'], $range['to']);
  if ($rows === null) return '<section class="panel"><h2>Legacy click records</h2><p>The older lifetime click table (<code>tool_clicks</code>) is not available on this server.</p></section>';
  $L = anz_legacy_summary($rows); $lifeAll = $s->lifetimeClicks(); $lifeTotal = $lifeAll === null ? null : array_sum($lifeAll);
  $o = '<section class="panel"><h2>Legacy click records, ' . anz_h($range['label']) . '</h2><p class="noteBox" role="note"><strong>Read this first.</strong> These are the old lifetime click records, written before tracking was fixed. They are <strong>not</strong> comparable with the new outbound-click counts and are never added to them. They contain <strong>no page views and no visitors</strong>: the browser cookie value stored in some rows identifies a browser, not a person, and is deliberately not counted here. Bots were not filtered, and with JavaScript one click was often written <strong>twice</strong> (about a second apart).</p>'
    . '<ul class="admGrid anCards">' . anz_card('Public lifetime total (all dates)', $lifeTotal !== null && $lifeTotal > 0 ? anz_n($lifeTotal) : null, ANZ_STATE_MEASURED, 'Every row in the click table, all dates: the sum of the numbers shown next to the eye icon on the public site. Not affected by the date range below.') . anz_card('Legacy click records', $L['records'] > 0 ? anz_n($L['records']) : null, ANZ_STATE_MEASURED, 'Rows in the old table with a click date in this range (UTC). Includes duplicates and bots.')
    . anz_card('Probable duplicate records', $L['records'] > 0 ? ($L['dup_known'] ? anz_n($L['probable_duplicates']) : null) : null, $L['dup_known'] ? ANZ_STATE_ESTIMATE : ANZ_STATE_UNAVAILABLE, $L['dup_known'] ? 'Records from the same browser cookie and tool within 10 seconds of the previous one: the old double write. Clicks without a stored cookie cannot be checked.' : 'Needs MySQL 8 window functions.')
    . anz_card('Records net of probable duplicates', $L['records'] > 0 && $L['dup_known'] ? anz_n($L['net']) : null, $L['dup_known'] ? ANZ_STATE_ESTIMATE : ANZ_STATE_UNAVAILABLE, 'Records minus probable duplicates. An estimate of click events with bots still included; not a count of people or visits.') . '</ul></section>';
  $series = ['Legacy records' => [], 'Probable duplicates' => []]; foreach ($L['days'] as $d) { $series['Legacy records'][$d['day']] = $d['records']; $series['Probable duplicates'][$d['day']] = $d['probable_duplicates']; }
  $o .= '<section class="panel">' . anz_chart('c-legacy', 'Legacy click records per day', 'Old table, before the fix; duplicates and bots included.', $range['from'], $range['to'], $series) . '</section>';
  $rr = []; $max = $L['tools'] ? $L['tools'][0]['records'] : 0; foreach (array_slice($L['tools'], 0, 50) as $t) { $c = $cat[$t['slug']] ?? null; $rr[] = [anz_h($c['name'] ?? $t['slug']), anz_h($c['category'] ?? '(not in the current catalogue)'), anz_n($t['records']) . ' ' . anz_bar($t['records'], $max), $L['dup_known'] ? anz_n($t['probable_duplicates']) : 'n/a'];}
  return $o . anz_table('legacytools', 'Legacy click records by tool (top 50)', ['Tool', 'Category', 'Records', 'Probable duplicates'], $rr, 'No legacy click records in this range.', 'Counts only what was recorded; days or tools with no record are not filled in.');
}

function anz_view_exports(AdmAnStore $s, array $get, array $cat, array $range, array $st, string $today): string {
  $o = '<section class="panel"><h2>CSV exports, ' . anz_h($range['label']) . '</h2><p class="small">Owner only. Every export is audited. Cells are protected against spreadsheet formula injection. The tool exports respect the search, category and sort chosen on the tools page.</p><ul class="anExports">';
  foreach (anz_export_names() as $k => $l) $o .= '<li><a class="btn sm secondary" href="' . anz_h(anz_url($range, ['export' => $k] + array_intersect_key($get, ['q' => 1, 'cat' => 1, 'sort' => 1]))) . '">' . anz_h($l) . '</a></li>';
  return $o . '</ul></section>';
}

function anz_export_names(): array { return ['visitors_daily' => 'Daily visitors and page views', 'visitors_monthly' => 'Monthly history', 'pages_all' => 'Pages', 'sources_all' => 'Traffic sources', 'devices_all' => 'Device categories', 'tools_all' => 'AI tool clicks and profile views', 'categories_all' => 'Category performance', 'placements_all' => 'Featured placement performance', 'legacy_clicks' => 'Legacy click records (old table; not comparable)']; }

/** @return ?array [filename, csv] */
function anz_export(AdmAnStore $s, string $which, array $range, array $get, array $cat, ?string $today = null): ?array {
  $today = $today ?? gmdate('Y-m-d'); [$f, $t] = [$range['from'], $range['to']]; $name = "aihubpedia-$which-$f-to-$t.csv"; $st = $s->status();
  switch ($which) {
    case 'visitors_daily': return [$name, adma_csv(['date_utc', 'daily_visitors_anonymous_hashes_not_people', 'page_views_human', 'sessions_approximate', 'views_without_visitor_id'], array_map(fn($r) => [$r['day'], $r['visitors'], $r['views_human'], $r['sessions'], $r['views_no_id']], $s->traffic($f, $t)))];
    case 'visitors_monthly': $all = $st['first_day'] ? $s->traffic($st['first_day'], $today) : []; return [$name, adma_csv(['month', 'visitor_days_not_unique_people', 'page_views_human', 'sessions_approximate', 'days_with_data'], array_map(fn($m) => [$m['month'], $m['visitor_days'], $m['views'], $m['sessions'], $m['days']], anz_months($all)))];
    case 'pages_all': return [$name, adma_csv(['page', 'page_views', 'visitor_days_not_unique_people'], array_map(fn($r) => [$r['k'], $r['views'], $r['visitor_days']], $s->dim('page', $f, $t, 5000)))];
    case 'sources_all': return [$name, adma_csv(['source', 'source_label', 'page_views', 'share_of_views_percent', 'visitor_days_not_unique_people'], array_map(fn($r) => [$r['k'], anz_referrer_label($r['k']), $r['views'], $r['share'] ?? '', $r['visitor_days']], anz_shares($s->dim('referrer', $f, $t, 100))))];
    case 'devices_all': return [$name, adma_csv(['device', 'page_views', 'share_of_views_percent', 'visitor_days_not_unique_people'], array_map(fn($r) => [$r['k'], $r['views'], $r['share'] ?? '', $r['visitor_days']], anz_shares($s->dim('device', $f, $t, 20))))];
    case 'tools_all': $tools = anz_tool_rows($s->tools($f, $t), $cat); [$top, $sort, $q, $c] = anz_tool_filters($get, $tools); $rk = anz_rank($tools, $sort, 5000, $q, $c); return [$name, adma_csv(['tool_slug', 'tool', 'category', 'tool_profile_views', 'outbound_clicks', 'outbound_clicks_per_profile_view', 'affiliate_outbound_clicks', 'sponsored_outbound_clicks', 'public_lifetime_total_all_dates'], array_map(fn($r) => [$r['slug'], $r['name'], $r['category'], $r['views'], $r['outbound'], $r['ratio'] ?? '', $r['affiliate'], $r['sponsored'], ($s->lifetimeClicks() ?? [])[$r['slug']] ?? ''], $rk['rows']))];
    case 'categories_all': return [$name, adma_csv(['category', 'tools_with_activity', 'tool_profile_views', 'outbound_clicks', 'share_of_outbound_clicks_percent', 'outbound_clicks_per_profile_view'], array_map(fn($c) => [$c['category'], $c['tools'], $c['views'], $c['outbound'], $c['share'] ?? '', $c['ratio'] ?? ''], anz_categories(anz_tool_rows($s->tools($f, $t), $cat))))];
    case 'legacy_clicks': $rows = $s->legacyClicks($f, $t); if ($rows === null) return null; return [$name, adma_csv(['date_utc', 'tool_slug', 'legacy_records_not_visitors_not_page_views', 'probable_duplicate_records'], array_map(fn($r) => [$r['day'], $r['tool_slug'], $r['records'], $r['dup_known'] ?? true ? $r['probable_duplicates'] : ''], $rows))];
    case 'placements_all': $pl = $s->placements(); if ($pl === null) return null; $cov = []; foreach ($s->traffic(anz_add_days($today, -800), $today) as $r) $cov[$r['day']] = 1;
      $sums = function (string $slug, string $a, string $b) use ($s) { $v = 0; $o = 0; foreach ($s->tools($a, $b) as $r) if ($r['tool_slug'] === $slug) { $v = (int)$r['tool_views']; $o = (int)$r['outbound_clicks']; } return ['views' => $v, 'outbound' => $o]; };
      return [$name, adma_csv(['tool_slug', 'tool', 'level', 'starts_on', 'ends_on', 'status', 'measurable', 'outbound_during', 'outbound_equal_period_before', 'change_percent', 'note'], array_map(fn($p) => [$p['slug'], $p['name'], $p['level'], $p['starts_on'], $p['ends_on'], $p['status'], $p['measurable'] ? 'yes' : 'no', $p['measurable'] ? $p['during']['outbound'] : '', $p['measurable'] ? $p['before']['outbound'] : '', $p['measurable'] && $p['growth']['pct'] !== null ? $p['growth']['pct'] : '', $p['measurable'] ? $p['growth']['text'] : $p['reason']], anz_placement_perf($pl, $sums, $cov, $today, $cat)))];
  }
  return null;
}

function anz_css(): string {
  return '.admAn,.admAn .panel,.admAn .tableWrap,.admAn figure{min-width:0;max-width:100%}.admAn .tableWrap{overflow-x:auto}.admAn code{overflow-wrap:anywhere;word-break:break-word}.admAn .anCards{list-style:none;padding:0;margin:0 0 16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.admAn .anCard .small{margin:.4rem 0 0;color:var(--muted)}.admAn .anCard .val{font-size:1.6rem;font-weight:700}'
    . '.admAn .anState{display:inline-block;padding:1px 8px;border-radius:999px;border:1px solid var(--line);font-size:.75rem;font-weight:700;color:var(--ink);background:var(--surface)}.admAn .anState-measured{border-color:var(--ok)}.admAn .anState-estimate,.admAn .anState-approximate{border-color:var(--na)}.admAn .anState-unavailable{border-style:dashed}'
    . '.admAn .anRange{display:flex;flex-wrap:wrap;gap:10px 14px;align-items:flex-end;margin:8px 0 14px}.admAn .anRange .field{flex:1 1 170px;margin:0}.admAn .panel h2,.admAn section>h2{margin-top:1.2rem}'
    . '.admAn .anChart{margin:0}.admAn .anChart svg{width:100%;height:auto;max-height:260px;display:block}.admAn .anPt:focus{outline:3px solid var(--brand);outline-offset:2px}.admAn .anData summary{min-height:44px;display:flex;align-items:center;cursor:pointer;color:var(--brand)}'
    . '.admAn .anBar{display:inline-block;vertical-align:middle;width:80px;height:8px;border-radius:4px;background:var(--line);margin-left:6px}.admAn .anBar span{display:block;height:100%;border-radius:4px;background:var(--brand)}'
    . '.admAn .anExports{list-style:none;padding:0;display:flex;flex-wrap:wrap;gap:10px}.admAn td a,.admAn .small a,.admAn .panel p a{display:inline-flex;align-items:center;min-height:44px;min-width:44px}.admAn .noteBox{margin:0 0 12px}';
}
