<?php
/**
 * Builds the production assets (committed, because deployments have no build step):
 *   assets/css/site.min.css   = style.css + ui.css, comments and whitespace removed (one render-blocking request instead of two)
 *   assets/js/app.min.js, assets/js/interactions.min.js   (terser, if installed)
 *   assets/build.json         = SHA-1 of every source, used by tests to prove the built files are current
 *
 * Usage: php scripts/build_assets.php          rebuild
 *        php scripts/build_assets.php --check  exit 1 if the built files are stale (used by CI)
 * Pages fall back to the readable source files automatically when a built file is missing.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$check = in_array('--check', $argv, true);

function css_minify(string $css): string {
  $css = preg_replace('!/\*.*?\*/!s', '', $css);
  $css = preg_replace('/\s+/', ' ', $css);
  $css = preg_replace('/\s*([{};,>])\s*/', '$1', $css);
  $css = str_replace(';}', '}', $css);
  return trim($css);
}

$sources = ['assets/css/style.css', 'assets/css/ui.css', 'assets/js/app.js', 'assets/js/interactions.js'];
$hashes = [];
foreach ($sources as $s) $hashes[$s] = sha1_file("$root/$s");
$buildFile = "$root/assets/build.json";
$prev = is_file($buildFile) ? (json_decode(file_get_contents($buildFile), true)['sources'] ?? []) : [];
$outputs = ['assets/css/site.min.css', 'assets/js/app.min.js', 'assets/js/interactions.min.js'];

if ($check) {
  $stale = [];
  foreach ($hashes as $s => $h) if (($prev[$s] ?? '') !== $h) $stale[] = $s;
  foreach ($outputs as $o) if (!is_file("$root/$o")) $stale[] = $o . ' (missing)';
  if ($stale) { fwrite(STDERR, "Built assets are stale or missing: " . implode(', ', $stale) . "\nRun: php scripts/build_assets.php\n"); exit(1); }
  echo "Built assets are up to date.\n"; exit(0);
}

$css = css_minify(file_get_contents("$root/assets/css/style.css") . "\n" . file_get_contents("$root/assets/css/ui.css"));
file_put_contents("$root/assets/css/site.min.css", $css . "\n");
printf("site.min.css: %d bytes (from %d)\n", strlen($css), filesize("$root/assets/css/style.css") + filesize("$root/assets/css/ui.css"));

$terser = trim((string)shell_exec('command -v terser 2>/dev/null'));
foreach (['app', 'interactions'] as $js) {
  $src = "$root/assets/js/$js.js"; $dst = "$root/assets/js/$js.min.js";
  if ($terser !== '') {
    $cmd = escapeshellarg($terser) . ' ' . escapeshellarg($src) . ' --compress --mangle --output ' . escapeshellarg($dst) . ' 2>&1';
    exec($cmd, $o, $code);
    if ($code !== 0) { fwrite(STDERR, "terser failed for $js.js:\n" . implode("\n", $o) . "\n"); exit(1); }
  } else {
    copy($src, $dst);   // no minifier installed: ship the readable file under the .min name
    echo "terser not found: copied $js.js unminified\n";
  }
  printf("%s.min.js: %d bytes (from %d)\n", $js, filesize($dst), filesize($src));
}
file_put_contents($buildFile, json_encode(['built' => date('Y-m-d'), 'sources' => $hashes], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "Wrote assets/build.json\n";
