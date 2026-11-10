<?php
require_once 'helpers.php';
$data=load_tools();
$tools=$data['tools'] ?? [];
$id=time();
$tools[]=['id'=>$id,'name'=>$_POST['name'] ?? 'Untitled','short'=>$_POST['description'] ?? '','url'=>$_POST['url'] ?? '','category'=>'Community'];
save_tools($tools);
echo "Tool submitted";
