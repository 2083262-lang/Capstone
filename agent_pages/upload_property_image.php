<?php
session_start();
header('Content-Type: application/json');
require_once '../connection.php';

if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'agent') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
    exit();
}

$accountId = (int)$_SESSION['account_id'];
$propertyId = isset($_POST['property_id']) ? (int)$_POST['property_id'] : 0;
if ($propertyId <= 0) { echo json_encode(['success'=>false,'message'=>'Missing property id']); exit(); }

// Ownership check via property_log
$stmt = $conn->prepare('SELECT 1 FROM property_log WHERE property_id=? AND account_id=? LIMIT 1');
$stmt->bind_param('ii', $propertyId, $accountId);
$stmt->execute();
$own = $stmt->get_result()->num_rows > 0;
$stmt->close();
if (!$own) { echo json_encode(['success'=>false,'message'=>'Access denied']); exit(); }

// Check if property is sold
$stmt = $conn->prepare('SELECT Status FROM property WHERE property_ID=? LIMIT 1');
$stmt->bind_param('i', $propertyId);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();
if ($row && $row['Status'] === 'Sold') {
    echo json_encode(['success' => false, 'message' => 'Cannot modify images for sold properties']);
    exit();
}

if (empty($_FILES['images'])) { echo json_encode(['success'=>false,'message'=>'No files uploaded']); exit(); }

// Enforce maximum photo count (20 featured photos)
$countStmt = $conn->prepare('SELECT COUNT(*) as cnt FROM property_images WHERE property_ID = ?');
$countStmt->bind_param('i', $propertyId);
$countStmt->execute();
$currentCount = (int)$countStmt->get_result()->fetch_assoc()['cnt'];
$countStmt->close();
$incomingCount = count($_FILES['images']['name']);
if ($currentCount + $incomingCount > 20) {
    echo json_encode(['success' => false, 'message' => 'Maximum 20 featured photos allowed. You currently have ' . $currentCount . '.']);
    exit();
}

$allowed = ['jpg','jpeg','png','gif'];
$maxSize = 25 * 1024 * 1024; // 25MB
$uploadDir = '../uploads/';
if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0755, true); }

$photos = [];
$errors = [];

// Find current max sort order
$maxSort = 0;
$sortRes = $conn->prepare('SELECT COALESCE(MAX(SortOrder),0) as m FROM property_images WHERE property_ID=?');
$sortRes->bind_param('i', $propertyId);
$sortRes->execute();
$maxSort = ($sortRes->get_result()->fetch_assoc()['m']) ?? 0;
$sortRes->close();

for ($i = 0; $i < count($_FILES['images']['name']); $i++) {
    if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) { $errors[] = 'Upload error on file index '.$i; continue; }
    $name = $_FILES['images']['name'][$i];
    $tmp = $_FILES['images']['tmp_name'][$i];
    $size = (int)$_FILES['images']['size'][$i];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) { $errors[] = "$name has invalid type"; continue; }
    if ($size > $maxSize) { $errors[] = "$name exceeds 5MB"; continue; }

    $newName = uniqid('prop_', true) . '.' . $ext;
    $dest = $uploadDir . $newName; // filesystem path
    if (!move_uploaded_file($tmp, $dest)) { $errors[] = "Failed to move $name"; continue; }

    $url = 'uploads/' . $newName; // db/web path
    $maxSort++;
    $ins = $conn->prepare('INSERT INTO property_images (property_ID, PhotoURL, SortOrder) VALUES (?,?,?)');
    $ins->bind_param('isi', $propertyId, $url, $maxSort);
    if ($ins->execute()) {
        $photos[] = ['url' => $url, 'sort' => $maxSort];
    } else {
        // rollback file if DB failed
        @unlink($dest);
        $errors[] = 'DB insert failed for '.$name;
    }
    $ins->close();
}

if (!empty($photos)) {
    echo json_encode(['success'=>true, 'photos'=>$photos, 'errors'=>$errors]);
} else {
    echo json_encode(['success'=>false, 'message'=> implode('; ',$errors) ?: 'Upload failed']);
}
exit();
