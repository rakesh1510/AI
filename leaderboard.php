<?php
require_once __DIR__ . '/helpers.php';
$data = load_tools();
$tools = $data['tools'] ?? [];

foreach($tools as &$tool){
  $tool['_popularity'] = popularity_breakdown($tool['id'] ?? '');
}
unset($tool);

usort($tools, function($a, $b){
  $sa = $a['_popularity']['score'] ?? 0;
  $sb = $b['_popularity']['score'] ?? 0;
  if($sa === $sb){
    return ($b['_popularity']['likes'] ?? 0) <=> ($a['_popularity']['likes'] ?? 0);
  }
  return $sb <=> $sa;
});

$tools = array_slice($tools, 0, 20);
$top3 = array_slice($tools, 0, 3);
$jsonld = [seo_tool_itemlist_node($tools, 'AIHubpedia AI tools leaderboard')];

$main_class = 'fullbleed';
require __DIR__ . '/layout_top.php';
$__max = 1; foreach ($tools as $t) { $__max = max($__max, (int)($t['_popularity']['score'] ?? 0)); }
$__medal = [1 => 'gold', 2 => 'silver', 3 => 'bronze'];
echo page_hero(['eyebrow' => 'AIHubpedia rankings', 'icon' => 'chart', 'title_html' => 'Leaderboard: <span class="gradText2">top tools by visitor activity</span>',
  'lead' => 'Top AI tools ranked by visitor activity on AIHubpedia. The popularity score uses clicks, likes, and dislikes so visitors can understand why a tool is ranked high.', 'art' => 'podium', 'crumbs' => [['Home', site_url('/')], ['Leaderboard', null]]]);
?>
<div class="wrap pgBody">
<?php if ($top3): ?>
  <section class="podium" aria-label="Top three tools">
    <?php foreach ($top3 as $i => $t): $p = $t['_popularity']; $rk = $i + 1; ?>
      <article class="podiumCard podiumCard--<?= $rk ?> medal--<?= $__medal[$rk] ?>">
        <span class="medal" aria-label="Rank <?= $rk ?>"><?= $rk ?></span>
        <?= logo_tile($t, 'xl') ?>
        <h2><a href="/tool.php?id=<?= urlencode($t['id']) ?>"><?= h($t['name']) ?></a></h2>
        <div class="small"><?= h($t['category'] ?? 'AI Tool') ?></div>
        <p><?= h($t['short'] ?? 'Popular AI tool on AIHubpedia.') ?></p>
        <ul class="rankStats rankStats--3"><li><strong><?= (int)$p['clicks'] ?></strong><span>Clicks</span></li><li><strong><?= (int)$p['likes'] ?></strong><span>Likes</span></li><li><strong><?= (int)$p['dislikes'] ?></strong><span>Dislikes</span></li></ul>
        <div class="scoreBar" role="img" aria-label="Popularity score <?= (int)$p['score'] ?>"><span class="scoreBar__label">Popularity score <strong><?= (int)$p['score'] ?></strong></span><span class="scoreBar__track"><span style="width:<?= max(4, (int)round(100 * (int)$p['score'] / $__max)) ?>%"></span></span></div>
        <div class="actions"><a class="btn secondary sm" href="/tool.php?id=<?= urlencode($t['id']) ?>">Details</a><a class="btn sm" target="_blank" rel="nofollow noopener" href="/redirect.php?id=<?= urlencode($t['id']) ?>">Open tool</a></div>
      </article>
    <?php endforeach; ?>
  </section>
<?php endif; ?>
  <section class="glass leaderInfo" style="margin:var(--s-5) 0 var(--s-3)">
    <span class="ico3d" style="--c1:#06b6d4;--c2:#2563eb"><?= ui_icon('shield') ?></span>
    <div><h2 style="margin:0 0 4px;font-size:1.35rem">How popularity is decided</h2>
    <p class="small" style="margin:0">A higher rank means users are opening the tool more often and the community is reacting positively to it. Likes carry more weight than clicks, while dislikes reduce the score.</p></div>
  </section>
  <div class="tableWrap" tabindex="0" role="region" aria-label="Leaderboard table, scrollable">
    <table class="pretty leaderboardTable">
      <caption class="sr">Top 20 AI tools by popularity score</caption>
      <thead><tr><th scope="col">Rank</th><th scope="col">Tool</th><th scope="col">Clicks</th><th scope="col">Likes</th><th scope="col">Dislikes</th><th scope="col">Popularity</th></tr></thead>
      <tbody>
      <?php foreach ($tools as $idx => $t): $p = $t['_popularity']; ?>
        <tr>
          <td><span class="leaderRankBadge"><?= $idx + 1 ?></span></td>
          <td><div class="leaderToolCell"><?= logo_tile($t, 'md') ?><div><a class="leaderToolName" href="/tool.php?id=<?= urlencode($t['id']) ?>"><?= h($t['name']) ?></a><div class="small"><?= h($t['category'] ?? 'AI Tool') ?></div></div></div></td>
          <td><?= (int)$p['clicks'] ?></td><td><?= (int)$p['likes'] ?></td><td><?= (int)$p['dislikes'] ?></td>
          <td><span class="scoreBar scoreBar--inline"><strong><?= (int)$p['score'] ?></strong><span class="scoreBar__track"><span style="width:<?= max(3, (int)round(100 * (int)$p['score'] / $__max)) ?>%"></span></span></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
