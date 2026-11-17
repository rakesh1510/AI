<?php
// Placeholder that used to answer {"ok":true} without storing anything, which would mislead API clients. Not implemented.
http_response_code(501);
header('Content-Type: application/json');
echo json_encode(['ok' => false, 'message' => 'Not implemented']);
