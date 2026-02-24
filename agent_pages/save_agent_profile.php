<?php
session_start();
include '../connection.php';

header('Content-Type: application/json');

// Check if user is logged in and is an agent
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'agent') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$account_id = $_SESSION['account_id'];
$errors = [];

// ===== Sanitize & validate inputs =====

// Personal info
$first_name = trim($_POST['first_name'] ?? '');
$middle_name = trim($_POST['middle_name'] ?? '');
$last_name = trim($_POST['last_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone_number = trim($_POST['phone_number'] ?? '');

// Professional info
$license_number = trim($_POST['license_number'] ?? '');
$years_experience_input = $_POST['years_experience'] ?? '';
$bio = trim($_POST['bio'] ?? '');

// Specializations
$raw_specs = [];
if (isset($_POST['specialization'])) {
    $raw_specs = is_array($_POST['specialization']) ? $_POST['specialization'] : array_filter(array_map('trim', explode(',', (string)$_POST['specialization'])));
}

// Allowed specialization options
$specialization_options = [
    'Luxury Homes', 'Commercial', 'Rentals', 'Condos', 'First-Time Buyers',
    'Investment Properties', 'New Construction', 'Relocation', 'Waterfront',
    'Land', 'Property Management', 'Foreclosures'
];

// ===== Validation =====

// First/Last name required
if ($first_name === '') {
    $errors[] = 'First name is required.';
} elseif (strlen($first_name) > 50) {
    $errors[] = 'First name must be 50 characters or less.';
}

if ($last_name === '') {
    $errors[] = 'Last name is required.';
} elseif (strlen($last_name) > 50) {
    $errors[] = 'Last name must be 50 characters or less.';
}

if (strlen($middle_name) > 50) {
    $errors[] = 'Middle name must be 50 characters or less.';
}

// Email
if ($email === '') {
    $errors[] = 'Email address is required.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please enter a valid email address.';
} else {
    // Check email uniqueness (exclude current user)
    $email_check = $conn->prepare("SELECT account_id FROM accounts WHERE email = ? AND account_id != ?");
    $email_check->bind_param("si", $email, $account_id);
    $email_check->execute();
    if ($email_check->get_result()->num_rows > 0) {
        $errors[] = 'This email address is already in use by another account.';
    }
    $email_check->close();
}

// Phone (optional but validate format if provided)
if (!empty($phone_number) && strlen($phone_number) > 20) {
    $errors[] = 'Phone number is too long.';
}

// License number
if ($license_number === '') {
    $errors[] = 'License number is required.';
} elseif (strlen($license_number) < 5 || strlen($license_number) > 100) {
    $errors[] = 'License number must be between 5 and 100 characters.';
} elseif (!preg_match('/^[A-Za-z0-9\-\/ ]+$/', $license_number)) {
    $errors[] = 'License number may only contain letters, numbers, spaces, dashes, and slashes.';
} else {
    // Check license uniqueness (exclude current user)
    $lic_check = $conn->prepare("SELECT account_id FROM agent_information WHERE license_number = ? AND account_id != ?");
    $lic_check->bind_param("si", $license_number, $account_id);
    $lic_check->execute();
    if ($lic_check->get_result()->num_rows > 0) {
        $errors[] = 'This license number is already registered to another agent.';
    }
    $lic_check->close();
}

// Years of experience
$years_experience = 0;
if ($years_experience_input !== '') {
    if (!is_numeric($years_experience_input) || floor($years_experience_input) != $years_experience_input) {
        $errors[] = 'Years of experience must be a whole number.';
    } else {
        $years_experience = (int) $years_experience_input;
        if ($years_experience < 0 || $years_experience > 70) {
            $errors[] = 'Years of experience must be between 0 and 70.';
        }
    }
}

// Specializations
$sanitized_specs = array_values(array_unique(array_filter(array_map('trim', $raw_specs))));
if (count($sanitized_specs) === 0) {
    $errors[] = 'Please select at least one specialization.';
} else {
    $invalid_specs = array_diff($sanitized_specs, $specialization_options);
    if (!empty($invalid_specs)) {
        $errors[] = 'One or more selected specializations are invalid.';
    }
}

// Bio
$bio = strip_tags($bio);
if ($bio !== '' && strlen($bio) < 30) {
    $errors[] = 'Biography must be at least 30 characters.';
}
if (strlen($bio) > 2000) {
    $errors[] = 'Biography must be at most 2000 characters.';
}

// ===== Profile picture upload =====
$new_profile_picture = null;
if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
    $file_tmp = $_FILES['profile_picture']['tmp_name'];
    $file_name = $_FILES['profile_picture']['name'];
    $file_size = (int) $_FILES['profile_picture']['size'];

    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    $max_size = 5 * 1024 * 1024;

    // Detect MIME type
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    $detected_mime = $finfo ? finfo_file($finfo, $file_tmp) : null;
    if ($finfo) finfo_close($finfo);

    if (!$detected_mime || !in_array($detected_mime, $allowed_types)) {
        $errors[] = 'Invalid file type. Only JPG, PNG and GIF images are allowed.';
    } elseif ($file_size > $max_size) {
        $errors[] = 'Profile picture must be less than 5MB.';
    } else {
        $ext_map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'];
        $safe_ext = $ext_map[$detected_mime] ?? 'jpg';
        $upload_dir = '../uploads/agents/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        $unique_name = uniqid('agent_', true) . '.' . $safe_ext;
        $upload_path = $upload_dir . $unique_name;

        if (move_uploaded_file($file_tmp, $upload_path)) {
            // Store path relative to project root (without ../)
            $new_profile_picture = 'uploads/agents/' . $unique_name;
        } else {
            $errors[] = 'Failed to upload profile picture. Please try again.';
        }
    }
}

// ===== Return errors if any =====
if (!empty($errors)) {
    echo json_encode(['success' => false, 'message' => implode('<br>', $errors)]);
    $conn->close();
    exit();
}

// ===== Build specialization string =====
$specialization = implode(', ', $sanitized_specs);

// ===== Begin database update =====
$conn->begin_transaction();

try {
    // 1. Update accounts table (personal info)
    $update_account = $conn->prepare("UPDATE accounts SET first_name = ?, middle_name = ?, last_name = ?, email = ?, phone_number = ? WHERE account_id = ?");
    $update_account->bind_param("sssssi", $first_name, $middle_name, $last_name, $email, $phone_number, $account_id);
    if (!$update_account->execute()) {
        throw new Exception('Failed to update account information: ' . $update_account->error);
    }
    $update_account->close();

    // 2. Update or insert agent_information (professional info)
    $check_exists = $conn->prepare("SELECT agent_info_id FROM agent_information WHERE account_id = ?");
    $check_exists->bind_param("i", $account_id);
    $check_exists->execute();
    $exists = $check_exists->get_result()->num_rows > 0;
    $check_exists->close();

    if ($exists) {
        // Update existing record
        if ($new_profile_picture !== null) {
            $update_agent = $conn->prepare("UPDATE agent_information SET license_number = ?, specialization = ?, years_experience = ?, bio = ?, profile_picture_url = ?, profile_completed = 1 WHERE account_id = ?");
            $update_agent->bind_param("ssissi", $license_number, $specialization, $years_experience, $bio, $new_profile_picture, $account_id);
        } else {
            $update_agent = $conn->prepare("UPDATE agent_information SET license_number = ?, specialization = ?, years_experience = ?, bio = ?, profile_completed = 1 WHERE account_id = ?");
            $update_agent->bind_param("ssisi", $license_number, $specialization, $years_experience, $bio, $account_id);
        }
        if (!$update_agent->execute()) {
            throw new Exception('Failed to update agent information: ' . $update_agent->error);
        }
        $update_agent->close();
    } else {
        // Insert new record
        $pic = $new_profile_picture ?? '';
        $insert_agent = $conn->prepare("INSERT INTO agent_information (account_id, license_number, specialization, years_experience, bio, profile_picture_url, profile_completed, is_approved) VALUES (?, ?, ?, ?, ?, ?, 1, 0)");
        $insert_agent->bind_param("ississ", $account_id, $license_number, $specialization, $years_experience, $bio, $pic);
        if (!$insert_agent->execute()) {
            throw new Exception('Failed to create agent profile: ' . $insert_agent->error);
        }
        $insert_agent->close();
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Profile updated successfully!'
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?>
