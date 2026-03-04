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

// Validate property_id parameter
if (!isset($_GET['property_id'])) {
    echo json_encode(['success' => false, 'message' => 'Property ID is required.']);
    exit;
}

$property_id = (int)$_GET['property_id'];

// Verify property ownership through property_log
$check_query = "SELECT property_id 
                FROM property_log 
                WHERE property_id = ? AND account_id = ?";
$stmt = $conn->prepare($check_query);
$stmt->bind_param("ii", $property_id, $agent_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Property not found or unauthorized.']);
    exit;
}

// Fetch tour requests with user information (align with existing schema used elsewhere)
$query = "SELECT 
                        tr.tour_id,
                        tr.tour_date as preferred_date,
                        tr.tour_time as preferred_time,
                        tr.message as message,
                        tr.request_status as request_status,
                        tr.requested_at as request_date,
                        tr.user_name as user_name,
                        tr.user_email as user_email,
                        tr.user_phone as user_phone
                    FROM tour_requests tr
                    WHERE tr.property_id = ? AND tr.agent_account_id = ?
                    ORDER BY 
                        CASE tr.request_status
                                WHEN 'Pending' THEN 1
                                WHEN 'Confirmed' THEN 2
                                WHEN 'Completed' THEN 3
                                WHEN 'Cancelled' THEN 4
                                WHEN 'Rejected' THEN 5
                                ELSE 6
                        END,
                        tr.requested_at DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $property_id, $agent_id);
$stmt->execute();
$result = $stmt->get_result();

$requests = [];
while ($row = $result->fetch_assoc()) {
    // Format dates
    if ($row['preferred_date']) {
        $row['preferred_date'] = date('F j, Y', strtotime($row['preferred_date']));
    }
    if ($row['preferred_time']) {
        $row['preferred_time'] = date('g:i A', strtotime($row['preferred_time']));
    }
    if ($row['request_date']) {
        $row['request_date'] = date('F j, Y g:i A', strtotime($row['request_date']));
    }
    
    $requests[] = $row;
}

echo json_encode([
    'success' => true,
    'requests' => $requests,
    'total' => count($requests)
]);

$conn->close();
?>
