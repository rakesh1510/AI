<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly (defence in depth if web-server rules are missing)
/**
 * Localisation-ready architecture (Phase 5). English is the only live locale; German, French, Spanish and Hindi are registered as "planned".
 *
 * Rules enforced here and tested in tests/i18n_test.php
 *  - A locale is routable, linked and listed in hreflang only when status is "live" AND the page has a recorded human review for that locale.
 *  - Every live page is self-canonical (canonical never points to another language).
 *  - hreflang sets are reciprocal, include a self reference and an x-default (the default locale), and are omitted entirely when a page has
 *    only one live version (a lone self-referencing hreflang adds nothing).
 *  - A translated page whose text is identical to its source is a duplicate: it must not be indexable.
 *  - No language switcher is shown while fewer than two locales are live.
 */
require_once __DIR__ . '/catalog.php';

function locale_registry(?array $override = null): array {
  $d = $override ?? catalog_json('locales.json', ['default' => 'en', 'locales' => ['en' => ['name' => 'English', 'native' => 'English', 'dir' => 'ltr', 'status' => 'live', 'translation_review' => 'source language']]]);
  $d['locales'] = $d['locales'] ?? [];
  $d['default'] = isset($d['locales'][$d['default'] ?? '']) ? $d['default'] : (array_key_first($d['locales']) ?: 'en');
  return $d;
}
function locale_default(?array $reg = null): string { return locale_registry($reg)['default']; }
function locale_is_live(string $code, ?array $reg = null): bool { return (locale_registry($reg)['locales'][$code]['status'] ?? '') === 'live'; }
function locale_live_codes(?array $reg = null): array { $r = locale_registry($reg); return array_keys(array_filter($r['locales'], fn($l) => ($l['status'] ?? '') === 'live')); }
function locale_dir(string $code, ?array $reg = null): string { return (locale_registry($reg)['locales'][$code]['dir'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr'; }

/** Splits "/de/tool.php" into [locale, "/tool.php"]. Only live locales are recognised; anything else is treated as part of the path. */
function locale_split_path(string $path, ?array $reg = null): array {
  $def = locale_default($reg);
  if (preg_match('#^/([a-z]{2})(/.*)?$#', $path, $m) && locale_is_live($m[1], $reg) && $m[1] !== $def) return [$m[1], ($m[2] ?? '') === '' ? '/' : $m[2]];
  return [$def, $path === '' ? '/' : $path];
}
function locale_path(string $code, string $path, ?array $reg = null): string {
  return $code === locale_default($reg) ? $path : '/' . $code . ($path === '/' ? '/' : $path);
}

/** Reviewed translation record for a page, or null. A record needs a reviewer name and a review date. */
function locale_translation(string $code, string $pageKey, ?array $translations = null): ?array {
  $t = $translations ?? catalog_json('translations.json', ['pages' => []]);
  $r = $t['pages'][$code][$pageKey] ?? null;
  return (is_array($r) && !empty($r['reviewed_by']) && !empty($r['reviewed_on'])) ? $r : null;
}

/** Locales in which a page is publishable: the default plus every live locale with a reviewed translation. */
function locale_page_versions(string $pageKey, ?array $reg = null, ?array $translations = null): array {
  $out = [locale_default($reg)];
  foreach (locale_live_codes($reg) as $c) if ($c !== locale_default($reg) && locale_translation($c, $pageKey, $translations)) $out[] = $c;
  return $out;
}

/** hreflang alternates for a page: [code or 'x-default' => absolute URL]. Empty when the page has a single version. */
function locale_hreflang(string $pageKey, string $baseUrl, ?array $reg = null, ?array $translations = null): array {
  $v = locale_page_versions($pageKey, $reg, $translations);
  if (count($v) < 2) return [];
  $base = rtrim($baseUrl, '/'); $out = [];
  foreach ($v as $c) $out[$c] = $base . locale_path($c, $pageKey, $reg);
  $out['x-default'] = $base . locale_path(locale_default($reg), $pageKey, $reg);
  return $out;
}
function locale_canonical(string $code, string $pageKey, string $baseUrl, ?array $reg = null): string { return rtrim($baseUrl, '/') . locale_path($code, $pageKey, $reg); }

/** Items for a language switcher; empty (no switcher) while fewer than two locales are live or the page has one version. */
function locale_switcher(string $pageKey, ?array $reg = null, ?array $translations = null): array {
  $v = locale_page_versions($pageKey, $reg, $translations);
  if (count($v) < 2) return [];
  $r = locale_registry($reg);
  return array_map(fn($c) => ['code' => $c, 'native' => $r['locales'][$c]['native'] ?? $c, 'path' => locale_path($c, $pageKey, $reg), 'lang' => $c], $v);
}

/**
 * Validates a set of pages as served, for tests and audits. $served = [pageKey => [locale => ['canonical'=>url,'hreflang'=>[..],'text'=>string,'indexable'=>bool]]].
 * @return string[] error messages (empty = valid)
 */
function locale_validate_set(array $served, ?array $reg = null): array {
  $err = []; $def = locale_default($reg);
  foreach ($served as $key => $byLocale) {
    foreach ($byLocale as $loc => $p) {
      if (!locale_is_live($loc, $reg)) $err[] = "$key: locale $loc is not live but is served";
      $expected = rtrim((string)($p['base'] ?? ''), '/') . locale_path($loc, $key, $reg);
      if (($p['canonical'] ?? '') !== $expected) $err[] = "$key [$loc]: canonical must be self-referencing ($expected), got " . ($p['canonical'] ?? 'none');
      $h = $p['hreflang'] ?? [];
      if ($h) {
        if (($h[$loc] ?? '') !== ($p['canonical'] ?? null)) $err[] = "$key [$loc]: hreflang lacks a self reference";
        if (!isset($h['x-default'])) $err[] = "$key [$loc]: hreflang lacks x-default";
        foreach ($h as $code => $url) {
          if ($code === 'x-default') continue;
          if (!isset($byLocale[$code])) $err[] = "$key [$loc]: hreflang points to $code, which is not served";
          elseif (($byLocale[$code]['hreflang'] ?? []) !== $h) $err[] = "$key [$loc]: hreflang set is not reciprocal with $code";
        }
      } elseif (count($byLocale) > 1) $err[] = "$key [$loc]: multiple versions exist but no hreflang";
      if ($loc !== $def && isset($byLocale[$def]) && trim((string)($p['text'] ?? '')) !== '' && trim((string)$p['text']) === trim((string)($byLocale[$def]['text'] ?? '')) && !empty($p['indexable'])) $err[] = "$key [$loc]: text identical to the $def source is indexable (duplicate content)";
    }
  }
  return $err;
}
