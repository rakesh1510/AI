<?php
require_once __DIR__ . '/helpers.php';
$id = trim($_GET['id'] ?? '');
$t = $id ? find_tool($id) : null;

if(!$t){
  header('Location: index.php');
  exit;
}

record_click($id);
event_record_server('outbound_click', $id, is_affiliate_link($t) ? 'yes' : 'no', is_featured_tool($t) ? 'sponsored' : 'organic');
$u = tool_url($t);
if($u === '#'){ header('Location: tool.php?id=' . urlencode($id)); exit; }
header('Location: ' . $u);
