<?php
session_start();
include '../connection.php';

require_once __DIR__ . '/../mail_helper.php';

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

    // Check for confirmed tours at the same date/time and apply public/private grouping rules
    $check = $conn->prepare("SELECT tour_id, tour_type, property_id FROM tour_requests WHERE agent_account_id = ? AND tour_date = ? AND tour_time = ? AND tour_id <> ? AND request_status = 'Confirmed'");
    if (!$check) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    $check->bind_param('issi', $agent_account_id, $req['tour_date'], $req['tour_time'], $tour_id);
    if (!$check->execute()) {
        $err = $check->error ?: $conn->error;
        $check->close();
        throw new Exception('Execute failed: ' . $err);
    }
    $res = $check->get_result();
    $hasConflict = false;
    while ($row = $res->fetch_assoc()) {
        if ($req['tour_type'] === 'private') {
            $hasConflict = true; // private cannot share slot
            break;
        }
        if ($req['tour_type'] === 'public') {
            if ($row['tour_type'] === 'private' || (int)$row['property_id'] !== (int)$req['property_id']) {
                $hasConflict = true;
                break;
            }
        }
    }
    $check->close();
    if ($hasConflict) {
        throw new Exception('Cannot confirm: another tour at the same date and time is already confirmed.');
    }

    // Update status and stamp confirmed_at
    $upd = $conn->prepare("UPDATE tour_requests SET request_status = 'Confirmed', confirmed_at = NOW(), decision_reason = NULL, decision_by = NULL, decision_at = NULL WHERE tour_id = ? AND agent_account_id = ?");
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
                $publicNote = "<div style=\"margin:14px 0;padding:12px 14px;border-radius:8px;border:1px solid #0dcaf05c;background:#e8f7fb;color:#0b7285;font-weight:600\">
                                                    <i style=\"margin-right:6px\">&#9888;</i> You selected a <strong>Public (Group) Tour</strong>. Other interested clients may join this same timeslot.
                                                </div>";
        }
        $body    = "<p>Hello " . htmlspecialchars($req['user_name']) . ",</p>
                                    <p>Your tour request for <strong>" . htmlspecialchars($propAddress) . "</strong> on <strong>" . date('F j, Y', strtotime($req['tour_date'])) . " at " . date('g:i A', strtotime($req['tour_time'])) . "</strong> has been <strong>CONFIRMED</strong>.</p>
                                    $publicNote
                                    <p>We look forward to meeting you. If you need to change the schedule, please reply to this email.</p>
                                    <p>Best regards,<br>Prestige Properties</p>";
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
