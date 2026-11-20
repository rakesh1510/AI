<?php
if (isset($_SERVER["SCRIPT_FILENAME"]) && realpath($_SERVER["SCRIPT_FILENAME"]) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
// Analytics A2-A4: pure arithmetic for the enhanced Analytics dashboard. No database, no output. Every function takes recorded rows and returns numbers WITH their denominators and data state.
// Rules: nothing is estimated unless labelled; a percentage is never computed from a zero or incomplete baseline; daily "visitors" are distinct daily hashes (NOT people), longer periods are
// "visitor-days"; dates are UTC; a missing day is "no data", not zero.

const ANZ_STATE_MEASURED = 'Measured';
const ANZ_STATE_APPROX = 'Approximate';
const ANZ_STATE_ESTIMATE = 'Estimate';
const ANZ_STATE_UNAVAILABLE = 'Unavailable';
const ANZ_ACTIVE_WINDOW_MIN = 5;

/** Interface every store implements (database store, in-memory store for tests and previews). All ranges are inclusive UTC days YYYY-MM-DD. */
interface AdmAnStore {
  /** storage (5 tables installed), tracking (AIHUB_ANALYTICS on), first_day, last_day (rolled-up), last_run, raw_rows (page_views has rows), device_col (optional column present) */
  public function status(): array;
  /** Rows per day with data: day, views_human, visitors, sessions, views_no_id. */
  public function traffic(string $from, string $to): array;
  /** dim in page|referrer|refhost|country|ptype|device. Rows: k, views, visitor_days. */
  public function dim(string $dim, string $from, string $to, int $limit): array;
  /** Per tool: tool_slug, tool_views, outbound_clicks, affiliate_clicks, sponsored_clicks (summed over the range). */
  public function tools(string $from, string $to): array;
  /** Per day, all tools: day, tool_views, outbound_clicks. */
  public function toolDays(string $from, string $to, ?string $slug = null): array;
  /** @return ?array{count:int,last_view:?string} distinct visitor hashes with a human view in the last 5 minutes (estimate); null = cannot be determined */
  public function activeNow(): ?array;
  /** Featured placements (Phase 8C): tool_slug, level, starts_on, ends_on, status. null = not installed. */
  public function placements(): ?array;
  /** Legacy lifetime click records (tool_clicks), per day and tool: day, tool_slug, records, probable_duplicates. null = the table is missing. NOT visitors, NOT page views. */
  public function legacyClicks(string $from, string $to): ?array;
}

function anz_days_between(string $from, string $to): int { return (int)((strtotime($to . ' UTC') - strtotime($from . ' UTC')) / 86400) + 1; }
function anz_add_days(string $d, int $n): string { return gmdate('Y-m-d', strtotime($d . ' UTC') + $n * 86400); }
function anz_valid_day(string $d): bool { return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4)); }

/** The named periods the dashboard needs, relative to $today (UTC). 'all' starts at $firstDay (null = no data yet). */
function anz_periods(string $today, ?string $firstDay = null): array {
  $monday = anz_add_days($today, -((int)gmdate('N', strtotime($today . ' UTC')) - 1)); $month = substr($today, 0, 8) . '01'; $year = substr($today, 0, 4) . '-01-01';
  $prevMonthEnd = anz_add_days($month, -1); $prevMonthStart = substr($prevMonthEnd, 0, 8) . '01'; $dom = (int)substr($today, 8, 2); $prevMonthSame = anz_add_days($prevMonthStart, $dom - 1);
  return [
    'today' => [$today, $today], 'yesterday' => [anz_add_days($today, -1), anz_add_days($today, -1)], 'week' => [$monday, $today], '7d' => [anz_add_days($today, -6), $today], '30d' => [anz_add_days($today, -29), $today],
    'month' => [$month, $today], 'year' => [$year, $today], 'all' => $firstDay !== null ? [$firstDay, $today] : null,
    'prev_7d' => [anz_add_days($today, -13), anz_add_days($today, -7)], 'prev_30d' => [anz_add_days($today, -59), anz_add_days($today, -30)],
    'prev_month_same' => [$prevMonthStart, $prevMonthSame > $prevMonthEnd ? $prevMonthEnd : $prevMonthSame],
  ];
}

