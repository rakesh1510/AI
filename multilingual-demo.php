<?php
// Retired demo: it used to show unreviewed machine translations. AIHubpedia is English only until a qualified human reviewer approves
// each translated page (see data/locales.json and docs/phase5/INTERNATIONALIZATION_PLAN.md). The page stays noindex and states this plainly.
require __DIR__ . '/layout_top.php'; ?>
<section class="hero"><h1>Languages</h1><p class="small">AIHubpedia is currently available in English only.</p></section>
<section class="card">
<p>We plan to offer German, French, Spanish and Hindi. We will publish a translated page only after a qualified person has reviewed it, because an unchecked machine translation of tool pricing or features could mislead readers. Until then, no translated content is published.</p>
<p class="small"><a href="/methodology.php">How AIHubpedia reviews information</a></p>
</section>
<?php require __DIR__ . '/layout_bottom.php'; ?>
