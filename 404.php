<?php
require_once __DIR__ . '/helpers.php';
http_response_code(404);
$title = "Page Not Found (404)";
$meta_desc = "The page you requested could not be found on AIHubpedia.";
$main_class = 'fullbleed';
require __DIR__ . '/layout_top.php';
echo '<div class="wrap pgBody">' . state_box(['art' => 'lost', 'eyebrow' => 'Error 404', 'title' => '404 — Page not found', 'text' => 'The page you requested does not exist or has moved.',
  'actions' => '<a class="btn btn--accent" href="/">Go home</a><a class="btn secondary" href="/categories.php">Browse categories</a><a class="btn secondary" href="/top.php">Top AI tools</a>']) . '</div>';
?>
<div class="wrap pgBody" style="padding-top:0">
  <form method="get" action="/search.php" class="notFoundSearch glass" role="search">
    <label for="nfSearch" class="sr">Search AI tools</label>
    <input class="input" id="nfSearch" type="search" name="q" placeholder="Search AI tools…">
    <button class="btn" type="submit">Search</button>
  </form>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