/** Range from the request: today|yesterday|week|7d|30d|month|year|all|custom (custom validated, max 800 days). Always valid. */
function anz_range(array $get, string $today, ?string $firstDay): array {
  $per = anz_periods($today, $firstDay); $key = is_string($get['range'] ?? null) ? $get['range'] : '30d'; $labels = ['today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'This week', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'month' => 'This month', 'year' => 'This year', 'all' => 'Since tracking began'];
  $err = null;
  if ($key === 'custom') {
    $f = is_string($get['from'] ?? null) ? $get['from'] : ''; $t = is_string($get['to'] ?? null) ? $get['to'] : '';
    if (!anz_valid_day($f) || !anz_valid_day($t)) $err = 'Custom dates must be real dates in the form YYYY-MM-DD. Showing the last 30 days instead.';
    elseif ($f > $t || $t > $today || anz_days_between($f, $t) > 800) $err = 'The custom range must start on or before its end, not end in the future, and cover at most 800 days. Showing the last 30 days instead.';
    else return ['key' => 'custom', 'from' => $f, 'to' => $t, 'label' => $f . ' to ' . $t, 'error' => null];
    $key = '30d';
  }
  if (!isset($labels[$key]) || ($key === 'all' && $per['all'] === null)) { $key = '30d'; }
  [$f, $t] = $per[$key]; return ['key' => $key, 'from' => $f, 'to' => $t, 'label' => $labels[$key] . ' (UTC)', 'error' => $err];
}

/** Totals over a range from daily rows. visitor_days = sum of daily distinct hashes (NOT people). */
function anz_sum(array $days, string $from, string $to): array {
  $t = ['views' => 0, 'visitor_days' => 0, 'sessions' => 0, 'no_id' => 0, 'days_with_data' => 0, 'span' => anz_days_between($from, $to)];
  foreach ($days as $r) { if ($r['day'] < $from || $r['day'] > $to) continue; $t['views'] += (int)$r['views_human']; $t['visitor_days'] += (int)$r['visitors']; $t['sessions'] += (int)$r['sessions']; $t['no_id'] += (int)($r['views_no_id'] ?? 0); $t['days_with_data']++; }
  $t['pages_per_session'] = $t['sessions'] > 0 ? round($t['views'] / $t['sessions'], 2) : null;
  return $t;
}

/**
 * Growth of $cur against $prev with its denominator. pct is null (and the reason given) when the baseline is zero or covers fewer days than the period asks for.
 * @return array{cur:int,prev:int,pct:?float,text:string,basis:string}
 */
function anz_growth(int $cur, int $prev, int $prevDaysWithData, int $prevSpan, string $basis): array {
  $g = ['cur' => $cur, 'prev' => $prev, 'pct' => null, 'basis' => $basis, 'text' => ''];
  if ($prevDaysWithData < $prevSpan) { $g['text'] = 'Not available: the comparison period has data for only ' . $prevDaysWithData . ' of ' . $prevSpan . ' days.'; return $g; }
  if ($prev <= 0) { $g['text'] = 'Not available: the comparison period recorded none, so a percentage would be meaningless.'; return $g; }
  $g['pct'] = round(($cur - $prev) / $prev * 100, 1); $g['text'] = ($g['pct'] > 0 ? '+' : '') . number_format($g['pct'], 1) . '% (' . number_format($cur) . ' vs ' . number_format($prev) . ')'; return $g;
}

/** Visitor and page-view growth rows for the Overview. $days must cover at least the previous 60 days. */
function anz_growth_rows(array $days, string $today, ?string $firstDay): array {
  $p = anz_periods($today, $firstDay); $rows = [];
  $pair = function (string $label, array $cur, array $prev, bool $partial) use ($days, &$rows) {
    $c = anz_sum($days, $cur[0], $cur[1]); $o = anz_sum($days, $prev[0], $prev[1]);
    $rows[] = ['label' => $label, 'periods' => $cur[0] . ($cur[0] !== $cur[1] ? ' to ' . $cur[1] : '') . ' vs ' . $prev[0] . ($prev[0] !== $prev[1] ? ' to ' . $prev[1] : ''), 'partial' => $partial,
      'visitors' => anz_growth($c['visitor_days'], $o['visitor_days'], $o['days_with_data'], $o['span'], 'visitor-days'), 'views' => anz_growth($c['views'], $o['views'], $o['days_with_data'], $o['span'], 'page views')];
  };
  $pair('Today so far vs yesterday', $p['today'], $p['yesterday'], true);
  $pair('Last 7 days vs the 7 before', $p['7d'], $p['prev_7d'], false);
  $pair('Last 30 days vs the 30 before', $p['30d'], $p['prev_30d'], false);
  $pair('Month to date vs same days last month', $p['month'], $p['prev_month_same'], false);
  return $rows;
}

/** Tool rows with catalogue name and category. $catalogue: slug => ['name','category']. outbound/views = the labelled ratio (D7), null when no profile views. */
function anz_tool_rows(array $rows, array $catalogue): array {
  $out = [];
  foreach ($rows as $r) {
    $slug = (string)$r['tool_slug']; $c = $catalogue[$slug] ?? null; $v = (int)$r['tool_views']; $o = (int)$r['outbound_clicks'];
    $out[] = ['slug' => $slug, 'name' => $c['name'] ?? $slug, 'category' => $c['category'] ?? '(not in the current catalogue)', 'views' => $v, 'outbound' => $o, 'affiliate' => (int)$r['affiliate_clicks'], 'sponsored' => (int)$r['sponsored_clicks'],
      'ratio' => $v > 0 ? round($o / $v, 4) : null, 'in_catalogue' => $c !== null];
  }
  return $out;
}

/** Filter, sort and cut. $sort: outbound|views|ratio|name. $limit: 10|20|50|100. */
function anz_rank(array $tools, string $sort, int $limit, string $q = '', string $category = ''): array {
  $q = mb_strtolower(trim($q));
  $f = array_values(array_filter($tools, fn($t) => ($q === '' || strpos(mb_strtolower($t['name'] . ' ' . $t['slug']), $q) !== false) && ($category === '' || $t['category'] === $category)));
  $cmp = ['outbound' => fn($a, $b) => [$b['outbound'], $b['views'], $a['name']] <=> [$a['outbound'], $a['views'], $b['name']], 'views' => fn($a, $b) => [$b['views'], $b['outbound'], $a['name']] <=> [$a['views'], $a['outbound'], $b['name']],
    'ratio' => fn($a, $b) => [$b['ratio'] ?? -1, $b['outbound'], $a['name']] <=> [$a['ratio'] ?? -1, $a['outbound'], $b['name']], 'name' => fn($a, $b) => strcasecmp($a['name'], $b['name'])][$sort] ?? null;
  if ($cmp === null) $cmp = fn($a, $b) => [$b['outbound'], $b['views'], $a['name']] <=> [$a['outbound'], $a['views'], $b['name']];
  usort($f, $cmp); return ['rows' => array_slice($f, 0, max(1, $limit)), 'matching' => count($f), 'total' => count($tools)];
}

/** Category performance from tool rows. share = category outbound / all outbound (of the rows given). */
function anz_categories(array $tools): array {
  $c = []; $all = 0; foreach ($tools as $t) $all += $t['outbound'];
  foreach ($tools as $t) { $k = $t['category']; $c[$k] = $c[$k] ?? ['category' => $k, 'tools' => 0, 'views' => 0, 'outbound' => 0, 'affiliate' => 0, 'sponsored' => 0]; $c[$k]['tools']++; $c[$k]['views'] += $t['views']; $c[$k]['outbound'] += $t['outbound']; $c[$k]['affiliate'] += $t['affiliate']; $c[$k]['sponsored'] += $t['sponsored']; }
  foreach ($c as &$x) { $x['ratio'] = $x['views'] > 0 ? round($x['outbound'] / $x['views'], 4) : null; $x['share'] = $all > 0 ? round($x['outbound'] / $all * 100, 1) : null; } unset($x);
  $c = array_values($c); usort($c, fn($a, $b) => [$b['outbound'], $b['views'], $a['category']] <=> [$a['outbound'], $a['views'], $b['category']]); return $c;
}

/** Percent change per tool against the previous equal period, only where the previous period has data for every day and the tool has a baseline. */
function anz_tool_changes(array $cur, array $prev, int $prevDaysWithData, int $prevSpan): array {
  $p = []; foreach ($prev as $r) $p[$r['tool_slug']] = (int)$r['outbound_clicks']; $out = [];
  foreach ($cur as $r) $out[$r['tool_slug']] = anz_growth((int)$r['outbound_clicks'], $p[$r['tool_slug']] ?? 0, $prevDaysWithData, $prevSpan, 'outbound clicks');
  return $out;
}

/**
 * Featured placement performance, descriptive only: outbound clicks and profile views for the tool during the placement window versus the equal-length window right before it.
 * Measurable only when the rollup covers every day of both windows; a placement still running is measured up to $today. Never causal.
 */
function anz_placement_perf(array $placements, callable $toolSums, array $coveredDays, string $today, array $catalogue): array {
  $out = [];
  foreach ($placements as $p) {
    $from = (string)$p['starts_on']; $to = min((string)$p['ends_on'], $today); $row = ['slug' => $p['tool_slug'], 'name' => $catalogue[$p['tool_slug']]['name'] ?? $p['tool_slug'], 'level' => (int)$p['level'], 'starts_on' => $from, 'ends_on' => (string)$p['ends_on'], 'status' => (string)$p['status'], 'measurable' => false, 'reason' => '', 'during' => null, 'before' => null, 'growth' => null];
    if ($p['status'] === 'cancelled') { $row['reason'] = 'Cancelled placement: not measured.'; $out[] = $row; continue; }
    if ($from > $today) { $row['reason'] = 'Not started yet.'; $out[] = $row; continue; }
    $span = anz_days_between($from, $to); $pf = anz_add_days($from, -$span); $pt = anz_add_days($from, -1);
    $need = array_merge(range(0, $span - 1), range(-$span, -1)); $missing = 0; foreach ($need as $i) if (!isset($coveredDays[anz_add_days($from, $i)])) $missing++;
    if ($missing > 0) { $row['reason'] = 'Not measurable: tracking data is missing for ' . $missing . ' of the ' . (2 * $span) . ' days needed (the placement window and the equal window before it).'; $out[] = $row; continue; }
    $d = $toolSums($p['tool_slug'], $from, $to); $b = $toolSums($p['tool_slug'], $pf, $pt);
    $row['measurable'] = true; $row['during'] = $d + ['days' => $span]; $row['before'] = $b + ['days' => $span, 'from' => $pf, 'to' => $pt]; $row['growth'] = anz_growth((int)$d['outbound'], (int)$b['outbound'], $span, $span, 'outbound clicks'); $out[] = $row;
  }
  return $out;
}

/** Monthly history from daily rows. */
function anz_months(array $days): array {
  $m = []; foreach ($days as $r) { $k = substr($r['day'], 0, 7); $m[$k] = $m[$k] ?? ['month' => $k, 'views' => 0, 'visitor_days' => 0, 'sessions' => 0, 'days' => 0]; $m[$k]['views'] += (int)$r['views_human']; $m[$k]['visitor_days'] += (int)$r['visitors']; $m[$k]['sessions'] += (int)$r['sessions']; $m[$k]['days']++; }
  ksort($m); return array_values($m);
}

/** Share rows for a dimension ("direct" keeps its caveat in the label). */
function anz_shares(array $rows): array { $t = 0; foreach ($rows as $r) $t += (int)$r['views']; $o = []; foreach ($rows as $r) $o[] = ['k' => $r['k'], 'views' => (int)$r['views'], 'visitor_days' => (int)$r['visitor_days'], 'share' => $t > 0 ? round($r['views'] / $t * 100, 1) : null]; return $o; }

function anz_referrer_label(string $k): string {
  static $m = ['direct' => 'Direct or unknown (no referrer sent)', 'internal' => 'Internal (from this site)', 'search_google' => 'Google search', 'search_bing' => 'Bing search', 'search_duckduckgo' => 'DuckDuckGo search', 'search_yahoo' => 'Yahoo search', 'search_brave' => 'Brave search', 'search_other' => 'Other search engines',
    'social' => 'Social media', 'other' => 'Other websites (referrals)', 'ai_chatgpt' => 'ChatGPT', 'ai_perplexity' => 'Perplexity', 'ai_claude' => 'Claude', 'ai_gemini' => 'Gemini', 'ai_copilot' => 'Copilot'];
  return $m[$k] ?? $k;
}
function anz_device_label(string $k): string { return ['mobile' => 'Mobile', 'tablet' => 'Tablet', 'desktop' => 'Desktop'][$k] ?? $k; }

/** In-memory store: the test double and the source for static previews (same semantics as the database store). */
final class AdmAnMemoryStore implements AdmAnStore {
  /** @param array $traffic day => [views_human, visitors, sessions, views_no_id]; $toolDaily day => slug => [views, outbound, affiliate, sponsored]; $dims dim => day => key => [views, visitor_days] */
  public function __construct(private array $status = [], private array $traffic = [], private array $toolDaily = [], private array $dims = [], private ?array $active = null, private ?array $placements = null, private ?array $legacy = null) {}
  public function status(): array { return $this->status + ['storage' => true, 'tracking' => true, 'first_day' => $this->traffic ? min(array_keys($this->traffic)) : null, 'last_day' => $this->traffic ? max(array_keys($this->traffic)) : null, 'last_run' => null, 'raw_rows' => (bool)$this->traffic, 'device_col' => isset($this->dims['device'])]; }
  public function traffic(string $from, string $to): array { $o = []; ksort($this->traffic); foreach ($this->traffic as $d => $r) if ($d >= $from && $d <= $to) $o[] = ['day' => $d, 'views_human' => $r[0], 'visitors' => $r[1], 'sessions' => $r[2], 'views_no_id' => $r[3] ?? 0]; return $o; }
  public function dim(string $dim, string $from, string $to, int $limit): array {
    $a = []; foreach (($this->dims[$dim] ?? []) as $d => $keys) if ($d >= $from && $d <= $to) foreach ($keys as $k => [$v, $u]) { $a[$k] = $a[$k] ?? ['k' => (string)$k, 'views' => 0, 'visitor_days' => 0]; $a[$k]['views'] += $v; $a[$k]['visitor_days'] += $u; }
    $a = array_values($a); usort($a, fn($x, $y) => [$y['views'], $x['k']] <=> [$x['views'], $y['k']]); return array_slice($a, 0, $limit);
  }
  public function tools(string $from, string $to): array {
    $a = []; foreach ($this->toolDaily as $d => $tools) if ($d >= $from && $d <= $to) foreach ($tools as $s => [$v, $c, $af, $sp]) { $a[$s] = $a[$s] ?? ['tool_slug' => (string)$s, 'tool_views' => 0, 'outbound_clicks' => 0, 'affiliate_clicks' => 0, 'sponsored_clicks' => 0]; $a[$s]['tool_views'] += $v; $a[$s]['outbound_clicks'] += $c; $a[$s]['affiliate_clicks'] += $af; $a[$s]['sponsored_clicks'] += $sp; }
    return array_values($a);
  }
  public function toolDays(string $from, string $to, ?string $slug = null): array {
    $o = []; ksort($this->toolDaily); foreach ($this->toolDaily as $d => $tools) if ($d >= $from && $d <= $to) { $v = 0; $c = 0; foreach ($tools as $s => $x) if ($slug === null || $s === $slug) { $v += $x[0]; $c += $x[1]; } $o[] = ['day' => $d, 'tool_views' => $v, 'outbound_clicks' => $c]; }
    return $o;
  }
  public function activeNow(): ?array { return $this->active; }
  public function placements(): ?array { return $this->placements; }
  public function legacyClicks(string $from, string $to): ?array { if ($this->legacy === null) return null; return array_values(array_filter($this->legacy, fn($r) => $r['day'] >= $from && $r['day'] <= $to)); }
}

/**
 * Legacy click records (tool_clicks, March 2026 onwards in production) summarised for display. These are the OLD lifetime counter rows: written before the single-count fix, not bot-filtered,
 * with no page-view or visitor meaning. "Probable duplicates" = a record from the same browser cookie and tool within 10 seconds of the previous one (the old double write). Never merged with new counts.
 * @return array{records:int,probable_duplicates:int,net:int,days:array,tools:array,first_day:?string,last_day:?string}
 */
function anz_legacy_summary(array $rows): array {
  $o = ['records' => 0, 'probable_duplicates' => 0, 'dup_known' => true, 'net' => 0, 'days' => [], 'tools' => [], 'first_day' => null, 'last_day' => null];
  foreach ($rows as $r) {
    $n = (int)$r['records']; $d = (int)$r['probable_duplicates']; if (array_key_exists('dup_known', $r) && !$r['dup_known']) $o['dup_known'] = false; $o['records'] += $n; $o['probable_duplicates'] += $d;
    $o['days'][$r['day']] = $o['days'][$r['day']] ?? ['day' => $r['day'], 'records' => 0, 'probable_duplicates' => 0]; $o['days'][$r['day']]['records'] += $n; $o['days'][$r['day']]['probable_duplicates'] += $d;
    $o['tools'][$r['tool_slug']] = $o['tools'][$r['tool_slug']] ?? ['slug' => $r['tool_slug'], 'records' => 0, 'probable_duplicates' => 0]; $o['tools'][$r['tool_slug']]['records'] += $n; $o['tools'][$r['tool_slug']]['probable_duplicates'] += $d;
  }
  ksort($o['days']); $o['days'] = array_values($o['days']); $o['tools'] = array_values($o['tools']); usort($o['tools'], fn($a, $b) => [$b['records'], $a['slug']] <=> [$a['records'], $b['slug']]);
  $o['net'] = $o['records'] - $o['probable_duplicates']; if ($o['days']) { $o['first_day'] = $o['days'][0]['day']; $o['last_day'] = $o['days'][count($o['days']) - 1]['day']; }
  return $o;
}
