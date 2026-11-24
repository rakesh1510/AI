<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly (defence in depth if web-server rules are missing)
require_once __DIR__ . "/art.php";
/** UI building blocks shared by the redesigned pages. Pure rendering helpers: no database access. */

/** Icon paths (24x24, stroke-based). One inline sprite per page instead of an icon library. */
function ui_icon_paths(): array {
  return [
    'pen' => '<path d="M12 20h9M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4 12.5-12.5z"/>',
    'code' => '<path d="M16 18l6-6-6-6M8 6l-6 6 6 6"/>',
    'image' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>',
    'mic' => '<path d="M12 1a3 3 0 00-3 3v8a3 3 0 006 0V4a3 3 0 00-3-3zM19 10v2a7 7 0 01-14 0v-2M12 19v4M8 23h8"/>',
    'search' => '<circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>',
    'chat' => '<path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>',
    'bolt' => '<path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>',
    'briefcase' => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/>',
    'chart' => '<path d="M18 20V10M12 20V4M6 20v-6"/>',
    'play' => '<circle cx="12" cy="12" r="10"/><path d="M10 8l6 4-6 4V8z"/>',
    'heart' => '<path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/>',
    'moon' => '<path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/>',
    'sun' => '<circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>',
    'menu' => '<path d="M3 12h18M3 6h18M3 18h18"/>',
    'x' => '<path d="M18 6L6 18M6 6l12 12"/>',
    'check' => '<path d="M20 6L9 17l-5-5"/>',
    'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/>',
    'compare' => '<path d="M17 1l4 4-4 4M3 11V9a4 4 0 014-4h14M7 23l-4-4 4-4M21 13v2a4 4 0 01-4 4H3"/>',
    'layers' => '<path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>',
    'arrow' => '<path d="M5 12h14M12 5l7 7-7 7"/>',
    'external' => '<path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6M15 3h6v6M10 14L21 3"/>',
    'book' => '<path d="M4 19.5A2.5 2.5 0 016.5 17H20V2H6.5A2.5 2.5 0 004 4.5v15zM4 19.5A2.5 2.5 0 006.5 22H20v-5"/>',
    'plug' => '<path d="M9 2v6M15 2v6M6 8h12v3a6 6 0 01-12 0V8zM12 17v5"/>',
    'database' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
    'flow' => '<rect x="3" y="3" width="6" height="6" rx="1"/><rect x="15" y="15" width="6" height="6" rx="1"/><path d="M9 6h4a2 2 0 012 2v7M12 3l3 3-3 3"/>',
    'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
    'megaphone' => '<path d="M3 11v2a1 1 0 001 1h2l5 4V6L6 10H4a1 1 0 00-1 1zM15 8a5 5 0 010 8M18 5a9 9 0 010 14"/>',
    'sparkle' => '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9L12 3z"/>',
  ];
}

/** The hidden sprite, printed once per page. */
function ui_icon_sprite(): string {
  $out = '<svg xmlns="http://www.w3.org/2000/svg" width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false"><defs>';
  foreach (ui_icon_paths() as $id => $paths) $out .= '<symbol id="i-' . $id . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</symbol>';
  return $out . '</defs></svg>';
}

function ui_icon(string $name, string $class = ''): string {
  return '<svg class="ic' . ($class !== '' ? ' ' . htmlspecialchars($class, ENT_QUOTES) : '') . '" aria-hidden="true" focusable="false"><use href="#i-' . htmlspecialchars($name, ENT_QUOTES) . '"/></svg>';
}

function ui_category_icon(string $category): string {
  $map = ['Chatbots' => 'chat', 'Writing' => 'pen', 'Coding' => 'code', 'Image' => 'image', 'Design' => 'layers', 'Video' => 'play', 'Audio' => 'mic',
    'Productivity' => 'check', 'Research' => 'search', 'Marketing' => 'chart', 'Business' => 'briefcase', 'Lifestyle' => 'sparkle', 'Automation' => 'bolt'];
  return $map[$category] ?? 'layers';
}

// ----------------------------------------------------------------------
// Advertising and affiliate transparency
// ----------------------------------------------------------------------

