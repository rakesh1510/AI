<?php
// Phase 8C M1-M3, DATABASE-FREE: validation, permissions, lifecycle, stale edits, revisions, listing, rendering and static safeguards.
// Uses only the in-memory store and synthetic data. No MySQL, no containers, no HTTP server. (The MySQL store and the migration are NOT exercised here.)
$root = dirname(__DIR__); chdir($root);
ob_start(); require_once $root . '/adm_core.php'; require_once $root . '/adm_tools_views.php'; ob_end_clean();
$pass = 0; $fail = 0; function ok($c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; fwrite(STDERR, "  FAIL: $m\n"); } }
$cats = ['Writing', 'Code', 'Audio']; $audit = []; $au = function (string $a, string $s, string $x) use (&$audit) { $audit[] = [$a, $s, $x]; };
$owner = ['username' => 'own', 'role' => 'owner']; $editor = ['username' => 'ed', 'role' => 'editor'];
$good = ['name' => 'Acme Writer', 'category' => 'Writing', 'short' => 'Writes marketing copy quickly for small teams.', 'pricing_type' => 'Freemium', 'website' => 'https://acme.example.com/', 'features' => "Drafts\nTone control", 'tags' => 'writing, marketing'];
$mk = fn() => new AdmToolMemoryStore([], $cats);

// ---- validation ----
[$e, $t] = admt_validate($good, $cats); ok(!$e && $t['slug'] === 'acme-writer' && $t['features'] === ['Drafts', 'Tone control'] && $t['tags'] === ['writing', 'marketing'], 'a good form validates, slug comes from the name, lists and tags parse');
foreach (['name' => ['name' => 'A'], 'category' => ['category' => 'Nope'], 'short' => ['short' => 'too short'], 'pricing_type' => ['pricing_type' => 'Gratis'], 'website' => ['website' => 'javascript:alert(1)'], 'affiliate' => ['affiliate' => 'ftp://x.example.com'], 'logo' => ['logo' => 'http://localhost/x.png'], 'tags' => ['tags' => 'bad@tag'], 'slug' => ['slug' => 'Bad Slug!']] as $f => $bad) { [$e] = admt_validate($bad + $good, $cats); ok(isset($e[$f]), "invalid $f is rejected"); }
foreach (['http://127.0.0.1/', 'https://10.0.0.5/', 'https://user:pw@example.com/', "https://exa mple.com/", 'data:text/html,x'] as $u) { [$e] = admt_validate(['website' => $u] + $good, $cats); ok(isset($e['website']), "unsafe website rejected: $u"); }
[$e, $t] = admt_validate(['name' => "<script>alert(1)</script>Evil\x00Tool", 'features' => "<img src=x onerror=alert(1)>f1"] + $good, $cats); ok(strpos($t['name'], '<') === false && strpos($t['name'], "\x00") === false && strpos($t['features'][0], '<') === false, 'markup and control characters are stripped');
[$e, $t] = admt_validate(['features' => implode("\n", range(1, 40)), 'tags' => implode(',', array_map(fn($i) => "tag$i", range(1, 30)))] + $good, $cats); ok(count($t['features']) === 10 && count($t['tags']) === 12, 'list sizes are capped');
[$e, $t] = admt_validate(['slug' => 'changed', 'name' => 'Renamed'] + $good, $cats, ['slug' => 'orig-slug']); ok($t['slug'] === 'orig-slug' && $t['name'] === 'Renamed', 'the slug can never change on edit');
ok(admt_readiness([]) !== [] && admt_readiness($t + ['features' => ['x']]) === [], 'readiness lists what is missing and passes a complete tool');

// ---- permissions (owner decision D2) ----
foreach (['create', 'edit', 'unpublish'] as $a) { ok(adm_can('owner', admt_action_perm($a)) && adm_can('editor', admt_action_perm($a)), "owner and editor may $a"); foreach (['sales', 'viewer', 'verifier', 'nobody'] as $r) ok(!adm_can($r, admt_action_perm($a)), "$r may not $a"); }
foreach (['publish', 'delete', 'restore'] as $a) { ok(adm_can('owner', admt_action_perm($a)), "owner may $a"); foreach (['editor', 'sales', 'viewer', 'verifier', 'nobody'] as $r) ok(!adm_can($r, admt_action_perm($a)), "$r may not $a"); }
ok(admt_action_perm('purge') === null && admt_action_perm('hard_delete') === null, 'there is no hard-delete action in Phase 8C');
ok(adm_can('editor', 'universe') && !adm_can('editor', 'finance') && !adm_can('editor', 'analytics'), 'giving editors tool access did not widen anything else');

