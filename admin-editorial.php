<?php
// Editorial verification queue (admin only). Records research notes and, only when a person ticks the full checklist, a verification.
// Authorization: signed-in admin session; every change is POST + session CSRF; nothing here verifies a profile automatically.
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/admin_auth.php';
$title = 'Editorial verification — AIHubpedia admin';
admin_require('Editorial verification');

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$byId = tools_by_id();
$msg = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  if (!admin_csrf_ok()) { sec_log('admin_csrf_rejected', ['page' => 'editorial']); http_response_code(403); exit('Security check failed.'); }
  $a = (string)($_POST['admin_action'] ?? '');
  if ($a === 'logout') { admin_logout(); header('Location: /admin-editorial.php', true, 303); exit; }
  if ($a === 'review') {
    $in = ['tool_id' => (string)($_POST['tool_id'] ?? ''), 'action' => (string)($_POST['action'] ?? ''), 'reviewer' => (string)($_POST['reviewer'] ?? ''), 'note' => (string)($_POST['note'] ?? ''),
      'confirm' => !empty($_POST['confirm']), 'checklist' => is_array($_POST['checklist'] ?? null) ? $_POST['checklist'] : [], 'sources_checked' => $_POST['sources_checked'] ?? -1];
    [$ok, $code] = editorial_log_append($in, 'admin_session');
    sec_log('admin_editorial_review', ['tool' => $in['tool_id'], 'action' => $in['action'], 'result' => $code]);
    header('Location: /admin-editorial.php?id=' . rawurlencode($in['tool_id']) . '&r=' . rawurlencode($code), true, 303);
    exit;
  }
}
$id = (string)($_GET['id'] ?? '');
$detail = isset($byId[$id]) ? $byId[$id] : null;
$resultLabels = ['ok' => 'Saved.', 'reviewer_required' => 'Enter the reviewer name.', 'note_required' => 'A note is required for this action.', 'checklist_incomplete' => 'Tick every checklist item to verify.',
  'confirmation_required' => 'Confirm that you personally verified the page.', 'sources_count_mismatch' => 'The number of sources checked must match the number of listed sources.', 'not_reviewed_profile' => 'Only reviewed profiles with sources can be verified.',
  'no_sources' => 'This profile has no sources.', 'log_unwritable' => 'The review log is not writable on this server.', 'unknown_tool' => 'Unknown tool.', 'invalid_action' => 'Unknown action.'];
require __DIR__ . '/layout_top.php';
?>
<h1>Editorial verification <span class="small">(admin)</span></h1>
<form method="post" style="float:right"><?= admin_csrf_field() ?><input type="hidden" name="admin_action" value="logout"><button class="btn secondary sm">Sign out</button></form>
<p class="small"><a href="/admin-leads.php">Leads</a> · <a href="/admin.php">Listing submissions</a> · Verification is a human act. Nothing on this page, in the tests or in a script marks a profile verified on its own. Profiles verified by an editor today: <strong><?= (int)editorial_verified_count() ?></strong> of <?= count($byId) ?>.</p>
<?php if (isset($_GET['r'])): $r = (string)$_GET['r']; ?><div class="noteBox" role="status"><?= $h($resultLabels[$r] ?? 'Not saved.') ?></div><?php endif; ?>

<?php if ($detail): $facts = editorial_fact_states($detail); $p = $detail['curated'] ?? null; $log = editorial_log_for($id); $tasks = editorial_research_tasks($detail); ?>
<p><a href="/admin-editorial.php">&larr; Queue</a></p>
<section class="panel">
  <h2 style="margin-top:0"><?= $h($detail['name']) ?> <span class="small">(<?= $h($id) ?>)</span></h2>
  <p class="small">Public page: <a href="/tool.php?id=<?= $h(rawurlencode($id)) ?>">/tool.php?id=<?= $h($id) ?></a><?= $p ? '' : ' · catalogue listing, not reviewed' ?></p>
  <table class="table"><thead><tr><th>Fact</th><th>State</th><th>Source</th><th>Source date</th><th>Re-check by</th></tr></thead><tbody>
  <?php foreach ($facts as $f): ?><tr>
    <td><?= $h($f['label']) ?></td><td><?= $h($f['state_label']) ?><div class="small"><?= $h($f['note']) ?></div></td>
    <td><?= $f['source_url'] ? '<a href="' . $h($f['source_url']) . '" rel="noopener nofollow ugc" target="_blank">' . $h($f['source_label'] ?: $f['source_url']) . '</a>' : '—' ?></td>
    <td><?= $h($f['source_date'] ?? '—') ?></td><td><?= $h($f['expires_on'] ?? '—') ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php if ($p && !empty($p['known_source_conflicts'])): ?><h3>Known source conflicts</h3><ul><?php foreach ($p['known_source_conflicts'] as $c): ?><li><?= $h($c) ?></li><?php endforeach; ?></ul><?php endif; ?>
  <h3>Research tasks</h3>
  <ul><?php foreach ($tasks as $t): ?><li><?= $h($t['task']) ?> <span class="small">(needs: <?= $h($t['needs']) ?>)</span></li><?php endforeach; ?></ul>
