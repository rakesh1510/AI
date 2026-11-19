<?php
/**
 * AI Universe read API (Phase 7): public, GET only, verified-and-current data only, JSON.
 * Single router: /universe-api.php?r=<route>. Routes: entities, entity, events, heatmap, graph, lineage, release_diff, compare.
 * Every parameter is validated against a whitelist or an integer range; anything else is a 400 (never a 500, never a stack trace).
 * All data comes from the uni_public_* functions of uni_core.php (verified + current rows, verified + current entities, no identities, origin or notes).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/uni_core.php';

final class UniApiError extends Exception {
  public function __construct(public int $status, public string $errorCode) { parent::__construct($errorCode); }
}

function uapi_headers(): void {
  sec_send_headers();
  header('Content-Type: application/json; charset=utf-8');
  header('X-Robots-Tag: noindex, nofollow');
  header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
  header('Cross-Origin-Resource-Policy: same-origin');
}
/** Sends one JSON response and stops. Successful responses are cacheable and carry an ETag; errors are never cached. */
function uapi_send(int $status, array $payload, bool $cache = false): void {
  uapi_headers();
  $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
  if ($body === false) { $status = 500; $cache = false; $body = '{"error":"encoding_failed"}'; }
  if ($cache && $status === 200) {
    $etag = '"' . substr(hash('sha256', $body), 0, 32) . '"';
    header('Cache-Control: public, max-age=60');
    header('ETag: ' . $etag);
    $inm = (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    if ($inm !== '' && strlen($inm) < 2000 && in_array($etag, array_map(fn($t) => preg_replace('#^W/#', '', trim($t)), explode(',', $inm)), true)) { http_response_code(304); exit; }
  } else {
    header('Cache-Control: no-store');
  }
  http_response_code($status);
  echo $body;
  exit;
}
function uapi_fail(int $status, string $code): void { throw new UniApiError($status, $code); }

/** Validated query parameters: only the names in $allowed (plus r), every value a string, bounded length. */
function uapi_params(array $allowed): array {
  if (strlen((string)($_SERVER['QUERY_STRING'] ?? '')) > 1024) uapi_fail(400, 'query_too_long');
  $out = [];
  foreach ($_GET as $k => $v) {
    $k = (string)$k;
    if ($k === 'r') continue;
    if (!in_array($k, $allowed, true)) uapi_fail(400, 'unknown_parameter');
    if (!is_string($v)) uapi_fail(400, 'parameter_type_invalid');
    if (strlen($v) > 400 || !mb_check_encoding($v, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $v)) uapi_fail(400, 'parameter_invalid');
    $out[$k] = $v;
  }
  return $out;
}
function uapi_int(array $p, string $k, int $min, int $max, int $default): int {
  if (!isset($p[$k]) || $p[$k] === '') return $default;
  if (!preg_match('/^\d{1,9}$/D', $p[$k])) uapi_fail(400, $k . '_invalid');
  $n = (int)$p[$k];
  if ($n < $min || $n > $max) uapi_fail(400, $k . '_out_of_range');
  return $n;
}
function uapi_slug(array $p, string $k, bool $required = true): ?string {
  if (!isset($p[$k]) || $p[$k] === '') { if ($required) uapi_fail(400, $k . '_required'); return null; }
  if (!preg_match('/^[a-z0-9._-]{2,120}$/D', $p[$k])) uapi_fail(400, $k . '_invalid');
  return $p[$k];
}
function uapi_enum(array $p, string $k, array $allowed, ?string $default = null): ?string {
  if (!isset($p[$k]) || $p[$k] === '') return $default;
  if (!in_array($p[$k], $allowed, true)) uapi_fail(400, $k . '_invalid');
  return $p[$k];
}
/** A date as YYYY-MM-DD; with $end a coarser YYYY or YYYY-MM names the last day of that period (so ?to=2024-03 includes March). */
function uapi_date(array $p, string $k, bool $end = false): ?string {
  if (!isset($p[$k]) || $p[$k] === '') return null;
  $d = uni_parse_date($p[$k]); if ($d === null) uapi_fail(400, $k . '_invalid');
  if (!$end) return $d[0];
  if ($d[1] === 'month') return gmdate('Y-m-t', strtotime($d[0] . ' UTC'));
  if ($d[1] === 'year') return substr($d[0], 0, 4) . '-12-31';
  return $d[0];
}
function uapi_page(array $p): array {
  $page = uapi_int($p, 'page', 1, UNI_MAX_PAGE, 1); $per = uapi_int($p, 'per_page', 1, UNI_MAX_PER_PAGE, 25);
  return [$page, $per];
}
/** Slug filter that must name a verified entity (unknown or unverified -> 404, indistinguishable). */
function uapi_known_entity(PDO $pdo, ?string $slug): ?string {
  if ($slug === null) return null;
  if (uni_public_entity($pdo, $slug) === null) uapi_fail(404, 'entity_not_found');
  return $slug;
}

function uapi_route(PDO $pdo, string $route): array {
  switch ($route) {
    case 'entities': {
      $p = uapi_params(['type', 'q', 'page', 'per_page']); [$page, $per] = uapi_page($p);
      $type = uapi_enum($p, 'type', uni_entity_types()); $q = $p['q'] ?? '';
      if ($q !== '' && uni_str($q, 80, false) !== $q) uapi_fail(400, 'q_invalid');
      return uni_public_entities($pdo, ['type' => $type, 'q' => $q], $page, $per);
    }
    case 'entity': {
      $p = uapi_params(['slug']); $e = uni_public_entity_detail($pdo, uapi_slug($p, 'slug'));
      if ($e === null) uapi_fail(404, 'entity_not_found');
      return $e;
    }
    case 'events': {
      $p = uapi_params(['entity', 'type', 'from', 'to', 'page', 'per_page']); [$page, $per] = uapi_page($p);
      $from = uapi_date($p, 'from'); $to = uapi_date($p, 'to', true);
      if ($from !== null && $to !== null && $from > $to) uapi_fail(400, 'range_invalid');
      $slug = uapi_known_entity($pdo, uapi_slug($p, 'entity', false));
      return uni_public_events($pdo, array_filter(['entity_slug' => $slug, 'event_type' => uapi_enum($p, 'type', uni_event_types()), 'from' => $from, 'to' => $to], fn($v) => $v !== null), $page, $per);
    }
    case 'heatmap': {
      $p = uapi_params(['grain', 'from', 'to', 'entity', 'category', 'day']);
      $slug = uapi_known_entity($pdo, uapi_slug($p, 'entity', false)); $cat = uapi_enum($p, 'category', uni_event_types());
      if (isset($p['day']) && $p['day'] !== '') {
        $d = uni_parse_date($p['day']); if ($d === null || $d[1] !== 'day') uapi_fail(400, 'day_invalid');
        return uni_public_day_events($pdo, $d[0], $slug, $cat) ?? uapi_fail(400, 'day_invalid');
      }
      $grain = uapi_enum($p, 'grain', ['day', 'week', 'month'], 'month');
      $to = uapi_date($p, 'to', true) ?? gmdate('Y-m-d');
      $from = uapi_date($p, 'from') ?? gmdate('Y-m-d', strtotime($to . ' UTC') - ($grain === 'month' ? 1826 : 365) * 86400);
      if ($from > $to) uapi_fail(400, 'range_invalid');
      $span = (strtotime($to . ' UTC') - strtotime($from . ' UTC')) / 86400;
      if ($span > ($grain === 'month' ? 36600 : 3700)) uapi_fail(400, 'range_too_wide');
      $h = uni_public_heatmap($pdo, $grain, $from, $to, $slug, $cat) ?? uapi_fail(400, 'parameters_invalid');
      return ['from' => $from, 'to' => $to, 'entity' => $slug, 'category' => $cat] + $h + ['description' => $grain === 'month' ? 'Verified events with day or month precision; year-only events are excluded.' : 'Verified events with day precision only; coarser dates are excluded and counted separately.'];
    }
    case 'graph': {
      $p = uapi_params(['slug', 'limit', 'offset', 'type']);
      $g = uni_public_graph($pdo, uapi_slug($p, 'slug'), uapi_int($p, 'limit', 1, 200, 50), uapi_int($p, 'offset', 0, UNI_MAX_PAGE * UNI_MAX_PER_PAGE, 0), uapi_enum($p, 'type', uni_edge_types()));
      if ($g === null) uapi_fail(404, 'entity_not_found');
      return $g;
    }
    case 'lineage': {
      $p = uapi_params(['slug', 'limit']);
      $l = uni_public_lineage($pdo, uapi_slug($p, 'slug'), uapi_int($p, 'limit', 1, 200, 100));
      if ($l === null) uapi_fail(404, 'entity_not_found');
      return $l;
    }
    case 'release_diff': {
      $p = uapi_params(['slug', 'a', 'b']);
      foreach (['a', 'b'] as $k) if (!isset($p[$k]) || $p[$k] === '' || mb_strlen($p[$k]) > 80) uapi_fail(400, $k . '_invalid');
      $d = uni_public_release_diff($pdo, uapi_slug($p, 'slug'), $p['a'], $p['b']);
      if (isset($d['error'])) uapi_fail($d['error'] === 'same_release' ? 400 : ($d['error'] === 'release_ambiguous' ? 409 : 404), $d['error']);
      return $d;
    }
    case 'compare': {
      $p = uapi_params(['slugs']);
      if (!isset($p['slugs'])) uapi_fail(400, 'slugs_required');
      $slugs = explode(',', $p['slugs']);
      if (count($slugs) > 4) uapi_fail(400, 'slugs_count_invalid');
      foreach ($slugs as $s) if (!preg_match('/^[a-z0-9._-]{2,120}$/D', $s)) uapi_fail(400, 'slugs_invalid');
      if (count(array_unique($slugs)) !== count($slugs) || count($slugs) < 2) uapi_fail(400, 'slugs_count_invalid');
      $c = uni_public_compare($pdo, $slugs);
      if (isset($c['error'])) uapi_fail($c['error'] === 'entity_not_found' ? 404 : 400, $c['error']);
      return $c;
    }
  }
  uapi_fail(404, 'unknown_route');
}

// ---- request ---------------------------------------------------------------------------------------------------------------------------------
try {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { header('Allow: GET'); uapi_fail(405, 'method_not_allowed'); }
  $limit = (int)sec_setting('AIHUB_UNIVERSE_RATE_LIMIT', '', '120'); $limit = max(1, min(10000, $limit));
  if (sec_rate_limited('universe-api', $limit, 60)) { header('Retry-After: 60'); uapi_fail(429, 'rate_limited'); }
  $r = $_GET['r'] ?? null;
  if ($r === null || $r === '') uapi_fail(400, 'route_required');
  if (!is_string($r) || !preg_match('/^[a-z_]{1,20}$/D', $r)) uapi_fail(400, 'route_invalid');
  $routes = ['entities', 'entity', 'events', 'heatmap', 'graph', 'lineage', 'release_diff', 'compare'];
  if (!in_array($r, $routes, true)) uapi_fail(404, 'unknown_route');
  try { $pdo = db(); } catch (Throwable $e) { sec_log_exception($e, 'universe-api db'); uapi_fail(503, 'service_unavailable'); }
  uapi_send(200, ['data' => uapi_route($pdo, $r)], true);
} catch (UniApiError $e) {
  uapi_send($e->status, ['error' => $e->errorCode] + ($e->errorCode === 'route_required' ? ['routes' => ['entities', 'entity', 'events', 'heatmap', 'graph', 'lineage', 'release_diff', 'compare']] : []));
} catch (Throwable $e) {
  sec_log_exception($e, 'universe-api');
  uapi_send($e instanceof PDOException ? 503 : 500, ['error' => $e instanceof PDOException ? 'service_unavailable' : 'internal_error']);
}
