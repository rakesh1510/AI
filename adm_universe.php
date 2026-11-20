<?php
if (isset($_SERVER["SCRIPT_FILENAME"]) && realpath($_SERVER["SCRIPT_FILENAME"]) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
// Unified Control Center, milestone U3: the AI Universe editorial workflow (Phase 7) rendered INSIDE the shell. Wrapper only: uni_core.php and uni_views.php are unchanged, so the
// maker-checker rules, the independent-verifier rule and the owner's read-only access are still enforced where they always were (the existing handler and uni_core). /universe-editor.php keeps working.
require_once __DIR__ . '/uni_views.php';

function admu_url(array $q = []): string { return '/admin-center.php?' . http_build_query(['m' => 'universe'] + $q); }

/** Point every editorial-workflow link and form at the shell. */
function admu_rewrite(string $html): string {
  $html = str_replace('action="/universe-editor.php"', 'action="/admin-center.php?m=universe"', $html);
  $html = str_replace('href="/universe-editor.php?', 'href="/admin-center.php?m=universe&amp;', $html);
  return str_replace('href="/universe-editor.php"', 'href="/admin-center.php?m=universe"', $html);
}

function admu_panel(string $h): string { return '<section class="panel uvMain">' . $h . '</section>'; }

/**
 * One request through the editorial workflow, same order of checks as universe-editor.php: role, CSRF (done by the caller), owner read-only, rate limit, then the existing post handler.
 * Returns ['redirect' => url] or ['status', 'title', 'body'].
 */
function admu_run(PDO $pdo, array $user, string $method, array $get, array $post): array {
  $role = (string)$user['role']; $status = 200; $title = 'Editorial workflow'; $body = ''; $errors = []; $old = []; $bad = []; $fail = ''; $type = ''; $query = $get;
  $out = function (int $s, string $t, string $h) use (&$status, &$title, &$body) { $status = $s; $title = $t; $body = $h; };
  if (!in_array($role, ['editor', 'verifier', 'owner'], true)) { sec_log('uni_forbidden_role', ['role' => $role]); $out(403, 'Not allowed', admu_panel('<h1>Not allowed</h1><p>Your account role does not have access to the editorial workflow.</p>')); }
  elseif ($method === 'POST') {
    if ($role === 'owner') $out(403, 'Read only', admu_panel('<h1>Read only</h1><p>The owner role can look at the editorial workflow but not change it.</p>'));
    elseif (sec_rate_limited('uni_write:' . (int)$user['id'], 60, 600)) { sec_log('uni_rate_limited'); $out(429, 'Too many changes', admu_panel('<h1>Too many changes</h1><p>' . uv_h(uv_error_text('rate_limited')) . '</p>')); }
    else {
      $res = uv_handle_post($pdo, $user, $post);
      if ($res['ok']) { $go = (array)$res['go']; $flash = isset($go['m']) ? (string)$go['m'] : ''; unset($go['m'], $go['msg']); return ['redirect' => admu_url($go + ($flash !== '' ? ['msg' => $flash] : []))]; }
      $fail = is_string($post['act'] ?? null) ? $post['act'] : ''; $type = is_string($post['t'] ?? null) ? $post['t'] : '';
      if (in_array($res['code'], ['forbidden', 'maker_checker', 'not_yours'], true)) $out(403, 'Not allowed', '<section class="panel uvMain"><h2>Not allowed</h2><p role="alert">' . uv_h(uv_error_text($res['code'])) . ' Nothing was changed.</p></section>');
      else {
        $status = $res['code'] === 'not_found' ? 404 : 422;
        $fld = uv_error_field($res['code'], $type); $bad = $fld === '' ? [] : [$fld];
        $errors = [[$fld === '' ? '' : $fail . '_' . $fld, uv_error_text($res['code'])]];
        foreach ($post as $k => $v) if (is_string($v) && $k !== '_csrf' && strlen($v) <= 4000) $old[$k] = $v;
        $query = ['v' => $fail === 'propose' ? 'new' : ($fail === 'review' || in_array($fail, ['attach_source', 'submit', 'correct'], true) ? 'item' : 'home'), 't' => $type, 'id' => $post['id'] ?? ''];
        if ($status === 404) { $old = []; $bad = []; }
      }
    }
  }
  if ($body === '') {
    $view = is_string($query['v'] ?? null) ? $query['v'] : 'home'; if (!in_array($view, ['home', 'mine', 'new', 'queue', 'overview', 'item'], true)) $view = 'home';
    $type = is_string($query['t'] ?? null) ? (string)$query['t'] : '';
    $want = ['mine' => 'editor', 'new' => 'editor', 'queue' => 'verifier', 'overview' => 'owner'][$view] ?? '';
    $o = ['old' => $old, 'bad' => $bad, 'failact' => $fail];
    $pre = uv_error_summary($errors) . uv_flash(is_string($get['msg'] ?? null) ? $get['msg'] : '');
    if ($want !== '' && $want !== $role) { $status = 403; $title = 'Not allowed'; $body = '<section class="panel"><h2>Not allowed</h2><p>Your role does not have this page.</p></section>'; }
    elseif ($view === 'item') {
      $iid = uv_int($query['id'] ?? null); $row = in_array($type, uv_types($pdo), true) && $iid > 0 ? uni_row($pdo, $type, $iid) : null;
      $seen = $row && ($role !== 'editor' || (int)$row['proposed_by'] === (int)$user['id'] || ($row['state'] === 'verified' && (int)$row['is_current'] === 1));   // an editor sees own items and verified ones
      if (!$seen) { $status = 404; $title = 'Not found'; $body = $pre . '<section class="panel"><h2>Not found</h2><p>That item does not exist or is not available to you.</p></section>'; }
      else { $title = 'Item'; $body = $pre . uv_view_item($pdo, $user, $type, $row, $o); }
    }
    elseif ($view === 'new') { $title = 'New proposal'; $body = $pre . uv_view_new($pdo, $type, $o); }
    elseif ($view === 'mine') { $title = 'My queue'; $body = $pre . uv_view_mine($pdo, $user); }
    elseif ($view === 'queue') { $title = 'Review queue'; $body = $pre . uv_view_queue($pdo, $user); }
    elseif ($view === 'overview') { $title = 'Overview'; $body = $pre . uv_view_overview($pdo); }
    else { $title = 'Home'; $body = $pre . uv_view_home($user); }
    $body = '<section class="panel uvMain">' . uv_nav($view, $user) . $body . '</section>';
  }
  return ['status' => $status, 'title' => $title, 'body' => $body];
}

/** Heading plus rewritten markup (also used by the synthetic previews). */
function admu_compose(string $inner): string {
  return '<div class="admCrm admUni"><h1>AI Universe review</h1><p class="small">Editors propose, verifiers decide independently, and the owner can look but not change. Nothing here publishes to the public site by itself.</p>' . admu_rewrite($inner) . '</div>';
}

function admu_css(): string {
  return '.admUni .uvMain{margin:0 0 14px}.admUni .uvNav{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 16px}.admUni .uvForm .uvFieldset{border:1px solid var(--line);border-radius:10px;padding:14px 16px;margin:0 0 16px}'
    . '.admUni .uvFieldset legend{font-weight:700;padding:0 6px}.admUni .uvCheck label{display:flex;gap:10px;align-items:flex-start;min-height:44px;padding:8px 0;cursor:pointer}.admUni .uvCheck input{width:24px;height:24px;flex:none}'
    . '.admUni .uvDl{display:grid;grid-template-columns:max-content 1fr;gap:6px 16px}.admUni .uvDl dt{font-weight:700}.admUni .uvDl dd{margin:0;overflow-wrap:anywhere}.admUni .uvSources{list-style:none;padding:0}'
    . '.admUni .uvSource{border:1px solid var(--line);border-radius:10px;padding:12px 16px;margin:0 0 12px;overflow-wrap:anywhere}.admUni .uvSource blockquote{margin:8px 0;padding:8px 14px;border-left:4px solid var(--brand)}'
    . '.admUni .uvErr{border-color:#b42318}.admUni .uvErr:focus{outline:3px solid #b42318;outline-offset:2px}.admUni .uvAi{border:2px solid currentColor;font-weight:700}.admUni .uvState{font-weight:700}'
    . '.admUni .sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}.admUni .panel a:not(.btn),.admUni td a{display:inline-flex;align-items:center;min-height:44px}'
    . '@media (max-width:640px){.admUni .uvDl{grid-template-columns:1fr}}';
}
