<?php
/**
 * AI Activity Heatmap (Phase 7): a contribution-style calendar of VERIFIED AI events (releases, launches, updates, pricing changes, announcements).
 * Server-rendered; JavaScript only enhances it. Data: uni_public_* only (verified + current rows of verified + current entities).
 * Days without verified activity are drawn empty (never filled). Events dated only to a month or a year are listed separately and never drawn on a single day.
 */
require_once __DIR__ . '/uni_pages.php';

const UH_SCRIPT = 'universe-heatmap.php';

/** The page model: filters, period, counts, coarse-dated events, day panel. @return array with 'status' and either 'error' or the data */
function uh_model(PDO $pdo, array $P): array {
  $m = ['status' => 200, 'any' => false];
  $latest = uni_public_events($pdo, [], 1, 1);
  [$m['entities'], $m['entities_truncated']] = up_entities_all($pdo);
  if ($latest['total'] < 1) return $m;
  $m['any'] = true; $lastDate = (string)$latest['items'][0]['event_date'];
  $view = $P['view'] ?? 'year'; if (!in_array($view, ['year', 'month', 'week'], true)) return ['status' => 400, 'error' => 'parameter_invalid'] + $m;
  $entity = null; $cat = null; $from = null; $to = null; $day = null; $year = null; $month = null; $week = null;
  if (isset($P['entity'])) {
    if (!up_slug_ok($P['entity'])) return ['status' => 400, 'error' => 'parameter_invalid'] + $m;
    if (uni_public_entity($pdo, $P['entity']) === null) return ['status' => 404, 'error' => 'entity_not_found'] + $m;
    $entity = $P['entity'];
  }
  if (isset($P['category'])) { if (!in_array($P['category'], uni_event_types(), true)) return ['status' => 400, 'error' => 'parameter_invalid'] + $m; $cat = $P['category']; }
  foreach (['from' => &$from, 'to' => &$to, 'day' => &$day, 'week' => &$week] as $k => &$ref) {
    if (isset($P[$k])) { $ref = up_day($P[$k]); if ($ref === null) return ['status' => 400, 'error' => 'date_invalid'] + $m; }
  }
  unset($ref);
  if (isset($P['year'])) { $year = up_year($P['year']); if ($year === null) return ['status' => 400, 'error' => 'date_invalid'] + $m; }
  if (isset($P['month'])) { $month = up_month($P['month']); if ($month === null) return ['status' => 400, 'error' => 'date_invalid'] + $m; }
  if ($from !== null && $to !== null && $from > $to) return ['status' => 400, 'error' => 'range_invalid'] + $m;

  if ($view === 'year') {
    $y = $year ?? ($day !== null ? (int)substr($day, 0, 4) : ($month !== null ? (int)substr($month, 0, 4) : ($week !== null ? (int)substr($week, 0, 4) : (int)substr($lastDate, 0, 4))));
    $pFrom = sprintf('%04d-01-01', $y); $pTo = sprintf('%04d-12-31', $y); $key = ['year' => (string)$y];
  } elseif ($view === 'month') {
    $mo = $month ?? ($day !== null ? substr($day, 0, 7) : ($week !== null ? substr($week, 0, 7) : ($year !== null ? sprintf('%04d-01', $year) : substr($lastDate, 0, 7))));
    $pFrom = $mo . '-01'; $pTo = gmdate('Y-m-t', up_ts($pFrom)); $key = ['month' => $mo];
  } else {
    $wk = up_monday($week ?? ($day ?? $lastDate)); $pFrom = $wk; $pTo = up_add_days($wk, 6); $key = ['week' => $wk];
  }
  $eFrom = $from !== null && $from > $pFrom ? $from : $pFrom; $eTo = $to !== null && $to < $pTo ? $to : $pTo;
  $m += ['view' => $view, 'key' => $key, 'pFrom' => $pFrom, 'pTo' => $pTo, 'eFrom' => $eFrom, 'eTo' => $eTo, 'from' => $from, 'to' => $to, 'entity' => $entity, 'category' => $cat, 'day' => $day,
    'counts' => [], 'monthTotals' => [], 'coarse' => [], 'coarseTotal' => 0, 'events' => [], 'panel' => null, 'max' => 0];
  if ($eFrom <= $eTo) {
    $h = uni_public_heatmap($pdo, 'day', $eFrom, $eTo, $entity, $cat);
    if ($h === null) return ['status' => 400, 'error' => 'parameter_invalid'] + $m;
    foreach ($h['buckets'] as $b) $m['counts'][$b['period']] = $b['count'];
    $m['max'] = $m['counts'] ? max($m['counts']) : 0; $m['dayTotal'] = $h['total']; $m['coarseTotal'] = $h['excluded_coarser_precision'];
    if ($view === 'year') {
      $hm = uni_public_heatmap($pdo, 'month', $eFrom, $eTo, $entity, $cat);
      foreach ($hm['buckets'] ?? [] as $b) $m['monthTotals'][$b['period']] = $b['count'];
    }
    if ($view !== 'week') {
      $filter = ['from' => $eFrom, 'to' => $eTo] + ($entity !== null ? ['entity_slug' => $entity] : []) + ($cat !== null ? ['event_type' => $cat] : []);
      for ($page = 1; $page <= 3; $page++) {
        $r = uni_public_events($pdo, $filter, $page, 100);
        foreach ($r['items'] as $ev) {
          if ($ev['date_precision'] === 'day') { if ($view === 'month') $m['events'][] = $ev; }
          elseif ($view === 'year' || $ev['date_precision'] === 'month') $m['coarse'][] = $ev;
        }
        if (!$r['has_more']) break;
      }
    } else {
      $r = uni_public_events($pdo, ['from' => $eFrom, 'to' => $eTo] + ($entity !== null ? ['entity_slug' => $entity] : []) + ($cat !== null ? ['event_type' => $cat] : []), 1, 100);
      foreach ($r['items'] as $ev) if ($ev['date_precision'] === 'day') $m['events'][] = $ev;
    }
  } else { $m['dayTotal'] = 0; }
  if ($day !== null) {
    $d = uni_public_day_events($pdo, $day, $entity, $cat);
    if ($d !== null) { $d['related'] = uh_related($pdo, $d['items']); $m['panel'] = $d; }
  }
  return $m;
}

