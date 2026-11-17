<?php
/**
 * Creates a DRAFT weekly roundup from real data (verified change-log entries and recently reviewed profiles). It prints JSON to stdout;
 * review it, fill in editor and summary, and only then add it to data/newsletter_issues.json with status "published" and a published_on date.
 * Usage: php scripts/newsletter_roundup.php [--week=2026-W47] [--days=7]
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../helpers.php';
$week = date('o-\WW'); $days = 7;
foreach ($argv as $a) { if (strpos($a, '--week=') === 0) $week = substr($a, 7); if (strpos($a, '--days=') === 0) $days = max(1, (int)substr($a, 7)); }
$d = newsletter_roundup_draft(tools_by_id(), $week, $days);
fwrite(STDERR, count($d['items']) . " item(s) from the last $days days. This is a DRAFT: a person must read every source before publishing.\n");
echo json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
