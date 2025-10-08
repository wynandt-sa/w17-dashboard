<?php
// monitoring_admin.php — manage monitoring badges and their location mappings (codes only)
// Badges pull status/uptime on the dashboard; here we only define badge meta + which locations see them.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

$me = user();
$is_admin = ($me['role'] ?? '') === 'admin';
if (!$is_admin) { http_response_code(403); exit('Admins only'); }

if (!function_exists('e')) { function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
function log_err($x){ try { @file_put_contents('/tmp/ticketing_error.log', "[".date('c')."] ".$x."\n", FILE_APPEND); } catch(Throwable $t){} }

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------- helpers ---------- */
function col_exists(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
  $st->execute([$table,$col]);
  return (bool)$st->fetchColumn();
}

/* ---------- schema (idempotent) ---------- */
try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS monitoring_badges (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(120) NOT NULL,
      description VARCHAR(255) NULL,
      monitor_id INT NOT NULL,
      hours INT NOT NULL DEFAULT 168,
      active TINYINT(1) NOT NULL DEFAULT 1,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS location_badges (
      location_id INT NOT NULL,
      badge_id INT NOT NULL,
      PRIMARY KEY (location_id, badge_id),
      CONSTRAINT fk_lb_loc FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
      CONSTRAINT fk_lb_badge FOREIGN KEY (badge_id) REFERENCES monitoring_badges(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
} catch(Throwable $e){ log_err("create tables: ".$e->getMessage()); }

/* --- Backward-compat for legacy column `url` (make it nullable if present) --- */
$HAS_URL = false;
try {
  $HAS_URL = col_exists($pdo,'monitoring_badges','url');
  if ($HAS_URL) {
    try { $pdo->exec("ALTER TABLE monitoring_badges MODIFY url VARCHAR(255) NULL DEFAULT NULL"); } catch(Throwable $e){}
  }
} catch(Throwable $e){ log_err("probe url col: ".$e->getMessage()); }

/* ---------- CSRF ---------- */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
function csrf_input(){ echo '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">'; }
function csrf_check(){
  if (empty($_POST['csrf']) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    http_response_code(400); exit('Invalid CSRF');
  }
}

/* ---------- POST ---------- */
$msg = $_GET['msg'] ?? null;
$err = $_GET['err'] ?? null;

if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  try {
    $action = $_POST['action'] ?? '';
    if ($action === 'create' || $action === 'update') {
      $id    = (int)($_POST['id'] ?? 0);
      $name  = trim($_POST['name'] ?? '');
      $desc  = trim($_POST['description'] ?? '');
      $mid   = (int)($_POST['monitor_id'] ?? 0);
      $hours = (int)($_POST['hours'] ?? 168);
      $active= isset($_POST['active']) ? 1 : 0;
      $locs  = array_map('intval', (array)($_POST['locations'] ?? []));

      if ($name==='' || $mid<=0) throw new RuntimeException('Name and Monitor ID are required.');
      if ($hours <= 0) $hours = 168;

      if ($action === 'create') {
        if ($HAS_URL) {
          $st = $pdo->prepare("INSERT INTO monitoring_badges (name,description,monitor_id,hours,active,url) VALUES (?,?,?,?,?,NULL)");
          $st->execute([$name,$desc,$mid,$hours,$active]);
        } else {
          $st = $pdo->prepare("INSERT INTO monitoring_badges (name,description,monitor_id,hours,active) VALUES (?,?,?,?,?)");
          $st->execute([$name,$desc,$mid,$hours,$active]);
        }
        $id = (int)$pdo->lastInsertId();
        if ($locs) {
          $ins = $pdo->prepare("INSERT INTO location_badges (location_id,badge_id) VALUES (?,?)");
          foreach ($locs as $L) $ins->execute([$L,$id]);
        }
        header('Location: monitoring_admin.php?msg=created'); exit;

      } else { // update
        if ($id<=0) throw new RuntimeException('Invalid badge id.');
        if ($HAS_URL) {
          $st = $pdo->prepare("UPDATE monitoring_badges SET name=?, description=?, monitor_id=?, hours=?, active=?, url=NULL WHERE id=?");
          $st->execute([$name,$desc,$mid,$hours,$active,$id]);
        } else {
          $st = $pdo->prepare("UPDATE monitoring_badges SET name=?, description=?, monitor_id=?, hours=?, active=? WHERE id=?");
          $st->execute([$name,$desc,$mid,$hours,$active,$id]);
        }
        $pdo->prepare("DELETE FROM location_badges WHERE badge_id=?")->execute([$id]);
        if ($locs) {
          $ins = $pdo->prepare("INSERT INTO location_badges (location_id,badge_id) VALUES (?,?)");
          foreach ($locs as $L) $ins->execute([$L,$id]);
        }
        header('Location: monitoring_admin.php?msg=updated'); exit;
      }
    }

    if ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id<=0) throw new RuntimeException('Invalid badge id.');
      $pdo->prepare("DELETE FROM monitoring_badges WHERE id=?")->execute([$id]);
      header('Location: monitoring_admin.php?msg=deleted'); exit;
    }

    throw new RuntimeException('Unknown action.');
  } catch(Throwable $ex) {
    log_err("POST error: ".$ex->getMessage());
    $q = http_build_query(['err'=>$ex->getMessage()]);
    header('Location: monitoring_admin.php?'.$q); exit;
  }
}

