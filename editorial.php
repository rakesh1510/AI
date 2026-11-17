<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly (defence in depth if web-server rules are missing)
/**
 * Editorial workflows (Phase 4, P4/P5): verified tool change log, newsletter issues and roundup drafts, content templates with
 * evidence gates and a content linter. Everything is derived from real data files; nothing is generated without a human gate.
 */

// ---------------------------------------------------------------------------
// Change log
// ---------------------------------------------------------------------------
function changelog_kinds(): array { return ['pricing' => 'Pricing or plans', 'feature' => 'Feature', 'limit' => 'Limits or terms', 'availability' => 'Availability', 'profile' => 'Profile correction']; }

/** @return string[] validation problems for a manual (verified) entry; empty when valid */
function changelog_entry_errors(array $e, array $byId, ?int $now = null): array {
  $now = $now ?? time(); $errs = [];
  $date = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && ($t = strtotime($d)) !== false && date('Y-m-d', $t) === $d;
  if (empty($e['tool']) || !isset($byId[$e['tool']])) $errs[] = 'unknown tool';
  if (!isset(changelog_kinds()[$e['kind'] ?? ''])) $errs[] = 'unknown kind';
  if (!$date($e['date'] ?? null)) $errs[] = 'date must be YYYY-MM-DD'; elseif (strtotime($e['date']) > $now + 86400) $errs[] = 'date is in the future';
  $s = trim((string)($e['summary'] ?? ''));
  if ($s === '' || mb_strlen($s) > 240) $errs[] = 'summary must be 1-240 characters';
  if (preg_match('/[<>]/', $s)) $errs[] = 'summary must be plain text';
  $ev = array_filter((array)($e['evidence'] ?? []), fn($u) => is_string($u) && sec_safe_external_url($u) !== null);
  if (!$ev) $errs[] = 'at least one valid http(s) evidence URL is required';
  if (trim((string)($e['verified_by'] ?? '')) === '') $errs[] = 'verified_by is required';
  if (!$date($e['verified_on'] ?? null)) $errs[] = 'verified_on must be YYYY-MM-DD';
  return $errs;
}

/** Entries for the public log: verified manual entries plus derived "profile reviewed" markers (clearly separate). Newest first. */
function changelog_entries(array $byId, ?int $now = null): array {
  $out = [];
  foreach ((catalog_json('tool_changelog.json', ['entries' => []])['entries'] ?? []) as $e) {
    if (changelog_entry_errors((array)$e, $byId, $now)) continue;     // invalid entries are ignored, never shown
    $out[] = ['tool' => $e['tool'], 'date' => $e['date'], 'kind' => $e['kind'], 'summary' => trim($e['summary']), 'evidence' => array_values(array_filter($e['evidence'], fn($u) => sec_safe_external_url($u) !== null)),
      'verified' => true, 'verified_by' => $e['verified_by'], 'verified_on' => $e['verified_on']];
  }
  foreach ($byId as $id => $t) {
    $p = $t['curated'] ?? null; if (!$p) continue;
    $d = $p['review']['reviewed_on'] ?? null; if (!$d || !strtotime($d)) continue;
    $out[] = ['tool' => $id, 'date' => $d, 'kind' => 'profile', 'summary' => 'Profile reviewed: ' . count((array)($p['sources'] ?? [])) . ' vendor source pages retrieved' . (!empty($p['review']['human_verified']) ? ' and verified by an editor' : '; not yet verified by an editor') . '.',
      'evidence' => [], 'verified' => false, 'derived' => true];
  }
  usort($out, fn($a, $b) => strcmp($b['date'], $a['date']) ?: strcmp((string)$a['tool'], (string)$b['tool']));
  return $out;
}

function changelog_for_tool(string $id, array $byId): array { return array_values(array_filter(changelog_entries($byId), fn($e) => $e['tool'] === $id)); }

// ---------------------------------------------------------------------------
// Newsletter issues (archive) and weekly roundup drafts
// ---------------------------------------------------------------------------
function newsletter_issues_all(): array { return catalog_json('newsletter_issues.json', ['issues' => []])['issues'] ?? []; }
function newsletter_issue_path(string $slug): string { return '/newsletter-issue.php?slug=' . rawurlencode($slug); }
function newsletter_issue_url(string $slug): string { return site_url(newsletter_issue_path($slug)); }