/** Related entities of the entities that appear in a day panel: documented verified relationships (1 hop), with their sources. Bounded. */
function uh_related(PDO $pdo, array $items): array {
  $slugs = array_slice(array_values(array_unique(array_column($items, 'entity_slug'))), 0, 6); $out = []; $seen = [];
  foreach ($slugs as $s) {
    $g = uni_public_graph($pdo, $s, 8, 0, null); if ($g === null) continue;
    $nodes = []; foreach ($g['nodes'] as $n) $nodes[$n['ref']] = $n;
    foreach ($g['edges'] as $e) {
      $k = $e['from'] . '|' . $e['type'] . '|' . $e['to']; if (isset($seen[$k]) || !isset($nodes[$e['from']], $nodes[$e['to']])) continue; $seen[$k] = true;
      $out[] = ['from' => $nodes[$e['from']], 'to' => $nodes[$e['to']], 'type' => $e['type'], 'sources' => $e['sources']];
      if (count($out) >= 12) return $out;
    }
  }
  return $out;
}

function uh_state(array $m, array $extra = []): array {
  $s = ['view' => $m['view'] === 'year' ? null : $m['view']] + $m['key'];
  return array_merge($s, ['entity' => $m['entity'], 'category' => $m['category'], 'from' => $m['from'], 'to' => $m['to']], $extra);
}
function uh_link(array $m, array $extra = []): string { return up_url(UH_SCRIPT, uh_state($m, $extra)); }
function uh_level(int $count, int $max): int { return $count < 1 || $max < 1 ? 0 : min(4, (int)ceil(4 * $count / $max)); }
function uh_plural(int $n, string $one, string $many): string { return $n . ' ' . ($n === 1 ? $one : $many); }

