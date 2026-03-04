<?php
session_start();
include 'connection.php';
include 'admin_profile_check.php';
require_once __DIR__ . '/config/session_timeout.php';
require_once __DIR__ . '/config/paths.php';

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

// =====================================================
// DASHBOARD QUERIES
// =====================================================

// --- Property Counts ---
$total_properties = $conn->query("SELECT COUNT(*) as count FROM property")->fetch_assoc()['count'];
$approved_properties = $conn->query("SELECT COUNT(*) as count FROM property WHERE approval_status = 'approved' AND Status NOT IN ('Sold','Pending Sold')")->fetch_assoc()['count'];
$pending_properties = $conn->query("SELECT COUNT(*) as count FROM property WHERE approval_status = 'pending'")->fetch_assoc()['count'];
$rejected_properties = $conn->query("SELECT COUNT(*) as count FROM property WHERE approval_status = 'rejected'")->fetch_assoc()['count'];
$sold_properties = $conn->query("SELECT COUNT(*) as count FROM property WHERE Status = 'Sold'")->fetch_assoc()['count'];
$pending_sold_properties = $conn->query("SELECT COUNT(*) as count FROM property WHERE Status = 'Pending Sold'")->fetch_assoc()['count'];
$for_rent_properties = $conn->query("SELECT COUNT(*) as count FROM property WHERE Status = 'For Rent' AND approval_status = 'approved'")->fetch_assoc()['count'];
$for_sale_properties = $conn->query("SELECT COUNT(*) as count FROM property WHERE Status = 'For Sale' AND approval_status = 'approved'")->fetch_assoc()['count'];

