    <?php
    require_once __DIR__ . '/../core/helpers.php';
    ext_header('AI Tool of the Day', 'Daily spotlight module.');
    ?>
    <?php $sample = ['ChatGPT','Claude','Midjourney','Cursor','Perplexity']; $tool = $sample[date('j') % count($sample)]; ?>
<div class="card"><h2>Today's Pick</h2><p><span class="badge">Featured Today</span></p><h3><?php echo htmlspecialchars($tool); ?></h3><p class="small">Rotate this daily on your homepage later if you want.</p></div>
    <?php ext_footer(); ?>
