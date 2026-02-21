<?php
session_start();
header('Content-Type: application/json');
include 'connection.php';

// Check if admin is logged in
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$property_id = isset($_POST['property_id']) ? (int)$_POST['property_id'] : 0;
$floor_number = isset($_POST['floor_number']) ? (int)$_POST['floor_number'] : 0;
$photo_id = isset($_POST['photo_id']) ? (int)$_POST['photo_id'] : 0;
$old_url = isset($_POST['old_url']) ? trim($_POST['old_url']) : '';

if ($property_id <= 0 || $floor_number <= 0 || $photo_id <= 0 || empty($old_url)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No image provided']);
    exit;
}

$upload_dir = "uploads/floors/{$property_id}/floor_{$floor_number}/";
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

$allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
$max_size = 5 * 1024 * 1024; // 5MB

// Validate file type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $_FILES['image']['tmp_name']);
finfo_close($finfo);

if (!in_array($mime, $allowed_types)) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPEG, PNG, and GIF are allowed.']);
    exit;
}

// Validate file size
if ($_FILES['image']['size'] > $max_size) {
    echo json_encode(['success' => false, 'message' => 'File size exceeds 5MB limit']);
    exit;
}

// Generate unique filename
$ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
$filename = "floor_{$floor_number}_" . uniqid() . '.' . uniqid() . '.' . $ext;
$filepath = $upload_dir . $filename;

// Move uploaded file
if (!move_uploaded_file($_FILES['image']['tmp_name'], $filepath)) {
    echo json_encode(['success' => false, 'message' => 'Failed to upload file']);
    exit;
}

// Update database
$sql_update = "UPDATE property_floor_images SET photo_url = ? WHERE id = ? AND property_id = ? AND floor_number = ?";
$stmt = $conn->prepare($sql_update);
$stmt->bind_param('siii', $filepath, $photo_id, $property_id, $floor_number);

if ($stmt->execute()) {
    // Delete old file if it exists and update was successful
    if (file_exists($old_url)) {
        @unlink($old_url);
    }
    
    $stmt->close();
    echo json_encode([
        'success' => true,
        'message' => 'Floor photo updated successfully',
        'new_url' => $filepath
    ]);
} else {
    // Remove uploaded file if DB update failed
    @unlink($filepath);
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Database update failed']);
}
?>
