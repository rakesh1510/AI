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
$main_class = 'fullbleed';
require __DIR__ . '/layout_top.php';
echo page_hero(['crumbs' => $crumbs, 'eyebrow' => 'Head-to-head', 'icon' => 'compare', 'title_html' => 'AI tool comparisons, <span class="gradText2">side by side</span>',
  'lead' => 'Popular head-to-head comparisons. You can also <a href="/compare.php" style="text-decoration:underline">compare any two AI tools</a> side by side.', 'art' => 'podium',
  'actions' => '<a class="btn btn--accent" href="/compare.php">Compare any two tools</a>']);
?>
<div class="wrap pgBody">
  <ul class="vsGrid vsGrid--pricing">
  <?php foreach ($pairs as $n => [$a, $b]): $cc = cat_theme((string)$a['category']); ?>
    <li><a class="vsCard" href="<?= h(compare_path($a['id'], $b['id'])) ?>" style="--c1:<?= $cc[0] ?>;--c2:<?= $cc[1] ?>">
      <span class="vsCard__tools">
        <span class="vsCard__tool"><?= logo_tile($a, 'lg') ?><strong><?= h($a['name']) ?></strong><small><?= h($a['pricing'] ?: 'Pricing varies') ?></small></span>
        <span class="vsBadge" aria-hidden="true">VS</span>
        <span class="vsCard__tool"><?= logo_tile($b, 'lg') ?><strong><?= h($b['name']) ?></strong><small><?= h($b['pricing'] ?: 'Pricing varies') ?></small></span>
      </span>
      <span class="vsCard__meta"><?= h($a['category']) ?><?= $a['category'] !== $b['category'] ? ' / ' . h($b['category']) : '' ?></span>
      <span class="vsCard__go">Compare <?= h($a['name']) ?> vs <?= h($b['name']) ?> <?= ui_icon('arrow') ?></span>
    </a></li>
  <?php endforeach; ?>
  </ul>
<?php if ($files): ?>
  <section class="card" style="margin-top:var(--s-5)">
    <h2 style="margin-top:0;font-size:1.5rem">More comparison pages</h2>
    <ul class="linkList">
      <?php foreach (array_slice($files, 0, 200) as $f): ?>
        <li><a href="/compare/<?= h(rawurlencode($f)) ?>"><?= h(ucwords(str_replace(['-vs-', '-', '.php'], [' vs ', ' ', ''], $f))) ?></a></li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