/** One month as a table: Monday-first, active days are links with the count printed in the cell, other days are plain empty cells. */
function uh_calendar(array $m, string $monthStart, string $headingTag = 'h3'): string {
  $mEnd = gmdate('Y-m-t', up_ts($monthStart)); $id = 'uhm-' . substr($monthStart, 0, 7); $name = gmdate('F Y', up_ts($monthStart));
  $dayNum = 0; $monthDayCount = 0; foreach ($m['counts'] as $d => $c) if (substr($d, 0, 7) === substr($monthStart, 0, 7)) $monthDayCount += $c;
  $monthOnly = $m['view'] === 'year' ? max(0, ($m['monthTotals'][substr($monthStart, 0, 7)] ?? 0) - $monthDayCount) : 0;
  $o = '<div class="uhMonth"><' . $headingTag . ' id="' . $id . '-h">' . up_h($name) . '</' . $headingTag . '><p class="upMeta">' . up_h(uh_plural($monthDayCount, 'verified event', 'verified events') . ' on specific days') . ($monthOnly > 0 ? up_h(' · ' . $monthOnly . ' dated to the month only') : '') . '</p>';
  $o .= '<table class="uhCal" aria-labelledby="' . $id . '-h"><thead><tr>';
  foreach (['Monday' => 'Mon', 'Tuesday' => 'Tue', 'Wednesday' => 'Wed', 'Thursday' => 'Thu', 'Friday' => 'Fri', 'Saturday' => 'Sat', 'Sunday' => 'Sun'] as $full => $abbr) $o .= '<th scope="col" abbr="' . $full . '"><span aria-hidden="true">' . $abbr . '</span><span class="sr">' . $full . '</span></th>';
  $o .= '</tr></thead><tbody>';
  for ($wk = up_monday($monthStart); $wk <= $mEnd; $wk = up_add_days($wk, 7)) {
    $o .= '<tr>';
    for ($i = 0; $i < 7; $i++) {
      $d = up_add_days($wk, $i);
      if (substr($d, 0, 7) !== substr($monthStart, 0, 7)) { $o .= '<td class="uhPad"></td>'; continue; }
      $o .= '<td>' . uh_cell($m, $d) . '</td>';
    }
    $o .= '</tr>';
  }
  return $o . '</tbody></table></div>';
}
function uh_in_range(array $m, string $d): bool { return $d >= $m['eFrom'] && $d <= $m['eTo']; }
function uh_cell(array $m, string $d): string {
  $n = (int)substr($d, 8, 2); $count = $m['counts'][$d] ?? 0;
  if (!uh_in_range($m, $d)) return '<span class="uhCell uhOut" data-date="' . $d . '"><span class="uhD">' . $n . '</span></span>';
  if ($count < 1) return '<span class="uhCell uhEmpty" data-date="' . $d . '"><span class="uhD">' . $n . '</span></span>';
  $sel = $m['day'] === $d;
  $label = gmdate('F j, Y', up_ts($d)) . ': ' . uh_plural($count, 'verified event', 'verified events') . ($sel ? ' (selected)' : '');
  return '<a class="uhCell uhLv' . uh_level($count, $m['max']) . ($sel ? ' uhSel' : '') . '" href="' . up_h(uh_link($m, ['day' => $d]) . '#day-panel') . '" data-date="' . $d . '"' . ($sel ? ' aria-current="date"' : '') . ' aria-label="' . up_h($label) . '"><span class="uhD" aria-hidden="true">' . $n . '</span><span class="uhN" aria-hidden="true">' . $count . '</span></a>';
}
function uh_legend(array $m): string {
  $max = $m['max']; $o = '<div class="uhLegend" aria-labelledby="uh-legend-h" role="group"><h3 id="uh-legend-h">Legend</h3><ul>';
  $o .= '<li><span class="uhKey uhEmpty" aria-hidden="true"></span> No verified activity: the day is left empty (a dashed outline, never filled)</li>';
  for ($k = 1; $k <= 4; $k++) {
    if ($max < 1) break;
    $lo = (int)floor(($k - 1) * $max / 4) + 1; $hi = (int)floor($k * $max / 4); if ($lo > $hi) continue;
    $o .= '<li><span class="uhKey uhLv' . $k . '" aria-hidden="true">' . ($lo === $hi ? $lo : $hi) . '</span> ' . up_h($lo === $hi ? uh_plural($lo, 'verified event', 'verified events') : $lo . ' to ' . $hi . ' verified events') . ' (level ' . $k . ' of 4: more events, stronger pattern; the exact count is printed in every cell)</li>';
  }
  return $o . '</ul></div>';
}
function uh_event_item(array $ev, array $m, bool $showDay): string {
  $o = '<li><strong>' . up_h($ev['title']) . '</strong> <span class="upMeta">' . up_h(up_event_type_label($ev['event_type'])) . ' · ' . up_h($ev['entity_name']) . ' · ' . up_time_tag($ev['event_date'], $ev['date_precision']) . '</span>';
  if ($showDay && $ev['date_precision'] === 'day') $o .= ' <a href="' . up_h(uh_link($m, ['day' => $ev['event_date']]) . '#day-panel') . '">Details<span class="sr"> for ' . up_h($ev['title']) . '</span></a>';
  return $o . '</li>';
}
function uh_panel(array $m): string {
  $p = $m['panel'];
  $o = '<section class="upPanel" id="day-panel" tabindex="-1" aria-labelledby="day-h">';
  if ($p === null) return $o . '<h2 id="day-h">Day details</h2><p>Select a day with verified activity to see the verified events behind it, their sources and related entities. Days without a link have no verified activity.</p></section>';
  $o .= '<h2 id="day-h">Verified events on ' . up_h(gmdate('F j, Y', up_ts($p['day']))) . '</h2>';
  if ($p['count'] < 1) return $o . '<p>No verified activity on this day' . ($m['entity'] !== null || $m['category'] !== null ? ' for the current filters' : '') . '. The day is shown empty on purpose: nothing is filled in.</p></section>';
  $o .= '<p class="upMeta">' . up_h(uh_plural($p['count'], 'verified event', 'verified events')) . ($p['count'] > count($p['items']) ? up_h(' (the first ' . count($p['items']) . ' are shown)') : '') . '. Only events dated to this exact day are listed.</p><ul class="upCards">';
  foreach ($p['items'] as $ev) {
    $o .= '<li class="upCard"><h3>' . up_h($ev['title']) . '</h3><p class="upMeta">' . up_h(up_event_type_label($ev['event_type'])) . ' · ' . up_h($ev['entity_name']) . ' · ' . up_time_tag($ev['event_date'], $ev['date_precision']) . '</p>';
    if ($ev['summary'] !== null && trim((string)$ev['summary']) !== '') $o .= '<p>' . up_h($ev['summary']) . '</p>';
    $o .= '<h4>Sources</h4>' . up_sources_html($ev['sources']) . '</li>';
  }
  $o .= '</ul><h3>Related entities</h3>';
  if (!$p['related']) $o .= '<p>' . up_na('related entities') . '</p>';
  else {
    $o .= '<ul class="upRel">';
    foreach ($p['related'] as $r) $o .= '<li>' . up_h($r['from']['name']) . ' ' . up_h(up_edge_phrase($r['type'])) . ' ' . up_h($r['to']['name']) . ' <span class="upMeta">(' . up_h(str_replace('_', ' ', $r['type'])) . ')</span>' . up_sources_html($r['sources'], 'upSources upInline') . '</li>';
    $o .= '</ul>';
  }
  $slugs = array_values(array_unique(array_column($p['items'], 'entity_slug')));
  $o .= '<p>' . implode(' · ', array_map(fn($s) => '<a href="' . up_h(up_url('universe-lineage.php', ['entity' => $s])) . '">Release history of ' . up_h(array_column($p['items'], 'entity_name', 'entity_slug')[$s]) . '</a>', array_slice($slugs, 0, 5))) . '</p>';
  return $o . '</section>';
}
function uh_filters(array $m): string {
  $o = '<form class="uhFilters" method="get" action="/' . UH_SCRIPT . '" aria-labelledby="uh-filter-h"><h2 id="uh-filter-h">Filters</h2><div class="upGrid">';
  if ($m['view'] !== 'year') $o .= '<input type="hidden" name="view" value="' . up_h($m['view']) . '">';
  if ($m['view'] === 'year') $o .= '<div class="upField"><label for="uh-year">Year</label><input class="input" id="uh-year" type="number" name="year" min="1990" max="2100" value="' . up_h($m['key']['year']) . '"></div>';
  elseif ($m['view'] === 'month') $o .= '<div class="upField"><label for="uh-month">Month</label><input class="input" id="uh-month" type="month" name="month" value="' . up_h($m['key']['month']) . '"></div>';
  else $o .= '<div class="upField"><label for="uh-week">Week containing</label><input class="input" id="uh-week" type="date" name="week" value="' . up_h($m['key']['week']) . '"></div>';
  $o .= '<div class="upField"><label for="uh-entity">Company or product</label><select id="uh-entity" name="entity"><option value="">All companies and products</option>';
  $by = []; foreach ($m['entities'] as $e) $by[$e['entity_type']][] = $e;
  foreach (['company', 'product', 'model', 'version'] as $t) { if (empty($by[$t])) continue; $o .= '<optgroup label="' . up_h(up_entity_type_label($t) . 's') . '">'; foreach ($by[$t] as $e) $o .= '<option value="' . up_h($e['slug']) . '"' . ($m['entity'] === $e['slug'] ? ' selected' : '') . '>' . up_h($e['name']) . '</option>'; $o .= '</optgroup>'; }
  $o .= '</select></div><div class="upField"><label for="uh-cat">Category of event</label><select id="uh-cat" name="category"><option value="">All categories</option>';
  foreach (uni_event_types() as $t) $o .= '<option value="' . up_h($t) . '"' . ($m['category'] === $t ? ' selected' : '') . '>' . up_h(up_event_type_label($t)) . '</option>';
  $o .= '</select></div><div class="upField"><label for="uh-from">From date</label><input class="input" id="uh-from" type="date" name="from" value="' . up_h($m['from'] ?? '') . '"></div>';
  $o .= '<div class="upField"><label for="uh-to">To date</label><input class="input" id="uh-to" type="date" name="to" value="' . up_h($m['to'] ?? '') . '"></div></div>';
  $o .= '<p class="upActions"><button class="btn" type="submit">Apply filters</button> <a class="btn secondary" href="' . up_h(up_url(UH_SCRIPT, ['view' => $m['view'] === 'year' ? null : $m['view']] + $m['key'])) . '">Reset filters</a></p>';
  if ($m['entities_truncated']) $o .= '<p class="upMeta">Only the first 300 entities are offered in the list.</p>';
  return $o . '</form>';
}
function uh_nav(array $m): string {
  $o = '<nav class="uhViews" aria-label="Calendar view and period"><ul class="upTabs">';
  $anchor = $m['day'] ?? $m['eFrom'];
  foreach (['year' => 'Year', 'month' => 'Month', 'week' => 'Week'] as $v => $label) {
    $key = $v === 'year' ? ['year' => substr($m['pFrom'], 0, 4)] : ($v === 'month' ? ['month' => substr($m['pFrom'], 0, 7)] : ['week' => up_monday($m['pFrom'])]);
    $href = up_url(UH_SCRIPT, ['view' => $v === 'year' ? null : $v] + $key + ['entity' => $m['entity'], 'category' => $m['category'], 'from' => $m['from'], 'to' => $m['to']]);
    $o .= '<li><a class="upTab" href="' . up_h($href) . '"' . ($m['view'] === $v ? ' aria-current="page"' : '') . '>' . $label . ' view</a></li>';
  }
  $o .= '</ul><p class="uhPeriod">';
  $step = fn(int $dir) => $m['view'] === 'year' ? ['year' => (string)((int)substr($m['pFrom'], 0, 4) + $dir)] : ($m['view'] === 'month' ? ['month' => gmdate('Y-m', up_ts(substr($m['pFrom'], 0, 7) . '-01') + ($dir > 0 ? 32 : -1) * 86400)] : ['week' => up_add_days($m['pFrom'], 7 * $dir)]);
  foreach ([-1 => 'Previous', 1 => 'Next'] as $dir => $label) {
    $k = $step($dir); $val = (string)reset($k); $ok = $m['view'] === 'year' ? ((int)$val >= 1990 && (int)$val <= 2100) : (substr($val, 0, 4) >= '1990' && substr($val, 0, 4) <= '2100');
    if ($ok) $o .= '<a class="btn secondary sm" href="' . up_h(up_url(UH_SCRIPT, ['view' => $m['view'] === 'year' ? null : $m['view']] + $k + ['entity' => $m['entity'], 'category' => $m['category'], 'from' => $m['from'], 'to' => $m['to']])) . '">' . $label . ' ' . $m['view'] . '</a> ';
  }
  $name = $m['view'] === 'year' ? substr($m['pFrom'], 0, 4) : ($m['view'] === 'month' ? gmdate('F Y', up_ts($m['pFrom'])) : 'Week of ' . gmdate('F j, Y', up_ts($m['pFrom'])) . ' to ' . gmdate('F j, Y', up_ts($m['pTo'])));
  return $o . '<strong class="uhPeriodName">' . up_h($name) . '</strong></p></nav>';
}
function uh_tables(array $m): string {
  $o = '<section id="uh-table" aria-labelledby="uh-table-h" class="uhTables"><h2 id="uh-table-h">Table view of the same data</h2><p>This table lists the same verified counts as the calendar, for anyone who prefers a table or cannot use the colour and pattern coding.</p>';
  if ($m['view'] === 'year') {
    $o .= '<div class="upScroll" role="region" tabindex="0" aria-label="Monthly totals table"><table class="upTable"><caption>Verified events per month</caption><thead><tr><th scope="col">Month</th><th scope="col">On specific days</th><th scope="col">Dated to the month only</th><th scope="col">Total</th></tr></thead><tbody>';
    for ($mo = 1; $mo <= 12; $mo++) {
      $key = sprintf('%s-%02d', substr($m['pFrom'], 0, 4), $mo); $days = 0; foreach ($m['counts'] as $d => $c) if (substr($d, 0, 7) === $key) $days += $c;
      $total = $m['monthTotals'][$key] ?? $days; $o .= '<tr><th scope="row">' . up_h(gmdate('F', up_ts($key . '-01'))) . '</th><td>' . $days . '</td><td>' . max(0, $total - $days) . '</td><td>' . $total . '</td></tr>';
    }
    $o .= '</tbody></table></div>';
  }
  $o .= '<div class="upScroll" role="region" tabindex="0" aria-label="Days with verified activity table"><table class="upTable"><caption>Days with verified activity (all other days have none)</caption><thead><tr><th scope="col">Date</th><th scope="col">Verified events</th><th scope="col">Details</th></tr></thead><tbody>';
  if (!$m['counts']) $o .= '<tr><td colspan="3">No verified activity on any single day in this period for the current filters.</td></tr>';
  foreach ($m['counts'] as $d => $c) $o .= '<tr><th scope="row"><time datetime="' . $d . '">' . up_h(gmdate('F j, Y', up_ts($d))) . '</time></th><td>' . $c . '</td><td><a href="' . up_h(uh_link($m, ['day' => $d]) . '#day-panel') . '">Show events<span class="sr"> on ' . up_h($d) . '</span></a></td></tr>';
  return $o . '</tbody></table></div></section>';
}

