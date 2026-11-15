<?php
require_once __DIR__ . '/helpers.php';
$guides = guides_all();
$title = 'AI Tool Guides: Comparisons Built From Vendor Sources';
$meta_desc = 'Plain-language AI tool guides comparing plans, free-tier limits, pricing and policies, with source links and dates so you can check every claim.';
$crumbs = [['Home', site_url('/')], ['Guides', null]];
$items = [];
$i = 0;
foreach ($guides as $slug => $g) { $items[] = ['@type' => 'ListItem', 'position' => ++$i, 'url' => guide_url($slug), 'name' => $g['title']]; }
$jsonld = [
  ['@type' => 'CollectionPage', '@id' => site_url('/guides.php#webpage'), 'url' => site_url('/guides.php'), 'name' => 'AI tool guides', 'isPartOf' => ['@id' => site_url('/#website')],
   'mainEntity' => ['@type' => 'ItemList', 'numberOfItems' => count($items), 'itemListElement' => $items]],
  seo_breadcrumb_node($crumbs),
];
require __DIR__ . '/layout_top.php';
?>
<?= render_breadcrumbs($crumbs) ?>
<h1>AI tool guides</h1>
<p class="prose">Each guide compares tools using the vendors' own pricing and help pages, shows the date the facts were collected, and links to every source. We say plainly where vendor pages disagree and where we have not tested a claim. <a href="/methodology.php">Read our methodology</a>.</p>
<div class="grid">
<?php foreach ($guides as $slug => $g): ?>
  <div class="card">
    <h2 style="margin:0 0 6px;font-size:1.17em"><a href="<?= h(guide_path($slug)) ?>"><?= h($g['h1']) ?></a></h2>
    <p class="small"><?= h($g['description']) ?></p>
    <p class="small">Updated <?= h($g['updated']) ?> · <?= count($g['tools']) ?> tools compared</p>
    <div class="actions"><a class="btn" href="<?= h(guide_path($slug)) ?>">Read the guide</a></div>
  </div>
<?php endforeach; ?>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
