<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
/**
 * Corporate customer acquisition (Phase 7D), built ON the Phase 6 prospect, outreach-draft and consent modules (crm_growth.php): a "prospect" stays a crm_prospects row;
 * this file adds the company-level acquisition profile, transparent scoring, the status workflow with history, suppression, conversion into a CRM lead (once),
 * CSV import/export, bilingual outreach templates and the acquisition dashboard.
 *
 * Rules: company-level data only (no scraping, no personal contact data in research fields); every figure is something a person recorded; estimates are labelled;
 * NOTHING is sent: drafts are text for a person to review, personalise and send outside this system, and sending does not bypass consent or marketing-law
 * requirements (the system records a permission basis and blocks suppressed companies; it does not decide what is lawful). Requires database/phase7d_acquisition.sql.
 */
require_once __DIR__ . '/crm_growth.php';

const CRM_ACQ_MAX_IMPORT_ROWS = 500;
const CRM_ACQ_MAX_IMPORT_BYTES = 1048576;
const CRM_ACQ_MAX_SOURCES = 8;

// ---- vocabularies -----------------------------------------------------------------------------------------------------------------------
/** The eight TrustNova service lines (private admin vocabulary; independent of the public service pages). */
function crm_acq_services(): array { return ['custom-ai-agents' => 'Custom AI agents', 'process-automation' => 'Business process automation', 'ai-chatbots-assistants' => 'AI chatbots and assistants', 'custom-software' => 'Custom software solutions', 'enterprise-integrations' => 'Enterprise integrations', 'data-analytics-ai' => 'Data analytics and AI insights', 'cloud-transformation' => 'Cloud and digital transformation', 'ai-consulting-strategy' => 'AI consulting and strategy']; }
function crm_acq_statuses(): array { return ['identified' => 'Identified', 'researching' => 'Researching', 'qualified' => 'Qualified', 'contact_planned' => 'Contact planned', 'contacted' => 'Contacted', 'responded' => 'Responded', 'consultation_requested' => 'Consultation requested', 'converted_to_lead' => 'Converted to lead', 'not_interested' => 'Not interested', 'do_not_contact' => 'Do not contact']; }
function crm_acq_transitions(): array {
  return ['identified' => ['researching', 'not_interested', 'do_not_contact'], 'researching' => ['qualified', 'identified', 'not_interested', 'do_not_contact'], 'qualified' => ['contact_planned', 'researching', 'not_interested', 'do_not_contact'],
    'contact_planned' => ['contacted', 'qualified', 'not_interested', 'do_not_contact'], 'contacted' => ['responded', 'contact_planned', 'not_interested', 'do_not_contact'], 'responded' => ['consultation_requested', 'not_interested', 'do_not_contact'],
    'consultation_requested' => ['not_interested', 'do_not_contact'], 'converted_to_lead' => [], 'not_interested' => ['researching', 'do_not_contact'], 'do_not_contact' => []];   // converted_to_lead only through crm_acq_convert()
}
function crm_acq_sizes(): array { return ['1-10' => '1-10 employees', '11-50' => '11-50', '51-200' => '51-200', '201-1000' => '201-1,000', '1000+' => 'More than 1,000']; }
function crm_acq_industries(): array { return ['manufacturing' => 'Manufacturing', 'logistics' => 'Logistics and transport', 'retail' => 'Retail and e-commerce', 'finance' => 'Financial services', 'insurance' => 'Insurance', 'healthcare' => 'Healthcare', 'energy' => 'Energy and utilities', 'telecom' => 'Telecom and media', 'public' => 'Public sector', 'education' => 'Education', 'professional' => 'Professional services', 'software' => 'Software and IT', 'construction' => 'Construction and real estate', 'hospitality' => 'Travel and hospitality', 'other' => 'Other']; }
function crm_acq_budget_kinds(): array { return ['unknown' => 'Unknown (nothing recorded)', 'public_signal' => 'Public signal (e.g. published tender or funding)', 'stated_by_company' => 'Stated by the company']; }
function crm_acq_urgency_kinds(): array { return ['unknown' => 'Unknown (nothing recorded)', 'planned' => 'Project planned (source noted)', 'urgent' => 'Urgent need (source noted)']; }
function crm_acq_contact_bases(): array { return ['none' => 'None recorded: do not contact', 'inbound_request' => 'They asked us to get in touch', 'consent' => 'Consent recorded (where and when in the note)', 'existing_relationship' => 'Existing customer relationship', 'non_email_business_channel' => 'Business channel that needs no personal consent (note which; check local law)']; }
function crm_acq_country_names(): array { return ['DE' => 'Germany', 'AT' => 'Austria', 'CH' => 'Switzerland', 'GB' => 'United Kingdom', 'IE' => 'Ireland', 'FR' => 'France', 'NL' => 'Netherlands', 'BE' => 'Belgium', 'LU' => 'Luxembourg', 'ES' => 'Spain', 'PT' => 'Portugal', 'IT' => 'Italy', 'SE' => 'Sweden', 'NO' => 'Norway', 'DK' => 'Denmark', 'FI' => 'Finland', 'PL' => 'Poland', 'CZ' => 'Czechia', 'US' => 'United States', 'CA' => 'Canada', 'MX' => 'Mexico', 'BR' => 'Brazil', 'AR' => 'Argentina', 'AE' => 'United Arab Emirates', 'SA' => 'Saudi Arabia', 'IN' => 'India', 'SG' => 'Singapore', 'JP' => 'Japan', 'KR' => 'South Korea', 'CN' => 'China', 'AU' => 'Australia', 'NZ' => 'New Zealand', 'ZA' => 'South Africa', 'NG' => 'Nigeria', 'EG' => 'Egypt', 'TR' => 'Turkey']; }
/** "DE", "de" or "Germany" -> "DE"; '' -> ''; anything else -> null. Any two-letter code is accepted so companies anywhere can be recorded. */
function crm_acq_country(string $s): ?string {
  $s = trim($s); if ($s === '') return ''; if (preg_match('/^[A-Za-z]{2}$/', $s)) return strtoupper($s);
  foreach (crm_acq_country_names() as $c => $n) if (strcasecmp($n, $s) === 0) return $c; return null;
}
function crm_acq_country_label(string $c): string { return $c === '' ? '—' : (crm_acq_country_names()[$c] ?? $c) . ' (' . $c . ')'; }
/** Lower-case company name without punctuation and legal-form words, for duplicate detection ("Acme GmbH" = "ACME Gmbh."). */
function crm_acq_norm_name(string $n): string {
  $n = mb_strtolower(trim($n)); $n = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $n) ?? $n; $drop = ['gmbh', 'ag', 'kg', 'ug', 'se', 'ltd', 'limited', 'llc', 'inc', 'corp', 'corporation', 'co', 'plc', 'sa', 'sas', 'sarl', 'srl', 'spa', 'bv', 'nv', 'ab', 'as', 'oy', 'pvt', 'company', 'und', 'mbh', 'ohg', 'ev'];
  $w = array_values(array_filter(preg_split('/\s+/', $n) ?: [], fn($x) => $x !== '' && !in_array($x, $drop, true))); return implode(' ', $w);
}
function crm_acq_ready(PDO $pdo): bool {
  static $memo = []; $k = spl_object_id($pdo); if (isset($memo[$k])) return $memo[$k];
  try { foreach (['crm_prospect_profile', 'crm_prospect_status_history', 'crm_prospect_suppressions'] as $t) $pdo->query("SELECT 1 FROM $t LIMIT 1"); return $memo[$k] = true; } catch (Throwable $e) { return false; }
}

