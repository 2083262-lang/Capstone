<?php
session_start();
include '../connection.php';

require_once __DIR__ . '/../mail_helper.php';
require_once __DIR__ . '/../email_template.php';

header('Content-Type: application/json');

if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'agent') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$agent_account_id = (int)$_SESSION['account_id'];
$tour_id = isset($_POST['tour_id']) ? (int)$_POST['tour_id'] : 0;
if ($tour_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

// Fetch tour ensuring it belongs to this agent
$sql = "SELECT tr.*, p.StreetAddress, p.City FROM tour_requests tr JOIN property p ON p.property_ID = tr.property_id WHERE tr.tour_id = ? AND tr.agent_account_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $tour_id, $agent_account_id);
$stmt->execute();
$req = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$req) {
    echo json_encode(['success' => false, 'message' => 'Tour not found.']);
    exit;
}

// If already confirmed but timestamp present, nothing to do
if ($req['request_status'] === 'Confirmed' && !empty($req['confirmed_at'])) {
    echo json_encode(['success' => true, 'message' => 'This tour is already confirmed.']);
    exit;
}

// Only Pending tours can be confirmed
if ($req['request_status'] !== 'Pending') {
    echo json_encode(['success' => false, 'message' => 'Only pending tour requests can be confirmed. Current status: ' . $req['request_status']]);
    exit;
}

// Use transaction and validate DB errors so we don't falsely report success
// Concurrency-safe confirm with conflict guard
$conn->begin_transaction();
try {
    // Lock the target request row
    $lock = $conn->prepare("SELECT tour_id FROM tour_requests WHERE tour_id = ? AND agent_account_id = ? FOR UPDATE");
    if (!$lock) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    $lock->bind_param('ii', $tour_id, $agent_account_id);
    if (!$lock->execute()) {
        $err = $lock->error ?: $conn->error;
        $lock->close();
        throw new Exception('Execute failed: ' . $err);
    }
    $locked = $lock->get_result()->fetch_assoc();
    $lock->close();
    if (!$locked) {
        throw new Exception('Tour not found or unauthorized.');
    }

    // Re-check status under lock to prevent race conditions
    $statusCheck = $conn->prepare("SELECT request_status FROM tour_requests WHERE tour_id = ?");
    $statusCheck->bind_param('i', $tour_id);
    $statusCheck->execute();
    $currentStatus = $statusCheck->get_result()->fetch_assoc();
    $statusCheck->close();
    if ($currentStatus['request_status'] !== 'Pending') {
        throw new Exception('Tour is no longer pending. Current status: ' . $currentStatus['request_status']);
    }

    // Check for confirmed tours at the same date/time (with 30-min buffer) and apply public/private grouping rules
    $check = $conn->prepare("
        SELECT tour_id, tour_type, property_id, tour_time
        FROM tour_requests
        WHERE agent_account_id = ?
          AND tour_date = ?
          AND tour_id <> ?
          AND request_status = 'Confirmed'
          AND ABS(TIMESTAMPDIFF(MINUTE, tour_time, ?)) < 30
    ");
    if (!$check) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    $check->bind_param('isis', $agent_account_id, $req['tour_date'], $tour_id, $req['tour_time']);
    if (!$check->execute()) {
        $err = $check->error ?: $conn->error;
        $check->close();
        throw new Exception('Execute failed: ' . $err);
    }
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
                if ($row['tour_type'] === 'private' || (int)$row['property_id'] !== (int)$req['property_id']) {
                $hasConflict = true; break;
                }
                // Public + same property + exact time = grouping allowed
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

    // Update status and stamp confirmed_at
    $upd = $conn->prepare("UPDATE tour_requests SET request_status = 'Confirmed', confirmed_at = NOW(), decision_reason = NULL, decision_by = 'agent', decision_at = NOW() WHERE tour_id = ? AND agent_account_id = ?");
    if (!$upd) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    $upd->bind_param('ii', $tour_id, $agent_account_id);
    if (!$upd->execute()) {
        $err = $upd->error ?: $conn->error;
        $upd->close();
        throw new Exception('Execute failed: ' . $err);
    }
    if ($upd->affected_rows <= 0) {
        $upd->close();
        throw new Exception('No rows updated. It may have been confirmed already or the record was not found.');
    }
    $upd->close();

    // Send email to user (best-effort)
    $propAddress = $req['StreetAddress'] . ', ' . $req['City'];
    $subject = 'Your Property Tour Has Been Confirmed';
    $publicNote = '';
    if (!empty($req['tour_type']) && $req['tour_type'] === 'public') {
        $publicNote = emailNotice('Public Tour Notice', 'You selected a <strong>Public (Group) Tour</strong>. Other interested clients may join this same timeslot.', '#2563eb');
    }
    $formattedDate = date('F j, Y', strtotime($req['tour_date']));
    $formattedTime = date('g:i A', strtotime($req['tour_time']));
    
    $bodyContent  = emailGreeting($req['user_name']);
    $bodyContent .= emailParagraph('Great news! Your tour request has been confirmed by the property agent. We look forward to showing you the property.');
    $bodyContent .= emailDivider();
    $bodyContent .= emailInfoCard('Tour Details', [
        'Property' => htmlspecialchars($propAddress),
        'Date'     => $formattedDate,
        'Time'     => $formattedTime,
    ]);
    $bodyContent .= $publicNote;
    $bodyContent .= emailNotice('Important Reminders', 'Please arrive on time. If you need to reschedule or cancel, please contact us as soon as possible. Bring a valid ID and any questions you may have about the property.', '#22c55e');
    $bodyContent .= emailClosing('We look forward to meeting you and helping you find your perfect property.');

    $body = buildEmailTemplate([
        'accentColor' => '#22c55e',
        'heading'     => 'Tour Confirmed',
        'subtitle'    => 'Your property tour has been approved',
        'body'        => $bodyContent,
    ]);
    $res = sendSystemMail($req['user_email'], $req['user_name'], $subject, $body, 'Your tour request has been confirmed.');

    $conn->commit();
    echo json_encode([
        'success' => true,
        'message' => !empty($res['success']) ? 'Tour confirmed and email sent to the client.' : 'Tour confirmed. Email notification could not be sent.'
    ]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Failed to confirm tour: ' . $e->getMessage()]);
}
