<?php
require_once __DIR__ . '/helpers.php';
$title = "AIHubpedia — AI Tools Directory";
$meta_desc = "Browse AI tools by category, compare tools, and discover the best AI apps for your workflow.";

$data = load_tools();
$tools = $data['tools'] ?? [];
$cats  = $data['categories'] ?? [];

$q   = trim($_GET['q'] ?? '');
$cat = trim($_GET['cat'] ?? '');

// Search + filter
$filtered = array_filter($tools, function($t) use ($q, $cat){
  if($cat !== '' && ($t['category'] ?? '') !== $cat) return false;
  if($q === '') return true;

  $hay = strtolower(
    ($t['name'] ?? '') . ' ' .
    ($t['short'] ?? '') . ' ' .
    ($t['description'] ?? '') . ' ' .
    implode(' ', ($t['tags'] ?? []))
  );

  return strpos($hay, strtolower($q)) !== false;
});
$filtered = array_values($filtered);

// Trending shortlist (manual + algorithm)
$manual_trending_ids = [
  'chatgpt','gemini','copilot','claude','perplexity',
  'canva-ai','deepseek','character-ai','grammarly','notebooklm'
];

$manual_map = [];
foreach($tools as $t){
  $id = $t['id'] ?? '';
  if($id !== '' && in_array($id, $manual_trending_ids, true)){
    $manual_map[$id] = $t;
  }
}

$manual_trend = [];
foreach($manual_trending_ids as $id){
  if(isset($manual_map[$id])) $manual_trend[] = $manual_map[$id];
}

// Algorithmic fallback (excluding manual)
$trend = array_values(array_filter($tools, function($t) use ($manual_trending_ids){
  return !in_array(($t['id'] ?? ''), $manual_trending_ids, true);
}));

usort($trend, function($a, $b){
  $sa = trending_score($a['id'] ?? '');
  $sb = trending_score($b['id'] ?? '');
  if($sa == $sb) return 0;
  return ($sa > $sb) ? -1 : 1;
});

$trend = array_merge($manual_trend, $trend);
$trend = array_slice($trend, 0, 10);

require __DIR__ . '/layout_top.php';
?>

<section class="hero">
  <h1>All AI tools, in one place</h1>
  <p class="small">
    Explore a growing library of <?= number_format(count($tools)) ?>+ AI tools across writing, coding, design,
    marketing, productivity, automation and more. Compare alternatives, explore use cases,
    and make AIHubpedia your daily AI discovery hub.
  </p>

  <div class="stats">

    <div class="stat">
      <div class="icon">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M12 8V4m0 0h3m-3 0H9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          <rect x="4" y="8" width="16" height="12" rx="3" fill="none" stroke="currentColor" stroke-width="2"/>
          <path d="M8.5 13h.01M15.5 13h.01" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
          <path d="M9 17h6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
      </div>
      <div class="number"><?= number_format(count($tools)) ?>+</div>
      <div class="label">AI Tools</div>
    </div>

    <div class="stat">
      <div class="icon">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <rect x="4" y="4" width="7" height="7" rx="2" fill="none" stroke="currentColor" stroke-width="2"/>
          <rect x="13" y="4" width="7" height="7" rx="2" fill="none" stroke="currentColor" stroke-width="2"/>
          <rect x="4" y="13" width="7" height="7" rx="2" fill="none" stroke="currentColor" stroke-width="2"/>
          <rect x="13" y="13" width="7" height="7" rx="2" fill="none" stroke="currentColor" stroke-width="2"/>
        </svg>
      </div>
      <div class="number"><?= number_format(count($cats)) ?>+</div>
      <div class="label">Categories</div>
    </div>

    <div class="stat">
      <div class="icon">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <circle cx="11" cy="11" r="6" fill="none" stroke="currentColor" stroke-width="2"/>
          <path d="M20 20l-4.2-4.2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
      </div>
      <div class="number"><?= number_format(count($filtered)) ?></div>
      <div class="label">Results</div>
    </div>

  </div>

  <div class="toolbar">
    <form method="get" class="heroSearch">
      <input class="input" name="q" value="<?= h($q) ?>" placeholder="Search: chat, image, coding, SEO..." />
      <select name="cat">
        <option value="">All categories</option>
        <?php foreach($cats as $c): ?>
          <option value="<?= h($c) ?>" <?= ($c === $cat) ? 'selected' : '' ?>><?= h($c) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn" type="submit">Search</button>
      <a class="btn secondary" href="index.php">Reset</a>
    </form>
  </div>
</section>

