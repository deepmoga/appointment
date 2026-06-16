<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireSuperAdmin();

$admin = getCurrentSuperAdmin();

// ─── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        setFlash('error', 'Invalid request. Please try again.');
        header('Location: plans.php'); exit;
    }

    $action = post('action');
    $id     = (int)post('id');

    if ($action === 'save') {
        $name        = trim(post('name'));
        $slug        = trim(post('slug'));
        $priceMonthly = (float)post('price_monthly');
        $priceYearly  = (float)post('price_yearly');
        $maxStaff     = (int)post('max_staff');
        $maxServices  = (int)post('max_services');
        $maxAppts     = (int)post('max_appointments_per_month');
        $isActive     = post('is_active') === '1' ? 1 : 0;

        $featuresRaw = (string)post('features');
        $features = array_values(array_filter(array_map('trim', explode("\n", $featuresRaw)), fn($f) => $f !== ''));

        if ($name === '' || $slug === '') {
            setFlash('error', 'Name and slug are required.');
        } else {
            if ($id > 0) {
                db()->prepare("
                    UPDATE subscription_plans SET
                        name = ?, slug = ?, price_monthly = ?, price_yearly = ?,
                        max_staff = ?, max_services = ?, max_appointments_per_month = ?,
                        features = ?, is_active = ?
                    WHERE id = ?
                ")->execute([$name, $slug, $priceMonthly, $priceYearly, $maxStaff, $maxServices, $maxAppts, json_encode($features), $isActive, $id]);
                setFlash('success', 'Plan updated.');
            } else {
                db()->prepare("
                    INSERT INTO subscription_plans (name, slug, price_monthly, price_yearly, max_staff, max_services, max_appointments_per_month, features, is_active)
                    VALUES (?,?,?,?,?,?,?,?,?)
                ")->execute([$name, $slug, $priceMonthly, $priceYearly, $maxStaff, $maxServices, $maxAppts, json_encode($features), $isActive]);
                setFlash('success', 'Plan created.');
            }
        }
        header('Location: plans.php'); exit;

    } elseif ($action === 'toggle_active') {
        db()->prepare("UPDATE subscription_plans SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
        setFlash('success', 'Plan status updated.');
        header('Location: plans.php'); exit;

    } elseif ($action === 'delete') {
        db()->prepare("DELETE FROM subscription_plans WHERE id = ?")->execute([$id]);
        setFlash('success', 'Plan deleted.');
        header('Location: plans.php'); exit;
    }
}

// ─── View data ──────────────────────────────────────────────────────────────────
$plans = db()->query("SELECT * FROM subscription_plans ORDER BY price_monthly ASC")->fetchAll();

$activeNav = 'plans';
$pageTitle = '💳 Subscription Plans';
include __DIR__ . '/partials/head.php';
?>

<div style="margin-bottom:16px;text-align:right;">
  <button class="btn btn-primary" data-modal-open="planModal" onclick="resetPlanForm()">+ Add Plan</button>
</div>

<?php if (empty($plans)): ?>
  <div class="card">
    <div class="empty-state">
      <div class="empty-state-emoji">💳</div>
      <div class="empty-state-title">No subscription plans yet</div>
      <div class="empty-state-desc">Create a plan to define pricing and limits for businesses.</div>
      <button class="btn btn-primary btn-sm" data-modal-open="planModal" onclick="resetPlanForm()">+ Add Plan</button>
    </div>
  </div>
<?php else: ?>
  <div class="content-grid-2">
    <?php foreach ($plans as $plan): ?>
      <?php $features = json_decode($plan['features'] ?? '[]', true) ?: []; ?>
      <div class="card">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
          <span style="font-size:1rem;font-weight:700;color:var(--gray-900);"><?= htmlspecialchars($plan['name']) ?></span>
          <?php if ($plan['is_active']): ?>
            <span class="badge badge-completed">Active</span>
          <?php else: ?>
            <span class="badge badge-cancelled">Inactive</span>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <div style="font-size:1.6rem;font-weight:800;color:var(--gray-900);margin-bottom:4px;">
            $<?= number_format($plan['price_monthly'], 2) ?> <span style="font-size:.85rem;font-weight:500;color:var(--gray-400);">/ month</span>
          </div>
          <div style="font-size:.85rem;color:var(--gray-500);margin-bottom:14px;">
            or $<?= number_format($plan['price_yearly'], 2) ?> / year
          </div>

          <div style="display:flex;gap:16px;font-size:.82rem;color:var(--gray-600);margin-bottom:14px;flex-wrap:wrap;">
            <div><strong><?= $plan['max_staff'] == -1 ? 'Unlimited' : $plan['max_staff'] ?></strong> staff</div>
            <div><strong><?= $plan['max_services'] == -1 ? 'Unlimited' : $plan['max_services'] ?></strong> services</div>
            <div><strong><?= $plan['max_appointments_per_month'] == -1 ? 'Unlimited' : number_format($plan['max_appointments_per_month']) ?></strong> appts/mo</div>
          </div>

          <?php if (!empty($features)): ?>
            <ul style="font-size:.82rem;color:var(--gray-600);line-height:1.8;margin-bottom:14px;padding-left:0;list-style:none;">
              <?php foreach ($features as $f): ?>
                <li>✓ <?= htmlspecialchars($f) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <div style="display:flex;gap:8px;">
            <button type="button" class="btn btn-outline btn-sm" style="flex:1;"
              onclick='editPlan(<?= json_encode($plan, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button>
            <form method="POST" style="flex:1;" onsubmit="return confirm('<?= $plan['is_active'] ? 'Deactivate' : 'Activate' ?> this plan?');">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="toggle_active">
              <input type="hidden" name="id" value="<?= $plan['id'] ?>">
              <button type="submit" class="btn btn-outline btn-sm" style="width:100%;"><?= $plan['is_active'] ? 'Deactivate' : 'Activate' ?></button>
            </form>
            <form method="POST" style="flex:1;" onsubmit="return confirm('Delete this plan permanently?');">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $plan['id'] ?>">
              <button type="submit" class="btn btn-outline btn-sm" style="width:100%;color:var(--danger);">Delete</button>
            </form>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- ── Plan Modal ─────────────────────────────────────── -->
<div class="modal-overlay" id="planModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div class="modal-title" id="planModalTitle">Add Plan</div>
      <button class="modal-close" data-modal-close>✕</button>
    </div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" id="plan_id" value="">
      <div class="modal-body">

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Plan Name *</label>
            <input type="text" name="name" id="plan_name" class="form-control" placeholder="e.g. Professional" required>
          </div>
          <div class="form-group">
            <label class="form-label">Slug *</label>
            <input type="text" name="slug" id="plan_slug" class="form-control" placeholder="e.g. pro" required>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Monthly Price ($) *</label>
            <input type="number" name="price_monthly" id="plan_price_monthly" class="form-control" step="0.01" min="0" required>
          </div>
          <div class="form-group">
            <label class="form-label">Yearly Price ($) *</label>
            <input type="number" name="price_yearly" id="plan_price_yearly" class="form-control" step="0.01" min="0" required>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Max Staff</label>
            <input type="number" name="max_staff" id="plan_max_staff" class="form-control" step="1" value="1">
            <div class="form-hint">Use -1 for unlimited.</div>
          </div>
          <div class="form-group">
            <label class="form-label">Max Services</label>
            <input type="number" name="max_services" id="plan_max_services" class="form-control" step="1" value="10">
            <div class="form-hint">Use -1 for unlimited.</div>
          </div>
          <div class="form-group">
            <label class="form-label">Max Appointments / Month</label>
            <input type="number" name="max_appointments_per_month" id="plan_max_appointments" class="form-control" step="1" value="100">
            <div class="form-hint">Use -1 for unlimited.</div>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Features (one per line)</label>
          <textarea name="features" id="plan_features" class="form-control" rows="6" placeholder="WhatsApp Booking Bot&#10;Service Catalog&#10;Basic Analytics"></textarea>
        </div>

        <div class="form-group">
          <label class="form-label">Status</label>
          <select name="is_active" id="plan_is_active" class="form-select">
            <option value="1">Active</option>
            <option value="0">Inactive</option>
          </select>
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary" id="planSubmitBtn">Save Plan</button>
      </div>
    </form>
  </div>
</div>

<script>
function resetPlanForm() {
  document.getElementById('planModalTitle').textContent = 'Add Plan';
  document.getElementById('plan_id').value = '';
  document.getElementById('plan_name').value = '';
  document.getElementById('plan_slug').value = '';
  document.getElementById('plan_price_monthly').value = '';
  document.getElementById('plan_price_yearly').value = '';
  document.getElementById('plan_max_staff').value = '1';
  document.getElementById('plan_max_services').value = '10';
  document.getElementById('plan_max_appointments').value = '100';
  document.getElementById('plan_features').value = '';
  document.getElementById('plan_is_active').value = '1';
  document.getElementById('planSubmitBtn').textContent = 'Save Plan';
}

function editPlan(plan) {
  document.getElementById('planModalTitle').textContent = 'Edit Plan';
  document.getElementById('plan_id').value = plan.id;
  document.getElementById('plan_name').value = plan.name;
  document.getElementById('plan_slug').value = plan.slug;
  document.getElementById('plan_price_monthly').value = parseFloat(plan.price_monthly).toFixed(2);
  document.getElementById('plan_price_yearly').value = parseFloat(plan.price_yearly).toFixed(2);
  document.getElementById('plan_max_staff').value = plan.max_staff;
  document.getElementById('plan_max_services').value = plan.max_services;
  document.getElementById('plan_max_appointments').value = plan.max_appointments_per_month;

  let features = [];
  try { features = JSON.parse(plan.features || '[]'); } catch (e) {}
  document.getElementById('plan_features').value = features.join('\n');

  document.getElementById('plan_is_active').value = plan.is_active ? '1' : '0';
  document.getElementById('planSubmitBtn').textContent = 'Update Plan';
  document.getElementById('planModal').classList.add('open');
}
</script>

<?php include __DIR__ . '/partials/foot.php'; ?>
