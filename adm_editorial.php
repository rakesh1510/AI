<?php
if (isset($_SERVER["SCRIPT_FILENAME"]) && realpath($_SERVER["SCRIPT_FILENAME"]) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
// Unified Control Center, milestone U4: tool PROFILE verification (the former admin-editorial.php) inside the shell, signed in with a personal account instead of the shared legacy password.
// Rules (owner decisions D3, D4): the reviewer is ALWAYS the signed-in account (a posted name is ignored); only verifiers may verify, reject or reopen; only editors prepare (note, research, sources opened);
// a verifier cannot verify a profile they prepared themselves; the owner can look but not change. The ledger and every verification rule stay in editorial_review.php (unchanged).
require_once __DIR__ . '/editorial_review.php';

function admed_url(array $q = []): string { return '/admin-center.php?' . http_build_query(['m' => 'editorial'] + $q); }
function admed_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** Who records the entry: always the signed-in account (display name and username), never a typed name. */
function admed_identity(array $u): string { return trim((string)($u['display_name'] ?? '')) . ' (' . (string)($u['username'] ?? '') . ')'; }

/** The role that may perform an action: editors prepare, verifiers decide. */
function admed_role_for(string $action): ?string { return ['note' => 'editor', 'research_needed' => 'editor', 'sources_checked' => 'editor', 'verify' => 'verifier', 'reject' => 'verifier', 'reopen' => 'verifier'][$action] ?? null; }

/** A verifier may not verify a profile that this same account prepared (notes, research flags, sources opened). */
function admed_independent(array $log, string $identity): bool {
  foreach ($log as $e) if (($e['reviewer'] ?? '') === $identity && in_array($e['action'] ?? '', ['note', 'research_needed', 'sources_checked'], true)) return false;
  return true;
}

function admed_labels(): array {
  return ['ok' => 'Saved.', 'reviewer_required' => 'Your account has no display name; ask the owner to set one.', 'note_required' => 'A note is required for this action.', 'checklist_incomplete' => 'Tick every checklist item to verify.',
    'confirmation_required' => 'Confirm that you personally verified the page.', 'sources_count_mismatch' => 'The number of sources checked must match the number of listed sources.', 'not_reviewed_profile' => 'Only reviewed profiles with sources can be verified.',
    'no_sources' => 'This profile has no sources.', 'log_unwritable' => 'The review log is not writable on this server.', 'unknown_tool' => 'Unknown tool.', 'invalid_action' => 'Unknown action.',
    'wrong_role' => 'Your role may not do this: editors prepare (notes, research, sources) and verifiers decide (verify, reject, reopen).', 'not_independent' => 'You prepared this profile yourself, so you cannot also verify it. Another verifier must do that.',
    'read_only' => 'The owner role can look at profile verification but not change it.'];
}

/**
 * One request. $append defaults to the real ledger writer (injectable for tests). Returns ['redirect' => url] or ['status', 'body'].
 * Order: role, owner read-only, action-to-role, independence, then the ledger (which enforces checklist, sources, confirmation). The caller has already checked sign-in, CSRF and the module permission.
 */
function admed_run(array $user, string $method, array $get, array $post, ?callable $append = null, ?callable $audit = null): array {
  $role = (string)$user['role']; $append = $append ?? 'editorial_log_append'; $audit = $audit ?? function (string $a, string $s) {};
  if (!in_array($role, ['editor', 'verifier', 'owner'], true)) return ['status' => 403, 'body' => '<h1>Not allowed</h1><p>Your account role does not have access to profile verification.</p>'];
  if ($method === 'POST') {
    $id = (string)($post['tool_id'] ?? ''); $action = (string)($post['action'] ?? '');
    $back = fn(string $code) => ['redirect' => admed_url(['id' => $id, 'msg' => $code])];
    if ($role === 'owner') { $audit('editorial_denied', 'owner read-only ' . $action); return $back('read_only'); }
    $need = admed_role_for($action);
    if ($need !== null && $need !== $role) { $audit('editorial_denied', $role . ' tried ' . $action); return $back('wrong_role'); }
    $ident = admed_identity($user);
    if ($action === 'verify' && !admed_independent(editorial_log_for($id), $ident)) { $audit('editorial_denied', 'not independent: ' . $id); return $back('not_independent'); }
    $in = ['tool_id' => $id, 'action' => $action, 'reviewer' => $ident, 'note' => (string)($post['note'] ?? ''), 'confirm' => !empty($post['confirm']),
      'checklist' => is_array($post['checklist'] ?? null) ? $post['checklist'] : [], 'sources_checked' => $post['sources_checked'] ?? -1];   // 'reviewer' is the session account, never the posted value
    [$ok, $code] = $append($in, 'admin_session');
    $audit('editorial_review', $id . ' ' . $action . ' ' . $code);
    return $back((string)$code);
  }
  return ['status' => 200, 'body' => admed_view($user, (string)($get['id'] ?? ''), is_string($get['msg'] ?? null) ? $get['msg'] : '')];
}

function admed_view(array $user, string $id, string $msg): string {
  $role = (string)$user['role']; $byId = tools_by_id(); $detail = isset($byId[$id]) ? $byId[$id] : null; $h = 'admed_h';
  $o = '<div class="admCrm admEd"><h1>Tool profile verification</h1><p class="small">Verification is a human act. Nothing here marks a profile verified on its own. You are recorded as <strong>' . $h(admed_identity($user)) . '</strong> (' . $h($role) . '). '
    . ($role === 'editor' ? 'Editors prepare: notes, research flags and sources opened.' : ($role === 'verifier' ? 'Verifiers decide: verify, reject or reopen, and only profiles you did not prepare yourself.' : 'The owner role is read only here.')) . '</p>';
  if ($msg !== '') $o .= '<div class="noteBox" role="status">' . $h(admed_labels()[$msg] ?? 'Not saved.') . '</div>';
  if (!$detail) {
    $o .= '<section class="panel"><h2>Queue</h2><p class="small">Ordered by the Phase 4 priority score (visibility and risk). Open a tool to see its evidence ledger, research tasks and history.</p><div class="tableWrap" tabindex="0" role="region" aria-label="Verification queue (scrollable)"><table class="table"><caption class="sr">Verification queue</caption><thead><tr><th scope="col">Priority</th><th scope="col">Tool</th><th scope="col">Status</th><th scope="col">Open tasks</th></tr></thead><tbody>';
    foreach (editorial_queue_rows(60) as $row) { $t = $byId[$row['id']]; $o .= '<tr><td>' . $h($row['priority']) . '</td><td><a href="' . $h(admed_url(['id' => $row['id']])) . '">' . $h($t['name']) . '</a></td><td>' . $h(editorial_status($t)) . '</td><td>' . count(editorial_research_tasks($t)) . '</td></tr>'; }
    return $o . '</tbody></table></div></section></div>';
  }
  $facts = editorial_fact_states($detail); $p = $detail['curated'] ?? null; $log = editorial_log_for($id); $tasks = editorial_research_tasks($detail);
  $o .= '<p><a href="' . $h(admed_url()) . '">&larr; Queue</a></p><section class="panel"><h2>' . $h($detail['name']) . ' <span class="small">(' . $h($id) . ')</span></h2><p class="small">Public page: <a href="/tool.php?id=' . $h(rawurlencode($id)) . '">/tool.php?id=' . $h($id) . '</a>' . ($p ? '' : ' · catalogue listing, not reviewed') . '</p>'
    . '<div class="tableWrap" tabindex="0" role="region" aria-label="Facts (scrollable)"><table class="table"><caption class="sr">Facts and sources</caption><thead><tr><th scope="col">Fact</th><th scope="col">State</th><th scope="col">Source</th><th scope="col">Source date</th><th scope="col">Re-check by</th></tr></thead><tbody>';
  foreach ($facts as $f) $o .= '<tr><td>' . $h($f['label']) . '</td><td>' . $h($f['state_label']) . '<div class="small">' . $h($f['note']) . '</div></td><td>' . ($f['source_url'] ? '<a href="' . $h($f['source_url']) . '" rel="noopener nofollow ugc" target="_blank">' . $h($f['source_label'] ?: $f['source_url']) . '</a>' : '—') . '</td><td>' . $h($f['source_date'] ?? '—') . '</td><td>' . $h($f['expires_on'] ?? '—') . '</td></tr>';
  $o .= '</tbody></table></div>';
  if ($p && !empty($p['known_source_conflicts'])) { $o .= '<h3>Known source conflicts</h3><ul>'; foreach ($p['known_source_conflicts'] as $c) $o .= '<li>' . $h($c) . '</li>'; $o .= '</ul>'; }
  $o .= '<h3>Research tasks</h3><ul>'; foreach ($tasks as $t) $o .= '<li>' . $h($t['task']) . ' <span class="small">(needs: ' . $h($t['needs']) . ')</span></li>'; $o .= '</ul></section>';
  $o .= '<section class="panel"><h3>Review history</h3>'; if (!$log) $o .= '<p class="small">No review events recorded for this tool.</p>'; else { $o .= '<ul>'; foreach ($log as $e) $o .= '<li><strong>' . $h($e['action']) . '</strong> — ' . $h($e['reviewer']) . ', ' . $h($e['at']) . (!empty($e['note']) ? ': ' . $h($e['note']) : '') . '</li>'; $o .= '</ul>'; } $o .= '</section>';
  if ($role === 'owner') return $o . '<p class="panel">The owner role can look but not record review events.</p></div>';
  $acts = $role === 'editor' ? ['note' => 'Add a note', 'research_needed' => 'Flag research needed', 'sources_checked' => 'Record that sources were opened (does not verify)'] : ['verify' => 'Verify (requires the full checklist)', 'reject' => 'Reject / withdraw verification', 'reopen' => 'Reopen'];
  $o .= '<section class="panel"><h3>Record a review event</h3><form method="post" action="/admin-center.php?m=editorial">' . crm_csrf_field() . '<input type="hidden" name="tool_id" value="' . $h($id) . '">'
    . '<div class="field"><label for="edAction">Action</label><select class="input" id="edAction" name="action">'; foreach ($acts as $k => $l) $o .= '<option value="' . $h($k) . '">' . $h($l) . '</option>';
  $o .= '</select></div><p class="small">Recorded as <strong>' . $h(admed_identity($user)) . '</strong> (taken from your sign-in; it cannot be typed).</p><div class="field"><label for="edNote">Note</label><textarea class="input" id="edNote" name="note" maxlength="600" rows="3"></textarea></div>';
  if ($role === 'verifier' && $p) {
    $o .= '<fieldset class="panel"><legend>Verification checklist (only for "Verify")</legend>'; foreach (editorial_verify_checklist() as $k => $lbl) $o .= '<label style="display:flex;gap:10px;align-items:flex-start;min-height:44px"><input type="checkbox" name="checklist[' . $h($k) . ']" value="1"> ' . $h($lbl) . '</label>';
    $o .= '<div class="field"><label for="edSrc">Number of sources checked (listed: ' . count((array)($p['sources'] ?? [])) . ')</label><input class="input" id="edSrc" name="sources_checked" type="number" min="0" max="50" style="max-width:120px"></div>'
      . '<label style="display:flex;gap:10px;align-items:flex-start;min-height:44px"><input type="checkbox" name="confirm" value="1"> I personally verified this profile and accept responsibility for the "Verified by an editor" label.</label></fieldset>';
  }
  return $o . '<p><button class="btn">Save</button></p></form></section></div>';
}