<section class="card">
  <div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap">
    <div>
      <h2 style="margin-top:0">⭐ Homepage Spotlight</h2>
      <p class="small" style="margin-bottom:0">
        Premium top placement for AI tools that want maximum homepage visibility.
      </p>
    </div>
    <div class="actions" style="margin-top:0">
      <a class="btn" href="submit.php">🚀 Get homepage spotlight</a>
      <a class="btn secondary" href="advertise.php">Pricing</a>
    </div>
  </div>

  <?php
    $spotlight = array_values(array_filter($tools, function($t){
      if(empty($t['spotlight_until'])) return false;
      return strtotime((string)$t['spotlight_until']) >= strtotime(date('Y-m-d'));
    }));
    usort($spotlight, function($a, $b){
      return strcmp((string)($b['spotlight_until'] ?? ''), (string)($a['spotlight_until'] ?? ''));
    });
    $spotlight = array_slice($spotlight, 0, 1);
  ?>

  <?php if(count($spotlight) === 0): ?>
    <div class="card" style="margin-top:16px;background:linear-gradient(135deg, rgba(124,58,237,.26), rgba(236,72,153,.16));border:1px dashed rgba(255,255,255,.24)">
      <div style="display:flex;justify-content:space-between;gap:18px;align-items:flex-start;flex-wrap:wrap">
        <div style="max-width:760px">
          <div class="badge" style="margin-bottom:10px">Top homepage placement</div>
          <h3 style="margin:0 0 10px">⭐ Your AI tool can be the first thing visitors see</h3>
          <p class="small" style="margin:0 0 12px">
            Homepage Spotlight is the most visible promotional placement on AIHubpedia.
            Perfect for product launches, new campaigns, and maximum click-through visibility.
          </p>
          <ul class="small" style="margin:0;padding-left:18px;line-height:1.8">
            <li>Premium top homepage position</li>
            <li>Higher visibility than standard featured listings</li>
            <li>Ideal for launches, deals, and announcements</li>
            <li>Strong traffic and discovery potential</li>
          </ul>
        </div>
        <div class="actions" style="margin-top:0">
          <a class="btn" href="advertise.php">View spotlight pricing</a>
          <a class="btn secondary" href="submit.php">Submit tool</a>
        </div>
      </div>
    </div>
  <?php else: ?>
    <?php $t = $spotlight[0]; ?>
    <div class="card" style="margin-top:16px;background:linear-gradient(135deg, rgba(124,58,237,.26), rgba(236,72,153,.16));border:1px solid rgba(255,255,255,.14)">
      <div style="display:flex;justify-content:space-between;gap:18px;align-items:flex-start;flex-wrap:wrap">
        <div style="display:flex;gap:14px;align-items:center;max-width:760px">
          <img class="logo" src="<?= h(tool_logo($t)) ?>" alt="" />
          <div>
            <div class="badge" style="margin-bottom:8px">Homepage Spotlight</div>
            <h3 style="margin:0 0 6px"><?= h($t['name']) ?></h3>
            <div class="small">
              Category: <b><?= h($t['category']) ?></b> • Spotlight until: <?= h($t['spotlight_until']) ?>
            </div>
            <p style="margin:10px 0 0"><?= h($t['short']) ?></p>
          </div>
        </div>

        <div class="actions" style="margin-top:0">
          <a class="btn secondary" href="tool.php?id=<?= urlencode($t['id']) ?>">Details</a>
          <?php if(tool_url($t) !== '#'): ?>
            <a class="btn" target="_blank" rel="noopener" href="redirect.php?id=<?= urlencode($t['id']) ?>">Open tool</a>
          <?php endif; ?>
        </div>
      </div>

      <div style="margin-top:14px">
        <?= render_vote_buttons($t['id'], 'index.php') ?>
      </div>
    </div>
  <?php endif; ?>
</section>

