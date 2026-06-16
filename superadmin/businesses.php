<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireSuperAdmin();

$admin  = getCurrentSuperAdmin();
$search = trim($_GET['q'] ?? '');
$viewId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$BUSINESS_TYPES = ['clinic','hospital','salon','spa','gym','restaurant','dental','legal','beauty','other'];
$PLANS          = ['free','starter','pro','enterprise'];

// ─── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        setFlash('error', 'Invalid request. Please try again.');
        header('Location: businesses.php' . ($viewId ? "?id=$viewId" : '')); exit;
    }

    $action     = post('action');
    $businessId = (int)post('business_id');

    if ($action === 'update_business') {
        $name    = trim(post('name'));
        $email   = trim(post('email'));
        $type    = post('business_type');
        $plan    = post('subscription_plan');
        $currency = trim(post('currency')) ?: 'USD';
        $isActive = post('is_active') === '1' ? 1 : 0;

        if (!in_array($type, $BUSINESS_TYPES, true)) $type = 'other';
        if (!in_array($plan, $PLANS, true)) $plan = 'free';

        if ($name === '' || $email === '') {
            setFlash('error', 'Name and email are required.');
        } else {
            db()->prepare("UPDATE businesses SET name = ?, email = ?, business_type = ?, subscription_plan = ?, currency = ?, is_active = ? WHERE id = ?")
                ->execute([$name, $email, $type, $plan, $currency, $isActive, $businessId]);
            setFlash('success', 'Business details updated.');
        }
        header('Location: businesses.php?id=' . $businessId); exit;

    } elseif ($action === 'update_whatsapp') {
        $phoneNumberId = trim(post('phone_number_id'));
        $wabaId        = trim(post('waba_id'));
        $accessToken   = trim(post('access_token'));
        $verifyToken   = trim(post('webhook_verify_token'));
        $phoneNumber   = trim(post('phone_number'));
        $displayName   = trim(post('display_name'));
        $isConnected   = post('is_connected') === '1' ? 1 : 0;

        $stmt = db()->prepare("SELECT access_token, webhook_verify_token FROM whatsapp_configs WHERE business_id = ?");
        $stmt->execute([$businessId]);
        $existing = $stmt->fetch();

        // Keep existing access token if the field was left blank
        if ($accessToken === '' && $existing) {
            $accessToken = $existing['access_token'];
        }
        if ($verifyToken === '') {
            $verifyToken = $existing['webhook_verify_token'] ?? bin2hex(random_bytes(16));
        }

        $stmt = db()->prepare("
            INSERT INTO whatsapp_configs (business_id, phone_number_id, waba_id, access_token, webhook_verify_token, phone_number, display_name, is_connected)
            VALUES (?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                phone_number_id = VALUES(phone_number_id),
                waba_id = VALUES(waba_id),
                access_token = VALUES(access_token),
                webhook_verify_token = VALUES(webhook_verify_token),
                phone_number = VALUES(phone_number),
                display_name = VALUES(display_name),
                is_connected = VALUES(is_connected)
        ");
        $stmt->execute([$businessId, $phoneNumberId, $wabaId, $accessToken, $verifyToken, $phoneNumber, $displayName, $isConnected]);

        setFlash('success', 'WhatsApp integration settings updated.');
        header('Location: businesses.php?id=' . $businessId); exit;

    } elseif ($action === 'generate_webhook_token') {
        $newToken = bin2hex(random_bytes(16));
        $stmt = db()->prepare("SELECT id FROM whatsapp_configs WHERE business_id = ?");
        $stmt->execute([$businessId]);

        if ($stmt->fetch()) {
            db()->prepare("UPDATE whatsapp_configs SET webhook_verify_token = ? WHERE business_id = ?")
                ->execute([$newToken, $businessId]);
        } else {
            db()->prepare("INSERT INTO whatsapp_configs (business_id, webhook_verify_token) VALUES (?, ?)")
                ->execute([$businessId, $newToken]);
        }

        setFlash('success', 'New webhook verify token generated.');
        header('Location: businesses.php?id=' . $businessId); exit;

    } elseif ($action === 'toggle_active') {
        db()->prepare("UPDATE businesses SET is_active = NOT is_active WHERE id = ?")->execute([$businessId]);
        setFlash('success', 'Business status updated.');
        header('Location: businesses.php' . ($viewId ? "?id=$viewId" : '')); exit;
    }
}

// ─── View data ──────────────────────────────────────────────────────────────────
$businesses = getAllBusinesses($search);
$viewBusiness = $viewId > 0 ? getBusinessById($viewId) : null;

$activeNav = 'businesses';
$pageTitle = $viewBusiness ? '🏢 ' . $viewBusiness['name'] : '🏢 Businesses';
include __DIR__ . '/partials/head.php';
?>

<?php if ($viewBusiness): ?>

  <a href="businesses.php" class="btn btn-sm btn-outline" style="margin-bottom:16px;display:inline-block;">← Back to all businesses</a>

  <div class="content-grid-2">

    <!-- Business details -->
    <div class="card">
      <div class="card-header">
        <span style="font-size:1rem;font-weight:700;color:var(--gray-900);">Business Details</span>
      </div>
      <div class="card-body">
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="update_business">
          <input type="hidden" name="business_id" value="<?= $viewBusiness['id'] ?>">

          <div class="form-group">
            <label class="form-label">Business Name</label>
            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($viewBusiness['name']) ?>" required>
          </div>

          <div class="form-group">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($viewBusiness['email']) ?>" required>
          </div>

          <div class="form-group">
            <label class="form-label">Business Type</label>
            <select name="business_type" class="form-select">
              <?php foreach ($BUSINESS_TYPES as $t): ?>
                <option value="<?= $t ?>" <?= $viewBusiness['business_type'] === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label">Subscription Plan</label>
            <select name="subscription_plan" class="form-select">
              <?php foreach ($PLANS as $p): ?>
                <option value="<?= $p ?>" <?= $viewBusiness['subscription_plan'] === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label">Currency</label>
            <input type="text" name="currency" class="form-control" value="<?= htmlspecialchars($viewBusiness['currency']) ?>" maxlength="10">
          </div>

          <div class="form-group">
            <label class="form-label">Account Status</label>
            <select name="is_active" class="form-select">
              <option value="1" <?= $viewBusiness['is_active'] ? 'selected' : '' ?>>Active</option>
              <option value="0" <?= !$viewBusiness['is_active'] ? 'selected' : '' ?>>Suspended</option>
            </select>
          </div>

          <button type="submit" class="btn btn-primary btn-full">Save Changes</button>
        </form>

        <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--gray-100);font-size:.82rem;color:var(--gray-500);line-height:1.7;">
          <div><strong>Joined:</strong> <?= formatDate($viewBusiness['created_at']) ?></div>
          <div><strong>Last Login:</strong> <?= $viewBusiness['last_login'] ? timeAgo($viewBusiness['last_login']) : 'Never' ?></div>
          <div><strong>Pricing Mode:</strong> <?= htmlspecialchars($viewBusiness['pricing_mode']) ?></div>
        </div>
      </div>
    </div>

    <!-- WhatsApp integration -->
    <div class="card">
      <div class="card-header">
        <span style="font-size:1rem;font-weight:700;color:var(--gray-900);">WhatsApp Integration</span>
      </div>
      <div class="card-body">
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="update_whatsapp">
          <input type="hidden" name="business_id" value="<?= $viewBusiness['id'] ?>">

          <div class="form-group">
            <label class="form-label">Phone Number ID</label>
            <input type="text" name="phone_number_id" class="form-control" value="<?= htmlspecialchars($viewBusiness['phone_number_id'] ?? '') ?>" placeholder="e.g. 109876543210123">
          </div>

          <div class="form-group">
            <label class="form-label">WABA ID (WhatsApp Business Account ID)</label>
            <input type="text" name="waba_id" class="form-control" value="<?= htmlspecialchars($viewBusiness['waba_id'] ?? '') ?>" placeholder="e.g. 102345678901234">
          </div>

          <div class="form-group">
            <label class="form-label">Access Token</label>
            <input type="password" name="access_token" class="form-control" placeholder="<?= !empty($viewBusiness['access_token']) ? '•••••••• (leave blank to keep current)' : 'Enter access token' ?>">
            <button type="button" class="btn btn-sm btn-outline toggle-password" data-target="access_token" style="margin-top:6px;" onclick="
              var f=this.previousElementSibling; f.type = f.type==='password' ? 'text' : 'password';">Show/Hide</button>
          </div>

          <div class="form-group">
            <label class="form-label">Webhook Verify Token</label>
            <div class="input-group">
              <input type="text" name="webhook_verify_token" class="form-control" value="<?= htmlspecialchars($viewBusiness['webhook_verify_token'] ?? '') ?>">
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Display Phone Number</label>
            <input type="text" name="phone_number" class="form-control" value="<?= htmlspecialchars($viewBusiness['phone_number'] ?? '') ?>" placeholder="e.g. +1 555 123 4567">
          </div>

          <div class="form-group">
            <label class="form-label">Verified Display Name</label>
            <input type="text" name="display_name" class="form-control" value="<?= htmlspecialchars($viewBusiness['display_name'] ?? '') ?>">
          </div>

          <div class="form-group">
            <label class="form-label">Connection Status</label>
            <select name="is_connected" class="form-select">
              <option value="1" <?= !empty($viewBusiness['is_connected']) ? 'selected' : '' ?>>Connected</option>
              <option value="0" <?= empty($viewBusiness['is_connected']) ? 'selected' : '' ?>>Not Connected</option>
            </select>
          </div>

          <button type="submit" class="btn btn-primary btn-full">Save WhatsApp Settings</button>
        </form>

        <form method="POST" style="margin-top:10px;">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="generate_webhook_token">
          <input type="hidden" name="business_id" value="<?= $viewBusiness['id'] ?>">
          <button type="submit" class="btn btn-outline btn-full btn-sm">🔄 Generate New Webhook Verify Token</button>
        </form>

        <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--gray-100);font-size:.8rem;color:var(--gray-500);line-height:1.7;">
          <div><strong>Webhook Callback URL:</strong></div>
          <code style="word-break:break-all;display:block;background:var(--gray-50);padding:8px;border-radius:6px;margin-top:4px;"><?= APP_URL ?>/api/webhook.php?business_id=<?= $viewBusiness['id'] ?></code>
        </div>
      </div>
    </div>

  </div>

