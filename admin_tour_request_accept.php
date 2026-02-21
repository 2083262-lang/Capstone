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

    // Prevent conflicts with public/private grouping rules
    $check = $conn->prepare("SELECT tour_id, tour_type, property_id FROM tour_requests WHERE agent_account_id = ? AND tour_date = ? AND tour_time = ? AND tour_id <> ? AND request_status = 'Confirmed'");
    if (!$check) throw new Exception('Prepare failed: ' . $conn->error);
    $check->bind_param('issi', $req['agent_account_id'], $req['tour_date'], $req['tour_time'], $tour_id);
    if (!$check->execute()) throw new Exception('Conflict check failed: ' . ($check->error ?: $conn->error));
    $res = $check->get_result();
    $hasConflict = false;
    while ($row = $res->fetch_assoc()) {
        if ($req['tour_type'] === 'private') { $hasConflict = true; break; }
        if ($req['tour_type'] === 'public') {
            if ($row['tour_type'] === 'private' || (int)$row['property_id'] !== (int)$req['property_id']) { $hasConflict = true; break; }
        }
    }
    $check->close();
    if ($hasConflict) {
        throw new Exception('Cannot confirm: another tour at the same date and time is already confirmed.');
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
                $publicNote = "<div style=\"margin:14px 0;padding:12px 14px;border-radius:8px;border:1px solid #0dcaf05c;background:#e8f7fb;color:#0b7285;font-weight:600\">
                                                    <i style=\"margin-right:6px\">&#9888;</i> You selected a <strong>Public (Group) Tour</strong>. Other interested clients may join this same timeslot.
                                                </div>";
        }
        $body    = "<p>Hello " . htmlspecialchars($req['user_name']) . ",</p>
                                    <p>Your tour request for <strong>" . htmlspecialchars($propAddress) . "</strong> on <strong>" . date('F j, Y', strtotime($req['tour_date'])) . " at " . date('g:i A', strtotime($req['tour_time'])) . "</strong> has been <strong>CONFIRMED</strong>.</p>
                                    $publicNote
                                    <p>We look forward to meeting you.</p>
                                    <p>Best regards,<br>Prestige Properties</p>";
    $res = sendSystemMail($req['user_email'], $req['user_name'], $subject, $body, 'Your tour request has been confirmed.');

    $conn->commit();
    echo json_encode(['success' => true, 'message' => !empty($res['success']) ? 'Tour confirmed and email sent.' : 'Tour confirmed. Email could not be sent.']);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Failed to confirm tour: ' . $e->getMessage()]);
}
?>
