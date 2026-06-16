<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/../includes/payment.php';

requireAuth();

$business   = getCurrentBusiness();
$businessId = (int)$_SESSION['business_id'];

// ─── Handle payment mode + own gateway save ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    $action = post('action');

    if ($action === 'update_payment_mode') {
        $mode = post('payment_mode') === 'own' ? 'own' : 'platform';
        $ownKeyId     = trim(post('own_razorpay_key_id'));
        $ownKeySecret = trim(post('own_razorpay_key_secret'));

        if ($mode === 'own' && ($ownKeyId === '' || $ownKeySecret === '')) {
            setFlash('error', 'Please enter your Razorpay Key ID and Key Secret to use your own gateway.');
        } else {
            db()->prepare("
                UPDATE businesses
                SET payment_mode = ?, own_gateway_type = ?,
                    own_razorpay_key_id = ?, own_razorpay_key_secret = ?
                WHERE id = ?
            ")->execute([
                $mode,
                $mode === 'own' ? 'razorpay' : null,
                $mode === 'own' ? $ownKeyId     : null,
                $mode === 'own' ? $ownKeySecret : null,
                $businessId,
            ]);
            $business = getCurrentBusiness(true); // fresh after save
            setFlash('success', 'Payment gateway settings updated.');
        }
    }

    header('Location: wallet.php'); exit;
}

$balance      = getWalletBalance($businessId);
$packages     = getRechargePackages();
$transactions = getWalletTransactions($businessId, 15);
$txTotal      = countWalletTransactions($businessId);
$rate         = getBookingFeeRate($businessId);
$platform     = getPlatformSettings();
$rzReady      = isPlatformRazorpayConfigured();

$activeNav = 'wallet';
$pageTitle = '💰 Wallet';
include __DIR__ . '/partials/head.php';
?>

<!-- Balance banner -->
<div class="stats-row" style="margin-bottom:0;">
  <div class="stat-card" style="background:linear-gradient(135deg,#eef2ff,#f3e8ff);border-color:var(--primary-light);flex:1.2;">
    <div class="stat-card-icon indigo">💰</div>
    <div>
      <div class="stat-card-num" id="walletBalance">₹<?= number_format($balance, 2) ?></div>
      <div class="stat-card-label">Wallet Balance</div>
    </div>
  </div>
  <div class="stat-card" style="flex:1;">
    <div class="stat-card-icon <?= $balance >= $rate ? 'green' : 'orange' ?>">
      <?= $balance >= $rate ? '✅' : '⚠️' ?>
    </div>
    <div>
      <div class="stat-card-num" style="font-size:1.1rem;">₹<?= number_format($rate, 2) ?></div>
      <div class="stat-card-label">Fee per Booking
        <span style="font-size:.72rem;color:var(--gray-400);">(<?= $business['payment_mode'] === 'own' ? 'Own Gateway' : 'Platform Gateway' ?>)</span>
      </div>
    </div>
  </div>
  <div class="stat-card" style="flex:1;">
    <div class="stat-card-icon blue">📋</div>
    <div>
      <div class="stat-card-num" style="font-size:1.1rem;">
        <?= $rate > 0 ? number_format(floor($balance / $rate)) : '∞' ?>
      </div>
      <div class="stat-card-label">Bookings Remaining</div>
    </div>
  </div>
</div>

<?php if ($balance < $rate): ?>
<div class="wa-connect-banner" style="background:linear-gradient(135deg,#fff7ed,#fef3c7);border-color:#f59e0b;margin-top:20px;">
  <div class="wa-connect-banner-icon">⚠️</div>
  <div style="flex:1">
    <div class="wa-connect-banner-title" style="color:#92400e;">Insufficient Wallet Balance</div>
    <div class="wa-connect-banner-sub" style="color:#b45309;">Your balance is below the booking fee (₹<?= number_format($rate, 2) ?>). New bookings are currently blocked. Please recharge your wallet.</div>
  </div>
</div>
<?php endif; ?>

