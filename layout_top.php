<?php
require_once __DIR__ . '/helpers.php';

$site = site_cfg();
$site_name = $site['site_name'] ?? 'AIHubpedia';

$title = $title ?? ($site_name . ' — Discover, Compare & Explore the Best AI Tools');
$meta_desc = $meta_desc ?? 'Discover, compare, and explore the best AI tools for writing, coding, design, productivity, and business on AIHubpedia.';

$canonical = $canonical ?? null;
$og_image = $og_image ?? 'https://aihubpedia.com/logo.png';

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'aihubpedia.com';
$request_uri = $_SERVER['REQUEST_URI'] ?? '/';
$current_url = $scheme . '://' . $host . $request_uri;

if (!$canonical) {
    $canonical = $current_url;
}

/* normalize canonical to primary domain */
$canonical = preg_replace('#^https?://(www\.)?187\.77\.184\.173#', 'https://aihubpedia.com', $canonical);
$canonical = preg_replace('#^http://#', 'https://', $canonical);

/* optional page-specific og type */
$og_type = $og_type ?? 'website';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title><?= h($title) ?></title>
<meta name="description" content="<?= h($meta_desc) ?>">
<meta name="robots" content="index, follow, max-image-preview:large">
<meta name="application-name" content="AIHubpedia">
<meta name="theme-color" content="#4f46e5">
<link rel="canonical" href="<?= h($canonical) ?>">

<link rel="icon" type="image/png" href="https://aihubpedia.com/favicon.png">
<link rel="shortcut icon" href="https://aihubpedia.com/favicon.png">

<!-- Open Graph -->
<meta property="og:site_name" content="AIHubpedia">
<meta property="og:type" content="<?= h($og_type) ?>">
<meta property="og:title" content="<?= h($title) ?>">
<meta property="og:description" content="<?= h($meta_desc) ?>">
<meta property="og:url" content="<?= h($canonical) ?>">
<meta property="og:image" content="<?= h($og_image) ?>">

<!-- Twitter -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= h($title) ?>">
<meta name="twitter:description" content="<?= h($meta_desc) ?>">
<meta name="twitter:image" content="<?= h($og_image) ?>">

<link rel="stylesheet" href="/assets/css/style.css">

<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Organization",
  "name": "AIHubpedia",
  "url": "https://aihubpedia.com",
  "logo": "https://aihubpedia.com/logo.png"
}
</script>

<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "WebSite",
  "name": "AIHubpedia",
  "url": "https://aihubpedia.com",
  "potentialAction": {
    "@type": "SearchAction",
    "target": "https://aihubpedia.com/search.php?q={search_term_string}",
    "query-input": "required name=search_term_string"
  }
}
</script>

<style>
.brand{
  display:flex;
  align-items:center;
  text-decoration:none;
  flex-shrink:0;
}

.brandLogo{
  height:44px;
  width:auto;
  display:block;
}

@media (max-width:768px){
  .brandLogo{
    height:36px;
  }
}
</style>
</head>
<body>
<header class="nav simpleNav">
  <div class="wrap navInner">
    <a class="brand" href="/" aria-label="AIHubpedia home">
      <img src="/logo.png" alt="AIHubpedia" class="brandLogo">
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
<main class="wrap">