
<?php
require_once __DIR__ . '/helpers.php';
$id = trim($_GET['id'] ?? '');
$t = $id ? find_tool($id) : null;

if(!$t){
  http_response_code(404);
  $title = "Tool not found — AIHubpedia";
  require __DIR__ . '/layout_top.php';
  echo "<h1>Tool not found</h1><p class='small'>The requested tool does not exist.</p>";
  require __DIR__ . '/layout_bottom.php';
  exit;
}

$title = ($t['seo_title'] ?? ($t['name'] . " — AIHubpedia"));
$meta_desc = ($t['seo_description'] ?? ($t['short'] ?? ''));
require __DIR__ . '/layout_top.php';

[$avg,$cnt] = rating_avg($t['id']);
$clicks = click_count($t['id']);

// alternatives: same category
$data = load_tools();
$alts = array_values(array_filter($data['tools'], function($x) use ($t){
  return ($x['id'] ?? '') !== ($t['id'] ?? '') && ($x['category'] ?? '') === ($t['category'] ?? '');
}));
$alts = array_slice($alts, 0, 6);
?>

<div class="card">
  <div style="display:flex;gap:12px;align-items:flex-start">
    <img class="logo" style="width:64px;height:64px" src="<?= h(tool_logo($t)) ?>" alt="" />
    <div style="flex:1">
      <h1 style="margin:0 0 6px"><?= h($t['name']) ?></h1>
      <div class="small">
        Category: <b><?= h($t['category']) ?></b> • Pricing: <b><?= h($t['pricing'] ?? 'Varies') ?></b> • Rating: <?= $cnt?number_format($avg,1):'—' ?> (<?= (int)$cnt ?>) • Clicks: <?= (int)$clicks ?>
      </div>
      <p style="margin-top:12px"><?= h($t['description'] ?? $t['short'] ?? '') ?></p>
      <div class="actions">
        <a class="btn" target="_blank" rel="noopener" href="redirect.php?id=<?= urlencode($t['id']) ?>">Open official website</a>
        <a class="btn secondary" href="compare.php?a=<?= urlencode($t['id']) ?>">Compare</a>
      </div>
      <?php $voteBreakdown = tool_vote_breakdown($t['id']); ?>
      <div class="voteSummary">
        <span class="votePill">👍 Likes: <?= (int)$voteBreakdown['up'] ?></span>
        <span class="votePill">👎 Dislikes: <?= (int)$voteBreakdown['down'] ?></span>
        <span class="votePill">Score: <?= (int)tool_vote_total($t['id']) ?></span>
      </div>
      <?= render_vote_buttons($t['id'], 'tool.php?id=' . urlencode($t['id']) . '#community') ?>
    </div>
  </div>

  <hr>

  <div class="kv">
    <div class="small"><b>Tags</b></div>
    <div>
      <?php foreach(($t['tags'] ?? []) as $tag): ?>
        <span class="badge gray"><?= h($tag) ?></span>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="kv">
    <div class="small"><b>Features</b></div>
    <div>
      <ul>
        <?php foreach(($t['features'] ?? []) as $f): ?><li><?= h($f) ?></li><?php endforeach; ?>
      </ul>
    </div>
  </div>

  <div class="kv">
    <div class="small"><b>Pros</b></div>
    <div>
      <ul>
        <?php foreach(($t['pros'] ?? []) as $p): ?><li><?= h($p) ?></li><?php endforeach; ?>
      </ul>
    </div>
  </div>

  <div class="kv">
    <div class="small"><b>Cons</b></div>
    <div>
      <ul>
        <?php foreach(($t['cons'] ?? []) as $c): ?><li><?= h($c) ?></li><?php endforeach; ?>
      </ul>
    </div>
  </div>

  <div class="ad">Ad placeholder (Inside tool page)</div>

  <h2>Rate this tool</h2>
  <form method="post" action="rate.php" class="card" style="background:rgba(255,255,255,.03)">
    <input type="hidden" name="id" value="<?= h($t['id']) ?>">
    <label class="small">Stars</label>
    <select name="stars">
      <option value="5">★★★★★ (5)</option>
      <option value="4">★★★★☆ (4)</option>
      <option value="3">★★★☆☆ (3)</option>
      <option value="2">★★☆☆☆ (2)</option>
      <option value="1">★☆☆☆☆ (1)</option>
    </select>
    <div class="actions" style="margin-top:10px">
      <button class="btn" type="submit">Submit rating</button>
      <a class="btn secondary" href="tool.php?id=<?= urlencode($t['id']) ?>">Refresh</a>
    </div>
  </form>

  <?php if(count($alts)): ?>
    <h2>Alternatives in <?= h($t['category']) ?></h2>
    <div class="grid">
      <?php foreach($alts as $a): ?>
        <div class="card">
          <div style="display:flex;gap:10px;align-items:center">
            <img class="logo" src="<?= h(tool_logo($a)) ?>" alt="" />
            <div>
              <h3 style="margin:0 0 4px"><?= h($a['name']) ?></h3>
              <div class="small"><?= h($a['pricing'] ?? 'Varies') ?></div>
            </div>
          </div>
          <p style="margin-top:10px"><?= h($a['short'] ?? '') ?></p>
          <div class="actions">
            <a class="btn secondary" href="tool.php?id=<?= urlencode($a['id']) ?>">Details</a>
            <a class="btn" href="compare.php?a=<?= urlencode($t['id']) ?>&b=<?= urlencode($a['id']) ?>">Compare</a>
          </div>
          <?= render_vote_buttons($a['id'], 'tool.php?id=' . urlencode($t['id'])) ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>


