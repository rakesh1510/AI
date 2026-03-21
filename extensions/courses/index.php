<?php
require_once __DIR__ . '/../core/helpers.php';
ext_header('AI Course Directory', 'Courses for learning AI skills.');
?>
<?php $rows = ext_read_json(__DIR__ . '/../../data/courses.json'); echo '<div class="grid">'; foreach($rows as $r){ echo '<div class="card"><h3>'.htmlspecialchars($r['title']).'</h3><p class="small">'.htmlspecialchars($r['provider']).'</p></div>'; } echo '</div>'; ?>
<?php ext_footer(); ?>
