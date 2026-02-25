<?php
session_start();
include '../connection.php';
require_once '../config/mail_config.php';

// Include PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require '../PHPMailer/src/Exception.php';
require '../PHPMailer/src/PHPMailer.php';
require '../PHPMailer/src/SMTP.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => 'An unknown error occurred.', 'html' => null, 'errors' => []];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Get data from POST request
    $property_id = isset($_POST['property_id']) ? (int)$_POST['property_id'] : 0;
    $user_name = trim($_POST['name'] ?? '');
    $user_email = trim($_POST['email'] ?? '');
    $user_phone = trim($_POST['phone'] ?? '');
    // Frontend sends 'tour_date'; support legacy 'date' as fallback
    $tour_date = trim($_POST['tour_date'] ?? ($_POST['date'] ?? ''));
    $tour_time = trim($_POST['time'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $tour_type = strtolower(trim($_POST['tour_type'] ?? 'private')) === 'public' ? 'public' : 'private';

    // Basic validation
    $missing = [];
    if (empty($property_id)) $missing[] = 'Property';
    if ($user_name === '') { $missing[] = 'Full Name'; $response['errors']['name'] = 'Full name is required.'; }
    if ($user_email === '') { $missing[] = 'Email'; $response['errors']['email'] = 'Email is required.'; }
    if ($tour_date === '') { $missing[] = 'Date'; $response['errors']['tour_date'] = 'Please select a date.'; }
    if ($tour_time === '') { $missing[] = 'Time'; $response['errors']['time'] = 'Please select a time.'; }

    if (!empty($missing)) {
        $response['message'] = 'Please fill in all required fields.';
        $response['html'] = '<div><strong>Missing fields:</strong> ' . htmlspecialchars(implode(', ', $missing)) . '</div>';
        echo json_encode($response);
        exit;
    }

    if (!filter_var($user_email, FILTER_VALIDATE_EMAIL)) {
        $response['message'] = 'Invalid email format.';
        $response['errors']['email'] = 'Please provide a valid email address.';
        $response['html'] = '<div><strong>Invalid Email:</strong> Please provide a valid email address.</div>';
        echo json_encode($response);
        exit;
    }

    // 2. Get Agent and Property Info
    $agent_info = null;
    $property_info = null;

    $stmt = $conn->prepare("
        SELECT 
            a.account_id, a.email, a.first_name, a.last_name, a.role_id,
            p.StreetAddress, p.City
        FROM property p
        JOIN property_log pl ON p.property_ID = pl.property_id
        JOIN accounts a ON pl.account_id = a.account_id
        WHERE p.property_ID = ? AND pl.action = 'CREATED'
        LIMIT 1
    ");
    $stmt->bind_param("i", $property_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $agent_info = [
            'id' => $row['account_id'],
            'email' => $row['email'],
            'name' => $row['first_name'] . ' ' . $row['last_name'],
            'role_id' => (int)$row['role_id']
        ];
        $property_info = [
            'address' => $row['StreetAddress'] . ', ' . $row['City']
        ];
    }
    $stmt->close();

    if (!$agent_info || !$property_info) {
        $response['message'] = 'Could not find agent or property information.';
        echo json_encode($response);
        exit;
    }

    $conn->begin_transaction();

    try {
        // 3. Insert into tour_requests table
        $insert_tour_sql = "
            INSERT INTO tour_requests 
            (property_id, agent_account_id, user_name, user_email, user_phone, tour_date, tour_time, tour_type, message) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        $stmt = $conn->prepare($insert_tour_sql);
        $stmt->bind_param("iisssssss", $property_id, $agent_info['id'], $user_name, $user_email, $user_phone, $tour_date, $tour_time, $tour_type, $message);
        $stmt->execute();
        $tour_id = $stmt->insert_id;
        $stmt->close();

        // 4. Insert into admin notifications table (ONLY if property is managed by an admin)
        if ($agent_info['role_id'] === 1) {
            $notification_message = "New " . ($tour_type === 'public' ? 'public (group)' : 'private') . " tour request for property #" . (int)$property_id . ".";
            $insert_notification_sql = "
                INSERT INTO notifications (item_id, item_type, message) 
                VALUES (?, 'tour', ?)
            ";
            $stmt = $conn->prepare($insert_notification_sql);
            $stmt->bind_param("is", $tour_id, $notification_message);
            $stmt->execute();
            $stmt->close();
        }

        // 4b. Agent notification — new tour request
        require_once __DIR__ . '/../agent_pages/agent_notification_helper.php';
        createAgentNotification(
            $conn,
            $agent_info['id'],
            'tour_new',
            'New Tour Request',
            "You have a new " . ($tour_type === 'public' ? 'public (group)' : 'private') . " tour request from {$user_name} for property #{$property_id} on " . date('M d, Y', strtotime($tour_date)) . " at " . date('g:i A', strtotime($tour_time)) . ".",
            $tour_id
        );

        // 5. Send Email using PHPMailer
        $mail = new PHPMailer(true);
        
        // Server settings - Using configuration from mail_config.php
        $mail->isSMTP();
        $mail->Host       = MAIL_SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_SMTP_USERNAME;
        $mail->Password   = MAIL_SMTP_PASSWORD;
        $mail->SMTPSecure = MAIL_SMTP_SECURE === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = (int)MAIL_SMTP_PORT;

        // Recipients
        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($agent_info['email'], $agent_info['name']);
        $mail->addReplyTo($user_email, $user_name);

        // Content
        $mail->isHTML(true);
        $mail->Subject = "New " . ($tour_type === 'public' ? 'Public (Group) ' : 'Private ') . "Tour Request for Property: {$property_info['address']}";
        $tour_type_badge = $tour_type === 'public' ? 'Public (Group) Tour' : 'Private Tour';
        $tour_type_color = $tour_type === 'public' ? '#2563eb' : '#d4af37';
        $formatted_date = date('F j, Y', strtotime($tour_date));
        $formatted_time = date('g:i A', strtotime($tour_time));
        $message_html = nl2br(htmlspecialchars($message));
        
        $mail->Body = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Tour Request</title>
</head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,\'Helvetica Neue\',Arial,sans-serif;background-color:#0a0a0a;line-height:1.6;">
    
    <!-- Email Container -->
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#0a0a0a;padding:60px 20px;">
        <tr>
            <td align="center">
                
                <!-- Content Card -->
                <table width="600" cellpadding="0" cellspacing="0" style="background-color:#111111;border:1px solid #1f1f1f;border-radius:4px;max-width:600px;">
                    
                    <!-- Accent Line -->
                    <tr>
                        <td style="background:linear-gradient(90deg,' . $tour_type_color . ' 0%,' . ($tour_type === 'public' ? '#3b82f6' : '#f4d03f') . ' 50%,' . $tour_type_color . ' 100%);height:3px;"></td>
                    </tr>
                    
                    <!-- Header -->
                    <tr>
                        <td style="padding:48px 48px 32px 48px;text-align:center;border-bottom:1px solid #1f1f1f;">
                            <div style="font-size:48px;margin-bottom:16px;">📅</div>
                            <h1 style="margin:0 0 12px 0;color:' . $tour_type_color . ';font-size:14px;font-weight:600;text-transform:uppercase;letter-spacing:3px;">New Tour Request</h1>
                            <p style="margin:0;color:#666666;font-size:15px;font-weight:400;">' . htmlspecialchars($tour_type_badge) . '</p>
                        </td>
                    </tr>
                    
                    <!-- Body Content -->
                    <tr>
                        <td style="padding:48px 48px 40px 48px;">
                            
                            <!-- Greeting -->
                            <p style="margin:0 0 24px 0;font-size:14px;color:#999999;line-height:1.7;">
                                Hello <span style="color:#d4af37;font-weight:500;">' . htmlspecialchars($agent_info['name']) . '</span>,
                            </p>
                            
                            <p style="margin:0 0 32px 0;font-size:14px;color:#999999;line-height:1.7;">
                                You have received a new property tour request. A potential client is interested in viewing one of your listings. Please review the details below and contact them promptly to confirm the appointment.
                            </p>
                            
                            <!-- Property & Client Details Card -->
                            <div style="background-color:#0d1117;border:1px solid:' . $tour_type_color . ';border-radius:2px;padding:24px;margin:0 0 24px 0;">
                                <p style="margin:0 0 16px 0;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:2px;color:' . $tour_type_color . ';">Request Details</p>
                                <table width="100%" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td style="padding:8px 0;font-size:13px;color:#666666;vertical-align:top;width:40%;">Property</td>
                                        <td style="padding:8px 0;font-size:13px;color:#ffffff;font-weight:500;">' . htmlspecialchars($property_info['address']) . ' <span style="color:#666666;font-size:12px;">(ID: ' . $property_id . ')</span></td>
                                    </tr>
                                    <tr>
                                        <td style="padding:8px 0;font-size:13px;color:#666666;vertical-align:top;">Tour Type</td>
                                        <td style="padding:8px 0;font-size:13px;color:' . $tour_type_color . ';font-weight:600;">' . htmlspecialchars($tour_type_badge) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding:8px 0;font-size:13px;color:#666666;vertical-align:top;">Requested Date</td>
                                        <td style="padding:8px 0;font-size:15px;color:#ffffff;font-weight:600;">' . htmlspecialchars($formatted_date) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding:8px 0;font-size:13px;color:#666666;vertical-align:top;">Requested Time</td>
                                        <td style="padding:8px 0;font-size:15px;color:#ffffff;font-weight:600;">' . htmlspecialchars($formatted_time) . '</td>
                                    </tr>
                                </table>
                            </div>
                            
                            <!-- Client Contact Card -->
                            <div style="background-color:#0d1117;border:1px solid #2563eb;border-radius:2px;padding:24px;margin:0 0 24px 0;">
                                <p style="margin:0 0 16px 0;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:2px;color:#2563eb;">Client Information</p>
                                <table width="100%" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td style="padding:8px 0;font-size:13px;color:#666666;vertical-align:top;width:40%;">Name</td>
                                        <td style="padding:8px 0;font-size:13px;color:#ffffff;font-weight:500;">' . htmlspecialchars($user_name) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding:8px 0;font-size:13px;color:#666666;vertical-align:top;">Email</td>
                                        <td style="padding:8px 0;font-size:13px;font-weight:500;"><a href="mailto:' . htmlspecialchars($user_email) . '" style="color:#2563eb;text-decoration:none;">' . htmlspecialchars($user_email) . '</a></td>
                                    </tr>
                                    <tr>
                                        <td style="padding:8px 0;font-size:13px;color:#666666;vertical-align:top;">Phone</td>
                                        <td style="padding:8px 0;font-size:13px;color:#ffffff;font-weight:500;">' . htmlspecialchars($user_phone) . '</td>
                                    </tr>
                                </table>
                            </div>
                            
                            <!-- Client Message -->
                            ' . (!empty($message) ? '<div style="background-color:#0d1117;border-left:2px solid #d4af37;padding:16px 20px;margin:0 0 32px 0;">
                                <p style="margin:0 0 8px 0;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:1px;color:#d4af37;">Client Message</p>
                                <p style="margin:0;font-size:13px;color:#999999;line-height:1.6;">' . $message_html . '</p>
                            </div>' : '') . '
                            
                            <!-- Divider -->
                            <div style="height:1px;background-color:#1f1f1f;margin:0 0 32px 0;"></div>
                            
                            <!-- Action Notice -->
                            <div style="background-color:#0d1117;border-left:2px solid #2563eb;padding:16px 20px;margin:0 0 24px 0;">
                                <p style="margin:0;font-size:13px;color:#999999;line-height:1.6;">
                                    <strong style="color:#2563eb;display:block;margin-bottom:6px;font-size:12px;text-transform:uppercase;letter-spacing:1px;">Next Steps</strong>
                                    Please contact the client promptly to confirm or reschedule the tour. You can manage this request from your Agent Dashboard. This email allows direct reply to the client.
                                </p>
                            </div>
                            
                            <!-- Footer Message -->
                            <p style="margin:0;font-size:13px;color:#666666;line-height:1.6;text-align:center;">
                                Respond quickly to build client trust and close deals faster.
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
        $mail->AltBody = "New " . ucfirst($tour_type) . " Tour Request from {$user_name} for property {$property_info['address']}. Email: {$user_email}, Phone: {$user_phone}, Date: {$tour_date}, Time: {$tour_time}. Message: {$message}";

        $mail->send();
        
        // Also send a confirmation email to the user
        $userMail = new PHPMailer(true);
        $userMail->isSMTP();
        $userMail->Host       = MAIL_SMTP_HOST;
        $userMail->SMTPAuth   = true;
        $userMail->Username   = MAIL_SMTP_USERNAME;
        $userMail->Password   = MAIL_SMTP_PASSWORD;
        $userMail->SMTPSecure = MAIL_SMTP_SECURE === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
        $userMail->Port       = (int)MAIL_SMTP_PORT;

        // Recipients for user confirmation email
        $userMail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $userMail->addAddress($user_email, $user_name);
        
        // Content
        $userMail->isHTML(true);
        $userMail->Subject = "Tour Request Confirmation (" . ucfirst($tour_type) . "): {$property_info['address']}";
        
        $userMail->Body = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tour Request Confirmation</title>
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
                            <h1 style="margin:0 0 12px 0;color:#10b981;font-size:14px;font-weight:600;text-transform:uppercase;letter-spacing:3px;">Request Confirmed</h1>
                            <p style="margin:0;color:#666666;font-size:15px;font-weight:400;">Your tour request has been sent</p>
                        </td>
                    </tr>
                    
                    <!-- Body Content -->
                    <tr>
                        <td style="padding:48px 48px 40px 48px;">
                            
                            <!-- Greeting -->
                            <p style="margin:0 0 24px 0;font-size:14px;color:#999999;line-height:1.7;">
                                Hello <span style="color:#d4af37;font-weight:500;">' . htmlspecialchars($user_name) . '</span>,
                            </p>
                            
                            <p style="margin:0 0 32px 0;font-size:14px;color:#999999;line-height:1.7;">
                                Thank you for your interest in this property! Your tour request has been successfully received and forwarded to the agent. They will review your request and contact you shortly to confirm the details.
                            </p>
                            
                            <!-- Request Details Card -->
                            <div style="background-color:#0d1117;border:1px solid #10b981;border-radius:2px;padding:24px;margin:0 0 32px 0;">
                                <p style="margin:0 0 16px 0;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:2px;color:#10b981;">Your Request Details</p>
                                <table width="100%" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td style="padding:8px 0;font-size:13px;color:#666666;vertical-align:top;width:40%;">Property</td>
                                        <td style="padding:8px 0;font-size:13px;color:#ffffff;font-weight:500;">' . htmlspecialchars($property_info['address']) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding:8px 0;font-size:13px;color:#666666;vertical-align:top;">Tour Type</td>
                                        <td style="padding:8px 0;font-size:13px;color:' . $tour_type_color . ';font-weight:600;">' . htmlspecialchars($tour_type_badge) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding:8px 0;font-size:13px;color:#666666;vertical-align:top;">Requested Date</td>
                                        <td style="padding:8px 0;font-size:15px;color:#ffffff;font-weight:600;">' . htmlspecialchars($formatted_date) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding:8px 0;font-size:13px;color:#666666;vertical-align:top;">Requested Time</td>
                                        <td style="padding:8px 0;font-size:15px;color:#ffffff;font-weight:600;">' . htmlspecialchars($formatted_time) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding:8px 0;font-size:13px;color:#666666;vertical-align:top;">Your Email</td>
                                        <td style="padding:8px 0;font-size:13px;color:#2563eb;font-weight:500;">' . htmlspecialchars($user_email) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding:8px 0;font-size:13px;color:#666666;vertical-align:top;">Your Phone</td>
                                        <td style="padding:8px 0;font-size:13px;color:#ffffff;font-weight:500;">' . htmlspecialchars($user_phone) . '</td>
                                    </tr>
                                </table>
                            </div>
                            
                            <!-- Divider -->
                            <div style="height:1px;background-color:#1f1f1f;margin:0 0 32px 0;"></div>
                            
                            <!-- What Happens Next -->
                            <div style="background-color:#0d1117;border-left:2px solid #d4af37;padding:16px 20px;margin:0 0 24px 0;">
                                <p style="margin:0;font-size:13px;color:#999999;line-height:1.6;">
                                    <strong style="color:#d4af37;display:block;margin-bottom:6px;font-size:12px;text-transform:uppercase;letter-spacing:1px;">What Happens Next</strong>
                                    The property agent will review your request and contact you via email or phone within 24-48 hours to confirm the tour appointment or suggest alternative times if needed.
                                </p>
                            </div>
                            
                            <!-- Footer Message -->
                            <p style="margin:0;font-size:13px;color:#666666;line-height:1.6;text-align:center;">
                                We appreciate your interest and look forward to helping you find your perfect property.
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
        $userMail->AltBody = "Your " . ucfirst($tour_type) . " tour request for property {$property_info['address']} on {$tour_date} at {$tour_time} has been received. The agent will contact you soon.";
        
        $userMail->send();

    $conn->commit();
    $response['success'] = true;
    $response['message'] = 'Tour request sent successfully! The agent will contact you soon.';
    $response['html'] = '<div><strong>Success:</strong> Your tour request has been sent. The agent will contact you soon.</div>';

    } catch (Exception $e) {
    $conn->rollback();
    $response['message'] = 'We could not send your request at this time.';
    
    // Log the error
    $errorInfo = '';
    if (isset($mail) && $mail instanceof PHPMailer) {
        $errorInfo .= "Agent email error: " . htmlspecialchars($mail->ErrorInfo) . "\n";
    }
    if (isset($userMail) && $userMail instanceof PHPMailer) {
        $errorInfo .= "User email error: " . htmlspecialchars($userMail->ErrorInfo) . "\n";
    }
    if (!$errorInfo) {
        $errorInfo = htmlspecialchars($e->getMessage());
    }
    
    // Log the error to our mail.log file
    if (defined('MAIL_DEBUG_ENABLED') && MAIL_DEBUG_ENABLED && defined('MAIL_LOG_FILE')) {
        @error_log('[TOUR REQUEST ERROR] ' . $errorInfo . "\n", 3, MAIL_LOG_FILE);
    }
    
    $response['html'] = '<div><strong>Error sending email:</strong> ' . htmlspecialchars($errorInfo) . '</div>';
    }

} else {
    $response['message'] = 'Invalid request method.';
}

echo json_encode($response);
?>