<?php
/**
 * Automated technical-SEO checks against a running local instance.
 * Usage: php tests/seo_test.php http://127.0.0.1:8002 [--full-sitemap]
 * Exit code 0 = all checks passed.
 */
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8002', '/');
$fullSitemap = in_array('--full-sitemap', $argv, true);
const PROD = 'https://aihubpedia.com';

$fail = 0; $pass = 0;
function check(bool $ok, string $msg) { global $fail, $pass; if ($ok) { $pass++; } else { $fail++; echo "  FAIL: $msg\n"; } }
function fetch(string $url): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60]);
  $raw = (string)curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  curl_close($ch);
  return [$code, substr($raw, 0, $hs), substr($raw, $hs)];
}
function parse_page(string $html): array {
  $dom = new DOMDocument();
  libxml_use_internal_errors(true);
  $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
  libxml_clear_errors();
  $xp = new DOMXPath($dom);
  $meta = function ($attr, $name) use ($xp) { $n = $xp->query("//meta[@$attr='$name']/@content"); return $n->length ? $n->item(0)->nodeValue : null; };
  $ld = [];
  foreach ($xp->query("//script[@type='application/ld+json']") as $s) { $ld[] = json_decode($s->nodeValue, true); }
  $imgsNoAlt = 0;
  foreach ($xp->query('//img') as $img) { if (!$img->hasAttribute('alt')) $imgsNoAlt++; }
  return [
    'titles' => array_map(fn($n) => trim($n->nodeValue), iterator_to_array($xp->query('//head/title'))),
    'desc' => $meta('name', 'description'),
    'robots' => $meta('name', 'robots'),
    'canonical' => ($c = $xp->query("//link[@rel='canonical']/@href"))->length ? $c->item(0)->nodeValue : null,
    'canonicalCount' => $xp->query("//link[@rel='canonical']")->length,
    'h1' => $xp->query('//h1')->length,
    'og' => $meta('property', 'og:title'),
    'ld' => $ld,
    'imgsNoAlt' => $imgsNoAlt,
    'viewport' => $meta('name', 'viewport'),
    'lang' => $dom->documentElement ? $dom->getElementsByTagName('html')->item(0)?->getAttribute('lang') : '',
  ];
}
function ld_types(array $ld): array {
  $types = [];
  $walk = function ($node) use (&$walk, &$types) {
    if (!is_array($node)) return;
    if (isset($node['@type'])) foreach ((array)$node['@type'] as $t) $types[] = $t;
    foreach ($node as $v) if (is_array($v)) $walk($v);
  };
  foreach ($ld as $block) $walk($block);
  return array_values(array_unique($types));
}

