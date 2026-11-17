<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json');
sec_guard_write('interact', 120, 600);

try {
    $toolId = trim((string)($_POST['id'] ?? ''));
    if ($toolId === '' || !find_tool($toolId)) { sec_fail(422, 'Unknown tool'); }
    echo json_encode(['success' => true, 'clicks' => (int)record_click($toolId)]);
} catch (Throwable $e) {
    sec_log_exception($e, 'track-click.php');
    sec_fail(500, 'Something went wrong. Please try again later.');
}
