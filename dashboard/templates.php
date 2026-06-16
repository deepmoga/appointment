<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/whatsapp.php';

requireAuth();

$business   = getCurrentBusiness();
$businessId = (int)$_SESSION['business_id'];

// ─── Built-in template definitions ─────────────────────────────────────────────
$TEMPLATE_TYPES = [
    'welcome' => [
        'label'       => 'Welcome Message',
        'icon'        => '👋',
        'description' => 'Sent when a new customer first messages your WhatsApp number.',
        'variables'   => ['business_name'],
    ],
    'booking_confirm' => [
        'label'       => 'Booking Confirmed',
        'icon'        => '✅',
        'description' => 'Sent when you confirm a customer\'s appointment.',
        'variables'   => ['customer_name', 'business_name', 'service_name', 'staff_name', 'date', 'time', 'price', 'appointment_number'],
    ],
    'booking_cancel' => [
        'label'       => 'Booking Cancelled',
        'icon'        => '❌',
        'description' => 'Sent when an appointment is cancelled.',
        'variables'   => ['customer_name', 'business_name', 'service_name', 'staff_name', 'date', 'time', 'price', 'appointment_number'],
    ],
    'reminder_24h' => [
        'label'       => '24-Hour Reminder',
        'icon'        => '⏰',
        'description' => 'Sent to customers 24 hours before their appointment.',
        'variables'   => ['customer_name', 'service_name', 'date', 'time'],
    ],
    'reminder_1h' => [
        'label'       => '1-Hour Reminder',
        'icon'        => '⏳',
        'description' => 'Sent to customers 1 hour before their appointment.',
        'variables'   => ['customer_name', 'service_name', 'time'],
    ],
    'follow_up' => [
        'label'       => 'Follow-Up / Thank You',
        'icon'        => '🙏',
        'description' => 'Sent after a completed appointment to thank the customer.',
        'variables'   => ['customer_name', 'business_name', 'service_name'],
    ],
    'review_request' => [
        'label'       => 'Review Request',
        'icon'        => '⭐',
        'description' => 'Sent after a visit to ask the customer for feedback.',
        'variables'   => ['customer_name', 'business_name', 'service_name'],
    ],
];

$ALL_VARS = [];
foreach ($TEMPLATE_TYPES as $info) { $ALL_VARS = array_merge($ALL_VARS, $info['variables']); }
$ALL_VARS = array_values(array_unique($ALL_VARS));

// ─── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        setFlash('error', 'Invalid request. Please try again.');
        header('Location: templates.php'); exit;
    }

    $action = post('action');

    if ($action === 'save_builtin') {
        $type    = post('type');
        $content = trim(post('content'));

        if (!isset($TEMPLATE_TYPES[$type])) {
            setFlash('error', 'Invalid template type.');
        } elseif ($content === '') {
            setFlash('error', 'Template content cannot be empty.');
        } else {
            preg_match_all('/\{\{(\w+)\}\}/', $content, $m);
            $vars = array_values(array_unique($m[1]));

            $stmt = db()->prepare("SELECT id FROM message_templates WHERE business_id = ? AND template_type = ? LIMIT 1");
            $stmt->execute([$businessId, $type]);
            $existingId = $stmt->fetchColumn();

            if ($existingId) {
                db()->prepare("UPDATE message_templates SET content = ?, variables = ?, is_active = 1 WHERE id = ? AND business_id = ?")
                    ->execute([$content, json_encode($vars), $existingId, $businessId]);
            } else {
                db()->prepare("INSERT INTO message_templates (business_id, template_name, template_type, content, variables, is_active) VALUES (?,?,?,?,?,1)")
                    ->execute([$businessId, $TEMPLATE_TYPES[$type]['label'], $type, $content, json_encode($vars)]);
            }
            setFlash('success', 'Template updated.');
        }

    } elseif ($action === 'reset_builtin') {
        $type = post('type');
        if (isset($TEMPLATE_TYPES[$type])) {
            db()->prepare("DELETE FROM message_templates WHERE business_id = ? AND template_type = ?")->execute([$businessId, $type]);
            setFlash('success', 'Template reset to default.');
        }

    } elseif ($action === 'save_custom') {
        $id      = (int)post('id');
        $name    = trim(post('name'));
        $content = trim(post('content'));

        if ($name === '' || $content === '') {
            setFlash('error', 'Name and content are required.');
        } else {
            preg_match_all('/\{\{(\w+)\}\}/', $content, $m);
            $vars = array_values(array_unique($m[1]));

            if ($id && ownsRecord('message_templates', $id, $businessId)) {
                db()->prepare("UPDATE message_templates SET template_name = ?, content = ?, variables = ? WHERE id = ? AND business_id = ?")
                    ->execute([$name, $content, json_encode($vars), $id, $businessId]);
                setFlash('success', 'Custom template updated.');
            } else {
                db()->prepare("INSERT INTO message_templates (business_id, template_name, template_type, content, variables, is_active) VALUES (?, ?, 'custom', ?, ?, 1)")
                    ->execute([$businessId, $name, $content, json_encode($vars)]);
                setFlash('success', 'Custom template created.');
            }
        }

    } elseif ($action === 'toggle_custom') {
        $id = (int)post('id');
        if (ownsRecord('message_templates', $id, $businessId)) {
            db()->prepare("UPDATE message_templates SET is_active = 1 - is_active WHERE id = ? AND business_id = ?")->execute([$id, $businessId]);
            setFlash('success', 'Template status updated.');
        }

    } elseif ($action === 'delete_custom') {
        $id = (int)post('id');
        if (ownsRecord('message_templates', $id, $businessId)) {
            db()->prepare("DELETE FROM message_templates WHERE id = ? AND business_id = ? AND template_type = 'custom'")->execute([$id, $businessId]);
            setFlash('success', 'Custom template deleted.');
        }
    }

    header('Location: templates.php');
    exit;
}

