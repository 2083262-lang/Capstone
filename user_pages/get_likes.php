<?php
include '../connection.php';

header('Content-Type: application/json');

$property_id = isset($_GET['property_id']) ? (int)$_GET['property_id'] : 0;

if ($property_id > 0) {
    $stmt = $conn->prepare("SELECT Likes FROM property WHERE property_ID = ?");
    $stmt->bind_param("i", $property_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        echo json_encode(['success' => true, 'likes' => (int)$row['Likes']]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Property not found.']);
    }
    $stmt->close();
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid property ID.']);
}

$conn->close();
