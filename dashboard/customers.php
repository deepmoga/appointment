<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

$business   = getCurrentBusiness();
$businessId = (int)$_SESSION['business_id'];
$currency   = $business['currency'] ?? 'USD';

$STATUSES = [
    'pending'     => 'Pending',
    'confirmed'   => 'Confirmed',
    'in_progress' => 'In Progress',
    'completed'   => 'Completed',
    'cancelled'   => 'Cancelled',
    'no_show'     => 'No Show',
];

// ─── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        setFlash('error', 'Invalid request. Please try again.');
        header('Location: customers.php'); exit;
    }

    $action = post('action');
    $pdo    = db();

    if ($action === 'save') {
        $id      = (int)post('id');
        $name    = post('name');
        $phone   = post('phone');
        $email   = post('email');
        $dob     = post('date_of_birth');
        $gender  = post('gender');
        $tags    = post('tags');
        $notes   = post('notes');

        $validGenders = ['male','female','other','prefer_not'];
        if (!in_array($gender, $validGenders, true)) $gender = null;
        if (empty($dob)) $dob = null;

        if (empty($phone)) {
            setFlash('error', 'Phone number is required.');
        } else {
            // Check phone uniqueness within business
            $stmt = $pdo->prepare("SELECT id FROM customers WHERE business_id = ? AND phone = ? AND id != ?");
            $stmt->execute([$businessId, $phone, $id]);
            if ($stmt->fetch()) {
                setFlash('error', 'Another customer already uses this phone number.');
            } elseif ($id && ownsRecord('customers', $id, $businessId)) {
                $stmt = $pdo->prepare("UPDATE customers SET name=?, phone=?, email=?, date_of_birth=?, gender=?, tags=?, notes=? WHERE id=? AND business_id=?");
                $stmt->execute([$name, $phone, $email, $dob, $gender, $tags, $notes, $id, $businessId]);
                setFlash('success', 'Customer updated.');
            } else {
                $stmt = $pdo->prepare("INSERT INTO customers (business_id, name, phone, email, date_of_birth, gender, tags, notes) VALUES (?,?,?,?,?,?,?,?)");
                $stmt->execute([$businessId, $name, $phone, $email, $dob, $gender, $tags, $notes]);
                setFlash('success', 'Customer added.');
            }
        }

    } elseif ($action === 'toggle_block') {
        $id = (int)post('id');
        if (ownsRecord('customers', $id, $businessId)) {
            $pdo->prepare("UPDATE customers SET is_blocked = 1 - is_blocked WHERE id=? AND business_id=?")->execute([$id, $businessId]);
            setFlash('success', 'Customer status updated.');
        }

    } elseif ($action === 'delete') {
        $id = (int)post('id');
        if (ownsRecord('customers', $id, $businessId)) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE customer_id = ?");
            $stmt->execute([$id]);
            if ((int)$stmt->fetchColumn() > 0) {
                setFlash('error', 'Cannot delete: this customer has appointment history. Block them instead.');
            } else {
                $pdo->prepare("DELETE FROM customers WHERE id=? AND business_id=?")->execute([$id, $businessId]);
                setFlash('success', 'Customer removed.');
            }
        }
    }

    header('Location: customers.php?' . http_build_query($_GET));
    exit;
}

// ─── Filters ────────────────────────────────────────────────────────────────
$search      = get('q');
$filterBlock = get('status'); // '', 'active', 'blocked'
$sort        = get('sort', 'recent');
$page        = max(1, (int)get('page', '1'));
$perPage     = 20;

$where  = ["business_id = ?"];
$params = [$businessId];

if ($search !== '') {
    $where[] = "(name LIKE ? OR phone LIKE ? OR email LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if ($filterBlock === 'blocked') {
    $where[] = "is_blocked = 1";
} elseif ($filterBlock === 'active') {
    $where[] = "is_blocked = 0";
}
$whereSql = implode(' AND ', $where);

$stmt = db()->prepare("SELECT COUNT(*) FROM customers WHERE $whereSql");
$stmt->execute($params);
$totalRows  = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$orderBy = match ($sort) {
    'name'    => 'name ASC',
    'spent'   => 'total_spent DESC',
    'visits'  => 'total_visits DESC',
    default   => 'created_at DESC',
};

