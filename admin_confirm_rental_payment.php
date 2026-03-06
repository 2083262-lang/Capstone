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

$conn->begin_transaction();

try {
    // Lock the payment row
    $stmt = $conn->prepare("SELECT rp.*, fr.commission_rate, fr.property_id, fr.tenant_name, fr.tenant_email, p.StreetAddress, p.City, a.first_name AS agent_first, a.last_name AS agent_last, a.email AS agent_email FROM rental_payments rp JOIN finalized_rentals fr ON rp.rental_id = fr.rental_id JOIN property p ON fr.property_id = p.property_ID JOIN accounts a ON rp.agent_id = a.account_id WHERE rp.payment_id = ? FOR UPDATE");
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
    $stmt2 = $conn->prepare("UPDATE rental_payments SET status = 'Confirmed', admin_notes = ?, confirmed_by = ?, confirmed_at = NOW() WHERE payment_id = ?");
    $stmt2->bind_param("sii", $admin_notes, $admin_id, $payment_id);
    $stmt2->execute();

    // Calculate commission
    $commission_rate = (float) $payment['commission_rate'];
    $commission_amount = round($payment['payment_amount'] * $commission_rate / 100, 2);

    // Create commission record
    $stmt3 = $conn->prepare("INSERT INTO rental_commissions (rental_id, payment_id, agent_id, commission_percentage, commission_amount, status, calculated_at, created_at) VALUES (?, ?, ?, ?, ?, 'calculated', NOW(), NOW())");
    $stmt3->bind_param("iiidd", $payment['rental_id'], $payment_id, $payment['agent_id'], $commission_rate, $commission_amount);
    $stmt3->execute();

    // Notify the agent
    $notif_msg = "Your rental payment #$payment_id has been confirmed. Commission: ₱" . number_format($commission_amount, 2);
    $stmt4 = $conn->prepare("INSERT INTO agent_notifications (agent_account_id, notif_type, reference_id, title, message, is_read, created_at) VALUES (?, 'rental_payment_confirmed', ?, 'Payment Confirmed', ?, 0, NOW())");
    $stmt4->bind_param("iis", $payment['agent_id'], $payment['property_id'], $notif_msg);
    $stmt4->execute();

    $conn->commit();

    // ── Send email to agent ──
    try {
        if (!empty($payment['agent_email'])) {
            $agentName = trim($payment['agent_first'] . ' ' . $payment['agent_last']);
            $propAddr  = trim($payment['StreetAddress'] . ', ' . $payment['City']);
            $fmtAmount = '₱' . number_format($payment['payment_amount'], 2);
            $fmtComm   = '₱' . number_format($commission_amount, 2);
            $periodFmt = date('M j, Y', strtotime($payment['period_start'])) . ' – ' . date('M j, Y', strtotime($payment['period_end']));

            $bodyContent  = emailGreeting($agentName);
            $bodyContent .= emailParagraph(
                'A rental payment you submitted has been <strong style="color:#22c55e;">confirmed</strong> by the admin. '
                . 'Your commission has been calculated.'
            );
            $bodyContent .= emailInfoCard('Payment Details', [
                'Property'    => htmlspecialchars($propAddr),
                'Tenant'      => htmlspecialchars($payment['tenant_name']),
                'Amount Paid' => '<span style="color:#d4af37;font-weight:700;">' . $fmtAmount . '</span>',
                'Period'      => $periodFmt,
            ]);
            $bodyContent .= emailInfoCard('Commission Earned', [
                'Rate'       => number_format($commission_rate, 2) . '%',
                'Commission' => '<span style="color:#22c55e;font-weight:700;">' . $fmtComm . '</span>',
                'Status'     => 'Calculated',
            ], '#22c55e');
            $bodyContent .= emailDivider();
            $bodyContent .= emailSignature();

            $html = buildEmailTemplate([
                'accentColor' => '#22c55e',
                'heading'     => 'Payment Confirmed',
                'subtitle'    => 'Rental payment confirmed and commission earned',
                'body'        => $bodyContent,
                'footerExtra' => 'This is an automated notification from HomeEstate Realty.',
            ]);
            $alt = "Hello {$agentName},\n\nYour rental payment of {$fmtAmount} for {$propAddr} has been confirmed.\nCommission earned: {$fmtComm}\n\nBest regards,\nThe HomeEstate Realty Team";
            sendSystemMail($payment['agent_email'], $agentName, 'Payment Confirmed – Commission ' . $fmtComm, $html, $alt);
        }
    } catch (Exception $mailEx) {
        error_log('[RENTAL PAYMENT CONFIRM EMAIL] ' . $mailEx->getMessage());
    }

    echo json_encode(['ok' => true, 'message' => "Payment confirmed. Commission of ₱" . number_format($commission_amount, 2) . " created."]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
