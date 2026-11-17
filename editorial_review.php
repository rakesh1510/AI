<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly (defence in depth if web-server rules are missing)
/**
 * Editorial verification system (Phase 5).
 *
 * Principles
 *  - Facts are never invented here. Everything shown comes from the curated profile (sources, dates, conflicts) or from the review log.
 *  - Every fact has one of five states: documented | unknown | conflicting | stale | editor_verified.
 *  - "editor_verified" exists only when a person recorded a verification (review log entry made in an authenticated admin session, or the
 *    existing CLI scripts/review_profile.php). No code path in the site, the tests or the CI creates one on its own.
 *  - The committed review log contains no entries. Tests write to a temporary log (AIHUB_EDITORIAL_LOG) and never to data/.
 */

/** Days after which a kind of fact must be re-checked. Pricing changes fastest. */
function editorial_freshness_rules(): array {
  return [
    'pricing'   => ['label' => 'Pricing and plans',        'days' => 45],
    'features'  => ['label' => 'Features and capabilities', 'days' => 180],
    'overview'  => ['label' => 'Overview and limitations',  'days' => 365],
  ];
}

function editorial_fact_state_defs(): array {
  return [
    'editor_verified' => ['label' => 'Verified by an editor', 'tone' => 'ok'],
    'documented'      => ['label' => 'Documented by vendor sources', 'tone' => 'info'],
    'conflicting'     => ['label' => 'Sources conflict', 'tone' => 'warn'],
    'stale'           => ['label' => 'Past its re-check date', 'tone' => 'warn'],
    'unknown'         => ['label' => 'Unknown: no sourced information', 'tone' => 'muted'],
  ];
}

function editorial_review_actions(): array { return ['note', 'research_needed', 'sources_checked', 'verify', 'reject', 'reopen']; }
function editorial_verify_checklist(): array {
  return [
    'sources_opened' => 'I opened every listed source on the live vendor site',
    'pricing_checked' => 'I compared the stated pricing with the live pricing page',
    'features_checked' => 'I confirmed each listed feature on a vendor page',
    'conflicts_handled' => 'Every known source conflict is resolved or disclosed on the page',
    'no_marketing_claims' => 'No unsupported superlatives or marketing claims remain',
  ];
}

function editorial_log_path(): string {
  $p = getenv('AIHUB_EDITORIAL_LOG');
  return ($p !== false && $p !== '') ? $p : __DIR__ . '/data/editorial_review_log.json';
}

/** @return array{schema:int,entries:array<int,array>} */
function editorial_log_load(bool $fresh = false): array {
  static $cache = [];
  $path = editorial_log_path();
  if ($fresh) unset($cache[$path]);
  if (isset($cache[$path])) return $cache[$path];
  $raw = is_file($path) ? @file_get_contents($path) : false;
  $d = $raw !== false ? json_decode($raw, true) : null;
  $entries = (is_array($d) && is_array($d['entries'] ?? null)) ? array_values(array_filter($d['entries'], 'is_array')) : [];
  return $cache[$path] = ['schema' => 1, 'entries' => $entries];
}

function editorial_clean_text(string $s, int $max): string {
  $s = preg_replace('/[\r\n\t]+/', ' ', $s) ?? '';                      // single-line values: no log-injection through line breaks
  $s = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $s) ?? '');
  return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
}

/** Entries for one tool, oldest first. */
function editorial_log_for(string $toolId): array {
  return array_values(array_filter(editorial_log_load()['entries'], fn($e) => ($e['tool_id'] ?? '') === $toolId));
}

/**
 * Current human verification recorded in the log for a tool: the latest verify/reject/reopen event decides.
 * @return ?array{reviewer:string,at:string,date:string}
 */
function editorial_log_verified(string $toolId): ?array {
  $state = null;
  foreach (editorial_log_for($toolId) as $e) {
    $a = $e['action'] ?? '';
    if ($a === 'verify' && !empty($e['reviewer']) && !empty($e['at'])) $state = ['reviewer' => (string)$e['reviewer'], 'at' => (string)$e['at'], 'date' => substr((string)$e['at'], 0, 10)];
    elseif ($a === 'reject' || $a === 'reopen') $state = null;
  }
  return $state;
}

