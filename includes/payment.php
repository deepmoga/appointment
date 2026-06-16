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
 * Create a Razorpay Payment Link (shareable URL sent via WhatsApp).
 * Returns short_url on success, null on failure.
 */
function createRazorpayPaymentLink(int $businessId, float $amountInr, string $description, string $customerPhone, string $customerName, int $appointmentId): ?string {
    $stmt = db()->prepare("SELECT payment_mode, own_razorpay_key_id, own_razorpay_key_secret FROM businesses WHERE id = ?");
    $stmt->execute([$businessId]);
    $biz = $stmt->fetch();

    if ($biz && ($biz['payment_mode'] ?? '') === 'own' && !empty($biz['own_razorpay_key_id'])) {
        $rzConfig = ['key_id' => $biz['own_razorpay_key_id'], 'key_secret' => $biz['own_razorpay_key_secret']];
    } else {
        $rzConfig = getPlatformRazorpayConfig();
    }

    if (empty($rzConfig['key_id']) || empty($rzConfig['key_secret'])) return null;

    $callbackUrl = (defined('APP_URL') ? APP_URL : '') . '/api/razorpay-verify.php?appt=' . $appointmentId;

    $payload = [
        'amount'          => (int)round($amountInr * 100),
        'currency'        => 'INR',
        'description'     => mb_substr($description, 0, 255),
        'customer'        => ['name' => $customerName, 'contact' => $customerPhone],
        'notify'          => ['sms' => false, 'email' => false],
        'reminder_enable' => false,
        'expire_by'       => time() + 86400,
        'reference_id'    => 'APPT_' . $appointmentId,
        'callback_url'    => $callbackUrl,
        'callback_method' => 'get',
    ];

    $ch = curl_init('https://api.razorpay.com/v1/payment_links');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_USERPWD        => $rzConfig['key_id'] . ':' . $rzConfig['key_secret'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$response) return null;
    $data = json_decode($response, true);
    return ($httpCode === 200 && !empty($data['short_url'])) ? $data['short_url'] : null;
}

/**
 * Fetch Razorpay Payment Link status.
 */
function getRazorpayPaymentLinkStatus(string $paymentLinkId, array $rzConfig): ?array {
    if (empty($rzConfig['key_id'])) return null;
    $ch = curl_init("https://api.razorpay.com/v1/payment_links/{$paymentLinkId}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $rzConfig['key_id'] . ':' . $rzConfig['key_secret'],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    if (!$response) return null;
    return json_decode($response, true) ?: null;
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
