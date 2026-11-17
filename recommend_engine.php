<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly (defence in depth if web-server rules are missing)
/**
 * Deterministic, explainable recommendation engine (Phase 4, P3).
 *
 * No external AI service, no randomness, no stored personal data: the same answers always give the same result.
 * Only tools with reviewed profiles can be recommended, because only they have documented capabilities, pricing sources and limitations.
 * Capabilities that are not documented ("unknown") never add or subtract points; they are listed as unmet/unknown so the visitor can check them.
 * Paid placement, affiliate links and click popularity are NOT inputs.
 */

function recommend_config(): array { return catalog_json('recommend_config.json', []); }

/**
 * Validate and normalise wizard answers (query-string style input).
 * @return array{objective:string,role:string,experience:string,team:string,budget:string,integrations:string[],errors:string[],complete:bool}
 */
function recommend_normalize_answers(array $in): array {
  $c = recommend_config(); $errors = [];
  $pick = function (string $key, array $options, string $default = '') use ($in) { $v = (string)($in[$key] ?? ''); return isset($options[$v]) ? $v : $default; };
  $a = [
    'objective' => $pick('objective', $c['objectives'] ?? []),
    'role' => $pick('role', $c['roles'] ?? [], 'other'),
    'experience' => $pick('experience', $c['experience'] ?? [], 'intermediate'),
    'team' => $pick('team', $c['team'] ?? [], 'solo'),
    'budget' => $pick('budget', $c['budget'] ?? []),
    'integrations' => [],
  ];
  foreach ((array)($in['integrations'] ?? []) as $k) { if (is_string($k) && isset(($c['integrations'] ?? [])[$k])) $a['integrations'][] = $k; }
  $a['integrations'] = array_values(array_unique($a['integrations']));
  if ($a['objective'] === '') $errors[] = 'Choose what you want to do.';
  if ($a['budget'] === '') $errors[] = 'Choose a budget.';
  return $a + ['errors' => $errors, 'complete' => !$errors];
}

/** Evidence snippet when the tool's sourced profile mentions an integration, otherwise null. */
function recommend_integration_evidence(array $tool, string $key): ?string {
  $def = (recommend_config()['integrations'] ?? [])[$key] ?? null;
  if (!$def || empty($tool['curated'])) return null;
  if (!empty($def['cap'])) { $c = tool_capabilities($tool)[$def['cap']] ?? null; return ($c && $c['state'] === 'documented') ? $c['evidence'] : null; }
  foreach (capability_haystack($tool['curated']) as $text) {
    foreach (explode(' | ', $text) as $part) {
      foreach ((array)$def['keywords'] as $kw) { if (strpos(mb_strtolower($part), $kw) !== false) return mb_substr(trim($part), 0, 160); }
    }
  }
  return null;
}

