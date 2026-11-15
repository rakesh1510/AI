<?php
require_once __DIR__ . '/helpers.php';
$id = trim($_GET['id'] ?? '');
$t = $id ? find_tool($id) : null;

if(!$t){
  http_response_code(404);
  $title = "AI Tool Not Found";
  $meta_desc = "The AI tool you requested is not listed on AIHubpedia.";
  require __DIR__ . '/layout_top.php';
  echo "<h1>Tool not found</h1><p class='small'>The requested tool does not exist or is no longer listed.</p>";
  echo "<div class='actions'><a class='btn' href='/'>Browse all AI tools</a><a class='btn secondary' href='/categories.php'>Browse categories</a></div>";
  require __DIR__ . '/layout_bottom.php';
  exit;
}

$title = tool_seo_title($t);
$meta_desc = tool_seo_description($t);
$canonical = tool_page_url($t['id']);
$og_image = (preg_match('#^https?://#', (string)($t['logo'] ?? '')) && strpos((string)$t['logo'], 'google.com/s2/favicons') === false) ? $t['logo'] : null;

[$avg,$cnt] = rating_avg($t['id']);
$clicks = click_count($t['id']);
$genericProfile = tool_has_generic_profile($t);

// alternatives: same category, tools with richer profiles first
$data = load_tools();
$alts = array_values(array_filter($data['tools'], function($x) use ($t){
  return ($x['id'] ?? '') !== ($t['id'] ?? '') && ($x['category'] ?? '') === ($t['category'] ?? '');
}));
usort($alts, function($x, $y){ return (int)tool_has_generic_profile($x) <=> (int)tool_has_generic_profile($y); });
$cp = $t['curated'] ?? null;
$curatedAlts = $cp ? curated_alternatives($t, tools_by_id(), 8) : [];
if($curatedAlts){
  $alts = $curatedAlts;
  $subtitle = ($t['subcategory'] ?? '') !== '' ? $t['subcategory'] : $t['category'];
} else {
  $alts = array_slice($alts, 0, 6);
}
$hasAltPage = count($curatedAlts) >= 3;

$catUrl = category_page_url((string)$t['category']);
$crumbs = [['Home', site_url('/')], [$t['category'] . ' AI tools', $catUrl]];
if(($t['subcategory'] ?? '') !== '' && $t['category'] !== CATALOG_UNCLASSIFIED){ $crumbs[] = [$t['subcategory'], category_page_url((string)$t['category'], 1, (string)$t['subcategory'])]; }
$crumbs[] = [$t['name'], null];
if(!tool_is_indexable($t) || $t['category'] === CATALOG_UNCLASSIFIED && tool_quality_tier($t) === 'basic' && thin_tool_policy() === 'noindex'){ $robots = 'noindex'; }
$jsonld = [
  seo_tool_node($t, $canonical, [$avg, $cnt]),
  seo_breadcrumb_node($crumbs),
];
require __DIR__ . '/layout_top.php';
?>
<?= render_breadcrumbs($crumbs) ?>
<div hidden data-tool-view data-id="<?= h($t['id']) ?>" data-name="<?= h($t['name']) ?>" data-category="<?= h($t['category']) ?>"></div>

