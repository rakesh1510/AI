<?php
// Phase 8C M4-M7, DATABASE-FREE: categories, tags, bulk actions, submission moderation, featured placements, screens and static safeguards.
// In-memory store and synthetic data only. The MySQL store, the migration and the public site are NOT exercised here.
$root = dirname(__DIR__); chdir($root);
ob_start(); require_once $root . '/adm_core.php'; require_once $root . '/adm_tools_views.php'; require_once $root . '/helpers.php'; ob_end_clean();
$pass = 0; $fail = 0; function ok($c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; fwrite(STDERR, "  FAIL: $m\n"); } }
$GLOBALS['ADMT_TODAY_OVERRIDE'] = '2026-11-20';
$owner = ['username' => 'own', 'role' => 'owner']; $editor = ['username' => 'ed', 'role' => 'editor']; $audit = []; $au = function (string $a, string $s, string $x) use (&$audit) { $audit[] = [$a, $s, $x]; };
$cats = ['Writing', 'Coding', 'Audio', 'Lifestyle', 'Legacy Label', 'Unused Label'];
$tool = fn(string $slug, string $status = 'published', array $o = []) => $o + ['slug' => $slug, 'name' => ucfirst($slug), 'category' => 'Writing', 'status' => $status, 'short' => 'A synthetic description that is long enough.', 'website' => 'https://t.example.com', 'features' => ['f'], 'tags' => ['seo', 'ai'], 'pros' => [], 'cons' => [], 'use_cases' => [], 'description' => '', 'pricing' => '', 'pricing_type' => 'Free', 'affiliate' => '', 'logo' => '', 'seo_title' => '', 'seo_description' => ''];
$mk = fn(?array $tools = null, array $subs = []) => new AdmToolMemoryStore($tools ?: [$tool('alpha'), $tool('beta'), $tool('gamma', 'draft'), $tool('delta', 'unpublished', ['category' => 'Legacy Label', 'tags' => ['ai']])], $cats, $subs);
$ver = fn($s, $slug) => (int)$s->get($slug)['_version'];
$sel = fn($s, array $slugs) => ['sel' => $slugs, 'ver' => array_combine($slugs, array_map(fn($x) => $ver($s, $x), $slugs))];

// ---- every M4-M6 action is owner-only, enforced server side ----
$s = $mk();
foreach (ADMT_OPS as $a) { ok(admt_action_perm($a) === 'tools_publish' && adm_can('owner', 'tools_publish'), "$a needs the owner permission"); foreach ([$editor, ['username' => 's', 'role' => 'sales'], ['username' => 'v', 'role' => 'viewer'], ['username' => 'x', 'role' => 'verifier'], ['username' => 'n', 'role' => 'nobody']] as $u) { $r = admt_apply($s, $u, $a, ['name' => 'Zed', 'old' => 'Writing', 'op' => 'delete', 'sel' => ['alpha'], 'id' => 1, 'slug' => 'alpha', 'level' => 1, 'ends_on' => '2026-12-01', 'confirm' => '1'], $au); ok(!$r['ok'] && $r['code'] === 403, "{$u['role']} cannot $a (403)"); } }
ok($s->get('alpha')['status'] === 'published' && count($s->categories()) === 6 && $s->placements() === [], 'denied attempts changed nothing');

