<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

$isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
    || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
    || isset($_POST['ajax']);

// POST only + same-origin + CSRF token + rate limit (see security.php). Legacy GET callers get 405 and change nothing.
sec_guard_write('interact', 40, 600, $isAjax);

$toolId = trim((string)($_POST['id'] ?? $_POST['tool_id'] ?? ''));
$vote = trim((string)($_POST['vote'] ?? $_POST['type'] ?? ''));
$stars = (int)($_POST['stars'] ?? 0);
if ($toolId !== '' && !find_tool($toolId)) { $toolId = ''; }   // only known tools can be voted on or rated

try {
    if ($toolId !== '' && in_array($vote, ['up', 'down', 'like', 'dislike'], true)) {
        $saved = vote_tool_action($toolId, in_array($vote, ['like'], true) ? 'up' : (in_array($vote, ['dislike'], true) ? 'down' : $vote));
        $counts = tool_vote_breakdown($toolId);
        $payload = [
            'success' => true,
            'saved' => (bool)$saved,
            'tool_id' => $toolId,
            'likes' => (int)($counts['up'] ?? 0),
            'dislikes' => (int)($counts['down'] ?? 0),
            'clicks' => (int)click_count($toolId),
            'score' => (int)popularity_score($toolId),
        ];
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode($payload); exit; }
        header('Location: ' . sec_safe_local_path($_POST['context'] ?? '', 'tool.php?id=' . urlencode($toolId)), true, 303);
        exit;
    }

    if ($toolId !== '' && $stars >= 1 && $stars <= 5) {
        add_rating($toolId, $stars);
        if ($isAjax) {
            [$avg, $count] = rating_avg($toolId);
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'tool_id' => $toolId, 'avg' => round((float)$avg, 2), 'count' => (int)$count]);
            exit;
        }
        header('Location: /tool.php?id=' . urlencode($toolId), true, 303);
        exit;
    }

    if ($isAjax) { sec_fail(422, 'Invalid request'); }
    header('Location: /index.php', true, 303);
} catch (Throwable $e) {
    sec_log_exception($e, 'rate.php');
    if ($isAjax) { sec_fail(500, 'Something went wrong. Please try again later.'); }
    http_response_code(500);
    echo 'Something went wrong. Please try again later.';
}
