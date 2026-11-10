<?php
require_once __DIR__ . '/../core/helpers.php';
ext_header('Leaderboard for Most Popular Tools', 'Ranked by clicks or ratings.');
?>
<?php $rows = ext_read_json(__DIR__ . '/../../data/leaderboard.json'); echo '<table><tr><th>Rank</th><th>Tool</th><th>Score</th></tr>'; $i=1; foreach($rows as $r){ echo '<tr><td>'.$i++.'</td><td>'.htmlspecialchars($r['tool']).'</td><td>'.htmlspecialchars($r['score']).'</td></tr>'; } echo '</table>'; ?>
<?php ext_footer(); ?>
