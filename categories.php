
<?php
require_once __DIR__ . '/helpers.php';
$title = "Categories — AIHubpedia";
$data = load_tools(); $tools = $data['tools'] ?? []; $cats = $data['categories'] ?? [];
require __DIR__ . '/layout_top.php';
?>
<h1>Categories</h1>
<p class="small">Browse AI tools by category.</p>

<div class="grid">
<?php foreach($cats as $c): 
  $count = 0;
  foreach($tools as $t){ if(($t['category'] ?? '') === $c) $count++; }
?>
  <div class="card">
    <h3 style="margin:0 0 6px"><?= h($c) ?></h3>
    <div class="small"><?= (int)$count ?> tools</div>
    <div class="actions">
      <a class="btn" href="category.php?name=<?= urlencode($c) ?>">Open</a>
    </div>
  </div>
<?php endforeach; ?>
</div>

<?php require __DIR__ . '/layout_bottom.php'; ?>