// [path, expected status, expected indexable?, expected canonical (path) or null, required JSON-LD types]
$cases = [
  ['/', 200, true, '/', ['Organization', 'WebSite', 'SearchAction', 'CollectionPage']],
  ['/?q=chat', 200, false, null, []],
  ['/?cat=Writing', 200, false, null, []],
  ['/tool.php?id=chatgpt', 200, true, '/tool.php?id=chatgpt', ['SoftwareApplication', 'BreadcrumbList']],
  ['/tool.php?id=monica', 200, true, '/tool.php?id=monica', ['SoftwareApplication', 'BreadcrumbList']],
  ['/tool.php?id=chatgpt&utm_source=x', 200, false, null, []],
  ['/tool.php?id=does-not-exist', 404, false, null, []],
  ['/tool.php', 404, false, null, []],
  ['/category.php?name=Writing', 200, true, '/category.php?name=Writing', ['CollectionPage', 'ItemList', 'BreadcrumbList']],
  ['/category.php?name=Business&page=2', 200, true, '/category.php?name=Business&page=2', ['ItemList']],
  ['/category.php?name=Business&page=99', 404, false, null, []],
  ['/category.php?name=NoSuchCategory', 404, false, null, []],
  ['/category.php', 404, false, null, []],
  ['/categories.php', 200, true, '/categories.php', ['ItemList', 'BreadcrumbList']],
  ['/top.php', 200, true, '/top.php', ['ItemList']],
  ['/leaderboard.php', 200, true, '/leaderboard.php', ['ItemList']],
  ['/compare.php', 200, true, '/compare.php', ['BreadcrumbList']],
  ['/compare.php?a=chatgpt&b=claude', 200, true, '/compare.php?a=chatgpt&b=claude', ['WebPage', 'BreadcrumbList']],
  ['/compare.php?a=claude&b=chatgpt', 200, true, '/compare.php?a=chatgpt&b=claude', ['WebPage']],
  ['/compare.php?a=chatgpt', 200, false, null, []],
  ['/compare.php?a=chatgpt&b=nope', 404, false, null, []],
  ['/comparisons.php', 200, true, '/comparisons.php', ['ItemList']],
  ['/blog.php', 200, true, '/blog.php', ['Blog']],
  ['/post.php?slug=best-ai-tools-2026', 200, true, '/post.php?slug=best-ai-tools-2026', ['BlogPosting', 'BreadcrumbList']],
  ['/post.php?slug=nope', 404, false, null, []],
  ['/ai-use-case-explorer.php', 200, true, '/ai-use-case-explorer.php', []],
  ['/ai-use-case.php?slug=write-blog-posts', 200, true, '/ai-use-case.php?slug=write-blog-posts', ['CollectionPage']],
  ['/about.php', 200, true, '/about.php', []],
  ['/contact.php', 200, true, '/contact.php', []],
  ['/submit.php', 200, true, '/submit.php', []],
  ['/advertise.php', 200, true, '/advertise.php', []],
  ['/privacy.php', 200, true, '/privacy.php', []],
  ['/terms.php', 200, true, '/terms.php', []],
  ['/impressum.php', 200, true, '/impressum.php', []],
  ['/disclaimer.php', 200, true, '/disclaimer.php', []],
  ['/filters.php', 200, true, '/filters.php', []],
  ['/community.php', 200, true, '/community.php', []],
  ['/login.php', 200, false, null, []],
  ['/ai-benchmarks.php', 200, false, null, []],
  ['/ai-usecase-explorer.php', 200, false, null, []],
  ['/new-features-hub.php', 200, false, null, []],
  ['/this-page-does-not-exist', 404, false, null, []],
  // ---- Phase 2 ----
  ['/guides.php', 200, true, '/guides.php', ['CollectionPage', 'ItemList', 'BreadcrumbList']],
  ['/guide.php?slug=best-ai-chatbots-and-assistants', 200, true, '/guide.php?slug=best-ai-chatbots-and-assistants', ['Article', 'ItemList', 'BreadcrumbList', 'SoftwareApplication']],
  ['/guide.php?slug=best-ai-meeting-transcription-tools', 200, true, '/guide.php?slug=best-ai-meeting-transcription-tools', ['Article', 'ItemList']],
  ['/guide.php?slug=best-ai-search-and-research-assistants', 200, true, '/guide.php?slug=best-ai-search-and-research-assistants', ['Article']],
  ['/guide.php?slug=best-ai-tools-for-everyday-office-work', 200, true, '/guide.php?slug=best-ai-tools-for-everyday-office-work', ['Article']],
  ['/guide.php?slug=nope', 404, false, null, []],
  ['/guide.php', 404, false, null, []],
  ['/alternatives.php?tool=chatgpt', 200, true, '/alternatives.php?tool=chatgpt', ['CollectionPage', 'ItemList', 'BreadcrumbList']],
  ['/alternatives.php?tool=otter-ai', 200, true, '/alternatives.php?tool=otter-ai', ['CollectionPage']],
  ['/alternatives.php?tool=grammarly', 404, false, null, []],
  ['/alternatives.php?tool=monica', 404, false, null, []],
  ['/methodology.php', 200, true, '/methodology.php', ['AboutPage', 'BreadcrumbList']],
  ['/tool.php?id=perplexity', 200, true, '/tool.php?id=perplexity', ['SoftwareApplication', 'BreadcrumbList']],
  ['/tool.php?id=notebooklm', 200, true, '/tool.php?id=notebooklm', ['SoftwareApplication']],
  ['/category.php?name=Productivity', 200, true, '/category.php?name=Productivity', ['CollectionPage', 'ItemList']],
  ['/category.php?name=Productivity&sub=meetings-transcription', 200, true, '/category.php?name=Productivity&sub=meetings-transcription', ['CollectionPage', 'BreadcrumbList']],
  ['/category.php?name=Productivity&sub=Meetings%20%26%20transcription', 200, true, '/category.php?name=Productivity&sub=meetings-transcription', ['CollectionPage']],
  ['/category.php?name=Productivity&sub=nonsense', 404, false, null, []],
  ['/category.php?name=Other', 200, false, null, []],
  ['/category.php?name=Chatbots&sub=general-ai-assistants', 200, true, '/category.php?name=Chatbots&sub=general-ai-assistants', ['CollectionPage']],
];

