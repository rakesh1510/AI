<?php
require_once __DIR__ . '/helpers.php';

if(PHP_SAPI !== 'cli'){
    http_response_code(403);
    exit("CLI only\n");
}

$slug = trim($argv[1] ?? '');
if($slug === ''){
    fwrite(STDERR, "Usage: php compare_fetch_source.php <tool-slug> [source-url] [source-type]\n");
    exit(1);
}

$tool = find_tool($slug);
if(!$tool){
    fwrite(STDERR, "Tool not found: {$slug}\n");
    exit(1);
}

if(!compare_tables_exist()){
    require __DIR__ . '/compare_install.php';
}

$url = trim($argv[2] ?? ($tool['website'] ?? ''));
$type = trim($argv[3] ?? 'homepage');
if($url === ''){
    fwrite(STDERR, "No source URL available for {$slug}\n");
    exit(1);
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 45,
    CURLOPT_USERAGENT => 'AIHubpedia Compare Bot/1.0',
]);
$html = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
if($html === false){
    $error = curl_error($ch);
    curl_close($ch);
    fwrite(STDERR, "Fetch failed: {$error}\n");
    exit(1);
}
curl_close($ch);

$text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
$text = preg_replace('/\s+/', ' ', $text);
$text = mb_substr($text, 0, 50000);
$hash = hash('sha256', $text);

$pdo = db();
$insert = $pdo->prepare("INSERT INTO compare_tool_sources (tool_slug, source_type, source_url, http_status, content_hash, raw_html, extracted_text, fetched_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
$insert->execute([$slug, $type, $url, $status, $hash, $html, $text]);

$update = $pdo->prepare("UPDATE compare_tool_profiles SET official_website = COALESCE(NULLIF(official_website,''), ?), source_status = ?, source_hash = ?, last_synced_at = NOW() WHERE tool_slug = ?");
$update->execute([$url, $status >= 200 && $status < 400 ? 'fetched' : 'error', $hash, $slug]);

echo "Saved source for {$slug} ({$type}) status={$status}\n";
