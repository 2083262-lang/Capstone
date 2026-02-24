<?php
// Start output buffering
ob_start();
session_start();
include 'connection.php';
require_once __DIR__ . '/mail_helper.php';

// --- Security Check: Ensure admin is logged in ---
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$admin_account_id = $_SESSION['account_id'];
$property_data = null;
$property_images = [];
$agent_info = null;
$amenities = [];
$error_message = '';
$success_message = '';
$price_history = []; // Initialize array for price history

$property_id_to_review = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($property_id_to_review <= 0) {
    header("Location: property.php");
    exit();
}

if (isset($_GET['status']) && isset($_GET['msg'])) {
    if ($_GET['status'] === 'success') $success_message = htmlspecialchars(urldecode($_GET['msg']));
    if ($_GET['status'] === 'error') $error_message = htmlspecialchars(urldecode($_GET['msg']));
}

// --- Handle ALL Form Submissions (Approve, Reject, Update Price) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $action = $_POST['action'];
    $posted_property_id = $_POST['property_id'] ?? 0;
    $admin_id = $_SESSION['account_id']; // Get the logged-in admin's ID for logging

    if ($posted_property_id == $property_id_to_review) {
        $conn->begin_transaction();
        try {
            // NEW: Get the current timestamp based on client's time if available
            $current_datetime = new DateTime("now", new DateTimeZone('Asia/Manila')); // Fallback to PH time
            if (isset($_POST['client_timestamp'])) {
                // Create a DateTime object from the ISO string sent by the browser (defensive)
                try {
                    $client_dt = new DateTime($_POST['client_timestamp']);
                    if ($client_dt) {
                        $current_datetime = $client_dt;
                    }
                } catch (Exception $ex) {
                    // ignore and use fallback timezone
                }
            }
            $current_date_sql = $current_datetime->format('Y-m-d');
            $current_datetime_sql = $current_datetime->format('Y-m-d H:i:s');


                        if ($action === 'approve') {
                                $stmt = $conn->prepare("UPDATE property SET approval_status = 'approved' WHERE property_ID = ?");
                                $stmt->bind_param("i", $posted_property_id);
                                $stmt->execute();

                                // Prepare success message
                                $success_redirect = "Property approved successfully.";

                                // After approval, record in logs with context
                                // 1) status_log: approved
                                if ($sl = $conn->prepare("INSERT INTO status_log (item_id, item_type, action, reason_message, action_by_account_id) VALUES (?, 'property', 'approved', ?, ?)")) {
                                    $approved_msg = 'Listing approved and published';
                                    $sl->bind_param('isi', $posted_property_id, $approved_msg, $admin_id);
                                    $sl->execute();
                                    $sl->close();
                                }

                                // 2) property_log: APPROVED with client timestamp and context (fallback if columns missing)
                                $pl_sql_ext = "INSERT INTO property_log (property_id, account_id, action, log_timestamp, reason_message) VALUES (?, ?, 'APPROVED', ?, ?)";
                                $pl = $conn->prepare($pl_sql_ext);
                                if ($pl) {
                                    $approved_detail = 'Listing approved by admin';
                                    $pl->bind_param('iiss', $posted_property_id, $admin_id, $current_datetime_sql, $approved_detail);
                                    $pl->execute();
                                    $pl->close();
                                } else {
                                    // Fallback minimal insert if extended columns are not available
                                    if ($pl2 = $conn->prepare("INSERT INTO property_log (property_id, account_id, action, log_timestamp) VALUES (?, ?, 'APPROVED', ?)")) {
                                        $pl2->bind_param('iis', $posted_property_id, $admin_id, $current_datetime_sql);
                                        $pl2->execute();
                                        $pl2->close();
                                    }
                                }

                                // Fetch agent (creator) and property info for email notification
                                $infoSql = "SELECT p.StreetAddress, p.City, p.PropertyType, p.ListingPrice,
                                                                     a.first_name, a.last_name, a.email, a.account_id AS agent_account_id
                                                        FROM property p
                                                        JOIN property_log pl ON pl.property_id = p.property_ID AND pl.action = 'CREATED'
                                                        JOIN accounts a ON a.account_id = pl.account_id
                                                        WHERE p.property_ID = ?
                                                        ORDER BY pl.log_timestamp ASC LIMIT 1";
                                $info = $conn->prepare($infoSql);
                                $info->bind_param('i', $posted_property_id);
                                $info->execute();
                                $agentRow = $info->get_result()->fetch_assoc();
                                $info->close();

                                // Agent notification — property approved
                                if ($agentRow && !empty($agentRow['agent_account_id'])) {
                                    require_once __DIR__ . '/agent_pages/agent_notification_helper.php';
                                    $addr_notif = trim(($agentRow['StreetAddress'] ?? '') . ', ' . ($agentRow['City'] ?? ''));
                                    createAgentNotification(
                                        $conn,
                                        (int)$agentRow['agent_account_id'],
                                        'property_approved',
                                        'Property Approved',
                                        "Your listing at {$addr_notif} ({$agentRow['PropertyType']}) has been approved and is now live.",
                                        $posted_property_id
                                    );
                                }

                                if ($agentRow && !empty($agentRow['email'])) {
                                        $toEmail = $agentRow['email'];
                                        $toName  = trim(($agentRow['first_name'] ?? '') . ' ' . ($agentRow['last_name'] ?? '')) ?: 'Agent';
                                        $addr    = trim(($agentRow['StreetAddress'] ?? '') . ', ' . ($agentRow['City'] ?? ''));
                                        $ptype   = $agentRow['PropertyType'] ?? 'Property';
                                        $price   = '₱' . number_format((float)$agentRow['ListingPrice'], 2);

                                        $subject = '✅ Listing Approved — ' . $ptype;
                                        $body = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Listing Approved</title>
</head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,\'Helvetica Neue\',Arial,sans-serif;background-color:#0a0a0a;line-height:1.6;">
    
    <!-- Email Container -->
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#0a0a0a;padding:60px 20px;">
        <tr>
            <td align="center">
                
                <!-- Content Card -->
                <table width="600" cellpadding="0" cellspacing="0" style="background-color:#111111;border:1px solid #1f1f1f;border-radius:4px;max-width:600px;">
                    
                    <!-- Success Accent Line -->
                    <tr>
                        <td style="background:linear-gradient(90deg,#10b981 0%,#059669 100%);height:3px;"></td>
                    </tr>
                    
                    <!-- Header -->
                    <tr>
                        <td style="padding:48px 48px 32px 48px;text-align:center;border-bottom:1px solid #1f1f1f;">
                            <div style="font-size:48px;margin-bottom:16px;">✅</div>
                            <h1 style="margin:0 0 12px 0;color:#10b981;font-size:14px;font-weight:600;text-transform:uppercase;letter-spacing:3px;">Listing Approved</h1>
                            <p style="margin:0;color:#666666;font-size:15px;font-weight:400;">Your property is now live on the platform</p>
                        </td>
                    </tr>
                    
                    <!-- Body Content -->
                    <tr>
                        <td style="padding:48px 48px 40px 48px;">
                            
                            <!-- Greeting -->
                            <p style="margin:0 0 24px 0;font-size:14px;color:#999999;line-height:1.7;">
                                Hello <span style="color:#d4af37;font-weight:500;">' . htmlspecialchars($toName) . '</span>,
                            </p>
                            
                            <p style="margin:0 0 32px 0;font-size:14px;color:#999999;line-height:1.7;">
                                Great news! Your property listing has been approved by our admin team and is now live on the platform. Potential buyers can now view and inquire about your property.
                            </p>
                            
                            <!-- Property Details Card -->
                            <div style="background-color:#0d1117;border:1px solid #2563eb;border-radius:2px;padding:24px;margin:0 0 32px 0;">
                                <p style="margin:0 0 16px 0;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:2px;color:#2563eb;">Property Details</p>
                                <table width="100%" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td style="padding:8px 0;font-size:13px;color:#666666;vertical-align:top;width:35%;">Property Type</td>
                                        <td style="padding:8px 0;font-size:13px;color:#ffffff;font-weight:500;">' . htmlspecialchars($ptype) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding:8px 0;font-size:13px;color:#666666;vertical-align:top;">Address</td>
                                        <td style="padding:8px 0;font-size:13px;color:#ffffff;font-weight:500;">' . htmlspecialchars($addr) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding:8px 0;font-size:13px;color:#666666;vertical-align:top;">Listing Price</td>
                                        <td style="padding:8px 0;font-size:15px;color:#10b981;font-weight:600;">' . htmlspecialchars($price) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding:8px 0;font-size:13px;color:#666666;vertical-align:top;">Approved On</td>
                                        <td style="padding:8px 0;font-size:13px;color:#ffffff;font-weight:500;">' . htmlspecialchars($current_datetime->format('F j, Y g:i A')) . '</td>
                                    </tr>
                                </table>
                            </div>
                            
                            <!-- Divider -->
                            <div style="height:1px;background-color:#1f1f1f;margin:0 0 32px 0;"></div>
                            
                            <!-- Next Steps -->
                            <div style="background-color:#0d1117;border-left:2px solid #d4af37;padding:16px 20px;margin:0 0 24px 0;">
                                <p style="margin:0;font-size:13px;color:#999999;line-height:1.6;">
                                    <strong style="color:#d4af37;display:block;margin-bottom:6px;font-size:12px;text-transform:uppercase;letter-spacing:1px;">Next Steps</strong>
                                    You can now manage this listing from your Agent Dashboard. Monitor inquiries, schedule tours, and update property information as needed.
                                </p>
                            </div>
                            
                            <!-- Footer Message -->
                            <p style="margin:0;font-size:13px;color:#666666;line-height:1.6;text-align:center;">
                                Thank you for using our platform to showcase your property.
                            </p>
                            
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background-color:#0a0a0a;padding:32px 48px;border-top:1px solid #1f1f1f;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="text-align:center;">
                                        <p style="margin:0 0 8px 0;font-size:13px;color:#666666;">
                                            <strong style="color:#d4af37;">HomeEstate Realty</strong>
                                        </p>
                                        <p style="margin:0;font-size:11px;color:#444444;">
                                            © ' . date('Y') . ' All rights reserved
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                </table>
                
                <!-- Support Link -->
                <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;margin-top:32px;">
                    <tr>
                        <td style="text-align:center;">
                            <p style="margin:0;font-size:12px;color:#444444;">
                                Need assistance? <a href="#" style="color:#2563eb;text-decoration:none;font-weight:500;">Contact Support</a>
                            </p>
                        </td>
                    </tr>
                </table>
                
            </td>
        </tr>
    </table>
    
</body>
</html>';

                                        // Send email (best-effort; do not block approval)
                                        try { sendSystemMail($toEmail, $toName, $subject, $body); } catch (Throwable $t) { /* ignore */ }
                                }

            } elseif ($action === 'reject') {
                $reject_reason = trim($_POST['reject_reason'] ?? '');
                $stmt = $conn->prepare("UPDATE property SET approval_status = 'rejected' WHERE property_ID = ?");
                $stmt->bind_param("i", $posted_property_id);
                $stmt->execute();
                                $success_redirect = "Property rejected successfully.";

                                // Log the change with client timestamp and context
                                // 1) property_log: REJECTED with reason (fallback if extended columns missing)
                                $pl_sql_ext = "INSERT INTO property_log (property_id, account_id, action, log_timestamp, reason_message) VALUES (?, ?, 'REJECTED', ?, ?)";
                                $pl = $conn->prepare($pl_sql_ext);
                                if ($pl) {
                                    $pl->bind_param('iiss', $posted_property_id, $admin_id, $current_datetime_sql, $reject_reason);
                                    $pl->execute();
                                    $pl->close();
                                } else {
                                    if ($pl2 = $conn->prepare("INSERT INTO property_log (property_id, account_id, action, log_timestamp) VALUES (?, ?, 'REJECTED', ?)")) {
                                        $pl2->bind_param('iis', $posted_property_id, $admin_id, $current_datetime_sql);
                                        $pl2->execute();
                                        $pl2->close();
                                    }
                                }

                                // Record reason in status_log if available (best-effort)
                                if ($reject_reason !== '') {
                                    if ($logStmt = $conn->prepare("INSERT INTO status_log (item_id, item_type, action, reason_message, action_by_account_id) VALUES (?, 'property', 'rejected', ?, ?)")) {
                                        $logStmt->bind_param('isi', $posted_property_id, $reject_reason, $admin_id);
                                        $logStmt->execute();
                                        $logStmt->close();
                                    }
                                }

                                // Notify the listing agent via email
                                $infoSql = "SELECT p.StreetAddress, p.City, p.PropertyType, p.ListingPrice,
                                                                     a.first_name, a.last_name, a.email, a.account_id AS agent_account_id
                                                        FROM property p
                                                        JOIN property_log pl ON pl.property_id = p.property_ID AND pl.action = 'CREATED'
                                                        JOIN accounts a ON a.account_id = pl.account_id
                                                        WHERE p.property_ID = ?
                                                        ORDER BY pl.log_timestamp ASC LIMIT 1";
                                $info = $conn->prepare($infoSql);
                                $info->bind_param('i', $posted_property_id);
                                $info->execute();
                                $agentRow = $info->get_result()->fetch_assoc();
                                $info->close();

                                // Agent notification — property rejected
                                if ($agentRow && !empty($agentRow['agent_account_id'])) {
                                    if (!function_exists('createAgentNotification')) {
                                        require_once __DIR__ . '/agent_pages/agent_notification_helper.php';
                                    }
                                    $addr_notif = trim(($agentRow['StreetAddress'] ?? '') . ', ' . ($agentRow['City'] ?? ''));
                                    $reason_text = $reject_reason !== '' ? " Reason: {$reject_reason}" : '';
                                    createAgentNotification(
                                        $conn,
                                        (int)$agentRow['agent_account_id'],
                                        'property_rejected',
                                        'Property Rejected',
                                        "Your listing at {$addr_notif} ({$agentRow['PropertyType']}) was rejected.{$reason_text}",
                                        $posted_property_id
                                    );
                                }

                                if ($agentRow && !empty($agentRow['email'])) {
                                        $toEmail = $agentRow['email'];
                                        $toName  = trim(($agentRow['first_name'] ?? '') . ' ' . ($agentRow['last_name'] ?? '')) ?: 'Agent';
                                        $addr    = trim(($agentRow['StreetAddress'] ?? '') . ', ' . ($agentRow['City'] ?? ''));
                                        $ptype   = $agentRow['PropertyType'] ?? 'Property';
                                        $price   = '₱' . number_format((float)$agentRow['ListingPrice'], 2);

                                                            $subject = '❗ Listing Rejected — ' . $ptype;
                                                            $reasonBlock = $reject_reason !== '' ? ("<tr>
                                        <td style=\"padding:8px 0;font-size:13px;color:#666666;vertical-align:top;width:35%;\">Rejection Reason</td>
                                        <td style=\"padding:8px 0;font-size:13px;color:#ef4444;font-weight:500;\">" . htmlspecialchars($reject_reason) . "</td>
                                    </tr>") : '';
                                                            $body = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Listing Rejected</title>
</head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,\'Helvetica Neue\',Arial,sans-serif;background-color:#0a0a0a;line-height:1.6;">
    
    <!-- Email Container -->
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#0a0a0a;padding:60px 20px;">
        <tr>
            <td align="center">
                
                <!-- Content Card -->
                <table width="600" cellpadding="0" cellspacing="0" style="background-color:#111111;border:1px solid #1f1f1f;border-radius:4px;max-width:600px;">
                    
                    <!-- Error Accent Line -->
                    <tr>
                        <td style="background:linear-gradient(90deg,#ef4444 0%,#dc2626 100%);height:3px;"></td>
                    </tr>
                    
                    <!-- Header -->
                    <tr>
                        <td style="padding:48px 48px 32px 48px;text-align:center;border-bottom:1px solid #1f1f1f;">
                            <div style="font-size:48px;margin-bottom:16px;">❗</div>
                            <h1 style="margin:0 0 12px 0;color:#ef4444;font-size:14px;font-weight:600;text-transform:uppercase;letter-spacing:3px;">Listing Rejected</h1>
                            <p style="margin:0;color:#666666;font-size:15px;font-weight:400;">Your property submission requires attention</p>
                        </td>
                    </tr>
                    
                    <!-- Body Content -->
                    <tr>
                        <td style="padding:48px 48px 40px 48px;">
                            
                            <!-- Greeting -->
                            <p style="margin:0 0 24px 0;font-size:14px;color:#999999;line-height:1.7;">
                                Hello <span style="color:#d4af37;font-weight:500;">' . htmlspecialchars($toName) . '</span>,
                            </p>
                            
                            <p style="margin:0 0 32px 0;font-size:14px;color:#999999;line-height:1.7;">
                                We have reviewed your property submission, but unfortunately we cannot approve it at this time. Please review the details below and make the necessary changes.
                            </p>
                            
                            <!-- Property Details Card -->
                            <div style="background-color:#0d1117;border:1px solid #ef4444;border-radius:2px;padding:24px;margin:0 0 32px 0;">
                                <p style="margin:0 0 16px 0;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:2px;color:#ef4444;">Property Details</p>
                                <table width="100%" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td style="padding:8px 0;font-size:13px;color:#666666;vertical-align:top;width:35%;">Property Type</td>
                                        <td style="padding:8px 0;font-size:13px;color:#ffffff;font-weight:500;">' . htmlspecialchars($ptype) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding:8px 0;font-size:13px;color:#666666;vertical-align:top;">Address</td>
                                        <td style="padding:8px 0;font-size:13px;color:#ffffff;font-weight:500;">' . htmlspecialchars($addr) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding:8px 0;font-size:13px;color:#666666;vertical-align:top;">Listing Price</td>
                                        <td style="padding:8px 0;font-size:13px;color:#ffffff;font-weight:500;">' . htmlspecialchars($price) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding:8px 0;font-size:13px;color:#666666;vertical-align:top;">Reviewed On</td>
                                        <td style="padding:8px 0;font-size:13px;color:#ffffff;font-weight:500;">' . htmlspecialchars($current_datetime->format('F j, Y g:i A')) . '</td>
                                    </tr>
                                    ' . $reasonBlock . '
                                </table>
                            </div>
                            
                            <!-- Divider -->
                            <div style="height:1px;background-color:#1f1f1f;margin:0 0 32px 0;"></div>
                            
                            <!-- Next Steps -->
                            <div style="background-color:#0d1117;border-left:2px solid #d4af37;padding:16px 20px;margin:0 0 24px 0;">
                                <p style="margin:0;font-size:13px;color:#999999;line-height:1.6;">
                                    <strong style="color:#d4af37;display:block;margin-bottom:6px;font-size:12px;text-transform:uppercase;letter-spacing:1px;">What You Can Do</strong>
                                    You can revise the property details and resubmit for review. Please address the issues mentioned above and ensure all information is accurate and complete. If you have any questions, please contact our support team.
                                </p>
                            </div>
                            
                            <!-- Footer Message -->
                            <p style="margin:0;font-size:13px;color:#666666;line-height:1.6;text-align:center;">
                                We appreciate your understanding and look forward to your resubmission.
                            </p>
                            
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background-color:#0a0a0a;padding:32px 48px;border-top:1px solid #1f1f1f;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="text-align:center;">
                                        <p style="margin:0 0 8px 0;font-size:13px;color:#666666;">
                                            <strong style="color:#d4af37;">HomeEstate Realty</strong>
                                        </p>
                                        <p style="margin:0;font-size:11px;color:#444444;">
                                            © ' . date('Y') . ' All rights reserved
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                </table>
                
                <!-- Support Link -->
                <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;margin-top:32px;">
                    <tr>
                        <td style="text-align:center;">
                            <p style="margin:0;font-size:12px;color:#444444;">
                                Need assistance? <a href="#" style="color:#2563eb;text-decoration:none;font-weight:500;">Contact Support</a>
                            </p>
                        </td>
                    </tr>
                </table>
                
            </td>
        </tr>
    </table>
    
</body>
</html>';

                                        try { sendSystemMail($toEmail, $toName, $subject, $body); } catch (Throwable $t) { /* ignore */ }
                                }

            } elseif ($action === 'update_price') {
                $new_price = $_POST['new_price'] ?? 0;
                if (!is_numeric($new_price) || $new_price <= 0) {
                    throw new Exception("Invalid price amount provided.");
                }

                $stmt_update = $conn->prepare("UPDATE property SET ListingPrice = ? WHERE property_ID = ?");
                $stmt_update->bind_param("di", $new_price, $posted_property_id);
                $stmt_update->execute();
                $stmt_update->close();

                // UPDATED: Use the client's date
                $stmt_history = $conn->prepare("INSERT INTO price_history (property_id, event_date, event_type, price) VALUES (?, ?, 'Price Change', ?)");
                $stmt_history->bind_param("isd", $posted_property_id, $current_date_sql, $new_price);
                $stmt_history->execute();
                $stmt_history->close();
                
                // UPDATED: Use the client's full timestamp
                $stmt_log = $conn->prepare("INSERT INTO property_log (property_id, account_id, action, log_timestamp) VALUES (?, ?, 'UPDATED', ?)");
                $stmt_log->bind_param("iis", $posted_property_id, $admin_id, $current_datetime_sql);
                $stmt_log->execute();
                $stmt_log->close();
                
                $success_redirect = "Property price updated successfully.";
            }
            
            $conn->commit();
            header("Location: view_property.php?id=" . $property_id_to_review . "&status=success&msg=" . urlencode($success_redirect));
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $error_redirect = "An error occurred: " . $e->getMessage();
            header("Location: view_property.php?id=" . $property_id_to_review . "&status=error&msg=" . urlencode($error_redirect));
            exit();
        }
    }
}

