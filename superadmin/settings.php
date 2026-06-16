<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireSuperAdmin();

$adminId = (int)$_SESSION['super_admin_id'];

// ─── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        setFlash('error', 'Invalid request. Please try again.');
        header('Location: settings.php'); exit;
    }

    $action = post('action');

    if ($action === 'update_profile') {
        $name  = trim(post('name'));
        $email = trim(post('email'));

        if ($name === '' || $email === '') {
            setFlash('error', 'Name and email are required.');
        } else {
            $stmt = db()->prepare("SELECT id FROM super_admins WHERE email = ? AND id != ?");
            $stmt->execute([$email, $adminId]);
            if ($stmt->fetch()) {
                setFlash('error', 'That email is already in use by another admin.');
            } else {
                db()->prepare("UPDATE super_admins SET name = ?, email = ? WHERE id = ?")
                    ->execute([$name, $email, $adminId]);
                $_SESSION['super_admin_name']  = $name;
                $_SESSION['super_admin_email'] = $email;
                setFlash('success', 'Profile updated successfully.');
            }
        }
        header('Location: settings.php#profile'); exit;

    } elseif ($action === 'change_password') {
        $current = post('current_password');
        $new     = post('new_password');
        $confirm = post('confirm_password');

        $stmt = db()->prepare("SELECT password FROM super_admins WHERE id = ?");
        $stmt->execute([$adminId]);
        $hash = (string)$stmt->fetchColumn();

        if (!password_verify($current, $hash)) {
            setFlash('error', 'Current password is incorrect.');
        } elseif (strlen($new) < 8) {
            setFlash('error', 'New password must be at least 8 characters long.');
        } elseif ($new !== $confirm) {
            setFlash('error', 'New password and confirmation do not match.');
        } else {
            db()->prepare("UPDATE super_admins SET password = ? WHERE id = ?")
                ->execute([password_hash($new, PASSWORD_DEFAULT), $adminId]);
            setFlash('success', 'Password changed successfully.');
        }
        header('Location: settings.php#security'); exit;

    } elseif ($action === 'update_platform') {
        $currencySymbol = trim(post('currency_symbol')) ?: '$';
        $contactPhone   = trim(post('contact_phone'));
        $contactEmail   = trim(post('contact_email'));
        $demoWhatsapp   = trim(post('demo_whatsapp'));

        updatePlatformSettings([
            'currency_symbol' => $currencySymbol,
            'contact_phone'   => $contactPhone,
            'contact_email'   => $contactEmail,
            'demo_whatsapp'   => $demoWhatsapp,
        ]);
        setFlash('success', 'Platform settings updated.');
        header('Location: settings.php#platform'); exit;

    } elseif ($action === 'generate_webhook_token') {
        $newToken = bin2hex(random_bytes(20));
        $ps = getPlatformSettings();
        $ps['wa_verify_token'] = $newToken;
        updatePlatformSettings($ps);
        setFlash('success', 'New webhook verify token generated. Update it in your Meta App webhook configuration.');
        header('Location: settings.php#platform'); exit;
    }

    header('Location: settings.php');
    exit;
}

$admin    = getCurrentSuperAdmin();
$platform = getPlatformSettings();

$activeNav = 'settings';
$pageTitle = '⚙️ Settings';
include __DIR__ . '/partials/head.php';
?>

<div class="tabs" data-panel-group="#settingsPanels">
  <div class="tab active" data-tab="profile">👤 Profile</div>
  <div class="tab" data-tab="security">🔒 Security</div>
  <div class="tab" data-tab="platform">🌐 Platform</div>
</div>

