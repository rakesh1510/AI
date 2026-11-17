<?php
require_once __DIR__ . '/security.php';
/**
 * Conversion journeys: stateless form tokens, lead capture, newsletter double opt-in, consent-gated events.
 * No cookies are set and no IP address or user-agent string is stored (only a short salted hash used for rate limiting).
 */

// ---------------------------------------------------------------------------
// Stateless form tokens (CSRF + time trap) — no session cookie needed
// ---------------------------------------------------------------------------
function form_secret(): string { return sec_secret(); }

function form_token(string $purpose, ?int $ts = null): string {
  $ts = $ts ?? time();
  return $ts . '.' . substr(hash_hmac('sha256', $purpose . '|' . $ts, form_secret()), 0, 32);
}

/** Valid when signed for this purpose, at least $minAge seconds old (bots submit instantly) and not older than a day. */
function form_token_valid(string $token, string $purpose, int $minAge = 2, ?int $now = null): bool {
  $now = $now ?? time();
  if (!preg_match('/^(\d{9,11})\.([a-f0-9]{32})$/', $token, $m)) return false;
  $ts = (int)$m[1];
  if (!hash_equals(substr(hash_hmac('sha256', $purpose . '|' . $ts, form_secret()), 0, 32), $m[2])) return false;
  $age = $now - $ts;
  return $age >= $minAge && $age <= 86400;
}

function conversion_ip_hash(): string { return sec_ip_hash('rate'); }

// ---------------------------------------------------------------------------
// Leads
// ---------------------------------------------------------------------------
function lead_types(): array {
  return [
    'advertiser' => ['label' => 'Featured listing or homepage spotlight', 'group' => 'advertise'],
    'affiliate_partner' => ['label' => 'Affiliate or referral partnership', 'group' => 'advertise'],
    'newsletter_sponsor' => ['label' => 'Newsletter sponsorship (register interest)', 'group' => 'advertise'],
    'consultation' => ['label' => 'AI consultation', 'group' => 'business'],
    'ai_agent' => ['label' => 'Custom AI agent', 'group' => 'business'],
    'software_dev' => ['label' => 'Custom software development', 'group' => 'business'],
  ];
}
/** Package catalogue for /advertise.php (data/sponsorship_packages.json). Listed prices come from the site configuration, inquiry packages carry no price. */
function sponsorship_packages(): array { return catalog_json('sponsorship_packages.json', ['packages' => []])['packages'] ?? []; }
function lead_goal_options(): array { $g = catalog_json('sponsorship_packages.json', ['goals' => []])['goals'] ?? []; return $g ?: ['' => 'Prefer not to say']; }
function lead_package_options(): array {
  $o = ['' => 'Not sure yet'];
  foreach (sponsorship_packages() as $k => $p) { if (!empty($p['paid'])) $o[$k] = (string)$p['label']; }
  return $o;
}
/** Campaign attribution from the landing URL (utm_* and ref/tool). Values are short slugs; they identify a campaign, never a person. */
function lead_attribution_from_request(array $get): array {
  $slug = fn($v, int $max) => (preg_match('/^[a-z0-9_.:-]{1,' . $max . '}$/', $x = strtolower(trim((string)$v))) ? $x : '');
  return ['attr_source' => $slug($get['utm_source'] ?? ($get['ref'] ?? ''), 40), 'attr_medium' => $slug($get['utm_medium'] ?? '', 40),
          'attr_campaign' => $slug($get['utm_campaign'] ?? '', 60), 'ref_tool' => $slug($get['tool'] ?? '', 80)];
}
function lead_budget_options(): array { return ['' => 'Prefer not to say', 'under-5k' => 'Under $5,000', '5k-20k' => '$5,000 – $20,000', '20k-50k' => '$20,000 – $50,000', 'over-50k' => 'Over $50,000', 'not-sure' => 'Not sure yet']; }
function lead_timeline_options(): array { return ['' => 'Prefer not to say', 'asap' => 'As soon as possible', '1-3-months' => 'Within 1–3 months', '3-6-months' => 'In 3–6 months', 'exploring' => 'Just exploring']; }