/** True when the outbound link for this tool is an affiliate link (tool-level URL or a site-wide affiliate parameter). */
function is_affiliate_link(array $t): bool {
  if (trim((string)($t['affiliate'] ?? '')) !== '') return true;
  $site = site_cfg();
  return trim((string)($site['affiliate_default'] ?? '')) !== '' && trim((string)($t['website'] ?? '')) !== '';
}

/**
 * True when the tool's website URL goes through an affiliate or referral-tracking host or carries a referral parameter. AIHubpedia does not
 * know whether it earns anything from such a link, so it is disclosed as "tracking/referral" and marked rel="sponsored" to be safe.
 */
function link_has_tracking(array $t): bool {
  $url = trim((string)($t['website'] ?? ''));
  if ($url === '') return false;
  $host = preg_replace('/^www\./', '', strtolower((string)parse_url($url, PHP_URL_HOST)));
  if ($host !== '' && preg_match('/(^|\.)(8odi\.net|shareasale\.com|awin1\.com|impact\.com|pxf\.io|sjv\.io|ojrq\.net|partnerstack\.com|rewardful\.com|refersion\.com|linksynergy\.com|tkqlhce\.com|anrdoezrs\.net|dpbolvw\.net|jdoqocy\.com|kqzyfj\.com|clickbank\.net|tidd\.ly|geni\.us)$/i', $host)) return true;
  return (bool)preg_match('/[?&](aff|affiliate|refcode|fpr|irclickid|clickid|a_aid|tap_a|mbsy|rfsn)=/i', $url);
}

/** rel attribute for outbound links: sponsored placements, affiliate links and tracking/referral links are marked "sponsored" (Google's guidance). */
function outbound_rel(array $t, bool $sponsored = false): string {
  return 'nofollow noopener' . (($sponsored || is_affiliate_link($t) || link_has_tracking($t)) ? ' sponsored' : '');
}

// ----------------------------------------------------------------------
// Tool card
// ----------------------------------------------------------------------

/**
 * One tool card for every listing surface.
 * Options: sponsored (bool), compact (bool), placement (string, used for event attribution).
 */
