<?php
if (isset($_SERVER["SCRIPT_FILENAME"]) && realpath($_SERVER["SCRIPT_FILENAME"]) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
// Phase 8C category-consistency safeguards. READ-ONLY view of the editorial overlays: the public site shows classification.json and curated_profiles.json
// ahead of the database (catalog.php, catalog_overlay_tool()). This file only READS them through the existing catalog helpers, so the module can
// tell the owner what visitors will really see and refuse edits that would have no public effect. It never writes any overlay, taxonomy or catalogue file.

if (!function_exists('taxonomy_categories')) require_once __DIR__ . '/helpers.php';   // the existing catalogue helpers (catalog.php); read only

/** The approved public categories (taxonomy.json), the only names a new or edited tool may use. */
function admt_public_categories(): array { return function_exists('taxonomy_categories') ? taxonomy_categories() : []; }

/** Names that must never be renamed or deleted here: taxonomy names, every category used by classification or curated files, and legacy redirect names. */
function admt_protected_categories(): array {
  $p = admt_public_categories();
  if (function_exists('classification_overrides')) foreach (classification_overrides() as $c) if (!empty($c['category'])) $p[] = (string)$c['category'];
  if (function_exists('curated_profiles')) foreach (curated_profiles() as $c) if (!empty($c['category'])) $p[] = (string)$c['category'];
  if (function_exists('taxonomy')) foreach (array_keys((array)(taxonomy()['legacy_redirects'] ?? [])) as $k) $p[] = (string)$k;
  return array_values(array_unique($p));
}

/**
 * What the editorial files control for one tool. 'locked' lists the form fields the public site takes from the overlay, so edits here would have no
 * public effect. The key mapping mirrors catalog_overlay_tool() exactly.
 */
function admt_overlay(string $slug): array {
  $cls = function_exists('classification_overrides') ? (classification_overrides()[$slug] ?? null) : null;
  $p = function_exists('curated_profile') ? curated_profile($slug) : null;
  $locked = [];
  if (($cls && !empty($cls['category'])) || $p) $locked[] = 'category';
  if ($p) {
    foreach (['short' => 'tagline', 'description' => 'summary', 'features' => 'key_features', 'pros' => 'strengths', 'cons' => 'limitations', 'use_cases' => 'use_cases', 'tags' => 'tags', 'seo_title' => 'seo_title', 'seo_description' => 'seo_description', 'website' => 'website'] as $f => $k) if (!empty($p[$k])) $locked[] = $f;
    if (!empty($p['pricing']['label'])) { $locked[] = 'pricing'; $locked[] = 'pricing_type'; }
  }
  $cat = $p ? (string)($p['category'] ?? '') : '';
  if ($cat === '' && $cls && !empty($cls['category'])) $cat = (string)$cls['category'];
  $sub = $p ? (string)($p['subcategory'] ?? '') : ($cls ? (string)($cls['subcategory'] ?? '') : '');
  $review = '';
  if ($p && function_exists('profile_review_label')) $review = profile_review_label($p);
  return ['classified' => (bool)($cls && !empty($cls['category'])), 'curated' => $p !== null, 'public_category' => $cat !== '' ? $cat : null, 'public_subcategory' => $sub, 'locked' => array_values(array_unique($locked)), 'review' => $review,
    'method' => $p ? 'editorial' : (string)($cls['method'] ?? '')];
}
/** Category visitors see: the overlay's when there is one, otherwise the tool's own. */
function admt_effective_category(array $tool): string { $o = admt_overlay((string)($tool['slug'] ?? '')); return $o['public_category'] ?? (string)($tool['category'] ?? ''); }

/** Slugs that already mean something in the seed catalogue or the editorial files. A new tool must not reuse them (it would inherit that tool's classification and profile). */
function admt_reserved_slugs(): array {
  $s = [];
  if (function_exists('read_json')) foreach ((array)(read_json('tools.json', ['tools' => []])['tools'] ?? []) as $t) if (!empty($t['id'])) $s[(string)$t['id']] = true;
  if (function_exists('classification_overrides')) foreach (array_keys(classification_overrides()) as $k) $s[(string)$k] = true;
  if (function_exists('curated_profiles')) foreach (array_keys(curated_profiles()) as $k) $s[(string)$k] = true;
  return array_keys($s);
}