// ---- transparent scoring (pure) ------------------------------------------------------------------------------------------------------------
/**
 * 0-100 from RECORDED inputs only (seven groups, maximum shown for each). Weights are planning assumptions, not measured win rates; nothing is inferred, so an unrecorded
 * input scores 0 and is listed under "missing". Bands: priority 70+, promising 45-69, research more below 45.
 * @param array $p services (comma list or array), requirements, automation_notes, size_band, budget_info, urgency, sources (array), country, industry, website, research_notes
 * @return array{score:int,band:string,reasons:array,missing:array,recorded:int,of:int}
 */
function crm_acq_score(array $p): array {
  $r = []; $missing = []; $rec = 0; $add = function (string $group, string $why, int $pts, int $max) use (&$r) { $r[] = ['group' => $group, 'why' => $why, 'pts' => $pts, 'max' => $max]; };
  $sv = $p['services'] ?? []; if (is_string($sv)) $sv = array_values(array_filter(explode(',', $sv))); $sv = array_values(array_intersect($sv, array_keys(crm_acq_services()))); $n = count($sv);
  if ($n > 0) { $rec++; $add('Service relevance', $n . ' TrustNova service' . ($n > 1 ? 's' : '') . ' marked as relevant', min(20, 10 + 5 * ($n - 1)), 20); } else { $add('Service relevance', 'No relevant service recorded', 0, 20); $missing[] = 'relevant services'; }
  $rq = mb_strlen(trim((string)($p['requirements'] ?? ''))); if ($rq > 0) { $rec++; $add('Business requirements', $rq >= 200 ? 'Detailed requirements recorded (200+ characters)' : ($rq >= 80 ? 'Requirements recorded (80+ characters)' : 'Short requirements note'), $rq >= 200 ? 15 : ($rq >= 80 ? 10 : 5), 15); } else { $add('Business requirements', 'No requirements recorded', 0, 15); $missing[] = 'business requirements'; }
  $au = mb_strlen(trim((string)($p['automation_notes'] ?? ''))); if ($au > 0) { $rec++; $add('Automation potential', $au >= 120 ? 'Specific automation opportunities noted (120+ characters)' : ($au >= 40 ? 'Automation opportunity noted' : 'Short automation note'), $au >= 120 ? 15 : ($au >= 40 ? 10 : 5), 15); } else { $add('Automation potential', 'No automation opportunity recorded', 0, 15); $missing[] = 'automation opportunities'; }
  $fit = ['1-10' => 5, '11-50' => 10, '51-200' => 13, '201-1000' => 15, '1000+' => 12][(string)($p['size_band'] ?? '')] ?? null; if ($fit !== null) { $rec++; $add('Company fit', 'Company size ' . ($p['size_band'] ?? '') . ' (mid-size and larger companies fit our project sizes best: a planning assumption)', $fit, 15); } else { $add('Company fit', 'Company size not recorded', 0, 15); $missing[] = 'company size'; }
  $bu = ['public_signal' => 8, 'stated_by_company' => 15][(string)($p['budget_info'] ?? 'unknown')] ?? 0; if ($bu > 0) { $rec++; $add('Budget information', ($p['budget_info'] ?? '') === 'stated_by_company' ? 'The company stated a budget' : 'A public budget signal exists', $bu, 15); } else { $add('Budget information', 'No budget information recorded (never assumed)', 0, 15); $missing[] = 'budget information'; }
  $ug = ['planned' => 5, 'urgent' => 10][(string)($p['urgency'] ?? 'unknown')] ?? 0; if ($ug > 0) { $rec++; $add('Project urgency', ($p['urgency'] ?? '') === 'urgent' ? 'Urgent need, with a recorded source' : 'A planned project, with a recorded source', $ug, 10); } else { $add('Project urgency', 'No urgency recorded (never assumed)', 0, 10); $missing[] = 'project urgency'; }
  $src = count((array)($p['sources'] ?? [])); $fields = (trim((string)($p['website'] ?? '')) !== '' ? 1 : 0) + (($p['country'] ?? '') !== '' ? 1 : 0) + (($p['industry'] ?? '') !== '' ? 1 : 0); $notes = mb_strlen(trim((string)($p['research_notes'] ?? '')));
  $q = ($src >= 2 ? 5 : ($src === 1 ? 3 : 0)) + min(3, $fields) + ($notes >= 100 ? 2 : 0); if ($q > 0) $rec++; $add('Research quality', $src . ' public source' . ($src === 1 ? '' : 's') . ', ' . $fields . ' of 3 basics (website, country, industry)' . ($notes >= 100 ? ', research notes' : ''), $q, 10); if ($q === 0) $missing[] = 'research sources and basics';
  $score = array_sum(array_column($r, 'pts')); return ['score' => $score, 'band' => $score >= 70 ? 'priority' : ($score >= 45 ? 'promising' : 'research'), 'reasons' => $r, 'missing' => $missing, 'recorded' => $rec, 'of' => 7];
}
function crm_acq_band_label(string $b): string { return ['priority' => 'Priority', 'promising' => 'Promising', 'research' => 'Research more'][$b] ?? $b; }

