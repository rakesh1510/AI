<?php
/** Content backlog integrity (Phase 5): every row is evidence-gated, nothing claims verification, the committed CSV is current. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "  FAIL: $m\n"; } }
$tmp = tempnam(sys_get_temp_dir(), 'bl');
exec('php ' . escapeshellarg($root . '/scripts/content_backlog.php') . ' --csv=' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
ok($rc === 0, 'the generator runs');
$fh = fopen($tmp, 'r'); $head = fgetcsv($fh, 0, ',', '"', ''); $rows = [];
while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) $rows[] = array_combine($head, $r); fclose($fh);
ok(count($rows) >= 100, 'at least 100 backlog rows (' . count($rows) . ')');
foreach (['search_intent', 'audience', 'proof_needed', 'differentiation', 'internal_links', 'conversion_goal', 'publication_gate', 'gate_now', 'verification_state', 'volume'] as $c) ok(count(array_filter($rows, fn($r) => trim($r[$c]) === '')) === 0, "every row fills '$c'");
$types = array_count_values(array_column($rows, 'type'));
foreach (['comparison', 'alternatives', 'task', 'workflow_guide', 'agent_explainer', 'change_explainer'] as $t) ok(($types[$t] ?? 0) > 0, "backlog covers '$t'");
ok(count(array_unique(array_column($rows, 'id'))) === count($rows), 'ids are unique');
ok(count(array_filter($rows, fn($r) => !preg_match('/^(no tool verified by an editor|not started|partly verified)$/', $r['verification_state']))) === 0, 'verification states are only the honest ones');
ok(count(array_filter($rows, fn($r) => preg_match('/^verified$/i', $r['verification_state']))) === 0, 'no row is marked verified');
ok(count(array_filter($rows, fn($r) => strpos($r['volume'], 'unmeasured') !== 0)) === 0, 'no search volume is stated (all unmeasured)');
$needTest = array_filter($rows, fn($r) => in_array($r['type'], ['workflow_guide', 'agent_explainer', 'change_explainer'], true));
ok($needTest && count(array_filter($needTest, fn($r) => strpos($r['gate_now'], 'blocked') !== 0)) === 0, 'workflows, agent explainers and change explainers are blocked until real evidence exists');
ok(count(array_filter($rows, fn($r) => preg_match('/\b\d{2,3}(,\d{3})+\s*(searches|visits|visitors)|#1|guaranteed/i', implode(' ', $r)))) === 0, 'no invented traffic figures or guarantees');
ok(trim(file_get_contents($tmp)) === trim(file_get_contents($root . '/docs/phase5/editorial_backlog.csv')), 'the committed CSV matches a fresh run (regenerate with scripts/content_backlog.php --csv=docs/phase5/editorial_backlog.csv)');
@unlink($tmp);
// Thin-page policy (existing switch AIHUB_THIN_TOOL_POLICY): measure what the owner decision would do, without changing the default.
$count = fn(string $env) => (int)trim((string)shell_exec($env . ' php -r ' . escapeshellarg('require "' . $root . '/sitemap_lib.php"; $n=0; foreach (sitemap_entries() as $e) if (strpos($e["loc"], "/tool.php") !== false) $n++; echo $n;')));
$tools = (int)trim((string)shell_exec('php -r ' . escapeshellarg('require "' . $root . '/helpers.php"; echo count(tools_by_id());')));
$basic = (int)trim((string)shell_exec('php -r ' . escapeshellarg('require "' . $root . '/helpers.php"; echo count(array_filter(tools_by_id(), fn($t) => tool_quality_tier($t) === "basic"));')));
ok($count('AIHUB_THIN_TOOL_POLICY=index') === $tools, "default policy: all $tools tool pages are in the sitemap (current behaviour preserved)");
ok($count('AIHUB_THIN_TOOL_POLICY=noindex') === $tools - $basic && $basic > 0, "policy noindex would remove exactly the $basic template-only tool pages from the sitemap");
echo "\nPASSED: $pass  FAILED: $fail\n"; exit($fail ? 1 : 0);
