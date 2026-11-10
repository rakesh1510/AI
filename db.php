<?php
/**
 * AIHubpedia Database Connection
 *
 * Provides a shared PDO instance configured via environment variables.
 * Zero hardcoded credentials.
 */

require_once __DIR__ . '/src/Env.php';

function db(): PDO {
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = (string)Env::get('AIHUB_DB_HOST', 'localhost');
    $port = (int)Env::get('AIHUB_DB_PORT', 3306);
    $dbname = (string)Env::get('AIHUB_DB_NAME', 'aihub');
    $user = (string)Env::get('AIHUB_DB_USER', 'root');
    $pass = (string)Env::get('AIHUB_DB_PASS', '');

    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 2,
    ]);

    return $pdo;
}
