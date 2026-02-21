<?php
session_start();
include 'connection.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$account_id = $_SESSION['account_id'];
$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate inputs
    $errors = [];
    
    // Required fields
    $required_fields = ['license_number', 'specialization', 'years_experience', 'bio'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
        }
    }
    
    // Additional validations
    $license_number = trim($_POST['license_number']);
    $specialization = trim($_POST['specialization']);
    $years_experience = intval($_POST['years_experience']);
    $bio = trim($_POST['bio']);
    // removed date_hired
    $profile_picture_url = '';
    
    // Validate years_experience is not negative
    if ($years_experience < 0) {
        $errors[] = 'Years of experience cannot be negative';
    }
    
    // removed date_hired validation
    
    // Handle file upload if provided
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $max_size = 5 * 1024 * 1024; // 5MB
        
        $file_type = $_FILES['profile_picture']['type'];
        $file_size = $_FILES['profile_picture']['size'];
        $file_tmp = $_FILES['profile_picture']['tmp_name'];
        $file_name = $_FILES['profile_picture']['name'];
        
        if (!in_array($file_type, $allowed_types)) {
            $errors[] = 'Only JPG, PNG and GIF images are allowed';
        } elseif ($file_size > $max_size) {
            $errors[] = 'File size must be less than 5MB';
        } else {
            $upload_dir = 'uploads/admins/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
            $new_file_name = uniqid('admin_', true) . '.' . $file_extension;
            $file_destination = $upload_dir . $new_file_name;
            
            if (move_uploaded_file($file_tmp, $file_destination)) {
                $profile_picture_url = $file_destination;
            } else {
                $errors[] = 'Failed to upload profile picture';
            }
        }
    }
    
    // If no errors, proceed with database operation
    if (empty($errors)) {
        // Check if admin profile already exists
        $check_sql = "SELECT admin_info_id FROM admin_information WHERE account_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $account_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        
        if ($result->num_rows > 0) {
            // Update existing profile
            $update_sql = "UPDATE admin_information 
                          SET license_number = ?, specialization = ?, years_experience = ?, 
                              bio = ?, profile_completed = 1";
            
            // Add profile picture to update if provided
            if (!empty($profile_picture_url)) {
                $update_sql .= ", profile_picture_url = ?";
            }
            
            $update_sql .= " WHERE account_id = ?";
            $update_stmt = $conn->prepare($update_sql);
            
            if (!empty($profile_picture_url)) {
                $update_stmt->bind_param("ssissi", $license_number, $specialization, $years_experience, 
                                      $bio, $profile_picture_url, $account_id);
            } else {
                $update_stmt->bind_param("ssisi", $license_number, $specialization, $years_experience, 
                                      $bio, $account_id);
            }
            
            if ($update_stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Admin profile updated successfully';
            } else {
                $response['message'] = 'Failed to update profile: ' . $update_stmt->error;
            }
            $update_stmt->close();
        } else {
            // Insert new profile
            $insert_sql = "INSERT INTO admin_information 
                          (account_id, license_number, specialization, years_experience, bio, profile_picture_url, profile_completed) 
                          VALUES (?, ?, ?, ?, ?, ?, 1)";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("ississ", $account_id, $license_number, $specialization, 
                                   $years_experience, $bio, $profile_picture_url);
            
            if ($insert_stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Admin profile created successfully';
            } else {
                $response['message'] = 'Failed to create profile: ' . $insert_stmt->error;
            }
            $insert_stmt->close();
        }
        $check_stmt->close();
    } else {
        // Return validation errors
        $response['message'] = implode(', ', $errors);
    }
}

// Return JSON response for AJAX
header('Content-Type: application/json');
echo json_encode($response);
exit();
?>