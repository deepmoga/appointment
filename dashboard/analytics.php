<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

$business   = getCurrentBusiness();
$businessId = (int)$_SESSION['business_id'];
$currency   = $business['currency'] ?? 'USD';

// ─── Date range ─────────────────────────────────────────────────────────────
$RANGES = [
    '7d'    => '7 Days',
    '30d'   => '30 Days',
    '90d'   => '90 Days',
    'month' => 'This Month',
    'year'  => 'This Year',
    'all'   => 'All Time',
];

$range = get('range') ?: '30d';
if (!isset($RANGES[$range])) $range = '30d';

$today = date('Y-m-d');
switch ($range) {
    case '7d':    $startDate = date('Y-m-d', strtotime('-6 days'));  $endDate = $today; break;
    case '90d':   $startDate = date('Y-m-d', strtotime('-89 days')); $endDate = $today; break;
    case 'month': $startDate = date('Y-m-01'); $endDate = $today; break;
    case 'year':  $startDate = date('Y-01-01'); $endDate = $today; break;
    case 'all':   $startDate = '2000-01-01'; $endDate = $today; break;
    default:      $startDate = date('Y-m-d', strtotime('-29 days')); $endDate = $today; break;
}

// ─── KPI summary ────────────────────────────────────────────────────────────
$stmt = db()->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'completed' THEN total_price ELSE 0 END) AS revenue,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
        SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) AS no_show
    FROM appointments
    WHERE business_id = ? AND appointment_date BETWEEN ? AND ?
");
$stmt->execute([$businessId, $startDate, $endDate]);
$kpi = $stmt->fetch();

$totalAppts       = (int)$kpi['total'];
$revenue          = (float)$kpi['revenue'];
$completed        = (int)$kpi['completed'];
$cancelled        = (int)$kpi['cancelled'];
$noShow           = (int)$kpi['no_show'];
$cancellationRate = $totalAppts > 0 ? round($cancelled / $totalAppts * 100, 1) : 0;
$avgValue         = $completed > 0 ? $revenue / $completed : 0;

$stmt = db()->prepare("SELECT COUNT(*) FROM customers WHERE business_id = ? AND DATE(created_at) BETWEEN ? AND ?");
$stmt->execute([$businessId, $startDate, $endDate]);
$newCustomers = (int)$stmt->fetchColumn();

// ─── Bookings/revenue over time (bucketed) ───────────────────────────────────
$days = (int)((strtotime($endDate) - strtotime($startDate)) / 86400) + 1;

if ($days <= 14) {
    $groupExpr = "appointment_date";
    $bucket    = 'day';
} elseif ($days <= 120) {
    $groupExpr = "YEARWEEK(appointment_date, 3)";
    $bucket    = 'week';
} else {
    $groupExpr = "DATE_FORMAT(appointment_date, '%Y-%m')";
    $bucket    = 'month';
}