/** @return string[] problems that block publishing an issue */
function newsletter_issue_errors(array $i): array {
  $e = [];
  if (!preg_match('/^[a-z0-9][a-z0-9-]{2,80}$/', (string)($i['slug'] ?? ''))) $e[] = 'slug must be lowercase letters, digits and hyphens';
  if (trim((string)($i['title'] ?? '')) === '') $e[] = 'title is required';
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($i['published_on'] ?? ''))) $e[] = 'published_on is required for published issues';
  if (trim((string)($i['editor'] ?? '')) === '') $e[] = 'an editor must be named';
  $items = (array)($i['items'] ?? []);
  if (count($items) < 1) $e[] = 'at least one item is required';
  foreach ($items as $n => $it) {
    if (trim((string)($it['note'] ?? '')) === '') $e[] = "item $n has no note";
    if (empty($it['source']) || sec_safe_external_url((string)$it['source']) === null) $e[] = "item $n needs a source URL";
  }
  if (!empty($i['sponsor']) && empty($i['sponsor']['label'])) $e[] = 'a sponsor block must be labelled';
  foreach (editorial_lint(strip_tags(implode(' ', array_map(fn($it) => (string)($it['note'] ?? ''), $items)) . ' ' . (string)($i['summary'] ?? '')))['problems'] as $p) $e[] = 'content: ' . $p;
  return $e;
}

/** Published issues that pass validation, newest first. Invalid "published" issues are held back, never shown. */
function newsletter_issues_public(): array {
  $out = [];
  foreach (newsletter_issues_all() as $slug => $i) {
    $i['slug'] = $slug;
    if (($i['status'] ?? '') !== 'published' || newsletter_issue_errors($i)) continue;
    $out[$slug] = $i;
  }
  uasort($out, fn($a, $b) => strcmp($b['published_on'], $a['published_on']));
  return $out;
}

/** Draft roundup from real data: profiles reviewed in the last $days days and verified change-log entries. Always a draft. */
function newsletter_roundup_draft(array $byId, string $weekLabel, int $days = 7, ?int $now = null): array {
  $now = $now ?? time(); $since = date('Y-m-d', $now - $days * 86400); $items = [];
  foreach (changelog_entries($byId, $now) as $e) {
    if ($e['date'] < $since) continue;
    $t = $byId[$e['tool']] ?? null; if (!$t) continue;
    $src = $e['evidence'][0] ?? (($t['curated']['sources'][0]['url'] ?? ''));
    $items[] = ['tool' => $e['tool'], 'note' => $t['name'] . ': ' . $e['summary'], 'source' => $src, 'verified' => !empty($e['verified'])];
  }
  return ['slug' => 'roundup-' . strtolower($weekLabel), 'title' => 'AIHubpedia weekly roundup ' . $weekLabel, 'status' => 'draft', 'editor' => '', 'published_on' => '', 'summary' => '', 'items' => $items,
    'checklist' => ['Every item has a source URL that was opened and read', 'No claim goes beyond what the source says', 'Sponsor content, if any, is labelled and separate', 'An editor is named', 'The unsubscribe link is present in the sent version']];
}

/** Plain-text rendering of an issue for a recipient (the unsubscribe URL is supplied per recipient). */
function newsletter_render_text(array $issue, string $unsubscribeUrl): string {
  $t = $issue['title'] . "\n" . str_repeat('=', mb_strlen($issue['title'])) . "\n\n";
  if (!empty($issue['summary'])) $t .= $issue['summary'] . "\n\n";
  foreach ((array)$issue['items'] as $it) $t .= '- ' . $it['note'] . "\n  Source: " . $it['source'] . "\n";
  if (!empty($issue['sponsor'])) $t .= "\n[Sponsored: " . $issue['sponsor']['label'] . "]\n" . ($issue['sponsor']['text'] ?? '') . "\n";
  return $t . "\nYou are receiving this because you confirmed a subscription to AIHubpedia emails.\nUnsubscribe: " . $unsubscribeUrl . "\n";
}

// ---------------------------------------------------------------------------
// Content templates, evidence gates and linter
// ---------------------------------------------------------------------------
function content_templates(): array { return catalog_json('content_templates.json', ['templates' => []])['templates'] ?? []; }

/**
 * Detects the patterns that make generated pages untrustworthy: unsupported testing claims, unsupported superlatives, keyword stuffing and
 * thin text. $tested lists tool ids for which an editor recorded real hands-on testing (none today).
 * @return array{problems:string[],words:int}
 */
function editorial_lint(string $text, array $tested = []): array {
  $p = []; $plain = trim(preg_replace('/\s+/', ' ', strip_tags($text)));
  $words = $plain === '' ? 0 : count(preg_split('/\s+/u', $plain));
  if (!$tested && preg_match('/\b(we|our team|i)\s+(tested|benchmarked|ran|used|tried)\b|\bhands-on\b|\bour (tests|benchmarks|testing) (show|found|prove)|\bin our tests\b|\bwe measured\b/i', $plain)) $p[] = 'claims testing or measurement that no evidence record supports';
  if (preg_match('/\b(best|#1|number one|top[- ]rated|leading|fastest|most accurate|unbeatable)\b/i', $plain) && !preg_match('/methodolog/i', $plain)) $p[] = 'uses a superlative without pointing to a methodology';
  if (preg_match('/\b(guaranteed|100% (accurate|safe|secure)|risk[- ]free|no downsides|never fails)\b/i', $plain)) $p[] = 'makes an absolute or guaranteed claim';
  if ($words >= 40) {
    $freq = array_count_values(array_filter(array_map(fn($w) => mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $w)), preg_split('/\s+/u', $plain)), fn($w) => mb_strlen($w) >= 5));
    arsort($freq); $top = reset($freq);
    if ($top && $top / $words > 0.08) $p[] = 'one word makes up more than 8% of the text (possible keyword stuffing)';
  }
  return ['problems' => $p, 'words' => $words];
}

