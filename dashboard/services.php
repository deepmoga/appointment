<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

$business   = getCurrentBusiness();
$businessId = (int)$_SESSION['business_id'];

// ─── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        setFlash('error', 'Invalid request. Please try again.');
        header('Location: services.php'); exit;
    }

    $action = post('action');

    if ($action === 'save') {
        $id              = (int)post('id');
        $categoryId      = (int)post('category_id');
        $name            = post('name');
        $description     = post('description');
        $duration        = max(5, (int)post('duration'));
        $bufferTime      = max(0, (int)post('buffer_time'));
        $price           = (float)post('price');
        $maxAdvanceDays  = max(1, (int)post('max_advance_days'));
        $staffIds        = isset($_POST['staff_ids']) ? array_map('intval', $_POST['staff_ids']) : [];

        if (empty($name) || $categoryId <= 0) {
            setFlash('error', 'Service name and category are required.');
        } elseif (!ownsRecord('service_categories', $categoryId, $businessId)) {
            setFlash('error', 'Invalid category selected.');
        } else {
            $pdo = db();
            if ($id && ownsRecord('services', $id, $businessId)) {
                $stmt = $pdo->prepare("UPDATE services SET category_id=?, name=?, description=?, duration=?, buffer_time=?, price=?, max_advance_days=? WHERE id=? AND business_id=?");
                $stmt->execute([$categoryId, $name, $description, $duration, $bufferTime, $price, $maxAdvanceDays, $id, $businessId]);
                $serviceId = $id;
                setFlash('success', 'Service updated successfully.');
            } else {
                $stmt = $pdo->prepare("INSERT INTO services (business_id, category_id, name, description, duration, buffer_time, price, max_advance_days) VALUES (?,?,?,?,?,?,?,?)");
                $stmt->execute([$businessId, $categoryId, $name, $description, $duration, $bufferTime, $price, $maxAdvanceDays]);
                $serviceId = (int)$pdo->lastInsertId();
                setFlash('success', 'Service created successfully.');
            }

            // Sync staff assignments
            $pdo->prepare("DELETE FROM service_staff WHERE service_id = ?")->execute([$serviceId]);
            if (!empty($staffIds)) {
                $insStmt = $pdo->prepare("INSERT IGNORE INTO service_staff (service_id, staff_id) VALUES (?,?)");
                foreach ($staffIds as $sid) {
                    if (ownsRecord('staff', $sid, $businessId)) {
                        $insStmt->execute([$serviceId, $sid]);
                    }
                }
            }
        }
    } elseif ($action === 'toggle') {
        $id = (int)post('id');
        if (ownsRecord('services', $id, $businessId)) {
            db()->prepare("UPDATE services SET is_active = 1 - is_active WHERE id=? AND business_id=?")->execute([$id, $businessId]);
            setFlash('success', 'Service status updated.');
        }
    } elseif ($action === 'delete') {
        $id = (int)post('id');
        if (ownsRecord('services', $id, $businessId)) {
            $stmt = db()->prepare("SELECT COUNT(*) FROM appointments WHERE service_id = ?");
            $stmt->execute([$id]);
            if ((int)$stmt->fetchColumn() > 0) {
                setFlash('error', 'Cannot delete: this service has existing appointments. Disable it instead.');
            } else {
                db()->prepare("DELETE FROM services WHERE id=? AND business_id=?")->execute([$id, $businessId]);
                setFlash('success', 'Service deleted.');
            }
        }
    }

    header('Location: services.php');
    exit;
}

// ─── Filters ────────────────────────────────────────────────────────────────
$filterCategory = (int)get('category', '0');
$search         = get('q');

// ─── Fetch data ──────────────────────────────────────────────────────────────
$categories = getCategories($businessId);
$staffList  = getStaffList($businessId, true);

$sql = "SELECT s.*, c.name AS category_name, c.icon AS category_icon
        FROM services s
        LEFT JOIN service_categories c ON c.id = s.category_id
        WHERE s.business_id = ?";
$params = [$businessId];

if ($filterCategory > 0) {
    $sql .= " AND s.category_id = ?";
    $params[] = $filterCategory;
}
if ($search !== '') {
    $sql .= " AND s.name LIKE ?";
    $params[] = '%' . $search . '%';
}
$sql .= " ORDER BY c.sort_order ASC, s.sort_order ASC, s.name ASC";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$services = $stmt->fetchAll();

