<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

if (!function_exists('e')) { function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$me = user();
$is_admin = is_admin();
$is_manager = is_manager();

/* ================= CSRF ================= */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
function csrf_input(){ echo '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">'; }
function csrf_check(){
  if (empty($_POST['csrf']) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    http_response_code(400); exit('Invalid CSRF');
  }
}

/* =============== Helpers & guards =============== */
function col_exists(PDO $pdo, string $table, string $col): bool {
  $s=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
  $s->execute([$table,$col]); return (bool)$s->fetchColumn();
}
function table_exists(PDO $pdo, string $table): bool {
  $s=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $s->execute([$table]); return (bool)$s->fetchColumn();
}
function enumify(string $s){ return strtoupper(trim($s)); }

/* =============== Migrations (idempotent, safe) =============== */
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
  dispatch_mode ENUM('PER_ASSIGNEE','PER_ITEM') NOT NULL DEFAULT 'PER_ASSIGNEE',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sched_tpl FOREIGN KEY (template_id) REFERENCES task_templates(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
if (!col_exists($pdo,'scheduled_tasks','dispatch_mode')) {
  try { $pdo->exec("ALTER TABLE scheduled_tasks ADD COLUMN dispatch_mode ENUM('PER_ASSIGNEE','PER_ITEM') NOT NULL DEFAULT 'PER_ASSIGNEE'"); } catch (Throwable $e) {}
}

$pdo->exec("
CREATE TABLE IF NOT EXISTS scheduled_task_assignees (
  task_id INT NOT NULL,
  user_id INT NOT NULL,
  PRIMARY KEY (task_id,user_id),
  CONSTRAINT fk_sta_task FOREIGN KEY (task_id) REFERENCES scheduled_tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS scheduled_task_item_assignees (
  task_id INT NOT NULL,
  item_id INT NOT NULL,
  user_id INT NOT NULL,
  PRIMARY KEY (task_id,item_id),
  CONSTRAINT fk_stia_task FOREIGN KEY (task_id) REFERENCES scheduled_tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_stia_item FOREIGN KEY (item_id) REFERENCES task_template_items(id) ON DELETE CASCADE
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
  item_id INT NULL,
  CONSTRAINT fk_strt_run FOREIGN KEY (run_id) REFERENCES scheduled_task_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

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
  INDEX(assignee), INDEX(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
if (!col_exists($pdo,'tasks','scheduled_task_run_ticket_id')) {
  try { $pdo->exec("ALTER TABLE tasks ADD COLUMN scheduled_task_run_ticket_id INT NULL"); } catch (Throwable $e) {}
}

/* =============== Ticket helper =============== */
function create_ticket_auto(PDO $pdo, array $payload): int {
  // Determine which columns exist
  $cols = [];
  foreach (['ticket_number','subject','requester_email','description','status','priority','created_by','created_at','agent_id'] as $c) {
    $s=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tickets' AND COLUMN_NAME=? LIMIT 1");
    $s->execute([$c]); if ($s->fetchColumn()) $cols[]=$c;
  }
  if (!in_array('subject',$cols,true)) throw new RuntimeException('tickets.subject missing');

  $params = []; $column_list = [];
  foreach ($cols as $c) {
    $column_list[] = $c;
    switch ($c) {
      case 'ticket_number':
        $n = (int)$pdo->query("SELECT IFNULL(MAX(id),0)+1 FROM tickets")->fetchColumn();
        $params[":$c"] = date('Ymd').'-'.str_pad((string)$n,4,'0',STR_PAD_LEFT);
        break;
      case 'status':
        $params[":$c"] = $payload['status'] ?? 'Open';
        break;
      case 'priority':
        $params[":$c"] = $payload['priority'] ?? 'Medium';
        break;
      case 'created_at':
        $params[":$c"] = $payload['created_at'] ?? date('Y-m-d H:i:s');
        break;
      default:
        $params[":$c"] = $payload[$c] ?? null;
    }
  }
  $sql = "INSERT INTO tickets (".implode(',',$column_list).") VALUES (".implode(',', array_keys($params)).")";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return (int)$pdo->lastInsertId();
}

/* =============== Scheduling math =============== */
function compute_next_run(array $t, ?DateTime $from=null): ?DateTime {
  $tz = new DateTimeZone($t['timezone'] ?? 'Africa/Johannesburg');
  $now = $from ?: new DateTime('now', $tz);
  $start = new DateTime((string)$t['start_date'], $tz);
  if ($now < $start) $now = clone $start;

  $type = $t['schedule_type'];
  if ($type === 'daily') {
    $d = clone $now; if ($d->format('H:i:s') > '00:00:01') $d->modify('+1 day');
    return $d->setTime(8,0,0);
  }
  if ($type === 'weekly' || $type === 'biweekly') {
    $by = array_filter(array_map('trim', explode(',', (string)($t['byday'] ?? ''))));
    if (!$by) $by = ['MO'];
    $map = ['Mon'=>'MO','Tue'=>'TU','Wed'=>'WE','Thu'=>'TH','Fri'=>'FR','Sat'=>'SA','Sun'=>'SU'];
    $d = clone $now; $d->setTime(8,0,0);
    $wkStart = (int)$start->format('W');
    for ($i=0; $i<90; $i++) {
      $iso = $map[$d->format('D')] ?? 'MO';
      $okWeek = ($type==='biweekly') ? ((((int)$d->format('W') - $wkStart) % 2) === 0) : true;
      if (in_array($iso,$by,true) && $okWeek && $d >= $start) return $d;
      $d->modify('+1 day');
    }
    return null;
  }
  if ($type === 'monthly' || $type === 'bimonthly') {
    $dom = max(1, min(31, (int)($t['day_of_month'] ?: 1)));
    $step = ($type==='bimonthly') ? 2 : 1;
    $base = clone $now; $base->setTime(8,0,0);
    for ($i=0; $i<24; $i++) {
      $cand = (clone $base)->modify('+'.($i*$step).' months');
      $cand->setDate((int)$cand->format('Y'), (int)$cand->format('m'), min($dom, (int)$cand->format('t')));
      if ($cand >= $start && $cand >= $now) return $cand;
    }
    return null;
  }
  return null;
}

/* =============== POST actions =============== */
if ($_SERVER['REQUEST_METHOD']==='POST') {

  /* Create scheduled */
  if (isset($_POST['sched_create'])) {
    csrf_check();
    $title = trim($_POST['s_title'] ?? '');
    $tpl_id = (int)($_POST['s_template'] ?? 0);
    $stype = $_POST['s_type'] ?? 'weekly';
    $dispatch = enumify($_POST['s_dispatch'] ?? 'PER_ASSIGNEE');
    $byday = '';
    if ($stype==='weekly' || $stype==='biweekly') {
      $by = $_POST['s_byday'] ?? [];
      $by = array_values(array_intersect($by, ['MO','TU','WE','TH','FR','SA','SU']));
      $byday = implode(',', $by ?: ['MO']);
    }
    $dom = null;
    if ($stype==='monthly' || $stype==='bimonthly') $dom = max(1, min(31, (int)($_POST['s_dom'] ?? 1)));
    $start = $_POST['s_start'] ?: date('Y-m-d');
    $active = isset($_POST['s_active']) ? 1 : 0;
    $assignees = array_map('intval', $_POST['s_assignees'] ?? []);

    $pdo->beginTransaction();
    $ins = $pdo->prepare("INSERT INTO scheduled_tasks (title,template_id,schedule_type,byday,day_of_month,start_date,timezone,active,created_by,dispatch_mode) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $ins->execute([$title,$tpl_id,$stype,$byday,$dom,$start,'Africa/Johannesburg',$active,$me['id'],$dispatch]);
    $task_id = (int)$pdo->lastInsertId();

    if ($dispatch === 'PER_ASSIGNEE') {
      if ($assignees) {
        $insA = $pdo->prepare("INSERT INTO scheduled_task_assignees (task_id,user_id) VALUES (?,?)");
        foreach ($assignees as $u) $insA->execute([$task_id,$u]);
      }
    } else { // PER_ITEM
      $pairs = $_POST['item_assignee'] ?? []; // item_id => user_id
      $insI = $pdo->prepare("INSERT INTO scheduled_task_item_assignees (task_id,item_id,user_id) VALUES (?,?,?)");
      foreach ($pairs as $item_id => $uid) {
        $iid = (int)$item_id; $uid = (int)$uid;
        if ($iid>0 && $uid>0) $insI->execute([$task_id,$iid,$uid]);
      }
    }

    $row = $pdo->query("SELECT * FROM scheduled_tasks WHERE id=".$task_id)->fetch(PDO::FETCH_ASSOC);
    $nxt = compute_next_run($row);
    $pdo->prepare("UPDATE scheduled_tasks SET next_run_at=? WHERE id=?")->execute([$nxt ? $nxt->format('Y-m-d H:i:s') : null, $task_id]);
    $pdo->commit();
    header('Location: tasks.php?msg=scheduled_created'); exit;
  }

  /* Update scheduled */
  if (isset($_POST['sched_update'])) {
    csrf_check();
    $task_id = (int)$_POST['sched_id'];
    $title = trim($_POST['s_title'] ?? '');
    $tpl_id = (int)($_POST['s_template'] ?? 0);
    $stype = $_POST['s_type'] ?? 'weekly';
    $dispatch = enumify($_POST['s_dispatch'] ?? 'PER_ASSIGNEE');
    $byday = '';
    if ($stype==='weekly' || $stype==='biweekly') {
      $by = $_POST['s_byday'] ?? [];
      $by = array_values(array_intersect($by, ['MO','TU','WE','TH','FR','SA','SU']));
      $byday = implode(',', $by ?: ['MO']);
    }
    $dom = null;
    if ($stype==='monthly' || $stype==='bimonthly') $dom = max(1, min(31, (int)($_POST['s_dom'] ?? 1)));
    $start = $_POST['s_start'] ?: date('Y-m-d');
    $active = isset($_POST['s_active']) ? 1 : 0;
    $assignees = array_map('intval', $_POST['s_assignees'] ?? []);

    $pdo->beginTransaction();
    $pdo->prepare("UPDATE scheduled_tasks SET title=?, template_id=?, schedule_type=?, byday=?, day_of_month=?, start_date=?, active=?, dispatch_mode=? WHERE id=?")
        ->execute([$title,$tpl_id,$stype,$byday,$dom,$start,$active,$dispatch,$task_id]);

    $pdo->prepare("DELETE FROM scheduled_task_assignees WHERE task_id=?")->execute([$task_id]);
    $pdo->prepare("DELETE FROM scheduled_task_item_assignees WHERE task_id=?")->execute([$task_id]);

    if ($dispatch === 'PER_ASSIGNEE') {
      if ($assignees) {
        $insA = $pdo->prepare("INSERT INTO scheduled_task_assignees (task_id,user_id) VALUES (?,?)");
        foreach ($assignees as $u) $insA->execute([$task_id,$u]);
      }
    } else {
      $pairs = $_POST['item_assignee'] ?? [];
      $insI = $pdo->prepare("INSERT INTO scheduled_task_item_assignees (task_id,item_id,user_id) VALUES (?,?,?)");
      foreach ($pairs as $item_id => $uid) {
        $iid = (int)$item_id; $uid = (int)$uid;
        if ($iid>0 && $uid>0) $insI->execute([$task_id,$iid,$uid]);
      }
    }

    $row = $pdo->query("SELECT * FROM scheduled_tasks WHERE id=".$task_id)->fetch(PDO::FETCH_ASSOC);
    $nxt = compute_next_run($row);
    $pdo->prepare("UPDATE scheduled_tasks SET next_run_at=? WHERE id=?")->execute([$nxt ? $nxt->format('Y-m-d H:i:s') : null, $task_id]);
    $pdo->commit();
    header('Location: tasks.php?msg=scheduled_updated'); exit;
  }

  /* Pause/Resume/Delete */
  if (isset($_POST['sched_pause'])) { csrf_check(); $id=(int)$_POST['id']; $pdo->prepare("UPDATE scheduled_tasks SET active=0 WHERE id=?")->execute([$id]); header('Location: tasks.php?msg=paused'); exit; }
  if (isset($_POST['sched_resume'])){ csrf_check(); $id=(int)$_POST['id']; $pdo->prepare("UPDATE scheduled_tasks SET active=1 WHERE id=?")->execute([$id]); header('Location: tasks.php?msg=active'); exit; }
  if (isset($_POST['sched_delete'])){ csrf_check(); $id=(int)$_POST['id']; $pdo->prepare("DELETE FROM scheduled_tasks WHERE id=?")->execute([$id]); header('Location: tasks.php?msg=deleted'); exit; }

  /* Run now (creates tickets) */
  if (isset($_POST['run_now'])) {
    csrf_check();
    $task_id = (int)$_POST['id'];
    try {
      $task = $pdo->prepare("SELECT * FROM scheduled_tasks WHERE id=?"); $task->execute([$task_id]);
      $task = $task->fetch(PDO::FETCH_ASSOC);
      if (!$task || (int)$task['active'] !== 1) { header('Location: tasks.php?msg=run_error'); exit; }

      $pdo->beginTransaction();
      $pdo->prepare("INSERT INTO scheduled_task_runs (task_id,run_date) VALUES (?, CURDATE())")->execute([$task['id']]);
      $run_id = (int)$pdo->lastInsertId();

      // Template & items
      $tpl = $pdo->prepare("SELECT * FROM task_templates WHERE id=?"); $tpl->execute([$task['template_id']]); $template=$tpl->fetch(PDO::FETCH_ASSOC);
      $items = $pdo->prepare("SELECT * FROM task_template_items WHERE template_id=? ORDER BY sort_order,id"); $items->execute([$task['template_id']]); $items=$items->fetchAll(PDO::FETCH_ASSOC);

      $subject = $task['title'].' — '.date('D d M');
      $body = '';
      if (!empty($template['description'])) $body .= $template['description']."\n\n";
      if ($items) { $body .= "Checklist:\n"; foreach ($items as $it) $body .= "- [ ] ".$it['item_text'].($it['is_required']?' (required)':'')."\n"; }

      // Dispatch logic
      $dispatch = enumify((string)$task['dispatch_mode']);
      if ($dispatch === 'PER_ITEM') {
        $st = $pdo->prepare("SELECT item_id,user_id FROM scheduled_task_item_assignees WHERE task_id=?");
        $st->execute([$task['id']]); $pair = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($items as $it) {
          $uid = (int)($pair[(int)$it['id']] ?? 0);
          if ($uid <= 0) continue;
          $tid = create_ticket_auto($pdo, [
            'subject'     => $subject.' • '.$it['item_text'],
            'description' => $body,
            'status'      => 'Open',
            'priority'    => 'Medium',
            'created_by'  => $task['created_by'] ?? null,
            'agent_id'    => $uid
          ]);
          $pdo->prepare("INSERT INTO scheduled_task_run_tickets (run_id,ticket_id,assignee_id,item_id) VALUES (?,?,?,?)")
              ->execute([$run_id,$tid,$uid,(int)$it['id']]);
        }
      } else {
        $ass = $pdo->prepare("SELECT user_id FROM scheduled_task_assignees WHERE task_id=?");
        $ass->execute([$task['id']]); $assignees=$ass->fetchAll(PDO::FETCH_COLUMN);
        foreach ($assignees as $uid) {
          $tid = create_ticket_auto($pdo, [
            'subject'     => $subject,
            'description' => $body,
            'status'      => 'Open',
            'priority'    => 'Medium',
            'created_by'  => $task['created_by'] ?? null,
            'agent_id'    => (int)$uid
          ]);
          $pdo->prepare("INSERT INTO scheduled_task_run_tickets (run_id,ticket_id,assignee_id) VALUES (?,?,?)")
              ->execute([$run_id,$tid,(int)$uid]);
        }
      }

      $nxt = compute_next_run($task, new DateTime('tomorrow', new DateTimeZone($task['timezone'] ?? 'Africa/Johannesburg')));
      $pdo->prepare("UPDATE scheduled_tasks SET next_run_at=? WHERE id=?")->execute([$nxt ? $nxt->format('Y-m-d H:i:s') : null, $task['id']]);
      $pdo->commit();
      header('Location: tasks.php?msg=run_created'); exit;

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      @file_put_contents('/tmp/scheduled_tasks_error.log', "[".date('c')."] RUN_NOW: ".$e->getMessage()."\n", FILE_APPEND);
      header('Location: tasks.php?msg=run_error'); exit;
    }
  }

  /* Close run */
  if (isset($_POST['close_run'])) {
    csrf_check();
    $run_id=(int)$_POST['run_id'];
    $sql = "
      SELECT COUNT(*) FROM scheduled_task_run_tickets rt
      JOIN tickets tk ON tk.id=rt.ticket_id
      WHERE rt.run_id=? AND COALESCE(tk.status,'') NOT IN ('Resolved','Closed')
    ";
    $st = $pdo->prepare($sql); $st->execute([$run_id]); $openLeft=(int)$st->fetchColumn();
    if ($openLeft===0) {
      $pdo->prepare("UPDATE scheduled_task_runs SET status='complete' WHERE id=?")->execute([$run_id]);
      header('Location: tasks.php?msg=run_closed'); exit;
    } else {
      header('Location: tasks.php?msg=run_incomplete'); exit;
    }
  }

  /* Simple task create/update/delete/complete */
  if (isset($_POST['create_simple'])) {
    csrf_check();
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $assignee = (int)($_POST['assignee'] ?? 0);
    $due_date = $_POST['due_date'] ?: null;
    $stmt = $pdo->prepare("INSERT INTO tasks (title,description,assignee,due_date,created_by) VALUES(?,?,?,?,?)");
    $stmt->execute([$title,$description,$assignee,$due_date,$me['id']]);
    header('Location: tasks.php?msg=created'); exit;
  }
  if (isset($_POST['update_simple'])) {
    csrf_check();
    $id = (int)($_POST['simple_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $assignee = (int)($_POST['assignee'] ?? 0);
    $due_date = $_POST['due_date'] ?: null;
    $st = $pdo->prepare("UPDATE tasks SET title=?, description=?, assignee=?, due_date=? WHERE id=?");
    $st->execute([$title,$description,$assignee,$due_date,$id]);
    header('Location: tasks.php?msg=task_updated'); exit;
  }
  if (isset($_POST['delete'])) {
    csrf_check();
    $id = (int)$_POST['id'];
    $pdo->prepare("DELETE FROM tasks WHERE id=?")->execute([$id]);
    header('Location: tasks.php?msg=deleted'); exit;
  }
  if (isset($_POST['complete'])) {
    csrf_check();
    $id = (int)$_POST['id'];
    $st = $pdo->prepare("SELECT scheduled_task_run_ticket_id FROM tasks WHERE id=?"); $st->execute([$id]);
    if ($st->fetchColumn() === null) {
      $pdo->prepare("UPDATE tasks SET status='completed' WHERE id=?")->execute([$id]);
      header('Location: tasks.php?msg=completed'); exit;
    } else {
      header('Location: tasks.php?msg=task_linked_to_ticket'); exit;
    }
  }
}

/* Auto-complete simple tasks when linked ticket is closed */
if (table_exists($pdo,'tasks') && table_exists($pdo,'scheduled_task_run_tickets') && table_exists($pdo,'tickets')) {
  try {
    $pdo->exec("
      UPDATE tasks t
      JOIN scheduled_task_run_tickets rtt ON rtt.id = t.scheduled_task_run_ticket_id
      JOIN tickets tk ON tk.id = rtt.ticket_id
      SET t.status='completed'
      WHERE t.status <> 'completed' AND COALESCE(tk.status,'') IN ('Resolved','Closed')
    ");
  } catch (Throwable $e) { /* ignore */ }
}

/* =============== Data for UI =============== */
$users = $pdo->query("SELECT id, first_name, last_name, email FROM users ORDER BY first_name,last_name")->fetchAll(PDO::FETCH_ASSOC);

$templates = $pdo->query("SELECT * FROM task_templates WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$allTpl = $pdo->query("SELECT * FROM task_templates ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$tplIds = array_column($allTpl,'id');
$itemsByTpl = [];
if ($tplIds) {
  $in = implode(',', array_fill(0,count($tplIds),'?'));
  $st = $pdo->prepare("SELECT * FROM task_template_items WHERE template_id IN ($in) ORDER BY sort_order,id");
  $st->execute($tplIds);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $it) $itemsByTpl[(int)$it['template_id']][] = $it;
}

$scheduled = $pdo->query("
  SELECT st.*,
         (SELECT COUNT(*) FROM scheduled_task_assignees a WHERE a.task_id=st.id) AS assignee_count
  FROM scheduled_tasks st
  ORDER BY st.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

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
           SUM(CASE WHEN COALESCE(tk.status,'') IN ('Resolved','Closed') THEN 1 ELSE 0 END) AS done,
           COUNT(*) AS total
    FROM scheduled_task_run_tickets rt
    JOIN tickets tk ON tk.id=rt.ticket_id
    WHERE rt.run_id IN ($in)
    GROUP BY rt.run_id
  ";
  $st = $pdo->prepare($sql); $st->execute($ids);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $runProgress[(int)$row['run_id']] = $row;
}

$st = $pdo->prepare("
  SELECT t.*, CONCAT_WS(' ',u.first_name,u.last_name) AS assignee_user, rtt.ticket_id
  FROM tasks t
  LEFT JOIN users u ON t.assignee=u.id
  LEFT JOIN scheduled_task_run_tickets rtt ON rtt.id = t.scheduled_task_run_ticket_id
  WHERE t.assignee = ? OR t.created_by = ?
  ORDER BY t.id DESC
");
$st->execute([$me['id'], $me['id']]);
$tasks = $st->fetchAll(PDO::FETCH_ASSOC);

/* JS payloads */
$simpleTasksForJs = [];
foreach ($tasks as $t) {
  $simpleTasksForJs[] = [
    'id' => (int)$t['id'],
    'title' => (string)$t['title'],
    'description' => (string)($t['description'] ?? ''),
    'assignee' => (int)($t['assignee'] ?? 0),
    'due_date' => (string)($t['due_date'] ?? ''),
    'is_linked' => (bool)$t['scheduled_task_run_ticket_id']
  ];
}
$scheduledForJs = $pdo->query("SELECT * FROM scheduled_tasks ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$assRows = $pdo->query("SELECT task_id,user_id FROM scheduled_task_assignees")->fetchAll(PDO::FETCH_ASSOC);
$assMap = [];
foreach ($assRows as $r) { $assMap[(int)$r['task_id']][] = (int)$r['user_id']; }
foreach ($scheduledForJs as &$s) { $s['assignees'] = $assMap[(int)$s['id']] ?? []; }
unset($s);

/* =============== Render =============== */
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
        <button class="btn" onclick="document.getElementById('templates').scrollIntoView({behavior:'smooth'})">Manage Templates</button>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-b" style="overflow:auto">
    <?php if(isset($_GET['msg'])): ?>
      <?php $m = $_GET['msg']; $text = [
        'created'=>'Task created','deleted'=>'Task deleted','completed'=>'Task completed',
        'task_linked_to_ticket'=>'Task is linked to a ticket and must be completed by resolving the ticket.',
        'run_created'=>'Scheduled tickets created.',
        'paused'=>'Scheduled task paused.','active'=>'Scheduled task resumed.',
        'scheduled_updated'=>'Scheduled task updated.','scheduled_created'=>'Scheduled task created.',
        'tpl_saved'=>'Template saved.','tpl_deleted'=>'Template deleted.',
        'run_closed'=>'Run marked complete.','run_incomplete'=>'Run has open tickets and cannot be closed.',
        'run_error'=>'Error running task.'
      ][$m] ?? e($m); ?>
      <div class="badge badge-success" style="display:block;margin-bottom:1rem;"><?= e($text) ?></div>
    <?php endif; ?>

    <?php if(!$scheduled): ?>
      <p>No scheduled tasks yet.</p>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>Title</th><th>Template</th><th>Frequency / Mode</th><th>Assignees</th><th>Next run</th><th>Status</th><th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($scheduled as $s): ?>
          <tr>
            <td><?= e($s['title']) ?></td>
            <td>#<?= (int)$s['template_id'] ?></td>
            <td>
              <span class="badge badge-primary"><?= e(ucfirst($s['schedule_type'])) ?></span>
              <span class="badge"><?= e($s['dispatch_mode']==='PER_ITEM'?'Per item':'Per assignee') ?></span>
            </td>
            <td><?= (int)$s['assignee_count'] ?></td>
            <td><?= e($s['next_run_at'] ?: '—') ?></td>
            <td><span class="badge <?= $s['active']?'badge-success':'badge-warning' ?>"><?= $s['active']?'Active':'Paused' ?></span></td>
            <td>
              <?php if ($is_admin || $is_manager): ?>
                <form method="post" style="display:inline"><?php csrf_input(); ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn btn-primary" name="run_now" value="1">Run now</button></form>
                <button class="btn" type="button" onclick="openScheduledEdit(<?= (int)$s['id'] ?>)">Edit</button>
                <?php if($s['active']): ?>
                  <form method="post" style="display:inline"><?php csrf_input(); ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn" name="sched_pause" value="1">Pause</button></form>
                <?php else: ?>
                  <form method="post" style="display:inline"><?php csrf_input(); ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn" name="sched_resume" value="1">Resume</button></form>
                <?php endif; ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Delete scheduled task?')"><?php csrf_input(); ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn btn-danger" name="sched_delete" value="1">Delete</button></form>
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
        $allDone = ((int)$pr['total']>0 && (int)$pr['done'] === (int)$pr['total']);
      ?>
        <tr>
          <td><?= e($r['title']) ?></td>
          <td><?= e($r['run_date']) ?></td>
          <td><span class="badge <?= $allDone?'badge-success':'badge-warning' ?>"><?= (int)$pr['done'] ?>/<?= (int)$pr['total'] ?></span></td>
          <td>
            <?php if ($is_admin || $is_manager): ?>
            <form method="post" style="display:inline"><?php csrf_input(); ?>
              <input type="hidden" name="run_id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-primary" name="close_run" value="1" <?= $allDone ? '' : 'disabled' ?>>Mark run complete</button>
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
    <?php if ($is_admin || $is_manager): ?><button class="btn" onclick="openModal('simple')">+ New Task</button><?php endif; ?>
  </div>
  <div class="card-b" style="overflow:auto">
    <?php if(!$tasks): ?>
      <p>No tasks assigned to you or created by you.</p>
    <?php else: ?>
      <table class="table">
        <thead><tr><th>Task</th><th>Assignee</th><th>Due</th><th>Status</th><th>Linked Ticket</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($tasks as $t): $isDone = (($t['status'] ?? '')==='completed'); ?>
          <tr>
            <td><?= e($t['title']) ?></td>
            <td><?= e($t['assignee_user'] ?? '-') ?></td>
            <td><?= e($t['due_date']) ?></td>
            <td><span class="badge <?= $isDone?'badge-success':'badge-warning' ?>"><?= e($t['status'] ?? 'open') ?></span></td>
            <td>
              <?php if (!empty($t['ticket_id'])): ?>
                <a href="tickets.php#t<?= (int)$t['ticket_id'] ?>">Ticket #<?= (int)$t['ticket_id'] ?></a>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td>
              <?php if (!$isDone): ?>
                <button class="btn" type="button" onclick="openSimpleEdit(<?= (int)$t['id'] ?>)">Edit</button>
                <?php if (empty($t['ticket_id'])): ?>
                  <form method="post" style="display:inline"><?php csrf_input(); ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn btn-primary" name="complete" value="1">Complete</button></form>
                <?php else: ?>
                  <span class="badge badge-warning">Close ticket to complete</span>
                <?php endif; ?>
              <?php endif; ?>
              <?php if ($is_admin || $is_manager): ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Delete this task?')"><?php csrf_input(); ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn btn-danger" name="delete" value="1">Delete</button></form>
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
    <div><button class="btn btn-primary" onclick="openTemplateModalCreate()">+ New Template</button></div>
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
              <form method="post" style="display:inline" onsubmit="return confirm('Delete template? This removes its items too.')"><?php csrf_input(); ?><input type="hidden" name="template_id" value="<?= (int)$tpl['id'] ?>"><button class="btn btn-danger" name="tpl_delete" value="1">Delete</button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- Modals -->
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
          <?php foreach ($templates as $t): ?>
            <option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option>
          <?php endforeach; ?>
          <?php if ($is_admin): ?><option value="__new__">+ New template…</option><?php endif; ?>
        </select>

        <div class="grid" style="grid-template-columns:1fr">
          <label style="font-weight:600">Dispatch mode</label>
          <select class="input" name="s_dispatch" id="s_dispatch" onchange="onDispatchChange()">
            <option value="PER_ASSIGNEE">One ticket per assignee</option>
            <option value="PER_ITEM">One ticket per item (choose user per item)</option>
          </select>
        </div>

        <div class="grid" style="grid-template-columns:1fr">
          <label style="font-weight:600">Assign to users (per assignee mode)</label>
          <div class="input" style="padding:.5rem">
            <div style="max-height:180px; overflow:auto">
              <?php foreach ($users as $u): ?>
                <label style="display:flex; align-items:center; gap:.5rem; margin:.25rem 0">
                  <input type="checkbox" name="s_assignees[]" value="<?= (int)$u['id'] ?>">
                  <span><?= e($u['first_name'].' '.$u['last_name'].' <'.$u['email'].'>') ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <div id="perItemBox" class="grid" style="grid-template-columns:1fr; display:none">
          <label style="font-weight:600; display:block">Per-item assignees</label>
          <div class="input" style="padding:.5rem">
            <div id="perItemRows" style="display:flex; flex-direction:column; gap:.5rem"></div>
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
            <?php foreach ($users as $u): ?>
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
function onTypeChange(){
  var t = document.getElementById('s_type').value;
  document.getElementById('weekdays').style.display = (t==='weekly'||t==='biweekly')?'block':'none';
  document.getElementById('dom').style.display = (t==='monthly'||t==='bimonthly')?'block':'none';
}
onTypeChange();

/* Template JSON */
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

function onTemplateSelectChange(){
  const sel = document.getElementById('s_template');
  if (!sel) return;
  if (sel.value === '__new__') { openTemplateModalCreate(); sel.value = ''; return; }
  // (re)build per-item assignee rows for selected template
  buildPerItemRows(parseInt(sel.value||'0',10), {});
}
function onDispatchChange(){
  const mode = document.getElementById('s_dispatch').value;
  document.getElementById('perItemBox').style.display = (mode === 'PER_ITEM') ? 'block' : 'none';
}

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

/* Per-item rows builder */
function buildPerItemRows(templateId, selectedMap){
  const area = document.getElementById('perItemRows');
  if (!area) return;
  area.innerHTML = '';
  const tpl = (window.TEMPLATES||[]).find(t=>t.id===templateId);
  if(!tpl){ area.innerHTML = '<em>Select a template first.</em>'; return; }
  (tpl.items||[]).forEach(it => {
    const div = document.createElement('div');
    div.innerHTML = `
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:.5rem; align-items:center">
        <div>${it.item_text}</div>
        <div>
          <select class="input" name="item_assignee[${it.id}]">
            <option value="">— choose user —</option>
            ${ (window.USERS_OPTIONS_HTML || '') }
          </select>
        </div>
      </div>
    `;
    area.appendChild(div);
    const sel = div.querySelector('select');
    if (sel && selectedMap && selectedMap[String(it.id)]) sel.value = String(selectedMap[String(it.id)]);
  });
}

/* USERS select options (for per-item builders) */
(function(){
  const opts = [];
  <?php foreach ($users as $u): ?>
    opts.push('<option value="<?= (int)$u['id'] ?>"><?= e($u['first_name'].' '.$u['last_name'].' <'.$u['email'].'>') ?></option>');
  <?php endforeach; ?>
  window.USERS_OPTIONS_HTML = opts.join('');
})();

/* Simple task editing */
function openSimpleEdit(id){
  const t = (window.SIMPLE_TASKS || []).find(x=>x.id===id);
  if(!t) return;
  if(t.is_linked) { alert("This task is linked to a ticket and cannot be edited or completed directly from here."); return; }
  document.getElementById('simple_id').value = t.id;
  document.querySelector('#modal-simple input[name="title"]').value = t.title || '';
  document.querySelector('#modal-simple textarea[name="description"]').value = t.description || '';
  document.querySelector('#modal-simple input[name="due_date"]').value = t.due_date || '';
  const sel = document.querySelector('#modal-simple select[name="assignee"]'); if (sel) sel.value = String(t.assignee || '');
  document.getElementById('btn-simple-create').style.display = 'none';
  document.getElementById('btn-simple-update').style.display = 'inline-block';
  document.querySelector('#modal-simple .modal-h h3').textContent = 'Edit Task';
  openModal('simple');
}

/* Scheduled task edit */
function openScheduledEdit(id){
  const s = (window.SCHEDULED || []).find(x=>x.id===id);
  if(!s) return;
  document.querySelector('#modal-sched .modal-h h3').textContent = 'Edit Scheduled Task';
  document.getElementById('sched_id').value = s.id;
  document.querySelector('#modal-sched input[name="s_title"]').value = s.title || '';
  const selTpl = document.getElementById('s_template');
  if (selTpl && !Array.from(selTpl.options).some(o => o.value===String(s.template_id))) {
    const opt = document.createElement('option'); opt.value = String(s.template_id); opt.textContent = '(inactive) #'+s.template_id; selTpl.appendChild(opt);
  }
  selTpl.value = String(s.template_id);
  document.getElementById('s_dispatch').value = s.dispatch_mode || 'PER_ASSIGNEE';
  onDispatchChange();
  if (s.dispatch_mode === 'PER_ITEM') {
    buildPerItemRows(parseInt(s.template_id,10), {});
  }
  document.getElementById('s_type').value = s.schedule_type || 'weekly';
  onTypeChange();
  const set = new Set((s.byday || '').split(',').filter(Boolean));
  document.querySelectorAll('#weekdays input[name="s_byday[]"]').forEach(cb=>{ cb.checked = set.has(cb.value); });
  const domEl = document.querySelector('#dom input[name="s_dom"]'); if (domEl) domEl.value = s.day_of_month || 1;
  document.querySelector('#modal-sched input[name="s_start"]').value = (s.start_date || '').substring(0,10);
  document.querySelector('#modal-sched input[name="s_active"]').checked = String(s.active)==='1';
  const assigned = new Set((s.assignees || []).map(String));
  document.querySelectorAll('#modal-sched input[name="s_assignees[]"]').forEach(cb=>{ cb.checked = assigned.has(cb.value); });

  document.getElementById('btn-sched-create').style.display = 'none';
  document.getElementById('btn-sched-update').style.display = 'inline-block';
  openModal('sched');
}

/* Reset "New Scheduled Task" */
(function(){
  const btns = Array.from(document.querySelectorAll('button[onclick="openModal(' + String.fromCharCode(39) + 'sched' + String.fromCharCode(39) + ')"]'));
  btns.forEach(btn => btn.addEventListener('click', function(){
    document.querySelector('#modal-sched .modal-h h3').textContent = 'New Scheduled Task';
    document.getElementById('sched_id').value = '';
    document.querySelector('#modal-sched input[name="s_title"]').value = '';
    document.getElementById('s_template').value = '';
    document.getElementById('s_dispatch').value = 'PER_ASSIGNEE';
    onDispatchChange();
    buildPerItemRows(0,{});
    document.getElementById('s_type').value = 'weekly'; onTypeChange();
    document.querySelectorAll('#weekdays input[name="s_byday[]"]').forEach(cb=>cb.checked = (cb.value==='MO'));
    const domEl = document.querySelector('#dom input[name="s_dom"]'); if (domEl) domEl.value = 1;
    document.querySelector('#modal-sched input[name="s_start"]').value = (new Date()).toISOString().slice(0,10);
    document.querySelector('#modal-sched input[name="s_active"]').checked = true;
    document.querySelectorAll('#modal-sched input[name="s_assignees[]"]').forEach(cb=>cb.checked=false);
    document.getElementById('btn-sched-create').style.display = 'inline-block';
    document.getElementById('btn-sched-update').style.display = 'none';
  }));
})();
</script>
<?php include __DIR__ . '/partials/footer.php'; ?>
