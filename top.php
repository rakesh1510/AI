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

$main_class = 'fullbleed';
require __DIR__ . '/layout_top.php';
$__top = array_slice($tools, 0, 60); $__cats = [];
foreach ($__top as $t) { $__cats[(string)$t['category']] = ($__cats[(string)$t['category']] ?? 0) + 1; }
arsort($__cats);
echo page_hero(['crumbs' => $crumbs, 'eyebrow' => 'Top AI tools', 'icon' => 'chart', 'title_html' => 'Top AI tools, <span class="gradText2">ranked by visitor activity</span>',
  'lead' => 'Ranked by AIHubpedia visitor activity: outbound clicks plus visitor star ratings. Rankings reflect activity on this site only, not market share.', 'art' => 'podium',
  'actions' => '<a class="btn btn--accent" href="/leaderboard.php">See the leaderboard</a><a class="btn secondary" href="/recommend.php">Get a shortlist</a>']);
?>
<div class="wrap pgBody">
  <div class="gNav" role="group" aria-label="Filter the top tools by category">
    <button type="button" hidden data-gfilter="*" aria-pressed="true">All</button>
    <?php foreach (array_slice(array_keys($__cats), 0, 8) as $c): ?><button type="button" hidden data-gfilter="<?= h($c) ?>" aria-pressed="false"><?= h($c) ?></button><?php endforeach; ?>
  </div>
  <div class="rankGrid">
  <?php foreach ($__top as $n => $t): [$avg, $cnt] = rating_avg($t['id']); $cc = cat_theme((string)$t['category']); ?>
    <article class="rankCard<?= $n < 3 ? ' rankCard--top' : '' ?>" data-gcat="<?= h($t['category']) ?>" style="--c1:<?= $cc[0] ?>;--c2:<?= $cc[1] ?>">
      <span class="rankNo" aria-label="Rank <?= $n + 1 ?>">#<?= $n + 1 ?></span>
      <div class="rankHead">
        <?= logo_tile($t, 'lg') ?>
        <div>
          <h2><a href="/tool.php?id=<?= urlencode($t['id']) ?>"><?= h($t['name']) ?></a></h2>
          <div class="metaRow"><span><a href="<?= h(category_path($t['category'])) ?>"><?= h($t['category']) ?></a></span><?php if (!empty($t['pricing'])): ?><span><?= h($t['pricing']) ?></span><?php endif; ?></div>
        </div>
      </div>
      <p class="rankDesc"><?= h($t['short'] ?? '') ?></p>
      <ul class="rankStats"><li><strong><?= $cnt ? number_format($avg, 1) : '—' ?></strong><span>Rating (<?= (int)$cnt ?>)</span></li><li><strong><?= (int)click_count($t['id']) ?></strong><span>Clicks</span></li></ul>
      <div class="actions">
        <a class="btn secondary sm" href="/tool.php?id=<?= urlencode($t['id']) ?>">Details</a>
        <a class="btn sm" target="_blank" rel="nofollow noopener" href="/redirect.php?id=<?= urlencode($t['id']) ?>">Open tool</a>
      </div>
      <?= render_vote_buttons($t['id'], 'top.php') ?>
    </article>
  <?php endforeach; ?>
  </div>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
