<?php
session_start();
include '../connection.php';
require_once __DIR__ . '/../mail_helper.php';

// Check authentication
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'agent') {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit();
}

// Validate tour_id
if (!isset($_POST['tour_id']) || !is_numeric($_POST['tour_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid tour request ID']);
    exit();
}

$tour_id = (int)$_POST['tour_id'];
$agent_id = (int)$_SESSION['account_id'];

// Start transaction
$conn->begin_transaction();

try {
    // Verify the agent owns this tour request
    $check_sql = "SELECT * FROM tour_requests WHERE tour_id = ? AND agent_account_id = ? AND request_status = 'Confirmed'";
    $stmt = $conn->prepare($check_sql);
    $stmt->bind_param('ii', $tour_id, $agent_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception('Tour request not found or not eligible to be marked as completed');
    }
    
    // Update the tour status
    $update_sql = "UPDATE tour_requests SET request_status = 'Completed', completed_at = NOW() WHERE tour_id = ?";
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param('i', $tour_id);
    if (!$stmt->execute()) {
        $err = $stmt->error ?: $conn->error;
        throw new Exception('Failed to update tour status: ' . $err);
    }
    if ($stmt->affected_rows <= 0) {
        throw new Exception('No rows updated. It may already be completed or not found.');
    }
    
    // Get user and property info for notification
    $info_sql = "SELECT tr.user_email, tr.user_name, tr.tour_date, tr.tour_time, 
                       p.StreetAddress, p.City, p.State 
                FROM tour_requests tr
                JOIN property p ON tr.property_id = p.property_ID
                WHERE tr.tour_id = ?";
    $stmt = $conn->prepare($info_sql);
    $stmt->bind_param('i', $tour_id);
    $stmt->execute();
    $tour_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    // Fetch the actual completed_at from DB to return
    $ts_stmt = $conn->prepare("SELECT completed_at FROM tour_requests WHERE tour_id = ?");
    $ts_stmt->bind_param('i', $tour_id);
    $ts_stmt->execute();
    $ts_res = $ts_stmt->get_result()->fetch_assoc();
    $completed_at = $ts_res ? $ts_res['completed_at'] : null;
    $ts_stmt->close();
    
    // Commit transaction
    $conn->commit();
    
    // Send email notification to user
    $property_address = $tour_info['StreetAddress'] . ', ' . $tour_info['City'] . ', ' . $tour_info['State'];
    $formattedDate = date('F j, Y', strtotime($tour_info['tour_date']));
    $formattedTime = date('g:i A', strtotime($tour_info['tour_time']));
    
    try {
        $subject = 'Tour Completed - Thank You for Visiting';
        $body = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tour Completed</title>
</head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,\'Helvetica Neue\',Arial,sans-serif;background-color:#0a0a0a;line-height:1.6;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#0a0a0a;padding:60px 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color:#111111;border:1px solid #1f1f1f;border-radius:4px;max-width:600px;">
                    <tr>
                        <td style="background:linear-gradient(90deg,#22c55e 0%,#16a34a 50%,#22c55e 100%);height:3px;"></td>
                    </tr>
                    <tr>
                        <td style="padding:48px 48px 32px 48px;text-align:center;border-bottom:1px solid #1f1f1f;">
                            <h1 style="margin:0 0 12px 0;color:#22c55e;font-size:14px;font-weight:600;text-transform:uppercase;letter-spacing:3px;">Tour Completed</h1>
                            <p style="margin:0;color:#666666;font-size:15px;font-weight:400;">Thank you for visiting the property</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:48px 48px 40px 48px;">
                            <p style="margin:0 0 24px 0;font-size:14px;color:#999999;line-height:1.7;">
                                Hello <span style="color:#d4af37;font-weight:500;">' . htmlspecialchars($tour_info['user_name']) . '</span>,
                            </p>
                            <p style="margin:0 0 32px 0;font-size:15px;color:#cccccc;line-height:1.8;">
                                Your property tour has been marked as completed. We hope you enjoyed viewing the property and found it to your liking.
                            </p>
                            <div style="height:1px;background-color:#1f1f1f;margin:0 0 32px 0;"></div>
                            <div style="background-color:#0d1117;border-left:2px solid #d4af37;padding:20px 24px;margin:0 0 24px 0;">
                                <p style="margin:0 0 12px 0;font-size:13px;color:#d4af37;font-weight:600;text-transform:uppercase;letter-spacing:1px;">Tour Details</p>
                                <p style="margin:0 0 8px 0;font-size:14px;color:#999999;"><strong style="color:#cccccc;">Property:</strong> ' . htmlspecialchars($property_address) . '</p>
                                <p style="margin:0 0 8px 0;font-size:14px;color:#999999;"><strong style="color:#cccccc;">Date:</strong> ' . $formattedDate . '</p>
                                <p style="margin:0;font-size:14px;color:#999999;"><strong style="color:#cccccc;">Time:</strong> ' . $formattedTime . '</p>
                            </div>
                            <div style="background-color:#0d1117;border-left:2px solid #2563eb;padding:16px 20px;margin:0 0 24px 0;">
                                <p style="margin:0;font-size:13px;color:#999999;line-height:1.6;">
                                    <strong style="color:#2563eb;display:block;margin-bottom:6px;font-size:12px;text-transform:uppercase;letter-spacing:1px;">What\'s Next?</strong>
                                    If you\'re interested in this property or would like to schedule additional viewings, please don\'t hesitate to reach out. We\'re here to help you find your perfect home.
                                </p>
                            </div>
                            <p style="margin:0;font-size:13px;color:#666666;line-height:1.6;text-align:center;">
                                Thank you for choosing HomeEstate Realty. We look forward to assisting you further.
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
        
        $emailResult = sendSystemMail($tour_info['user_email'], $tour_info['user_name'], $subject, $body, 'Your tour has been completed.');
        $emailStatus = !empty($emailResult['success']) ? 'Email sent successfully.' : 'Email could not be sent.';
    } catch (Exception $e) {
        $emailStatus = 'Email notification failed.';
    }
    
    // Return success response
    echo json_encode([
        'success' => true, 
        'message' => 'Tour marked as completed successfully. ' . ($emailStatus ?? ''),
        'completed_at' => $completed_at,
        'user_info' => [
            'name' => $tour_info['user_name'],
            'email' => $tour_info['user_email']
        ],
        'property_info' => [
            'address' => $tour_info['StreetAddress'] . ', ' . $tour_info['City'] . ', ' . $tour_info['State']
        ]
    ]);
} catch (Exception $e) {
    // Roll back transaction on error
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} finally {
    $conn->close();
}