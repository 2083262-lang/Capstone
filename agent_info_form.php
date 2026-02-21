<?php
// Start output buffering to prevent "headers already sent" errors for redirects
ob_start();
session_start();

include 'connection.php'; // Ensure this connects to your database

$error_message = '';
$success_message = '';

// Check if user is logged in and is an agent
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'agent') {
    header("Location: login.php");
    ob_end_flush();
    exit();
}

$account_id = $_SESSION['account_id'];

// Initialize variables for form fields
$license_number = '';
$specialization = '';
$years_experience = '';
$bio = '';
$profile_picture_url = '';
$date_hired = '';
$profile_completed_status = 0;
$is_approved_status = 0;

// Allowed specialization options (single source of truth)
$specialization_options = [
    'Luxury Homes', 'Commercial', 'Rentals', 'Condos', 'First-Time Buyers',
    'Investment Properties', 'New Construction', 'Relocation', 'Waterfront',
    'Land', 'Property Management', 'Foreclosures'
];

// --- Fetch existing agent information if it exists ---
$stmt_fetch_agent_info = $conn->prepare("SELECT license_number, specialization, years_experience, bio, profile_picture_url, profile_completed, is_approved FROM agent_information WHERE account_id = ?");
$stmt_fetch_agent_info->bind_param("i", $account_id);
$stmt_fetch_agent_info->execute();
$result_fetch_agent_info = $stmt_fetch_agent_info->get_result();

if ($result_fetch_agent_info->num_rows > 0) {
    $agent_data = $result_fetch_agent_info->fetch_assoc();
    $license_number = htmlspecialchars($agent_data['license_number']);
    $specialization = htmlspecialchars($agent_data['specialization']);
    $years_experience = htmlspecialchars($agent_data['years_experience']);
    $bio = htmlspecialchars($agent_data['bio']);
    $profile_picture_url = htmlspecialchars($agent_data['profile_picture_url']);
    // no date_hired anymore
    $profile_completed_status = $agent_data['profile_completed'];
    $is_approved_status = $agent_data['is_approved'];
}
$stmt_fetch_agent_info->close();


