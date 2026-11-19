<?php
/**
 * AI Universe graph (Phase 7): verified entities and their sourced relationships.
 * Server-rendered and readable without JavaScript (progressive enhancement): the interactive graph (assets/universe/) is layered on top and talks to the
 * public read API universe-api.php (verified + current rows only). Deep links: ?slug=<entity>; ?page=<n> pages the entity list.
 * Honest states: no verified data yet (noindex), data service unavailable (503, noindex), unknown entity (404, noindex). Synthetic data is never offered here.
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/uni_core.php';

const UG_PER_PAGE = 50;
const UG_EDGES_SHOWN = 100;
function ug_day($s): string { return substr((string)$s, 0, 10); }
function ug_url(array $q = []): string {
  $q = array_filter($q, fn($x) => $x !== null && $x !== '' && $x !== 1);
  return '/universe-graph.php' . ($q ? '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986) : '');
}
function ug_link_ok(string $u): bool { return preg_match('#^https?://#i', $u) === 1 && uni_url_ok($u); }

$slugRaw = $_GET['slug'] ?? null;
$slug = null; $slugBad = false;
if ($slugRaw !== null && $slugRaw !== '') {
  if (is_string($slugRaw) && preg_match('/^[a-z0-9._-]{2,120}$/D', $slugRaw)) $slug = $slugRaw; else $slugBad = true;
}
$pageNo = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => UNI_MAX_PAGE]]);
if ($pageNo === false) $pageNo = 1;

$state = 'ok'; $status = 200;
$list = ['items' => [], 'total' => 0, 'has_more' => false]; $detail = null; $graph = null;
try {
  $pdo = db();
  $list = uni_public_entities($pdo, [], $pageNo, UG_PER_PAGE);
  if ($list['total'] === 0) { $state = 'empty'; }
  elseif ($list['items'] === []) { $state = 'notfound'; $status = 404; }   // page number beyond the last page
  if ($state === 'ok' && $slugBad) { $state = 'notfound'; $status = 404; }
  if ($state === 'ok' && $slug !== null) {
    $detail = uni_public_entity_detail($pdo, $slug);
    if ($detail === null) { $state = 'notfound'; $status = 404; }
    else $graph = uni_public_graph($pdo, $slug, UG_EDGES_SHOWN, 0, null);
  }
} catch (Throwable $e) {
  if ($e instanceof PDOException && in_array((string)$e->getCode(), ['42S02', '42S22'], true)) { $state = 'empty'; $status = 200; $list = ['items' => [], 'total' => 0, 'has_more' => false]; }   /* the additive ledger migration is not installed yet: honest empty state, never a 5xx */
  else { sec_log_exception($e, 'universe-graph'); $state = 'unavailable'; $status = 503; }
}
if ($status !== 200) http_response_code($status);

// layout_top.php reads $title / $meta_desc / $robots / $canonical from this scope (and overwrites $v): set them first, never reuse $v afterwards.
$hasData = $state === 'ok' && $list['total'] > 0;
$robots = $hasData ? 'index' : 'noindex';
$title = $detail ? mb_strimwidth((string)$detail['name'], 0, 40, '...', 'UTF-8') . ' in the AI Universe graph' : 'AI Universe graph: verified AI entities and relationships';
$meta_desc = $detail
  ? 'Verified relationships of ' . mb_strimwidth((string)$detail['name'], 0, 60, '...', 'UTF-8') . ' in the AI Universe: each one with its source and the date the source was retrieved.'
  : 'Explore verified AI companies, products, models and versions and how they relate. Every relationship is verified by a second person and cites its source.';
$crumbs = [['Home', site_url('/')], ['AI Universe graph', $detail ? site_url(ug_url()) : null]];
if ($detail) $crumbs[] = [(string)$detail['name'], null];
$jsonld = [seo_breadcrumb_node($crumbs)];
$cfg = ['api' => '/universe-api.php', 'slug' => $detail ? $slug : null, 'syncQuery' => true, 'staticId' => 'uni-static'];
$asset = function (string $f): string { return '/assets/universe/' . $f . '?v=' . (@filemtime(__DIR__ . '/assets/universe/' . $f) ?: 1); };
if ($state === 'ok') $head_extra = '<link rel="stylesheet" href="' . h($asset('graph.css')) . '">';
require __DIR__ . '/layout_top.php';
?>
<?= render_breadcrumbs($crumbs) ?>
<header class="pageHero" style="padding-top:0">
  <h1>AI Universe graph</h1>
  <p class="lead">Verified AI companies, products, models and versions, and how they relate. Every relationship was entered with a source and verified by a second person; the graph shows nothing else.</p>
