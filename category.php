<?php
require_once __DIR__ . '/helpers.php';
$data = load_tools();
$tools = $data['tools'] ?? [];
$cats = $data['categories'] ?? [];
$name = trim($_GET['name'] ?? '');
$subParam = trim($_GET['sub'] ?? '');

// Retired category names (e.g. SEO) permanently redirect to their new home.
if ($name !== '' && ($new = taxonomy_legacy_redirect($name))) {
  header('Location: ' . category_path($new), true, 301);
  exit;
}

$catTools = $name === '' ? [] : array_values(array_filter($tools, fn($t) => ($t['category'] ?? '') === $name));

// Unknown or empty categories are real 404s.
if ($name === '' || !$catTools) {
  http_response_code(404);
  $title = 'AI Tool Category Not Found';
  $meta_desc = 'This AI tool category does not exist on AIHubpedia.';
  require __DIR__ . '/layout_top.php';
  echo '<h1>Category not found</h1><p class="small">' . ($name === '' ? 'No category was specified.' : 'There is no AI tool category called “' . h($name) . '”.') . '</p>';
  echo '<div class="actions"><a class="btn" href="/categories.php">Browse all categories</a></div>';
  require __DIR__ . '/layout_bottom.php';
  exit;
}

// Subcategory filter (canonical, indexable landing pages when there are enough tools).
$sub = '';
if ($subParam !== '') {
  $sub = (string)subcategory_resolve($name, $subParam);
  if ($sub === '') {
    http_response_code(404);
    $title = 'AI Tool Subcategory Not Found';
    $meta_desc = 'This AI tool subcategory does not exist on AIHubpedia.';
    require __DIR__ . '/layout_top.php';
    echo '<h1>Subcategory not found</h1><div class="actions"><a class="btn" href="' . h(category_path($name)) . '">Back to ' . h($name) . ' AI tools</a></div>';
    require __DIR__ . '/layout_bottom.php';
    exit;
  }
}
$items = $sub === '' ? $catTools : array_values(array_filter($catTools, fn($t) => ($t['subcategory'] ?? '') === $sub));
if ($sub !== '' && !$items) {
  http_response_code(404);
  $title = 'No Tools In This Subcategory';
  require __DIR__ . '/layout_top.php';
  echo '<h1>No tools in this subcategory</h1><div class="actions"><a class="btn" href="' . h(category_path($name)) . '">Back to ' . h($name) . ' AI tools</a></div>';
  require __DIR__ . '/layout_bottom.php';
  exit;
}

// Reviewed profiles first, then standard, then basic; alphabetical within each tier.
$tierRank = ['reviewed' => 0, 'standard' => 1, 'basic' => 2];
usort($items, function ($a, $b) use ($tierRank) {
  $ra = $tierRank[tool_quality_tier($a)];
  $rb = $tierRank[tool_quality_tier($b)];
  return $ra === $rb ? strcasecmp($a['name'] ?? '', $b['name'] ?? '') : $ra <=> $rb;
});

$perPage = 48;
$total = count($items);
$pages = max(1, (int)ceil($total / $perPage));
$page = max(1, (int)($_GET['page'] ?? 1));
if ($page > $pages) {
  http_response_code(404);
  $title = 'Page Not Found';
  require __DIR__ . '/layout_top.php';
  echo '<h1>Page not found</h1><div class="actions"><a class="btn" href="' . h(category_path($name, 1, $sub)) . '">Back to ' . h($name) . ' AI tools</a></div>';
  require __DIR__ . '/layout_bottom.php';
  exit;
}
$offset = ($page - 1) * $perPage;
$pageItems = array_slice($items, $offset, $perPage);

// Subcategory counts for the chip row.
$subCounts = [];
foreach ($catTools as $t) { $s = (string)($t['subcategory'] ?? ''); if ($s !== '') $subCounts[$s] = ($subCounts[$s] ?? 0) + 1; }
arsort($subCounts);

$isUnclassified = ($name === CATALOG_UNCLASSIFIED);
$label = $sub !== '' ? $sub : $name;
$examples = implode(', ', array_map(fn($t) => $t['name'], array_slice($items, 0, 3)));
$heading = $isUnclassified ? 'Other AI tools (awaiting classification)' : ($sub !== '' ? $sub . ' AI tools' : $name . ' AI tools');

$title = $isUnclassified ? 'Other AI Tools' : ($sub !== '' ? $sub . ' AI Tools: ' : $name . ' AI Tools: ') . ($total === 1 ? '1 Option' : $total . ' Options Compared') . ($page > 1 ? ' (Page ' . $page . ')' : '');
$meta_desc = $page > 1
  ? 'Page ' . $page . ' of ' . $pages . ': ' . strtolower($label) . ' AI tools ' . ($offset + 1) . '–' . min($offset + $perPage, $total) . ' of ' . $total . ', including ' . implode(', ', array_map(fn($t) => $t['name'], array_slice($pageItems, 0, 3))) . '. Compare pricing and alternatives.'
  : 'Browse ' . $total . ' ' . strtolower($label) . ' AI tools' . ($examples ? ', including ' . $examples : '') . '. Compare pricing, features and alternatives on AIHubpedia.';
