<?php
// locations.php — Core Location Management Portal
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$me = user();
if (!$me) { header('Location: login.php'); exit; }
$is_admin = ($me['role'] ?? '') === 'admin';

// NOTE: Relying on auth.php for function e() and h().

/* ---------------- CSRF ---------------- */
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
function csrf_input() { echo '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">'; }
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
function col_exists(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
  $st->execute([$table,$col]);
  return (bool)$st->fetchColumn();
}
function sanitise_key($str) {
    return preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace(' ', '_', $str)));
}


/* ---------------- Schema & Migrations ---------------- */
// Regions
$pdo->exec("
  CREATE TABLE IF NOT EXISTS regions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
if (!col_exists($pdo, 'locations', 'region_id')) {
    $pdo->exec("ALTER TABLE locations ADD COLUMN region_id INT NULL");
    $pdo->exec("ALTER TABLE locations ADD CONSTRAINT fk_loc_region FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE SET NULL");
}

// Custom Fields
$pdo->exec("
  CREATE TABLE IF NOT EXISTS location_custom_fields (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    field_key VARCHAR(50) NOT NULL UNIQUE,
    field_type ENUM('text','textarea','number','date') NOT NULL DEFAULT 'text',
    sort_order INT NOT NULL DEFAULT 0
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
$pdo->exec("
  CREATE TABLE IF NOT EXISTS location_field_values (
    location_id INT NOT NULL,
    field_id INT NOT NULL,
    field_value TEXT NULL,
    PRIMARY KEY (location_id, field_id),
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
    FOREIGN KEY (field_id) REFERENCES location_custom_fields(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");


/* ---------------- POST (admin only) ---------------- */
$form_errors  = [];
$form_payload = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$is_admin) { http_response_code(403); exit('Forbidden'); }
  csrf_check();

  // --- Region Management ---
  if (isset($_POST['add_region'])) {
      $name = first($_POST,'r_name');
      $desc = first($_POST,'r_desc');
      if (empty($name)) {
        header('Location: locations.php?msg=error&err=Region name is required#modal-manage-regions'); exit;
      }
      $pdo->prepare("INSERT INTO regions (name, description) VALUES (?,?)")->execute([$name, $desc]);
      header('Location: locations.php?msg=region_created#modal-manage-regions'); exit;
  }
  if (isset($_POST['del_region'])) {
      $id = (int)($_POST['r_id'] ?? 0);
      $pdo->prepare("DELETE FROM regions WHERE id=?")->execute([$id]);
      header('Location: locations.php?msg=region_deleted'); exit;
  }
  
  // --- Custom Field Management ---
  if (isset($_POST['add_field'])) {
      $name = first($_POST,'cf_name');
      $type = first($_POST,'cf_type');
      $key = sanitise_key($name);
      if (empty($name) || empty($key)) {
        header('Location: locations.php?msg=error&err=Field name and key are required#modal-manage-fields'); exit;
      }
      $pdo->prepare("INSERT INTO location_custom_fields (name, field_key, field_type) VALUES (?,?,?)")->execute([$name, $key, $type]);
      header('Location: locations.php?msg=field_created#modal-manage-fields'); exit;
  }
  if (isset($_POST['del_field'])) {
      $id = (int)($_POST['cf_id'] ?? 0);
      $pdo->prepare("DELETE FROM location_custom_fields WHERE id=?")->execute([$id]);
      header('Location: locations.php?msg=field_deleted'); exit;
  }


  // --- Location CRUD ---
  if ($is_create || $is_update) {
    $id       = (int)($_POST['id'] ?? 0);
    $code     = first($_POST,'code');
    $name     = first($_POST,'name');
    
    // **Defensive PHP 8.x variable assignment**
    $region_id_raw = first($_POST,'region_id');
    $regionId = (int)$region_id_raw ?: null;
    
    $birth_raw = first($_POST,'birthdate');
    $birth    = date_or_null($birth_raw);
    
    $address  = first($_POST,'address');
    $manager_raw = first($_POST,'manager');
    $manager  = $manager_raw === '' ? null : (int)$manager_raw;

    $phone_raw = first($_POST,'phone');
    $phone    = phone_or_null($phone_raw);

    $phone_ext_raw = first($_POST,'phone_ext');
    $phoneExt = phone_ext_or_null($phone_ext_raw);

    $email_raw = first($_POST,'email');
    $email    = email_or_null($email_raw); // Can return string or null or FALSE
    
    $active   = isset($_POST['active']) ? 1 : 0;
    // End FIX

    // Custom field values (will be processed after main location save)
    $custom_values = $_POST['custom_field'] ?? [];


    if ($code === '' || $name === '') $form_errors[] = 'Code and Name are required.';
    if ($email === false) $form_errors[] = 'Invalid email address.';
    if ($birth === false) $form_errors[] = 'Invalid birthdate (use YYYY-MM-DD).';
    if ($phoneExt === false) $form_errors[] = 'Phone extension must be exactly 4 digits.';
    if ($manager !== null && !ctype_digit((string)$manager_raw)) $form_errors[] = 'Invalid manager.';

    if ($is_update && $id <= 0) $form_errors[] = 'Invalid location ID.';

    if (!$form_errors) {
      if ($is_create) {
        $dup = $pdo->prepare("SELECT COUNT(*) FROM locations WHERE code = :c");
        $dup->execute([':c'=>$code]);
        if ($dup->fetchColumn() > 0) $form_errors[] = 'Code already exists.';
      } else { // update
        $dup = $pdo->prepare("SELECT COUNT(*) FROM locations WHERE code=:c AND id<>:id");
        $dup->execute([':c'=>$code, ':id'=>$id]);
        if ($dup->fetchColumn() > 0) $form_errors[] = 'Another location already uses this code.';
      }
    }

    if (!$form_errors) {
        // Final values for DB insert/update
        $db_birth = ($birth === false) ? null : $birth;
        $db_phone_ext = ($phoneExt === false) ? null : $phoneExt;
        $db_email = ($email === false) ? null : $email;


        if ($is_create) {
            $stmt = $pdo->prepare("INSERT INTO locations
              (code, name, region_id, birthdate, address, manager, phone, phone_ext, email, active)
              VALUES (:code, :name, :region_id, :birthdate, :addr, :mgr, :phone, :phone_ext, :email, :active)");
            $stmt->execute([
              ':code'=>$code, ':name'=>$name, ':region_id'=>$regionId, ':birthdate'=>$db_birth,
              ':addr'=>($address === '' ? null : $address), ':mgr'=>$manager,
              ':phone'=>$phone, ':phone_ext'=>$db_phone_ext,
              ':email'=>$db_email, ':active'=>$active
            ]);
            $id = (int)$pdo->lastInsertId();
        } else {
            $stmt = $pdo->prepare("UPDATE locations
                SET code=:code, name=:name, region_id=:region_id, birthdate=:birthdate, address=:addr,
                    manager=:mgr, phone=:phone, phone_ext=:phone_ext, email=:email, active=:active
                WHERE id=:id");
            $stmt->execute([
              ':code'=>$code, ':name'=>$name, ':region_id'=>$regionId, ':birthdate'=>$db_birth,
              ':addr'=>($address === '' ? null : $address), ':mgr'=>$manager,
              ':phone'=>$phone, ':phone_ext'=>$db_phone_ext,
              ':email'=>$db_email, ':active'=>$active, ':id'=>$id
            ]);
        }
        
        // Save custom field values
        $upsert_cf = $pdo->prepare("INSERT INTO location_field_values (location_id, field_id, field_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE field_value=VALUES(field_value)");
        foreach ($custom_fields as $cf) {
            $field_id = (int)$cf['id'];
            $value = $custom_values[$field_id] ?? null;
            if ($value !== null) {
                $upsert_cf->execute([$id, $field_id, (string)$value]);
            }
        }
        
        header('Location: locations.php?msg='.($is_create ? 'created' : 'updated')); exit;

    } else {
      $_SESSION['form_errors']  = $form_errors;
      $_SESSION['form_payload'] = $_POST;
      header('Location: locations.php?msg=error#modal-'.($is_create ? 'create' : 'edit')); exit;
    }
  }
}

/* ---------------- Fetch lists ---------------- */
$regions = $pdo->query("SELECT * FROM regions ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$custom_fields = $pdo->query("SELECT * FROM location_custom_fields ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all custom field values indexed by location_id and field_id
$field_values_raw = $pdo->query("SELECT location_id, field_id, field_value FROM location_field_values")->fetchAll(PDO::FETCH_ASSOC);
$field_values_map = [];
foreach ($field_values_raw as $v) {
    $field_values_map[(int)$v['location_id']][(int)$v['field_id']] = $v['field_value'];
}


$sql = "SELECT l.*, l.manager AS manager_user_id, r.name AS region_name, r.id AS region_id,
               m.first_name AS mgr_first, m.last_name AS mgr_last, m.email AS mgr_email, m.id AS mgr_id
        FROM locations l
        LEFT JOIN regions r ON r.id = l.region_id
        LEFT JOIN users m ON m.id = l.manager
        ORDER BY r.name ASC, l.code ASC";
$locations = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

/* Group locations by region and inject custom fields */
$locations_by_region = [];
foreach ($locations as $l) {
    $lid = (int)$l['id'];
    $mgrName = trim(($l['mgr_first'] ?? '').' '.($l['mgr_last'] ?? ''));
    $l['manager_name'] = $mgrName !== '' ? $mgrName : ($l['mgr_email'] ?? '—');
    $l['birthdate_disp'] = $l['birthdate'] ? DateTime::createFromFormat('Y-m-d',$l['birthdate'])->format('d M Y') : null;
    $l['custom_fields'] = $field_values_map[$lid] ?? [];
    
    $region_name = $l['region_name'] ?: 'Unassigned';
    $locations_by_region[$region_name][] = $l;
}

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
        <button type="button" class="btn btn-light" data-open="manage-fields">Manage Custom Fields</button>
        <button type="button" class="btn btn-secondary" data-open="manage-regions">Manage Regions</button>
        <button type="button" class="btn btn-primary" data-open="create">+ Add Location</button>
      <?php endif; ?>
      <span class="badge">Total: <?= (int)count($locations) ?></span>
    </div>
  </div>

  <div class="card-b">
    <?php if(isset($_GET['msg'])): ?>
      <div class="badge <?= in_array($_GET['msg'], ['error']) ? 'badge-danger' : 'badge-success' ?>" style="display:block;margin-bottom:1rem;">
        <?php
          $map = [
            'created'=>'Location created.','updated'=>'Location updated.','error'=>'Please fix the errors.',
            'region_created'=>'Region created.','region_deleted'=>'Region deleted.',
            'field_created'=>'Custom field created.','field_deleted'=>'Custom field deleted.'
          ];
          echo e($map[$_GET['msg']] ?? '');
        ?>
      </div>
    <?php endif; ?>
    <?php if(!empty($form_errors)): ?>
      <ul class="badge badge-danger" style="display:block;margin-bottom:1rem;list-style:disc;padding-left:1.25rem">
        <?php foreach($form_errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php foreach ($locations_by_region as $region_name => $locs): ?>
      <div style="margin-top:1.5rem">
        <h4 style="border-bottom:1px solid #e5e7eb; padding-bottom:.5rem; margin-bottom:.75rem">📍 <?= e($region_name) ?> (<?= count($locs) ?>)</h4>
        <div class="loc-grid">
          <?php foreach ($locs as $l): ?>
            <div class="loc-card row-openable" data-id="<?= (int)$l['id'] ?>">
              <div class="loc-head">
                <div class="loc-code"><?= e($l['code']) ?></div>
                <div class="loc-status <?= $l['active'] ? 'on' : 'off' ?>"><?= $l['active'] ? 'Active' : 'Inactive' ?></div>
              </div>
              <h4 class="loc-name"><?= e($l['name']) ?></h4>

              <div class="loc-row"><span class="k">Phone</span><span class="v">
                <?= $l['phone'] ? e($l['phone']) : '—' ?>
                <?php if ($l['phone_ext']): ?> (ext <?= e($l['phone_ext']) ?>)<?php endif; ?>
              </span></div>

              <div class="loc-row"><span class="k">Email</span><span class="v"><?= $l['email'] ? e($l['email']) : '—' ?></span></div>
              
              <?php if ($is_admin): ?>
              <div class="loc-actions">
                <button type="button" class="btn btn-light btn-sm btn-edit" data-id="<?= (int)$l['id'] ?>">Edit</button>
              </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div id="modal-location-detail" class="modal" style="display:none">
  <div class="modal-backdrop" data-close="location-detail"></div>
  <div class="modal-card" style="max-width:500px">
    <div class="modal-h">
      <h3 id="ld_name">Location Detail</h3>
      <button type="button" class="btn" data-close="location-detail">✕</button>
    </div>
    <div class="modal-b">
      <div id="ld_content"></div>
    </div>
  </div>
</div>

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
        <?php $pv = function($k,$d='') use ($form_payload){ return e($form_payload[$k] ?? $d); }; ?>
        <div class="form-grid">
          <div class="field"><label>Code *</label><input class="input" name="code" required value="<?= $pv('code') ?>"></div>
          <div class="field"><label>Name *</label><input class="input" name="name" required value="<?= $pv('name') ?>"></div>
          
          <div class="field">
            <label>Region</label>
            <select class="input" name="region_id">
              <option value="">— none —</option>
              <?php foreach ($regions as $r): ?>
                <option value="<?= (int)$r['id'] ?>" <?= ((int)$pv('region_id')===(int)$r['id'])?'selected':'' ?>><?= e($r['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field"><label>Birthdate</label><input class="input" type="date" name="birthdate" value="<?= $pv('birthdate') ?>"></div>
          <div class="field" style="grid-column:1/-1;"><label>Address</label><textarea class="input" name="address" rows="3"><?= $pv('address') ?></textarea></div>
          <div class="field">
            <label>Manager</label>
            <select class="input" name="manager">
              <option value="">— none —</option>
              <?php foreach ($managers as $m):
                $label = trim(($m['first_name'] ?? '').' '.($m['last_name'] ?? ''));
                if ($label === '') $label = $m['email']; ?>
                <option value="<?= (int)$m['id'] ?>" <?= ((int)$pv('manager')===(int)$m['id'])?'selected':'' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Phone</label><input class="input" name="phone" value="<?= $pv('phone') ?>"></div>
          <div class="field"><label>Phone Ext (4 digits)</label>
            <input class="input" name="phone_ext" id="c_phone_ext" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" placeholder="e.g. 0123" value="<?= $pv('phone_ext') ?>">
          </div>
          <div class="field"><label>Email</label><input class="input" type="email" name="email" value="<?= $pv('email') ?>"></div>
          <div class="field active-field tight">
            <input id="c_active" type="checkbox" name="active" <?= !isset($form_payload['active']) || (isset($form_payload['active']) && $form_payload['active']) ? 'checked' : '' ?>>
            <label for="c_active">Active</label>
          </div>

          <?php foreach ($custom_fields as $cf): 
            $key = 'custom_field['.(int)$cf['id'].']';
            $val = $form_payload[$key] ?? '';
          ?>
            <div class="field custom-field">
              <label><?= e($cf['name']) ?></label>
              <?php if ($cf['field_type'] === 'textarea'): ?>
                <textarea class="input" name="<?= $key ?>" rows="3"><?= e($val) ?></textarea>
              <? else: ?>
                <input class="input" name="<?= $key ?>" type="<?= e($cf['field_type']) ?>" value="<?= $pv('custom_field['.(int)$cf['id'].']', $val) ?>">
              <?php endif; ?>
            </div>
          <?php endforeach; ?>

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
          
          <div class="field">
            <label>Region</label>
            <select class="input" name="region_id" id="e_region_id">
              <option value="">— none —</option>
              <?php foreach ($regions as $r): ?>
                <option value="<?= (int)$r['id'] ?>"><?= e($r['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field"><label>Birthdate</label><input class="input" type="date" name="birthdate" id="e_birth"></div>
          <div class="field" style="grid-column:1/-1;"><label>Address</label><textarea class="input" name="address" id="e_addr" rows="3"></textarea></div>
          <div class="field">
            <label>Manager</label>
            <select class="input" name="manager" id="e_mgr">
              <option value="">— none —</option>
              <?php foreach ($managers as $m):
                $label = trim(($m['first_name'] ?? '').' '.($m['last_name'] ?? ''));
                if ($label === '') $label = $m['email']; ?>
                <option value="<?= (int)$m['id'] ?>"><?= e($label) ?></option>
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
          
          <?php foreach ($custom_fields as $cf): 
            $key = 'custom_field['.(int)$cf['id'].']';
          ?>
            <div class="field custom-field">
              <label><?= e($cf['name']) ?></label>
              <?php if ($cf['field_type'] === 'textarea'): ?>
                <textarea class="input" name="<?= $key ?>" rows="3" data-cf-id="<?= (int)$cf['id'] ?>"></textarea>
              <?php else: ?>
                <input class="input" name="<?= $key ?>" type="<?= e($cf['field_type']) ?>" data-cf-id="<?= (int)$cf['id'] ?>">
              <?php endif; ?>
            </div>
          <?php endforeach; ?>

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

<div id="modal-manage-regions" class="modal" style="display:none">
  <div class="modal-backdrop" data-close="manage-regions"></div>
  <div class="modal-card" style="max-width:500px">
    <div class="modal-h">
      <h3>Manage Regions</h3>
      <button type="button" class="btn" data-close="manage-regions">✕</button>
    </div>
    <div class="modal-b">
      <h4>Add New Region</h4>
      <form method="post" style="display:flex;gap:.5rem;margin-bottom:1.5rem">
        <?php csrf_input(); ?>
        <input class="input" name="r_name" placeholder="Region Name" required>
        <input type="hidden" name="add_region" value="1">
        <button class="btn btn-primary">Add</button>
      </form>
      
      <h4>Existing Regions</h4>
      <table class="table">
        <thead><tr><th>Name</th><th style="width:100px">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($regions as $r): ?>
          <tr>
            <td><?= e($r['name']) ?></td>
            <td>
              <form method="post" onsubmit="return confirm('Delete region? All associated locations will become unassigned.')" style="display:inline">
                <?php csrf_input(); ?>
                <input type="hidden" name="r_id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-danger btn-sm" name="del_region" value="1">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div id="modal-manage-fields" class="modal" style="display:none">
  <div class="modal-backdrop" data-close="manage-fields"></div>
  <div class="modal-card" style="max-width:600px">
    <div class="modal-h">
      <h3>Manage Custom Fields</h3>
      <button type="button" class="btn" data-close="manage-fields">✕</button>
    </div>
    <div class="modal-b">
      <h4>Add New Custom Field</h4>
      <form method="post" style="display:grid;grid-template-columns:1fr 1fr auto;gap:.5rem;align-items:end;margin-bottom:1.5rem">
        <?php csrf_input(); ?>
        <div class="field">
          <label>Field Name</label>
          <input class="input" name="cf_name" placeholder="e.g. WiFi SSID" required>
        </div>
        <div class="field">
          <label>Type</label>
          <select class="input" name="cf_type">
            <option value="text">Text</option>
            <option value="textarea">Multi-line Text</option>
            <option value="number">Number</option>
            <option value="date">Date</option>
          </select>
        </div>
        <input type="hidden" name="add_field" value="1">
        <button class="btn btn-primary">Add</button>
      </form>
      
      <h4>Existing Custom Fields</h4>
      <table class="table">
        <thead><tr><th>Name</th><th>Key</th><th>Type</th><th style="width:100px">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($custom_fields as $cf): ?>
          <tr>
            <td><?= e($cf['name']) ?></td>
            <td><code><?= e($cf['field_key']) ?></code></td>
            <td><?= e(ucfirst($cf['field_type'])) ?></td>
            <td>
              <form method="post" onsubmit="return confirm('Delete custom field and all associated data?')" style="display:inline">
                <?php csrf_input(); ?>
                <input type="hidden" name="cf_id" value="<?= (int)$cf['id'] ?>">
                <button class="btn btn-danger btn-sm" name="del_field" value="1">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<style>
/* Cards grid (uses header tokens) */
.loc-grid{ display:grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap:1rem; }
.loc-card{ background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow); padding:1rem; display:flex; flex-direction:column; gap:.5rem; cursor:pointer; }
.loc-card:hover{ box-shadow:var(--shadow-lg); }
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

/* Form grid in modals */
.form-grid{ display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
.field{ display:flex; flex-direction:column; gap:.35rem; }
.field label{ font-weight:600; }
.active-field{ grid-column:1 / -1; flex-direction:row; align-items:center; gap:.4rem; }
.active-field.tight{ justify-content:flex-start; }
@media (max-width:640px){ .form-grid{ grid-template-columns:1fr; } }
</style>

<script>
// JSON data of all locations for the Edit modal lookup
window.ALL_LOCATIONS = <?= json_encode($locations, JSON_UNESCAPED_UNICODE | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
window.CUSTOM_FIELDS = <?= json_encode($custom_fields, JSON_UNESCAPED_UNICODE | JSON_HEX_AMP | JSON_HEX_APOS) ?>;

// Function to open the detail modal using the ID
function openLocationModal(id){
    const l = window.ALL_LOCATIONS.find(loc => String(loc.id) === String(id));
    if (!l) {
        console.warn(`[Locations] Detail: ID ${id} not found in global data.`);
        return;
    }
    console.log(`[Locations] Action: Opening Detail Modal for ID: ${id}`);


    const content = document.getElementById('ld_content');
    const isActive = l.active == 1; 
    const statusText = isActive ? 'Active' : 'Inactive';
    
    let html = `
        <p><strong>Code:</strong> ${l.code}</p>
        <p><strong>Name:</strong> ${l.name}</p>
        ${l.region_name ? `<p><strong>Region:</strong> ${l.region_name}</p>` : ''}
        ${l.birthdate_disp ? `<p><strong>Birthdate:</strong> ${l.birthdate_disp}</p>` : ''}
        ${l.address ? `<p><strong>Address:</strong> ${l.address.replace(/\n/g, '<br>')}</p>` : ''}
        ${l.manager_name ? `<p><strong>Manager:</strong> ${l.manager_name} (${l.mgr_email || '—'})</p>` : ''}
        <p><strong>Phone:</strong> ${l.phone || '—'} ${l.phone_ext ? `(ext ${l.phone_ext})` : ''}</p>
        <p><strong>Email:</strong> ${l.email || '—'}</p>
        <p><strong>Status:</strong> ${statusText}</p>
    `;

    if (window.CUSTOM_FIELDS.length > 0) {
        html += '<h4 style="margin-top:1rem;border-top:1px solid #eee;padding-top:.5rem;">Custom Details</h4>';
        window.CUSTOM_FIELDS.forEach(cf => {
            const val = l.custom_fields[cf.id] || '—'; 
            html += `<p><strong>${cf.name}:</strong> ${val}</p>`;
        });
    }

    content.innerHTML = html;
    document.getElementById('ld_name').textContent = l.name;
    window.openModal('location-detail');
}

// Function to open the edit modal using the ID
function openEditModal(id) {
    const l = window.ALL_LOCATIONS.find(loc => String(loc.id) === String(id));
    if (!l) {
        console.warn(`[Locations] Edit: ID ${id} not found in global data.`);
        return;
    }
    console.log(`[Locations] Action: Opening Edit Modal for ID: ${id}`);

    
    try {
        function safeDate(v){ return /^\d{4}-\d{2}-\d{2}$/.test((v||'')) ? v : ''; }
        function trySetVal(id, value) {
            const el = document.getElementById(id);
            if (el) el.value = value || '';
        }

        // --- 1. Clear previous custom field values first ---
        document.querySelectorAll('#modal-edit [data-cf-id]').forEach(input => {
            if (input.type !== 'checkbox') {
                input.value = '';
            } else {
                input.checked = false;
            }
        });

        // --- 2. Populate standard fields ---
        trySetVal('e_id', l.id);
        trySetVal('e_code', l.code);
        trySetVal('e_name', l.name);
        trySetVal('e_birth', safeDate(l.birthdate));
        trySetVal('e_addr', l.address);
        trySetVal('e_mgr', l.manager_user_id);
        trySetVal('e_phone', l.phone);
        trySetVal('e_phone_ext', l.phone_ext);
        trySetVal('e_email', l.email);
        
        const activeCheckbox = document.getElementById('e_active');
        if (activeCheckbox) activeCheckbox.checked = (l.active == 1); 
        
        trySetVal('e_region_id', l.region_id);
        
        // --- 3. Custom Fields population (FIXED TypeError) ---
        window.CUSTOM_FIELDS.forEach(cf => {
            const input = document.querySelector(`#modal-edit [data-cf-id="${cf.id}"]`);
            if (input) {
                const value = l.custom_fields[cf.id] || ''; 
                
                if (input.tagName === 'TEXTAREA') {
                    input.value = value;
                } else if (input.type === 'checkbox') {
                    input.checked = (value == 1);
                } else {
                    input.value = value;
                }
            }
        });
        
        window.openModal('edit'); 

    } catch (e) {
        console.error("Error opening edit modal:", e);
        // CRITICAL FIX: Log the error detail and then exit
        console.error("Error Detail:", e.message, "at", e.stack);
    }
}

// **FIX: Use Delegated Event Listener for all card interactions.**
document.addEventListener('DOMContentLoaded', function() {
    document.body.addEventListener('click', function(e) {
        let card = e.target.closest('.loc-card');
        
        if (card) {
            const id = parseInt(card.getAttribute('data-id'));
            
            // Critical Diagnostic Logging
            console.log('Click Registered on Card ID:', id);

            // 1. Check for the Edit button click (needs event stop)
            let editButton = e.target.closest('.btn-edit');
            if (editButton) {
                e.stopPropagation(); 
                openEditModal(id);
                return; 
            }
            
            // 2. Default action: Open detail modal
            openLocationModal(id);
        }
    });
});


(function(){
  // Deep-link support after validation bounce
  if (location.hash === '#modal-create') window.openModal('create'); 
  if (location.hash === '#modal-edit')   window.openModal('edit'); 
})();
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
