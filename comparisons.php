<?php
require_once __DIR__ . '/helpers.php';
$data = load_tools();
$tools = $data['tools'] ?? [];
$pairs = available_comparison_pairs($tools, 24);

// Legacy static comparison pages generated into /compare (if any exist on the server).
$dir = __DIR__ . '/compare';
$files = [];
if (is_dir($dir)) {
  foreach (scandir($dir) as $f) {
    if (substr($f, -4) === '.php') $files[] = $f;
  }
}
sort($files);

$crumbs = [['Home', site_url('/')], ['AI tool comparisons', null]];
$items = [];
foreach ($pairs as $i => [$a, $b]) {
  $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'url' => compare_page_url($a['id'], $b['id']), 'name' => $a['name'] . ' vs ' . $b['name']];
}
$jsonld = [
  ['@type' => 'CollectionPage', '@id' => site_url('/comparisons.php#webpage'), 'url' => site_url('/comparisons.php'), 'name' => 'AI Tool Comparisons',
   'isPartOf' => ['@id' => site_url('/#website')], 'mainEntity' => ['@type' => 'ItemList', 'numberOfItems' => count($items), 'itemListElement' => $items]],
  seo_breadcrumb_node($crumbs),
];
require __DIR__ . '/layout_top.php';
?>
<?= render_breadcrumbs($crumbs) ?>
<h1>AI tool comparisons</h1>
<p class="small">Popular head-to-head comparisons. You can also <a href="/compare.php">compare any two AI tools</a> side by side.</p>

<div class="grid">
<?php foreach ($pairs as [$a, $b]): ?>
  <div class="card">
    <h2 style="margin:0 0 6px;font-size:1.17em"><a href="<?= h(compare_path($a['id'], $b['id'])) ?>"><?= h($a['name']) ?> vs <?= h($b['name']) ?></a></h2>
    <p class="small"><?= h($a['category']) ?><?= $a['category'] !== $b['category'] ? ' / ' . h($b['category']) : '' ?> · <?= h($a['pricing'] ?: 'Pricing varies') ?> vs <?= h($b['pricing'] ?: 'Pricing varies') ?></p>
    <div class="actions">
      <a class="btn" href="<?= h(compare_path($a['id'], $b['id'])) ?>">Compare</a>
    </div>
  </div>
<?php endforeach; ?>
</div>

<?php if ($files): ?>
<section class="card" style="margin-top:22px">
  <h2 style="margin-top:0">More comparison pages</h2>
  <ul class="linkList">
    <?php foreach (array_slice($files, 0, 200) as $f): ?>
      <li><a href="/compare/<?= h(rawurlencode($f)) ?>"><?= h(ucwords(str_replace(['-vs-', '-', '.php'], [' vs ', ' ', ''], $f))) ?></a></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php require __DIR__ . '/layout_bottom.php'; ?>
