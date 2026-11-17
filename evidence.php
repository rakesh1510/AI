<?php
/**
 * Evidence, verification and capability data for tool profiles (Phase 4).
 *
 * Everything here is derived from curated profiles (data/curated_profiles.json) and data/capabilities.json. Nothing is invented:
 *  - a capability is "documented" only when the sourced profile text mentions it; otherwise it is "unknown" (never "no");
 *  - confidence levels follow the review fields (human_verified, verified_on, sources, known conflicts, age);
 *  - tools without a curated profile are reported as "not reviewed".
 */

const EVIDENCE_STALE_DAYS = 90;

function capability_defs(): array { return catalog_json('capabilities.json', ['capabilities' => []])['capabilities'] ?? []; }

function evidence_level_defs(): array {
  return [
    'editor_verified'    => ['label' => 'Verified by an editor', 'short' => 'Verified', 'tone' => 'ok', 'desc' => 'An AIHubpedia editor checked these facts on the vendor\'s live pages.'],
    'verified_stale'     => ['label' => 'Verified, due for a re-check', 'short' => 'Re-check due', 'tone' => 'warn', 'desc' => 'An editor verified these facts, but more than ' . EVIDENCE_STALE_DAYS . ' days ago. Prices and plans may have changed.'],
    'evidence_collected' => ['label' => 'Sourced, awaiting editor verification', 'short' => 'Sourced', 'tone' => 'info', 'desc' => 'Facts come from the vendor sources listed on the page. A person has not yet confirmed them on the live pages.'],
    'limited'            => ['label' => 'Limited evidence', 'short' => 'Limited', 'tone' => 'warn', 'desc' => 'Few sources or conflicting information. Check the vendor\'s own pages before deciding.'],
    'unreviewed'         => ['label' => 'Not reviewed', 'short' => 'Not reviewed', 'tone' => 'muted', 'desc' => 'This is a catalogue listing. AIHubpedia has not reviewed it against vendor sources.'],
  ];
}

/**
 * Evidence summary for one tool record (as returned by load_tools()).
 * @return array{level:string,label:string,short:string,tone:string,desc:string,human_verified:bool,reviewed_on:?string,verified_on:?string,
 *   age_days:?int,stale:bool,pricing_summary:string,pricing_as_of:?string,pricing_age_days:?int,pricing_stale:bool,pricing_note:string,
 *   sources:array,source_count:int,conflicts:array,curated:bool}
 */
function tool_evidence(array $t, ?int $now = null): array {
  $defs = evidence_level_defs();
  $p = $t['curated'] ?? null;
  $base = ['human_verified' => false, 'reviewed_on' => null, 'verified_on' => null, 'age_days' => null, 'stale' => false,
    'pricing_summary' => '', 'pricing_as_of' => null, 'pricing_age_days' => null, 'pricing_stale' => false, 'pricing_note' => '',
    'sources' => [], 'source_count' => 0, 'conflicts' => [], 'curated' => false];
  if (!$p) return ['level' => 'unreviewed'] + $defs['unreviewed'] + $base;
  $now = $now ?? time();
  $r = $p['review'] ?? [];
  $human = !empty($r['human_verified']) && !empty($r['verified_on']);
  $age = profile_age_days($p, $now);
  $asOf = $p['pricing']['as_of'] ?? null;
  $pAge = ($asOf && strtotime((string)$asOf)) ? (int)floor(($now - strtotime((string)$asOf)) / 86400) : null;
  $sources = array_values(array_filter((array)($p['sources'] ?? []), fn($s) => !empty($s['url']) && sec_safe_external_url((string)$s['url']) !== null));
  $conflicts = array_values((array)($p['known_source_conflicts'] ?? []));
  $conf = strtolower((string)($p['pricing']['confidence'] ?? ''));
  $weakConf = (bool)preg_match('/disagree|not re-verified|dated|not confirmed|may be dated/', $conf);
  if ($human) $level = ($age !== null && $age > EVIDENCE_STALE_DAYS) ? 'verified_stale' : 'editor_verified';
  elseif (count($sources) < 2 || $conflicts || $weakConf) $level = 'limited';
  else $level = 'evidence_collected';
  return ['level' => $level] + $defs[$level] + [
    'human_verified' => $human, 'reviewed_on' => $r['reviewed_on'] ?? null, 'verified_on' => $r['verified_on'] ?? null,
    'age_days' => $age, 'stale' => $age !== null && $age > EVIDENCE_STALE_DAYS,
    'pricing_summary' => (string)($p['pricing']['summary'] ?? ''), 'pricing_as_of' => $asOf, 'pricing_age_days' => $pAge,
    'pricing_stale' => $pAge !== null && $pAge > EVIDENCE_STALE_DAYS, 'pricing_note' => (string)($p['pricing']['confidence'] ?? ''),
    'sources' => $sources, 'source_count' => count($sources), 'conflicts' => $conflicts, 'curated' => true,
  ];
}

