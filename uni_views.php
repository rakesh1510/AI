<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
/**
 * Editor / verifier web UI for the verified editorial workflow (Phase 7): views and the POST action controller.
 * Every write goes through uni_core.php (the only write path); this file only validates request shape, checks the page-level rules (own items,
 * review checklist) and renders. Roles: editor proposes, verifier reviews, owner reads. Output is escaped with uv_h() everywhere.
 * The page (universe-editor.php) owns the gate, CSRF check, rate limit and redirects.
 */
require_once __DIR__ . '/uni_core.php';
require_once __DIR__ . '/crm_views.php';

const UV_TYPES = ['entity', 'event', 'edge', 'release'];

function uv_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function uv_type_label(string $t): string { return ['entity' => 'Entity', 'event' => 'Event', 'edge' => 'Relationship (edge)', 'release' => 'Release'][$t] ?? $t; }
function uv_state_label(string $s): string { return ['proposed' => 'Proposed (draft)', 'in_review' => 'In review', 'verified' => 'Verified', 'rejected' => 'Rejected', 'superseded' => 'Superseded'][$s] ?? $s; }
function uv_post_str(array $p, string $k): string { $v = $p[$k] ?? ''; return is_string($v) ? $v : ''; }
function uv_int($v): int { return is_string($v) && ctype_digit($v) && strlen($v) <= 10 ? (int)$v : 0; }
function uv_is_ai($row): bool { return is_array($row) && ($row['origin'] ?? '') === 'ai_assisted'; }
function uv_ai_label(): string { return '<span class="badge uvAi">AI-assisted: human verification required</span>'; }
function uv_state_badge(string $s): string { return '<span class="badge uvState">Status: ' . uv_h(uv_state_label($s)) . '</span>'; }
function uv_http_url(string $u): bool { return preg_match('#^https?://#i', $u) === 1 && uni_url_ok($u); }

function uv_messages(): array {
  return ['created' => 'Proposal saved as a draft. Add more sources if needed, then submit it for review.', 'source_attached' => 'Source attached.', 'submitted' => 'Submitted for review.',
    'corrected' => 'Correction saved as a new draft version. The verified version stays public until the correction is verified.', 'verified' => 'Verified. The item is now public.', 'rejected' => 'Rejected.',
    'needs_second_source' => 'Recorded: a second source is needed. The proposer can attach one.', 'accepted_waiting' => 'Accepted. A second verifier must also accept before the item is public.'];
}
function uv_error_labels(): array {
  return ['forbidden' => 'Your role does not allow that action.', 'maker_checker' => 'You proposed this item, so you cannot review it.', 'not_yours' => 'Only the editor who proposed an item can change it before review.', 'type_invalid' => 'Unknown item type.',
    'not_found' => 'That item does not exist.', 'csrf' => 'The security check failed. Reload the page and try again.', 'rate_limited' => 'Too many changes in a short time. Wait a few minutes and try again.',
    'url_invalid' => 'The source URL must be a full http or https address.', 'publisher_required' => 'Enter the publisher of the source.', 'quote_required' => 'Enter the literal quote from the source that supports the claim.',
    'retrieved_at_invalid' => 'Enter the date you retrieved the source (YYYY-MM-DD, not in the future).', 'origin_invalid' => 'Choose human or AI-assisted.', 'slug_invalid' => 'The slug must be 2 or more characters of lower-case letters, digits, dot, dash or underscore.',
    'slug_taken' => 'An entity with that slug already exists.', 'name_required' => 'Enter a name.', 'entity_type_invalid' => 'Choose an entity type.', 'summary_invalid' => 'The summary is not valid text or is too long.',
    'entity_invalid' => 'Choose an entity.', 'entity_not_found' => 'That entity does not exist.', 'event_type_invalid' => 'Choose an event type.', 'date_kind_invalid' => 'Choose a date kind.', 'title_required' => 'Enter a title.',
    'date_invalid' => 'Enter a valid date as YYYY, YYYY-MM or YYYY-MM-DD.', 'edge_type_invalid' => 'Choose a relationship type.', 'self_edge' => 'A relationship needs two different entities.', 'edge_exists' => 'This relationship already exists.',
    'notes_invalid' => 'The notes are not valid text or are too long.', 'validity_invalid' => 'The end date cannot be before the start date.', 'label_required' => 'Enter a version label.', 'event_invalid' => 'The related event must be a number or empty.',
    'event_not_found' => 'That event does not exist.', 'source_invalid' => 'The source is not usable.', 'source_required' => 'At least one source with a quote and a retrieval date is required.', 'already_attached' => 'That source is already attached.',
    'target_locked' => 'Sources of a verified item cannot be changed. Create a correction instead.', 'not_proposed' => 'Only a draft can be submitted.', 'import_needs_new_version' => 'An imported lead needs a corrected version before review.',
    'not_correctable' => 'Only verified items can be corrected.', 'not_current' => 'Only the current version can be corrected.', 'correction_pending' => 'A correction for this item is already waiting.', 'field_not_correctable' => 'That field cannot be corrected.',
    'note_required' => 'A note is mandatory for every decision.', 'note_invalid' => 'The note is not valid text or is too long.', 'checklist_required' => 'Confirm that you opened the original source before accepting.', 'human_check_required' => 'Confirm that you checked the AI-assisted claim yourself.',
    'decision_invalid' => 'Choose accept, reject or needs second source.', 'not_in_review' => 'This item is not waiting for review.', 'already_reviewed' => 'You already reviewed this item.', 'entity_not_verified' => 'The entity must be verified first.',
    'event_not_verified' => 'The related event must be verified first.', 'import_cannot_be_verified' => 'Imported leads cannot be verified.', 'duplicate_current' => 'A current version already exists.', 'db_rule' => 'The database refused this change.', 'db_constraint' => 'The database refused this change.',
    'reference_invalid' => 'A referenced item does not exist.', 'error' => 'Not saved because of a server error.', 'parent_not_earlier' => 'A parent release must be earlier.', 'hash_invalid' => 'Invalid source hash.'];
}
function uv_error_text(string $code): string { return uv_error_labels()[$code] ?? 'Not saved: ' . str_replace('_', ' ', preg_replace('/[^a-z_]/', '', $code) ?? '') . '.'; }
function uv_error_field(string $code, string $type = ''): string {
  $m = ['url_invalid' => 'src_url', 'publisher_required' => 'src_publisher', 'quote_required' => 'src_quote', 'retrieved_at_invalid' => 'src_retrieved', 'origin_invalid' => 'origin', 'slug_invalid' => 'slug', 'slug_taken' => 'slug', 'name_required' => 'name',
    'entity_type_invalid' => 'entity_type', 'summary_invalid' => 'summary', 'entity_invalid' => $type === 'edge' ? 'from_entity_id' : 'entity_id', 'entity_not_found' => $type === 'edge' ? 'from_entity_id' : 'entity_id', 'event_type_invalid' => 'event_type',
    'date_kind_invalid' => 'date_kind', 'title_required' => 'title', 'date_invalid' => $type === 'release' ? 'release_date' : 'event_date', 'edge_type_invalid' => 'edge_type', 'self_edge' => 'to_entity_id', 'edge_exists' => 'edge_type', 'notes_invalid' => 'notes',
    'validity_invalid' => 'valid_to', 'label_required' => 'version_label', 'event_invalid' => 'event_id', 'event_not_found' => 'event_id', 'note_required' => 'note', 'note_invalid' => 'note', 'checklist_required' => 'opened_source', 'human_check_required' => 'human_checked', 'decision_invalid' => 'decision'];
  return $m[$code] ?? '';
}

