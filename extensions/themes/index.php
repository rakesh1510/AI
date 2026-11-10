<?php
require_once __DIR__ . '/../core/helpers.php';
ext_header('Dark Mode Interface', 'Theme switcher starter page.');
?>
<div class="card"><button onclick="document.body.style.background='#111';document.body.style.color='#eee'">Enable Dark Preview</button></div>
<?php ext_footer(); ?>
