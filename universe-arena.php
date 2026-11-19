<?php
/**
 * AI Battle Arena (Phase 7): side-by-side comparison of 2 to 4 entities from VERIFIED data only (uni_public_compare plus uni_public_* reads for sources and release data).
 * Missing data is shown as "Unavailable: no verified data", never invented. No winner and no score is ever computed: a benchmark appears only as the value a verified source reports.
 * Factual verified evidence, editorial analysis and community opinion are separate sections; the latter two are empty for now.
 */
require_once __DIR__ . '/uni_pages.php';

const UA_SCRIPT = 'universe-arena.php';

function ua_model(PDO $pdo, array $slugs, string $note): array {
  [$list, $trunc] = up_entities_all($pdo);
  $m = ['status' => 200, 'mode' => 'select', 'entities' => $list, 'truncated' => $trunc, 'slugs' => $slugs, 'note' => $note, 'total' => count($list)];
  if (count($slugs) < 2) { if (count($slugs) === 1) $m['note'] = 'choose_more'; return $m; }
  if (count($slugs) > 4) return ['status' => 400, 'error' => 'count_invalid'] + $m;
  if (count(array_unique($slugs)) !== count($slugs)) return ['status' => 400, 'error' => 'duplicate'] + $m;
  $c = uni_public_compare($pdo, $slugs);
  if (isset($c['error'])) return ['status' => $c['error'] === 'entity_not_found' ? 404 : 400, 'error' => $c['error']] + $m;
  $extra = [];
  foreach ($c['entities'] as $e) {
    $s = $e['entity']['slug'];
    $rel = uni_public_releases($pdo, ['entity_slug' => $s], 1, 1);
    $dev = uni_public_edges($pdo, ['entity_slug' => $s, 'edge_type' => 'developed_by'], 1, 20);
    $int = uni_public_edges($pdo, ['entity_slug' => $s, 'edge_type' => 'integrates_with'], 1, 20);
    $extra[$s] = ['latest' => $rel['items'][0] ?? null, 'releases' => $rel['total'], 'developers' => array_values(array_filter($dev['items'], fn($ed) => $ed['from_slug'] === $s)), 'integrations' => $int['items'], 'integrations_total' => $int['total']];
  }
  return ['mode' => 'compare', 'compare' => $c['entities'], 'fact_matrix' => $c['fact_matrix'] ?? [], 'extra' => $extra] + $m;
}

