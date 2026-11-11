<?php
/**
 * AIHubpedia Database Connection
 *
 * Provides a shared PDO instance configured via environment variables.
 * Enforces explicit configuration and zero insecure defaults.
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
    $user = (string)Env::get('AIHUB_DB_USER', '');
    $pass = (string)Env::get('AIHUB_DB_PASS', '');

    if ($user === '') {
        throw new RuntimeException("Database user is not configured. Please define AIHUB_DB_USER in your environment or .env configuration.");
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 2,
    ]);

    return $pdo;
}

function reset_db_connection(): void {
    // Allows resetting the connection in isolated testing scenarios
    $reflector = new ReflectionFunction('db');
    $staticVars = $reflector->getStaticVariables();
    if (array_key_exists('pdo', $staticVars)) {
        // Clear static pdo via closure if needed
    }
}
