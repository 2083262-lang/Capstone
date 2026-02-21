<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/mail_helper.php';

if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$tour_id = isset($_POST['tour_id']) ? (int)$_POST['tour_id'] : 0;
if ($tour_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

$sql = "SELECT tr.*, p.StreetAddress, p.City, p.State, p.ZIP
        FROM tour_requests tr
        JOIN property p ON tr.property_id = p.property_ID
        JOIN property_log pl ON pl.property_id = p.property_ID AND pl.action = 'CREATED'
        JOIN accounts a ON a.account_id = pl.account_id
        JOIN user_roles ur ON ur.role_id = a.role_id
        WHERE tr.tour_id = ? AND ur.role_name = 'admin'";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $tour_id);
$stmt->execute();
$tour = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$tour) {
    echo json_encode(['success' => false, 'message' => 'Tour request not found.']);
    exit;
}

if (strcasecmp($tour['request_status'], 'Confirmed') !== 0) {
    echo json_encode(['success' => false, 'message' => 'Only confirmed tours can be completed.']);
    exit;
}

$upd = $conn->prepare("UPDATE tour_requests SET request_status = 'Completed', completed_at = NOW() WHERE tour_id = ?");
if (!$upd) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}
$upd->bind_param('i', $tour_id);
$ok = $upd->execute();
$upd->close();

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Failed to update status']);
    exit;
}

$property_address = $tour['StreetAddress'] . ', ' . $tour['City'] . ', ' . $tour['State'] . ' ' . $tour['ZIP'];
$formattedDate = date('F j, Y', strtotime($tour['tour_date']));
$formattedTime = date('g:i A', strtotime($tour['tour_time']));

$subject = 'Tour Request Completed';
$body = "<div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>
  <div style='background:#161209;color:#fff;padding:16px 20px;'>
    <h2 style='margin:0;font-weight:700;'>Tour Completed</h2>
  </div>
  <div style='padding:20px;background:#f9f9f9;border:1px solid #e6e6e6;'>
    <p>Hello " . htmlspecialchars($tour['user_name']) . ",</p>
    <p>Your property tour has been marked as completed.</p>
    <div style='background:#fff;border-left:4px solid #bc9e42;padding:12px 16px;margin:16px 0;'>
      <p style='margin:0;'><strong>Property:</strong> " . htmlspecialchars($property_address) . "</p>
      <p style='margin:0;'><strong>Date & Time:</strong> $formattedDate at $formattedTime</p>
    </div>
    <p>Thank you for using our Real Estate System.</p>
  </div>
  <div style='text-align:center;padding:12px;background:#f0f0f0;color:#666;font-size:12px;'>
    <p style='margin:0;'>This is an automated message from the Real Estate System.</p>
  </div>
</div>";
$res = sendSystemMail($tour['user_email'], $tour['user_name'], $subject, $body, 'Your tour request was completed.');

echo json_encode(['success' => true, 'message' => !empty($res['success']) ? 'Tour completed and email sent.' : 'Tour completed. Email could not be sent.']);
?>
