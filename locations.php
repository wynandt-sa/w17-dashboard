<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_admin();

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------- JSON API ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_GET['api'])) {
  header('Content-Type: application/json');
  $in = json_decode(file_get_contents('php://input'), true) ?? [];
  if (($in['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'CSRF']); exit; }

  if ($_GET['api']==='get') {
    $id=(int)($in['id']??0);
    $st=$pdo->prepare("SELECT id,site_code,name,address,phone_ext,birthdate,active FROM locations WHERE id=?");
    $st->execute([$id]);
    echo json_encode(['ok'=>true,'location'=>$st->fetch(PDO::FETCH_ASSOC)]); exit;
  }

  if ($_GET['api']==='save') {
    $id=(int)($in['id']??0);
    $site_code=trim($in['site_code']??'');
    $name=trim($in['name']??'');
    $address=trim($in['address']??'');
    $phone_ext=preg_replace('/\D/','',$in['phone_ext']??''); // keep digits
    if ($phone_ext!=='' && strlen($phone_ext)!==4) { echo json_encode(['ok'=>false,'error'=>'Phone extension must be 4 digits']); exit; }
    $birthdate=trim($in['birthdate']??''); // YYYY-MM-DD
    $active=!empty($in['active'])?1:0;

    if ($id>0){
      $st=$pdo->prepare("UPDATE locations SET site_code=?,name=?,address=?,phone_ext=?,birthdate=?,active=? WHERE id=?");
      $st->execute([$site_code,$name,$address,$phone_ext?:null,$birthdate?:null,$active,$id]);
    } else {
      $st=$pdo->prepare("INSERT INTO locations (site_code,name,address,phone_ext,birthdate,active,created_at) VALUES (?,?,?,?,?,?,NOW())");
      $st->execute([$site_code,$name,$address,$phone_ext?:null,$birthdate?:null,$active]);
    }
    echo json_encode(['ok'=>true]); exit;
  }

  if ($_GET['api']==='delete') {
    $id=(int)($in['id']??0);
    $pdo->prepare("DELETE FROM locations WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]); exit;
  }

  echo json_encode(['ok'=>false]); exit;
}

/* ---------- Page ---------- */
$rows=$pdo->query("SELECT id,site_code,name,address,phone_ext,birthdate,active FROM locations ORDER BY site_code ASC")->fetchAll(PDO::FETCH_ASSOC);

$auth_page=false;
require __DIR__ . '/partials/header.php';
?>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Locations</h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#locModal" data-mode="create">Add Location</button>
  </div>

  <div class="card">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table mb-0 align-middle">
          <thead>
            <tr>
              <th>Site Code</th><th>Name</th><th>Address</th><th>Ext</th><th>Birthdate</th><th>Active</th><th style="width:120px;">Actions</th>
            </tr>
          </thead>
          <tbody id="locBody">
          <?php foreach($rows as $r): ?>
            <tr data-id="<?php echo (int)$r['id'];?>">
              <td><?php echo e($r['site_code']);?></td>
              <td><?php echo e($r['name']);?></td>
              <td><?php echo e($r['address']);?></td>
              <td><?php echo e($r['phone_ext']);?></td>
              <td><?php echo e($r['birthdate']);?></td>
              <td><?php echo $r['active']?'Yes':'No';?></td>
              <td>
                <button class="btn btn-sm btn-outline-secondary me-1 btn-edit" data-bs-toggle="modal" data-bs-target="#locModal">Edit</button>
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
<div class="modal fade" id="locModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form id="locForm" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Location</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id" id="l_id">
        <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf']; ?>">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Site Code</label>
            <input class="form-control" name="site_code" id="l_site_code" required>
          </div>
          <div class="col-md-8">
            <label class="form-label">Name</label>
            <input class="form-control" name="name" id="l_name" required>
          </div>
        </div>
        <div class="mb-3 mt-3">
          <label class="form-label">Address</label>
          <input class="form-control" name="address" id="l_address">
        </div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Phone Extension</label>
            <input class="form-control" name="phone_ext" id="l_phone_ext" inputmode="numeric" pattern="\\d{4}" placeholder="4 digits">
            <small class="text-muted">4 digits</small>
          </div>
          <div class="col-md-6">
            <label class="form-label">Birthdate</label>
            <input class="form-control" type="date" name="birthdate" id="l_birthdate">
          </div>
        </div>
        <div class="form-check mt-3">
          <input class="form-check-input" type="checkbox" id="l_active">
          <label class="form-check-label" for="l_active">Active</label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
const locBody=document.getElementById('locBody');
const locModal=document.getElementById('locModal');

locBody.addEventListener('click', async (e)=>{
  const tr=e.target.closest('tr'); if(!tr) return;
  const id=+tr.dataset.id;

  if (e.target.classList.contains('btn-edit')) {
    const res=await fetch('locations.php?api=get',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id,csrf:window.CSRF})});
    const data=await res.json();
    if(!data.ok) return alert(data.error||'Load failed');
    fillLocForm(data.location||{});
  }
  if (e.target.classList.contains('btn-del')) {
    if(!confirm('Delete this location?')) return;
    const res=await fetch('locations.php?api=delete',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id,csrf:window.CSRF})});
    const data=await res.json();
    if(data.ok) location.reload(); else alert(data.error||'Delete failed');
  }
});

document.querySelector('[data-bs-target="#locModal"][data-mode="create"]')?.addEventListener('click',()=>{
  fillLocForm({id:'',site_code:'',name:'',address:'',phone_ext:'',birthdate:'',active:1});
});

function fillLocForm(l){
  document.getElementById('l_id').value=l.id||'';
  document.getElementById('l_site_code').value=l.site_code||'';
  document.getElementById('l_name').value=l.name||'';
  document.getElementById('l_address').value=l.address||'';
  document.getElementById('l_phone_ext').value=l.phone_ext||'';
  document.getElementById('l_birthdate').value=l.birthdate||'';
  document.getElementById('l_active').checked=!!(+l.active||l.active===true);
}

document.getElementById('locForm').addEventListener('submit', async (e)=>{
  e.preventDefault();
  const payload={
    id:document.getElementById('l_id').value,
    site_code:document.getElementById('l_site_code').value,
    name:document.getElementById('l_name').value,
    address:document.getElementById('l_address').value,
    phone_ext:document.getElementById('l_phone_ext').value,
    birthdate:document.getElementById('l_birthdate').value,
    active:document.getElementById('l_active').checked?1:0,
    csrf:window.CSRF
  };
  const res=await fetch('locations.php?api=save',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
  const data=await res.json();
  if(data.ok) location.reload(); else alert(data.error||'Save failed');
});
</script>
</body>
</html>
