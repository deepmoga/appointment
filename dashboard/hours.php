<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

$business   = getCurrentBusiness();
$businessId = (int)$_SESSION['business_id'];

$SLOT_INTERVALS = [15 => '15 minutes', 20 => '20 minutes', 30 => '30 minutes', 45 => '45 minutes', 60 => '60 minutes'];

// ─── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        setFlash('error', 'Invalid request. Please try again.');
        header('Location: hours.php'); exit;
    }

    $action = post('action');
    $pdo    = db();

    if ($action === 'save_hours') {
        $hours = $_POST['hours'] ?? [];
        $stmt = $pdo->prepare("
            INSERT INTO business_hours (business_id, day_of_week, open_time, close_time, is_open, slot_interval, break_start, break_end)
            VALUES (?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE open_time=VALUES(open_time), close_time=VALUES(close_time),
                is_open=VALUES(is_open), slot_interval=VALUES(slot_interval),
                break_start=VALUES(break_start), break_end=VALUES(break_end)
        ");

        for ($d = 0; $d <= 6; $d++) {
            $isOpen     = isset($hours[$d]['is_open']) ? 1 : 0;
            $open       = $hours[$d]['open_time'] ?? '09:00';
            $close      = $hours[$d]['close_time'] ?? '17:00';
            $interval   = (int)($hours[$d]['slot_interval'] ?? 30);
            $hasBreak   = isset($hours[$d]['has_break']);
            $breakStart = $hasBreak && !empty($hours[$d]['break_start']) ? $hours[$d]['break_start'] : null;
            $breakEnd   = $hasBreak && !empty($hours[$d]['break_end'])   ? $hours[$d]['break_end']   : null;

            $stmt->execute([$businessId, $d, $open, $close, $isOpen, $interval, $breakStart, $breakEnd]);
        }
        setFlash('success', 'Business hours updated.');

    } elseif ($action === 'add_holiday') {
        $date      = post('holiday_date');
        $name      = post('name');
        $recurring = post('is_recurring') ? 1 : 0;

        if (empty($date)) {
            setFlash('error', 'Please select a date for the holiday.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO holidays (business_id, holiday_date, name, is_recurring) VALUES (?,?,?,?)");
            $stmt->execute([$businessId, $date, $name, $recurring]);
            setFlash('success', 'Holiday added.');
        }

    } elseif ($action === 'delete_holiday') {
        $id = (int)post('id');
        if (ownsRecord('holidays', $id, $businessId)) {
            $pdo->prepare("DELETE FROM holidays WHERE id=? AND business_id=?")->execute([$id, $businessId]);
            setFlash('success', 'Holiday removed.');
        }
    }

    header('Location: hours.php');
    exit;
}

// ─── Fetch data ──────────────────────────────────────────────────────────────
$stmt = db()->prepare("SELECT * FROM business_hours WHERE business_id = ? ORDER BY day_of_week ASC");
$stmt->execute([$businessId]);
$hoursMap = [];
foreach ($stmt->fetchAll() as $row) {
    $hoursMap[(int)$row['day_of_week']] = $row;
}

$stmt = db()->prepare("SELECT * FROM holidays WHERE business_id = ? ORDER BY holiday_date ASC");
$stmt->execute([$businessId]);
$holidays = $stmt->fetchAll();

$activeNav = 'hours';
$pageTitle = 'Business Hours';
include __DIR__ . '/partials/head.php';
?>

<p style="color:var(--gray-500);font-size:.9rem;max-width:620px;margin-bottom:20px;">
  Set your weekly availability and time-slot interval. Customers booking via WhatsApp will only see slots within these hours.
  Add holidays or blackout dates to block bookings on specific days.
</p>

<!-- ── Weekly Hours ────────────────────────────────────────── -->
<div class="card" style="margin-bottom:24px;">
  <div class="card-body">
    <h3 style="font-size:1.05rem;font-weight:700;color:var(--gray-900);margin-bottom:18px;">Weekly Hours</h3>

    <form method="POST" id="hoursForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_hours">

      <?php for ($d = 0; $d <= 6; $d++): ?>
        <?php
          $row        = $hoursMap[$d] ?? null;
          $isOpen     = $row ? (bool)$row['is_open'] : ($d !== 0 && $d !== 6);
          $openTime   = $row ? substr($row['open_time'], 0, 5) : '09:00';
          $closeTime  = $row ? substr($row['close_time'], 0, 5) : '17:00';
          $interval   = $row ? (int)$row['slot_interval'] : 30;
          $hasBreak   = $row && $row['break_start'] && $row['break_end'];
          $breakStart = $hasBreak ? substr($row['break_start'], 0, 5) : '13:00';
          $breakEnd   = $hasBreak ? substr($row['break_end'], 0, 5) : '14:00';
        ?>
        <div style="padding:14px 0;border-bottom:1px solid var(--gray-100);">
          <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
            <label class="toggle" style="flex-shrink:0;">
              <input type="checkbox" name="hours[<?= $d ?>][is_open]" id="day_open_<?= $d ?>" value="1" <?= $isOpen ? 'checked' : '' ?> onchange="toggleHoursRow(<?= $d ?>)">
              <span class="toggle-slider"></span>
            </label>
            <div style="font-weight:600;font-size:.9rem;color:var(--gray-900);width:100px;flex-shrink:0;"><?= dayName($d) ?></div>

            <div id="day_fields_<?= $d ?>" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;<?= $isOpen ? '' : 'opacity:.4;' ?>">
              <input type="time" name="hours[<?= $d ?>][open_time]" id="day_open_time_<?= $d ?>" class="form-control" style="max-width:130px;" value="<?= $openTime ?>" <?= $isOpen ? '' : 'disabled' ?>>
              <span style="color:var(--gray-400);font-size:.85rem;">to</span>
              <input type="time" name="hours[<?= $d ?>][close_time]" id="day_close_time_<?= $d ?>" class="form-control" style="max-width:130px;" value="<?= $closeTime ?>" <?= $isOpen ? '' : 'disabled' ?>>

              <select name="hours[<?= $d ?>][slot_interval]" id="day_interval_<?= $d ?>" class="form-select" style="max-width:140px;" <?= $isOpen ? '' : 'disabled' ?>>
                <?php foreach ($SLOT_INTERVALS as $val => $label): ?>
                  <option value="<?= $val ?>" <?= $interval == $val ? 'selected' : '' ?>><?= $label ?> slots</option>
                <?php endforeach; ?>
              </select>

              <label style="display:flex;align-items:center;gap:6px;font-size:.82rem;color:var(--gray-500);background:var(--gray-100);padding:6px 12px;border-radius:100px;cursor:pointer;">
                <input type="checkbox" name="hours[<?= $d ?>][has_break]" id="day_hasbreak_<?= $d ?>" value="1" <?= $hasBreak ? 'checked' : '' ?> <?= $isOpen ? '' : 'disabled' ?> onchange="toggleBreakFields(<?= $d ?>)" style="accent-color:var(--primary);">
                Break
              </label>

              <span id="day_break_fields_<?= $d ?>" style="display:<?= $hasBreak ? 'flex' : 'none' ?>;align-items:center;gap:8px;">
                <input type="time" name="hours[<?= $d ?>][break_start]" id="day_break_start_<?= $d ?>" class="form-control" style="max-width:130px;" value="<?= $breakStart ?>" <?= $isOpen ? '' : 'disabled' ?>>
                <span style="color:var(--gray-400);font-size:.85rem;">to</span>
                <input type="time" name="hours[<?= $d ?>][break_end]" id="day_break_end_<?= $d ?>" class="form-control" style="max-width:130px;" value="<?= $breakEnd ?>" <?= $isOpen ? '' : 'disabled' ?>>
              </span>
            </div>

            <?php if (!$isOpen): ?>
              <span class="hours-closed-label" id="day_closed_label_<?= $d ?>">Closed</span>
            <?php else: ?>
              <span class="hours-closed-label" id="day_closed_label_<?= $d ?>" style="display:none;">Closed</span>
            <?php endif; ?>
          </div>
        </div>
      <?php endfor; ?>

      <div style="padding-top:18px;text-align:right;">
        <button type="submit" class="btn btn-primary">Save Business Hours</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Holidays / Blackout Dates ──────────────────────────── -->
<div class="card">
  <div class="card-body">
    <h3 style="font-size:1.05rem;font-weight:700;color:var(--gray-900);margin-bottom:6px;">Holidays &amp; Blackout Dates</h3>
    <p style="font-size:.85rem;color:var(--gray-500);margin-bottom:18px;">Bookings will not be available on these dates.</p>

    <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:22px;padding-bottom:22px;border-bottom:1px solid var(--gray-100);">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_holiday">
      <div class="form-group" style="margin:0;">
        <label class="form-label">Date</label>
        <input type="date" name="holiday_date" class="form-control" required>
      </div>
      <div class="form-group" style="flex:1;margin:0;min-width:160px;">
        <label class="form-label">Name (optional)</label>
        <input type="text" name="name" class="form-control" placeholder="e.g. New Year's Day">
      </div>
      <div class="form-group" style="margin:0;">
        <label class="form-label">&nbsp;</label>
        <label style="display:flex;align-items:center;gap:6px;font-size:.85rem;color:var(--gray-600);background:var(--gray-100);padding:10px 14px;border-radius:var(--radius-sm);cursor:pointer;white-space:nowrap;">
          <input type="checkbox" name="is_recurring" value="1" style="accent-color:var(--primary);">
          Repeats yearly
        </label>
      </div>
      <button type="submit" class="btn btn-primary">+ Add Holiday</button>
    </form>

    <?php if (empty($holidays)): ?>
      <p style="color:var(--gray-400);font-size:.85rem;">No holidays added yet.</p>
    <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:0;">
        <?php foreach ($holidays as $h): ?>
          <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 0;border-bottom:1px solid var(--gray-100);">
            <div>
              <div style="font-weight:600;font-size:.9rem;color:var(--gray-900);">
                <?= date('D, d M Y', strtotime($h['holiday_date'])) ?>
                <?php if ($h['is_recurring']): ?>
                  <span style="font-size:.72rem;font-weight:600;color:var(--primary);background:var(--primary-50,#eef2ff);padding:2px 8px;border-radius:100px;margin-left:6px;">Yearly</span>
                <?php endif; ?>
              </div>
              <?php if ($h['name']): ?>
                <div style="font-size:.78rem;color:var(--gray-400);"><?= htmlspecialchars($h['name']) ?></div>
              <?php endif; ?>
            </div>
            <form method="POST" onsubmit="return confirm('Remove this holiday?');">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="delete_holiday">
              <input type="hidden" name="id" value="<?= $h['id'] ?>">
              <button type="submit" class="btn btn-sm" style="background:#fee2e2;color:#991b1b;border:none;">Remove</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
function toggleHoursRow(d) {
  const open = document.getElementById('day_open_' + d).checked;
  const fields = document.getElementById('day_fields_' + d);
  const closedLabel = document.getElementById('day_closed_label_' + d);

  fields.style.opacity = open ? '1' : '.4';
  closedLabel.style.display = open ? 'none' : 'inline';

  ['day_open_time_', 'day_close_time_', 'day_interval_', 'day_hasbreak_', 'day_break_start_', 'day_break_end_'].forEach(prefix => {
    const el = document.getElementById(prefix + d);
    if (el) el.disabled = !open;
  });
}

function toggleBreakFields(d) {
  const has = document.getElementById('day_hasbreak_' + d).checked;
  document.getElementById('day_break_fields_' + d).style.display = has ? 'flex' : 'none';
}
</script>

<?php include __DIR__ . '/partials/foot.php'; ?>