/** @return array{errors:array<string,string>,clean:array<string,string>} */
function lead_validate(array $post, array $allowedTypes, ?int $now = null): array {
  $e = []; $c = [];
  $g = fn($k, $max) => mb_substr(trim((string)($post[$k] ?? '')), 0, $max);
  $c['lead_type'] = $g('lead_type', 32);
  if (!in_array($c['lead_type'], $allowedTypes, true) || !isset(lead_types()[$c['lead_type']])) $e['lead_type'] = 'Please choose what you need.';
  $c['name'] = $g('name', 120);
  if (mb_strlen($c['name']) < 2) $e['name'] = 'Please enter your name.';
  $c['email'] = $g('email', 190);
  if (!filter_var($c['email'], FILTER_VALIDATE_EMAIL)) $e['email'] = 'Please enter a valid email address.';
  $c['company'] = $g('company', 190);
  $c['website'] = $g('website', 255);
  if ($c['website'] !== '') {
    if (!preg_match('#^https?://#i', $c['website'])) $c['website'] = 'https://' . $c['website'];
    if (!filter_var($c['website'], FILTER_VALIDATE_URL) || !preg_match('#^https?://[^\s/]+\.[^\s/]+#i', $c['website'])) $e['website'] = 'Please enter a valid website address.';
  }
  $c['budget'] = $g('budget', 40);
  if (!array_key_exists($c['budget'], lead_budget_options())) $c['budget'] = '';
  $c['timeline'] = $g('timeline', 40);
  if (!array_key_exists($c['timeline'], lead_timeline_options())) $c['timeline'] = '';
  $c['package_interest'] = $g('package_interest', 32);
  if (!array_key_exists($c['package_interest'], lead_package_options())) $c['package_interest'] = '';
  $c['campaign_goal'] = $g('campaign_goal', 24);
  if (!array_key_exists($c['campaign_goal'], lead_goal_options())) $c['campaign_goal'] = '';
  $c += lead_attribution_from_request(['utm_source' => $post['attr_source'] ?? '', 'utm_medium' => $post['attr_medium'] ?? '', 'utm_campaign' => $post['attr_campaign'] ?? '', 'tool' => $post['ref_tool'] ?? '']);
  $c['message'] = mb_substr(trim((string)($post['message'] ?? '')), 0, 4000);
  if (mb_strlen($c['message']) < 20) $e['message'] = 'Please describe what you need (at least 20 characters).';
  if (empty($post['consent'])) $e['consent'] = 'Please confirm that we may contact you about this request.';
  $c['source_page'] = preg_match('#^/[a-z0-9._/-]{0,100}$#i', (string)($post['source_page'] ?? '')) ? (string)$post['source_page'] : '';
  if (!empty($post['website_url_confirm'])) $e['_bot'] = 'Spam protection triggered.';   // honeypot
  if (!form_token_valid((string)($post['form_token'] ?? ''), 'lead', 2, $now)) $e['_token'] = 'Your form expired or was submitted too quickly. Please try again.';
  return ['errors' => $e, 'clean' => $c];
}

function lead_rate_limited(PDO $pdo, string $table, string $ipHash, int $max = 5): bool {
  if (!in_array($table, ['leads', 'subscribers'], true)) return true;
  $col = $table === 'leads' ? 'created_at' : 'created_at';
  $s = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE ip_hash = ? AND $col >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
  $s->execute([$ipHash]);
  return (int)$s->fetchColumn() >= $max;
}

