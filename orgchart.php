<?php
// orgchart.php — centered organogram (2nd tier auto-expanded, clean avatars, no search)
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php'; // auth.php now defines h() globally

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// **FIX: Conditionally define h() to prevent "Cannot redeclare function h()" fatal error.**
if (!function_exists('h')) {
  function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}


/* Fetch ACTIVE users */
$rows = $pdo->query("
  SELECT id, manager_id, first_name, last_name, job_title, department, email, avatar, active
  FROM users
  WHERE active=1
")->fetchAll(PDO::FETCH_ASSOC);

/* Index + children */
$all=[]; $children=[];
foreach ($rows as $r) { $all[(int)$r['id']] = $r; }
foreach ($rows as $r) {
  if ($r['manager_id']!==null) {
    $children[(int)$r['manager_id']][] = (int)$r['id'];
  }
}

/* Show user iff has a manager OR has children */
$displayable=[];
foreach ($all as $id=>$u) {
  if ($u['manager_id']!==null || !empty($children[$id])) $displayable[$id]=true;
}

/* Build payload */
$nodes=[]; $tree=[]; $roots=[];
foreach ($displayable as $id=>$_) {
  $u=$all[$id];
  $full=trim(($u['first_name']??'').' '.($u['last_name']??'')) ?: ($u['email']??('#'.$id));
  $initials=strtoupper(mb_substr($u['first_name']??'',0,1).mb_substr($u['last_name']??'',0,1)) ?: 'U';
  $nodes[$id]=[
    'id'=>$id,
    'manager_id'=>$u['manager_id']!==null?(int)$u['manager_id']:null,
    'name'=>$full,
    'job_title'=>$u['job_title']??'',
    'department'=>$u['department']??'',
    'email'=>$u['email']??'',
    'avatar'=>$u['avatar']??'',
    'initials'=>$initials
  ];
}
foreach ($children as $mid=>$kids) {
  $filtered=array_values(array_filter($kids, fn($cid)=>isset($displayable[$cid])));
  if ($filtered && isset($displayable[$mid])) $tree[$mid]=$filtered;
}
/* roots = no manager, but has children */
foreach ($nodes as $id=>$n) if ($n['manager_id']===null && !empty($tree[$id])) $roots[]=$id;

$payload=['nodes'=>$nodes,'tree'=>$tree,'roots'=>$roots];

include __DIR__ . '/partials/header.php';
?>
<div class="oc-shell">
  <div class="oc-head">
    <h3>Organisational Structure</h3>
    <div class="oc-metrics">
      <span class="badge">Roots: <?= count($roots) ?></span>
      <span class="badge">People: <?= count($nodes) ?></span>
    </div>
  </div>

  <div class="oc-body">
    <?php if (empty($roots)): ?>
      <div class="badge badge-warning" style="display:block">No org structure to display yet.</div>
    <?php else: ?>
      <div id="orgchart" class="oc"></div>
    <?php endif; ?>
  </div>
</div>

<script>
(() => {
  const DATA = <?= json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
  const byId = DATA.nodes, kids = DATA.tree, roots = DATA.roots;
  const host = document.getElementById('orgchart');
  if (!host) return;

  const el = (t,c,txt)=>{const e=document.createElement(t); if(c) e.className=c; if(txt) e.textContent=txt; return e;}

  function avatar(user){
    const wrap = el('div','card-ava');
    if (user.avatar) {
      const img = el('img','card-ava-img'); img.src=user.avatar; img.alt=user.name; wrap.appendChild(img);
    } else {
      wrap.appendChild(el('div','card-ava-fb', user.initials||'U'));
    }
    return wrap;
  }

  function personCard(user){
    const card = el('div','card');
    card.appendChild(avatar(user));
    card.appendChild(el('div','card-name', user.name));
    if (user.job_title) card.appendChild(el('div','card-title', user.job_title));
    if (user.department) card.appendChild(el('div','card-dept', user.department));

    if ((kids[user.id]||[]).length){
      const tog = el('button','card-toggle'); tog.setAttribute('aria-expanded','false');
      card.appendChild(tog);
      card.addEventListener('click', (e)=>{ if (e.target===tog) return; tog.click(); });
    }
    return card;
  }

  function renderNode(id){
    const u = byId[id];
    const node = el('div','node'); node.dataset.id=id;
    const card = personCard(u);
    node.appendChild(card);

    const childIds = kids[id] || [];
    if (childIds.length){
      const childrenWrap = el('div','children');   // collapsed by default
      childIds.forEach(cid=>{
        const col = el('div','child-col');
        col.appendChild(renderNode(cid));
        childrenWrap.appendChild(col);
      });
      node.appendChild(childrenWrap);

      const tog = card.querySelector('.card-toggle');
      tog.addEventListener('click', (e)=>{
        e.stopPropagation();
        const open = tog.getAttribute('aria-expanded')==='true';
        if (open){
          childrenWrap.style.display='none';
          node.classList.remove('open');
          tog.setAttribute('aria-expanded','false');
        } else {
          childrenWrap.style.display='flex';
          node.classList.add('open');
          tog.setAttribute('aria-expanded','true');
        }
      });
    }
    return node;
  }

  // Render roots and auto-expand only their direct children (second tier)
  roots.forEach(r=>{
    const node = renderNode(r);
    host.appendChild(node);

    const childrenWrap = node.querySelector(':scope > .children');
    const toggleBtn   = node.querySelector(':scope > .card .card-toggle');
    if (childrenWrap){
      childrenWrap.style.display = 'flex';   // open second tier
      node.classList.add('open');
      if (toggleBtn) toggleBtn.setAttribute('aria-expanded','true');
    }
  });
})();
</script>

<style>
/* ---------- Shell (keeps within the content area; no overlay) ---------- */
.oc-shell{
  width:100%;
  max-width:1400px;     /* large canvas but not full-bleed */
  margin: 0 auto;       /* center within the main content column */
  padding: 12px 18px 24px;
  position: static;     /* ensure it doesn't create overlay contexts */
  z-index: auto;
}
.oc-head{
  display:flex;
  align-items:center;
  justify-content:space-between;
  margin-bottom:12px;
}
.oc-head h3{ font-size:1.25rem; margin:0; }
.oc-metrics{ display:flex; gap:.5rem; align-items:center; }
.oc-body{
  display:flex;
  justify-content:center;
  align-items:flex-start;
  min-height:40vh;
  overflow:auto;        /* scroll inside the chart area if needed */
  position: static;
  z-index: auto;
}

/* ---------- Chart internals ---------- */
.oc{
  --line:#e5e7eb; --text:#1f2937; --muted:#6b7280;
  display:flex; flex-direction:column; align-items:center; gap:22px;
  position: static; z-index:auto;
}

/* Node */
.node{ display:flex; flex-direction:column; align-items:center; position:relative; }

/* Card */
.card{
  position:relative;
  width: 200px;
  background:#fff;
  border:1px solid #eef0f3;
  border-radius:16px;
  box-shadow: var(--shadow, 0 2px 4px rgba(0,0,0,.08));
  padding: 16px 14px 14px;
  text-align:center;
  cursor:pointer;
  transition: transform .06s ease, box-shadow .06s ease;
}
.card:hover{ transform: translateY(-1px); box-shadow: var(--shadow-lg, 0 10px 25px rgba(0,0,0,.12)); }

/* Avatar (clean, no border) */
.card-ava{ display:grid; place-items:center; margin-bottom:10px; }
.card-ava-img{ width:84px; height:84px; object-fit:cover; border-radius:50%; }
.card-ava-fb{
  width:84px; height:84px; border-radius:50%; display:grid; place-items:center;
  font-weight:800; font-size:24px; background:#f1f5f9; color:#334155;
}

/* Name + metadata */
.card-name{ font-weight:800; color:var(--text); line-height:1.2; margin-top:2px; }
.card-title{ font-size:.9rem; font-weight:600; color:var(--text); margin-top:2px; }
.card-dept{ font-size:.85rem; color:var(--muted); margin-top:2px; }

/* Toggle */
.card-toggle{
  position:absolute; right:10px; bottom:10px; width:26px; height:26px;
  border-radius:50%; border:1px solid #e5e7eb; background:#fff;
  display:grid; place-items:center;
}
.card-toggle::before{
  content:""; width:8px; height:8px;
  border-right:2px solid #6b7280; border-bottom:2px solid #6b7280;
  transform: rotate(45deg);
  transition:transform .12s ease;
}
.node:not(.open) > .card .card-toggle::before{ transform: rotate(-45deg); }

/* Children row with connectors */
.children{
  display:none;
  gap: 28px;
  margin-top: 18px;
  padding-top: 18px;
  position:relative;
  justify-content:center;
}
.children::before{
  content:""; position:absolute; top:0; left:10%; right:10%;
  height:2px; background:var(--line);
}
.node > .children::after{
  content:""; position:absolute; top:-18px; left:50%; transform:translateX(-50%);
  width:2px; height:18px; background:var(--line);
}
.child-col{ position:relative; display:flex; flex-direction:column; align-items:center; }
.child-col::before{
  content:""; position:absolute; top:-18px; left:50%; transform:translateX(-50%);
  width:2px; height:18px; background:var(--line);
}

/* Responsive */
@media (max-width:800px){
  .card{ width:180px; }
  .children{ gap:18px; }
}
</style>

<?php include __DIR__ . '/partials/footer.php'; ?>