function ua_names(array $m): array { return array_map(fn($e) => $e['entity']['name'], $m['compare']); }
function ua_cols(array $m): string { $o = '<tr><td class="upCorner"><span class="sr">Field</span></td>'; foreach ($m['compare'] as $e) $o .= '<th scope="col">' . up_h($e['entity']['name']) . '<br><span class="upMeta">' . up_h(up_entity_type_label($e['entity']['type'])) . '</span></th>'; return $o . '</tr>'; }
/** One side-by-side table. $rows is [row label => callable(entity slug, entity fields): cell html]. */
function ua_table(array $m, string $caption, array $rows): string {
  $o = '<div class="upScroll uaScroll" role="region" tabindex="0" aria-label="' . up_h($caption) . ' (scrolls sideways on small screens)"><table class="upTable uaTable"><caption>' . up_h($caption) . '</caption><thead>' . ua_cols($m) . '</thead><tbody>';
  foreach ($rows as $label => $fn) { $o .= '<tr><th scope="row">' . up_h($label) . '</th>'; foreach ($m['compare'] as $e) $o .= '<td>' . $fn($e['entity']['slug'], $e['fields']) . '</td>'; $o .= '</tr>'; }
  return $o . '</tbody></table></div>';
}
function ua_release_source(?array $rel): array { return $rel ? [['url' => $rel['source_url'], 'publisher' => $rel['source_publisher'], 'retrieved_at' => $rel['source_retrieved_at']]] : []; }
/** One verified fact as a cell fragment: value (+ unit), benchmark metric / version, conditions, release, observation date and its sources with retrieval date. */
function ua_fact_html(array $f): string {
  $val = $f['value_num'] !== null ? $f['value_num'] . ' ' . (string)$f['unit'] : (string)$f['value_text'];
  $o = '<div class="uaFact"><p class="uaFactValue">' . up_h($val) . '</p>';
  if ($f['benchmark']) $o .= '<p class="upMeta">Benchmark: ' . up_h($f['benchmark']['name']) . ' · Metric: ' . up_h($f['benchmark']['metric']) . ' · Version: ' . up_h($f['benchmark']['version']) . '</p>';
  if ($f['conditions'] !== null) $o .= '<p class="upMeta">Conditions: ' . up_h($f['conditions']) . '</p>';
  if ($f['release'] !== null) $o .= '<p class="upMeta">Release: ' . up_h($f['release']) . '</p>';
  return $o . '<p class="upMeta">As of ' . up_time_tag($f['observed_on'], $f['date_precision']) . '</p>' . up_sources_html($f['sources']) . '</div>';
}
/** Side-by-side table of the verified fact rows of one type. A row that has data for no entity is never produced; a cell without verified data reads "Unavailable: no verified data". Differences are flagged in words, nobody wins. */
function ua_fact_section(array $m, string $type, string $caption, string $emptyLabel): string {
  $rows = $m['fact_matrix'][$type]['rows'] ?? [];
  if (!$rows) return ua_table($m, $caption, [$emptyLabel => fn($s, $f) => up_na($emptyLabel)]);
  $o = '<div class="upScroll uaScroll" role="region" tabindex="0" aria-label="' . up_h($caption) . ' (scrolls sideways on small screens)"><table class="upTable uaTable"><caption>' . up_h($caption) . '</caption><thead>' . ua_cols($m) . '</thead><tbody>';
  foreach ($rows as $r) {
    $label = up_h($r['key']) . ($r['benchmark'] ? '<br><span class="upMeta">' . up_h($r['benchmark']['name']) . ' · ' . up_h($r['benchmark']['metric']) . '</span>' : '');
    $flag = !$r['comparable'] ? '<br><span class="uaDiffFlag uaNoCompare">Different benchmark version or conditions: not comparable</span>' : ($r['differs'] ? '<br><span class="uaDiffFlag">Values differ between entities</span>' : '');
    $o .= '<tr' . ($r['differs'] ? ' class="uaDiff"' : '') . '><th scope="row">' . $label . $flag . '</th>';
    foreach ($m['compare'] as $e) { $cell = $r['cells'][$e['entity']['slug']] ?? []; $o .= '<td>' . ($cell ? implode('', array_map('ua_fact_html', $cell)) : up_na($r['key'])) . '</td>'; }
    $o .= '</tr>';
  }
  return $o . '</tbody></table></div>';
}
function ua_from_release(array $m, string $key): callable {
  return function (string $slug, array $f) use ($m, $key) {
    $rel = $m['extra'][$slug]['latest']; $cs = $rel['change_set'] ?? null;
    if (!is_array($cs) || !array_key_exists($key, $cs)) return up_na($key);
    return '<p>' . up_h(up_value_text($cs[$key])) . '</p><p class="upMeta">Recorded in release ' . up_h($rel['version_label']) . ' (' . up_time_tag($rel['release_date'], $rel['date_precision']) . ')</p>' . up_sources_html(ua_release_source($rel));
  };
}
function ua_edge_list(array $edges, string $slug, int $total): string {
  if (!$edges) return up_na();
  $o = '<ul class="upList">';
  foreach ($edges as $ed) { $other = $ed['from_slug'] === $slug ? $ed['to_name'] : $ed['from_name']; $o .= '<li>' . up_h($other) . up_sources_html($ed['sources'], 'upSources upInline') . '</li>'; }
  return $o . '</ul>' . ($total > count($edges) ? '<p class="upMeta">Showing ' . count($edges) . ' of ' . $total . '.</p>' : '');
}
function ua_render_form(array $m): string {
  $o = '<form class="uaForm" method="get" action="/' . UA_SCRIPT . '" aria-labelledby="ua-pick-h"><h2 id="ua-pick-h">Choose what to compare</h2><p>Pick two to four different verified entities. Only entities with verified, sourced data are listed.</p><div class="upGrid">';
  for ($i = 0; $i < 4; $i++) {
    $sel = $m['slugs'][$i] ?? ''; $o .= '<div class="upField"><label for="ua-' . $i . '">Entity ' . ($i + 1) . ($i < 2 ? ' (required)' : ' (optional)') . '</label><select id="ua-' . $i . '" name="' . ['a', 'b', 'c', 'd'][$i] . '"' . ($i < 2 ? ' required' : '') . ' data-ua-slot><option value="">' . ($i < 2 ? 'Choose an entity' : 'None') . '</option>';
    foreach ($m['entities'] as $e) $o .= '<option value="' . up_h($e['slug']) . '"' . ($sel === $e['slug'] ? ' selected' : '') . '>' . up_h($e['name'] . ' (' . up_entity_type_label($e['entity_type']) . ')') . '</option>';
    $o .= '</select></div>';
  }
  $o .= '</div><p class="upActions"><button class="btn" type="submit">Compare</button> <a class="btn secondary" href="/' . UA_SCRIPT . '">Clear</a></p><p class="upMeta upUniq" role="status" aria-live="polite" data-ua-msg></p>' . ($m['truncated'] ? '<p class="upMeta">Only the first 300 entities are offered in the lists.</p>' : '') . '</form>';
  return $o;
}
function ua_render_select(array $m): string {
  $o = '<div class="upPage uaPage"><h1>AI Battle Arena</h1><p class="upLead">Compare two to four AI products or models side by side using verified, sourced data only. Where we have no verified data, we say so. We never invent a value and we never declare a winner.</p>';
  if ($m['total'] < 2) return $o . '<section class="upEmpty"><h2>Not enough verified entities yet</h2><p>At least two verified entities are needed for a comparison. Nothing is guessed or filled in.</p></section></div>';
  if ($m['note'] === 'choose_more') $o .= '<p class="upErrorNote" role="alert">Choose at least two different entities to compare.</p>';
  return $o . ua_render_form($m) . ua_how() . '</div>';
}
function ua_how(): string {
  return '<section class="upNotes" aria-labelledby="ua-how-h"><h2 id="ua-how-h">How comparisons work</h2><ul><li>Factual verified evidence comes only from records that were checked against a source by a second person.</li><li>Every value shows its source and the date we retrieved it.</li><li>A field with no verified data reads “Unavailable: no verified data”. It is never filled in from guesses.</li><li>AIHubpedia does not score or rank entities here. A benchmark result appears only as the figure a verified source reports.</li><li>Editorial analysis and community opinion are separate from the facts and are labelled as such.</li></ul></section>';
}
function ua_render_compare(array $m, string $shareUrl): string {
  $names = ua_names($m); $o = '<div class="upPage uaPage"><nav class="upCrumbs" aria-label="Breadcrumb"><a href="/' . UA_SCRIPT . '">AI Battle Arena</a></nav><h1>' . up_h(implode(' vs ', $names)) . '</h1><p class="upLead">A side-by-side comparison using verified, sourced data only. No winner is declared.</p>';
  $o .= '<details class="uaChange"><summary>Change the entities</summary>' . ua_render_form($m) . '</details>';
  $o .= '<section class="uaEvidence" aria-labelledby="ua-fact-h"><h2 id="ua-fact-h">Factual verified evidence</h2><p class="upBadge">Verified fact</p><p>Everything in this section was verified against a source. Missing data is marked unavailable.</p>';
  $o .= '<section aria-labelledby="ua-ov-h"><h3 id="ua-ov-h">Overview</h3>' . ua_table($m, 'Overview of the compared entities', [
    'Summary' => fn($s, $f) => $f['summary']['available'] ? '<p>' . up_h($f['summary']['value']) . '</p>' : up_na('summary'),
    'Developer' => fn($s, $f) => ua_edge_list($m['extra'][$s]['developers'], $s, count($m['extra'][$s]['developers'])),
    'Entity sources' => fn($s, $f) => $f['sources']['available'] ? up_sources_html($f['sources']['value']) : up_na('sources')]) . '</section>';
  $o .= '<section aria-labelledby="ua-rel-h"><h3 id="ua-rel-h">Releases and activity</h3>' . ua_table($m, 'Releases and activity', [
    'Latest verified release' => function ($s, $f) { $v = $f['latest_release']; return $v['available'] ? '<p>' . up_h($v['value']['version_label']) . '</p><p class="upMeta">' . up_time_tag($v['value']['release_date'], $v['value']['date_precision']) . '</p>' . up_sources_html([$v['value']['source']]) : up_na('latest release'); },
    'Verified releases' => fn($s, $f) => $f['release_count']['available'] ? up_h((string)$f['release_count']['value']) : up_na('release count'),
    'Latest verified event' => function ($s, $f) { $v = $f['latest_event']; return $v['available'] ? '<p>' . up_h($v['value']['title']) . '</p><p class="upMeta">' . up_h(up_event_type_label($v['value']['event_type'])) . ' · ' . up_time_tag($v['value']['event_date'], 'day') . '</p>' . up_sources_html($v['value']['sources']) : up_na('latest event'); },
    'Version differences' => function ($s, $f) use ($m) { $n = $m['extra'][$s]['releases']; return $n >= 2 ? '<a href="' . up_h(up_url('universe-lineage.php', ['entity' => $s]) . '#diff') . '">Compare two versions<span class="sr"> of ' . up_h($s) . '</span></a>' : up_na('a version comparison') . ' <span class="upMeta">Needs at least two verified releases.</span>'; }]) . '</section>';
  $o .= '<section aria-labelledby="ua-price-h"><h3 id="ua-price-h">Pricing</h3>' . ua_table($m, 'Pricing', ['Pricing' => function ($s, $f) use ($m) {
    $p = $f['pricing']; if (!$p['available']) return up_na('pricing'); $v = $p['value'];
    if ($v['from'] === 'latest_release_change_set') return '<p>' . up_h(up_value_text($v['value'])) . '</p><p class="upMeta">Recorded in the latest verified release</p>' . up_sources_html(ua_release_source($m['extra'][$s]['latest']));
    return '<p>' . up_h($v['title']) . '</p><p class="upMeta">Pricing change · ' . up_time_tag($v['date'], 'day') . '</p>' . up_sources_html($v['sources']);
  }]) . '<h4>Verified pricing facts</h4><p class="upMeta">Each price states its currency or unit and its source. Prices from different dates or plans are separate rows.</p>' . ua_fact_section($m, 'pricing', 'Verified pricing facts', 'pricing facts') . '</section>';
  $o .= '<section aria-labelledby="ua-feat-h"><h3 id="ua-feat-h">Features</h3>' . ua_fact_section($m, 'feature', 'Features', 'features') . '<p class="upMeta">Also recorded in the latest verified release (free-form release notes, shown as recorded; they are not part of the fact comparison above).</p>' . ua_table($m, 'Features recorded in release notes', ['Features' => ua_from_release($m, 'features')]) . '</section>';
  $o .= '<section aria-labelledby="ua-plat-h"><h3 id="ua-plat-h">Platforms</h3>' . ua_fact_section($m, 'platform', 'Platforms', 'platforms') . '<p class="upMeta">Also recorded in the latest verified release (free-form release notes, shown as recorded; they are not part of the fact comparison above).</p>' . ua_table($m, 'Platforms recorded in release notes', ['Platforms' => ua_from_release($m, 'platforms')]) . '</section>';
  $o .= '<section aria-labelledby="ua-int-h"><h3 id="ua-int-h">Integrations</h3>' . ua_table($m, 'Integrations', ['Integrations' => fn($s, $f) => ua_edge_list($m['extra'][$s]['integrations'], $s, $m['extra'][$s]['integrations_total'])]) . '<h4>Verified integration facts</h4>' . ua_fact_section($m, 'integration', 'Verified integration facts', 'integration facts') . '</section>';
  $o .= '<section aria-labelledby="ua-lim-h"><h3 id="ua-lim-h">Limits</h3>' . ua_fact_section($m, 'limit', 'Limits', 'limits') . '</section>';
  $o .= '<section aria-labelledby="ua-bench-h"><h3 id="ua-bench-h">Benchmarks</h3><p>A benchmark appears only as the figure a verified source reports, with its metric, benchmark version and conditions. AIHubpedia adds no score, rank or winner. <strong>Numbers from different conditions, versions or dates are not comparable.</strong> If a cell is unavailable, there is no verified benchmark evidence for it.</p>' . ua_fact_section($m, 'benchmark', 'Benchmarks', 'benchmarks') . '<p class="upMeta">Also recorded in the latest verified release (free-form release notes, shown as recorded; they are not part of the fact comparison above).</p>' . ua_table($m, 'Benchmarks recorded in release notes', ['Benchmarks' => ua_from_release($m, 'benchmarks')]) . '</section></section>';
  $o .= '<section class="uaEditorial" aria-labelledby="ua-ed-h"><h2 id="ua-ed-h">Editorial analysis</h2><p class="upBadge">Editorial opinion, not a verified fact</p><div class="upEmpty"><p>No editorial analysis has been published for this comparison yet. When it exists it will be written by AIHubpedia editors, labelled as opinion, and kept apart from the verified evidence above.</p></div></section>';
  $o .= '<section class="uaCommunity" aria-labelledby="ua-com-h"><h2 id="ua-com-h">Community opinion</h2><p class="upBadge">Community opinion, not a verified fact</p><div class="upEmpty"><p>No community opinion has been collected for these entities yet. Nothing is shown here until real, moderated contributions exist.</p></div></section>';
  $o .= '<section class="upShare" aria-labelledby="ua-share-h"><h2 id="ua-share-h">Share this comparison</h2><div class="upCopy"><label for="ua-share">Address of this comparison</label><input class="input" id="ua-share" type="text" readonly value="' . up_h($shareUrl) . '"><button class="btn secondary js-only" type="button" data-copy-from="ua-share">Copy link</button></div><p class="upMeta upCopied" role="status" aria-live="polite" data-copy-status></p></section>';
  return $o . ua_how() . '</div>';
}

