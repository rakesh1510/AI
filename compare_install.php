<?php
require_once __DIR__ . '/db.php';

$sqlFile = __DIR__ . '/database/install_compare_tables.sql';
if(!file_exists($sqlFile)){
    fwrite(STDERR, "Missing SQL file: {$sqlFile}\n");
    exit(1);
}

$sql = file_get_contents($sqlFile);
$pdo = db();
$pdo->exec($sql);
echo "Compare tables installed successfully.\n";
