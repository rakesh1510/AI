
<?php
require_once __DIR__ . '/helpers.php';
$title = "Top AI Tools — AIHubpedia";
$data = load_tools(); $tools = $data['tools'] ?? [];

usort($tools, function($a,$b){
  $sa = trending_score($a['id'] ?? '');
  $sb = trending_score($b['id'] ?? '');
  if($sa==$sb) return 0;
  return ($sa>$sb) ? -1 : 1;
});

require __DIR__ . '/layout_top.php';
?>
<h1>Top tools</h1>
<p class="small">Ranked by clicks + ratings.</p>

<div class="grid">
<?php foreach(array_slice($tools, 0, 60) as $t): [$avg,$cnt]=rating_avg($t['id']); ?>
  <div class="card">
    <div style="display:flex;gap:10px;align-items:center">
      <img class="logo" src="<?= h(tool_logo($t)) ?>" alt="" />
      <div>
        <h3 style="margin:0 0 4px"><?= h($t['name']) ?></h3>
        <div class="small"><?= h($t['category']) ?> • Rating: <?= $cnt?number_format($avg,1):'—' ?> (<?= (int)$cnt ?>) • Clicks: <?= (int)click_count($t['id']) ?></div>
      </div>
    </div>
    <p style="margin-top:10px"><?= h($t['short'] ?? '') ?></p>
    <div class="actions">
      <a class="btn secondary" href="tool.php?id=<?= urlencode($t['id']) ?>">Details</a>
      <a class="btn" target="_blank" rel="noopener" href="redirect.php?id=<?= urlencode($t['id']) ?>">Open tool</a>
    </div>
    <?= render_vote_buttons($t['id'], 'top.php') ?>
  </div>
<?php endforeach; ?>
</div>

<?php require __DIR__ . '/layout_bottom.php'; ?>
