<?php
session_start();
header('Content-Type: application/json');
require_once '../connection.php';

// Auth check
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'agent') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
    exit();
}

$accountId  = (int)$_SESSION['account_id'];
$propertyId = isset($_POST['property_id']) ? (int)$_POST['property_id'] : 0;
$floorNum   = isset($_POST['floor_number']) ? (int)$_POST['floor_number'] : 0;

if ($propertyId <= 0 || $floorNum < 1 || $floorNum > 10) {
    echo json_encode(['success' => false, 'message' => 'Invalid property ID or floor number']);
    exit();
}

// Ownership check
$stmt = $conn->prepare('SELECT 1 FROM property_log WHERE property_id = ? AND account_id = ? LIMIT 1');
$stmt->bind_param('ii', $propertyId, $accountId);
$stmt->execute();
$own = $stmt->get_result()->num_rows > 0;
$stmt->close();
if (!$own) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

// Check if property is sold
$stmt = $conn->prepare('SELECT Status FROM property WHERE property_ID = ? LIMIT 1');
$stmt->bind_param('i', $propertyId);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();
if ($row && $row['Status'] === 'Sold') {
    echo json_encode(['success' => false, 'message' => 'Cannot modify images for sold properties']);
    exit();
}

if (empty($_FILES['floor_images'])) {
    echo json_encode(['success' => false, 'message' => 'No files uploaded']);
    exit();
}

$allowed   = ['jpg', 'jpeg', 'png', 'gif'];
$maxSize   = 25 * 1024 * 1024; // 25MB
$floorDir  = '../uploads/floors/' . $propertyId . '/floor_' . $floorNum . '/';

if (!is_dir($floorDir)) {
    mkdir($floorDir, 0755, true);
}

// Find current max sort order for this floor
$maxSort = 0;
$sortStmt = $conn->prepare('SELECT COALESCE(MAX(sort_order), 0) as m FROM property_floor_images WHERE property_id = ? AND floor_number = ?');
$sortStmt->bind_param('ii', $propertyId, $floorNum);
$sortStmt->execute();
$maxSort = (int)$sortStmt->get_result()->fetch_assoc()['m'];
$sortStmt->close();

$photos = [];
$errors = [];
$files = $_FILES['floor_images'];
$fileCount = count($files['name']);

for ($i = 0; $i < $fileCount; $i++) {
    if ($files['error'][$i] !== UPLOAD_ERR_OK) {
        $errors[] = 'Upload error on file index ' . $i;
        continue;
    }

    $name = $files['name'][$i];
    $tmp  = $files['tmp_name'][$i];
    $size = (int)$files['size'][$i];
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed)) {
        $errors[] = "{$name} has invalid type (only JPEG, PNG, GIF allowed)";
        continue;
    }
    if ($size > $maxSize) {
        $errors[] = "{$name} exceeds 10MB limit";
        continue;
    }

    $newName = uniqid('floor_' . $floorNum . '_', true) . '.' . $ext;
    $dest    = $floorDir . $newName;

    if (!move_uploaded_file($tmp, $dest)) {
        $errors[] = "Failed to move {$name}";
        continue;
    }

    // Store the relative path for DB
    $dbPath = 'uploads/floors/' . $propertyId . '/floor_' . $floorNum . '/' . $newName;
    $maxSort++;

    $ins = $conn->prepare('INSERT INTO property_floor_images (property_id, floor_number, photo_url, sort_order) VALUES (?, ?, ?, ?)');
    $ins->bind_param('iisi', $propertyId, $floorNum, $dbPath, $maxSort);

    if ($ins->execute()) {
        $photos[] = ['url' => $dbPath, 'sort' => $maxSort];
    } else {
        @unlink($dest);
        $errors[] = 'DB insert failed for ' . $name;
    }
    $ins->close();
}

if (!empty($photos)) {
    echo json_encode(['success' => true, 'photos' => $photos, 'errors' => $errors, 'floor_number' => $floorNum]);
} else {
    echo json_encode(['success' => false, 'message' => implode('; ', $errors) ?: 'Upload failed']);
}
exit();
