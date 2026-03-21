<?php
require_once __DIR__ . '/../core/helpers.php';
ext_header('AI Use-Case Finder', 'Choose a problem and see suggested tools.');
?>
<div class="card"><label>What do you want to do?</label><select><option>Write blog posts</option><option>Generate images</option><option>Code faster</option></select><p style="margin-top:10px"><button>Find Tools</button></p></div>
<?php ext_footer(); ?>
