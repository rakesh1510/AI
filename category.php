<?php
require_once __DIR__ . '/helpers.php';
$data = load_tools();
$tools = $data['tools'] ?? [];
$cats = $data['categories'] ?? [];
$name = trim($_GET['name'] ?? '');

$items = $name === '' ? [] : array_values(array_filter($tools, fn($t) => ($t['category'] ?? '') === $name));

// Unknown or empty categories are real 404s (previously a soft-404 with HTTP 200).
if ($name === '' || !$items) {
  http_response_code(404);
  $title = 'AI Tool Category Not Found';
  $meta_desc = 'This AI tool category does not exist on AIHubpedia.';
  require __DIR__ . '/layout_top.php';
  echo '<h1>Category not found</h1><p class="small">' . ($name === '' ? 'No category was specified.' : 'There is no AI tool category called “' . h($name) . '”.') . '</p>';
  echo '<div class="actions"><a class="btn" href="/categories.php">Browse all categories</a></div>';
  require __DIR__ . '/layout_bottom.php';
  exit;
}

// Tools with product-specific profiles first, then alphabetical (stable, crawlable ordering).
usort($items, function ($a, $b) {
  $ga = (int)tool_has_generic_profile($a);
  $gb = (int)tool_has_generic_profile($b);
  return $ga === $gb ? strcasecmp($a['name'] ?? '', $b['name'] ?? '') : $ga <=> $gb;
});

$perPage = 48;
$total = count($items);
$pages = max(1, (int)ceil($total / $perPage));
$page = max(1, (int)($_GET['page'] ?? 1));
if ($page > $pages) {
  http_response_code(404);
  $title = 'Page Not Found';
  require __DIR__ . '/layout_top.php';
  echo '<h1>Page not found</h1><div class="actions"><a class="btn" href="' . h(category_path($name)) . '">Back to ' . h($name) . ' AI tools</a></div>';
  require __DIR__ . '/layout_bottom.php';
  exit;
}
$offset = ($page - 1) * $perPage;
$pageItems = array_slice($items, $offset, $perPage);

$pricingCounts = array_count_values(array_map(fn($t) => (string)($t['pricing_type'] ?: ($t['pricing'] ?? 'Varies')), $items));
arsort($pricingCounts);
$examples = implode(', ', array_map(fn($t) => $t['name'], array_slice($items, 0, 3)));

$title = $name . ' AI Tools: ' . ($total === 1 ? '1 Option' : $total . ' Free & Paid Options') . ($page > 1 ? ' (Page ' . $page . ')' : '');
$meta_desc = $page > 1
  ? 'Page ' . $page . ' of ' . $pages . ': ' . strtolower($name) . ' AI tools ' . ($offset + 1) . '–' . min($offset + $perPage, $total) . ' of ' . $total . ', including ' . implode(', ', array_map(fn($t) => $t['name'], array_slice($pageItems, 0, 3))) . '. Compare pricing and alternatives.'
  : 'Browse ' . $total . ' ' . strtolower($name) . ' AI tools' . ($examples ? ', including ' . $examples : '') . '. Compare pricing, features and alternatives on AIHubpedia.';
$canonical = category_page_url($name, $page);

$head_extra = '';
if ($page > 1) $head_extra .= '<link rel="prev" href="' . h(category_page_url($name, $page - 1)) . '">';
if ($page < $pages) $head_extra .= '<link rel="next" href="' . h(category_page_url($name, $page + 1)) . '">';

$crumbs = [['Home', site_url('/')], ['Categories', site_url('/categories.php')], [$name . ' AI tools', null]];
$jsonld = [
  [
    '@type' => 'CollectionPage',
    '@id' => $canonical . '#webpage',
    'url' => $canonical,
    'name' => seo_title($title),
    'description' => $meta_desc,
    'isPartOf' => ['@id' => site_url('/#website')],
    'mainEntity' => seo_tool_itemlist_node($pageItems, $name . ' AI tools', $offset),
  ],
  seo_breadcrumb_node($crumbs),
];
require __DIR__ . '/layout_top.php';
?>
<?= render_breadcrumbs($crumbs) ?>
<h1><?= h($name) ?> AI tools</h1>
<p><?= h(category_intro($name)) ?></p>
<p class="small">
  <?= h(seo_plural((int)$total, 'tool')) ?> listed<?php if ($pricingCounts): ?> ·
  <?php $parts = []; foreach (array_slice($pricingCounts, 0, 4, true) as $label => $n) { $parts[] = (int)$n . ' ' . h($label); } echo implode(' · ', $parts); ?>
  <?php endif; ?>
  <?php if ($pages > 1): ?> · Page <?= (int)$page ?> of <?= (int)$pages ?><?php endif; ?>
</p>

<div class="grid">
  <?php foreach ($pageItems as $t): ?>
    <div class="card">
      <div style="display:flex;gap:10px;align-items:center">
        <img class="logo" src="<?= h(tool_logo($t)) ?>" alt="<?= h($t['name']) ?> logo" width="48" height="48" loading="lazy" decoding="async" />
        <div>
          <h2 style="margin:0 0 4px;font-size:1.17em"><a href="/tool.php?id=<?= urlencode($t['id']) ?>"><?= h($t['name']) ?></a></h2>
          <div class="small"><?= h($t['pricing'] ?? 'Varies') ?></div>
        </div>
      </div>
      <p style="margin-top:10px"><?= h($t['short'] ?? '') ?></p>
      <div class="actions">
        <a class="btn secondary" href="/tool.php?id=<?= urlencode($t['id']) ?>">Details</a>
        <a class="btn" target="_blank" rel="nofollow noopener" href="/redirect.php?id=<?= urlencode($t['id']) ?>">Open tool</a>
      </div>
      <?= render_vote_buttons($t['id'], 'category.php?name=' . urlencode($name)) ?>
    </div>
  <?php endforeach; ?>
</div>

<?php if ($pages > 1): ?>
<nav class="pager" aria-label="Pagination">
  <?php if ($page > 1): ?><a href="<?= h(category_path($name, $page - 1)) ?>" rel="prev">← Previous</a><?php endif; ?>
  <?php for ($i = 1; $i <= $pages; $i++): ?>
    <?php if ($i === $page): ?><span aria-current="page"><?= $i ?></span>
    <?php else: ?><a href="<?= h(category_path($name, $i)) ?>"><?= $i ?></a><?php endif; ?>
  <?php endfor; ?>
  <?php if ($page < $pages): ?><a href="<?= h(category_path($name, $page + 1)) ?>" rel="next">Next →</a><?php endif; ?>
</nav>
<?php endif; ?>

<section class="card" style="margin-top:22px">
  <h2 style="margin-top:0">Other AI tool categories</h2>
  <ul class="linkList">
    <?php foreach ($cats as $c): if ($c === $name) continue; ?>
      <li><a href="<?= h(category_path($c)) ?>"><?= h($c) ?> AI tools</a></li>
    <?php endforeach; ?>
  </ul>
</section>

<?php require __DIR__ . '/layout_bottom.php'; ?>