<?php else: ?>

  <!-- Search -->
  <div class="card" style="margin-bottom:20px;">
    <div class="card-body">
      <form method="GET" style="display:flex;gap:10px;">
        <input type="text" name="q" class="form-control" placeholder="Search by name or email..." value="<?= htmlspecialchars($search) ?>" style="max-width:340px;">
        <button type="submit" class="btn btn-primary">Search</button>
        <?php if ($search !== ''): ?>
          <a href="businesses.php" class="btn btn-outline">Clear</a>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <!-- Businesses table -->
  <div class="card">
    <div class="card-header">
      <span style="font-size:1rem;font-weight:700;color:var(--gray-900);">All Businesses (<?= count($businesses) ?>)</span>
    </div>
    <?php if (empty($businesses)): ?>
      <div class="empty-state">
        <div class="empty-state-emoji">🏢</div>
        <div class="empty-state-title">No businesses found</div>
        <div class="empty-state-desc">Try a different search term.</div>
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
            <?php foreach ($businesses as $b): ?>
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
              <td style="display:flex;gap:6px;">
                <a href="businesses.php?id=<?= $b['id'] ?>" class="btn btn-sm btn-outline">Manage</a>
                <form method="POST" onsubmit="return confirm('<?= $b['is_active'] ? 'Suspend' : 'Reactivate' ?> this business?');">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="toggle_active">
                  <input type="hidden" name="business_id" value="<?= $b['id'] ?>">
                  <button type="submit" class="btn btn-sm <?= $b['is_active'] ? 'btn-outline' : 'btn-primary' ?>"><?= $b['is_active'] ? 'Suspend' : 'Activate' ?></button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<?php endif; ?>

<?php include __DIR__ . '/partials/foot.php'; ?>