$titles = []; $descs = []; $seenCanon = [];
echo "== Page checks ($base)\n";
foreach ($cases as [$path, $status, $indexable, $canon, $types]) {
  [$code, $headers, $body] = fetch($base . $path);
  echo "- $path [$code]\n";
  check($code === $status, "$path status $code, expected $status");
  check(strncmp(ltrim($body, "\xEF\xBB\xBF"), '<!doctype html>', 15) === 0 || strncmp($body, '<!doctype html>', 15) === 0, "$path must start with <!doctype html> (no leading output)");
  $p = parse_page($body);
  check(count($p['titles']) === 1, "$path has exactly one <title> (" . count($p['titles']) . ")");
  $t = $p['titles'][0] ?? '';
  check(mb_strlen($t) >= 10 && mb_strlen($t) <= 80, "$path title length " . mb_strlen($t) . " ($t)");
  check($p['desc'] !== null && mb_strlen($p['desc']) >= 30 && mb_strlen($p['desc']) <= 160, "$path meta description length " . mb_strlen((string)$p['desc']));
  check($p['viewport'] !== null, "$path has viewport meta");
  check($p['lang'] === 'en', "$path html lang=en");
  check($p['h1'] === 1, "$path has exactly one <h1> ({$p['h1']})");
  check($p['imgsNoAlt'] === 0, "$path images without alt: {$p['imgsNoAlt']}");
  $isNoindex = stripos((string)$p['robots'], 'noindex') !== false;
  check($indexable ? !$isNoindex : $isNoindex, "$path robots='{$p['robots']}', expected " . ($indexable ? 'indexable' : 'noindex'));
  if ($indexable) {
    check($p['canonicalCount'] === 1, "$path exactly one canonical");
    check($p['canonical'] === PROD . $canon, "$path canonical '{$p['canonical']}' expected '" . PROD . $canon . "'");
    if (!isset($seenCanon[$p['canonical']])) { $seenCanon[$p['canonical']] = true; $titles[$t][] = $path; $descs[(string)$p['desc']][] = $path; }
  } else {
    check(stripos($headers, 'X-Robots-Tag: noindex') !== false || $isNoindex, "$path noindex signalled");
  }
  foreach ($p['ld'] as $block) check(is_array($block) && ($block['@context'] ?? '') === 'https://schema.org', "$path JSON-LD parses with schema.org context");
  $found = ld_types($p['ld']);
  foreach ($types as $type) check(in_array($type, $found, true), "$path JSON-LD includes $type (found: " . implode(',', $found) . ")");
  // Tool pages must not invent ratings
  if (strpos($path, '/tool.php?id=') === 0 && $code === 200) {
    foreach ($p['ld'] as $block) foreach (($block['@graph'] ?? []) as $n) {
      if (($n['@type'] ?? '') === 'SoftwareApplication' && isset($n['aggregateRating'])) {
        check((int)$n['aggregateRating']['ratingCount'] > 0, "$path aggregateRating must have real ratingCount");
      }
    }
  }
}
echo "== Phase 2 behaviours\n";
// Retired category names redirect permanently; curated tool pages expose their evidence; unreviewed pages stay honest.
[$code, $headers] = fetch($base . '/category.php?name=SEO');
check($code === 301 && stripos($headers, 'Location: ') !== false && stripos($headers, 'name=Marketing') !== false, "legacy category SEO redirects 301 to Marketing (got $code)");
[$code, , $body] = fetch($base . '/tool.php?id=claude');
check(strpos($body, 'at a glance') !== false && strpos($body, '<details>') !== false && strpos($body, 'pending editor verification') !== false, 'curated tool page shows evidence block, sources and review status');
check(strpos($body, 'Verified by an AIHubpedia editor') === false, 'no profile claims editor verification');
check(strpos($body, 'claude.com/pricing') !== false, 'curated tool page links its vendor source');
[$code, , $body] = fetch($base . '/tool.php?id=monica');
check(strpos($body, 'Category assigned automatically') !== false || strpos($body, 'have not been verified') !== false, 'unreviewed tool page is labelled honestly');
[$code, , $body] = fetch($base . '/guide.php?slug=best-ai-chatbots-and-assistants');
check(strpos($body, 'has not benchmarked') !== false || strpos($body, 'not benchmarked') !== false, 'guide states that quality is not benchmarked');
check(preg_match('/<table/', $body) === 1 && strpos($body, 'Sources') !== false, 'guide contains a comparison table and sources');
check(substr_count($body, 'rel="nofollow noopener"') >= 5, 'guide source links are nofollow');
[$code, , $body] = fetch($base . '/alternatives.php?tool=chatgpt');
check(stripos($body, ' ai assistants options') === false && strpos($body, 'General AI assistants') !== false, 'alternatives copy keeps the AI acronym intact');
[$code, , $body] = fetch($base . '/llms.txt');
check(strpos($body, '## Guides') !== false && strpos($body, 'guide.php?slug=best-ai-chatbots-and-assistants') !== false, 'llms.txt lists the guides');
[$code, , $body] = fetch($base . '/sitemap.xml');
check(strpos($body, 'name=Other') === false, 'sitemap omits the unclassified bucket');
check(strpos($body, '/guide.php?slug=') !== false && strpos($body, '/alternatives.php?tool=chatgpt') !== false && strpos($body, '/methodology.php') !== false, 'sitemap lists guides, alternatives and methodology');
// Category pages must list tools that actually belong to the category (classification applied consistently).
[$code, , $body] = fetch($base . '/category.php?name=Research&sub=ai-search-answers');
check($code === 200 && strpos($body, '>Perplexity<') !== false, 'Perplexity appears under Research / AI search & answers');
[$code, , $body] = fetch($base . '/category.php?name=SEO&x=1');
check($code === 301, 'legacy category redirect also applies with extra parameters');

