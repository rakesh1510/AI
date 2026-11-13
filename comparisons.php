<?php
require_once __DIR__ . '/helpers.php';
$title = 'Comparison Drafts — AIHubpedia';
$robots_meta = 'noindex,follow';
$dir = __DIR__ . '/compare';
$files = [];
if(is_dir($dir)){
  foreach(scandir($dir) as $f){
    if($f === '.' || $f === '..') continue;
    if(substr($f,-4) === '.php') $files[] = $f;
  }
}
sort($files);
require __DIR__ . '/layout_top.php';
?>
<h1>Comparison pages</h1>
<p class="small">These comparison drafts have not been reviewed. <a href="/compare.php">Compare recorded tool information</a>.</p>

<div class="grid">
<?php foreach(array_slice($files,0,200) as $f): ?>
  <div class="card">
    <h3 style="margin:0 0 6px"><?= h(str_replace('.php','',$f)) ?></h3>
    <div class="actions">
      <a class="btn" href="compare/<?= h($f) ?>">Open</a>
    </div>
  </div>
<?php endforeach; ?>
</div>

<?php require __DIR__ . '/layout_bottom.php'; ?>

