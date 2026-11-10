<?php
function ext_read_json($file, $fallback = []) {
    if (!file_exists($file)) return $fallback;
    $raw = file_get_contents($file);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $fallback;
}
function ext_header($title, $subtitle = '') {
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . htmlspecialchars($title) . '</title>';
    echo '<link rel="stylesheet" href="/assets/css/extensions.css">';
    echo '</head><body><div class="wrap">';
    echo '<div class="hero"><h1>' . htmlspecialchars($title) . '</h1>';
    if ($subtitle) echo '<p class="small">' . htmlspecialchars($subtitle) . '</p>';
    echo '<div class="nav">
      <a class="badge" href="/extensions/collections/index.php">Collections</a>
      <a class="badge" href="/extensions/reports/index.php">Reports</a>
      <a class="badge" href="/extensions/events/index.php">Events</a>
      <a class="badge" href="/extensions/learning/index.php">Learning Hub</a>
      <a class="badge" href="/extensions/leaderboard/index.php">Leaderboard</a>
    </div></div>';
}
function ext_footer() {
    echo '</div></body></html>';
}
?>