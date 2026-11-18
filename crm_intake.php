<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
/**
 * Public intake (Phase 6): maps website inquiries to CRM records, validates the guided project and partnership forms, renders those forms.
 * The public inquiry is stored first (leads table, existing notification and reports keep working); the CRM copy is best effort.
 */
require_once __DIR__ . '/crm_dashboard.php';

function trustnova_catalog(): array { return catalog_json('trustnova_services.json', ['services' => [], 'process' => [], 'honest_notes' => []]); }
function trustnova_services(): array { return trustnova_catalog()['services'] ?? []; }
function project_contact_prefs(): array { return ['email' => 'Email', 'video_call' => 'Video call', 'phone' => 'Phone call']; }

/** lead type → [CRM kind, subtype] */
function crm_intake_kind(string $leadType): ?array {
  return [
    'advertiser' => ['advertising', 'featured_placement'], 'newsletter_sponsor' => ['advertising', 'newsletter_sponsorship'], 'category_sponsor' => ['advertising', 'category_sponsorship'], 'custom_campaign' => ['advertising', 'custom_campaign'],
    'affiliate_partner' => ['partnership', 'affiliate'], 'partnership_application' => ['partnership', 'application'],
    'consultation' => ['development', 'consultation'], 'ai_agent' => ['development', 'ai_agent'], 'software_dev' => ['development', 'software'], 'dev_project' => ['development', 'project'],
  ][$leadType] ?? null;
}
function crm_intake_campaign_package(string $leadType, string $pkgInterest): string {
  if ($leadType === 'newsletter_sponsor' || $pkgInterest === 'newsletter_sponsor') return 'newsletter_sponsorship';
  if ($leadType === 'category_sponsor') return 'category_sponsorship'; if ($leadType === 'custom_campaign') return 'custom_campaign';
  return in_array($pkgInterest, ['free', 'priority'], true) ? 'listing' : 'featured_placement';
}
/**
 * Mirrors one stored public inquiry into the CRM. Advertising types also create a campaign record (status "inquiry"); partnership types create a
 * partner application (status "applied", never public). @param array $clean validated fields (lead_validate) plus optional 'details', 'partner_category'.
 */
function crm_intake_record(PDO $pdo, array $clean, int $publicLeadId = 0, bool $synthetic = false): array {
  $map = crm_intake_kind((string)($clean['lead_type'] ?? '')); if (!$map) return [false, 'kind_invalid'];
  [$kind, $subtype] = $map; $details = (array)($clean['details'] ?? []);
  foreach (['package_interest', 'campaign_goal', 'ref_tool'] as $k) if (!empty($clean[$k])) $details[$k] = $clean[$k];
  $own = !$pdo->inTransaction(); if ($own) $pdo->beginTransaction();
  try {
    [$ok, $leadId, $info] = crm_lead_ingest($pdo, ['kind' => $kind, 'subtype' => $subtype, 'name' => $clean['name'] ?? '', 'email' => $clean['email'] ?? '', 'company' => $clean['company'] ?? '', 'website' => $clean['website'] ?? '', 'message' => $clean['message'] ?? '',
      'budget' => $clean['budget'] ?? '', 'timeline' => $clean['timeline'] ?? '', 'source' => $clean['source_page'] ?? '', 'attr_source' => $clean['attr_source'] ?? '', 'attr_medium' => $clean['attr_medium'] ?? '', 'attr_campaign' => $clean['attr_campaign'] ?? '',
      'details' => $details, 'public_lead_id' => $publicLeadId > 0 ? $publicLeadId : null, 'pref' => $clean['contact_pref'] ?? 'email', 'synthetic' => $synthetic]);
    if (!$ok) { if ($own) $pdo->rollBack(); return [false, (string)$leadId]; }
    $extra = [];
    if ($kind === 'advertising') { [$cok, $cid] = crm_campaign_create($pdo, null, (int)$info['company_id'], crm_intake_campaign_package($clean['lead_type'], (string)($clean['package_interest'] ?? '')), 'Inquiry from ' . crm_line($clean['company'] ?: $clean['name'], 120), (int)$leadId, (int)$info['contact_id'], $synthetic); if ($cok) $extra['campaign_id'] = (int)$cid; }
    if ($kind === 'partnership') { $cat = isset(crm_partner_categories()[$clean['partner_category'] ?? '']) ? $clean['partner_category'] : ($subtype === 'affiliate' ? 'affiliate' : 'technology_provider'); [$pok, $pid] = crm_partner_create($pdo, null, (int)$info['company_id'], $cat, (string)($clean['message'] ?? ''), (int)$info['contact_id'], (int)$leadId, $synthetic); if ($pok) $extra['partner_id'] = (int)$pid; }
    if ($own) $pdo->commit(); return [true, (string)$leadId, $info + $extra];
  } catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); sec_log_exception($e, 'crm_intake_record'); return [false, 'storage_error']; }
}

