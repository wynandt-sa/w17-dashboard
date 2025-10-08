<?php
/**
 * scheduled_tasks_runner.php
 * - Keeps your existing scheduled task generation logic (unchanged)
 * - Adds ticket housekeeping that also runs in the background:
 *     • Pending → Open when pending_until elapses (with Zulip notify)
 *     • SLA timers in business hours (pause on Pending, resume on Open)
 *     • SLA escalation notifications to agent/manager/manager’s manager via Zulip
 *
 * Run from cron, e.g.:
 * * * * * /usr/bin/php /path/to/scheduled_tasks_runner.php >/dev/null 2>&1
 */

require_once __DIR__ . '/db.php';
@require_once __DIR__ . '/config.php'; // Optional but recommended: APP_BASE_URL, ZULIP_* constants

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------------- Utilities: schema helpers ---------------- */
function table_has_column(PDO $pdo, $table, $column){
  $s = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
  $s->execute([$table,$column]); return (bool)$s->fetchColumn();
}
function ensure_schema_for_housekeeping(PDO $pdo): void {
  // Columns used by housekeeping
  if (!table_has_column($pdo,'tickets','pending_until'))        $pdo->exec("ALTER TABLE tickets ADD COLUMN pending_until DATETIME NULL");
  if (!table_has_column($pdo,'tickets','sla_started_at'))        $pdo->exec("ALTER TABLE tickets ADD COLUMN sla_started_at DATETIME NULL");
  if (!table_has_column($pdo,'tickets','sla_paused_started_at')) $pdo->exec("ALTER TABLE tickets ADD COLUMN sla_paused_started_at DATETIME NULL");
  if (!table_has_column($pdo,'tickets','sla_paused_seconds'))    $pdo->exec("ALTER TABLE tickets ADD COLUMN sla_paused_seconds INT NOT NULL DEFAULT 0");
  if (!table_has_column($pdo,'tickets','last_sla_level'))        $pdo->exec("ALTER TABLE tickets ADD COLUMN last_sla_level TINYINT NOT NULL DEFAULT 0");
  if (!table_has_column($pdo,'tickets','last_sla_escalated_at')) $pdo->exec("ALTER TABLE tickets ADD COLUMN last_sla_escalated_at DATETIME NULL");

  // Minimal ticket_logs for auditing (if not already present)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS ticket_logs (
      id INT AUTO_INCREMENT PRIMARY KEY,
      ticket_id INT NOT NULL,
      actor_id INT NULL,
      action ENUM('created','assign','status','priority','queue','comment','attachment','other') NOT NULL DEFAULT 'other',
      meta JSON NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX(ticket_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
}
ensure_schema_for_housekeeping($pdo);

/* ---------------- Zulip helper (safe if config not present) ---------------- */
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
    CURLOPT_TIMEOUT        => 10,
  ]);
  curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return ($http >= 200 && $http < 300);
}

/* ---------------- Minimal logger ---------------- */
function add_log(PDO $pdo, int $ticket_id, ?int $actor_id, string $action, array $meta = []): void {
  $s=$pdo->prepare("INSERT INTO ticket_logs (ticket_id,actor_id,action,meta) VALUES (?,?,?,?)");
  $s->execute([$ticket_id,$actor_id,$action,json_encode($meta, JSON_UNESCAPED_UNICODE)]);
}