/** Text of the profile fields that may document a capability (limitations are excluded on purpose). */
function capability_haystack(array $p): array {
  return [
    'key features' => implode(' | ', (array)($p['key_features'] ?? [])),
    'use cases' => implode(' | ', (array)($p['use_cases'] ?? [])),
    'summary' => (string)($p['summary'] ?? ''),
    'best for' => implode(' | ', (array)($p['best_for'] ?? [])),
    'strengths' => implode(' | ', (array)($p['strengths'] ?? [])),
  ];
}

/**
 * Documented capabilities for a tool.
 * @return array<string,array{state:string,evidence:string,field:string,label:string}>
 *   state: documented | unknown | not_reviewed | none_listed (free_plan only) | listing (free_plan on unreviewed tools)
 */
function tool_capabilities(array $t): array {
  $out = [];
  $p = $t['curated'] ?? null;
  foreach (capability_defs() as $key => $def) {
    $row = ['state' => $p ? 'unknown' : 'not_reviewed', 'evidence' => '', 'field' => '', 'label' => (string)$def['label']];
    if (($def['special'] ?? '') === 'pricing') {
      if ($p) {
        $type = strtolower((string)($p['pricing']['type'] ?? ''));
        $trial = strpos(strtolower((string)($p['pricing']['label'] ?? '')), 'trial') !== false;
        if (in_array($type, ['free', 'freemium'], true) || !empty($p['pricing']['free_option'])) { $row['state'] = 'documented'; $row['evidence'] = profile_free_option($p); $row['field'] = 'pricing'; }
        elseif ($trial) { $row['state'] = 'documented'; $row['evidence'] = 'Free trial'; $row['field'] = 'pricing'; }
        else { $row['state'] = 'none_listed'; $row['evidence'] = 'No free plan listed in the sources'; $row['field'] = 'pricing'; }
      } else {
        $pt = strtolower((string)($t['pricing_type'] ?? ''));
        if (in_array($pt, ['free', 'freemium', 'free trial'], true)) { $row['state'] = 'listing'; $row['evidence'] = 'Catalogue listing says "' . $t['pricing_type'] . '" (not reviewed)'; $row['field'] = 'listing'; }
      }
      $out[$key] = $row; continue;
    }
    if ($p) {
      foreach (capability_haystack($p) as $field => $text) {
        $lc = mb_strtolower($text);
        foreach ((array)$def['keywords'] as $kw) {
          if ($kw !== '' && strpos($lc, $kw) !== false) {
            // evidence: the profile item that mentions it
            $item = '';
            foreach (explode(' | ', $text) as $part) { if (strpos(mb_strtolower($part), $kw) !== false) { $item = trim($part); break; } }
            $row = ['state' => 'documented', 'evidence' => mb_substr($item !== '' ? $item : $text, 0, 160), 'field' => $field, 'label' => $row['label']];
            break 2;
          }
        }
      }
    }
    $out[$key] = $row;
  }
  return $out;
}

function capability_state_text(string $state): string {
  return ['documented' => 'Documented', 'unknown' => 'Unknown', 'not_reviewed' => 'Not reviewed', 'none_listed' => 'None listed', 'listing' => 'Listed, unreviewed'][$state] ?? $state;
}
function capability_state_help(string $state): string {
  return [
    'documented' => 'Mentioned in AIHubpedia\'s sourced profile.',
    'unknown' => 'Not documented in our sources. This does not mean the tool lacks it.',
    'not_reviewed' => 'No reviewed profile yet, so nothing is documented.',
    'none_listed' => 'The sources list no free plan.',
    'listing' => 'Only the unreviewed catalogue listing mentions it.',
  ][$state] ?? '';
}

/**
 * Feature-level comparison rows for two or more tools. Unknown values are explicit.
 * @return array<int,array{key:string,label:string,cells:array<int,array>,differs:bool}>
 */