// --- Handle Form Submission ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize and validate input
    $license_number = trim((string)($_POST['license_number'] ?? ''));
    // Collect raw specialization values as array for validation
    $raw_specs = [];
    if (isset($_POST['specialization'])) {
        if (is_array($_POST['specialization'])) {
            $raw_specs = $_POST['specialization'];
        } else {
            $raw_specs = array_filter(array_map('trim', explode(',', (string)$_POST['specialization'])));
        }
    }
    $years_experience_input = $_POST['years_experience'] ?? '';
    $bio = trim((string)($_POST['bio'] ?? ''));
    // removed date_hired

    $new_profile_picture_path = $profile_picture_url;

    // Begin server-side validation
    $validation_errors = [];

    // License number: required, 5-30 chars, alphanum, space, dash, slash allowed
    if ($license_number === '') {
        $validation_errors[] = 'License number is required.';
    } else {
        if (strlen($license_number) < 5 || strlen($license_number) > 30) {
            $validation_errors[] = 'License number must be 5-30 characters long.';
        }
        if (!preg_match('/^[A-Za-z0-9\\-\\/ ]+$/', $license_number)) {
            $validation_errors[] = 'License number may only contain letters, numbers, spaces, dashes, and slashes.';
        }
    }

    // Specializations: at least one required; each must be from allowed list
    $sanitized_specs = array_values(array_unique(array_filter(array_map('trim', $raw_specs))));
    if (count($sanitized_specs) === 0) {
        $validation_errors[] = 'Please select at least one specialization.';
    } else {
        $invalid_specs = array_diff($sanitized_specs, $specialization_options);
        if (!empty($invalid_specs)) {
            $validation_errors[] = 'One or more selected specializations are invalid.';
        }
    }

    // Years of Experience: optional, but if provided must be integer 0-70
    $years_experience = 0;
    if ($years_experience_input !== '') {
        if (!is_numeric($years_experience_input) || floor($years_experience_input) != $years_experience_input) {
            $validation_errors[] = 'Years of experience must be a whole number.';
        } else {
            $years_experience = (int)$years_experience_input;
            if ($years_experience < 0 || $years_experience > 70) {
                $validation_errors[] = 'Years of experience must be between 0 and 70.';
            }
        }
    }

    // Bio: required, reasonable length constraints
    $bio = strip_tags($bio);
    if ($bio === '') {
        $validation_errors[] = 'Biography is required.';
    } else {
        if (strlen($bio) < 30) {
            $validation_errors[] = 'Biography must be at least 30 characters.';
        }
        if (strlen($bio) > 1000) {
            $validation_errors[] = 'Biography must be at most 1000 characters.';
        }
    }

    // Handle file upload (server-side validation for profile image)
    if (isset($_FILES['profile_picture_file']) && $_FILES['profile_picture_file']['error'] == UPLOAD_ERR_OK) {
        $file_tmp_name = $_FILES['profile_picture_file']['tmp_name'];
        $file_name = $_FILES['profile_picture_file']['name'];
        $file_size = (int)$_FILES['profile_picture_file']['size'];

        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $max_file_size = 5 * 1024 * 1024; // 5MB

        // Detect MIME using finfo to avoid spoofing
        $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
        $detected_mime = $finfo ? finfo_file($finfo, $file_tmp_name) : null;
        if ($finfo) { finfo_close($finfo); }

        if (!$detected_mime || !in_array($detected_mime, $allowed_types)) {
            $error_message = "Invalid file type. Only JPG, JPEG, PNG, and GIF are allowed.";
        } elseif ($file_size > $max_file_size) {
            $error_message = "File size exceeds 5MB.";
        } else {
            // Build a safe filename with correct extension based on MIME
            $ext_map = [ 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif' ];
            $safe_ext = isset($ext_map[$detected_mime]) ? $ext_map[$detected_mime] : 'jpg';
            $upload_dir = 'uploads/agents/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            $unique_file_name = uniqid('agent_', true) . '.' . $safe_ext;
            $upload_path = $upload_dir . $unique_file_name;

            if (move_uploaded_file($file_tmp_name, $upload_path)) {
                $new_profile_picture_path = $upload_path;
            } else {
                $error_message = "Failed to upload profile picture.";
            }
        }
    }

    // If upload produced an error, add to validation errors
    if (!empty($error_message)) {
        $validation_errors[] = $error_message;
        $error_message = '';
    }

    // Prepare specialization string for DB if valid
    if (empty($validation_errors)) {
        $specialization = implode(', ', $sanitized_specs);
    }

    if (empty($validation_errors)) {
            $stmt_check_exists = $conn->prepare("SELECT agent_info_id FROM agent_information WHERE account_id = ?");
            $stmt_check_exists->bind_param("i", $account_id);
            $stmt_check_exists->execute();
            $result_check_exists = $stmt_check_exists->get_result();

            $db_operation_successful = false;

            if ($result_check_exists->num_rows > 0) {
                // UPDATE existing record
                $stmt_update = $conn->prepare("UPDATE agent_information SET license_number = ?, specialization = ?, years_experience = ?, bio = ?, profile_picture_url = ?, profile_completed = 1 WHERE account_id = ?");
                $stmt_update->bind_param("ssissi", $license_number, $specialization, $years_experience, $bio, $new_profile_picture_path, $account_id);
                if ($stmt_update->execute()) {
                    $success_message = "Agent information updated successfully!";
                    $db_operation_successful = true;
                } else {
                    $error_message = "Error updating information: " . $stmt_update->error;
                }
                $stmt_update->close();
            } else {
                // INSERT new record
                $stmt_insert = $conn->prepare("INSERT INTO agent_information (account_id, license_number, specialization, years_experience, bio, profile_picture_url, profile_completed, is_approved) VALUES (?, ?, ?, ?, ?, ?, 1, 0)");
                $stmt_insert->bind_param("ississ", $account_id, $license_number, $specialization, $years_experience, $bio, $new_profile_picture_path);
                if ($stmt_insert->execute()) {
                    $success_message = "Agent information submitted for review!";
                    $db_operation_successful = true;
                } else {
                    $error_message = "Error saving information: " . $stmt_insert->error;
                }
                $stmt_insert->close();
            }
            $stmt_check_exists->close();

            // --- CREATE NOTIFICATION ON SUCCESS ---
            if ($db_operation_successful) {
                $notif_check_stmt = $conn->prepare("SELECT notification_id FROM notifications WHERE item_id = ? AND item_type = 'agent' AND is_read = 0");
                $notif_check_stmt->bind_param("i", $account_id);
                $notif_check_stmt->execute();
                $notif_result = $notif_check_stmt->get_result();

                if ($notif_result->num_rows == 0) {
                    $name_stmt = $conn->prepare("SELECT first_name, last_name FROM accounts WHERE account_id = ?");
                    $name_stmt->bind_param("i", $account_id);
                    $name_stmt->execute();
                    $agent_name_result = $name_stmt->get_result()->fetch_assoc();
                    $agent_full_name = $agent_name_result['first_name'] . ' ' . $agent_name_result['last_name'];
                    $name_stmt->close();

                    $notification_message = "Agent '" . $conn->real_escape_string($agent_full_name) . "' submitted their profile for approval.";
                    $notif_stmt = $conn->prepare("INSERT INTO notifications (item_id, item_type, message) VALUES (?, 'agent', ?)");
                    $notif_stmt->bind_param("is", $account_id, $notification_message);
                    $notif_stmt->execute();
                    $notif_stmt->close();
                }
                $notif_check_stmt->close();
            }
    } else {
        // Aggregate validation errors for display
        $error_message = '<ul class="mb-0">' . implode('', array_map(function($e){ return '<li>' . htmlspecialchars($e) . '</li>'; }, $validation_errors)) . '</ul>';
    }
}

