<?php
require_once 'helpers.php';
session_start();
if(!is_logged_in_demo()){ header('Location: login.php'); exit; }
$id = $_GET['id'] ?? '';
$uid = (string)current_demo_user_id();
$data = load_bookmarks_demo();
$list = array_map('strval', $data[$uid] ?? []);
if(in_array((string)$id, $list, true)){
  $list = array_values(array_filter($list, function($x) use ($id){ return (string)$x !== (string)$id; }));
} else {
  $list[] = (string)$id;
}
$data[$uid] = array_values(array_unique($list));
save_bookmarks_demo($data);
header('Location: tool.php?id=' . urlencode($id));
exit;