<div class="card">
  <div style="display:flex;gap:12px;align-items:flex-start">
    <img class="logo" style="width:64px;height:64px" src="<?= h(tool_logo($t)) ?>" alt="<?= h($t['name']) ?> logo" width="64" height="64" decoding="async" />
    <div style="flex:1">
      <h1 style="margin:0 0 6px"><?= h($t['name']) ?></h1>
      <div class="small">
        Category: <a href="<?= h(rel_url($catUrl)) ?>"><b><?= h($t['category']) ?></b></a> • Pricing: <b><?= h($t['pricing'] ?? 'Varies') ?></b> • Rating: <?= $cnt?number_format($avg,1):'—' ?> (<?= (int)$cnt ?>) • Clicks: <?= (int)$clicks ?>
      </div>
      <p style="margin-top:12px"><?= h(($t['description'] ?? '') !== '' ? $t['description'] : ($t['short'] ?? '')) ?></p>
      <?php if(tool_domain($t['website'] ?? '') !== ''): ?>
        <p class="small">Official website: <?= h(tool_domain($t['website'])) ?></p>
      <?php endif; ?>
      <div class="actions">
        <a class="btn" target="_blank" rel="<?= h(outbound_rel($t, is_featured_tool($t))) ?>" href="/redirect.php?id=<?= urlencode($t['id']) ?>" data-outbound data-affiliate="<?= is_affiliate_link($t) ? '1' : '0' ?>" data-sponsored="<?= is_featured_tool($t) ? '1' : '0' ?>" data-placement="profile">Open official website<?= ui_icon('external') ?></a>
        <button type="button" class="btn secondary js-only" data-fav="<?= h($t['id']) ?>" aria-pressed="false" aria-label="Save <?= h($t['name']) ?> to favorites"><?= ui_icon('heart') ?> Save</button>
        <button type="button" class="btn secondary js-only" data-compare="<?= h($t['id']) ?>" data-name="<?= h($t['name']) ?>" aria-pressed="false"><?= ui_icon('compare') ?> Add to compare</button>
        <a class="btn secondary" href="/compare.php?a=<?= urlencode($t['id']) ?>" rel="nofollow">Compare</a>
      </div>
      <?php if(is_affiliate_link($t)): ?><p class="small">Affiliate link: AIHubpedia may earn a commission if you sign up. <a href="/disclosure.php">Details</a></p><?php endif; ?>
      <?php if(is_featured_tool($t)): ?><p class="adLabel"><span class="adTag">Sponsored</span> This listing is a paid placement. <a href="/disclosure.php">Why am I seeing this?</a></p><?php endif; ?>
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

  <?php if($cp): ?>
  <section class="profileBox" aria-labelledby="glance">
    <h2 id="glance" style="margin-top:0"><?= h($t['name']) ?> at a glance</h2>
    <dl class="factList">
      <?php if(!empty($cp['company'])): ?><dt>Company</dt><dd><?= h($cp['company']) ?></dd><?php endif; ?>
      <dt>Category</dt><dd><a href="<?= h(rel_url($catUrl)) ?>"><?= h($t['category']) ?></a><?php if(!empty($t['subcategory'])): ?> › <a href="<?= h(category_path((string)$t['category'], 1, (string)$t['subcategory'])) ?>"><?= h($t['subcategory']) ?></a><?php endif; ?></dd>
      <?php if(!empty($cp['best_for'])): ?><dt>Best for</dt><dd><?= h(implode('; ', $cp['best_for'])) ?></dd><?php endif; ?>
      <dt>Pricing</dt><dd><?= h($cp['pricing']['summary'] ?? '') ?> <span class="small">(as of <?= h($cp['pricing']['as_of'] ?? '') ?>; <?= h($cp['pricing']['confidence'] ?? '') ?>)</span></dd>
    </dl>
    <?php if(!empty($cp['known_source_conflicts'])): ?>
      <div class="noticeBox"><strong>Sources disagree:</strong>
        <ul style="margin:6px 0 0;padding-left:18px"><?php foreach($cp['known_source_conflicts'] as $c): ?><li><?= h($c) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>
    <p class="small"><?= h(profile_review_label($cp)) ?>. <a href="/methodology.php">How AIHubpedia reviews tools</a>.</p>
    <details>
      <summary>Sources (<?= count($cp['sources'] ?? []) ?>)</summary>
      <ul class="small">
        <?php foreach(($cp['sources'] ?? []) as $src): ?>
          <li><a href="<?= h($src['url']) ?>" rel="nofollow noopener" target="_blank"><?= h($src['label']) ?></a> · <?= h($src['type']) ?> · retrieved <?= h($src['retrieved']) ?></li>
        <?php endforeach; ?>
      </ul>
    </details>
  </section>
  <?php elseif(!empty($t['classification']) && ($t['classification']['method'] ?? '') === 'rule'): ?>
    <p class="small">Category assigned automatically from the tool's description<?= ($t['classification']['confidence'] ?? '') !== 'high' ? ' (lower confidence, queued for review)' : '' ?>. <a href="/methodology.php">Learn how listings are classified</a>.</p>
  <?php endif; ?>

  <div class="kv">
    <div class="small"><b>Tags</b></div>
    <div>
      <?php foreach(($t['tags'] ?? []) as $tag): ?>
        <span class="badge gray"><?= h($tag) ?></span>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if($genericProfile): ?>
    <div class="noticeBox">Detailed, product-specific features for <?= h($t['name']) ?> have not been verified by AIHubpedia yet. The notes below are general guidance for <?= h(strtolower($t['category'])) ?> tools — check the official website for current features and pricing.</div>
  <?php endif; ?>

  <div class="kv">
    <div class="small"><b><?= $genericProfile ? 'General notes' : ($cp ? 'Key features' : 'Features') ?></b></div>
    <div>
      <ul>
        <?php foreach(($t['features'] ?? []) as $f): ?><li><?= h($f) ?></li><?php endforeach; ?>
      </ul>
    </div>
  </div>

  <div class="kv">
    <div class="small"><b><?= $genericProfile ? 'Typical benefits' : ($cp ? 'Strengths' : 'Pros') ?></b></div>
    <div>
      <ul>
        <?php foreach(($t['pros'] ?? []) as $p): ?><li><?= h($p) ?></li><?php endforeach; ?>
      </ul>
    </div>
  </div>

  <div class="kv">
    <div class="small"><b><?= $genericProfile ? 'Things to check' : ($cp ? 'Limitations' : 'Cons') ?></b></div>
    <div>
      <ul>
        <?php foreach(($t['cons'] ?? []) as $c): ?><li><?= h($c) ?></li><?php endforeach; ?>
      </ul>
    </div>
  </div>

  <div class="ad">Ad placeholder (Inside tool page)</div>

  <h2>Rate this tool</h2>
  <form method="post" action="/rate.php" class="card" style="background:rgba(255,255,255,.03)">
    <input type="hidden" name="id" value="<?= h($t['id']) ?>">
    <label class="small" for="rateStars">Stars</label>
    <select name="stars" id="rateStars">
      <option value="5">★★★★★ (5)</option>
      <option value="4">★★★★☆ (4)</option>
      <option value="3">★★★☆☆ (3)</option>
      <option value="2">★★☆☆☆ (2)</option>
      <option value="1">★☆☆☆☆ (1)</option>
    </select>
    <div class="actions" style="margin-top:10px">
      <button class="btn" type="submit">Submit rating</button>
      <a class="btn secondary" href="/tool.php?id=<?= urlencode($t['id']) ?>">Refresh</a>
    </div>
  </form>

  <?php if(!empty($t['use_cases']) && !tool_field_is_generic($t, 'use_cases')): ?>
    <h2>Use cases for <?= h($t['name']) ?></h2>
    <ul>
      <?php foreach($t['use_cases'] as $u): ?><li><?= h($u) ?></li><?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if(count($alts)): ?>
    <h2><?= h($t['name']) ?> alternatives in <?= h($t['category']) ?></h2>
    <div class="grid">
      <?php foreach($alts as $a): ?>
        <div class="card">
          <div style="display:flex;gap:10px;align-items:center">
            <img class="logo" src="<?= h(tool_logo($a)) ?>" alt="<?= h($a['name']) ?> logo" width="48" height="48" loading="lazy" decoding="async" />
            <div>
              <h3 style="margin:0 0 4px"><a href="/tool.php?id=<?= urlencode($a['id']) ?>"><?= h($a['name']) ?></a></h3>
              <div class="small"><?= h($a['pricing'] ?? 'Varies') ?></div>
            </div>
          </div>
          <p style="margin-top:10px"><?= h($a['short'] ?? '') ?></p>
          <div class="actions">
            <a class="btn secondary" href="/tool.php?id=<?= urlencode($a['id']) ?>">Details</a>
            <a class="btn" href="<?= h(compare_path($t['id'], $a['id'])) ?>"><?= h($t['name']) ?> vs <?= h($a['name']) ?></a>
          </div>
          <?= render_vote_buttons($a['id'], 'tool.php?id=' . urlencode($t['id'])) ?>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="small" style="margin-top:12px">
      <?php if($hasAltPage): ?><a href="/alternatives.php?tool=<?= urlencode($t['id']) ?>">Full comparison of <?= h($t['name']) ?> alternatives →</a> · <?php endif; ?>
      <a href="<?= h(rel_url($catUrl)) ?>">See all <?= h($t['category']) ?> AI tools →</a>
    </p>
  <?php endif; ?>
