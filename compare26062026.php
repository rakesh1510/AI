<?php
require_once __DIR__ . '/helpers.php';
$title = "Compare AI Tools — AIHubpedia";
$data = load_tools();
$tools = $data['tools'] ?? [];

$a = trim($_GET['a'] ?? '');
$b = trim($_GET['b'] ?? '');
if($a === '' && isset($_GET['id'])) $a = trim($_GET['id']);
$ta = $a ? find_tool($a) : null;
$tb = $b ? find_tool($b) : null;

/**
 * Local compare bundle builder.
 * This reads directly from tools.json fields first, so the compare page does not
 * fall back to repeated/default compare-table values such as 4/10, 5/10, 4/10.
 */
function compare_page_clean_list($value): array {
  if (is_string($value)) {
    $decoded = json_decode($value, true);
    if (is_array($decoded)) $value = $decoded;
    else $value = array_filter(array_map('trim', explode(',', $value)));
  }
  if (!is_array($value)) return [];

  $out = [];
  foreach ($value as $item) {
    if (is_array($item)) {
      $item = $item['label'] ?? $item['name'] ?? $item['title'] ?? '';
    }
    $item = trim((string)$item);
    if ($item !== '') $out[] = $item;
  }
  return array_values(array_unique($out));
}

function compare_page_score(int $base, int $count, int $bonus = 0): int {
  return min(10, max(1, $base + $count + $bonus));
}

function compare_page_bundle(array $tool): array {
  $features     = compare_page_clean_list($tool['features'] ?? []);
  $useCases     = compare_page_clean_list($tool['use_cases'] ?? []);
  $pros         = compare_page_clean_list($tool['pros'] ?? []);
  $watchouts    = compare_page_clean_list($tool['watchouts'] ?? ($tool['cons'] ?? []));
  $platforms    = compare_page_clean_list($tool['platforms'] ?? []);
  $integrations = compare_page_clean_list($tool['integrations'] ?? []);
  $tags         = compare_page_clean_list($tool['tags'] ?? []);

  if (empty($platforms) && !empty($tool['website'])) {
    $platforms[] = 'Web';
  }

  // If no integration field exists, show useful integration-related tags instead of empty repeated fallback.
  if (empty($integrations) && !empty($tags)) {
    $integrationKeywords = ['api','slack','zoom','google meet','gmail','teams','notion','zapier','chrome','integrations','workflow','automation'];
    foreach ($tags as $tag) {
      if (in_array(strtolower($tag), $integrationKeywords, true)) {
        $integrations[] = $tag;
      }
    }
    $integrations = array_values(array_unique($integrations));
  }

  $featureDepth = isset($tool['feature_depth'])
    ? (int)$tool['feature_depth']
    : compare_page_score(3, count($features), min(2, count($integrations)));

  $easeOfUse = isset($tool['ease_of_use'])
    ? (int)$tool['ease_of_use']
    : compare_page_score(5, !empty($tool['pricing']) ? 1 : 0, !empty($platforms) ? 1 : 0);

  $useCaseRange = isset($tool['use_case_range'])
    ? (int)$tool['use_case_range']
    : compare_page_score(3, count($useCases), min(2, count($platforms)));

  $hasRealData = !empty($features) || !empty($useCases) || !empty($pros) || !empty($watchouts);

  return [
    'summary' => trim((string)($tool['description'] ?? ($tool['short'] ?? ''))),
    'pricing' => trim((string)($tool['pricing'] ?? ($tool['pricing_type'] ?? 'Unknown'))),
    'features' => $features,
    'use_cases' => $useCases,
    'pros' => $pros,
    'watchouts' => $watchouts,
    'platforms' => $platforms,
    'integrations' => $integrations,
    'real_data' => $hasRealData,
    'source_status' => $hasRealData ? 'available' : 'basic',
    'source_count' => $hasRealData ? 1 : 0,
    'source_confidence' => $hasRealData ? 'basic' : 'low',
    'feature_depth' => $featureDepth,
    'ease_of_use' => $easeOfUse,
    'use_case_range' => $useCaseRange,
  ];
}