$conn->close();
ob_end_flush();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Your Agent Profile</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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

        .form-wrapper {
            width: 100%;
            max-width: 700px;
            position: relative;
            z-index: 1;
        }

        .form-wrapper h2 {
            font-weight: 700;
            color: var(--white);
        }

        .form-section h3 {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--white);
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid rgba(37, 99, 235, 0.3);
        }

        .form-label {
            font-weight: 500;
            color: var(--gray-300);
        }

        .form-control {
            background: rgba(10, 10, 10, 0.6);
            border: 1px solid rgba(37, 99, 235, 0.3);
            border-radius: 2px;
            color: var(--white);
            transition: all 0.3s ease;
        }

        .form-control:focus {
            border-color: var(--blue);
            background: rgba(10, 10, 10, 0.8);
            box-shadow: 0 0 0 0.25rem rgba(37, 99, 235, 0.15),
                        0 4px 16px rgba(37, 99, 235, 0.2);
            color: var(--white);
        }

        .form-control::placeholder {
            color: var(--gray-600);
        }

        .form-text {
            color: var(--gray-500);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 50%, var(--gold-dark) 100%);
            color: var(--black);
            border: none;
            padding: 14px;
            border-radius: 2px;
            font-weight: 700;
            transition: all 0.3s ease;
            box-shadow: 0 4px 16px rgba(212, 175, 55, 0.25), 
                        0 0 0 1px rgba(212, 175, 55, 0.2);
            position: relative;
            overflow: hidden;
        }

        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(212, 175, 55, 0.4), 
                        0 0 0 1px rgba(212, 175, 55, 0.4),
                        0 0 30px rgba(212, 175, 55, 0.2);
            color: var(--black);
        }

        .btn-primary:hover::before {
            left: 100%;
        }

        .image-section {
            width: 45%;
            background: 
                radial-gradient(circle at 30% 50%, rgba(37, 99, 235, 0.08) 0%, transparent 50%),
                radial-gradient(circle at 70% 50%, rgba(212, 175, 55, 0.08) 0%, transparent 50%),
                linear-gradient(rgba(10, 10, 10, 0.7), rgba(10, 10, 10, 0.8)),
                url('images/agent-info-bg.jpg');
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

        .image-section h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            text-shadow: 0 2px 20px rgba(0, 0, 0, 0.5);
        }

        .image-section p {
            color: var(--gray-300);
            line-height: 1.8;
        }

        .profile-pic-preview {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid rgba(37, 99, 235, 0.3);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.3);
        }

        .status-message {
            border-left: 4px solid var(--gold);
        }

        .alert-danger {
            background: rgba(220, 53, 69, 0.15);
            border: 1px solid rgba(220, 53, 69, 0.3);
            border-radius: 2px;
            color: #ff6b6b;
        }

        .alert-success {
            background: rgba(40, 167, 69, 0.15);
            border: 1px solid rgba(40, 167, 69, 0.3);
            border-radius: 2px;
            color: #69db7c;
        }

        .alert-warning {
            background: rgba(255, 193, 7, 0.15);
            border: 1px solid rgba(255, 193, 7, 0.3);
            border-radius: 2px;
            color: #ffd43b;
        }

        .alert-warning .alert-heading,
        .alert-success .alert-heading {
            color: var(--white);
        }

        .alert-link {
            color: var(--gold);
        }

        .text-muted {
            color: var(--gray-400) !important;
        }

        /* Specialization Checkbox Grid */
        .specialization-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 0.75rem;
            padding: 1rem;
            background: rgba(10, 10, 10, 0.6);
            border: 1px solid rgba(37, 99, 235, 0.2);
            border-radius: 4px;
            max-height: 320px;
            overflow-y: auto;
        }

        .spec-checkbox-wrapper {
            position: relative;
        }

        .spec-checkbox-wrapper input[type="checkbox"] {
            position: absolute;
            opacity: 0;
            cursor: pointer;
        }

        .spec-checkbox-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.65rem 0.85rem;
            background: rgba(26, 26, 26, 0.8);
            border: 1px solid rgba(37, 99, 235, 0.2);
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--gray-300);
            transition: all 0.15s ease;
            user-select: none;
        }

        .spec-checkbox-wrapper input[type="checkbox"]:checked + .spec-checkbox-label {
            background: linear-gradient(135deg, rgba(212, 175, 55, 0.15) 0%, rgba(212, 175, 55, 0.1) 100%);
            border-color: var(--gold);
            color: var(--white);
        }

        .spec-checkbox-label:hover {
            border-color: var(--blue);
            background: rgba(37, 99, 235, 0.1);
        }

        .spec-checkbox-wrapper input[type="checkbox"]:checked + .spec-checkbox-label:hover {
            background: linear-gradient(135deg, rgba(212, 175, 55, 0.2) 0%, rgba(212, 175, 55, 0.15) 100%);
        }

        .spec-checkbox-label::before {
            content: '';
            width: 18px;
            height: 18px;
            border: 2px solid var(--gray-600);
            border-radius: 4px;
            background: rgba(10, 10, 10, 0.6);
            flex-shrink: 0;
            transition: all 0.15s ease;
        }

        .spec-checkbox-wrapper input[type="checkbox"]:checked + .spec-checkbox-label::before {
            background: var(--gold);
            border-color: var(--gold);
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%230a0a0a' d='M10.3 2.3L4.5 8.1 1.7 5.3l.7-.7 2.1 2.1 5.1-5.1z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: center;
        }

        .specialization-count {
            display: inline-block;
            margin-top: 0.5rem;
            padding: 0.35rem 0.75rem;
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 100%);
            color: var(--black);
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .text-danger {
            color: #ff6b6b !important;
        }

        a.text-muted {
            transition: color 0.3s ease;
        }

        a.text-muted:hover {
            color: var(--blue-light) !important;
        }

        @media (max-width: 992px) {
            .image-section {
                display: none;
            }
            .form-section {
                width: 100%;
            }
            .specialization-grid {
                grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
                gap: 0.5rem;
            }
        }
    </style>
