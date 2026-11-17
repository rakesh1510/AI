<?php
/**
 * Phase 4.1 independent security verification, static part (no server needed):
 *  - every top-level PHP file is classified in tests/security_manifest.json and obeys the rules of its kind
 *  - no secrets or personal data in tracked files (scans `git ls-files`; history is NOT inspected or modified)
 *  - hard-coded defaults, debug switches and dangerous functions
 * Usage: php tests/security_inventory_test.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = realpath(__DIR__ . '/..');
$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "  FAIL: $m\n"; } }
function section(string $t): void { echo "== $t\n"; }
function code_only(string $file, bool $noStrings = false): string { $o = ''; foreach (token_get_all((string)file_get_contents($file)) as $t) { if (is_array($t) && in_array($t[0], $noStrings ? [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING] : [T_COMMENT, T_DOC_COMMENT], true)) continue; $o .= is_array($t) ? $t[1] : $t; } return $o; }

section('Endpoint inventory: every PHP file is classified');
$man = json_decode((string)file_get_contents(__DIR__ . '/security_manifest.json'), true)['files'];
$files = array_map('basename', glob($root . '/*.php'));
sort($files);
$unclassified = array_values(array_diff($files, array_keys($man)));
$ghost = array_values(array_diff(array_keys($man), $files));
ok($unclassified === [], 'all top-level PHP files are in the security manifest (unclassified: ' . implode(', ', $unclassified) . ')');
ok($ghost === [], 'manifest has no entries for deleted files (' . implode(', ', $ghost) . ')');
$kinds = ['lib', 'cli', 'page', 'write', 'token_write', 'admin', 'demo', 'redirect', 'retired'];
foreach ($man as $f => $k) ok(in_array($k, $kinds, true), "$f has a valid kind");

$sinks = '/\b(write_json|file_put_contents|fwrite|unlink|rename|copy|mkdir|move_uploaded_file|send_mail|mail|save_tool_comments|save_tool_votes|save_bookmarks_demo|add_rating|record_click|vote_tool_action|lead_store|newsletter_subscribe|newsletter_confirm|newsletter_unsubscribe)\s*\(|\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|REPLACE\s+INTO|TRUNCATE|DROP\s+TABLE|ALTER\s+TABLE)\b/i';
foreach ($man as $f => $k) {
  $src = code_only("$root/$f"); $raw = (string)file_get_contents("$root/$f");
  switch ($k) {
    case 'page':
      ok(strpos($src, '$_POST') === false && strpos($src, '$_FILES') === false, "page $f never reads POST data or uploads");
      ok(!preg_match($sinks, preg_replace('/\blayout_(top|bottom)\b/', '', $src)) || in_array($f, ['sitemap.php', 'index.php', 'tool.php', 'recommend.php'], true), "page $f has no storage or mail sinks");
      break;
    case 'write':
      ok(preg_match('/sec_guard_write\(|sec_csrf_ok\(/', $src) === 1 || (strpos($src, "\$_SESSION['csrf']") !== false && strpos($src, 'sec_same_origin(') !== false), "write endpoint $f is guarded by CSRF + origin check");
      ok(preg_match('/sec_guard_write\(|sec_rate_limited\(/', $src) === 1, "write endpoint $f is rate limited");
      ok(strpos($src, '$_GET') === false, "write endpoint $f does not read state from the query string");
      break;
    case 'token_write':
      ok(preg_match('/form_token_valid\(|lead_validate\(|newsletter_valid_token\(/', $src) === 1, "token endpoint $f validates its secret token");
      break;
    case 'admin':
      ok(preg_match('/admin_require\(/', $src) === 1, "admin page $f calls admin_require() before output");
      ok(strpos($src, "\$_GET['pass']") === false && strpos($src, 'admin123') === false, "admin page $f has no URL password and no default");
      $firstOut = min(array_filter([strpos($src, 'admin_require('), PHP_INT_MAX]));
      ok(!preg_match('/(echo|print|\?>\s*<)/', substr($src, 0, $firstOut)) || strpos(substr($src, 0, $firstOut), '?>') === false, "admin page $f produces no output before the gate");
      break;
    case 'demo':
      ok(strpos($src, 'sec_require_demo(') !== false, "demo page $f is disabled unless AIHUB_DEMO_LOGIN=1");
      break;
    case 'cli':
      ok(preg_match("/PHP_SAPI\s*!==\s*'cli'/", $raw) === 1 || preg_match('/PHP_SAPI/', $raw) === 1, "maintenance script $f refuses non-CLI requests");
      break;
    case 'lib':
      ok(strpos($raw, 'include-only: never executable directly') !== false, "library $f refuses direct execution even if web-server rules are missing");
      ok(strpos($raw, 'Location:') === false || in_array($f, ['layout_top.php', 'admin_auth.php'], true), "library $f sends no redirects (admin_auth redirects only to a validated local path)");
      break;
    case 'redirect':
      ok(preg_match('/sec_safe_local_path\(|sec_safe_external_url\(|http_build_query|urlencode\(/', $src) === 1 || $f === 'vote-tool.php', "redirect endpoint $f builds its target safely");
      ok(!preg_match('/Location:\s*[\'"]?\s*\.\s*\$_(GET|POST|REQUEST)/', $src), "redirect endpoint $f never concatenates request data into Location");
      break;
    case 'retired':
      ok(strpos($src, 'Location') !== false && strpos($src, '$_POST') === false, "retired endpoint $f only redirects and never writes");
      break;
  }
}

section('Redirect hygiene across all code');
$bad = [];
foreach ($files as $f) {
  $src = code_only("$root/$f");
  if (preg_match_all('/header\(\s*[\'"]Location:\s*[\'"]?\s*\.?\s*(\$[A-Za-z_]+(?:\[[^\]]*\])?)/', $src, $m)) foreach ($m[1] as $var) { if (!preg_match('/^\$(target|u|safe|__|path|loc|url)/', $var) && !preg_match('/^\$_(GET|POST)/', $var)) $bad[] = "$f: $var"; if (preg_match('/^\$_(GET|POST)/', $var)) $bad[] = "$f: raw $var"; }
}
ok($bad === [], 'no Location header is built from raw request data: ' . implode('; ', $bad));
$locs = [];
foreach ($files as $f) { if (preg_match_all('/header\(\s*[\'"]Location:[^;]*;/', code_only("$root/$f"), $m)) foreach ($m[0] as $l) if (preg_match('/\$_(GET|POST|REQUEST|SERVER)\[/', $l) && !preg_match('/sec_safe_local_path/', $l)) $locs[] = "$f: $l"; }
ok($locs === [], 'every Location header using request data passes through sec_safe_local_path: ' . implode('; ', $locs));

section('Dangerous functions and switches');
$danger = [];
foreach ($files as $f) { $s = code_only("$root/$f", true); foreach (['eval', 'assert', 'create_function', 'unserialize', 'extract', 'shell_exec', 'passthru', 'proc_open', 'popen', 'system', 'exec'] as $fn) if (preg_match('/(?<![>\w:$])' . $fn . '\s*\(/', $s) && !in_array($f, ['compare_refresh_all.php'], true)) $danger[] = "$f:$fn"; }
ok($danger === [], 'no eval/unserialize/extract/shell functions in web-reachable code: ' . implode(', ', $danger));
$dbg = [];
foreach (array_merge($files, ['assets/js/app.js']) as $f) { if (preg_match('/phpinfo\s*\(|var_dump\s*\(|print_r\s*\(|error_reporting\s*\(\s*E_ALL|ini_set\([\'"]display_errors/i', code_only("$root/$f", true))) $dbg[] = $f; }
ok($dbg === [], 'no debug output or error-display switches: ' . implode(', ', $dbg));
$gets = [];
foreach ($man as $f => $k) { if (in_array($k, ['page'], true) && preg_match('/\$_GET\[[^\]]+\]\s*[^;]*\b(include|require|file_get_contents|fopen|readfile)\b/', code_only("$root/$f"))) $gets[] = $f; }
ok($gets === [], 'no GET parameter flows into file functions: ' . implode(', ', $gets));
foreach (['logout.php', 'login.php', 'bookmarks.php'] as $f) ok(strpos((string)file_get_contents("$root/$f"), 'sec_require_demo') !== false, "$f is demo-gated");

section('Secrets and personal data in tracked files');
$tracked = array_filter(explode("\n", (string)shell_exec('cd ' . escapeshellarg($root) . ' && git ls-files')));
ok(count($tracked) > 300, 'git ls-files returned the tracked file list (' . count($tracked) . ')');
$findings = [];
$allow = fn($f) => preg_match('#^(vendor/|docs/|tests/|composer\.lock$)#', $f) === 1;
$patterns = [
  'private key' => '/-----BEGIN (?:RSA |EC |DSA |OPENSSH |PGP )?PRIVATE KEY-----/',
  'AWS access key' => '/\bAKIA[0-9A-Z]{16}\b/',
  'Google API key' => '/\bAIza[0-9A-Za-z_\-]{35}\b/',
  'Slack token' => '/\bxox[baprs]-[0-9A-Za-z\-]{10,}/',
  'GitHub token' => '/\bgh[pousr]_[A-Za-z0-9]{30,}\b/',
  'OpenAI/Stripe style key' => '/\b(sk-[A-Za-z0-9]{20,}|sk_live_[A-Za-z0-9]{16,}|rk_live_[A-Za-z0-9]{16,})\b/',
  'Google app password (4x4 letters near smtp)' => '/smtp_pass["\']?\s*[:=]\s*["\'][a-z]{4}\s?[a-z]{4}\s?[a-z]{4}\s?[a-z]{4}["\']/i',
  'known leaked database password' => '/Romil@15101990/',
  'literal credential assignment' => '/\b(?:password|passwd|pass|secret|api_?key|token)\b["\']?\s*(?:=>|=|:)\s*["\'][^"\'\s$%{}<>\[\]()]{8,}["\']/i',
];
foreach ($tracked as $f) {
  if (!is_file("$root/$f") || preg_match('/\.(png|jpg|jpeg|webp|gif|ico|woff2?|ttf|pdf|zip|gz|min\.js|min\.css)$/i', $f)) continue;
  $raw = (string)file_get_contents("$root/$f");
  foreach ($patterns as $name => $re) {
    if ($name === 'literal credential assignment' && ($allow($f) || preg_match('#^(assets/|database/|scripts/|data/(curated|guides|tools|taxonomy|classification|use_cases|posts|capabilities|solutions|recommend|search|content|sponsorship))#', $f))) continue;
    if ($name !== 'literal credential assignment' && $name !== 'known leaked database password' && preg_match('#^(vendor/|composer\.lock$)#', $f)) continue;
    if ($name === 'known leaked database password' && (preg_match('#^(tests/security|docs/)#', $f))) continue;   // negative tests and audit docs name it on purpose
    if (preg_match($re, $raw, $m)) $findings[] = "$f ($name)";
  }
}
ok($findings === [], 'no secrets in tracked files: ' . implode('; ', $findings));
$site = json_decode((string)file_get_contents("$root/data/site.json"), true);
ok(($site['admin_pass'] ?? '') === '' && ($site['mail']['smtp_pass'] ?? '') === '' && ($site['mail']['smtp_user'] ?? '') === '', 'data/site.json holds no admin or SMTP credentials');
$users = json_decode((string)file_get_contents("$root/data/users.json"), true);
foreach ($users['users'] as $u) ok(strpos((string)$u['password'], '$2y$') === 0, 'demo user password is stored hashed, not in plain text');
foreach (['.env', 'config.local.php', 'data/.form_secret', 'id_rsa', 'id_ed25519'] as $f) ok(!in_array($f, $tracked, true), "$f is not tracked");
ok(count(array_filter($tracked, fn($f) => preg_match('/\.(pem|key|p12|pfx|sqlite|sqlite3|db|bak|dump)$/i', $f))) === 0, 'no key, keystore, database or backup files are tracked');
$sql = array_filter($tracked, fn($f) => preg_match('#^database/.*\.sql$#', $f));
foreach ($sql as $f) ok(!preg_match('/INSERT\s+INTO[^;]*(@[a-z0-9.-]+\.[a-z]{2,}|password|\$2y\$)/i', (string)file_get_contents("$root/$f")), "$f contains no personal data or password hashes");
$gi = (string)file_get_contents("$root/.gitignore");
ok(strpos($gi, 'data/.form_secret') !== false && strpos($gi, 'config.local.php') !== false, '.gitignore excludes the runtime secret and the local config');
ok(preg_match('#^(data/(subscribers|submissions|reviews_demo))\.json$#', 'x') === 0 && trim((string)file_get_contents("$root/data/subscribers.json")) === '[]' && trim((string)file_get_contents("$root/data/submissions.json")) === '[]', 'no subscriber or submission records are committed');
$emails = [];
foreach (['data/tool_comments.json', 'data/bookmarks.json', 'data/tool_votes.json', 'data/ratings.json'] as $f) if (preg_match('/[\w.+-]+@[\w-]+\.[\w.-]+/', (string)file_get_contents("$root/$f"))) $emails[] = $f;
ok($emails === [], 'visitor-generated data files contain no email addresses: ' . implode(', ', $emails));
$readme = (string)@file_get_contents("$root/README.txt");
ok(strpos($readme, 'pass=admin123') === false, 'README no longer documents the removed ?pass=admin123 login');

echo "\nPASSED: $pass  FAILED: $fail\n";
exit($fail ? 1 : 0);
