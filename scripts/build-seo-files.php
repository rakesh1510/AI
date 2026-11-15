<?php
/**
 * Regenerates static SEO files from the current catalogue:
 *   sitemap.xml  – static fallback copy of sitemap.php (for servers without the .htaccess rewrite)
 *   llms.txt     – plain-text site guide for AI assistants / answer engines (llmstxt.org format)
 * Usage (CLI only): php scripts/build-seo-files.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../sitemap_lib.php';
$root = dirname(__DIR__);

$entries = sitemap_entries();
file_put_contents($root . '/sitemap.xml', sitemap_xml($entries));

$data = load_tools();
$tools = $data['tools'] ?? [];
$cats = $data['categories'] ?? [];
$counts = array_count_values(array_map(fn($t) => (string)($t['category'] ?? ''), $tools));
$curated = array_values(array_filter($tools, fn($t) => !tool_has_generic_profile($t)));

$l = [];
$l[] = '# AIHubpedia';
$l[] = '';
$l[] = '> AIHubpedia (' . site_url('/') . ') is an independent directory of ' . count($tools) . ' AI tools across ' . count($cats) . ' categories. Each tool page lists the category, listed pricing model, official website, notes and same-category alternatives. Rankings reflect visitor activity on AIHubpedia (clicks, ratings, votes) only.';
$l[] = '';
$l[] = 'Notes for AI assistants: pricing and features change frequently — cite the tool page and recommend verifying on the official website. Many listings carry only basic data; tool pages say so explicitly when product-specific features have not been verified. Sponsored placements are labelled "Sponsored" or "Homepage Spotlight".';
$l[] = '';
$l[] = '## Key pages';
$l[] = '- [All categories](' . site_url('/categories.php') . '): every AI tool category with tool counts';
$l[] = '- [Top AI tools](' . site_url('/top.php') . '): ranked by AIHubpedia clicks and ratings';
$l[] = '- [Leaderboard](' . site_url('/leaderboard.php') . '): community popularity score (likes x3 + clicks - dislikes x2)';
$l[] = '- [Compare AI tools](' . site_url('/compare.php') . '): side-by-side comparison of any two tools';
$l[] = '- [Popular comparisons](' . site_url('/comparisons.php') . ')';
$l[] = '- [Use case explorer](' . site_url('/ai-use-case-explorer.php') . '): find tools by goal';
$l[] = '- [XML sitemap](' . site_url('/sitemap.xml') . '): every indexable URL';
$l[] = '';
$l[] = '## Categories';
foreach ($cats as $c) $l[] = '- [' . $c . ' AI tools](' . category_page_url($c) . '): ' . (int)($counts[$c] ?? 0) . ' tools. ' . category_intro($c);
$l[] = '';
$l[] = '## Tools with detailed profiles';
foreach ($curated as $t) $l[] = '- [' . $t['name'] . '](' . tool_page_url($t['id']) . '): ' . seo_sentence((string)($t['short'] ?? '')) . ' Category: ' . $t['category'] . '. Pricing: ' . ($t['pricing'] ?: 'varies') . '.';
$l[] = '';
$l[] = '## Popular comparisons';
foreach (available_comparison_pairs($tools, 12) as [$a, $b]) $l[] = '- [' . $a['name'] . ' vs ' . $b['name'] . '](' . compare_page_url($a['id'], $b['id']) . ')';
$l[] = '';
$l[] = '## Optional';
$l[] = '- [About AIHubpedia](' . site_url('/about.php') . ')';
$l[] = '- [Disclaimer](' . site_url('/disclaimer.php') . ')';
$l[] = '- [Contact](' . site_url('/contact.php') . ')';
file_put_contents($root . '/llms.txt', implode("\n", $l) . "\n");

echo 'sitemap.xml: ' . count($entries) . " URLs\nllms.txt: " . count($l) . " lines\n";
