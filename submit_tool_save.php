<?php
// Retired: this endpoint used to append anonymous, unvalidated entries straight to the live catalogue.
// Submissions now go through /submit.php (validated, rate limited, reviewed before anything is published).
header('X-Robots-Tag: noindex');
header('Location: /submit.php', true, 301);
exit;
