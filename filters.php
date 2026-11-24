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
$main_class = 'fullbleed';
require __DIR__ . '/layout_top.php';
?>
<?php echo page_hero(['crumbs' => [['Home', site_url('/')], ['Filters', null]], 'eyebrow' => 'Filters', 'icon' => 'flow', 'title_html' => 'Advanced <span class="gradText2">filters</span>', 'lead' => 'Filter tools by keyword, category, and price.', 'solo' => true, 'size' => 'sm']); ?>
<div class="wrap pgBody">
<section class="card filtersCard">
<form method="get" class="grid">
<div><label for="fKw">Keyword</label><input class="input" id="fKw" name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>"></div>
<div><label for="fCat">Category</label><input class="input" id="fCat" name="category" value="<?= htmlspecialchars($_GET['category'] ?? '') ?>"></div>
<div><label for="fPrice">Price</label><select class="input" id="fPrice" name="price"><option value="">Any</option><option value="free">Free</option><option value="paid">Paid</option><option value="freemium">Freemium</option></select></div>
<div style="align-self:end"><button class="btn">Filter</button></div>
</form>
</section>
<section class="cardGrid" aria-label="Filtered tools" style="margin-top:var(--s-4)">
<?php foreach (array_slice($filtered, 0, 48) as $t) echo render_tool_card($t, ['placement' => 'filters', 'heading' => 3]); ?>
</section>
<?php if (!$filtered): ?><div class="emptyMini" style="margin-top:var(--s-4)">No tools match these filters. Try fewer words or clear the category.</div><?php endif; ?>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>