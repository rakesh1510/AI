<?php
require_once __DIR__ . '/helpers.php';
$data = load_tools(); $tools = $data['tools'] ?? [];

// Score each tool once, then sort (previously recomputed inside the comparator: thousands of queries).
$scores = [];
foreach($tools as $t){ $scores[$t['id'] ?? ''] = trending_score($t['id'] ?? ''); }
usort($tools, function($a,$b) use ($scores){
  return ($scores[$b['id'] ?? ''] ?? 0) <=> ($scores[$a['id'] ?? ''] ?? 0);
});
$crumbs = [['Home', site_url('/')], ['Top AI tools', null]];
$jsonld = [seo_tool_itemlist_node(array_slice($tools, 0, 60), 'Top AI tools on AIHubpedia'), seo_breadcrumb_node($crumbs)];

require __DIR__ . '/layout_top.php';
?>
<?= render_breadcrumbs($crumbs) ?>
<h1>Top AI tools</h1>
<p class="small">Ranked by AIHubpedia visitor activity: outbound clicks plus visitor star ratings. Rankings reflect activity on this site only, not market share.</p>

<div class="grid">
<?php foreach(array_slice($tools, 0, 60) as $t): [$avg,$cnt]=rating_avg($t['id']); ?>
  <div class="card">
    <div style="display:flex;gap:10px;align-items:center">
      <img class="logo" src="<?= h(tool_logo($t)) ?>" alt="<?= h($t['name']) ?> logo" width="48" height="48" loading="lazy" decoding="async" />
      <div>
        <h2 style="margin:0 0 4px;font-size:1.17em"><a href="/tool.php?id=<?= urlencode($t['id']) ?>"><?= h($t['name']) ?></a></h2>
        <div class="small"><a href="<?= h(category_path($t['category'])) ?>"><?= h($t['category']) ?></a> • Rating: <?= $cnt?number_format($avg,1):'—' ?> (<?= (int)$cnt ?>) • Clicks: <?= (int)click_count($t['id']) ?></div>
      </div>
    </div>
    <p style="margin-top:10px"><?= h($t['short'] ?? '') ?></p>
    <div class="actions">
      <a class="btn secondary" href="/tool.php?id=<?= urlencode($t['id']) ?>">Details</a>
      <a class="btn" target="_blank" rel="nofollow noopener" href="/redirect.php?id=<?= urlencode($t['id']) ?>">Open tool</a>
    </div>
    <?= render_vote_buttons($t['id'], 'top.php') ?>
  </div>
<?php endforeach; ?>
</div>

<?php require __DIR__ . '/layout_bottom.php'; ?>
