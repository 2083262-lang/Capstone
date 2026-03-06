<?php
session_start();
require_once 'connection.php';
require_once __DIR__ . '/config/session_timeout.php';
require_once __DIR__ . '/config/paths.php';

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

// =============================================
// PROPERTY REPORT DATA
// =============================================
$property_report = [];
$prop_sql = "SELECT 
    p.property_ID, p.StreetAddress, p.City, p.Barangay, p.Province, p.ZIP,
    p.PropertyType, p.YearBuilt, p.SquareFootage, p.LotSize, p.Bedrooms, p.Bathrooms,
    p.ListingPrice, p.Status, p.ViewsCount, p.Likes, p.ListingDate, p.ParkingType,
    p.approval_status, p.sold_date,
    CONCAT(a.first_name, ' ', a.last_name) AS posted_by,
    ur.role_name AS poster_role
FROM property p
LEFT JOIN property_log pl ON p.property_ID = pl.property_id AND pl.action = 'CREATED'
LEFT JOIN accounts a ON pl.account_id = a.account_id
LEFT JOIN user_roles ur ON a.role_id = ur.role_id
ORDER BY p.ListingDate DESC";
$prop_result = $conn->query($prop_sql);
if ($prop_result) { while ($row = $prop_result->fetch_assoc()) { $property_report[] = $row; } }

// =============================================
// SALES REPORT DATA
// =============================================
$sales_report = [];
$sales_sql = "SELECT 
    fs.sale_id, fs.property_id, p.StreetAddress, p.City, p.PropertyType,
    fs.buyer_name, fs.buyer_email, fs.final_sale_price,
    fs.sale_date, fs.additional_notes, fs.finalized_at,
    CONCAT(agent.first_name, ' ', agent.last_name) AS agent_name,
    CONCAT(admin_acc.first_name, ' ', admin_acc.last_name) AS finalized_by_name,
    ac.commission_amount, ac.commission_percentage, ac.status AS commission_status
FROM finalized_sales fs
JOIN property p ON fs.property_id = p.property_ID
JOIN accounts agent ON fs.agent_id = agent.account_id
LEFT JOIN accounts admin_acc ON fs.finalized_by = admin_acc.account_id
LEFT JOIN agent_commissions ac ON fs.sale_id = ac.sale_id
ORDER BY fs.sale_date DESC";
$sales_result = $conn->query($sales_sql);
if ($sales_result) { while ($row = $sales_result->fetch_assoc()) { $sales_report[] = $row; } }

// =============================================
// RENTAL REPORT DATA
// =============================================
$rental_report = [];
$rental_sql = "SELECT 
    fr.rental_id, fr.property_id, p.StreetAddress, p.City, p.PropertyType,
    fr.tenant_name, fr.tenant_email, fr.tenant_phone,
    fr.monthly_rent, fr.security_deposit, fr.lease_start_date, fr.lease_end_date,
    fr.lease_term_months, fr.commission_rate, fr.lease_status,
    fr.renewed_at, fr.terminated_at, fr.finalized_at,
    CONCAT(agent.first_name, ' ', agent.last_name) AS agent_name,
    CONCAT(admin_acc.first_name, ' ', admin_acc.last_name) AS finalized_by_name,
    (SELECT COUNT(*) FROM rental_payments rp WHERE rp.rental_id = fr.rental_id AND rp.status = 'Confirmed') AS confirmed_payments,
    (SELECT COUNT(*) FROM rental_payments rp WHERE rp.rental_id = fr.rental_id AND rp.status = 'Pending') AS pending_payments,
    (SELECT COALESCE(SUM(rp.payment_amount), 0) FROM rental_payments rp WHERE rp.rental_id = fr.rental_id AND rp.status = 'Confirmed') AS total_collected,
    (SELECT COALESCE(SUM(rc.commission_amount), 0) FROM rental_commissions rc WHERE rc.rental_id = fr.rental_id) AS total_commission
FROM finalized_rentals fr
JOIN property p ON fr.property_id = p.property_ID
JOIN accounts agent ON fr.agent_id = agent.account_id
LEFT JOIN accounts admin_acc ON fr.finalized_by = admin_acc.account_id
ORDER BY fr.finalized_at DESC";
$rental_result = $conn->query($rental_sql);
if ($rental_result) { while ($row = $rental_result->fetch_assoc()) { $rental_report[] = $row; } }

// =============================================
// AGENT PERFORMANCE DATA
// =============================================
$agent_report = [];
$agent_sql = "SELECT 
    a.account_id, a.first_name, a.last_name, a.email, a.phone_number,
    a.date_registered, a.is_active,
    ai.license_number, ai.years_experience, ai.is_approved,
    COUNT(DISTINCT p_active.property_ID) AS active_listings,
    COUNT(DISTINCT p_all.property_ID) AS total_listings,
    COUNT(DISTINCT fs.sale_id) AS total_sales,
    COALESCE(SUM(DISTINCT fs.final_sale_price), 0) AS total_revenue,
    COALESCE(SUM(DISTINCT ac.commission_amount), 0) AS total_commission,
    COUNT(DISTINCT tr.tour_id) AS total_tours,
    COUNT(DISTINCT CASE WHEN tr.request_status = 'Completed' THEN tr.tour_id END) AS completed_tours,
    GROUP_CONCAT(DISTINCT s.specialization_name SEPARATOR ', ') AS specializations
FROM accounts a
JOIN agent_information ai ON a.account_id = ai.account_id
LEFT JOIN property_log pl ON pl.account_id = a.account_id AND pl.action = 'CREATED'
LEFT JOIN property p_active ON p_active.property_ID = pl.property_id AND p_active.Status IN ('For Sale','For Rent') AND p_active.approval_status = 'approved'
LEFT JOIN property p_all ON p_all.property_ID = pl.property_id
LEFT JOIN finalized_sales fs ON fs.agent_id = a.account_id
LEFT JOIN agent_commissions ac ON fs.sale_id = ac.sale_id
LEFT JOIN tour_requests tr ON tr.agent_account_id = a.account_id
LEFT JOIN agent_specializations asp ON asp.agent_info_id = ai.agent_info_id
LEFT JOIN specializations s ON s.specialization_id = asp.specialization_id
WHERE a.role_id = 2
GROUP BY a.account_id
ORDER BY total_sales DESC, total_revenue DESC";
$agent_result = $conn->query($agent_sql);
if ($agent_result) { while ($row = $agent_result->fetch_assoc()) { $agent_report[] = $row; } }

// =============================================
// TOUR REQUESTS DATA
// =============================================
$tour_report = [];
$tour_sql = "SELECT 
    tr.tour_id, tr.user_name, tr.user_email, tr.user_phone,
    tr.tour_date, tr.tour_time, tr.tour_type, tr.message, tr.request_status,
    tr.confirmed_at, tr.completed_at, tr.decision_reason, tr.requested_at,
    p.property_ID, p.StreetAddress, p.City, p.PropertyType, p.ListingPrice,
    CONCAT(a.first_name, ' ', a.last_name) AS agent_name
FROM tour_requests tr
JOIN property p ON tr.property_id = p.property_ID
JOIN accounts a ON tr.agent_account_id = a.account_id
ORDER BY tr.requested_at DESC";
$tour_result = $conn->query($tour_sql);
if ($tour_result) { while ($row = $tour_result->fetch_assoc()) { $tour_report[] = $row; } }

// =============================================
// SYSTEM ACTIVITY LOG
// =============================================
$activity_report = [];
$activity_sql = "SELECT al.log_id, al.action, al.action_type, al.description, al.log_timestamp,
    CONCAT(a.first_name, ' ', a.last_name) AS admin_name, 'admin_log' AS log_source
FROM admin_logs al JOIN accounts a ON al.admin_account_id = a.account_id ORDER BY al.log_timestamp DESC";
$activity_result = $conn->query($activity_sql);
if ($activity_result) { while ($row = $activity_result->fetch_assoc()) { $activity_report[] = $row; } }

$status_logs = [];
$status_sql = "SELECT sl.log_id, sl.item_id, sl.item_type, sl.action, sl.reason_message, sl.log_timestamp,
    CONCAT(a.first_name, ' ', a.last_name) AS action_by
FROM status_log sl LEFT JOIN accounts a ON sl.action_by_account_id = a.account_id ORDER BY sl.log_timestamp DESC";
$status_result = $conn->query($status_sql);
if ($status_result) { while ($row = $status_result->fetch_assoc()) { $status_logs[] = $row; } }

$property_logs = [];
$proplog_sql = "SELECT pl.log_id, pl.property_id, pl.action, pl.log_timestamp, pl.reason_message,
    p.StreetAddress, p.City, CONCAT(a.first_name, ' ', a.last_name) AS action_by
FROM property_log pl JOIN property p ON pl.property_id = p.property_ID
JOIN accounts a ON pl.account_id = a.account_id ORDER BY pl.log_timestamp DESC";
$proplog_result = $conn->query($proplog_sql);
if ($proplog_result) { while ($row = $proplog_result->fetch_assoc()) { $property_logs[] = $row; } }

// =============================================
// KPI SUMMARY DATA
// =============================================
$total_properties = $conn->query("SELECT COUNT(*) as c FROM property")->fetch_assoc()['c'] ?? 0;
$active_properties = $conn->query("SELECT COUNT(*) as c FROM property WHERE Status IN ('For Sale','For Rent') AND approval_status = 'approved'")->fetch_assoc()['c'] ?? 0;
$sold_count = $conn->query("SELECT COUNT(*) as c FROM property WHERE Status = 'Sold'")->fetch_assoc()['c'] ?? 0;
$pending_sold = $conn->query("SELECT COUNT(*) as c FROM property WHERE Status = 'Pending Sold'")->fetch_assoc()['c'] ?? 0;
$for_sale_count = $conn->query("SELECT COUNT(*) as c FROM property WHERE Status = 'For Sale' AND approval_status = 'approved'")->fetch_assoc()['c'] ?? 0;
$for_rent_count = $conn->query("SELECT COUNT(*) as c FROM property WHERE Status = 'For Rent' AND approval_status = 'approved'")->fetch_assoc()['c'] ?? 0;
$pending_approval = $conn->query("SELECT COUNT(*) as c FROM property WHERE approval_status = 'pending'")->fetch_assoc()['c'] ?? 0;
$rejected_count = $conn->query("SELECT COUNT(*) as c FROM property WHERE approval_status = 'rejected'")->fetch_assoc()['c'] ?? 0;
$total_agents = $conn->query("SELECT COUNT(*) as c FROM accounts WHERE role_id = 2")->fetch_assoc()['c'] ?? 0;
$approved_agents = $conn->query("SELECT COUNT(*) as c FROM agent_information WHERE is_approved = 1")->fetch_assoc()['c'] ?? 0;
$pending_agents = $total_agents - $approved_agents;

$sales_data = $conn->query("SELECT COUNT(*) as total_sales, COALESCE(SUM(final_sale_price), 0) as total_revenue FROM finalized_sales")->fetch_assoc();
$total_sales = $sales_data['total_sales'] ?? 0;
$total_revenue = $sales_data['total_revenue'] ?? 0;
$commission_data = $conn->query("SELECT COALESCE(SUM(commission_amount), 0) as total_commission FROM agent_commissions")->fetch_assoc();
$total_commission = $commission_data['total_commission'] ?? 0;

// Rental KPIs
$rented_count = $conn->query("SELECT COUNT(*) as c FROM property WHERE Status = 'Rented'")->fetch_assoc()['c'] ?? 0;
$pending_rented = $conn->query("SELECT COUNT(*) as c FROM property WHERE Status = 'Pending Rented'")->fetch_assoc()['c'] ?? 0;
$active_leases = $conn->query("SELECT COUNT(*) as c FROM finalized_rentals WHERE lease_status IN ('Active','Renewed')")->fetch_assoc()['c'] ?? 0;
$rental_kpi = $conn->query("SELECT COUNT(*) as total_payments, COALESCE(SUM(CASE WHEN status='Confirmed' THEN payment_amount ELSE 0 END),0) as rental_revenue, COALESCE(SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END),0) as pending_payments FROM rental_payments")->fetch_assoc();
$total_rental_payments = $rental_kpi['total_payments'] ?? 0;
$rental_revenue = $rental_kpi['rental_revenue'] ?? 0;
$pending_rental_payments = $rental_kpi['pending_payments'] ?? 0;
$rental_commission_data = $conn->query("SELECT COALESCE(SUM(commission_amount), 0) as total FROM rental_commissions")->fetch_assoc();
$total_rental_commission = $rental_commission_data['total'] ?? 0;

// Rental chart: payments by month
$rental_by_month = [];
$rbm_q = $conn->query("SELECT DATE_FORMAT(payment_date, '%Y-%m') AS month_key, DATE_FORMAT(payment_date, '%b %Y') AS month_label, COUNT(*) AS cnt, SUM(CASE WHEN status='Confirmed' THEN payment_amount ELSE 0 END) AS revenue FROM rental_payments GROUP BY month_key ORDER BY month_key ASC");
if ($rbm_q) { while ($r = $rbm_q->fetch_assoc()) { $rental_by_month[] = $r; } }

$total_tours = $conn->query("SELECT COUNT(*) as c FROM tour_requests")->fetch_assoc()['c'] ?? 0;
$pending_tours = $conn->query("SELECT COUNT(*) as c FROM tour_requests WHERE request_status = 'Pending'")->fetch_assoc()['c'] ?? 0;
$confirmed_tours = $conn->query("SELECT COUNT(*) as c FROM tour_requests WHERE request_status = 'Confirmed'")->fetch_assoc()['c'] ?? 0;
$completed_tours = $conn->query("SELECT COUNT(*) as c FROM tour_requests WHERE request_status = 'Completed'")->fetch_assoc()['c'] ?? 0;
$cancelled_tours = $conn->query("SELECT COUNT(*) as c FROM tour_requests WHERE request_status IN ('Cancelled','Rejected')")->fetch_assoc()['c'] ?? 0;

$total_views = $conn->query("SELECT COALESCE(SUM(ViewsCount), 0) as c FROM property")->fetch_assoc()['c'] ?? 0;
$total_likes = $conn->query("SELECT COALESCE(SUM(Likes), 0) as c FROM property")->fetch_assoc()['c'] ?? 0;

// Chart data: Properties by type
$prop_by_type = [];
$type_q = $conn->query("SELECT PropertyType, COUNT(*) as cnt FROM property GROUP BY PropertyType ORDER BY cnt DESC");
if ($type_q) { while ($r = $type_q->fetch_assoc()) { $prop_by_type[$r['PropertyType']] = (int)$r['cnt']; } }

// Chart data: Properties by city
$prop_by_city = [];
$city_q = $conn->query("SELECT City, COUNT(*) as cnt FROM property GROUP BY City ORDER BY cnt DESC LIMIT 10");
if ($city_q) { while ($r = $city_q->fetch_assoc()) { $prop_by_city[$r['City']] = (int)$r['cnt']; } }

// Chart data: Listings over time (monthly)
$listings_by_month = [];
$lm_q = $conn->query("SELECT DATE_FORMAT(ListingDate, '%Y-%m') AS month_key, DATE_FORMAT(ListingDate, '%b %Y') AS month_label, COUNT(*) AS cnt FROM property WHERE ListingDate IS NOT NULL GROUP BY month_key ORDER BY month_key ASC");
if ($lm_q) { while ($r = $lm_q->fetch_assoc()) { $listings_by_month[] = $r; } }

// Chart data: Sales over time (monthly)
$sales_by_month = [];
$sm_q = $conn->query("SELECT DATE_FORMAT(sale_date, '%Y-%m') AS month_key, DATE_FORMAT(sale_date, '%b %Y') AS month_label, COUNT(*) AS cnt, SUM(final_sale_price) AS revenue FROM finalized_sales GROUP BY month_key ORDER BY month_key ASC");
if ($sm_q) { while ($r = $sm_q->fetch_assoc()) { $sales_by_month[] = $r; } }

// Chart data: Tour requests over time (monthly)
$tours_by_month = [];
$tm_q = $conn->query("SELECT DATE_FORMAT(requested_at, '%Y-%m') AS month_key, DATE_FORMAT(requested_at, '%b %Y') AS month_label, COUNT(*) AS cnt FROM tour_requests GROUP BY month_key ORDER BY month_key ASC");
if ($tm_q) { while ($r = $tm_q->fetch_assoc()) { $tours_by_month[] = $r; } }

// Chart data: Tour status breakdown
$tour_statuses = [];
$ts_q = $conn->query("SELECT request_status, COUNT(*) as cnt FROM tour_requests GROUP BY request_status");
if ($ts_q) { while ($r = $ts_q->fetch_assoc()) { $tour_statuses[$r['request_status']] = (int)$r['cnt']; } }

