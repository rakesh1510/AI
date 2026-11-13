<?php
require_once __DIR__ . '/helpers.php';
$data = load_tools(); $tools = $data['tools'] ?? [];
$name = seo_param($_GET, 'name');
$items = array_values(array_filter($tools, fn($t) => ($t['category'] ?? '') === $name));
if ($name === '' || !$items) {
  http_response_code(404);
  $title = 'Category not found — AIHubpedia';
  require __DIR__ . '/layout_top.php';
  echo '<h1>Category not found</h1><p><a href="/categories.php">Browse available categories</a></p>';
  require __DIR__ . '/layout_bottom.php';
  exit;
}
$title = $name . ' AI Tools — Features & Alternatives | AIHubpedia';
$meta_desc = 'Explore ' . count($items) . ' listed ' . $name . ' AI tools. Compare descriptions, pricing information and alternatives for your workflow.';
$canonical = seo_url('/category.php', ['name' => $name]);
$breadcrumbs = [['name' => 'Home', 'url' => seo_url('/')], ['name' => 'Categories', 'url' => seo_url('/categories.php')], ['name' => $name, 'url' => $canonical]];
$page_schemas = [seo_breadcrumb_schema($breadcrumbs), [
  '@context' => 'https://schema.org', '@type' => 'CollectionPage',
  'name' => $title, 'description' => $meta_desc, 'url' => $canonical,
  'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => array_map(fn($t, $i) => [
    '@type' => 'ListItem', 'position' => $i + 1, 'name' => $t['name'],
    'url' => seo_url('/tool.php', ['id' => $t['id']])
  ], $items, array_keys($items))]
]];
require __DIR__ . '/layout_top.php';
seo_breadcrumbs($breadcrumbs);
?>
<h1><?= h($name) ?> AI tools</h1>
<p><?= h($meta_desc) ?> Pricing can change; confirm details with each provider.</p>

<div class="grid">
  <?php foreach($items as $t): ?>
    <div class="card">
      <div style="display:flex;gap:10px;align-items:center">
        <img class="logo" src="<?= h(tool_logo($t)) ?>" alt="<?= h($t['name']) ?> logo" width="56" height="56" loading="lazy" />
        <div>
          <h3 style="margin:0 0 4px"><?= h($t['name']) ?></h3>
          <div class="small"><?= h($t['pricing'] ?? 'Varies') ?></div>
        </div>
      </div>
      <p style="margin-top:10px"><?= h($t['short'] ?? '') ?></p>
      <div class="actions">
        <a class="btn secondary" href="tool.php?id=<?= urlencode($t['id']) ?>">Details</a>
        <a class="btn" target="_blank" rel="noopener sponsored" href="redirect.php?id=<?= urlencode($t['id']) ?>">Open tool</a>
      </div>
      <?= render_vote_buttons($t['id'], 'category.php?name=' . urlencode($name)) ?>
    </div>
  <?php endforeach; ?>
</div>

<?php require __DIR__ . '/layout_bottom.php'; ?>

