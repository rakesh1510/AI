<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/blog-seo.php';

/* ─── Load post ───────────────────────────────────────────── */
$slug = trim($_GET['slug'] ?? '');
$p    = $slug ? find_post($slug) : null;

if (!$p) {
    http_response_code(404);
    $title = "Post not found — AIHubpedia";
    require __DIR__ . '/layout_top.php';
    echo "<h1>Post not found</h1><p class='small'>The requested post does not exist.</p>";
    echo "<div class='actions'><a class='btn secondary' href='blog.php'>← Back to Blog</a></div>";
    require __DIR__ . '/layout_bottom.php';
    exit;
}

/* ─── SEO Titles / Descriptions ─────────────────────────── */
$title     = blog_seo_title($p);
$meta_desc = blog_seo_description($p);

require __DIR__ . '/layout_top.php';

/* ─── All head SEO (OG + schemas) ────────────────────────── */
blog_emit_head_seo($p);

/* ─── Load tools for internal link injection + related tools */
$toolsData   = load_tools();
$allTools    = $toolsData['tools'] ?? [];
$relatedTools = blog_related_tools($p, $allTools, 4);

/* ─── Inject internal links into post content ───────────── */
$postHtml = blog_inject_internal_links($p['content'] ?? '', $allTools);

/* ─── Determine type + topic ─────────────────────────────── */
$type  = blog_post_type($p);
$topic = blog_post_topic($p);
?>

<!-- ═══════════════════ PAGE STYLES ═════════════════════════ -->
<style>
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
.postBody a{color:#818cf8}
.hoverCard{transition:all .22s ease}
.hoverCard:hover{transform:translateY(-4px);box-shadow:0 18px 40px rgba(30,20,80,.18)}
.tocList{list-style:none;padding:0;margin:0}
.tocList li{padding:4px 0;border-bottom:1px solid rgba(255,255,255,.06)}
.tocList li:last-child{border-bottom:none}
.tocList a{color:#818cf8;text-decoration:none;font-size:.9rem}
.tocList a:hover{text-decoration:underline}
</style>

<!-- ═══════════════════ BREADCRUMB ══════════════════════════ -->
<?php blog_render_breadcrumb($p); ?>

<!-- ═══════════════════ ARTICLE HEADER ══════════════════════ -->
<div class="card" style="margin-bottom:4px">
  <h1 style="margin:0 0 12px;line-height:1.35"><?= h($p['title'] ?? '') ?></h1>
  <?php blog_render_meta_bar($p); ?>
  <?php if (!empty($p['excerpt'])): ?>
    <p style="margin:0;opacity:.85;line-height:1.7;font-size:1.05rem;border-left:3px solid rgba(99,102,241,.5);padding-left:14px">
      <?= h($p['excerpt']) ?>
    </p>
  <?php endif; ?>
</div>

<!-- ═══════════════════ POST BODY ════════════════════════════ -->
<div class="card" style="margin-top:4px">
  <div class="postBody">
    <?= $postHtml ?>
  </div>
</div>

<!-- ═══════════════════ COMPARE CTA ════════════════════════ -->
<?php blog_render_compare_cta($p); ?>

<!-- ═══════════════════ RELATED TOOLS ════════════════════════ -->
<?php blog_render_related_tools($relatedTools); ?>

<!-- ═══════════════════ RELATED POSTS ════════════════════════ -->
<?php
// Same topic, excluding current post
$allPosts = load_posts();
usort($allPosts, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));
$relatedPosts = array_values(array_filter($allPosts, function($rp) use ($p, $topic) {
    if (($rp['slug'] ?? '') === ($p['slug'] ?? '')) return false;
    return blog_post_topic($rp) === $topic;
}));
$relatedPosts = array_slice($relatedPosts, 0, 3);

// Fall back to any recent posts if none in same topic
if (empty($relatedPosts)) {
    $relatedPosts = array_slice(
        array_filter($allPosts, fn($rp) => ($rp['slug'] ?? '') !== ($p['slug'] ?? '')),
        0, 3
    );
}
?>
<?php if (!empty($relatedPosts)): ?>
<section class="card" style="margin-top:20px">
  <h3 style="margin-top:0">📖 More Articles You Might Like</h3>
  <div class="grid">
    <?php foreach ($relatedPosts as $rp):
      $rtype = blog_post_type($rp);
      $rtopic = blog_post_topic($rp);
      $rbadge = blog_type_badge_style($rtype);
      $rlabel = blog_type_label($rtype);
    ?>
    <article class="card hoverCard" style="border:1px solid rgba(99,102,241,.15)">
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px">
        <span class="badge" style="<?= $rbadge ?>;font-size:.7rem"><?= $rlabel ?></span>
        <span class="badge gray" style="font-size:.7rem"><?= h($rtopic) ?></span>
      </div>
      <h4 style="margin:0 0 6px;line-height:1.4">
        <a href="post.php?slug=<?= urlencode($rp['slug'] ?? '') ?>" style="color:inherit;text-decoration:none">
          <?= h($rp['title'] ?? '') ?>
        </a>
      </h4>
      <div class="small" style="opacity:.6;margin-bottom:8px"><?= h($rp['date'] ?? '') ?></div>
      <?php if (!empty($rp['excerpt'])): ?>
        <p class="small" style="margin:0 0 10px;opacity:.8"><?= h(substr($rp['excerpt'], 0, 110)) ?>…</p>
      <?php endif; ?>
      <a class="btn secondary" href="post.php?slug=<?= urlencode($rp['slug'] ?? '') ?>">Read →</a>
    </article>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<!-- ═══════════════════ BOTTOM CTA ══════════════════════════ -->
<section class="card" style="margin-top:20px;background:linear-gradient(135deg,rgba(99,102,241,.12),rgba(139,92,246,.08));border:1px solid rgba(139,92,246,.22)">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px">
    <div>
      <h3 style="margin:0 0 6px">Explore the full AI Tools Directory</h3>
      <p class="small" style="margin:0;opacity:.8">
        500+ AI tools reviewed, compared, and categorised to help you choose faster.
      </p>
    </div>
    <div class="actions" style="margin:0">
      <a class="btn" href="/">🔍 Browse AI Tools</a>
      <a class="btn secondary" href="blog.php">← Back to Blog</a>
    </div>
  </div>
</section>

<?php require __DIR__ . '/layout_bottom.php'; ?>
