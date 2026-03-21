
<?php
require_once __DIR__ . '/helpers.php';
$title = "Blog — AIHubpedia";
$posts = load_posts();
usort($posts, function($a,$b){ return strcmp($b['date'] ?? '', $a['date'] ?? ''); });
require __DIR__ . '/layout_top.php';
?>
<h1>Blog</h1>
<p class="small">Guides, lists, and updates to help you choose the right AI tools.</p>

<div class="grid">
<?php foreach($posts as $p): ?>
  <div class="card">
    <h3 style="margin:0 0 6px"><?= h($p['title'] ?? '') ?></h3>
    <div class="small"><?= h($p['date'] ?? '') ?></div>
    <p style="margin-top:10px"><?= h($p['excerpt'] ?? '') ?></p>
    <div class="actions">
      <a class="btn" href="post.php?slug=<?= urlencode($p['slug'] ?? '') ?>">Read</a>
    </div>
  </div>
<?php endforeach; ?>
</div>

<?php require __DIR__ . '/layout_bottom.php'; ?>
