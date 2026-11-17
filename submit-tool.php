<?php
// Retired legacy form (posted to an unauthenticated catalogue writer). Use /submit.php.
header('X-Robots-Tag: noindex');
header('Location: /submit.php', true, 301);
exit;