</section>
<section class="panel">
  <h3 style="margin-top:0">Review history</h3>
  <?php if (!$log): ?><p class="small">No review events recorded for this tool.</p><?php else: ?>
  <ul><?php foreach ($log as $e): ?><li><strong><?= $h($e['action']) ?></strong> — <?= $h($e['reviewer']) ?>, <?= $h($e['at']) ?> (<?= $h($e['via'] ?? '') ?>)<?= !empty($e['note']) ? ': ' . $h($e['note']) : '' ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
</section>
<section class="panel">
  <h3 style="margin-top:0">Record a review event</h3>
  <form method="post"><?= admin_csrf_field() ?><input type="hidden" name="admin_action" value="review"><input type="hidden" name="tool_id" value="<?= $h($id) ?>">
    <label for="edAction">Action</label>
    <select class="input" id="edAction" name="action">
      <option value="note">Add a note</option><option value="research_needed">Flag research needed</option><option value="sources_checked">Record that sources were opened (does not verify)</option>
      <option value="verify">Verify (requires the full checklist)</option><option value="reject">Reject / withdraw verification</option><option value="reopen">Reopen</option>
    </select>
    <label for="edReviewer">Reviewer name (the person performing this review)</label>
    <input class="input" id="edReviewer" name="reviewer" maxlength="80" required autocomplete="off">
    <label for="edNote">Note</label>
    <textarea class="input" id="edNote" name="note" maxlength="600" rows="3"></textarea>
    <?php if ($p): $nSrc = count((array)($p['sources'] ?? [])); ?>
    <fieldset style="margin-top:12px"><legend>Verification checklist (only for "Verify")</legend>
      <?php foreach (editorial_verify_checklist() as $k => $lbl): ?><label style="display:block"><input type="checkbox" name="checklist[<?= $h($k) ?>]" value="1"> <?= $h($lbl) ?></label><?php endforeach; ?>
      <label for="edSrc">Number of sources checked (listed: <?= (int)$nSrc ?>)</label>
      <input class="input" id="edSrc" name="sources_checked" type="number" min="0" max="50" style="max-width:120px">
      <label style="display:block;margin-top:8px"><input type="checkbox" name="confirm" value="1"> I personally verified this profile and accept responsibility for the "Verified by an editor" label.</label>
    </fieldset>
    <?php endif; ?>
    <p style="margin-top:14px"><button class="btn">Save</button></p>
  </form>
</section>
<?php else: ?>
<section class="panel">
  <h2 style="margin-top:0">Queue</h2>
  <p class="small">Ordered by the Phase 4 priority score (visibility and risk). Open a tool to see its evidence ledger, research tasks and history.</p>
  <table class="table"><thead><tr><th>Priority</th><th>Tool</th><th>Status</th><th>Open tasks</th></tr></thead><tbody>
  <?php foreach (editorial_queue_rows(60) as $row): $t = $byId[$row['id']]; ?>
    <tr><td><?= $h($row['priority']) ?></td><td><a href="/admin-editorial.php?id=<?= $h(rawurlencode($row['id'])) ?>"><?= $h($t['name']) ?></a></td><td><?= $h(editorial_status($t)) ?></td><td><?= count(editorial_research_tasks($t)) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
</section>
<?php endif; ?>
<?php require __DIR__ . '/layout_bottom.php'; ?>
