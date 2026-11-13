<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/sitemap-data.php';
header('Content-Type: application/xml; charset=utf-8');
try {
    $data = load_tools();
    $urls = seo_sitemap_urls($data['tools'] ?? [], load_posts(), load_use_cases());
    // Fail visibly rather than publish a truncated or invalid sitemap.
    // At 45,000 URLs, replace the root index with static shard entries before growth reaches the limit.
    if (count($urls) > 45000) throw new RuntimeException('Sitemap sharding required');
    $xml = seo_sitemap_xml($urls);
    if (strlen($xml) > 45000000) throw new RuntimeException('Sitemap size limit approached');
    echo $xml;
} catch (Throwable $e) {
    http_response_code(503);
    header('Retry-After: 3600');
    echo seo_sitemap_xml([]);
}