// ---- validation helpers -----------------------------------------------------------------------------------------------------------------
function crm_acq_no_personal(string ...$vals): bool { foreach ($vals as $v) if (crm_contains_personal_contact($v)) return false; return true; }
/** Validates the profile fields shared by the form and the CSV import. @return array{0:bool,1:array|string} cleaned profile or an error code */
function crm_acq_clean(array $in): array {
  $country = crm_acq_country((string)($in['country'] ?? '')); if ($country === null) return [false, 'country_invalid'];
  $industry = (string)($in['industry'] ?? ''); if ($industry !== '' && !isset(crm_acq_industries()[$industry])) return [false, 'industry_invalid'];
  $size = (string)($in['size_band'] ?? ''); if ($size !== '' && !isset(crm_acq_sizes()[$size])) return [false, 'size_invalid'];
  $sv = $in['services'] ?? []; if (is_string($sv)) $sv = array_values(array_filter(array_map('trim', preg_split('/[,;|]/', $sv) ?: []))); foreach ($sv as $s) if (!isset(crm_acq_services()[$s])) return [false, 'service_invalid']; $sv = array_values(array_unique($sv));
  $budget = (string)($in['budget_info'] ?? 'unknown') ?: 'unknown'; if (!isset(crm_acq_budget_kinds()[$budget])) return [false, 'budget_invalid']; $urg = (string)($in['urgency'] ?? 'unknown') ?: 'unknown'; if (!isset(crm_acq_urgency_kinds()[$urg])) return [false, 'urgency_invalid'];
  $req = crm_text($in['requirements'] ?? '', 3000); $aut = crm_text($in['automation_notes'] ?? '', 3000); $notes = crm_text($in['notes'] ?? '', 4000); $region = crm_line($in['region'] ?? '', 80); $unote = crm_line($in['urgency_note'] ?? '', 255); $next = crm_line($in['next_action'] ?? '', 255);
  if (!crm_acq_no_personal($req, $aut, $notes, $region, $unote, $next, (string)($in['est_basis'] ?? ''))) return [false, 'personal_data_in_notes'];
  if ($urg !== 'unknown' && $unote === '') return [false, 'urgency_source_required'];
  $est = null; $cur = (string)($in['est_currency'] ?? 'EUR') ?: 'EUR'; $basis = crm_line($in['est_basis'] ?? '', 255); $ev = trim((string)($in['est_value'] ?? ''));
  if ($ev !== '') { $est = crm_money_parse($ev); if ($est === null || $est > 100000000000) return [false, 'estimate_invalid']; if (!crm_currency_ok($cur)) return [false, 'currency_invalid']; if ($basis === '') return [false, 'estimate_basis_required']; }
  $nd = null; $d = trim((string)($in['next_action_at'] ?? '')); if ($d !== '') { $t = crm_date($d); if ($t === null) return [false, 'date_invalid']; $nd = gmdate('Y-m-d', $t); }
  return [true, ['country' => $country, 'region' => $region, 'industry' => $industry, 'size_band' => $size, 'services' => $sv, 'budget_info' => $budget, 'urgency' => $urg, 'urgency_note' => $unote, 'requirements' => $req, 'automation_notes' => $aut, 'notes' => $notes,
    'est_value_minor' => $est, 'est_currency' => $cur, 'est_basis' => $est === null ? '' : $basis, 'next_action' => $next, 'next_action_at' => $nd]];
}

