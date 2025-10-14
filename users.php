<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_admin();

/* session + csrf */
if (!function_exists('session_status') || session_status() !== PHP_SESSION_ACTIVE) {
  if (session_id() === '') { session_start(); }
}
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(openssl_random_pseudo_bytes(32)); }

/* db */
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* helpers: detect live columns safely */
function table_cols(PDO $pdo, $table){
  static $cache = [];
  if (isset($cache[$table])) return $cache[$table];
  $cols = [];
  try {
    $st = $pdo->query("SHOW COLUMNS FROM `{$table}`");
    foreach ($st as $r) { $cols[$r['Field']] = true; }
  } catch (Exception $e) { $cols = []; }
  return $cache[$table] = $cols;
}
$UC = table_cols($pdo,'users');

/* build a display name expr that always exists */
function user_display_name_sql($alias, $UC){
  if (isset($UC['name'])) return "{$alias}.name";
  $parts = [];
  if (isset($UC['first_name'])) $parts[] = "COALESCE({$alias}.first_name,'')";
  if (isset($UC['last_name']))  $parts[] = "COALESCE({$alias}.last_name,'')";
  if ($parts) return "TRIM(CONCAT(" . implode(", ' ', ", $parts) . "))";
  if (isset($UC['email'])) return "{$alias}.email";
  return "''";
}

/* split "Name" into first/last if needed */
function split_name($name){
  $name = trim((string)$name);
  if ($name === '') return ['',''];
  $parts = preg_split('/\s+/', $name);
  $first = array_shift($parts);
  $last  = trim(implode(' ', $parts));
  return [$first, $last];
}

/* ---------- JSON API ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_GET['api'])) {
  header('Content-Type: application/json');
  $in = json_decode(file_get_contents('php://input'), true);
  if (!is_array($in)) $in = [];
  if (!isset($_SESSION['csrf']) || ($in['csrf'] ?? '') !== $_SESSION['csrf']) {
    http_response_code(400); echo json_encode(['ok'=>false,'error'=>'CSRF']); exit;
  }

  $api = $_GET['api'];

  if ($api === 'get') {
    $id = (int)($in['id'] ?? 0);
    if ($id<=0){ echo json_encode(['ok'=>false,'error'=>'Invalid id']); exit; }

    // select columns that exist
    $fields = ['id'];
    foreach (['email','role','phone','mobile','manager_id','active'] as $f) if (isset($UC[$f])) $fields[] = $f;

    $sql = "SELECT ".implode(',', array_map(function($f){return "u.$f";}, $fields))." , "
         . user_display_name_sql('u', $UC) . " AS __display_name "
         . "FROM users u WHERE u.id=?";
    $st = $pdo->prepare($sql); $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if ($row && !isset($UC['name'])) {
      // return "name" virtual for the form
      $row['name'] = $row['__display_name'];
    }
    unset($row['__display_name']);

    echo json_encode(['ok'=>true,'user'=>$row]); exit;
  }

  if ($api === 'save') {
    $id = (int)($in['id'] ?? 0);

    // prepare columns and values that exist
    $set = []; $vals = [];

    // name handling
    if (isset($in['name'])) {
      $name = trim((string)$in['name']);
      if (isset($UC['name'])) {
        $set[] = "name=?"; $vals[] = $name;
      } else {
        list($first,$last) = split_name($name);
        if (isset($UC['first_name'])) { $set[]="first_name=?"; $vals[]=$first; }
        if (isset($UC['last_name']))  { $set[]="last_name=?";  $vals[]=$last;  }
      }
    }

    // other optional fields
    $map = ['email','role','phone','mobile'];
    foreach ($map as $f) {
      if (isset($UC[$f]) && isset($in[$f])) { $set[]="$f=?"; $vals[] = trim((string)$in[$f]); }
    }
    if (isset($UC['manager_id']) && array_key_exists('manager_id',$in)) {
      $set[]="manager_id=?"; $vals[] = ($in['manager_id'] === '' ? null : (int)$in['manager_id']);
    }
    if (isset($UC['active']) && array_key_exists('active',$in)) {
      $set[]="active=?"; $vals[] = !empty($in['active']) ? 1 : 0;
    }

    try {
      if ($id>0) {
        if ($set) {
          $sql="UPDATE users u SET ".implode(',', $set)." WHERE u.id=?";
          $vals[]=$id;
          $st=$pdo->prepare($sql); $st->execute($vals);
        }
      } else {
        $cols=[]; $qs=[]; $ins=[];
        foreach ($set as $k=>$assign){
          list($col,) = explode('=', $assign, 2);
          $cols[] = trim($col); $qs[]='?'; $ins[] = $vals[$k];
        }
        if (isset($UC['password_hash'])) { $cols[]='password_hash'; $qs[]='SHA2(CONCAT(UUID(),RAND()),256)'; }
        if (isset($UC['created_at']))    { $cols[]='created_at';    $qs[]='NOW()'; }
        if ($cols) {
          $sql = "INSERT INTO users (".implode(',',$cols).") VALUES (".implode(',',$qs).")";
          $st=$pdo->prepare($sql); $st->execute($ins);
        }
      }
      echo json_encode(['ok'=>true]);
    } catch (Exception $e) {
      http_response_code(500); echo json_encode(['ok'=>false,'error'=>'DB error']);
    }
    exit;
  }

  if ($api === 'delete') {
    $id=(int)($in['id']??0);
    $me = user();
    if ($me && (int)($me['id'] ?? 0) === $id) { echo json_encode(['ok'=>false,'error'=>'Cannot delete current user']); exit; }
    $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]); exit;
  }

  echo json_encode(['ok'=>false]); exit;
}

/* ---------- Page ---------- */
$nameExpr = user_display_name_sql('u', $UC) . " AS display_name";
$select = ["u.id", $nameExpr];
if (isset($UC['email']))  $select[]='u.email';
if (isset($UC['role']))   $select[]='u.role';
if (isset($UC['phone']))  $select[]='u.phone';
if (isset($UC['mobile'])) $select[]='u.mobile';
if (isset($UC['active'])) $select[]='u.active';

