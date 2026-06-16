<?php
/**
 * BookWA Installer
 * Run once to create the database and tables.
 * Delete or restrict access after setup.
 */

// Check for lock file
if (file_exists(__DIR__ . '/.installed')) {
    die('<div style="font-family:sans-serif;padding:40px;text-align:center;"><h2>Already Installed</h2><p>BookWA is already installed. Delete <code>.installed</code> to re-run the installer.</p><a href="index.php">← Go to App</a></div>');
}

$step    = isset($_POST['step']) ? (int)$_POST['step'] : 0;
$message = '';
$success = false;

if ($step === 1) {
    $host    = trim($_POST['db_host']    ?? 'localhost');
    $user    = trim($_POST['db_user']    ?? 'root');
    $pass    = $_POST['db_pass']         ?? '';
    $dbname  = trim($_POST['db_name']    ?? 'bookwa');
    $appUrl  = rtrim(trim($_POST['app_url'] ?? ''), '/');

    try {
        // Connect without DB to create it
        $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$dbname`");

        // Run the SQL schema
        $sql = file_get_contents(__DIR__ . '/database.sql');
        // Remove "CREATE DATABASE" and "USE" lines since we already handled that
        $sql = preg_replace('/^CREATE DATABASE.*?;/im', '', $sql);
        $sql = preg_replace('/^USE.*?;/im', '', $sql);

        // Execute each statement
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            if (!empty($statement)) {
                $pdo->exec($statement);
            }
        }

        // Write config with actual values
        $configContent = <<<PHP
<?php
define('APP_NAME',    'BookWA');
define('APP_TAGLINE', 'Automate Appointments. Power Growth.');
define('APP_URL',     '$appUrl');
define('APP_VERSION', '1.0.0');

define('DB_HOST',    '$host');
define('DB_USER',    '$user');
define('DB_PASS',    '$pass');
define('DB_NAME',    '$dbname');
define('DB_CHARSET', 'utf8mb4');

ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.gc_maxlifetime', 86400);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('UTC');
error_reporting(0);
ini_set('display_errors', 0);
PHP;
        file_put_contents(__DIR__ . '/includes/config.php', $configContent);

        // Write lock file
        file_put_contents(__DIR__ . '/.installed', date('Y-m-d H:i:s'));

        $success = true;
        $message = 'Installation successful! Database created and tables imported.';

    } catch (PDOException $e) {
        $message = 'Error: ' . $e->getMessage();
    }
}

// Auto-detect app URL
$guessedUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . str_replace('/install.php', '', ($_SERVER['REQUEST_URI'] ?? '/Github/Appointment'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>BookWA Installer</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Inter', sans-serif; background: #f1f5f9; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
    .card { background: white; border-radius: 16px; padding: 40px; max-width: 520px; width: 100%; box-shadow: 0 4px 24px rgba(0,0,0,.08); }
    .logo { display: flex; align-items: center; gap: 10px; font-size: 1.4rem; font-weight: 800; margin-bottom: 32px; }
    .logo-icon { width: 36px; height: 36px; background: #25D366; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
    h1 { font-size: 1.5rem; font-weight: 800; color: #111827; margin-bottom: 6px; }
    .sub { color: #6b7280; font-size: .9rem; margin-bottom: 28px; }
    .form-group { margin-bottom: 18px; }
    label { display: block; font-size: .875rem; font-weight: 600; color: #374151; margin-bottom: 6px; }
    input { width: 100%; padding: 10px 14px; border: 1.5px solid #e5e7eb; border-radius: 8px; font-size: .925rem; font-family: inherit; outline: none; transition: border .2s; }
    input:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,.12); }
    .hint { font-size: .78rem; color: #9ca3af; margin-top: 4px; }
    button { width: 100%; padding: 12px; background: #6366f1; color: white; border: none; border-radius: 8px; font-size: 1rem; font-weight: 700; cursor: pointer; margin-top: 8px; transition: background .2s; font-family: inherit; }
    button:hover { background: #4f46e5; }
    .alert { padding: 12px 16px; border-radius: 8px; font-size: .9rem; margin-bottom: 20px; }
    .alert-error   { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
    .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
    .success-actions { display: flex; flex-direction: column; gap: 12px; margin-top: 20px; }
    .btn-link { display: block; text-align: center; padding: 12px; background: #6366f1; color: white; border-radius: 8px; font-weight: 700; font-size: .95rem; text-decoration: none; }
    .btn-link.outline { background: transparent; color: #6366f1; border: 2px solid #6366f1; }
    .req-check { display: flex; align-items: center; gap: 8px; font-size: .85rem; margin-bottom: 8px; }
    .req-ok  { color: #065f46; }
    .req-err { color: #991b1b; }
  </style>
</head>
<body>

<div class="card">

  <div class="logo">
    <span class="logo-icon">💬</span>
    BookWA Installer
  </div>

  <?php if ($success): ?>
    <!-- Success state -->
    <div class="alert alert-success">✓ <?= htmlspecialchars($message) ?></div>
    <h1>Installation Complete! 🎉</h1>
    <p class="sub">BookWA is ready. Create your first account to get started.</p>
    <div class="success-actions">
      <a href="auth/signup.php" class="btn-link">Create Your Account →</a>
      <a href="index.php"       class="btn-link outline">Go to Homepage</a>
    </div>
    <p style="margin-top:16px;font-size:.8rem;color:#9ca3af;">⚠ For security, delete <code>install.php</code> from your server after setup.</p>

  <?php elseif (!empty($message)): ?>
    <!-- Error state -->
    <div class="alert alert-error">✕ <?= htmlspecialchars($message) ?></div>
    <a href="install.php" style="color:#6366f1;font-size:.875rem;">← Try again</a>

  <?php else: ?>
    <!-- Form -->
    <h1>Install BookWA</h1>
    <p class="sub">Enter your database credentials to create the database and tables.</p>

    <!-- PHP checks -->
    <div style="margin-bottom:20px;">
      <div class="req-check <?= version_compare(PHP_VERSION, '7.4', '>=') ? 'req-ok' : 'req-err' ?>">
        <?= version_compare(PHP_VERSION, '7.4', '>=') ? '✓' : '✕' ?> PHP <?= PHP_VERSION ?> (7.4+ required)
      </div>
      <div class="req-check <?= extension_loaded('pdo_mysql') ? 'req-ok' : 'req-err' ?>">
        <?= extension_loaded('pdo_mysql') ? '✓' : '✕' ?> PDO MySQL extension
      </div>
      <div class="req-check <?= is_writable(__DIR__ . '/includes') ? 'req-ok' : 'req-err' ?>">
        <?= is_writable(__DIR__ . '/includes') ? '✓' : '✕' ?> includes/ directory writable
      </div>
    </div>

    <form method="POST">
      <input type="hidden" name="step" value="1">

      <div class="form-group">
        <label>Database Host</label>
        <input type="text" name="db_host" value="localhost" required>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group">
          <label>Database User</label>
          <input type="text" name="db_user" value="root" required>
        </div>
        <div class="form-group">
          <label>Database Password</label>
          <input type="password" name="db_pass" value="" placeholder="(empty for root)">
        </div>
      </div>
      <div class="form-group">
        <label>Database Name</label>
        <input type="text" name="db_name" value="bookwa" required>
        <div class="hint">Will be created if it doesn't exist.</div>
      </div>
      <div class="form-group">
        <label>App URL</label>
        <input type="url" name="app_url" value="<?= htmlspecialchars($guessedUrl) ?>" required>
        <div class="hint">Full URL to this project, no trailing slash.</div>
      </div>

      <button type="submit">Install BookWA →</button>
    </form>

  <?php endif; ?>
</div>

</body>
</html>
