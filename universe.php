<?php
/**
 * AI Universe hub (Phase 7): one landing page for the four verified-data experiences (graph, heatmap, lineage, battle arena).
 * Read only. Every status line is computed from the verified, current rows of the ledger (uni_public_* functions); nothing is estimated.
 * While the ledger holds no verified data the page says so and is noindex; it is listed in the sitemap only once verified data exists.
 */
require_once __DIR__ . '/uni_pages.php';

const UHUB_SCRIPT = 'universe.php';

/** Verified counts for the four experiences. @return array{entities:int,events:int,releases:int} */
function uhub_counts(PDO $pdo): array {
  return ['entities' => (int)uni_public_entities($pdo, [], 1, 1)['total'], 'events' => (int)uni_public_events($pdo, [], 1, 1)['total'], 'releases' => (int)uni_public_releases($pdo, [], 1, 1)['total']];
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
      $c && $c['releases'] > 0 ? uhub_plural($c['releases'], 'verified release', 'verified releases') : $none, $c && $c['releases'] > 0],
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
    $o .= '</ul><section class="upNotes" aria-labelledby="uhub-how"><h2 id="uhub-how">How the data is verified</h2><p>One person enters a fact with a source and the date it was retrieved; a different person verifies it. Only verified, current facts appear here. <a href="/methodology.php">Read the methodology</a>.</p></section></div>';
    $uhubBody = $o;
    if ($uhubData) $jsonld = [['@type' => 'WebPage', '@id' => $canonical . '#webpage', 'url' => $canonical, 'name' => 'AI Universe', 'description' => $meta_desc, 'isPartOf' => ['@id' => site_url('/#website')]], up_crumbs([['Home', '/'], ['AI Universe', '/' . UHUB_SCRIPT]])];
  }
}
http_response_code($uhubStatus);
if ($uhubStatus >= 400) { $title = 'AI Universe'; $robots = 'noindex'; }
require __DIR__ . '/layout_top.php';
echo $uhubBody;
require __DIR__ . '/layout_bottom.php';
