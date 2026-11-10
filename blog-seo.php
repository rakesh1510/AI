<?php
/**
 * blog-seo.php — AIHubpedia Blog SEO & Content Helper Library
 *
 * Include ONCE at the top of blog.php and post.php.
 * Requires helpers.php to already be loaded (for h(), load_tools(), load_posts(), etc.)
 *
 * Provides:
 *   — Post classification  (post_type, post_category)
 *   — SEO meta generation  (title, description, OG, schemas)
 *   — Internal link injection (tool/compare/category links inside body)
 *   — Related tools finder
 *   — Blog search + category filter
 *   — "Latest from Blog" widget (for homepage)
 *   — Render helpers (featured card, standard card, category pills)
 */

/* ═══════════════════════════════════════════════════════════
   1. POST CLASSIFICATION
   Detects post type and topic category from slug + title.
   ═══════════════════════════════════════════════════════════ */

/**
 * Detect post type from slug/title.
 * Returns one of: review | comparison | best_list | howto | use_case | guide | general
 */
function blog_post_type(array $p): string {
    $hay = strtolower(($p['slug'] ?? '') . ' ' . ($p['title'] ?? ''));
    if (preg_match('/\bvs\b|versus|compared?\s+to|vs-/', $hay))          return 'comparison';
    if (preg_match('/\breview\b/', $hay))                                 return 'review';
    if (preg_match('/\bbest\b.*(tools?|apps?|software|platforms?|ai)/',  $hay)) return 'best_list';
    if (preg_match('/\bhow\s+to\b|how-to/', $hay))                       return 'howto';
    if (preg_match('/\buse\s+cases?\b|use-case/', $hay))                 return 'use_case';
    if (preg_match('/\bguide\b|tutorial|tips?/', $hay))                  return 'guide';
    return 'general';
}

/**
 * Detect topic category from slug/title.
 * Returns a human-readable string, e.g. "Website Builder", "SEO", "Writing".
 */
function blog_post_topic(array $p): string {
    $hay = strtolower(($p['slug'] ?? '') . ' ' . ($p['title'] ?? '') . ' ' . ($p['excerpt'] ?? ''));
    $map = [
        'website'    => 'Website Builder',
        'seo'        => 'SEO',
        'coding'     => 'Coding',
        'code'       => 'Coding',
        'writing'    => 'Writing',
        'chatbot'    => 'Chatbot',
        'chat'       => 'Chatbot',
        'video'      => 'Video',
        'image'      => 'Image & Design',
        'design'     => 'Image & Design',
        'marketing'  => 'Marketing',
        'automation' => 'Automation',
        'research'   => 'Research',
        'audio'      => 'Audio',
        'data'       => 'Data & Analytics',
        'productivity' => 'Productivity',
    ];
    foreach ($map as $kw => $label) {
        if (str_contains($hay, $kw)) return $label;
    }
    return 'AI Tools';
}

/**
 * Get a human label for the post type.
 */
function blog_type_label(string $type): string {
    return [
        'review'     => '📝 Review',
        'comparison' => '⚖️ Comparison',
        'best_list'  => '🏆 Best List',
        'howto'      => '🛠️ How-To',
        'use_case'   => '💡 Use Case',
        'guide'      => '📖 Guide',
        'general'    => '📄 Article',
    ][$type] ?? '📄 Article';
}

/**
 * Badge color class (inline style string) per type.
 */
function blog_type_badge_style(string $type): string {
    return [
        'review'     => 'background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff',
        'comparison' => 'background:linear-gradient(135deg,#0ea5e9,#6366f1);color:#fff',
        'best_list'  => 'background:linear-gradient(135deg,#f59e0b,#ef4444);color:#fff',
        'howto'      => 'background:linear-gradient(135deg,#10b981,#0ea5e9);color:#fff',
        'use_case'   => 'background:linear-gradient(135deg,#8b5cf6,#ec4899);color:#fff',
        'guide'      => 'background:linear-gradient(135deg,#64748b,#475569);color:#fff',
        'general'    => 'background:rgba(255,255,255,.12)',
    ][$type] ?? 'background:rgba(255,255,255,.12)';
}

