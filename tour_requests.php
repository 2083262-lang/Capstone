<?php
session_start();
require_once 'connection.php';

// Admin-only
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$error_message = '';
$tour_requests = [];

// ===== AUTO-EXPIRE PENDING TOURS FOR ADMIN PROPERTIES =====
// Use Philippine Time (Asia/Manila, UTC+8)
date_default_timezone_set('Asia/Manila');
$now_ph = date('Y-m-d H:i:s');

// Find Pending requests for admin-listed properties where tour date/time has passed
$expire_find_sql = "
    SELECT tr.tour_id, tr.user_name, tr.user_email, tr.tour_date, tr.tour_time,
           p.StreetAddress, p.City, p.Province
    FROM tour_requests tr
    JOIN property p ON tr.property_id = p.property_ID
    JOIN property_log pl ON pl.property_id = p.property_ID AND pl.action = 'CREATED'
    JOIN accounts u ON u.account_id = pl.account_id
    JOIN user_roles ur ON ur.role_id = u.role_id
    WHERE ur.role_name = 'admin'
      AND tr.request_status = 'Pending'
      AND CONCAT(tr.tour_date, ' ', tr.tour_time) < ?";
$stmt = $conn->prepare($expire_find_sql);
$stmt->bind_param('s', $now_ph);
$stmt->execute();
$expired_tours = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!empty($expired_tours)) {
    require_once __DIR__ . '/mail_helper.php';
    
    $expire_ids = array_column($expired_tours, 'tour_id');
    $placeholders = implode(',', array_fill(0, count($expire_ids), '?'));
    $types = str_repeat('i', count($expire_ids));
    
    $expire_sql = "UPDATE tour_requests 
                   SET request_status = 'Expired', 
                       expired_at = ?,
                       decision_reason = 'This tour request expired automatically because the scheduled date/time passed without a response.',
                       decision_at = ?
                   WHERE tour_id IN ($placeholders)";
    $stmt = $conn->prepare($expire_sql);
    $params = array_merge([$now_ph, $now_ph], $expire_ids);
    $stmt->bind_param('ss' . $types, ...$params);
    $stmt->execute();
    $stmt->close();
    
    foreach ($expired_tours as $exp) {
        $property_address = $exp['StreetAddress'] . ', ' . $exp['City'] . ', ' . $exp['Province'];
        $formattedDate = date('F j, Y', strtotime($exp['tour_date']));
        $formattedTime = date('g:i A', strtotime($exp['tour_time']));
        
        try {
            $subject = 'Tour Request Expired - ' . $property_address;
            $body = '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Tour Request Expired</title></head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,\'Helvetica Neue\',Arial,sans-serif;background-color:#0a0a0a;line-height:1.6;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#0a0a0a;padding:60px 20px;">
        <tr><td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color:#111111;border:1px solid #1f1f1f;border-radius:4px;max-width:600px;">
                    <tr><td style="background:linear-gradient(90deg,#f59e0b 0%,#d97706 50%,#f59e0b 100%);height:3px;"></td></tr>
                    <tr><td style="padding:48px 48px 32px 48px;text-align:center;border-bottom:1px solid #1f1f1f;">
                            <div style="width:56px;height:56px;border-radius:50%;background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.2);display:inline-flex;align-items:center;justify-content:center;margin-bottom:20px;"><span style="font-size:24px;">⏰</span></div>
                            <h1 style="margin:0 0 12px 0;color:#f59e0b;font-size:14px;font-weight:600;text-transform:uppercase;letter-spacing:3px;">Tour Request Expired</h1>
                            <p style="margin:0;color:#666666;font-size:15px;">Your scheduled tour date has passed</p>
                    </td></tr>
                    <tr><td style="padding:48px 48px 40px 48px;">
                            <p style="margin:0 0 24px 0;color:#999999;font-size:15px;">Hi <strong style="color:#ffffff;">' . htmlspecialchars($exp['user_name']) . '</strong>,</p>
                            <p style="margin:0 0 32px 0;color:#999999;font-size:14px;line-height:1.8;">We\'re sorry — your tour request was not confirmed before the scheduled date. It has been automatically marked as <strong style="color:#f59e0b;">expired</strong>.</p>
                            <table width="100%" cellpadding="0" cellspacing="0" style="background:rgba(245,158,11,0.04);border:1px solid rgba(245,158,11,0.15);border-radius:4px;margin-bottom:32px;">
                                <tr><td style="padding:28px 24px;">
                                    <span style="color:#666666;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:1px;">Property</span><br>
                                    <span style="color:#ffffff;font-size:14px;font-weight:500;line-height:1.8;">' . htmlspecialchars($property_address) . '</span><br><br>
                                    <span style="color:#666666;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:1px;">Date</span>: <span style="color:#ffffff;font-size:14px;">' . $formattedDate . '</span> &nbsp;&bull;&nbsp;
                                    <span style="color:#666666;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:1px;">Time</span>: <span style="color:#ffffff;font-size:14px;">' . $formattedTime . '</span>
                                </td></tr>
                            </table>
                            <table width="100%" cellpadding="0" cellspacing="0" style="background:rgba(37,99,235,0.04);border:1px solid rgba(37,99,235,0.15);border-radius:4px;">
                                <tr><td style="padding:24px;">
                                    <p style="margin:0 0 8px 0;color:#3b82f6;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px;">💡 What You Can Do</p>
                                    <ul style="margin:0;padding:0 0 0 20px;color:#999999;font-size:13px;line-height:2;">
                                        <li>Submit a <strong style="color:#ffffff;">new tour request</strong> with a future date</li>
                                        <li>Try selecting a <strong style="color:#ffffff;">different time slot</strong></li>
                                    </ul>
                                </td></tr>
                            </table>
                    </td></tr>
                    <tr><td style="padding:24px 48px;background:#0d0d0d;border-top:1px solid #1f1f1f;text-align:center;">
                            <p style="margin:0;color:#444444;font-size:12px;">HomeEstate Realty &bull; Automated Notification</p>
                    </td></tr>
                </table>
        </td></tr>
    </table>
</body></html>';
            sendSystemMail($exp['user_email'], $exp['user_name'], $subject, $body);
        } catch (Exception $e) {
            error_log("Failed to send expiry email for tour #{$exp['tour_id']}: " . $e->getMessage());
        }
    }
}

$status_counts = [
    'All' => 0,
    'Pending' => 0,
    'Confirmed' => 0,
    'Completed' => 0,
    'Cancelled' => 0,
    'Rejected' => 0,
    'Expired' => 0,
];

// Fetch tour requests with property images
$sql = "
    SELECT 
        tr.*, 
        p.StreetAddress, p.City, p.property_ID, p.PropertyType, p.Bedrooms, p.Bathrooms,
        a.first_name AS agent_first_name, a.last_name AS agent_last_name,
        u.first_name AS poster_first_name, u.last_name AS poster_last_name,
        ur.role_name AS poster_role,
        (SELECT pi.PhotoURL FROM property_images pi 
         WHERE pi.property_ID = p.property_ID 
         ORDER BY pi.SortOrder ASC LIMIT 1) as property_image
    FROM tour_requests tr
    LEFT JOIN property p ON p.property_ID = tr.property_id
    LEFT JOIN accounts a ON a.account_id = tr.agent_account_id
    LEFT JOIN property_log pl ON pl.property_id = p.property_ID AND pl.action = 'CREATED'
    LEFT JOIN accounts u ON u.account_id = pl.account_id
    LEFT JOIN user_roles ur ON ur.role_id = u.role_id
    WHERE ur.role_name = 'admin'
    ORDER BY 
        CASE tr.request_status
            WHEN 'Pending' THEN 1
            WHEN 'Confirmed' THEN 2
            WHEN 'Completed' THEN 3
            WHEN 'Cancelled' THEN 4
            WHEN 'Rejected' THEN 5
            WHEN 'Expired' THEN 6
            ELSE 7
        END,
        tr.requested_at DESC
";

