<?php
require_once __DIR__ . '/helpers.php';
$title = "Compare AI Tools — AIHubpedia";
$data = load_tools();
$tools = $data['tools'] ?? [];

$a = trim($_GET['a'] ?? '');
$b = trim($_GET['b'] ?? '');
if ($a === '' && isset($_GET['id'])) $a = trim($_GET['id']);
$ta = $a ? find_tool($a) : null;
$tb = $b ? find_tool($b) : null;

// ============================================================
// LIVE DATA — cache table + fetch layer
// ============================================================

/**
 * Ensure the tool_live_data table exists. Called once per request.
 */
function live_data_ensure_table(): bool {
    if (!db_connection_available()) return false;
    static $created = null;
    if ($created !== null) return $created;
    try {
        $pdo = db();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `tool_live_data` (
                `id`                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `tool_id`                  VARCHAR(190) NOT NULL,
                `source_url`               VARCHAR(1000) NOT NULL DEFAULT '',
                `extracted_text`           MEDIUMTEXT,
                `detected_features_json`   TEXT,
                `detected_integrations_json` TEXT,
                `detected_use_cases_json`  TEXT,
                `pricing_signal`           VARCHAR(100) DEFAULT NULL,
                `confidence_score`         FLOAT NOT NULL DEFAULT 0,
                `last_checked_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_tool_id` (`tool_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        return $created = true;
    } catch (Throwable $e) {
        return $created = false;
    }
}

/**
 * Return cached live data row for $toolId, or null if missing / stale.
 */
function live_data_get(string $toolId): ?array {
    if (!live_data_ensure_table()) return null;
    try {
        $pdo  = db();
        $stmt = $pdo->prepare("SELECT * FROM tool_live_data WHERE tool_id = ? LIMIT 1");
        $stmt->execute([$toolId]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        // 7-day TTL
        $age = time() - strtotime($row['last_checked_at']);
        if ($age > 604800) return null; // stale
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Persist (upsert) live data for a tool.
 */
function live_data_save(string $toolId, array $payload): void {
    if (!live_data_ensure_table()) return;
    try {
        $pdo  = db();
        $stmt = $pdo->prepare("
            INSERT INTO tool_live_data
                (tool_id, source_url, extracted_text, detected_features_json,
                 detected_integrations_json, detected_use_cases_json,
                 pricing_signal, confidence_score, last_checked_at)
            VALUES (?,?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE
                source_url               = VALUES(source_url),
                extracted_text           = VALUES(extracted_text),
                detected_features_json   = VALUES(detected_features_json),
                detected_integrations_json = VALUES(detected_integrations_json),
                detected_use_cases_json  = VALUES(detected_use_cases_json),
                pricing_signal           = VALUES(pricing_signal),
                confidence_score         = VALUES(confidence_score),
                last_checked_at          = NOW()
        ");
        $stmt->execute([
            $toolId,
            $payload['source_url']      ?? '',
            $payload['extracted_text']  ?? '',
            json_encode($payload['detected_features']     ?? []),
            json_encode($payload['detected_integrations'] ?? []),
            json_encode($payload['detected_use_cases']    ?? []),
            $payload['pricing_signal']    ?? null,
            $payload['confidence_score']  ?? 0,
        ]);
    } catch (Throwable $e) {
        // silent — comparison still works without cache
    }
}

/**
 * Fetch the homepage HTML via cURL and return clean text.
 * Returns empty string on failure.
 */
function live_data_fetch_text(string $url): string {
    if (!function_exists('curl_init')) return '';
    $url = trim($url);
    if ($url === '' || !preg_match('/^https?:\/\//i', $url)) return '';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; AIHubpediaBot/1.0)',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => ['Accept-Language: en-US,en;q=0.9'],
    ]);
    $html = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($html === false || $err !== '' || !is_string($html) || strlen($html) < 200) return '';

    // Remove scripts, styles, svg, noscript blocks
    $clean = preg_replace('/<(script|style|svg|noscript)[^>]*>.*?<\/\1>/is', ' ', $html);
    // Strip tags
    $clean = strip_tags($clean ?? '');
    // Normalise whitespace
    $clean = preg_replace('/\s+/', ' ', $clean ?? '');
    return trim((string)$clean);
}

/**
 * From plain text detect signals relevant to scoring.
 */
function live_data_detect_signals(string $text): array {
    $lower = strtolower($text);

    // ---- Pricing signal ----
    $pricingSignal = 'unknown';
    if (preg_match('/\bfree\s+(forever|plan|tier)\b/', $lower))        $pricingSignal = 'free';
    elseif (preg_match('/\bfreemium\b/', $lower))                       $pricingSignal = 'freemium';
    elseif (preg_match('/\bfree trial\b/', $lower))                     $pricingSignal = 'free trial';
    elseif (preg_match('/\bno credit card\b/', $lower))                 $pricingSignal = 'free trial';
    elseif (preg_match('/\bcontact (us|sales) for pricing\b/', $lower)) $pricingSignal = 'contact for pricing';
    elseif (preg_match('/\bpricing\b/', $lower))                        $pricingSignal = 'paid';
    elseif (preg_match('/\bsubscription\b/', $lower))                   $pricingSignal = 'paid';

    // ---- Features ----
    $featureKeywords = [
        'ai-powered','automation','analytics','dashboard','collaboration','export',
        'import','templates','workflow','integrations','api','mobile app','desktop app',
        'real-time','scheduling','reporting','tracking','custom','notifications',
        'search','filter','tagging','sharing','permissions','roles','onboarding',
        'support','security','encryption','sso','two-factor','plugin','extension',
        'batch','bulk','summarize','transcribe','generate','translate',
        'chat','voice','image','video','document','code','data',
    ];
    $detectedFeatures = [];
    foreach ($featureKeywords as $kw) {
        if (str_contains($lower, $kw)) $detectedFeatures[] = $kw;
    }

    // ---- Integrations ----
    $integrationKeywords = [
        'slack','notion','zapier','google workspace','gmail','google drive','google meet',
        'microsoft teams','outlook','dropbox','salesforce','hubspot','jira','asana',
        'trello','airtable','monday','stripe','shopify','twilio','discord','github',
        'gitlab','figma','zoom','webex','make','n8n','ifttt','webhook',
        'rest api','openai','anthropic','chrome extension',
    ];
    $detectedIntegrations = [];
    foreach ($integrationKeywords as $kw) {
        if (str_contains($lower, $kw)) $detectedIntegrations[] = ucwords($kw);
    }

    // ---- Use cases ----
    $useCaseKeywords = [
        'content creation','copywriting','email marketing','social media','seo',
        'customer support','customer service','sales','crm','project management',
        'note taking','meeting notes','transcription','code generation','code review',
        'resume','cover letter','job search','education','learning','research',
        'data analysis','reporting','image generation','video editing','podcasting',
        'e-commerce','lead generation','chatbot','virtual assistant','productivity',
    ];
    $detectedUseCases = [];
    foreach ($useCaseKeywords as $uc) {
        if (str_contains($lower, $uc)) $detectedUseCases[] = ucwords($uc);
    }

    // ---- Platform signals ----
    $detectedPlatforms = [];
    $platformMap = [
        'web app'        => 'Web',
        'browser'        => 'Web',
        'ios'            => 'iOS',
        'iphone'         => 'iOS',
        'android'        => 'Android',
        'windows'        => 'Windows',
        'macos'          => 'macOS',
        'mac os'         => 'macOS',
        'linux'          => 'Linux',
        'chrome extension' => 'Chrome Extension',
        'api'            => 'API',
    ];
    foreach ($platformMap as $needle => $label) {
        if (str_contains($lower, $needle)) $detectedPlatforms[$label] = $label;
    }

    // ---- Confidence: how rich is the page? ----
    $wordCount  = str_word_count($text);
    $confidence = min(1.0, ($wordCount / 500) * 0.5
                       + (count($detectedFeatures) / 10) * 0.25
                       + (count($detectedIntegrations) / 5) * 0.15
                       + (count($detectedUseCases) / 5) * 0.10);

    return [
        'pricing_signal'        => $pricingSignal,
        'detected_features'     => array_values(array_unique($detectedFeatures)),
        'detected_integrations' => array_values(array_unique($detectedIntegrations)),
        'detected_use_cases'    => array_values(array_unique($detectedUseCases)),
        'detected_platforms'    => array_values($detectedPlatforms),
        'confidence_score'      => round($confidence, 3),
    ];
}

/**
 * Master entry point: return live data for a tool.
 * Returns cached row if fresh; otherwise fetches live and caches.
 * On any failure returns null.
 */
function live_data_for_tool(string $toolId, string $websiteUrl): ?array {
    // Check cache first
    $cached = live_data_get($toolId);
    if ($cached !== null) {
        return [
            'source'              => 'cache',
            'source_url'          => $cached['source_url'] ?? '',
            'pricing_signal'      => $cached['pricing_signal'] ?? null,
            'detected_features'   => json_decode($cached['detected_features_json']    ?? '[]', true) ?: [],
            'detected_integrations'=> json_decode($cached['detected_integrations_json'] ?? '[]', true) ?: [],
            'detected_use_cases'  => json_decode($cached['detected_use_cases_json']   ?? '[]', true) ?: [],
            'confidence_score'    => (float)($cached['confidence_score'] ?? 0),
            'last_checked_at'     => $cached['last_checked_at'] ?? null,
            'fetch_ok'            => true,
        ];
    }

    // No fresh cache — attempt a live fetch
    if ($websiteUrl === '') return null;
    $text = live_data_fetch_text($websiteUrl);
    if ($text === '') {
        // Store a failure marker so we don't retry on every page load within TTL
        live_data_save($toolId, [
            'source_url'       => $websiteUrl,
            'extracted_text'   => '',
            'detected_features'=> [],
            'detected_integrations' => [],
            'detected_use_cases'    => [],
            'pricing_signal'   => null,
            'confidence_score' => 0,
        ]);
        return ['fetch_ok' => false, 'last_checked_at' => date('Y-m-d H:i:s')];
    }

    $signals = live_data_detect_signals($text);

    $payload = [
        'source_url'            => $websiteUrl,
        'extracted_text'        => substr($text, 0, 50000),
        'detected_features'     => $signals['detected_features'],
        'detected_integrations' => $signals['detected_integrations'],
        'detected_use_cases'    => $signals['detected_use_cases'],
        'pricing_signal'        => $signals['pricing_signal'],
        'confidence_score'      => $signals['confidence_score'],
    ];
    live_data_save($toolId, $payload);

    return [
        'source'               => 'live',
        'source_url'           => $websiteUrl,
        'pricing_signal'       => $signals['pricing_signal'],
        'detected_features'    => $signals['detected_features'],
        'detected_integrations'=> $signals['detected_integrations'],
        'detected_use_cases'   => $signals['detected_use_cases'],
        'detected_platforms'   => $signals['detected_platforms'] ?? [],
        'confidence_score'     => $signals['confidence_score'],
        'last_checked_at'      => date('Y-m-d H:i:s'),
        'fetch_ok'             => true,
    ];
}

// ============================================================
// Compare bundle helpers
// ============================================================

function compare_page_clean_list($value): array {
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) $value = $decoded;
        else $value = array_filter(array_map('trim', explode(',', $value)));
    }
    if (!is_array($value)) return [];
    $out = [];
    foreach ($value as $item) {
        if (is_array($item)) {
            $item = $item['label'] ?? $item['name'] ?? $item['title'] ?? '';
        }
        $item = trim((string)$item);
        if ($item !== '') $out[] = $item;
    }
    return array_values(array_unique($out));
}

function compare_clamp(float $v): int {
    return (int) min(10, max(1, round($v)));
}

// ---- Individual score calculators ----

function compare_score_feature_depth(array $features, array $pros, array $useCases, array $liveFeatures = []): int {
    $allFeatures = array_values(array_unique(array_merge($features, $liveFeatures)));
    $raw = (count($allFeatures) * 0.6) + (count($pros) * 0.3) + (count($useCases) * 0.2);
    $score = ($raw / 9) * 10;
    return ($score < 1 && $raw > 0) ? 1 : compare_clamp($score);
}

function compare_score_use_case_range(array $useCases, array $tags, string $category, array $liveUseCases = []): int {
    $allUc = array_values(array_unique(array_merge($useCases, $liveUseCases)));
    $base     = count($allUc) * 1.2;
    $tagBonus = min(3, count($tags) * 0.15);
    $broad    = ['productivity','writing','automation','assistant','general','multi-purpose'];
    $catBonus = in_array(strtolower($category), $broad, true) ? 1 : 0;
    return compare_clamp($base + $tagBonus + $catBonus);
}

function compare_score_integrations(array $integrations, array $tags, array $liveIntegrations = []): array {
    $integrationKeywords = [
        'api','slack','zoom','google meet','gmail','google','teams','notion',
        'zapier','chrome','integrations','workflow','automation','webhook',
        'make','n8n','ifttt','airtable','trello','asana','jira','hubspot',
        'salesforce','shopify','stripe','twilio','discord','twitter','x',
    ];
    if (empty($integrations)) {
        foreach ($tags as $tag) {
            if (in_array(strtolower($tag), $integrationKeywords, true)) $integrations[] = $tag;
        }
        $integrations = array_values(array_unique($integrations));
    }
    // Merge live integrations
    $combined = array_values(array_unique(array_merge($integrations, $liveIntegrations)));
    $score = compare_clamp(count($combined) * 1.2);
    return ['score' => $score, 'list' => $combined];
}

function compare_score_popularity(string $toolId): int {
    if (!function_exists('click_count')) return 5;
    $clicks = (int) click_count($toolId);
    if ($clicks <= 0) return 1;
    $score = (log10(max(1, $clicks)) / log10(10000)) * 10;
    return compare_clamp($score);
}

function compare_score_value_for_money(string $pricing, ?string $livePricingSignal = null): int {
    $map = [
        'free'                => 9,
        'freemium'            => 8,
        'free trial'          => 7,
        'paid'                => 6,
        'contact for pricing' => 4,
        'unknown'             => 5,
    ];
    // Prefer live signal over DB value when live data is confident
    $effectivePricing = ($livePricingSignal && $livePricingSignal !== 'unknown')
        ? $livePricingSignal
        : strtolower(trim($pricing));
    foreach ($map as $pattern => $val) {
        if (str_contains($effectivePricing, $pattern)) return $val;
    }
    return 5;
}

/**
 * Data Quality (1–10) — driven by live fetch confidence and DB data richness.
 */
function compare_score_data_quality(bool $hasDbData, ?array $live): int {
    $dbScore   = $hasDbData ? 5 : 2;
    if ($live === null)       return $dbScore;
    if (!($live['fetch_ok'] ?? false)) return max(1, $dbScore - 1);
    $liveBoost = (float)($live['confidence_score'] ?? 0) * 5; // 0–5 bonus
    return compare_clamp($dbScore + $liveBoost);
}

// ---- Bundle builder ----

function compare_page_bundle(array $tool, ?array $live): array {
    $id           = (string)($tool['id'] ?? '');
    $features     = compare_page_clean_list($tool['features'] ?? []);
    $useCases     = compare_page_clean_list($tool['use_cases'] ?? []);
    $pros         = compare_page_clean_list($tool['pros'] ?? []);
    $watchouts    = compare_page_clean_list($tool['watchouts'] ?? ($tool['cons'] ?? []));
    $platforms    = compare_page_clean_list($tool['platforms'] ?? []);
    $tags         = compare_page_clean_list($tool['tags'] ?? []);
    $category     = (string)($tool['category'] ?? '');
    $pricingRaw   = trim((string)($tool['pricing'] ?? ($tool['pricing_type'] ?? 'Unknown')));

    if (empty($platforms) && !empty($tool['website'])) $platforms[] = 'Web';

    // Live-detected data
    $liveFeatures     = ($live && ($live['fetch_ok'] ?? false)) ? ($live['detected_features']    ?? []) : [];
    $liveIntegrations = ($live && ($live['fetch_ok'] ?? false)) ? ($live['detected_integrations'] ?? []) : [];
    $liveUseCases     = ($live && ($live['fetch_ok'] ?? false)) ? ($live['detected_use_cases']    ?? []) : [];
    $livePlatforms    = ($live && ($live['fetch_ok'] ?? false)) ? ($live['detected_platforms']    ?? []) : [];
    $livePricing      = ($live && ($live['fetch_ok'] ?? false)) ? ($live['pricing_signal'] ?? null) : null;

    // Merge platforms
    if (!empty($livePlatforms)) {
        $platforms = array_values(array_unique(array_merge($platforms, $livePlatforms)));
    }

    // Integrations
    $rawIntegrations = compare_page_clean_list($tool['integrations'] ?? []);
    $intResult       = compare_score_integrations($rawIntegrations, $tags, $liveIntegrations);
    $integrations    = $intResult['list'];
    $intScore        = $intResult['score'];

    $hasDbData   = !empty($features) || !empty($useCases) || !empty($pros) || !empty($watchouts);
    $liveOk      = $live && ($live['fetch_ok'] ?? false);

    // Scores
    $featureDepth  = compare_score_feature_depth($features, $pros, $useCases, $liveFeatures);
    $useCaseRange  = compare_score_use_case_range($useCases, $tags, $category, $liveUseCases);
    $popularity    = compare_score_popularity($id);
    $valueForMoney = compare_score_value_for_money($pricingRaw, $livePricing);
    $dataQuality   = compare_score_data_quality($hasDbData, $live);

    // Overall — 5 dimensions now (community replaced by data quality)
    $allScores    = [$featureDepth, $useCaseRange, $intScore, $popularity, $valueForMoney, $dataQuality];
    $overallScore = compare_clamp(array_sum($allScores) / count($allScores));

    // Source status
    if ($liveOk) {
        $sourceStatus     = 'live_data';
        $sourceLabel      = 'Live data checked';
        $sourceConfidence = round(($live['confidence_score'] ?? 0) * 100) . '%';
    } elseif ($live && !$liveOk) {
        $sourceStatus     = 'fetch_failed';
        $sourceLabel      = 'Live data unavailable — using AIHubpedia listing data';
        $sourceConfidence = 'n/a';
    } else {
        $sourceStatus     = $hasDbData ? 'available' : 'basic';
        $sourceLabel      = $hasDbData ? 'AIHubpedia listing data' : 'Basic facts only';
        $sourceConfidence = $hasDbData ? 'basic' : 'low';
    }

    // Merge live features into display list (deduplicated, readable)
    $displayFeatures  = array_values(array_unique(array_merge($features, $liveFeatures)));
    $displayUseCases  = array_values(array_unique(array_merge($useCases, $liveUseCases)));

    return [
        'summary'             => trim((string)($tool['description'] ?? ($tool['short'] ?? ''))),
        'pricing'             => $livePricing && $livePricing !== 'unknown' ? ucfirst($livePricing) : $pricingRaw,
        'features'            => $displayFeatures,
        'use_cases'           => $displayUseCases,
        'pros'                => $pros,
        'watchouts'           => $watchouts,
        'platforms'           => $platforms,
        'integrations'        => $integrations,
        'real_data'           => $hasDbData || $liveOk,
        'source_status'       => $sourceStatus,
        'source_label'        => $sourceLabel,
        'source_confidence'   => $sourceConfidence,
        'live_ok'             => $liveOk,
        'last_checked_at'     => $live['last_checked_at'] ?? null,
        // Scores
        'feature_depth'       => $featureDepth,
        'use_case_range'      => $useCaseRange,
        'integrations_score'  => $intScore,
        'popularity'          => $popularity,
        'value_for_money'     => $valueForMoney,
        'data_quality'        => $dataQuality,
        'overall_score'       => $overallScore,
    ];
}

// ---- Score display helpers ----

function compare_page_score_items(array $bundle): array {
    return [
        'Feature depth'  => (int)($bundle['feature_depth']      ?? 0),
        'Use-case range' => (int)($bundle['use_case_range']     ?? 0),
        'Integrations'   => (int)($bundle['integrations_score'] ?? 0),
        'Popularity'     => (int)($bundle['popularity']         ?? 0),
        'Value for money'=> (int)($bundle['value_for_money']    ?? 0),
        'Data quality'   => (int)($bundle['data_quality']       ?? 0),
    ];
}

function compare_page_snapshot(array $bundle): array {
    return [
        'Overall score' => (int)($bundle['overall_score'] ?? 0) . '/10',
        'Feature depth' => (int)($bundle['feature_depth'] ?? 0) . '/10',
        'Integrations'  => count($bundle['integrations'] ?? []),
    ];
}

// ---- Winner logic ----

function compare_page_pick_winner(array $bundleA, array $bundleB): ?string {
    $scoreA = (float)($bundleA['overall_score'] ?? 0);
    $scoreB = (float)($bundleB['overall_score'] ?? 0);
    if ($scoreA === $scoreB) return null;
    return $scoreA > $scoreB ? 'a' : 'b';
}

function compare_page_winner_explanation(array $winner, array $loser, string $winnerName, string $loserName): string {
    $dims = [
        'feature_depth'      => 'feature depth',
        'use_case_range'     => 'use-case range',
        'integrations_score' => 'integrations',
        'popularity'         => 'popularity',
        'value_for_money'    => 'value for money',
        'data_quality'       => 'data quality',
    ];
    $gaps = [];
    foreach ($dims as $key => $label) {
        $diff = (int)($winner[$key] ?? 0) - (int)($loser[$key] ?? 0);
        if ($diff > 0) $gaps[$label] = $diff;
    }
    arsort($gaps);
    if (empty($gaps)) return "{$winnerName} edges ahead by a slim overall margin.";
    $top      = array_slice(array_keys($gaps), 0, 2);
    $strong   = implode(' and ', $top);
    $margin   = reset($gaps);
    $marginTxt = $margin >= 3 ? 'significantly stronger' : 'stronger';
    return "{$winnerName} wins on overall score, driven by {$marginTxt} {$strong} compared to {$loserName}.";
}

// ============================================================
// Fetch live data for both tools (before bundle build)
// ============================================================
$liveA = null;
$liveB = null;
if ($ta) {
    $websiteA = trim((string)($ta['website'] ?? ''));
    $liveA    = $websiteA !== '' ? live_data_for_tool((string)$ta['id'], $websiteA) : null;
}
if ($tb) {
    $websiteB = trim((string)($tb['website'] ?? ''));
    $liveB    = $websiteB !== '' ? live_data_for_tool((string)$tb['id'], $websiteB) : null;
}

$bundleA = $ta ? compare_page_bundle($ta, $liveA) : null;
$bundleB = $tb ? compare_page_bundle($tb, $liveB) : null;

$winnerKey  = ($bundleA && $bundleB) ? compare_page_pick_winner($bundleA, $bundleB) : null;
$winnerName = $winnerKey === 'a' ? ($ta['name'] ?? null) : ($winnerKey === 'b' ? ($tb['name'] ?? null) : null);
$winnerExplanation = null;
if ($winnerKey && $bundleA && $bundleB && $ta && $tb) {
    [$wBundle, $lBundle, $wName, $lName] = $winnerKey === 'a'
        ? [$bundleA, $bundleB, $ta['name'], $tb['name']]
        : [$bundleB, $bundleA, $tb['name'], $ta['name']];
    $winnerExplanation = compare_page_winner_explanation($wBundle, $lBundle, $wName, $lName);
}

require __DIR__ . '/layout_top.php';
?>

<section class="compare-hero card">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:20px;">
  <div class="compare-hero__copy">
    <span class="eyebrow">Side-by-side comparison</span>
    <h1>Compare tools</h1>
    <p class="small compare-lead">Pick two AI tools and compare verified compare data, pricing signals, source-backed features, and practical fit in one clean view.</p>
  </div>
         <a class="btn secondary"
       href="signup-redirect.php?id=b12"
       target="_blank"
       rel="nofollow sponsored noopener"
       style="white-space:nowrap;margin-top:5px;">
       🚀 Try B12 Free
    </a>
     </div>
  <form method="get" class="compare-form-grid">
    <div class="field">
      <label for="toolA">Tool A</label>
      <select id="toolA" name="a">
        <option value="">Choose first tool</option>
        <?php foreach ($tools as $t): ?>
          <option value="<?= h($t['id']) ?>" <?= ($a === $t['id']) ? 'selected' : '' ?>><?= h($t['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="toolB">Tool B</label>
      <select id="toolB" name="b">
        <option value="">Choose second tool</option>
        <?php foreach ($tools as $t): ?>
          <option value="<?= h($t['id']) ?>" <?= ($b === $t['id']) ? 'selected' : '' ?>><?= h($t['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="compare-form-actions">
      <button class="btn" type="submit">Compare now</button>
      <a class="btn secondary" href="compare.php">Reset</a>
    </div>
  </form>
</section>

<?php if ($ta && $tb && $bundleA && $bundleB): ?>
 <!-- <div class="compare-sticky-bar card">
    <div class="compare-sticky-bar__tools">
      <span><strong><?= h($ta['name']) ?></strong> vs <strong><?= h($tb['name']) ?></strong></span>
      <?php if ($winnerName): ?>
        <span class="compare-winner-pill">Overall edge: <?= h($winnerName) ?></span>
      <?php else: ?>
        <span class="compare-winner-pill neutral">Very close match</span>
      <?php endif; ?>
    </div>
    <?php if ($winnerExplanation): ?>
      <p class="small compare-winner-explanation" style="margin:6px 0 0;opacity:.85;"><?= h($winnerExplanation) ?></p>
    <?php endif; ?>
    <div class="compare-sticky-bar__actions">
      <a class="btn secondary" href="tool.php?id=<?= urlencode($ta['id']) ?>">View <?= h($ta['name']) ?></a>
      <a class="btn" href="tool.php?id=<?= urlencode($tb['id']) ?>">View <?= h($tb['name']) ?></a>
    </div>
  </div>
  -->

  <section class="compare-grid compare-grid--premium">
    <div class="compare-vs-badge">VS</div>
    <?php foreach ([
        ['tool' => $ta, 'bundle' => $bundleA, 'winner' => $winnerKey === 'a'],
        ['tool' => $tb, 'bundle' => $bundleB, 'winner' => $winnerKey === 'b'],
    ] as $entry):
      $tool       = $entry['tool'];
      $bundle     = $entry['bundle'];
      $scoreItems = compare_page_score_items($bundle);
      $snapshot   = compare_page_snapshot($bundle);
      $isWinner   = $entry['winner'];
      $lastChecked = $bundle['last_checked_at'] ?? null;
      $lastCheckedDisplay = $lastChecked ? date('j M Y', strtotime($lastChecked)) : null;
    ?>
      <article class="compare-card card <?= $isWinner ? 'compare-card--winner' : '' ?>">
        <?php if ($isWinner): ?><div class="compare-card__winner">Best overall value</div><?php endif; ?>
        <div class="compare-card__head">
          <div class="compare-card__brand">
            <img src="<?= h(tool_logo($tool)) ?>" alt="<?= h($tool['name']) ?> logo" class="tool-logo lg">
            <div>
              <h2><?= h($tool['name']) ?></h2>
              <div class="compare-meta">
                <span class="chip"><?= h($tool['category'] ?? 'AI Tool') ?></span>
                <span class="chip soft"><?= h($bundle['pricing'] ?: ($tool['pricing'] ?? 'Unknown')) ?></span>
                <?php if ($bundle['live_ok']): ?>
                  <span class="chip soft" style="color:#16a34a">&#10003; Live data checked</span>
                <?php elseif ($bundle['source_status'] === 'fetch_failed'): ?>
                  <span class="chip soft" style="color:#dc2626">Live data unavailable</span>
                <?php else: ?>
                  <span class="chip soft"><?= $bundle['real_data'] ? 'AIHubpedia data' : 'Basic facts only' ?></span>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <div class="compare-card__actions actions">
            <a class="btn secondary" href="tool.php?id=<?= urlencode($tool['id']) ?>">Review</a>
            <a class="btn" target="_blank" rel="noopener" href="redirect.php?id=<?= urlencode($tool['id']) ?>">Open tool</a>
          </div>
        </div>

        <div class="compare-snapshot">
          <?php foreach ($snapshot as $label => $value): ?>
            <div class="snapshot-box">
              <strong><?= h($value) ?></strong>
              <span><?= h($label) ?></span>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="compare-summary">
          <h3>Overview</h3>
          <p><?= h($bundle['summary'] ?: 'No summary has been saved yet for this tool.') ?></p>
          <p class="small" style="margin-top:10px">
            Data source: <strong><?= h($bundle['source_label']) ?></strong>
            <?php if ($bundle['live_ok'] && $bundle['source_confidence'] !== 'n/a'): ?>
              &nbsp;·&nbsp;Confidence: <?= h($bundle['source_confidence']) ?>
            <?php endif; ?>
            <?php if ($lastCheckedDisplay): ?>
              &nbsp;·&nbsp;Last checked: <strong><?= h($lastCheckedDisplay) ?></strong>
            <?php endif; ?>
          </p>
          <?php if ($bundle['source_status'] === 'fetch_failed'): ?>
            <p class="small" style="color:#dc2626;margin-top:4px">
              Live data unavailable — using AIHubpedia listing data.
            </p>
          <?php endif; ?>
        </div>

        <div class="compare-scoreboard">
          <?php foreach ($scoreItems as $label => $value): ?>
            <div class="score-item">
              <div class="score-item__row">
                <span><?= h($label) ?></span>
                <strong><?= h($value) ?>/10</strong>
              </div>
              <div class="score-bar"><span style="width: <?= (int)$value * 10 ?>%"></span></div>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="compare-columns">
          <div>
            <h3>Top features</h3>
            <ul class="nice-list">
              <?php foreach (array_slice($bundle['features'] ?? [], 0, 6) as $item): ?>
                <li><?= h($item) ?></li>
              <?php endforeach; ?>
              <?php if (empty($bundle['features'])): ?><li>No verified feature data yet.</li><?php endif; ?>
            </ul>
          </div>
          <div>
            <h3>Best use cases</h3>
            <ul class="nice-list">
              <?php foreach (array_slice($bundle['use_cases'] ?? [], 0, 6) as $item): ?>
                <li><?= h($item) ?></li>
              <?php endforeach; ?>
              <?php if (empty($bundle['use_cases'])): ?><li>No verified use-case data yet.</li><?php endif; ?>
            </ul>
          </div>
        </div>

        <div class="compare-columns">
          <div>
            <h3>Strengths</h3>
            <ul class="nice-list success">
              <?php foreach (array_slice($bundle['pros'] ?? [], 0, 4) as $item): ?>
                <li><?= h($item) ?></li>
              <?php endforeach; ?>
              <?php if (empty($bundle['pros'])): ?><li>No strengths saved yet.</li><?php endif; ?>
            </ul>
          </div>
          <div>
            <h3>Watch-outs</h3>
            <ul class="nice-list warning">
              <?php foreach (array_slice($bundle['watchouts'] ?? [], 0, 4) as $item): ?>
                <li><?= h($item) ?></li>
              <?php endforeach; ?>
              <?php if (empty($bundle['watchouts'])): ?><li>No watch-outs saved yet.</li><?php endif; ?>
            </ul>
          </div>
        </div>

        <div class="compare-columns">
          <div>
            <h3>Platforms</h3>
            <ul class="nice-list">
              <?php foreach (array_slice($bundle['platforms'] ?? [], 0, 8) as $item): ?>
                <li><?= h($item) ?></li>
              <?php endforeach; ?>
              <?php if (empty($bundle['platforms'])): ?><li>No verified platform data yet.</li><?php endif; ?>
            </ul>
          </div>
          <div>
            <h3>Integrations</h3>
            <ul class="nice-list">
              <?php foreach (array_slice($bundle['integrations'] ?? [], 0, 8) as $item): ?>
                <li><?= h($item) ?></li>
              <?php endforeach; ?>
              <?php if (empty($bundle['integrations'])): ?><li>No verified integration data yet.</li><?php endif; ?>
            </ul>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="compare-final card">
    <h2>Quick buying view</h2>
    <div class="compare-table-wrap">
      <table class="compare-table-modern">
        <tr>
          <th>Criteria</th>
          <th><?= h($ta['name']) ?></th>
          <th><?= h($tb['name']) ?></th>
        </tr>
        <tr>
          <td>Overall score</td>
          <td><strong><?= (int)($bundleA['overall_score'] ?? 0) ?>/10</strong></td>
          <td><strong><?= (int)($bundleB['overall_score'] ?? 0) ?>/10</strong></td>
        </tr>
        <tr>
          <td>Category</td>
          <td><?= h($ta['category'] ?? '-') ?></td>
          <td><?= h($tb['category'] ?? '-') ?></td>
        </tr>
        <tr>
          <td>Pricing</td>
          <td><?= h($bundleA['pricing'] ?: ($ta['pricing'] ?? '-')) ?></td>
          <td><?= h($bundleB['pricing'] ?: ($tb['pricing'] ?? '-')) ?></td>
        </tr>
        <tr>
          <td>Best for</td>
          <td><?= h(($bundleA['use_cases'][0] ?? $ta['short'] ?? '-')) ?></td>
          <td><?= h(($bundleB['use_cases'][0] ?? $tb['short'] ?? '-')) ?></td>
        </tr>
        <tr>
          <td>Platforms</td>
          <td><?= h(implode(', ', array_slice($bundleA['platforms'] ?? [], 0, 4)) ?: '-') ?></td>
          <td><?= h(implode(', ', array_slice($bundleB['platforms'] ?? [], 0, 4)) ?: '-') ?></td>
        </tr>
        <tr>
          <td>Data source</td>
          <td>
            <?= h($bundleA['source_label']) ?>
            <?php if ($bundleA['last_checked_at']): ?>
              <br><span class="small" style="opacity:.7">Checked: <?= h(date('j M Y', strtotime($bundleA['last_checked_at']))) ?></span>
            <?php endif; ?>
          </td>
          <td>
            <?= h($bundleB['source_label']) ?>
            <?php if ($bundleB['last_checked_at']): ?>
              <br><span class="small" style="opacity:.7">Checked: <?= h(date('j M Y', strtotime($bundleB['last_checked_at']))) ?></span>
            <?php endif; ?>
          </td>
        </tr>
        <tr>
          <td>Official site</td>
          <td><?= h(tool_domain($ta['website'] ?? '')) ?></td>
          <td><?= h(tool_domain($tb['website'] ?? '')) ?></td>
        </tr>
      </table>
    </div>
  </section>

  <?php if ($winnerExplanation): ?>
  <section class="compare-verdict card" style="margin-top:1.5rem;">
    <h2>Why <?= h($winnerName) ?> wins</h2>
    <p><?= h($winnerExplanation) ?></p>
    <?php
      $wBundle = $winnerKey === 'a' ? $bundleA : $bundleB;
      $lBundle = $winnerKey === 'a' ? $bundleB : $bundleA;
      $dims    = [
        'feature_depth'      => 'Feature depth',
        'use_case_range'     => 'Use-case range',
        'integrations_score' => 'Integrations',
        'popularity'         => 'Popularity',
        'value_for_money'    => 'Value for money',
        'data_quality'       => 'Data quality',
      ];
    ?>
    <div class="compare-verdict-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.75rem;margin-top:1rem;">
      <?php foreach ($dims as $key => $label):
        $wScore = (int)($wBundle[$key] ?? 0);
        $lScore = (int)($lBundle[$key] ?? 0);
        $diff   = $wScore - $lScore;
        $badge  = $diff > 0 ? '▲ +' . $diff : ($diff < 0 ? '▼ ' . $diff : '=');
        $cls    = $diff > 0 ? 'color:#16a34a' : ($diff < 0 ? 'color:#dc2626' : 'color:#6b7280');
      ?>
        <div style="background:var(--surface-alt,#f9fafb);border-radius:8px;padding:.75rem 1rem;">
          <div class="small" style="opacity:.7"><?= h($label) ?></div>
          <div style="font-size:1.2rem;font-weight:700;"><?= $wScore ?>/10</div>
          <div class="small" style="<?= $cls ?>;font-weight:600"><?= $badge ?> vs opponent</div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

<?php elseif ($a || $b): ?>
  <div class="card compare-empty-state">
    <h2>Select two valid tools</h2>
    <p class="small">Choose both Tool A and Tool B to unlock the full side-by-side comparison view.</p>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/layout_bottom.php'; ?>
