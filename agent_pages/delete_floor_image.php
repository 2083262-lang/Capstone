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
$photoUrl   = isset($_POST['photo_url']) ? trim($_POST['photo_url']) : '';

if ($propertyId <= 0 || $floorNum < 1 || $photoUrl === '') {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
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

// Normalize the photo URL - remove any leading '../' or './'
$photoUrl = str_replace(['../', './'], '', $photoUrl);
$photoUrl = ltrim($photoUrl, '/');

// Check if property is sold
$stmt = $conn->prepare('SELECT Status FROM property WHERE property_ID = ? LIMIT 1');
$stmt->bind_param('i', $propertyId);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();
if ($row && $row['Status'] === 'Sold') {
    echo json_encode(['success' => false, 'message' => 'Cannot delete images for sold properties']);
    exit();
}

// Confirm image belongs to this property and floor
// Use basename matching or exact match for flexibility
$sel = $conn->prepare('SELECT id, photo_url FROM property_floor_images WHERE property_id = ? AND floor_number = ? AND (photo_url = ? OR photo_url LIKE ?) LIMIT 1');
$likePattern = '%' . basename($photoUrl);
$sel->bind_param('iiss', $propertyId, $floorNum, $photoUrl, $likePattern);
$sel->execute();
$res = $sel->get_result();
$imgRow = $res->fetch_assoc();
$sel->close();

if (!$imgRow) {
    echo json_encode(['success' => false, 'message' => 'Image not found']);
    exit();
}

// Delete DB row
$del = $conn->prepare('DELETE FROM property_floor_images WHERE id = ?');
$del->bind_param('i', $imgRow['id']);
$ok = $del->execute();
$del->close();

if ($ok) {
    // Re-normalize sort order for this floor
    $imgs = $conn->prepare('SELECT id FROM property_floor_images WHERE property_id = ? AND floor_number = ? ORDER BY sort_order ASC');
    $imgs->bind_param('ii', $propertyId, $floorNum);
    $imgs->execute();
    $list = $imgs->get_result()->fetch_all(MYSQLI_ASSOC);
    $imgs->close();

    $i = 1;
    foreach ($list as $item) {
        $upd = $conn->prepare('UPDATE property_floor_images SET sort_order = ? WHERE id = ?');
        $upd->bind_param('ii', $i, $item['id']);
        $upd->execute();
        $upd->close();
        $i++;
    }

    // Remove file from disk
    $fsPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $photoUrl);
    if (is_file($fsPath)) {
        @unlink($fsPath);
    }

    // Get remaining count
    $cntStmt = $conn->prepare('SELECT COUNT(*) as cnt FROM property_floor_images WHERE property_id = ? AND floor_number = ?');
    $cntStmt->bind_param('ii', $propertyId, $floorNum);
    $cntStmt->execute();
    $remaining = (int)$cntStmt->get_result()->fetch_assoc()['cnt'];
    $cntStmt->close();

    echo json_encode(['success' => true, 'remaining' => $remaining, 'floor_number' => $floorNum]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to delete image']);
}
exit();
