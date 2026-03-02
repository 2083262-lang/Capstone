<?php
session_start();
include '../connection.php';
require_once __DIR__ . '/../mail_helper.php';
require_once __DIR__ . '/../email_template.php';

// Check authentication
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'agent') {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit();
}

// Validate tour_id
if (!isset($_POST['tour_id']) || !is_numeric($_POST['tour_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid tour request ID']);
    exit();
}

$tour_id = (int)$_POST['tour_id'];
$agent_id = (int)$_SESSION['account_id'];

// Start transaction
$conn->begin_transaction();

try {
    // Verify the agent owns this tour request
    $check_sql = "SELECT * FROM tour_requests WHERE tour_id = ? AND agent_account_id = ? AND request_status = 'Confirmed'";
    $stmt = $conn->prepare($check_sql);
    $stmt->bind_param('ii', $tour_id, $agent_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception('Tour request not found or not eligible to be marked as completed');
    }
    
    // Update the tour status
    $update_sql = "UPDATE tour_requests SET request_status = 'Completed', completed_at = NOW() WHERE tour_id = ?";
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param('i', $tour_id);
    if (!$stmt->execute()) {
        $err = $stmt->error ?: $conn->error;
        throw new Exception('Failed to update tour status: ' . $err);
    }
    if ($stmt->affected_rows <= 0) {
        throw new Exception('No rows updated. It may already be completed or not found.');
    }
    
    // Get user and property info for notification
    $info_sql = "SELECT tr.user_email, tr.user_name, tr.tour_date, tr.tour_time, 
                       p.StreetAddress, p.City, p.Province 
                FROM tour_requests tr
                JOIN property p ON tr.property_id = p.property_ID
                WHERE tr.tour_id = ?";
    $stmt = $conn->prepare($info_sql);
    $stmt->bind_param('i', $tour_id);
    $stmt->execute();
    $tour_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    // Fetch the actual completed_at from DB to return
    $ts_stmt = $conn->prepare("SELECT completed_at FROM tour_requests WHERE tour_id = ?");
    $ts_stmt->bind_param('i', $tour_id);
    $ts_stmt->execute();
    $ts_res = $ts_stmt->get_result()->fetch_assoc();
    $completed_at = $ts_res ? $ts_res['completed_at'] : null;
    $ts_stmt->close();
    
    // Commit transaction
    $conn->commit();
    
    // Send email notification to user
    $property_address = $tour_info['StreetAddress'] . ', ' . $tour_info['City'] . ', ' . $tour_info['Province'];
    $formattedDate = date('F j, Y', strtotime($tour_info['tour_date']));
    $formattedTime = date('g:i A', strtotime($tour_info['tour_time']));
    
    try {
        $subject = 'Tour Completed - Thank You for Visiting';

        $bodyContent  = emailGreeting($tour_info['user_name']);
        $bodyContent .= emailParagraph('Your property tour has been marked as completed. We hope you enjoyed viewing the property and found it to your liking.');
        $bodyContent .= emailDivider();
        $bodyContent .= emailInfoCard('Tour Details', [
            'Property' => htmlspecialchars($property_address),
            'Date'     => $formattedDate,
            'Time'     => $formattedTime,
        ]);
        $bodyContent .= emailNotice("What's Next?", "If you're interested in this property or would like to schedule additional viewings, please don't hesitate to reach out. We're here to help you find your perfect home.", '#2563eb');
        $bodyContent .= emailClosing('Thank you for choosing HomeEstate Realty. We look forward to assisting you further.');

        $body = buildEmailTemplate([
            'accentColor' => '#22c55e',
            'heading'     => 'Tour Completed',
            'subtitle'    => 'Thank you for visiting the property',
            'body'        => $bodyContent,
        ]);
        
        $emailResult = sendSystemMail($tour_info['user_email'], $tour_info['user_name'], $subject, $body, 'Your tour has been completed.');
        $emailStatus = !empty($emailResult['success']) ? 'Email sent successfully.' : 'Email could not be sent.';
    } catch (Exception $e) {
        $emailStatus = 'Email notification failed.';
    }
    
    // Return success response
    echo json_encode([
        'success' => true, 
        'message' => 'Tour marked as completed successfully. ' . ($emailStatus ?? ''),
        'completed_at' => $completed_at,
        'user_info' => [
            'name' => $tour_info['user_name'],
            'email' => $tour_info['user_email']
        ],
        'property_info' => [
            'address' => $tour_info['StreetAddress'] . ', ' . $tour_info['City'] . ', ' . $tour_info['Province']
        ]
    ]);
} catch (Exception $e) {
    // Roll back transaction on error
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} finally {
    $conn->close();
}