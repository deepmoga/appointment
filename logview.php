<?php
// Temporary log viewer — delete after use
if (($_GET['k'] ?? '') !== 'bkwa2026') { http_response_code(403); exit('Forbidden'); }

// DB check mode
if (isset($_GET['db'])) {
    header('Content-Type: text/plain');
    require_once __DIR__ . '/includes/config.php';
    require_once __DIR__ . '/includes/functions.php';
    echo "=== businesses ===\n";
    $rows = db()->query("SELECT id, name, email FROM businesses")->fetchAll();
    foreach ($rows as $r) echo "  biz#{$r['id']}: {$r['name']} ({$r['email']})\n";
    echo "\n=== whatsapp_configs ===\n";
    $rows = db()->query("SELECT business_id, phone_number_id, display_name, whatsapp_number, is_connected FROM whatsapp_configs")->fetchAll();
    foreach ($rows as $r) {
        echo "  biz#{$r['business_id']}: pid={$r['phone_number_id']} | {$r['display_name']} | {$r['whatsapp_number']} | connected={$r['is_connected']}\n";
    }
    exit;
}

// Debug mode: check PHP errors in webhook includes
if (isset($_GET['debug'])) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
    header('Content-Type: text/plain');
    echo "=== PHP Error Check ===\n\n";
    $files = [
        'includes/config.php',
        'includes/functions.php',
        'includes/wallet.php',
        'includes/whatsapp.php',
    ];
    foreach ($files as $f) {
        $path = __DIR__ . '/' . $f;
        echo "[$f] ";
        if (!file_exists($path)) { echo "MISSING\n"; continue; }
        echo "EXISTS (" . filesize($path) . " bytes)\n";
    }
    echo "\n=== Trying to include config.php ===\n";
    try { require_once __DIR__ . '/includes/config.php'; echo "config.php: OK\n"; } catch (Throwable $e) { echo "config.php ERROR: " . $e->getMessage() . "\n"; }
    echo "\n=== Trying to include functions.php ===\n";
    try { require_once __DIR__ . '/includes/functions.php'; echo "functions.php: OK\n"; } catch (Throwable $e) { echo "functions.php ERROR: " . $e->getMessage() . "\n"; }
    echo "\n=== Trying to include wallet.php ===\n";
    try { require_once __DIR__ . '/includes/wallet.php'; echo "wallet.php: OK\n"; } catch (Throwable $e) { echo "wallet.php ERROR: " . $e->getMessage() . "\n"; }
    echo "\n=== Trying to include whatsapp.php ===\n";
    try { require_once __DIR__ . '/includes/whatsapp.php'; echo "whatsapp.php: OK\n"; } catch (Throwable $e) { echo "whatsapp.php ERROR: " . $e->getMessage() . "\n"; }
    echo "\nDone.\n";
    exit;
}

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