function render_tool_card(array $t, array $opts = []): string {
  $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
  $id = (string)$t['id'];
  $sponsored = !empty($opts['sponsored']);
  $reviewed = !empty($t['curated']);
  $aff = is_affiliate_link($t);
  $placement = (string)($opts['placement'] ?? 'card');
  $lvl = in_array((int)($opts['heading'] ?? 3), [2, 3, 4], true) ? (int)($opts['heading'] ?? 3) : 3;   // heading level of the card title
  $free = in_array(strtolower((string)($t['pricing_type'] ?? '')), ['free', 'freemium'], true);
  $classes = 'tCard' . ($sponsored ? ' tCard--sponsored' : '') . ($reviewed ? ' tCard--reviewed' : '') . (!empty($opts['compact']) ? ' tCard--compact' : '');
  $cat = (string)($t['category'] ?? '');
  $o = '<article class="' . $classes . '" data-tool-id="' . $h($id) . '" data-name="' . $h($t['name']) . '" data-category="' . $h($cat) . '" data-pricing="' . $h(strtolower((string)($t['pricing_type'] ?? ''))) . '" data-reviewed="' . ($reviewed ? '1' : '0') . '"'
    . (!empty($opts['filter']) ? ' data-search="' . $h(search_normalize($t['name'] . ' ' . ($t['short'] ?? '') . ' ' . ($t['subcategory'] ?? ''))) . '"' : '') . '>';
  if ($sponsored) $o .= '<p class="adLabel"><span class="adTag">Sponsored</span> <a href="/disclosure.php">Why am I seeing this?</a></p>';
  $o .= '<div class="tCard__top">'
    . logo_tile($t, 'md')
    . '<div class="tCard__title"><h' . $lvl . '><a href="' . $h(tool_path($id)) . '">' . $h($t['name']) . '</a></h' . $lvl . '>'
    . '<p class="tCard__meta"><a href="' . $h(category_path($cat)) . '">' . $h($cat) . '</a>' . (!empty($t['subcategory']) ? ' · ' . $h($t['subcategory']) : '') . '</p></div>'
    . '<button type="button" class="iconBtn js-only" data-fav="' . $h($id) . '" aria-pressed="false" aria-label="Save ' . $h($t['name']) . ' to favorites">' . ui_icon('heart') . '</button>'
    . '</div>';
  $o .= '<p class="tCard__desc">' . $h($t['short'] ?? '') . '</p>';
  $o .= '<div class="tCard__badges">';
  if (!empty($t['pricing'])) $o .= '<span class="pill' . ($free ? ' pill--free' : '') . '">' . $h($t['pricing']) . '</span>';
  if ($reviewed) $o .= '<span class="pill pill--reviewed" title="Sources, dates and limitations are listed on the profile">' . ui_icon('shield') . ' Reviewed</span>';
  if ($reviewed && ($prov = ui_provenance($t, true)) !== '') $o .= '<span class="tCard__prov">' . $h($prov) . '</span>';   // provenance only when the profile data supports it
  if ($reviewed && !empty($opts['evidence'])) $o .= '<p class="tCard__evidence">' . render_evidence_badge($t) . '</p>';
  $o .= '</div>';
  if (!empty($opts['why']) && is_array($opts['why'])) $o .= '<p class="tCard__why"><strong>Why this result:</strong> ' . $h(implode(' · ', array_slice($opts['why'], 0, 4))) . '</p>';
  $o .= '<div class="tCard__actions">'
    . '<a class="btn sm" href="' . $h(tool_path($id)) . '">View profile</a>';
  if (tool_url($t) !== '#') {
    $o .= '<a class="btn sm secondary tCard__visit" target="_blank" rel="' . $h(outbound_rel($t, $sponsored)) . '" href="/redirect.php?id=' . $h(rawurlencode($id)) . '" data-outbound data-affiliate="' . ($aff ? '1' : '0') . '" data-sponsored="' . ($sponsored ? '1' : '0') . '" data-placement="' . $h($placement) . '">Visit site' . ui_icon('external') . '<span class="sr"> (new tab)</span></a>';
  }
  $o .= '<button type="button" class="btn sm ghost tCard__cmp js-only" data-compare="' . $h($id) . '" data-name="' . $h($t['name']) . '" aria-pressed="false">' . ui_icon('compare') . ' Compare</button>';
  $o .= '</div>';
  if (!empty($opts['votes'])) $o .= render_vote_buttons($id, (string)($opts['votes_context'] ?? 'index.php'));
  if ($aff) $o .= '<p class="tCard__note">Affiliate link: AIHubpedia may earn a commission. <a href="/disclosure.php">Details</a></p>';
  elseif (link_has_tracking($t)) $o .= '<p class="tCard__note">This website link uses a referral or tracking address. <a href="/disclosure.php">Details</a></p>';
  return $o . '</article>';
}

/** Small "Sponsored / affiliate" explainer used near paid placements. */
function render_disclosure_inline(): string {
  return '<p class="disclosureInline">Paid placements are labelled <span class="adTag">Sponsored</span> and never change a tool\'s facts or limitations. <a href="/disclosure.php">Read our advertising and affiliate disclosure</a>.</p>';
}

/** Search box with combobox semantics. JavaScript adds suggestions; without it the form submits to /search.php. */
function render_search_box(string $id, array $opts = []): string {
  $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
  $big = !empty($opts['big']);
  $source = (string)($opts['source'] ?? 'header');
  return '<form class="searchBox' . ($big ? ' searchBox--big' : '') . '" role="search" action="/search.php" method="get" data-search-form data-source="' . $h($source) . '">'
    . '<label class="sr" for="' . $h($id) . '">Search AI tools</label>'
    . '<span class="searchBox__icon">' . ui_icon('search') . '</span>'
    . '<input class="searchBox__input" id="' . $h($id) . '" type="search" name="q" value="' . $h($opts['value'] ?? '') . '" placeholder="' . $h($opts['placeholder'] ?? 'Search AI tools, categories and guides') . '" autocomplete="off" spellcheck="false" maxlength="80" enterkeyhint="search" role="combobox" aria-expanded="false" aria-controls="' . $h($id) . '-list" aria-autocomplete="list">'
    . '<button class="btn searchBox__btn" type="submit">Search</button>'
    . '<div class="suggest" hidden><ul id="' . $h($id) . '-list" role="listbox" aria-label="Search suggestions"></ul></div>'
    . '<p class="sr" aria-live="polite" data-search-status></p></form>';
}


