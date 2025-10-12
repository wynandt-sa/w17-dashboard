<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php'; // APP_BASE_URL, ZULIP_SITE, ZULIP_BOT_EMAIL, ZULIP_BOT_APIKEY
require_once __DIR__ . '/tickets_service.php'; // CRITICAL: Contains SLA, Housekeeping, and shared helpers
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

/* ---------------- Helpers & Schema ---------------- */
// col_exists is defined here; table_exists_robustly is defined in tickets_service.php
function col_exists(PDO $pdo, $table, $col){
  $s=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
  $s->execute([$table,$col]); return (bool)$s->fetchColumn();
}

function get_enum_values(PDO $pdo, string $table, string $column): array {
  $st = $pdo->prepare("SELECT COLUMN_TYPE, COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $st->execute([$table,$column]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  $values = []; $default = null;
  if ($row) {
    if (preg_match_all("/'([^']*)'/", (string)$row['COLUMN_TYPE'], $m)) $values = $m[1];
    $default = $row['COLUMN_DEFAULT'] ?? null;
  }
  return [$values, $default];
}
function next_ticket_number(PDO $pdo): string {
  $n = (int)$pdo->query("SELECT IFNULL(MAX(id),0)+1 FROM tickets")->fetchColumn();
  return date('Ymd') . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}
function user_name(PDO $pdo, ?int $id): ?string {
  if (!$id) return null;
  $s=$pdo->prepare("SELECT CONCAT_WS(' ',first_name,last_name) FROM users WHERE id=?");
  $s->execute([$id]); return $s->fetchColumn() ?: null;
}

/* Queues & schema safeguards */
$QUEUE_VALUES = ['Workshop17','HR','Finance'];
if (!col_exists($pdo,'tickets','agent_id'))    $pdo->exec("ALTER TABLE tickets ADD COLUMN agent_id INT NULL");
if (!col_exists($pdo,'tickets','location_id')) $pdo->exec("ALTER TABLE tickets ADD COLUMN location_id INT NULL");
if (!col_exists($pdo,'tickets','queue'))       $pdo->exec("ALTER TABLE tickets ADD COLUMN queue ENUM('Workshop17','HR','Finance') NULL DEFAULT 'Workshop17'");

/* Related To taxonomy (tiered) */
$pdo->exec("
CREATE TABLE IF NOT EXISTS related_to_options (
  id INT AUTO_INCREMENT PRIMARY KEY,
  parent_id INT NULL,
  label VARCHAR(120) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_rto_parent FOREIGN KEY (parent_id) REFERENCES related_to_options(id) ON DELETE CASCADE,
  INDEX(parent_id), INDEX(active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
if (!col_exists($pdo,'tickets','related_to_id')) {
  $pdo->exec("ALTER TABLE tickets ADD COLUMN related_to_id INT NULL, ADD CONSTRAINT fk_t_related FOREIGN KEY (related_to_id) REFERENCES related_to_options(id) ON DELETE SET NULL");
}

/* Pending-until workflow */
if (!col_exists($pdo,'tickets','pending_until')) {
  $pdo->exec("ALTER TABLE tickets ADD COLUMN pending_until DATETIME NULL");
}

/* SLA columns / Logs / Queue Access (Ensured by service and local logic) */
// This calls ensure_schema_for_housekeeping() from tickets_service.php
ensure_schema_for_housekeeping($pdo); 

/* Multi-queue access (team visibility) */
$pdo->exec("
CREATE TABLE IF NOT EXISTS user_queue_access (
  user_id INT NOT NULL,
  queue ENUM('Workshop17','HR','Finance') NOT NULL,
  PRIMARY KEY (user_id, queue),
  CONSTRAINT fk_uqa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* Comments & attachments (thread) */
$pdo->exec("
CREATE TABLE IF NOT EXISTS ticket_comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT NOT NULL,
  user_id INT NOT NULL,
  body TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tc_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  INDEX (ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
$pdo->exec("
CREATE TABLE IF NOT EXISTS ticket_attachments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT NOT NULL,
  comment_id INT NULL,
  path VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime VARCHAR(100) DEFAULT NULL,
  size INT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (ticket_id), INDEX (comment_id),
  CONSTRAINT fk_ta_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_ta_comment FOREIGN KEY (comment_id) REFERENCES ticket_comments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* Saved default view */
$pdo->exec("
CREATE TABLE IF NOT EXISTS user_ticket_default_view (
  user_id INT PRIMARY KEY,
  filters JSON NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_utdv FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* --- Task Synchronization Column Schema Check --- */
// CRITICAL: Ensure this column exists for task status updates.
if (!col_exists($pdo, 'tasks', 'scheduled_task_run_ticket_id')) {
    try {
        $pdo->exec("ALTER TABLE tasks ADD COLUMN scheduled_task_run_ticket_id INT NULL");
    } catch (Throwable $e) { /* silent fail if table doesn't exist, which is fine for now */ }
}
/* ------------------------------------------------ */


/* Enums (safe fallbacks) */
[$PRIORITY_VALUES, $PRIORITY_DEFAULT] = get_enum_values($pdo,'tickets','priority');
if (!$PRIORITY_VALUES) { $PRIORITY_VALUES=['Low','Medium','High','Critical']; $PRIORITY_DEFAULT='Medium'; }
[$STATUS_VALUES, $STATUS_DEFAULT] = get_enum_values($pdo,'tickets','status');
if (!$STATUS_VALUES) { $STATUS_VALUES=['New','Open','Pending','Resolved','Closed']; $STATUS_DEFAULT='New'; }

/* Uploads (with file info hardening) */
function handle_uploads(string $field, int $ticket_id, ?int $comment_id = null): array {
  if (empty($_FILES[$field]) || !is_array($_FILES[$field]['name'])) return [];
  $saved = [];
  $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
  $dir = __DIR__ . '/uploads/tickets';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);

  $names = $_FILES[$field]['name'];
  $tmps  = $_FILES[$field]['tmp_name'];
  $types = $_FILES[$field]['type'];
  $sizes = $_FILES[$field]['size'];
  $errs  = $_FILES[$field]['error'];

  $allowed = ['pdf','png','jpg','jpeg','webp','gif','txt','doc','docx','xls','xlsx','ppt','pptx','csv'];
  $allowedMimes = ['application/pdf','image/png','image/jpeg','image/webp','image/gif','text/plain','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/vnd.ms-powerpoint','application/vnd.openxmlformats-officedocument.presentationml.presentation','text/csv']; // Stronger content check

  for ($i=0; $i<count($names); $i++){
    if ($errs[$i] !== UPLOAD_ERR_OK) continue;
    $orig = (string)$names[$i];
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));

    if ($ext==='php' || !in_array($ext,$allowed,true)) continue;

    $mime = $types[$i] ?? null;
    if ($finfo) { // Use finfo for robust type check
      $mime = finfo_file($finfo, $tmps[$i]);
      if (!in_array($mime, $allowedMimes, true)) continue;
    }
    
    $new = uniqid('att_', true) . ($ext?'.'.$ext:'');
    $dest = $dir.'/'.$new;
    if (@move_uploaded_file($tmps[$i], $dest)) {
      $saved[] = [
        'path' => 'uploads/tickets/'.$new,
        'original' => $orig,
        'mime' => $mime,
        'size' => (int)$sizes[$i],
        'ticket_id'=>$ticket_id,
        'comment_id'=>$comment_id
      ];
    }
  }
  if ($finfo) finfo_close($finfo);
  return $saved;
}

/* ---------- Related To helpers ---------- */
function related_path(PDO $pdo, int $id): string {
  static $cache = [];
  if (isset($cache[$id])) return $cache[$id];
  $path = [];
  $cur = $id;
  while ($cur) {
    $s = $pdo->prepare("SELECT id,parent_id,label FROM related_to_options WHERE id=?");
    $s->execute([$cur]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) break;
    array_unshift($path, $row['label']);
    $cur = $row['parent_id'] ? (int)$row['parent_id'] : null;
  }
  return $cache[$id] = implode(' :: ', $path);
}
function related_options_kv(PDO $pdo): array {
  $rows = $pdo->query("SELECT id FROM related_to_options WHERE active=1 ORDER BY parent_id IS NULL DESC, parent_id, label")->fetchAll(PDO::FETCH_COLUMN);
  $out = [];
  foreach ($rows as $id) $out[(int)$id] = related_path($pdo, (int)$id);
  asort($out, SORT_NATURAL | SORT_FLAG_CASE);
  return $out;
}

/* ---------- Zulip helper ---------- */
function zulip_send_pm(string $toEmail, string $content): bool {
  if (!defined('ZULIP_SITE') || !defined('ZULIP_BOT_EMAIL') || !defined('ZULIP_BOT_APIKEY') || !ZULIP_SITE || !ZULIP_BOT_EMAIL || !ZULIP_BOT_APIKEY) return false;
  $payload = [
    'type'    => 'private',
    'to'      => json_encode([$toEmail]),
    'content' => $content,
  ];
  $ch = curl_init(rtrim(ZULIP_SITE,'/').'/api/v1/messages');
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD        => ZULIP_BOT_EMAIL.':'.ZULIP_BOT_APIKEY,
    CURLOPT_TIMEOUT        => 7,
  ]);
  @curl_exec($ch);
  $http = @curl_getinfo($ch, CURLINFO_HTTP_CODE);
  @curl_close($ch);
  return ($http >= 200 && $http < 300);
}

/* ---------------- Run housekeeping now ---------------- */
// This function is defined in tickets_service.php
run_ticket_housekeeping($pdo); 

/* ---------------- Queue access for current user ---------------- */
$uq = $pdo->prepare("SELECT queue FROM user_queue_access WHERE user_id=?");
$uq->execute([$me['id']]);
$myQueues = $uq->fetchAll(PDO::FETCH_COLUMN);
$queueOptions = $is_admin ? $QUEUE_VALUES : $myQueues;

/* ---------------- POST: create/update/delete/comment + save/clear default view + related_to management ---------------- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (isset($_POST['save_default'])) {
    csrf_check();
    $filters = [
      'no'          => ($_POST['f_no'] ?? '') ?: null,
      'status'      => ($_POST['f_status'] ?? '') ?: null,
      'agent_id'    => (isset($_POST['f_agent_id']) && $_POST['f_agent_id']!=='') ? (int)$_POST['f_agent_id'] : null,
      'queue'       => ($_POST['f_queue'] ?? '') ?: null,
      'location_id' => (int)($_POST['f_location_id'] ?? 0) ?: null,
    ];
    $stmt = $pdo->prepare("INSERT INTO user_ticket_default_view (user_id,filters) VALUES (?,?) 
                           ON DUPLICATE KEY UPDATE filters=VALUES(filters), updated_at=CURRENT_TIMESTAMP");
    $stmt->execute([$me['id'], json_encode($filters, JSON_UNESCAPED_UNICODE)]);
    header('Location: tickets.php?saved=1&'.http_build_query(array_filter([
      'no'=>$filters['no'],'status'=>$filters['status'],'agent_id'=>$filters['agent_id'],'queue'=>$filters['queue'],'location_id'=>$filters['location_id']
    ], fn($v)=>$v!==null && $v!==''))); exit;
  }
  if (isset($_POST['clear_default'])) {
    csrf_check();
    $pdo->prepare("DELETE FROM user_ticket_default_view WHERE user_id=?")->execute([$me['id']]);
    header('Location: tickets.php?cleared=1'); exit;
  }

  /* Admin: manage Related To options */
  if ($is_admin && isset($_POST['add_related'])) {
    csrf_check();
    $label = trim($_POST['rt_label'] ?? '');
    $parent = (int)($_POST['rt_parent_id'] ?? 0) ?: null;
    if ($label !== '') {
      $st = $pdo->prepare("INSERT INTO related_to_options (parent_id,label,active) VALUES (?,?,1)");
      $st->execute([$parent,$label]);
    }
    header('Location: tickets.php#manage-related'); exit;
  }
  if ($is_admin && isset($_POST['delete_related'])) {
    csrf_check();
    $rid = (int)($_POST['rid'] ?? 0);
    if ($rid>0) {
      $inUse   = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE related_to_id={$rid}")->fetchColumn();
      $hasChild= (int)$pdo->query("SELECT COUNT(*) FROM related_to_options WHERE parent_id={$rid}")->fetchColumn();
      if (!$inUse && !$hasChild) $pdo->prepare("DELETE FROM related_to_options WHERE id=?")->execute([$rid]);
    }
    header('Location: tickets.php#manage-related'); exit;
  }

  /* Create */
  if (isset($_POST['create'])) {
    csrf_check();
    $subject         = trim($_POST['subject'] ?? '');
    $requester_email = trim($_POST['requester_email'] ?? '');
    $priority        = $_POST['priority'] ?? $PRIORITY_DEFAULT;
    $status          = $_POST['status'] ?? $STATUS_DEFAULT;
    $description     = trim($_POST['description'] ?? '');
    $agent_id        = (int)($_POST['agent_id'] ?? 0) ?: null;
    $location_id     = (int)($_POST['location_id'] ?? 0) ?: null;
    $queue           = $_POST['queue'] ?? null;
    $related_to_id   = (int)($_POST['related_to_id'] ?? 0) ?: null;
    $pending_until   = null;

    if ($status === 'Pending') {
      $pending_until = trim($_POST['pending_until'] ?? '') ?: null;
      if ($pending_until) {
        $ts = strtotime($pending_until);
        $pending_until = $ts ? date('Y-m-d H:i:s', $ts) : null;
      }
    }
    $created_by      = $me['id'];

    if (!in_array($priority,$PRIORITY_VALUES,true)) $priority=$PRIORITY_DEFAULT;
    if (!in_array($status,$STATUS_VALUES,true))     $status=$STATUS_DEFAULT;

    if ($is_admin) {
      if (!in_array($queue,$QUEUE_VALUES,true)) $queue='Workshop17';
    } else {
      if (!$myQueues) { header('Location: tickets.php?msg=invalid'); exit; }
      if (!in_array($queue,$myQueues,true)) $queue = $myQueues[0];
    }

    if ($subject==='' || $requester_email==='' || $description==='') { header('Location: tickets.php?msg=invalid'); exit; }

    // If created with an assignee, force Open and start SLA
    if ($agent_id && $status !== 'Open') { $status = 'Open'; }
    $sla_started_at = ($status === 'Open') ? date('Y-m-d H:i:s') : null;

    $ins = $pdo->prepare("INSERT INTO tickets (ticket_number,subject,requester_email,priority,status,description,created_by,agent_id,location_id,queue,related_to_id,pending_until,sla_started_at)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
    for ($i=0; $i<3; $i++) {
      try {
        $tn = next_ticket_number($pdo);
        $ins->execute([$tn,$subject,$requester_email,$priority,$status,$description,$created_by,$agent_id,$location_id,$queue,$related_to_id,$pending_until,$sla_started_at]);
        $tid = (int)$pdo->lastInsertId();

        // attachments on creation
        $files = handle_uploads('attachments', $tid, null);
        if ($files) {
          $ia = $pdo->prepare("INSERT INTO ticket_attachments (ticket_id,comment_id,path,original_name,mime,size) VALUES (?,?,?,?,?,?)");
          foreach ($files as $a) $ia->execute([$tid,null,$a['path'],$a['original'],$a['mime'],$a['size']]);
          add_log($pdo,$tid,$me['id'],'attachment',['count'=>count($files),'at_create'=>true]);
        }

        // log creation + initial assignment (if any)
        add_log($pdo,$tid,$me['id'],'created',[
          'subject'=>$subject,'queue'=>$queue,
          'agent_id'=>$agent_id,'agent_name'=>user_name($pdo,$agent_id),
          'requester_email'=>$requester_email,'priority'=>$priority,'status'=>$status,
          'related_to_id'=>$related_to_id,'related_to'=> $related_to_id? related_path($pdo,$related_to_id):null,
          'pending_until'=>$pending_until
        ]);
        if ($agent_id) {
          add_log($pdo,$tid,$me['id'],'assign',[
            'from_id'=>null,'from_name'=>null,'to_id'=>$agent_id,'to_name'=>user_name($pdo,$agent_id)
          ]);
          // notify assigned agent on Zulip
          $agentEmail = $pdo->prepare("SELECT email FROM users WHERE id=?");
          $agentEmail->execute([$agent_id]);
          if ($email = $agentEmail->fetchColumn()) {
            $link = (defined('APP_BASE_URL')? rtrim(APP_BASE_URL,'/') : '').'/tickets.php#t'.$tid;
            $msg  = "You’ve been assigned ticket **{$tn}** — *".($subject ?: 'Ticket')."*.\n".
                    "Queue: `{$queue}`  |  Priority: `{$priority}`  |  Status: `{$status}`\n".
                    ($link ? "[Open in Dashboard Ticketing]($link)" : "");
            @zulip_send_pm($email, $msg);
          }
        }

        header('Location: tickets.php?msg=created'); exit;
      } catch (Throwable $e) { if (stripos($e->getMessage(),'duplicate')===false) throw $e; }
    }
    header('Location: tickets.php?msg=error'); exit;
  }

  /* Update */
  if (isset($_POST['update'])) {
    csrf_check();
    $id              = (int)($_POST['id'] ?? 0);
    $priority        = $_POST['priority'] ?? $PRIORITY_DEFAULT;
    $status          = $_POST['status'] ?? $STATUS_DEFAULT;
    $agent_id        = (int)($_POST['agent_id'] ?? 0) ?: null;
    $queue           = $_POST['queue'] ?? null;
    $related_to_id   = (int)($_POST['related_to_id'] ?? 0) ?: null;
    $pending_until   = null;

    if ($status === 'Pending') {
      $pending_until = trim($_POST['pending_until'] ?? '') ?: null;
      if ($pending_until) {
        $ts = strtotime($pending_until);
        $pending_until = $ts ? date('Y-m-d H:i:s', $ts) : null;
      }
    }

    if (!in_array($priority,$PRIORITY_VALUES,true)) $priority=$PRIORITY_DEFAULT;
    if (!in_array($status,$STATUS_VALUES,true))     $status=$STATUS_DEFAULT;

    // Load current values for diff (+ SLA fields)
    $old = $pdo->prepare("SELECT agent_id, status, priority, queue, requester_email, related_to_id, pending_until, sla_started_at, sla_paused_started_at, sla_paused_seconds FROM tickets WHERE id=?");
    $old->execute([$id]);
    $before = $old->fetch(PDO::FETCH_ASSOC);

    if ($queue !== null) {
      if ($is_admin) {
        if (!in_array($queue,$QUEUE_VALUES,true)) $queue = $before['queue'] ?? null;
      } else {
        if (!in_array($queue,$myQueues ?? [], true)) $queue = $before['queue'] ?? null;
      }
    }

    if ($id<=0) { header('Location: tickets.php?msg=invalid'); exit; }

    /* SLA side-effects */
    $now = date('Y-m-d H:i:s');
    $wasStatus = (string)($before['status'] ?? '');
    // If assigning while New -> flip to Open and start SLA
    if (empty($before['agent_id']) && !empty($agent_id) && $wasStatus === 'New') {
      $status = 'Open';
    }
    $sla_sets=[]; $sla_args=[];
    if ($status === 'Pending' && $wasStatus !== 'Pending') {
      $sla_sets[] = "sla_paused_started_at=?"; $sla_args[]=$now;
    }
    if ($wasStatus === 'Pending' && $status === 'Open') {
      $addPaused = 0;
      if (!empty($before['sla_paused_started_at'])) {
        $addPaused = business_seconds_between($before['sla_paused_started_at'], $now);
      }
      $sla_sets[] = "sla_paused_seconds = IFNULL(sla_paused_seconds,0)+?"; $sla_args[] = (int)$addPaused;
      $sla_sets[] = "sla_paused_started_at=NULL";
    }
    if ($status === 'Open' && empty($before['sla_started_at'])) {
      $sla_sets[] = "sla_started_at=?"; $sla_args[]=$now;
    }

    $sql="UPDATE tickets SET priority=?, status=?, agent_id=?, related_to_id=?, pending_until=?";
    $args=[$priority,$status,$agent_id,$related_to_id,$status==='Pending'?$pending_until:null];
    if ($queue!==null){ $sql.=", queue=?"; $args[]=$queue; }
    if ($sla_sets){ $sql.=", ".implode(", ", $sla_sets); $args=array_merge($args,$sla_args); }
    $sql.=" WHERE id=?"; $args[]=$id;
    $pdo->prepare($sql)->execute($args);

    /* --- Task-to-Ticket Synchronization FIX (Final Stable Logic) --- */
    // Only attempt synchronization if the necessary tables exist.
    if (table_exists_robustly($pdo, 'tasks') && table_exists_robustly($pdo, 'scheduled_task_run_tickets')) {
        if (($status === 'Resolved' || $status === 'Closed') && $wasStatus !== 'Resolved' && $wasStatus !== 'Closed') {
            // 1. Find the RTT IDs linked to this ticket
            $st_rtt_ids = $pdo->prepare("
                SELECT rtt.id
                FROM scheduled_task_run_tickets rtt
                WHERE rtt.ticket_id = ?
            ");
            $st_rtt_ids->execute([$id]);
            $rtt_ids = $st_rtt_ids->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($rtt_ids)) {
                // 2. UPDATE the tasks table using the collected IDs
                $in_placeholders = implode(',', array_fill(0, count($rtt_ids), '?'));
                
                $st_task_sync = $pdo->prepare("
                    UPDATE tasks 
                    SET status = 'completed'
                    WHERE scheduled_task_run_ticket_id IN ({$in_placeholders})
                    AND status <> 'completed'
                ");
                $st_task_sync->execute($rtt_ids);
            }
        }
    }
    /* -------------------------------------- */


    // Logs + notices
    if ((string)$before['agent_id'] !== (string)$agent_id) {
      add_log($pdo,$id,$me['id'],'assign',[
        'from_id'=>$before['agent_id'],'from_name'=>user_name($pdo,(int)$before['agent_id']),
        'to_id'=>$agent_id,'to_name'=>user_name($pdo,$agent_id)
      ]);
      if ($agent_id) {
        $agentEmail = $pdo->prepare("SELECT email FROM users WHERE id=?");
        $agentEmail->execute([$agent_id]);
        if ($email = $agentEmail->fetchColumn()) {
          $tnSt = $pdo->prepare("SELECT ticket_number,subject,queue,priority,status FROM tickets WHERE id=?");
          $tnSt->execute([$id]);
          $tmeta = $tnSt->fetch(PDO::FETCH_ASSOC) ?: [];
          $link  = (defined('APP_BASE_URL')? rtrim(APP_BASE_URL,'/') : '').'/tickets.php#t'.$id;
          $msg   = "You’ve been assigned ticket **{$tmeta['ticket_number']}** — *".($tmeta['subject'] ?? 'Ticket')."*.\n".
                   "Queue: `".($tmeta['queue'] ?? '-')."`  |  Priority: `".($tmeta['priority'] ?? '-')."`  |  Status: `".($tmeta['status'] ?? '-')."`\n".
                   ($link ? "[Open in Dashboard Ticketing]($link)" : "");
          @zulip_send_pm($email, $msg);
        }
      }
    }
    if ((string)$before['status'] !== (string)$status) {
      add_log($pdo,$id,$me['id'],'status',['from'=>$before['status'],'to'=>$status,'pending_until'=>($status==='Pending'?$pending_until:null)]);
      
      // Notify requester on status change
      $reqEmail = $before['requester_email'] ?? null;
      if ($reqEmail) {
          $tnSt = $pdo->prepare("SELECT ticket_number,subject FROM tickets WHERE id=?");
          $tnSt->execute([$id]);
          $tmeta = $tnSt->fetch(PDO::FETCH_ASSOC) ?: [];
          $msg = "Ticket **{$tmeta['ticket_number']}** — *".($tmeta['subject'] ?? 'Ticket')."* status updated to `{$status}`.\n".
                 "You requested this ticket.";
          @zulip_send_pm($reqEmail, $msg);
      }
    }
    if ($queue!==null && (string)$before['queue'] !== (string)$queue) {
      add_log($pdo,$id,$me['id'],'queue',['from'=>$before['queue'],'to'=>$queue]);
    }
    if ((string)($before['related_to_id'] ?? '') !== (string)($related_to_id ?? '')) {
      add_log($pdo,$id,$me['id'],'other',['field'=>'related_to','from_id'=>$before['related_to_id'],'to_id'=>$related_to_id,'to'=>($related_to_id?related_path($pdo,$related_to_id):null)]);
    }

    header('Location: tickets.php?msg=updated#t'.$id); exit;
  }

  /* Delete (admin) */
  if ($is_admin && isset($_POST['delete'])) {
    csrf_check();
    $id=(int)($_POST['id']??0);
    $pdo->prepare("DELETE FROM tickets WHERE id=?")->execute([$id]);
    header('Location: tickets.php?msg=deleted'); exit;
  }

  /* Comment (reopen if closed) */
  if (isset($_POST['add_comment'])) {
    csrf_check();
    $ticket_id = (int)($_POST['ticket_id'] ?? 0);
    $body = trim($_POST['comment_body'] ?? '');
    if ($ticket_id>0 && $body!=='') {
      $pdo->prepare("INSERT INTO ticket_comments (ticket_id,user_id,body) VALUES (?,?,?)")->execute([$ticket_id,$me['id'],$body]);
      $cid = (int)$pdo->lastInsertId();
      $files = handle_uploads('comment_attachments',$ticket_id,$cid);
      if ($files) {
        $ia = $pdo->prepare("INSERT INTO ticket_attachments (ticket_id,comment_id,path,original_name,mime,size) VALUES (?,?,?,?,?,?)");
        foreach ($files as $a) $ia->execute([$ticket_id,$cid,$a['path'],$a['original'],$a['mime'],$a['size']]);
        add_log($pdo,$ticket_id,$me['id'],'attachment',['count'=>count($files),'at_comment'=>true]);
      }
      add_log($pdo,$ticket_id,$me['id'],'comment',['length'=>mb_strlen($body)]);

      // Re-open if closed
      $cur = $pdo->prepare("SELECT status, agent_id, ticket_number, subject, queue, priority FROM tickets WHERE id=?");
      $cur->execute([$ticket_id]);
      $row = $cur->fetch(PDO::FETCH_ASSOC);
      if ($row && (string)$row['status'] === 'Closed') {
        $pdo->prepare("UPDATE tickets SET status='Open', pending_until=NULL WHERE id=?")->execute([$ticket_id]);
        add_log($pdo,$ticket_id,$me['id'],'status',['from'=>'Closed','to'=>'Open','reason'=>'comment_on_closed']);
        if (!empty($row['agent_id'])) {
          $getEmail = $pdo->prepare("SELECT email FROM users WHERE id=?");
          $getEmail->execute([(int)$row['agent_id']]);
          if ($email = $getEmail->fetchColumn()) {
            $link = (defined('APP_BASE_URL')? rtrim(APP_BASE_URL,'/') : '').'/tickets.php#t'.$ticket_id;
            $msg  = "Ticket **{$row['ticket_number']}** — *".($row['subject'] ?? 'Ticket')."* has **re-opened** (new comment on closed ticket).\n".
                    "Queue: `".($row['queue'] ?? '-')."`  |  Priority: `".($row['priority'] ?? '-')."`\n".
                    ($link ? "[Open in Dashboard Ticketing]($link)" : "");
            @zulip_send_pm($email, $msg);
          }
        }
      }
    }
    header('Location: tickets.php?msg=comment_added#t'.$ticket_id); exit;
  }
}

/* ---------------- Data for filters & lists ---------------- */
$users = $pdo->query("SELECT id, CONCAT_WS(' ',first_name,last_name) AS name FROM users ORDER BY first_name,last_name")->fetchAll(PDO::FETCH_KEY_PAIR);
$locations = $pdo->query("SELECT id, name FROM locations ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
$relatedKV = related_options_kv($pdo);

/* Filters: explicit GET first; if none, load saved default */
$hasExplicit = isset($_GET['no']) || isset($_GET['status']) || isset($_GET['agent_id']) || isset($_GET['queue']) || isset($_GET['location_id']) || isset($_GET['view']);
$filters = [
  'no'          => null,
  'status'      => null,
  'agent_id'    => null,
  'queue'       => null,
  'location_id' => null,
];
if ($hasExplicit) {
  $filters['no']          = ($_GET['no'] ?? '') ?: null;
  $filters['status']      = ($_GET['status'] ?? '') ?: null;
  $filters['agent_id']    = (isset($_GET['agent_id']) && $_GET['agent_id']!=='') ? (int)$_GET['agent_id'] : null;
  $filters['queue']       = ($_GET['queue'] ?? '') ?: null;
  $filters['location_id'] = (int)($_GET['location_id'] ?? 0) ?: null;
} else {
  $def = $pdo->prepare("SELECT filters FROM user_ticket_default_view WHERE user_id=?");
  $def->execute([$me['id']]);
  $j = $def->fetchColumn();
  if ($j) {
    $f = json_decode($j, true) ?: [];
    $filters = array_merge($filters, array_intersect_key($f, $filters));
  }
}
$usingDefault = !$hasExplicit && array_filter($filters, fn($v)=>$v!==null && $v!=='');

/* ---- Quick views (mine / team / unassigned) ---- */
$view = $_GET['view'] ?? null;          // 'mine' | 'team' | 'unassigned' | null
$teamAgentIds = [];
if ($view === 'team') {
  $teamAgentIds = [$me['id']];
  $mgr = null;
  if (col_exists($pdo,'users','manager_id')) {
    $mgr = (int)($me['manager_id'] ?? 0);
  }
  if ($mgr) {
    $stPeers = $pdo->prepare("SELECT id FROM users WHERE manager_id=?");
    $stPeers->execute([$mgr]);
    $peerIds = array_map('intval', $stPeers->fetchAll(PDO::FETCH_COLUMN));
    if ($peerIds) $teamAgentIds = array_values(array_unique(array_merge($teamAgentIds, $peerIds)));
  }
}

/* ---------------- Visibility + Filters (STRICT QUEUE-ONLY for non-admin) ---------------- */
$params = [];
$where  = [];
if (!$is_admin) {
  if (!$myQueues) {
    $where[] = "1=0";
  } else {
    $in = implode(',', array_fill(0,count($myQueues),'?'));
    $where[] = "t.queue IN ($in)";
    $params = array_merge($params, array_values($myQueues));
  }
}
if ($filters['no'])          { $where[] = "t.ticket_number LIKE ?"; $params[] = '%'.$filters['no'].'%'; }
if ($filters['status'])      { $where[] = "t.status=?";            $params[] = $filters['status']; }
if ($filters['queue'])       { $where[] = "t.queue=?";             $params[] = $filters['queue']; }
if ($filters['location_id']) { $where[] = "t.location_id=?";       $params[] = (int)$filters['location_id']; }

// Handle Agent filter, including -1 for Unassigned
if ($filters['agent_id'] === -1) {
    $where[] = "t.agent_id IS NULL";
} elseif ($filters['agent_id'] > 0) {
    $where[] = "t.agent_id=?";          $params[] = (int)$filters['agent_id'];
} elseif ($filters['agent_id'] === null || $filters['agent_id'] === 0) {
    // 0 or null: Any agent (handled by absence of filter)
}


/* Quick views constrain agent scope AND only Open tickets */
if ($view === 'mine') {
  $where[] = "t.status='Open'";
  $where[] = "t.agent_id=?";
  $params[] = (int)$me['id'];
} elseif ($view === 'team') {
  $where[] = "t.status='Open'";
  if ($teamAgentIds) {
    $ph = implode(',', array_fill(0, count($teamAgentIds), '?'));
    $where[] = "t.agent_id IN ($ph)";
    foreach ($teamAgentIds as $aid) $params[] = (int)$aid;
  } else {
    $where[] = "t.agent_id=?";
    $params[] = (int)$me['id'];
  }
} elseif ($view === 'unassigned') {
    $where[] = "t.agent_id IS NULL";
    $filters['agent_id'] = -1; // Set filter state for UI
}

$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

/* Fetch tickets */
$sql = "
  SELECT t.*,
         CONCAT_WS(' ',cu.first_name,cu.last_name) AS created_by_name,
         CONCAT_WS(' ',au.first_name,au.last_name) AS agent_name,
         l.name AS location_name,
         (SELECT COUNT(*) FROM ticket_comments c WHERE c.ticket_id=t.id) AS comments_count,
         rto.id AS related_to_id,
         rto.label AS related_leaf
  FROM tickets t
  LEFT JOIN users cu ON cu.id=t.created_by
  LEFT JOIN users au ON au.id=t.agent_id
  LEFT JOIN locations l ON l.id=t.location_id
  LEFT JOIN related_to_options rto ON rto.id=t.related_to_id
  $whereSql
  ORDER BY t.id DESC
";
$st = $pdo->prepare($sql); $st->execute($params);
$tickets = $st->fetchAll(PDO::FETCH_ASSOC);

/* Build full related_to path per ticket */
foreach ($tickets as &$tk) {
  $tk['related_to_path'] = $tk['related_to_id'] ? related_path($pdo,(int)$tk['related_to_id']) : null;
}
unset($tk);

/* preload thread content for the conversation modal */
$commentsByTicket = []; $attachmentsByTicket = []; $logsByTicket = [];
if ($tickets){
  $ids = array_column($tickets,'id');
  $in = implode(',', array_fill(0,count($ids),'?'));

  $sc = $pdo->prepare("SELECT c.*, CONCAT_WS(' ',u.first_name,u.last_name) AS user_name FROM ticket_comments c LEFT JOIN users u ON u.id=c.user_id WHERE c.ticket_id IN ($in) ORDER BY c.id ASC");
  $sc->execute($ids);
  foreach($sc->fetchAll(PDO::FETCH_ASSOC) as $r){ $commentsByTicket[(int)$r['ticket_id']][] = $r; }

  $sa = $pdo->prepare("SELECT * FROM ticket_attachments WHERE ticket_id IN ($in) ORDER BY id ASC");
  $sa->execute($ids);
  foreach($sa->fetchAll(PDO::FETCH_ASSOC) as $a){ $attachmentsByTicket[(int)$a['ticket_id']][] = $a; }

  $sl = $pdo->prepare("SELECT l.*, CONCAT_WS(' ',u.first_name,u.last_name) AS actor_name FROM ticket_logs l LEFT JOIN users u ON u.id=l.actor_id WHERE l.ticket_id IN ($in) ORDER BY l.id ASC");
  $sl->execute($ids);
  foreach($sl->fetchAll(PDO::FETCH_ASSOC) as $L){ $logsByTicket[(int)$L['ticket_id']][] = $L; }
}

include __DIR__ . '/partials/header.php';
?>
<div class="card">
  <div class="card-h">
    <h3>Dashboard — Tickets</h3>
    <div style="display:flex;gap:.5rem;align-items:center">
      <?php if ($is_admin || $myQueues): ?>
        <button class="btn btn-primary" type="button" onclick="openModal('new')">+ New Ticket</button>
      <?php endif; ?>
      <?php if ($is_admin): ?>
        <button class="btn" type="button" onclick="openModal('manage-related')" id="manage-related-btn">Manage “Related To”</button>
      <?php endif; ?>
    </div>
  </div>

  <div class="card-b">
    <form method="get" id="filtersForm" class="filters-grid">
      <div>
        <label class="label">Search #</label>
        <input class="input" type="text" name="no" id="filter_no" value="<?= e($filters['no'] ?? '') ?>" placeholder="e.g. 20250926">
      </div>
      <div>
        <label class="label">Status</label>
        <select class="input" name="status">
          <option value="">Any</option>
          <?php foreach($STATUS_VALUES as $s): ?>
            <option value="<?= e($s) ?>" <?= $filters['status']===$s?'selected':'' ?>><?= e($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="label">Agent</label>
        <select class="input" name="agent_id">
          <option value="">Any</option>
          <option value="-1" <?= $filters['agent_id']===-1?'selected':'' ?>>— Unassigned —</option>
          <?php foreach($users as $uid=>$nm): ?>
            <option value="<?= (int)$uid ?>" <?= ((int)$filters['agent_id']===(int)$uid)?'selected':'' ?>><?= e($nm) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="label">Queue</label>
        <?php if ($is_admin || $myQueues): ?>
          <select class="input" name="queue">
            <option value="">Any</option>
            <?php foreach(($queueOptions ?: []) as $q): ?>
              <option value="<?= e($q) ?>" <?= $filters['queue']===$q?'selected':'' ?>><?= e($q) ?></option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <div class="badge">No queue access</div>
        <?php endif; ?>
      </div>
      <div>
        <label class="label">Location</label>
        <select class="input" name="location_id">
          <option value="">Any</option>
          <?php foreach($locations as $lid=>$ln): ?>
            <option value="<?= (int)$lid ?>" <?= ((int)$filters['location_id']===(int)$lid)?'selected':'' ?>><?= e($ln) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>

    <div class="filters-actions">
      <button class="btn btn-primary" type="button" onclick="document.getElementById('filtersForm').submit()">Apply</button>
      <button class="btn" type="button" onclick="window.location.href='tickets.php'">Reset</button>
      <button class="btn" type="button" onclick="saveDefault()">Save as default view</button>
      <form method="post" class="inline-form">
        <?php csrf_input(); ?>
        <button class="btn" name="clear_default" value="1" type="submit">Clear default</button>
      </form>
      <?php if ($usingDefault): ?><span class="badge badge-success">Using your default</span><?php endif; ?>
    </div>

    <form method="post" id="saveDefaultForm" style="display:none">
      <?php csrf_input(); ?>
      <input type="hidden" name="f_no" id="sd_no">
      <input type="hidden" name="f_status" id="sd_status">
      <input type="hidden" name="f_agent_id" id="sd_agent">
      <input type="hidden" name="f_queue" id="sd_queue">
      <input type="hidden" name="f_location_id" id="sd_location">
      <input type="hidden" name="save_default" value="1">
    </form>
  </div>
</div>

<div class="card">
  <div class="card-h" style="display:flex;align-items:center;justify-content:space-between;gap:.75rem">
    <h3>All Tickets</h3>
    <div class="quickviews" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
      <a class="btn<?= ($view==='mine'?' btn-primary':'') ?>" href="tickets.php?view=mine">My tickets</a>
      <a class="btn<?= ($view==='team'?' btn-primary':'') ?>" href="tickets.php?view=team">My team’s tickets</a>
      <a class="btn<?= ($view==='unassigned'?' btn-primary':'') ?>" href="tickets.php?view=unassigned">Unassigned tickets</a>
      <?php if (!empty($view)): ?>
        <a class="btn" href="tickets.php">Clear</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-b" style="overflow:auto">
    <?php if ($view==='mine'): ?>
      <div class="badge badge-primary" style="margin:.25rem 0 .5rem">Viewing: My tickets (Open)</div>
    <?php elseif ($view==='team'): ?>
      <div class="badge badge-primary" style="margin:.25rem 0 .5rem">Viewing: My team’s tickets (Open)</div>
    <?php elseif ($view==='unassigned'): ?>
      <div class="badge badge-primary" style="margin:.25rem 0 .5rem">Viewing: Unassigned tickets</div>
    <?php endif; ?>

    <?php if(!$tickets): ?>
      <?php if(!$is_admin && !$myQueues): ?>
        <p>You don’t have access to any queues. Please ask an admin to add you to a queue.</p>
      <?php else: ?>
        <p>No tickets found for this view.</p>
      <?php endif; ?>
    <?php else: ?>
      <table class="table" id="ticketsTable">
        <thead>
          <tr>
            <th>#</th><th>Subject</th><th>Requester</th><th>Agent</th><th>Queue</th><th>Location</th><th>Related To</th><th>Status</th><th>Priority</th>
          </tr>
        </thead>
        <tbody>
        <?php $badgeClass = fn($s)=>(['New'=>'badge-primary','Open'=>'badge-warning','Pending'=>'badge-warning','Resolved'=>'badge-success','Closed'=>'badge-success'][$s]??'badge-primary'); ?>
        <?php foreach($tickets as $t): $tid=(int)$t['id']; ?>
          <tr id="t<?= $tid ?>" class="row-openable" data-id="<?= $tid ?>">
            <td><?= e($t['ticket_number']) ?></td>
            <td><?= e($t['subject']) ?></td>
            <td><?= e($t['requester_email'] ?? '-') ?></td>
            <td><?= e($t['agent_name'] ?? '-') ?></td>
            <td><?= e($t['queue'] ?? '-') ?></td>
            <td><?= e($t['location_name'] ?? '-') ?></td>
            <td><?= e($t['related_to_path'] ?? '-') ?></td>
            <td>
              <span class="badge <?= $badgeClass($t['status']) ?>">
                <?= e($t['status']) ?><?= $t['status']==='Pending' && $t['pending_until'] ? ' · until '.e($t['pending_until']) : '' ?>
              </span>
            </td>
            <td><?= e($t['priority']) ?></td>
            <td style="display:none">
              <span id="tdata-<?= $tid ?>" data-json='<?= e(json_encode([
                'id'=>$tid,'ticket_number'=>$t['ticket_number'],'subject'=>$t['subject'],
                'requester_email'=>$t['requester_email'],'priority'=>$t['priority'],'status'=>$t['status'],
                'description'=>$t['description'],'agent_id'=>$t['agent_id'],'agent_name'=>$t['agent_name'],
                'location_id'=>$t['location_id'],'location_name'=>$t['location_name'],'queue'=>$t['queue'],
                'created_at'=>$t['created_at'],'created_by_name'=>$t['created_by_name'],
                'related_to_id'=>$t['related_to_id'],'related_to_path'=>$t['related_to_path'],
                'pending_until'=>$t['pending_until']
              ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>'></span>
              <span id="cdata-<?= $tid ?>" data-comments='<?= e(json_encode($commentsByTicket[$tid] ?? [], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>'
                    data-atts='<?= e(json_encode($attachmentsByTicket[$tid] ?? [], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>'
                    data-logs='<?= e(json_encode($logsByTicket[$tid] ?? [], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>'></span>
              <?php if($is_admin): ?>
              <form method="post" class="inline" onsubmit="return confirm('Delete this ticket?')">
                <?php csrf_input(); ?><input type="hidden" name="id" value="<?= $tid ?>">
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

<div id="modal-new" class="modal" style="display:none">
  <div class="modal-backdrop" onclick="closeModal('new')"></div>
  <div class="modal-card" style="max-width:1000px">
    <div class="modal-h"><h3>New Ticket</h3><button class="btn" onclick="closeModal('new')">✕</button></div>
    <div class="modal-b">
      <?php if (!$is_admin && !$myQueues): ?>
        <div class="badge">You don’t have access to any queues. Please ask an admin.</div>
      <?php else: ?>
      <form method="post" enctype="multipart/form-data" class="grid grid-2">
        <?php csrf_input(); ?>
        <div class="grid" style="grid-template-columns:1fr"><label class="label">Subject</label><input class="input" name="subject" required></div>
        <div></div>
        <div><label class="label">From (Requester)</label><input class="input" type="email" name="requester_email" placeholder="name@example.com" required></div>
        <div><label class="label">To (Agent)</label>
          <select name="agent_id" class="input"><option value="">— Unassigned —</option>
            <?php foreach($users as $uid=>$nm): ?><option value="<?= (int)$uid ?>"><?= e($nm) ?></option><?php endforeach; ?></select>
        </div>
        <div><label class="label">Queue</label>
          <select name="queue" class="input" required>
            <?php foreach(($queueOptions ?: []) as $q): ?><option><?= e($q) ?></option><?php endforeach; ?></select>
        </div>
        <div><label class="label">Location</label>
          <select name="location_id" class="input"><option value="">—</option><?php foreach($locations as $lid=>$ln): ?><option value="<?= (int)$lid ?>"><?= e($ln) ?></option><?php endforeach; ?></select>
        </div>
        <div><label class="label">Related To</label>
          <select name="related_to_id" class="input"><option value="">—</option>
            <?php foreach($relatedKV as $rid=>$path): ?><option value="<?= (int)$rid ?>"><?= e($path) ?></option><?php endforeach; ?></select>
        </div>
        <div><label class="label">Priority</label>
          <select name="priority" class="input"><?php foreach($PRIORITY_VALUES as $p): ?><option <?= $p===$PRIORITY_DEFAULT?'selected':''; ?>><?= e($p) ?></option><?php endforeach; ?></select>
        </div>
        <div><label class="label">Status</label>
          <select name="status" class="input" id="new_status"><?php foreach($STATUS_VALUES as $s): ?><option <?= $s===$STATUS_DEFAULT?'selected':''; ?>><?= e($s) ?></option><?php endforeach; ?></select>
        </div>
        <div id="new_pending_wrap" style="display:none"><label class="label">Pending until</label><input class="input" type="datetime-local" name="pending_until" id="new_pending_until"></div>
        <div class="grid" style="grid-template-columns:1fr">
          <label class="label">Message</label><textarea class="input" name="description" rows="6" placeholder="Write your message…" required></textarea>
        </div>
        <div><label class="label">Attachments</label><input class="input" type="file" name="attachments[]" multiple><small style="color:#666">Allowed: pdf, images, doc/xls/ppt, csv, txt</small></div>
        <div><button class="btn btn-primary" name="create" value="1">Send</button><button class="btn" type="button" onclick="closeModal('new')">Cancel</button></div>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<div id="modal-ticket" class="modal" style="display:none">
  <div class="modal-backdrop" onclick="closeModal('ticket')"></div>
  <div class="modal-card" style="max-width:1100px">
    <div class="modal-h"><h3 id="conv_subject">Ticket</h3><button class="btn" onclick="closeModal('ticket')">✕</button></div>
    <div class="modal-b">
      <div class="conv-header">
        <div class="addr">
          <div><strong>From:</strong> <span id="conv_from"></span></div>
          <div><strong>To:</strong> <span id="conv_to"></span></div>
          <div><strong>Queue:</strong> <span id="conv_queue"></span></div>
          <div><strong>Location:</strong> <span id="conv_location"></span></div>
          <div><strong>Related To:</strong> <span id="conv_related"></span></div>
        </div>
        <form method="post" id="conv_update" class="controls">
          <?php csrf_input(); ?>
          <input type="hidden" name="id" id="conv_id">
          <label><span>Status</span><select name="status" id="conv_status" class="input"><?php foreach($STATUS_VALUES as $s): ?><option><?= e($s) ?></option><?php endforeach; ?></select></label>
          <label><span>Priority</span><select name="priority" id="conv_priority" class="input"><?php foreach($PRIORITY_VALUES as $p): ?><option><?= e($p) ?></option><?php endforeach; ?></select></label>
          <label><span>Agent</span><select name="agent_id" id="conv_agent" class="input"><option value="">—</option><?php foreach($users as $uid=>$nm): ?><option value="<?= (int)$uid ?>"><?= e($nm) ?></option><?php endforeach; ?></select></label>
          <?php if ($is_admin || $myQueues): ?>
          <label><span>Queue</span><select name="queue" id="conv_queue_sel" class="input"><?php foreach(($queueOptions ?: []) as $q): ?><option><?= e($q) ?></option><?php endforeach; ?></select></label>
          <?php endif; ?>
          <label><span>Related To</span><select name="related_to_id" id="conv_related_sel" class="input"><option value="">—</option><?php foreach($relatedKV as $rid=>$path): ?><option value="<?= (int)$rid ?>"><?= e($path) ?></option><?php endforeach; ?></select></label>
          <label id="conv_pending_wrap" style="display:none"><span>Pending until</span><input class="input" type="datetime-local" name="pending_until" id="conv_pending_until"></label>
          <button class="btn btn-primary" name="update" value="1">Save</button>
        </form>
      </div>

      <div id="thread" class="thread"></div>

      <form method="post" enctype="multipart/form-data" class="composer">
        <?php csrf_input(); ?>
        <input type="hidden" name="ticket_id" id="reply_ticket_id">
        <textarea class="input" name="comment_body" rows="3" placeholder="Reply…" required></textarea>
        <div class="compose-actions">
          <input class="input" type="file" name="comment_attachments[]" multiple>
          <button class="btn btn-primary" name="add_comment" value="1">Send</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div id="modal-manage-related" class="modal" style="display:none">
  <div class="modal-backdrop" onclick="closeModal('manage-related')"></div>
  <div class="modal-card" style="max-width:800px">
    <div class="modal-h"><h3>Manage “Related To”</h3><button class="btn" onclick="closeModal('manage-related')">✕</button></div>
    <div class="modal-b">
      <?php if ($is_admin): ?>
      <form method="post" class="grid-2" id="add-related-form">
        <?php csrf_input(); ?>
        <div>
          <label class="label">Parent (optional)</label>
          <select class="input" name="rt_parent_id">
            <option value="">— Root —</option>
            <?php foreach($relatedKV as $rid=>$path): ?>
              <option value="<?= (int)$rid ?>"><?= e($path) ?></option>
            <?php endforeach; ?></select>
        </div>
        <div>
          <label class="label">Label</label>
          <input class="input" type="text" name="rt_label" placeholder="e.g. IT, Printing, Paper" required>
        </div>
        <div><button class="btn btn-primary" name="add_related" value="1">Add</button></div>
      </form>

      <div style="margin-top:1rem">
        <table class="table">
          <thead><tr><th>Path</th><th style="width:120px">Actions</th></tr></thead>
          <tbody>
          <?php foreach($relatedKV as $rid=>$path): ?>
            <tr>
              <td><?= e($path) ?></td>
              <td>
                <form method="post" onsubmit="return confirm('Delete this item? Only allowed if unused and with no children.');" style="display:inline">
                  <?php csrf_input(); ?><input type="hidden" name="rid" value="<?= (int)$rid ?>">
                  <button class="btn btn-danger" name="delete_related" value="1">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
        <div class="badge">Admins only.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<style>
/* Filters */
.filters-grid{display:grid;grid-template-columns:repeat(5, minmax(0,1fr));gap:.75rem;align-items:end}
.filters-actions{
  margin-top:.75rem;display:flex;align-items:center;gap:.5rem;flex-wrap:nowrap;
  white-space:nowrap; /* keeps everything on one line */
}
.filters-actions .btn{height:36px;padding:.5rem .9rem}
.inline-form{display:inline;margin:0}
/* Conversation UI */
.modal{position:fixed;inset:0;z-index:60;display:flex;align-items:center;justify-content:center}
.modal-backdrop{position:absolute;inset:0;background:rgba(0,0,0,.35)}
.modal-card{position:relative;background:var(--white);border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,.15);width:min(1100px,94vw);max-height:92vh;overflow:auto}
.modal-h{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid #e5e7eb}
.modal-b{padding:1rem 1.25rem}
.label{font-weight:600;margin:.25rem 0}
.conv-header{display:flex;gap:1.25rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:1rem;border:1px solid #eef0f2;border-radius:10px;padding:.75rem 1rem;background:#fafbfc}
.conv-header .addr>div{margin:.15rem 0}
.conv-header .controls{display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end}
.conv-header .controls label{display:flex;flex-direction:column;font-size:.9rem}
.thread{display:flex;flex-direction:column;gap:.75rem;margin-bottom:1rem}
.msg{border:1px solid #eef0f2;border-radius:12px;padding:.75rem 1rem}
.msg .meta{display:flex;justify-content:space-between;gap:1rem;margin-bottom:.35rem;color:#555}
.msg .meta .fromto{display:flex;gap:1rem;flex-wrap:wrap}
.msg .body{white-space:pre-wrap}
.msg .atts{margin-top:.4rem}
.msg .atts a{margin-right:.4rem}
.msg.initial{background:#f7fafc}
.msg.log{background:#fff7ed;border-color:#fcd9b6}
.msg.log .body{font-style:italic}
.composer{display:grid;grid-template-columns:1fr auto;gap:.75rem;align-items:start}
.compose-actions{display:flex;flex-direction:column;gap:.5rem}
.grid-2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem}
@media (max-width:1200px){ .filters-grid{grid-template-columns:repeat(2,minmax(0,1fr));} .filters-actions{flex-wrap:wrap;} }

/* Row click to open */
#ticketsTable tbody tr.row-openable{cursor:pointer}
#ticketsTable tbody tr.row-openable:hover{background:#f8fafb}

/* Anchor buttons to match button styling (quick-views) */
a.btn{
  display:inline-flex; align-items:center; gap:.35rem;
  padding:.5rem .9rem; border:1px solid #e5e7eb; border-radius:8px;
  background:#f3f4f6; color:#111; text-decoration:none; font-weight:600;
  line-height:1; cursor:pointer;
}
a.btn:hover{ background:#eef2f5; }
a.btn.btn-primary{
  background:#22c55e; border-color:#22c55e; color:#fff;
}
a.btn.btn-primary:hover{ filter:brightness(0.97); }
a.btn.btn-danger{
  background:#ef4444; border-color:#ef4444; color:#fff;
}
</style>
<script>
function openModal(n){ document.getElementById('modal-'+n).style.display='flex'; }
function closeModal(n){ document.getElementById('modal-'+n).style.display='none'; }

function saveDefault(){
  const f = document.getElementById('filtersForm');
  document.getElementById('sd_no').value       = f.no.value || '';
  document.getElementById('sd_status').value   = f.status.value || '';
  document.getElementById('sd_agent').value    = f.agent_id.value || '';
  document.getElementById('sd_queue').value    = f.queue ? (f.queue.value || '') : '';
  document.getElementById('sd_location').value = f.location_id.value || '';
  document.getElementById('saveDefaultForm').submit();
}

/* Show/hide Pending-until in New Ticket */
(function(){
  const stSel = document.getElementById('new_status');
  const wrap = document.getElementById('new_pending_wrap');
  function toggle(){ wrap.style.display = (stSel && stSel.value === 'Pending') ? '' : 'none'; }
  if (stSel){ stSel.addEventListener('change', toggle, {passive:true}); toggle(); }
})();

/* Dynamic client-side filter by Ticket # */
(function(){
  const input = document.getElementById('filter_no');
  const tbody = document.querySelector('#ticketsTable tbody');
  if (!input || !tbody) return;
  function applyFilter(){
    const q = (input.value || '').toLowerCase().trim();
    const rows = tbody.querySelectorAll('tr');
    rows.forEach(tr => {
      const firstCell = tr.querySelector('td');
      const text = (firstCell ? firstCell.textContent : '').toLowerCase();
      tr.style.display = !q || text.indexOf(q) !== -1 ? '' : 'none';
    });
  }
  input.addEventListener('input', applyFilter, {passive:true});
  applyFilter();
})();

/* Row click opens ticket */
(function(){
  const rows = document.querySelectorAll('#ticketsTable tbody tr.row-openable');
  rows.forEach(tr=>{
    tr.addEventListener('click', ()=>{
      const id = tr.getAttribute('data-id');
      if (id) openTicketModal(parseInt(id,10));
    });
  });
})();

/* Build the conversation thread with logs inline */
function openTicketModal(id){
  const tEl = document.getElementById('tdata-'+id);
  const cEl = document.getElementById('cdata-'+id);
  if(!tEl) return;
  const t = JSON.parse(tEl.getAttribute('data-json')||'{}');
  const comments = cEl ? JSON.parse(cEl.getAttribute('data-comments')||'[]') : [];
  const atts = cEl ? JSON.parse(cEl.getAttribute('data-atts')||'[]') : [];
  const logs = cEl ? JSON.parse(cEl.getAttribute('data-logs')||'[]') : [];
  const attByComment = {};
  atts.forEach(a => { const k=String(a.comment_id||'0'); (attByComment[k]=attByComment[k]||[]).push(a); });

  document.getElementById('conv_subject').textContent = (t.ticket_number ? t.ticket_number+' — ' : '') + (t.subject||'Ticket');
  document.getElementById('conv_from').textContent = t.requester_email || '-';
  document.getElementById('conv_to').textContent = t.agent_name || 'Unassigned';
  document.getElementById('conv_queue').textContent = t.queue || '-';
  document.getElementById('conv_location').textContent = t.location_name || '-';
  document.getElementById('conv_related').textContent = t.related_to_path || '-';

  document.getElementById('conv_id').value = t.id;
  document.getElementById('conv_status').value = t.status || '';
  document.getElementById('conv_priority').value = t.priority || '';
  document.getElementById('conv_agent').value = t.agent_id || '';
  const qsel = document.getElementById('conv_queue_sel'); if (qsel) qsel.value = t.queue || '';
  const rsel = document.getElementById('conv_related_sel'); if (rsel) rsel.value = t.related_to_id || '';

  const pWrap = document.getElementById('conv_pending_wrap');
  const pInput = document.getElementById('conv_pending_until');

  if (pInput){
    if (t.pending_until){
      const dt = new Date(t.pending_until.replace(' ', 'T'));
      if (!isNaN(dt.getTime())){
        const pad = n=>String(n).padStart(2,'0');
        const val = dt.getFullYear()+'-'+pad(dt.getMonth()+1)+'-'+pad(dt.getDate())+'T'+pad(dt.getHours())+':'+pad(dt.getMinutes());
        pInput.value = val;
      } else { pInput.value = ''; }
    } else { pInput.value = ''; }
  }

  function togglePendingField(){
    const show = (document.getElementById('conv_status').value === 'Pending');
    if (pWrap) pWrap.style.display = show ? '' : 'none';
  }
  document.getElementById('conv_status').addEventListener('change', togglePendingField, {passive:true});
  togglePendingField();

  document.getElementById('reply_ticket_id').value = t.id;

  // Merge events
  const events = [];
  events.push({type:'initial', created_at:t.created_at, body:t.description, atts:(attByComment['0']||[])});
  logs.forEach(l => events.push({type:'log', created_at:l.created_at, action:l.action, actor_name:l.actor_name, meta:(l.meta?JSON.parse(l.meta):{})}));
  comments.forEach(c => events.push({type:'comment', created_at:c.created_at, body:c.body, user_name:c.user_name, atts:(attByComment[String(c.id)]||[])}));
  events.sort((a,b)=> new Date(a.created_at) - new Date(b.created_at));

  const thread = document.getElementById('thread'); thread.innerHTML = '';

  events.forEach(ev => {
    if (ev.type==='initial'){
      const m = document.createElement('div'); m.className='msg initial';
      const meta = `<div class="meta"><div class="fromto"><span><strong>From:</strong> ${t.requester_email||'-'}</span><span><strong>To:</strong> ${t.agent_name||'Unassigned'}</span></div><div><small>${ev.created_at||''}</small></div></div>`;
      let body = `<div class="body">${(ev.body||'').replace(/</g,'&lt;')}</div>`;
      if (ev.atts.length){ body += `<div class="atts"><small>Attachments:</small> ` + ev.atts.map(a=>`<a class="btn" href="${a.path}" target="_blank">${a.original_name}</a>`).join(' ') + `</div>`; }
      m.innerHTML = meta + body; thread.appendChild(m);
    } else if (ev.type==='log'){
      const m = document.createElement('div'); m.className='msg log';
      const meta = `<div class="meta"><div class="fromto"><span><strong>System</strong></span></div><div><small>${ev.created_at||''}</small></div></div>`;
      let text = '';
      const a = ev.action, u = ev.actor_name || 'System', d = ev.meta || {};
      if (a==='created'){ text = `Ticket created by ${u}`; }
      else if (a==='assign'){ const from = d.from_name || 'Unassigned', to = d.to_name || 'Unassigned'; text = `Assigned to ${to} (from ${from}) by ${u}`; }
      else if (a==='status'){ text = `Status changed from ${d.from||'-'} to ${d.to||'-'} by ${u}` + (d.pending_until?` (until ${d.pending_until})`:``); }
      else if (a==='priority'){ text = `Priority changed from ${d.from||'-'} to ${d.to||'-'} by ${u}`; }
      else if (a==='queue'){ text = `Queue changed from ${d.from||'-'} to ${d.to||'-'} by ${u}`; }
      else if (a==='comment'){ text = `Comment added by ${u}`; }
      else if (a==='attachment'){ text = `${(d.count||1)} attachment(s) added by ${u}`; }
      else { text = `${a} by ${u}`; }
      m.innerHTML = meta + `<div class="body">${text.replace(/</g,'&lt;')}</div>`;
      thread.appendChild(m);
    } else if (ev.type==='comment'){
      const m = document.createElement('div'); m.className='msg';
      const meta = `<div class="meta"><div class="fromto"><span><strong>From:</strong> ${ev.user_name||'User'}</span><span><strong>To:</strong> Participants</span></div><div><small>${ev.created_at||''}</small></div></div>`;
      let body = `<div class="body">${(ev.body||'').replace(/</g,'&lt;')}</div>`;
      if (ev.atts.length){ body += `<div class="atts"><small>Attachments:</small> ` + ev.atts.map(a=>`<a class="btn" href="${a.path}" target="_blank">${a.original_name}</a>`).join(' ') + `</div>`; }
      m.innerHTML = meta + body; thread.appendChild(m);
    }
  });

  openModal('ticket');
}
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
