<?php
// Shared SLA and Housekeeping logic
// Requires config.php (for Zulip constants) to be loaded by the caller.

// --- Core Helper Functions (Defined defensively to prevent redeclaration crashes) ---

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

if (!function_exists('table_has_column')) {
    function table_has_column(PDO $pdo, $table, $column){
      $s = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
      $s->execute([$table,$column]); return (bool)$s->fetchColumn();
    }
}

if (!function_exists('add_log')) {
    function add_log(PDO $pdo, int $ticket_id, ?int $actor_id, string $action, array $meta = []): void {
      $s=$pdo->prepare("INSERT INTO ticket_logs (ticket_id,actor_id,action,meta) VALUES (?,?,?,?)");
      $s->execute([$ticket_id,$actor_id,$action,json_encode($meta, JSON_UNESCAPED_UNICODE)]);
    }
}

// NEW: Get template and substitute placeholders
function get_email_template(PDO $pdo, string $type, array $ticket_data): ?array {
    $st = $pdo->prepare("SELECT subject, body_html FROM email_templates WHERE template_type = ? LIMIT 1");
    $st->execute([$type]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) return null;

    $tn = $ticket_data['ticket_number'] ?? 'N/A';
    $sub = $ticket_data['subject'] ?? 'N/A';
    $status = $ticket_data['status'] ?? 'N/A';
    $priority = $ticket_data['priority'] ?? 'N/A';
    $link = (defined('APP_BASE_URL')? rtrim(APP_BASE_URL,'/') : '').'/tickets.php#t'.($ticket_data['id'] ?? 0);

    $placeholders = [
        '{{ticket_number}}' => $tn,
        '{{ticket_subject}}' => $sub,
        '{{ticket_status}}' => $status,
        '{{ticket_priority}}' => $priority,
        '{{ticket_link}}' => $link
    ];

    $t['subject'] = str_replace(array_keys($placeholders), array_values($placeholders), $t['subject']);
    $t['body_html'] = str_replace(array_keys($placeholders), array_values($placeholders), $t['body_html']);
    return $t;
}

// NEW: Placeholder for send_mail (re-defined here to be available globally in context)
if (!function_exists('send_mail')) {
    function send_mail(string $to, string $subject, string $body_html, ?string $from_email = null): bool {
        // Placeholder implementation for external tools (e.g. Zulip) and internal calls (e.g. email_fetcher.php)
        error_log("MAIL_SENT_TICKET: To: $to, From: $from_email, Subject: $subject");
        return true;
    }
}

// NEW: Unified Zulip/Email notification logic
function notify_ticket_recipients(PDO $pdo, int $ticket_id, string $event_type, array $ticket_data, string $message_body_raw, ?array $excluded_emails = []): void {
    
    // 1. Determine recipients (Requester, Agent, Followers, CC)
    $recipients = [];
    
    // Followers
    $followers = $pdo->prepare("SELECT u.email FROM ticket_followers tf JOIN users u ON u.id=tf.user_id WHERE tf.ticket_id=?");
    $followers->execute([$ticket_id]);
    foreach($followers->fetchAll(PDO::FETCH_COLUMN) as $email) { $recipients[$email] = 'follower'; }

    // Requester
    if (!empty($ticket_data['requester_email'])) { $recipients[$ticket_data['requester_email']] = 'requester'; }
    
    // Agent
    $agent_email = null;
    if (!empty($ticket_data['agent_id'])) {
        $st_agent = $pdo->prepare("SELECT email FROM users WHERE id=?");
        $st_agent->execute([$ticket_data['agent_id']]);
        $agent_email = $st_agent->fetchColumn();
        if ($agent_email) { $recipients[$agent_email] = 'agent'; }
    }

    // CC Emails (comma-separated list stored as string)
    if (!empty($ticket_data['cc_emails'])) {
        $cc_list = explode(',', $ticket_data['cc_emails']);
        foreach(array_filter($cc_list) as $cc_email) {
            if (filter_var(trim($cc_email), FILTER_VALIDATE_EMAIL)) {
                $recipients[trim($cc_email)] = 'cc';
            }
        }
    }
    
    $excluded_emails = array_map('strtolower', $excluded_emails);
    $targets_zulip = []; $targets_email = [];
    
    foreach ($recipients as $email => $role) {
        if (!in_array(strtolower($email), $excluded_emails)) {
            $targets_zulip[] = $email;
            $targets_email[] = $email;
        }
    }

    // 2. Zulip Notification
    $link = (defined('APP_BASE_URL')? rtrim(APP_BASE_URL,'/') : '').'/tickets.php#t'.$ticket_id;
    $zulip_msg = "🎫 **[#{$ticket_data['ticket_number']}]** - *{$ticket_data['subject']}*\nStatus: `{$ticket_data['status']}`\n\n{$message_body_raw}\n\n[Open in Dashboard]($link)";
    
    foreach (array_unique($targets_zulip) as $email) {
        // zulip_send_pm is assumed to be available in the global scope of the caller
        @zulip_send_pm($email, $zulip_msg);
    }

    // 3. Email Notification
    $tpl = get_email_template($pdo, $event_type, $ticket_data);
    if ($tpl) {
        $queue_email = null;
        if (!empty($ticket_data['queue'])) {
            $st_q = $pdo->prepare("SELECT email FROM queue_emails WHERE queue=?");
            $st_q->execute([$ticket_data['queue']]);
            $queue_email = $st_q->fetchColumn();
        }

        foreach (array_unique($targets_email) as $email) {
            @send_mail($email, $tpl['subject'], $tpl['body_html'], $queue_email); 
        }
    }
}


