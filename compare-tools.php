<?php
$query = [];
if(isset($_GET['a'])) $query['a'] = (string)$_GET['a'];
if(isset($_GET['b'])) $query['b'] = (string)$_GET['b'];
$target = 'compare.php';
if($query) $target .= '?' . http_build_query($query);
header('Location: ' . $target, true, 302);
exit;