// ---- M4 categories (public taxonomy only; protected names) ----
$r = admt_apply($s, $owner, 'category_add', ['name' => 'Video'], $au); ok($r['ok'] && in_array('Video', $s->categories(), true), 'a missing public category can be added');
$r = admt_apply($s, $owner, 'category_add', ['name' => 'Video'], $au); ok(!$r['ok'] && $r['code'] === 409, 'adding it twice is refused');
foreach (['Gadgets', 'SEO', '', '<b>x</b>', "bad\x00"] as $bad) { $r = admt_apply($s, $owner, 'category_add', ['name' => $bad], $au); ok(!$r['ok'] && $r['code'] === 422 && !in_array($bad, $s->categories(), true), 'a non-public category name cannot be added: ' . json_encode($bad)); }
$r = admt_apply($s, $owner, 'category_rename', ['old' => 'Writing', 'name' => 'Marketing'], $au); ok(!$r['ok'] && $r['code'] === 409 && in_array('Writing', $s->categories(), true) && $s->get('alpha')['category'] === 'Writing', 'a protected public category cannot be renamed');
$r = admt_apply($s, $owner, 'category_rename', ['old' => 'Legacy Label', 'name' => 'Software'], $au); ok(!$r['ok'] && $r['code'] === 422, 'an unprotected label can only be renamed to a public category name');
$r = admt_apply($s, $owner, 'category_rename', ['old' => 'Legacy Label', 'name' => 'Marketing'], $au); ok($r['ok'] && $s->get('delta')['category'] === 'Marketing', 'an unprotected database label can be renamed to a public name and its tools follow');
$r = admt_apply($s, $owner, 'category_delete', ['old' => 'Marketing', 'confirm' => '1'], $au); ok(!$r['ok'] && $r['code'] === 409 && in_array('Marketing', $s->categories(), true), 'a protected public category cannot be deleted even with a confirmation');
$r = admt_apply($s, $owner, 'category_delete', ['old' => 'Unused Label'], $au); ok(!$r['ok'] && $r['code'] === 422, 'deleting an unprotected label needs the confirmation');
$r = admt_apply($s, $owner, 'category_delete', ['old' => 'Unused Label', 'confirm' => '1'], $au); ok($r['ok'] && !in_array('Unused Label', $s->categories(), true), 'an unprotected, unused label can be deleted');
ok($s->categoryCounts()['Writing'] === 3, 'category counts');

// ---- M4 tags ----
$s = $mk(); $r = admt_apply($s, $owner, 'tag_rename', ['old' => 'SEO', 'new' => 'ai'], $au); ok($r['ok'] && $s->get('alpha')['tags'] === ['ai'] && $s->get('delta')['tags'] === ['ai'], 'renaming a tag onto an existing tag merges without duplicates');
ok($s->revisions('alpha')[0]['action'] === 'tags' && $ver($s, 'alpha') === 1, 'each changed tool gets a history entry');
$r = admt_apply($s, $owner, 'tag_rename', ['old' => 'nothere', 'new' => 'x1'], $au); ok(!$r['ok'] && $r['code'] === 404, 'unused tag: not found');
$r = admt_apply($s, $owner, 'tag_rename', ['old' => 'ai', 'new' => '<x>'], $au); ok(!$r['ok'] || strpos(json_encode($s->all()), '<x>') === false, 'hostile tag name is not stored');
$many = []; for ($i = 1; $i <= 101; $i++) $many[] = $tool("t$i", 'published', ['tags' => ['big']]); $s2 = $mk($many); $r = admt_apply($s2, $owner, 'tag_rename', ['old' => 'big', 'new' => 'huge'], $au); ok(!$r['ok'] && $s2->get('t1')['tags'] === ['big'], 'more than 100 tools: refused, nothing changed');
$s3 = $mk([$tool('live', 'published', ['tags' => ['qq']]), $tool('gone', 'deleted', ['tags' => ['qq']])]); admt_apply($s3, $owner, 'tag_rename', ['old' => 'qq', 'new' => 'z9'], $au); ok($s3->get('live')['tags'] === ['z9'] && $s3->get('gone')['tags'] === ['qq'], 'trashed tools are not touched by a tag rename');

