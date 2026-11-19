<?php
if (isset($_SERVER["SCRIPT_FILENAME"]) && realpath($_SERVER["SCRIPT_FILENAME"]) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
// Phase 8C (M4-M6): categories, tags, bulk actions, submission moderation and featured placements. Pure rules over an AdmToolStore (no database needed to test).
// Every action here is OWNER-ONLY ('tools_publish', owner decision D2). Nothing sends email, takes payment or contacts anyone.
require_once __DIR__ . '/adm_tools_core.php';

const ADMT_OPS = ['category_add', 'category_rename', 'category_delete', 'tag_rename', 'bulk_review', 'bulk_apply', 'submission_approve', 'submission_reject', 'submission_needs_info', 'feature_add', 'feature_cancel'];
const ADMT_BULK_OPS = ['publish' => 'Publish', 'unpublish' => 'Unpublish', 'delete' => 'Move to trash', 'restore' => 'Restore', 'set_category' => 'Set category', 'add_tag' => 'Add tag', 'remove_tag' => 'Remove tag'];
const ADMT_BULK_MAX = 100;
function admt_today(): string { return (string)($GLOBALS['ADMT_TODAY_OVERRIDE'] ?? gmdate('Y-m-d')); }   // tests only; never set from a request

function admt_clean_name($v, int $max = 60): ?string { $n = admt_text($v, $max); return (mb_strlen($n) >= 2 && preg_match('/^[\p{L}\p{N} &.+\-]+$/u', $n)) ? $n : null; }
function admt_clean_tag($v): ?string { $n = admt_text($v, 30); return (mb_strlen($n) >= 2 && preg_match('/^[\p{L}\p{N} &.+\-]+$/u', $n)) ? $n : null; }
function admt_valid_date($d): bool { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && ($t = date_create_from_format('!Y-m-d', $d)) && $t->format('Y-m-d') === $d; }
/** active | ended | cancelled, derived from the stored status and today's date (public code is date-based, so an ended placement stops featuring by itself). */
function admt_placement_state(array $p, string $today): string { return $p['status'] === 'cancelled' ? 'cancelled' : ($p['ends_on'] < $today ? 'ended' : 'active'); }

// ---- bulk ---------------------------------------------------------------------------------------------------------------------------
/** Build the change set for a bulk action from the CURRENT stored tools; never trusts a review page. Returns ['items' => [...], 'problems' => [slug => reason], 'names' => [slug => name], 'error' => ?string]. */
function admt_bulk_plan(AdmToolStore $s, string $op, array $post): array {
  $out = ['items' => [], 'problems' => [], 'names' => [], 'error' => null];
  if (!isset(ADMT_BULK_OPS[$op])) { $out['error'] = 'Choose a bulk action.'; return $out; }
  $sel = array_values(array_unique(array_filter(array_map(fn($x) => is_string($x) ? admt_text($x, 120) : '', (array)($post['sel'] ?? [])), fn($x) => admt_valid_slug($x))));
  if (!$sel) { $out['error'] = 'Select at least one tool.'; return $out; }
  if (count($sel) > ADMT_BULK_MAX) { $out['error'] = 'Select at most ' . ADMT_BULK_MAX . ' tools at once.'; return $out; }
  $cat = null; $tag = null;
  if ($op === 'set_category') { $cat = admt_text($post['category'] ?? '', 120); if (!in_array($cat, admt_public_categories(), true)) { $out['error'] = 'Choose one of the public categories.'; return $out; } }
  if ($op === 'add_tag' || $op === 'remove_tag') { $tag = admt_clean_tag($post['tag'] ?? ''); if ($tag === null) { $out['error'] = 'Enter a tag of 2 to 30 letters, numbers, spaces, &, ., + or -.'; return $out; } }
  $vers = (array)($post['ver'] ?? []);
  foreach ($sel as $slug) {
    $t = $s->get($slug); if ($t === null) { $out['problems'][$slug] = 'Not found.'; continue; }
    $out['names'][$slug] = (string)$t['name']; $st = (string)$t['status']; $new = $t;
    if (!isset($vers[$slug]) || !is_numeric($vers[$slug]) || (int)$vers[$slug] !== (int)$t['_version']) { $out['problems'][$slug] = 'Changed by someone else since you opened the list. Reload.'; continue; }
    if ($op === 'restore') { if ($st !== 'deleted') { $out['problems'][$slug] = 'Not in the trash.'; continue; } $pub = false; foreach ($s->revisions($slug) as $rv) if ($rv['action'] === 'publish') { $pub = true; break; } $new['status'] = $pub ? 'unpublished' : 'draft'; }
    elseif (in_array($op, ['publish', 'unpublish', 'delete'], true)) {
      $to = admt_transition($op, $st); if ($to === null) { $out['problems'][$slug] = "A {$st} tool cannot be changed that way."; continue; }
      if ($op === 'publish' && ($miss = admt_readiness($t))) { $out['problems'][$slug] = 'Not ready to publish. Missing: ' . implode(', ', $miss) . '.'; continue; }
      $new['status'] = $to;
    } else {
      if ($st === 'deleted') { $out['problems'][$slug] = 'A deleted tool cannot be edited.'; continue; }
      $lk = admt_overlay($slug)['locked'];
      if ($op === 'set_category' && in_array('category', $lk, true)) { $out['problems'][$slug] = 'No public effect: the category is controlled by the editorial classification (public category: ' . (admt_overlay($slug)['public_category'] ?? '') . ').'; continue; }
      if ($op !== 'set_category' && in_array('tags', $lk, true)) { $out['problems'][$slug] = 'No public effect: tags are controlled by the curated editorial profile.'; continue; }
      if ($op === 'set_category') $new['category'] = $cat;
      elseif ($op === 'add_tag') { $tags = (array)$t['tags']; if (!in_array(mb_strtolower($tag), array_map('mb_strtolower', $tags), true)) $tags[] = $tag; if (count($tags) > 12) { $out['problems'][$slug] = 'Already has 12 tags.'; continue; } $new['tags'] = array_values($tags); }
      else $new['tags'] = array_values(array_filter((array)$t['tags'], fn($x) => mb_strtolower((string)$x) !== mb_strtolower($tag)));
    }
    if (admt_diff($t, $new)) $out['items'][] = ['slug' => $slug, 'tool' => $new, 'expected' => (int)$t['_version']];
  }
  return $out;
}

// ---- submissions --------------------------------------------------------------------------------------------------------------------
/** Why a submission cannot be approved yet (empty = fine). Same rule as the legacy admin: paid plans need confirmed payment. No payment is taken here. */
function admt_submission_blockers(array $sub): array {
  $b = []; if ((string)$sub['status'] !== 'pending') $b[] = 'It has already been decided.';
  if ((int)$sub['price'] > 0 && (string)$sub['payment_status'] !== 'paid') $b[] = 'The plan is paid and payment is not confirmed.';
  if (sec_safe_external_url((string)$sub['website']) === null) $b[] = 'The website address is not a valid public http(s) address.';
  return $b;
}
/** The DRAFT created from a submission: only what the submitter provided. No features, pros, cons or tags are invented. Returns [errors, tool]. */
function admt_submission_draft(array $sub, array $existingSlugs, array $cats, array $post): array {
  $e = []; $name = admt_text($sub['name'] ?? '', 120); if (mb_strlen($name) < 2) $e['name'] = 'The submitted name is too short.';
  $cat = admt_text($post['category'] ?? '', 120); if (!in_array($cat, admt_public_categories(), true)) $e['category'] = 'Choose one of the public categories.';
  $short = admt_text(($post['short'] ?? '') !== '' ? $post['short'] : ($sub['short_desc'] ?? ''), 300); if (mb_strlen($short) < 20) $e['short'] = 'The short description must be 20 to 300 characters; edit it here.';
  $web = sec_safe_external_url((string)($sub['website'] ?? '')); if ($web === null) $e['website'] = 'The website address is not valid.';
  $base = admt_text($post['slug'] ?? '', 120); if ($base === '') $base = admt_slugify($name); $slug = $base; $taken = array_flip(array_merge($existingSlugs, admt_reserved_slugs()));
  if (!admt_valid_slug($slug)) $e['slug'] = 'Use 2 to 120 lowercase letters, numbers and hyphens.';
  else { for ($i = 2; isset($taken[$slug]) && $i < 20; $i++) $slug = substr($base, 0, 115) . '-' . $i; if (isset($taken[$slug])) $e['slug'] = 'That address is in use.'; }
  return [$e, ['slug' => $slug, 'name' => $name, 'category' => $cat, 'short' => $short, 'description' => '', 'features' => [], 'pros' => [], 'cons' => [], 'use_cases' => [], 'pricing' => '', 'pricing_type' => 'Varies', 'website' => $web ?? '', 'affiliate' => '', 'logo' => '', 'tags' => [], 'seo_title' => '', 'seo_description' => '', 'status' => 'draft']];
}

// ---- engine -------------------------------------------------------------------------------------------------------------------------
/** Called by admt_apply() after the permission check. Same result shape; 'goto' = URL parameters for the success redirect, 'get' = where to re-render on failure. */
function admt_apply_ops(AdmToolStore $s, array $user, string $act, array $post, callable $audit): array {
  $actor = (string)($user['username'] ?? 'unknown'); $note = admt_text($post['note'] ?? '', 500);
  $fail = fn(int $c, string $m, array $e = [], array $get = ['view' => 'list']) => ['ok' => false, 'code' => $c, 'msg' => $m, 'slug' => null, 'errors' => $e + ['_' => $m], 'get' => $get];
  $ok = fn(string $msg, array $goto, ?string $slug = null) => ['ok' => true, 'code' => 303, 'msg' => $msg, 'slug' => $slug, 'errors' => [], 'goto' => $goto];
  $catv = ['view' => 'categories'];
  switch ($act) {
    case 'category_add':
      $n = admt_clean_name($post['name'] ?? ''); if ($n === null || !in_array($n, admt_public_categories(), true)) return $fail(422, 'Only the public categories can be added. Choose one from the list; changing the public taxonomy is an editorial task.', ['name' => 'Choose a public category.'], $catv);
      $r = $s->addCategory($n); if (!$r['ok']) return $fail(409, 'That category already exists in the database.', [], $catv);
      $audit('tool_category_add', $n, 'added'); return $ok('category_added', $catv);
    case 'category_rename':
      $old = admt_text($post['old'] ?? '', 120); $n = admt_clean_name($post['name'] ?? '');
      if (in_array($old, admt_protected_categories(), true)) return $fail(409, 'Protected: "' . $old . '" is part of the public taxonomy or is used by the editorial classification, so it cannot be renamed here.', [], $catv);
      if ($n === null || !in_array($n, admt_public_categories(), true)) return $fail(422, 'A category can only be renamed to a public category name.', [], $catv);
      $r = $s->renameCategory($old, $n); if (!$r['ok']) return $fail($r['error'] === 'missing' ? 404 : 409, $r['error'] === 'missing' ? 'Category not found.' : 'A category with that name already exists.', [], $catv);
      $audit('tool_category_rename', $old, 'to ' . $n); return $ok('category_renamed', $catv);
    case 'category_delete':
      $old = admt_text($post['old'] ?? '', 120);
      if (in_array($old, admt_protected_categories(), true)) return $fail(409, 'Protected: "' . $old . '" is part of the public taxonomy or is used by the editorial classification, so it cannot be deleted here.', [], $catv);
      if (($post['confirm'] ?? '') !== '1') return $fail(422, 'Tick the confirmation box to delete an empty category.', [], $catv);
      $r = $s->deleteCategory($old);
      if (!$r['ok']) return $fail($r['error'] === 'in_use' ? 409 : 404, $r['error'] === 'in_use' ? 'A category that still has tools cannot be deleted. Move its tools first.' : 'Category not found.', [], $catv);
      $audit('tool_category_delete', $old, 'deleted (was empty)'); return $ok('category_deleted', $catv);
    case 'tag_rename':
      $old = admt_clean_tag($post['old'] ?? ''); $new = admt_clean_tag($post['new'] ?? ''); if ($old === null || $new === null) return $fail(422, 'Enter the current tag and the new tag (2 to 30 letters, numbers, spaces, &, ., + or -).', [], $catv);
      $items = [];
      foreach ($s->all() as $t) {
        if ($t['status'] === 'deleted' || !in_array(mb_strtolower($old), array_map('mb_strtolower', (array)$t['tags']), true) || in_array('tags', admt_overlay((string)$t['slug'])['locked'], true)) continue;   // curated tags are controlled by the editorial profile
        $tags = []; foreach ((array)$t['tags'] as $g) { $g = mb_strtolower($g) === mb_strtolower($old) ? $new : $g; if (!in_array(mb_strtolower($g), array_map('mb_strtolower', $tags), true)) $tags[] = $g; }
        $items[] = ['slug' => $t['slug'], 'tool' => ['tags' => $tags] + $t, 'expected' => (int)$t['_version']];
      }
      if (!$items) return $fail(404, 'No active tool with editable tags uses that tag.', [], $catv); if (count($items) > ADMT_BULK_MAX) return $fail(422, 'More than ' . ADMT_BULK_MAX . ' tools use that tag; narrow it first.', [], $catv);
      $r = $s->bulkUpdate($items, 'tags', $actor, "tag '$old' to '$new'"); if (!$r['ok']) return $fail(409, 'A tool changed while renaming. Nothing was changed; try again.', [], $catv);
      $audit('tool_tag_rename', $old, 'to ' . $new . ' on ' . count($items) . ' tools'); return $ok('tag_renamed', $catv);
    case 'bulk_review': case 'bulk_apply':
      $op = admt_text($post['op'] ?? '', 20); $plan = admt_bulk_plan($s, $op, $post);
      if ($plan['error'] !== null) return $fail(422, $plan['error']);
      if ($act === 'bulk_review') return ['ok' => true, 'code' => 200, 'msg' => 'review', 'slug' => null, 'errors' => [], 'review' => ['op' => $op, 'plan' => $plan, 'post' => $post]];
      if ($op === 'delete' && ($post['confirm'] ?? '') !== '1') return ['ok' => false, 'code' => 422, 'msg' => 'Tick the confirmation box to move these tools to the trash.', 'slug' => null, 'errors' => ['_' => 'Confirmation missing.'], 'review' => ['op' => $op, 'plan' => $plan, 'post' => $post]];
      if ($plan['problems']) return ['ok' => false, 'code' => 409, 'msg' => 'Nothing was changed: fix or deselect the tools with problems.', 'slug' => null, 'errors' => ['_' => 'Nothing was changed.'], 'review' => ['op' => $op, 'plan' => $plan, 'post' => $post]];
      if (!$plan['items']) return $fail(422, 'Nothing to change: the selected tools already match.');
      $r = $s->bulkUpdate($plan['items'], $op === 'set_category' ? 'edit' : $op, $actor, 'bulk ' . $op); if (!$r['ok']) return $fail(409, 'A tool changed while saving. Nothing was changed; reload and try again.');
      foreach ($plan['items'] as $i) $audit('tool_bulk_' . $op, $i['slug'], 'bulk of ' . count($plan['items']));
      return $ok('bulk', ['view' => 'list']);
    case 'submission_approve': case 'submission_reject': case 'submission_needs_info':
      $id = (int)($post['id'] ?? 0); $sub = $id > 0 ? $s->submission($id) : null; $get = ['view' => 'submission', 'id' => (string)$id];
      if ($sub === null) return $fail(404, 'Submission not found.', [], ['view' => 'submissions']);
      if ($act !== 'submission_approve') {
        if (mb_strlen($note) < 5) return $fail(422, 'Write a short reason (at least 5 characters). It is saved with the decision.', ['note' => 'A reason is required.'], $get);
        $dec = $act === 'submission_reject' ? 'rejected' : 'needs_info'; $r = $s->decideSubmission($id, $dec, null, $actor, $note);
        if (!$r['ok']) return $fail($r['error'] === 'decided' ? 409 : 404, $r['error'] === 'decided' ? 'This submission has already been decided.' : 'Submission not found.', [], $get);
        $audit('tool_submission_' . $dec, '#' . $id, $dec); return $ok($dec === 'rejected' ? 'sub_rejected' : 'sub_info', ['view' => 'submissions']);
      }
      if ($b = admt_submission_blockers($sub)) return $fail(409, 'Cannot approve: ' . implode(' ', $b), [], $get);
      [$e, $draft] = admt_submission_draft($sub, array_column($s->all(), 'slug'), $s->categories(), $post); if ($e) return $fail(422, 'Please correct the highlighted fields.', $e, $get);
      $r = $s->decideSubmission($id, 'approved', $draft, $actor, $note);
      if (!$r['ok']) return $fail($r['error'] === 'decided' || $r['error'] === 'exists' ? 409 : 500, $r['error'] === 'decided' ? 'This submission has already been decided.' : ($r['error'] === 'exists' ? 'That address is already in use.' : 'The submission could not be saved.'), [], $get);
      $audit('tool_submission_approved', '#' . $id, 'draft ' . $draft['slug']); return $ok('sub_approved', ['view' => 'detail', 'slug' => $draft['slug']], $draft['slug']);
    case 'feature_add':
      $slug = admt_text($post['slug'] ?? '', 120); $t = admt_valid_slug($slug) ? $s->get($slug) : null; $get = ['view' => 'featured', 'slug' => $slug];
      if ($t === null) return $fail(404, 'Tool not found.', [], ['view' => 'featured']);
      if ($t['status'] !== 'published') return $fail(409, 'Only a published tool can be featured.', [], $get);
      $lvl = (int)($post['level'] ?? 0); if (!in_array($lvl, [1, 2], true)) return $fail(422, 'Choose level 1 or 2.', ['level' => 'Choose level 1 or 2.'], $get);
      $today = admt_today(); $end = (string)($post['ends_on'] ?? '');
      if (!admt_valid_date($end) || $end <= $today || $end > gmdate('Y-m-d', strtotime($today . ' +365 days'))) return $fail(422, 'Choose an end date after today and within one year.', ['ends_on' => 'Choose an end date after today and within one year.'], $get);
      foreach ($s->placements($slug) as $p) if (admt_placement_state($p, $today) === 'active') return $fail(409, 'This tool already has an active placement. Cancel it first.', [], $get);
      $r = $s->addPlacement($slug, $lvl, $today, $end, $actor, $note, (int)$t['_version']); if (!$r['ok']) return $fail($r['error'] === 'conflict' ? 409 : 500, $r['error'] === 'conflict' ? 'This tool was changed by someone else. Reload and try again.' : 'The placement could not be saved.', [], $get);
      $audit('tool_feature_add', $slug, "level $lvl until $end"); return $ok('featured', ['view' => 'featured']);
    case 'feature_cancel':
      $id = (int)($post['id'] ?? 0); $slug = admt_text($post['slug'] ?? '', 120); $r = $s->cancelPlacement($id, $actor, $note, (int)($post['version'] ?? -1));
      if (!$r['ok']) return $fail($r['error'] === 'missing' ? 404 : 409, $r['error'] === 'conflict' ? 'This tool was changed by someone else. Reload and try again.' : 'That placement cannot be cancelled.', [], ['view' => 'featured']);
      $audit('tool_feature_cancel', $slug, '#' . $id); return $ok('unfeatured', ['view' => 'featured']);
  }
  return $fail(400, 'Unknown action.');
}
