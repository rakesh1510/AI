<?php
// Writes static HTML of the tool-management screens from SYNTHETIC data (no database, no server) for accessibility and screenshot checks.
// Usage: php tests/adm_tools_preview.php OUT_DIR
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__); $out = rtrim($argv[1] ?? '', '/'); if ($out === '') { fwrite(STDERR, "usage: php tests/adm_tools_preview.php OUT_DIR\n"); exit(2); }
@mkdir($out, 0775, true); chdir($root);
ob_start(); require_once $root . '/adm_core.php'; require_once $root . '/adm_tools_views.php'; ob_end_clean();
$cats = ['Writing', 'Code', 'Audio', 'Design', 'Video']; $rows = [];
$names = ['Quill Draft', 'Pixel Forge', 'Echo Notes', 'Syntax Pilot', 'Frame Weaver', 'Tone Studio', 'Bug Lantern', 'Palette Muse', 'Clip Smith', 'Outline Owl'];
foreach ($names as $i => $n) $rows[] = ['slug' => admt_slugify($n), 'name' => $n . ' (synthetic)', 'category' => $cats[$i % 5], 'status' => ['published', 'published', 'draft', 'unpublished', 'published'][$i % 5], 'tags' => ['demo', 'synthetic'], 'featured_level' => $i === 1 ? 1 : 0, 'featured_until' => $i === 1 ? '2026-12-31' : '', 'short' => $i === 2 ? '' : 'Synthetic example description used only to preview the layout.', 'description' => '', 'website' => 'https://example.com/' . $i, 'affiliate' => '', 'logo' => '', 'pricing' => 'From 9 per month', 'pricing_type' => 'Freemium', 'features' => $i === 2 ? [] : ['Feature one', 'Feature two'], 'pros' => [], 'cons' => [], 'use_cases' => [], 'seo_title' => '', 'seo_description' => '', 'updated_at' => '2026-11-0' . ($i % 9 + 1) . ' 10:00:00', 'added' => '2026-10-01'];
$rows[] = ['status' => 'deleted', 'slug' => 'old-demo', 'name' => 'Old Demo (synthetic)'] + $rows[0];
$store = new AdmToolMemoryStore($rows, $cats); $store->update('quill-draft', ['name' => 'Quill Draft (synthetic)'] + $rows[0], 'edit', 'owner-demo', 0, 'Synthetic history entry');
$owner = ['username' => 'owner-demo', 'display_name' => 'Owner (preview)', 'role' => 'owner']; $editor = ['username' => 'editor-demo', 'display_name' => 'Editor (preview)', 'role' => 'editor'];
$pages = ['list' => [$owner, [], null], 'list-trash' => [$owner, ['status' => 'deleted'], null], 'detail-published' => [$owner, ['view' => 'detail', 'slug' => 'pixel-forge', 'msg' => 'published'], null], 'detail-draft-editor' => [$editor, ['view' => 'detail', 'slug' => 'echo-notes'], null],
  'form-new-errors' => [$owner, ['view' => 'new'], [['name' => 'X', 'website' => 'nope'], ['name' => 'Enter a name of 2 to 120 characters.', 'website' => 'Enter a public web address starting with http:// or https://.', 'category' => 'Choose one of the existing categories.'], 'Please correct the highlighted fields.']], 'form-edit' => [$owner, ['view' => 'edit', 'slug' => 'pixel-forge'], null]];
foreach ($pages as $name => [$u, $get, $f]) {
  ob_start(); @adm_page($u, 'tools', 'AI tool management', admt_body($store, $u, $get, $f[0] ?? null, $f[1] ?? [], $f[2] ?? null)); $html = ob_get_clean();
  $html = preg_replace('#<input type="hidden" name="_csrf" value="[^"]*">#', '<input type="hidden" name="_csrf" value="preview">', $html);
  file_put_contents("$out/$name.html", "<!-- synthetic preview, not live data -->\n" . $html);
}
echo count($pages) . " preview pages written to $out\n";
