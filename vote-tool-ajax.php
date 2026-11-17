<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json');
sec_guard_write('interact', 40, 600);

try {
    $toolId = trim((string)($_POST['id'] ?? ''));
    $type = trim((string)($_POST['type'] ?? 'up'));
    if ($toolId === '' || !find_tool($toolId)) { sec_fail(422, 'Unknown tool'); }
    if ($type !== 'up' && $type !== 'down') { sec_fail(422, 'Invalid vote type'); }
    if (!interactions_writable()) { header('Retry-After: 300'); sec_fail(503, 'Voting is temporarily unavailable.'); }

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
    sec_log_exception($e, 'vote-tool-ajax.php');
    sec_fail(500, 'Something went wrong. Please try again later.');
}
