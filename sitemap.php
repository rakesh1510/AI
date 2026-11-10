<?php
header("Content-Type: application/xml; charset=utf-8");

require_once __DIR__ . '/helpers.php';

$base = "https://aihubpedia.com";

function xml_url($url) {
  return htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc><?= xml_url($base . '/') ?></loc>
    <lastmod><?= date('Y-m-d') ?></lastmod>
    <priority>1.0</priority>
  </url>

  <url>
    <loc><?= xml_url($base . '/categories') ?></loc>
    <lastmod><?= date('Y-m-d') ?></lastmod>
    <priority>0.9</priority>
  </url>

  <url>
    <loc><?= xml_url($base . '/compare') ?></loc>
    <lastmod><?= date('Y-m-d') ?></lastmod>
    <priority>0.8</priority>
  </url>

  <url>
    <loc><?= xml_url($base . '/submit') ?></loc>
    <lastmod><?= date('Y-m-d') ?></lastmod>
    <priority>0.7</priority>
  </url>

<?php
try {
  $pdo = db();
  $stmt = $pdo->query("SELECT slug FROM tools WHERE slug IS NOT NULL AND slug != '' ORDER BY id DESC");

  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $slug = trim($row['slug']);
    echo "  <url>\n";
    echo "    <loc>" . xml_url($base . "/tools/" . rawurlencode($slug)) . "</loc>\n";
    echo "    <lastmod>" . date('Y-m-d') . "</lastmod>\n";
    echo "    <priority>0.8</priority>\n";
    echo "  </url>\n";
  }
} catch (Exception $e) {
  // Keep sitemap valid even if database fails
}
?>
</urlset>