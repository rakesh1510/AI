<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
/**
 * Public side of the affiliate engine (Phase 7E): the ONE place where an owner-approved affiliate link replaces a tool's outbound target.
 * Every existing mechanism (the /redirect.php outbound click, the "Affiliate link" disclosure on the tool page, the affiliate flag in click analytics) already
 * reads $tool['affiliate'], so overlaying that field here changes nothing else. Without the table, without an active link, or on any error the tool keeps
 * its previous behaviour: the affiliate URL set in tool management, else the official website. Never throws.
 */
const AFF_PUBLIC_TTL = 120;
function aff_final_url(string $url, string $trackingId, string $subidParam): string {
  $url = trim($url); $trackingId = trim($trackingId); $subidParam = trim($subidParam);
  if ($trackingId === '' || !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $subidParam)) return $url;
  $frag = ''; if (($h = strpos($url, '#')) !== false) { $frag = substr($url, $h); $url = substr($url, 0, $h); }
  return $url . (strpos($url, '?') !== false ? '&' : '?') . $subidParam . '=' . rawurlencode($trackingId) . $frag;
}
/** @return array<string,string> tool slug => final affiliate URL, for links that are active AND whose partnership is approved or active. Cached for AFF_PUBLIC_TTL seconds. */
function aff_public_active_map(?PDO $pdo = null, bool $fresh = false): array {
  static $memo = null; if ($memo !== null && !$fresh && $pdo === null) return $memo; $file = null;
  try {
    if ($pdo === null) {
      if (!function_exists('db_connection_available') || !db_connection_available() || !function_exists('db_table_exists') || !db_table_exists('aff_link')) return $memo = [];
      if (!$fresh && function_exists('cache_dir') && ($d = cache_dir()) !== '') { $file = $d . '/aff_active_links.json'; if (is_file($file) && ($j = json_decode((string)@file_get_contents($file), true)) && isset($j['at']) && time() - (int)$j['at'] <= AFF_PUBLIC_TTL && is_array($j['map'] ?? null)) return $memo = $j['map']; }
      $pdo = db();
    }
    $map = []; $s = $pdo->query("SELECT l.tool_slug, l.url, l.tracking_id, COALESCE(n.subid_param, '') AS subid FROM aff_link l JOIN aff_partner p ON p.id = l.partner_id LEFT JOIN aff_network n ON n.id = p.network_id WHERE l.status = 'active' AND p.status IN ('approved', 'active')");
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) { $u = aff_final_url((string)$r['url'], (string)$r['tracking_id'], (string)$r['subid']); if (stripos($u, 'https://') === 0 && function_exists('sec_safe_external_url') && sec_safe_external_url($u) !== null) $map[(string)$r['tool_slug']] = $u; }
    if ($file !== null) { @file_put_contents($file . '.tmp', json_encode(['at' => time(), 'map' => $map]), LOCK_EX); @rename($file . '.tmp', $file); }
    return $memo = $map;
  } catch (Throwable $e) { return $memo = []; }
}
function aff_public_cache_clear(): void { try { if (function_exists('cache_dir') && ($d = cache_dir()) !== '') @unlink($d . '/aff_active_links.json'); } catch (Throwable $e) {} }
/** Called from catalog_overlay_tool(): sets $tool['affiliate'] to the approved active link, if there is one. */
function aff_overlay_tool(array $tool): array { $id = (string)($tool['id'] ?? $tool['slug'] ?? ''); if ($id === '') return $tool; $m = aff_public_active_map(); if (isset($m[$id])) { $tool['affiliate'] = $m[$id]; $tool['affiliate_managed'] = true; } return $tool; }
