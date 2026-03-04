<?php
session_start();
require_once __DIR__ . '/config/session_timeout.php';
header('Content-Type: application/json');
include 'connection.php';

// Check if admin is logged in
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$property_id = isset($_POST['property_id']) ? (int)$_POST['property_id'] : 0;
$floor_number = isset($_POST['floor_number']) ? (int)$_POST['floor_number'] : 0;

if ($property_id <= 0 || $floor_number <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

// Verify property exists
$prop_check = $conn->prepare("SELECT property_ID FROM property WHERE property_ID = ? LIMIT 1");
$prop_check->bind_param('i', $property_id);
$prop_check->execute();
if ($prop_check->get_result()->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Property not found']);
    $prop_check->close();
    exit;
}
$prop_check->close();

if (empty($_FILES['images']) || !is_array($_FILES['images']['name'])) {
    echo json_encode(['success' => false, 'message' => 'No images provided']);
    exit;
}

$upload_dir = "uploads/floors/{$property_id}/floor_{$floor_number}/";
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

$allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
$mime_to_ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'];
$max_size = 25 * 1024 * 1024; // 25MB
$uploaded_count = 0;
$errors = [];

// Get current max sort order for this floor
$sql_max = "SELECT COALESCE(MAX(sort_order), 0) as max_order FROM property_floor_images WHERE property_id = ? AND floor_number = ?";
$stmt = $conn->prepare($sql_max);
$stmt->bind_param('ii', $property_id, $floor_number);
$stmt->execute();
$max_order = $stmt->get_result()->fetch_assoc()['max_order'];
$stmt->close();

$files = $_FILES['images'];
$file_count = count($files['name']);

for ($i = 0; $i < $file_count; $i++) {
    if ($files['error'][$i] !== UPLOAD_ERR_OK) {
        $errors[] = "Upload error for file " . ($i + 1);
        continue;
    }
    
    // Validate file type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $files['tmp_name'][$i]);
    finfo_close($finfo);
    
    if (!in_array($mime, $allowed_types)) {
        $errors[] = "Invalid file type for file " . ($i + 1);
        continue;
    }
    
    // Validate file size
    if ($files['size'][$i] > $max_size) {
        $errors[] = "File " . ($i + 1) . " exceeds 5MB limit";
        continue;
    }
    
    // Generate unique filename using MIME-based extension
    $ext = $mime_to_ext[$mime] ?? 'jpg';
    $filename = "floor_{$floor_number}_" . uniqid('', true) . '.' . $ext;
    $filepath = $upload_dir . $filename;
    
    // Move uploaded file
    if (move_uploaded_file($files['tmp_name'][$i], $filepath)) {
        // Insert into database
        $max_order++;
        $sql_insert = "INSERT INTO property_floor_images (property_id, floor_number, photo_url, sort_order) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql_insert);
        $stmt->bind_param('iisi', $property_id, $floor_number, $filepath, $max_order);
        
        if ($stmt->execute()) {
            $uploaded_count++;
        } else {
            @unlink($filepath); // Remove file if DB insert failed
            $errors[] = "Database error for file " . ($i + 1);
        }
        $stmt->close();
    } else {
        $errors[] = "Failed to upload file " . ($i + 1);
    }
}

if ($uploaded_count > 0) {
    echo json_encode([
        'success' => true,
        'count' => $uploaded_count,
        'errors' => $errors
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'No photos were uploaded',
        'errors' => $errors
    ]);
}
?>
