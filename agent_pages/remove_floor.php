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

if ($propertyId <= 0 || $floorNum < 1) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
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

// Get all image URLs for this floor (so we can delete files)
$imgs = $conn->prepare('SELECT photo_url FROM property_floor_images WHERE property_id = ? AND floor_number = ?');
$imgs->bind_param('ii', $propertyId, $floorNum);
$imgs->execute();
$imageRows = $imgs->get_result()->fetch_all(MYSQLI_ASSOC);
$imgs->close();

// Delete all DB rows for this floor
$del = $conn->prepare('DELETE FROM property_floor_images WHERE property_id = ? AND floor_number = ?');
$del->bind_param('ii', $propertyId, $floorNum);
$ok = $del->execute();
$deletedCount = $del->affected_rows;
$del->close();

if ($ok) {
    // Remove files from disk
    foreach ($imageRows as $imgItem) {
        $fsPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $imgItem['photo_url']);
        if (is_file($fsPath)) {
            @unlink($fsPath);
        }
    }

    // Try to remove the floor directory if empty
    $floorDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'floors' . DIRECTORY_SEPARATOR . $propertyId . DIRECTORY_SEPARATOR . 'floor_' . $floorNum;
    if (is_dir($floorDir)) {
        $remaining = array_diff(scandir($floorDir), ['.', '..']);
        if (empty($remaining)) {
            @rmdir($floorDir);
        }
    }

    // Renumber remaining floors to keep them sequential
    // Get all distinct floor numbers above the removed one
    $floorsAbove = $conn->prepare('SELECT DISTINCT floor_number FROM property_floor_images WHERE property_id = ? AND floor_number > ? ORDER BY floor_number ASC');
    $floorsAbove->bind_param('ii', $propertyId, $floorNum);
    $floorsAbove->execute();
    $aboveList = $floorsAbove->get_result()->fetch_all(MYSQLI_ASSOC);
    $floorsAbove->close();

    foreach ($aboveList as $aboveFloor) {
        $oldFloor = (int)$aboveFloor['floor_number'];
        $newFloor = $oldFloor - 1;

        // Get images for renaming paths
        $getImgs = $conn->prepare('SELECT id, photo_url FROM property_floor_images WHERE property_id = ? AND floor_number = ?');
        $getImgs->bind_param('ii', $propertyId, $oldFloor);
        $getImgs->execute();
        $floorImgs = $getImgs->get_result()->fetch_all(MYSQLI_ASSOC);
        $getImgs->close();

        // Create new floor directory
        $newFloorDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'floors' . DIRECTORY_SEPARATOR . $propertyId . DIRECTORY_SEPARATOR . 'floor_' . $newFloor;
        if (!is_dir($newFloorDir)) {
            mkdir($newFloorDir, 0755, true);
        }

        foreach ($floorImgs as $fi) {
            // Move physical file
            $oldPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $fi['photo_url']);
            $filename = basename($fi['photo_url']);
            $newPath = $newFloorDir . DIRECTORY_SEPARATOR . $filename;
            $newDbPath = 'uploads/floors/' . $propertyId . '/floor_' . $newFloor . '/' . $filename;

            if (is_file($oldPath)) {
                @rename($oldPath, $newPath);
            }

            // Update DB row
            $upd = $conn->prepare('UPDATE property_floor_images SET floor_number = ?, photo_url = ? WHERE id = ?');
            $upd->bind_param('isi', $newFloor, $newDbPath, $fi['id']);
            $upd->execute();
            $upd->close();
        }

        // Remove old floor directory if empty
        $oldFloorDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'floors' . DIRECTORY_SEPARATOR . $propertyId . DIRECTORY_SEPARATOR . 'floor_' . $oldFloor;
        if (is_dir($oldFloorDir)) {
            $remainingFiles = array_diff(scandir($oldFloorDir), ['.', '..']);
            if (empty($remainingFiles)) {
                @rmdir($oldFloorDir);
            }
        }
    }

    echo json_encode(['success' => true, 'deleted_count' => $deletedCount, 'floor_number' => $floorNum]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to remove floor']);
}
exit();
