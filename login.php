<?php
require_once __DIR__ . '/helpers.php';
Auth::startSession();
$msg='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!csrf_validate()){
    $msg = 'Security check failed. Please refresh and try again.';
  } else {
    $users=load_users_demo();
    foreach(($users['users']??[]) as $u){
      if(($u['email']??'')===($_POST['email']??'') && ($u['password']??'')===($_POST['password']??'')){
        session_regenerate_id(true);
        $_SESSION['demo_user_id']=$u['id'];
        header('Location: bookmarks.php'); exit;
      }
    }
    $msg='Invalid demo login';
  }
}
require __DIR__ . '/layout_top.php';
?>
<section class="hero"><h1>Demo Login</h1><p class="small">Use demo@aihubpedia.com / demo123</p></section>
<section class="card">
<?php if($msg): ?><div class="noteBox"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<form method="post">
<?= csrf_field() ?>
<label>Email</label><input class="input" name="email" value="demo@aihubpedia.com">
<label style="margin-top:12px;display:block">Password</label><input class="input" type="password" name="password" value="demo123">
<p style="margin-top:14px"><button class="btn">Login</button></p>
</form>
</section>
<?php require __DIR__ . '/layout_bottom.php'; ?>