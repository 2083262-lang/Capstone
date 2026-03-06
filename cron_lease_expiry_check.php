<?php
/**
 * Lease Expiry Check
 * 
 * Designed to be called from the admin dashboard or via cron.
 * 1. Sends notifications for leases expiring within 30 days (no duplicates).
 * 2. Auto-expires leases past their end date (Active → Expired).
 *
 * Usage (CLI): php cron_lease_expiry_check.php
 * Usage (Web): included / called from admin dashboard
 */

$is_cli = (php_sapi_name() === 'cli');

if (!$is_cli) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
        header("Location: login.php");
        exit();
    }
}

include __DIR__ . '/connection.php';
require_once __DIR__ . '/mail_helper.php';
require_once __DIR__ . '/email_template.php';

$today = date('Y-m-d');
$warn_date = date('Y-m-d', strtotime('+30 days'));
$results = ['expiring_warned' => 0, 'auto_expired' => 0, 'errors' => []];

// ─── 1. Warn about leases expiring within 30 days ───────────────────────────
$sql_expiring = "
    SELECT fr.rental_id, fr.property_id, fr.tenant_name, fr.tenant_email, fr.lease_end_date,
           fr.agent_id, fr.monthly_rent,
           p.StreetAddress, p.City,
           a.first_name AS agent_first, a.last_name AS agent_last, a.email AS agent_email
    FROM finalized_rentals fr
    JOIN property p ON fr.property_id = p.property_ID
    JOIN accounts a ON fr.agent_id = a.account_id
    WHERE fr.lease_status IN ('Active', 'Renewed')
      AND fr.lease_end_date BETWEEN ? AND ?
";
$stmt = $conn->prepare($sql_expiring);
$stmt->bind_param("ss", $today, $warn_date);
$stmt->execute();
$expiring = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

