<?php
if (($_GET['k'] ?? '') !== 'bkwa2026') { http_response_code(403); exit('Forbidden'); }
header('Content-Type: text/plain');
$output = [];
exec('cd ' . escapeshellarg(dirname(__DIR__)) . ' && git pull origin main 2>&1', $output, $code);
echo "Exit code: $code\n\n";
echo implode("\n", $output) . "\n";
