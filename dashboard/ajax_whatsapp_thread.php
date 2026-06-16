<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();
header('Content-Type: application/json');

$businessId = (int)$_SESSION['business_id'];
$phone      = get('phone');

if ($phone === '') {
    echo json_encode(['messages' => []]);
    exit;
}

$pdo = db();

$stmt = $pdo->prepare("
    SELECT id, direction, message_type, content, created_at
    FROM whatsapp_messages
    WHERE business_id = ? AND customer_phone = ?
    ORDER BY created_at ASC
    LIMIT 200
");
$stmt->execute([$businessId, $phone]);
$messages = $stmt->fetchAll();

// Mark inbound messages as read
$pdo->prepare("UPDATE whatsapp_messages SET is_read = 1 WHERE business_id = ? AND customer_phone = ? AND direction = 'inbound' AND is_read = 0")
    ->execute([$businessId, $phone]);

// Look up customer name if available
$stmt = $pdo->prepare("SELECT name FROM customers WHERE business_id = ? AND phone = ? LIMIT 1");
$stmt->execute([$businessId, $phone]);
$customerName = $stmt->fetchColumn() ?: null;

echo json_encode(['messages' => $messages, 'customer_name' => $customerName]);
