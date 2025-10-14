<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

if (!function_exists('session_status') || session_status() !== PHP_SESSION_ACTIVE) {
  if (session_id() === '') { session_start(); }
}
if (empty($_SESSION['csrf'])) { $_SESSION['csrf']=bin2hex(openssl_random_pseudo_bytes(32)); }

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function cols(PDO $pdo,$table){
  static $cache=[]; if (isset($cache[$table])) return $cache[$table];
  $out=[]; try{ foreach($pdo->query("SHOW COLUMNS FROM `$table`") as $r){ $out[$r['Field']]=true; } }catch(Exception $e){ $out=[]; }
  return $cache[$table]=$out;
}
$TC = cols($pdo,'tasks');
$UC = cols($pdo,'users');

/* API: toggle completed */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_GET['api']) && $_GET['api']==='toggle') {
  header('Content-Type: application/json');
  $in = json_decode(file_get_contents('php://input'), true); if (!is_array($in)) $in=[];
  if (!isset($_SESSION['csrf']) || ($in['csrf'] ?? '') !== $_SESSION['csrf']) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'CSRF']); exit; }
  if (!isset($TC['completed'])) { echo json_encode(['ok'=>false,'error'=>'Missing column: tasks.completed']); exit; }
  $id=(int)($in['id']??0);
  $completed=!empty($in['completed'])?1:0;
  try { $st=$pdo->prepare("UPDATE tasks SET completed=? WHERE id=?"); $st->execute([$completed,$id]); echo json_encode(['ok'=>true]); }
  catch(Exception $e){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'DB error']); }
  exit;
}

/* Create task */
$me = user(); $my_id = (int)($me['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['create_task']) && isset($_POST['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
  $title=trim($_POST['title']??'');
  if ($title!=='') {
    $assignee = (!empty($_POST['assignee_id'])) ? (int)$_POST['assignee_id'] : $my_id;
    $cols=['title']; $qs=['?']; $vals=[$title];
    if (isset($TC['assignee_id'])) { $cols[]='assignee_id'; $qs[]='?'; $vals[]=$assignee; }
    if (isset($TC['created_by']))  { $cols[]='created_by';  $qs[]='?'; $vals[]=$my_id; }
    if (isset($TC['completed']))   { $cols[]='completed';   $qs[]='?'; $vals[]=0; }
    if (isset($TC['created_at']))  { $cols[]='created_at';  $qs[]='NOW()'; }
    $sql="INSERT INTO tasks (".implode(',',$cols).") VALUES (".implode(',',$qs).")";
    $st=$pdo->prepare($sql); $st->execute($vals);
    header('Location: tasks.php'); exit;
  }
}

/* Data */
$users = $pdo->query("SELECT id, ".(isset($UC['name'])?'name':'email')." AS name FROM users ".(isset($UC['active'])?'WHERE active=1 ':'')."ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$sel = ['t.id','t.title'];
if (isset($TC['completed']))  $sel[]='t.completed';
if (isset($TC['created_at'])) $sel[]='t.created_at';
$joinAssignee = isset($TC['assignee_id']);
if ($joinAssignee) $sel[]='u.name AS assignee';

$sql = "SELECT ".implode(',', $sel)." FROM tasks t ";
if ($joinAssignee) $sql .= "LEFT JOIN users u ON u.id=t.assignee_id ";
$sql .= "ORDER BY ".(isset($TC['created_at'])?'t.created_at DESC':'t.id DESC');
$tasks = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$page_title='Tasks';
$auth_page=false;
require __DIR__ . '/partials/header.php';
?>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Tasks</h4>
    <form class="d-flex align-items-center gap-2" method="post">
      <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf'];?>">
      <input type="hidden" name="create_task" value="1">
      <input name="title" class="form-control" placeholder="New task" required>
      <?php if ($joinAssignee): ?>
      <select name="assignee_id" class="form-select">
        <option value="">Assign to me</option>
        <?php foreach($users as $u): ?>
          <option value="<?php echo (int)$u['id'];?>"><?php echo htmlspecialchars($u['name'], ENT_QUOTES, 'UTF-8'); ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <button class="btn btn-primary" type="submit">Add</button>
    </form>
  </div>

  <div class="card">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table mb-0 align-middle">
          <thead>
            <tr>
              <?php if (isset($TC['completed'])): ?><th style="width:60px;">Done</th><?php endif; ?>
              <th>Title</th>
              <?php if ($joinAssignee): ?><th>Assignee</th><?php endif; ?>
              <?php if (isset($TC['created_at'])): ?><th>Created</th><?php endif; ?>
            </tr>
          </thead>
          <tbody id="taskBody">
            <?php foreach($tasks as $t): ?>
              <tr data-id="<?php echo (int)$t['id'];?>">
                <?php if (isset($TC['completed'])): ?>
                <td><input class="form-check-input task-toggle" type="checkbox" <?php echo !empty($t['completed'])?'checked':'';?>></td>
                <?php endif; ?>
                <td><?php echo htmlspecialchars($t['title'], ENT_QUOTES, 'UTF-8'); ?></td>
                <?php if ($joinAssignee): ?><td><?php echo htmlspecialchars($t['assignee'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td><?php endif; ?>
                <?php if (isset($TC['created_at'])): ?><td><?php echo htmlspecialchars($t['created_at'], ENT_QUOTES, 'UTF-8'); ?></td><?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php if (isset($TC['completed'])): ?>
<script>
(function(){
  var tbody = document.getElementById('taskBody');
  function csrf(){ var el=document.querySelector('input[name="csrf"]'); return el?el.value:(window.CSRF||''); }
  tbody.addEventListener('change', function(e){
    if (!e.target.classList.contains('task-toggle')) return;
    var tr = e.target.closest ? e.target.closest('tr') : null; if (!tr) return;
    var id = +tr.getAttribute('data-id');
    var completed = e.target.checked ? 1 : 0;
    fetch('tasks.php?api=toggle', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ id: id, completed: completed, csrf: csrf() })
    }).then(function(res){
      if (!res.ok){ alert('Update failed'); e.target.checked = !e.target.checked; return; }
      return res.json();
    }).then(function(data){
      if (data && data.ok !== true){ alert((data && data.error) || 'Update failed'); e.target.checked = !e.target.checked; }
    });
  });
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
