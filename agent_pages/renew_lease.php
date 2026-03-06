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
$new_term  = isset($_POST['new_term_months']) ? (int) $_POST['new_term_months'] : 0;
$new_rent  = isset($_POST['new_monthly_rent']) ? (float) $_POST['new_monthly_rent'] : 0;

// Validate inputs
if ($rental_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid rental ID.']);
    exit();
}
if ($new_term < 1 || $new_term > 120) {
    echo json_encode(['success' => false, 'message' => 'Lease term must be between 1 and 120 months.']);
    exit();
}
if ($new_rent <= 0) {
    echo json_encode(['success' => false, 'message' => 'Monthly rent must be greater than zero.']);
    exit();
}

$conn->begin_transaction();

try {
    // Lock and verify ownership
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
        throw new Exception('Only active, renewed, or expired leases can be renewed. Current status: ' . $lease['lease_status']);
    }

    // Check for pending payments
    $pend = $conn->prepare("SELECT COUNT(*) AS cnt FROM rental_payments WHERE rental_id = ? AND status = 'Pending'");
    $pend->bind_param("i", $rental_id);
    $pend->execute();
    $pending_count = $pend->get_result()->fetch_assoc()['cnt'];
    if ($pending_count > 0) {
        throw new Exception("Cannot renew: there are $pending_count pending payment(s) awaiting admin review. Please wait for them to be processed first.");
    }

    // Calculate new dates: new start = old end + 1 day
    $new_start = date('Y-m-d', strtotime($lease['lease_end_date'] . ' +1 day'));
    $new_end   = date('Y-m-d', strtotime($new_start . " +$new_term months -1 day"));

    // Update the lease
    $upd = $conn->prepare("
        UPDATE finalized_rentals 
        SET lease_start_date = ?, 
            lease_end_date = ?, 
            lease_term_months = ?,
            monthly_rent = ?,
            lease_status = 'Renewed',
            renewed_at = NOW()
        WHERE rental_id = ?
    ");
    $upd->bind_param("ssidi", $new_start, $new_end, $new_term, $new_rent, $rental_id);
    $upd->execute();

    // Update rental_details with new rent if it exists
    $upd_rd = $conn->prepare("UPDATE rental_details SET MonthlyRent = ? WHERE property_ID = ?");
    $upd_rd->bind_param("di", $new_rent, $lease['property_id']);
    $upd_rd->execute();

    // Update property listing price
    $upd_prop = $conn->prepare("UPDATE property SET ListingPrice = ? WHERE property_ID = ?");
    $upd_prop->bind_param("di", $new_rent, $lease['property_id']);
    $upd_prop->execute();

    // Property log
    $log_msg = "Lease renewed. New term: $new_term months ($new_start to $new_end). Monthly rent: ₱" . number_format($new_rent, 2);
    $log = $conn->prepare("INSERT INTO property_log (property_id, account_id, action, reason_message) VALUES (?, ?, 'LEASE_RENEWED', ?)");
    $log->bind_param("iis", $lease['property_id'], $agent_id, $log_msg);
    $log->execute();

    // Price history
    $ph = $conn->prepare("INSERT INTO price_history (property_id, event_type, price, event_date) VALUES (?, 'Rented', ?, CURDATE())");
    $ph->bind_param("id", $lease['property_id'], $new_rent);
    $ph->execute();

    // Admin notification
    $admin_msg = "Lease #$rental_id has been renewed by the agent. New term: $new_term months. New rent: ₱" . number_format($new_rent, 2);
    $n = $conn->prepare("INSERT INTO notifications (item_id, item_type, title, message, category, priority, is_read, created_at) VALUES (?, 'property_rental', 'Lease Renewed', ?, 'update', 'normal', 0, NOW())");
    $n->bind_param("is", $lease['property_id'], $admin_msg);
    $n->execute();

    $conn->commit();

    // ── Send lease renewal email to tenant ──
    try {
        if (!empty($lease['tenant_email'])) {
            $tenantName = $lease['tenant_name'] ?: 'Tenant';
            $propAddr   = trim($lease['StreetAddress'] . ', ' . $lease['City']);
            $fmtRent    = '₱' . number_format($new_rent, 2);

            $bodyContent  = emailGreeting($tenantName);
            $bodyContent .= emailParagraph(
                'Great news! Your lease at <strong>' . htmlspecialchars($propAddr) . '</strong> has been <strong style="color:#22c55e;">renewed</strong>.'
            );
            $bodyContent .= emailInfoCard('New Lease Terms', [
                'Property'     => htmlspecialchars($propAddr),
                'Lease Start'  => date('M j, Y', strtotime($new_start)),
                'Lease End'    => date('M j, Y', strtotime($new_end)),
                'Term'         => $new_term . ' month' . ($new_term > 1 ? 's' : ''),
                'Monthly Rent' => '<span style="color:#d4af37;font-weight:700;">' . $fmtRent . '</span>',
            ], '#22c55e');
            $bodyContent .= emailNotice('Important', 'Please review the updated terms. If you have any questions, contact your property agent.', '#3b82f6');
            $bodyContent .= emailDivider();
            $bodyContent .= emailSignature();

            $html = buildEmailTemplate([
                'accentColor' => '#22c55e',
                'heading'     => 'Lease Renewed',
                'subtitle'    => 'Your lease has been renewed with updated terms',
                'body'        => $bodyContent,
                'footerExtra' => 'This is an automated notification from HomeEstate Realty.',
            ]);
            $alt = "Hello {$tenantName},\n\nYour lease at {$propAddr} has been renewed.\nNew period: $new_start to $new_end\nMonthly rent: {$fmtRent}\n\nBest regards,\nThe HomeEstate Realty Team";
            sendSystemMail($lease['tenant_email'], $tenantName, 'Lease Renewed – ' . htmlspecialchars($propAddr), $html, $alt);
        }
    } catch (Exception $mailEx) {
        error_log('[LEASE RENEWAL EMAIL] ' . $mailEx->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => "Lease renewed successfully. New period: $new_start to $new_end."
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
