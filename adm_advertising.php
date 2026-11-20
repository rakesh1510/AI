<?php
if (isset($_SERVER["SCRIPT_FILENAME"]) && realpath($_SERVER["SCRIPT_FILENAME"]) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
// Unified Control Center, milestone U6: Advertising HUB. A READ-ONLY view that brings together records that already exist: CRM advertising campaigns (Phase 6), featured placements (Phase 8C) and
// paid listing submissions (public form). No new table, no new action, no messages, no billing. Campaign work stays in the CRM; featured placements and payment confirmation stay in Tool management.
// The approved Phase 8E to 8G roadmap (advertiser profiles, renewals, reminders, sponsored newsletter bookings, approved outbound messages) is not started and not replaced.

function admv_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function admv_url(array $q = []): string { return '/admin-center.php?' . http_build_query(['m' => 'advertising'] + $q); }
/** Views: the first two need the advertising permission (owner, sales); the last two use tool-management data and are owner only. */
function admv_views(): array { return ['overview' => ['Overview', 'advertising'], 'campaigns' => ['Campaigns', 'advertising'], 'featured' => ['Featured placements', 'tools_publish'], 'listings' => ['Paid listing submissions', 'tools_publish']]; }
function admv_view(array $get, string $role): string { $v = $get['view'] ?? 'overview'; if (!is_string($v) || !isset(admv_views()[$v])) return 'overview'; return adm_can($role, admv_views()[$v][1]) ? $v : 'overview'; }

function admv_table(string $caption, array $head, array $rows): string {
  if (!$rows) return '<p class="small">Nothing recorded yet.</p>';
  $o = '<div class="tableWrap" tabindex="0" role="region" aria-label="' . admv_h($caption) . ' (scrollable)"><table class="table"><caption class="sr">' . admv_h($caption) . '</caption><thead><tr>'; foreach ($head as $h) $o .= '<th scope="col">' . admv_h($h) . '</th>'; $o .= '</tr></thead><tbody>';
  foreach ($rows as $r) { $o .= '<tr>'; foreach ($r as $c) $o .= '<td>' . $c . '</td>'; $o .= '</tr>'; } return $o . '</tbody></table></div>';
}

/** Campaign counts by status. Synthetic (test) records are listed but never counted. */
function admv_campaign_counts(array $campaigns): array {
  $c = []; foreach ($campaigns as $g) { if (!empty($g['is_synthetic'])) continue; $s = (string)$g['status']; $c[$s] = ($c[$s] ?? 0) + 1; } return $c;
}

function admv_campaigns_table(array $campaigns, string $role): string {
  $rows = []; $labels = function_exists('crm_campaign_statuses') ? crm_campaign_statuses() : [];
  foreach ($campaigns as $g) {
    $q = ($g['quote_minor'] ?? null) !== null && function_exists('crm_money_format') ? admv_h(crm_money_format((int)$g['quote_minor'], (string)$g['quote_currency'])) . ' <span class="small">(indicative, not revenue)</span>' : '—';
    $title = adm_can($role, 'crm') && function_exists('admc_url') ? '<a href="' . admv_h(admc_url('campaign', ['id' => (int)$g['id']])) . '">' . admv_h($g['title']) . '</a>' : admv_h($g['title']);
    $rows[] = [$title . (!empty($g['is_synthetic']) ? ' <span class="badge">Synthetic test record</span>' : ''), admv_h($g['company_name'] ?? ''), admv_h($labels[$g['status']] ?? $g['status']), admv_h($g['package'] ?? ''), admv_h(($g['start_date'] ?? '') !== '' ? $g['start_date'] . ' to ' . ($g['end_date'] ?? '') : 'Not scheduled'), admv_h($g['disclosure_label'] ?? '') ?: 'Not set', $q];
  }
  return admv_table('Advertising campaigns', ['Campaign', 'Company', 'Status', 'Package', 'Dates', 'Disclosure label', 'Quote'], $rows);
}

/** $placements and $listings are null when the viewer may not see them (or tool management is not installed). */
function admv_body(array $user, array $get, array $campaigns, ?array $placements, ?array $listings): string {
  $role = (string)$user['role']; $view = admv_view($get, $role); $nav = '<nav aria-label="Advertising sections" class="crmNav">';
  foreach (admv_views() as $k => [$l, $perm]) if (adm_can($role, $perm)) $nav .= '<a class="btn sm' . ($k === $view ? '' : ' secondary') . '" href="' . admv_h(admv_url(['view' => $k])) . '"' . ($k === $view ? ' aria-current="page"' : '') . '>' . admv_h($l) . '</a>';
  $nav .= '</nav>'; $o = '<div class="admCrm admAdv"><h1>Advertising</h1><p class="small">A read-only overview of advertising records that already exist. Nothing here books, bills, emails or publishes. Edit campaigns in the CRM and featured placements in Tool management.</p>' . $nav;
  if ($view === 'campaigns') return $o . '<h2>Campaigns</h2>' . admv_campaigns_table($campaigns, $role) . '</div>';
  if ($view === 'featured') {
    if ($placements === null) return $o . '<h2>Featured placements</h2><p class="noteBox">Featured placements are not available (tool management is not installed on this server).</p></div>';
    $rows = []; foreach ($placements as $p) $rows[] = [admv_h($p['tool_slug']), 'Level ' . (int)$p['level'], admv_h($p['starts_on'] . ' to ' . $p['ends_on']), admv_h($p['source']), admv_h(ucfirst((string)$p['status'])), admv_h($p['note'] ?? '')];
    return $o . '<h2>Featured placements</h2><p class="small">Placement history and schedule from Tool management. <a href="' . admv_h('/admin-center.php?m=tools&view=featured') . '">Manage placements</a></p>' . admv_table('Featured placements', ['Tool', 'Level', 'Dates', 'Source', 'Status', 'Note'], $rows) . '</div>';
  }
  if ($view === 'listings') {
    if ($listings === null) return $o . '<h2>Paid listing submissions</h2><p class="noteBox">Submissions are not available (tool management is not installed on this server).</p></div>';
    $rows = []; foreach ($listings as $s) $rows[] = ['<a href="' . admv_h('/admin-center.php?m=tools&view=submission&id=' . (int)$s['id']) . '">' . admv_h($s['name']) . '</a>', admv_h($s['plan']), admv_h((int)$s['price'] . ' ' . $s['currency']), admv_h($s['payment_status'] === 'paid' ? 'Paid (recorded manually, not bank-verified)' : ($s['payment_status'] === 'unpaid' ? 'Unpaid' : $s['payment_status'])), admv_h(ucfirst((string)$s['status'])), admv_h($s['created_at'])];
    return $o . '<h2>Paid listing submissions</h2><p class="small">Submissions with a price. Payment is confirmed by the owner in Tool management. Amounts are in whole currency units as submitted.</p>' . admv_table('Paid listing submissions', ['Submission', 'Plan', 'Price', 'Payment', 'Status', 'Received'], $rows) . '</div>';
  }
  $cnt = admv_campaign_counts($campaigns); $labels = function_exists('crm_campaign_statuses') ? crm_campaign_statuses() : []; $tiles = '';
  foreach (['inquiry', 'in_review', 'approved', 'scheduled', 'live', 'completed'] as $st) $tiles .= '<div class="card"><strong>' . (int)($cnt[$st] ?? 0) . '</strong><div class="small">' . admv_h($labels[$st] ?? $st) . '</div></div>';
  $o .= '<h2>Campaigns by status</h2><div class="cardGrid">' . $tiles . '</div><p class="small">Counts exclude synthetic test records. Quotes are indicative and are not revenue; recorded income lives in Finance.</p>';
  if ($placements !== null) { $act = array_filter($placements, fn($p) => ($p['status'] ?? '') === 'active'); $o .= '<h2>Featured placements</h2><p>' . count($act) . ' active, ' . count($placements) . ' in the history. <a href="' . admv_h(admv_url(['view' => 'featured'])) . '">See placements</a></p>'; }
  if ($listings !== null) { $unp = array_filter($listings, fn($s) => ($s['payment_status'] ?? '') === 'unpaid' && ($s['status'] ?? '') === 'pending'); $o .= '<h2>Paid listing submissions</h2><p>' . count($listings) . ' with a price; ' . count($unp) . ' pending and unpaid. <a href="' . admv_h(admv_url(['view' => 'listings'])) . '">See submissions</a></p>'; }
  $o .= '<h2>Not built yet</h2><p class="small">Advertiser profiles, renewal reminders, sponsored newsletter bookings and approved outbound messages are part of the approved Phase 8E to 8G roadmap and have not been started.</p>';
  return $o . '</div>';
}