// ----------------------------------------------------------------------
// Shared navigation data (header, mobile menu and footer read the same lists)
// ----------------------------------------------------------------------

/** Main header links. AI Universe is intentionally not listed (hidden from the public navigation; its routes still exist). */
function site_nav_primary(): array {
  return [['/categories.php', 'Discover'], ['/compare.php', 'Compare'], ['/guides.php', 'Guides'], ['/business.php', 'For Business'], ['/advertise.php', 'Advertise']];
}
/** The call to action next to the primary links: the existing project enquiry form. */
function site_nav_cta(): array { return ['/services.php#inquiry', 'Discuss Your Project']; }
/** Secondary navigation, grouped. Every group has at least one link; nothing here points to AI Universe. */
function site_nav_more(): array {
  $c = ['#3b82f6', '#4f46e5']; $g = ['#10b981', '#0ea5e9']; $v = ['#8b5cf6', '#ec4899']; $o = ['#f59e0b', '#ef4444']; $t = ['#06b6d4', '#2563eb'];
  return [
    'Find tools' => [
      ['/top.php', 'Top tools', 'Ranked picks by published rules', 'chart', $o], ['/solutions.php', 'Find by problem', 'Start from what you need to solve', 'search', $t],
      ['/recommend.php', 'Recommendation wizard', 'Answer a few questions, get a shortlist', 'sparkle', $v], ['/ai-use-case-explorer.php', 'Use cases', 'Browse tools by job to be done', 'layers', $g],
      ['/directory.php', 'All tools (A to Z)', 'The full catalogue, paginated', 'book', $c], ['/filters.php', 'Filters', 'Narrow by pricing, platform and more', 'flow', $t],
    ],
    'Compare and learn' => [
      ['/comparisons.php', 'Popular comparisons', 'Head-to-head pairings with logos and pricing', 'compare', $v], ['/leaderboard.php', 'Leaderboard', 'Top tools by the published formula', 'chart', $o],
      ['/blog.php', 'Blog', 'Articles and analysis', 'pen', $c], ['/methodology.php', 'How we review', 'Sources, dates and evidence levels', 'shield', $g], ['/about.php', 'About AIHubpedia', 'Who runs it and why', 'heart', $t],
    ],
    'Work with us' => [
      ['/contact.php', 'Contact', 'Write to us', 'mail', $c], ['/submit.php', 'Submit a tool', 'Free and priority listings', 'plug', $g],
      ['/services.php', 'AI development services', 'Integrations, automation, custom AI', 'code', $v], ['/partners.php', 'Partnerships', 'Referral and partner enquiries', 'megaphone', $o], ['/disclosure.php', 'Advertising disclosure', 'How paid placements are labelled', 'book', $t],
    ],
  ];
}

/** True once the newsletter is really sending. Until the site configuration says so (newsletter.launched), pages describe sign-up as launch updates. */
function newsletter_launched(): bool { $c = site_cfg(); return !empty($c['newsletter']['launched']); }

/** Short, honest provenance line for a card: only what the data supports (reviewed profile and its pricing date). Empty string when nothing is verifiable. */
function ui_provenance(array $t, bool $short = false): string {
  $cp = $t['curated'] ?? null; if (empty($cp)) return '';
  $date = (string)($cp['pricing']['as_of'] ?? '');
  if ($short) return $date !== '' ? 'Pricing as of ' . $date : '';   // cards already carry the "Reviewed" badge
  return $date !== '' ? 'Reviewed · pricing as of ' . $date : 'Reviewed profile';
}

