<?php
require_once 'helpers.php';
session_start();
$toolId = $_POST['tool_id'] ?? '';
$name = trim($_POST['name'] ?? 'Anonymous');
$comment = trim($_POST['comment'] ?? '');
if($toolId !== '' && $comment !== ''){
  $rows = load_tool_comments();
  $rows[] = [
    'tool_id' => $toolId,
    'name' => $name,
    'comment' => $comment,
    'created_at' => date('Y-m-d H:i:s')
  ];
  save_tool_comments($rows);
}
header('Location: tool.php?id=' . urlencode($toolId) . '#community');
exit;