<?php
require_once 'auth.php';

$login_error = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);
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

    <link rel="stylesheet" href="../style/login.css?v=20260927d">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <link rel="icon" type="image/jpeg" href="../../Images/background.jpg">
</head>
<body>

    <!-- PAGE LOADER -->
    <div class="page-loader" id="pageLoader">
        <div class="page-loader-logo">
            <div class="page-loader-badge">LC</div>
            <div class="page-loader-text">
                <span class="page-loader-name">LUCKY CHARM</span>
                <span class="page-loader-sub">HYDRAULIC HOSE &amp; INDUSTRIAL SALES CO.</span>
            </div>
        </div>
        <div class="page-loader-bar"></div>
        <span class="page-loader-status">Loading console&hellip;</span>
    </div>

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
                        <div class="feature-icon-chip"><i class="fa-solid fa-chart-line"></i></div>
                        <span>REALTIME SYNC</span>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon-chip"><i class="fa-solid fa-bell"></i></div>
                        <span>PREDICTIVE ALERTS</span>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon-chip"><i class="fa-solid fa-shield-halved"></i></div>
                        <span>AUDIT TRAIL</span>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon-chip"><i class="fa-solid fa-building"></i></div>
                        <span>18 BRANCHES</span>
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
            <form id="form-signin" class="form-section" method="POST" action="login_process.php">

                <?php if ($login_error): ?>
                <div class="alert alert-error">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <?= htmlspecialchars($login_error) ?>
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

                <div class="trust-strip">
                    <div class="trust-item">
                        <i class="fa-solid fa-bolt"></i>
                        <span>REAL-TIME INVENTORY</span>
                    </div>
                    <div class="trust-item">
                        <i class="fa-solid fa-shield-halved"></i>
                        <span>ROLE-BASED ACCESS</span>
                    </div>
                </div>
            </form>
        </div>

        <div class="right-footer">
            <span>&copy; 2026 Lucky 8 Hydraulics Co. &nbsp;&middot;&nbsp; <a href="#">Security Policy</a> &nbsp;&middot;&nbsp; <a href="#">Terms</a></span>
            <span class="encrypted"><i class="fa-solid fa-shield"></i> 256-BIT ENCRYPTED SESSION</span>
        </div>

    </div>

    <script src="../src/login.js?v=20260926d"></script>
</body>
</html>
