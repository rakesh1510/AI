<?php
// Phase 8C category-consistency safeguards, DATABASE-FREE. Covers: public-taxonomy-only categories, read-only fields where the editorial files control the
// public site, protected categories, no-effect bulk changes, reserved slugs, notices, and the regression that ALL 509 existing tools still resolve to the
// same public category, subcategory, classification method and curated fields as before (snapshot taken from the UNCHANGED overlay code at 6ae055a).
// Usage: php tests/phase8c_category_safeguards_test.php        (add --write to regenerate the snapshot after an intended EDITORIAL change)
$root = dirname(__DIR__); chdir($root);
ob_start(); require_once $root . '/adm_core.php'; require_once $root . '/adm_tools_views.php'; require_once $root . '/helpers.php'; ob_end_clean();
$pass = 0; $fail = 0; function ok($c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; fwrite(STDERR, "  FAIL: $m\n"); } }
$owner = ['username' => 'own', 'role' => 'owner']; $editor = ['username' => 'ed', 'role' => 'editor']; $au = function ($a, $b, $c) {};
$snapFile = "$root/tests/fixtures/phase8c_overlay_snapshot.json";
function resolve509(): array {
  $raw = json_decode(file_get_contents(dirname(__DIR__) . '/data/tools.json'), true)['tools']; $out = [];
  foreach ($raw as $t) { $r = catalog_overlay_tool($t); $c = $r['curated'] ?? null;
    $out[$t['id']] = ['category' => (string)$r['category'], 'subcategory' => (string)($r['subcategory'] ?? ''), 'method' => (string)($r['classification']['method'] ?? ''), 'curated' => $c !== null,
      'fields' => sha1(json_encode([$r['short'] ?? '', $r['description'] ?? '', $r['features'] ?? [], $r['pros'] ?? [], $r['cons'] ?? [], $r['use_cases'] ?? [], $r['tags'] ?? [], $r['pricing'] ?? '', $r['pricing_type'] ?? '', $r['website'] ?? '', $r['seo_title'] ?? '', $r['seo_description'] ?? '']))]; }
  ksort($out); return $out;
}
$live = resolve509();
if (in_array('--write', $argv ?? [], true)) { file_put_contents($snapFile, json_encode(['note' => 'Public resolution of every tools.json record through the overlay code (catalog_overlay_tool). Regenerate only for an intended editorial change: php tests/phase8c_category_safeguards_test.php --write', 'count' => count($live), 'tools' => $live], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"); echo "snapshot written\n"; exit(0); }

// ---- regression: all 509 existing public classifications are unchanged ----
$snap = json_decode((string)file_get_contents($snapFile), true); $want = $snap['tools'] ?? [];
ok(count($live) === 509 && count($want) === 509 && ($snap['count'] ?? 0) === 509, 'there are 509 tools in the catalogue and in the snapshot');
$diff = []; foreach ($want as $id => $w) if (($live[$id] ?? null) !== $w) $diff[] = $id; ok($diff === [] && array_keys($live) === array_keys($want), 'all 509 tools resolve to the same public category, subcategory, method and curated fields as the snapshot' . ($diff ? ' (changed: ' . implode(',', array_slice($diff, 0, 5)) . ')' : ''));
$tax = admt_public_categories(); ok(count($tax) === 14 && in_array('Other', $tax, true) && !in_array('SEO', $tax, true), 'there are 14 public categories (taxonomy), and the legacy name SEO is not one of them');
ok(count(array_filter($live, fn($x) => !in_array($x['category'], $tax, true))) === 0, 'every one of the 509 public categories is a taxonomy category');
ok(count(array_filter($live, fn($x) => $x['curated'])) === 20, 'there are 20 curated profiles');
$bad = []; foreach ($live as $id => $x) { $o = admt_overlay($id); if ($o['public_category'] !== $x['category'] || $o['public_subcategory'] !== $x['subcategory'] || !in_array('category', $o['locked'], true) || $o['curated'] !== $x['curated']) $bad[] = $id; }
ok($bad === [], 'the module agrees with the public site for all 509 tools and locks the category of every one' . ($bad ? ' (differs: ' . implode(',', array_slice($bad, 0, 5)) . ')' : ''));
$bad = []; foreach ($live as $id => $x) if (admt_effective_category(['slug' => $id, 'category' => 'Automation']) !== $x['category']) $bad[] = $id; ok($bad === [], 'the effective category of every tool is the public one, whatever the database label says');
$bad = []; foreach ($live as $id => $x) if (!in_array($id, admt_reserved_slugs(), true)) $bad[] = $id; ok($bad === [], 'every existing tool address is reserved against reuse');
// lock fidelity: every field the public overlay replaces is locked in the module
$raw = json_decode(file_get_contents("$root/data/tools.json"), true)['tools']; $lists = ['features', 'pros', 'cons', 'use_cases', 'tags']; $bad = [];
foreach ($raw as $t) { if (!curated_profile($t['id'])) continue; $probe = ['id' => $t['id'], 'category' => 'PROBE', 'short' => 'PROBE', 'description' => 'PROBE', 'pricing' => 'PROBE', 'pricing_type' => 'PROBE', 'website' => 'PROBE', 'seo_title' => 'PROBE', 'seo_description' => 'PROBE'] + array_fill_keys($lists, ['PROBE']); $r = catalog_overlay_tool($probe); $lk = admt_overlay($t['id'])['locked'];
  foreach (['category', 'short', 'description', 'pricing', 'pricing_type', 'website', 'seo_title', 'seo_description'] + $lists as $f) if (($r[$f] ?? null) !== ($probe[$f] ?? null) && !in_array($f, $lk, true)) $bad[] = $t['id'] . ':' . $f; }
ok($bad === [], 'every field a curated profile overrides publicly is locked in the module' . ($bad ? ' (missing: ' . implode(',', array_slice($bad, 0, 5)) . ')' : ''));
$cur = array_values(array_keys(array_filter($live, fn($x) => $x['curated'])))[0]; $cls = array_values(array_keys(array_filter($live, fn($x) => !$x['curated'])))[0];

// ---- stores seeded like production: a curated tool, a classified tool, a tool with no overlay ----
$mk = function () use ($cur, $cls) { $base = ['status' => 'published', 'short' => 'Original stored description long enough.', 'description' => 'Stored description', 'features' => ['stored feature'], 'pros' => [], 'cons' => [], 'use_cases' => [], 'tags' => ['stored'], 'pricing' => 'Stored pricing', 'pricing_type' => 'Paid', 'website' => 'https://stored.example.com', 'affiliate' => '', 'logo' => '', 'seo_title' => 'Stored', 'seo_description' => 'Stored'];
  return new AdmToolMemoryStore([$base + ['slug' => $cur, 'name' => 'Curated One', 'category' => 'Automation'], $base + ['slug' => $cls, 'name' => 'Classified One', 'category' => 'Automation'], $base + ['slug' => 'brand-new-tool', 'name' => 'Brand New', 'category' => 'Writing']], ['Automation', 'Writing']); };
$full = fn($slug, $x = []) => $x + ['slug' => $slug, 'name' => 'Renamed', 'category' => 'Writing', 'short' => 'Edited short description that is long enough.', 'description' => 'Edited', 'features' => "edited feature", 'pros' => 'p', 'cons' => 'c', 'use_cases' => 'u', 'pricing' => 'Edited pricing', 'pricing_type' => 'Free', 'website' => 'https://edited.example.com', 'affiliate' => '', 'logo' => '', 'tags' => 'edited', 'seo_title' => 'Edited', 'seo_description' => 'Edited'];
$ver = fn($s, $slug) => (int)$s->get($slug)['_version'];

// ---- categories for new and edited tools ----
$s = $mk(); $new = ['name' => 'Fresh Tool', 'short' => 'A fresh synthetic tool description for checks.', 'pricing_type' => 'Free', 'website' => 'https://fresh.example.com', 'features' => 'one'];
foreach (['SEO', 'Automationn', '', 'Code'] as $c) { $r = admt_apply($s, $owner, 'create', ['category' => $c] + $new, $au); ok(!$r['ok'] && isset($r['errors']['category']) && $s->get('fresh-tool') === null, 'a new tool cannot use a non-public category: ' . json_encode($c)); }
foreach ($tax as $c) { $s2 = $mk(); $r = admt_apply($s2, $owner, 'create', ['category' => $c] + $new, $au); if (!$r['ok']) { ok(false, "a public category is accepted: $c"); break; } } ok(true, 'every one of the 14 public categories is accepted for a new tool');
$r = admt_apply($s, $owner, 'edit', $full('brand-new-tool', ['version' => $ver($s, 'brand-new-tool'), 'category' => 'Gadgets']), $au); ok(!$r['ok'] && isset($r['errors']['category']), 'an edit cannot move an unclassified tool to a non-public category');
$r = admt_apply($s, $owner, 'edit', $full('brand-new-tool', ['version' => $ver($s, 'brand-new-tool'), 'category' => 'Marketing']), $au); ok($r['ok'] && $s->get('brand-new-tool')['category'] === 'Marketing', 'an unclassified tool can move to another public category');

// ---- classified tool: category is read-only ----
$r = admt_apply($s, $editor, 'edit', $full($cls, ['version' => $ver($s, $cls), 'category' => 'Writing', 'name' => 'Classified Renamed']), $au);
ok($r['ok'] && $s->get($cls)['name'] === 'Classified Renamed', 'an editor can still rename a classified tool');
ok($s->get($cls)['category'] === 'Automation' && !in_array('category', $s->revisions($cls)[0]['fields'], true), 'a classified tool keeps its stored category even if a different one is posted (and no phantom change is recorded)');

// ---- curated tool: overlay-controlled fields are read-only ----
$before = $s->get($cur); $r = admt_apply($s, $owner, 'edit', $full($cur, ['version' => $ver($s, $cur), 'name' => 'Curated Renamed', 'category' => 'Image', 'website' => 'https://stored.example.com']), $au); $after = $s->get($cur);
$same = true; foreach (admt_overlay($cur)['locked'] as $f) if ($after[$f] !== $before[$f]) $same = false;
ok($r['ok'] && $same && $after['name'] === 'Curated Renamed', 'editing a curated tool changes its name but none of the fields the curated profile controls');
ok($s->revisions($cur)[0]['fields'] === ['name'], 'the history records only the name change for the curated tool');
$r = admt_apply($s, $owner, 'edit', $full($cur, ['version' => $ver($s, $cur), 'pricing_type' => 'NotAType', 'features' => '']), $au); ok($r['ok'], 'locked fields are not validated or required on a curated tool (values come from the stored record)');
$r = admt_apply($s, $owner, 'unpublish', ['slug' => $cur, 'version' => $ver($s, $cur)], $au); ok($r['ok'] && $s->get($cur)['status'] === 'unpublished', 'status can still be managed for a curated tool');

// ---- bulk: no-effect changes are blocked ----
$sel = function ($slugs) use (&$s, $ver) { return ['sel' => $slugs, 'ver' => array_combine($slugs, array_map(fn($x) => $ver($s, $x), $slugs))]; };
$s = $mk(); $r = admt_apply($s, $owner, 'bulk_review', ['op' => 'set_category', 'category' => 'Marketing'] + $sel([$cur, $cls, 'brand-new-tool']), $au); $pl = $r['review']['plan'];
ok(isset($pl['problems'][$cur]) && isset($pl['problems'][$cls]) && !isset($pl['problems']['brand-new-tool']) && strpos($pl['problems'][$cls], 'No public effect') !== false, 'bulk set-category flags classified and curated tools as having no public effect and accepts the unclassified one');
$r = admt_apply($s, $owner, 'bulk_apply', ['op' => 'set_category', 'category' => 'Marketing'] + $sel([$cur, 'brand-new-tool']), $au); ok(!$r['ok'] && $s->get('brand-new-tool')['category'] === 'Writing' && $s->get($cur)['category'] === 'Automation', 'a batch containing a no-effect tool changes nothing');
$r = admt_apply($s, $owner, 'bulk_apply', ['op' => 'set_category', 'category' => 'Marketing'] + $sel(['brand-new-tool']), $au); ok($r['ok'] && $s->get('brand-new-tool')['category'] === 'Marketing', 'bulk set-category works for tools without editorial classification');
$r = admt_apply($s, $owner, 'bulk_review', ['op' => 'set_category', 'category' => 'Gadgets'] + $sel(['brand-new-tool']), $au); ok(!$r['ok'] && $r['code'] === 422, 'bulk set-category only accepts public categories');
$r = admt_apply($s, $owner, 'bulk_review', ['op' => 'add_tag', 'tag' => 'newtag'] + $sel([$cur, 'brand-new-tool']), $au); ok(isset($r['review']['plan']['problems'][$cur]) && strpos($r['review']['plan']['problems'][$cur], 'curated') !== false && !isset($r['review']['plan']['problems']['brand-new-tool']), 'bulk tag changes on a curated tool are flagged as having no public effect');
$r = admt_apply($s, $owner, 'tag_rename', ['old' => 'stored', 'new' => 'renamedtag'], $au); ok($r['ok'] && $s->get($cur)['tags'] === ['stored'] && $s->get($cls)['tags'] === ['renamedtag'], 'tag rename skips curated tools and updates the rest');

// ---- protected categories ----
$prot = admt_protected_categories(); ok(count(array_diff($tax, $prot)) === 0 && in_array('SEO', $prot, true), 'all public categories and the legacy SEO name are protected');
foreach (['SEO', 'Writing', 'Other'] as $n) { $s = new AdmToolMemoryStore([], ['SEO', 'Writing', 'Other']); $a = admt_apply($s, $owner, 'category_rename', ['old' => $n, 'name' => 'Marketing'], $au); $b = admt_apply($s, $owner, 'category_delete', ['old' => $n, 'confirm' => '1'], $au); ok(!$a['ok'] && !$b['ok'] && $a['code'] === 409 && $b['code'] === 409 && in_array($n, $s->categories(), true), "protected category $n cannot be renamed or deleted"); }
$o = admt_overlay($cur); ok($o['curated'] && $o['review'] !== '' && $o['method'] === 'editorial', 'the overlay reports the curated profile review state');

// ---- reserved slugs ----
$s = $mk(); $res = array_values(array_diff(admt_reserved_slugs(), ['brand-new-tool'])); ok(count($res) >= 509 && in_array('chatgpt', $res, true), 'reserved slugs include every catalogue tool');
foreach (['chatgpt', $cls, $cur] as $slug) { $r = admt_apply($s, $owner, 'create', ['slug' => $slug, 'category' => 'Writing'] + $new, $au); ok(!$r['ok'] && isset($r['errors']['slug']) && strpos($r['errors']['slug'], 'cannot be reused') !== false, "a slug used by the catalogue or editorial files is refused: $slug"); }
$ghost = array_keys(classification_overrides())[0]; $r = admt_apply(new AdmToolMemoryStore([], ['Writing']), $owner, 'create', ['slug' => $ghost, 'category' => 'Writing'] + $new, $au); ok(!$r['ok'], 'the check does not depend on the database: a slug missing from the store but present in the files is still refused');
$r = admt_apply($s, $owner, 'create', ['slug' => 'totally-new-address', 'category' => 'Writing'] + $new, $au); ok($r['ok'], 'an unused address is accepted');
$subs = [['id' => 1, 'name' => 'ChatGPT', 'category' => 'Writing', 'website' => 'https://x.example.com', 'short_desc' => 'A synthetic submission named like an existing tool.']]; $s = new AdmToolMemoryStore([], ['Writing'], $subs);
$r = admt_apply($s, $owner, 'submission_approve', ['id' => 1, 'category' => 'Writing'], $au); ok($r['ok'] && $s->get('chatgpt') === null && $s->get('chatgpt-2') !== null, 'approving a submission never reuses a reserved address (numbered suffix)');
$r = admt_apply($s, $owner, 'submission_approve', ['id' => 1, 'category' => 'Code'], $au); $s = new AdmToolMemoryStore([], ['Writing'], $subs); $r = admt_apply($s, $owner, 'submission_approve', ['id' => 1, 'category' => 'SEO'], $au); ok(!$r['ok'] && isset($r['errors']['category']), 'a submission cannot be approved into a non-public category');

// ---- screens ----
$s = $mk(); $s->update('brand-new-tool', ['status' => 'published'] + $s->get('brand-new-tool'), 'edit', 'x', 0, '');
$h = admt_body($s, $owner, ['view' => 'edit', 'slug' => $cur]); ok(strpos($h, 'Managed by the editorial workflow') !== false && strpos($h, 'scripts/review_profile.php') !== false && substr_count($h, 'readonly') >= 8 && strpos($h, 'Public category:') !== false && strpos($h, 'Database category: Automation') !== false && strpos($h, '<select id="f_category"') === false, 'curated edit form: explanation, read-only fields, public and database category, no category dropdown');
$h = admt_body($s, $owner, ['view' => 'edit', 'slug' => $cls]); ok(strpos($h, 'controlled by the editorial classification') !== false && strpos($h, 'scripts/classify_tools.php') !== false && strpos($h, '<select id="f_category"') === false && strpos($h, 'no curated profile yet') !== false, 'classified edit form: category read-only, reclassification is an editorial task, no-profile hint');
$h = admt_body($s, $owner, ['view' => 'edit', 'slug' => 'brand-new-tool']); preg_match('#<select id="f_category".*?</select>#s', $h, $m); ok(strpos($h, 'No editorial profile yet') !== false && preg_match_all('#<option[ >]#', $m[0] ?? '') === 15 && strpos($m[0] ?? '', 'SEO') === false, 'unclassified edit form: warning, and a category dropdown of exactly the 14 public categories');
$h = admt_body($s, $owner, ['view' => 'new']); ok(strpos($h, 'No editorial profile yet') !== false && strpos($h, 'public category') !== false, 'new tool form explains that the category is the public category');
$h = admt_body($s, $owner, ['view' => 'detail', 'slug' => $cur]); ok(strpos($h, 'Public category') !== false && strpos($h, 'Database category') !== false && strpos($h, 'not shown to visitors') !== false && strpos($h, 'Managed by the editorial workflow') !== false, 'detail: public vs database category and the curated notice');
$h = admt_body($s, $owner, ['view' => 'detail', 'slug' => 'brand-new-tool']); ok(strpos($h, 'no editorial classification') !== false && strpos($h, 'No editorial profile yet') !== false, 'detail for an unclassified tool: where its category comes from, and the warning');
$h = admt_body($s, $owner, ['view' => 'categories']); ok(substr_count($h, 'Protected') >= 14 && strpos($h, 'tool_category_rename') === false && strpos($h, 'cannot be renamed or deleted here') !== false, 'categories screen: all 14 public categories shown as protected, no rename');
$h = admt_body($s, $editor, ['view' => 'categories']); ok(strpos($h, 'tool_category_') === false, 'editor sees the categories screen read-only');
$h = admt_body($s, $owner, []); ok(strpos($h, '<span class="badge">Curated</span>') !== false && preg_match('#name="category".*?</select>#s', $h, $m2) && strpos($m2[0], 'SEO') === false, 'list: curated badge, and the category filter lists only public categories');
$h = admt_body($s, $owner, ['category' => 'Automation']); ok(strpos($h, 'Curated One') === false, 'the category filter uses the category visitors see, not the database label');

// ---- static: the safeguards never write overlays and the public code is untouched by this module ----
$code = ''; foreach (['adm_tools_core.php', 'adm_tools_ops.php', 'adm_tools_screens.php', 'adm_tools_views.php', 'adm_tools_overlay.php'] as $f) $code .= preg_replace('#/\*.*?\*/|//[^\n]*#s', '', file_get_contents("$root/$f"));
ok(!preg_match('/write_json|file_put_contents|fopen\(|rename\(|unlink\(/', $code), 'the module never writes any file, so the overlay, taxonomy and catalogue files cannot be changed by it');
ok(!preg_match('/__DIR__[^;]*(taxonomy|classification|curated_profiles|tools|sitemap)\.(json|php)/', $code) && !preg_match('/catalog\.php/', $code), 'the module builds no path to the taxonomy, classification, curated, catalogue or sitemap files (it only calls the existing read helpers)');
$sec = json_decode((string)file_get_contents("$root/tests/security_manifest.json"), true)['files'] ?? []; ok(($sec['adm_tools_overlay.php'] ?? '') === 'lib', 'the overlay reader is classified in the security manifest');
echo "\nPASSED: $pass  FAILED: $fail\n"; exit($fail ? 1 : 0);
