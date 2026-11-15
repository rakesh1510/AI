<?php
/** IndexNow helpers (https://www.indexnow.org/documentation). Supported by Bing, Yandex, Naver, Seznam and Yep; Google does not use it. */
function indexnow_valid_key(string $key): bool { return (bool)preg_match('/^[a-zA-Z0-9-]{8,128}$/', $key); }
function indexnow_generate_key(): string { return bin2hex(random_bytes(16)); }
/** @param string[] $urls absolute URLs on $host */
function indexnow_payload(string $host, string $key, array $urls): array {
  $urls = array_values(array_unique(array_filter($urls, fn($u) => parse_url($u, PHP_URL_HOST) === $host)));
  return ['host' => $host, 'key' => $key, 'keyLocation' => 'https://' . $host . '/' . $key . '.txt', 'urlList' => array_slice($urls, 0, 10000)];
}