/** True when a column exists (cached). Lets the code run before the Phase 4 migration is applied. */
function db_column_exists(PDO $pdo, string $table, string $column): bool {
  static $cache = [];
  $k = $table . '.' . $column;
  if (isset($cache[$k])) return $cache[$k];
  try { $s = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'); $s->execute([$table, $column]); return $cache[$k] = (bool)$s->fetchColumn(); }
  catch (Throwable $e) { return $cache[$k] = false; }
}

function lead_store(PDO $pdo, array $clean, string $ipHash, bool $synthetic = false, string $table = 'leads'): int {
  if (!preg_match('/^leads(_[a-z]+)?$/', $table)) throw new InvalidArgumentException('bad table');
  $extended = db_column_exists($pdo, $table, 'package_interest');
  $message = $clean['message'];
  if (!$extended) {   // migration not applied yet: keep the qualification answers inside the message instead of losing them
    $extra = array_filter(['Package interest' => lead_package_options()[$clean['package_interest'] ?? ''] ?? '', 'Goal' => lead_goal_options()[$clean['campaign_goal'] ?? ''] ?? '', 'Source' => $clean['attr_source'] ?? '', 'Campaign' => $clean['attr_campaign'] ?? '']);
    foreach ($extra as $k => $v) $message .= "\n[$k: $v]";
  }
  $cols = 'created_at, lead_type, name, email, company, website, budget, timeline, message, source_page, consent_at, ip_hash';
  $vals = 'NOW(), :t, :n, :e, :c, :w, :b, :tl, :m, :s, NOW(), :ip';
  $args = [':t' => $clean['lead_type'], ':n' => $clean['name'], ':e' => $clean['email'], ':c' => $clean['company'], ':w' => $clean['website'], ':b' => $clean['budget'], ':tl' => $clean['timeline'], ':m' => $message, ':s' => $clean['source_page'], ':ip' => $ipHash];
  if ($extended) {
    $cols .= ', package_interest, campaign_goal, attr_source, attr_medium, attr_campaign, ref_tool, is_synthetic';
    $vals .= ', :pi, :cg, :as, :am, :ac, :rt, :sy';
    $args += [':pi' => $clean['package_interest'] ?? '', ':cg' => $clean['campaign_goal'] ?? '', ':as' => $clean['attr_source'] ?? '', ':am' => $clean['attr_medium'] ?? '', ':ac' => $clean['attr_campaign'] ?? '', ':rt' => $clean['ref_tool'] ?? '', ':sy' => $synthetic ? 1 : 0];
  }
  $s = $pdo->prepare("INSERT INTO $table ($cols) VALUES ($vals)");
  $s->execute($args);
  return (int)$pdo->lastInsertId();
}

function lead_notify_owner(array $c): bool {
  $to = site_owner_email();
  if ($to === '') return false;
  $label = lead_types()[$c['lead_type']]['label'] ?? $c['lead_type'];
  $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
  $rows = ['Type' => $label, 'Name' => $c['name'], 'Email' => $c['email'], 'Company' => $c['company'], 'Website' => $c['website'], 'Budget' => $c['budget'], 'Timeline' => $c['timeline'], 'Page' => $c['source_page'],
    'Package interest' => lead_package_options()[$c['package_interest'] ?? ''] ?? '', 'Goal' => lead_goal_options()[$c['campaign_goal'] ?? ''] ?? '',
    'Attribution' => trim(($c['attr_source'] ?? '') . ' ' . ($c['attr_medium'] ?? '') . ' ' . ($c['attr_campaign'] ?? '') . ' ' . (!empty($c['ref_tool']) ? 'tool:' . $c['ref_tool'] : ''))];
  $body = '<h2>New AIHubpedia lead</h2>';
  foreach ($rows as $k => $v) if ($v !== '') $body .= '<p><b>' . $h($k) . ':</b> ' . $h($v) . '</p>';
  $body .= '<p><b>Message:</b><br>' . nl2br($h($c['message'])) . '</p>';
  return send_mail($to, '[AIHubpedia lead] ' . $label . ' — ' . $c['name'], $body, '', $c['email']);
}

// ---------------------------------------------------------------------------
// Newsletter (double opt-in). This code stores and confirms subscribers; it does not send newsletter issues.
// ---------------------------------------------------------------------------
function newsletter_consent_text(): string { return 'I agree to receive occasional AIHubpedia emails (new guides and notable price or plan changes). I can unsubscribe at any time.'; }

function newsletter_valid_token(string $t): bool { return (bool)preg_match('/^[a-f0-9]{32}$/', $t); }

/**
 * Create or refresh a pending subscription.
 * @return array{status:string} one of: invalid, rate_limited, created, resend, throttled, already_confirmed
 */
function newsletter_subscribe(PDO $pdo, string $email, string $source, string $ipHash): array {
  $email = mb_strtolower(trim($email));
  if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) return ['status' => 'invalid'];
  if (lead_rate_limited($pdo, 'subscribers', $ipHash, 5)) return ['status' => 'rate_limited'];
  $s = $pdo->prepare('SELECT id, status, token, last_confirmation_sent_at FROM subscribers WHERE email = ?');
  $s->execute([$email]);
  $row = $s->fetch();
  if ($row) {
    if ($row['status'] === 'confirmed') return ['status' => 'already_confirmed'];
    $last = $row['last_confirmation_sent_at'] ? strtotime($row['last_confirmation_sent_at']) : 0;
    if (time() - $last < 600) return ['status' => 'throttled', 'token' => $row['token']];
    $token = $row['token'];
    $pdo->prepare("UPDATE subscribers SET status = 'pending', unsubscribed_at = NULL, last_confirmation_sent_at = NOW() WHERE id = ?")->execute([$row['id']]);
    return ['status' => 'resend', 'token' => $token];
  }
  $token = bin2hex(random_bytes(16));
  $pdo->prepare("INSERT INTO subscribers (email, status, token, created_at, last_confirmation_sent_at, consent_text, source_page, ip_hash) VALUES (?, 'pending', ?, NOW(), NOW(), ?, ?, ?)")
      ->execute([$email, $token, newsletter_consent_text(), substr($source, 0, 120), $ipHash]);
  return ['status' => 'created', 'token' => $token];
}

