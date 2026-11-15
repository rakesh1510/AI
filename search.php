<?php
// Legacy search URL (referenced by the old WebSite SearchAction): permanently redirect to homepage search.
$q = trim((string)($_GET['q'] ?? ''));
header('Location: /' . ($q !== '' ? '?q=' . rawurlencode($q) : ''), true, 301);
exit;
