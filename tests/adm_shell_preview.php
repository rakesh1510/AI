<?php
// Writes static HTML of the unified Control Center shell from SYNTHETIC data (no database, no server) for accessibility, behaviour and screenshot checks.
// Usage: php tests/adm_shell_preview.php OUT_DIR
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__); $out = rtrim($argv[1] ?? '', '/'); if ($out === '') { fwrite(STDERR, "usage: php tests/adm_shell_preview.php OUT_DIR\n"); exit(2); }
@mkdir($out, 0775, true); chdir($root); ob_start(); require_once $root . '/helpers.php'; require_once $root . '/crm_views.php'; require_once $root . '/adm_core.php'; require_once $root . '/adm_crm.php'; require_once $root . '/adm_tools_views.php'; require_once $root . '/adm_finance_views.php'; require_once $root . '/adm_auth.php'; require_once $root . '/adm_account.php'; ob_end_clean();
$u = fn(string $role, string $name) => ['id' => 1, 'username' => strtolower($role) . '-demo', 'display_name' => $name, 'role' => $role];
$tiles = [adm_tile('New inquiries, last 30 days', null, 'No inquiries recorded (preview).', 'none'), adm_tile('Open opportunities', null, 'No opportunities recorded (preview).', 'none'), adm_tile('Verified entities', null, 'Nothing verified yet (preview).', 'none'), adm_tile('Follow-ups overdue', null, 'No tasks (preview).', 'none')];
$overview = '<h1>Overview</h1><p class="lead">Preview of the unified shell with synthetic content.</p>' . adm_tiles_html($tiles) . '<section class="panel"><h2>Needs attention</h2><p>Nothing needs attention in this preview.</p></section>';
$render = function (array $user, string $cur, string $body, array $get = []) { $_GET = $get; ob_start(); @adm_page($user, $cur, 'Preview', $body); $_GET = []; $h = ob_get_clean(); return $h; };
$pages = [];
putenv('AIHUB_ENV_LABEL=staging'); $pages['shell-owner-staging'] = $render($u('owner', 'Olivia Owner'), 'dashboard', $overview);
putenv('AIHUB_ENV_LABEL=production'); $pages['shell-owner-production'] = $render($u('owner', 'Olivia Owner'), 'dashboard', $overview);
putenv('AIHUB_ENV_LABEL=staging');
$pages['shell-owner-finance'] = $render($u('owner', 'Olivia Owner'), 'finance', '<h1>Finance</h1><p class="lead">Preview body (the real Finance screens are tested separately).</p>', ['view' => 'revenue']);
$pages['shell-editor-tools'] = $render($u('editor', 'Edith Editor'), 'tools', '<h1>AI tool management</h1><p class="lead">Preview body.</p>', ['view' => 'submissions']);
$pages['shell-verifier'] = $render($u('verifier', 'Verifier One'), 'dashboard', $overview);
$pages['shell-viewer'] = $render($u('viewer', 'Vik Viewer'), 'dashboard', $overview);
$_GET = []; $pages['shell-account-owner'] = $render($u('owner', 'Olivia Owner'), 'account', adm_account_body($u('owner', 'Olivia Owner')), []);
$pages['shell-account-errors'] = $render($u('editor', 'Edith Editor'), 'account', adm_account_body($u('editor', 'Edith Editor'), [], ['current' => 'The current password is not correct.', 'new' => 'Use at least 12 characters. A long phrase of several words works well.'], 'Please correct the highlighted fields.'), []);
$crmU = $u('sales', 'Sam Sales'); $leadsInner = '<h2>Leads</h2><form method="get" action="/crm.php" class="crmForm"><input type="hidden" name="v" value="leads">' . crm_f_input('q', 'Search', '') . crm_f_select('kind', 'Kind', ['' => 'All', 'inquiry' => 'Inquiry'], '') . '<button class="btn sm">Search</button></form>'
  . crm_tbl('Leads', ['Name', 'Company', 'Status', 'Created'], [[crm_link('lead', 1, 'Preview Person'), 'Example Ltd', '<span class="badge gray">new</span>', '2026-11-01'], [crm_link('lead', 2, 'Second Person'), 'Sample GmbH', '<span class="badge gray">contacted</span>', '2026-11-02']]);
$leadInner = '<h2>Lead 1</h2><div class="panel"><p>Preview Person, Example Ltd</p></div>' . crm_form('lead_note', ['id' => 1], crm_f_input('note', 'Note', ''), 'Add note');
$pages['shell-crm-leads-sales'] = $render($crmU, 'crm', admc_compose('leads', $crmU, '', $leadsInner), ['m' => 'crm', 'v' => 'leads']);
$pages['shell-crm-lead-owner'] = $render($u('owner', 'Olivia Owner'), 'crm', admc_compose('lead', $u('owner', 'Olivia Owner'), '<div class="noteBox" role="status">Saved.</div>', $leadInner), ['m' => 'crm', 'v' => 'lead']);
foreach (['signin' => adm_signin_html('', 'preview'), 'signin-error' => adm_signin_html('Sign-in failed. Check the username and password.', 'preview')] as $k => $inner) { $page = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><title>Sign in | AIHubpedia Control Center</title><style>' . adm_css() . '</style></head><body><a class="skip" href="#main">Skip to content</a>' . adm_env_html() . '<main id="main" class="admAuth" tabindex="-1"><p class="admBrand" style="margin:0 0 10px">AIHubpedia Control Center</p><div class="panel">' . $inner . '</div></main></body></html>'; $pages[$k] = $page; }
foreach ($pages as $name => $html) { $html = preg_replace('#<input type="hidden" name="_csrf" value="[^"]*">#', '<input type="hidden" name="_csrf" value="preview">', $html); $html = str_replace('src="/assets/js/admin-shell.js"', 'src="file://' . $root . '/assets/js/admin-shell.js"', $html); file_put_contents("$out/$name.html", "<!-- synthetic preview, not live data -->\n" . $html); }
echo count($pages) . " preview pages written to $out\n";
