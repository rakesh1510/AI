<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/blog-seo.php';

/* ─── Data ────────────────────────────────────────────────── */
$allPosts = load_posts();
usort($allPosts, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));

/* ─── Filters from URL ────────────────────────────────────── */
$q   = trim($_GET['q']   ?? '');
$cat = strtolower(trim($_GET['cat'] ?? ''));

/* ─── SEO Meta ────────────────────────────────────────────── */
$baseUrl = 'https://aihubpedia.com';
if ($cat !== '') {
  $title     = "Best " . ucwords($cat) . " AI Articles — AIHubpedia Blog";
  $meta_desc = "Browse AIHubpedia blog posts about " . ucwords($cat) . " AI tools, reviews, comparisons, and guides.";
} elseif ($q !== '') {
  $title     = "Blog search: " . $q . " — AIHubpedia";
  $meta_desc = "Search results for \"" . $q . "\" on the AIHubpedia blog.";
} else {
  $title     = "AI Tools Blog — Reviews, Comparisons & Guides | AIHubpedia";
  $meta_desc = "Discover the best AI tools with in-depth reviews, comparisons, how-to guides, and curated tool lists. Updated regularly by AIHubpedia.";
}

require __DIR__ . '/layout_top.php';

/* ─── Canonical + OG (minimal — post.php has the full schema) */
$canonical = $baseUrl . '/blog.php' . ($cat ? '?cat=' . rawurlencode($cat) : ($q ? '?q=' . rawurlencode($q) : ''));
echo '<link rel="canonical" href="' . htmlspecialchars($canonical) . '">' . "\n";
echo '<meta property="og:title" content="' . htmlspecialchars($title) . '">' . "\n";
echo '<meta property="og:description" content="' . htmlspecialchars($meta_desc) . '">' . "\n";
echo '<meta property="og:type" content="website">' . "\n";
echo '<meta property="og:url" content="' . htmlspecialchars($canonical) . '">' . "\n";

