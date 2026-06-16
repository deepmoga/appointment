<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

$business   = getCurrentBusiness();
$businessId = (int)$_SESSION['business_id'];

$COLORS = ['#6366f1','#25D366','#f59e0b','#ef4444','#3b82f6','#8b5cf6','#10b981','#ec4899','#14b8a6','#f97316'];

// ─── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        setFlash('error', 'Invalid request. Please try again.');
        header('Location: staff.php'); exit;
    }

    $action = post('action');
    $pdo    = db();

    if ($action === 'save') {
        $id             = (int)post('id');
        $name           = post('name');
        $email          = post('email');
        $phone          = post('phone');
        $role           = post('role');
        $specialization = post('specialization');
        $bio            = post('bio');
        $color          = post('color') ?: '#6366f1';

        if (empty($name)) {
            setFlash('error', 'Staff name is required.');
        } else {
            if ($id && ownsRecord('staff', $id, $businessId)) {
                $stmt = $pdo->prepare("UPDATE staff SET name=?, email=?, phone=?, role=?, specialization=?, bio=?, color=? WHERE id=? AND business_id=?");
                $stmt->execute([$name, $email, $phone, $role, $specialization, $bio, $color, $id, $businessId]);
                setFlash('success', 'Staff member updated.');
            } else {
                $stmt = $pdo->prepare("INSERT INTO staff (business_id, name, email, phone, role, specialization, bio, color) VALUES (?,?,?,?,?,?,?,?)");
                $stmt->execute([$businessId, $name, $email, $phone, $role, $specialization, $bio, $color]);
                $newId = (int)$pdo->lastInsertId();

                // Default schedule: Mon–Sat 09:00–18:00, Sunday off
                $insStmt = $pdo->prepare("INSERT INTO staff_schedules (staff_id, day_of_week, start_time, end_time, is_working) VALUES (?,?,?,?,?)");
                for ($d = 0; $d <= 6; $d++) {
                    $insStmt->execute([$newId, $d, '09:00:00', '18:00:00', $d === 0 ? 0 : 1]);
                }
                setFlash('success', 'Staff member added.');
            }
        }
    } elseif ($action === 'toggle') {
        $id = (int)post('id');
        if (ownsRecord('staff', $id, $businessId)) {
            $pdo->prepare("UPDATE staff SET is_active = 1 - is_active WHERE id=? AND business_id=?")->execute([$id, $businessId]);
            setFlash('success', 'Staff status updated.');
        }
    } elseif ($action === 'delete') {
        $id = (int)post('id');
        if (ownsRecord('staff', $id, $businessId)) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE staff_id = ?");
            $stmt->execute([$id]);
            if ((int)$stmt->fetchColumn() > 0) {
                setFlash('error', 'Cannot delete: this staff member has appointment history. Deactivate instead.');
            } else {
                $pdo->prepare("DELETE FROM staff WHERE id=? AND business_id=?")->execute([$id, $businessId]);
                setFlash('success', 'Staff member removed.');
            }
        }
    } elseif ($action === 'save_schedule') {
        $staffId = (int)post('staff_id');
        if (ownsRecord('staff', $staffId, $businessId)) {
            $schedule = $_POST['schedule'] ?? [];
            $stmt = $pdo->prepare("INSERT INTO staff_schedules (staff_id, day_of_week, start_time, end_time, is_working) VALUES (?,?,?,?,?)
                ON DUPLICATE KEY UPDATE start_time=VALUES(start_time), end_time=VALUES(end_time), is_working=VALUES(is_working)");
            for ($d = 0; $d <= 6; $d++) {
                $isWorking = isset($schedule[$d]['is_working']) ? 1 : 0;
                $start     = $schedule[$d]['start_time'] ?? '09:00';
                $end       = $schedule[$d]['end_time'] ?? '18:00';
                $stmt->execute([$staffId, $d, $start, $end, $isWorking]);
            }
            setFlash('success', 'Weekly schedule updated.');
        }
    } elseif ($action === 'add_leave') {
        $staffId   = (int)post('staff_id');
        $leaveDate = post('leave_date');
        $leaveType = post('leave_type') ?: 'other';
        $reason    = post('reason');

        if (!ownsRecord('staff', $staffId, $businessId) || empty($leaveDate)) {
            setFlash('error', 'Please select a valid date.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO staff_leaves (staff_id, leave_date, leave_type, reason, approved) VALUES (?,?,?,?,1)");
            $stmt->execute([$staffId, $leaveDate, $leaveType, $reason]);
            setFlash('success', 'Time off added.');
        }
    } elseif ($action === 'delete_leave') {
        $leaveId = (int)post('leave_id');
        $stmt = $pdo->prepare("SELECT sl.id FROM staff_leaves sl INNER JOIN staff s ON s.id = sl.staff_id WHERE sl.id = ? AND s.business_id = ?");
        $stmt->execute([$leaveId, $businessId]);
        if ($stmt->fetch()) {
            $pdo->prepare("DELETE FROM staff_leaves WHERE id = ?")->execute([$leaveId]);
            setFlash('success', 'Time off removed.');
        }
    }

    header('Location: staff.php');
    exit;
}

// ─── Fetch data ──────────────────────────────────────────────────────────────
$staffMembers = getStaffList($businessId);

$schedulesMap = [];
$leavesMap    = [];
if (!empty($staffMembers)) {
    $ids = array_column($staffMembers, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));

    $stmt = db()->prepare("SELECT * FROM staff_schedules WHERE staff_id IN ($in) ORDER BY day_of_week ASC");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) {
        $schedulesMap[$row['staff_id']][(int)$row['day_of_week']] = $row;
    }

    $stmt = db()->prepare("SELECT * FROM staff_leaves WHERE staff_id IN ($in) ORDER BY leave_date DESC");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) {
        $leavesMap[$row['staff_id']][] = $row;
    }
}

