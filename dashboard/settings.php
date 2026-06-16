<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

$business   = getCurrentBusiness();
$businessId = (int)$_SESSION['business_id'];

$BUSINESS_TYPES = [
    'clinic'     => '🏥 Medical Clinic',
    'dental'     => '🦷 Dental Practice',
    'hospital'   => '🏨 Hospital',
    'salon'      => '💇 Hair Salon',
    'spa'        => '💆 Spa & Wellness',
    'beauty'     => '💄 Beauty Studio',
    'gym'        => '🏋️ Gym & Fitness',
    'restaurant' => '🍽️ Restaurant',
    'legal'      => '⚖️ Legal Office',
    'other'      => '🏢 Other',
];

$COUNTRIES = [
    'US' => 'United States', 'CA' => 'Canada', 'GB' => 'United Kingdom', 'AU' => 'Australia',
    'IN' => 'India', 'PK' => 'Pakistan', 'AE' => 'United Arab Emirates', 'SA' => 'Saudi Arabia',
    'DE' => 'Germany', 'FR' => 'France', 'ES' => 'Spain', 'IT' => 'Italy', 'NL' => 'Netherlands',
    'BR' => 'Brazil', 'MX' => 'Mexico', 'ZA' => 'South Africa', 'NG' => 'Nigeria', 'EG' => 'Egypt',
    'PH' => 'Philippines', 'ID' => 'Indonesia', 'MY' => 'Malaysia', 'SG' => 'Singapore',
    'BD' => 'Bangladesh', 'TR' => 'Turkey', 'NZ' => 'New Zealand', 'IE' => 'Ireland',
];

$CURRENCIES = ['USD','EUR','GBP','CAD','AUD','INR','PKR','AED','SAR','ZAR','NGN','EGP','PHP','IDR','MYR','SGD','BDT','TRY','NZD','BRL','MXN'];

$TIMEZONES = DateTimeZone::listIdentifiers(DateTimeZone::ALL);

$PLANS = [
    'free' => [
        'name' => 'Free', 'price' => 0,
        'features' => ['1 Staff Member','10 Services','50 Appointments / month','WhatsApp Booking Bot','Basic Analytics'],
    ],
];
$stmt = db()->query("SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY price_monthly ASC");
foreach ($stmt->fetchAll() as $p) {
    $PLANS[$p['slug']] = [
        'name'     => $p['name'],
        'price'    => (float)$p['price_monthly'],
        'features' => json_decode($p['features'] ?? '[]', true) ?: [],
    ];
}

