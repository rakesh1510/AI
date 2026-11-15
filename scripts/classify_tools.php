<?php
/**
 * Rule-based catalogue classification (first-pass proposals for editorial review).
 *
 * Usage:  php scripts/classify_tools.php [--write] [--report]
 *   (default)  dry run: prints the summary only
 *   --write    writes data/classification.json (entries with method "editorial" are never overwritten)
 *   --report   prints every proposal with its signals, grouped by confidence
 *
 * Decision rules
 *   high   → applied.
 *   medium → applied, flagged needs_review.
 *   low    → keep the source category when it exists in the taxonomy and is not the "Automation" dumping ground;
 *            otherwise the tool goes to "Other" (queued for review).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../catalog.php';
require_once __DIR__ . '/../seo.php';

$raw = json_decode(file_get_contents(__DIR__ . '/../data/tools.json'), true);
$tools = $raw['tools'] ?? [];
$existing = json_decode(@file_get_contents(__DIR__ . '/../data/classification.json') ?: '[]', true)['tools'] ?? [];
$taxCats = array_keys(taxonomy()['categories']);
$write = in_array('--write', $argv, true);
$report = in_array('--report', $argv, true);

$out = []; $counts = ['high' => 0, 'medium' => 0, 'low' => 0, 'none' => 0]; $moved = 0; $byCat = [];
foreach ($tools as $t) {
  $id = $t['id'];
  if (($existing[$id]['method'] ?? '') === 'editorial') { $out[$id] = $existing[$id]; continue; }
  $c = classify_tool($t);
  $counts[$c['confidence']]++;
  $source = (string)$t['category'];
  if (in_array($c['confidence'], ['high', 'medium'], true)) {
    $entry = ['category' => $c['category'], 'subcategory' => $c['subcategory'], 'confidence' => $c['confidence'], 'method' => 'rule', 'score' => $c['score'], 'signals' => array_slice($c['signals'], 0, 6), 'needs_review' => $c['confidence'] !== 'high'];
  } elseif (in_array($source, $taxCats, true) && $source !== 'Automation' && $source !== 'Other') {
    $entry = ['category' => $source, 'subcategory' => '', 'confidence' => 'low', 'method' => 'source', 'score' => $c['score'], 'signals' => array_slice($c['signals'], 0, 6), 'needs_review' => true];
  } else {
    $entry = ['category' => CATALOG_UNCLASSIFIED, 'subcategory' => 'Unclassified', 'confidence' => 'low', 'method' => 'source', 'score' => $c['score'], 'signals' => array_slice($c['signals'], 0, 6), 'needs_review' => true];
  }
  if ($entry['category'] !== $source) $moved++;
  $out[$id] = $entry;
}
foreach ($out as $e) $byCat[$e['category']] = ($byCat[$e['category']] ?? 0) + 1;
arsort($byCat);

echo "Tools: " . count($tools) . "\n";
echo "Confidence: " . json_encode($counts) . "\n";
echo "Category changed vs source: $moved\n";
echo "Resulting categories:\n";
foreach ($byCat as $cat => $n) printf("  %-14s %4d\n", $cat, $n);

if ($report) {
  foreach (['high', 'medium', 'low'] as $level) {
    echo "\n== $level ==\n";
    foreach ($tools as $t) {
      $e = $out[$t['id']];
      if ($e['confidence'] !== $level) continue;
      printf("%-28s %-12s -> %-14s / %-34s [%s] %s\n", $t['id'], $t['category'], $e['category'], $e['subcategory'], implode(',', $e['signals'] ?? []), mb_substr($t['short'], 0, 60));
    }
  }
}
if ($write) {
  $payload = ['generated' => date('Y-m-d'), 'method' => 'rule-based first pass (scripts/classify_tools.php); entries with method "editorial" are manual and preserved', 'tools' => $out];
  file_put_contents(__DIR__ . '/../data/classification.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
  echo "\nWrote data/classification.json (" . count($out) . " entries)\n";
}
