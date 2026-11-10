<?php
require_once __DIR__ . '/db.php';
header('Content-Type: text/plain; charset=utf-8');

$sqlFile = __DIR__ . '/database/full_mysql_schema.sql';
if (!file_exists($sqlFile)) {
    exit("Schema file not found.\n");
}

$sql = file_get_contents($sqlFile);
if ($sql === false) {
    exit("Could not read schema file.\n");
}

try {
    db()->exec($sql);
    echo "Full MySQL schema installed successfully.\n";
    echo "Now open import-json-to-mysql.php to import existing JSON data.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "Installation failed: " . $e->getMessage() . "\n";
}
