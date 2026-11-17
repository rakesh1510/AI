<?php
require_once __DIR__ . '/helpers.php';
// Public comments: POST + CSRF + rate limit, known tools only, length caps. Output is escaped where it is displayed.
sec_guard_write('comment', 6, 3600, false);
$toolId = trim((string)($_POST['tool_id'] ?? ''));
$name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 60);
if ($name === '') $name = 'Anonymous';
$comment = mb_substr(trim((string)($_POST['comment'] ?? '')), 0, 1500);
if (!empty($_POST['website_url_confirm'])) { sec_log('comment_honeypot'); header('Location: /index.php', true, 303); exit; }
if ($toolId !== '' && $comment !== '' && find_tool($toolId)) {
  $rows = load_tool_comments();
  $rows[] = ['tool_id' => $toolId, 'name' => $name, 'comment' => $comment, 'created_at' => date('Y-m-d H:i:s')];
  save_tool_comments(array_slice($rows, -5000));
}
header('Location: /tool.php?id=' . urlencode($toolId) . '#community', true, 303);
exit;
