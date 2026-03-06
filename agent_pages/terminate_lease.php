<?php
session_start();
include __DIR__ . '/../connection.php';
require_once __DIR__ . '/../mail_helper.php';
require_once __DIR__ . '/../email_template.php';

header('Content-Type: application/json');

if (!isset($_SESSION['account_id']) || !in_array($_SESSION['user_role'], ['agent', 'admin'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit();
}

$agent_id  = (int) $_SESSION['account_id'];
$rental_id = isset($_POST['rental_id']) ? (int) $_POST['rental_id'] : 0;
$reason    = trim($_POST['termination_reason'] ?? '');

if ($rental_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid rental ID.']);
    exit();
}

$conn->begin_transaction();

try {
    // Lock and verify
    $stmt = $conn->prepare("
        SELECT fr.*, p.StreetAddress, p.City
        FROM finalized_rentals fr
        JOIN property p ON fr.property_id = p.property_ID
        WHERE fr.rental_id = ?
        FOR UPDATE
    ");
    $stmt->bind_param("i", $rental_id);
    $stmt->execute();
    $lease = $stmt->get_result()->fetch_assoc();

    if (!$lease) {
        throw new Exception('Lease not found.');
    }
    if ((int)$lease['agent_id'] !== $agent_id) {
        throw new Exception('You do not own this property.');
    }
    if (!in_array($lease['lease_status'], ['Active', 'Renewed', 'Expired'])) {
        throw new Exception('This lease cannot be terminated. Current status: ' . $lease['lease_status']);
    }

    // Check for pending payments
    $pend = $conn->prepare("SELECT COUNT(*) AS cnt FROM rental_payments WHERE rental_id = ? AND status = 'Pending'");
    $pend->bind_param("i", $rental_id);
    $pend->execute();
    $pending_count = $pend->get_result()->fetch_assoc()['cnt'];
    if ($pending_count > 0) {
        throw new Exception("Cannot terminate: there are $pending_count pending payment(s) awaiting admin review. Please wait for them to be processed first.");
    }

    // Update lease
    $upd = $conn->prepare("UPDATE finalized_rentals SET lease_status = 'Terminated', terminated_at = NOW(), terminated_by = ? WHERE rental_id = ?");
    $upd->bind_param("ii", $agent_id, $rental_id);
    $upd->execute();

    // Unlock property — make it available again
    $upd_prop = $conn->prepare("UPDATE property SET Status = 'For Rent', is_locked = 0 WHERE property_ID = ?");
    $upd_prop->bind_param("i", $lease['property_id']);
    $upd_prop->execute();

    // Property log
    $reason_text = $reason ? " Reason: $reason" : '';
    $log_msg = "Lease #$rental_id terminated by agent.$reason_text";
    $log = $conn->prepare("INSERT INTO property_log (property_id, account_id, action, reason_message) VALUES (?, ?, 'LEASE_TERMINATED', ?)");
    $log->bind_param("iis", $lease['property_id'], $agent_id, $log_msg);
    $log->execute();

    // Price history
    $ph = $conn->prepare("INSERT INTO price_history (property_id, event_type, price, event_date) VALUES (?, 'Lease Ended', ?, CURDATE())");
    $old_rent = $lease['monthly_rent'];
    $ph->bind_param("id", $lease['property_id'], $old_rent);
    $ph->execute();

    // Admin notification
    $admin_msg = "Lease #$rental_id for " . $lease['StreetAddress'] . ", " . $lease['City'] . " (Tenant: " . $lease['tenant_name'] . ") has been terminated by the agent.$reason_text";
    $n = $conn->prepare("INSERT INTO notifications (item_id, item_type, title, message, category, priority, is_read, created_at) VALUES (?, 'property_rental', 'Lease Terminated', ?, 'alert', 'high', 0, NOW())");
    $n->bind_param("is", $lease['property_id'], $admin_msg);
    $n->execute();

    $conn->commit();

    // ── Send termination email to tenant ──
    try {
        if (!empty($lease['tenant_email'])) {
            $tenantName = $lease['tenant_name'] ?: 'Tenant';
            $propAddr   = trim($lease['StreetAddress'] . ', ' . $lease['City']);

            $bodyContent  = emailGreeting($tenantName);
            $bodyContent .= emailParagraph(
                'We are writing to inform you that your lease at <strong>' . htmlspecialchars($propAddr) . '</strong> has been <strong style="color:#ef4444;">terminated</strong>.'
            );
            $bodyContent .= emailInfoCard('Lease Details', [
                'Property'       => htmlspecialchars($propAddr),
                'Lease Period'   => date('M j, Y', strtotime($lease['lease_start_date'])) . ' – ' . date('M j, Y', strtotime($lease['lease_end_date'])),
                'Monthly Rent'   => '₱' . number_format($lease['monthly_rent'], 2),
                'Status'         => '<span style="color:#ef4444;font-weight:700;">Terminated</span>',
                'Effective Date' => date('M j, Y'),
            ]);
            if (!empty($reason)) {
                $bodyContent .= emailNotice('Reason for Termination', htmlspecialchars($reason), '#ef4444');
            }
            $bodyContent .= emailNotice('Important', 'If you have questions regarding this termination, please contact your property agent directly.', '#f59e0b');
            $bodyContent .= emailDivider();
            $bodyContent .= emailSignature();

            $html = buildEmailTemplate([
                'accentColor' => '#ef4444',
                'heading'     => 'Lease Terminated',
                'subtitle'    => 'Your lease has been terminated',
                'body'        => $bodyContent,
                'footerExtra' => 'This is an automated notification from HomeEstate Realty.',
            ]);
            $alt = "Hello {$tenantName},\n\nYour lease at {$propAddr} has been terminated effective " . date('M j, Y') . "." . ($reason ? "\nReason: {$reason}" : '') . "\n\nPlease contact your property agent if you have questions.\n\nBest regards,\nThe HomeEstate Realty Team";
            sendSystemMail($lease['tenant_email'], $tenantName, 'Lease Terminated – ' . htmlspecialchars($propAddr), $html, $alt);
        }
    } catch (Exception $mailEx) {
        error_log('[LEASE TERMINATION EMAIL] ' . $mailEx->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => 'Lease terminated successfully. Property is now available for rent again.'
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