$stmt = db()->prepare("SELECT * FROM customers WHERE $whereSql ORDER BY $orderBy LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$customers = $stmt->fetchAll();

$activeNav = 'customers';
$pageTitle = 'Customers';
include __DIR__ . '/partials/head.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
  <p style="color:var(--gray-500);font-size:.9rem;max-width:520px;">
    Every customer who books via WhatsApp or is added manually appears here, along with their visit history and notes.
  </p>
  <button class="btn btn-primary" data-modal-open="customerModal" onclick="resetCustomerForm()">+ Add Customer</button>
</div>

<!-- Filters -->
<form method="GET" class="filter-bar">
  <div class="search-box">
    <span class="search-box-icon">🔍</span>
    <input type="text" name="q" placeholder="Search name, phone or email…" value="<?= htmlspecialchars($search) ?>">
  </div>
  <select name="status" class="filter-select" onchange="this.form.submit()">
    <option value="">All Customers</option>
    <option value="active" <?= $filterBlock === 'active' ? 'selected' : '' ?>>Active</option>
    <option value="blocked" <?= $filterBlock === 'blocked' ? 'selected' : '' ?>>Blocked</option>
  </select>
  <select name="sort" class="filter-select" onchange="this.form.submit()">
    <option value="recent" <?= $sort === 'recent' ? 'selected' : '' ?>>Recently Added</option>
    <option value="name"   <?= $sort === 'name'   ? 'selected' : '' ?>>Name (A–Z)</option>
    <option value="spent"  <?= $sort === 'spent'  ? 'selected' : '' ?>>Total Spent</option>
    <option value="visits" <?= $sort === 'visits' ? 'selected' : '' ?>>Total Visits</option>
  </select>
  <button type="submit" class="btn btn-outline btn-sm">Filter</button>
  <?php if ($search || $filterBlock || $sort !== 'recent'): ?>
    <a href="customers.php" class="btn btn-ghost btn-sm">Clear</a>
  <?php endif; ?>
</form>

<?php if (empty($customers)): ?>
  <div class="card">
    <div class="empty-state">
      <div class="empty-state-emoji">👤</div>
      <div class="empty-state-title">No customers found</div>
      <div class="empty-state-desc">Customers are added automatically when they book via WhatsApp, or you can add them manually.</div>
      <button class="btn btn-primary btn-sm" data-modal-open="customerModal" onclick="resetCustomerForm()">+ Add Customer</button>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div style="overflow-x:auto;">
      <table class="data-table">
        <thead>
          <tr>
            <th>Customer</th>
            <th>Tags</th>
            <th>Visits</th>
            <th>Total Spent</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($customers as $c): ?>
          <?php $initials = strtoupper(substr($c['name'] ?: $c['phone'], 0, 1)); ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:12px;">
                <div class="avatar" style="width:40px;height:40px;font-size:.9rem;background:#6366f1;"><?= htmlspecialchars($initials) ?></div>
                <div>
                  <div style="font-weight:600;color:var(--gray-900);"><?= htmlspecialchars($c['name'] ?: 'Unknown') ?></div>
                  <div style="font-size:.78rem;color:var(--gray-400);"><?= htmlspecialchars($c['phone']) ?></div>
                </div>
              </div>
            </td>
            <td>
              <?php if ($c['tags']): ?>
                <?php foreach (array_filter(array_map('trim', explode(',', $c['tags']))) as $tag): ?>
                  <span style="display:inline-block;font-size:.72rem;font-weight:600;color:var(--primary);background:var(--primary-50);padding:2px 8px;border-radius:100px;margin:2px 2px 0 0;"><?= htmlspecialchars($tag) ?></span>
                <?php endforeach; ?>
              <?php else: ?>
                <span style="color:var(--gray-400);">—</span>
              <?php endif; ?>
            </td>
            <td><?= (int)$c['total_visits'] ?></td>
            <td style="font-weight:600;"><?= formatPrice((float)$c['total_spent'], $currency) ?></td>
            <td>
              <?php if ($c['is_blocked']): ?>
                <span class="badge badge-cancelled">Blocked</span>
              <?php else: ?>
                <span class="badge badge-confirmed">Active</span>
              <?php endif; ?>
            </td>
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <button class="btn btn-sm btn-outline"
                  onclick='openProfile(<?= json_encode($c, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                  View
                </button>
                <form method="POST" onsubmit="return confirm('<?= $c['is_blocked'] ? 'Unblock' : 'Block' ?> this customer?');">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="toggle_block">
                  <input type="hidden" name="id" value="<?= $c['id'] ?>">
                  <button type="submit" class="btn btn-sm" style="background:<?= $c['is_blocked'] ? '#d1fae5' : '#fee2e2' ?>;color:<?= $c['is_blocked'] ? '#065f46' : '#991b1b' ?>;border:none;">
                    <?= $c['is_blocked'] ? 'Unblock' : 'Block' ?>
                  </button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <div class="pagination">
        <?php
          $qs = $_GET; unset($qs['page']);
          $base = '?' . http_build_query($qs) . (empty($qs) ? '' : '&');
        ?>
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
          <a href="<?= $base ?>page=<?= $p ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<!-- ── Add/Edit Customer Modal ───────────────────────────── -->
<div class="modal-overlay" id="customerModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div class="modal-title" id="customerModalTitle">Add Customer</div>
      <button class="modal-close" data-modal-close>✕</button>
    </div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" id="cu_id" value="">
      <div class="modal-body">

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Name</label>
            <input type="text" name="name" id="cu_name" class="form-control" placeholder="Customer name">
          </div>
          <div class="form-group">
            <label class="form-label">Phone *</label>
            <input type="text" name="phone" id="cu_phone" class="form-control" placeholder="e.g. +1 234 567 8900" required>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Email</label>
            <input type="email" name="email" id="cu_email" class="form-control" placeholder="customer@example.com">
          </div>
          <div class="form-group">
            <label class="form-label">Date of Birth</label>
            <input type="date" name="date_of_birth" id="cu_dob" class="form-control">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Gender</label>
            <select name="gender" id="cu_gender" class="form-select">
              <option value="">Not specified</option>
              <option value="male">Male</option>
              <option value="female">Female</option>
              <option value="other">Other</option>
              <option value="prefer_not">Prefer not to say</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Tags</label>
            <input type="text" name="tags" id="cu_tags" class="form-control" placeholder="e.g. VIP, Regular (comma separated)">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Notes</label>
          <textarea name="notes" id="cu_notes" class="form-control" placeholder="Internal notes about this customer"></textarea>
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary" id="customerSubmitBtn">Save Customer</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Customer Profile Modal ────────────────────────────── -->
<div class="modal-overlay" id="profileModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div class="modal-title" id="profileModalTitle">Customer Profile</div>
      <button class="modal-close" data-modal-close>✕</button>
    </div>
    <div class="modal-body">

      <div class="content-grid-2" style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px;">
        <div class="info-box">
          <div style="font-size:.78rem;color:var(--gray-400);">Total Visits</div>
          <div id="pf_visits" style="font-weight:700;font-size:1.2rem;color:var(--gray-900);"></div>
        </div>
        <div class="info-box">
          <div style="font-size:.78rem;color:var(--gray-400);">Total Spent</div>
          <div id="pf_spent" style="font-weight:700;font-size:1.2rem;color:var(--gray-900);"></div>
        </div>
        <div class="info-box">
          <div style="font-size:.78rem;color:var(--gray-400);">Loyalty Points</div>
          <div id="pf_loyalty" style="font-weight:700;font-size:1.2rem;color:var(--gray-900);"></div>
        </div>
      </div>

      <div class="tabs" data-panel-group="#profilePanels">
        <div class="tab active" data-tab="details">Details</div>
        <div class="tab" data-tab="history">Appointment History</div>
      </div>

      <div id="profilePanels">
        <div class="tab-panel active" data-panel="details">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="pf_id">

            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Name</label>
                <input type="text" name="name" id="pf_name" class="form-control">
              </div>
              <div class="form-group">
                <label class="form-label">Phone *</label>
                <input type="text" name="phone" id="pf_phone" class="form-control" required>
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" id="pf_email" class="form-control">
              </div>
              <div class="form-group">
                <label class="form-label">Date of Birth</label>
                <input type="date" name="date_of_birth" id="pf_dob" class="form-control">
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Gender</label>
                <select name="gender" id="pf_gender" class="form-select">
                  <option value="">Not specified</option>
                  <option value="male">Male</option>
                  <option value="female">Female</option>
                  <option value="other">Other</option>
                  <option value="prefer_not">Prefer not to say</option>
                </select>
              </div>
              <div class="form-group">
                <label class="form-label">Tags</label>
                <input type="text" name="tags" id="pf_tags" class="form-control" placeholder="comma separated">
              </div>
            </div>
            <div class="form-group">
              <label class="form-label">Notes</label>
              <textarea name="notes" id="pf_notes" class="form-control"></textarea>
            </div>
            <div style="text-align:right;">
              <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
          </form>
        </div>

        <div class="tab-panel" data-panel="history">
          <div id="pf_history">
            <p style="color:var(--gray-400);font-size:.85rem;">Loading…</p>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
const STATUS_LABELS = <?= json_encode($STATUSES, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function resetCustomerForm() {
  document.getElementById('customerModalTitle').textContent = 'Add Customer';
  document.getElementById('cu_id').value = '';
  document.getElementById('cu_name').value = '';
  document.getElementById('cu_phone').value = '';
  document.getElementById('cu_email').value = '';
  document.getElementById('cu_dob').value = '';
  document.getElementById('cu_gender').value = '';
  document.getElementById('cu_tags').value = '';
  document.getElementById('cu_notes').value = '';
  document.getElementById('customerSubmitBtn').textContent = 'Save Customer';
}

function openProfile(c) {
  document.getElementById('profileModalTitle').textContent = c.name || c.phone;

  document.getElementById('pf_visits').textContent = c.total_visits;
  document.getElementById('pf_spent').textContent = '<?= $currency ?> ' + parseFloat(c.total_spent).toFixed(2);
  document.getElementById('pf_loyalty').textContent = c.loyalty_points;

  document.getElementById('pf_id').value = c.id;
  document.getElementById('pf_name').value = c.name || '';
  document.getElementById('pf_phone').value = c.phone || '';
  document.getElementById('pf_email').value = c.email || '';
  document.getElementById('pf_dob').value = c.date_of_birth || '';
  document.getElementById('pf_gender').value = c.gender || '';
  document.getElementById('pf_tags').value = c.tags || '';
  document.getElementById('pf_notes').value = c.notes || '';

  // Reset to first tab
  document.querySelectorAll('#profileModal .tab').forEach((t,i) => t.classList.toggle('active', i===0));
  document.querySelectorAll('#profilePanels .tab-panel').forEach((p,i) => p.classList.toggle('active', i===0));

  // Load appointment history
  const historyEl = document.getElementById('pf_history');
  historyEl.innerHTML = '<p style="color:var(--gray-400);font-size:.85rem;">Loading…</p>';

  fetch(`ajax_customer_history.php?customer_id=${c.id}`)
    .then(r => r.json())
    .then(data => {
      if (!data.appointments || data.appointments.length === 0) {
        historyEl.innerHTML = '<p style="color:var(--gray-400);font-size:.85rem;">No appointment history yet.</p>';
        return;
      }
      let html = '<table class="data-table"><thead><tr><th>Date</th><th>Service</th><th>Staff</th><th>Price</th><th>Status</th></tr></thead><tbody>';
      data.appointments.forEach(a => {
        const label = STATUS_LABELS[a.status] || a.status;
        html += `<tr>
          <td>${a.appointment_date}<div style="font-size:.78rem;color:var(--gray-400);">${a.appointment_time.substring(0,5)}</div></td>
          <td>${a.service_name || '—'}</td>
          <td>${a.staff_name || 'Any'}</td>
          <td><?= $currency ?> ${parseFloat(a.total_price).toFixed(2)}</td>
          <td><span class="badge badge-${a.status}">${label}</span></td>
        </tr>`;
      });
      html += '</tbody></table>';
      historyEl.innerHTML = html;
    })
    .catch(() => {
      historyEl.innerHTML = '<p style="color:#991b1b;font-size:.85rem;">Could not load history.</p>';
    });

  document.getElementById('profileModal').classList.add('open');
}
</script>

<?php include __DIR__ . '/partials/foot.php'; ?>