// ---- small form helpers (labels, hints, error association; ids are prefixed per form so several forms can share a page) -------------------
function uv_form(string $act, array $hidden, string $inner, string $btn, string $legend = ''): string {
  $o = '<form method="post" action="/universe-editor.php" class="uvForm" id="form_' . uv_h($act) . '">' . crm_csrf_field() . '<input type="hidden" name="act" value="' . uv_h($act) . '">';
  foreach ($hidden as $k => $v) $o .= '<input type="hidden" name="' . uv_h($k) . '" value="' . uv_h($v) . '">';
  return $o . $inner . '<p><button type="submit" class="btn">' . uv_h($btn) . '</button></p></form>';
}
function uv_ctx(array $o): string { return (string)($o['act'] ?? 'f'); }
function uv_input(string $name, string $label, array $o = []): string {
  $id = uv_ctx($o) . '_' . $name; $hint = $o['hint'] ?? ''; $bad = in_array($name, $o['bad'] ?? [], true); $val = (string)(($o['old'] ?? [])[$name] ?? ($o['value'] ?? ''));
  return '<div class="field"><label for="' . uv_h($id) . '">' . uv_h($label) . (!empty($o['required']) ? ' <span class="small">(required)</span>' : '') . '</label>'
    . ($hint !== '' ? '<span class="small" id="' . uv_h($id) . '_h">' . uv_h($hint) . '</span>' : '')
    . '<input class="input" id="' . uv_h($id) . '" name="' . uv_h($name) . '" type="' . uv_h($o['type'] ?? 'text') . '" value="' . uv_h($val) . '"' . (!empty($o['required']) ? ' required aria-required="true"' : '') . ' maxlength="' . (int)($o['max'] ?? 255) . '" autocomplete="off"'
    . ($hint !== '' ? ' aria-describedby="' . uv_h($id) . '_h"' : '') . ($bad ? ' aria-invalid="true"' : '') . '></div>';
}
function uv_area(string $name, string $label, array $o = []): string {
  $id = uv_ctx($o) . '_' . $name; $hint = $o['hint'] ?? ''; $bad = in_array($name, $o['bad'] ?? [], true); $val = (string)(($o['old'] ?? [])[$name] ?? '');
  return '<div class="field"><label for="' . uv_h($id) . '">' . uv_h($label) . (!empty($o['required']) ? ' <span class="small">(required)</span>' : '') . '</label>' . ($hint !== '' ? '<span class="small" id="' . uv_h($id) . '_h">' . uv_h($hint) . '</span>' : '')
    . '<textarea class="input" id="' . uv_h($id) . '" name="' . uv_h($name) . '" rows="' . (int)($o['rows'] ?? 3) . '" maxlength="' . (int)($o['max'] ?? 1000) . '"' . (!empty($o['required']) ? ' required aria-required="true"' : '') . ($hint !== '' ? ' aria-describedby="' . uv_h($id) . '_h"' : '') . ($bad ? ' aria-invalid="true"' : '') . '>' . uv_h($val) . '</textarea></div>';
}
function uv_select(string $name, string $label, array $opts, array $o = []): string {
  $id = uv_ctx($o) . '_' . $name; $bad = in_array($name, $o['bad'] ?? [], true); $sel = (string)(($o['old'] ?? [])[$name] ?? ($o['value'] ?? ''));
  $h = '<option value="">Choose...</option>'; foreach ($opts as $k => $l) $h .= '<option value="' . uv_h($k) . '"' . ((string)$k === $sel ? ' selected' : '') . '>' . uv_h($l) . '</option>';
  return '<div class="field"><label for="' . uv_h($id) . '">' . uv_h($label) . (!empty($o['required']) ? ' <span class="small">(required)</span>' : '') . '</label><select id="' . uv_h($id) . '" name="' . uv_h($name) . '"' . (!empty($o['required']) ? ' required aria-required="true"' : '') . ($bad ? ' aria-invalid="true"' : '') . '>' . $h . '</select></div>';
}
function uv_origin_select(string $name, string $label, array $o): string {
  if (!isset($o['old'][$name]) && !isset($o['value'])) $o['value'] = 'human';
  return uv_select($name, $label, ['human' => 'Human: I read the source myself', 'ai_assisted' => 'AI-assisted: an AI tool helped (a human verifier must check it)'], $o);
}
function uv_source_fields(array $o): string {
  $o['required'] = true;
  return '<fieldset class="uvFieldset"><legend>Source (evidence)</legend><p class="small">Every claim needs a source. Quote the passage that supports it literally. A verifier will open the original page and compare.</p>'
    . uv_input('src_url', 'Source URL (https)', $o + ['type' => 'url', 'max' => 2048]) . uv_input('src_publisher', 'Publisher', $o + ['max' => 190])
    . uv_input('src_retrieved', 'Date you retrieved it', $o + ['type' => 'date', 'hint' => 'YYYY-MM-DD']) . uv_area('src_quote', 'Literal quote', $o + ['rows' => 4, 'max' => 1000])
    . uv_origin_select('src_origin', 'How was this source found and read?', ['required' => true] + $o) . '</fieldset>';
}
/** Error summary (role=alert, receives focus on load through autofocus): links to the invalid fields. */
function uv_error_summary(array $errs): string {
  if (!$errs) return '';
  $li = ''; foreach ($errs as $e) $li .= '<li>' . ($e[0] !== '' ? '<a href="#' . uv_h($e[0]) . '">' . uv_h($e[1]) . '</a>' : uv_h($e[1])) . '</li>';
  return '<div class="noteBox uvErr" role="alert" tabindex="-1" autofocus id="errSummary"><h2 style="margin-top:0;font-size:18px">Please fix the following</h2><ul>' . $li . '</ul></div>';
}
/** Options for one form of a page: old values and invalid fields only apply to the form that was submitted (o['failact']). */
function uv_scope(array $o, string $act): array { $mine = ($o['failact'] ?? '') === $act; return ['act' => $act, 'old' => $mine ? ($o['old'] ?? []) : [], 'bad' => $mine ? ($o['bad'] ?? []) : []]; }
function uv_flash(string $m): string { $l = uv_messages()[$m] ?? ''; return $l === '' ? '' : '<div class="noteBox" role="status" tabindex="-1" id="flash">' . uv_h($l) . '</div>'; }