// ---- M4 bulk ----
$s = $mk([$tool('a1'), $tool('a2'), $tool('a3', 'draft'), $tool('a4', 'draft', ['features' => []]), $tool('a5', 'deleted')]);
$P = fn($op, $slugs, $x = []) => ['op' => $op] + $sel($s, $slugs) + $x;
$r = admt_apply($s, $owner, 'bulk_review', ['op' => 'publish'], $au); ok(!$r['ok'] && $r['code'] === 422, 'nothing selected: refused');
$r = admt_apply($s, $owner, 'bulk_review', ['op' => 'explode', 'sel' => ['a1']], $au); ok(!$r['ok'], 'unknown bulk action refused');
$r = admt_apply($s, $owner, 'bulk_review', ['op' => 'publish', 'sel' => array_map(fn($i) => "x$i", range(1, 101))], $au); ok(!$r['ok'] && strpos($r['msg'], '100') !== false, 'more than 100 selected: refused');
$r = admt_apply($s, $owner, 'bulk_review', $P('publish', ['a3', 'a4']), $au); ok($r['ok'] && $r['code'] === 200 && count($r['review']['plan']['items']) === 1 && isset($r['review']['plan']['problems']['a4']), 'review shows what changes and what is blocked, and writes nothing');
ok($s->get('a3')['status'] === 'draft', 'review wrote nothing');
$r = admt_apply($s, $owner, 'bulk_apply', $P('publish', ['a3', 'a4']), $au); ok(!$r['ok'] && $r['code'] === 409 && $s->get('a3')['status'] === 'draft', 'one blocked tool blocks the whole batch (all-or-nothing)');
$r = admt_apply($s, $owner, 'bulk_apply', $P('publish', ['a3']), $au); ok($r['ok'] && $s->get('a3')['status'] === 'published' && $s->revisions('a3')[0]['action'] === 'publish', 'bulk publish a ready draft');
$r = admt_apply($s, $owner, 'bulk_apply', $P('unpublish', ['a1', 'a2']), $au); ok($r['ok'] && $s->get('a1')['status'] === 'unpublished' && $s->get('a2')['status'] === 'unpublished', 'bulk unpublish');
$r = admt_apply($s, $owner, 'bulk_apply', $P('delete', ['a1', 'a2']), $au); ok(!$r['ok'] && $r['code'] === 422 && $s->get('a1')['status'] === 'unpublished', 'bulk delete needs the confirmation');
$r = admt_apply($s, $owner, 'bulk_apply', $P('delete', ['a1', 'a2'], ['confirm' => '1']), $au); ok($r['ok'] && $s->get('a1')['status'] === 'deleted' && $s->get('a2') !== null, 'bulk soft delete keeps records');
$r = admt_apply($s, $owner, 'bulk_apply', $P('restore', ['a1', 'a2']), $au); ok($r['ok'] && $s->get('a1')['status'] === 'draft' && $s->get('a2')['status'] === 'draft', 'bulk restore never goes straight to published (never-published records come back as drafts)');
$r = admt_apply($s, $owner, 'bulk_apply', $P('restore', ['a3']), $au); ok(!$r['ok'] && $s->get('a3')['status'] === 'published', 'restoring a tool that is not in the trash is blocked');
$r = admt_apply($s, $owner, 'bulk_apply', $P('set_category', ['a1', 'a3'], ['category' => 'Audio']), $au); ok($r['ok'] && $s->get('a1')['category'] === 'Audio' && $s->get('a3')['category'] === 'Audio', 'bulk set category');
$r = admt_apply($s, $owner, 'bulk_apply', $P('set_category', ['a1'], ['category' => 'Nope']), $au); ok(!$r['ok'], 'bulk set category needs an existing category');
$r = admt_apply($s, $owner, 'bulk_apply', $P('add_tag', ['a1', 'a3'], ['tag' => 'New Tag']), $au); ok($r['ok'] && in_array('New Tag', $s->get('a1')['tags'], true), 'bulk add tag');
$r = admt_apply($s, $owner, 'bulk_apply', $P('remove_tag', ['a1', 'a3'], ['tag' => 'new tag']), $au); ok($r['ok'] && !in_array('New Tag', $s->get('a3')['tags'], true), 'bulk remove tag is case-insensitive');
$r = admt_apply($s, $owner, 'bulk_apply', $P('add_tag', ['a1'], ['tag' => '<b>']), $au); ok(!$r['ok'], 'hostile tag refused');
$sel1 = $sel($s, ['a1', 'a3']); admt_apply($s, $owner, 'tag_rename', ['old' => 'seo', 'new' => 'seo2'], $au);   // someone changes the tools after the review page was shown
$before = [$s->get('a1')['category'], $s->get('a3')['category']]; $r = admt_apply($s, $owner, 'bulk_apply', ['op' => 'set_category', 'category' => 'Coding'] + $sel1, $au); ok(!$r['ok'] && $r['code'] === 409 && [$s->get('a1')['category'], $s->get('a3')['category']] === $before, 'stale review (tools changed meanwhile): nothing is applied');
$audit = []; admt_apply($s, $owner, 'bulk_apply', $P('add_tag', ['a1', 'a3'], ['tag' => 'zzz']), $au); ok(count(array_filter($audit, fn($x) => $x[0] === 'tool_bulk_add_tag')) === 2, 'one audit line per changed tool');

