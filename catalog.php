<?php
/**
 * Catalogue quality layer (Phase 2).
 *
 *  - Taxonomy: categories + subcategories (data/taxonomy.json)
 *  - Classifier: transparent, rule-based first-pass proposals (scripts/classify_tools.php writes data/classification.json)
 *  - Curated profiles: evidence-backed editorial profiles with sources and review status (data/curated_profiles.json)
 *  - Overlay: applies classification + curated profiles on top of the raw tool records (JSON or MySQL)
 *
 * No network access and no database access happen in this file.
 */

const CATALOG_UNCLASSIFIED = 'Other';

function catalog_json(string $file, $default = []) {
  static $cache = [];
  if (array_key_exists($file, $cache)) return $cache[$file];
  $path = __DIR__ . '/data/' . $file;
  $raw = is_file($path) ? file_get_contents($path) : false;
  $data = $raw !== false ? json_decode($raw, true) : null;
  return $cache[$file] = is_array($data) ? $data : $default;
}

function taxonomy(): array { return catalog_json('taxonomy.json', ['categories' => []]); }

/** Ordered list of category names, "Other" always last. */
function taxonomy_categories(): array {
  $names = array_keys(taxonomy()['categories'] ?? []);
  $names = array_values(array_diff($names, [CATALOG_UNCLASSIFIED]));
  sort($names, SORT_NATURAL | SORT_FLAG_CASE);
  $names[] = CATALOG_UNCLASSIFIED;
  return $names;
}

function taxonomy_subcategories(string $category): array {
  return array_keys(taxonomy()['categories'][$category]['subcategories'] ?? []);
}

function taxonomy_category_description(string $category): string {
  return (string)(taxonomy()['categories'][$category]['description'] ?? '');
}

/** Legacy category names that now redirect (e.g. SEO -> Marketing). */
function taxonomy_legacy_redirect(string $name): ?string {
  return taxonomy()['legacy_redirects'][$name] ?? null;
}

function subcategory_slug(string $sub): string { return seo_slugify($sub); }

/** Resolve a subcategory from a slug or name within a category; returns the canonical name or null. */
function subcategory_resolve(string $category, string $value): ?string {
  foreach (taxonomy_subcategories($category) as $name) {
    if ($name === $value || subcategory_slug($name) === seo_slugify($value)) return $name;
  }
  return null;
}

// ---------------------------------------------------------------------------
// Classifier
// ---------------------------------------------------------------------------

function classifier_match(string $keyword, string $text): bool {
  if ($keyword === '') return false;
  if ($keyword[0] === '=') {
    return (bool)preg_match('/(?<![\p{L}\p{N}])' . preg_quote(substr($keyword, 1), '/') . '(?![\p{L}\p{N}])/iu', $text);
  }
  return (bool)preg_match('/(?<![\p{L}\p{N}])' . preg_quote($keyword, '/') . '/iu', $text);
}

/**
 * Score every subcategory against a tool's name and one-line description.
 * Weights: strong keyword = 2, kw = 1, plus 1 when the keyword appears in both the name and the description.
 * The category is chosen by its summed evidence (so one ambiguous keyword cannot outvote several consistent
 * signals), the subcategory by its own score. Confidence compares the winning category with the runner-up category.
 */
