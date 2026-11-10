<?php
require_once __DIR__ . '/../core/helpers.php';
ext_header('Personalized Recommendations', 'Starter page for suggesting tools based on interests or searches.');
?>
<div class="card"><h2>Recommendation Engine</h2><p class="small">Add your own logic later from search history, tags, or clicks.</p><div class="grid"><div class="card"><h3>For marketers</h3><p>Copywriting, SEO, ad creatives.</p></div><div class="card"><h3>For developers</h3><p>Code assistants, testing, docs.</p></div><div class="card"><h3>For founders</h3><p>Pitch decks, analytics, research.</p></div></div></div>
<?php ext_footer(); ?>
