<?php
// Read-only JSON endpoint: the current SERVER total of outbound clicks for up to 30 tools (?ids=a,b), used by the public "clicks" counter to refresh itself after a visitor returns from a tool's website.
// It never writes, never counts anything, sets no cookie, and is not recorded as a page view. The page only ever displays the numbers returned here (it never increments a number by itself).
require_once __DIR__ . '/helpers.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
if (!in_array(($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['GET', 'HEAD'], true)) { http_response_code(405); header('Allow: GET, HEAD'); exit; }
if (sec_rate_limited('clickcount', 120, 600)) { http_response_code(429); header('Retry-After: 60'); echo json_encode(['error' => 'rate_limited']); exit; }
$raw = isset($_GET['ids']) && is_string($_GET['ids']) ? $_GET['ids'] : (isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '');
$ids = array_slice(array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)), fn($i) => preg_match('/^[a-z0-9][a-z0-9._-]{0,80}$/i', $i) === 1))), 0, 30);
$byId = tools_by_id(); $counts = []; foreach ($ids as $id) if (isset($byId[$id])) $counts[$id] = click_count($id);
echo json_encode(['counts' => (object)$counts, 'source' => click_counts_source()], JSON_UNESCAPED_SLASHES);