function classify_text(string $name, string $text): array {
  $subScores = []; $catScores = [];
  foreach (taxonomy()['categories'] ?? [] as $cat => $def) {
    if ($cat === CATALOG_UNCLASSIFIED) continue;
    foreach ($def['subcategories'] as $sub => $rules) {
      $score = 0; $signals = [];
      foreach (['strong' => 2, 'kw' => 1] as $type => $weight) {
        foreach ($rules[$type] ?? [] as $kw) {
          $inName = classifier_match($kw, $name);
          $inText = classifier_match($kw, $text);
          // Agreement between name and description is stronger evidence than a name-only match
          // (brand names such as "Companion" or "Dream" often say little about the product).
          if ($inName && $inText) { $score += $weight + 1; $signals[] = ltrim($kw, '=') . '@name'; }
          elseif ($inName || $inText) { $score += $weight; $signals[] = ltrim($kw, '=') . ($inName ? '@name' : ''); }
        }
      }
      if ($score > 0) {
        $subScores[$cat][$sub] = ['score' => $score, 'signals' => $signals];
        $catScores[$cat] = ($catScores[$cat] ?? 0) + $score;
      }
    }
  }
  if (!$catScores) return ['category' => CATALOG_UNCLASSIFIED, 'subcategory' => 'Unclassified', 'score' => 0, 'margin' => 0, 'confidence' => 'none', 'signals' => []];
  arsort($catScores);
  $cats = array_keys($catScores);
  $bestCat = $cats[0];
  $runner = $catScores[$cats[1] ?? ''] ?? 0;
  uasort($subScores[$bestCat], fn($a, $b) => $b['score'] <=> $a['score']);
  $bestSub = array_key_first($subScores[$bestCat]);
  $score = $catScores[$bestCat];
  $margin = $score - $runner;
  $confidence = ($score >= 3 && $margin >= 2) ? 'high' : (($score >= 2 && $margin >= 1) ? 'medium' : 'low');
  return ['category' => $bestCat, 'subcategory' => $bestSub, 'score' => $score, 'margin' => $margin, 'confidence' => $confidence, 'signals' => array_values(array_unique($subScores[$bestCat][$bestSub]['signals']))];
}

function classify_tool(array $tool): array {
  return classify_text((string)($tool['name'] ?? ''), (string)($tool['short'] ?? ''));
}

// ---------------------------------------------------------------------------
// Stored classification + curated profiles
// ---------------------------------------------------------------------------

function classification_overrides(): array { return catalog_json('classification.json', ['tools' => []])['tools'] ?? []; }
function curated_profiles(): array { return catalog_json('curated_profiles.json', ['profiles' => []])['profiles'] ?? []; }
function curated_profile(string $id): ?array { return curated_profiles()[$id] ?? null; }

/** Review status labels shown to visitors. */
function profile_review_label(array $profile): string {
  $r = $profile['review'] ?? [];
  if (!empty($r['human_verified'])) return 'Verified by an AIHubpedia editor on ' . ($r['verified_on'] ?? '');
  return 'Evidence reviewed ' . ($r['reviewed_on'] ?? '') . ' · pending editor verification';
}

/**
 * Apply classification and curated profile to one raw tool record.
 * Curated data wins over rule-based classification; neither touches records without an entry.
 */
function catalog_overlay_tool(array $tool): array {
  $id = (string)($tool['id'] ?? '');
  $cls = classification_overrides()[$id] ?? null;
  if ($cls && !empty($cls['category'])) {
    $tool['source_category'] = $tool['category'] ?? '';
    $tool['category'] = (string)$cls['category'];
    $tool['subcategory'] = (string)($cls['subcategory'] ?? '');
    $tool['classification'] = ['confidence' => (string)($cls['confidence'] ?? 'low'), 'method' => (string)($cls['method'] ?? 'rule')];
  }
  $p = curated_profile($id);
  if ($p) {
    $tool['curated'] = $p;
    $tool['category'] = (string)($p['category'] ?? $tool['category']);
    $tool['subcategory'] = (string)($p['subcategory'] ?? ($tool['subcategory'] ?? ''));
    $tool['classification'] = ['confidence' => 'editorial', 'method' => 'editorial'];
    foreach (['short' => 'tagline', 'description' => 'summary'] as $field => $key) {
      if (!empty($p[$key])) $tool[$field] = (string)$p[$key];
    }
    foreach (['features' => 'key_features', 'pros' => 'strengths', 'cons' => 'limitations', 'use_cases' => 'use_cases', 'tags' => 'tags'] as $field => $key) {
      if (!empty($p[$key])) $tool[$field] = array_values($p[$key]);
    }
    if (!empty($p['pricing']['label'])) { $tool['pricing'] = (string)$p['pricing']['label']; $tool['pricing_type'] = (string)($p['pricing']['type'] ?? $p['pricing']['label']); }
    if (!empty($p['website'])) $tool['website'] = (string)$p['website'];
    if (!empty($p['seo_title'])) $tool['seo_title'] = (string)$p['seo_title'];
    if (!empty($p['seo_description'])) $tool['seo_description'] = (string)$p['seo_description'];
  }
  return $tool;
}