/** Shared premium page hero (dark lit stage). Options: eyebrow, icon, title (plain text) or title_html, lead (html), crumbs, art (scene name), actions (html), facts (list of strings), solo (no art column), size ('sm'). */
function page_hero(array $o): string {
  $art = (string)($o['art'] ?? ''); $solo = !empty($o['solo']) || $art === '';
  $h = '<section class="pgHero stage' . ($solo ? ' pgHero--solo' : '') . (($o['size'] ?? '') === 'sm' ? ' pgHero--sm' : '') . '"><div class="wrap"><div>';
  if (!empty($o['crumbs'])) $h .= '<div class="pgHero__crumbs" style="padding:0 0 14px">' . render_breadcrumbs($o['crumbs']) . '</div>';
  if (!empty($o['eyebrow'])) $h .= '<span class="eyebrow">' . (!empty($o['icon']) ? ui_icon((string)$o['icon']) . ' ' : '') . htmlspecialchars((string)$o['eyebrow'], ENT_QUOTES) . '</span>';
  $title = isset($o['title_html']) ? (string)$o['title_html'] : htmlspecialchars((string)($o['title'] ?? ''), ENT_QUOTES);
  $h .= '<h1>' . $title . '</h1>';
  if (!empty($o['lead'])) $h .= '<p class="lead">' . $o['lead'] . '</p>';
  if (!empty($o['actions'])) $h .= '<div class="heroActions">' . $o['actions'] . '</div>';
  if (!empty($o['facts'])) { $h .= '<ul class="factRow">'; foreach ($o['facts'] as $f) $h .= '<li>' . ui_icon('check') . ' ' . htmlspecialchars((string)$f, ENT_QUOTES) . '</li>'; $h .= '</ul>'; }
  $h .= '</div>';
  if (!$solo) $h .= '<div class="pgHero__art" aria-hidden="true">' . art_scene($art, true) . '</div>';
  return $h . '</div></section>';
}
function page_body_open(string $cls = ''): string { return '<div class="wrap pgBody' . ($cls !== '' ? ' ' . htmlspecialchars($cls, ENT_QUOTES) : '') . '">'; }
function page_body_close(): string { return '</div>'; }

/** Premium empty / error / success panel with a 3D scene. Options: art (scene), eyebrow, title, text (html), actions (html), tone ('ok' for success). */
function state_box(array $o): string {
  $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
  return '<div class="stateBox' . (($o['tone'] ?? '') === 'ok' ? ' stateBox--ok' : '') . '" role="' . (($o['tone'] ?? '') === 'ok' ? 'status' : 'region') . '"><div>'
    . (!empty($o['eyebrow']) ? '<span class="eyebrow">' . $e($o['eyebrow']) . '</span>' : '')
    . '<h1>' . $e($o['title'] ?? '') . '</h1>' . (!empty($o['text']) ? '<p>' . $o['text'] . '</p>' : '')
    . (!empty($o['html']) ? '<div class="stateBox__html">' . $o['html'] . '</div>' : '')
    . (!empty($o['actions']) ? '<div class="heroActions">' . $o['actions'] . '</div>' : '') . '</div>'
    . art_scene((string)($o['art'] ?? 'lost')) . '</div>';
}

/** Long-form reading layout: adds anchors to the top-level section headings (h2, or h3 when there is no h2), builds a table of contents and wraps the text for readability. */
function doc_page(string $html, string $tocTitle = 'On this page'): string {
  $lvl = preg_match('/<h2[\s>]/i', $html) ? 'h2' : 'h3'; $toc = []; $used = [];
  $html = preg_replace_callback('#<' . $lvl . '([^>]*)>(.*?)</' . $lvl . '>#is', function ($m) use (&$toc, &$used, $lvl) {
    $text = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES));
    if (preg_match('/\sid="([^"]+)"/', $m[1], $im)) { $id = $im[1]; }
    else { $base = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-'); $id = $base !== '' ? $base : 'section'; $n = 2; while (isset($used[$id])) $id = $base . '-' . $n++; $m[1] .= ' id="' . $id . '"'; }
    $used[$id] = 1; $toc[] = [$id, $text];
    return '<' . $lvl . $m[1] . '>' . $m[2] . '</' . $lvl . '>';
  }, $html) ?? $html;
  $o = '<div class="docLayout' . ($lvl === 'h3' ? ' docLayout--legal' : '') . '">';
  if (count($toc) >= 3) {
    $o .= '<nav class="docToc" aria-label="' . htmlspecialchars($tocTitle, ENT_QUOTES) . '"><h2>' . htmlspecialchars($tocTitle, ENT_QUOTES) . '</h2><ol>';
    foreach ($toc as [$id, $t]) $o .= '<li><a href="#' . htmlspecialchars($id, ENT_QUOTES) . '">' . htmlspecialchars($t, ENT_QUOTES) . '</a></li>';
    $o .= '</ol></nav>';
  } else { $o = '<div class="docLayout docLayout--solo">'; }
  return $o . '<article class="docBody">' . $html . '</article></div>';
}
