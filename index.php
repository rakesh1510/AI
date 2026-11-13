<?php
require_once __DIR__ . '/helpers.php';
 
/*
  SEO/Growth Homepage for AIHubpedia
  - Strong AIHubpedia brand positioning
  - AI tools directory SEO targeting
  - Dynamic SEO title/meta for homepage, category pages, and search pages
  - Canonical URL handling to reduce duplicate URLs
  - Optional noindex for internal search result pages
  - JSON-LD structured data: WebSite, Organization, ItemList, FAQPage, BreadcrumbList
  - Better internal linking for SEO crawl depth
  - More keyword-rich visible content
*/

$data = load_tools();
$tools = $data['tools'] ?? [];
$cats  = $data['categories'] ?? [];

$q   = seo_param($_GET, 'q');
$cat = seo_param($_GET, 'cat');

$siteName = 'AIHubpedia';
$baseUrl  = 'https://aihubpedia.com';

$canonical = seo_canonical($_SERVER['REQUEST_URI'] ?? '/');
if ($cat !== '' && !in_array($cat, $cats, true)) {
  http_response_code(404);
  $title = 'Category not found — AIHubpedia';
  require __DIR__ . '/layout_top.php';
  echo '<h1>Category not found</h1><p><a href="/categories.php">Browse categories</a></p>';
  require __DIR__ . '/layout_bottom.php';
  exit;
}

/* =========================
   DYNAMIC SEO META
========================= */
if ($cat !== '') {
  $title = "Best " . $cat . " AI Tools — Compare Top AI Apps | AIHubpedia";
  $meta_desc = "Discover the best " . $cat . " AI tools. Compare pricing, features, alternatives, free plans, and popular AI apps curated by AIHubpedia.";
} elseif ($q !== '') {
  $title = "Search AI Tools for " . $q . " — AIHubpedia";
  $meta_desc = "Search AIHubpedia for AI tools related to " . $q . ". Find tools, compare alternatives, and discover useful AI apps for your workflow.";
} else {
  $title = "AI Tools Directory – Discover & Compare 500+ AI Tools | AIHubpedia";
  $meta_desc = "Explore 500+ AI tools, compare features, pricing, alternatives, and use cases. AIHubpedia is a trusted AI tools directory for founders, developers, marketers, and businesses.";
}

$robots_meta = ($q !== '') ? 'noindex,follow' : 'index,follow';

/* =========================
   SEARCH + FILTER
========================= */
$filtered = array_filter($tools, function($t) use ($q, $cat){
  if($cat !== '' && ($t['category'] ?? '') !== $cat) return false;
  if($q === '') return true;

  $hay = strtolower(
    ($t['name'] ?? '') . ' ' .
    ($t['short'] ?? '') . ' ' .
    ($t['description'] ?? '') . ' ' .
    ($t['pricing'] ?? '') . ' ' .
    ($t['pricing_type'] ?? '') . ' ' .
    implode(' ', ($t['tags'] ?? []))
  );

  return strpos($hay, strtolower($q)) !== false;
});
$filtered = array_values($filtered);

/* =========================
   SORT HELPERS
========================= */
$mostClicked = $tools;
usort($mostClicked, function($a, $b){
  return click_count($b['id'] ?? '') <=> click_count($a['id'] ?? '');
});

$trend = array_slice($mostClicked, 0, 10);
$recommended = array_slice($mostClicked, 10, 6);

$freeTools = array_values(array_filter($tools, function($t){
  $pricing = strtolower(($t['pricing_type'] ?? '') . ' ' . ($t['pricing'] ?? ''));
  return strpos($pricing, 'free') !== false || strpos($pricing, 'freemium') !== false || strpos($pricing, 'trial') !== false;
}));

usort($freeTools, function($a, $b){
  return click_count($b['id'] ?? '') <=> click_count($a['id'] ?? '');
});

$freeTools = array_slice($freeTools, 0, 6);

/* =========================
   CATEGORY COUNTS
========================= */
$catCounts = [];
foreach($cats as $c){
  $catCounts[$c] = 0;
}

foreach($tools as $t){
  $category = $t['category'] ?? 'Other';
  if(!isset($catCounts[$category])) $catCounts[$category] = 0;
  $catCounts[$category]++;
}

arsort($catCounts);

