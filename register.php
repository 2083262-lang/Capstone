<?php
session_start();
include 'connection.php'; // Make sure this file correctly connects to your MySQL database

$error_message = '';
$success_message = '';

// Preserve input values between submissions (except password)
$first_name = '';
$middle_name = '';
$last_name = '';
$username = '';
$email = '';
$phone_number = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username     = trim($_POST['username']);
    $password     = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $email        = trim($_POST['email']);
    $first_name   = trim($_POST['first_name']);
    $middle_name  = trim($_POST['middle_name']);
    $last_name    = trim($_POST['last_name']);
    // Support a visible local phone input (phone_local) and a hidden normalized phone_number
    $phone_local = trim($_POST['phone_local'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');

    // Repopulate preserved variables for rendering the form after validation failure
    $first_name = htmlspecialchars($first_name, ENT_QUOTES);
    $middle_name = htmlspecialchars($middle_name, ENT_QUOTES);
    $last_name = htmlspecialchars($last_name, ENT_QUOTES);
    $username = htmlspecialchars($username, ENT_QUOTES);
    $email = htmlspecialchars($email, ENT_QUOTES);
    $phone_number = htmlspecialchars($phone_number, ENT_QUOTES);

    // Validate required fields
    $errors = [];
    if (empty($username)) $errors[] = 'Username is required.';
    if (empty($password)) $errors[] = 'Password is required.';
    if (empty($password_confirm)) $errors[] = 'Password confirmation is required.';
    if (empty($email)) $errors[] = 'Email is required.';
    if (empty($first_name)) $errors[] = 'First name is required.';
    if (empty($middle_name)) $errors[] = 'Middle name is required.';
    if (empty($last_name)) $errors[] = 'Last name is required.';
    if (empty($phone_local) && empty($phone_number)) $errors[] = 'Phone number is required.';

    // Email validation
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    // Normalize phone input. If user typed into the visible local field, use that and prefix +63.
    $phone_local_display = '';
    if (!empty($phone_local)) {
        // remove non-digits
        $pl_digits = preg_replace('/\D+/', '', $phone_local);
        // strip leading zeros
        $pl_digits = preg_replace('/^0+/', '', $pl_digits);
        // build normalized international format
        $phone_number = '+63' . $pl_digits;
        $phone_local_display = $pl_digits;
    } else {
        // if a full phone_number was submitted (fallback), normalize it
        $p = preg_replace('/[^0-9+]/', '', $phone_number);
        if (preg_match('/^0(9\d{9})$/', $p, $m)) {
            $phone_number = '+63' . $m[1];
            $phone_local_display = $m[1];
        } elseif (preg_match('/^63(9\d{9})$/', $p, $m)) {
            $phone_number = '+63' . $m[1];
            $phone_local_display = $m[1];
        } elseif (preg_match('/^\+63(9\d{9})$/', $p, $m)) {
            $phone_number = '+63' . $m[1];
            $phone_local_display = $m[1];
        } else {
            // leave as-is (will be validated below if present)
            $phone_local_display = preg_replace('/^\+63/', '', $phone_number);
        }
    }

    // Phone validation: now expect normalized +639XXXXXXXXX or empty
    if (!empty($phone_number) && !preg_match('/^\+639\d{9}$/', $phone_number)) {
        $errors[] = 'Please enter a valid Philippine phone number. Use the +63 prefix box and then the 10-digit mobile number (e.g. 9171234567).';
    }

    // Password strength
    if (!empty($password)) {
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $errors[] = 'Password must contain letters and numbers.';
        }
    }

    // Password confirmation
    if (!empty($password) && ($password !== $password_confirm)) {
        $errors[] = 'Password and confirmation do not match.';
    }

    if (!empty($errors)) {
        $error_message = implode(' ', $errors);
    } else {
        // Hash the password for security
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        // --- Step 1: Get the role_id for 'agent' from the user_roles table ---
        $role_id = null;
        $stmt_role = $conn->prepare("SELECT role_id FROM user_roles WHERE role_name = 'agent'");
        if ($stmt_role) {
            $stmt_role->execute();
            $stmt_role->bind_result($role_id);
            $stmt_role->fetch();
            $stmt_role->close();
        }

        if ($role_id === null) {
            $error_message = "Error: 'agent' role not found. Please contact an administrator.";
        } else {
            // --- Step 2: Prepare the SQL insert into the accounts table ---
            $stmt_insert_account = $conn->prepare("INSERT INTO accounts 
                                                    (role_id, first_name, middle_name, last_name, phone_number, email, username, password_hash, is_active) 
                                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, TRUE)");
            if (!$stmt_insert_account) {
                $error_message = 'Database error: could not prepare statement.';
            } else {
                $stmt_insert_account->bind_param("isssssss", $role_id, $first_name, $middle_name, $last_name, $phone_number, $email, $username, $password_hash);

                try {
                    $stmt_insert_account->execute();
                    $success_message = "Registration successful! You can now <a href='login.php' class='alert-link'>log in</a> to complete your agent profile.";
                    // clear preserved fields on success
                    $first_name = $middle_name = $last_name = $username = $email = $phone_number = '';
                } catch (mysqli_sql_exception $e) {
                    // Duplicate entry
                    if ($e->getCode() == 1062) {
                        $dupMsg = $e->getMessage();
                        if (stripos($dupMsg, 'username') !== false) {
                            $error_message = "Username already exists. Please choose a different username.";
                        } elseif (stripos($dupMsg, 'email') !== false) {
                            $error_message = "Email already registered. Use a different email or login.";
                        } else {
                            $error_message = "A record with the same unique value already exists. Please check your inputs.";
                        }
                    } else {
                        $error_message = 'Database error: ' . $e->getMessage();
                    }
                }
                $stmt_insert_account->close();
            }
        }
    }
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agent Registration - Real Estate</title>
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

        .register-wrapper {
            width: 100%;
            max-width: 700px;
            background: transparent;
            border: none;
            border-radius: 4px;
            padding: 48px 40px;
            position: relative;
            z-index: 1;
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

        .register-wrapper h1 {
            font-weight: 700;
            color: var(--white);
            margin-bottom: 0.5rem;
            font-size: 2rem;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        }

        .register-wrapper > p {
            color: var(--gray-400);
            margin-bottom: 32px;
        }

        .register-wrapper .form-label {
            font-weight: 500;
            color: var(--gray-300);
            margin-bottom: 8px;
        }

        .register-wrapper .form-control {
            height: 50px;
            border-radius: 2px;
            border: 1px solid rgba(37, 99, 235, 0.3);
            background: rgba(10, 10, 10, 0.6);
            color: var(--white);
            transition: all 0.3s ease;
        }

        .register-wrapper .form-control:focus {
            border-color: var(--blue);
            background: rgba(10, 10, 10, 0.8);
            box-shadow: 0 0 0 0.25rem rgba(37, 99, 235, 0.15),
                        0 4px 16px rgba(37, 99, 235, 0.2);
            color: var(--white);
        }

        .register-wrapper .form-control::placeholder {
            color: var(--gray-600);
        }

        .input-group-text {
            background: rgba(37, 99, 235, 0.1);
            border: 1px solid rgba(37, 99, 235, 0.3);
            border-right: none;
            color: var(--gray-300);
            border-radius: 2px 0 0 2px;
        }

        .input-group .form-control {
            border-radius: 0 2px 2px 0;
        }

        .form-text {
            color: var(--gray-500);
        }

        /* required asterisk */
        .form-label.required::after {
            content: " *";
            color: #ff6b6b;
            margin-left: 2px;
        }

        .register-btn {
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

        .register-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s ease;
        }

        .register-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(212, 175, 55, 0.4), 
                        0 0 0 1px rgba(212, 175, 55, 0.4),
                        0 0 30px rgba(212, 175, 55, 0.2);
            color: var(--black);
        }

        .register-btn:hover::before {
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

        .alert-success {
            background: rgba(25, 135, 84, 0.15);
            border: 1px solid rgba(25, 135, 84, 0.3);
            border-radius: 2px;
            color: #69db7c;
            padding: 12px 16px;
            margin-bottom: 24px;
        }

        .alert-success a {
            color: var(--gold);
        }

        .image-section {
            width: 50%;
            background: 
                radial-gradient(circle at 30% 50%, rgba(37, 99, 235, 0.08) 0%, transparent 50%),
                radial-gradient(circle at 70% 50%, rgba(212, 175, 55, 0.08) 0%, transparent 50%),
                linear-gradient(rgba(10, 10, 10, 0.7), rgba(10, 10, 10, 0.8)),
                url('images/register-bg.jpg');
            background-size: cover;
            background-position: center;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: var(--white);
            text-align: center;
            padding: 2rem;
            border-left: 1px solid rgba(37, 99, 235, 0.2);
            position: relative;
        }

        .image-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
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

        .login-link {
            text-align: center;
            margin-top: 1.5rem;
            font-size: 0.9rem;
            color: var(--gray-400);
        }

        .login-link a {
            color: var(--gold);
            font-weight: 600;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .login-link a:hover {
            color: var(--gold-light);
            text-decoration: none;
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
            .register-wrapper {
                padding: 32px 24px;
            }

            .register-wrapper h1 {
                font-size: 1.75rem;
            }
        }
    </style>
</head>
<body>

<div class="main-container">
    <div class="form-section">
        <div class="register-wrapper">
            <h1>Create an Account</h1>
            <p class="mb-4">Join our team of professional real estate agents.</p>

            <?php if ($error_message): ?>
                <div class="alert alert-danger"><?php echo $error_message; ?></div>
            <?php endif; ?>
            <?php if ($success_message): ?>
                <div class="alert alert-success"><?php echo $success_message; ?></div>
            <?php endif; ?>

            <form action="register.php" method="POST">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label required">First Name</label>
                        <input type="text" name="first_name" id="first_name" class="form-control" value="<?php echo $first_name; ?>">
                        <div class="invalid-feedback" id="firstNameError"></div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label required">Middle Name</label>
                        <input type="text" name="middle_name" id="middle_name" class="form-control" value="<?php echo $middle_name; ?>">
                        <div class="invalid-feedback" id="middleNameError"></div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label required">Last Name</label>
                        <input type="text" name="last_name" id="last_name" class="form-control" value="<?php echo $last_name; ?>">
                        <div class="invalid-feedback" id="lastNameError"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required">Email</label>
                        <input type="email" name="email" id="email" class="form-control" value="<?php echo $email; ?>">
                        <div class="invalid-feedback" id="emailError"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required">Phone Number</label>
                        <div class="input-group">
                            <span class="input-group-text">+63</span>
                            <input type="text" name="phone_local" id="phone_local" class="form-control" value="<?php echo htmlspecialchars($phone_local_display ?? '', ENT_QUOTES); ?>" placeholder="9XXXXXXXXX">
                        </div>
                        <input type="hidden" name="phone_number" id="phone_number" value="<?php echo htmlspecialchars($phone_number ?? '', ENT_QUOTES); ?>">
                        <div class="invalid-feedback d-block" id="phoneError" style="display:none"></div>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label required">Username</label>
                        <input type="text" name="username" id="username" class="form-control" value="<?php echo $username; ?>">
                        <div class="invalid-feedback" id="usernameError"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required">Password</label>
                        <input type="password" name="password" id="password" class="form-control">
                        <div class="form-text">At least 8 characters, include letters and numbers.</div>
                        <div class="invalid-feedback" id="passwordError"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required">Confirm Password</label>
                        <input type="password" name="password_confirm" id="password_confirm" class="form-control">
                        <div class="invalid-feedback" id="passwordConfirmError"></div>
                    </div>
                </div>
                <div class="mt-4">
                    <button type="submit" class="btn register-btn">Register Account</button>
                </div>
            </form>

            <div class="login-link">
                Already have an account? <a href="login.php">Sign in here</a>
            </div>
        </div>
    </div>

    <div class="image-section">
        <img src="images/Logo.png" alt="HomeEstate Realty Logo">
        <h2>Join Our Elite Team</h2>
        <p>Gain access to exclusive listings, powerful tools, and a network of professionals dedicated to your success.</p>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Real-time client-side validation
    const usernameEl = document.getElementById('username');
    const emailEl = document.getElementById('email');
    const phoneLocalEl = document.getElementById('phone_local');
    const phoneHiddenEl = document.getElementById('phone_number');
    const passwordEl = document.getElementById('password');
    const passwordConfirmEl = document.getElementById('password_confirm');

    function showError(el, id, message) {
        el.classList.add('is-invalid');
        const feedback = document.getElementById(id);
        if (feedback) {
            feedback.textContent = message;
            feedback.style.display = 'block';
        }
    }
    function clearError(el, id) {
        el.classList.remove('is-invalid');
        const feedback = document.getElementById(id);
        if (feedback) {
            feedback.textContent = '';
            feedback.style.display = 'none';
        }
    }

    function validateEmail() {
        const v = emailEl.value.trim();
        if (!v) { showError(emailEl, 'emailError', 'Email is required.'); return false; }
        const ok = /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v);
        if (!ok) { showError(emailEl, 'emailError', 'Invalid email address.'); return false; }
        clearError(emailEl, 'emailError'); return true;
    }

    function validateFirstName() {
        const v = document.getElementById('first_name').value.trim();
        const el = document.getElementById('first_name');
        if (!v) { showError(el, 'firstNameError', 'First name is required.'); return false; }
        clearError(el, 'firstNameError'); return true;
    }

    function validateMiddleName() {
        const v = document.getElementById('middle_name').value.trim();
        const el = document.getElementById('middle_name');
        if (!v) { showError(el, 'middleNameError', 'Middle name is required.'); return false; }
        clearError(el, 'middleNameError'); return true;
    }

    function validateLastName() {
        const v = document.getElementById('last_name').value.trim();
        const el = document.getElementById('last_name');
        if (!v) { showError(el, 'lastNameError', 'Last name is required.'); return false; }
        clearError(el, 'lastNameError'); return true;
    }

    function validateUsername() {
        const v = usernameEl.value.trim();
        if (!v) { showError(usernameEl, 'usernameError', 'Username is required.'); return false; }
        if (v.length < 3) { showError(usernameEl, 'usernameError', 'Username too short.'); return false; }
        clearError(usernameEl, 'usernameError'); return true;
    }

    function validatePhone() {
        const v = phoneLocalEl.value.trim();
        if (!v) { // optional
            clearError(phoneLocalEl, 'phoneError');
            phoneHiddenEl.value = '';
            return true;
        }
        const digits = v.replace(/\D/g, '');
        // accept leading 0 or not
        let normalized = digits.replace(/^0+/, '');
        // must now be 10 digits starting with 9 (e.g., 9171234567)
        if (!/^[9]\d{9}$/.test(normalized)) {
            showError(phoneLocalEl, 'phoneError', 'Enter local mobile number without 0 (e.g. 9171234567)');
            phoneHiddenEl.value = '';
            return false;
        }
        // set hidden full number
        phoneHiddenEl.value = '+63' + normalized;
        clearError(phoneLocalEl, 'phoneError'); return true;
    }

    function validatePassword() {
        const v = passwordEl.value || '';
        if (v.length < 8) { showError(passwordEl, 'passwordError', 'Password must be at least 8 characters.'); return false; }
        if (!/[A-Za-z]/.test(v) || !/\d/.test(v)) { showError(passwordEl, 'passwordError', 'Password must include letters and numbers.'); return false; }
        clearError(passwordEl, 'passwordError'); return true;
    }

    function validatePasswordConfirm() {
        const a = passwordEl.value || '';
        const b = passwordConfirmEl.value || '';
        if (a !== b) { showError(passwordConfirmEl, 'passwordConfirmError', 'Passwords do not match.'); return false; }
        clearError(passwordConfirmEl, 'passwordConfirmError'); return true;
    }

    usernameEl && usernameEl.addEventListener('input', validateUsername);
    emailEl && emailEl.addEventListener('input', validateEmail);
    phoneLocalEl && phoneLocalEl.addEventListener('input', validatePhone);
    const firstNameEl = document.getElementById('first_name');
    const middleNameEl = document.getElementById('middle_name');
    const lastNameEl = document.getElementById('last_name');
    firstNameEl && firstNameEl.addEventListener('input', validateFirstName);
    middleNameEl && middleNameEl.addEventListener('input', validateMiddleName);
    lastNameEl && lastNameEl.addEventListener('input', validateLastName);
    passwordEl && passwordEl.addEventListener('input', () => { validatePassword(); validatePasswordConfirm(); });
    passwordConfirmEl && passwordConfirmEl.addEventListener('input', validatePasswordConfirm);

    // Prevent form submit if invalid
    document.querySelector('form').addEventListener('submit', function(e){
        let ok = true;
        ok = validateUsername() && ok;
        ok = validateEmail() && ok;
        ok = validateFirstName() && ok;
        ok = validateMiddleName() && ok;
        ok = validateLastName() && ok;
        ok = validatePhone() && ok;
        ok = validatePassword() && ok;
        ok = validatePasswordConfirm() && ok;
        if (!ok) {
            e.preventDefault();
            e.stopPropagation();
        }
    });
</script>
</body>
</html>