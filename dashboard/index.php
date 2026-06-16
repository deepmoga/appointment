<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/wallet.php';

requireAuth();

$business     = getCurrentBusiness();
$businessId   = (int)$_SESSION['business_id'];
$stats        = getDashboardStats($businessId);
$appointments = getRecentAppointments($businessId, 8);

// ─── Setup progress checks ─────────────────────────────────────────────────────
$waConfigured = false;
$hasServices  = false;
$hasStaff     = false;
try {
    $stmt = db()->prepare("SELECT is_connected FROM whatsapp_configs WHERE business_id = ?");
    $stmt->execute([$businessId]);
    $wa = $stmt->fetch();
    $waConfigured = $wa && $wa['is_connected'];

    $stmt = db()->prepare("SELECT COUNT(*) FROM services WHERE business_id = ?");
    $stmt->execute([$businessId]);
    $hasServices = (int)$stmt->fetchColumn() > 0;

    $stmt = db()->prepare("SELECT COUNT(*) FROM staff WHERE business_id = ?");
    $stmt->execute([$businessId]);
    $hasStaff = (int)$stmt->fetchColumn() > 0;
} catch (Exception $e) {}

// ─── Wallet info ───────────────────────────────────────────────────────────────
$walletBalance  = getWalletBalance($businessId);
$paymentMode    = $business['payment_mode'] ?? 'platform';
$platformSettings = getPlatformSettings();
$feePerBooking  = ($paymentMode === 'own')
    ? (float)($platformSettings['fee_own_gateway'] ?? 5)
    : (float)($platformSettings['fee_platform_gateway'] ?? 20);
$remainingBookings = $feePerBooking > 0 ? floor($walletBalance / $feePerBooking) : 0;
$lowBalance     = $walletBalance < ($feePerBooking * 5);

$setupDone = $waConfigured && $hasServices && $hasStaff;

$STATUS_META = [
    'pending'     => ['label' => 'Pending',     'class' => 'badge-pending'],
    'confirmed'   => ['label' => 'Confirmed',   'class' => 'badge-confirmed'],
    'in_progress' => ['label' => 'In Progress', 'class' => 'badge-in_progress'],
    'completed'   => ['label' => 'Completed',   'class' => 'badge-completed'],
    'cancelled'   => ['label' => 'Cancelled',   'class' => 'badge-cancelled'],
    'no_show'     => ['label' => 'No Show',     'class' => 'badge-no_show'],
];

$activeNav = 'dashboard';
$pageTitle = '👋 Welcome back, ' . explode(' ', $business['name'])[0];
include __DIR__ . '/partials/head.php';
?>

<!-- WhatsApp connection banner -->
<?php if (!$waConfigured): ?>
<div class="wa-connect-banner">
  <div class="wa-connect-banner-icon">📱</div>
  <div style="flex:1">
    <div class="wa-connect-banner-title">Connect your WhatsApp Business account</div>
    <div class="wa-connect-banner-sub">Link your WhatsApp to start receiving bookings automatically via chatbot.</div>
  </div>
  <a href="whatsapp.php" class="btn btn-wa btn-sm">Connect WhatsApp →</a>
</div>
<?php endif; ?>

<!-- Setup steps (shown until all steps done) -->
<?php if (!$setupDone): ?>
<div class="setup-card">
  <div class="setup-card-title">🚀 Complete your setup</div>
  <div class="setup-card-sub">Finish these steps to start accepting WhatsApp bookings.</div>
  <div class="setup-steps">
    <a href="whatsapp.php" class="setup-step <?= $waConfigured ? 'done' : '' ?>">
      <span class="setup-step-check"><?= $waConfigured ? '✅' : '📱' ?></span>
      Connect WhatsApp
    </a>
    <a href="services.php" class="setup-step <?= $hasServices ? 'done' : '' ?>">
      <span class="setup-step-check"><?= $hasServices ? '✅' : '🛍️' ?></span>
      Add Services
    </a>
    <a href="staff.php" class="setup-step <?= $hasStaff ? 'done' : '' ?>">
      <span class="setup-step-check"><?= $hasStaff ? '✅' : '👥' ?></span>
      Add Staff Members
    </a>
    <a href="hours.php" class="setup-step">
      <span class="setup-step-check">⏰</span>
      Set Business Hours
    </a>
    <a href="templates.php" class="setup-step">
      <span class="setup-step-check">💬</span>
      Customize Bot Messages
    </a>
  </div>
