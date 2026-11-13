<?php
require_once __DIR__ . '/helpers.php';

function tool_cta_url(array $t): string {
  if (!empty($t['affiliate']))  return $t['affiliate'];
  if (!empty($t['signup_url'])) return $t['signup_url'];
  return $t['website'] ?? '#';
}

/* ─────────────────────────────────────────────
   PAGE BOOTSTRAP
   ───────────────────────────────────────────── */
$id = seo_param($_GET, 'id');
$t  = $id ? find_tool($id) : null;

if (!$t) {
  http_response_code(404);
  $title = "Tool not found — AIHubpedia";
  require __DIR__ . '/layout_top.php';
  echo "<h1>Tool not found</h1><p class='small'>The requested tool does not exist.</p>";
  require __DIR__ . '/layout_bottom.php';
  exit;
}

$title = seo_text($t['seo_title'] ?? '', $t['name'] . ' — Features, Pricing & Alternatives | AIHubpedia');
$meta_desc = seo_text($t['seo_description'] ?? '', seo_text($t['short'] ?? '', 'Explore ' . $t['name'] . ': listed features, pricing information, use cases, and alternatives.'));
$canonical = seo_url('/tool.php', ['id' => $t['id']]);
$breadcrumbs = [['name' => 'Home', 'url' => seo_url('/')],
  ['name' => $t['category'], 'url' => seo_url('/category.php', ['name' => $t['category']])],
  ['name' => $t['name'], 'url' => $canonical]];
$page_schemas = [seo_breadcrumb_schema($breadcrumbs), [
  '@context' => 'https://schema.org', '@type' => 'SoftwareApplication',
  'name' => $t['name'], 'url' => $canonical, 'applicationCategory' => $t['category'],
  'description' => seo_text($t['description'] ?? '', $t['short'] ?? '')
]];
// Deliberately omit offers, ratings, and review dates without verified evidence.
require __DIR__ . '/layout_top.php';

[$avg, $cnt] = rating_avg($t['id']);
$clicks = click_count($t['id']);

// Alternatives: same category
$data = load_tools();
$alts = array_values(array_filter($data['tools'], function ($x) use ($t) {
  return ($x['id'] ?? '') !== ($t['id'] ?? '') && ($x['category'] ?? '') === ($t['category'] ?? '');
}));
$alts = array_slice($alts, 0, 6);

// Display recorded fields only; never infer product claims from category names.
$features = is_array($t['features'] ?? null) ? $t['features'] : [];
$pros = is_array($t['pros'] ?? null) ? $t['pros'] : [];
$cons = is_array($t['cons'] ?? null) ? $t['cons'] : [];
$use_cases = is_array($t['use_cases'] ?? null) ? $t['use_cases'] : [];
$tags = is_array($t['tags'] ?? null) ? $t['tags'] : [];

$cta_url = seo_http_url(tool_cta_url($t));
$official_url = seo_http_url($t['website'] ?? '');
?>

