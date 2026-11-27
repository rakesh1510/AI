<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly (defence in depth if web-server rules are missing)
require_once __DIR__ . '/helpers.php';
sec_send_headers();

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
if (strpos(seo_current_script(), 'universe') === 0 && !(function_exists('universe_public') && universe_public())) $robots = 'noindex, nofollow';   // hidden feature: reachable by URL, never indexed
// A page may set a stricter full robots string (private pages use 'noindex, nofollow'); that must survive error statuses and be sent as the header too.
$__strict_robots = is_string($robots) && strpos($robots, 'nofollow') !== false;
if (((is_int($__status) && $__status >= 400) || seo_has_noncanonical_params()) && !$__strict_robots) $robots = 'noindex';
$__robots_meta = $robots === 'index' ? 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1'
  : ($robots === 'noindex' ? 'noindex, follow' : $robots);
if (!headers_sent() && strpos($__robots_meta, 'noindex') !== false) header('X-Robots-Tag: ' . ($__strict_robots ? $__robots_meta : 'noindex, follow'));

$og_type = $og_type ?? 'website';
$og_image = $og_image ?? site_url('/logo.png');
$__jsonld = array_merge([seo_org_node(), seo_website_node()], isset($jsonld) && is_array($jsonld) ? $jsonld : []);

analytics_track_request();

// Search-engine ownership verification (set the env vars; nothing is output otherwise).
$__verify = '';
if (($v = getenv('AIHUB_GOOGLE_SITE_VERIFICATION')) !== false && $v !== '') $__verify .= '<meta name="google-site-verification" content="' . h($v) . '">' . "\n";
if (($v = getenv('AIHUB_BING_SITE_VERIFICATION')) !== false && $v !== '') $__verify .= '<meta name="msvalidate.01" content="' . h($v) . '">' . "\n";