// ---- lifecycle on the in-memory store ----
$s = $mk(); $r = admt_apply($s, $owner, 'create', $good, $au); ok($r['ok'] && $r['slug'] === 'acme-writer' && $s->get('acme-writer')['status'] === 'draft', 'create makes a DRAFT, never published');
$r = admt_apply($s, $owner, 'create', $good, $au); ok(!$r['ok'] && $r['code'] === 409, 'duplicate address refused');
$r = admt_apply($s, $editor, 'create', ['name' => 'Ed Tool', 'website' => 'https://ed.example.com'] + $good, $au); ok($r['ok'] && $s->get('ed-tool')['status'] === 'draft', 'an editor can create a draft');
$r = admt_apply($s, ['username' => 'v', 'role' => 'viewer'], 'create', $good, $au); ok(!$r['ok'] && $r['code'] === 403, 'a viewer cannot create (403)');
$v = function ($slug) use (&$s) { return (int)$s->get($slug)['_version']; };
$r = admt_apply($s, $editor, 'publish', ['slug' => 'acme-writer', 'version' => $v('acme-writer')], $au); ok(!$r['ok'] && $r['code'] === 403 && $s->get('acme-writer')['status'] === 'draft', 'an editor cannot publish (403), status unchanged');
$r = admt_apply($s, $owner, 'publish', ['slug' => 'acme-writer', 'version' => $v('acme-writer')], $au); ok($r['ok'] && $s->get('acme-writer')['status'] === 'published', 'the owner publishes a complete draft');
$s->create(['slug' => 'thin', 'name' => 'Thin', 'category' => '', 'short' => '', 'website' => '', 'features' => [], 'status' => 'draft'] + admt_validate($good, $cats)[1], 'x', ''); $r = admt_apply($s, $owner, 'publish', ['slug' => 'thin', 'version' => 1], $au); ok(!$r['ok'] && $r['code'] === 422 && $s->get('thin')['status'] === 'draft', 'an incomplete tool cannot be published');
$r = admt_apply($s, $editor, 'unpublish', ['slug' => 'acme-writer', 'version' => $v('acme-writer')], $au); ok($r['ok'] && $s->get('acme-writer')['status'] === 'unpublished', 'an editor can unpublish');
$r = admt_apply($s, $editor, 'delete', ['slug' => 'acme-writer', 'version' => $v('acme-writer'), 'confirm' => '1'], $au); ok(!$r['ok'] && $r['code'] === 403, 'an editor cannot delete');
$r = admt_apply($s, $owner, 'delete', ['slug' => 'acme-writer', 'version' => $v('acme-writer')], $au); ok(!$r['ok'] && $r['code'] === 422 && $s->get('acme-writer')['status'] === 'unpublished', 'delete needs the confirmation');
$r = admt_apply($s, $owner, 'delete', ['slug' => 'acme-writer', 'version' => $v('acme-writer'), 'confirm' => '1'], $au); ok($r['ok'] && $s->get('acme-writer')['status'] === 'deleted' && $s->get('acme-writer') !== null, 'soft delete keeps the record');
$r = admt_apply($s, $owner, 'edit', ['slug' => 'acme-writer', 'version' => $v('acme-writer')] + $good, $au); ok(!$r['ok'] && $r['code'] === 409, 'a deleted tool cannot be edited');
$r = admt_apply($s, $owner, 'create', $good, $au); ok(!$r['ok'] && $r['code'] === 409, 'a deleted tool keeps its address reserved');
$r = admt_apply($s, $editor, 'restore', ['slug' => 'acme-writer', 'version' => $v('acme-writer')], $au); ok(!$r['ok'] && $r['code'] === 403, 'an editor cannot restore');
$r = admt_apply($s, $owner, 'restore', ['slug' => 'acme-writer', 'version' => $v('acme-writer')], $au); ok($r['ok'] && $s->get('acme-writer')['status'] === 'unpublished', 'restore returns a previously published tool to UNPUBLISHED, never straight to published');
$r = admt_apply($s, $owner, 'delete', ['slug' => 'ed-tool', 'version' => $v('ed-tool'), 'confirm' => '1'], $au); $r = admt_apply($s, $owner, 'restore', ['slug' => 'ed-tool', 'version' => $v('ed-tool')], $au); ok($r['ok'] && $s->get('ed-tool')['status'] === 'draft', 'a never-published tool restores as a draft');
$r = admt_apply($s, $owner, 'restore', ['slug' => 'ed-tool', 'version' => $v('ed-tool')], $au); ok(!$r['ok'] && $r['code'] === 409, 'only a deleted tool can be restored');
$r = admt_apply($s, $owner, 'publish', ['slug' => 'ed-tool', 'version' => $v('ed-tool')], $au); $r2 = admt_apply($s, $owner, 'publish', ['slug' => 'ed-tool', 'version' => $v('ed-tool')], $au); ok($r['ok'] && !$r2['ok'] && $r2['code'] === 409, 'publishing twice is refused');
$r = admt_apply($s, $owner, 'frobnicate', [], $au); ok(!$r['ok'] && $r['code'] === 400, 'unknown action is a 400');
$r = admt_apply($s, $owner, 'publish', ['slug' => '../etc', 'version' => 1], $au); ok(!$r['ok'] && $r['code'] === 404, 'a malformed slug is just not found');

