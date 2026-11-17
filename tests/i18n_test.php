<?php
/** Localisation architecture tests (Phase 5): registry, routing, hreflang, canonicals, duplicate content. No network. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/helpers.php';
require_once $root . '/sitemap_lib.php';
$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "  FAIL: $m\n"; } }
function section(string $t): void { echo "== $t\n"; }
$base = 'https://example.test';

section('Shipped configuration: English only');
$reg = locale_registry();
ok($reg['default'] === 'en' && locale_live_codes() === ['en'], 'only English is live');
foreach (['de', 'fr', 'es', 'hi'] as $c) ok(($reg['locales'][$c]['status'] ?? '') === 'planned' && !locale_is_live($c), "$c is planned, not live");
ok(catalog_json('translations.json', ['pages' => ['x' => 1]])['pages'] === [], 'no translation has been reviewed');
ok(locale_split_path('/de/tool.php') === ['en', '/de/tool.php'], 'planned locales are not routed');
ok(locale_hreflang('/about.php', $base) === [] && locale_switcher('/about.php') === [], 'no hreflang and no language switcher while only English is live');
ok(locale_dir('en') === 'ltr', 'English is left-to-right');
$xml = sitemap_xml(sitemap_entries());
ok(!preg_match('#<loc>[^<]*/(de|fr|es|hi)/#', $xml) && strpos($xml, 'hreflang') === false, 'the sitemap lists no locale URLs and no hreflang');
$demo = file_get_contents($root . '/multilingual-demo.php');
ok(!preg_match('/Entdecke|Descubre|Deutsch:|Español:/u', $demo), 'the retired demo page no longer publishes machine translations');
ob_start(); $_SERVER['REQUEST_URI'] = '/about.php'; $_SERVER['HTTP_HOST'] = 'localhost'; require $root . '/about.php'; $page = ob_get_clean();
ok(strpos($page, '<html lang="en" dir="ltr"') !== false && strpos($page, 'rel="alternate" hreflang') === false, 'pages declare lang="en" and emit no hreflang');

section('Routing and URL strategy (synthetic registry)');
$synth = ['default' => 'en', 'locales' => [
  'en' => ['native' => 'English', 'dir' => 'ltr', 'status' => 'live'], 'de' => ['native' => 'Deutsch', 'dir' => 'ltr', 'status' => 'live'],
  'fr' => ['native' => 'Français', 'dir' => 'ltr', 'status' => 'planned'], 'ar' => ['native' => 'العربية', 'dir' => 'rtl', 'status' => 'live']]];
$tr = ['pages' => ['de' => ['/about.php' => ['reviewed_by' => 'Reviewer', 'reviewed_on' => '2026-11-17'], '/tool.php' => ['reviewed_by' => '', 'reviewed_on' => '2026-11-17']], 'fr' => ['/about.php' => ['reviewed_by' => 'R', 'reviewed_on' => '2026-11-17']]]];
ok(locale_split_path('/de/about.php', $synth) === ['de', '/about.php'] && locale_split_path('/de/', $synth) === ['de', '/'] && locale_split_path('/de', $synth) === ['de', '/'], 'live locale prefixes are recognised');
ok(locale_split_path('/fr/about.php', $synth)[0] === 'en', 'planned locale prefixes are not recognised');
ok(locale_split_path('/en/about.php', $synth) === ['en', '/en/about.php'], 'the default locale has no prefix (no duplicate /en/ URLs)');
ok(locale_path('de', '/about.php', $synth) === '/de/about.php' && locale_path('en', '/about.php', $synth) === '/about.php' && locale_path('de', '/', $synth) === '/de/', 'paths map to prefixed URLs');
ok(locale_dir('ar', $synth) === 'rtl', 'right-to-left locales are supported');
ok(locale_page_versions('/about.php', $synth, $tr) === ['en', 'de'], 'a live locale with a reviewed translation is a version');
ok(locale_page_versions('/tool.php', $synth, $tr) === ['en'], 'a translation without a named reviewer is not publishable');
ok(locale_page_versions('/about.php', $synth, $tr) !== ['en', 'de', 'fr'], 'a planned locale is never a version even with a record');
$h = locale_hreflang('/about.php', $base, $synth, $tr);
ok($h === ['en' => "$base/about.php", 'de' => "$base/de/about.php", 'x-default' => "$base/about.php"], 'hreflang has both versions and x-default');
ok(locale_hreflang('/tool.php', $base, $synth, $tr) === [], 'a single-version page has no hreflang');
ok(count(locale_switcher('/about.php', $synth, $tr)) === 2 && locale_switcher('/tool.php', $synth, $tr) === [], 'the switcher appears only where a translation exists');
ok(locale_canonical('de', '/about.php', $base, $synth) === "$base/de/about.php", 'canonical of a translation is itself');