// --- Business Logic Functions ---

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

function priority_sla_seconds(PDO $pdo, string $priority, string $type = 'resolution'): int {
    try {
        // We rely on the existence of the sla_settings table (created in system_settings.php)
        $col = ($type === 'response') ? 'response_hours' : 'resolution_hours';
        $st = $pdo->prepare("SELECT {$col} FROM sla_settings WHERE priority = ?");
        $st->execute([$priority]);
        $hours = (float)$st->fetchColumn();
        return max(1, (int)round($hours * 3600)); // Min 1 second
    } catch (Throwable $e) {
        // Fallback to hardcoded defaults if DB is unavailable/missing schema
        switch ($priority) {
            case 'Critical': return 2 * 3600;
            case 'High':     return 4 * 3600;
            case 'Medium':   return 12 * 3600;
            default:         return 24 * 3600;
        }
    }
}

function priority_reset_seconds(PDO $pdo, string $priority): int {
    return priority_sla_seconds($pdo, $priority, 'resolution');
}

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

function ensure_schema_for_housekeeping(PDO $pdo): void {
  if (!table_has_column($pdo,'tickets','pending_until'))        $pdo->exec("ALTER TABLE tickets ADD COLUMN pending_until DATETIME NULL");
  if (!table_has_column($pdo,'tickets','sla_started_at'))        $pdo->exec("ALTER TABLE tickets ADD COLUMN sla_started_at DATETIME NULL");
  if (!table_has_column($pdo,'tickets','sla_paused_started_at')) $pdo->exec("ALTER TABLE tickets ADD COLUMN sla_paused_started_at DATETIME NULL");
  if (!table_has_column($pdo,'tickets','sla_paused_seconds'))    $pdo->exec("ALTER TABLE tickets ADD COLUMN sla_paused_seconds INT NOT NULL DEFAULT 0");
  if (!table_has_column($pdo,'tickets','last_sla_level'))        $pdo->exec("ALTER TABLE tickets ADD COLUMN last_sla_level TINYINT NOT NULL DEFAULT 0");
  if (!table_has_column($pdo,'tickets','last_sla_escalated_at')) $pdo->exec("ALTER TABLE tickets ADD COLUMN last_sla_escalated_at DATETIME NULL");

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

function run_ticket_housekeeping(PDO $pdo): void {
  ensure_schema_for_housekeeping($pdo);

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
                  ($link ? "[Open in Dashboard Ticketing]($link)" : "");
          // Requires zulip_send_pm to be available in the global scope of the caller
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
      $sla  = priority_sla_seconds($pdo, $t['priority'] ?? 'Low', 'resolution');
      $rset = priority_reset_seconds($pdo, $t['priority'] ?? 'Low');

      $lvl = (int)($t['last_sla_level'] ?? 0);
      $escalateTo = 0;
      if ($lvl < 1 && $elapsed >= $sla)            $escalateTo = 1;
      if ($lvl < 2 && $elapsed >= ($sla + $rset))  $escalateTo = 2;

      if ($escalateTo > 0) {
        $emails = agent_manager_chain_emails($pdo, (int)$t['agent_id']);
        $hoursOver = round( max(0, $elapsed - ($escalateTo===1 ? $sla : $sla+$rset)) / 3600, 2 );
        $link = (defined('APP_BASE_URL')? rtrim(APP_BASE_URL,'/') : '').'/tickets.php#t'.$t['id'];

        $targets = [];
        if ($emails[0]) $targets[] = $emails[0];
        if ($emails[1]) $targets[] = $emails[1];
        if ($escalateTo >= 2 && $emails[2]) $targets[] = $emails[2];

        if ($targets) {
          $msg = "⏱️ SLA **breach level {$escalateTo}** for ticket **{$t['ticket_number']}** — *".($t['subject'] ?? 'Ticket')."*.\n".
                 "Priority: `{$t['priority']}` | Elapsed business time: **".round($elapsed/3600,2)."h** | Over by **{$hoursOver}h**\n".
                 ($link ? "[Open in Dashboard Ticketing]($link)" : "");
          // Requires zulip_send_pm to be available in the global scope of the caller
          foreach (array_unique($targets) as $em) { @zulip_send_pm($em, $msg); }
        }
        $pdo->prepare("UPDATE tickets SET last_sla_level=?, last_sla_escalated_at=NOW() WHERE id=?")->execute([$escalateTo, (int)$t['id']]);
        add_log($pdo,(int)$t['id'], null, 'other', ['sla_escalation_level'=>$escalateTo,'elapsed_seconds'=>$elapsed]);
      }
    }
  }
}
