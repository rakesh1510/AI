<?php
// Unified Admin Control Center, U6, DATABASE-FREE: the Advertising hub (read-only view over CRM campaigns, featured placements and paid listing submissions).
$root = dirname(__DIR__); chdir($root);
ob_start(); require_once $root . '/helpers.php'; require_once $root . '/crm_views.php'; require_once $root . '/adm_core.php'; require_once $root . '/adm_crm.php'; require_once $root . '/adm_advertising.php'; ob_end_clean();
$pass = 0; $fail = 0; function ok($c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; fwrite(STDERR, "  FAIL: $m\n"); } }
$src = file_get_contents($root . '/adm_advertising.php'); $ac = file_get_contents($root . '/admin-center.php');
$U = fn(string $r) => ['id' => 1, 'username' => $r, 'display_name' => ucfirst($r), 'role' => $r];
$camps = [['id' => 1, 'title' => 'Spring <b>sponsor</b>', 'company_name' => 'Example Ltd', 'package' => 'featured_listing', 'status' => 'live', 'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'disclosure_label' => 'Sponsored', 'quote_minor' => 49000, 'quote_currency' => 'EUR', 'is_synthetic' => 0],
  ['id' => 2, 'title' => 'Test record', 'company_name' => 'Synthetic Co', 'package' => 'newsletter', 'status' => 'live', 'start_date' => null, 'end_date' => null, 'disclosure_label' => '', 'quote_minor' => null, 'quote_currency' => null, 'is_synthetic' => 1],
  ['id' => 3, 'title' => 'Inbound', 'company_name' => 'Other GmbH', 'package' => 'featured_listing', 'status' => 'inquiry', 'start_date' => null, 'end_date' => null, 'disclosure_label' => '', 'quote_minor' => null, 'quote_currency' => null, 'is_synthetic' => 0]];
$pl = [['tool_slug' => 'alpha', 'level' => 2, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'source' => 'manual', 'status' => 'active', 'note' => 'n <i>x</i>'], ['tool_slug' => 'beta', 'level' => 1, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-07', 'source' => 'submission', 'status' => 'ended', 'note' => '']];
$li = [['id' => 7, 'name' => 'Paid Tool', 'plan' => 'featured', 'price' => 49, 'currency' => 'EUR', 'payment_status' => 'unpaid', 'status' => 'pending', 'created_at' => '2026-11-10 09:00:00'], ['id' => 8, 'name' => 'Paid Two', 'plan' => 'homepage', 'price' => 99, 'currency' => 'EUR', 'payment_status' => 'paid', 'status' => 'approved', 'created_at' => '2026-11-11 09:00:00']];
// permissions and views
ok(adm_can('owner', 'advertising') && adm_can('sales', 'advertising') && !adm_can('viewer', 'advertising') && !adm_can('editor', 'advertising') && !adm_can('verifier', 'advertising'), 'advertising permission unchanged: owner and sales only');
ok(admv_view(['view' => 'featured'], 'sales') === 'overview' && admv_view(['view' => 'listings'], 'sales') === 'overview' && admv_view(['view' => 'featured'], 'owner') === 'featured' && admv_view(['view' => 'zzz'], 'owner') === 'overview' && admv_view(['view' => ['x']], 'owner') === 'overview', 'sales cannot open the owner-only views; unknown views fall back');
$m = adm_modules()['advertising']; ok(!empty($m['live']) && !empty($m['internal']) && $m['perm'] === 'advertising' && $m['phase'] === '8E', 'module is live inside the shell and still tagged with its roadmap phase 8E');
$kids = []; foreach (adm_nav_tree('sales', 'advertising') as $g) foreach ($g['items'] as $i) if ($i['id'] === 'advertising') $kids = array_column($i['children'], 'label'); ok($kids === ['Overview', 'Campaigns'], 'sales menu shows only the views it may open');
$kids = []; foreach (adm_nav_tree('owner', 'advertising') as $g) foreach ($g['items'] as $i) if ($i['id'] === 'advertising') $kids = array_column($i['children'], 'label'); ok(count($kids) === 4, 'owner menu shows four views');
// overview
$h = admv_body($U('owner'), [], $camps, $pl, $li); ok(strpos($h, 'Not built yet') !== false && strpos($h, 'Phase 8E to 8G') !== false, 'overview states what is not built (roadmap preserved)');
$cnt = admv_campaign_counts($camps); ok(($cnt['live'] ?? 0) === 1 && ($cnt['inquiry'] ?? 0) === 1, 'synthetic records are not counted');
ok(strpos($h, '1 active, 2 in the history') !== false && strpos($h, '2 with a price; 1 pending and unpaid') !== false, 'placement and listing summaries');
$h = admv_body($U('sales'), [], $camps, null, null); ok(strpos($h, 'Featured placements</h2>') === false && strpos($h, 'Paid listing') === false, 'sales overview has no placement or listing data');
// campaigns view
$h = admv_body($U('sales'), ['view' => 'campaigns'], $camps, null, null);
ok(strpos($h, '&lt;b&gt;sponsor') !== false && strpos($h, '<b>') === false, 'campaign titles are escaped');
ok(strpos($h, 'Synthetic test record') !== false && strpos($h, 'indicative, not revenue') !== false && strpos($h, '490.00 EUR') !== false, 'synthetic records are labelled; quotes are labelled indicative and shown in money format');
ok(strpos($h, 'admin-center.php?m=crm&amp;v=campaign&amp;id=1') !== false, 'campaign titles link to the CRM campaign page inside the shell');
$h = admv_body($U('viewer'), ['view' => 'campaigns'], $camps, null, null); ok(strpos($h, 'href="/admin-center.php?m=crm') === false, 'a role without crm gets no CRM link');
// owner views
$h = admv_body($U('owner'), ['view' => 'featured'], $camps, $pl, $li); ok(strpos($h, 'alpha') !== false && strpos($h, 'Level 2') !== false && strpos($h, '&lt;i&gt;x') !== false && strpos($h, 'm=tools&amp;view=featured') !== false, 'featured placements listed, escaped, linked to Tool management');
$h = admv_body($U('owner'), ['view' => 'listings'], $camps, $pl, $li); ok(strpos($h, 'Paid (recorded manually, not bank-verified)') !== false && strpos($h, 'Unpaid') !== false && strpos($h, 'view=submission&amp;id=7') !== false, 'paid listing submissions with honest payment wording');
$h = admv_body($U('owner'), ['view' => 'featured'], $camps, null, null); ok(strpos($h, 'not installed') !== false, 'missing tool management is explained, not an error');
$h = admv_body($U('owner'), ['view' => 'campaigns'], [], null, null); ok(strpos($h, 'Nothing recorded yet') !== false, 'empty campaigns are explained');
// read only, no new tables
ok(strpos($src, '<form') === false && strpos($src, 'method="post"') === false, 'the hub has no forms');
ok(!preg_match('/INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|ALTER\s+TABLE|DROP\s+TABLE|CREATE\s+TABLE|prepare\(|->query\(|mail\(/i', $src), 'the hub runs no SQL of its own and sends nothing');
ok(!preg_match("#=== 'advertising'\) \{[^}]*POST#", $ac) && strpos($ac, "elseif (\$m === 'advertising')") !== false, 'no POST route for the advertising module');
ok(strpos($ac, "if (!adm_can((string)\$user['role'], \$mod['perm']))") < strpos($ac, "elseif (\$m === 'advertising')"), 'route is after the module permission check');
ok(strpos($ac, "adm_can((string)\$user['role'], 'tools_publish') && AdmToolPdoStore::ready") !== false, 'placements and submissions are loaded only for the owner');
$diff = shell_exec('git diff --stat 7c4b35f -- crm_growth.php adm_finance_core.php adm_finance_views.php 2>/dev/null'); ok(trim((string)$diff) === '', 'CRM campaign code and Finance are unchanged');
echo "$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
