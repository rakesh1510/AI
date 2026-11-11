<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/src/Auth.php';

Auth::startSession();

if (isset($_SESSION['demo_user_id'])) {
    unset($_SESSION['demo_user_id']);
}

if (Auth::isAdminLoggedIn()) {
    Auth::adminLogout();
}

header('Location: index.php');
exit;
