<?php
require_once __DIR__ . '/../core/helpers.php';
ext_header('Verified Company Profiles', 'Profiles for AI startups and companies.');
?>
<?php $rows = ext_read_json(__DIR__ . '/../../data/companies.json'); echo '<div class="grid">'; foreach($rows as $r){ echo '<div class="card"><h3>'.htmlspecialchars($r['name']).'</h3>'; if(!empty($r['verified'])) echo '<span class="badge">'.htmlspecialchars($r['badge']).'</span>'; echo '</div>'; } echo '</div>'; ?>
<?php ext_footer(); ?>