<div class="content-grid-2" style="margin-top:20px;align-items:start;">

  <!-- ── Recharge Packages ────────────────────────────────── -->
  <div>
    <div class="card">
      <div class="card-header">
        <span style="font-size:1rem;font-weight:700;color:var(--gray-900);">🔋 Recharge Wallet</span>
      </div>
      <div class="card-body">
        <?php if (!$rzReady): ?>
          <div style="text-align:center;padding:20px;color:var(--gray-500);font-size:.88rem;">
            ⚙️ Payment gateway not configured yet. Please contact support to enable recharges.
          </div>
        <?php elseif (empty($packages)): ?>
          <div style="text-align:center;padding:20px;color:var(--gray-500);">No recharge packages available.</div>
        <?php else: ?>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <?php foreach ($packages as $pkg):
              $total = (float)$pkg['credits'] + (float)$pkg['bonus'];
            ?>
            <div class="package-card <?= $pkg['is_popular'] ? 'popular' : '' ?>"
                 data-package-id="<?= $pkg['id'] ?>"
                 data-amount="<?= $pkg['amount'] ?>"
                 data-name="<?= htmlspecialchars($pkg['name']) ?>"
                 style="border:2px solid <?= $pkg['is_popular'] ? 'var(--primary)' : 'var(--gray-200)' ?>;border-radius:12px;padding:16px;cursor:pointer;position:relative;transition:all .18s;">
              <?php if ($pkg['is_popular']): ?>
                <div style="position:absolute;top:-1px;right:12px;background:var(--primary);color:#fff;font-size:.65rem;font-weight:700;padding:2px 10px;border-radius:0 0 8px 8px;letter-spacing:.05em;">POPULAR</div>
              <?php endif; ?>
              <div style="font-weight:700;font-size:1rem;color:var(--gray-900);margin-bottom:2px;"><?= htmlspecialchars($pkg['name']) ?></div>
              <div style="font-size:1.4rem;font-weight:800;color:var(--primary);">₹<?= number_format((float)$pkg['amount'], 0) ?></div>
              <div style="font-size:.8rem;color:var(--gray-500);margin-top:4px;">
                ₹<?= number_format($total, 2) ?> credits
                <?php if ((float)$pkg['bonus'] > 0): ?>
                  <span style="color:var(--wa-dark);font-weight:600;">+₹<?= number_format((float)$pkg['bonus'], 0) ?> bonus</span>
                <?php endif; ?>
              </div>
              <?php if (!empty($pkg['description'])): ?>
                <div style="font-size:.75rem;color:var(--gray-400);margin-top:4px;"><?= htmlspecialchars($pkg['description']) ?></div>
              <?php endif; ?>
              <button class="btn btn-primary btn-sm btn-full recharge-btn" style="margin-top:12px;"
                      data-package-id="<?= $pkg['id'] ?>">
                Recharge ₹<?= number_format((float)$pkg['amount'], 0) ?>
              </button>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Payment Mode Settings -->
    <div class="card" style="margin-top:20px;">
      <div class="card-header">
        <span style="font-size:1rem;font-weight:700;color:var(--gray-900);">⚙️ Payment Gateway Mode</span>
      </div>
      <div class="card-body">
        <p style="font-size:.83rem;color:var(--gray-500);margin-bottom:16px;line-height:1.6;">
          Choose how payments are collected from your customers. This affects your per-booking fee.
        </p>
        <form method="POST" id="gatewayForm">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="update_payment_mode">

          <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;margin-bottom:14px;padding:12px;border-radius:10px;border:2px solid <?= ($business['payment_mode'] ?? 'platform') === 'platform' ? 'var(--primary)' : 'var(--gray-200)' ?>;" onclick="selectMode('platform')">
            <input type="radio" name="payment_mode" value="platform" <?= ($business['payment_mode'] ?? 'platform') === 'platform' ? 'checked' : '' ?> style="margin-top:3px;">
            <span>
              <strong style="color:var(--gray-900);">Platform Gateway</strong>
              <span style="display:block;font-size:.8rem;color:var(--gray-500);">We process payments. Fee: <strong style="color:var(--primary);">₹<?= number_format((float)($platform['rate_platform_gateway'] ?? 20), 2) ?>/booking</strong></span>
            </span>
          </label>

          <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;padding:12px;border-radius:10px;border:2px solid <?= ($business['payment_mode'] ?? 'platform') === 'own' ? 'var(--primary)' : 'var(--gray-200)' ?>;" onclick="selectMode('own')">
            <input type="radio" name="payment_mode" value="own" <?= ($business['payment_mode'] ?? 'platform') === 'own' ? 'checked' : '' ?> style="margin-top:3px;">
            <span>
              <strong style="color:var(--gray-900);">Own Razorpay Account</strong>
              <span style="display:block;font-size:.8rem;color:var(--gray-500);">You collect payments directly. Fee: <strong style="color:var(--wa-dark);">₹<?= number_format((float)($platform['rate_own_gateway'] ?? 5), 2) ?>/booking</strong></span>
            </span>
          </label>

          <div id="ownGatewayFields" style="margin-top:14px;<?= ($business['payment_mode'] ?? 'platform') === 'own' ? '' : 'display:none;' ?>">
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Razorpay Key ID</label>
                <input type="text" name="own_razorpay_key_id" class="form-control"
                       value="<?= htmlspecialchars($business['own_razorpay_key_id'] ?? '') ?>"
                       placeholder="rzp_live_XXXXXXXX">
              </div>
              <div class="form-group">
                <label class="form-label">Razorpay Key Secret</label>
                <input type="password" name="own_razorpay_key_secret" class="form-control"
                       placeholder="<?= !empty($business['own_razorpay_key_secret']) ? '••••••••••••' : 'Enter secret key' ?>">
                <div class="form-hint">Leave blank to keep existing secret.</div>
              </div>
            </div>
          </div>

          <button type="submit" class="btn btn-primary" style="margin-top:16px;">Save Gateway Settings</button>
        </form>
      </div>
    </div>
  </div>

  <!-- ── Transaction History ──────────────────────────────── -->
  <div class="card">
    <div class="card-header">
      <span style="font-size:1rem;font-weight:700;color:var(--gray-900);">📄 Transaction History</span>
      <span style="font-size:.78rem;color:var(--gray-400);"><?= number_format($txTotal) ?> total</span>
    </div>
    <?php if (empty($transactions)): ?>
      <div class="empty-state">
        <div class="empty-state-emoji">💳</div>
        <div class="empty-state-title">No transactions yet</div>
        <div class="empty-state-desc">Your wallet credits and booking fee deductions will appear here.</div>
      </div>
    <?php else: ?>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Description</th>
              <th>Type</th>
              <th style="text-align:right;">Amount</th>
              <th style="text-align:right;">Balance</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($transactions as $tx): ?>
            <tr>
              <td style="font-size:.8rem;color:var(--gray-400);white-space:nowrap;"><?= date('d M y, h:i A', strtotime($tx['created_at'])) ?></td>
              <td style="font-size:.85rem;">
                <?= htmlspecialchars($tx['description']) ?>
                <?php if (!empty($tx['reference_id'])): ?>
                  <div style="font-size:.72rem;color:var(--gray-400);"><?= htmlspecialchars($tx['reference_id']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($tx['type'] === 'credit'): ?>
                  <span class="badge badge-confirmed">Credit</span>
                <?php else: ?>
                  <span class="badge badge-pending">Debit</span>
                <?php endif; ?>
              </td>
              <td style="text-align:right;font-weight:600;color:<?= $tx['type'] === 'credit' ? 'var(--wa-dark)' : 'var(--danger)' ?>;">
                <?= $tx['type'] === 'credit' ? '+' : '-' ?>₹<?= number_format((float)$tx['amount'], 2) ?>
              </td>
              <td style="text-align:right;font-size:.85rem;color:var(--gray-500);">₹<?= number_format((float)$tx['balance_after'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

</div>

<!-- Razorpay JS -->
<?php if ($rzReady): ?>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<?php endif; ?>

<script>
const CSRF = <?= json_encode(csrfToken()) ?>;

function selectMode(mode) {
  document.querySelectorAll('input[name="payment_mode"]').forEach(r => r.checked = r.value === mode);
  document.getElementById('ownGatewayFields').style.display = mode === 'own' ? 'block' : 'none';
  document.querySelectorAll('label[onclick]').forEach(l => {
    l.style.borderColor = l.getAttribute('onclick').includes(mode) ? 'var(--primary)' : 'var(--gray-200)';
  });
}

document.querySelectorAll('.recharge-btn').forEach(btn => {
  btn.addEventListener('click', async function(e) {
    e.stopPropagation();
    const packageId = this.dataset.packageId;
    const originalText = this.textContent;
    this.disabled = true;
    this.textContent = 'Loading…';

    try {
      const res = await fetch('<?= APP_URL ?>/api/razorpay-order.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({package_id: parseInt(packageId), csrf_token: CSRF}),
      });
      const data = await res.json();
      if (data.error) { alert(data.error); return; }

      const rzp = new Razorpay({
        key: data.key_id,
        amount: data.amount,
        currency: data.currency,
        order_id: data.order_id,
        name: data.name,
        description: data.description,
        theme: {color: '#6366f1'},
        handler: async function(response) {
          const vRes = await fetch('<?= APP_URL ?>/api/razorpay-verify.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
              razorpay_order_id: response.razorpay_order_id,
              razorpay_payment_id: response.razorpay_payment_id,
              razorpay_signature: response.razorpay_signature,
            }),
          });
          const vData = await vRes.json();
          if (vData.success) {
            document.getElementById('walletBalance').textContent = '₹' + parseFloat(vData.balance).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            alert('✅ ' + vData.message + '\nNew Balance: ₹' + parseFloat(vData.balance).toFixed(2));
            location.reload();
          } else {
            alert('❌ ' + (vData.error || 'Payment verification failed.'));
          }
        },
        modal: {ondismiss: function() { btn.disabled = false; btn.textContent = originalText; }},
      });
      rzp.open();
    } catch(err) {
      alert('Network error. Please try again.');
    } finally {
      btn.disabled = false;
      btn.textContent = originalText;
    }
  });
});
</script>

<style>
.package-card:hover { box-shadow: 0 4px 20px rgba(99,102,241,.15); transform: translateY(-2px); }
</style>

<?php include __DIR__ . '/partials/foot.php'; ?>