/** Quality tier used for labelling, ordering and the thin-content policy. */
function tool_quality_tier(array $tool): string {
  if (!empty($tool['curated'])) return 'reviewed';
  return tool_has_generic_profile($tool) ? 'basic' : 'standard';
}

/**
 * Thin-content policy for tool pages that only carry generic, unverified data.
 * 'index' (default, preserves Phase 1 behaviour) or 'noindex' (recommended once Search Console data confirms
 * the thin pages are not earning traffic). Set with AIHUB_THIN_TOOL_POLICY.
 */
function thin_tool_policy(): string {
  $v = getenv('AIHUB_THIN_TOOL_POLICY');
  return $v === 'noindex' ? 'noindex' : 'index';
}

function tool_is_indexable(array $tool): bool {
  return !(tool_quality_tier($tool) === 'basic' && thin_tool_policy() === 'noindex');
}

/** Alternatives for a curated tool: explicit list first, then other curated tools in the same subcategory. */
function curated_alternatives(array $tool, array $byId, int $limit = 8): array {
  $p = $tool['curated'] ?? null;
  $out = [];
  foreach (($p['alternatives'] ?? []) as $altId) {
    if (isset($byId[$altId]) && $altId !== $tool['id'] && !empty($byId[$altId]['curated'])) $out[$altId] = $byId[$altId];
  }
  return array_slice(array_values($out), 0, $limit);
}

// ---------------------------------------------------------------------------
// Curated-profile validation (used by scripts/audit_catalog.php, scripts/review_profile.php and tests)
// ---------------------------------------------------------------------------

