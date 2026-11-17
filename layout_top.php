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
if ((is_int($__status) && $__status >= 400) || seo_has_noncanonical_params()) $robots = 'noindex';
$__robots_meta = $robots === 'index' ? 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1'
  : ($robots === 'noindex' ? 'noindex, follow' : $robots);
if (!headers_sent() && strpos($__robots_meta, 'noindex') !== false) header('X-Robots-Tag: noindex, follow');

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
$__js_ver = @filemtime(__DIR__ . '/assets/js/interactions.js') ?: '1';
$__built_css = is_file(__DIR__ . '/assets/css/site.min.css') ? (string)@filemtime(__DIR__ . '/assets/css/site.min.css') : '';   // built by scripts/build_assets.php
$__hide_header_search = !empty($hide_header_search);
$__nav = [['/categories.php', 'Categories'], ['/guides.php', 'Guides'], ['/compare.php', 'Compare'], ['/top.php', 'Top Tools'], ['/ai-use-case-explorer.php', 'Use Cases']];
$__navMore = [['/solutions.php', 'Find by problem'], ['/recommend.php', 'Recommendation wizard'], ['/leaderboard.php', 'Leaderboard'], ['/submit.php', 'Submit a tool'], ['/contact.php', 'Contact'], ['/comparisons.php', 'Comparisons'], ['/filters.php', 'Filters'], ['/community.php', 'Community'], ['/blog.php', 'Blog'], ['/methodology.php', 'Methodology'], ['/about.php', 'About'], ['/disclosure.php', 'Advertising disclosure']];
?>
<!doctype html>
<html lang="en" class="no-js"<?= analytics_enabled() ? ' data-events="1"' : '' ?><?= (isset($theme_lock) && $theme_lock === 'light') ? ' data-theme="light" data-theme-lock="light"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<script>(function(){var d=document.documentElement;d.className='js';if(d.getAttribute('data-theme-lock'))return;try{var t=localStorage.getItem('ah_theme');if(t!=='dark'&&t!=='light'){t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'}d.setAttribute('data-theme',t)}catch(e){}})();</script>
<title><?= h($title) ?></title>
<meta name="description" content="<?= h($meta_desc) ?>">
<meta name="robots" content="<?= h($__robots_meta) ?>">
<?php if ($robots !== 'noindex'): ?>
<link rel="canonical" href="<?= h($canonical) ?>">
<?php endif; ?>
<?= $__verify ?><meta name="application-name" content="AIHubpedia">
<meta name="theme-color" content="#4f46e5">
<?php if ($__built_css): ?>
<link rel="stylesheet" href="/assets/css/site.min.css?v=<?= h($__built_css) ?>">
<?php else: ?>
<link rel="stylesheet" href="/assets/css/style.css?v=<?= h($__css_ver) ?>">
<link rel="stylesheet" href="/assets/css/ui.css?v=<?= h($__ui_ver) ?>">
<?php endif; ?>
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
<meta name="csrf-token" content="<?= htmlspecialchars(sec_csrf_token('interact'), ENT_QUOTES) ?>">
</head>
<body data-page="<?= h(analytics_page_type(seo_current_script())) ?>">
<?= ui_icon_sprite() ?>
<a class="skipLink" href="#main">Skip to content</a>
<header class="siteHeader">
  <div class="wrap hdr">
    <a class="brand" href="/" aria-label="AIHubpedia home">
      <img src="/assets/images/logo-44.webp" srcset="/assets/images/logo-44.webp 1x, /assets/images/logo-88.webp 2x" width="187" height="44" alt="AIHubpedia" class="brandLogo" fetchpriority="high">
    </a>

    <nav class="primaryNav" id="primaryNav" aria-label="Main navigation">
      <?php foreach ($__nav as [$href, $label]): ?><a href="<?= h($href) ?>"><?= h($label) ?></a><?php endforeach; ?>
      <div class="moreMenu">
        <button class="moreMenu__btn" type="button" aria-haspopup="true" aria-expanded="false">More <?= ui_icon('arrow', 'caret') ?></button>
        <div class="moreMenu__panel">
          <?php foreach ($__navMore as [$href, $label]): ?><a href="<?= h($href) ?>"><?= h($label) ?></a><?php endforeach; ?>
        </div>
      </div>
    </nav>

    <?php if (!$__hide_header_search): ?><div class="hdrSearch"><?= render_search_box('hdrSearch', ['source' => 'header', 'placeholder' => 'Search AI tools']) ?></div><?php endif; ?>

    <div class="hdrActions">
      <a class="iconBtn favLink js-only" href="/favorites.php" aria-label="Saved tools"><?= ui_icon('heart') ?><span class="favCount" data-fav-count hidden>0</span></a>
      <button class="iconBtn js-only" id="themeToggle" type="button" aria-label="Switch color theme" aria-pressed="false"><?= ui_icon('moon', 'ic-moon') ?><?= ui_icon('sun', 'ic-sun') ?></button>
      <a class="btn sm hdrCta" href="/business.php">For business</a>
      <button class="iconBtn navToggle js-only" type="button" aria-expanded="false" aria-controls="primaryNav" aria-label="Open menu"><?= ui_icon('menu', 'ic-open') ?><?= ui_icon('x', 'ic-close') ?></button>
    </div>
  </div>
</header>
<main class="<?= h($main_class ?? 'wrap') ?>" id="main">
