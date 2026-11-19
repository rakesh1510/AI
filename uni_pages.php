<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
/**
 * Shared helpers for the public AI Universe pages (Phase 7): universe-heatmap.php, universe-lineage.php, universe-arena.php.
 * READ ONLY. Data comes exclusively from the uni_public_* functions of uni_core.php (verified, current rows of verified, current entities; no identities, notes or origin).
 * Every value is escaped with up_h() at output. Parameters are validated against whitelists and ranges; invalid input is a 4xx page, never a 500.
 * A missing ledger schema (uni_* tables not installed) behaves exactly like an empty ledger: empty states, noindex, no errors.
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/uni_core.php';

function up_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/**
 * Runs $fn($pdo) and classifies the outcome. Returns ['ok', result] | ['empty', null] (schema not installed) | ['down', null] (database unavailable).
 */
function up_guard(callable $fn): array {
  try {
    if (!db_connection_available()) return ['down', null];
    return ['ok', $fn(db())];
  } catch (PDOException $e) {
    $state = (string)$e->getCode();
    if (in_array($state, ['42S02', '42S22'], true)) return ['empty', null];
    sec_log_exception($e, 'universe page');
    return ['down', null];
  } catch (Throwable $e) {
    sec_log_exception($e, 'universe page');
    return ['down', null];
  }
}

/** Cheap abuse guard shared by the three pages: 429 with Retry-After when one client asks for too many pages. */
function up_rate_limit(): bool {
  $limit = max(30, min(10000, (int)sec_setting('AIHUB_UNIVERSE_PAGE_RATE_LIMIT', '', '600')));
  return sec_rate_limited('universe-pages', $limit, 60);
}

// ---- parameters ------------------------------------------------------------------------------------------------------------------------

/**
 * Reads whitelisted query parameters. @return array [params (strings), error code or '']. Unknown parameters are ignored (the layout marks such URLs noindex);
 * arrays, control characters, invalid UTF-8 and over-long values are errors.
 */
function up_params(array $allowed): array {
  if (strlen((string)($_SERVER['QUERY_STRING'] ?? '')) > 2048) return [[], 'query_too_long'];
  $out = [];
  foreach ($_GET as $k => $v) {
    $k = (string)$k;
    if (!in_array($k, $allowed, true)) continue;
    if (!is_string($v)) return [[], 'parameter_type_invalid'];
    if (strlen($v) > 400 || !mb_check_encoding($v, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $v)) return [[], 'parameter_invalid'];
    if ($v !== '') $out[$k] = $v;
  }
  return [$out, ''];
}
function up_slug_ok($s): bool { return is_string($s) && preg_match('/^[a-z0-9._-]{2,120}$/D', $s) === 1; }
/** A strict YYYY-MM-DD day (calendar-valid, 1900..2100) or null. */
function up_day($s): ?string { if (!is_string($s)) return null; $d = uni_parse_date($s, 'day'); return $d === null ? null : $d[0]; }
function up_month($s): ?string { if (!is_string($s) || !preg_match('/^\d{4}-\d{2}$/D', $s)) return null; $d = uni_parse_date($s, 'month'); return $d === null ? null : substr($d[0], 0, 7); }
function up_year($s): ?int { if (!is_string($s) || !preg_match('/^\d{4}$/D', $s)) return null; $y = (int)$s; return $y >= 1990 && $y <= 2100 ? $y : null; }
function up_ts(string $day): int { return strtotime($day . ' 00:00:00 UTC'); }
function up_add_days(string $day, int $n): string { return gmdate('Y-m-d', up_ts($day) + $n * 86400); }
function up_monday(string $day): string { $w = (int)gmdate('N', up_ts($day)); return up_add_days($day, 1 - $w); }
function up_url(string $script, array $params): string {
  $p = array_filter($params, fn($v) => $v !== null && $v !== '');
  return '/' . $script . ($p ? '?' . http_build_query($p, '', '&', PHP_QUERY_RFC3986) : '');
}

// ---- labels ----------------------------------------------------------------------------------------------------------------------------