</header>

<?php if ($state === 'empty'): ?>
  <section class="panel" aria-labelledby="ugEmpty">
    <h2 id="ugEmpty" style="margin-top:0">No verified data yet</h2>
    <p>The graph stays empty until the first entities and relationships have been entered with a source and verified by a second person. We do not fill it with estimates, imported lists or machine-generated links.</p>
    <p><a href="/methodology.php">How we verify information</a></p>
  </section>
<?php elseif ($state === 'unavailable'): ?>
  <section class="panel" aria-labelledby="ugDown" role="alert">
    <h2 id="ugDown" style="margin-top:0">The data is temporarily unavailable</h2>
    <p>The verified data could not be read right now. Nothing is shown rather than something unverified. Please try again in a few minutes.</p>
  </section>
<?php else: ?>
  <?php if ($state === 'notfound'): ?>
    <section class="panel" aria-labelledby="ugNf" role="alert">
      <h2 id="ugNf" style="margin-top:0">Not found among the verified entities</h2>
      <p>This entity does not exist, is not verified, or the page number is out of range. The verified entities are listed below.</p>
      <p><a href="<?= h(ug_url()) ?>">Show the first page of verified entities</a></p>
    </section>
  <?php endif; ?>

  <?php if ($state === 'ok'): ?>
  <noscript><p class="noticeBox">The interactive graph needs JavaScript. The list of verified entities and relationships below works without it.</p></noscript>
  <div class="ugapp ugembed" id="ugapp" hidden>
    <div class="skips">
      <a class="skip" href="#controls" data-skip="controls">Skip to controls and search</a>
      <a class="skip" href="#stage" data-skip="stage">Skip to graph or table</a>
      <a class="skip" href="#panel" data-skip="panel" id="skipPanel" hidden>Skip to entity details</a>
    </div>
    <header>
      <div class="top"><p class="ugtitle">Interactive graph</p>
      <p class="verified-note" id="synBanner">Verified data from the read API: only verified, current entities and relationships, each with its source.</p></div>
      <details id="controls" open tabindex="-1">
        <summary>Controls, filters and search</summary>
        <div id="bar">
          <div class="grp" role="group" aria-label="View">
            <button id="vGraph" type="button" aria-pressed="true">Graph</button>
            <button id="vTable" type="button" aria-pressed="false">Table</button>
          </div>
          <label class="sr" for="q">Search entities by name</label>
          <input id="q" type="search" placeholder="Search entities" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="results" aria-autocomplete="list">
          <div id="reswrap"><ul id="results" role="listbox" aria-label="Search results"></ul><p id="resmsg" hidden>No matches</p></div>
        </div>
        <fieldset id="filters"><legend class="sr">Filter by entity type</legend></fieldset>
      </details>
      <details id="kbdBox">
        <summary>Keyboard and touch help</summary>
        <ul class="help">
          <li>Graph focused: <kbd>Arrow keys</kbd> pan, <kbd>+</kbd> / <kbd>-</kbd> zoom, <kbd>0</kbd> fit all, <kbd>Esc</kbd> clear selection.</li>
          <li><kbd>Enter</kbd> selects the entity nearest the centre; then <kbd>Enter</kbd> again opens its details. Selecting an entity loads its verified relationships.</li>
          <li><kbd>N</kbd> / <kbd>Shift+N</kbd> next / previous entity by distance from the centre; <kbd>]</kbd> / <kbd>[</kbd> next / previous related entity; <kbd>B</kbd> back to the previous selection.</li>
          <li>Buttons on the graph do the same without keys. The Table view lists the same entities and keeps the selection.</li>
          <li>Touch: drag to pan, pinch to zoom, tap an entity.</li>
        </ul>
      </details>
      <details id="legendBox">
        <summary>Legend (relationship types and entity types)</summary>
        <div id="legend"></div>
      </details>
    </header>
    <div id="layout">
    <div id="ugmain">
      <section id="stage" aria-label="Graph or table of entities" tabindex="-1">
        <canvas id="cv" tabindex="0" role="application" aria-roledescription="graph" aria-describedby="cvhelp" aria-label="Interactive graph of verified AI entities"></canvas>
        <p id="cvhelp" class="sr">Arrow keys pan, plus and minus zoom, zero fits all, Enter selects the entity nearest the centre, N and Shift N cycle entities, right and left square brackets cycle related entities, B goes back, Escape clears the selection. The Table view is an equivalent list.</p>
        <div id="zoomctl" role="group" aria-label="Graph navigation">
          <button id="zIn" type="button">Zoom in</button>
          <button id="zOut" type="button">Zoom out</button>
          <button id="zFit" type="button">Fit</button>
          <button id="zPick" type="button">Select centre</button>
          <button id="zNext" type="button" disabled>Next related</button>
        </div>
        <div id="hud"><span id="progress"></span></div>
        <div id="tablewrap" hidden role="region" aria-label="Entity table">
          <div class="pager" role="group" aria-label="Table paging">
            <button id="prev" type="button">Previous page</button>
            <span id="pageinfo"></span>
            <button id="next" type="button">Next page</button>
          </div>
          <table id="tbl">
            <caption id="tcap" tabindex="-1">Verified entities (same entities as the graph)</caption>
            <thead><tr><th scope="col">Name</th><th scope="col">Type</th><th scope="col" id="thCo">Developed by (once loaded)</th><th scope="col" id="thRel">Verified relationships loaded so far (count)</th></tr></thead>
            <tbody id="tbody"></tbody>
          </table>
        </div>
      </section>
    </div>
    <section id="panel" aria-label="Entity details" hidden>
      <div><h2 id="pname" tabindex="-1"></h2> <button id="pclose" type="button" aria-label="Close details">Close</button></div>
      <p id="psum" hidden></p>
      <dl id="pdl"></dl>
      <h3 id="relh">Relationships (verified, with source)</h3>
      <p id="relnote" class="verified-note" hidden></p>
      <ul id="edges" aria-labelledby="relh"></ul>
      <p id="pnet" role="status"></p>
      <button id="pmore" type="button" hidden>Load more relationships</button>
    </section>
    </div>
    <div id="live" class="sr" role="status" aria-live="polite" aria-atomic="true"></div>
  </div>
  <?php endif; ?>

  <details id="uni-static" class="panel" open>
    <summary>List of verified entities<?= $detail ? ' and the relationships of ' . h($detail['name']) : '' ?> (works without JavaScript)</summary>
    <?php if ($detail && $graph): ?>
      <section aria-labelledby="ugEnt" id="entity">
        <h2 id="ugEnt"><?= h($detail['name']) ?></h2>
        <dl>
          <dt>Type</dt><dd><?= h($detail['entity_type']) ?></dd>
          <dt>Verified</dt><dd><?= h(ug_day($detail['verified_at'])) ?></dd>
          <?php if ((string)$detail['summary'] !== ''): ?><dt>Summary</dt><dd><?= h($detail['summary']) ?></dd><?php endif; ?>
          <dt>Sources</dt><dd>
            <?php if (!$detail['sources']): ?>No source recorded for this entity.<?php else: ?><ul>
              <?php foreach ($detail['sources'] as $src): ?><li><?php if (ug_link_ok((string)$src['url'])): ?><a href="<?= h($src['url']) ?>" rel="nofollow noopener" target="_blank"><?= h($src['publisher'] ?: $src['url']) ?></a><?php else: ?><?= h($src['publisher'] ?: 'source') ?><?php endif; ?> (retrieved <?= h(ug_day($src['retrieved_at'])) ?>)</li><?php endforeach; ?>
            </ul><?php endif; ?></dd>
        </dl>
        <h3>Verified relationships</h3>
        <?php $names = []; foreach ($graph['nodes'] as $gn) $names[$gn['ref']] = $gn; ?>
        <?php if (!$graph['edges']): ?>
          <p>No verified relationships yet.</p>
        <?php else: ?>
          <div style="overflow-x:auto"><table class="pretty">
            <caption>Verified relationships of <?= h($detail['name']) ?>, grouped by relationship type. The API publishes no verification date per relationship, so the retrieval date of each source is shown.</caption>
            <thead><tr><th scope="col">Relationship</th><th scope="col">From</th><th scope="col">To</th><th scope="col">Valid</th><th scope="col">Sources</th></tr></thead>
            <tbody>
            <?php foreach ($graph['edges'] as $ed): $f = $names[$ed['from']] ?? null; $t = $names[$ed['to']] ?? null; ?>
              <tr>
                <th scope="row"><?= h($ed['type']) ?></th>
                <td><?php if ($f): ?><a href="<?= h(ug_url(['slug' => $f['slug']])) ?>"><?= h($f['name']) ?></a> (<?= h($f['type']) ?>)<?php endif; ?></td>
                <td><?php if ($t): ?><a href="<?= h(ug_url(['slug' => $t['slug']])) ?>"><?= h($t['name']) ?></a> (<?= h($t['type']) ?>)<?php endif; ?></td>
                <td><?= $ed['valid_from'] || $ed['valid_to'] ? h(($ed['valid_from'] ?: '?') . ' to ' . ($ed['valid_to'] ?: 'present')) : 'not stated' ?></td>
                <td><?php if (!$ed['sources']): ?>No source recorded<?php endif; ?>
                  <?php foreach ($ed['sources'] as $src): ?><div><?php if (ug_link_ok((string)$src['url'])): ?><a href="<?= h($src['url']) ?>" rel="nofollow noopener" target="_blank"><?= h($src['publisher'] ?: $src['url']) ?></a><?php else: ?><?= h($src['publisher'] ?: 'source') ?><?php endif; ?> (retrieved <?= h(ug_day($src['retrieved_at'])) ?>)</div><?php endforeach; ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
          <?php if ($graph['has_more']): ?><p>Showing the first <?= (int)count($graph['edges']) ?> of <?= (int)$graph['total_edges'] ?> verified relationships. The interactive graph loads the rest.</p><?php endif; ?>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <?php if ($list['items']): ?>
    <h2 id="ugList">Verified entities (<?= (int)$list['total'] ?>)</h2>
    <div style="overflow-x:auto"><table class="pretty">
      <caption>Verified entities, page <?= (int)$list['page'] ?> of <?= (int)max(1, (int)ceil($list['total'] / UG_PER_PAGE)) ?>. Select a name to see its relationships.</caption>
      <thead><tr><th scope="col">Name</th><th scope="col">Type</th><th scope="col">Verified</th></tr></thead>
      <tbody>
      <?php foreach ($list['items'] as $it): ?>
        <tr><th scope="row"><a href="<?= h(ug_url(['slug' => $it['slug']])) ?>"><?= h($it['name']) ?></a></th><td><?= h($it['entity_type']) ?></td><td><?= h(ug_day($it['verified_at'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <nav aria-label="Entity list pages" class="pager">
      <?php if ($list['page'] > 1): ?><a rel="prev" href="<?= h(ug_url(['slug' => $slug, 'page' => $list['page'] - 1])) ?>">Previous page</a><?php endif; ?>
      <?php if ($list['has_more']): ?><a rel="next" href="<?= h(ug_url(['slug' => $slug, 'page' => $list['page'] + 1])) ?>">Next page</a><?php endif; ?>
    </nav>
    <?php endif; ?>
  </details>

  <?php if ($state === 'ok'): ?>
  <script>window.UNIVERSE_GRAPH_CONFIG=<?= json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
  <?php foreach (['dispatch', 'timer', 'quadtree', 'force', 'color', 'interpolate', 'ease', 'selection', 'transition', 'drag', 'zoom'] as $d3): ?>
  <script defer src="<?= h($asset('vendor/d3-' . $d3 . '.min.js')) ?>"></script>
  <?php endforeach; ?>
  <script defer src="<?= h($asset('data-source.js')) ?>"></script>
  <script defer src="<?= h($asset('graph.js')) ?>"></script>
  <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/layout_bottom.php'; ?>