// Pre-fetch staff assignments for all services
$serviceStaffMap = [];
if (!empty($services)) {
    $ids = array_column($services, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT service_id, staff_id FROM service_staff WHERE service_id IN ($in)");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) {
        $serviceStaffMap[$row['service_id']][] = (int)$row['staff_id'];
    }
}

$currency = $business['currency'] ?? 'USD';

$activeNav = 'services';
$pageTitle = 'Services';
include __DIR__ . '/partials/head.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
  <p style="color:var(--gray-500);font-size:.9rem;max-width:520px;">
    Add the services your business offers. Customers will see name, price, and duration when booking via WhatsApp.
  </p>
  <?php if (empty($categories)): ?>
    <a href="categories.php" class="btn btn-primary">+ Add Category First</a>
  <?php else: ?>
    <button class="btn btn-primary" data-modal-open="serviceModal" onclick="resetServiceForm()">+ Add Service</button>
  <?php endif; ?>
</div>

<?php if (empty($categories)): ?>
  <div class="card">
    <div class="empty-state">
      <div class="empty-state-emoji">🗂️</div>
      <div class="empty-state-title">Create a category first</div>
      <div class="empty-state-desc">Services must belong to a category. Add at least one category to get started.</div>
      <a href="categories.php" class="btn btn-primary btn-sm">Go to Categories</a>
    </div>
  </div>
<?php else: ?>

<!-- Filters -->
<form method="GET" class="filter-bar">
  <div class="search-box">
    <span class="search-box-icon">🔍</span>
    <input type="text" name="q" placeholder="Search services…" value="<?= htmlspecialchars($search) ?>">
  </div>
  <select name="category" class="filter-select" onchange="this.form.submit()">
    <option value="0">All Categories</option>
    <?php foreach ($categories as $cat): ?>
      <option value="<?= $cat['id'] ?>" <?= $filterCategory == $cat['id'] ? 'selected' : '' ?>>
        <?= htmlspecialchars($cat['icon'] . ' ' . $cat['name']) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn btn-outline btn-sm">Filter</button>
  <?php if ($filterCategory || $search): ?>
    <a href="services.php" class="btn btn-ghost btn-sm">Clear</a>
  <?php endif; ?>
</form>

<?php if (empty($services)): ?>
  <div class="card">
    <div class="empty-state">
      <div class="empty-state-emoji">🛍️</div>
      <div class="empty-state-title">No services found</div>
      <div class="empty-state-desc">Add your first service to start accepting bookings.</div>
      <button class="btn btn-primary btn-sm" data-modal-open="serviceModal" onclick="resetServiceForm()">+ Add Service</button>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div style="overflow-x:auto;">
      <table class="data-table">
        <thead>
          <tr>
            <th>Service</th>
            <th>Category</th>
            <th>Duration</th>
            <th>Price</th>
            <th>Staff</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($services as $svc): ?>
          <?php $assignedStaff = $serviceStaffMap[$svc['id']] ?? []; ?>
          <tr>
            <td>
              <div style="font-weight:600;color:var(--gray-900);"><?= htmlspecialchars($svc['name']) ?></div>
              <?php if ($svc['description']): ?>
                <div style="font-size:.78rem;color:var(--gray-400);max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($svc['description']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars(($svc['category_icon'] ?? '') . ' ' . ($svc['category_name'] ?? '—')) ?></td>
            <td>
              <?= (int)$svc['duration'] ?> min
              <?php if ($svc['buffer_time'] > 0): ?>
                <div style="font-size:.75rem;color:var(--gray-400);">+<?= (int)$svc['buffer_time'] ?> min buffer</div>
              <?php endif; ?>
            </td>
            <td style="font-weight:600;"><?= formatPrice($svc['price'], $currency) ?></td>
            <td>
              <?php if (empty($assignedStaff)): ?>
                <span style="font-size:.78rem;color:var(--gray-400);">Any staff</span>
              <?php else: ?>
                <span style="font-size:.78rem;color:var(--gray-600);"><?= count($assignedStaff) ?> assigned</span>
              <?php endif; ?>
            </td>
            <td>
              <label class="toggle">
                <form method="POST" id="toggle-<?= $svc['id'] ?>">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= $svc['id'] ?>">
                </form>
                <input type="checkbox" <?= $svc['is_active'] ? 'checked' : '' ?> onchange="document.getElementById('toggle-<?= $svc['id'] ?>').submit()">
                <span class="toggle-slider"></span>
              </label>
            </td>
            <td>
              <div style="display:flex;gap:6px;">
                <button class="btn btn-sm btn-outline"
                  onclick='editService(<?= json_encode(array_merge($svc, ["staff_ids" => $assignedStaff]), JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                  Edit
                </button>
                <form method="POST" onsubmit="return confirm('Delete this service?');">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= $svc['id'] ?>">
                  <button type="submit" class="btn btn-sm" style="background:#fee2e2;color:#991b1b;border:none;">Delete</button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
