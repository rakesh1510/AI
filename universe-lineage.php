<?php
/**
 * AI Tool Lineage (Phase 7): Git-style release history of a model family or product. Server-rendered (the SVG graphs need no JavaScript).
 * Only DOCUMENTED, VERIFIED relationships are drawn, each with its own type and sources:
 *   technical ancestry = derived_from, built_on;  product succession = successor_of, renamed_from;  documented release parent = a verified release-to-release link.
 * Nothing else (developed_by, integrates_with, hosted_on, acquired_by, version_of) is ever drawn as ancestry. Data: uni_public_* only.
 */
require_once __DIR__ . '/uni_pages.php';

const UL_SCRIPT = 'universe-lineage.php';

function ul_model(PDO $pdo, array $P): array {
  if (!isset($P['entity'])) {
    $by = [];
    foreach (up_all_releases($pdo, null, 500) as $r) {
      $s = $r['entity_slug']; $by[$s] ??= ['slug' => $s, 'name' => $r['entity_name'], 'count' => 0, 'first' => $r['release_date'], 'last' => $r['release_date']];
      $by[$s]['count']++; if ($r['release_date'] < $by[$s]['first']) $by[$s]['first'] = $r['release_date']; if ($r['release_date'] > $by[$s]['last']) $by[$s]['last'] = $r['release_date'];
    }
    usort($by, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return ['status' => 200, 'mode' => 'index', 'families' => $by];
  }
  if (!up_slug_ok($P['entity'])) return ['status' => 400, 'error' => 'parameter_invalid'];
  $ent = uni_public_entity($pdo, $P['entity']); if ($ent === null) return ['status' => 404, 'error' => 'entity_not_found'];
  $rels = up_all_releases($pdo, $ent['slug'], 200); usort($rels, fn($a, $b) => [$a['release_date'], $a['version_label']] <=> [$b['release_date'], $b['version_label']]);
  $lin = uni_public_lineage($pdo, $ent['slug'], 100) ?? ['nodes' => [], 'edges' => [], 'entities' => [], 'entity_edges' => [], 'truncated' => false];
  $events = [];
  for ($page = 1; $page <= 3; $page++) { $r = uni_public_events($pdo, ['entity_slug' => $ent['slug']], $page, 100); foreach ($r['items'] as $e) $events[] = $e; if (!$r['has_more']) break; }
  $m = ['status' => 200, 'mode' => 'entity', 'entity' => $ent, 'releases' => $rels, 'lineage' => $lin, 'events' => $events, 'diff' => null, 'diff_error' => null, 'a' => $P['a'] ?? '', 'b' => $P['b'] ?? ''];
  if (isset($P['a']) || isset($P['b'])) {
    if (!isset($P['a'], $P['b'])) { $m['diff_error'] = ['status' => 400, 'code' => 'release_not_found']; }
    else {
      $d = uni_public_release_diff($pdo, $ent['slug'], $P['a'], $P['b']);
      if (isset($d['error'])) $m['diff_error'] = ['status' => $d['error'] === 'same_release' ? 400 : ($d['error'] === 'release_ambiguous' ? 409 : 404), 'code' => $d['error']];
      else $m['diff'] = $d;
    }
  }
  return $m;
}

function ul_short(string $ref): string { return substr($ref, 0, 7); }
function ul_trunc(string $s, int $n): string { return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s; }
function ul_svg_defs(string $p): string {
  return '<defs><marker id="' . $p . 'ArrTech" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="8" markerHeight="8" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z" class="ulArrTech"/></marker>'
    . '<marker id="' . $p . 'ArrSucc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="8" markerHeight="8" orient="auto-start-reverse"><path d="M1 1L9 5L1 9" class="ulArrSucc"/></marker>'
    . '<marker id="' . $p . 'ArrRel" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 2L10 5L0 8" class="ulArrRel"/></marker></defs>';
}
function ul_kind_class(string $kind): string { return ['technical_ancestry' => 'Tech', 'product_succession' => 'Succ', 'release_parent' => 'Rel'][$kind] ?? 'Rel'; }

/** Branch graph of releases: one column per release in date order, one lane per entity (the subject first). Edges are documented release-parent links only. */
function ul_svg_releases(array $lin, string $slug, string $name): string {
  $nodes = $lin['nodes']; usort($nodes, fn($a, $b) => [$a['release_date'], $a['ref']] <=> [$b['release_date'], $b['ref']]);
  $lanes = [$slug => 0]; foreach ($nodes as $n) if (!isset($lanes[$n['entity_slug']])) $lanes[$n['entity_slug']] = count($lanes);
  $laneName = []; foreach ($nodes as $n) $laneName[$n['entity_slug']] = $n['entity_name'];
  $step = 104; $left = 28; $top = 58; $laneH = 86; $pos = [];
  foreach ($nodes as $i => $n) $pos[$n['ref']] = [$left + 30 + $i * $step, $top + $lanes[$n['entity_slug']] * $laneH];
  $w = $left * 2 + 40 + count($nodes) * $step; $h = $top + (count($lanes) - 1) * $laneH + 66;
  $o = '<svg class="ulSvg" xmlns="http://www.w3.org/2000/svg" role="group" aria-labelledby="ul-rg-t ul-rg-d" viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" focusable="false"><title id="ul-rg-t">Release branch graph of ' . up_h($name) . '</title><desc id="ul-rg-d">Releases in date order from left to right, one row per product or model. Lines join a release to its documented parent release. The same relationships are listed in the tables below.</desc>' . ul_svg_defs('r');
  foreach ($lanes as $s => $ln) $o .= '<text class="ulLane" x="' . $left . '" y="' . ($top + $ln * $laneH - 26) . '">' . up_h(ul_trunc($laneName[$s] ?? $s, 34)) . '</text><line class="ulLaneLine" x1="' . $left . '" x2="' . ($w - $left) . '" y1="' . ($top + $ln * $laneH) . '" y2="' . ($top + $ln * $laneH) . '"/>';
  $byRef = []; foreach ($nodes as $n) $byRef[$n['ref']] = $n;
  foreach ($lin['edges'] as $e) {
    if (!isset($pos[$e['from']], $pos[$e['to']])) continue;
    [$px, $py] = $pos[$e['from']]; [$cx, $cy] = $pos[$e['to']]; $dir = $cx >= $px ? 1 : -1; $x1 = $px + 10 * $dir; $x2 = $cx - 12 * $dir; $dx = $x2 - $x1;
    $lift = ($py === $cy && abs($dx) > $step * 1.5) ? -34 : 0;
    $d = 'M' . $x1 . ' ' . $py . ' C' . round($x1 + $dx * 0.45) . ' ' . ($py + $lift) . ' ' . round($x1 + $dx * 0.55) . ' ' . ($cy + $lift) . ' ' . $x2 . ' ' . $cy;
    $o .= '<path class="ulEdge ulEdgeRel" data-kind="release_parent" d="' . $d . '" marker-end="url(#rArrRel)"><title>' . up_h(($byRef[$e['from']]['version_label'] ?? '') . ' is the documented parent release of ' . ($byRef[$e['to']]['version_label'] ?? '')) . '</title></path>';
  }
  foreach ($nodes as $n) {
    [$x, $y] = $pos[$n['ref']]; $own = $n['entity_slug'] === $slug;
    $href = $own ? '#rel-' . $n['ref'] : up_url(UL_SCRIPT, ['entity' => $n['entity_slug']]) . '#rel-' . $n['ref'];
    $label = $n['version_label'] . ', ' . up_date_long($n['release_date'], $n['date_precision']) . ($own ? '' : ', release of ' . $n['entity_name']);
    $o .= '<a class="ulNodeLink" href="' . up_h($href) . '" aria-label="' . up_h($label) . '"><circle class="ulNode' . ($own ? '' : ' ulNodeOther') . '" cx="' . $x . '" cy="' . $y . '" r="9"/><text class="ulNodeLabel" x="' . $x . '" y="' . ($y + 26) . '" text-anchor="middle">' . up_h(ul_trunc($n['version_label'], 13)) . '</text><text class="ulNodeDate" x="' . $x . '" y="' . ($y + 40) . '" text-anchor="middle">' . up_h(up_iso_partial($n['release_date'], $n['date_precision'])) . '</text></a>';
  }
  return $o . '</svg>';
}

/** Entity-level lineage: ancestors on the left, the subject in the middle, derived or succeeding entities on the right. Arrows point from older to newer. */
function ul_svg_entities(array $lin, string $subjectRef, string $name): string {
  $nodes = []; foreach ($lin['entities'] as $n) $nodes[$n['ref']] = $n;
  $col = [0 => [], 1 => [$subjectRef => $nodes[$subjectRef] ?? ['ref' => $subjectRef, 'name' => $name, 'slug' => '', 'type' => '']], 2 => []]; $edges = [];
  foreach ($lin['entity_edges'] as $e) {
    $subjectIsChild = $e['from'] === $subjectRef; $other = $subjectIsChild ? $e['to'] : $e['from']; $c = $subjectIsChild ? 0 : 2;
    if (!isset($nodes[$other])) continue; $col[$c][$other] = $nodes[$other]; $edges[] = ['parent' => $subjectIsChild ? [0, $other] : [1, $subjectRef], 'child' => $subjectIsChild ? [1, $subjectRef] : [2, $other], 'e' => $e];
  }
  $bw = 176; $bh = 44; $gap = 168; $x = [16, 16 + $bw + $gap, 16 + 2 * ($bw + $gap)]; $rows = max(count($col[0]), count($col[1]), count($col[2]), 1); $vs = 74; $w = $x[2] + $bw + 16; $h = 24 + $rows * $vs;
  $y = []; foreach ($col as $c => $list) { $i = 0; $off = ($rows - max(count($list), 1)) * $vs / 2; foreach ($list as $ref => $n) { $y[$c][$ref] = 20 + $off + $i * $vs; $i++; } }
  $o = '<svg class="ulSvg" xmlns="http://www.w3.org/2000/svg" role="group" aria-labelledby="ul-eg-t ul-eg-d" viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" focusable="false"><title id="ul-eg-t">Documented lineage of ' . up_h($name) . '</title><desc id="ul-eg-d">Entities with a documented technical ancestry or product succession relationship to ' . up_h($name) . '. Arrows point from the earlier entity to the later one. The same relationships are listed in the table below.</desc>' . ul_svg_defs('e');
  foreach ($edges as $ed) {
    [$pc, $pr] = $ed['parent']; [$cc, $cr] = $ed['child']; $x1 = $x[$pc] + $bw; $y1 = $y[$pc][$pr] + $bh / 2; $x2 = $x[$cc] - 2; $y2 = $y[$cc][$cr] + $bh / 2; $mx = ($x1 + $x2) / 2; $kind = $ed['e']['type']; $k = ul_kind_class($kind);
    $o .= '<g data-kind="' . up_h($kind) . '"><path class="ulEdge ulEdge' . $k . '" d="M' . $x1 . ' ' . $y1 . ' C' . $mx . ' ' . $y1 . ' ' . $mx . ' ' . $y2 . ' ' . $x2 . ' ' . $y2 . '" marker-end="url(#eArr' . $k . ')"/>'
      . '<text class="ulEdgeLabel" x="' . $mx . '" y="' . (($y1 + $y2) / 2 - 8) . '" text-anchor="middle">' . up_h(up_kind_label($kind)) . '</text><text class="ulEdgeLabel2" x="' . $mx . '" y="' . (($y1 + $y2) / 2 + 6) . '" text-anchor="middle">' . up_h(str_replace('_', ' ', $ed['e']['edge_type'])) . '</text></g>';
  }
  foreach ($col as $c => $list) foreach ($list as $ref => $n) {
    $isSubject = $c === 1; $nm = ul_trunc((string)$n['name'], 22);
    $o .= '<g><rect class="ulBox' . ($isSubject ? ' ulBoxSubject' : '') . '" x="' . $x[$c] . '" y="' . $y[$c][$ref] . '" width="' . $bw . '" height="' . $bh . '" rx="10"/><text class="ulBoxText" x="' . ($x[$c] + $bw / 2) . '" y="' . ($y[$c][$ref] + 19) . '" text-anchor="middle">' . up_h($nm) . '</text><text class="ulBoxType" x="' . ($x[$c] + $bw / 2) . '" y="' . ($y[$c][$ref] + 34) . '" text-anchor="middle">' . up_h(up_entity_type_label((string)($n['type'] ?? ''))) . '</text><title>' . up_h($n['name']) . '</title></g>';
  }
  return $o . '</svg>';
}

function ul_legend(bool $withToggles): string {
  $line = fn(string $cls, string $mk) => '<svg class="ulKeySvg" width="64" height="16" viewBox="0 0 64 16" aria-hidden="true" focusable="false">' . ul_svg_defs('k' . $mk) . '<path class="ulEdge ulEdge' . $cls . '" d="M2 8H56" marker-end="url(#kArr' . $cls . ')"/></svg>';
  $row = fn(string $kind, string $cls, string $label, string $text) => '<li><span class="ulKeyIcon">' . $line($cls, $cls) . '</span><span><strong>' . $label . '</strong>: ' . $text . ($withToggles ? ' <label class="ulToggle js-only"><input type="checkbox" checked data-ul-toggle="' . $kind . '"> Show on graph</label>' : '') . '</span></li>';
  return '<section class="ulLegend" aria-labelledby="ul-legend-h"><h2 id="ul-legend-h">Legend: relationship types</h2><p>Each type of relationship has its own line style and its own label, so they are never confused. Only relationships that are documented in a source and verified are drawn.</p><ul>'
    . $row('technical_ancestry', 'Tech', 'Technical ancestry', 'a solid thick line with a filled arrow. Used only for “derived from” and “built on”.')
    . $row('product_succession', 'Succ', 'Product succession', 'a dashed line with an open arrow. Used only for “successor of” and “renamed from”. A successor is not necessarily technically derived from its predecessor.')
    . $row('release_parent', 'Rel', 'Documented release parent', 'a thin dotted line between two releases whose source names one as the parent of the other.')
    . '</ul><p class="upMeta">Not drawn as lineage: developed by, integrates with, hosted on, acquired by, and version of. If no relationship is documented, no line is drawn and none is implied.</p></section>';
}

function ul_change_dl(?array $cs): string {
  if (!is_array($cs) || $cs === [] || array_is_list($cs)) return $cs === null ? '<p>Changes: ' . up_na() . '</p>' : '<p>Changes: ' . up_h(up_value_text($cs)) . '</p>';
  $o = '<dl class="upDl">';
  foreach ($cs as $k => $v) { if (in_array((string)$k, ['description', 'summary'], true)) continue; $o .= '<dt>' . up_h(str_replace('_', ' ', (string)$k)) . '</dt><dd>' . up_h(up_value_text($v)) . '</dd>'; }
  return $o . '</dl>';
}
function ul_commits(array $m): string {
  $slug = $m['entity']['slug']; $nodeEntity = []; foreach ($m['lineage']['nodes'] as $n) $nodeEntity[$n['ref']] = $n; $o = '<ol class="ulCommits">';
  foreach ($m['releases'] as $r) {
    $cs = $r['change_set']; $desc = is_array($cs) ? ($cs['description'] ?? $cs['summary'] ?? null) : null;
    $o .= '<li class="ulCommit" id="rel-' . up_h($r['ref']) . '"><h3>' . up_h($r['version_label']) . ' <code class="ulHash" title="Release reference ' . up_h($r['ref']) . '">' . up_h(ul_short($r['ref'])) . '</code></h3><p class="upMeta">Released ' . up_time_tag($r['release_date'], $r['date_precision']) . '</p>';
    $o .= '<p>' . ($desc !== null && is_scalar($desc) && trim((string)$desc) !== '' ? up_h((string)$desc) : 'Description: ' . up_na()) . '</p>' . ul_change_dl(is_array($cs) ? $cs : null);
    if ($r['parents']) {
      $o .= '<p>Documented parent release' . (count($r['parents']) > 1 ? 's' : '') . ': ';
      $o .= implode(', ', array_map(function ($p) use ($nodeEntity, $slug) {
        $own = !isset($nodeEntity[$p['ref']]) || $nodeEntity[$p['ref']]['entity_slug'] === $slug; $href = ($own ? '' : up_url(UL_SCRIPT, ['entity' => $nodeEntity[$p['ref']]['entity_slug']])) . '#rel-' . $p['ref'];
        return '<a href="' . up_h($href) . '">' . up_h($p['version_label']) . ($own ? '' : ' (' . up_h($nodeEntity[$p['ref']]['entity_name']) . ')') . '</a>';
      }, $r['parents'])) . '</p>';
    } else $o .= '<p class="upMeta">No parent release is documented for this release.</p>';
    $o .= '<h4>Source</h4>' . up_sources_html([['url' => $r['source_url'], 'publisher' => $r['source_publisher'], 'retrieved_at' => $r['source_retrieved_at']]]) . '</li>';
  }
  return $o . '</ol>';
}
function ul_relationship_table(array $lin, string $name): string {
  if (!$lin['entity_edges']) return '<p>' . up_na('technical ancestry or product succession') . ' No ancestry or succession relationship is documented for ' . up_h($name) . '.</p>';
  $nodes = []; foreach ($lin['entities'] as $n) $nodes[$n['ref']] = $n;
  $o = '<div class="upScroll" role="region" tabindex="0" aria-label="Documented relationships table"><table class="upTable"><caption>Documented relationships of ' . up_h($name) . '</caption><thead><tr><th scope="col">Relationship type</th><th scope="col">Statement</th><th scope="col">Valid</th><th scope="col">Sources</th></tr></thead><tbody>';
  foreach ($lin['entity_edges'] as $e) {
    $o .= '<tr data-kind="' . up_h($e['type']) . '"><th scope="row">' . up_h(up_kind_label($e['type'])) . '<br><span class="upMeta">' . up_h(str_replace('_', ' ', $e['edge_type'])) . '</span></th><td>' . up_h(($nodes[$e['from']]['name'] ?? '') . ' ' . up_edge_phrase($e['edge_type']) . ' ' . ($nodes[$e['to']]['name'] ?? '')) . '</td><td>'
      . up_h(($e['valid_from'] ?? '') !== '' || ($e['valid_to'] ?? '') !== '' ? trim(($e['valid_from'] ?? 'unknown') . ' to ' . ($e['valid_to'] ?? 'present')) : 'Not stated') . '</td><td>' . up_sources_html($e['sources']) . '</td></tr>';
  }
  return $o . '</tbody></table></div>';
}
function ul_release_edge_table(array $lin): string {
  if (!$lin['edges']) return '';
  $nodes = []; foreach ($lin['nodes'] as $n) $nodes[$n['ref']] = $n;
  $o = '<div class="upScroll" role="region" tabindex="0" aria-label="Documented release parents table"><table class="upTable"><caption>Documented release parents (the dotted lines of the release graph)</caption><thead><tr><th scope="col">Parent release</th><th scope="col">Release</th><th scope="col">Source</th></tr></thead><tbody>';
  foreach ($lin['edges'] as $e) { $a = $nodes[$e['from']] ?? null; $b = $nodes[$e['to']] ?? null; if (!$a || !$b) continue;
    $o .= '<tr data-kind="release_parent"><th scope="row">' . up_h($a['entity_name'] . ' ' . $a['version_label']) . '</th><td>' . up_h($b['entity_name'] . ' ' . $b['version_label']) . '</td><td>' . up_sources_html([$e['source']]) . '</td></tr>'; }
  return $o . '</tbody></table></div>';
}
function ul_diff_form(array $m): string {
  $opt = function (string $sel) use ($m) { $o = '<option value="">Choose a release</option>'; foreach ($m['releases'] as $r) $o .= '<option value="' . up_h($r['ref']) . '"' . ($sel === $r['ref'] ? ' selected' : '') . '>' . up_h($r['version_label'] . ' (' . up_iso_partial($r['release_date'], $r['date_precision']) . ')') . '</option>'; return $o; };
  return '<form class="ulDiffForm" method="get" action="/' . UL_SCRIPT . '#diff"><input type="hidden" name="entity" value="' . up_h($m['entity']['slug']) . '"><div class="upGrid"><div class="upField"><label for="ul-a">First release</label><select id="ul-a" name="a" required>' . $opt($m['a']) . '</select></div><div class="upField"><label for="ul-b">Second release</label><select id="ul-b" name="b" required>' . $opt($m['b']) . '</select></div></div><p class="upActions"><button class="btn" type="submit">Show differences</button></p></form>';
}
function ul_facet(string $label, array $f): string {
  $o = '<h4>' . up_h($label) . '</h4>';
  if (!($f['available'] ?? false)) return $o . '<p>' . up_na($label) . '</p>';
  if (isset($f['change_set'])) { $c = $f['change_set']; $o .= '<p>Status in the release data: <strong>' . up_h($c['status']) . '</strong>. From: ' . up_h(up_value_text($c['from'])) . '. To: ' . up_h(up_value_text($c['to'])) . '.</p>'; }
  foreach ($f['events'] ?? [] as $e) $o .= '<div class="upCard"><p><strong>' . up_h($e['title']) . '</strong> <span class="upMeta">' . up_h(up_event_type_label($e['event_type'])) . ' · ' . up_time_tag($e['event_date'], $e['date_precision']) . '</span></p>' . up_sources_html($e['sources']) . '</div>';
  return $o;
}
function ul_diff(array $m): string {
  $o = '<section class="ulDiff" id="diff" tabindex="-1" aria-labelledby="diff-h"><h2 id="diff-h">Compare two releases</h2>';
  if (count($m['releases']) < 2) return $o . '<p>' . up_na('a version comparison') . ' At least two verified releases are needed to compare versions.</p></section>';
  $o .= '<p>Pick two verified releases to see what changed between them. Only fields present in both verified records are compared; anything else is marked unavailable.</p>' . ul_diff_form($m);
  if ($m['diff_error']) return $o . '<p class="upErrorNote" role="alert">' . up_h(up_error_text($m['diff_error']['code'])) . '</p></section>';
  $d = $m['diff']; if ($d === null) return $o . '</section>';
  $o .= '<div class="upResult" aria-live="polite"><h3>' . up_h($d['from']['version_label']) . ' to ' . up_h($d['to']['version_label']) . '</h3><p class="upMeta">' . up_time_tag($d['from']['release_date'], $d['from']['date_precision']) . ' to ' . up_time_tag($d['to']['release_date'], $d['to']['date_precision']) . ' · ' . up_h((string)$d['days_between']) . ' days apart' . ($d['swapped_to_chronological_order'] ? ' · shown in date order' : '') . '</p><h4>Release data (change set)</h4>';
  $cs = $d['change_set'];
  if (!($cs['available'] ?? false)) $o .= '<p>' . up_na('the change set') . ' At least one of the two releases has no recorded change set, so no field comparison is possible.</p>';
  else {
    $o .= '<div class="upScroll" role="region" tabindex="0" aria-label="Release differences table"><table class="upTable"><caption>Differences in recorded release data</caption><thead><tr><th scope="col">Field</th><th scope="col">Status</th><th scope="col">Earlier release</th><th scope="col">Later release</th></tr></thead><tbody>';
    foreach ($cs['added'] as $k) $o .= '<tr><th scope="row">' . up_h(str_replace('_', ' ', $k)) . '</th><td>Added</td><td>' . up_na() . '</td><td>Present in the later release</td></tr>';
    foreach ($cs['removed'] as $k) $o .= '<tr><th scope="row">' . up_h(str_replace('_', ' ', $k)) . '</th><td>Removed</td><td>Present in the earlier release</td><td>' . up_na() . '</td></tr>';
    foreach ($cs['changed'] as $c) $o .= '<tr><th scope="row">' . up_h(str_replace('_', ' ', $c['key'])) . '</th><td>Changed</td><td>' . up_h(up_value_text($c['from'])) . '</td><td>' . up_h(up_value_text($c['to'])) . '</td></tr>';
    if (!$cs['added'] && !$cs['removed'] && !$cs['changed']) $o .= '<tr><td colspan="4">No differences in the recorded fields.</td></tr>';
    $o .= '</tbody></table></div><p class="upMeta">' . up_h((string)$cs['unchanged_count']) . ' recorded ' . ((int)$cs['unchanged_count'] === 1 ? 'field is' : 'fields are') . ' identical in both releases.</p>';
  }
  return $o . ul_facet('Pricing', $d['pricing']) . ul_facet('Integrations', $d['integrations']) . '<p class="upMeta">Pricing and integration events are those verified between the two release dates.</p></div></section>';
}
function ul_timeline(array $m): string {
  $items = [];
  foreach ($m['releases'] as $r) $items[] = ['date' => $r['release_date'], 'prec' => $r['date_precision'], 'kind' => 'Release', 'title' => $r['version_label'], 'href' => '#rel-' . $r['ref']];
  foreach ($m['events'] as $e) $items[] = ['date' => $e['event_date'], 'prec' => $e['date_precision'], 'kind' => up_event_type_label($e['event_type']), 'title' => $e['title'], 'href' => ''];
  usort($items, fn($a, $b) => [$a['date'], $a['kind'], $a['title']] <=> [$b['date'], $b['kind'], $b['title']]);
  if (!$items) return '<p>' . up_na('an evolution timeline') . '</p>';
  $by = []; foreach ($items as $it) $by[substr($it['date'], 0, 4)][] = $it;
  $o = '<div class="ulTimeline">';
  foreach ($by as $y => $list) {
    $o .= '<section class="ulYear" aria-labelledby="ul-y-' . $y . '"><h3 id="ul-y-' . $y . '">' . $y . ' <span class="upMeta">' . up_h(ul_count(count($list))) . '</span></h3><ol>';
    foreach ($list as $it) $o .= '<li><span class="ulKind">' . up_h($it['kind']) . '</span> ' . ($it['href'] !== '' ? '<a href="' . up_h($it['href']) . '">' . up_h($it['title']) . '</a>' : up_h($it['title'])) . ' <span class="upMeta">' . up_time_tag($it['date'], $it['prec']) . '</span></li>';
    $o .= '</ol></section>';
  }
  return $o . '</div>';
}
function ul_count(int $n): string { return $n . ($n === 1 ? ' item' : ' items'); }

function ul_render_index(array $m): string {
  $o = '<div class="upPage ulPage"><h1>AI Tool Lineage</h1><p class="upLead">Git-style release histories of AI models and products. Each release has a date and a source; relationships between releases and between products are drawn only when a source documents them, and each kind of relationship is kept separate.</p>';
  $o .= '<section aria-labelledby="ul-fam-h"><h2 id="ul-fam-h">Families with a verified release history</h2>';
  if (!$m['families']) return $o . '</section><section class="upEmpty"><h2>No verified releases yet</h2><p>No release has been verified so far, so there is no lineage to show. Nothing is guessed or filled in.</p></section></div>';
  $o .= '<div class="upScroll" role="region" tabindex="0" aria-label="Families table"><table class="upTable"><caption>Model families and products with verified releases</caption><thead><tr><th scope="col">Family or product</th><th scope="col">Verified releases</th><th scope="col">First release</th><th scope="col">Latest release</th></tr></thead><tbody>';
  foreach ($m['families'] as $f) $o .= '<tr><th scope="row"><a href="' . up_h(up_url(UL_SCRIPT, ['entity' => $f['slug']])) . '">' . up_h($f['name']) . '</a></th><td>' . $f['count'] . '</td><td>' . up_h($f['first']) . '</td><td>' . up_h($f['last']) . '</td></tr>';
  return $o . '</tbody></table></div></section>' . ul_legend(false) . '</div>';
}
function ul_render_entity(array $m): string {
  $ent = $m['entity']; $lin = $m['lineage']; $name = $ent['name']; $hasRel = (bool)$m['releases']; $hasEdges = (bool)$lin['entity_edges'];
  $o = '<div class="upPage ulPage"><nav class="upCrumbs" aria-label="Breadcrumb"><a href="/' . UL_SCRIPT . '">AI Tool Lineage</a></nav><h1>Release history of ' . up_h($name) . '</h1><p class="upLead">' . up_h(up_entity_type_label($ent['entity_type'])) . '. ';
  $o .= $hasRel ? up_h(count($m['releases']) . ' verified release' . (count($m['releases']) === 1 ? '' : 's')) . ' from ' . up_h($m['releases'][0]['release_date']) . ' to ' . up_h($m['releases'][count($m['releases']) - 1]['release_date']) . '.' : 'No verified release is recorded for this entry yet.';
  $o .= '</p>';
  if (trim((string)$ent['summary']) !== '') $o .= '<p>' . up_h($ent['summary']) . '</p>';
  if (!$hasRel && !$hasEdges && !$m['events']) return $o . '<section class="upEmpty"><h2>No verified history yet</h2><p>' . up_na('releases, relationships or events') . ' Nothing is guessed or filled in. This page appears in search results only once verified data exists.</p></section></div>';
  $o .= '<ul class="upJump"><li><a href="#graph">Branch graph</a></li><li><a href="#commits">Commit history</a></li><li><a href="#diff">Compare releases</a></li><li><a href="#timeline">Timeline</a></li><li><a href="#relationships">Relationships</a></li></ul>' . ul_legend(true);
  $o .= '<section id="graph" aria-labelledby="graph-h"><h2 id="graph-h">Release branch graph</h2>';
  if ($lin['nodes']) {
    $o .= '<p>Releases run from oldest on the left to newest on the right. Each dot is a verified release: select it to jump to its entry in the commit history.</p><div class="upScroll ulGraph" role="region" tabindex="0" aria-label="Release branch graph (scrolls sideways)">' . ul_svg_releases($lin, $ent['slug'], $name) . '</div>';
    if ($lin['truncated']) $o .= '<p class="upMeta">The graph is capped at ' . (int)$lin['node_cap'] . ' releases; more exist.</p>';
    if (!$lin['edges']) $o .= '<p class="upMeta">No release has a documented parent release, so no lines are drawn between the releases.</p>';
    $o .= ul_release_edge_table($lin);
  } else $o .= '<p>' . up_na('releases') . '</p>';
  $o .= '</section><section id="relationships" aria-labelledby="rel-h"><h2 id="rel-h">Relationships of ' . up_h($name) . '</h2>';
  if ($hasEdges) $o .= '<p>Earlier entities are on the left, later ones on the right. The arrows run from the earlier to the later entity.</p><div class="upScroll ulGraph" role="region" tabindex="0" aria-label="Entity lineage graph (scrolls sideways)">' . ul_svg_entities($lin, $ent['ref'], $name) . '</div>';
  $o .= ul_relationship_table($lin, $name) . '</section>';
  $o .= '<section id="commits" aria-labelledby="commits-h"><h2 id="commits-h">Commit history</h2>' . ($hasRel ? '<p>Verified releases, oldest first. Each entry shows its date, recorded changes, documented parent releases and source.</p>' . ul_commits($m) : '<p>' . up_na('releases') . '</p>') . '</section>';
  $o .= ul_diff($m);
  $o .= '<section id="timeline" aria-labelledby="timeline-h"><h2 id="timeline-h">Evolution timeline</h2><p>Verified releases and events of ' . up_h($name) . ', grouped by year.</p>' . ul_timeline($m) . '</section></div>';
  return $o;
}

// ---- request ---------------------------------------------------------------------------------------------------------------------------

$ulStatus = 200; $ulBody = ''; $robots = 'index'; $jsonld = [];
$title = 'AI Tool Lineage: Verified Release History of AI Models'; $meta_desc = 'Git-style release histories of AI models and products with dates, sources and documented lineage. Ancestry and succession are kept separate.';
$canonical = site_url('/' . UL_SCRIPT); $head_extra = up_head_extra();
$ulMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($ulMethod, ['GET', 'HEAD'], true)) { header('Allow: GET, HEAD'); http_response_code(405); exit('Method not allowed.'); }
if (up_rate_limit()) { header('Retry-After: 60'); $ulStatus = 429; $ulBody = up_error_body('Too many requests', 'Please wait a minute and try again.', '/' . UL_SCRIPT, 'Back to AI Tool Lineage'); }
else {
  [$ulP, $ulPerr] = up_params(['entity', 'a', 'b']);
  if ($ulPerr !== '') { $ulStatus = 400; $ulBody = up_error_body('That page could not be loaded', up_error_text($ulPerr), '/' . UL_SCRIPT, 'Open AI Tool Lineage'); }
  else {
    [$ulState, $ulM] = up_guard(fn(PDO $pdo) => ul_model($pdo, $ulP));
    if ($ulState === 'down') { $ulStatus = 503; header('Retry-After: 120'); $ulBody = up_error_body('Temporarily unavailable', 'The verified data could not be loaded right now. Please try again in a few minutes.', '/' . UL_SCRIPT, 'Try again'); }
    elseif ($ulState === 'empty') { $robots = 'noindex'; $ulBody = ul_render_index(['families' => []]); }
    elseif (isset($ulM['error'])) { $ulStatus = $ulM['status']; $ulBody = up_error_body($ulStatus === 404 ? 'Not found' : 'That page could not be loaded', up_error_text($ulM['error']), '/' . UL_SCRIPT, 'Open AI Tool Lineage'); }
    elseif ($ulM['mode'] === 'index') {
      $ulBody = ul_render_index($ulM); if (!$ulM['families']) $robots = 'noindex';
      else $jsonld = [['@type' => 'CollectionPage', '@id' => $canonical . '#webpage', 'url' => $canonical, 'name' => 'AI Tool Lineage', 'description' => $meta_desc, 'isPartOf' => ['@id' => site_url('/#website')]], up_crumbs([['Home', '/'], ['AI Tool Lineage', '/' . UL_SCRIPT]])];
    } else {
      $ent = $ulM['entity']; $ulBody = ul_render_entity($ulM); $n = count($ulM['releases']);
      $title = 'Release History of ' . $ent['name'] . ': AI Tool Lineage';
      $meta_desc = 'Verified release history of ' . $ent['name'] . ($n ? ': ' . $n . ' release' . ($n === 1 ? '' : 's') . ' with dates and sources.' : '.') . ' Technical ancestry and product succession are kept separate.';
      $canonical = site_url(up_url(UL_SCRIPT, ['entity' => $ent['slug']]));
      if (!$n && !$ulM['lineage']['entity_edges'] && !$ulM['events']) $robots = 'noindex';
      else {
        $list = []; foreach ($ulM['releases'] as $i => $r) $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $r['version_label'], 'description' => 'Verified release dated ' . up_iso_partial($r['release_date'], $r['date_precision'])];
        $jsonld = [['@type' => 'WebPage', '@id' => $canonical . '#webpage', 'url' => $canonical, 'name' => $title, 'description' => $meta_desc, 'about' => ['@type' => 'Thing', 'name' => $ent['name'], 'description' => (string)$ent['summary']], 'isPartOf' => ['@id' => site_url('/#website')]],
          up_crumbs([['Home', '/'], ['AI Tool Lineage', '/' . UL_SCRIPT], [$ent['name'], up_url(UL_SCRIPT, ['entity' => $ent['slug']])]])];
        if ($list) $jsonld[] = ['@type' => 'ItemList', 'name' => 'Verified releases of ' . $ent['name'], 'itemListOrder' => 'https://schema.org/ItemListOrderAscending', 'numberOfItems' => count($list), 'itemListElement' => $list];
      }
      if ($ulM['diff_error']) { $ulStatus = $ulM['diff_error']['status']; }
    }
  }
}
http_response_code($ulStatus);
if ($ulStatus >= 400 && $ulBody !== '' && strpos($ulBody, 'class="upError"') !== false) { $title = 'AI Tool Lineage'; $meta_desc = 'Git-style release histories of AI models and products with dates, sources and documented lineage.'; $canonical = site_url('/' . UL_SCRIPT); $jsonld = []; }
if ($ulStatus >= 400) $robots = 'noindex';
require __DIR__ . '/layout_top.php';
echo $ulBody;
echo up_page_script('lineage');
require __DIR__ . '/layout_bottom.php';
