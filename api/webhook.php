<?php
/**
 * WhatsApp Cloud API webhook receiver.
 *
 * Each business configures their Meta App's webhook callback URL as:
 *   {APP_URL}/api/webhook.php?b={business_id}
 *
 * GET  — webhook verification handshake (hub.mode / hub.verify_token / hub.challenge)
 * POST — incoming message / status notifications
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/../includes/whatsapp.php';

$businessId = (int)($_GET['b'] ?? 0);

// ─── GET: Webhook verification ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode      = $_GET['hub_mode'] ?? '';
    $token     = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';

    if ($mode === 'subscribe') {
        // Try per-business verify token (legacy / ?b= style)
        $config = $businessId > 0 ? getWhatsappConfig($businessId) : null;
        if ($config && !empty($config['webhook_verify_token']) && hash_equals($config['webhook_verify_token'], $token)) {
            header('Content-Type: text/plain');
            echo $challenge;
            exit;
        }

        // Try platform-wide shared verify token
        $platform = getPlatformSettings();
        if (!empty($platform['wa_verify_token']) && hash_equals($platform['wa_verify_token'], $token)) {
            header('Content-Type: text/plain');
            echo $challenge;
            exit;
        }
    }

    http_response_code(403);
    echo 'Verification failed';
    exit;
}

// ─── POST: Incoming events ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw     = file_get_contents('php://input');
    $payload = json_decode($raw, true);

    if (is_array($payload)) {
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                // Resolve business by phone_number_id if not supplied via query string
                $resolvedBusinessId = $businessId;
                if ($resolvedBusinessId <= 0) {
                    $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
                    if ($phoneNumberId) {
                        $stmt = db()->prepare("SELECT business_id FROM whatsapp_configs WHERE phone_number_id = ?");
                        $stmt->execute([$phoneNumberId]);
                        $resolvedBusinessId = (int)$stmt->fetchColumn();
                    }
                }

                if ($resolvedBusinessId <= 0) continue;

                foreach ($value['messages'] ?? [] as $msg) {
                    $from        = $msg['from'] ?? '';
                    $waMessageId = $msg['id'] ?? null;
                    $type        = $msg['type'] ?? 'text';
                    $text        = '';
                    $replyId     = '';

                    switch ($type) {
                        case 'text':
                            $text = $msg['text']['body'] ?? '';
                            break;
                        case 'interactive':
                            $text    = $msg['interactive']['button_reply']['title']
                                ?? $msg['interactive']['list_reply']['title']
                                ?? '';
                            $replyId = $msg['interactive']['button_reply']['id']
                                ?? $msg['interactive']['list_reply']['id']
                                ?? '';
                            break;
                        case 'button':
                            $text = $msg['button']['text'] ?? '';
                            break;
                        default:
                            $text = '';
                    }

                    if ($from === '') continue;

                    // Find existing customer for nicer logging (optional)
                    $customerId = null;
                    $stmt = db()->prepare("SELECT id FROM customers WHERE business_id = ? AND phone = ? LIMIT 1");
                    $stmt->execute([$resolvedBusinessId, $from]);
                    if ($row = $stmt->fetch()) {
                        $customerId = (int)$row['id'];
                    }

                    logWhatsappMessage($resolvedBusinessId, $from, 'inbound', $type === 'text' ? 'text' : $type, $text ?: "[{$type} message]", $customerId, $waMessageId);

                    if ($text !== '' || $replyId !== '') {
                        try {
                            processIncomingMessage($resolvedBusinessId, $from, $text, $replyId);
                        } catch (Exception $e) {
                            // Swallow errors so Meta doesn't keep retrying the same payload
                        }
                    }
                }
            }
        }
    }

    http_response_code(200);
    echo 'OK';
    exit;
}

http_response_code(405);
echo 'Method Not Allowed';