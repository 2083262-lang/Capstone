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

$property_address = $tour['StreetAddress'] . ', ' . $tour['City'] . ', ' . $tour['State'] . ' ' . $tour['ZIP'];
$formattedDate = date('F j, Y', strtotime($tour['tour_date']));
$formattedTime = date('g:i A', strtotime($tour['tour_time']));

$subject = 'Tour Request Update: Rejected by Admin';
$body = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tour Request Rejected</title>
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
                            <h1 style="margin:0 0 12px 0;color:#ef4444;font-size:14px;font-weight:600;text-transform:uppercase;letter-spacing:3px;">Request Rejected</h1>
                            <p style="margin:0;color:#666666;font-size:15px;font-weight:400;">Your tour request could not be approved</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:48px 48px 40px 48px;">
                            <p style="margin:0 0 24px 0;font-size:14px;color:#999999;line-height:1.7;">
                                Hello <span style="color:#d4af37;font-weight:500;">' . htmlspecialchars($tour['user_name']) . '</span>,
                            </p>
                            <p style="margin:0 0 32px 0;font-size:15px;color:#cccccc;line-height:1.8;">
                                Thank you for your interest. Unfortunately, your tour request has been reviewed and rejected by our administration team.
                            </p>
                            <div style="height:1px;background-color:#1f1f1f;margin:0 0 32px 0;"></div>
                            <div style="background-color:#0d1117;border-left:2px solid #d4af37;padding:20px 24px;margin:0 0 24px 0;">
                                <p style="margin:0 0 12px 0;font-size:13px;color:#d4af37;font-weight:600;text-transform:uppercase;letter-spacing:1px;">Request Details</p>
                                <p style="margin:0 0 8px 0;font-size:14px;color:#999999;"><strong style="color:#cccccc;">Property:</strong> ' . htmlspecialchars($property_address) . '</p>
                                <p style="margin:0;font-size:14px;color:#999999;"><strong style="color:#cccccc;">Requested Schedule:</strong> ' . $formattedDate . ' at ' . $formattedTime . '</p>
                            </div>
                            <div style="background-color:#0d1117;border-left:2px solid #ef4444;padding:16px 20px;margin:0 0 24px 0;">
                                <p style="margin:0;font-size:13px;color:#999999;line-height:1.6;">
                                    <strong style="color:#ef4444;display:block;margin-bottom:6px;font-size:12px;text-transform:uppercase;letter-spacing:1px;">Reason for Rejection</strong>
                                    ' . nl2br(htmlspecialchars($reason)) . '
                                </p>
                            </div>
                            <div style="background-color:#0d1117;border-left:2px solid #2563eb;padding:16px 20px;margin:0 0 24px 0;">
                                <p style="margin:0;font-size:13px;color:#999999;line-height:1.6;">
                                    <strong style="color:#2563eb;display:block;margin-bottom:6px;font-size:12px;text-transform:uppercase;letter-spacing:1px;">Next Steps</strong>
                                    You may browse other available properties or contact us for personalized assistance in finding the right property for you.
                                </p>
                            </div>
                            <p style="margin:0;font-size:13px;color:#666666;line-height:1.6;text-align:center;">
                                We appreciate your understanding and look forward to assisting you.
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
$res = sendSystemMail($tour['user_email'], $tour['user_name'], $subject, $body, 'Your tour request was rejected.');

echo json_encode(['success' => true, 'message' => !empty($res['success']) ? 'Tour rejected and email sent.' : 'Tour rejected. Email could not be sent.']);
?>