// ---- request ---------------------------------------------------------------------------------------------------------------------------

$uaStatus = 200; $uaBody = ''; $robots = 'index'; $jsonld = [];
$title = 'AI Battle Arena: Compare AI Tools With Verified Data'; $meta_desc = 'Compare two to four AI products or models side by side using only verified, sourced data. Missing data is marked unavailable and no winner is declared.';
$canonical = site_url('/' . UA_SCRIPT); $head_extra = up_head_extra(); $uaShare = $canonical;
$uaMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($uaMethod, ['GET', 'HEAD'], true)) { header('Allow: GET, HEAD'); http_response_code(405); exit('Method not allowed.'); }
if (up_rate_limit()) { header('Retry-After: 60'); $uaStatus = 429; $uaBody = up_error_body('Too many requests', 'Please wait a minute and try again.', '/' . UA_SCRIPT, 'Back to the arena'); }
else {
  [$uaP, $uaPerr] = up_params(['e', 'a', 'b', 'c', 'd']);
  $uaSlugs = []; $uaBad = $uaPerr !== '' ? $uaPerr : '';
  if ($uaBad === '') {
    $raw = [];
    if (isset($uaP['e'])) $raw = explode(',', $uaP['e']);
    foreach (['a', 'b', 'c', 'd'] as $k) if (isset($uaP[$k])) $raw[] = $uaP[$k];
    foreach ($raw as $s) { if ($s === '') continue; if (!up_slug_ok($s)) { $uaBad = 'parameter_invalid'; break; } $uaSlugs[] = $s; }
    if ($uaBad === '' && count($uaSlugs) > 4) $uaBad = 'count_invalid';
    if ($uaBad === '' && count(array_unique($uaSlugs)) !== count($uaSlugs)) $uaBad = 'duplicate';
    if ($uaBad === '' && (isset($uaP['a']) || isset($uaP['b']) || isset($uaP['c']) || isset($uaP['d'])) && !isset($uaP['e'])) { header('Location: ' . up_url(UA_SCRIPT, ['e' => implode(',', $uaSlugs)]), true, 303); exit; }
    if ($uaBad === '' && isset($uaP['e']) && (isset($uaP['a']) || isset($uaP['b']) || isset($uaP['c']) || isset($uaP['d']))) $uaBad = 'parameter_invalid';
  }
  if ($uaBad !== '') { $uaStatus = 400; $uaBody = up_error_body('That comparison could not be loaded', up_error_text($uaBad), '/' . UA_SCRIPT, 'Choose entities again'); }
  else {
    [$uaState, $uaM] = up_guard(fn(PDO $pdo) => ua_model($pdo, $uaSlugs, ''));
    if ($uaState === 'down') { $uaStatus = 503; header('Retry-After: 120'); $uaBody = up_error_body('Temporarily unavailable', 'The verified data could not be loaded right now. Please try again in a few minutes.', '/' . UA_SCRIPT, 'Try again'); }
    elseif ($uaState === 'empty') { $robots = 'noindex'; $uaBody = ua_render_select(['total' => 0, 'note' => '', 'entities' => [], 'slugs' => [], 'truncated' => false]); }
    elseif (isset($uaM['error'])) { $uaStatus = $uaM['status']; $uaBody = up_error_body($uaStatus === 404 ? 'Not found' : 'That comparison could not be loaded', up_error_text($uaM['error']), '/' . UA_SCRIPT, 'Choose entities again'); }
    elseif ($uaM['mode'] === 'select') {
      $uaBody = ua_render_select($uaM);
      if ($uaM['total'] < 2 || $uaSlugs) $robots = 'noindex';
      else $jsonld = [['@type' => 'WebPage', '@id' => $canonical . '#webpage', 'url' => $canonical, 'name' => 'AI Battle Arena', 'description' => $meta_desc, 'isPartOf' => ['@id' => site_url('/#website')]], up_crumbs([['Home', '/'], ['AI Battle Arena', '/' . UA_SCRIPT]])];
    } else {
      $names = ua_names($uaM); $sorted = $uaSlugs; sort($sorted);
      $canonical = site_url(up_url(UA_SCRIPT, ['e' => implode(',', $sorted)])); $uaShare = site_url(up_url(UA_SCRIPT, ['e' => implode(',', $uaSlugs)]));
      $title = 'Compare ' . implode(' vs ', $names) . ': Verified AI Comparison';
      $meta_desc = 'Side-by-side comparison of ' . implode(', ', $names) . ' using only verified, sourced data. Missing data is marked unavailable and no winner is declared.';
      $uaBody = ua_render_compare($uaM, $uaShare);
      $items = []; foreach ($uaM['compare'] as $i => $e) { $it = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $e['entity']['name']]; if ($e['fields']['summary']['available']) $it['description'] = $e['fields']['summary']['value']; $items[] = $it; }
      $jsonld = [['@type' => 'WebPage', '@id' => $canonical . '#webpage', 'url' => $canonical, 'name' => $title, 'description' => $meta_desc, 'isPartOf' => ['@id' => site_url('/#website')]],
        ['@type' => 'ItemList', 'name' => 'Compared entities', 'numberOfItems' => count($items), 'itemListElement' => $items], up_crumbs([['Home', '/'], ['AI Battle Arena', '/' . UA_SCRIPT], [implode(' vs ', $names), up_url(UA_SCRIPT, ['e' => implode(',', $sorted)])]])];
    }
  }
}
http_response_code($uaStatus);
if ($uaStatus >= 400) { $title = 'AI Battle Arena'; $meta_desc = 'Compare two to four AI products or models side by side using only verified, sourced data.'; $canonical = site_url('/' . UA_SCRIPT); $jsonld = []; $robots = 'noindex'; }
require __DIR__ . '/layout_top.php';
echo $uaBody;
echo up_page_script('arena');
require __DIR__ . '/layout_bottom.php';