// ─── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        setFlash('error', 'Invalid request. Please try again.');
        header('Location: settings.php'); exit;
    }

    $action = post('action');

    if ($action === 'update_profile') {
        $name         = trim(post('name'));
        $phone        = trim(post('phone'));
        $businessType = post('business_type');
        $logo         = trim(post('logo'));
        $address      = trim(post('address'));
        $city         = trim(post('city'));
        $country      = post('country');
        $timezone     = post('timezone');
        $currencyVal  = post('currency');

        if ($name === '') {
            setFlash('error', 'Business name is required.');
        } elseif (!isset($BUSINESS_TYPES[$businessType])) {
            setFlash('error', 'Please select a valid business type.');
        } elseif (!in_array($timezone, $TIMEZONES, true)) {
            setFlash('error', 'Please select a valid timezone.');
        } else {
            db()->prepare("
                UPDATE businesses SET name=?, phone=?, business_type=?, logo=?, address=?, city=?, country=?, timezone=?, currency=?
                WHERE id = ?
            ")->execute([$name, $phone, $businessType, $logo, $address, $city, $country, $timezone, $currencyVal, $businessId]);
            setFlash('success', 'Business profile updated successfully.');
        }
        header('Location: settings.php'); exit;

    } elseif ($action === 'update_pricing') {
        $pricingMode = post('pricing_mode') === 'fixed' ? 'fixed' : 'per_service';
        $fixedPrice  = post('fixed_price') !== '' ? (float)post('fixed_price') : null;

        if ($pricingMode === 'fixed' && ($fixedPrice === null || $fixedPrice <= 0)) {
            setFlash('error', 'Please enter a valid fixed price greater than 0.');
        } else {
            db()->prepare("UPDATE businesses SET pricing_mode = ?, fixed_price = ? WHERE id = ?")
                ->execute([$pricingMode, $pricingMode === 'fixed' ? $fixedPrice : null, $businessId]);
            setFlash('success', 'Pricing settings updated successfully.');
        }
        header('Location: settings.php#profile'); exit;

    } elseif ($action === 'update_booking_options') {
        $tokenMode    = post('token_mode') === 'daily' ? 'daily' : 'db_id';
        $timeRequired = post('time_required') === '0' ? 0 : 1;
        $parallelBk   = post('enable_parallel_bookings') === '1' ? 1 : 0;

        db()->prepare("UPDATE businesses SET token_mode = ?, time_required = ?, enable_parallel_bookings = ? WHERE id = ?")
            ->execute([$tokenMode, $timeRequired, $parallelBk, $businessId]);
        setFlash('success', 'Booking options updated successfully.');
        header('Location: settings.php#booking'); exit;

    } elseif ($action === 'change_password') {
        $current = post('current_password');
        $new     = post('new_password');
        $confirm = post('confirm_password');

        $stmt = db()->prepare("SELECT password FROM businesses WHERE id = ?");
        $stmt->execute([$businessId]);
        $hash = (string)$stmt->fetchColumn();

        if (!password_verify($current, $hash)) {
            setFlash('error', 'Current password is incorrect.');
        } elseif (strlen($new) < 8) {
            setFlash('error', 'New password must be at least 8 characters long.');
        } elseif ($new !== $confirm) {
            setFlash('error', 'New password and confirmation do not match.');
        } else {
            db()->prepare("UPDATE businesses SET password = ? WHERE id = ?")
                ->execute([password_hash($new, PASSWORD_DEFAULT), $businessId]);
            setFlash('success', 'Password changed successfully.');
        }
        header('Location: settings.php#security'); exit;
    }

    header('Location: settings.php');
    exit;
}

$activeNav = 'settings';
$pageTitle = 'Settings';
include __DIR__ . '/partials/head.php';
?>

<div class="tabs" data-panel-group="#settingsPanels">
  <div class="tab active" data-tab="profile">🏢 Business Profile</div>
  <div class="tab" data-tab="booking">📋 Booking Options</div>
  <div class="tab" data-tab="security">🔒 Security</div>
  <div class="tab" data-tab="subscription">💳 Subscription</div>
</div>

