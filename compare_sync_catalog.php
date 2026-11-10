<?php
require_once __DIR__ . '/helpers.php';

if(!compare_tables_exist()){
    require __DIR__ . '/compare_install.php';
}

$data = load_tools();
$tools = $data['tools'] ?? [];
$pdo = db();
$stmt = $pdo->prepare("INSERT INTO compare_tool_profiles (tool_slug, tool_name, official_website, pricing_model, last_synced_at, source_status) VALUES (?, ?, ?, ?, NOW(), 'pending') ON DUPLICATE KEY UPDATE tool_name = VALUES(tool_name), official_website = VALUES(official_website), pricing_model = VALUES(pricing_model), last_synced_at = NOW()");
$count = 0;
foreach($tools as $tool){
    $stmt->execute([
        (string)($tool['id'] ?? ''),
        (string)($tool['name'] ?? ''),
        (string)($tool['website'] ?? ''),
        (string)($tool['pricing'] ?? ''),
    ]);
    $count++;
}

echo "Synced {$count} tools into compare_tool_profiles.\n";
