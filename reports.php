<?php
session_start();
require_once 'connection.php';

// Admin access check
$is_admin = false;
if (isset($_SESSION['account_id'])) {
    if (isset($_SESSION['role_id']) && intval($_SESSION['role_id']) === 1) {
        $is_admin = true;
    }
    if (isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin') {
        $is_admin = true;
    }
}
if (!$is_admin) {
    header('Location: login.php');
    exit();
}

// Date range filter (default: last 30 days)
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

// === KEY METRICS ===
// Total Properties
$total_properties = $conn->query("SELECT COUNT(*) as c FROM property")->fetch_assoc()['c'] ?? 0;
$active_properties = $conn->query("SELECT COUNT(*) as c FROM property WHERE Status IN ('For Sale','For Rent') AND approval_status = 'approved'")->fetch_assoc()['c'] ?? 0;
$sold_properties = $conn->query("SELECT COUNT(*) as c FROM property WHERE Status = 'Sold'")->fetch_assoc()['c'] ?? 0;

// Total Agents
$total_agents = $conn->query("SELECT COUNT(*) as c FROM accounts WHERE role_id = 2")->fetch_assoc()['c'] ?? 0;
$approved_agents = $conn->query("SELECT COUNT(*) as c FROM agent_information WHERE is_approved = 1")->fetch_assoc()['c'] ?? 0;
$pending_agents = $conn->query("SELECT COUNT(*) as c FROM agent_information WHERE is_approved = 0")->fetch_assoc()['c'] ?? 0;

// Sales & Revenue
$sales_data = $conn->query("SELECT COUNT(*) as total_sales, SUM(final_sale_price) as total_revenue FROM finalized_sales")->fetch_assoc();
$total_sales = $sales_data['total_sales'] ?? 0;
$total_revenue = $sales_data['total_revenue'] ?? 0;
// Get commission from agent_commissions table
$commission_data = $conn->query("SELECT SUM(commission_amount) as total_commission FROM agent_commissions")->fetch_assoc();
$total_commission = $commission_data['total_commission'] ?? 0;

// Tour Requests
$total_tours = $conn->query("SELECT COUNT(*) as c FROM tour_requests")->fetch_assoc()['c'] ?? 0;
$pending_tours = $conn->query("SELECT COUNT(*) as c FROM tour_requests WHERE request_status = 'Pending'")->fetch_assoc()['c'] ?? 0;
$completed_tours = $conn->query("SELECT COUNT(*) as c FROM tour_requests WHERE request_status = 'Completed'")->fetch_assoc()['c'] ?? 0;

// Property Views & Likes
// Align with schema: columns are ViewsCount and Likes on property table
$total_views = $conn->query("SELECT COALESCE(SUM(ViewsCount), 0) as c FROM property")->fetch_assoc()['c'] ?? 0;
$total_likes = $conn->query("SELECT COALESCE(SUM(Likes), 0) as c FROM property")->fetch_assoc()['c'] ?? 0;

// === REVENUE TREND (Last 12 months) ===
$revenue_trend = [];
for ($i = 11; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $result = $conn->query("SELECT COALESCE(SUM(final_sale_price), 0) as revenue FROM finalized_sales WHERE DATE_FORMAT(sale_date, '%Y-%m') = '$month'")->fetch_assoc();
    $revenue_trend[] = [
        'month' => date('M Y', strtotime($month)),
        'revenue' => floatval($result['revenue'] ?? 0)
    ];
}

// === PROPERTY STATISTICS BY TYPE ===
$property_by_type = [];
// Align with schema: PropertyType column. Alias as Type for UI/JS compatibility
$type_result = $conn->query("SELECT PropertyType AS Type, COUNT(*) as count FROM property GROUP BY PropertyType ORDER BY count DESC");
while ($row = $type_result->fetch_assoc()) {
    $property_by_type[] = $row;
}

// === PROPERTY STATISTICS BY STATUS ===
$property_by_status = [];
$status_result = $conn->query("SELECT Status AS status, COUNT(*) as count FROM property GROUP BY Status");
while ($row = $status_result->fetch_assoc()) {
    $property_by_status[] = $row;
}

// === TOP PERFORMING AGENTS ===
$top_agents = [];
$agent_query = "SELECT 
    a.account_id, 
    a.first_name, 
    a.last_name,
    COUNT(DISTINCT fs.sale_id) as total_sales,
    COALESCE(SUM(ac.commission_amount), 0) as total_commission,
    COUNT(DISTINCT p.property_ID) as active_listings
