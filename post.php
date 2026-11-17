<?php
require_once __DIR__ . '/helpers.php';
$slug = trim($_GET['slug'] ?? '');
$p = $slug ? find_post($slug) : null;

if(!$p){
  http_response_code(404);
  $title = "Article Not Found";
  $meta_desc = "The article you requested could not be found on AIHubpedia.";
  require __DIR__ . '/layout_top.php';
  echo "<h1>Post not found</h1><p class='small'>The requested post does not exist.</p>";
  echo "<div class='actions'><a class='btn' href='/blog.php'>Back to blog</a></div>";
  require __DIR__ . '/layout_bottom.php';
  exit;
}

$title = (string)($p['title'] ?? 'Article');
$meta_desc = (string)($p['excerpt'] ?? '') !== '' ? (string)$p['excerpt'] : seo_truncate((string)($p['content'] ?? ''), 155);
$canonical = post_page_url((string)$p['slug']);
$og_type = 'article';
if(!post_is_indexable($p)) $robots = 'noindex';   // thin posts stay readable but are not indexed
$date = (string)($p['date'] ?? '');
$crumbs = [['Home', site_url('/')], ['Blog', site_url('/blog.php')], [$title, null]];
$article = [
  '@type' => 'BlogPosting',
  '@id' => $canonical . '#article',
  'headline' => seo_truncate($title, 110),
  'description' => seo_description($meta_desc),
  'url' => $canonical,
  'mainEntityOfPage' => $canonical,
  'inLanguage' => 'en',
  'author' => ['@type' => 'Organization', 'name' => 'AIHubpedia', 'url' => site_url('/')],
  'publisher' => ['@id' => site_url('/#organization')],
  'image' => site_url('/logo.png'),
];
if($date !== '' && strtotime($date)){
  $article['datePublished'] = date('Y-m-d', strtotime($date));
  $article['dateModified'] = $article['datePublished'];
  $head_extra = '<meta property="article:published_time" content="' . h($article['datePublished']) . '">';
}
$jsonld = [$article, seo_breadcrumb_node($crumbs)];
require __DIR__ . '/layout_top.php';
?>
<?= render_breadcrumbs($crumbs) ?>
<article>
  <h1><?= h($p['title'] ?? '') ?></h1>
  <?php if($date !== ''): ?><div class="small">Published <time datetime="<?= h($article['datePublished'] ?? $date) ?>"><?= h($date) ?></time> · AIHubpedia</div><?php endif; ?>
  <div class="card" style="margin-top:14px">
    <?= $p['content'] ?? '' ?>
  </div>
</article>
<div class="card" style="margin-top:14px">
  <h2 style="margin-top:0">Explore AI tools</h2>
  <ul class="linkList">
    <li><a href="/categories.php">All AI tool categories</a></li>
    <li><a href="/top.php">Top AI tools</a></li>
    <li><a href="/comparisons.php">Popular comparisons</a></li>
  </ul>
</div>
<div class="actions" style="margin-top:14px">
  <a class="btn secondary" href="/blog.php">Back to blog</a>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
