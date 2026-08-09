<?php
session_start();
require_once '../../Landing Page/php/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'administrator') {
    header('Location: ../../Landing Page/login.php'); exit;
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$words     = explode(' ', trim($user_name));
$initials  = strtoupper(substr($words[0],0,1).(isset($words[1])?substr($words[1],0,1):''));

/* ── CRUD ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $full_name   = trim($_POST['full_name']   ?? '');
        $employee_id = trim($_POST['employee_id'] ?? '');
        $email       = trim($_POST['email']       ?? '');
        $role        = in_array($_POST['role']??'',['branch_staff','administrator'])?$_POST['role']:'branch_staff';
        $branch      = trim($_POST['branch'] ?? '');
        $password    = password_hash(trim($_POST['password']??'password123'), PASSWORD_BCRYPT);
        $status      = 'offline';
        $stmt = $conn->prepare("INSERT INTO users (full_name,employee_id,email,password,branch,role,status) VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param('sssssss',$full_name,$employee_id,$email,$password,$branch,$role,$status);
        $stmt->execute(); $stmt->close();
        header('Location: users.php?flash=added'); exit;
    }

    if ($action === 'edit') {
        $id     = (int)($_POST['id']??0);
        $role   = in_array($_POST['role']??'',['branch_staff','administrator'])?$_POST['role']:'branch_staff';
        $branch = trim($_POST['branch']??'');
        $stmt   = $conn->prepare("UPDATE users SET role=?,branch=? WHERE id=?");
        $stmt->bind_param('ssi',$role,$branch,$id);
        $stmt->execute(); $stmt->close();
        header('Location: users.php?flash=edited'); exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id']??0);
        if ($id !== (int)$_SESSION['user_id']) {
            $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
            $stmt->bind_param('i',$id);
            $stmt->execute(); $stmt->close();
        }
        header('Location: users.php?flash=deleted'); exit;
    }
}

/* ── Users data ── */
$users = [];
$r = $conn->query("SELECT id,full_name,employee_id,email,branch,role,status FROM users ORDER BY role,full_name");
while ($row = $r->fetch_assoc()) $users[] = $row;

$total    = count($users);
$admins   = count(array_filter($users,fn($u)=>$u['role']==='administrator'));
$staff    = $total - $admins;
$online   = count(array_filter($users,fn($u)=>$u['status']==='online'));

/* ── Branch list ── */
$branches=[];
$r=$conn->query("SELECT DISTINCT UPPER(branch) b FROM users WHERE branch!='' ORDER BY b");
while($row=$r->fetch_row()) $branches[]=$row[0];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lucky 8 — Users</title>
<link rel="stylesheet" href="../styles/admin.css">
<link rel="stylesheet" href="../styles/users.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>
<?php include 'sidebar.php'; ?>

