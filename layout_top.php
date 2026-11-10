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

$canonical = preg_replace('#^https?://(www\.)?187\.77\.184\.173#', 'https://aihubpedia.com', $canonical);
$canonical = preg_replace('#^http://#', 'https://', $canonical);

$og_type = $og_type ?? 'website';
?>

<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title><?= h($title) ?></title>
<meta name="description" content="<?= h($meta_desc) ?>">
<link rel="canonical" href="<?= h($canonical) ?>">

<link rel="icon" href="/favicon.png">

<meta property="og:title" content="<?= h($title) ?>">
<meta property="og:description" content="<?= h($meta_desc) ?>">
<meta property="og:image" content="<?= h($og_image) ?>">

<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="trust-badges.css">
<?php require __DIR__ . '/org-schema.php'; ?>
<style>

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