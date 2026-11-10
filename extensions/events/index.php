<?php
require_once __DIR__ . '/../core/helpers.php';
ext_header('AI Events Calendar', 'Conferences, webinars, and meetups.');
?>
<?php $rows = ext_read_json(__DIR__ . '/../../data/events.json'); echo '<table><tr><th>Date</th><th>Event</th><th>Location</th></tr>'; foreach($rows as $r){ echo '<tr><td>'.htmlspecialchars($r['date']).'</td><td>'.htmlspecialchars($r['name']).'</td><td>'.htmlspecialchars($r['location']).'</td></tr>'; } echo '</table>'; ?>
<?php ext_footer(); ?>
