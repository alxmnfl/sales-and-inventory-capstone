<?php
require_once '../../Landing Page/php/auth.php';
require_once '../../Landing Page/php/db.php';
require_once '../../Landing Page/php/employee_id_helper.php';

['user_name' => $user_name, 'initials' => $initials] = require_admin();

// Super admins are the only ones who may create/edit/delete other administrator
// accounts (e.g. removing an admin who has resigned). Regular admins are limited
// to managing branch staff.
$is_super = !empty($_SESSION['is_super_admin']);

function remaining_super_admins(mysqli $conn, int $excludeId = 0): int {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE is_super_admin = 1 AND id <> ?");
    $stmt->bind_param('i', $excludeId);
    $stmt->execute();
    $stmt->bind_result($cnt);
    $stmt->fetch();
    $stmt->close();
    return (int) $cnt;
}

/* ── CRUD ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $full_name = trim($_POST['full_name'] ?? '');
        $email     = trim($_POST['email']     ?? '');
        $role      = in_array($_POST['role']??'',['branch_staff','administrator'])?$_POST['role']:'branch_staff';
        if ($role === 'administrator' && !$is_super) $role = 'branch_staff'; // only super admins can create admins
        $make_super = ($is_super && $role === 'administrator' && !empty($_POST['is_super_admin'])) ? 1 : 0;
        $branch    = trim($_POST['branch'] ?? '');
        $password  = password_hash(trim($_POST['password']??'password123'), PASSWORD_BCRYPT);
        $status    = 'offline';

        // Employee ID is generated server-side, never trusted from the form.
        $stmt = $conn->prepare("INSERT INTO users (full_name,employee_id,email,password,branch,role,is_super_admin,status) VALUES (?,?,?,?,?,?,?,?)");
        for ($try = 0; $try < 5; $try++) {
            $employee_id = next_employee_id($conn);
            $stmt->bind_param('ssssssis',$full_name,$employee_id,$email,$password,$branch,$role,$make_super,$status);
            if ($stmt->execute()) {
                $stmt->close();
                header('Location: users.php?flash=added'); exit;
            }
            if ($conn->errno !== 1062) break;   // 1062 = duplicate key; only a clashing ID is worth retrying
        }
        $stmt->close();
        header('Location: users.php?flash=error'); exit;
    }

    if ($action === 'edit') {
        $id = (int)($_POST['id']??0);

        $stmt = $conn->prepare("SELECT role, is_super_admin FROM users WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $target = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$target || ($target['role'] === 'administrator' && !$is_super)) {
            header('Location: users.php?flash=denied'); exit;
        }

        $role   = in_array($_POST['role']??'',['branch_staff','administrator'])?$_POST['role']:'branch_staff';
        if ($role === 'administrator' && !$is_super) $role = 'branch_staff';
        $branch = trim($_POST['branch']??'');
        $make_super = ($is_super && $role === 'administrator' && !empty($_POST['is_super_admin'])) ? 1 : 0;

        // Never let the very last super admin be demoted — someone has to be
        // left who can manage administrator accounts.
        if ((int)$target['is_super_admin'] === 1 && $make_super === 0 && remaining_super_admins($conn, $id) === 0) {
            $make_super = 1;
        }

        $stmt   = $conn->prepare("UPDATE users SET role=?,branch=?,is_super_admin=? WHERE id=?");
        $stmt->bind_param('ssii',$role,$branch,$make_super,$id);
        $stmt->execute(); $stmt->close();
        header('Location: users.php?flash=edited'); exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id']??0);
        if ($id !== (int)$_SESSION['user_id']) {
            $stmt = $conn->prepare("SELECT role, is_super_admin FROM users WHERE id=?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $target = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $allowed = $target && (!($target['role'] === 'administrator') || $is_super);
            // Refuse to delete the last remaining super admin, resigned or not —
            // that would leave nobody able to manage admin accounts at all.
            if ($allowed && (int)$target['is_super_admin'] === 1 && remaining_super_admins($conn, $id) === 0) {
                $allowed = false;
            }

            if ($allowed) {
                $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
                $stmt->bind_param('i',$id);
                $stmt->execute(); $stmt->close();
            } else {
                header('Location: users.php?flash=denied'); exit;
            }
        }
        header('Location: users.php?flash=deleted'); exit;
    }
}

/* ── Users data ── */
$users = [];
$r = $conn->query("SELECT id,full_name,employee_id,email,branch,role,is_super_admin,status FROM users ORDER BY role,full_name");
while ($row = $r->fetch_assoc()) $users[] = $row;

