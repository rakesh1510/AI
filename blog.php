<?php
require_once __DIR__ . '/helpers.php';
$posts = load_posts();
usort($posts, function($a,$b){ return strcmp($b['date'] ?? '', $a['date'] ?? ''); });
$crumbs = [['Home', site_url('/')], ['Blog', null]];
$__items = [];
foreach(array_values($posts) as $i => $p){ $__items[] = ['@type'=>'ListItem','position'=>$i+1,'url'=>post_page_url((string)($p['slug'] ?? '')),'name'=>(string)($p['title'] ?? '')]; }
$jsonld = [['@type'=>'Blog','@id'=>site_url('/blog.php#blog'),'url'=>site_url('/blog.php'),'name'=>'AIHubpedia Blog','publisher'=>['@id'=>site_url('/#organization')],
  'mainEntity'=>['@type'=>'ItemList','itemListElement'=>$__items]], seo_breadcrumb_node($crumbs)];
require __DIR__ . '/layout_top.php';
?>
<?= render_breadcrumbs($crumbs) ?>
<h1>AI tools blog</h1>
<p class="small">Guides, lists, and updates to help you choose the right AI tools.</p>

<div class="grid">
<?php foreach($posts as $p): ?>
  <div class="card">
    <h2 style="margin:0 0 6px;font-size:1.17em"><a href="/post.php?slug=<?= urlencode($p['slug'] ?? '') ?>"><?= h($p['title'] ?? '') ?></a></h2>
    <div class="small"><?= h($p['date'] ?? '') ?></div>
    <p style="margin-top:10px"><?= h($p['excerpt'] ?? '') ?></p>
    <div class="actions">
      <a class="btn" href="/post.php?slug=<?= urlencode($p['slug'] ?? '') ?>">Read</a>
    </div>
  </div>
<?php endforeach; ?>
</div>

<?php require __DIR__ . '/layout_bottom.php'; ?>