// ---- storage -----------------------------------------------------------------------------------------------------------------------------
/** Existing prospects (and CRM companies) that look like the same company: same website domain or same normalised name. @return array{prospects:array,companies:array} */
function crm_acq_find_duplicates(PDO $pdo, string $name, string $website, ?int $exceptId = null): array {
  $dom = crm_domain($website); $norm = crm_acq_norm_name($name); $pr = []; $co = [];
  foreach ($pdo->query('SELECT id, company_name, domain FROM crm_prospects')->fetchAll(PDO::FETCH_ASSOC) as $x) { if ($exceptId !== null && (int)$x['id'] === $exceptId) continue; if (($dom !== '' && $x['domain'] === $dom) || ($norm !== '' && crm_acq_norm_name($x['company_name']) === $norm)) $pr[] = ['id' => (int)$x['id'], 'name' => $x['company_name']]; }
  foreach ($pdo->query('SELECT id, name, domain FROM crm_companies WHERE is_synthetic = 0')->fetchAll(PDO::FETCH_ASSOC) as $x) if (($dom !== '' && $x['domain'] === $dom) || ($norm !== '' && crm_acq_norm_name($x['name']) === $norm)) $co[] = ['id' => (int)$x['id'], 'name' => $x['name']];
  return ['prospects' => $pr, 'companies' => $co];
}
function crm_acq_is_suppressed(PDO $pdo, string $website): bool { if (!crm_acq_ready($pdo)) return false; $d = crm_domain($website); if ($d === '') return false; $s = $pdo->prepare('SELECT 1 FROM crm_prospect_suppressions WHERE domain = ?'); $s->execute([$d]); return (bool)$s->fetchColumn(); }
function crm_acq_defaults(): array { return ['country' => '', 'region' => '', 'industry' => '', 'size_band' => '', 'requirements' => '', 'automation_notes' => '', 'services' => '', 'est_value_minor' => null, 'est_currency' => 'EUR', 'est_basis' => '', 'budget_info' => 'unknown', 'urgency' => 'unknown', 'urgency_note' => '', 'sources' => null, 'next_action' => '', 'next_action_at' => null, 'status' => 'identified', 'contact_basis' => 'none', 'contact_basis_note' => '', 'lead_id' => null]; }
/** A prospect with its profile (defaults when it has none, e.g. created before the migration), decoded sources and the transparent score. */
function crm_acq_get(PDO $pdo, int $id): ?array {
  $s = $pdo->prepare('SELECT p.*, u.display_name AS owner_name FROM crm_prospects p LEFT JOIN crm_users u ON u.id = p.owner_id WHERE p.id = ?'); $s->execute([$id]); $p = $s->fetch(PDO::FETCH_ASSOC); if (!$p) return null;
  $pr = []; if (crm_acq_ready($pdo)) { $q = $pdo->prepare('SELECT * FROM crm_prospect_profile WHERE prospect_id = ?'); $q->execute([$id]); $pr = $q->fetch(PDO::FETCH_ASSOC) ?: []; }
  $p = array_merge($p, crm_acq_defaults(), $pr); $p['sources'] = $p['sources'] ? (array)json_decode((string)$p['sources'], true) : []; $p['notes'] = (string)$p['research_notes']; $p['score'] = crm_acq_score($p + ['services' => $p['services']]); $p['suppressed'] = crm_acq_is_suppressed($pdo, (string)$p['website']); return $p;
}
function crm_acq_save_profile(PDO $pdo, int $id, array $c, ?array $sources = null): void {
  $pdo->prepare('INSERT INTO crm_prospect_profile (prospect_id, country, region, industry, size_band, requirements, automation_notes, services, est_value_minor, est_currency, est_basis, budget_info, urgency, urgency_note, sources, next_action, next_action_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE country=VALUES(country), region=VALUES(region), industry=VALUES(industry), size_band=VALUES(size_band), requirements=VALUES(requirements), automation_notes=VALUES(automation_notes), services=VALUES(services), est_value_minor=VALUES(est_value_minor), est_currency=VALUES(est_currency), est_basis=VALUES(est_basis), budget_info=VALUES(budget_info), urgency=VALUES(urgency), urgency_note=VALUES(urgency_note), next_action=VALUES(next_action), next_action_at=VALUES(next_action_at), updated_at=VALUES(updated_at)')
    ->execute([$id, $c['country'], $c['region'], $c['industry'], $c['size_band'], $c['requirements'], $c['automation_notes'], implode(',', $c['services']), $c['est_value_minor'], $c['est_currency'], $c['est_basis'], $c['budget_info'], $c['urgency'], $c['urgency_note'], $sources === null ? null : json_encode($sources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $c['next_action'], $c['next_action_at'], crm_now()]);
}
function crm_acq_history(PDO $pdo, int $id, ?string $from, string $to, string $note, ?array $user): void { $pdo->prepare('INSERT INTO crm_prospect_status_history (prospect_id, from_status, to_status, note, changed_by, changed_at) VALUES (?,?,?,?,?,?)')->execute([$id, $from, $to, crm_line($note, 255), $user['id'] ?? null, crm_now()]); }
/** Creates a company-level prospect with its profile. A prospect whose website domain or normalised name already exists is refused ('duplicate_prospect'). @return array [ok, id|code, ?existingId] */
function crm_acq_create(PDO $pdo, ?array $user, array $in): array {
  if (crm_deny($user, 'write')) return [false, 'forbidden']; if (!crm_acq_ready($pdo)) return [false, 'not_migrated'];
  [$ok, $c] = crm_acq_clean($in); if (!$ok) return [false, (string)$c]; $name = crm_line($in['company'] ?? '', 190); $web = trim((string)($in['website'] ?? ''));
  if ($name === '') return [false, 'company_required']; $dup = crm_acq_find_duplicates($pdo, $name, $web); if ($dup['prospects']) return [false, 'duplicate_prospect', $dup['prospects'][0]['id']];
  $srcs = []; $su = trim((string)($in['source_url'] ?? '')); if ($su !== '') { if (sec_safe_external_url($su) === null) return [false, 'source_url_invalid']; $srcs[] = ['url' => $su, 'label' => crm_line($in['source_label'] ?? '', 80), 'checked' => gmdate('Y-m-d')]; }
  $own = !$pdo->inTransaction(); if ($own) $pdo->beginTransaction();
  try {
    [$k, $id] = crm_prospect_add($pdo, $user, $name, $web, 'development', $c['notes'], $su); if (!$k) { if ($own) $pdo->rollBack(); return [false, (string)$id]; } $id = (int)$id;
    crm_acq_save_profile($pdo, $id, $c, $srcs); if (!empty($in['owner_id'])) $pdo->prepare('UPDATE crm_prospects SET owner_id = ? WHERE id = ?')->execute([(int)$in['owner_id'], $id]);
    crm_acq_history($pdo, $id, null, 'identified', 'Prospect recorded', $user); if (crm_acq_is_suppressed($pdo, $web)) { $pdo->prepare("UPDATE crm_prospect_profile SET status = 'do_not_contact' WHERE prospect_id = ?")->execute([$id]); crm_acq_history($pdo, $id, 'identified', 'do_not_contact', 'Domain is on the suppression list', $user); }
    if ($own) $pdo->commit(); return [true, (string)$id, null];
  } catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); sec_log_exception($e, 'crm_acq_create'); return [false, 'storage_error']; }
}
function crm_acq_update(PDO $pdo, ?array $user, int $id, array $in): array {
  if (crm_deny($user, 'write')) return [false, 'forbidden']; if (!crm_acq_ready($pdo)) return [false, 'not_migrated']; $p = crm_acq_get($pdo, $id); if (!$p) return [false, 'not_found'];
  [$ok, $c] = crm_acq_clean($in); if (!$ok) return [false, (string)$c]; $name = crm_line($in['company'] ?? $p['company_name'], 190); if ($name === '') return [false, 'company_required']; $web = crm_text($in['website'] ?? $p['website'], 255); if ($web !== '' && crm_domain($web) === '') return [false, 'website_invalid'];
  if (crm_contains_personal_contact($name)) return [false, 'personal_data_in_notes']; $dup = crm_acq_find_duplicates($pdo, $name, $web, $id); if ($dup['prospects']) return [false, 'duplicate_prospect', $dup['prospects'][0]['id']];
  $pdo->prepare('UPDATE crm_prospects SET company_name = ?, website = ?, domain = ?, research_notes = ?, owner_id = ? WHERE id = ?')->execute([$name, $web, crm_domain($web), $c['notes'], ($in['owner_id'] ?? '') !== '' ? (int)$in['owner_id'] : $p['owner_id'], $id]);
  crm_acq_save_profile($pdo, $id, $c, null); crm_audit($pdo, $user, 'prospect_update', 'prospect', $id, 'profile updated'); return [true, 'ok'];
}
function crm_acq_add_source(PDO $pdo, ?array $user, int $id, string $url, string $label, string $checked = ''): array {
  if (crm_deny($user, 'write')) return [false, 'forbidden']; if (!crm_acq_ready($pdo)) return [false, 'not_migrated']; $p = crm_acq_get($pdo, $id); if (!$p) return [false, 'not_found'];
  $u = sec_safe_external_url(trim($url)); if ($u === null) return [false, 'source_url_invalid']; if (count($p['sources']) >= CRM_ACQ_MAX_SOURCES) return [false, 'too_many_sources']; $label = crm_line($label, 80); if (crm_contains_personal_contact($label)) return [false, 'personal_data_in_notes'];
  $d = gmdate('Y-m-d'); if (trim($checked) !== '') { $t = crm_date($checked); if ($t === null) return [false, 'date_invalid']; $d = gmdate('Y-m-d', $t); } foreach ($p['sources'] as $s) if (($s['url'] ?? '') === $u) return [false, 'source_exists'];
  $p['sources'][] = ['url' => $u, 'label' => $label, 'checked' => $d];
  $pdo->prepare('INSERT INTO crm_prospect_profile (prospect_id, sources, updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE sources = VALUES(sources), updated_at = VALUES(updated_at)')->execute([$id, json_encode($p['sources'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), crm_now()]); crm_audit($pdo, $user, 'prospect_source', 'prospect', $id, 'public source added'); return [true, 'ok'];
}

// ---- status workflow, suppression, contact permission -------------------------------------------------------------------------------------
function crm_acq_suppress_domain(PDO $pdo, ?array $user, string $website, string $reason): void { $d = crm_domain($website); if ($d === '') return; $pdo->prepare('INSERT IGNORE INTO crm_prospect_suppressions (domain, reason, created_by, created_at) VALUES (?,?,?,?)')->execute([$d, crm_line($reason, 255), $user['id'] ?? null, crm_now()]); }
function crm_acq_set_contact_basis(PDO $pdo, ?array $user, int $id, string $basis, string $note): array {
  if (crm_deny($user, 'write')) return [false, 'forbidden']; if (!crm_acq_ready($pdo)) return [false, 'not_migrated']; if (!isset(crm_acq_contact_bases()[$basis])) return [false, 'basis_invalid']; $p = crm_acq_get($pdo, $id); if (!$p) return [false, 'not_found'];
  $note = crm_line($note, 255); if ($basis !== 'none' && $note === '') return [false, 'basis_note_required']; if (crm_contains_personal_contact($note)) return [false, 'personal_data_in_notes'];
  $pdo->prepare('INSERT INTO crm_prospect_profile (prospect_id, contact_basis, contact_basis_note, updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE contact_basis = VALUES(contact_basis), contact_basis_note = VALUES(contact_basis_note), updated_at = VALUES(updated_at)')->execute([$id, $basis, $note, crm_now()]);
  crm_audit($pdo, $user, 'prospect_contact_basis', 'prospect', $id, $basis); return [true, 'ok'];
}
/** Moves a prospect along the workflow. Contacting needs a recorded permission basis and a company that is not suppressed; declines and suppression need a reason; conversion has its own function. */
function crm_acq_move(PDO $pdo, ?array $user, int $id, string $to, string $note = ''): array {
  if (crm_deny($user, 'write')) return [false, 'forbidden']; if (!crm_acq_ready($pdo)) return [false, 'not_migrated']; $p = crm_acq_get($pdo, $id); if (!$p) return [false, 'not_found'];
  if (!isset(crm_acq_statuses()[$to]) || $to === 'converted_to_lead' || !in_array($to, crm_acq_transitions()[$p['status']] ?? [], true)) return [false, 'transition_invalid']; $note = crm_line($note, 255);
  if (in_array($to, ['not_interested', 'do_not_contact'], true) && $note === '') return [false, 'reason_required']; if (crm_contains_personal_contact($note)) return [false, 'personal_data_in_notes'];
  if ($to === 'contacted') { if ($p['suppressed']) return [false, 'prospect_suppressed']; if ($p['contact_basis'] === 'none') return [false, 'contact_basis_required']; }
  if (in_array($to, ['contact_planned'], true) && $p['suppressed']) return [false, 'prospect_suppressed'];
  $own = !$pdo->inTransaction(); if ($own) $pdo->beginTransaction();
  try {
    $pdo->prepare('INSERT INTO crm_prospect_profile (prospect_id, status, updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = VALUES(updated_at)')->execute([$id, $to, crm_now()]);
    crm_acq_history($pdo, $id, $p['status'], $to, $note, $user);
    if ($to === 'do_not_contact') { crm_acq_suppress_domain($pdo, $user, (string)$p['website'], $note); $pdo->prepare("UPDATE crm_outreach_drafts SET status = 'archived' WHERE prospect_id = ? AND status <> 'archived'")->execute([$id]); }
    if ($to === 'qualified') $pdo->prepare("UPDATE crm_prospects SET fit_status = 'fit' WHERE id = ? AND fit_status = 'unqualified'")->execute([$id]);
    crm_audit($pdo, $user, 'prospect_' . $to, 'prospect', $id, $p['status'] . ' -> ' . $to); if ($own) $pdo->commit(); return [true, 'ok'];
  } catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); sec_log_exception($e, 'crm_acq_move'); return [false, 'storage_error']; }
}
/** Owner-only: removes a domain from the suppression list (the prospect, if any, goes back to "identified"). */
function crm_acq_unsuppress(PDO $pdo, ?array $user, string $domain, string $reason): array {
  if (crm_deny($user, 'approve')) return [false, 'forbidden']; if (!crm_acq_ready($pdo)) return [false, 'not_migrated']; $d = crm_domain($domain); $reason = crm_line($reason, 255); if ($d === '' || $reason === '') return [false, 'reason_required'];
  $s = $pdo->prepare('DELETE FROM crm_prospect_suppressions WHERE domain = ?'); $s->execute([$d]); if (!$s->rowCount()) return [false, 'not_found'];
  foreach ($pdo->query("SELECT p.id FROM crm_prospects p JOIN crm_prospect_profile f ON f.prospect_id = p.id WHERE p.domain = " . $pdo->quote($d) . " AND f.status = 'do_not_contact'")->fetchAll(PDO::FETCH_COLUMN) as $pid) { $pdo->prepare("UPDATE crm_prospect_profile SET status = 'identified', updated_at = ? WHERE prospect_id = ?")->execute([crm_now(), $pid]); crm_acq_history($pdo, (int)$pid, 'do_not_contact', 'identified', 'Suppression lifted: ' . $reason, $user); }
  crm_audit($pdo, $user, 'prospect_unsuppress', 'prospect', null, $d . ': ' . $reason); return [true, 'ok'];
}

