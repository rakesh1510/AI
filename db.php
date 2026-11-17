<?php
require_once __DIR__ . '/security.php';

/**
 * Database connection. Credentials come ONLY from the environment (AIHUB_DB_*) or the untracked config.local.php
 * (['db' => ['host' =>, 'name' =>, 'user' =>, 'pass' =>]]). There are deliberately no fallback credentials: without
 * configuration this throws, and callers fall back to the JSON catalogue.
 */
function db(): PDO {
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = sec_setting('AIHUB_DB_HOST', 'db.host', 'localhost');
    $dbname = sec_setting('AIHUB_DB_NAME', 'db.name', 'aihub');
    $user = sec_setting('AIHUB_DB_USER', 'db.user');
    $pass = sec_setting('AIHUB_DB_PASS', 'db.pass');

    if ($user === '' || $pass === '') {
        throw new RuntimeException('Database credentials are not configured (set AIHUB_DB_USER / AIHUB_DB_PASS).');
    }

    $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}