/* =========================
   SEO HELPERS
========================= */
function seo_json($data): string {
  return seo_json_ld($data);
}

function seo_tool_url(array $t): string {
  $id = $t['id'] ?? '';
  return 'https://aihubpedia.com/tool.php?id=' . rawurlencode($id);
}

function seo_category_url(string $c): string {
  return seo_url('/category.php', ['name' => $c]);
}

/* =========================
   REUSABLE CARD
========================= */
function render_growth_tool_card(array $t, string $returnPage = 'index.php', ?int $rank = null){
  $id = $t['id'] ?? '';
  $name = $t['name'] ?? 'AI Tool';
  $clicks = click_count($id);
  $votes = tool_vote_total($id);
  $comments = count(tool_comments($id));

  $pricingText = (string)($t['pricing'] ?? 'Not recorded');
  $pricingType = (string)($t['pricing_type'] ?? 'Varies');
  $pricingLower = strtolower($pricingText . ' ' . $pricingType);

  $primaryLabel = '🚀 Try Now';
  if(strpos($pricingLower, 'free') !== false || strpos($pricingLower, 'freemium') !== false){
    $primaryLabel = '🚀 Try Free Now';
  }

  $bestFor = $t['category'] ?? 'AI workflows';
  $short = trim((string)($t['short'] ?? ''));

  if($short === '') {
    $short = $name . ' helps users improve productivity with AI-powered workflows.';
  }
  ?>
  <article class="card hoverCard growthToolCard" >
    <div style="display:flex;gap:12px;align-items:center">
      <img class="logo"
           src="<?= h(tool_logo($t)) ?>"
           alt="<?= h($name) ?> logo"
           loading="lazy"
           width="56"
           height="56"
           />

      <div style="min-width:0">
        <h3 style="margin:0 0 4px">
          <?php if($rank !== null): ?>
            🏆 #<?= (int)$rank ?>
          <?php endif; ?>
          <a href="tool.php?id=<?= urlencode($id) ?>" style="color:inherit;text-decoration:none">
            <?= h($name) ?>
          </a>
        </h3>

        <div class="small">
          <span>
            <span><?= h($pricingText) ?></span>
          </span>
          • 🔥 <?= number_format($clicks) ?> clicks
        </div>

        <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
          <?php if($clicks >= 100): ?>
            <span class="badge hotBadge">🔥 Trending</span>
          <?php endif; ?>
          <span class="badge">⚡ <?= h($t['category'] ?? 'AI Tool') ?></span>
          <span class="badge gray"><?= h($pricingType) ?></span>
        </div>
      </div>
    </div>

    <p style="margin-top:12px" itemprop="description"><?= h($short) ?></p>

    <div class="small benefitLine">
      ✅ Best for: <?= h($bestFor) ?>, AI productivity, workflow automation, and tool discovery
    </div>

    <div style="margin-top:14px">
      <?php if (tool_signup_url($t) !== '#'): ?>
        <a class="btn fullWidth ctaPulse" target="_blank" rel="noopener sponsored"
           href="signup-redirect.php?id=<?= urlencode($id) ?>"
           aria-label="Try <?= h($name) ?>">
          <?= h($primaryLabel) ?>
        </a>
      <?php elseif (tool_open_url($t) !== '#'): ?>
        <a class="btn fullWidth ctaPulse" target="_blank" rel="noopener sponsored"
           href="redirect.php?id=<?= urlencode($id) ?>"
           aria-label="Open <?= h($name) ?>">
          🚀 Open Tool
        </a>
      <?php endif; ?>
    </div>

    <?php if($clicks > 0): ?>
      <div class="small fomoLine">
        🔥 <?= number_format($clicks) ?> users already explored this tool
      </div>
    <?php endif; ?>

    <div class="actions" style="margin-top:10px">
      <a class="btn secondary" href="tool.php?id=<?= urlencode($id) ?>">📝 Review</a>
      <a class="btn secondary" href="compare.php?tool=<?= urlencode($id) ?>">Compare</a>
    </div>

    <div class="small socialLine">
      <span>👍 <?= $votes > 0 ? (int)$votes . ' votes' : 'Be first to vote' ?></span>
      <span>💬 <?= $comments > 0 ? (int)$comments . ' comments' : 'Share feedback' ?></span>
    </div>

    <?= render_vote_buttons($id, $returnPage) ?>
  </article>
  <?php
}