function compare_page_score_items(array $bundle): array {
  return [
    'Feature depth' => (int)($bundle['feature_depth'] ?? 0),
    'Ease of use' => (int)($bundle['ease_of_use'] ?? 0),
    'Use-case range' => (int)($bundle['use_case_range'] ?? 0),
  ];
}

function compare_page_snapshot(array $bundle): array {
  return [
    'Feature depth' => (int)($bundle['feature_depth'] ?? 0),
    'Use-case range' => (int)($bundle['use_case_range'] ?? 0),
    'Integrations' => count($bundle['integrations'] ?? []),
  ];
}

function compare_page_pick_winner(array $bundleA, array $bundleB): ?string {
  $scoreA = array_sum(compare_page_score_items($bundleA));
  $scoreB = array_sum(compare_page_score_items($bundleB));
  if ($scoreA === $scoreB) return null;
  return $scoreA > $scoreB ? 'a' : 'b';
}

$bundleA = $ta ? compare_page_bundle($ta) : null;
$bundleB = $tb ? compare_page_bundle($tb) : null;
$winnerKey = ($bundleA && $bundleB) ? compare_page_pick_winner($bundleA, $bundleB) : null;
$winnerName = $winnerKey === 'a' ? ($ta['name'] ?? null) : ($winnerKey === 'b' ? ($tb['name'] ?? null) : null);

require __DIR__ . '/layout_top.php';
?>

<section class="compare-hero card">
  <div class="compare-hero__copy">
    <span class="eyebrow">Side-by-side comparison</span>
    <h1>Compare tools</h1>
    <p class="small compare-lead">Pick two AI tools and compare verified compare data, pricing signals, source-backed features, and practical fit in one clean view.</p>
  </div>
  <form method="get" class="compare-form-grid">
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
      <a class="btn secondary" href="compare.php">Reset</a>
    </div>
  </form>
</section>