<div class="card" style="margin-top:16px">
  <h2 style="margin-top:0">User actions</h2>
  <div class="actions">
    <a class="btn secondary" href="toggle-bookmark.php?id=<?= urlencode($t['id'] ?? ($_GET['id'] ?? '')) ?>">Save / Remove Bookmark</a>
    <a class="btn secondary" href="compare-tools.php?a=<?= urlencode($t['id'] ?? ($_GET['id'] ?? '')) ?>">Compare this tool</a>
    <a class="btn secondary" href="login.php">Demo Login</a>
  </div>
  <p class="small" style="margin-top:10px">Average demo rating: <?= number_format(avg_rating_demo($t['id'] ?? ($_GET['id'] ?? '')), 1) ?>/5</p>
</div>


<div id="community" class="card" style="margin-top:16px">
  <h2 style="margin-top:0">Community Interaction</h2>
  <p class="small">Comments, discussions, and voting help create engagement.</p>
  <?= render_vote_buttons($t['id'] ?? ($_GET['id'] ?? ''), 'tool.php?id=' . urlencode($t['id'] ?? ($_GET['id'] ?? '')) . '#community') ?>
  <?php $communityVotes = tool_vote_breakdown($t['id'] ?? ($_GET['id'] ?? '')); ?>
  <p class="small" style="margin-top:10px">Likes: <?= (int)$communityVotes['up'] ?> • Dislikes: <?= (int)$communityVotes['down'] ?> • Score: <?= (int)tool_vote_total($t['id'] ?? ($_GET['id'] ?? '')) ?> from <?= (int)tool_vote_count($t['id'] ?? ($_GET['id'] ?? '')) ?> votes</p>

  <h3 style="margin-top:18px">Discussion</h3>
  <?php $comments_demo = tool_comments($t['id'] ?? ($_GET['id'] ?? '')); ?>
  <?php if($comments_demo): ?>
    <?php foreach($comments_demo as $c): ?>
      <div class="noteBox" style="margin-bottom:10px">
        <strong><?= htmlspecialchars($c['name'] ?? 'Anonymous') ?></strong>
        <div class="small"><?= htmlspecialchars($c['created_at'] ?? '') ?></div>
        <p style="margin:8px 0 0"><?= nl2br(htmlspecialchars($c['comment'] ?? '')) ?></p>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <div class="noteBox">No comments yet. Start the discussion.</div>
  <?php endif; ?>

  <form method="post" action="submit-comment.php" style="margin-top:14px">
    <input type="hidden" name="tool_id" value="<?= htmlspecialchars($t['id'] ?? ($_GET['id'] ?? '')) ?>">
    <label>Your name</label>
    <input class="input" name="name" placeholder="Your name">
    <label style="margin-top:12px;display:block">Comment</label>
    <textarea class="input" name="comment" rows="5" placeholder="Share your experience with this tool"></textarea>
    <p style="margin-top:12px"><button class="btn">Post comment</button></p>
  </form>
</div>

<?php require __DIR__ . '/layout_bottom.php'; ?>