/* ═══════════════════════════════════════════════════════════
   2. SEO META GENERATION
   ═══════════════════════════════════════════════════════════ */

/**
 * Build the <title> tag value for a post.
 */
function blog_seo_title(array $p): string {
    if (!empty($p['seo_title'])) return $p['seo_title'];
    $base  = trim($p['title'] ?? 'Blog');
    $year  = date('Y');
    $type  = blog_post_type($p);
    $suffix = [
        'review'     => " — In-Depth Review $year | AIHubpedia",
        'comparison' => " — Full Comparison $year | AIHubpedia",
        'best_list'  => " — Complete Guide $year | AIHubpedia",
        'howto'      => " — Step-by-Step Guide | AIHubpedia",
        'use_case'   => " — Practical AI Guide | AIHubpedia",
        'guide'      => " — AI Guide $year | AIHubpedia",
        'general'    => " | AIHubpedia",
    ][$type] ?? ' | AIHubpedia';
    return $base . $suffix;
}

/**
 * Build the meta description for a post.
 */
function blog_seo_description(array $p): string {
    if (!empty($p['seo_description'])) return $p['seo_description'];
    if (!empty($p['excerpt']))         return trim($p['excerpt']);
    // Strip any HTML from content and take 155 chars
    $raw = strip_tags($p['content'] ?? '');
    $raw = preg_replace('/\s+/', ' ', $raw);
    return substr(trim($raw), 0, 155);
}

/**
 * Emit all head SEO tags for a post page.
 * Call inside <head> (layout_top.php already renders $title & $meta_desc;
 * this adds OG + schemas).
 */
function blog_emit_head_seo(array $p, string $baseUrl = 'https://aihubpedia.com'): void {
    $slug   = h($p['slug'] ?? '');
    $url    = $baseUrl . '/post.php?slug=' . rawurlencode($p['slug'] ?? '');
    $title  = h(blog_seo_title($p));
    $desc   = h(blog_seo_description($p));
    $date   = h($p['date'] ?? $p['published_at'] ?? '');
    $type   = blog_post_type($p);
    $topic  = blog_post_topic($p);

    // ── OG + Twitter ──────────────────────────────────────
    echo '<meta property="og:type"        content="article">' . "\n";
    echo '<meta property="og:title"       content="' . $title . '">' . "\n";
    echo '<meta property="og:description" content="' . $desc  . '">' . "\n";
    echo '<meta property="og:url"         content="' . h($url) . '">' . "\n";
    if (!empty($p['image'])) {
        echo '<meta property="og:image" content="' . h($p['image']) . '">' . "\n";
    }
    echo '<meta name="twitter:card"        content="summary_large_image">' . "\n";
    echo '<meta name="twitter:title"       content="' . $title . '">' . "\n";
    echo '<meta name="twitter:description" content="' . $desc  . '">' . "\n";
    echo '<link rel="canonical"            href="'    . h($url) . '">' . "\n";

    // ── Article Schema ────────────────────────────────────
    $articleSchema = [
        '@context'         => 'https://schema.org',
        '@type'            => ($type === 'howto') ? 'HowTo' : 'Article',
        'headline'         => $p['title'] ?? '',
        'description'      => blog_seo_description($p),
        'url'              => $url,
        'datePublished'    => $date,
        'dateModified'     => $p['updated_at'] ?? $date,
        'author'           => ['@type' => 'Organization', 'name' => 'AIHubpedia'],
        'publisher'        => [
            '@type' => 'Organization',
            'name'  => 'AIHubpedia',
            'logo'  => ['@type' => 'ImageObject', 'url' => $baseUrl . '/assets/img/logo.png'],
        ],
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
    ];

    // ── Breadcrumb Schema ────────────────────────────────
    $breadcrumbSchema = [
        '@context' => 'https://schema.org',
        '@type'    => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home',  'item' => $baseUrl . '/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Blog',  'item' => $baseUrl . '/blog.php'],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $p['title'] ?? '', 'item' => $url],
        ],
    ];

    // ── FAQ Schema (if post has faq data) ────────────────
    $schemas = [$articleSchema, $breadcrumbSchema];
    if (!empty($p['faqs']) && is_array($p['faqs'])) {
        $faqEntities = [];
        foreach ($p['faqs'] as $faq) {
            if (!empty($faq['q']) && !empty($faq['a'])) {
                $faqEntities[] = [
                    '@type'          => 'Question',
                    'name'           => $faq['q'],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq['a']],
                ];
            }
        }
        if ($faqEntities) {
            $schemas[] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $faqEntities];
        }
    }

    foreach ($schemas as $schema) {
        echo '<script type="application/ld+json">' . "\n";
        echo json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        echo "\n</script>\n";
    }
}

