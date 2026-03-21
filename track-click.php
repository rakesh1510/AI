<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid request'
        ]);
        exit;
    }

    $toolId = trim($_POST['id'] ?? '');

    if ($toolId === '') {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Missing tool id'
        ]);
        exit;
    }

    $total = record_click($toolId);

    echo json_encode([
        'success' => true,
        'clicks' => (int)$total,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}