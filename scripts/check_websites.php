<?php
/**
 * Verify that each tool's website still responds, and record the result in data/website_checks.json.
 * Needs outbound internet access, so run it from a server or CI job (not from a restricted sandbox).
 *
 * Usage: php scripts/check_websites.php [--limit=50] [--only=chatgpt,claude] [--delay-ms=500] [--write]
 *   Default is a dry run that prints results. Be polite: sequential requests with a delay, identifying User-Agent, no retries.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../catalog.php';
require_once __DIR__ . '/../seo.php';
$opt = ['limit' => 50, 'only' => [], 'delay' => 500, 'write' => in_array('--write', $argv, true)];
foreach ($argv as $a) {
  if (strpos($a, '--limit=') === 0) $opt['limit'] = (int)substr($a, 8);
  if (strpos($a, '--only=') === 0) $opt['only'] = array_filter(explode(',', substr($a, 7)));
  if (strpos($a, '--delay-ms=') === 0) $opt['delay'] = max(100, (int)substr($a, 11));
}
$tools = json_decode(file_get_contents(__DIR__ . '/../data/tools.json'), true)['tools'];
$path = __DIR__ . '/../data/website_checks.json';
$results = is_file($path) ? (json_decode(file_get_contents($path), true)['checks'] ?? []) : [];
$todo = array_values(array_filter($tools, fn($t) => !$opt['only'] || in_array($t['id'], $opt['only'], true)));
// Oldest-checked first so repeated runs walk through the whole catalogue.
usort($todo, fn($a, $b) => strcmp($results[$a['id']]['checked'] ?? '', $results[$b['id']]['checked'] ?? ''));
$todo = array_slice($todo, 0, $opt['limit']);
$counts = [];
foreach ($todo as $t) {
  $url = (string)$t['website'];
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_NOBODY => false, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_USERAGENT => 'AIHubpediaLinkCheck/1.0 (+https://aihubpedia.com/methodology.php)', CURLOPT_RANGE => '0-2048']);
  curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); if ($code === 206) $code = 200;
  $r = website_check_classify($code, $url, (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL), curl_error($ch));
  curl_close($ch);
  $results[$t['id']] = ['status' => $r['status'], 'note' => $r['note'], 'http' => $code, 'checked' => date('Y-m-d'), 'url' => $url];
  $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
  printf("%-28s %-13s %s\n", $t['id'], $r['status'], $r['note']);
  usleep($opt['delay'] * 1000);
}
echo "\nSummary: " . json_encode($counts) . "\n";
if ($opt['write']) { file_put_contents($path, json_encode(['checks' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"); echo "Wrote data/website_checks.json\n"; } else { echo "Dry run (use --write to save).\n"; }
