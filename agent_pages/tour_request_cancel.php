<?php
session_start();
header('Content-Type: application/json');
include '../connection.php';
require_once __DIR__ . '/../mail_helper.php';

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
$sql = "SELECT tr.*, p.StreetAddress, p.City, p.State, p.ZIP, a.first_name AS agent_first_name, a.last_name AS agent_last_name
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

// Update status to Cancelled and store decision metadata
$upd = $conn->prepare("UPDATE tour_requests SET request_status = 'Cancelled', decision_reason = ?, decision_by = 'agent', decision_at = NOW() WHERE tour_id = ?");
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
$property_address = $tour['StreetAddress'] . ', ' . $tour['City'] . ', ' . $tour['State'] . ' ' . $tour['ZIP'];
$formattedDate = date('F j, Y', strtotime($tour['tour_date']));
$formattedTime = date('g:i A', strtotime($tour['tour_time']));

try {
    $subject = 'Tour Schedule Cancelled by Agent';

    $body = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tour Cancelled</title>
</head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,\'Helvetica Neue\',Arial,sans-serif;background-color:#0a0a0a;line-height:1.6;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#0a0a0a;padding:60px 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color:#111111;border:1px solid #1f1f1f;border-radius:4px;max-width:600px;">
                    <tr>
                        <td style="background:linear-gradient(90deg,#ef4444 0%,#dc2626 50%,#ef4444 100%);height:3px;"></td>
                    </tr>
                    <tr>
                        <td style="padding:48px 48px 32px 48px;text-align:center;border-bottom:1px solid #1f1f1f;">
                            <h1 style="margin:0 0 12px 0;color:#ef4444;font-size:14px;font-weight:600;text-transform:uppercase;letter-spacing:3px;">Tour Cancelled</h1>
                            <p style="margin:0;color:#666666;font-size:15px;font-weight:400;">Your tour schedule has been cancelled</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:48px 48px 40px 48px;">
                            <p style="margin:0 0 24px 0;font-size:14px;color:#999999;line-height:1.7;">
                                Hello <span style="color:#d4af37;font-weight:500;">' . htmlspecialchars($tour['user_name']) . '</span>,
                            </p>
                            <p style="margin:0 0 32px 0;font-size:15px;color:#cccccc;line-height:1.8;">
                                We regret to inform you that your previously confirmed tour has been cancelled by the property agent.
                            </p>
                            <div style="height:1px;background-color:#1f1f1f;margin:0 0 32px 0;"></div>
                            <div style="background-color:#0d1117;border-left:2px solid #d4af37;padding:20px 24px;margin:0 0 24px 0;">
                                <p style="margin:0 0 12px 0;font-size:13px;color:#d4af37;font-weight:600;text-transform:uppercase;letter-spacing:1px;">Cancellation Details</p>
                                <p style="margin:0 0 8px 0;font-size:14px;color:#999999;"><strong style="color:#cccccc;">Property:</strong> ' .htmlspecialchars($property_address) . '</p>
                                <p style="margin:0;font-size:14px;color:#999999;"><strong style="color:#cccccc;">Original Schedule:</strong> ' . $formattedDate . ' at ' . $formattedTime . '</p>
                            </div>
                            <div style="background-color:#0d1117;border-left:2px solid #ef4444;padding:16px 20px;margin:0 0 24px 0;">
                                <p style="margin:0;font-size:13px;color:#999999;line-height:1.6;">
                                    <strong style="color:#ef4444;display:block;margin-bottom:6px;font-size:12px;text-transform:uppercase;letter-spacing:1px;">Reason for Cancellation</strong>
                                    ' . nl2br(htmlspecialchars($reason)) . '
                                </p>
                            </div>
                            <div style="background-color:#0d1117;border-left:2px solid #2563eb;padding:16px 20px;margin:0 0 24px 0;">
                                <p style="margin:0;font-size:13px;color:#999999;line-height:1.6;">
                                    <strong style="color:#2563eb;display:block;margin-bottom:6px;font-size:12px;text-transform:uppercase;letter-spacing:1px;">What You Can Do</strong>
                                    You may submit a new tour request if you are still interested in viewing this property. We apologize for any inconvenience.
                                </p>
                            </div>
                            <p style="margin:0;font-size:13px;color:#666666;line-height:1.6;text-align:center;">
                                We appreciate your understanding and look forward to serving you.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color:#0a0a0a;padding:32px 48px;border-top:1px solid #1f1f1f;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="text-align:center;">
                                        <p style="margin:0 0 8px 0;font-size:13px;color:#666666;">
                                            <strong style="color:#d4af37;">HomeEstate Realty</strong>
                                        </p>
                                        <p style="margin:0;font-size:11px;color:#444444;">
                                            © ' . date('Y') . ' All rights reserved
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;margin-top:32px;">
                    <tr>
                        <td style="text-align:center;">
                            <p style="margin:0;font-size:12px;color:#444444;">
                                Need assistance? <a href="#" style="color:#2563eb;text-decoration:none;font-weight:500;">Contact Support</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';

    $res = sendSystemMail($tour['user_email'], $tour['user_name'], $subject, $body, 'Your tour schedule was cancelled.');
    if (!empty($res['success'])) {
      echo json_encode(['success' => true, 'message' => 'Tour has been cancelled and email sent.']);
    } else {
      echo json_encode(['success' => true, 'message' => 'Tour has been cancelled. Email notification could not be sent.']);
    }
    exit;
} catch (Exception $e) {
  echo json_encode(['success' => true, 'message' => 'Status updated, but email failed.']);
  exit;
}
