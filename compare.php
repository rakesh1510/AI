<?php
require_once __DIR__ . '/helpers.php';
$data = load_tools();
$tools = $data['tools'] ?? [];

$a = trim($_GET['a'] ?? '');
$b = trim($_GET['b'] ?? '');
if($a === '' && isset($_GET['id'])) $a = trim($_GET['id']);
$ta = $a ? find_tool($a) : null;
$tb = $b ? find_tool($b) : null;

$bundleA = $ta ? compare_tool_bundle($ta) : null;
$bundleB = $tb ? compare_tool_bundle($tb) : null;
$winnerKey = ($bundleA && $bundleB) ? compare_pick_winner_from_bundles($bundleA, $bundleB) : null;
$winnerName = $winnerKey === 'a' ? ($ta['name'] ?? null) : ($winnerKey === 'b' ? ($tb['name'] ?? null) : null);

$crumbs = [['Home', site_url('/')], ['Compare AI tools', site_url('/compare.php')]];
if(($a !== '' && !$ta) || ($b !== '' && !$tb)){
  // Unknown tool ids: real 404 instead of a thin 200 page.
  http_response_code(404);
  $title = 'Comparison Not Found';
  $meta_desc = 'One of the AI tools in this comparison is not listed on AIHubpedia.';
} elseif($ta && $tb && $ta['id'] === $tb['id']){
  $robots = 'noindex';
  $canonical = tool_page_url($ta['id']);
} elseif($ta && $tb){
  $title = $ta['name'] . ' vs ' . $tb['name'] . ': Features, Pricing & Use Cases Compared';
  $meta_desc = $ta['name'] . ' vs ' . $tb['name'] . ': compare category (' . $ta['category'] . ' / ' . $tb['category'] . '), pricing (' . ($ta['pricing'] ?: 'n/a') . ' / ' . ($tb['pricing'] ?: 'n/a') . '), listed features, use cases and platforms side by side.';
  $canonical = compare_page_url($ta['id'], $tb['id']);
  $crumbs[1][1] = site_url('/compare.php');
  $crumbs[] = [$ta['name'] . ' vs ' . $tb['name'], null];
  $jsonld = [
    ['@type' => 'WebPage', '@id' => $canonical . '#webpage', 'url' => $canonical, 'name' => seo_title($title), 'description' => seo_description($meta_desc),
     'isPartOf' => ['@id' => site_url('/#website')],
     'about' => [
       ['@type' => 'SoftwareApplication', 'name' => $ta['name'], 'url' => tool_page_url($ta['id']), 'applicationCategory' => tool_schema_category((string)$ta['category'])],
       ['@type' => 'SoftwareApplication', 'name' => $tb['name'], 'url' => tool_page_url($tb['id']), 'applicationCategory' => tool_schema_category((string)$tb['category'])],
     ]],
    seo_breadcrumb_node($crumbs),
  ];
} elseif($ta || $tb){
  // Half-selected comparison (e.g. linked from a tool page): useful for users, not for the index.
  $robots = 'noindex';
  $canonical = site_url('/compare.php');
} else {
  $crumbs[1][1] = null;
  $jsonld = [seo_breadcrumb_node($crumbs)];
}
$__pairs = available_comparison_pairs($tools, 12);

require __DIR__ . '/layout_top.php';
?>

