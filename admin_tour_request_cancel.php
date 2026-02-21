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
$reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';
if ($tour_id <= 0 || $reason === '') {
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

$upd = $conn->prepare("UPDATE tour_requests SET request_status = 'Cancelled', decision_reason = ?, decision_by = 'admin', decision_at = NOW() WHERE tour_id = ?");
if (!$upd) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}
$upd->bind_param('si', $reason, $tour_id);
$ok = $upd->execute();
$upd->close();

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Failed to update status']);
    exit;
}

$property_address = $tour['StreetAddress'] . ', ' . $tour['City'] . ', ' . $tour['State'] . ' ' . $tour['ZIP'];
$formattedDate = date('F j, Y', strtotime($tour['tour_date']));
$formattedTime = date('g:i A', strtotime($tour['tour_time']));

$subject = 'Tour Request Update: Cancelled by Admin';
$body = "<div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>
  <div style='background:#161209;color:#fff;padding:16px 20px;'>
    <h2 style='margin:0;font-weight:700;'>Tour Request Cancelled</h2>
  </div>
  <div style='padding:20px;background:#f9f9f9;border:1px solid #e6e6e6;'>
    <p>Hello " . htmlspecialchars($tour['user_name']) . ",</p>
    <p>Your tour request has been cancelled by the admin.</p>
    <div style='background:#fff;border-left:4px solid #bc9e42;padding:12px 16px;margin:16px 0;'>
      <p style='margin:0;'><strong>Property:</strong> " . htmlspecialchars($property_address) . "</p>
      <p style='margin:0;'><strong>Scheduled:</strong> $formattedDate at $formattedTime</p>
    </div>
    <div style='background:#fff;border:1px dashed #e6e6e6;padding:12px 16px;border-radius:8px;'>
      <p style='margin:0 0 6px 0;'><strong>Reason:</strong></p>
      <p style='margin:0;font-style:italic;color:#555;'>" . nl2br(htmlspecialchars($reason)) . "</p>
    </div>
  </div>
  <div style='text-align:center;padding:12px;background:#f0f0f0;color:#666;font-size:12px;'>
    <p style='margin:0;'>This is an automated message from the Real Estate System.</p>
  </div>
</div>";
$res = sendSystemMail($tour['user_email'], $tour['user_name'], $subject, $body, 'Your tour request was cancelled.');

echo json_encode(['success' => true, 'message' => !empty($res['success']) ? 'Tour cancelled and email sent.' : 'Tour cancelled. Email could not be sent.']);
?>