// ---- edit, stale-edit check, revisions, audit ----
$s = $mk(); admt_apply($s, $owner, 'create', $good, $au); $ver = $v('acme-writer');
$r = admt_apply($s, $editor, 'edit', ['slug' => 'acme-writer', 'version' => $ver, 'name' => 'Acme Writer Pro', 'note' => 'rename'] + $good, $au); ok($r['ok'] && $s->get('acme-writer')['name'] === 'Acme Writer Pro' && $s->get('acme-writer')['slug'] === 'acme-writer', 'edit saves and keeps the address');
$r = admt_apply($s, $editor, 'edit', ['slug' => 'acme-writer', 'version' => $ver, 'name' => 'Stale'] + $good, $au); ok(!$r['ok'] && $r['code'] === 409 && $s->get('acme-writer')['name'] === 'Acme Writer Pro', 'a stale form is refused and nothing is overwritten');
$r = admt_apply($s, $editor, 'edit', ['slug' => 'acme-writer', 'version' => '', 'name' => 'NoVersion'] + $good, $au); ok(!$r['ok'] && $r['code'] === 409, 'a missing version is refused');
$r = admt_apply($s, $editor, 'edit', ['slug' => 'acme-writer', 'version' => $v('acme-writer'), 'website' => 'javascript:1'] + $good, $au); ok(!$r['ok'] && $r['code'] === 422 && isset($r['errors']['website']), 'edit validation errors are returned per field');
$h = $s->revisions('acme-writer'); ok(count($h) === 2 && $h[0]['action'] === 'edit' && $h[0]['fields'] === ['name'] && $h[0]['note'] === 'rename' && $h[1]['action'] === 'create' && $h[0]['revision_no'] === 2, 'every change has a revision with the changed fields');
ok(array_column($audit, 0) === array_values(array_filter(array_column($audit, 0))) && in_array('tool_create', array_column($audit, 0), true) && in_array('tool_denied', array_column($audit, 0), true), 'creates and denials are audited');

