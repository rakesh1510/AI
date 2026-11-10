<?php
require_once __DIR__ . '/../core/helpers.php';
ext_header('Tool Request Feature', 'Let users request tools they want listed.');
?>
<div class="card"><label>Requested Tool</label><input placeholder="Tool name"><label style="margin-top:10px;display:block">Why should it be listed?</label><textarea rows="5"></textarea><p style="margin-top:10px"><button>Submit Request</button></p></div>
<?php ext_footer(); ?>