/** Validation for the guided TrustNova project form. @return array{errors:array,clean:array} */
function project_validate(array $post, ?int $now = null): array {
  $p = ['lead_type' => 'dev_project'] + $post; $v = lead_validate($p, ['dev_project'], $now); $e = $v['errors']; $c = $v['clean'];
  $svc = (string)($post['service'] ?? ''); if (!isset(trustnova_services()[$svc])) $e['service'] = 'Please choose the kind of project.'; $c['service'] = isset(trustnova_services()[$svc]) ? $svc : '';
  $tech = mb_substr(trim((string)($post['existing_tech'] ?? '')), 0, 600); $c['existing_tech'] = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $tech) ?? '';
  $pref = (string)($post['contact_pref'] ?? 'email'); $c['contact_pref'] = isset(project_contact_prefs()[$pref]) ? $pref : 'email';
  if (mb_strlen($c['company']) < 2) $e['company'] = 'Please enter your company name.';
  if (mb_strlen($c['message']) < 40) $e['message'] = 'Please describe the business problem (at least 40 characters) so we can judge whether we can help.';
  $c['details'] = ['service' => $c['service'], 'existing_technology' => $c['existing_tech'], 'preferred_contact' => $c['contact_pref']];
  $c['message'] = $c['message'] . ($c['existing_tech'] !== '' ? "\n[Existing technology: " . $c['existing_tech'] . ']' : '') . ($c['service'] !== '' ? "\n[Project category: " . (trustnova_services()[$c['service']]['title'] ?? $c['service']) . ']' : '');
  return ['errors' => $e, 'clean' => $c];
}
/** Validation for the partnership application. */
function partner_validate(array $post, ?int $now = null): array {
  $p = ['lead_type' => 'partnership_application'] + $post; $v = lead_validate($p, ['partnership_application'], $now); $e = $v['errors']; $c = $v['clean'];
  $cat = (string)($post['partner_category'] ?? ''); if (!isset(crm_partner_categories()[$cat])) $e['partner_category'] = 'Please choose a partner category.'; $c['partner_category'] = isset(crm_partner_categories()[$cat]) ? $cat : '';
  if (mb_strlen($c['company']) < 2) $e['company'] = 'Please enter your company name.';
  if ($c['website'] === '') $e['website'] = 'Please enter your company website.';
  if (mb_strlen($c['message']) < 40) $e['message'] = 'Please describe the collaboration you propose (at least 40 characters).';
  return ['errors' => $e, 'clean' => $c];
}

