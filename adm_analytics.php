<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
/**
 * Phase 8B analytics for the Control Center: rollup from the real tracking tables (page_views, events, CRM) into the five adm_* tables, date ranges, report queries,
 * safe CSV and rendering. Rules: only stored rows are used (nothing estimated or invented); incompatible metrics are never added together
 * (page views, visitor-days, approximate sessions, outbound click events and the lifetime tool_clicks popularity counter stay separate); a missing source is reported
 * as "not available", never as zero. Rollup SQL needs window functions (MySQL 8.0+ / MariaDB 10.2+).
 */
require_once __DIR__ . '/adm_core.php';

const ADMA_SESSION_GAP = 1800;      // seconds of inactivity that end an approximate session
const ADMA_MAX_DAYS = 366;
const ADMA_CONVERSIONS = ['lead_submit' => 'Inquiry forms submitted', 'newsletter_signup' => 'Newsletter sign-ups', 'newsletter_confirmed' => 'Newsletter confirmations', 'wizard_complete' => 'Recommendation wizard completed'];

/** True when a table exists (never throws). */
function adma_table(PDO $pdo, string $t): bool {
  try { $s = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'); $s->execute([$t]); return (bool)$s->fetchColumn(); } catch (Throwable $e) { return false; }
}
function adma_column(PDO $pdo, string $t, string $c): bool { try { $q = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'); $q->execute([$t, $c]); return (bool)$q->fetchColumn(); } catch (Throwable $e) { return false; } }
function adma_storage_ready(PDO $pdo): bool { foreach (['adm_traffic_daily', 'adm_traffic_dim_daily', 'adm_tool_daily', 'adm_event_daily', 'adm_rollup_run'] as $t) if (!adma_table($pdo, $t)) return false; return true; }

/* ===================================================== rollup (CLI and tests) ===================================================== */
/** Rebuilds one UTC day from the raw tables (delete + insert in one transaction: safe to repeat). @return int rows written */
function adma_rollup_day(PDO $pdo, string $day): int {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) throw new InvalidArgumentException('bad day');
  $hasPv = adma_table($pdo, 'page_views'); $hasEv = adma_table($pdo, 'events'); $n = 0;
  $pdo->beginTransaction();
  try {
    foreach (['adm_traffic_daily', 'adm_traffic_dim_daily', 'adm_tool_daily', 'adm_event_daily'] as $t) $pdo->prepare("DELETE FROM $t WHERE day = ?")->execute([$day]);
    $now = gmdate('Y-m-d H:i:s');
    if ($hasPv) {
      $q = $pdo->prepare("SELECT COALESCE(SUM(is_bot = 0 AND status < 400),0) vh, COALESCE(SUM(is_bot = 1),0) vb, COALESCE(SUM(is_bot = 0 AND status < 400 AND visitor_hash = ''),0) vn, COALESCE(SUM(is_bot = 0 AND status >= 400),0) ve,
        COUNT(DISTINCT CASE WHEN is_bot = 0 AND status < 400 AND visitor_hash <> '' THEN visitor_hash END) vis, COUNT(*) total FROM page_views WHERE day = ?");
      $q->execute([$day]); $r = $q->fetch(PDO::FETCH_ASSOC);
      if ((int)$r['total'] > 0) {
        $ss = $pdo->prepare("SELECT COUNT(*) FROM (SELECT viewed_at, LAG(viewed_at) OVER (PARTITION BY visitor_hash ORDER BY viewed_at, id) AS prev FROM page_views WHERE day = ? AND is_bot = 0 AND status < 400 AND visitor_hash <> '') t
          WHERE prev IS NULL OR TIMESTAMPDIFF(SECOND, prev, viewed_at) > " . ADMA_SESSION_GAP);
        $ss->execute([$day]); $sessions = (int)$ss->fetchColumn();
        $pdo->prepare('INSERT INTO adm_traffic_daily (day, views_human, views_bot, views_no_id, views_error, visitors, sessions, updated_at) VALUES (?,?,?,?,?,?,?,?)')->execute([$day, $r['vh'], $r['vb'], $r['vn'], $r['ve'], $r['vis'], $sessions, $now]); $n++;
        $dim = $pdo->prepare('INSERT INTO adm_traffic_dim_daily (day, dim, dim_key, views, visitors) VALUES (?,?,?,?,?)');
        $human = "day = ? AND is_bot = 0 AND status < 400";
        $dimQ = function (string $name, string $col, int $limit, string $extra = '') use ($pdo, $dim, $day, $human, &$n) {
          $s = $pdo->prepare("SELECT $col k, COUNT(*) v, COUNT(DISTINCT NULLIF(visitor_hash,'')) u FROM page_views WHERE $human $extra GROUP BY k ORDER BY v DESC, k LIMIT $limit"); $s->execute([$day]);
          foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $x) { $dim->execute([$day, $name, substr((string)$x['k'], 0, 190), $x['v'], $x['u']]); $n++; }
        };
        if (adma_column($pdo, 'page_views', 'device')) $dimQ('device', 'device', 10, " AND device <> ''");
        $dimQ('page', 'path', 300); $dimQ('referrer', 'referrer_class', 40); $dimQ('refhost', 'referrer_host', 50, " AND referrer_host <> ''"); $dimQ('country', 'country', 300); $dimQ('ptype', 'page_type', 60);
        $top = (int)$pdo->query("SELECT COALESCE(SUM(views),0) FROM adm_traffic_dim_daily WHERE day = " . $pdo->quote($day) . " AND dim = 'page'")->fetchColumn();
        if ((int)$r['vh'] > $top) { $dim->execute([$day, 'page', '(other pages)', (int)$r['vh'] - $top, 0]); $n++; }   // so the table always reconciles to the day's total
        $tv = $pdo->prepare("SELECT SUBSTRING_INDEX(SUBSTRING(path, 14), '&', 1) slug, COUNT(*) v FROM page_views WHERE $human AND page_type = 'tool' AND path LIKE '/tool.php?id=%' GROUP BY slug"); $tv->execute([$day]); $tool = [];
        foreach ($tv->fetchAll(PDO::FETCH_ASSOC) as $x) if ($x['slug'] !== '') $tool[substr(rawurldecode($x['slug']), 0, 120)] = ['v' => (int)$x['v'], 'c' => 0, 'a' => 0, 's' => 0];
      }
    }
    $tool = $tool ?? [];
    if ($hasEv) {
      $ec = $pdo->prepare("SELECT ref, COUNT(*) c, COALESCE(SUM(prop1 = 'yes'),0) a, COALESCE(SUM(prop2 = 'sponsored'),0) s FROM events WHERE day = ? AND name = 'outbound_click' AND prop2 IN ('sponsored','organic') AND ref <> '' GROUP BY ref"); $ec->execute([$day]);
      foreach ($ec->fetchAll(PDO::FETCH_ASSOC) as $x) { $k = substr((string)$x['ref'], 0, 120); $tool[$k] = ($tool[$k] ?? ['v' => 0]) + ['c' => 0, 'a' => 0, 's' => 0]; $tool[$k]['c'] = (int)$x['c']; $tool[$k]['a'] = (int)$x['a']; $tool[$k]['s'] = (int)$x['s']; }
      $es = $pdo->prepare("SELECT name, CASE WHEN name = 'outbound_click' THEN '' ELSE ref END r, COUNT(*) c, COUNT(DISTINCT NULLIF(visitor_hash,'')) v FROM events WHERE day = ? AND (name <> 'outbound_click' OR prop2 IN ('sponsored','organic')) GROUP BY name, r"); $es->execute([$day]);
      $ei = $pdo->prepare('INSERT INTO adm_event_daily (day, name, ref, events, visitors) VALUES (?,?,?,?,?)');
      foreach ($es->fetchAll(PDO::FETCH_ASSOC) as $x) { $ei->execute([$day, $x['name'], substr((string)$x['r'], 0, 80), $x['c'], $x['v']]); $n++; }
      if ($hasPv) {   // same-day, first-touch attribution through the shared rotating hash (visitors with no hash are '(no visitor id)')
        $names = array_keys(ADMA_CONVERSIONS); $in = implode(',', array_fill(0, count($names), '?'));
        $as = $pdo->prepare("SELECT e.name, CASE WHEN e.visitor_hash = '' THEN '(no visitor id)' ELSE COALESCE(f.referrer_class, '(no page view that day)') END k, COUNT(*) c, COUNT(DISTINCT NULLIF(e.visitor_hash,'')) v
          FROM events e LEFT JOIN (SELECT visitor_hash, referrer_class FROM (SELECT visitor_hash, referrer_class, ROW_NUMBER() OVER (PARTITION BY visitor_hash ORDER BY viewed_at, id) rn FROM page_views WHERE day = ? AND is_bot = 0 AND status < 400 AND visitor_hash <> '') z WHERE rn = 1) f ON f.visitor_hash = e.visitor_hash
          WHERE e.day = ? AND e.name IN ($in) GROUP BY e.name, k");
        $as->execute(array_merge([$day, $day], $names)); $di = $pdo->prepare('INSERT INTO adm_traffic_dim_daily (day, dim, dim_key, views, visitors) VALUES (?,?,?,?,?)');
        foreach ($as->fetchAll(PDO::FETCH_ASSOC) as $x) { $di->execute([$day, 'attr:' . $x['name'], $x['k'], $x['c'], $x['v']]); $n++; }
      }
    }
    $ti = $pdo->prepare('INSERT INTO adm_tool_daily (day, tool_slug, tool_views, outbound_clicks, affiliate_clicks, sponsored_clicks) VALUES (?,?,?,?,?,?)');
    foreach ($tool as $slug => $x) { $ti->execute([$day, $slug, $x['v'], $x['c'], $x['a'], $x['s']]); $n++; }
    $pdo->commit();
  } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
  return $n;
}

/** Rolls up a day range and writes a run row. @return array{rows:int,days:int} */
function adma_rollup_range(PDO $pdo, string $from, string $to, string $job = 'rollup'): array {
  $pdo->prepare("INSERT INTO adm_rollup_run (job, day_from, day_to, started_at, status) VALUES (?,?,?,?, 'running')")->execute([$job, $from, $to, gmdate('Y-m-d H:i:s')]); $id = (int)$pdo->lastInsertId();
  $rows = 0; $days = 0;
  try {
    for ($t = strtotime($from . ' UTC'); $t <= strtotime($to . ' UTC'); $t += 86400) { $rows += adma_rollup_day($pdo, gmdate('Y-m-d', $t)); $days++; }
    $pdo->prepare("UPDATE adm_rollup_run SET finished_at = ?, rows_written = ?, status = 'ok' WHERE id = ?")->execute([gmdate('Y-m-d H:i:s'), $rows, $id]);
  } catch (Throwable $e) { $pdo->prepare("UPDATE adm_rollup_run SET finished_at = ?, status = 'failed', note = ? WHERE id = ?")->execute([gmdate('Y-m-d H:i:s'), substr(get_class($e), 0, 100), $id]); throw $e; }
  return ['rows' => $rows, 'days' => $days];
}

/* ===================================================== ranges and availability ===================================================== */
/** @return array{from:string,to:string,key:string,label:string,error:?string} dates are UTC days; custom ranges are validated (real dates, from <= to, at most 366 days, not in the future). */
function adma_range(array $get, ?int $now = null): array {
  $now = $now ?? time(); $today = gmdate('Y-m-d', $now); $key = is_string($get['range'] ?? null) ? $get['range'] : '7d';
  $mk = fn(string $f, string $t, string $k, string $l, ?string $e = null) => ['from' => $f, 'to' => $t, 'key' => $k, 'label' => $l, 'error' => $e];
  switch ($key) {
    case 'today': return $mk($today, $today, 'today', 'Today (UTC)');
    case 'yesterday': $y = gmdate('Y-m-d', $now - 86400); return $mk($y, $y, 'yesterday', 'Yesterday (UTC)');
    case '30d': return $mk(gmdate('Y-m-d', $now - 29 * 86400), $today, '30d', 'Last 30 days');
    case 'custom':
      $f = is_string($get['from'] ?? null) ? $get['from'] : ''; $t = is_string($get['to'] ?? null) ? $get['to'] : ''; $ok = fn(string $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4));
      if (!$ok($f) || !$ok($t)) return $mk(gmdate('Y-m-d', $now - 6 * 86400), $today, '7d', 'Last 7 days', 'Custom dates must be real dates in the form YYYY-MM-DD. Showing the last 7 days instead.');
      if ($f > $t || $t > $today || (strtotime($t . ' UTC') - strtotime($f . ' UTC')) / 86400 >= ADMA_MAX_DAYS) return $mk(gmdate('Y-m-d', $now - 6 * 86400), $today, '7d', 'Last 7 days', 'The custom range must end today or earlier, start before it ends, and span at most ' . ADMA_MAX_DAYS . ' days. Showing the last 7 days instead.');
      return $mk($f, $t, 'custom', $f . ' to ' . $t);
    default: return $mk(gmdate('Y-m-d', $now - 6 * 86400), $today, '7d', 'Last 7 days');
  }
}

/** Where the data stands: installed? first tracked day? last rollup? raw rows present? */
function adma_status(PDO $pdo): array {
  $s = ['storage' => adma_storage_ready($pdo), 'raw_page_views' => adma_table($pdo, 'page_views'), 'raw_events' => adma_table($pdo, 'events'), 'enabled' => function_exists('analytics_enabled') && analytics_enabled(), 'first_day' => null, 'last_run' => null, 'raw_rows' => 0];
  if ($s['raw_page_views']) { try { $s['raw_rows'] = (int)$pdo->query('SELECT COUNT(*) FROM (SELECT 1 FROM page_views LIMIT 1) t')->fetchColumn(); } catch (Throwable $e) {} }
  if ($s['storage']) {
    $s['first_day'] = $pdo->query('SELECT MIN(day) FROM adm_traffic_daily')->fetchColumn() ?: null;
    $s['last_run'] = $pdo->query("SELECT MAX(finished_at) FROM adm_rollup_run WHERE status = 'ok'")->fetchColumn() ?: null;
  }
  return $s;
}

/* ===================================================== report queries (rollup tables only; bound parameters) ===================================================== */
function adma_summary(PDO $pdo, string $from, string $to): array {
  $s = $pdo->prepare('SELECT day, views_human, views_bot, views_no_id, views_error, visitors, sessions FROM adm_traffic_daily WHERE day BETWEEN ? AND ? ORDER BY day'); $s->execute([$from, $to]);
  $rows = $s->fetchAll(PDO::FETCH_ASSOC); $t = ['views_human' => 0, 'views_bot' => 0, 'views_no_id' => 0, 'views_error' => 0, 'visitor_days' => 0, 'sessions' => 0];
  foreach ($rows as $r) { $t['views_human'] += (int)$r['views_human']; $t['views_bot'] += (int)$r['views_bot']; $t['views_no_id'] += (int)$r['views_no_id']; $t['views_error'] += (int)$r['views_error']; $t['visitor_days'] += (int)$r['visitors']; $t['sessions'] += (int)$r['sessions']; }
  $span = (int)((strtotime($to . ' UTC') - strtotime($from . ' UTC')) / 86400) + 1;
  return ['days' => $rows, 'totals' => $t, 'days_with_data' => count($rows), 'span' => $span];
}
function adma_dim(PDO $pdo, string $dim, string $from, string $to, int $limit = 200): array {
  $s = $pdo->prepare('SELECT dim_key k, SUM(views) views, SUM(visitors) visitor_days FROM adm_traffic_dim_daily WHERE dim = ? AND day BETWEEN ? AND ? GROUP BY dim_key ORDER BY views DESC, dim_key LIMIT ' . (int)$limit);
  $s->execute([$dim, $from, $to]); return $s->fetchAll(PDO::FETCH_ASSOC);
}
function adma_tools(PDO $pdo, string $from, string $to, int $limit = 200): array {
  $s = $pdo->prepare('SELECT tool_slug, SUM(tool_views) tool_views, SUM(outbound_clicks) outbound_clicks, SUM(affiliate_clicks) affiliate_clicks, SUM(sponsored_clicks) sponsored_clicks FROM adm_tool_daily WHERE day BETWEEN ? AND ? GROUP BY tool_slug ORDER BY outbound_clicks DESC, tool_views DESC, tool_slug LIMIT ' . (int)$limit);
  $s->execute([$from, $to]); return $s->fetchAll(PDO::FETCH_ASSOC);
}
function adma_events(PDO $pdo, string $from, string $to): array {
  $s = $pdo->prepare('SELECT name, SUM(events) events, SUM(visitors) visitor_days FROM adm_event_daily WHERE day BETWEEN ? AND ? GROUP BY name'); $s->execute([$from, $to]); return $s->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
}
/** Lifetime popularity counter (tool_clicks): NOT date-filtered, includes clicks counted before bot filtering existed, never added to the event-based numbers. @return ?array null = table missing */
function adma_lifetime_clicks(PDO $pdo, int $limit = 50): ?array {
  if (!adma_table($pdo, 'tool_clicks')) return null;
  try { return $pdo->query('SELECT tool_slug, COUNT(*) clicks FROM tool_clicks GROUP BY tool_slug ORDER BY clicks DESC, tool_slug LIMIT ' . (int)$limit)->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return null; }
}
/** CRM inquiries created in the range by kind (real CRM rows; a different source from the tracking events). @return ?array */
function adma_crm_leads(PDO $pdo, string $from, string $to): ?array {
  try { $s = $pdo->prepare('SELECT kind, COUNT(*) n FROM crm_leads WHERE created_at >= ? AND created_at < ? GROUP BY kind ORDER BY n DESC'); $s->execute([$from . ' 00:00:00', gmdate('Y-m-d', strtotime($to . ' UTC') + 86400) . ' 00:00:00']); return $s->fetchAll(PDO::FETCH_KEY_PAIR); } catch (Throwable $e) { return null; }
}
/** Payments recorded in the CRM on one day, in the reporting currency only. @return ?int minor units, null = none recorded or CRM unavailable */
function adma_revenue_received(PDO $pdo, string $day): ?int {
  try { $s = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(amount_minor),0) FROM crm_revenue_entries WHERE kind = 'received' AND currency = ? AND entry_date = ?"); $s->execute([ADM_REPORT_CURRENCY, $day]); [$c, $sum] = $s->fetch(PDO::FETCH_NUM); return (int)$c > 0 ? (int)$sum : null; } catch (Throwable $e) { return null; }
}

/* ===================================================== CSV (formula-injection safe) ===================================================== */
function adma_csv_cell($v): string {
  $s = (string)$v; if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) $s = "'" . $s;   // a spreadsheet must never evaluate data as a formula
  return '"' . str_replace('"', '""', str_replace(["\r\n", "\r", "\n"], ' ', $s)) . '"';
}
function adma_csv(array $header, array $rows): string {
  $o = "\xEF\xBB\xBF" . implode(',', array_map('adma_csv_cell', $header)) . "\r\n";
  foreach ($rows as $r) $o .= implode(',', array_map('adma_csv_cell', $r)) . "\r\n";
  return $o;
}
/** @return ?array [filename, csv] */
function adma_export(PDO $pdo, string $which, array $range): ?array {
  [$f, $t] = [$range['from'], $range['to']]; $name = "aihubpedia-$which-$f-to-$t.csv";
  switch ($which) {
    case 'daily': $s = adma_summary($pdo, $f, $t); return [$name, adma_csv(['date_utc', 'page_views_human', 'bot_views_not_counted', 'views_without_visitor_id', 'error_views', 'daily_unique_visitors', 'approx_sessions'], array_map(fn($r) => [$r['day'], $r['views_human'], $r['views_bot'], $r['views_no_id'], $r['views_error'], $r['visitors'], $r['sessions']], $s['days']))];
    case 'pages': return [$name, adma_csv(['page', 'page_views', 'visitor_days'], array_map(fn($r) => [$r['k'], $r['views'], $r['visitor_days']], adma_dim($pdo, 'page', $f, $t, 5000)))];
    case 'referrers': return [$name, adma_csv(['referrer_class', 'page_views', 'visitor_days'], array_map(fn($r) => [$r['k'], $r['views'], $r['visitor_days']], adma_dim($pdo, 'referrer', $f, $t, 500)))];
    case 'countries': return [$name, adma_csv(['country', 'page_views', 'visitor_days'], array_map(fn($r) => [$r['k'] === 'XX' ? 'Unknown' : $r['k'], $r['views'], $r['visitor_days']], adma_dim($pdo, 'country', $f, $t, 500)))];
    case 'tools': return [$name, adma_csv(['tool', 'tool_detail_page_views', 'outbound_click_events', 'affiliate_outbound_clicks', 'sponsored_outbound_clicks'], array_map(fn($r) => [$r['tool_slug'], $r['tool_views'], $r['outbound_clicks'], $r['affiliate_clicks'], $r['sponsored_clicks']], adma_tools($pdo, $f, $t, 5000)))];
    case 'conversions': $e = adma_events($pdo, $f, $t); $rows = []; foreach (ADMA_CONVERSIONS as $k => $l) $rows[] = [$k, $l, $e[$k]['events'] ?? 0, $e[$k]['visitor_days'] ?? 0]; return [$name, adma_csv(['event', 'description', 'events', 'visitor_days'], $rows)];
  }
  return null;
}

/* ===================================================== rendering ===================================================== */
function adma_url(array $range, array $extra = []): string {
  $q = ['m' => 'analytics', 'range' => $range['key']]; if ($range['key'] === 'custom') { $q['from'] = $range['from']; $q['to'] = $range['to']; }
  foreach ($extra as $k => $v) if ($v !== null) $q[$k] = $v;
  return '/admin-center.php?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
}
/** Sorts rows by a whitelisted column. @return array{0:array,1:string,2:string} [rows, column, direction] */
function adma_sort(array $rows, array $cols, string $table, array $get, string $defaultCol): array {
  $col = $defaultCol; $dir = 'desc';
  if (isset($get['sort']) && is_string($get['sort']) && preg_match('/^([a-z]+):([a-z_]+)$/', $get['sort'], $m) && $m[1] === $table && in_array($m[2], $cols, true)) { $col = $m[2]; $dir = (($get['dir'] ?? '') === 'asc') ? 'asc' : 'desc'; }
  usort($rows, function ($a, $b) use ($col, $dir) { $x = $a[$col] ?? ''; $y = $b[$col] ?? ''; $c = (is_numeric($x) && is_numeric($y)) ? ($x <=> $y) : strcasecmp((string)$x, (string)$y); return $dir === 'asc' ? $c : -$c; });
  return [$rows, $col, $dir];
}
function adma_th(array $range, array $get, string $table, string $col, string $label, string $curCol, string $curDir): string {
  $isCur = $col === $curCol; $next = ($isCur && $curDir === 'desc') ? 'asc' : 'desc';
  return '<th scope="col"' . ($isCur ? ' aria-sort="' . ($curDir === 'asc' ? 'ascending' : 'descending') . '"' : '') . '><a href="' . adm_h(adma_url($range, ['sort' => "$table:$col", 'dir' => $next])) . '#t-' . adm_h($table) . '">' . adm_h($label) . '</a></th>';
}
/** Inline SVG line chart with a text alternative (no libraries). Series: [label => [date => value]]. */
function adma_chart(array $days, array $series, string $title): string {
  if (count($days) < 1) return '';
  $w = 640; $h = 180; $pad = 30; $max = 1; foreach ($series as $pts) foreach ($pts as $v) $max = max($max, (int)$v);
  $n = count($days); $x = fn(int $i) => $n > 1 ? $pad + ($w - 2 * $pad) * $i / ($n - 1) : $w / 2; $y = fn(int $v) => $h - $pad - ($h - 2 * $pad) * $v / $max;
  $cols = ['var(--brand)', 'var(--ok)']; $o = '<svg role="img" aria-labelledby="chart-t" viewBox="0 0 ' . $w . ' ' . $h . '" style="width:100%;height:auto;max-height:240px"><title id="chart-t">' . adm_h($title) . '</title>';
  $o .= '<line x1="' . $pad . '" y1="' . ($h - $pad) . '" x2="' . ($w - $pad) . '" y2="' . ($h - $pad) . '" stroke="var(--line)"/><text x="' . $pad . '" y="14" font-size="11" fill="var(--muted)">max ' . adm_n($max) . '</text>';
  $o .= '<text x="' . $pad . '" y="' . ($h - 8) . '" font-size="11" fill="var(--muted)">' . adm_h($days[0]) . '</text><text x="' . ($w - $pad) . '" y="' . ($h - 8) . '" font-size="11" text-anchor="end" fill="var(--muted)">' . adm_h($days[$n - 1]) . '</text>';
  $k = 0; foreach ($series as $label => $pts) { $p = []; foreach ($days as $i => $d) $p[] = round($x($i), 1) . ',' . round($y((int)($pts[$d] ?? 0)), 1); $o .= '<polyline fill="none" stroke="' . $cols[$k % 2] . '" stroke-width="2.5" points="' . implode(' ', $p) . '"><title>' . adm_h($label) . '</title></polyline>'; $k++; }
  $o .= '</svg><p class="small" style="margin:.2rem 0 0;color:var(--muted)">';
  $k = 0; foreach (array_keys($series) as $label) { $o .= '<span style="color:' . $cols[$k % 2] . ';font-weight:700">&#9632;</span> ' . adm_h($label) . ' &nbsp; '; $k++; }
  return $o . '</p>';
}
function adma_table_html(string $id, string $caption, array $heads, array $rows, string $empty): string {
  $o = '<section class="panel" id="t-' . adm_h($id) . '" aria-labelledby="h-' . adm_h($id) . '"><h3 id="h-' . adm_h($id) . '" style="margin:.1rem 0 .5rem;font-size:1.05rem">' . adm_h($caption) . '</h3>';
  if (!$rows) return $o . '<p class="small" style="color:var(--muted)">' . adm_h($empty) . '</p></section>';
  $o .= '<div class="scroll" role="region" tabindex="0" aria-label="' . adm_h($caption) . '"><table><thead><tr>' . implode('', $heads) . '</tr></thead><tbody>';
  foreach ($rows as $r) { $o .= '<tr>'; foreach ($r as $i => $c) $o .= $i === 0 ? '<th scope="row">' . adm_h($c) . '</th>' : '<td>' . adm_h($c) . '</td>'; $o .= '</tr>'; }
  return $o . '</tbody></table></div></section>';
}

/** Whole analytics page body. @param array $get the request query */
function adma_body(PDO $pdo, array $get): string {
  $range = adma_range($get); $st = adma_status($pdo); $a = fn(string $k, string $l) => '<a class="admBtn" style="display:inline-flex;align-items:center;text-decoration:none"' . ($range['key'] === $k ? ' aria-current="true"' : '') . ' href="' . adm_h(adma_url(['key' => $k, 'from' => $range['from'], 'to' => $range['to']])) . '">' . adm_h($l) . '</a>';
  $o = '<h1>Analytics</h1><p class="lead">First-party, cookie-free measurement from the site\'s own records. All dates are UTC days. Nothing is estimated or back-filled: where tracking data does not exist the report says so.</p>';
  $o .= '<nav class="panel" aria-label="Date range"><div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">' . $a('today', 'Today') . $a('yesterday', 'Yesterday') . $a('7d', 'Last 7 days') . $a('30d', 'Last 30 days')
    . '<form method="get" action="/admin-center.php" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0"><input type="hidden" name="m" value="analytics"><input type="hidden" name="range" value="custom">'
    . '<label for="adma-from">From</label><input class="admBtn" id="adma-from" name="from" type="date" value="' . adm_h($range['from']) . '" max="' . gmdate('Y-m-d') . '"><label for="adma-to">To</label><input class="admBtn" id="adma-to" name="to" type="date" value="' . adm_h($range['to']) . '" max="' . gmdate('Y-m-d') . '"><button class="admBtn" type="submit">Apply custom range</button></form></div>'
    . '<p class="small" style="margin:.6rem 0 0">Showing <strong>' . adm_h($range['label']) . '</strong> (' . adm_h($range['from']) . ' to ' . adm_h($range['to']) . ').</p>' . ($range['error'] ? '<p role="alert" style="color:var(--na);margin:.4rem 0 0">' . adm_h($range['error']) . '</p>' : '') . '</nav>';
  if (!$st['storage']) return $o . '<section class="panel"><h2 style="margin-top:0">Analytics storage is not installed</h2><p>The five rollup tables from <code>database/phase8b_analytics.sql</code> have not been applied on this server, so no report can be shown. This is "not available", not zero activity. An administrator applies the migration after review and schedules <code>php scripts/adm_rollup.php</code>.</p></section>';
  if ($st['first_day'] === null) {
    $why = !$st['raw_page_views'] ? 'The <code>page_views</code> table does not exist on this server.' : (!$st['enabled'] && $st['raw_rows'] === 0 ? 'Visitor tracking is not enabled on this server (<code>AIHUB_ANALYTICS</code>) and no page views have been recorded.' : ($st['raw_rows'] === 0 ? 'Tracking is enabled but no page view has been recorded yet.' : 'Page views exist but the rollup has not run yet. Run <code>php scripts/adm_rollup.php --since-first</code>.'));
    return $o . '<section class="panel"><h2 style="margin-top:0">No tracking data yet</h2><p>' . $why . ' This is "not available", not zero visitors.</p></section>';
  }
  $sum = adma_summary($pdo, $range['from'], $range['to']); $t = $sum['totals'];
  if ($range['to'] < $st['first_day']) return $o . '<section class="panel"><h2 style="margin-top:0">No tracking data for this range</h2><p>Tracking data starts on <strong>' . adm_h($st['first_day']) . '</strong>. Earlier dates are unavailable, not zero.</p></section>';
  $o .= '<p class="small" style="color:var(--muted)">Tracking data starts on ' . adm_h($st['first_day']) . '. Last rollup: ' . adm_h((string)$st['last_run']) . ' UTC. ' . ($range['from'] < $st['first_day'] ? '<strong>Days before the start are unavailable, not zero.</strong> ' : '') . 'Days in range with recorded tracking: ' . $sum['days_with_data'] . ' of ' . $sum['span'] . '. A day without rows is shown as 0 on the assumption that tracking stayed on.</p>';
  $single = $range['from'] === $range['to'];
  $o .= adm_tiles_html([
    adm_tile('Page views (human)', adm_n($t['views_human']), 'Non-bot requests that returned a page (status below 400).'),
    adm_tile($single ? 'Unique visitors (that day)' : 'Visitor-days', adm_n($t['visitor_days']), $single ? 'Distinct daily visitor hashes. The hash rotates daily and uses no cookie.' : 'Sum of daily unique visitors. NOT the number of different people: the hash rotates every day.'),
    adm_tile('Sessions (approximate)', adm_n($t['sessions']), 'A daily visitor\'s views split by more than 30 minutes of inactivity.'),
    adm_tile('Bot views (not counted)', adm_n($t['views_bot']), 'Identified by user agent (a heuristic). Excluded from every figure above.'),
    adm_tile('Views without visitor id', adm_n($t['views_no_id']), 'Do-Not-Track or Global Privacy Control visitors: counted as views, never as visitors.'),
  ]);
  $series = ['Page views (human)' => [], 'Daily unique visitors' => []]; $dates = [];
  foreach ($sum['days'] as $d) { $dates[] = $d['day']; $series['Page views (human)'][$d['day']] = (int)$d['views_human']; $series['Daily unique visitors'][$d['day']] = (int)$d['visitors']; }
  if (count($dates) > 1) $o .= '<section class="panel"><h2 style="margin-top:0;font-size:1.1rem">Trend</h2>' . adma_chart($dates, $series, 'Daily page views and daily unique visitors') . '<details style="margin-top:8px"><summary>Show the daily numbers</summary><div class="scroll" role="region" tabindex="0" aria-label="Daily numbers"><table><thead><tr><th scope="col">Date (UTC)</th><th scope="col">Page views</th><th scope="col">Unique visitors</th><th scope="col">Sessions (approx.)</th></tr></thead><tbody>'
    . implode('', array_map(fn($d) => '<tr><th scope="row">' . adm_h($d['day']) . '</th><td>' . adm_n((int)$d['views_human']) . '</td><td>' . adm_n((int)$d['visitors']) . '</td><td>' . adm_n((int)$d['sessions']) . '</td></tr>', $sum['days'])) . '</tbody></table></div></details></section>';
  // pages
  [$pg, $pc, $pd] = adma_sort(adma_dim($pdo, 'page', $range['from'], $range['to']), ['k', 'views', 'visitor_days'], 'pages', $get, 'views');
  $o .= adma_table_html('pages', 'Top pages', [adma_th($range, $get, 'pages', 'k', 'Page', $pc, $pd), adma_th($range, $get, 'pages', 'views', 'Page views', $pc, $pd), adma_th($range, $get, 'pages', 'visitor_days', 'Visitor-days', $pc, $pd)], array_map(fn($r) => [$r['k'], adm_n((int)$r['views']), adm_n((int)$r['visitor_days'])], array_slice($pg, 0, 50)), 'No page views in this range.');
  // sources
  $ref = array_values(array_filter(adma_dim($pdo, 'referrer', $range['from'], $range['to']), fn($r) => $r['k'] !== 'internal')); [$rf, $rc, $rd] = adma_sort($ref, ['k', 'views', 'visitor_days'], 'sources', $get, 'views');
  $o .= adma_table_html('sources', 'Traffic sources (referrer class; internal navigation excluded)', [adma_th($range, $get, 'sources', 'k', 'Source', $rc, $rd), adma_th($range, $get, 'sources', 'views', 'Page views', $rc, $rd), adma_th($range, $get, 'sources', 'visitor_days', 'Visitor-days', $rc, $rd)], array_map(fn($r) => [$r['k'], adm_n((int)$r['views']), adm_n((int)$r['visitor_days'])], $rf), 'No external referrals in this range.');
  // countries (only when a CDN country header was present for at least some views)
  $co = adma_dim($pdo, 'country', $range['from'], $range['to']); $known = 0; $all = 0; foreach ($co as $r) { $all += (int)$r['views']; if ($r['k'] !== 'XX') $known += (int)$r['views']; }
  if ($all > 0 && $known === 0) $o .= '<section class="panel"><h3 style="margin:.1rem 0 .4rem;font-size:1.05rem">Countries</h3><p class="small" style="color:var(--muted);margin:0">Not available: no country information was recorded for this range (it depends on a CDN or proxy country header). Unknown location is never guessed.</p></section>';
  elseif ($all > 0) { [$cs, $cc, $cd] = adma_sort(array_map(fn($r) => ['k' => $r['k'] === 'XX' ? 'Unknown' : $r['k']] + $r, $co), ['k', 'views', 'visitor_days'], 'countries', $get, 'views');
    $o .= adma_table_html('countries', 'Countries (' . round($known / $all * 100) . '% of views have a known country)', [adma_th($range, $get, 'countries', 'k', 'Country', $cc, $cd), adma_th($range, $get, 'countries', 'views', 'Page views', $cc, $cd), adma_th($range, $get, 'countries', 'visitor_days', 'Visitor-days', $cc, $cd)], array_map(fn($r) => [$r['k'], adm_n((int)$r['views']), adm_n((int)$r['visitor_days'])], array_slice($cs, 0, 30)), ''); }
  // tools: three separate measures, no click-through rate
  [$tl, $tc, $td] = adma_sort(adma_tools($pdo, $range['from'], $range['to']), ['tool_slug', 'tool_views', 'outbound_clicks', 'affiliate_clicks', 'sponsored_clicks'], 'tools', $get, 'outbound_clicks');
  $o .= '<h2 class="sec" style="font-size:1.15rem;margin:1.4rem 0 .2rem">AI tools</h2><p class="small" style="color:var(--muted);margin:0 0 .5rem">Three different measures that are kept apart: tool-detail page views (tracking), outbound click events (server-side redirects, bots filtered only for clicks recorded after that filter was deployed) and, below, the lifetime popularity counter. Clicks on listing cards are not tracked, so <strong>no click-through rate is calculated</strong>.</p>';
  $o .= adma_table_html('tools', 'Tools in this range', [adma_th($range, $get, 'tools', 'tool_slug', 'Tool', $tc, $td), adma_th($range, $get, 'tools', 'tool_views', 'Tool-detail page views', $tc, $td), adma_th($range, $get, 'tools', 'outbound_clicks', 'Outbound click events', $tc, $td), adma_th($range, $get, 'tools', 'affiliate_clicks', 'of which affiliate', $tc, $td), adma_th($range, $get, 'tools', 'sponsored_clicks', 'of which sponsored placement', $tc, $td)],
    array_map(fn($r) => [$r['tool_slug'], adm_n((int)$r['tool_views']), adm_n((int)$r['outbound_clicks']), adm_n((int)$r['affiliate_clicks']), adm_n((int)$r['sponsored_clicks'])], array_slice($tl, 0, 50)), 'No tool page views or outbound click events in this range. Listing-card clicks: not tracked.');
  $life = adma_lifetime_clicks($pdo);
  $o .= $life === null ? '<section class="panel"><h3 style="margin:.1rem 0 .4rem;font-size:1.05rem">Lifetime tool popularity counter</h3><p class="small" style="margin:0;color:var(--muted)">Not available: the <code>tool_clicks</code> table could not be read.</p></section>'
    : adma_table_html('lifetime', 'Lifetime tool popularity counter (all dates, not affected by the date range; includes clicks counted before bot filtering)', ['<th scope="col">Tool</th>', '<th scope="col">Lifetime clicks</th>'], array_map(fn($r) => [$r['tool_slug'], adm_n((int)$r['clicks'])], $life), 'No clicks counted yet.');
  // conversions
  $ev = adma_events($pdo, $range['from'], $range['to']); $crm = adma_crm_leads($pdo, $range['from'], $range['to']); $rows = [];
  foreach (ADMA_CONVERSIONS as $k => $l) $rows[] = [$l, adm_n((int)($ev[$k]['events'] ?? 0)), adm_n((int)($ev[$k]['visitor_days'] ?? 0))];
  $o .= '<h2 class="sec" style="font-size:1.15rem;margin:1.4rem 0 .2rem">Conversions</h2><p class="small" style="color:var(--muted);margin:0 0 .5rem">Tracked events and CRM records are different sources and are shown apart. There is no member registration feature, so there is no registration metric.</p>'
    . adma_table_html('conv', 'Tracked conversion events', ['<th scope="col">Event</th>', '<th scope="col">Events</th>', '<th scope="col">Visitor-days</th>'], $rows, '');
  $o .= $crm === null ? '<section class="panel"><p class="small" style="margin:0;color:var(--muted)">CRM inquiries: not available (the CRM could not be read).</p></section>'
    : adma_table_html('crm', 'New CRM inquiries by kind (CRM records, created in the range)', ['<th scope="col">Kind</th>', '<th scope="col">Inquiries</th>'], array_map(fn($k, $n) => [$k, adm_n((int)$n)], array_keys($crm), array_values($crm)), 'No CRM inquiries were created in this range.');
  $attr = []; foreach (array_keys(ADMA_CONVERSIONS) as $k) foreach (adma_dim($pdo, 'attr:' . $k, $range['from'], $range['to'], 50) as $r) $attr[] = [ADMA_CONVERSIONS[$k], $r['k'], adm_n((int)$r['views'])];
  $o .= adma_table_html('attr', 'Attribution of tracked conversions (same-day, first-touch referrer class of the same daily visitor hash)', ['<th scope="col">Event</th>', '<th scope="col">First referrer class that day</th>', '<th scope="col">Events</th>'], $attr, 'No attributable conversions in this range. Visitors with Do-Not-Track or without a page view that day appear as such.');
  // exports
  $o .= '<section class="panel" aria-labelledby="h-exp"><h2 id="h-exp" style="margin:.1rem 0 .5rem;font-size:1.05rem">CSV exports (current range)</h2><p class="small" style="color:var(--muted)">Plain data, UTF-8. Cells that could be read as spreadsheet formulas are neutralised. Each download is recorded in the audit log.</p><div style="display:flex;flex-wrap:wrap;gap:8px">';
  foreach (['daily' => 'Daily totals', 'pages' => 'Pages', 'referrers' => 'Sources', 'countries' => 'Countries', 'tools' => 'Tools', 'conversions' => 'Conversions'] as $k => $l) $o .= '<a class="admBtn" style="display:inline-flex;align-items:center;text-decoration:none" href="' . adm_h(adma_url($range, ['export' => $k])) . '">' . adm_h($l) . '</a>';
  return $o . '</div></section>';
}

/** Overview tiles for the Control Center home (last 7 days), honest when storage or data is missing. */
function adma_overview_tiles(PDO $pdo): array {
  $st = adma_status($pdo);
  if (!$st['storage']) return [adm_tile('Visitors and page views', null, 'Not available yet: the analytics storage is not installed on this server (Phase 8B migration).', 'na')];
  if ($st['first_day'] === null) return [adm_tile('Visitors and page views', null, 'No tracking data yet: tracking is ' . ($st['enabled'] ? 'enabled but nothing is recorded yet.' : 'not enabled on this server.'), 'na')];
  $r = adma_range(['range' => '7d']); $s = adma_summary($pdo, $r['from'], $r['to']); $t = $s['totals']; $tools = adma_events($pdo, $r['from'], $r['to']);
  $oc = adma_tools($pdo, $r['from'], $r['to'], 5000); $clicks = 0; foreach ($oc as $x) $clicks += (int)$x['outbound_clicks'];
  return [adm_tile('Visitors and page views', adm_n($t['views_human']) . ' views', 'Last 7 days: ' . adm_n($t['visitor_days']) . ' visitor-days (daily uniques added up, not people). Tracking data starts ' . $st['first_day'] . '. Details in Analytics.'),
          adm_tile('Outbound AI tool clicks', adm_n($clicks), 'Last 7 days, server-side outbound click events (bots filtered only for newer events).')];
}