// ---- data reads (read-only; every write goes through uni_core.php) ---------------------------------------------------------------------
function uv_entity_options(PDO $pdo): array {
  $o = []; foreach ($pdo->query('SELECT id, name, slug, state FROM uni_entity WHERE is_current = 1 ORDER BY name LIMIT 300')->fetchAll(PDO::FETCH_ASSOC) as $r) $o[$r['id']] = $r['name'] . ' (' . $r['slug'] . ', ' . $r['state'] . ')';
  return $o;
}
function uv_entity_name(PDO $pdo, $id): string { $s = $pdo->prepare('SELECT name FROM uni_entity WHERE id = ?'); $s->execute([(int)$id]); $n = $s->fetchColumn(); return $n === false ? '(unknown)' : (string)$n; }
function uv_label(PDO $pdo, string $type, array $r): string {
  if ($type === 'entity') return (string)$r['name'];
  if ($type === 'event') return (string)$r['title'];
  if ($type === 'release') return (string)$r['version_label'];
  return uv_entity_name($pdo, $r['from_entity_id']) . ' ' . str_replace('_', ' ', (string)$r['edge_type']) . ' ' . uv_entity_name($pdo, $r['to_entity_id']);
}
function uv_sources(PDO $pdo, string $type, array $row): array {
  if ($type === 'release') { $s = $pdo->prepare('SELECT * FROM uni_source WHERE id = ?'); $s->execute([(int)$row['source_id']]); }
  else { $lk = ['entity' => ['uni_entity_source', 'entity_id'], 'event' => ['uni_event_source', 'event_id'], 'edge' => ['uni_edge_source', 'edge_id']][$type]; $s = $pdo->prepare("SELECT s.* FROM {$lk[0]} l JOIN uni_source s ON s.id = l.source_id WHERE l.{$lk[1]} = ? ORDER BY s.id"); $s->execute([(int)$row['id']]); }
  return $s->fetchAll(PDO::FETCH_ASSOC);
}
function uv_reviews(PDO $pdo, string $type, int $id): array { $s = $pdo->prepare('SELECT decision, note, decided_at FROM uni_review WHERE target_type = ? AND target_id = ? ORDER BY id'); $s->execute([$type, $id]); return $s->fetchAll(PDO::FETCH_ASSOC); }
function uv_item_ai(PDO $pdo, string $type, array $row, array $sources): bool { if (uv_is_ai($row)) return true; foreach ($sources as $s) if (uv_is_ai($s)) return true; return false; }