// --- Fetch Property Data (Main Query) ---
$sql_property = "SELECT * FROM property WHERE property_ID = ?";
$stmt_property = $conn->prepare($sql_property);
$stmt_property->bind_param("i", $property_id_to_review);
$stmt_property->execute();
$result_property = $stmt_property->get_result();
$property_data = $result_property->fetch_assoc();
$stmt_property->close();

// --- Fetch Rental Details if property is For Rent ---
$rental_data = null;
if ($property_data && $property_data['Status'] === 'For Rent') {
    $sql_rental = "SELECT monthly_rent, security_deposit, lease_term_months, furnishing, available_from 
                   FROM rental_details WHERE property_id = ? LIMIT 1";
    $stmt_rental = $conn->prepare($sql_rental);
    $stmt_rental->bind_param("i", $property_id_to_review);
    $stmt_rental->execute();
    $result_rental = $stmt_rental->get_result();
    $rental_data = $result_rental->fetch_assoc();
    $stmt_rental->close();
}

if ($property_data) {

    $sql_history = "SELECT event_date, event_type, price FROM price_history WHERE property_id = ? ORDER BY event_date DESC";
    $stmt_history = $conn->prepare($sql_history);
    $stmt_history->bind_param("i", $property_id_to_review);
    $stmt_history->execute();
    $history_result = $stmt_history->get_result();
    $price_history_raw = $history_result->fetch_all(MYSQLI_ASSOC);
    $stmt_history->close();
    
    // --- Process Price History for Table Display (reversed)
    $price_history_for_table = array_reverse($price_history_raw);
    $price_history = []; // This will be for the formatted table view

    // Calculate percentage change
    for ($i = 0; $i < count($price_history_raw); $i++) {
        $current_event = $price_history_raw[$i];
        $previous_price = isset($price_history_raw[$i + 1]) ? $price_history_raw[$i + 1]['price'] : null;
        $change_percentage = null;
        $change_class = '';

        if ($previous_price && $previous_price > 0) {
            $change = (($current_event['price'] - $previous_price) / $previous_price) * 100;
            $change_percentage = round($change, 2);
            if ($change > 0) {
                $change_class = 'text-success'; // Price increased
            } elseif ($change < 0) {
                $change_class = 'text-danger'; // Price decreased
            }
        }
        // Add calculated data to a new array
        $price_history[] = [
            'event_date' => date('M d, Y', strtotime($current_event['event_date'])),
            'event_type' => $current_event['event_type'],
            'price' => '₱' . number_format($current_event['price']),
            'change_percentage' => $change_percentage,
            'change_class' => $change_class
        ];
    }
    // --- Fetch Agent who CREATED the property (including their account_id) ---
    $sql_agent = "SELECT a.account_id, a.first_name, a.middle_name, a.last_name, a.email,
                          ai.profile_picture_url AS agent_profile_picture_url,
                          adm.profile_picture_url AS admin_profile_picture_url
                  FROM property_log pl
                  JOIN accounts a ON pl.account_id = a.account_id
                  LEFT JOIN agent_information ai ON a.account_id = ai.account_id
                  LEFT JOIN admin_information adm ON a.account_id = adm.account_id
                  WHERE pl.property_id = ? AND pl.action = 'CREATED'
                  LIMIT 1";
    $stmt_agent = $conn->prepare($sql_agent);
    $stmt_agent->bind_param("i", $property_id_to_review);
    $stmt_agent->execute();
    $result_agent = $stmt_agent->get_result();
    $agent_info = $result_agent->fetch_assoc();
    $stmt_agent->close();
    
    // Fetch all other related data (images, amenities, etc.)
    // ... (This logic is unchanged and omitted for brevity) ...
    // Fetch all featured images for the property
    $sql_images = "SELECT PhotoURL FROM property_images WHERE property_ID = ? ORDER BY SortOrder ASC";
    $stmt_images = $conn->prepare($sql_images);
    $stmt_images->bind_param("i", $property_id_to_review);
    $stmt_images->execute();
    $result_images = $stmt_images->get_result();
    while ($row = $result_images->fetch_assoc()) {
        $property_images[] = $row['PhotoURL'];
    }
    $stmt_images->close();

    // Fetch floor images grouped by floor number
    $floor_images = [];
    $sql_floor_images = "SELECT floor_number, photo_url, sort_order FROM property_floor_images WHERE property_id = ? ORDER BY floor_number ASC, sort_order ASC";
    $stmt_floor_images = $conn->prepare($sql_floor_images);
    $stmt_floor_images->bind_param("i", $property_id_to_review);
    $stmt_floor_images->execute();
    $result_floor_images = $stmt_floor_images->get_result();
    while ($row = $result_floor_images->fetch_assoc()) {
        $floor_num = (int)$row['floor_number'];
        if (!isset($floor_images[$floor_num])) {
            $floor_images[$floor_num] = [];
        }
        $floor_images[$floor_num][] = $row['photo_url'];
    }
    $stmt_floor_images->close();

    // Fetch all amenities for this property
    $sql_amenities = "SELECT am.amenity_name FROM property_amenities pa
                      JOIN amenities am ON pa.amenity_id = am.amenity_id
                      WHERE pa.property_id = ?";
    $stmt_amenities = $conn->prepare($sql_amenities);
    $stmt_amenities->bind_param("i", $property_id_to_review);
    $stmt_amenities->execute();
    $result_amenities = $stmt_amenities->get_result();
    while ($row = $result_amenities->fetch_assoc()) {
        $amenities[] = $row['amenity_name'];
    }
    $stmt_amenities->close();
    
    // --- REAL-TIME CALCULATION LOGIC ---
    $price_per_sqft = 'N/A';
    if (!empty($property_data['SquareFootage']) && $property_data['SquareFootage'] > 0) {
        $raw_value = $property_data['ListingPrice'] / $property_data['SquareFootage'];
        $price_per_sqft = '₱' . number_format($raw_value, 2);
    }
    // Compute days on market safely (handle invalid dates like 0000-00-00)
    $days_on_market = null;
    $listing_date_raw = $property_data['ListingDate'] ?? '';
    if (!empty($listing_date_raw) && $listing_date_raw !== '0000-00-00') {
        $listing_ts = strtotime($listing_date_raw);
        if ($listing_ts !== false) {
            $listingDateObj = new DateTime(date('Y-m-d', $listing_ts));
            $today = new DateTime();
            $interval = $today->diff($listingDateObj);
            $days_on_market = $interval->days;
        }
    }

} else {
    $error_message = "Property not found.";
}

ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Property - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        /* Tailwind-Inspired Luxury Real Estate Theme */
        :root {
            --primary-color: #111827;
            --secondary-color: #bc9e42;
            --background-color: #f9fafb;
            --card-bg-color: #ffffff;
            --border-color: #e5e7eb;
            --success-color: #10b981;
            --danger-color: #ef4444;
            --warning-color: #f59e0b;
            --text-primary: #111827;
            --text-secondary: #6b7280;
            --text-muted: #9ca3af;
            /* Clean Tailwind shadows */
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
        }
        
        body { 
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; 
            background-color: var(--background-color); 
            font-weight: 400;
            line-height: 1.6;
            color: var(--text-primary);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        
        /* Modern admin layout - minimal and clean */
        .admin-sidebar {
            background: linear-gradient(180deg, var(--primary-color) 0%, #1f2937 100%);
            color: #fff;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            width: 290px;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 2px 0 8px rgba(0,0,0,0.05);
        }

        .admin-content {
            margin-left: 290px;
            padding: 0;
            min-height: 100vh;
        }
        
        @media (max-width: 1200px) {
            .admin-sidebar {
                transform: translateX(-100%);
            }
            
            .admin-content {
                margin-left: 0 !important;
                padding: 0;
            }
        }
        
        @media (max-width: 768px) {
            .admin-content {
                margin-left: 0 !important;
                padding: 0;
            }
        }

        /* Luxury Hero Section - Clean & Minimal */
        .property-hero {
            position: relative;
            height: 65vh;
            min-height: 450px;
            max-height: 700px;
            overflow: hidden;
            background: #000;
        }

        .hero-image {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            z-index: 1;
        }

        .hero-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(to bottom, rgba(0,0,0,0.1) 0%, rgba(0,0,0,0.4) 100%);
            z-index: 2;
        }

        .hero-content {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            z-index: 3;
            color: white;
            padding: 3rem 0 2.5rem;
            background: linear-gradient(to top, rgba(0,0,0,0.75) 0%, transparent 100%);
        }

        .property-price {
            font-size: 3rem;
            font-weight: 700;
            color: #ffffff;
            text-shadow: 0 2px 8px rgba(0,0,0,0.3);
            margin-bottom: 0.75rem;
            letter-spacing: -0.02em;
        }

        .property-address {
            font-size: 1.375rem;
            font-weight: 400;
            color: rgba(255, 255, 255, 0.95);
            text-shadow: 0 1px 3px rgba(0,0,0,0.3);
            margin-bottom: 1.5rem;
            letter-spacing: -0.01em;
        }

        .property-specs {
            display: flex;
            gap: 1.5rem;
            font-size: 1rem;
            font-weight: 500;
            color: rgba(255, 255, 255, 0.9);
        }

        .property-specs span {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1.25rem;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 9999px;
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .property-specs span i {
            font-size: 1.125rem;
        }

        /* Elegant Image Gallery */
        .hero-thumbnails {
            position: absolute;
            bottom: 1.5rem;
            right: 1.5rem;
            z-index: 4;
            display: flex;
            gap: 0.5rem;
            max-width: 320px;
            overflow-x: auto;
            padding: 0.625rem;
            background: rgba(0, 0, 0, 0.4);
            border-radius: 12px;
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .hero-thumbnail {
            width: 70px;
            height: 50px;
            object-fit: cover;
            border-radius: 8px;
            cursor: pointer;
            border: 2px solid transparent;
            transition: border-color 0.2s ease, opacity 0.2s ease;
            flex-shrink: 0;
            opacity: 0.7;
        }

        .hero-thumbnail:hover,
        .hero-thumbnail.active {
            border-color: var(--secondary-color);
            opacity: 1;
        }

        /* Clean Status Badges */
        .hero-status-badge {
            position: absolute;
            top: 1.5rem;
            right: 1.5rem;
            z-index: 4;
            font-weight: 600;
            font-size: 0.875rem;
            padding: 0.625rem 1.125rem;
            border-radius: 9999px;
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            text-transform: capitalize;
            letter-spacing: 0.025em;
            line-height: 1;
        }

        .badge-approved { 
            background: linear-gradient(135deg, #10b981 0%, #059669 100%); 
            color: white; 
        }
        .badge-pending { 
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); 
            color: white; 
        }
        .badge-rejected { 
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); 
            color: white; 
        }

        .property-status-badge {
            position: absolute;
            top: 1.5rem;
            left: 1.5rem;
            z-index: 4;
            font-weight: 600;
            font-size: 0.875rem;
            padding: 0.625rem 1.125rem;
            border-radius: 9999px;
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            text-transform: capitalize;
            letter-spacing: 0.025em;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            line-height: 1;
        }

        .status-for-sale {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
        }

        .status-for-rent {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }



        .content-section {
            background: var(--card-bg-color);
            border-radius: 16px;
            padding: 3rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 2.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            letter-spacing: -0.01em;
        }

        .section-title i {
            color: var(--secondary-color);
            font-size: 1.5rem;
        }

        /* Elegant Facts Grid */
        .facts-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 2rem;
        }

        @media (max-width: 992px) {
            .facts-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 576px) {
            .facts-grid {
                grid-template-columns: 1fr;
            }
        }

        .fact-item {
            display: flex;
            flex-direction: column;
            justify-content: center;
            text-align: center;
            padding: 2.5rem 2rem;
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            min-height: 130px;
        }

        .fact-item:hover {
            border-color: var(--secondary-color);
            box-shadow: var(--shadow-md);
        }

        .fact-label {
            font-size: 0.75rem;
            color: var(--text-secondary);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .fact-label i {
            font-size: 0.875rem;
            color: var(--secondary-color);
        }

        .fact-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.2;
            letter-spacing: -0.01em;
        }

        .fact-value.highlight {
            color: var(--secondary-color);
        }

        .fact-value small {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-secondary);
        }


        /* Clean Amenities Grid */
        .amenities-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 1rem;
        }

        .amenity-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1.125rem 1.5rem;
            background: var(--background-color);
            border-radius: 10px;
            border: 1px solid var(--border-color);
            font-weight: 500;
            font-size: 0.9375rem;
            color: var(--text-primary);
            transition: border-color 0.2s ease, background-color 0.2s ease;
        }

        .amenity-item:hover {
            background: #ffffff;
            border-color: var(--secondary-color);
        }

        .amenity-item i {
            color: var(--secondary-color);
            width: 20px;
            text-align: center;
            font-size: 1.125rem;
        }

        /* Elegant Description */
        .property-description {
            font-size: 1.0625rem;
            line-height: 2;
            color: var(--text-secondary);
            letter-spacing: -0.01em;
            text-align: justify;
            text-justify: inter-word;
        }

        /* Luxury Rental Details Card */
        .rental-details-card {
            background: linear-gradient(135deg, #fffbf5 0%, #fff9ef 100%);
            border: 1px solid #f59e0b;
            border-radius: 16px;
            padding: 2.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
        }

        .rental-details-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #d97706;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            letter-spacing: -0.01em;
        }

        .rental-details-title i {
            font-size: 1.5rem;
        }

        .rental-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1.25rem;
        }

        .rental-info-item {
            background: white;
            padding: 1.5rem 1.25rem;
            border-radius: 12px;
            border: 1px solid rgba(245, 158, 11, 0.2);
            box-shadow: var(--shadow-sm);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            text-align: center;
        }

        .rental-info-item:hover {
            border-color: #f59e0b;
            box-shadow: var(--shadow-md);
        }

        .rental-info-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: #d97706;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.875rem;
        }

        .rental-info-value {
            font-size: 1.125rem;
            font-weight: 700;
            color: #92400e;
            letter-spacing: -0.01em;
        }


        /* Clean Sticky Action Panel */
        .action-panel {
            position: sticky;
            top: 20px;
            z-index: 10;
            max-height: calc(100vh - 40px);
            overflow-y: auto;
            overflow-x: hidden;
            padding-bottom: 20px;
        }
        
        /* Custom scrollbar for action panel */
        .action-panel::-webkit-scrollbar {
            width: 6px;
        }
        
        .action-panel::-webkit-scrollbar-track {
            background: transparent;
        }
        
        .action-panel::-webkit-scrollbar-thumb {
            background: var(--border-color);
            border-radius: 10px;
        }
        
        .action-panel::-webkit-scrollbar-thumb:hover {
            background: var(--text-muted);
        }

        .action-card {
            background: var(--card-bg-color);
            border-radius: 16px;
            padding: 2.5rem;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border-color);
            margin-bottom: 1.5rem;
        }
        
        /* Highlight admin actions card */
        .admin-actions-card {
            background: linear-gradient(135deg, #ffffff 0%, #fafafa 100%);
            border: 2px solid var(--secondary-color);
            box-shadow: 0 8px 24px rgba(188, 158, 66, 0.15);
        }
        
        .admin-actions-card .section-title {
            color: var(--primary-color);
            font-size: 1.125rem;
            margin-bottom: 1.5rem;
        }
        
        .admin-actions-card .section-title i {
            color: var(--secondary-color);
        }

        /* Elegant Agent Card */
        .agent-card {
            text-align: center;
            padding: 2.5rem 2rem;
            background: var(--background-color);
            border-radius: 12px;
            border: 1px solid var(--border-color);
        }

        .agent-avatar {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            object-fit: cover;
            margin: 0 auto 1.5rem;
            border: 3px solid var(--secondary-color);
            box-shadow: var(--shadow);
        }

        .agent-name {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
            letter-spacing: -0.01em;
        }

        .agent-title {
            font-size: 0.9375rem;
            color: var(--text-secondary);
            font-weight: 500;
        }

        /* Luxury Buttons */
        .btn-modern {
            font-weight: 600;
            padding: 1.125rem 2.5rem;
            border-radius: 12px;
            border: none;
            transition: all 0.3s ease;
            text-transform: capitalize;
            letter-spacing: 0.01em;
            font-size: 1rem;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        /* Admin action buttons - larger and more prominent */
        .admin-actions-card .btn-modern {
            padding: 1.25rem 2rem;
            font-size: 1.0625rem;
            font-weight: 700;
        }

        .btn-approve {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
        }

        .btn-approve:hover {
            box-shadow: 0 6px 16px rgba(16, 185, 129, 0.35);
            color: white;
        }

        .btn-reject {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.25);
        }

        .btn-reject:hover {
            box-shadow: 0 6px 16px rgba(239, 68, 68, 0.35);
            color: white;
        }

        .btn-secondary-modern {
            background: var(--background-color);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .btn-secondary-modern:hover {
            background: #ffffff;
            border-color: var(--secondary-color);
            color: var(--text-primary);
        }

        .btn-update {
            background: linear-gradient(135deg, var(--secondary-color) 0%, #a78a3a 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(188, 158, 66, 0.25);
        }

        .btn-update:hover {
            box-shadow: 0 6px 16px rgba(188, 158, 66, 0.35);
            color: white;
        }


        /* Clean Price History Table */
        .price-history-table {
            background: var(--card-bg-color);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
        }

        .price-history-table table {
            margin: 0;
        }

        .price-history-table th {
            background: var(--background-color);
            font-weight: 600;
            color: var(--text-primary);
            padding: 1.25rem 1.75rem;
            border: none;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .price-history-table td {
            padding: 1.25rem 1.75rem;
            border: none;
            border-bottom: 1px solid var(--border-color);
            font-weight: 500;
            color: var(--text-secondary);
        }

        .price-history-table tr:last-child td {
            border-bottom: none;
        }

        .price-history-table tr:hover {
            background: var(--background-color);
        }

        /* Clean Chart Container */
        .chart-container {
            position: relative;
            height: 350px;
            background: var(--card-bg-color);
            border-radius: 12px;
            padding: 2rem;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
        }

        .chart-filters {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        .chart-filter-btn {
            padding: 0.625rem 1.25rem;
            border: 1px solid var(--border-color);
            background: var(--background-color);
            color: var(--text-primary);
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 500;
            transition: background-color 0.2s ease, border-color 0.2s ease, color 0.2s ease;
            cursor: pointer;
        }

        .chart-filter-btn.active,
        .chart-filter-btn:hover {
            background: var(--secondary-color);
            color: white;
            border-color: var(--secondary-color);
        }


        /* Responsive Design - Mobile Optimized */
        @media (max-width: 768px) {
            .property-price { font-size: 2.25rem; }
            .property-address { font-size: 1.125rem; }
            .property-specs { 
                flex-direction: column; 
                gap: 0.75rem; 
                align-items: flex-start;
            }
            .hero-thumbnails { display: none; }
            .content-section { padding: 2rem; }
            .facts-grid { grid-template-columns: 1fr; gap: 1.25rem; }
            .property-content { padding: 2.5rem 0; }
            .action-card { padding: 2rem; }
            .agent-card { padding: 2rem 1.5rem; }
        }

        /* Clean Alert Styles */
        .alert {
            border: none;
            border-radius: 12px;
            padding: 1.25rem 1.75rem;
            margin-bottom: 2.5rem;
            border-left: 4px solid;
        }

        .alert-success {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.08) 0%, rgba(16, 185, 129, 0.03) 100%);
            color: #065f46;
            border-left-color: var(--success-color);
        }

        .alert-danger {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.08) 0%, rgba(239, 68, 68, 0.03) 100%);
            color: #991b1b;
            border-left-color: var(--danger-color);
        }

        /* Minimal Modal Improvements */
        .modal-content {
            border: none;
            border-radius: 16px;
            box-shadow: var(--shadow-lg);
        }

        .modal-header {
            background: var(--primary-color);
            color: white;
            border-radius: 16px 16px 0 0;
            padding: 1.75rem 2.25rem;
            border-bottom: none;
        }

        .modal-body {
            padding: 2.5rem;
        }

        .modal-footer {
            padding: 1.75rem 2.25rem;
            border-top: 1px solid var(--border-color);
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: var(--background-color);
        }

        ::-webkit-scrollbar-thumb {
            background: var(--secondary-color);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #a78a3a;
        }

        /* Floor Selector Pills */
        .floor-selector {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: var(--background-color);
            border-radius: 12px;
            border: 1px solid var(--border-color);
        }

        .floor-pill {
            padding: 0.625rem 1.25rem;
            border: 1px solid var(--border-color);
            background: white;
            color: var(--text-primary);
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .floor-pill:hover {
            background: var(--background-color);
            border-color: var(--secondary-color);
        }

        .floor-pill.active {
            background: var(--secondary-color);
            color: white;
            border-color: var(--secondary-color);
        }

        .floor-pill i {
            font-size: 1rem;
        }

        /* Enhanced Hero Thumbnails */
        .hero-thumbnails {
            position: absolute;
            bottom: 1.5rem;
            right: 1.5rem;
            z-index: 4;
            display: flex;
            gap: 0.5rem;
            max-width: 400px;
            overflow-x: auto;
            padding: 0.625rem;
            background: rgba(0, 0, 0, 0.4);
            border-radius: 12px;
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .hero-thumbnail {
            width: 70px;
            height: 50px;
            object-fit: cover;
            border-radius: 8px;
            cursor: pointer;
            border: 2px solid transparent;
            transition: border-color 0.2s ease, opacity 0.2s ease;
            flex-shrink: 0;
            opacity: 0.7;
        }

        .hero-thumbnail:hover,
        .hero-thumbnail.active {
            border-color: var(--secondary-color);
            opacity: 1;
        }

        /* Gallery Button */
        .gallery-trigger-btn {
            position: absolute;
            bottom: 7rem;
            right: 1.5rem;
            z-index: 4;
            background: rgba(0, 0, 0, 0.6);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 0.875rem 1.5rem;
            border-radius: 9999px;
            font-weight: 600;
            font-size: 0.9375rem;
            cursor: pointer;
            backdrop-filter: blur(12px);
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }

        .gallery-trigger-btn:hover {
            background: rgba(0, 0, 0, 0.8);
            border-color: var(--secondary-color);
            transform: translateY(-2px);
        }

        /* Full-Screen Gallery Modal */
        .gallery-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.97);
            z-index: 9999;
            overflow: hidden;
        }

        .gallery-modal.active {
            display: flex;
            flex-direction: column;
        }

        .gallery-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem 2rem;
            background: rgba(0, 0, 0, 0.9);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .gallery-title {
            color: white;
            font-size: 1.25rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .gallery-close-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.2);
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 1.5rem;
        }

        .gallery-close-btn:hover {
            background: var(--danger-color);
            border-color: var(--danger-color);
            transform: rotate(90deg);
        }

        .gallery-type-selector {
            display: flex;
            gap: 1rem;
            padding: 1.5rem 2rem;
            background: rgba(0, 0, 0, 0.8);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .gallery-type-btn {
            padding: 0.75rem 1.75rem;
            background: rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 9999px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }

        .gallery-type-btn:hover {
            background: rgba(255, 255, 255, 0.15);
            color: white;
        }

        .gallery-type-btn.active {
            background: var(--secondary-color);
            color: white;
            border-color: var(--secondary-color);
        }

        .gallery-floor-selector {
            display: flex;
            gap: 0.625rem;
            padding: 1.5rem 2rem;
            background: rgba(0, 0, 0, 0.8);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            overflow-x: auto;
        }

        .gallery-floor-btn {
            padding: 0.625rem 1.25rem;
            background: rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 9999px;
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }

        .gallery-floor-btn:hover {
            background: rgba(255, 255, 255, 0.15);
            color: white;
        }

        .gallery-floor-btn.active {
            background: var(--secondary-color);
            color: white;
            border-color: var(--secondary-color);
        }

        .gallery-content {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            position: relative;
            overflow: hidden;
        }

        .gallery-main-image {
            max-width: 90%;
            max-height: 90%;
            object-fit: contain;
            border-radius: 8px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
        }

        .gallery-nav-btn {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
            width: 56px;
            height: 56px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 1.5rem;
            backdrop-filter: blur(8px);
        }

        .gallery-nav-btn:hover {
            background: var(--secondary-color);
            border-color: var(--secondary-color);
            transform: translateY(-50%) scale(1.1);
        }

        .gallery-nav-btn.prev {
            left: 2rem;
        }

        .gallery-nav-btn.next {
            right: 2rem;
        }

        .gallery-counter {
            position: absolute;
            bottom: 2rem;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 9999px;
            font-weight: 600;
            border: 1px solid rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(8px);
        }

        .gallery-thumbnails {
            padding: 1.5rem 2rem;
            background: rgba(0, 0, 0, 0.9);
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            overflow-x: auto;
        }

        .gallery-thumb-grid {
            display: flex;
            gap: 0.75rem;
            min-width: min-content;
        }

        .gallery-thumb {
            width: 100px;
            height: 70px;
            object-fit: cover;
            border-radius: 8px;
            cursor: pointer;
            border: 2px solid transparent;
            transition: all 0.2s ease;
            opacity: 0.6;
            flex-shrink: 0;
        }

        .gallery-thumb:hover,
        .gallery-thumb.active {
            border-color: var(--secondary-color);
            opacity: 1;
            transform: scale(1.05);
        }

        /* Responsive Gallery */
        @media (max-width: 768px) {
            .gallery-header {
                padding: 1rem 1.5rem;
            }

            .gallery-title {
                font-size: 1rem;
            }

            .gallery-type-selector,
            .gallery-floor-selector {
                padding: 1rem 1.5rem;
            }

            .gallery-nav-btn {
                width: 44px;
                height: 44px;
                font-size: 1.25rem;
            }

            .gallery-nav-btn.prev {
                left: 1rem;
            }

            .gallery-nav-btn.next {
                right: 1rem;
            }

            .gallery-content {
                padding: 1rem;
            }

            .gallery-main-image {
                max-width: 95%;
                max-height: 95%;
            }
        }

        /* Error State */
        .error-state {
            text-align: center;
            padding: 5rem 2rem;
            background: var(--card-bg-color);
            border-radius: 16px;
            margin: 3rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
        }

        .error-icon {
            font-size: 4rem;
            color: var(--secondary-color);
            margin-bottom: 1.5rem;
        }

        /* Detail Row Styling */
        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.25rem 0;
            border-bottom: 1px solid var(--border-color);
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: var(--text-secondary);
            font-size: 0.875rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }

        .detail-label i {
            color: var(--text-muted);
            font-size: 1rem;
        }

        .detail-value {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.9375rem;
        }

        /* Form Controls */
        .form-control:focus {
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(188, 158, 66, 0.1);
        }

        .form-label {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.9375rem;
            margin-bottom: 0.5rem;
        }
        
        /* Mobile Floating Action Button */
        .mobile-actions-fab {
            display: none;
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 999;
        }
        
        .fab-button {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--secondary-color) 0%, #a78a3a 100%);
            color: white;
            border: none;
            box-shadow: 0 4px 20px rgba(188, 158, 66, 0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .fab-button:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 30px rgba(188, 158, 66, 0.5);
        }
        
        .fab-menu {
            position: absolute;
            bottom: 75px;
            right: 0;
            background: white;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
            padding: 1rem;
            min-width: 280px;
            display: none;
            border: 1px solid var(--border-color);
        }
        
        .fab-menu.active {
            display: block;
            animation: slideUpFade 0.3s ease;
        }
        
        @keyframes slideUpFade {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .fab-menu-title {
            font-size: 0.875rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }
        
        .fab-menu-actions {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        
        .fab-menu-actions .btn {
            justify-content: flex-start;
            text-align: left;
        }
        
        /* Responsive Adjustments */
        @media (max-width: 991px) {
            .action-panel {
                position: relative;
                top: 0;
                margin-top: 2rem;
                max-height: none;
                overflow-y: visible;
            }
            
            .mobile-actions-fab {
                display: block;
            }
            
            /* Hide sidebar actions on mobile, show in FAB instead */
            .action-panel-mobile-hide {
                display: none;
            }
        }
        
        @media (min-width: 992px) {
            .mobile-actions-fab {
                display: none;
            }
        }

    </style>
</head>
<body>

<!-- Include Modern Sidebar and Navbar -->
<?php 
$active_page = 'property.php';
include 'admin_sidebar.php'; 
include 'admin_navbar.php'; 
?>

<div class="admin-content">
    <?php if ($error_message): ?>
        <div class="container-fluid px-4 pt-4">
            <div class="alert alert-danger" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error_message); ?>
            </div>
        </div>
    <?php endif; ?>
    
    <?php if ($success_message): ?>
        <div class="container-fluid px-4 pt-4">
            <div class="alert alert-success" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($success_message); ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($property_data): ?>
        <?php
            $full_address = htmlspecialchars($property_data['StreetAddress'] . ', ' . $property_data['City']);
            $status_class = 'badge-' . strtolower($property_data['approval_status']);
            $is_approved = $property_data['approval_status'] === 'approved';
            $is_admin_poster = ($agent_info && $agent_info['account_id'] == $admin_account_id);
        ?>
        
        <!-- Hero Section -->
        <div class="property-hero">
            <img src="<?php echo htmlspecialchars($property_images[0] ?? 'https://via.placeholder.com/1200x600?text=No+Image'); ?>" 
                 alt="Main property view" id="mainHeroImage" class="hero-image">
            <div class="hero-overlay"></div>
            
            <!-- Property Status Badge (For Sale / For Rent) -->
            <?php
                $status_value = $property_data['Status'] ?? 'For Sale';
                $status_badge_class = ($status_value === 'For Rent') ? 'status-for-rent' : 'status-for-sale';
                $status_icon = ($status_value === 'For Rent') ? 'bi-key-fill' : 'bi-tag-fill';
            ?>
            <div class="property-status-badge <?php echo $status_badge_class; ?>">
                <i class="bi <?php echo $status_icon; ?> me-2"></i><?php echo htmlspecialchars($status_value); ?>
            </div>
            
            <!-- Approval Status Badge -->
            <div class="hero-status-badge <?php echo $status_class; ?>" style="right: 1.5rem; left: auto;">
                <?php echo ucfirst($property_data['approval_status']); ?>
            </div>
            
            <!-- View Gallery Button -->
            <button class="gallery-trigger-btn" onclick="openGallery()">
                <i class="bi bi-grid-3x3-gap-fill"></i>
                <span>View All Photos</span>
            </button>
            
            <!-- Floor Selector (if floor images exist) -->
            <?php if (!empty($floor_images)): ?>
            <div class="floor-selector" style="position: absolute; top: 1.5rem; left: 50%; transform: translateX(-50%); z-index: 4; max-width: 80%;">
                <button class="floor-pill active" data-type="featured" onclick="switchHeroView('featured')">
                    <i class="bi bi-image"></i>
                    Featured
                </button>
                <?php foreach ($floor_images as $floor_num => $images): ?>
                    <button class="floor-pill" data-type="floor" data-floor="<?php echo $floor_num; ?>" 
                            onclick="switchHeroView('floor', <?php echo $floor_num; ?>)">
                        <i class="bi bi-building"></i>
                        Floor <?php echo $floor_num; ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <!-- Thumbnails -->
            <div class="hero-thumbnails" id="heroThumbnails">
                <?php 
                $hero_images = $property_images; // Default to featured images
                foreach ($hero_images as $index => $img_url): 
                ?>
                    <img src="<?php echo htmlspecialchars($img_url); ?>" 
                         alt="Thumbnail <?php echo $index + 1; ?>" 
                         class="hero-thumbnail <?php echo $index === 0 ? 'active' : ''; ?>" 
                         onclick="changeHeroImage('<?php echo htmlspecialchars($img_url); ?>', this)">
                <?php endforeach; ?>
            </div>
            
            <!-- Hero Content -->
            <div class="hero-content">
                <div class="container-fluid px-4">
                    <div class="row">
                        <div class="col-lg-8">
                            <h1 class="property-price">
                                ₱<?php echo number_format($property_data['ListingPrice']); ?>
                                <?php if ($status_value === 'For Rent'): ?>
                                    <span style="font-size: 1.5rem; font-weight: 400; color: rgba(255,255,255,0.8);">/ month</span>
                                <?php endif; ?>
                            </h1>
                            <p class="property-address">
                                <i class="bi bi-geo-alt me-2"></i><?php echo $full_address; ?>
                            </p>
                            <div class="property-specs">
                                <span><i class="bi bi-house me-2"></i><?php echo $property_data['Bedrooms']; ?> beds</span>
                                <span><i class="bi bi-droplet me-2"></i><?php echo $property_data['Bathrooms']; ?> baths</span>
                                <?php if (!empty($property_data['SquareFootage'])): ?>
                                <span><i class="bi bi-rulers me-2"></i><?php echo number_format($property_data['SquareFootage']); ?> sqft</span>
                                <?php endif; ?>
                                <span><i class="bi bi-calendar me-2"></i><?php echo ($days_on_market !== null ? $days_on_market : '—'); ?> days on market</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Rental Details Section (shown only for For Rent properties) -->
        <?php if ($status_value === 'For Rent' && $rental_data): ?>
        <div class="container-fluid px-4 mt-4">
            <div class="rental-details-card">
                <h3 class="rental-details-title">
                    <i class="bi bi-key-fill"></i>
                    Rental Information
                </h3>
                <div class="rental-info-grid">
                    <div class="rental-info-item">
                        <div class="rental-info-label">Monthly Rent</div>
                        <div class="rental-info-value">₱<?php echo number_format($rental_data['monthly_rent'] ?? $property_data['ListingPrice'], 2); ?></div>
                    </div>
                    <div class="rental-info-item">
                        <div class="rental-info-label">Security Deposit</div>
                        <div class="rental-info-value">₱<?php echo number_format($rental_data['security_deposit'] ?? 0, 2); ?></div>
                    </div>
                    <div class="rental-info-item">
                        <div class="rental-info-label">Lease Term</div>
                        <div class="rental-info-value">
                            <?php echo htmlspecialchars($rental_data['lease_term_months'] ?? 'N/A'); ?> 
                            <?php if (!empty($rental_data['lease_term_months'])): ?>
                                <span style="font-size: 0.875rem; font-weight: 500;">months</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="rental-info-item">
                        <div class="rental-info-label">Furnishing</div>
                        <div class="rental-info-value">
                            <span class="rental-badge"><?php echo htmlspecialchars($rental_data['furnishing'] ?? 'Not Specified'); ?></span>
                        </div>
                    </div>
                    <div class="rental-info-item">
                        <div class="rental-info-label">Available From</div>
                        <div class="rental-info-value">
                            <?php 
                                if (!empty($rental_data['available_from'])) {
                                    echo date('M d, Y', strtotime($rental_data['available_from']));
                                } else {
                                    echo 'Immediate';
                                }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Main Content -->
        <div class="property-content">
            <div class="container-fluid px-4">
                <div class="row">
                    <div class="col-lg-8">
                        
                        <!-- Key Facts -->
                        <div class="content-section">
                            <h2 class="section-title">
                                <i class="bi bi-info-circle-fill"></i>
                                Property Overview
                            </h2>
                            <div class="facts-grid">
                                <div class="fact-item">
                                    <div class="fact-label">
                                        <i class="bi bi-house-door"></i>
                                        Property Type
                                    </div>
                                    <div class="fact-value"><?php echo htmlspecialchars($property_data['PropertyType']); ?></div>
                                </div>
                                <div class="fact-item">
                                    <div class="fact-label">
                                        <i class="bi bi-calendar-event"></i>
                                        Year Built
                                    </div>
                                    <div class="fact-value"><?php echo htmlspecialchars($property_data['YearBuilt'] ?? 'N/A'); ?></div>
                                </div>
                                <div class="fact-item">
                                    <div class="fact-label">
                                        <i class="bi bi-calculator"></i>
                                        Price/SqFt
                                    </div>
                                    <div class="fact-value highlight"><?php echo $price_per_sqft; ?></div>
                                </div>
                                <div class="fact-item">
                                    <div class="fact-label">
                                        <i class="bi bi-p-square"></i>
                                        Parking
                                    </div>
                                    <div class="fact-value"><?php echo htmlspecialchars($property_data['ParkingType'] ?? 'N/A'); ?></div>
                                </div>
                                <div class="fact-item">
                                    <div class="fact-label">
                                        <i class="bi bi-tag"></i>
                                        Listing Status
                                    </div>
                                    <div class="fact-value">
                                        <?php 
                                            $status_display = htmlspecialchars($property_data['Status']);
                                            echo $status_display;
                                        ?>
                                    </div>
                                </div>
                                <div class="fact-item">
                                    <div class="fact-label">
                                        <i class="bi bi-clock-history"></i>
                                        Listed Date
                                    </div>
                                    <div class="fact-value">
                                        <?php 
                                            // Handle invalid dates (0000-00-00)
                                            $listing_date = $property_data['ListingDate'];
                                            if (!empty($listing_date) && $listing_date !== '0000-00-00') {
                                                $timestamp = strtotime($listing_date);
                                                if ($timestamp !== false) {
                                                    echo date('M d, Y', $timestamp);
                                                } else {
                                                    echo '<span style="color: var(--text-muted);">Not set</span>';
                                                }
                                            } else {
                                                echo '<span style="color: var(--text-muted);">Not set</span>';
                                            }
                                        ?>
                                        <br><small><?php echo ($days_on_market !== null ? $days_on_market . ' days ago' : '—'); ?></small>
                                    </div>
                                </div>
                                <?php if (!empty($property_data['SquareFootage'])): ?>
                                <div class="fact-item">
                                    <div class="fact-label">
                                        <i class="bi bi-rulers"></i>
                                        Square Footage
                                    </div>
                                    <div class="fact-value"><?php echo number_format($property_data['SquareFootage']); ?> <small>sqft</small></div>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($property_data['LotSize'])): ?>
                                <div class="fact-item">
                                    <div class="fact-label">
                                        <i class="bi bi-bounding-box"></i>
                                        Lot Size
                                    </div>
                                    <div class="fact-value"><?php echo number_format($property_data['LotSize'], 2); ?> <small>acres</small></div>
                                </div>
                                <?php endif; ?>
                                <div class="fact-item">
                                    <div class="fact-label">
                                        <i class="bi bi-geo-alt"></i>
                                        County
                                    </div>
                                    <div class="fact-value"><?php echo htmlspecialchars($property_data['County'] ?? 'N/A'); ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Description -->
                        <div class="content-section">
                            <h2 class="section-title">
                                <i class="bi bi-card-text"></i>
                                Property Description
                            </h2>
                            <div class="property-description">
                                <?php echo nl2br(htmlspecialchars($property_data['ListingDescription'] ?? 'No description provided.')); ?>
                            </div>
                        </div>

                        <!-- Amenities -->
                        <div class="content-section">
                            <h2 class="section-title">
                                <i class="bi bi-star"></i>
                                Amenities & Features
                            </h2>
                            <?php if (!empty($amenities)): ?>
                                <div class="amenities-grid">
                                    <?php foreach ($amenities as $amenity): ?>
                                        <div class="amenity-item">
                                            <i class="bi bi-check-circle"></i>
                                            <?php echo htmlspecialchars($amenity); ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted">No amenities were listed for this property.</p>
                            <?php endif; ?>
                        </div>

                        <!-- Price History -->
                        <div class="content-section">
                            <h2 class="section-title">
                                <i class="bi bi-graph-up-arrow"></i>
                                Price History
                            </h2>
                            <?php if (!empty($price_history)): ?>
                                <div class="price-history-table">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Event</th>
                                                <th>Price</th>
                                                <th>Change</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach (array_slice($price_history, 0, 6) as $event): ?>
                                                <tr>
                                                    <td><?php echo $event['event_date']; ?></td>
                                                    <td>
                                                        <span class="badge bg-light text-dark">
                                                            <?php echo $event['event_type']; ?>
                                                        </span>
                                                    </td>
                                                    <td><strong><?php echo $event['price']; ?></strong></td>
                                                    <td>
                                                        <?php if ($event['change_percentage'] !== null): ?>
                                                            <span class="percentage-change <?php echo $event['change_class']; ?>">
                                                                <i class="bi bi-<?php echo $event['change_percentage'] > 0 ? 'arrow-up' : 'arrow-down'; ?>"></i>
                                                                <?php echo abs($event['change_percentage']); ?>%
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            
                                            <?php if (count($price_history) > 6): ?>
                                                <?php foreach (array_slice($price_history, 6) as $event): ?>
                                                    <tr class="extra-history-row d-none">
                                                        <td><?php echo $event['event_date']; ?></td>
                                                        <td>
                                                            <span class="badge bg-light text-dark">
                                                                <?php echo $event['event_type']; ?>
                                                            </span>
                                                        </td>
                                                        <td><strong><?php echo $event['price']; ?></strong></td>
                                                        <td>
                                                            <?php if ($event['change_percentage'] !== null): ?>
                                                                <span class="percentage-change <?php echo $event['change_class']; ?>">
                                                                    <i class="bi bi-<?php echo $event['change_percentage'] > 0 ? 'arrow-up' : 'arrow-down'; ?>"></i>
                                                                    <?php echo abs($event['change_percentage']); ?>%
                                                                </span>
                                                            <?php else: ?>
                                                                <span class="text-muted">-</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <?php if (count($price_history) > 6): ?>
                                    <div class="text-center mt-3">
                                        <button id="showMoreHistoryBtn" class="btn btn-outline-secondary">
                                            <i class="bi bi-chevron-down"></i> Show More History
                                        </button>
                                        <button id="showLessHistoryBtn" class="btn btn-outline-secondary d-none">
                                            <i class="bi bi-chevron-up"></i> Show Less
                                        </button>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <p class="text-muted">No price history available for this property.</p>
                            <?php endif; ?>
                        </div>

                        <!-- Price Chart -->
                        <div class="content-section">
                            <h2 class="section-title">
                                <i class="bi bi-bar-chart-line"></i>
                                Price Trend Analysis
                            </h2>
                            <div class="chart-filters">
                                <button class="chart-filter-btn active" data-period="all">All Time</button>
                                <button class="chart-filter-btn" data-period="yearly">1 Year</button>
                                <button class="chart-filter-btn" data-period="monthly">6 Months</button>
                                <button class="chart-filter-btn" data-period="weekly">30 Days</button>
                                <button class="chart-filter-btn" data-period="custom">Custom</button>
                            </div>
                            <div id="customDateRange" class="row g-2 mb-3 d-none">
                                <div class="col-md-4">
                                    <label class="form-label">Start Date</label>
                                    <input type="date" id="startDate" class="form-control">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">End Date</label>
                                    <input type="date" id="endDate" class="form-control">
                                </div>
                            </div>
                            <div class="chart-container">
                                <canvas id="priceHistoryChart"></canvas>
                            </div>
                        </div>

                    </div>
                    
                    <div class="col-lg-4">
                        <div class="action-panel">
                            
                            <!-- Admin Actions (Moved to Top) -->
                            <div class="action-card admin-actions-card">
                                <h3 class="section-title">
                                    <i class="bi bi-shield-check"></i>
                                    Admin Actions
                                </h3>
                                
                                <?php if (!$is_approved): ?>
                                    <form id="adminActionForm" action="view_property.php?id=<?php echo $property_id_to_review; ?>" method="POST">
                                        <input type="hidden" name="property_id" value="<?php echo $property_id_to_review; ?>">
                                        <input type="hidden" name="client_timestamp" id="clientTimestamp">
                                        <input type="hidden" name="reject_reason" id="rejectReasonInput">
                                        <div class="d-grid gap-3">
                                            <button type="submit" name="action" value="approve" class="btn btn-modern btn-approve">
                                                <i class="bi bi-check-circle me-2"></i>Approve Listing
                                            </button>
                                            <button type="button" id="openRejectModalBtn" class="btn btn-modern btn-reject">
                                                <i class="bi bi-x-circle me-2"></i>Reject Listing
                                            </button>
                                        </div>
                                    </form>
                                <?php elseif ($is_admin_poster): ?>
                                    <div class="d-grid gap-3">
                                        <button type="button" class="btn btn-modern btn-update" data-bs-toggle="modal" data-bs-target="#updatePriceModal">
                                            <i class="bi bi-tag me-2"></i>Update Price
                                        </button>
                                        <button type="button" class="btn btn-modern btn-secondary-modern" onclick="openEditPropertyModal(<?php echo $property_id_to_review; ?>)">
                                            <i class="bi bi-pencil me-2"></i>Edit Details
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-info mb-0">
                                        <i class="bi bi-info-circle me-2"></i>
                                        This listing is approved. Only the posting administrator can make direct updates.
                                    </div>
                                <?php endif; ?>
                                
                                <div class="d-grid mt-4">
                                    <a href="property.php" class="btn btn-modern btn-secondary-modern">
                                        <i class="bi bi-arrow-left me-2"></i>Back to Properties
                                    </a>
                                </div>
                            </div>
                            
                            <!-- Agent Info (Moved Below Admin Actions) -->
                            <?php if ($agent_info): ?>
                            <div class="action-card">
                                <h3 class="section-title">
                                    <i class="bi bi-person-badge"></i>
                                    Listed By
                                </h3>
                                <div class="agent-card">
                                            <?php
                                            $listed_by_avatar = 'https://via.placeholder.com/80?text=N/A';
                                            if (!empty($agent_info['admin_profile_picture_url'])) {
                                                $listed_by_avatar = $agent_info['admin_profile_picture_url'];
                                            } elseif (!empty($agent_info['agent_profile_picture_url'])) {
                                                $listed_by_avatar = $agent_info['agent_profile_picture_url'];
                                            } elseif (!empty($agent_info['profile_picture_url'])) {
                                                // backward compatible field
                                                $listed_by_avatar = $agent_info['profile_picture_url'];
                                            }
                                            ?>
                                            <img src="<?php echo htmlspecialchars($listed_by_avatar); ?>" 
                                                 alt="Agent" class="agent-avatar">
                                    <div class="agent-name"><?php echo htmlspecialchars(trim($agent_info['first_name'] . ' ' . $agent_info['last_name'])); ?></div>
                                    <div class="agent-title">HomeEstate Realty</div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <?php else: ?>
        <div class="error-state">
            <div class="error-icon">
                <i class="bi bi-house-exclamation"></i>
            </div>
            <h2>Property Not Found</h2>
            <p class="text-muted mb-4">The property you are trying to view does not exist or is no longer available.</p>
            <a href="property.php" class="btn btn-modern btn-secondary-modern">
                <i class="bi bi-arrow-left me-2"></i>Back to Properties
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- Update Price Modal -->
<div class="modal fade" id="updatePriceModal" tabindex="-1" aria-labelledby="updatePriceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="view_property.php?id=<?php echo $property_id_to_review; ?>" method="POST" id="updatePriceForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="updatePriceModalLabel">
                        <i class="bi bi-tag me-2"></i>Update Listing Price
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_price">
                    <input type="hidden" name="property_id" value="<?php echo $property_id_to_review; ?>">
                    <input type="hidden" name="client_timestamp" id="clientTimestampInput">
                    
                    <div class="mb-4">
                        <label class="form-label">Current Information</label>
                        <div class="row text-center">
                            <div class="col-6">
                                <div class="p-3 bg-light rounded">
                                    <div class="h4 text-primary mb-1">₱<?php echo number_format($property_data['ListingPrice'] ?? 0); ?></div>
                                    <small class="text-muted">Current Price</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-3 bg-light rounded">
                                    <div class="h4 text-primary mb-1"><?php echo $price_per_sqft; ?></div>
                                    <small class="text-muted">Price/SqFt</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="new_price" class="form-label">New Price (PHP) *</label>
                        <div class="input-group">
                            <span class="input-group-text">₱</span>
                            <input type="number" class="form-control" id="new_price" name="new_price" 
                                   step="0.01" min="1" 
                                   data-sqft="<?php echo htmlspecialchars($property_data['SquareFootage'] ?? 0); ?>" 
                                   required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">New Price/SqFt (Calculated)</label>
                        <div id="newPricePerSqFtDisplay" class="p-2 bg-success-subtle text-success rounded">---</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-modern btn-update">Update Price</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Reason Modal -->
