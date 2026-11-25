<?php
/**
 * AI Universe stays hidden by default: no sitemap entries (even with verified data), no public navigation or footer links, noindex on its pages,
 * robots.txt disallows its routes, while the code, routes, data and admin tools are untouched (the public-mode switch re-enables listing).
 * DB-free. Usage: php tests/universe_hidden_test.php
 */
chdir(__DIR__ . '/..');
putenv('AIHUB_UNIVERSE_PUBLIC');   // default: hidden
require_once 'helpers.php'; require_once 'ui.php'; require_once 'sitemap_lib.php';
$p = 0; $f = 0; function ck($ok, $m) { global $p, $f; if ($ok) $p++; else { $f++; echo "FAIL $m\n"; } }

ck(universe_public() === false, 'hidden by default');
$xml = sitemap_xml(sitemap_entries());
ck(strpos($xml, 'universe') === false && strpos($xml, '<urlset') !== false, 'sitemap lists no AI Universe URL and is still a valid urlset');
$nav = json_encode([site_nav_primary(), site_nav_more(), site_nav_cta()]);
ck(stripos($nav, 'universe') === false, 'navigation has no AI Universe link');
foreach (['layout_top.php', 'layout_bottom.php', 'index.php'] as $file) {
  $src = file_get_contents($file);
  // the Universe routes' own sub-navigation (only rendered while visiting a universe route) is part of the preserved feature, not public navigation
  $src = preg_replace('#<\?php if \(\$__inUniverse\): \?>.*?<\?php endif; \?>#s', '', $src);
  ck(!preg_match('#href="[^"]*universe#i', $src), "$file has no hard-coded AI Universe link");
}
$robots = file_get_contents('robots.txt');
// robots.txt keeps the API and the endless parameter spaces blocked, but does NOT disallow the pages themselves: while hidden they send noindex, nofollow, which a crawler can only obey if it may fetch them
foreach (['/universe-api.php', '/universe-editor.php', '/universe-heatmap.php?*', '/universe-arena.php?a=', '/universe-arena.php?b='] as $path) ck(strpos($robots, "Disallow: $path") !== false, "robots.txt disallows $path");
foreach (['/universe.php', '/universe-graph.php', '/universe-lineage.php'] as $path) ck(strpos($robots, "Disallow: $path\n") === false, "robots.txt does not hide $path from the noindex signal");
ck(strpos($robots, 'Sitemap: https://aihubpedia.com/sitemap.xml') !== false, 'robots.txt still advertises the sitemap');
// functionality preserved: the routes, code and admin tools still exist
foreach (['universe.php', 'universe-graph.php', 'universe-heatmap.php', 'universe-lineage.php', 'universe-arena.php', 'universe-editor.php', 'universe-api.php', 'uni_core.php', 'uni_pages.php', 'adm_universe.php'] as $file) ck(is_file($file), "$file is preserved");
// the switch is reversible
putenv('AIHUB_UNIVERSE_PUBLIC=1'); ck(universe_public() === true, 'AIHUB_UNIVERSE_PUBLIC=1 re-enables public listing'); putenv('AIHUB_UNIVERSE_PUBLIC');
// hidden pages are noindex
$lt = file_get_contents('layout_top.php');
ck(strpos($lt, "universe_public()") !== false && strpos($lt, "noindex, nofollow") !== false, 'layout sets noindex, nofollow for universe pages while hidden');
echo "universe hidden: $p passed, $f failed\n"; exit($f ? 1 : 0);