function up_event_type_label(string $t): string {
  return ['model_release' => 'Model release', 'product_release' => 'Product release', 'major_update' => 'Major update', 'integration' => 'Integration', 'pricing_change' => 'Pricing change', 'launch' => 'Launch', 'acquisition' => 'Acquisition', 'announcement' => 'Announcement'][$t] ?? ucfirst(str_replace('_', ' ', $t));
}
function up_entity_type_label(string $t): string { return ['company' => 'Company', 'product' => 'Product', 'model' => 'Model', 'version' => 'Version'][$t] ?? ucfirst($t); }
/** Short verb phrase of a relationship, read from the "from" entity to the "to" entity. */
function up_edge_phrase(string $t): string {
  return ['derived_from' => 'is derived from', 'built_on' => 'is built on', 'successor_of' => 'is the successor of', 'renamed_from' => 'was renamed from', 'developed_by' => 'is developed by', 'integrates_with' => 'integrates with',
    'hosted_on' => 'is hosted on', 'acquired_by' => 'is acquired by', 'version_of' => 'is a version of'][$t] ?? str_replace('_', ' ', $t);
}
function up_kind_label(string $kind): string { return ['technical_ancestry' => 'Technical ancestry', 'product_succession' => 'Product succession', 'release_parent' => 'Documented release parent'][$kind] ?? $kind; }
function up_date_long(string $date, string $precision): string {
  $ts = up_ts($date);
  if ($precision === 'year') return gmdate('Y', $ts) . ' (year only)';
  if ($precision === 'month') return gmdate('F Y', $ts) . ' (month only)';
  return gmdate('F j, Y', $ts);
}
function up_time_tag(string $date, string $precision): string {
  $iso = $precision === 'year' ? substr($date, 0, 4) : ($precision === 'month' ? substr($date, 0, 7) : $date);
  return '<time datetime="' . up_h($iso) . '">' . up_h(up_date_long($date, $precision)) . '</time>';
}
function up_iso_partial(string $date, string $precision): string { return $precision === 'year' ? substr($date, 0, 4) : ($precision === 'month' ? substr($date, 0, 7) : $date); }

// ---- fragments -------------------------------------------------------------------------------------------------------------------------

function up_na(string $what = ''): string { return '<span class="upNa">Unavailable: no verified data' . ($what !== '' ? ' <span class="sr">for ' . up_h($what) . '</span>' : '') . '</span>'; }
function up_http_url(string $u): bool { return preg_match('#^https?://[^\s<>"]+$#i', $u) === 1 && strlen($u) <= 2000; }
/** Source links with publisher and retrieval date. Non-http(s) URLs are shown as text, never as links. */
function up_sources_html(array $sources, string $class = 'upSources'): string {
  if (!$sources) return '<p class="upNa">Unavailable: no source recorded</p>';
  $o = '<ul class="' . up_h($class) . '">';
  foreach ($sources as $s) {
    $url = (string)($s['url'] ?? ''); $pub = trim((string)($s['publisher'] ?? '')); $label = $pub !== '' ? $pub : $url;
    $o .= '<li>' . (up_http_url($url) ? '<a href="' . up_h($url) . '" rel="nofollow noopener noreferrer" target="_blank">' . up_h($label) . '<span class="sr"> (opens in a new tab)</span></a>' : '<span>' . up_h($label) . '</span>');
    $ret = (string)($s['retrieved_at'] ?? '');
    if ($ret !== '') $o .= ' <span class="upMeta">retrieved <time datetime="' . up_h(substr($ret, 0, 10)) . '">' . up_h(substr($ret, 0, 10)) . '</time></span>';
    $o .= '</li>';
  }
  return $o . '</ul>';
}
/** A stored JSON value as short text: scalars as is, lists joined, objects as compact JSON; bounded. */
function up_value_text($v): string {
  if ($v === null) return 'none';
  if (is_bool($v)) return $v ? 'yes' : 'no';
  if (is_scalar($v)) $t = (string)$v;
  elseif (is_array($v) && array_is_list($v) && !array_filter($v, fn($x) => !is_scalar($x))) $t = implode(', ', array_map(fn($x) => is_bool($x) ? ($x ? 'yes' : 'no') : (string)$x, $v));
  elseif (is_array($v) && !array_is_list($v) && !array_filter($v, fn($x) => !is_scalar($x))) { $parts = []; foreach ($v as $k => $x) $parts[] = $k . ': ' . (is_bool($x) ? ($x ? 'yes' : 'no') : (string)$x); $t = implode('; ', $parts); }
  else $t = (string)json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
  return mb_strlen($t) > 400 ? mb_substr($t, 0, 399) . '…' : $t;
}
function up_page_script(string $name): string { return '<script src="/assets/js/universe.js?v=' . up_h((string)(@filemtime(__DIR__ . '/assets/js/universe.js') ?: 1)) . '" defer></script>'; }
function up_head_extra(): string { return '<link rel="stylesheet" href="/assets/css/universe.css?v=' . up_h((string)(@filemtime(__DIR__ . '/assets/css/universe.css') ?: 1)) . '">'; }
function up_crumbs(array $items): array {
  $list = []; foreach ($items as $i => [$name, $path]) $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => site_url($path)];
  return ['@type' => 'BreadcrumbList', 'itemListElement' => $list];
}
/** A short error page body used for 4xx/5xx answers: always one h1, a reset link and no echo of raw input. */
function up_error_body(string $heading, string $message, string $backHref, string $backLabel): string {
  return '<section class="upError" role="alert"><h1>' . up_h($heading) . '</h1><p>' . up_h($message) . '</p><p><a class="btn" href="' . up_h($backHref) . '">' . up_h($backLabel) . '</a></p></section>';
}
function up_error_text(string $code): string {
  return ['parameter_invalid' => 'One of the values in the address is not valid, so nothing was loaded.', 'parameter_type_invalid' => 'One of the values in the address is not valid, so nothing was loaded.', 'query_too_long' => 'The address is too long.',
    'range_invalid' => 'The start date must not be after the end date.', 'range_too_wide' => 'That date range is too wide.', 'date_invalid' => 'A date in the address is not a valid date.', 'entity_not_found' => 'No verified entity matches that address.',
    'count_invalid' => 'Choose between two and four different entities.', 'duplicate' => 'Choose different entities: each one can only be compared once.', 'same_release' => 'Choose two different releases to compare.', 'release_not_found' => 'No verified release matches that choice.',
    'release_ambiguous' => 'That version label matches more than one release. Pick the releases from the list instead.'][$code] ?? 'The request could not be processed.';
}

