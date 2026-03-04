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

if ($property_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid property ID']);
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

$upload_dir = 'uploads/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

$allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
$mime_to_ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'];
$max_size = 25 * 1024 * 1024; // 25MB
$max_photos = 20;
$uploaded_count = 0;
$errors = [];

// Enforce max 20 photos total
$count_check = $conn->prepare("SELECT COUNT(*) as cnt FROM property_images WHERE property_ID = ?");
$count_check->bind_param('i', $property_id);
$count_check->execute();
$current_count = (int)$count_check->get_result()->fetch_assoc()['cnt'];
$count_check->close();

$files = $_FILES['images'];
$file_count = count($files['name']);

if ($current_count + $file_count > $max_photos) {
    echo json_encode(['success' => false, 'message' => "Maximum $max_photos photos allowed. Currently $current_count photos."]);
    exit;
}

// Get current max sort order
$sql_max = "SELECT COALESCE(MAX(SortOrder), 0) as max_order FROM property_images WHERE property_ID = ?";
$stmt = $conn->prepare($sql_max);
$stmt->bind_param('i', $property_id);
$stmt->execute();
$max_order = $stmt->get_result()->fetch_assoc()['max_order'];
$stmt->close();

$files = $_FILES['images'];

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
    $filename = uniqid('prop_', true) . '.' . $ext;
    $filepath = $upload_dir . $filename;
    
    // Move uploaded file
    if (move_uploaded_file($files['tmp_name'][$i], $filepath)) {
        // Insert into database
        $max_order++;
        $sql_insert = "INSERT INTO property_images (property_ID, PhotoURL, SortOrder) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql_insert);
        $stmt->bind_param('isi', $property_id, $filepath, $max_order);
        
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