// ---- M5 submissions ----
$subs = [['id' => 1, 'name' => 'Free Tool', 'category' => 'Writing', 'website' => 'https://free.example.com/', 'short_desc' => 'A free synthetic tool that helps people draft text.'], ['id' => 2, 'name' => 'Paid Tool', 'category' => 'Coding', 'website' => 'https://paid.example.com/', 'short_desc' => 'A paid synthetic tool for code review.', 'plan' => 'featured', 'price' => 99, 'payment_status' => 'unpaid'],
  ['id' => 3, 'name' => '<script>alert(1)</script>Evil', 'category' => 'Writing', 'website' => 'javascript:alert(1)', 'short_desc' => 'short'], ['id' => 4, 'name' => 'Alpha', 'category' => 'Writing', 'website' => 'https://alpha2.example.com/', 'short_desc' => 'Duplicate-named synthetic tool for slug collision.'], ['id' => 5, 'name' => 'Paid OK', 'category' => 'Writing', 'website' => 'https://ok.example.com/', 'short_desc' => 'A paid and confirmed synthetic tool entry.', 'plan' => 'featured', 'price' => 99, 'payment_status' => 'paid']];
$s = $mk(null, $subs); ok(admt_submission_blockers($s->submission(2)) !== [] && admt_submission_blockers($s->submission(3)) !== [] && admt_submission_blockers($s->submission(1)) === [] && admt_submission_blockers($s->submission(5)) === [], 'blockers: unpaid paid plan and invalid website; free and paid-confirmed are fine');
$r = admt_apply($s, $owner, 'submission_approve', ['id' => 2, 'category' => 'Coding'], $au); ok(!$r['ok'] && $r['code'] === 409 && $s->submission(2)['status'] === 'pending', 'an unpaid paid-plan submission cannot be approved');
$r = admt_apply($s, $owner, 'submission_approve', ['id' => 3, 'category' => 'Writing'], $au); ok(!$r['ok'] && $s->get('evil') === null, 'a submission with an unsafe website cannot be approved');
$r = admt_apply($s, $owner, 'submission_approve', ['id' => 1], $au); ok(!$r['ok'] && $r['code'] === 422 && isset($r['errors']['category']), 'approval needs a category chosen');
$r = admt_apply($s, $owner, 'submission_approve', ['id' => 1, 'category' => 'Writing'], $au); $d = $s->get('free-tool'); ok($r['ok'] && $d['status'] === 'draft' && $d['features'] === [] && $d['pros'] === [] && $d['cons'] === [] && $d['tags'] === [] && $d['website'] === 'https://free.example.com/' && $s->submission(1)['status'] === 'approved' && $s->submission(1)['tool_slug'] === 'free-tool', 'approval creates a DRAFT with only submitted data (nothing invented)');
ok(admt_readiness($d) !== [], 'the new draft cannot be published until features are added');
$r = admt_apply($s, $owner, 'submission_approve', ['id' => 1, 'category' => 'Writing'], $au); ok(!$r['ok'] && $r['code'] === 409, 'a decided submission cannot be decided again');
$r = admt_apply($s, $owner, 'submission_approve', ['id' => 4, 'category' => 'Writing'], $au); ok($r['ok'] && $s->get('alpha-2') !== null && $s->get('alpha')['website'] === 'https://t.example.com', 'a taken address gets a numbered suffix and the existing tool is untouched');
$r = admt_apply($s, $owner, 'submission_approve', ['id' => 5, 'category' => 'Writing', 'short' => 'Edited synthetic description that is long enough.'], $au); ok($r['ok'] && $s->get('paid-ok')['short'] === 'Edited synthetic description that is long enough.' && $s->get('paid-ok')['status'] === 'draft', 'a confirmed paid submission is approved as a draft, with the short description editable');
$s = $mk(null, $subs); $r = admt_apply($s, $owner, 'submission_reject', ['id' => 1], $au); ok(!$r['ok'] && $r['code'] === 422 && $s->submission(1)['status'] === 'pending', 'rejecting needs a reason');
$r = admt_apply($s, $owner, 'submission_needs_info', ['id' => 1, 'note' => 'Please add pricing details'], $au); ok($r['ok'] && $s->submission(1)['status'] === 'pending' && $s->submission(1)['decision'] === 'needs_info', 'needs-info keeps it pending with the reason saved');
$r = admt_apply($s, $owner, 'submission_approve', ['id' => 1, 'category' => 'Writing'], $au); ok($r['ok'] && $s->submission(1)['status'] === 'approved', 'a needs-info submission can still be approved later');
$r = admt_apply($s, $owner, 'submission_reject', ['id' => 2, 'note' => 'Not a fit for the catalogue'], $au); ok($r['ok'] && $s->submission(2)['status'] === 'rejected' && $s->submission(2)['review_note'] === 'Not a fit for the catalogue' && $s->get('paid-tool') === null, 'rejection saves the reason and creates nothing');
ok(admt_apply($s, $owner, 'submission_reject', ['id' => 99, 'note' => 'does not exist'], $au)['code'] === 404, 'unknown submission: 404');

