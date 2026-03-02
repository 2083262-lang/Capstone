<?php
session_start();
header('Content-Type: application/json');
include '../connection.php';
require_once __DIR__ . '/../mail_helper.php';
require_once __DIR__ . '/../email_template.php';

// Robust JSON error handling for easier debugging in UI
ini_set('display_errors', '0');
set_error_handler(function($errno, $errstr, $errfile, $errline) {
  $msg = "PHP Error [$errno]: $errstr at $errfile:$errline";
  echo json_encode(['success' => false, 'message' => $msg]);
  exit;
});
set_exception_handler(function($ex) {
  echo json_encode(['success' => false, 'message' => 'Exception: ' . $ex->getMessage()]);
  exit;
});
register_shutdown_function(function() {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
    echo json_encode(['success' => false, 'message' => 'Fatal error: ' . $e['message']]);
  }
});

if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'agent') {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$agent_account_id = (int)$_SESSION['account_id'];
$tour_id = isset($_POST['tour_id']) ? (int)$_POST['tour_id'] : 0;
$reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';

if ($tour_id <= 0 || $reason === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid request data']);
    exit;
}

// Verify ownership and fetch needed data
$sql = "SELECT tr.*, p.StreetAddress, p.City, p.Province, p.ZIP, a.first_name AS agent_first_name, a.last_name AS agent_last_name
        FROM tour_requests tr
        JOIN property p ON tr.property_id = p.property_ID
        JOIN accounts a ON tr.agent_account_id = a.account_id
        WHERE tr.tour_id = ? AND tr.agent_account_id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
  echo json_encode(['success' => false, 'message' => 'Database error (prepare): ' . $conn->error]);
  exit;
}
$stmt->bind_param('ii', $tour_id, $agent_account_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) {
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Tour request not found']);
    exit;
}
$tour = $res->fetch_assoc();
$stmt->close();

if ($tour['request_status'] === 'Cancelled') {
    echo json_encode(['success' => true, 'message' => 'This request is already cancelled.']);
    exit;
}

// Update status to Rejected and store decision metadata
$upd = $conn->prepare("UPDATE tour_requests SET request_status = 'Rejected', decision_reason = ?, decision_by = 'agent', decision_at = NOW() WHERE tour_id = ?");
if (!$upd) {
  echo json_encode(['success' => false, 'message' => 'Database error (prepare update): ' . $conn->error]);
  exit;
}
$upd->bind_param('si', $reason, $tour_id);
if (!$upd->execute()) {
    $upd->close();
    echo json_encode(['success' => false, 'message' => 'Failed to update status']);
    exit;
}
$upd->close();

// Compose email
$property_address = $tour['StreetAddress'] . ', ' . $tour['City'] . ', ' . $tour['Province'] . ' ' . $tour['ZIP'];
$formattedDate = date('F j, Y', strtotime($tour['tour_date']));
$formattedTime = date('g:i A', strtotime($tour['tour_time']));

try {
    $subject = 'Tour Request Update: Rejected by Agent';

    $bodyContent  = emailGreeting($tour['user_name']);
    $bodyContent .= emailParagraph('Thank you for your interest. Unfortunately, the property agent is unable to accommodate your tour request at this time.');
    $bodyContent .= emailDivider();
    $bodyContent .= emailInfoCard('Request Details', [
        'Property'           => htmlspecialchars($property_address),
        'Requested Schedule' => $formattedDate . ' at ' . $formattedTime,
    ]);
    $bodyContent .= emailNotice('Reason for Rejection', nl2br(htmlspecialchars($reason)), '#ef4444');
    $bodyContent .= emailNotice('Alternative Options', 'You may submit a new request with different dates/times or explore other available properties that match your criteria.', '#2563eb');
    $bodyContent .= emailClosing('We appreciate your interest and hope to assist you with other property viewings.');

    $body = buildEmailTemplate([
        'accentColor' => '#ef4444',
        'heading'     => 'Request Rejected',
        'subtitle'    => 'Your tour request could not be approved',
        'body'        => $bodyContent,
    ]);
    $res = sendSystemMail($tour['user_email'], $tour['user_name'], $subject, $body, 'Your tour request was rejected.');
    if (!empty($res['success'])) {
      echo json_encode(['success' => true, 'message' => 'Tour request rejected and email sent.']);
    } else {
      echo json_encode(['success' => true, 'message' => 'Tour request rejected. Email notification could not be sent.']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => true, 'message' => 'Status updated, but email failed.']);
}