<section class="card">
  <div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap">
    <div>
      <h2 style="margin-top:0">Featured listings</h2>
      <p class="small" style="margin-bottom:0">
        Sponsored placements for AI tools. Want your tool featured on AIHubpedia?
      </p>
    </div>
    <div class="actions" style="margin-top:0">
      <a class="btn" href="submit.php">🚀 Promote your AI tool</a>
      <a class="btn secondary" href="advertise.php">Pricing</a>
    </div>
  </div>

  <div class="grid">
    <?php $featured = featured_tools($tools, 6); ?>
    <?php if(count($featured) === 0): ?>
      <div class="card" style="grid-column:1/-1;background:linear-gradient(135deg, rgba(124,58,237,.18), rgba(34,197,94,.10));border:1px dashed rgba(255,255,255,.22)">
        <div style="display:flex;justify-content:space-between;gap:18px;align-items:flex-start;flex-wrap:wrap">
          <div style="max-width:720px">
            <div class="badge" style="margin-bottom:10px">Sponsored placement</div>
            <h3 style="margin:0 0 10px">🚀 Promote your AI tool here</h3>
            <p class="small" style="margin:0 0 12px">
              Get featured on the AIHubpedia homepage and reach users actively searching for the best AI tools.
            </p>
            <ul class="small" style="margin:0;padding-left:18px;line-height:1.8">
              <li>Homepage visibility</li>
              <li>More clicks and direct traffic</li>
              <li>Premium placement for product discovery</li>
              <li>Great for launches, promotions, and SEO exposure</li>
            </ul>
          </div>
          <div class="actions" style="margin-top:0">
            <a class="btn" href="advertise.php">View pricing</a>
            <a class="btn secondary" href="submit.php">Submit tool</a>
          </div>
        </div>
      </div>
    <?php else: ?>
      <?php foreach($featured as $t): ?>
        <div class="card" style="background:rgba(34,197,94,.08)">
          <div style="display:flex;gap:10px;align-items:center">
            <img class="logo" src="<?= h(tool_logo($t)) ?>" alt="" />
            <div>
              <div class="badge" style="margin-bottom:6px">Sponsored</div>
              <h3 style="margin:0 0 4px"><?= h($t['name']) ?></h3>
              <div class="small">
                Category: <b><?= h($t['category']) ?></b> • Featured until: <?= h($t['featured_until']) ?>
              </div>
            </div>
          </div>

          <p style="margin-top:10px"><?= h($t['short']) ?></p>

          <div class="actions">
            <a class="btn secondary" href="tool.php?id=<?= urlencode($t['id']) ?>">Details</a>
            <?php if(tool_url($t) !== '#'): ?>
              <a class="btn" target="_blank" rel="noopener" href="redirect.php?id=<?= urlencode($t['id']) ?>">Open tool</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>

<section class="card">
  <h2 style="margin-top:0">🔥 Trending AI tools today</h2>
  <p class="small">Based on clicks, ratings, and user engagement.</p>

  <div class="grid">
    <?php foreach($trend as $t): ?>
      <?php [$avg, $cnt] = rating_avg($t['id']); ?>
      <div class="card" style="background:rgba(255,255,255,.03)">
        <div style="display:flex;gap:10px;align-items:center">
          <img class="logo" src="<?= h(tool_logo($t)) ?>" alt="" />
          <div>
            <h3 style="margin:0 0 4px"><?= h($t['name']) ?></h3>
            <div class="small">Category: <b><?= h($t['category']) ?></b></div>
          </div>
        </div>

        <p style="margin-top:10px"><?= h($t['short']) ?></p>

        <div class="actions">
          <a class="btn secondary" href="tool.php?id=<?= urlencode($t['id']) ?>">Details</a>
          <?php if(tool_url($t) !== '#'): ?>
            <a class="btn" target="_blank" rel="noopener" href="redirect.php?id=<?= urlencode($t['id']) ?>">Open tool</a>
          <?php endif; ?>
        </div>

        <?= render_vote_buttons($t['id'], 'index.php') ?>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="card">
  <h2 style="margin-top:0">Browse tools</h2>
  <p class="small">Showing <?= number_format(count($filtered)) ?> results.</p>

  <div class="grid">
    <?php foreach(array_slice($filtered, 0, 48) as $t): ?>
      <div class="card">
        <div style="display:flex;gap:10px;align-items:center">
          <img class="logo" src="<?= h(tool_logo($t)) ?>" alt="" />
          <div>
            <h3 style="margin:0 0 4px"><?= h($t['name']) ?></h3>
            <div class="small"><?= h($t['pricing'] ?? 'Varies') ?></div>
          </div>
        </div>

        <p style="margin-top:10px"><?= h($t['short']) ?></p>

        <div class="badges">
          <span class="badge"><?= h($t['category']) ?></span>
          <span class="badge gray"><?= h($t['pricing_type'] ?? 'Varies') ?></span>
        </div>

        <div class="actions">
          <a class="btn secondary" href="tool.php?id=<?= urlencode($t['id']) ?>">Details</a>
          <?php if(tool_url($t) !== '#'): ?>
            <a class="btn" target="_blank" rel="noopener" href="redirect.php?id=<?= urlencode($t['id']) ?>">Open tool</a>
          <?php endif; ?>
        </div>

        <?= render_vote_buttons($t['id'], 'index.php') ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="actions">
    <a class="btn secondary" href="categories.php">Browse categories</a>
    <a class="btn secondary" href="top.php">See top tools</a>
  </div>
</section>

<?php require __DIR__ . '/layout_bottom.php'; ?>