/* ═══════════════════════════════════════════════════════════
   3. INTERNAL LINK INJECTION
   Scans post HTML content and wraps known tool names / category
   keywords in links to tool pages, compare pages, and category pages.
   Only replaces the FIRST occurrence of each name to avoid over-linking.
   ═══════════════════════════════════════════════════════════ */

/**
 * Inject internal links into post HTML content.
 * Returns modified HTML string.
 */
function blog_inject_internal_links(string $html, array $allTools): string {
    if (empty($html) || empty($allTools)) return $html;

    // Build lookup: tool name (lowercase) => tool array
    $toolMap = [];
    foreach ($allTools as $t) {
        $name = trim($t['name'] ?? '');
        if ($name !== '') {
            $toolMap[strtolower($name)] = $t;
        }
    }

    // Sort by name length descending so "GitHub Copilot" matches before "GitHub"
    uksort($toolMap, fn($a, $b) => strlen($b) - strlen($a));

    $replaced = []; // track what we've already linked

    foreach ($toolMap as $lower => $t) {
        if (isset($replaced[$lower])) continue;
        $name = $t['name'];
        $id   = $t['id'] ?? '';
        if ($id === '') continue;

        // Only link first occurrence; don't link inside existing <a> tags
        $pattern     = '/(?<!["\'>\/])(\b' . preg_quote($name, '/') . '\b)(?![^<]*<\/a>)/u';
        $replacement = '<a href="tool.php?id=' . urlencode($id) . '" class="blogToolLink" title="' . htmlspecialchars($name) . ' — AI Tool Review">${1}</a>';

        $new = preg_replace($pattern, $replacement, $html, 1);
        if ($new !== null && $new !== $html) {
            $html = $new;
            $replaced[$lower] = true;
        }
    }

    // Category keyword links
    $categoryLinks = [
        'AI writing tools'      => '/?cat=Writing',
        'AI coding tools'       => '/?cat=Coding',
        'AI SEO tools'          => '/?cat=SEO',
        'AI chatbots'           => '/?cat=Chatbot',
        'AI image tools'        => '/?cat=Image+Generation',
        'AI video tools'        => '/?cat=Video',
        'AI automation tools'   => '/?cat=Automation',
        'website builders'      => '/?cat=Website+Builder',
        'AI website builders'   => '/?cat=Website+Builder',
    ];
    foreach ($categoryLinks as $phrase => $href) {
        if (isset($replaced['cat:' . $phrase])) continue;
        $pattern = '/(?<!["\'>])(' . preg_quote($phrase, '/') . ')(?![^<]*<\/a>)/i';
        $new = preg_replace(
            $pattern,
            '<a href="' . $href . '" class="blogCatLink">${1}</a>',
            $html, 1
        );
        if ($new !== null && $new !== $html) {
            $html = $new;
            $replaced['cat:' . $phrase] = true;
        }
    }

    return $html;
}

/* ═══════════════════════════════════════════════════════════
   4. RELATED TOOLS FINDER
   Matches tools to a post based on name mentions and topic.
   ═══════════════════════════════════════════════════════════ */