<div id="settingsPanels">

  <!-- ── Profile ───────────────────────────────────── -->
  <div class="tab-panel active" data-panel="profile">
    <div class="card">
      <div class="card-body">
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="update_profile">

          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Full Name *</label>
              <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($admin['name']) ?>" required>
            </div>
            <div class="form-group">
              <label class="form-label">Email Address *</label>
              <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($admin['email']) ?>" required>
            </div>
          </div>

          <div style="font-size:.82rem;color:var(--gray-500);margin-bottom:16px;">
            <strong>Last Login:</strong> <?= $admin['last_login'] ? timeAgo($admin['last_login']) : 'Never' ?><br>
            <strong>Account Created:</strong> <?= formatDate($admin['created_at']) ?>
          </div>

          <button type="submit" class="btn btn-primary">Save Changes</button>
        </form>
      </div>
    </div>
  </div>

  <!-- ── Security ───────────────────────────────────── -->
  <div class="tab-panel" data-panel="security">
    <div class="card">
      <div class="card-header">
        <span style="font-size:1rem;font-weight:700;color:var(--gray-900);">Change Password</span>
      </div>
      <div class="card-body">
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="change_password">

          <div class="form-group">
            <label class="form-label">Current Password *</label>
            <input type="password" name="current_password" class="form-control" required>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label">New Password *</label>
              <input type="password" name="new_password" class="form-control" minlength="8" required>
              <div class="form-hint">At least 8 characters.</div>
            </div>
            <div class="form-group">
              <label class="form-label">Confirm New Password *</label>
              <input type="password" name="confirm_password" class="form-control" minlength="8" required>
            </div>
          </div>

          <button type="submit" class="btn btn-primary">Change Password</button>
        </form>
      </div>
    </div>
  </div>

  <!-- ── Platform ───────────────────────────────────── -->
  <div class="tab-panel" data-panel="platform">
    <div class="card">
      <div class="card-header">
        <span style="font-size:1rem;font-weight:700;color:var(--gray-900);">Front Page &amp; Currency</span>
      </div>
      <div class="card-body">
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="update_platform">

          <div class="form-group">
            <label class="form-label">Currency Symbol</label>
            <input type="text" name="currency_symbol" class="form-control" value="<?= htmlspecialchars($platform['currency_symbol']) ?>" maxlength="5" style="max-width:120px;" required>
            <div class="form-hint">Shown before plan prices on the homepage pricing section, e.g. $ or ₹.</div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Contact Phone</label>
              <input type="text" name="contact_phone" class="form-control" value="<?= htmlspecialchars($platform['contact_phone']) ?>" placeholder="+91 97805-51900">
            </div>
            <div class="form-group">
              <label class="form-label">Contact Email</label>
              <input type="email" name="contact_email" class="form-control" value="<?= htmlspecialchars($platform['contact_email']) ?>" placeholder="you@example.com">
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Demo WhatsApp Number</label>
            <input type="text" name="demo_whatsapp" class="form-control" value="<?= htmlspecialchars($platform['demo_whatsapp']) ?>" placeholder="+91 70096 21194">
            <div class="form-hint">Shown in the homepage "Try the Live Demo" section, with a scannable WhatsApp QR code.</div>
          </div>

          <button type="submit" class="btn btn-primary">Save Platform Settings</button>
        </form>

        <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--gray-100);font-size:.82rem;color:var(--gray-500);">
          Homepage pricing cards are pulled live from <a href="plans.php">Subscription Plans</a> — edit prices, limits, and features there.
        </div>
      </div>

      <div style="margin-top:20px;border-top:1px solid var(--gray-100);padding-top:20px;">
        <div style="font-weight:700;font-size:.95rem;color:var(--gray-900);margin-bottom:10px;">📡 Shared WhatsApp Webhook</div>
        <p style="font-size:.82rem;color:var(--gray-500);margin-bottom:14px;">
          All businesses use one webhook URL. Use the verify token below when registering the webhook in your Meta App.
        </p>
        <div class="form-group">
          <label class="form-label">Platform Webhook URL</label>
          <div class="input-group">
            <input type="text" class="form-control" id="platWebhookUrl" value="<?= htmlspecialchars(APP_URL . '/api/webhook.php') ?>" readonly style="font-size:.78rem;">
            <button type="button" class="input-group-icon" onclick="navigator.clipboard.writeText(document.getElementById('platWebhookUrl').value);this.textContent='✓';">📋</button>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Webhook Verify Token</label>
          <div class="input-group">
            <input type="text" class="form-control" id="platVerifyToken" value="<?= htmlspecialchars($platform['wa_verify_token'] ?? '') ?>" readonly style="font-size:.78rem;" placeholder="Not yet generated">
            <?php if (!empty($platform['wa_verify_token'])): ?>
            <button type="button" class="input-group-icon" onclick="navigator.clipboard.writeText(document.getElementById('platVerifyToken').value);this.textContent='✓';">📋</button>
            <?php endif; ?>
          </div>
          <form method="POST" style="margin-top:8px;" onsubmit="return confirm('Generate a new webhook verify token? You must update it in your Meta App.');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="generate_webhook_token">
            <button type="submit" class="btn btn-sm btn-outline">🔄 <?= empty($platform['wa_verify_token']) ? 'Generate Token' : 'Regenerate Token' ?></button>
          </form>
        </div>
      </div>
    </div>
  </div>

</div>

<?php include __DIR__ . '/partials/foot.php'; ?>
