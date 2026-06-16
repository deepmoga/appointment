<?php
/**
 * POST /api/razorpay-order.php
 * Creates a Razorpay order for wallet recharge.
 * Called via AJAX from dashboard/wallet.php
 *
 * POST body (JSON): { package_id: int }
 * Response (JSON):  { order_id, amount, key_id } on success | { error } on failure
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/../includes/payment.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body      = json_decode(file_get_contents('php://input'), true);
$packageId = (int)($body['package_id'] ?? 0);
$csrf      = $body['csrf_token'] ?? '';

if (!verifyCsrf($csrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

if ($packageId <= 0) {
    echo json_encode(['error' => 'Invalid package']);
    exit;
}

$stmt = db()->prepare("SELECT * FROM recharge_packages WHERE id = ? AND is_active = 1");
$stmt->execute([$packageId]);
$package = $stmt->fetch();

if (!$package) {
    echo json_encode(['error' => 'Package not found']);
    exit;
}

if (!isPlatformRazorpayConfigured()) {
    echo json_encode(['error' => 'Payment gateway not configured. Please contact support.']);
    exit;
}

$rzConfig = getPlatformRazorpayConfig();
$businessId = (int)$_SESSION['business_id'];
$receipt = 'bwa_' . $businessId . '_' . time();

$order = createRazorpayOrder((float)$package['amount'], $receipt, $rzConfig);

if (!$order) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not create payment order. Please try again.']);
    exit;
}

// Store pending recharge in session so verify endpoint can confirm
$_SESSION['pending_recharge'] = [
    'order_id'   => $order['id'],
    'package_id' => $packageId,
    'amount'     => (float)$package['amount'],
    'credits'    => (float)$package['credits'] + (float)$package['bonus'],
    'business_id'=> $businessId,
];

echo json_encode([
    'order_id' => $order['id'],
    'amount'   => (int)round((float)$package['amount'] * 100), // paise for Razorpay JS
    'currency' => 'INR',
    'key_id'   => $rzConfig['key_id'],
    'name'     => APP_NAME . ' Wallet Recharge',
    'description' => $package['name'] . ' — ₹' . number_format((float)$package['credits'] + (float)$package['bonus'], 2) . ' credits',
]);
