<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/connection.php';

if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$tour_id = isset($_REQUEST['tour_id']) ? (int)$_REQUEST['tour_id'] : 0;
if ($tour_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid tour ID']);
    exit;
}

// Ensure the tour request belongs to a property listed by an admin
$sql = "
SELECT tr.*, 
       p.StreetAddress, p.City, p.Province, p.ZIP, p.PropertyType,
       a_agent.first_name AS agent_first_name, a_agent.last_name AS agent_last_name,
       a_list.first_name AS poster_first_name, a_list.last_name AS poster_last_name,
       ur.role_name AS poster_role
FROM tour_requests tr
JOIN property p ON p.property_ID = tr.property_id
LEFT JOIN accounts a_agent ON a_agent.account_id = tr.agent_account_id
JOIN property_log pl ON pl.property_id = p.property_ID AND pl.action = 'CREATED'
JOIN accounts a_list ON a_list.account_id = pl.account_id
JOIN user_roles ur ON ur.role_id = a_list.role_id
WHERE tr.tour_id = ? AND ur.role_name = 'admin'
";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $tour_id);
$stmt->execute();
$res = $stmt->get_result();
$data = $res->fetch_assoc();
$stmt->close();

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Tour request not found for admin-listed property.']);
    exit;
}

// Format fields for convenience
$data['tour_date_fmt'] = $data['tour_date'] ? date('F j, Y', strtotime($data['tour_date'])) : '';
$data['tour_time_fmt'] = $data['tour_time'] ? date('g:i A', strtotime($data['tour_time'])) : '';
$data['requested_at_fmt'] = $data['requested_at'] ? date('F j, Y g:i A', strtotime($data['requested_at'])) : '';
$data['confirmed_at_fmt'] = $data['confirmed_at'] ? date('F j, Y g:i A', strtotime($data['confirmed_at'])) : '';
$data['completed_at_fmt'] = $data['completed_at'] ? date('F j, Y g:i A', strtotime($data['completed_at'])) : '';
$data['decision_at_fmt'] = $data['decision_at'] ? date('F j, Y g:i A', strtotime($data['decision_at'])) : '';

echo json_encode(['success' => true, 'data' => $data]);
?>