$canonical = category_page_url($name, $page, $sub);
// The catch-all bucket and very thin subcategory pages stay out of the index.
if ($isUnclassified || ($sub !== '' && $total < 3)) $robots = 'noindex';

$head_extra = '';
if ($page > 1) $head_extra .= '<link rel="prev" href="' . h(category_page_url($name, $page - 1, $sub)) . '">';
if ($page < $pages) $head_extra .= '<link rel="next" href="' . h(category_page_url($name, $page + 1, $sub)) . '">';

$crumbs = [['Home', site_url('/')], ['Categories', site_url('/categories.php')]];
if ($sub !== '') { $crumbs[] = [$name . ' AI tools', category_page_url($name)]; $crumbs[] = [$sub, null]; }
else { $crumbs[] = [$name . ' AI tools', null]; }
$jsonld = [
  [
    '@type' => 'CollectionPage',
    '@id' => $canonical . '#webpage',
    'url' => $canonical,
    'name' => seo_title($title),
    'description' => $meta_desc,
    'isPartOf' => ['@id' => site_url('/#website')],
    'mainEntity' => seo_tool_itemlist_node($pageItems, $label . ' AI tools', $offset),
  ],
  seo_breadcrumb_node($crumbs),
];
require __DIR__ . '/layout_top.php';
?>
<?= render_breadcrumbs($crumbs) ?>
<h1><?= h($heading) ?></h1>
<p><?= h($isUnclassified ? taxonomy_category_description($name) : ($sub !== '' ? 'A focused list of ' . strtolower($sub) . ' tools within ' . strtolower($name) . ' AI tools.' : taxonomy_category_description($name))) ?></p>
<p class="small">
  <?= h(seo_plural((int)$total, 'tool')) ?> listed
  <?php $rev = count(array_filter($items, fn($t) => !empty($t['curated']))); if ($rev): ?> · <?= (int)$rev ?> with <a href="/methodology.php">reviewed profiles</a><?php endif; ?>
  <?php if ($pages > 1): ?> · Page <?= (int)$page ?> of <?= (int)$pages ?><?php endif; ?>
</p>

<?php if (!$isUnclassified && count($subCounts) > 1): ?>
<nav aria-label="Subcategories">
  <ul class="linkList">
    <li><a href="<?= h(category_path($name)) ?>"<?= $sub === '' ? ' aria-current="page"' : '' ?>>All <?= h($name) ?> (<?= (int)count($catTools) ?>)</a></li>
    <?php foreach ($subCounts as $s => $n): ?>
      <li><a href="<?= h(category_path($name, 1, $s)) ?>"<?= $s === $sub ? ' aria-current="page"' : '' ?>><?= h($s) ?> (<?= (int)$n ?>)</a></li>
    <?php endforeach; ?>
  </ul>
</nav>
<?php endif; ?>

<div class="grid">
  <?php foreach ($pageItems as $t): $tier = tool_quality_tier($t); ?>
    <div class="card">
      <div style="display:flex;gap:10px;align-items:center">
        <img class="logo" src="<?= h(tool_logo($t)) ?>" alt="<?= h($t['name']) ?> logo" width="48" height="48" loading="lazy" decoding="async" />
        <div>
          <h2 style="margin:0 0 4px;font-size:1.17em"><a href="/tool.php?id=<?= urlencode($t['id']) ?>"><?= h($t['name']) ?></a></h2>
          <div class="small"><?= h($t['pricing'] ?? 'Varies') ?><?= !empty($t['subcategory']) && $sub === '' ? ' · ' . h($t['subcategory']) : '' ?></div>
        </div>
      </div>
      <p style="margin-top:10px"><?= h($t['short'] ?? '') ?></p>
      <?php if ($tier === 'reviewed'): ?><span class="badge">Reviewed profile</span><?php endif; ?>
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
  <?php if ($page > 1): ?><a href="<?= h(category_path($name, $page - 1, $sub)) ?>" rel="prev">← Previous</a><?php endif; ?>
  <?php for ($i = 1; $i <= $pages; $i++): ?>
    <?php if ($i === $page): ?><span aria-current="page"><?= $i ?></span>
    <?php else: ?><a href="<?= h(category_path($name, $i, $sub)) ?>"><?= $i ?></a><?php endif; ?>
  <?php endfor; ?>
  <?php if ($page < $pages): ?><a href="<?= h(category_path($name, $page + 1, $sub)) ?>" rel="next">Next →</a><?php endif; ?>
</nav>
<?php endif; ?>

<section class="card" style="margin-top:22px">
  <h2 style="margin-top:0">Other AI tool categories</h2>
  <ul class="linkList">
    <?php foreach ($cats as $c): if ($c === $name || $c === CATALOG_UNCLASSIFIED) continue; ?>
      <li><a href="<?= h(category_path($c)) ?>"><?= h($c) ?> AI tools</a></li>
    <?php endforeach; ?>
  </ul>
</section>

<?php require __DIR__ . '/layout_bottom.php'; ?>
