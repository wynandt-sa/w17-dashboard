<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

/* --------- no-cache so celebrations + badges update promptly --------- */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!function_exists('e')) {
  function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
function log_err($ex){
  try { @file_put_contents('/tmp/ticketing_error.log', "[".date('c')."] ".$ex."\n", FILE_APPEND); } catch(Throwable $t) {}
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$me = user();
$is_admin = ($me['role'] ?? '') === 'admin';
$myLocId = (int)($me['location_id'] ?? 0);

/* ---------------- helpers for cheap schema checks ---------------- */
function col_exists(PDO $pdo, $table, $col){
  try{
    $st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
    $st->execute([$table,$col]); return (bool)$st->fetchColumn();
  }catch(Throwable $e){ log_err($e->getMessage()); return false; }
}
function table_exists(PDO $pdo, $table){
  try{
    $st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1");
    $st->execute([$table]); return (bool)$st->fetchColumn();
  }catch(Throwable $e){ log_err($e->getMessage()); return false; }
}

/* ---------------- Queue visibility (strict) for KPIs ---------------- */
$params=[]; $whereQueues=''; $myQueues=[];
if (!$is_admin) {
  try {
    $uq=$pdo->prepare("SELECT queue FROM user_queue_access WHERE user_id=?");
    $uq->execute([(int)$me['id']]);
    $myQueues=$uq->fetchAll(PDO::FETCH_COLUMN);
  } catch(Throwable $e){ log_err($e->getMessage()); $myQueues=[]; }
  if (!$myQueues) {
    $whereQueues="WHERE 1=0";
  } else {
    $in=implode(',', array_fill(0,count($myQueues),'?'));
    $whereQueues="WHERE t.queue IN ($in)";
    $params=array_values($myQueues);
  }
}

/* ---------------- KPIs ---------------- */
try {
  $sqlOpen="SELECT COUNT(*) c FROM tickets t $whereQueues".($whereQueues?" AND":" WHERE")." t.status <> 'Closed'";
  $st=$pdo->prepare($sqlOpen); $st->execute($params); $openCount=(int)$st->fetchColumn();
} catch(Throwable $e){ $openCount=0; log_err($e->getMessage()); }

try {
  $dateCol = col_exists($pdo,'tickets','updated_at') ? 't.updated_at' : 't.created_at';
  $sqlRes="SELECT COUNT(*) c FROM tickets t $whereQueues".($whereQueues?" AND":" WHERE")." t.status='Resolved' AND DATE($dateCol)=CURDATE()";
  $st=$pdo->prepare($sqlRes); $st->execute($params); $resolvedCount=(int)$st->fetchColumn();
} catch(Throwable $e){ $resolvedCount=0; log_err($e->getMessage()); }

try { $activeTasks=(int)($pdo->query("SELECT COUNT(*) c FROM tasks WHERE status <> 'completed'")->fetch()['c'] ?? 0); }
catch(Throwable $e){ $activeTasks=0; log_err($e->getMessage()); }

try { $totalUsers = $is_admin ? (int)($pdo->query("SELECT COUNT(*) c FROM users")->fetch()['c'] ?? 0) : null; }
catch(Throwable $e){ $totalUsers=null; log_err($e->getMessage()); }

/* ---------------- My Recent Tickets (open & assigned to me) ---------------- */
try {
  $sqlRT="SELECT t.ticket_number, t.subject, t.status, t.priority, t.created_at
          FROM tickets t
          $whereQueues".($whereQueues?" AND":" WHERE")." t.status <> 'Closed' AND t.agent_id = ?
          ORDER BY t.id DESC LIMIT 8";
  $st=$pdo->prepare($sqlRT);
  $st->execute([...$params, (int)$me['id']]);
  $recentTickets=$st->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e){ $recentTickets=[]; log_err($e->getMessage()); }

/* ---------------- Celebrations (unchanged from previous working version) ---------------- */
$todayUsersBirthdays = $todayUsersAnniv = $todayLocationBirthdays = [];
$upcomingUsersBirthdays = $upcomingUsersAnniv = $upcomingLocationBirthdays = [];
try {
  $userDisplayExpr="CONCAT_WS(' ', first_name, last_name)";
  $mdayList=[]; for($i=1;$i<=5;$i++) $mdayList[]=date('m-d', strtotime("+$i day"));
  $mdayQs = $mdayList ? implode(',', array_fill(0,count($mdayList),'?')) : "'--'";

  $todayUsersBirthdays=$pdo->query("
    SELECT id, $userDisplayExpr AS display_name, date_of_birth
    FROM users
    WHERE date_of_birth IS NOT NULL
      AND DATE_FORMAT(date_of_birth,'%m-%d') = DATE_FORMAT(CURDATE(),'%m-%d')
    ORDER BY display_name
  ")->fetchAll(PDO::FETCH_ASSOC);

  $st=$pdo->prepare("
    SELECT id, $userDisplayExpr AS display_name, date_of_birth
    FROM users
    WHERE date_of_birth IS NOT NULL
      AND DATE_FORMAT(date_of_birth,'%m-%d') IN ($mdayQs)
    ORDER BY DATE_FORMAT(date_of_birth,'%m-%d'), display_name
  "); $st->execute($mdayList); $upcomingUsersBirthdays=$st->fetchAll(PDO::FETCH_ASSOC);

  $todayUsersAnniv=$pdo->query("
    SELECT id, $userDisplayExpr AS display_name, work_anniversary,
           TIMESTAMPDIFF(YEAR, work_anniversary, CURDATE()) AS years_now
    FROM users
    WHERE work_anniversary IS NOT NULL
      AND DATE_FORMAT(work_anniversary,'%m-%d') = DATE_FORMAT(CURDATE(),'%m-%d')
    ORDER BY display_name
  ")->fetchAll(PDO::FETCH_ASSOC);

  $st=$pdo->prepare("
    SELECT id, $userDisplayExpr AS display_name, work_anniversary,
           YEAR(work_anniversary) AS start_year,
           DATE_FORMAT(work_anniversary,'%m-%d') AS md
    FROM users
    WHERE work_anniversary IS NOT NULL
      AND DATE_FORMAT(work_anniversary,'%m-%d') IN ($mdayQs)
    ORDER BY md, display_name
  "); $st->execute($mdayList); $upcomingUsersAnniv=$st->fetchAll(PDO::FETCH_ASSOC);
  $cy=(int)date('Y'); foreach($upcomingUsersAnniv as &$r){ $r['years_next']=max(1,$cy-(int)$r['start_year']); } unset($r);

  if (table_exists($pdo,'locations')) {
    $locCol  = col_exists($pdo,'locations','birthday') ? 'birthday' : (col_exists($pdo,'locations','birthdate') ? 'birthdate' : null);
    $locName = col_exists($pdo,'locations','name') ? 'name' : (col_exists($pdo,'locations','location_name') ? 'location_name' : null);
    if ($locCol && $locName) {
      $todayLocationBirthdays=$pdo->query("
        SELECT id, $locName AS loc_name, $locCol AS bday,
               TIMESTAMPDIFF(YEAR, $locCol, CURDATE()) AS years_now
        FROM locations
        WHERE $locCol IS NOT NULL
          AND DATE_FORMAT($locCol,'%m-%d') = DATE_FORMAT(CURDATE(),'%m-%d')
        ORDER BY loc_name
      ")->fetchAll(PDO::FETCH_ASSOC);

      $st=$pdo->prepare("
        SELECT id, $locName AS loc_name, $locCol AS bday,
               YEAR($locCol) AS start_year, DATE_FORMAT($locCol,'%m-%d') AS md
        FROM locations
        WHERE $locCol IS NOT NULL
          AND DATE_FORMAT($locCol,'%m-%d') IN ($mdayQs)
        ORDER BY DATE_FORMAT($locCol,'%m-%d'), loc_name
      ");
      $st->execute($mdayList); $upcomingLocationBirthdays=$st->fetchAll(PDO::FETCH_ASSOC);
      $cy=(int)date('Y'); foreach($upcomingLocationBirthdays as &$L){ $L['years_next']=max(1,$cy-(int)$L['start_year']); } unset($L);
    }
  }
} catch(Throwable $e){
  log_err($e->getMessage());
  $todayUsersBirthdays = $todayUsersAnniv = $todayLocationBirthdays = [];
  $upcomingUsersBirthdays = $upcomingUsersAnniv = $upcomingLocationBirthdays = [];
}
$hasToday = (count($todayUsersBirthdays)+count($todayUsersAnniv)+count($todayLocationBirthdays))>0;
$hasUpcoming = (count($upcomingUsersBirthdays)+count($upcomingUsersAnniv)+count($upcomingLocationBirthdays))>0;
$hasAnyCelebrations = $hasToday || $hasUpcoming;

/* ---------------- Monitoring badges (single combined badge each) ---------------- */

/* network fetch with curl fallback */
function http_get($url, $timeout=5){
  $res = null;
  if (function_exists('curl_init')) {
    $ch=curl_init($url);
    curl_setopt_array($ch,[
      CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_TIMEOUT=>$timeout,
      CURLOPT_CONNECTTIMEOUT=>$timeout, CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_SSL_VERIFYHOST=>0
    ]);
    $res=curl_exec($ch);
    curl_close($ch);
  } else {
    $ctx=stream_context_create(['http'=>['timeout'=>$timeout],'ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);
    $res=@file_get_contents($url,false,$ctx);
  }
  return $res===false?null:$res;
}

/* parse % from badge payload (SVG or text) */
function parse_percent($txt){
  if (!$txt) return null;
  if (preg_match('/(\d+(?:\.\d+)?)\s*%/i', $txt, $m)) return (float)$m[1];
  return null;
}
/* parse status word (up|down|pending|degraded) from payload */
function parse_status($txt){
  if (!$txt) return null;
  $s = strtolower($txt);
  if (strpos($s,'down') !== false) return 'down';
  if (strpos($s,'pending') !== false) return 'pending';
  if (strpos($s,'degraded') !== false) return 'pending';
  if (strpos($s,'up') !== false) return 'up';
  return null;
}

$monitorBase = 'https://monitor.itried.co.za/api/badge/';
$badges=[];

/* what to show:
   - Admins: if they have no location set, show ALL active badges.
   - Non-admins: if they have a location, show those mapped; otherwise show nothing + hint.
*/
$badgesSql = null; $args = [];
if ($is_admin && !$myLocId) {
  $badgesSql = "SELECT b.id, b.name, b.description, b.monitor_id, b.hours
                FROM monitoring_badges b
                WHERE b.active=1
                ORDER BY b.name";
} else {
  $badgesSql = "SELECT DISTINCT b.id, b.name, b.description, b.monitor_id, b.hours
                FROM monitoring_badges b
                JOIN location_badges lb ON lb.badge_id=b.id
                WHERE b.active=1 AND lb.location_id=?";
  $args[] = $myLocId ?: -1;
}
try {
  if (table_exists($pdo,'monitoring_badges') && table_exists($pdo,'location_badges')) {
    $st=$pdo->prepare($badgesSql); $st->execute($args); $badges=$st->fetchAll(PDO::FETCH_ASSOC);
  }
} catch(Throwable $e){ log_err($e->getMessage()); $badges=[]; }

/* hydrate with live data; never fatal */
foreach ($badges as &$B){
  $mid=(int)$B['monitor_id']; $hrs=max(1,(int)($B['hours']??168));
  $uptRaw = http_get($monitorBase.$mid."/uptime/".$hrs);
  $statRaw= http_get($monitorBase.$mid."/status");
  $B['uptime'] = parse_percent($uptRaw);
  $B['status'] = parse_status($statRaw) ?: 'pending';
  // color class
  $B['state']  = ($B['status']==='down' ? 'down' : ($B['status']==='pending' ? 'pending' : 'up'));
}
unset($B);

/* ---------------- App shortcut cards ---------------- */
$appCards = [
  ['title'=>'My Workshop17','url'=>'https://my.workshop17.com','desc'=>'Member portal'],
  ['title'=>'Papercut','url'=>'https://print.workshop17.com','desc'=>'Print management'],
  ['title'=>'Workshop17 CRM','url'=>'https://crm.workshop17.co.za','desc'=>'Sales CRM'],
  ['title'=>'Workshop17 Website','url'=>'https://workshop17.com','desc'=>'Public site'],
  ['title'=>'Radius Server','url'=>'https://radius.workshop17.com:2443','desc'=>'Network auth'],
];

include __DIR__ . '/partials/header.php';
?>

<?php if ($hasAnyCelebrations): ?>
  <div class="cele-wrap">
    <?php if ($hasToday): ?>
      <div class="cele-banner card">
        <div class="card-h"><h3>🎉 Today's Celebrations</h3></div>
        <div class="card-b">
          <div class="cele-list">
            <?php foreach ($todayUsersBirthdays as $p): ?>
              <div class="cele-card"><div class="cele-emoji">🎂</div><div><div class="cele-name"><?= e($p['display_name']) ?></div><div class="cele-sub">Happy Birthday! 🥳</div></div><button class="cele-btn" type="button">Celebrate</button></div>
            <?php endforeach; ?>
            <?php foreach ($todayUsersAnniv as $p): ?>
              <div class="cele-card"><div class="cele-emoji">🏆</div><div><div class="cele-name"><?= e($p['display_name']) ?></div><div class="cele-sub"><?= (int)max(0,(int)$p['years_now']) ?> <?= ((int)$p['years_now']===1?'Year':'Years') ?> at Workshop17!</div></div><button class="cele-btn" type="button">Celebrate</button></div>
            <?php endforeach; ?>
            <?php foreach ($todayLocationBirthdays as $l): ?>
              <div class="cele-card"><div class="cele-emoji">📍</div><div><div class="cele-name"><?= e($l['loc_name']) ?></div><div class="cele-sub">Location Birthday — <?= (int)$l['years_now'] ?> <?= ((int)$l['years_now']===1?'Year':'Years') ?> 🎉</div></div><button class="cele-btn" type="button">Celebrate</button></div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($hasUpcoming): ?>
      <div class="card">
        <div class="card-h"><h3>🗓️ Upcoming (next 5 days)</h3></div>
        <div class="card-b">
          <div class="cele-list">
            <?php foreach ($upcomingUsersBirthdays as $p): ?>
              <div class="cele-card cele-muted"><div class="cele-emoji">🎂</div><div><div class="cele-name"><?= e($p['display_name']) ?></div><div class="cele-sub">Birthday on <?= e(date('D, d M', strtotime($p['date_of_birth']))) ?></div></div></div>
            <?php endforeach; ?>
            <?php foreach ($upcomingUsersAnniv as $p): ?>
              <div class="cele-card cele-muted"><div class="cele-emoji">🏆</div><div><div class="cele-name"><?= e($p['display_name']) ?></div><div class="cele-sub"><?= (int)$p['years_next'] ?> <?= ((int)$p['years_next']===1?'Year':'Years') ?> on <?= e(date('D, d M', strtotime(date('Y').'-'.$p['md']))) ?></div></div></div>
            <?php endforeach; ?>
            <?php foreach ($upcomingLocationBirthdays as $l): ?>
              <div class="cele-card cele-muted"><div class="cele-emoji">📍</div><div><div class="cele-name"><?= e($l['loc_name']) ?></div><div class="cele-sub">Location Birthday — <?= (int)$l['years_next'] ?> <?= ((int)$l['years_next']===1?'Year':'Years') ?> on <?= e(date('D, d M', strtotime(date('Y').'-'.$l['md']))) ?></div></div></div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<!-- ======= KPIs ======= -->
<div class="card">
  <div class="card-h">
    <h3>Dashboard</h3>
    <?php if ($is_admin && $totalUsers !== null): ?><div class="badge badge-primary">Users: <?= (int)$totalUsers ?></div><?php endif; ?>
  </div>
  <div class="card-b">
    <div class="kpi-grid">
      <div class="kpi-card"><div class="kpi-label">Open Tickets</div><div class="kpi-value"><?= (int)$openCount ?></div></div>
      <div class="kpi-card"><div class="kpi-label">Resolved Today</div><div class="kpi-value"><?= (int)$resolvedCount ?></div></div>
      <div class="kpi-card"><div class="kpi-label">Active Tasks</div><div class="kpi-value"><?= (int)$activeTasks ?></div></div>
    </div>
  </div>
</div>

<!-- ======= My Open Tickets ======= -->
<div class="card">
  <div class="card-h"><h3>My Open Tickets</h3></div>
  <div class="card-b">
    <?php if (empty($recentTickets)): ?>
      <p>No open tickets assigned to you.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Ticket #</th><th>Subject</th><th>Status</th><th>Priority</th><th class="fit">Created</th></tr></thead>
          <tbody>
          <?php foreach($recentTickets as $t): ?>
            <tr>
              <td><?= e($t['ticket_number']) ?></td>
              <td><?= e($t['subject']) ?></td>
              <td><span class="badge badge-primary"><?= e($t['status']) ?></span></td>
              <td><?= e($t['priority']) ?></td>
              <td class="fit"><?= e(date('Y-m-d', strtotime($t['created_at']))) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ======= Apps & Monitoring ======= -->
<div class="grid grid-3">
  <!-- Apps -->
  <div class="card">
    <div class="card-h"><h3>Apps</h3></div>
    <div class="card-b">
      <div class="app-grid">
        <?php foreach($appCards as $a): ?>
          <a class="app-card" href="<?= e($a['url']) ?>" target="_blank" rel="noopener">
            <div class="app-title"><?= e($a['title']) ?></div>
            <div class="app-desc"><?= e($a['desc']) ?></div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Monitoring -->
  <div class="card" style="grid-column: span 2;">
    <div class="card-h">
      <h3>Monitoring</h3>
      <?php if(!$is_admin && !$myLocId): ?>
        <div class="badge">No location set. Ask an admin to set your location to see local badges.</div>
      <?php endif; ?>
    </div>
    <div class="card-b">
      <?php if (!$badges): ?>
        <p>No monitoring badges to show.</p>
      <?php else: ?>
        <div class="mon-grid">
          <?php foreach($badges as $B): 
            $upt = $B['uptime']; $status = $B['status']; $state = $B['state'];
          ?>
            <div class="mon-card">
              <div class="mon-top">
                <div class="mon-name"><?= e($B['name']) ?></div>
                <span class="mbadge <?= 'mbadge-'.$state ?>">
                  <strong><?= $status==='up'?'UP':($status==='down'?'DOWN':'PENDING') ?></strong>
                  <?php if ($upt !== null): ?><span class="sep">•</span><span><?= number_format($upt,2) ?>%</span><?php endif; ?>
                </span>
              </div>
              <?php if (!empty($B['description'])): ?><div class="mon-desc"><?= e($B['description']) ?></div><?php endif; ?>
              <div class="mon-foot"><small>Window: <?= (int)$B['hours'] ?>h • ID: <?= (int)$B['monitor_id'] ?></small></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<style>
/* Apps */
.app-grid{ display:grid; gap:0.75rem; grid-template-columns:repeat(2,minmax(0,1fr)); }
.app-card{
  display:block; border:1px solid var(--gray-200); border-radius:12px; padding:0.9rem 1rem;
  background:#fff; text-decoration:none; color:#111; box-shadow:var(--shadow);
}
.app-card:hover{ background:var(--gray-50); }
.app-title{ font-weight:800; margin-bottom:.2rem; }
.app-desc{ color:var(--muted); font-size:.92rem; }

/* Monitoring */
.mon-grid{ display:grid; gap:0.75rem; grid-template-columns:repeat(2,minmax(0,1fr)); }
@media (max-width:900px){ .mon-grid{ grid-template-columns:1fr; } }
.mon-card{ border:1px solid var(--gray-200); border-radius:12px; padding:0.9rem 1rem; background:#fff; box-shadow:var(--shadow); }
.mon-top{ display:flex; justify-content:space-between; align-items:center; gap:0.75rem; }
.mon-name{ font-weight:800; }
.mon-desc{ color:var(--muted); margin-top:.25rem; }
.mon-foot{ margin-top:.5rem; color:#69707a; }

/* Combined badge pill */
.mbadge{
  display:inline-flex; align-items:center; gap:.4rem; padding:.25rem .6rem;
  border-radius:999px; font-size:.82rem; font-weight:800; line-height:1;
  border:1px solid transparent;
}
.mbadge .sep{ opacity:.6; }
.mbadge-up{ background:rgba(34,197,94,.18); color:#166534; border-color:rgba(34,197,94,.35); }     /* green */
.mbadge-pending{ background:rgba(245,158,11,.18); color:#92400e; border-color:rgba(245,158,11,.32);} /* amber */
.mbadge-down{ background:rgba(239,68,68,.18); color:#991b1b; border-color:rgba(239,68,68,.32);}    /* red */
</style>

<script>
/* tiny confetti for celebrations */
(function(){
  function rand(min,max){ return Math.random()*(max-min)+min; }
  function confetti(n=220){
    const colors=['#ffffff','#FFCDD2','#F8BBD0','#BBDEFB','#B2EBF2','#C8E6C9','#FFF9C4','#FFE0B2'];
    const bodyW=document.documentElement.clientWidth;
    for(let i=0;i<n;i++){
      const p=document.createElement('div');
      p.style.position='fixed'; p.style.top='-10px'; p.style.left=(Math.random()*bodyW)+'px';
      p.style.width=rand(6,12)+'px'; p.style.height=rand(10,18)+'px';
      p.style.background=colors[Math.floor(rand(0,colors.length))]; p.style.opacity='.95';
      p.style.transform='translateY(-10vh) rotate('+rand(0,360)+'deg)'; p.style.animation='fall '+rand(1.2,2.4)+'s linear forwards';
      p.style.zIndex='80'; document.body.appendChild(p); setTimeout(()=>p.remove(),3000);
    }
  }
  document.addEventListener('click', (ev)=>{ if(ev.target.closest('.cele-btn')) confetti(240); }, {passive:true});
})();
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