function crm_form_field(string $id, string $label, string $control, string $err = '', string $hint = ''): string {
  $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
  return '<div class="field"><label for="' . $h($id) . '">' . $label . '</label>' . ($hint !== '' ? '<p class="small" id="' . $h($id) . '-hint" style="margin:0 0 4px">' . $h($hint) . '</p>' : '') . $control . ($err !== '' ? '<p class="fieldError" id="' . $h($id) . '-err" role="alert">' . $h($err) . '</p>' : '') . '</div>';
}
function render_project_form(array $opts = []): string {
  $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES); $v = $opts['values'] ?? []; $e = $opts['errors'] ?? []; $att = lead_attribution_from_request($_GET); $sel = (string)($v['service'] ?? ($opts['service'] ?? ''));
  $aria = fn($k) => isset($e[$k]) ? ' aria-invalid="true" aria-describedby="pj-' . $k . '-err"' : '';
  $o = '<form class="leadForm" id="projectForm" method="post" action="/project-inquiry.php" novalidate>';
  if (!empty($e['_token']) || !empty($e['_bot'])) $o .= '<p class="fieldError" role="alert">' . $h($e['_token'] ?? $e['_bot']) . '</p>';
  $o .= '<input type="hidden" name="form_token" value="' . $h(form_token('lead')) . '"><input type="hidden" name="source_page" value="/services.php"><div class="hp" aria-hidden="true"><label>Leave this field empty<input type="text" name="website_url_confirm" tabindex="-1" autocomplete="off"></label></div>';
  foreach (['attr_source', 'attr_medium', 'attr_campaign'] as $ak) $o .= '<input type="hidden" name="' . $ak . '" value="' . $h($v[$ak] ?? $att[$ak]) . '">';
  $opt = '<option value="">Choose a project type</option>'; foreach (trustnova_services() as $slug => $s) $opt .= '<option value="' . $h($slug) . '"' . ($sel === $slug ? ' selected' : '') . '>' . $h($s['title']) . '</option>';
  $o .= '<fieldset><legend>1. The project</legend>' . crm_form_field('pj-service', 'Project category', '<select id="pj-service" name="service" required' . $aria('service') . '>' . $opt . '</select>', $e['service'] ?? '')
      . crm_form_field('pj-message', 'The business problem', '<textarea class="input" id="pj-message" name="message" rows="5" required' . $aria('message') . ' aria-describedby="pj-message-hint">' . $h($v['message'] ?? '') . '</textarea>', $e['message'] ?? '', 'What is hard or costly today, and what would a good result look like? Please do not include confidential data or personal data about other people.')
      . crm_form_field('pj-tech', 'Existing technology <span class="opt">(optional)</span>', '<textarea class="input" id="pj-tech" name="existing_tech" rows="2" maxlength="600">' . $h($v['existing_tech'] ?? '') . '</textarea>', '', 'Systems, languages, cloud or tools involved.') . '</fieldset>';
  $bo = ''; foreach (lead_budget_options() as $k => $l) $bo .= '<option value="' . $h($k) . '"' . (($v['budget'] ?? '') === (string)$k ? ' selected' : '') . '>' . $h($l) . '</option>';
  $to = ''; foreach (lead_timeline_options() as $k => $l) $to .= '<option value="' . $h($k) . '"' . (($v['timeline'] ?? '') === (string)$k ? ' selected' : '') . '>' . $h($l) . '</option>';
  $o .= '<fieldset><legend>2. Budget and timing</legend><div class="fieldRow">' . crm_form_field('pj-budget', 'Budget range <span class="opt">(optional)</span>', '<select id="pj-budget" name="budget">' . $bo . '</select>') . crm_form_field('pj-timeline', 'Timeline <span class="opt">(optional)</span>', '<select id="pj-timeline" name="timeline">' . $to . '</select>') . '</div></fieldset>';
  $po = ''; foreach (project_contact_prefs() as $k => $l) $po .= '<option value="' . $h($k) . '"' . (($v['contact_pref'] ?? 'email') === $k ? ' selected' : '') . '>' . $h($l) . '</option>';
  $o .= '<fieldset><legend>3. About you</legend><div class="fieldRow">' . crm_form_field('pj-name', 'Your name', '<input class="input" id="pj-name" name="name" autocomplete="name" required value="' . $h($v['name'] ?? '') . '"' . $aria('name') . '>', $e['name'] ?? '')
      . crm_form_field('pj-email', 'Business email', '<input class="input" id="pj-email" name="email" type="email" autocomplete="email" required value="' . $h($v['email'] ?? '') . '"' . $aria('email') . '>', $e['email'] ?? '') . '</div><div class="fieldRow">'
      . crm_form_field('pj-company', 'Company', '<input class="input" id="pj-company" name="company" autocomplete="organization" required value="' . $h($v['company'] ?? '') . '"' . $aria('company') . '>', $e['company'] ?? '')
      . crm_form_field('pj-website', 'Website <span class="opt">(optional)</span>', '<input class="input" id="pj-website" name="website" inputmode="url" autocomplete="url" placeholder="https://" value="' . $h($v['website'] ?? '') . '"' . $aria('website') . '>', $e['website'] ?? '') . '</div>'
      . crm_form_field('pj-pref', 'Preferred way to be contacted', '<select id="pj-pref" name="contact_pref">' . $po . '</select>') . '</fieldset>';
  $o .= '<div class="field check"><label><input type="checkbox" name="consent" value="1" required' . (!empty($v['consent']) ? ' checked' : '') . $aria('consent') . '> I agree that TrustNova Tech may use these details to reply to my inquiry. <a href="/privacy.php">Privacy policy</a></label>' . (isset($e['consent']) ? '<p class="fieldError" id="pj-consent-err" role="alert">' . $h($e['consent']) . '</p>' : '') . '</div>';
  return $o . '<button class="btn" type="submit">Send project inquiry</button><p class="small">We reply by email if it looks like a fit. We never add you to a mailing list from this form.</p></form>';
}
function render_partner_form(array $opts = []): string {
  $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES); $v = $opts['values'] ?? []; $e = $opts['errors'] ?? []; $att = lead_attribution_from_request($_GET);
  $aria = fn($k) => isset($e[$k]) ? ' aria-invalid="true" aria-describedby="pa-' . $k . '-err"' : '';
  $o = '<form class="leadForm" id="partnerForm" method="post" action="/partner-apply.php" novalidate>';
  if (!empty($e['_token']) || !empty($e['_bot'])) $o .= '<p class="fieldError" role="alert">' . $h($e['_token'] ?? $e['_bot']) . '</p>';
  $o .= '<input type="hidden" name="form_token" value="' . $h(form_token('lead')) . '"><input type="hidden" name="source_page" value="/partners.php"><div class="hp" aria-hidden="true"><label>Leave this field empty<input type="text" name="website_url_confirm" tabindex="-1" autocomplete="off"></label></div>';
  foreach (['attr_source', 'attr_medium', 'attr_campaign'] as $ak) $o .= '<input type="hidden" name="' . $ak . '" value="' . $h($v[$ak] ?? $att[$ak]) . '">';
  $co = '<option value="">Choose a category</option>'; foreach (crm_partner_categories() as $k => $l) $co .= '<option value="' . $h($k) . '"' . (($v['partner_category'] ?? '') === $k ? ' selected' : '') . '>' . $h($l) . '</option>';
  $o .= crm_form_field('pa-cat', 'Partner category', '<select id="pa-cat" name="partner_category" required' . $aria('partner_category') . '>' . $co . '</select>', $e['partner_category'] ?? '')
      . '<div class="fieldRow">' . crm_form_field('pa-company', 'Company', '<input class="input" id="pa-company" name="company" autocomplete="organization" required value="' . $h($v['company'] ?? '') . '"' . $aria('company') . '>', $e['company'] ?? '')
      . crm_form_field('pa-website', 'Company website', '<input class="input" id="pa-website" name="website" inputmode="url" autocomplete="url" placeholder="https://" required value="' . $h($v['website'] ?? '') . '"' . $aria('website') . '>', $e['website'] ?? '') . '</div><div class="fieldRow">'
      . crm_form_field('pa-name', 'Your name', '<input class="input" id="pa-name" name="name" autocomplete="name" required value="' . $h($v['name'] ?? '') . '"' . $aria('name') . '>', $e['name'] ?? '')
      . crm_form_field('pa-email', 'Business email', '<input class="input" id="pa-email" name="email" type="email" autocomplete="email" required value="' . $h($v['email'] ?? '') . '"' . $aria('email') . '>', $e['email'] ?? '') . '</div>'
      . crm_form_field('pa-message', 'Your collaboration proposal', '<textarea class="input" id="pa-message" name="message" rows="5" required' . $aria('message') . '>' . $h($v['message'] ?? '') . '</textarea>', $e['message'] ?? '', 'What would we do together, and what do you expect from AIHubpedia? Please do not include confidential information.');
  $o .= '<div class="field check"><label><input type="checkbox" name="consent" value="1" required' . (!empty($v['consent']) ? ' checked' : '') . $aria('consent') . '> I agree that AIHubpedia may use these details to reply to my application. <a href="/privacy.php">Privacy policy</a></label>' . (isset($e['consent']) ? '<p class="fieldError" id="pa-consent-err" role="alert">' . $h($e['consent']) . '</p>' : '') . '</div>';
  return $o . '<button class="btn" type="submit">Send application</button></form>';
}

