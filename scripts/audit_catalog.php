<?php
/**
 * Catalogue quality audit. Read-only.
 * Usage: php scripts/audit_catalog.php [--json] [--stale-days=90]
 * Exit code 1 when a curated profile is invalid (so CI can gate on it).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../helpers.php';
$json = in_array('--json', $argv, true);
$stale = 90; foreach ($argv as $a) if (strpos($a, '--stale-days=') === 0) $stale = (int)substr($a, 13);

$data = load_tools();
$tools = $data['tools'];
$byId = []; foreach ($tools as $t) $byId[$t['id']] = $t;
$ids = array_keys($byId);
$rawTools = json_decode(file_get_contents(__DIR__ . '/../data/tools.json'), true)['tools'];

$tiers = ['reviewed' => 0, 'standard' => 0, 'basic' => 0]; $cats = []; $subs = [];
foreach ($tools as $t) { $tiers[tool_quality_tier($t)]++; $cats[$t['category']] = ($cats[$t['category']] ?? 0) + 1; if (!empty($t['subcategory'])) $subs[$t['category'] . ' / ' . $t['subcategory']] = ($subs[$t['category'] . ' / ' . $t['subcategory']] ?? 0) + 1; }
arsort($cats); arsort($subs);

$host = fn($u) => preg_replace('/^www\./', '', strtolower((string)parse_url((string)$u, PHP_URL_HOST)));
$byDomain = []; $byName = [];
foreach ($rawTools as $t) {
  $byDomain[$host($t['website'])][] = $t['id'];
  $byName[preg_replace('/[^a-z0-9]/', '', strtolower($t['name']))][] = $t['id'];
}
$dupDomains = array_filter($byDomain, fn($v, $k) => $k !== '' && count($v) > 1, ARRAY_FILTER_USE_BOTH);
$dupNames = array_filter($byName, fn($v) => count($v) > 1);
$notVendorSite = ['appsumo.8odi.net', 'onelink.me', 'apps.apple.com', 'chrome.google.com', 'play.google.com', 'bit.ly', 'tinyurl.com', 'adoption.microsoft.com', 'aws.amazon.com', 'cloud.google.com'];
$badSite = [];
foreach ($rawTools as $t) {
  $h = $host($t['website']);
  foreach ($notVendorSite as $n) if ($h === $n || substr($h, -strlen($n) - 1) === '.' . $n) $badSite[$t['id']] = $h;
  if ($h === '') $badSite[$t['id']] = '(missing)';
  elseif (!preg_match('#^https://#', (string)$t['website'])) $badSite[$t['id']] = $badSite[$t['id']] ?? 'non-https';
}
$boiler = 0; foreach ($rawTools as $t) if (strpos($t['description'], 'is an AI tool listed under') !== false) $boiler++;

$profileErrors = []; $staleProfiles = [];
foreach (curated_profiles() as $id => $p) {
  $err = curated_profile_errors($id, $p, $ids);
  if (!isset($byId[$id])) $err[] = 'profile id is not in tools.json';
  if ($err) $profileErrors[$id] = $err;
  $age = profile_age_days($p);
  if ($age !== null && $age > $stale) $staleProfiles[$id] = $age;
}
$needsReview = array_keys(array_filter(classification_overrides(), fn($c) => !empty($c['needs_review'])));

// Review backlog: un-reviewed tools ordered by observed demand (clicks), then name.
$clicks = all_click_counts();
$backlog = array_values(array_filter($tools, fn($t) => empty($t['curated'])));
usort($backlog, fn($a, $b) => ($clicks[$b['id']] ?? 0) <=> ($clicks[$a['id']] ?? 0) ?: strcmp($a['name'], $b['name']));
$top = array_map(fn($t) => ['id' => $t['id'], 'name' => $t['name'], 'category' => $t['category'], 'clicks' => $clicks[$t['id']] ?? 0, 'website' => $t['website']], array_slice($backlog, 0, 25));

$report = [
  'tools' => count($tools), 'tiers' => $tiers, 'categories' => $cats, 'top_subcategories' => array_slice($subs, 0, 15, true),
  'duplicate_domains' => $dupDomains, 'duplicate_names' => $dupNames, 'website_not_vendor_site' => $badSite,
  'boilerplate_descriptions' => $boiler, 'classification_needs_review' => count($needsReview),
  'curated_profile_errors' => $profileErrors, 'stale_profiles' => $staleProfiles, 'review_backlog_top25' => $top,
];
if ($json) { echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n"; exit($profileErrors ? 1 : 0); }

echo "Catalogue audit\n===============\n";
printf("Tools: %d   reviewed: %d   standard: %d   basic: %d\n", count($tools), $tiers['reviewed'], $tiers['standard'], $tiers['basic']);
echo "Categories: "; foreach ($cats as $c => $n) echo "$c=$n  "; echo "\n";
echo "Boilerplate descriptions: $boiler   Classification awaiting review: " . count($needsReview) . "\n";
echo "Duplicate domains: " . count($dupDomains) . "\n"; foreach ($dupDomains as $d => $v) echo "  $d: " . implode(', ', $v) . "\n";
echo "Duplicate normalised names: " . count($dupNames) . "\n";
echo "Website is not the vendor's own site (affiliate/store/redirect/missing): " . count($badSite) . "\n"; foreach ($badSite as $id => $h) echo "  $id → $h\n";
echo "Curated profile errors: " . count($profileErrors) . "\n"; foreach ($profileErrors as $id => $err) foreach ($err as $m) echo "  $id: $m\n";
echo "Stale profiles (> $stale days): " . count($staleProfiles) . "\n"; foreach ($staleProfiles as $id => $age) echo "  $id: $age days\n";
echo "\nReview backlog (highest demand first):\n"; foreach ($top as $t) printf("  %-28s %-12s clicks=%-4d %s\n", $t['id'], $t['category'], $t['clicks'], $t['website']);
exit($profileErrors ? 1 : 0);