function capability_matrix(array $tools, bool $onlyRelevant = true): array {
  $caps = array_map('tool_capabilities', $tools);
  $rows = [];
  foreach (capability_defs() as $key => $def) {
    $cells = []; $states = [];
    foreach ($caps as $c) { $cells[] = $c[$key]; $states[] = $c[$key]['state']; }
    if ($onlyRelevant && !array_filter($states, fn($s) => in_array($s, ['documented', 'none_listed', 'listing'], true))) continue;   // nothing known about anyone: skip the row
    $rows[] = ['key' => $key, 'label' => (string)$def['label'], 'cells' => $cells, 'differs' => count(array_unique($states)) > 1];
  }
  return $rows;
}

/** Capabilities most tools document (plans, integrations, summaries): too generic to justify calling one tool an alternative to another. */
function capability_generic_keys(): array { return ['free_plan', 'team_plans', 'integrations', 'api_access', 'summarization']; }

/**
 * Alternatives to a tool, ranked by documented overlap with transparent reasons.
 * Candidates: reviewed tools only (they are the only ones with documented capabilities).
 * @return array<int,array{tool:array,score:float,reasons:string[],shared:string[],only_candidate:string[],editor_listed:bool,pricing_differs:bool}>
 */
function tool_alternatives_ranked(array $tool, array $byId, int $limit = 6): array {
  $mine = tool_capabilities($tool);
  $generic = capability_generic_keys();
  $myDoc = array_keys(array_filter($mine, fn($c, $k) => $c['state'] === 'documented' && ($c['field'] ?? '') !== 'pricing' && !in_array($k, $generic, true), ARRAY_FILTER_USE_BOTH));
  $editor = (array)(($tool['curated'] ?? [])['alternatives'] ?? []);
  $out = [];
  foreach ($byId as $id => $c) {
    if ($id === ($tool['id'] ?? '') || empty($c['curated'])) continue;
    $cc = tool_capabilities($c);
    $cDoc = array_keys(array_filter($cc, fn($x, $k) => $x['state'] === 'documented' && ($x['field'] ?? '') !== 'pricing' && !in_array($k, $generic, true), ARRAY_FILTER_USE_BOTH));
    $shared = array_values(array_intersect($myDoc, $cDoc));
    $only = array_values(array_diff($cDoc, $myDoc));
    $sameSub = !empty($tool['subcategory']) && ($c['subcategory'] ?? '') === $tool['subcategory'];
    $sameCat = ($c['category'] ?? '') === ($tool['category'] ?? '');
    $listed = in_array($id, $editor, true);
    $score = 3 * count($shared) + ($sameSub ? 4 : 0) + ($sameCat ? 1 : 0) + ($listed ? 5 : 0);
    if ($score <= 0 || (!$sameSub && !$listed && count($shared) < 2)) continue;
    $labels = fn(array $keys) => array_map(fn($k) => (string)$mine[$k]['label'], $keys);
    $reasons = [];
    if ($listed) $reasons[] = 'Listed as an alternative in the editorial profile';
    if ($sameSub) $reasons[] = 'Same subcategory: ' . $tool['subcategory'];
    if ($shared) $reasons[] = 'Both document: ' . implode(', ', $labels($shared));
    $pa = strtolower((string)($tool['pricing_type'] ?? '')); $pb = strtolower((string)($c['pricing_type'] ?? ''));
    $out[] = ['tool' => $c, 'score' => (float)$score, 'reasons' => $reasons, 'shared' => $labels($shared),
      'only_candidate' => array_map(fn($k) => (string)$cc[$k]['label'], $only), 'editor_listed' => $listed, 'pricing_differs' => $pa !== '' && $pb !== '' && $pa !== $pb];
  }
  usort($out, fn($a, $b) => $b['score'] <=> $a['score'] ?: strcasecmp($a['tool']['name'], $b['tool']['name']));
  return array_slice($out, 0, $limit);
}

/** Small badge + dates used on cards, tool pages and comparisons. */
function render_evidence_badge(array $t, bool $withDate = true): string {
  $e = tool_evidence($t);
  $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
  $date = '';
  if ($withDate && $e['curated']) {
    $date = $e['human_verified'] ? 'verified ' . $e['verified_on'] : 'sources retrieved ' . ($e['reviewed_on'] ?? '');
  }
  return '<span class="evBadge ev-' . $h($e['tone']) . '" title="' . $h($e['desc']) . '"><span class="evDot" aria-hidden="true"></span>' . $h($e['short']) . ($date !== '' ? ' <span class="evDate">· ' . $h($date) . '</span>' : '') . '</span>';
}
