<?php
// Editor and verifier interface for the AI Universe editorial workflow (Phase 7). Not public: CRM user sign-in required (editor, verifier or owner role).
// GET never changes state. Every POST needs the session CSRF token and a same-origin request, is rate limited, and ends in a 303 redirect (or a 4xx re-render with an error summary).
// All writes happen in uni_core.php; this page only routes, checks permissions and renders.
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/uni_views.php';
$uniUser = crm_gate();          // renders the sign-in form / disabled notice and exits when there is no signed-in user
$pdo = crm_pdo();
header('Cache-Control: no-store'); header('X-Robots-Tag: noindex, nofollow');
$uniRole = (string)$uniUser['role']; $uniStatus = 200; $uniBody = ''; $uniTitle = 'Editorial workflow'; $uniErrors = []; $uniOld = []; $uniBad = []; $uniFail = '';
$uniOut = function (int $status, string $title, string $html) use (&$uniStatus, &$uniTitle, &$uniBody) { $uniStatus = $status; $uniTitle = $title; $uniBody = $html; };
$uniMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$uniQuery = $_GET; $uniType = ''; $uniView = 'home';

if (!in_array($uniMethod, ['GET', 'HEAD', 'POST'], true)) { header('Allow: GET, HEAD, POST'); http_response_code(405); exit('Method not allowed.'); }
if (!in_array($uniRole, ['editor', 'verifier', 'owner'], true)) {
  sec_log('uni_forbidden_role', ['role' => $uniRole]);
  $uniOut(403, 'Not allowed', '<section class="panel"><h1>Not allowed</h1><p>Your account role does not have access to the editorial workflow.</p></section>');
} elseif ($uniMethod === 'POST') {
  if (!crm_csrf_ok()) { sec_log('uni_csrf_rejected'); $uniOut(403, 'Security check failed', '<section class="panel"><h1>Security check failed</h1><p>Reload the page and try again. Nothing was changed.</p></section>'); }
  elseif (($_POST['act'] ?? '') === 'logout') { crm_audit($pdo, $uniUser, 'logout', 'user', (int)$uniUser['id'], 'signed out'); crm_logout(); header('Location: /universe-editor.php', true, 303); exit; }
  elseif ($uniRole === 'owner') $uniOut(403, 'Read only', '<section class="panel"><h1>Read only</h1><p>The owner role can look at the editorial workflow but not change it.</p></section>');
  elseif (sec_rate_limited('uni_write:' . (int)$uniUser['id'], 60, 600)) { sec_log('uni_rate_limited'); header('Retry-After: 600'); $uniOut(429, 'Too many changes', '<section class="panel"><h1>Too many changes</h1><p>' . uv_h(uv_error_text('rate_limited')) . '</p></section>'); }
  else {
    $res = uv_handle_post($pdo, $uniUser, $_POST);
    if ($res['ok']) { header('Location: /universe-editor.php?' . http_build_query($res['go']), true, 303); exit; }
    $uniFail = is_string($_POST['act'] ?? null) ? $_POST['act'] : ''; $uniType = is_string($_POST['t'] ?? null) ? $_POST['t'] : '';
    if (in_array($res['code'], ['forbidden', 'maker_checker', 'not_yours'], true)) $uniOut(403, 'Not allowed', '<section class="panel uvMain"><h2>Not allowed</h2><p role="alert">' . uv_h(uv_error_text($res['code'])) . ' Nothing was changed.</p></section>');
    else {
    $uniStatus = $res['code'] === 'not_found' ? 404 : 422;
    $fld = uv_error_field($res['code'], $uniType); $uniBad = $fld === '' ? [] : [$fld];
    $uniErrors = [[$fld === '' ? '' : $uniFail . '_' . $fld, uv_error_text($res['code'])]];
    foreach (($_POST) as $k => $v) if (is_string($v) && $k !== '_csrf' && strlen($v) <= 4000) $uniOld[$k] = $v;
    $uniQuery = ['v' => $uniFail === 'propose' ? 'new' : ($uniFail === 'review' || in_array($uniFail, ['attach_source', 'submit', 'correct'], true) ? 'item' : 'home'), 't' => $uniType, 'id' => $_POST['id'] ?? ''];
    if ($uniStatus === 404) { $uniOld = []; $uniBad = []; }
    }
  }
}
if ($uniBody === '') {   // GET views, and the re-render of a refused POST
  $uniView = is_string($uniQuery['v'] ?? null) ? $uniQuery['v'] : 'home'; if (!in_array($uniView, ['home', 'mine', 'new', 'queue', 'overview', 'item'], true)) $uniView = 'home';
  $uniType = is_string($uniQuery['t'] ?? null) ? (string)$uniQuery['t'] : '';
  $want = ['mine' => 'editor', 'new' => 'editor', 'queue' => 'verifier', 'overview' => 'owner'][$uniView] ?? '';
  $o = ['old' => $uniOld, 'bad' => $uniBad, 'failact' => $uniFail];
  $pre = uv_error_summary($uniErrors) . uv_flash(is_string($_GET['m'] ?? null) ? $_GET['m'] : '');
  if ($want !== '' && $want !== $uniRole) { $uniStatus = 403; $uniTitle = 'Not allowed'; $uniBody = '<section class="panel"><h2>Not allowed</h2><p>Your role does not have this page.</p></section>'; }
  elseif ($uniView === 'item') {
    $iid = uv_int($uniQuery['id'] ?? null); $row = in_array($uniType, UV_TYPES, true) && $iid > 0 ? uni_row($pdo, $uniType, $iid) : null;
    $seen = $row && ($uniRole !== 'editor' || (int)$row['proposed_by'] === (int)$uniUser['id'] || ($row['state'] === 'verified' && (int)$row['is_current'] === 1));   // an editor sees own items and verified ones
    if (!$seen) { $uniStatus = 404; $uniTitle = 'Not found'; $uniBody = $pre . '<section class="panel"><h2>Not found</h2><p>That item does not exist or is not available to you.</p></section>'; }
    else { $uniTitle = 'Item'; $uniBody = $pre . uv_view_item($pdo, $uniUser, $uniType, $row, $o); }
  }
  elseif ($uniView === 'new') { $uniTitle = 'New proposal'; $uniBody = $pre . uv_view_new($pdo, $uniType, $o); }
  elseif ($uniView === 'mine') { $uniTitle = 'My queue'; $uniBody = $pre . uv_view_mine($pdo, $uniUser); }
  elseif ($uniView === 'queue') { $uniTitle = 'Review queue'; $uniBody = $pre . uv_view_queue($pdo, $uniUser); }
  elseif ($uniView === 'overview') { $uniTitle = 'Overview'; $uniBody = $pre . uv_view_overview($pdo); }
  else { $uniTitle = 'Home'; $uniBody = $pre . uv_view_home($uniUser); }
  $uniBody = '<section class="panel uvMain">' . uv_nav($uniView, $uniUser) . $uniBody . '</section>';
}
http_response_code($uniStatus);
$title = $uniTitle . ' — AIHubpedia editorial workflow'; $robots = 'noindex, nofollow';   // private page: the layout must not downgrade this to "noindex, follow"
require __DIR__ . '/layout_top.php';
?>
<style>
.uvMain{margin:18px auto}.uvNav{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 16px}.uvForm .uvFieldset{border:1px solid var(--border);border-radius:14px;padding:14px 16px;margin:0 0 16px}
.uvFieldset legend{font-weight:700;padding:0 6px}.uvCheck label{display:flex;gap:10px;align-items:flex-start;min-height:44px;padding:8px 0;cursor:pointer}.uvCheck input{width:24px;height:24px;flex:none}
.uvDl{display:grid;grid-template-columns:max-content 1fr;gap:6px 16px}.uvDl dt{font-weight:700}.uvDl dd{margin:0;overflow-wrap:anywhere}.uvSources{list-style:none;padding:0}.uvSource{border:1px solid var(--border);border-radius:14px;padding:12px 16px;margin:0 0 12px;overflow-wrap:anywhere}
.uvSource blockquote{margin:8px 0;padding:8px 14px;border-left:4px solid var(--brand)}.uvErr{border-color:#b42318}.uvErr:focus{outline:3px solid #b42318;outline-offset:2px}.uvAi{border:2px solid currentColor;font-weight:700}.uvState{font-weight:700}
.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}@media (max-width:640px){.uvDl{grid-template-columns:1fr}}
</style>
<h1>Editorial workflow <span class="small">(internal)</span></h1>
<form method="post" action="/universe-editor.php"><?= crm_csrf_field() ?><input type="hidden" name="act" value="logout"><button type="submit" class="btn secondary sm">Sign out <?= uv_h($uniUser['display_name']) ?> (<?= uv_h($uniRole) ?>)</button></form>
<?= $uniBody ?>
<?php require __DIR__ . '/layout_bottom.php'; ?>
