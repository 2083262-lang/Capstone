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
    echo json_encode(['success' => false, 'message' => 'Invalid tour request.']);
    exit;
}

// Load tour ensuring the property was listed by an admin
$sql = "SELECT tr.*, p.StreetAddress, p.City
        FROM tour_requests tr
        JOIN property p ON p.property_ID = tr.property_id
        JOIN property_log pl ON pl.property_id = p.property_ID AND pl.action = 'CREATED'
        JOIN accounts a ON a.account_id = pl.account_id
        JOIN user_roles ur ON ur.role_id = a.role_id
        WHERE tr.tour_id = ? AND ur.role_name = 'admin'";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $tour_id);
$stmt->execute();
$req = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$req) {
    echo json_encode(['success' => false, 'message' => 'Tour not found or not an admin-listed property.']);
    exit;
}

if ($req['request_status'] === 'Confirmed' && !empty($req['confirmed_at'])) {
    echo json_encode(['success' => true, 'message' => 'This tour is already confirmed.']);
    exit;
}

// Only Pending tours can be confirmed
if ($req['request_status'] !== 'Pending') {
    echo json_encode(['success' => false, 'message' => 'Only pending tour requests can be confirmed. Current status: ' . $req['request_status']]);
    exit;
}

$conn->begin_transaction();
try {
    // Lock the row first to prevent race conditions
    $lock = $conn->prepare("SELECT tour_id FROM tour_requests WHERE tour_id = ? FOR UPDATE");
    if (!$lock) throw new Exception('Prepare failed: ' . $conn->error);
    $lock->bind_param('i', $tour_id);
    if (!$lock->execute()) throw new Exception('Lock failed: ' . ($lock->error ?: $conn->error));
    $locked = $lock->get_result()->fetch_assoc();
    $lock->close();
    if (!$locked) throw new Exception('Tour not found.');

    // Re-check status under lock to prevent race conditions
    $statusCheck = $conn->prepare("SELECT request_status FROM tour_requests WHERE tour_id = ?");
    $statusCheck->bind_param('i', $tour_id);
    $statusCheck->execute();
    $currentStatus = $statusCheck->get_result()->fetch_assoc();
    $statusCheck->close();
    if ($currentStatus['request_status'] !== 'Pending') {
        throw new Exception('Tour is no longer pending. Current status: ' . $currentStatus['request_status']);
    }

    // Prevent conflicts with public/private grouping rules
    // Check for exact time match AND tours within 30-minute proximity (agent can't physically be at two places)
    $check = $conn->prepare("
        SELECT tr.tour_id, tr.tour_type, tr.property_id, tr.tour_time
        FROM tour_requests tr
        JOIN property p ON tr.property_id = p.property_ID
        JOIN property_log pl ON pl.property_id = p.property_ID AND pl.action = 'CREATED'
        JOIN accounts a ON a.account_id = pl.account_id
        JOIN user_roles ur ON ur.role_id = a.role_id
        WHERE ur.role_name = 'admin'
          AND tr.tour_date = ?
          AND tr.tour_id <> ?
          AND tr.request_status = 'Confirmed'
          AND ABS(TIMESTAMPDIFF(MINUTE, tr.tour_time, ?)) < 30
    ");
    if (!$check) throw new Exception('Prepare failed: ' . $conn->error);
    $check->bind_param('sis', $req['tour_date'], $tour_id, $req['tour_time']);
    if (!$check->execute()) throw new Exception('Conflict check failed: ' . ($check->error ?: $conn->error));
    $res = $check->get_result();
    $hasConflict = false;
    while ($row = $res->fetch_assoc()) {
        $isExactTime = ($row['tour_time'] === $req['tour_time']);
        if ($req['tour_type'] === 'private') {
            // Private tours conflict with anything at exact same time
            // or anything within 30 min at a different property
            if ($isExactTime || (int)$row['property_id'] !== (int)$req['property_id']) {
                $hasConflict = true; break;
            }
        }
        if ($req['tour_type'] === 'public') {
            if ($isExactTime) {
                // Exact time: conflict with private, or public at different property
                if ($row['tour_type'] === 'private' || (int)$row['property_id'] !== (int)$req['property_id']) {
                    $hasConflict = true; break;
                }
                // Public + same property + exact time = grouping allowed, no conflict
            } else {
                // Within 30 min but not exact: conflict if different property
                if ((int)$row['property_id'] !== (int)$req['property_id']) {
                    $hasConflict = true; break;
                }
            }
        }
    }
    $check->close();
    if ($hasConflict) {
        throw new Exception('Cannot confirm: another confirmed tour conflicts with this time slot (exact match or within 30-minute buffer).');
    }

    $upd = $conn->prepare("UPDATE tour_requests SET request_status = 'Confirmed', confirmed_at = NOW(), decision_reason = NULL, decision_by = 'admin', decision_at = NULL WHERE tour_id = ?");
    if (!$upd) throw new Exception('Prepare failed: ' . $conn->error);
    $upd->bind_param('i', $tour_id);
    if (!$upd->execute()) throw new Exception('Update failed: ' . ($upd->error ?: $conn->error));
    if ($upd->affected_rows <= 0) throw new Exception('No rows updated.');
    $upd->close();

    $propAddress = $req['StreetAddress'] . ', ' . $req['City'];
    $subject = 'Your Property Tour Has Been Confirmed';
    $publicNote = '';
    if (!empty($req['tour_type']) && $req['tour_type'] === 'public') {
        $publicNote = '<div style="background-color:#0d1117;border-left:2px solid #2563eb;padding:16px 20px;margin:0 0 24px 0;">
                        <p style="margin:0;font-size:13px;color:#999999;line-height:1.6;">
                            <strong style="color:#2563eb;display:block;margin-bottom:6px;font-size:12px;text-transform:uppercase;letter-spacing:1px;">Public Tour Notice</strong>
                            You selected a <strong>Public (Group) Tour</strong>. Other interested clients may join this same timeslot.
                        </p>
                    </div>';
    }
    $formattedDate = date('F j, Y', strtotime($req['tour_date']));
    $formattedTime = date('g:i A', strtotime($req['tour_time']));
    
    $body = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tour Confirmed</title>
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
                            <h1 style="margin:0 0 12px 0;color:#22c55e;font-size:14px;font-weight:600;text-transform:uppercase;letter-spacing:3px;">Tour Confirmed</h1>
                            <p style="margin:0;color:#666666;font-size:15px;font-weight:400;">Your property tour has been approved</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:48px 48px 40px 48px;">
                            <p style="margin:0 0 24px 0;font-size:14px;color:#999999;line-height:1.7;">
                                Hello <span style="color:#d4af37;font-weight:500;">' . htmlspecialchars($req['user_name']) . '</span>,
                            </p>
                            <p style="margin:0 0 32px 0;font-size:15px;color:#cccccc;line-height:1.8;">
                                Great news! Your tour request has been confirmed. We look forward to showing you the property.
                            </p>
                            <div style="height:1px;background-color:#1f1f1f;margin:0 0 32px 0;"></div>
                            <div style="background-color:#0d1117;border-left:2px solid #d4af37;padding:20px 24px;margin:0 0 24px 0;">
                                <p style="margin:0 0 12px 0;font-size:13px;color:#d4af37;font-weight:600;text-transform:uppercase;letter-spacing:1px;">Tour Details</p>
                                <p style="margin:0 0 8px 0;font-size:14px;color:#999999;"><strong style="color:#cccccc;">Property:</strong> ' . htmlspecialchars($propAddress) . '</p>
                                <p style="margin:0 0 8px 0;font-size:14px;color:#999999;"><strong style="color:#cccccc;">Date:</strong> ' . $formattedDate . '</p>
                                <p style="margin:0;font-size:14px;color:#999999;"><strong style="color:#cccccc;">Time:</strong> ' . $formattedTime . '</p>
                            </div>
                            ' . $publicNote . '
                            <div style="background-color:#0d1117;border-left:2px solid #22c55e;padding:16px 20px;margin:0 0 24px 0;">
                                <p style="margin:0;font-size:13px;color:#999999;line-height:1.6;">
                                    <strong style="color:#22c55e;display:block;margin-bottom:6px;font-size:12px;text-transform:uppercase;letter-spacing:1px;">Important Reminders</strong>
                                    Please arrive on time. If you need to reschedule or cancel, please notify us as soon as possible. Bring a valid ID and any questions you may have about the property.
                                </p>
                            </div>
                            <p style="margin:0;font-size:13px;color:#666666;line-height:1.6;text-align:center;">
                                We look forward to meeting you and helping you find your perfect property.
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
    $res = sendSystemMail($req['user_email'], $req['user_name'], $subject, $body, 'Your tour request has been confirmed.');

    $conn->commit();
    echo json_encode(['success' => true, 'message' => !empty($res['success']) ? 'Tour confirmed and email sent.' : 'Tour confirmed. Email could not be sent.']);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Failed to confirm tour: ' . $e->getMessage()]);
}
?>