<?= render_breadcrumbs($crumbs) ?>
<section class="compare-hero card">
  <div class="compare-hero__copy">
    <span class="eyebrow">Side-by-side comparison</span>
    <h1><?= ($ta && $tb) ? h($ta['name']) . ' vs ' . h($tb['name']) : 'Compare AI tools' ?></h1>
    <p class="small compare-lead">Pick two AI tools and compare category, listed pricing, features, use cases and platforms side by side. Source-backed data is shown where AIHubpedia has collected it.</p>
  </div>
  <form method="get" action="/compare.php" class="compare-form-grid" data-compare-form>
    <div class="field">
      <label for="toolA">Tool A</label>
      <select id="toolA" name="a">
        <option value="">Choose first tool</option>
        <?php foreach($tools as $t): ?>
          <option value="<?= h($t['id']) ?>" <?= ($a===$t['id'])?'selected':'' ?>><?= h($t['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="toolB">Tool B</label>
      <select id="toolB" name="b">
        <option value="">Choose second tool</option>
        <?php foreach($tools as $t): ?>
          <option value="<?= h($t['id']) ?>" <?= ($b===$t['id'])?'selected':'' ?>><?= h($t['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="compare-form-actions">
      <button class="btn" type="submit">Compare now</button>
      <button type="button" class="btn secondary js-only" data-compare-swap>Swap</button>
      <a class="btn secondary" href="/compare.php">Reset</a>
    </div>
  </form>
</section>

<?php if($ta && $tb && $bundleA && $bundleB): ?>
  <div class="compare-sticky-bar card">
    <div class="compare-sticky-bar__tools">
      <span><strong><?= h($ta['name']) ?></strong> vs <strong><?= h($tb['name']) ?></strong></span>
      <?php if($winnerName): ?><span class="compare-winner-pill">More complete profile: <?= h($winnerName) ?></span><?php else: ?><span class="compare-winner-pill neutral">Similar profile depth</span><?php endif; ?>
    </div>
    <div class="compare-sticky-bar__actions">
      <a class="btn secondary" href="/tool.php?id=<?= urlencode($ta['id']) ?>">View <?= h($ta['name']) ?></a>
      <a class="btn" href="/tool.php?id=<?= urlencode($tb['id']) ?>">View <?= h($tb['name']) ?></a>
    </div>
  </div>

  <p class="small compare-note">Scores below measure how much information AIHubpedia has on each tool (listed features, use cases, platforms, integrations and free-plan data). They are not a quality rating or a recommendation — always check the official websites.</p>

  <section class="compare-grid compare-grid--premium">
    <div class="compare-vs-badge">VS</div>
    <?php foreach([['tool'=>$ta,'bundle'=>$bundleA,'winner'=>$winnerKey==='a'], ['tool'=>$tb,'bundle'=>$bundleB,'winner'=>$winnerKey==='b']] as $entry): ?>
      <?php
        $tool = $entry['tool'];
        $bundle = $entry['bundle'];
        $scoreItems = compare_score_items_from_bundle($bundle);
        $snapshot = compare_snapshot_from_bundle($bundle);
        $isWinner = $entry['winner'];
      ?>
      <article class="compare-card card <?= $isWinner ? 'compare-card--winner' : '' ?>">
        <?php if($isWinner): ?><div class="compare-card__winner">More complete profile</div><?php endif; ?>
        <div class="compare-card__head">
          <div class="compare-card__brand">
            <img src="<?= h(tool_logo($tool)) ?>" alt="<?= h($tool['name']) ?> logo" class="tool-logo lg" width="64" height="64" decoding="async">
            <div>
              <h2><a href="/tool.php?id=<?= urlencode($tool['id']) ?>"><?= h($tool['name']) ?></a></h2>
              <div class="compare-meta">
                <span class="chip"><?= h($tool['category'] ?? 'AI Tool') ?></span>
                <span class="chip soft"><?= h($bundle['pricing'] ?: ($tool['pricing'] ?? 'Unknown')) ?></span>
                <span class="chip soft"><?= $bundle['real_data'] ? 'Source-backed profile' : 'Basic listing data' ?></span>
              </div>
            </div>
          </div>
          <div class="compare-card__actions actions">
            <a class="btn secondary" href="/tool.php?id=<?= urlencode($tool['id']) ?>">Details</a>
            <a class="btn" target="_blank" rel="<?= h(outbound_rel($tool)) ?>" href="/redirect.php?id=<?= urlencode($tool['id']) ?>" data-outbound data-affiliate="<?= is_affiliate_link($tool) ? '1' : '0' ?>" data-placement="compare">Open tool</a>
          </div>
        </div>

        <div class="compare-snapshot">
          <?php foreach($snapshot as $label => $value): ?>
            <div class="snapshot-box">
              <strong><?= h($value) ?></strong>
              <span><?= h($label) ?></span>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="compare-summary">
          <h3>Overview</h3>
          <p><?= h($bundle['summary'] ?: 'No compare summary has been saved yet for this tool.') ?></p>
          <p class="small" style="margin-top:10px">
            Source status: <strong><?= h($bundle['source_status']) ?></strong>
            <?php if($bundle['source_count']): ?> · <?= (int)$bundle['source_count'] ?> saved source page(s)<?php endif; ?>
            <?php if($bundle['source_confidence']): ?> · confidence: <?= h($bundle['source_confidence']) ?><?php endif; ?>
          </p>
        </div>

        <div class="compare-scoreboard">
          <?php foreach($scoreItems as $label => $value): ?>
            <div class="score-item">
              <div class="score-item__row">
                <span><?= h($label) ?></span>
                <strong><?= h($value) ?>/10</strong>
              </div>
              <div class="score-bar"><span style="width: <?= (int)$value * 10 ?>%"></span></div>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="compare-columns">
          <div>
            <h3>Top features</h3>
            <ul class="nice-list">
              <?php foreach(array_slice($bundle['features'] ?? [], 0, 4) as $item): ?>
                <li><?= h($item) ?></li>
              <?php endforeach; ?>
              <?php if(empty($bundle['features'])): ?><li>No feature data collected yet.</li><?php endif; ?>
            </ul>
          </div>
          <div>
            <h3>Best use cases</h3>
            <ul class="nice-list">
              <?php foreach(array_slice($bundle['use_cases'] ?? [], 0, 4) as $item): ?>
                <li><?= h($item) ?></li>
              <?php endforeach; ?>
              <?php if(empty($bundle['use_cases'])): ?><li>No use-case data collected yet.</li><?php endif; ?>
            </ul>
          </div>
        </div>

        <div class="compare-columns">
          <div>
            <h3>Strengths</h3>
            <ul class="nice-list success">
              <?php foreach(array_slice($bundle['pros'] ?? [], 0, 4) as $item): ?>
                <li><?= h($item) ?></li>
              <?php endforeach; ?>
              <?php if(empty($bundle['pros'])): ?><li>No strengths saved yet.</li><?php endif; ?>
            </ul>
          </div>
          <div>
            <h3>Watch-outs</h3>
            <ul class="nice-list warning">
              <?php foreach(array_slice($bundle['watchouts'] ?? [], 0, 4) as $item): ?>
                <li><?= h($item) ?></li>
              <?php endforeach; ?>
              <?php if(empty($bundle['watchouts'])): ?><li>No watch-outs saved yet.</li><?php endif; ?>
            </ul>
          </div>
        </div>

        <div class="compare-columns">
          <div>
            <h3>Platforms</h3>
            <ul class="nice-list">
              <?php foreach(array_slice($bundle['platforms'] ?? [], 0, 6) as $item): ?>
                <li><?= h($item) ?></li>
              <?php endforeach; ?>
              <?php if(empty($bundle['platforms'])): ?><li>No platform data collected yet.</li><?php endif; ?>
            </ul>
          </div>
          <div>
            <h3>Integrations</h3>
            <ul class="nice-list">
              <?php foreach(array_slice($bundle['integrations'] ?? [], 0, 6) as $item): ?>
                <li><?= h($item) ?></li>
              <?php endforeach; ?>
              <?php if(empty($bundle['integrations'])): ?><li>No integration data collected yet.</li><?php endif; ?>
            </ul>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

  <?php
    $pair = [$ta, $tb];
    $evs = array_map('tool_evidence', $pair);
    $rows = capability_matrix($pair);
    $freeRow = null;
  ?>
  <section class="compare-final card" aria-labelledby="featMatrix">
    <h2 id="featMatrix">Feature-by-feature comparison</h2>
    <p class="small">Built only from AIHubpedia's sourced profiles. <strong>Documented</strong> means the profile mentions the feature; <strong>Unknown</strong> means we have no sourced statement, not that the tool lacks it. <a href="/methodology.php#evidence">How to read this</a>.</p>
    <div class="matrixWrap" tabindex="0" role="region" aria-label="Feature comparison table, scrollable">
      <table class="matrix">
        <caption class="sr">Documented features and evidence for <?= h($ta['name']) ?> and <?= h($tb['name']) ?></caption>
        <thead><tr><th scope="col">Feature</th><th scope="col"><?= h($ta['name']) ?></th><th scope="col"><?= h($tb['name']) ?></th></tr></thead>
        <tbody>
          <tr class="<?= $evs[0]['level'] !== $evs[1]['level'] ? 'differs' : '' ?>"><th scope="row">Evidence level</th>
            <?php foreach($pair as $i => $tt): ?><td data-label="<?= h($tt['name']) ?>"><?= render_evidence_badge($tt, false) ?></td><?php endforeach; ?></tr>
          <tr><th scope="row">Last reviewed / verified</th>
            <?php foreach($pair as $i => $tt): $e = $evs[$i]; ?><td data-label="<?= h($tt['name']) ?>"><?php if(!$e['curated']): ?><span class="cellState s-not_reviewed">Not reviewed</span><?php elseif($e['human_verified']): ?><?= h($e['verified_on']) ?> (editor verified)<?php else: ?>Sources retrieved <?= h($e['reviewed_on']) ?><span class="cellEv">Not yet verified by an editor</span><?php endif; ?></td><?php endforeach; ?></tr>
          <tr><th scope="row">Pricing (as stated in sources)</th>
            <?php foreach($pair as $i => $tt): $e = $evs[$i]; ?><td data-label="<?= h($tt['name']) ?>"><?php if($e['pricing_summary'] !== ''): ?><?= h($e['pricing_summary']) ?><span class="cellEv">As of <?= h($e['pricing_as_of']) ?><?= $e['pricing_stale'] ? ' — older than ' . (int)EVIDENCE_STALE_DAYS . ' days' : '' ?>; <?= h($e['pricing_note']) ?></span><?php else: ?><span class="cellState s-unknown">Unknown</span><span class="cellEv">Listing says: <?= h($tt['pricing'] ?: 'not stated') ?> (not reviewed)</span><?php endif; ?></td><?php endforeach; ?></tr>
          <tr><th scope="row">Sources listed</th>
            <?php foreach($pair as $i => $tt): $e = $evs[$i]; ?><td data-label="<?= h($tt['name']) ?>"><?= $e['curated'] ? (int)$e['source_count'] . ' page' . ($e['source_count'] === 1 ? '' : 's') . ($e['conflicts'] ? ' · sources disagree' : '') : '<span class="cellState s-unknown">None</span>' ?></td><?php endforeach; ?></tr>
          <?php foreach($rows as $row): ?>
            <tr class="<?= $row['differs'] ? 'differs' : '' ?>"><th scope="row"><?= h($row['label']) ?></th>
              <?php foreach($row['cells'] as $i => $c): ?>
                <td data-label="<?= h($pair[$i]['name']) ?>"><span class="cellState s-<?= h($c['state']) ?>"><?= h(capability_state_text($c['state'])) ?></span><?php if($c['evidence'] !== ''): ?><span class="cellEv"><?= h($c['evidence']) ?></span><?php else: ?><span class="cellEv"><?= h(capability_state_help($c['state'])) ?></span><?php endif; ?></td>
              <?php endforeach; ?></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if(!$rows): ?><p class="small">Neither tool has documented capabilities yet, so there is nothing to compare feature by feature.</p><?php endif; ?>
    <p class="small">Shaded rows differ between the two tools.</p>
  </section>

  <section class="compare-final card">
    <h2>Quick buying view</h2>
    <div class="compare-table-wrap" tabindex="0" role="region" aria-label="Comparison table, scrollable">
      <table class="compare-table-modern">
        <tr>
          <th>Criteria</th>
          <th><?= h($ta['name']) ?></th>
          <th><?= h($tb['name']) ?></th>
        </tr>
        <tr<?= ($ta['category'] ?? '') !== ($tb['category'] ?? '') ? ' class="diffRow"' : '' ?>>
          <td>Category</td>
          <td><?= h($ta['category'] ?? '-') ?></td>
          <td><?= h($tb['category'] ?? '-') ?></td>
        </tr>
        <tr<?= ($bundleA['pricing'] ?: ($ta['pricing'] ?? '')) !== ($bundleB['pricing'] ?: ($tb['pricing'] ?? '')) ? ' class="diffRow"' : '' ?>>
          <td>Pricing</td>
          <td><?= h($bundleA['pricing'] ?: ($ta['pricing'] ?? '-')) ?></td>
          <td><?= h($bundleB['pricing'] ?: ($tb['pricing'] ?? '-')) ?></td>
        </tr>
        <tr class="diffRow">
          <td>Best for</td>
          <td><?= h(($bundleA['use_cases'][0] ?? $ta['short'] ?? '-')) ?></td>
          <td><?= h(($bundleB['use_cases'][0] ?? $tb['short'] ?? '-')) ?></td>
        </tr>
        <tr<?= implode(',', array_slice($bundleA['platforms'] ?? [], 0, 4)) !== implode(',', array_slice($bundleB['platforms'] ?? [], 0, 4)) ? ' class="diffRow"' : '' ?>>
          <td>Platforms</td>
          <td><?= h(implode(', ', array_slice($bundleA['platforms'] ?? [], 0, 4)) ?: '-') ?></td>
          <td><?= h(implode(', ', array_slice($bundleB['platforms'] ?? [], 0, 4)) ?: '-') ?></td>
        </tr>
        <tr class="diffRow">
          <td>Official site</td>
          <td><?= h(tool_domain($ta['website'] ?? '')) ?></td>
          <td><?= h(tool_domain($tb['website'] ?? '')) ?></td>
        </tr>
      </table>
    </div>
    <p class="small">Highlighted rows differ between the two tools.</p>
  </section>
<?php elseif($a || $b): ?>
  <div class="card compare-empty-state">
    <h2>Select two valid tools</h2>
    <p class="small">Choose both Tool A and Tool B to unlock the full side-by-side comparison view.</p>
  </div>
<?php endif; ?>

<?php if($__pairs): ?>
<section class="card" style="margin-top:18px">
  <h2 style="margin-top:0">Popular AI tool comparisons</h2>
  <ul class="linkList">
    <?php foreach($__pairs as [$pa, $pb]): ?>
      <li><a href="<?= h(compare_path($pa['id'], $pb['id'])) ?>"><?= h($pa['name']) ?> vs <?= h($pb['name']) ?></a></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php require __DIR__ . '/layout_bottom.php'; ?>