function blog_related_tools(array $p, array $allTools, int $limit = 4): array {
    $haystack = strtolower(
        ($p['title'] ?? '') . ' ' .
        ($p['excerpt'] ?? '') . ' ' .
        strip_tags($p['content'] ?? '')
    );

    $scored = [];
    foreach ($allTools as $t) {
        $name = strtolower($t['name'] ?? '');
        $cat  = strtolower($t['category'] ?? '');
        $score = 0;
        if ($name !== '' && str_contains($haystack, $name))  $score += 5;
        if ($cat  !== '' && str_contains($haystack, $cat))   $score += 2;
        // boost by click popularity
        $score += min(3, (int)(click_count($t['id'] ?? '') / 50));
        if ($score > 0) {
            $t['_score'] = $score;
            $scored[] = $t;
        }
    }

    if (count($scored) < $limit) {
        // fill with top clicked tools in same topic category
        $topic = strtolower(blog_post_topic($p));
        foreach ($allTools as $t) {
            if (in_array($t, $scored, true)) continue;
            if (str_contains(strtolower($t['category'] ?? ''), $topic)) {
                $t['_score'] = 1;
                $scored[] = $t;
            }
        }
    }

    usort($scored, fn($a, $b) => ($b['_score'] ?? 0) <=> ($a['_score'] ?? 0));
    return array_slice($scored, 0, $limit);
}

/* ═══════════════════════════════════════════════════════════
   5. BLOG SEARCH + CATEGORY FILTER
   ═══════════════════════════════════════════════════════════ */

function blog_filter_posts(array $posts, string $q, string $cat): array {
    $q   = strtolower(trim($q));
    $cat = strtolower(trim($cat));
    return array_values(array_filter($posts, function ($p) use ($q, $cat) {
        if ($cat !== '') {
            $ptopic = strtolower(blog_post_topic($p));
            if ($ptopic !== $cat) return false;
        }
        if ($q === '') return true;
        $hay = strtolower(($p['title'] ?? '') . ' ' . ($p['excerpt'] ?? '') . ' ' . strip_tags($p['content'] ?? ''));
        return str_contains($hay, $q);
    }));
}

/**
 * Get all unique topic categories from a set of posts.
 */
function blog_all_categories(array $posts): array {
    $cats = [];
    foreach ($posts as $p) {
        $c = blog_post_topic($p);
        $cats[$c] = ($cats[$c] ?? 0) + 1;
    }
    arsort($cats);
    return $cats; // [ 'Website Builder' => 3, 'SEO' => 2, ... ]
}

/* ═══════════════════════════════════════════════════════════
   6. "LATEST FROM BLOG" WIDGET (for homepage)
   ═══════════════════════════════════════════════════════════ */

/**
 * Render a compact "Latest from the Blog" section.
 * Drop this inside index.php: <?php blog_render_homepage_widget(3); ?>
 */
function blog_render_homepage_widget(int $count = 3): void {
    $posts = load_posts();
    // Already sorted DESC by date from load_posts(), but re-sort to be safe
    usort($posts, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));
    $posts = array_slice($posts, 0, $count);
    if (empty($posts)) return;
    ?>
    <section class="card" style="margin-top:16px">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:16px">
        <h2 style="margin:0">✍️ Latest from the Blog</h2>
        <a class="btn secondary" href="blog.php">View all posts →</a>
      </div>
      <div class="grid">
        <?php foreach ($posts as $p):
          $type  = blog_post_type($p);
          $topic = blog_post_topic($p);
          $badge_style = blog_type_badge_style($type);
          $label = blog_type_label($type);
        ?>
        <article class="card hoverCard" style="border:1px solid rgba(99,102,241,.15)">
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
            <span class="badge" style="<?= $badge_style ?>;font-size:.72rem"><?= $label ?></span>
            <span class="badge gray" style="font-size:.72rem"><?= h($topic) ?></span>
          </div>
          <h3 style="margin:0 0 8px;font-size:1rem;line-height:1.45">
            <a href="post.php?slug=<?= urlencode($p['slug'] ?? '') ?>" style="color:inherit;text-decoration:none">
              <?= h($p['title'] ?? '') ?>
            </a>
          </h3>
          <div class="small" style="opacity:.6;margin-bottom:8px"><?= h($p['date'] ?? '') ?></div>
          <?php if (!empty($p['excerpt'])): ?>
            <p class="small" style="margin:0 0 12px;opacity:.85"><?= h(substr($p['excerpt'], 0, 120)) ?><?= strlen($p['excerpt']) > 120 ? '…' : '' ?></p>
          <?php endif; ?>
          <a class="btn secondary" href="post.php?slug=<?= urlencode($p['slug'] ?? '') ?>">Read →</a>
        </article>
        <?php endforeach; ?>
      </div>
    </section>
    <?php
}

