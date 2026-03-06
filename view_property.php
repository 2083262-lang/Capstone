<?php
// Start output buffering
ob_start();
session_start();
include 'connection.php';
require_once __DIR__ . '/mail_helper.php';
require_once __DIR__ . '/config/session_timeout.php';
require_once __DIR__ . '/config/paths.php';

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

// --- Fetch Rental Details if property is a rental listing ---
$rental_data = null;
if ($property_data && in_array($property_data['Status'], ['For Rent', 'Pending Rented', 'Rented'])) {
    $sql_rental = "SELECT monthly_rent, security_deposit, lease_term_months, furnishing, available_from 
                   FROM rental_details WHERE property_id = ? LIMIT 1";
    $stmt_rental = $conn->prepare($sql_rental);
    $stmt_rental->bind_param("i", $property_id_to_review);
    $stmt_rental->execute();
    $result_rental = $stmt_rental->get_result();
    $rental_data = $result_rental->fetch_assoc();
    $stmt_rental->close();
}

// --- Check for existing sale verification status ---
$sale_status = null;
$rental_status = null;
$is_property_sold = false;
$is_pending_sold = false;
$is_property_rented = false;
$is_pending_rented = false;
if ($property_data) {
    $sql_sale_check = "SELECT status FROM sale_verifications WHERE property_id = ? ORDER BY submitted_at DESC LIMIT 1";
    $stmt_sale_check = $conn->prepare($sql_sale_check);
    $stmt_sale_check->bind_param("i", $property_id_to_review);
    $stmt_sale_check->execute();
    $result_sale_check = $stmt_sale_check->get_result();
    $sale_verification = $result_sale_check->fetch_assoc();
    $stmt_sale_check->close();
    $sale_status = $sale_verification ? $sale_verification['status'] : null;

    // Check for rental verification status (for rental properties)
    if (in_array($property_data['Status'], ['For Rent', 'Pending Rented', 'Rented'])) {
        $sql_rental_check = "SELECT status FROM rental_verifications WHERE property_id = ? ORDER BY submitted_at DESC LIMIT 1";
        $stmt_rental_check = $conn->prepare($sql_rental_check);
        $stmt_rental_check->bind_param("i", $property_id_to_review);
        $stmt_rental_check->execute();
        $result_rental_check = $stmt_rental_check->get_result();
        $rental_verification_row = $result_rental_check->fetch_assoc();
        $stmt_rental_check->close();
        $rental_status = $rental_verification_row ? $rental_verification_row['status'] : null;
    }

    // Combine: either sale or rental verification blocks the button
    $sale_status = $sale_status ?? $rental_status;

    $is_property_sold = ($property_data['Status'] === 'Sold');
    $is_pending_sold = ($property_data['Status'] === 'Pending Sold');
    $is_property_rented = ($property_data['Status'] === 'Rented');
    $is_pending_rented = ($property_data['Status'] === 'Pending Rented');
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
        // Normalize paths: strip leading '../' that may have been saved by agent upload scripts
        $photo_url = ltrim($row['photo_url'], '.');
        $photo_url = ltrim($photo_url, '/');
        $floor_images[$floor_num][] = $photo_url;
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
    <link href="<?= ASSETS_CSS ?>bootstrap.min.css" rel="stylesheet">
    <link href="<?= ASSETS_CSS ?>inter-font.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= ASSETS_CSS ?>fontawesome-all.min.css">
    <link rel="stylesheet" href="<?= ASSETS_CSS ?>bootstrap-icons.min.css">
    
    <style>
        /* ================================================
           ADMIN VIEW PROPERTY PAGE
           Theme consistent with property.php (admin panel)
           ================================================ */

        :root {
            --primary-color: #161209;
            --secondary-color: #bc9e42;
            --accent-color: #a08636;
            --bg-light: #f8f9fa;
            --border-color: #e0e0e0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-light);
            color: #212529;
        }

        .admin-sidebar {
            background: linear-gradient(180deg, #161209 0%, #1f1a0f 100%);
            color: #fff;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            width: 290px;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }

        .admin-content {
            margin-left: 290px;
            padding: 0;
            min-height: 100vh;
            max-width: 1800px;
        }

        @media (max-width: 1200px) {
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

        /* ===== PAGE-SPECIFIC VARIABLES ===== */
        .admin-content {
            --gold: #d4af37;
            --gold-light: #f4d03f;
            --gold-dark: #b8941f;
            --blue: #2563eb;
            --blue-light: #3b82f6;
            --blue-dark: #1e40af;
            --card-bg: #ffffff;
            --text-primary: #212529;
            --text-secondary: #6c757d;
        }

        /* ===== GALLERY GRID SECTION ===== */
        .property-hero-gallery {
            margin-bottom: 0;
        }

        /* View Selector (above gallery) */
        .gallery-view-selector {
            background: linear-gradient(to bottom, #ffffff, #f1f5f9);
            border-top: 1px solid #e2e8f0;
            border-bottom: 3px solid transparent;
            border-image: linear-gradient(90deg, var(--gold), var(--blue)) 1;
            padding: 1rem 0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.12), 0 1px 3px rgba(0,0,0,0.08);
        }

        .view-selector-container {
            display: flex;
            gap: 0.5rem;
            padding: 0 1.5rem;
            flex-wrap: wrap;
        }

        .view-selector-btn {
            padding: 0.5rem 1.25rem;
            background: #ffffff;
            color: #475569;
            border: 1px solid #e2e8f0;
            border-radius: 2px;
            font-size: 0.8rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }

        .view-selector-btn:hover {
            background: #f1f5f9;
            color: #1e293b;
            border-color: var(--gold);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .view-selector-btn.active {
            background: linear-gradient(135deg, var(--gold-dark), var(--gold));
            color: white;
            border-color: var(--gold);
            box-shadow: 0 2px 8px rgba(212, 175, 55, 0.3);
        }

        .view-selector-btn i {
            font-size: 0.9rem;
        }

        .gallery-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 0;
            height: 500px;
            overflow: hidden;
        }

        .gallery-grid-main {
            position: relative;
            cursor: pointer;
            overflow: hidden;
        }

        .gallery-grid-main img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .gallery-grid-main:hover img {
            transform: scale(1.02);
        }

        .gallery-grid-sidebar {
            display: grid;
            grid-template-rows: 1fr 1fr;
            gap: 0;
        }

        .gallery-grid-item {
            position: relative;
            cursor: pointer;
            overflow: hidden;
        }



        .gallery-grid-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .gallery-grid-item:hover img {
            transform: scale(1.05);
        }

        .gallery-grid-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f1f5f9;
            color: #94a3b8;
            font-size: 2rem;
        }

        .gallery-more-overlay {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.55);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 700;
            color: white;
            pointer-events: none;
        }



        /* Property Header Info (below gallery) */
        .property-header-info {
            padding: 2rem 1.5rem 1rem;
            background: #fff;
        }

        .property-price-header {
            font-size: 2.25rem;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 0.35rem;
            letter-spacing: -0.02em;
        }

        .property-price-header .price-suffix {
            font-size: 1.1rem;
            font-weight: 400;
            color: var(--text-secondary);
        }

        .property-address-header {
            font-size: 1.05rem;
            font-weight: 400;
            color: var(--text-secondary);
            margin-bottom: 0.75rem;
        }

        .property-specs-bar {
            display: flex;
            gap: 0.75rem;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-primary);
            flex-wrap: wrap;
        }

        .property-specs-bar span {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.4rem 0.85rem;
            background: #f1f5f9;
            border-radius: 2px;
            border: 1px solid #e2e8f0;
        }

        .property-specs-bar span i {
            font-size: 0.95rem;
            color: var(--gold-dark);
        }

        /* Status Badges (inside gallery image) */
        .gallery-status-badges {
            position: absolute;
            top: 1rem;
            left: 1rem;
            z-index: 4;
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .property-status-badge {
            font-weight: 700;
            font-size: 0.7rem;
            padding: 0.4rem 0.8rem;
            border-radius: 2px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            line-height: 1;
            backdrop-filter: blur(12px);
        }

        .status-for-sale {
            background: rgba(37, 99, 235, 0.85);
            color: #fff;
            border: 1px solid rgba(37, 99, 235, 0.3);
        }

        .status-for-rent {
            background: rgba(212, 175, 55, 0.85);
            color: #fff;
            border: 1px solid rgba(212, 175, 55, 0.3);
        }

        .hero-status-badge {
            font-weight: 700;
            font-size: 0.7rem;
            padding: 0.4rem 0.8rem;
            border-radius: 2px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            line-height: 1;
            backdrop-filter: blur(12px);
        }

        .badge-approved { background: rgba(34, 197, 94, 0.85); color: #fff; border: 1px solid rgba(34, 197, 94, 0.3); }
        .badge-pending { background: rgba(245, 158, 11, 0.85); color: #fff; border: 1px solid rgba(245, 158, 11, 0.3); }
        .badge-rejected { background: rgba(239, 68, 68, 0.85); color: #fff; border: 1px solid rgba(239, 68, 68, 0.3); }

        /* Sold status badge in gallery */
        .status-sold {
            background: rgba(220, 38, 38, 0.85);
            color: #fff;
            border: 1px solid rgba(220, 38, 38, 0.3);
        }
        .status-pending-sold {
            background: rgba(245, 158, 11, 0.85);
            color: #fff;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        /* Sold locked notice */
        .sold-locked-notice {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 1rem 1.25rem;
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            border: 1px solid #fca5a5;
            border-radius: 4px;
            margin-bottom: 1rem;
            color: #991b1b;
            font-size: 0.85rem;
            font-weight: 500;
        }
        .sold-locked-notice i {
            font-size: 1.1rem;
            margin-top: 2px;
            flex-shrink: 0;
        }
        .pending-sold-notice {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 1rem 1.25rem;
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
            border: 1px solid #fbbf24;
            border-radius: 4px;
            margin-bottom: 1rem;
            color: #92400e;
            font-size: 0.85rem;
            font-weight: 500;
        }
        .pending-sold-notice i {
            font-size: 1.1rem;
            margin-top: 2px;
            flex-shrink: 0;
        }

        /* Mark as Sold button style */
        .btn-sold {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.25);
        }
        .btn-sold:hover {
            box-shadow: 0 6px 16px rgba(124, 58, 237, 0.35);
            color: white;
            transform: translateY(-2px);
        }

        /* ===== MARK AS SOLD MODAL — Light Theme (matches property.php) ===== */
        #markSoldModal .modal-content {
            background: #ffffff;
            border: 1px solid rgba(37, 99, 235, 0.15);
            border-radius: 6px;
            overflow: hidden;
        }
        #markSoldModal .modal-header {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            border-bottom: none;
            padding: 1.4rem 1.75rem;
            position: relative;
        }
        #markSoldModal .modal-header::after {
            content: '';
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, var(--gold-dark), var(--blue));
        }
        #markSoldModal .modal-title {
            font-size: 1rem;
            font-weight: 700;
            color: #f1f5f9;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }
        #markSoldModal .modal-title i {
            color: var(--gold);
        }
        #markSoldModal .modal-body {
            background: #f8fafc;
            padding: 1.5rem 1.75rem 0.75rem;
        }
        #markSoldModal .modal-footer {
            background: #ffffff;
            border-top: 1px solid #e2e8f0;
            padding: 1rem 1.75rem;
        }
        /* Property confirm banner */
        #markSoldModal .property-confirm-card {
            background: #ffffff;
            border: 1px solid rgba(37, 99, 235, 0.12);
            border-left: 3px solid var(--gold-dark);
            border-radius: 4px;
            padding: 0.9rem 1.25rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        #markSoldModal .property-confirm-icon {
            font-size: 1.75rem;
            color: var(--gold-dark);
            flex-shrink: 0;
            line-height: 1;
        }
        #markSoldModal .property-confirm-card .property-confirm-title {
            font-weight: 700;
            font-size: 0.95rem;
            color: #0f172a;
        }
        #markSoldModal .property-confirm-card .property-confirm-address {
            font-size: 0.8rem;
            color: #64748b;
            margin-top: 0.2rem;
        }
        /* Section divider label */
        #markSoldModal .form-section-label {
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #64748b;
            padding-bottom: 0.6rem;
            margin-bottom: 0.85rem;
            border-bottom: 1px solid #e2e8f0;
        }
        #markSoldModal .form-section-label i {
            color: var(--gold-dark);
        }
        /* Labels and inputs */
        #markSoldModal .form-label {
            font-weight: 600;
            font-size: 0.8rem;
            color: #374151;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.4rem;
        }
        #markSoldModal .form-label .req {
            color: #dc2626;
        }
        #markSoldModal .form-text {
            font-size: 0.73rem;
            color: #6c757d;
            margin-top: 0.35rem;
        }
        #markSoldModal .form-control,
        #markSoldModal .input-group-text {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            font-size: 0.88rem;
            color: #212529;
        }
        #markSoldModal .input-group-text {
            background: #f8fafc;
            color: var(--gold-dark);
            font-weight: 700;
            border-right: none;
        }
        #markSoldModal .input-group .form-control {
            border-left: none;
        }
        #markSoldModal .form-control::placeholder {
            color: #94a3b8;
        }
        #markSoldModal .form-control:focus {
            background: #ffffff;
            border-color: var(--gold-dark);
            color: #212529;
            box-shadow: 0 0 0 0.2rem rgba(184, 148, 31, 0.15);
        }
        #markSoldModal .form-control[type="file"] {
            background: #ffffff;
        }
        #markSoldModal .form-control[type="file"]::-webkit-file-upload-button {
            background: #f1f5f9;
            border: none;
            border-right: 1px solid #e2e8f0;
            color: #374151;
            padding: 0.35rem 0.75rem;
            border-radius: 3px 0 0 3px;
            font-size: 0.8rem;
            cursor: pointer;
        }
        /* Submit button */
        #markSoldModal .btn-submit-sold {
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 50%, var(--gold-dark) 100%);
            color: #ffffff;
            font-weight: 700;
            padding: 0.75rem 2rem;
            border: none;
            border-radius: 4px;
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(184, 148, 31, 0.25);
        }
        #markSoldModal .btn-submit-sold:hover {
            box-shadow: 0 6px 20px rgba(184, 148, 31, 0.4);
            transform: translateY(-1px);
            color: #ffffff;
        }
        #markSoldModal .btn-submit-sold:disabled {
            opacity: 0.55;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        /* Cancel button */
        #markSoldModal .btn-cancel-sold {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            color: #6c757d;
            font-weight: 600;
            font-size: 0.82rem;
            padding: 0.75rem 1.5rem;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            transition: all 0.2s;
        }
        #markSoldModal .btn-cancel-sold:hover {
            border-color: #dc2626;
            color: #dc2626;
            background: rgba(220, 38, 38, 0.03);
        }
        /* File preview grid */
        .doc-preview-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 0.6rem;
        }
        .doc-preview-item {
            position: relative;
            width: 90px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            overflow: hidden;
            background: #ffffff;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            transition: box-shadow 0.2s;
        }
        .doc-preview-item:hover { box-shadow: 0 3px 10px rgba(0,0,0,0.12); }
        .doc-preview-thumb {
            width: 100%;
            height: 70px;
            object-fit: cover;
            display: block;
        }
        .doc-preview-icon {
            width: 100%;
            height: 70px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8fafc;
            font-size: 2rem;
        }
        .doc-preview-icon.pdf  { color: #dc2626; }
        .doc-preview-icon.word { color: #2563eb; }
        .doc-preview-icon.other{ color: #64748b; }
        .doc-preview-name {
            font-size: 0.65rem;
            color: #374151;
            font-weight: 500;
            padding: 0.25rem 0.4rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            border-top: 1px solid #f1f5f9;
        }
        .doc-preview-remove {
            position: absolute;
            top: 3px;
            right: 3px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: rgba(220,38,38,0.85);
            color: #fff;
            border: none;
            font-size: 0.65rem;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            line-height: 1;
            padding: 0;
            transition: background 0.2s;
        }
        .doc-preview-remove:hover { background: #dc2626; }

        /* Days on Market Badge (inside gallery image) */
        .gallery-days-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            z-index: 4;
            background: rgba(0, 0, 0, 0.6);
            color: #fff;
            font-weight: 700;
            font-size: 0.7rem;
            padding: 0.4rem 0.8rem;
            border-radius: 2px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            line-height: 1;
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.15);
        }

        /* Lightbox Overlay */
        .lightbox-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.95);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .lightbox-overlay img {
            max-width: 90%;
            max-height: 85vh;
            object-fit: contain;
            border-radius: 4px;
        }

        .lightbox-close {
            position: absolute;
            top: 1.25rem;
            right: 1.25rem;
            background: rgba(255,255,255,0.1);
            color: white;
            border: 1px solid rgba(255,255,255,0.2);
            width: 44px;
            height: 44px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 1.25rem;
            transition: all 0.2s ease;
            z-index: 10;
        }

        .lightbox-close:hover {
            background: #dc2626;
            border-color: #dc2626;
        }

        .lightbox-prev,
        .lightbox-next {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(255,255,255,0.1);
            color: white;
            border: 1px solid rgba(255,255,255,0.2);
            width: 48px;
            height: 48px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 1.25rem;
            transition: all 0.2s ease;
            backdrop-filter: blur(8px);
            z-index: 10;
        }

        .lightbox-prev { left: 1.5rem; }
        .lightbox-next { right: 1.5rem; }

        .lightbox-prev:hover,
        .lightbox-next:hover {
            background: linear-gradient(135deg, var(--gold-dark), var(--gold));
            border-color: var(--gold);
            transform: translateY(-50%) scale(1.05);
        }

        .lightbox-counter {
            position: absolute;
            bottom: 2rem;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0,0,0,0.8);
            color: white;
            padding: 0.4rem 1rem;
            border-radius: 2px;
            font-weight: 700;
            font-size: 0.85rem;
            border: 1px solid rgba(255,255,255,0.15);
        }

        .lightbox-label {
            position: absolute;
            top: 1.5rem;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 0.4rem 1rem;
            border-radius: 2px;
            font-weight: 600;
            font-size: 0.8rem;
            white-space: nowrap;
        }



        /* ===== CONTENT SECTIONS ===== */
        .content-section {
            background: var(--card-bg);
            border: 1px solid rgba(37, 99, 235, 0.1);
            border-radius: 4px;
            padding: 2rem 1.5rem;
            margin-top: 1rem;
            margin-bottom: 1.25rem;
            position: relative;
            overflow: hidden;
        }

        .content-section::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .content-section:hover::before { opacity: 1; }

        .content-section:hover {
            border-color: rgba(37, 99, 235, 0.2);
        }

        .section-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            letter-spacing: -0.01em;
        }

        .section-title i {
            color: var(--gold-dark);
            font-size: 1.1rem;
        }

        /* Facts Grid */
        .facts-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
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
            padding: 1.5rem 1.25rem;
            background: #ffffff;
            border-radius: 4px;
            border: 1px solid rgba(37, 99, 235, 0.1);
            transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.3s ease;
            min-height: 110px;
        }

        .fact-item:hover {
            border-color: rgba(37, 99, 235, 0.25);
            box-shadow: 0 4px 16px rgba(37, 99, 235, 0.06);
            transform: translateY(-2px);
        }

        .fact-label {
            font-size: 0.7rem;
            color: var(--text-secondary);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
        }

        .fact-label i {
            font-size: 0.8rem;
            color: var(--gold-dark);
        }

        .fact-value {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--text-primary);
            line-height: 1.2;
            letter-spacing: -0.01em;
        }

        .fact-value.highlight {
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .fact-value small {
            font-size: 0.8rem;
            font-weight: 500;
            color: var(--text-secondary);
        }


        /* Amenities Grid */
        .amenities-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 0.75rem;
        }

        .amenity-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.8rem 1rem;
            background: #f8fafc;
            border-radius: 4px;
            border: 1px solid #e2e8f0;
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--text-primary);
            transition: border-color 0.2s ease, background-color 0.2s ease;
        }

        .amenity-item:hover {
            background: #ffffff;
            border-color: rgba(37, 99, 235, 0.25);
        }

        .amenity-item i {
            color: var(--gold-dark);
            width: 18px;
            text-align: center;
            font-size: 0.95rem;
        }

        /* Description */
        .property-description {
            font-size: 0.95rem;
            line-height: 1.9;
            color: var(--text-secondary);
            letter-spacing: -0.01em;
            text-align: justify;
            text-justify: inter-word;
        }

        /* Rental Details Card */
        .rental-details-card {
            background: var(--card-bg);
            border: 1px solid rgba(212, 175, 55, 0.3);
            border-radius: 4px;
            padding: 1.75rem 1.5rem;
            margin-bottom: 1.25rem;
            position: relative;
            overflow: hidden;
        }

        .rental-details-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), var(--gold-dark), transparent);
        }

        .rental-details-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--gold-dark);
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            letter-spacing: -0.01em;
        }

        .rental-details-title i {
            font-size: 1.1rem;
        }

        .rental-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 1rem;
        }

        .rental-info-item {
            background: #fffbeb;
            padding: 1.25rem 1rem;
            border-radius: 4px;
            border: 1px solid rgba(212, 175, 55, 0.15);
            transition: border-color 0.2s ease, transform 0.3s ease;
            text-align: center;
        }

        .rental-info-item:hover {
            border-color: var(--gold);
            transform: translateY(-2px);
        }

        .rental-info-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--gold-dark);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 0.5rem;
        }

        .rental-info-value {
            font-size: 1rem;
            font-weight: 800;
            color: #92400e;
            letter-spacing: -0.01em;
        }


        /* ===== ACTION PANEL (Sidebar) ===== */
        .action-panel {
            position: sticky;
            top: 20px;
            z-index: 10;
            max-height: calc(100vh - 40px);
            overflow-y: auto;
            overflow-x: hidden;
            padding-bottom: 20px;
        }
        
        .action-card {
            background: var(--card-bg);
            border: 1px solid rgba(37, 99, 235, 0.1);
            border-radius: 4px;
            padding: 1.75rem;
            margin-top: 1rem;
            margin-bottom: 1.25rem;
            position: relative;
            overflow: hidden;
        }

        .action-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent);
        }
        
        /* Admin Actions Card */
        .admin-actions-card {
            border: 1px solid rgba(212, 175, 55, 0.3);
        }

        .admin-actions-card::before {
            background: linear-gradient(90deg, transparent, var(--gold), var(--gold-dark), transparent);
        }
        
        .admin-actions-card .section-title {
            color: var(--text-primary);
            font-size: 1rem;
            margin-bottom: 1.25rem;
        }
        
        .admin-actions-card .section-title i {
            color: var(--gold-dark);
        }

        /* Agent Card */
        .agent-card {
            text-align: center;
            padding: 1.5rem 1.25rem;
            background: #f8fafc;
            border-radius: 4px;
            border: 1px solid #e2e8f0;
        }

        .agent-avatar {
            width: 80px;
            height: 80px;
            border-radius: 4px;
            object-fit: cover;
            margin: 0 auto 1rem;
            border: 2px solid var(--gold);
        }

        .agent-name {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
            letter-spacing: -0.01em;
        }

        .agent-title {
            font-size: 0.8rem;
            color: var(--text-secondary);
            font-weight: 600;
        }

        /* ===== BUTTONS ===== */
        .btn-modern {
            font-weight: 700;
            padding: 0.75rem 1.5rem;
            border-radius: 4px;
            border: none;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-size: 0.8rem;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            position: relative;
            overflow: hidden;
        }
        
        .admin-actions-card .btn-modern {
            padding: 0.85rem 1.5rem;
            font-size: 0.85rem;
        }

        .btn-approve {
            background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(34, 197, 94, 0.25);
        }

        .btn-approve:hover {
            box-shadow: 0 6px 16px rgba(34, 197, 94, 0.35);
            color: white;
            transform: translateY(-2px);
        }

        .btn-reject {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.25);
        }

        .btn-reject:hover {
            box-shadow: 0 6px 16px rgba(239, 68, 68, 0.35);
            color: white;
            transform: translateY(-2px);
        }

        .btn-secondary-modern {
            background: var(--card-bg);
            color: var(--text-secondary);
            border: 1px solid #e2e8f0;
        }

        .btn-secondary-modern:hover {
            border-color: var(--blue);
            color: var(--blue);
            background: rgba(37, 99, 235, 0.03);
        }

        .btn-update {
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 50%, var(--gold-dark) 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(212, 175, 55, 0.25);
        }

        .btn-update:hover {
            box-shadow: 0 6px 16px rgba(212, 175, 55, 0.35);
            color: white;
            transform: translateY(-2px);
        }


        /* ===== PRICE HISTORY TABLE ===== */
        .price-history-table {
            background: var(--card-bg);
            border-radius: 4px;
            overflow: hidden;
            border: 1px solid rgba(37, 99, 235, 0.1);
        }

        .price-history-table table { margin: 0; }

        .price-history-table th {
            background: #f8fafc;
            font-weight: 700;
            color: var(--text-primary);
            padding: 0.85rem 1.25rem;
            border: none;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .price-history-table td {
            padding: 0.85rem 1.25rem;
            border: none;
            border-bottom: 1px solid #e2e8f0;
            font-weight: 500;
            color: var(--text-secondary);
            font-size: 0.85rem;
        }

        .price-history-table tr:last-child td { border-bottom: none; }
        .price-history-table tr:hover { background: #f8fafc; }

        /* Chart Container */
        .chart-container {
            position: relative;
            height: 300px;
            background: var(--card-bg);
            border-radius: 4px;
            padding: 1.5rem;
            border: 1px solid rgba(37, 99, 235, 0.1);
        }

        .chart-filters {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.25rem;
            flex-wrap: wrap;
        }

        .chart-filter-btn {
            padding: 0.4rem 0.85rem;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            color: var(--text-primary);
            border-radius: 2px;
            font-size: 0.8rem;
            font-weight: 600;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .chart-filter-btn.active,
        .chart-filter-btn:hover {
            background: linear-gradient(135deg, var(--gold-dark), var(--gold));
            color: white;
            border-color: var(--gold);
        }


        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .admin-content { padding: 1rem; }
            .property-price-header { font-size: 1.75rem; }
            .property-address-header { font-size: 0.95rem; }
            .property-specs-bar { 
                flex-direction: column; 
                gap: 0.5rem; 
                align-items: flex-start;
            }
            .gallery-grid {
                grid-template-columns: 1fr;
                height: 300px;
            }
            .gallery-grid-sidebar { display: none; }
            .view-selector-container { padding: 0 1rem; overflow-x: auto; flex-wrap: nowrap; -webkit-overflow-scrolling: touch; }
            .view-selector-btn { font-size: 0.7rem; padding: 0.4rem 0.8rem; white-space: nowrap; flex-shrink: 0; }
            .content-section { padding: 1.25rem 1rem; }
            .facts-grid { grid-template-columns: 1fr; gap: 0.75rem; }
            .rental-details-card { padding: 1.25rem 1rem; }
            .action-card { padding: 1.25rem; }
            .agent-card { padding: 1.25rem 1rem; }
            .lightbox-prev { left: 0.75rem; }
            .lightbox-next { right: 0.75rem; }
            .lightbox-prev, .lightbox-next { width: 40px; height: 40px; font-size: 1rem; }
        }

        @media (max-width: 576px) {
            .admin-content { padding: 0.75rem; }
            .property-price-header { font-size: 1.5rem; }
            .property-address-header { font-size: 0.88rem; }
            .gallery-grid { height: 240px; }
            .content-section { padding: 1rem 0.85rem; }
            .action-card { padding: 1rem; }
            .action-card .d-flex { flex-wrap: wrap; gap: 0.5rem; }
            .action-card .btn { flex: 1 1 auto; min-width: 0; justify-content: center; font-size: 0.8rem; }
            .agent-card { padding: 1rem 0.85rem; }
        }

        /* ===== TOAST NOTIFICATIONS ===== */
        #toastContainer {
            position: fixed;
            top: 1.5rem;
            right: 1.5rem;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
            pointer-events: none;
        }
        .app-toast {
            display: flex;
            align-items: flex-start;
            gap: 0.85rem;
            background: #ffffff;
            border-radius: 12px;
            padding: 0.9rem 1.1rem;
            min-width: 300px;
            max-width: 380px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.16), 0 0 0 1px rgba(0,0,0,0.06);
            pointer-events: all;
            position: relative;
            overflow: hidden;
            animation: toast-in .35s cubic-bezier(.34,1.56,.64,1) forwards;
        }
        @keyframes toast-in  { from { opacity:0; transform: translateX(60px) scale(.95); } to { opacity:1; transform: translateX(0) scale(1); } }
        .app-toast.toast-out { animation: toast-out .3s ease forwards; }
        @keyframes toast-out { to { opacity:0; transform: translateX(60px) scale(.9); max-height:0; padding:0; margin:0; } }
        .app-toast::before {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 3px;
        }
        .app-toast.toast-success::before { background: linear-gradient(180deg, #d4af37, #b8941f); }
        .app-toast.toast-error::before   { background: linear-gradient(180deg, #ef4444, #dc2626); }
        .app-toast.toast-info::before    { background: linear-gradient(180deg, #2563eb, #1e40af); }
        .app-toast.toast-warning::before { background: linear-gradient(180deg, #d4af37, #b8941f); }
        .app-toast-icon {
            width: 36px; height: 36px;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }
        .toast-success .app-toast-icon,
        .toast-warning .app-toast-icon { background: rgba(212,175,55,0.12); color: #d4af37; }
        .toast-error   .app-toast-icon { background: rgba(239,68,68,0.1);   color: #ef4444; }
        .toast-info    .app-toast-icon { background: rgba(37,99,235,0.1);   color: #2563eb; }
        .app-toast-body      { flex: 1; min-width: 0; }
        .app-toast-title     { font-size: 0.82rem; font-weight: 700; color: #111827; margin-bottom: 0.2rem; }
        .app-toast-msg       { font-size: 0.78rem; color: #6b7280; line-height: 1.4; word-break: break-word; }
        .app-toast-close {
            background: none; border: none; cursor: pointer;
            color: #9ca3af; font-size: 0.8rem;
            padding: 0; line-height: 1;
            flex-shrink: 0;
            transition: color .2s;
        }
        .app-toast-close:hover { color: #374151; }
        .app-toast-progress {
            position: absolute;
            bottom: 0; left: 0;
            height: 2px;
            border-radius: 0 0 0 12px;
        }
        .toast-success .app-toast-progress,
        .toast-warning .app-toast-progress { background: linear-gradient(90deg, #d4af37, #b8941f); }
        .toast-error   .app-toast-progress { background: linear-gradient(90deg, #ef4444, #dc2626); }
        .toast-info    .app-toast-progress { background: linear-gradient(90deg, #2563eb, #1e40af); }
        @keyframes toast-progress { from { width: 100%; } to { width: 0%; } }

        /* Modal Styles */
        .modal-content {
            border: none;
            border-radius: 4px;
            overflow: hidden;
        }

        .modal-header {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: white;
            border-radius: 0;
            padding: 1.25rem 1.75rem;
            border-bottom: none;
            position: relative;
        }

        .modal-header::after {
            content: '';
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, var(--gold), var(--blue));
        }

        .modal-body {
            padding: 1.75rem;
        }

        .modal-footer {
            padding: 1.25rem 1.75rem;
            border-top: 1px solid #e2e8f0;
            background: #f8fafc;
        }

        /* Error State */
        .error-state {
            text-align: center;
            padding: 4rem 2rem;
            background: var(--card-bg);
            border-radius: 4px;
            margin: 2rem 1.5rem;
            border: 1px solid rgba(37, 99, 235, 0.1);
            position: relative;
            overflow: hidden;
        }

        .error-state::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent);
        }

        .error-icon {
            font-size: 3rem;
            color: var(--gold-dark);
            margin-bottom: 1rem;
        }

        /* Detail Row */
        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.85rem 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .detail-row:last-child { border-bottom: none; }

        .detail-label {
            color: var(--text-secondary);
            font-size: 0.8rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .detail-label i {
            color: #94a3b8;
            font-size: 0.85rem;
        }

        .detail-value {
            font-weight: 700;
            color: var(--text-primary);
            font-size: 0.85rem;
        }

        /* Form Controls */
        .form-control:focus {
            border-color: var(--gold);
            box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.1);
        }

        .form-label {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.85rem;
            margin-bottom: 0.375rem;
        }
        
        /* Mobile FAB */
        .mobile-actions-fab {
            display: none;
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 999;
        }
        
        .fab-button {
            width: 52px;
            height: 52px;
            border-radius: 4px;
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 100%);
            color: white;
            border: none;
            box-shadow: 0 4px 16px rgba(212, 175, 55, 0.35);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .fab-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 24px rgba(212, 175, 55, 0.45);
        }
        
        .fab-menu {
            position: absolute;
            bottom: 65px;
            right: 0;
            background: white;
            border-radius: 4px;
            box-shadow: 0 8px 32px rgba(15, 23, 42, 0.15);
            padding: 1rem;
            min-width: 260px;
            display: none;
            border: 1px solid rgba(37, 99, 235, 0.1);
        }
        
        .fab-menu.active {
            display: block;
            animation: slideUpFade 0.25s ease;
        }
        
        @keyframes slideUpFade {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fab-menu-title {
            font-size: 0.7rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .fab-menu-actions {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .fab-menu-actions .btn {
            justify-content: flex-start;
            text-align: left;
        }
        
        @media (max-width: 991px) {
            .action-panel {
                position: relative;
                top: 0;
                margin-top: 1.5rem;
                max-height: none;
                overflow-y: visible;
            }
            .mobile-actions-fab { display: block; }
            .action-panel-mobile-hide { display: none; }
        }
        
        @media (min-width: 992px) {
            .mobile-actions-fab { display: none; }
        }

        /* ===== PROPERTY CONTENT WRAPPER ===== */
        .property-content {
            padding: 0;
        }

        /* ================================================================
           SKELETON SCREEN — CSR / Progressive Hydration
           view_property.php
        ================================================================ */
        @keyframes sk-shimmer {
            0%   { background-position: -800px 0; }
            100% { background-position:  800px 0; }
        }
        .sk-shimmer {
            background: linear-gradient(90deg, #f0f0f0 25%, #e8e8e8 50%, #f0f0f0 75%);
            background-size: 1600px 100%;
            animation: sk-shimmer 1.6s ease-in-out infinite;
            border-radius: 4px;
        }
        #page-content { display: none; }

        /* Gallery section skeleton */
        .sk-gallery-selector {
            background: linear-gradient(to bottom, #ffffff, #f1f5f9);
            border-top: 1px solid #e2e8f0;
            border-bottom: 3px solid #e2e8f0;
            padding: 1rem 1.5rem;
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            align-items: center;
        }
        .sk-gallery-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            height: 460px;
            gap: 0;
        }
        .sk-gallery-main { width: 100%; height: 100%; border-radius: 0; }
        .sk-gallery-sidebar {
            display: grid;
            grid-template-rows: 1fr 1fr;
            gap: 2px;
            border-left: 2px solid #fff;
        }
        .sk-gallery-sidebar > .sk-shimmer { border-radius: 0; }
        .sk-prop-header-info {
            background: #fff;
            border-top: 1px solid #e2e8f0;
            padding: 1.25rem 1.5rem 1.5rem;
        }

        /* Two-column detail layout */
        .sk-prop-content {
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 1.5rem;
            padding: 1.5rem;
            background: #f8f9fa;
        }
        .sk-prop-left, .sk-prop-right { display: flex; flex-direction: column; }

        /* Generic card */
        .sk-dash-card {
            background: #fff;
            border: 1px solid rgba(37,99,235,0.1);
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 1.25rem;
        }
        .sk-dash-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #f1f5f9;
        }
        .sk-dash-body { padding: 1.25rem; }
        .sk-line { display: block; border-radius: 4px; }

        /* Key facts grid inside card */
        .sk-facts-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
        }
        .sk-fact-item { padding: 0.4rem 0; }

        /* Responsive */
        @media (max-width: 1200px) {
            .sk-gallery-grid { height: 360px; }
            .sk-prop-content { grid-template-columns: 1fr; }
            .sk-prop-right { display: none; }
        }
        @media (max-width: 768px) {
            .sk-gallery-grid { grid-template-columns: 1fr; height: 220px; }
            .sk-gallery-sidebar { display: none; }
            .sk-facts-grid { grid-template-columns: repeat(2, 1fr); }
            .sk-prop-content { padding: 1rem; gap: 1rem; }
        }
        @media (max-width: 576px) {
            .sk-gallery-selector { padding: 0.75rem 1rem; }
            .sk-prop-header-info { padding: 1rem; }
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

    <!-- NO-JS FALLBACK -->
    <noscript><style>
        #sk-screen    { display: none !important; }
        #page-content { display: block !important; opacity: 1 !important; }
    </style></noscript>

    <!-- SKELETON SCREEN ─────────────────────────────────────────── -->
    <div id="sk-screen" role="presentation" aria-hidden="true">

        <!-- Gallery View Selector Bar -->
        <div class="sk-gallery-selector">
            <?php for ($ski = 0; $ski < 3; $ski++): ?>
            <div class="sk-shimmer" style="width:130px;height:34px;border-radius:2px;flex-shrink:0;"></div>
            <?php endfor; ?>
        </div>

        <!-- Gallery Grid -->
        <div class="sk-gallery-grid">
            <div class="sk-gallery-main sk-shimmer"></div>
            <div class="sk-gallery-sidebar">
                <div class="sk-shimmer"></div>
                <div class="sk-shimmer"></div>
            </div>
        </div>

        <!-- Property Header Info (price, address, specs) -->
        <div class="sk-prop-header-info">
            <div class="sk-shimmer sk-line" style="width:260px;height:36px;margin-bottom:0.6rem;"></div>
            <div class="sk-shimmer sk-line" style="width:380px;height:14px;margin-bottom:0.9rem;"></div>
            <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
                <div class="sk-shimmer" style="width:82px;height:24px;border-radius:4px;"></div>
                <div class="sk-shimmer" style="width:82px;height:24px;border-radius:4px;"></div>
                <div class="sk-shimmer" style="width:100px;height:24px;border-radius:4px;"></div>
                <div class="sk-shimmer" style="width:130px;height:24px;border-radius:4px;"></div>
            </div>
        </div>

        <!-- Two-column detail area -->
        <div class="sk-prop-content">

            <!-- Left Column: detail sections -->
            <div class="sk-prop-left">

                <!-- Property Overview card -->
                <div class="sk-dash-card">
                    <div class="sk-dash-header">
                        <div class="sk-shimmer sk-line" style="width:170px;height:16px;"></div>
                    </div>
                    <div class="sk-dash-body">
                        <div class="sk-facts-grid">
                            <?php for ($ski = 0; $ski < 9; $ski++): ?>
                            <div class="sk-fact-item">
                                <div class="sk-shimmer sk-line" style="width:75px;height:10px;margin-bottom:7px;"></div>
                                <div class="sk-shimmer sk-line" style="width:110px;height:14px;"></div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>

                <!-- Description card -->
                <div class="sk-dash-card">
                    <div class="sk-dash-header">
                        <div class="sk-shimmer sk-line" style="width:130px;height:16px;"></div>
                    </div>
                    <div class="sk-dash-body">
                        <div class="sk-shimmer sk-line" style="width:95%;height:12px;margin-bottom:9px;"></div>
                        <div class="sk-shimmer sk-line" style="width:90%;height:12px;margin-bottom:9px;"></div>
                        <div class="sk-shimmer sk-line" style="width:93%;height:12px;margin-bottom:9px;"></div>
                        <div class="sk-shimmer sk-line" style="width:85%;height:12px;margin-bottom:9px;"></div>
                        <div class="sk-shimmer sk-line" style="width:70%;height:12px;"></div>
                    </div>
                </div>

                <!-- Amenities card -->
                <div class="sk-dash-card">
                    <div class="sk-dash-header">
                        <div class="sk-shimmer sk-line" style="width:100px;height:16px;"></div>
                    </div>
                    <div class="sk-dash-body" style="display:flex;flex-wrap:wrap;gap:0.6rem;">
                        <?php for ($ski = 0; $ski < 8; $ski++): ?>
                        <div class="sk-shimmer" style="width:<?= [90,110,95,120,85,100,115,88][$ski] ?>px;height:28px;border-radius:20px;"></div>
                        <?php endfor; ?>
                    </div>
                </div>

            </div><!-- /.sk-prop-left -->

            <!-- Right Column: actions + agent -->
            <div class="sk-prop-right">

                <!-- Admin Actions card -->
                <div class="sk-dash-card">
                    <div class="sk-dash-header">
                        <div class="sk-shimmer sk-line" style="width:130px;height:16px;"></div>
                    </div>
                    <div class="sk-dash-body">
                        <div class="sk-shimmer sk-line" style="height:44px;border-radius:4px;margin-bottom:0.75rem;"></div>
                        <div class="sk-shimmer sk-line" style="height:44px;border-radius:4px;margin-bottom:0.75rem;"></div>
                        <div class="sk-shimmer sk-line" style="height:44px;border-radius:4px;"></div>
                    </div>
                </div>

                <!-- Agent card -->
                <div class="sk-dash-card">
                    <div class="sk-dash-header">
                        <div class="sk-shimmer sk-line" style="width:100px;height:16px;"></div>
                    </div>
                    <div class="sk-dash-body" style="display:flex;flex-direction:column;align-items:center;gap:0.75rem;padding-top:1.5rem;padding-bottom:1.5rem;">
                        <div class="sk-shimmer" style="width:80px;height:80px;border-radius:50%;"></div>
                        <div class="sk-shimmer sk-line" style="width:140px;height:15px;"></div>
                        <div class="sk-shimmer sk-line" style="width:110px;height:12px;"></div>
                    </div>
                </div>

            </div><!-- /.sk-prop-right -->

        </div><!-- /.sk-prop-content -->

    </div><!-- /#sk-screen -->

    <!-- REAL CONTENT (hidden until hydrated) ───────────────────── -->
    <div id="page-content">

    <?php if ($error_message || $success_message): ?>
    <script>
    document.addEventListener('skeleton:hydrated', function() {
        <?php if ($success_message): ?>
        showToast('success', 'Success', '<?= addslashes(htmlspecialchars($success_message)) ?>', 6000);
        <?php endif; ?>
        <?php if ($error_message): ?>
        showToast('error', 'Error', '<?= addslashes(htmlspecialchars($error_message)) ?>', 7000);
        <?php endif; ?>
    });
    </script>
    <?php endif; ?>

    <?php if ($property_data): ?>
        <?php
            $full_address = htmlspecialchars($property_data['StreetAddress'] . ', ' . $property_data['City'] . ', ' . $property_data['Province'] . ' ' . $property_data['ZIP']);
            $status_class = 'badge-' . strtolower($property_data['approval_status']);
            $is_approved = $property_data['approval_status'] === 'approved';
            $is_admin_poster = ($agent_info && $agent_info['account_id'] == $admin_account_id);
        ?>
        
        <!-- Hero Section - Grid Gallery -->
        <?php
            $status_value = $property_data['Status'] ?? 'For Sale';
            if ($status_value === 'For Rent') {
                $status_badge_class = 'status-for-rent';
                $status_icon = 'bi-key-fill';
            } elseif ($status_value === 'Rented') {
                $status_badge_class = 'status-sold';
                $status_icon = 'bi-check-circle-fill';
            } elseif ($status_value === 'Pending Rented') {
                $status_badge_class = 'status-pending-sold';
                $status_icon = 'bi-hourglass-split';
            } elseif ($status_value === 'Sold') {
                $status_badge_class = 'status-sold';
                $status_icon = 'bi-check-circle-fill';
            } elseif ($status_value === 'Pending Sold') {
                $status_badge_class = 'status-pending-sold';
                $status_icon = 'bi-hourglass-split';
            } else {
                $status_badge_class = 'status-for-sale';
                $status_icon = 'bi-tag-fill';
            }
        ?>
        <section class="property-hero-gallery">
            <div class="container-fluid px-0">
                <!-- Floor/Featured Selector (above gallery) -->
                <div class="gallery-view-selector">
                    <div class="view-selector-container">
                        <button class="view-selector-btn active" data-type="featured" onclick="switchHeroView('featured')">
                            <i class="bi bi-star-fill"></i> Featured Images
                        </button>
                        <?php if (!empty($floor_images)): ?>
                            <?php foreach ($floor_images as $floor_num => $images): ?>
                                <button class="view-selector-btn" data-type="floor" data-floor="<?php echo $floor_num; ?>" onclick="switchHeroView('floor', <?php echo $floor_num; ?>)">
                                    <i class="bi bi-building"></i> Floor <?php echo $floor_num; ?>
                                </button>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="gallery-grid">
                    <div class="gallery-grid-main" onclick="openLightbox(0)">
                        <img src="<?php echo htmlspecialchars($property_images[0] ?? (BASE_URL . 'images/placeholder.svg')); ?>" 
                             alt="Main property view" id="mainHeroImage">
                        
                        <!-- Status Badges (inside image) -->
                        <div class="gallery-status-badges">
                            <div class="property-status-badge <?php echo $status_badge_class; ?>">
                                <i class="bi <?php echo $status_icon; ?>"></i><?php echo htmlspecialchars($status_value); ?>
                            </div>
                            <div class="hero-status-badge <?php echo $status_class; ?>">
                                <?php echo ucfirst($property_data['approval_status']); ?>
                            </div>
                        </div>

                        <!-- Days on Market Badge (inside image) -->
                        <div class="gallery-days-badge">
                            <i class="bi bi-calendar3"></i> <?php echo ($days_on_market !== null ? $days_on_market : '—'); ?> days on market
                        </div>
                    </div>
                    <div class="gallery-grid-sidebar">
                        <div class="gallery-grid-item" id="sidebarItem0" <?php if(count($property_images) >= 2): ?>onclick="openLightbox(1)"<?php else: ?>style="cursor:default;"<?php endif; ?>>
                            <?php if(count($property_images) >= 2): ?>
                                <img src="<?php echo htmlspecialchars($property_images[1]); ?>" alt="Property image 2" id="sideImg0">
                            <?php else: ?>
                                <div class="gallery-grid-placeholder"><i class="bi bi-image"></i></div>
                            <?php endif; ?>
                        </div>
                        <div class="gallery-grid-item" id="sidebarItem1" <?php if(count($property_images) >= 3): ?>onclick="openLightbox(2)"<?php else: ?>style="cursor:default;"<?php endif; ?>>
                            <?php if(count($property_images) >= 3): ?>
                                <img src="<?php echo htmlspecialchars($property_images[2]); ?>" alt="Property image 3" id="sideImg1">
                            <?php else: ?>
                                <div class="gallery-grid-placeholder"><i class="bi bi-image"></i></div>
                            <?php endif; ?>
                            <?php if(count($property_images) > 3): ?>
                                <div class="gallery-more-overlay" id="moreOverlay">+<?php echo count($property_images) - 3; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Property Header Info -->
                <div class="property-header-info container-fluid">
                    <h1 class="property-price-header">
                        ₱<?php echo number_format($property_data['ListingPrice']); ?>
                        <?php if (in_array($status_value, ['For Rent', 'Pending Rented', 'Rented'])): ?>
                            <span class="price-suffix">/ month</span>
                        <?php endif; ?>
                    </h1>
                    <p class="property-address-header">
                        <i class="bi bi-geo-alt me-2"></i><?php echo $full_address; ?>
                    </p>
                    <div class="property-specs-bar">
                        <span><i class="bi bi-house me-1"></i><?php echo $property_data['Bedrooms']; ?> beds</span>
                        <span><i class="bi bi-droplet me-1"></i><?php echo $property_data['Bathrooms']; ?> baths</span>
                        <?php if (!empty($property_data['SquareFootage'])): ?>
                        <span><i class="bi bi-rulers me-1"></i><?php echo number_format($property_data['SquareFootage']); ?> sqft</span>
                        <?php endif; ?>
                        <?php if (!empty($property_data['MLSNumber'])): ?>
                        <span><i class="bi bi-hash me-1"></i>MLS: <?php echo htmlspecialchars($property_data['MLSNumber']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <!-- Rental Details Section (shown for rental properties) -->
        <?php if (in_array($status_value, ['For Rent', 'Pending Rented', 'Rented']) && $rental_data): ?>
        <div class="container-fluid px-0 mt-4">
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
            <div class="container-fluid px-0">
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
                                        Barangay
                                    </div>
                                    <div class="fact-value"><?php echo htmlspecialchars($property_data['Barangay'] ?? 'N/A'); ?></div>
                                </div>
                                <div class="fact-item">
                                    <div class="fact-label">
                                        <i class="bi bi-map"></i>
                                        Province
                                    </div>
                                    <div class="fact-value"><?php echo htmlspecialchars($property_data['Province']); ?></div>
                                </div>
                                <?php if (!empty($property_data['Source'])): ?>
                                <div class="fact-item">
                                    <div class="fact-label">
                                        <i class="bi bi-building"></i>
                                        Source (MLS)
                                    </div>
                                    <div class="fact-value"><?php echo htmlspecialchars($property_data['Source']); ?></div>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($property_data['MLSNumber'])): ?>
                                <div class="fact-item">
                                    <div class="fact-label">
                                        <i class="bi bi-hash"></i>
                                        MLS Number
                                    </div>
                                    <div class="fact-value"><?php echo htmlspecialchars($property_data['MLSNumber']); ?></div>
                                </div>
                                <?php endif; ?>
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
                                
                                <?php if ($is_property_sold): ?>
                                    <div class="sold-locked-notice">
                                        <i class="bi bi-lock-fill"></i>
                                        <div><strong>Property Sold</strong><br>This property has been marked as sold and is locked for editing to maintain historical accuracy.</div>
                                    </div>
                                <?php elseif ($is_pending_sold): ?>
                                    <div class="pending-sold-notice">
                                        <i class="bi bi-hourglass-split"></i>
                                        <div><strong>Pending Sale Verification</strong><br>A sale verification has been submitted and is awaiting review. <a href="admin_property_sale_approvals.php">Review here</a>.</div>
                                    </div>
                <?php elseif ($is_property_rented): ?>
                    <div class="sold-locked-notice">
                        <i class="bi bi-lock-fill"></i>
                        <div><strong>Property Rented</strong><br>This property has an active lease and is locked for editing to maintain lease integrity.</div>
                    </div>
                    <?php if ($is_admin_poster): ?>
                    <div class="d-grid gap-3 mt-3">
                        <a href="admin_lease_management.php?property_id=<?php echo $property_id_to_review; ?>" class="btn btn-modern btn-update">
                            <i class="bi bi-key me-2"></i>Manage Lease &amp; Payments
                        </a>
                    </div>
                    <?php endif; ?>
                <?php elseif ($is_pending_rented): ?>
                    <div class="pending-sold-notice">
                        <i class="bi bi-hourglass-split"></i>
                        <div><strong>Pending Rental Verification</strong><br>A rental verification has been submitted and is awaiting review. <a href="admin_rental_approvals.php">Review here</a>.</div>
                    </div>

                                <?php endif; ?>

                                <?php if (!$is_approved && !$is_property_sold && !$is_pending_sold && !$is_property_rented && !$is_pending_rented): ?>
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
                                <?php elseif ($is_approved && !$is_property_sold && !$is_pending_sold && !$is_property_rented && !$is_pending_rented): ?>
                                    <?php if ($is_admin_poster): ?>
                                    <div class="d-grid gap-3">
                                        <button type="button" class="btn btn-modern btn-update" data-bs-toggle="modal" data-bs-target="#updatePriceModal">
                                            <i class="bi bi-tag me-2"></i>Update Price
                                        </button>
                                        <button type="button" class="btn btn-modern btn-secondary-modern" onclick="openEditPropertyModal(<?php echo $property_id_to_review; ?>)">
                                            <i class="bi bi-pencil me-2"></i>Edit Details
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!$sale_status): ?>
                                    <div class="d-grid gap-3 mt-3">
                                        <button type="button" class="btn btn-modern btn-sold" data-bs-toggle="modal" data-bs-target="#markSoldModal">
                                            <i class="bi bi-check-circle me-2"></i><?php echo ($property_data['Status'] === 'For Rent') ? 'Mark as Rented' : 'Mark as Sold'; ?>
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!$is_admin_poster): ?>
                                    <div style="margin-top:0.75rem; padding:0.7rem 0.9rem; border-radius:4px; background:rgba(37,99,235,0.05); border:1px solid rgba(37,99,235,0.15); border-left:3px solid #2563eb; font-size:0.78rem; color:#1e40af; font-weight:500; display:flex; align-items:flex-start; gap:0.5rem;">
                                        <i class="bi bi-info-circle" style="flex-shrink:0; margin-top:0.05rem;"></i>
                                        <span>This listing is approved. Only the posting administrator can edit property details.</span>
                                    </div>
                                    <?php endif; ?>
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
                                            $listed_by_avatar = BASE_URL . 'images/placeholder-avatar.svg';
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

    </div><!-- /#page-content -->
</div><!-- /.admin-content -->

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

<!-- Mark as Sold Confirmation Modal -->
<div class="modal fade" id="markSoldModal" tabindex="-1" aria-labelledby="markSoldModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">

            <!-- Header -->
            <div class="modal-header">
                <h5 class="modal-title" id="markSoldModalLabel">
                    <i class="bi bi-patch-check-fill me-2"></i><?php echo ($property_data['Status'] === 'For Rent') ? 'Mark Property as Rented' : 'Mark Property as Sold'; ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Body -->
            <div class="modal-body">
                <?php $is_rental_property = ($property_data['Status'] === 'For Rent'); ?>
                <form id="msvSaleForm" enctype="multipart/form-data">
                    <input type="hidden" name="property_id" value="<?php echo $property_id_to_review; ?>">
                    <input type="hidden" name="verification_type" value="<?php echo $is_rental_property ? 'rental' : 'sale'; ?>">

                    <!-- Property Confirmation Banner -->
                    <div class="property-confirm-card">
                        <div class="property-confirm-icon"><i class="bi bi-house-check-fill"></i></div>
                        <div>
                            <div class="property-confirm-title">
                                <?php echo htmlspecialchars($property_data['PropertyType'] ?? 'Property'); ?>
                            </div>
                            <div class="property-confirm-address">
                                <i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars(($property_data['StreetAddress'] ?? '') . ', ' . ($property_data['City'] ?? '')); ?>
                            </div>
                        </div>
                        <div class="ms-auto text-end" style="flex-shrink:0;">
                            <?php if ($is_rental_property): ?>
                            <div style="font-size:0.7rem;color:#6c757d;text-transform:uppercase;letter-spacing:1px;">Monthly Rent</div>
                            <div style="font-size:1.1rem;font-weight:800;color:var(--gold-dark);">&#8369;<?php echo number_format($rental_data['monthly_rent'] ?? $property_data['ListingPrice'] ?? 0); ?></div>
                            <?php else: ?>
                            <div style="font-size:0.7rem;color:#6c757d;text-transform:uppercase;letter-spacing:1px;">Listing Price</div>
                            <div style="font-size:1.1rem;font-weight:800;color:var(--gold-dark);">&#8369;<?php echo number_format($property_data['ListingPrice'] ?? 0); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($is_rental_property): ?>
                    <!-- ===== RENTAL FIELDS ===== -->

                    <!-- Row 1: Lease Details -->
                    <div class="form-section-label"><i class="bi bi-calendar-range me-1"></i>Lease Details</div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label for="admin_monthly_rent" class="form-label">Monthly Rent (&#8369;) <span class="req">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">&#8369;</span>
                                <input type="number" step="0.01" min="1" class="form-control" id="admin_monthly_rent" name="monthly_rent"
                                       placeholder="0.00" value="<?php echo htmlspecialchars($rental_data['monthly_rent'] ?? ''); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label for="admin_security_deposit" class="form-label">Security Deposit (&#8369;)</label>
                            <div class="input-group">
                                <span class="input-group-text">&#8369;</span>
                                <input type="number" step="0.01" min="0" class="form-control" id="admin_security_deposit" name="security_deposit"
                                       placeholder="0.00" value="<?php echo htmlspecialchars($rental_data['security_deposit'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label for="admin_lease_term" class="form-label">Lease Term (Months) <span class="req">*</span></label>
                            <input type="number" min="1" max="120" class="form-control" id="admin_lease_term" name="lease_term_months"
                                   placeholder="e.g. 12" value="<?php echo htmlspecialchars($rental_data['lease_term_months'] ?? '12'); ?>" required>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="admin_lease_start" class="form-label">Lease Start Date <span class="req">*</span></label>
                            <input type="date" class="form-control" id="admin_lease_start" name="lease_start_date" required>
                        </div>
                        <div class="col-md-6">
                            <label for="admin_lease_end" class="form-label">Lease End Date</label>
                            <input type="date" class="form-control" id="admin_lease_end" name="lease_end_date" readonly>
                            <div class="form-text"><i class="bi bi-info-circle me-1"></i>Auto-calculated from start date &amp; term.</div>
                        </div>
                    </div>

                    <!-- Row 2: Tenant Info -->
                    <div class="form-section-label"><i class="bi bi-person-fill me-1"></i>Tenant Information</div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label for="admin_tenant_name" class="form-label">Tenant's Name <span class="req">*</span></label>
                            <input type="text" class="form-control" id="admin_tenant_name" name="tenant_name" placeholder="Tenant's full name" required>
                        </div>
                        <div class="col-md-4">
                            <label for="admin_tenant_email" class="form-label">Tenant's Email</label>
                            <input type="email" class="form-control" id="admin_tenant_email" name="tenant_email" placeholder="tenant@email.com">
                        </div>
                        <div class="col-md-4">
                            <label for="admin_tenant_phone" class="form-label">Tenant's Phone</label>
                            <input type="tel" class="form-control" id="admin_tenant_phone" name="tenant_phone" placeholder="+63 9XX XXX XXXX">
                        </div>
                    </div>

                    <!-- Row 3: Documents -->
                    <div class="form-section-label"><i class="bi bi-file-earmark-text-fill me-1"></i>Documentation</div>
                    <div class="row g-3 mb-3">
                        <div class="col-12">
                            <label for="msvSaleDocuments" class="form-label">Rental Documents <span class="req">*</span></label>
                            <input type="file" class="form-control" id="msvSaleDocuments" name="rental_documents[]" multiple required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                            <div class="form-text"><i class="bi bi-info-circle me-1"></i>Lease agreement, tenant ID, proof of deposit, etc. Max 120MB per file.</div>
                            <div id="msvDocPreview" class="msv-doc-preview doc-preview-grid mt-2"></div>
                        </div>
                    </div>

                    <?php else: ?>
                    <!-- ===== SALE FIELDS ===== -->

                    <!-- Row 1: Sale Details -->
                    <div class="form-section-label"><i class="bi bi-currency-exchange me-1"></i>Sale Details</div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="admin_sale_price" class="form-label">Sale Price (&#8369;) <span class="req">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">&#8369;</span>
                                <input type="number" step="0.01" min="1" class="form-control" id="admin_sale_price" name="sale_price"
                                       placeholder="0.00" value="<?php echo htmlspecialchars($property_data['ListingPrice'] ?? ''); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="admin_sale_date" class="form-label">Sale Date <span class="req">*</span></label>
                            <input type="date" class="form-control" id="admin_sale_date" name="sale_date" max="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>

                    <!-- Row 2: Buyer Info -->
                    <div class="form-section-label"><i class="bi bi-person-fill me-1"></i>Buyer Information</div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="admin_buyer_name" class="form-label">Buyer's Name <span class="req">*</span></label>
                            <input type="text" class="form-control" id="admin_buyer_name" name="buyer_name" placeholder="Buyer's full name" required>
                        </div>
                        <div class="col-md-6">
                            <label for="admin_buyer_email" class="form-label">Buyer's Email</label>
                            <input type="email" class="form-control" id="admin_buyer_email" name="buyer_email" placeholder="buyer@email.com">
                        </div>
                    </div>

                    <!-- Row 3: Documents -->
                    <div class="form-section-label"><i class="bi bi-file-earmark-text-fill me-1"></i>Documentation</div>
                    <div class="row g-3 mb-3">
                        <div class="col-12">
                            <label for="msvSaleDocuments" class="form-label">Sale Documents <span class="req">*</span></label>
                            <input type="file" class="form-control" id="msvSaleDocuments" name="sale_documents[]" multiple required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                            <div class="form-text"><i class="bi bi-info-circle me-1"></i>PDF, JPG, PNG, DOC accepted &mdash; max 120MB per file.</div>
                            <div id="msvDocPreview" class="msv-doc-preview doc-preview-grid mt-2"></div>
                        </div>
                    </div>

                    <?php endif; ?>

                    <!-- Row 4: Notes (shared) -->
                    <div class="row g-3 mb-2">
                        <div class="col-12">
                            <label for="admin_additional_notes" class="form-label">Additional Notes</label>
                            <textarea class="form-control" id="admin_additional_notes" name="additional_notes" rows="3" placeholder="Optional notes..."></textarea>
                        </div>
                    </div>

                </form>
            </div>

            <!-- Footer -->
            <div class="modal-footer d-flex justify-content-between align-items-center">
                <span style="font-size:0.75rem;color:#6c757d;"><i class="bi bi-shield-lock me-1" style="color:var(--gold-dark);"></i>Submission will be logged and reviewed.</span>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-cancel-sold" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-submit-sold" id="msvConfirmSaleBtn">
                        <i class="bi bi-patch-check-fill me-1"></i> Submit Verification
                    </button>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Lightbox Overlay -->
<div class="lightbox-overlay" id="lightboxOverlay" style="display:none;">
    <button class="lightbox-close" onclick="closeLightbox(event)"><i class="bi bi-x-lg"></i></button>
    <button class="lightbox-prev" onclick="changeImage(-1, event)"><i class="bi bi-chevron-left"></i></button>
    <button class="lightbox-next" onclick="changeImage(1, event)"><i class="bi bi-chevron-right"></i></button>
    <img src="" alt="Lightbox view" id="lightboxImage">
    <div class="lightbox-counter" id="lightboxCounter"></div>
    <div class="lightbox-label" id="lightboxLabel"></div>
</div>

<!-- Toast Container -->
<div id="toastContainer"></div>

<script src="<?= ASSETS_JS ?>bootstrap.bundle.min.js"></script>
<script src="<?= ASSETS_JS ?>chart.umd.min.js"></script>
<script>
    // ===== TOAST NOTIFICATION SYSTEM =====
    function showToast(type, title, message, duration) {
        duration = duration || 4500;
        const container = document.getElementById('toastContainer');
        const icons = {
            success: 'bi-check-circle-fill',
            error:   'bi-x-circle-fill',
            info:    'bi-info-circle-fill',
            warning: 'bi-exclamation-triangle-fill'
        };
        const toast = document.createElement('div');
        toast.className = `app-toast toast-${type}`;
        toast.innerHTML = `
            <div class="app-toast-icon"><i class="bi ${icons[type] || icons.info}"></i></div>
            <div class="app-toast-body">
                <div class="app-toast-title">${title}</div>
                <div class="app-toast-msg">${message}</div>
            </div>
            <button class="app-toast-close" onclick="dismissToast(this.closest('.app-toast'))">&times;</button>
            <div class="app-toast-progress" style="animation: toast-progress ${duration}ms linear forwards;"></div>
        `;
        container.appendChild(toast);
        const timer = setTimeout(() => dismissToast(toast), duration);
        toast._timer = timer;
    }
    function dismissToast(toast) {
        if (!toast || toast._dismissed) return;
        toast._dismissed = true;
        clearTimeout(toast._timer);
        toast.classList.add('toast-out');
        setTimeout(() => toast.remove(), 320);
    }

    // Property image data from PHP
    const featuredImages = <?php echo json_encode($property_images); ?>;
    const floorImagesRaw = <?php echo json_encode($floor_images); ?>;
    
    // Convert floor images to a usable object
    const floorImages = {};
    for (const [key, value] of Object.entries(floorImagesRaw || {})) {
        floorImages[key] = value;
    }
    
    // Gallery state
    let currentHeroView = 'featured';
    let currentHeroFloor = null;
    let currentLightboxImages = [];
    let currentLightboxIndex = 0;
    let currentLightboxLabel = 'Featured Images';

    // Update sidebar thumbnails when switching views
    function updateSidebar(images) {
        const sideImg0 = document.getElementById('sideImg0');
        const sideImg1 = document.getElementById('sideImg1');
        const sidebarItem0 = document.getElementById('sidebarItem0');
        const sidebarItem1 = document.getElementById('sidebarItem1');
        const moreOverlay = document.getElementById('moreOverlay');

        if (images.length >= 2 && sideImg0) {
            sideImg0.src = images[1];
            sidebarItem0.onclick = () => openLightbox(1);
            sidebarItem0.style.cursor = 'pointer';
        } else if (sidebarItem0) {
            sidebarItem0.innerHTML = '<div class="gallery-grid-placeholder"><i class="bi bi-image"></i></div>';
            sidebarItem0.onclick = null;
            sidebarItem0.style.cursor = 'default';
        }

        if (images.length >= 3 && sideImg1) {
            sideImg1.src = images[2];
            sidebarItem1.onclick = () => openLightbox(2);
            sidebarItem1.style.cursor = 'pointer';
        } else if (sidebarItem1) {
            const placeholder = '<div class="gallery-grid-placeholder"><i class="bi bi-image"></i></div>';
            sidebarItem1.innerHTML = placeholder;
            sidebarItem1.onclick = null;
            sidebarItem1.style.cursor = 'default';
        }

        // Update "+N more" overlay
        if (moreOverlay) moreOverlay.remove();
        if (images.length > 3 && sidebarItem1) {
            const overlay = document.createElement('div');
            overlay.className = 'gallery-more-overlay';
            overlay.id = 'moreOverlay';
            overlay.textContent = '+' + (images.length - 3);
            sidebarItem1.appendChild(overlay);
        }
    }

    // Switch hero view between featured and floor images
    function switchHeroView(viewType, floorNum = null) {
        const mainImage = document.getElementById('mainHeroImage');
        const pills = document.querySelectorAll('.view-selector-btn');
        
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
            currentLightboxLabel = 'Featured Images';
        } else if (viewType === 'floor' && floorImages[floorNum]) {
            imagesToShow = floorImages[floorNum];
            currentLightboxLabel = 'Floor ' + floorNum + ' Images';
        }
        
        if (imagesToShow.length > 0) {
            mainImage.src = imagesToShow[0];
            updateSidebar(imagesToShow);
        } else {
            console.warn('No images found for:', viewType, floorNum);
            // Show placeholder if no images
            mainImage.src = '<?= BASE_URL ?>images/placeholder.svg';
            updateSidebar([]);
        }
    }

    // Lightbox functions
    function openLightbox(index) {
        if (currentHeroView === 'featured') {
            currentLightboxImages = featuredImages;
            currentLightboxLabel = 'Featured Images';
        } else if (currentHeroView === 'floor' && floorImages[currentHeroFloor]) {
            currentLightboxImages = floorImages[currentHeroFloor];
            currentLightboxLabel = 'Floor ' + currentHeroFloor + ' Images';
        }
        
        if (currentLightboxImages.length === 0) return;
        
        currentLightboxIndex = index;
        document.getElementById('lightboxOverlay').style.display = 'flex';
        document.body.style.overflow = 'hidden';
        updateLightboxImage();
    }

    function closeLightbox(event) {
        if (event) event.stopPropagation();
        document.getElementById('lightboxOverlay').style.display = 'none';
        document.body.style.overflow = '';
    }

    function changeImage(direction, event) {
        if (event) event.stopPropagation();
        currentLightboxIndex += direction;
        if (currentLightboxIndex < 0) currentLightboxIndex = currentLightboxImages.length - 1;
        if (currentLightboxIndex >= currentLightboxImages.length) currentLightboxIndex = 0;
        updateLightboxImage();
    }

    function updateLightboxImage() {
        const img = document.getElementById('lightboxImage');
        const counter = document.getElementById('lightboxCounter');
        const label = document.getElementById('lightboxLabel');
        
        img.src = currentLightboxImages[currentLightboxIndex];
        counter.textContent = (currentLightboxIndex + 1) + ' / ' + currentLightboxImages.length;
        label.textContent = currentLightboxLabel;
    }

    // Keyboard navigation for lightbox
    document.addEventListener('keydown', function(e) {
        const overlay = document.getElementById('lightboxOverlay');
        if (overlay.style.display !== 'flex') return;
        
        if (e.key === 'ArrowLeft') {
            e.preventDefault();
            changeImage(-1);
        } else if (e.key === 'ArrowRight') {
            e.preventDefault();
            changeImage(1);
        } else if (e.key === 'Escape') {
            e.preventDefault();
            closeLightbox();
        }
    });

    // Close lightbox when clicking overlay background
    document.getElementById('lightboxOverlay')?.addEventListener('click', function(e) {
        if (e.target === this) {
            closeLightbox();
        }
    });

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
    
    // === Lease End Date Auto-calculation (Rental properties) ===
    (function() {
        const startInput = document.getElementById('admin_lease_start');
        const termInput = document.getElementById('admin_lease_term');
        const endInput = document.getElementById('admin_lease_end');
        if (!startInput || !termInput || !endInput) return;
        function calcEnd() {
            const start = startInput.value;
            const months = parseInt(termInput.value);
            if (!start || !months || months < 1) { endInput.value = ''; return; }
            const d = new Date(start);
            d.setMonth(d.getMonth() + months);
            d.setDate(d.getDate() - 1); // lease ends the day before the Nth month anniversary
            endInput.value = d.toISOString().split('T')[0];
        }
        startInput.addEventListener('change', calcEnd);
        termInput.addEventListener('input', calcEnd);
    })();

    // === Mark as Sold — File Preview Logic ===
    (function() {
        const fileInput = document.getElementById('msvSaleDocuments');
        const previewGrid = document.getElementById('msvDocPreview');
        if (!fileInput || !previewGrid) return;

        let selectedFiles = [];

        fileInput.addEventListener('change', function() {
            // Merge newly picked files into selectedFiles (avoid exact duplicates by name+size)
            Array.from(fileInput.files).forEach(f => {
                const dup = selectedFiles.some(s => s.name === f.name && s.size === f.size);
                if (!dup) selectedFiles.push(f);
            });
            syncInputAndRender();
        });

        function syncInputAndRender() {
            // Write selectedFiles back to the input via DataTransfer
            const dt = new DataTransfer();
            selectedFiles.forEach(f => dt.items.add(f));
            fileInput.files = dt.files;
            renderPreviews();
        }

        function renderPreviews() {
            previewGrid.innerHTML = '';
            if (!selectedFiles.length) return;
            selectedFiles.forEach((file, idx) => {
                const item = document.createElement('div');
                item.className = 'doc-preview-item';

                const ext = file.name.split('.').pop().toLowerCase();
                const isImage = ['jpg','jpeg','png','gif','webp'].includes(ext);

                if (isImage) {
                    const img = document.createElement('img');
                    img.className = 'doc-preview-thumb';
                    img.alt = file.name;
                    const reader = new FileReader();
                    reader.onload = e => { img.src = e.target.result; };
                    reader.readAsDataURL(file);
                    item.appendChild(img);
                } else {
                    const iconDiv = document.createElement('div');
                    let iconClass = 'other', iconName = 'bi-file-earmark';
                    if (ext === 'pdf') { iconClass = 'pdf'; iconName = 'bi-file-earmark-pdf-fill'; }
                    else if (['doc','docx'].includes(ext)) { iconClass = 'word'; iconName = 'bi-file-earmark-word-fill'; }
                    iconDiv.className = `doc-preview-icon ${iconClass}`;
                    iconDiv.innerHTML = `<i class="bi ${iconName}"></i>`;
                    item.appendChild(iconDiv);
                }

                const nameDiv = document.createElement('div');
                nameDiv.className = 'doc-preview-name';
                nameDiv.title = file.name;
                nameDiv.textContent = file.name;
                item.appendChild(nameDiv);

                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'doc-preview-remove';
                removeBtn.title = 'Remove';
                removeBtn.innerHTML = '<i class="bi bi-x"></i>';
                removeBtn.addEventListener('click', function() {
                    selectedFiles.splice(idx, 1);
                    syncInputAndRender();
                });
                item.appendChild(removeBtn);

                previewGrid.appendChild(item);
            });
        }

        // Reset previews when modal is closed
        const markSoldModal = document.getElementById('markSoldModal');
        if (markSoldModal) {
            markSoldModal.addEventListener('hidden.bs.modal', function() {
                selectedFiles = [];
                previewGrid.innerHTML = '';
                fileInput.value = '';
            });
        }
    })();

    // === Mark as Sold/Rented Modal Logic ===
    const isRentalProperty = <?php echo json_encode($property_data['Status'] === 'For Rent'); ?>;
    const msvConfirmSaleBtn = document.getElementById('msvConfirmSaleBtn');
    if (msvConfirmSaleBtn) {
        msvConfirmSaleBtn.addEventListener('click', function() {
            const form = document.getElementById('msvSaleForm');

            // Common file validation
            const allowedTypes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
            const maxFileSize = 120 * 1024 * 1024; // 120MB

            if (isRentalProperty) {
                // ----- RENTAL VALIDATION -----
                const monthlyRent = parseFloat((form.querySelector('[name="monthly_rent"]') || {}).value);
                const leaseStart = (form.querySelector('[name="lease_start_date"]') || {}).value;
                const leaseTerm = parseInt((form.querySelector('[name="lease_term_months"]') || {}).value);
                const tenantName = (form.querySelector('[name="tenant_name"]') || {value:''}).value.trim();
                const rentalDocs = form.querySelector('[name="rental_documents[]"]');
                const docFiles = rentalDocs ? rentalDocs.files : [];

                if (!monthlyRent || monthlyRent <= 0) {
                    showMsvSaleAlert('danger', 'Please enter a valid monthly rent.');
                    return;
                }
                if (!leaseStart) {
                    showMsvSaleAlert('danger', 'Please select a lease start date.');
                    return;
                }
                if (!leaseTerm || leaseTerm < 1 || leaseTerm > 120) {
                    showMsvSaleAlert('danger', 'Lease term must be between 1 and 120 months.');
                    return;
                }
                if (!tenantName) {
                    showMsvSaleAlert('danger', 'Please enter the tenant\'s name.');
                    return;
                }
                if (!docFiles || docFiles.length === 0) {
                    showMsvSaleAlert('danger', 'Please upload at least one rental document.');
                    return;
                }
                for (let i = 0; i < docFiles.length; i++) {
                    if (!allowedTypes.includes(docFiles[i].type)) {
                        showMsvSaleAlert('danger', 'Invalid file type: ' + docFiles[i].name + '. Allowed: PDF, JPG, PNG, DOC.');
                        return;
                    }
                    if (docFiles[i].size > maxFileSize) {
                        showMsvSaleAlert('danger', 'File too large: ' + docFiles[i].name + ' (max 120MB).');
                        return;
                    }
                }
            } else {
                // ----- SALE VALIDATION -----
                const salePrice = parseFloat(form.querySelector('[name="sale_price"]').value);
                const saleDate = form.querySelector('[name="sale_date"]').value;
                const buyerName = form.querySelector('[name="buyer_name"]').value.trim();
                const saleDocuments = form.querySelector('[name="sale_documents[]"]').files;

                if (!salePrice || salePrice <= 0) {
                    showMsvSaleAlert('danger', 'Please enter a valid sale price.');
                    return;
                }
                if (!saleDate) {
                    showMsvSaleAlert('danger', 'Please select a sale date.');
                    return;
                }
                const today = new Date();
                today.setHours(23, 59, 59, 999);
                if (new Date(saleDate) > today) {
                    showMsvSaleAlert('danger', 'Sale date cannot be in the future.');
                    return;
                }
                if (!buyerName) {
                    showMsvSaleAlert('danger', 'Please enter the buyer\'s name.');
                    return;
                }
                if (!saleDocuments || saleDocuments.length === 0) {
                    showMsvSaleAlert('danger', 'Please upload at least one sale document.');
                    return;
                }
                for (let i = 0; i < saleDocuments.length; i++) {
                    if (!allowedTypes.includes(saleDocuments[i].type)) {
                        showMsvSaleAlert('danger', 'Invalid file type: ' + saleDocuments[i].name + '. Allowed: PDF, JPG, PNG, DOC.');
                        return;
                    }
                    if (saleDocuments[i].size > maxFileSize) {
                        showMsvSaleAlert('danger', 'File too large: ' + saleDocuments[i].name + ' (max 120MB).');
                        return;
                    }
                }
            }
            
            // Disable button and show loading
            msvConfirmSaleBtn.disabled = true;
            msvConfirmSaleBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Submitting...';
            
            // Submit via fetch — route to correct backend
            const formData = new FormData(form);
            const endpoint = isRentalProperty ? 'admin_mark_as_rented_process.php' : 'admin_mark_as_sold_process.php';
            
            fetch(endpoint, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(response => {
                const contentType = response.headers.get('content-type') || '';
                if (contentType.includes('application/json')) {
                    return response.json();
                }
                // Non-JSON response — likely a PHP error page
                return response.text().then(text => {
                    console.error('Non-JSON response from server:', text);
                    return { success: false, message: 'Server error. Check console for details.' };
                });
            })
            .then(data => {
                if (data.success) {
                    showMsvSaleAlert('success', data.message);
                    // Redirect after a short delay
                    setTimeout(() => {
                        window.location.href = 'view_property.php?id=<?php echo $property_id_to_review; ?>&status=success&msg=' + encodeURIComponent(data.message);
                    }, 1500);
                } else {
                    showMsvSaleAlert('danger', data.message || 'An error occurred.');
                    msvConfirmSaleBtn.disabled = false;
                    msvConfirmSaleBtn.innerHTML = '<i class="bi bi-patch-check-fill me-1"></i> Submit Verification';
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
                showMsvSaleAlert('danger', 'Network error: ' + (error.message || 'Please try again.'));
                msvConfirmSaleBtn.disabled = false;
                msvConfirmSaleBtn.innerHTML = '<i class="bi bi-patch-check-fill me-1"></i> Submit Verification';
            });
        });
    }
    
    function showMsvSaleAlert(type, message) {
        const toastType = (type === 'danger') ? 'error' : type;
        const toastTitle = (type === 'danger') ? 'Validation Error' : 'Success';
        showToast(toastType, toastTitle, message, type === 'danger' ? 5000 : 6000);
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
            <?php if ($is_property_sold): ?>
                <div class="sold-locked-notice" style="margin: 0;">
                    <i class="bi bi-lock-fill"></i>
                    <div><strong>Sold</strong> — Property is locked.</div>
                </div>
            <?php elseif ($is_pending_sold): ?>
                <div class="pending-sold-notice" style="margin: 0;">
                    <i class="bi bi-hourglass-split"></i>
                    <div><strong>Pending</strong> — Sale verification in review.</div>
                </div>
            <?php elseif (!$is_approved): ?>
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
            <?php elseif ($is_approved): ?>
                <?php if ($is_admin_poster): ?>
                <button type="button" class="btn btn-modern btn-update w-100" data-bs-toggle="modal" data-bs-target="#updatePriceModal">
                    <i class="bi bi-tag me-2"></i>Update Price
                </button>
                <button type="button" class="btn btn-modern btn-secondary-modern w-100" onclick="openEditPropertyModal(<?php echo $property_id_to_review; ?>)">
                    <i class="bi bi-pencil me-2"></i>Edit
                </button>
                <?php endif; ?>
                <?php if (!$sale_status): ?>
                <button type="button" class="btn btn-modern btn-sold w-100" data-bs-toggle="modal" data-bs-target="#markSoldModal">
                    <i class="bi bi-check-circle me-2"></i><?php echo ($property_data['Status'] === 'For Rent') ? 'Mark as Rented' : 'Mark as Sold'; ?>
                </button>
                <?php endif; ?>
            <?php endif; ?>
            <a href="property.php" class="btn btn-modern btn-secondary-modern w-100">
                <i class="bi bi-arrow-left me-2"></i>Back
            </a>
        </div>
    </div>
</div>

<!-- Include Edit Property Modal -->
<?php include 'modals/edit_property_modal.php'; ?>

<!-- SKELETON HYDRATION — Progressive Content Reveal -->
<script>
(function () {
    'use strict';
    var MIN_SKELETON_MS = 400;
    var skeletonStart   = Date.now();
    var hydrated        = false;

    function hydrate() {
        if (hydrated) return;
        hydrated = true;

        var sk = document.getElementById('sk-screen');
        var pc = document.getElementById('page-content');
        if (!sk || !pc) return;

        /* Fade skeleton OUT */
        sk.style.transition = 'opacity 0.35s ease';
        sk.style.opacity    = '0';
        setTimeout(function () { sk.style.display = 'none'; }, 360);

        /* Fade real content IN */
        pc.style.opacity    = '0';
        pc.style.display    = 'block';
        requestAnimationFrame(function () {
            pc.style.transition = 'opacity 0.4s ease';
            pc.style.opacity    = '1';
        });

        /* Notify deferred UI (toasts, etc.) */
        setTimeout(function () {
            document.dispatchEvent(new CustomEvent('skeleton:hydrated'));
        }, 520);
    }

    function scheduleHydration() {
        var elapsed = Date.now() - skeletonStart;
        var remaining = MIN_SKELETON_MS - elapsed;
        if (remaining <= 0) {
            hydrate();
        } else {
            setTimeout(hydrate, remaining);
        }
    }

    window.addEventListener('load', scheduleHydration);

}());
</script>

</body>
</html>