/** @return string[] human-readable problems; empty when the profile is valid */
function curated_profile_errors(string $id, array $p, array $allIds): array {
  $e = [];
  $isDate = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
  $cat = (string)($p['category'] ?? '');
  if (!isset(taxonomy()['categories'][$cat])) $e[] = "category '$cat' is not in the taxonomy";
  elseif (!in_array((string)($p['subcategory'] ?? ''), taxonomy_subcategories($cat), true)) $e[] = "subcategory '" . ($p['subcategory'] ?? '') . "' is not in '$cat'";
  foreach (['tagline', 'summary', 'seo_title', 'seo_description'] as $f) if (trim((string)($p[$f] ?? '')) === '') $e[] = "missing $f";
  $min = ['key_features' => 2, 'strengths' => 1, 'limitations' => 1, 'use_cases' => 1, 'best_for' => 1];
  foreach ($min as $f => $n) if (count(array_filter((array)($p[$f] ?? []))) < $n) $e[] = "$f needs at least $n item(s)";
  $generic = function_exists('tool_generic_profile_values') ? tool_generic_profile_values() : [];
  foreach (['key_features', 'strengths', 'limitations', 'use_cases'] as $f) foreach ((array)($p[$f] ?? []) as $v) if (in_array($v, $generic, true)) $e[] = "$f contains generic placeholder text: $v";
  $title = (string)($p['seo_title'] ?? ''); $desc = (string)($p['seo_description'] ?? '');
  if (mb_strlen($title) > 62) $e[] = 'seo_title longer than 62 characters';
  if (mb_strlen($desc) < 50 || mb_strlen($desc) > 160) $e[] = 'seo_description must be 50-160 characters (' . mb_strlen($desc) . ')';
  $pr = (array)($p['pricing'] ?? []);
  foreach (['label', 'type', 'summary', 'confidence'] as $f) if (trim((string)($pr[$f] ?? '')) === '') $e[] = "pricing.$f missing";
  if (!$isDate($pr['as_of'] ?? null)) $e[] = 'pricing.as_of must be YYYY-MM-DD';
  $srcs = (array)($p['sources'] ?? []);
  if (!$srcs) $e[] = 'at least one source is required';
  foreach ($srcs as $i => $s) {
    if (!preg_match('#^https://#', (string)($s['url'] ?? ''))) $e[] = "sources[$i].url must be https";
    if (trim((string)($s['label'] ?? '')) === '' || !in_array($s['type'] ?? '', ['vendor', 'documentation', 'third-party', 'regulator'], true)) $e[] = "sources[$i] needs label and a valid type";
    if (!$isDate($s['retrieved'] ?? null)) $e[] = "sources[$i].retrieved must be YYYY-MM-DD";
  }
  $r = (array)($p['review'] ?? []);
  if (!$isDate($r['reviewed_on'] ?? null)) $e[] = 'review.reviewed_on must be YYYY-MM-DD';
  if (trim((string)($r['reviewed_by'] ?? '')) === '') $e[] = 'review.reviewed_by missing';
  if (!array_key_exists('human_verified', $r) || !is_bool($r['human_verified'])) $e[] = 'review.human_verified must be a boolean';
  if (!empty($r['human_verified']) && !$isDate($r['verified_on'] ?? null)) $e[] = 'human_verified requires review.verified_on';
  foreach ((array)($p['alternatives'] ?? []) as $a) {
    if ($a === $id) $e[] = 'lists itself as an alternative';
    elseif (!in_array($a, $allIds, true)) $e[] = "alternative '$a' is not in the catalogue";
  }
  return $e;
}

/** Days since a profile's evidence was collected (null when unknown). */
function profile_age_days(array $p, ?int $now = null): ?int {
  $d = $p['review']['verified_on'] ?? $p['review']['reviewed_on'] ?? null;
  $t = $d ? strtotime((string)$d) : false;
  return $t ? (int)floor((($now ?? time()) - $t) / 86400) : null;
}

// ---------------------------------------------------------------------------
// Website verification (scripts/check_websites.php stores results in data/website_checks.json)
// ---------------------------------------------------------------------------

/**
 * Classify an HTTP check of a tool's website.
 * @return array{status:string,note:string} status: ok | redirected | moved_domain | dead | blocked | error
 */
function website_check_classify(int $httpCode, string $originalUrl, string $finalUrl, string $curlError = ''): array {
  $host = fn($u) => preg_replace('/^www\./', '', strtolower((string)parse_url($u, PHP_URL_HOST)));
  if ($httpCode === 0) return ['status' => 'error', 'note' => $curlError !== '' ? $curlError : 'no response (DNS, TLS or timeout)'];
  if (in_array($httpCode, [401, 403, 429, 999], true)) return ['status' => 'blocked', 'note' => "HTTP $httpCode (bot protection or login wall; verify manually)"];
  if ($httpCode === 404 || $httpCode === 410 || $httpCode >= 500) return ['status' => 'dead', 'note' => "HTTP $httpCode"];
  if ($httpCode >= 200 && $httpCode < 400) {
    if ($host($originalUrl) !== '' && $host($finalUrl) !== '' && $host($originalUrl) !== $host($finalUrl)) return ['status' => 'moved_domain', 'note' => 'redirects to ' . $host($finalUrl)];
    return ['status' => rtrim($originalUrl, '/') === rtrim($finalUrl, '/') ? 'ok' : 'redirected', 'note' => "HTTP $httpCode"];
  }
  return ['status' => 'error', 'note' => "HTTP $httpCode"];
}
