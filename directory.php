<?php
// Crawlable A to Z directory of every listed tool, paginated (48 per page) with rel prev/next and canonical URLs. It is the target of "Browse all tools": the homepage shows only a curated selection,
// while every tool stays reachable by search, category pages and this directory. No form, no write.
require_once __DIR__ . '/helpers.php';
$data = load_tools(); $tools = array_values($data['tools'] ?? []);
usort($tools, fn($a, $b) => strcasecmp((string)$a['name'], (string)$b['name']));
$perPage = 48; $total = count($tools); $pages = max(1, (int)ceil($total / $perPage));
$page = max(1, (int)($_GET['page'] ?? 1));
if ($page > $pages) { http_response_code(404); $title = 'Page Not Found'; require __DIR__ . '/layout_top.php'; echo '<h1>Page not found</h1><div class="actions"><a class="btn" href="/directory.php">Back to all tools</a></div>'; require __DIR__ . '/layout_bottom.php'; exit; }
$offset = ($page - 1) * $perPage; $items = array_slice($tools, $offset, $perPage);
$dirUrl = fn(int $p) => '/directory.php' . ($p > 1 ? '?page=' . $p : '');
$title = 'All AI Tools (A to Z)' . ($page > 1 ? ' — Page ' . $page : '') . ' | AIHubpedia';
$meta_desc = 'Every AI tool listed on AIHubpedia in alphabetical order' . ($page > 1 ? ', page ' . $page . ' of ' . $pages : '') . ': ' . number_format($total) . ' tools with categories, pricing notes and reviewed profiles where available.';
$canonical = site_url($dirUrl($page));
$head_extra = ($page > 1 ? '<link rel="prev" href="' . h(site_url($dirUrl($page - 1))) . '">' : '') . ($page < $pages ? '<link rel="next" href="' . h(site_url($dirUrl($page + 1))) . '">' : '');
$crumbs = [['Home', site_url('/')], ['All tools', null]];
$jsonld = [['@type' => 'CollectionPage', '@id' => $canonical . '#webpage', 'url' => $canonical, 'name' => seo_title($title), 'description' => $meta_desc, 'isPartOf' => ['@id' => site_url('/#website')], 'mainEntity' => seo_tool_itemlist_node($items, 'All AI tools', $offset)], seo_breadcrumb_node($crumbs)];
require __DIR__ . '/layout_top.php';
?>
<?= render_breadcrumbs($crumbs) ?>
<h1>All AI tools, A to Z</h1>
<p class="lead small"><?= h(number_format($total)) ?> tools listed<?php if ($pages > 1): ?> · Page <?= (int)$page ?> of <?= (int)$pages ?><?php endif; ?>. Prefer to start from a goal? <a href="/categories.php">Browse categories</a>, <a href="/recommend.php">use the recommendation wizard</a> or <a href="/search.php">search</a>.</p>
<div class="cardGrid">
  <?php foreach ($items as $t) echo render_tool_card($t, ['placement' => 'directory', 'heading' => 2]); ?>
</div>
<?php if ($pages > 1): ?>
<nav class="pager" aria-label="Pagination">
  <?php if ($page > 1): ?><a href="<?= h($dirUrl($page - 1)) ?>" rel="prev">← Previous</a><?php endif; ?>
  <?php for ($i = 1; $i <= $pages; $i++): ?>
    <?php if ($i === $page): ?><span aria-current="page"><?= $i ?></span><?php else: ?><a href="<?= h($dirUrl($i)) ?>"><?= $i ?></a><?php endif; ?>
  <?php endfor; ?>
  <?php if ($page < $pages): ?><a href="<?= h($dirUrl($page + 1)) ?>" rel="next">Next →</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php require __DIR__ . '/layout_bottom.php'; ?>
