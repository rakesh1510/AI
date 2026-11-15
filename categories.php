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
foreach (array_values($cats) as $i => $c) {
  $catItems[] = ['@type' => 'ListItem', 'position' => $i + 1, 'url' => category_page_url($c), 'name' => $c . ' AI tools'];
}
$jsonld = [
  ['@type' => 'CollectionPage', '@id' => site_url('/categories.php#webpage'), 'url' => site_url('/categories.php'),
   'name' => 'AI Tool Categories', 'isPartOf' => ['@id' => site_url('/#website')],
   'mainEntity' => ['@type' => 'ItemList', 'numberOfItems' => count($catItems), 'itemListElement' => $catItems]],
  seo_breadcrumb_node($crumbs),
];
require __DIR__ . '/layout_top.php';
?>
<?= render_breadcrumbs($crumbs) ?>
<h1>AI tool categories</h1>
<p class="small">Browse <?= number_format(count($tools)) ?> AI tools across <?= count($cats) ?> categories.</p>

<div class="grid">
<?php foreach ($cats as $c): $list = $byCat[$c] ?? []; ?>
  <div class="card">
    <h2 style="margin:0 0 6px;font-size:1.17em"><a href="<?= h(category_path($c)) ?>"><?= h($c) ?> AI tools</a></h2>
    <div class="small"><?= h(seo_plural(count($list), 'tool')) ?></div>
    <p class="small" style="margin-top:8px"><?= h(category_intro($c)) ?></p>
    <?php if ($list): ?>
      <ul class="linkList">
        <?php foreach (array_slice($list, 0, 3) as $t): ?>
          <li><a href="/tool.php?id=<?= urlencode($t['id']) ?>"><?= h($t['name']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <div class="actions">
      <a class="btn" href="<?= h(category_path($c)) ?>">Open <?= h($c) ?></a>
    </div>
  </div>
<?php endforeach; ?>
</div>

<?php require __DIR__ . '/layout_bottom.php'; ?>
