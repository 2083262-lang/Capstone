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
    $formattedDate = date('F j, Y', strtotime($req['tour_date']));
    $formattedTime = date('g:i A', strtotime($req['tour_time']));

    require_once __DIR__ . '/email_template.php';

    $bodyContent  = emailGreeting($req['user_name']);
    $bodyContent .= emailParagraph('Great news! Your tour request has been confirmed. We look forward to showing you the property.');
    $bodyContent .= emailDivider();
    $bodyContent .= emailInfoCard('Tour Details', [
        'Property' => htmlspecialchars($propAddress),
        'Date'     => $formattedDate,
        'Time'     => $formattedTime,
    ]);

    if (!empty($req['tour_type']) && $req['tour_type'] === 'public') {
        $bodyContent .= emailNotice(
            'Public Tour Notice',
            'You selected a <strong>Public (Group) Tour</strong>. Other interested clients may join this same timeslot.',
            '#2563eb'
        );
    }

    $bodyContent .= emailNotice(
        'Important Reminders',
        'Please arrive on time. If you need to reschedule or cancel, please notify us as soon as possible. Bring a valid ID and any questions you may have about the property.',
        '#22c55e'
    );
    $bodyContent .= emailClosing('We look forward to meeting you and helping you find your perfect property.');

    $body = buildEmailTemplate([
        'accentColor' => '#22c55e',
        'heading'     => 'Tour Confirmed',
        'subtitle'    => 'Your property tour has been approved',
        'body'        => $bodyContent,
    ]);
    $res = sendSystemMail($req['user_email'], $req['user_name'], $subject, $body, 'Your tour request has been confirmed.');

    $conn->commit();
    echo json_encode(['success' => true, 'message' => !empty($res['success']) ? 'Tour confirmed and email sent.' : 'Tour confirmed. Email could not be sent.']);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Failed to confirm tour: ' . $e->getMessage()]);
}
?>
