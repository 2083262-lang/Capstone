<?php
require_once __DIR__ . '/mail_helper.php';
require_once __DIR__ . '/email_template.php';

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
            
            // --- Send Approval Email ---
            $subject = 'Agent Application Approved - Welcome to HomeEstate Realty';
            
            $bodyContent  = emailGreeting($agent_first_name);
            $bodyContent .= emailParagraph('Congratulations! We are thrilled to inform you that your agent application has been <strong style="color:#22c55e;">approved</strong>. You are now an official member of the HomeEstate Realty team.');
            $bodyContent .= emailStatusBadge('Status', '&#10003; ACTIVE AGENT', '#22c55e', 'You can now access your dashboard');
            $bodyContent .= emailDivider();
            $bodyContent .= emailInfoCard('Next Steps', [
                '1' => 'Log in to your agent dashboard',
                '2' => 'Complete your profile details',
                '3' => 'Start adding property listings',
                '4' => 'Respond to tour requests from buyers',
            ]);
            $bodyContent .= emailNotice('Need Help?', "If you have any questions or need assistance getting started, please don't hesitate to contact our support team.", '#2563eb');
            $bodyContent .= emailSignature();

            $html = buildEmailTemplate([
                'accentColor' => '#22c55e',
                'heading'     => 'Application Approved',
                'subtitle'    => 'Welcome to our team of agents',
                'body'        => $bodyContent,
                'footerExtra' => 'Cagayan De Oro City, Northern Mindanao, Philippines',
            ]);

            $alt = "Dear {$agent_first_name},\n\nCongratulations! Your agent application has been approved. You are now an active agent.\n\nNext Steps:\n- Log in to your agent dashboard\n- Complete your profile details\n- Start adding property listings\n- Respond to tour requests from buyers\n\nBest regards,\nThe HomeEstate Realty Team";
            $send = sendSystemMail($agent_email, $agent_first_name, $subject, $html, $alt);
            
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
            
            // --- Send Rejection Email ---
            $subject = 'Agent Application Update - HomeEstate Realty';

            $bodyContent  = emailGreeting($agent_first_name);
            $bodyContent .= emailParagraph('Thank you for your interest in joining HomeEstate Realty. After a careful review of your application, we regret to inform you that we are unable to proceed at this time.');
            $bodyContent .= emailDivider();
            $bodyContent .= emailNotice('Reason for Decision', '"' . htmlspecialchars($rejection_reason) . '"', '#d4af37');
            $bodyContent .= emailParagraph('We appreciate the time and effort you put into your application. We wish you the best in your future endeavors.');
            $bodyContent .= emailSignature('Sincerely');

            $html = buildEmailTemplate([
                'accentColor' => '#d4af37',
                'heading'     => 'Application Update',
                'subtitle'    => 'Regarding your agent application',
                'body'        => $bodyContent,
                'footerExtra' => 'Cagayan De Oro City, Northern Mindanao, Philippines',
            ]);

            $alt = "Dear {$agent_first_name},\n\nThank you for your interest in joining our team. After a careful review, we regret to inform you that we cannot proceed at this time.\n\nReason: \"" . htmlspecialchars($rejection_reason) . "\"\n\nWe wish you the best.\n\nSincerely,\nThe HomeEstate Realty Team";
            $send = sendSystemMail($agent_email, $agent_first_name, $subject, $html, $alt);
            
            $success_message_redirect = "Agent rejected successfully and an email has been sent.";
            
        } elseif ($action === 'disable') {
            // Handle disabling an approved agent account
            if (empty($_POST['disable_reason'])) {
                throw new Exception("Disable reason is required.");
            }
            $disable_reason = $_POST['disable_reason'];
            
            // Set account to inactive
            $stmt_deactivate = $conn->prepare("UPDATE accounts SET is_active = 0 WHERE account_id = ?");
            $stmt_deactivate->bind_param("i", $posted_account_id);
            $stmt_deactivate->execute();
            $stmt_deactivate->close();

            // Log the disable action
            $stmt_log = $conn->prepare("INSERT INTO status_log (item_id, item_type, action, reason_message, action_by_account_id) VALUES (?, 'agent', 'disabled', ?, ?)");
            $stmt_log->bind_param("isi", $posted_account_id, $disable_reason, $admin_account_id);
            $stmt_log->execute();
            $stmt_log->close();
            
            // --- Send Disable Notification Email ---
            $subject = 'Account Status Update - HomeEstate Realty';

            $bodyContent  = emailGreeting($agent_first_name);
            $bodyContent .= emailParagraph('We are writing to inform you that your agent account with HomeEstate Realty has been <strong style="color:#ef4444;">disabled</strong> by our administration team.');
            $bodyContent .= emailStatusBadge('Account Status', '&#10005; DISABLED', '#ef4444', 'You cannot access your account');
            $bodyContent .= emailDivider();
            $bodyContent .= emailNotice('Reason for Disabling', '"' . htmlspecialchars($disable_reason) . '"', '#ef4444');
            $bodyContent .= emailNotice('What This Means', 'Your account has been temporarily disabled. You will not be able to log in or access any agent features until your account is reactivated by an administrator.', '#d4af37');
            $bodyContent .= emailParagraph('If you believe this action was taken in error or would like to discuss reactivating your account, please contact our support team.');
            $bodyContent .= emailSignature('Sincerely');

            $html = buildEmailTemplate([
                'accentColor' => '#ef4444',
                'heading'     => 'Account Disabled',
                'subtitle'    => 'Important account status update',
                'body'        => $bodyContent,
                'footerExtra' => 'Cagayan De Oro City, Northern Mindanao, Philippines',
            ]);

            $alt = "Dear {$agent_first_name},\n\nYour agent account has been disabled.\n\nReason: \"" . htmlspecialchars($disable_reason) . "\"\n\nYou will not be able to log in until reactivated. Contact support if you believe this is an error.\n\nSincerely,\nThe HomeEstate Realty Team";
            
            $send = sendSystemMail($agent_email, $agent_first_name, $subject, $html, $alt);
            
            $success_message_redirect = "Agent account has been disabled successfully and notification email has been sent!";
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
                           ai.license_number, COALESCE((SELECT GROUP_CONCAT(s.specialization_name ORDER BY s.specialization_name SEPARATOR ', ') FROM agent_specializations asp JOIN specializations s ON asp.specialization_id = s.specialization_id WHERE asp.agent_info_id = ai.agent_info_id), '') AS specialization, ai.years_experience,
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