/**
 * Can a generated page of this template be published for these inputs? Returns reasons when not.
 * @param array $ctx tools (array of tool records), human_approved (bool)
 * @return array{pass:bool,reasons:string[]}
 */
function editorial_gate(string $templateId, array $ctx): array {
  $tpl = content_templates()[$templateId] ?? null;
  if (!$tpl) return ['pass' => false, 'reasons' => ['unknown template']];
  $r = []; $tools = $ctx['tools'] ?? [];
  $reviewed = array_filter($tools, fn($t) => !empty($t['curated']));
  if (count($tools) < (int)($tpl['min_tools'] ?? 1)) $r[] = 'needs at least ' . (int)$tpl['min_tools'] . ' tools';
  if (count($reviewed) < (int)($tpl['min_reviewed'] ?? 0)) $r[] = 'needs at least ' . (int)$tpl['min_reviewed'] . ' reviewed (sourced) tool profiles';
  foreach ($reviewed as $t) { if (count(tool_evidence($t)['sources']) < (int)($tpl['min_sources_per_tool'] ?? 2)) $r[] = ($t['name'] ?? '?') . ' has fewer than ' . (int)$tpl['min_sources_per_tool'] . ' valid sources'; }
  if (!empty($tpl['requires_tested_evidence']) && empty($ctx['tested'])) $r[] = 'this template needs a recorded hands-on test, and none exists';
  if (!empty($tpl['requires_human_approval']) && empty($ctx['human_approved'])) $r[] = 'needs approval by a named editor before publishing';
  return ['pass' => !$r, 'reasons' => $r];
}

// ---------------------------------------------------------------------------
// Pricing and capability comparison groups (template "pricing-comparison")
// ---------------------------------------------------------------------------
function pricing_group_slug(string $subcategory): string { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($subcategory)), '-'); }

/** @return array<string,array{label:string,tools:array}> reviewed-tool groups by subcategory, only those that pass the evidence gate */
function pricing_groups(array $byId): array {
  $groups = [];
  foreach ($byId as $t) { if (!empty($t['curated']) && ($t['subcategory'] ?? '') !== '') $groups[pricing_group_slug($t['subcategory'])]['tools'][] = $t; }
  $out = [];
  foreach ($groups as $slug => $g) {
    $g['label'] = (string)$g['tools'][0]['subcategory'];
    usort($g['tools'], fn($a, $b) => strcasecmp($a['name'], $b['name']));
    if (editorial_gate('pricing-comparison', ['tools' => $g['tools']])['pass']) $out[$slug] = $g;
  }
  ksort($out);
  return $out;
}

/** Posts under 250 words (excluding markup) are too thin to index: they stay readable but noindex and out of the sitemap. */
function post_word_count(array $p): int { $t = trim(preg_replace('/\s+/', ' ', strip_tags((string)($p['content'] ?? '')))); return $t === '' ? 0 : count(preg_split('/\s+/u', $t)); }
function post_is_indexable(array $p): bool { return post_word_count($p) >= 250; }
function posts_indexable(): array { return array_values(array_filter(load_posts(), 'post_is_indexable')); }

/**
 * Contextual internal links for a tool page. Problem pages are linked only when the tool really appears on them (reviewed tools only,
 * to keep page generation cheap); pricing groups only when the group passes the evidence gate.
 * @return array<int,array{label:string,url:string,why:string}>
 */
function tool_related_links(array $t, array $byId): array {
  $out = [];
  if (!empty($t['curated'])) {
    foreach (solutions_all() as $slug => $p) {       // served from the derived-data cache (cache.php), not from ten catalogue scans per page view
      foreach (solution_results_cached($slug) as $r) { if ($r['tool']['id'] === $t['id']) { $out[] = ['label' => $p['title'], 'url' => solution_path($slug), 'why' => 'Appears in the shortlist for this problem']; break; } }
      if (count($out) >= 3) break;
    }
    $sub = (string)($t['subcategory'] ?? '');
    if ($sub !== '' && isset(($g = pricing_groups($byId))[pricing_group_slug($sub)])) $out[] = ['label' => $sub . ': pricing and capability comparison', 'url' => '/pricing-comparison.php?group=' . rawurlencode(pricing_group_slug($sub)), 'why' => 'Pricing as stated, side by side'];
    $out[] = ['label' => 'Recently updated profiles and verified changes', 'url' => '/changelog.php', 'why' => 'When this profile was last reviewed'];
  }
  $out[] = ['label' => 'Get a personalised shortlist', 'url' => '/recommend.php', 'why' => 'Six questions, no sign-up'];
  return $out;
}