</div>
<?php endif; ?>

<!-- Stats row -->
<div class="stats-row">
  <div class="stat-card">
    <div class="stat-card-icon green">📅</div>
    <div>
      <div class="stat-card-num"><?= $stats['today_appointments'] ?></div>
      <div class="stat-card-label">Today's Appointments</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-card-icon orange">⏳</div>
    <div>
      <div class="stat-card-num"><?= $stats['pending'] ?></div>
      <div class="stat-card-label">Pending Confirmations</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-card-icon blue">👤</div>
    <div>
      <div class="stat-card-num"><?= number_format($stats['total_customers']) ?></div>
      <div class="stat-card-label">Total Customers</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-card-icon indigo">💰</div>
    <div>
      <div class="stat-card-num"><?= formatPrice($stats['monthly_revenue'], $business['currency'] ?? 'USD') ?></div>
      <div class="stat-card-label">Revenue This Month</div>
    </div>
  </div>
</div>

<!-- Content grid -->
<div class="content-grid-2">

  <!-- Recent appointments table -->
  <div class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
      <span style="font-size:1rem;font-weight:700;color:var(--gray-900);">Recent Appointments</span>
      <a href="appointments.php" class="btn btn-sm btn-outline">View All</a>
    </div>
    <?php if (empty($appointments)): ?>
      <div class="empty-state">
        <div class="empty-state-emoji">📅</div>
        <div class="empty-state-title">No appointments yet</div>
        <div class="empty-state-desc">Once customers start booking via WhatsApp, their appointments will appear here.</div>
        <a href="appointments.php" class="btn btn-primary btn-sm">+ Add Manual Booking</a>
      </div>
    <?php else: ?>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead>
            <tr>
              <th>Customer</th>
              <th>Service</th>
              <th>Date & Time</th>
              <th>Staff</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($appointments as $appt): ?>
            <?php $s = $STATUS_META[$appt['status']] ?? ['label' => $appt['status'], 'class' => '']; ?>
            <tr>
              <td>
                <div style="font-weight:600;color:var(--gray-900);"><?= htmlspecialchars($appt['customer_name'] ?: 'Unknown') ?></div>
                <div style="font-size:.78rem;color:var(--gray-400);"><?= htmlspecialchars($appt['customer_phone']) ?></div>
              </td>
              <td><?= htmlspecialchars($appt['service_name'] ?: '—') ?></td>
              <td>
                <div><?= formatDate($appt['appointment_date']) ?></div>
                <div style="font-size:.78rem;color:var(--gray-400);"><?= formatTime($appt['appointment_time']) ?></div>
              </td>
              <td><?= htmlspecialchars($appt['staff_name'] ?: '—') ?></td>
              <td><span class="badge <?= $s['class'] ?>"><?= $s['label'] ?></span></td>
              <td>
                <a href="appointments.php?date=<?= urlencode($appt['appointment_date']) ?>" class="btn btn-sm btn-outline">Manage</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Right column -->
  <div style="display:flex;flex-direction:column;gap:20px;">

    <!-- Quick actions -->
    <div class="card">
      <div class="card-header">
        <span style="font-size:.95rem;font-weight:700;color:var(--gray-900);">Quick Actions</span>
      </div>
      <div class="card-body">
        <div class="quick-grid">
          <a href="appointments.php" class="quick-action">
            <span class="quick-action-icon">➕</span> New Booking
          </a>
          <a href="customers.php" class="quick-action">
            <span class="quick-action-icon">👤</span> Add Customer
          </a>
          <a href="services.php" class="quick-action">
            <span class="quick-action-icon">🛍️</span> Add Service
          </a>
          <a href="staff.php" class="quick-action">
            <span class="quick-action-icon">👨‍⚕️</span> Add Doctor
          </a>
          <a href="whatsapp.php?tab=conversations" class="quick-action">
            <span class="quick-action-icon">📩</span> Send Message
          </a>
          <a href="analytics.php" class="quick-action">
            <span class="quick-action-icon">📊</span> View Report
          </a>
        </div>
      </div>
    </div>

    <!-- Wallet card -->
    <div class="card" style="<?= $lowBalance ? 'border-color:#fca5a5;' : '' ?>">
      <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <span style="font-size:.95rem;font-weight:700;color:var(--gray-900);">💰 Wallet</span>
        <a href="wallet.php" class="btn btn-sm btn-outline">Recharge</a>
      </div>
      <div class="card-body">
        <?php if ($lowBalance): ?>
          <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:.82rem;color:#991b1b;">
            ⚠️ Low balance! Recharge to keep receiving bookings.
          </div>
        <?php endif; ?>

        <div style="display:grid;gap:12px;">
          <!-- Balance -->
          <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:<?= $lowBalance ? '#fef2f2' : '#f0fdf4' ?>;border-radius:10px;">
            <div>
              <div style="font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--gray-400);">Wallet Balance</div>
              <div style="font-size:1.6rem;font-weight:800;color:<?= $lowBalance ? '#dc2626' : '#16a34a' ?>;line-height:1.2;">₹<?= number_format($walletBalance, 2) ?></div>
            </div>
            <div style="font-size:2rem;">💳</div>
          </div>

          <!-- Fee per booking -->
          <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 16px;background:var(--gray-50);border-radius:10px;">
            <div>
              <div style="font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--gray-400);">Fee Per Booking</div>
              <div style="font-size:1.1rem;font-weight:700;color:var(--gray-800);">₹<?= number_format($feePerBooking, 0) ?></div>
            </div>
            <div style="font-size:.72rem;padding:4px 8px;border-radius:20px;font-weight:600;
              <?= $paymentMode === 'own' ? 'background:#dbeafe;color:#1d4ed8;' : 'background:#ede9fe;color:#6d28d9;' ?>">
              <?= $paymentMode === 'own' ? 'Own Gateway' : 'Platform Gateway' ?>
            </div>
          </div>

          <!-- Remaining bookings -->
          <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 16px;background:var(--gray-50);border-radius:10px;">
            <div>
              <div style="font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--gray-400);">Remaining Bookings</div>
              <div style="font-size:1.1rem;font-weight:700;color:<?= $remainingBookings < 5 ? '#dc2626' : 'var(--gray-800)' ?>;"><?= number_format($remainingBookings) ?></div>
            </div>
            <div style="font-size:1.4rem;"><?= $remainingBookings < 5 ? '🔴' : ($remainingBookings < 20 ? '🟡' : '🟢') ?></div>
          </div>
        </div>

        <a href="wallet.php" class="btn btn-primary btn-full btn-sm" style="margin-top:14px;">View Wallet & Recharge →</a>
      </div>
    </div>

    <!-- Subscription card -->
    <div class="card" style="background:linear-gradient(135deg,#eef2ff,#f3e8ff);border-color:var(--primary-light);">
      <div class="card-body">
        <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--primary);margin-bottom:8px;"><?= ucfirst($business['subscription_plan'] ?? 'free') ?> Plan</div>
        <div style="font-size:.9rem;color:var(--gray-700);margin-bottom:16px;line-height:1.6;">
          <?php if (($business['subscription_plan'] ?? 'free') === 'free'): ?>
            You're on the <strong>Free Plan</strong>. Upgrade to unlock unlimited appointments, staff, and advanced features.
          <?php else: ?>
            Thanks for being a <strong><?= ucfirst($business['subscription_plan']) ?></strong> subscriber!
          <?php endif; ?>
        </div>
        <a href="settings.php#subscription" class="btn btn-primary btn-full btn-sm">View Plans →</a>
      </div>
    </div>

  </div>
</div><!-- /content grid -->

<?php include __DIR__ . '/partials/foot.php'; ?>
