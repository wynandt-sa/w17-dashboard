<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$me = user();
$is_admin = ($me['role'] ?? '') === 'admin';
if (!$is_admin) { http_response_code(403); exit('Admins only'); }

if (!function_exists('e')) { function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

/* ---------- CSRF ---------- */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
function csrf_input(){ echo '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">'; }
function csrf_check(){
  if (empty($_POST['csrf']) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    http_response_code(400); exit('Invalid CSRF');
  }
}

/* ---------- Helpers / schema ---------- */
function col_exists(PDO $pdo, $table, $col){
  $s=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
  $s->execute([$table,$col]); return (bool)$s->fetchColumn();
}

$QUEUE_VALUES = ['Workshop17','HR','Finance'];

$pdo->exec("
CREATE TABLE IF NOT EXISTS user_queue_access (
  user_id INT NOT NULL,
  queue ENUM('Workshop17','HR','Finance') NOT NULL,
  PRIMARY KEY (user_id, queue),
  CONSTRAINT fk_uqa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* Detect password column gracefully */
$PASSWORD_COL = null;
if (col_exists($pdo,'users','password_hash')) $PASSWORD_COL = 'password_hash';
elseif (col_exists($pdo,'users','password'))  $PASSWORD_COL = 'password';

/* ---------- POST: create / update / delete ---------- */
$msg = null; $err = null;

if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();

  /* CREATE */
  if (isset($_POST['create'])) {
    try {
      $first = trim($_POST['first_name'] ?? '');
      $last  = trim($_POST['last_name'] ?? '');
      $email = trim($_POST['email'] ?? '');
      $usern = trim($_POST['username'] ?? '');
      $role  = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
      $active= isset($_POST['active']) ? 1 : 0;
      $dob   = $_POST['date_of_birth'] ?: null;
      $anniv = $_POST['work_anniversary'] ?: null;
      $dept  = trim($_POST['department'] ?? '');
      $job   = trim($_POST['job_title'] ?? '');
      $mgr   = (int)($_POST['manager_id'] ?? 0) ?: null;
      $loc   = (int)($_POST['location_id'] ?? 0) ?: null;
      $pwd   = trim($_POST['password'] ?? '');

      if ($first==='' || $last==='' || $email==='' || $usern==='') {
        throw new RuntimeException('Please complete all required fields.');
      }
      if ($PASSWORD_COL && $pwd==='') { throw new RuntimeException('Please set an initial password.'); }

      $cols = ['first_name','last_name','email','username','role','active','date_of_birth','work_anniversary','department','job_title','manager_id','location_id'];
      $vals = [$first,$last,$email,$usern,$role,$active,$dob,$anniv,$dept,$job,$mgr,$loc];

      if ($PASSWORD_COL && $pwd!=='') {
        $cols[] = $PASSWORD_COL;
        $vals[] = password_hash($pwd, PASSWORD_DEFAULT);
      }

      $place = implode(',', array_fill(0,count($cols),'?'));
      $sql = "INSERT INTO users (".implode(',',$cols).") VALUES ($place)";
      $pdo->prepare($sql)->execute($vals);
      $uid = (int)$pdo->lastInsertId();

      // queues
      $sel = array_values(array_intersect($QUEUE_VALUES, (array)($_POST['queues'] ?? [])));
      if ($sel) {
        $ins = $pdo->prepare("INSERT INTO user_queue_access (user_id,queue) VALUES (?,?)");
        foreach ($sel as $q) $ins->execute([$uid,$q]);
      }
      $msg = 'created';
      header('Location: users.php?msg='.$msg); exit;

    } catch (Throwable $ex) {
      $err = $ex->getMessage();
      if (stripos($err, 'duplicate') !== false || stripos($err, 'unique') !== false) {
        $err = 'Username or email already exists.';
      }
    }
  }

  /* UPDATE */
  if (isset($_POST['save'])) {
    try {
      $id    = (int)($_POST['id'] ?? 0);
      $first = trim($_POST['first_name'] ?? '');
      $last  = trim($_POST['last_name'] ?? '');
      $email = trim($_POST['email'] ?? '');
      $usern = trim($_POST['username'] ?? '');
      $role  = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
      $active= isset($_POST['active']) ? 1 : 0;
      $dob   = $_POST['date_of_birth'] ?: null;
      $anniv = $_POST['work_anniversary'] ?: null;
      $dept  = trim($_POST['department'] ?? '');
      $job   = trim($_POST['job_title'] ?? '');
      $mgr   = (int)($_POST['manager_id'] ?? 0) ?: null;
      $loc   = (int)($_POST['location_id'] ?? 0) ?: null;
      $pwd   = trim($_POST['password'] ?? '');

      if ($id<=0 || $first==='' || $last==='' || $email==='' || $usern==='') {
        throw new RuntimeException('Please complete all required fields.');
      }

      $sets = "first_name=?, last_name=?, email=?, username=?, role=?, active=?, date_of_birth=?, work_anniversary=?, department=?, job_title=?, manager_id=?, location_id=?";
      $args = [$first,$last,$email,$usern,$role,$active,$dob,$anniv,$dept,$job,$mgr,$loc];

      if ($PASSWORD_COL && $pwd!=='') {
        $sets .= ", $PASSWORD_COL=?";
        $args[] = password_hash($pwd, PASSWORD_DEFAULT);
      }
      $sets .= " WHERE id=?";
      $args[] = $id;

      $pdo->prepare("UPDATE users SET $sets")->execute($args);

      // queues
      $pdo->prepare("DELETE FROM user_queue_access WHERE user_id=?")->execute([$id]);
      $sel = array_values(array_intersect($QUEUE_VALUES, (array)($_POST['queues'] ?? [])));
      if ($sel) {
        $ins = $pdo->prepare("INSERT INTO user_queue_access (user_id,queue) VALUES (?,?)");
        foreach ($sel as $q) $ins->execute([$id,$q]);
      }

      $msg = 'saved';
      header('Location: users.php?msg='.$msg); exit;

    } catch (Throwable $ex) {
      $err = $ex->getMessage();
      if (stripos($err, 'duplicate') !== false || stripos($err, 'unique') !== false) {
        $err = 'Username or email already exists.';
      }
    }
  }

  /* DELETE (admin only; from modal) */
  if (isset($_POST['delete'])) {
    $id = (int)($_POST['id'] ?? 0);
    if ($id === (int)$me['id']) { $err = "You can't delete yourself."; }
    else {
      $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
      $msg = 'deleted';
      header('Location: users.php?msg='.$msg); exit;
    }
  }
}

/* ---------- Data ---------- */
$users = $pdo->query("SELECT u.*, CONCAT_WS(' ',m.first_name,m.last_name) AS manager_name
                      FROM users u LEFT JOIN users m ON m.id=u.manager_id
                      ORDER BY u.first_name,u.last_name")->fetchAll(PDO::FETCH_ASSOC);

$managers = $pdo->query("SELECT id, CONCAT_WS(' ',first_name,last_name) AS name FROM users ORDER BY first_name,last_name")->fetchAll(PDO::FETCH_KEY_PAIR);
$queuesByUser = $pdo->query("SELECT user_id, GROUP_CONCAT(queue ORDER BY queue) AS qs FROM user_queue_access GROUP BY user_id")->fetchAll(PDO::FETCH_KEY_PAIR);

/* Locations for select lists */
$locationsKV = [];
if ($pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='locations'")->fetchColumn()) {
  $locationsKV = $pdo->query("SELECT id, name FROM locations ORDER BY code, name")->fetchAll(PDO::FETCH_KEY_PAIR);
}

include __DIR__ . '/partials/header.php';
?>
<div class="card">
  <div class="card-h">
    <h3>Users</h3>
    <div>
      <button class="btn btn-primary" type="button" onclick="openNew()">+ New User</button>
    </div>
  </div>
  <div class="card-b">
    <?php if($msg || isset($_GET['msg'])): ?>
      <?php $m = $msg ?? $_GET['msg']; $text = ['saved'=>'User saved','created'=>'User created','deleted'=>'User deleted'][$m] ?? e($m); ?>
      <div class="badge badge-success" style="display:block;margin-bottom:1rem;"><?= e($text) ?></div>
    <?php endif; ?>
    <?php if($err): ?>
      <div class="badge badge-danger" style="display:block;margin-bottom:1rem;"><?= e($err) ?></div>
    <?php endif; ?>

    <?php if(!$users): ?>
      <p>No users yet.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table" id="usersTable">
          <thead><tr>
            <th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Active</th><th>Queues</th>
          </tr></thead>
          <tbody>
          <?php foreach($users as $u): $uid=(int)$u['id']; ?>
            <tr class="row-openable" data-id="<?= $uid ?>" style="cursor:pointer">
              <td><?= e(trim(($u['first_name']??'').' '.($u['last_name']??''))) ?></td>
              <td><?= e($u['username'] ?? '') ?></td>
              <td><?= e($u['email'] ?? '') ?></td>
              <td><?= e($u['role'] ?? 'user') ?></td>
              <td><?= !empty($u['active']) ? 'Yes' : 'No' ?></td>
              <td><?= e($queuesByUser[$uid] ?? '—') ?></td>
            </tr>
            <tr style="display:none">
              <td colspan="6">
                <span id="u<?= $uid ?>"
                      data-json='<?= e(json_encode($u, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>'
                      data-queues='<?= e(json_encode(($queuesByUser[$uid]??'')!==''? explode(',',$queuesByUser[$uid]) : [], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>'></span>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ============ NEW USER MODAL ============ -->
<div id="modal-new-user" class="modal" style="display:none">
  <div class="modal-backdrop" onclick="closeNew()"></div>
  <div class="modal-card" style="max-width:900px">
    <div class="modal-h"><h3>New User</h3><button class="btn btn-light btn-icon" onclick="closeNew()" aria-label="Close">✕</button></div>
    <div class="modal-b">
      <form method="post" class="grid grid-2">
        <?php csrf_input(); ?>
        <input type="hidden" name="create" value="1">
        <div class="grid" style="grid-template-columns:1fr"><label class="label">First Name *</label><input class="input" name="first_name" required></div>
        <div class="grid" style="grid-template-columns:1fr"><label class="label">Last Name *</label><input class="input" name="last_name" required></div>
        <div class="grid" style="grid-template-columns:1fr"><label class="label">Email *</label><input class="input" type="email" name="email" required></div>
        <div class="grid" style="grid-template-columns:1fr"><label class="label">Username *</label><input class="input" name="username" required></div>

        <div>
          <label class="label">Role</label>
          <select name="role" class="input"><option>user</option><option>admin</option></select>
        </div>
        <div>
          <label class="label">Manager</label>
          <select name="manager_id" class="input"><option value="">—</option>
            <?php foreach($managers as $mid=>$nm): ?><option value="<?= (int)$mid ?>"><?= e($nm) ?></option><?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="label">Location</label>
          <select name="location_id" class="input">
            <option value="">—</option>
            <?php foreach($locationsKV as $lid=>$ln): ?>
              <option value="<?= (int)$lid ?>"><?= e($ln) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="label">Department</label>
          <input class="input" name="department">
        </div>
        <div>
          <label class="label">Job Title</label>
          <input class="input" name="job_title">
        </div>

        <div>
          <label class="label">Date of Birth</label>
          <input class="input" type="date" name="date_of_birth">
        </div>
        <div>
          <label class="label">Work Anniversary</label>
          <input class="input" type="date" name="work_anniversary">
        </div>

        <div class="grid" style="grid-template-columns:1fr">
          <label class="label">Queue Visibility</label>
          <div class="chipset">
            <?php foreach($QUEUE_VALUES as $q): ?>
              <label class="chip"><input type="checkbox" name="queues[]" value="<?= e($q) ?>"> <span><?= e($q) ?></span></label>
            <?php endforeach; ?>
          </div>
          <small style="color:#666">Tick the queues this user can view.</small>
        </div>

        <?php if ($PASSWORD_COL): ?>
        <div class="grid" style="grid-template-columns:1fr">
          <label class="label">Initial Password *</label>
          <input class="input" type="password" name="password" autocomplete="new-password" required>
        </div>
        <?php endif; ?>

        <div>
          <label class="label">Active</label>
          <input type="checkbox" name="active" value="1" checked>
        </div>

        <div style="grid-column:1 / -1; display:flex; gap:.5rem; flex-wrap:wrap">
          <button class="btn btn-primary">Create User</button>
          <button class="btn btn-light" type="button" onclick="closeNew()">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ============ EDIT USER MODAL ============ -->
<div id="modal-edit-user" class="modal" style="display:none">
  <div class="modal-backdrop" onclick="closeEdit()"></div>
  <div class="modal-card" style="max-width:900px">
    <div class="modal-h"><h3>Edit User</h3><button class="btn btn-light btn-icon" onclick="closeEdit()" aria-label="Close">✕</button></div>
    <div class="modal-b">
      <form method="post" class="grid grid-2" id="editForm">
        <?php csrf_input(); ?>
        <input type="hidden" name="save" value="1">
        <input type="hidden" name="id" id="f_id">
        <div class="grid" style="grid-template-columns:1fr"><label class="label">First Name *</label><input class="input" name="first_name" id="f_first" required></div>
        <div class="grid" style="grid-template-columns:1fr"><label class="label">Last Name *</label><input class="input" name="last_name" id="f_last" required></div>
        <div class="grid" style="grid-template-columns:1fr"><label class="label">Email *</label><input class="input" type="email" name="email" id="f_email" required></div>
        <div class="grid" style="grid-template-columns:1fr"><label class="label">Username *</label><input class="input" name="username" id="f_usern" required></div>

        <div>
          <label class="label">Role</label>
          <select name="role" id="f_role" class="input"><option>user</option><option>admin</option></select>
        </div>
        <div>
          <label class="label">Manager</label>
          <select name="manager_id" id="f_mgr" class="input"><option value="">—</option>
            <?php foreach($managers as $mid=>$nm): ?><option value="<?= (int)$mid ?>"><?= e($nm) ?></option><?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="label">Location</label>
          <select name="location_id" id="f_loc" class="input">
            <option value="">—</option>
            <?php foreach($locationsKV as $lid=>$ln): ?>
              <option value="<?= (int)$lid ?>"><?= e($ln) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="label">Department</label>
          <input class="input" name="department" id="f_dept">
        </div>
        <div>
          <label class="label">Job Title</label>
          <input class="input" name="job_title" id="f_job">
        </div>

        <div>
          <label class="label">Date of Birth</label>
          <input class="input" type="date" name="date_of_birth" id="f_dob">
        </div>
        <div>
          <label class="label">Work Anniversary</label>
          <input class="input" type="date" name="work_anniversary" id="f_anniv">
        </div>

        <div class="grid" style="grid-template-columns:1fr">
          <label class="label">Queue Visibility</label>
          <div class="chipset">
            <?php foreach($QUEUE_VALUES as $q): ?>
              <label class="chip"><input type="checkbox" name="queues[]" value="<?= e($q) ?>" class="qcb"> <span><?= e($q) ?></span></label>
            <?php endforeach; ?>
          </div>
        </div>

        <?php if ($PASSWORD_COL): ?>
        <div class="grid" style="grid-template-columns:1fr">
          <label class="label">New Password (leave blank to keep)</label>
          <input class="input" type="password" name="password" autocomplete="new-password">
        </div>
        <?php endif; ?>

        <div>
          <label class="label">Active</label>
          <input type="checkbox" name="active" id="f_active" value="1">
        </div>

        <div style="grid-column:1 / -1; display:flex; gap:.5rem; flex-wrap:wrap">
          <button class="btn btn-primary">Save</button>
          <button class="btn btn-light" type="button" onclick="closeEdit()">Cancel</button>
          <!-- Admins can delete from here -->
          <form method="post" style="display:inline" onsubmit="return confirm('Delete this user?')">
            <?php csrf_input(); ?>
            <input type="hidden" name="id" id="del_id">
            <button class="btn btn-danger" name="delete" value="1">Delete</button>
          </form>
        </div>
      </form>
    </div>
  </div>
</div>

<style>
/* keep custom CSS minimal—inherit buttons/inputs from header */
#usersTable tbody tr.row-openable:hover{ background:#f8fafb; }
.modal{position:fixed;inset:0;z-index:60;display:flex;align-items:center;justify-content:center}
.modal-backdrop{position:absolute;inset:0;background:rgba(0,0,0,.35)}
.modal-card{position:relative;background:var(--white);border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,.15);width:min(900px,94vw);max-height:92vh;overflow:auto}
.modal-h{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid #e5e7eb}
.modal-b{padding:1rem 1.25rem}
.label{font-weight:600;margin:.25rem 0}
.chipset{display:flex;gap:.5rem;flex-wrap:wrap}
.chip{display:inline-flex;align-items:center;gap:.35rem;border:1px solid var(--gray-200);border-radius:999px;padding:.35rem .6rem;background:#fafbfc}
.btn-icon{padding:.35rem .5rem}
</style>
<script>
/* row click opens edit */
(function(){
  document.querySelectorAll('#usersTable tbody tr.row-openable').forEach(tr=>{
    tr.addEventListener('click', ()=>{
      const id = tr.getAttribute('data-id');
      if (id) openEdit(parseInt(id,10));
    }, {passive:true});
  });
})();

function openNew(){
  const f = document.querySelector('#modal-new-user form');
  f.reset();
  document.getElementById('modal-new-user').style.display='flex';
}
function closeNew(){ document.getElementById('modal-new-user').style.display='none'; }

function openEdit(id){
  const el = document.getElementById('u'+id);
  if(!el) return;
  const u = JSON.parse(el.getAttribute('data-json')||'{}');
  const qs = JSON.parse(el.getAttribute('data-queues')||'[]');

  document.getElementById('f_id').value   = u.id || '';
  document.getElementById('del_id').value = u.id || '';
  document.getElementById('f_first').value= u.first_name || '';
  document.getElementById('f_last').value = u.last_name || '';
  document.getElementById('f_email').value= u.email || '';
  document.getElementById('f_usern').value= u.username || '';
  document.getElementById('f_role').value = u.role || 'user';
  document.getElementById('f_mgr').value  = u.manager_id || '';
  document.getElementById('f_loc').value  = u.location_id || '';
  document.getElementById('f_dept').value = u.department || '';
  document.getElementById('f_job').value  = u.job_title || '';
  document.getElementById('f_dob').value  = u.date_of_birth || '';
  document.getElementById('f_anniv').value= u.work_anniversary || '';
  document.getElementById('f_active').checked = !!(+u.active);

  // tick queues
  document.querySelectorAll('#modal-edit-user input.qcb').forEach(cb => {
    cb.checked = qs.includes(cb.value);
  });

  document.getElementById('modal-edit-user').style.display='flex';
}
function closeEdit(){ document.getElementById('modal-edit-user').style.display='none'; }
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