</div>


<div class="card" style="margin-top:16px">
  <h2 style="margin-top:0">User actions</h2>
  <div class="actions">
    <a class="btn secondary" rel="nofollow" href="/toggle-bookmark.php?id=<?= urlencode($t['id'] ?? ($_GET['id'] ?? '')) ?>">Save / Remove Bookmark</a>
    <a class="btn secondary" rel="nofollow" href="/compare-tools.php?a=<?= urlencode($t['id'] ?? ($_GET['id'] ?? '')) ?>">Compare this tool</a>
    <a class="btn secondary" rel="nofollow" href="/login.php">Demo Login</a>
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

  <form method="post" action="/submit-comment.php" style="margin-top:14px">
    <input type="hidden" name="tool_id" value="<?= htmlspecialchars($t['id'] ?? ($_GET['id'] ?? '')) ?>">
    <label for="commentName">Your name</label>
    <input class="input" id="commentName" name="name" placeholder="Your name" autocomplete="name">
    <label for="commentText" style="margin-top:12px;display:block">Comment</label>
    <textarea class="input" id="commentText" name="comment" rows="5" placeholder="Share your experience with this tool"></textarea>
    <p style="margin-top:12px"><button class="btn">Post comment</button></p>
  </form>
</div>

<section class="panel" style="margin-top:22px" aria-labelledby="bizHelp">
  <h2 id="bizHelp" style="margin-top:0">Own this tool, or need something custom?</h2>
  <p class="small">If you are the vendor, you can <a href="/advertise.php">ask about labelled placements</a> or send a correction through <a href="/contact.php">contact</a>. If no existing tool fits your workflow, <a href="/business.php">request a consultation</a> about a custom AI agent.</p>
</section>
<?php require __DIR__ . '/layout_bottom.php'; ?>
