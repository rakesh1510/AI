<?php
/**
 * Database-powered discovery: ranked tool search, suggestions and goal-based entry points.
 *
 * This is deliberately NOT an AI chat backend. It ranks the catalogue with transparent rules (name, subcategory,
 * tags, one-line description, reviewed-profile boost, click popularity). A future AI model integration should live
 * behind a separate endpoint and can reuse search_tools_ranked() for retrieval.
 */

function search_normalize(string $s): string {
  $s = mb_strtolower($s, 'UTF-8');
  $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
  return trim(preg_replace('/\s+/u', ' ', $s));
}

/** @return string[] tokens of at least 2 characters (a single 1-character query yields no tokens) */
function search_tokens(string $q): array {
  $out = [];
  foreach (explode(' ', search_normalize($q)) as $t) { if (mb_strlen($t) >= 2) $out[] = $t; }
  return array_values(array_unique($out));
}

/** Word-prefix containment: "tran" matches "transcription tool" and "otter transcribe". */
function search_has_word_prefix(string $haystack, string $needle): bool {
  return $needle !== '' && (strpos(' ' . $haystack, ' ' . $needle) !== false);
}

/**
 * Rank tools for a query.
 * @param array $tools  catalogue records (load_tools()['tools'])
 * @return array<int,array{tool:array,score:float}> best first
 */
function search_tools_ranked(array $tools, string $q, int $limit = 24, array $filters = []): array {
  $qn = search_normalize($q);
  $tokens = search_tokens($q);
  if ($qn === '' || (!$tokens && mb_strlen($qn) < 2)) return [];
  if (!$tokens) $tokens = [$qn];
  $clicks = function_exists('all_click_counts') ? all_click_counts() : [];
  $results = []; $partial = [];
  foreach ($tools as $t) {
    if (!empty($filters['category']) && ($t['category'] ?? '') !== $filters['category']) continue;
    if (!empty($filters['pricing_type']) && strcasecmp((string)($t['pricing_type'] ?? ''), $filters['pricing_type']) !== 0) continue;
    $name = search_normalize((string)($t['name'] ?? ''));
    $slug = search_normalize((string)($t['id'] ?? ''));
    $sub = search_normalize((string)($t['subcategory'] ?? ''));
    $cat = search_normalize((string)($t['category'] ?? ''));
    $tags = search_normalize(implode(' ', (array)($t['tags'] ?? [])));
    $short = search_normalize((string)($t['short'] ?? ''));
    $uses = search_normalize(implode(' ', array_merge((array)($t['use_cases'] ?? []), (array)($t['features'] ?? []))));
    $desc = search_normalize((string)($t['description'] ?? ''));
    $score = 0.0;
    if ($qn === $name || $qn === $slug) $score += 100;
    elseif (strpos($name, $qn) === 0) $score += 70;
    elseif (search_has_word_prefix($name, $qn)) $score += 50;
    elseif (strpos($name, $qn) !== false) $score += 35;
    $matchedTokens = 0; $tokenScore = 0.0;
    foreach ($tokens as $tok) {
      $m = 0.0;
      if (search_has_word_prefix($name, $tok)) $m = max($m, 16);
      elseif (strpos($name, $tok) !== false) $m = max($m, 10);
      if (search_has_word_prefix($sub, $tok)) $m = max($m, 9);
      if (search_has_word_prefix($cat, $tok)) $m = max($m, 6);
      if (search_has_word_prefix($tags, $tok)) $m = max($m, 4);
      if (search_has_word_prefix($short, $tok)) $m = max($m, 3);
      if (search_has_word_prefix($uses, $tok)) $m = max($m, 2);
      if (search_has_word_prefix($desc, $tok)) $m = max($m, 1);
      if ($m > 0.0) { $matchedTokens++; $tokenScore += $m; }
    }
    if ($matchedTokens === 0) continue;
    $score += $tokenScore;
    if (!empty($t['curated'])) $score += 10;
    $score += min(6.0, log(1 + (int)($clicks[$t['id']] ?? 0)) * 1.5);
    $row = ['tool' => $t, 'score' => $score];
    if ($matchedTokens === count($tokens)) $results[] = $row; else $partial[] = $row;
  }
  // Multi-word queries with no tool matching every word fall back to tools matching some of them, best first.
  if (!$results && $partial) { foreach ($partial as &$p) $p['partial'] = true; unset($p); $results = $partial; }
  // Typo tolerance: only when nothing matched, compare against tool names.
  if (!$results && mb_strlen($qn) >= 4) {
    foreach ($tools as $t) {
      if (!empty($filters['category']) && ($t['category'] ?? '') !== $filters['category']) continue;
      $name = search_normalize((string)($t['name'] ?? ''));
      $best = levenshtein(substr($qn, 0, 40), substr($name, 0, 40));
      foreach (explode(' ', $name) as $w) { if ($w !== '') $best = min($best, levenshtein(substr($qn, 0, 40), substr($w, 0, 40))); }
      if ($best <= 2) $results[] = ['tool' => $t, 'score' => 20 - $best * 5 + (!empty($t['curated']) ? 5 : 0), 'fuzzy' => true];
    }
  }
  usort($results, fn($a, $b) => $b['score'] <=> $a['score'] ?: strcasecmp($a['tool']['name'], $b['tool']['name']));
  return array_slice($results, 0, $limit);
}

