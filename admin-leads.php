<?php
// Lead status and reporting prototype (admin only). Raw counts, no revenue or conversion-rate claims.
// Authorization: signed-in admin session only (see admin_auth.php); all changes are POST + session CSRF; email addresses are masked in
// the list and shown in full only on a detail view, which is written to the security log.
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/admin_auth.php';
$title = 'Leads — AIHubpedia admin';
admin_require('Leads');

$ready = false; $pdo = null; $err = '';
try { db_connection_available(); $pdo = db(); $ready = db_table_exists('leads'); } catch (Throwable $e) { sec_log_exception($e, 'admin-leads'); }

if ($ready && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  if (!admin_csrf_ok()) { sec_log('admin_csrf_rejected', ['page' => 'leads']); http_response_code(403); exit('Security check failed.'); }
  if (($_POST['admin_action'] ?? '') === 'logout') { admin_logout(); header('Location: /admin-leads.php', true, 303); exit; }
  if (($_POST['admin_action'] ?? '') === 'set_status') {
    $id = (int)($_POST['id'] ?? 0); $to = (string)($_POST['status'] ?? '');
    $res = lead_set_status($pdo, $id, $to);
    sec_log('admin_lead_status', ['id' => $id, 'to' => $to, 'result' => $res]);
    header('Location: /admin-leads.php?' . ($res === 'ok' ? 'id=' . $id . '&updated=1' : 'error=' . rawurlencode($res)), true, 303);
    exit;
  }
}

$detail = null;
if ($ready && isset($_GET['id'])) {
  $detail = lead_get($pdo, (int)$_GET['id']);
  if ($detail) sec_log('admin_lead_view', ['id' => (int)$detail['id']]);
}
$days = (int)($_GET['days'] ?? 90); if (!in_array($days, [7, 30, 90, 365], true)) $days = 90;
$filters = ['type' => (string)($_GET['type'] ?? ''), 'status' => (string)($_GET['status'] ?? ''), 'q' => trim((string)($_GET['q'] ?? '')), 'days' => $days, 'page' => (int)($_GET['page'] ?? 1)];
$list = $ready ? leads_list($pdo, $filters) : ['rows' => [], 'total' => 0];
$report = $ready ? leads_report($pdo, $days) : null;
$perPage = 25; $pages = max(1, (int)ceil($list['total'] / $perPage));
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$typeLabel = fn($t) => lead_types()[$t]['label'] ?? $t;
$qs = fn(array $o = []) => '/admin-leads.php?' . http_build_query(array_filter(array_merge(['type' => $filters['type'], 'status' => $filters['status'], 'q' => $filters['q'], 'days' => $days], $o), fn($v) => $v !== '' && $v !== null));
require __DIR__ . '/layout_top.php';
?>
<h1>Leads <span class="small">(admin prototype)</span></h1>
<form method="post" style="float:right"><?= admin_csrf_field() ?><input type="hidden" name="admin_action" value="logout"><button class="btn secondary sm">Sign out</button></form>
<p class="small"><a href="/admin.php">Listing submissions</a> · Counts only: no revenue, traffic or conversion-rate figures are calculated or shown. Personal data: addresses are masked in lists; opening a lead is logged.</p>

<?php if (!$ready): ?>
  <div class="noticeBox" role="alert">The <code>leads</code> table is not available, so there is nothing to show. Apply <code>database/phase3_business.sql</code> (and <code>phase4_business.sql</code>) to a database you control first.</div>
