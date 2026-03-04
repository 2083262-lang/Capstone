<?php
session_start();
require_once __DIR__ . '/../config/session_timeout.php';
header('Content-Type: application/json');
require_once '../connection.php';

if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'agent') {
    echo json_encode(['success'=>false, 'message'=>'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid method']); exit(); }
$accountId = (int)$_SESSION['account_id'];
$propertyId = isset($_POST['property_id']) ? (int)$_POST['property_id'] : 0;
$photoUrl = isset($_POST['photo_url']) ? trim($_POST['photo_url']) : '';
if ($propertyId<=0 || $photoUrl==='') { echo json_encode(['success'=>false,'message'=>'Missing params']); exit(); }

// Ownership check
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
    echo json_encode(['success' => false, 'message' => 'Cannot delete images for sold properties']);
    exit();
}

// Confirm image belongs to property and get id + sort
$sel = $conn->prepare('SELECT PhotoURL, SortOrder FROM property_images WHERE property_ID=? AND PhotoURL=? LIMIT 1');
$sel->bind_param('is', $propertyId, $photoUrl);
$sel->execute();
$res = $sel->get_result();
$row = $res->fetch_assoc();
$sel->close();
if (!$row) { echo json_encode(['success'=>false,'message'=>'Image not found']); exit(); }

// Delete DB row
$del = $conn->prepare('DELETE FROM property_images WHERE property_ID=? AND PhotoURL=?');
$del->bind_param('is', $propertyId, $photoUrl);
$ok = $del->execute();
$del->close();

if ($ok) {
    // Re-normalize sort order compactly 1..n
    $imgs = $conn->prepare('SELECT PhotoURL FROM property_images WHERE property_ID=? ORDER BY SortOrder ASC');
    $imgs->bind_param('i', $propertyId);
    $imgs->execute();
    $list = $imgs->get_result()->fetch_all(MYSQLI_ASSOC);
    $imgs->close();
    $i = 1;
    foreach ($list as $img) {
        $upd = $conn->prepare('UPDATE property_images SET SortOrder=? WHERE property_ID=? AND PhotoURL=?');
        $upd->bind_param('iis', $i, $propertyId, $img['PhotoURL']);
        $upd->execute();
        $upd->close();
        $i++;
    }

    // Try to remove the file from disk
    $fsPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . $photoUrl; // ../uploads/...
    if (is_file($fsPath)) { @unlink($fsPath); }

    echo json_encode(['success'=>true]);
} else {
    echo json_encode(['success'=>false,'message'=>'Failed to delete image']);
}
exit();