</head>
<body>

<div class="main-container">
    <div class="image-section">
        <h1>Final Step</h1>
        <p>Complete your professional profile to build trust with clients and get access to our full suite of tools. This information will be reviewed by our team.</p>
    </div>

    <div class="form-section">
        <div class="form-wrapper">
            <div class="text-center mb-4">
                <p class="text-muted fw-bold">Fill out the details below to complete your registration.</p>
            </div>

            <?php if ($error_message): ?>
                <div class="alert alert-danger" role="alert"><?php echo $error_message; ?></div>
            <?php endif; ?>
            <?php if ($success_message): ?>
                <div class="alert alert-success" role="alert"><?php echo $success_message; ?></div>
            <?php endif; ?>

            <?php if ($profile_completed_status == 1): ?>
                <div class="alert status-message <?php echo $is_approved_status ? 'alert-success' : 'alert-warning'; ?>" role="alert">
                    <h4 class="alert-heading">Account Status: <?php echo $is_approved_status ? 'Approved' : 'Pending Approval'; ?></h4>
                    <p class="mb-0">
                        <?php echo $is_approved_status 
                            ? 'Your profile is approved! You can now access the <a href="agent_dashboard.php" class="alert-link">Agent Dashboard</a>.' 
                            : 'Your profile is awaiting administrator approval. You will have full access once approved.'; 
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <form action="agent_info_form.php" method="POST" enctype="multipart/form-data">
                <div class="row g-3">
                    <div class="col-12">
                        <h3>Identity & Photo</h3>
                        <div class="d-flex align-items-center gap-3">
                            <img src="<?php echo !empty($profile_picture_url) ? htmlspecialchars($profile_picture_url) : 'images/profile.png'; ?>" alt="Preview" id="imagePreview" class="profile-pic-preview">
                            <div class="flex-grow-1">
                                <label for="profile_picture_file" class="form-label">Profile Picture</label>
                                <input type="file" name="profile_picture_file" id="profile_picture_file" class="form-control" accept="image/jpeg,image/png,image/gif">
                                <div class="form-text">JPG/JPEG/PNG/GIF, max 5MB.</div>
                                <div id="imageError" class="text-danger small mt-1" style="display: none;"></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 mt-4">
                        <h3>Professional Details</h3>
                    </div>
                    <div class="col-md-6">
                        <label for="license_number" class="form-label">License Number <span style="color: red;">*</span></label>
                        <input type="text" name="license_number" id="license_number" class="form-control" value="<?php echo $license_number; ?>" required maxlength="30" pattern="^[A-Za-z0-9\-\/ ]{5,30}$" title="5-30 characters; letters, numbers, spaces, dashes, and slashes only">
                        <div class="form-text">
                            Enter your official real estate license number.
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="years_experience" class="form-label">Years of Experience</label>
                        <input type="number" name="years_experience" id="years_experience" class="form-control" value="<?php echo $years_experience; ?>" min="0" max="70" step="1">
                        <div class="form-text">
                            Enter the total number of years you've worked in real estate.
                        </div>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Specializations <span style="color: red;">*</span></label>
                        <?php 
                            $selected_specializations = [];
                            if (isset($agent_data) && isset($agent_data['specialization'])) {
                                $selected_specializations = array_filter(array_map('trim', explode(',', (string)$agent_data['specialization'])));
                            } elseif (!empty($specialization)) {
                                $selected_specializations = array_filter(array_map('trim', explode(',', (string)$specialization)));
                            }
                        ?>
                        <div class="specialization-grid">
                            <?php foreach ($specialization_options as $opt): 
                                $isChecked = in_array($opt, $selected_specializations, true) ? 'checked' : '';
                            ?>
                                <div class="spec-checkbox-wrapper">
                                    <input type="checkbox" 
                                           name="specialization[]" 
                                           id="spec_<?php echo htmlspecialchars(str_replace(' ', '_', $opt)); ?>" 
                                           value="<?php echo htmlspecialchars($opt); ?>" 
                                           <?php echo $isChecked; ?>
                                           class="spec-checkbox">
                                    <label for="spec_<?php echo htmlspecialchars(str_replace(' ', '_', $opt)); ?>" class="spec-checkbox-label">
                                        <?php echo htmlspecialchars($opt); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text">
                            Select all areas that apply to your expertise.
                            <span class="specialization-count" id="specCount" style="display: none;">0 selected</span>
                        </div>
                        <input type="hidden" name="specialization_validator" id="specializationValidator" required>
                    </div>
                    <!-- Date Hired removed per client request -->
                    <div class="col-12 mt-4">
                        <h3>Biography</h3>
                        <label for="bio" class="form-label">About Me <span style="color: red;">*</span></label>
                        <textarea name="bio" id="bio" class="form-control" rows="4" placeholder="Tell clients a little about your experience..." required><?php echo $bio; ?></textarea>
                    </div>
                </div>

                <div class="d-grid mt-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        <?php echo ($profile_completed_status) ? 'Update Information' : 'Submit for Review'; ?>
                    </button>
                </div>
                 <div class="text-center mt-3">
                    <a href="login.php" class="text-muted small">Go Back</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Live preview for profile picture upload
    const profilePictureInput = document.getElementById('profile_picture_file');
    const imagePreview = document.getElementById('imagePreview');

    const imageError = document.getElementById('imageError');

    function validateImageClient(file) {
        const allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        const maxSize = 5 * 1024 * 1024; // 5MB

        if (!allowedTypes.includes(file.type)) {
            return 'Invalid file type. Only JPG, JPEG, PNG, and GIF are allowed.';
        }
        if (file.size > maxSize) {
            return 'File size exceeds 5MB.';
        }
        return null; // valid
    }

    profilePictureInput.addEventListener('change', function(event) {
        const file = event.target.files && event.target.files[0];
        
        // Clear previous error
        imageError.style.display = 'none';
        imageError.textContent = '';
        
        if (!file) return;
        
        const errorMsg = validateImageClient(file);
        if (errorMsg) {
            // Show inline error
            imageError.textContent = errorMsg;
            imageError.style.display = 'block';
            // Reset input
            profilePictureInput.value = '';
            return;
        }
        
        // Preview valid image
        const reader = new FileReader();
        reader.onload = function(e) {
            imagePreview.src = e.target.result;
        };
        reader.readAsDataURL(file);
    });

    // Specialization checkbox validation and count
    const specCheckboxes = document.querySelectorAll('.spec-checkbox');
    const specCount = document.getElementById('specCount');
    const specValidator = document.getElementById('specializationValidator');

    function updateSpecializationCount() {
        const checked = document.querySelectorAll('.spec-checkbox:checked');
        const count = checked.length;
        
        if (count > 0) {
            specCount.textContent = count + ' selected';
            specCount.style.display = 'inline-block';
            specValidator.value = 'valid'; // Set hidden field to pass required validation
        } else {
            specCount.style.display = 'none';
            specValidator.value = ''; // Clear to trigger validation error
        }
    }

    specCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateSpecializationCount);
    });

    // Initial count on page load
    updateSpecializationCount();

    // Form validation
    document.querySelector('form').addEventListener('submit', function(e) {
        const checked = document.querySelectorAll('.spec-checkbox:checked');
        if (checked.length === 0) {
            e.preventDefault();
            alert('Please select at least one specialization.');
            return false;
        }
    });
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>