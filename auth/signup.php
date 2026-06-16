<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

redirectIfLoggedIn();

$errors = [];
$values = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        $errors['general'] = 'Invalid request. Please try again.';
    } else {
        $values = [
            'name'          => post('name'),
            'email'         => post('email'),
            'phone'         => post('phone'),
            'business_type' => post('business_type'),
            'password'      => $_POST['password'] ?? '',
            'confirm'       => $_POST['password_confirm'] ?? '',
        ];

        // Validate
        if (empty($values['name']))  $errors['name']  = 'Business name is required.';
        if (empty($values['email'])) $errors['email'] = 'Email address is required.';
        elseif (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
        if (strlen($values['password']) < 8) $errors['password'] = 'Password must be at least 8 characters.';
        if ($values['password'] !== $values['confirm']) $errors['confirm'] = 'Passwords do not match.';

        if (empty($errors)) {
            try {
                // Check duplicate email
                $stmt = db()->prepare('SELECT id FROM businesses WHERE email = ? LIMIT 1');
                $stmt->execute([$values['email']]);
                if ($stmt->fetch()) {
                    $errors['email'] = 'An account with this email already exists.';
                } else {
                    // Create business
                    $slug = uniqueSlug($values['name']);
                    $hash = password_hash($values['password'], PASSWORD_DEFAULT);
                    $stmt = db()->prepare("
                        INSERT INTO businesses (name, slug, email, password, phone, business_type)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$values['name'], $slug, $values['email'], $hash, $values['phone'], $values['business_type']]);
                    $businessId = (int)db()->lastInsertId();

                    // Create default business hours
                    createDefaultBusinessHours($businessId);

                    // Log in the new user
                    $business = ['id' => $businessId, 'name' => $values['name'], 'email' => $values['email']];
                    login($business);

                    setFlash('success', 'Welcome to BookWA! Let\'s set up your workspace.');
                    header('Location: ' . APP_URL . '/dashboard/index.php');
                    exit;
                }
            } catch (PDOException $e) {
                $errors['general'] = 'Something went wrong. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Create Account — BookWA</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body>

<div class="auth-page">

  <!-- Left panel -->
  <div class="auth-panel-left">
    <div class="auth-left-content">
      <a href="<?= APP_URL ?>/index.php" class="auth-logo">
        <span class="auth-logo-icon">💬</span>
        BookWA
      </a>

      <h2 class="auth-left-title">Start automating<br>your bookings today.</h2>
      <p class="auth-left-sub">Join 500+ businesses using BookWA to manage appointments via WhatsApp — no missed calls, no double-bookings.</p>

      <ul class="auth-feature-list">
        <li class="auth-feature"><span class="auth-feature-dot"></span>WhatsApp chatbot takes bookings 24/7</li>
        <li class="auth-feature"><span class="auth-feature-dot"></span>Automated reminders reduce no-shows by 40%</li>
        <li class="auth-feature"><span class="auth-feature-dot"></span>Manage staff schedules & services easily</li>
        <li class="auth-feature"><span class="auth-feature-dot"></span>Full analytics dashboard included</li>
        <li class="auth-feature"><span class="auth-feature-dot"></span>14-day free trial — no credit card needed</li>
      </ul>
    </div>
    <div class="auth-left-footer">© <?= date('Y') ?> BookWA. All rights reserved.</div>
  </div>

  <!-- Right panel (form) -->
  <div class="auth-panel-right">
    <div class="auth-form-wrap">

      <h1 class="auth-form-title">Create your account</h1>
      <p class="auth-form-sub">Already have an account? <a href="<?= APP_URL ?>/auth/login.php">Sign in</a></p>

      <?php if (!empty($errors['general'])): ?>
        <div class="alert alert-error"><span>✕</span><?= htmlspecialchars($errors['general']) ?></div>
      <?php endif; ?>

      <form method="POST" id="signupForm" novalidate>
        <?= csrfField() ?>

        <div class="form-group">
          <label class="form-label" for="name">Business Name *</label>
          <input
            type="text" id="name" name="name"
            class="form-control <?= isset($errors['name']) ? 'error' : '' ?>"
            value="<?= htmlspecialchars($values['name'] ?? '') ?>"
            placeholder="e.g. City Dental Clinic"
            required autofocus>
          <?php if (isset($errors['name'])): ?>
            <div class="form-error">⚠ <?= htmlspecialchars($errors['name']) ?></div>
          <?php endif; ?>
        </div>

        <div class="form-group">
          <label class="form-label" for="business_type">Business Type *</label>
          <select id="business_type" name="business_type" class="form-select" required>
            <option value="">Select your business type…</option>
            <option value="clinic"     <?= ($values['business_type'] ?? '') === 'clinic'      ? 'selected' : '' ?>>🏥 Medical Clinic</option>
            <option value="dental"     <?= ($values['business_type'] ?? '') === 'dental'      ? 'selected' : '' ?>>🦷 Dental Practice</option>
            <option value="hospital"   <?= ($values['business_type'] ?? '') === 'hospital'    ? 'selected' : '' ?>>🏨 Hospital</option>
            <option value="salon"      <?= ($values['business_type'] ?? '') === 'salon'       ? 'selected' : '' ?>>💇 Hair Salon</option>
            <option value="spa"        <?= ($values['business_type'] ?? '') === 'spa'         ? 'selected' : '' ?>>💆 Spa & Wellness</option>
            <option value="beauty"     <?= ($values['business_type'] ?? '') === 'beauty'      ? 'selected' : '' ?>>💄 Beauty Studio</option>
            <option value="gym"        <?= ($values['business_type'] ?? '') === 'gym'         ? 'selected' : '' ?>>🏋️ Gym & Fitness</option>
            <option value="restaurant" <?= ($values['business_type'] ?? '') === 'restaurant'  ? 'selected' : '' ?>>🍽️ Restaurant</option>
            <option value="legal"      <?= ($values['business_type'] ?? '') === 'legal'       ? 'selected' : '' ?>>⚖️ Legal Office</option>
            <option value="other"      <?= ($values['business_type'] ?? '') === 'other'       ? 'selected' : '' ?>>🏢 Other</option>
          </select>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="email">Email Address *</label>
            <input
              type="email" id="email" name="email"
              class="form-control <?= isset($errors['email']) ? 'error' : '' ?>"
              value="<?= htmlspecialchars($values['email'] ?? '') ?>"
              placeholder="you@business.com"
              required>
            <?php if (isset($errors['email'])): ?>
              <div class="form-error">⚠ <?= htmlspecialchars($errors['email']) ?></div>
            <?php endif; ?>
          </div>
          <div class="form-group">
            <label class="form-label" for="phone">Phone Number</label>
            <input
              type="tel" id="phone" name="phone"
              class="form-control"
              value="<?= htmlspecialchars($values['phone'] ?? '') ?>"
              placeholder="+1 555 000 0000">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="password">Password *</label>
          <div class="input-group">
            <input
              type="password" id="password" name="password"
              class="form-control <?= isset($errors['password']) ? 'error' : '' ?>"
              placeholder="Minimum 8 characters"
              required>
            <button type="button" class="input-group-icon toggle-password" tabindex="-1">👁</button>
          </div>
          <?php if (isset($errors['password'])): ?>
            <div class="form-error">⚠ <?= htmlspecialchars($errors['password']) ?></div>
          <?php else: ?>
            <div class="form-hint">Strength: <strong id="passwordStrength"></strong></div>
          <?php endif; ?>
        </div>

        <div class="form-group">
          <label class="form-label" for="password_confirm">Confirm Password *</label>
          <div class="input-group">
            <input
              type="password" id="password_confirm" name="password_confirm"
              class="form-control <?= isset($errors['confirm']) ? 'error' : '' ?>"
              placeholder="Repeat your password"
              required>
            <button type="button" class="input-group-icon toggle-password" tabindex="-1">👁</button>
          </div>
          <?php if (isset($errors['confirm'])): ?>
            <div class="form-error" style="display:flex;">⚠ <?= htmlspecialchars($errors['confirm']) ?></div>
          <?php else: ?>
            <div class="form-error" id="confirmError" style="display:none;">⚠ Passwords do not match.</div>
          <?php endif; ?>
        </div>

        <button type="submit" class="btn btn-primary btn-full btn-lg" style="margin-top:8px;">
          Create My Account →
        </button>

        <p class="terms-text">
          By creating an account, you agree to our
          <a href="#">Terms of Service</a> and <a href="#">Privacy Policy</a>.
          Your 14-day free trial starts now.
        </p>
      </form>

    </div>
  </div>

</div>

<script src="<?= APP_URL ?>/assets/js/main.js"></script>
</body>
</html>