<?php else: ?>
  <?php if (!empty($report['synthetic'])): ?><div class="noticeBox" role="status"><strong>Synthetic test data:</strong> <?= (int)$report['synthetic'] ?> of the leads in this period are generated test records, not real people.</div><?php endif; ?>
  <?php if (isset($_GET['error'])): ?><div class="noticeBox" role="alert">That status change was not applied (<?= $h((string)$_GET['error']) ?>).</div><?php endif; ?>
  <?php if (isset($_GET['updated'])): ?><div class="successBox" role="status">Status updated.</div><?php endif; ?>

  <?php if ($detail): ?>
    <section class="panel" aria-labelledby="leadTitle">
      <h2 id="leadTitle" style="margin-top:0">Lead #<?= (int)$detail['id'] ?> · <?= $h($typeLabel($detail['lead_type'])) ?></h2>
      <dl class="factList">
        <dt>Received</dt><dd><?= $h($detail['created_at']) ?></dd>
        <dt>Name</dt><dd><?= $h($detail['name']) ?></dd>
        <dt>Email</dt><dd><?= $h($detail['email']) ?></dd>
        <?php if ($detail['company'] !== ''): ?><dt>Company</dt><dd><?= $h($detail['company']) ?></dd><?php endif; ?>
        <?php if ($detail['website'] !== ''): ?><dt>Website</dt><dd><?= $h($detail['website']) ?></dd><?php endif; ?>
        <dt>Budget / timeline</dt><dd><?= $h(lead_budget_options()[$detail['budget']] ?? '') ?: '—' ?> / <?= $h(lead_timeline_options()[$detail['timeline']] ?? '') ?: '—' ?></dd>
        <?php if (!empty($detail['package_interest'])): ?><dt>Package interest</dt><dd><?= $h(lead_package_options()[$detail['package_interest']] ?? $detail['package_interest']) ?></dd><?php endif; ?>
        <?php if (!empty($detail['campaign_goal'])): ?><dt>Goal</dt><dd><?= $h(lead_goal_options()[$detail['campaign_goal']] ?? $detail['campaign_goal']) ?></dd><?php endif; ?>
        <dt>Source page</dt><dd><?= $h($detail['source_page']) ?: '—' ?></dd>
        <?php if (!empty($detail['attr_source']) || !empty($detail['attr_campaign']) || !empty($detail['ref_tool'])): ?><dt>Attribution</dt><dd><?= $h(trim(($detail['attr_source'] ?? '') . ' ' . ($detail['attr_medium'] ?? '') . ' ' . ($detail['attr_campaign'] ?? ''))) ?><?= !empty($detail['ref_tool']) ? ' · from tool ' . $h($detail['ref_tool']) : '' ?></dd><?php endif; ?>
        <dt>Status</dt><dd><strong><?= $h(lead_statuses()[$detail['status']] ?? $detail['status']) ?></strong></dd>
      </dl>
      <h3>Message</h3>
      <p style="white-space:pre-wrap"><?= $h($detail['message']) ?></p>
      <?php $next = lead_status_transitions()[$detail['status']] ?? []; if ($next): ?>
        <form method="post" class="fieldRow" style="align-items:end;margin-top:12px">
          <?= admin_csrf_field() ?><input type="hidden" name="admin_action" value="set_status"><input type="hidden" name="id" value="<?= (int)$detail['id'] ?>">
          <div class="field"><label for="newStatus">Change status to</label>
            <select id="newStatus" name="status"><?php foreach ($next as $n): ?><option value="<?= $h($n) ?>"><?= $h(lead_statuses()[$n]) ?></option><?php endforeach; ?></select></div>
          <div><button class="btn">Update status</button></div>
        </form>
      <?php endif; ?>
      <?php if ($detail['history']): ?>
        <h3>Status history</h3>
        <ul class="small"><?php foreach ($detail['history'] as $x): ?><li><?= $h($x['changed_at']) ?>: <?= $h(lead_statuses()[$x['old_status']] ?? $x['old_status']) ?> → <?= $h(lead_statuses()[$x['new_status']] ?? $x['new_status']) ?></li><?php endforeach; ?></ul>
      <?php endif; ?>
      <p><a href="<?= $h($qs()) ?>">← Back to the list</a></p>
    </section>
  <?php elseif (isset($_GET['id'])): ?>
    <div class="noticeBox" role="alert">Lead not found.</div>
  <?php endif; ?>

  <section class="panel" aria-labelledby="repTitle" style="margin-top:18px">
    <h2 id="repTitle" style="margin-top:0">Summary, last <?= (int)$days ?> days</h2>
    <p class="small">Total leads: <strong><?= (int)$report['total'] ?></strong>. Stage counts show how many leads are currently at or beyond each stage (raw numbers, not rates). With small numbers, differences are noise.</p>
    <div class="matrixWrap" tabindex="0" role="region" aria-label="Lead summary tables, scrollable">
    <table class="matrix">
      <caption class="sr">Leads by type, status and source</caption>
      <thead><tr><th scope="col">Group</th><th scope="col">Item</th><th scope="col">Count</th></tr></thead>
      <tbody>
        <?php foreach ($report['by_type'] as $x): ?><tr><th scope="row">Type</th><td data-label="Item"><?= $h($typeLabel($x['lead_type'])) ?></td><td data-label="Count"><?= (int)$x['n'] ?></td></tr><?php endforeach; ?>
        <?php foreach ($report['by_status'] as $x): ?><tr><th scope="row">Status</th><td data-label="Item"><?= $h(lead_statuses()[$x['status']] ?? $x['status']) ?></td><td data-label="Count"><?= (int)$x['n'] ?></td></tr><?php endforeach; ?>
        <?php foreach ($report['stages'] as $st => $n): ?><tr><th scope="row">At or beyond</th><td data-label="Item"><?= $h(lead_statuses()[$st]) ?></td><td data-label="Count"><?= (int)$n ?></td></tr><?php endforeach; ?>
        <?php foreach ($report['by_page'] as $x): ?><tr><th scope="row">Source page</th><td data-label="Item"><?= $h($x['source_page']) ?></td><td data-label="Count"><?= (int)$x['n'] ?></td></tr><?php endforeach; ?>
        <?php foreach ($report['by_source'] as $x): ?><tr><th scope="row">Attribution</th><td data-label="Item"><?= $h(trim($x['attr_source'] . ' ' . $x['attr_campaign'])) ?></td><td data-label="Count"><?= (int)$x['n'] ?></td></tr><?php endforeach; ?>
        <?php foreach ($report['by_package'] as $x): ?><tr><th scope="row">Package interest</th><td data-label="Item"><?= $h(lead_package_options()[$x['package_interest']] ?? $x['package_interest']) ?></td><td data-label="Count"><?= (int)$x['n'] ?></td></tr><?php endforeach; ?>
        <?php if (!$report['total']): ?><tr><td colspan="3">No leads in this period.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
  </section>

  <section style="margin-top:18px" aria-labelledby="listTitle">
    <h2 id="listTitle">All leads (<?= (int)$list['total'] ?>)</h2>
    <form method="get" class="fieldRow" style="align-items:end">
      <div class="field"><label for="fType">Type</label><select id="fType" name="type"><option value="">All</option><?php foreach (lead_types() as $k => $t): ?><option value="<?= $h($k) ?>"<?= $filters['type'] === $k ? ' selected' : '' ?>><?= $h($t['label']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="fStatus">Status</label><select id="fStatus" name="status"><option value="">All</option><?php foreach (lead_statuses() as $k => $l): ?><option value="<?= $h($k) ?>"<?= $filters['status'] === $k ? ' selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="fDays">Period</label><select id="fDays" name="days"><?php foreach ([7, 30, 90, 365] as $d): ?><option value="<?= $d ?>"<?= $days === $d ? ' selected' : '' ?>>Last <?= $d ?> days</option><?php endforeach; ?></select></div>
      <div class="field"><label for="fQ">Name or company</label><input class="input" id="fQ" name="q" value="<?= $h($filters['q']) ?>" maxlength="60"></div>
      <div><button class="btn">Filter</button></div>
    </form>
    <div class="matrixWrap" tabindex="0" role="region" aria-label="Leads table, scrollable">
    <table class="matrix">
      <caption class="sr">Leads, newest first</caption>
      <thead><tr><th scope="col">#</th><th scope="col">Received</th><th scope="col">Type</th><th scope="col">Name</th><th scope="col">Email (masked)</th><th scope="col">Status</th></tr></thead>
      <tbody>
        <?php foreach ($list['rows'] as $r): ?>
          <tr><th scope="row"><a href="<?= $h($qs(['id' => $r['id']])) ?>">#<?= (int)$r['id'] ?></a></th><td data-label="Received"><?= $h($r['created_at']) ?></td><td data-label="Type"><?= $h($typeLabel($r['lead_type'])) ?></td><td data-label="Name"><?= $h($r['name']) ?><?= !empty($r['is_synthetic']) ? ' <span class="small">(synthetic)</span>' : '' ?></td><td data-label="Email (masked)"><?= $h(lead_mask_email($r['email'])) ?></td><td data-label="Status"><?= $h(lead_statuses()[$r['status']] ?? $r['status']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$list['rows']): ?><tr><td colspan="6">No leads match these filters.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
    <?php if ($pages > 1): ?>
      <nav class="pager" aria-label="Pagination"><?php if ($filters['page'] > 1): ?><a href="<?= $h($qs(['page' => $filters['page'] - 1])) ?>">← Previous</a><?php endif; ?><span aria-current="page"><?= (int)$filters['page'] ?></span><?php if ($filters['page'] < $pages): ?><a href="<?= $h($qs(['page' => $filters['page'] + 1])) ?>">Next →</a><?php endif; ?></nav>
    <?php endif; ?>
  </section>
<?php endif; ?>
<?php require __DIR__ . '/layout_bottom.php'; ?>