<div class="main" id="mainContent">
    <header class="topbar">
        <div style="font-size:15px;font-weight:700;color:#111827;">Users</div>
        <div class="topbar-right">
            <div class="icon-btn"><i class="fa-regular fa-bell"></i><span class="notif-dot"></span></div>
            <div class="user-chip"><?=htmlspecialchars($initials)?></div>
        </div>
    </header>

    <div class="page-content">

        <?php if(isset($_GET['flash'])):?>
        <div class="flash ok"><?=$_GET['flash']==='added'?'User added.':($_GET['flash']==='edited'?'User updated.':'User deleted.')?></div>
        <?php endif;?>

        <!-- KPIs -->
        <div class="kpi-grid" style="margin-bottom:20px;">
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Total Users</span><div class="kpi-icon orange"><i class="fa-solid fa-users"></i></div></div>
                <div class="kpi-value"><?=(int)$total?></div>
                <div class="kpi-meta">registered accounts</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Administrators</span><div class="kpi-icon" style="background:rgba(232,97,26,0.1);color:#e8611a;"><i class="fa-solid fa-user-shield"></i></div></div>
                <div class="kpi-value"><?=(int)$admins?></div>
                <div class="kpi-meta">admin accounts</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Branch Staff</span><div class="kpi-icon green"><i class="fa-solid fa-user-tie"></i></div></div>
                <div class="kpi-value"><?=(int)$staff?></div>
                <div class="kpi-meta">staff accounts</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Online</span><div class="kpi-icon" style="background:rgba(16,185,129,0.1);color:#10b981;"><i class="fa-solid fa-circle-check"></i></div></div>
                <div class="kpi-value"><?=(int)$online?></div>
                <div class="kpi-meta">currently online</div>
            </div>
        </div>

        <!-- Table -->
        <div class="chart-card">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
                <div><div class="chart-title">All Users</div><div class="chart-subtitle">Manage accounts and access roles</div></div>
                <button class="btn-orange" onclick="openAddModal()"><i class="fa-solid fa-plus"></i> Add User</button>
            </div>
            <table class="intel-table">
                <thead><tr>
                    <th>User</th><th>Employee ID</th><th>Email</th>
                    <th>Branch</th><th>Role</th><th>Status</th><th class="col-r">Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach($users as $u):
                    $w=explode(' ',trim($u['full_name']));
                    $ini=strtoupper(substr($w[0],0,1).(isset($w[1])?substr($w[1],0,1):''));
                    $rc=$u['role']==='administrator'?'role-admin':'role-staff';
                    $sc=$u['status']==='online'?'status-ok':'status-rej';
                ?>
                <tr>
                    <td><div style="display:flex;align-items:center;gap:10px;">
                        <div class="user-avatar-sm"><?=$ini?></div>
                        <div><div class="prod-name"><?=htmlspecialchars($u['full_name'])?></div></div>
                    </div></td>
                    <td class="col-mono"><?=htmlspecialchars($u['employee_id'])?></td>
                    <td><?=htmlspecialchars($u['email'])?></td>
                    <td><?=htmlspecialchars(strtoupper($u['branch']))?></td>
                    <td><span class="role-badge <?=$rc?>"><?=$u['role']==='administrator'?'Admin':'Staff'?></span></td>
                    <td><span class="status-badge <?=$sc?>"><?=ucfirst($u['status']??'offline')?></span></td>
                    <td class="col-r" style="white-space:nowrap;">
                        <button class="btn-ghost" onclick='openEditModal(<?=json_encode($u)?>)'>Edit</button>
                        <?php if((int)$u['id']!==(int)$_SESSION['user_id']):?>
                        <button class="btn-danger" onclick="confirmDelete(<?=(int)$u['id']?>, '<?=addslashes(htmlspecialchars($u['full_name']))?>')">Delete</button>
                        <?php endif;?>
                    </td>
                </tr>
                <?php endforeach;?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Modal -->
<div class="modal-bg" id="addModal">
    <div class="modal">
        <h3><i class="fa-solid fa-user-plus" style="color:#e8611a;margin-right:8px;"></i>Add User</h3>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-row">
                <div class="form-group"><label>Full Name</label><input name="full_name" required></div>
                <div class="form-group"><label>Employee ID</label><input name="employee_id" required></div>
            </div>
            <div class="form-group"><label>Email</label><input name="email" type="email" required></div>
            <div class="form-row">
                <div class="form-group"><label>Role</label>
                    <select name="role">
                        <option value="branch_staff">Branch Staff</option>
                        <option value="administrator">Administrator</option>
                    </select>
                </div>
                <div class="form-group"><label>Branch</label>
                    <select name="branch">
                        <option value="">— Select —</option>
                        <?php foreach($branches as $b):?><option><?=htmlspecialchars($b)?></option><?php endforeach;?>
                    </select>
                </div>
            </div>
            <div class="form-group"><label>Password</label><input name="password" type="password" placeholder="Min. 8 characters" required></div>
            <div class="modal-footer">
                <button type="button" class="btn-ghost" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn-orange">Add User</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal-bg" id="editModal">
    <div class="modal">
        <h3><i class="fa-solid fa-user-pen" style="color:#e8611a;margin-right:8px;"></i>Edit User</h3>
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="editId">
            <div class="form-group"><label>Name (read-only)</label><input id="editName" disabled style="background:#f9fafb;color:#9ca3af;"></div>
            <div class="form-row">
                <div class="form-group"><label>Role</label>
                    <select name="role" id="editRole">
                        <option value="branch_staff">Branch Staff</option>
                        <option value="administrator">Administrator</option>
                    </select>
                </div>
                <div class="form-group"><label>Status (live)</label>
                    <input id="editStatus" disabled style="background:#f9fafb;color:#9ca3af;">
                </div>
            </div>
            <div class="form-group"><label>Branch</label>
                <select name="branch" id="editBranch">
                    <option value="">— None —</option>
                    <?php foreach($branches as $b):?><option><?=htmlspecialchars($b)?></option><?php endforeach;?>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-ghost" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn-orange">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<form method="POST" id="deleteForm" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteId">
</form>

<script src="../src/users.js"></script>
</body>
</html>
