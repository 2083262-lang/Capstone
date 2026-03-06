<?php
session_start();
include 'connection.php';
require_once __DIR__ . '/config/session_timeout.php';
require_once __DIR__ . '/mail_helper.php';
require_once __DIR__ . '/email_template.php';

header('Content-Type: application/json');

if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
    echo json_encode(['ok' => false, 'message' => 'Unauthorized.']);
    exit();
}

$payment_id = isset($_POST['payment_id']) ? (int)$_POST['payment_id'] : 0;
$admin_notes = trim($_POST['admin_notes'] ?? '');

if ($payment_id <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Invalid payment ID.']);
    exit();
}

if (empty($admin_notes)) {
    echo json_encode(['ok' => false, 'message' => 'Rejection reason is required.']);
    exit();
}

$conn->begin_transaction();

try {
    // Lock the payment row
    $stmt = $conn->prepare("
        SELECT rp.*, fr.property_id, fr.tenant_name, fr.tenant_email,
               p.StreetAddress, p.City,
               a.first_name AS agent_first, a.last_name AS agent_last, a.email AS agent_email
        FROM rental_payments rp
        JOIN finalized_rentals fr ON rp.rental_id = fr.rental_id
        JOIN property p ON fr.property_id = p.property_ID
        JOIN accounts a ON rp.agent_id = a.account_id
        WHERE rp.payment_id = ? FOR UPDATE
    ");
    $stmt->bind_param("i", $payment_id);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();

    if (!$payment) {
        throw new Exception('Payment not found.');
    }
    if ($payment['status'] !== 'Pending') {
        throw new Exception('Payment is already ' . strtolower($payment['status']) . '.');
    }

    // Update payment status
    $admin_id = (int) $_SESSION['account_id'];
    $stmt2 = $conn->prepare("UPDATE rental_payments SET status = 'Rejected', admin_notes = ?, confirmed_by = ?, confirmed_at = NOW() WHERE payment_id = ?");
    $stmt2->bind_param("sii", $admin_notes, $admin_id, $payment_id);
    $stmt2->execute();

    // Notify the agent
    $notif_msg = "Your rental payment #$payment_id was rejected. Reason: " . mb_substr($admin_notes, 0, 200);
    $stmt3 = $conn->prepare("INSERT INTO agent_notifications (agent_account_id, notif_type, reference_id, title, message, is_read, created_at) VALUES (?, 'rental_payment_rejected', ?, 'Payment Rejected', ?, 0, NOW())");
    $stmt3->bind_param("iis", $payment['agent_id'], $payment['property_id'], $notif_msg);
    $stmt3->execute();

    $conn->commit();

    // ── Send rejection email to agent ──
    try {
        if (!empty($payment['agent_email'])) {
            $agentName = trim($payment['agent_first'] . ' ' . $payment['agent_last']);
            $propAddr  = trim($payment['StreetAddress'] . ', ' . $payment['City']);
            $fmtAmount = '₱' . number_format($payment['payment_amount'], 2);
            $periodFmt = date('M j, Y', strtotime($payment['period_start'])) . ' – ' . date('M j, Y', strtotime($payment['period_end']));

            $bodyContent  = emailGreeting($agentName);
            $bodyContent .= emailParagraph(
                'A rental payment you submitted has been <strong style="color:#ef4444;">rejected</strong> by the admin. '
                . 'Please review the details below.'
            );
            $bodyContent .= emailInfoCard('Payment Details', [
                'Property'    => htmlspecialchars($propAddr),
                'Tenant'      => htmlspecialchars($payment['tenant_name']),
                'Amount'      => $fmtAmount,
                'Period'      => $periodFmt,
            ]);
            $bodyContent .= emailNotice(
                'Rejection Reason',
                htmlspecialchars($admin_notes),
                '#ef4444'
            );
            $bodyContent .= emailNotice('What You Can Do', '
                <ul style="margin:0;padding-left:18px;">
                    <li>Review the rejection reason above</li>
                    <li>Correct the payment or supporting documents</li>
                    <li>Re-submit the payment from the Rental Payments page</li>
                </ul>
            ', '#6366f1');
            $bodyContent .= emailDivider();
            $bodyContent .= emailSignature();

            $html = buildEmailTemplate([
                'accentColor' => '#ef4444',
                'heading'     => 'Payment Rejected',
                'subtitle'    => 'Rental payment requires correction',
                'body'        => $bodyContent,
                'footerExtra' => 'This is an automated notification from HomeEstate Realty.',
            ]);
            $alt = "Hello {$agentName},\n\nYour rental payment of {$fmtAmount} for {$propAddr} was rejected.\nReason: {$admin_notes}\n\nPlease correct and re-submit.\n\nBest regards,\nThe HomeEstate Realty Team";
            sendSystemMail($payment['agent_email'], $agentName, 'Payment Rejected – ' . htmlspecialchars($propAddr), $html, $alt);
        }
    } catch (Exception $mailEx) {
        error_log('[RENTAL PAYMENT REJECT EMAIL] ' . $mailEx->getMessage());
    }

    echo json_encode(['ok' => true, 'message' => 'Payment rejected. The agent has been notified.']);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
