<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly (defence in depth if web-server rules are missing)
require_once __DIR__ . '/security.php';
/**
 * First-party, privacy-preserving traffic measurement.
 *
 * - Disabled unless AIHUB_ANALYTICS=1 (and the page_views table exists: database/phase2_analytics.sql).
 * - No cookies, no raw IP addresses, no user-agent strings stored. Do-Not-Track / Global Privacy Control requests are counted
 *   as page views but get no visitor hash.
 * - Bots are classified by user agent (heuristic; spoofable). Treat counts as an estimate and cross-check with Search Console
 *   and server logs. AI crawlers and AI-app referrals are recorded separately.
 * - Recording happens after the response is built and can never break a page.
 */

function analytics_enabled(): bool { return getenv('AIHUB_ANALYTICS') === '1'; }

/** @return array{is_bot:bool,type:string,name:string} */
function analytics_classify_agent(string $ua): array {
  $ua = trim($ua);
  if ($ua === '') return ['is_bot' => true, 'type' => 'other_bot', 'name' => 'empty-ua'];
  $groups = [
    'ai_crawler' => ['GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-SearchBot', 'Claude-User', 'anthropic-ai', 'PerplexityBot', 'Perplexity-User', 'CCBot', 'Bytespider', 'Amazonbot', 'meta-externalagent', 'cohere-ai', 'DuckAssistBot', 'MistralAI-User', 'YouBot', 'Google-Extended', 'Applebot-Extended'],
    'search_crawler' => ['Googlebot', 'Google-InspectionTool', 'AdsBot-Google', 'bingbot', 'BingPreview', 'DuckDuckBot', 'Applebot', 'YandexBot', 'Baiduspider', 'Slurp', 'Qwantify', 'SeznamBot', 'Bravebot'],
    'seo_tool' => ['AhrefsBot', 'SemrushBot', 'MJ12bot', 'DotBot', 'PetalBot', 'DataForSeoBot', 'Screaming Frog', 'BLEXBot'],
    'social_preview' => ['facebookexternalhit', 'Twitterbot', 'LinkedInBot', 'Slackbot', 'WhatsApp', 'TelegramBot', 'Discordbot', 'Pinterestbot'],
  ];
  foreach ($groups as $type => $names) {
    foreach ($names as $n) { if (stripos($ua, $n) !== false) return ['is_bot' => true, 'type' => $type, 'name' => $n]; }
  }
  if (preg_match('/bot\b|crawl|spider|scrape|headless|lighthouse|pagespeed|curl\/|wget|python-requests|python-urllib|httpclient|libwww|go-http-client|okhttp\/|java\/|monitor|uptime|check_http|phantomjs|puppeteer|playwright/i', $ua)) {
    return ['is_bot' => true, 'type' => 'other_bot', 'name' => 'generic'];
  }
  return ['is_bot' => false, 'type' => '', 'name' => ''];
}

/** @return array{class:string,host:string} */
function analytics_classify_referrer(string $referrer, string $ownHost = 'aihubpedia.com', string $utmSource = ''): array {
  $host = strtolower((string)parse_url($referrer, PHP_URL_HOST));
  $host = preg_replace('/^www\./', '', $host);
  $own = preg_replace('/^www\./', '', strtolower($ownHost));
  // AI apps may identify themselves with utm_source even when the referrer header is stripped.
  $utm = strtolower($utmSource);
  $aiMap = ['chatgpt.com' => 'ai_chatgpt', 'chat.openai.com' => 'ai_chatgpt', 'perplexity.ai' => 'ai_perplexity', 'claude.ai' => 'ai_claude', 'gemini.google.com' => 'ai_gemini', 'copilot.microsoft.com' => 'ai_copilot', 'copilot.cloud.microsoft' => 'ai_copilot'];
  foreach ($aiMap as $d => $class) {
    if ($host === $d || ($host !== '' && substr($host, -strlen($d) - 1) === '.' . $d)) return ['class' => $class, 'host' => $host];
    if ($utm !== '' && ($utm === $d || $utm === preg_replace('/\..*$/', '', $d))) return ['class' => $class, 'host' => $utm];
  }
  if ($host === '') return ['class' => 'direct', 'host' => ''];
  if ($host === $own) return ['class' => 'internal', 'host' => $host];
  $rules = [
    'search_google' => '/(^|\.)google\.[a-z.]+$/', 'search_bing' => '/(^|\.)bing\.com$/', 'search_duckduckgo' => '/(^|\.)duckduckgo\.com$/',
    'search_yahoo' => '/(^|\.)yahoo\.[a-z.]+$/', 'search_brave' => '/(^|\.)search\.brave\.com$/',
    'search_other' => '/(^|\.)(yandex\.[a-z.]+|baidu\.com|ecosia\.org|startpage\.com|qwant\.com|naver\.com|seznam\.cz)$/',
    'social' => '/(^|\.)(facebook\.com|t\.co|twitter\.com|x\.com|linkedin\.com|lnkd\.in|reddit\.com|youtube\.com|news\.ycombinator\.com|pinterest\.[a-z.]+|instagram\.com|tiktok\.com|bsky\.app|threads\.net)$/',
  ];
  foreach ($rules as $class => $re) { if (preg_match($re, $host)) return ['class' => $class, 'host' => $host]; }
  return ['class' => 'other', 'host' => $host];
}

