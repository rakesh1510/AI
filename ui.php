<?php
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

/** rel attribute for outbound links: sponsored placements and affiliate links are marked "sponsored" (Google's guidance). */
function outbound_rel(array $t, bool $sponsored = false): string {
  return 'nofollow noopener' . (($sponsored || is_affiliate_link($t)) ? ' sponsored' : '');
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
    . '<img class="tCard__logo" src="' . $h(tool_logo($t)) . '" alt="' . $h($t['name']) . ' logo" width="44" height="44" loading="lazy" decoding="async">'
    . '<div class="tCard__title"><h' . $lvl . '><a href="' . $h(tool_path($id)) . '">' . $h($t['name']) . '</a></h' . $lvl . '>'
    . '<p class="tCard__meta"><a href="' . $h(category_path($cat)) . '">' . $h($cat) . '</a>' . (!empty($t['subcategory']) ? ' · ' . $h($t['subcategory']) : '') . '</p></div>'
    . '<button type="button" class="iconBtn js-only" data-fav="' . $h($id) . '" aria-pressed="false" aria-label="Save ' . $h($t['name']) . ' to favorites">' . ui_icon('heart') . '</button>'
    . '</div>';
  $o .= '<p class="tCard__desc">' . $h($t['short'] ?? '') . '</p>';
  $o .= '<div class="tCard__badges">';
  if (!empty($t['pricing'])) $o .= '<span class="pill' . ($free ? ' pill--free' : '') . '">' . $h($t['pricing']) . '</span>';
  if ($reviewed) $o .= '<span class="pill pill--reviewed" title="Sources, dates and limitations are listed on the profile">' . ui_icon('shield') . ' Reviewed</span>';
  $o .= '</div>';
  $o .= '<div class="tCard__actions">'
    . '<a class="btn sm" href="' . $h(tool_path($id)) . '">View profile</a>';
  if (tool_url($t) !== '#') {
    $o .= '<a class="btn sm secondary" target="_blank" rel="' . $h(outbound_rel($t, $sponsored)) . '" href="/redirect.php?id=' . $h(rawurlencode($id)) . '" data-outbound data-affiliate="' . ($aff ? '1' : '0') . '" data-sponsored="' . ($sponsored ? '1' : '0') . '" data-placement="' . $h($placement) . '">Visit site' . ui_icon('external') . '<span class="sr"> (new tab)</span></a>';
  }
  $o .= '<button type="button" class="btn sm ghost js-only" data-compare="' . $h($id) . '" data-name="' . $h($t['name']) . '" aria-pressed="false">' . ui_icon('compare') . ' Compare</button>';
  $o .= '</div>';
  if (!empty($opts['votes'])) $o .= render_vote_buttons($id, (string)($opts['votes_context'] ?? 'index.php'));
  if ($aff) $o .= '<p class="tCard__note">Affiliate link: AIHubpedia may earn a commission. <a href="/disclosure.php">Details</a></p>';
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