<div class="modal fade" id="rejectReasonModal" tabindex="-1" aria-labelledby="rejectReasonModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="rejectReasonModalLabel"><i class="bi bi-chat-left-text me-2"></i>Provide Rejection Reason</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label for="rejectReasonTextarea" class="form-label fw-semibold">Reason for Rejection</label>
                <textarea id="rejectReasonTextarea" class="form-control" rows="4" placeholder="Explain why this listing is being rejected..." required></textarea>
                <div id="rejectReasonError" class="text-danger mt-2" style="display:none">Please enter a reason.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="confirmRejectBtn" class="btn btn-danger"><i class="bi bi-x-circle me-2"></i>Reject Listing</button>
            </div>
        </div>
    </div>
    </div>

<!-- Full-Screen Gallery Modal -->
<div class="gallery-modal" id="galleryModal">
    <!-- Gallery Header -->
    <div class="gallery-header">
        <h2 class="gallery-title">
            <i class="bi bi-images"></i>
            <span id="galleryTitleText">Property Gallery</span>
        </h2>
        <button class="gallery-close-btn" onclick="closeGallery()" title="Close Gallery">
            <i class="bi bi-x"></i>
        </button>
    </div>
    
    <!-- Gallery Type Selector -->
    <div class="gallery-type-selector">
        <button class="gallery-type-btn active" data-gallery-type="featured" onclick="switchGalleryType('featured')">
            <i class="bi bi-star-fill"></i>
            Featured Images
        </button>
        <?php if (!empty($floor_images)): ?>
        <button class="gallery-type-btn" data-gallery-type="floors" onclick="switchGalleryType('floors')">
            <i class="bi bi-building"></i>
            Floor Images
        </button>
        <?php endif; ?>
    </div>
    
    <!-- Floor Selector for Floor Images (hidden by default) -->
    <?php if (!empty($floor_images)): ?>
    <div class="gallery-floor-selector" id="galleryFloorSelector" style="display: none;">
        <?php foreach ($floor_images as $floor_num => $images): ?>
            <button class="gallery-floor-btn <?php echo $floor_num === array_key_first($floor_images) ? 'active' : ''; ?>" 
                    data-floor="<?php echo $floor_num; ?>" 
                    onclick="switchGalleryFloor(<?php echo $floor_num; ?>)">
                Floor <?php echo $floor_num; ?>
            </button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <!-- Gallery Content (Main Image) -->
    <div class="gallery-content">
        <button class="gallery-nav-btn prev" onclick="navigateGallery(-1)">
            <i class="bi bi-chevron-left"></i>
        </button>
        
        <img src="" alt="Gallery image" id="galleryMainImage" class="gallery-main-image">
        
        <button class="gallery-nav-btn next" onclick="navigateGallery(1)">
            <i class="bi bi-chevron-right"></i>
        </button>
        
        <div class="gallery-counter" id="galleryCounter">
            1 / 1
        </div>
    </div>
    
    <!-- Gallery Thumbnails -->
    <div class="gallery-thumbnails">
        <div class="gallery-thumb-grid" id="galleryThumbGrid">
            <!-- Thumbnails will be populated by JavaScript -->
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Property image data from PHP
    const featuredImages = <?php echo json_encode($property_images); ?>;
    const floorImages = <?php echo json_encode($floor_images); ?>;
    
    // Gallery state
    let currentGalleryType = 'featured';
    let currentFloor = <?php echo !empty($floor_images) ? array_key_first($floor_images) : 'null'; ?>;
    let currentImageIndex = 0;
    let currentImages = [];

    // Hero view state
    let currentHeroView = 'featured';
    let currentHeroFloor = null;

    // Switch hero view between featured and floor images
    function switchHeroView(viewType, floorNum = null) {
        const mainImage = document.getElementById('mainHeroImage');
        const thumbnailsContainer = document.getElementById('heroThumbnails');
        const pills = document.querySelectorAll('.floor-pill');
        
        // Update active pill
        pills.forEach(pill => {
            if (viewType === 'featured' && pill.dataset.type === 'featured') {
                pill.classList.add('active');
            } else if (viewType === 'floor' && pill.dataset.type === 'floor' && parseInt(pill.dataset.floor) === floorNum) {
                pill.classList.add('active');
            } else {
                pill.classList.remove('active');
            }
        });
        
        currentHeroView = viewType;
        currentHeroFloor = floorNum;
        
        let imagesToShow = [];
        if (viewType === 'featured') {
            imagesToShow = featuredImages;
        } else if (viewType === 'floor' && floorImages[floorNum]) {
            imagesToShow = floorImages[floorNum];
        }
        
        if (imagesToShow.length > 0) {
            // Update main image
            mainImage.src = imagesToShow[0];
            
            // Update thumbnails
            thumbnailsContainer.innerHTML = '';
            imagesToShow.forEach((img, index) => {
                const thumb = document.createElement('img');
                thumb.src = img;
                thumb.alt = `Thumbnail ${index + 1}`;
                thumb.className = `hero-thumbnail ${index === 0 ? 'active' : ''}`;
                thumb.onclick = function() {
                    changeHeroImage(img, this);
                };
                thumbnailsContainer.appendChild(thumb);
            });
        }
    }

    // Hero image functionality
    function changeHeroImage(imageUrl, thumbnailElement) {
        document.getElementById('mainHeroImage').src = imageUrl;
        
        document.querySelectorAll('.hero-thumbnail').forEach(thumb => {
            thumb.classList.remove('active');
        });
        thumbnailElement.classList.add('active');
    }

    // Open gallery
    function openGallery() {
        const modal = document.getElementById('galleryModal');
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        
        // Start with current hero view
        if (currentHeroView === 'featured') {
            switchGalleryType('featured');
        } else if (currentHeroView === 'floor') {
            switchGalleryType('floors');
            if (currentHeroFloor !== null) {
                switchGalleryFloor(currentHeroFloor);
            }
        }
    }

    // Close gallery
    function closeGallery() {
        const modal = document.getElementById('galleryModal');
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }

    // Switch gallery type (featured/floors)
    function switchGalleryType(type) {
        currentGalleryType = type;
        const buttons = document.querySelectorAll('.gallery-type-btn');
        const floorSelector = document.getElementById('galleryFloorSelector');
        
        buttons.forEach(btn => {
            if (btn.dataset.galleryType === type) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });
        
        if (type === 'featured') {
            if (floorSelector) floorSelector.style.display = 'none';
            currentImages = [...featuredImages];
            document.getElementById('galleryTitleText').textContent = 'Featured Images';
        } else if (type === 'floors') {
            if (floorSelector) floorSelector.style.display = 'flex';
            if (currentFloor && floorImages[currentFloor]) {
                currentImages = [...floorImages[currentFloor]];
                document.getElementById('galleryTitleText').textContent = `Floor ${currentFloor} Images`;
            }
        }
        
        currentImageIndex = 0;
        updateGalleryDisplay();
    }

    // Switch gallery floor
    function switchGalleryFloor(floorNum) {
        currentFloor = floorNum;
        const buttons = document.querySelectorAll('.gallery-floor-btn');
        
        buttons.forEach(btn => {
            if (parseInt(btn.dataset.floor) === floorNum) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });
        
        if (floorImages[floorNum]) {
            currentImages = [...floorImages[floorNum]];
            document.getElementById('galleryTitleText').textContent = `Floor ${floorNum} Images`;
            currentImageIndex = 0;
            updateGalleryDisplay();
        }
    }

    // Navigate gallery (prev/next)
    function navigateGallery(direction) {
        if (currentImages.length === 0) return;
        
        currentImageIndex += direction;
        
        if (currentImageIndex < 0) {
            currentImageIndex = currentImages.length - 1;
        } else if (currentImageIndex >= currentImages.length) {
            currentImageIndex = 0;
        }
        
        updateGalleryDisplay();
    }

    // Jump to specific image
    function jumpToImage(index) {
        currentImageIndex = index;
        updateGalleryDisplay();
    }

    // Update gallery display
    function updateGalleryDisplay() {
        if (currentImages.length === 0) return;
        
        const mainImage = document.getElementById('galleryMainImage');
        const counter = document.getElementById('galleryCounter');
        const thumbGrid = document.getElementById('galleryThumbGrid');
        
        // Update main image
        mainImage.src = currentImages[currentImageIndex];
        
        // Update counter
        counter.textContent = `${currentImageIndex + 1} / ${currentImages.length}`;
        
        // Update thumbnails
        thumbGrid.innerHTML = '';
        currentImages.forEach((img, index) => {
            const thumb = document.createElement('img');
            thumb.src = img;
            thumb.alt = `Thumbnail ${index + 1}`;
            thumb.className = `gallery-thumb ${index === currentImageIndex ? 'active' : ''}`;
            thumb.onclick = () => jumpToImage(index);
            thumbGrid.appendChild(thumb);
        });
        
        // Scroll active thumbnail into view
        setTimeout(() => {
            const activeThumb = thumbGrid.querySelector('.gallery-thumb.active');
            if (activeThumb) {
                activeThumb.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
            }
        }, 100);
    }

    // Keyboard navigation
    document.addEventListener('keydown', function(e) {
        const modal = document.getElementById('galleryModal');
        if (!modal.classList.contains('active')) return;
        
        if (e.key === 'ArrowLeft') {
            e.preventDefault();
            navigateGallery(-1);
        } else if (e.key === 'ArrowRight') {
            e.preventDefault();
            navigateGallery(1);
        } else if (e.key === 'Escape') {
            e.preventDefault();
            closeGallery();
        }
    });

    // Prevent gallery modal from closing when clicking on image
    document.getElementById('galleryMainImage')?.addEventListener('click', function(e) {
        e.stopPropagation();
    });

    // Close gallery when clicking outside content
    document.getElementById('galleryModal')?.addEventListener('click', function(e) {
        if (e.target === this) {
            closeGallery();
        }
    });
    // Hero image functionality
    function changeHeroImage(imageUrl, thumbnailElement) {
        document.getElementById('mainHeroImage').src = imageUrl;
        
        document.querySelectorAll('.hero-thumbnail').forEach(thumb => {
            thumb.classList.remove('active');
        });
        thumbnailElement.classList.add('active');
    }

    // Timestamp handling for forms
    document.addEventListener('DOMContentLoaded', function() {
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', function() {
                const timestampInput = form.querySelector('input[name="client_timestamp"]');
                if (timestampInput) {
                    timestampInput.value = new Date().toISOString();
                }
            });
        });
    });

    // Price calculation in modal
    const newPriceInput = document.getElementById('new_price');
    const priceDisplay = document.getElementById('newPricePerSqFtDisplay');
    
    if (newPriceInput && priceDisplay) {
        newPriceInput.addEventListener('input', function() {
            const newPrice = parseFloat(this.value);
            const sqft = parseFloat(this.dataset.sqft);

            if (newPrice > 0 && sqft > 0) {
                const pricePerSqft = newPrice / sqft;
                priceDisplay.textContent = '₱' + pricePerSqft.toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            } else {
                priceDisplay.textContent = '---';
            }
        });
    }

    // Price history show more/less
    const showMoreBtn = document.getElementById('showMoreHistoryBtn');
    const showLessBtn = document.getElementById('showLessHistoryBtn');
    const extraRows = document.querySelectorAll('.extra-history-row');

    if (showMoreBtn && showLessBtn) {
        showMoreBtn.addEventListener('click', function() {
            extraRows.forEach(row => row.classList.remove('d-none'));
            showMoreBtn.classList.add('d-none');
            showLessBtn.classList.remove('d-none');
        });

        showLessBtn.addEventListener('click', function() {
            extraRows.forEach(row => row.classList.add('d-none'));
            showLessBtn.classList.add('d-none');
            showMoreBtn.classList.remove('d-none');
        });
    }

    // Chart functionality
    const priceHistoryData = <?php echo json_encode($price_history_raw); ?>;
    let priceChart;

    function renderChart(data) {
        const ctx = document.getElementById('priceHistoryChart');
        
        if (priceChart) {
            priceChart.destroy();
        }

        const gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 350);
        gradient.addColorStop(0, 'rgba(188, 158, 66, 0.3)');
        gradient.addColorStop(1, 'rgba(188, 158, 66, 0.05)');

        priceChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.map(row => new Date(row.event_date).toLocaleDateString('en-US', { 
                    month: 'short', 
                    day: 'numeric',
                    year: 'numeric'
                })),
                datasets: [{
                    label: 'Price (PHP)',
                    data: data.map(row => row.price),
                    borderColor: '#bc9e42',
                    backgroundColor: gradient,
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#bc9e42',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 6,
                    pointHoverRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            color: '#6b7280'
                        }
                    },
                    y: {
                        beginAtZero: false,
                        grid: {
                            color: 'rgba(107, 114, 128, 0.1)'
                        },
                        ticks: {
                            color: '#6b7280',
                            callback: function(value) {
                                return '₱' + value.toLocaleString();
                            }
                        }
                    }
                },
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                elements: {
                    point: {
                        hoverBackgroundColor: '#bc9e42'
                    }
                }
            }
        });
    }

    function filterAndRender(period) {
        const now = new Date();
        let filteredData = [];

        if (period === 'all') {
            filteredData = priceHistoryData;
        } else if (period === 'custom') {
            const startDate = new Date(document.getElementById('startDate').value);
            const endDate = new Date(document.getElementById('endDate').value);
            if (!isNaN(startDate) && !isNaN(endDate)) {
                filteredData = priceHistoryData.filter(row => {
                    const rowDate = new Date(row.event_date);
                    return rowDate >= startDate && rowDate <= endDate;
                });
            } else {
                return;
            }
        } else {
            let daysToSubtract = 0;
            if (period === 'yearly') daysToSubtract = 365;
            if (period === 'monthly') daysToSubtract = 180; // 6 months
            if (period === 'weekly') daysToSubtract = 30;
            
            const cutoffDate = new Date();
            cutoffDate.setDate(now.getDate() - daysToSubtract);
            
            filteredData = priceHistoryData.filter(row => new Date(row.event_date) >= cutoffDate);
        }

        if (filteredData.length > 0) {
            renderChart(filteredData);
        }
    }

    // Chart filter buttons
    document.addEventListener('DOMContentLoaded', function() {
        const filterButtons = document.querySelectorAll('.chart-filter-btn');
        const customDateRange = document.getElementById('customDateRange');

        filterButtons.forEach(button => {
            button.addEventListener('click', function() {
                filterButtons.forEach(btn => btn.classList.remove('active'));
                this.classList.add('active');
                
                const period = this.dataset.period;
                
                if (period === 'custom') {
                    customDateRange.classList.remove('d-none');
                } else {
                    customDateRange.classList.add('d-none');
                    filterAndRender(period);
                }
            });
        });

        // Custom date range inputs
        document.getElementById('startDate').addEventListener('change', () => filterAndRender('custom'));
        document.getElementById('endDate').addEventListener('change', () => filterAndRender('custom'));

        // Initial chart render
        if (priceHistoryData.length > 0) {
            filterAndRender('all');
        }
    });

    // Smooth scrolling for internal links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });

    // Intersection Observer for animation on scroll
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, observerOptions);

    // Observe content sections for scroll animations
    document.querySelectorAll('.content-section').forEach(section => {
        section.style.opacity = '0';
        section.style.transform = 'translateY(20px)';
        section.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(section);
    });

    // Enhanced form validation
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const requiredInputs = form.querySelectorAll('[required]');
            let isValid = true;

            requiredInputs.forEach(input => {
                if (!input.value.trim()) {
                    input.classList.add('is-invalid');
                    isValid = false;
                } else {
                    input.classList.remove('is-invalid');
                }
            });

            if (!isValid) {
                e.preventDefault();
                // Show error message
                const firstInvalid = form.querySelector('.is-invalid');
                if (firstInvalid) {
                    firstInvalid.focus();
                }
            }
        });
    });

    // Auto-hide alerts after 5 seconds
    document.querySelectorAll('.alert').forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-20px)';
            setTimeout(() => {
                alert.remove();
            }, 300);
        }, 5000);
    });

    // Price input formatting
    const priceInputs = document.querySelectorAll('input[type="number"]');
    priceInputs.forEach(input => {
        input.addEventListener('input', function() {
            // Remove any non-numeric characters except decimal point
            this.value = this.value.replace(/[^\d.]/g, '');
            
            // Ensure only one decimal point
            const parts = this.value.split('.');
            if (parts.length > 2) {
                this.value = parts[0] + '.' + parts.slice(1).join('');
            }
        });
    });

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl/Cmd + B to go back to properties
        if ((e.ctrlKey || e.metaKey) && e.key === 'b') {
            e.preventDefault();
            window.location.href = 'property.php';
        }
        
        // Escape to close modals
        if (e.key === 'Escape') {
            const openModal = document.querySelector('.modal.show');
            if (openModal) {
                const modal = bootstrap.Modal.getInstance(openModal);
                modal.hide();
            }
        }
    });

    // Loading state on form submit — ensure submit value is preserved even if button is disabled
    document.addEventListener('submit', function(e) {
        const form = e.target;
        const btn = e.submitter || form.querySelector('button[type="submit"], input[type="submit"]');
        if (btn) {
            // Preserve submitter's name/value (e.g., action=approve) using a hidden input
            if (btn.name && typeof btn.value !== 'undefined' && btn.value !== null) {
                let mirror = form.querySelector('input[type="hidden"][name="' + btn.name + '"]');
                if (!mirror) {
                    mirror = document.createElement('input');
                    mirror.type = 'hidden';
                    mirror.name = btn.name;
                    form.appendChild(mirror);
                }
                mirror.value = btn.value;
            }

            if (!btn.dataset.loadingApplied) {
                btn.dataset.loadingApplied = '1';
                btn.dataset.originalText = btn.innerHTML || btn.value || '';
                const spinner = '<i class="bi bi-hourglass-split me-2"></i>Processing...';
                if (btn.tagName === 'BUTTON') btn.innerHTML = spinner;
                if (btn.tagName === 'INPUT') btn.value = 'Processing...';
                // Now safe to disable without losing the submitter value
                btn.disabled = true;
            }
        }
    }, true);

    // Tooltip initialization for Bootstrap tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Prevent double-click form submission
    let formSubmitted = false;
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function() {
            if (formSubmitted) {
                return false;
            }
            formSubmitted = true;
            
            // Reset after 3 seconds
            setTimeout(() => {
                formSubmitted = false;
            }, 3000);
        });
    });

    // Reject Reason Modal wiring
    const openRejectBtn = document.getElementById('openRejectModalBtn');
    if (openRejectBtn) {
        const modalEl = document.getElementById('rejectReasonModal');
        const rejectModal = new bootstrap.Modal(modalEl);
        const textarea = document.getElementById('rejectReasonTextarea');
        const errorEl = document.getElementById('rejectReasonError');
        const confirmBtn = document.getElementById('confirmRejectBtn');
        const form = document.getElementById('adminActionForm');
        const hiddenReason = document.getElementById('rejectReasonInput');

        openRejectBtn.addEventListener('click', () => {
            textarea.value = '';
            errorEl.style.display = 'none';
            rejectModal.show();
            setTimeout(() => textarea.focus(), 150);
        });

        confirmBtn.addEventListener('click', () => {
            const value = textarea.value.trim();
            if (!value) {
                errorEl.style.display = 'block';
                textarea.focus();
                return;
            }
            hiddenReason.value = value;
            // build a synthetic submit for reject action
            const submit = document.createElement('button');
            submit.type = 'submit';
            submit.name = 'action';
            submit.value = 'reject';
            submit.style.display = 'none';
            form.appendChild(submit);
            rejectModal.hide();
            submit.click();
            form.removeChild(submit);
        });
    }
    
    // Mobile FAB Toggle
    const fabButton = document.getElementById('fabButton');
    const fabMenu = document.getElementById('fabMenu');
    
    if (fabButton && fabMenu) {
        fabButton.addEventListener('click', function() {
            fabMenu.classList.toggle('active');
        });
        
        // Close FAB menu when clicking outside
        document.addEventListener('click', function(event) {
            if (!event.target.closest('.mobile-actions-fab')) {
                fabMenu.classList.remove('active');
            }
        });
    }
