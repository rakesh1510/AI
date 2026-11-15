<?php
require_once __DIR__ . '/helpers.php';
http_response_code(404);
$title = "Page Not Found (404)";
$meta_desc = "The page you requested could not be found on AIHubpedia.";
require __DIR__ . '/layout_top.php';
?>
<h1>404 — Page not found</h1>
<p class="small">The page you requested does not exist or has moved.</p>
<form method="get" action="/" class="heroSearch" role="search" style="margin:16px 0">
  <label for="nfSearch" class="visuallyHidden">Search AI tools</label>
  <input class="input" id="nfSearch" type="search" name="q" placeholder="Search AI tools…">
  <button class="btn" type="submit">Search</button>
</form>
<div class="actions">
  <a class="btn" href="/">Go home</a>
  <a class="btn secondary" href="/categories.php">Browse categories</a>
  <a class="btn secondary" href="/top.php">Top AI tools</a>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