<?php endif; ?>

<!-- ── Service Modal ─────────────────────────────────────── -->
<div class="modal-overlay" id="serviceModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div class="modal-title" id="serviceModalTitle">Add Service</div>
      <button class="modal-close" data-modal-close>✕</button>
    </div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" id="svc_id" value="">
      <div class="modal-body">

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Service Name *</label>
            <input type="text" name="name" id="svc_name" class="form-control" placeholder="e.g. Haircut & Styling" required>
          </div>
          <div class="form-group">
            <label class="form-label">Category *</label>
            <select name="category_id" id="svc_category_id" class="form-select" required>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['icon'] . ' ' . $cat['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" id="svc_description" class="form-control" placeholder="Brief description shown to customers on WhatsApp"></textarea>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Price (<?= htmlspecialchars($currency) ?>) *</label>
            <input type="number" name="price" id="svc_price" class="form-control" step="0.01" min="0" placeholder="0.00" required>
          </div>
          <div class="form-group">
            <label class="form-label">Duration (minutes) *</label>
            <input type="number" name="duration" id="svc_duration" class="form-control" min="5" step="5" placeholder="30" required>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Buffer Time After (minutes)</label>
            <input type="number" name="buffer_time" id="svc_buffer_time" class="form-control" min="0" step="5" value="0">
            <div class="form-hint">Cleanup/prep time blocked after each booking.</div>
          </div>
          <div class="form-group">
            <label class="form-label">Max Advance Booking (days)</label>
            <input type="number" name="max_advance_days" id="svc_max_advance_days" class="form-control" min="1" value="30">
            <div class="form-hint">How far ahead customers can book.</div>
          </div>
        </div>

        <?php if (!empty($staffList)): ?>
        <div class="form-group">
          <label class="form-label">Assign Staff</label>
          <div style="display:flex;flex-wrap:wrap;gap:10px;">
            <?php foreach ($staffList as $st): ?>
              <label style="display:flex;align-items:center;gap:6px;background:var(--gray-100);padding:8px 12px;border-radius:100px;font-size:.85rem;cursor:pointer;">
                <input type="checkbox" name="staff_ids[]" value="<?= $st['id'] ?>" class="svc-staff-checkbox" style="accent-color:var(--primary);">
                <?= htmlspecialchars($st['name']) ?>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="form-hint">Leave all unchecked to allow any staff member to perform this service.</div>
        </div>
        <?php endif; ?>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary" id="serviceSubmitBtn">Save Service</button>
      </div>
    </form>
  </div>
</div>

<script>
function resetServiceForm() {
  document.getElementById('serviceModalTitle').textContent = 'Add Service';
  document.getElementById('svc_id').value = '';
  document.getElementById('svc_name').value = '';
  document.getElementById('svc_description').value = '';
  document.getElementById('svc_category_id').selectedIndex = 0;
  document.getElementById('svc_price').value = '';
  document.getElementById('svc_duration').value = '';
  document.getElementById('svc_buffer_time').value = '0';
  document.getElementById('svc_max_advance_days').value = '30';
  document.querySelectorAll('.svc-staff-checkbox').forEach(cb => cb.checked = false);
  document.getElementById('serviceSubmitBtn').textContent = 'Save Service';
}

function editService(svc) {
  document.getElementById('serviceModalTitle').textContent = 'Edit Service';
  document.getElementById('svc_id').value = svc.id;
  document.getElementById('svc_name').value = svc.name;
  document.getElementById('svc_description').value = svc.description || '';
  document.getElementById('svc_category_id').value = svc.category_id;
  document.getElementById('svc_price').value = parseFloat(svc.price).toFixed(2);
  document.getElementById('svc_duration').value = svc.duration;
  document.getElementById('svc_buffer_time').value = svc.buffer_time;
  document.getElementById('svc_max_advance_days').value = svc.max_advance_days;

  const staffIds = (svc.staff_ids || []).map(String);
  document.querySelectorAll('.svc-staff-checkbox').forEach(cb => {
    cb.checked = staffIds.includes(cb.value);
  });

  document.getElementById('serviceSubmitBtn').textContent = 'Update Service';
  document.getElementById('serviceModal').classList.add('open');
}
</script>

<?php include __DIR__ . '/partials/foot.php'; ?>