// ---- conversion into a CRM lead (exactly once) -------------------------------------------------------------------------------------------
/** Needs a prospect that responded or asked for a consultation, and a contact the company itself gave us (name and business e-mail) plus what they asked for. Creates ONE CRM lead. */
function crm_acq_convert(PDO $pdo, ?array $user, int $id, string $contactName, string $contactEmail, string $message): array {
  if (crm_deny($user, 'write')) return [false, 'forbidden']; if (!crm_acq_ready($pdo)) return [false, 'not_migrated']; $p = crm_acq_get($pdo, $id); if (!$p) return [false, 'not_found'];
  if ($p['lead_id'] !== null) return [false, 'already_converted']; if (!in_array($p['status'], ['responded', 'consultation_requested'], true)) return [false, 'conversion_needs_response']; if ($p['suppressed']) return [false, 'prospect_suppressed'];
  $message = crm_text($message, 4000); if (trim($message) === '') return [false, 'message_required'];
  $own = !$pdo->inTransaction(); if ($own) $pdo->beginTransaction();
  try {
    [$ok, $leadId] = crm_lead_ingest($pdo, ['kind' => 'development', 'name' => $contactName, 'email' => $contactEmail, 'company' => $p['company_name'], 'website' => $p['website'], 'message' => $message, 'budget' => '', 'timeline' => '', 'source' => 'prospect-outreach', 'attr_source' => 'outreach', 'attr_medium' => 'prospect', 'details' => ['prospect_id' => (string)$id, 'services' => (string)$p['services']]]);
    if (!$ok) { if ($own) $pdo->rollBack(); return [false, (string)$leadId]; }
    $u = $pdo->prepare('UPDATE crm_prospect_profile SET lead_id = ?, status = ?, updated_at = ? WHERE prospect_id = ? AND lead_id IS NULL'); $u->execute([(int)$leadId, 'converted_to_lead', crm_now(), $id]); if ($u->rowCount() !== 1) { if ($own) $pdo->rollBack(); return [false, 'already_converted']; }
    crm_acq_history($pdo, $id, $p['status'], 'converted_to_lead', 'Lead #' . (int)$leadId, $user); crm_audit($pdo, $user, 'prospect_convert', 'prospect', $id, 'lead #' . (int)$leadId); if ($own) $pdo->commit(); return [true, (string)$leadId];
  } catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); sec_log_exception($e, 'crm_acq_convert'); return [false, 'storage_error']; }
}

// ---- list / filters -------------------------------------------------------------------------------------------------------------------
/** @param array $f status, country, industry, service, owner, q, due (overdue) */
function crm_acq_list(PDO $pdo, array $f = [], int $limit = 300): array {
  $w = []; $a = []; $ready = crm_acq_ready($pdo); $join = $ready ? ' LEFT JOIN crm_prospect_profile f ON f.prospect_id = p.id' : '';
  if ($ready) {
    if (!empty($f['status']) && isset(crm_acq_statuses()[$f['status']])) { if ($f['status'] === 'identified') $w[] = "COALESCE(f.status, 'identified') = 'identified'"; else { $w[] = 'f.status = ?'; $a[] = $f['status']; } }
    if (!empty($f['country'])) { $w[] = 'f.country = ?'; $a[] = strtoupper(substr((string)$f['country'], 0, 2)); } if (!empty($f['industry']) && isset(crm_acq_industries()[$f['industry']])) { $w[] = 'f.industry = ?'; $a[] = $f['industry']; }
    if (!empty($f['service']) && isset(crm_acq_services()[$f['service']])) { $w[] = 'FIND_IN_SET(?, f.services) > 0'; $a[] = $f['service']; }
    if (!empty($f['due'])) { $w[] = "f.next_action_at < ? AND COALESCE(f.status, 'identified') NOT IN ('converted_to_lead','not_interested','do_not_contact')"; $a[] = gmdate('Y-m-d'); }
  }
  if (!empty($f['owner'])) { $w[] = 'p.owner_id = ?'; $a[] = (int)$f['owner']; } if (!empty($f['q'])) { $w[] = 'p.company_name LIKE ?'; $a[] = '%' . addcslashes(crm_text($f['q'], 80), '%_\\') . '%'; }
  $s = $pdo->prepare('SELECT p.id FROM crm_prospects p' . $join . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY p.id DESC LIMIT ' . max(1, min(500, $limit))); $s->execute($a); $o = [];
  foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) { $p = crm_acq_get($pdo, (int)$id); if ($p) $o[] = $p; } return $o;
}

