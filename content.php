<?php
/** Editorial content helpers: guides, alternatives, comparison tables. Data lives in data/guides.json and data/curated_profiles.json. */

function guides_all(): array { return catalog_json('guides.json', ['guides' => []])['guides'] ?? []; }
function guide_get(string $slug): ?array { $g = guides_all()[$slug] ?? null; return $g ? $g + ['slug' => $slug] : null; }
function guide_path(string $slug): string { return '/guide.php?slug=' . rawurlencode($slug); }
function guide_url(string $slug): string { return site_url(guide_path($slug)); }
function guide_author(): string { return (string)(catalog_json('guides.json', [])['author'] ?? 'AIHubpedia Editorial Team'); }

function alternatives_path(string $id): string { return '/alternatives.php?tool=' . rawurlencode($id); }
function alternatives_url(string $id): string { return site_url(alternatives_path($id)); }

/** Guides that include a given tool (for cross-linking). */
function guides_for_tool(string $id): array {
  $out = [];
  foreach (guides_all() as $slug => $g) { if (in_array($id, $g['tools'] ?? [], true)) $out[$slug] = $g; }
  return $out;
}

/** Tool ids that get an alternatives page: curated with at least three curated alternatives. */
function alternatives_eligible(array $byId): array {
  $ids = [];
  foreach ($byId as $id => $t) { if (!empty($t['curated']) && count(curated_alternatives($t, $byId, 8)) >= 3) $ids[] = $id; }
  return $ids;
}

/** Plain-language "free option" label for comparison tables. */
function profile_free_option(array $p): string {
  $pr = $p['pricing'] ?? [];
  if (!empty($pr['free_option'])) return (string)$pr['free_option'];
  $type = strtolower((string)($pr['type'] ?? ''));
  if ($type === 'free') return 'Free';
  if ($type === 'freemium') return 'Free plan with limits';
  if (strpos(strtolower((string)($pr['label'] ?? '')), 'trial') !== false) return 'Free trial';
  return 'No free plan listed';
}

/** First limitation, trimmed for table cells. */
function profile_key_limit(array $p): string { return (string)(($p['limitations'] ?? [''])[0] ?? ''); }

/** Render a responsive comparison table for tool records that carry curated profiles. */
function render_profile_table(array $tools): string {
  $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
  $out = '<div class="tableWrap" tabindex="0" role="region" aria-label="Comparison table, scrollable"><table class="compareTable"><thead><tr><th scope="col">Tool</th><th scope="col">Free option</th><th scope="col">Pricing (as of ' . $h($tools ? ($tools[0]['curated']['pricing']['as_of'] ?? '') : '') . ')</th><th scope="col">Best for</th><th scope="col">Main limitation</th></tr></thead><tbody>';
  foreach ($tools as $t) {
    $p = $t['curated'];
    $out .= '<tr><th scope="row"><a href="' . $h(tool_path($t['id'])) . '">' . $h($t['name']) . '</a></th>'
      . '<td>' . $h(profile_free_option($p)) . '</td>'
      . '<td>' . $h($p['pricing']['summary'] ?? '') . '</td>'
      . '<td>' . $h(implode('; ', array_slice($p['best_for'] ?? [], 0, 2))) . '</td>'
      . '<td>' . $h(profile_key_limit($p)) . '</td></tr>';
  }
  return $out . '</tbody></table></div>';
}
