<?php
if (isset($_SERVER["SCRIPT_FILENAME"]) && realpath($_SERVER["SCRIPT_FILENAME"]) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
// Phase 8D inline SVG charts: no libraries, no scripts, no heavy effects. Every chart has a title, a description, native hover tooltips (<title>) and a data table
// alternative. One currency per chart (never mixed). Colours come from CSS variables (light and dark); identity is also carried by labels and line style, not colour alone.
function admf_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function admf_short_month(string $ym): string { return date('M', strtotime($ym . '-01')) . ' ' . substr($ym, 2, 2); }
/** Compact axis value from minor units: 1.2k, 3.4M. */
function admf_compact(int $minor): string { $v = $minor / 100; $a = abs($v); if ($a >= 1000000) return rtrim(rtrim(number_format($v / 1000000, 1), '0'), '.') . 'M'; if ($a >= 1000) return rtrim(rtrim(number_format($v / 1000, 1), '0'), '.') . 'k'; return (string)(int)round($v); }
function admf_nice_max(int $max): int { if ($max <= 0) return 100; $p = 10 ** (strlen((string)$max) - 1); foreach ([1, 2, 2.5, 5, 10] as $m) if ($m * $p >= $max) return (int)($m * $p); return $p * 10; }
function admf_chart_empty(string $title, string $msg): string { return '<figure class="fChart fEmpty"><figcaption><strong>' . admf_h($title) . '</strong></figcaption><p class="fNo" role="note">' . admf_h($msg) . '</p></figure>'; }

/**
 * Month chart. $o: id, title, desc, currency, labels (YYYY-MM list), series [[name, values (minor, null = no point), cls 1|2, kind 'bar'|'line', dash bool]], state (badge key).
 * Returns an empty-state figure when no series has a recorded value (a chart of nothing would suggest zero).
 */
function admf_chart(array $o): string {
  $labels = $o['labels']; $n = count($labels); $cur = $o['currency']; $all = [];
  foreach ($o['series'] as $s) foreach ($s['values'] as $v) if ($v !== null) $all[] = $v;
  $hasData = false; foreach ($o['series'] as $s) if (empty($s['optional'])) foreach ($s['values'] as $v) if ($v !== null && $v !== 0) $hasData = true;
  if (!$n || !$hasData) return admf_chart_empty($o['title'], $o['empty'] ?? 'Nothing is recorded for this period yet, so there is nothing to draw.');
  $hi = max(1, max($all)); $lo = min(0, min($all)); $step = admf_nice_max((int)ceil(($hi - $lo) / 4)); $max = (int)(ceil($hi / $step) * $step); $min = (int)(floor($lo / $step) * $step); if ($max === $min) $max = $min + $step;
  $W = 640; $H = 280; $L = 52; $R = 12; $T = 16; $B = 34; $pw = $W - $L - $R; $ph = $H - $T - $B; $y = fn(int $v) => $T + $ph - ($v - $min) / max(1, $max - $min) * $ph; $slot = $pw / $n;
  $id = admf_h($o['id']); $bars = array_values(array_filter($o['series'], fn($s) => ($s['kind'] ?? 'bar') === 'bar')); $nb = max(1, count($bars));
  $svg = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-labelledby="' . $id . '-t ' . $id . '-d" preserveAspectRatio="xMidYMid meet"><title id="' . $id . '-t">' . admf_h($o['title']) . '</title><desc id="' . $id . '-d">' . admf_h($o['desc']) . '</desc>';
  for ($v = $min; $v <= $max; $v += $step) { $yy = round($y($v), 1); $svg .= '<line class="fGrid" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $yy . '" y2="' . $yy . '"/><text class="fAx" x="' . ($L - 6) . '" y="' . ($yy + 4) . '" text-anchor="end">' . admf_h(admf_compact($v)) . '</text>'; }
  $zero = round($y(0), 1); if ($min < 0) $svg .= '<line class="fBase" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $zero . '" y2="' . $zero . '"/>';
  foreach ($labels as $i => $ym) { if ($n > 8 && $i % 2 === 1 && $i !== $n - 1) continue; $svg .= '<text class="fAx" x="' . round($L + $slot * ($i + .5), 1) . '" y="' . ($H - 12) . '" text-anchor="middle">' . admf_h(admf_short_month($ym)) . '</text>'; }
  $bi = 0;
  foreach ($o['series'] as $s) {
    $cls = 'fc' . (int)($s['cls'] ?? 1);
    if (($s['kind'] ?? 'bar') === 'bar') {
      $bw = min(26, ($slot - 6) / $nb - 2); foreach ($s['values'] as $i => $v) { if ($v === null) continue; $x = $L + $slot * $i + ($slot - ($bw + 2) * $nb) / 2 + $bi * ($bw + 2); $y0 = $y(max(0, $v)); $y1 = $y(min(0, $v)); $h = max(1, abs($y1 - $y0));
        $svg .= '<rect class="fBar ' . $cls . '" x="' . round($x, 1) . '" y="' . round(min($y0, $y1), 1) . '" width="' . round($bw, 1) . '" height="' . round($h, 1) . '" rx="3"><title>' . admf_h(admf_short_month($labels[$i]) . ': ' . $s['name'] . ' ' . admf_money($v, $cur)) . '</title></rect>'; }
      $bi++;
    } else {
      $pts = []; foreach ($s['values'] as $i => $v) if ($v !== null) $pts[] = [round($L + $slot * ($i + .5), 1), round($y($v), 1), $i, $v];
      if (count($pts) > 1) $svg .= '<polyline class="fLine ' . $cls . (!empty($s['dash']) ? ' fDash' : '') . '" fill="none" points="' . implode(' ', array_map(fn($p) => $p[0] . ',' . $p[1], $pts)) . '"/>';
      foreach ($pts as $p) $svg .= '<circle class="fDot ' . $cls . '" cx="' . $p[0] . '" cy="' . $p[1] . '" r="4"><title>' . admf_h(admf_short_month($labels[$p[2]]) . ': ' . $s['name'] . ' ' . admf_money($p[3], $cur)) . '</title></circle>';
      if ($pts && !empty($s['label'])) { $last = end($pts); $svg .= '<text class="fDirect" x="' . min($W - $R, $last[0] + 6) . '" y="' . ($last[1] - 8) . '" text-anchor="end">' . admf_h($s['label']) . '</text>'; }
    }
  }
  $svg .= '</svg>';
  $legend = '<ul class="fLegend" aria-label="Legend">'; foreach ($o['series'] as $s) $legend .= '<li><span class="fKey fc' . (int)($s['cls'] ?? 1) . (($s['kind'] ?? 'bar') === 'line' ? ' fKeyLine' . (!empty($s['dash']) ? ' fDash' : '') : '') . '" aria-hidden="true"></span>' . admf_h($s['name']) . '</li>'; $legend .= '</ul>';
  $tbl = '<div class="scroll" role="region" aria-label="' . admf_h($o['title']) . ' data table" tabindex="0"><table><caption class="sr">' . admf_h($o['title']) . ' (' . admf_h($cur) . ')</caption><thead><tr><th scope="col">Month</th>';
  foreach ($o['series'] as $s) $tbl .= '<th scope="col">' . admf_h($s['name']) . '</th>'; $tbl .= '</tr></thead><tbody>';
  foreach ($labels as $i => $ym) { $tbl .= '<tr><th scope="row">' . admf_h($ym) . '</th>'; foreach ($o['series'] as $s) { $v = $s['values'][$i] ?? null; $tbl .= '<td>' . ($v === null ? 'No data' : admf_h(admf_money($v, $cur))) . '</td>'; } $tbl .= '</tr>'; }
  $tbl .= '</tbody></table></div>';
  return '<figure class="fChart"><figcaption><strong>' . admf_h($o['title']) . '</strong>' . (!empty($o['state']) ? ' ' . admf_state($o['state']) : '') . '<br><span class="small">' . admf_h($o['desc']) . '</span></figcaption>' . $svg . $legend . '<details class="fDet"><summary>Data table</summary>' . $tbl . '</details></figure>';
}

/** Horizontal bars for one currency (streams, expense categories): $items = [label => minor]. Single series, direct value labels. */
function admf_chart_hbars(string $id, string $title, string $desc, string $cur, array $items, string $state = '', string $empty = 'Nothing is recorded yet.'): string {
  $items = array_filter($items, fn($v) => $v > 0); arsort($items); if (!$items) return admf_chart_empty($title, $empty);
  $max = max($items); $rowH = 34; $H = count($items) * $rowH + 8; $W = 640; $L = 190; $R = 86; $pw = $W - $L - $R; $i = 0;
  $svg = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-labelledby="' . admf_h($id) . '-t ' . admf_h($id) . '-d"><title id="' . admf_h($id) . '-t">' . admf_h($title) . '</title><desc id="' . admf_h($id) . '-d">' . admf_h($desc) . '</desc>';
  foreach ($items as $label => $v) { $yy = 4 + $i * $rowH; $w = max(2, $v / $max * $pw);
    $svg .= '<text class="fAx fRowLab" x="' . ($L - 8) . '" y="' . ($yy + 20) . '" text-anchor="end">' . admf_h(mb_strimwidth((string)$label, 0, 28, '…')) . '</text><rect class="fBar fc1" x="' . $L . '" y="' . ($yy + 6) . '" width="' . round($w, 1) . '" height="20" rx="4"><title>' . admf_h($label . ': ' . admf_money($v, $cur)) . '</title></rect><text class="fDirect fVal" x="' . round($L + $w + 6, 1) . '" y="' . ($yy + 21) . '">' . admf_h(admf_compact($v)) . '</text>'; $i++; }
  $svg .= '</svg>'; $tbl = '<div class="scroll" role="region" aria-label="' . admf_h($title) . ' data table" tabindex="0"><table><caption class="sr">' . admf_h($title) . ' (' . admf_h($cur) . ')</caption><thead><tr><th scope="col">Item</th><th scope="col">Amount</th></tr></thead><tbody>';
  foreach ($items as $label => $v) $tbl .= '<tr><th scope="row">' . admf_h($label) . '</th><td>' . admf_h(admf_money($v, $cur)) . '</td></tr>'; $tbl .= '</tbody></table></div>';
  return '<figure class="fChart"><figcaption><strong>' . admf_h($title) . '</strong>' . ($state !== '' ? ' ' . admf_state($state) : '') . '<br><span class="small">' . admf_h($desc) . '</span></figcaption>' . $svg . '<details class="fDet"><summary>Data table</summary>' . $tbl . '</details></figure>';
}

/** Progress bar toward one target. Shown only when a recorded value exists; the value is never presented as an achievement when it is missing. */
function admf_chart_bullet(string $id, string $title, int $value, int $target, string $cur, string $targetLabel): string {
  $pct = $target > 0 ? min(100, $value / $target * 100) : 0; $W = 640; $H = 56; $pw = $W - 24;
  $svg = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-labelledby="' . admf_h($id) . '-t"><title id="' . admf_h($id) . '-t">' . admf_h($title . ': ' . admf_money($value, $cur) . ' recorded of a ' . admf_money($target, $cur) . ' target (' . round($pct, 1) . ' percent)') . '</title><rect class="fTrack" x="12" y="14" width="' . $pw . '" height="18" rx="9"/><rect class="fBar fc1" x="12" y="14" width="' . max(2, round($pw * $pct / 100, 1)) . '" height="18" rx="9"/><text class="fAx" x="12" y="50">' . admf_h(admf_money($value, $cur)) . ' recorded</text><text class="fAx" x="' . ($W - 12) . '" y="50" text-anchor="end">' . admf_h($targetLabel) . '</text></svg>';
  return '<figure class="fChart"><figcaption><strong>' . admf_h($title) . '</strong> ' . admf_state('recorded') . ' ' . admf_state('target') . '</figcaption>' . $svg . '</figure>';
}