// Chart data: Property price ranges
$price_ranges = [
    '< 1M' => 0, '1M-5M' => 0, '5M-10M' => 0, '10M-20M' => 0, '20M+' => 0
];
$pr_q = $conn->query("SELECT ListingPrice FROM property WHERE approval_status = 'approved'");
if ($pr_q) {
    while ($r = $pr_q->fetch_assoc()) {
        $p = (float)$r['ListingPrice'];
        if ($p < 1000000) $price_ranges['< 1M']++;
        elseif ($p < 5000000) $price_ranges['1M-5M']++;
        elseif ($p < 10000000) $price_ranges['5M-10M']++;
        elseif ($p < 20000000) $price_ranges['10M-20M']++;
        else $price_ranges['20M+']++;
    }
}

// Chart data: Admin activity over time (daily, last 30 days)
$admin_activity_daily = [];
$aa_q = $conn->query("SELECT DATE(log_timestamp) AS day, COUNT(*) AS cnt FROM admin_logs WHERE log_timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY day ORDER BY day ASC");
if ($aa_q) { while ($r = $aa_q->fetch_assoc()) { $admin_activity_daily[] = $r; } }

// Chart data: Views per property (top 10)
$top_viewed = [];
$tv_q = $conn->query("SELECT StreetAddress, City, ViewsCount, Likes FROM property WHERE approval_status = 'approved' ORDER BY ViewsCount DESC LIMIT 10");
if ($tv_q) { while ($r = $tv_q->fetch_assoc()) { $top_viewed[] = $r; } }

