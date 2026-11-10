<?php
require_once __DIR__ . '/helpers.php';
$title = "Leaderboard — AIHubpedia";
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

require __DIR__ . '/layout_top.php';
?>

<section class="leaderboardHero">
  <div class="leaderboardHero__content">
    <span class="leaderboardEyebrow">AIHubpedia rankings</span>
    <h1>Leaderboard</h1>
    <p>Top AI tools ranked by real user activity on AIHubpedia. The popularity score uses clicks, likes, and dislikes so visitors can understand why a tool is ranked high.</p>
    <!--<div class="leaderboardFormula">Popularity score = (Likes × 3) + Clicks − (Dislikes × 2)</div> -->
  </div>
</section>

<?php if($top3): ?>
<section class="leaderTop3">
  <?php foreach($top3 as $i => $t): $p = $t['_popularity']; ?>
    <article class="leaderPodium leaderPodium--<?= $i+1 ?>">
      <div class="leaderPodium__rank">#<?= $i+1 ?></div>
      <div class="leaderPodium__head">
        <img class="logo" src="<?= h(tool_logo($t)) ?>" alt="" />
        <div>
          <h2><a href="tool.php?id=<?= urlencode($t['id']) ?>"><?= h($t['name']) ?></a></h2>
          <div class="small"><?= h($t['category'] ?? 'AI Tool') ?></div>
        </div>
      </div>
      <p><?= h($t['short'] ?? 'Popular AI tool on AIHubpedia.') ?></p>
      <div class="leaderStats">
        <div class="leaderStat"><strong><?= (int)$p['clicks'] ?></strong><span>Clicks</span></div>
        <div class="leaderStat"><strong><?= (int)$p['likes'] ?></strong><span>Likes</span></div>
        <div class="leaderStat"><strong><?= (int)$p['dislikes'] ?></strong><span>Dislikes</span></div>
      </div>
      <div class="leaderScoreRow">
        <span class="leaderScoreLabel">Popularity Score</span>
        <strong><?= (int)$p['score'] ?></strong>
      </div>
      <div class="actions">
        <a class="btn secondary" href="tool.php?id=<?= urlencode($t['id']) ?>">Details</a>
        <a class="btn" target="_blank" rel="noopener" href="redirect.php?id=<?= urlencode($t['id']) ?>">Open tool</a>
      </div>
    </article>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<section class="leaderboardInfo card">
  <h2>How popularity is decided</h2>
  <p class="small">A higher rank means users are opening the tool more often and the community is reacting positively to it. Likes carry more weight than clicks, while dislikes reduce the score.</p>
</section>

<section class="card leaderboardTableWrap">
  <table class="pretty leaderboardTable">
    <thead>
      <tr>
        <th>Rank</th>
        <th>Tool</th>
        <th>Clicks</th>
        <th>Likes</th>
        <th>Dislikes</th>
        <th>Popularity</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($tools as $idx => $t): $p = $t['_popularity']; ?>
      <tr>
        <td><span class="leaderRankBadge"><?= $idx + 1 ?></span></td>
        <td>
          <div class="leaderToolCell">
            <img class="logo" src="<?= h(tool_logo($t)) ?>" alt="" />
            <div>
              <a class="leaderToolName" href="tool.php?id=<?= urlencode($t['id']) ?>"><?= h($t['name']) ?></a>
              <div class="small"><?= h($t['category'] ?? 'AI Tool') ?></div>
            </div>
          </div>
        </td>
        <td><?= (int)$p['clicks'] ?></td>
        <td><?= (int)$p['likes'] ?></td>
        <td><?= (int)$p['dislikes'] ?></td>
        <td><strong><?= (int)$p['score'] ?></strong></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<?php require __DIR__ . '/layout_bottom.php'; ?>
