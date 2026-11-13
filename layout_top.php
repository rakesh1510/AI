<?php
require_once __DIR__ . '/helpers.php';
$site = site_cfg();
$site_name = $site['site_name'] ?? 'AIHubpedia';
$title = seo_text($title ?? '', $site_name . ' — Discover & Compare AI Tools');
$meta_desc = seo_text($meta_desc ?? '', 'Discover AI tools for writing, coding, design, productivity, and business. Compare listed features and explore alternatives on AIHubpedia.');
$request_uri = $_SERVER['REQUEST_URI'] ?? '/';
$canonical = seo_canonical($canonical ?? $request_uri);
$robots_meta = $robots_meta ?? seo_default_robots($request_uri, (int)http_response_code());
if ((int)http_response_code() >= 400) $robots_meta = 'noindex,follow';
$og_image = seo_http_url($og_image ?? '') ?: SEO_ORIGIN . '/logo.png';
$og_type = $og_type ?? 'website';
$page_language = $page_language ?? 'en';
if (!preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D', $page_language)) $page_language = 'en';
$translations = seo_language_links($published_translations ?? []);
?>

<!doctype html>
<html lang="<?= h($page_language) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title><?= h($title) ?></title>
<meta name="description" content="<?= h($meta_desc) ?>">
<link rel="canonical" href="<?= h($canonical) ?>">
<meta name="robots" content="<?= h($robots_meta) ?>">
<?php foreach ($translations as $language => $url): ?>
<link rel="alternate" hreflang="<?= h($language) ?>" href="<?= h($url) ?>">
<?php endforeach; ?>

<link rel="icon" href="/favicon.png">

<meta property="og:title" content="<?= h($title) ?>">
<meta property="og:description" content="<?= h($meta_desc) ?>">
<meta property="og:image" content="<?= h($og_image) ?>">
<meta property="og:image:alt" content="<?= h($og_image_alt ?? 'AIHubpedia') ?>">
<meta property="og:url" content="<?= h($canonical) ?>">
<meta property="og:type" content="<?= h($og_type) ?>">
<meta property="og:site_name" content="<?= h($site_name) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= h($title) ?>">
<meta name="twitter:description" content="<?= h($meta_desc) ?>">
<meta name="twitter:image" content="<?= h($og_image) ?>">
<?php foreach ($page_schemas ?? [] as $schema): ?>
<script type="application/ld+json"><?= seo_json_ld($schema) ?></script>
<?php endforeach; ?>
<?php if (isset($head_extra) && is_callable($head_extra)) $head_extra(); ?>

<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="/trust-badges.css">
<?php require __DIR__ . '/org-schema.php'; ?>
<style>
.seoBreadcrumbs {display:flex;flex-wrap:wrap;gap:1em;list-style:none;padding:0;}
.seoBreadcrumbs li+li:before {content:"/";margin-right:1em;}


/* ===== HEADER FIX ===== */
.simpleNav{
  position: relative;
  z-index: 10000;
  background: #fff;
}

/* ===== BRAND ===== */
.brand{
  display:flex;
  align-items:center;
  text-decoration:none;
}

.brandLogo{
  height:44px;
}

/* ===== NAV ===== */
.navInner{
  display:flex;
  align-items:center;
  justify-content:space-between;
}

.simpleNavLinks{
  display:flex;
  align-items:center;
  gap:18px;
}

/* ===== DROPDOWN ===== */
.simpleDropdown{
  position: relative;
}

.simpleDropdownBtn{
  background:none;
  border:none;
  cursor:pointer;
  font-weight:600;
}

.simpleDropdownMenu{
  position:absolute;
  top:110%;
  right:0;
  background:#fff;
  border-radius:12px;
  padding:12px;
  box-shadow:0 10px 30px rgba(0,0,0,0.1);
  display:none;
  flex-direction:column;
  min-width:200px;
  z-index:99999;
}

.simpleDropdown:hover .simpleDropdownMenu{
  display:flex;
}

.simpleDropdownMenu a{
  padding:10px;
  text-decoration:none;
  color:#333;
  border-radius:8px;
}

.simpleDropdownMenu a:hover{
  background:#f5f5ff;
}

/* ===== BUTTON ===== */
.contactBtn{
  background:#4f46e5;
  color:#fff;
  padding:8px 14px;
  border-radius:10px;
  text-decoration:none;
}

/* ===== FIX OVERLAY ===== */
.hero,
.card{
  position:relative;
  z-index:1;
}

/* ===== MOBILE ===== */
@media (max-width:768px){
  .brandLogo{height:36px;}
  .simpleNavLinks{
    flex-wrap:wrap;
    gap:10px;
  }
}

</style>
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-WX1YVP7HCY"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'G-WX1YVP7HCY');
</script>
</head>
<body>

<header class="nav simpleNav">
  <div class="wrap navInner">

    <!-- LOGO -->
    <a class="brand" href="/">
      <img src="/logo.png" alt="AIHubpedia" class="brandLogo">
    </a>

    <!-- NAV -->
    <nav class="navLinks simpleNavLinks">

      <a href="/categories.php">Categories</a>
      <a href="/top.php">Top Tools</a>
      <a href="/compare.php">Compare</a>
      <a href="/leaderboard.php">Leaderboard</a>

      <!-- 🚀 IMPROVED DROPDOWN -->
      <div class="simpleDropdown">
        <button class="simpleDropdownBtn">Explore ▾</button>

        <div class="simpleDropdownMenu">
          <a href="/top.php">🔥 Trending Tools</a>
          <a href="/ai-use-case-explorer.php">🧭 Use-Case Explorer</a>
          <a href="/community.php">💬 Community</a>
          <a href="/blog.php">✍️ Blog</a>
        </div>
      </div>

      <a href="/submit.php">Submit</a>
      <a class="contactBtn" href="/contact.php">Contact</a>

    </nav>
  </div>
</header>

<main class="wrap">
