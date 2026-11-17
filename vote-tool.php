<?php
// Legacy GET voting link. State-changing GET requests are CSRF-prone and prefetchable, so this no longer records anything:
// it just sends the visitor back to the tool page. Voting uses POST /rate.php or /vote-tool-ajax.php with a CSRF token.
require_once __DIR__ . '/helpers.php';
$toolId = trim((string)($_GET['id'] ?? ''));
sec_log('legacy_get_vote_ignored');
header('Location: ' . ($toolId !== '' ? '/tool.php?id=' . urlencode($toolId) . '#community' : '/index.php'), true, 303);
exit;