/**
 * Append a review event. Returns [ok, message]. Validation lives here so every caller (admin page, CLI, tests) is held to the same rules.
 * A 'verify' event needs a real curated profile with at least one source, a named reviewer, every checklist item ticked and an explicit
 * confirmation. $via is 'admin_session' or 'cli'; the admin page passes 'admin_session' only after admin_logged_in() and a CSRF check.
 * @return array{0:bool,1:string}
 */
function editorial_log_append(array $in, string $via, ?int $now = null): array {
  if (!in_array($via, ['admin_session', 'cli'], true)) return [false, 'invalid_channel'];
  $id = (string)($in['tool_id'] ?? '');
  $tools = tools_by_id();
  if ($id === '' || !isset($tools[$id])) return [false, 'unknown_tool'];
  $action = (string)($in['action'] ?? '');
  if (!in_array($action, editorial_review_actions(), true)) return [false, 'invalid_action'];
  $reviewer = editorial_clean_text((string)($in['reviewer'] ?? ''), 80);
  if (mb_strlen($reviewer) < 2) return [false, 'reviewer_required'];
  $note = editorial_clean_text((string)($in['note'] ?? ''), 600);
  $entry = ['tool_id' => $id, 'action' => $action, 'reviewer' => $reviewer, 'at' => gmdate('c', $now ?? time()), 'via' => $via];
  if ($note !== '') $entry['note'] = $note;
  if (in_array($action, ['note', 'research_needed', 'reject', 'reopen'], true) && $note === '') return [false, 'note_required'];
  if ($action === 'verify') {
    $p = $tools[$id]['curated'] ?? null;
    if (!$p) return [false, 'not_reviewed_profile'];
    $nSrc = count(array_filter((array)($p['sources'] ?? []), fn($s) => !empty($s['url'])));
    if ($nSrc < 1) return [false, 'no_sources'];
    if (empty($in['confirm'])) return [false, 'confirmation_required'];
    $cl = (array)($in['checklist'] ?? []);
    foreach (array_keys(editorial_verify_checklist()) as $k) if (empty($cl[$k])) return [false, 'checklist_incomplete'];
    if ((int)($in['sources_checked'] ?? -1) !== $nSrc) return [false, 'sources_count_mismatch'];
    $entry['sources_checked'] = $nSrc;
    $entry['checklist'] = array_keys(editorial_verify_checklist());
  }
  $path = editorial_log_path();
  $dir = dirname($path);
  if (!is_dir($dir) || !is_writable($dir)) return [false, 'log_unwritable'];
  $fh = @fopen($path, 'c+');
  if (!$fh) return [false, 'log_unwritable'];
  if (!flock($fh, LOCK_EX)) { fclose($fh); return [false, 'log_unwritable']; }
  $raw = stream_get_contents($fh);
  $d = $raw !== '' && $raw !== false ? json_decode($raw, true) : null;
  $entries = (is_array($d) && is_array($d['entries'] ?? null)) ? $d['entries'] : [];
  $entries[] = $entry;
  ftruncate($fh, 0); rewind($fh);
  fwrite($fh, json_encode(['schema' => 1, 'note' => 'Append-only editorial review log. Entries are created only by a person (admin session or scripts/review_profile.php).', 'entries' => $entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
  fflush($fh); flock($fh, LOCK_UN); fclose($fh);
  editorial_log_load(true);
  return [true, 'ok'];
}

function editorial_days_since(?string $date, int $now): ?int {
  $t = $date ? strtotime($date) : false;
  return $t ? (int)floor(($now - $t) / 86400) : null;
}

function editorial_conflict_kind(string $text): string {
  return preg_match('/\$|€|£|price|pricing|plan|tier|seat|per (user|month)|free/i', $text) ? 'pricing' : 'features';
}

/**
 * Per-fact evidence ledger for a tool.
 * @return array<string,array{label:string,state:string,state_label:string,tone:string,source_url:string,source_label:string,source_date:?string,
 *   expires_on:?string,days_left:?int,note:string,conflicts:array}>
 */
function editorial_fact_states(array $t, ?int $now = null): array {
  $now = $now ?? time();
  $rules = editorial_freshness_rules();
  $defs = editorial_fact_state_defs();
  $id = (string)($t['id'] ?? '');
  $p = $t['curated'] ?? null;
  $out = [];
  $sources = [];
  if ($p) $sources = array_values(array_filter((array)($p['sources'] ?? []), fn($s) => !empty($s['url']) && sec_safe_external_url((string)$s['url']) !== null));
  $cli = !empty($p['review']['human_verified']) && !empty($p['review']['verified_on']);
  $log = editorial_log_verified($id);
  $verifiedOn = $log['date'] ?? ($cli ? (string)$p['review']['verified_on'] : null);
  $conflicts = $p ? array_values((array)($p['known_source_conflicts'] ?? [])) : [];
  $byKind = ['pricing' => [], 'features' => [], 'overview' => []];
  foreach ($conflicts as $c) { $k = editorial_conflict_kind((string)$c); $byKind[$k][] = (string)$c; if ($k === 'features') $byKind['overview'][] = (string)$c; }
  $primary = $sources[0] ?? null;
  foreach ($rules as $kind => $rule) {
    $row = ['label' => $rule['label'], 'state' => 'unknown', 'source_url' => '', 'source_label' => '', 'source_date' => null, 'expires_on' => null, 'days_left' => null, 'note' => '', 'conflicts' => $byKind[$kind]];
    if (!$p) { $row['note'] = 'Not reviewed: no sourced information. This listing has not been checked against vendor sources.'; }
    elseif (!$sources) { $row['note'] = 'The profile lists no usable source.'; }
    else {
      $srcDate = $kind === 'pricing' ? ($p['pricing']['as_of'] ?? null) : ($primary['retrieved'] ?? ($p['review']['reviewed_on'] ?? null));
      if ($kind === 'pricing' && empty($p['pricing']['summary'])) { $row['note'] = 'No sourced pricing statement.'; }
      else {
        $anchor = $verifiedOn ?: $srcDate;
        $age = editorial_days_since($anchor, $now);
        $row['source_url'] = (string)$primary['url']; $row['source_label'] = (string)($primary['label'] ?? ''); $row['source_date'] = $srcDate;
        if ($age !== null) { $row['expires_on'] = gmdate('Y-m-d', strtotime($anchor) + $rule['days'] * 86400); $row['days_left'] = $rule['days'] - $age; }
        if ($byKind[$kind]) { $row['state'] = 'conflicting'; $row['note'] = 'Vendor sources disagree; see the conflict list.'; }
        elseif ($age !== null && $age > $rule['days']) { $row['state'] = 'stale'; $row['note'] = 'Last checked ' . $age . ' days ago; the rule for this fact is ' . $rule['days'] . ' days.'; }
        elseif ($verifiedOn) { $row['state'] = 'editor_verified'; $row['note'] = 'Confirmed by an editor on ' . $verifiedOn . '.'; }
        elseif ($age === null) { $row['state'] = 'unknown'; $row['note'] = 'No source date recorded.'; }
        else { $row['state'] = 'documented'; $row['note'] = 'Taken from vendor sources on ' . $srcDate . '; not yet confirmed by an editor.'; }
      }
    }
    $row['state_label'] = $defs[$row['state']]['label']; $row['tone'] = $defs[$row['state']]['tone'];
    $out[$kind] = $row;
  }
  return $out;
}

/** Overall editorial status line for admin lists. */
function editorial_status(array $t, ?int $now = null): string {
  $s = array_column(editorial_fact_states($t, $now), 'state');
  if (in_array('conflicting', $s, true)) return 'conflicting';
  if (in_array('stale', $s, true)) return 'stale';
  if ($s && count(array_unique($s)) === 1 && $s[0] === 'editor_verified') return 'editor_verified';
  if (in_array('unknown', $s, true)) return 'unknown';
  return 'documented';
}

/**
 * Concrete research tasks for a tool. Each says what a person must check; none states a fact about the tool.
 * @return array<int,array{code:string,priority:int,task:string,needs:string}>
 */
function editorial_research_tasks(array $t, ?int $now = null): array {
  $p = $t['curated'] ?? null; $name = (string)($t['name'] ?? ($t['id'] ?? ''));
  $tasks = [];
  if (!$p) {
    $tasks[] = ['code' => 'find_vendor_sources', 'priority' => 1, 'task' => "Open the official website of $name and record the pricing page, documentation and changelog URLs with the date.", 'needs' => 'live vendor pages'];
    $tasks[] = ['code' => 'replace_template_text', 'priority' => 2, 'task' => "Replace the template features, pros and cons with at least three capabilities documented on a vendor page, or leave them blank.", 'needs' => 'vendor documentation'];
    $tasks[] = ['code' => 'confirm_category', 'priority' => 3, 'task' => "Confirm the category and subcategory against what the vendor says the product is.", 'needs' => 'vendor description'];
    return $tasks;
  }
  $facts = editorial_fact_states($t, $now);
  $nSrc = count((array)($p['sources'] ?? []));
  if ($nSrc < 2) $tasks[] = ['code' => 'second_source', 'priority' => 2, 'task' => "Add a second independent vendor source (documentation or changelog) for $name.", 'needs' => 'live vendor pages'];
  foreach ($facts as $k => $f) {
    if ($f['state'] === 'conflicting') foreach ($f['conflicts'] as $c) $tasks[] = ['code' => 'resolve_conflict_' . $k, 'priority' => 1, 'task' => 'Resolve or disclose this source conflict on the live pages: ' . $c, 'needs' => 'live vendor pages'];
    if ($f['state'] === 'stale') $tasks[] = ['code' => 'recheck_' . $k, 'priority' => 1, 'task' => 'Re-check ' . strtolower($f['label']) . ' at ' . ($f['source_url'] ?: 'the vendor site') . ' (rule: ' . (editorial_freshness_rules()[$k]['days']) . ' days).', 'needs' => 'live vendor pages'];
    if ($f['state'] === 'unknown' && $k === 'pricing') $tasks[] = ['code' => 'add_pricing_source', 'priority' => 2, 'task' => "Record $name's pricing statement with its source URL and date, or state that the vendor does not publish prices.", 'needs' => 'vendor pricing page'];
  }
  if (empty($p['review']['human_verified']) && !editorial_log_verified((string)($t['id'] ?? ''))) $tasks[] = ['code' => 'editor_verification', 'priority' => 3, 'task' => "An editor verifies $name against the live vendor pages and records it with the checklist.", 'needs' => 'an authorised human editor'];
  usort($tasks, fn($a, $b) => $a['priority'] <=> $b['priority'] ?: strcmp($a['code'], $b['code']));
  return $tasks;
}

/** Number of profiles with human verification recorded (curated review block or review log). Must stay 0 until real editors act. */
function editorial_verified_count(): int {
  $n = 0;
  foreach (tools_by_id() as $id => $t) { $p = $t['curated'] ?? null; if (($p && !empty($p['review']['human_verified'])) || editorial_log_verified((string)$id)) $n++; }
  return $n;
}

/** Rows for the admin queue: the committed Phase 4 priority CSV when present, otherwise curated tools first. */
function editorial_queue_rows(int $limit = 60): array {
  $rows = []; $csv = __DIR__ . '/docs/phase4/editorial_queue.csv';
  $byId = tools_by_id();
  if (is_file($csv) && ($fh = @fopen($csv, 'r'))) {
    $head = fgetcsv($fh, 0, ',', '"', '');
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false && count($rows) < $limit) {
      if (!$head || count($r) !== count($head)) continue;
      $row = array_combine($head, $r);
      if (isset($byId[$row['id'] ?? ''])) $rows[] = ['id' => $row['id'], 'priority' => $row['priority'], 'score' => (int)$row['score']];
    }
    fclose($fh);
  }
  if (!$rows) { foreach ($byId as $id => $t) { if (count($rows) >= $limit) break; if (!empty($t['curated'])) $rows[] = ['id' => $id, 'priority' => 'P1', 'score' => 0]; } }
  return $rows;
}
