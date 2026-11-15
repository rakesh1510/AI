<?php
/**
 * IndexNow submission. DRY RUN by default; nothing is sent without --apply.
 *   php scripts/indexnow.php --init            create a key and write <key>.txt to the web root (then set AIHUB_INDEXNOW_KEY)
 *   php scripts/indexnow.php                   dry run: show how many URLs would be submitted
 *   php scripts/indexnow.php --apply           POST the sitemap URLs to api.indexnow.org (needs the key file to be live on the site)
 *   php scripts/indexnow.php --apply --only=guide,alternatives   limit to URL substrings
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../sitemap_lib.php';
require_once __DIR__ . '/indexnow_lib.php';
$root = dirname(__DIR__);
if (in_array('--init', $argv, true)) {
  $key = indexnow_generate_key();
  file_put_contents("$root/$key.txt", $key);
  echo "Key file written: $key.txt\nSet AIHUB_INDEXNOW_KEY=$key and deploy the file so https://aihubpedia.com/$key.txt serves the key.\n";
  exit(0);
}
$key = (string)getenv('AIHUB_INDEXNOW_KEY');
if (!indexnow_valid_key($key)) { fwrite(STDERR, "Set AIHUB_INDEXNOW_KEY (run --init first).\n"); exit(2); }
$only = []; foreach ($argv as $a) if (strpos($a, '--only=') === 0) $only = array_filter(explode(',', substr($a, 7)));
$urls = array_map(fn($e) => $e['loc'], sitemap_entries());
if ($only) $urls = array_values(array_filter($urls, function ($u) use ($only) { foreach ($only as $o) if (strpos($u, $o) !== false) return true; return false; }));
$payload = indexnow_payload(parse_url(AIHUB_SITE_URL, PHP_URL_HOST), $key, $urls);
echo count($payload['urlList']) . " URLs for host {$payload['host']} (keyLocation {$payload['keyLocation']})\n";
if (!in_array('--apply', $argv, true)) { echo "Dry run. Re-run with --apply to submit.\n"; exit(0); }
$ch = curl_init('https://api.indexnow.org/IndexNow');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "IndexNow responded HTTP $code (200/202 = accepted).\n";
exit(in_array($code, [200, 202], true) ? 0 : 1);
