<?php
// Router for `php -S` that emulates the production Apache rules in .htaccess
// (static files, 404 ErrorDocument, sitemap.xml rewrite, blocked private paths).
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$root = $_SERVER['DOCUMENT_ROOT'];
if (preg_match('#^/(data|database|tests|scripts|\.git|\.github)(/|$)#', $path) || preg_match('#\.(sql|bak|log|ini|sh)$#', $path)
    || preg_match('#^/(composer\.(json|lock)|helpersold\.php|vote_debug\.php)$#', $path)) {
  $path = '/__blocked__';
}
if ($path === '/sitemap.xml' && is_file($root . '/sitemap.php')) {
  $_SERVER['SCRIPT_NAME'] = '/sitemap.php';
  require $root . '/sitemap.php';
  return true;
}
if ($path === '/') $path = '/index.php';
$file = realpath($root . $path);
if ($file && strpos($file, realpath($root)) === 0 && is_file($file)) {
  if (substr($file, -4) === '.php') {
    $_SERVER['SCRIPT_NAME'] = $path;
    $_SERVER['SCRIPT_FILENAME'] = $file;
    chdir(dirname($file));
    require $file;
    return true;
  }
  return false; // let the built-in server serve static files
}
http_response_code(404);
$_SERVER['SCRIPT_NAME'] = '/404.php';
if (is_file($root . '/404.php')) { require $root . '/404.php'; } else { echo 'Not Found'; }
return true;
