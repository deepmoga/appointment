<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();
header('Content-Type: application/json');

$businessId = (int)$_SESSION['business_id'];
$customerId = (int)get('customer_id');

if ($customerId <= 0 || !ownsRecord('customers', $customerId, $businessId)) {
    echo json_encode(['appointments' => []]);
    exit;
}

$stmt = db()->prepare("
    SELECT a.id, a.appointment_date, a.appointment_time, a.status, a.payment_status, a.total_price,
           s.name AS service_name, st.name AS staff_name
    FROM appointments a
    LEFT JOIN services s ON s.id = a.service_id
    LEFT JOIN staff   st ON st.id = a.staff_id
    WHERE a.business_id = ? AND a.customer_id = ?
    ORDER BY a.appointment_date DESC, a.appointment_time DESC
    LIMIT 50
");
$stmt->execute([$businessId, $customerId]);

echo json_encode(['appointments' => $stmt->fetchAll()]);
