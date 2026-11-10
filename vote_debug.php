<?php
require 'helpers.php';
header('Content-Type: text/plain');

echo "visitor_id(): " . visitor_id() . PHP_EOL;
echo "cookie: " . ($_COOKIE['aihub_visitor_id'] ?? 'NOT SET') . PHP_EOL;
echo "ip: " . client_ip_address() . PHP_EOL;