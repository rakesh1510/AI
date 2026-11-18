<?php
// Router for `php -S` that emulates the production Apache rules in .htaccess
// (static files, 404 ErrorDocument, sitemap.xml rewrite, blocked private paths).
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$root = $_SERVER['DOCUMENT_ROOT'];
if (preg_match('#^/(data|database|tests|scripts|extensions|vendor|docs|qa-screenshots-local|nslookup|\.git|\.github)(/|$)#', $path) || preg_match('#(^|/)\.(?!well-known/)#', $path) || preg_match('#\.(md|lock|yml|yaml|dist|template)$#', $path) || preg_match('#^/(README[A-Za-z_]*\.txt|PROJECT_STATUS\.txt|NEW_LAYOUT_README\.txt)$#', $path) || preg_match('#\.(sql|bak|log|ini|sh)$#', $path)
    || preg_match('#^/(composer\.(json|lock)|helpersold\.php|vote_debug\.php|(seo|catalog|content|analytics|sitemap_lib|helpers|db|discovery|ui|mailer|conversion|security|admin_auth|evidence|leads_admin|recommend_engine|editorial|editorial_review|i18n|crm_core|crm_sales|crm_money|crm_growth|crm_dashboard|crm_intake|crm_views|cache|layout_top|layout_bottom)\.php|config\.local\.php|(install-database|install-full-mysql|import-json-to-mysql|generate_compare_pages|compare_(install|sync_catalog|refresh_all|fetch_source|enrich_tool))\.php)$#', $path)) {
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
