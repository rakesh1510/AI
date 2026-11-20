<?php
if (isset($_SERVER["SCRIPT_FILENAME"]) && realpath($_SERVER["SCRIPT_FILENAME"]) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
// Unified Control Center, milestone U2: the existing CRM (Phase 6) rendered INSIDE the shell. Wrapper only: crm_views.php / crm_core.php are unchanged, so every CRM rule
// (roles, validation, stale-edit checks, audit) still applies and /crm.php keeps working (legacy address preserved). No new table, no new permission, no new account system.

/** Address of a CRM view inside the shell. */
function admc_url(string $view = 'dashboard', array $extra = []): string {
  return '/admin-center.php?' . http_build_query(['m' => 'crm', 'v' => $view] + $extra);
}

/** Point every CRM link and form at the shell. Only the fixed CRM address is rewritten; nothing else in the markup is touched. */
function admc_rewrite(string $html): string {
  // A GET form drops the query string of its action, so the shell's module parameter travels as a hidden field instead.
  $html = preg_replace('#<form method="get" action="/crm\.php"([^>]*)>#i', '<form method="get" action="/admin-center.php"$1><input type="hidden" name="m" value="crm">', $html) ?? $html;
  $html = str_replace('action="/crm.php"', 'action="/admin-center.php?m=crm"', $html);
  $html = str_replace(['href="/crm.php?', "href='/crm.php?"], ['href="/admin-center.php?m=crm&amp;', "href='/admin-center.php?m=crm&amp;"], $html);
  return str_replace('href="/crm.php"', 'href="/admin-center.php?m=crm"', $html);
}

/** Which CRM view a request asks for; anything unknown is the dashboard. */
function admc_view(array $get): string { $v = $get['v'] ?? 'dashboard'; return is_string($v) && in_array($v, crm_views(), true) ? $v : 'dashboard'; }

/** Page body: flash message, CRM section bar, and the unchanged CRM view. Flash code travels in `msg` (the shell owns `m`). */
function admc_body(PDO $pdo, array $user, array $get): string {
  $view = admc_view($get);
  $q = $get; unset($q['m'], $q['msg']); $q['v'] = $view;
  $flash = '';
  if (isset($get['msg']) && is_string($get['msg']) && $get['msg'] !== '') $flash = '<div class="noteBox" role="status">' . crm_h(crm_msg_label($get['msg'])) . '</div>';
  ob_start(); crm_render_view($pdo, $user, $view, $q); $inner = (string)ob_get_clean();
  return admc_compose($view, $user, $flash, $inner);
}

/** Pure composition (also used by the synthetic previews): heading, section bar, flash, rewritten CRM markup. */
function admc_compose(string $view, array $user, string $flash, string $inner): string {
  return '<div class="admCrm"><h1>CRM and leads</h1><p class="small">Internal records only. Nothing here sends email, invoices or payments. Personal data is limited to business contact details; opening a lead is logged.</p>'
    . admc_rewrite(crm_nav($view, $user)) . $flash . admc_rewrite($inner) . '</div>';
}

/** Handle one CRM action. Caller has already checked CSRF, same origin and the `crm` permission. Returns the address to redirect to. */
function admc_post(PDO $pdo, array $user, array $post): string {
  [$go, $code] = crm_handle_action($pdo, $user, $post);
  parse_str((string)$go, $gq); $v = is_string($gq['v'] ?? null) && in_array($gq['v'], crm_views(), true) ? $gq['v'] : 'dashboard'; unset($gq['m'], $gq['msg']);
  return admc_url($v, array_diff_key($gq, ['v' => 1]) + ['msg' => (string)$code]);
}

/** Scoped compatibility styles for the CRM markup (it was written for the public layout). Uses the shell's colour tokens, so light and dark both work. */
function admc_css(): string {
  return '.admCrm .small{color:var(--muted);font-size:.9rem}.admCrm h2{margin:1.2rem 0 .5rem;font-size:1.2rem}.admCrm h3{font-size:1.05rem}'
    . '.admCrm .btn{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:8px 16px;border-radius:8px;border:1px solid var(--brand);background:var(--brand);color:var(--brandInk);font:inherit;font-weight:600;text-decoration:none;cursor:pointer}'
    . '.admCrm .btn.secondary{background:var(--surface);color:var(--brand)}.admCrm .btn.sm{min-height:44px;padding:6px 12px;font-size:.92rem}'
    . '.admCrm .input,.admCrm input[type=text],.admCrm input[type=search],.admCrm input[type=number],.admCrm input[type=date],.admCrm input[type=email],.admCrm select,.admCrm textarea{width:100%;max-width:100%;min-height:44px;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:var(--surface);color:var(--ink);font:inherit}'
    . '.admCrm .field{display:flex;flex-direction:column;gap:4px;margin:0 0 10px}.admCrm .field label,.admCrm label{font-weight:600;font-size:.92rem}'
    . '.admCrm .crmForm{display:flex;flex-wrap:wrap;gap:10px 14px;align-items:flex-end;margin:8px 0 14px}.admCrm .crmForm .field{flex:1 1 200px;margin:0}'
    . '.admCrm .panel,.admCrm .card{background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:14px;margin:0 0 14px}'
    . '.admCrm .cardGrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin:0 0 14px}'
    . '.admCrm .noteBox{background:var(--surface);border:1px solid var(--line);border-left:4px solid var(--brand);border-radius:8px;padding:10px 14px;margin:0 0 14px}'
    . '.admCrm .badge{display:inline-block;padding:2px 10px;border-radius:999px;border:1px solid var(--line);background:var(--surface);color:var(--ink);font-size:.82rem}'
    . '.admCrm .tableWrap{overflow-x:auto;border:1px solid var(--line);border-radius:10px;background:var(--surface);margin:0 0 14px}.admCrm .tableWrap:focus-visible{outline:3px solid var(--brand);outline-offset:2px}'
    . '.admCrm .table{width:100%;border-collapse:collapse;font-size:.94rem}.admCrm .table th,.admCrm .table td{padding:8px 10px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}.admCrm .table th{background:var(--bg);white-space:nowrap}'
    . '.admCrm .sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}'
    . '.admCrm .table a,.admEd a:not(.btn),.admAdv a:not(.btn),.admAcc a:not(.btn){display:inline-flex;align-items:center;min-height:44px;min-width:44px}'
    . '.admCrm .crmNav{display:flex;flex-wrap:wrap;gap:6px}.admCrm a:not(.btn){color:var(--brand)}';
}
