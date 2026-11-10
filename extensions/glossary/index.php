<?php
require_once __DIR__ . '/../core/helpers.php';
ext_header('AI Glossary', 'Common AI terms and concepts.');
?>
<?php $rows = ext_read_json(__DIR__ . '/../../data/glossary.json'); echo '<table><tr><th>Term</th><th>Meaning</th></tr>'; foreach($rows as $r){ echo '<tr><td>'.htmlspecialchars($r['term']).'</td><td>'.htmlspecialchars($r['meaning']).'</td></tr>'; } echo '</table>'; ?>
<?php ext_footer(); ?>