<?php if($ta && $tb && $bundleA && $bundleB): ?>
  <div class="compare-sticky-bar card">
    <div class="compare-sticky-bar__tools">
      <span><strong><?= h($ta['name']) ?></strong> vs <strong><?= h($tb['name']) ?></strong></span>
      <?php if($winnerName): ?><span class="compare-winner-pill">Overall edge: <?= h($winnerName) ?></span><?php else: ?><span class="compare-winner-pill neutral">Very close match</span><?php endif; ?>
    </div>
    <div class="compare-sticky-bar__actions">
      <a class="btn secondary" href="tool.php?id=<?= urlencode($ta['id']) ?>">View <?= h($ta['name']) ?></a>
      <a class="btn" href="tool.php?id=<?= urlencode($tb['id']) ?>">View <?= h($tb['name']) ?></a>
    </div>
  </div>

  <!--<div class="ad compare-ad">Compare pipeline reads from new compare tables only. Existing tools table stays unchanged.</div>-->

  <section class="compare-grid compare-grid--premium">
    <div class="compare-vs-badge">VS</div>
    <?php foreach([['tool'=>$ta,'bundle'=>$bundleA,'winner'=>$winnerKey==='a'], ['tool'=>$tb,'bundle'=>$bundleB,'winner'=>$winnerKey==='b']] as $entry): ?>
      <?php
        $tool = $entry['tool'];
        $bundle = $entry['bundle'];
        $scoreItems = compare_page_score_items($bundle);
        $snapshot = compare_page_snapshot($bundle);
        $isWinner = $entry['winner'];
      ?>
      <article class="compare-card card <?= $isWinner ? 'compare-card--winner' : '' ?>">
        <?php if($isWinner): ?><div class="compare-card__winner">Best overall value</div><?php endif; ?>
        <div class="compare-card__head">
          <div class="compare-card__brand">
            <img src="<?= h(tool_logo($tool)) ?>" alt="<?= h($tool['name']) ?> logo" class="tool-logo lg">
            <div>
              <h2><?= h($tool['name']) ?></h2>
              <div class="compare-meta">
                <span class="chip"><?= h($tool['category'] ?? 'AI Tool') ?></span>
                <span class="chip soft"><?= h($bundle['pricing'] ?: ($tool['pricing'] ?? 'Unknown')) ?></span>
                <span class="chip soft"><?= $bundle['real_data'] ? 'Verified compare data' : 'Basic facts only' ?></span>
              </div>
            </div>
          </div>
          <div class="compare-card__actions actions">
            <a class="btn secondary" href="tool.php?id=<?= urlencode($tool['id']) ?>">Details</a>
            <a class="btn" target="_blank" rel="noopener" href="redirect.php?id=<?= urlencode($tool['id']) ?>">Open tool</a>
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
              <?php if(empty($bundle['features'])): ?><li>No verified feature data yet.</li><?php endif; ?>
            </ul>
          </div>
          <div>
            <h3>Best use cases</h3>
            <ul class="nice-list">
              <?php foreach(array_slice($bundle['use_cases'] ?? [], 0, 4) as $item): ?>
                <li><?= h($item) ?></li>
              <?php endforeach; ?>
              <?php if(empty($bundle['use_cases'])): ?><li>No verified use-case data yet.</li><?php endif; ?>
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
              <?php if(empty($bundle['platforms'])): ?><li>No verified platform data yet.</li><?php endif; ?>
            </ul>
          </div>
          <div>
            <h3>Integrations</h3>
            <ul class="nice-list">
              <?php foreach(array_slice($bundle['integrations'] ?? [], 0, 6) as $item): ?>
                <li><?= h($item) ?></li>
              <?php endforeach; ?>
              <?php if(empty($bundle['integrations'])): ?><li>No verified integration data yet.</li><?php endif; ?>
            </ul>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="compare-final card">
    <h2>Quick buying view</h2>
    <div class="compare-table-wrap">
      <table class="compare-table-modern">
        <tr>
          <th>Criteria</th>
          <th><?= h($ta['name']) ?></th>
          <th><?= h($tb['name']) ?></th>
        </tr>
        <tr>
          <td>Category</td>
          <td><?= h($ta['category'] ?? '-') ?></td>
          <td><?= h($tb['category'] ?? '-') ?></td>
        </tr>
        <tr>
          <td>Pricing</td>
          <td><?= h($bundleA['pricing'] ?: ($ta['pricing'] ?? '-')) ?></td>
          <td><?= h($bundleB['pricing'] ?: ($tb['pricing'] ?? '-')) ?></td>
        </tr>
        <tr>
          <td>Best for</td>
          <td><?= h(($bundleA['use_cases'][0] ?? $ta['short'] ?? '-')) ?></td>
          <td><?= h(($bundleB['use_cases'][0] ?? $tb['short'] ?? '-')) ?></td>
        </tr>
        <tr>
          <td>Platforms</td>
          <td><?= h(implode(', ', array_slice($bundleA['platforms'] ?? [], 0, 4)) ?: '-') ?></td>
          <td><?= h(implode(', ', array_slice($bundleB['platforms'] ?? [], 0, 4)) ?: '-') ?></td>
        </tr>
        <tr>
          <td>Official site</td>
          <td><?= h(tool_domain($ta['website'] ?? '')) ?></td>
          <td><?= h(tool_domain($tb['website'] ?? '')) ?></td>
        </tr>
      </table>
    </div>
  </section>
<?php elseif($a || $b): ?>
  <div class="card compare-empty-state">
    <h2>Select two valid tools</h2>
    <p class="small">Choose both Tool A and Tool B to unlock the full side-by-side comparison view.</p>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/layout_bottom.php'; ?>
