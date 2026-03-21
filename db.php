<?php

function db(): PDO {
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = getenv('AIHUB_DB_HOST') ?: 'localhost';
    $dbname = getenv('AIHUB_DB_NAME') ?: 'aihub';
    $user = getenv('AIHUB_DB_USER') ?: 'pmaadmin';
    $pass = getenv('AIHUB_DB_PASS') ?: 'Romil@15101990!1319';

    $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}
