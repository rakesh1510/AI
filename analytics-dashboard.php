<?php
require_once 'helpers.php';
$data = load_tools();
$tools = $data['tools'] ?? [];
$total = count($tools);
$top = $tools;
usort($top, function($x,$y){ return trending_score($y['id'] ?? '') <=> trending_score($x['id'] ?? ''); });
$top = array_slice($top, 0, 5);
require __DIR__ . '/layout_top.php';
?>
<section class="hero"><h1>Analytics Dashboard</h1><p class="small">Starter platform analytics.</p></section>
<section class="grid">
<div class="card"><h3>Total Tools</h3><p><?= $total ?></p></div>
<div class="card"><h3>Top Trending</h3><p><?= htmlspecialchars($top[0]['name'] ?? 'N/A') ?></p></div>
<div class="card"><h3>SEO Pages</h3><p>4</p></div>
</section>
<section class="card"><h2 style="margin-top:0">Top 5 tools</h2><table class="pretty">
<tr><th>Tool</th><th>Score</th></tr>
<?php foreach($top as $t): ?><tr><td><?= htmlspecialchars($t['name']) ?></td><td><?= number_format(trending_score($t['id'] ?? ''),1) ?></td></tr><?php endforeach; ?>
</table></section>
<?php require __DIR__ . '/layout_bottom.php'; ?>