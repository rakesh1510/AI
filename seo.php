<?php
/**
 * Central technical-SEO helpers: canonical URLs, titles, descriptions,
 * robots directives, JSON-LD and per-page defaults.
 *
 * Everything here is pure PHP with no database access, so it is safe to call
 * from any page (including error pages) without extra queries.
 */

if (!defined('AIHUB_SITE_URL')) {
  $envBase = getenv('AIHUB_BASE_URL');
  define('AIHUB_SITE_URL', rtrim($envBase !== false && $envBase !== '' ? $envBase : 'https://aihubpedia.com', '/'));
}
const AIHUB_SITE_NAME = 'AIHubpedia';
const AIHUB_TITLE_MAX = 65;
const AIHUB_DESC_MAX = 158;

function site_url(string $path = '/'): string {
  if ($path === '' || $path[0] !== '/') $path = '/' . $path;
  return AIHUB_SITE_URL . $path;
}

/** Collapse whitespace and strip tags so meta text is clean. */
function seo_clean_text($text): string {
  $text = html_entity_decode(strip_tags((string)$text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
  return trim(preg_replace('/\s+/u', ' ', $text));
}

/** Truncate at a word boundary and add an ellipsis when needed. */
function seo_truncate(string $text, int $max): string {
  $text = seo_clean_text($text);
  if (mb_strlen($text) <= $max) return $text;
  $cut = mb_substr($text, 0, $max - 1);
  $space = mb_strrpos($cut, ' ');
  if ($space !== false && $space > $max * 0.6) $cut = mb_substr($cut, 0, $space);
  return rtrim($cut, " ,;:.-–—") . '…';
}

/** Ensure a sentence ends with terminal punctuation. */
function seo_sentence(string $text): string {
  $text = seo_clean_text($text);
  if ($text === '') return '';
  return preg_match('/[.!?…]$/u', $text) ? $text : $text . '.';
}

/** Correct "a"/"an" for a following word. */
function seo_article(string $word): string {
  return preg_match('/^[aeiou]/i', trim($word)) ? 'an' : 'a';
}

/** Append the brand once and keep the title in a sensible length. */
function seo_title(string $title): string {
  $title = seo_clean_text($title);
  if ($title === '') return AIHUB_SITE_NAME . ' — AI Tools Directory';
  if (stripos($title, AIHUB_SITE_NAME) !== false) return $title;
  // Keep within ~70 characters (Google truncates by pixel width): drop the brand suffix before truncating.
  $suffix = ' | ' . AIHUB_SITE_NAME;
  if (mb_strlen($title . $suffix) <= 70) return $title . $suffix;
  if (mb_strlen($title) <= 70) return $title;
  return seo_truncate($title, 70);
}

function seo_description(string $desc): string {
  return seo_truncate($desc, AIHUB_DESC_MAX);
}

/** Simple, stable slug for anchors and filenames. */
function seo_slugify(string $s): string {
  $s = strtolower(trim($s));
  $s = preg_replace('/[^a-z0-9]+/', '-', $s);
  return trim($s, '-');
}

/** Root-relative paths for on-page links (work on any host: production, staging, local). */
function tool_path(string $id): string { return '/tool.php?id=' . rawurlencode($id); }
function category_path(string $name, int $page = 1, string $sub = ''): string {
  $q = '/category.php?name=' . rawurlencode($name);
  if ($sub !== '') $q .= '&sub=' . rawurlencode(subcategory_slug($sub));
  return $q . ($page > 1 ? '&page=' . $page : '');
}
function post_path(string $slug): string { return '/post.php?slug=' . rawurlencode($slug); }
function use_case_path(string $slug): string { return '/ai-use-case.php?slug=' . rawurlencode($slug); }
/** Canonical comparison path: ids sorted so A-vs-B and B-vs-A share one URL. */
function compare_path(string $a, string $b): string {
  $pair = [$a, $b];
  sort($pair, SORT_STRING);
  return '/compare.php?a=' . rawurlencode($pair[0]) . '&b=' . rawurlencode($pair[1]);
}
/** Absolute URLs (canonical, JSON-LD, sitemap). */
function tool_page_url(string $id): string { return site_url(tool_path($id)); }
function category_page_url(string $name, int $page = 1, string $sub = ''): string { return site_url(category_path($name, $page, $sub)); }
function post_page_url(string $slug): string { return site_url(post_path($slug)); }
function use_case_page_url(string $slug): string { return site_url(use_case_path($slug)); }
function compare_page_url(string $a, string $b): string { return site_url(compare_path($a, $b)); }
/** Convert an absolute site URL to a root-relative one for links. */
function rel_url(string $url): string {
  return strpos($url, AIHUB_SITE_URL . '/') === 0 ? substr($url, strlen(AIHUB_SITE_URL)) : $url;
}

/**
 * Per-page defaults for pages that do not set their own metadata.
 * robots: 'index' (default) or 'noindex' for utility, demo and placeholder pages.
 */
function seo_page_registry(): array {
  return [
    'index.php' => ['title' => 'AIHubpedia — AI Tools Directory: Discover & Compare AI Tools', 'desc' => 'Browse and compare AI tools for writing, coding, design, audio, video and automation. See pricing, categories, alternatives and community votes on AIHubpedia.'],
    'categories.php' => ['title' => 'AI Tool Categories — Browse AI Tools by Use', 'desc' => 'Browse AI tools by category: writing, coding, image, video, audio, automation, chatbots and more. See how many tools each category lists and jump straight in.'],
    'top.php' => ['title' => 'Top AI Tools — Ranked by Clicks & Ratings', 'desc' => 'The most-used AI tools on AIHubpedia, ranked by outbound clicks and visitor ratings. Compare categories, pricing and alternatives.'],
    'leaderboard.php' => ['title' => 'AI Tools Leaderboard — Community Popularity Ranking', 'desc' => 'Top 20 AI tools ranked by AIHubpedia visitor activity: outbound clicks, likes and dislikes. See how the popularity score is calculated.'],
    'compare.php' => ['title' => 'Compare AI Tools Side by Side', 'desc' => 'Pick any two AI tools and compare category, pricing, listed features, use cases, platforms and integrations side by side.'],
    'comparisons.php' => ['title' => 'AI Tool Comparisons — Popular Head-to-Head Matchups', 'desc' => 'Popular AI tool comparisons on AIHubpedia: chatbots, writing assistants, coding tools and more, compared side by side.'],
    'blog.php' => ['title' => 'AI Tools Blog — Guides & Shortlists', 'desc' => 'Guides, shortlists and updates to help you choose the right AI tools for writing, coding, design and productivity.'],
    'about.php' => ['title' => 'About AIHubpedia — Independent AI Tools Directory', 'desc' => 'Learn what AIHubpedia is, how AI tools are listed and organised, and how rankings and comparisons work.'],
    'contact.php' => ['title' => 'Contact AIHubpedia', 'desc' => 'Get in touch with AIHubpedia about listings, corrections, partnerships or advertising.'],
    'submit.php' => ['title' => 'Submit Your AI Tool', 'desc' => 'Submit an AI tool to the AIHubpedia directory. Free listings are reviewed manually; optional paid placements are available.'],
    'advertise.php' => ['title' => 'Advertise on AIHubpedia — Featured & Spotlight Listings', 'desc' => 'Pricing and options for featured listings and homepage spotlight placements on AIHubpedia.'],
    'privacy.php' => ['title' => 'Privacy Policy', 'desc' => 'How AIHubpedia collects, uses and protects data, including cookies, analytics and contact form submissions.'],
    'terms.php' => ['title' => 'Terms of Service', 'desc' => 'The terms that govern use of the AIHubpedia AI tools directory, listings, votes and comparisons.'],
    'impressum.php' => ['title' => 'Impressum (Legal Notice)', 'desc' => 'Legal notice and provider identification for AIHubpedia.'],
    'disclaimer.php' => ['title' => 'Disclaimer', 'desc' => 'Important information about the accuracy of AI tool listings, pricing and third-party links on AIHubpedia.'],
    'ai-use-case-explorer.php' => ['title' => 'AI Use Case Explorer — Find AI Tools by Goal', 'desc' => 'Start from what you want to do — write, design, automate, build websites — and find matching AI tools on AIHubpedia.'],
    'filters.php' => ['title' => 'Filter AI Tools by Category & Price', 'desc' => 'Filter the AIHubpedia AI tools directory by keyword, category and pricing model.'],
    'community.php' => ['title' => 'Community — Most Discussed AI Tools', 'desc' => 'See which AI tools the AIHubpedia community votes on and discusses the most.'],
    'gallery.php' => ['title' => 'Gallery', 'desc' => 'Image gallery of visual assets used across the AIHubpedia website.', 'robots' => 'noindex'],
    // Utility, demo and placeholder pages: kept reachable, excluded from the index.
    'search.php' => ['title' => 'Search AI Tools', 'desc' => 'Search the AIHubpedia directory by tool name, category, use case or goal, with reviewed profiles ranked first.', 'robots' => 'noindex'],
    'favorites.php' => ['title' => 'Your Saved AI Tools', 'desc' => 'AI tools you saved in this browser. Saved tools are stored on your device only.', 'robots' => 'noindex'],
    'inquiry.php' => ['title' => 'Request Received', 'desc' => 'Confirmation that your request to AIHubpedia was received. We reply by email if it is a fit.', 'robots' => 'noindex'],
    'subscribe.php' => ['title' => 'Subscribe to AIHubpedia Updates', 'desc' => 'Confirm your email subscription to AIHubpedia updates.', 'robots' => 'noindex'],
    'newsletter-confirm.php' => ['title' => 'Confirm Subscription', 'desc' => 'Confirm your AIHubpedia email subscription.', 'robots' => 'noindex'],
    'unsubscribe.php' => ['title' => 'Unsubscribe', 'desc' => 'Unsubscribe from AIHubpedia emails.', 'robots' => 'noindex'],
    'business.php' => ['title' => 'AI Consulting & Custom AI Agents by TrustNova Tech', 'desc' => 'Request an AI consultation or a custom AI agent or software project from TrustNova Tech. Tell us what you want to build.'],
    'disclosure.php' => ['title' => 'Advertising & Affiliate Disclosure', 'desc' => 'How AIHubpedia labels sponsored placements and affiliate links, and why paid placements never change a tool profile.'],
    '404.php' => ['title' => 'Page Not Found', 'desc' => 'The page you requested could not be found.', 'robots' => 'noindex'],
    'admin.php' => ['title' => 'Admin', 'desc' => 'AIHubpedia administration area for managing listings and settings.', 'robots' => 'noindex'],
    'login.php' => ['title' => 'Demo Login', 'desc' => 'Demo login for testing AIHubpedia bookmarks and saved AI tools.', 'robots' => 'noindex'],
    'bookmarks.php' => ['title' => 'My Bookmarks', 'desc' => 'Your saved AI tools on AIHubpedia, stored for this demo account.', 'robots' => 'noindex'],
    'affiliate-dashboard.php' => ['title' => 'Affiliate reporting', 'desc' => 'Affiliate link reporting for AIHubpedia (no revenue figures are shown).', 'robots' => 'noindex'],
    'analytics-dashboard.php' => ['title' => 'Analytics Dashboard (Demo)', 'desc' => 'Preview of the AIHubpedia analytics dashboard with demo figures.', 'robots' => 'noindex'],
    'dark-mode-demo.php' => ['title' => 'Dark Mode Demo', 'desc' => 'Preview of the experimental AIHubpedia dark mode theme.', 'robots' => 'noindex'],
    'multilingual-demo.php' => ['title' => 'Multilingual Demo', 'desc' => 'Preview of experimental multilingual support on AIHubpedia.', 'robots' => 'noindex'],
    'new-features-hub.php' => ['title' => 'New Features Hub', 'desc' => 'Overview of experimental and preview features on AIHubpedia.', 'robots' => 'noindex'],
    'generate_compare_pages.php' => ['title' => 'Generate Comparison Pages', 'desc' => 'Internal tool for generating AIHubpedia comparison pages.', 'robots' => 'noindex'],
    'ai-benchmarks.php' => ['title' => 'AI Tool Benchmarks (Preview)', 'desc' => 'Preview page with illustrative, unmeasured AI tool comparisons.', 'robots' => 'noindex'],
    'ai-ecosystem-map.php' => ['title' => 'AI Ecosystem Map (Preview)', 'desc' => 'Preview page with an overview of the AI tools ecosystem.', 'robots' => 'noindex'],
    'ai-innovation-hub.php' => ['title' => 'AI Innovation Hub (Preview)', 'desc' => 'Preview page for the experimental AIHubpedia AI innovation hub.', 'robots' => 'noindex'],
    'ai-learning-paths.php' => ['title' => 'AI Learning Paths (Preview)', 'desc' => 'Preview page with suggested AI learning paths for beginners.', 'robots' => 'noindex'],
    'ai-playground.php' => ['title' => 'AI Playground (Preview)', 'desc' => 'Preview page for the experimental AIHubpedia AI playground.', 'robots' => 'noindex'],
    'ai-problem-solver.php' => ['title' => 'AI Problem Solver (Preview)', 'desc' => 'Preview page for the experimental AIHubpedia AI problem solver.', 'robots' => 'noindex'],
    'ai-project-templates.php' => ['title' => 'AI Project Templates (Preview)', 'desc' => 'Preview page with example AI project templates and starters.', 'robots' => 'noindex'],
    'ai-recommendation-quiz.php' => ['title' => 'AI Tool Recommendation Quiz (Preview)', 'desc' => 'Preview of a quiz that suggests AI tools based on your needs.', 'robots' => 'noindex'],
    'ai-trend-radar.php' => ['title' => 'AI Trend Radar (Preview)', 'desc' => 'Preview page with editorial examples of AI trends (not measured data).', 'robots' => 'noindex'],
    'ai-usecase-explorer.php' => ['title' => 'AI Use Case Explorer (Preview)', 'desc' => 'Preview page. See the full AI Use Case Explorer.', 'robots' => 'noindex', 'canonical' => '/ai-use-case-explorer.php'],
    'ai-workflow-generator.php' => ['title' => 'AI Workflow Generator (Preview)', 'desc' => 'Preview page for the experimental AI workflow generator.', 'robots' => 'noindex'],
  ];
}

/** Query parameters that define a distinct, indexable URL per script. */
function seo_canonical_params(): array {
  return [
    'tool.php' => ['id'],
    'category.php' => ['name', 'sub', 'page'],
    'alternatives.php' => ['tool'],
    'guide.php' => ['slug'],
    'post.php' => ['slug'],
    'ai-use-case.php' => ['slug'],
    'compare.php' => ['a', 'b'],
  ];
}

function seo_current_script(): string {
  $script = $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '/index.php');
  return ltrim((string)$script, '/');
}

/**
 * Build the canonical URL for the current request: fixed host + https,
 * clean path, and only the whitelisted query parameters for this script.
 */
function seo_default_canonical(): string {
  $script = seo_current_script();
  $base = basename($script);
  if ($script === 'index.php' || $script === '') return site_url('/');
  $allowed = seo_canonical_params()[$script] ?? [];
  $params = [];
  foreach ($allowed as $key) {
    $val = isset($_GET[$key]) ? trim((string)$_GET[$key]) : '';
    if ($val === '' || ($key === 'page' && (int)$val <= 1)) continue;
    $params[$key] = $val;
  }
  if ($base === 'compare.php' && isset($params['a'], $params['b'])) {
    return compare_page_url($params['a'], $params['b']);
  }
  return site_url('/' . $script) . ($params ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '');
}

/** True when the URL carries parameters that do not create unique content (search, filters, tracking). */
function seo_has_noncanonical_params(): bool {
  $allowed = seo_canonical_params()[seo_current_script()] ?? [];
  foreach (array_keys($_GET) as $key) {
    if (!in_array($key, $allowed, true)) return true;
  }
  return false;
}

/** Render one <script type="application/ld+json"> containing an @graph. */
function seo_jsonld(array $nodes): string {
  $nodes = array_values(array_filter($nodes));
  if (!$nodes) return '';
  $json = json_encode(['@context' => 'https://schema.org', '@graph' => $nodes],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
  return '<script type="application/ld+json">' . $json . '</script>';
}

function seo_org_node(): array {
  return [
    '@type' => 'Organization',
    '@id' => site_url('/#organization'),
    'name' => AIHUB_SITE_NAME,
    'url' => site_url('/'),
    'logo' => ['@type' => 'ImageObject', 'url' => site_url('/logo.png'), 'width' => 853, 'height' => 201],
    'email' => 'contact@aihubpedia.com',
  ];
}

function seo_website_node(): array {
  return [
    '@type' => 'WebSite',
    '@id' => site_url('/#website'),
    'name' => AIHUB_SITE_NAME,
    'url' => site_url('/'),
    'inLanguage' => 'en',
    'publisher' => ['@id' => site_url('/#organization')],
    'potentialAction' => [
      '@type' => 'SearchAction',
      'target' => ['@type' => 'EntryPoint', 'urlTemplate' => site_url('/search.php?q={search_term_string}')],
      'query-input' => 'required name=search_term_string',
    ],
  ];
}

/** BreadcrumbList from [[name, absoluteUrl|null], ...]. Last item may omit the URL. */
function seo_breadcrumb_node(array $items): array {
  $list = [];
  foreach (array_values($items) as $i => $item) {
    $entry = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => seo_clean_text($item[0])];
    if (!empty($item[1])) $entry['item'] = $item[1];
    $list[] = $entry;
  }
  return ['@type' => 'BreadcrumbList', 'itemListElement' => $list];
}

/** Visible breadcrumb navigation that mirrors the JSON-LD. */
function render_breadcrumbs(array $items): string {
  $out = '<nav class="breadcrumbs" aria-label="Breadcrumb"><ol>';
  $last = count($items) - 1;
  foreach (array_values($items) as $i => $item) {
    $name = htmlspecialchars((string)$item[0], ENT_QUOTES);
    if ($i < $last && !empty($item[1])) {
      $out .= '<li><a href="' . htmlspecialchars(rel_url((string)$item[1]), ENT_QUOTES) . '">' . $name . '</a></li>';
    } else {
      $out .= '<li aria-current="page">' . $name . '</li>';
    }
  }
  return $out . '</ol></nav>';
}

/** ItemList of tools (for category/listing pages). */
function seo_tool_itemlist_node(array $tools, string $name, int $offset = 0): array {
  $items = [];
  foreach (array_values($tools) as $i => $t) {
    $items[] = ['@type' => 'ListItem', 'position' => $offset + $i + 1, 'url' => tool_page_url((string)($t['id'] ?? '')), 'name' => (string)($t['name'] ?? '')];
  }
  return ['@type' => 'ItemList', 'name' => $name, 'numberOfItems' => count($items), 'itemListElement' => $items];
}

// ----------------------------------------------------------------------
// Tool-specific SEO helpers
// ----------------------------------------------------------------------

/** Placeholder feature/pro/con values that were bulk-generated, not product-specific. */
function tool_generic_profile_values(): array {
  return [
    'Fast setup and onboarding', 'AI-assisted workflow', 'Exports/sharing (varies by plan)',
    'Saves time', 'Easy to start', 'Good for iterative work',
    'Quality depends on input', 'Pricing/features vary by plan',
    'Create drafts faster', 'Automate repetitive tasks', 'Improve quality and consistency',
  ];
}

/** True when every listed item for a field is a generic placeholder (or the field is empty). */
function tool_field_is_generic(array $tool, string $field): bool {
  $items = array_values(array_filter(array_map('trim', (array)($tool[$field] ?? []))));
  if (!$items) return true;
  return count(array_diff($items, tool_generic_profile_values())) === 0;
}

function tool_has_generic_profile(array $tool): bool {
  if (!empty($tool['curated'])) return false;
  return tool_field_is_generic($tool, 'features') && tool_field_is_generic($tool, 'pros');
}

/** Detect the old bulk-generated "Name — Category AI Tool" titles/descriptions. */
function tool_seo_title_is_boilerplate(array $t): bool {
  $st = trim((string)($t['seo_title'] ?? ''));
  return $st === '' || preg_match('/^' . preg_quote((string)($t['name'] ?? ''), '/') . ' — [\w &]+ AI Tool$/u', $st);
}
function tool_seo_desc_is_boilerplate(array $t): bool {
  $sd = trim((string)($t['seo_description'] ?? ''));
  return $sd === '' || strpos($sd, 'See features, pricing, tags, and alternatives') !== false;
}

function tool_seo_title(array $t): string {
  $name = (string)($t['name'] ?? 'AI tool');
  if (!tool_seo_title_is_boilerplate($t)) return seo_title((string)$t['seo_title']);
  return seo_title($name . ': Features, Pricing & Alternatives');
}

function tool_seo_description(array $t): string {
  if (!tool_seo_desc_is_boilerplate($t)) return seo_description((string)$t['seo_description']);
  $name = (string)($t['name'] ?? 'This tool');
  $cat = (string)($t['category'] ?? 'AI');
  $short = seo_sentence((string)($t['short'] ?? ''));
  $pricing = trim((string)($t['pricing'] ?? ''));
  $lead = $name . ' is ' . seo_article($cat) . ' ' . strtolower($cat) . ' AI tool';
  $desc = $short !== '' ? $lead . ': ' . lcfirst(rtrim($short, '.')) . '.' : $lead . '.';
  if ($pricing !== '') $desc .= ' Pricing: ' . $pricing . '.';
  $desc .= ' Compare alternatives on AIHubpedia.';
  return seo_description($desc);
}

/** Map a directory category to a schema.org applicationCategory. */
function tool_schema_category(string $cat): string {
  $map = [
    'Coding' => 'DeveloperApplication', 'Design' => 'DesignApplication', 'Image' => 'DesignApplication',
    'Video' => 'MultimediaApplication', 'Audio' => 'MultimediaApplication', 'Chatbots' => 'CommunicationApplication',
    'Writing' => 'BusinessApplication', 'SEO' => 'BusinessApplication', 'Automation' => 'BusinessApplication',
    'Productivity' => 'BusinessApplication', 'Research' => 'EducationalApplication',
  ];
  return $map[$cat] ?? 'BusinessApplication';
}

/**
 * SoftwareApplication node. Offers are only emitted when the listing says a free tier exists,
 * and aggregateRating only when real visitor ratings exist — nothing is invented.
 */
function seo_tool_node(array $t, string $canonical, array $rating = [0, 0]): array {
  $node = [
    '@type' => 'SoftwareApplication',
    '@id' => $canonical . '#software',
    'name' => (string)($t['name'] ?? ''),
    'applicationCategory' => tool_schema_category((string)($t['category'] ?? '')),
    'description' => seo_truncate((string)($t['description'] ?: ($t['short'] ?? '')), 300),
    'mainEntityOfPage' => $canonical,
  ];
  $site = trim((string)($t['website'] ?? ''));
  if ($site !== '' && preg_match('#^https?://#i', $site)) {
    $node['url'] = $site;
    $node['operatingSystem'] = 'Web';
  }
  if (!empty($t['curated'])) {
    $cp = $t['curated'];
    if (!empty($cp['company'])) $node['creator'] = ['@type' => 'Organization', 'name' => (string)$cp['company']];
    if (!empty($cp['review']['reviewed_on'])) $node['dateModified'] = (string)$cp['review']['reviewed_on'];
  }
  $logo = trim((string)($t['logo'] ?? ''));
  if ($logo !== '' && preg_match('#^https?://#i', $logo)) $node['image'] = $logo;
  $pricing = strtolower((string)($t['pricing'] ?? '') . ' ' . (string)($t['pricing_type'] ?? ''));
  if (preg_match('/\bfree\b|freemium/', $pricing) && strpos($pricing, 'trial') === false) {
    $node['offers'] = ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD', 'description' => 'Free plan or free tier available (' . seo_clean_text((string)($t['pricing'] ?? '')) . ')'];
  }
  [$avg, $cnt] = $rating;
  if ((int)$cnt > 0 && $avg > 0) {
    $node['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => round((float)$avg, 1), 'ratingCount' => (int)$cnt, 'bestRating' => 5, 'worstRating' => 1];
  }
  return $node;
}

/** Short editorial descriptions for each category (used on category pages). */
function category_intro(string $cat): string {
  if (function_exists('taxonomy_category_description') && ($d = taxonomy_category_description($cat)) !== '') return $d;
  $map = [
    'Audio' => 'AI audio tools for text-to-speech, voice cloning, transcription, music generation and podcast editing.',
    'Automation' => 'AI automation tools and assistants that connect apps, handle repetitive tasks, summarise information and run workflows for you.',
    'Chatbots' => 'AI chatbots and assistants for answering questions, drafting text, brainstorming and coding help.',
    'Coding' => 'AI coding tools for code completion, code review, debugging, documentation and building apps faster.',
    'Design' => 'AI design tools for layouts, graphics, branding, presentations and UI mock-ups.',
    'Image' => 'AI image tools for generating, editing, upscaling and restyling pictures from text prompts or photos.',
    'Productivity' => 'AI productivity tools for notes, meetings, scheduling, research and everyday knowledge work.',
    'Research' => 'AI research tools for finding sources, reading papers and summarising information.',
    'SEO' => 'AI SEO tools for keyword research, content optimisation, briefs and on-page audits.',
    'Video' => 'AI video tools for generating clips, editing footage, avatars, subtitles and short-form content.',
    'Writing' => 'AI writing tools for blog posts, marketing copy, emails, rewriting and grammar checks.',
  ];
  return $map[$cat] ?? ('AI tools in the ' . $cat . ' category.');
}

/** Hand-picked, well-known head-to-head pairs used for internal links to comparison pages. */
function popular_comparison_pairs(): array {
  return [
    ['chatgpt', 'claude'], ['chatgpt', 'gemini'], ['claude', 'gemini'], ['chatgpt', 'perplexity'],
    ['chatgpt', 'copilot'], ['gemini', 'perplexity'], ['chatgpt', 'deepseek'], ['claude', 'perplexity'],
    ['grammarly', 'quillbot'], ['canva-ai', 'midjourney'], ['github-copilot', 'cursor'], ['notebooklm', 'perplexity'],
  ];
}

/** Keep only pairs whose tools exist in the current catalogue. */
function available_comparison_pairs(array $tools, int $limit = 12): array {
  $byId = [];
  foreach ($tools as $t) $byId[(string)($t['id'] ?? '')] = $t;
  $pairs = [];
  foreach (popular_comparison_pairs() as [$a, $b]) {
    if (isset($byId[$a], $byId[$b])) $pairs[] = [$byId[$a], $byId[$b]];
  }
  // Top up with same-category pairs from non-generic profiles so the list is never empty.
  if (count($pairs) < $limit) {
    $byCat = [];
    foreach ($tools as $t) {
      if (tool_has_generic_profile($t)) continue;
      $byCat[(string)($t['category'] ?? '')][] = $t;
    }
    foreach ($byCat as $list) {
      for ($i = 0; $i + 1 < count($list) && count($pairs) < $limit; $i += 2) {
        $key = [$list[$i]['id'], $list[$i + 1]['id']];
        $dupe = false;
        foreach ($pairs as $p) {
          if (in_array($p[0]['id'], $key, true) && in_array($p[1]['id'], $key, true)) { $dupe = true; break; }
        }
        if (!$dupe) $pairs[] = [$list[$i], $list[$i + 1]];
      }
    }
  }
  return array_slice($pairs, 0, $limit);
}

/** "1 tool" / "5 tools". */
function seo_plural(int $n, string $word, ?string $plural = null): string {
  return $n . ' ' . ($n === 1 ? $word : ($plural ?? $word . 's'));
}
