<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

redirectIfLoggedIn();

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
                $stmt = db()->prepare('SELECT * FROM businesses WHERE email = ? AND is_active = 1 LIMIT 1');
                $stmt->execute([$email]);
                $business = $stmt->fetch();

                if ($business && password_verify($password, $business['password'])) {
                    login($business);
                    setFlash('success', 'Welcome back, ' . $business['name'] . '!');
                    $redirect = get('redirect') ?: APP_URL . '/dashboard/index.php';
                    header('Location: ' . $redirect);
                    exit;
                } else {
                    $errors['general'] = 'Invalid email or password. Please try again.';
                    // Rate limiting hint (production should use proper rate limiting)
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
  <title>Sign In — BookWA</title>
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

      <h2 class="auth-left-title">Good to see you<br>back.</h2>
      <p class="auth-left-sub">Sign in to manage your appointments, review your schedule, and keep your business running smoothly.</p>

      <ul class="auth-feature-list">
        <li class="auth-feature"><span class="auth-feature-dot"></span>View today's appointments instantly</li>
        <li class="auth-feature"><span class="auth-feature-dot"></span>Confirm or reschedule pending bookings</li>
        <li class="auth-feature"><span class="auth-feature-dot"></span>Monitor WhatsApp bot conversations</li>
        <li class="auth-feature"><span class="auth-feature-dot"></span>Check your revenue analytics</li>
      </ul>
    </div>
    <div class="auth-left-footer">© <?= date('Y') ?> BookWA. All rights reserved.</div>
  </div>

  <!-- Right panel -->
  <div class="auth-panel-right">
    <div class="auth-form-wrap">

      <h1 class="auth-form-title">Welcome back</h1>
      <p class="auth-form-sub">Don't have an account? <a href="<?= APP_URL ?>/auth/signup.php">Sign up free</a></p>

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
            placeholder="you@business.com"
            required autofocus>
          <?php if (isset($errors['email'])): ?>
            <div class="form-error">⚠ <?= htmlspecialchars($errors['email']) ?></div>
          <?php endif; ?>
        </div>

        <div class="form-group">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:7px;">
            <label class="form-label" for="password" style="margin:0">Password</label>
            <a href="#" style="font-size:.8rem;color:var(--primary);font-weight:600;">Forgot password?</a>
          </div>
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

        <div style="display:flex;align-items:center;gap:8px;margin-bottom:24px;">
          <input type="checkbox" id="remember" name="remember" style="accent-color:var(--primary);width:16px;height:16px;">
          <label for="remember" style="font-size:.875rem;color:var(--gray-600);cursor:pointer;">Remember me for 30 days</label>
        </div>

        <button type="submit" class="btn btn-primary btn-full btn-lg">
          Sign In →
        </button>

        <div class="form-divider">or</div>

        <a href="<?= APP_URL ?>/auth/signup.php" class="btn btn-outline btn-full">
          Create a new account
        </a>

      </form>

    </div>
  </div>

</div>

<script src="<?= APP_URL ?>/assets/js/main.js"></script>
</body>
</html>
