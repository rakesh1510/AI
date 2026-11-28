<?php
if (isset($_SERVER["SCRIPT_FILENAME"]) && realpath($_SERVER["SCRIPT_FILENAME"]) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
// Unified Admin Control Center, U1: navigation registry, role filtering, breadcrumbs and the environment banner.
// The registry decides what to SHOW. Every module still enforces its own permission on every request, so a hidden link is never the control.
// Labels and addresses come from adm_modules() (single source); this file only groups them and lists each module's sub-views.

/** Groups in display order. A module appears only when the role holds its permission; a group with no visible module is hidden. 'children' are the module's own views. */
function adm_nav_groups(): array {
  $v = fn(string $label, string $param, string $value, ?string $perm = null) => ['label' => $label, 'param' => $param, 'value' => $value, 'perm' => $perm];
  return [
    ['id' => 'overview', 'label' => 'Overview', 'items' => [['mod' => 'dashboard']]],
    ['id' => 'sales', 'label' => 'Sales and customers', 'items' => [['mod' => 'crm', 'children' => [
      $v('Dashboard', 'v', 'dashboard'), $v('Leads', 'v', 'leads'), $v('Pipeline', 'v', 'opportunities'), $v('Companies', 'v', 'companies'), $v('Proposals', 'v', 'proposals'), $v('Contracts', 'v', 'contracts'),
      $v('Tasks', 'v', 'tasks'), $v('Campaigns', 'v', 'campaigns'), $v('Partners', 'v', 'partners'), $v('Prospects', 'v', 'prospects'), $v('Outreach drafts', 'v', 'outreach')]]]],
    ['id' => 'content', 'label' => 'Content and catalogue', 'items' => [
      ['mod' => 'tools', 'children' => [$v('Tools', 'view', ''), $v('Categories and tags', 'view', 'categories'), $v('Submissions', 'view', 'submissions'), $v('Featured', 'view', 'featured')]],
      ['mod' => 'universe'], ['mod' => 'editorial'], ['mod' => 'legacy']]],
    ['id' => 'growth', 'label' => 'Growth', 'items' => [['mod' => 'analytics', 'children' => [$v('Overview', 'view', ''), $v('Visitors and history', 'view', 'visitors'), $v('Pages', 'view', 'pages'), $v('Sources, devices, countries', 'view', 'sources'), $v('AI tool clicks', 'view', 'tools'), $v('Categories', 'view', 'categories'), $v('Featured placements', 'view', 'placements'), $v('Legacy click records', 'view', 'legacy'), $v('Exports', 'view', 'exports')]], ['mod' => 'advertising', 'children' => [$v('Overview', 'view', ''), $v('Campaigns', 'view', 'campaigns'), $v('Featured placements', 'view', 'featured', 'tools_publish'), $v('Paid listing submissions', 'view', 'listings', 'tools_publish'), $v('Revenue dashboard', 'view', 'revenue'), $v('Bookings', 'view', 'bookings'), $v('Packages and inventory', 'view', 'packages'), $v('Advertisers', 'view', 'advertisers'), $v('Sales opportunities', 'view', 'opportunities')]]]],
    ['id' => 'money', 'label' => 'Money', 'items' => [['mod' => 'finance', 'children' => [
      $v('Overview', 'view', ''), $v('Revenue', 'view', 'revenue'), $v('Advertising and sponsorship', 'view', 'advertising'), $v('Projects and retainers', 'view', 'projects'),
      $v('Expenses and profit', 'view', 'expenses'), $v('Refunds and credits', 'view', 'refunds'), $v('Targets', 'view', 'targets'), $v('Exports', 'view', 'exports')]]]],
    ['id' => 'ops', 'label' => 'Operations', 'items' => [['mod' => 'operations'], ['mod' => 'performance'], ['mod' => 'access', 'children' => [$v('Users and roles', 'view', ''), $v('Audit log', 'view', 'audit'), $v('System health', 'view', 'health')]]]],
    ['id' => 'account', 'label' => 'Account', 'items' => [['mod' => 'account']]],
  ];
}

/** Address of a module or of one of its views. Sub-views of in-shell modules stay inside /admin-center.php; link-out modules keep their existing URL. */
function adm_nav_href(array $m, string $id, ?array $child = null): string {
  $base = (string)($m['href'] ?? '/admin-center.php?m=' . $id);
  if ($child === null || ($child['value'] ?? '') === '') return $base;
  return $base . (strpos($base, '?') !== false ? '&' : '?') . rawurlencode($child['param']) . '=' . rawurlencode($child['value']);
}

/** The navigation visible to one role, with the active module and view marked. Pure: no output. */
function adm_nav_tree(string $role, string $current = '', array $get = []): array {
  $mods = adm_modules(); $out = [];
  foreach (adm_nav_groups() as $g) {
    $items = [];
    foreach ($g['items'] as $it) {
      $id = $it['mod']; $m = $mods[$id] ?? null; if ($m === null || !adm_can($role, $m['perm'])) continue;
      $planned = empty($m['live']); $children = [];
      if ($id === $current) foreach (($it['children'] ?? []) as $c) {
        if (($c['perm'] ?? null) !== null && !adm_can($role, $c['perm'])) continue;
        $val = (string)($get[$c['param']] ?? ''); $active = ($c['value'] === '' ? ($val === '' || !adm_nav_child_exists($it['children'], $c['param'], $val)) : $val === $c['value']);
        $children[] = ['label' => $c['label'], 'href' => adm_nav_href($m, $id, $c), 'active' => $active, 'value' => $c['value']];
      }
      $items[] = ['id' => $id, 'label' => (string)$m['label'], 'href' => $planned ? '/admin-center.php?m=' . $id : adm_nav_href($m, $id), 'active' => $id === $current, 'planned' => $planned, 'phase' => (string)($m['phase'] ?? ''), 'external' => !$planned && empty($m['internal']) && $id !== 'dashboard', 'children' => $children];
    }
    if ($items) $out[] = ['id' => $g['id'], 'label' => $g['label'], 'items' => $items, 'active' => (bool)array_filter($items, fn($i) => $i['active'])];
  }
  return $out;
}
function adm_nav_child_exists(array $children, string $param, string $val): bool { foreach ($children as $c) if ($c['param'] === $param && $c['value'] === $val) return true; return false; }

/** Breadcrumb trail: Control Center, group, module, and the current view when it has a label. Items without 'href' are the current page. */
function adm_breadcrumbs(string $current, array $get, string $role): array {
  $trail = [['label' => 'Control Center', 'href' => '/admin-center.php']]; if ($current === 'dashboard' || $current === '') { $trail[0] = ['label' => 'Control Center']; return $trail; }
  foreach (adm_nav_tree($role, $current, $get) as $g) foreach ($g['items'] as $i) if ($i['id'] === $current) {
    $trail[] = ['label' => $g['label']]; $active = null; foreach ($i['children'] as $c) if ($c['active'] && $c['value'] !== '') $active = $c;
    $trail[] = $active ? ['label' => $i['label'], 'href' => $i['href']] : ['label' => $i['label']]; if ($active) $trail[] = ['label' => $active['label']]; return $trail;
  }
  return $trail;
}

/** Environment banner: staging amber, production red (owner decision D8). Anything else is shown as "not set" so nobody assumes which server they are on. */
function adm_env(): array {
  $raw = strtolower(function_exists('sec_setting') ? sec_setting('AIHUB_ENV_LABEL', 'env_label') : '');
  $map = ['staging' => ['STAGING', 'This is the staging server. Use test data only.'], 'production' => ['PRODUCTION', 'This is the live site. Changes affect real data and real visitors.'], 'development' => ['DEVELOPMENT', 'This is a development environment.'], 'local' => ['LOCAL', 'This is a local environment.']];
  if (isset($map[$raw])) return ['key' => $raw, 'label' => $map[$raw][0], 'text' => $map[$raw][1]];
  return ['key' => 'unset', 'label' => 'ENVIRONMENT NOT SET', 'text' => 'The server does not say whether it is staging or production. Ask the administrator to set AIHUB_ENV_LABEL.'];
}
function adm_env_html(): string { $e = adm_env(); return '<div class="admEnv admEnv-' . adm_h($e['key']) . '" role="note"><strong>' . adm_h($e['label']) . '</strong><span>' . adm_h($e['text']) . '</span></div>'; }

function adm_nav_html(array $tree): string {
  $o = '';
  foreach ($tree as $g) {
    $o .= '<details class="admGroup" data-group="' . adm_h($g['id']) . '" open><summary>' . adm_h($g['label']) . '</summary><ul>';
    foreach ($g['items'] as $i) {
      $o .= '<li class="admItem' . ($i['active'] ? ' is-active' : '') . '"><a href="' . adm_h($i['href']) . '"' . ($i['active'] && !array_filter($i['children'], fn($c) => $c['active'] && $c['value'] !== '') ? ' aria-current="page"' : '') . '>' . adm_h($i['label']) . ($i['planned'] ? ' <span class="badge">Phase ' . adm_h($i['phase']) . '</span>' : '') . '</a>';
      if ($i['children']) { $o .= '<ul class="admSub" aria-label="' . adm_h($i['label']) . ' views">'; foreach ($i['children'] as $c) $o .= '<li><a href="' . adm_h($c['href']) . '"' . ($c['active'] ? ' aria-current="page"' : '') . '>' . adm_h($c['label']) . '</a></li>'; $o .= '</ul>'; }
      $o .= '</li>';
    }
    $o .= '</ul></details>';
  }
  return $o;
}
function adm_crumbs_html(array $trail): string {
  $o = '<nav class="admCrumbs" aria-label="Breadcrumb"><ol>'; $n = count($trail);
  foreach ($trail as $k => $t) { $last = $k === $n - 1; $o .= '<li>' . (!$last && isset($t['href']) ? '<a href="' . adm_h($t['href']) . '">' . adm_h($t['label']) . '</a>' : '<span' . ($last ? ' aria-current="page"' : '') . '>' . adm_h($t['label']) . '</span>') . '</li>'; }
  return $o . '</ol></nav>';
}
