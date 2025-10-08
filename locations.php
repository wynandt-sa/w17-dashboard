<?php
// locations.php — card view for all locations; admin-only create/edit (with 4-digit phone_ext)
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$me = user();
if (!$me) { header('Location: login.php'); exit; }
$is_admin = ($me['role'] ?? '') === 'admin';

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* ---------------- CSRF ---------------- */
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
function csrf_input() { echo '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'">'; }
function csrf_check() {
  if (empty($_POST['csrf']) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    http_response_code(400);
    exit('Invalid CSRF token');
  }
}

/* ---------------- Helpers ---------------- */
function first($arr, $key, $default = '') { return isset($arr[$key]) ? trim((string)$arr[$key]) : $default; }
function email_or_null($v){ $v = trim((string)$v); return $v === '' ? null : (filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : false); }
function phone_or_null($v){ $v = trim((string)$v); return $v === '' ? null : $v; }
function phone_ext_or_null($v){
  $v = trim((string)$v);
  if ($v === '') return null;
  return preg_match('/^\d{4}$/', $v) ? $v : false; // exactly 4 digits
}
function date_or_null($v){
  $v = trim((string)$v);
  if ($v === '') return null;
  $d = DateTime::createFromFormat('Y-m-d', $v);
  return ($d && $d->format('Y-m-d') === $v) ? $v : false;
}

/* ---------------- POST (admin only) ---------------- */
$form_errors  = [];
$form_payload = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$is_admin) { http_response_code(403); exit('Forbidden'); }
  csrf_check();

  // CREATE
  if (isset($_POST['create'])) {
    $code     = first($_POST,'code');
    $name     = first($_POST,'name');
    $birth    = date_or_null(first($_POST,'birthdate'));
    $address  = first($_POST,'address');
    $manager  = first($_POST,'manager'); // users.id
    $phone    = phone_or_null(first($_POST,'phone'));
    $phoneExt = phone_ext_or_null(first($_POST,'phone_ext'));
    $email    = email_or_null(first($_POST,'email'));
    $active   = isset($_POST['active']) ? 1 : 0;

    if ($code === '' || $name === '') $form_errors[] = 'Code and Name are required.';
    if ($email === false) $form_errors[] = 'Invalid email address.';
    if ($birth === false) $form_errors[] = 'Invalid birthdate (use YYYY-MM-DD).';
    if ($phoneExt === false) $form_errors[] = 'Phone extension must be exactly 4 digits.';
    if ($manager !== '' && !ctype_digit($manager)) $form_errors[] = 'Invalid manager.';

    if (!$form_errors) {
      $dup = $pdo->prepare("SELECT COUNT(*) FROM locations WHERE code = :c");
      $dup->execute([':c'=>$code]);
      if ($dup->fetchColumn() > 0) $form_errors[] = 'Code already exists.';
    }

    if (!$form_errors) {
      $stmt = $pdo->prepare("INSERT INTO locations
        (code, name, birthdate, address, manager, phone, phone_ext, email, active)
        VALUES (:code, :name, :birthdate, :addr, :mgr, :phone, :phone_ext, :email, :active)");
      $stmt->execute([
        ':code'=>$code,
        ':name'=>$name,
        ':birthdate'=>($birth === false ? null : $birth),
        ':addr'=>($address === '' ? null : $address),
        ':mgr'=>($manager === '' ? null : (int)$manager),
        ':phone'=>$phone,
        ':phone_ext'=>($phoneExt === false ? null : $phoneExt),
        ':email'=>($email === null ? null : $email),
        ':active'=>$active
      ]);
      header('Location: locations.php?msg=created'); exit;
    } else {
      $_SESSION['form_errors']  = $form_errors;
      $_SESSION['form_payload'] = $_POST;
      header('Location: locations.php?msg=error#create'); exit;
    }
  }

  // UPDATE
  if (isset($_POST['update'])) {
    $id       = (int)($_POST['id'] ?? 0);
    $code     = first($_POST,'code');
    $name     = first($_POST,'name');
    $birth    = date_or_null(first($_POST,'birthdate'));
    $address  = first($_POST,'address');
    $manager  = first($_POST,'manager');
    $phone    = phone_or_null(first($_POST,'phone'));
    $phoneExt = phone_ext_or_null(first($_POST,'phone_ext'));
    $email    = email_or_null(first($_POST,'email'));
    $active   = isset($_POST['active']) ? 1 : 0;

    if ($id <= 0) $form_errors[] = 'Invalid location ID.';
    if ($code === '' || $name === '') $form_errors[] = 'Code and Name are required.';
    if ($email === false) $form_errors[] = 'Invalid email address.';
    if ($birth === false) $form_errors[] = 'Invalid birthdate (use YYYY-MM-DD).';
    if ($phoneExt === false) $form_errors[] = 'Phone extension must be exactly 4 digits.';
    if ($manager !== '' && !ctype_digit($manager)) $form_errors[] = 'Invalid manager.';

    if (!$form_errors) {
      $row = $pdo->prepare("SELECT id FROM locations WHERE id=:id");
      $row->execute([':id'=>$id]);
      if (!$row->fetchColumn()) $form_errors[] = 'Location not found.';
    }
    if (!$form_errors) {
      $dup = $pdo->prepare("SELECT COUNT(*) FROM locations WHERE code=:c AND id<>:id");
      $dup->execute([':c'=>$code, ':id'=>$id]);
      if ($dup->fetchColumn() > 0) $form_errors[] = 'Another location already uses this code.';
    }

    if (!$form_errors) {
      $stmt = $pdo->prepare("UPDATE locations
                                SET code=:code,
                                    name=:name,
                                    birthdate=:birthdate,
                                    address=:addr,
                                    manager=:mgr,
                                    phone=:phone,
                                    phone_ext=:phone_ext,
                                    email=:email,
                                    active=:active
                              WHERE id=:id");
      $stmt->execute([
        ':code'=>$code,
        ':name'=>$name,
        ':birthdate'=>($birth === false ? null : $birth),
        ':addr'=>($address === '' ? null : $address),
        ':mgr'=>($manager === '' ? null : (int)$manager),
        ':phone'=>$phone,
        ':phone_ext'=>($phoneExt === false ? null : $phoneExt),
        ':email'=>($email === null ? null : $email),
        ':active'=>$active,
        ':id'=>$id
      ]);
      header('Location: locations.php?msg=updated'); exit;
    } else {
      $_SESSION['form_errors']  = $form_errors;
      $_SESSION['form_payload'] = $_POST;
      header('Location: locations.php?msg=error#edit'); exit;
    }
  }
}

/* ---------------- Fetch lists ---------------- */
$sql = "SELECT l.id, l.code, l.name, l.birthdate, l.address, l.manager, l.phone, l.phone_ext, l.email, l.active,
               m.first_name AS mgr_first, m.last_name AS mgr_last, m.email AS mgr_email, m.id AS mgr_id
        FROM locations l
        LEFT JOIN users m ON m.id = l.manager
        ORDER BY l.code ASC, l.name ASC"; // sort by code
$locations = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$mgrStmt = $pdo->query("SELECT id, first_name, last_name, email FROM users WHERE active=1 ORDER BY first_name, last_name, email");
$managers = $mgrStmt->fetchAll(PDO::FETCH_ASSOC);

$form_errors  = $_SESSION['form_errors'] ?? [];
$form_payload = $_SESSION['form_payload'] ?? [];
unset($_SESSION['form_errors'], $_SESSION['form_payload']);

include __DIR__ . '/partials/header.php';
?>
<div class="card">
  <div class="card-h" style="display:flex;justify-content:space-between;align-items:center">
    <h3>Location Management</h3>
    <div style="display:flex;gap:.5rem;align-items:center">
      <?php if ($is_admin): ?>
        <button type="button" class="btn btn-primary" data-open="create">+ Add Location</button>
      <?php endif; ?>
      <span class="badge">Total: <?= (int)count($locations) ?></span>
    </div>
  </div>

  <div class="card-b">
    <?php if(isset($_GET['msg'])): ?>
      <div class="badge <?= in_array($_GET['msg'], ['error']) ? 'badge-danger' : 'badge-success' ?>" style="display:block;margin-bottom:1rem;">
        <?php
          $map = ['created'=>'Location created.','updated'=>'Location updated.','error'=>'Please fix the errors.'];
          echo h($map[$_GET['msg']] ?? '');
        ?>
      </div>
    <?php endif; ?>

    <?php if(!empty($form_errors)): ?>
      <ul class="badge badge-danger" style="display:block;margin-bottom:1rem;list-style:disc;padding-left:1.25rem">
        <?php foreach($form_errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <!-- Card grid -->
    <div class="loc-grid">
      <?php foreach ($locations as $l):
        $mgrName = trim(($l['mgr_first'] ?? '').' '.($l['mgr_last'] ?? ''));
        $mgrDisp = $mgrName !== '' ? $mgrName : ($l['mgr_email'] ?? '');
        $bdisp   = $l['birthdate'] ? DateTime::createFromFormat('Y-m-d',$l['birthdate'])->format('d M Y') : null;
      ?>
        <div class="loc-card"
          <?php if ($is_admin): ?>
            data-id="<?= (int)$l['id'] ?>"
            data-code="<?= h($l['code']) ?>"
            data-name="<?= h($l['name']) ?>"
            data-birthdate="<?= h((string)$l['birthdate']) ?>"
            data-address="<?= h((string)$l['address']) ?>"
            data-manager="<?= h((string)$l['manager']) ?>"
            data-phone="<?= h((string)$l['phone']) ?>"
            data-phoneext="<?= h((string)$l['phone_ext']) ?>"
            data-email="<?= h((string)$l['email']) ?>"
            data-active="<?= (int)$l['active'] ?>"
          <?php endif; ?>
        >
          <div class="loc-head">
            <div class="loc-code"><?= h($l['code']) ?></div>
            <div class="loc-status <?= $l['active'] ? 'on' : 'off' ?>"><?= $l['active'] ? 'Active' : 'Inactive' ?></div>
          </div>
          <h4 class="loc-name"><?= h($l['name']) ?></h4>

          <?php if ($bdisp): ?>
            <div class="loc-row"><span class="k">Birthdate</span><span class="v"><?= h($bdisp) ?></span></div>
          <?php endif; ?>

          <?php if ($l['address']): ?>
            <div class="loc-row"><span class="k">Address</span><span class="v"><?= nl2br(h($l['address'])) ?></span></div>
          <?php endif; ?>

          <div class="loc-row"><span class="k">Manager</span><span class="v"><?= $mgrDisp ? h($mgrDisp) : '—' ?></span></div>

          <div class="loc-row"><span class="k">Phone</span><span class="v">
            <?= $l['phone'] ? h($l['phone']) : '—' ?>
            <?php if ($l['phone_ext']): ?> (ext <?= h($l['phone_ext']) ?>)<?php endif; ?>
          </span></div>

          <div class="loc-row"><span class="k">Email</span><span class="v"><?= $l['email'] ? h($l['email']) : '—' ?></span></div>

          <?php if ($is_admin): ?>
          <div class="loc-actions">
            <button type="button" class="btn btn-light btn-sm btn-edit" data-open="edit">Edit</button>
          </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php if ($is_admin): ?>
<!-- ===== Create Modal (global modal styles from header.php) ===== -->
<div id="modal-create" class="modal" style="display:none">
  <div class="modal-backdrop" data-close="create"></div>
  <div class="modal-card" style="max-width:720px">
    <div class="modal-h">
      <h3>Add Location</h3>
      <button type="button" class="btn" data-close="create">✕</button>
    </div>
    <form method="post" id="formCreate">
      <?php csrf_input(); ?>
      <div class="modal-b">
        <?php $pv = function($k,$d='') use ($form_payload){ return h($form_payload[$k] ?? $d); }; ?>
        <div class="form-grid">
          <div class="field"><label>Code *</label><input class="input" name="code" required value="<?= $pv('code') ?>"></div>
          <div class="field"><label>Name *</label><input class="input" name="name" required value="<?= $pv('name') ?>"></div>
          <div class="field"><label>Birthdate</label><input class="input" type="date" name="birthdate" value="<?= $pv('birthdate') ?>"></div>
          <div class="field" style="grid-column:1/-1;"><label>Address</label><textarea class="input" name="address" rows="3"><?= $pv('address') ?></textarea></div>
          <div class="field">
            <label>Manager</label>
            <select class="input" name="manager">
              <option value="">— none —</option>
              <?php foreach ($managers as $m):
                $label = trim(($m['first_name'] ?? '').' '.($m['last_name'] ?? ''));
                if ($label === '') $label = $m['email']; ?>
                <option value="<?= (int)$m['id'] ?>"><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Phone</label><input class="input" name="phone" value="<?= $pv('phone') ?>"></div>
          <div class="field"><label>Phone Ext (4 digits)</label>
            <input class="input" name="phone_ext" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" placeholder="e.g. 0123" value="<?= $pv('phone_ext') ?>">
          </div>
          <div class="field"><label>Email</label><input class="input" type="email" name="email" value="<?= $pv('email') ?>"></div>
          <div class="field active-field tight">
            <input id="c_active" type="checkbox" name="active" <?= isset($form_payload['active']) ? 'checked' : 'checked' ?>>
            <label for="c_active">Active</label>
          </div>
        </div>
        <input type="hidden" name="create" value="1">
      </div>
      <div class="modal-f">
        <button type="button" class="btn" data-close="create">Cancel</button>
        <button class="btn btn-primary">Create</button>
      </div>
    </form>
  </div>
</div>

<!-- ===== Edit Modal ===== -->
<div id="modal-edit" class="modal" style="display:none">
  <div class="modal-backdrop" data-close="edit"></div>
  <div class="modal-card" style="max-width:720px">
    <div class="modal-h">
      <h3>Edit Location</h3>
      <button type="button" class="btn" data-close="edit">✕</button>
    </div>
    <form method="post" id="formEdit">
      <?php csrf_input(); ?>
      <div class="modal-b">
        <div class="form-grid">
          <div class="field"><label>Code *</label><input class="input" name="code" id="e_code" required></div>
          <div class="field"><label>Name *</label><input class="input" name="name" id="e_name" required></div>
          <div class="field"><label>Birthdate</label><input class="input" type="date" name="birthdate" id="e_birth"></div>
          <div class="field" style="grid-column:1/-1;"><label>Address</label><textarea class="input" name="address" id="e_addr" rows="3"></textarea></div>
          <div class="field">
            <label>Manager</label>
            <select class="input" name="manager" id="e_mgr">
              <option value="">— none —</option>
              <?php foreach ($managers as $m):
                $label = trim(($m['first_name'] ?? '').' '.($m['last_name'] ?? ''));
                if ($label === '') $label = $m['email']; ?>
                <option value="<?= (int)$m['id'] ?>"><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Phone</label><input class="input" name="phone" id="e_phone"></div>
          <div class="field"><label>Phone Ext (4 digits)</label>
            <input class="input" name="phone_ext" id="e_phone_ext" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" placeholder="e.g. 0123">
          </div>
          <div class="field"><label>Email</label><input class="input" type="email" name="email" id="e_email"></div>
          <div class="field active-field tight">
            <input type="checkbox" name="active" id="e_active">
            <label for="e_active">Active</label>
          </div>
        </div>
        <input type="hidden" name="id" id="e_id">
        <input type="hidden" name="update" value="1">
      </div>
      <div class="modal-f">
        <button type="button" class="btn" data-close="edit">Cancel</button>
        <button class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<style>
/* Cards grid (uses header tokens) */
.loc-grid{ display:grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap:1rem; }
.loc-card{ background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow); padding:1rem; display:flex; flex-direction:column; gap:.5rem; }
.loc-head{ display:flex; justify-content:space-between; align-items:center; margin-bottom:.25rem; }
.loc-code{ font-weight:700; letter-spacing:.5px; color:var(--dark); }
.loc-status{ font-size:.8rem; padding:.15rem .5rem; border-radius:999px; background:#e5e7eb; }
.loc-status.on{ background:#d1fae5; color:#065f46; } .loc-status.off{ background:#fee2e2; color:#991b1b; }
.loc-name{ font-size:1.05rem; font-weight:700; color:var(--dark); }
.loc-row{ display:flex; gap:.5rem; align-items:flex-start; }
.loc-row .k{ width:100px; flex:0 0 100px; color:#6b7280; font-size:.9rem; }
.loc-row .v{ flex:1; word-break:break-word; }
.loc-actions{ margin-top:.5rem; display:flex; justify-content:flex-end; }
/* Keep header button styles; only shrink size */
.btn-sm{ padding:.25rem .5rem; font-size:.85rem; }
<?php if ($is_admin): ?> .loc-card:hover{ box-shadow:var(--shadow-lg); } <?php endif; ?>

/* Form grid in modals */
.form-grid{ display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
.field{ display:flex; flex-direction:column; gap:.35rem; }
.field label{ font-weight:600; }
.active-field{ grid-column:1 / -1; flex-direction:row; align-items:center; gap:.4rem; }
.active-field.tight{ justify-content:flex-start; }
@media (max-width:640px){ .form-grid{ grid-template-columns:1fr; } }
</style>

<?php if ($is_admin): ?>
<script>
(function(){
  // Helper for safe date to <input type="date">
  function safeDate(v){ return /^\d{4}-\d{2}-\d{2}$/.test((v||'')) ? v : ''; }

  // Wire up "Edit" buttons (fill modal and open)
  document.querySelectorAll('.loc-card .btn-edit').forEach(btn=>{
    btn.addEventListener('click', (ev)=>{
      const card = ev.target.closest('.loc-card');
      if (!card) return;
      document.getElementById('e_id').value        = card.dataset.id || '';
      document.getElementById('e_code').value      = card.dataset.code || '';
      document.getElementById('e_name').value      = card.dataset.name || '';
      document.getElementById('e_birth').value     = safeDate(card.dataset.birthdate);
      document.getElementById('e_addr').value      = card.dataset.address || '';
      document.getElementById('e_mgr').value       = card.dataset.manager || '';
      document.getElementById('e_phone').value     = card.dataset.phone || '';
      document.getElementById('e_phone_ext').value = card.dataset.phoneext || '';
      document.getElementById('e_email').value     = card.dataset.email || '';
      document.getElementById('e_active').checked  = (card.dataset.active === '1');
      openModal('edit');
    });
  });

  // Deep-link support after validation bounce
  if (location.hash === '#create') openModal('create');
  if (location.hash === '#edit')   openModal('edit');
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/partials/footer.php'; ?>