function newsletter_send_confirmation(string $email, string $token): bool {
  $url = site_url('/newsletter-confirm.php?token=' . $token);
  $html = '<p>Please confirm your subscription to AIHubpedia emails.</p><p><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">Confirm my subscription</a></p><p>If you did not request this, ignore this email and you will not be subscribed.</p>';
  return send_mail($email, 'Confirm your AIHubpedia subscription', $html, "Confirm your subscription: $url\n\nIf you did not request this, ignore this email.");
}

function newsletter_confirm(PDO $pdo, string $token): bool {
  if (!newsletter_valid_token($token)) return false;
  $s = $pdo->prepare("UPDATE subscribers SET status = 'confirmed', confirmed_at = COALESCE(confirmed_at, NOW()), unsubscribed_at = NULL WHERE token = ? AND status IN ('pending','confirmed')");
  $s->execute([$token]);
  return $s->rowCount() > 0 || (bool)$pdo->query("SELECT 1 FROM subscribers WHERE token = " . $pdo->quote($token) . " AND status = 'confirmed'")->fetchColumn();
}

function newsletter_unsubscribe(PDO $pdo, string $token): bool {
  if (!newsletter_valid_token($token)) return false;
  $s = $pdo->prepare("UPDATE subscribers SET status = 'unsubscribed', unsubscribed_at = NOW() WHERE token = ? AND status <> 'unsubscribed'");
  $s->execute([$token]);
  return $s->rowCount() > 0;
}

// ---------------------------------------------------------------------------
// Events (business measurement). Client events need AIHUB_ANALYTICS=1 AND the visitor's opt-in; server events need AIHUB_ANALYTICS=1.
// ---------------------------------------------------------------------------
/** name => allowed property keys (values are validated as short slugs). */
function event_schema(): array {
  return [
    'search_submit' => ['source', 'results'],        // hero | header | results ; result-count bucket
    'suggest_click' => ['kind', 'pos'],              // tool | category | guide ; position
    'outbound_click' => ['affiliate', 'placement'],  // ref = tool id
    'compare_start' => ['n'],
    'compare_open' => [],
    'favorite_add' => [],                            // ref = tool id
    'filter_use' => ['kind'],
    'theme_toggle' => ['theme'],
    'returning_visit' => ['visits'],                 // visit-count bucket
    'newsletter_signup' => ['source'],               // server-side, after a valid request
    'newsletter_confirmed' => [],
    'lead_submit' => [],                             // ref = lead type (server-side)
    'wizard_complete' => ['budget'],                 // server-side; ref = objective key (no personal data)
  ];
}

/** Client-sendable subset (server-only events cannot be forged from the browser). */
function event_client_names(): array { return ['search_submit', 'suggest_click', 'outbound_click', 'compare_start', 'compare_open', 'favorite_add', 'filter_use', 'theme_toggle', 'returning_visit']; }

function event_clean_value($v): string {
  $v = strtolower(trim((string)$v));
  return preg_match('/^[a-z0-9_.:-]{1,40}$/', $v) ? $v : '';
}

