<?php
require_once __DIR__ . '/helpers.php';

/*
 * Page variables (all optional, set before including this file):
 *   $title       page title (brand suffix added automatically)
 *   $meta_desc   meta description
 *   $canonical   absolute canonical URL (defaults to a clean, whitelisted URL)
 *   $robots      'index' | 'noindex' | full robots string
 *   $og_type     Open Graph type (website, article, ...)
 *   $og_image    absolute image URL
 *   $jsonld      array of extra JSON-LD nodes for this page
 *   $head_extra  extra raw HTML for <head> (e.g. rel prev/next)
 */
$__script = seo_current_script();
$__page = seo_page_registry()[basename($__script)] ?? [];

$title = seo_title($title ?? ($__page['title'] ?? (AIHUB_SITE_NAME . ' — AI Tools Directory')));
$meta_desc = seo_description($meta_desc ?? ($__page['desc'] ?? 'Discover, compare, and explore AI tools for writing, coding, design, productivity, and business on AIHubpedia.'));

if (empty($canonical)) {
  $canonical = !empty($__page['canonical']) ? site_url($__page['canonical']) : seo_default_canonical();
}
$canonical = preg_replace('#^https?://(www\.)?(aihubpedia\.com|187\.77\.184\.173)#i', AIHUB_SITE_URL, (string)$canonical);

// Robots: error responses, registry noindex pages and parameterised (search/filter) URLs are not indexed.
$__status = http_response_code();
$robots = $robots ?? ($__page['robots'] ?? 'index');
if ((is_int($__status) && $__status >= 400) || seo_has_noncanonical_params()) $robots = 'noindex';
$__robots_meta = $robots === 'index' ? 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1'
  : ($robots === 'noindex' ? 'noindex, follow' : $robots);
if (!headers_sent() && strpos($__robots_meta, 'noindex') !== false) header('X-Robots-Tag: noindex, follow');

$og_type = $og_type ?? 'website';
$og_image = $og_image ?? site_url('/logo.png');
$__jsonld = array_merge([seo_org_node(), seo_website_node()], isset($jsonld) && is_array($jsonld) ? $jsonld : []);

$__css_ver = @filemtime(__DIR__ . '/assets/css/style.css') ?: '1';
$__js_ver = @filemtime(__DIR__ . '/assets/js/interactions.js') ?: '1';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?></title>
<meta name="description" content="<?= h($meta_desc) ?>">
<meta name="robots" content="<?= h($__robots_meta) ?>">
<?php if ($robots !== 'noindex'): ?>
<link rel="canonical" href="<?= h($canonical) ?>">
<?php endif; ?>
<meta name="application-name" content="AIHubpedia">
<meta name="theme-color" content="#4f46e5">
<link rel="preconnect" href="https://www.google.com" crossorigin>
<link rel="dns-prefetch" href="https://t0.gstatic.com">
<link rel="stylesheet" href="/assets/css/style.css?v=<?= h($__css_ver) ?>">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/images/favicon-32.png">
<link rel="icon" type="image/png" sizes="48x48" href="/assets/images/favicon-48.png">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/images/favicon-180.png">
<link rel="manifest" href="/site.webmanifest">
<link rel="alternate" type="text/plain" title="LLM-friendly site summary" href="/llms.txt">

<meta property="og:site_name" content="AIHubpedia">
<meta property="og:locale" content="en_US">
<meta property="og:type" content="<?= h($og_type) ?>">
<meta property="og:title" content="<?= h($title) ?>">
<meta property="og:description" content="<?= h($meta_desc) ?>">
<meta property="og:url" content="<?= h($canonical) ?>">
<meta property="og:image" content="<?= h($og_image) ?>">
<meta property="og:image:alt" content="AIHubpedia">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= h($title) ?>">
<meta name="twitter:description" content="<?= h($meta_desc) ?>">
<meta name="twitter:image" content="<?= h($og_image) ?>">
<?= isset($head_extra) ? $head_extra . "\n" : '' ?>
<?= seo_jsonld($__jsonld) ?>

<style>
.brand{display:flex;align-items:center;text-decoration:none;flex-shrink:0}
.brandLogo{height:44px;width:auto;display:block}
@media (max-width:768px){.brandLogo{height:36px}}
</style>
</head>
<body>
<a class="skipLink" href="#main">Skip to content</a>
<header class="nav simpleNav">
  <div class="wrap navInner">
    <a class="brand" href="/" aria-label="AIHubpedia home">
      <img src="/assets/images/logo-44.webp" srcset="/assets/images/logo-44.webp 1x, /assets/images/logo-88.webp 2x" width="187" height="44" alt="AIHubpedia" class="brandLogo" fetchpriority="high">
    </a>

    <nav class="navLinks simpleNavLinks" aria-label="Main navigation">
      <a href="/categories.php">Categories</a>
      <a href="/top.php">Top Tools</a>
      <a href="/compare.php">Compare</a>
      <a href="/leaderboard.php">Leaderboard</a>
      <a href="/ai-use-case-explorer.php">Use Cases</a>

      <div class="simpleDropdown">
        <button class="simpleDropdownBtn" type="button" aria-haspopup="true" aria-expanded="false">More ▾</button>
        <div class="simpleDropdownMenu">
          <a href="/comparisons.php">Comparisons</a>
          <a href="/filters.php">Filters</a>
          <a href="/community.php">Community</a>
          <a href="/blog.php">Blog</a>
          <a href="/about.php">About</a>
        </div>
      </div>

      <a href="/submit.php">Submit</a>
      <a class="contactBtn" href="/contact.php">Contact</a>
    </nav>
  </div>
</header>
<main class="wrap" id="main">