/* ─── Breadcrumb schema ───────────────────────────────────── */
$bcSchema = [
  '@context' => 'https://schema.org',
  '@type'    => 'BreadcrumbList',
  'itemListElement' => [
    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $baseUrl . '/'],
    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Blog', 'item' => $baseUrl . '/blog.php'],
  ],
];
echo '<script type="application/ld+json">' . json_encode($bcSchema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . '</script>' . "\n";

/* ─── Categories ──────────────────────────────────────────── */
$allCategories = blog_all_categories($allPosts);  // [ 'SEO' => 4, 'Coding' => 2, … ]

/* ─── Filtered set ────────────────────────────────────────── */
$filtered = blog_filter_posts($allPosts, $q, $cat);

/* ─── Popular = top 6 by word-count proxy (no view counter yet) */
$popular = $allPosts;
usort($popular, fn($a, $b) => str_word_count(strip_tags($b['content'] ?? '')) <=> str_word_count(strip_tags($a['content'] ?? '')));
$popular = array_slice($popular, 0, 6);

/* ─── Featured = first post OR one explicitly flagged ────────*/
$featured = null;
foreach ($allPosts as $p) {
  if (!empty($p['featured'])) { $featured = $p; break; }
}
if (!$featured && !empty($allPosts)) $featured = $allPosts[0];

/* ─── Latest = newest posts excluding featured ───────────── */
$latest = array_values(array_filter($allPosts, fn($p) => ($p['slug'] ?? '') !== ($featured['slug'] ?? '')));
$latest = array_slice($latest, 0, 6);
?>

<!-- ═══════════════════ PAGE STYLES ═════════════════════════ -->
<style>
.blogHero{background:linear-gradient(135deg,rgba(99,102,241,.16),rgba(139,92,246,.12));border-radius:14px;padding:32px 28px;margin-bottom:24px}
.blogSearchBar{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
.blogSearchBar input,.blogSearchBar select{flex:1;min-width:180px}
.catPill{display:inline-block;padding:6px 14px;border-radius:999px;font-size:.78rem;text-decoration:none;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);color:inherit;transition:all .2s}
.catPill:hover,.catPill.active{background:linear-gradient(135deg,#6366f1,#8b5cf6);border-color:transparent;color:#fff}
.blogToolLink{color:#818cf8;text-decoration:none;border-bottom:1px dashed rgba(129,140,248,.4)}
.blogToolLink:hover{border-bottom-color:#818cf8}
.blogCatLink{color:#34d399;text-decoration:none;border-bottom:1px dashed rgba(52,211,153,.4)}
.blogCatLink:hover{border-bottom-color:#34d399}
.postBody{line-height:1.8;font-size:1rem}
.postBody h2{margin-top:2em;margin-bottom:.6em}
.postBody h3{margin-top:1.6em;margin-bottom:.4em}
.postBody ul,.postBody ol{padding-left:20px;margin:12px 0}
.postBody li{margin-bottom:6px}
.postBody blockquote{border-left:3px solid #6366f1;padding-left:16px;opacity:.85;font-style:italic;margin:20px 0}
.postBody table{width:100%;border-collapse:collapse;margin:16px 0;font-size:.9rem}
.postBody table th,.postBody table td{padding:8px 12px;border:1px solid rgba(255,255,255,.10);text-align:left}
.postBody table th{background:rgba(99,102,241,.15)}
.hoverCard{transition:all .22s ease;border:1px solid rgba(99,102,241,.15)}
.hoverCard:hover{transform:translateY(-4px);box-shadow:0 18px 40px rgba(30,20,80,.18);border-color:rgba(99,102,241,.38)}
</style>

<!-- ═══════════════════ HERO / SEARCH ═══════════════════════ -->
<div class="blogHero">
  <h1 style="margin:0 0 8px">✍️ AIHubpedia Blog</h1>
  <p class="small" style="margin:0 0 0;opacity:.85;max-width:680px">
    In-depth AI tool reviews, comparisons, how-to guides, and curated tool lists —
    updated regularly to help you choose, compare, and use the best AI tools.
  </p>

  <form method="get" action="blog.php" class="blogSearchBar" style="margin-top:18px">
    <input class="input" name="q" value="<?= h($q) ?>" placeholder="Search articles…" aria-label="Search blog" />
    <select class="input" name="cat" aria-label="Filter by category">
      <option value="">All topics</option>
      <?php foreach (array_keys($allCategories) as $c): ?>
        <option value="<?= h(strtolower($c)) ?>" <?= (strtolower($c) === $cat) ? 'selected' : '' ?>>
          <?= h($c) ?> (<?= (int)$allCategories[$c] ?>)
        </option>
      <?php endforeach; ?>
    </select>
    <button class="btn" type="submit">Search</button>
    <a class="btn secondary" href="blog.php">Reset</a>
  </form>
</div>

<!-- ═══════════════════ CATEGORY PILLS ══════════════════════ -->
<?php if (!empty($allCategories)): ?>
<section style="margin-bottom:24px">
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <a class="catPill <?= ($cat === '') ? 'active' : '' ?>" href="blog.php">All</a>
    <?php foreach (array_keys($allCategories) as $c): ?>
      <a class="catPill <?= (strtolower($c) === $cat) ? 'active' : '' ?>"
         href="blog.php?cat=<?= urlencode(strtolower($c)) ?>">
        <?= h($c) ?> <span style="opacity:.6"><?= (int)$allCategories[$c] ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($q !== '' || $cat !== ''): ?>
<!-- ══════ FILTERED RESULTS ══════════════════════════════════ -->
  <section class="card" style="margin-bottom:24px">
    <h2 style="margin-top:0">
      <?php if ($q)   echo '🔎 Results for "' . h($q) . '"'; ?>
      <?php if ($cat) echo '📂 ' . h(ucwords($cat)); ?>
      <span class="small" style="opacity:.6;font-weight:400"> — <?= count($filtered) ?> article<?= count($filtered) !== 1 ? 's' : '' ?></span>
    </h2>
    <?php if (empty($filtered)): ?>
      <p class="small">No articles found. <a href="blog.php">View all posts →</a></p>
    <?php else: ?>
      <div class="grid">
        <?php foreach ($filtered as $p): blog_render_post_card($p); endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

<?php else: ?>
<!-- ══════ FULL HOMEPAGE LAYOUT ══════════════════════════════ -->

  <!-- Featured Article -->
  <?php if ($featured): ?>
  <section style="margin-bottom:24px">
    <h2 style="margin-top:0;margin-bottom:14px">⭐ Featured Article</h2>
    <?php blog_render_featured_card($featured); ?>
  </section>
  <?php endif; ?>

  <!-- Latest Articles -->
  <?php if (!empty($latest)): ?>
  <section class="card" style="margin-bottom:24px">
    <h2 style="margin-top:0">🆕 Latest Articles</h2>
    <div class="grid">
      <?php foreach ($latest as $p): blog_render_post_card($p); endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- Popular Articles -->
  <?php if (!empty($popular)): ?>
  <section class="card" style="margin-bottom:24px">
    <h2 style="margin-top:0">🔥 Popular Articles</h2>
    <div class="grid">
      <?php foreach ($popular as $p): blog_render_post_card($p); endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

<?php endif; // end filtered/full toggle ?>

<!-- ══════ CONTENT TYPE QUICK LINKS ═════════════════════════ -->
<section class="card" style="margin-bottom:24px">
  <h2 style="margin-top:0">📚 Browse by Content Type</h2>
  <div style="display:flex;gap:12px;flex-wrap:wrap">
    <a class="badge" href="blog.php?q=review" style="padding:8px 14px">📝 Reviews</a>
    <a class="badge" href="blog.php?q=vs" style="padding:8px 14px">⚖️ Comparisons</a>
    <a class="badge" href="blog.php?q=best" style="padding:8px 14px">🏆 Best Lists</a>
    <a class="badge" href="blog.php?q=how+to" style="padding:8px 14px">🛠️ How-To Guides</a>
    <a class="badge" href="blog.php?q=use+case" style="padding:8px 14px">💡 Use Cases</a>
    <a class="badge" href="blog.php?q=guide" style="padding:8px 14px">📖 Guides</a>
  </div>
</section>

<!-- ══════ SUGGESTED ARTICLES CTA ═══════════════════════════ -->
<section class="card" style="background:linear-gradient(135deg,rgba(99,102,241,.12),rgba(139,92,246,.08));border:1px solid rgba(139,92,246,.22)">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px">
    <div>
      <h3 style="margin:0 0 6px">Looking for a specific AI tool review?</h3>
      <p class="small" style="margin:0;opacity:.8">
        Browse 500+ AI tools, compare alternatives, and find the right tool for your workflow.
      </p>
    </div>
    <div class="actions" style="margin:0">
      <a class="btn" href="/">🔍 Explore AI Tools</a>
      <a class="btn secondary" href="compare.php">⚖️ Compare Tools</a>
    </div>
  </div>
</section>

<?php require __DIR__ . '/layout_bottom.php'; ?>
