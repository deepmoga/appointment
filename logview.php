<?php
// Temporary log viewer — delete after use
if (($_GET['k'] ?? '') !== 'bkwa2026') { http_response_code(403); exit('Forbidden'); }
$log = __DIR__ . '/logs/webhook.log';
header('Content-Type: text/plain; charset=utf-8');
if (!file_exists($log)) { echo "Log file not found: $log\n\nChecking if logs/ dir exists: ";
    echo is_dir(__DIR__.'/logs') ? "YES (dir exists but no log file yet)\n" : "NO (logs/ dir does not exist)\n";
    echo "\nwritable check: " . (is_writable(__DIR__) ? "public_html is writable" : "NOT writable") . "\n";
    exit;
}
$lines = file($log);
echo "=== Last 100 lines of webhook.log (" . count($lines) . " total) ===\n\n";
echo implode('', array_slice($lines, -100));