/* ═══════════════════════════════════════════════════════════
   7. RENDER HELPERS (blog.php cards)
   ═══════════════════════════════════════════════════════════ */

/**
 * Render a large "featured" post card.
 */
function blog_render_featured_card(array $p): void {
    $type  = blog_post_type($p);
    $topic = blog_post_topic($p);
    $badge = blog_type_badge_style($type);
    $label = blog_type_label($type);
    $slug  = urlencode($p['slug'] ?? '');
    ?>
    <article class="card hoverCard" style="background:linear-gradient(135deg,rgba(99,102,241,.14),rgba(139,92,246,.10));border:1px solid rgba(139,92,246,.28)">
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px">
        <span class="badge" style="<?= $badge ?>;font-size:.75rem"><?= $label ?></span>
        <span class="badge gray" style="font-size:.75rem"><?= h($topic) ?></span>
        <span class="badge" style="background:linear-gradient(135deg,#f59e0b,#ef4444);color:#fff;font-size:.72rem">⭐ Featured</span>
      </div>
      <h2 style="margin:0 0 10px;font-size:1.35rem;line-height:1.4">
        <a href="post.php?slug=<?= $slug ?>" style="color:inherit;text-decoration:none">
          <?= h($p['title'] ?? '') ?>
        </a>
      </h2>
      <div class="small" style="opacity:.6;margin-bottom:10px"><?= h($p['date'] ?? '') ?></div>
      <?php if (!empty($p['excerpt'])): ?>
        <p style="margin:0 0 16px;line-height:1.65"><?= h($p['excerpt']) ?></p>
      <?php endif; ?>
      <a class="btn" href="post.php?slug=<?= $slug ?>">Read article →</a>
    </article>
    <?php
}

/**
 * Render a standard post grid card.
 */
function blog_render_post_card(array $p): void {
    $type  = blog_post_type($p);
    $topic = blog_post_topic($p);
    $badge = blog_type_badge_style($type);
    $label = blog_type_label($type);
    $slug  = urlencode($p['slug'] ?? '');
    ?>
    <article class="card hoverCard" style="border:1px solid rgba(99,102,241,.15)">
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
        <span class="badge" style="<?= $badge ?>;font-size:.72rem"><?= $label ?></span>
        <span class="badge gray" style="font-size:.72rem"><?= h($topic) ?></span>
      </div>
      <h3 style="margin:0 0 8px;line-height:1.45">
        <a href="post.php?slug=<?= $slug ?>" style="color:inherit;text-decoration:none">
          <?= h($p['title'] ?? '') ?>
        </a>
      </h3>
      <div class="small" style="opacity:.6;margin-bottom:8px"><?= h($p['date'] ?? '') ?></div>
      <?php if (!empty($p['excerpt'])): ?>
        <p class="small" style="margin:0 0 12px;opacity:.85"><?= h(substr($p['excerpt'], 0, 130)) ?><?= strlen($p['excerpt']) > 130 ? '…' : '' ?></p>
      <?php endif; ?>
      <div class="actions">
        <a class="btn secondary" href="post.php?slug=<?= $slug ?>">Read →</a>
        <a class="btn secondary" href="blog.php?cat=<?= urlencode(strtolower($topic)) ?>"><?= h($topic) ?></a>
      </div>
    </article>
    <?php
}

/**
 * Render related tool cards (compact inline row).
 */
