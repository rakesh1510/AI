<?php
require_once __DIR__ . '/helpers.php';
$posts = load_posts();
usort($posts, function($a,$b){ return strcmp($b['date'] ?? '', $a['date'] ?? ''); });
$crumbs = [['Home', site_url('/')], ['Blog', null]];
if(!posts_indexable()) $robots = 'noindex';   // an index of only thin posts is itself thin
$__items = [];
foreach(array_values($posts) as $i => $p){ $__items[] = ['@type'=>'ListItem','position'=>$i+1,'url'=>post_page_url((string)($p['slug'] ?? '')),'name'=>(string)($p['title'] ?? '')]; }
$jsonld = [['@type'=>'Blog','@id'=>site_url('/blog.php#blog'),'url'=>site_url('/blog.php'),'name'=>'AIHubpedia Blog','publisher'=>['@id'=>site_url('/#organization')],
  'mainEntity'=>['@type'=>'ItemList','itemListElement'=>$__items]], seo_breadcrumb_node($crumbs)];
$main_class = 'fullbleed';
require __DIR__ . '/layout_top.php';
$__mins = fn(array $p): int => max(1, (int)ceil(str_word_count(strip_tags((string)($p['content'] ?? ''))) / 200));
$__d = fn(string $d) => ($t = strtotime($d)) ? date('j M Y', $t) : $d;
echo page_hero(['crumbs' => $crumbs, 'eyebrow' => 'The AIHubpedia blog', 'icon' => 'pen', 'title_html' => 'AI tools blog: <span class="gradText2">guides, lists and updates</span>', 'lead' => 'Guides, lists, and updates to help you choose the right AI tools.', 'art' => 'book']);
?>
<div class="wrap pgBody">
<?php if ($posts): $f = $posts[0]; ?>
  <article class="magFeature" style="--c1:#8b5cf6;--c2:#2563eb">
    <?= art_thumb('Chatbots', 0) ?>
    <div class="magFeature__body">
      <span class="kicker">Latest article</span>
      <h2><a href="/post.php?slug=<?= urlencode($f['slug'] ?? '') ?>" style="text-decoration:none;color:inherit"><?= h($f['title'] ?? '') ?></a></h2>
      <p><?= h($f['excerpt'] ?? '') ?></p>
      <div class="metaRow"><span><?= h($__d((string)($f['date'] ?? ''))) ?></span><span><?= (int)$__mins($f) ?> min read</span></div>
      <a class="btn btn--accent" style="width:max-content" href="/post.php?slug=<?= urlencode($f['slug'] ?? '') ?>">Read the article <?= ui_icon('arrow') ?></a>
    </div>
  </article>
  <?php $rest = array_slice($posts, 1); if ($rest): ?>
  <div class="sectionHead" style="margin-top:var(--s-5)"><div><h2>More articles</h2></div></div>
  <div class="gGrid">
    <?php foreach ($rest as $n => $p): ?>
      <article class="gCard" style="--c1:#06b6d4;--c2:#2563eb">
        <span class="kicker">Article</span>
        <?= art_thumb('Research', $n + 1) ?>
        <div class="gCard__body"><h3><a href="/post.php?slug=<?= urlencode($p['slug'] ?? '') ?>"><?= h($p['title'] ?? '') ?></a></h3><p><?= h($p['excerpt'] ?? '') ?></p>
          <div class="metaRow"><span><?= h($__d((string)($p['date'] ?? ''))) ?></span><span><?= (int)$__mins($p) ?> min read</span></div></div>
      </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
<?php else: ?>
  <div class="emptyMini"><p><strong>No articles yet.</strong> Browse the <a href="/guides.php">guides</a> in the meantime.</p></div>
<?php endif; ?>
  <div class="sectionHead" style="margin-top:var(--s-5)"><div><h2>Keep exploring</h2></div></div>
  <ul class="exploreRow">
    <li><a class="bento__cell" href="/guides.php"><span class="ico3d" style="--c1:#8b5cf6;--c2:#ec4899"><?= ui_icon('book') ?></span><h3>Guides</h3><p>Comparisons built from vendor sources, with dates.</p></a></li>
    <li><a class="bento__cell" href="/top.php"><span class="ico3d" style="--c1:#f59e0b;--c2:#ef4444"><?= ui_icon('chart') ?></span><h3>Top AI tools</h3><p>Ranked by the published formula.</p></a></li>
    <li><a class="bento__cell" href="/comparisons.php"><span class="ico3d" style="--c1:#06b6d4;--c2:#2563eb"><?= ui_icon('compare') ?></span><h3>Popular comparisons</h3><p>Head-to-head pairings with logos and pricing.</p></a></li>
  </ul>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
