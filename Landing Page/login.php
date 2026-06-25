<?php
session_start();

$login_error   = $_SESSION['login_error']  ?? '';
$reg_error     = $_SESSION['reg_error']    ?? '';
$reg_success   = $_SESSION['reg_success']  ?? '';
unset($_SESSION['login_error'], $_SESSION['reg_error'], $_SESSION['reg_success']);

$active_tab = $_GET['tab'] ?? 'signin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lucky 8 Hydraulics Co. — Operations Console</title>
    <link rel="stylesheet" href="login.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>

    <!-- LEFT PANEL -->
    <div class="left-panel">
        <div class="left-overlay"></div>
        <div class="left-inner">

            <div class="logo-block">
                <div class="logo-badge">L8</div>
                <div class="logo-text">
                    <span class="logo-name">LUCKY 8</span>
                    <span class="logo-sub">HYDRAULICS CO.</span>
                </div>
            </div>

            <div class="left-content">
                <div class="ops-badge">
                    <span class="ops-dot"></span>
                    OPERATIONS CONSOLE
                </div>

                <h1 class="headline">
                    HYDRAULIC HOSE <span class="highlight">LUCKY 8</span>
                </h1>

                <p class="left-subtext">
                    Synchronize your operation at scale — seamless inventory management, rapid transaction processing, and intelligent forecasting across all branches.
                </p>

                <div class="feature-grid">
                    <div class="feature-card">
                        <i class="fa-solid fa-chart-line"></i>
                        <span>REALTIME SYNC</span>
                    </div>
                    <div class="feature-card">
                        <i class="fa-solid fa-bell"></i>
                        <span>PREDICTIVE ALERTS</span>
                    </div>
                    <div class="feature-card">
                        <i class="fa-solid fa-shield-halved"></i>
                        <span>AUDIT TRAIL</span>
                    </div>
                    <div class="feature-card">
                        <i class="fa-solid fa-building"></i>
                        <span>19 BRANCHES</span>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- RIGHT PANEL -->
    <div class="right-panel">

        <div class="right-topbar">
            <div class="status-ok">
                <span class="status-dot"></span>
                ALL SYSTEMS OPERATIONAL
            </div>
            <div class="it-support">
                <i class="fa-solid fa-headset"></i>
                IT Support
            </div>
        </div>

        <div class="right-content">
            <div class="tab-group">
                <button type="button" class="tab" id="tab-signin" onclick="switchTab('signin')">SIGN IN</button>
                <button type="button" class="tab" id="tab-register" onclick="switchTab('register')">REGISTER</button>
            </div>

            <form id="form-signin" class="form-section" method="POST" action="login_process.php" style="display:none;">

                <?php if ($login_error): ?>
                <div class="alert alert-error">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <?= htmlspecialchars($login_error) ?>
                </div>
                <?php endif; ?>

                <?php if ($reg_success): ?>
                <div class="alert alert-success">
                    <i class="fa-solid fa-circle-check"></i>
                    <?= htmlspecialchars($reg_success) ?>
                </div>
                <?php endif; ?>

                <h2 class="welcome-heading">WELCOME BACK, OPERATOR.</h2>
                <p class="welcome-sub">Sign in to access your branch console, POS, and live inventory.</p>

                <div class="form-group">
                    <label>WORK EMAIL</label>
                    <div class="input-wrapper">
                        <i class="fa-regular fa-envelope"></i>
                        <input type="email" name="email" placeholder="you@lucky8hydraulics.com" required>
                    </div>
                </div>

                <div class="form-group">
                    <div class="label-row">
                        <label>PASSWORD</label>
                        <a href="#" class="forgot-link">Forgot?</a>
                    </div>
                    <div class="input-wrapper">
                        <i class="fa-solid fa-lock"></i>
                        <input type="password" name="password" placeholder="••••••••" id="passwordInput" required>
                        <i class="fa-regular fa-eye toggle-pw" onclick="togglePassword()"></i>
                    </div>
                </div>

                <div class="form-extras">
                    <label class="checkbox-label">
                        <input type="checkbox" name="remember" value="1" checked>
                        Keep me signed in
                    </label>
                    <div class="secure-session">
                        <span class="secure-dot"></span>
                        SECURE SESSION
                    </div>
                </div>

                <button type="submit" class="signin-btn">SIGN IN &rarr;</button>

                <p class="bottom-link">New here? <a href="#" onclick="switchTab('register'); return false;">Register Here &rarr;</a></p>
            </form>

            <form id="form-register" class="form-section" method="POST" action="register.php" style="display:none;">

                <input type="hidden" name="branch" id="branchInput" value="">
                <input type="hidden" name="role"   id="roleInput"   value="branch_staff">

                <?php if ($reg_error): ?>
                <div class="alert alert-error">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <?= htmlspecialchars($reg_error) ?>
                </div>
                <?php endif; ?>

                <h2 class="welcome-heading">JOIN THE OPERATIONS CREW.</h2>
                <p class="welcome-sub">Submit your details — an Admin will verify and approve your access shortly.</p>

                <div class="form-row">
                    <div class="form-group">
                        <label>FULL NAME</label>
                        <div class="input-wrapper">
                            <i class="fa-regular fa-user"></i>
                            <input type="text" name="full_name" placeholder="Juan Dela Cruz" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>EMPLOYEE ID</label>
                        <div class="input-wrapper">
                            <i class="fa-regular fa-id-card"></i>
                            <input type="text" name="employee_id" placeholder="L8-2026-0042" required>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>WORK EMAIL</label>
                    <div class="input-wrapper">
                        <i class="fa-regular fa-envelope"></i>
                        <input type="email" name="email" placeholder="you@lucky8hydraulics.com" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>REQUESTED BRANCH</label>
                    <div class="custom-select" id="branchSelect">
                        <div class="custom-select-trigger" onclick="toggleBranchDropdown(event)">
                            <i class="fa-solid fa-location-dot"></i>
                            <span class="select-display" id="branchDisplay">Select one of 19 branches...</span>
                            <i class="fa-solid fa-chevron-down branch-arrow" id="branchArrow"></i>
                        </div>
                        <div class="custom-dropdown" id="branchDropdown">
                            <div class="dropdown-search-box">
                                <i class="fa-solid fa-magnifying-glass"></i>
                                <input type="text" id="branchSearch" placeholder="Search branch by code, city or region..." oninput="filterBranches()" onclick="event.stopPropagation()">
                            </div>
                            <div class="dropdown-list" id="branchList"></div>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>REQUESTED ROLE</label>
                    <div class="role-grid">
                        <div class="role-card active" onclick="selectRole(this, 'branch_staff')">
                            <div class="role-check"><i class="fa-solid fa-check"></i></div>
                            <div class="role-icon"><i class="fa-solid fa-store"></i></div>
                            <div class="role-title">BRANCH STAFF</div>
                            <div class="role-desc">Process local sales, view branch inventory, manage daily POS operations.</div>
                        </div>
                        <div class="role-card" onclick="selectRole(this, 'administrator')">
                            <div class="role-check"><i class="fa-solid fa-check"></i></div>
                            <div class="role-icon"><i class="fa-solid fa-shield-halved"></i></div>
                            <div class="role-title">ADMINISTRATOR</div>
                            <div class="role-desc">Full system access, cross-branch analytics, user approvals &amp; audit trail.</div>
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>PASSWORD</label>
                        <div class="input-wrapper">
                            <i class="fa-solid fa-lock"></i>
                            <input type="password" name="password" id="regPassword" placeholder="Min. 8 characters" required>
                            <i class="fa-regular fa-eye toggle-pw" onclick="toggleRegPassword('regPassword', this)"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>CONFIRM</label>
                        <div class="input-wrapper">
                            <i class="fa-solid fa-shield-halved"></i>
                            <input type="password" name="confirm_password" id="regConfirm" placeholder="Re-enter password" required>
                            <i class="fa-regular fa-eye toggle-pw" onclick="toggleRegPassword('regConfirm', this)"></i>
                        </div>
                    </div>
                </div>

                <div class="form-group agree-row">
                    <label class="checkbox-label">
                        <input type="checkbox" name="agree" value="1" required>
                        <span>I understand my account will remain in <strong>pending</strong> status until an Administrator verifies and approves it, and I agree to the system's audit policy.</span>
                    </label>
                </div>

                <button type="submit" class="signin-btn">REGISTER &rarr;</button>

                <p class="bottom-link">Already approved? <a href="#" onclick="switchTab('signin'); return false;">Sign in here &rarr;</a></p>
            </form>
        </div>

        <div class="right-footer">
            <span>&copy; 2026 Lucky 8 Hydraulics Co. &nbsp;&middot;&nbsp; <a href="#">Security Policy</a> &nbsp;&middot;&nbsp; <a href="#">Terms</a></span>
            <span class="encrypted"><i class="fa-solid fa-shield"></i> 256-BIT ENCRYPTED SESSION</span>
        </div>

    </div>

    <script>
        const initialTab = "<?= htmlspecialchars($active_tab) ?>";
    </script>
    <script src="login.js"></script>
</body>
</html>
