<?php
require_once __DIR__ . '/helpers.php';

$title = "AI Tool Categories — AIHubpedia";
$meta_desc = "Browse AI tools by category including automation, chatbots, coding, image generation, SEO, productivity, audio, and design.";

$data = load_tools();
$tools = $data['tools'] ?? [];
$cats  = $data['categories'] ?? [];

// Count tools per category
$catCounts = [];
foreach($cats as $c){
  $catCounts[$c] = 0;
}
foreach($tools as $t){
  $category = $t['category'] ?? 'Other';
  if(!isset($catCounts[$category])) $catCounts[$category] = 0;
  $catCounts[$category]++;
}

// Sort categories by tool count
arsort($catCounts);

require __DIR__ . '/layout_top.php';
?>

<section class="hero">
  <h1>🧭 Explore AI Tools by Category</h1>
  <p class="small">
    Find the best AI tools for automation, coding, chatbots, image generation, SEO,
    productivity, audio, design, and more.
  </p>

  <div class="trust" style="margin-top:18px;display:flex;gap:14px;flex-wrap:wrap">
    <span class="badge">🔥 <?= number_format(count($tools)) ?>+ AI tools</span>
    <span class="badge">📂 <?= number_format(count($catCounts)) ?>+ categories</span>
    <span class="badge">🚀 Curated for fast discovery</span>
  </div>
</section>

<section class="card">
  <h2 style="margin-top:0">🔥 Popular Categories</h2>
  <p class="small">Start with the biggest AI tool collections.</p>

  <div class="grid">
    <?php foreach(array_slice($catCounts, 0, 6, true) as $c => $count): ?>
      <div class="card" style="background:rgba(255,255,255,.03)">
        <div class="badge" style="margin-bottom:10px">Popular</div>
        <h3 style="margin:0 0 8px"><?= h($c) ?></h3>
        <p class="small"><?= (int)$count ?> AI tools available</p>

        <div class="actions">
          <a class="btn" href="category.php?name=<?= urlencode($c) ?>">
            🚀 Explore <?= h($c) ?>
          </a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="card">
  <h2 style="margin-top:0">📂 All Categories</h2>
  <p class="small">Browse every AIHubpedia category.</p>

  <div class="grid">
    <?php foreach($catCounts as $c => $count): ?>
      <div class="card">
        <h3 style="margin:0 0 8px"><?= h($c) ?></h3>
        <div class="small">🔥 <?= (int)$count ?> tools</div>

        <div class="actions" style="margin-top:14px">
          <a class="btn" href="category.php?name=<?= urlencode($c) ?>">
            Open
          </a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<?php require __DIR__ . '/layout_bottom.php'; ?>