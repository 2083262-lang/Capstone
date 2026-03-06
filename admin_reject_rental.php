<?php
session_start();
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/config/session_timeout.php';
require_once __DIR__ . '/mail_helper.php';
require_once __DIR__ . '/email_template.php';

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'Invalid request method']);
        exit;
    }

    $admin_id        = (int) $_SESSION['account_id'];
    $verification_id = isset($_POST['verification_id']) ? (int) $_POST['verification_id'] : 0;
    $admin_notes     = isset($_POST['admin_notes']) ? trim(strip_tags(substr($_POST['admin_notes'], 0, 2000))) : '';

    if ($verification_id <= 0) {
        echo json_encode(['ok' => false, 'message' => 'Invalid verification ID.']);
        exit;
    }
    if (empty($admin_notes)) {
        echo json_encode(['ok' => false, 'message' => 'Rejection reason is required.']);
        exit;
    }

    $conn->begin_transaction();

    // Fetch verification with lock
    $stmt = $conn->prepare("
        SELECT rv.*, p.StreetAddress, p.City 
        FROM rental_verifications rv
        JOIN property p ON rv.property_id = p.property_ID
        WHERE rv.verification_id = ? FOR UPDATE
    ");
    $stmt->bind_param("i", $verification_id);
    $stmt->execute();
    $rv = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$rv) {
        $conn->rollback();
        echo json_encode(['ok' => false, 'message' => 'Verification not found.']);
        exit;
    }
    if ($rv['status'] !== 'Pending') {
        $conn->rollback();
        echo json_encode(['ok' => false, 'message' => 'This verification has already been ' . strtolower($rv['status']) . '.']);
        exit;
    }

    $property_id = (int)$rv['property_id'];
    $agent_id    = (int)$rv['agent_id'];

    // 1. Update verification
    $upd = $conn->prepare("UPDATE rental_verifications SET status = 'Rejected', admin_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE verification_id = ?");
    $upd->bind_param("sii", $admin_notes, $admin_id, $verification_id);
    if (!$upd->execute()) throw new Exception('Failed to update verification.');
    $upd->close();

    // 2. Return property to For Rent
    $prop = $conn->prepare("UPDATE property SET Status = 'For Rent' WHERE property_ID = ?");
    $prop->bind_param("i", $property_id);
    if (!$prop->execute()) throw new Exception('Failed to update property status.');
    $prop->close();

    // 3. Property log
    $log_msg = "Rental verification rejected. Reason: $admin_notes";
    $log = $conn->prepare("INSERT INTO property_log (property_id, account_id, action, reason_message) VALUES (?, ?, 'UPDATED', ?)");
    $log->bind_param("iis", $property_id, $admin_id, $log_msg);
    $log->execute();
    $log->close();

    // 4. Agent notification
    $notif_msg = "Your rental verification for {$rv['StreetAddress']}, {$rv['City']} has been rejected. Reason: $admin_notes";
    $an = $conn->prepare("INSERT INTO agent_notifications (agent_account_id, notif_type, reference_id, title, message, is_read, created_at) VALUES (?, 'rental_rejected', ?, 'Rental Rejected', ?, 0, NOW())");
    $an->bind_param("iis", $agent_id, $property_id, $notif_msg);
    $an->execute();
    $an->close();

    $conn->commit();

    // ── Send rejection email to agent ──
    try {
        $agStmt = $conn->prepare("SELECT first_name, last_name, email FROM accounts WHERE account_id = ?");
        $agStmt->bind_param("i", $agent_id);
        $agStmt->execute();
        $agRow = $agStmt->get_result()->fetch_assoc();
        $agStmt->close();

        if ($agRow && !empty($agRow['email'])) {
            $agentName  = trim($agRow['first_name'] . ' ' . $agRow['last_name']);
            $agentEmail = $agRow['email'];
            $propAddr   = trim($rv['StreetAddress'] . ', ' . $rv['City']);

            $bodyContent  = emailGreeting($agentName);
            $bodyContent .= emailParagraph(
                'Your rental verification for <strong>' . htmlspecialchars($propAddr) . '</strong> has been '
                . '<strong style="color:#dc2626;">rejected</strong> by the admin.'
            );
            $bodyContent .= emailInfoCard('Rejection Details', [
                'Property' => htmlspecialchars($propAddr),
                'Tenant'   => htmlspecialchars($rv['tenant_name']),
                'Reason'   => htmlspecialchars($admin_notes),
            ], '#dc2626');
            $bodyContent .= emailDivider();
            $bodyContent .= emailNotice(
                'What You Can Do',
                'The property has been returned to <strong>For Rent</strong> status. '
                . 'You may review the rejection reason, make corrections, and submit a new rental verification.',
                '#2563eb'
            );
            $bodyContent .= emailSignature();

            $html = buildEmailTemplate([
                'accentColor' => '#dc2626',
                'heading'     => 'Rental Verification Rejected',
                'subtitle'    => 'Your rental verification needs attention',
                'body'        => $bodyContent,
                'footerExtra' => 'This is an automated notification from HomeEstate Realty.',
            ]);

            $alt = "Hello {$agentName},\n\nYour rental verification for {$propAddr} has been rejected.\nReason: {$admin_notes}\n\n"
                 . "The property has been returned to For Rent status.\n\nBest regards,\nThe HomeEstate Realty Team";

            sendSystemMail($agentEmail, $agentName, 'Rental Rejected – ' . $propAddr, $html, $alt);
        }
    } catch (Exception $mailEx) {
        error_log('[RENTAL REJECTION EMAIL] ' . $mailEx->getMessage());
    }

    echo json_encode(['ok' => true, 'message' => 'Rental verification rejected. Property returned to For Rent status.']);

} catch (Exception $e) {
    if (isset($conn)) $conn->rollback();
    error_log("Rental rejection error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'An error occurred while processing the rejection.']);
}
?>
