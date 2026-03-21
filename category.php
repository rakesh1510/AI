
<?php
require_once __DIR__ . '/helpers.php';
$data = load_tools(); $tools = $data['tools'] ?? [];
$name = trim($_GET['name'] ?? '');
$title = ($name ? $name : "Category") . " — AIHubpedia";
require __DIR__ . '/layout_top.php';

if($name===''){
  echo "<p class='small'>Missing category.</p>";
  require __DIR__ . '/layout_bottom.php';
  exit;
}

$items = array_values(array_filter($tools, fn($t)=>($t['category']??'')===$name));
?>
<h1><?= h($name) ?></h1>
<p class="small"><?= count($items) ?> tools</p>

<div class="grid">
  <?php foreach($items as $t): ?>
    <div class="card">
      <div style="display:flex;gap:10px;align-items:center">
        <img class="logo" src="<?= h(tool_logo($t)) ?>" alt="" />
        <div>
          <h3 style="margin:0 0 4px"><?= h($t['name']) ?></h3>
          <div class="small"><?= h($t['pricing'] ?? 'Varies') ?></div>
        </div>
      </div>
      <p style="margin-top:10px"><?= h($t['short'] ?? '') ?></p>
      <div class="actions">
        <a class="btn secondary" href="tool.php?id=<?= urlencode($t['id']) ?>">Details</a>
        <a class="btn" target="_blank" rel="noopener" href="redirect.php?id=<?= urlencode($t['id']) ?>">Open tool</a>
      </div>
      <?= render_vote_buttons($t['id'], 'category.php?name=' . urlencode($name)) ?>
    </div>
  <?php endforeach; ?>
</div>

<?php require __DIR__ . '/layout_bottom.php'; ?>
