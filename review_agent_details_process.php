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
    $action = $_POST['action'];
    $posted_account_id = $_POST['account_id'] ?? 0;
    $rejection_reason = $_POST['rejection_reason'] ?? '';

    if ($posted_account_id != $account_id_to_review) {
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
            $stmt_update = $conn->prepare("UPDATE agent_information SET is_approved = 1 WHERE account_id = ?");
            $stmt_update->bind_param("i", $posted_account_id);
            $stmt_update->execute();
            $stmt_update->close();

            // Make sure the account is active
            $stmt_active = $conn->prepare("UPDATE accounts SET is_active = 1 WHERE account_id = ?");
            $stmt_active->bind_param("i", $posted_account_id);
            $stmt_active->execute();
            $stmt_active->close();

            $stmt_log = $conn->prepare("INSERT INTO status_log (item_id, item_type, action, action_by_account_id) VALUES (?, 'agent', 'approved', ?)");
            $stmt_log->bind_param("ii", $posted_account_id, $admin_account_id);
            $stmt_log->execute();
            $stmt_log->close();
            
            $success_message_redirect = "Agent approved successfully!";

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
            
            // --- Send Rejection Email (centralized helper) ---
            $subject = 'An Update on Your Agent Application';
            $html = "
                <body style='margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f8f4f4;'>
                    <table border='0' cellpadding='0' cellspacing='0' width='100%'>
                        <tr>
                            <td style='padding: 20px 0;'>
                                <table align='center' border='0' cellpadding='0' cellspacing='0' width='600' style='border-collapse: collapse; background-color: #ffffff; border: 1px solid #e6e6e6; border-radius: 8px;'>
                                    <tr>
                                        <td align='center' style='padding: 20px 0; border-bottom: 1px solid #e6e6e6; background-color: #161209; border-radius: 8px 8px 0 0;'>
                                            <img src='[URL_TO_YOUR_LOGO]' alt='Your Company Logo' width='180' style='display: block;' />
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style='padding: 40px 30px;'>
                                            <h1 style='color: #161209; font-size: 24px; margin: 0 0 20px 0;'>Application Update</h1>
                                            <p style='margin: 0 0 20px 0; color: #555555; font-size: 16px; line-height: 1.5;'>
                                                Dear {$agent_first_name},
                                            </p>
                                            <p style='margin: 0 0 20px 0; color: #555555; font-size: 16px; line-height: 1.5;'>
                                                Thank you for your interest in joining our team. After a careful review of your application, we regret to inform you that we cannot proceed at this time.
                                            </p>
                                            <p style='margin: 0 0 10px 0; color: #555555; font-size: 16px; font-weight: bold;'>
                                                Reason for decision:
                                            </p>
                                            <table border='0' cellpadding='0' cellspacing='0' width='100%'>
                                                <tr>
                                                    <td style='padding: 20px; background-color: #f8f4f4; border-left: 4px solid #bc9e42;'>
                                                        <p style='margin: 0; color: #333333; font-size: 16px; line-height: 1.5;'>
                                                            <em>" . htmlspecialchars($rejection_reason) . "</em>
                                                        </p>
                                                    </td>
                                                </tr>
                                            </table>
                                            <p style='margin: 20px 0 0 0; color: #555555; font-size: 16px; line-height: 1.5;'>
                                                We wish you the best in your future endeavors.
                                            </p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style='padding: 20px 30px; background-color: #f8f4f4; border-radius: 0 0 8px 8px; text-align: center;'>
                                            <p style='margin: 0; color: #888888; font-size: 12px;'>
                                                &copy; " . date('Y') . " Your Company Name. All Rights Reserved.<br>
                                                Cagayan De Oro City, Northern Mindanao, Philippines
                                            </p>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    </table>
                </body>
            ";
            $alt = "Dear {$agent_first_name},\n\nThank you for your interest in joining our team. After a careful review of your application, we regret to inform you that we cannot proceed at this time.\n\nReason for decision:\n\"" . htmlspecialchars($rejection_reason) . "\"\n\nWe wish you the best in your future endeavors.\n\nSincerely,\nThe Admin Team";
            $send = sendSystemMail($agent_email, $agent_first_name, $subject, $html, $alt);
            
            $success_message_redirect = "Agent rejected successfully and an email has been sent.";
        }
        
        $conn->commit();
        header("Location: review_agent_details.php?account_id={$account_id_to_review}&status=success&msg=" . urlencode($success_message_redirect));
        exit();

    } catch (Exception $e) {
        $conn->rollback();
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