foreach ($expiring as $lease) {
    $days_left = (int) ((strtotime($lease['lease_end_date']) - strtotime($today)) / 86400);
    $end_formatted = date('M d, Y', strtotime($lease['lease_end_date']));

    // Check for existing expiry warning notification in last 7 days (prevent spam)
    $dup_check = $conn->prepare("
        SELECT notification_id FROM agent_notifications 
        WHERE agent_account_id = ? AND reference_id = ? AND notif_type = 'lease_expiring' 
          AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        LIMIT 1
    ");
    $dup_check->bind_param("ii", $lease['agent_id'], $lease['property_id']);
    $dup_check->execute();
    if ($dup_check->get_result()->num_rows > 0) continue;

    $msg = "Lease for " . $lease['StreetAddress'] . ", " . $lease['City'] . " (Tenant: " . $lease['tenant_name'] . ") expires on $end_formatted ($days_left days remaining). Consider renewing or preparing for termination.";

    // Agent notification
    $ins = $conn->prepare("INSERT INTO agent_notifications (agent_account_id, notif_type, reference_id, title, message, is_read, created_at) VALUES (?, 'lease_expiring', ?, 'Lease Expiring Soon', ?, 0, NOW())");
    $ins->bind_param("iis", $lease['agent_id'], $lease['property_id'], $msg);
    $ins->execute();

    // Admin notification
    $admin_msg = "Lease #" . $lease['rental_id'] . " for " . $lease['StreetAddress'] . " expires on $end_formatted ($days_left days).";
    $admin_ins = $conn->prepare("INSERT INTO notifications (item_id, item_type, title, message, category, priority, is_read, created_at) VALUES (?, 'property_rental', 'Lease Expiring', ?, 'alert', 'high', 0, NOW())");
    $admin_ins->bind_param("is", $lease['property_id'], $admin_msg);
    $admin_ins->execute();

    $results['expiring_warned']++;

    // ── Email the agent about expiring lease ──
    try {
        if (!empty($lease['agent_email'])) {
            $agentName = trim($lease['agent_first'] . ' ' . $lease['agent_last']);
            $propAddr  = trim($lease['StreetAddress'] . ', ' . $lease['City']);

            $bodyContent  = emailGreeting($agentName);
            $bodyContent .= emailParagraph(
                'This is a reminder that a lease you manage is <strong style="color:#f59e0b;">expiring soon</strong>.'
            );
            $bodyContent .= emailInfoCard('Lease Details', [
                'Property'    => htmlspecialchars($propAddr),
                'Tenant'      => htmlspecialchars($lease['tenant_name']),
                'Lease Ends'  => $end_formatted,
                'Days Left'   => '<span style="color:#f59e0b;font-weight:700;">' . $days_left . ' days</span>',
                'Monthly Rent'=> '₱' . number_format($lease['monthly_rent'], 2),
            ], '#f59e0b');
            $bodyContent .= emailNotice('Action Needed', '
                <ul style="margin:0;padding-left:18px;">
                    <li>Discuss renewal terms with the tenant</li>
                    <li>Renew the lease from the Rental Payments page</li>
                    <li>Or prepare for lease termination</li>
                </ul>
            ', '#6366f1');
            $bodyContent .= emailDivider();
            $bodyContent .= emailSignature();

            $html = buildEmailTemplate([
                'accentColor' => '#f59e0b',
                'heading'     => 'Lease Expiring Soon',
                'subtitle'    => 'A lease you manage is expiring in ' . $days_left . ' days',
                'body'        => $bodyContent,
                'footerExtra' => 'This is an automated reminder from HomeEstate Realty.',
            ]);
            $alt = "Hello {$agentName},\n\nLease for {$propAddr} (Tenant: {$lease['tenant_name']}) expires on {$end_formatted} ({$days_left} days). Please take appropriate action.\n\nBest regards,\nThe HomeEstate Realty Team";
            sendSystemMail($lease['agent_email'], $agentName, 'Lease Expiring – ' . $propAddr, $html, $alt);
        }
    } catch (Exception $mailEx) {
        error_log('[CRON LEASE EXPIRY WARNING EMAIL] ' . $mailEx->getMessage());
    }
}

// ─── 2. Auto-expire overdue leases ──────────────────────────────────────────
$sql_overdue = "
    SELECT fr.rental_id, fr.property_id, fr.tenant_name, fr.tenant_email, fr.lease_end_date,
           fr.agent_id, fr.monthly_rent,
           p.StreetAddress, p.City,
           a.first_name AS agent_first, a.last_name AS agent_last, a.email AS agent_email
    FROM finalized_rentals fr
    JOIN property p ON fr.property_id = p.property_ID
    JOIN accounts a ON fr.agent_id = a.account_id
    WHERE fr.lease_status IN ('Active', 'Renewed')
      AND fr.lease_end_date < ?
";
$stmt2 = $conn->prepare($sql_overdue);
$stmt2->bind_param("s", $today);
$stmt2->execute();
$overdue = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

foreach ($overdue as $lease) {
    $conn->begin_transaction();
    try {
        // Mark lease as expired
        $upd = $conn->prepare("UPDATE finalized_rentals SET lease_status = 'Expired' WHERE rental_id = ? AND lease_status IN ('Active', 'Renewed')");
        $upd->bind_param("i", $lease['rental_id']);
        $upd->execute();

        if ($upd->affected_rows === 0) {
            $conn->rollback();
            continue;
        }

        // NOTE: Property stays Rented to allow renewal. Agent must explicitly terminate to unlock.

        // Agent notification
        $exp_msg = "Lease #" . $lease['rental_id'] . " for " . $lease['StreetAddress'] . " has expired. You can renew or terminate the lease.";
        $n1 = $conn->prepare("INSERT INTO agent_notifications (agent_account_id, notif_type, reference_id, title, message, is_read, created_at) VALUES (?, 'lease_expired', ?, 'Lease Expired', ?, 0, NOW())");
        $n1->bind_param("iis", $lease['agent_id'], $lease['property_id'], $exp_msg);
        $n1->execute();

        // Admin notification
        $admin_exp_msg = "Lease #" . $lease['rental_id'] . " for " . $lease['StreetAddress'] . " has auto-expired.";
        $n2 = $conn->prepare("INSERT INTO notifications (item_id, item_type, title, message, category, priority, is_read, created_at) VALUES (?, 'property_rental', 'Lease Expired', ?, 'alert', 'high', 0, NOW())");
        $n2->bind_param("is", $lease['property_id'], $admin_exp_msg);
        $n2->execute();

        $conn->commit();
        $results['auto_expired']++;

        // ── Email agent about expired lease ──
        try {
            if (!empty($lease['agent_email'])) {
                $agentName = trim($lease['agent_first'] . ' ' . $lease['agent_last']);
                $propAddr  = trim($lease['StreetAddress'] . ', ' . $lease['City']);

                $bodyContent  = emailGreeting($agentName);
                $bodyContent .= emailParagraph(
                    'A lease you manage has <strong style="color:#ef4444;">expired</strong>. Please take action.'
                );
                $bodyContent .= emailInfoCard('Expired Lease', [
                    'Property'   => htmlspecialchars($propAddr),
                    'Tenant'     => htmlspecialchars($lease['tenant_name']),
                    'Lease Ended'=> date('M j, Y', strtotime($lease['lease_end_date'])),
                ], '#ef4444');
                $bodyContent .= emailNotice('Next Steps', '
                    <ul style="margin:0;padding-left:18px;">
                        <li><strong>Renew</strong> the lease with updated terms</li>
                        <li>Or <strong>terminate</strong> to make the property available again</li>
                    </ul>
                ', '#6366f1');
                $bodyContent .= emailDivider();
                $bodyContent .= emailSignature();

                $html = buildEmailTemplate([
                    'accentColor' => '#ef4444',
                    'heading'     => 'Lease Expired',
                    'subtitle'    => 'Immediate action required',
                    'body'        => $bodyContent,
                    'footerExtra' => 'This is an automated notification from HomeEstate Realty.',
                ]);
                $alt = "Hello {$agentName},\n\nLease for {$propAddr} (Tenant: {$lease['tenant_name']}) has expired. Please renew or terminate.\n\nBest regards,\nThe HomeEstate Realty Team";
                sendSystemMail($lease['agent_email'], $agentName, 'Lease Expired – ' . $propAddr, $html, $alt);
            }
        } catch (Exception $mailEx) {
            error_log('[CRON LEASE EXPIRED EMAIL] ' . $mailEx->getMessage());
        }
    } catch (Exception $e) {
        $conn->rollback();
        $results['errors'][] = "Rental #{$lease['rental_id']}: " . $e->getMessage();
    }
}

// ─── Output ──────────────────────────────────────────────────────────────────
if ($is_cli) {
    echo "Lease Expiry Check Complete\n";
    echo "  Expiring warned: {$results['expiring_warned']}\n";
    echo "  Auto-expired:    {$results['auto_expired']}\n";
    if (!empty($results['errors'])) {
        echo "  Errors:\n";
        foreach ($results['errors'] as $err) echo "    - $err\n";
    }
} else {
    // Store results in session for dashboard display
    $_SESSION['lease_check_results'] = $results;
}
