<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();
header('Content-Type: application/json');

$businessId = (int)$_SESSION['business_id'];

$serviceId  = (int)get('service_id');
$date       = get('date');
$staffId    = (int)get('staff_id');
$excludeId  = (int)get('exclude_id');

if ($serviceId <= 0 || empty($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['slots' => []]);
    exit;
}

$slots = getAvailableSlots($businessId, $serviceId, $date, $staffId, $excludeId);

echo json_encode(['slots' => $slots]);
