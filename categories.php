<?php
require_once __DIR__ . '/helpers.php';
$data = load_tools(); $tools = $data['tools'] ?? []; $cats = $data['categories'] ?? [];

// Group once (previously a nested loop over every tool for every category).
$byCat = [];
foreach ($tools as $t) { $byCat[(string)($t['category'] ?? 'Other')][] = $t; }
foreach ($byCat as &$list) {
  usort($list, fn($a, $b) => (int)tool_has_generic_profile($a) <=> (int)tool_has_generic_profile($b) ?: strcasecmp($a['name'] ?? '', $b['name'] ?? ''));
}
unset($list);

$crumbs = [['Home', site_url('/')], ['Categories', null]];
$catItems = [];
foreach (array_values(array_filter($cats, fn($c) => $c !== CATALOG_UNCLASSIFIED)) as $i => $c) {
  $catItems[] = ['@type' => 'ListItem', 'position' => $i + 1, 'url' => category_page_url($c), 'name' => $c . ' AI tools'];
}
$jsonld = [
  ['@type' => 'CollectionPage', '@id' => site_url('/categories.php#webpage'), 'url' => site_url('/categories.php'),
   'name' => 'AI Tool Categories', 'isPartOf' => ['@id' => site_url('/#website')],
   'mainEntity' => ['@type' => 'ItemList', 'numberOfItems' => count($catItems), 'itemListElement' => $catItems]],
  seo_breadcrumb_node($crumbs),
];
$main_class = 'fullbleed';
require __DIR__ . '/layout_top.php';
$nCats = count(array_filter($cats, fn($c) => $c !== CATALOG_UNCLASSIFIED));
?>
<section class="pgHero stage" data-tone="<?= h(page_tone()) ?>">
  <div class="wrap">
    <div>
    <div class="pgHero__crumbs"><?= render_breadcrumbs($crumbs) ?></div>
    <div>
      <span class="eyebrow"><?= ui_icon('layers') ?> Browse by category</span>
      <h1>AI tool <span class="gradText2">categories</span></h1>
      <p class="lead">Browse <?= h(number_format(count($tools))) ?> AI tools across <?= (int)count($cats) ?> categories. Pick a category, then compare plans, limits and sources side by side.</p>
      <div class="catFilter" role="search"><?= ui_icon('search') ?><label class="sr" for="catFilter">Filter categories</label><input id="catFilter" type="search" placeholder="Filter categories, e.g. “writing”" autocomplete="off" data-cat-filter></div>
    </div>
    </div>
    <div class="pgHero__art" aria-hidden="true"><?= art_cubes(true) ?></div>
  </div>
</section>

<section class="section wrap" aria-label="Category list">
  <ul class="catGrid">
  <?php foreach ($cats as $c):
    $list = $byCat[$c] ?? []; $label = $c === CATALOG_UNCLASSIFIED ? 'Other' : $c; [$c1, $c2, $ico] = cat_theme((string)$label);
    $subs = [];
    foreach ($list as $t) { $sname = (string)($t['subcategory'] ?? ''); if ($sname !== '') $subs[$sname] = ($subs[$sname] ?? 0) + 1; }
    arsort($subs);
  ?>
    <li data-cat-name="<?= h(strtolower($label . ' ' . implode(' ', array_keys($subs)))) ?>">
      <article class="catTile" style="--c1:<?= h($c1) ?>;--c2:<?= h($c2) ?>">
        <span class="catIcon" aria-hidden="true"><?= ui_icon($ico) ?></span>
        <h2><a href="<?= h(category_path($c)) ?>"><?= h($label) ?> AI tools</a></h2>
        <span class="catCount"><?= h(seo_plural(count($list), 'tool')) ?></span>
        <p><?= h(category_intro($c)) ?></p>
        <?php if (count($subs) > 1 && $c !== CATALOG_UNCLASSIFIED): ?>
          <ul class="catSubs">
            <?php foreach (array_slice($subs, 0, 3, true) as $sname => $n): ?>
              <li><a href="<?= h(category_path($c, 1, $sname)) ?>"><?= h($sname) ?> (<?= (int)$n ?>)</a></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <div class="catFoot">
          <span class="logoStack" aria-hidden="true"><?php foreach (array_slice($list, 0, 4) as $t) echo logo_tile($t, 'sm'); ?></span>
          <span class="catGo">Open <?= h($label) ?> <?= ui_icon('arrow') ?></span>
        </div>
      </article>
    </li>
  <?php endforeach; ?>
  </ul>
  <p class="catEmpty" data-cat-empty hidden>No category matches that filter. <a href="/categories.php">Show all categories</a>.</p>
</section>

<?php require __DIR__ . '/layout_bottom.php'; ?>
