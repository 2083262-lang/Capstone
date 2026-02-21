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
            a.account_id, a.email, a.first_name, a.last_name,
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
            'name' => $row['first_name'] . ' ' . $row['last_name']
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

        // 4. Insert into notifications table
        $notification_message = "New " . ($tour_type === 'public' ? 'public (group)' : 'private') . " tour request from {$user_name} for property #{$property_id}.";
        $insert_notification_sql = "
            INSERT INTO notifications (item_id, item_type, message) 
            VALUES (?, 'tour', ?)
        ";
        $stmt = $conn->prepare($insert_notification_sql);
        $stmt->bind_param("is", $tour_id, $notification_message);
        $stmt->execute();
        $stmt->close();

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
        $mail->Body    = "
            <div style='font-family: Arial, sans-serif; line-height: 1.6;'>
                <h2>New Tour Request</h2>
                <p>Hello {$agent_info['name']},</p>
                <p>You have received a new tour request for your property listing. Please see the details below and respond to the client as soon as possible.</p>
                <hr>
                <h3>Request Details:</h3>
                <ul>
                    <li><strong>Property:</strong> {$property_info['address']} (ID: {$property_id})</li>
                    <li><strong>Tour Type:</strong> " . ucfirst($tour_type) . "</li>
                    <li><strong>Client Name:</strong> {$user_name}</li>
                    <li><strong>Client Email:</strong> <a href='mailto:{$user_email}'>{$user_email}</a></li>
                    <li><strong>Client Phone:</strong> {$user_phone}</li>
                    <li><strong>Requested Date:</strong> " . date('F j, Y', strtotime($tour_date)) . "</li>
                    <li><strong>Requested Time:</strong> " . date('g:i A', strtotime($tour_time)) . "</li>
                </ul>
                <h3>Message:</h3>
                <p style='background-color: #f4f4f4; padding: 15px; border-radius: 5px;'>" . nl2br(htmlspecialchars($message)) . "</p>
                <hr>
                <p>You can view and manage this request from your agent dashboard.</p>
                <p><em>This is an automated message. Please do not reply directly to this email unless using the 'Reply-To' feature.</em></p>
            </div>
        ";
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
        $userMail->Body    = "
            <div style='font-family: Arial, sans-serif; line-height: 1.6;'>
                <h2>Tour Request Confirmation</h2>
                <p>Hello {$user_name},</p>
                <p>Thank you for requesting a property tour. Your request has been received and forwarded to the agent.</p>
                <hr>
                <h3>Your Request Details:</h3>
                <ul>
                    <li><strong>Property:</strong> {$property_info['address']}</li>
                    <li><strong>Tour Type:</strong> " . ucfirst($tour_type) . "</li>
                    <li><strong>Requested Date:</strong> " . date('F j, Y', strtotime($tour_date)) . "</li>
                    <li><strong>Requested Time:</strong> " . date('g:i A', strtotime($tour_time)) . "</li>
                </ul>
                <p>The agent will review your request and contact you soon to confirm the tour.</p>
                <hr>
                <p><em>This is an automated message. Please do not reply directly to this email.</em></p>
            </div>
        ";
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