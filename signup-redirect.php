<?php
require_once __DIR__ . '/helpers.php';

$id = trim($_GET['id'] ?? '');
if ($id === '') { http_response_code(400); exit('Missing id'); }

$data = load_tools();
$tools = $data['tools'] ?? [];

$tool = null;
foreach ($tools as $t) {
  if (($t['id'] ?? '') === $id) { $tool = $t; break; }
}
if (!$tool) { http_response_code(404); exit('Not found'); }

$url = tool_signup_url($tool);
if ($url === '#') { http_response_code(404); exit('No signup'); }

// optional: track signup click here

header('Location: ' . $url, true, 302);
exit;