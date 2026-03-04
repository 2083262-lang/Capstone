<?php
session_start();
require_once('../connection.php');
require_once __DIR__ . '/../config/session_timeout.php';

header('Content-Type: application/json');

// Check if agent is logged in
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'agent') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$agent_id = (int)$_SESSION['account_id'];

// Validate required fields
if (!isset($_POST['property_id']) || !isset($_POST['new_price'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
    exit;
}

$property_id = (int)$_POST['property_id'];
$new_price = (float)$_POST['new_price'];
$reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';

// Validate new price
if ($new_price <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid price. Must be greater than 0.']);
    exit;
}

// Verify property ownership through property_log (align with schema: property_ID)
$check_query = "SELECT p.property_ID, p.ListingPrice, p.Status 
                FROM property p 
                INNER JOIN property_log pl ON p.property_ID = pl.property_id 
                WHERE p.property_ID = ? AND pl.account_id = ?";
$stmt = $conn->prepare($check_query);
$stmt->bind_param("ii", $property_id, $agent_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Property not found or unauthorized.']);
    exit;
}

$property = $result->fetch_assoc();
$current_price = (float)$property['ListingPrice'];

// Check if property is sold (locked for editing)
if ($property['Status'] === 'Sold') {
    echo json_encode(['success' => false, 'message' => 'This property has been sold and is locked for editing to maintain historical accuracy.']);
    exit;
}

// Check if price is actually different
if (abs($new_price - $current_price) < 0.01) {
    echo json_encode(['success' => false, 'message' => 'New price is the same as current price.']);
    exit;
}

// Calculate percentage change
$price_diff = $new_price - $current_price;
$percent_change = ($price_diff / $current_price) * 100;

// Begin transaction
$conn->begin_transaction();

try {
    // Update property price
    $update_query = "UPDATE property SET ListingPrice = ? WHERE property_ID = ?";
    $update_stmt = $conn->prepare($update_query);
    $update_stmt->bind_param("di", $new_price, $property_id);
    
    if (!$update_stmt->execute()) {
        throw new Exception('Failed to update property price.');
    }
    
    // Insert into price_history table (columns: property_id, event_date, event_type, price)
    $event_type = $price_diff > 0 ? 'Price Increase' : 'Price Decrease';
    $history_query = "INSERT INTO price_history (property_id, event_date, event_type, price) 
                      VALUES (?, NOW(), ?, ?)";
    $history_stmt = $conn->prepare($history_query);
    $history_stmt->bind_param("isd", $property_id, $event_type, $new_price);
    
    if (!$history_stmt->execute()) {
        throw new Exception('Failed to record price history.');
    }
    
    // Commit transaction
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Price updated successfully!',
        'new_price' => $new_price,
        'old_price' => $current_price,
        'change' => $price_diff,
        'percent_change' => $percent_change
    ]);
    
} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?>