/** @return array<string,string>|null normalised row, or null when the event is not acceptable */
function event_normalize(array $in, bool $fromClient = true): ?array {
  $name = (string)($in['name'] ?? '');
  $schema = event_schema();
  if (!isset($schema[$name]) || ($fromClient && !in_array($name, event_client_names(), true))) return null;
  $props = is_array($in['props'] ?? null) ? $in['props'] : [];
  $keys = $schema[$name];
  return [
    'name' => $name,
    'ref' => substr(event_clean_value($in['ref'] ?? ''), 0, 40),
    'prop1' => isset($keys[0]) ? event_clean_value($props[$keys[0]] ?? '') : '',
    'prop2' => isset($keys[1]) ? event_clean_value($props[$keys[1]] ?? '') : '',
    'page_type' => preg_match('/^[a-z_]{1,24}$/', (string)($in['page_type'] ?? '')) ? (string)$in['page_type'] : '',
  ];
}

function event_insert(PDO $pdo, array $row, string $visitorHash = '', bool $returning = false, ?int $ts = null): void {
  $ts = $ts ?? time();
  $pdo->prepare('INSERT INTO events (occurred_at, day, name, page_type, ref, prop1, prop2, visitor_hash, returning_user) VALUES (?,?,?,?,?,?,?,?,?)')
      ->execute([date('Y-m-d H:i:s', $ts), date('Y-m-d', $ts), $row['name'], $row['page_type'] ?? '', $row['ref'] ?? '', $row['prop1'] ?? '', $row['prop2'] ?? '', $visitorHash, $returning ? 1 : 0]);
}

/** Record a server-side business event (no personal data). Never throws. */
function event_record_server(string $name, string $ref = '', string $p1 = '', string $p2 = '', string $pageType = ''): void {
  if (!analytics_enabled()) return;
  try {
    if (!db_table_exists('events')) return;
    $schema = event_schema();
    if (!isset($schema[$name])) return;
    $keys = $schema[$name];
    $row = ['name' => $name, 'ref' => substr(event_clean_value($ref), 0, 40), 'page_type' => $pageType,
      'prop1' => isset($keys[0]) ? event_clean_value($p1) : '', 'prop2' => isset($keys[1]) ? event_clean_value($p2) : ''];
    event_insert(db(), $row);
  } catch (Throwable $e) { /* never affect the request */ }
}

