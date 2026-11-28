<?php
// DB-free checks for the premium UI redesign. Usage: php tests/premium_ui_test.php [baseUrl]
chdir(__DIR__ . '/..');
require_once 'helpers.php'; require_once 'ui.php'; require_once 'crm_intake.php'; require_once 'conversion.php';
$p = 0; $f = 0; function ck($ok, $m) { global $p, $f; if ($ok) $p++; else { $f++; echo "FAIL $m\n"; } }
// nav
$labels = array_column(site_nav_primary(), 1);
ck($labels === ['Discover', 'Compare', 'Guides', 'For Business', 'Advertise'], 'primary nav labels');
ck(site_nav_cta()[1] === 'Book a Free Consultation', 'CTA label');
$all = json_encode([site_nav_primary(), site_nav_more(), site_nav_cta()]);
ck(stripos($all, 'universe') === false && stripos($all, 'heatmap') === false && stripos($all, 'battle') === false, 'no Universe links in nav');
foreach (['layout_bottom.php'] as $file) ck(stripos(file_get_contents($file), 'universe') === false, "$file has no Universe link");
ck(stripos(file_get_contents('contact.php'), 'Ad placeholder') === false, 'contact placeholder removed');
// cta_click event accepted
ck(array_key_exists('cta_click', event_schema()) && in_array('cta_click', event_client_names(), true), 'cta_click event registered');
// project form validation: only goal, name, email, consent required
$t0 = time() - 100;
$ok = ['name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'message' => 'We need an API integration between our CRM and billing system.', 'consent' => '1', 'form_token' => form_token('lead', $t0), 'website_url_confirm' => ''];
$r = project_validate($ok, time());
ck(empty($r['errors'] ?? $r) || (isset($r['errors']) && !$r['errors']), 'valid short form (no company/service) accepted: ' . json_encode($r));
$bad = project_validate(['name' => '', 'email' => 'nope', 'message' => 'short', 'form_token' => form_token('lead', $t0)], time());
$be = $bad['errors'] ?? $bad;
ck(isset($be['email']) && isset($be['name']), 'invalid form rejected with field errors: ' . json_encode($be));
$bot = project_validate(array_merge($ok, ['website_url_confirm' => 'x']), time());
ck(!empty($bot['errors'] ?? $bot), 'honeypot rejected');
// v2 illustration helpers: original inline SVG, no external references
foreach (['art_orb', 'art_agent', 'art_flow'] as $fn) { $svg = $fn(); ck(strpos($svg, '<svg') === 0 && stripos($svg, 'http') === false || strpos($svg, 'xmlns') === false, "$fn is self-contained SVG"); ck(stripos($svg, '<script') === false && stripos($svg, 'href=') === false, "$fn has no scripts or links"); }
ck(strpos(art_thumb('Chatbots', 2), 'artThumb') !== false && strpos(art_placement('featured'), 'SPONSORED') !== false, 'thumb and placement preview render');
ck(cat_theme('Audio')[2] === 'mic' && count(cat_theme('Nope')) === 3, 'cat_theme returns a theme for known and unknown categories');
$lt = logo_tile(['name' => 'Zed <b>', 'category' => 'Coding', 'id' => 'nonexistent-x'], 'md');
ck(strpos($lt, '<b>Z</b>') !== false && strpos($lt, 'Zed <b>') === false, 'logo_tile escapes and shows a monogram fallback');
// reveal fallback: hidden state only under reveal-on
$css = file_get_contents('assets/css/premium.css') . file_get_contents('assets/css/ui.css');
ck(!preg_match('/html\.js\s+\.reveal\s*\{[^}]*opacity:\s*0/', $css), 'no JS-only hiding without reveal-on');
echo "premium ui php: $p passed, $f failed\n"; exit($f ? 1 : 0);
