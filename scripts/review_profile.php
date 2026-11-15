<?php
/**
 * Mark a curated profile as verified by a human editor.
 * Usage: php scripts/review_profile.php <tool-id> --by="Editor Name" [--confirm]
 * Without --confirm it only prints what would change. Run it only after re-checking every source on the live vendor pages.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../catalog.php';
require_once __DIR__ . '/../seo.php';
$id = $argv[1] ?? '';
$by = ''; foreach ($argv as $a) if (strpos($a, '--by=') === 0) $by = trim(substr($a, 5), "\"'");
$confirm = in_array('--confirm', $argv, true);
$path = __DIR__ . '/../data/curated_profiles.json';
$doc = json_decode(file_get_contents($path), true);
if ($id === '' || !isset($doc['profiles'][$id])) { fwrite(STDERR, "Unknown profile id.\n"); exit(2); }
if ($by === '') { fwrite(STDERR, "--by=\"Editor Name\" is required.\n"); exit(2); }
$today = date('Y-m-d');
$p = &$doc['profiles'][$id];
foreach ($p['sources'] as &$s) $s['retrieved'] = $today;
unset($s);
$p['pricing']['as_of'] = $today;
$p['review'] = ['status' => 'verified', 'reviewed_on' => $today, 'reviewed_by' => $by, 'human_verified' => true, 'verified_on' => $today, 'notes' => 'Confirmed by an editor on the live vendor pages listed under sources.'];
echo ($confirm ? 'Verifying' : 'Would verify') . " '$id' as of $today by $by (sources and pricing dates updated).\n";
if ($confirm) { file_put_contents($path, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"); echo "Saved.\n"; }