/** @return array{score:float,reasons:string[],unknown:string[],primary:int,objective_points:int,excluded:?string} */
function recommend_score_tool(array $tool, array $a, ?array $caps = null, ?array $ev = null): array {
  $c = recommend_config(); $caps = $caps ?? tool_capabilities($tool); $ev = $ev ?? tool_evidence($tool);
  $obj = $c['objectives'][$a['objective']]; $score = 0.0; $reasons = []; $unknown = []; $primary = 0; $objPoints = 0;
  $doc = fn(string $k) => ($caps[$k]['state'] ?? '') === 'documented';
  $label = fn(string $k) => (string)($caps[$k]['label'] ?? $k);
  $ex = fn(string $k) => ' — “' . $caps[$k]['evidence'] . '”';

  if (($c['budget'][$a['budget']]['filter_free'] ?? false) && !$doc('free_plan')) return ['score' => 0.0, 'reasons' => [], 'unknown' => [], 'primary' => 0, 'objective_points' => 0, 'excluded' => 'no free plan documented'];

  foreach ($obj['caps'] as $k => $w) {
    if ($doc($k)) { $score += $w; $primary++; $objPoints += $w; $reasons[] = '+' . $w . ' ' . $label($k) . ' is documented' . $ex($k); }
    else $unknown[] = $label($k) . ' is not documented in our sources';
  }
  if (in_array($tool['subcategory'] ?? '', $obj['subcategories'] ?? [], true)) { $score += 2; $reasons[] = '+2 In the “' . $tool['subcategory'] . '” subcategory, which fits your goal'; }

  $boost = (array)(($c['roles'][$a['role']]['boost'] ?? [])) + (array)(($c['experience'][$a['experience']]['boost'] ?? []));
  foreach ((array)($c['roles'][$a['role']]['boost'] ?? []) as $k => $w) { if ($k !== 0 && $doc($k)) { $score += $w; $reasons[] = '+' . $w . ' Role adjustment: ' . $label($k) . ' is documented'; } }
  foreach ((array)($c['experience'][$a['experience']]['boost'] ?? []) as $k => $w) { if ($doc($k)) { $score += $w; $reasons[] = '+' . $w . ' Experience adjustment: ' . $label($k) . ' is documented'; } }

  $tw = (int)($c['team'][$a['team']]['team_weight'] ?? 0) + (int)($c['budget'][$a['budget']]['team_bonus'] ?? 0);
  if ($tw > 0) { if ($doc('team_plans')) { $score += $tw; $reasons[] = '+' . $tw . ' Team or business plans are documented' . $ex('team_plans'); } else $unknown[] = 'Team or business plans are not documented in our sources'; }
  if (($c['budget'][$a['budget']]['filter_free'] ?? false) && $doc('free_plan')) { $score += 2; $reasons[] = '+2 A free plan is documented' . $ex('free_plan'); }

  foreach ($a['integrations'] as $ik) {
    $snip = recommend_integration_evidence($tool, $ik); $il = $c['integrations'][$ik]['label'];
    if ($snip !== null) { $score += 2; $reasons[] = '+2 ' . $il . ' is mentioned — “' . $snip . '”'; } else $unknown[] = $il . ' integration is not documented in our sources';
  }
  $bonus = ['editor_verified' => 2, 'verified_stale' => 1, 'evidence_collected' => 1, 'limited' => 0][$ev['level']] ?? 0;
  if ($bonus > 0) { $score += $bonus; $reasons[] = '+' . $bonus . ' ' . $ev['label'] . ' (' . ($ev['human_verified'] ? 'verified' : 'sourced') . ' evidence)'; }
  return ['score' => $score, 'reasons' => $reasons, 'unknown' => array_values(array_unique($unknown)), 'primary' => $primary, 'objective_points' => $objPoints, 'excluded' => null];
}

/**
 * @param array $answers normalised answers (recommend_normalize_answers) — must be complete
 * @return array{status:string,items:array,combos:array,workflow:array,explain:array,excluded_free:int,notes:array}
 */