function uh_render(array $m, string $shareUrl): string {
  $o = '<div class="upPage uhPage"><h1>AI Activity Heatmap</h1><p class="upLead">A calendar of verified AI releases, launches, updates, pricing changes and announcements. Every event on this page was checked against a source by a second person. A day without verified activity stays empty: nothing is estimated or filled in.</p>';
  $o .= '<p class="upSkip"><a href="#uh-table">Skip to the table version of the calendar</a></p>';
  $o .= uh_filters($m) . uh_nav($m);
  $total = $m['dayTotal'] ?? 0; $filt = ($m['entity'] !== null ? ' for the selected company or product' : '') . ($m['category'] !== null ? ' in the selected category' : '');
  $o .= '<div class="upStatus" role="status" aria-live="polite" id="uh-status"><p>' . up_h(uh_plural($total, 'verified event', 'verified events') . ' on specific days in this period' . $filt . '.') . '</p></div>';
  $o .= '<section class="uhCalendars" aria-labelledby="uh-cal-h"><h2 id="uh-cal-h">Calendar</h2><p class="upMeta">Days with a number are links: open one to see its events. Dashed empty days have no verified activity. Dimmed days are outside the selected date range. With a keyboard, Tab to a calendar then use the arrow keys to move between active days.</p><div class="uhMonths' . ($m['view'] === 'year' ? ' uhYear' : '') . '">';
  if ($m['view'] === 'year') for ($mo = 1; $mo <= 12; $mo++) $o .= uh_calendar($m, sprintf('%s-%02d-01', substr($m['pFrom'], 0, 4), $mo), 'h3');
  elseif ($m['view'] === 'month') $o .= uh_calendar($m, $m['pFrom'], 'h3');
  else $o .= uh_week($m);
  $o .= '</div>' . uh_legend($m) . '</section>' . uh_panel($m);
  if ($m['view'] !== 'year' && $m['events']) {
    $o .= '<section class="uhList" aria-labelledby="uh-ev-h"><h2 id="uh-ev-h">Verified events in this ' . up_h($m['view']) . '</h2><ul class="upList">';
    foreach ($m['events'] as $ev) $o .= uh_event_item($ev, $m, true);
    $o .= '</ul></section>';
  }
  $o .= '<section class="uhCoarse" aria-labelledby="uh-coarse-h"><h2 id="uh-coarse-h">Events with only a month or year date</h2>';
  if ($m['view'] === 'week') $o .= '<p>Events dated only to a month or a year cannot belong to a single week, so they are not listed in the week view. See the month or year view.</p>';
  elseif ($m['coarse']) {
    $o .= '<p>These verified events have a less precise date. They are listed here and are not drawn on any single day of the calendar.</p><ul class="upList">';
    foreach ($m['coarse'] as $ev) $o .= uh_event_item($ev, $m, false);
    $o .= '</ul>' . ($m['coarseTotal'] > count($m['coarse']) ? '<p class="upMeta">More events with coarse dates exist than are listed here.</p>' : '');
  } else $o .= '<p>No verified events with only a month or year date in this period for the current filters.</p>';
  $o .= '</section>' . uh_tables($m);
  $o .= '<section class="upShare" aria-labelledby="uh-share-h"><h2 id="uh-share-h">Share this view</h2><p>The address of this page holds the whole view (period, filters and selected day), so you can copy it and send it to someone.</p><div class="upCopy"><label for="uh-share">Address of this view</label><input class="input" id="uh-share" type="text" readonly value="' . up_h($shareUrl) . '"><button class="btn secondary js-only" type="button" data-copy-from="uh-share">Copy link</button></div><p class="upMeta upCopied" role="status" aria-live="polite" data-copy-status></p></section>';
  $o .= '<section class="upNotes" aria-labelledby="uh-notes-h"><h2 id="uh-notes-h">How to read this page</h2><ul><li>Only events with a verified status are counted. Drafts, events under review, rejected events and replaced versions never appear.</li><li>The number in a cell is the number of verified events dated to that exact day.</li><li>Events dated only to a month or a year are never placed on a day. They are listed separately and counted in the month totals of the year view.</li><li>Each event links to its sources with the date we retrieved them.</li></ul></section></div>';
  return $o;
}
function uh_week(array $m): string {
  $name = 'Week of ' . gmdate('F j, Y', up_ts($m['pFrom']));
  $o = '<div class="uhMonth"><h3 id="uhw-h">' . up_h($name) . '</h3><p class="upMeta">' . up_h(uh_plural((int)($m['dayTotal'] ?? 0), 'verified event', 'verified events') . ' on specific days') . '</p><table class="uhCal uhWeek" aria-labelledby="uhw-h"><thead><tr>';
  foreach (['Monday' => 'Mon', 'Tuesday' => 'Tue', 'Wednesday' => 'Wed', 'Thursday' => 'Thu', 'Friday' => 'Fri', 'Saturday' => 'Sat', 'Sunday' => 'Sun'] as $full => $abbr) $o .= '<th scope="col" abbr="' . $full . '"><span aria-hidden="true">' . $abbr . '</span><span class="sr">' . $full . '</span></th>';
  $o .= '</tr></thead><tbody><tr>';
  for ($i = 0; $i < 7; $i++) $o .= '<td>' . uh_cell($m, up_add_days($m['pFrom'], $i)) . '</td>';
  return $o . '</tr></tbody></table></div>';
}

