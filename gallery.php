<?php
$title="Gallery — AI Tools Directory";
require __DIR__ . '/layout_top.php';
?>
<h1>Gallery</h1>
<p class="small">Sample images you can use for thumbnails/banners (optional).</p>
<div class="ad">Ad placeholder</div>
<div class="grid">
<?php
$dir = __DIR__ . '/assets/images';
$files = glob($dir . '/*.jpg');
sort($files);
foreach ($files as $f) {
  $name = basename($f);
  echo '<div class="card">';
  echo '<h3>'.htmlspecialchars($name).'</h3>';
  echo '<img src="assets/images/'.htmlspecialchars($name).'" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.08)" loading="lazy" />';
  echo '</div>';
}
?>
</div>
<?php require __DIR__ . '/layout_bottom.php'; ?>
