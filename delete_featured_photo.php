<?php
session_start();
header('Content-Type: application/json');
include 'connection.php';

// Check if admin is logged in
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$property_id = isset($input['property_id']) ? (int)$input['property_id'] : 0;
$photo_id = isset($input['photo_id']) ? (int)$input['photo_id'] : 0;
$photo_url = isset($input['photo_url']) ? trim($input['photo_url']) : '';

if ($property_id <= 0 || $photo_id <= 0 || empty($photo_url)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

// Check if this is the only photo
$sql_check = "SELECT COUNT(*) as count FROM property_images WHERE property_ID = ?";
$stmt = $conn->prepare($sql_check);
$stmt->bind_param('i', $property_id);
$stmt->execute();
$count = $stmt->get_result()->fetch_assoc()['count'];
$stmt->close();

if ($count <= 1) {
    echo json_encode(['success' => false, 'message' => 'Cannot delete the only photo. Property must have at least one photo.']);
    exit;
}

// Delete from database
$sql_delete = "DELETE FROM property_images WHERE PhotoID = ? AND property_ID = ?";
$stmt = $conn->prepare($sql_delete);
$stmt->bind_param('ii', $photo_id, $property_id);

if ($stmt->execute()) {
    // Delete file if it exists
    if (file_exists($photo_url)) {
        @unlink($photo_url);
    }
    
    // Re-normalize sort order
    $sql_renumber = "SELECT PhotoID FROM property_images WHERE property_ID = ? ORDER BY SortOrder ASC";
    $stmt2 = $conn->prepare($sql_renumber);
    $stmt2->bind_param('i', $property_id);
    $stmt2->execute();
    $result = $stmt2->get_result();
    
    $order = 1;
    while ($row = $result->fetch_assoc()) {
        $sql_update = "UPDATE property_images SET SortOrder = ? WHERE PhotoID = ?";
        $stmt3 = $conn->prepare($sql_update);
        $stmt3->bind_param('ii', $order, $row['PhotoID']);
        $stmt3->execute();
        $stmt3->close();
        $order++;
    }
    $stmt2->close();
    
    $stmt->close();
    echo json_encode(['success' => true, 'message' => 'Photo deleted successfully']);
} else {
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Failed to delete photo']);
}
?>