// Service counts per staff
$serviceCounts = [];
if (!empty($staffMembers)) {
    $stmt = db()->prepare("SELECT staff_id, COUNT(*) AS cnt FROM service_staff GROUP BY staff_id");
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        $serviceCounts[(int)$row['staff_id']] = (int)$row['cnt'];
    }
}

$activeNav = 'staff';
$pageTitle = 'Staff';
include __DIR__ . '/partials/head.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
  <p style="color:var(--gray-500);font-size:.9rem;max-width:520px;">
    Manage your team — set who can perform which services, weekly working hours, and time off.
  </p>
  <button class="btn btn-primary" data-modal-open="staffModal" onclick="resetStaffForm()">+ Add Staff</button>
</div>

<?php if (empty($staffMembers)): ?>
  <div class="card">
    <div class="empty-state">
      <div class="empty-state-emoji">👥</div>
      <div class="empty-state-title">No staff members yet</div>
      <div class="empty-state-desc">Add your team so customers can be assigned to staff and you can manage individual schedules.</div>
      <button class="btn btn-primary btn-sm" data-modal-open="staffModal" onclick="resetStaffForm()">+ Add Staff</button>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div style="overflow-x:auto;">
      <table class="data-table">
        <thead>
          <tr>
            <th>Staff</th>
            <th>Role</th>
            <th>Contact</th>
            <th>Services</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($staffMembers as $st): ?>
          <?php
            $initials = strtoupper(substr($st['name'], 0, 1) . substr(strrchr($st['name'], ' ') ?: '', 1, 1));
            $schedule = $schedulesMap[$st['id']] ?? [];
            $leaves   = $leavesMap[$st['id']] ?? [];
          ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:12px;">
                <div class="avatar" style="width:40px;height:40px;font-size:.9rem;background:<?= htmlspecialchars($st['color']) ?>;"><?= htmlspecialchars($initials) ?></div>
                <div>
                  <div style="font-weight:600;color:var(--gray-900);"><?= htmlspecialchars($st['name']) ?></div>
                  <?php if ($st['specialization']): ?>
                    <div style="font-size:.78rem;color:var(--gray-400);"><?= htmlspecialchars($st['specialization']) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            </td>
            <td><?= htmlspecialchars($st['role'] ?: '—') ?></td>
            <td>
              <?php if ($st['phone']): ?><div style="font-size:.85rem;"><?= htmlspecialchars($st['phone']) ?></div><?php endif; ?>
              <?php if ($st['email']): ?><div style="font-size:.78rem;color:var(--gray-400);"><?= htmlspecialchars($st['email']) ?></div><?php endif; ?>
              <?php if (!$st['phone'] && !$st['email']): ?>—<?php endif; ?>
            </td>
            <td><?= $serviceCounts[$st['id']] ?? 0 ?></td>
            <td>
              <label class="toggle">
                <form method="POST" id="toggle-<?= $st['id'] ?>">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= $st['id'] ?>">
                </form>
                <input type="checkbox" <?= $st['is_active'] ? 'checked' : '' ?> onchange="document.getElementById('toggle-<?= $st['id'] ?>').submit()">
                <span class="toggle-slider"></span>
              </label>
            </td>
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <button class="btn btn-sm btn-outline"
                  onclick='openSchedule(<?= (int)$st['id'] ?>, <?= json_encode($st['name'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode(array_values($schedule), JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($leaves, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                  Schedule
                </button>
                <button class="btn btn-sm btn-outline"
                  onclick='editStaff(<?= json_encode($st, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                  Edit
                </button>
                <form method="POST" onsubmit="return confirm('Remove this staff member?');">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= $st['id'] ?>">
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

