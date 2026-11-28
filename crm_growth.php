<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
/**
 * Advertising campaigns, partnerships and business development (Phase 6). INTERNAL WORKFLOWS ONLY.
 *  - Campaign records, review and status; they never change organic rankings, evidence levels or recommendations (a test enforces that the
 *    ranking code does not read these tables).
 *  - Partners are shown publicly only when a person verified them with an evidence URL; an unverified company is never shown as a partner.
 *  - Prospects, outreach drafts and consent: drafts only. There is no code in this release that sends outreach, and no "sent" status.
 */
require_once __DIR__ . '/crm_money.php';

// ---- advertising campaigns -----------------------------------------------------------------------------------------------------------
function crm_campaign_statuses(): array { return ['inquiry' => 'Inquiry', 'in_review' => 'In review', 'approved' => 'Approved', 'scheduled' => 'Scheduled', 'live' => 'Live', 'completed' => 'Completed', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled']; }
function crm_campaign_transitions(): array { return ['inquiry' => ['in_review', 'rejected', 'cancelled'], 'in_review' => ['approved', 'rejected', 'cancelled'], 'approved' => ['scheduled', 'cancelled'], 'scheduled' => ['live', 'cancelled'], 'live' => ['completed', 'cancelled']]; }
function crm_disclosure_labels(): array { return ['Sponsored', 'Advertisement', 'Partner content']; }
function crm_campaign_packages(): array { return ['featured_placement' => 'Featured tool placement', 'category_sponsorship' => 'Category sponsorship', 'newsletter_sponsorship' => 'Newsletter sponsorship', 'custom_campaign' => 'Custom campaign', 'listing' => 'Standard or priority listing']; }
function crm_campaign_create(PDO $pdo, ?array $user, int $companyId, string $package, string $title, ?int $leadId = null, ?int $contactId = null, bool $synthetic = false): array {
  if ($user !== null && crm_deny($user, 'write')) return [false, 'forbidden'];
  if (!crm_company_get($pdo, $companyId)) return [false, 'company_invalid']; if (!isset(crm_campaign_packages()[$package])) return [false, 'package_invalid'];
  $title = crm_line($title, 190); if ($title === '') $title = crm_campaign_packages()[$package];
  $now = crm_now(); $pdo->prepare("INSERT INTO crm_campaigns (company_id, lead_id, contact_id, package, title, status, created_at, updated_at, is_synthetic) VALUES (?,?,?,?,?,'inquiry',?,?,?)")->execute([$companyId, $leadId, $contactId, $package, $title, $now, $now, $synthetic ? 1 : 0]);
  $id = (int)$pdo->lastInsertId(); crm_audit($pdo, $user, 'campaign_create', 'campaign', $id, $package); return [true, (string)$id];
}
function crm_campaign_get(PDO $pdo, int $id): ?array { $s = $pdo->prepare('SELECT g.*, c.name AS company_name, u.display_name AS reviewer FROM crm_campaigns g LEFT JOIN crm_companies c ON c.id = g.company_id LEFT JOIN crm_users u ON u.id = g.reviewed_by WHERE g.id = ?'); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC) ?: null; }
function crm_campaigns_list(PDO $pdo, string $status = '', int $limit = 200): array {
  $s = $pdo->prepare('SELECT g.*, c.name AS company_name FROM crm_campaigns g LEFT JOIN crm_companies c ON c.id = g.company_id' . ($status !== '' && isset(crm_campaign_statuses()[$status]) ? ' WHERE g.status = ?' : '') . ' ORDER BY g.id DESC LIMIT ' . max(1, min(500, $limit)));
  $s->execute($status !== '' && isset(crm_campaign_statuses()[$status]) ? [$status] : []); return $s->fetchAll(PDO::FETCH_ASSOC);
}
/** Review details stored before approval: disclosure label, landing URL, dates, optional internal indicative quote (never published). */
function crm_campaign_update(PDO $pdo, ?array $user, int $id, array $f): array {
  if (crm_deny($user, 'write')) return [false, 'forbidden']; $g = crm_campaign_get($pdo, $id); if (!$g) return [false, 'not_found'];
  if (in_array($g['status'], ['completed', 'rejected', 'cancelled'], true)) return [false, 'closed'];
  $label = (string)($f['disclosure_label'] ?? $g['disclosure_label']); if ($label !== '' && !in_array($label, crm_disclosure_labels(), true)) return [false, 'label_invalid'];
  $url = crm_text($f['landing_url'] ?? $g['landing_url'], 255); if ($url !== '' && sec_safe_external_url($url) === null) return [false, 'landing_url_invalid'];
  $dates = []; foreach (['start_date', 'end_date'] as $k) { $v = (string)($f[$k] ?? $g[$k] ?? ''); if ($v === '') { $dates[$k] = null; continue; } $t = crm_date($v); if ($t === null) return [false, $k . '_invalid']; $dates[$k] = gmdate('Y-m-d', $t); }
  if ($dates['start_date'] && $dates['end_date'] && $dates['end_date'] < $dates['start_date']) return [false, 'dates_invalid'];
  $qm = null; $qc = null; if (($f['quote'] ?? '') !== '') { $qm = crm_money_parse((string)$f['quote']); $qc = (string)($f['quote_currency'] ?? 'EUR'); if ($qm === null || !crm_currency_ok($qc)) return [false, 'quote_invalid']; }
  $pdo->prepare('UPDATE crm_campaigns SET disclosure_label = ?, landing_url = ?, start_date = ?, end_date = ?, placement_notes = ?, quote_minor = ?, quote_currency = ?, title = ?, updated_at = ? WHERE id = ?')
      ->execute([$label, $url, $dates['start_date'], $dates['end_date'], crm_text($f['placement_notes'] ?? $g['placement_notes'], 2000), $qm, $qc, crm_line($f['title'] ?? $g['title'], 190) ?: $g['title'], crm_now(), $id]);
  crm_audit($pdo, $user, 'campaign_update', 'campaign', $id, 'details updated'); return [true, 'ok'];
}
function crm_campaign_move(PDO $pdo, ?array $user, int $id, string $to, string $reason = ''): array {
  $g = crm_campaign_get($pdo, $id); if (!$g) return [false, 'not_found'];
  if (!in_array($to, crm_campaign_transitions()[$g['status']] ?? [], true)) return [false, 'transition_invalid'];
  if (in_array($to, ['approved', 'rejected'], true) ? crm_deny($user, 'approve') : crm_deny($user, 'write')) return [false, 'forbidden'];
  $reason = crm_line($reason, 190);
  if ($to === 'rejected' && $reason === '') return [false, 'reason_required'];
  if ($to === 'approved') {   // approval gate: transparent labelling and a safe destination are mandatory
    if (!in_array($g['disclosure_label'], crm_disclosure_labels(), true)) return [false, 'disclosure_label_required'];
    if ($g['landing_url'] === '' || sec_safe_external_url($g['landing_url']) === null) return [false, 'landing_url_required'];
  }
  if ($to === 'scheduled' && (empty($g['start_date']) || empty($g['end_date']))) return [false, 'dates_required'];
  $code = $g['attribution_code'];
  if ($to === 'approved' && !$code) { $code = 'c' . $id . '-' . substr(crm_slug($g['company_name'] ?? 'x', 20), 0, 20); $code = rtrim($code, '-.'); }
  $pdo->prepare('UPDATE crm_campaigns SET status = ?, reviewed_by = ?, reviewed_at = ?, reject_reason = ?, attribution_code = ?, updated_at = ? WHERE id = ?')
      ->execute([$to, in_array($to, ['approved', 'rejected'], true) ? ($user['id'] ?? null) : $g['reviewed_by'], in_array($to, ['approved', 'rejected'], true) ? crm_now() : $g['reviewed_at'], $to === 'rejected' ? $reason : $g['reject_reason'], $code, crm_now(), $id]);
  crm_activity_add($pdo, $user, 'campaign', $id, 'system', crm_campaign_statuses()[$g['status']] . ' → ' . crm_campaign_statuses()[$to] . ($reason !== '' ? ': ' . $reason : ''));
  crm_audit($pdo, $user, 'campaign_' . $to, 'campaign', $id, $g['status'] . ' → ' . $to); return [true, 'ok'];
}
/** Reporting definitions shown to advertisers and used by the dashboard. Counts only; no rates promised. */
function crm_reporting_definitions(): array {
  return [
    'inquiry' => 'A form submission recorded in the CRM. It is not a sale.',
    'attributed_lead' => 'A CRM lead whose landing URL carried this campaign’s attribution code (utm_campaign). Codes are slugs, never personal data.',
    'outbound_click' => 'A visit to a tool’s outbound link counted by AIHubpedia’s own redirect, rate-limited per network. Not a conversion, not a sign-up.',
    'newsletter_click' => 'Defined only once a newsletter has been launched; none exists today, so there is nothing to report.',
    'impression' => 'Not measured: analytics is off by default, so no impression or audience figure is reported.',
  ];
}
function crm_campaign_report(PDO $pdo, int $id): ?array {
  $g = crm_campaign_get($pdo, $id); if (!$g) return null; $n = 0;
  if ($g['attribution_code']) { $s = $pdo->prepare('SELECT COUNT(*) FROM crm_leads WHERE attr_campaign = ?'); $s->execute([$g['attribution_code']]); $n = (int)$s->fetchColumn(); }
  return ['campaign_id' => $id, 'status' => $g['status'], 'attribution_code' => $g['attribution_code'], 'attributed_leads' => $n, 'definitions' => crm_reporting_definitions()];
}
function crm_campaign_disclosure_html(array $g): string { $l = in_array($g['disclosure_label'] ?? '', crm_disclosure_labels(), true) ? $g['disclosure_label'] : 'Sponsored'; return '<span class="badge sponsoredLabel">' . htmlspecialchars($l, ENT_QUOTES) . '</span>'; }

// ---- partnerships --------------------------------------------------------------------------------------------------------------------
function crm_partner_categories(): array { return ['ai_tool_vendor' => 'AI tool vendor', 'ai_startup' => 'AI startup', 'saas' => 'SaaS company', 'affiliate' => 'Affiliate partner', 'technology_provider' => 'Technology provider', 'implementation_agency' => 'Implementation agency', 'enterprise' => 'Enterprise partner']; }
function crm_partner_statuses(): array { return ['applied' => 'Applied', 'in_review' => 'In review', 'approved' => 'Approved', 'rejected' => 'Rejected', 'suspended' => 'Suspended']; }
function crm_partner_transitions(): array { return ['applied' => ['in_review', 'rejected'], 'in_review' => ['approved', 'rejected'], 'approved' => ['suspended'], 'suspended' => ['approved', 'rejected'], 'rejected' => ['in_review']]; }
function crm_partner_create(PDO $pdo, ?array $user, int $companyId, string $category, string $proposal, ?int $contactId = null, ?int $leadId = null, bool $synthetic = false): array {
  if ($user !== null && crm_deny($user, 'write')) return [false, 'forbidden']; if (!crm_company_get($pdo, $companyId)) return [false, 'company_invalid']; if (!isset(crm_partner_categories()[$category])) return [false, 'category_invalid'];
  $now = crm_now(); $pdo->prepare("INSERT INTO crm_partners (company_id, contact_id, lead_id, category, status, proposal, public_listing, created_at, updated_at, is_synthetic) VALUES (?,?,?,?,'applied',?,'hidden',?,?,?)")->execute([$companyId, $contactId, $leadId, $category, crm_text($proposal, 4000), $now, $now, $synthetic ? 1 : 0]);
  $id = (int)$pdo->lastInsertId(); crm_audit($pdo, $user, 'partner_create', 'partner', $id, $category); return [true, (string)$id];
}
function crm_partner_get(PDO $pdo, int $id): ?array { $s = $pdo->prepare('SELECT p.*, c.name AS company_name, c.website FROM crm_partners p LEFT JOIN crm_companies c ON c.id = p.company_id WHERE p.id = ?'); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC) ?: null; }
function crm_partners_list(PDO $pdo, string $status = '', int $limit = 200): array {
  $ok = $status !== '' && isset(crm_partner_statuses()[$status]); $s = $pdo->prepare('SELECT p.*, c.name AS company_name FROM crm_partners p LEFT JOIN crm_companies c ON c.id = p.company_id' . ($ok ? ' WHERE p.status = ?' : '') . ' ORDER BY p.id DESC LIMIT ' . max(1, min(500, $limit)));
  $s->execute($ok ? [$status] : []); return $s->fetchAll(PDO::FETCH_ASSOC);
}
function crm_partner_move(PDO $pdo, ?array $user, int $id, string $to, string $note = ''): array {
  $p = crm_partner_get($pdo, $id); if (!$p) return [false, 'not_found']; if (!in_array($to, crm_partner_transitions()[$p['status']] ?? [], true)) return [false, 'transition_invalid'];
  if (in_array($to, ['approved', 'rejected', 'suspended'], true) ? crm_deny($user, 'approve') : crm_deny($user, 'write')) return [false, 'forbidden'];
  $note = crm_line($note, 190); if (in_array($to, ['rejected', 'suspended'], true) && $note === '') return [false, 'reason_required'];
  $listing = in_array($to, ['rejected', 'suspended'], true) ? 'hidden' : $p['public_listing'];   // leaving "approved" always withdraws the public listing
  $pdo->prepare('UPDATE crm_partners SET status = ?, public_listing = ?, updated_at = ? WHERE id = ?')->execute([$to, $listing, crm_now(), $id]);
  crm_activity_add($pdo, $user, 'partner', $id, 'system', crm_partner_statuses()[$p['status']] . ' → ' . crm_partner_statuses()[$to] . ($note !== '' ? ': ' . $note : '')); crm_audit($pdo, $user, 'partner_' . $to, 'partner', $id, $p['status'] . ' → ' . $to); return [true, 'ok'];
}
/** Public listing needs: approved status, an https evidence URL, and a person with approval rights attesting the relationship is real. */
function crm_partner_publish(PDO $pdo, ?array $user, int $id, string $evidenceUrl): array {
  if (crm_deny($user, 'approve')) return [false, 'forbidden']; $p = crm_partner_get($pdo, $id); if (!$p) return [false, 'not_found']; if ($p['status'] !== 'approved') return [false, 'partner_not_approved'];
  $u = sec_safe_external_url(trim($evidenceUrl)); if ($u === null || strpos($u, 'https://') !== 0) return [false, 'evidence_url_required'];
  $pdo->prepare("UPDATE crm_partners SET public_listing = 'listed', verified_at = ?, verified_by = ?, evidence_url = ?, updated_at = ? WHERE id = ?")->execute([crm_now(), $user['id'] ?? null, $u, crm_now(), $id]);
  crm_audit($pdo, $user, 'partner_publish', 'partner', $id, 'public listing enabled'); return [true, 'ok'];
}
function crm_partner_unpublish(PDO $pdo, ?array $user, int $id): array { if (crm_deny($user, 'approve')) return [false, 'forbidden']; $pdo->prepare("UPDATE crm_partners SET public_listing = 'hidden', updated_at = ? WHERE id = ?")->execute([crm_now(), $id]); crm_audit($pdo, $user, 'partner_unpublish', 'partner', $id, 'public listing withdrawn'); return [true, 'ok']; }
/** What the public page may show: verified, approved, explicitly listed partners only. */
function crm_partners_public(PDO $pdo): array {
  return $pdo->query("SELECT p.id, p.category, p.evidence_url, p.verified_at, c.name AS company_name, c.website FROM crm_partners p JOIN crm_companies c ON c.id = p.company_id WHERE p.status = 'approved' AND p.public_listing = 'listed' AND p.verified_at IS NOT NULL AND p.evidence_url <> '' AND p.is_synthetic = 0 ORDER BY c.name")->fetchAll(PDO::FETCH_ASSOC);
}

