<?php
/**
 * POST /api/razorpay-verify.php
 * Verifies Razorpay payment signature and credits wallet on success.
 * Called via AJAX after Razorpay JS checkout completes.
 *
 * POST body (JSON): { razorpay_order_id, razorpay_payment_id, razorpay_signature }
 * Response (JSON):  { success, balance } | { error }
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
$orderId   = trim($body['razorpay_order_id']   ?? '');
$paymentId = trim($body['razorpay_payment_id'] ?? '');
$signature = trim($body['razorpay_signature']  ?? '');

if ($orderId === '' || $paymentId === '' || $signature === '') {
    echo json_encode(['error' => 'Missing payment data']);
    exit;
}

// Match against session-stored pending recharge
$pending = $_SESSION['pending_recharge'] ?? null;
if (!$pending || $pending['order_id'] !== $orderId || (int)$pending['business_id'] !== (int)$_SESSION['business_id']) {
    echo json_encode(['error' => 'Order mismatch. Please try again.']);
    exit;
}

$rzConfig = getPlatformRazorpayConfig();

if (!verifyRazorpaySignature($orderId, $paymentId, $signature, $rzConfig)) {
    http_response_code(400);
    echo json_encode(['error' => 'Payment signature verification failed.']);
    exit;
}

// Prevent double-credit: check if this payment_id was already processed
$stmt = db()->prepare("SELECT id FROM wallet_transactions WHERE reference_id = ? LIMIT 1");
$stmt->execute([$paymentId]);
if ($stmt->fetch()) {
    echo json_encode(['error' => 'This payment was already processed.']);
    exit;
}

$businessId = (int)$pending['business_id'];
$credits    = (float)$pending['credits'];
$amount     = (float)$pending['amount'];

$stmt = db()->prepare("SELECT name FROM recharge_packages WHERE id = ?");
$stmt->execute([$pending['package_id']]);
$packageName = (string)$stmt->fetchColumn();

creditWallet($businessId, $credits, "Wallet recharge — {$packageName} (₹{$amount})", $paymentId);

unset($_SESSION['pending_recharge']);

$newBalance = getWalletBalance($businessId);

echo json_encode([
    'success' => true,
    'credits_added' => $credits,
    'balance' => $newBalance,
    'message' => '₹' . number_format($credits, 2) . ' credited to your wallet!',
]);