/* ---------------- Business-time + SLA helpers ---------------- */
/* Business hours: Mon–Fri, 08:00–17:00 (server time). Adjust if needed. */
function business_seconds_between(string $from, string $to): int {
  $start = strtotime($from); $end = strtotime($to);
  if (!$start || !$end || $end <= $start) return 0;
  $dayStartH = 8; $dayEndH = 17;
  $sec = 0; $cur = $start;
  $cur = $cur - ($cur % 60); $end = $end - ($end % 60);
  while ($cur < $end) {
    $w = (int)date('N', $cur); // 1..7
    if ($w >= 1 && $w <= 5) {
      $ds = mktime($dayStartH,0,0, (int)date('m',$cur),(int)date('d',$cur),(int)date('Y',$cur));
      $de = mktime($dayEndH,0,0,   (int)date('m',$cur),(int)date('d',$cur),(int)date('Y',$cur));
      $winStart = max($cur, $ds);
      $winEnd   = min($end, $de);
      if ($winEnd > $winStart) $sec += ($winEnd - $winStart);
    }
    $cur = mktime(0,0,0, (int)date('m',$cur),(int)date('d',$cur)+1,(int)date('Y',$cur));
  }
  return $sec;
}
function priority_sla_seconds(string $priority): int {
  switch ($priority) {
    case 'High':   return 4 * 3600;   // 4 business hours
    case 'Medium': return 12 * 3600;  // 12 business hours
    case 'Low':    return 24 * 3600;  // 24 business hours
    default:       return 24 * 3600;
  }
}
/* Reset window = SLA again (so escalate level 2 at 2×SLA). Adjust if desired. */
function priority_reset_seconds(string $priority): int { return priority_sla_seconds($priority); }

/* Compute elapsed business seconds for SLA (subtract paused time; if currently Pending, counts live pause) */
function sla_elapsed_business_seconds(array $t): int {
  $now = date('Y-m-d H:i:s');
  if (empty($t['sla_started_at'])) return 0;
  $base = business_seconds_between($t['sla_started_at'], $now);
  $paused = (int)($t['sla_paused_seconds'] ?? 0);
  if (!empty($t['sla_paused_started_at'])) {
    $paused += business_seconds_between($t['sla_paused_started_at'], $now);
  }
  return max(0, $base - $paused);
}

/* Resolve agent, manager, manager's manager emails */
function agent_manager_chain_emails(PDO $pdo, ?int $agent_id): array {
  $emails = [null, null, null];
  if (!$agent_id) return $emails;
  $st = $pdo->prepare("SELECT id,email,manager_id FROM users WHERE id=?");
  $st->execute([$agent_id]);
  $u = $st->fetch(PDO::FETCH_ASSOC);
  if (!$u) return $emails;
  $emails[0] = $u['email'] ?? null;

  $mid = (int)($u['manager_id'] ?? 0);
  if ($mid) {
    $st->execute([$mid]);
    $m = $st->fetch(PDO::FETCH_ASSOC);
    if ($m) {
      $emails[1] = $m['email'] ?? null;
      $mmid = (int)($m['manager_id'] ?? 0);
      if ($mmid) {
        $st->execute([$mmid]);
        $mm = $st->fetch(PDO::FETCH_ASSOC);
        if ($mm) $emails[2] = $mm['email'] ?? null;
      }
    }
  }
  return $emails;
}

