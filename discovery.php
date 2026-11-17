<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly (defence in depth if web-server rules are missing)
/**
 * Database-powered discovery: ranked tool search, suggestions and goal-based entry points.
 *
 * This is deliberately NOT an AI chat backend. It ranks the catalogue with transparent rules (name, subcategory,
 * tags, one-line description, reviewed-profile boost, click popularity). A future AI model integration should live
 * behind a separate endpoint and can reuse search_tools_ranked() for retrieval.
 */

function search_normalize(string $s): string {
  static $memo = [];   // pure function: memoised because the same tool text is normalised for every query
  if (isset($memo[$s])) return $memo[$s];
  if (count($memo) > 30000) $memo = [];
  $o = mb_strtolower($s, 'UTF-8');
  $o = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $o);
  return $memo[$s] = trim(preg_replace('/\s+/u', ' ', $o));
}

/** @return string[] tokens of at least 2 characters (a single 1-character query yields no tokens) */
function search_tokens(string $q): array {
  $out = [];
  foreach (explode(' ', search_normalize($q)) as $t) { if (mb_strlen($t) >= 2) $out[] = $t; }
  $out = array_values(array_unique($out));
  // Filler words ("tools to transcribe") must not match random descriptions; keep them only when nothing else is left.
  $stop = ['tool', 'tools', 'best', 'top', 'ai', 'to', 'for', 'and', 'the', 'with', 'of', 'in', 'on', 'my', 'your', 'an', 'is', 'it', 'at', 'by', 'from', 'or', 'are', 'me', 'do', 'can', 'how'];
  $kept = array_values(array_filter($out, fn($t) => !in_array($t, $stop, true)));
  return $kept ?: $out;
}

/** Word-prefix containment: "tran" matches "transcription tool" and "otter transcribe". */
function search_has_word_prefix(string $haystack, string $needle): bool {
  return $needle !== '' && (strpos(' ' . $haystack, ' ' . $needle) !== false);
}

/** Editorial synonym groups (data/search_synonyms.json) as normalised term lists. */
function search_synonym_groups(): array {
  static $groups = null;
  if ($groups !== null) return $groups;
  $groups = [];
  foreach ((catalog_json('search_synonyms.json', ['groups' => []])['groups'] ?? []) as $g) {
    $terms = array_values(array_unique(array_filter(array_map('search_normalize', (array)$g), 'strlen')));
    if (count($terms) >= 2) $groups[] = $terms;
  }
  return $groups;
}

/**
 * Related terms for each query token: token => list of [term, from] where "from" is the group term that triggered it.
 * A group is triggered by a single-word term equal to the token, or by a multi-word term that appears as a phrase in the query.
 */
function search_expansions(string $qn, array $tokens): array {
  $out = [];
  foreach (search_synonym_groups() as $terms) {
    foreach ($terms as $trigger) {
      $words = explode(' ', $trigger);
      $phrase = count($words) > 1;
      if ($phrase && !search_has_word_prefix($qn . ' ', $trigger . ' ')) continue;
      if ($phrase) {
        // A phrase is one concept: attach the related terms to its first query word; the other words are covered by it.
        $inQuery = array_values(array_filter($words, fn($w) => in_array($w, $tokens, true)));
        if (!$inQuery) continue;
        $anchor = $inQuery[0];
        foreach ($terms as $alt) { if ($alt !== $trigger) $out[$anchor][$alt] = $trigger; }
        foreach (array_slice($inQuery, 1) as $w) $out['__covered'][$w] = $anchor;
      } elseif (in_array($trigger, $tokens, true)) {
        foreach ($terms as $alt) { if ($alt !== $trigger) $out[$trigger][$alt] = $trigger; }
      }
    }
  }
  $covered = $out['__covered'] ?? []; unset($out['__covered']);
  $res = array_map(fn($m) => array_map(fn($alt, $from) => ['term' => (string)$alt, 'from' => $from], array_keys($m), $m), $out);
  if ($covered) $res['__covered'] = $covered;
  return $res;
}

/** Weight of the best field that contains $needle as a word prefix, with a short field label for explanations. */
function search_field_match(array $f, string $needle): array {
  $best = [0.0, ''];
  $try = function (float $w, string $label, bool $hit) use (&$best) { if ($hit && $w > $best[0]) $best = [$w, $label]; };
  $try(16, 'name', search_has_word_prefix($f['name'], $needle));
  $try(10, 'name', strpos($f['name'], $needle) !== false);
  $try(9, 'subcategory', search_has_word_prefix($f['sub'], $needle));
  $try(6, 'category', search_has_word_prefix($f['cat'], $needle));
  $try(4, 'tags', search_has_word_prefix($f['tags'], $needle));
  $try(3, 'one-line description', search_has_word_prefix($f['short'], $needle));
  $try(2, 'use cases and features', search_has_word_prefix($f['uses'], $needle));
  $try(1, 'description', search_has_word_prefix($f['desc'], $needle));
  return $best;
}

