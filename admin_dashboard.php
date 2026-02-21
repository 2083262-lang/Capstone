<?php
session_start();
include 'connection.php';
include 'admin_profile_check.php';

// Check if the user is logged in AND their role is 'admin'
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$admin_username = $_SESSION['username'];
$admin_result = $conn->query("SELECT first_name FROM accounts WHERE account_id = {$_SESSION['account_id']}");
$admin_first_name = $admin_result && $admin_result->num_rows > 0 ? $admin_result->fetch_assoc()['first_name'] : 'Admin';

// Check if admin has completed their profile
$profile_completed = checkAdminProfileCompletion($conn, $_SESSION['account_id']);

// --- DASHBOARD ANALYTICS QUERIES ---
$total_properties = $conn->query("SELECT COUNT(*) as count FROM property")->fetch_assoc()['count'];
$approved_properties = $conn->query("SELECT COUNT(*) as count FROM property WHERE approval_status = 'approved'")->fetch_assoc()['count'];
$pending_properties = $conn->query("SELECT COUNT(*) as count FROM property WHERE approval_status = 'pending'")->fetch_assoc()['count'];
$rejected_properties = $conn->query("SELECT COUNT(*) as count FROM property WHERE approval_status = 'rejected'")->fetch_assoc()['count'];
$sold_properties = $conn->query("SELECT COUNT(*) as count FROM property WHERE Status = 'Sold'")->fetch_assoc()['count'];

