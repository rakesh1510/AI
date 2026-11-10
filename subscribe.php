
<?php
require_once __DIR__ . '/helpers.php';
$email = trim($_POST['email'] ?? '');
if($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)){
  $subs = read_json('subscribers.json', []);
  $subs[] = ['email'=>$email, 'ts'=>date('c')];
  write_json('subscribers.json', $subs);
}
header('Location: index.php');
