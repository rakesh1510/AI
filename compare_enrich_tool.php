<?php
require_once __DIR__ . '/helpers.php';

if(PHP_SAPI !== 'cli'){
    http_response_code(403);
    exit("CLI only\n");
}

$slug = trim($argv[1] ?? '');
if($slug === ''){
    fwrite(STDERR, "Usage: php compare_enrich_tool.php <tool-slug>\n");
    exit(1);
}

if(!compare_tables_exist()){
    require __DIR__ . '/compare_install.php';
}

$tool = find_tool($slug);
if(!$tool){
    fwrite(STDERR, "Tool not found: {$slug}\n");
    exit(1);
}

$apiKey = getenv('OPENAI_API_KEY') ?: '';
$model = getenv('OPENAI_MODEL') ?: '';
if($apiKey === '' || $model === ''){
    fwrite(STDERR, "OPENAI_API_KEY and OPENAI_MODEL must be set.\n");
    exit(1);
}

$pdo = db();
$stmt = $pdo->prepare("SELECT source_url, extracted_text FROM compare_tool_sources WHERE tool_slug = ? ORDER BY fetched_at DESC, id DESC LIMIT 5");
$stmt->execute([$slug]);
$sources = $stmt->fetchAll();
if(!$sources){
    fwrite(STDERR, "No fetched sources found for {$slug}. Run compare_fetch_source.php first.\n");
    exit(1);
}

$sourceText = [];
foreach($sources as $src){
    $sourceText[] = "URL: " . ($src['source_url'] ?? '') . "\nTEXT:\n" . mb_substr((string)($src['extracted_text'] ?? ''), 0, 12000);
}
$sourceFacts = implode("\n\n---\n\n", $sourceText);

$schema = [
    'type' => 'object',
    'additionalProperties' => false,
    'properties' => [
        'summary' => ['type' => 'string'],
        'pricing_model' => ['type' => 'string'],
        'free_plan' => ['type' => ['boolean', 'null']],
        'free_trial' => ['type' => ['boolean', 'null']],
        'platforms' => ['type' => 'array', 'items' => ['type' => 'string']],
        'integrations' => ['type' => 'array', 'items' => ['type' => 'string']],
        'top_features' => ['type' => 'array', 'items' => ['type' => 'string']],
        'best_use_cases' => ['type' => 'array', 'items' => ['type' => 'string']],
        'strengths' => ['type' => 'array', 'items' => ['type' => 'string']],
        'watchouts' => ['type' => 'array', 'items' => ['type' => 'string']],
        'confidence' => ['type' => 'string']
    ],
    'required' => ['summary','pricing_model','free_plan','free_trial','platforms','integrations','top_features','best_use_cases','strengths','watchouts','confidence']
];

$payload = [
    'model' => $model,
    'input' => [
        [
            'role' => 'system',
            'content' => [
                ['type' => 'input_text', 'text' => "You enrich compare data for an AI tools directory. Use only the provided source text. Do not invent missing facts. Keep bullet labels specific and short. Return JSON only."]
            ]
        ],
        [
            'role' => 'user',
            'content' => [
                ['type' => 'input_text', 'text' => "Tool name: {$tool['name']}\nWebsite: " . ($tool['website'] ?? '') . "\n\nSource text:\n{$sourceFacts}"]
            ]
        ]
    ],
    'text' => [
        'format' => [
            'type' => 'json_schema',
            'name' => 'compare_tool_enrichment',
            'schema' => $schema,
            'strict' => true
        ]
    ]
];

$ch = curl_init('https://api.openai.com/v1/responses');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => 120,
]);
$response = curl_exec($ch);
if($response === false){
    $error = curl_error($ch);
    curl_close($ch);
    fwrite(STDERR, "OpenAI request failed: {$error}\n");
    exit(1);
}
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if($http < 200 || $http >= 300){
    fwrite(STDERR, "OpenAI request returned HTTP {$http}: {$response}\n");
    exit(1);
}

$data = json_decode($response, true);
$json = $data['output'][0]['content'][0]['text'] ?? '';
if($json === ''){
    fwrite(STDERR, "OpenAI returned no JSON text.\n");
    exit(1);
}
$result = json_decode($json, true);
if(!is_array($result)){
    fwrite(STDERR, "Invalid JSON returned: {$json}\n");
    exit(1);
}

$profileStmt = $pdo->prepare("UPDATE compare_tool_profiles SET tool_name = ?, official_website = ?, pricing_model = ?, free_plan = ?, free_trial = ?, platforms_json = ?, integrations_json = ?, summary = ?, source_status = 'enriched', source_confidence = ?, source_hash = ?, last_enriched_at = NOW(), updated_at = NOW() WHERE tool_slug = ?");
$profileStmt->execute([
    (string)($tool['name'] ?? ''),
    (string)($tool['website'] ?? ''),
    (string)($result['pricing_model'] ?? ''),
    array_key_exists('free_plan', $result) ? ($result['free_plan'] === null ? null : (int)(bool)$result['free_plan']) : null,
    array_key_exists('free_trial', $result) ? ($result['free_trial'] === null ? null : (int)(bool)$result['free_trial']) : null,
    json_encode($result['platforms'] ?? [], JSON_UNESCAPED_UNICODE),
    json_encode($result['integrations'] ?? [], JSON_UNESCAPED_UNICODE),
    (string)($result['summary'] ?? ''),
    (string)($result['confidence'] ?? 'basic'),
    hash('sha256', $sourceFacts),
    $slug,
]);

$pdo->prepare("DELETE FROM compare_tool_points WHERE tool_slug = ?")->execute([$slug]);
$insertPoint = $pdo->prepare("INSERT INTO compare_tool_points (tool_slug, point_type, label, source_url, source_note, evidence_snippet, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
$mapping = [
    'feature' => $result['top_features'] ?? [],
    'use_case' => $result['best_use_cases'] ?? [],
    'pro' => $result['strengths'] ?? [],
    'watchout' => $result['watchouts'] ?? [],
    'integration' => $result['integrations'] ?? [],
];
foreach($mapping as $type => $items){
    $i = 0;
    foreach((array)$items as $item){
        $label = trim((string)$item);
        if($label === '') continue;
        $insertPoint->execute([$slug, $type, $label, $tool['website'] ?? '', 'AI-assisted from saved sources', null, $i]);
        $i++;
    }
}

echo "Enriched compare data for {$slug}.\n";