// ---- request ---------------------------------------------------------------------------------------------------------------------------

$uhStatus = 200; $uhBody = ''; $robots = 'index'; $jsonld = []; $uhShare = site_url('/' . UH_SCRIPT);
$title = 'AI Activity Heatmap: Verified AI Release Calendar'; $meta_desc = 'A calendar of verified AI releases, launches and updates by day. Every event is sourced and reviewed; days without verified activity stay empty.';
$canonical = site_url('/' . UH_SCRIPT); $head_extra = up_head_extra();
$uhMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($uhMethod, ['GET', 'HEAD'], true)) { header('Allow: GET, HEAD'); http_response_code(405); exit('Method not allowed.'); }
if (up_rate_limit()) { header('Retry-After: 60'); $uhStatus = 429; $uhBody = up_error_body('Too many requests', 'Please wait a minute and try again.', '/' . UH_SCRIPT, 'Back to the heatmap'); }
else {
  [$uhP, $uhPerr] = up_params(['view', 'year', 'month', 'week', 'entity', 'category', 'from', 'to', 'day']);
  if ($uhPerr !== '') { $uhStatus = 400; $uhBody = up_error_body('That view could not be loaded', up_error_text($uhPerr), '/' . UH_SCRIPT, 'Open the heatmap'); }
  else {
    [$uhState, $uhM] = up_guard(fn(PDO $pdo) => uh_model($pdo, $uhP));
    if ($uhState === 'down') { $uhStatus = 503; header('Retry-After: 120'); $uhBody = up_error_body('Temporarily unavailable', 'The verified data could not be loaded right now. Please try again in a few minutes.', '/' . UH_SCRIPT, 'Try again'); }
    elseif ($uhState === 'empty' || !$uhM['any']) {
      $robots = 'noindex';
      $uhBody = '<div class="upPage"><h1>AI Activity Heatmap</h1><section class="upEmpty"><h2>No verified activity yet</h2><p>No AI event has been verified so far, so the calendar is empty. Nothing is estimated or filled in. Events appear here only after an editor proposed them with a source and a second person verified them.</p></section></div>';
    }
    elseif (isset($uhM['error'])) { $uhStatus = $uhM['status']; $uhBody = up_error_body($uhStatus === 404 ? 'Not found' : 'That view could not be loaded', up_error_text($uhM['error']), '/' . UH_SCRIPT, 'Open the heatmap'); }
    else {
      $uhShare = site_url(uh_link($uhM, $uhM['day'] !== null ? ['day' => $uhM['day']] : []));
      $uhBody = uh_render($uhM, $uhShare);
      if ($uhM['view'] === 'year' && !$uhM['entity'] && !$uhM['category'] && !$uhM['from'] && !$uhM['to'] && !$uhM['day']) { $title = 'AI Activity Heatmap ' . $uhM['key']['year'] . ': Verified AI Releases'; }
      $jsonld = [['@type' => 'WebPage', '@id' => $canonical . '#webpage', 'url' => $canonical, 'name' => 'AI Activity Heatmap', 'description' => $meta_desc, 'isPartOf' => ['@id' => site_url('/#website')]], up_crumbs([['Home', '/'], ['AI Activity Heatmap', '/' . UH_SCRIPT]])];
    }
  }
}
http_response_code($uhStatus);
if ($uhStatus >= 400) { $title = 'AI Activity Heatmap'; $robots = 'noindex'; }
require __DIR__ . '/layout_top.php';
echo $uhBody;
echo up_page_script('heatmap');
require __DIR__ . '/layout_bottom.php';
