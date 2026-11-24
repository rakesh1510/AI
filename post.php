<?php
require_once __DIR__ . '/helpers.php';
$slug = trim($_GET['slug'] ?? '');
$p = $slug ? find_post($slug) : null;

if(!$p){
  http_response_code(404);
  $title = "Article Not Found";
  $meta_desc = "The article you requested could not be found on AIHubpedia.";
  $main_class = 'fullbleed';
  require __DIR__ . '/layout_top.php';
  echo '<div class="wrap pgBody">' . state_box(['art' => 'lost', 'eyebrow' => 'Article not found', 'title' => 'Post not found', 'text' => 'The requested post does not exist.', 'actions' => '<a class="btn btn--accent" href="/blog.php">Back to blog</a>']) . '</div>';
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
$main_class = 'fullbleed';
require __DIR__ . '/layout_top.php';
$__words = str_word_count(strip_tags((string)($p['content'] ?? ''))); $__mins = max(1, (int)ceil($__words / 200));
$__dd = ($t = strtotime($date)) ? date('j F Y', $t) : $date;
?>
<section class="pgHero stage pgHero--sm">
  <div class="wrap">
    <div>
      <div class="pgHero__crumbs" style="padding:0 0 14px"><?= render_breadcrumbs($crumbs) ?></div>
      <span class="eyebrow"><?= ui_icon('pen') ?> Article</span>
      <h1 style="font-size:clamp(2.2rem,1.4rem + 3vw,3.4rem)"><?= h($p['title'] ?? '') ?></h1>
      <?php if ($date !== ''): ?><p class="lead" style="margin:0">Published <time datetime="<?= h($article['datePublished'] ?? $date) ?>"><?= h($__dd) ?></time> · AIHubpedia · <?= (int)$__mins ?> min read</p><?php endif; ?>
    </div>
    <div class="pgHero__art" aria-hidden="true"><?= art_thumb('Chatbots', 0) ?></div>
  </div>
</section>
<div class="wrap pgBody">
  <article class="articleBox"><div class="docBody"><?= $p['content'] ?? '' ?></div></article>
  <div class="sectionHead" style="margin-top:var(--s-5)"><div><h2>Explore AI tools</h2></div></div>
  <ul class="exploreRow">
    <li><a class="bento__cell" href="/categories.php"><span class="ico3d" style="--c1:#8b5cf6;--c2:#ec4899"><?= ui_icon('layers') ?></span><h3>All AI tool categories</h3><p>Browse every category with counts.</p></a></li>
    <li><a class="bento__cell" href="/top.php"><span class="ico3d" style="--c1:#f59e0b;--c2:#ef4444"><?= ui_icon('chart') ?></span><h3>Top AI tools</h3><p>Ranked by the published formula.</p></a></li>
    <li><a class="bento__cell" href="/comparisons.php"><span class="ico3d" style="--c1:#06b6d4;--c2:#2563eb"><?= ui_icon('compare') ?></span><h3>Popular comparisons</h3><p>Head-to-head pairings.</p></a></li>
  </ul>
  <div class="actions" style="margin-top:var(--s-4)"><a class="btn secondary" href="/blog.php">Back to blog</a></div>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