echo "== Duplicate metadata across indexable pages\n";
foreach ($titles as $t => $paths) check(count($paths) === 1, "duplicate title '$t' on " . implode(', ', $paths));
foreach ($descs as $d => $paths) check(count($paths) === 1, "duplicate description on " . implode(', ', $paths));

echo "== robots.txt / llms.txt / private files\n";
[$code, , $robots] = fetch($base . '/robots.txt');
check($code === 200, 'robots.txt 200');
check(preg_match('#^Sitemap: https://aihubpedia\.com/sitemap\.xml$#m', $robots) === 1, 'robots.txt has absolute Sitemap URL');
foreach (['OAI-SearchBot', 'ClaudeBot', 'PerplexityBot', 'Google-Extended', 'Bingbot'] as $bot) check(strpos($robots, "User-agent: $bot") !== false, "robots.txt names $bot");
check(preg_match('#^Disallow: /data/$#m', $robots) === 1, 'robots.txt disallows /data/');
[$code, , $llms] = fetch($base . '/llms.txt');
check($code === 200 && strpos($llms, '# AIHubpedia') === 0, 'llms.txt served');
foreach (['/data/site.json', '/data/users.json', '/database/full_mysql_schema.sql', '/composer.json', '/vote_debug.php', '/catalog.php', '/analytics.php', '/content.php', '/seo.php', '/helpers.php', '/db.php', '/data/curated_profiles.json', '/data/classification.json', '/scripts/traffic_report.php'] as $priv) {
  [$code] = fetch($base . $priv);
  check($code === 404, "$priv must not be served (got $code)");
}
[$code, , $body] = fetch($base . '/search.php?q=chat');
check($code === 301, 'legacy search.php redirects (301)');

echo "== Sitemap\n";
foreach (['/sitemap.xml', '/sitemap.php'] as $sm) {
  [$code, $headers, $xml] = fetch($base . $sm);
  check($code === 200, "$sm 200");
  check(stripos($headers, 'application/xml') !== false, "$sm content-type xml");
  check(strncmp($xml, '<?xml', 5) === 0, "$sm starts with XML declaration (no leading whitespace)");
  libxml_use_internal_errors(true);
  $doc = simplexml_load_string($xml);
  check($doc !== false, "$sm is well-formed XML");
  if (!$doc) continue;
  $locs = array_map('strval', iterator_to_array($doc->url ? (function () use ($doc) { foreach ($doc->url as $u) yield (string)$u->loc; })() : [], false));
  check(count($locs) > 500, "$sm has " . count($locs) . " URLs");
  check(count($locs) === count(array_unique($locs)), "$sm has no duplicate URLs");
  foreach ($locs as $loc) { if (strpos($loc, PROD . '/') !== 0) { check(false, "$sm non-canonical host: $loc"); break; } }
  if ($sm === '/sitemap.php') {
    $sample = $fullSitemap ? $locs : array_merge(array_slice($locs, 0, 60), array_slice($locs, -40));
    echo "  verifying " . count($sample) . " sitemap URLs are 200 + indexable + self-canonical\n";
    foreach ($sample as $loc) {
      [$code, , $body] = fetch($base . substr($loc, strlen(PROD)));
      $p = parse_page($body);
      check($code === 200, "sitemap URL $loc returns $code");
      check(stripos((string)$p['robots'], 'noindex') === false, "sitemap URL $loc is noindex");
      check($p['canonical'] === $loc, "sitemap URL $loc canonical is '{$p['canonical']}'");
    }
  }
}

echo "\nPASSED: $pass  FAILED: $fail\n";
exit($fail ? 1 : 0);
