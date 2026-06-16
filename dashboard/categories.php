<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

$business   = getCurrentBusiness();
$businessId = (int)$_SESSION['business_id'];

$ICONS  = ['🗂️','💇','💆','🦷','💉','🏋️','🧖','💅','🩺','✂️','🧴','🛁','👁️','🦴','🧘','🚿','💊','🎨'];
$COLORS = ['#6366f1','#25D366','#f59e0b','#ef4444','#3b82f6','#8b5cf6','#10b981','#ec4899','#14b8a6','#f97316'];

// ─── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        setFlash('error', 'Invalid request. Please try again.');
        header('Location: categories.php'); exit;
    }

    $action = post('action');

    if ($action === 'save') {
        $id          = (int)post('id');
        $name        = post('name');
        $description = post('description');
        $icon        = post('icon') ?: '🗂️';
        $color       = post('color') ?: '#6366f1';
        $sortOrder   = (int)post('sort_order');

        if (empty($name)) {
            setFlash('error', 'Category name is required.');
        } else {
            if ($id && ownsRecord('service_categories', $id, $businessId)) {
                $stmt = db()->prepare("UPDATE service_categories SET name=?, description=?, icon=?, color=?, sort_order=? WHERE id=? AND business_id=?");
                $stmt->execute([$name, $description, $icon, $color, $sortOrder, $id, $businessId]);
                setFlash('success', 'Category updated successfully.');
            } else {
                $stmt = db()->prepare("INSERT INTO service_categories (business_id, name, description, icon, color, sort_order) VALUES (?,?,?,?,?,?)");
                $stmt->execute([$businessId, $name, $description, $icon, $color, $sortOrder]);
                setFlash('success', 'Category created successfully.');
            }
        }
    } elseif ($action === 'toggle') {
        $id = (int)post('id');
        if (ownsRecord('service_categories', $id, $businessId)) {
            db()->prepare("UPDATE service_categories SET is_active = 1 - is_active WHERE id=? AND business_id=?")->execute([$id, $businessId]);
            setFlash('success', 'Category status updated.');
        }
    } elseif ($action === 'delete') {
        $id = (int)post('id');
        if (ownsRecord('service_categories', $id, $businessId)) {
            $stmt = db()->prepare("SELECT COUNT(*) FROM services WHERE category_id = ?");
            $stmt->execute([$id]);
            if ((int)$stmt->fetchColumn() > 0) {
                setFlash('error', 'Cannot delete: this category still has services. Move or delete them first.');
            } else {
                db()->prepare("DELETE FROM service_categories WHERE id=? AND business_id=?")->execute([$id, $businessId]);
                setFlash('success', 'Category deleted.');
            }
        }
    }

    header('Location: categories.php');
    exit;
}

