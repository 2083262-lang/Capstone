<?php
session_start();
// Check if the user is an admin. If not, redirect to login page.
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Include your database connection file (e.g., connection.php)
require_once 'connection.php';

// Initialize message variable
$message_type = '';
$message_text = '';
$errors = []; // Array to collect validation errors

// Check if the form was submitted using the POST method
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // --- Input Validation ---

    // Required fields check
    $required_fields = ['first_name', 'last_name', 'email', 'license_number'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            $errors[] = ucwords(str_replace('_', ' ', $field)) . " is required.";
        }
    }

    // Sanitize and validate individual fields
    $firstName = trim($_POST['first_name']);
    if (strlen($firstName) > 100) {
        $errors[] = "First Name cannot exceed 100 characters.";
    }

    $lastName = trim($_POST['last_name']);
    if (strlen($lastName) > 100) {
        $errors[] = "Last Name cannot exceed 100 characters.";
    }

    $email = trim($_POST['email']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid Email format.";
    } else {
        // Check if email already exists
        $stmt_check_email = $conn->prepare("SELECT agent_id FROM agents WHERE email = ? LIMIT 1");
        $stmt_check_email->bind_param("s", $email);
        $stmt_check_email->execute();
        $stmt_check_email->store_result();
        if ($stmt_check_email->num_rows > 0) {
            $errors[] = "Email already registered to another agent.";
        }
        $stmt_check_email->close();
    }
    if (strlen($email) > 255) {
        $errors[] = "Email cannot exceed 255 characters.";
    }

    $phoneNumber = isset($_POST['phone_number']) ? trim($_POST['phone_number']) : null;
    if ($phoneNumber && strlen($phoneNumber) > 20) {
        $errors[] = "Phone Number cannot exceed 20 characters.";
    }
    // Optional: Add regex for phone number format if needed, e.g., if (!preg_match('/^\d{10,15}$/', $phoneNumber))

    $licenseNumber = trim($_POST['license_number']);
    if (strlen($licenseNumber) > 100) {
        $errors[] = "License Number cannot exceed 100 characters.";
    } else {
        // Check if license number already exists
        $stmt_check_license = $conn->prepare("SELECT agent_id FROM agents WHERE license_number = ? LIMIT 1");
        $stmt_check_license->bind_param("s", $licenseNumber);
        $stmt_check_license->execute();
        $stmt_check_license->store_result();
        if ($stmt_check_license->num_rows > 0) {
            $errors[] = "License Number already registered to another agent.";
        }
        $stmt_check_license->close();
    }

    $specialization = isset($_POST['specialization']) ? trim($_POST['specialization']) : null;
    if ($specialization && strlen($specialization) > 255) {
        $errors[] = "Specialization cannot exceed 255 characters.";
    }

    $yearsExperience = isset($_POST['years_experience']) && is_numeric($_POST['years_experience']) ? (int)$_POST['years_experience'] : 0;
    if ($yearsExperience < 0) {
        $errors[] = "Years of Experience cannot be negative.";
    }

    $bio = isset($_POST['bio']) ? trim($_POST['bio']) : null;
    // TEXT field, typically no max length validation unless you want to limit it for display.

    // removed date_hired

    $profilePictureUrl = null;
    // Handle profile picture upload
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = "uploads/agents/"; // Directory to store agent profile pictures
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $file_name = $_FILES['profile_picture']['name'];
        $file_tmp = $_FILES['profile_picture']['tmp_name'];
        $file_size = $_FILES['profile_picture']['size'];
        $file_type = $_FILES['profile_picture']['type'];
        
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $max_file_size = 5 * 1024 * 1024; // 5MB

        if (!in_array($file_ext, $allowed_extensions)) {
            $errors[] = "Profile picture: Only JPG, JPEG, PNG, GIF, WEBP files are allowed.";
        }
        if ($file_size > $max_file_size) {
            $errors[] = "Profile picture: File size must be less than 5MB.";
        }

        if (empty($errors)) { // Proceed with upload only if no prior errors for the image
            $new_file_name = uniqid('agent_') . "." . $file_ext;
            $file_destination = $upload_dir . $new_file_name;

            if (move_uploaded_file($file_tmp, $file_destination)) {
                $profilePictureUrl = $file_destination;
            } else {
                $errors[] = "Failed to upload profile picture.";
            }
        }
    }

    // If no validation errors, proceed with database insertion
    if (empty($errors)) {
        // SQL query to insert data into the `agents` table
        $sql = "INSERT INTO agents (
            first_name, last_name, email, phone_number, license_number, specialization,
            years_experience, bio, profile_picture_url
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?
        )";

        // Use a prepared statement to prevent SQL injection
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("ssssssiss",
                $firstName, $lastName, $email, $phoneNumber, $licenseNumber, $specialization,
                $yearsExperience, $bio, $profilePictureUrl
            );

            if ($stmt->execute()) {
                $_SESSION['message'] = [
                    'type' => 'success',
                    'text' => "Agent " . htmlspecialchars($firstName . ' ' . $lastName) . " successfully added!"
                ];
            } else {
                $errors[] = "Error inserting agent: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $errors[] = "SQL preparation failed: " . $conn->error;
        }
    }

    // If there were any errors, store them in the session
    if (!empty($errors)) {
        $_SESSION['message'] = [
            'type' => 'danger',
            'text' => implode('<br>', $errors)
        ];
    }
}

// Redirect back to the form page
header("Location: add_agent.php");
exit();
?>