// ---- views -----------------------------------------------------------------------------------------------------------------------------
function uv_nav(string $view, array $u): string {
  $items = ['home' => 'Home'];
  if ($u['role'] === 'editor') $items += ['mine' => 'My queue', 'new' => 'New proposal'];
  if ($u['role'] === 'verifier') $items += ['queue' => 'Review queue'];
  if ($u['role'] === 'owner') $items += ['overview' => 'Overview'];
  $o = '<nav aria-label="Editorial workflow sections" class="uvNav">'; foreach ($items as $k => $l) $o .= '<a class="btn sm' . ($k === $view ? '' : ' secondary') . '" href="/universe-editor.php?v=' . $k . '"' . ($k === $view ? ' aria-current="page"' : '') . '>' . uv_h($l) . '</a>';
  return $o . '</nav>';
}
function uv_item_row_link(string $type, array $r, string $label): string {
  return '<a href="/universe-editor.php?v=item&amp;t=' . uv_h($type) . '&amp;id=' . (int)$r['id'] . '">' . uv_h($label) . '</a>';
}
function uv_items_table(PDO $pdo, array $items, string $empty): string {
  if (!$items) return '<p class="small">' . uv_h($empty) . '</p>';
  $h = '<div class="tableWrap" tabindex="0" role="region" aria-label="Items (scrollable)"><table class="pretty"><caption class="sr-only">Items</caption><thead><tr><th scope="col">Type</th><th scope="col">Item</th><th scope="col">Status</th><th scope="col">Flags</th><th scope="col">Proposed</th></tr></thead><tbody>';
  foreach ($items as $it) { [$t, $r] = $it; $h .= '<tr><td>' . uv_h(uv_type_label($t)) . '</td><td>' . uv_item_row_link($t, $r, uv_label($pdo, $t, $r)) . '</td><td>' . uv_state_badge((string)$r['state']) . '</td><td>' . (uv_is_ai($r) ? uv_ai_label() : '') . (!empty($r['_awaiting']) ? ' <span class="badge">Waiting for a second verifier</span>' : '') . '</td><td>' . uv_h($r['proposed_at']) . '</td></tr>'; }
  return $h . '</tbody></table></div>';
}
function uv_collect(PDO $pdo, string $where, array $args, int $limit = 50): array {
  $out = [];
  foreach (UV_TYPES as $t) { $s = $pdo->prepare('SELECT * FROM ' . uni_table($t) . " WHERE $where ORDER BY id DESC LIMIT " . (int)$limit); $s->execute($args); foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = [$t, $r]; }
  usort($out, fn($a, $b) => strcmp((string)$b[1]['proposed_at'], (string)$a[1]['proposed_at']) ?: ((int)$b[1]['id'] <=> (int)$a[1]['id']));
  return $out;
}
function uv_view_home(array $u): string {
  $r = $u['role'];
  $o = '<p>Signed in as ' . uv_h($u['display_name']) . ' (' . uv_h($r) . '). Every item is a proposal with at least one quoted source. A different person must verify it before it can be public: nobody reviews their own work.</p>';
  if ($r === 'editor') $o .= '<p>You propose and submit. You cannot review.</p><p><a class="btn" href="/universe-editor.php?v=new">New proposal</a> <a class="btn secondary" href="/universe-editor.php?v=mine">My queue</a></p>';
  elseif ($r === 'verifier') $o .= '<p>You review proposals from editors: open the original source, compare it with the quote, then decide.</p><p><a class="btn" href="/universe-editor.php?v=queue">Review queue</a></p>';
  else $o .= '<p>Read-only overview. The owner role does not propose or verify here.</p><p><a class="btn" href="/universe-editor.php?v=overview">Overview</a></p>';
  return $o;
}
function uv_view_mine(PDO $pdo, array $u): string {
  $mine = uv_collect($pdo, 'proposed_by = ?', [(int)$u['id']]);
  $ver = uv_collect($pdo, "state = 'verified' AND is_current = 1", [], 15);
  return '<h2>My queue</h2>' . uv_items_table($pdo, $mine, 'You have not proposed anything yet.') . '<h2>Verified items you can correct</h2>' . uv_items_table($pdo, $ver, 'Nothing is verified yet.');
}
function uv_view_queue(PDO $pdo, array $u): string {
  $items = [];
  foreach (UV_TYPES as $t) { [$ok, $rows] = uni_review_queue($pdo, (int)$u['id'], $t, 25); if (!$ok) continue; foreach ($rows as $q) { $r = uni_row($pdo, $t, (int)$q['id']); if ($r) { $ps = uni_publish_state($pdo, $t, (int)$r['id']); $r['_awaiting'] = $ps && $ps['accepts'] > 0; $items[] = [$t, $r]; } } }
  usort($items, fn($a, $b) => (int)$a[1]['id'] <=> (int)$b[1]['id']);
  return '<h2>Review queue</h2><p class="small">Items waiting for review that you did not propose and have not reviewed yet.</p>' . uv_items_table($pdo, $items, 'Nothing is waiting for you.');
}
function uv_view_overview(PDO $pdo): string {
  $h = '<h2>Overview (read-only)</h2><div class="tableWrap" tabindex="0" role="region" aria-label="Counts (scrollable)"><table class="pretty"><caption class="sr-only">Counts by type and status</caption><thead><tr><th scope="col">Type</th>';
  $states = ['proposed', 'in_review', 'verified', 'rejected', 'superseded']; foreach ($states as $s) $h .= '<th scope="col">' . uv_h(uv_state_label($s)) . '</th>'; $h .= '<th scope="col">Waiting for second verifier</th></tr></thead><tbody>';
  foreach (UV_TYPES as $t) {
    $c = array_fill_keys($states, 0); foreach ($pdo->query('SELECT state, COUNT(*) n FROM ' . uni_table($t) . ' GROUP BY state')->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($c[$r['state']])) $c[$r['state']] = (int)$r['n'];
    $w = $pdo->prepare("SELECT COUNT(*) FROM " . uni_table($t) . " x WHERE x.state = 'in_review' AND EXISTS (SELECT 1 FROM uni_review r WHERE r.target_type = ? AND r.target_id = x.id AND r.decision = 'accept')"); $w->execute([$t]);
    $h .= '<tr><th scope="row">' . uv_h(uv_type_label($t)) . '</th>'; foreach ($states as $s) $h .= '<td>' . $c[$s] . '</td>'; $h .= '<td>' . (int)$w->fetchColumn() . '</td></tr>';
  }
  $h .= '</tbody></table></div><h2>Latest items</h2>' . uv_items_table($pdo, array_slice(uv_collect($pdo, '1 = 1', [], 10), 0, 20), 'No items yet.');
  return $h;
}
/** New-proposal chooser and the four forms. $o: old values, bad fields, act. */
function uv_view_new(PDO $pdo, string $type, array $o): string {
  $h = '<h2>New proposal</h2><nav aria-label="Proposal type" class="uvNav">';
  foreach (UV_TYPES as $t) $h .= '<a class="btn sm' . ($t === $type ? '' : ' secondary') . '" href="/universe-editor.php?v=new&amp;t=' . $t . '"' . ($t === $type ? ' aria-current="page"' : '') . '>' . uv_h(uv_type_label($t)) . '</a>';
  $h .= '</nav>';
  if (!in_array($type, UV_TYPES, true)) return $h . '<p class="small">Choose what you want to propose.</p>';
  $o = uv_scope($o, 'propose'); $ents = uv_entity_options($pdo);
  $inner = '';
  if ($type === 'entity') $inner = uv_select('entity_type', 'Entity type', array_combine(uni_entity_types(), array_map('ucfirst', uni_entity_types())), $o + ['required' => true]) . uv_input('slug', 'Slug', $o + ['required' => true, 'max' => 120, 'hint' => 'Lower-case letters, digits, dot, dash, underscore. Not rewritten, refused if invalid.']) . uv_input('name', 'Name', $o + ['required' => true, 'max' => 190]) . uv_area('summary', 'Summary (optional)', $o + ['max' => 500, 'hint' => 'Up to 500 characters.']);
  if ($type === 'event') $inner = uv_select('entity_id', 'Entity', $ents, $o + ['required' => true]) . uv_select('event_type', 'Event type', array_combine(uni_event_types(), array_map(fn($x) => str_replace('_', ' ', $x), uni_event_types())), $o + ['required' => true])
    . uv_select('date_kind', 'Date kind', ['announced' => 'Announced', 'ga' => 'Generally available', 'preview' => 'Preview'], $o + ['required' => true, 'value' => 'announced']) . uv_input('event_date', 'Event date', $o + ['required' => true, 'max' => 10, 'hint' => 'YYYY-MM-DD, YYYY-MM or YYYY (the precision you can prove).'])
    . uv_input('title', 'Title', $o + ['required' => true, 'max' => 255]) . uv_area('summary', 'Summary (optional)', $o + ['max' => 1000]);
  if ($type === 'edge') $inner = uv_select('from_entity_id', 'From entity', $ents, $o + ['required' => true]) . uv_select('edge_type', 'Relationship', array_combine(uni_edge_types(), array_map(fn($x) => str_replace('_', ' ', $x), uni_edge_types())), $o + ['required' => true])
    . uv_select('to_entity_id', 'To entity', $ents, $o + ['required' => true]) . uv_input('valid_from', 'Valid from (optional)', $o + ['max' => 10, 'hint' => 'YYYY, YYYY-MM or YYYY-MM-DD.']) . uv_input('valid_to', 'Valid to (optional)', $o + ['max' => 10]) . uv_area('notes', 'Notes (optional)', $o + ['max' => 500, 'hint' => 'Internal, never shown publicly.']);
  if ($type === 'release') $inner = uv_select('entity_id', 'Entity', $ents, $o + ['required' => true]) . uv_input('version_label', 'Version label', $o + ['required' => true, 'max' => 80]) . uv_input('release_date', 'Release date', $o + ['required' => true, 'max' => 10, 'hint' => 'YYYY-MM-DD, YYYY-MM or YYYY.'])
    . uv_input('event_id', 'Related event number (optional)', $o + ['max' => 10, 'hint' => 'The number of an existing event.']);
  $inner .= uv_origin_select('origin', 'Origin of this proposal', ['required' => true] + $o) . uv_source_fields($o);
  return $h . '<section class="panel">' . uv_form('propose', ['t' => $type], '<fieldset class="uvFieldset"><legend>' . uv_h(uv_type_label($type)) . ' proposal</legend>' . $inner . '</fieldset>', 'Save draft') . '</section>';
}
function uv_source_block(array $s): string {
  $url = (string)$s['url']; $link = uv_http_url($url) ? '<a class="btn sm uvOpen" href="' . uv_h($url) . '" target="_blank" rel="noopener noreferrer">Open the original source (new tab)</a>' : '<span class="badge">Link not shown: not a safe http(s) address</span>';
  return '<li class="uvSource"><p><strong>' . uv_h($s['publisher']) . '</strong> - retrieved ' . uv_h($s['retrieved_at']) . ' ' . (uv_is_ai($s) ? uv_ai_label() : '') . '</p><blockquote>' . nl2br(uv_h($s['quote'])) . '</blockquote><p class="small">URL as recorded: ' . uv_h($url) . '</p><p>' . $link . '</p></li>';
}
function uv_view_item(PDO $pdo, array $u, string $type, array $row, array $o = []): string {
  $id = (int)$row['id']; $r = $u['role']; $mine = (int)$row['proposed_by'] === (int)$u['id']; $sources = uv_sources($pdo, $type, $row); $ai = uv_item_ai($pdo, $type, $row, $sources); $ps = uni_publish_state($pdo, $type, $id);
  $h = '<h2>' . uv_h(uv_type_label($type)) . ': ' . uv_h(uv_label($pdo, $type, $row)) . '</h2><p>' . uv_state_badge((string)$row['state']) . ' <span class="badge">Version ' . (int)$row['version_no'] . '</span> ' . ($ai ? uv_ai_label() : '') . ($mine ? ' <span class="badge">You proposed this</span>' : '') . '</p>';
  $dl = ['Origin' => $row['origin'], 'Proposed' => $row['proposed_at']];
  if ($type === 'entity') $dl += ['Entity type' => $row['entity_type'], 'Slug' => $row['slug'], 'Summary' => $row['summary']];
  if ($type === 'event') $dl += ['Entity' => uv_entity_name($pdo, $row['entity_id']), 'Event type' => $row['event_type'], 'Date' => $row['event_date'] . ' (' . $row['date_precision'] . ', ' . $row['date_kind'] . ')', 'Title' => $row['title'], 'Summary' => $row['summary']];
  if ($type === 'edge') $dl += ['From' => uv_entity_name($pdo, $row['from_entity_id']), 'Relationship' => $row['edge_type'], 'To' => uv_entity_name($pdo, $row['to_entity_id']), 'Valid from' => $row['valid_from'] ?? '', 'Valid to' => $row['valid_to'] ?? '', 'Notes' => $row['notes']];
  if ($type === 'release') $dl += ['Entity' => uv_entity_name($pdo, $row['entity_id']), 'Version' => $row['version_label'], 'Date' => $row['release_date'] . ' (' . $row['date_precision'] . ')', 'Related event' => $row['event_id'] ?? ''];
  $h .= '<dl class="uvDl">'; foreach ($dl as $k => $v) $h .= '<dt>' . uv_h($k) . '</dt><dd>' . uv_h($v) . '</dd>'; $h .= '</dl>';
  if ($type === 'event') {
    $d = uni_duplicates_of_event($pdo, $id); if ($d) $h .= '<div class="noteBox" role="note"><strong>Possible duplicate (flagged, not merged):</strong> same entity, date and title as event ' . implode(', ', array_map(fn($x) => '<a href="/universe-editor.php?v=item&amp;t=event&amp;id=' . (int)$x . '">#' . (int)$x . '</a>', $d)) . '.</div>';
    foreach (uni_conflicts($pdo, 'event', $id) as $c) { $dd = $c['detail']; if (($dd['kind'] ?? '') === 'date_conflict') $h .= '<div class="noteBox" role="note"><strong>Possible date conflict:</strong> the same title exists on another date (event ' . implode(', ', array_map(fn($x) => '<a href="/universe-editor.php?v=item&amp;t=event&amp;id=' . (int)$x . '">#' . (int)$x . '</a>', (array)($dd['others'] ?? []))) . '). Resolve it by review, nothing is merged automatically.</div>'; }
  }
  if ($ps) { $h .= '<p>' . uv_h((string)$ps['valid_sources']) . ' valid source(s). ' . ($ps['accepts'] > 0 ? 'Accepts so far: ' . (int)$ps['accepts'] . '. ' . ($ps['second_review_required'] ? 'This item is sampled for a second review: a second, different verifier must also accept.' : 'One accept is enough for this item.') : 'Items selected by the sampling rule need two different verifiers; you learn this after the first accept.') . '</p>'; }
  $h .= '<h3>Sources</h3>' . ($sources ? '<ul class="uvSources">' . implode('', array_map('uv_source_block', $sources)) . '</ul>' : '<p class="small">No source yet.</p>');
  $rev = uv_reviews($pdo, $type, $id); if ($rev) { $h .= '<h3>Review history</h3><ul>'; foreach ($rev as $x) $h .= '<li>' . uv_h(str_replace('_', ' ', $x['decision'])) . ' - ' . uv_h($x['decided_at']) . ': ' . uv_h($x['note']) . '</li>'; $h .= '</ul>'; }
  $open = in_array($row['state'], ['proposed', 'in_review'], true);
  if ($r === 'verifier') {
    if ($mine) $h .= '<div class="noteBox" role="note">You proposed this item, so you cannot review it. A different verifier must.</div>';
    elseif ($row['state'] === 'in_review') {
      $done = (int)$pdo->query('SELECT COUNT(*) FROM uni_review WHERE target_type = ' . $pdo->quote($type) . ' AND target_id = ' . $id . ' AND reviewer_id = ' . (int)$u['id'])->fetchColumn();
      $h .= $done ? '<div class="noteBox" role="note">You already reviewed this item.</div>' : uv_review_form($type, $id, $ai, $o);
    } else $h .= '<p class="small">This item is not waiting for review.</p>';
  }
  if ($r === 'editor') {
    if ($mine && $open) {
      $h .= '<section class="panel"><h3>Attach another source</h3>' . uv_form('attach_source', ['t' => $type, 'id' => $id], uv_source_fields(uv_scope($o, 'attach_source')), 'Attach source') . '</section>';
      if ($row['state'] === 'proposed') $h .= '<section class="panel"><h3>Submit for review</h3><p class="small">After submitting you can still add sources, but not edit the claim. Needs at least one valid source.</p>' . uv_form('submit', ['t' => $type, 'id' => $id], '', 'Submit for review') . '</section>';
    }
    if ($row['state'] === 'verified' && (int)$row['is_current'] === 1) $h .= '<section class="panel"><h3>Propose a correction</h3><p class="small">Creates a new draft version. Leave a field empty to keep it. The verified version stays public until the correction is verified by someone else.</p>' . uv_form('correct', ['t' => $type, 'id' => $id], uv_correct_fields($type, uv_scope($o, 'correct')) . uv_origin_select('origin', 'Origin of the correction', ['required' => true] + uv_scope($o, 'correct')), 'Save correction') . '</section>';
  }
  return $h;
}
function uv_correct_fields(string $type, array $o): string {
  if ($type === 'entity') return uv_input('name', 'New name', $o + ['max' => 190]) . uv_area('summary', 'New summary', $o + ['max' => 500]);
  if ($type === 'event') return uv_input('title', 'New title', $o + ['max' => 255]) . uv_input('event_date', 'New date', $o + ['max' => 10, 'hint' => 'YYYY-MM-DD, YYYY-MM or YYYY.']) . uv_area('summary', 'New summary', $o + ['max' => 1000]);
  if ($type === 'edge') return uv_input('valid_from', 'New valid from', $o + ['max' => 10]) . uv_input('valid_to', 'New valid to', $o + ['max' => 10]) . uv_area('notes', 'New notes', $o + ['max' => 500]);
  return uv_input('version_label', 'New version label', $o + ['max' => 80]) . uv_input('release_date', 'New release date', $o + ['max' => 10, 'hint' => 'YYYY-MM-DD, YYYY-MM or YYYY.']);
}
function uv_review_form(string $type, int $id, bool $ai, array $o): string {
  $o = uv_scope($o, 'review'); $bad = $o['bad'] ?? []; $old = $o['old'] ?? [];
  $chk = function (string $name, string $label) use ($bad, $old): string { return '<div class="field uvCheck"><label for="review_' . $name . '"><input type="checkbox" id="review_' . $name . '" name="' . $name . '" value="1"' . (!empty($old[$name]) ? ' checked' : '') . (in_array($name, $bad, true) ? ' aria-invalid="true"' : '') . '> ' . uv_h($label) . '</label></div>'; };
  $radios = '<fieldset class="uvFieldset"><legend>Decision <span class="small">(required)</span></legend>'; foreach (['accept' => 'Accept: the source supports the claim', 'reject' => 'Reject: wrong, unsupported or unusable', 'needs_second_source' => 'Needs a second source'] as $k => $l) $radios .= '<div class="field uvCheck"><label for="review_d_' . $k . '"><input type="radio" id="review_d_' . $k . '" name="decision" value="' . $k . '"' . (($old['decision'] ?? '') === $k ? ' checked' : '') . ' required> ' . uv_h($l) . '</label></div>'; $radios .= '</fieldset>';
  $list = '<fieldset class="uvFieldset"><legend>Verification checklist</legend>' . $chk('opened_source', 'I opened the original source with the link above and compared it with the quote (required to accept).') . ($ai ? $chk('human_checked', 'This item is AI-assisted: I checked the claim myself in the original source and did not rely on the AI output (required to accept).') : '') . '</fieldset>';
  return '<section class="panel"><h3>Your review</h3>' . ($ai ? '<p>' . uv_ai_label() . '</p>' : '') . uv_form('review', ['t' => $type, 'id' => $id], $radios . $list . uv_area('note', 'Note for the record', $o + ['required' => true, 'rows' => 4, 'hint' => 'Mandatory for every decision. Say what you checked.']), 'Record decision') . '</section>';
}

