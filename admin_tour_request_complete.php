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

$property_address = $tour['StreetAddress'] . ', ' . $tour['City'] . ', ' . $tour['Province'] . ' ' . $tour['ZIP'];
$formattedDate = date('F j, Y', strtotime($tour['tour_date']));
$formattedTime = date('g:i A', strtotime($tour['tour_time']));

$subject = 'Tour Request Completed';

require_once __DIR__ . '/email_template.php';

$bodyContent  = emailGreeting($tour['user_name']);
$bodyContent .= emailParagraph('Your property tour has been marked as completed. We hope you enjoyed viewing the property and found it to your liking.');
$bodyContent .= emailDivider();
$bodyContent .= emailInfoCard('Tour Details', [
    'Property' => htmlspecialchars($property_address),
    'Date'     => $formattedDate,
    'Time'     => $formattedTime,
]);
$bodyContent .= emailNotice("What's Next?", "If you're interested in this property or would like to schedule additional viewings, please don't hesitate to reach out. We're here to help you find your perfect home.", '#2563eb');
$bodyContent .= emailClosing('Thank you for choosing HomeEstate Realty. We look forward to assisting you further.');

$body = buildEmailTemplate([
    'accentColor' => '#22c55e',
    'heading'     => 'Tour Completed',
    'subtitle'    => 'Thank you for visiting the property',
    'body'        => $bodyContent,
]);
$res = sendSystemMail($tour['user_email'], $tour['user_name'], $subject, $body, 'Your tour request was completed.');

echo json_encode(['success' => true, 'message' => !empty($res['success']) ? 'Tour completed and email sent.' : 'Tour completed. Email could not be sent.']);
?>