$__css_ver = @filemtime(__DIR__ . '/assets/css/style.css') ?: '1';
$__ui_ver = @filemtime(__DIR__ . '/assets/css/ui.css') ?: '1';
$__pr_ver = @filemtime(__DIR__ . '/assets/css/premium.css') ?: '1';
$__js_ver = @filemtime(__DIR__ . '/assets/js/interactions.js') ?: '1';
$__built_css = is_file(__DIR__ . '/assets/css/site.min.css') ? (string)@filemtime(__DIR__ . '/assets/css/site.min.css') : '';   // built by scripts/build_assets.php
$__hide_header_search = !empty($hide_header_search);
$__nav = site_nav_primary(); $__navMore = site_nav_more(); [$__ctaHref, $__ctaLabel] = site_nav_cta();
$__uniExperiences = [['/universe-graph.php', 'Graph'], ['/universe-heatmap.php', 'Heatmap'], ['/universe-lineage.php', 'Lineage'], ['/universe-arena.php', 'Battle Arena']];   // used only for the sub-navigation of the (unlinked) AI Universe pages themselves
$__curBase = basename($__script);
$__inUniverse = $__curBase === 'universe.php' || in_array('/' . $__curBase, array_column($__uniExperiences, 0), true);
$__cur = function (string $href) use ($__curBase, $__inUniverse): string {
  $p = '/' . $__curBase;
  if ($p === $href) return ' aria-current="page"';
  return ($href === '/universe.php' && $__inUniverse) ? ' aria-current="true"' : '';
};
?>
<!doctype html>
<html lang="<?= h(locale_split_path((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH))[0]) ?>" dir="<?= h(locale_dir(locale_split_path((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH))[0])) ?>" class="no-js"<?= analytics_enabled() ? ' data-events="1"' : '' ?><?= (isset($theme_lock) && $theme_lock === 'light') ? ' data-theme="light" data-theme-lock="light"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<script>(function(){var d=document.documentElement;d.className='js';if(d.getAttribute('data-theme-lock'))return;try{var t=localStorage.getItem('ah_theme');if(t!=='dark'&&t!=='light'){t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'}d.setAttribute('data-theme',t)}catch(e){}})();</script>
<title><?= h($title) ?></title>
<meta name="description" content="<?= h($meta_desc) ?>">
<meta name="robots" content="<?= h($__robots_meta) ?>">
<?php if ($robots !== 'noindex'): ?>
<link rel="canonical" href="<?= h($canonical) ?>">
<?php foreach (locale_hreflang(locale_split_path((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH))[1], AIHUB_SITE_URL) as $__hl => $__hu): ?>
<link rel="alternate" hreflang="<?= h($__hl) ?>" href="<?= h($__hu) ?>">
<?php endforeach; ?>
<?php endif; ?>
<?= $__verify ?><meta name="application-name" content="AIHubpedia">
<meta name="theme-color" content="#4f46e5">
<?php if ($__built_css): ?>
<link rel="preload" href="/assets/fonts/space-grotesk-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/inter-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/assets/css/site.min.css?v=<?= h($__built_css) ?>">
<?php else: ?>
<link rel="stylesheet" href="/assets/css/style.css?v=<?= h($__css_ver) ?>">
<link rel="stylesheet" href="/assets/css/ui.css?v=<?= h($__ui_ver) ?>">
<link rel="stylesheet" href="/assets/css/premium.css?v=<?= h($__pr_ver) ?>">
<link rel="stylesheet" href="/assets/css/premium2.css?v=<?= h(@filemtime(__DIR__ . "/assets/css/premium2.css") ?: 1) ?>">
<link rel="stylesheet" href="/assets/css/premium3.css?v=<?= h(@filemtime(__DIR__ . "/assets/css/premium3.css") ?: 1) ?>">
<?php endif; ?>
<link rel="icon" type="image/png" sizes="32x32" href="/assets/images/favicon-32.png">
<link rel="icon" type="image/png" sizes="48x48" href="/assets/images/favicon-48.png">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/images/favicon-180.png">
<link rel="manifest" href="/site.webmanifest" crossorigin="use-credentials">
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
<meta name="csrf-token" content="<?= htmlspecialchars(sec_csrf_token('interact'), ENT_QUOTES) ?>">
</head>
<body data-page="<?= h(analytics_page_type(seo_current_script())) ?>">
<?= ui_icon_sprite() ?>
<a class="skipLink" href="#main">Skip to content</a>
<header class="siteHeader" data-header>
  <div class="wrap hdr">
    <a class="brand" href="/" aria-label="AIHubpedia home">
      <img class="brandMark" src="/assets/images/logo-mark-44.webp" srcset="/assets/images/logo-mark-44.webp 1x, /assets/images/logo-mark-88.webp 2x" width="40" height="40" alt="" fetchpriority="high">
      <span class="brandWord" aria-hidden="true">Hubpedia</span>
    </a>

    <nav class="siteNav" id="siteNav" aria-label="Main navigation">
      <ul class="siteNav__list">
        <?php foreach ($__nav as [$href, $label]): ?><li><a class="siteNav__link" href="<?= h($href) ?>"<?= $__cur($href) ?>><?= h($label) ?></a></li><?php endforeach; ?>
        <li class="siteMore" data-more>
          <details class="siteMore__details">
            <summary class="siteNav__link siteMore__btn" aria-label="More pages">More <?= ui_icon('arrow', 'caret') ?></summary>
            <div class="siteMore__panel" id="moreMenu">
              <?php foreach ($__navMore as $__grp => $__links): ?>
              <div class="siteMore__group"><p class="siteMore__title"><?= h($__grp) ?></p>
                <?php foreach ($__links as [$href, $label, $__d, $__ic, $__cc]): ?><a href="<?= h($href) ?>"<?= $__cur($href) ?>><span class="mm__ico" style="--c1:<?= h($__cc[0]) ?>;--c2:<?= h($__cc[1]) ?>" aria-hidden="true"><?= ui_icon($__ic) ?></span><span><strong><?= h($label) ?></strong><small><?= h($__d) ?></small></span></a><?php endforeach; ?>
              </div>
              <?php endforeach; ?>
              <div class="siteMore__foot"><p><strong>Need something built?</strong> API integrations, workflow automation and custom AI.</p><a class="btn" href="<?= h($__ctaHref) ?>" data-cta="mega_project"><?= h($__ctaLabel) ?></a></div>
            </div>
          </details>
        </li>
      </ul>
      <a class="btn siteNav__cta" href="<?= h($__ctaHref) ?>" data-cta="header_project"><?= h($__ctaLabel) ?></a>
    </nav>

    <div class="hdrActions">
      <?php if (!$__hide_header_search): ?><a class="iconBtn hdrSearchBtn" href="/search.php" data-search-toggle aria-label="Search AI tools" aria-expanded="false" aria-controls="hdrSearchBar" title="Search (press /)"><?= ui_icon('search') ?><kbd aria-hidden="true">/</kbd></a><?php endif; ?>
      <a class="iconBtn favLink js-only" href="/favorites.php" aria-label="Saved tools"><?= ui_icon('heart') ?><span class="favCount" data-fav-count hidden>0</span></a>
      <button class="iconBtn js-only" id="themeToggle" type="button" aria-label="Switch color theme" aria-pressed="false"><?= ui_icon('moon', 'ic-moon') ?><?= ui_icon('sun', 'ic-sun') ?></button>
      <a class="btn sm hdrCta" href="<?= h($__ctaHref) ?>" data-cta="header_project"><?= h($__ctaLabel) ?></a>
      <button class="iconBtn navToggle js-only" type="button" aria-expanded="false" aria-controls="siteNav" aria-label="Open menu"><?= ui_icon('menu', 'ic-open') ?><?= ui_icon('x', 'ic-close') ?></button>
    </div>
  </div>
  <?php if (!$__hide_header_search): ?><div class="hdrSearchBar" id="hdrSearchBar" data-search-bar hidden><div class="wrap"><?= render_search_box('hdrSearch', ['source' => 'header', 'placeholder' => 'Search AI tools, categories and guides']) ?></div></div><?php endif; ?>
</header>
<main class="<?= h($main_class ?? 'wrap') ?>" id="main">
<?php if ($__inUniverse): ?>
<nav class="uniSubnav" aria-label="AI Universe sections">
  <a class="uniSubnav__home" href="/universe.php"<?= $__cur('/universe.php') ?>>AI Universe</a>
  <ul>
    <?php foreach ($__uniExperiences as [$href, $label]): ?><li><a href="<?= h($href) ?>"<?= $__cur($href) ?>><?= h($label) ?></a></li>
    <?php endforeach; ?>
  </ul>
</nav>
<?php endif; ?>