$total    = count($users);
$admins   = count(array_filter($users,fn($u)=>$u['role']==='administrator'));
$staff    = $total - $admins;
$online   = count(array_filter($users,fn($u)=>$u['status']==='online'));

/* ── Branch list ── */
// "ALL BRANCHES" is kept here — it is a valid assignment for administrators.
$branches = get_all_branches($conn, false);

/* ── Next auto-generated Employee ID (shown in the Add modal) ── */
$next_employee_id = next_employee_id($conn);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lucky 8 — Users</title>
<link rel="icon" type="image/jpeg" href="../../Images/background.jpg">
<link rel="stylesheet" href="../styles/admin.css?v=20260927e">
<link rel="stylesheet" href="../styles/users.css?v=20260927">
<link href="../../vendor/fonts/fonts.css" rel="stylesheet">
<link rel="stylesheet" href="../../vendor/fontawesome/css/all.min.css">
</head>
<body>
<?php include 'sidebar.php'; ?>

<div class="main" id="mainContent">
    <header class="topbar">
        <div style="font-size:15px;font-weight:700;color:#111827;">Users</div>
    </header>

    <div class="page-content">

        <?php if(isset($_GET['flash'])):
            $fl    = $_GET['flash'];
            $isErr = $fl === 'error' || $fl === 'denied';
            $msg   = $fl==='added'   ? 'User added.'
                   : ($fl==='edited'  ? 'User updated.'
                   : ($fl==='deleted' ? 'User deleted.'
                   : ($fl==='denied'  ? 'Only a super admin can manage another administrator\'s account.'
                   : 'Could not add user — that email may already be registered.')));
        ?>
        <div class="flash <?=$isErr?'err':'ok'?>"><?=$msg?></div>
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
                    $rowIsSuper=!empty($u['is_super_admin']);
                    $rc=$rowIsSuper?'role-super':($u['role']==='administrator'?'role-admin':'role-staff');
                    $roleLabel=$rowIsSuper?'Super Admin':($u['role']==='administrator'?'Admin':'Staff');
                    $sc=$u['status']==='online'?'status-ok':'status-rej';
                    // Only super admins may edit/delete other administrator accounts
                    // (e.g. removing one who has resigned); everyone can still manage staff.
                    $canManageRow = $u['role']!=='administrator' || $is_super;
                ?>
                <tr>
                    <td><div style="display:flex;align-items:center;gap:10px;">
                        <div class="user-avatar-sm"><?=$ini?></div>
                        <div><div class="prod-name"><?=htmlspecialchars($u['full_name'])?></div></div>
                    </div></td>
                    <td class="col-mono"><?=htmlspecialchars($u['employee_id'])?></td>
                    <td><?=htmlspecialchars($u['email'])?></td>
                    <td><?=htmlspecialchars(strtoupper($u['branch']))?></td>
                    <td><span class="role-badge <?=$rc?>"><?=$roleLabel?></span></td>
                    <td><span class="status-badge <?=$sc?>"><?=ucfirst($u['status']??'offline')?></span></td>
                    <td class="col-r" style="white-space:nowrap;">
                        <?php if($canManageRow):?>
                        <button class="btn-ghost" onclick='openEditModal(<?=json_encode($u)?>)'>Edit</button>
                        <?php if((int)$u['id']!==(int)$_SESSION['user_id']):?>
                        <button class="btn-danger" onclick="confirmDelete(<?=(int)$u['id']?>, '<?=addslashes(htmlspecialchars($u['full_name']))?>')">Delete</button>
                        <?php endif;?>
                        <?php else:?>
                        <span class="row-locked-hint" title="Only a super admin can manage another administrator's account">Super admin only</span>
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
                <div class="form-group"><label>Full Name</label><input name="full_name" id="addFullName" required></div>
                <div class="form-group">
                    <label>Employee ID <span class="hint">auto-generated</span></label>
                    <input name="employee_id" value="<?=htmlspecialchars($next_employee_id)?>" readonly>
                </div>
            </div>
            <div class="form-group"><label>Email <span class="hint">auto-generated, editable</span></label><input name="email" id="addEmail" type="email" required></div>
            <div class="form-row">
                <div class="form-group"><label>Role</label>
                    <div class="cselect" id="addRoleSelect">
                        <button type="button" class="cselect-trigger">
                            <span class="cselect-value" data-placeholder="Select role">Branch Staff</span>
                            <i class="fa-solid fa-chevron-down"></i>
                        </button>
                        <input type="hidden" name="role" value="branch_staff">
                        <ul class="cselect-list">
                            <li data-value="branch_staff" class="selected">Branch Staff</li>
                            <?php if($is_super):?>
                            <li data-value="administrator">Administrator</li>
                            <?php endif;?>
                        </ul>
                    </div>
                </div>
                <div class="form-group"><label>Branch</label>
                    <div class="cselect" id="addBranchSelect">
                        <button type="button" class="cselect-trigger">
                            <span class="cselect-value placeholder" data-placeholder="— Select —">— Select —</span>
                            <i class="fa-solid fa-chevron-down"></i>
                        </button>
                        <input type="hidden" name="branch" value="">
                        <ul class="cselect-list">
                            <?php foreach($branches as $b):?>
                            <li data-value="<?=htmlspecialchars($b)?>"><?=htmlspecialchars($b)?></li>
                            <?php endforeach;?>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="form-group"><label>Password</label>
                <div class="pw-wrap">
                    <input name="password" id="addPassword" type="password" placeholder="Min. 8 characters" minlength="8" required>
                    <i class="fa-solid fa-eye pw-toggle" onclick="togglePw('addPassword', this)" title="Show password"></i>
                </div>
            </div>
            <?php if($is_super):?>
            <label class="super-toggle">
                <input type="checkbox" name="is_super_admin" value="1">
                Grant Super Admin privileges <span class="hint">(can manage other administrator accounts)</span>
            </label>
            <?php endif;?>
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
                    <div class="cselect" id="editRoleSelect">
                        <button type="button" class="cselect-trigger">
                            <span class="cselect-value" data-placeholder="Select role">Branch Staff</span>
                            <i class="fa-solid fa-chevron-down"></i>
                        </button>
                        <input type="hidden" name="role" value="branch_staff">
                        <ul class="cselect-list">
                            <li data-value="branch_staff">Branch Staff</li>
                            <?php if($is_super):?>
                            <li data-value="administrator">Administrator</li>
                            <?php endif;?>
                        </ul>
                    </div>
                </div>
                <div class="form-group"><label>Status (live)</label>
                    <input id="editStatus" disabled style="background:#f9fafb;color:#9ca3af;">
                </div>
            </div>
            <div class="form-group"><label>Branch</label>
                <div class="cselect" id="editBranchSelect">
                    <button type="button" class="cselect-trigger">
                        <span class="cselect-value placeholder" data-placeholder="— None —">— None —</span>
                        <i class="fa-solid fa-chevron-down"></i>
                    </button>
                    <input type="hidden" name="branch" value="">
                    <ul class="cselect-list">
                        <?php foreach($branches as $b):?>
                        <li data-value="<?=htmlspecialchars($b)?>"><?=htmlspecialchars($b)?></li>
                        <?php endforeach;?>
                    </ul>
                </div>
            </div>
            <?php if($is_super):?>
            <label class="super-toggle">
                <input type="checkbox" name="is_super_admin" id="editIsSuper" value="1">
                Grant Super Admin privileges <span class="hint">(can manage other administrator accounts)</span>
            </label>
            <?php endif;?>
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

<script src="../src/modal-helpers.js?v=20260927"></script>
<script src="../src/users.js?v=20260927"></script>
</body>
</html>
