<?php
session_start();
include 'connection.php'; // Ensure this file establishes your $conn (MySQLi) connection
$error_message = '';

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
                            'redirect_to' => $redirect_to ?: 'login.php'
                        ];
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
    <title>Login - Real Estate System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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
        }

        .form-section {
            display: flex;
            justify-content: center;
            align-items: center;
            flex-grow: 1;
            padding: 2rem;
            background: linear-gradient(135deg, var(--black) 0%, var(--black-lighter) 100%);
            position: relative;
        }

        .form-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 30%, rgba(37, 99, 235, 0.05) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(212, 175, 55, 0.04) 0%, transparent 50%);
            pointer-events: none;
        }

        .login-wrapper {
            width: 100%;
            max-width: 450px;
            background: transparent;
            border: none;
            border-radius: 4px;
            padding: 48px 40px;
            position: relative;
            z-index: 1;
        }

        .login-wrapper::before {
            display: none;
        }

        .logo-container {
            text-align: center;
            margin-bottom: 32px;
        }

        .logo-container img {
            width: 80px;
            height: auto;
            filter: drop-shadow(0 4px 12px rgba(212, 175, 55, 0.3));
            margin-bottom: 16px;
        }

        .login-wrapper h1 {
            font-weight: 700;
            color: var(--white);
            margin-bottom: 0.5rem;
            font-size: 2rem;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        }

        .login-wrapper p {
            color: var(--gray-400);
            margin-bottom: 32px;
        }

        .login-wrapper .form-label {
            font-weight: 500;
            color: var(--gray-300);
            margin-bottom: 8px;
        }

        .login-wrapper .form-control {
            height: 50px;
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

        .image-section {
            width: 50%;
            background: 
                radial-gradient(circle at 30% 50%, rgba(37, 99, 235, 0.08) 0%, transparent 50%),
                radial-gradient(circle at 70% 50%, rgba(212, 175, 55, 0.08) 0%, transparent 50%),
                linear-gradient(rgba(10, 10, 10, 0.7), rgba(10, 10, 10, 0.8)),
                url('images/login-bg.jpg');
            background-size: cover;
            background-position: center;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: var(--white);
            text-align: center;
            padding: 2rem;
            border-right: 1px solid rgba(37, 99, 235, 0.2);
            position: relative;
        }

        .image-section::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 1px;
            height: 100%;
            background: linear-gradient(180deg, 
                transparent 0%, 
                rgba(212, 175, 55, 0.5) 50%, 
                transparent 100%);
        }

        .image-section img {
            max-width: 280px;
            height: auto;
            filter: drop-shadow(0 4px 20px rgba(212, 175, 55, 0.4));
            margin-bottom: 32px;
        }

        .image-section h2 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            text-shadow: 0 2px 20px rgba(0, 0, 0, 0.5);
        }

        .image-section p {
            font-size: 1.1rem;
            max-width: 400px;
            color: var(--gray-300);
            line-height: 1.8;
        }

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

        /* Responsive Design */
        @media (max-width: 992px) {
            .image-section {
                display: none;
            }
            .form-section {
                width: 100%;
            }
        }

        @media (max-width: 576px) {
            .login-wrapper {
                padding: 32px 24px;
            }

            .login-wrapper h1 {
                font-size: 1.75rem;
            }
        }
    </style>
</head>
<body>

<div class="main-container">
    <div class="image-section">
        <img src="images/LogoName.png" alt="HomeEstate Realty Logo">
        <h2>Welcome Back</h2>
        <p>Your premier partner in finding the perfect property. Log in to manage your listings and connect with clients.</p>
    </div>

    <div class="form-section">
        <div class="login-wrapper">
            <div class="logo-container">
                <img src="images/Logo.png" alt="Logo">
            </div>
            <h1>Sign In</h1>
            <p class="mb-4 ">Enter your credentials to access your account.</p>

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
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>