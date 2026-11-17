<?php
/**
 * Prioritised editorial backlog for evidence-gated content (READ-ONLY, deterministic, no network).
 * Usage: php scripts/content_backlog.php [--csv=path] [--json]
 * Every row names the proof a publisher must hold before the page may exist or be indexed. The gate column evaluates the CURRENT state of the
 * catalogue with editorial_gate(); nothing here publishes a page, marks a tool verified or states a search volume (volumes are "unmeasured").
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../helpers.php';
$opt = function (string $k, $d = '') use ($argv) { foreach ($argv as $a) if (strpos($a, "--$k=") === 0) return substr($a, strlen($k) + 3); return $d; };
$byId = tools_by_id(); $tools = array_values($byId);
$rows = []; $n = 0;
$add = function (array $r) use (&$rows, &$n) { $rows[] = $r + ['id' => sprintf('B%03d', ++$n)]; };
$vol = 'unmeasured: needs Search Console data after launch';
$gateText = function (string $tpl, array $set): array { $g = editorial_gate($tpl, ['tools' => $set]); return [$g['pass'] ? 'gate_passes_awaiting_editor' : 'blocked', implode('; ', $g['reasons'])]; };
$verifiedAny = fn(array $set) => (bool)array_filter($set, fn($t) => tool_evidence($t)['human_verified']);

// 1. Existing problem pages (live behind the evidence gate): keep them correct
foreach (solutions_all() as $slug => $p) {
  $set = array_map(fn($r) => $r['tool'], solution_results_cached($slug, 12)); [$st, $why] = $gateText('best-for-task', $set);
  $rev = count(array_filter($set, fn($t) => !empty($t['curated'])));
  $add(['type' => 'task', 'working_title' => $p['title'], 'search_intent' => 'A person has a job to do and wants a shortlist with reasons', 'audience' => 'Individuals and small teams',
    'proof_needed' => 'Each listed tool: vendor pricing and feature pages (2+ sources), editor verification; hands-on test for any "best for" claim',
    'differentiation' => 'Shows documented vs unknown per tool, reasons per result, no paid placement influence', 'internal_links' => 'tools in results; /compare.php; /recommend.php',
    'conversion_goal' => 'Wizard start or newsletter sign-up', 'publication_gate' => 'best-for-task: 3+ tools, 3+ reviewed, 2+ sources each', 'gate_now' => $st . ($rev < 3 ? ' (only ' . $rev . ' reviewed)' : ''), 'blocked_by' => $why,
    'state' => 'exists (' . (solution_indexable(solution_results_cached($slug, 12)) ? 'indexable' : 'noindex') . ')', 'verification_state' => $verifiedAny($set) ? 'partly verified' : 'no tool verified by an editor', 'priority_score' => 60 + $rev * 3, 'volume' => $vol]);
}
// 2. Proposed task pages from well-populated subcategories (only where reviewed tools could exist)
$bySub = []; foreach ($tools as $t) { $s = ($t['subcategory'] ?? '') ?: ''; if ($s !== '' && $s !== 'Unclassified') $bySub[$s][] = $t; }
ksort($bySub);
$existing = array_map(fn($p) => strtolower($p['query'] ?? ''), solutions_all());
foreach ($bySub as $sub => $set) {
  if (count($set) < 8) continue;
  $rev = array_values(array_filter($set, fn($t) => !empty($t['curated']))); [$st, $why] = $gateText('best-for-task', $rev);
  $add(['type' => 'task', 'working_title' => 'AI tools for ' . strtolower($sub), 'search_intent' => 'A person wants tools for ' . strtolower($sub) . ' and a reason to pick one', 'audience' => 'Role depends on subcategory: confirm with an editor',
    'proof_needed' => 'At least 3 tools researched from vendor pages with dates; pricing evidence; limitations; no claims from listing text', 'differentiation' => 'Evidence states per tool; honest unknowns; criteria stated before ranking',
    'internal_links' => 'category page; /compare.php; relevant guide', 'conversion_goal' => 'Wizard start', 'publication_gate' => 'best-for-task', 'gate_now' => $st, 'blocked_by' => $why ?: 'editor sign-off', 'state' => 'proposed (catalogue has ' . count($set) . ' listings, ' . count($rev) . ' reviewed)',
    'verification_state' => 'not started', 'priority_score' => 20 + min(30, count($set)), 'volume' => $vol]);
}
// 3. Comparisons: curated pairs
$cur = array_values(array_filter($tools, fn($t) => !empty($t['curated']))); $shown = [];
foreach (available_comparison_pairs($tools, 24) as [$a, $b]) $shown[$a['id'] . '|' . $b['id']] = true;
for ($i = 0; $i < count($cur); $i++) for ($j = $i + 1; $j < count($cur); $j++) {
  $a = $cur[$i]; $b = $cur[$j]; if ($a['category'] !== $b['category']) continue;
  $key = $a['id'] . '|' . $b['id']; $live = isset($shown[$key]) || isset($shown[$b['id'] . '|' . $a['id']]); [$st, $why] = $gateText('a-vs-b', [$a, $b]);
  $conf = count((array)($a['curated']['known_source_conflicts'] ?? [])) + count((array)($b['curated']['known_source_conflicts'] ?? []));
  $add(['type' => 'comparison', 'working_title' => $a['name'] . ' vs ' . $b['name'], 'search_intent' => 'A person is choosing between two named tools', 'audience' => 'People already considering both',
    'proof_needed' => 'Both profiles: 2+ vendor sources each, pricing as of date, conflicts resolved or disclosed, editor verification; hands-on test for any verdict', 'differentiation' => 'Documented / unknown / conflicting states side by side; no winner without a test',
    'internal_links' => '/tool.php for each; /alternatives.php; /pricing-comparison.php', 'conversion_goal' => 'Wizard start', 'publication_gate' => 'a-vs-b: 2 reviewed tools, 2+ sources each', 'gate_now' => $st, 'blocked_by' => trim($why . ($conf ? '; ' . $conf . ' known source conflict(s)' : ''), '; '),
    'state' => $live ? 'exists' : 'candidate', 'verification_state' => ($verifiedAny([$a, $b]) ? 'partly verified' : 'no tool verified by an editor'), 'priority_score' => ($live ? 55 : 35) + ($conf ? 5 : 0), 'volume' => $vol]);
}
// 4. Alternatives to a specific tool
foreach ($cur as $t) {
  $alts = array_values(array_filter(array_map(fn($id) => $byId[$id] ?? null, (array)($t['curated']['alternatives'] ?? [])))); [$st, $why] = $gateText('alternatives', array_merge([$t], $alts));
  $add(['type' => 'alternatives', 'working_title' => 'Alternatives to ' . $t['name'], 'search_intent' => 'A person wants options to a tool they already know', 'audience' => 'Current or prospective users of ' . $t['name'],
    'proof_needed' => 'Each alternative reviewed with sources; stated reasons for listing it (documented capability overlap), not popularity', 'differentiation' => 'Reasons per alternative; unknowns marked; no affiliate or sponsor influence',
    'internal_links' => '/tool.php?id=' . $t['id'] . '; /compare.php', 'conversion_goal' => 'Compare or wizard', 'publication_gate' => 'alternatives: 4+ tools, 4+ reviewed, 2+ sources each', 'gate_now' => $st, 'blocked_by' => $why, 'state' => 'exists (gated)',
    'verification_state' => 'no tool verified by an editor', 'priority_score' => 30 + count($alts), 'volume' => $vol]);
}
// 5. Vendor pricing/feature change explainers: one per known source conflict
foreach ($cur as $t) foreach ((array)($t['curated']['known_source_conflicts'] ?? []) as $c) {
  $add(['type' => 'change_explainer', 'working_title' => $t['name'] . ': what changed in pricing or plans', 'search_intent' => 'A person saw conflicting prices or plan names and wants the current position', 'audience' => 'Buyers of ' . $t['name'],
    'proof_needed' => 'Dated captures of the vendor pricing page, vendor announcement or changelog URL, editor confirmation of the current plan names. Conflict on record: ' . $c, 'differentiation' => 'Dated before/after with sources; no speculation about why',
    'internal_links' => '/tool.php?id=' . $t['id'] . '; /changelog.php', 'conversion_goal' => 'Newsletter sign-up (price-change alerts only if a newsletter exists)', 'publication_gate' => 'Verified changelog entry with vendor evidence URL and verifier', 'gate_now' => 'blocked', 'blocked_by' => 'needs an editor to verify the current vendor pages; no verified changelog entry exists',
    'state' => 'proposed', 'verification_state' => 'not started', 'priority_score' => 50, 'volume' => $vol]);
}
// 6. Workflows and integration guides, 7. agent explainers (editor-defined topics; nothing exists yet; each needs a recorded hands-on test)
$topics = [
  ['workflow', 'From meeting recording to action items: a transcription and task-list workflow', 'Meeting organisers and team leads', 'Hands-on run on a non-confidential recording with dated screenshots; vendor docs for each export/integration used'],
  ['workflow', 'Turn a long PDF into a briefing: a summarise-and-verify workflow', 'Analysts and students', 'Hands-on run on a public document; a stated method for checking the summary against the source'],
  ['workflow', 'Research with citations: a source-first AI workflow', 'Researchers and writers', 'Hands-on run; examples of errors found; vendor documentation on citation behaviour'],
  ['workflow', 'Connecting an AI assistant to a spreadsheet or CRM: what integrations actually exist', 'Operations and sales teams', 'Vendor integration documentation with dates; a tested example; list of what is not supported'],
  ['agent', 'What an AI agent is, and when a simple automation is the better choice', 'Managers evaluating agents', 'Vendor and standards documentation; a worked decision tree; no vendor claims presented as fact'],
  ['agent', 'How to evaluate an AI agent vendor: a due-diligence checklist', 'Buyers and security reviewers', 'Checklist reviewed by a qualified person (security/legal); sourced references; labelled as general guidance'],
  ['agent', 'Keeping a human in the loop: approvals, logs and rollback for agent actions', 'Teams deploying agents', 'Hands-on prototype or vendor documentation of approval/logging features, dated'],
  ['agent', 'Agent costs: how usage-based pricing can surprise you', 'Finance and engineering leads', 'Dated vendor pricing pages; a worked example using published prices only'],
];
foreach ($topics as [$type, $title, $aud, $proof]) $add(['type' => $type === 'workflow' ? 'workflow_guide' : 'agent_explainer', 'working_title' => $title, 'search_intent' => $type === 'workflow' ? 'A person wants step-by-step instructions that work' : 'A team wants to build or buy an agent responsibly', 'audience' => $aud,
  'proof_needed' => $proof, 'differentiation' => 'Documented steps with dates, limits and failure cases stated; original examples', 'internal_links' => '/guides.php; related tool pages; /recommend.php', 'conversion_goal' => 'Wizard start or TrustNova Tech consultation (agent topics)',
  'publication_gate' => $type === 'workflow' ? 'industry-workflow: tested evidence + named editor approval' : 'agent-implementation: tested evidence + named editor approval', 'gate_now' => 'blocked', 'blocked_by' => 'no recorded hands-on test exists; needs a named editor', 'state' => 'proposed', 'verification_state' => 'not started', 'priority_score' => 25, 'volume' => $vol]);

usort($rows, fn($a, $b) => $b['priority_score'] <=> $a['priority_score'] ?: strcmp($a['id'], $b['id']));
foreach ($rows as $i => &$r) { $r['rank'] = $i + 1; $r['priority'] = $r['priority_score'] >= 55 ? 'P1' : ($r['priority_score'] >= 35 ? 'P2' : 'P3'); } unset($r);
$cols = ['rank', 'priority', 'id', 'type', 'working_title', 'state', 'search_intent', 'audience', 'proof_needed', 'differentiation', 'internal_links', 'conversion_goal', 'publication_gate', 'gate_now', 'blocked_by', 'verification_state', 'volume'];
if (in_array('--json', $argv, true)) { echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n"; exit; }
$csv = (string)$opt('csv');
if ($csv !== '') { $fh = fopen($csv, 'w'); fputcsv($fh, $cols, ',', '"', ''); foreach ($rows as $r) fputcsv($fh, array_map(fn($c) => $r[$c] ?? '', $cols), ',', '"', ''); fclose($fh); }
$by = []; foreach ($rows as $r) $by[$r['type']] = ($by[$r['type']] ?? 0) + 1; ksort($by);
echo 'backlog rows: ' . count($rows) . ' (' . implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($by), $by)) . ")\n";
echo 'ready for editor (gate passes): ' . count(array_filter($rows, fn($r) => strpos($r['gate_now'], 'gate_passes') === 0)) . '; blocked: ' . count(array_filter($rows, fn($r) => strpos($r['gate_now'], 'blocked') === 0)) . "\n";