/** Verified entities for filter lists and selectors (bounded to 300; the flag says whether more exist). @return array [items, truncated] */
function up_entities_all(PDO $pdo): array {
  $items = []; $total = 0;
  for ($page = 1; $page <= 3; $page++) {
    $r = uni_public_entities($pdo, [], $page, 100); $total = $r['total']; foreach ($r['items'] as $it) $items[] = $it;
    if (!$r['has_more']) break;
  }
  return [$items, $total > count($items)];
}
/** Every verified release (bounded to 500) as rows of uni_public_releases, used to find the families that have a release history. */
function up_all_releases(PDO $pdo, ?string $slug = null, int $cap = 500): array {
  $out = [];
  for ($page = 1; count($out) < $cap; $page++) {
    $r = uni_public_releases($pdo, $slug === null ? [] : ['entity_slug' => $slug], $page, 100);
    foreach ($r['items'] as $it) $out[] = $it;
    if (!$r['has_more']) break;
  }
  return array_slice($out, 0, $cap);
}

/**
 * Entries for the sitemap: only URLs that have verified data. @return array of [path, changefreq, priority]
 */
function up_sitemap_entries(): array {
  $res = up_guard(function (PDO $pdo) {
    $e = [];
    if (uni_public_entities($pdo, [], 1, 1)['total'] > 0 || uni_public_events($pdo, [], 1, 1)['total'] > 0) $e[] = ['/universe.php', 'weekly', '0.6'];
    $ev = uni_public_events($pdo, [], 1, 1);
    if ($ev['total'] > 0) $e[] = ['/universe-heatmap.php', 'weekly', '0.6'];
    $rels = up_all_releases($pdo, null, 500); $seen = [];
    foreach ($rels as $r) $seen[$r['entity_slug']] = true;
    if ($seen) { $e[] = ['/universe-lineage.php', 'weekly', '0.6']; foreach (array_keys($seen) as $s) $e[] = ['/universe-lineage.php?entity=' . rawurlencode((string)$s), 'weekly', '0.5']; }
    if (uni_public_entities($pdo, [], 1, 1)['total'] >= 2) $e[] = ['/universe-arena.php', 'weekly', '0.6'];
    return $e;
  });
  return $res[0] === 'ok' ? $res[1] : [];
}
