<?php
// Temporary log viewer — delete after use
if (($_GET['k'] ?? '') !== 'bkwa2026') { http_response_code(403); exit('Forbidden'); }
$log = __DIR__ . '/logs/webhook.log';
header('Content-Type: text/plain; charset=utf-8');
if (!file_exists($log)) {
    // Try to create the logs dir and write a test entry
    @mkdir(dirname($log), 0755, true);
    file_put_contents($log, date('Y-m-d H:i:s') . " [TEST] Log file created by logview.php\n", FILE_APPEND | LOCK_EX);
    echo "Log was just initialized. Send a WhatsApp message now and refresh this page.\n\n";
    echo "logs/ dir exists: " . (is_dir(dirname($log)) ? "YES\n" : "NO — mkdir failed\n");
    echo "log writable: " . (is_writable(dirname($log)) ? "YES\n" : "NO\n");
    exit;
}
$lines = file($log);
echo "=== Last 100 lines of webhook.log (" . count($lines) . " total) ===\n\n";
echo implode('', array_slice($lines, -100));
