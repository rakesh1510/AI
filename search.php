<?php
// Database-powered search results (server-rendered). Not indexed. See discovery.php: this is NOT an AI chat backend.
require_once __DIR__ . '/helpers.php';
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$cat = trim((string)($_GET['cat'] ?? ''));
$price = trim((string)($_GET['price'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$data = load_tools();
$tools = $data['tools'];
$cats = array_values(array_filter($data['categories'], fn($c) => $c !== CATALOG_UNCLASSIFIED));
if ($cat !== '' && !in_array($cat, $data['categories'], true)) $cat = '';
if (!in_array(strtolower($price), ['free', 'freemium', 'paid', 'free trial'], true)) $price = '';

$all = $q !== '' ? search_tools_ranked($tools, $q, 300) : [];
$unfiltered = count($all);
// Category facet counts (before applying the category filter)
$facets = [];
foreach ($all as $r) $facets[$r['tool']['category']] = ($facets[$r['tool']['category']] ?? 0) + 1;
arsort($facets);
$results = $q !== '' ? search_tools_ranked($tools, $q, 300, ['category' => $cat, 'pricing_type' => $price]) : [];
$total = count($results);
$perPage = 24;
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$slice = array_slice($results, ($page - 1) * $perPage, $perPage);
$partial = $results && !empty($results[0]['partial']);
$fuzzy = $results && !empty($results[0]['fuzzy']);

$title = $q !== '' ? 'Search results for “' . $q . '”' : 'Search AI Tools';
$meta_desc = $q !== '' ? $total . ' AI tools matching “' . $q . '” on AIHubpedia.' : 'Search the AIHubpedia directory by tool name, category, use case or goal.';
$hide_header_search = true;
$suggest = $q !== '' ? search_suggest_payload($tools, $q, 0)['items'] : [];
$related = array_values(array_filter($suggest, fn($i) => $i['type'] !== 'tool'));
$url = function (array $over = []) use ($q, $cat, $price) {
  $p = array_filter(array_merge(['q' => $q, 'cat' => $cat, 'price' => $price], $over), fn($v) => $v !== '' && $v !== null && $v !== 1);
  return '/search.php?' . http_build_query($p, '', '&', PHP_QUERY_RFC3986);
};
require __DIR__ . '/layout_top.php';
?>
<div class="pageHero" style="padding-top:0">
  <h1><?= $q !== '' ? 'Results for “' . h($q) . '”' : 'Search AI tools' ?></h1>
  <p class="lead">Ranked from the AIHubpedia database by name, category, tags and description. Tools with reviewed, sourced profiles rank higher.</p>
</div>
<div style="max-width:760px;margin:6px 0 22px"><?= render_search_box('pageSearch', ['big' => true, 'source' => 'results', 'value' => $q]) ?></div>

<?php if ($q === ''): ?>
  <section aria-labelledby="startTitle">
    <h2 id="startTitle">Start from a goal</h2>
    <div class="intentGrid">
      <?php foreach (discovery_intents($tools) as $i): ?>
        <a class="intent" href="<?= h($i['url']) ?>"><span class="ico"><?= ui_icon($i['icon']) ?></span><span><strong><?= h($i['label']) ?></strong><span class="n"><?= h(seo_plural($i['count'], 'tool')) ?></span></span></a>
      <?php endforeach; ?>
    </div>
  </section>
<?php else: ?>
  <div data-search-results="<?= (int)$total ?>" class="sr" aria-live="polite"><?= (int)$total ?> results</div>
  <div class="filterBar" role="group" aria-label="Refine results">
    <a class="chipBtn" href="<?= h($url(['cat' => '', 'page' => 1])) ?>" <?= $cat === '' ? 'aria-current="true"' : '' ?>>All (<?= (int)$unfiltered ?>)</a>
    <?php foreach (array_slice($facets, 0, 6, true) as $fc => $n): ?>
      <a class="chipBtn" href="<?= h($url(['cat' => $fc, 'page' => 1])) ?>" <?= $cat === $fc ? 'aria-current="true"' : '' ?>><?= h($fc) ?> (<?= (int)$n ?>)</a>
    <?php endforeach; ?>
    <a class="chipBtn" href="<?= h($url(['price' => $price === 'free' ? '' : 'free', 'page' => 1])) ?>" <?= $price === 'free' ? 'aria-current="true"' : '' ?>>Free</a>
    <a class="chipBtn" href="<?= h($url(['price' => $price === 'freemium' ? '' : 'freemium', 'page' => 1])) ?>" <?= $price === 'freemium' ? 'aria-current="true"' : '' ?>>Freemium</a>
  </div>

  <?php if ($fuzzy): ?><p class="noticeBox">No exact match for “<?= h($q) ?>”. Showing tools with similar names.</p><?php endif; ?>
  <?php if ($partial): ?><p class="noticeBox">No tool matches every word in your search, so these match some of them.</p><?php endif; ?>

  <?php if ($total === 0): ?>
    <section class="panel" aria-labelledby="noRes">
      <h2 id="noRes">No tools found for “<?= h($q) ?>”</h2>
      <p class="small">Try fewer or different words, or start from a goal below. If a tool is missing, you can <a href="/submit.php">submit it</a>.</p>
      <div class="intentGrid" style="margin-top:14px">
        <?php foreach (array_slice(discovery_intents($tools), 0, 6) as $i): ?>
          <a class="intent" href="<?= h($i['url']) ?>"><span class="ico"><?= ui_icon($i['icon']) ?></span><span><strong><?= h($i['label']) ?></strong><span class="n"><?= h(seo_plural($i['count'], 'tool')) ?></span></span></a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php else: ?>
    <p class="small"><?= (int)$total ?> <?= $total === 1 ? 'result' : 'results' ?><?= $pages > 1 ? ' · page ' . (int)$page . ' of ' . (int)$pages : '' ?></p>
    <div class="cardGrid">
      <?php foreach ($slice as $r) echo render_tool_card($r['tool'], ['placement' => 'search', 'heading' => 2]); ?>
    </div>
    <?php if ($pages > 1): ?>
      <nav class="pager" aria-label="Pagination">
        <?php if ($page > 1): ?><a href="<?= h($url(['page' => $page - 1])) ?>" rel="prev">← Previous</a><?php endif; ?>
        <span aria-current="page"><?= (int)$page ?></span>
        <?php if ($page < $pages): ?><a href="<?= h($url(['page' => $page + 1])) ?>" rel="next">Next →</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($related): ?>
    <section style="margin-top:28px" aria-labelledby="relTitle">
      <h2 id="relTitle">Related topics and guides</h2>
      <ul class="linkList"><?php foreach ($related as $r): ?><li><a href="<?= h($r['url']) ?>"><?= h($r['label']) ?></a></li><?php endforeach; ?></ul>
    </section>
  <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/layout_bottom.php'; ?>
