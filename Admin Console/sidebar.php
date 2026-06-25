<?php
/* sidebar.php — shared sidebar included by every admin page.
   Requires: session started, DB_HOST/USER/PASS/NAME constants available (from db.php). */
$_sbar_page = basename($_SERVER['PHP_SELF']);

$_sb = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$_sb->set_charset('utf8mb4');

// Badge: total branch staff count
$_staff_cnt = 0;
$_r = $_sb->query("SELECT COUNT(*) FROM users WHERE role='branch_staff'");
if ($_r) $_staff_cnt = (int)$_r->fetch_row()[0];

// Badge: products at risk of stockout in < 7 days
$_risk_cnt = 0;
$_r = $_sb->query("
    SELECT COUNT(DISTINCT p.id) FROM pos_products p
    INNER JOIN (
        SELECT si.product_id, SUM(si.quantity)/30.0 AS avg_d
        FROM pos_sale_items si
        JOIN pos_sales s ON si.sale_id=s.id
        WHERE s.created_at >= DATE_SUB(NOW(),INTERVAL 30 DAY)
        GROUP BY si.product_id
    ) v ON p.id=v.product_id
    WHERE v.avg_d>0 AND (p.stock/v.avg_d)<7
");
if ($_r) $_risk_cnt = (int)$_r->fetch_row()[0];

// Recent activity for notification bell (last 5 audit entries)
$_notif_items = [];
$_r = $_sb->query("SELECT action, entity_name, user_name, branch, created_at FROM audit_trail ORDER BY created_at DESC LIMIT 5");
if ($_r) while ($_row = $_r->fetch_assoc()) $_notif_items[] = $_row;

$_sb->close();

// User info for profile dropdown (read from session)
$_sb_user_name = $_SESSION['user_name'] ?? 'Admin';
$_sb_words     = explode(' ', trim($_sb_user_name));
$_sb_initials  = strtoupper(substr($_sb_words[0],0,1).(isset($_sb_words[1])?substr($_sb_words[1],0,1):''));

$_nav = [
    ['index.php',       'fa-gauge-high',          'Dashboard',      0,            ''],
    ['inventory.php',   'fa-boxes-stacked',       'Inventory',      0,            ''],
    ['sales.php',       'fa-chart-line',          'Sales',          0,            ''],
    ['branches.php',    'fa-building',            'Branches',       0,            ''],
    ['users.php',       'fa-users',               'Users',          $_staff_cnt,  ''],
    ['forecasts.php',   'fa-wand-magic-sparkles', 'Forecasts',      $_risk_cnt,   'blue'],
    ['movement.php',    'fa-shuffle',             'Movement Intel', 0,            ''],
    ['audit-trail.php', 'fa-shield-halved',       'Audit Trail',    0,            ''],
    ['reports.php',     'fa-chart-bar',           'Reports',        0,            ''],
];
?>
<aside class="sidebar">
    <div class="sidebar-logo">
        <div class="logo-badge">L8</div>
        <div class="logo-text">
            <span class="logo-name">LUCKY 8</span>
            <span class="logo-sub">ADMIN CONSOLE</span>
        </div>
        <button class="sidebar-burger" id="sidebarBurger" title="Toggle menu">
            <i class="fa-solid fa-bars"></i>
        </button>
    </div>
    <nav class="sidebar-nav">
        <?php foreach ($_nav as [$_f,$_ic,$_lb,$_bd,$_bc]): ?>
        <a href="<?=$_f?>" class="nav-item<?=$_sbar_page===$_f?' active':''?>">
            <i class="nav-icon fa-solid <?=$_ic?>"></i>
            <span class="nav-text"><?=$_lb?></span>
            <?php if($_bd>0):?><span class="nav-badge<?=$_bc?" $_bc":''?>"><?=$_bd?></span><?php endif;?>
        </a>
        <?php endforeach;?>
    </nav>
    <div class="sidebar-footer">
        <div class="user-avatar"><?=htmlspecialchars($_sb_initials)?></div>
        <div class="user-info">
            <div class="user-name"><?=htmlspecialchars($_sb_user_name)?></div>
            <div class="user-role">Administrator</div>
        </div>
        <a href="../Landing Page/login.php" class="logout-btn" title="Sign out">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
        </a>
    </div>
<?php
/* ── Pre-build inner HTML in PHP so json_encode() handles all escaping ── */

// Notification panel inner HTML
ob_start(); ?>
<div class="topbar-drop-header">Recent Activity</div>
<?php if(empty($_notif_items)): ?>
<div class="topbar-drop-empty"><i class="fa-regular fa-bell-slash"></i>No recent activity</div>
<?php else: foreach($_notif_items as $_ni):
    $_ni_class = strpos($_ni['action'],'DELETE')!==false?'notif-dot-del':(strpos($_ni['action'],'ADD')!==false?'notif-dot-add':(strpos($_ni['action'],'EDIT')!==false?'notif-dot-edit':'notif-dot-sale'));
    $_ni_icon  = strpos($_ni['action'],'DELETE')!==false?'fa-trash':(strpos($_ni['action'],'ADD')!==false?'fa-plus':(strpos($_ni['action'],'EDIT')!==false?'fa-pen':'fa-receipt'));
?>
<div class="notif-entry">
    <div class="notif-entry-action"><i class="fa-solid <?=$_ni_icon?> <?=$_ni_class?>"></i><?=htmlspecialchars($_ni['action']??'')?></div>
    <div class="notif-entry-meta"><?=htmlspecialchars($_ni['entity_name']??'—')?> &mdash; <?=htmlspecialchars($_ni['user_name']??'')?> &bull; <?=htmlspecialchars($_ni['created_at']??'')?></div>
</div>
<?php endforeach; endif; ?>
<a href="audit-trail.php" class="topbar-drop-item" style="border-top:1px solid #f3f4f6;">
    <i class="fa-solid fa-shield-halved"></i>View Full Audit Trail
</a>
<?php $_notif_inner = ob_get_clean();

// User profile panel inner HTML
ob_start(); ?>
<div class="topbar-drop-user">
    <div class="topbar-drop-avatar"><?=htmlspecialchars($_sb_initials)?></div>
    <div>
        <div class="topbar-drop-name"><?=htmlspecialchars($_sb_user_name)?></div>
        <div class="topbar-drop-role">Administrator</div>
    </div>
</div>
<a href="index.php" class="topbar-drop-item"><i class="fa-solid fa-gauge-high"></i>Dashboard</a>
<a href="users.php" class="topbar-drop-item"><i class="fa-solid fa-users"></i>Manage Users</a>
<a href="../Landing Page/login.php" class="topbar-drop-item danger"><i class="fa-solid fa-arrow-right-from-bracket"></i>Sign Out</a>
<?php $_user_inner = ob_get_clean(); ?>
</aside>
<script>
(function(){
    /* ── Sidebar burger toggle ── */
    var b=document.getElementById('sidebarBurger'),s=document.querySelector('.sidebar');
    if(b&&s){
        b.addEventListener('click',function(){
            var c=s.classList.toggle('collapsed');
            var m=document.getElementById('mainContent')||document.querySelector('.main');
            if(m)m.classList.toggle('sidebar-collapsed',c);
        });
    }

    /* ── Topbar dropdowns — appended to body, positioned via getBoundingClientRect ── */
    document.addEventListener('DOMContentLoaded',function(){
        var notifBtn = document.querySelector('.icon-btn');
        var userChip = document.querySelector('.user-chip');
        if(!notifBtn||!userChip) return;

        // Create both panels as body children (avoids overflow/positioning issues inside small elements)
        var notifDrop = document.createElement('div');
        notifDrop.id = 'notifDrop';
        notifDrop.className = 'topbar-drop';
        notifDrop.innerHTML = <?=json_encode($_notif_inner)?>;
        document.body.appendChild(notifDrop);

        var userDrop = document.createElement('div');
        userDrop.id = 'userDrop';
        userDrop.className = 'topbar-drop';
        userDrop.innerHTML = <?=json_encode($_user_inner)?>;
        document.body.appendChild(userDrop);

        function positionBelow(drop, trigger) {
            var r = trigger.getBoundingClientRect();
            drop.style.top   = (r.bottom + 8) + 'px';
            drop.style.right = (window.innerWidth - r.right) + 'px';
            drop.style.left  = 'auto';
        }

        notifBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            var wasOpen = notifDrop.classList.contains('open');
            notifDrop.classList.remove('open');
            userDrop.classList.remove('open');
            if(!wasOpen){ positionBelow(notifDrop, notifBtn); notifDrop.classList.add('open'); }
        });

        userChip.addEventListener('click', function(e) {
            e.stopPropagation();
            var wasOpen = userDrop.classList.contains('open');
            notifDrop.classList.remove('open');
            userDrop.classList.remove('open');
            if(!wasOpen){ positionBelow(userDrop, userChip); userDrop.classList.add('open'); }
        });

        document.addEventListener('click', function() {
            notifDrop.classList.remove('open');
            userDrop.classList.remove('open');
        });

        // Close on scroll/resize so position stays accurate
        window.addEventListener('resize', function() {
            notifDrop.classList.remove('open');
            userDrop.classList.remove('open');
        });
    });
}());
</script>
