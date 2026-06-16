<?php
/**
 * WhatsApp Cloud API webhook receiver.
 * Webhook URL: {APP_URL}/api/webhook.php
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/../includes/whatsapp.php';

// ─── Logger ───────────────────────────────────────────────────────────────────

define('WH_LOG', dirname(__DIR__) . '/logs/webhook.log');

function whLog(string $level, string $msg, array $ctx = []): void {
    @mkdir(dirname(WH_LOG), 0755, true);
    $line = date('Y-m-d H:i:s') . " [$level] $msg";
    if ($ctx) $line .= ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    file_put_contents(WH_LOG, $line . "\n", FILE_APPEND | LOCK_EX);
}

$businessId = (int)($_GET['b'] ?? 0);

// ─── GET: Webhook verification ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode      = $_GET['hub_mode'] ?? '';
    $token     = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';

    whLog('INFO', 'Verification attempt', ['mode' => $mode, 'token_len' => strlen($token)]);

    if ($mode === 'subscribe') {
        $config = $businessId > 0 ? getWhatsappConfig($businessId) : null;
        if ($config && !empty($config['webhook_verify_token']) && hash_equals($config['webhook_verify_token'], $token)) {
            whLog('INFO', 'Verified via business token', ['biz' => $businessId]);
            header('Content-Type: text/plain');
            echo $challenge;
            exit;
        }

        $platform = getPlatformSettings();
        if (!empty($platform['wa_verify_token']) && hash_equals($platform['wa_verify_token'], $token)) {
            whLog('INFO', 'Verified via platform token');
            header('Content-Type: text/plain');
            echo $challenge;
            exit;
        }

        whLog('WARN', 'Verification FAILED — token mismatch');
    }

    http_response_code(403);
    echo 'Verification failed';
    exit;
}

// ─── POST: Incoming events ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw     = file_get_contents('php://input');
    $payload = json_decode($raw, true);

    if (!is_array($payload)) {
        whLog('WARN', 'Invalid JSON payload', ['raw_len' => strlen($raw)]);
        http_response_code(200);
        echo 'OK';
        exit;
    }

    whLog('INFO', 'Webhook POST received', ['entries' => count($payload['entry'] ?? [])]);

    foreach ($payload['entry'] ?? [] as $entry) {
        foreach ($entry['changes'] ?? [] as $change) {
            $value = $change['value'] ?? [];

            // Resolve business by phone_number_id
            $resolvedBusinessId = $businessId;
            $phoneNumberId      = $value['metadata']['phone_number_id'] ?? null;

            if ($resolvedBusinessId <= 0 && $phoneNumberId) {
                $stmt = db()->prepare("SELECT business_id FROM whatsapp_configs WHERE phone_number_id = ?");
                $stmt->execute([$phoneNumberId]);
                $resolvedBusinessId = (int)$stmt->fetchColumn();

                if ($resolvedBusinessId <= 0) {
                    whLog('WARN', 'No business found for phone_number_id', ['phone_number_id' => $phoneNumberId]);
                    continue;
                }
            }

            if ($resolvedBusinessId <= 0) {
                whLog('WARN', 'Could not resolve business_id', ['phone_number_id' => $phoneNumberId]);
                continue;
            }

            // Status updates (read receipts, delivered) — log and skip
            if (!empty($value['statuses'])) {
                foreach ($value['statuses'] as $s) {
                    whLog('INFO', 'Message status update', ['status' => $s['status'] ?? '', 'id' => $s['id'] ?? '']);
                }
            }

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

                if ($from === '') {
                    whLog('WARN', 'Message missing "from" field', ['type' => $type]);
                    continue;
                }

                whLog('INFO', 'Inbound message', [
                    'biz'   => $resolvedBusinessId,
                    'from'  => substr($from, 0, 6) . '****',
                    'type'  => $type,
                    'text'  => mb_substr($text, 0, 60),
                    'pid'   => $phoneNumberId,
                ]);

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
                        whLog('INFO', 'Bot processed message OK', ['biz' => $resolvedBusinessId, 'state' => $text]);
                    } catch (Throwable $e) {
                        whLog('ERROR', 'Bot exception: ' . $e->getMessage(), [
                            'file' => basename($e->getFile()),
                            'line' => $e->getLine(),
                            'biz'  => $resolvedBusinessId,
                        ]);
                    }
                } else {
                    whLog('INFO', 'Message skipped — no text or replyId', ['type' => $type]);
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