$page_schemas = [['@context' => 'https://schema.org', '@type' => 'WebSite', '@id' => SEO_ORIGIN . '/#website', 'name' => 'AIHubpedia', 'url' => SEO_ORIGIN . '/', 'publisher' => ['@id' => SEO_ORIGIN . '/#organization']]];
require __DIR__ . '/layout_top.php';
?>

<div style="display:none;">Impact-Site-Verification: 968dd254-5013-4efd-ad39-8cbc46defd12</div>


<?php
$itemList = [];
foreach (array_slice($trend, 0, 10) as $t) {
  $itemList[] = ['@type' => 'ListItem', 'position' => count($itemList) + 1,
    'url' => seo_tool_url($t), 'name' => $t['name']];
}
?>
<script type="application/ld+json"><?= seo_json_ld([
  '@context' => 'https://schema.org', '@type' => 'ItemList',
  'name' => 'Most Clicked AI Tools on AIHubpedia', 'itemListElement' => $itemList
]) ?></script>

<style>
.hoverCard{transition:all .25s ease;border:1px solid rgba(99,102,241,.15)}
.hoverCard:hover{transform:translateY(-6px);box-shadow:0 22px 48px rgba(30,20,80,.20);border-color:rgba(99,102,241,.42)}
.fullWidth{width:100%;text-align:center;justify-content:center}
.ctaPulse{transition:all .2s ease;box-shadow:0 14px 28px rgba(99,102,241,.22)}
.ctaPulse:hover{transform:scale(1.025)}
.hotBadge{background:linear-gradient(135deg,#ef4444,#f97316);color:#fff}
.heroCtas{display:flex;gap:12px;flex-wrap:wrap;justify-content:center;margin-top:18px}
.growthToolCard p{min-height:48px}
.categoryPills,.seoLinks{display:flex;gap:12px;flex-wrap:wrap}
.categoryPills a,.seoLinks a{text-decoration:none}
.benefitLine{margin-top:8px;opacity:.9}
.fomoLine{margin-top:8px;opacity:.72;text-align:center}
.socialLine{margin-top:12px;display:flex;justify-content:space-between;gap:10px;opacity:.85}
.seoIntro{max-width:940px;margin:18px auto 0;line-height:1.75}
.faqGrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}
.seoList{line-height:1.9}
</style>

<section class="hero">
  <h1>
    <?php if($cat !== ''): ?>
      🚀 Best <?= h($cat) ?> AI Tools to Discover, Compare & Launch
    <?php elseif($q !== ''): ?>
      🔎 Search Results for “<?= h($q) ?>” AI Tools
    <?php else: ?>
      🚀 AIHubpedia – Discover & Compare the Best AI Tools
    <?php endif; ?>
  </h1>

  <p class="small seoIntro">
    AIHubpedia is an AI tools directory to discover, compare, and explore <?= number_format(count($tools)) ?>+ AI tools
    for coding, writing, design, automation, SEO, marketing, productivity, startups, and business.
    Compare features, find alternatives, explore free AI apps, and choose the right software faster.
  </p>

  <div class="heroCtas">
    <a class="btn" href="#most-clicked">🔥 Explore Trending Tools</a>
    <a class="btn secondary" href="/ai-use-case-explorer.php">🧭 Find Tools by Use Case</a>
    <a class="btn secondary" href="/compare.php">📊 Compare Tools</a>
    <a class="btn secondary" href="/categories.php">📂 Browse Categories</a>
  </div>

  <div class="stats">
    <div class="stat">
      <div class="icon">🤖</div>
      <div class="number"><?= number_format(count($tools)) ?>+</div>
      <div class="label">AI Tools</div>
    </div>
    <div class="stat">
      <div class="icon">📂</div>
      <div class="number"><?= number_format(count($cats)) ?>+</div>
      <div class="label">Categories</div>
    </div>
    <div class="stat">
      <div class="icon">🔥</div>
      <div class="number"><?= number_format(count($trend)) ?></div>
      <div class="label">Trending Picks</div>
    </div>
  </div>

  <div class="toolbar">
    <form method="get" class="heroSearch" action="/">
      <input class="input" name="q" value="<?= h($q) ?>" placeholder="Search AI tools: chatbot, image, coding, SEO..." aria-label="Search AI tools" />

      <select name="cat" aria-label="Select AI tools category">
        <option value="">All categories</option>
        <?php foreach($cats as $c): ?>
          <option value="<?= h($c) ?>" <?= ($c === $cat) ? 'selected' : '' ?>>
            <?= h($c) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <button class="btn" type="submit">Search</button>
      <a class="btn secondary" href="/">Reset</a>
    </form>
  </div>

  <div class="trust" style="margin-top:18px;display:flex;gap:14px;flex-wrap:wrap;justify-content:center;opacity:.95">
    <span class="badge">✅ AIHubpedia by TrustNova Tech, Germany</span>
    <span class="badge">⭐ <?= number_format(count($tools)) ?>+ AI tools listed</span>
    <span class="badge">🔥 Most-clicked tools ranked</span>
    <span class="badge">👨‍💻 Curated for developers, founders & marketers</span>
    <span class="badge">📊 Compare tools and alternatives</span>
  </div>
</section>

<?php require __DIR__ . '/homepage-trust-section.php'; ?>

<!-- ═══════════════════════════════════════════
     NEW ON AIHUBPEDIA — B12 ANNOUNCEMENT BANNER
     ═══════════════════════════════════════════ -->
<section class="card" style="background:linear-gradient(135deg,rgba(99,102,241,.22) 0%,rgba(139,92,246,.18) 40%,rgba(59,130,246,.16) 100%);border:1px solid rgba(139,92,246,.35);position:relative;overflow:hidden">
  <!-- decorative blobs -->
  <div style="position:absolute;top:-40px;right:-40px;width:220px;height:220px;background:radial-gradient(circle,rgba(139,92,246,.18),transparent 70%);pointer-events:none"></div>
  <div style="position:absolute;bottom:-30px;left:-30px;width:160px;height:160px;background:radial-gradient(circle,rgba(59,130,246,.14),transparent 70%);pointer-events:none"></div>

  <div style="position:relative;display:flex;gap:20px;align-items:flex-start;flex-wrap:wrap">
    <!-- logo -->
    <div style="flex-shrink:0;width:64px;height:64px;border-radius:14px;background:linear-gradient(135deg,#6366f1,#8b5cf6);display:flex;align-items:center;justify-content:center;font-size:28px;box-shadow:0 8px 24px rgba(99,102,241,.35)">🌐</div>

    <div style="flex:1;min-width:220px">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px">
        <span class="badge" style="background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;font-size:.75rem;padding:4px 12px;border-radius:999px">🎉 New on AIHubpedia</span>
        <span class="badge" style="background:rgba(255,255,255,.10);font-size:.72rem">AI Website Builder</span>
      </div>

      <h2 style="margin:0 0 8px;font-size:1.45rem">Welcome B12 🚀</h2>
      <p style="margin:0 0 14px;line-height:1.7;max-width:680px">
        We're excited to feature <strong>B12</strong> on AIHubpedia.<br>
        B12 is an AI-powered website builder that helps businesses, freelancers, consultants, and agencies
        create professional websites faster with AI and expert support.
      </p>

      <div class="actions" style="margin-top:0">
        <a class="btn" target="_blank" rel="noopener" href="tool.php?id=b12"
           style="background:linear-gradient(135deg,#6366f1,#8b5cf6);box-shadow:0 8px 22px rgba(99,102,241,.32)">
          Explore B12 →
        </a>
          <a class="btn secondary"
     href="signup-redirect.php?id=b12"
     target="_blank"
     rel="nofollow sponsored noopener">
     🚀 Try B12 Free
  </a>
      </div>
    </div>
  </div>
</section>

<section id="most-clicked" class="card">
  <h2 style="margin-top:0">🔥 Most Clicked AI Tools</h2>
  <p class="small">Top 10 AI tools people are exploring right now on AIHubpedia.</p>

  <div class="grid">
    <?php $rank = 1; ?>
    <?php foreach($trend as $t): ?>
      <?php render_growth_tool_card($t, 'index.php', $rank); ?>
      <?php $rank++; ?>
    <?php endforeach; ?>
  </div>
</section>

<section class="card">
  <h2 style="margin-top:0">🧭 Explore AI Tools by Category</h2>
  <p class="small">Find AI tools by exact use case and category. These internal links help users and search engines discover more pages.</p>

  <div class="categoryPills">
    <?php foreach(array_slice($catCounts, 0, 18, true) as $c => $count): ?>
      <a class="badge" href="<?= h(seo_category_url($c)) ?>" title="Best <?= h($c) ?> AI tools">
        <?= h($c) ?> AI Tools · <?= (int)$count ?>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<section class="card">
  <h2 style="margin-top:0">🧰 Explore AI Tools Directory</h2>
  <p class="small">
    Browse AI tools across categories, pricing types, alternatives, and practical use cases.
    <?php if(count($filtered) > 0): ?>
      Showing <?= number_format(min(count($filtered), 48)) ?> of <?= number_format(count($filtered)) ?> matching tools.
    <?php endif; ?>
  </p>

  <?php if(count($filtered) === 0): ?>
    <div class="card" style="background:rgba(255,255,255,.05)">
      <h3 style="margin-top:0">No matching AI tools found</h3>
      <p class="small">Try another keyword, browse all categories, or explore trending tools.</p>
      <div class="actions">
        <a class="btn" href="/">View all AI tools</a>
        <a class="btn secondary" href="/categories.php">Browse categories</a>
      </div>
    </div>
  <?php else: ?>
    <div class="grid">
      <?php foreach(array_slice($filtered, 0, 48) as $t): ?>
        <?php render_growth_tool_card($t, 'index.php'); ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="actions">
    <a class="btn secondary" href="categories.php">Browse categories</a>
    <a class="btn secondary" href="top.php">See top tools</a>
    <a class="btn secondary" href="ai-use-case-explorer.php">Use-case explorer</a>
    <a class="btn secondary" href="compare.php">Compare AI tools</a>
  </div>
</section>

<section class="card">
  <h2 style="margin-top:0">✨ Popular AI Tool Alternatives</h2>
  <p class="small">Popular alternatives and discovery picks based on click activity.</p>

  <div class="grid">
    <?php foreach($recommended as $t): ?>
      <?php render_growth_tool_card($t, 'index.php'); ?>
    <?php endforeach; ?>
  </div>
</section>

<section class="card">
  <h2 style="margin-top:0">🆓 Best Free & Freemium AI Tools</h2>
  <p class="small">Explore AI tools with free plans, free trials, or freemium access for creators, developers, marketers, and founders.</p>

  <div class="grid">
    <?php foreach($freeTools as $t): ?>
      <?php render_growth_tool_card($t, 'index.php'); ?>
    <?php endforeach; ?>
  </div>
</section>

<!-- ═══════════════════════════
     RECENTLY ADDED TOOLS
     ═══════════════════════════ -->
<section class="card">
  <h2 style="margin-top:0">🆕 Recently Added Tools</h2>
  <p class="small">The latest AI tools added to the AIHubpedia directory.</p>

  <?php
  // Sort by 'added_at' date if available, else fall back to array order (last items = newest)
  $recentTools = $tools;
  usort($recentTools, function($a, $b){
    $da = $a['added_at'] ?? $a['created_at'] ?? '';
    $db = $b['added_at'] ?? $b['created_at'] ?? '';
    if($da === '' && $db === '') return 0;
    if($da === '') return 1;
    if($db === '') return -1;
    return strcmp((string)$db, (string)$da);
  });
  $recentTools = array_slice($recentTools, 0, 6);
  ?>

  <div class="grid">
    <?php foreach($recentTools as $t): ?>
      <?php render_growth_tool_card($t, 'index.php'); ?>
    <?php endforeach; ?>
  </div>

  <div class="actions" style="margin-top:16px">
    <a class="btn secondary" href="categories.php">Browse all tools</a>
  </div>
</section>

<!-- ═══════════════════════════
     FEATURED COMPARISONS
     ═══════════════════════════ -->
<section class="card">
  <h2 style="margin-top:0">📊 Featured Comparisons</h2>
  <p class="small">Side-by-side comparisons of the most popular AI tools. Find the best fit for your workflow.</p>

<div class="seoLinks">

    <!-- AI Website Builders -->
    <a class="badge" href="/compare.php?a=b12&b=durable">B12 vs Durable</a>
    <a class="badge" href="/compare.php?a=b12&b=10web">B12 vs 10Web</a>
    <a class="badge" href="/compare.php?a=durable&b=10web">Durable vs 10Web</a>

    <!-- AI Chat -->
    <a class="badge" href="/compare.php?a=chatgpt&b=claude">ChatGPT vs Claude</a>
    <a class="badge" href="/compare.php?a=chatgpt&b=gemini">ChatGPT vs Gemini</a>
    <a class="badge" href="/compare.php?a=claude&b=gemini">Claude vs Gemini</a>
    <a class="badge" href="/compare.php?a=chatgpt&b=deepseek">ChatGPT vs DeepSeek</a>
    <a class="badge" href="/compare.php?a=perplexity&b=chatgpt">Perplexity vs ChatGPT</a>

    <!-- AI Research -->
    <a class="badge" href="/compare.php?a=scite&b=consensus">Scite vs Consensus</a>
    <a class="badge" href="/compare.php?a=julius-ai&b=notebooklm">Julius AI vs NotebookLM</a>

    <!-- AI Coding -->
    <a class="badge" href="/compare.php?a=github-copilot&b=cursor">GitHub Copilot vs Cursor</a>
    <a class="badge" href="/compare.php?a=cursor&b=windsurf">Cursor vs Windsurf</a>
    <a class="badge" href="/compare.php?a=lovable&b=bolt-new">Lovable vs Bolt</a>

    <!-- AI Images -->
    <a class="badge" href="/compare.php?a=midjourney&b=dall-e-3">Midjourney vs DALL·E 3</a>
    <a class="badge" href="/compare.php?a=midjourney&b=leonardo-ai">Midjourney vs Leonardo AI</a>

    <!-- AI Audio -->
    <a class="badge" href="/compare.php?a=suno&b=udio">Suno vs Udio</a>

    <!-- AI Video -->
    <a class="badge" href="/compare.php?a=runway&b=heygen">Runway vs HeyGen</a>
    <a class="badge" href="/compare.php?a=heygen&b=synthesia">HeyGen vs Synthesia</a>

    <!-- Productivity -->
    <a class="badge" href="/compare.php?a=notion-ai&b=mem-ai">Notion AI vs Mem AI</a>
    <a class="badge" href="/compare.php?a=motion&b=reclaim-ai">Motion vs Reclaim AI</a>
    <a class="badge" href="/compare.php?a=otter-ai&b=fireflies-ai">Otter.ai vs Fireflies.ai</a>

</div>

  <div class="actions" style="margin-top:16px">
    <a class="btn secondary" href="compare.php">Compare more tools</a>
  </div>
</section>

<section class="card">
  <h2 style="margin-top:0">🔗 Popular AI Tool Searches</h2>
  <p class="small">Quick links to high-intent AI software discovery pages.</p>

  <div class="seoLinks">
    <a class="badge" href="/?q=chatbot">Best AI chatbot tools</a>
    <a class="badge" href="/?q=coding">Best AI coding tools</a>
    <a class="badge" href="/?q=writing">Best AI writing tools</a>
    <a class="badge" href="/?q=seo">Best AI SEO tools</a>
    <a class="badge" href="/?q=image">Best AI image tools</a>
    <a class="badge" href="/?q=automation">Best AI automation tools</a>
    <a class="badge" href="/?q=marketing">Best AI marketing tools</a>
    <a class="badge" href="/?q=productivity">Best AI productivity tools</a>
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
            Perfect for launches, campaigns, and maximum click-through visibility.
          </p>
        </div>
        <div class="actions" style="margin-top:0">
          <a class="btn" href="advertise.php">View spotlight pricing</a>
          <a class="btn secondary" href="submit.php">Submit tool</a>
        </div>
      </div>
    </div>
  <?php else: ?>
    <?php render_growth_tool_card($spotlight[0], 'index.php'); ?>
  <?php endif; ?>
</section>

<section class="card">
  <div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap">
    <div>
      <h2 style="margin-top:0">Featured AI Tool Listings</h2>
      <p class="small" style="margin-bottom:0">
        Sponsored placements for AI tools that want more visibility, qualified users, and product discovery traffic.
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
              Get featured on AIHubpedia and reach users actively searching for AI tools, software alternatives, and useful productivity apps.
            </p>
          </div>
          <div class="actions" style="margin-top:0">
            <a class="btn" href="advertise.php">View pricing</a>
            <a class="btn secondary" href="submit.php">Submit tool</a>
          </div>
        </div>
      </div>
    <?php else: ?>
      <?php foreach($featured as $t): ?>
        <?php render_growth_tool_card($t, 'index.php'); ?>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>


<section class="card">
  <h2 style="margin-top:0">❓ AI Tools FAQ</h2>
  <div class="faqGrid">
    <div class="card">
      <h3 style="margin-top:0">What is AIHubpedia?</h3>
      <p class="small">AIHubpedia is an AI tools directory for discovering, comparing, and exploring AI tools, alternatives, pricing, and practical use cases.</p>
    </div>
    <div class="card">
      <h3 style="margin-top:0">What is the best AI tool?</h3>
      <p class="small">The best AI tool depends on your use case. AIHubpedia helps you compare tools for writing, coding, design, marketing, SEO, automation, and business.</p>
    </div>
    <div class="card">
      <h3 style="margin-top:0">Are there free AI tools?</h3>
      <p class="small">Yes. Many tools offer free plans, free trials, or freemium access. Use the free and freemium section to discover them quickly.</p>
    </div>
    <div class="card">
      <h3 style="margin-top:0">How does AIHubpedia rank trending tools?</h3>
      <p class="small">Trending tools are ranked using click activity and discovery signals from users exploring tools on AIHubpedia.</p>
    </div>
  </div>
</section>

<section class="card" style="background:linear-gradient(135deg, rgba(34,197,94,.10), rgba(59,130,246,.10));border:1px solid rgba(255,255,255,.12)">
  <div style="display:flex;justify-content:space-between;gap:20px;align-items:flex-start;flex-wrap:wrap">
    <div style="max-width:760px">
      <div class="badge" style="margin-bottom:10px">Audience & Reach</div>
      <h2 style="margin:0 0 10px">🚀 Partner with AIHubpedia</h2>
      <p class="small" style="margin:0 0 14px">
        Reach developers, founders, marketers, creators, and AI-curious users actively exploring new tools,
        comparing AI software, and looking for product recommendations.
      </p>

      <ul class="small" style="margin:0;padding-left:18px;line-height:1.9">
        <li><strong><?= number_format(count($tools)) ?>+ AI tools listed</strong></li>
        <li><strong>Growing global AI discovery audience</strong></li>
        <li><strong>Homepage, featured, and spotlight placements</strong></li>
        <li><strong>Built for product discovery, clicks, and partnerships</strong></li>
      </ul>
    </div>

    <div class="actions" style="margin-top:0">
      <a class="btn" href="advertise.php">Partner With Us</a>
      <a class="btn secondary" href="submit.php">Submit Your Tool</a>
    </div>
  </div>
</section>

<!-- ═══════════════════════════════════════
     TRUST SECTION
     ═══════════════════════════════════════ -->
<section class="card" style="text-align:center">
  <h2 style="margin-top:0">✅ Why users trust AIHubpedia</h2>
  <div style="display:flex;gap:14px;flex-wrap:wrap;justify-content:center;margin-top:14px">
    <span class="badge" style="font-size:.85rem;padding:8px 16px">✅ 500+ AI tools listed</span>
    <span class="badge" style="font-size:.85rem;padding:8px 16px">✅ Reviews and comparisons</span>
    <span class="badge" style="font-size:.85rem;padding:8px 16px">✅ Community ratings</span>
    <span class="badge" style="font-size:.85rem;padding:8px 16px">✅ Frequently updated</span>
    <span class="badge" style="font-size:.85rem;padding:8px 16px">✅ Independent AI directory</span>
  </div>
</section>

<!-- ═══════════════════════════════════════
     AFFILIATE DISCLOSURE
     ═══════════════════════════════════════ -->
<!--<section class="card" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.10)">
  <p class="small" style="margin:0;line-height:1.7;opacity:.80">
    <strong>Affiliate Disclosure:</strong>
    Some links on AIHubpedia may be affiliate links. If you purchase through them, we may earn a commission at no additional cost to you.
  </p>
</section>
-->
<?php require __DIR__ . '/layout_bottom.php'; ?>
