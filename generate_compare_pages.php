<?php
// Legacy generator is CLI-only and creates noindex drafts, never indexable landing pages.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/helpers.php';
$data = load_tools();
$tools = $data['tools'] ?? [];
$max = (int)($argv[1] ?? 100); // number of pages
$dir = __DIR__ . '/compare';
if(!is_dir($dir)) mkdir($dir, 0755, true);

function slugify($s){
  $s = strtolower(trim($s));
  $s = preg_replace('/[^a-z0-9]+/', '-', $s);
  return trim($s,'-');
}

$created = 0;
for($i=0; $i<count($tools) && $created<$max; $i++){
  for($j=$i+1; $j<count($tools) && $created<$max; $j++){
    $a = $tools[$i]; $b = $tools[$j];
    // Draft comparisons must at least concern tools in the same category.
    if(($a['category'] ?? '') !== ($b['category'] ?? '')) continue;

    $fn = slugify($a['name'] . '-vs-' . $b['name']) . '.php';
    $path = $dir . '/' . $fn;

    $title = $a['name'] . ' vs ' . $b['name'] . ' — AIHubpedia';
    $body = "<?php\n"
      . "\$title=" . var_export($title,true) . ";\n"
      . "\$robots_meta='noindex,follow';\n"
      . "require __DIR__ . '/../layout_top.php';\n"
      . "?>\n"
      . "<h1>" . htmlspecialchars($a['name']) . " vs " . htmlspecialchars($b['name']) . "</h1>\n"
      . "<p class='small'>Comparison draft. Confirm product details with provider sources.</p>\n"
      . "<div class='actions'><a class='btn secondary' href='../tool.php?id=" . urlencode($a['id']) . "'>Details: " . htmlspecialchars($a['name']) . "</a>"
      . "<a class='btn secondary' href='../tool.php?id=" . urlencode($b['id']) . "'>Details: " . htmlspecialchars($b['name']) . "</a>"
      . "<a class='btn' href='../compare.php?a=" . urlencode($a['id']) . "&b=" . urlencode($b['id']) . "'>Compare interactively</a></div>\n"
      . "<hr>\n"
      . "<table><tr><th>" . htmlspecialchars($a['name']) . "</th><th>" . htmlspecialchars($b['name']) . "</th></tr>"
      . "<tr><td><b>Category:</b> " . htmlspecialchars($a['category'] ?? '') . "</td><td><b>Category:</b> " . htmlspecialchars($b['category'] ?? '') . "</td></tr>"
      . "<tr><td><b>Pricing:</b> " . htmlspecialchars($a['pricing'] ?? '') . "</td><td><b>Pricing:</b> " . htmlspecialchars($b['pricing'] ?? '') . "</td></tr>"
      . "<tr><td>" . htmlspecialchars($a['short'] ?? '') . "</td><td>" . htmlspecialchars($b['short'] ?? '') . "</td></tr>"
      . "</table>\n"
      . "<?php require __DIR__ . '/../layout_bottom.php'; ?>\n";

    file_put_contents($path, $body);
    $created++;
  }
}

echo "Created {$created} pages in {$dir}\n";