section('Validator catches cross-language canonical and duplicate-content errors');
$good = fn() => ['/about.php' => [
  'en' => ['base' => $base, 'canonical' => "$base/about.php", 'hreflang' => $h, 'text' => 'About us', 'indexable' => true],
  'de' => ['base' => $base, 'canonical' => "$base/de/about.php", 'hreflang' => $h, 'text' => 'Über uns', 'indexable' => true]]];
ok(locale_validate_set($good(), $synth) === [], 'a correct set validates');
$s = $good(); $s['/about.php']['de']['canonical'] = "$base/about.php";
ok(count(array_filter(locale_validate_set($s, $synth), fn($e) => strpos($e, 'self-referencing') !== false)) === 1, 'a translation canonicalised to English is rejected');
$s = $good(); $s['/about.php']['en']['canonical'] = "$base/de/about.php";
ok(count(array_filter(locale_validate_set($s, $synth), fn($e) => strpos($e, 'self-referencing') !== false)) === 1, 'an English page canonicalised to German is rejected');
$s = $good(); $s['/about.php']['de']['hreflang'] = ['de' => "$base/de/about.php", 'x-default' => "$base/about.php"];
ok(count(array_filter(locale_validate_set($s, $synth), fn($e) => strpos($e, 'reciprocal') !== false)) >= 1, 'non-reciprocal hreflang is rejected');
$s = $good(); unset($s['/about.php']['de']['hreflang']['x-default']); $s['/about.php']['en']['hreflang'] = $s['/about.php']['de']['hreflang'];
ok(count(array_filter(locale_validate_set($s, $synth), fn($e) => strpos($e, 'x-default') !== false)) >= 1, 'missing x-default is rejected');
$s = $good(); $s['/about.php']['de']['hreflang'] = []; $s['/about.php']['en']['hreflang'] = [];
ok(count(array_filter(locale_validate_set($s, $synth), fn($e) => strpos($e, 'no hreflang') !== false)) === 2, 'multiple versions without hreflang are rejected');
$s = $good(); $s['/about.php']['de']['text'] = 'About us';
ok(count(array_filter(locale_validate_set($s, $synth), fn($e) => strpos($e, 'duplicate content') !== false)) === 1, 'an untranslated indexable copy is flagged as duplicate content');
$s = $good(); $s['/about.php']['de']['text'] = 'About us'; $s['/about.php']['de']['indexable'] = false;
ok(locale_validate_set($s, $synth) === [], 'the same copy is acceptable when it is noindex');
$s = $good(); $s['/about.php']['fr'] = $s['/about.php']['de'];
ok(count(array_filter(locale_validate_set($s, $synth), fn($e) => strpos($e, 'not live') !== false)) >= 1, 'serving a planned locale is rejected');
$s = $good(); $s['/about.php']['de']['hreflang']['es'] = "$base/es/about.php"; $s['/about.php']['en']['hreflang']['es'] = "$base/es/about.php";
ok(count(array_filter(locale_validate_set($s, $synth), fn($e) => strpos($e, 'not served') !== false)) >= 1, 'hreflang to an unserved language is rejected');

section('Data hygiene');
ok(!preg_match('/\b(machine|auto)[- ]translat/i', file_get_contents($root . '/data/translations.json')) , 'translation store does not claim machine translations');
echo "\nPASSED: $pass  FAILED: $fail\n"; exit($fail ? 1 : 0);