</script>

<!-- Mobile Floating Action Button -->
<div class="mobile-actions-fab">
    <button class="fab-button" id="fabButton" type="button">
        <i class="bi bi-lightning-charge"></i>
    </button>
    <div class="fab-menu" id="fabMenu">
        <div class="fab-menu-title">
            <i class="bi bi-shield-check"></i>
            Quick Actions
        </div>
        <div class="fab-menu-actions">
            <?php if (!$is_approved): ?>
                <form action="view_property.php?id=<?php echo $property_id_to_review; ?>" method="POST" style="display: contents;">
                    <input type="hidden" name="property_id" value="<?php echo $property_id_to_review; ?>">
                    <input type="hidden" name="client_timestamp" class="client-timestamp-mobile">
                    <button type="submit" name="action" value="approve" class="btn btn-modern btn-approve w-100">
                        <i class="bi bi-check-circle me-2"></i>Approve
                    </button>
                    <button type="button" class="btn btn-modern btn-reject w-100" onclick="document.getElementById('openRejectModalBtn').click();">
                        <i class="bi bi-x-circle me-2"></i>Reject
                    </button>
                </form>
            <?php elseif ($is_admin_poster): ?>
                <button type="button" class="btn btn-modern btn-update w-100" data-bs-toggle="modal" data-bs-target="#updatePriceModal">
                    <i class="bi bi-tag me-2"></i>Update Price
                </button>
                <button type="button" class="btn btn-modern btn-secondary-modern w-100" onclick="openEditPropertyModal(<?php echo $property_id_to_review; ?>)">
                    <i class="bi bi-pencil me-2"></i>Edit
                </button>
            <?php endif; ?>
            <a href="property.php" class="btn btn-modern btn-secondary-modern w-100">
                <i class="bi bi-arrow-left me-2"></i>Back
            </a>
        </div>
    </div>
</div>

<!-- Include Edit Property Modal -->
<?php include 'modals/edit_property_modal.php'; ?>

</body>
</html>