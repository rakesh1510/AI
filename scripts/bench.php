<?php
/**
 * PHP execution-time benchmark for the discovery code paths (CLI, no network). Median of N runs, milliseconds.
 * Usage: php scripts/bench.php [--n=15] [--synthetic=0] [--json]
 *   --synthetic=N  replaces the catalogue with N generated tools (real reviewed profiles are kept) to test scaling.
 * Set AIHUB_CACHE=0 to measure without the cache.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$opt = function (string $k, $d) use ($argv) { foreach ($argv as $a) if (strpos($a, "--$k=") === 0) return substr($a, strlen($k) + 3); return $d; };
$n = max(3, (int)$opt('n', 15)); $synth = (int)$opt('synthetic', 0); $json = in_array('--json', $argv, true);
require_once __DIR__ . '/../helpers.php';
register_shutdown_function(function () { foreach ((array)glob(sys_get_temp_dir() . '/aihub_bench_cache_' . getmypid() . '/*') as $f) @unlink($f); @rmdir(sys_get_temp_dir() . '/aihub_bench_cache_' . getmypid()); });
if ($synth > 0) {
  putenv('AIHUB_CACHE_DIR=' . sys_get_temp_dir() . '/aihub_bench_cache_' . getmypid());   // the cache is keyed by data files: synthetic catalogues must never share it with the real one
  mt_srand(7); $words = ['transcription', 'writing', 'meeting', 'image', 'code', 'research', 'video', 'audio', 'support', 'automation', 'seo', 'pdf', 'translate', 'email', 'schedule'];
  $tools = array_values(array_filter(load_tools()['tools'], fn($t) => !empty($t['curated'])));
  for ($i = 0; count($tools) < $synth; $i++) {
    $w = [$words[mt_rand(0, 14)], $words[mt_rand(0, 14)], $words[mt_rand(0, 14)]];
    $tools[] = ['id' => 'synthetic-' . $i, 'name' => 'Synth ' . ucfirst($w[0]) . ' ' . $i, 'category' => 'Productivity', 'subcategory' => 'Meetings & transcription', 'short' => "AI $w[0] and $w[1] helper $i", 'description' => "A synthetic tool for $w[2] $i", 'tags' => [$w[0], $w[1]], 'features' => [], 'use_cases' => [$w[2]], 'pricing' => 'Freemium', 'pricing_type' => 'Freemium', 'website' => 'https://example.com/' . $i];
  }
  global $AIHUB_TOOLS_CACHE; $AIHUB_TOOLS_CACHE = ['tools' => $tools, 'categories' => ['Productivity'], 'source' => 'synthetic'];
}
$byId = tools_by_id(); $tools = array_values($byId);
$unrevList = array_filter($byId, fn($t) => empty($t['curated'])); $unrev = reset($unrevList) ?: ['id' => 'x'];
$time = function (callable $f) use ($n) { $t = []; for ($i = 0; $i < $n; $i++) { $s = hrtime(true); $f(); $t[] = (hrtime(true) - $s) / 1e6; } sort($t); return ['median_ms' => round($t[intdiv(count($t), 2)], 2), 'first_ms' => round($t[0] === $t[0] ? $t[0] : 0, 2), 'max_ms' => round(end($t), 2)]; };
$firstRun = function (callable $f) { $s = hrtime(true); $f(); return round((hrtime(true) - $s) / 1e6, 2); };
$cases = [
  'related_links(reviewed tool)' => fn() => tool_related_links($byId['otter-ai'], $byId),
  'related_links(unreviewed tool)' => fn() => tool_related_links($unrev, $byId),
  'solutions hub (10 problems)' => function () use ($tools) { foreach (solutions_all() as $p) solution_results($tools, $p, 40); },
  'solutions hub via cache (10 problems)' => function () { foreach (array_keys(solutions_all()) as $s) solution_results_cached($s, SOLUTION_CACHE_ROWS); },
  'one problem page' => fn() => solution_results($tools, solution_get('transcribe-meetings-and-interviews')),
  'search "transcription meeting"' => fn() => search_tools_ranked($tools, 'transcription meeting', 24),
  'wizard (meetings, small team)' => fn() => recommend(recommend_normalize_answers(['objective' => 'meetings', 'budget' => 'flexible', 'team' => 'small']), $byId),
  'pricing groups' => fn() => pricing_groups($byId),
];
$out = ['php' => PHP_VERSION, 'tools' => count($byId), 'reviewed' => count(array_filter($byId, fn($t) => !empty($t['curated']))), 'runs' => $n, 'cache' => getenv('AIHUB_CACHE') === '0' ? 'off' : 'default', 'cases' => []];
foreach ($cases as $label => $f) { $first = $firstRun($f); $out['cases'][$label] = ['first_call_ms' => $first] + $time($f); }
if ($json) { echo json_encode($out, JSON_PRETTY_PRINT), "\n"; exit; }
printf("PHP %s | %d tools (%d reviewed) | %d runs | cache: %s\n", $out['php'], $out['tools'], $out['reviewed'], $n, $out['cache']);
printf("%-34s %12s %12s\n", 'case', 'first call', 'median');
foreach ($out['cases'] as $l => $r) printf("%-34s %9.2f ms %9.2f ms\n", $l, $r['first_call_ms'], $r['median_ms']);