// ---- POST actions ------------------------------------------------------------------------------------------------------------------------
/**
 * Runs one action for a signed-in user. Role and CSRF were already checked by the page.
 * @return array{ok:bool, code:string, go:array, type:string} go = query for the 303 redirect on success
 */
function uv_handle_post(PDO $pdo, array $u, array $p): array {
  $act = uv_post_str($p, 'act'); $type = uv_post_str($p, 't'); $uid = (int)$u['id']; $fail = fn(string $c) => ['ok' => false, 'code' => $c, 'go' => [], 'type' => $type];
  $item = fn(string $t, int $id, string $m) => ['ok' => true, 'code' => $m, 'go' => ['v' => 'item', 't' => $t, 'id' => $id, 'm' => $m], 'type' => $t];
  $isEditor = $u['role'] === 'editor'; $isVerifier = $u['role'] === 'verifier';
  if (!in_array($act, ['propose', 'attach_source', 'submit', 'correct', 'review'], true)) return $fail('forbidden');
  if (in_array($act, ['propose', 'attach_source', 'submit', 'correct'], true) && !$isEditor) return $fail('forbidden');
  if ($act === 'review' && !$isVerifier) return $fail('forbidden');
  if (!in_array($type, UV_TYPES, true)) return $fail('type_invalid');
  $originOk = fn(string $o) => in_array($o, ['human', 'ai_assisted'], true);
  $src = ['url' => uv_post_str($p, 'src_url'), 'publisher' => uv_post_str($p, 'src_publisher'), 'retrieved_at' => uv_post_str($p, 'src_retrieved'), 'quote' => uv_post_str($p, 'src_quote'), 'origin' => uv_post_str($p, 'src_origin') ?: 'human'];
  if ($act === 'propose') {
    $origin = uv_post_str($p, 'origin') ?: 'human';
    if (!$originOk($origin) || !$originOk($src['origin'])) return $fail('origin_invalid');
    $s = fn(string $k) => uv_post_str($p, $k); $n = fn(string $k) => uv_int($p[$k] ?? null);
    $r = uni_tx($pdo, function () use ($pdo, $uid, $type, $src, $origin, $s, $n) {
      if ($type === 'release') {
        [$ok, $sid] = uni_source_create($pdo, $uid, $src); if (!$ok) return [false, $sid];
        $ev = $s('event_id');
        [$ok, $rid] = uni_propose_release($pdo, $uid, ['entity_id' => $n('entity_id'), 'event_id' => $ev === '' ? null : $n('event_id'), 'version_label' => $s('version_label'), 'release_date' => $s('release_date'), 'source_id' => (int)$sid, 'origin' => $origin]);
        return $ok ? [true, (int)$rid] : [false, $rid];
      }
      if ($type === 'entity') [$ok, $res] = uni_propose_entity($pdo, $uid, ['entity_type' => $s('entity_type'), 'slug' => $s('slug'), 'name' => $s('name'), 'summary' => $s('summary'), 'origin' => $origin]);
      elseif ($type === 'event') [$ok, $res] = uni_propose_event($pdo, $uid, ['entity_id' => $n('entity_id'), 'event_type' => $s('event_type'), 'date_kind' => $s('date_kind'), 'event_date' => $s('event_date'), 'title' => $s('title'), 'summary' => $s('summary'), 'origin' => $origin]);
      else [$ok, $res] = uni_propose_edge($pdo, $uid, ['from_entity_id' => $n('from_entity_id'), 'to_entity_id' => $n('to_entity_id'), 'edge_type' => $s('edge_type'), 'valid_from' => $s('valid_from') === '' ? null : $s('valid_from'), 'valid_to' => $s('valid_to') === '' ? null : $s('valid_to'), 'notes' => $s('notes'), 'origin' => $origin]);
      if (!$ok) return [false, $res];
      $id = $type === 'event' ? (int)$res['id'] : (int)$res;
      [$ok2, $why] = uni_attach_source($pdo, $uid, $type, $id, $src); return $ok2 ? [true, $id] : [false, $why];
    });
    return $r[0] ? $item($type, (int)$r[1], 'created') : $fail((string)$r[1]);
  }
  $id = uv_int($p['id'] ?? null); $row = $id > 0 ? uni_row($pdo, $type, $id) : null; if (!$row) return $fail('not_found');
  if ($act === 'attach_source' || $act === 'submit') {
    if ((int)$row['proposed_by'] !== $uid) return $fail('not_yours');
    if ($act === 'submit') { $r = uni_submit_for_review($pdo, $uid, $type, $id); return $r[0] ? $item($type, $id, 'submitted') : $fail((string)$r[1]); }
    if (!$originOk($src['origin'])) return $fail('origin_invalid');
    $r = uni_attach_source($pdo, $uid, $type, $id, $src); return $r[0] ? $item($type, $id, 'source_attached') : $fail((string)$r[1]);
  }
  if ($act === 'correct') {
    $origin = uv_post_str($p, 'origin') ?: 'human'; if (!$originOk($origin)) return $fail('origin_invalid');
    $fields = ['entity' => ['name', 'summary'], 'event' => ['title', 'event_date', 'summary'], 'edge' => ['valid_from', 'valid_to', 'notes'], 'release' => ['version_label', 'release_date']][$type]; $ch = ['origin' => $origin];
    foreach ($fields as $f) { $v = uv_post_str($p, $f); if (trim($v) !== '') $ch[$f] = $v; }
    $r = uni_correct($pdo, $uid, $type, $id, $ch); return $r[0] ? $item($type, (int)$r[1], 'corrected') : $fail((string)$r[1]);
  }
  // review: maker-checker first, then the mandatory note and checklist, then the service layer decides
  if ((int)$row['proposed_by'] === $uid) return $fail('maker_checker');
  $dec = uv_post_str($p, 'decision'); if (!in_array($dec, ['accept', 'reject', 'needs_second_source'], true)) return $fail('decision_invalid');
  if (trim(uv_post_str($p, 'note')) === '') return $fail('note_required');
  if ($dec === 'accept') {
    if (uv_post_str($p, 'opened_source') !== '1') return $fail('checklist_required');
    if (uv_item_ai($pdo, $type, $row, uv_sources($pdo, $type, $row)) && uv_post_str($p, 'human_checked') !== '1') return $fail('human_check_required');
  }
  $r = uni_review($pdo, $uid, $type, $id, $dec, uv_post_str($p, 'note'));
  if (!$r[0]) return $fail((string)$r[1]);
  $m = $dec === 'accept' ? ($r[1]['state'] === 'verified' ? 'verified' : 'accepted_waiting') : ($dec === 'reject' ? 'rejected' : 'needs_second_source');
  return ['ok' => true, 'code' => $m, 'go' => ['v' => 'queue', 'm' => $m], 'type' => $type];
}