// ─── Fetch data ──────────────────────────────────────────────────────────────
$stmt = db()->prepare("SELECT * FROM message_templates WHERE business_id = ? AND template_type != 'custom' AND is_active = 1");
$stmt->execute([$businessId]);
$customRows = [];
foreach ($stmt->fetchAll() as $row) {
    $customRows[$row['template_type']] = $row;
}

$stmt = db()->prepare("SELECT * FROM message_templates WHERE business_id = ? AND template_type = 'custom' ORDER BY id DESC");
$stmt->execute([$businessId]);
$customTemplates = $stmt->fetchAll();

$SAMPLE_VARS = [
    'customer_name' => 'Sarah',
    'business_name' => $business['name'],
    'service_name'  => 'Haircut & Style',
    'staff_name'    => 'Alex',
    'date'          => date('D, M j', strtotime('+1 day')),
    'time'          => '2:30 PM',
    'price'         => formatPrice(45, $business['currency'] ?? 'USD'),
    'appointment_number' => '#000123',
];

$jsTemplates = [];
foreach ($TEMPLATE_TYPES as $type => $info) {
    $content = $customRows[$type]['content'] ?? DEFAULT_WA_TEMPLATES[$type];
    $jsTemplates[$type] = [
        'label'     => $info['label'],
        'icon'      => $info['icon'],
        'content'   => $content,
        'variables' => $info['variables'],
    ];
}

$activeNav = 'templates';
$pageTitle = 'Message Templates';
include __DIR__ . '/partials/head.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
  <p style="color:var(--gray-500);font-size:.9rem;max-width:620px;">
    Customize the automatic WhatsApp messages sent to your customers. Use the variable buttons to insert dynamic details like the customer's name, service, date and time.
  </p>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px;margin-bottom:32px;">
  <?php foreach ($TEMPLATE_TYPES as $type => $info):
    $customized = isset($customRows[$type]);
    $content    = $customRows[$type]['content'] ?? DEFAULT_WA_TEMPLATES[$type];
    $preview    = renderTemplate($content, $SAMPLE_VARS);
  ?>
    <div class="card">
      <div class="card-body">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;gap:10px;">
          <div style="display:flex;align-items:center;gap:10px;">
            <div style="font-size:1.5rem;"><?= $info['icon'] ?></div>
            <div>
              <div style="font-weight:700;color:var(--gray-900);"><?= htmlspecialchars($info['label']) ?></div>
              <div style="font-size:.78rem;color:var(--gray-400);max-width:220px;"><?= htmlspecialchars($info['description']) ?></div>
            </div>
          </div>
          <span class="badge <?= $customized ? 'badge-confirmed' : 'badge-no_show' ?>"><?= $customized ? 'Customized' : 'Default' ?></span>
        </div>

        <div class="wa-msg wa-msg-in" style="margin:0 0 14px;max-width:100%;background:var(--gray-50);box-shadow:none;">
          <?= htmlspecialchars($preview) ?>
        </div>

        <div style="display:flex;gap:8px;">
          <button class="btn btn-sm btn-outline" style="flex:1;" onclick="editTemplate('<?= $type ?>')">Edit</button>
          <?php if ($customized): ?>
            <form method="POST" style="flex:1;" onsubmit="return confirm('Reset this message back to its default text?');">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="reset_builtin">
              <input type="hidden" name="type" value="<?= $type ?>">
              <button type="submit" class="btn btn-sm" style="width:100%;background:#fee2e2;color:#991b1b;border:none;">Reset</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- ── Custom Templates ───────────────────────────────────────── -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px;">
  <div>
    <div style="font-weight:700;font-size:1.05rem;color:var(--gray-900);">Custom Templates</div>
    <p style="color:var(--gray-500);font-size:.85rem;margin:4px 0 0;max-width:520px;">Reusable message snippets you can copy into manual WhatsApp replies.</p>
  </div>
  <button class="btn btn-primary" data-modal-open="customModal" onclick="resetCustomForm()">+ Add Custom Template</button>
