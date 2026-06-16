<?php
/**
 * Shared super-admin <head> + sidebar + topbar opening.
 * Expects (set by including page):
 *   $admin      — array from getCurrentSuperAdmin()
 *   $activeNav  — string key matching one of the nav item keys below
 *   $pageTitle  — string for <title> and topbar heading
 */

$initials = strtoupper(substr($admin['name'], 0, 2));

$navGroups = [
    'Platform' => [
        ['key' => 'dashboard',  'icon' => '📊', 'label' => 'Dashboard',          'href' => 'index.php'],
        ['key' => 'businesses', 'icon' => '🏢', 'label' => 'Businesses',          'href' => 'businesses.php'],
        ['key' => 'plans',      'icon' => '💳', 'label' => 'Subscription Plans',  'href' => 'plans.php'],
        ['key' => 'pricing',    'icon' => '💰', 'label' => 'Pricing & Wallet',    'href' => 'pricing.php'],
    ],
    'Account' => [
        ['key' => 'settings', 'icon' => '⚙️', 'label' => 'Settings', 'href' => 'settings.php'],
    ],
];
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle) ?> — BookWA Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body>

<div class="dash-layout">

  <!-- ── Sidebar ─────────────────────────────────────────── -->
  <aside class="sidebar" id="sidebar">

    <div class="sidebar-header">
      <a href="index.php" class="sidebar-logo">
        <span class="sidebar-logo-icon">🛠️</span>
        BookWA Admin
      </a>
    </div>

    <nav class="sidebar-nav">
      <?php foreach ($navGroups as $groupName => $items): ?>
        <div class="nav-section-title"><?= htmlspecialchars($groupName) ?></div>
        <?php foreach ($items as $item): ?>
          <a class="nav-item <?= $activeNav === $item['key'] ? 'active' : '' ?>" href="<?= htmlspecialchars($item['href']) ?>">
            <span class="nav-item-icon"><?= $item['icon'] ?></span>
            <?= htmlspecialchars($item['label']) ?>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
      <a class="sidebar-user" href="settings.php" style="text-decoration:none;">
        <div class="sidebar-user-av"><?= htmlspecialchars($initials) ?></div>
        <div>
          <div class="sidebar-user-name"><?= htmlspecialchars($admin['name']) ?></div>
          <div class="sidebar-user-plan">Super Admin</div>
        </div>
      </a>
      <a class="nav-item" href="logout.php" style="margin-top:4px;">
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
