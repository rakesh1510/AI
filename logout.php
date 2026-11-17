<?php
require_once __DIR__ . '/helpers.php';
sec_require_demo();
sec_session_start();
unset($_SESSION['demo_user_id']);
header('Location: /index.php', true, 303);
exit;