function analytics_page_type(string $script): string {
  $base = basename($script);
  $map = ['index.php' => 'home', 'tool.php' => 'tool', 'category.php' => 'category', 'categories.php' => 'category_index', 'compare.php' => 'compare', 'comparisons.php' => 'compare_index',
    'guide.php' => 'guide', 'guides.php' => 'guide_index', 'alternatives.php' => 'alternatives', 'post.php' => 'post', 'blog.php' => 'blog', 'top.php' => 'top', 'leaderboard.php' => 'leaderboard',
    'methodology.php' => 'methodology', '404.php' => 'not_found', 'ai-use-case.php' => 'use_case', 'ai-use-case-explorer.php' => 'use_case_index'];
  return $map[$base] ?? 'other';
}

/** True only when AIHUB_ANALYTICS_SALT is a secret of at least 32 characters. There is no default salt: without one no visitor hash exists. */
function analytics_salt_ok(): bool { $s = getenv('AIHUB_ANALYTICS_SALT'); return is_string($s) && strlen($s) >= 32 && stripos($s, 'default') === false; }   // the former public placeholder contains 'default' and is rejected
function analytics_visitor_hash(string $ip, string $ua, string $period): string {
  if (!analytics_salt_ok()) return '';   // missing or weak salt: never record a visitor hash (a public default would make hashes reversible)
  $salt = (string)getenv('AIHUB_ANALYTICS_SALT');
  return substr(hash('sha256', $salt . '|' . $period . '|' . $ip . '|' . $ua), 0, 16);
}

/** Period used for the rotating hash: 'day' (default) or 'week' (set AIHUB_ANALYTICS_HASH_WINDOW=week for weekly uniques). */
function analytics_hash_period(int $ts): string {
  return getenv('AIHUB_ANALYTICS_HASH_WINDOW') === 'week' ? date('o-\WW', $ts) : date('Y-m-d', $ts);
}

/** Build the row to store for the current request (pure function of its inputs, easy to test). */
function analytics_build_row(array $server, array $get, int $status, int $ts, string $path): array {
  $ua = (string)($server['HTTP_USER_AGENT'] ?? '');
  $agent = analytics_classify_agent($ua);
  $ref = analytics_classify_referrer((string)($server['HTTP_REFERER'] ?? ''), (string)($server['HTTP_HOST'] ?? 'aihubpedia.com'), (string)($get['utm_source'] ?? ''));
  $optOut = (($server['HTTP_DNT'] ?? '') === '1') || (($server['HTTP_SEC_GPC'] ?? '') === '1');
  $country = strtoupper(substr((string)($server['HTTP_CF_IPCOUNTRY'] ?? ($server['HTTP_X_COUNTRY_CODE'] ?? '')), 0, 2));
  if (!preg_match('/^[A-Z]{2}$/', $country)) $country = 'XX';
  $ip = sec_client_ip($server);
  return [
    'viewed_at' => date('Y-m-d H:i:s', $ts), 'day' => date('Y-m-d', $ts), 'path' => substr($path, 0, 190),
    'page_type' => analytics_page_type((string)($server['SCRIPT_NAME'] ?? '')), 'status' => $status,
    'referrer_class' => $ref['class'], 'referrer_host' => substr($ref['host'], 0, 120), 'country' => $country,
    'visitor_hash' => ($agent['is_bot'] || $optOut) ? '' : analytics_visitor_hash($ip, $ua, analytics_hash_period($ts)),
    'is_bot' => $agent['is_bot'] ? 1 : 0, 'bot_type' => $agent['type'], 'bot_name' => substr($agent['name'], 0, 40),
  ];
}

function analytics_insert(PDO $pdo, array $row): void {
  $cols = array_keys($row);
  $stmt = $pdo->prepare('INSERT INTO page_views (' . implode(',', $cols) . ') VALUES (' . implode(',', array_map(fn($c) => ':' . $c, $cols)) . ')');
  $stmt->execute($row);
}

/** Register a shutdown hook that records the current request. Safe to call multiple times. */
function analytics_track_request(): void {
  static $registered = false;
  if ($registered || !analytics_enabled() || PHP_SAPI === 'cli') return;
  $registered = true;
  register_shutdown_function(function () {
    try {
      if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
      if (!db_table_exists('page_views')) return;
      $status = http_response_code();
      // Missing pages: record the URL that was requested (path only, never the query string) so broken links are findable.
      $path = ($status === 404 && basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === '404.php')
        ? (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH)
        : rel_url(seo_default_canonical());
      analytics_insert(db(), analytics_build_row($_SERVER, $_GET, is_int($status) ? $status : 200, time(), $path));
    } catch (Throwable $e) {
      // Analytics must never affect the page.
    }
  });
}
