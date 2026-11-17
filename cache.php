<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly (defence in depth if web-server rules are missing)
/**
 * Small, safe derived-data cache (Phase 4.1).
 *
 * What is cached: results that are expensive to derive from the whole catalogue and identical for every visitor (ranked tools per problem
 * page and the tool-to-problem links built from them). Nothing personal is ever cached; recommendation-wizard results are not cached.
 *
 * Correct invalidation: an entry is valid only while ALL of these match:
 *   1. the catalogue fingerprint: size+mtime of every data file that feeds ranking (tools, curated profiles, classification, taxonomy,
 *      synonyms, solutions, capabilities), plus CHECKSUM TABLE of the database tools table when the catalogue comes from MySQL;
 *   2. the code fingerprint: size+mtime of the ranking code (discovery, evidence, editorial, cache), so a deployment invalidates it;
 *   3. a time-to-live (default 15 minutes), because the click-popularity boost changes continuously.
 * Failures are never fatal: unreadable, corrupt, unwritable or disabled (AIHUB_CACHE=0) caches simply fall back to computing live.
 * Files live outside the web root (AIHUB_CACHE_DIR, default <system temp>/aihub_cache_<uid>), are written atomically and hold no personal data.
 */
const AIHUB_CACHE_SCHEMA = 1;
const AIHUB_CACHE_TTL = 900;

function cache_enabled(): bool { return sec_setting('AIHUB_CACHE', 'cache', '1') !== '0'; }
function cache_dir(): string {   // a configured directory that is not private to this user falls back to the per-user default
  $c = sec_setting('AIHUB_CACHE_DIR', 'cache_dir');
  if ($c !== '' && ($d = sec_private_dir($c)) !== '') return $d;
  return sec_private_dir(sec_default_private_dir('aihub_cache'));
}

/** @param string[]|null $files absolute paths (tests pass temporary files) */
function catalog_fingerprint(?array $files = null, ?string $dbChecksum = null): string {
  $dir = __DIR__ . '/data/';
  $files = $files ?? array_map(fn($f) => $dir . $f, ['tools.json', 'curated_profiles.json', 'classification.json', 'taxonomy.json', 'search_synonyms.json', 'solutions.json', 'capabilities.json', 'editorial_review_log.json']);
  $parts = [];
  foreach ($files as $f) { $parts[] = basename($f) . ':' . (is_file($f) ? filesize($f) . ':' . filemtime($f) . ':' . crc32((string)file_get_contents($f)) : 'missing'); }
  if ($dbChecksum === null && function_exists('mysql_tables_available_for_content') && mysql_tables_available_for_content()) {
    try { $r = db()->query('CHECKSUM TABLE tools')->fetch(PDO::FETCH_NUM); $dbChecksum = (string)($r[1] ?? ''); } catch (Throwable $e) { $dbChecksum = 'error'; }
  }
  $parts[] = 'db:' . ($dbChecksum ?? 'none');
  return substr(sha1(implode('|', $parts)), 0, 20);
}

function code_fingerprint(?array $files = null): string {
  $files = $files ?? array_map(fn($f) => __DIR__ . '/' . $f, ['discovery.php', 'evidence.php', 'editorial.php', 'editorial_review.php', 'cache.php', 'catalog.php']);
  $p = ['schema' . AIHUB_CACHE_SCHEMA];
  foreach ($files as $f) $p[] = basename($f) . ':' . (is_file($f) ? filesize($f) . ':' . filemtime($f) : 'missing');
  return substr(sha1(implode('|', $p)), 0, 12);
}

/**
 * Read-through cache. $compute must return a JSON-serialisable value.
 * @return array{0:mixed,1:string} [value, 'hit'|'miss'|'off']
 */
function cache_remember(string $name, callable $compute, ?int $ttl = null, ?int $now = null, ?string $catFp = null, ?string $codeFp = null): array {
  if (!cache_enabled() || !preg_match('/^[a-z0-9_-]{1,40}$/', $name) || cache_dir() === '') return [$compute(), 'off'];
  $now = $now ?? time(); $ttl = $ttl ?? AIHUB_CACHE_TTL;
  static $fpMemo = null;   // the catalogue cannot change during one request
  $catFp = $catFp ?? ($fpMemo['cat'] ?? ($fpMemo['cat'] = catalog_fingerprint())); $codeFp = $codeFp ?? ($fpMemo['code'] ?? ($fpMemo['code'] = code_fingerprint()));
  $path = cache_dir() . '/' . $name . '-' . $catFp . '-' . $codeFp . '.json';
  if (is_file($path) && ($raw = @file_get_contents($path)) !== false) {
    $e = json_decode($raw, true);
    if (is_array($e) && ($e['schema'] ?? null) === AIHUB_CACHE_SCHEMA && ($e['catalog'] ?? '') === $catFp && ($e['code'] ?? '') === $codeFp && isset($e['at']) && $now - (int)$e['at'] <= $ttl && array_key_exists('value', $e)) return [$e['value'], 'hit'];
  }
  $value = $compute();
  $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
  if (@file_put_contents($tmp, json_encode(['schema' => AIHUB_CACHE_SCHEMA, 'catalog' => $catFp, 'code' => $codeFp, 'at' => $now, 'value' => $value]), LOCK_EX) !== false) {
    if (!@rename($tmp, $path)) @unlink($tmp);
    foreach ((array)@glob(cache_dir() . '/' . $name . '-*.json') as $old) { if ($old !== $path && @filemtime($old) < $now - 3600) @unlink($old); }   // drop entries for older catalogue/code versions
  }
  return [$value, 'miss'];
}

function cache_clear(): int { $n = 0; foreach ((array)@glob(cache_dir() . '/*.json') as $f) { if (@unlink($f)) $n++; } return $n; }
