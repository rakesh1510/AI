<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit;
    }

    $toolId = trim($_POST['id'] ?? '');
    $type = trim($_POST['type'] ?? 'up');

    if ($toolId === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Missing tool id']);
        exit;
    }

    if ($type !== 'up' && $type !== 'down') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Invalid vote type']);
        exit;
    }

    $ok = vote_tool_action($toolId, $type);
    $counts = tool_vote_breakdown($toolId);

    echo json_encode([
        'success' => $ok,
        'message' => $ok ? 'Vote saved' : 'You already voted that way',
        'likes' => (int)($counts['up'] ?? 0),
        'dislikes' => (int)($counts['down'] ?? 0),
        'clicks' => function_exists('click_count') ? (int)click_count($toolId) : 0,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}