</div>

<?php if (empty($customTemplates)): ?>
  <div class="card">
    <div class="empty-state">
      <div class="empty-state-emoji">💬</div>
      <div class="empty-state-title">No custom templates yet</div>
      <div class="empty-state-desc">Create reusable message snippets for promotions, announcements, or common replies.</div>
      <button class="btn btn-primary btn-sm" data-modal-open="customModal" onclick="resetCustomForm()">+ Add Custom Template</button>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="card-body" style="padding:0;">
      <table class="data-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Content Preview</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($customTemplates as $tpl): ?>
            <tr>
              <td style="font-weight:600;color:var(--gray-900);"><?= htmlspecialchars($tpl['template_name']) ?></td>
              <td style="color:var(--gray-500);font-size:.82rem;max-width:320px;"><?= htmlspecialchars(mb_strimwidth($tpl['content'], 0, 70, '…')) ?></td>
              <td>
                <label class="toggle" title="<?= $tpl['is_active'] ? 'Active' : 'Inactive' ?>">
                  <form method="POST" id="toggle-<?= $tpl['id'] ?>">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="toggle_custom">
                    <input type="hidden" name="id" value="<?= $tpl['id'] ?>">
                  </form>
                  <input type="checkbox" <?= $tpl['is_active'] ? 'checked' : '' ?> onchange="document.getElementById('toggle-<?= $tpl['id'] ?>').submit()">
                  <span class="toggle-slider"></span>
                </label>
              </td>
              <td>
                <div style="display:flex;gap:8px;">
                  <button class="btn btn-sm btn-outline" onclick='editCustom(<?= json_encode($tpl, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button>
                  <form method="POST" onsubmit="return confirm('Delete this template? This cannot be undone.');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete_custom">
                    <input type="hidden" name="id" value="<?= $tpl['id'] ?>">
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

<!-- ── Built-in Template Modal ───────────────────────────────── -->
<div class="modal-overlay" id="templateModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div class="modal-title" id="templateModalTitle">Edit Template</div>
      <button class="modal-close" data-modal-close>✕</button>
    </div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_builtin">
      <input type="hidden" name="type" id="tpl_type" value="">
      <div class="modal-body">
        <div class="content-grid-2">
          <div>
            <div class="form-group">
              <label class="form-label">Message Content</label>
              <textarea name="content" id="tpl_content" class="form-control" rows="10" oninput="updatePreview('tpl_content','tpl_preview')" required></textarea>
              <div class="form-hint">Use *text* for <b>bold</b> and emojis — formatting follows WhatsApp's text styling.</div>
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label">Insert Variable</label>
              <div id="tpl_vars" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
            </div>
          </div>
          <div>
            <label class="form-label">Live Preview</label>
            <div class="phone-screen" style="height:340px;">
              <div class="wa-topbar">
                <div class="wa-av"><?= htmlspecialchars(strtoupper(substr($business['name'], 0, 1))) ?></div>
                <div>
                  <div class="wa-info-name"><?= htmlspecialchars($business['name']) ?></div>
                  <div class="wa-info-status">online</div>
                </div>
              </div>
              <div class="wa-msgs">
                <div class="wa-msg wa-msg-in" id="tpl_preview"></div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Save Template</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Custom Template Modal ─────────────────────────────────── -->
