<?php
require_once __DIR__ . '/../core/helpers.php';
ext_header('AI Industry Reports', 'Trend analysis and weekly market summaries.');
?>
<?php $rows = ext_read_json(__DIR__ . '/../../data/reports.json'); echo '<div class="grid">'; foreach($rows as $r){ echo '<div class="card"><h3>'.htmlspecialchars($r['title']).'</h3><p class="small">'.htmlspecialchars($r['slug']).'</p></div>'; } echo '</div>'; ?>
<?php ext_footer(); ?>
