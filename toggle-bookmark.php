<?php
require_once __DIR__ . '/helpers.php';
// Demo bookmarks: POST + CSRF (a GET link could be triggered by any third-party page).
session_start();
if(!is_logged_in_demo()){ header('Location: /login.php', true, 303); exit; }
sec_guard_write('bookmark', 30, 600, false);
$id = trim((string)($_POST['id'] ?? ''));
if($id === '' || !find_tool($id)){ header('Location: /index.php', true, 303); exit; }
$uid = (string)current_demo_user_id();
$data = load_bookmarks_demo();
$list = array_map('strval', $data[$uid] ?? []);
if(in_array($id, $list, true)){
  $list = array_values(array_filter($list, function($x) use ($id){ return (string)$x !== $id; }));
} else {
  $list[] = $id;
}
$data[$uid] = array_values(array_unique($list));
save_bookmarks_demo($data);
header('Location: /tool.php?id=' . urlencode($id), true, 303);
exit;
