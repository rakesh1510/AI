<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

// Analytics A1: this endpoint no longer WRITES. Outbound clicks are counted exactly once, by redirect.php (the link the visitor actually follows).
// It stays only so a page cached before the fix (whose script still posts here) keeps working: it answers with the current total and records nothing.
header('Content-Type: application/json');
sec_guard_write('interact', 120, 600);

try {
    $toolId = trim((string)($_POST['id'] ?? ''));
    if ($toolId === '' || !find_tool($toolId)) { sec_fail(422, 'Unknown tool'); }
    $counts = function_exists('all_click_counts') ? all_click_counts() : [];
    echo json_encode(['success' => true, 'clicks' => (int)($counts[$toolId] ?? 0), 'counted' => false]);
} catch (Throwable $e) {
    sec_log_exception($e, 'track-click.php');
    sec_fail(500, 'Something went wrong. Please try again later.');
}