/** Normalised searchable fields of one tool, memoised by content so repeated queries do not re-normalise the whole catalogue. */
function search_tool_fields(array $t): array {
  static $memo = [];
  $key = (string)($t['id'] ?? '') . '|' . crc32(json_encode([$t['name'] ?? '', $t['subcategory'] ?? '', $t['category'] ?? '', $t['tags'] ?? [], $t['short'] ?? '', $t['use_cases'] ?? [], $t['features'] ?? [], $t['description'] ?? '']));
  if (isset($memo[$key])) return $memo[$key];
  if (count($memo) > 20000) $memo = [];
  return $memo[$key] = [
    'name' => search_normalize((string)($t['name'] ?? '')), 'slug' => search_normalize((string)($t['id'] ?? '')),
    'sub' => search_normalize((string)($t['subcategory'] ?? '')), 'cat' => search_normalize((string)($t['category'] ?? '')),
    'tags' => search_normalize(implode(' ', (array)($t['tags'] ?? []))), 'short' => search_normalize((string)($t['short'] ?? '')),
    'uses' => search_normalize(implode(' ', array_merge(tool_nongeneric_items((array)($t['use_cases'] ?? [])), tool_nongeneric_items((array)($t['features'] ?? []))))),   // template phrases are identical on ~490 tools: not evidence of relevance
    'desc' => search_normalize((string)($t['description'] ?? '')),
  ];
}

/**
 * Rank tools for a query with transparent rules. Each result carries "reasons": plain-language statements of why it matched.
 * @param array $tools  catalogue records (load_tools()['tools'])
 * @return array<int,array{tool:array,score:float,reasons:string[]}> best first
 */
