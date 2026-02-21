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
$floor_number = isset($input['floor_number']) ? (int)$input['floor_number'] : 0;
$photo_id = isset($input['photo_id']) ? (int)$input['photo_id'] : 0;
$photo_url = isset($input['photo_url']) ? trim($input['photo_url']) : '';

if ($property_id <= 0 || $floor_number <= 0 || $photo_id <= 0 || empty($photo_url)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

// Delete from database
$sql_delete = "DELETE FROM property_floor_images WHERE id = ? AND property_id = ? AND floor_number = ?";
$stmt = $conn->prepare($sql_delete);
$stmt->bind_param('iii', $photo_id, $property_id, $floor_number);

if ($stmt->execute()) {
    // Delete file if it exists
    if (file_exists($photo_url)) {
        @unlink($photo_url);
    }
    
    // Re-normalize sort order for this floor
    $sql_renumber = "SELECT id FROM property_floor_images WHERE property_id = ? AND floor_number = ? ORDER BY sort_order ASC";
    $stmt2 = $conn->prepare($sql_renumber);
    $stmt2->bind_param('ii', $property_id, $floor_number);
    $stmt2->execute();
    $result = $stmt2->get_result();
    
    $order = 1;
    while ($row = $result->fetch_assoc()) {
        $sql_update = "UPDATE property_floor_images SET sort_order = ? WHERE id = ?";
        $stmt3 = $conn->prepare($sql_update);
        $stmt3->bind_param('ii', $order, $row['id']);
        $stmt3->execute();
        $stmt3->close();
        $order++;
    }
    $stmt2->close();
    
    $stmt->close();
    echo json_encode(['success' => true, 'message' => 'Floor photo deleted successfully']);
} else {
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Failed to delete photo']);
}
?>