// ---------------------------------------------------------------------------
// Form rendering
// ---------------------------------------------------------------------------
function render_lead_form(array $opts): string {
  $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
  $types = $opts['types'];            // list of lead type keys
  $v = $opts['values'] ?? []; $err = $opts['errors'] ?? [];
  $idp = $opts['id'] ?? 'lead';
  $fe = fn($k) => isset($err[$k]) ? '<p class="fieldError" id="' . $idp . '-' . $k . '-err" role="alert">' . $h($err[$k]) . '</p>' : '';
  $aria = fn($k) => isset($err[$k]) ? ' aria-invalid="true" aria-describedby="' . $idp . '-' . $k . '-err"' : '';
  $o = '<form class="leadForm" method="post" action="/inquiry.php" novalidate>';
  if (!empty($err['_token']) || !empty($err['_bot'])) $o .= '<p class="fieldError" role="alert">' . $h($err['_token'] ?? $err['_bot']) . '</p>';
  $o .= '<input type="hidden" name="form_token" value="' . $h(form_token('lead')) . '"><input type="hidden" name="source_page" value="' . $h($opts['source'] ?? '') . '">';
  $o .= '<div class="hp" aria-hidden="true"><label>Leave this field empty<input type="text" name="website_url_confirm" tabindex="-1" autocomplete="off"></label></div>';
  $att = lead_attribution_from_request($_GET);
  foreach (['attr_source', 'attr_medium', 'attr_campaign', 'ref_tool'] as $ak) $o .= '<input type="hidden" name="' . $ak . '" value="' . $h($v[$ak] ?? $att[$ak]) . '">';
  if (count($types) > 1) {
    $o .= '<div class="field"><label for="' . $idp . '-type">What do you need?</label><select id="' . $idp . '-type" name="lead_type" required' . $aria('lead_type') . '>';
    foreach ($types as $t) $o .= '<option value="' . $h($t) . '"' . (($v['lead_type'] ?? ($opts['default'] ?? '')) === $t ? ' selected' : '') . '>' . $h(lead_types()[$t]['label']) . '</option>';
    $o .= '</select>' . $fe('lead_type') . '</div>';
  } else {
    $o .= '<input type="hidden" name="lead_type" value="' . $h($types[0]) . '">';
  }
  $o .= '<div class="fieldRow"><div class="field"><label for="' . $idp . '-name">Your name</label><input class="input" id="' . $idp . '-name" name="name" autocomplete="name" required value="' . $h($v['name'] ?? '') . '"' . $aria('name') . '>' . $fe('name') . '</div>'
     . '<div class="field"><label for="' . $idp . '-email">Work email</label><input class="input" id="' . $idp . '-email" name="email" type="email" autocomplete="email" required value="' . $h($v['email'] ?? '') . '"' . $aria('email') . '>' . $fe('email') . '</div></div>';
  $o .= '<div class="fieldRow"><div class="field"><label for="' . $idp . '-company">Company <span class="opt">(optional)</span></label><input class="input" id="' . $idp . '-company" name="company" autocomplete="organization" value="' . $h($v['company'] ?? '') . '"></div>'
     . '<div class="field"><label for="' . $idp . '-website">Website <span class="opt">(optional)</span></label><input class="input" id="' . $idp . '-website" name="website" inputmode="url" autocomplete="url" placeholder="https://" value="' . $h($v['website'] ?? '') . '"' . $aria('website') . '>' . $fe('website') . '</div></div>';
  if (!empty($opts['budget'])) {
    $o .= '<div class="fieldRow"><div class="field"><label for="' . $idp . '-budget">Budget <span class="opt">(optional)</span></label><select id="' . $idp . '-budget" name="budget">';
    foreach (lead_budget_options() as $k => $l) $o .= '<option value="' . $h($k) . '"' . (($v['budget'] ?? '') === (string)$k ? ' selected' : '') . '>' . $h($l) . '</option>';
    $o .= '</select></div><div class="field"><label for="' . $idp . '-timeline">Timeline <span class="opt">(optional)</span></label><select id="' . $idp . '-timeline" name="timeline">';
    foreach (lead_timeline_options() as $k => $l) $o .= '<option value="' . $h($k) . '"' . (($v['timeline'] ?? '') === (string)$k ? ' selected' : '') . '>' . $h($l) . '</option>';
    $o .= '</select></div></div>';
  }
  if (!empty($opts['packages'])) {
    $o .= '<div class="fieldRow"><div class="field"><label for="' . $idp . '-package">Package you are interested in <span class="opt">(optional)</span></label><select id="' . $idp . '-package" name="package_interest">';
    foreach (lead_package_options() as $k => $l) $o .= '<option value="' . $h($k) . '"' . (($v['package_interest'] ?? ($opts['package'] ?? '')) === (string)$k ? ' selected' : '') . '>' . $h($l) . '</option>';
    $o .= '</select></div><div class="field"><label for="' . $idp . '-goal">Main goal <span class="opt">(optional)</span></label><select id="' . $idp . '-goal" name="campaign_goal">';
    foreach (lead_goal_options() as $k => $l) $o .= '<option value="' . $h($k) . '"' . (($v['campaign_goal'] ?? '') === (string)$k ? ' selected' : '') . '>' . $h($l) . '</option>';
    $o .= '</select></div></div>';
  }
  $o .= '<div class="field"><label for="' . $idp . '-message">' . $h($opts['message_label'] ?? 'How can we help?') . '</label><textarea class="input" id="' . $idp . '-message" name="message" rows="5" required' . $aria('message') . '>' . $h($v['message'] ?? '') . '</textarea>' . $fe('message') . '</div>';
  $o .= '<div class="field check"><label><input type="checkbox" name="consent" value="1" required' . (!empty($v['consent']) ? ' checked' : '') . $aria('consent') . '> I agree that AIHubpedia may use these details to reply to my request. <a href="/privacy.php">Privacy policy</a></label>' . $fe('consent') . '</div>';
  $o .= '<button class="btn" type="submit">' . $h($opts['button'] ?? 'Send request') . '</button></form>';
  return $o;
}