// --- Agent Counts ---
$total_agents = $conn->query("SELECT COUNT(*) as count FROM agent_information")->fetch_assoc()['count'];
$approved_agents = $conn->query("SELECT COUNT(*) as count FROM agent_information WHERE is_approved = 1")->fetch_assoc()['count'];
$pending_agents = $conn->query("
    SELECT COUNT(*) as count FROM agent_information ai
    WHERE ai.is_approved = 0
    AND NOT EXISTS (
        SELECT 1 FROM status_log sl
        WHERE sl.item_id = ai.account_id AND sl.item_type = 'agent' AND sl.action = 'rejected'
    )
")->fetch_assoc()['count'];

// --- Tour Counts (only for properties listed by this admin) ---
$admin_id = (int)$_SESSION['account_id'];
$total_tours = $conn->query("SELECT COUNT(*) as count FROM tour_requests tr INNER JOIN property_log pl ON tr.property_id = pl.property_id AND pl.action = 'CREATED' AND pl.account_id = $admin_id")->fetch_assoc()['count'];
$pending_tours = $conn->query("SELECT COUNT(*) as count FROM tour_requests tr INNER JOIN property_log pl ON tr.property_id = pl.property_id AND pl.action = 'CREATED' AND pl.account_id = $admin_id WHERE tr.request_status = 'Pending'")->fetch_assoc()['count'];
$confirmed_tours = $conn->query("SELECT COUNT(*) as count FROM tour_requests tr INNER JOIN property_log pl ON tr.property_id = pl.property_id AND pl.action = 'CREATED' AND pl.account_id = $admin_id WHERE tr.request_status = 'Confirmed'")->fetch_assoc()['count'];
$completed_tours = $conn->query("SELECT COUNT(*) as count FROM tour_requests tr INNER JOIN property_log pl ON tr.property_id = pl.property_id AND pl.action = 'CREATED' AND pl.account_id = $admin_id WHERE tr.request_status = 'Completed'")->fetch_assoc()['count'];
$cancelled_tours = $conn->query("SELECT COUNT(*) as count FROM tour_requests tr INNER JOIN property_log pl ON tr.property_id = pl.property_id AND pl.action = 'CREATED' AND pl.account_id = $admin_id WHERE tr.request_status IN ('Cancelled','Rejected')")->fetch_assoc()['count'];

// --- Financial Metrics ---
$total_property_value = $conn->query("SELECT SUM(ListingPrice) as total FROM property WHERE approval_status = 'approved' AND Status NOT IN ('Sold','Pending Sold')")->fetch_assoc()['total'] ?? 0;
$avg_property_value = $conn->query("SELECT AVG(ListingPrice) as avg FROM property WHERE approval_status = 'approved' AND ListingPrice > 0")->fetch_assoc()['avg'] ?? 0;
$total_sold_value = $conn->query("SELECT SUM(final_sale_price) as total FROM finalized_sales")->fetch_assoc()['total'] ?? 0;

// --- Property Highlights ---
$highest_priced_property = $conn->query("
    SELECT StreetAddress, City, ListingPrice FROM property
    WHERE approval_status = 'approved' AND Status NOT IN ('Sold','Pending Sold')
    ORDER BY ListingPrice DESC LIMIT 1
")->fetch_assoc();

$most_viewed_property = $conn->query("
    SELECT StreetAddress, City, ViewsCount FROM property
    WHERE approval_status = 'approved'
    ORDER BY ViewsCount DESC LIMIT 1
")->fetch_assoc();

// --- Views ---
$total_views = $conn->query("SELECT SUM(ViewsCount) as total FROM property")->fetch_assoc()['total'] ?? 0;
$total_likes = $conn->query("SELECT SUM(Likes) as total FROM property")->fetch_assoc()['total'] ?? 0;

// --- Pending Approvals ---
$pending_sales_list = $conn->query("
    SELECT sv.verification_id, sv.property_id, p.StreetAddress, p.City, sv.sale_price, sv.submitted_at,
           CONCAT(a.first_name, ' ', a.last_name) as agent_name
    FROM sale_verifications sv
    JOIN property p ON sv.property_id = p.property_ID
    JOIN accounts a ON sv.agent_id = a.account_id
    WHERE sv.status = 'Pending'
    ORDER BY sv.submitted_at DESC LIMIT 5
");
$pending_sales_list = $pending_sales_list ? $pending_sales_list->fetch_all(MYSQLI_ASSOC) : [];

$pending_agents_list = $conn->query("
    SELECT a.account_id, a.first_name, a.last_name, a.email, a.date_registered,
           ai.license_number
    FROM agent_information ai
    JOIN accounts a ON ai.account_id = a.account_id
    WHERE ai.is_approved = 0
    AND NOT EXISTS (
        SELECT 1 FROM status_log sl
        WHERE sl.item_id = ai.account_id AND sl.item_type = 'agent' AND sl.action = 'rejected'
    )
    ORDER BY a.date_registered DESC LIMIT 5
");
$pending_agents_list = $pending_agents_list ? $pending_agents_list->fetch_all(MYSQLI_ASSOC) : [];

$pending_properties_list = $conn->query("
    SELECT p.property_ID, p.StreetAddress, p.City, p.PropertyType, p.ListingPrice, p.ListingDate,
           CONCAT(a.first_name, ' ', a.last_name) as posted_by
    FROM property p
    LEFT JOIN property_log pl ON p.property_ID = pl.property_id AND pl.action = 'CREATED'
    LEFT JOIN accounts a ON pl.account_id = a.account_id
    WHERE p.approval_status = 'pending'
    ORDER BY p.ListingDate DESC LIMIT 5
");
$pending_properties_list = $pending_properties_list ? $pending_properties_list->fetch_all(MYSQLI_ASSOC) : [];

$pending_approvals_total = count($pending_sales_list) + count($pending_agents_list) + count($pending_properties_list);

// --- Recent Activity (from status_log + property_log) ---
$recent_activity_sql = "
    (
        SELECT 'agent' as type, CONCAT(a.first_name, ' ', a.last_name) as subject,
        sl.action, sl.log_timestamp as timestamp, sl.reason_message
        FROM status_log sl
        JOIN accounts a ON sl.item_id = a.account_id AND sl.item_type = 'agent'
        ORDER BY sl.log_timestamp DESC LIMIT 5
    )
    UNION ALL
    (
        SELECT 'property' as type, p.StreetAddress as subject,
        sl.action, sl.log_timestamp as timestamp, sl.reason_message
        FROM status_log sl
        JOIN property p ON sl.item_id = p.property_ID AND sl.item_type = 'property'
        ORDER BY sl.log_timestamp DESC LIMIT 5
    )
    UNION ALL
    (
        SELECT 'listing' as type, p.StreetAddress as subject,
        pl.action, pl.log_timestamp as timestamp, pl.reason_message
        FROM property_log pl
        JOIN property p ON pl.property_id = p.property_ID
        WHERE pl.action = 'CREATED'
        ORDER BY pl.log_timestamp DESC LIMIT 5
    )
    ORDER BY timestamp DESC LIMIT 8";
$recent_activity_result = $conn->query($recent_activity_sql);
$recent_activity = $recent_activity_result ? $recent_activity_result->fetch_all(MYSQLI_ASSOC) : [];

// --- Chart: Listings Trend (Last 30 Days) ---
$chart_result = $conn->query("
    SELECT DATE(ListingDate) as date, COUNT(*) as count
    FROM property
    WHERE ListingDate >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY DATE(ListingDate)
    ORDER BY date ASC
");
$chart_labels = [];
$chart_values = [];
if ($chart_result) {
    while ($row = $chart_result->fetch_assoc()) {
        $chart_labels[] = date('M d', strtotime($row['date']));
        $chart_values[] = (int)$row['count'];
    }
}

// --- Chart: Tour Requests Trend (Last 30 Days, admin-listed properties only) ---
$tour_chart_result = $conn->query("
    SELECT DATE(tr.requested_at) as date, COUNT(*) as count
    FROM tour_requests tr
    INNER JOIN property_log pl ON tr.property_id = pl.property_id AND pl.action = 'CREATED' AND pl.account_id = $admin_id
    WHERE tr.requested_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY DATE(tr.requested_at)
    ORDER BY date ASC
");
$tour_chart_labels = [];
$tour_chart_values = [];
if ($tour_chart_result) {
    while ($row = $tour_chart_result->fetch_assoc()) {
        $tour_chart_labels[] = date('M d', strtotime($row['date']));
        $tour_chart_values[] = (int)$row['count'];
    }
}

// --- Property Type Distribution ---
$property_types = $conn->query("
    SELECT PropertyType, COUNT(*) as count
    FROM property WHERE approval_status = 'approved'
    GROUP BY PropertyType ORDER BY count DESC
");
$property_types = $property_types ? $property_types->fetch_all(MYSQLI_ASSOC) : [];

// --- Property Status Distribution (for doughnut chart) ---
$status_for_sale = $for_sale_properties;
$status_for_rent = $for_rent_properties;
$status_sold = $sold_properties;
$status_pending_sold = $pending_sold_properties;

// --- Top Agents by Properties Sold ---
$top_agents = $conn->query("
    SELECT a.account_id, a.first_name, a.last_name, a.phone_number,
           ag.profile_picture_url,
           COUNT(p.property_ID) as sold_count,
           SUM(p.ListingPrice) as total_sales_value
    FROM accounts a
    JOIN agent_information ag ON a.account_id = ag.account_id
    LEFT JOIN property p ON a.account_id = p.sold_by_agent AND p.Status = 'Sold'
    WHERE ag.is_approved = 1
    GROUP BY a.account_id
    ORDER BY sold_count DESC, total_sales_value DESC
    LIMIT 5
");
$top_agents = $top_agents ? $top_agents->fetch_all(MYSQLI_ASSOC) : [];

// --- Upcoming Tours (Confirmed / Pending, future dates) ---
$upcoming_tours = $conn->query("
    SELECT tr.tour_id, tr.request_status as status, tr.tour_date, tr.tour_time,
           tr.user_name, tr.user_email, tr.tour_type,
           p.StreetAddress, p.City,
           CONCAT(a.first_name, ' ', a.last_name) as agent_name
    FROM tour_requests tr
    JOIN property p ON tr.property_id = p.property_ID
    JOIN accounts a ON tr.agent_account_id = a.account_id
    INNER JOIN property_log pl ON tr.property_id = pl.property_id AND pl.action = 'CREATED' AND pl.account_id = $admin_id
    WHERE tr.request_status IN ('Pending','Confirmed')
    AND tr.tour_date >= CURDATE()
    ORDER BY tr.tour_date ASC, tr.tour_time ASC
    LIMIT 6
");
$upcoming_tours = $upcoming_tours ? $upcoming_tours->fetch_all(MYSQLI_ASSOC) : [];

// --- Unread Notifications Count ---
$unread_notifs = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE is_read = 0")->fetch_assoc()['count'] ?? 0;

// --- Utility Functions ---
function time_elapsed_string($datetime) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    if ($diff->y > 0) return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    if ($diff->m > 0) return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    if ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    if ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    if ($diff->i > 0) return $diff->i . ' min' . ($diff->i > 1 ? 's' : '') . ' ago';
    return 'just now';
}

function formatCurrency($amount) {
    if ($amount === null || $amount == 0) return '₱0.00';
    if ($amount >= 1000000) return '₱' . number_format($amount / 1000000, 2) . 'M';
    if ($amount >= 1000) return '₱' . number_format($amount / 1000, 1) . 'K';
    return '₱' . number_format($amount, 2);
}

function formatCurrencyFull($amount) {
    if ($amount === null || $amount == 0) return '₱0.00';
    return '₱' . number_format($amount, 2);
}

$tour_success_rate = $total_tours > 0 ? round(($completed_tours / $total_tours) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Real Estate System</title>
    <link href="<?= ASSETS_CSS ?>bootstrap.min.css" rel="stylesheet">
    <link href="<?= ASSETS_CSS ?>inter-font.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= ASSETS_CSS ?>fontawesome-all.min.css">
    <link rel="stylesheet" href="<?= ASSETS_CSS ?>bootstrap-icons.min.css">

    <style>
        /* ================================================
           ADMIN DASHBOARD PAGE
           Design system matches property.php exactly
           ================================================ */

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

        /* ===== PAGE-SPECIFIC VARIABLES ===== */
        .admin-content {
            --gold: #d4af37;
            --gold-light: #f4d03f;
            --gold-dark: #b8941f;
            --blue: #2563eb;
            --blue-light: #3b82f6;
            --blue-dark: #1e40af;
            --card-bg: #ffffff;
            --text-primary: #212529;
            --text-secondary: #6c757d;
        }

        /* ===== PAGE HEADER ===== */
        .page-header {
            background: var(--card-bg);
            border: 1px solid rgba(37, 99, 235, 0.1);
            border-radius: 4px;
            padding: 2rem 2.5rem;
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background:
                radial-gradient(ellipse at top right, rgba(37, 99, 235, 0.04) 0%, transparent 50%),
                radial-gradient(ellipse at bottom left, rgba(212, 175, 55, 0.03) 0%, transparent 50%);
            pointer-events: none;
        }

        .page-header::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent);
        }

        .page-header-inner {
            position: relative;
            z-index: 2;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1.5rem;
        }

        .page-header h1 {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--text-primary, #0f172a);
            margin-bottom: 0.25rem;
        }

        .page-header .subtitle {
            color: var(--text-secondary, #64748b);
            font-size: 0.95rem;
            margin-bottom: 0;
        }

        .header-meta {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .header-meta-item {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.8rem;
            color: var(--text-secondary);
            background: #f8fafc;
            padding: 0.35rem 0.75rem;
            border-radius: 2px;
            border: 1px solid #e2e8f0;
        }

        .header-meta-item i {
            color: var(--gold);
        }

        /* ===== KPI STAT CARDS ===== */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .kpi-card {
            background: var(--card-bg);
            border: 1px solid rgba(37, 99, 235, 0.1);
            border-radius: 4px;
            padding: 1.25rem;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--blue), transparent);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .kpi-card:hover {
            border-color: rgba(37, 99, 235, 0.25);
            box-shadow: 0 8px 32px rgba(37, 99, 235, 0.08);
            transform: translateY(-3px);
        }

        .kpi-card:hover::before { opacity: 1; }

        .kpi-card .kpi-icon {
            width: 40px;
            height: 40px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            margin-bottom: 0.75rem;
        }

        .kpi-icon.gold {
            background: linear-gradient(135deg, rgba(212, 175, 55, 0.08) 0%, rgba(212, 175, 55, 0.15) 100%);
            color: var(--gold-dark);
            border: 1px solid rgba(212, 175, 55, 0.2);
        }

        .kpi-icon.blue {
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.06) 0%, rgba(37, 99, 235, 0.12) 100%);
            color: var(--blue);
            border: 1px solid rgba(37, 99, 235, 0.15);
        }

        .kpi-icon.green {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.06) 0%, rgba(34, 197, 94, 0.12) 100%);
            color: #16a34a;
            border: 1px solid rgba(34, 197, 94, 0.15);
        }

        .kpi-icon.red {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.06) 0%, rgba(239, 68, 68, 0.12) 100%);
            color: #dc2626;
            border: 1px solid rgba(239, 68, 68, 0.15);
        }

        .kpi-icon.amber {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.06) 0%, rgba(245, 158, 11, 0.12) 100%);
            color: #d97706;
            border: 1px solid rgba(245, 158, 11, 0.15);
        }

        .kpi-icon.cyan {
            background: linear-gradient(135deg, rgba(6, 182, 212, 0.06) 0%, rgba(6, 182, 212, 0.12) 100%);
            color: #0891b2;
            border: 1px solid rgba(6, 182, 212, 0.15);
        }

        .kpi-card .kpi-label {
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-secondary, #64748b);
            margin-bottom: 0.25rem;
        }

        .kpi-card .kpi-value {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text-primary, #0f172a);
            line-height: 1.2;
        }

        .kpi-card .kpi-sub {
            font-size: 0.7rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
            font-weight: 500;
        }

        /* ===== DASHBOARD CARDS ===== */
        .dash-card {
            background: var(--card-bg);
            border: 1px solid rgba(37, 99, 235, 0.1);
            border-radius: 4px;
            overflow: hidden;
            position: relative;
            height: 100%;
            display: flex;
            flex-direction: column;
            transition: all 0.3s ease;
        }

        .dash-card:hover {
            box-shadow: 0 4px 16px rgba(37, 99, 235, 0.06);
        }

        .dash-card::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent);
        }

        .dash-card-header {
            padding: 1.15rem 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: linear-gradient(180deg, #fafbfc 0%, var(--card-bg) 100%);
        }

        .dash-card-title {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .dash-card-title i {
            color: var(--gold-dark);
            font-size: 1rem;
        }

        .dash-card-body {
            padding: 1.25rem 1.5rem;
            flex: 1;
        }

        .dash-card-action {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--blue);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            transition: color 0.2s;
        }

        .dash-card-action:hover {
            color: var(--blue-dark);
        }

        /* ===== QUICK ACTIONS ===== */
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .quick-action-item {
            background: var(--card-bg);
            border: 1px solid rgba(37, 99, 235, 0.1);
            border-radius: 4px;
            padding: 1.25rem;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .quick-action-item::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, var(--gold), var(--blue));
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .quick-action-item:hover {
            border-color: rgba(37, 99, 235, 0.25);
            box-shadow: 0 8px 32px rgba(37, 99, 235, 0.08);
            transform: translateY(-3px);
        }

        .quick-action-item:hover::before { opacity: 1; }

        .qa-icon {
            width: 48px;
            height: 48px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
            position: relative;
        }

        .qa-badge {
            position: absolute;
            top: -6px;
            right: -6px;
            min-width: 20px;
            height: 20px;
            border-radius: 10px;
            background: #dc2626;
            color: #fff;
            font-size: 0.65rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 5px;
            border: 2px solid var(--card-bg);
        }

        .qa-content {
            flex: 1;
            min-width: 0;
        }

        .qa-title {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.15rem;
        }

        .qa-desc {
            font-size: 0.7rem;
            color: var(--text-secondary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* ===== DATA TABLE ===== */
        .dash-table {
            width: 100%;
            font-size: 0.82rem;
        }

        .dash-table thead th {
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-secondary);
            padding: 0.6rem 0.75rem;
            border-bottom: 1px solid #e2e8f0;
            background: #fafbfc;
            white-space: nowrap;
        }

        .dash-table tbody td {
            padding: 0.65rem 0.75rem;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
            color: var(--text-primary);
        }

        .dash-table tbody tr:last-child td {
            border-bottom: none;
        }

        .dash-table tbody tr:hover {
            background: rgba(37, 99, 235, 0.02);
        }

        /* ===== STATUS BADGES ===== */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.2rem 0.55rem;
            border-radius: 2px;
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .badge-pending {
            background: rgba(245, 158, 11, 0.1);
            color: #d97706;
            border: 1px solid rgba(245, 158, 11, 0.15);
        }

        .badge-confirmed {
            background: rgba(34, 197, 94, 0.1);
            color: #16a34a;
            border: 1px solid rgba(34, 197, 94, 0.15);
        }

        .badge-approved {
            background: rgba(34, 197, 94, 0.1);
            color: #16a34a;
            border: 1px solid rgba(34, 197, 94, 0.15);
        }

        .badge-rejected {
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
            border: 1px solid rgba(239, 68, 68, 0.15);
        }

        .badge-completed {
            background: rgba(37, 99, 235, 0.1);
            color: var(--blue);
            border: 1px solid rgba(37, 99, 235, 0.15);
        }

        /* ===== ACTIVITY FEED ===== */
        .activity-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 0.85rem;
            padding: 0.85rem 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .activity-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .activity-item:first-child {
            padding-top: 0;
        }

        .activity-icon {
            width: 34px;
            height: 34px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        .activity-content {
            flex: 1;
            min-width: 0;
        }

        .activity-text {
            font-size: 0.82rem;
            color: var(--text-primary);
            line-height: 1.4;
            margin-bottom: 0.2rem;
        }

        .activity-text strong {
            font-weight: 700;
        }

        .activity-time {
            font-size: 0.68rem;
            color: #94a3b8;
            font-weight: 500;
        }

        /* ===== SCROLLABLE ===== */
        .dash-scrollable {
            max-height: 380px;
            overflow-y: auto;
        }

        /* ===== CHART CONTAINER ===== */
        .chart-container {
            position: relative;
            height: 280px;
        }

        /* ===== PROGRESS BAR ===== */
        .progress-slim {
            height: 6px;
            border-radius: 3px;
            background: #e2e8f0;
            overflow: hidden;
        }

        .progress-slim .bar {
            height: 100%;
            border-radius: 3px;
            transition: width 0.6s ease;
        }

        .bar-gold { background: linear-gradient(90deg, var(--gold-dark), var(--gold)); }
        .bar-blue { background: linear-gradient(90deg, var(--blue-dark), var(--blue)); }
        .bar-green { background: linear-gradient(90deg, #16a34a, #22c55e); }
        .bar-red { background: linear-gradient(90deg, #dc2626, #ef4444); }
        .bar-cyan { background: linear-gradient(90deg, #0891b2, #06b6d4); }

        /* ===== EMPTY STATE ===== */
        .empty-state {
            text-align: center;
            padding: 2.5rem 1rem;
            color: var(--text-secondary, #64748b);
        }

        .empty-state i {
            font-size: 2.5rem;
            color: rgba(37, 99, 235, 0.15);
            margin-bottom: 0.75rem;
            display: block;
        }

        .empty-state p {
            font-size: 0.85rem;
            color: #94a3b8;
            margin: 0;
        }

        /* ===== TOUR ITEM ===== */
        .tour-item {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            padding: 0.85rem 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .tour-item:last-child { border-bottom: none; padding-bottom: 0; }
        .tour-item:first-child { padding-top: 0; }

        .tour-date-box {
            width: 48px;
            height: 52px;
            border-radius: 4px;
            background: linear-gradient(135deg, var(--blue-dark), var(--blue));
            color: #fff;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .tour-date-box .month {
            font-size: 0.55rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            line-height: 1;
        }

        .tour-date-box .day {
            font-size: 1.15rem;
            font-weight: 800;
            line-height: 1.2;
        }

        .tour-info {
            flex: 1;
            min-width: 0;
        }

        .tour-property {
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 0.15rem;
        }

        .tour-meta {
            font-size: 0.7rem;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 0.6rem;
            flex-wrap: wrap;
        }

        .tour-meta span {
            display: inline-flex;
            align-items: center;
            gap: 0.2rem;
        }

        .tour-meta i {
            font-size: 0.65rem;
        }

        /* ===== AGENT ROW ===== */
        .agent-row {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .agent-row:last-child { border-bottom: none; padding-bottom: 0; }
        .agent-row:first-child { padding-top: 0; }

        .agent-rank {
            width: 28px;
            height: 28px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 800;
            flex-shrink: 0;
        }

        .agent-rank.rank-1 {
            background: linear-gradient(135deg, var(--gold-dark), var(--gold));
            color: #fff;
        }

        .agent-rank.rank-other {
            background: #f1f5f9;
            color: var(--text-secondary);
            border: 1px solid #e2e8f0;
        }

        .agent-info {
            flex: 1;
            min-width: 0;
        }

        .agent-name {
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .agent-stat {
            font-size: 0.7rem;
            color: var(--text-secondary);
        }

        .agent-sales-badge {
            background: rgba(37, 99, 235, 0.08);
            color: var(--blue);
            font-size: 0.72rem;
            font-weight: 700;
            padding: 0.2rem 0.6rem;
            border-radius: 2px;
            border: 1px solid rgba(37, 99, 235, 0.12);
            white-space: nowrap;
        }

        /* ===== MARKET HIGHLIGHT ITEM ===== */
        .highlight-item {
            padding: 0.85rem 1rem;
            border-radius: 4px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            margin-bottom: 0.75rem;
            transition: all 0.2s ease;
        }

        .highlight-item:last-child { margin-bottom: 0; }

        .highlight-item:hover {
            border-color: rgba(212, 175, 55, 0.3);
            background: #fffdf5;
        }

        .highlight-label {
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--text-secondary);
            margin-bottom: 0.35rem;
        }

        .highlight-value {
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--text-primary);
            line-height: 1.2;
        }

        .highlight-sub {
            font-size: 0.72rem;
            color: var(--text-secondary);
            margin-top: 0.2rem;
        }

        /* ===== INLINE BUTTONS ===== */
        .btn-dash {
            font-size: 0.72rem;
            font-weight: 600;
            padding: 0.3rem 0.7rem;
            border-radius: 2px;
            border: 1px solid #e2e8f0;
            background: var(--card-bg);
            color: var(--blue);
            text-decoration: none;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .btn-dash:hover {
            border-color: var(--blue);
            background: rgba(37, 99, 235, 0.04);
            color: var(--blue-dark);
        }

        .btn-dash-gold {
            color: var(--gold-dark);
            border-color: rgba(212, 175, 55, 0.3);
        }

        .btn-dash-gold:hover {
            border-color: var(--gold);
            background: rgba(212, 175, 55, 0.05);
            color: var(--gold-dark);
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 1400px) {
            .kpi-grid { grid-template-columns: repeat(3, 1fr); }
            .quick-actions-grid { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 992px) {
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .quick-actions-grid { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 768px) {
            .admin-content { padding: 1rem; }
            .page-header { padding: 1.25rem 1rem; }
            .page-header h1 { font-size: 1.3rem; }
            .page-header-inner { flex-direction: column; align-items: flex-start; gap: 0.75rem; }
            .kpi-grid { grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
            .kpi-card { padding: 1rem; }
            .kpi-card .kpi-value { font-size: 1.5rem; }
            .quick-actions-grid { grid-template-columns: repeat(2, 1fr); }
            .dash-card { padding: 1.25rem; }
            .dash-card-header { flex-wrap: wrap; gap: 0.5rem; }
            .dash-table thead th { font-size: 0.72rem; padding: 0.7rem 0.75rem; }
            .dash-table tbody td { font-size: 0.8rem; padding: 0.65rem 0.75rem; }
        }

        @media (max-width: 576px) {
            .admin-content { padding: 0.75rem; }
            .page-header { padding: 1rem; }
            .page-header h1 { font-size: 1.15rem; }
            .kpi-grid { grid-template-columns: 1fr 1fr; gap: 0.5rem; }
            .kpi-card { padding: 0.85rem; }
            .kpi-card .kpi-value { font-size: 1.25rem; }
            .kpi-card .kpi-label { font-size: 0.65rem; }
            .quick-actions-grid { grid-template-columns: 1fr 1fr; gap: 0.5rem; }
            .dash-card { padding: 1rem; }
        }

        @media (max-width: 400px) {
            .kpi-grid { grid-template-columns: 1fr 1fr; }
            .kpi-card .kpi-value { font-size: 1.1rem; }
        }

        /* ===== ROW EQUAL HEIGHT ===== */
        .row > [class*='col-'] {
            display: flex;
            flex-direction: column;
        }

        .row > [class*='col-'] > .dash-card {
            flex: 1;
        }

        /* ===== TOAST NOTIFICATIONS ===== */
        #toastContainer {
            position: fixed;
            top: 1.5rem;
            right: 1.5rem;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
            pointer-events: none;
        }
        .app-toast {
            display: flex;
            align-items: flex-start;
            gap: 0.85rem;
            background: #ffffff;
            border-radius: 12px;
            padding: 0.9rem 1.1rem;
            min-width: 300px;
            max-width: 380px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.16), 0 0 0 1px rgba(0,0,0,0.06);
            pointer-events: all;
            position: relative;
            overflow: hidden;
            animation: toast-in .35s cubic-bezier(.34,1.56,.64,1) forwards;
        }
        @keyframes toast-in  { from { opacity:0; transform: translateX(60px) scale(.95); } to { opacity:1; transform: translateX(0) scale(1); } }
        .app-toast.toast-out { animation: toast-out .3s ease forwards; }
        @keyframes toast-out { to { opacity:0; transform: translateX(60px) scale(.9); max-height:0; padding:0; margin:0; } }
        .app-toast::before {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 3px;
        }
        .app-toast.toast-success::before { background: linear-gradient(180deg, #d4af37, #b8941f); }
        .app-toast.toast-error::before   { background: linear-gradient(180deg, #ef4444, #dc2626); }
        .app-toast.toast-info::before    { background: linear-gradient(180deg, #2563eb, #1e40af); }
        .app-toast.toast-warning::before { background: linear-gradient(180deg, #d4af37, #b8941f); }
        .app-toast-icon {
            width: 36px; height: 36px;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }
        .toast-success .app-toast-icon,
        .toast-warning .app-toast-icon { background: rgba(212,175,55,0.12); color: #d4af37; }
        .toast-error   .app-toast-icon { background: rgba(239,68,68,0.1);   color: #ef4444; }
        .toast-info    .app-toast-icon { background: rgba(37,99,235,0.1);   color: #2563eb; }
        .app-toast-body      { flex: 1; min-width: 0; }
        .app-toast-title     { font-size: 0.82rem; font-weight: 700; color: #111827; margin-bottom: 0.2rem; }
        .app-toast-msg       { font-size: 0.78rem; color: #6b7280; line-height: 1.4; word-break: break-word; }
        .app-toast-close {
            background: none; border: none; cursor: pointer;
            color: #9ca3af; font-size: 0.8rem;
            padding: 0; line-height: 1;
            flex-shrink: 0;
            transition: color .2s;
        }
        .app-toast-close:hover { color: #374151; }
        .app-toast-progress {
            position: absolute;
            bottom: 0; left: 0;
            height: 2px;
            border-radius: 0 0 0 12px;
        }
        .toast-success .app-toast-progress,
        .toast-warning .app-toast-progress { background: linear-gradient(90deg, #d4af37, #b8941f); }
        .toast-error   .app-toast-progress { background: linear-gradient(90deg, #ef4444, #dc2626); }
        .toast-info    .app-toast-progress { background: linear-gradient(90deg, #2563eb, #1e40af); }
        @keyframes toast-progress { from { width: 100%; } to { width: 0%; } }

        /* ================================================================
           SKELETON SCREEN — Admin Dashboard (CSR / Progressive Hydration)
           ================================================================ */
        @keyframes sk-shimmer {
            0%   { background-position: -800px 0; }
            100% { background-position:  800px 0; }
        }
        .sk-shimmer {
            background: linear-gradient(90deg, #f0f0f0 25%, #e8e8e8 50%, #f0f0f0 75%);
            background-size: 1600px 100%;
            animation: sk-shimmer 1.6s ease-in-out infinite;
            border-radius: 4px;
        }
        #page-content { display: none; }

        .sk-page-header {
            background: #fff;
            border: 1px solid rgba(37,99,235,0.1);
            border-radius: 4px;
            padding: 2rem 2.5rem;
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
        }
        .sk-page-header::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, #e8e3d0, #d4e0f7, transparent);
        }

        /* 6-column KPI grid for dashboard */
        .sk-kpi-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .sk-kpi-card {
            background: #fff;
            border: 1px solid rgba(37,99,235,0.1);
            border-radius: 4px;
            padding: 1.25rem;
        }
        .sk-kpi-icon { width: 40px; height: 40px; border-radius: 4px; margin-bottom: 0.75rem; }

        /* Quick actions 4-column */
        .sk-quick-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .sk-quick-item {
            background: #fff;
            border: 1px solid rgba(37,99,235,0.1);
            border-radius: 4px;
            padding: 1.25rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        /* Generic dashboard card skeleton */
        .sk-dash-card {
            background: #fff;
            border: 1px solid rgba(37,99,235,0.1);
            border-radius: 4px;
            overflow: hidden;
            position: relative;
            height: 100%;
        }
        .sk-dash-card::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, #e8e3d0, #d4e0f7, transparent);
        }
        .sk-dash-header {
            padding: 1.15rem 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            background: #fafbfc;
        }
        .sk-dash-body { padding: 1.25rem 1.5rem; }
        .sk-line { display: block; border-radius: 4px; }

        @media (max-width: 1400px) {
            .sk-kpi-grid  { grid-template-columns: repeat(3, 1fr); }
            .sk-quick-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 992px)  {
            .sk-kpi-grid  { grid-template-columns: repeat(2, 1fr); }
            .sk-quick-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px)  {
            .sk-kpi-grid  { grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
            .sk-quick-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 576px)  {
            .sk-kpi-grid  { grid-template-columns: 1fr 1fr; gap: 0.5rem; }
            .sk-quick-grid { grid-template-columns: 1fr 1fr; gap: 0.5rem; }
        }
    </style>
</head>
<body>

    <?php include 'admin_sidebar.php'; ?>
    <?php include 'admin_navbar.php'; ?>

    <div class="admin-content">

        <!-- NO-JS FALLBACK: show real content if JS is disabled -->
        <noscript><style>
            #sk-screen    { display: none !important; }
            #page-content { display: block !important; opacity: 1 !important; }
        </style></noscript>

        <!-- ═══════════════════════════════════════════════════════
             SKELETON SCREEN — renders on first paint, removed by JS
             ═══════════════════════════════════════════════════════ -->
        <div id="sk-screen" role="presentation" aria-hidden="true">

            <!-- Page Header -->
            <div class="sk-page-header">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;">
                    <div>
                        <div class="sk-line sk-shimmer" style="width:140px;height:22px;margin-bottom:10px;"></div>
                        <div class="sk-line sk-shimmer" style="width:310px;height:13px;"></div>
                    </div>
                    <div style="display:flex;gap:0.5rem;">
                        <div class="sk-shimmer" style="width:105px;height:30px;border-radius:2px;"></div>
                        <div class="sk-shimmer" style="width:120px;height:30px;border-radius:2px;"></div>
                    </div>
                </div>
            </div>

            <!-- KPI Cards (6-column) -->
            <div class="sk-kpi-grid">
                <?php for ($sk_i = 0; $sk_i < 6; $sk_i++): ?>
                <div class="sk-kpi-card">
                    <div class="sk-kpi-icon sk-shimmer"></div>
                    <div class="sk-line sk-shimmer" style="width:70%;height:10px;margin-bottom:8px;"></div>
                    <div class="sk-line sk-shimmer" style="width:50%;height:22px;margin-bottom:6px;"></div>
                    <div class="sk-line sk-shimmer" style="width:80%;height:10px;"></div>
                </div>
                <?php endfor; ?>
            </div>

            <!-- Quick Actions (4-column) -->
            <div class="sk-quick-grid">
                <?php for ($sk_i = 0; $sk_i < 4; $sk_i++): ?>
                <div class="sk-quick-item">
                    <div class="sk-shimmer" style="width:48px;height:48px;border-radius:4px;flex-shrink:0;"></div>
                    <div style="flex:1;">
                        <div class="sk-line sk-shimmer" style="width:75%;height:13px;margin-bottom:8px;"></div>
                        <div class="sk-line sk-shimmer" style="width:90%;height:10px;"></div>
                    </div>
                </div>
                <?php endfor; ?>
            </div>

            <!-- Row 1: Charts -->
            <div class="row g-4 mb-4">
                <div class="col-lg-8">
                    <div class="sk-dash-card">
                        <div class="sk-dash-header">
                            <div class="sk-shimmer" style="width:130px;height:14px;border-radius:3px;"></div>
                        </div>
                        <div class="sk-dash-body">
                            <div class="sk-shimmer" style="width:100%;height:260px;border-radius:4px;"></div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="sk-dash-card">
                        <div class="sk-dash-header">
                            <div class="sk-shimmer" style="width:110px;height:14px;border-radius:3px;"></div>
                        </div>
                        <div class="sk-dash-body">
                            <div class="sk-shimmer" style="width:160px;height:160px;border-radius:50%;margin:0.5rem auto 1.25rem;display:block;"></div>
                            <?php for ($sk_i = 0; $sk_i < 3; $sk_i++): ?>
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.5rem;">
                                <div class="sk-shimmer" style="width:55%;height:11px;border-radius:3px;"></div>
                                <div class="sk-shimmer" style="width:25%;height:11px;border-radius:3px;"></div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Row 2: Pending Approvals + Activity Feed -->
            <div class="row g-4 mb-4">
                <div class="col-lg-7">
                    <div class="sk-dash-card">
                        <div class="sk-dash-header">
                            <div class="sk-shimmer" style="width:160px;height:14px;border-radius:3px;"></div>
                        </div>
                        <div class="sk-dash-body" style="padding:0;">
                            <!-- table header row -->
                            <div style="display:flex;gap:1rem;padding:0.7rem 1.5rem;border-bottom:1px solid #e2e8f0;background:#fafbfc;">
                                <div class="sk-shimmer" style="flex:2;height:11px;border-radius:3px;"></div>
                                <div class="sk-shimmer" style="flex:1;height:11px;border-radius:3px;"></div>
                                <div class="sk-shimmer" style="width:60px;height:11px;border-radius:3px;"></div>
                                <div class="sk-shimmer" style="width:55px;height:11px;border-radius:3px;"></div>
                            </div>
                            <?php for ($sk_i = 0; $sk_i < 5; $sk_i++): ?>
                            <div style="display:flex;gap:1rem;align-items:center;padding:0.65rem 1.5rem;border-bottom:1px solid #f1f5f9;">
                                <div style="flex:2;">
                                    <div class="sk-shimmer" style="width:80%;height:12px;border-radius:3px;margin-bottom:5px;"></div>
                                    <div class="sk-shimmer" style="width:55%;height:10px;border-radius:3px;"></div>
                                </div>
                                <div class="sk-shimmer" style="flex:1;height:12px;border-radius:3px;"></div>
                                <div class="sk-shimmer" style="width:60px;height:12px;border-radius:3px;"></div>
                                <div class="sk-shimmer" style="width:55px;height:26px;border-radius:3px;"></div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="sk-dash-card">
                        <div class="sk-dash-header">
                            <div class="sk-shimmer" style="width:130px;height:14px;border-radius:3px;"></div>
                        </div>
                        <div class="sk-dash-body">
                            <?php for ($sk_i = 0; $sk_i < 6; $sk_i++): ?>
                            <div style="display:flex;gap:0.85rem;align-items:flex-start;padding:0.75rem 0;border-bottom:1px solid #f1f5f9;">
                                <div class="sk-shimmer" style="width:34px;height:34px;border-radius:4px;flex-shrink:0;"></div>
                                <div style="flex:1;">
                                    <div class="sk-shimmer" style="width:85%;height:12px;border-radius:3px;margin-bottom:6px;"></div>
                                    <div class="sk-shimmer" style="width:38%;height:10px;border-radius:3px;"></div>
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Rows 3–4: Card pair placeholders -->
            <?php for ($sk_row = 0; $sk_row < 2; $sk_row++): ?>
            <div class="row g-4 mb-4">
                <div class="col-lg-6">
                    <div class="sk-dash-card">
                        <div class="sk-dash-header">
                            <div class="sk-shimmer" style="width:140px;height:14px;border-radius:3px;"></div>
                        </div>
                        <div class="sk-dash-body">
                            <?php for ($sk_i = 0; $sk_i < 5; $sk_i++): ?>
                            <div style="display:flex;gap:0.85rem;align-items:center;padding:0.75rem 0;border-bottom:1px solid #f1f5f9;">
                                <div class="sk-shimmer" style="width:48px;height:52px;border-radius:4px;flex-shrink:0;"></div>
                                <div style="flex:1;">
                                    <div class="sk-shimmer" style="width:70%;height:12px;border-radius:3px;margin-bottom:6px;"></div>
                                    <div class="sk-shimmer" style="width:90%;height:10px;border-radius:3px;"></div>
                                </div>
                                <div class="sk-shimmer" style="width:60px;height:22px;border-radius:3px;"></div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="sk-dash-card">
                        <div class="sk-dash-header">
                            <div class="sk-shimmer" style="width:120px;height:14px;border-radius:3px;"></div>
                        </div>
                        <div class="sk-dash-body">
                            <?php for ($sk_i = 0; $sk_i < 4; $sk_i++): ?>
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.9rem;">
                                <div>
                                    <div class="sk-shimmer" style="width:120px;height:12px;border-radius:3px;margin-bottom:5px;"></div>
                                    <div class="sk-shimmer" style="width:80px;height:10px;border-radius:3px;"></div>
                                </div>
                                <div>
                                    <div class="sk-shimmer" style="width:100px;height:6px;border-radius:3px;margin-bottom:4px;"></div>
                                    <div class="sk-shimmer" style="width:35px;height:11px;border-radius:3px;margin-left:auto;"></div>
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endfor; ?>

        </div><!-- /#sk-screen -->

        <!-- ═══════════════════════════════════════════════════════
             REAL PAGE CONTENT — hidden until hydrated by JS
             ═══════════════════════════════════════════════════════ -->
        <div id="page-content">

        <!-- ===== PAGE HEADER ===== -->
        <div class="page-header">
            <div class="page-header-inner">
                <div>
                    <h1>Dashboard</h1>
                    <p class="subtitle">Welcome back, <?php echo htmlspecialchars($admin_first_name); ?>. Here's your platform overview.</p>
                </div>
                <div class="header-meta">
                    <?php if($unread_notifs > 0): ?>
                    <a href="admin_notifications.php" class="header-meta-item text-decoration-none">
                        <i class="bi bi-bell-fill"></i>
                        <strong><?php echo $unread_notifs; ?></strong> unread
                    </a>
                    <?php endif; ?>
                    <div class="header-meta-item">
                        <i class="bi bi-calendar3"></i>
                        <?php echo date('F d, Y'); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== KPI STAT CARDS ===== -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-icon gold"><i class="bi bi-buildings"></i></div>
                <div class="kpi-label">Active Listings</div>
                <div class="kpi-value"><?php echo number_format($approved_properties); ?></div>
                <div class="kpi-sub"><?php echo $for_sale_properties; ?> for sale &middot; <?php echo $for_rent_properties; ?> for rent</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon green"><i class="bi bi-person-badge"></i></div>
                <div class="kpi-label">Active Agents</div>
                <div class="kpi-value"><?php echo number_format($approved_agents); ?></div>
                <div class="kpi-sub"><?php echo $total_agents; ?> total registered</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon amber"><i class="bi bi-hourglass-split"></i></div>
                <div class="kpi-label">Pending Approvals</div>
                <div class="kpi-value"><?php echo number_format($pending_approvals_total); ?></div>
                <div class="kpi-sub"><?php echo $pending_properties; ?> properties &middot; <?php echo $pending_agents; ?> agents</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon blue"><i class="bi bi-calendar-check"></i></div>
                <div class="kpi-label">Tour Requests</div>
                <div class="kpi-value"><?php echo number_format($total_tours); ?></div>
                <div class="kpi-sub"><?php echo $pending_tours; ?> pending &middot; <?php echo $confirmed_tours; ?> confirmed</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon cyan"><i class="bi bi-tag-fill"></i></div>
                <div class="kpi-label">Properties Sold</div>
                <div class="kpi-value"><?php echo number_format($sold_properties); ?></div>
                <div class="kpi-sub"><?php echo $pending_sold_properties; ?> pending verification</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon gold"><i class="bi bi-cash-stack"></i></div>
                <div class="kpi-label">Portfolio Value</div>
                <div class="kpi-value" title="<?php echo formatCurrencyFull($total_property_value); ?>"><?php echo formatCurrency($total_property_value); ?></div>
                <div class="kpi-sub">Avg <?php echo formatCurrency($avg_property_value); ?></div>
            </div>
        </div>

        <!-- ===== QUICK ACTIONS ===== -->
        <div class="quick-actions-grid">
            <a href="property.php" class="quick-action-item">
                <div class="qa-icon" style="background: linear-gradient(135deg, rgba(245, 158, 11, 0.08), rgba(245, 158, 11, 0.15)); color: #d97706; border: 1px solid rgba(245, 158, 11, 0.2);">
                    <i class="bi bi-building-fill-check"></i>
                    <?php if($pending_properties > 0): ?>
                    <span class="qa-badge"><?php echo $pending_properties; ?></span>
                    <?php endif; ?>
                </div>
                <div class="qa-content">
                    <div class="qa-title">Review Properties</div>
                    <div class="qa-desc"><?php echo $pending_properties; ?> listings awaiting approval</div>
                </div>
            </a>
            <a href="agent.php" class="quick-action-item">
                <div class="qa-icon" style="background: linear-gradient(135deg, rgba(34, 197, 94, 0.08), rgba(34, 197, 94, 0.15)); color: #16a34a; border: 1px solid rgba(34, 197, 94, 0.2);">
                    <i class="bi bi-person-badge-fill"></i>
                    <?php if($pending_agents > 0): ?>
                    <span class="qa-badge"><?php echo $pending_agents; ?></span>
                    <?php endif; ?>
                </div>
                <div class="qa-content">
                    <div class="qa-title">Review Agents</div>
                    <div class="qa-desc"><?php echo $pending_agents; ?> applications pending</div>
                </div>
            </a>
            <a href="admin_property_sale_approvals.php" class="quick-action-item">
                <div class="qa-icon" style="background: linear-gradient(135deg, rgba(6, 182, 212, 0.08), rgba(6, 182, 212, 0.15)); color: #0891b2; border: 1px solid rgba(6, 182, 212, 0.2);">
                    <i class="bi bi-clipboard-check-fill"></i>
                    <?php if(count($pending_sales_list) > 0): ?>
                    <span class="qa-badge"><?php echo count($pending_sales_list); ?></span>
                    <?php endif; ?>
                </div>
                <div class="qa-content">
                    <div class="qa-title">Sale Approvals</div>
                    <div class="qa-desc"><?php echo count($pending_sales_list); ?> sales need verification</div>
                </div>
            </a>
            <a href="tour_requests.php" class="quick-action-item">
                <div class="qa-icon" style="background: linear-gradient(135deg, rgba(37, 99, 235, 0.08), rgba(37, 99, 235, 0.15)); color: #2563eb; border: 1px solid rgba(37, 99, 235, 0.2);">
                    <i class="bi bi-calendar2-week-fill"></i>
                    <?php if($pending_tours > 0): ?>
                    <span class="qa-badge"><?php echo $pending_tours; ?></span>
                    <?php endif; ?>
                </div>
                <div class="qa-content">
                    <div class="qa-title">Tour Requests</div>
                    <div class="qa-desc"><?php echo $pending_tours; ?> pending tour requests</div>
                </div>
            </a>
        </div>

        <!-- ===== ROW 1: CHARTS ===== -->
        <div class="row g-4 mb-4">
            <!-- Listings Trend -->
            <div class="col-lg-8">
                <div class="dash-card">
                    <div class="dash-card-header">
                        <h3 class="dash-card-title"><i class="bi bi-graph-up"></i> Listings Activity</h3>
                        <span class="dash-card-action" style="font-size: 0.72rem; color: var(--text-secondary); pointer-events: none;">Last 30 Days</span>
                    </div>
                    <div class="dash-card-body">
                        <?php if(!empty($chart_labels)): ?>
                        <div class="chart-container">
                            <canvas id="listingsChart"></canvas>
                        </div>
                        <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-graph-up"></i>
                            <p>No listing data for the last 30 days</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Property Status Distribution -->
            <div class="col-lg-4">
                <div class="dash-card">
                    <div class="dash-card-header">
                        <h3 class="dash-card-title"><i class="bi bi-pie-chart-fill"></i> Listing Status</h3>
                    </div>
                    <div class="dash-card-body">
                        <?php if($total_properties > 0): ?>
                        <div class="chart-container" style="height: 200px;">
                            <canvas id="statusChart"></canvas>
                        </div>
                        <div class="mt-3">
                            <?php
                            $status_items = [
                                ['label' => 'For Sale', 'count' => $status_for_sale, 'color' => '#2563eb'],
                                ['label' => 'For Rent', 'count' => $status_for_rent, 'color' => '#d4af37'],
                                ['label' => 'Sold', 'count' => $status_sold, 'color' => '#64748b'],
                                ['label' => 'Pending Sold', 'count' => $status_pending_sold, 'color' => '#0891b2'],
                            ];
                            foreach($status_items as $item):
                                if($item['count'] == 0) continue;
                                $pct = $total_properties > 0 ? round(($item['count'] / $total_properties) * 100, 1) : 0;
                            ?>
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <div class="d-flex align-items-center gap-2">
                                    <span style="width: 10px; height: 10px; border-radius: 2px; background: <?php echo $item['color']; ?>; display: inline-block;"></span>
                                    <span style="font-size: 0.78rem; font-weight: 500; color: var(--text-primary);"><?php echo $item['label']; ?></span>
                                </div>
                                <span style="font-size: 0.75rem; font-weight: 700; color: var(--text-secondary);"><?php echo $item['count']; ?> <small>(<?php echo $pct; ?>%)</small></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-pie-chart"></i>
                            <p>No property data available</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== ROW 2: PENDING APPROVALS + ACTIVITY ===== -->
        <div class="row g-4 mb-4">
            <!-- Pending Approvals -->
            <div class="col-lg-7">
                <div class="dash-card">
                    <div class="dash-card-header">
                        <h3 class="dash-card-title">
                            <i class="bi bi-clipboard-check"></i> Pending Approvals
                            <?php if($pending_approvals_total > 0): ?>
                            <span class="status-badge badge-pending" style="margin-left: 0.5rem;"><?php echo $pending_approvals_total; ?></span>
                            <?php endif; ?>
                        </h3>
                    </div>
                    <div class="dash-card-body dash-scrollable" style="padding: 0;">
                        <?php if(!empty($pending_properties_list)): ?>
                        <!-- Pending Properties -->
                        <div style="padding: 1rem 1.5rem 0.5rem;">
                            <div style="font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text-secondary); margin-bottom: 0.5rem;">
                                <i class="bi bi-building text-warning me-1"></i> Property Listings
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="dash-table">
                                <thead>
                                    <tr>
                                        <th>Property</th>
                                        <th>Type</th>
                                        <th>Price</th>
                                        <th>Posted By</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($pending_properties_list as $prop): ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 600; max-width: 180px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($prop['StreetAddress']); ?></div>
                                            <div style="font-size: 0.7rem; color: var(--text-secondary);"><?php echo htmlspecialchars($prop['City']); ?></div>
                                        </td>
                                        <td><span style="font-size: 0.75rem;"><?php echo htmlspecialchars($prop['PropertyType']); ?></span></td>
                                        <td><strong><?php echo formatCurrency($prop['ListingPrice']); ?></strong></td>
                                        <td><span style="font-size: 0.75rem;"><?php echo htmlspecialchars($prop['posted_by'] ?? 'Admin'); ?></span></td>
                                        <td><a href="view_property.php?id=<?php echo $prop['property_ID']; ?>" class="btn-dash">Review</a></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>

                        <?php if(!empty($pending_agents_list)): ?>
                        <!-- Pending Agents -->
                        <div style="padding: 1rem 1.5rem 0.5rem; <?php echo !empty($pending_properties_list) ? 'border-top: 1px solid #e2e8f0;' : ''; ?>">
                            <div style="font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text-secondary); margin-bottom: 0.5rem;">
                                <i class="bi bi-person-badge text-success me-1"></i> Agent Applications
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="dash-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>License</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($pending_agents_list as $agent): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($agent['first_name'] . ' ' . $agent['last_name']); ?></strong></td>
                                        <td><span style="font-size: 0.78rem;"><?php echo htmlspecialchars($agent['email']); ?></span></td>
                                        <td><span style="font-size: 0.75rem; font-family: monospace;"><?php echo htmlspecialchars($agent['license_number'] ?? 'N/A'); ?></span></td>
                                        <td><a href="review_agent_details.php?account_id=<?php echo $agent['account_id']; ?>" class="btn-dash btn-dash-gold">Review</a></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>

                        <?php if(!empty($pending_sales_list)): ?>
                        <!-- Pending Sales -->
                        <div style="padding: 1rem 1.5rem 0.5rem; <?php echo (!empty($pending_properties_list) || !empty($pending_agents_list)) ? 'border-top: 1px solid #e2e8f0;' : ''; ?>">
                            <div style="font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text-secondary); margin-bottom: 0.5rem;">
                                <i class="bi bi-cash-stack text-info me-1"></i> Sale Verifications
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="dash-table">
                                <thead>
                                    <tr>
                                        <th>Property</th>
                                        <th>Agent</th>
                                        <th>Sale Price</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($pending_sales_list as $sale): ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 600; max-width: 160px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($sale['StreetAddress']); ?></div>
                                        </td>
                                        <td><span style="font-size: 0.78rem;"><?php echo htmlspecialchars($sale['agent_name']); ?></span></td>
                                        <td><strong><?php echo formatCurrency($sale['sale_price']); ?></strong></td>
                                        <td><a href="admin_property_sale_approvals.php" class="btn-dash">Review</a></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>

                        <?php if(empty($pending_properties_list) && empty($pending_agents_list) && empty($pending_sales_list)): ?>
                        <div class="empty-state">
                            <i class="bi bi-check-circle"></i>
                            <p>All caught up — no pending approvals</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="col-lg-5">
                <div class="dash-card">
                    <div class="dash-card-header">
                        <h3 class="dash-card-title"><i class="bi bi-activity"></i> Recent Activity</h3>
                    </div>
                    <div class="dash-card-body dash-scrollable">
                        <?php if(!empty($recent_activity)): ?>
                        <ul class="activity-list">
                            <?php foreach($recent_activity as $activity):
                                $type = $activity['type'];
                                $action = strtolower($activity['action']);

                                // Determine icon and color
                                if ($type === 'listing' || $action === 'created') {
                                    $icon = 'bi-plus-circle-fill';
                                    $icon_bg = 'rgba(37, 99, 235, 0.1)';
                                    $icon_color = '#2563eb';
                                    $action_text = 'New listing submitted';
                                } elseif ($action === 'approved') {
                                    $icon = 'bi-check-circle-fill';
                                    $icon_bg = 'rgba(34, 197, 94, 0.1)';
                                    $icon_color = '#16a34a';
                                    $action_text = ($type === 'agent') ? 'Agent approved' : 'Property approved';
                                } elseif ($action === 'rejected') {
                                    $icon = 'bi-x-circle-fill';
                                    $icon_bg = 'rgba(239, 68, 68, 0.1)';
                                    $icon_color = '#dc2626';
                                    $action_text = ($type === 'agent') ? 'Agent rejected' : 'Property rejected';
                                } else {
                                    $icon = 'bi-info-circle-fill';
                                    $icon_bg = 'rgba(100, 116, 139, 0.1)';
                                    $icon_color = '#64748b';
                                    $action_text = 'Status updated';
                                }
                            ?>
                            <li class="activity-item">
                                <div class="activity-icon" style="background: <?php echo $icon_bg; ?>; color: <?php echo $icon_color; ?>;">
                                    <i class="bi <?php echo $icon; ?>"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-text">
                                        <?php echo $action_text; ?>: <strong><?php echo htmlspecialchars($activity['subject']); ?></strong>
                                    </div>
                                    <div class="activity-time"><?php echo time_elapsed_string($activity['timestamp']); ?></div>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-clock-history"></i>
                            <p>No recent activity to display</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== ROW 3: UPCOMING TOURS + TOP AGENTS ===== -->
        <div class="row g-4 mb-4">
            <!-- Upcoming Tours -->
            <div class="col-lg-6">
                <div class="dash-card">
                    <div class="dash-card-header">
                        <h3 class="dash-card-title"><i class="bi bi-calendar2-week"></i> Upcoming Tours</h3>
                        <a href="tour_requests.php" class="dash-card-action">View All <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <div class="dash-card-body dash-scrollable">
                        <?php if(!empty($upcoming_tours)): ?>
                            <?php foreach($upcoming_tours as $tour): ?>
                            <div class="tour-item">
                                <div class="tour-date-box">
                                    <span class="month"><?php echo date('M', strtotime($tour['tour_date'])); ?></span>
                                    <span class="day"><?php echo date('d', strtotime($tour['tour_date'])); ?></span>
                                </div>
                                <div class="tour-info">
                                    <div class="tour-property"><?php echo htmlspecialchars($tour['StreetAddress']); ?></div>
                                    <div class="tour-meta">
                                        <span><i class="bi bi-clock"></i> <?php echo date('g:i A', strtotime($tour['tour_time'])); ?></span>
                                        <span><i class="bi bi-person"></i> <?php echo htmlspecialchars($tour['user_name']); ?></span>
                                        <span><i class="bi bi-person-badge"></i> <?php echo htmlspecialchars($tour['agent_name']); ?></span>
                                    </div>
                                </div>
                                <span class="status-badge <?php echo $tour['status'] === 'Confirmed' ? 'badge-confirmed' : 'badge-pending'; ?>">
                                    <?php echo $tour['status']; ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="bi bi-calendar-x"></i>
                                <p>No upcoming tours scheduled</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Top Agents -->
            <div class="col-lg-6">
                <div class="dash-card">
                    <div class="dash-card-header">
                        <h3 class="dash-card-title"><i class="bi bi-trophy"></i> Top Performing Agents</h3>
                        <a href="agent.php" class="dash-card-action">View All <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <div class="dash-card-body">
                        <?php if(!empty($top_agents)): ?>
                            <?php
                            $rank = 1;
                            foreach($top_agents as $agent):
                            ?>
                            <div class="agent-row">
                                <div class="agent-rank <?php echo $rank === 1 ? 'rank-1' : 'rank-other'; ?>">
                                    <?php if($rank === 1): ?>
                                        <i class="bi bi-trophy-fill" style="font-size: 0.7rem;"></i>
                                    <?php else: ?>
                                        <?php echo $rank; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="agent-info">
                                    <div class="agent-name"><?php echo htmlspecialchars($agent['first_name'] . ' ' . $agent['last_name']); ?></div>
                                    <div class="agent-stat">
                                        <?php if($agent['total_sales_value'] > 0): ?>
                                            <?php echo formatCurrency($agent['total_sales_value']); ?> total sales
                                        <?php else: ?>
                                            No completed sales yet
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="agent-sales-badge">
                                    <?php echo $agent['sold_count']; ?> sold
                                </div>
                            </div>
                            <?php $rank++; endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="bi bi-people"></i>
                                <p>No agent data available</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== ROW 4: PROPERTY TYPES + MARKET HIGHLIGHTS ===== -->
        <div class="row g-4 mb-4">
            <!-- Property Type Distribution -->
            <div class="col-lg-7">
                <div class="dash-card">
                    <div class="dash-card-header">
                        <h3 class="dash-card-title"><i class="bi bi-diagram-3"></i> Property Type Distribution</h3>
                    </div>
                    <div class="dash-card-body">
                        <?php if(!empty($property_types)):
                            $total_approved = array_sum(array_column($property_types, 'count'));
                        ?>
                            <?php foreach($property_types as $index => $type):
                                $pct = $total_approved > 0 ? round(($type['count'] / $total_approved) * 100, 1) : 0;
                                $bar_classes = ['bar-gold', 'bar-blue', 'bar-green', 'bar-cyan', 'bar-red'];
                                $bar = $bar_classes[$index % count($bar_classes)];
                            ?>
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <div style="min-width: 140px;">
                                    <div style="font-size: 0.82rem; font-weight: 600; color: var(--text-primary);"><?php echo htmlspecialchars($type['PropertyType']); ?></div>
                                    <div style="font-size: 0.7rem; color: var(--text-secondary);"><?php echo $type['count']; ?> listing<?php echo $type['count'] != 1 ? 's' : ''; ?></div>
                                </div>
                                <div class="flex-grow-1 mx-3">
                                    <div class="progress-slim">
                                        <div class="bar <?php echo $bar; ?>" style="width: <?php echo $pct; ?>%;"></div>
                                    </div>
                                </div>
                                <div style="min-width: 42px; text-align: right;">
                                    <span style="font-size: 0.8rem; font-weight: 700; color: var(--text-primary);"><?php echo $pct; ?>%</span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="bi bi-diagram-3"></i>
                                <p>No property type data available</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Market Highlights -->
            <div class="col-lg-5">
                <div class="dash-card">
                    <div class="dash-card-header">
                        <h3 class="dash-card-title"><i class="bi bi-star-fill"></i> Market Highlights</h3>
                    </div>
                    <div class="dash-card-body">
                        <div class="highlight-item">
                            <div class="highlight-label">Highest Listed Property</div>
                            <div class="highlight-value" style="color: #16a34a;"><?php echo formatCurrencyFull($highest_priced_property['ListingPrice'] ?? 0); ?></div>
                            <div class="highlight-sub">
                                <i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars(($highest_priced_property['StreetAddress'] ?? 'N/A') . ', ' . ($highest_priced_property['City'] ?? '')); ?>
                            </div>
                        </div>
                        <div class="highlight-item">
                            <div class="highlight-label">Most Viewed Listing</div>
                            <div class="highlight-value"><?php echo number_format($most_viewed_property['ViewsCount'] ?? 0); ?> <small style="font-size: 0.7rem; font-weight: 500; color: var(--text-secondary);">views</small></div>
                            <div class="highlight-sub">
                                <i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars(($most_viewed_property['StreetAddress'] ?? 'N/A') . ', ' . ($most_viewed_property['City'] ?? '')); ?>
                            </div>
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <div class="highlight-item">
                                    <div class="highlight-label">Total Views</div>
                                    <div class="highlight-value"><?php echo number_format($total_views); ?></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="highlight-item">
                                    <div class="highlight-label">Tour Completion</div>
                                    <div class="highlight-value" style="color: var(--blue);"><?php echo $tour_success_rate; ?>%</div>
                                </div>
                            </div>
                        </div>
                        <div class="row g-2 mt-0">
                            <div class="col-6">
                                <div class="highlight-item">
                                    <div class="highlight-label">Total Likes</div>
                                    <div class="highlight-value"><?php echo number_format($total_likes); ?></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="highlight-item">
                                    <div class="highlight-label">Avg. Value</div>
                                    <div class="highlight-value" style="font-size: 0.95rem;"><?php echo formatCurrency($avg_property_value); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        </div><!-- /#page-content -->

    </div><!-- /.admin-content -->

    <?php if (!$profile_completed) include 'admin_profile_modal.php'; ?>

    <!-- Toast Container -->
    <div id="toastContainer"></div>

    <script src="<?= ASSETS_JS ?>chart.umd.min.js"></script>
    <script src="<?= ASSETS_JS ?>bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {

        // ===== LISTINGS TREND CHART =====
        const listingsCtx = document.getElementById('listingsChart');
        const listingsLabels = <?php echo json_encode($chart_labels); ?>;
        const listingsValues = <?php echo json_encode($chart_values); ?>;

        if (listingsCtx && listingsLabels.length > 0) {
            new Chart(listingsCtx, {
                type: 'line',
                data: {
                    labels: listingsLabels,
                    datasets: [{
                        label: 'New Listings',
                        data: listingsValues,
                        backgroundColor: 'rgba(212, 175, 55, 0.08)',
                        borderColor: '#d4af37',
                        borderWidth: 2.5,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#d4af37',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        pointHoverBackgroundColor: '#b8941f',
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.9)',
                            titleColor: '#f8fafc',
                            bodyColor: '#e2e8f0',
                            borderColor: '#d4af37',
                            borderWidth: 1,
                            cornerRadius: 4,
                            padding: 12,
                            titleFont: { weight: '700', size: 12 },
                            bodyFont: { size: 12 },
                            callbacks: {
                                label: function(context) {
                                    return context.parsed.y + ' listing' + (context.parsed.y !== 1 ? 's' : '');
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                color: '#94a3b8',
                                font: { size: 11, weight: '500' }
                            },
                            grid: {
                                color: 'rgba(0,0,0,0.04)',
                                drawBorder: false
                            }
                        },
                        x: {
                            ticks: {
                                color: '#94a3b8',
                                font: { size: 11, weight: '500' },
                                maxRotation: 45
                            },
                            grid: { display: false }
                        }
                    }
                }
            });
        }

        // ===== STATUS DOUGHNUT CHART =====
        const statusCtx = document.getElementById('statusChart');
        if (statusCtx) {
            new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: ['For Sale', 'For Rent', 'Sold', 'Pending Sold'],
                    datasets: [{
                        data: [<?php echo "$status_for_sale, $status_for_rent, $status_sold, $status_pending_sold"; ?>],
                        backgroundColor: ['#2563eb', '#d4af37', '#64748b', '#0891b2'],
                        borderWidth: 2,
                        borderColor: '#fff',
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.9)',
                            titleColor: '#f8fafc',
                            bodyColor: '#e2e8f0',
                            cornerRadius: 4,
                            padding: 10,
                            bodyFont: { size: 12 },
                            callbacks: {
                                label: function(context) {
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const pct = total > 0 ? ((context.parsed / total) * 100).toFixed(1) : 0;
                                    return context.label + ': ' + context.parsed + ' (' + pct + '%)';
                                }
                            }
                        }
                    }
                }
            });
        }

    });

    // ===== DEFERRED UI — fires after skeleton hydrates (real content is visible) =====
    /* Profile modal and toast notifications use 'skeleton:hydrated' instead of
       'DOMContentLoaded' so they only appear once the real page content is visible. */
    document.addEventListener('skeleton:hydrated', function() {

        // ===== PROFILE MODAL =====
        <?php if (!$profile_completed): ?>
        var adminProfileModal = new bootstrap.Modal(document.getElementById('adminProfileModal'), {
            backdrop: 'static',
            keyboard: false
        });
        adminProfileModal.show();
        <?php endif; ?>

        // ===== PENDING NOTIFICATION =====
        const pendingCount = <?php echo $pending_approvals_total; ?>;
        if (pendingCount > 0) {
            showToast('warning', 'Pending Approvals', 'You have ' + pendingCount + (pendingCount !== 1 ? ' items' : ' item') + ' pending approval.');
        }

    });

    // ===== TOAST NOTIFICATION SYSTEM =====
    function showToast(type, title, message, duration) {
        duration = duration || 4500;
        const container = document.getElementById('toastContainer');
        const icons = {
            success: 'bi-check-circle-fill',
            error:   'bi-x-circle-fill',
            info:    'bi-info-circle-fill',
            warning: 'bi-exclamation-triangle-fill'
        };
        const toast = document.createElement('div');
        toast.className = `app-toast toast-${type}`;
        toast.innerHTML = `
            <div class="app-toast-icon"><i class="bi ${icons[type] || icons.info}"></i></div>
            <div class="app-toast-body">
                <div class="app-toast-title">${title}</div>
                <div class="app-toast-msg">${message}</div>
            </div>
            <button class="app-toast-close" onclick="dismissToast(this.closest('.app-toast'))">&times;</button>
            <div class="app-toast-progress" style="animation: toast-progress ${duration}ms linear forwards;"></div>
        `;
        container.appendChild(toast);
        const timer = setTimeout(() => dismissToast(toast), duration);
        toast._timer = timer;
    }
    function dismissToast(toast) {
        if (!toast || toast._dismissed) return;
        toast._dismissed = true;
        clearTimeout(toast._timer);
        toast.classList.add('toast-out');
        setTimeout(() => toast.remove(), 320);
    }
    </script>

    <!-- ═══════════════════════════════════════════════════════════════
         SKELETON HYDRATION — Progressive Content Reveal
         Trigger: window 'load' (fires after ALL external resources finish)
         See: docs/SKELETON_SCREEN_GUIDE.md — Parts C & D
         ═══════════════════════════════════════════════════════════════ -->
    <script>
    (function () {
        'use strict';

        /* ── Configuration ────────────────────────────────────────────── */
        var MIN_SKELETON_MS = 400;   /* Minimum ms skeleton stays visible.
                                        Prevents flash on fast local loads. */
        var skeletonStart = Date.now();

        function hydrate() {
            var sk = document.getElementById('sk-screen');
            var pc = document.getElementById('page-content');

            if (!pc) return;
            if (!sk) {
                pc.style.cssText = 'display:block;opacity:1;';
                document.dispatchEvent(new Event('skeleton:hydrated'));
                return;
            }

            /* Step 1: make real content visible but transparent */
            pc.style.display = 'block';
            pc.style.opacity = '0';

            /* Step 2: cross-fade on the next frame */
            requestAnimationFrame(function () {
                sk.style.transition = 'opacity 0.35s ease';
                sk.style.opacity    = '0';

                pc.style.transition = 'opacity 0.42s ease 0.1s';
                requestAnimationFrame(function () {
                    pc.style.opacity = '1';
                });
            });

            /* Step 3: remove skeleton from DOM, dispatch hydrated event */
            window.setTimeout(function () {
                if (sk && sk.parentNode) sk.parentNode.removeChild(sk);
                pc.style.transition = '';
                pc.style.opacity    = '';

                /* Deferred UI (profile modal, toasts) can now fire */
                document.dispatchEvent(new Event('skeleton:hydrated'));
            }, 520);
        }

        /* Enforce MIN_SKELETON_MS before hydrating */
        function scheduleHydration() {
            var elapsed   = Date.now() - skeletonStart;
            var remaining = Math.max(0, MIN_SKELETON_MS - elapsed);
            if (remaining > 0) {
                window.setTimeout(hydrate, remaining);
            } else {
                hydrate();
            }
        }

        /*
         * Trigger on window 'load' — waits for Bootstrap CSS, Google Fonts,
         * Font Awesome, Chart.js, and all images to finish loading.
         * DOMContentLoaded fires too early (PHP page DOM is already complete).
         */
        if (document.readyState === 'complete') {
            scheduleHydration();
        } else {
            window.addEventListener('load', scheduleHydration);
        }

    }());
    </script>
</body>
</html>