$joinMgr = isset($UC['manager_id']);
if ($joinMgr) { $select[]="COALESCE(m.name, m.email, '') AS manager_name"; $select[]='u.manager_id'; }

$sql="SELECT ".implode(',', $select)." FROM users u ";
if ($joinMgr) $sql.="LEFT JOIN users m ON m.id=u.manager_id ";
$sql.="ORDER BY display_name ASC";
$users = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// managers for dropdown
$managers = $pdo->query("SELECT id, ".user_display_name_sql('x',$UC)." AS name FROM users x ".(isset($UC['role'])?"WHERE x.role IN ('manager','admin')":"")." ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$page_title='Users';
$auth_page=false;
require __DIR__ . '/partials/header.php';
?>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Users</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#userModal" data-mode="create">Add User</button>
  </div>

  <div class="card">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table mb-0 align-middle">
          <thead>
            <tr>
              <th>Name</th><?php if (isset($UC['email'])): ?><th>Email</th><?php endif; ?>
              <?php if (isset($UC['role'])): ?><th>Role</th><?php endif; ?>
              <?php if ($joinMgr): ?><th>Manager</th><?php endif; ?>
              <?php if (isset($UC['phone'])): ?><th>Phone</th><?php endif; ?>
              <?php if (isset($UC['mobile'])): ?><th>Mobile</th><?php endif; ?>
              <?php if (isset($UC['active'])): ?><th>Active</th><?php endif; ?>
              <th style="width:120px;">Actions</th>
            </tr>
          </thead>
          <tbody id="usersBody">
          <?php foreach($users as $u): ?>
            <tr data-id="<?php echo (int)$u['id']; ?>">
              <td><?php echo htmlspecialchars($u['display_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
              <?php if (isset($UC['email'])): ?><td><?php echo htmlspecialchars($u['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td><?php endif; ?>
              <?php if (isset($UC['role'])):  ?><td><?php echo htmlspecialchars($u['role'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td><?php endif; ?>
              <?php if ($joinMgr): ?><td><?php echo htmlspecialchars($u['manager_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td><?php endif; ?>
              <?php if (isset($UC['phone'])):  ?><td><?php echo htmlspecialchars($u['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td><?php endif; ?>
              <?php if (isset($UC['mobile'])): ?><td><?php echo htmlspecialchars($u['mobile'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td><?php endif; ?>
              <?php if (isset($UC['active'])): ?><td><?php echo !empty($u['active'])?'Yes':'No'; ?></td><?php endif; ?>
              <td>
                <button class="btn btn-sm btn-outline-secondary me-1 btn-edit" data-bs-toggle="modal" data-bs-target="#userModal">Edit</button>
                <button class="btn btn-sm btn-outline-danger btn-del">Delete</button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form id="userForm" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf']; ?>">
        <input type="hidden" id="u_id">
        <div class="mb-3">
          <label class="form-label">Name</label>
          <input class="form-control" id="u_name" required>
        </div>
        <?php if (isset($UC['email'])): ?>
        <div class="mb-3">
          <label class="form-label">Email</label>
          <input class="form-control" type="email" id="u_email" required>
        </div>
        <?php endif; ?>
        <?php if (isset($UC['role'])): ?>
        <div class="mb-3">
          <label class="form-label">Role</label>
          <select class="form-select" id="u_role">
            <option value="user">user</option>
            <option value="manager">manager</option>
            <option value="admin">admin</option>
          </select>
        </div>
        <?php endif; ?>
        <?php if ($joinMgr): ?>
        <div class="mb-3">
          <label class="form-label">Manager</label>
          <select class="form-select" id="u_manager_id">
            <option value="">None</option>
            <?php foreach($managers as $m): ?>
              <option value="<?php echo (int)$m['id'];?>"><?php echo htmlspecialchars($m['name'], ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <?php if (isset($UC['phone']) || isset($UC['mobile'])): ?>
        <div class="row g-3">
          <?php if (isset($UC['phone'])): ?>
          <div class="col-md-6">
            <label class="form-label">Phone</label>
            <input class="form-control" id="u_phone">
          </div>
          <?php endif; ?>
          <?php if (isset($UC['mobile'])): ?>
          <div class="col-md-6">
            <label class="form-label">Mobile</label>
            <input class="form-control" id="u_mobile">
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (isset($UC['active'])): ?>
        <div class="form-check mt-3">
          <input class="form-check-input" type="checkbox" id="u_active">
          <label class="form-check-label" for="u_active">Active</label>
        </div>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  var tbody = document.getElementById('usersBody');
  var modalEl = document.getElementById('userModal');
  var form = document.getElementById('userForm');

  function csrf(){
    var el = form.querySelector('input[name="csrf"]');
    return el ? el.value : (window.CSRF || '');
  }
  function fill(u){
    document.getElementById('u_id').value = u.id || '';
    document.getElementById('u_name').value = u.name || u.display_name || '';
    var e = document.getElementById('u_email'); if (e) e.value = u.email || '';
    var r = document.getElementById('u_role'); if (r && u.role) r.value = u.role;
    var m = document.getElementById('u_manager_id'); if (m) m.value = (u.manager_id != null ? u.manager_id : '');
    var p = document.getElementById('u_phone');  if (p) p.value = u.phone || '';
    var mb= document.getElementById('u_mobile'); if (mb) mb.value = u.mobile || '';
    var ac= document.getElementById('u_active'); if (ac) ac.checked = !!(u.active*1 || u.active===true);
  }
  function api(path, payload){
    return fetch(path, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) }).then(function(r){ return r.json(); });
  }

  var createBtn = document.querySelector('[data-bs-target="#userModal"][data-mode="create"]');
  if (createBtn) createBtn.addEventListener('click', function(){ fill({id:'',name:'',role:'user',manager_id:'',active:1}); });

  tbody.addEventListener('click', function(e){
    var btnEdit = e.target.closest ? e.target.closest('.btn-edit') : null;
    var btnDel  = e.target.closest ? e.target.closest('.btn-del')  : null;
    var tr = e.target.closest ? e.target.closest('tr') : null; if (!tr) return;
    var id = +tr.getAttribute('data-id');

    if (btnEdit){
      api('users.php?api=get', {id:id, csrf: csrf()}).then(function(data){
        if (!data.ok) { alert(data.error||'Failed to load'); return; }
        fill(data.user||{});
        try { new bootstrap.Modal(modalEl).show(); } catch(_){}
      });
    }
    if (btnDel){
      if (!confirm('Delete this user?')) return;
      api('users.php?api=delete', {id:id, csrf: csrf()}).then(function(data){
        if (data.ok) location.reload(); else alert(data.error||'Delete failed');
      });
    }
  });

  form.addEventListener('submit', function(e){
    e.preventDefault();
    var payload = {
      id: document.getElementById('u_id').value,
      name: document.getElementById('u_name').value,
      csrf: csrf()
    };
    var e1 = document.getElementById('u_email'); if (e1) payload.email = e1.value;
    var r1 = document.getElementById('u_role');  if (r1) payload.role  = r1.value;
    var m1 = document.getElementById('u_manager_id'); if (m1) payload.manager_id = m1.value;
    var p1 = document.getElementById('u_phone'); if (p1) payload.phone = p1.value;
    var m2 = document.getElementById('u_mobile'); if (m2) payload.mobile = m2.value;
    var a1 = document.getElementById('u_active'); if (a1) payload.active = a1.checked ? 1 : 0;

    api('users.php?api=save', payload).then(function(data){
      if (data.ok) location.reload(); else alert(data.error||'Save failed');
    });
  });
})();
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
