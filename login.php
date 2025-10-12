<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (is_logged_in()) {
    header('Location: ' . (BASE_URL ?: '') . '/dashboard.php');
    exit;
}

/* CSRF for password form */
if (empty($_SESSION['csrf_login'])) {
    $_SESSION['csrf_login'] = bin2hex(random_bytes(32));
}
function csrf_login_input(){
    echo '<input type="hidden" name="csrf" value="'.htmlspecialchars($_SESSION['csrf_login'], ENT_QUOTES, 'UTF-8').'">';
}
function csrf_login_check(){
    if (empty($_POST['csrf']) || empty($_SESSION['csrf_login']) || !hash_equals($_SESSION['csrf_login'], $_POST['csrf'])) {
        http_response_code(400);
        die('Bad request');
    }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'password') {
    csrf_login_check();
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if ($email && $password) {
        $stmt = db()->prepare('SELECT * FROM users WHERE email = :u AND active = 1 LIMIT 1');
        $stmt->execute([':u' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && password_verify($password, $row['password'])) {
            session_regenerate_id(true);
            $_SESSION['user'] = $row;
            header('Location: ' . (BASE_URL ?: '') . '/dashboard.php');
            exit;
        }
    }
    $error = 'Invalid credentials';
}

$auth_page = true; // flag for header.php (use auth styles)
include __DIR__ . '/partials/header.php';
?>

<div class="auth-card" role="dialog" aria-labelledby="loginTitle" aria-describedby="loginDesc">
  <div class="card-h">
    <h3 id="loginTitle" class="auth-title" style="padding:1.25rem 1.5rem">Login to Dashboard</h3>
  </div>
  <div class="auth-body">
    <p id="loginDesc" class="sr-only">Sign in with your email & password or continue with Google. Only Workshop17 accounts are allowed.</p>

    <?php if($error): ?>
      <div class="badge badge-danger" style="display:block;margin-bottom:1rem;" role="alert">
        <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <form method="post" autocomplete="on" novalidate>
      <?php csrf_login_input(); ?>
      <input type="hidden" name="form" value="password">
      <div class="auth-group">
        <input type="email" name="email" class="auth-input" placeholder="Email" required autocomplete="email" autofocus>
      </div>
      <div class="auth-group">
        <input type="password" name="password" class="auth-input" placeholder="Password" required autocomplete="current-password" minlength="6">
      </div>
      <div class="auth-group">
        <button class="auth-btn" type="submit">Log In</button>
      </div>
    </form>

    <div class="auth-divider">or</div>

    <div class="oauth-buttons">
      <a class="oauth-btn" href="<?= BASE_URL ?>/google_oauth.php">
        <svg class="oauth-icon" viewBox="0 0 533.5 544.3" aria-hidden="true">
          <path fill="#EA4335" d="M533.5 278.4c0-17.4-1.5-34.1-4.3-50.3H272v95.2h146.9c-6.3 34.4-25.1 63.6-53.5 83V471h86.4c50.6-46.6 81.7-115.3 81.7-192.6z"/>
          <path fill="#34A853" d="M272 544.3c72.9 0 134.2-24.1 178.9-65.2l-86.4-66.4c-24 16.1-54.8 25.6-92.5 25.6-71 0-131.1-47.9-152.6-112.3H30.8v70.3C75.2 490.3 168.5 544.3 272 544.3z"/>
          <path fill="#4A90E2" d="M119.4 325.9c-11.3-33.9-11.3-71.1 0-105L30.8 150.6v-70.3C-28.7 145.7-28.7 267.6 30.8 345.6l88.6-19.7z"/>
          <path fill="#FBBC05" d="M272 106.1c39.6-.6 77.9 14 106.8 41.4l80-80C411.9 24.4 345.3-.6 272 0 168.5 0 75.2 54 30.8 150.6L119.4 221C140.9 156.6 201 108.7 272 108.7z"/>
        </svg>
        Continue with Google
      </a>
    </div>

    <div class="auth-muted">
      <a href="#" onclick="alert('Please contact your administrator to reset your password.'); return false;">Forgot your password?</a>
    </div>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