$stmt = $conn->prepare($sql);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $status = $row['request_status'] ?: 'Pending';
    if (!isset($status_counts[$status])) $status_counts[$status] = 0;
    $status_counts[$status]++;
    $status_counts['All']++;

    $row['tour_date_fmt'] = $row['tour_date'] ? date('M j, Y', strtotime($row['tour_date'])) : '';
    $row['tour_time_fmt'] = $row['tour_time'] ? date('g:i A', strtotime($row['tour_time'])) : '';
    $row['requested_at_fmt'] = $row['requested_at'] ? date('M j, Y g:i A', strtotime($row['requested_at'])) : '';
    $tour_requests[] = $row;
}
$stmt->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tour Requests Management - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { 
            font-family: 'Inter', sans-serif;
            background-color: #f8f9fa;
            color: #212529;
        }
        
        /* Use standardized admin-content from dashboard */
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
            border: 1px solid #e0e0e0;
            border-left: 4px solid #bc9e42;
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
            border-left-color: #bc9e42;
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
            color: #161209;
            line-height: 1.2;
        }
        
        /* Tour Request Cards - Redesigned */
        .tour-card {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.2s ease;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        
        .tour-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .tour-card-image {
            width: 100%;
            height: 180px;
            object-fit: cover;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        }
        
        .tour-card-body {
            padding: 1.5rem;
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        
        .tour-card-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 1rem;
            gap: 1rem;
        }
        
        .tour-id {
            font-size: 0.7rem;
            font-weight: 700;
            color: #6c757d;
            background: #f8f9fa;
            padding: 0.35rem 0.65rem;
            border-radius: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .property-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #161209;
            margin: 0 0 0.5rem 0;
            line-height: 1.3;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .property-title a {
            color: inherit;
            text-decoration: none;
            transition: color 0.2s ease;
        }
        
        .property-title a:hover {
            color: #bc9e42;
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
            color: #bc9e42;
        }
        
        /* Client Info - Compact */
        .client-compact {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .client-name {
            font-weight: 600;
            color: #161209;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .client-name i {
            color: #bc9e42;
        }

        .client-contact {
            font-size: 0.8rem;
            color: #6c757d;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .client-contact i {
            width: 14px;
            color: #bc9e42;
        }
        
        /* Tour Schedule - Compact */
        .tour-schedule {
            background: #fffbf0;
            padding: 0.875rem;
            border-radius: 8px;
            border: 1px solid #f5ecd0;
            margin-bottom: 1rem;
        }
        
        .tour-datetime {
            display: flex;
            align-items: center;
            gap: 1rem;
            font-size: 0.875rem;
            color: #161209;
            font-weight: 500;
        }
        
        .tour-datetime i {
            color: #bc9e42;
        }
        
        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 0.35rem 0.75rem;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-pending { 
            background: #fff3cd; 
            color: #856404;
        }
        
        .status-confirmed { 
            background: #cfe2ff; 
            color: #084298;
        }
        
        .status-completed { 
            background: #d1e7dd; 
            color: #0f5132;
        }
        
        .status-cancelled { 
            background: #e2e3e5; 
            color: #41464b;
        }
        
        .status-rejected { 
            background: #f8d7da; 
            color: #842029;
        }
        
        .status-expired { 
            background: #e9ecef; 
            color: #6c757d;
        }
        
        /* Tour Type Badge */
        .type-badge {
            display: inline-block;
            padding: 0.3rem 0.6rem;
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.3px;
            line-height: 1;
            vertical-align: middle;
        }
        .type-public {
            background: #e8f5e9;
            color: #1b5e20;
            border: 1px solid #66bb6a;
        }
        .type-private {
            background: #f3e5f5;
            color: #4a148c;
            border: 1px solid #ba68c8;
        }
        
        /* Card Footer */
        .tour-card-footer {
            margin-top: auto;
            padding-top: 1rem;
            border-top: 1px solid #e9ecef;
        }
        
        .btn-view-details {
            width: 100%;
            padding: 0.625rem;
            font-size: 0.875rem;
            font-weight: 600;
            border-radius: 8px;
            border: none;
            background: #161209;
            color: #ffffff;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .btn-view-details:hover {
            background: #bc9e42;
            color: #161209;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(188, 158, 66, 0.3);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: #fff;
            border: 1px solid #e0e0e0;
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
            color: #161209;
            margin-bottom: 0.5rem;
        }
        
        .empty-state p {
            color: #6c757d;
            margin: 0;
        }
        
        /* Search & Filter Bar - Professional & Minimalist */
        .search-filter-bar {
            display: flex;
            gap: 1rem;
            align-items: center;
            background: #fff;
            padding: 1.25rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border: 1px solid #e8e8e8;
        }
        
        .search-wrapper {
            flex: 1;
            position: relative;
        }
        
        .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            font-size: 1.1rem;
            pointer-events: none;
        }
        
        .search-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.75rem;
            border: 2px solid #e8e8e8;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.2s ease;
            background: #fafbfc;
        }
        
        .search-input:focus {
            outline: none;
            border-color: var(--secondary-color, #bc9e42);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(188, 158, 66, 0.1);
        }
        
        .search-input::placeholder {
            color: #adb5bd;
        }
        
        .filter-controls {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }
        
        .status-select {
            min-width: 160px;
            padding: 0.75rem 1rem;
            border: 2px solid #e8e8e8;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 500;
            background: #fafbfc;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .status-select:focus {
            outline: none;
            border-color: var(--secondary-color, #bc9e42);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(188, 158, 66, 0.1);
        }
        
        .calendar-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.25rem;
            background: linear-gradient(135deg, #161209 0%, #2a2318 100%);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        
        .calendar-btn:hover {
            background: linear-gradient(135deg, #2a2318 0%, #161209 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(22, 18, 9, 0.3);
        }
        
        .calendar-btn i {
            font-size: 1.1rem;
        }
        
        @media (max-width: 992px) {
            .search-filter-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-controls {
                width: 100%;
            }
            
            .status-select {
                flex: 1;
            }
            
            .calendar-btn {
                justify-content: center;
            }
        }
        
        /* Enhanced Modal Styles - Professional & Minimalist */
        .modal-content {
            border-radius: 12px;
            border: 1px solid #e0e0e0;
            box-shadow: 0 8px 32px rgba(0,0,0,0.12);
        }
        
        .modal-header {
            background: #fff;
            color: #1a1a1a;
            border-radius: 12px 12px 0 0;
            padding: 1.75rem 2rem;
            border-bottom: 2px solid #f5f5f5;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .modal-header .btn-close {
            opacity: 0.5;
            transition: opacity 0.2s ease;
        }

        .modal-header .btn-close:hover {
            opacity: 1;
        }
        
        .modal-title {
            font-size: 1.35rem;
            font-weight: 700;
            color: #1a1a1a;
            display: flex;
            align-items: center;
            gap: 0.625rem;
            letter-spacing: -0.02em;
        }
        
        .modal-title i {
            font-size: 1.4rem;
            color: #161209;
        }
        
        .modal-body {
            padding: 2rem;
            background: #fafbfc;
        }

        .modal-section {
            margin-bottom: 1.75rem;
            background: #fff;
            padding: 1.5rem;
            border-radius: 10px;
            border: 1px solid #e8e8e8;
        }

        .modal-section:last-child {
            margin-bottom: 0;
        }

        .modal-section-title {
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #161209;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .modal-section-title i {
            color: #bc9e42;
        }

        .modal-info-row {
            display: grid;
            grid-template-columns: 140px 1fr;
            gap: 1rem;
            padding: 0.875rem 0;
            border-bottom: 1px solid #f5f5f5;
        }
        
        .modal-info-row:last-child {
            border-bottom: none;
        }

        .modal-info-label {
            font-weight: 600;
            color: #6c757d;
            font-size: 0.875rem;
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            padding-top: 2px;
        }

        .modal-info-label i {
            color: #bc9e42;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .modal-info-value {
            color: #2d3748;
            font-size: 0.95rem;
            font-weight: 500;
            line-height: 1.5;
        }

        .modal-property-image {
            width: 100%;
            height: 280px;
            object-fit: cover;
            border-radius: 10px;
            margin-bottom: 1.75rem;
            border: 1px solid #e8e8e8;
        }

        .modal-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.875rem;
            border: 1px solid currentColor;
        }
        
        .modal-footer {
            padding: 1.5rem 2rem;
            background: #fff;
            border-top: 2px solid #f5f5f5;
            border-radius: 0 0 12px 12px;
            gap: 0.75rem;
            display: flex;
            justify-content: flex-end;
        }

        .modal-action-btn {
            padding: 0.75rem 1.75rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.95rem;
            border: 2px solid transparent;
            transition: all 0.2s ease;
        }
        
        .modal-action-btn.btn-success {
            background: #28a745;
            border-color: #28a745;
        }
        
        .modal-action-btn.btn-success:hover {
            background: #218838;
            border-color: #218838;
            box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
        }
        
        .modal-action-btn.btn-danger {
            background: #dc3545;
            border-color: #dc3545;
        }
        
        .modal-action-btn.btn-danger:hover {
            background: #c82333;
            border-color: #c82333;
            box-shadow: 0 2px 8px rgba(220, 53, 69, 0.3);
        }
        
        .modal-action-btn.btn-warning {
            background: #ffc107;
            border-color: #ffc107;
            color: #1a1a1a;
        }
        
        .modal-action-btn.btn-warning:hover {
            background: #e0a800;
            border-color: #e0a800;
            box-shadow: 0 2px 8px rgba(255, 193, 7, 0.3);
        }
        
        .modal-action-btn.btn-primary {
            background: #007bff;
            border-color: #007bff;
        }
        
        .modal-action-btn.btn-primary:hover {
            background: #0056b3;
            border-color: #0056b3;
            box-shadow: 0 2px 8px rgba(0, 123, 255, 0.3);
        }
        
        .modal-action-btn.btn-secondary {
            background: #fff;
            border-color: #d0d0d0;
            color: #495057;
        }

        .modal-action-btn.btn-secondary:hover {
            background: #f8f9fa;
            border-color: #adb5bd;
        }
        
        .modal-backdrop.show {
            opacity: 0.6;
        }
        
        /* Calendar Sidebar Styles */
        .calendar-sidebar {
            position: fixed;
            top: 0;
            right: 0;
            width: 100%;
            height: 100%;
            z-index: 9999;
            pointer-events: none;
        }
        
        .calendar-sidebar.active {
            pointer-events: all;
        }
        
        .calendar-sidebar-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
        }
        
        .calendar-sidebar.active .calendar-sidebar-overlay {
            opacity: 1;
            pointer-events: all;
        }
        
        .calendar-sidebar-content {
            position: absolute;
            top: 0;
            right: 0;
            width: 450px;
            max-width: 90vw;
            height: 100%;
            background: linear-gradient(135deg, #ffffff 0%, #fafafa 100%);
            box-shadow: -4px 0 30px rgba(0, 0, 0, 0.2);
            transform: translateX(100%);
            transition: transform 0.3s ease;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .calendar-sidebar.active .calendar-sidebar-content {
            transform: translateX(0);
        }
        
        .calendar-header {
            background: linear-gradient(135deg, #bc9e42 0%, #f7e9b0 100%);
            color: #3a2c06;
            padding: 1.5rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 3px solid #bc9e42;
            box-shadow: 0 4px 10px rgba(188, 158, 66, 0.18);
        }
        
        .calendar-header h4 {
            font-weight: 700;
            font-size: 1.25rem;
            display: flex;
            align-items: center;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .debug-toggle-container {
            padding: 1rem 2rem;
            background: linear-gradient(to right, #fff3cd 0%, #fffbf0 100%);
            border-bottom: 2px solid #ffc107;
        }
        
        .debug-toggle-container .form-check-label {
            color: #856404;
            font-weight: 500;
            cursor: pointer;
            font-size: 0.875rem;
        }
        
        .debug-toggle-container .form-check-input {
            cursor: pointer;
            width: 2.5rem;
            height: 1.25rem;
        }
        
        .debug-toggle-container .form-check-input:checked {
            background-color: #ffc107;
            border-color: #ffc107;
        }
        
        .btn-close-sidebar {
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: black;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            backdrop-filter: blur(10px);
            background: transparent;
        }
        
        .btn-close-sidebar:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: scale(1.1) rotate(90deg);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        
        .calendar-body {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem;
        }
        
        .calendar-widget {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .calendar-controls {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }
        
        .calendar-month-year {
            font-weight: 700;
            color: #161209;
            font-size: 1.1rem;
        }
        
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 0.25rem;
            margin-bottom: 1rem;
        }
        
        .calendar-day-header {
            text-align: center;
            font-weight: 700;
            font-size: 0.75rem;
            color: #6c757d;
            padding: 0.5rem 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .calendar-day {
            aspect-ratio: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.12s ease, border-color 0.12s ease;
            position: relative;
            background: white;
            border: 1px solid transparent;
        }
        
        .calendar-day:hover:not(.disabled) {
            background: rgba(188, 158, 66, 0.06);
            border-color: rgba(188, 158, 66, 0.3);
        }
        
        .calendar-day.disabled {
            color: #ccc;
            cursor: not-allowed;
            opacity: 0.5;
        }
        
        .calendar-day.today {
            background: rgba(188, 158, 66, 0.2);
            border: 2px solid #bc9e42;
            font-weight: 800;
        }
        
        .calendar-day.selected {
            background: linear-gradient(135deg, #bc9e42, #d4b555);
            color: #161209;
            font-weight: 800;
        }
        
        .calendar-day.has-tours::after {
            content: '';
            position: absolute;
            bottom: 6px;
            left: 50%;
            transform: translateX(-50%);
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #bc9e42;
        }
        
        .calendar-day.has-pending::after {
            background: #ffc107;
        }
        
        .calendar-day.has-confirmed::after {
            background: #28a745;
        }
        
        .calendar-day.has-cancelled::after {
            background: #6c757d;
        }
        
        .calendar-day.has-rejected::after {
            background: #dc3545;
        }
        
        .calendar-day.has-completed::after {
            background: #17a2b8;
        }
        
        .calendar-day.has-expired::after {
            background: #9ca3af;
        }
        
        .calendar-legend {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            padding-top: 1rem;
            border-top: 2px solid #e9ecef;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
            color: #495057;
            font-weight: 600;
            padding: 0.25rem 0.75rem;
            background: rgba(188, 158, 66, 0.05);
            border-radius: 20px;
            transition: all 0.2s ease;
        }
        
        .legend-item:hover {
            background: rgba(188, 158, 66, 0.1);
            transform: translateY(-1px);
        }
        
        .legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            box-shadow: 0 0 4px rgba(0,0,0,0.2);
        }
        
        .scheduled-tours-section {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .section-header {
            padding: 1rem 1.5rem;
            background: linear-gradient(135deg, rgba(188, 158, 66, 0.1), rgba(188, 158, 66, 0.05));
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .section-header h6 {
            font-weight: 700;
            color: #161209;
        }
        
        .scheduled-tours-list {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .tour-item {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid rgba(230, 230, 230, 0.5);
            border-left: 3px solid transparent;
            transition: background-color 0.12s ease, border-left-color 0.12s ease;
            cursor: pointer;
            position: relative;
        }
        
        .tour-item:last-child {
            border-bottom: none;
        }
        
        .tour-item:hover {
            background: rgba(188, 158, 66, 0.02);
            border-left-color: #bc9e42;
        }
        
        .tour-item.status-pending {
            border-left-color: #ffc107;
        }
        
        .tour-item.status-pending:hover {
            border-left-color: #ffc107;
        }
        
        .tour-item.status-confirmed {
            border-left-color: #28a745;
        }
        
        .tour-item.status-confirmed:hover {
            border-left-color: #28a745;
        }
        
        .tour-item.status-cancelled {
            border-left-color: #6c757d;
            opacity: 0.7;
        }
        
        .tour-item.status-rejected {
            border-left-color: #dc3545;
            opacity: 0.7;
        }
        
        .tour-item.status-completed {
            border-left-color: #17a2b8;
            opacity: 0.8;
        }
        
        .tour-item.status-expired {
            border-left-color: #9ca3af;
            opacity: 0.5;
        }
        
        .tour-item-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }
        
        .tour-client-name {
            font-weight: 700;
            color: #161209;
            font-size: 0.95rem;
        }
        
        .tour-time {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            color: #6c757d;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .tour-property {
            color: #6c757d;
            font-size: 0.8rem;
            margin-bottom: 0.3rem;
        }
        
        .tour-conflict-warning {
            background: linear-gradient(to right, rgba(255, 193, 7, 0.15) 0%, rgba(255, 193, 7, 0.05) 100%);
            border: 1px solid rgba(255, 193, 7, 0.4);
            border-left: 4px solid #ffc107;
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(255, 193, 7, 0.2);
            padding: 0.6rem 0.85rem;
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            font-size: 0.8rem;
            color: #856404;
            font-weight: 600;
        }
        
        .tour-conflict-warning i {
            color: #ffc107;
            font-size: 1rem;
        }
        
        .tour-item-empty {
            padding: 3rem 1.5rem;
            text-align: center;
            color: #6c757d;
        }
        
        @media (prefers-reduced-motion: reduce) {
            * {
                transition: none !important;
            }
        }
    </style>
</head>
<body>
<?php
    $active_page = 'tour_requests.php';
    include 'admin_sidebar.php';
    include 'admin_navbar.php';
?>
<div class="admin-content">
    <div class="container-fluid">
        <!-- Search & Filter Controls -->
        <div class="search-filter-bar mb-4">
            <div class="search-wrapper">
                <i class="bi bi-search search-icon"></i>
                <input id="tourSearch" type="text" class="search-input" placeholder="Search by address, city, or client name" aria-label="Search tours">
            </div>
            <div class="filter-controls">
                <select id="tourStatusFilter" class="status-select">
                    <option value="All">All statuses</option>
                    <option value="Pending">Pending</option>
                    <option value="Confirmed">Confirmed</option>
                    <option value="Completed">Completed</option>
                    <option value="Cancelled">Cancelled</option>
                    <option value="Rejected">Rejected</option>
                    <option value="Expired">Expired</option>
                </select>
                <button class="calendar-btn" id="openCalendarBtn">
                    <i class="bi bi-calendar3"></i>
                    <span>Calendar</span>
                </button>
            </div>
        </div>

        <!-- Tour Requests Grid -->
        <?php if (empty($tour_requests)): ?>
            <div class="empty-state">
                <i class="bi bi-inbox"></i>
                <h3>No Tour Requests Found</h3>
                <p>There are currently no tour requests in the system.</p>
            </div>
        <?php else: ?>
                <div class="row g-4" id="tourRequestsGrid">
                <?php foreach ($tour_requests as $r): ?>
                    <div class="col-xl-4 col-lg-4 col-md-6 col-sm-12" data-status="<?php echo htmlspecialchars($r['request_status']); ?>" 
                         id="card-<?php echo (int)$r['tour_id']; ?>">
                        <div class="tour-card">
                            <!-- Property Image -->
                            <img src="<?php echo !empty($r['property_image']) ? htmlspecialchars($r['property_image']) : 'uploads/default-property.jpg'; ?>" 
                                 alt="Property" 
                                 class="tour-card-image"
                                 onerror="this.src='uploads/default-property.jpg'">
                            
                            <div class="tour-card-body">
                                <!-- Header with ID and Status -->
                                <div class="tour-card-header">
                                    <span class="tour-id">TR-<?php echo (int)$r['tour_id']; ?></span>
                                    <?php 
                                        $status = $r['request_status'] ?: 'Pending';
                                        $status_class = 'status-pending';
                                        switch ($status) {
                                            case 'Confirmed': $status_class = 'status-confirmed'; break;
                                            case 'Completed': $status_class = 'status-completed'; break;
                                            case 'Cancelled': $status_class = 'status-cancelled'; break;
                                            case 'Rejected': $status_class = 'status-rejected'; break;
                                            case 'Expired': $status_class = 'status-expired'; break;
                                        }
                                    ?>
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <?php echo htmlspecialchars($status); ?>
                                    </span>
                                </div>
                                
                                <!-- Tour Type Badge -->
                                <?php if (!empty($r['tour_type'])): 
                                    $tour_type = strtolower(trim($r['tour_type']));
                                    $type_class = $tour_type === 'public' ? 'type-public' : 'type-private';
                                    $type_label = $tour_type === 'public' ? 'Public' : 'Private';
                                ?>
                                <div class="mb-2">
                                    <span class="type-badge <?php echo $type_class; ?>">
                                        <i class="bi bi-<?php echo $tour_type === 'public' ? 'people' : 'person'; ?> me-1"></i>
                                        <?php echo $type_label; ?> Tour
                                    </span>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Property Info -->
                                <h3 class="property-title">
                                    <a href="view_property.php?property_id=<?php echo (int)$r['property_ID']; ?>" target="_blank">
                                        <?php echo htmlspecialchars($r['StreetAddress']); ?>
                                    </a>
                                </h3>
                                <div class="property-location">
                                    <i class="bi bi-geo-alt-fill"></i>
                                    <span><?php echo htmlspecialchars($r['City']); ?></span>
                                </div>
                                
                                <!-- Client Info - Compact -->
                                <div class="client-compact">
                                    <div class="client-name">
                                        <i class="bi bi-person-fill"></i>
                                        <?php echo htmlspecialchars($r['user_name']); ?>
                                    </div>
                                    <div class="client-contact">
                                        <i class="bi bi-envelope-fill"></i>
                                        <span><?php echo htmlspecialchars($r['user_email']); ?></span>
                                    </div>
                                </div>
                                
                                <!-- Tour Schedule -->
                                <div class="tour-schedule">
                                    <div class="tour-datetime">
                                        <span>
                                            <i class="bi bi-calendar-event"></i>
                                            <?php echo htmlspecialchars($r['tour_date_fmt']); ?>
                                        </span>
                                        <span>
                                            <i class="bi bi-clock"></i>
                                            <?php echo htmlspecialchars($r['tour_time_fmt']); ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <!-- Card Footer -->
                                <div class="tour-card-footer">
                                    <button class="btn-view-details" onclick="openDetails(<?php echo (int)$r['tour_id']; ?>)">
                                        <i class="bi bi-eye me-2"></i>View Full Details
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Enhanced Tour Details Modal -->
<div class="modal fade" id="tourDetailsModal" tabindex="-1" aria-labelledby="tourDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="tourDetailsModalLabel">
                    <i class="bi bi-calendar-check"></i>
                    Tour Request Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="modalBody">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-3 text-muted">Loading tour request details...</p>
                </div>
            </div>
            <div class="modal-footer" id="modalFooter">
                <button type="button" class="btn btn-secondary modal-action-btn" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg me-2"></i>Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Confirmation Modal -->
<div class="modal fade" id="confirmationModal" tabindex="-1" aria-labelledby="confirmationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmationModalLabel">
                    <i class="bi bi-question-circle me-2"></i>Confirm Action
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="confirmationModalBody">
                <!-- Dynamic content -->
            </div>
            <div class="modal-footer" id="confirmationModalFooter">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg me-2"></i>Cancel
                </button>
                <button type="button" class="btn btn-primary" id="confirmActionBtn">
                    <i class="bi bi-check-lg me-2"></i>Confirm
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Input Modal (for reasons) -->
<div class="modal fade" id="inputModal" tabindex="-1" aria-labelledby="inputModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="inputModalLabel">
                    <i class="bi bi-chat-left-text me-2"></i>Provide Reason
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label for="reasonInput" class="form-label fw-bold" id="inputModalPrompt">Please provide a reason:</label>
                <textarea class="form-control" id="reasonInput" rows="4" placeholder="Enter your reason here..."></textarea>
                <div class="invalid-feedback" id="reasonError">
                    This field is required.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg me-2"></i>Cancel
                </button>
                <button type="button" class="btn btn-primary" id="submitReasonBtn">
                    <i class="bi bi-check-lg me-2"></i>Submit
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Note: legacy script/tour_requests.js relied on old modal markup; keeping logic inline here to avoid conflicts. -->
<script>
// Filter function
function setFilter(status) {
    // Redirected: use new search + status filter
    document.getElementById('tourStatusFilter').value = status;
    applyFilters();
}

// Apply search and status filters to the cards
function applyFilters() {
    const q = (document.getElementById('tourSearch').value || '').toLowerCase().trim();
    const status = (document.getElementById('tourStatusFilter').value || 'All');
    const cards = document.querySelectorAll('#tourRequestsGrid [data-status]');
    let visibleCount = 0;

    cards.forEach(card => {
        const cardStatus = (card.getAttribute('data-status') || '').toLowerCase();
        const titleEl = card.querySelector('.property-title') || { innerText: '' };
        const clientEl = card.querySelector('.client-name') || { innerText: '' };
        const combined = (titleEl.innerText + ' ' + clientEl.innerText + ' ' + card.getAttribute('data-status')).toLowerCase();

        const statusMatch = (status === 'All') || (cardStatus === status.toLowerCase());
        const textMatch = q === '' || combined.indexOf(q) !== -1;

        if (statusMatch && textMatch) {
            card.style.display = '';
            visibleCount++;
        } else {
            card.style.display = 'none';
        }
    });

    // Show empty state if no results
    const grid = document.getElementById('tourRequestsGrid');
    let existing = document.getElementById('filterEmptyState');
    if (visibleCount === 0) {
        if (!existing) {
            const emptyState = document.createElement('div');
            emptyState.id = 'filterEmptyState';
            emptyState.className = 'col-12';
            emptyState.innerHTML = `
                <div class="empty-state">
                    <i class="bi bi-inbox"></i>
                    <h3>No matching tour requests</h3>
                    <p>Try adjusting your search or status filter.</p>
                </div>
            `;
            grid.appendChild(emptyState);
        }
    } else if (existing) {
        existing.remove();
    }
}

// Enhanced openDetails function
function openDetails(tourId) {
    const modal = new bootstrap.Modal(document.getElementById('tourDetailsModal'));
    const modalBody = document.getElementById('modalBody');
    
    // Show loading state
    modalBody.innerHTML = `
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-3 text-muted">Loading tour request details...</p>
        </div>
    `;
    
    modal.show();
    
    // Fetch tour details from server and map to UI schema
    fetch(`admin_tour_request_details.php?tour_id=${tourId}`)
        .then(response => response.json())
        .then(data => {
            if (data && data.success && data.data) {
                const d = data.data;
                const tour = {
                    // Status and IDs
                    tour_id: d.tour_id,
                    status: d.request_status || 'Pending',
                    property_id: d.property_id,

                    // Property
                    property_address: d.StreetAddress || d.property_address || 'N/A',
                    property_city: d.City || d.property_city || 'N/A',
                    property_type: d.PropertyType || d.property_type || '',
                    property_image: d.property_image || '',

                    // Client
                    client_name: d.user_name || `${d.first_name ?? ''} ${d.last_name ?? ''}`.trim(),
                    client_email: d.user_email || d.email || '',
                    client_phone: d.user_phone || d.phone_number || '',

                    // Agent
                    agent_name: (d.agent_first_name && d.agent_last_name) ? `${d.agent_first_name} ${d.agent_last_name}` : (d.agent_name || ''),

                    // Dates
                    tour_date: d.tour_date_fmt || (d.tour_date ? new Date(d.tour_date).toLocaleDateString() : ''),
                    tour_time: d.tour_time_fmt || (d.tour_time || ''),
                    requested_at: d.requested_at_fmt || (d.requested_at || ''),

                    // Tour Type
                    tour_type: d.tour_type || '',

                    // Notes
                    notes: d.message || d.decision_reason || ''
                };
                displayTourDetails(tour);
            } else {
                modalBody.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Error loading tour details: ${(data && data.message) ? data.message : 'Unknown error'}
                    </div>
                `;
            }
        })
        .catch(error => {
            modalBody.innerHTML = `
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Error loading tour details. Please try again.
                </div>
            `;
            console.error('Error:', error);
        });
}

function displayTourDetails(tour) {
    const statusConfig = {
        'Pending': { class: 'status-pending', icon: 'bi-clock-history' },
        'Confirmed': { class: 'status-confirmed', icon: 'bi-check-circle-fill' },
        'Completed': { class: 'status-completed', icon: 'bi-flag-fill' },
        'Cancelled': { class: 'status-cancelled', icon: 'bi-x-circle-fill' },
        'Rejected': { class: 'status-rejected', icon: 'bi-slash-circle-fill' },
        'Expired': { class: 'status-expired', icon: 'bi-hourglass-bottom' }
    };
    
    const config = statusConfig[tour.status] || statusConfig['Pending'];
    
    const rawType = getTourType(tour);
    const typeVal = normalizeTourType(rawType);
    const typeLabel = typeVal ? (typeVal === 'public' ? 'Public' : 'Private') : null;
    const typeClass = typeVal ? (typeVal === 'public' ? 'type-public' : 'type-private') : '';
    
    const modalBody = document.getElementById('modalBody');
    modalBody.innerHTML = `
        <!-- Property Image -->
        ${tour.property_image ? `
            <img src="${tour.property_image}" alt="Property" class="modal-property-image" onerror="this.src='uploads/default-property.jpg'">
        ` : ''}
        
        <!-- Status Badge -->
        <div class="mb-4">
            <span class="modal-status-badge ${config.class}">
                <i class="bi ${config.icon}"></i>
                ${tour.status}
            </span>
            ${typeLabel ? `<span class="ms-2 type-badge ${typeClass}" title="Tour Type">${typeLabel}</span>` : ''}
        </div>

        <!-- Property Information -->
        <div class="modal-section">
            <div class="modal-section-title"><i class="bi bi-building me-2"></i>Property Information</div>
            <div class="modal-info-row">
                <div class="modal-info-label"><i class="bi bi-geo-alt"></i>Address</div>
                <div class="modal-info-value">${escapeHtml(tour.property_address)}</div>
            </div>
            <div class="modal-info-row">
                <div class="modal-info-label"><i class="bi bi-pin-map"></i>City</div>
                <div class="modal-info-value">${escapeHtml(tour.property_city)}</div>
            </div>
            <div class="modal-info-row">
                <div class="modal-info-label"><i class="bi bi-house"></i>Property Type</div>
                <div class="modal-info-value">${escapeHtml(tour.property_type || 'N/A')}</div>
            </div>
            <div class="modal-info-row">
                <div class="modal-info-label"><i class="bi bi-hash"></i>Property ID</div>
                <div class="modal-info-value">#${tour.property_id}</div>
            </div>
        </div>

        <!-- Client Information -->
        <div class="modal-section">
            <div class="modal-section-title"><i class="bi bi-person me-2"></i>Client Information</div>
            <div class="modal-info-row">
                <div class="modal-info-label"><i class="bi bi-person-fill"></i>Name</div>
                <div class="modal-info-value">${escapeHtml(tour.client_name)}</div>
            </div>
            <div class="modal-info-row">
                <div class="modal-info-label"><i class="bi bi-envelope"></i>Email</div>
                <div class="modal-info-value"><a href="mailto:${escapeHtml(tour.client_email)}">${escapeHtml(tour.client_email)}</a></div>
            </div>
            ${tour.client_phone ? `
                <div class="modal-info-row">
                    <div class="modal-info-label"><i class="bi bi-telephone"></i>Phone</div>
                    <div class="modal-info-value"><a href="tel:${escapeHtml(tour.client_phone)}">${escapeHtml(tour.client_phone)}</a></div>
                </div>
            ` : ''}
        </div>

        <!-- Tour Schedule -->
        <div class="modal-section">
            <div class="modal-section-title"><i class="bi bi-calendar-event me-2"></i>Tour Schedule</div>
            <div class="modal-info-row">
                <div class="modal-info-label"><i class="bi bi-calendar3"></i>Date</div>
                <div class="modal-info-value">${escapeHtml(tour.tour_date)}</div>
            </div>
            <div class="modal-info-row">
                <div class="modal-info-label"><i class="bi bi-clock"></i>Time</div>
                <div class="modal-info-value">${escapeHtml(tour.tour_time)}</div>
            </div>
            <div class="modal-info-row">
                <div class="modal-info-label"><i class="bi bi-calendar-plus"></i>Requested</div>
                <div class="modal-info-value">${escapeHtml(tour.requested_at)}</div>
            </div>
            ${typeLabel ? `
            <div class="modal-info-row">
                <div class="modal-info-label"><i class="bi bi-people"></i>Tour Type</div>
                <div class="modal-info-value"><span class="type-badge ${typeClass}">${typeLabel}</span></div>
            </div>
            ` : ''}
        </div>

        ${tour.agent_name ? `
            <!-- Agent Information -->
            <div class="modal-section">
                <div class="modal-section-title"><i class="bi bi-person-badge me-2"></i>Assigned Agent</div>
                <div class="modal-info-row">
                    <div class="modal-info-label"><i class="bi bi-person-check"></i>Agent Name</div>
                    <div class="modal-info-value">${escapeHtml(tour.agent_name)}</div>
                </div>
            </div>
        ` : ''}

        ${tour.notes ? `
            <!-- Additional Notes -->
            <div class="modal-section">
                <div class="modal-section-title"><i class="bi bi-chat-left-text me-2"></i>Additional Notes</div>
                <div class="alert alert-info mb-0">
                    <i class="bi bi-info-circle me-2"></i>${escapeHtml(tour.notes)}
                </div>
            </div>
        ` : ''}
    `;
    
    // Update modal footer with action buttons based on status
    updateModalActions(tour);
}

function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return String(text || '').replace(/[&<>"']/g, m => map[m]);
}

// Resolve tour_type safely, with fallback to calendar data
function getTourType(tour) {
    if (tour && tour.tour_type) return tour.tour_type;
    try {
        const id = tour && tour.tour_id;
        if (!id || !window.calendarData || !Array.isArray(window.calendarData.tours)) return null;
        const found = window.calendarData.tours.find(t => String(t.tour_id) === String(id));
        return found ? found.tour_type : null;
    } catch (e) {
        return null;
    }
}

// Normalize tour type to 'public' | 'private' or null if unknown
function normalizeTourType(val) {
    if (val === undefined || val === null) return null;
    const t = String(val).trim().toLowerCase();
    if (t === 'public') return 'public';
    if (t === 'private') return 'private';
    return null;
}

function updateModalActions(tour) {
    const modalFooter = document.getElementById('modalFooter');
    if (!modalFooter) return;
    
    let actionButtons = '';
    
    // Store tour_id globally for action handlers
    window.currentTourId = tour.tour_id;
    
    if (tour.status === 'Pending') {
        actionButtons = `
            <button type="button" class="btn btn-success modal-action-btn" onclick="handleAccept(${tour.tour_id})">
                <i class="bi bi-check-circle me-2"></i>Confirm Tour
            </button>
            <button type="button" class="btn btn-danger modal-action-btn" onclick="handleReject(${tour.tour_id})">
                <i class="bi bi-x-circle me-2"></i>Reject Request
            </button>
        `;
    } else if (tour.status === 'Confirmed') {
        actionButtons = `
            <button type="button" class="btn btn-success modal-action-btn" onclick="handleComplete(${tour.tour_id})">
                <i class="bi bi-flag-fill me-2"></i>Mark as Completed
            </button>
            <button type="button" class="btn btn-warning modal-action-btn" onclick="handleCancel(${tour.tour_id})">
                <i class="bi bi-ban me-2"></i>Cancel Tour
            </button>
        `;
    }
    
    modalFooter.innerHTML = `
        ${actionButtons}
        <button type="button" class="btn btn-secondary modal-action-btn" data-bs-dismiss="modal">
            <i class="bi bi-x-lg me-2"></i>Close
        </button>
    `;
}

// Action handlers
function handleAccept(tourId) {
    // First check if this is a public tour with existing confirmed public tours
    const tours = (typeof calendarData !== 'undefined' && calendarData && Array.isArray(calendarData.tours)) ? calendarData.tours : [];
    const tour = tours.find(t => String(t.tour_id) === String(tourId));
    if (!tour) {
        showConfirmationModal(
            'Confirm Tour Request',
            'Are you sure you want to confirm this tour request? The client will be notified via email.',
            () => {
                executeAction('admin_tour_request_accept.php', tourId, 'Confirmed', 'Tour request confirmed successfully!');
            }
        );
        return;
    }

    const tourType = normalizeTourType(tour.tour_type);
    
    // Check for grouped public tours
    if (tourType === 'public') {
        const existingPublicTours = tours.filter(t => 
            String(t.tour_id) !== String(tourId) &&
            t.request_status === 'Confirmed' &&
            normalizeTourType(t.tour_type) === 'public' &&
            String(t.property_id) === String(tour.property_id) &&
            normalizeDateKey(t.tour_date) === normalizeDateKey(tour.tour_date) &&
            t.tour_time === tour.tour_time
        );

        if (existingPublicTours.length > 0) {
            const groupMessage = `
                <div class="alert alert-warning" style="border-left: 4px solid #ffc107; background: linear-gradient(to right, rgba(255, 193, 7, 0.15), rgba(255, 193, 7, 0.05)); margin-bottom: 1rem;">
                    <div style="display: flex; align-items: start; gap: 0.75rem;">
                        <i class="bi bi-exclamation-triangle-fill" style="color: #ffc107; font-size: 1.5rem; flex-shrink: 0;"></i>
                        <div>
                            <h6 style="margin: 0 0 0.5rem 0; color: #856404; font-weight: 700;">Public Group Tour Notice</h6>
                            <p style="margin: 0; color: #856404; font-size: 0.9rem;">
                                <strong>${existingPublicTours.length}</strong> other public tour request(s) have already been confirmed for the same property, date, and time. 
                                This tour will be grouped with the existing confirmed tour(s).
                            </p>
                        </div>
                    </div>
                </div>
                <p>Are you sure you want to confirm this public tour request? The client will be notified via email.</p>
            `;
            
            showConfirmationModal(
                'Confirm Public Group Tour',
                groupMessage,
                () => {
                    executeAction('admin_tour_request_accept.php', tourId, 'Confirmed', 'Tour request confirmed successfully!');
                }
            );
            return;
        }
    }

    // Normal confirmation for private tours or public tours without existing groups
    showConfirmationModal(
        'Confirm Tour Request',
        'Are you sure you want to confirm this tour request? The client will be notified via email.',
        () => {
            executeAction('admin_tour_request_accept.php', tourId, 'Confirmed', 'Tour request confirmed successfully!');
        }
    );
}

function handleReject(tourId) {
    showInputModal(
        'Reject Tour Request',
        'Please provide a reason for rejecting this tour request:',
        (reason) => {
            executeAction('admin_tour_request_reject.php', tourId, 'Rejected', 'Tour request rejected successfully!', { reason: reason });
        }
    );
}

function handleCancel(tourId) {
    showInputModal(
        'Cancel Tour',
        'Please provide a reason for cancelling this confirmed tour:',
        (reason) => {
            executeAction('admin_tour_request_cancel.php', tourId, 'Cancelled', 'Tour cancelled successfully!', { reason: reason });
        }
    );
}

function handleComplete(tourId) {
    showConfirmationModal(
        'Mark Tour as Completed',
        'Are you sure you want to mark this tour as completed? This action confirms that the property tour has been successfully conducted.',
        () => {
            executeAction('admin_tour_request_complete.php', tourId, 'Completed', 'Tour marked as completed successfully!');
        }
    );
}

// Show confirmation modal
function showConfirmationModal(title, message, onConfirm) {
    const modal = new bootstrap.Modal(document.getElementById('confirmationModal'));
    document.getElementById('confirmationModalLabel').innerHTML = `<i class="bi bi-question-circle me-2"></i>${title}`;
    document.getElementById('confirmationModalBody').innerHTML = `${message}`;
    
    const confirmBtn = document.getElementById('confirmActionBtn');
    
    // Remove old event listeners by cloning
    const newConfirmBtn = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
    
    newConfirmBtn.addEventListener('click', () => {
        modal.hide();
        onConfirm();
    });
    
    modal.show();
}

// Show input modal (for reasons)
function showInputModal(title, prompt, onSubmit) {
    const modal = new bootstrap.Modal(document.getElementById('inputModal'));
    const input = document.getElementById('reasonInput');
    const error = document.getElementById('reasonError');
    
    document.getElementById('inputModalLabel').innerHTML = `<i class="bi bi-chat-left-text me-2"></i>${title}`;
    document.getElementById('inputModalPrompt').textContent = prompt;
    input.value = '';
    input.classList.remove('is-invalid');
    error.style.display = 'none';
    
    const submitBtn = document.getElementById('submitReasonBtn');
    
    // Remove old event listeners by cloning
    const newSubmitBtn = submitBtn.cloneNode(true);
    submitBtn.parentNode.replaceChild(newSubmitBtn, submitBtn);
    
    newSubmitBtn.addEventListener('click', () => {
        const reason = input.value.trim();
        if (!reason) {
            input.classList.add('is-invalid');
            error.style.display = 'block';
            return;
        }
        modal.hide();
        onSubmit(reason);
    });
    
    modal.show();
}

function executeAction(endpoint, tourId, newStatus, successMessage, extraData = {}) {
    const modalBody = document.getElementById('modalBody');
    const originalContent = modalBody.innerHTML;
    
    // Show loading
    modalBody.innerHTML = `
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Processing...</span>
            </div>
            <p class="mt-3 text-muted">Processing your request...</p>
        </div>
    `;
    
    const formData = new URLSearchParams();
    formData.append('tour_id', tourId);
    for (const key in extraData) {
        formData.append(key, extraData[key]);
    }
    
    fetch(endpoint, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success message
            showNotification(successMessage, 'success');
            
            // Update the card status in the grid
            updateCardStatus(tourId, newStatus);
            
            // Close modal and reload after short delay
            setTimeout(() => {
                const modal = bootstrap.Modal.getInstance(document.getElementById('tourDetailsModal'));
                if (modal) modal.hide();
                location.reload();
            }, 1500);
        } else {
            // Restore content and show error
            modalBody.innerHTML = originalContent;
            showNotification(data.message || 'Action failed. Please try again.', 'danger');
        }
    })
    .catch(error => {
        // Restore content and show error
        modalBody.innerHTML = originalContent;
        showNotification('Network error. Please try again.', 'danger');
        console.error('Error:', error);
    });
}

function updateCardStatus(tourId, newStatus) {
    const card = document.getElementById(`card-${tourId}`);
    if (!card) return;
    
    // Update data-status attribute
    card.setAttribute('data-status', newStatus);
    
    // Update status badge
    const statusBadge = card.querySelector('.status-badge');
    if (statusBadge) {
        statusBadge.textContent = newStatus;
        statusBadge.className = 'status-badge';
        
        switch(newStatus) {
            case 'Confirmed': statusBadge.classList.add('status-confirmed'); break;
            case 'Completed': statusBadge.classList.add('status-completed'); break;
            case 'Cancelled': statusBadge.classList.add('status-cancelled'); break;
            case 'Rejected': statusBadge.classList.add('status-rejected'); break;
            default: statusBadge.classList.add('status-pending');
        }
    }
}

function showNotification(message, type = 'info') {
    const colors = {
        success: { bg: '#d1e7dd', border: '#198754', text: '#0f5132' },
        danger: { bg: '#f8d7da', border: '#dc3545', text: '#842029' },
        warning: { bg: '#fff3cd', border: '#ffc107', text: '#856404' },
        info: { bg: '#cfe2ff', border: '#0d6efd', text: '#084298' }
    };
    const color = colors[type] || colors.info;
    
    const notification = document.createElement('div');
    notification.className = 'position-fixed';
    notification.style.cssText = `
        top: 20px;
        right: 20px;
        z-index: 9999;
        max-width: 350px;
        background-color: ${color.bg};
        color: ${color.text};
        border: 1px solid ${color.border};
        border-left: 4px solid ${color.border};
        border-radius: 8px;
        padding: 1rem;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        transform: translateX(400px);
        transition: transform 0.3s ease;
    `;
    notification.innerHTML = `
        <div class="d-flex align-items-center">
            <i class="bi bi-${type === 'success' ? 'check-circle' : type === 'danger' ? 'exclamation-triangle' : 'info-circle'} me-2"></i>
            <div class="flex-grow-1">${escapeHtml(message)}</div>
            <button type="button" class="btn-close ms-2" onclick="this.parentElement.parentElement.remove()"></button>
        </div>
    `;
    document.body.appendChild(notification);
    
    setTimeout(() => notification.style.transform = 'translateX(0)', 10);
    setTimeout(() => {
        notification.style.transform = 'translateX(400px)';
        setTimeout(() => notification.remove(), 300);
    }, 5000);
}
</script>

<!-- Calendar Sidebar -->
<div class="calendar-sidebar" id="calendarSidebar">
    <div class="calendar-sidebar-overlay" id="calendarOverlay"></div>
    <div class="calendar-sidebar-content">
        <div class="calendar-header">
            <h4 class="mb-0">
                <i class="bi bi-calendar3 me-2"></i>Tour Schedule Calendar
            </h4>
            <button class="btn-close-sidebar" id="closeCalendarBtn">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        
        <!-- Debug Toggle -->
        <div class="debug-toggle-container">
            <div class="form-check form-switch">
                <input class="form-check-input me-2" type="checkbox" id="showAllStatusesToggle" onchange="toggleDebugMode()">
                <label class="form-check-label" for="showAllStatusesToggle">
                    <small>Show All Status</small>
                </label>
            </div>
        </div>
        
        <div class="calendar-body">
            <!-- Calendar Widget -->
            <div class="calendar-widget">
                <div class="calendar-controls">
                    <button class="btn btn-sm btn-outline-secondary" id="prevMonthBtn">
                        <i class="bi bi-chevron-left"></i>
                    </button>
                    <h5 class="calendar-month-year mb-0" id="calendarMonthYear">January 2025</h5>
                    <button class="btn btn-sm btn-outline-secondary" id="nextMonthBtn">
                        <i class="bi bi-chevron-right"></i>
                    </button>
                </div>
                
                <div class="calendar-grid" id="calendarGrid">
                    <!-- Calendar will be rendered here by JS -->
                </div>
                
                <div class="calendar-legend">
                    <div class="legend-item">
                        <span class="legend-dot" style="background: #ffc107;"></span>
                        <span>Pending</span>
                    </div>
                    <div class="legend-item">
                        <span class="legend-dot" style="background: #28a745;"></span>
                        <span>Confirmed</span>
                    </div>
                    <div class="legend-item debug-only" style="display: none;">
                        <span class="legend-dot" style="background: #6c757d;"></span>
                        <span>Cancelled</span>
                    </div>
                    <div class="legend-item debug-only" style="display: none;">
                        <span class="legend-dot" style="background: #dc3545;"></span>
                        <span>Rejected</span>
                    </div>
                    <div class="legend-item debug-only" style="display: none;">
                        <span class="legend-dot" style="background: #17a2b8;"></span>
                        <span>Completed</span>
                    </div>
                    <div class="legend-item debug-only" style="display: none;">
                        <span class="legend-dot" style="background: #9ca3af;"></span>
                        <span>Expired</span>
                    </div>
                </div>
            </div>
            
            <!-- Scheduled Tours List -->
            <div class="scheduled-tours-section">
                <div class="section-header">
                    <h6 class="mb-0" id="scheduledToursTitle">
                        <i class="bi bi-list me-2"></i>All Scheduled Tours (<span id="tourCount">0</span>)
                    </h6>
                    <button class="btn btn-sm btn-outline-secondary" id="clearDateFilter" style="display:none;">
                        <i class="bi bi-x me-1"></i>Clear Filter
                    </button>
                </div>
                
                <div class="scheduled-tours-list" id="scheduledToursList">
                    <!-- Tours will be rendered here by JS -->
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// ===== CALENDAR FUNCTIONALITY =====
const calendarData = {
    currentDate: new Date(),
    selectedDate: null,
    tours: <?php echo json_encode($tour_requests); ?>,
    toursByDate: {}
};

// Normalize date to YYYY-MM-DD
function normalizeDateKey(value) {
    if (!value) return '';
    return String(value).slice(0, 10);
}

// Debug mode state
let debugMode = false;

// Toggle debug mode
function toggleDebugMode() {
    debugMode = document.getElementById('showAllStatusesToggle').checked;
    
    // Show/hide debug legend items
    document.querySelectorAll('.legend-item.debug-only').forEach(item => {
        item.style.display = debugMode ? 'flex' : 'none';
    });
    
    // Reinitialize and re-render calendar
    initializeToursByDate();
    renderCalendar();
    renderScheduledTours();
    
    console.log('Debug mode:', debugMode ? 'ON' : 'OFF');
}

// Initialize tours by date
function initializeToursByDate() {
    calendarData.toursByDate = {};
    calendarData.tours.forEach(tour => {
        const status = (tour.request_status || '').toString();
        // Skip rejected/cancelled/completed unless debug mode is on
        if (!debugMode && status !== 'Pending' && status !== 'Confirmed') return;
        
        const dateKey = normalizeDateKey(tour.tour_date);
        if (!dateKey) return;
        if (!calendarData.toursByDate[dateKey]) {
            calendarData.toursByDate[dateKey] = [];
        }
        calendarData.toursByDate[dateKey].push(tour);
    });
}

// Open/Close Calendar Sidebar
const openCalBtn = document.getElementById('openCalendarBtn');
if (openCalBtn) openCalBtn.addEventListener('click', () => {
    document.getElementById('calendarSidebar').classList.add('active');
    initializeToursByDate();
    renderCalendar();
    renderScheduledTours();
});

const closeCalBtn = document.getElementById('closeCalendarBtn');
if (closeCalBtn) closeCalBtn.addEventListener('click', () => {
    document.getElementById('calendarSidebar').classList.remove('active');
});

const calOverlay = document.getElementById('calendarOverlay');
if (calOverlay) calOverlay.addEventListener('click', () => {
    document.getElementById('calendarSidebar').classList.remove('active');
});

// Calendar Navigation
document.getElementById('prevMonthBtn').addEventListener('click', () => {
    calendarData.currentDate.setMonth(calendarData.currentDate.getMonth() - 1);
    renderCalendar();
});

document.getElementById('nextMonthBtn').addEventListener('click', () => {
    calendarData.currentDate.setMonth(calendarData.currentDate.getMonth() + 1);
    renderCalendar();
});

// Render Calendar
function renderCalendar() {
    const year = calendarData.currentDate.getFullYear();
    const month = calendarData.currentDate.getMonth();
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const prevLastDay = new Date(year, month, 0);
    const firstDayIndex = firstDay.getDay();
    const lastDayIndex = lastDay.getDay();
    const nextDays = 7 - lastDayIndex - 1;
    
    const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 
                        'July', 'August', 'September', 'October', 'November', 'December'];
    
    document.getElementById('calendarMonthYear').textContent = `${monthNames[month]} ${year}`;
    
    const grid = document.getElementById('calendarGrid');
    grid.innerHTML = '';
    
    // Day headers
    const dayHeaders = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    dayHeaders.forEach(day => {
        const header = document.createElement('div');
        header.className = 'calendar-day-header';
        header.textContent = day;
        grid.appendChild(header);
    });
    
    // Previous month days
    for (let i = firstDayIndex; i > 0; i--) {
        const day = document.createElement('div');
        day.className = 'calendar-day disabled';
        day.textContent = prevLastDay.getDate() - i + 1;
        grid.appendChild(day);
    }
    
    // Current month days
    const today = new Date();
    for (let i = 1; i <= lastDay.getDate(); i++) {
        const day = document.createElement('div');
        day.className = 'calendar-day';
        day.textContent = i;
        
        const dateKey = `${year}-${String(month + 1).padStart(2, '0')}-${String(i).padStart(2, '0')}`;
        const isToday = i === today.getDate() && month === today.getMonth() && year === today.getFullYear();
        const isSelected = calendarData.selectedDate === dateKey;
        
        if (isToday) day.classList.add('today');
        if (isSelected) day.classList.add('selected');
        
        // Check if this date has tours
        if (calendarData.toursByDate[dateKey]) {
            const tours = calendarData.toursByDate[dateKey];
            day.classList.add('has-tours');
            
            // Determine status classes
            const hasConfirmed = tours.some(t => t.request_status === 'Confirmed');
            const hasPending = tours.some(t => t.request_status === 'Pending');
            
            if (hasConfirmed) day.classList.add('has-confirmed');
            else if (hasPending) day.classList.add('has-pending');
            
            // Debug mode: Add additional status markers
            if (debugMode) {
                const hasCancelled = tours.some(t => t.request_status === 'Cancelled');
                const hasRejected = tours.some(t => t.request_status === 'Rejected');
                const hasCompleted = tours.some(t => t.request_status === 'Completed');
                const hasExpired = tours.some(t => t.request_status === 'Expired');
                
                if (hasCancelled) day.classList.add('has-cancelled');
                if (hasRejected) day.classList.add('has-rejected');
                if (hasCompleted) day.classList.add('has-completed');
                if (hasExpired) day.classList.add('has-expired');
            }
        }
        
        day.addEventListener('click', () => selectDate(dateKey));
        grid.appendChild(day);
    }
    
    // Next month days
    for (let i = 1; i <= nextDays; i++) {
        const day = document.createElement('div');
        day.className = 'calendar-day disabled';
        day.textContent = i;
        grid.appendChild(day);
    }
}

// Select Date
function selectDate(dateKey) {
    if (calendarData.selectedDate === dateKey) {
        // Deselect if clicking same date
        calendarData.selectedDate = null;
        document.getElementById('clearDateFilter').style.display = 'none';
    } else {
        calendarData.selectedDate = dateKey;
        document.getElementById('clearDateFilter').style.display = 'inline-block';
    }
    renderCalendar();
    renderScheduledTours();
}

// Clear Date Filter
document.getElementById('clearDateFilter').addEventListener('click', () => {
    calendarData.selectedDate = null;
    document.getElementById('clearDateFilter').style.display = 'none';
    renderCalendar();
    renderScheduledTours();
});

// Render Scheduled Tours
function renderScheduledTours() {
    const list = document.getElementById('scheduledToursList');
    const title = document.getElementById('scheduledToursTitle');
    const count = document.getElementById('tourCount');
    
    let toursToShow = calendarData.tours;
    let titleText = 'All Scheduled Tours';
    
    if (calendarData.selectedDate) {
        const key = normalizeDateKey(calendarData.selectedDate);
        toursToShow = calendarData.toursByDate[key] || [];
        const date = new Date(calendarData.selectedDate + 'T00:00:00');
        titleText = `Tours on ${date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`;
    }
    
    // Filter out completed, cancelled, and rejected (unless debug mode is on)
    if (!debugMode) {
        toursToShow = toursToShow.filter(t => ['Pending', 'Confirmed'].includes(t.request_status));
    }
    
    count.textContent = toursToShow.length;
    title.innerHTML = `<i class="bi bi-list me-2"></i>${titleText} (<span id="tourCount">${toursToShow.length}</span>)`;
    
    if (toursToShow.length === 0) {
        list.innerHTML = `
            <div class="tour-item-empty">
                <i class="bi bi-calendar-x" style="font-size: 3rem; color: #ccc;"></i>
                <p class="mb-0 mt-2">No tours scheduled for this ${calendarData.selectedDate ? 'date' : 'period'}</p>
            </div>
        `;
        return;
    }
    
    // Sort by time
    toursToShow.sort((a, b) => {
        const timeA = new Date(`2000-01-01T${a.tour_time}`);
        const timeB = new Date(`2000-01-01T${b.tour_time}`);
        return timeA - timeB;
    });
    
    // Check for time conflicts
    const timeConflicts = new Map();
    toursToShow.forEach((tour, index) => {
        const key = `${tour.tour_date}_${tour.tour_time}`;
        if (!timeConflicts.has(key)) {
            timeConflicts.set(key, []);
        }
        timeConflicts.get(key).push(index);
    });
    
    list.innerHTML = toursToShow.map((tour, index) => {
        const status = tour.request_status;
        let statusClass = 'status-pending';
        let statusIcon = 'clock';
        
        if (status === 'Confirmed') {
            statusClass = 'status-confirmed';
            statusIcon = 'check';
        } else if (status === 'Cancelled') {
            statusClass = 'status-cancelled';
            statusIcon = 'slash-circle';
        } else if (status === 'Rejected') {
            statusClass = 'status-rejected';
            statusIcon = 'x-circle';
        } else if (status === 'Completed') {
            statusClass = 'status-completed';
            statusIcon = 'check-all';
        } else if (status === 'Expired') {
            statusClass = 'status-expired';
            statusIcon = 'hourglass-bottom';
        }
        
        const timeKey = `${tour.tour_date}_${tour.tour_time}`;
        const hasConflict = timeConflicts.get(timeKey).length > 1;
        const tNorm = normalizeTourType(tour.tour_type);
        const tClass = tNorm ? (tNorm === 'public' ? 'type-public' : 'type-private') : '';
        const tLabel = tNorm ? (tNorm === 'public' ? 'Public' : 'Private') : null;
        
        return `
            <div class="tour-item ${statusClass}" data-tour-id="${tour.tour_id}">
                <div class="tour-item-header">
                    <div class="tour-client-name">${escapeHtml(tour.user_name)}</div>
                    <span class="status-badge ${statusClass}">
                        <i class="bi bi-${statusIcon}"></i>
                    </span>
                </div>
                <div class="tour-time">
                    <i class="bi bi-clock"></i>
                    ${formatTime(tour.tour_time)}
                </div>
                <div class="tour-property">
                    <i class="bi bi-geo-alt me-1"></i>
                    ${escapeHtml(tour.StreetAddress + ', ' + tour.City)}
                </div>
                ${tLabel ? `
                <div class="mt-1">
                    <span class="type-badge ${tClass}">${tLabel}</span>
                </div>
                ` : ''}
                ${hasConflict ? `
                    <div class="tour-conflict-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <span>Time conflict detected! ${timeConflicts.get(timeKey).length} tours at same time</span>
                    </div>
                ` : ''}
            </div>
        `;
    }).join('');
    
    // Add click handlers to tour items
    list.querySelectorAll('.tour-item').forEach(item => {
        item.addEventListener('click', () => {
            const tourId = item.getAttribute('data-tour-id');
            const row = document.querySelector(`.tour-card[data-tour-id="${tourId}"]`);
            if (row) {
                document.getElementById('calendarSidebar').classList.remove('active');
                row.click();
            }
        });
    });
}

// Helper functions
function formatTime(timeStr) {
    const date = new Date(`2000-01-01T${timeStr}`);
    return date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
}

function escapeHtml(text) {
    const map = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
    return String(text || '').replace(/[&<>"']/g, m => map[m]);
}
// ===== END CALENDAR FUNCTIONALITY =====
</script>

<script>
// Enhance existing cards with Public/Private badges using loaded data
function enhanceTypeBadgesOnCards() {
    try {
        const list = Array.from(document.querySelectorAll('.tour-card'));
        if (!list.length || !window.calendarData || !Array.isArray(window.calendarData.tours)) return;
        list.forEach(card => {
            const id = card.getAttribute('data-tour-id');
            if (!id) return;
            const tour = window.calendarData.tours.find(t => String(t.tour_id) === String(id));
            if (!tour) return;
            const tNorm = normalizeTourType(tour.tour_type);
            if (!tNorm) return; // skip if unknown
            const label = tNorm === 'public' ? 'Public' : 'Private';
            const cls = tNorm === 'public' ? 'type-public' : 'type-private';
            const badge = document.createElement('span');
            badge.className = `type-badge ${cls}`;
            badge.textContent = label;
            // Prefer placing in header if present
            const header = card.querySelector('.tour-card-header');
            if (header && !header.querySelector('.type-badge')) {
                const container = document.createElement('div');
                container.className = 'd-flex align-items-center gap-2';
                // Place badge at the end of header content
                header.appendChild(badge);
            } else {
                const body = card.querySelector('.tour-card-body');
                if (body && !body.querySelector('.type-badge')) {
                    const wrap = document.createElement('div');
                    wrap.className = 'mb-2';
                    wrap.appendChild(badge);
                    body.prepend(wrap);
                }
            }
        });
    } catch (e) {
        // no-op
    }
}

document.addEventListener('DOMContentLoaded', enhanceTypeBadgesOnCards);
    
    // Wire search and status filters
    document.addEventListener('DOMContentLoaded', function() {
        const search = document.getElementById('tourSearch');
        const status = document.getElementById('tourStatusFilter');
        if (search) search.addEventListener('input', applyFilters);
        if (status) status.addEventListener('change', applyFilters);
    });
</script>

</body>
</html>