<?php
/**
 * Razorpay payment verification.
 *
 * Handles two scenarios:
 * 1. AJAX POST (wallet recharge) — called from dashboard after Razorpay JS checkout
 * 2. GET callback / Webhook POST (doctor booking payment link) — called by Razorpay
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/../includes/payment.php';
require_once __DIR__ . '/../includes/whatsapp.php';

// ─── Scenario 2a: GET callback from Razorpay after patient pays via payment link ─
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['appt'])) {
    $appointmentId = (int)$_GET['appt'];
    $paymentLinkId = $_GET['razorpay_payment_link_id'] ?? '';
    $paymentId     = $_GET['razorpay_payment_id']      ?? '';
    $status        = $_GET['razorpay_payment_link_status'] ?? '';

    if ($appointmentId > 0 && $status === 'paid') {
        confirmBookingFromPayment($appointmentId, $paymentId);
    }

    // Redirect to a thank-you page or close
    header('Content-Type: text/html');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Payment</title></head><body style="font-family:sans-serif;text-align:center;padding:60px;">';
    if ($status === 'paid') {
        echo '<h2 style="color:#16a34a">✅ Payment Successful!</h2><p>Your appointment has been confirmed. You will receive a WhatsApp confirmation shortly.</p>';
    } else {
        echo '<h2 style="color:#dc2626">❌ Payment Incomplete</h2><p>Please complete your payment using the WhatsApp link or contact us.</p>';
    }
    echo '</body></html>';
    exit;
}

// ─── Scenario 2b: Webhook POST from Razorpay (payment_link.paid event) ───────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw     = file_get_contents('php://input');
    $payload = json_decode($raw, true);

    // Check if it's a payment link webhook
    $event = $payload['event'] ?? '';
    if (in_array($event, ['payment_link.paid', 'payment.captured'], true)) {
        $referenceId = $payload['payload']['payment_link']['entity']['reference_id']
                    ?? $payload['payload']['payment']['entity']['description']
                    ?? '';

        if (str_starts_with($referenceId, 'APPT_')) {
            $appointmentId = (int)substr($referenceId, 5);
            $paymentId     = $payload['payload']['payment']['entity']['id'] ?? '';
            if ($appointmentId > 0) {
                confirmBookingFromPayment($appointmentId, $paymentId);
                http_response_code(200);
                echo json_encode(['status' => 'ok']);
                exit;
            }
        }

        // Otherwise fall through to wallet recharge handler below
    }

    // ─── Scenario 1: AJAX POST — wallet recharge ─────────────────────────────────
    header('Content-Type: application/json');

    if (session_status() === PHP_SESSION_NONE) session_start();

    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $body      = json_decode($raw, true);
    $orderId   = trim($body['razorpay_order_id']   ?? '');
    $paymentId = trim($body['razorpay_payment_id'] ?? '');
    $signature = trim($body['razorpay_signature']  ?? '');

    if ($orderId === '' || $paymentId === '' || $signature === '') {
        echo json_encode(['error' => 'Missing payment data']);
        exit;
    }

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

    $stmt = db()->prepare("SELECT id FROM wallet_transactions WHERE reference_id = ? LIMIT 1");
    $stmt->execute([$paymentId]);
    if ($stmt->fetch()) {
        echo json_encode(['error' => 'This payment was already processed.']);
        exit;
    }

    $businessId  = (int)$pending['business_id'];
    $credits     = (float)$pending['credits'];
    $amount      = (float)$pending['amount'];

    $stmt = db()->prepare("SELECT name FROM recharge_packages WHERE id = ?");
    $stmt->execute([$pending['package_id']]);
    $packageName = (string)$stmt->fetchColumn();

    creditWallet($businessId, $credits, "Wallet recharge — {$packageName} (₹{$amount})", $paymentId);
    unset($_SESSION['pending_recharge']);

    $newBalance = getWalletBalance($businessId);
    echo json_encode([
        'success'       => true,
        'credits_added' => $credits,
        'balance'       => $newBalance,
        'message'       => '₹' . number_format($credits, 2) . ' credited to your wallet!',
    ]);
    exit;
}

http_response_code(405);
echo 'Method Not Allowed';

// ─── Helper: confirm booking + send WhatsApp message ─────────────────────────────
function confirmBookingFromPayment(int $appointmentId, string $paymentId): void {
    $stmt = db()->prepare("
        SELECT a.*, b.id AS biz_id, b.name AS biz_name,
               c.phone AS cust_phone, c.language AS cust_lang, c.name AS cust_name,
               st.name AS doctor_name
        FROM appointments a
        LEFT JOIN businesses b ON b.id = a.business_id
        LEFT JOIN customers  c ON c.id = a.customer_id
        LEFT JOIN staff      st ON st.id = a.staff_id
        WHERE a.id = ?
    ");
    $stmt->execute([$appointmentId]);
    $appt = $stmt->fetch();
    if (!$appt) return;

    // Idempotency: skip if already confirmed
    if (in_array($appt['payment_status'], ['paid', 'completed'], true)) return;

    db()->prepare("UPDATE appointments SET status='confirmed', payment_status='paid' WHERE id=?")
        ->execute([$appointmentId]);

    // Get session data to build confirmation message
    $businessId = (int)$appt['biz_id'];
    $phone      = $appt['cust_phone'] ?? '';
    if (!$phone) return;

    $session = getWhatsappSession($businessId, $phone);
    $data    = $session['session_data'] ?? [];
    $lang    = $data['lang'] ?? ($appt['cust_lang'] ?? 'en');
    if (!isset(WA_LANGS[$lang])) $lang = 'en';

    $timeLabel = date('g:i A', strtotime($appt['appointment_time'] ?? '09:00:00'));
    $vars = [
        'doctor_name'        => $data['doctor_name'] ?? ($appt['doctor_name'] ?? ''),
        'date'               => formatDate($appt['appointment_date']),
        'time'               => $data['time'] ?? $timeLabel,
        'patient_name'       => $data['patient_name'] ?? ($appt['cust_name'] ?? ''),
        'place'              => $data['place'] ?? '—',
        'appointment_number' => formatAppointmentNumber($appointmentId),
    ];

    sendWhatsappMessage($businessId, $phone, wt($lang, 'booking_confirmed_doc', $vars));
    saveWhatsappSession($businessId, $phone, 'idle', ['lang' => $lang], $appointmentId);
}
