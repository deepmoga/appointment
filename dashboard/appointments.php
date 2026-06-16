<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/whatsapp.php';

requireAuth();

$business     = getCurrentBusiness();
$businessId   = (int)$_SESSION['business_id'];
$currency     = $business['currency'] ?? 'USD';
$tokenMode    = $business['token_mode'] ?? 'db_id';
$timeRequired = isset($business['time_required']) ? (int)$business['time_required'] : 1;

$STATUSES = [
    'pending'     => 'Pending',
    'confirmed'   => 'Confirmed',
    'in_progress' => 'In Progress',
    'completed'   => 'Completed',
    'cancelled'   => 'Cancelled',
    'no_show'     => 'No Show',
];
$PAYMENT_STATUSES = [
    'unpaid'   => 'Unpaid',
    'paid'     => 'Paid',
    'partial'  => 'Partial',
    'refunded' => 'Refunded',
];

// ─── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        setFlash('error', 'Invalid request. Please try again.');
        header('Location: appointments.php'); exit;
    }

    $action = post('action');
    $pdo    = db();

    if ($action === 'create') {
        $customerPhone = post('customer_phone');
        $customerName  = post('customer_name');
        $customerEmail = post('customer_email');
        $serviceId     = (int)post('service_id');
        $staffId       = (int)post('staff_id');
        $date          = post('appointment_date');
        $time          = post('appointment_time');
        $customerNote  = post('customer_note');
        $adminNote     = post('admin_note');

        if (empty($customerPhone) || $serviceId <= 0 || empty($date) || ($timeRequired && empty($time))) {
            setFlash('error', 'Please fill in customer phone, service and date' . ($timeRequired ? ' and time slot' : '') . '.');
        } else {
            $stmt = $pdo->prepare("SELECT * FROM services WHERE id = ? AND business_id = ?");
            $stmt->execute([$serviceId, $businessId]);
            $service = $stmt->fetch();

            if (!$service) {
                setFlash('error', 'Selected service not found.');
            } else {
                $isDateOnly = !$timeRequired || empty($time);
                $timeNorm   = $isDateOnly ? '00:00:00' : ((strlen($time) === 5) ? $time . ':00' : $time);
                $assignedStaff = $staffId ?: null;

                if (!$isDateOnly) {
                    $slots = getAvailableSlots($businessId, $serviceId, $date, $staffId);
                    $matched = null;
                    foreach ($slots as $slot) {
                        if ($slot['time'] === $timeNorm) { $matched = $slot; break; }
                    }
                    if (!$matched) {
                        setFlash('error', 'That time slot is no longer available. Please choose another.');
                        header('Location: appointments.php?' . http_build_query($_GET)); exit;
                    }
                    $assignedStaff = $staffId > 0 ? $staffId : ($matched['staff_id'] ?? null);
                }

                $duration = (int)$service['duration'];
                $endTime  = $isDateOnly ? '00:00:00' : minutesToTime(timeToMinutes($timeNorm) + $duration);

                $customerId  = findOrCreateCustomer($businessId, $customerPhone, $customerName, $customerEmail);
                $dailyToken  = ($tokenMode === 'daily') ? assignDailyToken($businessId, $date) : null;

                $pdo->prepare("
                    INSERT INTO appointments
                        (business_id, customer_id, service_id, staff_id, appointment_date, appointment_time, end_time, duration, status, customer_note, admin_note, total_price, payment_status, booking_source, daily_token)
                    VALUES (?,?,?,?,?,?,?,?, 'confirmed', ?, ?, ?, 'unpaid', 'admin', ?)
                ")->execute([
                    $businessId, $customerId, $serviceId, $assignedStaff,
                    $date, $timeNorm, $endTime, $duration,
                    $customerNote, $adminNote, $service['price'], $dailyToken,
                ]);

                setFlash('success', 'Appointment booked successfully.');
            }
        }

    } elseif ($action === 'update_status') {
        $id     = (int)post('id');
        $status = post('status');

        if (ownsRecord('appointments', $id, $businessId) && array_key_exists($status, $STATUSES)) {
            $stmt = $pdo->prepare("SELECT status, customer_id, total_price FROM appointments WHERE id = ?");
            $stmt->execute([$id]);
            $appt = $stmt->fetch();

            $pdo->prepare("UPDATE appointments SET status = ? WHERE id = ? AND business_id = ?")
                ->execute([$status, $id, $businessId]);

            // Adjust customer stats when transitioning to/from "completed"
            if ($appt && $appt['status'] !== 'completed' && $status === 'completed') {
                $pdo->prepare("UPDATE customers SET total_visits = total_visits + 1, total_spent = total_spent + ? WHERE id = ?")
                    ->execute([$appt['total_price'], $appt['customer_id']]);
            } elseif ($appt && $appt['status'] === 'completed' && $status !== 'completed') {
                $pdo->prepare("UPDATE customers SET total_visits = GREATEST(0, total_visits - 1), total_spent = GREATEST(0, total_spent - ?) WHERE id = ?")
                    ->execute([$appt['total_price'], $appt['customer_id']]);
            }

            sendAppointmentStatusMessage($businessId, $id, $status);

            setFlash('success', 'Appointment status updated.');
        }

    } elseif ($action === 'update_payment') {
        $id  = (int)post('id');
        $pay = post('payment_status');

        if (ownsRecord('appointments', $id, $businessId) && array_key_exists($pay, $PAYMENT_STATUSES)) {
            $pdo->prepare("UPDATE appointments SET payment_status = ? WHERE id = ? AND business_id = ?")
                ->execute([$pay, $id, $businessId]);
            setFlash('success', 'Payment status updated.');
        }

    } elseif ($action === 'update_note') {
        $id   = (int)post('id');
        $note = post('admin_note');

        if (ownsRecord('appointments', $id, $businessId)) {
            $pdo->prepare("UPDATE appointments SET admin_note = ? WHERE id = ? AND business_id = ?")
                ->execute([$note, $id, $businessId]);
            setFlash('success', 'Note saved.');
        }

    } elseif ($action === 'notify_available') {
        $id = (int)post('id');
        if (ownsRecord('appointments', $id, $businessId)) {
            $ok = sendDoctorAvailableMessage($businessId, $id);
            setFlash($ok ? 'success' : 'error', $ok ? 'Availability notification sent.' : 'Failed to send notification — check WhatsApp connection.');
        }

    } elseif ($action === 'notify_all_today') {
        if (!isWhatsappConnected($businessId)) {
            setFlash('error', 'WhatsApp is not connected.');
        } else {
            $stmt = $pdo->prepare("SELECT id FROM appointments WHERE business_id = ? AND appointment_date = CURDATE() AND status IN ('pending','confirmed') ORDER BY appointment_time ASC");
            $stmt->execute([$businessId]);
            $ids = array_column($stmt->fetchAll(), 'id');
            $sent = 0;
            foreach ($ids as $aid) {
                if (sendDoctorAvailableMessage($businessId, (int)$aid)) $sent++;
            }
            setFlash('success', "Notified {$sent} customer(s) for today.");
        }
    }

    header('Location: appointments.php?' . http_build_query($_GET));
    exit;
}

