<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/../includes/payment.php';

requireSuperAdmin();

$admin = getCurrentSuperAdmin();

// ─── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        setFlash('error', 'Invalid request.');
        header('Location: pricing.php'); exit;
    }

    $action = post('action');

    if ($action === 'update_rates') {
        $ratePlatform = (float)post('rate_platform_gateway');
        $rateOwn      = (float)post('rate_own_gateway');
        $minRecharge  = (float)post('min_recharge_amount');
        $lowAlert     = (float)post('low_balance_alert');

        if ($ratePlatform <= 0 || $rateOwn <= 0) {
            setFlash('error', 'Booking fee rates must be greater than 0.');
        } else {
            $platform = getPlatformSettings();
            updatePlatformSettings(array_merge($platform, [
                'rate_platform_gateway' => $ratePlatform,
                'rate_own_gateway'      => $rateOwn,
                'min_recharge_amount'   => $minRecharge,
                'low_balance_alert'     => $lowAlert,
            ]));
            setFlash('success', 'Booking fee rates updated.');
        }
        header('Location: pricing.php#rates'); exit;

    } elseif ($action === 'update_razorpay') {
        $keyId     = trim(post('razorpay_key_id'));
        $keySecret = trim(post('razorpay_key_secret'));

        $platform = getPlatformSettings();
        $update = array_merge($platform, ['razorpay_key_id' => $keyId]);
        if ($keySecret !== '') $update['razorpay_key_secret'] = $keySecret;
        updatePlatformSettings($update);
        setFlash('success', 'Razorpay credentials updated.');
        header('Location: pricing.php#razorpay'); exit;

    } elseif ($action === 'save_package') {
        $pkgId      = (int)post('pkg_id');
        $name       = trim(post('name'));
        $amount     = (float)post('amount');
        $credits    = (float)post('credits');
        $bonus      = (float)post('bonus');
        $desc       = trim(post('description'));
        $isPopular  = post('is_popular') === '1' ? 1 : 0;
        $isActive   = post('is_active') === '1' ? 1 : 0;
        $sortOrder  = (int)post('sort_order');

        if ($name === '' || $amount <= 0 || $credits <= 0) {
            setFlash('error', 'Package name, amount and credits are required.');
        } elseif ($pkgId > 0) {
            db()->prepare("UPDATE recharge_packages SET name=?,amount=?,credits=?,bonus=?,description=?,is_popular=?,is_active=?,sort_order=? WHERE id=?")
                ->execute([$name,$amount,$credits,$bonus,$desc,$isPopular,$isActive,$sortOrder,$pkgId]);
            setFlash('success', 'Package updated.');
        } else {
            db()->prepare("INSERT INTO recharge_packages (name,amount,credits,bonus,description,is_popular,is_active,sort_order) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$name,$amount,$credits,$bonus,$desc,$isPopular,$isActive,$sortOrder]);
            setFlash('success', 'Package created.');
        }
        header('Location: pricing.php#packages'); exit;

    } elseif ($action === 'delete_package') {
        $pkgId = (int)post('pkg_id');
        if ($pkgId > 0) {
            db()->prepare("DELETE FROM recharge_packages WHERE id = ?")->execute([$pkgId]);
            setFlash('success', 'Package deleted.');
        }
        header('Location: pricing.php#packages'); exit;

    } elseif ($action === 'manual_credit') {
        $targetBizId = (int)post('business_id');
        $amount      = (float)post('amount');
        $reason      = trim(post('reason')) ?: 'Manual credit by admin';

        if ($targetBizId <= 0 || $amount <= 0) {
            setFlash('error', 'Invalid business or amount.');
        } else {
            creditWallet($targetBizId, $amount, $reason, 'admin_manual');
            setFlash('success', "₹{$amount} credited to business #{$targetBizId}.");
        }
        header('Location: pricing.php#manual'); exit;
    }

    header('Location: pricing.php'); exit;
}

$platform = getPlatformSettings();
$packages = getRechargePackages(false);

// Business list for manual credit dropdown
$businesses = db()->query("SELECT id, name, wallet_balance, payment_mode FROM businesses WHERE is_active=1 ORDER BY name")->fetchAll();