/**
 * Shared submit path for the public forms (advertising, project, partnership).
 * Order: the public `leads` table when it exists (existing notification tracking, reports and rate limit), then the CRM mirror. If the `leads` table
 * does not exist (the live "aihub" schema lacks the Phase 3/4 tables) but the CRM tables do, the inquiry is stored in the CRM only, so no inquiry is
 * lost; the per-network limit then uses the file-based limiter. If neither exists the form reports "temporarily unavailable".
 * @return array{status:string,lead_id:int,crm:string} status: ok | rate_limited | unavailable
 */
function crm_public_submit(array $clean, string $logCtx): array {
  try {
    $haveLeads = db_table_exists('leads'); $haveCrm = crm_tables_ready();
    if (!$haveLeads && !$haveCrm) return ['status' => 'unavailable', 'lead_id' => 0, 'crm' => 'none'];
    $pdo = db(); $ipHash = conversion_ip_hash();
    if ($haveLeads ? lead_rate_limited($pdo, 'leads', $ipHash, 5) : sec_rate_limited('public_inquiry', 5, 3600)) return ['status' => 'rate_limited', 'lead_id' => 0, 'crm' => 'none'];
    $leadId = $haveLeads ? lead_store($pdo, $clean, $ipHash) : 0; $crmLead = 0; $crmState = $haveCrm ? 'failed' : 'not_installed';
    if ($haveCrm) { try { [$ok, $id] = crm_intake_record($pdo, $clean, $leadId); if ($ok) { $crmState = 'stored'; $crmLead = (int)$id; } } catch (Throwable $e) { sec_log_exception($e, $logCtx . ':crm'); } }
    $sent = false; try { $sent = lead_notify_owner($clean); } catch (Throwable $e) { mail_last_error('smtp_other'); sec_log_exception($e, $logCtx . ':notify'); }
    if ($haveLeads) { try { lead_record_notification($pdo, $leadId, $sent, mail_last_error()); } catch (Throwable $e) { sec_log_exception($e, $logCtx . ':notify-record'); } }
    elseif (!$sent && $crmLead) { try { crm_activity_add($pdo, null, 'lead', $crmLead, 'system', 'The owner e-mail notification failed (' . mail_last_error() . '). Check this lead in the CRM.'); } catch (Throwable $e) { sec_log_exception($e, $logCtx . ':notify-note'); } }
    if (!$sent) sec_log('lead_notification_failed', ['lead' => $leadId ?: $crmLead, 'reason' => mail_last_error()]);
    event_record_server('lead_submit', (string)($clean['lead_type'] ?? ''));
    return ['status' => 'ok', 'lead_id' => $leadId, 'crm' => $crmState];
  } catch (Throwable $e) { sec_log_exception($e, $logCtx . ':submit'); return ['status' => 'unavailable', 'lead_id' => 0, 'crm' => 'none']; }
}
