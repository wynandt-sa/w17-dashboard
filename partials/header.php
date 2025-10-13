<?php
// partials/header.php (stable)
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/*
  Pages like login.php set $auth_page = true to hide the main nav.
  If not set, default to false.
*/
if (!isset($auth_page)) {
  $auth_page = false;
}

/* HTML escape helper (idempotent if already declared elsewhere) */
if (!function_exists('e')) {
  function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$me = user(); // may be null on auth pages
$title = isset($page_title) && $page_title !== '' ? $page_title : 'W17 Dashboard';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e($title); ?></title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
:root{
  --primary:#88C28F;
  --primary-dark:#5a8860;
  --danger:#ef4444;
  --warning:#ff9800;
  --dark:#2c3e50;
  --gray:#6b7280;
  --light:#f5f7fa;
  --white:#fff;
}
html,body{height:100%;}
body{background:#f6f7fb;color:#1f2937;margin:0;}
a{color:var(--primary);}
.btn-primary{background:var(--primary);border-color:var(--primary);}
.btn-primary:hover{background:var(--primary-dark);border-color:var(--primary-dark);}
.card{border:0;box-shadow:0 2px 10px rgba(0,0,0,.06);border-radius:.75rem;background:#fff;}
.table thead th{background:#f0f4f7;border-bottom:1px solid #e5e7eb;}
.form-check-input:checked{background-color:var(--primary);border-color:var(--primary);}
</style>

<!-- Expose CSRF for small JS calls on pages -->
<script>window.CSRF="<?php echo $_SESSION['csrf']; ?>";</script>
</head>
<body>

<?php if (!$auth_page): ?>
<nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom shadow-sm">
  <div class="container-fluid">
    <a class="navbar-brand fw-semibold" href="/dashboard.php" style="color:var(--primary)">W17</a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain" aria-controls="navMain" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div id="navMain" class="collapse navbar-collapse">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item"><a class="nav-link" href="/dashboard.php">Dashboard</a></li>
        <li class="nav-item"><a class="nav-link" href="/tickets.php">Tickets</a></li>
        <li class="nav-item"><a class="nav-link" href="/tasks.php">Tasks</a></li>
        <li class="nav-item"><a class="nav-link" href="/users.php">Users</a></li>
        <li class="nav-item"><a class="nav-link" href="/locations.php">Locations</a></li>
        <li class="nav-item"><a class="nav-link" href="/reports.php">Reports</a></li>
      </ul>

      <div class="d-flex align-items-center gap-3">
        <span class="text-muted small">
          <?php echo e($me['name'] ?? ($me['email'] ?? '')); ?>
        </span>
        <a href="/logout.php" class="btn btn-sm btn-outline-secondary">Logout</a>
      </div>
    </div>
  </div>
</nav>
<?php endif; ?>

<!-- Important: Do NOT close body/html here.
     Your partials/footer.php should include Bootstrap JS and close the tags. -->