// ---- dashboard (recorded data only) ----------------------------------------------------------------------------------------------------------
/** Counts come from prospect status, its history and CRM leads. "Contacted / responded / consultation" are cumulative (everyone who ever reached that step, from the history). */
function crm_acq_dashboard(PDO $pdo, ?int $nowTs = null): array {
  $today = gmdate('Y-m-d', $nowTs ?? time()); $total = (int)$pdo->query('SELECT COUNT(*) FROM crm_prospects WHERE is_synthetic = 0')->fetchColumn(); $d = ['ready' => crm_acq_ready($pdo), 'total' => $total];
  if (!$d['ready']) return $d + ['researched' => null, 'qualified' => null, 'awaiting' => null, 'contacted' => null, 'responded' => null, 'consultations' => null, 'converted' => null, 'rate' => null, 'overdue' => null, 'by_status' => [], 'by_industry' => [], 'by_country' => [], 'by_service' => [], 'overdue_list' => []];
  $rows = $pdo->query("SELECT p.id, p.company_name, COALESCE(f.status,'identified') st, COALESCE(f.country,'') country, COALESCE(f.industry,'') industry, COALESCE(f.services,'') services, f.est_value_minor, COALESCE(f.est_currency,'EUR') cur, f.next_action_at, COALESCE(f.next_action,'') na, f.lead_id FROM crm_prospects p LEFT JOIN crm_prospect_profile f ON f.prospect_id = p.id WHERE p.is_synthetic = 0")->fetchAll(PDO::FETCH_ASSOC);
  $ever = fn(string $to) => (int)$pdo->query("SELECT COUNT(DISTINCT h.prospect_id) FROM crm_prospect_status_history h JOIN crm_prospects p ON p.id = h.prospect_id WHERE p.is_synthetic = 0 AND h.to_status = " . $pdo->quote($to))->fetchColumn();
  $by = array_fill_keys(array_keys(crm_acq_statuses()), 0); $ind = []; $cty = []; $svc = []; $over = []; $closed = ['converted_to_lead', 'not_interested', 'do_not_contact']; $mine = ['identified', 'researching', 'qualified', 'contact_planned', 'responded', 'consultation_requested']; $aw = 0;
  foreach ($rows as $r) {
    $by[$r['st']] = ($by[$r['st']] ?? 0) + 1; if (in_array($r['st'], $mine, true)) $aw++;
    if (!in_array($r['st'], $closed, true)) {
      $ik = $r['industry'] ?: '(not recorded)'; $ck = $r['country'] ?: '(not recorded)';
      $ind[$ik] = $ind[$ik] ?? ['n' => 0, 'value' => []]; $ind[$ik]['n']++; $cty[$ck] = $cty[$ck] ?? ['n' => 0, 'value' => []]; $cty[$ck]['n']++;
      if ($r['est_value_minor'] !== null) { $ind[$ik]['value'][$r['cur']] = ($ind[$ik]['value'][$r['cur']] ?? 0) + (int)$r['est_value_minor']; $cty[$ck]['value'][$r['cur']] = ($cty[$ck]['value'][$r['cur']] ?? 0) + (int)$r['est_value_minor']; }
      $list = array_filter(explode(',', $r['services'])) ?: ['(not recorded)']; foreach ($list as $sv) { $svc[$sv] = $svc[$sv] ?? ['n' => 0, 'value' => []]; $svc[$sv]['n']++; if ($r['est_value_minor'] !== null && count($list) === 1) $svc[$sv]['value'][$r['cur']] = ($svc[$sv]['value'][$r['cur']] ?? 0) + (int)$r['est_value_minor']; }
      if ($r['next_action_at'] !== null && $r['next_action_at'] < $today) $over[] = ['id' => (int)$r['id'], 'company' => $r['company_name'], 'due' => $r['next_action_at'], 'action' => $r['na'], 'status' => $r['st']];
    }
  }
  usort($over, fn($a, $b) => strcmp($a['due'], $b['due'])); $contacted = $ever('contacted'); $conv = count(array_filter($rows, fn($r) => $r['lead_id'] !== null)); $res = $pdo->query("SELECT COUNT(*) FROM crm_prospect_profile f JOIN crm_prospects p ON p.id = f.prospect_id WHERE p.is_synthetic = 0 AND f.status IN ('qualified','contact_planned','contacted','responded','consultation_requested','converted_to_lead')")->fetchColumn();
  uasort($ind, fn($a, $b) => $b['n'] <=> $a['n']); uasort($cty, fn($a, $b) => $b['n'] <=> $a['n']); uasort($svc, fn($a, $b) => $b['n'] <=> $a['n']);
  $researched = $total - ($by['identified'] ?? 0);
  return $d + ['researched' => $researched, 'qualified' => $by['qualified'] ?? 0, 'awaiting' => $aw, 'contacted' => $contacted, 'responded' => $ever('responded'), 'consultations' => $ever('consultation_requested'), 'converted' => $conv, 'rate' => $contacted > 0 ? round($conv / $contacted * 100, 1) : null, 'overdue' => count($over), 'by_status' => $by, 'by_industry' => $ind, 'by_country' => $cty, 'by_service' => $svc, 'overdue_list' => array_slice($over, 0, 15), 'qualified_or_later' => (int)$res];
}

