<?php
require_once __DIR__ . '/helpers.php';
ext_header('AIHubpedia Widgets', 'Optional widgets you can include later without changing your old logic.');
$cards = [
 ['Tool of the Day','/extensions/recommendations/tool_of_day.php'],
 ['Collections','/extensions/collections/index.php'],
 ['Trending Report','/extensions/trending-report/index.php'],
 ['Recently Added','/extensions/analytics/recent.php'],
];
echo '<div class="grid">';
foreach ($cards as $c) {
 echo '<div class="card"><h3>'.htmlspecialchars($c[0]).'</h3><p class="small">Standalone module page</p><a href="'.$c[1].'">Open</a></div>';
}
echo '</div>';
ext_footer();
?>