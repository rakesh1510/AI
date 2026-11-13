<?php
/** Pure SEO helpers: no database, network requests or writes. */
const SEO_ORIGIN = 'https://aihubpedia.com';

function seo_param(array $params, string $key): string {
    return isset($params[$key]) && is_scalar($params[$key]) ? trim((string)$params[$key]) : '';
}

function seo_url(string $path, array $query = []): string {
    return SEO_ORIGIN . '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
}

function seo_canonical(string $uri): string {
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    if ($path === '/index.php') $path = '/';
    parse_str(parse_url($uri, PHP_URL_QUERY) ?: '', $params);
    $keys = ['/' => ['cat', 'q'], '/tool.php' => ['id'], '/category.php' => ['name'],
        '/compare.php' => ['a', 'b'], '/post.php' => ['slug'], '/ai-use-case.php' => ['slug']];
    $query = [];
    foreach ($keys[$path] ?? array_keys($params) as $key) {
        if (preg_match('/^(utm_|gclid$|fbclid$|msclkid$)/i', $key)) continue;
        $value = seo_param($params, $key);
        if ($value !== '') $query[$key] = $value;
    }
    if ($path === '/' && !empty($query['cat']) && empty($query['q'])) {
        return seo_url('/category.php', ['name' => $query['cat']]);
    }
    if ($path === '/compare.php' && count($query) === 2) {
        $pair = [$query['a'], $query['b']];
        sort($pair, SORT_STRING);
        $query = ['a' => $pair[0], 'b' => $pair[1]];
    }
    ksort($query);
    return seo_url($path, $query);
}

function seo_text($value, string $fallback = ''): string {
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$value)) ?? '');
    return $text !== '' ? $text : $fallback;
}

function seo_json_ld(array $value): string {
    return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
}

function seo_http_url($url): string {
    $url = trim((string)$url);
    return filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true) ? $url : '';
}

function seo_breadcrumb_schema(array $crumbs): array {
    $items = [];
    foreach ($crumbs as $crumb) {
        $items[] = ['@type' => 'ListItem', 'position' => count($items) + 1, 'name' => $crumb['name'], 'item' => $crumb['url']];
    }
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
}

function seo_breadcrumbs(array $crumbs): void {
    echo '<nav aria-label="Breadcrumb"><ol class="seoBreadcrumbs">';
    foreach ($crumbs as $i => $crumb) {
        echo '<li>';
        if ($i === count($crumbs) - 1) echo '<span aria-current="page">' . h($crumb['name']) . '</span>';
        else echo '<a href="' . h($crumb['url']) . '">' . h($crumb['name']) . '</a>';
        echo '</li>';
    }
    echo '</ol></nav>';
}

function seo_default_robots(string $uri, int $status): string {
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    parse_str(parse_url($uri, PHP_URL_QUERY) ?: '', $params);
    if ($status >= 400 || seo_param($params, 'q') !== '' || preg_match('#/(admin|login|bookmarks|analytics-dashboard|affiliate-dashboard|multilingual-demo|dark-mode-demo|comparisons|index\d+|tool\d+|compare\d+)\.php$#', $path)
        || strpos($path, '/compare/') === 0) return 'noindex,follow';
    return 'index,follow,max-image-preview:large';
}

/** Only explicitly reviewed, actually published translations may be supplied. */
function seo_language_links(array $translations): array {
    $links = [];
    foreach ($translations as $language => $url) {
        if (!preg_match('/^(?:[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*|x-default)$/D', $language)) continue;
        if (seo_http_url($url) !== '' && strpos($url, SEO_ORIGIN . '/') === 0) $links[$language] = $url;
    }
    return $links;
}