// ---- CSV import / export (company-level only; validated; duplicates skipped) ---------------------------------------------------------------------
function crm_acq_csv_columns(): array { return ['company', 'website', 'country', 'region', 'industry', 'company_size', 'services', 'requirements', 'automation_opportunities', 'estimated_value', 'currency', 'estimate_basis', 'research_notes', 'source_url', 'next_action', 'next_action_date']; }
/** Spreadsheet formula injection guard: a cell that starts with = + - @ tab or CR gets a leading apostrophe. */
function crm_acq_csv_cell($v): string { $s = (string)$v; return $s !== '' && strpos("=+-@\t\r", $s[0]) !== false ? "'" . $s : $s; }
function crm_acq_export_csv(PDO $pdo): string {
  $h = ['id', 'company', 'website', 'country', 'region', 'industry', 'company_size', 'services', 'status', 'prospect_owner', 'estimated_value', 'currency', 'estimate_basis', 'value_label', 'priority_score', 'next_action', 'next_action_date', 'public_sources', 'requirements', 'automation_opportunities', 'research_notes'];
  $f = fopen('php://temp', 'w+'); fwrite($f, "\xEF\xBB\xBF"); fputcsv($f, $h, ',', '"', '\\');
  foreach (crm_acq_list($pdo, [], 500) as $p) fputcsv($f, array_map('crm_acq_csv_cell', [$p['id'], $p['company_name'], $p['website'], $p['country'], $p['region'], $p['industry'], $p['size_band'], (string)$p['services'], $p['status'], $p['owner_name'] ?? '', $p['est_value_minor'] === null ? '' : number_format($p['est_value_minor'] / 100, 2, '.', ''), $p['est_value_minor'] === null ? '' : $p['est_currency'], $p['est_basis'], $p['est_value_minor'] === null ? '' : 'Internal estimate, not a quote', $p['score']['score'], $p['next_action'], $p['next_action_at'] ?? '', implode(' | ', array_column($p['sources'], 'url')), (string)$p['requirements'], (string)$p['automation_notes'], $p['notes']]), ',', '"', '\\');
  rewind($f); $o = (string)stream_get_contents($f); fclose($f); return $o;
}
/** @return array{0:bool,1:array|string} [header, rows(assoc)] or an error code */
function crm_acq_csv_parse(string $csv): array {
  if (strlen($csv) > CRM_ACQ_MAX_IMPORT_BYTES) return [false, 'csv_too_large']; $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv; if (!mb_check_encoding($csv, 'UTF-8')) return [false, 'csv_encoding'];
  $first = strtok($csv, "\n") ?: ''; $d = substr_count($first, ';') > substr_count($first, ',') ? ';' : ','; $f = fopen('php://temp', 'w+'); fwrite($f, $csv); rewind($f);
  $head = fgetcsv($f, 0, $d, '"', '\\'); if (!$head) { fclose($f); return [false, 'csv_empty']; } $head = array_map(fn($x) => strtolower(trim(str_replace([' ', '-'], '_', (string)$x))), $head); $rows = []; $line = 1;
  while (($r = fgetcsv($f, 0, $d, '"', '\\')) !== false) { $line++; if ($r === [null] || !array_filter($r, fn($x) => trim((string)$x) !== '')) continue; $a = []; foreach ($head as $i => $k) if ($k !== '') $a[$k] = trim((string)($r[$i] ?? '')); $a['_line'] = $line; $rows[] = $a; if (count($rows) > CRM_ACQ_MAX_IMPORT_ROWS) { fclose($f); return [false, 'csv_too_many_rows']; } }
  fclose($f); return [true, ['header' => $head, 'rows' => $rows]];
}
function crm_acq_resolve(string $v, array $map): string { $v = trim($v); if ($v === '') return ''; if (isset($map[$v])) return $v; foreach ($map as $k => $l) if (strcasecmp($l, $v) === 0 || strcasecmp($k, $v) === 0) return $k; return $v; }
/** Validates every row; with $dry (default) nothing is stored. Rows whose website domain or normalised name already exists (in the database or earlier in the file) are skipped as duplicates. */
function crm_acq_import(PDO $pdo, ?array $user, string $csv, bool $dry = true): array {
  if (crm_deny($user, 'write')) return ['ok' => false, 'error' => 'forbidden']; if (!crm_acq_ready($pdo)) return ['ok' => false, 'error' => 'not_migrated'];
  [$ok, $d] = crm_acq_csv_parse($csv); if (!$ok) return ['ok' => false, 'error' => (string)$d]; if (!in_array('company', $d['header'], true)) return ['ok' => false, 'error' => 'csv_company_column_missing'];
  $res = ['ok' => true, 'dry' => $dry, 'rows' => [], 'created' => 0, 'duplicates' => 0, 'errors' => 0, 'ignored_columns' => array_values(array_diff(array_filter($d['header']), array_merge(crm_acq_csv_columns(), ['id', 'status', 'prospect_owner', 'value_label', 'priority_score', 'public_sources']))) ]; $seenD = []; $seenN = [];
  foreach ($d['rows'] as $r) {
    $name = crm_line($r['company'] ?? '', 190); $line = (int)$r['_line']; $add = function (string $st, string $msg = '') use (&$res, $line, $name) { $res['rows'][] = ['line' => $line, 'company' => $name, 'result' => $st, 'message' => $msg]; if ($st === 'duplicate') $res['duplicates']++; elseif ($st === 'error') $res['errors']++; else $res['created']++; };
    if ($name === '') { $add('error', 'company_required'); continue; }
    $web = trim((string)($r['website'] ?? '')); if ($web !== '' && crm_domain($web) === '') { $add('error', 'website_invalid'); continue; } $dom = crm_domain($web); $norm = crm_acq_norm_name($name);
    if (($dom !== '' && isset($seenD[$dom])) || isset($seenN[$norm])) { $add('duplicate', 'repeated in this file'); continue; }
    $in = ['company' => $name, 'website' => $web, 'country' => $r['country'] ?? '', 'region' => $r['region'] ?? '', 'industry' => crm_acq_resolve((string)($r['industry'] ?? ''), crm_acq_industries()), 'size_band' => crm_acq_resolve((string)($r['company_size'] ?? ''), crm_acq_sizes()),
      'services' => array_values(array_filter(array_map(fn($x) => crm_acq_resolve($x, crm_acq_services()), preg_split('/[;|,]/', (string)($r['services'] ?? '')) ?: []))), 'requirements' => $r['requirements'] ?? '', 'automation_notes' => $r['automation_opportunities'] ?? '', 'est_value' => $r['estimated_value'] ?? '', 'est_currency' => ($r['currency'] ?? '') ?: 'EUR',
      'est_basis' => $r['estimate_basis'] ?? '', 'notes' => $r['research_notes'] ?? '', 'source_url' => $r['source_url'] ?? '', 'next_action' => $r['next_action'] ?? '', 'next_action_at' => $r['next_action_date'] ?? ''];
    [$cok, $c] = crm_acq_clean($in); if (!$cok) { $add('error', (string)$c); continue; } if (trim($in['source_url']) !== '' && sec_safe_external_url($in['source_url']) === null) { $add('error', 'source_url_invalid'); continue; }
    $dup = crm_acq_find_duplicates($pdo, $name, $web); if ($dup['prospects']) { $add('duplicate', 'already a prospect (#' . $dup['prospects'][0]['id'] . ')'); continue; }
    if ($dry) { $add('would_create', $dup['companies'] ? 'note: also an existing CRM company' : ''); } else { $x = crm_acq_create($pdo, $user, $in); if ($x[0]) $add('created', $dup['companies'] ? 'note: also an existing CRM company' : ''); else $add('error', (string)$x[1]); }
    if ($dom !== '') $seenD[$dom] = 1; $seenN[$norm] = 1;
  }
  if (!$dry) crm_audit($pdo, $user, 'prospect_import', 'prospect', null, $res['created'] . ' created, ' . $res['duplicates'] . ' duplicates, ' . $res['errors'] . ' errors'); return $res;
}