// ---- M6 featured placements ----
$s = $mk(); $s->today = '2026-11-20';
$r = admt_apply($s, $owner, 'feature_add', ['slug' => 'gamma', 'level' => 1, 'ends_on' => '2026-12-20'], $au); ok(!$r['ok'] && $r['code'] === 409, 'only published tools can be featured');
$r = admt_apply($s, $owner, 'feature_add', ['slug' => 'alpha', 'level' => 3, 'ends_on' => '2026-12-20'], $au); ok(!$r['ok'] && isset($r['errors']['level']), 'level must be 1 or 2');
foreach (['2026-11-20', '2026-11-19', '2028-01-01', 'soon', '2026-13-40', ''] as $d) { $r = admt_apply($s, $owner, 'feature_add', ['slug' => 'alpha', 'level' => 1, 'ends_on' => $d], $au); ok(!$r['ok'] && isset($r['errors']['ends_on']), "end date rejected: '$d'"); }
$r = admt_apply($s, $owner, 'feature_add', ['slug' => 'alpha', 'level' => 2, 'ends_on' => '2026-12-20', 'note' => 'Q4 sponsor'], $au); $a = $s->get('alpha'); ok($r['ok'] && $a['featured_level'] === 2 && $a['featured_until'] === '2026-12-20' && $s->revisions('alpha')[0]['action'] === 'feature', 'a placement mirrors into the featured fields and is in the history');
ok(is_featured_tool(['featured_level' => $a['featured_level'], 'featured_until' => $a['featured_until']]), 'the existing public featured check accepts the mirrored values');
$r = admt_apply($s, $owner, 'feature_add', ['slug' => 'alpha', 'level' => 1, 'ends_on' => '2026-12-25'], $au); ok(!$r['ok'] && $r['code'] === 409, 'no overlapping placement for one tool');
$pid = $s->placements('alpha')[0]['id']; $r = admt_apply($s, $owner, 'feature_cancel', ['id' => $pid, 'slug' => 'alpha', 'version' => 0], $au); ok(!$r['ok'] && $r['code'] === 409 && $s->get('alpha')['featured_level'] === 2, 'cancel with a stale tool version is refused');
$r = admt_apply($s, $owner, 'feature_cancel', ['id' => $pid, 'slug' => 'alpha', 'version' => $ver($s, 'alpha')], $au); ok($r['ok'] && $s->get('alpha')['featured_level'] === 0 && $s->get('alpha')['featured_until'] === '' && $s->placements('alpha')[0]['status'] === 'cancelled', 'cancelling an active placement clears the mirror');
$r = admt_apply($s, $owner, 'feature_cancel', ['id' => $pid, 'slug' => 'alpha', 'version' => $ver($s, 'alpha')], $au); ok(!$r['ok'], 'a cancelled placement cannot be cancelled again');
$r = admt_apply($s, $owner, 'feature_add', ['slug' => 'alpha', 'level' => 1, 'ends_on' => '2026-12-25'], $au); ok($r['ok'] && $s->get('alpha')['featured_level'] === 1, 'a new placement is allowed after cancelling');
ok(admt_placement_state(['status' => 'active', 'ends_on' => '2026-11-19'], '2026-11-20') === 'ended' && admt_placement_state(['status' => 'active', 'ends_on' => '2026-11-20'], '2026-11-20') === 'active' && admt_placement_state(['status' => 'cancelled', 'ends_on' => '2027-01-01'], '2026-11-20') === 'cancelled', 'placement state is derived from the dates');
ok(admt_feature_effective([['status' => 'active', 'starts_on' => '2026-11-01', 'ends_on' => '2026-12-01', 'level' => 1], ['status' => 'active', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-10', 'level' => 2], ['status' => 'cancelled', 'starts_on' => '2026-11-01', 'ends_on' => '2027-01-01', 'level' => 2]], '2026-11-20') === [1, '2026-12-01'], 'effective level ignores ended and cancelled placements');
$before = $s->get('alpha')['name']; $r = admt_apply($s, $owner, 'edit', ['slug' => 'alpha', 'version' => $ver($s, 'alpha'), 'name' => 'Alpha Two', 'category' => 'Writing', 'short' => 'A synthetic description that is long enough.', 'pricing_type' => 'Free', 'website' => 'https://t.example.com', 'features' => 'f', 'tags' => 'seo, ai'], $au); ok($r['ok'] && $s->get('alpha')['featured_level'] === 1 && $s->revisions('alpha')[0]['fields'] === ['name'], 'editing a tool keeps its placement and does not show phantom changes in the history');

// ---- screens ----
$subsX = $subs; $s = new AdmToolMemoryStore([$tool('alpha'), $tool('beta', 'draft'), $tool('<script>x</script>', 'published', ['name' => '<script>alert(1)</script>'])], $cats, $subsX); $s->placements(); admt_apply($s, $owner, 'feature_add', ['slug' => 'alpha', 'level' => 1, 'ends_on' => '2026-12-20'], $au);
$h = admt_body($s, $owner, []); ok(strpos($h, 'aria-label="Tool management sections"') !== false && strpos($h, 'name="sel[]"') !== false && strpos($h, 'class="bulkBar"') !== false && strpos($h, 'value="tool_bulk_review"') !== false && strpos($h, '<script>alert') === false, 'owner list: section nav, selection boxes, bulk bar, escaped');
$h = admt_body($s, $editor, []); ok(strpos($h, 'sel[]') === false && strpos($h, 'bulkBar') === false, 'editor list: no selection boxes or bulk bar');
$h = admt_body($s, $owner, ['view' => 'categories']); ok(strpos($h, 'Protected') !== false && strpos($h, 'tool_category_rename') === false && strpos($h, 'tool_category_add') !== false && strpos($h, 'tool_category_delete') !== false && strpos($h, 'tool_tag_rename') !== false, 'owner categories screen: public categories protected (no rename), add-missing, delete of an unused label, tag rename');
$h = admt_body($s, $editor, ['view' => 'categories']); ok(strpos($h, 'tool_category_') === false && strpos($h, 'Writing') !== false && strpos($h, 'tool_tag_rename') === false, 'editor sees categories read-only');
$h = admt_body($s, $owner, ['view' => 'submissions']); ok(strpos($h, 'Submissions') !== false && strpos($h, '&lt;script&gt;') !== false && strpos($h, '<script>alert(1)</script>Evil') === false && strpos($h, 'Blocked:') !== false, 'submissions list: untrusted text escaped, blockers shown');
$h = admt_body($s, $owner, ['view' => 'submission', 'id' => '2']); ok(strpos($h, 'disabled') !== false && strpos($h, 'tool_submission_reject') !== false && strpos($h, 'tool_submission_needs_info') !== false && strpos($h, 'name="_csrf"') !== false, 'blocked submission: approve disabled, reject and needs-info available, CSRF present');
$h = admt_body($s, $owner, ['view' => 'submission', 'id' => '1'], ['note' => '"><b>x'], ['category' => 'Choose an existing category.'], 'Please correct the highlighted fields.'); ok(strpos($h, 'aria-invalid="true"') !== false && strpos($h, 'role="alert"') !== false && strpos($h, '"><b>x') === false, 'submission form errors are accessible and escaped');
$h = admt_body($s, $editor, ['view' => 'submission', 'id' => '1']); ok(strpos($h, 'Only the owner can decide') !== false && strpos($h, 'tool_submission_approve') === false, 'editor cannot see decision buttons');
$h = admt_body($s, $owner, ['view' => 'featured']); ok(strpos($h, 'tool_feature_add') !== false && strpos($h, 'tool_feature_cancel') !== false && strpos($h, 'type="date"') !== false && strpos($h, 'No payment is taken here') !== false && strpos($h, 'sponsored') !== false, 'featured screen: add, cancel, date input, honest wording');
$h = admt_body($s, $editor, ['view' => 'featured']); ok(strpos($h, 'tool_feature_') === false, 'editor sees placements read-only');
$rv = admt_apply($s, $owner, 'bulk_review', ['op' => 'delete', 'sel' => ['alpha', 'beta']] + ['ver' => ['alpha' => $ver($s, 'alpha'), 'beta' => $ver($s, 'beta')]], $au); $h = admt_render_result($s, $owner, ['review' => $rv['review'], 'top' => null]); ok(strpos($h, 'tool_bulk_apply') !== false && strpos($h, 'name="confirm"') !== false && strpos($h, 'Apply to 2 tools') !== false, 'bulk review page: confirmation and apply button');
$rv = admt_apply($s, $owner, 'bulk_review', ['op' => 'restore', 'sel' => ['alpha'], 'ver' => ['alpha' => $ver($s, 'alpha')]], $au); $h = admt_render_result($s, $owner, ['review' => $rv['review'], 'top' => null]); ok(strpos($h, 'Blocked: Not in the trash') !== false && strpos($h, 'tool_bulk_apply') === false, 'bulk review with a problem offers no apply button');
$res = admt_handle_post($s, $owner, ['act' => 'tool_category_add', 'name' => 'Business'], $au); ok(isset($res['redirect']) && strpos($res['redirect'], 'view=categories') !== false && strpos($res['redirect'], 'msg=category_added') !== false, 'a successful action redirects (303) with a fixed message key');
$res = admt_handle_post($s, $owner, ['act' => 'tool_category_add', 'name' => 'x'], $au); ok(!isset($res['redirect']) && $res['code'] === 422 && strpos(admt_render_result($s, $owner, $res), 'role="alert"') !== false, 'a failed action re-renders its screen with an alert');
$res = admt_handle_post($s, $editor, ['act' => 'tool_feature_add', 'slug' => 'alpha'], $au); ok($res['code'] === 403, 'handle_post returns 403 to a non-owner');
ok(strpos(admt_css(), '.bulkBar') !== false && strpos(admt_css(), '.selBox') !== false, 'bulk and selection styles exist');

// ---- static safeguards ----
$code = ''; foreach (['adm_tools_core.php', 'adm_tools_ops.php', 'adm_tools_screens.php', 'adm_tools_views.php'] as $f) $code .= file_get_contents("$root/$f");
ok(!preg_match('/DELETE\s+FROM\s+tools\b|TRUNCATE|DROP\s+TABLE|ALTER\s+TABLE/i', $code), 'no hard delete of tools, no truncate, drop or alter');
ok(!preg_match('/\bmail\(|mb_send_mail|curl_init|stripe|paypal|file_get_contents\(\s*[\'"]https?:|fsockopen|smtp/i', $code), 'the module sends no email, takes no payment and makes no network calls');
ok(!preg_match('/write_json|file_put_contents|fopen\(|\btools\.json/', preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $code)), 'the module never writes tools.json or files');
ok(!preg_match('/\buni_[a-z_]+|universe-editor|uni_core/', $code), 'the module does not touch AI Universe tables or code');
$pdoSrc = preg_replace('#/\*.*?\*/#s', '', substr($code, strpos($code, 'final class AdmToolPdoStore'))); preg_match_all('/\b(?:FROM|JOIN|INTO)\s+`?(\w+)`?|\bUPDATE\s+`?(\w+)`?\s+SET/', $pdoSrc, $m); $used = array_unique(array_map('strtolower', array_filter(array_merge($m[1], $m[2]))));
ok(!array_diff($used, ['tools', 'categories', 'submissions', 'adm_tool_revision', 'adm_tool_feature', 'adm_submission_review', 'select', 'set']) , 'the MySQL store reads and writes only the six expected tables: ' . implode(',', array_diff($used, ['tools', 'categories', 'submissions', 'adm_tool_revision', 'adm_tool_feature', 'adm_submission_review'])));
$mig = (string)file_get_contents("$root/database/phase8c_tools.sql"); foreach (['tool_slug', 'level', 'starts_on', 'ends_on', 'source', 'status', 'note', 'created_by', 'created_at', 'submission_id', 'decision', 'reviewed_by', 'reviewed_at'] as $c) ok(strpos($mig, $c) !== false, "migration defines column $c used by the store");
$sec = json_decode((string)file_get_contents("$root/tests/security_manifest.json"), true)['files'] ?? []; foreach (['adm_tools_ops.php', 'adm_tools_screens.php'] as $f) ok(($sec[$f] ?? '') === 'lib', "$f is in the security manifest");
echo "\nPASSED: $pass  FAILED: $fail\n"; exit($fail ? 1 : 0);
