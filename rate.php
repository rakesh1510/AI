<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
if (session_status() === PHP_SESSION_NONE) { @session_start(); }

$isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
    || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
    || isset($_POST['ajax']);

$toolId = trim($_POST['id'] ?? $_POST['tool_id'] ?? '');
$vote = trim($_POST['vote'] ?? $_POST['type'] ?? '');
$stars = (int)($_POST['stars'] ?? 0);

try {
    if ($toolId !== '' && ($vote === 'up' || $vote === 'down' || $vote === 'like' || $vote === 'dislike')) {
        $saved = vote_tool_action($toolId, $vote);
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

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode($payload);
            exit;
        }

        header('Location: ' . ($_POST['context'] ?? ('tool.php?id=' . urlencode($toolId))));
        exit;
    }

    if ($toolId !== '' && $stars > 0) {
        add_rating($toolId, $stars);

        if ($isAjax) {
            [$avg, $count] = rating_avg($toolId);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'tool_id' => $toolId,
                'avg' => round((float)$avg, 2),
                'count' => (int)$count,
            ]);
            exit;
        }

        header('Location: tool.php?id=' . urlencode($toolId));
        exit;
    }

    if ($isAjax) {
        header('Content-Type: application/json', true, 422);
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit;
    }

    header('Location: index.php');
} catch (Throwable $e) {
    if ($isAjax) {
        header('Content-Type: application/json', true, 500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
    throw $e;
}