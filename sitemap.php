<?php
header("Content-Type: application/xml; charset=utf-8");

require 'helpers.php';

$base = "https://aihubpedia.com";

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">

<url>
<loc><?php echo $base; ?>/</loc>
<priority>1.0</priority>
</url>

<url>
<loc><?php echo $base; ?>/categories</loc>
<priority>0.9</priority>
</url>

<url>
<loc><?php echo $base; ?>/compare</loc>
<priority>0.8</priority>
</url>

<url>
<loc><?php echo $base; ?>/submit</loc>
<priority>0.7</priority>
</url>

<?php

try {

$pdo = db();

$stmt = $pdo->query("SELECT slug FROM tools");

while($row = $stmt->fetch()){

$slug = $row['slug'];

echo "<url>";
echo "<loc>$base/tools/$slug</loc>";
echo "<priority>0.8</priority>";
echo "</url>";

}

}catch(Exception $e){}

?>

</urlset>