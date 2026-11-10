<?php
require_once __DIR__ . '/helpers.php';

if(PHP_SAPI !== 'cli'){
    http_response_code(403);
    exit("CLI only\n");
}

$data = load_tools();
$tools = $data['tools'] ?? [];
foreach($tools as $tool){
    $slug = (string)($tool['id'] ?? '');
    if($slug === '') continue;
    echo "== {$slug} ==\n";
    passthru('php ' . escapeshellarg(__DIR__ . '/compare_fetch_source.php') . ' ' . escapeshellarg($slug));
    passthru('php ' . escapeshellarg(__DIR__ . '/compare_enrich_tool.php') . ' ' . escapeshellarg($slug));
}
