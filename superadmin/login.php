<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

redirectIfSuperAdminLoggedIn();

$errors = [];
$email  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        $errors['general'] = 'Invalid request. Please try again.';
    } else {
        $email    = post('email');
        $password = $_POST['password'] ?? '';

        if (empty($email))    $errors['email']    = 'Email address is required.';
        if (empty($password)) $errors['password'] = 'Password is required.';

        if (empty($errors)) {
            try {
                $stmt = db()->prepare('SELECT * FROM super_admins WHERE email = ? LIMIT 1');
                $stmt->execute([$email]);
                $admin = $stmt->fetch();

                if ($admin && password_verify($password, $admin['password'])) {
                    superAdminLogin($admin);
                    setFlash('success', 'Welcome back, ' . $admin['name'] . '!');
                    header('Location: ' . APP_URL . '/superadmin/index.php');
                    exit;
                } else {
                    $errors['general'] = 'Invalid email or password. Please try again.';
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
  <title>Super Admin Sign In — BookWA</title>
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
        <span class="auth-logo-icon">🛠️</span>
        BookWA Admin
      </a>

      <h2 class="auth-left-title">Platform<br>Control Center.</h2>
      <p class="auth-left-sub">Sign in to manage every business on BookWA, configure WhatsApp credentials, and oversee subscription plans.</p>

      <ul class="auth-feature-list">
        <li class="auth-feature"><span class="auth-feature-dot"></span>View platform-wide stats &amp; revenue</li>
        <li class="auth-feature"><span class="auth-feature-dot"></span>Manage all business accounts</li>
        <li class="auth-feature"><span class="auth-feature-dot"></span>Configure WhatsApp App IDs, keys &amp; webhooks</li>
        <li class="auth-feature"><span class="auth-feature-dot"></span>Manage subscription plans</li>
      </ul>
    </div>
    <div class="auth-left-footer">© <?= date('Y') ?> BookWA. All rights reserved.</div>
  </div>

  <!-- Right panel -->
  <div class="auth-panel-right">
    <div class="auth-form-wrap">

      <h1 class="auth-form-title">Super Admin Login</h1>
      <p class="auth-form-sub">Restricted area — platform administrators only.</p>

      <?php if (!empty($errors['general'])): ?>
        <div class="alert alert-error"><span>✕</span><?= htmlspecialchars($errors['general']) ?></div>
      <?php endif; ?>

      <form method="POST" novalidate>
        <?= csrfField() ?>

        <div class="form-group">
          <label class="form-label" for="email">Email Address</label>
          <input
            type="email" id="email" name="email"
            class="form-control <?= isset($errors['email']) ? 'error' : '' ?>"
            value="<?= htmlspecialchars($email) ?>"
            placeholder="admin@bookwa.local"
            required autofocus>
          <?php if (isset($errors['email'])): ?>
            <div class="form-error">⚠ <?= htmlspecialchars($errors['email']) ?></div>
          <?php endif; ?>
        </div>

        <div class="form-group">
          <label class="form-label" for="password">Password</label>
          <div class="input-group">
            <input
              type="password" id="password" name="password"
              class="form-control <?= isset($errors['password']) ? 'error' : '' ?>"
              placeholder="Your password"
              required>
            <button type="button" class="input-group-icon toggle-password" tabindex="-1">👁</button>
          </div>
          <?php if (isset($errors['password'])): ?>
            <div class="form-error">⚠ <?= htmlspecialchars($errors['password']) ?></div>
          <?php endif; ?>
        </div>

        <button type="submit" class="btn btn-primary btn-full btn-lg">
          Sign In →
        </button>

      </form>

    </div>
  </div>

</div>

<script src="<?= APP_URL ?>/assets/js/main.js"></script>
</body>
</html>