function blog_render_related_tools(array $tools): void {
    if (empty($tools)) return;
    ?>
    <section class="card" style="margin-top:20px">
      <h3 style="margin-top:0">🔧 Related AI Tools</h3>
      <div class="grid">
        <?php foreach ($tools as $t):
          $id      = $t['id'] ?? '';
          $name    = $t['name'] ?? 'AI Tool';
          $pricing = $t['pricing'] ?? 'Varies';
          $short   = $t['short'] ?? '';
          $affUrl  = tool_open_url($t);
        ?>
        <div class="card" style="border:1px solid rgba(99,102,241,.15)">
          <div style="display:flex;gap:10px;align-items:center;margin-bottom:10px">
            <img src="<?= h(tool_logo($t)) ?>" alt="<?= h($name) ?> logo" width="40" height="40" style="border-radius:8px" loading="lazy">
            <div>
              <strong><?= h($name) ?></strong>
              <div class="small" style="opacity:.7"><?= h($pricing) ?></div>
            </div>
          </div>
          <?php if ($short): ?>
            <p class="small" style="margin:0 0 10px;opacity:.85"><?= h(substr($short, 0, 90)) ?>…</p>
          <?php endif; ?>
          <div class="actions">
            <a class="btn secondary" href="tool.php?id=<?= urlencode($id) ?>">Review</a>
            <?php if ($affUrl !== '#'): ?>
              <a class="btn" href="<?= h($affUrl) ?>" target="_blank" rel="noopener">Try it →</a>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php
}

/**
 * Render breadcrumb navigation for a post.
 */
function blog_render_breadcrumb(array $p): void {
    $topic = blog_post_topic($p);
    ?>
    <nav class="small" style="margin-bottom:14px;opacity:.75" aria-label="Breadcrumb">
      <a href="/" style="color:inherit">Home</a>
      <span style="margin:0 6px">›</span>
      <a href="blog.php" style="color:inherit">Blog</a>
      <span style="margin:0 6px">›</span>
      <a href="blog.php?cat=<?= urlencode(strtolower($topic)) ?>" style="color:inherit"><?= h($topic) ?></a>
      <span style="margin:0 6px">›</span>
      <span><?= h($p['title'] ?? '') ?></span>
    </nav>
    <?php
}

/**
 * Render the author / date / reading-time bar.
 */
function blog_render_meta_bar(array $p): void {
    $wordCount = str_word_count(strip_tags($p['content'] ?? ''));
    $readMins  = max(1, (int)round($wordCount / 220));
    $type      = blog_post_type($p);
    $badge     = blog_type_badge_style($type);
    $label     = blog_type_label($type);
    ?>
    <div class="small" style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;margin-bottom:16px;opacity:.85">
      <span class="badge" style="<?= $badge ?>"><?= $label ?></span>
      <span>✍️ AIHubpedia</span>
      <span>📅 <?= h($p['date'] ?? '') ?></span>
      <span>⏱ <?= $readMins ?> min read</span>
    </div>
    <?php
}

/**
 * Render "Compare this tool" CTA if the post is a review or comparison.
 */
function blog_render_compare_cta(array $p): void {
    $type = blog_post_type($p);
    if (!in_array($type, ['review', 'comparison'], true)) return;

    // Extract slugs: reviews = 1 tool from slug, comparisons = two tools
    preg_match_all('/[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?/', strtolower($p['slug'] ?? ''), $m);
    $parts = $m[0] ?? [];
    $href  = '';

    if ($type === 'comparison' && count($parts) >= 2) {
        // Try to identify the two tool slugs around "vs"
        $slug  = $p['slug'] ?? '';
        $sides = preg_split('/-vs-|_vs_|versus/', $slug, 2);
        if (count($sides) === 2) {
            $href = 'compare.php?a=' . urlencode(trim($sides[0], '-')) . '&b=' . urlencode(trim($sides[1], '-'));
        }
    } elseif ($type === 'review') {
        // Guess tool slug: everything before "-review"
        $toolSlug = preg_replace('/-review.*$/i', '', $p['slug'] ?? '');
        $href = 'compare.php?a=' . urlencode($toolSlug);
    }
    if ($href === '') return;
    ?>
    <div class="card" style="margin-top:20px;background:linear-gradient(135deg,rgba(99,102,241,.12),rgba(59,130,246,.08));border:1px solid rgba(99,102,241,.25);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px">
      <div>
        <strong>📊 Compare with other tools</strong>
        <p class="small" style="margin:4px 0 0;opacity:.8">See how it stacks up side-by-side with alternatives.</p>
      </div>
      <a class="btn" href="<?= h($href) ?>">Open comparison →</a>
    </div>
    <?php
}