// ─── Fetch data ──────────────────────────────────────────────────────────────
$stmt = db()->prepare("
    SELECT c.*, COUNT(s.id) AS service_count
    FROM service_categories c
    LEFT JOIN services s ON s.category_id = c.id
    WHERE c.business_id = ?
    GROUP BY c.id
    ORDER BY c.sort_order ASC, c.name ASC
");
$stmt->execute([$businessId]);
$categories = $stmt->fetchAll();

$activeNav = 'categories';
$pageTitle = 'Service Categories';
include __DIR__ . '/partials/head.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
  <p style="color:var(--gray-500);font-size:.9rem;max-width:520px;">
    Organize your services into categories — these appear as the first menu customers see when booking via WhatsApp (e.g. "1️⃣ Hair · 2️⃣ Nails · 3️⃣ Spa").
  </p>
  <button class="btn btn-primary" data-modal-open="categoryModal" onclick="resetCategoryForm()">+ Add Category</button>
</div>

<?php if (empty($categories)): ?>
  <div class="card">
    <div class="empty-state">
      <div class="empty-state-emoji">🗂️</div>
      <div class="empty-state-title">No categories yet</div>
      <div class="empty-state-desc">Create your first service category to start organizing your offerings.</div>
      <button class="btn btn-primary btn-sm" data-modal-open="categoryModal" onclick="resetCategoryForm()">+ Add Category</button>
    </div>
  </div>
<?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;">
    <?php foreach ($categories as $cat): ?>
      <div class="card">
        <div class="card-body">
          <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:12px;">
            <div style="display:flex;align-items:center;gap:12px;">
              <div style="width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.5rem;background:<?= htmlspecialchars($cat['color']) ?>22;">
                <?= htmlspecialchars($cat['icon']) ?>
              </div>
              <div>
                <div style="font-weight:700;color:var(--gray-900);"><?= htmlspecialchars($cat['name']) ?></div>
                <div style="font-size:.78rem;color:var(--gray-400);"><?= (int)$cat['service_count'] ?> service<?= $cat['service_count'] == 1 ? '' : 's' ?></div>
              </div>
            </div>
            <label class="toggle" title="<?= $cat['is_active'] ? 'Active' : 'Inactive' ?>">
              <form method="POST" id="toggle-<?= $cat['id'] ?>">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= $cat['id'] ?>">
              </form>
              <input type="checkbox" <?= $cat['is_active'] ? 'checked' : '' ?> onchange="document.getElementById('toggle-<?= $cat['id'] ?>').submit()">
              <span class="toggle-slider"></span>
            </label>
          </div>
          <?php if ($cat['description']): ?>
            <p style="font-size:.85rem;color:var(--gray-500);margin-bottom:14px;line-height:1.5;"><?= htmlspecialchars($cat['description']) ?></p>
          <?php endif; ?>
          <div style="display:flex;gap:8px;">
            <button class="btn btn-sm btn-outline" style="flex:1;"
              onclick='editCategory(<?= json_encode($cat, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
              Edit
            </button>
            <form method="POST" style="flex:1;" onsubmit="return confirm('Delete this category? This cannot be undone.');">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $cat['id'] ?>">
              <button type="submit" class="btn btn-sm" style="width:100%;background:#fee2e2;color:#991b1b;border:none;">Delete</button>
            </form>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- ── Category Modal ─────────────────────────────────────── -->
<div class="modal-overlay" id="categoryModal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" id="categoryModalTitle">Add Category</div>
      <button class="modal-close" data-modal-close>✕</button>
    </div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" id="cat_id" value="">
      <div class="modal-body">

        <div class="form-group">
          <label class="form-label">Category Name *</label>
          <input type="text" name="name" id="cat_name" class="form-control" placeholder="e.g. Hair Services" required>
        </div>

        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" id="cat_description" class="form-control" placeholder="Optional short description shown to customers"></textarea>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Icon</label>
            <div class="icon-options" data-input="cat_icon">
              <?php foreach ($ICONS as $i => $icon): ?>
                <div class="icon-option <?= $i === 0 ? 'selected' : '' ?>" data-value="<?= $icon ?>"><?= $icon ?></div>
              <?php endforeach; ?>
            </div>
            <input type="hidden" name="icon" id="cat_icon" value="🗂️">
          </div>
          <div class="form-group">
            <label class="form-label">Color</label>
            <div class="color-options" data-input="cat_color">
              <?php foreach ($COLORS as $i => $color): ?>
                <div class="color-option <?= $i === 0 ? 'selected' : '' ?>" data-value="<?= $color ?>" style="background:<?= $color ?>"></div>
              <?php endforeach; ?>
            </div>
            <input type="hidden" name="color" id="cat_color" value="#6366f1">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Sort Order</label>
          <input type="number" name="sort_order" id="cat_sort_order" class="form-control" value="0" min="0">
          <div class="form-hint">Lower numbers appear first in the WhatsApp menu.</div>
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary" id="categorySubmitBtn">Save Category</button>
      </div>
    </form>
  </div>
</div>

<script>
function resetCategoryForm() {
  document.getElementById('categoryModalTitle').textContent = 'Add Category';
  document.getElementById('cat_id').value = '';
  document.getElementById('cat_name').value = '';
  document.getElementById('cat_description').value = '';
  document.getElementById('cat_sort_order').value = '0';
  document.getElementById('cat_icon').value = '🗂️';
  document.getElementById('cat_color').value = '#6366f1';
  document.querySelectorAll('.icon-option').forEach((el,i) => el.classList.toggle('selected', i===0));
  document.querySelectorAll('.color-option').forEach((el,i) => el.classList.toggle('selected', i===0));
  document.getElementById('categorySubmitBtn').textContent = 'Save Category';
}

function editCategory(cat) {
  document.getElementById('categoryModalTitle').textContent = 'Edit Category';
  document.getElementById('cat_id').value = cat.id;
  document.getElementById('cat_name').value = cat.name;
  document.getElementById('cat_description').value = cat.description || '';
  document.getElementById('cat_sort_order').value = cat.sort_order;
  document.getElementById('cat_icon').value = cat.icon;
  document.getElementById('cat_color').value = cat.color;
  document.querySelectorAll('.icon-option').forEach(el => el.classList.toggle('selected', el.dataset.value === cat.icon));
  document.querySelectorAll('.color-option').forEach(el => el.classList.toggle('selected', el.dataset.value === cat.color));
  document.getElementById('categorySubmitBtn').textContent = 'Update Category';
  document.getElementById('categoryModal').classList.add('open');
}
</script>

<?php include __DIR__ . '/partials/foot.php'; ?>
