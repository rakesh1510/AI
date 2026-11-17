<?php
/**
 * Builds the list of indexable URLs for the XML sitemap and llms.txt.
 * Only canonical, indexable (HTTP 200, no noindex) URLs are included.
 */
require_once __DIR__ . '/helpers.php';

function sitemap_entries(): array {
  $data = load_tools();
  $tools = $data['tools'] ?? [];
  $cats = $data['categories'] ?? [];
  $today = date('Y-m-d');
  $entries = [];
  $add = function (string $loc, ?string $lastmod = null, string $freq = 'weekly', string $prio = '0.5') use (&$entries) {
    $entries[$loc] = ['loc' => $loc, 'lastmod' => $lastmod, 'changefreq' => $freq, 'priority' => $prio];
  };

  $add(site_url('/'), $today, 'daily', '1.0');
  foreach ([
    ['/categories.php', 'weekly', '0.9'], ['/guides.php', 'weekly', '0.9'], ['/methodology.php', 'monthly', '0.6'], ['/top.php', 'daily', '0.8'], ['/leaderboard.php', 'daily', '0.7'],
    ['/compare.php', 'weekly', '0.7'], ['/solutions.php', 'weekly', '0.8'], ['/comparisons.php', 'weekly', '0.7'], ['/ai-use-case-explorer.php', 'weekly', '0.7'],
    ['/blog.php', 'weekly', '0.6'], ['/filters.php', 'monthly', '0.4'], ['/community.php', 'weekly', '0.4'],
    ['/submit.php', 'monthly', '0.5'], ['/business.php', 'monthly', '0.6'], ['/disclosure.php', 'yearly', '0.3'], ['/advertise.php', 'monthly', '0.4'], ['/about.php', 'yearly', '0.4'],
    ['/contact.php', 'yearly', '0.4'], ['/disclaimer.php', 'yearly', '0.2'], ['/privacy.php', 'yearly', '0.2'],
    ['/terms.php', 'yearly', '0.2'], ['/impressum.php', 'yearly', '0.2'],
  ] as [$path, $freq, $prio]) {
    $add(site_url($path), null, $freq, $prio);
  }

  $counts = array_count_values(array_map(fn($t) => (string)($t['category'] ?? ''), $tools));
  foreach ($cats as $c) {
    $n = (int)($counts[$c] ?? 0);
    if ($n === 0 || $c === CATALOG_UNCLASSIFIED) continue;   // the catch-all bucket is noindex
    $add(category_page_url($c), $today, 'weekly', '0.8');
    for ($p = 2, $pages = (int)ceil($n / 48); $p <= $pages; $p++) $add(category_page_url($c, $p), $today, 'weekly', '0.4');
    // Subcategory landing pages with at least three tools.
    $subCounts = [];
    foreach ($tools as $t) { if (($t['category'] ?? '') === $c && ($t['subcategory'] ?? '') !== '') $subCounts[$t['subcategory']] = ($subCounts[$t['subcategory']] ?? 0) + 1; }
    foreach ($subCounts as $sub => $sn) { if ($sn >= 3) $add(category_page_url($c, 1, $sub), $today, 'weekly', '0.6'); }
  }

  foreach (guides_all() as $slug => $g) $add(guide_url($slug), (string)($g['updated'] ?? null) ?: null, 'monthly', '0.8');
  $byId = [];
  foreach ($tools as $t) $byId[(string)$t['id']] = $t;
  foreach (alternatives_eligible($byId) as $altId) $add(alternatives_url($altId), (string)($byId[$altId]['curated']['review']['reviewed_on'] ?? '') ?: null, 'monthly', '0.7');

  foreach (solutions_indexable_slugs($tools) as $slug) $add(solution_url($slug), null, 'weekly', '0.7');   // evidence-gated: >= 3 reviewed tools

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

  foreach (load_posts() as $p) {
    $slug = (string)($p['slug'] ?? '');
    if ($slug === '') continue;
    $d = (string)($p['date'] ?? '');
    $add(post_page_url($slug), ($d !== '' && strtotime($d)) ? date('Y-m-d', strtotime($d)) : null, 'monthly', '0.6');
  }
  return array_values($entries);
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
