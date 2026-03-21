
<?php
require_once __DIR__ . '/helpers.php';
$title = "404 — AIHubpedia";
http_response_code(404);
require __DIR__ . '/layout_top.php';
?>
<h1>404 — Page not found</h1>
<p class="small">The page you requested does not exist.</p>
<div class="actions">
  <a class="btn" href="index.php">Go home</a>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
