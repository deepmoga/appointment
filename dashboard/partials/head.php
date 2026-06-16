<?php
/**
 * Shared dashboard <head> + sidebar + topbar opening.
 * Expects (set by including page):
 *   $business   — array from getCurrentBusiness()
 *   $activeNav  — string key matching one of the nav item keys below
 *   $pageTitle  — string for <title> and topbar heading
 */

$businessId  = (int)$_SESSION['business_id'];
$badgeCounts = getSidebarBadgeCounts($businessId);
$initials    = strtoupper(substr($business['name'], 0, 2));

$navGroups = [
    'Overview' => [
        ['key' => 'dashboard',    'icon' => '📊', 'label' => 'Dashboard',     'href' => 'index.php'],
        ['key' => 'appointments', 'icon' => '📅', 'label' => 'Appointments',  'href' => 'appointments.php', 'badge' => $badgeCounts['pending']],
        ['key' => 'customers',    'icon' => '👤', 'label' => 'Customers',     'href' => 'customers.php'],
    ],
    'Management' => [
        ['key' => 'categories', 'icon' => '🗂️', 'label' => 'Categories',     'href' => 'categories.php'],
        ['key' => 'services',   'icon' => '🛍️', 'label' => 'Services',       'href' => 'services.php'],
        ['key' => 'staff',      'icon' => '👨‍⚕️', 'label' => 'Doctors',        'href' => 'staff.php'],
        ['key' => 'hours',      'icon' => '⏰', 'label' => 'Business Hours', 'href' => 'hours.php'],
    ],
    'Engagement' => [
        ['key' => 'whatsapp',  'icon' => '📱', 'label' => 'WhatsApp Setup',     'href' => 'whatsapp.php'],
        ['key' => 'templates', 'icon' => '💬', 'label' => 'Message Templates',  'href' => 'templates.php'],
    ],
    'Insights' => [
        ['key' => 'analytics', 'icon' => '📈', 'label' => 'Analytics', 'href' => 'analytics.php'],
    ],
    'Billing' => [
        ['key' => 'wallet',       'icon' => '💰', 'label' => 'Wallet',       'href' => 'wallet.php', 'badge_warn' => $badgeCounts['low_wallet']],
        ['key' => 'subscription', 'icon' => '💳', 'label' => 'Subscription', 'href' => 'settings.php#subscription'],
    ],
    'Settings' => [
        ['key' => 'settings', 'icon' => '⚙️', 'label' => 'Settings', 'href' => 'settings.php'],
    ],
];
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle) ?> — BookWA</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body>

<div class="dash-layout">

  <!-- ── Sidebar ─────────────────────────────────────────── -->
  <aside class="sidebar" id="sidebar">

    <div class="sidebar-header">
      <a href="<?= APP_URL ?>/index.php" class="sidebar-logo">
        <span class="sidebar-logo-icon">💬</span>
        BookWA
      </a>
    </div>

    <nav class="sidebar-nav">
      <?php foreach ($navGroups as $groupName => $items): ?>
        <div class="nav-section-title"><?= htmlspecialchars($groupName) ?></div>
        <?php foreach ($items as $item): ?>
          <a class="nav-item <?= $activeNav === $item['key'] ? 'active' : '' ?>" href="<?= htmlspecialchars($item['href']) ?>">
            <span class="nav-item-icon"><?= $item['icon'] ?></span>
            <?= htmlspecialchars($item['label']) ?>
            <?php if (!empty($item['badge'])): ?>
              <span class="nav-item-badge"><?= (int)$item['badge'] ?></span>
            <?php elseif (!empty($item['badge_warn'])): ?>
              <span class="nav-item-badge" style="background:var(--warning,#f59e0b);">!</span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>

      <div class="nav-section-title">Coming Soon</div>
      <span class="nav-item disabled">
        <span class="nav-item-icon">📋</span> Waitlist
        <span class="nav-item-soon">Soon</span>
      </span>
      <span class="nav-item disabled">
        <span class="nav-item-icon">⭐</span> Reviews
        <span class="nav-item-soon">Soon</span>
      </span>
      <span class="nav-item disabled">
        <span class="nav-item-icon">📅</span> Google Calendar
        <span class="nav-item-soon">Soon</span>
      </span>
    </nav>

    <div class="sidebar-footer">
      <a class="sidebar-user" href="settings.php" style="text-decoration:none;">
        <div class="sidebar-user-av"><?= htmlspecialchars($initials) ?></div>
        <div>
          <div class="sidebar-user-name"><?= htmlspecialchars($business['name']) ?></div>
          <div class="sidebar-user-plan"><?= ucfirst($business['subscription_plan'] ?? 'free') ?> Plan</div>
        </div>
      </a>
      <a class="nav-item" href="<?= APP_URL ?>/auth/logout.php" style="margin-top:4px;">
        <span class="nav-item-icon">🚪</span> Sign Out
      </a>
    </div>

  </aside>

  <!-- ── Main ────────────────────────────────────────────── -->
  <main class="dash-main">

    <!-- Topbar -->
    <div class="dash-topbar">
      <button id="sidebarToggle" class="nav-mobile-toggle" aria-label="Menu">
        <span></span><span></span><span></span>
      </button>
      <div class="dash-topbar-title"><?= htmlspecialchars($pageTitle) ?></div>
      <div class="dash-topbar-actions">
        <?php $flash = getFlash(); if ($flash): ?>
          <div class="alert alert-<?= $flash['type'] ?>" style="margin:0;padding:8px 14px;">
            <?= htmlspecialchars($flash['message']) ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="dash-content">