FROM accounts a
LEFT JOIN agent_information ai ON a.account_id = ai.account_id
LEFT JOIN finalized_sales fs ON a.account_id = fs.agent_id
LEFT JOIN agent_commissions ac ON fs.sale_id = ac.sale_id
LEFT JOIN property_log pl ON pl.account_id = a.account_id AND pl.action = 'CREATED'
LEFT JOIN property p ON p.property_ID = pl.property_id AND p.Status IN ('For Sale','For Rent') AND p.approval_status = 'approved'
WHERE a.role_id = 2 AND ai.is_approved = 1
GROUP BY a.account_id
ORDER BY total_sales DESC, total_commission DESC
LIMIT 10";
$agent_result = $conn->query($agent_query);
while ($row = $agent_result->fetch_assoc()) {
    $top_agents[] = $row;
}

// === RECENT SALES ===
$recent_sales = [];
$sales_query = "SELECT 
    fs.sale_id,
    fs.property_id,
    fs.buyer_name,
    fs.final_sale_price,
    fs.sale_date,
    ac.commission_amount,
    p.StreetAddress,
    CONCAT(a.first_name, ' ', a.last_name) as agent_name
FROM finalized_sales fs
JOIN property p ON fs.property_id = p.property_ID
JOIN accounts a ON fs.agent_id = a.account_id
LEFT JOIN agent_commissions ac ON fs.sale_id = ac.sale_id
ORDER BY fs.sale_date DESC
LIMIT 10";
$sales_result = $conn->query($sales_query);
while ($row = $sales_result->fetch_assoc()) {
    $recent_sales[] = $row;
}

// === AVERAGE PRICE BY PROPERTY TYPE ===
$avg_price_by_type = [];
// Align with schema: PropertyType and ListingPrice columns. Alias as Type for UI/JS compatibility
$avg_query = "SELECT PropertyType AS Type, AVG(ListingPrice) as avg_price, COUNT(*) as count FROM property WHERE Status IN ('For Sale','For Rent') AND approval_status = 'approved' GROUP BY PropertyType ORDER BY avg_price DESC";
$avg_result = $conn->query($avg_query);
while ($row = $avg_result->fetch_assoc()) {
    $avg_price_by_type[] = $row;
}

// === MONTHLY COMPARISON (current vs previous month) ===
$current_month_sales = $conn->query("SELECT COUNT(*) as count, COALESCE(SUM(final_sale_price), 0) as revenue FROM finalized_sales WHERE MONTH(sale_date) = MONTH(CURDATE()) AND YEAR(sale_date) = YEAR(CURDATE())")->fetch_assoc();
$previous_month_sales = $conn->query("SELECT COUNT(*) as count, COALESCE(SUM(final_sale_price), 0) as revenue FROM finalized_sales WHERE MONTH(sale_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(sale_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))")->fetch_assoc();

