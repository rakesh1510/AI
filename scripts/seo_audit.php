<?php
/**
 * Technical SEO / GEO audit against a running instance (test server). Reads every sitemap URL and reports facts, not scores:
 * duplicate titles and descriptions, missing canonicals/h1, noindex pages listed in the sitemap, JSON-LD that does not parse,
 * thin pages, internal-link counts and image alt coverage.
 * Usage: php scripts/seo_audit.php http://127.0.0.1:8002 [--json]
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8002', '/'); $json = in_array('--json', $argv, true);
$get = function (string $url) { $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false]); $b = (string)curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return [$c, $b]; };
[$code, $xml] = $get($base . '/sitemap.xml');
preg_match_all('#<loc>([^<]+)</loc>#', html_entity_decode($xml), $m);
$urls = array_map(fn($u) => $base . (parse_url($u, PHP_URL_PATH) ?: '/') . (($q = parse_url($u, PHP_URL_QUERY)) ? '?' . $q : ''), $m[1]);
$r = ['sitemap_urls' => count($urls), 'by_type' => [], 'missing_title' => [], 'missing_description' => [], 'missing_h1' => [], 'multiple_h1' => [], 'missing_canonical' => [], 'canonical_mismatch' => [], 'noindex_in_sitemap' => [], 'jsonld_invalid' => [], 'no_breadcrumb' => [], 'thin' => [], 'img_without_alt' => 0, 'non_200' => []];
$titles = []; $descs = []; $inlinks = [];
foreach ($urls as $u) {
  [$c, $html] = $get($u);
  $type = basename(parse_url($u, PHP_URL_PATH) ?: 'index.php'); $r['by_type'][$type] = ($r['by_type'][$type] ?? 0) + 1;
  if ($c !== 200) { $r['non_200'][] = "$c $u"; continue; }
  $d = new DOMDocument(); libxml_use_internal_errors(true); $d->loadHTML('<?xml encoding="utf-8"?>' . $html); libxml_clear_errors(); $x = new DOMXPath($d);
  $t = trim((string)($x->query('//head/title')->item(0)->nodeValue ?? '')); $desc = (string)($x->query("//meta[@name='description']/@content")->item(0)->nodeValue ?? '');
  $canon = (string)($x->query("//link[@rel='canonical']/@href")->item(0)->nodeValue ?? ''); $robots = (string)($x->query("//meta[@name='robots']/@content")->item(0)->nodeValue ?? '');
  $h1 = $x->query('//h1')->length;
  if ($t === '') $r['missing_title'][] = $u; else $titles[$t][] = $u;
  if ($desc === '') $r['missing_description'][] = $u; else $descs[$desc][] = $u;
  if ($h1 === 0) $r['missing_h1'][] = $u; if ($h1 > 1) $r['multiple_h1'][] = $u;
  if ($canon === '') $r['missing_canonical'][] = $u; elseif (rtrim(parse_url($canon, PHP_URL_PATH) ?: '/', '/') . '?' . (parse_url($canon, PHP_URL_QUERY) ?? '') !== rtrim(parse_url($u, PHP_URL_PATH) ?: '/', '/') . '?' . (parse_url($u, PHP_URL_QUERY) ?? '')) $r['canonical_mismatch'][] = "$u -> $canon";
  if (stripos($robots, 'noindex') !== false) $r['noindex_in_sitemap'][] = $u;
  foreach ($x->query("//script[@type='application/ld+json']") as $s) { if (json_decode($s->nodeValue, true) === null) $r['jsonld_invalid'][] = $u; }
  if ($type !== 'index.php' && $x->query("//nav[contains(@class,'breadcrumbs')]")->length === 0) $r['no_breadcrumb'][] = $u;
  foreach ($x->query('//img') as $img) if (!$img->hasAttribute('alt')) $r['img_without_alt']++;
  $main = $x->query('//main')->item(0); $words = $main ? str_word_count(strip_tags($main->textContent)) : 0;
  if ($words < 150 && $type !== 'index.php') $r['thin'][] = "$words words: $u";
  foreach ($x->query('//main//a/@href') as $a) { $h = $a->nodeValue; if ($h !== '' && $h[0] === '/') $inlinks[parse_url($h, PHP_URL_PATH) . (($q = parse_url($h, PHP_URL_QUERY)) ? '?' . $q : '')] = ($inlinks[parse_url($h, PHP_URL_PATH) . (($q = parse_url($h, PHP_URL_QUERY)) ? '?' . $q : '')] ?? 0) + 1; }
}
$r['duplicate_titles'] = array_map('count', array_filter($titles, fn($l) => count($l) > 1));
$r['duplicate_descriptions'] = array_map('count', array_filter($descs, fn($l) => count($l) > 1));
$orphans = 0; foreach ($urls as $u) { $k = (parse_url($u, PHP_URL_PATH) ?: '/') . (($q = parse_url($u, PHP_URL_QUERY)) ? '?' . $q : ''); if (!isset($inlinks[$k])) $orphans++; }
$r['sitemap_urls_without_in_content_links'] = $orphans;
if ($json) { echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n"; exit(0); }
echo "SEO audit of {$r['sitemap_urls']} sitemap URLs\n";
foreach (['non_200', 'missing_title', 'missing_description', 'missing_h1', 'multiple_h1', 'missing_canonical', 'canonical_mismatch', 'noindex_in_sitemap', 'jsonld_invalid', 'no_breadcrumb', 'thin'] as $k) echo str_pad($k, 26) . count($r[$k]) . "\n";
echo str_pad('duplicate titles (groups)', 26) . count($r['duplicate_titles']) . "\n" . str_pad('duplicate descriptions', 26) . count($r['duplicate_descriptions']) . "\n" . str_pad('img without alt', 26) . $r['img_without_alt'] . "\n" . str_pad('no in-content inbound link', 26) . $orphans . "\n";
echo "by type: " . json_encode($r['by_type']) . "\n";
foreach (['duplicate_titles', 'duplicate_descriptions'] as $k) { $top = $r[$k]; arsort($top); foreach (array_slice($top, 0, 3, true) as $v => $n) echo "  $k x$n: " . substr($v, 0, 90) . "\n"; }
foreach (['noindex_in_sitemap', 'canonical_mismatch', 'thin'] as $k) foreach (array_slice($r[$k], 0, 4) as $v) echo "  $k: $v\n";

$gate = ['non_200', 'missing_title', 'missing_description', 'missing_h1', 'multiple_h1', 'missing_canonical', 'canonical_mismatch', 'noindex_in_sitemap', 'jsonld_invalid'];
$bad = 0; foreach ($gate as $k) $bad += count($r[$k]);
$bad += count($r['duplicate_titles']) + count($r['duplicate_descriptions']) + ($r['img_without_alt'] > 0 ? 1 : 0);
exit($bad ? 1 : 0);
