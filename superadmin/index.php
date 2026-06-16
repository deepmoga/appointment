<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireSuperAdmin();

$admin = getCurrentSuperAdmin();
$stats = getPlatformStats();
$businesses = getAllBusinesses();
$recentBusinesses = array_slice($businesses, 0, 8);

$activeNav = 'dashboard';
$pageTitle = '🛠️ Platform Dashboard';
include __DIR__ . '/partials/head.php';
?>

<!-- Stats row -->
<div class="stats-row">
  <div class="stat-card">
    <div class="stat-card-icon indigo">🏢</div>
    <div>
      <div class="stat-card-num"><?= number_format($stats['total_businesses']) ?></div>
      <div class="stat-card-label">Total Businesses</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-card-icon green">✅</div>
    <div>
      <div class="stat-card-num"><?= number_format($stats['active_businesses']) ?></div>
      <div class="stat-card-label">Active Businesses</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-card-icon blue">📱</div>
    <div>
      <div class="stat-card-num"><?= number_format($stats['connected_whatsapp']) ?></div>
      <div class="stat-card-label">WhatsApp Connected</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-card-icon orange">📅</div>
    <div>
      <div class="stat-card-num"><?= number_format($stats['today_appointments']) ?></div>
      <div class="stat-card-label">Today's Appointments</div>
    </div>
  </div>
</div>

<div class="stats-row">
  <div class="stat-card">
    <div class="stat-card-icon green">📋</div>
    <div>
      <div class="stat-card-num"><?= number_format($stats['total_appointments']) ?></div>
      <div class="stat-card-label">Total Appointments</div>
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
      <div class="stat-card-num"><?= formatPrice($stats['total_revenue']) ?></div>
      <div class="stat-card-label">Revenue (Completed)</div>
    </div>
  </div>
</div>

<!-- Recent businesses -->
<div class="card">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
    <span style="font-size:1rem;font-weight:700;color:var(--gray-900);">Recent Businesses</span>
    <a href="businesses.php" class="btn btn-sm btn-outline">View All</a>
  </div>
  <?php if (empty($recentBusinesses)): ?>
    <div class="empty-state">
      <div class="empty-state-emoji">🏢</div>
      <div class="empty-state-title">No businesses yet</div>
      <div class="empty-state-desc">New tenant signups will appear here.</div>
    </div>
  <?php else: ?>
    <div style="overflow-x:auto;">
      <table class="data-table">
        <thead>
          <tr>
            <th>Business</th>
            <th>Email</th>
            <th>Plan</th>
            <th>WhatsApp</th>
            <th>Status</th>
            <th>Joined</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentBusinesses as $b): ?>
          <tr>
            <td>
              <div style="font-weight:600;color:var(--gray-900);"><?= htmlspecialchars($b['name']) ?></div>
              <div style="font-size:.78rem;color:var(--gray-400);"><?= htmlspecialchars(ucfirst($b['business_type'])) ?></div>
            </td>
            <td><?= htmlspecialchars($b['email']) ?></td>
            <td><span class="badge"><?= htmlspecialchars(ucfirst($b['subscription_plan'])) ?></span></td>
            <td>
              <?php if (!empty($b['is_connected'])): ?>
                <span class="badge badge-confirmed">Connected</span>
              <?php else: ?>
                <span class="badge badge-pending">Not Connected</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($b['is_active']): ?>
                <span class="badge badge-completed">Active</span>
              <?php else: ?>
                <span class="badge badge-cancelled">Suspended</span>
              <?php endif; ?>
            </td>
            <td><?= formatDate($b['created_at']) ?></td>
            <td>
              <a href="businesses.php?id=<?= $b['id'] ?>" class="btn btn-sm btn-outline">Manage</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/partials/foot.php'; ?>
