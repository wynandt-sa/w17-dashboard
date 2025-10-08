<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_admin();

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (!function_exists('e')) {
    function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}


/* ---------------- CSRF ---------------- */
if(session_status()!==PHP_SESSION_ACTIVE) session_start();
if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
function csrf_input(){ echo '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">'; }
function csrf_check(){ if(empty($_POST['csrf'])||!hash_equals($_SESSION['csrf'],$_POST['csrf'])) die("CSRF!"); }

/* ---------------- Schema ---------------- */
$pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(100) UNIQUE NOT NULL,
  setting_value TEXT NOT NULL
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS queue_emails (
  id INT AUTO_INCREMENT PRIMARY KEY,
  queue VARCHAR(100) NOT NULL,
  email VARCHAR(255) NOT NULL UNIQUE
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS email_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  template_type ENUM('new','pending','closed') NOT NULL UNIQUE,
  subject VARCHAR(255) NOT NULL,
  body_html TEXT NOT NULL
)");

/* ---------------- Actions ---------------- */
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_check();

  if(isset($_POST['smtp_save'])){
    foreach(['smtp_host','smtp_port','smtp_user','smtp_pass','smtp_secure'] as $k){
      $v = trim($_POST[$k]??'');
      $st=$pdo->prepare("INSERT INTO system_settings(setting_key,setting_value) VALUES(?,?)
                         ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
      $st->execute([$k,$v]);
    }
  }

  if(isset($_POST['queue_add'])){
    $st=$pdo->prepare("INSERT INTO queue_emails(queue,email) VALUES(?,?)");
    $st->execute([$_POST['queue'],$_POST['email']]);
  }

  if(isset($_POST['template_save'])){
    $st=$pdo->prepare("INSERT INTO email_templates(template_type,subject,body_html)
                       VALUES(?,?,?)
                       ON DUPLICATE KEY UPDATE subject=VALUES(subject), body_html=VALUES(body_html)");
    $st->execute([$_POST['template_type'],$_POST['subject'],$_POST['body_html']]);
  }

  header("Location: system_settings.php?saved=1"); exit;
}

/* ---------------- Load ---------------- */
function setting($pdo,$k){
  $st=$pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key=?");
  $st->execute([$k]); return $st->fetchColumn();
}
$smtp = [
  'smtp_host'=>setting($pdo,'smtp_host'),
  'smtp_port'=>setting($pdo,'smtp_port'),
  'smtp_user'=>setting($pdo,'smtp_user'),
  'smtp_pass'=>setting($pdo,'smtp_pass'),
  'smtp_secure'=>setting($pdo,'smtp_secure')
];
$queues=$pdo->query("SELECT * FROM queue_emails")->fetchAll(PDO::FETCH_ASSOC);
$templates=$pdo->query("SELECT * FROM email_templates")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__.'/partials/header.php';
?>
<div class="card">
  <div class="card-h"><h3>System Settings</h3></div>
  <div class="card-b">
    <h4>SMTP Settings</h4>
    <form method="post">
      <?php csrf_input(); ?>
      <input class="input" name="smtp_host" placeholder="SMTP Host" value="<?=e($smtp['smtp_host'])?>"><br>
      <input class="input" name="smtp_port" placeholder="Port" value="<?=e($smtp['smtp_port'])?>"><br>
      <input class="input" name="smtp_user" placeholder="Username" value="<?=e($smtp['smtp_user'])?>"><br>
      <input class="input" type="password" name="smtp_pass" placeholder="Password" value="<?=e($smtp['smtp_pass'])?>"><br>
      <select name="smtp_secure" class="input">
        <option value="">None</option>
        <option value="ssl" <?=($smtp['smtp_secure']==='ssl'?'selected':'')?>>SSL</option>
        <option value="tls" <?=($smtp['smtp_secure']==='tls'?'selected':'')?>>TLS</option>
      </select><br>
      <button class="btn btn-primary" name="smtp_save">Save SMTP</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-h"><h4>Queue Email Mapping</h4></div>
  <div class="card-b">
    <form method="post">
      <?php csrf_input(); ?>
      <input class="input" name="queue" placeholder="Queue (e.g. Workshop17)">
      <input class="input" type="email" name="email" placeholder="Queue Email">
      <button class="btn btn-primary" name="queue_add">Add</button>
    </form>
    <ul>
      <?php foreach($queues as $q): ?>
        <li><?=e($q['queue'])?> → <?=e($q['email'])?></li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>

<div class="card">
  <div class="card-h"><h4>Email Templates</h4></div>
  <div class="card-b">
    <form method="post">
      <?php csrf_input(); ?>
      <select name="template_type" class="input">
        <option value="new">New Ticket</option>
        <option value="pending">Pending</option>
        <option value="closed">Closed</option>
      </select><br>
      <input class="input" name="subject" placeholder="Email Subject"><br>
      <textarea class="input" name="body_html" rows="8" placeholder="HTML body"></textarea><br>
      <button class="btn btn-primary" name="template_save">Save Template</button>
    </form>
    <h5>Existing Templates</h5>
    <ul>
      <?php foreach($templates as $t): ?>
        <li><strong><?=e($t['template_type'])?>:</strong> <?=e($t['subject'])?></li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
<?php include __DIR__.'/partials/footer.php'; ?>
