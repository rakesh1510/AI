
<?php
require_once __DIR__ . '/db.php';

$sql = file_get_contents(__DIR__ . '/database/aihub_interactions.sql');
$message = '';
try {
    db()->exec($sql);
    $message = 'Database tables created successfully. You can now delete or protect this file.';
} catch (Throwable $e) {
    $message = 'Database install failed: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Install AIHubpedia DB</title>
<style>body{font-family:Arial;background:#f6f6fb;padding:40px}.box{max-width:780px;margin:auto;background:#fff;border-radius:16px;padding:28px;box-shadow:0 8px 30px rgba(0,0,0,.08)}</style>
</head>
<body>
<div class="box">
  <h1>AIHubpedia database installer</h1>
  <p><?php echo htmlspecialchars($message, ENT_QUOTES); ?></p>
  <p>File used: <code>database/aihub_interactions.sql</code></p>
</div>
</body>
</html>
