<?php
require_once __DIR__ . '/mail_helper.php';

// --- Security Check: Ensure admin is logged in ---
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$admin_account_id = $_SESSION['account_id'];
$admin_username = $_SESSION['username'];
$agent_data = null;
$error_message = '';
$success_message = '';

$account_id_to_review = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;
if ($account_id_to_review <= 0) {
    header("Location: agent.php");
    exit();
}

if (isset($_GET['status']) && isset($_GET['msg'])) {
    if ($_GET['status'] === 'success') $success_message = htmlspecialchars(urldecode($_GET['msg']));
    if ($_GET['status'] === 'error') $error_message = htmlspecialchars(urldecode($_GET['msg']));
}

// --- Handle Form Submission (Approve/Reject) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    // Debug logging
    error_log("POST received in review_agent_details_process.php");
    error_log("POST data: " . print_r($_POST, true));
    
    $action = $_POST['action'];
    $posted_account_id = $_POST['account_id'] ?? 0;
    $rejection_reason = $_POST['rejection_reason'] ?? '';

    error_log("Action: " . $action);
    error_log("Posted Account ID: " . $posted_account_id);
    error_log("Account ID to review: " . $account_id_to_review);

    if ($posted_account_id != $account_id_to_review) {
        error_log("Security Alert: Mismatched ID");
        header("Location: review_agent_details.php?account_id={$account_id_to_review}&status=error&msg=" . urlencode("Security Alert: Mismatched ID."));
        exit();
    }

    // Fetch agent email for notification before the transaction
    $stmt_email = $conn->prepare("SELECT email, first_name FROM accounts WHERE account_id = ?");
    $stmt_email->bind_param("i", $posted_account_id);
    $stmt_email->execute();
    $agent_email_result = $stmt_email->get_result()->fetch_assoc();
    $agent_email = $agent_email_result['email'];
    $agent_first_name = $agent_email_result['first_name'];
    $stmt_email->close();

    $conn->begin_transaction();
    try {
        if ($action === 'approve') {
            error_log("Starting approve process for account_id: " . $posted_account_id);
            
            $stmt_update = $conn->prepare("UPDATE agent_information SET is_approved = 1 WHERE account_id = ?");
            $stmt_update->bind_param("i", $posted_account_id);
            $stmt_update->execute();
            $affected_rows = $stmt_update->affected_rows;
            error_log("Updated agent_information: " . $affected_rows . " rows affected");
            $stmt_update->close();

            // Make sure the account is active
            $stmt_active = $conn->prepare("UPDATE accounts SET is_active = 1 WHERE account_id = ?");
            $stmt_active->bind_param("i", $posted_account_id);
            $stmt_active->execute();
            $affected_rows = $stmt_active->affected_rows;
            error_log("Updated accounts is_active: " . $affected_rows . " rows affected");
            $stmt_active->close();

            $stmt_log = $conn->prepare("INSERT INTO status_log (item_id, item_type, action, action_by_account_id) VALUES (?, 'agent', 'approved', ?)");
            $stmt_log->bind_param("ii", $posted_account_id, $admin_account_id);
            $stmt_log->execute();
            error_log("Status log created");
            $stmt_log->close();
            
            // --- Send Approval Email (Modern Dark Theme) ---
            $subject = 'Agent Application Approved - Welcome to HomeEstate Realty';
            $html = '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application Approved</title>
</head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,\'Helvetica Neue\',Arial,sans-serif;background-color:#0a0a0a;line-height:1.6;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#0a0a0a;padding:60px 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color:#111111;border:1px solid #1f1f1f;border-radius:4px;max-width:600px;">
                    <tr>
                        <td style="background:linear-gradient(90deg,#22c55e 0%,#16a34a 50%,#22c55e 100%);height:3px;"></td>
                    </tr>
                    <tr>
                        <td style="padding:48px 48px 32px 48px;text-align:center;border-bottom:1px solid #1f1f1f;">
                            <h1 style="margin:0 0 12px 0;color:#22c55e;font-size:14px;font-weight:600;text-transform:uppercase;letter-spacing:3px;">Application Approved</h1>
                            <p style="margin:0;color:#666666;font-size:15px;font-weight:400;">Welcome to our team of agents</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:48px 48px 40px 48px;">
                            <p style="margin:0 0 24px 0;font-size:14px;color:#999999;line-height:1.7;">
                                Hello <span style="color:#d4af37;font-weight:500;">' . htmlspecialchars($agent_first_name) . '</span>,
                            </p>
                            <p style="margin:0 0 32px 0;font-size:15px;color:#cccccc;line-height:1.8;">
                                Congratulations! We are thrilled to inform you that your agent application has been <strong style="color:#22c55e;">approved</strong>. You are now an official member of the HomeEstate Realty team.
                            </p>
                            <div style="text-align:center;margin:0 0 40px 0;">
                                <div style="display:inline-block;background-color:#0d1117;border:1px solid #22c55e;border-radius:2px;padding:28px 40px;">
                                    <p style="margin:0 0 12px 0;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:2px;color:#22c55e;">Status</p>
                                    <div style="font-size:24px;font-weight:700;color:#22c55e;">
                                        ✓ ACTIVE AGENT
                                    </div>
                                    <p style="margin:12px 0 0 0;font-size:12px;color:#666666;">You can now access your dashboard</p>
                                </div>
                            </div>
                            <div style="height:1px;background-color:#1f1f1f;margin:0 0 32px 0;"></div>
                            <div style="background-color:#0d1117;border-left:2px solid #d4af37;padding:20px 24px;margin:0 0 32px 0;">
                                <p style="margin:0 0 16px 0;font-size:13px;color:#d4af37;font-weight:600;text-transform:uppercase;letter-spacing:1px;">Next Steps</p>
                                <ul style="margin:0;padding:0 0 0 20px;font-size:14px;color:#999999;line-height:2;">
                                    <li>Log in to your agent dashboard</li>
                                    <li>Complete your profile details</li>
                                    <li>Start adding property listings</li>
                                    <li>Respond to tour requests from buyers</li>
                                </ul>
                            </div>
                            <div style="background-color:#0d1117;border-left:2px solid #2563eb;padding:16px 20px;margin:0 0 24px 0;">
                                <p style="margin:0;font-size:1 3px;color:#999999;line-height:1.6;">
                                    <strong style="color:#2563eb;display:block;margin-bottom:6px;font-size:12px;text-transform:uppercase;letter-spacing:1px;">Need Help?</strong>
                                    If you have any questions or need assistance getting started, please don\'t hesitate to contact our support team.
                                </p>
                            </div>
                            <p style="margin:0;font-size:14px;color:#999999;line-height:1.7;">
                                Best regards,<br>
                                <strong style="color:#d4af37;">The HomeEstate Realty Team</strong>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color:#0a0a0a;padding:32px 48px;border-top:1px solid #1f1f1f;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="text-align:center;">
                                        <p style="margin:0 0 8px 0;font-size:13px;color:#666666;">
                                            <strong style="color:#d4af37;">HomeEstate Realty</strong>
                                        </p>
                                        <p style="margin:0 0 4px 0;font-size:11px;color:#444444;">
                                            Cagayan De Oro City, Northern Mindanao, Philippines
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
            </td>
        </tr>
    </table>
</body>
</html>
';
            $alt = "Dear {$agent_first_name},\n\nCongratulations! We are pleased to inform you that your agent application has been approved.\n\nYou are now an active agent! You can now log in to your agent dashboard and start managing properties, responding to tour requests, and connecting with potential buyers.\n\nNext Steps:\n- Log in to your agent dashboard\n- Complete your agent profile (if not already done)\n- Start adding properties\n- Respond to tour requests from potential buyers\n\nIf you have any questions or need assistance, please don't hesitate to contact our support team.\n\nBest regards,\nThe HomeEstate Realty Team";
            
            error_log("Sending approval email to: " . $agent_email);
            $send = sendSystemMail($agent_email, $agent_first_name, $subject, $html, $alt);
            error_log("Email sent result: " . ($send ? 'Success' : 'Failed'));
            
            $success_message_redirect = "Agent approved successfully and notification email has been sent!";

        } elseif ($action === 'reject') {
            if (empty($rejection_reason)) {
                throw new Exception("Rejection reason is required.");
            }
            $stmt_update = $conn->prepare("UPDATE agent_information SET is_approved = 0 WHERE account_id = ?");
            $stmt_update->bind_param("i", $posted_account_id);
            $stmt_update->execute();
            $stmt_update->close();

            // Also set account to inactive
            $stmt_inactive = $conn->prepare("UPDATE accounts SET is_active = 0 WHERE account_id = ?");
            $stmt_inactive->bind_param("i", $posted_account_id);
            $stmt_inactive->execute();
            $stmt_inactive->close();

            $stmt_log = $conn->prepare("INSERT INTO status_log (item_id, item_type, action, reason_message, action_by_account_id) VALUES (?, 'agent', 'rejected', ?, ?)");
            $stmt_log->bind_param("isi", $posted_account_id, $rejection_reason, $admin_account_id);
            $stmt_log->execute();
            $stmt_log->close();
            
            // --- Send Rejection Email (Modern Dark Theme) ---
            $subject = 'Agent Application Update - HomeEstate Realty';
            $html = '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application Update</title>
</head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,\'Helvetica Neue\',Arial,sans-serif;background-color:#0a0a0a;line-height:1.6;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#0a0a0a;padding:60px 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color:#111111;border:1px solid #1f1f1f;border-radius:4px;max-width:600px;">
                    <tr>
                        <td style="background:linear-gradient(90deg,#d4af37 0%,#f4d03f 50%,#d4af37 100%);height:3px;"></td>
                    </tr>
                    <tr>
                        <td style="padding:48px 48px 32px 48px;text-align:center;border-bottom:1px solid #1f1f1f;">
                            <h1 style="margin:0 0 12px 0;color:#d4af37;font-size:14px;font-weight:600;text-transform:uppercase;letter-spacing:3px;">Application Update</h1>
                            <p style="margin:0;color:#666666;font-size:15px;font-weight:400;">Regarding your agent application</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:48px 48px 40px 48px;">
                            <p style="margin:0 0 24px 0;font-size:14px;color:#999999;line-height:1.7;">
                                Hello <span style="color:#d4af37;font-weight:500;">' . htmlspecialchars($agent_first_name) . '</span>,
                            </p>
                            <p style="margin:0 0 32px 0;font-size:15px;color:#cccccc;line-height:1.8;">
                                Thank you for your interest in joining HomeEstate Realty. After a careful review of your application, we regret to inform you that we are unable to proceed at this time.
                            </p>
                            <div style="height:1px;background-color:#1f1f1f;margin:0 0 32px 0;"></div>
                            <div style="background-color:#0d1117;border-left:2px solid #d4af37;padding:20px 24px;margin:0 0 32px 0;">
                                <p style="margin:0 0 12px 0;font-size:13px;color:#d4af37;font-weight:600;text-transform:uppercase;letter-spacing:1px;">Reason for Decision</p>
                                <p style="margin:0;font-size:14px;color:#999999;line-height:1.7;font-style:italic;">
                                    "' . htmlspecialchars($rejection_reason) . '"
                                </p>
                            </div>
                            <p style="margin:0 0 24px 0;font-size:14px;color:#999999;line-height:1.7;">
                                We appreciate the time and effort you put into your application. We wish you the best in your future endeavors.
                            </p>
                            <div style="height:1px;background-color:#1f1f1f;margin:0 0 24px 0;"></div>
                            <p style="margin:0;font-size:14px;color:#999999;line-height:1.7;">
                                Sincerely,<br>
                                <strong style="color:#d4af37;">The HomeEstate Realty Team</strong>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color:#0a0a0a;padding:32px 48px;border-top:1px solid #1f1f1f;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="text-align:center;">
                                        <p style="margin:0 0 8px 0;font-size:13px;color:#666666;">
                                            <strong style="color:#d4af37;">HomeEstate Realty</strong>
                                        </p>
                                        <p style="margin:0 0 4px 0;font-size:11px;color:#444444;">
                                            Cagayan De Oro City, Northern Mindanao, Philippines
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
            </td>
        </tr>
    </table>
</body>
</html>
';
            $alt = "Dear {$agent_first_name},\n\nThank you for your interest in joining our team. After a careful review of your application, we regret to inform you that we cannot proceed at this time.\n\nReason for decision:\n\"" . htmlspecialchars($rejection_reason) . "\"\n\nWe wish you the best in your future endeavors.\n\nSincerely,\nThe Admin Team";
            $send = sendSystemMail($agent_email, $agent_first_name, $subject, $html, $alt);
            
            $success_message_redirect = "Agent rejected successfully and an email has been sent.";
            
        } elseif ($action === 'disable') {
            // Handle disabling an approved agent account
            if (empty($_POST['disable_reason'])) {
                throw new Exception("Disable reason is required.");
            }
            $disable_reason = $_POST['disable_reason'];
            
            error_log("Starting disable process for account_id: " . $posted_account_id);
            
            // Set account to inactive (keep is_approved as 1 but set is_active to 0)
            $stmt_deactivate = $conn->prepare("UPDATE accounts SET is_active = 0 WHERE account_id = ?");
            $stmt_deactivate->bind_param("i", $posted_account_id);
            $stmt_deactivate->execute();
            $affected_rows = $stmt_deactivate->affected_rows;
            error_log("Updated accounts is_active to 0: " . $affected_rows . " rows affected");
            $stmt_deactivate->close();

            // Log the disable action
            $stmt_log = $conn->prepare("INSERT INTO status_log (item_id, item_type, action, reason_message, action_by_account_id) VALUES (?, 'agent', 'disabled', ?, ?)");
            $stmt_log->bind_param("isi", $posted_account_id, $disable_reason, $admin_account_id);
            $stmt_log->execute();
            error_log("Status log created for disable action");
            $stmt_log->close();
            
            // --- Send Disable Notification Email (Modern Dark Theme) ---
            $subject = 'Account Status Update - HomeEstate Realty';
            $html = '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Disabled</title>
</head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,\'Helvetica Neue\',Arial,sans-serif;background-color:#0a0a0a;line-height:1.6;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#0a0a0a;padding:60px 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color:#111111;border:1px solid #1f1f1f;border-radius:4px;max-width:600px;">
                    <tr>
                        <td style="background:linear-gradient(90deg,#ef4444 0%,#dc2626 50%,#ef4444 100%);height:3px;"></td>
                    </tr>
                    <tr>
                        <td style="padding:48px 48px 32px 48px;text-align:center;border-bottom:1px solid #1f1f1f;">
                            <h1 style="margin:0 0 12px 0;color:#ef4444;font-size:14px;font-weight:600;text-transform:uppercase;letter-spacing:3px;">Account Disabled</h1>
                            <p style="margin:0;color:#666666;font-size:15px;font-weight:400;">Important account status update</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:48px 48px 40px 48px;">
                            <p style="margin:0 0 24px 0;font-size:14px;color:#999999;line-height:1.7;">
                                Hello <span style="color:#d4af37;font-weight:500;">' . htmlspecialchars($agent_first_name) . '</span>,
                            </p>
                            <p style="margin:0 0 32px 0;font-size:15px;color:#cccccc;line-height:1.8;">
                                We are writing to inform you that your agent account with HomeEstate Realty has been <strong style="color:#ef4444;">disabled</strong> by our administration team.
                            </p>
                            <div style="text-align:center;margin:0 0 40px 0;">
                                <div style="display:inline-block;background-color:#0d1117;border:1px solid #ef4444;border-radius:2px;padding:28px 40px;">
                                    <p style="margin:0 0 12px 0;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:2px;color:#ef4444;">Account Status</p>
                                    <div style="font-size:24px;font-weight:700;color:#ef4444;">
                                        ✕ DISABLED
                                    </div>
                                    <p style="margin:12px 0 0 0;font-size:12px;color:#666666;">You cannot access your account</p>
                                </div>
                            </div>
                            <div style="height:1px;background-color:#1f1f1f;margin:0 0 32px 0;"></div>
                            <div style="background-color:#0d1117;border-left:2px solid #ef4444;padding:20px 24px;margin:0 0 32px 0;">
                                <p style="margin:0 0 12px 0;font-size:13px;color:#ef4444;font-weight:600;text-transform:uppercase;letter-spacing:1px;">Reason for Disabling</p>
                                <p style="margin:0;font-size:14px;color:#999999;line-height:1.7;font-style:italic;">
                                    "' . htmlspecialchars($disable_reason) . '"
                                </p>
                            </div>
                            <div style="background-color:#0d1117;border-left:2px solid #d4af37;padding:16px 20px;margin:0 0 24px 0;">
                                <p style="margin:0;font-size:13px;color:#999999;line-height:1.6;">
                                    <strong style="color:#d4af37;display:block;margin-bottom:6px;font-size:12px;text-transform:uppercase;letter-spacing:1px;">What This Means</strong>
                                    Your account has been temporarily disabled. You will not be able to log in or access any agent features until your account is reactivated by an administrator.
                                </p>
                            </div>
                            <p style="margin:0 0 24px 0;font-size:14px;color:#999999;line-height:1.7;">
                                If you believe this action was taken in error or would like to discuss reactivating your account, please contact our support team.
                            </p>
                            <div style="height:1px;background-color:#1f1f1f;margin:0 0 24px 0;"></div>
                            <p style="margin:0;font-size:14px;color:#999999;line-height:1.7;">
                                Sincerely,<br>
                                <strong style="color:#d4af37;">The HomeEstate Realty Team</strong>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color:#0a0a0a;padding:32px 48px;border-top:1px solid #1f1f1f;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="text-align:center;">
                                        <p style="margin:0 0 8px 0;font-size:13px;color:#666666;">
                                            <strong style="color:#d4af37;">HomeEstate Realty</strong>
                                        </p>
                                        <p style="margin:0 0 4px 0;font-size:11px;color:#444444;">
                                            Cagayan De Oro City, Northern Mindanao, Philippines
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
            </td>
        </tr>
    </table>
</body>
</html>
';
            $alt = "Dear {$agent_first_name},\n\nWe are writing to inform you that your agent account with HomeEstate Realty has been disabled by our administration team.\n\nAccount Status: DISABLED\n\nReason for disabling:\n\"" . htmlspecialchars($disable_reason) . "\"\n\nWhat This Means:\nYour account has been temporarily disabled. You will not be able to log in or access any agent features until your account is reactivated by an administrator.\n\nIf you believe this action was taken in error or would like to discuss reactivating your account, please contact our support team.\n\nSincerely,\nThe HomeEstate Realty Team";
            
            error_log("Sending disable notification email to: " . $agent_email);
            $send = sendSystemMail($agent_email, $agent_first_name, $subject, $html, $alt);
            error_log("Email sent result: " . ($send ? 'Success' : 'Failed'));
            
            $success_message_redirect = "Agent account has been disabled successfully and notification email has been sent!";
        }
        
        error_log("Committing transaction...");
        $conn->commit();
        error_log("Transaction committed successfully");
        error_log("Redirecting with success message: " . $success_message_redirect);
        header("Location: review_agent_details.php?account_id={$account_id_to_review}&status=success&msg=" . urlencode($success_message_redirect));
        exit();

    } catch (Exception $e) {
        error_log("Exception caught: " . $e->getMessage());
        $conn->rollback();
        error_log("Transaction rolled back");
        $error_redirect = "An error occurred: " . $e->getMessage();
        header("Location: review_agent_details.php?account_id={$account_id_to_review}&status=error&msg=" . urlencode($error_redirect));
        exit();
    }
}


// --- Fetch Agent Details for Display ---
$sql_fetch_agent = "SELECT a.account_id, a.first_name, a.middle_name, a.last_name, a.email, a.phone_number, a.date_registered, a.username,
                           ai.license_number, ai.specialization, ai.years_experience,
                           ai.bio, ai.profile_picture_url,
                           ai.profile_completed, ai.is_approved
                    FROM accounts a
                    LEFT JOIN agent_information ai ON a.account_id = ai.account_id
                    WHERE a.account_id = ? AND a.role_id = (SELECT role_id FROM user_roles WHERE role_name = 'agent')";

$stmt_fetch_agent = $conn->prepare($sql_fetch_agent);
$stmt_fetch_agent->bind_param("i", $account_id_to_review);
$stmt_fetch_agent->execute();
$result_fetch_agent = $stmt_fetch_agent->get_result();
if ($result_fetch_agent->num_rows > 0) {
    $agent_data = $result_fetch_agent->fetch_assoc();
} else {
    $error_message = "Agent not found or is not an agent role.";
}


?>