// ---- business development: prospects, outreach drafts, consent -----------------------------------------------------------------------
function crm_prospect_fit_statuses(): array { return ['unqualified' => 'Unqualified', 'fit' => 'Good fit', 'not_fit' => 'Not a fit', 'archived' => 'Archived']; }
function crm_contains_personal_contact(string $s): bool { $s = preg_replace('/\b\d{4}-\d{2}-\d{2}\b|\b\d{1,2}\.\d{1,2}\.(\d{4}|\d{2})\b/', ' ', $s) ?? $s; return (bool)preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}|(\+?\d[\d\s().-]{8,}\d)/', $s); }
/** Company-level research only. Emails and phone numbers are refused so private personal data is not collected into research notes. */
function crm_prospect_add(PDO $pdo, ?array $user, string $company, string $website, string $kind, string $notes, string $sourceUrl = '', bool $synthetic = false): array {
  if (crm_deny($user, 'write')) return [false, 'forbidden']; $company = crm_line($company, 190); if ($company === '') return [false, 'company_required']; if (!isset(crm_lead_kinds()[$kind])) return [false, 'kind_invalid'];
  if (crm_contains_personal_contact($notes) || crm_contains_personal_contact($company)) return [false, 'personal_data_in_notes'];
  $su = trim($sourceUrl); if ($su !== '' && sec_safe_external_url($su) === null) return [false, 'source_url_invalid']; $web = crm_text($website, 255); if ($web !== '' && crm_domain($web) === '') return [false, 'website_invalid'];
  $pdo->prepare('INSERT INTO crm_prospects (company_name, website, domain, kind, research_notes, source_url, owner_id, created_at, is_synthetic) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$company, $web, crm_domain($web), $kind, crm_text($notes, 4000), $su, $user['id'] ?? null, crm_now(), $synthetic ? 1 : 0]);
  $id = (int)$pdo->lastInsertId(); crm_audit($pdo, $user, 'prospect_add', 'prospect', $id, $kind); return [true, (string)$id];
}
function crm_prospect_qualify(PDO $pdo, ?array $user, int $id, string $fit): array {
  if (crm_deny($user, 'write')) return [false, 'forbidden']; if (!isset(crm_prospect_fit_statuses()[$fit])) return [false, 'fit_invalid'];
  $s = $pdo->prepare('UPDATE crm_prospects SET fit_status = ? WHERE id = ?'); $s->execute([$fit, $id]); if (!$s->rowCount() && !$pdo->query('SELECT 1 FROM crm_prospects WHERE id = ' . (int)$id)->fetchColumn()) return [false, 'not_found'];
  crm_audit($pdo, $user, 'prospect_' . $fit, 'prospect', $id, 'fit set'); return [true, 'ok'];
}
function crm_prospects_list(PDO $pdo, string $fit = '', int $limit = 200): array { $ok = isset(crm_prospect_fit_statuses()[$fit]); $s = $pdo->prepare('SELECT * FROM crm_prospects' . ($ok ? ' WHERE fit_status = ?' : '') . ' ORDER BY id DESC LIMIT ' . max(1, min(500, $limit))); $s->execute($ok ? [$fit] : []); return $s->fetchAll(PDO::FETCH_ASSOC); }
function crm_outreach_templates(): array {
  $foot = "\n\nIf you would rather not hear from us again, reply with the word STOP and we will not contact you again.";
  return [
    'advertiser_intro' => ['subject' => 'AIHubpedia: a question about {{product}}', 'body' => "Hello {{name}},\n\nWe maintain AIHubpedia, an independent AI tool directory. We are exploring labelled sponsored placements for AI tools like {{product}}. Sponsored items are clearly marked and never change our editorial evidence or recommendations. Would it be useful if we sent details?" . $foot],
    'partner_intro' => ['subject' => 'Partnership idea: {{company}} and AIHubpedia', 'body' => "Hello {{name}},\n\nWe are looking for implementation and technology partners. If a collaboration might interest {{company}}, we would be glad to hear what you have in mind. No obligation." . $foot],
    'services_followup' => ['subject' => 'Following up on your project question', 'body' => "Hello {{name}},\n\nThank you for your project inquiry. Here is what we would like to understand next: {{questions}}." . $foot],
  ];
}
function crm_outreach_draft_add(PDO $pdo, ?array $user, string $subject, string $body, ?int $prospectId = null, ?int $contactId = null): array {
  if (crm_deny($user, 'outreach')) return [false, 'forbidden']; $subject = crm_line($subject, 190); $body = crm_text($body, 6000);
  if ($subject === '' || $body === '') return [false, 'content_required']; if (($prospectId === null) === ($contactId === null)) return [false, 'one_target_required'];
  if (stripos($body, 'STOP') === false) return [false, 'optout_line_required'];
  if ($contactId !== null) { $c = crm_contact_get($pdo, $contactId); if (!$c) return [false, 'contact_not_found']; if ($c['unsubscribed_at'] !== null || $c['consent_status'] === 'unsubscribed') return [false, 'contact_unsubscribed']; if (!in_array($c['consent_status'], ['inbound_inquiry', 'opted_in'], true)) return [false, 'no_consent_basis']; }
  if ($prospectId !== null && !$pdo->query('SELECT 1 FROM crm_prospects WHERE id = ' . (int)$prospectId)->fetchColumn()) return [false, 'prospect_not_found'];
  $pdo->prepare("INSERT INTO crm_outreach_drafts (prospect_id, contact_id, subject, body, status, created_by, created_at) VALUES (?,?,?,?,'draft',?,?)")->execute([$prospectId, $contactId, $subject, $body, $user['id'] ?? null, crm_now()]);
  $id = (int)$pdo->lastInsertId(); crm_audit($pdo, $user, 'outreach_draft', 'prospect', $prospectId, 'draft #' . $id . ' (not sent; this system sends nothing)'); return [true, (string)$id];
}
function crm_outreach_draft_status(PDO $pdo, ?array $user, int $id, string $to): array {
  if (crm_deny($user, 'outreach')) return [false, 'forbidden']; if (!in_array($to, ['draft', 'reviewed', 'archived'], true)) return [false, 'status_invalid'];   // there is deliberately no "sent"
  if ($to === 'reviewed') { $b = $pdo->prepare('SELECT subject, body FROM crm_outreach_drafts WHERE id = ?'); $b->execute([$id]); $d = $b->fetch(PDO::FETCH_ASSOC); if ($d && preg_match('/\{\{\s*[a-z_]+\s*\}\}/i', $d['subject'] . ' ' . $d['body'])) return [false, 'placeholders_unresolved']; }   // Phase 7D: a draft with unfilled {{placeholders}} cannot be reviewed
  $s = $pdo->prepare('UPDATE crm_outreach_drafts SET status = ? WHERE id = ?'); $s->execute([$to, $id]); crm_audit($pdo, $user, 'outreach_status', 'prospect', null, 'draft #' . $id . ' ' . $to); return [true, 'ok'];
}
function crm_outreach_drafts_list(PDO $pdo, int $limit = 100): array { return $pdo->query('SELECT d.*, p.company_name FROM crm_outreach_drafts d LEFT JOIN crm_prospects p ON p.id = d.prospect_id ORDER BY d.id DESC LIMIT ' . max(1, min(500, $limit)))->fetchAll(PDO::FETCH_ASSOC); }
function crm_contact_set_consent(PDO $pdo, ?array $user, int $contactId, string $status, string $source): array {
  if (crm_deny($user, 'outreach')) return [false, 'forbidden']; if (!in_array($status, ['none', 'inbound_inquiry', 'opted_in'], true)) return [false, 'status_invalid']; $c = crm_contact_get($pdo, $contactId); if (!$c) return [false, 'not_found'];
  if ($c['unsubscribed_at'] !== null) return [false, 'contact_unsubscribed'];   // an unsubscribe is final unless the person themselves asks to be contacted again
  $source = crm_line($source, 80); if ($status === 'opted_in' && $source === '') return [false, 'consent_source_required'];
  $pdo->prepare('UPDATE crm_contacts SET consent_status = ?, consent_source = ?, consent_at = ? WHERE id = ?')->execute([$status, $source, crm_now(), $contactId]); crm_audit($pdo, $user, 'consent_' . $status, 'contact', $contactId, 'source: ' . $source); return [true, 'ok'];
}
function crm_contact_unsubscribe(PDO $pdo, ?array $user, int $contactId): array {
  if (crm_deny($user, 'write')) return [false, 'forbidden']; if (!crm_contact_get($pdo, $contactId)) return [false, 'not_found'];
  $pdo->prepare("UPDATE crm_contacts SET consent_status = 'unsubscribed', unsubscribed_at = ? WHERE id = ?")->execute([crm_now(), $contactId]);
  $pdo->prepare("UPDATE crm_outreach_drafts SET status = 'archived' WHERE contact_id = ? AND status <> 'archived'")->execute([$contactId]);
  crm_audit($pdo, $user, 'contact_unsubscribe', 'contact', $contactId, 'suppressed; open drafts archived'); return [true, 'ok'];
}
function crm_outreach_report(PDO $pdo): array {
  $d = $pdo->query("SELECT status, COUNT(*) n FROM crm_outreach_drafts GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
  return ['drafts' => (int)array_sum($d), 'by_status' => $d, 'sent' => 0, 'note' => 'This system does not send outreach; "sent" is always 0.',
    'prospects' => (int)$pdo->query('SELECT COUNT(*) FROM crm_prospects')->fetchColumn(), 'unsubscribed_contacts' => (int)$pdo->query('SELECT COUNT(*) FROM crm_contacts WHERE unsubscribed_at IS NOT NULL')->fetchColumn(),
    'open_followups' => (int)$pdo->query('SELECT COUNT(*) FROM crm_tasks WHERE done_at IS NULL')->fetchColumn()];
}
require_once __DIR__ . '/crm_acquisition.php';   // Phase 7D (customer acquisition); degrades gracefully until database/phase7d_acquisition.sql is applied