$editPkg = null;
if (isset($_GET['edit_pkg'])) {
    $stmt = db()->prepare("SELECT * FROM recharge_packages WHERE id = ?");
    $stmt->execute([(int)$_GET['edit_pkg']]);
    $editPkg = $stmt->fetch() ?: null;
}

$activeNav = 'pricing';
$pageTitle = '💳 Pricing & Wallet';
include __DIR__ . '/partials/head.php';
?>

<div class="tabs" data-panel-group="#pricingPanels">
  <div class="tab active" data-tab="rates">💰 Booking Rates</div>
  <div class="tab" data-tab="razorpay">🔑 Razorpay</div>
  <div class="tab" data-tab="packages">📦 Recharge Packages</div>
  <div class="tab" data-tab="manual">🎁 Manual Credit</div>
</div>

<div id="pricingPanels">

  <!-- ── Booking Rates ──────────────────────────────────────── -->
  <div class="tab-panel active" data-panel="rates">
    <div class="card" style="max-width:560px;">
      <div class="card-header">
        <span style="font-size:1rem;font-weight:700;color:var(--gray-900);">Per-Booking Fee Rates</span>
      </div>
      <div class="card-body">
        <p style="font-size:.83rem;color:var(--gray-500);margin-bottom:20px;line-height:1.6;">
          These amounts are deducted from the client's wallet each time a booking is confirmed.
        </p>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="update_rates">

          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Platform Gateway Rate (₹)</label>
              <input type="number" name="rate_platform_gateway" class="form-control"
                     value="<?= $platform['rate_platform_gateway'] ?? 20 ?>" step="0.01" min="0.01" required>
              <div class="form-hint">Client uses your payment gateway</div>
            </div>
            <div class="form-group">
              <label class="form-label">Own Gateway Rate (₹)</label>
              <input type="number" name="rate_own_gateway" class="form-control"
                     value="<?= $platform['rate_own_gateway'] ?? 5 ?>" step="0.01" min="0.01" required>
              <div class="form-hint">Client has their own Razorpay account</div>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Minimum Recharge Amount (₹)</label>
              <input type="number" name="min_recharge_amount" class="form-control"
                     value="<?= $platform['min_recharge_amount'] ?? 100 ?>" step="1" min="1">
            </div>
            <div class="form-group">
              <label class="form-label">Low Balance Alert Threshold (₹)</label>
              <input type="number" name="low_balance_alert" class="form-control"
                     value="<?= $platform['low_balance_alert'] ?? 100 ?>" step="1" min="0">
              <div class="form-hint">WhatsApp alert sent below this amount</div>
            </div>
          </div>

          <button type="submit" class="btn btn-primary">Save Rates</button>
        </form>

        <div style="margin-top:24px;padding:16px;background:var(--gray-50);border-radius:10px;font-size:.83rem;color:var(--gray-600);">
          <strong>Platform Revenue Estimate:</strong><br>
          At ₹<?= $platform['rate_platform_gateway'] ?? 20 ?>/booking (platform mode) — 1,000 bookings/month = ₹<?= number_format(($platform['rate_platform_gateway'] ?? 20) * 1000) ?>/month<br>
          At ₹<?= $platform['rate_own_gateway'] ?? 5 ?>/booking (own gateway) — 1,000 bookings/month = ₹<?= number_format(($platform['rate_own_gateway'] ?? 5) * 1000) ?>/month
        </div>
      </div>
    </div>
  </div>

  <!-- ── Razorpay ───────────────────────────────────────────── -->
  <div class="tab-panel" data-panel="razorpay">
    <div class="card" style="max-width:560px;">
      <div class="card-header">
        <span style="font-size:1rem;font-weight:700;color:var(--gray-900);">🔑 Platform Razorpay Credentials</span>
      </div>
      <div class="card-body">
        <p style="font-size:.83rem;color:var(--gray-500);margin-bottom:16px;line-height:1.6;">
          Used to collect wallet recharges from your clients. Get these from your Razorpay Dashboard → Settings → API Keys.
        </p>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="update_razorpay">

          <div class="form-group">
            <label class="form-label">Razorpay Key ID</label>
            <input type="text" name="razorpay_key_id" class="form-control"
                   value="<?= htmlspecialchars($platform['razorpay_key_id'] ?? '') ?>"
                   placeholder="rzp_live_XXXXXXXXXXXXXXXX" required>
          </div>
          <div class="form-group">
            <label class="form-label">Razorpay Key Secret</label>
            <input type="password" name="razorpay_key_secret" class="form-control"
                   placeholder="<?= !empty($platform['razorpay_key_secret']) ? '••••••••••• (set)' : 'Enter key secret' ?>">
            <div class="form-hint">Leave blank to keep current secret.</div>
          </div>

          <button type="submit" class="btn btn-primary">Save Credentials</button>
        </form>

        <?php if (!empty($platform['razorpay_key_id'])): ?>
        <div style="margin-top:16px;padding:12px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;font-size:.83rem;color:#166534;">
          ✅ Razorpay configured — clients can recharge their wallets.
        </div>
        <?php else: ?>
        <div style="margin-top:16px;padding:12px;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;font-size:.83rem;color:#9a3412;">
          ⚠️ Razorpay not configured — wallet recharges are disabled for all clients.
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── Recharge Packages ──────────────────────────────────── -->
  <div class="tab-panel" data-panel="packages">
    <div style="display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start;">

      <div class="card">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
          <span style="font-size:1rem;font-weight:700;color:var(--gray-900);">Recharge Packages</span>
          <a href="pricing.php?edit_pkg=0#packages" class="btn btn-sm btn-primary">+ Add Package</a>
        </div>
        <div style="overflow-x:auto;">
          <table class="data-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Pay (₹)</th>
                <th>Credits (₹)</th>
                <th>Bonus</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($packages as $pkg): ?>
              <tr>
                <td>
                  <strong><?= htmlspecialchars($pkg['name']) ?></strong>
                  <?php if ($pkg['is_popular']): ?><span class="badge badge-confirmed" style="margin-left:4px;font-size:.65rem;">Popular</span><?php endif; ?>
                </td>
                <td>₹<?= number_format((float)$pkg['amount'], 2) ?></td>
                <td>₹<?= number_format((float)$pkg['credits'], 2) ?></td>
                <td><?= (float)$pkg['bonus'] > 0 ? '+₹'.number_format((float)$pkg['bonus'],2) : '—' ?></td>
                <td><span class="badge <?= $pkg['is_active'] ? 'badge-confirmed' : 'badge-cancelled' ?>"><?= $pkg['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                <td style="display:flex;gap:6px;">
                  <a href="pricing.php?edit_pkg=<?= $pkg['id'] ?>#packages" class="btn btn-sm btn-outline">Edit</a>
                  <form method="POST" onsubmit="return confirm('Delete this package?');" style="display:inline;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete_package">
                    <input type="hidden" name="pkg_id" value="<?= $pkg['id'] ?>">
                    <button class="btn btn-sm btn-outline" style="color:var(--danger);">Del</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php if (isset($_GET['edit_pkg'])): ?>
      <div class="card">
        <div class="card-header">
          <span style="font-size:.95rem;font-weight:700;color:var(--gray-900);"><?= $editPkg ? 'Edit Package' : 'New Package' ?></span>
        </div>
        <div class="card-body">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_package">
            <input type="hidden" name="pkg_id" value="<?= $editPkg ? $editPkg['id'] : 0 ?>">

            <div class="form-group">
              <label class="form-label">Name *</label>
              <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($editPkg['name'] ?? '') ?>" required>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Client Pays (₹) *</label>
                <input type="number" name="amount" class="form-control" value="<?= $editPkg['amount'] ?? '' ?>" step="0.01" min="1" required>
              </div>
              <div class="form-group">
                <label class="form-label">Credits Added (₹) *</label>
                <input type="number" name="credits" class="form-control" value="<?= $editPkg['credits'] ?? '' ?>" step="0.01" min="1" required>
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Bonus Credits (₹)</label>
                <input type="number" name="bonus" class="form-control" value="<?= $editPkg['bonus'] ?? 0 ?>" step="0.01" min="0">
              </div>
              <div class="form-group">
                <label class="form-label">Sort Order</label>
                <input type="number" name="sort_order" class="form-control" value="<?= $editPkg['sort_order'] ?? 0 ?>">
              </div>
            </div>
            <div class="form-group">
              <label class="form-label">Description</label>
              <input type="text" name="description" class="form-control" value="<?= htmlspecialchars($editPkg['description'] ?? '') ?>">
            </div>
            <div style="display:flex;gap:16px;margin-bottom:16px;">
              <label style="display:flex;align-items:center;gap:6px;font-size:.85rem;cursor:pointer;">
                <input type="checkbox" name="is_popular" value="1" <?= ($editPkg['is_popular'] ?? 0) ? 'checked' : '' ?>>
                Mark as Popular
              </label>
              <label style="display:flex;align-items:center;gap:6px;font-size:.85rem;cursor:pointer;">
                <input type="checkbox" name="is_active" value="1" <?= ($editPkg['is_active'] ?? 1) ? 'checked' : '' ?>>
                Active
              </label>
            </div>
            <div style="display:flex;gap:8px;">
              <button type="submit" class="btn btn-primary btn-sm">Save</button>
              <a href="pricing.php#packages" class="btn btn-outline btn-sm">Cancel</a>
            </div>
          </form>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div>

  <!-- ── Manual Credit ─────────────────────────────────────── -->
  <div class="tab-panel" data-panel="manual">
    <div style="display:grid;grid-template-columns:400px 1fr;gap:20px;align-items:start;">
      <div class="card">
        <div class="card-header">
          <span style="font-size:1rem;font-weight:700;color:var(--gray-900);">🎁 Add Credits Manually</span>
        </div>
        <div class="card-body">
          <p style="font-size:.83rem;color:var(--gray-500);margin-bottom:16px;">
            Credit a business wallet for offline payments, support adjustments, or promotions.
          </p>
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="manual_credit">

            <div class="form-group">
              <label class="form-label">Business *</label>
              <select name="business_id" class="form-select" required>
                <option value="">Select a business…</option>
                <?php foreach ($businesses as $b): ?>
                  <option value="<?= $b['id'] ?>">
                    <?= htmlspecialchars($b['name']) ?> — ₹<?= number_format((float)$b['wallet_balance'], 2) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Amount (₹) *</label>
              <input type="number" name="amount" class="form-control" step="0.01" min="1" required>
            </div>
            <div class="form-group">
              <label class="form-label">Reason</label>
              <input type="text" name="reason" class="form-control" placeholder="e.g. Promotional credit, Offline payment">
            </div>
            <button type="submit" class="btn btn-primary" onclick="return confirm('Credit this wallet?');">Add Credits</button>
          </form>
        </div>
      </div>

      <!-- All balances overview -->
      <div class="card">
        <div class="card-header">
          <span style="font-size:1rem;font-weight:700;color:var(--gray-900);">All Business Wallet Balances</span>
        </div>
        <div style="overflow-x:auto;">
          <table class="data-table">
            <thead>
              <tr><th>Business</th><th>Mode</th><th style="text-align:right;">Balance</th><th>Status</th></tr>
            </thead>
            <tbody>
              <?php foreach ($businesses as $b):
                $lowThreshold = (float)($platform['low_balance_alert'] ?? 100);
                $isLow = (float)$b['wallet_balance'] < $lowThreshold;
              ?>
              <tr>
                <td><strong><?= htmlspecialchars($b['name']) ?></strong></td>
                <td><span class="badge"><?= $b['payment_mode'] === 'own' ? 'Own GW' : 'Platform' ?></span></td>
                <td style="text-align:right;font-weight:600;color:<?= $isLow ? 'var(--danger)' : 'var(--gray-900)' ?>;">
                  ₹<?= number_format((float)$b['wallet_balance'], 2) ?>
                </td>
                <td><?= $isLow ? '<span class="badge badge-cancelled">Low</span>' : '<span class="badge badge-confirmed">OK</span>' ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

</div>

<script>
(function () {
  const hash = window.location.hash.replace('#', '');
  if (!hash) return;
  const tab = document.querySelector('.tabs .tab[data-tab="' + hash + '"]');
  if (tab) tab.click();
})();
</script>

<?php include __DIR__ . '/partials/foot.php'; ?>
