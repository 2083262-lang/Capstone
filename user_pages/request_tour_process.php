<?php
session_start();
include '../connection.php';
require_once __DIR__ . '/../mail_helper.php';
require_once __DIR__ . '/../email_template.php';

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

        // 5. Send Email to Agent using centralized mailer
        $tour_type_badge = $tour_type === 'public' ? 'Public (Group) Tour' : 'Private Tour';
        $tour_type_color = $tour_type === 'public' ? '#2563eb' : '#d4af37';
        $formatted_date = date('F j, Y', strtotime($tour_date));
        $formatted_time = date('g:i A', strtotime($tour_time));

        // --- Agent notification email ---
        $agentSubject = "New " . ($tour_type === 'public' ? 'Public (Group) ' : 'Private ') . "Tour Request for Property: {$property_info['address']}";

        $agentBody  = emailGreeting($agent_info['name']);
        $agentBody .= emailParagraph('You have received a new property tour request. A potential client is interested in viewing one of your listings. Please review the details below and contact them promptly to confirm the appointment.');
        $agentBody .= emailInfoCard('Request Details', [
            'Property'       => htmlspecialchars($property_info['address']) . ' <span style="color:#666666;font-size:12px;">(ID: ' . $property_id . ')</span>',
            'Tour Type'      => '<span style="color:' . $tour_type_color . ';font-weight:600;">' . htmlspecialchars($tour_type_badge) . '</span>',
            'Requested Date' => $formatted_date,
            'Requested Time' => $formatted_time,
        ], $tour_type_color);
        $agentBody .= emailInfoCard('Client Information', [
            'Name'  => htmlspecialchars($user_name),
            'Email' => '<a href="mailto:' . htmlspecialchars($user_email) . '" style="color:#2563eb;text-decoration:none;">' . htmlspecialchars($user_email) . '</a>',
            'Phone' => htmlspecialchars($user_phone),
        ], '#2563eb');
        if (!empty($message)) {
            $agentBody .= emailNotice('Client Message', nl2br(htmlspecialchars($message)), '#d4af37');
        }
        $agentBody .= emailDivider();
        $agentBody .= emailNotice('Next Steps', 'Please contact the client promptly to confirm or reschedule the tour. You can manage this request from your Agent Dashboard. This email allows direct reply to the client.', '#2563eb');
        $agentBody .= emailClosing('Respond quickly to build client trust and close deals faster.');

        $agentHtml = buildEmailTemplate([
            'accentColor' => $tour_type_color,
            'heading'     => 'New Tour Request',
            'subtitle'    => htmlspecialchars($tour_type_badge),
            'body'        => $agentBody,
        ]);

        $agentAltBody = "New " . ucfirst($tour_type) . " Tour Request from {$user_name} for property {$property_info['address']}. Email: {$user_email}, Phone: {$user_phone}, Date: {$tour_date}, Time: {$tour_time}. Message: {$message}";

        sendSystemMail($agent_info['email'], $agent_info['name'], $agentSubject, $agentHtml, $agentAltBody, [
            'replyToEmail' => $user_email,
            'replyToName'  => $user_name,
        ]);

        // --- User confirmation email ---
        $userSubject = "Tour Request Confirmation (" . ucfirst($tour_type) . "): {$property_info['address']}";

        $userBody  = emailGreeting($user_name);
        $userBody .= emailParagraph('Thank you for your interest in this property! Your tour request has been successfully received and forwarded to the agent. They will review your request and contact you shortly to confirm the details.');
        $userBody .= emailInfoCard('Your Request Details', [
            'Property'       => htmlspecialchars($property_info['address']),
            'Tour Type'      => '<span style="color:' . $tour_type_color . ';font-weight:600;">' . htmlspecialchars($tour_type_badge) . '</span>',
            'Requested Date' => $formatted_date,
            'Requested Time' => $formatted_time,
            'Your Email'     => '<span style="color:#2563eb;">' . htmlspecialchars($user_email) . '</span>',
            'Your Phone'     => htmlspecialchars($user_phone),
        ], '#22c55e');
        $userBody .= emailDivider();
        $userBody .= emailNotice('What Happens Next', 'The property agent will review your request and contact you via email or phone within 24-48 hours to confirm the tour appointment or suggest alternative times if needed.', '#d4af37');
        $userBody .= emailClosing('We appreciate your interest and look forward to helping you find your perfect property.');

        $userHtml = buildEmailTemplate([
            'accentColor' => '#22c55e',
            'heading'     => 'Request Confirmed',
            'subtitle'    => 'Your tour request has been sent',
            'body'        => $userBody,
        ]);

        $userAltBody = "Your " . ucfirst($tour_type) . " tour request for property {$property_info['address']} on {$tour_date} at {$tour_time} has been received. The agent will contact you soon.";

        sendSystemMail($user_email, $user_name, $userSubject, $userHtml, $userAltBody);

    $conn->commit();
    $response['success'] = true;
    $response['message'] = 'Tour request sent successfully! The agent will contact you soon.';
    $response['html'] = '<div><strong>Success:</strong> Your tour request has been sent. The agent will contact you soon.</div>';

    } catch (Exception $e) {
    $conn->rollback();
    $response['message'] = 'We could not send your request at this time.';
    
    // Log the error
    $errorInfo = htmlspecialchars($e->getMessage());
    
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