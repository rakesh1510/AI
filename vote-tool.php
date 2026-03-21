<?php
require_once 'helpers.php';
session_start();
$toolId = trim($_GET['id'] ?? '');
$type = $_GET['type'] ?? 'up';
$return = trim($_GET['return'] ?? '');
if($toolId !== ''){
  vote_tool_action($toolId, $type);
}

$allowedPages = [
  'index.php','top.php','categories.php','category.php','community.php','leaderboard.php',
  'tool.php','compare.php','compare-tools.php','filters.php','ai-use-case-explorer.php'
];
$target = 'tool.php?id=' . urlencode($toolId) . '#community';
if($return !== ''){
  $returnPath = parse_url($return, PHP_URL_PATH) ?: $return;
  $baseName = basename($returnPath);
  if(in_array($baseName, $allowedPages, true)){
    $target = $return;
  }
}
header('Location: ' . $target);
exit;
