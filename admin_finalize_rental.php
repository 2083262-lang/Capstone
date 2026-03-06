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
    $commission_rate = isset($_POST['commission_rate']) ? trim($_POST['commission_rate']) : '';
    $admin_notes     = isset($_POST['admin_notes']) ? trim(strip_tags(substr($_POST['admin_notes'] ?? '', 0, 2000))) : '';

    // Validate
    $errors = [];
    if ($verification_id <= 0) $errors[] = 'Invalid verification ID.';
    $commission_rate = is_numeric($commission_rate) ? (float)$commission_rate : null;
    if ($commission_rate === null || $commission_rate < 0.01 || $commission_rate > 100) {
        $errors[] = 'Commission rate must be between 0.01% and 100%.';
    }

    if (!empty($errors)) {
        echo json_encode(['ok' => false, 'message' => implode(' ', $errors)]);
        exit;
    }

    // Fetch the verification
    // Start transaction first so FOR UPDATE lock works correctly
    $conn->begin_transaction();

    $stmt = $conn->prepare("
        SELECT rv.*, p.StreetAddress, p.City, p.Status AS property_status
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
        echo json_encode(['ok' => false, 'message' => 'Verification record not found.']);
        exit;
    }

    if ($rv['status'] !== 'Pending') {
        $conn->rollback();
        echo json_encode(['ok' => false, 'message' => 'This verification has already been ' . strtolower($rv['status']) . '.']);
        exit;
    }

    // Calculate lease end date
    $lease_start = $rv['lease_start_date'];
    $lease_end = date('Y-m-d', strtotime($lease_start . " + {$rv['lease_term_months']} months - 1 day"));

    // 1. Update rental_verifications
    $upd = $conn->prepare("
        UPDATE rental_verifications 
        SET status = 'Approved', admin_notes = ?, reviewed_by = ?, reviewed_at = NOW()
        WHERE verification_id = ?
    ");
    $upd->bind_param("sii", $admin_notes, $admin_id, $verification_id);
    if (!$upd->execute()) throw new Exception('Failed to update verification.');
    $upd->close();

    // 2. Insert finalized_rentals
    $p_property_id = (int)$rv['property_id'];
    $p_agent_id = (int)$rv['agent_id'];
    $p_tenant_name = $rv['tenant_name'];
    $p_tenant_email = $rv['tenant_email'];
    $p_tenant_phone = $rv['tenant_phone'];
    $p_monthly_rent = (float)$rv['monthly_rent'];
    $p_security_deposit = (float)$rv['security_deposit'];
    $p_additional_notes = $rv['additional_notes'];
    $lease_term = (int)$rv['lease_term_months'];
    
    $fr_stmt = $conn->prepare("
        INSERT INTO finalized_rentals 
        (verification_id, property_id, agent_id, tenant_name, tenant_email, tenant_phone,
         monthly_rent, security_deposit, lease_start_date, lease_end_date, lease_term_months,
         commission_rate, additional_notes, lease_status, finalized_by, finalized_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, NOW())
    ");
    $fr_stmt->bind_param(
        "iiisssddssidsi",
        $verification_id, $p_property_id, $p_agent_id,
        $p_tenant_name, $p_tenant_email, $p_tenant_phone,
        $p_monthly_rent, $p_security_deposit,
        $lease_start, $lease_end, $lease_term,
        $commission_rate, $p_additional_notes, $admin_id
    );
    if (!$fr_stmt->execute()) throw new Exception('Failed to create finalized rental: ' . $fr_stmt->error);
    $rental_id = $conn->insert_id;
    $fr_stmt->close();

    // 3. Update property status
    $prop_upd = $conn->prepare("UPDATE property SET Status = 'Rented', is_locked = 1 WHERE property_ID = ?");
    $prop_upd->bind_param("i", $p_property_id);
    if (!$prop_upd->execute()) throw new Exception('Failed to update property status.');
    $prop_upd->close();

    // 4. Property log
    $log_msg = "Rental approved by admin. Commission rate: {$commission_rate}%";
    $log_stmt = $conn->prepare("INSERT INTO property_log (property_id, account_id, action, reason_message) VALUES (?, ?, 'RENTED', ?)");
    $log_stmt->bind_param("iis", $p_property_id, $admin_id, $log_msg);
    $log_stmt->execute();
    $log_stmt->close();

    // 5. Price history
    $ph_stmt = $conn->prepare("INSERT INTO price_history (property_id, event_type, price, event_date) VALUES (?, 'Rented', ?, NOW())");
    $ph_stmt->bind_param("id", $p_property_id, $p_monthly_rent);
    $ph_stmt->execute();
    $ph_stmt->close();

    // 6. Agent notification
    $agent_msg = "Your rental verification for {$rv['StreetAddress']}, {$rv['City']} has been approved! Commission rate: {$commission_rate}% per confirmed payment.";
    $an_stmt = $conn->prepare("
        INSERT INTO agent_notifications (agent_account_id, notif_type, reference_id, title, message, is_read, created_at)
        VALUES (?, 'rental_approved', ?, 'Rental Approved', ?, 0, NOW())
    ");
    $an_stmt->bind_param("iis", $p_agent_id, $p_property_id, $agent_msg);
    $an_stmt->execute();
    $an_stmt->close();

    // 7. Admin notification log
    $admin_msg = "Rental approved for {$rv['StreetAddress']}, {$rv['City']}. Tenant: {$rv['tenant_name']}";
    $notif_stmt = $conn->prepare("INSERT INTO notifications (item_id, item_type, title, message, category, priority, is_read, created_at) VALUES (?, 'property_rental', 'Rental Approved', ?, 'update', 'normal', 1, NOW())");
    $notif_stmt->bind_param("is", $p_property_id, $admin_msg);
    $notif_stmt->execute();
    $notif_stmt->close();

    $conn->commit();

    // ── Send email notifications (after commit, non-blocking) ──
    $emailSent = false;
    try {
        // Fetch agent info for email
        $agStmt = $conn->prepare("SELECT first_name, last_name, email FROM accounts WHERE account_id = ?");
        $agStmt->bind_param("i", $p_agent_id);
        $agStmt->execute();
        $agRow = $agStmt->get_result()->fetch_assoc();
        $agStmt->close();

        if ($agRow && !empty($agRow['email'])) {
            $agentName  = trim($agRow['first_name'] . ' ' . $agRow['last_name']);
            $agentEmail = $agRow['email'];
            $propAddr   = trim($rv['StreetAddress'] . ', ' . $rv['City']);
            $fmtRent    = '₱' . number_format($p_monthly_rent, 2);
            $fmtDeposit = '₱' . number_format($p_security_deposit, 2);
            $fmtPct     = number_format($commission_rate, 2) . '%';
            $leaseStartFmt = date('F j, Y', strtotime($lease_start));
            $leaseEndFmt   = date('F j, Y', strtotime($lease_end));

            // Agent email — Rental Approved
            $bodyContent  = emailGreeting($agentName, 'Congratulations');
            $bodyContent .= emailParagraph(
                'Your rental verification has been <strong style="color:#22c55e;">approved</strong> and the lease has been finalized. '
                . 'Here are the complete details of the new lease.'
            );
            $bodyContent .= emailInfoCard('Lease Details', [
                'Property'        => htmlspecialchars($propAddr),
                'Tenant'          => htmlspecialchars($p_tenant_name),
                'Monthly Rent'    => '<span style="color:#d4af37;font-weight:700;">' . $fmtRent . '</span>',
                'Security Deposit'=> $fmtDeposit,
                'Lease Period'    => $leaseStartFmt . ' &mdash; ' . $leaseEndFmt,
                'Term'            => $lease_term . ' month' . ($lease_term > 1 ? 's' : ''),
            ]);
            $bodyContent .= emailInfoCard('Commission Details', [
                'Commission Rate' => '<span style="color:#ffffff;font-weight:700;">' . $fmtPct . '</span>',
                'Basis'           => 'Per confirmed monthly payment',
            ], '#22c55e');
            $bodyContent .= emailDivider();
            $bodyContent .= emailNotice(
                'What Happens Next',
                'You can now manage this lease from the <strong style="color:#d4af37;">Lease Management</strong> page. '
                . 'Record monthly rent payments with proof and they will be reviewed by the admin. '
                . 'Your commission will be calculated on each confirmed payment.',
                '#2563eb'
            );
            $bodyContent .= emailSignature();

            $html = buildEmailTemplate([
                'accentColor' => '#22c55e',
                'heading'     => 'Rental Approved',
                'subtitle'    => 'Your rental verification has been approved and lease created',
                'body'        => $bodyContent,
                'footerExtra' => 'This is an automated notification from HomeEstate Realty.',
            ]);

            $alt = "Congratulations {$agentName},\n\n"
                 . "Your rental verification for {$propAddr} has been approved.\n\n"
                 . "Tenant: {$p_tenant_name}\nMonthly Rent: {$fmtRent}\nLease: {$leaseStartFmt} to {$leaseEndFmt}\nCommission Rate: {$fmtPct}\n\n"
                 . "You can manage the lease from your Agent Dashboard.\n\n"
                 . "Best regards,\nThe HomeEstate Realty Team";

            $mailResult = sendSystemMail($agentEmail, $agentName, 'Rental Approved – ' . $propAddr, $html, $alt);
            $emailSent = !empty($mailResult['success']);
        }

        // Tenant email — Lease Confirmation (if tenant email provided)
        if (!empty($p_tenant_email)) {
            $tenantBody  = emailGreeting($p_tenant_name);
            $tenantBody .= emailParagraph(
                'We are pleased to confirm that your lease has been finalized. Below are your lease details for your records.'
            );
            $tenantBody .= emailInfoCard('Your Lease Details', [
                'Property'         => htmlspecialchars($propAddr),
                'Monthly Rent'     => '<span style="color:#d4af37;font-weight:700;">' . $fmtRent . '</span>',
                'Security Deposit' => $fmtDeposit,
                'Lease Start'      => $leaseStartFmt,
                'Lease End'        => $leaseEndFmt,
                'Term'             => $lease_term . ' month' . ($lease_term > 1 ? 's' : ''),
            ]);
            $tenantBody .= emailDivider();
            $tenantBody .= emailNotice(
                'Important Information',
                'Please ensure your monthly rent payments are made on time. '
                . 'If you have any questions about your lease, please contact your property agent.',
                '#2563eb'
            );
            $tenantBody .= emailSignature();

            $tenantHtml = buildEmailTemplate([
                'accentColor' => '#2563eb',
                'heading'     => 'Lease Confirmation',
                'subtitle'    => 'Your lease has been finalized',
                'body'        => $tenantBody,
                'footerExtra' => 'This is an automated notification from HomeEstate Realty.',
            ]);

            $tenantAlt = "Hello {$p_tenant_name},\n\nYour lease for {$propAddr} has been finalized.\n\n"
                       . "Monthly Rent: {$fmtRent}\nLease Period: {$leaseStartFmt} to {$leaseEndFmt}\n\n"
                       . "Best regards,\nThe HomeEstate Realty Team";

            sendSystemMail($p_tenant_email, $p_tenant_name, 'Lease Confirmation – ' . $propAddr, $tenantHtml, $tenantAlt);
        }
    } catch (Exception $mailEx) {
        error_log('[RENTAL APPROVAL EMAIL] ' . $mailEx->getMessage());
    }

    echo json_encode([
        'ok'      => true,
        'message' => "Rental approved successfully! Lease created with {$commission_rate}% commission rate.",
        'rental_id' => $rental_id,
        'email_sent' => $emailSent
    ]);

} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    error_log("Rental finalization error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'An error occurred while processing the approval. Please try again.']);
}
?>
