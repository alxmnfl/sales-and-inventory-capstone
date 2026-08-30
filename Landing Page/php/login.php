<?php
require_once 'auth.php';
require_once 'employee_id_helper.php';

$login_error   = $_SESSION['login_error']  ?? '';
$reg_error     = $_SESSION['reg_error']    ?? '';
$reg_success   = $_SESSION['reg_success']  ?? '';
unset($_SESSION['login_error'], $_SESSION['reg_error'], $_SESSION['reg_success']);

$active_tab       = $_GET['tab'] ?? 'signin';
$next_employee_id = next_employee_id($conn);
$conn->close();

// A valid persistent-login token is restored by auth.php before any HTML is sent.
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['user_role'] === 'administrator') {
        header('Location: ../../Admin%20Console/php/index.php');
    } else {
        header('Location: ../../POS/php/index.php');
    }
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lucky Charm — Hydraulic Hose &amp; Industrial Sales Co.</title>

    <link rel="stylesheet" href="../style/login.css">
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
                <div class="logo-badge">LC</div>
                <div class="logo-text">
                    <span class="logo-name">LUCKY CHARM</span>
                    <span class="logo-sub">HYDRAULIC HOSE &amp; INDUSTRIAL SALES CO.</span>
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

                <button type="submit" class="signin-btn">SIGN IN</button>

                <p class="bottom-link">New here? <a href="#" onclick="switchTab('register'); return false;">Register Here</a></p>
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

                <h2 class="welcome-heading">Join The Operations Crew.</h2>
                <p class="welcome-sub">Fill in your details below to set up your account</p>

                <div class="form-row">
                    <div class="form-group">
                        <label>Full Name</label>
                        <div class="input-wrapper">
                            <i class="fa-regular fa-user"></i>
                            <input type="text" name="full_name" placeholder="Juan Dela Cruz" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Employee ID <span style="font-weight:400;opacity:.7;">(auto-generated)</span></label>
                        <div class="input-wrapper">
                            <i class="fa-regular fa-id-card"></i>
                            <input type="text" value="<?= htmlspecialchars($next_employee_id) ?>" disabled>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Work Email</label>
                    <div class="input-wrapper">
                        <i class="fa-regular fa-envelope"></i>
                        <input type="email" name="email" placeholder="you@lucky8hydraulics.com" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Select Branch</label>
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

                <div class="form-row">
                    <div class="form-group">
                        <label>Password</label>
                        <div class="input-wrapper">
                            <i class="fa-solid fa-lock"></i>
                            <input type="password" name="password" id="regPassword" placeholder="Min. 8 characters" required>
                            <i class="fa-regular fa-eye toggle-pw" onclick="toggleRegPassword('regPassword', this)"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Confirm</label>
                        <div class="input-wrapper">
                            <i class="fa-solid fa-shield-halved"></i>
                            <input type="password" name="confirm_password" id="regConfirm" placeholder="Re-enter password" required>
                            <i class="fa-regular fa-eye toggle-pw" onclick="toggleRegPassword('regConfirm', this)"></i>
                        </div>
                    </div>
                </div>

                <button type="submit" class="signin-btn">Register</button>

                <p class="bottom-link">Already have an account? <a href="#" onclick="switchTab('signin'); return false;">Sign in here</a></p>
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
    <script src="../src/login.js"></script>
</body>
</html>
