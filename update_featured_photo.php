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
$photo_id = isset($_POST['photo_id']) ? (int)$_POST['photo_id'] : 0;
$old_url = isset($_POST['old_url']) ? trim($_POST['old_url']) : '';

if ($property_id <= 0 || $photo_id <= 0 || empty($old_url)) {
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

if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No image provided']);
    exit;
}

$upload_dir = 'uploads/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

$allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
$max_size = 25 * 1024 * 1024; // 25MB

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

// Generate unique filename using MIME-based extension
$mime_to_ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'];
$ext = $mime_to_ext[$mime] ?? 'jpg';
$filename = uniqid('prop_', true) . '.' . $ext;
$filepath = $upload_dir . $filename;

// Move uploaded file
if (!move_uploaded_file($_FILES['image']['tmp_name'], $filepath)) {
    echo json_encode(['success' => false, 'message' => 'Failed to upload file']);
    exit;
}

// Update database
$sql_update = "UPDATE property_images SET PhotoURL = ? WHERE PhotoID = ? AND property_ID = ?";
$stmt = $conn->prepare($sql_update);
$stmt->bind_param('sii', $filepath, $photo_id, $property_id);

if ($stmt->execute()) {
    // Delete old file safely (prevent path traversal)
    $real_old = realpath($old_url);
    $uploads_dir = realpath(__DIR__ . '/uploads');
    if ($real_old && $uploads_dir && strpos($real_old, $uploads_dir) === 0 && is_file($real_old)) {
        @unlink($real_old);
    }
    
    $stmt->close();
    echo json_encode([
        'success' => true,
        'message' => 'Photo updated successfully',
        'new_url' => $filepath
    ]);
} else {
    // Remove uploaded file if DB update failed
    @unlink($filepath);
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Database update failed']);
}
?>
