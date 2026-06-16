<?php
/**
 * Razorpay payment integration.
 *
 * Platform Razorpay account → used to collect wallet recharges from clients.
 * Client own Razorpay account → used by client to collect from their customers (future).
 */

function getPlatformRazorpayConfig(): array {
    $s = getPlatformSettings();
    return [
        'key_id'     => $s['razorpay_key_id']     ?? '',
        'key_secret' => $s['razorpay_key_secret'] ?? '',
    ];
}

function getClientRazorpayConfig(int $businessId): array {
    $stmt = db()->prepare("SELECT own_razorpay_key_id, own_razorpay_key_secret FROM businesses WHERE id = ?");
    $stmt->execute([$businessId]);
    $row = $stmt->fetch();
    return [
        'key_id'     => $row['own_razorpay_key_id']     ?? '',
        'key_secret' => $row['own_razorpay_key_secret'] ?? '',
    ];
}

function isPlatformRazorpayConfigured(): bool {
    $c = getPlatformRazorpayConfig();
    return !empty($c['key_id']) && !empty($c['key_secret']);
}

/**
 * Create a Razorpay order (server-side).
 * Returns the order array on success, null on failure.
 */
function createRazorpayOrder(float $amountInr, string $receipt, array $rzConfig): ?array {
    if (empty($rzConfig['key_id']) || empty($rzConfig['key_secret'])) return null;

    $ch = curl_init('https://api.razorpay.com/v1/orders');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'amount'   => (int)round($amountInr * 100), // paise
            'currency' => 'INR',
            'receipt'  => substr($receipt, 0, 40),
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_USERPWD        => $rzConfig['key_id'] . ':' . $rzConfig['key_secret'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$response) return null;
    $data = json_decode($response, true);
    return ($httpCode === 200 && isset($data['id'])) ? $data : null;
}

/**
 * Verify Razorpay payment signature after checkout.
 */
function verifyRazorpaySignature(string $orderId, string $paymentId, string $signature, array $rzConfig): bool {
    if (empty($rzConfig['key_secret'])) return false;
    $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, $rzConfig['key_secret']);
    return hash_equals($expected, $signature);
}

/**
 * Fetch payment details from Razorpay to double-check amount/status.
 */
function getRazorpayPaymentDetails(string $paymentId, array $rzConfig): ?array {
    if (empty($rzConfig['key_id'])) return null;
    $ch = curl_init("https://api.razorpay.com/v1/payments/{$paymentId}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $rzConfig['key_id'] . ':' . $rzConfig['key_secret'],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    if (!$response) return null;
    $data = json_decode($response, true);
    return isset($data['id']) ? $data : null;
}
