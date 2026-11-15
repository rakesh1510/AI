<?php
// JSON endpoint: search suggestions (?q=) and server-rendered tool cards for saved tools (?ids=a,b). No writes, no cookies.
require_once __DIR__ . '/helpers.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
$tools = load_tools()['tools'];

if (isset($_GET['ids'])) {
  $ids = array_slice(array_filter(array_map('trim', explode(',', (string)$_GET['ids'])), fn($i) => preg_match('/^[a-z0-9][a-z0-9._-]{0,80}$/i', $i)), 0, 50);
  $byId = tools_by_id();
  $html = '';
  foreach ($ids as $id) { if (isset($byId[$id])) $html .= render_tool_card($byId[$id], ['placement' => 'favorites', 'heading' => 2]); }
  header('Cache-Control: private, no-store');
  echo json_encode(['cards' => $html], JSON_UNESCAPED_SLASHES);
  exit;
}

$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
if (mb_strlen($q) < 2) { echo json_encode(['q' => $q, 'items' => []]); exit; }
header('Cache-Control: public, max-age=60');
echo json_encode(search_suggest_payload($tools, $q), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
