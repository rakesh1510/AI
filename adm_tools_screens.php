<?php
if (isset($_SERVER["SCRIPT_FILENAME"]) && realpath($_SERVER["SCRIPT_FILENAME"]) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
// Phase 8C (M4-M7): screens for categories and tags, bulk review, submission moderation and featured placements, plus the shared sub-navigation.
// Pure rendering over an AdmToolStore. Forms for owner-only actions are shown only to the owner (the server re-checks every action).
require_once __DIR__ . '/adm_tools_ops.php';

function admt_csrf(): string { return function_exists('crm_csrf_field') ? crm_csrf_field() : ''; }
function admt_money(array $sub): string { return (int)$sub['price'] > 0 ? admt_h(number_format((int)$sub['price']) . ' ' . $sub['currency']) : 'Free'; }

function admt_subnav(AdmToolStore $s, string $view): string {
  $pending = count(array_filter($s->submissions(), fn($x) => $x['status'] === 'pending'));
  $tabs = [['list', 'Tools', ['view' => 'list'], null], ['categories', 'Categories and tags', ['view' => 'categories'], null], ['submissions', 'Submissions', ['view' => 'submissions'], $pending], ['featured', 'Featured', ['view' => 'featured'], null]];
  $cur = ['detail' => 'list', 'new' => 'list', 'edit' => 'list', 'bulk' => 'list', 'submission' => 'submissions'][$view] ?? $view;
  $o = '<nav aria-label="Tool management sections"><ul class="tTabs tSub">';
  foreach ($tabs as [$k, $lab, $q, $n]) $o .= '<li><a href="' . admt_h(admt_url($q === ['view' => 'list'] ? [] : $q)) . '"' . ($cur === $k ? ' aria-current="page"' : '') . '>' . admt_h($lab) . ($n ? ' <span class="badge">' . (int)$n . ' pending</span>' : '') . '</a></li>';
  return $o . '</ul></nav>';
}

function admt_categories_view(AdmToolStore $s, array $user, ?string $flash, ?string $top): string {
  $owner = adm_can((string)($user['role'] ?? ''), 'tools_publish'); $counts = $s->categoryCounts();
  $o = '<h1>Categories and tags</h1><p class="lead">Categories are shared by every tool. Renaming one changes its name everywhere it is used in the database.</p><p class="warn">Editorial classification and curated profile files (data/classification.json, data/curated_profiles.json) keep their own category names and take precedence on the public site. Renaming a category they use can split it into two public categories, so rename only categories that those files do not use.</p>' . admt_flash($flash) . admt_flash($top, true);
  $o .= '<section class="panel" aria-labelledby="c-h"><h2 id="c-h">Categories</h2><ul class="tRows">';
  foreach ($counts as $name => $n) {
    $o .= '<li class="tRow"><div><strong>' . admt_h($name) . '</strong> <span class="badge">' . (int)$n . ' tool' . ($n === 1 ? '' : 's') . '</span></div>';
    if ($owner) {
      $o .= '<form method="post" action="/admin-center.php?m=tools" class="tInline">' . admt_csrf() . '<input type="hidden" name="act" value="tool_category_rename"><input type="hidden" name="old" value="' . admt_h($name) . '"><label>New name<input type="text" name="name" value="' . admt_h($name) . '" maxlength="60" required></label><button class="tBtn" type="submit">Rename</button></form>';
      if ($n === 0) $o .= '<form method="post" action="/admin-center.php?m=tools" class="tInline">' . admt_csrf() . '<input type="hidden" name="act" value="tool_category_delete"><input type="hidden" name="old" value="' . admt_h($name) . '"><label class="chk"><input type="checkbox" name="confirm" value="1" required> Confirm</label><button class="tBtn danger" type="submit">Delete empty category</button></form>';
    }
    $o .= '</li>';
  }
  $o .= '</ul>' . (!$counts ? '<p>No categories yet.</p>' : '');
  if ($owner) $o .= '<form method="post" action="/admin-center.php?m=tools" class="tForm tInline">' . admt_csrf() . '<input type="hidden" name="act" value="tool_category_add"><label for="cat_new">New category<input id="cat_new" type="text" name="name" maxlength="60" required></label><button class="tBtn primary" type="submit">Add category</button></form>';
  $o .= '</section><section class="panel" aria-labelledby="g-h"><h2 id="g-h">Tags</h2>';
  $tags = []; foreach ($s->all() as $t) if ($t['status'] !== 'deleted') foreach ((array)$t['tags'] as $g) { $k = mb_strtolower($g); $tags[$k] = ($tags[$k] ?? ['n' => 0, 'label' => $g]); $tags[$k]['n']++; }
  uasort($tags, fn($a, $b) => $b['n'] <=> $a['n'] ?: strcasecmp($a['label'], $b['label']));
  $o .= $tags ? '<ul class="tChips">' . implode('', array_map(fn($g) => '<li><span class="chip">' . admt_h($g['label']) . ' · ' . (int)$g['n'] . '</span></li>', array_slice($tags, 0, 60))) . '</ul>' : '<p>No tags in use yet.</p>';
  if ($owner) $o .= '<form method="post" action="/admin-center.php?m=tools" class="tForm tInline">' . admt_csrf() . '<input type="hidden" name="act" value="tool_tag_rename"><label for="tg_old">Rename or merge tag<input id="tg_old" name="old" type="text" maxlength="30" required></label><label for="tg_new">Into<input id="tg_new" name="new" type="text" maxlength="30" required></label><button class="tBtn" type="submit">Rename tag</button></form><p class="small">If the new tag already exists on a tool the two are merged. Every changed tool gets a history entry.</p>';
  return $o . '</section>';
}

/** Bulk review page: what will change, what is blocked. Nothing is written until the owner confirms. */
function admt_bulk_review_view(AdmToolStore $s, array $review, ?string $top): string {
  $op = $review['op']; $plan = $review['plan']; $post = $review['post']; $n = count($plan['items']);
  $o = '<p><a class="admLink" href="' . admt_h(admt_url()) . '">← Back to the tool list</a></p><h1>Review: ' . admt_h(ADMT_BULK_OPS[$op] ?? $op) . '</h1>' . admt_flash($top, true);
  $o .= '<p class="lead">' . $n . ' tool' . ($n === 1 ? '' : 's') . ' will change. This is all-or-nothing: if anything is blocked, nothing is changed.</p><section class="panel" aria-labelledby="r-h"><h2 id="r-h">Selected tools</h2><ul class="tRows">';
  $chg = array_column($plan['items'], null, 'slug');
  foreach ($plan['names'] as $slug => $name) { $o .= '<li class="tRow"><div><strong>' . admt_h($name) . '</strong> <code>' . admt_h($slug) . '</code></div><div>' . (isset($plan['problems'][$slug]) ? '<span class="err">Blocked: ' . admt_h($plan['problems'][$slug]) . '</span>' : (isset($chg[$slug]) ? '<span class="chip chip-published">Will change</span>' : '<span class="small">Already matches</span>')) . '</div></li>'; }
  foreach ($plan['problems'] as $slug => $why) if (!isset($plan['names'][$slug])) $o .= '<li class="tRow"><div><code>' . admt_h($slug) . '</code></div><div><span class="err">Blocked: ' . admt_h($why) . '</span></div></li>';
  $o .= '</ul></section>';
  if ($plan['problems']) return $o . '<p><a class="tBtn primary" href="' . admt_h(admt_url()) . '">Back to the list</a></p>';
  $o .= '<form class="tForm panel" method="post" action="/admin-center.php?m=tools">' . admt_csrf() . '<input type="hidden" name="act" value="tool_bulk_apply"><input type="hidden" name="op" value="' . admt_h($op) . '">';
  foreach ((array)($post['sel'] ?? []) as $slug) if (is_string($slug)) $o .= '<input type="hidden" name="sel[]" value="' . admt_h($slug) . '"><input type="hidden" name="ver[' . admt_h($slug) . ']" value="' . (int)($post['ver'][$slug] ?? -1) . '">';
  if ($op === 'set_category') $o .= '<input type="hidden" name="category" value="' . admt_h($post['category'] ?? '') . '"><p>New category: <strong>' . admt_h($post['category'] ?? '') . '</strong></p>';
  if ($op === 'add_tag' || $op === 'remove_tag') $o .= '<input type="hidden" name="tag" value="' . admt_h($post['tag'] ?? '') . '"><p>Tag: <strong>' . admt_h($post['tag'] ?? '') . '</strong></p>';
  if ($op === 'delete') $o .= '<label class="chk"><input type="checkbox" name="confirm" value="1" required> I confirm these tools move to the trash (they can be restored)</label>';
  return $o . '<div class="tActions"><button class="tBtn ' . ($op === 'delete' ? 'danger' : 'primary') . '" type="submit"' . ($n === 0 ? ' disabled' : '') . '>Apply to ' . $n . ' tool' . ($n === 1 ? '' : 's') . '</button><a class="tBtn" href="' . admt_h(admt_url()) . '">Cancel</a></div></form>';
}

function admt_submissions_view(AdmToolStore $s, array $user, array $get, ?string $flash, ?string $top): string {
  $f = (string)($get['status'] ?? 'pending'); $all = $s->submissions(); $rows = array_values(array_filter($all, fn($x) => $f === 'all' ? true : ($f === 'decided' ? $x['status'] !== 'pending' : $x['status'] === 'pending')));
  $o = '<h1>Submissions</h1><p class="lead">Tools sent in through the public form. Approving creates a <strong>draft</strong> only; nothing is published, charged or emailed here.</p>' . admt_flash($flash) . admt_flash($top, true);
  $o .= '<nav aria-label="Filter submissions"><ul class="tTabs">';
  foreach (['pending' => 'Pending', 'decided' => 'Decided', 'all' => 'All'] as $k => $l) $o .= '<li><a href="' . admt_h(admt_url(['view' => 'submissions', 'status' => $k])) . '"' . ($f === $k ? ' aria-current="true"' : '') . '>' . $l . '</a></li>';
  $o .= '</ul></nav>';
  if (!$rows) return $o . '<div class="panel"><h2>Nothing here</h2><p>' . ($f === 'pending' ? 'No submissions are waiting for review.' : 'No submissions match.') . '</p></div>';
  $o .= '<ul class="toolCards">';
  foreach ($rows as $x) { $blk = $x['status'] === 'pending' ? admt_submission_blockers($x) : [];
    $o .= '<li class="toolCard"><h3><a href="' . admt_h(admt_url(['view' => 'submission', 'id' => $x['id']])) . '">' . admt_h($x['name']) . '</a></h3><div>' . admt_chip($x['status'] === 'pending' ? ($x['decision'] === 'needs_info' ? 'draft' : 'unpublished') : ($x['status'] === 'approved' ? 'published' : 'deleted')) . ' <span class="small">' . admt_h($x['decision'] === 'needs_info' ? 'Needs info' : ucfirst($x['status'])) . '</span></div><div class="meta">' . admt_h($x['category']) . ' · Plan ' . admt_h($x['plan']) . ' · ' . admt_money($x) . ' · Payment: ' . admt_h(str_replace('_', ' ', $x['payment_status'])) . '</div>' . ($blk ? '<div class="warn">Blocked: ' . admt_h(implode(' ', $blk)) . '</div>' : '') . '<div class="meta">Submitted ' . admt_h($x['created_at']) . '</div></li>'; }
  return $o . '</ul>';
}

function admt_submission_view(AdmToolStore $s, array $user, int $id, array $form, array $errs, ?string $top): string {
  $x = $s->submission($id); if ($x === null) return '<h1>Submission not found</h1><p><a class="admLink" href="' . admt_h(admt_url(['view' => 'submissions'])) . '">Back to submissions</a></p>';
  $owner = adm_can((string)($user['role'] ?? ''), 'tools_publish'); $blk = admt_submission_blockers($x); $cats = $s->categories();
  $o = '<p><a class="admLink" href="' . admt_h(admt_url(['view' => 'submissions'])) . '">← All submissions</a></p><h1>' . admt_h($x['name']) . '</h1>' . admt_flash($top, true);
  $o .= '<section class="panel tDetail" aria-labelledby="s-h"><h2 id="s-h">What was submitted</h2><dl><dt>Status</dt><dd>' . admt_h(ucfirst($x['status'])) . ($x['decision'] !== '' ? ' (' . admt_h(str_replace('_', ' ', $x['decision'])) . ' by ' . admt_h($x['reviewed_by']) . ')' : '') . '</dd><dt>Category</dt><dd>' . admt_h($x['category']) . '</dd><dt>Website</dt><dd>' . admt_h($x['website']) . '</dd><dt>Description</dt><dd>' . admt_h($x['short_desc']) . '</dd><dt>Plan</dt><dd>' . admt_h($x['plan']) . ' · ' . admt_money($x) . '</dd><dt>Payment</dt><dd>' . admt_h(str_replace('_', ' ', $x['payment_status'])) . '</dd><dt>Submitted</dt><dd>' . admt_h($x['created_at']) . '</dd>' . ($x['review_note'] !== '' ? '<dt>Reviewer note</dt><dd>' . admt_h($x['review_note']) . '</dd>' : '') . ($x['tool_slug'] !== '' ? '<dt>Draft tool</dt><dd><a class="admLink" href="' . admt_h(admt_url(['view' => 'detail', 'slug' => $x['tool_slug']])) . '">' . admt_h($x['tool_slug']) . '</a></dd>' : '') . '</dl><p class="small">Submitted text is untrusted: it is shown escaped and never published automatically.</p></section>';
  if ($x['status'] !== 'pending') return $o;
  if (!$owner) return $o . '<p class="panel">Only the owner can decide submissions.</p>';
  $sug = $form['category'] ?? (in_array($x['category'], $cats, true) ? $x['category'] : '');
  $o .= '<form class="tForm panel" method="post" action="/admin-center.php?m=tools" novalidate>' . admt_csrf() . '<input type="hidden" name="id" value="' . (int)$x['id'] . '"><h2>Decision</h2>' . ($blk ? '<p class="warn">Approval is blocked: ' . admt_h(implode(' ', $blk)) . ' You can still reject it or ask for more information.</p>' : '');
  $o .= '<label for="sb_cat">Category for the draft<select id="sb_cat" name="category"' . (isset($errs['category']) ? ' aria-invalid="true" aria-describedby="sb_cat_e"' : '') . '><option value="">Choose…</option>';
  foreach ($cats as $c) $o .= '<option' . ($sug === $c ? ' selected' : '') . '>' . admt_h($c) . '</option>';
  $o .= '</select></label>' . (isset($errs['category']) ? '<span class="err" id="sb_cat_e">' . admt_h($errs['category']) . '</span>' : '');
  $o .= admt_area('short', 'Short description for the draft', (string)($form['short'] ?? $x['short_desc']), $errs, '20 to 300 characters') . admt_field('slug', 'Address (optional, from the name if empty)', (string)($form['slug'] ?? ''), $errs, 'text', ['maxlength' => '120']) . admt_area('note', 'Reason or note (required to reject or ask for information)', (string)($form['note'] ?? ''), $errs);
  return $o . '<div class="tActions"><button class="tBtn primary" type="submit" name="act" value="tool_submission_approve"' . ($blk ? ' disabled' : '') . '>Approve (creates a draft)</button><button class="tBtn" type="submit" name="act" value="tool_submission_needs_info">Needs more information</button><button class="tBtn danger" type="submit" name="act" value="tool_submission_reject">Reject</button></div></form>';
}

function admt_featured_view(AdmToolStore $s, array $user, ?string $flash, ?string $top, array $form = [], array $errs = []): string {
  $owner = adm_can((string)($user['role'] ?? ''), 'tools_publish'); $today = admt_today(); $places = $s->placements(); $o = '<h1>Featured placements</h1><p class="lead">A placement features a published tool for a limited time. It never changes editorial ranking and is labelled as sponsored on the public site as before. No payment is taken here.</p>' . admt_flash($flash) . admt_flash($top, true);
  $activeBySlug = []; foreach ($places as $p) if (admt_placement_state($p, $today) === 'active') $activeBySlug[$p['tool_slug']] = true;
  if ($owner) {
    $o .= '<form class="tForm panel" method="post" action="/admin-center.php?m=tools" novalidate>' . admt_csrf() . '<input type="hidden" name="act" value="tool_feature_add"><h2>Add a placement</h2><div class="row2"><label for="fp_tool">Published tool<select id="fp_tool" name="slug" required><option value="">Choose…</option>';
    $vers = []; foreach ($s->all() as $t) if ($t['status'] === 'published' && empty($activeBySlug[$t['slug']])) { $o .= '<option value="' . admt_h($t['slug']) . '"' . (($form['slug'] ?? '') === $t['slug'] ? ' selected' : '') . '>' . admt_h($t['name']) . '</option>'; }
    $o .= '</select></label><label for="fp_lvl">Level<select id="fp_lvl" name="level"><option value="1">1 · Featured</option><option value="2">2 · Premium</option></select></label></div>';
    $o .= '<div class="row2">' . admt_field('ends_on', 'Ends on (UTC)', (string)($form['ends_on'] ?? ''), $errs, 'date', ['required' => true, 'min' => gmdate('Y-m-d', strtotime($today . ' +1 day')), 'max' => gmdate('Y-m-d', strtotime($today . ' +365 days'))]) . admt_field('note', 'Note (optional)', '', [], 'text', ['maxlength' => '500']) . '</div>';
    $o .= '<p class="small">The placement starts today (UTC). Future-dated scheduling needs a scheduled job and is not part of Phase 8C. A tool can have one active placement at a time.</p>';
    $o .= '<div class="tActions"><button class="tBtn primary" type="submit">Add placement</button></div></form>';
  }
  $o .= '<section class="panel" aria-labelledby="fp-h"><h2 id="fp-h">Placements</h2>';
  if (!$places) return $o . '<p>No placements yet.</p></section>';
  $o .= '<ul class="tRows">';
  foreach ($places as $p) { $st = admt_placement_state($p, $today); $t = $s->get($p['tool_slug']);
    $o .= '<li class="tRow"><div><strong>' . admt_h($t['name'] ?? $p['tool_slug']) . '</strong> <span class="chip chip-' . ($st === 'active' ? 'published' : ($st === 'ended' ? 'unpublished' : 'deleted')) . '">' . admt_h(ucfirst($st)) . '</span><br><span class="small">Level ' . (int)$p['level'] . ' · ' . admt_h($p['starts_on']) . ' to ' . admt_h($p['ends_on']) . ' · by ' . admt_h($p['created_by']) . ($p['note'] !== '' ? ' · ' . admt_h($p['note']) : '') . '</span></div>';
    if ($owner && $st === 'active' && $t !== null) $o .= '<form method="post" action="/admin-center.php?m=tools" class="tInline">' . admt_csrf() . '<input type="hidden" name="act" value="tool_feature_cancel"><input type="hidden" name="id" value="' . (int)$p['id'] . '"><input type="hidden" name="slug" value="' . admt_h($p['tool_slug']) . '"><input type="hidden" name="version" value="' . (int)$t['_version'] . '"><button class="tBtn danger" type="submit">Cancel placement</button></form>';
    $o .= '</li>'; }
  return $o . '</ul></section>';
}