<div id="settingsPanels">

  <!-- ── Business Profile ───────────────────────────────────── -->
  <div class="tab-panel active" data-panel="profile">
    <div class="card">
      <div class="card-body">
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="update_profile">

          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Business Name *</label>
              <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($business['name']) ?>" required>
            </div>
            <div class="form-group">
              <label class="form-label">Business Type *</label>
              <select name="business_type" class="form-select" required>
                <?php foreach ($BUSINESS_TYPES as $key => $label): ?>
                  <option value="<?= $key ?>" <?= $business['business_type'] === $key ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Email Address</label>
              <input type="email" class="form-control" value="<?= htmlspecialchars($business['email']) ?>" disabled>
              <div class="form-hint">Your login email cannot be changed.</div>
            </div>
            <div class="form-group">
              <label class="form-label">Phone Number</label>
              <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($business['phone'] ?? '') ?>" placeholder="e.g. +1 555 123 4567">
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Logo URL</label>
            <input type="text" name="logo" class="form-control" value="<?= htmlspecialchars($business['logo'] ?? '') ?>" placeholder="https://example.com/logo.png">
            <div class="form-hint">Paste a hosted image URL to display your logo on customer-facing pages.</div>
          </div>

          <div class="form-group">
            <label class="form-label">Address</label>
            <input type="text" name="address" class="form-control" value="<?= htmlspecialchars($business['address'] ?? '') ?>" placeholder="Street address">
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label">City</label>
              <input type="text" name="city" class="form-control" value="<?= htmlspecialchars($business['city'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Country</label>
              <select name="country" class="form-select">
                <?php foreach ($COUNTRIES as $code => $label): ?>
                  <option value="<?= $code ?>" <?= ($business['country'] ?? 'US') === $code ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Timezone</label>
              <select name="timezone" class="form-select">
                <?php foreach ($TIMEZONES as $tz): ?>
                  <option value="<?= $tz ?>" <?= ($business['timezone'] ?? 'UTC') === $tz ? 'selected' : '' ?>><?= str_replace('_', ' ', $tz) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Currency</label>
              <select name="currency" class="form-select">
                <?php foreach ($CURRENCIES as $cur): ?>
                  <option value="<?= $cur ?>" <?= ($business['currency'] ?? 'USD') === $cur ? 'selected' : '' ?>><?= $cur ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-hint">Used for prices throughout the dashboard and WhatsApp messages.</div>
            </div>
          </div>

          <div class="modal-footer" style="padding:0;border:none;justify-content:flex-start;margin-top:8px;">
            <button type="submit" class="btn btn-primary">Save Changes</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Appointment Pricing -->
    <div class="card" style="margin-top:20px;">
      <div class="card-header">
        <span style="font-size:1rem;font-weight:700;color:var(--gray-900);">💰 Appointment Pricing</span>
      </div>
      <div class="card-body">
        <p style="font-size:.85rem;color:var(--gray-500);margin-bottom:16px;">
          Choose how prices are shown to customers in the WhatsApp bot.
        </p>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="update_pricing">

          <div class="form-group">
            <label class="form-label" style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-weight:400;margin-bottom:10px;">
              <input type="radio" name="pricing_mode" value="per_service" <?= ($business['pricing_mode'] ?? 'per_service') !== 'fixed' ? 'checked' : '' ?> onchange="document.getElementById('fixedPriceField').style.display='none'" style="margin-top:3px;">
              <span>
                <strong style="display:block;color:var(--gray-900);">Per-Service Pricing</strong>
                <span style="font-size:.82rem;color:var(--gray-500);">Each service has its own price, shown to customers when booking.</span>
              </span>
            </label>
            <label class="form-label" style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-weight:400;">
              <input type="radio" name="pricing_mode" value="fixed" <?= ($business['pricing_mode'] ?? 'per_service') === 'fixed' ? 'checked' : '' ?> onchange="document.getElementById('fixedPriceField').style.display='block'" style="margin-top:3px;">
              <span>
                <strong style="display:block;color:var(--gray-900);">Fixed Price for Any Appointment</strong>
                <span style="font-size:.82rem;color:var(--gray-500);">Charge one flat price for every booking, regardless of service. Individual service prices won't be shown to customers in the bot.</span>
              </span>
            </label>
          </div>

          <div class="form-group" id="fixedPriceField" style="<?= ($business['pricing_mode'] ?? 'per_service') === 'fixed' ? '' : 'display:none;' ?> max-width:240px;">
            <label class="form-label">Fixed Price (<?= htmlspecialchars($business['currency'] ?? 'USD') ?>)</label>
            <input type="number" name="fixed_price" class="form-control" step="0.01" min="0" value="<?= htmlspecialchars($business['fixed_price'] ?? '') ?>" placeholder="e.g. 300">
          </div>

          <div class="modal-footer" style="padding:0;border:none;justify-content:flex-start;margin-top:8px;">
            <button type="submit" class="btn btn-primary">Save Pricing</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ── Booking Options ─────────────────────────────────────── -->
  <div class="tab-panel" data-panel="booking">
    <div class="card">
      <div class="card-header">
        <span style="font-size:1rem;font-weight:700;color:var(--gray-900);">🔢 Appointment Token / Numbering</span>
      </div>
      <div class="card-body">
        <p style="font-size:.85rem;color:var(--gray-500);margin-bottom:16px;">
          Choose how booking numbers are shown to customers and in your dashboard.
        </p>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="update_booking_options">

          <div class="form-group">
            <label class="form-label" style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-weight:400;margin-bottom:10px;">
              <input type="radio" name="token_mode" value="db_id" <?= ($business['token_mode'] ?? 'db_id') !== 'daily' ? 'checked' : '' ?> style="margin-top:3px;">
              <span>
                <strong style="display:block;color:var(--gray-900);">Database Serial (default)</strong>
                <span style="font-size:.82rem;color:var(--gray-500);">Booking numbers are global sequential IDs (#000001, #000002…). Numbers never repeat.</span>
              </span>
            </label>
            <label class="form-label" style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-weight:400;">
              <input type="radio" name="token_mode" value="daily" <?= ($business['token_mode'] ?? '') === 'daily' ? 'checked' : '' ?> style="margin-top:3px;">
              <span>
                <strong style="display:block;color:var(--gray-900);">Daily Token (restart each day from 1)</strong>
                <span style="font-size:.82rem;color:var(--gray-500);">Token #1, #2, #3… each day — ideal for clinics where patients are called by today's token number. Next day restarts from 1.</span>
              </span>
            </label>
          </div>

          <hr style="border:none;border-top:1px solid var(--gray-100);margin:20px 0;">
          <div style="font-size:.95rem;font-weight:700;color:var(--gray-900);margin-bottom:12px;">⏰ Time Slots</div>

          <div class="form-group">
            <label class="form-label" style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-weight:400;margin-bottom:10px;">
              <input type="radio" name="time_required" value="1" <?= ($business['time_required'] ?? 1) ? 'checked' : '' ?> style="margin-top:3px;">
              <span>
                <strong style="display:block;color:var(--gray-900);">Time slots required (default)</strong>
                <span style="font-size:.82rem;color:var(--gray-500);">Customers select a specific time slot when booking via WhatsApp or the dashboard.</span>
              </span>
            </label>
            <label class="form-label" style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-weight:400;">
              <input type="radio" name="time_required" value="0" <?= !($business['time_required'] ?? 1) ? 'checked' : '' ?> style="margin-top:3px;">
              <span>
                <strong style="display:block;color:var(--gray-900);">Date only — no time slot</strong>
                <span style="font-size:.82rem;color:var(--gray-500);">Customers book a date only. Useful for walk-in / token-queue systems where time is managed in person.</span>
              </span>
            </label>
          </div>

          <hr style="border:none;border-top:1px solid var(--gray-100);margin:20px 0;">
          <div style="font-size:.95rem;font-weight:700;color:var(--gray-900);margin-bottom:12px;">👥 Parallel Staff Bookings</div>

          <div class="form-group">
            <label class="form-label" style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-weight:400;margin-bottom:10px;">
              <input type="radio" name="enable_parallel_bookings" value="0" <?= !($business['enable_parallel_bookings'] ?? 0) ? 'checked' : '' ?> style="margin-top:3px;">
              <span>
                <strong style="display:block;color:var(--gray-900);">Standard (one booking per slot per staff)</strong>
                <span style="font-size:.82rem;color:var(--gray-500);">Each time slot is booked by one customer per available staff member. Default for most businesses.</span>
              </span>
            </label>
            <label class="form-label" style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-weight:400;">
              <input type="radio" name="enable_parallel_bookings" value="1" <?= ($business['enable_parallel_bookings'] ?? 0) ? 'checked' : '' ?> style="margin-top:3px;">
              <span>
                <strong style="display:block;color:var(--gray-900);">Parallel (up to N bookings per slot = staff count)</strong>
                <span style="font-size:.82rem;color:var(--gray-500);">If you have 4 staff, 4 customers can book the same time slot simultaneously — ideal for salons with multiple chairs.</span>
              </span>
            </label>
          </div>

          <div class="modal-footer" style="padding:0;border:none;justify-content:flex-start;margin-top:8px;">
            <button type="submit" class="btn btn-primary">Save Booking Options</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ── Security ────────────────────────────────────────────── -->
  <div class="tab-panel" data-panel="security">
    <div class="card" style="max-width:520px;">
      <div class="card-body">
        <div style="font-weight:700;color:var(--gray-900);margin-bottom:4px;">Change Password</div>
        <p style="font-size:.85rem;color:var(--gray-500);margin-bottom:16px;">Use a strong password you don't use elsewhere.</p>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="change_password">

          <div class="form-group">
            <label class="form-label">Current Password *</label>
            <div class="input-group">
              <input type="password" name="current_password" class="form-control" required>
              <button type="button" class="input-group-icon toggle-password">👁</button>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">New Password *</label>
            <div class="input-group">
              <input type="password" name="new_password" class="form-control" minlength="8" required>
              <button type="button" class="input-group-icon toggle-password">👁</button>
            </div>
            <div class="form-hint">At least 8 characters.</div>
          </div>
          <div class="form-group">
            <label class="form-label">Confirm New Password *</label>
            <div class="input-group">
              <input type="password" name="confirm_password" class="form-control" minlength="8" required>
              <button type="button" class="input-group-icon toggle-password">👁</button>
            </div>
          </div>

          <div class="modal-footer" style="padding:0;border:none;justify-content:flex-start;margin-top:8px;">
            <button type="submit" class="btn btn-primary">Update Password</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ── Subscription ────────────────────────────────────────── -->
  <div class="tab-panel" data-panel="subscription">
    <div class="card" style="margin-bottom:20px;">
      <div class="card-body" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div>
          <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--primary);margin-bottom:6px;">Current Plan</div>
          <div style="font-size:1.3rem;font-weight:800;color:var(--gray-900);"><?= htmlspecialchars($PLANS[$business['subscription_plan']]['name'] ?? ucfirst($business['subscription_plan'])) ?></div>
          <?php if (!empty($business['subscription_ends_at'])): ?>
            <div style="font-size:.82rem;color:var(--gray-500);margin-top:4px;">Renews / expires on <?= formatDate($business['subscription_ends_at']) ?></div>
          <?php else: ?>
            <div style="font-size:.82rem;color:var(--gray-500);margin-top:4px;">No expiry — Free plan</div>
          <?php endif; ?>
        </div>
        <span class="badge badge-confirmed" style="font-size:.85rem;padding:6px 16px;"><?= $business['is_active'] ? 'Active' : 'Inactive' ?></span>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;">
      <?php foreach ($PLANS as $slug => $plan):
        $isCurrent = $business['subscription_plan'] === $slug;
      ?>
        <div class="card" style="<?= $isCurrent ? 'border-color:var(--primary);box-shadow:0 0 0 3px rgba(99,102,241,.12);' : '' ?>">
          <div class="card-body">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
              <div style="font-weight:700;font-size:1.05rem;color:var(--gray-900);"><?= htmlspecialchars($plan['name']) ?></div>
              <?php if ($isCurrent): ?><span class="badge badge-confirmed">Current</span><?php endif; ?>
            </div>
            <div style="font-size:1.6rem;font-weight:800;color:var(--gray-900);margin-bottom:14px;">
              <?= $plan['price'] > 0 ? '$' . number_format($plan['price'], 2) : 'Free' ?>
              <?php if ($plan['price'] > 0): ?><span style="font-size:.8rem;font-weight:500;color:var(--gray-400);">/month</span><?php endif; ?>
            </div>
            <ul style="list-style:none;padding:0;margin:0 0 16px;display:flex;flex-direction:column;gap:8px;">
              <?php foreach ($plan['features'] as $feature): ?>
                <li style="font-size:.85rem;color:var(--gray-600);display:flex;align-items:flex-start;gap:8px;">
                  <span style="color:var(--wa);">✓</span> <?= htmlspecialchars($feature) ?>
                </li>
              <?php endforeach; ?>
            </ul>
            <?php if (!$isCurrent): ?>
              <button class="btn btn-primary btn-full btn-sm" disabled title="Billing not yet available">Upgrade</button>
            <?php else: ?>
              <button class="btn btn-outline btn-full btn-sm" disabled>Current Plan</button>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<script>
// Open the correct tab based on URL hash (e.g. settings.php#subscription)
(function () {
  const hash = window.location.hash.replace('#', '');
  if (!hash) return;
  const tab = document.querySelector('.tabs .tab[data-tab="' + hash + '"]');
  if (tab) tab.click();
})();
</script>

<?php include __DIR__ . '/partials/foot.php'; ?>