<!-- ── Staff Modal (Add/Edit) ─────────────────────────────── -->
<div class="modal-overlay" id="staffModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div class="modal-title" id="staffModalTitle">Add Staff Member</div>
      <button class="modal-close" data-modal-close>✕</button>
    </div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" id="st_id" value="">
      <div class="modal-body">

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Full Name *</label>
            <input type="text" name="name" id="st_name" class="form-control" placeholder="e.g. Dr. Sarah Khan" required>
          </div>
          <div class="form-group">
            <label class="form-label">Role / Title</label>
            <input type="text" name="role" id="st_role" class="form-control" placeholder="e.g. Dentist, Stylist, Trainer">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Email</label>
            <input type="email" name="email" id="st_email" class="form-control" placeholder="staff@example.com">
          </div>
          <div class="form-group">
            <label class="form-label">Phone</label>
            <input type="text" name="phone" id="st_phone" class="form-control" placeholder="+1 234 567 8900">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Specialization</label>
          <input type="text" name="specialization" id="st_specialization" class="form-control" placeholder="e.g. Cosmetic Dentistry, Hair Coloring">
        </div>

        <div class="form-group">
          <label class="form-label">Bio</label>
          <textarea name="bio" id="st_bio" class="form-control" placeholder="Short bio shown to customers (optional)"></textarea>
        </div>

        <div class="form-group">
          <label class="form-label">Avatar Color</label>
          <div class="color-options" data-input="st_color">
            <?php foreach ($COLORS as $i => $color): ?>
              <div class="color-option <?= $i === 0 ? 'selected' : '' ?>" data-value="<?= $color ?>" style="background:<?= $color ?>"></div>
            <?php endforeach; ?>
          </div>
          <input type="hidden" name="color" id="st_color" value="#6366f1">
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary" id="staffSubmitBtn">Save Staff Member</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Schedule / Time Off Modal ──────────────────────────── -->
<div class="modal-overlay" id="scheduleModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div class="modal-title" id="scheduleModalTitle">Schedule</div>
      <button class="modal-close" data-modal-close>✕</button>
    </div>
    <div class="modal-body">

      <div class="tabs" data-panel-group="#schedulePanels">
        <div class="tab active" data-tab="hours">Weekly Hours</div>
        <div class="tab" data-tab="leave">Time Off</div>
      </div>

      <div id="schedulePanels">

        <!-- Weekly Hours -->
        <div class="tab-panel active" data-panel="hours">
          <form method="POST" id="scheduleForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_schedule">
            <input type="hidden" name="staff_id" id="sched_staff_id">

            <?php for ($d = 0; $d <= 6; $d++): ?>
              <div style="display:flex;align-items:center;gap:14px;padding:12px 0;border-bottom:1px solid var(--gray-100);flex-wrap:wrap;">
                <label class="toggle" style="flex-shrink:0;">
                  <input type="checkbox" name="schedule[<?= $d ?>][is_working]" id="day_working_<?= $d ?>" value="1" onchange="toggleDayInputs(<?= $d ?>)">
                  <span class="toggle-slider"></span>
                </label>
                <div style="font-weight:600;font-size:.9rem;color:var(--gray-900);width:100px;flex-shrink:0;"><?= dayName($d) ?></div>
                <div style="display:flex;align-items:center;gap:8px;" id="day_times_<?= $d ?>">
                  <input type="time" name="schedule[<?= $d ?>][start_time]" id="day_start_<?= $d ?>" class="form-control" style="max-width:140px;">
                  <span style="color:var(--gray-400);font-size:.85rem;">to</span>
                  <input type="time" name="schedule[<?= $d ?>][end_time]" id="day_end_<?= $d ?>" class="form-control" style="max-width:140px;">
                </div>
              </div>
            <?php endfor; ?>

            <div style="padding-top:18px;text-align:right;">
              <button type="submit" class="btn btn-primary">Save Weekly Hours</button>
            </div>
          </form>
        </div>

        <!-- Time Off -->
        <div class="tab-panel" data-panel="leave">
          <div id="leavesList" style="margin-bottom:18px;"></div>

          <form method="POST" style="display:flex;gap:10px;flex-wrap:end;align-items:flex-end;flex-wrap:wrap;border-top:1px solid var(--gray-100);padding-top:18px;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_leave">
            <input type="hidden" name="staff_id" id="sched_staff_id2">
            <div class="form-group" style="margin:0;">
              <label class="form-label">Date</label>
              <input type="date" name="leave_date" class="form-control" required>
            </div>
            <div class="form-group" style="margin:0;">
              <label class="form-label">Type</label>
              <select name="leave_type" class="form-select">
                <option value="vacation">Vacation</option>
                <option value="sick">Sick</option>
                <option value="personal">Personal</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="form-group" style="flex:1;margin:0;min-width:160px;">
              <label class="form-label">Reason (optional)</label>
              <input type="text" name="reason" class="form-control" placeholder="e.g. Annual leave">
            </div>
            <button type="submit" class="btn btn-primary">+ Add Time Off</button>
          </form>
        </div>

      </div>
    </div>
  </div>
