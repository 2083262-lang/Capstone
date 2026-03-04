<?php
// ── Session cookie hardening (must be set before session_start) ──
ini_set('session.use_strict_mode', 1);      // Reject uninitialized session IDs
ini_set('session.use_only_cookies', 1);     // Never pass session ID via URL
ini_set('session.cookie_httponly', 1);      // Block JS access to session cookie
ini_set('session.cookie_samesite', 'Lax');  // Mitigate CSRF via cookie scope
// ini_set('session.cookie_secure', 1);     // Uncomment when using HTTPS in production

session_start();
include 'connection.php'; // Ensure this file establishes your $conn (MySQLi) connection
require_once __DIR__ . '/config/paths.php';
$error_message = '';
$registration_notice = '';
$profile_notice = '';
if (isset($_GET['registered']) && $_GET['registered'] == '1') {
    $registration_notice = "Registration successful! Please log in with your credentials to complete your agent profile.";
}
if (isset($_GET['profile_submitted']) && $_GET['profile_submitted'] == '1') {
    $profile_notice = "Your profile has been submitted for review. Please wait for admin approval before logging in. Check your email for details.";
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (empty($username) || empty($password)) {
        $error_message = "Both username and password are required.";
    } else {
        // Prepare the SQL query to get account details and role name
        $stmt = $conn->prepare("SELECT a.account_id, a.password_hash, a.email, a.first_name, a.last_name, ur.role_name, a.is_active 
                                FROM accounts a
                                JOIN user_roles ur ON a.role_id = ur.role_id
                                WHERE a.username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();

            // Verify password
            if (password_verify($password, $user['password_hash'])) {
                // Check if account is active
                if ($user['is_active']) {
                    // Determine post-2FA redirect based on role
                    $redirect_to = '';
                    if ($user['role_name'] == 'admin') {
                        $redirect_to = 'admin_dashboard.php';
                    } elseif ($user['role_name'] == 'agent') {
                        // Agent-specific logic (keep pre-2FA gating for pending approval)
                        $agent_info_stmt = $conn->prepare("SELECT profile_completed, is_approved FROM agent_information WHERE account_id = ?");
                        $agent_info_stmt->bind_param("i", $user['account_id']);
                        $agent_info_stmt->execute();
                        $agent_info_result = $agent_info_stmt->get_result();

                        if ($agent_info_result->num_rows == 0) {
                            $redirect_to = 'agent_info_form.php';
                        } else {
                            $agent_details = $agent_info_result->fetch_assoc();
                            if ((int)$agent_details['profile_completed'] === 0) {
                                $redirect_to = 'agent_info_form.php';
                            } elseif ((int)$agent_details['is_approved'] === 0) {
                                // Block login until approved (no 2FA yet)
                                $error_message = "Your agent account is pending approval by an administrator.";
                            } else {
                                $redirect_to = 'agent_pages/agent_dashboard.php';
                            }
                        }
                        $agent_info_stmt->close();
                    }

                    if (empty($error_message)) {
                        // Start 2FA: stash minimal identity in session and go to 2FA page
                        $_SESSION['pending_login'] = [
                            'account_id' => $user['account_id'],
                            'username' => $username,
                            'user_role' => $user['role_name'],
                            'email' => $user['email'] ?? null,
                            'first_name' => $user['first_name'] ?? null,
                            'last_name' => $user['last_name'] ?? null,
                            'redirect_to' => $redirect_to ?: 'login.php',
                            'created_at' => time() // Pending-login expiration timestamp
                        ];
                        // Generate CSRF token for 2FA flow
                        $_SESSION['twofa_csrf_token'] = bin2hex(random_bytes(32));
                        // Reset 2FA auto-send guard so the code is sent once on first arrival
                        if (isset($_SESSION['twofa_init_sent'])) {
                            unset($_SESSION['twofa_init_sent']);
                        }
                        header("Location: two_factor.php");
                        exit();
                    }
                } else {
                    $error_message = "Your account is inactive. Please contact support.";
                }
            } else {
                $error_message = "Invalid username or password.";
            }
        } else {
            $error_message = "Invalid username or password.";
        }
        $stmt->close();
    }
    $conn->close(); 
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - HomeEstate Realty</title>
    <link href="<?= ASSETS_CSS ?>bootstrap.min.css" rel="stylesheet">
    <link href="<?= ASSETS_CSS ?>inter-font.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= ASSETS_CSS ?>fontawesome-all.min.css">
    <style>
        :root {
            /* Primary Brand Colors */
            --gold: #d4af37;
            --gold-light: #f4d03f;
            --gold-dark: #b8941f;
            --blue: #2563eb;
            --blue-light: #3b82f6;
            --blue-dark: #1e40af;
            
            /* Neutral Palette */
            --black: #0a0a0a;
            --black-light: #111111;
            --black-lighter: #1a1a1a;
            --black-border: #1f1f1f;
            --white: #ffffff;
            
            /* Semantic Grays */
            --gray-50: #f8f9fa;
            --gray-100: #e8e9eb;
            --gray-200: #d1d4d7;
            --gray-300: #b8bec4;
            --gray-400: #9ca4ab;
            --gray-500: #7a8a99;
            --gray-600: #5d6d7d;
            --gray-700: #3f4b56;
            --gray-800: #2a3138;
            --gray-900: #1a1f24;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body, html {
            height: 100%;
            margin: 0;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background-color: var(--black);
            color: var(--white);
            line-height: 1.6;
        }

        .main-container {
            display: flex;
            min-height: 100vh;
            width: 100%;
            background:
                radial-gradient(circle at 20% 30%, rgba(37, 99, 235, 0.07) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(212, 175, 55, 0.06) 0%, transparent 50%),
                linear-gradient(rgba(10, 10, 10, 0.78), rgba(10, 10, 10, 0.85)),
                url('images/login-bg.jpg') center/cover no-repeat fixed;
            justify-content: center;
            align-items: center;
            padding: 2rem;
            overflow-y: auto;
            position: relative;
        }

        /* Subtle particle-like dots overlay */
        .main-container::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image:
                radial-gradient(circle, rgba(212,175,55,0.08) 1px, transparent 1px),
                radial-gradient(circle, rgba(37,99,235,0.05) 1px, transparent 1px);
            background-size: 60px 60px, 90px 90px;
            background-position: 0 0, 30px 30px;
            pointer-events: none;
            z-index: 0;
        }

        .form-section {
            display: flex;
            justify-content: center;
            align-items: center;
            width: 100%;
            position: relative;
            z-index: 1;
        }

        .form-section::before { display: none; }

        .login-wrapper {
            width: 100%;
            max-width: 860px;
            display: flex;
            overflow: hidden;
            position: relative;
            z-index: 1;
        }

        /* ── Left branding panel ── */
        .login-brand-panel {
            width: 300px;
            flex-shrink: 0;
            background: linear-gradient(160deg,
                rgba(212,175,55,0.12) 0%,
                rgba(37,99,235,0.08) 60%,
                rgba(10,10,10,0.2) 100%);
            border-right: 1px solid rgba(212, 175, 55, 0.12);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 48px 32px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .login-brand-panel::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at 50% 30%, rgba(212,175,55,0.15) 0%, transparent 60%),
                radial-gradient(circle at 50% 80%, rgba(37,99,235,0.1) 0%, transparent 50%);
            pointer-events: none;
        }
        .login-brand-panel img {
            width: 80px;
            height: auto;
            filter: drop-shadow(0 6px 18px rgba(212,175,55,0.45));
            margin-bottom: 20px;
            position: relative;
        }
        .login-brand-panel .brand-name {
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 3.5px;
            text-transform: uppercase;
            color: var(--gold);
            margin-bottom: 16px;
            position: relative;
        }
        .login-brand-panel h2 {
            font-size: 1.55rem;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 12px;
            line-height: 1.3;
            position: relative;
        }
        .login-brand-panel p {
            font-size: 0.82rem;
            color: var(--gray-400);
            line-height: 1.7;
            position: relative;
        }
        .brand-gold-line {
            width: 40px;
            height: 3px;
            background: linear-gradient(90deg, transparent, var(--gold), transparent);
            border-radius: 2px;
            margin: 16px auto;
            position: relative;
        }

        /* ── Right form panel ── */
        .login-form-panel {
            flex: 1;
            padding: 48px 44px;
        }

        .login-form-panel h1 {
            font-weight: 700;
            color: var(--white);
            margin-bottom: 0.5rem;
            font-size: 1.75rem;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        }

        .login-form-panel > p {
            color: var(--gray-400);
            margin-bottom: 28px;
            font-size: 0.9rem;
        }

        .login-wrapper .form-label {
            font-weight: 500;
            color: var(--gray-300);
            margin-bottom: 8px;
        }

        .login-wrapper .form-control {
            height: 46px;
            border-radius: 2px;
            border: 1px solid rgba(37, 99, 235, 0.3);
            background: rgba(10, 10, 10, 0.6);
            color: var(--white);
            transition: all 0.3s ease;
        }

        .login-wrapper .form-control:focus {
            border-color: var(--blue);
            background: rgba(10, 10, 10, 0.8);
            box-shadow: 0 0 0 0.25rem rgba(37, 99, 235, 0.15),
                        0 4px 16px rgba(37, 99, 235, 0.2);
            color: var(--white);
        }

        .login-wrapper .form-control::placeholder {
            color: var(--gray-600);
        }

        .login-btn {
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 50%, var(--gold-dark) 100%);
            color: var(--black);
            border: none;
            padding: 14px;
            border-radius: 2px;
            font-weight: 700;
            width: 100%;
            transition: all 0.3s ease;
            font-size: 16px;
            box-shadow: 0 4px 16px rgba(212, 175, 55, 0.25), 
                        0 0 0 1px rgba(212, 175, 55, 0.2);
            position: relative;
            overflow: hidden;
        }

        .login-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s ease;
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(212, 175, 55, 0.4), 
                        0 0 0 1px rgba(212, 175, 55, 0.4),
                        0 0 30px rgba(212, 175, 55, 0.2);
            color: var(--black);
        }

        .login-btn:hover::before {
            left: 100%;
        }

        .alert-danger {
            background: rgba(220, 53, 69, 0.15);
            border: 1px solid rgba(220, 53, 69, 0.3);
            border-radius: 2px;
            color: #ff6b6b;
            padding: 12px 16px;
            margin-bottom: 24px;
        }

        /* Decorative gold line above form title */
        .login-divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 28px;
        }
        .login-divider::before,
        .login-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(212, 175, 55, 0.3), transparent);
        }
        .login-divider span {
            font-size: 0.7rem;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: var(--gold);
            font-weight: 600;
            white-space: nowrap;
        }

        .image-section { display: none; }

        .register-link {
            text-align: center;
            margin-top: 1.5rem;
            font-size: 0.9rem;
            color: var(--gray-400);
        }

        .register-link a {
            color: var(--gold);
            font-weight: 600;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .register-link a:hover {
            color: var(--gold-light);
            text-decoration: none;
        }

        .small {
            color: var(--gray-500);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .small:hover {
            color: var(--blue-light);
        }

        /* Responsive: collapse to single column on small screens */
        @media (max-width: 680px) {
            .login-brand-panel { display: none; }
            .login-form-panel { padding: 36px 28px; }
        }

        /* ===== Enhanced Design System ===== */

        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: var(--black); }
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, var(--gold-dark), var(--gold), var(--gold-dark));
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, var(--gold), var(--gold-light), var(--gold));
        }
        ::-webkit-scrollbar-corner { background: var(--black); }
        html { scrollbar-width: thin; scrollbar-color: var(--gold-dark) var(--black); }

        /* Glassmorphism Card */
        .login-wrapper {
            background: rgba(17, 17, 17, 0.6);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(212, 175, 55, 0.06);
            border-radius: 20px;
            box-shadow: 0 8px 40px rgba(0, 0, 0, 0.5),
                        inset 0 1px 0 rgba(255, 255, 255, 0.04);
        }

        /* Enhanced Alerts */
        .alert { border-radius: 12px; }
        .alert-danger { border-radius: 12px; }

        /* Toast Notification */
        .toast-container {
            position: fixed;
            top: 28px;
            right: 28px;
            z-index: 1100;
        }
        .custom-toast {
            background: rgba(17, 24, 39, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(37, 99, 235, 0.25);
            border-radius: 14px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4),
                        0 0 0 1px rgba(37, 99, 235, 0.1);
            padding: 16px 20px;
            color: #93c5fd;
            min-width: 340px;
            max-width: 480px;
            animation: toastSlideIn 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .custom-toast .toast-icon { color: var(--gold); font-size: 1.25rem; }
        .custom-toast strong { color: var(--gold-light); }
        .custom-toast .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
            opacity: 0.6;
        }
        .custom-toast .btn-close:hover { opacity: 1; }
        .custom-toast .toast-progress {
            height: 3px;
            background: linear-gradient(90deg, var(--gold-dark), var(--gold), var(--gold-dark));
            border-radius: 0 0 14px 14px;
            margin: 12px -20px -16px -20px;
            transform-origin: left;
            animation: toastProgress 7s linear forwards;
        }
        @keyframes toastSlideIn {
            from { opacity: 0; transform: translateX(60px); }
            to { opacity: 1; transform: translateX(0); }
        }
        @keyframes toastSlideOut {
            to { opacity: 0; transform: translateX(60px); }
        }
        @keyframes toastProgress {
            from { transform: scaleX(1); }
            to { transform: scaleX(0); }
        }
        .toast-hiding {
            animation: toastSlideOut 0.3s ease forwards;
        }

        /* Gradient Text for Image Section */
        .image-section h2 {
            background: linear-gradient(135deg, var(--white) 0%, var(--gold-light) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        /* Entrance Animation */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .login-wrapper {
            animation: fadeInUp 0.5s ease-out;
        }

        .form-section { overflow-y: visible; }
        /* Remove old override */

        /* Page exit transition */
        @keyframes slideOutLeft {
            to { opacity: 0; transform: translateX(-60px); }
        }
        .page-exit .login-wrapper {
            animation: slideOutLeft 0.35s cubic-bezier(0.4, 0, 1, 1) forwards;
        }

        /* Login submit transition */
        @keyframes fadeOutScale {
            to { opacity: 0; transform: scale(0.96); }
        }
        .login-submitting .login-wrapper {
            animation: fadeOutScale 0.4s cubic-bezier(0.4, 0, 1, 1) forwards;
        }
    </style>
</head>
<body>

<?php if ($registration_notice): ?>
<div class="toast-container">
    <div class="custom-toast" id="registrationToast">
        <div class="d-flex align-items-start gap-3">
            <i class="fas fa-check-circle toast-icon mt-1"></i>
            <div class="flex-grow-1">
                <strong>Almost there!</strong> <?php echo $registration_notice; ?>
            </div>
            <button type="button" class="btn-close ms-2" onclick="dismissToast('registrationToast')" aria-label="Close"></button>
        </div>
        <div class="toast-progress"></div>
    </div>
</div>
<?php endif; ?>

<?php if ($profile_notice): ?>
<div class="toast-container" <?php echo $registration_notice ? 'style="top: 100px;"' : ''; ?>>
    <div class="custom-toast" id="profileToast">
        <div class="d-flex align-items-start gap-3">
            <i class="fas fa-envelope toast-icon mt-1" style="color: #3b82f6;"></i>
            <div class="flex-grow-1">
                <strong>Profile Submitted!</strong> <?php echo $profile_notice; ?>
            </div>
            <button type="button" class="btn-close ms-2" onclick="dismissToast('profileToast')" aria-label="Close"></button>
        </div>
        <div class="toast-progress"></div>
    </div>
</div>
<?php endif; ?>

<div class="main-container">
    <div class="form-section">
        <div class="login-wrapper">

            <!-- Left branding panel -->
            <div class="login-brand-panel">
                <img src="images/Logo.png" alt="HomeEstate Realty">
                <span class="brand-name">HomeEstate Realty</span>
                <div class="brand-gold-line"></div>
                <h2>Welcome Back</h2>
                <p>Your premier partner in finding the perfect property. Log in to manage your listings.</p>
            </div>

            <!-- Right form panel -->
            <div class="login-form-panel">
                <div class="login-divider"><span>Sign In to Your Account</span></div>
                <h1>Sign In</h1>
                <p class="mb-4">Enter your credentials to access your account.</p>

            <?php if ($error_message): ?>
                <div class="alert alert-danger" role="alert">
                    <?php echo $error_message; ?>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST">
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" class="form-control" id="username" name="username" required>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>
                <div class="d-flex justify-content-end mb-4">
                    <a href="#" class="small">Forgot password?</a>
                </div>
                <button type="submit" class="btn login-btn">Login</button>
            </form>

            <div class="register-link">
                Don't have an agent account? <a href="register.php">Register here</a>
            </div>
            </div><!-- end login-form-panel -->

        </div><!-- end login-wrapper -->
    </div><!-- end form-section -->
</div><!-- end main-container -->

<script src="<?= ASSETS_JS ?>bootstrap.bundle.min.js"></script>
<script>
    // Toast notification auto-dismiss (supports multiple toasts by ID)
    function dismissToast(toastId) {
        var toast = document.getElementById(toastId);
        if (toast) {
            toast.classList.add('toast-hiding');
            setTimeout(function() {
                var container = toast.parentElement;
                if (container) container.remove();
            }, 300);
        }
    }

    // Auto-dismiss all toasts after 7 seconds
    (function() {
        ['registrationToast', 'profileToast'].forEach(function(id) {
            var toast = document.getElementById(id);
            if (toast) {
                setTimeout(function() { dismissToast(id); }, 7000);
            }
        });
    })();

    // Smooth page transition on Register link click
    document.querySelectorAll('.register-link a').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            var href = this.getAttribute('href');
            document.querySelector('.main-container').classList.add('page-exit');
            setTimeout(function() { window.location.href = href; }, 350);
        });
    });

    // Smooth login form submit transition
    document.querySelector('form').addEventListener('submit', function(e) {
        e.preventDefault();
        var form = this;
        document.querySelector('.main-container').classList.add('login-submitting');
        setTimeout(function() { form.submit(); }, 400);
    });
</script>
</body>
</html>