$stmt = db()->prepare("
    SELECT $groupExpr AS period, MIN(appointment_date) AS period_start, COUNT(*) AS cnt,
           SUM(CASE WHEN status = 'completed' THEN total_price ELSE 0 END) AS rev
    FROM appointments
    WHERE business_id = ? AND appointment_date BETWEEN ? AND ? AND status != 'cancelled'
    GROUP BY period
    ORDER BY period_start ASC
");
$stmt->execute([$businessId, $startDate, $endDate]);
$timeSeries = $stmt->fetchAll();

foreach ($timeSeries as &$row) {
    $row['cnt'] = (int)$row['cnt'];
    $row['rev'] = (float)$row['rev'];
    if ($bucket === 'day') {
        $row['label'] = date('D j', strtotime($row['period_start']));
    } elseif ($bucket === 'week') {
        $row['label'] = 'Wk of ' . date('M j', strtotime($row['period_start']));
    } else {
        $row['label'] = date('M Y', strtotime($row['period_start']));
    }
}
unset($row);

$maxRev = !empty($timeSeries) ? max(array_column($timeSeries, 'rev')) : 0;

// ─── Status breakdown ─────────────────────────────────────────────────────────
$STATUS_META = [
    'pending'     => ['label' => 'Pending',     'color' => '#f59e0b'],
    'confirmed'   => ['label' => 'Confirmed',   'color' => '#10b981'],
    'in_progress' => ['label' => 'In Progress', 'color' => '#3b82f6'],
    'completed'   => ['label' => 'Completed',   'color' => '#6366f1'],
    'cancelled'   => ['label' => 'Cancelled',   'color' => '#ef4444'],
    'no_show'     => ['label' => 'No Show',     'color' => '#9ca3af'],
];

$stmt = db()->prepare("SELECT status, COUNT(*) AS cnt FROM appointments WHERE business_id = ? AND appointment_date BETWEEN ? AND ? GROUP BY status");
$stmt->execute([$businessId, $startDate, $endDate]);
$statusCounts = [];
foreach ($stmt->fetchAll() as $r) $statusCounts[$r['status']] = (int)$r['cnt'];
$statusTotal = array_sum($statusCounts);

// ─── Booking source breakdown ─────────────────────────────────────────────────
$SOURCE_META = [
    'whatsapp' => ['label' => '💬 WhatsApp Bot',     'color' => '#25D366'],
    'admin'    => ['label' => '🖥️ Manual (Dashboard)', 'color' => '#6366f1'],
    'website'  => ['label' => '🌐 Website',          'color' => '#3b82f6'],
];

$stmt = db()->prepare("SELECT booking_source, COUNT(*) AS cnt FROM appointments WHERE business_id = ? AND appointment_date BETWEEN ? AND ? GROUP BY booking_source");
$stmt->execute([$businessId, $startDate, $endDate]);
$sourceCounts = [];
foreach ($stmt->fetchAll() as $r) {
    $key = $r['booking_source'] ?: 'admin';
    $sourceCounts[$key] = ($sourceCounts[$key] ?? 0) + (int)$r['cnt'];
}
$sourceTotal = array_sum($sourceCounts);

// ─── Top services ──────────────────────────────────────────────────────────────
$stmt = db()->prepare("
    SELECT s.name AS name, COUNT(*) AS bookings, SUM(CASE WHEN a.status = 'completed' THEN a.total_price ELSE 0 END) AS revenue
    FROM appointments a
    LEFT JOIN services s ON s.id = a.service_id
    WHERE a.business_id = ? AND a.appointment_date BETWEEN ? AND ? AND a.status != 'cancelled'
    GROUP BY a.service_id
    ORDER BY bookings DESC
    LIMIT 6
");
$stmt->execute([$businessId, $startDate, $endDate]);
$topServices = $stmt->fetchAll();
$maxServiceBookings = !empty($topServices) ? max(array_column($topServices, 'bookings')) : 0;

// ─── Top staff ──────────────────────────────────────────────────────────────────
$stmt = db()->prepare("
    SELECT st.name AS name, COUNT(*) AS bookings, SUM(CASE WHEN a.status = 'completed' THEN a.total_price ELSE 0 END) AS revenue
    FROM appointments a
    LEFT JOIN staff st ON st.id = a.staff_id
    WHERE a.business_id = ? AND a.appointment_date BETWEEN ? AND ? AND a.status != 'cancelled' AND a.staff_id IS NOT NULL
    GROUP BY a.staff_id
    ORDER BY bookings DESC
    LIMIT 6
");
$stmt->execute([$businessId, $startDate, $endDate]);
$topStaff = $stmt->fetchAll();
$maxStaffBookings = !empty($topStaff) ? max(array_column($topStaff, 'bookings')) : 0;

// ─── Peak booking hours ───────────────────────────────────────────────────────
$stmt = db()->prepare("
    SELECT HOUR(appointment_time) AS hr, COUNT(*) AS cnt
    FROM appointments
    WHERE business_id = ? AND appointment_date BETWEEN ? AND ? AND status != 'cancelled'
    GROUP BY hr
    ORDER BY hr ASC
");
$stmt->execute([$businessId, $startDate, $endDate]);
$peakHours = $stmt->fetchAll();
$maxHourCnt = !empty($peakHours) ? max(array_column($peakHours, 'cnt')) : 0;

// ─── WhatsApp engagement ────────────────────────────────────────────────────────
$stmt = db()->prepare("SELECT direction, COUNT(*) AS cnt FROM whatsapp_messages WHERE business_id = ? AND created_at BETWEEN ? AND ? GROUP BY direction");
$stmt->execute([$businessId, $startDate . ' 00:00:00', $endDate . ' 23:59:59']);
$waCounts = ['inbound' => 0, 'outbound' => 0];
foreach ($stmt->fetchAll() as $r) $waCounts[$r['direction']] = (int)$r['cnt'];

$stmt = db()->prepare("SELECT COUNT(*) FROM appointments WHERE business_id = ? AND booking_source = 'whatsapp' AND appointment_date BETWEEN ? AND ?");
$stmt->execute([$businessId, $startDate, $endDate]);
$waBookings = (int)$stmt->fetchColumn();

// ─── Repeat customer rate (all-time) ───────────────────────────────────────────
$stmt = db()->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN total_visits >= 2 THEN 1 ELSE 0 END) AS repeat_count FROM customers WHERE business_id = ?");
$stmt->execute([$businessId]);
$custStats  = $stmt->fetch();
$repeatRate = ((int)$custStats['total']) > 0 ? round((int)$custStats['repeat_count'] / (int)$custStats['total'] * 100, 1) : 0;

$activeNav = 'analytics';
$pageTitle = 'Analytics';
include __DIR__ . '/partials/head.php';
?>

<div class="filter-bar">
  <?php foreach ($RANGES as $key => $label): ?>
    <a href="?range=<?= $key ?>" class="btn btn-sm <?= $range === $key ? 'btn-primary' : 'btn-outline' ?>"><?= $label ?></a>
  <?php endforeach; ?>
  <span style="margin-left:auto;font-size:.8rem;color:var(--gray-400);">
    <?= formatDate($startDate) ?> – <?= formatDate($endDate) ?>
  </span>
</div>

<!-- KPI cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin-bottom:20px;">
  <div class="stat-card">
    <div class="stat-card-icon indigo">💰</div>
    <div>
      <div class="stat-card-num"><?= formatPrice($revenue, $currency) ?></div>
      <div class="stat-card-label">Revenue (Completed)</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-card-icon blue">📅</div>
    <div>
      <div class="stat-card-num"><?= number_format($totalAppts) ?></div>
      <div class="stat-card-label">Total Appointments</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-card-icon green">👤</div>
    <div>
      <div class="stat-card-num"><?= number_format($newCustomers) ?></div>
      <div class="stat-card-label">New Customers</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-card-icon purple">📊</div>
    <div>
      <div class="stat-card-num"><?= formatPrice($avgValue, $currency) ?></div>
      <div class="stat-card-label">Avg. Booking Value</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-card-icon orange">❌</div>
    <div>
      <div class="stat-card-num"><?= $cancellationRate ?>%</div>
      <div class="stat-card-label">Cancellation Rate</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-card-icon green">🔁</div>
    <div>
      <div class="stat-card-num"><?= $repeatRate ?>%</div>
      <div class="stat-card-label">Repeat Customers</div>
    </div>
  </div>
</div>

<!-- Revenue over time -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-header">
    <span style="font-size:1rem;font-weight:700;color:var(--gray-900);">Revenue Over Time</span>
  </div>
  <div class="card-body">
    <?php if (empty($timeSeries)): ?>
      <div class="empty-state">
        <div class="empty-state-emoji">📈</div>
        <div class="empty-state-title">No data for this period</div>
        <div class="empty-state-desc">Completed appointments will appear here once you have bookings in this date range.</div>
      </div>
    <?php else: ?>
      <div class="chart-cols">
        <?php foreach ($timeSeries as $row): ?>
          <div class="chart-col">
            <div class="chart-col-bar" style="height:<?= $maxRev > 0 ? max(round($row['rev'] / $maxRev * 100), 2) : 2 ?>%;">
              <span class="chart-col-amt"><?= $row['rev'] > 0 ? formatPrice($row['rev'], $currency) : '' ?></span>
            </div>
            <div class="chart-col-label"><?= htmlspecialchars($row['label']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="content-grid-2" style="grid-template-columns:1fr 1fr;margin-bottom:20px;">

  <!-- Status breakdown -->
  <div class="card">
    <div class="card-header">
      <span style="font-size:1rem;font-weight:700;color:var(--gray-900);">Appointments by Status</span>
    </div>
    <div class="card-body">
      <?php if ($statusTotal === 0): ?>
        <div class="empty-state" style="padding:20px;">
          <div class="empty-state-desc">No appointments in this period.</div>
        </div>
      <?php else: ?>
        <?php foreach ($STATUS_META as $key => $meta):
          $cnt = $statusCounts[$key] ?? 0;
          if ($cnt === 0) continue;
          $pct = round($cnt / $statusTotal * 100);
        ?>
          <div class="bar-row">
            <div class="bar-label"><?= htmlspecialchars($meta['label']) ?></div>
            <div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $meta['color'] ?>;"></div></div>
            <div class="bar-value"><?= $cnt ?> (<?= $pct ?>%)</div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Booking sources -->
  <div class="card">
    <div class="card-header">
      <span style="font-size:1rem;font-weight:700;color:var(--gray-900);">Booking Sources</span>
    </div>
    <div class="card-body">
      <?php if ($sourceTotal === 0): ?>
        <div class="empty-state" style="padding:20px;">
          <div class="empty-state-desc">No appointments in this period.</div>
        </div>
      <?php else: ?>
        <?php foreach ($SOURCE_META as $key => $meta):
          $cnt = $sourceCounts[$key] ?? 0;
          if ($cnt === 0) continue;
          $pct = round($cnt / $sourceTotal * 100);
        ?>
          <div class="bar-row">
            <div class="bar-label"><?= $meta['label'] ?></div>
            <div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $meta['color'] ?>;"></div></div>
            <div class="bar-value"><?= $cnt ?> (<?= $pct ?>%)</div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

</div>

<div class="content-grid-2" style="grid-template-columns:1fr 1fr;margin-bottom:20px;">

  <!-- Top services -->
  <div class="card">
    <div class="card-header">
      <span style="font-size:1rem;font-weight:700;color:var(--gray-900);">Top Services</span>
    </div>
    <div class="card-body">
      <?php if (empty($topServices)): ?>
        <div class="empty-state" style="padding:20px;">
          <div class="empty-state-desc">No bookings in this period.</div>
        </div>
      <?php else: ?>
        <?php foreach ($topServices as $svc):
          $pct = $maxServiceBookings > 0 ? round($svc['bookings'] / $maxServiceBookings * 100) : 0;
        ?>
          <div class="bar-row">
            <div class="bar-label"><?= htmlspecialchars($svc['name'] ?: 'Unknown') ?></div>
            <div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%;"></div></div>
            <div class="bar-value"><?= (int)$svc['bookings'] ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Top staff -->
  <div class="card">
    <div class="card-header">
      <span style="font-size:1rem;font-weight:700;color:var(--gray-900);">Top Staff</span>
    </div>
    <div class="card-body">
      <?php if (empty($topStaff)): ?>
        <div class="empty-state" style="padding:20px;">
          <div class="empty-state-desc">No staff-assigned bookings in this period.</div>
        </div>
      <?php else: ?>
        <?php foreach ($topStaff as $st):
          $pct = $maxStaffBookings > 0 ? round($st['bookings'] / $maxStaffBookings * 100) : 0;
        ?>
          <div class="bar-row">
            <div class="bar-label"><?= htmlspecialchars($st['name'] ?: 'Unknown') ?></div>
            <div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%;"></div></div>
            <div class="bar-value"><?= (int)$st['bookings'] ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

</div>

<div class="content-grid-2" style="grid-template-columns:1fr 320px;">

  <!-- Peak hours -->
  <div class="card">
    <div class="card-header">
      <span style="font-size:1rem;font-weight:700;color:var(--gray-900);">Peak Booking Hours</span>
    </div>
    <div class="card-body">
      <?php if (empty($peakHours)): ?>
        <div class="empty-state" style="padding:20px;">
          <div class="empty-state-desc">No bookings in this period.</div>
        </div>
      <?php else: ?>
        <div class="chart-cols">
          <?php foreach ($peakHours as $row): ?>
            <div class="chart-col">
              <div class="chart-col-bar" style="height:<?= $maxHourCnt > 0 ? max(round($row['cnt'] / $maxHourCnt * 100), 2) : 2 ?>%;">
                <span class="chart-col-amt"><?= (int)$row['cnt'] ?></span>
              </div>
              <div class="chart-col-label"><?= date('g A', mktime((int)$row['hr'], 0, 0)) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- WhatsApp engagement -->
  <div class="card">
    <div class="card-header">
      <span style="font-size:1rem;font-weight:700;color:var(--gray-900);">WhatsApp Engagement</span>
    </div>
    <div class="card-body">
      <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--gray-100);">
        <span style="font-size:.85rem;color:var(--gray-600);">📥 Messages Received</span>
        <strong><?= number_format($waCounts['inbound']) ?></strong>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--gray-100);">
        <span style="font-size:.85rem;color:var(--gray-600);">📤 Messages Sent</span>
        <strong><?= number_format($waCounts['outbound']) ?></strong>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;">
        <span style="font-size:.85rem;color:var(--gray-600);">📅 Bookings via Bot</span>
        <strong><?= number_format($waBookings) ?></strong>
      </div>
    </div>
  </div>

</div>

<?php include __DIR__ . '/partials/foot.php'; ?>
