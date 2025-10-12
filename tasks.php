<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

if (!function_exists('e')) { function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$me = user();
$is_admin = is_admin();
$is_manager = is_manager();

/* ---------------- CSRF ---------------- */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
function csrf_input(){ echo '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">'; }
function csrf_check(){
  if (empty($_POST['csrf']) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    http_response_code(400); exit('Invalid CSRF');
  }
}

/* ---------------- Helpers ---------------- */
if (!function_exists('table_has_column')) {
    function table_has_column(PDO $pdo, $table, $column){
      $s = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
      $s->execute([$table,$column]); return (bool)$s->fetchColumn();
    }
}
if (!function_exists('table_exists_robustly')) {
    function table_exists_robustly(PDO $pdo, $table){
        try {
            $pdo->query("SELECT 1 FROM {$table} LIMIT 1");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
}

/* ---------- Minimal migrations (idempotent) ---------- */
$pdo->exec("
CREATE TABLE IF NOT EXISTS tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(150) NOT NULL,
  description TEXT NULL,
  assignee INT NULL,
  due_date DATE NULL,
  status ENUM('open','completed') NOT NULL DEFAULT 'open',
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  scheduled_task_run_ticket_id INT NULL,
  INDEX(assignee), INDEX(status),
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
// CRITICAL: Ensure the linking column exists if the table was created elsewhere
if (!table_has_column($pdo, 'tasks', 'scheduled_task_run_ticket_id')) {
    try {
        $pdo->exec("ALTER TABLE tasks ADD COLUMN scheduled_task_run_ticket_id INT NULL");
    } catch (Throwable $e) { /* silent fail */ }
}

$pdo->exec("
CREATE TABLE IF NOT EXISTS task_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  description TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
$pdo->exec("
CREATE TABLE IF NOT EXISTS task_template_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  template_id INT NOT NULL,
  item_text VARCHAR(255) NOT NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_tti_tpl FOREIGN KEY (template_id) REFERENCES task_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
$pdo->exec("
CREATE TABLE IF NOT EXISTS scheduled_tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(150) NOT NULL,
  template_id INT NOT NULL,
  schedule_type ENUM('daily','weekly','biweekly','monthly','bimonthly') NOT NULL,
  byday VARCHAR(32) NULL,
  day_of_month TINYINT NULL,
  start_date DATE NOT NULL,
  next_run_at DATETIME NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'Africa/Johannesburg',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sched_tpl FOREIGN KEY (template_id) REFERENCES task_templates(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
$pdo->exec("
CREATE TABLE IF NOT EXISTS scheduled_task_assignees (
  task_id INT NOT NULL,
  user_id INT NOT NULL,
  PRIMARY KEY (task_id,user_id),
  CONSTRAINT fk_sta_task FOREIGN KEY (task_id) REFERENCES scheduled_tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
$pdo->exec("
CREATE TABLE IF NOT EXISTS scheduled_task_runs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  task_id INT NOT NULL,
  run_date DATE NOT NULL,
  status ENUM('open','complete') NOT NULL DEFAULT 'open',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_str_task FOREIGN KEY (task_id) REFERENCES scheduled_tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
$pdo->exec("
CREATE TABLE IF NOT EXISTS scheduled_task_run_tickets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  run_id INT NOT NULL,
  ticket_id INT NOT NULL,
  assignee_id INT NULL,
  CONSTRAINT fk_strt_run FOREIGN KEY (run_id) REFERENCES scheduled_task_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
$pdo->exec("
CREATE TABLE IF NOT EXISTS task_run_checklist (
    ticket_id INT NOT NULL,
    item_index INT NOT NULL,
    is_completed TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (ticket_id, item_index),
    CONSTRAINT fk_trc_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");


/* ---------- Ticket creation (modified for task tickets) ---------- */
function create_ticket_auto(PDO $pdo, array $payload){
    $wantCols = ['ticket_number','subject','requester_email','description','status','priority','created_by','created_at', 'agent_id'];
    $cols = [];
    foreach ($wantCols as $c) {
        $s=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tickets' AND COLUMN_NAME=? LIMIT 1");
        $s->execute([$c]); if ($s->fetchColumn()) $cols[]=$c;
    }
    if (!in_array('subject',$cols,true)) throw new RuntimeException('tickets.subject missing');

    // NEW: Get creator/manager info (task creator becomes ticket requester)
    $requester_email = null;
    $requester_id = $payload['created_by'] ?? null; // ID of the manager who created the task
    if ($requester_id) {
        $st_req = $pdo->prepare("SELECT email FROM users WHERE id=?");
        $st_req->execute([$requester_id]);
        $requester_email = $st_req->fetchColumn();
    }
    
    $priorityEnum = []; $priorityDefault = null;
    if (in_array('priority',$cols,true)) {
        $s = $pdo->prepare("SELECT COLUMN_TYPE, COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tickets' AND COLUMN_NAME='priority' LIMIT 1");
        $s->execute();
        if ($row = $s->fetch(PDO::FETCH_ASSOC)) {
            if (preg_match_all("/'([^']*)'/", (string)$row['COLUMN_TYPE'], $m)) $priorityEnum = $m[1];
            $priorityDefault = $row['COLUMN_DEFAULT'] ?? null;
        }
    }

    $payload2 = $payload;
    $bodyText = $payload['description'] ?? '';
    if (trim($bodyText)==='') $bodyText = 'Generated from template.';
    if (in_array('description',$cols,true) && !isset($payload2['description'])) $payload2['description'] = $bodyText;

    $params = []; $columns_list = [];
    foreach ($cols as $c) {
        if ($c==='ticket_number') {
            $columns_list[] = $c;
            $n = (int)$pdo->query("SELECT IFNULL(MAX(id),0)+1 FROM tickets")->fetchColumn();
            $params[":$c"] = date('Ymd').'-'.str_pad((string)$n,4,'0',STR_PAD_LEFT);
            continue;
        }

        if ($c==='status') {
            $columns_list[] = $c;
            $params[":$c"] = $payload2['status'] ?? 'Open';
            continue;
        }

        if ($c==='priority') {
            $columns_list[] = $c;
            $want = $payload2['priority'] ?? null;
            if (!$want || !in_array($want, $priorityEnum, true)) {
                $want = $priorityDefault ?: ($priorityEnum[0] ?? 'Medium');
            }
            $params[":$c"] = $want;
            continue;
        }
        
        if ($c==='requester_email') { // NEW: Set requester email to task creator's email
            $columns_list[] = $c;
            $params[":$c"] = $requester_email;
            continue;
        }
        
        // Assignee in task context becomes Agent in ticket context
        if ($c==='agent_id') {
            $columns_list[] = $c;
            $params[":$c"] = $payload2['assignee_id'] ?? null; 
            continue;
        }

        if ($c==='created_at') {
            $columns_list[] = $c;
            $params[":$c"] = $payload2['created_at'] ?? date('Y-m-d H:i:s');
            continue;
        }

        $columns_list[] = $c;
        $params[":$c"] = $payload2[$c] ?? null;
    }

    $sql = "INSERT INTO tickets (".implode(',',$columns_list).") VALUES (".implode(',', array_keys($params)).")";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int)$pdo->lastInsertId();
}

/* ---------- Compute next run (weekday mapping fix) ---------- */
function compute_next_run(array $t, DateTime $from=null): ?DateTime {
  $tz = new DateTimeZone($t['timezone'] ?: 'Africa/Johannesburg');
  $now = $from ?: new DateTime('now', $tz);
  $start = new DateTime($t['start_date'], $tz);
  if ($now < $start) $now = clone $start;

  $type = $t['schedule_type'];
  if ($type === 'daily') {
    $d = clone $now; if ($d->format('H:i:s') > '00:00:01') $d->modify('+1 day');
    return $d->setTime(8,0,0);
  }
  if ($type === 'weekly' || $type === 'biweekly') {
    $by = array_filter(array_map('trim', explode(',', (string)$t['byday'])));
    if (!$by) $by = ['MO'];
    $map = ['Mon'=>'MO','Tue'=>'TU','Wed'=>'WE','THU'=>'TH','Fri'=>'FR','Sat'=>'SA','Sun'=>'SU'];
    $d = clone $now; $d->setTime(8,0,0);
    $wkStart = (int)$start->format('W');
    for ($i=0; $i<60; $i++) {
      $iso = $map[$d->format('D')] ?? 'MO';
      $okWeek = ($type==='biweekly') ? ((((int)$d->format('W') - $wkStart) % 2) === 0) : true;
      if (in_array($iso,$by,true) && $okWeek && $d >= $start) return $d;
      $d->modify('+1 day');
    }
    return null;
  }
  if ($type === 'monthly' || $type === 'bimonthly') {
    $dom = (int)($t['day_of_month'] ?: 1);
    $months_step = (int)$t['day_of_month'] > 28 ? 1 : (($type==='bimonthly') ? 2 : 1);
    $base = clone $now; $base->setTime(8,0,0);
    for ($i=0;$i<24;$i++){
      $candidate = (clone $base)->modify('+'.($i*$months_step).' months');
      $candidate->setDate((int)$candidate->format('Y'), (int)$candidate->format('m'), min($dom, (int)$candidate->format('t')));
      if ($candidate >= $start && $candidate >= $now) return $candidate;
    }
    return null;
  }
  return null;
}

/* ---------- POST actions ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST') {

  /* CREATE: simple task */
  if (isset($_POST['create_simple'])) {
    csrf_check();
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $assignee = (int)($_POST['assignee'] ?? 0);
    $due_date = $_POST['due_date'] ?: null;
    $stmt = $pdo->prepare("INSERT INTO tasks (title,description,assignee,due_date,created_by) VALUES(:t,:d,:a,:dd,:cb)");
    $stmt->execute([':t'=>$title, ':d'=>$description, ':a'=>$assignee, ':dd'=>$due_date, ':cb'=>$me['id']]);
    header('Location: tasks.php?msg=created'); exit;
  }

  /* UPDATE: simple task */
  if (isset($_POST['update_simple'])) {
    csrf_check();
    $id = (int)($_POST['simple_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $assignee = (int)($_POST['assignee'] ?? 0);
    $due_date = $_POST['due_date'] ?: null;

    $st = $pdo->prepare("UPDATE tasks SET title=?, description=?, assignee=?, due_date=? WHERE id=?");
    $st->execute([$title, $description, $assignee, $due_date, $id]);
    header('Location: tasks.php?msg=task_updated'); exit;
  }

  /* COMPLETE: simple task (Only allowed if no ticket link) */
  if (isset($_POST['complete'])) {
    csrf_check();
    $id = (int)$_POST['id'];
    
    $st_check = $pdo->prepare("SELECT scheduled_task_run_ticket_id FROM tasks WHERE id = ?");
    $st_check->execute([$id]);
    if ($st_check->fetchColumn() !== null) {
        // Task linked to a ticket, must be completed by closing the ticket
        header('Location: tasks.php?msg=task_linked_to_ticket'); exit; 
    }

    $pdo->prepare("UPDATE tasks SET status='completed' WHERE id=:id")->execute([':id'=>$id]);
    header('Location: tasks.php?msg=completed'); exit;
  }

  /* DELETE: simple task */
  if (isset($_POST['delete'])) {
    csrf_check();
    $id = (int)$_POST['id'];
    $pdo->prepare("DELETE FROM tasks WHERE id=:id")->execute([':id'=>$id]);
    header('Location: tasks.php?msg=deleted'); exit;
  }

  /* TEMPLATE save (create + edit) */
  if ($is_admin && isset($_POST['tpl_save'])) {
    csrf_check();
    $tpl_id = (int)$_POST['tpl_id'];
    $name   = trim($_POST['tpl_name'] ?? '');
    $desc   = trim($_POST['tpl_desc'] ?? '');
    $active = isset($_POST['tpl_active']) ? 1 : 0;

    if ($tpl_id > 0) {
      $pdo->prepare("UPDATE task_templates SET name=?, description=?, is_active=? WHERE id=?")->execute([$name,$desc,$active,$tpl_id]);
    } else {
      $pdo->prepare("INSERT INTO task_templates (name,description,is_active,created_by) VALUES (?,?,?,?)")->execute([$name,$desc,$active,$me['id']]);
      $tpl_id = (int)$pdo->lastInsertId();
    }

    // Items
    $item_ids    = $_POST['item_id']    ?? [];
    $item_texts  = $_POST['item_text']  ?? [];
    $item_reqs   = $_POST['item_req']   ?? [];
    $item_dels   = $_POST['item_del']   ?? [];
    $sort = 0;

    $ins = $pdo->prepare("INSERT INTO task_template_items (template_id,item_text,is_required,sort_order) VALUES (?,?,?,?)");
    $upd = $pdo->prepare("UPDATE task_template_items SET item_text=?, is_required=?, sort_order=? WHERE id=? AND template_id=?");
    $del = $pdo->prepare("DELETE FROM task_template_items WHERE id=? AND template_id=?");

    foreach ($item_texts as $i => $text) {
      $text = trim((string)$text);
      $id   = trim((string)($item_ids[$i] ?? ''));
      $isReq = isset($item_reqs[$i]) ? 1 : 0;
      $isDel = isset($item_dels[$i]) && $item_dels[$i]=='1';

      if ($id !== '') {
        $id = (int)$id;
        if ($isDel || $text==='') { $del->execute([$id,$tpl_id]); continue; }
        $upd->execute([$text,$isReq,$sort++,$id,$tpl_id]);
      } else {
        if ($isDel || $text==='') continue;
        $ins->execute([$tpl_id,$text,$isReq,$sort++]);
      }
    }

    header('Location: tasks.php?msg=tpl_saved'); exit;
  }

  /* TEMPLATE delete */
  if ($is_admin && isset($_POST['tpl_delete'])) {
    csrf_check();
    $tpl = (int)$_POST['template_id'];
    $pdo->prepare("DELETE FROM task_templates WHERE id=?")->execute([$tpl]);
    header('Location: tasks.php?msg=tpl_deleted'); exit;
  }

  /* CREATE: scheduled task */
  if (isset($_POST['sched_create'])) {
    csrf_check();
    $title   = trim($_POST['s_title'] ?? '');
    $tpl_id  = (int)($_POST['s_template'] ?? 0);
    $stype   = $_POST['s_type'] ?? 'weekly';
    $byday   = '';
    if ($stype==='weekly' || $stype==='biweekly') {
      $by = $_POST['s_byday'] ?? [];
      $by = array_intersect($by, ['MO','TU','WE','TH','FR','SA','SU']);
      $byday = implode(',', $by ?: ['MO']);
    }
    $dom    = null;
    if ($stype==='monthly' || $stype==='bimonthly') {
      $dom = max(1, min(31, (int)$_POST['s_dom']));
    }
    $start  = $_POST['s_start'] ?: date('Y-m-d');
    $active = isset($_POST['s_active']) ? 1 : 0;
    $assignees = array_map('intval', $_POST['s_assignees'] ?? []);

    $pdo->beginTransaction();
    $ins = $pdo->prepare("INSERT INTO scheduled_tasks (title,template_id,schedule_type,byday,day_of_month,start_date,timezone,active,created_by) VALUES (?,?,?,?,?,?,?,?,?)");
    $ins->execute([$title,$tpl_id,$stype,$byday,$dom,$start,'Africa/Johannesburg',$active,$me['id']]);
    $task_id = (int)$pdo->lastInsertId();
    if ($assignees) {
      $insA = $pdo->prepare("INSERT INTO scheduled_task_assignees (task_id,user_id) VALUES (?,?)");
      foreach ($assignees as $u) $insA->execute([$task_id,$u]);
    }
    $taskRow = $pdo->query("SELECT * FROM scheduled_tasks WHERE id=".$task_id)->fetch(PDO::FETCH_ASSOC);
    $nxt = compute_next_run($taskRow);
    if ($nxt) { $pdo->prepare("UPDATE scheduled_tasks SET next_run_at=? WHERE id=?")->execute([$nxt?$nxt->format('Y-m-d H:i:s'):null, $task_id]); }
    $pdo->commit();
    header('Location: tasks.php?msg=scheduled_created'); exit;
  }

  /* UPDATE: scheduled task */
  if (isset($_POST['sched_update'])) {
    csrf_check();
    $task_id = (int)($_POST['sched_id'] ?? 0);
    $title   = trim($_POST['s_title'] ?? '');
    $tpl_id  = (int)($_POST['s_template'] ?? 0);
    $stype   = $_POST['s_type'] ?? 'weekly';

    $byday = '';
    if ($stype==='weekly' || $stype==='biweekly') {
      $by = $_POST['s_byday'] ?? [];
      $by = array_intersect($by, ['MO','TU','WE','TH','FR','SA','SU']);
      $byday = implode(',', $by ?: ['MO']);
    }
    $dom = null;
    if ($stype==='monthly' || $stype==='bimonthly') {
      $dom = max(1, min(31, (int)($_POST['s_dom'] ?? 1)));
    }
    $start  = $_POST['s_start'] ?: date('Y-m-d');
    $active = isset($_POST['s_active']) ? 1 : 0;
    $assignees = array_map('intval', $_POST['s_assignees'] ?? []);

    $pdo->beginTransaction();
    $pdo->prepare("
      UPDATE scheduled_tasks
      SET title=?, template_id=?, schedule_type=?, byday=?, day_of_month=?, start_date=?, active=?
      WHERE id=?
    ")->execute([$title,$tpl_id,$stype,$byday,$dom,$start,$active,$task_id]);

    $pdo->prepare("DELETE FROM scheduled_task_assignees WHERE task_id=?")->execute([$task_id]);
    if ($assignees) {
      $insA = $pdo->prepare("INSERT INTO scheduled_task_assignees (task_id,user_id) VALUES (?,?)");
      foreach ($assignees as $u) $insA->execute([$task_id,$u]);
    }

    $row = $pdo->query("SELECT * FROM scheduled_tasks WHERE id=".$task_id)->fetch(PDO::FETCH_ASSOC);
    $nxt = compute_next_run($row);
    $pdo->prepare("UPDATE scheduled_tasks SET next_run_at=? WHERE id=?")->execute([$nxt?$nxt->format('Y-m-d H:i:s'):null, $task_id]);

    $pdo->commit();
    header('Location: tasks.php?msg=scheduled_updated'); exit;
  }

  /* Pause/Resume/Delete scheduled */
  if (isset($_POST['sched_pause'])) { csrf_check(); $id=(int)$_POST['id']; $pdo->prepare("UPDATE scheduled_tasks SET active=0 WHERE id=?")->execute([$id]); header('Location: tasks.php?msg=paused'); exit; }
  if (isset($_POST['sched_resume'])){ csrf_check(); $id=(int)$_POST['id']; $pdo->prepare("UPDATE scheduled_tasks SET active=1 WHERE id=?")->execute([$id]); header('Location: tasks.php?msg=active'); exit; }
  if (isset($_POST['sched_delete'])){ csrf_check(); $id=(int)$_POST['id']; $pdo->prepare("DELETE FROM scheduled_tasks WHERE id=?")->execute([$id]); header('Location: tasks.php?msg=deleted'); exit; }


  /* Run now */
  if (isset($_POST['run_now'])) {
    csrf_check();
    $task_id=(int)$_POST['id'];
    try {
      $task = $pdo->query("SELECT * FROM scheduled_tasks WHERE id=".$task_id)->fetch(PDO::FETCH_ASSOC);
      if ($task && (int)$task['active']===1){
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO scheduled_task_runs (task_id, run_date) VALUES (?, CURDATE())")->execute([$task['id']]);
        $run_id = (int)$pdo->lastInsertId();
        $tpl = $pdo->prepare("SELECT * FROM task_templates WHERE id=?"); $tpl->execute([$task['template_id']]); $template = $tpl->fetch(PDO::FETCH_ASSOC);
        $items = $pdo->prepare("SELECT * FROM task_template_items WHERE template_id=? ORDER BY sort_order,id"); $items->execute([$task['template_id']]); $items=$items->fetchAll(PDO::FETCH_ASSOC);
        $ass = $pdo->prepare("SELECT user_id FROM scheduled_task_assignees WHERE task_id=?"); $ass->execute([$task['id']]); $assignees=$ass->fetchAll(PDO::FETCH_COLUMN);

        $body = '';
        if (!empty($template['description'])) $body .= $template['description']."\n\n";
        if ($items) { $body .= "Checklist:\n"; foreach ($items as $it) { $body .= "- [ ] ".$it['item_text'].($it['is_required']?' (required)':'')."\n"; } }
        $subject = $task['title'].' — '.date('D d M');

        // Manager who created the task is the ticket's internal requester
        $manager_id = $task['created_by']; 
        
        foreach ($assignees as $uid) {
          $tid = create_ticket_auto($pdo, [
            'subject'       => $subject,
            'description'   => $body,
            'status'        => 'Open',
            'priority'      => 'Medium',          
            'created_by'    => $manager_id ?? null, // Task creator remains ticket creator
            'assignee_id'   => (int)$uid,          // Task assignee becomes ticket agent
          ]);
          $pdo->prepare("INSERT INTO scheduled_task_run_tickets (run_id,ticket_id,assignee_id) VALUES (?,?,?)")->execute([$run_id,$tid,$uid]);

          // Checklist item insertion (REQUIRED FOR TASK CHECKLIST)
          $ins_checklist = $pdo->prepare("INSERT INTO task_run_checklist (ticket_id, item_index) VALUES (?,?)");
          foreach($items as $idx => $it) {
              $ins_checklist->execute([$tid, $idx]);
          }
        }
        $next = compute_next_run($task, new DateTime('tomorrow', new DateTimeZone($task['timezone'] ?: 'Africa/Johannesburg')));
        if ($next) $pdo->prepare("UPDATE scheduled_tasks SET next_run_at=? WHERE id=?")->execute([$nxt?$nxt->format('Y-m-d H:i:s'):null, $task['id']]);
        $pdo->commit();
      }
      header('Location: tasks.php?msg=run_created'); exit;
    } catch (Throwable $e) {
      @file_put_contents('/tmp/scheduled_tasks_error.log', "[".date('c')."] RUN_NOW: ".$e->getMessage()."\n", FILE_APPEND);
      if ($pdo->inTransaction()) $pdo->rollBack();
      header('Location: tasks.php?msg=run_error'); exit;
    }
  }

  /* Close run (only when all tickets resolved/closed) */
  if (isset($_POST['close_run'])) {
    csrf_check();
    $run_id=(int)$_POST['run_id'];
    $ok = $pdo->prepare("
      SELECT COUNT(*) FROM scheduled_task_run_tickets rt
      JOIN tickets tk ON tk.id=rt.ticket_id
      WHERE rt.run_id=? AND tk.status NOT IN ('Resolved','Closed')
    "); $ok->execute([$run_id]); $openLeft = (int)$ok->fetchColumn();
    if ($openLeft===0) {
      $pdo->prepare("UPDATE scheduled_task_runs SET status='complete' WHERE id=?")->execute([$run_id]);
      header('Location: tasks.php?msg=run_closed'); exit;
    } else {
      header('Location: tasks.php?msg=run_incomplete'); exit;
    }
  }
}

// Auto-complete simple tasks linked to tickets when the ticket is closed
if (table_exists_robustly($pdo, 'tasks') && table_exists_robustly($pdo, 'scheduled_task_run_tickets') && table_exists_robustly($pdo, 'tickets')) {
    try {
        $pdo->exec("
            UPDATE tasks t
            INNER JOIN scheduled_task_run_tickets rtt ON rtt.id = t.scheduled_task_run_ticket_id
            INNER JOIN tickets tk ON tk.id = rtt.ticket_id
            SET t.status = 'completed'
            WHERE t.status <> 'completed' 
            AND tk.status IN ('Resolved', 'Closed')
        ");
    } catch (Throwable $e) {
        // Log error, but proceed - this query is defensive and ensures cleanup.
    }
}


/* ---------- Data for UI ---------- */
$users = $pdo->query("SELECT id, first_name, last_name, email FROM users ORDER BY first_name,last_name")->fetchAll(PDO::FETCH_ASSOC);

$templates = $pdo->query("SELECT * FROM task_templates WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/* All templates + items (for list + modal prefill) */
$allTpl = $pdo->query("SELECT * FROM task_templates ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$tplIds = array_column($allTpl,'id');
$itemsByTpl = [];
if ($tplIds){
  $in = implode(',', array_fill(0,count($tplIds),'?'));
  $st = $pdo->prepare("SELECT * FROM task_template_items WHERE template_id IN ($in) ORDER BY sort_order,id");
  $st->execute($tplIds);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $it) { $itemsByTpl[(int)$it['template_id']][] = $it; }
}

$scheduled = $pdo->query("
  SELECT st.*,
         (SELECT COUNT(*) FROM scheduled_task_assignees a WHERE a.task_id=st.id) AS assignee_count
  FROM scheduled_tasks st
  ORDER BY st.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Current runs with progress
$runs = $pdo->query("
  SELECT r.*, st.title
  FROM scheduled_task_runs r
  JOIN scheduled_tasks st ON st.id=r.task_id
  WHERE r.status='open'
  ORDER BY r.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$runProgress = [];
if ($runs) {
  $in = implode(',', array_fill(0,count($runs),'?'));
  $ids = array_column($runs,'id');
  $sql = "
    SELECT rt.run_id,
           SUM(CASE WHEN tk.status IN ('Resolved','Closed') THEN 1 ELSE 0 END) AS done,
           COUNT(*) AS total
    FROM scheduled_task_run_tickets rt
    JOIN tickets tk ON tk.id=rt.ticket_id
    WHERE rt.run_id IN ($in)
    GROUP BY rt.run_id
  ";
  $st = $pdo->prepare($sql); $st->execute($ids);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $runProgress[(int)$row['run_id']] = $row;
}

// Simple tasks - FILTERED by assignee or creator
$taskSql = "
  SELECT t.*, CONCAT_WS(' ',u.first_name,u.last_name) AS assignee_user, rtt.ticket_id
  FROM tasks t 
  LEFT JOIN users u ON t.assignee=u.id
  LEFT JOIN scheduled_task_run_tickets rtt ON rtt.id = t.scheduled_task_run_ticket_id
  WHERE t.assignee = ? OR t.created_by = ?
  ORDER BY t.id DESC
";
$st = $pdo->prepare($taskSql);
$st->execute([$me['id'], $me['id']]);
$tasks = $st->fetchAll(PDO::FETCH_ASSOC);


/* === JS maps for editing === */
$simpleTasksForJs = [];
foreach ($tasks as $t) {
  $simpleTasksForJs[] = [
    'id' => (int)$t['id'],
    'title' => (string)$t['title'],
    'description' => (string)($t['description'] ?? ''),
    'assignee' => (int)($t['assignee'] ?? 0),
    'due_date' => (string)($t['due_date'] ?? ''),
    'is_linked' => (bool)$t['scheduled_task_run_ticket_id'] // Pass link status to JS
  ];
}
$scheduledForJs = $pdo->query("SELECT * FROM scheduled_tasks ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$assRows = $pdo->query("SELECT task_id,user_id FROM scheduled_task_assignees")->fetchAll(PDO::FETCH_ASSOC);
$assMap = [];
foreach ($assRows as $r) { $assMap[(int)$r['task_id']][] = (int)$r['user_id']; }
foreach ($scheduledForJs as &$s) { $s['assignees'] = $assMap[(int)$s['id']] ?? []; }
unset($s);

include __DIR__ . '/partials/header.php';
?>

<div class="card">
  <div class="card-h">
    <h3>Dashboard — Scheduled Tasks</h3>
    <div>
      <?php if ($is_admin || $is_manager): ?>
        <button class="btn btn-primary" onclick="openModal('sched')">+ New Scheduled Task</button>
      <?php endif; ?>
      <?php if ($is_admin): ?>
        <button class="btn" onclick="scrollToTemplates()">Manage Templates</button>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-b" style="overflow:auto">
    <?php if(isset($_GET['msg'])): ?>
      <?php $m = $_GET['msg']; $text = [
          'saved'=>'User saved','created'=>'Task created','deleted'=>'Task deleted',
          'task_linked_to_ticket'=>'Task is linked to a ticket and must be completed by resolving the ticket.',
          'run_created'=>'Scheduled tickets successfully created.',
          'paused'=>'Scheduled task paused.', 'active'=>'Scheduled task resumed.',
          'scheduled_updated'=>'Scheduled task updated.', 'tpl_saved'=>'Template saved.',
          'tpl_deleted'=>'Template deleted.', 'run_closed'=>'Run marked complete.',
          'run_incomplete'=>'Run has open tickets and cannot be closed.',
          'run_error'=>'Error running task.', 'scheduled_created'=>'Scheduled task created.'
      ][$m] ?? e($m); ?>
      <div class="badge badge-success" style="display:block;margin-bottom:1rem;">Action: <?= e($text) ?></div>
    <?php endif; ?>

    <?php if(!$scheduled): ?>
      <p>No scheduled tasks yet.</p>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>Title</th><th>Template</th><th>Frequency</th><th>Assignees</th><th>Next Run</th><th>Status</th><th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($scheduled as $s): ?>
          <tr>
            <td><?= e($s['title']) ?></td>
            <td><?= e($s['template_id'] ?? '-') ?></td>
            <td>
              <span class="badge badge-primary">
                <?= e(ucfirst($s['schedule_type'])) ?>
                <?php if(in_array($s['schedule_type'],['weekly','biweekly']) && $s['byday']): ?> — <?= e($s['byday']) ?><?php endif; ?>
                <?php if(in_array($s['schedule_type'],['monthly','bimonthly']) && $s['day_of_month']): ?> — day <?= (int)$s['day_of_month'] ?><?php endif; ?>
              </span>
            </td>
            <td><?= (int)$s['assignee_count'] ?></td>
            <td><?= e($s['next_run_at'] ?: '—') ?></td>
            <td>
              <span class="badge <?= $s['active']?'badge-success':'badge-warning' ?>"><?= $s['active']?'Active':'Paused' ?></span>
            </td>
            <td>
              <?php if ($is_admin || $is_manager): ?>
              <form method="post" style="display:inline"><?php csrf_input(); ?>
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <button class="btn btn-primary" name="run_now" value="1" title="Generate tickets now">Run now</button>
              </form>
              <button class="btn" type="button" onclick="openScheduledEdit(<?= (int)$s['id'] ?>)">Edit</button>
              <?php if($s['active']): ?>
                <form method="post" style="display:inline"><?php csrf_input(); ?>
                  <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                  <button class="btn" name="sched_pause" value="1">Pause</button>
                </form>
              <?php else: ?>
                <form method="post" style="display:inline"><?php csrf_input(); ?>
                  <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                  <button class="btn" name="sched_resume" value="1">Resume</button>
                </form>
              <?php endif; ?>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete scheduled task?')"><?php csrf_input(); ?>
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <button class="btn btn-danger" name="sched_delete" value="1">Delete</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<?php if($runs): ?>
<div class="card">
  <div class="card-h"><h3>Open Runs (progress)</h3></div>
  <div class="card-b" style="overflow:auto">
    <table class="table">
      <thead><tr><th>Task</th><th>Run Date</th><th>Progress</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach($runs as $r):
        $pr = $runProgress[$r['id']] ?? ['done'=>0,'total'=>0];
      ?>
        <tr>
          <td><?= e($r['title']) ?></td>
          <td><?= e($r['run_date']) ?></td>
          <td>
            <span class="badge <?= ($pr['total']>0 && $pr['done']===$pr['total'])?'badge-success':'badge-warning' ?>">
              <?= (int)($pr['done']) ?>/<?= (int)($pr['total']) ?>
            </span>
          </td>
          <td>
            <?php if ($is_admin || $is_manager): ?>
            <form method="post" style="display:inline"><?php csrf_input(); ?>
              <input type="hidden" name="run_id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-primary" name="close_run" value="1" <?= ($pr['total']>0 && $pr['done']===$pr['total'])?'':'disabled' ?>>Mark run complete</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-h">
    <h3>Dashboard — My Tasks</h3>
    <?php if ($is_admin || $is_manager): ?>
      <button class="btn" onclick="openModal('simple')">+ New Task</button>
    <?php endif; ?>
  </div>
  <div class="card-b" style="overflow:auto">
    <?php if(!$tasks): ?>
      <p>No tasks assigned to you or created by you.</p>
    <?php else: ?>
      <table class="table">
        <thead><tr><th>Task</th><th>Assignee</th><th>Due</th><th>Status</th><th>Linked Ticket</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($tasks as $t): ?>
          <tr>
            <td><?= e($t['title']) ?></td>
            <td><?= e($t['assignee_user'] ?? '-') ?></td>
            <td><?= e($t['due_date']) ?></td>
            <td><span class="badge <?= ($t['status']??'')==='completed'?'badge-success':'badge-warning' ?>"><?= e($t['status'] ?? 'open') ?></span></td>
            <td>
              <?php if (!empty($t['ticket_id'])): ?>
                <a href="tickets.php#t<?= (int)$t['ticket_id'] ?>">Ticket #<?= (int)$t['ticket_id'] ?></a>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
            <td>
              <?php if (($t['status']??'')!=='completed'): ?>
                  <button class="btn" type="button" onclick="openSimpleEdit(<?= (int)$t['id'] ?>)">Edit</button>
                  <?php if (empty($t['ticket_id'])): // Only allow direct completion if no ticket is linked ?>
                      <form method="post" style="display:inline"><?php csrf_input(); ?>
                        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                        <button class="btn btn-primary" name="complete" value="1">Complete</button>
                      </form>
                  <?php else: ?>
                      <span class="badge badge-warning">Close ticket to complete</span>
                  <?php endif; ?>
              <?php endif; ?>
              <?php if ($is_admin || $is_manager): ?>
                  <form method="post" style="display:inline" onsubmit="return confirm('Delete this task?')"><?php csrf_input(); ?>
                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                    <button class="btn btn-danger" name="delete" value="1">Delete</button>
                  </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<?php if ($is_admin): ?>
<div class="card" id="templates">
  <div class="card-h">
    <h3>Dashboard — Task Templates (admin)</h3>
    <div>
      <button class="btn btn-primary" onclick="openTemplateModalCreate()">+ New Template</button>
    </div>
  </div>
  <div class="card-b">
    <?php if(!$allTpl): ?>
      <p>No templates yet.</p>
    <?php else: ?>
      <table class="table">
        <thead><tr><th>Name</th><th>Active</th><th>Items</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($allTpl as $tpl): $countItems = isset($itemsByTpl[(int)$tpl['id']]) ? count($itemsByTpl[(int)$tpl['id']]) : 0; ?>
          <tr>
            <td><?= e($tpl['name']) ?></td>
            <td><span class="badge <?= $tpl['is_active']?'badge-success':'badge-warning' ?>"><?= $tpl['is_active']?'Yes':'No' ?></span></td>
            <td><?= (int)$countItems ?></td>
            <td>
              <button class="btn btn-primary" onclick="openTemplateModalEdit(<?= (int)$tpl['id'] ?>)">Edit</button>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete template? This removes its items too.')"><?php csrf_input(); ?>
                <input type="hidden" name="template_id" value="<?= (int)$tpl['id'] ?>">
                <button class="btn btn-danger" name="tpl_delete" value="1">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div id="modal-sched" class="modal" style="display:none">
  <div class="modal-backdrop" onclick="closeModal('sched')"></div>
  <div class="modal-card">
    <div class="modal-h"><h3>New Scheduled Task</h3><button class="btn" onclick="closeModal('sched')">✕</button></div>
    <div class="modal-b">
      <form method="post" class="grid" style="grid-template-columns:1fr 1fr; gap:1rem">
        <?php csrf_input(); ?>
        <input type="hidden" name="sched_id" id="sched_id">
        <input class="input" name="s_title" placeholder="Title *" required>
        <select class="input" name="s_template" id="s_template" required onchange="onTemplateSelectChange()">
          <option value="">Select template…</option>
          <?php foreach($templates as $t): ?>
            <option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option>
          <?php endforeach; ?>
          <?php if ($is_admin): ?>
            <option value="__new__">+ New template…</option>
          <?php endif; ?>
        </select>

        <div class="grid" style="grid-template-columns:1fr">
          <label style="font-weight:600">Assign to users</label>
          <div class="input" style="padding:.5rem">
            <div style="max-height:180px; overflow:auto">
              <?php foreach($users as $u): ?>
                <label style="display:flex; align-items:center; gap:.5rem; margin:.25rem 0">
                  <input type="checkbox" name="s_assignees[]" value="<?= (int)$u['id'] ?>">
                  <span><?= e($u['first_name'].' '.$u['last_name'].' <'.$u['email'].'>') ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <div>
          <label style="font-weight:600">Frequency</label>
          <select class="input" name="s_type" id="s_type" onchange="onTypeChange()">
            <option value="weekly">Weekly</option>
            <option value="biweekly">Bi-weekly</option>
            <option value="daily">Daily</option>
            <option value="monthly">Monthly</option>
            <option value="bimonthly">Bi-monthly</option>
          </select>
        </div>

        <div id="weekdays">
          <label style="font-weight:600; display:block">Weekdays</label>
          <?php foreach(['MO'=>'Mon','TU'=>'Tue','WE'=>'Wed','TH'=>'Thu','FR'=>'Fri','SA'=>'Sat','SU'=>'Sun'] as $k=>$v): ?>
            <label style="display:inline-flex; align-items:center; gap:.35rem; margin:.25rem .5rem .25rem 0">
              <input type="checkbox" name="s_byday[]" value="<?= $k ?>" <?= $k==='MO'?'checked':''; ?>> <?= $v ?>
            </label>
          <?php endforeach; ?>
        </div>

        <div id="dom" style="display:none">
          <label style="font-weight:600">Day of month</label>
          <input class="input" type="number" name="s_dom" min="1" max="31" value="1">
        </div>

        <div>
          <label style="font-weight:600; display:block">Start date</label>
          <input class="input" type="date" name="s_start" value="<?= e(date('Y-m-d')) ?>">
        </div>

        <label style="display:flex; align-items:center; gap:.5rem">
          <input type="checkbox" name="s_active" checked> Active
        </label>

        <div style="grid-column:1/-1">
          <button class="btn btn-primary" id="btn-sched-create" name="sched_create" value="1">Create Scheduled Task</button>
          <button class="btn btn-primary" id="btn-sched-update" name="sched_update" value="1" style="display:none">Save Changes</button>
          <button class="btn" type="button" onclick="closeModal('sched')">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div id="modal-simple" class="modal" style="display:none">
  <div class="modal-backdrop" onclick="closeModal('simple')"></div>
  <div class="modal-card">
    <div class="modal-h"><h3>New Task</h3><button class="btn" onclick="closeModal('simple')">✕</button></div>
    <div class="modal-b">
      <form method="post" class="grid grid-2">
        <?php csrf_input(); ?>
        <input type="hidden" name="simple_id" id="simple_id">
        <input class="input" name="title" placeholder="Task Title *" required>
        <input class="input" type="date" name="due_date" required>
        <div class="grid" style="grid-template-columns:1fr">
          <textarea class="input" name="description" rows="3" placeholder="Description"></textarea>
        </div>
        <div>
          <select class="input" name="assignee" required>
            <?php foreach($users as $u): ?>
              <option value="<?= (int)$u['id'] ?>"><?= e($u['first_name'].' '.$u['last_name'].' <'.$u['email'].'>') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <button class="btn btn-primary" id="btn-simple-create" name="create_simple" value="1">Create Task</button>
          <button class="btn btn-primary" id="btn-simple-update" name="update_simple" value="1" style="display:none">Save Changes</button>
          <button class="btn" type="button" onclick="closeModal('simple')">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($is_admin): ?>
<div id="modal-template" class="modal" style="display:none">
  <div class="modal-backdrop" onclick="closeTemplateModal()"></div>
  <div class="modal-card">
    <div class="modal-h"><h3 id="tpl-modal-title">Template</h3><button class="btn" onclick="closeTemplateModal()">✕</button></div>
    <div class="modal-b">
      <form method="post" id="tpl-form">
        <?php csrf_input(); ?>
        <input type="hidden" name="tpl_id" id="tpl_id">
        <div class="grid" style="grid-template-columns:1fr 1fr; gap:1rem">
          <input class="input" name="tpl_name" id="tpl_name" placeholder="Template name *" required>
          <label style="display:flex; align-items:center; gap:.5rem">
            <input type="checkbox" name="tpl_active" id="tpl_active" checked> Active
          </label>
          <textarea class="input" name="tpl_desc" id="tpl_desc" rows="2" placeholder="Optional description" style="grid-column:1/-1"></textarea>
        </div>

        <div style="margin-top:1rem">
          <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:.5rem">
            <strong>Checklist items</strong>
            <button class="btn" type="button" onclick="addTplItemRow()">+ Add item</button>
          </div>
          <div id="tpl-items"></div>
        </div>

        <div style="margin-top:1rem">
          <button class="btn btn-primary" name="tpl_save" value="1">Save Template</button>
          <button class="btn" type="button" onclick="closeTemplateModal()">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<style>
.modal{position:fixed;inset:0;z-index:60;display:flex;align-items:center;justify-content:center}
.modal-backdrop{position:absolute;inset:0;background:rgba(0,0,0,.35)}
.modal-card{position:relative;background:var(--white);border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,.15);width:min(1000px,94vw);max-height:90vh;overflow:auto}
.modal-h{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid #e5e7eb}
.modal-b{padding:1rem 1.25rem}
.tpl-row{display:grid;grid-template-columns:1fr auto auto auto;gap:.5rem;align-items:center;margin:.4rem 0}
.tpl-row .input{margin:0}
.badge{vertical-align:middle}
</style>
<script>
function openModal(name){ document.getElementById('modal-'+name).style.display='flex'; }
function closeModal(name){ document.getElementById('modal-'+name).style.display='none'; }
function scrollToTemplates(){ const el=document.getElementById('templates'); if(el){ el.scrollIntoView({behavior:'smooth'}); } }

/* Frequency UI */
function onTypeChange(){
  var t = document.getElementById('s_type').value;
  document.getElementById('weekdays').style.display = (t==='weekly'||t==='biweekly')?'block':'none';
  document.getElementById('dom').style.display = (t==='monthly'||t==='bimonthly')?'block':'none';
}
onTypeChange();

/* Templates JSON for modal prefilling */
<?php
  $tplForJs = [];
  foreach ($allTpl as $tpl) {
    $tplForJs[] = [
      'id' => (int)$tpl['id'],
      'name' => $tpl['name'],
      'description' => $tpl['description'],
      'is_active' => (int)$tpl['is_active'],
      'items' => array_values($itemsByTpl[$tpl['id']] ?? [])
    ];
  }
?>
window.TEMPLATES = <?= json_encode($tplForJs, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
window.SIMPLE_TASKS = <?= json_encode($simpleTasksForJs, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
window.SCHEDULED    = <?= json_encode($scheduledForJs,     JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;

/* Template modal controls */
function openTemplateModalCreate(){
  fillTemplateForm({id:0,name:'',description:'',is_active:1,items:[]});
  document.getElementById('tpl-modal-title').textContent='New Template';
  document.getElementById('modal-template').style.display='flex';
}
function openTemplateModalEdit(id){
  const t = (window.TEMPLATES || []).find(x=>x.id===id);
  if(!t) return;
  fillTemplateForm(t);
  document.getElementById('tpl-modal-title').textContent='Edit Template';
  document.getElementById('modal-template').style.display='flex';
}
function closeTemplateModal(){ document.getElementById('modal-template').style.display='none'; }

/* When selecting template in Scheduled modal */
function onTemplateSelectChange(){
  const sel = document.getElementById('s_template');
  if (!sel) return;
  if (sel.value === '__new__') {
    openTemplateModalCreate();
    sel.value = '';
  }
}

/* Build template form */
let tplItemIdx = 0;
function clearTplItems(){ document.getElementById('tpl-items').innerHTML=''; tplItemIdx=0; }
function addTplItemRow(data){
  data = data || {id:'', item_text:'', is_required:1};
  const wrap = document.getElementById('tpl-items');
  const row = document.createElement('div');
  row.className = 'tpl-row';
  row.dataset.index = tplItemIdx;

  row.innerHTML = `
    <input type="hidden" name="item_id[]" value="${data.id ? String(data.id).replace(/"/g,'&quot;') : ''}">
    <input type="hidden" name="item_del[]" value="0">
    <input class="input" name="item_text[]" placeholder="Checklist item" value="${(data.item_text||'').replace(/"/g,'&quot;')}">
    <label style="display:flex;align-items:center;gap:.35rem">
      <input type="checkbox" name="item_req[${tplItemIdx}]" ${data.is_required? 'checked':''}> required
    </label>
    <button class="btn" type="button" onclick="moveTplRow(this,-1)" title="Move up">↑</button>
    <button class="btn btn-danger" type="button" onclick="deleteTplRow(this)">Remove</button>
  `;
  wrap.appendChild(row);
  tplItemIdx++;
}
function deleteTplRow(btn){
  const row = btn.closest('.tpl-row');
  if(!row) return;
  const idInput = row.querySelector('input[name="item_id[]"]');
  const delInput = row.querySelector('input[name="item_del[]"]');
  if (idInput && idInput.value) { delInput.value = '1'; }
  row.remove();
}
function moveTplRow(btn, dir){
  const row = btn.closest('.tpl-row');
  if(!row) return;
  const wrap = row.parentElement;
  if(dir<0 && row.previousElementSibling){ wrap.insertBefore(row, row.previousElementSibling); }
  if(dir>0 && row.nextElementSibling){ wrap.insertBefore(row.nextElementSibling, row); }
}
function fillTemplateForm(t){
  document.getElementById('tpl_id').value = t.id || 0;
  document.getElementById('tpl_name').value = t.name || '';
  document.getElementById('tpl_desc').value = t.description || '';
  document.getElementById('tpl_active').checked = !!(t.is_active);
  clearTplItems();
  (t.items || []).forEach(it => addTplItemRow(it));
  if ((t.items || []).length === 0) addTplItemRow();
}

/* ===== Edit SIMPLE task ===== */
function openSimpleEdit(id){
  const t = (window.SIMPLE_TASKS || []).find(x=>x.id===id);
  if(!t) return;
  if(t.is_linked) {
      alert("This task is linked to a ticket and cannot be edited or completed directly from here.");
      return;
  }
  document.getElementById('simple_id').value = t.id;
  document.querySelector('#modal-simple input[name="title"]').value = t.title || '';
  document.querySelector('#modal-simple textarea[name="description"]').value = t.description || '';
  document.querySelector('#modal-simple input[name="due_date"]').value = t.due_date || '';
  const sel = document.querySelector('#modal-simple select[name="assignee"]');
  if (sel) sel.value = String(t.assignee || '');
  document.getElementById('btn-simple-create').style.display = 'none';
  document.getElementById('btn-simple-update').style.display = 'inline-block';
  const h = document.querySelector('#modal-simple .modal-h h3'); if (h) h.textContent = 'Edit Task';
  openModal('simple');
}

/* ===== Edit SCHEDULED task ===== */
function openScheduledEdit(id){
  const s = (window.SCHEDULED || []).find(x=>x.id===id);
  if(!s) return;

  document.querySelector('#modal-sched .modal-h h3').textContent = 'Edit Scheduled Task';
  document.getElementById('sched_id').value = s.id;
  document.querySelector('#modal-sched input[name="s_title"]').value = s.title || '';

  const selTpl = document.getElementById('s_template');
  if (selTpl && !Array.from(selTpl.options).some(o => o.value===String(s.template_id))) {
    const opt = document.createElement('option');
    opt.value = String(s.template_id);
    opt.textContent = '(inactive) #' + s.template_id;
    selTpl.appendChild(opt);
  }
  selTpl.value = String(s.template_id);

  document.getElementById('s_type').value = s.schedule_type || 'weekly';
  onTypeChange();

  const set = new Set((s.byday || '').split(',').filter(Boolean));
  document.querySelectorAll('#weekdays input[name="s_byday[]"]').forEach(cb=>{
    cb.checked = set.has(cb.value);
  });

  const domEl = document.querySelector('#dom input[name="s_dom"]');
  if (domEl) domEl.value = s.day_of_month || 1;
  document.querySelector('#modal-sched input[name="s_start"]').value = (s.start_date || '').substring(0,10);
  document.querySelector('#modal-sched input[name="s_active"]').checked = String(s.active)==='1';

  const assigned = new Set((s.assignees || []).map(String));
  document.querySelectorAll('#modal-sched input[name="s_assignees[]"]').forEach(cb=>{
    cb.checked = assigned.has(cb.value);
  });

  document.getElementById('btn-sched-create').style.display = 'none';
  document.getElementById('btn-sched-update').style.display = 'inline-block';

  openModal('sched');
}

/* Reset on "New Scheduled Task" */
(function(){
  const btns = Array.from(document.querySelectorAll('button[onclick="openModal(\'sched\')"]'));
  btns.forEach(btn => btn.addEventListener('click', function(){
    const h = document.querySelector('#modal-sched .modal-h h3');
    if (h) h.textContent = 'New Scheduled Task';
    document.getElementById('sched_id').value = '';
    document.querySelector('#modal-sched input[name="s_title"]').value = '';
    document.getElementById('s_template').value = '';
    document.getElementById('s_type').value = 'weekly';
    onTypeChange();
    document.querySelectorAll('#weekdays input[name="s_byday[]"]').forEach(cb=>cb.checked = (cb.value==='MO'));
    const domEl = document.querySelector('#dom input[name="s_dom"]'); if (domEl) domEl.value = 1;
    document.querySelector('#modal-sched input[name="s_start"]').value = (new Date()).toISOString().slice(0,10);
    document.querySelector('#modal-sched input[name="s_active"]').checked = true;
    document.querySelectorAll('#modal-sched input[name="s_assignees[]"]').forEach(cb=>cb.checked=false);
    document.getElementById('btn-sched-create').style.display = 'inline-block';
    document.getElementById('btn-sched-update').style.display = 'none';
  }));
})();

/* Reset on "New Task" */
(function(){
  const btns = Array.from(document.querySelectorAll('button[onclick="openModal(\'simple\')"]'));
  btns.forEach(btn => btn.addEventListener('click', function(){
    document.getElementById('simple_id').value = '';
    document.querySelector('#modal-simple input[name="title"]').value = '';
    document.querySelector('#modal-simple textarea[name="description"]').value = '';
    document.querySelector('#modal-simple input[name="due_date"]').value = '';
    const sel = document.querySelector('#modal-simple select[name="assignee"]');
    if (sel && sel.options.length) sel.selectedIndex = 0;
    document.getElementById('btn-simple-create').style.display = 'inline-block';
    document.getElementById('btn-simple-update').style.display = 'none';
    const h = document.querySelector('#modal-simple .modal-h h3'); if (h) h.textContent = 'New Task';
  }));
})();
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