// Filter options
$cities = [];
$city_result = $conn->query("SELECT DISTINCT City FROM property ORDER BY City ASC");
if ($city_result) { while ($row = $city_result->fetch_assoc()) { $cities[] = $row['City']; } }
$property_types = [];
$type_result_filter = $conn->query("SELECT type_name AS PropertyType FROM property_types ORDER BY type_name ASC");
if ($type_result_filter) { while ($row = $type_result_filter->fetch_assoc()) { $property_types[] = $row['PropertyType']; } }
$agents_list = [];
$agents_list_result = $conn->query("SELECT a.account_id, CONCAT(a.first_name, ' ', a.last_name) AS full_name FROM accounts a WHERE a.role_id = 2 ORDER BY a.first_name ASC");
if ($agents_list_result) { while ($row = $agents_list_result->fetch_assoc()) { $agents_list[] = $row; } }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - HomeEstate Realty</title>
    <link href="<?= ASSETS_CSS ?>bootstrap.min.css" rel="stylesheet">
    <link href="<?= ASSETS_CSS ?>inter-font.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= ASSETS_CSS ?>fontawesome-all.min.css">
    <link rel="stylesheet" href="<?= ASSETS_CSS ?>bootstrap-icons.min.css">
    <!-- Chart.js -->
    <script src="<?= ASSETS_JS ?>chart.umd.min.js"></script>
    <!-- Export Libraries -->
    <script src="<?= ASSETS_JS ?>jspdf.umd.min.js"></script>
    <script src="<?= ASSETS_JS ?>jspdf.plugin.autotable.min.js"></script>
    <script src="<?= ASSETS_JS ?>xlsx.full.min.js"></script>
    <style>
        :root {
            --primary-color: #161209;
            --secondary-color: #bc9e42;
            --accent-color: #a08636;
            --bg-light: #f8f9fa;
            --border-color: #e0e0e0;
        }

        body { font-family: 'Inter', sans-serif; background-color: var(--bg-light); color: #212529; }

        .admin-sidebar { background: linear-gradient(180deg, #161209 0%, #1f1a0f 100%); color: #fff; height: 100vh; position: fixed; top: 0; left: 0; width: 290px; overflow-y: auto; z-index: 1000; box-shadow: 2px 0 10px rgba(0,0,0,0.1); }
        .admin-content { margin-left: 290px; padding: 2rem; min-height: 100vh; }
        @media (max-width: 1200px) { .admin-content { margin-left: 0 !important; padding: 1.5rem; } }
        @media (max-width: 768px) { .admin-content { margin-left: 0 !important; padding: 1rem; } }

        .admin-content {
            --gold: #d4af37; --gold-light: #f4d03f; --gold-dark: #b8941f;
            --blue: #2563eb; --blue-light: #3b82f6; --blue-dark: #1e40af;
            --card-bg: #ffffff; --text-primary: #212529; --text-secondary: #6c757d;
        }

        /* ===== PAGE HEADER ===== */
        .page-header { background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; padding: 2rem 2.5rem; margin-bottom: 1.5rem; position: relative; overflow: hidden; }
        .page-header::before { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: radial-gradient(ellipse at top right, rgba(37,99,235,0.04) 0%, transparent 50%), radial-gradient(ellipse at bottom left, rgba(212,175,55,0.03) 0%, transparent 50%); pointer-events: none; }
        .page-header::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent); }
        .page-header-inner { position: relative; z-index: 2; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1.5rem; }
        .page-header h1 { font-size: 1.75rem; font-weight: 800; color: var(--text-primary); margin-bottom: 0.25rem; }
        .page-header .subtitle { color: var(--text-secondary); font-size: 0.95rem; }
        .page-header .header-badge { background: linear-gradient(135deg, var(--gold-dark), var(--gold)); color: #fff; font-size: 0.75rem; font-weight: 700; padding: 0.3rem 0.85rem; border-radius: 2px; text-transform: uppercase; letter-spacing: 0.5px; }

        /* ===== KPI STAT CARDS ===== */
        .kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
        .kpi-card { background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; padding: 1.25rem; position: relative; overflow: hidden; transition: all 0.3s ease; }
        .kpi-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--blue), transparent); opacity: 0; transition: opacity 0.3s ease; }
        .kpi-card:hover { border-color: rgba(37,99,235,0.25); box-shadow: 0 8px 32px rgba(37,99,235,0.08); transform: translateY(-3px); }
        .kpi-card:hover::before { opacity: 1; }
        .kpi-card .kpi-icon { width: 40px; height: 40px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; margin-bottom: 0.75rem; }
        .kpi-icon.gold { background: linear-gradient(135deg, rgba(212,175,55,0.08), rgba(212,175,55,0.15)); color: var(--gold-dark); border: 1px solid rgba(212,175,55,0.2); }
        .kpi-icon.blue { background: linear-gradient(135deg, rgba(37,99,235,0.06), rgba(37,99,235,0.12)); color: var(--blue); border: 1px solid rgba(37,99,235,0.15); }
        .kpi-icon.green { background: linear-gradient(135deg, rgba(34,197,94,0.06), rgba(34,197,94,0.12)); color: #16a34a; border: 1px solid rgba(34,197,94,0.15); }
        .kpi-icon.red { background: linear-gradient(135deg, rgba(239,68,68,0.06), rgba(239,68,68,0.12)); color: #dc2626; border: 1px solid rgba(239,68,68,0.15); }
        .kpi-icon.purple { background: linear-gradient(135deg, rgba(139,92,246,0.06), rgba(139,92,246,0.12)); color: #7c3aed; border: 1px solid rgba(139,92,246,0.15); }
        .kpi-icon.teal { background: linear-gradient(135deg, rgba(20,184,166,0.06), rgba(20,184,166,0.12)); color: #0d9488; border: 1px solid rgba(20,184,166,0.15); }
        .kpi-icon.amber { background: linear-gradient(135deg, rgba(245,158,11,0.06), rgba(245,158,11,0.12)); color: #d97706; border: 1px solid rgba(245,158,11,0.15); }
        .kpi-icon.cyan { background: linear-gradient(135deg, rgba(6,182,212,0.06), rgba(6,182,212,0.12)); color: #0891b2; border: 1px solid rgba(6,182,212,0.15); }
        .kpi-card .kpi-label { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: var(--text-secondary); margin-bottom: 0.25rem; }
        .kpi-card .kpi-value { font-size: 1.5rem; font-weight: 800; color: var(--text-primary); line-height: 1.2; }
        .kpi-card .kpi-sub { font-size: 0.72rem; color: var(--text-secondary); margin-top: 0.25rem; font-weight: 500; }

        /* ===== SECTION HEADER BAR ===== */
        .section-bar { background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; padding: 1rem 1.5rem; margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; position: relative; overflow: hidden; }
        .section-bar::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent); }
        .section-bar-title { font-size: 1.1rem; font-weight: 700; color: var(--text-primary); margin: 0; display: flex; align-items: center; gap: 0.5rem; }
        .section-bar-title i { color: var(--gold-dark); }

        /* ===== ACTION BUTTONS ===== */
        .action-buttons { display: flex; gap: 0.75rem; flex-wrap: wrap; }
        .btn-gold { background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 50%, var(--gold-dark) 100%); color: #fff; border: none; padding: 0.6rem 1.25rem; font-size: 0.85rem; font-weight: 700; border-radius: 4px; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; transition: all 0.3s ease; box-shadow: 0 4px 12px rgba(212,175,55,0.25); position: relative; overflow: hidden; cursor: pointer; }
        .btn-gold::before { content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent); transition: left 0.5s ease; }
        .btn-gold:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(212,175,55,0.35); color: #fff; }
        .btn-gold:hover::before { left: 100%; }
        .btn-outline-admin { background: var(--card-bg); color: var(--text-secondary); border: 1px solid #e2e8f0; padding: 0.6rem 1.25rem; font-size: 0.85rem; font-weight: 600; border-radius: 4px; display: inline-flex; align-items: center; gap: 0.5rem; transition: all 0.3s ease; cursor: pointer; text-decoration: none; }
        .btn-outline-admin:hover { border-color: var(--blue); color: var(--blue); background: rgba(37,99,235,0.03); }
        .btn-export-pdf { background: linear-gradient(135deg, #dc2626, #ef4444); color: #fff; border: none; padding: 0.6rem 1.25rem; font-size: 0.85rem; font-weight: 700; border-radius: 4px; display: inline-flex; align-items: center; gap: 0.5rem; transition: all 0.3s ease; cursor: pointer; }
        .btn-export-pdf:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(220,38,38,0.3); color: #fff; }
        .btn-export-excel { background: linear-gradient(135deg, #16a34a, #22c55e); color: #fff; border: none; padding: 0.6rem 1.25rem; font-size: 0.85rem; font-weight: 700; border-radius: 4px; display: inline-flex; align-items: center; gap: 0.5rem; transition: all 0.3s ease; cursor: pointer; }
        .btn-export-excel:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(22,163,74,0.3); color: #fff; }
        .filter-count-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 20px; height: 20px; padding: 0 6px; background: var(--blue); color: #fff; border-radius: 10px; font-size: 0.7rem; font-weight: 700; }

        /* ===== REPORT TABS ===== */
        .report-tabs { background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; overflow: hidden; margin-bottom: 1.5rem; position: relative; }
        .report-tabs::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent); z-index: 5; }
        .report-tabs .nav-tabs { border-bottom: 1px solid #e2e8f0; padding: 0.25rem 0.5rem 0; gap: 0.25rem; background: linear-gradient(180deg, #fafbfc, var(--card-bg)); }
        .report-tabs .nav-link { border: none; border-bottom: 3px solid transparent; background: transparent; color: var(--text-secondary); font-weight: 600; font-size: 0.85rem; padding: 0.85rem 1.25rem; border-radius: 0; }
        .report-tabs .nav-link:hover { color: var(--text-primary); background: rgba(37,99,235,0.03); border-bottom-color: rgba(37,99,235,0.2); }
        .report-tabs .nav-link.active { color: var(--gold-dark); background: rgba(212,175,55,0.03); border-bottom-color: var(--gold); }
        .tab-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 22px; height: 22px; padding: 0 0.4rem; border-radius: 2px; font-size: 0.7rem; font-weight: 700; margin-left: 0.5rem; }
        .badge-gold { background: rgba(212,175,55,0.1); color: #b8941f; border: 1px solid rgba(212,175,55,0.15); }
        .badge-blue { background: rgba(37,99,235,0.1); color: #2563eb; border: 1px solid rgba(37,99,235,0.15); }
        .badge-green { background: rgba(34,197,94,0.1); color: #16a34a; border: 1px solid rgba(34,197,94,0.15); }
        .badge-cyan { background: rgba(6,182,212,0.1); color: #0891b2; border: 1px solid rgba(6,182,212,0.15); }
        .badge-purple { background: rgba(124,58,237,0.1); color: #7c3aed; border: 1px solid rgba(124,58,237,0.15); }
        .badge-amber { background: rgba(245,158,11,0.1); color: #d97706; border: 1px solid rgba(245,158,11,0.15); }
        .tab-content { padding: 1.5rem; }

        /* ===== CHART CARDS ===== */
        .chart-grid { display: grid; gap: 1.5rem; margin-bottom: 1.5rem; }
        .chart-grid-2 { grid-template-columns: 1fr 1fr; }
        .chart-grid-3 { grid-template-columns: 1fr 1fr 1fr; }
        .chart-grid-overview { grid-template-columns: 2fr 1fr 1fr; }
        .chart-grid-2-1 { grid-template-columns: 2fr 1fr; }
        .chart-grid-1-2 { grid-template-columns: 1fr 2fr; }

        .chart-card { background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; padding: 1.5rem; position: relative; overflow: hidden; transition: all 0.3s ease; }
        .chart-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--blue), transparent); opacity: 0; transition: opacity 0.3s ease; }
        .chart-card:hover { border-color: rgba(37,99,235,0.2); box-shadow: 0 8px 32px rgba(37,99,235,0.06); }
        .chart-card:hover::before { opacity: 1; }

        .chart-card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem; padding-bottom: 0.75rem; border-bottom: 1px solid #f1f5f9; }
        .chart-card-title { font-size: 0.85rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem; }
        .chart-card-title i { color: var(--gold-dark); font-size: 1rem; }
        .chart-card-subtitle { font-size: 0.72rem; color: var(--text-secondary); font-weight: 500; }
        .chart-card-badge { font-size: 0.68rem; font-weight: 700; padding: 0.2rem 0.5rem; border-radius: 2px; text-transform: uppercase; letter-spacing: 0.3px; }

        .chart-container { position: relative; width: 100%; }
        .chart-container.h-250 { height: 250px; }
        .chart-container.h-280 { height: 280px; }
        .chart-container.h-300 { height: 300px; }
        .chart-container.h-320 { height: 320px; }
        .chart-container.h-350 { height: 350px; }

        /* KPI inside chart cards */
        .chart-kpi-row { display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem; }
        .chart-kpi { text-align: center; flex: 1; min-width: 80px; padding: 0.5rem; background: #f8fafc; border-radius: 4px; border: 1px solid #f1f5f9; }
        .chart-kpi-val { font-size: 1.25rem; font-weight: 800; color: var(--text-primary); line-height: 1.2; }
        .chart-kpi-val.gold { color: var(--gold-dark); }
        .chart-kpi-val.blue { color: var(--blue); }
        .chart-kpi-val.green { color: #16a34a; }
        .chart-kpi-val.red { color: #dc2626; }
        .chart-kpi-label { font-size: 0.65rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-secondary); margin-top: 0.15rem; }

        /* ===== DATA TABLES ===== */
        .report-table-wrapper { overflow-x: auto; border-radius: 6px; border: 1px solid rgba(37,99,235,0.1); box-shadow: 0 1px 4px rgba(0,0,0,0.04); background: #fff; }
        .report-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 0.82rem; }
        .report-table thead th { position: sticky; top: 0; z-index: 3; background: linear-gradient(180deg, #0f172a 0%, #1e293b 100%); color: rgba(255,255,255,0.75); font-weight: 700; font-size: 0.68rem; text-transform: uppercase; letter-spacing: 1px; padding: 0.9rem 1rem; border-bottom: 2px solid var(--gold-dark); white-space: nowrap; border-right: 1px solid rgba(255,255,255,0.06); }
        .report-table thead th:first-child { border-radius: 6px 0 0 0; width: 44px; text-align: center; color: rgba(212,175,55,0.7); }
        .report-table thead th:last-child { border-radius: 0 6px 0 0; border-right: none; }
        .report-table tbody td { padding: 0.78rem 1rem; border-bottom: 1px solid #f1f5f9; color: var(--text-primary); vertical-align: middle; border-right: 1px solid #f8fafc; }
        .report-table tbody td:first-child { text-align: center; font-weight: 700; font-size: 0.72rem; color: #94a3b8; background: #fafbfc; border-right: 2px solid rgba(212,175,55,0.15); }
        .report-table tbody td:last-child { border-right: none; }
        .report-table tbody tr:nth-child(even) td { background: #fafbfc; }
        .report-table tbody tr:nth-child(even) td:first-child { background: #f4f6f8; }
        .report-table tbody tr:hover td { background: rgba(212,175,55,0.04) !important; }
        .report-table tbody tr:hover td:first-child { background: rgba(212,175,55,0.08) !important; color: var(--gold-dark); }
        .report-table tbody tr:last-child td { border-bottom: none; }
        .report-table tbody tr:last-child td:first-child { border-radius: 0 0 0 6px; }
        .report-table tbody tr:last-child td:last-child { border-radius: 0 0 6px 0; }

        .status-pill { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.22rem 0.65rem; border-radius: 20px; font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; }
        .status-pill.for-sale { background: rgba(37,99,235,0.1); color: #2563eb; }
        .status-pill.for-rent { background: rgba(212,175,55,0.1); color: #b8941f; }
        .status-pill.sold { background: rgba(100,116,139,0.1); color: #64748b; }
        .status-pill.pending-sold { background: rgba(6,182,212,0.1); color: #0891b2; }
        .status-pill.approved { background: rgba(34,197,94,0.1); color: #16a34a; }
        .status-pill.pending { background: rgba(245,158,11,0.1); color: #d97706; }
        .status-pill.rejected { background: rgba(239,68,68,0.1); color: #dc2626; }
        .status-pill.confirmed { background: rgba(37,99,235,0.1); color: #2563eb; }
        .status-pill.completed { background: rgba(34,197,94,0.1); color: #16a34a; }
        .status-pill.cancelled { background: rgba(239,68,68,0.1); color: #dc2626; }
        .status-pill.expired { background: rgba(100,116,139,0.1); color: #64748b; }
        .status-pill.paid { background: rgba(34,197,94,0.1); color: #16a34a; }
        .status-pill.calculated { background: rgba(37,99,235,0.1); color: #2563eb; }
        .status-pill.login { background: rgba(37,99,235,0.1); color: #2563eb; }
        .status-pill.logout { background: rgba(100,116,139,0.1); color: #64748b; }
        .status-pill.created { background: rgba(34,197,94,0.1); color: #16a34a; }
        .status-pill.updated { background: rgba(37,99,235,0.1); color: #2563eb; }
        .status-pill.deleted { background: rgba(239,68,68,0.1); color: #dc2626; }
        .status-pill.agent { background: rgba(6,182,212,0.1); color: #0891b2; }
        .status-pill.property { background: rgba(212,175,55,0.1); color: #b8941f; }
        .status-pill.private { background: rgba(100,116,139,0.1); color: #64748b; }
        .status-pill.public { background: rgba(37,99,235,0.1); color: #2563eb; }
        .price-text { font-weight: 700; color: var(--gold-dark); }

        /* ===== PAGINATION ===== */
        .report-pagination { display: flex; align-items: center; justify-content: space-between; padding: 1rem 0 0; border-top: 1px solid #e2e8f0; margin-top: 1rem; flex-wrap: wrap; gap: 0.75rem; }
        .pagination-info { font-size: 0.8rem; color: var(--text-secondary); font-weight: 500; }
        .pagination-controls { display: flex; gap: 0.25rem; }
        .page-btn { width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border: 1px solid #e2e8f0; background: #fff; color: var(--text-secondary); border-radius: 4px; font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: all 0.2s ease; }
        .page-btn:hover:not(:disabled):not(.active) { border-color: var(--blue); color: var(--blue); background: rgba(37,99,235,0.03); }
        .page-btn.active { background: linear-gradient(135deg, var(--gold-dark), var(--gold)); color: #fff; border-color: var(--gold); }
        .page-btn:disabled { opacity: 0.4; cursor: not-allowed; }

        /* ===== EMPTY STATE ===== */
        .empty-state { text-align: center; padding: 3rem 1rem; color: var(--text-secondary); }
        .empty-state i { font-size: 3rem; color: rgba(37,99,235,0.15); margin-bottom: 1rem; display: block; }
        .empty-state h4 { font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem; }

        /* ===== FILTER SIDEBAR ===== */
        .filter-sidebar { position: fixed; top: 0; right: 0; width: 100%; height: 100%; z-index: 9999; pointer-events: none; }
        .filter-sidebar.active { pointer-events: all; }
        .filter-sidebar-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.4); opacity: 0; transition: opacity 0.2s ease; pointer-events: none; }
        .filter-sidebar.active .filter-sidebar-overlay { opacity: 1; pointer-events: all; }
        .filter-sidebar-content { position: absolute; top: 0; right: 0; width: 480px; max-width: 90vw; height: 100%; background: #ffffff; border-left: 1px solid rgba(37,99,235,0.15); box-shadow: -8px 0 32px rgba(15,23,42,0.1); transform: translateX(100%); transition: transform 0.25s ease; display: flex; flex-direction: column; overflow: hidden; }
        .filter-sidebar.active .filter-sidebar-content { transform: translateX(0); }
        .filter-header { background: linear-gradient(135deg, #0f172a, #1e293b); color: #fff; padding: 1.5rem 2rem; display: flex; align-items: center; justify-content: space-between; position: relative; overflow: hidden; }
        .filter-header::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, var(--gold), var(--blue)); }
        .filter-header h4 { font-weight: 700; font-size: 1.15rem; display: flex; align-items: center; gap: 0.75rem; margin: 0; }
        .filter-header h4 i { color: var(--gold); font-size: 1.3rem; }
        .btn-close-filter { background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); color: #fff; width: 36px; height: 36px; border-radius: 4px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s ease; font-size: 1rem; }
        .btn-close-filter:hover { background: rgba(239,68,68,0.2); border-color: rgba(239,68,68,0.4); }
        .filter-body { flex: 1; overflow-y: auto; padding: 1.5rem; background: #f8fafc; }
        .filter-section { background: #fff; border-radius: 4px; padding: 1.25rem; margin-bottom: 1rem; border: 1px solid #e2e8f0; }
        .filter-section-title { font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: #334155; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; padding-bottom: 0.75rem; border-bottom: 1px solid #e2e8f0; }
        .filter-section-title i { color: var(--gold-dark); font-size: 0.95rem; }
        .filter-select { width: 100%; padding: 0.55rem 0.85rem; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 0.85rem; font-weight: 500; color: #334155; background: #fff; transition: all 0.2s ease; cursor: pointer; }
        .filter-select:focus { outline: none; border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
        .filter-chips { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .filter-chip { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.4rem 0.75rem; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 0.8rem; font-weight: 500; color: #475569; cursor: pointer; transition: all 0.2s ease; background: #fff; }
        .filter-chip:hover { border-color: var(--gold); color: var(--gold-dark); }
        .filter-chip:has(input:checked) { background: rgba(212,175,55,0.08); border-color: var(--gold); color: var(--gold-dark); }
        .filter-chip input[type="checkbox"] { width: 14px; height: 14px; accent-color: var(--gold); cursor: pointer; }
        .filter-results-summary { background: linear-gradient(135deg, rgba(37,99,235,0.04), rgba(212,175,55,0.04)); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; padding: 1rem 1.25rem; display: flex; align-items: center; gap: 0.75rem; margin-top: 1rem; }
        .filter-results-summary i { color: var(--gold); font-size: 1.2rem; }
        .filter-results-count { font-size: 1.2rem; font-weight: 800; color: var(--text-primary); }
        .filter-results-label { font-size: 0.75rem; color: var(--text-secondary); }
        .filter-footer { padding: 1.25rem 1.5rem; border-top: 1px solid #e2e8f0; display: flex; gap: 0.75rem; background: #fff; }
        .filter-footer .btn { flex: 1; padding: 0.65rem 1rem; font-size: 0.85rem; font-weight: 700; border-radius: 4px; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; }
        .filter-footer .btn-outline-secondary { background: #fff; border: 1px solid #e2e8f0; color: var(--text-secondary); }
        .filter-footer .btn-outline-secondary:hover { border-color: #cbd5e1; background: #f8fafc; color: #334155; }
        .filter-footer .btn-primary { background: linear-gradient(135deg, var(--gold-dark), var(--gold)); border: none; color: #fff; }
        .filter-footer .btn-primary:hover { box-shadow: 0 4px 12px rgba(212,175,55,0.25); }

        /* ===== EXPORT PREVIEW MODAL ===== */
        .export-modal-overlay { position: fixed; inset: 0; background: rgba(15,23,42,0.6); backdrop-filter: blur(4px); z-index: 10000; display: none; align-items: center; justify-content: center; padding: 1.5rem; animation: fadeIn 0.2s ease; }
        .export-modal-overlay.active { display: flex; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .export-modal { background: #fff; border-radius: 8px; width: 100%; max-width: 1200px; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 25px 60px rgba(0,0,0,0.3), 0 0 0 1px rgba(255,255,255,0.05); overflow: hidden; animation: slideUp 0.25s ease; }
        .export-modal-header { background: linear-gradient(135deg, #0f172a, #1e293b); color: #fff; padding: 1.25rem 2rem; display: flex; align-items: center; justify-content: space-between; position: relative; }
        .export-modal-header::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, var(--gold), var(--blue), var(--gold)); }
        .export-modal-body { flex: 1; overflow: auto; padding: 1.5rem 2rem; background: #f8fafc; }
        .export-preview-table { width: 100%; border-collapse: collapse; font-size: 0.78rem; background: #fff; border: 1px solid #e2e8f0; border-radius: 4px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
        .export-preview-table thead th { background: linear-gradient(180deg, #f8fafc, #f1f5f9); color: var(--text-secondary); font-weight: 700; font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.5px; padding: 0.75rem 0.85rem; border-bottom: 2px solid #e2e8f0; white-space: nowrap; position: sticky; top: 0; z-index: 2; }
        .export-preview-table tbody td { padding: 0.6rem 0.85rem; border-bottom: 1px solid #f1f5f9; color: var(--text-primary); }
        .export-preview-table tbody tr:hover { background: rgba(37,99,235,0.02); }
        .export-preview-table tbody tr:nth-child(even) { background: #fafbfc; }
        .export-preview-table tbody tr:nth-child(even):hover { background: rgba(37,99,235,0.03); }
        .export-modal-footer { padding: 1.25rem 2rem; border-top: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; background: #fff; flex-wrap: wrap; gap: 0.75rem; }
        .export-modal-footer .export-info { font-size: 0.82rem; color: var(--text-secondary); font-weight: 500; }
        .export-modal-footer .export-info strong { color: var(--text-primary); }

        /* ===== RESPONSIVE ===== */

        /* 1600px — large desktops */
        @media (max-width: 1600px) {
            .kpi-grid { grid-template-columns: repeat(3, 1fr); }
        }

        /* 1400px — medium desktops */
        @media (max-width: 1400px) {
            .kpi-grid { grid-template-columns: repeat(3, 1fr); }
            .chart-grid-3 { grid-template-columns: 1fr 1fr; }
            .chart-grid-overview { grid-template-columns: 1fr 1fr; }
        }

        /* 1100px — small desktops / large tablets */
        @media (max-width: 1100px) {
            .chart-grid-overview { grid-template-columns: 1fr 1fr; }
        }

        /* 992px — tablets */
        @media (max-width: 992px) {
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .chart-grid-2, .chart-grid-2-1, .chart-grid-1-2 { grid-template-columns: 1fr; }
            .chart-grid-3, .chart-grid-overview { grid-template-columns: 1fr; }
            .chart-container.h-280 { height: 240px; }
            .chart-container.h-300 { height: 260px; }
            .chart-container.h-320 { height: 280px; }
            .chart-container.h-350 { height: 300px; }
            .page-header { padding: 1.5rem 1.75rem; }
            .chart-card { padding: 1.25rem; }
            .tab-content { padding: 1.25rem; }
        }

        /* 768px — large phones / small tablets */
        @media (max-width: 768px) {
            .admin-content { padding: 1rem; }
            .page-header { padding: 1.25rem 1rem; }
            .page-header h1 { font-size: 1.3rem; }
            .page-header .subtitle { font-size: 0.85rem; }
            .page-header-inner { flex-direction: column; align-items: flex-start; gap: 0.75rem; }
            .kpi-grid { grid-template-columns: 1fr 1fr; gap: 0.75rem; }
            .kpi-card { padding: 1rem; }
            .kpi-card .kpi-value { font-size: 1.25rem; }
            .section-bar { flex-direction: column; align-items: flex-start; gap: 0.75rem; }
            .action-buttons { width: 100%; flex-wrap: wrap; gap: 0.5rem; }
            .action-buttons > * { flex: 1 1 auto; min-width: 0; justify-content: center; font-size: 0.8rem; padding: 0.55rem 0.85rem; }
            .report-tabs .nav-tabs { overflow-x: auto; flex-wrap: nowrap; -webkit-overflow-scrolling: touch; padding-bottom: 0; }
            .report-tabs .nav-link { padding: 0.65rem 0.85rem; font-size: 0.8rem; white-space: nowrap; }
            .tab-content { padding: 1rem; }
            .chart-card { padding: 1rem; }
            .chart-card-header { flex-wrap: wrap; gap: 0.5rem; }
            .chart-container.h-250 { height: 200px; }
            .chart-container.h-280 { height: 210px; }
            .chart-container.h-300 { height: 220px; }
            .chart-container.h-320 { height: 240px; }
            .chart-container.h-350 { height: 260px; }
            .chart-kpi-row { gap: 0.5rem; }
            .chart-kpi { padding: 0.4rem; min-width: 60px; }
            .chart-kpi-val { font-size: 1rem; }
            .report-table-wrapper { max-height: 400px !important; }
            .report-pagination { flex-direction: column; align-items: flex-start; gap: 0.5rem; }
            .export-modal-footer { flex-direction: column; align-items: stretch; gap: 0.75rem; }
            .export-modal-footer .d-flex { flex-wrap: wrap; justify-content: stretch; }
            .export-modal-footer .d-flex > * { flex: 1 1 auto; justify-content: center; }
            .export-modal { max-height: 95vh; }
            .filter-sidebar-content { width: 100%; max-width: 100%; }
        }

        /* 576px — phones */
        @media (max-width: 576px) {
            .admin-content { padding: 0.75rem; }
            .page-header { padding: 1rem; }
            .page-header h1 { font-size: 1.15rem; }
            .kpi-grid { grid-template-columns: 1fr 1fr; gap: 0.5rem; }
            .kpi-card { padding: 0.85rem; }
            .kpi-card .kpi-value { font-size: 1.1rem; }
            .kpi-card .kpi-label { font-size: 0.65rem; }
            .kpi-card .kpi-sub { font-size: 0.65rem; }
            .kpi-card .kpi-icon { width: 34px; height: 34px; font-size: 0.95rem; }
            .chart-grid { gap: 0.75rem; }
            .chart-card { padding: 0.85rem; }
            .chart-card-title { font-size: 0.78rem; }
            .chart-container.h-250 { height: 180px; }
            .chart-container.h-280 { height: 190px; }
            .chart-container.h-300 { height: 200px; }
            .chart-container.h-320 { height: 210px; }
            .chart-container.h-350 { height: 220px; }
            .report-tabs .nav-link { padding: 0.55rem 0.7rem; font-size: 0.75rem; }
            .tab-badge { display: none; }
            .tab-content { padding: 0.75rem; }
            .action-buttons > * { font-size: 0.75rem; padding: 0.5rem 0.6rem; }
            .section-bar { padding: 0.85rem 1rem; }
            .section-bar-title { font-size: 0.95rem; }
            .filter-body { padding: 1rem; }
            .export-modal-body { padding: 1rem; }
            .export-modal-header { padding: 1rem 1.25rem; }
            .export-modal-footer { padding: 1rem 1.25rem; }
        }

        /* 400px — very small phones */
        @media (max-width: 400px) {
            .kpi-grid { grid-template-columns: 1fr 1fr; }
            .kpi-card .kpi-value { font-size: 1rem; }
            .page-header h1 { font-size: 1rem; }
            .report-tabs .nav-link { padding: 0.5rem 0.6rem; font-size: 0.72rem; }
        }

        @media print {
            .admin-sidebar, .section-bar, .filter-sidebar, .export-modal-overlay { display: none !important; }
            .admin-content { margin-left: 0 !important; padding: 0.5rem !important; }
        }

        /* ================================================================
           SKELETON SCREEN SYSTEM — Client-Side Rendering (CSR) Pattern
           Matches: reports.php
           ================================================================ */
        @keyframes sk-shimmer { 0% { background-position: -800px 0; } 100% { background-position: 800px 0; } }
        .sk-shimmer {
            background: linear-gradient(90deg, #f0f0f0 25%, #e8e8e8 50%, #f0f0f0 75%);
            background-size: 800px 100%;
            animation: sk-shimmer 1.4s ease-in-out infinite;
            border-radius: 4px;
        }
        #page-content { display: none; }

        .sk-page-header { background:#fff; border-radius:4px; padding:1.25rem 1.75rem; margin-bottom:1.5rem; border:1px solid rgba(37,99,235,0.08); position:relative; overflow:hidden; }
        .sk-kpi-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:1rem; margin-bottom:1.5rem; }
        .sk-kpi-card { background:#fff; border-radius:4px; border:1px solid rgba(37,99,235,0.08); padding:1.25rem; display:flex; flex-direction:column; gap:0.6rem; }
        .sk-kpi-icon { width:40px; height:40px; border-radius:4px; flex-shrink:0; }
        .sk-chart-row { display:grid; gap:1.5rem; margin-bottom:1.5rem; }
        .sk-chart-row-3   { grid-template-columns:1fr 1fr 1fr; }
        .sk-chart-row-2   { grid-template-columns:1fr 1fr; }
        .sk-chart-row-ov  { grid-template-columns:2fr 1fr 1fr; }
        .sk-chart-card { background:#fff; border-radius:4px; border:1px solid rgba(37,99,235,0.08); padding:1.5rem; display:flex; flex-direction:column; gap:1rem; }
        .sk-chart-header { display:flex; justify-content:space-between; align-items:center; padding-bottom:0.75rem; border-bottom:1px solid #f1f5f9; }
        .sk-chart-area { border-radius:4px; }
        .sk-action-bar { background:#fff; border-radius:4px; border:1px solid rgba(37,99,235,0.08); padding:1rem 1.5rem; margin-bottom:1.5rem; display:flex; align-items:center; justify-content:space-between; overflow:hidden; min-height:64px; position:relative; }
        .sk-tabs { background:#fff; border-radius:4px; border:1px solid rgba(37,99,235,0.08); padding:0.875rem 1.5rem; margin-bottom:0; display:flex; align-items:center; gap:0.75rem; min-height:56px; position:relative; overflow:hidden; }
        .sk-table-wrap { background:#fff; border-radius:4px; border:1px solid rgba(37,99,235,0.08); overflow:hidden; }
        .sk-table-head { background: linear-gradient(180deg,#0f172a,#1e293b); padding:0.9rem 1rem; display:flex; gap:0.75rem; align-items:center; }
        .sk-table-row { display:flex; align-items:center; gap:0.75rem; padding:0.78rem 1rem; border-bottom:1px solid #f1f5f9; }
        .sk-line { display:block; border-radius:4px; }
        @media (max-width:1400px) { .sk-kpi-grid { grid-template-columns:repeat(3,1fr); } .sk-chart-row-ov,.sk-chart-row-3 { grid-template-columns:1fr 1fr; } }
        @media (max-width:992px)  { .sk-kpi-grid { grid-template-columns:repeat(2,1fr); } .sk-chart-row-2,.sk-chart-row-ov,.sk-chart-row-3 { grid-template-columns:1fr; } }
        @media (max-width:768px)  { .sk-kpi-grid { grid-template-columns:1fr 1fr; gap:0.75rem; } }
        @media (max-width:576px)  { .sk-kpi-grid { grid-template-columns:1fr 1fr; gap:0.5rem; } }
    </style>
</head>
<body>

    <?php include 'admin_sidebar.php'; ?>
    <?php include 'admin_navbar.php'; ?>
    <?php if (isset($conn) && $conn instanceof mysqli) { $conn->close(); } ?>

    <div class="admin-content">

        <noscript><style>
            #sk-screen    { display: none !important; }
            #page-content { display: block !important; opacity: 1 !important; }
        </style></noscript>

        <!-- ══════════════════════════════════════════════════════
             SKELETON SCREEN — visible on first paint
        ════════════════════════════════════════════════════════ -->
        <div id="sk-screen" role="presentation" aria-hidden="true">

            <!-- Page Header -->
            <div class="sk-page-header">
                <div class="sk-line sk-shimmer" style="width:200px;height:22px;margin-bottom:10px;"></div>
                <div class="sk-line sk-shimmer" style="width:420px;height:13px;"></div>
            </div>

            <!-- 6 KPI Cards -->
            <div class="sk-kpi-grid">
                <?php for ($i = 0; $i < 6; $i++): ?>
                <div class="sk-kpi-card">
                    <div class="sk-kpi-icon sk-shimmer"></div>
                    <div class="sk-line sk-shimmer" style="width:70px;height:11px;"></div>
                    <div class="sk-line sk-shimmer" style="width:50px;height:26px;"></div>
                    <div class="sk-line sk-shimmer" style="width:90px;height:11px;"></div>
                </div>
                <?php endfor; ?>
            </div>

            <!-- Chart Row 1: Content Overview (2fr) + By Type (1fr) + Status (1fr) -->
            <div class="sk-chart-row sk-chart-row-ov">
                <div class="sk-chart-card">
                    <div class="sk-chart-header">
                        <div class="sk-line sk-shimmer" style="width:160px;height:14px;"></div>
                        <div class="sk-shimmer" style="width:60px;height:20px;border-radius:3px;"></div>
                    </div>
                    <div style="display:flex;gap:1rem;margin-bottom:0.5rem;">
                        <div class="sk-shimmer" style="flex:1;height:50px;border-radius:4px;"></div>
                        <div class="sk-shimmer" style="flex:1;height:50px;border-radius:4px;"></div>
                        <div class="sk-shimmer" style="flex:1;height:50px;border-radius:4px;"></div>
                    </div>
                    <div class="sk-chart-area sk-shimmer" style="height:280px;width:100%;"></div>
                </div>
                <div class="sk-chart-card">
                    <div class="sk-chart-header">
                        <div class="sk-line sk-shimmer" style="width:140px;height:14px;"></div>
                    </div>
                    <div class="sk-chart-area sk-shimmer" style="height:300px;width:100%;"></div>
                </div>
                <div class="sk-chart-card">
                    <div class="sk-chart-header">
                        <div class="sk-line sk-shimmer" style="width:120px;height:14px;"></div>
                    </div>
                    <div class="sk-chart-area sk-shimmer" style="height:300px;width:100%;"></div>
                </div>
            </div>

            <!-- Chart Row 2: Top Viewed (1fr) + Price Distribution (1fr) -->
            <div class="sk-chart-row sk-chart-row-2">
                <div class="sk-chart-card">
                    <div class="sk-chart-header">
                        <div class="sk-line sk-shimmer" style="width:180px;height:14px;"></div>
                        <div class="sk-shimmer" style="width:55px;height:20px;border-radius:3px;"></div>
                    </div>
                    <div class="sk-chart-area sk-shimmer" style="height:300px;width:100%;"></div>
                </div>
                <div class="sk-chart-card">
                    <div class="sk-chart-header">
                        <div class="sk-line sk-shimmer" style="width:160px;height:14px;"></div>
                        <div class="sk-shimmer" style="width:65px;height:20px;border-radius:3px;"></div>
                    </div>
                    <div class="sk-chart-area sk-shimmer" style="height:300px;width:100%;"></div>
                </div>
            </div>

            <!-- Chart Row 3: Tour Status (1fr) + Tours Over Time (1fr) + By City (1fr) -->
            <div class="sk-chart-row sk-chart-row-3">
                <div class="sk-chart-card">
                    <div class="sk-chart-header">
                        <div class="sk-line sk-shimmer" style="width:110px;height:14px;"></div>
                    </div>
                    <div style="display:flex;gap:0.75rem;margin-bottom:0.5rem;">
                        <div class="sk-shimmer" style="flex:1;height:44px;border-radius:4px;"></div>
                        <div class="sk-shimmer" style="flex:1;height:44px;border-radius:4px;"></div>
                        <div class="sk-shimmer" style="flex:1;height:44px;border-radius:4px;"></div>
                    </div>
                    <div class="sk-chart-area sk-shimmer" style="height:250px;width:100%;"></div>
                </div>
                <div class="sk-chart-card">
                    <div class="sk-chart-header">
                        <div class="sk-line sk-shimmer" style="width:150px;height:14px;"></div>
                        <div class="sk-shimmer" style="width:65px;height:20px;border-radius:3px;"></div>
                    </div>
                    <div class="sk-chart-area sk-shimmer" style="height:300px;width:100%;"></div>
                </div>
                <div class="sk-chart-card">
                    <div class="sk-chart-header">
                        <div class="sk-line sk-shimmer" style="width:160px;height:14px;"></div>
                        <div class="sk-shimmer" style="width:55px;height:20px;border-radius:3px;"></div>
                    </div>
                    <div class="sk-chart-area sk-shimmer" style="height:300px;width:100%;"></div>
                </div>
            </div>

            <!-- Chart Row 4: Agent Performance (1fr) + Admin Activity (1fr) -->
            <div class="sk-chart-row sk-chart-row-2">
                <div class="sk-chart-card">
                    <div class="sk-chart-header">
                        <div class="sk-line sk-shimmer" style="width:170px;height:14px;"></div>
                        <div class="sk-shimmer" style="width:60px;height:20px;border-radius:3px;"></div>
                    </div>
                    <div class="sk-chart-area sk-shimmer" style="height:320px;width:100%;"></div>
                </div>
                <div class="sk-chart-card">
                    <div class="sk-chart-header">
                        <div class="sk-line sk-shimmer" style="width:150px;height:14px;"></div>
                        <div class="sk-shimmer" style="width:65px;height:20px;border-radius:3px;"></div>
                    </div>
                    <div class="sk-chart-area sk-shimmer" style="height:320px;width:100%;"></div>
                </div>
            </div>

            <!-- Section Bar (action bar) -->
            <div class="sk-action-bar">
                <div class="sk-line sk-shimmer" style="width:200px;height:20px;"></div>
                <div style="display:flex;gap:0.75rem;">
                    <div class="sk-shimmer" style="width:110px;height:36px;border-radius:4px;"></div>
                    <div class="sk-shimmer" style="width:115px;height:36px;border-radius:4px;"></div>
                    <div class="sk-shimmer" style="width:125px;height:36px;border-radius:4px;"></div>
                </div>
            </div>

            <!-- 5 Report Tabs -->
            <div class="sk-tabs">
                <div class="sk-shimmer" style="width:90px;height:20px;border-radius:3px;"></div>
                <div class="sk-shimmer" style="width:60px;height:20px;border-radius:3px;"></div>
                <div class="sk-shimmer" style="width:140px;height:20px;border-radius:3px;"></div>
                <div class="sk-shimmer" style="width:115px;height:20px;border-radius:3px;"></div>
                <div class="sk-shimmer" style="width:130px;height:20px;border-radius:3px;"></div>
            </div>

            <!-- Data Table Skeleton -->
            <div class="sk-table-wrap">
                <div class="sk-table-head">
                    <?php for ($i = 0; $i < 8; $i++): ?>
                    <div class="sk-shimmer" style="flex:1;height:12px;opacity:0.35;"></div>
                    <?php endfor; ?>
                </div>
                <?php for ($i = 0; $i < 8; $i++): ?>
                <div class="sk-table-row">
                    <div class="sk-shimmer" style="width:28px;height:12px;flex-shrink:0;"></div>
                    <div class="sk-shimmer" style="flex:2;height:13px;"></div>
                    <div class="sk-shimmer" style="flex:1;height:13px;"></div>
                    <div class="sk-shimmer" style="flex:1;height:13px;"></div>
                    <div class="sk-shimmer" style="width:70px;height:20px;border-radius:20px;"></div>
                    <div class="sk-shimmer" style="flex:1;height:13px;"></div>
                </div>
                <?php endfor; ?>
            </div>

        </div><!-- /#sk-screen -->

        <div id="page-content">

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-inner">
                <div>
                    <h1><i class="" style="color:var(--gold-dark);"></i>Reports & Analytics</h1>
                    <p class="subtitle">Comprehensive analytics dashboard with charts, data tables, and export tools</p>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="header-badge"><i class="bi bi-calendar3 me-1"></i> <?php echo date('F d, Y'); ?></span>
                </div>
            </div>
        </div>

        <!-- KPI Summary -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-icon gold"><i class="bi bi-buildings"></i></div>
                <div class="kpi-label">Total Properties</div>
                <div class="kpi-value"><?php echo $total_properties; ?></div>
                <div class="kpi-sub"><?php echo $active_properties; ?> active listings</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon green"><i class="bi bi-check-circle"></i></div>
                <div class="kpi-label">Total Sales</div>
                <div class="kpi-value"><?php echo $total_sales; ?></div>
                <div class="kpi-sub"><?php echo $sold_count; ?> sold properties</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon blue"><i class="bi bi-currency-dollar"></i></div>
                <div class="kpi-label">Total Revenue</div>
                <div class="kpi-value" style="font-size:1.15rem;">&#8369;<?php echo number_format($total_revenue, 0); ?></div>
                <div class="kpi-sub">&#8369;<?php echo number_format($total_commission, 0); ?> commissions</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon cyan"><i class="bi bi-person-badge"></i></div>
                <div class="kpi-label">Agents</div>
                <div class="kpi-value"><?php echo $total_agents; ?></div>
                <div class="kpi-sub"><?php echo $approved_agents; ?> approved</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon amber"><i class="bi bi-signpost-2"></i></div>
                <div class="kpi-label">Tour Requests</div>
                <div class="kpi-value"><?php echo $total_tours; ?></div>
                <div class="kpi-sub"><?php echo $pending_tours; ?> pending</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon red"><i class="bi bi-eye"></i></div>
                <div class="kpi-label">Property Views</div>
                <div class="kpi-value"><?php echo number_format($total_views); ?></div>
                <div class="kpi-sub"><?php echo number_format($total_likes); ?> likes</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon purple"><i class="bi bi-house-check"></i></div>
                <div class="kpi-label">Active Leases</div>
                <div class="kpi-value"><?php echo $active_leases; ?></div>
                <div class="kpi-sub"><?php echo $rented_count; ?> rented &middot; <?php echo $pending_rented; ?> pending</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon teal"><i class="bi bi-receipt"></i></div>
                <div class="kpi-label">Rental Revenue</div>
                <div class="kpi-value" style="font-size:1.15rem;">&#8369;<?php echo number_format($rental_revenue, 0); ?></div>
                <div class="kpi-sub">&#8369;<?php echo number_format($total_rental_commission, 0); ?> rental commissions</div>
            </div>
        </div>

        <!-- ==================== CHARTS SECTION ==================== -->

        <!-- Row 1: Content Overview (line) + Properties by Type (doughnut) + Listing Status (doughnut) -->
        <div class="chart-grid chart-grid-overview">
            <div class="chart-card">
                <div class="chart-card-header">
                    <div>
                        <div class="chart-card-title"><i class="bi bi-graph-up-arrow"></i> CONTENT OVERVIEW</div>
                        <div class="chart-card-subtitle">Listings, tours & views trend over time</div>
                    </div>
                    <span class="chart-card-badge badge-gold">All Time</span>
                </div>
                <div class="chart-kpi-row">
                    <div class="chart-kpi">
                        <div class="chart-kpi-val gold"><?php echo $total_properties; ?></div>
                        <div class="chart-kpi-label">Listings</div>
                    </div>
                    <div class="chart-kpi">
                        <div class="chart-kpi-val blue"><?php echo $total_tours; ?></div>
                        <div class="chart-kpi-label">Tours</div>
                    </div>
                    <div class="chart-kpi">
                        <div class="chart-kpi-val green"><?php echo number_format($total_views); ?></div>
                        <div class="chart-kpi-label">Views</div>
                    </div>
                </div>
                <div class="chart-container h-280">
                    <canvas id="chartContentOverview"></canvas>
                </div>
            </div>
            <div class="chart-card">
                <div class="chart-card-header">
                    <div>
                        <div class="chart-card-title"><i class="bi bi-pie-chart"></i> BY PROPERTY TYPE</div>
                        <div class="chart-card-subtitle">Distribution breakdown</div>
                    </div>
                </div>
                <div class="chart-container h-300" style="display:flex;align-items:center;justify-content:center;">
                    <canvas id="chartPropType"></canvas>
                </div>
            </div>
            <div class="chart-card">
                <div class="chart-card-header">
                    <div>
                        <div class="chart-card-title"><i class="bi bi-tags"></i> LISTING STATUS</div>
                        <div class="chart-card-subtitle">Current status distribution</div>
                    </div>
                </div>
                <div class="chart-container h-300" style="display:flex;align-items:center;justify-content:center;">
                    <canvas id="chartListingStatus"></canvas>
                </div>
            </div>
        </div>

        <!-- Row 2: Top Viewed Properties (bar) + Price Distribution (bar) -->
        <div class="chart-grid chart-grid-2">
            <div class="chart-card">
                <div class="chart-card-header">
                    <div>
                        <div class="chart-card-title"><i class="bi bi-bar-chart"></i> TOP VIEWED PROPERTIES</div>
                        <div class="chart-card-subtitle">Top 10 by view count</div>
                    </div>
                    <span class="chart-card-badge badge-blue">Views</span>
                </div>
                <div class="chart-container h-300">
                    <canvas id="chartTopViewed"></canvas>
                </div>
            </div>
            <div class="chart-card">
                <div class="chart-card-header">
                    <div>
                        <div class="chart-card-title"><i class="bi bi-cash-stack"></i> PRICE DISTRIBUTION</div>
                        <div class="chart-card-subtitle">Approved listings by price range</div>
                    </div>
                    <span class="chart-card-badge badge-green">Listings</span>
                </div>
                <div class="chart-container h-300">
                    <canvas id="chartPriceRange"></canvas>
                </div>
            </div>
        </div>

        <!-- Row 3: Tour Request Status (doughnut) + Tour Requests Over Time (bar) + Properties by City (horizontal bar) -->
        <div class="chart-grid chart-grid-3">
            <div class="chart-card">
                <div class="chart-card-header">
                    <div>
                        <div class="chart-card-title"><i class="bi bi-calendar-check"></i> TOUR STATUS</div>
                        <div class="chart-card-subtitle">Request status breakdown</div>
                    </div>
                </div>
                <div class="chart-kpi-row">
                    <div class="chart-kpi"><div class="chart-kpi-val green"><?php echo $completed_tours; ?></div><div class="chart-kpi-label">Completed</div></div>
                    <div class="chart-kpi"><div class="chart-kpi-val blue"><?php echo $confirmed_tours; ?></div><div class="chart-kpi-label">Confirmed</div></div>
                    <div class="chart-kpi"><div class="chart-kpi-val gold"><?php echo $pending_tours; ?></div><div class="chart-kpi-label">Pending</div></div>
                </div>
                <div class="chart-container h-250" style="display:flex;align-items:center;justify-content:center;">
                    <canvas id="chartTourStatus"></canvas>
                </div>
            </div>
            <div class="chart-card">
                <div class="chart-card-header">
                    <div>
                        <div class="chart-card-title"><i class="bi bi-bar-chart-line"></i> TOURS OVER TIME</div>
                        <div class="chart-card-subtitle">Monthly tour request volume</div>
                    </div>
                    <span class="chart-card-badge badge-cyan">Monthly</span>
                </div>
                <div class="chart-container h-300">
                    <canvas id="chartToursMonthly"></canvas>
                </div>
            </div>
            <div class="chart-card">
                <div class="chart-card-header">
                    <div>
                        <div class="chart-card-title"><i class="bi bi-geo-alt"></i> PROPERTIES BY CITY</div>
                        <div class="chart-card-subtitle">Top locations by count</div>
                    </div>
                    <span class="chart-card-badge badge-gold">Top 10</span>
                </div>
                <div class="chart-container h-300">
                    <canvas id="chartPropCity"></canvas>
                </div>
            </div>
        </div>

        <!-- Row 4: Agent Performance (horizontal bar) + Admin Activity Timeline (line area) -->
        <div class="chart-grid chart-grid-2">
            <div class="chart-card">
                <div class="chart-card-header">
                    <div>
                        <div class="chart-card-title"><i class="bi bi-people"></i> AGENT PERFORMANCE</div>
                        <div class="chart-card-subtitle">Listings, sales, and tours per agent</div>
                    </div>
                    <span class="chart-card-badge badge-amber">Agents</span>
                </div>
                <div class="chart-container h-320">
                    <canvas id="chartAgentPerformance"></canvas>
                </div>
            </div>
            <div class="chart-card">
                <div class="chart-card-header">
                    <div>
                        <div class="chart-card-title"><i class="bi bi-activity"></i> ADMIN ACTIVITY</div>
                        <div class="chart-card-subtitle">Login activity &mdash; last 30 days</div>
                    </div>
                    <span class="chart-card-badge badge-blue">30 Days</span>
                </div>
                <div class="chart-container h-320">
                    <canvas id="chartAdminActivity"></canvas>
                </div>
            </div>
        </div>

        <!-- ==================== DATA TABLES SECTION ==================== -->

        <!-- Action Bar -->
        <div class="section-bar">
            <h2 class="section-bar-title">
                <i class="bi bi-table"></i>
                Detailed Report Data
            </h2>
            <div class="action-buttons">
                <button type="button" class="btn-outline-admin" id="openFilterSidebar">
                    <i class="bi bi-funnel"></i> Smart Filters
                    <span class="filter-count-badge" id="filterCountBadge" style="display:none;">0</span>
                </button>
                <button type="button" class="btn-export-pdf" id="btnExportPDF"><i class="bi bi-file-earmark-pdf"></i> Export PDF</button>
                <button type="button" class="btn-export-excel" id="btnExportExcel"><i class="bi bi-file-earmark-spreadsheet"></i> Export Excel</button>
            </div>
        </div>

        <!-- Report Tabs -->
        <div class="report-tabs">
            <ul class="nav nav-tabs" id="reportTabs" role="tablist">
                <li class="nav-item"><button class="nav-link active" id="tab-properties" data-bs-toggle="tab" data-bs-target="#content-properties" type="button" role="tab"><i class="bi bi-building me-1"></i> Properties <span class="tab-badge badge-gold" id="badge-properties"><?php echo count($property_report); ?></span></button></li>
                <li class="nav-item"><button class="nav-link" id="tab-sales" data-bs-toggle="tab" data-bs-target="#content-sales" type="button" role="tab"><i class="bi bi-cash-coin me-1"></i> Sales <span class="tab-badge badge-green" id="badge-sales"><?php echo count($sales_report); ?></span></button></li>
                <li class="nav-item"><button class="nav-link" id="tab-agents" data-bs-toggle="tab" data-bs-target="#content-agents" type="button" role="tab"><i class="bi bi-people me-1"></i> Agent Performance <span class="tab-badge badge-blue" id="badge-agents"><?php echo count($agent_report); ?></span></button></li>
                <li class="nav-item"><button class="nav-link" id="tab-rentals" data-bs-toggle="tab" data-bs-target="#content-rentals" type="button" role="tab"><i class="bi bi-house-check me-1"></i> Rentals <span class="tab-badge badge-purple" id="badge-rentals"><?php echo count($rental_report); ?></span></button></li>
                <li class="nav-item"><button class="nav-link" id="tab-tours" data-bs-toggle="tab" data-bs-target="#content-tours" type="button" role="tab"><i class="bi bi-calendar-check me-1"></i> Tour Requests <span class="tab-badge badge-cyan" id="badge-tours"><?php echo count($tour_report); ?></span></button></li>
                <li class="nav-item"><button class="nav-link" id="tab-activity" data-bs-toggle="tab" data-bs-target="#content-activity" type="button" role="tab"><i class="bi bi-activity me-1"></i> System Activity <span class="tab-badge badge-amber" id="badge-activity"><?php echo count($activity_report) + count($status_logs) + count($property_logs); ?></span></button></li>
            </ul>
            <div class="tab-content" id="reportTabsContent">
                <!-- Properties -->
                <div class="tab-pane fade show active" id="content-properties" role="tabpanel">
                    <div class="report-table-wrapper" style="max-height:600px;overflow-y:auto;">
                        <table class="report-table" id="tableProperties"><thead><tr><th>#</th><th>Address</th><th>City</th><th>Province</th><th>Type</th><th>Beds</th><th>Baths</th><th>Sq Ft</th><th>Listing Price</th><th>Status</th><th>Approval</th><th>Views</th><th>Likes</th><th>Listed Date</th><th>Posted By</th></tr></thead><tbody id="tbodyProperties"></tbody></table>
                    </div>
                    <div class="report-pagination" id="paginationProperties"></div>
                </div>
                <!-- Sales -->
                <div class="tab-pane fade" id="content-sales" role="tabpanel">
                    <div class="report-table-wrapper" style="max-height:600px;overflow-y:auto;">
                        <table class="report-table" id="tableSales"><thead><tr><th>#</th><th>Property</th><th>City</th><th>Type</th><th>Buyer</th><th>Buyer Email</th><th>Sale Price</th><th>Sale Date</th><th>Agent</th><th>Commission</th><th>Comm %</th><th>Comm Status</th><th>Finalized By</th><th>Finalized At</th></tr></thead><tbody id="tbodySales"></tbody></table>
                    </div>
                    <div class="report-pagination" id="paginationSales"></div>
                </div>
                <!-- Agents -->
                <div class="tab-pane fade" id="content-agents" role="tabpanel">
                    <div class="report-table-wrapper" style="max-height:600px;overflow-y:auto;">
                        <table class="report-table" id="tableAgents"><thead><tr><th>#</th><th>Agent</th><th>Email</th><th>Phone</th><th>License</th><th>Exp.</th><th>Specializations</th><th>Active</th><th>Total</th><th>Sales</th><th>Revenue</th><th>Commission</th><th>Tours</th><th>Completed</th><th>Status</th><th>Registered</th></tr></thead><tbody id="tbodyAgents"></tbody></table>
                    </div>
                    <div class="report-pagination" id="paginationAgents"></div>
                </div>
                <!-- Rentals -->
                <div class="tab-pane fade" id="content-rentals" role="tabpanel">
                    <div class="report-table-wrapper" style="max-height:600px;overflow-y:auto;">
                        <table class="report-table" id="tableRentals"><thead><tr><th>#</th><th>Property</th><th>City</th><th>Type</th><th>Tenant</th><th>Monthly Rent</th><th>Deposit</th><th>Lease Start</th><th>Lease End</th><th>Term</th><th>Comm %</th><th>Collected</th><th>Commission</th><th>Payments</th><th>Status</th><th>Agent</th><th>Finalized</th></tr></thead><tbody id="tbodyRentals"></tbody></table>
                    </div>
                    <div class="report-pagination" id="paginationRentals"></div>
                </div>
                <!-- Tours -->
                <div class="tab-pane fade" id="content-tours" role="tabpanel">
                    <div class="report-table-wrapper" style="max-height:600px;overflow-y:auto;">
                        <table class="report-table" id="tableTours"><thead><tr><th>#</th><th>Visitor</th><th>Email</th><th>Phone</th><th>Property</th><th>City</th><th>Tour Date</th><th>Time</th><th>Type</th><th>Status</th><th>Agent</th><th>Requested</th><th>Confirmed</th><th>Completed</th></tr></thead><tbody id="tbodyTours"></tbody></table>
                    </div>
                    <div class="report-pagination" id="paginationTours"></div>
                </div>
                <!-- System Activity -->
                <div class="tab-pane fade" id="content-activity" role="tabpanel">
                    <h6 style="font-weight:700;color:#334155;margin-bottom:1rem;"><i class="bi bi-shield-lock me-2" style="color:var(--gold-dark);"></i>Admin Login Activity</h6>
                    <div class="report-table-wrapper" style="max-height:350px;overflow-y:auto;margin-bottom:2rem;">
                        <table class="report-table"><thead><tr><th>#</th><th>Admin</th><th>Action</th><th>Timestamp</th></tr></thead><tbody id="tbodyAdminLogs"></tbody></table>
                    </div>
                    <h6 style="font-weight:700;color:#334155;margin-bottom:1rem;"><i class="bi bi-arrow-left-right me-2" style="color:var(--gold-dark);"></i>Status Changes</h6>
                    <div class="report-table-wrapper" style="max-height:350px;overflow-y:auto;margin-bottom:2rem;">
                        <table class="report-table"><thead><tr><th>#</th><th>Type</th><th>ID</th><th>Action</th><th>Reason</th><th>By</th><th>Timestamp</th></tr></thead><tbody id="tbodyStatusLogs"></tbody></table>
                    </div>
                    <h6 style="font-weight:700;color:#334155;margin-bottom:1rem;"><i class="bi bi-journal-text me-2" style="color:var(--gold-dark);"></i>Property Action Log</h6>
                    <div class="report-table-wrapper" style="max-height:350px;overflow-y:auto;">
                        <table class="report-table"><thead><tr><th>#</th><th>Property</th><th>City</th><th>Action</th><th>Reason</th><th>By</th><th>Timestamp</th></tr></thead><tbody id="tbodyPropertyLogs"></tbody></table>
                    </div>
                </div>
            </div>
        </div>
    </div>

        </div><!-- /#page-content -->
    </div>

    <!-- ===================== FILTER SIDEBAR ===================== -->
    <div class="filter-sidebar" id="filterSidebar">
        <div class="filter-sidebar-overlay" id="filterOverlay"></div>
        <div class="filter-sidebar-content">
            <div class="filter-header">
                <h4><i class="bi bi-funnel me-2"></i>Smart Report Filters</h4>
                <button class="btn-close-filter" id="closeFilterBtn"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="filter-body">
                <div class="filter-section">
                    <div class="filter-section-title"><i class="bi bi-calendar-range"></i> Date Range</div>
                    <div style="display:flex;align-items:center;gap:0.5rem;">
                        <input type="date" id="filterDateFrom" class="filter-select">
                        <span style="color:#94a3b8;font-weight:600;">&mdash;</span>
                        <input type="date" id="filterDateTo" class="filter-select">
                    </div>
                </div>
                <div class="filter-section">
                    <div class="filter-section-title"><i class="bi bi-house-door"></i> Property Type</div>
                    <div class="filter-chips" id="filterPropertyTypes">
                        <?php foreach ($property_types as $pt): ?>
                        <label class="filter-chip"><input type="checkbox" class="filter-prop-type" value="<?php echo htmlspecialchars($pt); ?>" checked><span><?php echo htmlspecialchars($pt); ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="filter-section">
                    <div class="filter-section-title"><i class="bi bi-geo-alt"></i> City</div>
                    <select id="filterCity" class="filter-select"><option value="">All Cities</option>
                        <?php foreach ($cities as $city): ?><option value="<?php echo htmlspecialchars($city); ?>"><?php echo htmlspecialchars($city); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-section">
                    <div class="filter-section-title"><i class="bi bi-tags"></i> Listing Status</div>
                    <div class="filter-chips">
                        <label class="filter-chip"><input type="checkbox" class="filter-status" value="For Sale" checked><span>For Sale</span></label>
                        <label class="filter-chip"><input type="checkbox" class="filter-status" value="For Rent" checked><span>For Rent</span></label>
                        <label class="filter-chip"><input type="checkbox" class="filter-status" value="Pending Sold" checked><span>Pending Sold</span></label>
                        <label class="filter-chip"><input type="checkbox" class="filter-status" value="Sold" checked><span>Sold</span></label>
                    </div>
                </div>
                <div class="filter-section">
                    <div class="filter-section-title"><i class="bi bi-shield-check"></i> Approval Status</div>
                    <div class="filter-chips">
                        <label class="filter-chip"><input type="checkbox" class="filter-approval" value="pending" checked><span>Pending</span></label>
                        <label class="filter-chip"><input type="checkbox" class="filter-approval" value="approved" checked><span>Approved</span></label>
                        <label class="filter-chip"><input type="checkbox" class="filter-approval" value="rejected" checked><span>Rejected</span></label>
                    </div>
                </div>
                <div class="filter-section">
                    <div class="filter-section-title"><i class="bi bi-person-badge"></i> Agent</div>
                    <select id="filterAgent" class="filter-select"><option value="">All Agents</option>
                        <?php foreach ($agents_list as $ag): ?><option value="<?php echo htmlspecialchars($ag['full_name']); ?>"><?php echo htmlspecialchars($ag['full_name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-section">
                    <div class="filter-section-title"><i class="bi bi-calendar-check"></i> Tour Status</div>
                    <div class="filter-chips">
                        <label class="filter-chip"><input type="checkbox" class="filter-tour-status" value="Pending" checked><span>Pending</span></label>
                        <label class="filter-chip"><input type="checkbox" class="filter-tour-status" value="Confirmed" checked><span>Confirmed</span></label>
                        <label class="filter-chip"><input type="checkbox" class="filter-tour-status" value="Completed" checked><span>Completed</span></label>
                        <label class="filter-chip"><input type="checkbox" class="filter-tour-status" value="Cancelled" checked><span>Cancelled</span></label>
                        <label class="filter-chip"><input type="checkbox" class="filter-tour-status" value="Rejected" checked><span>Rejected</span></label>
                        <label class="filter-chip"><input type="checkbox" class="filter-tour-status" value="Expired" checked><span>Expired</span></label>
                    </div>
                </div>
                <div class="filter-results-summary">
                    <i class="bi bi-check-circle-fill"></i>
                    <div><div class="filter-results-count" id="filteredCount">0</div><div class="filter-results-label">Records Match Your Filters</div></div>
                </div>
            </div>
            <div class="filter-footer">
                <button class="btn btn-outline-secondary" id="clearFiltersBtn"><i class="bi bi-arrow-clockwise me-2"></i>Reset All</button>
                <button class="btn btn-primary" id="applyFiltersBtn"><i class="bi bi-check2 me-2"></i>Apply Filters</button>
            </div>
        </div>
    </div>

    <!-- ===================== EXPORT PREVIEW MODAL ===================== -->
    <div class="export-modal-overlay" id="exportModalOverlay">
        <div class="export-modal">
            <div class="export-modal-header">
                <div style="display:flex;align-items:center;gap:0.75rem;">
                    <img src="images/Logo.png" alt="Logo" style="width:32px;height:32px;border-radius:4px;">
                    <div>
                        <h4 style="margin:0;font-size:1.1rem;font-weight:700;display:flex;align-items:center;gap:0.5rem;"><i class="bi bi-eye" style="color:var(--gold);"></i> <span id="exportModalTitle">Export Preview</span></h4>
                        <span style="font-size:0.72rem;color:rgba(255,255,255,0.5);font-weight:500;">HomeEstate Realty &bull; Admin Reports</span>
                    </div>
                </div>
                <button class="btn-close-filter" id="closeExportModal"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="export-modal-body" id="exportPreviewBody"></div>
            <div class="export-modal-footer">
                <div class="export-info">
                    <div style="display:flex;align-items:center;gap:0.5rem;">
                        <span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:4px;background:linear-gradient(135deg,rgba(212,175,55,0.08),rgba(212,175,55,0.15));border:1px solid rgba(212,175,55,0.2);"><i class="bi bi-table" style="color:#b8941f;font-size:0.85rem;"></i></span>
                        <div>
                            <div style="font-size:0.82rem;font-weight:600;color:#334155;">Showing <strong style="color:#2563eb;" id="exportRowCount">0</strong> records from <strong style="color:#b8941f;" id="exportTabName">Properties</strong></div>
                            <div style="font-size:0.7rem;color:#94a3b8;">Filtered report data &bull; Ready to export</div>
                        </div>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn-outline-admin" id="cancelExportBtn"><i class="bi bi-x-circle me-1"></i> Cancel</button>
                    <button class="btn-export-pdf" id="confirmExportPDF" style="display:none;"><i class="bi bi-file-earmark-pdf me-1"></i> Download PDF</button>
                    <button class="btn-export-excel" id="confirmExportExcel" style="display:none;"><i class="bi bi-file-earmark-spreadsheet me-1"></i> Download Excel</button>
                </div>
            </div>
        </div>
    </div>

<script src="<?= ASSETS_JS ?>bootstrap.bundle.min.js"></script>
<script>
// =============================================
// DATA FROM PHP
// =============================================
const DATA = {
    properties: <?php echo json_encode($property_report); ?>,
    sales: <?php echo json_encode($sales_report); ?>,
    rentals: <?php echo json_encode($rental_report); ?>,
    agents: <?php echo json_encode($agent_report); ?>,
    tours: <?php echo json_encode($tour_report); ?>,
    adminLogs: <?php echo json_encode($activity_report); ?>,
    statusLogs: <?php echo json_encode($status_logs); ?>,
    propertyLogs: <?php echo json_encode($property_logs); ?>
};

let FILTERED = {
    properties: [...DATA.properties], sales: [...DATA.sales], rentals: [...DATA.rentals], agents: [...DATA.agents],
    tours: [...DATA.tours], adminLogs: [...DATA.adminLogs], statusLogs: [...DATA.statusLogs], propertyLogs: [...DATA.propertyLogs]
};

const ROWS_PER_PAGE = 25;
let currentPages = { properties: 1, sales: 1, rentals: 1, agents: 1, tours: 1 };

// =============================================
// CHART.JS GLOBAL DEFAULTS
// =============================================
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.font.size = 11;
Chart.defaults.color = '#64748b';
Chart.defaults.plugins.legend.labels.usePointStyle = true;
Chart.defaults.plugins.legend.labels.pointStyleWidth = 8;
Chart.defaults.plugins.legend.labels.padding = 16;
Chart.defaults.plugins.legend.labels.font = { size: 11, weight: 500 };
Chart.defaults.elements.bar.borderRadius = 4;
Chart.defaults.elements.bar.borderSkipped = false;
Chart.defaults.elements.line.tension = 0.4;

const COLORS = {
    gold: '#d4af37', goldLight: 'rgba(212,175,55,0.15)', goldDark: '#b8941f',
    blue: '#2563eb', blueLight: 'rgba(37,99,235,0.12)', blueDark: '#1e40af',
    green: '#16a34a', greenLight: 'rgba(34,197,94,0.12)',
    red: '#dc2626', redLight: 'rgba(239,68,68,0.12)',
    amber: '#d97706', amberLight: 'rgba(245,158,11,0.12)',
    cyan: '#0891b2', cyanLight: 'rgba(6,182,212,0.12)',
    purple: '#7c3aed', purpleLight: 'rgba(124,58,237,0.12)',
    slate: '#64748b', slateLight: 'rgba(100,116,139,0.12)',
    palette: ['#2563eb', '#d4af37', '#16a34a', '#dc2626', '#0891b2', '#7c3aed', '#d97706', '#ec4899', '#64748b', '#059669']
};

// =============================================
// CHART: CONTENT OVERVIEW (Multi-line)
// =============================================
(function() {
    var listData = <?php echo json_encode($listings_by_month); ?>;
    var tourData = <?php echo json_encode($tours_by_month); ?>;
    // Merge all month labels
    var allMonths = {};
    listData.forEach(function(r) { allMonths[r.month_key] = r.month_label; });
    tourData.forEach(function(r) { allMonths[r.month_key] = r.month_label; });
    var sortedKeys = Object.keys(allMonths).sort();
    var labels = sortedKeys.map(function(k) { return allMonths[k]; });
    var listMap = {}; listData.forEach(function(r) { listMap[r.month_key] = parseInt(r.cnt); });
    var tourMap = {}; tourData.forEach(function(r) { tourMap[r.month_key] = parseInt(r.cnt); });

    new Chart(document.getElementById('chartContentOverview'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                { label: 'New Listings', data: sortedKeys.map(function(k) { return listMap[k] || 0; }), borderColor: COLORS.gold, backgroundColor: COLORS.goldLight, fill: true, pointBackgroundColor: COLORS.gold, pointRadius: 5, pointHoverRadius: 7, borderWidth: 2.5 },
                { label: 'Tour Requests', data: sortedKeys.map(function(k) { return tourMap[k] || 0; }), borderColor: COLORS.blue, backgroundColor: COLORS.blueLight, fill: true, pointBackgroundColor: COLORS.blue, pointRadius: 5, pointHoverRadius: 7, borderWidth: 2.5 }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false, interaction: { intersect: false, mode: 'index' }, scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { stepSize: 1 } } }, plugins: { legend: { position: 'top', align: 'end' } } }
    });
})();

// =============================================
// CHART: PROPERTY TYPE (Doughnut)
// =============================================
(function() {
    var data = <?php echo json_encode($prop_by_type); ?>;
    var labels = Object.keys(data);
    var values = Object.values(data);
    var total = values.reduce(function(a, b) { return a + b; }, 0);
    new Chart(document.getElementById('chartPropType'), {
        type: 'doughnut',
        data: { labels: labels, datasets: [{ data: values, backgroundColor: COLORS.palette.slice(0, labels.length), borderWidth: 0, hoverOffset: 8 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '65%', plugins: { legend: { position: 'bottom', labels: { padding: 12, font: { size: 10 } } }, tooltip: { callbacks: { label: function(ctx) { return ctx.label + ': ' + ctx.raw + ' (' + Math.round(ctx.raw / total * 100) + '%)'; } } } } }
    });
})();

// =============================================
// CHART: LISTING STATUS (Doughnut)
// =============================================
(function() {
    var labels = ['For Sale', 'For Rent', 'Pending Sold', 'Sold'];
    var values = [<?php echo $for_sale_count; ?>, <?php echo $for_rent_count; ?>, <?php echo $pending_sold; ?>, <?php echo $sold_count; ?>];
    var colors = [COLORS.blue, COLORS.gold, COLORS.cyan, COLORS.slate];
    var total = values.reduce(function(a, b) { return a + b; }, 0);
    new Chart(document.getElementById('chartListingStatus'), {
        type: 'doughnut',
        data: { labels: labels, datasets: [{ data: values, backgroundColor: colors, borderWidth: 0, hoverOffset: 8 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '65%', plugins: { legend: { position: 'bottom', labels: { padding: 12, font: { size: 10 } } }, tooltip: { callbacks: { label: function(ctx) { return ctx.label + ': ' + ctx.raw + ' (' + (total > 0 ? Math.round(ctx.raw / total * 100) : 0) + '%)'; } } } } }
    });
})();

// =============================================
// CHART: TOP VIEWED PROPERTIES (Bar)
// =============================================
(function() {
    var data = <?php echo json_encode($top_viewed); ?>;
    var labels = data.map(function(r) { var addr = r.StreetAddress || ''; return addr.length > 25 ? addr.substring(0, 25) + '...' : addr; });
    var views = data.map(function(r) { return parseInt(r.ViewsCount) || 0; });
    var likes = data.map(function(r) { return parseInt(r.Likes) || 0; });
    new Chart(document.getElementById('chartTopViewed'), {
        type: 'bar',
        data: { labels: labels, datasets: [
            { label: 'Views', data: views, backgroundColor: COLORS.blueLight, borderColor: COLORS.blue, borderWidth: 1.5 },
            { label: 'Likes', data: likes, backgroundColor: COLORS.goldLight, borderColor: COLORS.gold, borderWidth: 1.5 }
        ] },
        options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', scales: { x: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.04)' } }, y: { grid: { display: false }, ticks: { font: { size: 10 } } } }, plugins: { legend: { position: 'top', align: 'end' } } }
    });
})();

// =============================================
// CHART: PRICE DISTRIBUTION (Bar)
// =============================================
(function() {
    var data = <?php echo json_encode($price_ranges); ?>;
    var labels = Object.keys(data);
    var values = Object.values(data);
    var bgColors = [COLORS.greenLight, COLORS.blueLight, COLORS.goldLight, COLORS.amberLight, COLORS.redLight];
    var borderColors = [COLORS.green, COLORS.blue, COLORS.gold, COLORS.amber, COLORS.red];
    new Chart(document.getElementById('chartPriceRange'), {
        type: 'bar',
        data: { labels: labels.map(function(l) { return '₱ ' + l; }), datasets: [{ label: 'Properties', data: values, backgroundColor: bgColors, borderColor: borderColors, borderWidth: 1.5 }] },
        options: { responsive: true, maintainAspectRatio: false, scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { stepSize: 1 } } }, plugins: { legend: { display: false } } }
    });
})();

// =============================================
// CHART: TOUR STATUS (Doughnut)
// =============================================
(function() {
    var data = <?php echo json_encode($tour_statuses); ?>;
    var labels = Object.keys(data);
    var values = Object.values(data);
    var statusColors = { 'Pending': COLORS.amber, 'Confirmed': COLORS.blue, 'Completed': COLORS.green, 'Cancelled': COLORS.red, 'Rejected': '#94a3b8', 'Expired': '#cbd5e1' };
    var colors = labels.map(function(l) { return statusColors[l] || COLORS.slate; });
    var total = values.reduce(function(a, b) { return a + b; }, 0);
    new Chart(document.getElementById('chartTourStatus'), {
        type: 'doughnut',
        data: { labels: labels, datasets: [{ data: values, backgroundColor: colors, borderWidth: 0, hoverOffset: 6 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '60%', plugins: { legend: { position: 'bottom', labels: { padding: 10, font: { size: 10 } } }, tooltip: { callbacks: { label: function(ctx) { return ctx.label + ': ' + ctx.raw + ' (' + (total > 0 ? Math.round(ctx.raw / total * 100) : 0) + '%)'; } } } } }
    });
})();

// =============================================
// CHART: TOURS OVER TIME (Bar)
// =============================================
(function() {
    var data = <?php echo json_encode($tours_by_month); ?>;
    var labels = data.map(function(r) { return r.month_label; });
    var values = data.map(function(r) { return parseInt(r.cnt); });
    new Chart(document.getElementById('chartToursMonthly'), {
        type: 'bar',
        data: { labels: labels, datasets: [{ label: 'Tour Requests', data: values, backgroundColor: COLORS.cyanLight, borderColor: COLORS.cyan, borderWidth: 1.5 }] },
        options: { responsive: true, maintainAspectRatio: false, scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { stepSize: 1 } } }, plugins: { legend: { display: false } } }
    });
})();

// =============================================
// CHART: PROPERTIES BY CITY (Horizontal Bar)
// =============================================
(function() {
    var data = <?php echo json_encode($prop_by_city); ?>;
    var labels = Object.keys(data);
    var values = Object.values(data);
    new Chart(document.getElementById('chartPropCity'), {
        type: 'bar',
        data: { labels: labels, datasets: [{ label: 'Properties', data: values, backgroundColor: COLORS.goldLight, borderColor: COLORS.gold, borderWidth: 1.5 }] },
        options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', scales: { x: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { stepSize: 1 } }, y: { grid: { display: false } } }, plugins: { legend: { display: false } } }
    });
})();

// =============================================
// CHART: AGENT PERFORMANCE (Grouped Bar)
// =============================================
(function() {
    var data = <?php echo json_encode($agent_report); ?>;
    var labels = data.map(function(r) { return (r.first_name || '') + ' ' + (r.last_name || '').charAt(0) + '.'; });
    var listings = data.map(function(r) { return parseInt(r.total_listings) || 0; });
    var sales = data.map(function(r) { return parseInt(r.total_sales) || 0; });
    var tours = data.map(function(r) { return parseInt(r.total_tours) || 0; });
    new Chart(document.getElementById('chartAgentPerformance'), {
        type: 'bar',
        data: { labels: labels, datasets: [
            { label: 'Listings', data: listings, backgroundColor: COLORS.goldLight, borderColor: COLORS.gold, borderWidth: 1.5 },
            { label: 'Sales', data: sales, backgroundColor: COLORS.greenLight, borderColor: COLORS.green, borderWidth: 1.5 },
            { label: 'Tours', data: tours, backgroundColor: COLORS.blueLight, borderColor: COLORS.blue, borderWidth: 1.5 }
        ] },
        options: { responsive: true, maintainAspectRatio: false, scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { stepSize: 1 } } }, plugins: { legend: { position: 'top', align: 'end' } } }
    });
})();

// =============================================
// CHART: ADMIN ACTIVITY (Area Line)
// =============================================
(function() {
    var data = <?php echo json_encode($admin_activity_daily); ?>;
    var labels = data.map(function(r) { var d = new Date(r.day); return d.toLocaleDateString('en-PH', {month:'short',day:'numeric'}); });
    var values = data.map(function(r) { return parseInt(r.cnt); });
    new Chart(document.getElementById('chartAdminActivity'), {
        type: 'line',
        data: { labels: labels, datasets: [{ label: 'Admin Logins', data: values, borderColor: COLORS.blue, backgroundColor: 'rgba(37,99,235,0.08)', fill: true, pointBackgroundColor: COLORS.blue, pointRadius: 4, pointHoverRadius: 6, borderWidth: 2.5 }] },
        options: { responsive: true, maintainAspectRatio: false, scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { stepSize: 1 } } }, plugins: { legend: { display: false } } }
    });
})();

// =============================================
// UTILITY FUNCTIONS
// =============================================
function esc(str) { if (str === null || str === undefined || str === '') return '<span style="color:#94a3b8;">&mdash;</span>'; var d = document.createElement('div'); d.textContent = String(str); return d.innerHTML; }
function formatPrice(v) { if (!v || v == 0) return '<span style="color:#94a3b8;">&mdash;</span>'; var n = parseFloat(v); var parts = n.toFixed(2).split('.'); parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ','); return '&#8369; ' + parts[0] + '.' + parts[1]; }
function formatPriceText(v) { if (!v || v == 0) return '\u2014'; var n = parseFloat(v); var parts = n.toFixed(2).split('.'); parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ','); return '\u20B1 ' + parts[0] + '.' + parts[1]; }
function formatDate(d) { if (!d) return '<span style="color:#94a3b8;">&mdash;</span>'; var dt = new Date(d); if (isNaN(dt)) return esc(d); return dt.toLocaleDateString('en-PH', {year:'numeric',month:'short',day:'numeric'}); }
function formatDateTime(d) { if (!d) return '<span style="color:#94a3b8;">&mdash;</span>'; var dt = new Date(d); if (isNaN(dt)) return esc(d); return dt.toLocaleDateString('en-PH', {year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}); }
function statusPill(s) { if (!s) return '<span style="color:#94a3b8;">&mdash;</span>'; var c = s.toLowerCase().replace(/\s+/g,'-'); var d = document.createElement('div'); d.textContent = s; return '<span class="status-pill '+c+'">'+d.innerHTML+'</span>'; }

// =============================================
// RENDER TABLE FUNCTIONS
// =============================================
function renderPropertiesTable(page) {
    page = page || 1; currentPages.properties = page;
    var data = FILTERED.properties, start = (page-1)*ROWS_PER_PAGE, pageData = data.slice(start, start+ROWS_PER_PAGE);
    var tbody = document.getElementById('tbodyProperties');
    if (data.length === 0) { tbody.innerHTML = '<tr><td colspan="15"><div class="empty-state"><i class="bi bi-building"></i><h4>No Properties Found</h4><p>Adjust your filters</p></div></td></tr>'; document.getElementById('paginationProperties').innerHTML = ''; return; }
    var h = '';
    pageData.forEach(function(r, i) { h += '<tr><td>'+(start+i+1)+'</td><td>'+esc(r.StreetAddress)+'</td><td>'+esc(r.City)+'</td><td>'+esc(r.Province)+'</td><td>'+esc(r.PropertyType)+'</td><td>'+(r.Bedrooms!=null?r.Bedrooms:'&mdash;')+'</td><td>'+(r.Bathrooms!=null?r.Bathrooms:'&mdash;')+'</td><td>'+(r.SquareFootage?Number(r.SquareFootage).toLocaleString():'&mdash;')+'</td><td class="price-text">'+formatPrice(r.ListingPrice)+'</td><td>'+statusPill(r.Status)+'</td><td>'+statusPill(r.approval_status)+'</td><td>'+(r.ViewsCount!=null?r.ViewsCount:0)+'</td><td>'+(r.Likes!=null?r.Likes:0)+'</td><td>'+formatDate(r.ListingDate)+'</td><td>'+esc(r.posted_by)+'</td></tr>'; });
    tbody.innerHTML = h; renderPagination('paginationProperties', data.length, page, 'properties');
}
function renderSalesTable(page) {
    page = page || 1; currentPages.sales = page;
    var data = FILTERED.sales, start = (page-1)*ROWS_PER_PAGE, pageData = data.slice(start, start+ROWS_PER_PAGE);
    var tbody = document.getElementById('tbodySales');
    if (data.length === 0) { tbody.innerHTML = '<tr><td colspan="14"><div class="empty-state"><i class="bi bi-cash-coin"></i><h4>No Sales Records</h4><p>Adjust your filters</p></div></td></tr>'; document.getElementById('paginationSales').innerHTML = ''; return; }
    var h = '';
    pageData.forEach(function(r, i) { h += '<tr><td>'+(start+i+1)+'</td><td>'+esc(r.StreetAddress)+'</td><td>'+esc(r.City)+'</td><td>'+esc(r.PropertyType)+'</td><td>'+esc(r.buyer_name)+'</td><td>'+esc(r.buyer_email)+'</td><td class="price-text">'+formatPrice(r.final_sale_price)+'</td><td>'+formatDate(r.sale_date)+'</td><td>'+esc(r.agent_name)+'</td><td class="price-text">'+formatPrice(r.commission_amount)+'</td><td>'+(r.commission_percentage?r.commission_percentage+'%':'&mdash;')+'</td><td>'+statusPill(r.commission_status)+'</td><td>'+esc(r.finalized_by_name)+'</td><td>'+formatDateTime(r.finalized_at)+'</td></tr>'; });
    tbody.innerHTML = h; renderPagination('paginationSales', data.length, page, 'sales');
}
function renderAgentsTable(page) {
    page = page || 1; currentPages.agents = page;
    var data = FILTERED.agents, start = (page-1)*ROWS_PER_PAGE, pageData = data.slice(start, start+ROWS_PER_PAGE);
    var tbody = document.getElementById('tbodyAgents');
    if (data.length === 0) { tbody.innerHTML = '<tr><td colspan="16"><div class="empty-state"><i class="bi bi-people"></i><h4>No Agents Found</h4><p>Adjust your filters</p></div></td></tr>'; document.getElementById('paginationAgents').innerHTML = ''; return; }
    var h = '';
    pageData.forEach(function(r, i) { h += '<tr><td>'+(start+i+1)+'</td><td><strong>'+esc(r.first_name)+' '+esc(r.last_name)+'</strong></td><td>'+esc(r.email)+'</td><td>'+esc(r.phone_number)+'</td><td>'+esc(r.license_number)+'</td><td>'+(r.years_experience!=null?r.years_experience+' yr'+(r.years_experience>1?'s':''):'&mdash;')+'</td><td>'+(r.specializations?esc(r.specializations):'&mdash;')+'</td><td>'+r.active_listings+'</td><td>'+r.total_listings+'</td><td><strong>'+r.total_sales+'</strong></td><td class="price-text">'+formatPrice(r.total_revenue)+'</td><td class="price-text">'+formatPrice(r.total_commission)+'</td><td>'+r.total_tours+'</td><td>'+r.completed_tours+'</td><td>'+statusPill(r.is_approved==1?'approved':'pending')+'</td><td>'+formatDate(r.date_registered)+'</td></tr>'; });
    tbody.innerHTML = h; renderPagination('paginationAgents', data.length, page, 'agents');
}
function renderRentalsTable(page) {
    page = page || 1; currentPages.rentals = page;
    var data = FILTERED.rentals, start = (page-1)*ROWS_PER_PAGE, pageData = data.slice(start, start+ROWS_PER_PAGE);
    var tbody = document.getElementById('tbodyRentals');
    if (data.length === 0) { tbody.innerHTML = '<tr><td colspan="17"><div class="empty-state"><i class="bi bi-house-check"></i><h4>No Rental Records</h4><p>Adjust your filters</p></div></td></tr>'; document.getElementById('paginationRentals').innerHTML = ''; return; }
    var h = '';
    pageData.forEach(function(r, i) { h += '<tr><td>'+(start+i+1)+'</td><td>'+esc(r.StreetAddress)+'</td><td>'+esc(r.City)+'</td><td>'+esc(r.PropertyType)+'</td><td>'+esc(r.tenant_name)+'</td><td class="price-text">'+formatPrice(r.monthly_rent)+'</td><td class="price-text">'+formatPrice(r.security_deposit)+'</td><td>'+formatDate(r.lease_start_date)+'</td><td>'+formatDate(r.lease_end_date)+'</td><td>'+(r.lease_term_months?r.lease_term_months+' mo':'&mdash;')+'</td><td>'+(r.commission_rate?r.commission_rate+'%':'&mdash;')+'</td><td class="price-text">'+formatPrice(r.total_collected)+'</td><td class="price-text">'+formatPrice(r.total_commission)+'</td><td>'+(r.confirmed_payments||0)+'/'+(parseInt(r.confirmed_payments||0)+parseInt(r.pending_payments||0))+'</td><td>'+statusPill(r.lease_status||'active')+'</td><td>'+esc(r.agent_name)+'</td><td>'+formatDateTime(r.finalized_at)+'</td></tr>'; });
    tbody.innerHTML = h; renderPagination('paginationRentals', data.length, page, 'rentals');
}
function renderToursTable(page) {
    page = page || 1; currentPages.tours = page;
    var data = FILTERED.tours, start = (page-1)*ROWS_PER_PAGE, pageData = data.slice(start, start+ROWS_PER_PAGE);
    var tbody = document.getElementById('tbodyTours');
    if (data.length === 0) { tbody.innerHTML = '<tr><td colspan="14"><div class="empty-state"><i class="bi bi-calendar-check"></i><h4>No Tour Requests</h4><p>Adjust your filters</p></div></td></tr>'; document.getElementById('paginationTours').innerHTML = ''; return; }
    var h = '';
    pageData.forEach(function(r, i) { h += '<tr><td>'+(start+i+1)+'</td><td>'+esc(r.user_name)+'</td><td>'+esc(r.user_email)+'</td><td>'+esc(r.user_phone)+'</td><td>'+esc(r.StreetAddress)+'</td><td>'+esc(r.City)+'</td><td>'+formatDate(r.tour_date)+'</td><td>'+esc(r.tour_time)+'</td><td>'+statusPill(r.tour_type)+'</td><td>'+statusPill(r.request_status)+'</td><td>'+esc(r.agent_name)+'</td><td>'+formatDateTime(r.requested_at)+'</td><td>'+formatDateTime(r.confirmed_at)+'</td><td>'+formatDateTime(r.completed_at)+'</td></tr>'; });
    tbody.innerHTML = h; renderPagination('paginationTours', data.length, page, 'tours');
}
function renderActivityTables() {
    var ab = document.getElementById('tbodyAdminLogs');
    if (FILTERED.adminLogs.length === 0) { ab.innerHTML = '<tr><td colspan="4"><div class="empty-state"><p>No admin logs</p></div></td></tr>'; }
    else { var h=''; FILTERED.adminLogs.forEach(function(r,i){h+='<tr><td>'+(i+1)+'</td><td>'+esc(r.admin_name)+'</td><td>'+statusPill(r.action)+'</td><td>'+formatDateTime(r.log_timestamp)+'</td></tr>';}); ab.innerHTML=h; }
    var sb = document.getElementById('tbodyStatusLogs');
    if (FILTERED.statusLogs.length === 0) { sb.innerHTML = '<tr><td colspan="7"><div class="empty-state"><p>No status logs</p></div></td></tr>'; }
    else { var h2=''; FILTERED.statusLogs.forEach(function(r,i){h2+='<tr><td>'+(i+1)+'</td><td>'+statusPill(r.item_type)+'</td><td>'+r.item_id+'</td><td>'+statusPill(r.action)+'</td><td>'+esc(r.reason_message)+'</td><td>'+esc(r.action_by)+'</td><td>'+formatDateTime(r.log_timestamp)+'</td></tr>';}); sb.innerHTML=h2; }
    var pb = document.getElementById('tbodyPropertyLogs');
    if (FILTERED.propertyLogs.length === 0) { pb.innerHTML = '<tr><td colspan="7"><div class="empty-state"><p>No property logs</p></div></td></tr>'; }
    else { var h3=''; FILTERED.propertyLogs.forEach(function(r,i){h3+='<tr><td>'+(i+1)+'</td><td>'+esc(r.StreetAddress)+'</td><td>'+esc(r.City)+'</td><td>'+statusPill(r.action)+'</td><td>'+esc(r.reason_message)+'</td><td>'+esc(r.action_by)+'</td><td>'+formatDateTime(r.log_timestamp)+'</td></tr>';}); pb.innerHTML=h3; }
}

// =============================================
// PAGINATION
// =============================================
function renderPagination(cid, total, cur, key) {
    var c = document.getElementById(cid), tp = Math.ceil(total / ROWS_PER_PAGE);
    if (tp <= 1) { c.innerHTML = ''; return; }
    var s = (cur-1)*ROWS_PER_PAGE+1, e = Math.min(cur*ROWS_PER_PAGE, total);
    var h = '<div class="pagination-info">Showing '+s+'&ndash;'+e+' of '+total+'</div><div class="pagination-controls">';
    h += '<button class="page-btn" onclick="goToPage(\''+key+'\','+(cur-1)+')" '+(cur<=1?'disabled':'')+'><i class="bi bi-chevron-left"></i></button>';
    var mv=5, sp=Math.max(1,cur-Math.floor(mv/2)), ep=Math.min(tp,sp+mv-1);
    if(ep-sp<mv-1) sp=Math.max(1,ep-mv+1);
    if(sp>1){h+='<button class="page-btn" onclick="goToPage(\''+key+'\',1)">1</button>';if(sp>2)h+='<span class="page-btn" style="border:none;cursor:default;">&hellip;</span>';}
    for(var p=sp;p<=ep;p++) h+='<button class="page-btn '+(p===cur?'active':'')+'" onclick="goToPage(\''+key+'\','+p+')">'+p+'</button>';
    if(ep<tp){if(ep<tp-1)h+='<span class="page-btn" style="border:none;cursor:default;">&hellip;</span>';h+='<button class="page-btn" onclick="goToPage(\''+key+'\','+tp+')">'+tp+'</button>';}
    h += '<button class="page-btn" onclick="goToPage(\''+key+'\','+(cur+1)+')" '+(cur>=tp?'disabled':'')+'><i class="bi bi-chevron-right"></i></button></div>';
    c.innerHTML = h;
}
function goToPage(k, p) { switch(k){case 'properties':renderPropertiesTable(p);break;case 'sales':renderSalesTable(p);break;case 'rentals':renderRentalsTable(p);break;case 'agents':renderAgentsTable(p);break;case 'tours':renderToursTable(p);break;} }

// =============================================
// FILTER LOGIC
// =============================================
function applyFilters() {
    var df=document.getElementById('filterDateFrom').value, dt=document.getElementById('filterDateTo').value, city=document.getElementById('filterCity').value, agent=document.getElementById('filterAgent').value;
    var ct=[],cs=[],ca=[],cts=[];
    document.querySelectorAll('.filter-prop-type:checked').forEach(function(c){ct.push(c.value);});
    document.querySelectorAll('.filter-status:checked').forEach(function(c){cs.push(c.value);});
    document.querySelectorAll('.filter-approval:checked').forEach(function(c){ca.push(c.value);});
    document.querySelectorAll('.filter-tour-status:checked').forEach(function(c){cts.push(c.value);});

    FILTERED.properties = DATA.properties.filter(function(r) {
        if(df&&r.ListingDate&&r.ListingDate<df) return false; if(dt&&r.ListingDate&&r.ListingDate>dt) return false;
        if(city&&r.City!==city) return false; if(ct.length>0&&ct.indexOf(r.PropertyType)===-1) return false;
        if(cs.length>0&&cs.indexOf(r.Status)===-1) return false; if(ca.length>0&&ca.indexOf(r.approval_status)===-1) return false;
        if(agent&&r.posted_by!==agent) return false; return true;
    });
    FILTERED.sales = DATA.sales.filter(function(r) {
        if(df&&r.sale_date&&r.sale_date<df) return false; if(dt&&r.sale_date&&r.sale_date>dt) return false;
        if(city&&r.City!==city) return false; if(ct.length>0&&ct.indexOf(r.PropertyType)===-1) return false;
        if(agent&&r.agent_name!==agent) return false; return true;
    });
    FILTERED.agents = DATA.agents.filter(function(r) { var fn=(r.first_name||'')+' '+(r.last_name||''); if(agent&&fn!==agent) return false; return true; });
    FILTERED.tours = DATA.tours.filter(function(r) {
        if(df&&r.tour_date&&r.tour_date<df) return false; if(dt&&r.tour_date&&r.tour_date>dt) return false;
        if(city&&r.City!==city) return false; if(agent&&r.agent_name!==agent) return false;
        if(cts.length>0&&cts.indexOf(r.request_status)===-1) return false; return true;
    });
    FILTERED.rentals = DATA.rentals.filter(function(r) {
        if(df&&r.lease_start_date&&r.lease_start_date<df) return false; if(dt&&r.lease_start_date&&r.lease_start_date>dt) return false;
        if(city&&r.City!==city) return false; if(ct.length>0&&ct.indexOf(r.PropertyType)===-1) return false;
        if(agent&&r.agent_name!==agent) return false; return true;
    });
    FILTERED.adminLogs = DATA.adminLogs.filter(function(r) { if(df&&r.log_timestamp&&r.log_timestamp.substring(0,10)<df) return false; if(dt&&r.log_timestamp&&r.log_timestamp.substring(0,10)>dt) return false; return true; });
    FILTERED.statusLogs = DATA.statusLogs.filter(function(r) { if(df&&r.log_timestamp&&r.log_timestamp.substring(0,10)<df) return false; if(dt&&r.log_timestamp&&r.log_timestamp.substring(0,10)>dt) return false; return true; });
    FILTERED.propertyLogs = DATA.propertyLogs.filter(function(r) { if(df&&r.log_timestamp&&r.log_timestamp.substring(0,10)<df) return false; if(dt&&r.log_timestamp&&r.log_timestamp.substring(0,10)>dt) return false; return true; });

    document.getElementById('badge-properties').textContent=FILTERED.properties.length;
    document.getElementById('badge-sales').textContent=FILTERED.sales.length;
    document.getElementById('badge-rentals').textContent=FILTERED.rentals.length;
    document.getElementById('badge-agents').textContent=FILTERED.agents.length;
    document.getElementById('badge-tours').textContent=FILTERED.tours.length;
    document.getElementById('badge-activity').textContent=FILTERED.adminLogs.length+FILTERED.statusLogs.length+FILTERED.propertyLogs.length;

    var fc=0;
    if(df||dt) fc++; if(city) fc++; if(agent) fc++;
    if(ct.length<document.querySelectorAll('.filter-prop-type').length) fc++;
    if(cs.length<document.querySelectorAll('.filter-status').length) fc++;
    if(ca.length<document.querySelectorAll('.filter-approval').length) fc++;
    if(cts.length<document.querySelectorAll('.filter-tour-status').length) fc++;
    var b=document.getElementById('filterCountBadge');
    if(fc>0){b.textContent=fc;b.style.display='inline-flex';}else{b.style.display='none';}
    document.getElementById('filteredCount').textContent=getActiveFilteredCount();

    renderPropertiesTable(1); renderSalesTable(1); renderRentalsTable(1); renderAgentsTable(1); renderToursTable(1); renderActivityTables();
}

function getActiveTabKey() { var at=document.querySelector('#reportTabs .nav-link.active'); if(!at)return'properties'; var id=at.id; if(id.indexOf('properties')!==-1)return'properties'; if(id.indexOf('sales')!==-1)return'sales'; if(id.indexOf('rentals')!==-1)return'rentals'; if(id.indexOf('agents')!==-1)return'agents'; if(id.indexOf('tours')!==-1)return'tours'; if(id.indexOf('activity')!==-1)return'activity'; return'properties'; }
function getActiveFilteredCount() { var k=getActiveTabKey(); if(k==='activity')return FILTERED.adminLogs.length+FILTERED.statusLogs.length+FILTERED.propertyLogs.length; return FILTERED[k]?FILTERED[k].length:0; }
function resetAllFilters() { document.getElementById('filterDateFrom').value=''; document.getElementById('filterDateTo').value=''; document.getElementById('filterCity').value=''; document.getElementById('filterAgent').value=''; document.querySelectorAll('.filter-prop-type,.filter-status,.filter-approval,.filter-tour-status').forEach(function(c){c.checked=true;}); applyFilters(); }

// Filter sidebar controls
document.getElementById('openFilterSidebar').addEventListener('click', function() { document.getElementById('filterSidebar').classList.add('active'); document.getElementById('filteredCount').textContent=getActiveFilteredCount(); });
document.getElementById('closeFilterBtn').addEventListener('click', function() { document.getElementById('filterSidebar').classList.remove('active'); });
document.getElementById('filterOverlay').addEventListener('click', function() { document.getElementById('filterSidebar').classList.remove('active'); });
document.getElementById('applyFiltersBtn').addEventListener('click', function() { applyFilters(); document.getElementById('filterSidebar').classList.remove('active'); });
document.getElementById('clearFiltersBtn').addEventListener('click', function() { resetAllFilters(); });
document.querySelectorAll('.filter-prop-type,.filter-status,.filter-approval,.filter-tour-status').forEach(function(cb) { cb.addEventListener('change', function() { applyFilters(); document.getElementById('filteredCount').textContent=getActiveFilteredCount(); }); });
['filterDateFrom','filterDateTo','filterCity','filterAgent'].forEach(function(id) { var el=document.getElementById(id); if(el){el.addEventListener('change',function(){applyFilters();document.getElementById('filteredCount').textContent=getActiveFilteredCount();});} });
document.querySelectorAll('#reportTabs .nav-link').forEach(function(tab) { tab.addEventListener('shown.bs.tab', function() { document.getElementById('filteredCount').textContent=getActiveFilteredCount(); }); });

// =============================================
// EXPORT DATA
// =============================================
function getExportData() {
    var key = getActiveTabKey(), headers = [], rows = [], title = '';
    switch(key) {
        case 'properties': title='Property Report'; headers=['#','Address','City','Province','Type','Beds','Baths','Sq Ft','Listing Price','Status','Approval','Views','Likes','Listed Date','Posted By'];
            rows=FILTERED.properties.map(function(r,i){return[i+1,r.StreetAddress||'',r.City||'',r.Province||'',r.PropertyType||'',r.Bedrooms!=null?r.Bedrooms:'',r.Bathrooms!=null?r.Bathrooms:'',r.SquareFootage||'',r.ListingPrice?formatPriceText(r.ListingPrice):'',r.Status||'',r.approval_status||'',r.ViewsCount!=null?r.ViewsCount:0,r.Likes!=null?r.Likes:0,r.ListingDate||'',r.posted_by||''];}); break;
        case 'sales': title='Sales Report'; headers=['#','Property','City','Type','Buyer','Email','Sale Price','Sale Date','Agent','Commission','%','Status','Finalized By','Finalized At'];
            rows=FILTERED.sales.map(function(r,i){return[i+1,r.StreetAddress||'',r.City||'',r.PropertyType||'',r.buyer_name||'',r.buyer_email||'',r.final_sale_price?formatPriceText(r.final_sale_price):'',r.sale_date||'',r.agent_name||'',r.commission_amount?formatPriceText(r.commission_amount):'',r.commission_percentage?r.commission_percentage+'%':'',r.commission_status||'',r.finalized_by_name||'',r.finalized_at||''];}); break;
        case 'agents': title='Agent Performance Report'; headers=['#','Agent','Email','Phone','License','Exp.','Specializations','Active','Total','Sales','Revenue','Commission','Tours','Completed','Status','Registered'];
            rows=FILTERED.agents.map(function(r,i){return[i+1,(r.first_name||'')+' '+(r.last_name||''),r.email||'',r.phone_number||'',r.license_number||'',(r.years_experience!=null?r.years_experience:'')+' yrs',r.specializations||'',r.active_listings,r.total_listings,r.total_sales,r.total_revenue?formatPriceText(r.total_revenue):formatPriceText(0),r.total_commission?formatPriceText(r.total_commission):formatPriceText(0),r.total_tours,r.completed_tours,r.is_approved==1?'Approved':'Pending',r.date_registered||''];}); break;
        case 'rentals': title='Rental Report'; headers=['#','Property','City','Type','Tenant','Monthly Rent','Deposit','Lease Start','Lease End','Term','Comm %','Collected','Commission','Payments','Status','Agent','Finalized'];
            rows=FILTERED.rentals.map(function(r,i){return[i+1,r.StreetAddress||'',r.City||'',r.PropertyType||'',r.tenant_name||'',r.monthly_rent?formatPriceText(r.monthly_rent):'',r.security_deposit?formatPriceText(r.security_deposit):'',r.lease_start_date||'',r.lease_end_date||'',r.lease_term_months?r.lease_term_months+' mo':'',r.commission_rate?r.commission_rate+'%':'',r.total_collected?formatPriceText(r.total_collected):'',r.total_commission?formatPriceText(r.total_commission):'',((r.confirmed_payments||0)+'/'+(parseInt(r.confirmed_payments||0)+parseInt(r.pending_payments||0))),r.lease_status||'Active',r.agent_name||'',r.finalized_at||''];}); break;
        case 'tours': title='Tour Requests Report'; headers=['#','Visitor','Email','Phone','Property','City','Tour Date','Time','Type','Status','Agent','Requested','Confirmed','Completed'];
            rows=FILTERED.tours.map(function(r,i){return[i+1,r.user_name||'',r.user_email||'',r.user_phone||'',r.StreetAddress||'',r.City||'',r.tour_date||'',r.tour_time||'',r.tour_type||'',r.request_status||'',r.agent_name||'',r.requested_at||'',r.confirmed_at||'',r.completed_at||''];}); break;
        case 'activity': title='System Activity Report'; headers=['#','Source','Action','Item','Details','By','Timestamp']; rows=[];
            FILTERED.adminLogs.forEach(function(r){rows.push([rows.length+1,'Admin Log',r.action||'','\u2014',r.description||'',r.admin_name||'',r.log_timestamp||'']);});
            FILTERED.statusLogs.forEach(function(r){rows.push([rows.length+1,'Status Change',r.action||'',r.item_type+' #'+r.item_id,r.reason_message||'',r.action_by||'',r.log_timestamp||'']);});
            FILTERED.propertyLogs.forEach(function(r){rows.push([rows.length+1,'Property Log',r.action||'',r.StreetAddress||'',r.reason_message||'',r.action_by||'',r.log_timestamp||'']);}); break;
    }
    return {title:title,headers:headers,rows:rows,key:key};
}

// =============================================
// EXPORT PREVIEW MODAL
// =============================================
var pendingExportType = null;
function openExportPreview(type) {
    pendingExportType = type;
    var ed = getExportData();
    document.getElementById('exportModalTitle').textContent = ed.title + ' \u2014 Preview';
    document.getElementById('exportRowCount').textContent = ed.rows.length;
    document.getElementById('exportTabName').textContent = ed.title;
    var h = '<table class="export-preview-table"><thead><tr>';
    ed.headers.forEach(function(hd) { h += '<th>'+hd+'</th>'; }); h += '</tr></thead><tbody>';
    var preview = ed.rows.slice(0, 100);
    preview.forEach(function(row) { h += '<tr>'; row.forEach(function(cell) { h += '<td>'+(cell!=null&&cell!=undefined?cell:'\u2014')+'</td>'; }); h += '</tr>'; });
    if (ed.rows.length > 100) h += '<tr><td colspan="'+ed.headers.length+'" style="text-align:center;color:#94a3b8;padding:1rem;font-style:italic;">&hellip; and '+(ed.rows.length-100)+' more records</td></tr>';
    h += '</tbody></table>';
    document.getElementById('exportPreviewBody').innerHTML = h;
    document.getElementById('confirmExportPDF').style.display = type==='pdf'?'inline-flex':'none';
    document.getElementById('confirmExportExcel').style.display = type==='excel'?'inline-flex':'none';
    document.getElementById('exportModalOverlay').classList.add('active');
}
function closeExportPreview() { document.getElementById('exportModalOverlay').classList.remove('active'); pendingExportType=null; }
document.getElementById('btnExportPDF').addEventListener('click', function(){ openExportPreview('pdf'); });
document.getElementById('btnExportExcel').addEventListener('click', function(){ openExportPreview('excel'); });
document.getElementById('closeExportModal').addEventListener('click', closeExportPreview);
document.getElementById('cancelExportBtn').addEventListener('click', closeExportPreview);
document.getElementById('exportModalOverlay').addEventListener('click', function(e){ if(e.target===document.getElementById('exportModalOverlay')) closeExportPreview(); });

// PDF Export — load logo as base64 once
var pdfLogoBase64 = null;
(function() {
    var img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = function() {
        var canvas = document.createElement('canvas');
        canvas.width = img.naturalWidth; canvas.height = img.naturalHeight;
        canvas.getContext('2d').drawImage(img, 0, 0);
        pdfLogoBase64 = canvas.toDataURL('image/png');
    };
    img.src = 'images/Logo.png';
})();

document.getElementById('confirmExportPDF').addEventListener('click', function() {
    var ed=getExportData(), jsPDFLib=window.jspdf, doc=new jsPDFLib.jsPDF({orientation:'landscape',unit:'mm',format:'a4'});
    var pw = doc.internal.pageSize.getWidth(), ph = doc.internal.pageSize.getHeight();

    function drawHeader(pageNum, totalPages) {
        // Dark header bar
        doc.setFillColor(22, 18, 9); doc.rect(0, 0, pw, 24, 'F');
        // Gold accent line
        doc.setFillColor(212, 175, 55); doc.rect(0, 24, pw, 1.2, 'F');
        // Logo
        var logoX = 10, textStartX = 10;
        if (pdfLogoBase64) { try { doc.addImage(pdfLogoBase64, 'PNG', 10, 3, 18, 18); textStartX = 31; } catch(e) {} }
        // Brand name
        doc.setTextColor(212, 175, 55); doc.setFontSize(15); doc.setFont('helvetica', 'bold');
        doc.text('HomeEstate Realty', textStartX, 11);
        // Subtitle
        doc.setTextColor(200, 200, 200); doc.setFontSize(8.5); doc.setFont('helvetica', 'normal');
        doc.text(ed.title + '  |  Generated: ' + new Date().toLocaleDateString('en-PH', {year:'numeric', month:'long', day:'numeric'}), textStartX, 17);
        // Record count & date badge
        doc.setFontSize(8); doc.setTextColor(148, 163, 184);
        doc.text('Total Records: ' + ed.rows.length, pw - 14, 11, {align: 'right'});
        doc.setFontSize(7); doc.text('Page ' + pageNum + ' of ' + totalPages, pw - 14, 17, {align: 'right'});
    }

    function drawFooter() {
        doc.setDrawColor(226, 232, 240); doc.setLineWidth(0.3); doc.line(10, ph - 12, pw - 10, ph - 12);
        doc.setFontSize(7); doc.setTextColor(148, 163, 184);
        doc.text('HomeEstate Realty  \u2022  Admin Reports  \u2022  Confidential', pw / 2, ph - 7, {align: 'center'});
    }

    // Table
    doc.autoTable({ head: [ed.headers], body: ed.rows, startY: 30, theme: 'grid',
        styles: { fontSize: 7, cellPadding: 2.5, overflow: 'linebreak', lineColor: [226, 232, 240], lineWidth: 0.2, textColor: [51, 65, 85] },
        headStyles: { fillColor: [15, 23, 42], textColor: [212, 175, 55], fontStyle: 'bold', fontSize: 6.5, cellPadding: 3, lineColor: [212, 175, 55], lineWidth: 0.4 },
        alternateRowStyles: { fillColor: [248, 250, 252] },
        margin: { top: 30, left: 10, right: 10, bottom: 16 },
        didDrawPage: function(data) {
            drawHeader(data.pageNumber, doc.internal.getNumberOfPages());
            drawFooter();
        }
    });
    // Fix total pages on all pages
    var totalPages = doc.internal.getNumberOfPages();
    for (var i = 1; i <= totalPages; i++) { doc.setPage(i); drawHeader(i, totalPages); drawFooter(); }

    doc.save(ed.title.toLowerCase().replace(/\s+/g, '_') + '_' + new Date().toISOString().slice(0, 10) + '.pdf');
    closeExportPreview();
});

// Excel Export
document.getElementById('confirmExportExcel').addEventListener('click', function() {
    var ed=getExportData(), wsData=[ed.headers].concat(ed.rows), ws=XLSX.utils.aoa_to_sheet(wsData);
    var colW=ed.headers.map(function(h,i){var m=h.length;ed.rows.forEach(function(r){var s=String(r[i]!=null?r[i]:'');if(s.length>m)m=s.length;});return{wch:Math.min(Math.max(m+2,10),40)};});
    ws['!cols']=colW;
    var wb=XLSX.utils.book_new(); XLSX.utils.book_append_sheet(wb,ws,ed.title.substring(0,31));
    XLSX.writeFile(wb,ed.title.toLowerCase().replace(/\s+/g,'_')+'_'+new Date().toISOString().slice(0,10)+'.xlsx');
    closeExportPreview();
});

// =============================================
// INIT
// =============================================
document.addEventListener('DOMContentLoaded', function() { applyFilters(); });
</script>

    <!-- ══════════════════════════════════════════════════════
         SKELETON HYDRATION SCRIPT
         Waits for window 'load' (fonts + CSS ready) then
         cross-fades skeleton out and real content in.
    ════════════════════════════════════════════════════════ -->
    <script>
    (function () {
        'use strict';
        var MIN_SKELETON_MS = 400;
        var skeletonStart   = Date.now();
        var hydrated        = false;
        function hydrate() {
            if (hydrated) return; hydrated = true;
            var sk = document.getElementById('sk-screen');
            var pc = document.getElementById('page-content');
            if (!sk || !pc) return;
            sk.style.transition = 'opacity 0.35s ease'; sk.style.opacity = '0';
            setTimeout(function () { sk.style.display = 'none'; }, 360);
            pc.style.opacity = '0'; pc.style.display = 'block';
            requestAnimationFrame(function () { pc.style.transition = 'opacity 0.4s ease'; pc.style.opacity = '1'; });
            setTimeout(function () { document.dispatchEvent(new CustomEvent('skeleton:hydrated')); }, 520);
        }
        function scheduleHydration() {
            var elapsed   = Date.now() - skeletonStart;
            var remaining = MIN_SKELETON_MS - elapsed;
            if (remaining <= 0) { hydrate(); } else { setTimeout(hydrate, remaining); }
        }
        window.addEventListener('load', scheduleHydration);
    }());
    </script>
</body>
</html>