// ---- assisted outreach: bilingual service templates; drafts only, never sent -------------------------------------------------------------------
function crm_acq_service_copy(): array {
  return [
    'custom-ai-agents' => ['en' => ['what' => 'custom AI agents that carry out a defined task with your data and tools, with limits, logging and a person in control', 'ask' => 'Would a short call be useful to see whether one of your recurring tasks could be a fit?'], 'de' => ['what' => 'individuelle KI-Agenten, die eine klar definierte Aufgabe mit Ihren Daten und Werkzeugen erledigen, mit Grenzen, Protokollierung und menschlicher Kontrolle', 'ask' => 'Wäre ein kurzes Gespräch sinnvoll, um zu prüfen, ob eine Ihrer wiederkehrenden Aufgaben dafür geeignet ist?']],
    'process-automation' => ['en' => ['what' => 'business process automation that connects existing tools and uses AI only where it adds value, with a clear fallback to a person', 'ask' => 'If you would like, we can look together at one process that costs your team time today.'], 'de' => ['what' => 'Automatisierung von Geschäftsprozessen, die bestehende Werkzeuge verbindet und KI nur dort einsetzt, wo sie Nutzen bringt, mit klarem Rückfall auf einen Menschen', 'ask' => 'Gern schauen wir gemeinsam auf einen Prozess, der Ihr Team heute Zeit kostet.']],
    'ai-chatbots-assistants' => ['en' => ['what' => 'AI chatbots and assistants that answer from approved company knowledge, respect access rules and hand over to a person when unsure', 'ask' => 'Would it help to discuss where an assistant could relieve your support or internal teams?'], 'de' => ['what' => 'KI-Chatbots und Assistenten, die aus freigegebenem Unternehmenswissen antworten, Zugriffsregeln beachten und bei Unsicherheit an einen Menschen übergeben', 'ask' => 'Wäre ein Austausch hilfreich, wo ein Assistent Ihren Support oder interne Teams entlasten könnte?']],
    'custom-software' => ['en' => ['what' => 'custom software solutions built for a specific business process, from a first release to a maintainable product', 'ask' => 'If a process in your company is poorly served by standard software, we would be glad to hear about it.'], 'de' => ['what' => 'individuelle Softwarelösungen für einen konkreten Geschäftsprozess, von der ersten Version bis zu einem wartbaren Produkt', 'ask' => 'Wenn ein Prozess in Ihrem Unternehmen von Standardsoftware schlecht abgedeckt wird, hören wir gern davon.']],
    'enterprise-integrations' => ['en' => ['what' => 'enterprise integrations: reliable connections between your systems and third-party services, including data mapping, retries and monitoring', 'ask' => 'Is there a system hand-off in your company that is still done by hand or breaks regularly?'], 'de' => ['what' => 'Unternehmens-Integrationen: verlässliche Verbindungen zwischen Ihren Systemen und Drittdiensten, inklusive Datenabbildung, Wiederholversuchen und Überwachung', 'ask' => 'Gibt es in Ihrem Unternehmen eine Systemübergabe, die noch von Hand erfolgt oder regelmäßig bricht?']],
    'data-analytics-ai' => ['en' => ['what' => 'data analytics and AI insights: turning your existing data into reports and models your teams can act on, with the assumptions stated', 'ask' => 'Would a short conversation about the questions your data could answer be of interest?'], 'de' => ['what' => 'Datenanalyse und KI-Erkenntnisse: Ihre vorhandenen Daten werden zu Berichten und Modellen, auf die Ihre Teams reagieren können, mit offengelegten Annahmen', 'ask' => 'Wäre ein kurzes Gespräch über die Fragen interessant, die Ihre Daten beantworten könnten?']],
    'cloud-transformation' => ['en' => ['what' => 'cloud and digital transformation: architecture and stepwise modernisation with attention to cost, security and recoverability', 'ask' => 'If a modernisation or cloud move is on your roadmap, we can share how we would approach the first step.'], 'de' => ['what' => 'Cloud und digitale Transformation: Architektur und schrittweise Modernisierung mit Blick auf Kosten, Sicherheit und Wiederherstellbarkeit', 'ask' => 'Steht bei Ihnen eine Modernisierung oder ein Cloud-Umzug an, schildern wir gern, wie wir den ersten Schritt angehen würden.']],
    'ai-consulting-strategy' => ['en' => ['what' => 'AI consulting and strategy: finding where AI is worth using in your business, and where it is not, before anything is built', 'ask' => 'Would an exploratory conversation about your priorities be useful?'], 'de' => ['what' => 'KI-Beratung und Strategie: klären, wo sich KI in Ihrem Unternehmen lohnt und wo nicht, bevor etwas gebaut wird', 'ask' => 'Wäre ein erstes Gespräch über Ihre Prioritäten hilfreich?']],
  ];
}
/** Subject and body for a service and language ('en' or 'de'). {{company}} and {{service}} are filled from the record; {{name}}, {{observation}}, {{sender}} and {{imprint}} stay for a person to complete (a draft cannot be marked reviewed while any remain). */
function crm_acq_template(string $service, string $lang, array $prospect): ?array {
  $c = crm_acq_service_copy()[$service][$lang] ?? null; if ($c === null) return null; $co = (string)($prospect['company_name'] ?? '{{company}}'); $label = crm_acq_services()[$service];
  if ($lang === 'de') return ['subject' => 'Eine Frage an ' . $co . ': ' . $label, 'body' => "Guten Tag {{name}},\n\n{{observation}}\n\nTrustNova Tech unterstützt Unternehmen bei " . $c['what'] . '. ' . $c['ask'] . "\n\nMit freundlichen Grüßen\n{{sender}}\nTrustNova Tech\nImpressum: {{imprint}}\n\nWenn Sie keine weiteren Nachrichten von uns wünschen, antworten Sie einfach mit STOPP. Wir kontaktieren Sie dann nicht mehr."];
  return ['subject' => 'A question for ' . $co . ': ' . $label, 'body' => "Hello {{name}},\n\n{{observation}}\n\nTrustNova Tech helps companies with " . $c['what'] . '. ' . $c['ask'] . "\n\nBest regards\n{{sender}}\nTrustNova Tech\nLegal notice: {{imprint}}\n\nIf you would rather not hear from us again, reply with STOP and we will not contact you again."];
}
function crm_acq_unresolved(string $text): array { preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/i', $text, $m); return array_values(array_unique($m[1])); }
/** A draft for a prospect: refused for companies marked not interested / do not contact or on the suppression list; otherwise the Phase 6 draft rules (opt-out line) apply. Never sends. */
function crm_acq_draft_add(PDO $pdo, ?array $user, int $prospectId, string $subject, string $body): array {
  if (crm_deny($user, 'outreach')) return [false, 'forbidden']; $p = crm_acq_get($pdo, $prospectId); if (!$p) return [false, 'prospect_not_found'];
  if ($p['suppressed'] || in_array($p['status'], ['not_interested', 'do_not_contact'], true)) return [false, 'prospect_suppressed']; return crm_outreach_draft_add($pdo, $user, $subject, $body, $prospectId, null);
}