function search_tools_ranked(array $tools, string $q, int $limit = 24, array $filters = []): array {
  $qn = search_normalize($q);
  $tokens = search_tokens($q);
  if ($qn === '' || (!$tokens && mb_strlen($qn) < 2)) return [];
  if (!$tokens) $tokens = [$qn];
  // Task verbs describe the visitor's action, not the product: "write code" is about code tools. Dropped from ranking only when a more specific word remains.
  $specific = array_values(array_diff($tokens, ['write', 'make', 'create', 'generate', 'find', 'build', 'get', 'use', 'help', 'need', 'want']));
  if ($specific) $tokens = $specific;
  $clicks = function_exists('all_click_counts') ? all_click_counts() : [];
  $expansions = search_expansions($qn, $tokens);
  $covered = $expansions['__covered'] ?? []; unset($expansions['__covered']);
  $results = []; $partial = [];
  foreach ($tools as $t) {
    if (!empty($filters['category']) && ($t['category'] ?? '') !== $filters['category']) continue;
    if (!empty($filters['pricing_type']) && strcasecmp((string)($t['pricing_type'] ?? ''), $filters['pricing_type']) !== 0) continue;
    $f = search_tool_fields($t);
    $score = 0.0; $reasons = [];
    if ($qn === $f['name'] || $qn === $f['slug']) { $score += 100; $reasons[] = 'Exact name match'; }
    elseif (strpos($f['name'], $qn) === 0) { $score += 70; $reasons[] = 'Name starts with your search'; }
    elseif (search_has_word_prefix($f['name'], $qn)) { $score += 50; $reasons[] = 'A word in the name matches'; }
    elseif (strpos($f['name'], $qn) !== false) { $score += 35; $reasons[] = 'Name contains your search'; }
    $matchedTokens = 0; $tokenScore = 0.0; $altHit = []; $strong = $score > 0.0;   // strong = matched in name, subcategory, category or tags
    foreach ($tokens as $tok) {
      if (isset($covered[$tok]) && !empty($altHit[$covered[$tok]])) { $matchedTokens++; continue; }   // part of a phrase already matched through a related term
      [$m, $label] = search_field_match($f, $tok);
      if ($m > 0.0) {
        $matchedTokens++; $tokenScore += $m; if ($m >= 4) $strong = true;
        if ($label !== 'name' || $score === 0.0) $reasons[] = 'Matched “' . $tok . '” in ' . $label;
        continue;
      }
      // Related terms (editorial synonyms): weaker than a direct match and always labelled.
      $bestAlt = [0.0, '', ''];
      foreach (($expansions[$tok] ?? []) as $ex) {
        [$am, $al] = search_field_match($f, $ex['term']);
        if ($am * 0.6 > $bestAlt[0]) $bestAlt = [$am * 0.6, $al, $ex['term']];
      }
      if ($bestAlt[0] > 0.0) { $altHit[$tok] = true; $matchedTokens++; $tokenScore += $bestAlt[0]; $reasons[] = 'Related term “' . $bestAlt[2] . '” found in ' . $bestAlt[1] . ' (for “' . $tok . '”)'; }
    }
    if ($matchedTokens === 0) continue;
    $score += $tokenScore;
    // Reviewed profiles are preferred, but a mention in a feature list must not outrank a tool that is actually in the searched category.
    if (!empty($t['curated'])) { $score += $strong ? 10 : 3; $reasons[] = 'Has a reviewed, sourced profile'; }
    $boost = min(6.0, log(1 + (int)($clicks[$t['id']] ?? 0)) * 1.5);
    if ($boost >= 1.5) $reasons[] = 'Popular with visitors';
    $score += $boost;
    $row = ['tool' => $t, 'score' => $score, 'reasons' => array_values(array_unique($reasons))];
    if ($matchedTokens === count($tokens)) $results[] = $row; else $partial[] = $row;
  }
  // Multi-word queries with no tool matching every word fall back to tools matching some of them, best first.
  if (!$results && $partial) { foreach ($partial as &$p) { $p['partial'] = true; $p['reasons'][] = 'Matches some of your words, not all'; } unset($p); $results = $partial; }
  // Typo tolerance: only when nothing matched, compare against tool names.
  if (!$results && mb_strlen($qn) >= 4) {
    foreach ($tools as $t) {
      if (!empty($filters['category']) && ($t['category'] ?? '') !== $filters['category']) continue;
      $name = search_normalize((string)($t['name'] ?? ''));
      $best = levenshtein(substr($qn, 0, 40), substr($name, 0, 40));
      foreach (explode(' ', $name) as $w) { if ($w !== '') $best = min($best, levenshtein(substr($qn, 0, 40), substr($w, 0, 40))); }
      if ($best <= 2) $results[] = ['tool' => $t, 'score' => 20 - $best * 5 + (!empty($t['curated']) ? 5 : 0), 'fuzzy' => true, 'reasons' => ['Name is similar to your search (possible typo)']];
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

// ---------------------------------------------------------------------------
// Task / business-problem discovery (data/solutions.json)
// ---------------------------------------------------------------------------
function solutions_all(): array { return catalog_json('solutions.json', ['problems' => []])['problems'] ?? []; }
function solution_get(string $slug): ?array { $p = solutions_all()[$slug] ?? null; return $p ? $p + ['slug' => $slug] : null; }
function solution_path(string $slug): string { return '/solutions.php?problem=' . rawurlencode($slug); }
function solution_url(string $slug): string { return site_url(solution_path($slug)); }

/** Ranked tools for a problem: same transparent rules as search, so every result carries its reasons. */
const SOLUTION_PAGE_LIMIT = 12;   // tools shown on a problem page; the evidence gate counts exactly these
function solution_results(array $tools, array $problem, int $limit = SOLUTION_PAGE_LIMIT): array {
  return search_tools_ranked($tools, (string)$problem['query'], $limit);
}

/** Evidence gate: a problem page is indexable only with at least three reviewed, sourced tools among the results. */
function solution_reviewed_count(array $results): int {
  return count(array_filter($results, fn($r) => !empty($r['tool']['curated'])));
}
function solution_indexable(array $results): bool { return solution_reviewed_count($results) >= 3; }

/** Ranked rows kept in the cache per problem: enough for the hub counts (40) and the page (12). */
const SOLUTION_CACHE_ROWS = 40;

/**
 * slug => [['id','score','reasons','partial'], ...] for every problem, computed once per catalogue/code version (see cache.php).
 * @return array{0:array,1:string} [data, 'hit'|'miss'|'off']
 */
function solution_pages_data(): array {
  static $memo = null;
  if ($memo !== null) return $memo;
  return $memo = cache_remember('solutions', function () {
    $tools = array_values(tools_by_id()); $out = [];
    foreach (solutions_all() as $slug => $p) {
      $rows = [];
      foreach (solution_results($tools, $p, SOLUTION_CACHE_ROWS) as $r) $rows[] = ['id' => (string)$r['tool']['id'], 'score' => round((float)$r['score'], 4), 'reasons' => array_values($r['reasons'] ?? []), 'partial' => !empty($r['partial'])];
      $out[$slug] = $rows;
    }
    return $out;
  });
}

/** Same shape as solution_results() for the real catalogue, served from the cache. Tool records are always read fresh. */
function solution_results_cached(string $slug, int $limit = SOLUTION_PAGE_LIMIT): array {
  [$data, $state] = solution_pages_data(); $byId = tools_by_id(); $out = [];
  if ($state === 'off') {   // no usable cache (disabled, unwritable or unsafe directory): compute only what this request needs, never all ten problems
    static $live = [];
    if (!isset($live[$slug])) { $p = solution_get($slug); $live[$slug] = $p ? solution_results(array_values($byId), $p, SOLUTION_CACHE_ROWS) : []; }
    return array_slice($live[$slug], 0, $limit);
  }
  foreach (array_slice($data[$slug] ?? [], 0, $limit) as $row) {
    if (!isset($byId[$row['id']])) continue;
    $out[] = ['tool' => $byId[$row['id']], 'score' => $row['score'], 'reasons' => $row['reasons']] + ($row['partial'] ? ['partial' => true] : []);
  }
  return $out;
}

/** Slugs of indexable problem pages (used by the sitemap). */
function solutions_indexable_slugs(?array $tools = null): array {
  $out = [];
  foreach (array_keys(solutions_all()) as $slug) { if (solution_indexable(solution_results_cached($slug, SOLUTION_PAGE_LIMIT))) $out[] = $slug; }
  return $out;
}
