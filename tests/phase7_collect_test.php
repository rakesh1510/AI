<?php
/**
 * Phase 7 shortest-path test: public-source captures -> collector (replay, no network) -> importer -> proposed leads (private) -> human adoption (editor)
 * -> independent verification (two different verifiers) -> verified data in the public API and the AI Universe pages.
 * All data here is SYNTHETIC (invented organisation 'Synth Labs'); the captures are generated in a temp dir, nothing is fetched. Scratch DB p7ct_<pid> only.
 * Usage: php tests/phase7_collect_test.php   (env as the other Phase 7 tests: AIHUB_COMPAT_ROOT_USER/PASS, AIHUB_DB_HOST)
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
ob_start(); require_once $root . '/uni_core.php'; ob_end_clean();
$dsnBase = getenv('P7_DSN_BASE') ?: 'mysql:host=' . (getenv('AIHUB_DB_HOST') ?: '127.0.0.1') . ';port=3306';
$user = getenv('P7_USER') ?: (getenv('AIHUB_COMPAT_ROOT_USER') ?: 'aihub_test');
$pw = getenv('P7_PASS') !== false ? getenv('P7_PASS') : (getenv('AIHUB_COMPAT_ROOT_PASS') !== false ? getenv('AIHUB_COMPAT_ROOT_PASS') : 'test_only_pw');
$dbHost = getenv('AIHUB_DB_HOST') ?: '127.0.0.1'; $db = 'p7ct_' . getmypid();
$opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$admin = new PDO($dsnBase, $user, $pw, $opt); $admin->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo = new PDO($dsnBase . ";dbname=$db", $user, $pw, $opt);
$pass = 0; $fail = 0; $proc = null; $tmp = sys_get_temp_dir() . '/p7ct_' . getmypid(); @mkdir("$tmp/out/captures", 0775, true);
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) $pass++; else { $fail++; fwrite(STDERR, "  FAIL: $m\n"); } }
function section(string $t): void { fwrite(STDERR, "== $t\n"); }
register_shutdown_function(function () use ($admin, $db, &$proc, $tmp) { if (getenv("P7CT_KEEP")) { fwrite(STDERR, "kept $tmp\n"); return; } if (is_resource($proc)) { @proc_terminate($proc); @proc_close($proc); } try { $admin->exec("DROP DATABASE IF EXISTS `$db`"); } catch (Throwable $e) {} shell_exec('rm -rf ' . escapeshellarg($tmp)); });
function sqlStatements(string $file): array { $d = ';'; $b = ''; $o = []; foreach (preg_split('/\R/', (string)file_get_contents($file)) as $l) { $t = trim($l); if ($b === '' && ($t === '' || str_starts_with($t, '--'))) continue; if (preg_match('/^DELIMITER\s+(\S+)$/i', $t, $m)) { $d = $m[1]; continue; } $b .= $l . "\n"; if (str_ends_with(rtrim($b), $d)) { $o[] = substr(rtrim($b), 0, -strlen($d)); $b = ''; } } return $o; }
foreach (['phase6_crm.sql', 'phase7_universe.sql', 'phase7_triggers.sql'] as $f) foreach (sqlStatements("$root/database/$f") as $s) $pdo->exec($s);

// ---- synthetic captures (same shape the collector stores) ----
$cap = function (string $url, string $body) use ($tmp) { file_put_contents("$tmp/out/captures/" . sha1($url) . '.json', json_encode(['url' => $url, 'fetched_at' => '2026-01-02 03:04:05', 'http' => 200, 'sha256' => hash('sha256', $body), 'body' => $body], JSON_UNESCAPED_SLASHES)); };
$W = 'https://www.wikidata.org'; $HF = 'https://huggingface.co';
$cap("$W/w/api.php?action=wbsearchentities&search=" . rawurlencode('Synth Labs') . '&language=en&type=item&limit=7&format=json', '{"search":[{"id":"Q990001","label":"Synth Labs","description":"synthetic artificial intelligence company"}]}');
$cap("$W/wiki/Special:EntityData/Q990001.json", '{"entities":{"Q990001":{"descriptions":{"en":{"language":"en","value":"synthetic artificial intelligence company"}},"claims":{"P856":[{"mainsnak":{"datavalue":{"value":"https://synth.example"}}}]}}}}');
$cap("$W/w/api.php?action=wbsearchentities&search=" . rawurlencode('Escaped Org') . '&language=en&type=item&limit=7&format=json', '{"search":[{"id":"Q990002","label":"Escaped Org","description":"synthetic company"}]}');
$cap("$W/wiki/Special:EntityData/Q990002.json", '{"entities":{"Q990002":{"descriptions":{"en":{"language":"en","value":"Caf' . chr(92) . 'u00e9 AI company"}}}}}');   // raw capture holds the JSON escape; the decoded text is NOT a substring of it: must be dropped
$cap("$HF/api/models?author=synthlabs&sort=downloads&direction=-1&limit=2", '[{"id":"synthlabs/alpha"},{"id":"synthlabs/alpha-ft"}]');
$cap("$HF/api/models/synthlabs/alpha", '{"id":"synthlabs/alpha","author":"synthlabs","createdAt":"2024-05-06T07:08:09.000Z","tags":["license:mit"]}');
$cap("$HF/api/models/synthlabs/alpha-ft", '{"id":"synthlabs/alpha-ft","author":"synthlabs","createdAt":"2024-09-10T00:00:00.000Z","tags":["base_model:finetune:synthlabs/alpha","base_model:quantized:synthlabs/alpha"]}');
file_put_contents("$tmp/seed.json", json_encode([['name' => 'Synth Labs', 'hf' => 'synthlabs'], ['name' => 'Escaped Org', 'hf' => 'escapedorg'], ['name' => 'Unknown Org', 'hf' => 'nobody']]));

section('collector (replay): only quote-backed records, nothing invented');
exec('php ' . escapeshellarg("$root/scripts/uni_collect_public.php") . " --seed=$tmp/seed.json --out-dir=$tmp/out --max-models=2 --replay 2>&1", $o, $rc);
$rep = json_decode(implode('', $o), true); $imp = json_decode((string)@file_get_contents("$tmp/out/import.json"), true);
ok($rc === 0 && is_array($rep) && $rep['companies'] === 1 && $rep['models'] === 2 && $rep['events'] === 2 && $rep['edges'] === 3, 'one company, two models, two events and three edges (2 developed_by + 1 derived_from): ' . implode('', $o));
$skips = json_encode($rep['skipped'] ?? []); ok(str_contains($skips, 'Escaped Org') && str_contains($skips, 'quote_not_in_capture'), 'a claim whose quote is not verbatim in the capture is dropped and reported');
ok(str_contains($skips, 'Unknown Org'), 'an organisation without a capture is skipped and reported, never guessed');
$allQuotesFound = true; foreach ($imp['sources'] as $s) { $c = json_decode((string)@file_get_contents("$tmp/out/captures/" . sha1($s['url']) . '.json'), true); if (!$c || strpos($c['body'], $s['quote']) === false) $allQuotesFound = false; }
ok($allQuotesFound && count($imp['sources']) >= 5, 'every source quote is a verbatim substring of its stored capture');
ok(!str_contains(json_encode($imp), '"state"') && !str_contains(json_encode($imp), 'verified'), 'the import file carries no state or verification fields');
$types = array_count_values(array_column($imp['edges'], 'edge_type')); ok(($types['derived_from'] ?? 0) === 1 && !isset($types['built_on']), 'only the documented finetune tag becomes derived_from; the quantized tag is not turned into ancestry');
ok(is_file("$tmp/out/review_batch.csv") && count(file("$tmp/out/review_batch.csv")) > 5, 'a review batch with quote and check instructions is produced');

// ---- users, importer ----
$H = password_hash('test-only-password', PASSWORD_DEFAULT);
$mk = function (string $n, string $r) use ($pdo, $H): int { [$o, $id] = crm_user_create($pdo, $n, ucfirst($n), $r, $H); if (!$o) throw new RuntimeException("user $n: " . json_encode($id)); return (int)$id; };
$ed = $mk('ctedit', 'editor'); $v1 = $mk('ctver1', 'verifier'); $v2 = $mk('ctver2', 'verifier');
$env = ['AIHUB_DB_HOST' => $dbHost, 'AIHUB_DB_NAME' => $db, 'AIHUB_DB_USER' => $user, 'AIHUB_DB_PASS' => $pw, 'AIHUB_FORM_SECRET' => 'collect-test-secret-not-for-production'];
$run = function (string $script, string $args) use ($root, $env): array { $e = ''; foreach ($env as $k => $v) $e .= $k . '=' . escapeshellarg($v) . ' '; exec("cd " . escapeshellarg($root) . " && $e php " . escapeshellarg("$root/$script") . " $args 2>&1", $o, $rc); return [$rc, implode("\n", $o)]; };
section('importer: proposed leads only, private');
[$rc, $out] = $run('scripts/uni_import.php', "--file=$tmp/out/import.json --user=ctedit --json"); ok($rc === 0, 'importer dry run is clean: ' . substr($out, 0, 300));
[$rc, $out] = $run('scripts/uni_import.php', "--file=$tmp/out/import.json --user=ctedit --apply --json"); ok($rc === 0, 'importer apply succeeds: ' . substr($out, 0, 300));
$cnt = fn(string $sql) => (int)$pdo->query($sql)->fetchColumn();
ok($cnt("SELECT COUNT(*) FROM uni_entity WHERE origin = 'import' AND state = 'proposed'") === 3 && $cnt("SELECT COUNT(*) FROM uni_entity WHERE state <> 'proposed'") === 0, 'three imported entities, all proposed');
ok(uni_public_entities($pdo)['total'] === 0 && uni_public_events($pdo)['total'] === 0 && uni_public_edges($pdo)['total'] === 0, 'unverified leads are not visible through any public read');
[$rc, $out] = $run('scripts/uni_import.php', "--file=$tmp/out/import.json --user=ctedit --apply --json"); ok($rc === 0 && $cnt('SELECT COUNT(*) FROM uni_entity') === 3, 're-running the import creates nothing new');

section('human adoption by the editor, then independent verification by different people');
[$rc, $out] = $run('scripts/uni_adopt.php', '--user=ctver1'); ok($rc === 2, 'a verifier cannot run adoption (editor role required)');
[$rc, $out] = $run('scripts/uni_adopt.php', '--user=ctedit'); ok($rc === 0 && str_contains($out, '"would_adopt":8') && $cnt("SELECT COUNT(*) FROM uni_entity WHERE origin = 'human'") === 0, 'adoption dry run lists 8 leads and writes nothing: ' . $out);
[$rc, $out] = $run('scripts/uni_adopt.php', '--user=ctedit --apply'); ok($rc === 0 && str_contains($out, '"adopted":8'), 'adoption creates human-origin versions awaiting review: ' . $out);
$pend = fn(string $t) => $pdo->query("SELECT id FROM $t WHERE origin = 'human' AND state = 'in_review' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$selfRej = uni_review($pdo, $ed, 'entity', (int)$pend('uni_entity')[0], 'accept'); ok($selfRej[0] === false, 'the editor who adopted cannot verify (not a verifier / maker-checker)');
$verify = function (string $type, int $id) use ($pdo, $v1, $v2): string { foreach ([$v1, $v2] as $v) { $r = uni_review($pdo, $v, $type, $id, 'accept'); if (!$r[0]) return $r[1]; if (($r[1]['state'] ?? '') === 'verified') return 'verified'; } return 'stuck'; };
$res = []; foreach (['entity' => 'uni_entity', 'event' => 'uni_event', 'edge' => 'uni_edge'] as $type => $t) foreach ($pend($t) as $id) $res[] = $verify($type, (int)$id);
ok(count($res) === 8 && count(array_unique($res)) === 1 && $res[0] === 'verified', 'entities, then events and edges, are verified by two different verifiers: ' . json_encode($res));

section('verified data is public: API and pages');
$port = 8330; for (; $port < 8350; $port++) { $s = @stream_socket_server("tcp://127.0.0.1:$port"); if ($s) { fclose($s); break; } }
$logd = "$tmp/log"; @mkdir($logd, 0775, true);
$proc = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root, "$root/tests/router.php"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp, $root, $env + ['AIHUB_SECURITY_LOG' => "$logd/sec.log", 'AIHUB_RATE_DIR' => "$logd/rate", 'AIHUB_UNIVERSE_RATE_LIMIT' => '10000'] + getenv());
for ($i = 0; $i < 60; $i++) { usleep(100000); if (@fsockopen('127.0.0.1', $port)) break; }
$get = function (string $p) use ($port): array { $raw = @file_get_contents("http://127.0.0.1:$port$p", false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 30]])); $st = 0; foreach (($http_response_header ?? []) as $l) if (preg_match('#^HTTP/\S+ (\d{3})#', $l, $m)) $st = (int)$m[1]; return [$st, (string)$raw]; };
[$st, $b] = $get('/universe-api.php?r=entities'); $j = json_decode($b, true)['data'] ?? []; ok($st === 200 && ($j['total'] ?? 0) === 3, 'public API lists the three verified entities ' . substr($b, 0, 220));
[$st, $b] = $get('/universe-api.php?r=graph&slug=synth-labs'); $j = json_decode($b, true)['data'] ?? []; ok($st === 200 && count($j['edges'] ?? []) >= 2 && str_contains($b, 'developed_by') && !str_contains($b, 'proposed_by') && !str_contains($b, 'origin'), 'company graph neighbourhood shows its models with sources and no identities or origin ' . substr($b, 0, 220));
[$st, $b] = $get('/universe-api.php?r=events'); $j = json_decode($b, true)['data'] ?? []; ok($st === 200 && ($j['total'] ?? 0) === 2 && str_contains($b, '2024-09-10'), 'public events carry the Hub creation dates ' . substr($b, 0, 220));
[$st, $b] = $get('/universe-api.php?r=lineage&slug=synthlabs-alpha-ft'); ok($st === 200 && (str_contains($b, 'derived_from') || str_contains($b, 'technical_ancestry')), 'the documented fine-tune relationship is available as lineage/ancestry data (HTTP ' . $st . ')');
[$st, $b] = $get('/universe.php'); ok($st === 200 && str_contains($b, '3 verified entities') && str_contains($b, 'Recent verified events') && str_contains($b, 'Most connected verified entities') && str_contains($b, 'Synth Labs') && !str_contains($b, 'No verified data yet</h2>'), 'hub shows verified counts, recent events, most connected entities and a search');
[$st, $b] = $get('/universe-lineage.php'); ok($st === 200 && str_contains($b, 'Documented ancestry and succession') && str_contains($b, 'synthlabs/alpha') && !str_contains($b, 'No verified releases yet'), 'lineage index lists documented ancestry even without formal releases');
[$st, $b] = $get('/universe-graph.php?q=alpha&type=model'); ok($st === 200 && str_contains($b, 'synthlabs/alpha') && !str_contains($b, 'Synth Labs</a>'), 'graph page filter by name and type works');
[$st, $b] = $get('/universe-graph.php?q=zzzznomatch'); ok($st === 200 && str_contains($b, 'No verified entities match') && !str_contains($b, 'No verified data yet</h2>'), 'a filter without a match says so and does not claim the ledger is empty');
[$st, $b] = $get('/universe-heatmap.php'); ok($st === 200 && str_contains($b, '2024'), 'heatmap renders with verified events');
[$st, $b] = $get('/universe-arena.php?e=synthlabs-alpha&e=synthlabs-alpha-ft'); ok($st === 200 && str_contains($b, 'Unavailable'), 'arena renders for two verified entities and marks missing facts as unavailable (HTTP ' . $st . ')');
echo "\nPASSED: $pass  FAILED: $fail\n"; exit($fail ? 1 : 0);
