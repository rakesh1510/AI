<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly (defence in depth if web-server rules are missing)
/**
 * Builds the list of indexable URLs for the XML sitemap and llms.txt.
 * Only canonical, indexable (HTTP 200, no noindex) URLs are included.
 */
require_once __DIR__ . '/helpers.php';

function sitemap_entries(): array {
  $data = load_tools();
  $tools = $data['tools'] ?? [];
  $cats = $data['categories'] ?? [];
  $entries = [];
  $add = function (string $loc, ?string $lastmod = null, string $freq = 'weekly', string $prio = '0.5') use (&$entries) {
    $entries[$loc] = ['loc' => $loc, 'lastmod' => $lastmod, 'changefreq' => $freq, 'priority' => $prio];
  };

  $add(site_url('/'), null, 'daily', '1.0');   // no lastmod: the homepage changes with the catalogue, and an invented date would be inaccurate
  foreach ([
    ['/categories.php', 'weekly', '0.9'], ['/guides.php', 'weekly', '0.9'], ['/methodology.php', 'monthly', '0.6'], ['/top.php', 'daily', '0.8'], ['/leaderboard.php', 'daily', '0.7'],
    ['/compare.php', 'weekly', '0.7'], ['/solutions.php', 'weekly', '0.8'], ['/recommend.php', 'monthly', '0.8'], ['/changelog.php', 'weekly', '0.6'], ['/comparisons.php', 'weekly', '0.7'], ['/ai-use-case-explorer.php', 'weekly', '0.7'],
    ['/filters.php', 'monthly', '0.4'], ['/community.php', 'weekly', '0.4'],
    ['/submit.php', 'monthly', '0.5'], ['/business.php', 'monthly', '0.6'], ['/services.php', 'monthly', '0.6'], ['/partners.php', 'monthly', '0.4'], ['/disclosure.php', 'yearly', '0.3'], ['/advertise.php', 'monthly', '0.4'], ['/about.php', 'yearly', '0.4'],
    ['/contact.php', 'yearly', '0.4'], ['/disclaimer.php', 'yearly', '0.2'], ['/privacy.php', 'yearly', '0.2'],
    ['/terms.php', 'yearly', '0.2'], ['/impressum.php', 'yearly', '0.2'],
  ] as [$path, $freq, $prio]) {
    $add(site_url($path), null, $freq, $prio);
  }
  foreach (array_keys(catalog_json('trustnova_services.json', ['services' => []])['services'] ?? []) as $svcSlug) $add(site_url('/services.php?s=' . rawurlencode($svcSlug)), null, 'monthly', '0.5');   // our own original service pages

  $counts = array_count_values(array_map(fn($t) => (string)($t['category'] ?? ''), $tools));
  foreach ($cats as $c) {
    $n = (int)($counts[$c] ?? 0);
    if ($n === 0 || $c === CATALOG_UNCLASSIFIED) continue;   // the catch-all bucket is noindex
    $catAdded = array_filter(array_map(fn($t) => ($t['category'] ?? '') === $c && !empty($t['added']) && strtotime((string)$t['added']) ? date('Y-m-d', strtotime((string)$t['added'])) : null, $tools));
    $catMod = $catAdded ? max($catAdded) : null;   // newest listing date in the category (real data), otherwise omitted
    $add(category_page_url($c), $catMod, 'weekly', '0.8');
    for ($p = 2, $pages = (int)ceil($n / 48); $p <= $pages; $p++) $add(category_page_url($c, $p), $catMod, 'weekly', '0.4');
    // Subcategory landing pages with at least three tools.
    $subCounts = [];
    foreach ($tools as $t) { if (($t['category'] ?? '') === $c && ($t['subcategory'] ?? '') !== '') $subCounts[$t['subcategory']] = ($subCounts[$t['subcategory']] ?? 0) + 1; }
    foreach ($subCounts as $sub => $sn) { if ($sn >= 3) $add(category_page_url($c, 1, $sub), $catMod, 'weekly', '0.6'); }
  }

  foreach (guides_all() as $slug => $g) $add(guide_url($slug), (string)($g['updated'] ?? null) ?: null, 'monthly', '0.8');
  $byId = [];
  foreach ($tools as $t) $byId[(string)$t['id']] = $t;
  foreach (alternatives_eligible($byId) as $altId) $add(alternatives_url($altId), (string)($byId[$altId]['curated']['review']['reviewed_on'] ?? '') ?: null, 'monthly', '0.7');

  foreach (solutions_indexable_slugs($tools) as $slug) $add(solution_url($slug), null, 'weekly', '0.7');   // evidence-gated: >= 3 reviewed tools

  if ($pg = pricing_groups($byId)) {   // evidence-gated: >= 3 reviewed tools with >= 2 sources each
    $add(site_url('/pricing-comparison.php'), null, 'weekly', '0.7');
    foreach (array_keys($pg) as $gs) $add(site_url('/pricing-comparison.php?group=' . rawurlencode($gs)), null, 'weekly', '0.7');
  }

  if (newsletter_issues_public()) {
    $add(site_url('/newsletter-archive.php'), null, 'weekly', '0.5');
    foreach (newsletter_issues_public() as $slug => $i) $add(newsletter_issue_url($slug), (string)$i['published_on'], 'yearly', '0.4');
  }

  foreach (sitemap_universe_paths() as $uPath) $add(site_url($uPath), null, 'weekly', '0.5');   // AI Universe graph: evidence-gated, nothing is listed while there is no verified data

  foreach (load_use_cases() as $slug => $case) $add(use_case_page_url((string)$slug), null, 'weekly', '0.6');

  foreach ($tools as $t) {
    $id = (string)($t['id'] ?? '');
    if ($id === '') continue;
    $added = (string)($t['added'] ?? '');
    $lastmod = ($added !== '' && strtotime($added)) ? date('Y-m-d', strtotime($added)) : null;
    if (!tool_is_indexable($t)) continue;
    $add(tool_page_url($id), !empty($t['curated']) ? ($t['curated']['review']['reviewed_on'] ?? $lastmod) : $lastmod, 'weekly', !empty($t['curated']) ? '0.8' : (tool_has_generic_profile($t) ? '0.5' : '0.7'));
  }

  foreach (available_comparison_pairs($tools, 24) as [$a, $b]) $add(compare_page_url($a['id'], $b['id']), null, 'monthly', '0.5');

  /* AI Universe pages are listed only when verified data exists for them (see up_sitemap_entries); a missing or empty ledger adds nothing. */
  try { require_once __DIR__ . '/uni_pages.php'; foreach (up_sitemap_entries() as [$path, $freq, $prio]) $add(site_url($path), null, $freq, $prio); } catch (Throwable $e) { sec_log_exception($e, 'sitemap universe'); }

  if (posts_indexable()) $add(site_url('/blog.php'), null, 'weekly', '0.6');
  foreach (posts_indexable() as $p) {
    $slug = (string)($p['slug'] ?? '');
    if ($slug === '') continue;
    $d = (string)($p['date'] ?? '');
    $add(post_page_url($slug), ($d !== '' && strtotime($d)) ? date('Y-m-d', strtotime($d)) : null, 'monthly', '0.6');
  }
  return array_values($entries);
}

/** Paths of the AI Universe graph pages that have verified content: none while the ledger holds no verified entity (or when it cannot be read), otherwise the graph page and the first 200 entity deep links. */
function sitemap_universe_paths(): array {
  try {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/uni_core.php';
    $pdo = db();
    $first = uni_public_entities($pdo, [], 1, 100);
    if ($first['total'] === 0) return [];
    $items = $first['items'];
    if ($first['has_more']) $items = array_merge($items, uni_public_entities($pdo, [], 2, 100)['items']);
    $out = ['/universe-graph.php'];
    foreach ($items as $it) $out[] = '/universe-graph.php?slug=' . rawurlencode((string)$it['slug']);
    return $out;
  } catch (Throwable $e) {
    return [];
  }
}

function sitemap_xml(array $entries): string {
  $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
  foreach ($entries as $e) {
    $out .= '  <url><loc>' . htmlspecialchars($e['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>';
    if (!empty($e['lastmod'])) $out .= '<lastmod>' . $e['lastmod'] . '</lastmod>';
    $out .= '<changefreq>' . $e['changefreq'] . '</changefreq><priority>' . $e['priority'] . "</priority></url>\n";
  }
  return $out . "</urlset>\n";
}
