<?php
/**
 * Search-quality evaluation against tests/fixtures/search_quality.json (READ-ONLY, no network, no database writes).
 * Usage: php scripts/search_quality.php [--json] [--verbose]
 * Relevance is judged by taxonomy (see the fixture note). Metrics: precision@5, success@3 (a relevant result in the top 3), MRR.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../helpers.php';
function search_quality_eval(array $fx, array $tools): array {
  $rows = []; $p5 = $s3 = $mrr = 0.0;
  foreach ($fx['queries'] as $c) {
    $r = search_tools_ranked(array_values($tools), $c['q'], 10);
    $rel = array_flip($c['relevant']); $hits = []; $first = null;
    foreach ($r as $i => $x) { $k = ($x['tool']['category'] ?? '') . ' / ' . ($x['tool']['subcategory'] ?? ''); $h = isset($rel[$k]); $hits[] = $h; if ($h && $first === null) $first = $i + 1; }
    $top5 = array_slice($hits, 0, 5); $prec = count($top5) ? array_sum($top5) / 5 : 0.0;
    $rows[] = ['q' => $c['q'], 'results' => count($r), 'p5' => $prec, 'first_relevant_rank' => $first, 'top' => array_map(fn($x) => $x['tool']['id'], array_slice($r, 0, 5))];
    $p5 += $prec; $s3 += ($first !== null && $first <= 3) ? 1 : 0; $mrr += $first ? 1 / $first : 0;
  }
  $n = max(1, count($fx['queries']));
  return ['n' => count($fx['queries']), 'p_at_5' => round($p5 / $n, 3), 'success_at_3' => round($s3 / $n, 3), 'mrr' => round($mrr / $n, 3), 'rows' => $rows];
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
  $fx = json_decode(file_get_contents(__DIR__ . '/../tests/fixtures/search_quality.json'), true);
  $res = search_quality_eval($fx, tools_by_id());
  if (in_array('--json', $argv, true)) { echo json_encode($res, JSON_PRETTY_PRINT), "\n"; exit; }
  printf("queries=%d  precision@5=%.3f  success@3=%.3f  MRR=%.3f\n", $res['n'], $res['p_at_5'], $res['success_at_3'], $res['mrr']);
  if (in_array('--verbose', $argv, true)) foreach ($res['rows'] as $r) printf("  %-28s p5=%.1f first=%s  %s\n", $r['q'], $r['p5'], $r['first_relevant_rank'] ?? '-', implode(',', $r['top']));
}
