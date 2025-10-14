<?php
// users.php — list + profile + modals with robust open/close shim + debug
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$me = user();
$my_id = (int)($me['id'] ?? 0);
$is_admin = is_admin();
if (!function_exists('is_manager')) { function is_manager(){ return is_admin(); } }
$is_manager = is_manager();

if (!function_exists('e')) {
  function e($v, $enc = true){ $s=(string)$v; return $enc ? htmlspecialchars($s, ENT_QUOTES, 'UTF-8') : $s; }
}

/* ---------- mode ---------- */
$view_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$is_list_view = $view_id === 0;
if ($is_list_view && !$is_admin) { header("Location: users.php?id={$my_id}"); exit; }
if (!$is_list_view && !$is_admin && $view_id !== $my_id) {
  $st = $pdo->prepare("SELECT 1 FROM users WHERE id=? AND manager_id=? AND active=1");
  $st->execute([$view_id,$my_id]);
  if (!$st->fetchColumn()) { http_response_code(403); exit('Access denied.'); }
}

/* ---------- CSRF ---------- */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
function csrf_input(){ echo '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">'; }
function csrf_check(){
  if (empty($_POST['csrf']) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    http_response_code(400); exit('Invalid CSRF token');
  }
}

/* ---------- light migrations ---------- */
function col_exists(PDO $pdo, $table, $col){
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
  $q->execute([$table,$col]); return (bool)$q->fetchColumn();
}
if (!col_exists($pdo,'users','phone')) { try { $pdo->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(50) NULL"); } catch(Throwable $__){} }

$QUEUE_VALUES = ['Workshop17','HR','Finance'];
$pdo->exec("
CREATE TABLE IF NOT EXISTS user_queue_access (
  user_id INT NOT NULL,
  queue ENUM('Workshop17','HR','Finance') NOT NULL,
  PRIMARY KEY (user_id, queue),
  CONSTRAINT fk_uqa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$PASSWORD_COL = null;
if (col_exists($pdo,'users','password_hash')) $PASSWORD_COL='password_hash';
elseif (col_exists($pdo,'users','password'))   $PASSWORD_COL='password';

/* ---------- POST ---------- */
$msg=null; $err=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();

  /* CREATE (admin) */
  if ($is_admin && isset($_POST['create'])) {
    try {
      $first=trim($_POST['first_name']??''); $last=trim($_POST['last_name']??'');
      $email=trim($_POST['email']??''); $usern=trim($_POST['username']??'');
      $role=(($_POST['role']??'user')==='admin')?'admin':'user';
      $active=isset($_POST['active'])?1:0;
      $dob=($_POST['date_of_birth']??'')?:null;
      $anniv=($_POST['work_anniversary']??'')?:null;
      $dept=trim($_POST['department']??''); $job=trim($_POST['job_title']??'');
      $mgr=(int)($_POST['manager_id']??0)?:null; $loc=(int)($_POST['location_id']??0)?:null;
      $phone=trim($_POST['phone']??''); $pwd=trim($_POST['password']??'');

      if ($first===''||$last===''||$email===''||$usern==='') throw new RuntimeException('Please complete all required fields.');
      if ($PASSWORD_COL && $pwd==='') throw new RuntimeException('Please set an initial password.');

      $cols=['first_name','last_name','email','username','role','active','manager_id','location_id','date_of_birth','work_anniversary','department','job_title','phone'];
      $vals=[$first,$last,$email,$usern,$role,$active,$mgr,$loc,$dob,$anniv,$dept,$job,$phone];
      if ($PASSWORD_COL && $pwd!==''){ $cols[]=$PASSWORD_COL; $vals[]=password_hash($pwd,PASSWORD_DEFAULT); }

      $place=implode(',', array_fill(0,count($cols),'?'));
      $pdo->prepare("INSERT INTO users (".implode(',',$cols).") VALUES ($place)")->execute($vals);
      $uid=(int)$pdo->lastInsertId();

      $sel=array_values(array_intersect($QUEUE_VALUES,(array)($_POST['queues']??[])));
      if ($sel){ $ins=$pdo->prepare("INSERT INTO user_queue_access (user_id,queue) VALUES (?,?)"); foreach($sel as $q)$ins->execute([$uid,$q]); }
      header('Location: users.php?msg=created'); exit;

    } catch(Throwable $ex){ $err=$ex->getMessage(); if (stripos($err,'duplicate')!==false||stripos($err,'unique')!==false)$err='Username or email already exists.'; }
  }

  /* UPDATE (self/admin/manager) */
  if (isset($_POST['save'])) {
    try {
      $id=(int)($_POST['id']??0); if($id<=0) throw new RuntimeException('Invalid user ID.');
      $can_full=$is_admin;
      if(!$is_admin && $id!==$my_id){
        $st=$pdo->prepare("SELECT 1 FROM users WHERE id=? AND manager_id=? AND active=1");
        $st->execute([$id,$my_id]); if(!$st->fetchColumn()){ http_response_code(403); exit('Access denied.'); }
      }
      $stc=$pdo->prepare("SELECT email,username,role,active,manager_id,location_id FROM users WHERE id=?");
      $stc->execute([$id]); $cur=$stc->fetch(PDO::FETCH_ASSOC);

      $first=trim($_POST['first_name']??''); $last=trim($_POST['last_name']??'');
      if($first===''||$last==='') throw new RuntimeException('First/Last name required.');
      $dob=($_POST['date_of_birth']??'')?:null; $anniv=($_POST['work_anniversary']??'')?:null;
      $dept=trim($_POST['department']??''); $job=trim($_POST['job_title']??''); $phone=trim($_POST['phone']??''); $pwd=trim($_POST['password']??'');

      $email=$can_full?trim($_POST['email']??''):$cur['email'];
      $usern=$can_full?trim($_POST['username']??''):$cur['username'];
      $role =$can_full?((($_POST['role']??'user')==='admin')?'admin':'user'):$cur['role'];
      $active=$can_full?(isset($_POST['active'])?1:0):$cur['active'];
      $mgr  =$can_full?((int)($_POST['manager_id']??0)?:null):$cur['manager_id'];
      $loc  =$can_full?((int)($_POST['location_id']??0)?:null):$cur['location_id'];

      $sets="first_name=?, last_name=?, email=?, username=?, role=?, active=?, manager_id=?, location_id=?, date_of_birth=?, work_anniversary=?, department=?, job_title=?, phone=?";
      $args=[$first,$last,$email,$usern,$role,$active,$mgr,$loc,$dob,$anniv,$dept,$job,$phone];
      if ($PASSWORD_COL && $pwd!==''){ $sets.=", $PASSWORD_COL=?"; $args[]=password_hash($pwd,PASSWORD_DEFAULT); }

      if($can_full){
        $pdo->prepare("DELETE FROM user_queue_access WHERE user_id=?")->execute([$id]);
        $sel=array_values(array_intersect($QUEUE_VALUES,(array)($_POST['queues']??[])));
        if($sel){ $ins=$pdo->prepare("INSERT INTO user_queue_access (user_id,queue) VALUES (?,?)"); foreach($sel as $q)$ins->execute([$id,$q]); }
      }
      $args[]=$id;
      $pdo->prepare("UPDATE users SET $sets WHERE id=?")->execute($args);
      header('Location: users.php?id='.$id.'&msg=saved'); exit;

    } catch(Throwable $ex){ $err=$ex->getMessage(); if (stripos($err,'duplicate')!==false||stripos($err,'unique')!==false)$err='Username or email already exists.'; }
  }

  /* DELETE (admin) */
  if ($is_admin && isset($_POST['delete'])) {
    $id=(int)($_POST['id']??0);
    if ($id===$my_id) { $err="You can't delete yourself."; }
    else { $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]); header('Location: users.php?msg=deleted'); exit; }
  }
}

/* ---------- Data ---------- */
$managers=$pdo->query("SELECT id, CONCAT_WS(' ',first_name,last_name) AS name FROM users ORDER BY first_name,last_name")->fetchAll(PDO::FETCH_KEY_PAIR);
$locationsKV=[];
$tbl=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='locations'");
$tbl->execute(); if($tbl->fetchColumn()){ $locationsKV=$pdo->query("SELECT id, name FROM locations ORDER BY code, name")->fetchAll(PDO::FETCH_KEY_PAIR); }

if ($is_list_view){
  $users=$pdo->query("SELECT u.*, CONCAT_WS(' ',m.first_name,m.last_name) AS manager_name FROM users u LEFT JOIN users m ON m.id=u.manager_id ORDER BY u.first_name,u.last_name")->fetchAll(PDO::FETCH_ASSOC);
  $queuesByUser=$pdo->query("SELECT user_id, GROUP_CONCAT(queue ORDER BY queue) AS qs FROM user_queue_access GROUP BY user_id")->fetchAll(PDO::FETCH_KEY_PAIR);
}else{
  $st=$pdo->prepare("SELECT u.*, CONCAT_WS(' ',m.first_name,m.last_name) AS manager_name FROM users u LEFT JOIN users m ON m.id=u.manager_id WHERE u.id=?");
  $st->execute([$view_id]); $target=$st->fetch(PDO::FETCH_ASSOC);
  if(!$target){ http_response_code(404); exit('User not found.'); }
  $stq=$pdo->prepare("SELECT queue FROM user_queue_access WHERE user_id=?"); $stq->execute([$view_id]); $tqueues=$stq->fetchAll(PDO::FETCH_COLUMN);
  $users=[$target]; $queuesByUser=[$view_id=>implode(',',$tqueues)];
}

$DEBUG = isset($_GET['debug']) && $_GET['debug']=='1';

include __DIR__ . '/partials/header.php';
?>
<div class="card">
  <div class="card-h" style="display:flex;justify-content:space-between;align-items:center">
    <h3>Dashboard — <?= $is_list_view ? 'Users' : 'My Profile' ?></h3>
    <?php if ($is_list_view): ?>
      <div>
        <button class="btn btn-primary" type="button" id="btnNewUser" data-open="new-user">+ New User</button>
      </div>
    <?php endif; ?>
  </div>
  <div class="card-b">
    <?php if(isset($_GET['msg'])): ?>
      <?php $m=$_GET['msg']; $text=['saved'=>'User saved','created'=>'User created','deleted'=>'User deleted'][$m] ?? e($m); ?>
      <div class="badge badge-success" style="display:block;margin-bottom:1rem;"><?= e($text) ?></div>
    <?php endif; ?>
    <?php if($err): ?>
      <div class="badge badge-danger" style="display:block;margin-bottom:1rem;"><?= e($err) ?></div>
    <?php endif; ?>

    <?php if ($is_list_view): ?>
      <div class="table-wrap">
        <table class="table" id="usersTable">
          <thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Active</th><th>Queues</th></tr></thead>
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
            <tr style="display:none"><td colspan="6">
              <span id="u<?= $uid ?>"
                    data-json='<?= e(json_encode($u, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>'
                    data-queues='<?= e(json_encode(($queuesByUser[$uid]??'')!==''? explode(',',$queuesByUser[$uid]) : [], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>'></span>
            </td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: $t=$users[0]; $tid=(int)$t['id']; ?>
      <p>Viewing profile for: <strong><?= e(trim(($t['first_name']??'').' '.($t['last_name']??''))) ?></strong></p>
      <div id="u<?= $tid ?>"
           data-json='<?= e(json_encode($t, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>'
           data-queues='<?= e(json_encode(($queuesByUser[$tid]??'')!==''? explode(',',$queuesByUser[$tid]) : [], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>'></div>
      <button class="btn btn-primary" type="button" onclick="openEdit(<?= $tid ?>); showModal('modal-edit-user');">Edit Profile</button>
    <?php endif; ?>
  </div>
</div>

<?php if ($is_list_view): ?>
<!-- New User Modal -->
<div id="modal-new-user" class="modal" style="display:none">
  <div class="modal-backdrop" data-close="modal-new-user"></div>
  <div class="modal-card" style="max-width:900px">
    <div class="modal-h"><h3>New User</h3><button class="btn btn-light btn-icon" type="button" onclick="hideModal('modal-new-user')">✕</button></div>
    <div class="modal-b">
      <form method="post" class="grid grid-2" id="formNewUser">
        <?php csrf_input(); ?><input type="hidden" name="create" value="1">
        <div class="grid" style="grid-template-columns:1fr"><label class="label">First Name *</label><input class="input" name="first_name" required></div>
        <div class="grid" style="grid-template-columns:1fr"><label class="label">Last Name *</label><input class="input" name="last_name" required></div>
        <div class="grid" style="grid-template-columns:1fr"><label class="label">Email *</label><input class="input" type="email" name="email" required></div>
        <div class="grid" style="grid-template-columns:1fr"><label class="label">Username *</label><input class="input" name="username" required></div>

        <div><label class="label">Role</label><select name="role" class="input"><option>user</option><option>admin</option></select></div>
        <div><label class="label">Manager</label><select name="manager_id" class="input"><option value="">—</option><?php foreach($managers as $mid=>$nm): ?><option value="<?= (int)$mid ?>"><?= e($nm) ?></option><?php endforeach; ?></select></div>
        <div><label class="label">Location</label><select name="location_id" class="input"><option value="">—</option><?php foreach($locationsKV as $lid=>$ln): ?><option value="<?= (int)$lid ?>"><?= e($ln) ?></option><?php endforeach; ?></select></div>

        <div><label class="label">Phone</label><input class="input" name="phone"></div>
        <div><label class="label">Department</label><input class="input" name="department"></div>
        <div><label class="label">Job Title</label><input class="input" name="job_title"></div>
        <div><label class="label">Date of Birth</label><input class="input" type="date" name="date_of_birth"></div>
        <div><label class="label">Work Anniversary</label><input class="input" type="date" name="work_anniversary"></div>

        <div class="grid" style="grid-template-columns:1fr">
          <label class="label">Queue Visibility</label>
          <div class="chipset"><?php foreach($QUEUE_VALUES as $q): ?><label class="chip"><input type="checkbox" name="queues[]" value="<?= e($q) ?>"> <span><?= e($q) ?></span></label><?php endforeach; ?></div>
        </div>

        <?php if ($PASSWORD_COL): ?>
        <div class="grid" style="grid-template-columns:1fr"><label class="label">Initial Password *</label><input class="input" type="password" name="password" autocomplete="new-password" required></div>
        <?php endif; ?>
        <div><label class="label">Active</label><input type="checkbox" name="active" value="1" checked></div>

        <div style="grid-column:1 / -1; display:flex; gap:.5rem; flex-wrap:wrap">
          <button class="btn btn-primary">Create User</button>
          <button class="btn btn-light" type="button" onclick="hideModal('modal-new-user')">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Edit User Modal -->
<div id="modal-edit-user" class="modal" style="display:none">
  <div class="modal-backdrop" data-close="modal-edit-user"></div>
  <div class="modal-card" style="max-width:900px">
    <div class="modal-h"><h3 id="edit_dlg_title">Edit User</h3><button class="btn btn-light btn-icon" type="button" onclick="hideModal('modal-edit-user')">✕</button></div>
    <div class="modal-b">
      <form method="post" class="grid grid-2" id="formEditUser">
        <?php csrf_input(); ?><input type="hidden" name="save" value="1"><input type="hidden" name="id" id="f_id">
        <div class="grid" style="grid-template-columns:1fr"><label class="label">First Name *</label><input class="input" name="first_name" id="f_first" required></div>
        <div class="grid" style="grid-template-columns:1fr"><label class="label">Last Name *</label><input class="input" name="last_name" id="f_last" required></div>

        <div class="grid" style="grid-template-columns:1fr"><label class="label">Email *</label><input class="input" type="email" name="email" id="f_email" required></div>
        <div class="grid" style="grid-template-columns:1fr"><label class="label">Username *</label><input class="input" name="username" id="f_usern" required></div>

        <div class="field_admin"><label class="label">Role</label><select name="role" id="f_role" class="input"><option>user</option><option>admin</option></select></div>
        <div class="field_admin"><label class="label">Manager</label><select name="manager_id" id="f_mgr" class="input"><option value="">—</option><?php foreach($managers as $mid=>$nm): ?><option value="<?= (int)$mid ?>"><?= e($nm) ?></option><?php endforeach; ?></select></div>
        <div class="field_admin"><label class="label">Location</label><select name="location_id" id="f_loc" class="input"><option value="">—</option><?php foreach($locationsKV as $lid=>$ln): ?><option value="<?= (int)$lid ?>"><?= e($ln) ?></option><?php endforeach; ?></select></div>

        <div class="grid" style="grid-template-columns:1fr"><label class="label">Phone</label><input class="input" name="phone" id="f_phone"></div>
        <div class="grid" style="grid-template-columns:1fr"><label class="label">Department</label><input class="input" name="department" id="f_dept"></div>
        <div class="grid" style="grid-template-columns:1fr"><label class="label">Job Title</label><input class="input" name="job_title" id="f_job"></div>

        <div class="grid" style="grid-template-columns:1fr"><label class="label">Date of Birth</label><input class="input" type="date" name="date_of_birth" id="f_dob"></div>
        <div class="grid" style="grid-template-columns:1fr"><label class="label">Work Anniversary</label><input class="input" type="date" name="work_anniversary" id="f_anniv"></div>

        <div class="grid field_admin" style="grid-template-columns:1fr">
          <label class="label">Queue Visibility</label>
          <div class="chipset"><?php foreach($QUEUE_VALUES as $q): ?><label class="chip"><input type="checkbox" name="queues[]" value="<?= e($q) ?>" class="qcb"> <span><?= e($q) ?></span></label><?php endforeach; ?></div>
        </div>

        <?php if ($PASSWORD_COL): ?>
        <div class="grid" style="grid-template-columns:1fr"><label class="label">New Password (leave blank to keep)</label><input class="input" type="password" name="password" autocomplete="new-password"></div>
        <?php endif; ?>

        <div class="field_admin"><label class="label">Active</label><input type="checkbox" name="active" id="f_active" value="1"></div>

        <div style="grid-column:1 / -1; display:flex; gap:.5rem; flex-wrap:wrap">
          <button class="btn btn-primary">Save</button>
          <button class="btn btn-light" type="button" onclick="hideModal('modal-edit-user')">Cancel</button>
        </div>
      </form>

      <?php if ($is_admin): ?>
      <form method="post" onsubmit="return confirm('Delete this user?')" style="display:inline-block;margin: .5rem 0 0 .5rem">
        <?php csrf_input(); ?><input type="hidden" name="id" id="del_id"><button class="btn btn-danger" name="delete" value="1">Delete</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<style>
/* ensure our modals always win the stacking contest */
.modal{position:fixed;inset:0;z-index:2000;display:flex;align-items:center;justify-content:center}
.modal-backdrop{position:absolute;inset:0;background:rgba(0,0,0,.35)}
.modal-card{position:relative;background:var(--white);border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,.2);width:min(900px,94vw);max-height:92vh;overflow:auto}
.modal-h{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid #e5e7eb}
.modal-b{padding:1rem 1.25rem}
.label{font-weight:600;margin:.25rem 0}
.chipset{display:flex;gap:.5rem;flex-wrap:wrap}
.chip{display:inline-flex;align-items:center;gap:.35rem;border:1px solid var(--gray-200);border-radius:999px;padding:.35rem .6rem;background:#fafbfc}
.input-readonly{background:#f5f6f7}
<?php if($DEBUG): ?>
#usersDiag{position:fixed;right:10px;bottom:10px;background:#111;color:#fff;padding:6px 8px;border-radius:8px;font:12px/1.3 monospace;z-index:99999;opacity:.9}
<?php endif; ?>
</style>

<script>
/* ===== Modal shim: works with or without site openModal() ===== */
function _findModalEl(id){
  // Accept 'modal-edit-user' | 'edit-user' | 'edit'
  const base = (id||'').replace(/^modal-/, '');
  return document.getElementById('modal-'+base) || document.getElementById(id) || document.getElementById(base);
}
function showModal(id){
  const dbg = (msg)=>{ try{ if(window.usersDiag) usersDiag.textContent=msg; console.log('[users]', msg); }catch(_){} };
  const base = (id||'').replace(/^modal-/, '');
  // Try site helper (both flavors)
  if (typeof window.openModal === 'function') {
    try { window.openModal(base); dbg('openModal('+base+')'); } catch(e){ console.warn(e); }
    try { window.openModal('modal-'+base); dbg('openModal(modal-'+base+')'); } catch(e){/*no-op*/}
  }
  // Hard fallback
  const el = _findModalEl(id);
  if (el){ el.style.display='flex'; el.removeAttribute('aria-hidden'); document.documentElement.style.overflow='hidden'; dbg('fallback display:flex on #'+el.id); }
  else { dbg('modal not found for id='+id); }
}
function hideModal(id){
  if (typeof window.closeModal === 'function') {
    try { window.closeModal(id.replace(/^modal-/, '')); } catch(e){}
    try { window.closeModal(id); } catch(e){}
  }
  const el=_findModalEl(id);
  if(el){ el.style.display='none'; el.setAttribute('aria-hidden','true'); document.documentElement.style.overflow=''; }
}

/* ===== Page wiring ===== */
const IS_ADMIN = <?= $is_admin ? 'true' : 'false' ?>;
const MY_ID    = <?= (int)$my_id ?>;

function safeDate(v){ return (/^\d{4}-\d{2}-\d{2}$/).test((v||'')) ? v : ''; }

function openEdit(id){
  const el=document.getElementById('u'+id); if(!el) return;
  const u = JSON.parse(el.getAttribute('data-json')||'{}');
  const qs= JSON.parse(el.getAttribute('data-queues')||'[]');

  document.getElementById('f_id').value   = u.id || '';
  const delId=document.getElementById('del_id'); if(delId) delId.value=u.id||'';
  document.getElementById('f_first').value= u.first_name || '';
  document.getElementById('f_last').value = u.last_name || '';
  document.getElementById('f_phone').value= u.phone || '';
  document.getElementById('f_dept').value = u.department || '';
  document.getElementById('f_job').value  = u.job_title || '';
  document.getElementById('f_dob').value  = safeDate(u.date_of_birth);
  document.getElementById('f_anniv').value= safeDate(u.work_anniversary);

  document.getElementById('f_email').value= u.email || '';
  document.getElementById('f_usern').value= u.username || '';
  document.getElementById('f_role').value = u.role || 'user';
  document.getElementById('f_mgr').value  = u.manager_id || '';
  document.getElementById('f_loc').value  = u.location_id || '';
  const act=document.getElementById('f_active'); if(act) act.checked=(u.active==1);

  document.querySelectorAll('.field_admin').forEach(el=>{ el.style.display = IS_ADMIN ? 'grid' : 'none'; });
  const ro = !IS_ADMIN;
  document.getElementById('f_email').readOnly = ro;
  document.getElementById('f_usern').readOnly = ro;
  document.getElementById('f_email').classList.toggle('input-readonly', ro);
  document.getElementById('f_usern').classList.toggle('input-readonly', ro);

  document.querySelectorAll('#modal-edit-user input.qcb').forEach(cb=>{
    cb.checked = qs.includes(cb.value);
    cb.disabled = !IS_ADMIN;
  });

  document.getElementById('edit_dlg_title').textContent = (id===MY_ID) ? 'Edit My Profile' : 'Edit User';
}

(function(){
  // debug overlay
  <?php if($DEBUG): ?>window.usersDiag = document.createElement('div'); usersDiag.id='usersDiag'; usersDiag.textContent='ready'; document.body.appendChild(usersDiag);<?php endif; ?>

  // Table row -> edit
  document.querySelectorAll('#usersTable tbody tr.row-openable').forEach(tr=>{
    tr.addEventListener('click', ()=>{
      const id=parseInt(tr.getAttribute('data-id')||'0',10);
      console.log('[users] openEdit', id);
      openEdit(id);
      showModal('modal-edit-user');
    }, {passive:true});
  });

  // New user button
  const btnNew=document.getElementById('btnNewUser');
  if(btnNew){ btnNew.addEventListener('click', ()=>{ showModal('modal-new-user'); }); }

  // Backdrop click closes
  document.querySelectorAll('.modal .modal-backdrop').forEach(bd=>{
    bd.addEventListener('click', ()=> hideModal(bd.getAttribute('data-close') || (bd.parentElement && bd.parentElement.id) || ''));
  });
  // ESC
  window.addEventListener('keydown', (e)=>{ if(e.key==='Escape'){ ['modal-edit-user','modal-new-user'].forEach(hideModal); }});

  // Sanity when debug=1 — open/close quickly so we know the element is found
  <?php if($DEBUG): ?>
  setTimeout(()=>{ showModal('modal-edit-user'); setTimeout(()=>hideModal('modal-edit-user'), 250); }, 150);
  setTimeout(()=>{ showModal('modal-new-user');  setTimeout(()=>hideModal('modal-new-user'),  250); }, 450);
  <?php endif; ?>
})();
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
