    <?php
    require_once __DIR__ . '/../core/helpers.php';
    ext_header('AI Tool Collections', 'Curated lists like startup, designer, marketing, and coding collections.');
    ?>
    <div class="card"><h2>Curated Collections</h2><p class="small">Use this page as a new SEO section without changing your old directory pages.</p></div>
<div class="grid">
<?php $rows = ext_read_json(__DIR__ . '/../../data/collections.json'); foreach($rows as $r){ echo '<div class="card"><h3>'.htmlspecialchars($r['title']).'</h3><p class="small">Slug: '.htmlspecialchars($r['slug']).'</p><p><span class="badge">'.$r['count'].' tools</span></p></div>'; } ?>
</div>
    <?php ext_footer(); ?>
