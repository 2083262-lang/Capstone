<?php
session_start();
require_once __DIR__ . '/config/session_timeout.php';
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

$sql = "SELECT tr.*, p.StreetAddress, p.City, p.Province, p.ZIP
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

$upd = $conn->prepare("UPDATE tour_requests SET request_status = 'Rejected', decision_reason = ?, decision_by = 'admin', decision_at = NOW() WHERE tour_id = ?");
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

$property_address = $tour['StreetAddress'] . ', ' . $tour['City'] . ', ' . $tour['Province'] . ' ' . $tour['ZIP'];
$formattedDate = date('F j, Y', strtotime($tour['tour_date']));
$formattedTime = date('g:i A', strtotime($tour['tour_time']));

$subject = 'Tour Request Update: Rejected by Admin';

require_once __DIR__ . '/email_template.php';

$bodyContent  = emailGreeting($tour['user_name']);
$bodyContent .= emailParagraph('Thank you for your interest. Unfortunately, your tour request has been reviewed and rejected by our administration team.');
$bodyContent .= emailDivider();
$bodyContent .= emailInfoCard('Request Details', [
    'Property'           => htmlspecialchars($property_address),
    'Requested Schedule' => $formattedDate . ' at ' . $formattedTime,
]);
$bodyContent .= emailNotice('Reason for Rejection', nl2br(htmlspecialchars($reason)), '#ef4444');
$bodyContent .= emailNotice('Next Steps', 'You may browse other available properties or contact us for personalized assistance in finding the right property for you.', '#2563eb');
$bodyContent .= emailClosing('We appreciate your understanding and look forward to assisting you.');

$body = buildEmailTemplate([
    'accentColor' => '#ef4444',
    'heading'     => 'Request Rejected',
    'subtitle'    => 'Your tour request could not be approved',
    'body'        => $bodyContent,
]);
$res = sendSystemMail($tour['user_email'], $tour['user_name'], $subject, $body, 'Your tour request was rejected.');

echo json_encode(['success' => true, 'message' => !empty($res['success']) ? 'Tour rejected and email sent.' : 'Tour rejected. Email could not be sent.']);
?>
