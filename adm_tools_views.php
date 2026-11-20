<?php
if (isset($_SERVER["SCRIPT_FILENAME"]) && realpath($_SERVER["SCRIPT_FILENAME"]) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
// Phase 8C (M2-M3): AI tool management screens for the Control Center. Pure rendering over an AdmToolStore, so previews and tests need no database.
require_once __DIR__ . '/adm_tools_core.php';
require_once __DIR__ . '/adm_tools_screens.php';

function admt_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function admt_url(array $p = []): string { return '/admin-center.php?' . http_build_query(['m' => 'tools'] + array_filter($p, fn($v) => $v !== '' && $v !== null && $v !== false)); }
function admt_chip(string $s): string { return '<span class="chip chip-' . admt_h($s) . '">' . admt_h(ucfirst($s)) . '</span>'; }

function admt_css(): string {
  return '.tHead{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px}.tBtn{display:inline-flex;align-items:center;justify-content:center;min-height:44px;min-width:44px;padding:.5rem 1rem;border-radius:10px;border:1px solid var(--line);background:var(--surface);color:var(--ink);font:inherit;font-weight:600;text-decoration:none;cursor:pointer;box-shadow:0 1px 0 rgba(0,0,0,.04),0 6px 14px -8px rgba(16,32,63,.35);transition:transform .15s,box-shadow .15s}'
    . '.tBtn:hover{transform:translateY(-1px);box-shadow:0 8px 18px -8px rgba(16,32,63,.45)}.tBtn.primary{background:linear-gradient(180deg,var(--brand),#0847c4);border-color:transparent;color:#fff}.tBtn.danger{border-color:#b42318;color:#b42318}'
    . '@media (prefers-color-scheme:dark){.tBtn.primary{color:#06142e;background:linear-gradient(180deg,#7db1ff,var(--brand))}.tBtn.danger{border-color:#ff8a80;color:#ff8a80}}'
    . '.tTabs{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0;padding:0;list-style:none}.tTabs a{display:inline-flex;align-items:center;gap:6px;min-height:44px;padding:0 .9rem;border:1px solid var(--line);border-radius:999px;background:var(--surface);color:var(--ink);text-decoration:none}.tTabs a[aria-current=true],.tTabs a[aria-current=page]{background:var(--brand);color:var(--brandInk);border-color:transparent;font-weight:600}'
    . '.tFilter{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;align-items:end;background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:14px;box-shadow:0 10px 24px -18px rgba(16,32,63,.5)}'
    . '.tFilter label,.tForm label{display:grid;gap:4px;font-size:.88rem;color:var(--muted);font-weight:600}.tFilter input,.tFilter select,.tForm input,.tForm select,.tForm textarea{min-height:44px;padding:.5rem .65rem;border:1px solid var(--line);border-radius:10px;background:var(--bg);color:var(--ink);font:inherit;width:100%}.tForm textarea{min-height:96px}'
    . '.tFilter .chk{display:flex;align-items:center;gap:8px;min-height:44px}.tFilter .chk input{width:22px;min-height:22px}'
    . '.toolCards{list-style:none;margin:16px 0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,300px),1fr));gap:14px}'
    . '.toolCard{position:relative;display:grid;gap:6px;background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:14px;box-shadow:0 1px 0 rgba(255,255,255,.4) inset,0 12px 26px -20px rgba(16,32,63,.6);transition:transform .18s,box-shadow .18s}'
    . '.toolCard:hover{transform:translateY(-2px);box-shadow:0 18px 30px -20px rgba(16,32,63,.7)}.toolCard h3{margin:0;font-size:1.05rem;overflow-wrap:anywhere}.toolCard h3 a{display:inline-flex;align-items:center;min-height:44px}.toolCard .meta{color:var(--muted);font-size:.85rem;overflow-wrap:anywhere}.toolCard code,.tDetail code{font-size:.82rem}'
    . '.chip{display:inline-block;font-size:.74rem;font-weight:700;padding:.1rem .55rem;border-radius:999px;border:1px solid currentColor;white-space:nowrap}.chip-published{color:var(--ok)}.chip-draft{color:var(--na)}.chip-unpublished{color:var(--muted)}.chip-deleted{color:#b42318}'
    . '@media (prefers-color-scheme:dark){.chip-deleted{color:#ff8a80}}.warn{color:var(--na);font-size:.82rem}.tDetail dl{display:grid;grid-template-columns:minmax(110px,170px) minmax(0,1fr);gap:6px 14px;margin:0}.tDetail dt{color:var(--muted);font-weight:600}.tDetail dd{margin:0;overflow-wrap:anywhere}'
    . '.tActions{display:flex;flex-wrap:wrap;gap:10px;align-items:center}.tActions form{margin:0;display:flex;flex-wrap:wrap;gap:8px;align-items:center}.tForm{display:grid;gap:14px;max-width:820px}.tForm .row2{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}'
    . '.err{color:#b42318;font-size:.85rem;font-weight:600}@media (prefers-color-scheme:dark){.err{color:#ff8a80}}.flash{border:1px solid var(--ok);border-left-width:6px;border-radius:10px;padding:10px 14px;background:var(--surface);margin:10px 0}.flash.bad{border-color:#b42318}'
    . '.pager{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.tHist{list-style:none;margin:0;padding:0;display:grid;gap:8px}.tHist li{border-left:3px solid var(--line);padding-left:12px}'
    . '.tSub{margin-top:0}.tRows{list-style:none;margin:0;padding:0;display:grid;gap:10px}.tRow{display:flex;flex-wrap:wrap;gap:10px 16px;align-items:center;justify-content:space-between;border:1px solid var(--line);border-radius:12px;padding:10px 12px;background:var(--bg)}.tRow>div{min-width:0;overflow-wrap:anywhere}'
    . '.tInline{display:flex;flex-wrap:wrap;gap:8px;align-items:end;margin:0}.tInline label{display:grid;gap:4px;font-size:.85rem;color:var(--muted);font-weight:600}.tInline input{min-height:44px;padding:.4rem .6rem;border:1px solid var(--line);border-radius:10px;background:var(--surface);color:var(--ink);font:inherit}.tChips{list-style:none;display:flex;flex-wrap:wrap;gap:8px;padding:0;margin:0 0 12px}'
    . '.selBox{position:absolute;top:6px;right:6px;display:inline-flex;align-items:center;justify-content:center;min-width:44px;min-height:44px;cursor:pointer}.selBox input{width:22px;height:22px}.sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}'
    . '.bulkBar{position:sticky;bottom:0;z-index:5;display:flex;flex-wrap:wrap;gap:10px;align-items:end;background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:12px;margin:14px 0;box-shadow:0 -10px 24px -16px rgba(16,32,63,.55)}.bulkBar label{display:grid;gap:4px;font-size:.85rem;color:var(--muted);font-weight:600}.bulkBar select,.bulkBar input{min-height:44px;padding:.4rem .6rem;border:1px solid var(--line);border-radius:10px;background:var(--bg);color:var(--ink);font:inherit}'
    . '.lockNote{border-left:6px solid var(--brand)}.lockNote h2{margin-top:0;font-size:1.05rem}.catBox .lbl{display:block;font-size:.88rem;color:var(--muted);font-weight:600;margin-bottom:4px}.catLine{margin:0;padding:.55rem .75rem;border:1px dashed var(--line);border-radius:10px;background:var(--bg)}.tForm input[readonly],.tForm textarea[readonly],.tForm select:disabled{background:var(--surface);border-style:dashed;color:var(--muted)}.warn.panel{margin:12px 0}'
    . '@media (max-width:600px){.tSub{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.tSub a{width:100%;justify-content:center;text-align:center;padding:.25rem .5rem}.tDetail dl{grid-template-columns:minmax(0,1fr)}.tDetail dt{margin-top:6px}.bulkBar{position:static}}';
}

function admt_flash(?string $msg, bool $bad = false): string { return $msg ? '<p class="flash' . ($bad ? ' bad' : '') . '" role="' . ($bad ? 'alert' : 'status') . '">' . admt_h($msg) . '</p>' : ''; }
function admt_field(string $name, string $label, string $val, array $errs, string $type = 'text', array $attr = []): string {
  $id = 'f_' . $name; $e = $errs[$name] ?? null; $a = '';
  foreach ($attr as $k => $v) $a .= ' ' . $k . ($v === true ? '' : '="' . admt_h($v) . '"');
  return '<label for="' . $id . '">' . admt_h($label) . '<input id="' . $id . '" name="' . $name . '" type="' . $type . '" value="' . admt_h($val) . '"' . $a . ($e ? ' aria-invalid="true" aria-describedby="' . $id . '_e"' : '') . '></label>' . ($e ? '<span class="err" id="' . $id . '_e">' . admt_h($e) . '</span>' : '');
}
function admt_area(string $name, string $label, string $val, array $errs, string $hint = '', bool $ro = false): string {
  $id = 'f_' . $name; $e = $errs[$name] ?? null;
  return '<label for="' . $id . '">' . admt_h($label) . ($hint !== '' ? ' <span class="small">(' . admt_h($hint) . ')</span>' : '') . '<textarea id="' . $id . '" name="' . $name . '"' . ($ro ? ' readonly' : '') . ($e ? ' aria-invalid="true" aria-describedby="' . $id . '_e"' : '') . '>' . admt_h($val) . '</textarea></label>' . ($e ? '<span class="err" id="' . $id . '_e">' . admt_h($e) . '</span>' : '');
}

/** Explains what the editorial files control for a tool (shown on the form and the detail page). */
function admt_overlay_notice(?array $o, bool $isNew): string {
  if ($isNew || $o === null) return '<p class="panel warn" role="note"><strong>No editorial profile yet.</strong> This tool is shown as a standard page built from the fields below. The category you choose here is the public category, so it must be one of the public categories. Editorial classification and reviewed profiles are added through the editorial workflow, not here.</p>';
  if ($o['curated']) return '<section class="panel lockNote" aria-labelledby="lk-h"><h2 id="lk-h">Managed by the editorial workflow</h2><p>This tool has a curated editorial profile (' . admt_h($o['review']) . '). The public site shows the profile\'s category, description, features, pros, cons, use cases, tags, pricing and search text instead of the fields stored here, so those fields are read-only. Change them through the editorial workflow (<code>scripts/review_profile.php</code>). Name, status, featured placements and history can still be managed here.</p></section>';
  if ($o['classified']) return '<section class="panel lockNote" role="note"><p><strong>Category is controlled by the editorial classification.</strong> The public category is <strong>' . admt_h($o['public_category']) . '</strong>' . ($o['public_subcategory'] !== '' ? ' · ' . admt_h($o['public_subcategory']) : '') . '. Reclassifying an existing tool is an editorial task (<code>scripts/classify_tools.php</code>), so the category is read-only here. This tool has no curated profile yet, so it is shown as a standard page built from the stored fields.</p></section>';
  return '<p class="panel warn" role="note"><strong>No editorial profile yet.</strong> Shown as a standard page built from the stored fields.</p>';
}

/** Create / edit form. $v = values to show (posted values on a validation error), $errs = field errors. Fields the editorial files control are read-only. */
function admt_form(AdmToolStore $s, array $user, ?array $cur, array $v, array $errs, ?string $top = null): string {
  $edit = $cur !== null; $lines = fn($k) => implode("\n", (array)($v[$k] ?? [])); $ov = $edit ? admt_overlay((string)$cur['slug']) : null; $lk = array_flip($ov['locked'] ?? []); $ro = fn($f) => isset($lk[$f]);
  $note = fn($f, $l) => $ro($f) ? $l . ' (managed by the editorial profile)' : $l;
  $o = '<h1>' . ($edit ? 'Edit ' . admt_h($cur['name']) : 'New tool') . '</h1><p class="lead">' . ($edit ? 'Changes to a published tool go live when saved. Every save is recorded in the history.' : 'New tools start as drafts. Only the owner can publish.') . '</p>';
  $o .= admt_flash($top, true) . admt_overlay_notice($ov, !$edit);
  $o .= '<form class="tForm panel" method="post" action="/admin-center.php?m=tools" novalidate>' . (function_exists('crm_csrf_field') ? crm_csrf_field() : '') . '<input type="hidden" name="act" value="tool_' . ($edit ? 'edit' : 'create') . '">';
  if ($edit) $o .= '<input type="hidden" name="slug" value="' . admt_h($cur['slug']) . '"><input type="hidden" name="version" value="' . (int)$cur['_version'] . '">';
  $o .= '<div class="row2">' . admt_field('name', 'Name', (string)($v['name'] ?? ''), $errs, 'text', ['required' => true, 'maxlength' => '120', 'autocomplete' => 'off']);
  $o .= $edit ? '<label for="f_slug">Address (cannot be changed)<input id="f_slug" type="text" value="' . admt_h($cur['slug']) . '" readonly></label>' : admt_field('slug', 'Address (optional, from the name if empty)', (string)($v['slug'] ?? ''), $errs, 'text', ['maxlength' => '120', 'autocapitalize' => 'none', 'autocomplete' => 'off']);
  $o .= '</div><div class="row2">';
  if ($ro('category')) $o .= '<div class="catBox"><span class="lbl">Category</span><p class="catLine"><strong>Public category:</strong> ' . admt_h($ov['public_category'] ?? '') . ($ov['public_subcategory'] !== '' ? ' · ' . admt_h($ov['public_subcategory']) : '') . '<br><span class="small">Database category: ' . admt_h($cur['category'] !== '' ? $cur['category'] : 'none') . ' (not shown to visitors). Controlled by the editorial classification.</span></p></div>';
  else {
    $o .= '<label for="f_category">Category (public)<select id="f_category" name="category" required' . (isset($errs['category']) ? ' aria-invalid="true" aria-describedby="f_category_e"' : '') . '><option value="">Choose…</option>';
    foreach (admt_public_categories() as $c) $o .= '<option' . (($v['category'] ?? '') === $c ? ' selected' : '') . '>' . admt_h($c) . '</option>';
    $o .= '</select></label>' . (isset($errs['category']) ? '<span class="err" id="f_category_e">' . admt_h($errs['category']) . '</span>' : '');
  }
  $o .= '<label for="f_pricing_type">' . admt_h($note('pricing_type', 'Pricing type')) . '<select id="f_pricing_type" name="pricing_type"' . ($ro('pricing_type') ? ' disabled' : ' required') . (isset($errs['pricing_type']) ? ' aria-invalid="true" aria-describedby="f_pricing_type_e"' : '') . '><option value="">Choose…</option>';
  $ptv = (string)($v['pricing_type'] ?? ''); $ptl = array_unique(array_merge(ADMT_PRICING, $ptv !== '' ? [$ptv] : []));
  foreach ($ptl as $p) $o .= '<option' . ($ptv === $p ? ' selected' : '') . '>' . admt_h($p) . '</option>';
  $o .= '</select></label>' . (isset($errs['pricing_type']) ? '<span class="err" id="f_pricing_type_e">' . admt_h($errs['pricing_type']) . '</span>' : '') . '</div>';
  $o .= admt_area('short', $note('short', 'Short description'), (string)($v['short'] ?? ''), $errs, '20 to 300 characters', $ro('short')) . admt_area('description', $note('description', 'Description'), (string)($v['description'] ?? ''), $errs, '', $ro('description'));
  $o .= '<div class="row2">' . admt_area('features', $note('features', 'Features'), $lines('features'), $errs, 'one per line', $ro('features')) . admt_area('use_cases', $note('use_cases', 'Use cases'), $lines('use_cases'), $errs, 'one per line', $ro('use_cases')) . admt_area('pros', $note('pros', 'Pros'), $lines('pros'), $errs, 'one per line', $ro('pros')) . admt_area('cons', $note('cons', 'Cons'), $lines('cons'), $errs, 'one per line', $ro('cons')) . '</div>';
  $o .= '<div class="row2">' . admt_field('website', $note('website', 'Website'), (string)($v['website'] ?? ''), $errs, 'url', ['required' => true, 'inputmode' => 'url'] + ($ro('website') ? ['readonly' => true] : [])) . admt_field('affiliate', 'Affiliate link (optional)', (string)($v['affiliate'] ?? ''), $errs, 'url', ['inputmode' => 'url']) . '</div>';
  $o .= '<div class="row2">' . admt_field('pricing', $note('pricing', 'Pricing note'), (string)($v['pricing'] ?? ''), $errs, 'text', $ro('pricing') ? ['readonly' => true] : []) . admt_field('logo', 'Logo (optional)', (string)($v['logo'] ?? ''), $errs) . '</div>';
  $o .= admt_field('tags', $note('tags', 'Tags (comma separated, up to 12)'), implode(', ', (array)($v['tags'] ?? [])), $errs, 'text', $ro('tags') ? ['readonly' => true] : []) . '<div class="row2">' . admt_field('seo_title', $note('seo_title', 'Search title (optional, 70)'), (string)($v['seo_title'] ?? ''), $errs, 'text', $ro('seo_title') ? ['readonly' => true] : []) . admt_field('seo_description', $note('seo_description', 'Search description (optional, 200)'), (string)($v['seo_description'] ?? ''), $errs, 'text', $ro('seo_description') ? ['readonly' => true] : []) . '</div>';
  $o .= admt_field('note', 'Change note (optional)', '', [], 'text', ['maxlength' => '500']);
  return $o . '<div class="tActions"><button class="tBtn primary" type="submit">' . ($edit ? 'Save changes' : 'Create draft') . '</button><a class="tBtn" href="' . admt_h($edit ? admt_url(['view' => 'detail', 'slug' => $cur['slug']]) : admt_url()) . '">Cancel</a></div></form>';
}

function admt_action_form(array $t, string $act, string $label, string $cls = '', bool $confirm = false): string {
  return '<form method="post" action="/admin-center.php?m=tools">' . (function_exists('crm_csrf_field') ? crm_csrf_field() : '') . '<input type="hidden" name="act" value="tool_' . $act . '"><input type="hidden" name="slug" value="' . admt_h($t['slug']) . '"><input type="hidden" name="version" value="' . (int)$t['_version'] . '">'
    . ($confirm ? '<label class="chk" style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="confirm" value="1" required style="width:22px;min-height:22px"> Confirm</label>' : '') . '<button class="tBtn ' . $cls . '" type="submit">' . admt_h($label) . '</button></form>';
}

function admt_detail(AdmToolStore $s, array $user, string $slug, ?string $flash): string {
  $t = admt_valid_slug($slug) ? $s->get($slug) : null; $role = (string)($user['role'] ?? '');
  if ($t === null) return '<h1>Tool not found</h1><p><a class="admLink" href="' . admt_h(admt_url()) . '">Back to the tool list</a></p>';
  $t += ['pricing_type' => '', 'pricing' => '', 'website' => '', 'short' => '', 'features' => [], 'tags' => [], 'updated_at' => '', 'category' => '', 'name' => '']; $st = (string)$t['status']; $miss = admt_readiness($t);
  $o = '<p><a class="admLink" href="' . admt_h(admt_url()) . '">← All tools</a></p><div class="tHead"><h1>' . admt_h($t['name']) . ' ' . admt_chip($st) . '</h1></div>' . admt_flash($flash);
  $o .= admt_overlay_notice($ov0 = admt_overlay((string)$t['slug']), false);
  $o .= '<section class="panel" aria-labelledby="t-act"><h2 id="t-act">Actions</h2><div class="tActions">';
  if ($st !== 'deleted' && adm_can($role, 'tools')) $o .= '<a class="tBtn" href="' . admt_h(admt_url(['view' => 'edit', 'slug' => $slug])) . '">Edit</a>';
  if ($st === 'published' && adm_can($role, 'tools')) $o .= admt_action_form($t, 'unpublish', 'Unpublish');
  if (in_array($st, ['draft', 'unpublished'], true) && adm_can($role, 'tools_publish')) $o .= admt_action_form($t, 'publish', 'Publish', 'primary');
  if ($st !== 'deleted' && adm_can($role, 'tools_publish')) $o .= admt_action_form($t, 'delete', 'Move to trash', 'danger', true);
  if ($st === 'deleted' && adm_can($role, 'tools_publish')) $o .= admt_action_form($t, 'restore', 'Restore');
  if (!adm_can($role, 'tools_publish')) $o .= '<span class="small">Publishing, deleting and restoring are owner actions.</span>';
  $o .= '</div>' . ($miss && $st !== 'published' ? '<p class="warn">Not ready to publish. Missing: ' . admt_h(implode(', ', $miss)) . '.</p>' : '') . '</section>';
  $o .= '<section class="panel tDetail" aria-labelledby="t-det"><h2 id="t-det">Details</h2><dl>';
  $ov = admt_overlay((string)$t['slug']); $rows = ['Public category' => admt_h($ov['public_category'] ?? ($t['category'] !== '' ? $t['category'] : '')) . ($ov['public_subcategory'] !== '' ? ' · ' . admt_h($ov['public_subcategory']) : '') . ($ov['public_category'] === null ? ' <span class="small">(from the stored category: no editorial classification)</span>' : ' <span class="small">(editorial)</span>'), 'Database category' => admt_h($t['category']) . ($ov['public_category'] !== null && $ov['public_category'] !== $t['category'] ? ' <span class="small">(not shown to visitors)</span>' : ''), 'Editorial' => $ov['curated'] ? admt_h($ov['review']) : ($ov['classified'] ? 'Classified, no curated profile' : 'No editorial profile'), 'Address' => '<code>' . admt_h($t['slug']) . '</code>' . ($st === 'published' ? ' · <a class="admLink" href="' . admt_h('/tool.php?id=' . rawurlencode($t['slug'])) . '">view public page</a>' : ''), 'Pricing' => admt_h(trim($t['pricing_type'] . ' ' . $t['pricing'])), 'Website' => admt_h($t['website']),
    'Short description' => admt_h($t['short']), 'Features' => admt_h(implode('; ', $t['features'])), 'Tags' => admt_h(implode(', ', $t['tags'])), 'Featured' => (int)($t['featured_level'] ?? 0) > 0 ? 'Level ' . (int)$t['featured_level'] . ((string)($t['featured_until'] ?? '') !== '' ? ' until ' . admt_h($t['featured_until']) : '') : 'No', 'Last updated' => admt_h($t['updated_at'])];
  foreach ($rows as $k => $v) $o .= '<dt>' . admt_h($k) . '</dt><dd>' . ($v === '' ? '<span class="small">Not set</span>' : $v) . '</dd>';
  $o .= '</dl></section><section class="panel" aria-labelledby="t-hist"><h2 id="t-hist">History</h2>';
  $h = $s->revisions($slug);
  $o .= $h ? '<ul class="tHist">' . implode('', array_map(fn($r) => '<li><strong>' . admt_h(ucfirst($r['action'])) . '</strong> by ' . admt_h($r['changed_by']) . ' · <time datetime="' . admt_h(str_replace(' ', 'T', $r['changed_at'])) . 'Z">' . admt_h($r['changed_at']) . ' UTC</time> · #' . (int)$r['revision_no'] . ($r['fields'] ? '<br><span class="small">Changed: ' . admt_h(implode(', ', $r['fields'])) . '</span>' : '') . ($r['note'] !== '' ? '<br><span class="small">' . admt_h($r['note']) . '</span>' : '') . '</li>', $h)) . '</ul>' : '<p class="small">No changes recorded here yet. This tool came from the catalogue seed.</p>';
  return $o . '</section>';
}

function admt_list_view(AdmToolStore $s, array $user, array $get, ?string $flash): string {
  $all = $s->all(); $L = admt_list($all, $get); $role = (string)($user['role'] ?? ''); $cur = (string)($get['status'] ?? '');
  $o = '<div class="tHead"><div><h1>AI tool management</h1><p class="lead">Create, edit and review the tools shown on the site. ' . adm_n(count($all) - $L['counts']['deleted']) . ' active, ' . adm_n($L['counts']['deleted']) . ' in the trash.</p></div>' . (adm_can($role, 'tools') ? '<a class="tBtn primary" href="' . admt_h(admt_url(['view' => 'new'])) . '">New tool</a>' : '') . '</div>' . admt_flash($flash);
  $o .= '<nav aria-label="Filter by status"><ul class="tTabs">';
  $tabs = ['' => ['All active', $L['counts']['draft'] + $L['counts']['published'] + $L['counts']['unpublished']], 'published' => ['Published', $L['counts']['published']], 'draft' => ['Draft', $L['counts']['draft']], 'unpublished' => ['Unpublished', $L['counts']['unpublished']], 'deleted' => ['Trash', $L['counts']['deleted']]];
  foreach ($tabs as $k => [$lab, $n]) $o .= '<li><a href="' . admt_h(admt_url(['status' => $k] + array_diff_key($get, ['status' => 1, 'page' => 1, 'view' => 1, 'slug' => 1, 'msg' => 1, 'm' => 1]))) . '"' . ((($cur === 'all' ? '' : $cur) === $k) ? ' aria-current="true"' : '') . '>' . admt_h($lab) . ' <span class="badge">' . adm_n($n) . '</span></a></li>';
  $o .= '</ul></nav><form class="tFilter" method="get" action="/admin-center.php" role="search" aria-label="Search and filter tools"><input type="hidden" name="m" value="tools">' . ($cur !== '' ? '<input type="hidden" name="status" value="' . admt_h($cur) . '">' : '');
  $o .= '<label>Search<input type="search" name="q" value="' . admt_h($get['q'] ?? '') . '" placeholder="Name, address, tag…"></label><label>Public category<select name="category"><option value="">All categories</option>';
  foreach (admt_public_categories() as $c) $o .= '<option' . (($get['category'] ?? '') === $c ? ' selected' : '') . '>' . admt_h($c) . '</option>';
  $o .= '</select></label><label>Tag<input type="text" name="tag" value="' . admt_h($get['tag'] ?? '') . '"></label><label>Sort<select name="sort">';
  foreach (['name' => 'Name', 'updated' => 'Recently updated', 'status' => 'Status', 'category' => 'Category'] as $k => $l) $o .= '<option value="' . $k . '"' . ($L['sort'] === $k ? ' selected' : '') . '>' . $l . '</option>';
  $o .= '</select></label><label class="chk"><input type="checkbox" name="featured" value="1"' . (!empty($get['featured']) ? ' checked' : '') . '> Featured only</label><label class="chk"><input type="checkbox" name="incomplete" value="1"' . (!empty($get['incomplete']) ? ' checked' : '') . '> Missing required fields</label><button class="tBtn primary" type="submit">Apply</button></form>';
  $o .= '<p class="small" role="status">' . adm_n($L['total']) . ' tool' . ($L['total'] === 1 ? '' : 's') . ' found.</p>';
  if (!$L['rows']) return $o . '<div class="panel"><h2>No tools match</h2><p>Try clearing the search or filters.</p></div>';
  $bulk = adm_can($role, 'tools_publish');
  if ($bulk) $o .= '<form method="post" action="/admin-center.php?m=tools" aria-label="Select tools for a bulk action">' . admt_csrf() . '<input type="hidden" name="act" value="tool_bulk_review"><p class="small">Select up to ' . ADMT_BULK_MAX . ' tools on this page, choose an action at the bottom and review it before anything changes.</p>';
  $o .= '<ul class="toolCards">';
  foreach ($L['rows'] as $t) { $m = admt_readiness($t); $o .= '<li class="toolCard">' . ($bulk ? '<label class="selBox"><input type="checkbox" name="sel[]" value="' . admt_h($t['slug']) . '"><span class="sr">Select ' . admt_h($t['name']) . '</span></label><input type="hidden" name="ver[' . admt_h($t['slug']) . ']" value="' . (int)($t['_version'] ?? 0) . '">' : '') . '<h3><a href="' . admt_h(admt_url(['view' => 'detail', 'slug' => $t['slug']])) . '">' . admt_h($t['name']) . '</a></h3><div>' . admt_chip((string)$t['status']) . (admt_overlay((string)$t['slug'])['curated'] ? ' <span class="badge">Curated</span>' : '') . ((int)($t['featured_level'] ?? 0) > 0 ? ' <span class="badge">Featured</span>' : '') . '</div><div class="meta">' . admt_h($t['category'] ?: 'No category') . ' · <code>' . admt_h($t['slug']) . '</code></div>' . ($m ? '<div class="warn">Missing: ' . admt_h(implode(', ', $m)) . '</div>' : '') . '<div class="meta">Updated ' . admt_h($t['updated_at']) . '</div></li>'; }
  $o .= '</ul>';
  if ($bulk) {
    $o .= '<div class="bulkBar" role="group" aria-label="Bulk actions for the selected tools"><label>Action<select name="op">';
    foreach (ADMT_BULK_OPS as $k => $l) $o .= '<option value="' . $k . '">' . admt_h($l) . '</option>';
    $o .= '</select></label><label>Public category (for Set category)<select name="category"><option value="">Choose…</option>';
    foreach (admt_public_categories() as $c) $o .= '<option>' . admt_h($c) . '</option>';
    $o .= '</select></label><label>Tag (for Add or Remove tag)<input type="text" name="tag" maxlength="30"></label><button class="tBtn primary" type="submit">Review change</button></div></form>';
  }
  $o .= '<nav class="pager" aria-label="Pages">';
  $q = array_diff_key($get, ['page' => 1, 'view' => 1, 'slug' => 1, 'msg' => 1, 'm' => 1]);
  if ($L['page'] > 1) $o .= '<a class="tBtn" href="' . admt_h(admt_url($q + ['page' => $L['page'] - 1])) . '">Previous</a>';
  $o .= '<span>Page ' . $L['page'] . ' of ' . $L['pages'] . '</span>';
  if ($L['page'] < $L['pages']) $o .= '<a class="tBtn" href="' . admt_h(admt_url($q + ['page' => $L['page'] + 1])) . '">Next</a>';
  return $o . '</nav>';
}

/** Router for the module's pages. $form/$errs/$top carry a failed POST back to the form. */
function admt_body(AdmToolStore $s, array $user, array $get, ?array $form = null, array $errs = [], ?string $top = null): string {
  $role = (string)($user['role'] ?? ''); if (!adm_can($role, 'tools')) return '<h1>Not allowed</h1>';
  $flash = isset($get['msg']) && is_string($get['msg']) ? (admt_messages()[$get['msg']] ?? null) : null; $view = (string)($get['view'] ?? 'list'); $slug = is_string($get['slug'] ?? null) ? $get['slug'] : '';
  $nav = admt_subnav($s, $view);
  if ($view === 'new') return $nav . admt_form($s, $user, null, $form ?? [], $errs, $top);
  if ($view === 'edit') { $t = admt_valid_slug($slug) ? $s->get($slug) : null; if ($t === null || $t['status'] === 'deleted') return $nav . '<h1>Tool not found</h1><p><a class="admLink" href="' . admt_h(admt_url()) . '">Back to the tool list</a></p>'; return $nav . admt_form($s, $user, $t, $form ?? $t, $errs, $top); }
  if ($view === 'detail') return $nav . ($top ? admt_flash($top, true) : '') . admt_detail($s, $user, $slug, $flash);
  if ($view === 'categories') return $nav . admt_categories_view($s, $user, $flash, $top);
  if ($view === 'submissions') return $nav . admt_submissions_view($s, $user, $get, $flash, $top);
  if ($view === 'submission') return $nav . admt_submission_view($s, $user, (int)($get['id'] ?? 0), $form ?? [], $errs, $top);
  if ($view === 'featured') return $nav . admt_featured_view($s, $user, $flash, $top, $form ?? [], $errs);
  return $nav . ($top ? admt_flash($top, true) : '') . admt_list_view($s, $user, $get, $flash);
}

/** POST entry used by admin-center.php after the CSRF check. Writes through the store, audits, then redirects (303) or re-renders (errors, or the bulk review page). */
function admt_handle_post(AdmToolStore $s, array $user, array $post, callable $audit): array {
  $act = is_string($post['act'] ?? null) ? substr($post['act'], 5) : '';
  $r = admt_apply($s, $user, $act, $post, $audit);
  if ($r['ok'] && isset($r['review'])) return ['code' => 200, 'review' => $r['review'], 'top' => null];
  if ($r['ok']) return ['redirect' => admt_url(($r['goto'] ?? ['view' => 'detail', 'slug' => $r['slug']]) + ['msg' => $r['msg']]), 'code' => 303];
  if (isset($r['review'])) return ['code' => $r['code'], 'review' => $r['review'], 'top' => $r['msg']];
  $slug = (string)($r['slug'] ?? ''); $get = $r['get'] ?? ['view' => $act === 'create' ? 'new' : ($act === 'edit' ? 'edit' : 'detail'), 'slug' => $slug];
  $form = in_array($act, ['create', 'edit'], true) ? admt_validate($post, admt_public_categories(), $act === 'edit' ? $s->get($slug) : null, $act === 'edit' ? admt_overlay($slug)['locked'] : [])[1] : (isset($r['get']) ? $post : null);
  return ['code' => $r['code'], 'form' => $form, 'errs' => $r['errors'], 'top' => $r['errors']['_'] ?? $r['msg'], 'get' => ['m' => 'tools'] + $get];
}
/** Page body for a handle_post result that did not redirect. */
function admt_render_result(AdmToolStore $s, array $user, array $res): string {
  if (isset($res['review'])) return admt_subnav($s, 'bulk') . admt_bulk_review_view($s, $res['review'], $res['top']);
  return admt_body($s, $user, $res['get'], $res['form'], $res['errs'], $res['top']);
}

/** Honest state when the content tables or the Phase 8C migration are not installed on this server: read-only, nothing is changed. */
function admt_not_installed(): string {
  return '<h1>AI tool management</h1><div class="panel"><h2>Storage not installed</h2><p>This module needs the content tables (<code>tools</code>, <code>categories</code>) and the Phase 8C migration (<code>database/phase8c_tools.sql</code>). Until then the public site keeps using its current catalogue and nothing here can change it.</p><p class="small">The migration is applied by your administrator after the owner approves it.</p></div>';
}