/* ---------- Lists ---------- */
$locations = [];
try {
  $rows = $pdo->query("SELECT id, code, name FROM locations ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $r) $locations[(int)$r['id']] = ['code'=>$r['code'], 'name'=>$r['name']];
} catch(Throwable $e){ log_err("load locations: ".$e->getMessage()); }

$badges = [];
try {
  $rows = $pdo->query("
      SELECT b.id, b.name, b.description, b.monitor_id, b.hours, b.active,
             GROUP_CONCAT(lb.location_id ORDER BY lb.location_id) AS loc_ids
      FROM monitoring_badges b
      LEFT JOIN location_badges lb ON lb.badge_id = b.id
      GROUP BY b.id
      ORDER BY b.id DESC
  ")->fetchAll(PDO::FETCH_ASSOC);

  foreach ($rows as $r) {
    $ids = array_filter(array_map('intval', $r['loc_ids'] ? explode(',', $r['loc_ids']) : []));
    $codes = [];
    foreach ($ids as $lid) { if (isset($locations[$lid])) $codes[] = $locations[$lid]['code']; }
    $r['loc_ids_arr'] = $ids;
    $r['codes'] = $codes;
    $badges[] = $r;
  }
} catch(Throwable $e){ log_err("load badges: ".$e->getMessage()); }

include __DIR__ . '/partials/header.php';
?>
<div class="card">
  <div class="card-h" style="display:flex;justify-content:space-between;align-items:center">
    <h3>Monitoring — Admin</h3>
    <div style="display:flex;gap:.5rem;align-items:center">
      <button class="btn btn-primary" id="btnAdd">+ Add Badge</button>
      <span class="badge">Total: <?= (int)count($badges) ?></span>
    </div>
  </div>

  <div class="card-b">
    <?php if ($msg): ?>
      <div class="badge badge-success" style="display:block;margin-bottom:1rem;">
        <?= e(['created'=>'Badge created','updated'=>'Badge updated','deleted'=>'Badge deleted'][$msg] ?? $msg) ?>
      </div>
    <?php endif; ?>
    <?php if ($err): ?>
      <div class="badge badge-danger" style="display:block;margin-bottom:1rem;"><?= e($err) ?></div>
    <?php endif; ?>

    <?php if (!$badges): ?>
      <p>No badges yet.</p>
    <?php else: ?>
      <div class="mon-admin-grid">
        <?php foreach ($badges as $b): ?>
          <div class="mon-admin-card" data-json='<?= e(json_encode($b, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>'>
            <div class="mac-top">
              <div class="mac-title"><?= e($b['name']) ?></div>
              <span class="badge <?= $b['active']?'badge-success':'badge-danger' ?>"><?= $b['active']?'Active':'Inactive' ?></span>
            </div>
            <?php if (!empty($b['description'])): ?>
              <div class="mac-desc"><?= e($b['description']) ?></div>
            <?php endif; ?>
            <div class="mac-sub">Monitor ID: <strong><?= (int)$b['monitor_id'] ?></strong> &nbsp;•&nbsp; Window: <strong><?= (int)$b['hours'] ?>h</strong></div>
            <div class="mac-codes">
              <?php if ($b['codes']): ?>
                <?php foreach ($b['codes'] as $c): ?><span class="chip"><?= e($c) ?></span><?php endforeach; ?>
              <?php else: ?>
                <em>No locations</em>
              <?php endif; ?>
            </div>
            <div class="mac-actions">
              <button class="btn btn-secondary btn-edit" type="button">Edit</button>
              <form method="post" onsubmit="return confirm('Delete this badge?')" style="display:inline">
                <?php csrf_input(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                <button class="btn btn-danger" type="submit">Delete</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ===== Modal: Add/Edit badge ===== -->
<div id="modal-badge" class="modal" style="display:none">
  <div class="modal-backdrop" data-close></div>
  <div class="modal-card" style="max-width:780px">
    <div class="modal-h">
      <h3 id="dlgTitle">Add Badge</h3>
      <button class="btn btn-light" type="button" data-close>✕</button>
    </div>
    <div class="modal-b">
      <form method="post" id="fBadge" class="form-grid" action="monitoring_admin.php">
        <?php csrf_input(); ?>
        <input type="hidden" name="action" id="f_action" value="create">
        <input type="hidden" name="id" id="f_id" value="0">
        <div class="field"><label class="label">Name *</label><input class="input" name="name" id="f_name" required></div>
        <div class="field"><label class="label">Monitor ID *</label><input class="input" name="monitor_id" id="f_mid" type="number" min="1" required></div>
        <div class="field"><label class="label">Hours</label><input class="input" name="hours" id="f_hours" type="number" min="1" value="168"></div>
        <div class="field active-field"><input type="checkbox" id="f_active" name="active" checked><label for="f_active">Active</label></div>
        <div class="field" style="grid-column:1/-1"><label class="label">Description</label><input class="input" name="description" id="f_desc"></div>

        <div class="field" style="grid-column:1/-1">
          <label class="label">Show for location codes</label>
          <div class="code-grid">
            <?php foreach ($locations as $lid=>$L): ?>
              <label class="code-chip"><input type="checkbox" name="locations[]" value="<?= (int)$lid ?>"> <span><?= e($L['code']) ?></span></label>
            <?php endforeach; ?>
          </div>
        </div>

        <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:.5rem;margin-top:.5rem">
          <button class="btn" type="button" data-close>Cancel</button>
          <button class="btn btn-primary" type="submit" id="btnSubmit">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<style>
.mon-admin-grid{ display:grid; gap:1rem; grid-template-columns:repeat(auto-fill,minmax(340px,1fr)); }
.mon-admin-card{ background:#fff; border:1px solid var(--gray-200); border-radius:12px; box-shadow:var(--shadow); padding:1rem; display:flex; flex-direction:column; gap:.5rem; }
.mac-top{ display:flex; justify-content:space-between; align-items:center; gap:.5rem; }
.mac-title{ font-weight:800; }
.mac-desc{ color:var(--muted); }
.mac-sub{ font-size:.9rem; color:#6b7280; }
.mac-codes{ display:flex; gap:.4rem; flex-wrap:wrap; margin-top:.25rem; }
.chip{ display:inline-block; padding:.25rem .55rem; border-radius:999px; border:1px solid #e5e7eb; background:#f9fafb; font-size:.82rem; }
.mac-actions{ display:flex; gap:.5rem; justify-content:flex-start; margin-top:.5rem; }

.form-grid{ display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
.field{ display:flex; flex-direction:column; gap:.35rem; }
.label{ font-weight:600; }
.active-field{ flex-direction:row; align-items:center; gap:.5rem; margin-top:1.75rem; }
.code-grid{ display:grid; grid-template-columns:repeat(6,minmax(0,1fr)); gap:.5rem; padding:.6rem; background:#f8fafc; border:1px dashed #e5e7eb; border-radius:12px; }
.code-chip{ display:flex; align-items:center; gap:.4rem; padding:.4rem .6rem; border:1px solid #e5e7eb; border-radius:999px; background:#fff; justify-content:center; }
@media (max-width:900px){ .form-grid{ grid-template-columns:1fr; } .code-grid{ grid-template-columns:repeat(3,1fr); } }
</style>

<script>
(function(){
  const modal = document.getElementById('modal-badge');
  const form  = document.getElementById('fBadge');

  function open(){ modal.style.display='flex'; }
  function close(){ modal.style.display='none'; }
  modal.querySelectorAll('[data-close]').forEach(el=>el.addEventListener('click', close));
  modal.querySelector('.modal-backdrop')?.addEventListener('click', close);

  document.getElementById('btnAdd').addEventListener('click', ()=>{
    form.reset();
    document.getElementById('f_action').value='create';
    document.getElementById('f_id').value='0';
    document.getElementById('dlgTitle').textContent='Add Badge';
    document.getElementById('f_hours').value = 168;
    document.getElementById('f_active').checked = true;
    document.querySelectorAll('input[name="locations[]"]').forEach(cb=>cb.checked=false);
    open();
  });

  document.querySelectorAll('.btn-edit').forEach(btn=>{
    btn.addEventListener('click', (ev)=>{
      const card = ev.target.closest('.mon-admin-card');
      const data = JSON.parse(card.getAttribute('data-json') || '{}');

      form.reset();
      document.getElementById('f_action').value='update';
      document.getElementById('f_id').value = data.id || 0;
      document.getElementById('dlgTitle').textContent='Edit Badge';

      document.getElementById('f_name').value  = data.name || '';
      document.getElementById('f_desc').value  = data.description || '';
      document.getElementById('f_mid').value   = data.monitor_id || '';
      document.getElementById('f_hours').value = data.hours || 168;
      document.getElementById('f_active').checked = !!(+data.active);

      document.querySelectorAll('input[name="locations[]"]').forEach(cb=>cb.checked=false);
      (data.loc_ids_arr || []).forEach(id=>{
        const cb = document.querySelector('input[name="locations[]"][value="'+id+'"]');
        if (cb) cb.checked = true;
      });

      open();
    });
  });
})();
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
