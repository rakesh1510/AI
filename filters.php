<?php
require_once 'helpers.php';
$data = load_tools();
$tools = $data['tools'] ?? [];
$q = strtolower(trim($_GET['q'] ?? ''));
$category = strtolower(trim($_GET['category'] ?? ''));
$price = strtolower(trim($_GET['price'] ?? ''));
$filtered = array_values(array_filter($tools, function($t) use ($q,$category,$price){
  $ok = true;
  if($q !== ''){
    $ok = $ok && (stripos($t['name'] ?? '', $q) !== false || stripos($t['short'] ?? '', $q) !== false);
  }
  if($category !== '') $ok = $ok && strtolower($t['category'] ?? '') === $category;
  if($price !== ''){
    $pricing = strtolower(($t['pricing'] ?? $t['price'] ?? ''));
    $ok = $ok && stripos($pricing, $price) !== false;
  }
  return $ok;
}));
require __DIR__ . '/layout_top.php';
?>
<section class="hero"><h1>Advanced Filters</h1><p class="small">Filter tools by keyword, category, and price.</p></section>
<section class="card">
<form method="get" class="grid">
<div><label>Keyword</label><input class="input" name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>"></div>
<div><label>Category</label><input class="input" name="category" value="<?= htmlspecialchars($_GET['category'] ?? '') ?>"></div>
<div><label>Price</label><select class="input" name="price"><option value="">Any</option><option value="free">Free</option><option value="paid">Paid</option><option value="freemium">Freemium</option></select></div>
<div style="align-self:end"><button class="btn">Filter</button></div>
</form>
</section>
<section class="grid">
<?php foreach($filtered as $t): ?>
  <div class="card">
    <h3><a href="tool.php?id=<?= urlencode($t['id']) ?>"><?= htmlspecialchars($t['name']) ?></a></h3>
    <p><?= htmlspecialchars($t['short'] ?? '') ?></p>
  </div>
<?php endforeach; ?>
</section>
<?php require __DIR__ . '/layout_bottom.php'; ?>