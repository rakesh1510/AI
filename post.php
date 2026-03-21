
<?php
require_once __DIR__ . '/helpers.php';
$slug = trim($_GET['slug'] ?? '');
$p = $slug ? find_post($slug) : null;

if(!$p){
  http_response_code(404);
  $title = "Post not found — AIHubpedia";
  require __DIR__ . '/layout_top.php';
  echo "<h1>Post not found</h1><p class='small'>The requested post does not exist.</p>";
  require __DIR__ . '/layout_bottom.php';
  exit;
}

$title = ($p['title'] ?? 'Post') . " — AIHubpedia";
$meta_desc = $p['excerpt'] ?? '';
require __DIR__ . '/layout_top.php';
?>
<h1><?= h($p['title'] ?? '') ?></h1>
<div class="small"><?= h($p['date'] ?? '') ?></div>
<div class="card" style="margin-top:14px">
  <?= $p['content'] ?? '' ?>
</div>
<div class="actions" style="margin-top:14px">
  <a class="btn secondary" href="blog.php">Back to blog</a>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
