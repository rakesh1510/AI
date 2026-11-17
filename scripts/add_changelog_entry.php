<?php
/**
 * Adds an editor-verified entry to data/tool_changelog.json after validating it (same rules the public page applies).
 * Usage: php scripts/add_changelog_entry.php --tool=otter-ai --kind=pricing --date=2026-11-20 --summary="..." --evidence=https://vendor/page --by="Editor Name" [--verified-on=2026-11-21]
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../helpers.php';
$o = []; foreach ($argv as $a) if (preg_match('/^--([a-z-]+)=(.*)$/s', $a, $m)) $o[$m[1]] = $m[2];
$e = ['tool' => $o['tool'] ?? '', 'kind' => $o['kind'] ?? '', 'date' => $o['date'] ?? '', 'summary' => $o['summary'] ?? '', 'evidence' => array_filter(explode(',', $o['evidence'] ?? '')), 'verified_by' => $o['by'] ?? '', 'verified_on' => $o['verified-on'] ?? date('Y-m-d')];
$errs = changelog_entry_errors($e, tools_by_id());
if ($errs) { fwrite(STDERR, "Not added:\n - " . implode("\n - ", $errs) . "\n"); exit(1); }
$path = __DIR__ . '/../data/tool_changelog.json';
$j = json_decode((string)file_get_contents($path), true) ?: ['entries' => []];
$j['entries'][] = $e;
file_put_contents($path, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", LOCK_EX);
echo "Added entry for {$e['tool']} ({$e['date']}).\n";
