<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/whatsapp.php';

requireAuth();

$business   = getCurrentBusiness();
$businessId = (int)$_SESSION['business_id'];

/**
 * Calls the Graph API to verify the saved phone_number_id/access_token pair
 * and updates is_connected/phone_number/display_name accordingly.
 * Returns ['success' => bool, 'message' => string].
 */
function testWhatsappConnection(int $businessId): array {
    $config = getWhatsappConfig($businessId);

    if (!$config || empty($config['phone_number_id']) || empty($config['access_token'])) {
        return ['success' => false, 'message' => 'Please save your Phone Number ID and Access Token before testing the connection.'];
    }

    $url = "https://graph.facebook.com/v19.0/{$config['phone_number_id']}?fields=display_phone_number,verified_name";
    $decoded = null;
    $httpCode = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $config['access_token']],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded = $response ? json_decode($response, true) : null;
    }

    if ($httpCode >= 200 && $httpCode < 300 && $decoded && isset($decoded['display_phone_number'])) {
        db()->prepare("UPDATE whatsapp_configs SET is_connected = 1, phone_number = ?, display_name = ? WHERE business_id = ?")
            ->execute([$decoded['display_phone_number'], $decoded['verified_name'] ?? $config['display_name'], $businessId]);
        return ['success' => true, 'message' => '✅ Connection successful! Verified number: ' . $decoded['display_phone_number']];
    }

    db()->prepare("UPDATE whatsapp_configs SET is_connected = 0 WHERE business_id = ?")->execute([$businessId]);
    $errMsg = $decoded['error']['message'] ?? 'Could not connect. Please double-check your Phone Number ID and Access Token.';
    return ['success' => false, 'message' => '❌ Connection test failed: ' . $errMsg];
}

// ─── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        setFlash('error', 'Invalid request. Please try again.');
        header('Location: whatsapp.php'); exit;
    }

    $action = post('action');

    if ($action === 'save_config') {
        $phoneNumberId = trim(post('phone_number_id'));
        $wabaId        = trim(post('waba_id'));
        $accessToken   = trim(post('access_token'));
        $phoneNumber   = trim(post('phone_number'));
        $displayName   = trim(post('display_name'));

        $existing = getWhatsappConfig($businessId);

        // Keep the existing access token if the field was left blank (already saved)
        if ($accessToken === '' && $existing) {
            $accessToken = $existing['access_token'];
        }

        $verifyToken = $existing['webhook_verify_token'] ?? '';
        if ($verifyToken === '') {
            $verifyToken = bin2hex(random_bytes(16));
        }

        $stmt = db()->prepare("
            INSERT INTO whatsapp_configs (business_id, phone_number_id, waba_id, access_token, webhook_verify_token, phone_number, display_name, is_connected)
            VALUES (?,?,?,?,?,?,?,0)
            ON DUPLICATE KEY UPDATE
                phone_number_id = VALUES(phone_number_id),
                waba_id = VALUES(waba_id),
                access_token = VALUES(access_token),
                webhook_verify_token = VALUES(webhook_verify_token),
                phone_number = VALUES(phone_number),
                display_name = VALUES(display_name),
                is_connected = 0
        ");
        $stmt->execute([$businessId, $phoneNumberId, $wabaId, $accessToken, $verifyToken, $phoneNumber, $displayName]);

        if (post('test_now') === '1') {
            $result = testWhatsappConnection($businessId);
            setFlash($result['success'] ? 'success' : 'error', 'Settings saved. ' . $result['message']);
        } else {
            setFlash('success', 'WhatsApp settings saved. Click "Test Connection" to verify your credentials.');
        }
        header('Location: whatsapp.php?tab=setup'); exit;

    } elseif ($action === 'generate_token') {
        $newToken = bin2hex(random_bytes(16));
        $existing = getWhatsappConfig($businessId);

        if ($existing) {
            db()->prepare("UPDATE whatsapp_configs SET webhook_verify_token = ? WHERE business_id = ?")
                ->execute([$newToken, $businessId]);
        } else {
            db()->prepare("INSERT INTO whatsapp_configs (business_id, webhook_verify_token) VALUES (?, ?)")
                ->execute([$businessId, $newToken]);
        }

        setFlash('success', 'New webhook verify token generated. Update it in your Meta App\'s webhook configuration.');
        header('Location: whatsapp.php?tab=setup'); exit;

    } elseif ($action === 'test_connection') {
        $result = testWhatsappConnection($businessId);
        setFlash($result['success'] ? 'success' : 'error', $result['message']);
        header('Location: whatsapp.php?tab=setup'); exit;

    } elseif ($action === 'disconnect') {
        db()->prepare("UPDATE whatsapp_configs SET is_connected = 0 WHERE business_id = ?")->execute([$businessId]);
        setFlash('success', 'WhatsApp account disconnected.');
        header('Location: whatsapp.php?tab=setup'); exit;

    } elseif ($action === 'send_message') {
        $phone   = trim(post('phone'));
        $message = trim(post('message'));

        if ($phone === '' || $message === '') {
            setFlash('error', 'Please enter a message to send.');
        } elseif (!isWhatsappConnected($businessId)) {
            setFlash('error', 'Connect your WhatsApp Business account before sending messages.');
        } else {
            $ok = sendWhatsappMessage($businessId, $phone, $message);
            if (!$ok) {
                setFlash('error', 'Failed to send message. Please check your connection.');
            }
        }

        header('Location: whatsapp.php?tab=conversations&phone=' . urlencode($phone)); exit;
    }

    header('Location: whatsapp.php');
    exit;
}