/* ---------------- Housekeeping ---------------- */
function run_ticket_housekeeping(PDO $pdo): void {
  // 1) Auto-reopen: Pending until elapsed -> Open + notify assignee
  $due = $pdo->query("
    SELECT id, ticket_number, subject, agent_id, queue, priority
    FROM tickets
    WHERE status='Pending' AND pending_until IS NOT NULL AND pending_until <= NOW()
  ")->fetchAll(PDO::FETCH_ASSOC);

  if ($due) {
    $ids = array_map('intval', array_column($due,'id'));
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("UPDATE tickets SET status='Open', pending_until=NULL WHERE id IN ($ph)")->execute($ids);

    $getEmail = $pdo->prepare("SELECT email FROM users WHERE id=?");
    foreach ($due as $t) {
      add_log($pdo,(int)$t['id'], null, 'status', ['from'=>'Pending','to'=>'Open','reason'=>'pending_elapsed']);
      if (!empty($t['agent_id'])) {
        $getEmail->execute([(int)$t['agent_id']]);
        if ($email = $getEmail->fetchColumn()) {
          $link = (defined('APP_BASE_URL')? rtrim(APP_BASE_URL,'/') : '').'/tickets.php#t'.(int)$t['id'];
          $msg  = "Ticket **{$t['ticket_number']}** — *".($t['subject'] ?: 'Ticket')."* has **re-opened** (pending timer elapsed).\n".
                  "Queue: `{$t['queue']}`  |  Priority: `{$t['priority']}`\n".
                  ($link ? "[Open in Ticketing]($link)" : "");
          @zulip_send_pm($email, $msg);
        }
      }
    }
  }

  // 2) SLA escalation for Open tickets
  $open = $pdo->query("
    SELECT id, ticket_number, subject, priority, agent_id, sla_started_at, sla_paused_started_at, sla_paused_seconds, last_sla_level
    FROM tickets
    WHERE status='Open' AND sla_started_at IS NOT NULL
  ")->fetchAll(PDO::FETCH_ASSOC);

  if ($open) {
    foreach ($open as $t) {
      $elapsed = sla_elapsed_business_seconds($t);
      $sla  = priority_sla_seconds($t['priority'] ?? 'Low');
      $rset = priority_reset_seconds($t['priority'] ?? 'Low');

      $lvl = (int)($t['last_sla_level'] ?? 0);
      $escalateTo = 0;
      if ($lvl < 1 && $elapsed >= $sla)            $escalateTo = 1;
      if ($lvl < 2 && $elapsed >= ($sla + $rset))  $escalateTo = 2;

      if ($escalateTo > 0) {
        $emails = agent_manager_chain_emails($pdo, (int)$t['agent_id']);
        $hoursOver = round( max(0, $elapsed - ($escalateTo===1 ? $sla : $sla+$rset)) / 3600, 2 );
        $link = (defined('APP_BASE_URL')? rtrim(APP_BASE_URL,'/') : '').'/tickets.php#t'.$t['id'];

        $targets = [];
        if ($emails[0]) $targets[] = $emails[0];               // agent
        if ($emails[1]) $targets[] = $emails[1];               // manager
        if ($escalateTo >= 2 && $emails[2]) $targets[] = $emails[2]; // manager's manager

        if ($targets) {
          $msg = "⏱️ SLA **breach level {$escalateTo}** for ticket **{$t['ticket_number']}** — *".($t['subject'] ?? 'Ticket')."*.\n".
                 "Priority: `{$t['priority']}` | Elapsed business time: **".round($elapsed/3600,2)."h** | Over by **{$hoursOver}h**\n".
                 ($link ? "[Open in Ticketing]($link)" : "");
          foreach (array_unique($targets) as $em) { @zulip_send_pm($em, $msg); }
        }
        $pdo->prepare("UPDATE tickets SET last_sla_level=?, last_sla_escalated_at=NOW() WHERE id=?")->execute([$escalateTo, (int)$t['id']]);
        add_log($pdo,(int)$t['id'], null, 'other', ['sla_escalation_level'=>$escalateTo,'elapsed_seconds'=>$elapsed]);
      }
    }
  }
}

/* ---------------- Run housekeeping every time this runner executes ---------------- */
run_ticket_housekeeping($pdo);

/* =======================================================================
 * Everything below is your EXISTING scheduled task logic, unchanged
 * ======================================================================= */

/* (kept) Auto ticket creation utility for scheduled tasks */
function create_ticket_auto(PDO $pdo, array $payload){
  $cols=[]; foreach(['ticket_number','subject','description','body','content','status','priority','assignee_id','assigned_to','created_by','created_at'] as $c){ if(table_has_column($pdo,'tickets',$c)) $cols[]=$c; }
  if(!in_array('subject',$cols,true)) throw new RuntimeException('tickets.subject missing');
  $params=[]; $columns_list=[];
  foreach($cols as $c){
    $columns_list[]=$c;
    if($c==='ticket_number'){ $n = (int)$pdo->query("SELECT IFNULL(MAX(id),0)+1 FROM tickets")->fetchColumn(); $params[":$c"]=date('Ymd').'-'.str_pad((string)$n,4,'0',STR_PAD_LEFT); }
    elseif($c==='status'){ $params[":$c"]=$payload['status']??'Open'; }
    elseif($c==='priority'){ $params[":$c"]=$payload['priority']??'Normal'; } // left as-is to avoid breaking existing flows
    elseif($c==='created_at'){ $params[":$c"]=$payload['created_at']??date('Y-m-d H:i:s'); }
    elseif($c==='assignee_id' || $c==='assigned_to'){ $params[":$c"]=$payload['assignee_id']??null; }
    else { $params[":$c"]=$payload[$c]??null; }
  }
  $sql="INSERT INTO tickets (".implode(',',$columns_list).") VALUES (".implode(',',array_keys($params)).")";
  $st=$pdo->prepare($sql); $st->execute($params);
  return (int)$pdo->lastInsertId();
}

/* (kept) Compute next run for scheduled task engine */
function compute_next_run(array $t): ?DateTime {
  $tz = new DateTimeZone($t['timezone'] ?: 'Africa/Johannesburg');
  $now = new DateTime('now', $tz);
  $start = new DateTime($t['start_date'], $tz);
  if ($now < $start) $now = clone $start;
  $type = $t['schedule_type'];
  if ($type==='daily'){ $d=clone $now; if($d->format('H:i:s')>'00:00:01') $d->modify('+1 day'); return $d->setTime(8,0,0); }
  if ($type==='weekly' || $type==='biweekly'){
    $by=array_filter(array_map('trim',explode(',',(string)$t['byday']))); if(!$by) $by=['MO'];
    $d=clone $now; $d->setTime(8,0,0);
    $wkStart=(int)$start->format('W');
    for($i=0;$i<60;$i++){
      $iso=strtoupper(substr($d->format('D'),0,2)).'O';
      $okWeek = ($type==='biweekly') ? ((((int)$d->format('W')-$wkStart)%2)===0) : true;
      if(in_array($iso,$by,true) && $okWeek && $d>=$start) return $d;
      $d->modify('+1 day');
    }
    return null;
  }
  if ($type==='monthly' || $type==='bimonthly'){
    $dom=(int)($t['day_of_month']?:1); $step = ($type==='bimonthly')?2:1;
    $base=clone $now; $base->setTime(8,0,0);
    for($i=0;$i<24;$i++){
      $cand=(clone $base)->modify('+'.($i*$step).' months');
      $cand->setDate((int)$cand->format('Y'), (int)$cand->format('m'), min($dom,(int)$cand->format('t')));
      if($cand>=$start && $cand>=$now) return $cand;
    } return null;
  }
  return null;
}

/* (kept) Process due tasks */
$due = $pdo->query("SELECT * FROM scheduled_tasks WHERE active=1 AND next_run_at IS NOT NULL AND next_run_at <= NOW()")->fetchAll(PDO::FETCH_ASSOC);
foreach ($due as $task) {
  $pdo->beginTransaction();
  $pdo->prepare("INSERT INTO scheduled_task_runs (task_id, run_date) VALUES (?, CURDATE())")->execute([$task['id']]);
  $run_id = (int)$pdo->lastInsertId();
  $tpl = $pdo->prepare("SELECT * FROM task_templates WHERE id=?"); $tpl->execute([$task['template_id']]); $template = $tpl->fetch(PDO::FETCH_ASSOC);
  $items = $pdo->prepare("SELECT * FROM task_template_items WHERE template_id=? ORDER BY sort_order,id"); $items->execute([$task['template_id']]); $items=$items->fetchAll(PDO::FETCH_ASSOC);
  $ass = $pdo->prepare("SELECT user_id FROM scheduled_task_assignees WHERE task_id=?"); $ass->execute([$task['id']]); $assignees=$ass->fetchAll(PDO::FETCH_COLUMN);

  $body=''; if(!empty($template['description'])) $body.=$template['description']."\n\n";
  if($items){ $body.="Checklist:\n"; foreach($items as $it){ $body.="- [ ] ".$it['item_text'].($it['is_required']?' (required)':'')."\n"; } }
  $subject = $task['title'].' — '.date('D d M');

  foreach ($assignees as $uid) {
    $tid = create_ticket_auto($pdo, [
      'subject'=>$subject, 'description'=>$body, 'status'=>'Open', 'priority'=>'Normal', 'assignee_id'=>(int)$uid
    ]);
    $pdo->prepare("INSERT INTO scheduled_task_run_tickets (run_id,ticket_id,assignee_id) VALUES (?,?,?)")->execute([$run_id,$tid,$uid]);
  }
  // forward next_run_at
  $next = compute_next_run($task);
  if ($next) $pdo->prepare("UPDATE scheduled_tasks SET next_run_at=? WHERE id=?")->execute([$next->format('Y-m-d H:i:s'), $task['id']]);
  $pdo->commit();
}

echo "OK\n";
