<?php
/**
 * AI Universe hub (Phase 7): one landing page for the four verified-data experiences (graph, heatmap, lineage, battle arena).
 * Read only. Every status line is computed from the verified, current rows of the ledger (uni_public_* functions); nothing is estimated.
 * While the ledger holds no verified data the page says so and is noindex; it is listed in the sitemap only once verified data exists.
 */
require_once __DIR__ . '/uni_pages.php';

const UHUB_SCRIPT = 'universe.php';

/** Verified counts for the four experiences. @return array{entities:int,events:int,releases:int,ancestry:int} */
function uhub_counts(PDO $pdo): array {
  return ['entities' => (int)uni_public_entities($pdo, [], 1, 1)['total'], 'events' => (int)uni_public_events($pdo, [], 1, 1)['total'], 'releases' => (int)uni_public_releases($pdo, [], 1, 1)['total'],
    'ancestry' => (int)$pdo->query("SELECT COUNT(*) FROM uni_edge ed " . uni_live_entity_join('ed', 'from_entity_id', 'fe') . ' ' . uni_live_entity_join('ed', 'to_entity_id', 'te') . " WHERE ed.state = 'verified' AND ed.is_current = 1 AND ed.edge_type IN ('derived_from','successor_of','renamed_from')")->fetchColumn()];
}
/** Verified-only overview: counts by type, latest verified events, most connected entities (by verified relationships; a mechanical count, not an editorial pick). */
function uhub_extras(PDO $pdo): array {
  $types = []; foreach (['company' => 'Companies', 'product' => 'Products', 'model' => 'Models', 'version' => 'Versions'] as $t => $l) $types[$t] = [$l, (int)uni_public_entities($pdo, ['type' => $t], 1, 1)['total']];
  $recent = uni_public_events($pdo, [], 1, 8)['items'];
  $j = uni_live_entity_join('ed', 'from_entity_id', 'fe') . ' ' . uni_live_entity_join('ed', 'to_entity_id', 'te'); $w = "WHERE ed.state = 'verified' AND ed.is_current = 1";
  $s = $pdo->query("SELECT slug, name, entity_type, COUNT(*) AS n FROM (SELECT fe.slug, fe.name, fe.entity_type FROM uni_edge ed $j $w UNION ALL SELECT te.slug, te.name, te.entity_type FROM uni_edge ed $j $w) x GROUP BY slug, name, entity_type ORDER BY n DESC, name LIMIT 8");
  return ['types' => $types, 'recent' => $recent, 'connected' => $s->fetchAll(PDO::FETCH_ASSOC)];
}
function uhub_plural(int $n, string $one, string $many): string { return number_format($n) . ' ' . ($n === 1 ? $one : $many); }

/** The four experiences with an honest status. $c is null while the ledger is empty or not installed. */
function uhub_cards(?array $c): array {
  $none = 'No verified data yet';
  return [
    ['/universe-graph.php', 'Graph', 'Verified companies, products, models and versions, and how they relate. Every relationship cites its source.',
      $c && $c['entities'] > 0 ? uhub_plural($c['entities'], 'verified entity', 'verified entities') : $none, $c && $c['entities'] > 0],
    ['/universe-heatmap.php', 'Activity heatmap', 'A calendar of verified releases, launches and updates by day. Days without verified activity stay empty.',
      $c && $c['events'] > 0 ? uhub_plural($c['events'], 'verified event', 'verified events') : $none, $c && $c['events'] > 0],
    ['/universe-lineage.php', 'Release lineage', 'Release histories of AI models and products with dates and sources. Technical ancestry and product succession are kept apart.',
      $c && $c['releases'] > 0 ? uhub_plural($c['releases'], 'verified release', 'verified releases') : ($c && ($c['ancestry'] ?? 0) > 0 ? uhub_plural((int)$c['ancestry'], 'documented ancestry or succession link', 'documented ancestry or succession links') : $none), $c && ($c['releases'] > 0 || ($c['ancestry'] ?? 0) > 0)],
    ['/universe-arena.php', 'Battle Arena', 'Compare two to four products or models side by side. Missing data is marked unavailable and no winner is declared.',
      $c && $c['entities'] >= 2 ? 'Ready: ' . number_format($c['entities']) . ' verified entities to compare' : ($c && $c['entities'] === 1 ? 'Needs at least two verified entities (1 so far)' : $none), $c && $c['entities'] >= 2],
  ];
}

$uhubMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($uhubMethod, ['GET', 'HEAD'], true)) { header('Allow: GET, HEAD'); http_response_code(405); exit('Method not allowed.'); }
$title = 'AI Universe: Verified AI Graph, Heatmap, Lineage and Arena';
$meta_desc = 'Four ways to explore AI with verified data only: a relationship graph, a release heatmap, release lineage and a side-by-side battle arena. Every fact is sourced and checked by a second person.';
$canonical = site_url('/' . UHUB_SCRIPT); $head_extra = up_head_extra(); $robots = 'noindex'; $uhubStatus = 200;
if (up_rate_limit()) { header('Retry-After: 60'); $uhubStatus = 429; $uhubBody = up_error_body('Too many requests', 'Please wait a minute and try again.', '/' . UHUB_SCRIPT, 'Back to AI Universe'); }
else {
  [$uhubState, $uhubC] = up_guard('uhub_counts');
  if ($uhubState === 'down') { $uhubStatus = 503; header('Retry-After: 120'); $uhubBody = up_error_body('Temporarily unavailable', 'The verified data could not be loaded right now. Nothing is shown rather than something unverified. Please try again in a few minutes.', '/' . UHUB_SCRIPT, 'Try again'); }
  else {
    $uhubData = $uhubState === 'ok' && ($uhubC['entities'] > 0 || $uhubC['events'] > 0 || $uhubC['releases'] > 0);
    if ($uhubData) $robots = 'index';
    $o = '<div class="upPage uhubPage"><h1>AI Universe</h1><p class="upLead">Four ways to explore AI products, models and releases. Everything here was entered with a source and checked by a second person; there are no estimates, scores or machine-generated links.</p>';
    if (!$uhubData) $o .= '<section class="upEmpty" aria-labelledby="uhub-empty"><h2 id="uhub-empty">No verified data yet</h2><p>The ledger is empty until the first facts have been entered with a source and verified. The four experiences below are ready and stay empty rather than show anything unverified.</p></section>';
    $o .= '<ul class="uhubGrid">';
    foreach (uhub_cards($uhubData ? $uhubC : null) as $i => [$href, $name, $desc, $status, $has]) {
      $o .= '<li class="uhubCard"><h2 id="uhub-h' . $i . '"><a href="' . up_h($href) . '">' . up_h($name) . '</a></h2><p>' . up_h($desc) . '</p>'
        . '<p class="uhubStatus' . ($has ? ' uhubStatus--ok' : '') . '"><span class="sr">Status: </span>' . up_h($status) . '</p></li>';
    }
    $o .= '</ul>';
    if ($uhubData) {
      [$uhubXs, $x] = up_guard('uhub_extras');
      if ($uhubXs === 'ok' && $x) {
        $o .= '<section class="uhubOverview" aria-labelledby="uhub-browse"><h2 id="uhub-browse">Browse verified entities</h2>'
          . '<form method="get" action="/universe-graph.php" role="search" aria-label="Search verified entities"><label for="uhub-q">Name contains</label> <input id="uhub-q" name="q" type="search" maxlength="80" style="min-height:44px;min-width:44px;padding:.4rem .8rem;box-sizing:border-box"> <button type="submit" style="min-height:44px;min-width:44px;padding:.4rem .8rem;box-sizing:border-box">Search</button></form><ul class="uhubTypes">';
        foreach ($x['types'] as $t => [$l, $n]) if ($n > 0) $o .= '<li><a href="' . up_h('/universe-graph.php?type=' . $t) . '">' . up_h($l) . '</a> (' . number_format($n) . ')</li>';
        $o .= '</ul>';
        if ($x['connected']) { $o .= '<h3>Most connected verified entities</h3><ul class="uhubList">'; foreach ($x['connected'] as $e) $o .= '<li><a href="' . up_h('/universe-graph.php?slug=' . rawurlencode((string)$e['slug'])) . '">' . up_h($e['name']) . '</a> (' . up_h($e['entity_type']) . ', ' . (int)$e['n'] . ' verified ' . ((int)$e['n'] === 1 ? 'relationship' : 'relationships') . ')</li>'; $o .= '</ul>'; }
        if ($x['recent']) { $o .= '<h3>Recent verified events</h3><ul class="uhubList">'; foreach ($x['recent'] as $e) $o .= '<li><time datetime="' . up_h(substr((string)$e['event_date'], 0, 10)) . '">' . up_h(substr((string)$e['event_date'], 0, 10)) . '</time> ' . up_h($e['title']) . ' <span class="small">(' . up_h((string)($e['entity_name'] ?? '')) . ')</span></li>'; $o .= '</ul>'; }
        $o .= '</section>';
      }
    }
    $o .= '<section class="upNotes" aria-labelledby="uhub-how"><h2 id="uhub-how">How the data is verified</h2><p>One person enters a fact with a source and the date it was retrieved; a different person verifies it. Only verified, current facts appear here. <a href="/methodology.php">Read the methodology</a>.</p></section></div>';
    $uhubBody = $o;
    if ($uhubData) $jsonld = [['@type' => 'WebPage', '@id' => $canonical . '#webpage', 'url' => $canonical, 'name' => 'AI Universe', 'description' => $meta_desc, 'isPartOf' => ['@id' => site_url('/#website')]], up_crumbs([['Home', '/'], ['AI Universe', '/' . UHUB_SCRIPT]])];
  }
}
http_response_code($uhubStatus);
if ($uhubStatus >= 400) { $title = 'AI Universe'; $robots = 'noindex'; }
require __DIR__ . '/layout_top.php';
echo $uhubBody;
require __DIR__ . '/layout_bottom.php';