// ---- listing ----
$rows = []; for ($i = 1; $i <= 60; $i++) $rows[] = ['slug' => "tool-$i", 'name' => "Tool $i", 'category' => $i % 2 ? 'Writing' : 'Code', 'status' => $i % 10 === 0 ? 'deleted' : ($i % 3 === 0 ? 'draft' : 'published'), 'tags' => $i % 5 === 0 ? ['Pdf'] : [], 'featured_level' => $i === 7 ? 1 : 0, 'short' => $i % 4 === 0 ? '' : 'A synthetic description long enough.', 'website' => 'https://t.example.com', 'features' => ['f'], 'updated_at' => sprintf('2026-01-%02d 00:00:00', $i % 28 + 1)];
$all = (new AdmToolMemoryStore($rows, $cats))->all();
$L = admt_list($all, []); ok($L['counts']['deleted'] === 6 && $L['total'] === 54 && count($L['rows']) === 25 && $L['pages'] === 3, 'default list hides the trash and paginates at 25');
$L = admt_list($all, ['status' => 'deleted']); ok($L['total'] === 6, 'the trash view lists only deleted tools');
$L = admt_list($all, ['status' => 'draft']); ok($L['total'] > 0 && !array_filter($L['rows'], fn($t) => $t['status'] !== 'draft'), 'status filter');
$L = admt_list($all, ['category' => 'Code']); ok(!array_filter($L['rows'], fn($t) => $t['category'] !== 'Code'), 'category filter');
$L = admt_list($all, ['tag' => 'pdf']); ok($L['total'] === 6 && $L['rows'][0]['tags'] === ['Pdf'], 'tag filter is case-insensitive');
$L = admt_list($all, ['q' => 'TOOL 12']); ok($L['total'] === 1 && $L['rows'][0]['slug'] === 'tool-12', 'search by name');
$L = admt_list($all, ['featured' => '1']); ok($L['total'] === 1, 'featured filter');
$L = admt_list($all, ['incomplete' => '1']); ok($L['total'] > 0 && !array_filter($L['rows'], fn($t) => !admt_readiness($t)), 'missing-fields filter');
$L = admt_list($all, ['sort' => 'updated', 'dir' => 'desc']); ok($L['rows'][0]['updated_at'] >= $L['rows'][1]['updated_at'], 'sort by updated');
$L = admt_list($all, ['page' => '99']); ok($L['page'] === 3, 'page number is clamped');
$L = admt_list($all, ['q' => '%\'"; DROP', 'sort' => 'evil']); ok($L['total'] === 0 && $L['sort'] === 'name', 'hostile search and sort values are harmless');

// ---- rendering ----
$st = new AdmToolMemoryStore($rows + [99 => ['slug' => 'xss', 'name' => '<script>alert(1)</script>', 'category' => 'Code', 'status' => 'draft', 'short' => '"><img src=x onerror=1>', 'website' => 'https://x.example.com', 'features' => ['f'], 'tags' => ['t'], 'updated_at' => '2026-01-01 00:00:00']], $cats);
$html = admt_body($st, $owner, []); ok(strpos($html, '<script>alert') === false && strpos($html, 'role="search"') !== false && strpos($html, 'aria-label="Filter by status"') !== false && strpos($html, 'aria-label="Pages"') !== false, 'list renders with landmarks and no raw markup');
$html = admt_body($st, $owner, ['view' => 'detail', 'slug' => 'xss']); ok(strpos($html, '<script>alert') === false && strpos($html, '&lt;script&gt;') !== false && strpos($html, 'name="_csrf"') !== false && strpos($html, 'value="tool_publish"') !== false && strpos($html, 'value="tool_delete"') !== false, 'owner detail: escaped, CSRF field, publish and delete forms');
$html = admt_body($st, $editor, ['view' => 'detail', 'slug' => 'xss']); ok(strpos($html, 'tool_publish') === false && strpos($html, 'tool_delete') === false && strpos($html, 'owner actions') !== false, 'editor detail: no publish or delete controls');
$html = admt_body($st, $owner, ['view' => 'detail', 'slug' => 'tool-10']); ok(strpos($html, 'tool_restore') !== false && strpos($html, 'tool_edit') === false && strpos($html, '>Edit<') === false, 'trashed tool: restore offered, edit not');
$html = admt_body($st, $owner, ['view' => 'new'], ['name' => '"><b>x'], ['name' => 'Enter a name'], 'Please correct the highlighted fields.'); ok(strpos($html, 'aria-invalid="true"') !== false && strpos($html, 'role="alert"') !== false && strpos($html, '"><b>x') === false && strpos($html, 'for="f_name"') !== false, 'form errors: aria-invalid, alert, escaped values, labels');
$html = admt_body($st, $owner, ['view' => 'edit', 'slug' => 'tool-1']); ok(strpos($html, 'name="version"') !== false && strpos($html, 'readonly') !== false, 'edit form carries the version and a read-only address');
ok(strpos(admt_body($st, ['role' => 'viewer'], []), 'Not allowed') !== false, 'a role without access sees nothing');
ok(strpos(admt_body($st, $owner, ['msg' => '<b>x</b>']), '<b>x</b>') === false, 'the flash message comes only from a fixed list');
ok(strpos(admt_body($st, $owner, ['view' => 'detail', 'slug' => '../x']), 'Tool not found') !== false, 'bad slug in the URL is not found');
ok(strpos(admt_css(), 'prefers-reduced-motion') === false && strpos(adm_css(), 'prefers-reduced-motion') !== false && strpos(adm_css(), '.toolCards') !== false, 'tool styles are part of the page CSS, motion is disabled for reduced-motion users');
ok(strpos(admt_not_installed(), 'Storage not installed') !== false, 'honest state when storage is not installed');

