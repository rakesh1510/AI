<?php
require_once __DIR__ . '/../core/helpers.php';
ext_header('Advanced Filter System', 'Filter by price, platform, language, features, or industry.');
?>
<div class="card"><h2>Filters</h2><div class="grid"><div><label>Price</label><select><option>Free</option><option>Freemium</option><option>Paid</option></select></div><div><label>Platform</label><select><option>Web</option><option>iOS</option><option>Android</option></select></div><div><label>Industry</label><select><option>Marketing</option><option>Coding</option><option>Design</option></select></div></div></div>
<?php ext_footer(); ?>