/** Goal-based entry points shown on the homepage and in empty search states. Only goals with tools are returned. */
function discovery_intents(array $tools): array {
  $defs = [
    ['Write & edit', 'pen', 'Writing', ''],
    ['Code & build', 'code', 'Coding', ''],
    ['Images & design', 'image', 'Image', ''],
    ['Meetings & transcripts', 'mic', 'Productivity', 'Meetings & transcription'],
    ['Research & answers', 'search', 'Research', 'AI search & answers'],
    ['Chat assistants', 'chat', 'Chatbots', 'General AI assistants'],
    ['Automate work', 'bolt', 'Automation', ''],
    ['Business & finance', 'briefcase', 'Business', ''],
    ['Marketing & SEO', 'chart', 'Marketing', ''],
    ['Video & audio', 'play', 'Video', ''],
  ];
  $out = [];
  foreach ($defs as [$label, $icon, $cat, $sub]) {
    $n = 0;
    foreach ($tools as $t) { if (($t['category'] ?? '') === $cat && ($sub === '' || ($t['subcategory'] ?? '') === $sub)) $n++; }
    if ($n === 0) continue;
    $out[] = ['label' => $label, 'icon' => $icon, 'count' => $n, 'url' => category_path($cat, 1, $sub), 'category' => $cat, 'subcategory' => $sub];
  }
  return $out;
}

/** JSON payload for the suggestion dropdown. */
function search_suggest_payload(array $tools, string $q, int $toolLimit = 6): array {
  $ranked = search_tools_ranked($tools, $q, $toolLimit);
  $items = [];
  foreach ($ranked as $r) {
    $t = $r['tool'];
    $items[] = ['type' => 'tool', 'id' => $t['id'], 'label' => $t['name'], 'meta' => trim($t['category'] . (!empty($t['subcategory']) ? ' · ' . $t['subcategory'] : '')),
      'pricing' => (string)($t['pricing'] ?? ''), 'reviewed' => !empty($t['curated']), 'url' => tool_path($t['id'])];
  }
  $qn = search_normalize($q);
  $cats = [];
  if ($qn !== '') {
    $counts = [];
    foreach ($tools as $t) { $k = $t['category'] . '|' . ($t['subcategory'] ?? ''); $counts[$k] = ($counts[$k] ?? 0) + 1; }
    foreach (taxonomy()['categories'] as $cat => $def) {
      if ($cat === CATALOG_UNCLASSIFIED) continue;
      if (search_has_word_prefix(search_normalize($cat), $qn)) {
        $n = array_sum(array_map(fn($k, $v) => strpos($k, $cat . '|') === 0 ? $v : 0, array_keys($counts), $counts));
        if ($n) $cats[] = ['type' => 'category', 'label' => $cat . ' AI tools', 'meta' => seo_plural($n, 'tool'), 'url' => category_path($cat)];
      }
      foreach ($def['subcategories'] as $sub => $_) {
        if (search_has_word_prefix(search_normalize($sub), $qn) && ($n = $counts[$cat . '|' . $sub] ?? 0) >= 3) {
          $cats[] = ['type' => 'category', 'label' => $sub, 'meta' => $cat . ' · ' . seo_plural($n, 'tool'), 'url' => category_path($cat, 1, $sub)];
        }
      }
    }
  }
  $guides = [];
  foreach (guides_all() as $slug => $g) {
    $hay = search_normalize($g['title'] . ' ' . $g['h1']);
    $ok = $qn !== '';
    foreach (search_tokens($q) ?: [$qn] as $tok) { if (!search_has_word_prefix($hay, $tok)) { $ok = false; break; } }
    if ($ok) $guides[] = ['type' => 'guide', 'label' => $g['h1'], 'meta' => 'Guide', 'url' => guide_path($slug)];
  }
  return ['q' => $q, 'items' => array_merge($items, array_slice($cats, 0, 3), array_slice($guides, 0, 2))];
}
