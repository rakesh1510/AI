
<?php
require_once __DIR__ . '/helpers.php';
$id = trim($_GET['id'] ?? '');
$t = $id ? find_tool($id) : null;

if(!$t){
  header('Location: index.php');
  exit;
}

record_click($id);
$u = tool_url($t);
if($u === '#'){ header('Location: tool.php?id=' . urlencode($id)); exit; }
header('Location: ' . $u);
