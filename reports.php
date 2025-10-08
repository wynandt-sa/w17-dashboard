<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$me = user();

/* ---- Only managers (users with child users) may view ---- */
$st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE manager_id=?");
$st->execute([$me['id']]);
if ((int)$st->fetchColumn() === 0) {
  http_response_code(403);
  exit("Access denied");
}

/* ---- helpers ---- */
function col_exists(PDO $pdo, string $table, string $col): bool {
  $s = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
  $s->execute([$table,$col]);
  return (bool)$s->fetchColumn();
}

/* ---------- Related To helpers (hierarchy via related_to_options) ---------- */
function related_path(PDO $pdo, ?int $id): string {
  if (!$id) return '-';
  static $cache = [];
  if (isset($cache[$id])) return $cache[$id];
  $path = [];
  $cur = $id;
  while ($cur) {
    $s = $pdo->prepare("SELECT id,parent_id,label FROM related_to_options WHERE id=?");
    $s->execute([$cur]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) break;
    array_unshift($path, (string)$row['label']);
    $cur = $row['parent_id'] ? (int)$row['parent_id'] : null;
  }
  $cache[$id] = $path ? implode(' :: ', $path) : '-';
  return $cache[$id];
}

/* ------------ Filter option lists ------------ */
$users = $pdo->query("
  SELECT id, CONCAT_WS(' ',first_name,last_name) AS name
  FROM users
  ORDER BY first_name,last_name
")->fetchAll(PDO::FETCH_KEY_PAIR);

$locations = $pdo->query("SELECT id, name FROM locations ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);

$queues = $pdo->query("
  SELECT DISTINCT queue FROM tickets WHERE queue IS NOT NULL AND queue<>'' ORDER BY queue
")->fetchAll(PDO::FETCH_COLUMN);
if (!$queues) { $queues = ['Workshop17','HR','Finance']; }

/* Detect schema for Related To */
$hasRelatedId  = col_exists($pdo,'tickets','related_to_id') && $pdo->query("SHOW TABLES LIKE 'related_to_options'")->fetchColumn();
$hasRelatedStr = col_exists($pdo,'tickets','related_to');

/* Build dropdown options for Related To (hierarchical paths) */
$relatedOptions = [];
if ($hasRelatedId) {
  $opts = $pdo->query("SELECT id FROM related_to_options ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
  foreach ($opts as $rid) {
    $relatedOptions[(int)$rid] = related_path($pdo, (int)$rid);
  }
  asort($relatedOptions, SORT_NATURAL|SORT_FLAG_CASE);
} elseif ($hasRelatedStr) {
  $rows = $pdo->query("
    SELECT DISTINCT related_to
    FROM tickets
    WHERE related_to IS NOT NULL AND related_to <> ''
    ORDER BY related_to
  ")->fetchAll(PDO::FETCH_COLUMN);
  foreach ($rows as $rt) $relatedOptions[$rt] = $rt;
}

/* ------------ Read filters from GET ------------ */
$filter_agent    = (int)($_GET['agent_id'] ?? 0) ?: null;
$filter_loc      = (int)($_GET['location_id'] ?? 0) ?: null;
$filter_queue    = trim($_GET['queue'] ?? '') ?: null;
$filter_related_id = $hasRelatedId ? ((int)($_GET['related_to_id'] ?? 0) ?: null) : null;
$filter_related    = (!$hasRelatedId && $hasRelatedStr) ? (trim($_GET['related_to'] ?? '') ?: null) : null;

/* Build WHERE clause for tickets alias `t` */
$where = [];
$params = [];
if ($filter_agent)      { $where[] = "t.agent_id = ?";     $params[] = $filter_agent; }
if ($filter_loc)        { $where[] = "t.location_id = ?";  $params[] = $filter_loc; }
if ($filter_queue)      { $where[] = "t.queue = ?";        $params[] = $filter_queue; }
if ($hasRelatedId && $filter_related_id) { $where[] = "t.related_to_id = ?"; $params[] = $filter_related_id; }
if (!$hasRelatedId && $hasRelatedStr && $filter_related) { $where[] = "t.related_to = ?"; $params[] = $filter_related; }
$whereSql = $where ? (' AND '.implode(' AND ',$where)) : '';

/* ---------- KPIs (MariaDB-safe) ---------- */

/* Average First Response Time (FRT) – hours */
$sqlFRT = "
  SELECT COALESCE(AVG(first_response_hours),0)
  FROM (
    SELECT TIMESTAMPDIFF(MINUTE, t.created_at, MIN(c.created_at)) / 60 AS first_response_hours
    FROM tickets t
    JOIN ticket_comments c ON c.ticket_id = t.id
    WHERE 1=1 {$whereSql}
    GROUP BY t.id
  ) AS y
";
$st = $pdo->prepare($sqlFRT); $st->execute($params); $avgFRT = $st->fetchColumn();

/* Average Resolution Time (ART) – hours */
$sqlART = "
  SELECT COALESCE(AVG(resolution_hours),0)
  FROM (
    SELECT TIMESTAMPDIFF(HOUR, t.created_at, MAX(l.created_at)) AS resolution_hours
    FROM tickets t
    JOIN ticket_logs l ON l.ticket_id = t.id
    WHERE l.action='status'
      AND (l.meta LIKE '%Resolved%' OR l.meta LIKE '%Closed%')
      {$whereSql}
    GROUP BY t.id
  ) AS x
";
$st = $pdo->prepare($sqlART); $st->execute($params); $avgART = $st->fetchColumn();

/* SLA Compliance – % (Low=24h, Medium=12h, High=4h) */
$sqlSLA = "
  SELECT COALESCE(100 * SUM(CASE WHEN s.resolution_hours <= s.sla_hours THEN 1 ELSE 0 END) / NULLIF(COUNT(*),0), 0)
  FROM (
    SELECT
      t.id,
      TIMESTAMPDIFF(HOUR, t.created_at, MAX(l.created_at)) AS resolution_hours,
      CASE t.priority WHEN 'Low' THEN 24 WHEN 'Medium' THEN 12 WHEN 'High' THEN 4 ELSE 24 END AS sla_hours
    FROM tickets t
    JOIN ticket_logs l ON l.ticket_id = t.id
    WHERE l.action='status'
      AND (l.meta LIKE '%Resolved%' OR l.meta LIKE '%Closed%')
      {$whereSql}
    GROUP BY t.id, t.priority
  ) AS s
";
$st = $pdo->prepare($sqlSLA); $st->execute($params); $slaCompliance = $st->fetchColumn();

/* Ticket Backlog – open/unresolved now */
$sqlBacklog = "
  SELECT COUNT(*) 
  FROM tickets t
  WHERE t.status IN ('New','Open','Pending') {$whereSql}
";
$st = $pdo->prepare($sqlBacklog); $st->execute($params); $ticketBacklog = $st->fetchColumn();

/* Tickets handled per agent (Resolved/Closed in last 30 days) */
$sqlHandled = "
  SELECT CONCAT_WS(' ',u.first_name,u.last_name) AS agent, COUNT(*) AS cnt
  FROM tickets t
  JOIN users u ON u.id = t.agent_id
  WHERE t.status IN ('Resolved','Closed')
    AND t.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
";
$paramsHandled = [];
if ($filter_agent)      { $sqlHandled .= " AND t.agent_id = ?";     $paramsHandled[] = $filter_agent; }
if ($filter_loc)        { $sqlHandled .= " AND t.location_id = ?";  $paramsHandled[] = $filter_loc; }
if ($filter_queue)      { $sqlHandled .= " AND t.queue = ?";        $paramsHandled[] = $filter_queue; }
if ($hasRelatedId && $filter_related_id) { $sqlHandled .= " AND t.related_to_id = ?"; $paramsHandled[] = $filter_related_id; }
if (!$hasRelatedId && $hasRelatedStr && $filter_related) { $sqlHandled .= " AND t.related_to = ?"; $paramsHandled[] = $filter_related; }
$sqlHandled .= " GROUP BY u.id ORDER BY cnt DESC, agent ASC";

$st = $pdo->prepare($sqlHandled); $st->execute($paramsHandled); $handled = $st->fetchAll(PDO::FETCH_ASSOC);

/* Tickets by Related To – breakdown */
$byRelated = [];
if ($hasRelatedId) {
  $sqlByRelated = "
    SELECT t.related_to_id AS rid,
           COUNT(*) AS total,
           SUM(CASE WHEN t.status IN ('New','Open','Pending') THEN 1 ELSE 0 END) AS open_cnt,
           SUM(CASE WHEN t.status IN ('Resolved','Closed') THEN 1 ELSE 0 END) AS closed_cnt
    FROM tickets t
    WHERE 1=1 {$whereSql}
    GROUP BY t.related_to_id
    ORDER BY total DESC
  ";
  $st = $pdo->prepare($sqlByRelated); $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $r) {
    $path = related_path($pdo, $r['rid'] ? (int)$r['rid'] : null);
    $byRelated[] = [
      'related_to' => $path,
      'total'      => (int)$r['total'],
      'open_cnt'   => (int)$r['open_cnt'],
      'closed_cnt' => (int)$r['closed_cnt'],
    ];
  }
  // Sort naturally by total desc, then name
  usort($byRelated, function($a,$b){
    if ($a['total'] === $b['total']) return strcasecmp($a['related_to'],$b['related_to']);
    return $b['total'] <=> $a['total'];
  });
} elseif ($hasRelatedStr) {
  $sqlByRelated = "
    SELECT COALESCE(NULLIF(t.related_to,''), '-') AS related_to,
           COUNT(*) AS total,
           SUM(CASE WHEN t.status IN ('New','Open','Pending') THEN 1 ELSE 0 END) AS open_cnt,
           SUM(CASE WHEN t.status IN ('Resolved','Closed') THEN 1 ELSE 0 END) AS closed_cnt
    FROM tickets t
    WHERE 1=1 {$whereSql}
    GROUP BY COALESCE(NULLIF(t.related_to,''), '-')
    ORDER BY total DESC, related_to ASC
  ";
  $st = $pdo->prepare($sqlByRelated); $st->execute($params); $byRelated = $st->fetchAll(PDO::FETCH_ASSOC);
}

include __DIR__ . '/partials/header.php';
?>
<div class="card">
  <div class="card-h"><h3>Reports & KPIs</h3></div>
  <div class="card-b">
    <!-- Filters -->
    <form method="get" class="grid grid-2" style="grid-template-columns:repeat(5,minmax(0,1fr));gap:.75rem;margin-bottom:1rem">
      <div>
        <label class="label">Location</label>
        <select name="location_id" class="input">
          <option value="">Any</option>
          <?php foreach($locations as $lid=>$ln): $lid_int=(int)$lid; ?>
            <option value="<?= $lid_int ?>" <?= ($filter_loc===$lid_int)?'selected':'' ?>><?= htmlspecialchars($ln) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="label">Agent</label>
        <select name="agent_id" class="input">
          <option value="">Any</option>
          <?php foreach($users as $uid=>$nm): $uid_int=(int)$uid; ?>
            <option value="<?= $uid_int ?>" <?= ($filter_agent===$uid_int)?'selected':'' ?>><?= htmlspecialchars($nm) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="label">Queue</label>
        <select name="queue" class="input">
          <option value="">Any</option>
          <?php foreach($queues as $q): ?>
            <option value="<?= htmlspecialchars($q) ?>" <?= ($filter_queue===$q)?'selected':'' ?>><?= htmlspecialchars($q) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($hasRelatedId): ?>
      <div>
        <label class="label">Related To</label>
        <select name="related_to_id" class="input">
          <option value="">Any</option>
          <?php foreach($relatedOptions as $rid=>$label): ?>
            <option value="<?= (int)$rid ?>" <?= ($filter_related_id===(int)$rid)?'selected':'' ?>><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php elseif ($hasRelatedStr): ?>
      <div>
        <label class="label">Related To</label>
        <select name="related_to" class="input">
          <option value="">Any</option>
          <?php foreach($relatedOptions as $rt => $lbl): ?>
            <option value="<?= htmlspecialchars($rt) ?>" <?= ($filter_related===$rt)?'selected':'' ?>><?= htmlspecialchars($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php else: ?>
      <div>
        <label class="label">Related To</label>
        <div class="input" style="opacity:.6">Not configured</div>
      </div>
      <?php endif; ?>
      <div style="display:flex;align-items:flex-end;gap:.5rem">
        <button class="btn btn-primary">Apply</button>
        <a class="btn btn-secondary" href="reports.php">Reset</a>
      </div>
    </form>

    <table class="table">
      <thead>
        <tr><th>Report/KPI</th><th>Description</th><th>Value</th><th>Goal / Insight</th></tr>
      </thead>
      <tbody>
        <tr>
          <td>Average First Response Time (FRT)</td>
          <td>The average time it takes for a team member to reply to a new ticket.</td>
          <td><?= number_format((float)$avgFRT, 2) ?> hours</td>
          <td>A low FRT shows good responsiveness.</td>
        </tr>
        <tr>
          <td>Average Resolution Time (ART)</td>
          <td>Total time from creation until the ticket is marked Resolved/Closed.</td>
          <td><?= number_format((float)$avgART, 2) ?> hours</td>
          <td>Lower ART = higher operational efficiency.</td>
        </tr>
        <tr>
          <td>SLA Compliance Rate</td>
          <td>% of tickets resolved within SLA based on priority (Low 24h, Medium 12h, High 4h).</td>
          <td><?= number_format((float)$slaCompliance, 2) ?>%</td>
          <td>Falling compliance is a major risk to member satisfaction.</td>
        </tr>
        <tr>
          <td>Ticket Backlog</td>
          <td>Open tickets (New/Open/Pending).</td>
          <td><?= (int)$ticketBacklog ?></td>
          <td>Growing backlog indicates the team is falling behind capacity.</td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-h"><h3>Tickets by “Related To” (Current Filters)</h3></div>
  <div class="card-b">
    <table class="table">
      <thead><tr><th>Related To</th><th>Total</th><th>Open</th><th>Resolved/Closed</th></tr></thead>
      <tbody>
        <?php foreach($byRelated as $row): ?>
          <tr>
            <td><?= htmlspecialchars($row['related_to']) ?></td>
            <td><?= (int)$row['total'] ?></td>
            <td><?= (int)$row['open_cnt'] ?></td>
            <td><?= (int)$row['closed_cnt'] ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$byRelated): ?>
          <tr><td colspan="4">No data for the selected filters.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-h"><h3>Tickets Handled Per Agent (Last 30 days)</h3></div>
  <div class="card-b">
    <table class="table">
      <thead><tr><th>Agent</th><th>Tickets Resolved/Closed</th></tr></thead>
      <tbody>
        <?php foreach($handled as $h): ?>
          <tr>
            <td><?= htmlspecialchars($h['agent'] ?? '-') ?></td>
            <td><?= (int)$h['cnt'] ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$handled): ?>
          <tr><td colspan="2">No data for the selected filters.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