// ─── Fetch data ──────────────────────────────────────────────────────────────
$config      = getWhatsappConfig($businessId);
$connected   = isWhatsappConnected($businessId);
$platform    = getPlatformSettings();
$webhookUrl  = APP_URL . '/api/webhook.php';
$verifyToken = $platform['wa_verify_token'] ?: ($config['webhook_verify_token'] ?? '');

$activeTab = (get('tab') === 'conversations') ? 'conversations' : 'setup';
$openPhone = get('phone');

$stmt = db()->prepare("
    SELECT t.customer_phone, t.last_time, t.unread_count, c.name AS customer_name,
        (SELECT content FROM whatsapp_messages m WHERE m.business_id = ? AND m.customer_phone = t.customer_phone ORDER BY m.created_at DESC LIMIT 1) AS last_message
    FROM (
        SELECT customer_phone, MAX(created_at) AS last_time,
               SUM(CASE WHEN direction = 'inbound' AND is_read = 0 THEN 1 ELSE 0 END) AS unread_count
        FROM whatsapp_messages
        WHERE business_id = ?
        GROUP BY customer_phone
    ) t
    LEFT JOIN customers c ON c.business_id = ? AND c.phone = t.customer_phone
    ORDER BY t.last_time DESC
");
$stmt->execute([$businessId, $businessId, $businessId]);
$conversations = $stmt->fetchAll();

if ($openPhone === '' && !empty($conversations)) {
    $openPhone = $conversations[0]['customer_phone'];
}

$activeNav = 'whatsapp';
$pageTitle = 'WhatsApp Setup';
include __DIR__ . '/partials/head.php';
?>

<div class="tabs" data-panel-group="#waPanels">
  <div class="tab <?= $activeTab === 'setup' ? 'active' : '' ?>" data-tab="setup">⚙️ Setup</div>
  <div class="tab <?= $activeTab === 'conversations' ? 'active' : '' ?>" data-tab="conversations">
    💬 Conversations
    <?php $totalUnread = array_sum(array_column($conversations, 'unread_count')); ?>
    <?php if ($totalUnread > 0): ?><span class="nav-item-badge" style="margin-left:6px;"><?= (int)$totalUnread ?></span><?php endif; ?>
  </div>
</div>

<div id="waPanels">

  <!-- ── Setup Tab ─────────────────────────────────────────── -->
  <div class="tab-panel <?= $activeTab === 'setup' ? 'active' : '' ?>" data-panel="setup">
    <div class="content-grid-2">

      <div>
        <div class="card" style="margin-bottom:20px;">
          <div class="card-body">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
              <div>
                <div style="font-weight:700;font-size:1.05rem;color:var(--gray-900);">WhatsApp Business API Connection</div>
                <div style="font-size:.85rem;color:var(--gray-500);">Connect your Meta WhatsApp Business Cloud API account.</div>
              </div>
              <span class="badge <?= $connected ? 'badge-confirmed' : 'badge-cancelled' ?>">
                <?= $connected ? '🟢 Connected' : '🔴 Not Connected' ?>
              </span>
            </div>

            <?php if ($connected): ?>
              <div style="display:flex;align-items:center;justify-content:space-between;background:var(--gray-50);border-radius:var(--radius-sm);padding:12px 16px;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
                <div>
                  <div style="font-weight:600;color:var(--gray-900);"><?= htmlspecialchars($config['display_name'] ?: 'WhatsApp Business') ?></div>
                  <div style="font-size:.82rem;color:var(--gray-500);"><?= htmlspecialchars($config['phone_number'] ?: '') ?></div>
                </div>
                <div style="display:flex;gap:8px;">
                  <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="test_connection">
                    <button type="submit" class="btn btn-sm btn-outline">Re-test</button>
                  </form>
                  <form method="POST" onsubmit="return confirm('Disconnect WhatsApp? Your bot will stop responding to customers.');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="disconnect">
                    <button type="submit" class="btn btn-sm" style="background:#fee2e2;color:#991b1b;border:none;">Disconnect</button>
                  </form>
                </div>
              </div>
            <?php endif; ?>

            <form method="POST">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="save_config">

              <div class="form-row">
                <div class="form-group">
                  <label class="form-label">Phone Number ID *</label>
                  <input type="text" name="phone_number_id" class="form-control" placeholder="e.g. 109876543210123" value="<?= htmlspecialchars($config['phone_number_id'] ?? '') ?>">
                  <div class="form-hint">From your Meta App → WhatsApp → API Setup.</div>
                </div>
                <div class="form-group">
                  <label class="form-label">WhatsApp Business Account ID</label>
                  <input type="text" name="waba_id" class="form-control" placeholder="e.g. 123456789012345" value="<?= htmlspecialchars($config['waba_id'] ?? '') ?>">
                </div>
              </div>

              <div class="form-group">
                <label class="form-label">Permanent Access Token *</label>
                <div class="input-group">
                  <input type="password" name="access_token" id="wa_access_token" class="form-control" placeholder="<?= !empty($config['access_token']) ? '••••••••••••••••••••••••' : 'Paste your access token' ?>" value="<?= htmlspecialchars($config['access_token'] ?? '') ?>">
                  <button type="button" class="input-group-icon toggle-password">👁</button>
                </div>
                <div class="form-hint">Leave blank when editing other fields to keep your existing token.</div>
              </div>

              <div class="form-row">
                <div class="form-group">
                  <label class="form-label">Display Name</label>
                  <input type="text" name="display_name" class="form-control" placeholder="e.g. Glow Salon" value="<?= htmlspecialchars($config['display_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                  <label class="form-label">WhatsApp Number</label>
                  <input type="text" name="phone_number" class="form-control" placeholder="e.g. +1 555 123 4567" value="<?= htmlspecialchars($config['phone_number'] ?? '') ?>">
                </div>
              </div>

              <div class="modal-footer" style="padding:0;border:none;justify-content:flex-start;gap:10px;margin-top:8px;">
                <button type="submit" class="btn btn-primary">Save Settings</button>
                <button type="submit" name="test_now" value="1" class="btn btn-outline">Save &amp; Test Connection</button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <div>
        <div class="card" style="margin-bottom:20px;">
          <div class="card-body">
            <div style="font-weight:700;color:var(--gray-900);margin-bottom:4px;">📡 Webhook Configuration</div>
            <p style="font-size:.82rem;color:var(--gray-500);margin-bottom:14px;">
              Use a single platform webhook — all WhatsApp numbers on this platform share it. Messages are routed automatically by your Phone Number ID.
            </p>
            <div class="form-group">
              <label class="form-label">Callback URL</label>
              <div class="input-group">
                <input type="text" class="form-control" id="webhookUrl" value="<?= htmlspecialchars($webhookUrl) ?>" readonly style="font-size:.78rem;">
                <button type="button" class="input-group-icon" onclick="copyField('webhookUrl', this)">📋</button>
              </div>
            </div>
            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label">Verify Token</label>
              <?php if ($verifyToken): ?>
              <div class="input-group">
                <input type="text" class="form-control" id="verifyToken" value="<?= htmlspecialchars($verifyToken) ?>" readonly style="font-size:.78rem;">
                <button type="button" class="input-group-icon" onclick="copyField('verifyToken', this)">📋</button>
              </div>
              <?php else: ?>
              <div class="info-box" style="font-size:.82rem;">⚠️ No verify token set. Ask your platform admin to generate one in the Superadmin → Platform Settings.</div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="info-box">
          <strong>📖 Setup Instructions (Shared Webhook)</strong><br><br>
          1. In <code>developers.facebook.com</code>, open your Meta App → WhatsApp → Configuration → Webhook.<br>
          2. Set <strong>Callback URL</strong> to the value above (one URL for all businesses).<br>
          3. Set <strong>Verify Token</strong> to the value above (shared platform token).<br>
          4. Subscribe to the <strong>messages</strong> webhook field.<br>
          5. Paste your <strong>Phone Number ID</strong> and <strong>Access Token</strong> in the form on the left, then click <em>Save &amp; Test Connection</em>.
        </div>
      </div>

    </div>
  </div>

  <!-- ── Conversations Tab ─────────────────────────────────── -->
  <div class="tab-panel <?= $activeTab === 'conversations' ? 'active' : '' ?>" data-panel="conversations">
    <div class="content-grid-2" style="grid-template-columns: 320px 1fr;">

      <div class="card">
        <div class="card-body" style="padding:10px;">
          <?php if (empty($conversations)): ?>
            <div class="empty-state" style="padding:30px 16px;">
              <div class="empty-state-emoji">💬</div>
              <div class="empty-state-title">No conversations yet</div>
              <div class="empty-state-desc">Messages from customers via WhatsApp will appear here.</div>
            </div>
          <?php else: ?>
            <div class="wa-conv-list">
              <?php foreach ($conversations as $c):
                $name = $c['customer_name'] ?: $c['customer_phone'];
                $isActive = $c['customer_phone'] === $openPhone;
              ?>
                <div class="wa-conv-item <?= $isActive ? 'active' : '' ?>"
                     onclick="openConversation('<?= htmlspecialchars($c['customer_phone'], ENT_QUOTES) ?>','<?= htmlspecialchars(addslashes($name), ENT_QUOTES) ?>', this)">
                  <div class="wa-conv-av"><?= htmlspecialchars(strtoupper(substr($name, 0, 2))) ?></div>
                  <div class="wa-conv-info">
                    <div class="wa-conv-name"><?= htmlspecialchars($name) ?></div>
                    <div class="wa-conv-preview"><?= htmlspecialchars(mb_strimwidth((string)($c['last_message'] ?? ''), 0, 42, '…')) ?></div>
                  </div>
                  <?php if ((int)$c['unread_count'] > 0): ?>
                    <div class="wa-unread-badge"><?= (int)$c['unread_count'] ?></div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div>
        <?php if (empty($conversations)): ?>
          <div class="card"><div class="wa-chat-empty">Conversations with your customers will show up here once they message your WhatsApp number.</div></div>
        <?php else: ?>
          <div class="wa-chat-panel">
            <div class="wa-chat-header">
              <div class="wa-chat-header-name" id="chatHeaderName">&nbsp;</div>
              <div class="wa-chat-header-phone" id="chatHeaderPhone">&nbsp;</div>
            </div>
            <div class="wa-msgs" id="waMsgs"></div>
            <form method="POST" class="wa-reply-form" id="replyForm">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="send_message">
              <input type="hidden" name="phone" id="reply_phone" value="">
              <textarea name="message" rows="2" placeholder="<?= $connected ? 'Type a message…' : 'Connect WhatsApp to send messages' ?>" required <?= $connected ? '' : 'disabled' ?>></textarea>
              <button type="submit" class="btn btn-primary" <?= $connected ? '' : 'disabled' ?>>Send</button>
            </form>
          </div>
        <?php endif; ?>
      </div>

    </div>
  </div>

</div>

<script>
function copyField(id, btn) {
  const el = document.getElementById(id);
  el.select();
  el.setSelectionRange(0, 99999);
  navigator.clipboard.writeText(el.value).then(() => {
    const original = btn.textContent;
    btn.textContent = '✅';
    setTimeout(() => { btn.textContent = original; }, 1200);
  });
}

function openConversation(phone, name, el) {
  document.querySelectorAll('.wa-conv-item').forEach(i => i.classList.remove('active'));
  if (el) {
    el.classList.add('active');
    const badge = el.querySelector('.wa-unread-badge');
    if (badge) badge.remove();
  }

  document.getElementById('chatHeaderName').textContent = name;
  document.getElementById('chatHeaderPhone').textContent = phone;
  document.getElementById('reply_phone').value = phone;

  const msgsEl = document.getElementById('waMsgs');
  msgsEl.innerHTML = '<div style="text-align:center;color:var(--gray-400);font-size:.8rem;padding:20px;">Loading…</div>';

  fetch('ajax_whatsapp_thread.php?phone=' + encodeURIComponent(phone))
    .then(r => r.json())
    .then(data => {
      msgsEl.innerHTML = '';
      (data.messages || []).forEach(m => {
        const div = document.createElement('div');
        div.className = 'wa-msg ' + (m.direction === 'inbound' ? 'wa-msg-in' : 'wa-msg-out');

        const textDiv = document.createElement('div');
        textDiv.textContent = m.content;
        div.appendChild(textDiv);

        const timeDiv = document.createElement('div');
        timeDiv.className = 'wa-msg-time';
        const dt = new Date(m.created_at.replace(' ', 'T'));
        timeDiv.textContent = dt.toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
        div.appendChild(timeDiv);

        msgsEl.appendChild(div);
      });
      msgsEl.scrollTop = msgsEl.scrollHeight;
    })
    .catch(() => {
      msgsEl.innerHTML = '<div style="text-align:center;color:var(--gray-400);font-size:.8rem;padding:20px;">Could not load messages.</div>';
    });
}

<?php if (!empty($conversations) && $openPhone): ?>
document.addEventListener('DOMContentLoaded', () => {
  const activeItem = document.querySelector('.wa-conv-item.active') || document.querySelector('.wa-conv-item');
  if (activeItem) activeItem.click();
});
<?php endif; ?>
</script>

<?php include __DIR__ . '/partials/foot.php'; ?>
