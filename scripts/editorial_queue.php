<?php
/**
 * Catalogue quality audit and prioritised editorial verification queue (READ-ONLY).
 * Usage: php scripts/editorial_queue.php [--csv=path] [--json] [--top=40]
 * It scores every tool on (a) how visible it is, so errors cost the most trust, and (b) how risky its current data is. It never edits a
 * profile and NEVER marks anything verified: verification needs a person to check the live vendor pages (scripts/review_profile.php).
 * Output is deterministic (no timestamps) so a committed copy can be diffed.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../helpers.php';
$opt = function (string $k, $d = '') use ($argv) { foreach ($argv as $a) if (strpos($a, "--$k=") === 0) return substr($a, strlen($k) + 3); return $d; };
$byId = tools_by_id(); $tools = array_values($byId);
$cls = classification_overrides();

// ---- visibility signals ---------------------------------------------------------------------------------------------------------------
$inGuides = []; foreach (guides_all() as $slug => $g) foreach (($g['tools'] ?? []) as $id) $inGuides[$id][] = $slug;
$inProblems = []; foreach (solutions_all() as $slug => $p) foreach (solution_results($tools, $p, SOLUTION_PAGE_LIMIT) as $r) $inProblems[$r['tool']['id']][] = $slug;
$inPairs = []; foreach (available_comparison_pairs($tools, 24) as [$a, $b]) { $inPairs[$a['id']][] = $b['id']; $inPairs[$b['id']][] = $a['id']; }
$wizard = []; foreach (array_keys(recommend_config()['objectives']) as $o) foreach (recommend(recommend_normalize_answers(['objective' => $o, 'budget' => 'flexible']), $byId)['items'] as $it) $wizard[$it['tool']['id']][] = $o;
$alts = []; foreach ($byId as $id => $t) { if (empty($t['curated'])) continue; foreach (($t['curated']['alternatives'] ?? []) as $a) $alts[$a][] = $id; }

// ---- risk signals ---------------------------------------------------------------------------------------------------------------------
$affHosts = '/(^|\.)(8odi\.net|shareasale\.com|awin1\.com|impact\.com|pxf\.io|sjv\.io|ojrq\.net|partnerstack\.com|rewardful\.com|refersion\.com|linksynergy\.com|tkqlhce\.com|anrdoezrs\.net|dpbolvw\.net|jdoqocy\.com|kqzyfj\.com|clickbank\.net|tidd\.ly|geni\.us|go\.[a-z0-9-]+\.[a-z]+|click\.linksynergy\.com|appsumo\.8odi\.net)$/i';
$affParams = '/[?&](aff|affiliate|ref|refcode|fpr|via|irclickid|clickid|partner|a_aid|tap_a|mbsy|rfsn)=/i';
$claims = '/\b(best|#1|number one|leading|top[- ]rated|revolutioni[sz]e[sd]?|ultimate|world[- ]class|trusted by|\d+\+?\s?(million|k)\b|\d+x\b|\d+%|guarantee[d]?|unlimited|instantly|10x|game[- ]chang)/i';
$norm = fn($s) => preg_replace('/[^a-z0-9]+/', '', strtolower((string)$s));
$domCount = []; $nameCount = []; $boiler = [];
foreach ($tools as $t) { $d = preg_replace('/^www\./', '', strtolower((string)parse_url((string)($t['website'] ?? ''), PHP_URL_HOST))); $domCount[$d] = ($domCount[$d] ?? 0) + 1; $nameCount[$norm($t['name'])] = ($nameCount[$norm($t['name'])] ?? 0) + 1; if (empty($t['curated'])) { $k = md5(json_encode([$t['features'] ?? [], $t['pros'] ?? [], $t['cons'] ?? []])); $boiler[$k] = ($boiler[$k] ?? 0) + 1; } }
arsort($boiler); $boilerKey = array_key_first($boiler); $boilerN = (int)reset($boiler);

$rows = [];
foreach ($tools as $t) {
  $id = (string)$t['id']; $ev = tool_evidence($t); $caps = tool_capabilities($t); $reviewed = !empty($t['curated']);
  $host = preg_replace('/^www\./', '', strtolower((string)parse_url((string)($t['website'] ?? ''), PHP_URL_HOST)));
  $flags = []; $vis = 0; $risk = 0;
  // visibility
  if (!empty($inGuides[$id])) { $vis += 15; $flags[] = 'in-guide'; }
  if (!empty($inProblems[$id])) { $vis += min(20, 10 * count($inProblems[$id])); $flags[] = 'on-problem-page'; }
  if (!empty($inPairs[$id])) { $vis += 10; $flags[] = 'in-comparison'; }
  if (!empty($wizard[$id])) { $vis += 10; $flags[] = 'wizard-eligible'; }
  if (!empty($alts[$id])) { $vis += 5; $flags[] = 'listed-as-alternative'; }
  if ($reviewed) $vis += 10;
  // risk
  if ($reviewed && !$ev['human_verified']) { $risk += 25; $flags[] = 'reviewed-not-verified'; }
  if ($reviewed && $ev['conflicts']) { $risk += 10; $flags[] = 'sources-conflict'; }
  if ($reviewed && in_array($ev['level'], ['limited'], true)) { $risk += 5; $flags[] = 'limited-evidence'; }
  if ($reviewed && $ev['pricing_stale']) { $risk += 10; $flags[] = 'pricing-stale'; }
  if ($reviewed && count(array_filter($caps, fn($c) => $c['state'] === 'documented')) < 4) { $risk += 4; $flags[] = 'weak-comparison-data'; }
  if (!$reviewed) { $flags[] = 'pricing-unsourced'; if (md5(json_encode([$t['features'] ?? [], $t['pros'] ?? [], $t['cons'] ?? []])) === $boilerKey && $boilerN > 20) { $risk += 5; $flags[] = 'template-features-pros-cons'; } }
  if (link_has_tracking($t)) { $risk += 20; $flags[] = 'possible-affiliate-link-in-website'; }
  if (preg_match($claims, (string)($t['short'] ?? ''))) { $risk += 8; $flags[] = 'marketing-claim-in-description'; }
  $c = $cls[$id] ?? null;
  if (!$reviewed && $c) { if (($c['confidence'] ?? '') === 'low') { $risk += 5; $flags[] = 'category-low-confidence'; } if (($t['category'] ?? '') === CATALOG_UNCLASSIFIED) { $risk += 3; $flags[] = 'category-other'; } if (!empty($t['source_category']) && $t['source_category'] !== $t['category']) $flags[] = 'category-differs-from-source'; }
  if (($domCount[$host] ?? 0) > 1 && $host !== '') { $risk += 8; $flags[] = 'duplicate-website-domain'; }
  if (($nameCount[$norm($t['name'])] ?? 0) > 1) { $risk += 8; $flags[] = 'duplicate-name'; }
  if (mb_strlen((string)($t['short'] ?? '')) < 40) { $risk += 4; $flags[] = 'description-too-short'; }
  if (preg_match('#^http://#i', (string)($t['website'] ?? ''))) { $risk += 4; $flags[] = 'website-not-https'; }
  if (empty($t['website']) || !preg_match('#^https?://#i', (string)$t['website'])) { $risk += 10; $flags[] = 'website-missing-or-invalid'; }
  $score = $vis + $risk;
  $action = in_array('possible-affiliate-link-in-website', $flags, true) ? 'Check the website field: replace tracking URLs with the vendor URL or disclose as an affiliate link' :
    ($reviewed ? ($ev['conflicts'] ? 'Verify on live vendor pages and resolve the listed source conflicts' : 'Verify facts and prices on live vendor pages (scripts/review_profile.php)') :
    (!empty($flags) && (in_array('on-problem-page', $flags, true) || in_array('in-comparison', $flags, true)) ? 'Review and write a sourced profile (visible in discovery pages)' : 'Low priority: keep as basic listing, fix flagged data quality items'));
  $rows[] = ['id' => $id, 'name' => $t['name'], 'tier' => tool_quality_tier($t), 'category' => (string)$t['category'], 'subcategory' => (string)($t['subcategory'] ?? ''), 'visibility' => $vis, 'risk' => $risk, 'score' => $score, 'flags' => $flags, 'action' => $action, 'human_verified' => $reviewed && $ev['human_verified']];
}
usort($rows, fn($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['id'], $b['id']));
foreach ($rows as $i => &$r) { $r['rank'] = $i + 1; $r['priority'] = $r['score'] >= 50 ? 'P1' : ($r['score'] >= 25 ? 'P2' : 'P3'); } unset($r);

$flagCounts = []; foreach ($rows as $r) foreach ($r['flags'] as $f) $flagCounts[$f] = ($flagCounts[$f] ?? 0) + 1; arsort($flagCounts);
$summary = ['tools' => count($rows), 'reviewed' => count(array_filter($rows, fn($r) => $r['tier'] === 'reviewed')), 'editor_verified' => count(array_filter($rows, fn($r) => $r['human_verified'])),
  'priority' => array_count_values(array_column($rows, 'priority')), 'flag_counts' => $flagCounts, 'template_boilerplate_profiles' => $boilerN];
if (($csv = $opt('csv')) !== '') {
  $fh = fopen($csv, 'w'); fputcsv($fh, ['rank', 'priority', 'score', 'visibility', 'risk', 'id', 'name', 'tier', 'category', 'subcategory', 'flags', 'recommended_action', 'human_verified']);
  foreach ($rows as $r) fputcsv($fh, [$r['rank'], $r['priority'], $r['score'], $r['visibility'], $r['risk'], $r['id'], $r['name'], $r['tier'], $r['category'], $r['subcategory'], implode(';', $r['flags']), $r['action'], $r['human_verified'] ? 'yes' : 'no']);
  fclose($fh);
}
if (in_array('--json', $argv, true)) { echo json_encode(['summary' => $summary, 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n"; exit(0); }
echo "Editorial verification queue: {$summary['tools']} tools, {$summary['reviewed']} reviewed, {$summary['editor_verified']} editor-verified\n";
echo 'Priority: ' . json_encode($summary['priority']) . "\nTemplate boilerplate (identical features/pros/cons) on {$boilerN} unreviewed profiles\nFlags:\n";
foreach ($flagCounts as $f => $n) printf("  %-36s %d\n", $f, $n);
echo "\nTop " . (int)$opt('top', 25) . ":\n";
foreach (array_slice($rows, 0, (int)$opt('top', 25)) as $r) printf("  #%-3d %s %-4d %-26s %s\n", $r['rank'], $r['priority'], $r['score'], $r['id'], implode(', ', array_slice($r['flags'], 0, 4)));
