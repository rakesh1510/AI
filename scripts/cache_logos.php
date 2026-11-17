<?php
/**
 * Optional, owner-run step: downloads each tool's favicon ONCE and stores it under assets/logos/ so pages stop requesting Google's
 * favicon service for every visitor (privacy, speed). tool_logo() then serves the local file automatically.
 * Usage (on a build machine with outbound HTTPS, never from the web): php scripts/cache_logos.php [--limit=50] [--force]
 * Safety: only public http(s) domains from the catalogue; 64 KB max per icon; the content is verified to be an image before saving;
 * files are named after the validated tool id. Nothing is downloaded unless you run this script.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../helpers.php';
$limit = 100000; $force = in_array('--force', $argv, true);
foreach ($argv as $a) if (strpos($a, '--limit=') === 0) $limit = max(1, (int)substr($a, 8));
$dir = dirname(__DIR__) . '/assets/logos'; if (!is_dir($dir)) mkdir($dir, 0755, true);
$done = 0; $skipped = 0; $failed = 0;
foreach (tools_by_id() as $id => $t) {
  if ($done >= $limit) break;
  if (!preg_match('/^[a-z0-9._-]{1,80}$/i', (string)$id)) continue;
  if (!$force && (is_file("$dir/$id.png") || is_file("$dir/$id.webp"))) { $skipped++; continue; }
  $dom = tool_domain((string)($t['website'] ?? '')); if (!$dom || sec_safe_external_url('https://' . $dom . '/') === null) { $failed++; continue; }
  $ch = curl_init('https://www.google.com/s2/favicons?domain=' . urlencode($dom) . '&sz=128');
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXFILESIZE => 65536, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
  $img = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  $info = $img !== '' ? @getimagesizefromstring($img) : false;
  if ($code !== 200 || !$info || !in_array($info[2], [IMAGETYPE_PNG], true) || strlen($img) > 65536) { $failed++; continue; }
  file_put_contents("$dir/$id.png", $img); $done++;
  usleep(150000);
}
echo "Saved $done logo(s), skipped $skipped existing, $failed failed or unavailable.\n";
