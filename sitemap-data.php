<?php
require_once __DIR__ . '/seo.php';

/** Only routes backed by public templates and their published content loaders. */
function seo_sitemap_urls(array $tools, array $posts, array $useCases): array {
    $urls = [];
    foreach ($tools as $tool) {
        if (!empty($tool['status']) && $tool['status'] !== 'published') continue;
        $id = seo_param($tool, 'id');
        if ($id !== '') $urls[] = seo_url('/tool.php', ['id' => $id]);
        $category = seo_param($tool, 'category');
        if ($id !== '' && $category !== '') $urls[] = seo_url('/category.php', ['name' => $category]);
    }
    foreach ($posts as $post) {
        if (!empty($post['status']) && $post['status'] !== 'published') continue;
        $slug = seo_param($post, 'slug');
        if ($slug !== '') $urls[] = seo_url('/post.php', ['slug' => $slug]);
    }
    foreach ($useCases as $slug => $case) {
        if (is_string($slug) && $slug !== '' && is_array($case)) $urls[] = seo_url('/ai-use-case.php', ['slug' => $slug]);
    }
    $urls = array_values(array_unique($urls));
    sort($urls, SORT_STRING);
    return $urls;
}

function seo_sitemap_xml(array $urls, string $type = 'urlset'): string {
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<' . $type . ' xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    $element = $type === 'sitemapindex' ? 'sitemap' : 'url';
    foreach ($urls as $url) {
        $xml .= '  <' . $element . '><loc>' . htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc></' . $element . ">\n";
    }
    return $xml . '</' . $type . ">\n";
}
