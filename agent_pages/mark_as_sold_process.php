<?php
session_start();
require_once '../connection.php';

// Respond with JSON for fetch-based submissions
function respond_json($success, $message, $extra = []) {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => (bool)$success, 'message' => $message], $extra));
    exit();
}

// Check if user is logged in and is an agent
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'agent') {
    respond_json(false, 'Unauthorized access.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(false, 'Invalid request method.');
}

try {
    // Get agent ID from session
    $agent_id = $_SESSION['account_id'];
    
    // Validate required fields
    $required_fields = ['property_id', 'sale_price', 'sale_date', 'buyer_name'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            respond_json(false, "Missing required field: $field");
        }
    }
    
    // Validate files
    if (!isset($_FILES['sale_documents']) || empty($_FILES['sale_documents']['name'][0])) {
        respond_json(false, 'At least one sale document is required.');
    }
    
    $property_id = intval($_POST['property_id']);
    $sale_price = floatval($_POST['sale_price']);
    $sale_date = $_POST['sale_date'];
    $buyer_name = trim($_POST['buyer_name']);
    $buyer_contact = trim($_POST['buyer_contact'] ?? '');
    $additional_notes = trim($_POST['additional_notes'] ?? '');
    
    // Validate property belongs to agent and is approved
    $property_check = $conn->prepare("
        SELECT p.property_ID, p.StreetAddress, p.Status 
        FROM property p
        INNER JOIN property_log pl ON p.property_ID = pl.property_id 
        WHERE p.property_ID = ? AND pl.account_id = ? AND p.approval_status = 'approved' AND pl.action = 'CREATED'
        ORDER BY pl.log_timestamp DESC LIMIT 1
    ");
    $property_check->bind_param("ii", $property_id, $agent_id);
    $property_check->execute();
    $property_result = $property_check->get_result();
    
    if ($property_result->num_rows === 0) {
        respond_json(false, 'Property not found or not authorized.');
    }
    
    $property = $property_result->fetch_assoc();
    
    // Check if there's already a pending sale verification for this property
    $existing_check = $conn->prepare("
        SELECT verification_id 
        FROM sale_verifications 
        WHERE property_id = ? AND status IN ('Pending', 'Approved')
    ");
    $existing_check->bind_param("i", $property_id);
    $existing_check->execute();
    
    if ($existing_check->get_result()->num_rows > 0) {
        respond_json(false, 'A sale verification is already pending or approved for this property.');
    }
    
    // Validate sale date
    $sale_date_obj = DateTime::createFromFormat('Y-m-d', $sale_date);
    if (!$sale_date_obj || $sale_date_obj > new DateTime()) {
        respond_json(false, 'Invalid sale date. Date cannot be in the future.');
    }
    
    // Validate sale price
    if ($sale_price <= 0) {
        respond_json(false, 'Sale price must be greater than zero.');
    }
    
    // Create upload directory if it doesn't exist
    $upload_base = '../sale_documents';
    if (!file_exists($upload_base)) {
        if (!mkdir($upload_base, 0755, true)) {
            respond_json(false, 'Failed to create upload directory.');
        }
    }
    
    // Create property-specific directory
    $upload_dir = $upload_base . '/' . $property_id;
    if (!file_exists($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            respond_json(false, 'Failed to create property upload directory.');
        }
    }
    
    // Process file uploads
    $uploaded_files = [];
    $allowed_types = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    $max_file_size = 120 * 1024 * 1024; // 120MB
    
    $files = $_FILES['sale_documents'];
    for ($i = 0; $i < count($files['name']); $i++) {
        if ($files['error'][$i] === UPLOAD_ERR_OK) {
            $file_name = $files['name'][$i];
            $file_tmp = $files['tmp_name'][$i];
            $file_size = $files['size'][$i];
            $file_type = $files['type'][$i];
            
            // Validate file type
            if (!in_array($file_type, $allowed_types)) {
                respond_json(false, "Invalid file type: $file_name");
            }
            
            // Validate file size
            if ($file_size > $max_file_size) {
                respond_json(false, "File too large: $file_name (max 120MB)");
            }
            
            // Generate unique filename
            $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
            $unique_name = uniqid() . '_' . time() . '.' . $file_extension;
            $file_path = $upload_dir . '/' . $unique_name;
            
            // Move uploaded file
            if (move_uploaded_file($file_tmp, $file_path)) {
                $uploaded_files[] = [
                    'original_name' => $file_name,
                    'stored_name' => $unique_name,
                    'file_path' => $file_path,
                    'file_size' => $file_size,
                    'mime_type' => $file_type
                ];
            } else {
                respond_json(false, "Failed to upload file: $file_name");
            }
        } else {
            respond_json(false, "Upload error for file: " . $files['name'][$i]);
        }
    }
    
    // Begin transaction
    $conn->begin_transaction();
    
    try {
        // Insert sale verification record
        $insert_verification = $conn->prepare("
            INSERT INTO sale_verifications 
            (property_id, agent_id, sale_price, sale_date, buyer_name, buyer_contact, additional_notes, status, submitted_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())
        ");
        
        // Match the updated database structure
        $insert_verification->bind_param("iidssss", $property_id, $agent_id, $sale_price, $sale_date, $buyer_name, $buyer_contact, $additional_notes);
        
        if (!$insert_verification->execute()) {
            throw new Exception('Failed to insert sale verification record.');
        }
        
        $verification_id = $conn->insert_id;

            // Update property status to 'pending sold'
            $update_property = $conn->prepare("UPDATE property SET Status = 'Pending Sold' WHERE property_ID = ?");
            $update_property->bind_param("i", $property_id);
            if (!$update_property->execute()) {
                throw new Exception('Failed to update property status to pending sold.');
            }
            $update_property->close();
        
        // Insert document records
        $insert_document = $conn->prepare("
            INSERT INTO sale_verification_documents 
            (verification_id, original_filename, stored_filename, file_path, file_size, mime_type, uploaded_at) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        foreach ($uploaded_files as $file) {
            // Types: i (verification_id), s (original_filename), s (stored_filename), s (file_path), i (file_size), s (mime_type)
            $insert_document->bind_param("isssis", $verification_id, $file['original_name'], $file['stored_name'], $file['file_path'], $file['file_size'], $file['mime_type']);
            
            if (!$insert_document->execute()) {
                throw new Exception('Failed to insert document record for: ' . $file['original_name']);
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        respond_json(true, 'Sale verification submitted successfully! Your submission is pending admin review.', [
            'property_id' => $property_id,
            'verification_id' => $verification_id
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction
        $conn->rollback();
        
        // Clean up uploaded files on error
        foreach ($uploaded_files as $file) {
            if (file_exists($file['file_path'])) {
                unlink($file['file_path']);
            }
        }
        
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Sale verification error: " . $e->getMessage());
    respond_json(false, 'An error occurred while processing your submission. Please try again.');
}
?>