// ─── Filters ────────────────────────────────────────────────────────────────
$filterDate   = get('date', date('Y-m-d'));
$filterStatus = get('status');
$filterStaff  = (int)get('staff', '0');
$search       = get('q');
$page         = max(1, (int)get('page', '1'));
$perPage      = 20;

$where  = ["a.business_id = ?"];
$params = [$businessId];

if ($filterDate !== '') {
    $where[] = "a.appointment_date = ?";
    $params[] = $filterDate;
}
if ($filterStatus !== '' && array_key_exists($filterStatus, $STATUSES)) {
    $where[] = "a.status = ?";
    $params[] = $filterStatus;
}
if ($filterStaff > 0) {
    $where[] = "a.staff_id = ?";
    $params[] = $filterStaff;
}
if ($search !== '') {
    $where[] = "(c.name LIKE ? OR c.phone LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
$whereSql = implode(' AND ', $where);

// Count total
$stmt = db()->prepare("
    SELECT COUNT(*) FROM appointments a
    LEFT JOIN customers c ON c.id = a.customer_id
    WHERE $whereSql
");
$stmt->execute($params);
$totalRows  = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$orderBy = $filterDate !== ''
    ? "IF(HOUR(a.appointment_time) < 12, 0, 1) ASC, daily_seq ASC"
    : "a.appointment_date DESC, IF(HOUR(a.appointment_time) < 12, 0, 1) ASC, daily_seq ASC";

$sql = "
    SELECT a.*, c.name AS customer_name, c.phone AS customer_phone, c.email AS customer_email,
           s.name AS service_name, s.duration AS service_duration,
           st.name AS staff_name, st.color AS staff_color,
           IF(HOUR(a.appointment_time) < 12, 'M', 'E') AS session_prefix,
           (SELECT COUNT(*) FROM appointments a2
            WHERE a2.business_id = a.business_id
              AND a2.appointment_date = a.appointment_date
              AND IF(HOUR(a.appointment_time) < 12, HOUR(a2.appointment_time) < 12, HOUR(a2.appointment_time) >= 12)
              AND a2.id <= a.id) AS daily_seq
    FROM appointments a
    LEFT JOIN customers c ON c.id = a.customer_id
    LEFT JOIN services  s ON s.id = a.service_id
    LEFT JOIN staff    st ON st.id = a.staff_id
    WHERE $whereSql
    ORDER BY $orderBy
    LIMIT $perPage OFFSET $offset
";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$appointments = $stmt->fetchAll();

// ─── Data for "New Appointment" modal ────────────────────────────────────────
$categories = getCategories($businessId, true);
$allServices = getServicesList($businessId, null, true);
$allStaff = getStaffList($businessId, true);

$serviceStaffMap = [];
foreach ($allServices as $svc) {
    $serviceStaffMap[$svc['id']] = getServiceStaffIds($svc['id']);
}

$activeNav = 'appointments';
$pageTitle = 'Appointments';
include __DIR__ . '/partials/head.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
  <p style="color:var(--gray-500);font-size:.9rem;max-width:520px;">
    View and manage bookings from WhatsApp and create manual appointments for walk-in or phone customers.
  </p>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <?php if ($filterDate === date('Y-m-d') && isWhatsappConnected($businessId)): ?>
      <form method="POST" onsubmit="return confirm('Send availability notification to all today\'s pending/confirmed customers?')">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="notify_all_today">
        <button type="submit" class="btn btn-outline btn-sm" style="background:#f0fdf4;color:#166534;border-color:#bbf7d0;">🟢 Notify All Today</button>
      </form>
    <?php endif; ?>
    <?php if (empty($allServices)): ?>
      <a href="services.php" class="btn btn-primary">+ Add Service First</a>
    <?php else: ?>
      <button class="btn btn-primary" data-modal-open="bookingModal" onclick="resetBookingForm()">+ New Appointment</button>
    <?php endif; ?>
  </div>
</div>

<!-- Filters -->
<form method="GET" class="filter-bar">
  <div class="form-group" style="margin:0;">
    <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($filterDate) ?>" onchange="this.form.submit()">
  </div>
  <select name="status" class="filter-select" onchange="this.form.submit()">
    <option value="">All Statuses</option>
    <?php foreach ($STATUSES as $key => $label): ?>
      <option value="<?= $key ?>" <?= $filterStatus === $key ? 'selected' : '' ?>><?= $label ?></option>
    <?php endforeach; ?>
  </select>
  <?php if (!empty($allStaff)): ?>
  <select name="staff" class="filter-select" onchange="this.form.submit()">
    <option value="0">All Staff</option>
    <?php foreach ($allStaff as $st): ?>
      <option value="<?= $st['id'] ?>" <?= $filterStaff == $st['id'] ? 'selected' : '' ?>><?= htmlspecialchars($st['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>
  <div class="search-box">
    <span class="search-box-icon">🔍</span>
    <input type="text" name="q" placeholder="Search customer name or phone…" value="<?= htmlspecialchars($search) ?>">
  </div>
  <button type="submit" class="btn btn-outline btn-sm">Filter</button>
  <a href="appointments.php" class="btn btn-ghost btn-sm">Clear</a>
  <?php if ($filterDate !== date('Y-m-d')): ?>
    <a href="?date=<?= date('Y-m-d') ?>" class="btn btn-ghost btn-sm">Today</a>
  <?php endif; ?>
</form>

<?php if (empty($appointments)): ?>
  <div class="card">
    <div class="empty-state">
      <div class="empty-state-emoji">📅</div>
      <div class="empty-state-title">No appointments found</div>
      <div class="empty-state-desc">
        <?= $filterDate === date('Y-m-d') ? 'No bookings for today yet.' : 'No bookings match your filters.' ?>
      </div>
      <?php if (!empty($allServices)): ?>
        <button class="btn btn-primary btn-sm" data-modal-open="bookingModal" onclick="resetBookingForm()">+ New Appointment</button>
      <?php endif; ?>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div style="overflow-x:auto;">
      <table class="data-table">
        <thead>
          <tr>
            <th>Booking #</th>
            <th><?= $tokenMode === 'daily' ? 'Token · Date' : 'Date &amp; Time' ?></th>
            <th>Customer</th>
            <th>Service</th>
            <th>Staff</th>
            <th>Price</th>
            <th>Payment</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($appointments as $a): ?>
          <?php
            $bkPrefix = $a['session_prefix'] ?? (((int)substr($a['appointment_time'] ?? '09', 0, 2) < 12) ? 'M' : 'E');
            $bkNum    = (int)($a['daily_seq'] ?? 0);
            $bkLabel  = $bkNum > 0 ? $bkPrefix . str_pad((string)$bkNum, 3, '0', STR_PAD_LEFT) : '—';
          ?>
          <tr>
            <td>
              <span style="font-weight:700;font-size:.95rem;color:<?= $bkPrefix === 'M' ? '#d97706' : '#7c3aed' ?>;">
                <?= htmlspecialchars($bkLabel) ?>
              </span>
            </td>
            <td>
              <?php if ($tokenMode === 'daily' && !empty($a['daily_token'])): ?>
                <div style="font-weight:700;color:var(--primary);font-size:1rem;">Token #<?= (int)$a['daily_token'] ?></div>
                <div style="font-size:.8rem;color:var(--gray-500);"><?= formatDate($a['appointment_date']) ?></div>
              <?php else: ?>
                <div style="font-weight:600;color:var(--gray-900);"><?= formatDate($a['appointment_date']) ?></div>
              <?php endif; ?>
              <?php $isAllDay = substr($a['appointment_time'] ?? '', 0, 5) === '00:00'; ?>
              <div style="font-size:.8rem;color:var(--gray-400);">
                <?= $isAllDay ? '— All Day —' : formatTime($a['appointment_time']) . ' – ' . formatTime($a['end_time']) ?>
              </div>
            </td>
            <td>
              <div style="font-weight:600;color:var(--gray-900);"><?= htmlspecialchars($a['patient_name'] ?: $a['customer_name'] ?: 'Unknown') ?></div>
              <div style="font-size:.8rem;color:var(--gray-400);"><?= htmlspecialchars($a['customer_phone']) ?></div>
            </td>
            <td>
              <?= htmlspecialchars($a['service_name'] ?? '—') ?>
              <div style="font-size:.78rem;color:var(--gray-400);"><?= (int)$a['duration'] ?> min · <?= ucfirst($a['booking_source']) ?></div>
            </td>
            <td>
              <?php if ($a['staff_name']): ?>
                <span style="display:inline-flex;align-items:center;gap:6px;">
                  <span style="width:10px;height:10px;border-radius:50%;background:<?= htmlspecialchars($a['staff_color'] ?: '#6366f1') ?>;display:inline-block;"></span>
                  <?= htmlspecialchars($a['staff_name']) ?>
                </span>
              <?php else: ?>
                <span style="color:var(--gray-400);">Any</span>
              <?php endif; ?>
            </td>
            <td style="font-weight:600;"><?= formatPrice((float)$a['total_price'], $currency) ?></td>
            <td>
              <span class="badge badge-<?= $a['payment_status'] === 'paid' ? 'confirmed' : ($a['payment_status'] === 'refunded' ? 'cancelled' : 'pending') ?>">
                <?= $PAYMENT_STATUSES[$a['payment_status']] ?? ucfirst($a['payment_status']) ?>
              </span>
            </td>
            <td>
              <span class="badge badge-<?= $a['status'] ?>"><?= $STATUSES[$a['status']] ?? ucfirst($a['status']) ?></span>
            </td>
            <td>
              <button class="btn btn-sm btn-outline"
                onclick='openDetails(<?= json_encode($a, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                Manage
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <div class="pagination">
        <?php
          $qs = $_GET; unset($qs['page']);
          $base = '?' . http_build_query($qs) . (empty($qs) ? '' : '&');
        ?>
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
          <a href="<?= $base ?>page=<?= $p ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<!-- ── New Appointment Modal ─────────────────────────────── -->
<div class="modal-overlay" id="bookingModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div class="modal-title">New Appointment</div>
      <button class="modal-close" data-modal-close>✕</button>
    </div>
    <form method="POST" id="bookingForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="create">
      <div class="modal-body">

        <h4 style="font-size:.9rem;font-weight:700;color:var(--gray-900);margin-bottom:12px;">Customer</h4>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Phone Number *</label>
            <input type="text" name="customer_phone" id="bk_phone" class="form-control" placeholder="e.g. +1 234 567 8900" required>
          </div>
          <div class="form-group">
            <label class="form-label">Name</label>
            <input type="text" name="customer_name" id="bk_name" class="form-control" placeholder="Customer name">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Email (optional)</label>
          <input type="email" name="customer_email" id="bk_email" class="form-control" placeholder="customer@example.com">
        </div>

        <h4 style="font-size:.9rem;font-weight:700;color:var(--gray-900);margin:20px 0 12px;">Booking Details</h4>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Category</label>
            <select id="bk_category" class="form-select" onchange="filterServices()">
              <option value="0">All Categories</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['icon'] . ' ' . $cat['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Service *</label>
            <select name="service_id" id="bk_service" class="form-select" onchange="onServiceChange()" required>
              <option value="">Select a service</option>
              <?php foreach ($allServices as $svc): ?>
                <option value="<?= $svc['id'] ?>" data-category="<?= $svc['category_id'] ?>">
                  <?= htmlspecialchars($svc['name']) ?> — <?= formatPrice((float)$svc['price'], $currency) ?> (<?= (int)$svc['duration'] ?> min)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Staff</label>
            <select name="staff_id" id="bk_staff" class="form-select" onchange="loadSlots()">
              <option value="0">Any available</option>
              <?php foreach ($allStaff as $st): ?>
                <option value="<?= $st['id'] ?>"><?= htmlspecialchars($st['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Date *</label>
            <input type="date" id="bk_date" class="form-control" value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>" onchange="loadSlots()" required>
          </div>
        </div>

        <?php if ($timeRequired): ?>
        <div class="form-group">
          <label class="form-label">Available Time Slots *</label>
          <input type="hidden" name="appointment_date" id="bk_date_hidden" value="<?= date('Y-m-d') ?>">
          <input type="hidden" name="appointment_time" id="bk_time_hidden" value="">
          <div id="bk_slots" style="display:flex;flex-wrap:wrap;gap:8px;min-height:42px;">
            <span style="color:var(--gray-400);font-size:.85rem;">Select a service to see available slots.</span>
          </div>
        </div>
        <?php else: ?>
        <input type="hidden" name="appointment_date" id="bk_date_hidden" value="<?= date('Y-m-d') ?>">
        <input type="hidden" name="appointment_time" id="bk_time_hidden" value="">
        <div class="form-group">
          <div class="info-box" style="font-size:.85rem;">⏰ Time slots are disabled — customers will be seen in token/walk-in order.</div>
        </div>
        <?php endif; ?>

        <div class="form-group">
          <label class="form-label">Customer Note</label>
          <textarea name="customer_note" class="form-control" placeholder="Any notes from the customer (optional)"></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">Internal Note</label>
          <textarea name="admin_note" class="form-control" placeholder="Internal note, not visible to customer (optional)"></textarea>
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Book Appointment</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Appointment Details / Manage Modal ────────────────── -->
<div class="modal-overlay" id="detailsModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div class="modal-title">Appointment Details</div>
      <button class="modal-close" data-modal-close>✕</button>
    </div>
    <div class="modal-body">

      <div class="content-grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:20px;">
        <div class="info-box">
          <div style="font-size:.78rem;color:var(--gray-400);margin-bottom:4px;">Customer</div>
          <div id="dt_customer_name" style="font-weight:700;color:var(--gray-900);"></div>
          <div id="dt_customer_phone" style="font-size:.85rem;color:var(--gray-500);"></div>
          <div id="dt_customer_email" style="font-size:.85rem;color:var(--gray-500);"></div>
        </div>
        <div class="info-box">
          <div style="font-size:.78rem;color:var(--gray-400);margin-bottom:4px;">Booking</div>
          <div id="dt_service" style="font-weight:700;color:var(--gray-900);"></div>
          <div id="dt_datetime" style="font-size:.85rem;color:var(--gray-500);"></div>
          <div id="dt_staff" style="font-size:.85rem;color:var(--gray-500);"></div>
          <div id="dt_price" style="font-size:.85rem;color:var(--gray-500);"></div>
        </div>
      </div>

      <div id="dt_customer_note_wrap" style="margin-bottom:20px;">
        <div style="font-size:.78rem;color:var(--gray-400);margin-bottom:4px;">Customer Note</div>
        <div id="dt_customer_note" style="font-size:.88rem;color:var(--gray-700);background:var(--gray-50);padding:12px;border-radius:var(--radius-sm);"></div>
      </div>

      <div id="dt_notify_wrap" style="margin-bottom:16px;display:none;">
        <form method="POST" id="notifyForm">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="notify_available">
          <input type="hidden" name="id" id="dt_id_notify">
          <button type="submit" class="btn btn-outline btn-sm" style="background:#f0fdf4;color:#166534;border-color:#bbf7d0;" onclick="return confirm('Send an availability notification to this customer?')">
            🟢 Notify: Doctor / Staff Available
          </button>
        </form>
      </div>

      <div class="tabs" data-panel-group="#detailsPanels">
        <div class="tab active" data-tab="status">Status</div>
        <div class="tab" data-tab="payment">Payment</div>
        <div class="tab" data-tab="note">Internal Note</div>
      </div>

      <div id="detailsPanels">
        <div class="tab-panel active" data-panel="status">
          <form method="POST" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="id" id="dt_id_status">
            <div class="form-group" style="margin:0;flex:1;min-width:180px;">
              <label class="form-label">Appointment Status</label>
              <select name="status" id="dt_status" class="form-select">
                <?php foreach ($STATUSES as $key => $label): ?>
                  <option value="<?= $key ?>"><?= $label ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="submit" class="btn btn-primary">Update Status</button>
          </form>
        </div>

        <div class="tab-panel" data-panel="payment">
          <form method="POST" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_payment">
            <input type="hidden" name="id" id="dt_id_payment">
            <div class="form-group" style="margin:0;flex:1;min-width:180px;">
              <label class="form-label">Payment Status</label>
              <select name="payment_status" id="dt_payment" class="form-select">
                <?php foreach ($PAYMENT_STATUSES as $key => $label): ?>
                  <option value="<?= $key ?>"><?= $label ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="submit" class="btn btn-primary">Update Payment</button>
          </form>
        </div>

        <div class="tab-panel" data-panel="note">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_note">
            <input type="hidden" name="id" id="dt_id_note">
            <div class="form-group">
              <label class="form-label">Internal Note</label>
              <textarea name="admin_note" id="dt_admin_note" class="form-control" placeholder="Internal note, not visible to customer"></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Save Note</button>
          </form>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
const SERVICES = <?= json_encode($allServices, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const SERVICE_STAFF = <?= json_encode($serviceStaffMap, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const ALL_STAFF = <?= json_encode($allStaff, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const STATUS_LABELS = <?= json_encode($STATUSES, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const PAYMENT_LABELS = <?= json_encode($PAYMENT_STATUSES, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function resetBookingForm() {
  document.getElementById('bookingForm').reset();
  document.getElementById('bk_date').value = '<?= date('Y-m-d') ?>';
  document.getElementById('bk_date_hidden').value = '<?= date('Y-m-d') ?>';
  document.getElementById('bk_time_hidden').value = '';
  document.getElementById('bk_staff').innerHTML = '<option value="0">Any available</option>' +
    ALL_STAFF.map(s => `<option value="${s.id}">${s.name}</option>`).join('');
  filterServices();
  document.getElementById('bk_slots').innerHTML = '<span style="color:var(--gray-400);font-size:.85rem;">Select a service to see available slots.</span>';
}

function filterServices() {
  const catId = document.getElementById('bk_category').value;
  const select = document.getElementById('bk_service');
  Array.from(select.options).forEach(opt => {
    if (!opt.value) { opt.hidden = false; return; }
    opt.hidden = (catId !== '0' && opt.dataset.category !== catId);
  });
}

function onServiceChange() {
  const serviceId = document.getElementById('bk_service').value;
  const staffSelect = document.getElementById('bk_staff');
  const eligibleStaffIds = SERVICE_STAFF[serviceId] || [];

  const current = staffSelect.value;
  staffSelect.innerHTML = '<option value="0">Any available</option>' +
    ALL_STAFF
      .filter(s => eligibleStaffIds.length === 0 || eligibleStaffIds.includes(s.id))
      .map(s => `<option value="${s.id}">${s.name}</option>`).join('');
  if ([...staffSelect.options].some(o => o.value === current)) staffSelect.value = current;

  loadSlots();
}

function loadSlots() {
  const serviceId = document.getElementById('bk_service').value;
  const staffId = document.getElementById('bk_staff').value;
  const date = document.getElementById('bk_date').value;
  const slotsEl = document.getElementById('bk_slots');

  document.getElementById('bk_date_hidden').value = date;
  document.getElementById('bk_time_hidden').value = '';

  if (!serviceId || !date) {
    slotsEl.innerHTML = '<span style="color:var(--gray-400);font-size:.85rem;">Select a service to see available slots.</span>';
    return;
  }

  slotsEl.innerHTML = '<span style="color:var(--gray-400);font-size:.85rem;">Loading slots…</span>';

  fetch(`ajax_slots.php?service_id=${serviceId}&staff_id=${staffId}&date=${date}`)
    .then(r => r.json())
    .then(data => {
      if (!data.slots || data.slots.length === 0) {
        slotsEl.innerHTML = '<span style="color:var(--gray-400);font-size:.85rem;">No available slots for this date.</span>';
        return;
      }
      slotsEl.innerHTML = '';
      data.slots.forEach(slot => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-sm btn-outline';
        btn.textContent = slot.label;
        btn.dataset.time = slot.time;
        btn.onclick = () => selectSlot(btn, slot.time);
        slotsEl.appendChild(btn);
      });
    })
    .catch(() => {
      slotsEl.innerHTML = '<span style="color:#991b1b;font-size:.85rem;">Could not load slots. Please try again.</span>';
    });
}

function selectSlot(btn, time) {
  document.querySelectorAll('#bk_slots .btn').forEach(b => {
    b.classList.remove('btn-primary');
    b.classList.add('btn-outline');
  });
  btn.classList.remove('btn-outline');
  btn.classList.add('btn-primary');
  document.getElementById('bk_time_hidden').value = time;
}

function openDetails(a) {
  document.getElementById('dt_customer_name').textContent = a.customer_name || 'Unknown';
  document.getElementById('dt_customer_phone').textContent = a.customer_phone || '';
  document.getElementById('dt_customer_email').textContent = a.customer_email || '';

  document.getElementById('dt_service').textContent = a.service_name || '—';
  document.getElementById('dt_datetime').textContent = a.appointment_date + ' · ' + a.appointment_time.substring(0,5) + ' – ' + a.end_time.substring(0,5);
  document.getElementById('dt_staff').textContent = a.staff_name ? ('Staff: ' + a.staff_name) : 'Staff: Any';
  document.getElementById('dt_price').textContent = 'Price: ' + a.total_price;

  const noteWrap = document.getElementById('dt_customer_note_wrap');
  if (a.customer_note) {
    document.getElementById('dt_customer_note').textContent = a.customer_note;
    noteWrap.style.display = '';
  } else {
    noteWrap.style.display = 'none';
  }

  document.getElementById('dt_id_status').value = a.id;
  document.getElementById('dt_status').value = a.status;

  document.getElementById('dt_id_payment').value = a.id;
  document.getElementById('dt_payment').value = a.payment_status;

  document.getElementById('dt_id_note').value = a.id;
  document.getElementById('dt_admin_note').value = a.admin_note || '';

  // Notify button
  document.getElementById('dt_id_notify').value = a.id;
  const notifyWrap = document.getElementById('dt_notify_wrap');
  notifyWrap.style.display = ['pending','confirmed'].includes(a.status) ? '' : 'none';

  // Reset to first tab
  document.querySelectorAll('#detailsModal .tab').forEach((t,i) => t.classList.toggle('active', i===0));
  document.querySelectorAll('#detailsPanels .tab-panel').forEach((p,i) => p.classList.toggle('active', i===0));

  document.getElementById('detailsModal').classList.add('open');
}
</script>

<?php include __DIR__ . '/partials/foot.php'; ?>