<div class="modal-overlay" id="customModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div class="modal-title" id="customModalTitle">Add Custom Template</div>
      <button class="modal-close" data-modal-close>✕</button>
    </div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_custom">
      <input type="hidden" name="id" id="custom_id" value="">
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Template Name *</label>
          <input type="text" name="name" id="custom_name" class="form-control" placeholder="e.g. Holiday Promo" required>
        </div>
        <div class="content-grid-2">
          <div>
            <div class="form-group">
              <label class="form-label">Message Content *</label>
              <textarea name="content" id="custom_content" class="form-control" rows="9" oninput="updatePreview('custom_content','custom_preview')" required></textarea>
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label">Insert Variable</label>
              <div id="custom_vars" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
            </div>
          </div>
          <div>
            <label class="form-label">Live Preview</label>
            <div class="phone-screen" style="height:300px;">
              <div class="wa-topbar">
                <div class="wa-av"><?= htmlspecialchars(strtoupper(substr($business['name'], 0, 1))) ?></div>
                <div>
                  <div class="wa-info-name"><?= htmlspecialchars($business['name']) ?></div>
                  <div class="wa-info-status">online</div>
                </div>
              </div>
              <div class="wa-msgs">
                <div class="wa-msg wa-msg-in" id="custom_preview"></div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary" id="customSubmitBtn">Save Template</button>
      </div>
    </form>
  </div>
</div>

<script>
const TEMPLATES   = <?= json_encode($jsTemplates, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const ALL_VARS    = <?= json_encode($ALL_VARS) ?>;
const SAMPLE_VARS = <?= json_encode($SAMPLE_VARS, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

function renderSample(content) {
  return content.replace(/\{\{(\w+)\}\}/g, (m, key) => SAMPLE_VARS[key] !== undefined ? SAMPLE_VARS[key] : m);
}

function updatePreview(textareaId, previewId) {
  const ta = document.getElementById(textareaId);
  document.getElementById(previewId).textContent = renderSample(ta.value);
}

function insertAtCursor(textareaId, text, previewId) {
  const ta = document.getElementById(textareaId);
  const start = ta.selectionStart ?? ta.value.length;
  const end = ta.selectionEnd ?? ta.value.length;
  ta.value = ta.value.substring(0, start) + text + ta.value.substring(end);
  ta.focus();
  ta.selectionStart = ta.selectionEnd = start + text.length;
  updatePreview(textareaId, previewId);
}

function renderVarButtons(containerId, variables, textareaId, previewId) {
  const container = document.getElementById(containerId);
  container.innerHTML = '';
  variables.forEach(v => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-sm btn-outline';
    btn.textContent = '+ {{' + v + '}}';
    btn.onclick = () => insertAtCursor(textareaId, '{{' + v + '}}', previewId);
    container.appendChild(btn);
  });
}

function editTemplate(type) {
  const tpl = TEMPLATES[type];
  document.getElementById('templateModalTitle').textContent = tpl.icon + ' ' + tpl.label;
  document.getElementById('tpl_type').value = type;
  document.getElementById('tpl_content').value = tpl.content;
  renderVarButtons('tpl_vars', tpl.variables, 'tpl_content', 'tpl_preview');
  updatePreview('tpl_content', 'tpl_preview');
  document.getElementById('templateModal').classList.add('open');
}

function resetCustomForm() {
  document.getElementById('customModalTitle').textContent = 'Add Custom Template';
  document.getElementById('custom_id').value = '';
  document.getElementById('custom_name').value = '';
  document.getElementById('custom_content').value = '';
  document.getElementById('custom_preview').textContent = '';
  document.getElementById('customSubmitBtn').textContent = 'Save Template';
  renderVarButtons('custom_vars', ALL_VARS, 'custom_content', 'custom_preview');
}

function editCustom(tpl) {
  document.getElementById('customModalTitle').textContent = 'Edit Custom Template';
  document.getElementById('custom_id').value = tpl.id;
  document.getElementById('custom_name').value = tpl.template_name;
  document.getElementById('custom_content').value = tpl.content;
  renderVarButtons('custom_vars', ALL_VARS, 'custom_content', 'custom_preview');
  updatePreview('custom_content', 'custom_preview');
  document.getElementById('customModal').classList.add('open');
}

// Initialize variable buttons for the "Add Custom Template" modal on first load
renderVarButtons('custom_vars', ALL_VARS, 'custom_content', 'custom_preview');
</script>

<?php include __DIR__ . '/partials/foot.php'; ?>