function recommend(array $answers, array $byId): array {
  $c = recommend_config(); $a = $answers;
  if (empty($a['complete'])) return ['status' => 'incomplete', 'items' => [], 'combos' => [], 'workflow' => [], 'explain' => [], 'excluded_free' => 0, 'notes' => $a['errors'] ?? []];
  $obj = $c['objectives'][$a['objective']];
  $rows = []; $excluded = 0;
  foreach ($byId as $id => $t) {
    if (empty($t['curated'])) continue;
    $caps = tool_capabilities($t); $ev = tool_evidence($t);
    $s = recommend_score_tool($t, $a, $caps, $ev);
    if ($s['excluded']) { if (array_sum(array_map(fn($k) => ($caps[$k]['state'] ?? '') === 'documented' ? 1 : 0, array_keys($obj['caps']))) > 0) $excluded++; continue; }
    if ($s['objective_points'] < 2) continue;                // too little of the goal is documented for this tool to recommend it
    $rows[] = $s + ['tool' => $t, 'caps' => $caps, 'evidence' => $ev];
  }
  usort($rows, fn($x, $y) => $y['score'] <=> $x['score'] ?: strcasecmp($x['tool']['name'], $y['tool']['name']));
  $rows = array_slice($rows, 0, 5);
  $needed = array_keys($obj['caps']);
  foreach ($a['integrations'] as $ik) if (!empty($c['integrations'][$ik]['cap'])) $needed[] = $c['integrations'][$ik]['cap'];
  $docNeeded = fn(array $r) => array_values(array_filter(array_unique($needed), fn($k) => ($r['caps'][$k]['state'] ?? '') === 'documented'));

  $items = [];
  $shown = array_map(fn($r) => $r['tool']['id'], $rows);
  foreach ($rows as $r) {
    $t = $r['tool']; $p = $t['curated'];
    $alts = [];
    foreach (tool_alternatives_ranked($t, $byId, 6) as $alt) { if (!in_array($alt['tool']['id'], $shown, true) && count($alts) < 2) $alts[] = ['tool' => $alt['tool'], 'reasons' => $alt['reasons']]; }
    $items[] = ['tool' => $t, 'score' => $r['score'], 'reasons' => $r['reasons'], 'unknown' => $r['unknown'], 'evidence' => $r['evidence'],
      'pricing' => ['summary' => $r['evidence']['pricing_summary'], 'as_of' => $r['evidence']['pricing_as_of'], 'stale' => $r['evidence']['pricing_stale'], 'note' => $r['evidence']['pricing_note']],
      'limitations' => array_slice((array)($p['limitations'] ?? []), 0, 2), 'alternatives' => $alts, 'covers' => $docNeeded($r)];
  }
  // Combinations: a second tool only if it documents something the first does not, for the needs you stated.
  $combos = [];
  for ($i = 0; $i < count($items); $i++) for ($j = $i + 1; $j < count($items); $j++) {
    $A = $items[$i]; $B = $items[$j];
    $onlyB = array_values(array_diff($B['covers'], $A['covers'])); $onlyA = array_values(array_diff($A['covers'], $B['covers']));
    if (!$onlyB || !$onlyA) continue;
    $labels = fn(array $ks, array $item) => array_map(fn($k) => (string)tool_capabilities($item['tool'])[$k]['label'], $ks);
    $combos[] = ['a' => $A['tool'], 'b' => $B['tool'], 'a_for' => $labels($onlyA, $A), 'b_for' => $labels($onlyB, $B), 'weight' => count($onlyA) + count($onlyB) + $A['score'] / 100 + $B['score'] / 100];
  }
  usort($combos, fn($x, $y) => $y['weight'] <=> $x['weight']);
  $combos = array_slice($combos, 0, 2);

  $t1 = $items[0]['tool']['name'] ?? 'your first choice'; $t2 = $items[1]['tool']['name'] ?? 'a second tool from the list';
  $wf = array_map(fn($l) => str_replace(['{t1}', '{t2}'], [$t1, $t2], $l), $obj['workflow']);
  if ($a['experience'] === 'beginner') array_unshift($wf, 'Set aside about 30 minutes. You only need to sign up and try one real task; do not configure anything else yet.');
  if ($a['experience'] === 'advanced') $wf[] = 'Read the API documentation and rate limits before building anything on top of a tool.';
  if ($a['team'] !== 'solo') $wf[] = 'Pilot with ' . ($c['team'][$a['team']]['pilot'] ?? 'a small group') . ' for two weeks, then decide whether to roll out.';
  $wf[] = 'Check each vendor\'s pricing page and data policy yourself. Our profiles show when they were last retrieved, and prices change.';

  $explain = ['Goal: “' . $obj['label'] . '”. Capabilities counted: ' . implode(', ', array_map(fn($k) => (capability_defs()[$k]['label'] ?? $k) . ' (' . $obj['caps'][$k] . ')', array_keys($obj['caps']))) . '.',
    'Role: ' . ($c['roles'][$a['role']]['label'] ?? '') . '. ' . ($c['roles'][$a['role']]['note'] ?? ''),
    'Experience: ' . ($c['experience'][$a['experience']]['label'] ?? '') . '. ' . ($c['experience'][$a['experience']]['note'] ?? ''),
    'Team: ' . ($c['team'][$a['team']]['label'] ?? '') . '. ' . ((int)($c['team'][$a['team']]['team_weight'] ?? 0) > 0 ? 'Documented team plans add points.' : 'No team adjustment.'),
    'Budget: ' . ($c['budget'][$a['budget']]['label'] ?? '') . '. We can only check whether a free plan is documented; exact prices are shown as stated in the sources.',
    'Integrations: ' . ($a['integrations'] ? implode(', ', array_map(fn($k) => $c['integrations'][$k]['label'], $a['integrations'])) : 'none selected') . '.',
    'Not used: paid placement, affiliate links, popularity or ratings. Only tools with reviewed, sourced profiles are considered.'];
  $status = count($items) >= 3 ? 'ok' : (count($items) >= 1 ? 'few' : 'none');
  $notes = [];
  if ($excluded) $notes[] = $excluded . ' reviewed tool' . ($excluded === 1 ? '' : 's') . ' documenting your goal ' . ($excluded === 1 ? 'was' : 'were') . ' left out because no free plan is documented.';
  return ['status' => $status, 'items' => $items, 'combos' => $combos, 'workflow' => $wf, 'explain' => $explain, 'excluded_free' => $excluded, 'notes' => $notes];
}
