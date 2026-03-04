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

$property_id = isset($_GET['property_id']) ? (int)$_GET['property_id'] : 0;

if ($property_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid property ID']);
    exit;
}

try {
    // Get featured photos
    $featured_photos = [];
    $sql_featured = "SELECT PhotoID as id, PhotoURL as url, SortOrder FROM property_images WHERE property_ID = ? ORDER BY SortOrder ASC";
    $stmt = $conn->prepare($sql_featured);
    $stmt->bind_param('i', $property_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $featured_photos[] = $row;
    }
    $stmt->close();
    
    // Get floor photos grouped by floor number
    $floor_photos = [];
    $sql_floors = "SELECT id, floor_number, photo_url as url, sort_order FROM property_floor_images WHERE property_id = ? ORDER BY floor_number ASC, sort_order ASC";
    $stmt = $conn->prepare($sql_floors);
    $stmt->bind_param('i', $property_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $floor_num = $row['floor_number'];
        if (!isset($floor_photos[$floor_num])) {
            $floor_photos[$floor_num] = [];
        }
        $floor_photos[$floor_num][] = [
            'id' => $row['id'],
            'url' => $row['url'],
            'sort_order' => $row['sort_order']
        ];
    }
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'featured_photos' => $featured_photos,
        'floor_photos' => $floor_photos
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