$highest_priced_property = $conn->query("
    SELECT StreetAddress, City, ListingPrice 
    FROM property 
    WHERE approval_status = 'approved' AND Status <> 'Sold' 
    ORDER BY ListingPrice DESC 
    LIMIT 1
")->fetch_assoc();

$lowest_priced_property = $conn->query("
    SELECT StreetAddress, City, ListingPrice 
    FROM property 
    WHERE approval_status = 'approved' AND ListingPrice > 0 AND Status <> 'Sold'
    ORDER BY ListingPrice ASC 
    LIMIT 1
")->fetch_assoc();

$most_viewed_property = $conn->query("
    SELECT StreetAddress, City, ViewsCount 
    FROM property 
    WHERE approval_status = 'approved' 
    ORDER BY ViewsCount DESC 
    LIMIT 1
")->fetch_assoc();

$total_agents = $conn->query("SELECT COUNT(*) as count FROM agent_information")->fetch_assoc()['count'];
$approved_agents = $conn->query("SELECT COUNT(*) as count FROM agent_information WHERE is_approved = 1")->fetch_assoc()['count'];
// Pending agents should exclude those already rejected
$pending_agents = $conn->query("
        SELECT COUNT(*) as count
        FROM agent_information ai
        WHERE ai.is_approved = 0
            AND NOT EXISTS (
                SELECT 1 FROM status_log sl
                WHERE sl.item_id = ai.account_id
                    AND sl.item_type = 'agent'
                    AND sl.action = 'rejected'
            )
")->fetch_assoc()['count'];

$total_users = $conn->query("SELECT COUNT(*) as count FROM accounts a JOIN user_roles ur ON a.role_id = ur.role_id WHERE ur.role_name = 'user'")->fetch_assoc()['count'] ?? 0;
$active_users = $conn->query("SELECT COUNT(*) as count FROM accounts a JOIN user_roles ur ON a.role_id = ur.role_id WHERE ur.role_name = 'user' AND a.is_active = 1")->fetch_assoc()['count'] ?? 0;

$total_tours = $conn->query("SELECT COUNT(*) as count FROM tour_requests")->fetch_assoc()['count'];
$pending_tours = $conn->query("SELECT COUNT(*) as count FROM tour_requests WHERE request_status = 'Pending'")->fetch_assoc()['count'];
$confirmed_tours = $conn->query("SELECT COUNT(*) as count FROM tour_requests WHERE request_status = 'Confirmed'")->fetch_assoc()['count'];
$completed_tours = $conn->query("SELECT COUNT(*) as count FROM tour_requests WHERE request_status = 'Completed'")->fetch_assoc()['count'];

$total_property_value = $conn->query("SELECT SUM(ListingPrice) as total FROM property WHERE approval_status = 'approved' AND Status <> 'Sold'")->fetch_assoc()['total'] ?? 0;
$avg_property_value = $conn->query("SELECT AVG(ListingPrice) as avg FROM property WHERE approval_status = 'approved' AND ListingPrice > 0")->fetch_assoc()['avg'] ?? 0;

// Recent Activity
$recent_activity_sql = "
    (
        SELECT 'agent_status' as type, a.account_id as item_id, CONCAT(a.first_name, ' ', a.last_name) as subject, 
        sl.action, 'Agent Status Update' as description_verb, sl.log_timestamp as timestamp
        FROM status_log sl
        JOIN accounts a ON sl.item_id = a.account_id AND sl.item_type = 'agent'
        WHERE sl.action = 'approved' OR sl.action = 'rejected'
        ORDER BY sl.log_timestamp DESC
        LIMIT 4
    )
    UNION ALL
    (
        SELECT 'property_status' as type, p.property_ID as item_id, p.StreetAddress as subject, 
        sl.action, 'Property Sale Finalized' as description_verb, sl.log_timestamp as timestamp
        FROM status_log sl
        JOIN property p ON sl.item_id = p.property_ID AND sl.item_type = 'property'
        WHERE sl.action = 'approved'
        ORDER BY sl.log_timestamp DESC
        LIMIT 4
    )
    ORDER BY timestamp DESC
    LIMIT 8";
$recent_activity_result = $conn->query($recent_activity_sql);
$recent_activity = $recent_activity_result ? $recent_activity_result->fetch_all(MYSQLI_ASSOC) : [];

// Pending items
$pending_sales_list = $conn->query("
    SELECT sv.verification_id, sv.property_id, p.StreetAddress, p.City, sv.sale_price, sv.submitted_at 
    FROM sale_verifications sv
    JOIN property p ON sv.property_id = p.property_ID
    WHERE sv.status = 'Pending'
    ORDER BY sv.submitted_at DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

$pending_agents_list = $conn->query("
    SELECT a.account_id, a.first_name, a.last_name, a.email, a.date_registered 
        FROM agent_information ai 
        JOIN accounts a ON ai.account_id = a.account_id 
        WHERE ai.is_approved = 0 
            AND NOT EXISTS (
                SELECT 1 FROM status_log sl
                WHERE sl.item_id = ai.account_id
                    AND sl.item_type = 'agent'
                    AND sl.action = 'rejected'
            )
        ORDER BY a.date_registered DESC 
        LIMIT 5
")->fetch_all(MYSQLI_ASSOC);
$pending_approvals_total = count($pending_sales_list) + count($pending_agents_list);

// Chart Data
$chart_data_sql = "SELECT DATE(ListingDate) as date, COUNT(*) as count 
                    FROM property 
                    WHERE ListingDate >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    GROUP BY DATE(ListingDate)
                    ORDER BY date ASC";
$chart_result = $conn->query($chart_data_sql);
$chart_labels = [];
$chart_values = [];
if ($chart_result) {
    while($row = $chart_result->fetch_assoc()){
        $chart_labels[] = date('M d', strtotime($row['date']));
        $chart_values[] = $row['count'];
    }
}

// Property Type Distribution
$property_types = $conn->query("SELECT PropertyType, COUNT(*) as count FROM property WHERE approval_status = 'approved' GROUP BY PropertyType")->fetch_all(MYSQLI_ASSOC);

// Top Agents
$top_agents = $conn->query("
    SELECT a.account_id, a.first_name, a.last_name, a.phone_number, COUNT(p.property_ID) as property_count
    FROM accounts a
    JOIN agent_information ag ON a.account_id = ag.account_id
    LEFT JOIN property p ON a.account_id = p.sold_by_agent AND p.Status = 'Sold'
    WHERE ag.is_approved = 1
    GROUP BY a.account_id
    ORDER BY property_count DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// Recent Tours
$recent_tours = $conn->query("
    SELECT tr.tour_id, tr.request_status AS status, tr.tour_date, tr.tour_time, tr.requested_at AS created_at,
           p.StreetAddress, p.City, tr.user_name
    FROM tour_requests tr
    JOIN property p ON tr.property_id = p.property_ID
    WHERE tr.request_status = 'Pending'
    ORDER BY tr.requested_at DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

$total_views = $conn->query("SELECT SUM(ViewsCount) as total FROM property")->fetch_assoc()['total'] ?? 0;
$avg_views_per_property = $approved_properties > 0 ? round($total_views / $approved_properties) : 0;
$tour_success_rate = $total_tours > 0 ? round(($completed_tours / $total_tours) * 100, 1) : 0;

function time_elapsed_string($datetime) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    
    if ($diff->y > 0) return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    if ($diff->m > 0) return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    if ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    if ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    if ($diff->i > 0) return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
    return 'just now';
}

function formatCurrency($amount) {
    if ($amount === null || $amount === 0) return '₱0.00';
    return '₱' . number_format($amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Real Estate Analytics</title>
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

        /* Card Styles */
        .card {
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: box-shadow 0.2s ease;
        }
        
        .card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
        }

        .card-header {
            background-color: #fff;
            border-bottom: 1px solid var(--border-color);
            padding: 1.25rem 1.5rem;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Stats Cards */
        .stat-card {
            background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
            border-left: 4px solid var(--secondary-color);
            min-height: 120px;
        }

        .stat-card .card-body {
            padding: 1.5rem;
            display: flex;
            align-items: center;
            overflow: hidden;
        }

        .stat-card .d-flex {
            width: 100%;
            overflow: hidden;
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
            min-width: 56px;
        }

        .flex-grow-1 {
            min-width: 0 !important;
            flex: 1 1 0% !important;
            overflow: hidden !important;
        }

        .stat-value {
            font-size: 1.5rem !important;
            font-weight: 700 !important;
            color: var(--primary-color) !important;
            line-height: 1.2 !important;
            margin-bottom: 0.25rem !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            white-space: nowrap !important;
            max-width: 100% !important;
            display: block !important;
            width: 100% !important;
        }

        .stat-label {
            font-size: 0.8rem;
            color: #6c757d;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Responsive stat card text sizing */
        @media (max-width: 1366px) {
            .stat-value {
                font-size: 1.25rem;
            }
        }

        @media (max-width: 1200px) {
            .stat-value {
                font-size: 1.1rem;
            }
        }

        /* Quick Action Cards */
        .quick-action {
            text-decoration: none;
            display: block;
            transition: transform 0.2s ease;
            height: 100%;
        }

        .quick-action:hover {
            transform: translateY(-4px);
        }

        .quick-action-card {
            text-align: center;
            padding: 2rem 1.5rem;
            border-radius: 12px;
            border: 2px solid var(--border-color);
            background: #fff;
            min-height: 180px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            transition: all 0.2s ease;
        }

        .quick-action-card:hover {
            border-color: var(--secondary-color);
            box-shadow: 0 6px 20px rgba(0,0,0,0.1);
        }

        .quick-action-icon-wrapper {
            position: relative;
            display: inline-block;
            margin-bottom: 1rem;
        }

        .quick-action-icon {
            font-size: 2.5rem;
        }

        .quick-action-title {
            font-weight: 600;
            color: #212529;
            font-size: 1rem;
            margin-bottom: 0;
        }

        .quick-action-badge {
            position: absolute;
            top: -8px;
            right: -12px;
            background-color: #dc3545;
            color: #fff;
            border-radius: 20px;
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
            font-weight: 700;
            min-width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #fff;
            box-shadow: 0 2px 6px rgba(0,0,0,0.2);
        }

        /* Table Styles */
        .data-table {
            font-size: 0.9rem;
        }

        .data-table thead {
            background-color: #f8f9fa;
            border-bottom: 2px solid var(--border-color);
        }

        .data-table th {
            font-weight: 600;
            color: #495057;
            padding: 0.75rem;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.5px;
        }

        .data-table td {
            padding: 0.75rem;
            vertical-align: middle;
        }

        .data-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        /* Badge Styles */
        .badge-status {
            padding: 0.35rem 0.65rem;
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: 6px;
        }

        .badge-pending {
            background-color: #fff3cd;
            color: #856404;
        }

        .badge-approved {
            background-color: #d1e7dd;
            color: #0f5132;
        }

        .badge-completed {
            background-color: #cfe2ff;
            color: #084298;
        }

        /* Activity Feed */
        .activity-item {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-item:hover {
            background-color: #f8f9fa;
        }

        .activity-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }

        .activity-time {
            font-size: 0.75rem;
            color: #6c757d;
        }

        /* Progress Bar */
        .progress {
            height: 8px;
            border-radius: 4px;
            background-color: #e9ecef;
        }

        .progress-bar {
            background: linear-gradient(90deg, var(--secondary-color), var(--accent-color));
            border-radius: 4px;
        }

        /* Hero Header */
        .dashboard-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, #2a2318 100%);
            color: #fff;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
        }

        .dashboard-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .dashboard-header p {
            opacity: 0.9;
            margin-bottom: 0;
        }

        /* Scrollbar */
        .scrollable-content {
            max-height: 450px;
            overflow-y: auto;
        }

        .scrollable-content::-webkit-scrollbar {
            width: 6px;
        }

        .scrollable-content::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }

        .scrollable-content::-webkit-scrollbar-thumb {
            background: var(--secondary-color);
            border-radius: 3px;
        }

        /* Chart Container */
        .chart-container {
            position: relative;
            height: 350px;
        }

        /* Ensure consistent card heights in rows */
        .row > [class*='col-'] {
            display: flex;
            flex-direction: column;
        }

        .row > [class*='col-'] > .card {
            flex: 1;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #6c757d;
            min-height: 200px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        /* Card consistent styling */
        .card-body {
            min-height: 80px;
        }

        /* Market highlights specific styling */
        .market-highlight-item {
            padding: 1rem;
            border-radius: 8px;
            background-color: #f8f9fa;
            margin-bottom: 0.75rem;
        }

        .market-highlight-item:last-child {
            margin-bottom: 0;
        }
    </style>
</head>
<body>

    <?php include 'admin_sidebar.php'; ?>
    <?php include 'admin_navbar.php'; ?>

    <div class="admin-content">
        <!-- Dashboard Header -->
        <!-- Overview Stats -->
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-primary bg-opacity-10 text-primary me-3">
                                <i class="bi bi-building"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="stat-value"><?php echo number_format($approved_properties); ?></div>
                                <div class="stat-label">Active Listings</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-success bg-opacity-10 text-success me-3">
                                <i class="bi bi-people-fill"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="stat-value"><?php echo number_format($approved_agents); ?></div>
                                <div class="stat-label">Active Agents</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-warning bg-opacity-10 text-warning me-3">
                                <i class="bi bi-exclamation-circle-fill"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="stat-value"><?php echo number_format($pending_approvals_total); ?></div>
                                <div class="stat-label">Pending Approvals</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-info bg-opacity-10 text-info me-3">
                                <i class="bi bi-currency-dollar"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="stat-value" title="<?php echo formatCurrency($total_property_value); ?>"><?php echo formatCurrency($total_property_value); ?></div>
                                <div class="stat-label">Total Value</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-lg-6 col-md-6">
                <a href="approval.php?type=agent" class="quick-action">
                    <div class="quick-action-card">
                        <div class="quick-action-icon-wrapper">
                            <div class="quick-action-icon text-success">
                                <i class="bi bi-person-badge-fill"></i>
                            </div>
                            <?php if($pending_agents > 0): ?>
                                <span class="quick-action-badge"><?php echo $pending_agents; ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="quick-action-title">Review Agents</div>
                    </div>
                </a>
            </div>
            <div class="col-xl-3 col-lg-6 col-md-6">
                <a href="approval.php?type=property" class="quick-action">
                    <div class="quick-action-card">
                        <div class="quick-action-icon-wrapper">
                            <div class="quick-action-icon text-primary">
                                <i class="bi bi-building-fill"></i>
                            </div>
                            <?php if($pending_properties > 0): ?>
                                <span class="quick-action-badge"><?php echo $pending_properties; ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="quick-action-title">Review Properties</div>
                    </div>
                </a>
            </div>
            <div class="col-xl-3 col-lg-6 col-md-6">
                <a href="sales.php" class="quick-action">
                    <div class="quick-action-card">
                        <div class="quick-action-icon-wrapper">
                            <div class="quick-action-icon text-warning">
                                <i class="bi bi-currency-exchange"></i>
                            </div>
                            <?php if(count($pending_sales_list) > 0): ?>
                                <span class="quick-action-badge"><?php echo count($pending_sales_list); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="quick-action-title">Finalize Sales</div>
                    </div>
                </a>
            </div>
            <div class="col-xl-3 col-lg-6 col-md-6">
                <a href="reports.php" class="quick-action">
                    <div class="quick-action-card">
                        <div class="quick-action-icon-wrapper">
                            <div class="quick-action-icon text-danger">
                                <i class="bi bi-file-earmark-bar-graph-fill"></i>
                            </div>
                        </div>
                        <div class="quick-action-title">View Reports</div>
                    </div>
                </a>
            </div>
        </div>

        <!-- Main Content Grid -->
        <div class="row g-4 mb-4">
            <!-- Chart -->
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-graph-up me-2"></i>Property Listings Trend (Last 30 Days)
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="listingsChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Market Highlights -->
            <div class="col-lg-4">
                <div class="card h-100">
                    <div class="card-header">
                        <i class="bi bi-star-fill me-2"></i>Market Highlights
                    </div>
                    <div class="card-body">
                        <div class="market-highlight-item">
                            <small class="text-muted d-block mb-2 text-uppercase" style="font-size: 0.7rem; letter-spacing: 0.5px;">Most Viewed</small>
                            <strong class="d-block mb-1"><?php echo htmlspecialchars($most_viewed_property['StreetAddress'] ?? 'N/A'); ?></strong>
                            <small class="text-muted"><i class="bi bi-eye me-1"></i><?php echo number_format($most_viewed_property['ViewsCount'] ?? 0); ?> views</small>
                        </div>
                        <div class="market-highlight-item">
                            <small class="text-muted d-block mb-2 text-uppercase" style="font-size: 0.7rem; letter-spacing: 0.5px;">Highest Price</small>
                            <strong class="d-block text-success mb-1" style="font-size: 1.1rem;"><?php echo formatCurrency($highest_priced_property['ListingPrice'] ?? 0); ?></strong>
                            <small class="text-muted"><i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($highest_priced_property['City'] ?? 'N/A'); ?></small>
                        </div>
                        <div class="market-highlight-item">
                            <small class="text-muted d-block mb-2 text-uppercase" style="font-size: 0.7rem; letter-spacing: 0.5px;">Lowest Price</small>
                            <strong class="d-block text-danger mb-1" style="font-size: 1.1rem;"><?php echo formatCurrency($lowest_priced_property['ListingPrice'] ?? 0); ?></strong>
                            <small class="text-muted"><i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($lowest_priced_property['City'] ?? 'N/A'); ?></small>
                        </div>
                        <div class="row g-2 mt-2">
                            <div class="col-6">
                                <div class="market-highlight-item">
                                    <small class="text-muted d-block mb-2 text-uppercase" style="font-size: 0.7rem; letter-spacing: 0.5px;">Avg Views</small>
                                    <strong class="d-block" style="font-size: 1.25rem;"><?php echo number_format($avg_views_per_property); ?></strong>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="market-highlight-item">
                                    <small class="text-muted d-block mb-2 text-uppercase" style="font-size: 0.7rem; letter-spacing: 0.5px;">Tour Success</small>
                                    <strong class="d-block text-primary" style="font-size: 1.25rem;"><?php echo $tour_success_rate; ?>%</strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pending Approvals & Activity -->
        <div class="row g-4 mb-4">
            <!-- Pending Approvals -->
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-clipboard-check me-2"></i>Pending Approvals</span>
                        <span class="badge bg-danger"><?php echo $pending_approvals_total; ?></span>
                    </div>
                    <div class="card-body scrollable-content">
                        <?php if(!empty($pending_sales_list)): ?>
                            <h6 class="mb-3"><i class="bi bi-currency-dollar text-warning me-2"></i>Sales Verifications</h6>
                            <div class="table-responsive mb-4">
                                <table class="table table-sm data-table">
                                    <thead>
                                        <tr>
                                            <th>Property</th>
                                            <th>Sale Price</th>
                                            <th>Submitted</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($pending_sales_list as $sale): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($sale['StreetAddress']); ?></td>
                                            <td><?php echo formatCurrency($sale['sale_price']); ?></td>
                                            <td><?php echo time_elapsed_string($sale['submitted_at']); ?></td>
                                            <td><a href="view_sale_verification.php?id=<?php echo $sale['verification_id']; ?>" class="btn btn-sm btn-primary">Review</a></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>

                        <?php if(!empty($pending_agents_list)): ?>
                            <h6 class="mb-3"><i class="bi bi-person-badge text-success me-2"></i>Agent Applications</h6>
                            <div class="table-responsive">
                                <table class="table table-sm data-table">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Email</th>
                                            <th>Registered</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($pending_agents_list as $agent): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($agent['first_name'] . ' ' . $agent['last_name']); ?></td>
                                            <td><?php echo htmlspecialchars($agent['email']); ?></td>
                                            <td><?php echo isset($agent['date_registered']) ? date('M d, Y', strtotime($agent['date_registered'])) : 'N/A'; ?></td>
                                            <td><a href="review_agent_details.php?account_id=<?php echo $agent['account_id']; ?>" class="btn btn-sm btn-success">Review</a></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>

                        <?php if(empty($pending_sales_list) && empty($pending_agents_list)): ?>
                            <div class="empty-state">
                                <i class="bi bi-check-circle"></i>
                                <p class="mb-0">No pending approvals</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-activity me-2"></i>Recent Activity
                    </div>
                    <div class="card-body scrollable-content p-0">
                        <?php if(!empty($recent_activity)): ?>
                            <?php foreach($recent_activity as $activity): 
                                $is_approved = $activity['action'] === 'approved';
                                $icon_class = $activity['type'] == 'property_status' ? 'bi-house-door-fill' : 'bi-person-badge-fill';
                                $bg_class = $is_approved ? 'bg-success' : 'bg-danger';
                            ?>
                            <div class="activity-item">
                                <div class="d-flex">
                                    <div class="activity-icon <?php echo $bg_class; ?> bg-opacity-10 text-<?php echo $is_approved ? 'success' : 'danger'; ?> me-3">
                                        <i class="bi <?php echo $icon_class; ?>"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="mb-1">
                                            <span class="badge badge-status <?php echo $is_approved ? 'badge-approved' : 'badge-pending'; ?>">
                                                <?php echo ucfirst($activity['action']); ?>
                                            </span>
                                        </div>
                                        <div class="fw-medium"><?php echo htmlspecialchars($activity['subject']); ?></div>
                                        <div class="activity-time"><?php echo time_elapsed_string($activity['timestamp']); ?></div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="bi bi-clock-history"></i>
                                <p class="mb-0">No recent activity</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Property Types & Tours -->
        <div class="row g-4 mb-4">
            <!-- Property Type Distribution -->
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-pie-chart-fill me-2"></i>Property Type Distribution
                    </div>
                    <div class="card-body">
                        <?php if(!empty($property_types)): ?>
                            <div class="table-responsive">
                                <table class="table data-table">
                                    <thead>
                                        <tr>
                                            <th>Type</th>
                                            <th>Count</th>
                                            <th>Share</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($property_types as $type): 
                                            $percentage = $approved_properties > 0 ? ($type['count'] / $approved_properties) * 100 : 0;
                                        ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($type['PropertyType']); ?></strong></td>
                                            <td><?php echo number_format($type['count']); ?></td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="progress flex-grow-1 me-2" style="height: 8px;">
                                                        <div class="progress-bar" style="width: <?php echo $percentage; ?>%"></div>
                                                    </div>
                                                    <small class="text-muted"><?php echo number_format($percentage, 1); ?>%</small>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="bi bi-pie-chart"></i>
                                <p class="mb-0">No property data available</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Tour Requests -->
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-calendar-event me-2"></i>Pending Tours</span>
                        <span class="badge bg-warning text-dark"><?php echo $pending_tours; ?></span>
                    </div>
                    <div class="card-body">
                        <?php if(!empty($recent_tours)): ?>
                            <div class="table-responsive">
                                <table class="table table-sm data-table">
                                    <thead>
                                        <tr>
                                            <th>Client</th>
                                            <th>Property</th>
                                            <th>Date</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($recent_tours as $tour): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($tour['user_name']); ?></td>
                                            <td><?php echo htmlspecialchars($tour['StreetAddress']); ?></td>
                                            <td><?php echo date('M d', strtotime($tour['tour_date'])); ?></td>
                                            <td><a href="tour_requests.php?id=<?php echo $tour['tour_id']; ?>" class="btn btn-sm btn-outline-primary">View</a></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="bi bi-calendar-check"></i>
                                <p class="mb-0">No pending tour requests</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Performing Agents -->
        <div class="row g-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-trophy-fill me-2"></i>Top Performing Agents
                    </div>
                    <div class="card-body">
                        <?php if(!empty($top_agents)): ?>
                            <div class="table-responsive">
                                <table class="table data-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 5%;">Rank</th>
                                            <th style="width: 30%;">Agent Name</th>
                                            <th style="width: 20%;">Contact</th>
                                            <th style="width: 15%;">Properties Sold</th>
                                            <th style="width: 30%;">Performance</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $rank = 1;
                                        $max_sales = $top_agents[0]['property_count'] > 0 ? $top_agents[0]['property_count'] : 1;
                                        foreach($top_agents as $agent): 
                                            $performance = ($agent['property_count'] / $max_sales) * 100;
                                        ?>
                                        <tr>
                                            <td>
                                                <?php if($rank === 1): ?>
                                                    <i class="bi bi-trophy-fill text-warning"></i>
                                                <?php endif; ?>
                                                <strong><?php echo $rank++; ?></strong>
                                            </td>
                                            <td><strong><?php echo htmlspecialchars($agent['first_name'] . ' ' . $agent['last_name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($agent['phone_number']); ?></td>
                                            <td>
                                                <span class="badge badge-status badge-completed">
                                                    <?php echo number_format($agent['property_count']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="progress flex-grow-1 me-2">
                                                        <div class="progress-bar" style="width: <?php echo $performance; ?>%"></div>
                                                    </div>
                                                    <small class="text-muted"><?php echo number_format($performance); ?>%</small>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="bi bi-people"></i>
                                <p class="mb-0">No agent performance data available</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$profile_completed) include 'admin_profile_modal.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Chart.js initialization
        const ctx = document.getElementById('listingsChart');
        const chartLabels = <?php echo json_encode($chart_labels); ?>;
        const chartValues = <?php echo json_encode($chart_values); ?>;

        if (ctx && chartLabels.length > 0) {
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: chartLabels,
                    datasets: [{
                        label: 'New Listings',
                        data: chartValues,
                        backgroundColor: 'rgba(188, 158, 66, 0.1)',
                        borderColor: 'rgba(188, 158, 66, 1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: 'rgba(188, 158, 66, 1)',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(22, 18, 9, 0.9)',
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            borderColor: 'rgba(188, 158, 66, 1)',
                            borderWidth: 1,
                            cornerRadius: 6,
                            padding: 12,
                            callbacks: {
                                label: function(context) {
                                    return `${context.parsed.y} listing${context.parsed.y !== 1 ? 's' : ''}`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                color: '#6c757d',
                                font: { size: 11 }
                            },
                            grid: {
                                color: 'rgba(0,0,0,0.05)',
                                drawBorder: false
                            }
                        },
                        x: {
                            ticks: {
                                color: '#6c757d',
                                font: { size: 11 }
                            },
                            grid: { display: false }
                        }
                    }
                }
            });
        }

        // Profile modal
        <?php if (!$profile_completed): ?>
        var adminProfileModal = new bootstrap.Modal(document.getElementById('adminProfileModal'), {
            backdrop: 'static',
            keyboard: false
        });
        adminProfileModal.show();
        <?php endif; ?>

        // Notification for pending items
        const pendingCount = <?php echo $pending_approvals_total; ?>;
        if (pendingCount > 0) {
            setTimeout(() => {
                showNotification(`You have ${pendingCount} item${pendingCount !== 1 ? 's' : ''} pending approval`, 'warning');
            }, 500);
        }
    });

    // Notification system
    function showNotification(message, type = 'info') {
        const colors = {
            warning: { bg: '#fff3cd', border: '#ffc107', text: '#856404' },
            success: { bg: '#d1e7dd', border: '#198754', text: '#0f5132' },
            info: { bg: '#cfe2ff', border: '#0d6efd', text: '#084298' }
        };
        const color = colors[type] || colors.info;
        
        const notification = document.createElement('div');
        notification.className = 'position-fixed';
        notification.style.cssText = `
            top: 20px;
            right: 20px;
            z-index: 1060;
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
                <i class="bi bi-info-circle me-2"></i>
                <div class="flex-grow-1">${message}</div>
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
</body>
</html>