</div>

<script>
const LEAVE_TYPE_LABELS = { vacation: 'Vacation', sick: 'Sick Leave', personal: 'Personal', other: 'Other' };

function resetStaffForm() {
  document.getElementById('staffModalTitle').textContent = 'Add Staff Member';
  document.getElementById('st_id').value = '';
  document.getElementById('st_name').value = '';
  document.getElementById('st_role').value = '';
  document.getElementById('st_email').value = '';
  document.getElementById('st_phone').value = '';
  document.getElementById('st_specialization').value = '';
  document.getElementById('st_bio').value = '';
  document.getElementById('st_color').value = '#6366f1';
  document.querySelectorAll('#staffModal .color-option').forEach((el,i) => el.classList.toggle('selected', i===0));
  document.getElementById('staffSubmitBtn').textContent = 'Save Staff Member';
}

function editStaff(st) {
  document.getElementById('staffModalTitle').textContent = 'Edit Staff Member';
  document.getElementById('st_id').value = st.id;
  document.getElementById('st_name').value = st.name;
  document.getElementById('st_role').value = st.role || '';
  document.getElementById('st_email').value = st.email || '';
  document.getElementById('st_phone').value = st.phone || '';
  document.getElementById('st_specialization').value = st.specialization || '';
  document.getElementById('st_bio').value = st.bio || '';
  document.getElementById('st_color').value = st.color || '#6366f1';
  document.querySelectorAll('#staffModal .color-option').forEach(el => el.classList.toggle('selected', el.dataset.value === st.color));
  document.getElementById('staffSubmitBtn').textContent = 'Update Staff Member';
  document.getElementById('staffModal').classList.add('open');
}

function toggleDayInputs(d) {
  const checked = document.getElementById('day_working_' + d).checked;
  document.getElementById('day_start_' + d).disabled = !checked;
  document.getElementById('day_end_' + d).disabled = !checked;
  document.getElementById('day_times_' + d).style.opacity = checked ? '1' : '.4';
}

function openSchedule(staffId, staffName, schedule, leaves) {
  document.getElementById('scheduleModalTitle').textContent = 'Schedule — ' + staffName;
  document.getElementById('sched_staff_id').value = staffId;
  document.getElementById('sched_staff_id2').value = staffId;

  // Build a lookup by day_of_week
  const byDay = {};
  (schedule || []).forEach(row => byDay[row.day_of_week] = row);

  for (let d = 0; d <= 6; d++) {
    const row = byDay[d];
    const isWorking = row ? !!parseInt(row.is_working) : (d !== 0);
    const start = row ? row.start_time.substring(0,5) : '09:00';
    const end   = row ? row.end_time.substring(0,5) : '18:00';

    document.getElementById('day_working_' + d).checked = isWorking;
    document.getElementById('day_start_' + d).value = start;
    document.getElementById('day_end_' + d).value = end;
    toggleDayInputs(d);
  }

  // Render leaves list
  const listEl = document.getElementById('leavesList');
  if (!leaves || leaves.length === 0) {
    listEl.innerHTML = '<p style="color:var(--gray-400);font-size:.85rem;">No time off scheduled.</p>';
  } else {
    let html = '';
    leaves.forEach(lv => {
      const label = LEAVE_TYPE_LABELS[lv.leave_type] || 'Other';
      html += `<div style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--gray-100);">
        <div>
          <div style="font-weight:600;font-size:.88rem;color:var(--gray-900);">${lv.leave_date} <span style="font-weight:500;color:var(--gray-400);">· ${label}</span></div>
          ${lv.reason ? `<div style="font-size:.78rem;color:var(--gray-400);">${lv.reason.replace(/</g,'&lt;')}</div>` : ''}
        </div>
        <form method="POST" onsubmit="return confirm('Remove this time off entry?');">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="action" value="delete_leave">
          <input type="hidden" name="leave_id" value="${lv.id}">
          <button type="submit" class="btn btn-sm" style="background:#fee2e2;color:#991b1b;border:none;">Remove</button>
        </form>
      </div>`;
    });
    listEl.innerHTML = html;
  }

  document.getElementById('scheduleModal').classList.add('open');
}
</script>

<?php include __DIR__ . '/partials/foot.php'; ?>