$sales_growth = 0;
$revenue_growth = 0;
if ($previous_month_sales['count'] > 0) {
    $sales_growth = (($current_month_sales['count'] - $previous_month_sales['count']) / $previous_month_sales['count']) * 100;
}
if ($previous_month_sales['revenue'] > 0) {
    $revenue_growth = (($current_month_sales['revenue'] - $previous_month_sales['revenue']) / $previous_month_sales['revenue']) * 100;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reports & Analytics - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --primary-color: #161209;
            --secondary-color: #bc9e42;
            --accent-color: #a08636;
            --bg-light: #f8f9fa;
            --border-color: #e0e0e0;
            --text-muted: #6c757d;
            --success-color: #198754;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --info-color: #0dcaf0;
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

        /* Dashboard Header */
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

        .dashboard-header .btn-export {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
            padding: 0.6rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }

        .dashboard-header .btn-export:hover {
            background: rgba(255, 255, 255, 0.3);
            border-color: rgba(255, 255, 255, 0.5);
            transform: translateY(-2px);
        }

        /* Filter Bar */
        .filter-bar {
            background: #fff;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .filter-label {
            font-weight: 600;
            color: var(--primary-color);
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .date-input {
            padding: 0.6rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.9rem;
            transition: border-color 0.2s ease;
        }

        .date-input:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(188, 158, 66, 0.1);
        }

        .btn-filter {
            background: var(--secondary-color);
            color: white;
            border: none;
            padding: 0.6rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-filter:hover {
            background: var(--accent-color);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(188, 158, 66, 0.3);
        }

        .btn-filter.btn-reset {
            background: var(--text-muted);
        }

        .btn-filter.btn-reset:hover {
            background: #5a6268;
        }

        /* Stats Cards */
        .stat-card {
            background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            position: relative;
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            min-height: 140px;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.1);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
        }

        .stat-card.revenue::before { background: var(--secondary-color); }
        .stat-card.properties::before { background: var(--info-color); }
        .stat-card.agents::before { background: var(--success-color); }
        .stat-card.sales::before { background: var(--primary-color); }
        .stat-card.tours::before { background: var(--warning-color); }

        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }

        .stat-card.revenue .stat-icon {
            background: rgba(188, 158, 66, 0.1);
            color: var(--secondary-color);
        }

        .stat-card.properties .stat-icon {
            background: rgba(13, 202, 240, 0.1);
            color: var(--info-color);
        }

        .stat-card.agents .stat-icon {
            background: rgba(25, 135, 84, 0.1);
            color: var(--success-color);
        }

        .stat-card.sales .stat-icon {
            background: rgba(22, 18, 9, 0.1);
            color: var(--primary-color);
        }

        .stat-card.tours .stat-icon {
            background: rgba(255, 193, 7, 0.1);
            color: var(--warning-color);
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--primary-color);
            line-height: 1.2;
            margin-bottom: 0.5rem;
        }

        .stat-change {
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .stat-change.positive {
            color: var(--success-color);
        }

        .stat-change.negative {
            color: var(--danger-color);
        }

        .stat-meta {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 0.5rem;
        }

        /* Chart Cards */
        .chart-card {
            background: #fff;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
            transition: box-shadow 0.2s ease;
        }

        .chart-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .chart-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary-color);
            margin: 0;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .chart-subtitle {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin: 0;
        }

        .chart-container {
            position: relative;
            height: 350px;
        }

        /* Data Table */
        .data-table {
            background: #fff;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .table-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary-color);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .table {
            margin: 0;
            font-size: 0.9rem;
        }

        .table thead th {
            background: var(--bg-light);
            color: var(--primary-color);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.5px;
            border-bottom: 2px solid var(--border-color);
            padding: 1rem;
        }

        .table tbody td {
            padding: 1rem;
            vertical-align: middle;
            border-bottom: 1px solid var(--border-color);
        }

        .table tbody tr:last-child td {
            border-bottom: none;
        }

        .table tbody tr:hover {
            background: rgba(188, 158, 66, 0.05);
        }

        .badge {
            padding: 0.4rem 0.75rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-success {
            background: rgba(25, 135, 84, 0.1);
            color: var(--success-color);
        }

        .badge-warning {
            background: rgba(255, 193, 7, 0.1);
            color: #c98600;
        }

        .badge-info {
            background: rgba(13, 202, 240, 0.1);
            color: var(--info-color);
        }

        .badge-secondary {
            background: rgba(188, 158, 66, 0.1);
            color: var(--secondary-color);
        }

        /* Grid Layout */
        .reports-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .col-span-6 {
            grid-column: span 6;
        }

        .col-span-4 {
            grid-column: span 4;
        }

        .col-span-8 {
            grid-column: span 8;
        }

        .col-span-12 {
            grid-column: span 12;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .col-span-6, .col-span-4, .col-span-8 {
                grid-column: span 12;
            }
        }

        @media (max-width: 768px) {
            .filter-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .date-input, .btn-filter {
                width: 100%;
            }
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 3rem;
            opacity: 0.3;
            margin-bottom: 1rem;
        }

        /* Print Styles */
        @media print {
            .admin-content {
                margin-left: 0;
                margin-top: 0;
            }
            .filter-bar, .btn-export {
                display: none;
            }
        }
    </style>
</head>
<body>
    <?php include 'admin_sidebar.php'; ?>
    <?php include 'admin_navbar.php'; ?>

    <div class="admin-content">
        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h1><i class="bi bi-graph-up-arrow me-2"></i>Reports & Analytics</h1>
                    <p>Comprehensive business intelligence and performance metrics</p>
                </div>
                <button class="btn-export" onclick="window.print()">
                    <i class="bi bi-printer me-2"></i>Export Report
                </button>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <span class="filter-label"><i class="bi bi-calendar3 me-2"></i>Date Range:</span>
            <input type="date" class="date-input" id="date_from" value="<?php echo $date_from; ?>">
            <span class="text-muted">to</span>
            <input type="date" class="date-input" id="date_to" value="<?php echo $date_to; ?>">
            <button class="btn-filter" onclick="applyFilter()">
                <i class="bi bi-funnel me-2"></i>Apply Filter
            </button>
            <button class="btn-filter btn-reset" onclick="resetFilter()">
                <i class="bi bi-arrow-clockwise me-2"></i>Reset
            </button>
        </div>

        <!-- Key Metrics Stats -->
        <div class="row g-4 mb-4">
            <div class="col-xl-4 col-lg-6">
                <div class="stat-card revenue">
                    <div class="stat-icon">
                        <i class="bi bi-currency-dollar"></i>
                    </div>
                    <div class="stat-label">Total Revenue</div>
                    <div class="stat-value">₱<?php echo number_format($total_revenue, 2); ?></div>
                    <?php if ($revenue_growth != 0): ?>
                    <div class="stat-change <?php echo $revenue_growth > 0 ? 'positive' : 'negative'; ?>">
                        <i class="bi bi-arrow-<?php echo $revenue_growth > 0 ? 'up' : 'down'; ?>"></i>
                        <?php echo abs(round($revenue_growth, 1)); ?>% vs last month
                    </div>
                    <?php endif; ?>
                    <div class="stat-meta">From <?php echo $total_sales; ?> completed sales</div>
                </div>
            </div>

            <div class="col-xl-4 col-lg-6">
                <div class="stat-card properties">
                    <div class="stat-icon">
                        <i class="bi bi-buildings"></i>
                    </div>
                    <div class="stat-label">Total Properties</div>
                    <div class="stat-value"><?php echo $total_properties; ?></div>
                    <div class="stat-meta">
                        <?php echo $active_properties; ?> active • <?php echo $sold_properties; ?> sold
                    </div>
                </div>
            </div>

            <div class="col-xl-4 col-lg-6">
                <div class="stat-card agents">
                    <div class="stat-icon">
                        <i class="bi bi-person-badge"></i>
                    </div>
                    <div class="stat-label">Total Agents</div>
                    <div class="stat-value"><?php echo $total_agents; ?></div>
                    <div class="stat-meta">
                        <?php echo $approved_agents; ?> approved • <?php echo $pending_agents; ?> pending
                    </div>
                </div>
            </div>

            <div class="col-xl-4 col-lg-6">
                <div class="stat-card sales">
                    <div class="stat-icon">
                        <i class="bi bi-handshake"></i>
                    </div>
                    <div class="stat-label">Completed Sales</div>
                    <div class="stat-value"><?php echo $total_sales; ?></div>
                    <?php if ($sales_growth != 0): ?>
                    <div class="stat-change <?php echo $sales_growth > 0 ? 'positive' : 'negative'; ?>">
                        <i class="bi bi-arrow-<?php echo $sales_growth > 0 ? 'up' : 'down'; ?>"></i>
                        <?php echo abs(round($sales_growth, 1)); ?>% vs last month
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-xl-4 col-lg-6">
                <div class="stat-card tours">
                    <div class="stat-icon">
                        <i class="bi bi-calendar-check"></i>
                    </div>
                    <div class="stat-label">Tour Requests</div>
                    <div class="stat-value"><?php echo $total_tours; ?></div>
                    <div class="stat-meta">
                        <?php echo $pending_tours; ?> pending • <?php echo $completed_tours; ?> completed
                    </div>
                </div>
            </div>

            <div class="col-xl-4 col-lg-6">
                <div class="stat-card properties">
                    <div class="stat-icon">
                        <i class="bi bi-eye"></i>
                    </div>
                    <div class="stat-label">Property Views</div>
                    <div class="stat-value"><?php echo number_format($total_views); ?></div>
                    <div class="stat-meta">
                        <?php echo number_format($total_likes); ?> likes
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="reports-grid">
            <!-- Revenue Trend Chart -->
            <div class="col-span-8">
                <div class="chart-card">
                    <div class="chart-header">
                        <div>
                            <h3 class="chart-title"><i class="bi bi-graph-up me-2"></i>Monthly Revenue Trend</h3>
                            <p class="chart-subtitle">Total revenue for the past 12 months</p>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="revenueChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Property Status Distribution -->
            <div class="col-span-4">
                <div class="chart-card">
                    <div class="chart-header">
                        <div>
                            <h3 class="chart-title"><i class="bi bi-pie-chart me-2"></i>Property Distribution</h3>
                            <p class="chart-subtitle">By status</p>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Property Type Distribution -->
            <div class="col-span-6">
                <div class="chart-card">
                    <div class="chart-header">
                        <div>
                            <h3 class="chart-title"><i class="bi bi-bar-chart me-2"></i>Properties by Type</h3>
                            <p class="chart-subtitle">Distribution across property categories</p>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="typeChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Average Price by Type -->
            <div class="col-span-6">
                <div class="chart-card">
                    <div class="chart-header">
                        <div>
                            <h3 class="chart-title"><i class="bi bi-cash-stack me-2"></i>Average Price by Type</h3>
                            <p class="chart-subtitle">Market pricing analysis</p>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="avgPriceChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Performing Agents Table -->
        <div class="data-table">
            <div class="table-header">
                <h3 class="table-title"><i class="bi bi-trophy me-2"></i>Top Performing Agents</h3>
            </div>
            <?php if (empty($top_agents)): ?>
                <div class="empty-state">
                    <i class="bi bi-person-badge"></i>
                    <p>No agent performance data available yet.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Agent Name</th>
                                <th>Total Sales</th>
                                <th>Total Commission</th>
                                <th>Active Listings</th>
                                <th>Performance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $rank = 1;
                            foreach ($top_agents as $agent): 
                            ?>
                                <tr>
                                    <td><strong>#<?php echo $rank++; ?></strong></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($agent['first_name'] . ' ' . $agent['last_name']); ?></strong>
                                    </td>
                                    <td><?php echo intval($agent['total_sales']); ?></td>
                                    <td>₱<?php echo number_format($agent['total_commission'], 2); ?></td>
                                    <td><?php echo intval($agent['active_listings']); ?></td>
                                    <td>
                                        <?php if ($agent['total_sales'] >= 3): ?>
                                            <span class="badge badge-success">Excellent</span>
                                        <?php elseif ($agent['total_sales'] >= 1): ?>
                                            <span class="badge badge-info">Good</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">Building</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Recent Sales Table -->
        <div class="data-table">
            <div class="table-header">
                <h3 class="table-title"><i class="bi bi-receipt me-2"></i>Recent Sales</h3>
            </div>
            <?php if (empty($recent_sales)): ?>
                <div class="empty-state">
                    <i class="bi bi-handshake"></i>
                    <p>No sales data available yet.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Sale ID</th>
                                <th>Property</th>
                                <th>Buyer</th>
                                <th>Agent</th>
                                <th>Sale Price</th>
                                <th>Commission</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_sales as $sale): ?>
                                <tr>
                                    <td><strong>#<?php echo $sale['sale_id']; ?></strong></td>
                                    <td><?php echo htmlspecialchars($sale['StreetAddress']); ?></td>
                                    <td><?php echo htmlspecialchars($sale['buyer_name']); ?></td>
                                    <td><?php echo htmlspecialchars($sale['agent_name']); ?></td>
                                    <td><strong>₱<?php echo number_format($sale['final_sale_price'], 2); ?></strong></td>
                                    <td>₱<?php echo number_format($sale['commission_amount'] ?? 0, 2); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($sale['sale_date'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Average Price by Type Table -->
        <div class="data-table">
            <div class="table-header">
                <h3 class="table-title"><i class="bi bi-tags me-2"></i>Market Analysis by Property Type</h3>
            </div>
            <?php if (empty($avg_price_by_type)): ?>
                <div class="empty-state">
                    <i class="bi bi-bar-chart"></i>
                    <p>No pricing data available.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Property Type</th>
                                <th>Average Price</th>
                                <th>Total Listings</th>
                                <th>Market Share</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_count = array_sum(array_column($avg_price_by_type, 'count'));
                            foreach ($avg_price_by_type as $type): 
                                $percentage = ($type['count'] / $total_count) * 100;
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($type['Type']); ?></strong></td>
                                    <td>₱<?php echo number_format($type['avg_price'], 2); ?></td>
                                    <td><?php echo intval($type['count']); ?></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="progress" style="width: 100px; height: 8px;">
                                                <div class="progress-bar" style="width: <?php echo $percentage; ?>%; background: var(--secondary-color);"></div>
                                            </div>
                                            <span><?php echo round($percentage, 1); ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Filter Functions
    function applyFilter() {
        const dateFrom = document.getElementById('date_from').value;
        const dateTo = document.getElementById('date_to').value;
        window.location.href = `?date_from=${dateFrom}&date_to=${dateTo}`;
    }

    function resetFilter() {
        window.location.href = 'reports.php';
    }

    // Chart.js Configuration
    const chartColors = {
        primary: '#161209',
        secondary: '#bc9e42',
        success: '#198754',
        info: '#0dcaf0',
        warning: '#ffc107',
        danger: '#dc3545',
        muted: '#6c757d'
    };

    // Revenue Trend Chart
    const revenueData = <?php echo json_encode($revenue_trend); ?>;
    const revenueCtx = document.getElementById('revenueChart');
    if (revenueCtx) {
        new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: revenueData.map(d => d.month),
                datasets: [{
                    label: 'Revenue',
                    data: revenueData.map(d => d.revenue),
                    borderColor: chartColors.secondary,
                    backgroundColor: chartColors.secondary + '20',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 5,
                    pointHoverRadius: 7,
                    pointBackgroundColor: chartColors.secondary,
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: chartColors.primary,
                        padding: 12,
                        titleFont: { size: 14, weight: 'bold' },
                        bodyFont: { size: 13 },
                        callbacks: {
                            label: function(context) {
                                return 'Revenue: ₱' + context.parsed.y.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₱' + (value / 1000000).toFixed(1) + 'M';
                            }
                        },
                        grid: {
                            color: '#e9ecef'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }

    // Property Status Chart
    const statusData = <?php echo json_encode($property_by_status); ?>;
    const statusCtx = document.getElementById('statusChart');
    if (statusCtx && statusData.length > 0) {
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: statusData.map(d => d.status.charAt(0).toUpperCase() + d.status.slice(1)),
                datasets: [{
                    data: statusData.map(d => d.count),
                    backgroundColor: [
                        chartColors.success,
                        chartColors.info,
                        chartColors.warning,
                        chartColors.muted
                    ],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            font: { size: 12 }
                        }
                    },
                    tooltip: {
                        backgroundColor: chartColors.primary,
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((context.parsed / total) * 100).toFixed(1);
                                return context.label + ': ' + context.parsed + ' (' + percentage + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    // Property Type Chart
    const typeData = <?php echo json_encode($property_by_type); ?>;
    const typeCtx = document.getElementById('typeChart');
    if (typeCtx && typeData.length > 0) {
        new Chart(typeCtx, {
            type: 'bar',
            data: {
                labels: typeData.map(d => d.Type),
                datasets: [{
                    label: 'Properties',
                    data: typeData.map(d => d.count),
                    backgroundColor: chartColors.secondary,
                    borderRadius: 6,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: chartColors.primary,
                        padding: 12
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        },
                        grid: {
                            color: '#e9ecef'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }

    // Average Price Chart
    const avgPriceData = <?php echo json_encode($avg_price_by_type); ?>;
    const avgPriceCtx = document.getElementById('avgPriceChart');
    if (avgPriceCtx && avgPriceData.length > 0) {
        new Chart(avgPriceCtx, {
            type: 'bar',
            data: {
                labels: avgPriceData.map(d => d.Type),
                datasets: [{
                    label: 'Average Price',
                    data: avgPriceData.map(d => parseFloat(d.avg_price)),
                    backgroundColor: chartColors.info,
                    borderRadius: 6,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: chartColors.primary,
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                return 'Avg Price: ₱' + context.parsed.x.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₱' + (value / 1000000).toFixed(1) + 'M';
                            }
                        },
                        grid: {
                            color: '#e9ecef'
                        }
                    },
                    y: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }

    // Print functionality
    window.addEventListener('beforeprint', function() {
        document.querySelectorAll('canvas').forEach(canvas => {
            const img = document.createElement('img');
            img.src = canvas.toDataURL();
            img.style.maxWidth = '100%';
            canvas.parentNode.insertBefore(img, canvas);
            canvas.style.display = 'none';
        });
    });

    window.addEventListener('afterprint', function() {
        document.querySelectorAll('canvas').forEach(canvas => {
            canvas.style.display = 'block';
            const img = canvas.previousElementSibling;
            if (img && img.tagName === 'IMG') {
                img.remove();
            }
        });
    });
</script>
</body>
</html>
<?php $conn->close(); ?>
