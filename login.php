<?php
require_once 'helpers.php';
session_start();
$msg='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!sec_csrf_ok('login')){ $msg='Please reload the page and try again.'; }
  elseif(sec_rate_limited('demo_login', 8, 900)){ $msg='Too many attempts. Please try again later.'; sec_log('rate_limited',['scope'=>'demo_login']); }
  else {
  $users=load_users_demo();
  foreach(($users['users']??[]) as $u){
    if(($u['email']??'')===($_POST['email']??'') && ($u['password']??'')===($_POST['password']??'')){
      session_regenerate_id(true);
      $_SESSION['demo_user_id']=$u['id'];
      header('Location: /bookmarks.php', true, 303); exit;
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
<?= sec_csrf_field('login') ?>
<label>Email</label><input class="input" name="email" value="demo@aihubpedia.com">
<label style="margin-top:12px;display:block">Password</label><input class="input" type="password" name="password" value="demo123">
<p style="margin-top:14px"><button class="btn">Login</button></p>
</form>
</section>
<?php require __DIR__ . '/layout_bottom.php'; ?>