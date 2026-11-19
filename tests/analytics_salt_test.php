<?php
// Database-free: a missing or weak salt must never produce a visitor hash; tracking stays off by default.
require_once __DIR__ . '/../helpers.php';
$pass = 0; $fail = 0; function ok($c, $m) { global $pass, $fail; if ($c) $pass++; else { $fail++; echo "  FAIL: $m\n"; } }
putenv('AIHUB_ANALYTICS'); putenv('AIHUB_ANALYTICS_SALT');
ok(!analytics_enabled(), 'tracking is off by default');
$args = ['203.0.113.9', 'Mozilla/5.0', '2026-11-15'];
ok(analytics_visitor_hash(...$args) === '', 'no salt: no hash');
putenv('AIHUB_ANALYTICS_SALT='); ok(analytics_visitor_hash(...$args) === '', 'empty salt: no hash');
putenv('AIHUB_ANALYTICS_SALT=aihubpedia-default-salt-set-AIHUB_ANALYTICS_SALT-x'); putenv('AIHUB_ANALYTICS_SALT=short'); ok(analytics_visitor_hash(...$args) === '', 'short salt: no hash');
putenv('AIHUB_ANALYTICS_SALT=' . str_repeat('a', 31)); ok(analytics_visitor_hash(...$args) === '', '31-character salt: no hash');
putenv('AIHUB_ANALYTICS_SALT=' . str_repeat('a', 32)); $h = analytics_visitor_hash(...$args); ok(strlen($h) === 16, '32-character salt: hash produced');
putenv('AIHUB_ANALYTICS_SALT=' . str_repeat('b', 32)); ok(analytics_visitor_hash(...$args) !== $h, 'hash depends on the salt');
foreach (['aihubpedia-default-salt-set-AIHUB_ANALYTICS_SALT'] as $d) { putenv('AIHUB_ANALYTICS_SALT=' . $d); ok(analytics_visitor_hash(...$args) === '', 'the old public default string is rejected'); }
putenv('AIHUB_ANALYTICS_SALT'); $row = analytics_build_row(['HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/120', 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_HOST' => 'aihubpedia.com'], [], 200, 1700000000, '/tool.php');
ok($row['visitor_hash'] === '', 'a built page-view row carries no visitor hash without a salt');
ok(strpos((string)file_get_contents(__DIR__ . '/../analytics.php'), "'aihubpedia-default-salt") === false, 'no default salt literal remains in analytics.php');
echo "\nPASSED: $pass  FAILED: $fail\n"; exit($fail ? 1 : 0);