<?php seo_breadcrumbs($breadcrumbs); ?>
<div class="card">
  <div style="display:flex;gap:12px;align-items:flex-start">
    <img class="logo" style="width:64px;height:64px" src="<?= h(tool_logo($t)) ?>" alt="<?= h($t['name']) ?> logo" width="64" height="64" />
    <div style="flex:1">
      <h1 style="margin:0 0 6px"><?= h($t['name']) ?></h1>
      <div class="small">
        Category: <b><?= h($t['category']) ?></b> &bull;
        Pricing: <b><?= h(seo_text($t['pricing'] ?? '', 'Not recorded')) ?></b> &bull;
        Rating: <?= $cnt ? number_format($avg, 1) : '—' ?> (<?= (int)$cnt ?>) &bull;
        Clicks: <?= (int)$clicks ?>
      </div>
      <p style="margin-top:12px"><?= h($t['description'] ?? $t['short'] ?? '') ?></p>
      <div class="actions">
        <?php if ($cta_url): ?><a class="btn" target="_blank" rel="noopener<?= !empty($t['affiliate']) ? ' sponsored' : '' ?>" href="<?= h($cta_url) ?>" data-conversion="tool_outbound">Visit tool</a><?php endif; ?>
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

  <p class="small">Pricing verification date is not recorded. Check the provider for current prices and terms.
    <?php if ($official_url): ?><a href="<?= h($official_url) ?>" rel="noopener" target="_blank">Provider website</a>.<?php endif; ?>
    <?php if (!empty($t['affiliate'])): ?>This listing contains an affiliate link. <a href="/affiliate-disclosure.php">Read our disclosure</a>.<?php endif; ?>
    <a href="/editorial-policy.php">How listings are maintained</a>.</p>
  <hr>

  <!-- TAGS -->
  <?php if (!empty($tags)): ?>
  <div class="kv">
    <div class="small"><b>Tags</b></div>
    <div>
      <?php foreach ($tags as $tag): ?>
        <span class="badge gray"><?= h($tag) ?></span>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- FEATURES -->
  <?php if (!empty($features)): ?>
  <div class="kv">
    <div class="small"><b>Features</b></div>
    <div>
      <ul>
        <?php foreach ($features as $f): ?>
          <li><?= h($f) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <?php endif; ?>

  <!-- USE CASES -->
  <?php if (!empty($use_cases)): ?>
  <div class="kv">
    <div class="small"><b>Use Cases</b></div>
    <div>
      <ul>
        <?php foreach ($use_cases as $uc): ?>
          <li><?= h($uc) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <?php endif; ?>

  <!-- PROS -->
  <?php if (!empty($pros)): ?>
  <div class="kv">
    <div class="small"><b>Pros</b></div>
    <div>
      <ul>
        <?php foreach ($pros as $p): ?>
          <li><?= h($p) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <?php endif; ?>

  <!-- CONS -->
  <?php if (!empty($cons)): ?>
  <div class="kv">
    <div class="small"><b>Cons</b></div>
    <div>
      <ul>
        <?php foreach ($cons as $c): ?>
          <li><?= h($c) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <?php endif; ?>

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

  <?php if (count($alts)): ?>
    <h2>Alternatives in <?= h($t['category']) ?></h2>
    <div class="grid">
      <?php foreach ($alts as $a): ?>
        <div class="card">
          <div style="display:flex;gap:10px;align-items:center">
            <img class="logo" src="<?= h(tool_logo($a)) ?>" alt="<?= h($a['name']) ?> logo" width="56" height="56" loading="lazy" />
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
    <a class="btn secondary" href="toggle-bookmark.php?id=<?= urlencode($t['id']) ?>">Save / Remove Bookmark</a>
    <a class="btn secondary" href="compare-tools.php?a=<?= urlencode($t['id']) ?>">Compare this tool</a>
    <a class="btn secondary" href="login.php">Demo Login</a>
  </div>

</div>


<div id="community" class="card" style="margin-top:16px">
  <h2 style="margin-top:0">Community Interaction</h2>
  <p class="small">Comments, discussions, and voting help create engagement.</p>
  <?= render_vote_buttons($t['id'], 'tool.php?id=' . urlencode($t['id']) . '#community') ?>
  <?php $communityVotes = tool_vote_breakdown($t['id']); ?>
  <p class="small" style="margin-top:10px">
    Likes: <?= (int)$communityVotes['up'] ?> &bull;
    Dislikes: <?= (int)$communityVotes['down'] ?> &bull;
    Score: <?= (int)tool_vote_total($t['id']) ?> from <?= (int)tool_vote_count($t['id']) ?> votes
  </p>

  <h3 style="margin-top:18px">Discussion</h3>
  <?php $comments_demo = tool_comments($t['id']); ?>
  <?php if ($comments_demo): ?>
    <?php foreach ($comments_demo as $c): ?>
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
    <input type="hidden" name="tool_id" value="<?= htmlspecialchars($t['id']) ?>">
    <label>Your name</label>
    <input class="input" name="name" placeholder="Your name">
    <label style="margin-top:12px;display:block">Comment</label>
    <textarea class="input" name="comment" rows="5" placeholder="Share your experience with this tool"></textarea>
    <p style="margin-top:12px"><button class="btn">Post comment</button></p>
  </form>
</div>

<?php require __DIR__ . '/layout_bottom.php'; ?>

