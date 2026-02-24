<?php
session_start();
require_once 'connection.php';
require_once 'mail_helper.php';

// Admin-only access
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// Handle approval action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve' && isset($_POST['verification_id'])) {
    $verification_id = (int)$_POST['verification_id'];
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // First, get the verification data including all sale details
        $sql = "SELECT sv.*, p.ListingPrice FROM sale_verifications sv 
                LEFT JOIN property p ON p.property_ID = sv.property_id 
                WHERE sv.verification_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $verification_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception('Sale verification not found');
        }
        
        $verification = $result->fetch_assoc();
        $property_id = $verification['property_id'];
        $stmt->close();
        
        // Update sale verification status to Approved
        $sql = "UPDATE sale_verifications SET status = 'Approved', reviewed_by = ?, reviewed_at = NOW() WHERE verification_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $_SESSION['account_id'], $verification_id);
        $stmt->execute();
        $stmt->close();
        
    // Update property status to "Sold" and lock it
    $sql = "UPDATE property SET Status = 'Sold', is_locked = 1, sold_date = ?, sold_by_agent = ? WHERE property_ID = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sii', $verification['sale_date'], $verification['agent_id'], $property_id);
    $stmt->execute();
    $stmt->close();

        // Determine buyer email: prefer explicit email in buyer_contact if valid, else derive from latest tour request
        $buyerEmail = null;
        if (!empty($verification['buyer_contact']) && filter_var($verification['buyer_contact'], FILTER_VALIDATE_EMAIL)) {
            $buyerEmail = $verification['buyer_contact'];
        } else {
            $tr = $conn->prepare("SELECT user_email FROM tour_requests WHERE property_id = ? ORDER BY requested_at DESC LIMIT 1");
            $tr->bind_param('i', $property_id);
            $tr->execute();
            $trRes = $tr->get_result();
            $trRow = $trRes ? $trRes->fetch_assoc() : null;
            $tr->close();
            if ($trRow && !empty($trRow['user_email'])) {
                $buyerEmail = $trRow['user_email'];
            }
        }

        // Create permanent finalized sale record (canonical table)
        $sql = "INSERT INTO finalized_sales 
                (verification_id, property_id, agent_id, buyer_name, buyer_email, buyer_contact, final_sale_price, sale_date, commission_amount, commission_percentage, additional_notes, finalized_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iiisssdssi', 
            $verification_id,
            $property_id,
            $verification['agent_id'],
            $verification['buyer_name'],
            $buyerEmail,
            $verification['buyer_contact'],
            $verification['sale_price'],
            $verification['sale_date'],
            $verification['additional_notes'],
            $_SESSION['account_id']
        );
        $stmt->execute();
        $stmt->close();
        
    // Log the approval action
        $sql = "INSERT INTO status_log (item_id, item_type, action, reason_message, action_by_account_id) VALUES (?, 'property', 'approved', 'Property sale verification approved - property marked as sold and permanent record created', ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $property_id, $_SESSION['account_id']);
        $stmt->execute();
        $stmt->close();

    // Record in price history as 'Sold' event
    $sql = "INSERT INTO price_history (property_id, event_date, event_type, price) VALUES (?, CURDATE(), 'Sold', ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('id', $property_id, $verification['sale_price']);
    $stmt->execute();
    $stmt->close();

    // Append to property_log to keep audit of changes
    // Record that the property was sold in the property_log for auditing
    // Try to include contextual fields (reason_message, reference_id) when the schema supports them.
    $approvedMessage = 'Sale approved via verification #' . $verification_id;
    $extendedSql = "INSERT INTO property_log (property_id, account_id, action, log_timestamp, reason_message, reference_id) VALUES (?, ?, 'SOLD', NOW(), ?, ?)";
    $stmt = $conn->prepare($extendedSql);
    if ($stmt) {
        $stmt->bind_param('iisi', $property_id, $_SESSION['account_id'], $approvedMessage, $verification_id);
        $stmt->execute();
        $stmt->close();
    } else {
        // Fallback for older schemas without extra columns
        $sql = "INSERT INTO property_log (property_id, account_id, action, log_timestamp) VALUES (?, ?, 'SOLD', NOW())";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('ii', $property_id, $_SESSION['account_id']);
            $stmt->execute();
            $stmt->close();
        }
    }
        
        // Commit transaction
        $conn->commit();

        // ===== SEND EMAIL NOTIFICATIONS =====
        
        // Fetch property details and agent info for email
        $emailDataSql = "SELECT p.StreetAddress, p.City, p.PropertyType, p.ListingPrice,
                                a.first_name AS agent_first_name, a.last_name AS agent_last_name, a.email AS agent_email
                         FROM property p
                         LEFT JOIN accounts a ON a.account_id = ?
                         WHERE p.property_ID = ?";
        $emailStmt = $conn->prepare($emailDataSql);
        $emailStmt->bind_param('ii', $verification['agent_id'], $property_id);
        $emailStmt->execute();
        $emailData = $emailStmt->get_result()->fetch_assoc();
        $emailStmt->close();
        
        $propertyAddress = $emailData['StreetAddress'] . ', ' . $emailData['City'];
        $agentName = $emailData['agent_first_name'] . ' ' . $emailData['agent_last_name'];
        $agentEmail = $emailData['agent_email'];
        $formattedSalePrice = '₱' . number_format($verification['sale_price'], 2);
        $saleDate = date('F j, Y', strtotime($verification['sale_date']));
        
        // 1) Send email to AGENT
        if (!empty($agentEmail)) {
            $agentSubject = '🎉 Congratulations! Property Sale Approved';
            $agentBody = "
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset='UTF-8'>
                <style>
                    body { font-family: 'Inter', Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
                    .email-container { max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
                    .email-header { background: linear-gradient(135deg, #161209 0%, #2a2318 100%); color: #ffffff; padding: 40px 30px; text-align: center; }
                    .email-header h1 { margin: 0; font-size: 28px; font-weight: 700; }
                    .email-header .icon { font-size: 48px; margin-bottom: 10px; }
                    .email-body { padding: 40px 30px; }
                    .success-badge { display: inline-block; background: #d1e7dd; color: #0f5132; padding: 8px 16px; border-radius: 20px; font-weight: 600; font-size: 14px; margin-bottom: 20px; }
                    .email-body h2 { color: #161209; margin-top: 0; font-size: 22px; }
                    .email-body p { margin: 15px 0; color: #555; }
                    .info-box { background: #f8f9fa; border-left: 4px solid #bc9e42; padding: 20px; border-radius: 8px; margin: 25px 0; }
                    .info-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #e0e0e0; }
                    .info-row:last-child { border-bottom: none; }
                    .info-label { font-weight: 600; color: #6c757d; font-size: 14px; }
                    .info-value { color: #161209; font-weight: 600; text-align: right; }
                    .highlight-price { color: #bc9e42; font-size: 24px; font-weight: 700; }
                    .cta-button { display: inline-block; background: linear-gradient(135deg, #bc9e42, #d4b555); color: #161209; padding: 14px 30px; text-decoration: none; border-radius: 8px; font-weight: 600; margin: 20px 0; text-align: center; }
                    .email-footer { background: #f8f9fa; padding: 30px; text-align: center; color: #6c757d; font-size: 14px; border-top: 1px solid #e0e0e0; }
                    .email-footer p { margin: 5px 0; }
                </style>
            </head>
            <body>
                <div class='email-container'>
                    <div class='email-header'>
                        <div class='icon'>🎉</div>
                        <h1>Property Sale Approved!</h1>
                    </div>
                    <div class='email-body'>
                        <span class='success-badge'>✓ APPROVED</span>
                        <h2>Congratulations, {$agentName}!</h2>
                        <p>Great news! The property sale verification you submitted has been <strong>approved</strong> by the admin team.</p>
                        
                        <div class='info-box'>
                            <div class='info-row'>
                                <span class='info-label'>Property Address:</span>
                                <span class='info-value'>{$propertyAddress}</span>
                            </div>
                            <div class='info-row'>
                                <span class='info-label'>Property Type:</span>
                                <span class='info-value'>{$emailData['PropertyType']}</span>
                            </div>
                            <div class='info-row'>
                                <span class='info-label'>Sale Price:</span>
                                <span class='info-value highlight-price'>{$formattedSalePrice}</span>
                            </div>
                            <div class='info-row'>
                                <span class='info-label'>Sale Date:</span>
                                <span class='info-value'>{$saleDate}</span>
                            </div>
                            <div class='info-row'>
                                <span class='info-label'>Buyer Name:</span>
                                <span class='info-value'>{$verification['buyer_name']}</span>
                            </div>
                        </div>
                        
                        <p><strong>What happens next?</strong></p>
                        <ul style='color: #555; padding-left: 20px;'>
                            <li>The property has been marked as <strong>SOLD</strong> in the system</li>
                            <li>Commission processing will be initiated shortly</li>
                            <li>You'll receive further details about your commission payout</li>
                            <li>This sale will be reflected in your performance dashboard</li>
                        </ul>
                        
                        <p style='margin-top: 30px;'>Thank you for your excellent work! This successful sale contributes to your outstanding track record.</p>
                        
                        <a href='http://localhost/capstoneSystem/agent_pages/agent_dashboard.php' class='cta-button'>View Dashboard</a>
                    </div>
                    <div class='email-footer'>
                        <p><strong>Property Management System</strong></p>
                        <p>This is an automated notification. Please do not reply to this email.</p>
                        <p style='margin-top: 15px; font-size: 12px;'>© 2025 Property Management System. All rights reserved.</p>
                    </div>
                </div>
            </body>
            </html>
            ";
            
            sendEmail($agentEmail, $agentSubject, $agentBody);
        }
        
        // 2) Send email to BUYER
        if (!empty($buyerEmail)) {
            $buyerSubject = '🏡 Property Purchase Confirmed - Congratulations!';
            $buyerBody = "
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset='UTF-8'>
                <style>
                    body { font-family: 'Inter', Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
                    .email-container { max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
                    .email-header { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: #ffffff; padding: 40px 30px; text-align: center; }
                    .email-header h1 { margin: 0; font-size: 28px; font-weight: 700; }
                    .email-header .icon { font-size: 48px; margin-bottom: 10px; }
                    .email-body { padding: 40px 30px; }
                    .success-badge { display: inline-block; background: #d1e7dd; color: #0f5132; padding: 8px 16px; border-radius: 20px; font-weight: 600; font-size: 14px; margin-bottom: 20px; }
                    .email-body h2 { color: #161209; margin-top: 0; font-size: 22px; }
                    .email-body p { margin: 15px 0; color: #555; }
                    .info-box { background: #f8f9fa; border-left: 4px solid #28a745; padding: 20px; border-radius: 8px; margin: 25px 0; }
                    .info-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #e0e0e0; }
                    .info-row:last-child { border-bottom: none; }
                    .info-label { font-weight: 600; color: #6c757d; font-size: 14px; }
                    .info-value { color: #161209; font-weight: 600; text-align: right; }
                    .highlight-price { color: #28a745; font-size: 24px; font-weight: 700; }
                    .congratulations-box { background: linear-gradient(135deg, #fffbf0 0%, #fff9e6 100%); border: 2px solid #bc9e42; padding: 20px; border-radius: 8px; margin: 25px 0; text-align: center; }
                    .email-footer { background: #f8f9fa; padding: 30px; text-align: center; color: #6c757d; font-size: 14px; border-top: 1px solid #e0e0e0; }
                    .email-footer p { margin: 5px 0; }
                </style>
            </head>
            <body>
                <div class='email-container'>
                    <div class='email-header'>
                        <div class='icon'>🏡</div>
                        <h1>Congratulations on Your New Property!</h1>
                    </div>
                    <div class='email-body'>
                        <span class='success-badge'>✓ PURCHASE CONFIRMED</span>
                        <h2>Dear {$verification['buyer_name']},</h2>
                        <p>Congratulations! Your property purchase has been officially <strong>approved and confirmed</strong>.</p>
                        
                        <div class='congratulations-box'>
                            <h3 style='margin: 0 0 10px 0; color: #bc9e42;'>🎊 Welcome to Your New Home! 🎊</h3>
                            <p style='margin: 0; font-size: 16px;'>You are now the proud owner of this beautiful property.</p>
                        </div>
                        
                        <div class='info-box'>
                            <div class='info-row'>
                                <span class='info-label'>Property Address:</span>
                                <span class='info-value'>{$propertyAddress}</span>
                            </div>
                            <div class='info-row'>
                                <span class='info-label'>Property Type:</span>
                                <span class='info-value'>{$emailData['PropertyType']}</span>
                            </div>
                            <div class='info-row'>
                                <span class='info-label'>Purchase Price:</span>
                                <span class='info-value highlight-price'>{$formattedSalePrice}</span>
                            </div>
                            <div class='info-row'>
                                <span class='info-label'>Sale Date:</span>
                                <span class='info-value'>{$saleDate}</span>
                            </div>
                            <div class='info-row'>
                                <span class='info-label'>Your Agent:</span>
                                <span class='info-value'>{$agentName}</span>
                            </div>
                        </div>
                        
                        <p><strong>Next Steps:</strong></p>
                        <ul style='color: #555; padding-left: 20px;'>
                            <li>Your agent will contact you shortly to finalize documentation</li>
                            <li>Ensure all legal paperwork is completed and filed</li>
                            <li>Schedule your property handover and key collection</li>
                            <li>Update your contact information for property records</li>
                        </ul>
                        
                        <p style='margin-top: 30px;'>We wish you many happy years in your new home! Thank you for choosing to work with us.</p>
                        
                        <p style='margin-top: 20px; font-size: 14px; color: #6c757d;'><em>For any questions or assistance, please contact your agent {$agentName} or our support team.</em></p>
                    </div>
                    <div class='email-footer'>
                        <p><strong>Property Management System</strong></p>
                        <p>This is an automated notification. Please do not reply to this email.</p>
                        <p style='margin-top: 15px; font-size: 12px;'>© 2025 Property Management System. All rights reserved.</p>
                    </div>
                </div>
            </body>
            </html>
            ";
            
            sendEmail($buyerEmail, $buyerSubject, $buyerBody);
        }
        
        // ===== END EMAIL NOTIFICATIONS =====

        // Trigger internal processes after successful approval

        // 1) Notification for property sale (use allowed enum 'property_sale')
        $finance_message = "Property sale approved - Commission queuing required for Property #{$property_id}, Agent ID: {$verification['agent_id']}, Sale Price: $" . number_format($verification['sale_price'], 2);
        $finance_sql = "INSERT INTO notifications (item_id, item_type, message, created_at) VALUES (?, 'property_sale', ?, NOW())";
        $stmt = $conn->prepare($finance_sql);
        $stmt->bind_param('is', $verification_id, $finance_message);
        $stmt->execute();
        $stmt->close();

        // Agent notification — sale approved
        require_once __DIR__ . '/agent_pages/agent_notification_helper.php';
        createAgentNotification(
            $conn,
            (int)$verification['agent_id'],
            'sale_approved',
            'Sale Approved',
            "Your sale for Property #{$property_id} has been approved! Sale price: ₱" . number_format($verification['sale_price'], 2) . ". Commission will be processed shortly.",
            $verification_id
        );

        // Agent performance is now tracked via finalized_sales and agent_commissions tables
        // No need for separate agent_performance table

        // Redirect back with success message
        header('Location: ' . $_SERVER['PHP_SELF'] . '?success=approved');
        exit;
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $error_message = 'Error approving sale verification: ' . $e->getMessage();
    }
}

// Handle rejection action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject' && isset($_POST['verification_id']) && isset($_POST['reason'])) {
    $verification_id = (int)$_POST['verification_id'];
    $reason = trim($_POST['reason']);
    
    try {
        // Fetch verification and related data before rejection
        $fetchSql = "SELECT sv.*, p.StreetAddress, p.City, p.PropertyType, p.ListingPrice, p.property_ID,
                            a.first_name AS agent_first_name, a.last_name AS agent_last_name, a.email AS agent_email
                     FROM sale_verifications sv
                     LEFT JOIN property p ON p.property_ID = sv.property_id
                     LEFT JOIN accounts a ON a.account_id = sv.agent_id
                     WHERE sv.verification_id = ?";
        $fetchStmt = $conn->prepare($fetchSql);
        $fetchStmt->bind_param('i', $verification_id);
        $fetchStmt->execute();
        $rejectionData = $fetchStmt->get_result()->fetch_assoc();
        $fetchStmt->close();
        
        if (!$rejectionData) {
            throw new Exception('Sale verification not found');
        }
        
        // Update sale verification status to Rejected with reason
        $sql = "UPDATE sale_verifications SET status = 'Rejected', admin_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE verification_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sii', $reason, $_SESSION['account_id'], $verification_id);
        $stmt->execute();
        $stmt->close();
        
        // Revert property status back to "For Sale" since the sale request was rejected
        if (!empty($rejectionData['property_ID'])) {
            $propId = (int)$rejectionData['property_ID'];
            $revertSql = "UPDATE property SET Status = 'For Sale', is_locked = 0 WHERE property_ID = ?";
            $revertStmt = $conn->prepare($revertSql);
            $revertStmt->bind_param('i', $propId);
            $revertStmt->execute();
            $revertStmt->close();
        }
        
        // Log the rejection action
        $sql = "INSERT INTO status_log (item_id, item_type, action, reason_message, action_by_account_id) VALUES (?, 'property', 'rejected', ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('isi', $verification_id, $reason, $_SESSION['account_id']);
        $stmt->execute();
        $stmt->close();

        // Also record the rejection in property_log (use property ID where available)
        if (!empty($rejectionData['property_ID'])) {
            $propId = (int)$rejectionData['property_ID'];
            // Try extended property_log insert that captures the rejection reason and verification reference
            $extendedLogSql = "INSERT INTO property_log (property_id, account_id, action, log_timestamp, reason_message, reference_id) VALUES (?, ?, 'REJECTED', NOW(), ?, ?)";
            $logStmt = $conn->prepare($extendedLogSql);
            if ($logStmt) {
                $logStmt->bind_param('iisi', $propId, $_SESSION['account_id'], $reason, $verification_id);
                $logStmt->execute();
                $logStmt->close();
            } else {
                // Fallback to minimal insert for older schemas
                $logSql = "INSERT INTO property_log (property_id, account_id, action, log_timestamp) VALUES (?, ?, 'REJECTED', NOW())";
                $logStmt2 = $conn->prepare($logSql);
                if ($logStmt2) {
                    $logStmt2->bind_param('ii', $propId, $_SESSION['account_id']);
                    $logStmt2->execute();
                    $logStmt2->close();
                }
            }
        }
        
        // ===== SEND REJECTION EMAIL NOTIFICATIONS =====
        
        $propertyAddress = $rejectionData['StreetAddress'] . ', ' . $rejectionData['City'];
        $agentName = $rejectionData['agent_first_name'] . ' ' . $rejectionData['agent_last_name'];
        $agentEmail = $rejectionData['agent_email'];
        $formattedSalePrice = '₱' . number_format($rejectionData['sale_price'], 2);
        
        // Determine buyer email
        $buyerEmail = null;
        if (!empty($rejectionData['buyer_contact']) && filter_var($rejectionData['buyer_contact'], FILTER_VALIDATE_EMAIL)) {
            $buyerEmail = $rejectionData['buyer_contact'];
        } else {
            $tr = $conn->prepare("SELECT user_email FROM tour_requests WHERE property_id = ? ORDER BY requested_at DESC LIMIT 1");
            $tr->bind_param('i', $rejectionData['property_ID']);
            $tr->execute();
            $trRes = $tr->get_result();
            $trRow = $trRes ? $trRes->fetch_assoc() : null;
            $tr->close();
            if ($trRow && !empty($trRow['user_email'])) {
                $buyerEmail = $trRow['user_email'];
            }
        }
        
        // 1) Send email to AGENT
        if (!empty($agentEmail)) {
            $agentSubject = '⚠️ Property Sale Verification - Action Required';
            $agentBody = "
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset='UTF-8'>
                <style>
                    body { font-family: 'Inter', Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
                    .email-container { max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
                    .email-header { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: #ffffff; padding: 40px 30px; text-align: center; }
                    .email-header h1 { margin: 0; font-size: 28px; font-weight: 700; }
                    .email-header .icon { font-size: 48px; margin-bottom: 10px; }
                    .email-body { padding: 40px 30px; }
                    .warning-badge { display: inline-block; background: #f8d7da; color: #842029; padding: 8px 16px; border-radius: 20px; font-weight: 600; font-size: 14px; margin-bottom: 20px; }
                    .email-body h2 { color: #161209; margin-top: 0; font-size: 22px; }
                    .email-body p { margin: 15px 0; color: #555; }
                    .info-box { background: #f8f9fa; border-left: 4px solid #dc3545; padding: 20px; border-radius: 8px; margin: 25px 0; }
                    .info-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #e0e0e0; }
                    .info-row:last-child { border-bottom: none; }
                    .info-label { font-weight: 600; color: #6c757d; font-size: 14px; }
                    .info-value { color: #161209; font-weight: 600; text-align: right; }
                    .reason-box { background: #fff3cd; border: 2px solid #ffc107; padding: 20px; border-radius: 8px; margin: 25px 0; }
                    .reason-box h3 { margin: 0 0 10px 0; color: #856404; font-size: 16px; }
                    .reason-box p { margin: 0; color: #856404; font-weight: 500; }
                    .cta-button { display: inline-block; background: linear-gradient(135deg, #dc3545, #c82333); color: #ffffff; padding: 14px 30px; text-decoration: none; border-radius: 8px; font-weight: 600; margin: 20px 0; text-align: center; }
                    .email-footer { background: #f8f9fa; padding: 30px; text-align: center; color: #6c757d; font-size: 14px; border-top: 1px solid #e0e0e0; }
                    .email-footer p { margin: 5px 0; }
                </style>
            </head>
            <body>
                <div class='email-container'>
                    <div class='email-header'>
                        <div class='icon'>⚠️</div>
                        <h1>Sale Verification Rejected</h1>
                    </div>
                    <div class='email-body'>
                        <span class='warning-badge'>✗ REJECTED</span>
                        <h2>Dear {$agentName},</h2>
                        <p>Your property sale verification has been <strong>reviewed and rejected</strong> by the admin team. This requires your attention.</p>
                        
                        <div class='info-box'>
                            <div class='info-row'>
                                <span class='info-label'>Property Address:</span>
                                <span class='info-value'>{$propertyAddress}</span>
                            </div>
                            <div class='info-row'>
                                <span class='info-label'>Property Type:</span>
                                <span class='info-value'>{$rejectionData['PropertyType']}</span>
                            </div>
                            <div class='info-row'>
                                <span class='info-label'>Submitted Sale Price:</span>
                                <span class='info-value'>{$formattedSalePrice}</span>
                            </div>
                            <div class='info-row'>
                                <span class='info-label'>Buyer Name:</span>
                                <span class='info-value'>{$rejectionData['buyer_name']}</span>
                            </div>
                        </div>
                        
                        <div class='reason-box'>
                            <h3>📋 Reason for Rejection:</h3>
                            <p>{$reason}</p>
                        </div>
                        
                        <p><strong>What you need to do:</strong></p>
                        <ul style='color: #555; padding-left: 20px;'>
                            <li>Review the rejection reason carefully</li>
                            <li>Address any issues or missing information</li>
                            <li>Gather correct documentation if needed</li>
                            <li>Resubmit the sale verification with accurate details</li>
                            <li>Contact admin support if you need clarification</li>
                        </ul>
                        
                        <p style='margin-top: 30px;'>Please ensure all information and documentation is accurate before resubmitting. If you have any questions, don't hesitate to reach out to our support team.</p>
                        
                        <a href='http://localhost/capstoneSystem/agent_pages/agent_dashboard.php' class='cta-button'>Go to Dashboard</a>
                    </div>
                    <div class='email-footer'>
                        <p><strong>Property Management System</strong></p>
                        <p>This is an automated notification. Please do not reply to this email.</p>
                        <p style='margin-top: 15px; font-size: 12px;'>© 2025 Property Management System. All rights reserved.</p>
                    </div>
                </div>
            </body>
            </html>
            ";
            
            sendEmail($agentEmail, $agentSubject, $agentBody);
        }
        
        // 2) Send email to BUYER (if applicable)
        if (!empty($buyerEmail)) {
            $buyerSubject = '📋 Property Purchase Update - Verification Under Review';
            $buyerBody = "
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset='UTF-8'>
                <style>
                    body { font-family: 'Inter', Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
                    .email-container { max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
                    .email-header { background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%); color: #ffffff; padding: 40px 30px; text-align: center; }
                    .email-header h1 { margin: 0; font-size: 28px; font-weight: 700; }
                    .email-header .icon { font-size: 48px; margin-bottom: 10px; }
                    .email-body { padding: 40px 30px; }
                    .info-badge { display: inline-block; background: #fff3cd; color: #856404; padding: 8px 16px; border-radius: 20px; font-weight: 600; font-size: 14px; margin-bottom: 20px; }
                    .email-body h2 { color: #161209; margin-top: 0; font-size: 22px; }
                    .email-body p { margin: 15px 0; color: #555; }
                    .info-box { background: #f8f9fa; border-left: 4px solid #ffc107; padding: 20px; border-radius: 8px; margin: 25px 0; }
                    .info-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #e0e0e0; }
                    .info-row:last-child { border-bottom: none; }
                    .info-label { font-weight: 600; color: #6c757d; font-size: 14px; }
                    .info-value { color: #161209; font-weight: 600; text-align: right; }
                    .email-footer { background: #f8f9fa; padding: 30px; text-align: center; color: #6c757d; font-size: 14px; border-top: 1px solid #e0e0e0; }
                    .email-footer p { margin: 5px 0; }
                </style>
            </head>
            <body>
                <div class='email-container'>
                    <div class='email-header'>
                        <div class='icon'>📋</div>
                        <h1>Purchase Verification Update</h1>
                    </div>
                    <div class='email-body'>
                        <span class='info-badge'>ℹ️ UNDER REVIEW</span>
                        <h2>Dear {$rejectionData['buyer_name']},</h2>
                        <p>We're writing to inform you that the sale verification for your property purchase requires additional review.</p>
                        
                        <div class='info-box'>
                            <div class='info-row'>
                                <span class='info-label'>Property Address:</span>
                                <span class='info-value'>{$propertyAddress}</span>
                            </div>
                            <div class='info-row'>
                                <span class='info-label'>Property Type:</span>
                                <span class='info-value'>{$rejectionData['PropertyType']}</span>
                            </div>
                            <div class='info-row'>
                                <span class='info-label'>Your Agent:</span>
                                <span class='info-value'>{$agentName}</span>
                            </div>
                        </div>
                        
                        <p><strong>What does this mean?</strong></p>
                        <p>Some details in the sale verification submitted by your agent need to be corrected or clarified. Your agent has been notified and will address this promptly.</p>
                        
                        <p><strong>What happens next?</strong></p>
                        <ul style='color: #555; padding-left: 20px;'>
                            <li>Your agent will review and correct the submission</li>
                            <li>Once corrected, the verification will be resubmitted for approval</li>
                            <li>You'll be notified once everything is finalized</li>
                            <li>This is a normal part of ensuring accuracy</li>
                        </ul>
                        
                        <p style='margin-top: 30px;'>Your agent {$agentName} will be in contact with you shortly. If you have any immediate concerns, please reach out to them directly.</p>
                        
                        <p style='margin-top: 20px; font-size: 14px; color: #6c757d;'><em>We apologize for any inconvenience and appreciate your patience as we ensure all details are correct.</em></p>
                    </div>
                    <div class='email-footer'>
                        <p><strong>Property Management System</strong></p>
                        <p>This is an automated notification. Please do not reply to this email.</p>
                        <p style='margin-top: 15px; font-size: 12px;'>© 2025 Property Management System. All rights reserved.</p>
                    </div>
                </div>
            </body>
            </html>
            ";
            
            sendEmail($buyerEmail, $buyerSubject, $buyerBody);
        }
        
        // ===== END REJECTION EMAIL NOTIFICATIONS =====
        
        // Agent notification — sale rejected
        if (!function_exists('createAgentNotification')) {
            require_once __DIR__ . '/agent_pages/agent_notification_helper.php';
        }
        if (!empty($rejectionData['agent_id'])) {
            createAgentNotification(
                $conn,
                (int)$rejectionData['agent_id'],
                'sale_rejected',
                'Sale Rejected',
                "Your sale verification for {$propertyAddress} was rejected. Reason: {$reason}",
                $verification_id
            );
        }

        // Redirect back with success message
        header('Location: ' . $_SERVER['PHP_SELF'] . '?success=rejected');
        exit;
        
    } catch (Exception $e) {
        $error_message = 'Error rejecting sale verification: ' . $e->getMessage();
    }
}

// Handle success messages
$success_message = '';
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'approved') {
        $success_message = 'Property sale verification approved successfully! The property has been marked as SOLD.';
    } elseif ($_GET['success'] === 'rejected') {
        $success_message = 'Property sale verification rejected successfully.';
    }
}

$error_message = '';
$sale_verifications = [];
$status_counts = [
    'All' => 0,
    'Pending' => 0,
    'Approved' => 0,
    'Rejected' => 0,
];

// Fetch sale verifications with property and agent details
$sql = "
    SELECT 
        sv.*, 
        p.StreetAddress, p.City, p.property_ID, p.PropertyType, p.ListingPrice,
        a.first_name AS agent_first_name, a.last_name AS agent_last_name, a.email AS agent_email,
        (SELECT pi.PhotoURL FROM property_images pi 
         WHERE pi.property_ID = p.property_ID 
         ORDER BY pi.SortOrder ASC LIMIT 1) as property_image,
        (SELECT COUNT(*) FROM property_images pi WHERE pi.property_ID = p.property_ID) as property_image_count,
        (SELECT GROUP_CONCAT(
            CONCAT(
                '{\"url\":\"', REPLACE(pi.PhotoURL, '\"', '\\\"'), '\"',
                ',\"sort_order\":', COALESCE(pi.SortOrder, 0), '}'
            ) ORDER BY pi.SortOrder ASC SEPARATOR '|||'
         ) FROM property_images pi 
         WHERE pi.property_ID = p.property_ID) as property_images_json,
        (SELECT COUNT(*) FROM sale_verification_documents svd 
         WHERE svd.verification_id = sv.verification_id) as document_count,
        (SELECT GROUP_CONCAT(
            CONCAT(
                '{\"id\":', svd.document_id, 
                ',\"original_filename\":\"', REPLACE(svd.original_filename, '\"', '\\\"'), '\"',
                ',\"stored_filename\":\"', REPLACE(svd.stored_filename, '\"', '\\\"'), '\"',
                ',\"file_path\":\"', REPLACE(svd.file_path, '\"', '\\\"'), '\"',
                ',\"file_size\":', COALESCE(svd.file_size, 0),
                ',\"mime_type\":\"', COALESCE(svd.mime_type, ''), '\"',
                ',\"uploaded_at\":\"', svd.uploaded_at, '\"}'
            ) SEPARATOR '|||'
         ) FROM sale_verification_documents svd 
         WHERE svd.verification_id = sv.verification_id) as documents_json
    FROM sale_verifications sv
    LEFT JOIN property p ON p.property_ID = sv.property_id
    LEFT JOIN accounts a ON a.account_id = sv.agent_id
    ORDER BY 
        CASE sv.status
            WHEN 'Pending' THEN 1
            WHEN 'Approved' THEN 2
            WHEN 'Rejected' THEN 3
            ELSE 4
        END,
        sv.submitted_at DESC
";

$stmt = $conn->prepare($sql);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $status = $row['status'] ?: 'Pending';
    if (!isset($status_counts[$status])) $status_counts[$status] = 0;
    $status_counts[$status]++;
    $status_counts['All']++;

    $row['sale_date_fmt'] = $row['sale_date'] ? date('M j, Y', strtotime($row['sale_date'])) : '';
    $row['submitted_at_fmt'] = $row['submitted_at'] ? date('M j, Y g:i A', strtotime($row['submitted_at'])) : '';
    $row['reviewed_at_fmt'] = $row['reviewed_at'] ? date('M j, Y g:i A', strtotime($row['reviewed_at'])) : '';
    
    // Process documents JSON
    if ($row['documents_json']) {
        $documents = [];
        $doc_strings = explode('|||', $row['documents_json']);
        foreach ($doc_strings as $doc_string) {
            $documents[] = json_decode($doc_string, true);
        }
        $row['documents'] = $documents;
    } else {
        $row['documents'] = [];
    }
    
    // Process property images JSON
    if ($row['property_images_json']) {
        $property_images = [];
        $img_strings = explode('|||', $row['property_images_json']);
        foreach ($img_strings as $img_string) {
            $property_images[] = json_decode($img_string, true);
        }
        $row['property_images'] = $property_images;
    } else {
        $row['property_images'] = [];
    }
    
    $sale_verifications[] = $row;
}
$stmt->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Property Sale Approvals - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
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
        
        /* Use standardized admin-content from dashboard */
        .admin-content {
            margin-left: 290px;
            padding: 2rem;
            min-height: 100vh;
            max-width: 1800px;
        }
        
        @media (max-width: 1200px) {
            .admin-content {
                margin-left: 0 !important;
                padding: 1.5rem;
            }
        }
        
        @media (max-width: 768px) {
            .admin-content {
                margin-left: 0 !important;
                padding: 1rem;
            }
        }
        
        /* Dashboard Header - Consistent with admin_dashboard.php */
        .dashboard-header {
            background: linear-gradient(135deg, #161209 0%, #2a2318 100%);
            color: #fff;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        .dashboard-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .dashboard-header p {
            opacity: 0.9;
            margin-bottom: 0;
            font-size: 1rem;
        }
        
        /* Stats Cards - Consistent with dashboard */
        .stat-card {
            background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
            border: 1px solid var(--border-color);
            border-left: 4px solid var(--secondary-color);
            border-radius: 12px;
            padding: 1.5rem;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            min-height: 120px;
            display: flex;
            align-items: center;
        }
        
        .stat-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .stat-card.active {
            border-left-color: var(--secondary-color);
            background: linear-gradient(135deg, #fffbf0 0%, #fff9e6 100%);
            box-shadow: 0 4px 16px rgba(188, 158, 66, 0.2);
        }

        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
            margin-right: 1rem;
        }
        
        .stat-card .stat-label {
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            margin-bottom: 0.25rem;
        }
        
        .stat-card .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
            line-height: 1.2;
        }
        
        /* Sale Verification Cards - Redesigned for consistency */
        .sale-card {
            background: #fff;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: all 0.2s ease;
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        
        .sale-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .sale-card-image {
            position: relative;
            height: 200px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            overflow: hidden;
        }
        
        .sale-card-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .sale-card-image .property-type-badge {
            position: absolute;
            top: 12px;
            left: 12px;
            background: rgba(22, 18, 9, 0.9);
            color: #fff;
            padding: 0.35rem 0.75rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            backdrop-filter: blur(4px);
        }
        
        .sale-card-image .status-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            padding: 0.35rem 0.85rem;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            backdrop-filter: blur(4px);
        }
        
        .status-badge.pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-badge.approved {
            background: #d1e7dd;
            color: #0f5132;
        }
        
        .status-badge.rejected {
            background: #f8d7da;
            color: #842029;
        }
        
        .sale-card-body {
            padding: 1.5rem;
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        
        .property-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-color);
            margin: 0 0 0.5rem 0;
            line-height: 1.3;
            /* Single-line truncation with ellipsis to prevent wrapping to two lines */
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: block;
            max-width: 100%;
        }
        
        .property-location {
            font-size: 0.875rem;
            color: #6c757d;
            display: flex;
            align-items: center;
            gap: 0.35rem;
            margin-bottom: 1rem;
        }
        
        .property-location i {
            color: var(--secondary-color);
        }
        
        .sale-info {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        
        .sale-info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }
        
        .sale-info-row:last-child {
            margin-bottom: 0;
        }
        
        .sale-info-label {
            font-size: 0.75rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        
        .sale-info-value {
            font-size: 0.9rem;
            color: var(--primary-color);
            font-weight: 600;
        }
        
        .sale-price {
            font-size: 1.1rem;
            color: var(--secondary-color);
            font-weight: 700;
        }
        
        .buyer-info {
            background: #fffbf0;
            padding: 0.875rem;
            border-radius: 8px;
            border: 1px solid #f5ecd0;
            margin-bottom: 1rem;
        }
        
        .buyer-info-title {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--secondary-color);
            margin-bottom: 0.5rem;
            letter-spacing: 0.5px;
        }
        
        .buyer-detail {
            font-size: 0.875rem;
            color: var(--primary-color);
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .buyer-detail:last-child {
            margin-bottom: 0;
        }
        
        .buyer-detail i {
            width: 16px;
            color: var(--secondary-color);
        }
        
        .agent-info {
            padding: 0.75rem;
            background: #e8f4f8;
            border-radius: 8px;
            margin-bottom: 1rem;
            border: 1px solid #d1e7f0;
        }
        
        .agent-info-title {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            color: #0c5776;
            margin-bottom: 0.5rem;
            letter-spacing: 0.5px;
        }
        
        .agent-name {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .agent-name i {
            color: #0c5776;
        }
        
        .document-count {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            color: var(--secondary-color);
            font-weight: 600;
            margin-top: 0.5rem;
        }
        
        .card-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: auto;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }
        
        .btn-action {
            flex: 1;
            padding: 0.625rem;
            font-size: 0.875rem;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
        }
        
        .btn-view {
            background: var(--primary-color);
            color: #fff;
        }
        
        .btn-view:hover {
            background: var(--secondary-color);
            color: var(--primary-color);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(188, 158, 66, 0.3);
        }
        
        .btn-approve {
            background: #28a745;
            color: #fff;
        }
        
        .btn-approve:hover {
            background: #218838;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }
        
        .btn-reject {
            background: #dc3545;
            color: #fff;
        }
        
        .btn-reject:hover {
            background: #c82333;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: #fff;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .empty-state i {
            font-size: 3.5rem;
            color: #6c757d;
            opacity: 0.3;
            margin-bottom: 1rem;
        }
        
        .empty-state h3 {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }
        
        .empty-state p {
            color: #6c757d;
            margin: 0;
        }
        
        /* Alerts - Match dashboard styling */
        .alert {
            border-radius: 8px;
            border-left: 4px solid;
            margin-bottom: 1.5rem;
            padding: 1rem 1.25rem;
        }
        
        /* Modal Styles - Match tour_requests.php */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            z-index: 1050;
            opacity: 0;
            transition: opacity 0.2s ease;
        }
        
        .modal-overlay.show {
            display: block;
            opacity: 1;
        }
        
        .modal-container {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0.9);
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.15);
            max-width: 800px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            z-index: 1051;
            opacity: 0;
            transition: all 0.2s ease;
        }
        
        .modal-large {
            max-width: 1200px;
            width: 95%;
        }
        
        .modal-overlay.show .modal-container {
            opacity: 1;
            transform: translate(-50%, -50%) scale(1);
        }
        
        .modal-header {
            background: linear-gradient(135deg, #161209 0%, #2a2318 100%);
            color: white;
            padding: 1.5rem 2rem;
            border-radius: 12px 12px 0 0;
            border-bottom: none;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .modal-title {
            font-size: 1.25rem;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: white;
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 4px;
            transition: all 0.2s ease;
            opacity: 0.8;
        }
        
        .modal-close:hover {
            opacity: 1;
            background: rgba(255,255,255,0.1);
        }
        
        .modal-body {
            padding: 2rem;
        }
        
        .modal-footer {
            padding: 1.25rem 2rem;
            background: #f8f9fa;
            border-top: 1px solid #e9ecef;
            border-radius: 0 0 12px 12px;
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
        }
        
        .detail-section {
            margin-bottom: 2rem;
        }
        
        .detail-section:last-child {
            margin-bottom: 0;
        }
        
        .detail-title {
            font-size: 0.875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--secondary-color);
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #f8f9fa;
        }
        
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }
        
        .detail-item {
            display: flex;
            flex-direction: column;
        }
        
        .detail-label {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            margin-bottom: 0.25rem;
        }
        
        .detail-value {
            font-size: 0.95rem;
            color: var(--primary-color);
            font-weight: 500;
        }
        
        .detail-value.price {
            font-size: 1.1rem;
            color: var(--secondary-color);
            font-weight: 700;
        }
        
        .property-image-large {
            width: 100%;
            height: 250px;
            object-fit: cover;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        
        .status-display {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 600;
        }
        
        .status-display.pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-display.approved {
            background: #d1e7dd;
            color: #0f5132;
        }
        
        .status-display.rejected {
            background: #f8d7da;
            color: #842029;
        }
        
        .documents-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        
        .document-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem;
            background: #f8f9fa;
            border-radius: 4px;
            border: 1px solid var(--border-color);
        }
        
        .document-icon {
            font-size: 1.5rem;
            color: var(--secondary-color);
        }
        
        .document-info {
            flex: 1;
        }
        
        .document-name {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }
        
        .document-meta {
            font-size: 0.75rem;
            color: var(--text-secondary);
        }
        
        .document-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn-preview, .btn-download {
            padding: 0.375rem 0.75rem;
            font-size: 0.75rem;
            font-weight: 600;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .btn-preview {
            background: var(--secondary-color);
            color: var(--primary-color);
        }
        
        .btn-preview:hover {
            background: #a08636;
        }
        
        .btn-download {
            background: #28a745;
            color: #fff;
        }
        
        .btn-download:hover {
            background: #218838;
        }
        
        .btn-modal {
            padding: 0.625rem 1.5rem;
            font-size: 0.875rem;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .btn-modal:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .btn-primary {
            background: var(--primary-color);
            color: #fff;
        }
        
        .btn-primary:hover {
            background: var(--secondary-color);
            color: var(--primary-color);
        }
        
        .btn-success {
            background: #28a745;
            color: #fff;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .btn-danger {
            background: #dc3545;
            color: #fff;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: #fff;
        }
        
        .btn-secondary:hover {
            background: #545b62;
        }
        
        /* Property Gallery Styles */
        .property-gallery {
            position: relative;
            width: 100%;
            height: 300px;
            overflow: hidden;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 1rem;
        }
        
        .gallery-item {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            transition: opacity 0.3s ease;
            display: none;
        }
        
        .gallery-item.active {
            opacity: 1;
            display: block;
        }
        
        .gallery-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 8px;
        }
        
        .gallery-navigation {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .gallery-nav-btn {
            background: var(--secondary-color);
            color: var(--primary-color);
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            font-size: 1rem;
        }
        
        .gallery-nav-btn:hover:not(:disabled) {
            background: #a08636;
            transform: scale(1.1);
        }
        
        .gallery-nav-btn:disabled {
            background: #dee2e6;
            color: #6c757d;
            cursor: not-allowed;
            transform: none;
        }
        
        .gallery-indicators {
            display: flex;
            gap: 0.5rem;
        }
        
        .gallery-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: none;
            background: #dee2e6;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .gallery-indicator.active {
            background: var(--secondary-color);
            transform: scale(1.2);
        }
        
        .gallery-indicator:hover {
            background: #a08636;
        }
        
        .no-images {
            text-align: center;
        }

        /* Processing overlay */
        .processing-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(0, 0, 0, 0.45);
            z-index: 2000; /* Above custom modals */
        }

        .processing-overlay.show { display: flex; }

        .processing-box {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            background: rgba(22, 18, 9, 0.95);
            color: #fff;
            padding: 1rem 1.25rem;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.25);
            min-width: 260px;
            justify-content: center;
        }

        .processing-text {
            font-weight: 600;
            letter-spacing: 0.2px;
        }
    </style>
</head>
<body>
    <?php 
    $active_page = 'admin_property_sale_approvals.php';
    include 'admin_sidebar.php'; 
    include 'admin_navbar.php'; 
    ?>
    
    <div class="admin-content">
        <div class="container-fluid">
            <!-- Success Message -->
            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>

            <!-- Error Message -->
            <?php if ($error_message): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <!-- Dashboard Header -->
            <div class="dashboard-header">
                <h1><i class="bi bi-file-earmark-check me-2"></i>Property Sale Approvals</h1>
                <p>Review and approve property sale verifications submitted by agents</p>
            </div>

            <!-- Statistics -->
            <div class="row g-4 mb-4">
                <div class="col-xl-3 col-lg-6 col-md-6">
                    <div class="stat-card active" data-filter="All" onclick="filterSales('All')">
                        <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                            <i class="bi bi-list-ul"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="stat-label">Total Submissions</div>
                            <div class="stat-value"><?php echo $status_counts['All']; ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-lg-6 col-md-6">
                    <div class="stat-card" data-filter="Pending" onclick="filterSales('Pending')">
                        <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                            <i class="bi bi-clock-history"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="stat-label">Pending Review</div>
                            <div class="stat-value"><?php echo $status_counts['Pending']; ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-lg-6 col-md-6">
                    <div class="stat-card" data-filter="Approved" onclick="filterSales('Approved')">
                        <div class="stat-icon bg-success bg-opacity-10 text-success">
                            <i class="bi bi-tag-fill"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="stat-label">Sold</div>
                            <div class="stat-value"><?php echo $status_counts['Approved']; ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-lg-6 col-md-6">
                    <div class="stat-card" data-filter="Rejected" onclick="filterSales('Rejected')">
                        <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                            <i class="bi bi-x-circle"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="stat-label">Rejected</div>
                            <div class="stat-value"><?php echo $status_counts['Rejected']; ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sale Verification Cards -->
            <?php if (empty($sale_verifications)): ?>
                <div class="empty-state">
                    <i class="bi bi-inbox"></i>
                    <h3>No Sale Verifications Found</h3>
                    <p>There are no property sale verifications to display at this time.</p>
                </div>
            <?php else: ?>
                <div class="row g-4" id="salesGrid">
                    <?php foreach ($sale_verifications as $sale): ?>
                        <div class="col-xl-3 col-lg-4 col-md-6" data-status="<?php echo htmlspecialchars($sale['status']); ?>">
                            <div class="sale-card">
                                <!-- Property Image -->
                                <div class="sale-card-image">
                                    <?php if ($sale['property_image']): ?>
                                        <img src="<?php echo htmlspecialchars($sale['property_image']); ?>" 
                                             alt="<?php echo htmlspecialchars($sale['StreetAddress']); ?>"
                                             onerror="this.src='uploads/default-property.jpg'">
                                    <?php else: ?>
                                        <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#6c757d;">
                                            <i class="bi bi-image" style="font-size:3rem;"></i>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="property-type-badge">
                                        <i class="bi bi-house-door me-1"></i><?php echo htmlspecialchars($sale['PropertyType']); ?>
                                    </div>
                                    
                                    <?php
                                        // Display 'Sold' for approvals (keeps approved styling)
                                        $badgeClass = strtolower($sale['status']) === 'approved' ? 'approved' : strtolower($sale['status']);
                                        $badgeLabel = ($sale['status'] === 'Approved') ? 'Sold' : $sale['status'];
                                    ?>
                                    <div class="status-badge <?php echo $badgeClass; ?>">
                                        <?php echo htmlspecialchars($badgeLabel); ?>
                                    </div>
                                </div>
                        
                                <!-- Card Body -->
                                <div class="sale-card-body">
                                    <h3 class="property-title">
                                        <?php echo htmlspecialchars($sale['StreetAddress']); ?>
                                    </h3>
                                    <div class="property-location">
                                        <i class="bi bi-geo-alt-fill"></i>
                                        <span><?php echo htmlspecialchars($sale['City']); ?></span>
                                    </div>
                                    
                                    <!-- Sale Information -->
                                    <div class="sale-info">
                                        <div class="sale-info-row">
                                            <div class="sale-info-label">Sale Price</div>
                                            <div class="sale-info-value sale-price">
                                                ₱<?php echo number_format($sale['sale_price'], 2); ?>
                                            </div>
                                        </div>
                                        <div class="sale-info-row">
                                            <div class="sale-info-label">Listing Price</div>
                                            <div class="sale-info-value">
                                                ₱<?php echo number_format($sale['ListingPrice'], 2); ?>
                                            </div>
                                        </div>
                                        <div class="sale-info-row">
                                            <div class="sale-info-label">Sale Date</div>
                                            <div class="sale-info-value">
                                                <?php echo htmlspecialchars($sale['sale_date_fmt']); ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Buyer Information -->
                                    <div class="buyer-info">
                                        <div class="buyer-info-title"><i class="bi bi-person-fill me-2"></i>Buyer</div>
                                        <div class="buyer-detail">
                                            <i class="bi bi-person"></i>
                                            <span><?php echo htmlspecialchars($sale['buyer_name']); ?></span>
                                        </div>
                                        <?php if ($sale['buyer_contact']): ?>
                                            <div class="buyer-detail">
                                                <i class="bi bi-envelope"></i>
                                                <span><?php echo htmlspecialchars($sale['buyer_contact']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Agent Information -->
                                    <div class="agent-info">
                                        <div class="agent-info-title"><i class="bi bi-person-badge me-2"></i>Agent</div>
                                        <div class="agent-name">
                                            <i class="bi bi-person-check"></i>
                                            <?php echo htmlspecialchars($sale['agent_first_name'] . ' ' . $sale['agent_last_name']); ?>
                                        </div>
                                        <?php if ($sale['document_count'] > 0): ?>
                                            <div class="document-count">
                                                <i class="bi bi-file-earmark-text"></i>
                                                <?php echo $sale['document_count']; ?> Document<?php echo $sale['document_count'] > 1 ? 's' : ''; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Actions -->
                                    <div class="card-actions">
                                        <button class="btn-action btn-view" 
                                                onclick="viewDetails(<?php echo $sale['verification_id']; ?>)">
                                            <i class="bi bi-eye"></i> View
                                        </button>
                                        <?php if ($sale['status'] === 'Pending'): ?>
                                            <button class="btn-action btn-approve" 
                                                    onclick="approveVerification(<?php echo $sale['verification_id']; ?>)">
                                                <i class="bi bi-check-lg"></i> Approve
                                            </button>
                                            <button class="btn-action btn-reject" 
                                                    onclick="rejectVerification(<?php echo $sale['verification_id']; ?>)">
                                                <i class="bi bi-x-lg"></i> Reject
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal for View Details -->
    <div class="modal-overlay" id="detailsModal">
        <div class="modal-container">
            <div class="modal-header">
                <h2 class="modal-title"><i class="bi bi-file-earmark-check me-2"></i>Sale Verification Details</h2>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalContent">
                <!-- Content will be populated by JavaScript -->
            </div>
            <div class="modal-footer">
                <div id="modalActions">
                    <!-- Action buttons will be populated by JavaScript -->
                </div>
                <button class="btn-modal btn-secondary" onclick="closeModal()">
                    <i class="bi bi-x-lg me-2"></i>Close
                </button>
            </div>
        </div>
    </div>

    <!-- Modal for Document Preview -->
    <div class="modal-overlay" id="previewModal">
        <div class="modal-container modal-large">
            <div class="modal-header">
                <h2 class="modal-title" id="previewTitle"><i class="bi bi-file-earmark-text me-2"></i>Document Preview</h2>
                <button class="modal-close" onclick="closePreviewModal()">&times;</button>
            </div>
            <div class="modal-body" id="previewContent">
                <!-- Document preview will be shown here -->
            </div>
            <div class="modal-footer">
                <button class="btn-modal btn-primary" id="downloadBtn" onclick="downloadCurrentDocument()">
                    <i class="bi bi-download me-2"></i> Download
                </button>
                <button class="btn-modal btn-secondary" onclick="closePreviewModal()">
                    <i class="bi bi-x-lg me-2"></i>Close
                </button>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div class="modal-overlay" id="confirmationModal">
        <div class="modal-container">
            <div class="modal-header">
                <h2 class="modal-title" id="confirmationModalLabel">
                    <i class="bi bi-question-circle me-2"></i>Confirm Action
                </h2>
                <button class="modal-close" onclick="closeConfirmationModal()">&times;</button>
            </div>
            <div class="modal-body" id="confirmationModalBody">
                <!-- Dynamic content -->
            </div>
            <div class="modal-footer">
                <button class="btn-modal btn-secondary" onclick="closeConfirmationModal()">
                    <i class="bi bi-x-lg me-2"></i>Cancel
                </button>
                <button class="btn-modal btn-primary" id="confirmActionBtn">
                    <i class="bi bi-check-lg me-2"></i>Confirm
                </button>
            </div>
        </div>
    </div>

    <!-- Input Modal (for rejection reason) -->
    <div class="modal-overlay" id="inputModal">
        <div class="modal-container">
            <div class="modal-header">
                <h2 class="modal-title" id="inputModalLabel">
                    <i class="bi bi-chat-left-text me-2"></i>Provide Reason
                </h2>
                <button class="modal-close" onclick="closeInputModal()">&times;</button>
            </div>
            <div class="modal-body">
                <label for="reasonInput" class="form-label fw-bold" id="inputModalPrompt" style="color: #161209; margin-bottom: 0.5rem;">Please provide a reason:</label>
                <textarea class="form-control" id="reasonInput" rows="4" placeholder="Enter your reason here..." style="border: 1px solid #e0e0e0; border-radius: 8px; padding: 0.75rem;"></textarea>
                <div style="color: #dc3545; font-size: 0.875rem; margin-top: 0.5rem; display: none;" id="reasonError">
                    This field is required.
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-modal btn-secondary" onclick="closeInputModal()">
                    <i class="bi bi-x-lg me-2"></i>Cancel
                </button>
                <button class="btn-modal btn-danger" id="submitReasonBtn">
                    <i class="bi bi-check-lg me-2"></i>Submit
                </button>
            </div>
        </div>
    </div>

    <!-- Processing Overlay -->
    <div id="processingOverlay" class="processing-overlay" aria-hidden="true">
        <div class="processing-box" role="status" aria-live="polite">
            <div class="spinner-border text-light" style="width: 1.5rem; height: 1.5rem;" aria-hidden="true"></div>
            <div class="processing-text">Processing, please wait…</div>
        </div>
    </div>

    <!-- Bootstrap 5 Modal: Finalize Sale & Commission -->
    <div class="modal fade" id="finalizeSaleModal" tabindex="-1" aria-labelledby="finalizeSaleLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #161209 0%, #2a2318 100%); color: #fff;">
                    <h5 class="modal-title" id="finalizeSaleLabel"><i class="bi bi-cash-coin me-2"></i>Finalize Sale & Commission</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="finalizeSaleForm">
                    <div class="modal-body">
                        <input type="hidden" name="property_id" id="finalize_property_id">
                        <input type="hidden" name="agent_id" id="finalize_agent_id">

                        <div class="mb-3">
                            <label for="final_sale_price" class="form-label fw-semibold">Final Sale Price</label>
                            <input type="number" step="0.01" min="0" class="form-control" id="final_sale_price" name="final_sale_price" placeholder="e.g. 3500000" required>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="buyer_name" class="form-label fw-semibold">Buyer Name</label>
                                <input type="text" class="form-control" id="buyer_name" name="buyer_name" placeholder="Juan Dela Cruz">
                            </div>
                            <div class="col-md-6">
                                <label for="buyer_email" class="form-label fw-semibold">Buyer Email</label>
                                <input type="email" class="form-control" id="buyer_email" name="buyer_email" placeholder="buyer@example.com">
                            </div>
                        </div>
                        <div class="mt-3">
                            <label for="buyer_contact" class="form-label fw-semibold">Buyer Contact</label>
                            <input type="text" class="form-control" id="buyer_contact" name="buyer_contact" placeholder="(+63) 900 000 0000">
                        </div>
                        <div class="mt-3">
                            <label for="commission_percentage" class="form-label fw-semibold">Commission Percentage (%)</label>
                            <input type="number" step="0.01" min="0" max="100" class="form-control" id="commission_percentage" name="commission_percentage" placeholder="e.g. 3" required>
                        </div>
                        <div class="mt-3">
                            <label for="notes" class="form-label fw-semibold">Notes (optional)</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="Any additional context..."></textarea>
                        </div>
                        <div class="mt-3 small text-muted" id="finalizeHelp"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Save & Calculate</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Bootstrap (for finalize modal)
        let finalizeModalInstance = null;
        document.addEventListener('DOMContentLoaded', function() {
            if (window.bootstrap) {
                const modalEl = document.getElementById('finalizeSaleModal');
                if (modalEl) finalizeModalInstance = new bootstrap.Modal(modalEl);
            }
        });

        // Processing overlay helpers
        function showProcessing(message) {
            const overlay = document.getElementById('processingOverlay');
            const textEl = overlay.querySelector('.processing-text');
            if (message && textEl) textEl.textContent = message;
            overlay.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function hideProcessing() {
            const overlay = document.getElementById('processingOverlay');
            overlay.classList.remove('show');
            document.body.style.overflow = '';
        }

        // Store sale verifications data for modal
        const saleVerifications = <?php echo json_encode($sale_verifications); ?>;
        
        // Filter functionality
        document.querySelectorAll('.stat-card').forEach(card => {
            card.addEventListener('click', function() {
                const filter = this.dataset.filter;
                
                // Update active state
                document.querySelectorAll('.stat-card').forEach(c => c.classList.remove('active'));
                this.classList.add('active');
                
                // Filter grid items that contain the data-status attribute (the column wrapper)
                const cards = document.querySelectorAll('#salesGrid [data-status]');
                let visibleCount = 0;

                cards.forEach(card => {
                    // data-status lives on the wrapper (col-*) element, not the inner .sale-card
                    const status = card.getAttribute('data-status') || '';
                    if (filter === 'All' || status === filter) {
                        card.style.display = 'block';
                        visibleCount++;
                    } else {
                        card.style.display = 'none';
                    }
                });
                
                // Handle empty state
                const grid = document.getElementById('salesGrid');
                let emptyState = grid.querySelector('.empty-state');
                
                if (visibleCount === 0) {
                    // Show empty state
                    if (!emptyState) {
                        emptyState = document.createElement('div');
                        emptyState.className = 'empty-state col-12';
                        grid.appendChild(emptyState);
                    }
                    emptyState.innerHTML = `
                        <i class="bi bi-clipboard-x"></i>
                        <h3>No ${filter} Verifications</h3>
                        <p>There are no ${filter.toLowerCase()} verifications to display.</p>
                    `;
                } else {
                    // Remove empty state if it exists
                    if (emptyState) {
                        emptyState.remove();
                    }
                }
            });
        });
        
        function viewDetails(verificationId) {
            const sale = saleVerifications.find(s => s.verification_id == verificationId);
            if (!sale) return;
            // Expose current sale for finalize workflow
            window.currentViewedSale = sale;
            
            const modalContent = document.getElementById('modalContent');
            const modalActions = document.getElementById('modalActions');
            
            // Format status for display — show 'Sold' when status is 'Approved', but keep approved styling
            const statusClass = (sale.status && sale.status.toLowerCase() === 'approved') ? 'approved' : (sale.status || '').toLowerCase();
            const statusLabel = (sale.status === 'Approved') ? 'Sold' : (sale.status || '');
            const statusDisplay = `<span class="status-display ${statusClass}"><i class="bi bi-circle-fill"></i> ${statusLabel}</span>`;
            
            // Build modal content
            modalContent.innerHTML = `
                <!-- Property Images Gallery -->
                <div class="detail-section">
                    <h3 class="detail-title"><i class="bi bi-images me-2"></i>Property Images (${sale.property_image_count || 0})</h3>
                    ${sale.property_images && sale.property_images.length > 0 ? `
                        <div class="property-gallery">
                            ${sale.property_images.map((image, index) => `
                                <div class="gallery-item ${index === 0 ? 'active' : ''}" data-index="${index}">
                                    <img src="${image.url}" alt="Property Image ${index + 1}" class="gallery-image">
                                </div>
                            `).join('')}
                        </div>
                        ${sale.property_images.length > 1 ? `
                            <div class="gallery-navigation">
                                <button class="gallery-nav-btn" onclick="previousImage()" id="prevBtn" disabled>
                                    <i class="bi bi-chevron-left"></i>
                                </button>
                                <div class="gallery-indicators">
                                    ${sale.property_images.map((_, index) => `
                                        <button class="gallery-indicator ${index === 0 ? 'active' : ''}" 
                                                onclick="goToImage(${index})" 
                                                data-index="${index}"></button>
                                    `).join('')}
                                </div>
                                <button class="gallery-nav-btn" onclick="nextImage()" id="nextBtn">
                                    <i class="bi bi-chevron-right"></i>
                                </button>
                            </div>
                        ` : ''}
                    ` : `
                        <div class="no-images">
                            <img src="https://via.placeholder.com/800x250?text=No+Images" alt="No images" class="property-image-large">
                        </div>
                    `}
                </div>
                
                <!-- Property Details -->
                <div class="detail-section">
                    <h3 class="detail-title"><i class="bi bi-building me-2"></i>Property Information</h3>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="detail-label">Property Address</div>
                            <div class="detail-value">${sale.StreetAddress}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">City</div>
                            <div class="detail-value">${sale.City}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Property Type</div>
                            <div class="detail-value">${sale.PropertyType}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Listing Price</div>
                            <div class="detail-value">₱${Number(sale.ListingPrice).toLocaleString()}</div>
                        </div>
                    </div>
                </div>
                
                <!-- Sale Details -->
                <div class="detail-section">
                    <h3 class="detail-title"><i class="bi bi-handshake me-2"></i>Sale Information</h3>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="detail-label">Sale Price</div>
                            <div class="detail-value price">₱${Number(sale.sale_price).toLocaleString()}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Sale Date</div>
                            <div class="detail-value">${sale.sale_date_fmt}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Status</div>
                            <div class="detail-value">${statusDisplay}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Submitted Date</div>
                            <div class="detail-value">${sale.submitted_at_fmt}</div>
                        </div>
                        ${sale.reviewed_at ? `
                        <div class="detail-item">
                            <div class="detail-label">Reviewed Date</div>
                            <div class="detail-value">${sale.reviewed_at_fmt}</div>
                        </div>
                        ` : ''}
                        ${sale.admin_notes ? `
                        <div class="detail-item" style="grid-column: 1 / -1;">
                            <div class="detail-label">Admin Notes</div>
                            <div class="detail-value">${sale.admin_notes}</div>
                        </div>
                        ` : ''}
                    </div>
                </div>
                
                <!-- Buyer Information -->
                <div class="detail-section">
                    <h3 class="detail-title"><i class="bi bi-person-fill me-2"></i>Buyer Information</h3>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="detail-label">Buyer Name</div>
                            <div class="detail-value">${sale.buyer_name}</div>
                        </div>
                        ${sale.buyer_contact ? `
                        <div class="detail-item">
                            <div class="detail-label">Buyer Contact</div>
                            <div class="detail-value">${sale.buyer_contact}</div>
                        </div>
                        ` : ''}
                        ${sale.additional_notes ? `
                        <div class="detail-item" style="grid-column: 1 / -1;">
                            <div class="detail-label">Additional Notes</div>
                            <div class="detail-value">${sale.additional_notes}</div>
                        </div>
                        ` : ''}
                    </div>
                </div>
                
                <!-- Agent Information -->
                <div class="detail-section">
                    <h3 class="detail-title"><i class="bi bi-person-badge me-2"></i>Agent Information</h3>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="detail-label">Agent Name</div>
                            <div class="detail-value">${sale.agent_first_name} ${sale.agent_last_name}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Agent Email</div>
                            <div class="detail-value">${sale.agent_email}</div>
                        </div>
                    </div>
                </div>
                
                <!-- Documents -->
                ${sale.document_count > 0 ? `
                <div class="detail-section">
                    <h3 class="detail-title"><i class="bi bi-file-earmark-text me-2"></i>Supporting Documents (${sale.document_count})</h3>
                    <div class="documents-list">
                        ${sale.documents.map(doc => {
                            const fileExt = doc.original_filename.split('.').pop().toLowerCase();
                            const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(fileExt);
                            const isPDF = fileExt === 'pdf';
                            const fileSize = formatFileSize(doc.file_size);
                            const uploadDate = new Date(doc.uploaded_at).toLocaleDateString();
                            
                            return `
                                <div class="document-item">
                                    <div class="document-icon">
                                        ${isImage ? '<i class="bi bi-file-image"></i>' : 
                                          isPDF ? '<i class="bi bi-file-pdf"></i>' : 
                                          '<i class="bi bi-file-earmark"></i>'}
                                    </div>
                                    <div class="document-info">
                                        <div class="document-name">${doc.original_filename}</div>
                                        <div class="document-meta">${fileSize} • Uploaded ${uploadDate}</div>
                                    </div>
                                    <div class="document-actions">
                                        ${isImage || isPDF ? 
                                            `<button class="btn-preview" onclick="previewDocument('${doc.file_path}', '${doc.mime_type}', '${doc.original_filename}', ${doc.id})">
                                                <i class="bi bi-eye"></i> Preview
                                             </button>` : ''}
                                        <button class="btn-download" onclick="downloadDocument(${doc.id}, '${doc.original_filename}')">
                                            <i class="bi bi-download"></i> Download
                                        </button>
                                    </div>
                                </div>
                            `;
                        }).join('')}
                    </div>
                </div>
                ` : ''}
            `;
            
            // Build action buttons
            if (sale.status === 'Pending') {
                modalActions.innerHTML = `
                    <button class="btn-modal btn-success" onclick="approveFromModal(${verificationId})">
                        <i class="bi bi-check-lg me-1"></i> Approve
                    </button>
                    <button class="btn-modal btn-danger" onclick="rejectFromModal(${verificationId})">
                        <i class="bi bi-x-lg me-1"></i> Reject
                    </button>
                `;
            } else {
                modalActions.innerHTML = '';
            }
            
            // Show modal
            openModal();
        }
        
        function openModal() {
            document.getElementById('detailsModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function closeModal() {
            document.getElementById('detailsModal').classList.remove('show');
            document.body.style.overflow = '';
        }
        
        // Close modal when clicking overlay
        document.getElementById('detailsModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.getElementById('detailsModal').classList.contains('show')) {
                closeModal();
            }
        });
        
        function approveFromModal(verificationId) {
            showConfirmationModal(
                'Approve Property Sale',
                'Are you sure you want to approve this property sale verification? This will mark the property as SOLD and notify the agent and buyer via email.',
                () => {
                    // Create form to submit approval
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = window.location.href;
                    
                    const verificationInput = document.createElement('input');
                    verificationInput.type = 'hidden';
                    verificationInput.name = 'verification_id';
                    verificationInput.value = verificationId;
                    
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'approve';
                    
                    form.appendChild(verificationInput);
                    form.appendChild(actionInput);
                    document.body.appendChild(form);
                    showProcessing('Approving sale, please wait…');
                    form.submit();
                }
            );
        }
        
        function rejectFromModal(verificationId) {
            showInputModal(
                'Reject Sale Verification',
                'Please provide a reason for rejecting this sale verification:',
                (reason) => {
                    // Create form to submit rejection
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = window.location.href;
                    
                    const verificationInput = document.createElement('input');
                    verificationInput.type = 'hidden';
                    verificationInput.name = 'verification_id';
                    verificationInput.value = verificationId;
                    
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'reject';
                    
                    const reasonInput = document.createElement('input');
                    reasonInput.type = 'hidden';
                    reasonInput.name = 'reason';
                    reasonInput.value = reason;
                    
                    form.appendChild(verificationInput);
                    form.appendChild(actionInput);
                    form.appendChild(reasonInput);
                    document.body.appendChild(form);
                    showProcessing('Rejecting sale, please wait…');
                    form.submit();
                }
            );
        }
        
        // Show confirmation modal
        function showConfirmationModal(title, message, onConfirm) {
            const modal = document.getElementById('confirmationModal');
            document.getElementById('confirmationModalLabel').innerHTML = `<i class="bi bi-question-circle me-2"></i>${title}`;
            document.getElementById('confirmationModalBody').innerHTML = `<p style="margin: 0; color: #555; font-size: 1rem;">${message}</p>`;
            
            const confirmBtn = document.getElementById('confirmActionBtn');
            
            // Remove old event listeners by cloning
            const newConfirmBtn = confirmBtn.cloneNode(true);
            confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
            
            newConfirmBtn.addEventListener('click', () => {
                closeConfirmationModal();
                onConfirm();
            });
            
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function closeConfirmationModal() {
            document.getElementById('confirmationModal').classList.remove('show');
            document.body.style.overflow = '';
        }
        
        // Show input modal (for rejection reason)
        function showInputModal(title, prompt, onSubmit) {
            const modal = document.getElementById('inputModal');
            const input = document.getElementById('reasonInput');
            const error = document.getElementById('reasonError');
            
            document.getElementById('inputModalLabel').innerHTML = `<i class="bi bi-chat-left-text me-2"></i>${title}`;
            document.getElementById('inputModalPrompt').textContent = prompt;
            input.value = '';
            input.style.borderColor = '#e0e0e0';
            error.style.display = 'none';
            
            const submitBtn = document.getElementById('submitReasonBtn');
            
            // Remove old event listeners by cloning
            const newSubmitBtn = submitBtn.cloneNode(true);
            submitBtn.parentNode.replaceChild(newSubmitBtn, submitBtn);
            
            newSubmitBtn.addEventListener('click', () => {
                const reason = input.value.trim();
                if (!reason) {
                    input.style.borderColor = '#dc3545';
                    error.style.display = 'block';
                    return;
                }
                closeInputModal();
                onSubmit(reason);
            });
            
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function closeInputModal() {
            document.getElementById('inputModal').classList.remove('show');
            document.body.style.overflow = '';
        }
        
        // Close modals when clicking overlay
        document.getElementById('confirmationModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeConfirmationModal();
            }
        });
        
        document.getElementById('inputModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeInputModal();
            }
        });
        
        // Close modals on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (document.getElementById('confirmationModal').classList.contains('show')) {
                    closeConfirmationModal();
                }
                if (document.getElementById('inputModal').classList.contains('show')) {
                    closeInputModal();
                }
            }
        });
        
        // Document preview and download functions
        let currentDocumentId = null;
        let currentDocumentName = '';
        
        function previewDocument(filePath, mimeType, fileName, documentId) {
            // Convert relative path to web-accessible path
            const webPath = filePath.replace('../sale_documents/', 'sale_documents/');
            currentDocumentId = documentId;
            currentDocumentName = fileName;
            
            const previewContent = document.getElementById('previewContent');
            const previewTitle = document.getElementById('previewTitle');
            
            previewTitle.textContent = `Preview: ${fileName}`;
            
            if (mimeType.startsWith('image/')) {
                // Image preview
                previewContent.innerHTML = `
                    <div style="text-align: center;">
                        <img src="${webPath}" alt="${fileName}" style="max-width: 100%; max-height: 70vh; object-fit: contain; border-radius: 4px; box-shadow: var(--shadow-md);">
                    </div>
                `;
            } else if (mimeType === 'application/pdf') {
                // PDF preview using iframe
                previewContent.innerHTML = `
                    <div style="height: 70vh;">
                        <iframe src="${webPath}" width="100%" height="100%" style="border: none; border-radius: 4px;"></iframe>
                    </div>
                `;
            } else {
                // Generic file preview
                previewContent.innerHTML = `
                    <div style="text-align: center; padding: 3rem;">
                        <i class="bi bi-file-earmark" style="font-size: 4rem; color: var(--text-secondary); margin-bottom: 1rem;"></i>
                        <h4 style="color: var(--text-secondary); margin-bottom: 1rem;">Preview not available</h4>
                        <p style="color: var(--text-secondary);">This file type cannot be previewed in the browser.</p>
                        <p style="color: var(--text-secondary); font-size: 0.9rem;">Click "Download" to view the file.</p>
                    </div>
                `;
            }
            
            openPreviewModal();
        }
        
        function downloadDocument(documentId, fileName) {
            // Redirect to PHP download script
            window.location.href = `download_document.php?id=${documentId}`;
        }
        
        function downloadCurrentDocument() {
            if (currentDocumentId && currentDocumentName) {
                downloadDocument(currentDocumentId, currentDocumentName);
            }
        }
        
        function openPreviewModal() {
            document.getElementById('previewModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function closePreviewModal() {
            document.getElementById('previewModal').classList.remove('show');
            document.body.style.overflow = '';
        }
        
        // Close preview modal when clicking overlay
        document.getElementById('previewModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closePreviewModal();
            }
        });
        
        // Close preview modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.getElementById('previewModal').classList.contains('show')) {
                closePreviewModal();
            }
        });
        
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
        
        function approveVerification(verificationId) {
            showConfirmationModal(
                'Approve Property Sale',
                'Are you sure you want to approve this property sale verification? This will mark the property as SOLD and notify the agent and buyer via email.',
                () => {
                    // Create form to submit approval
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = window.location.href;
                    
                    const verificationInput = document.createElement('input');
                    verificationInput.type = 'hidden';
                    verificationInput.name = 'verification_id';
                    verificationInput.value = verificationId;
                    
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'approve';
                    
                    form.appendChild(verificationInput);
                    form.appendChild(actionInput);
                    document.body.appendChild(form);
                    showProcessing('Approving sale, please wait…');
                    form.submit();
                }
            );
        }
        
        function rejectVerification(verificationId) {
            showInputModal(
                'Reject Sale Verification',
                'Please provide a reason for rejecting this sale verification:',
                (reason) => {
                    // Create form to submit rejection
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = window.location.href;
                    
                    const verificationInput = document.createElement('input');
                    verificationInput.type = 'hidden';
                    verificationInput.name = 'verification_id';
                    verificationInput.value = verificationId;
                    
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'reject';
                    
                    const reasonInput = document.createElement('input');
                    reasonInput.type = 'hidden';
                    reasonInput.name = 'reason';
                    reasonInput.value = reason;
                    
                    form.appendChild(verificationInput);
                    form.appendChild(actionInput);
                    form.appendChild(reasonInput);
                    document.body.appendChild(form);
                    showProcessing('Rejecting sale, please wait…');
                    form.submit();
                }
            );
        }
        
        // Property Gallery Navigation Functions
        let currentImageIndex = 0;
        let totalImages = 0;
        
        function initializeGallery() {
            const galleryItems = document.querySelectorAll('.gallery-item');
            const indicators = document.querySelectorAll('.gallery-indicator');
            const prevBtn = document.getElementById('prevBtn');
            const nextBtn = document.getElementById('nextBtn');
            
            totalImages = galleryItems.length;
            currentImageIndex = 0;
            
            if (totalImages <= 1) {
                if (prevBtn) prevBtn.style.display = 'none';
                if (nextBtn) nextBtn.style.display = 'none';
                return;
            }
            
            updateGalleryDisplay();
        }
        
        function updateGalleryDisplay() {
            const galleryItems = document.querySelectorAll('.gallery-item');
            const indicators = document.querySelectorAll('.gallery-indicator');
            const prevBtn = document.getElementById('prevBtn');
            const nextBtn = document.getElementById('nextBtn');
            
            // Update gallery items
            galleryItems.forEach((item, index) => {
                if (index === currentImageIndex) {
                    item.classList.add('active');
                } else {
                    item.classList.remove('active');
                }
            });
            
            // Update indicators
            indicators.forEach((indicator, index) => {
                if (index === currentImageIndex) {
                    indicator.classList.add('active');
                } else {
                    indicator.classList.remove('active');
                }
            });
            
            // Update navigation buttons
            if (prevBtn) {
                prevBtn.disabled = currentImageIndex === 0;
            }
            if (nextBtn) {
                nextBtn.disabled = currentImageIndex === totalImages - 1;
            }
        }
        
        function nextImage() {
            if (currentImageIndex < totalImages - 1) {
                currentImageIndex++;
                updateGalleryDisplay();
            }
        }
        
        function previousImage() {
            if (currentImageIndex > 0) {
                currentImageIndex--;
                updateGalleryDisplay();
            }
        }
        
        function goToImage(index) {
            if (index >= 0 && index < totalImages) {
                currentImageIndex = index;
                updateGalleryDisplay();
            }
        }
        
        // Initialize gallery when modal opens
        function openModal() {
            document.getElementById('detailsModal').classList.add('show');
            document.body.style.overflow = 'hidden';
            
            // Initialize gallery after modal is shown
            setTimeout(() => {
                initializeGallery();
            }, 100);
        }

        // Open Bootstrap Finalize Modal and prefill
        function openFinalizeModal(payload) {
            try {
                document.getElementById('finalize_property_id').value = payload.property_id || '';
                document.getElementById('finalize_agent_id').value = payload.agent_id || '';
                document.getElementById('final_sale_price').value = payload.final_sale_price || '';
                document.getElementById('buyer_name').value = payload.buyer_name || '';
                document.getElementById('buyer_email').value = payload.buyer_email || '';
                document.getElementById('buyer_contact').value = payload.buyer_contact || '';
                document.getElementById('commission_percentage').value = '';
                document.getElementById('notes').value = '';
                const help = document.getElementById('finalizeHelp');
                help.textContent = `Property #${payload.property_id} • Agent #${payload.agent_id}`;
                if (finalizeModalInstance) finalizeModalInstance.show();
            } catch (e) {
                console.error(e);
            }
        }

        // Add Finalize button into Details modal actions when Approved
        const actionsObserver = new MutationObserver(() => {
            try {
                const actionsEl = document.getElementById('modalActions');
                const sale = window.currentViewedSale || null;
                if (!actionsEl || !sale) return;
                const exists = actionsEl.querySelector('[data-finalize-btn="1"]');
                if (exists) return;
                if ((sale.status || '').toLowerCase() === 'approved') {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'btn-modal btn-primary';
                    btn.setAttribute('data-finalize-btn', '1');
                    btn.innerHTML = '<i class="bi bi-cash-coin me-1"></i>Finalize Sale & Commission';
                    btn.addEventListener('click', () => {
                        openFinalizeModal({
                            property_id: sale.property_id,
                            agent_id: sale.agent_id,
                            final_sale_price: sale.sale_price || '',
                            buyer_name: sale.buyer_name || '',
                            buyer_email: sale.buyer_email || '',
                            buyer_contact: sale.buyer_contact || ''
                        });
                    });
                    actionsEl.appendChild(btn);
                }
            } catch (e) { /* noop */ }
        });

        document.addEventListener('DOMContentLoaded', function() {
            const actionsEl = document.getElementById('modalActions');
            if (actionsEl) actionsObserver.observe(actionsEl, { childList: true, subtree: false });
        });

        // Handle finalize submit
        const finalizeForm = document.getElementById('finalizeSaleForm');
        if (finalizeForm) {
            finalizeForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                const fd = new FormData(finalizeForm);
                const price = parseFloat(fd.get('final_sale_price'));
                const pct = parseFloat(fd.get('commission_percentage'));
                if (!price || price <= 0) {
                    alert('Please enter a valid final sale price.');
                    return;
                }
                if (isNaN(pct) || pct < 0 || pct > 100) {
                    alert('Commission percentage must be between 0 and 100.');
                    return;
                }
                showProcessing('Finalizing sale and computing commission…');
                try {
                    const res = await fetch('admin_finalize_sale.php', { method: 'POST', body: fd });
                    const data = await res.json();
                    hideProcessing();
                    if (data && data.ok) {
                        if (finalizeModalInstance) finalizeModalInstance.hide();
                        alert(`Success! Commission computed: ₱${Number(data.commission_amount).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}`);
                        window.location.href = window.location.pathname + '?success=finalized';
                    } else {
                        alert(data && data.message ? data.message : 'Failed to finalize sale.');
                    }
                } catch (err) {
                    hideProcessing();
                    alert('Unexpected error while finalizing sale.');
                    console.error(err);
                }
            });
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
