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

$_sb->close();

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
        <div class="user-avatar"><?=htmlspecialchars($initials)?></div>
        <div class="user-info">
            <div class="user-name"><?=htmlspecialchars($user_name)?></div>
            <div class="user-role">Administrator</div>
        </div>
        <a href="../Landing Page/login.php" class="logout-btn" title="Sign out">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
        </a>
    </div>
</aside>
<script>
(function(){
    var b=document.getElementById('sidebarBurger'),s=document.querySelector('.sidebar');
    if(!b||!s)return;
    b.addEventListener('click',function(){
        var c=s.classList.toggle('collapsed');
        var m=document.getElementById('mainContent')||document.querySelector('.main');
        if(m)m.classList.toggle('sidebar-collapsed',c);
    });
}());
</script>