// ---- static safeguards ----
$mig = (string)file_get_contents("$root/database/phase8c_tools.sql"); $rb = (string)file_get_contents("$root/database/phase8c_rollback.sql");
ok(preg_match_all('/^\s*CREATE TABLE IF NOT EXISTS (\w+)/mi', $mig, $m) === 3 && $m[1] === ['adm_tool_revision', 'adm_tool_feature', 'adm_submission_review'], 'migration creates exactly the three approved tables');
ok(!preg_match('/\b(ALTER|DROP|DELETE|UPDATE|INSERT|TRUNCATE|RENAME)\b/i', preg_replace('/--[^\n]*/', '', $mig)), 'migration contains no ALTER, DROP, DELETE, UPDATE or INSERT');
ok(preg_match('/DROP TABLE IF EXISTS adm_submission_review, adm_tool_feature, adm_tool_revision;/', $rb) === 1 && substr_count(strtoupper(preg_replace('/--[^\n]*/', '', $rb)), 'DROP') === 1 && !preg_match('/\b(DELETE|ALTER|TRUNCATE)\b/i', $rb), 'rollback drops only those three tables');
$h = (string)file_get_contents("$root/helpers.php"); ok(preg_match("/WHERE t\.slug = \? AND t\.status = 'published'/", $h) === 1, 'find_tool() only returns published tools');
$imp = (string)file_get_contents("$root/import-json-to-mysql.php"); ok(strpos($imp, "adm_tool_revision") !== false && strpos($imp, 'INSERT IGNORE INTO categories') !== false && strpos($imp, 'never overwrite a managed record') !== false, 'the JSON import never overwrites managed records once the module is installed');
$new = (string)file_get_contents("$root/adm_tools_core.php") . (string)file_get_contents("$root/adm_tools_views.php");
ok(!preg_match('/DELETE\s+FROM\s+tools|TRUNCATE|DROP\s+TABLE/i', $new), 'no hard-delete or destructive SQL in the module');
ok(!preg_match('/write_json|file_put_contents|fopen\(/', $new), 'the module never writes tools.json or any file');
ok(!preg_match('/\buni_[a-z_]+|universe-editor|uni_core/', $new), 'the module does not touch AI Universe tables or code');
ok(!preg_match('/\bALTER\s+TABLE\b/i', $new), 'the module never alters tables');
$ac = (string)file_get_contents("$root/admin-center.php"); ok(strpos($ac, 'crm_csrf_ok()') !== false && strpos($ac, 'crm_csrf_ok()') < strpos($ac, "'tool_'"), 'tool POSTs reach the handler only after the CSRF and same-origin check');
ok(strpos($ac, "if (!in_array(\$method, ['GET', 'HEAD'], true))") !== false, 'methods other than GET, HEAD and POST are still refused');
ok(strpos(file_get_contents("$root/adm_core.php"), "'internal' => true, 'perm' => 'tools'") !== false, 'the tools module is registered as live');
$sec = json_decode((string)file_get_contents("$root/tests/security_manifest.json"), true); ok(($sec['files']['adm_tools_core.php'] ?? '') === 'lib' && ($sec['files']['adm_tools_views.php'] ?? '') === 'lib', 'new files are classified in the security manifest');
echo "\nPASSED: $pass  FAILED: $fail\n"; exit($fail ? 1 : 0);
