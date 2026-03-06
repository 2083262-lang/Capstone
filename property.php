<?php
session_start();
include 'connection.php'; // Include your database connection
require_once __DIR__ . '/config/session_timeout.php';
require_once __DIR__ . '/config/paths.php';

// Check if the user is logged in AND their role is 'admin'
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// --- FILTERING LOGIC (remains the same) ---
$filter_conditions = [];
$filter_params = [];
$filter_types = '';
if ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['apply_filters'])) {
    // (Your existing filtering code would go here)
}

// --- CORRECTED SQL QUERY ---
// This query has been reverted to your required method, using property_log.
$sql = "SELECT 
            p.*, 
            pi.PhotoURL,
            a.account_id AS poster_account_id,
            a.first_name AS poster_first_name,
            a.last_name AS poster_last_name,
            ur.role_name AS poster_role_name,
            rd.monthly_rent AS rd_monthly_rent,
            rd.security_deposit AS rd_security_deposit,
            rd.lease_term_months AS rd_lease_term_months,
            rd.furnishing AS rd_furnishing,
            rd.available_from AS rd_available_from
        FROM property p
        LEFT JOIN property_images pi ON p.property_ID = pi.property_ID AND pi.SortOrder = 1
        LEFT JOIN property_log pl ON p.property_ID = pl.property_id AND pl.action = 'CREATED'
        LEFT JOIN accounts a ON pl.account_id = a.account_id
        LEFT JOIN user_roles ur ON a.role_id = ur.role_id
        LEFT JOIN rental_details rd ON rd.property_id = p.property_ID";

if (!empty($filter_conditions)) {
    $sql .= " WHERE " . implode(' AND ', $filter_conditions);
}

$sql .= " GROUP BY p.property_ID ORDER BY FIELD(p.approval_status, 'pending', 'approved', 'rejected'), p.ListingDate DESC";


$properties = [];
if ($stmt = $conn->prepare($sql)) {
    if (!empty($filter_params)) {
        // (Your existing parameter binding logic for filters remains here)
    }
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $properties = $result->fetch_all(MYSQLI_ASSOC);
    } else { 
        echo "Error executing query: " . $stmt->error; 
    }
    $stmt->close();
} else { 
    echo "Error preparing query: " . $conn->error; 
}

// --- Separate properties into categories ---
// Note: 'approval_status' is the admin workflow status (pending/approved/rejected)
// 'Status' is the listing status (For Sale/For Rent/Sold)
$pending_properties = array_filter($properties, fn($p) => ($p['approval_status'] ?? '') == 'pending' && !(isset($p['Status']) && in_array(strtolower(trim($p['Status'])), ['sold','pending sold','rented','pending rented'])));
$approved_properties = array_filter($properties, fn($p) => ($p['approval_status'] ?? '') == 'approved' && !(isset($p['Status']) && in_array(strtolower(trim($p['Status'])), ['sold','pending sold','rented','pending rented'])));
$rejected_properties = array_filter($properties, fn($p) => ($p['approval_status'] ?? '') == 'rejected' && !(isset($p['Status']) && in_array(strtolower(trim($p['Status'])), ['sold','pending sold','rented','pending rented'])));
// Pending Sold is represented by Status = 'Pending Sold'; Sold is Status = 'Sold'
$pending_sold_properties = array_filter($properties, fn($p) => isset($p['Status']) && strtolower(trim($p['Status'])) === 'pending sold');
$sold_properties = array_filter($properties, fn($p) => isset($p['Status']) && strtolower(trim($p['Status'])) === 'sold');
// Rented properties
$rented_properties = array_filter($properties, fn($p) => isset($p['Status']) && strtolower(trim($p['Status'])) === 'rented');
$pending_rented_properties = array_filter($properties, fn($p) => isset($p['Status']) && strtolower(trim($p['Status'])) === 'pending rented');

// Fetch property types for filters
$property_types_db = [];
$pt_result = $conn->query("SELECT type_name FROM property_types ORDER BY type_name ASC");
if ($pt_result) { while ($pt_row = $pt_result->fetch_assoc()) { $property_types_db[] = $pt_row['type_name']; } }

// Fetch unique listers (accounts who created properties)
$listers_db = [];
$listers_result = $conn->query("
    SELECT DISTINCT a.account_id, a.first_name, a.last_name, ur.role_name
    FROM accounts a
    JOIN property_log pl ON a.account_id = pl.account_id AND pl.action = 'CREATED'
    JOIN property p ON pl.property_id = p.property_ID
    LEFT JOIN user_roles ur ON a.role_id = ur.role_id
    ORDER BY a.first_name ASC, a.last_name ASC
");
if ($listers_result) { while ($lr = $listers_result->fetch_assoc()) { $listers_db[] = $lr; } }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Property Management - Admin Panel</title>
    <link href="<?= ASSETS_CSS ?>bootstrap.min.css" rel="stylesheet">
    <link href="<?= ASSETS_CSS ?>inter-font.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= ASSETS_CSS ?>fontawesome-all.min.css">
    <link rel="stylesheet" href="<?= ASSETS_CSS ?>bootstrap-icons.min.css">
    
    <style>
        /* ================================================
           ADMIN PROPERTY PAGE
           Structure matches admin_dashboard.php exactly:
           - Simple :root for page-specific vars only
           - Hardcoded sidebar/content layout (no variable overrides)
           - No wildcard resets
           - No preload hacks
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

        /* ===== PAGE-SPECIFIC VARIABLES (used only by property page elements) ===== */
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
        }

        .page-header .header-badge {
            background: linear-gradient(135deg, var(--gold-dark), var(--gold));
            color: #fff;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.3rem 0.85rem;
            border-radius: 2px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* ===== KPI STAT CARDS ===== */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
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

        .kpi-icon.purple {
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.06) 0%, rgba(139, 92, 246, 0.12) 100%);
            color: #7c3aed;
            border: 1px solid rgba(139, 92, 246, 0.15);
        }

        .kpi-icon.pink {
            background: linear-gradient(135deg, rgba(236, 72, 153, 0.06) 0%, rgba(236, 72, 153, 0.12) 100%);
            color: #db2777;
            border: 1px solid rgba(236, 72, 153, 0.15);
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

        /* ===== ACTION BAR ===== */
        .action-bar {
            background: var(--card-bg);
            border: 1px solid rgba(37, 99, 235, 0.1);
            border-radius: 4px;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            position: relative;
            overflow: hidden;
        }

        .action-bar::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent);
        }

        .action-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text-primary, #0f172a);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .action-title i { color: var(--gold-dark); }

        .action-buttons {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .btn-gold {
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 50%, var(--gold-dark) 100%);
            color: #fff;
            border: none;
            padding: 0.6rem 1.25rem;
            font-size: 0.85rem;
            font-weight: 700;
            border-radius: 4px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(212, 175, 55, 0.25);
            position: relative;
            overflow: hidden;
        }

        .btn-gold::before {
            content: '';
            position: absolute;
            top: 0; left: -100%; width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s ease;
        }

        .btn-gold:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(212, 175, 55, 0.35);
            color: #fff;
        }

        .btn-gold:hover::before { left: 100%; }

        .btn-outline-admin {
            background: var(--card-bg);
            color: var(--text-secondary, #64748b);
            border: 1px solid #e2e8f0;
            padding: 0.6rem 1.25rem;
            font-size: 0.85rem;
            font-weight: 600;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            cursor: pointer;
            text-decoration: none;
        }

        .btn-outline-admin:hover {
            border-color: var(--blue);
            color: var(--blue);
            background: rgba(37, 99, 235, 0.03);
        }

        .filter-count-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            height: 20px;
            padding: 0 6px;
            background: var(--blue);
            color: #fff;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 700;
        }

        /* ===== TABS ===== */
        /* NOTE: .property-tabs .nav-link styles below are scoped to property tabs only */
        /* Sidebar .nav-link styles are in admin_sidebar.php (scoped to .admin-sidebar) */
        /* This prevents CSS conflicts between sidebar navigation and property tabs */
        .property-tabs {
            background: var(--card-bg);
            border: 1px solid rgba(37, 99, 235, 0.1);
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 1.5rem;
            position: relative;
        }

        .property-tabs::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent);
            z-index: 5;
        }

        .property-tabs .nav-tabs {
            border-bottom: 1px solid #e2e8f0;
            padding: 0.25rem 0.5rem 0;
            gap: 0.25rem;
            background: linear-gradient(180deg, #fafbfc 0%, var(--card-bg) 100%);
        }

        .property-tabs .nav-item {
            margin-bottom: 0;
        }

        .property-tabs .nav-link {
            border: none;
            border-bottom: 3px solid transparent;
            background: transparent;
            color: var(--text-secondary, #64748b);
            font-weight: 600;
            font-size: 0.85rem;
            padding: 0.85rem 1.25rem;
            border-radius: 0;
        }

        .property-tabs .nav-link:hover {
            color: var(--text-primary, #0f172a);
            background: rgba(37, 99, 235, 0.03);
            border-bottom-color: rgba(37, 99, 235, 0.2);
        }

        .property-tabs .nav-link.active {
            color: var(--gold-dark);
            background: rgba(212, 175, 55, 0.03);
            border-bottom-color: var(--gold);
        }

        .tab-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 22px;
            height: 22px;
            padding: 0 0.4rem;
            border-radius: 2px;
            font-size: 0.7rem;
            font-weight: 700;
            margin-left: 0.5rem;
        }

        .badge-approved { background: rgba(34, 197, 94, 0.1); color: #16a34a; border: 1px solid rgba(34, 197, 94, 0.15); }
        .badge-pending { background: rgba(245, 158, 11, 0.1); color: #d97706; border: 1px solid rgba(245, 158, 11, 0.15); }
        .badge-rejected { background: rgba(239, 68, 68, 0.1); color: #dc2626; border: 1px solid rgba(239, 68, 68, 0.15); }
        .badge-sold { background: rgba(100, 116, 139, 0.1); color: #64748b; border: 1px solid rgba(100, 116, 139, 0.15); }
        .badge-pending-sold { background: rgba(6, 182, 212, 0.1); color: #0891b2; border: 1px solid rgba(6, 182, 212, 0.15); }
        .badge-rented { background: rgba(139, 92, 246, 0.1); color: #7c3aed; border: 1px solid rgba(139, 92, 246, 0.15); }
        .badge-pending-rented { background: rgba(236, 72, 153, 0.1); color: #db2777; border: 1px solid rgba(236, 72, 153, 0.15); }

        .tab-content { padding: 1.5rem; }

        .properties-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 1.25rem;
        }

        /* ===== ADMIN PROPERTY CARD ===== */
        .admin-prop-card {
            background: var(--card-bg);
            border: 1px solid rgba(37, 99, 235, 0.1);
            border-radius: 4px;
            overflow: hidden;
            transition: all 0.3s ease;
            height: 100%;
            display: flex;
            flex-direction: column;
            position: relative;
        }

        .admin-prop-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, var(--gold), var(--blue));
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: 5;
        }

        .admin-prop-card:hover {
            border-color: rgba(37, 99, 235, 0.25);
            box-shadow: 0 8px 32px rgba(37, 99, 235, 0.08);
            transform: translateY(-4px);
        }

        .admin-prop-card:hover::before { opacity: 1; }

        /* Card Image */
        .admin-prop-card .card-img-wrap {
            position: relative;
            height: 200px;
            overflow: hidden;
            background: #f1f5f9;
        }

        .admin-prop-card .card-img-wrap img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }

        .admin-prop-card:hover .card-img-wrap img {
            transform: scale(1.05);
        }

        .admin-prop-card .img-overlay {
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 60%;
            background: linear-gradient(to top, rgba(0, 0, 0, 0.65) 0%, transparent 100%);
            pointer-events: none;
        }

        /* Badges */
        .admin-prop-card .status-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.25rem 0.65rem;
            border-radius: 2px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            z-index: 3;
        }

        .status-badge.approved { background: rgba(34, 197, 94, 0.9); color: #fff; }
        .status-badge.pending { background: rgba(245, 158, 11, 0.9); color: #fff; }
        .status-badge.rejected { background: rgba(239, 68, 68, 0.9); color: #fff; }
        .status-badge.pending-sold { background: rgba(6, 182, 212, 0.9); color: #fff; }
        .status-badge.sold { background: rgba(100, 116, 139, 0.9); color: #fff; }
        .status-badge.rented { background: rgba(139, 92, 246, 0.9); color: #fff; }
        .status-badge.pending-rented { background: rgba(236, 72, 153, 0.9); color: #fff; }

        .admin-prop-card .listing-badge {
            position: absolute;
            top: 12px;
            left: 12px;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.25rem 0.65rem;
            border-radius: 2px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            z-index: 3;
        }

        .listing-badge.for-sale { background: rgba(37, 99, 235, 0.9); color: #fff; }
        .listing-badge.for-rent { background: rgba(212, 175, 55, 0.9); color: #fff; }
        .listing-badge.is-sold { background: rgba(100, 116, 139, 0.8); color: #fff; }
        .listing-badge.is-rented { background: rgba(139, 92, 246, 0.8); color: #fff; }

        .admin-prop-card .type-badge {
            position: absolute;
            bottom: 12px;
            left: 14px;
            padding: 0.2rem 0.6rem;
            border-radius: 2px;
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            z-index: 3;
            background: rgba(0, 0, 0, 0.7);
            color: #e2e8f0;
            backdrop-filter: blur(4px);
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .admin-prop-card .price-overlay {
            position: absolute;
            bottom: 12px;
            right: 14px;
            z-index: 3;
        }

        .admin-prop-card .price-overlay .price {
            font-size: 1.3rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            text-shadow: none;
            filter: drop-shadow(0 1px 2px rgba(0,0,0,0.5));
        }

        .admin-prop-card .price-overlay .price-suffix {
            font-size: 0.7rem;
            color: #cbd5e1;
            font-weight: 600;
        }

        /* Card Body */
        .admin-prop-card .card-body-content {
            padding: 1.25rem;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .admin-prop-card .prop-address {
            font-weight: 700;
            font-size: 0.95rem;
            color: var(--text-primary, #0f172a);
            margin-bottom: 0.2rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .admin-prop-card .prop-location {
            font-size: 0.8rem;
            color: var(--text-secondary, #64748b);
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .admin-prop-card .prop-location i {
            color: var(--blue);
            font-size: 0.75rem;
        }

        /* Stats Row */
        .admin-prop-card .stats-row {
            display: flex;
            gap: 1rem;
            padding: 0.75rem 0;
            border-top: 1px solid #e2e8f0;
            border-bottom: 1px solid #e2e8f0;
            margin-bottom: 0.75rem;
        }

        .admin-prop-card .stat-item {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.8rem;
            color: var(--text-secondary, #64748b);
        }

        .admin-prop-card .stat-item i {
            color: var(--gold);
            font-size: 0.75rem;
        }

        .admin-prop-card .stat-item strong {
            color: var(--text-primary, #0f172a);
            font-weight: 700;
        }

        /* Meta Row */
        .admin-prop-card .meta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
            font-size: 0.75rem;
            color: var(--text-secondary, #64748b);
        }

        .admin-prop-card .meta-item {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            background: #f8fafc;
            padding: 0.2rem 0.5rem;
            border-radius: 2px;
            border: 1px solid #e2e8f0;
            font-weight: 500;
        }

        .admin-prop-card .meta-item i {
            color: #94a3b8;
            font-size: 0.7rem;
        }

        /* Rental Info */
        .admin-prop-card .rental-info-section {
            background: #fffbeb;
            border: 1px solid rgba(212, 175, 55, 0.2);
            border-radius: 4px;
            padding: 0.6rem 0.8rem;
            margin-bottom: 0.75rem;
            font-size: 0.75rem;
            color: #92400e;
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
        }

        .admin-prop-card .rental-tag {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            background: #fff;
            padding: 0.2rem 0.5rem;
            border-radius: 2px;
            border: 1px solid rgba(212, 175, 55, 0.15);
        }

        .admin-prop-card .rental-tag i {
            color: #d97706;
        }

        /* Footer */
        .admin-prop-card .card-footer-section {
            margin-top: auto;
            padding-top: 0.75rem;
            border-top: 1px solid #e2e8f0;
        }

        .admin-prop-card .posted-by {
            font-size: 0.75rem;
            color: #94a3b8;
            margin-bottom: 0.75rem;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.3rem;
        }

        .admin-prop-card .posted-by i { color: #cbd5e1; }

        .admin-prop-card .btn-manage {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            width: 100%;
            background: linear-gradient(135deg, var(--blue-dark) 0%, var(--blue) 100%);
            color: #fff;
            border: none;
            padding: 0.6rem;
            font-size: 0.8rem;
            font-weight: 700;
            border-radius: 4px;
            text-decoration: none;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.2);
        }

        .admin-prop-card .btn-manage:hover {
            box-shadow: 0 4px 16px rgba(37, 99, 235, 0.3);
            transform: translateY(-1px);
            color: #fff;
        }

        /* ===== EMPTY STATE ===== */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--text-secondary, #64748b);
        }

        .empty-state i {
            font-size: 3rem;
            color: rgba(37, 99, 235, 0.15);
            margin-bottom: 1rem;
            display: block;
        }

        .empty-state-icon {
            font-size: 3rem;
            color: rgba(37, 99, 235, 0.15);
            margin-bottom: 1rem;
        }

        .empty-state h4 {
            font-weight: 700;
            color: var(--text-primary, #0f172a);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            font-size: 0.9rem;
            color: #94a3b8;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 1400px) {
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 992px) {
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .properties-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .admin-content { padding: 1rem; }
            .page-header { padding: 1.25rem 1rem; }
            .page-header h1 { font-size: 1.3rem; }
            .page-header-inner { flex-direction: column; align-items: flex-start; gap: 0.75rem; }
            .action-bar { flex-direction: column; align-items: flex-start; gap: 0.5rem; }
            .action-bar > * { width: 100%; }
            .kpi-grid { grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
            .kpi-card { padding: 1rem; }
            .kpi-card .kpi-value { font-size: 1.25rem; }
            .property-tabs .nav-tabs { overflow-x: auto; flex-wrap: nowrap; -webkit-overflow-scrolling: touch; }
            .property-tabs .nav-link { padding: 0.65rem 0.85rem; font-size: 0.8rem; white-space: nowrap; }
            .pagination-bar { flex-direction: column; align-items: flex-start; gap: 0.5rem; }
        }

        @media (max-width: 576px) {
            .admin-content { padding: 0.75rem; }
            .page-header { padding: 1rem; }
            .page-header h1 { font-size: 1.15rem; }
            .kpi-grid { grid-template-columns: 1fr 1fr; gap: 0.5rem; }
            .kpi-card { padding: 0.85rem; }
            .kpi-card .kpi-value { font-size: 1.1rem; }
            .kpi-card .kpi-label { font-size: 0.65rem; }
            .property-tabs .nav-link { padding: 0.55rem 0.7rem; font-size: 0.75rem; }
            .tab-badge { display: none; }
        }

        /* ===== FILTER SIDEBAR ===== */
        .filter-sidebar {
            position: fixed;
            top: 0;
            right: 0;
            width: 100%;
            height: 100%;
            z-index: 9999;
            pointer-events: none;
        }

        .filter-sidebar.active { pointer-events: all; }

        .filter-sidebar-overlay {
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.4);
            opacity: 0;
            transition: opacity 0.2s ease;
            pointer-events: none;
        }

        .filter-sidebar.active .filter-sidebar-overlay {
            opacity: 1;
            pointer-events: all;
        }

        .filter-sidebar-content {
            position: absolute;
            top: 0; right: 0;
            width: 480px;
            max-width: 90vw;
            height: 100%;
            background: #ffffff;
            border-left: 1px solid rgba(37, 99, 235, 0.15);
            box-shadow: -8px 0 32px rgba(15, 23, 42, 0.1);
            transform: translateX(100%);
            transition: transform 0.25s ease;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .filter-sidebar.active .filter-sidebar-content {
            transform: translateX(0);
        }

        .filter-header {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #fff;
            padding: 1.5rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }

        .filter-header::after {
            content: '';
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, var(--gold), var(--blue));
        }

        .filter-header h4 {
            font-weight: 700;
            font-size: 1.15rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin: 0;
        }

        .filter-header h4 i {
            color: var(--gold);
            font-size: 1.3rem;
        }

        .btn-close-filter {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #fff;
            width: 36px;
            height: 36px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 1rem;
        }

        .btn-close-filter:hover {
            background: rgba(239, 68, 68, 0.2);
            border-color: rgba(239, 68, 68, 0.4);
        }

        .filter-body {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem;
            background: #f8fafc;
        }

        .filter-section {
            background: #fff;
            border-radius: 4px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            border: 1px solid #e2e8f0;
        }

        .filter-section:last-child { margin-bottom: 0; }

        .filter-section-title {
            font-weight: 700;
            font-size: 0.8rem;
            color: var(--text-primary, #0f172a);
            margin-bottom: 1rem;
            padding-bottom: 0.6rem;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .filter-section-title i {
            color: var(--gold);
            font-size: 1rem;
        }

        .filter-search-box { position: relative; }

        .filter-search-box input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.75rem;
            border-radius: 4px;
            border: 1px solid #e2e8f0;
            background: #fff;
            transition: all 0.2s ease;
            font-size: 0.875rem;
            color: var(--text-primary, #0f172a);
        }

        .filter-search-box input::placeholder { color: #94a3b8; }

        .filter-search-box input:focus {
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.08);
            outline: none;
        }

        .filter-search-box i {
            position: absolute;
            left: 0.85rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 1rem;
        }

        /* Price Slider */
        .price-slider-container {
            position: relative;
            height: 40px;
            margin-bottom: 1.25rem;
        }

        .price-slider-track {
            position: absolute;
            top: 50%; left: 0; right: 0;
            height: 6px;
            background: #e2e8f0;
            border-radius: 3px;
            transform: translateY(-50%);
        }

        .price-slider-range {
            position: absolute;
            height: 100%;
            background: linear-gradient(90deg, var(--gold-dark), var(--gold));
            border-radius: 3px;
        }

        .price-range-slider {
            position: absolute;
            width: 100%; height: 6px;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            pointer-events: none;
            -webkit-appearance: none;
            appearance: none;
        }

        .price-range-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 20px; height: 20px;
            border-radius: 50%;
            background: #fff;
            border: 3px solid var(--gold);
            cursor: pointer;
            pointer-events: all;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
        }

        .price-range-slider::-webkit-slider-thumb:hover {
            box-shadow: 0 3px 10px rgba(212, 175, 55, 0.3);
        }

        .price-range-slider::-moz-range-thumb {
            width: 20px; height: 20px;
            border-radius: 50%;
            background: #fff;
            border: 3px solid var(--gold);
            cursor: pointer;
            pointer-events: all;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
        }

        .price-range-inputs {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            gap: 0.75rem;
            align-items: center;
        }

        .price-input { position: relative; }

        .price-input input {
            width: 100%;
            padding: 0.6rem 0.75rem 0.6rem 2rem;
            border-radius: 4px;
            border: 1px solid #e2e8f0;
            background: #fff;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-primary, #0f172a);
            transition: all 0.2s ease;
        }

        .price-input input:focus {
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.08);
            outline: none;
        }

        .price-input .currency-symbol {
            position: absolute;
            left: 0.65rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gold-dark);
            font-weight: 700;
            font-size: 0.8rem;
        }

        .range-divider {
            color: #94a3b8;
            font-weight: 600;
        }

        /* Filter Chips */
        .filter-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .filter-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.5rem 0.85rem;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 2px;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.8125rem;
            font-weight: 500;
            color: var(--text-primary, #0f172a);
        }

        .filter-chip:hover {
            background: #f8fafc;
            border-color: var(--gold);
        }

        .filter-chip.active {
            background: linear-gradient(135deg, var(--gold-dark), var(--gold));
            color: #fff;
            border-color: var(--gold);
            font-weight: 600;
        }

        .filter-chip input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
            accent-color: var(--gold);
        }

        .filter-select {
            width: 100%;
            padding: 0.6rem 0.85rem;
            border-radius: 4px;
            border: 1px solid #e2e8f0;
            background: #fff;
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--text-primary, #0f172a);
            transition: all 0.2s ease;
        }

        .filter-select:focus {
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.08);
            outline: none;
        }

        .year-inputs {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            gap: 0.6rem;
            align-items: center;
        }

        .year-inputs input,
        .year-inputs input[type="date"] {
            width: 100%;
            min-width: 0;
            padding: 0.6rem 0.75rem;
            border-radius: 4px;
            border: 1px solid #e2e8f0;
            background: #fff;
            font-size: 0.8125rem;
            font-weight: 500;
            color: var(--text-primary, #0f172a);
            transition: all 0.2s ease;
        }

        .year-inputs input:focus,
        .year-inputs input[type="date"]:focus {
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.08);
            outline: none;
        }

        .year-inputs .range-divider {
            color: #94a3b8;
            font-weight: 600;
            font-size: 1rem;
        }

        .quick-filters {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.6rem;
            flex-wrap: wrap;
        }

        .quick-filter-btn {
            padding: 0.4rem 0.85rem;
            border: 1px solid #e2e8f0;
            background: #fff;
            border-radius: 2px;
            font-size: 0.75rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            color: var(--text-primary, #0f172a);
        }

        .quick-filter-btn:hover {
            border-color: var(--gold);
            background: #fffbeb;
        }

        .quick-filter-btn.active {
            background: linear-gradient(135deg, var(--gold-dark), var(--gold));
            color: #fff;
            border-color: var(--gold);
            font-weight: 600;
        }

        .filter-footer {
            padding: 1.25rem 1.5rem;
            background: #fff;
            border-top: 1px solid #e2e8f0;
            display: flex;
            gap: 0.75rem;
        }

        .filter-footer .btn {
            flex: 1;
            padding: 0.7rem 1.25rem;
            font-weight: 600;
            border-radius: 4px;
            transition: all 0.2s ease;
            font-size: 0.85rem;
        }

        .filter-footer .btn:hover {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .filter-footer .btn-outline-secondary {
            background: #fff;
            border: 1px solid #e2e8f0;
            color: var(--text-secondary, #64748b);
        }

        .filter-footer .btn-outline-secondary:hover {
            border-color: rgba(239, 68, 68, 0.3);
            color: #dc2626;
            background: rgba(239, 68, 68, 0.03);
        }

        .filter-footer .btn-primary {
            background: linear-gradient(135deg, var(--blue-dark), var(--blue));
            border: none;
            color: #fff;
        }

        .filter-footer .btn-primary:hover {
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
        }

        .filter-results-summary {
            background: rgba(37, 99, 235, 0.04);
            border: 1px solid rgba(37, 99, 235, 0.12);
            border-radius: 4px;
            padding: 0.85rem 1rem;
            margin-top: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .filter-results-summary i {
            color: var(--blue);
            font-size: 1.25rem;
        }

        .filter-results-text { flex: 1; }

        .filter-results-count {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--blue);
        }

        .filter-results-label {
            font-size: 0.8rem;
            color: var(--text-secondary, #64748b);
        }

        .filter-range-slider { margin-top: 0.75rem; }

        .filter-range-slider input[type="range"] {
            width: 100%;
            height: 6px;
            border-radius: 3px;
            outline: none;
            -webkit-appearance: none;
        }

        .range-values {
            display: flex;
            justify-content: space-between;
            margin-top: 0.4rem;
            font-size: 0.8rem;
            color: var(--text-secondary, #64748b);
            font-weight: 600;
        }

        /* ===== PAGINATION ===== */
        .pagination-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.75rem;
            padding: 1.25rem 0.25rem 0.5rem;
            margin-top: 0.75rem;
            border-top: 1px solid rgba(212,175,55,0.18);
        }
        .pagination-info {
            font-size: 0.82rem;
            color: #64748b;
            font-weight: 500;
        }
        .pagination-controls {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        .page-btn {
            min-width: 36px;
            height: 36px;
            padding: 0 0.6rem;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: #fff;
            color: #374151;
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.18s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-family: inherit;
            line-height: 1;
        }
        .page-btn:hover:not(:disabled):not(.active) {
            background: #f8f9fa;
            border-color: #d4af37;
            color: #d4af37;
        }
        .page-btn.active {
            background: linear-gradient(135deg, #d4af37, #b8941f);
            border-color: #d4af37;
            color: #fff;
            font-weight: 700;
            box-shadow: 0 2px 8px rgba(212,175,55,0.35);
        }
        .page-btn:disabled {
            opacity: 0.35;
            cursor: not-allowed;
        }
        .page-ellipsis {
            padding: 0 0.35rem;
            color: #94a3b8;
            font-size: 0.9rem;
            line-height: 36px;
        }
        @media (max-width: 576px) {
            .pagination-bar { flex-direction: column; align-items: flex-start; }
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
           SKELETON SCREEN — Property Management (CSR / Progressive Hydration)
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

        /* 4-column KPI grid — matches .kpi-grid on this page */
        .sk-kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
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

        .sk-action-bar {
            background: #fff;
            border: 1px solid rgba(37,99,235,0.1);
            border-radius: 4px;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            position: relative;
            overflow: hidden;
        }
        .sk-action-bar::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, #e8e3d0, #d4e0f7, transparent);
        }

        .sk-tabs {
            background: #fff;
            border: 1px solid rgba(37,99,235,0.1);
            border-radius: 4px;
            margin-bottom: 1.5rem;
            padding: 0 0.5rem;
            display: flex;
            gap: 0.25rem;
            align-items: center;
            height: 54px;
            position: relative;
            overflow: hidden;
        }
        .sk-tabs::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, #e8e3d0, #d4e0f7, transparent);
        }

        .sk-content-wrap {
            background: #fff;
            border: 1px solid rgba(37,99,235,0.1);
            border-radius: 4px;
            overflow: hidden;
        }
        /* matches .properties-grid minmax(380px, 1fr) */
        .sk-prop-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 1.25rem;
            padding: 1.5rem;
        }
        .sk-prop-card {
            background: #fff;
            border: 1px solid rgba(37,99,235,0.1);
            border-radius: 4px;
            overflow: hidden;
        }
        .sk-card-img    { height: 200px; width: 100%; }
        .sk-card-body   { padding: 1.25rem; }
        .sk-card-footer { padding: 0 1.25rem 1.25rem; }
        .sk-line        { display: block; border-radius: 4px; }

        @media (max-width: 1400px) { .sk-kpi-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 992px)  {
            .sk-kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .sk-prop-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px)  { .sk-kpi-grid { grid-template-columns: repeat(2, 1fr); gap: 0.75rem; } }
        @media (max-width: 576px)  { .sk-kpi-grid { grid-template-columns: 1fr 1fr; gap: 0.5rem; } }

    </style>
</head>
<body>

    <!-- Include Sidebar Component -->
    <?php include 'admin_sidebar.php'; ?>
    
    <!-- Include Navbar Component -->
    <?php include 'admin_navbar.php'; ?>

    <!-- Main Content Area -->
    <div class="admin-content">

        <!-- NO-JS FALLBACK: show real content if JS is disabled -->
        <noscript><style>
            #sk-screen    { display: none !important; }
            #page-content { display: block !important; opacity: 1 !important; }
        </style></noscript>

        <!-- ═════════════════════════════════════════════════════════
             SKELETON SCREEN — renders on first paint, removed by JS
             ═════════════════════════════════════════════════════════ -->
        <div id="sk-screen" role="presentation" aria-hidden="true">

            <!-- Page Header -->
            <div class="sk-page-header">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;">
                    <div>
                        <div class="sk-line sk-shimmer" style="width:220px;height:22px;margin-bottom:10px;"></div>
                        <div class="sk-line sk-shimmer" style="width:380px;height:13px;"></div>
                    </div>
                </div>
            </div>

            <!-- KPI Cards (6-column) -->
            <div class="sk-kpi-grid">
                <?php for ($sk_i = 0; $sk_i < 6; $sk_i++): ?>
                <div class="sk-kpi-card">
                    <div class="sk-kpi-icon sk-shimmer"></div>
                    <div class="sk-line sk-shimmer" style="width:65%;height:10px;margin-bottom:8px;"></div>
                    <div class="sk-line sk-shimmer" style="width:45%;height:22px;"></div>
                </div>
                <?php endfor; ?>
            </div>

            <!-- Action Bar -->
            <div class="sk-action-bar">
                <div class="sk-shimmer" style="width:160px;height:18px;border-radius:3px;"></div>
                <div style="display:flex;gap:0.75rem;">
                    <div class="sk-shimmer" style="width:135px;height:36px;border-radius:4px;"></div>
                    <div class="sk-shimmer" style="width:150px;height:36px;border-radius:4px;"></div>
                </div>
            </div>

            <!-- Status Tabs (6 tabs: All, Pending, Pending Sold, Approved, Rejected, Sold) -->
            <div class="sk-tabs">
                <div class="sk-shimmer" style="width:55px;height:20px;border-radius:3px;"></div>
                <div class="sk-shimmer" style="width:80px;height:20px;border-radius:3px;"></div>
                <div class="sk-shimmer" style="width:110px;height:20px;border-radius:3px;"></div>
                <div class="sk-shimmer" style="width:85px;height:20px;border-radius:3px;"></div>
                <div class="sk-shimmer" style="width:80px;height:20px;border-radius:3px;"></div>
                <div class="sk-shimmer" style="width:60px;height:20px;border-radius:3px;"></div>
            </div>

            <!-- Property Card Grid (3 cards matching minmax 380px) -->
            <div class="sk-content-wrap">
                <div class="sk-prop-grid">
                    <?php for ($sk_i = 0; $sk_i < 3; $sk_i++): ?>
                    <div class="sk-prop-card">
                        <!-- Card image -->
                        <div class="sk-card-img sk-shimmer"></div>
                        <!-- Card body -->
                        <div class="sk-card-body">
                            <div class="sk-line sk-shimmer" style="width:80%;height:14px;margin-bottom:8px;"></div>
                            <div class="sk-line sk-shimmer" style="width:55%;height:11px;margin-bottom:14px;"></div>
                            <!-- Stats row -->
                            <div style="display:flex;gap:1rem;padding:0.75rem 0;border-top:1px solid #f1f5f9;border-bottom:1px solid #f1f5f9;margin-bottom:10px;">
                                <div class="sk-shimmer" style="flex:1;height:12px;border-radius:3px;"></div>
                                <div class="sk-shimmer" style="flex:1;height:12px;border-radius:3px;"></div>
                                <div class="sk-shimmer" style="flex:1;height:12px;border-radius:3px;"></div>
                            </div>
                            <!-- Meta pills -->
                            <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:10px;">
                                <div class="sk-shimmer" style="width:70px;height:22px;border-radius:2px;"></div>
                                <div class="sk-shimmer" style="width:85px;height:22px;border-radius:2px;"></div>
                                <div class="sk-shimmer" style="width:60px;height:22px;border-radius:2px;"></div>
                            </div>
                        </div>
                        <!-- Card footer -->
                        <div class="sk-card-footer">
                            <div class="sk-line sk-shimmer" style="width:55%;height:11px;margin:0 auto 10px;border-radius:3px;"></div>
                            <div class="sk-shimmer" style="width:100%;height:36px;border-radius:4px;"></div>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>

        </div><!-- /#sk-screen -->

        <!-- ═════════════════════════════════════════════════════════
             REAL PAGE CONTENT — hidden until hydrated by JS
             ═════════════════════════════════════════════════════════ -->
        <div id="page-content">

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-inner">
                <div>
                    <h1></i>Property Management</h1>
                    <p class="subtitle">Monitor, review, and manage all property listings across the platform</p>
                </div>
            </div>
        </div>

        <!-- KPI Stat Cards -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-icon gold"><i class="bi bi-buildings"></i></div>
                <div class="kpi-label">Total Listings</div>
                <div class="kpi-value"><?php echo count($properties); ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon amber"><i class="bi bi-clock-history"></i></div>
                <div class="kpi-label">Pending Review</div>
                <div class="kpi-value"><?php echo count($pending_properties); ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon green"><i class="bi bi-check-circle"></i></div>
                <div class="kpi-label">Approved</div>
                <div class="kpi-value"><?php echo count($approved_properties); ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon red"><i class="bi bi-x-circle"></i></div>
                <div class="kpi-label">Rejected</div>
                <div class="kpi-value"><?php echo count($rejected_properties); ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon cyan"><i class="bi bi-hourglass-split"></i></div>
                <div class="kpi-label">Pending Sold</div>
                <div class="kpi-value"><?php echo count($pending_sold_properties); ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon blue"><i class="bi bi-tag-fill"></i></div>
                <div class="kpi-label">Sold</div>
                <div class="kpi-value"><?php echo count($sold_properties); ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon pink"><i class="bi bi-hourglass-bottom"></i></div>
                <div class="kpi-label">Pending Rented</div>
                <div class="kpi-value"><?php echo count($pending_rented_properties); ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon purple"><i class="bi bi-key-fill"></i></div>
                <div class="kpi-label">Rented</div>
                <div class="kpi-value"><?php echo count($rented_properties); ?></div>
            </div>
        </div>

        <!-- Action Bar -->
        <div class="action-bar">
            <h2 class="action-title">
                <i class="bi bi-list-ul"></i>
                All Listings
            </h2>
            <div class="action-buttons">
                <button type="button" class="btn-outline-admin" id="openFilterSidebar">
                    <i class="bi bi-funnel"></i>
                    Filter Properties
                    <span class="filter-count-badge" id="filterCountBadge" style="display:none;">0</span>
                </button>
                <a href="add_property.php" class="btn-gold">
                    <i class="bi bi-plus-circle"></i>
                    Add New Property
                </a>
            </div>
        </div>

        <!-- Property Tabs -->
        <div class="property-tabs">
            <ul class="nav nav-tabs" id="propertyStatusTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="all-tab" data-bs-toggle="tab" data-bs-target="#all-content" type="button" role="tab">
                        <i class="bi bi-grid-3x3-gap me-1"></i>
                        All
                        <span class="tab-badge badge-approved"><?php echo count($properties); ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending-content" type="button" role="tab">
                        <i class="bi bi-clock-history me-1"></i>
                        Pending
                        <span class="tab-badge badge-pending"><?php echo count($pending_properties); ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="pending-sold-tab" data-bs-toggle="tab" data-bs-target="#pending-sold-content" type="button" role="tab">
                        <i class="bi bi-hourglass-split me-1"></i>
                        Pending Sold
                        <span class="tab-badge badge-pending-sold"><?php echo count($pending_sold_properties); ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="approved-tab" data-bs-toggle="tab" data-bs-target="#approved-content" type="button" role="tab">
                        <i class="bi bi-check-circle me-1"></i>
                        Approved
                        <span class="tab-badge badge-approved"><?php echo count($approved_properties); ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="rejected-tab" data-bs-toggle="tab" data-bs-target="#rejected-content" type="button" role="tab">
                        <i class="bi bi-x-circle me-1"></i>
                        Rejected
                        <span class="tab-badge badge-rejected"><?php echo count($rejected_properties); ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="sold-tab" data-bs-toggle="tab" data-bs-target="#sold-content" type="button" role="tab">
                        <i class="bi bi-tag-fill me-1"></i>
                        Sold
                        <span class="tab-badge badge-sold"><?php echo count($sold_properties); ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="pending-rented-tab" data-bs-toggle="tab" data-bs-target="#pending-rented-content" type="button" role="tab">
                        <i class="bi bi-hourglass-bottom me-1"></i>
                        Pending Rented
                        <span class="tab-badge badge-pending-rented"><?php echo count($pending_rented_properties); ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="rented-tab" data-bs-toggle="tab" data-bs-target="#rented-content" type="button" role="tab">
                        <i class="bi bi-key-fill me-1"></i>
                        Rented
                        <span class="tab-badge badge-rented"><?php echo count($rented_properties); ?></span>
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="propertyStatusTabsContent">
                <!-- All Properties -->
                <div class="tab-pane fade show active" id="all-content" role="tabpanel">
                    <div class="properties-grid" id="all-grid"></div>
                    <div class="pagination-bar" id="all-pagination" style="display:none;"></div>
                </div>
                <!-- Pending Properties -->
                <div class="tab-pane fade" id="pending-content" role="tabpanel">
                    <div class="properties-grid" id="pending-grid"></div>
                    <div class="pagination-bar" id="pending-pagination" style="display:none;"></div>
                </div>
                <!-- Pending Sold Properties -->
                <div class="tab-pane fade" id="pending-sold-content" role="tabpanel">
                    <div class="properties-grid" id="pending-sold-grid"></div>
                    <div class="pagination-bar" id="pending-sold-pagination" style="display:none;"></div>
                </div>
                <!-- Approved Properties -->
                <div class="tab-pane fade" id="approved-content" role="tabpanel">
                    <div class="properties-grid" id="approved-grid"></div>
                    <div class="pagination-bar" id="approved-pagination" style="display:none;"></div>
                </div>
                <!-- Rejected Properties -->
                <div class="tab-pane fade" id="rejected-content" role="tabpanel">
                    <div class="properties-grid" id="rejected-grid"></div>
                    <div class="pagination-bar" id="rejected-pagination" style="display:none;"></div>
                </div>
                <!-- Sold Properties -->
                <div class="tab-pane fade" id="sold-content" role="tabpanel">
                    <div class="properties-grid" id="sold-grid"></div>
                    <div class="pagination-bar" id="sold-pagination" style="display:none;"></div>
                </div>
                <!-- Pending Rented Properties -->
                <div class="tab-pane fade" id="pending-rented-content" role="tabpanel">
                    <div class="properties-grid" id="pending-rented-grid"></div>
                    <div class="pagination-bar" id="pending-rented-pagination" style="display:none;"></div>
                </div>
                <!-- Rented Properties -->
                <div class="tab-pane fade" id="rented-content" role="tabpanel">
                    <div class="properties-grid" id="rented-grid"></div>
                    <div class="pagination-bar" id="rented-pagination" style="display:none;"></div>
                </div>
            </div>

            <!-- Hidden container with all property cards -->
            <div id="all-property-cards" style="display: none;">
                <?php foreach ($properties as $property): ?>
                    <div id="property-<?php echo $property['property_ID']; ?>" 
                         class="property-card-wrapper" 
                         data-approval-status="<?php echo htmlspecialchars($property['approval_status'] ?? ''); ?>" 
                         data-status="<?php echo htmlspecialchars(strtolower($property['Status'] ?? '')); ?>" 
                         data-property-id="<?php echo $property['property_ID']; ?>">
                        <?php include 'admin_property_card_template.php'; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        </div><!-- /#page-content -->
    </div><!-- /.admin-content -->


        <!-- Filter Sidebar -->
    <div class="filter-sidebar" id="filterSidebar">
        <div class="filter-sidebar-overlay" id="filterOverlay"></div>
        <div class="filter-sidebar-content">
            <div class="filter-header">
                <h4>
                    <i class="bi bi-funnel me-2"></i>Advanced Filters
                </h4>
                <button class="btn-close-filter" id="closeFilterBtn">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            
            <div class="filter-body">
                <!-- Search Section -->
                <div class="filter-section">
                    <div class="filter-section-title">
                        <i class="bi bi-search"></i>
                        Search
                    </div>
                    <div class="filter-search-box">
                        <i class="bi bi-search"></i>
                        <input type="text" id="searchInput" class="form-control" placeholder="Search by address, city, or description...">
                    </div>
                </div>

                <!-- Price Range Section -->
                <div class="filter-section">
                    <div class="filter-section-title">
                        <i class="bi bi-currency-dollar"></i>
                        Price Range
                    </div>
                    <div class="price-slider-container">
                        <div class="price-slider-track">
                            <div class="price-slider-range" id="priceSliderRange"></div>
                        </div>
                        <input type="range" id="priceMinSlider" class="price-range-slider" min="0" max="50000000" value="0" step="100000">
                        <input type="range" id="priceMaxSlider" class="price-range-slider" min="0" max="50000000" value="50000000" step="100000">
                    </div>
                    <div class="price-range-inputs">
                        <div class="price-input">
                            <span class="currency-symbol">₱</span>
                            <input type="text" id="priceMin" class="form-control" placeholder="Min" readonly>
                        </div>
                        <span class="range-divider">—</span>
                        <div class="price-input">
                            <span class="currency-symbol">₱</span>
                            <input type="text" id="priceMax" class="form-control" placeholder="Max" readonly>
                        </div>
                    </div>
                    <div class="quick-filters">
                        <button class="quick-filter-btn" data-price-range="0-5000000">Under 5M</button>
                        <button class="quick-filter-btn" data-price-range="5000000-15000000">5M - 15M</button>
                        <button class="quick-filter-btn" data-price-range="15000000-30000000">15M - 30M</button>
                        <button class="quick-filter-btn" data-price-range="30000000-999999999">30M+</button>
                    </div>
                </div>

                <!-- Property Type Section -->
                <div class="filter-section">
                    <div class="filter-section-title">
                        <i class="bi bi-house-door"></i>
                        Property Type
                    </div>
                    <div class="filter-chips">
                        <?php foreach ($property_types_db as $pt_name): ?>
                        <label class="filter-chip">
                            <input type="checkbox" class="property-type-filter" value="<?php echo htmlspecialchars($pt_name); ?>" checked>
                            <span><?php echo htmlspecialchars($pt_name); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Bedrooms & Bathrooms Section -->
                <div class="filter-section">
                    <div class="filter-section-title">
                        <i class="bi bi-door-open"></i>
                        Bedrooms & Bathrooms
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label" style="font-size: 0.875rem; font-weight: 600;">Bedrooms</label>
                            <select id="bedroomsFilter" class="filter-select">
                                <option value="">Any</option>
                                <option value="1">1+</option>
                                <option value="2">2+</option>
                                <option value="3">3+</option>
                                <option value="4">4+</option>
                                <option value="5">5+</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label" style="font-size: 0.875rem; font-weight: 600;">Bathrooms</label>
                            <select id="bathroomsFilter" class="filter-select">
                                <option value="">Any</option>
                                <option value="1">1+</option>
                                <option value="2">2+</option>
                                <option value="3">3+</option>
                                <option value="4">4+</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Square Footage Section -->
                <div class="filter-section">
                    <div class="filter-section-title">
                        <i class="bi bi-rulers"></i>
                        Square Footage
                    </div>
                    <div class="price-range-inputs">
                        <div class="price-input">
                            <input type="number" id="sqftMin" class="form-control" placeholder="Min sq ft" min="0" step="100">
                        </div>
                        <span class="range-divider">—</span>
                        <div class="price-input">
                            <input type="number" id="sqftMax" class="form-control" placeholder="Max sq ft" min="0" step="100">
                        </div>
                    </div>
                </div>

                <!-- Year Built Section -->
                <div class="filter-section">
                    <div class="filter-section-title">
                        <i class="bi bi-calendar-event"></i>
                        Year Built
                    </div>
                    <div class="year-inputs">
                        <input type="number" id="yearMin" class="form-control" placeholder="From" min="1900" max="2025" step="1">
                        <span class="range-divider">—</span>
                        <input type="number" id="yearMax" class="form-control" placeholder="To" min="1900" max="2025" step="1">
                    </div>
                    <div class="quick-filters">
                        <button class="quick-filter-btn" data-year-range="2020-2025">New (2020+)</button>
                        <button class="quick-filter-btn" data-year-range="2010-2019">Recent (2010-2019)</button>
                        <button class="quick-filter-btn" data-year-range="1990-2009">Established</button>
                    </div>
                </div>

                <!-- Location Section -->
                <div class="filter-section">
                    <div class="filter-section-title">
                        <i class="bi bi-geo-alt"></i>
                        Location
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label" style="font-size: 0.875rem; font-weight: 600;">City</label>
                            <select id="cityFilter" class="filter-select">
                                <option value="">All Cities</option>
                                <option value="Cagayan de Oro">Cagayan de Oro</option>
                                <option value="Manolo Fortich">Manolo Fortich</option>
                                <option value="Manolo Fortichh">Manolo Fortichh</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label" style="font-size: 0.875rem; font-weight: 600;">Barangay</label>
                            <select id="countyFilter" class="filter-select">
                                <option value="">All Barangays</option>
                                <option value="Bukidnon">Bukidnon</option>
                                <option value="Misamis Oriental">Misamis Oriental</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Listing Status Section -->
                <div class="filter-section">
                    <div class="filter-section-title">
                        <i class="bi bi-tags"></i>
                        Listing Status
                    </div>
                    <div class="filter-chips">
                        <label class="filter-chip">
                            <input type="checkbox" class="status-filter" value="For Sale" checked>
                            <span>For Sale</span>
                        </label>
                        <label class="filter-chip">
                            <input type="checkbox" class="status-filter" value="For Rent" checked>
                            <span>For Rent</span>
                        </label>
                        <label class="filter-chip">
                            <input type="checkbox" class="status-filter" value="Pending Sold" checked>
                            <span>Pending Sold</span>
                        </label>
                        <label class="filter-chip">
                            <input type="checkbox" class="status-filter" value="Sold" checked>
                            <span>Sold</span>
                        </label>
                        <label class="filter-chip">
                            <input type="checkbox" class="status-filter" value="Pending Rented" checked>
                            <span>Pending Rented</span>
                        </label>
                        <label class="filter-chip">
                            <input type="checkbox" class="status-filter" value="Rented" checked>
                            <span>Rented</span>
                        </label>
                    </div>
                </div>

                <!-- Approval Status Section -->
                <div class="filter-section">
                    <div class="filter-section-title">
                        <i class="bi bi-shield-check"></i>
                        Approval Status
                    </div>
                    <div class="filter-chips">
                        <label class="filter-chip">
                            <input type="checkbox" class="approval-filter" value="pending" checked>
                            <span>Pending</span>
                        </label>
                        <label class="filter-chip">
                            <input type="checkbox" class="approval-filter" value="approved" checked>
                            <span>Approved</span>
                        </label>
                        <label class="filter-chip">
                            <input type="checkbox" class="approval-filter" value="rejected" checked>
                            <span>Rejected</span>
                        </label>
                    </div>
                </div>

                <!-- Parking Type Section -->
                <div class="filter-section">
                    <div class="filter-section-title">
                        <i class="bi bi-car-front"></i>
                        Parking
                    </div>
                    <select id="parkingFilter" class="filter-select">
                        <option value="">Any Parking</option>
                        <option value="1-Car Garage">1-Car Garage</option>
                        <option value="2-Car Garage">2-Car Garage</option>
                        <option value="3-Car Garage">3-Car Garage</option>
                        <option value="Assigned Parking Space">Assigned Parking</option>
                        <option value="Private lot">Private Lot</option>
                        <option value="Garage">Garage</option>
                    </select>
                </div>

                <!-- Listing Date Range Section -->
                <div class="filter-section">
                    <div class="filter-section-title">
                        <i class="bi bi-calendar-range"></i>
                        Listing Date
                    </div>
                    <div class="year-inputs">
                        <input type="date" id="listingDateFrom" class="form-control">
                        <span class="range-divider">—</span>
                        <input type="date" id="listingDateTo" class="form-control">
                    </div>
                </div>

                <!-- Listed By Section -->
                <div class="filter-section">
                    <div class="filter-section-title">
                        <i class="bi bi-person-badge"></i>
                        Listed By
                    </div>
                    <select id="listedByFilter" class="filter-select">
                        <option value="">All Agents &amp; Admins</option>
                        <?php foreach ($listers_db as $lr): ?>
                        <option value="<?php echo (int)$lr['account_id']; ?>">
                            <?php echo htmlspecialchars($lr['first_name'] . ' ' . $lr['last_name']); ?>
                            <?php if (!empty($lr['role_name'])): ?>(<?php echo ucfirst($lr['role_name']); ?>)<?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Results Summary -->
                <div class="filter-results-summary">
                    <i class="bi bi-check-circle-fill"></i>
                    <div class="filter-results-text">
                        <div class="filter-results-count" id="filteredCount">0</div>
                        <div class="filter-results-label">Properties Match Your Criteria</div>
                    </div>
                </div>
            </div>

            <div class="filter-footer">
                <button class="btn btn-outline-secondary" id="clearFiltersBtn">
                    <i class="bi bi-arrow-clockwise me-2"></i>Reset
                </button>
                <button class="btn btn-primary" id="applyFiltersBtn">
                    <i class="bi bi-check2 me-2"></i>Apply Filters
                </button>
            </div>
        </div>
    </div>

    <!-- Toast Container -->
    <div id="toastContainer"></div>

    <script src="<?= ASSETS_JS ?>bootstrap.bundle.min.js"></script>
    <script>
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

        // ===== PENDING PROPERTY NOTIFICATIONS =====
        /* Fires on skeleton:hydrated so toasts only appear once real content is visible */
        document.addEventListener('skeleton:hydrated', function() {
            const pendingReview  = <?php echo count($pending_properties); ?>;
            const pendingSold    = <?php echo count($pending_sold_properties); ?>;

            if (pendingReview > 0) {
                showToast(
                    'warning',
                    'Pending Review',
                    pendingReview + ' propert' + (pendingReview !== 1 ? 'ies require' : 'y requires') + ' approval before going live.',
                    6000
                );
            }
            if (pendingSold > 0) {
                setTimeout(() => {
                    showToast(
                        'info',
                        'Pending Sale',
                        pendingSold + ' propert' + (pendingSold !== 1 ? 'ies are' : 'y is') + ' awaiting sale finalization.',
                        6000
                    );
                }, 700);
            }
        });

        // Embed properties data for client-side filtering
        var allProperties = <?php echo json_encode(array_map(function($p){
            // expose necessary fields to client for admin card display
            return [
                'property_ID' => $p['property_ID'] ?? null,
                'StreetAddress' => $p['StreetAddress'] ?? '',
                'City' => $p['City'] ?? '',
                'Province' => $p['Province'] ?? '',
                'ZIP' => $p['ZIP'] ?? '',
                'ListingPrice' => isset($p['ListingPrice']) ? (float)$p['ListingPrice'] : 0,
                'ListingDate' => $p['ListingDate'] ?? null,
                'approval_status' => $p['approval_status'] ?? '',
                'Status' => $p['Status'] ?? '', // Listing status: For Sale/For Rent/Sold
                'PhotoURL' => $p['PhotoURL'] ?? '',
                'PropertyType' => $p['PropertyType'] ?? '',
                'Bedrooms' => $p['Bedrooms'] ?? 0,
                'Bathrooms' => $p['Bathrooms'] ?? 0,
                'SquareFootage' => $p['SquareFootage'] ?? 0,
                'YearBuilt' => $p['YearBuilt'] ?? '',
                'ViewsCount' => $p['ViewsCount'] ?? 0,
                'Likes' => $p['Likes'] ?? 0,
                'poster_account_id' => $p['poster_account_id'] ?? null,
            ];
        }, $properties)); ?>;

        // Determine price bounds
        var prices = allProperties.map(p => p.ListingPrice || 0);
        var PRICE_MIN = Math.min(...prices, 0);
        var PRICE_MAX = Math.max(...prices, 100000000);

    document.addEventListener('DOMContentLoaded', () => {
            // initialize price sliders limits
            const minEl = document.getElementById('priceMin');
            const maxEl = document.getElementById('priceMax');
            const minLabel = document.getElementById('priceMinLabel');
            const maxLabel = document.getElementById('priceMaxLabel');

            if (minEl && maxEl) {
                // initialize numeric slider bounds using the computed PRICE_MIN/PRICE_MAX
                const minSlider = document.getElementById('priceMinSlider');
                const maxSlider = document.getElementById('priceMaxSlider');
                if (minSlider && maxSlider) {
                    minSlider.min = PRICE_MIN;
                    minSlider.max = PRICE_MAX;
                    maxSlider.min = PRICE_MIN;
                    maxSlider.max = PRICE_MAX;
                    minSlider.value = PRICE_MIN;
                    maxSlider.value = PRICE_MAX;
                }

                // show formatted defaults in text inputs
                minEl.value = numberWithCommas(PRICE_MIN);
                maxEl.value = (PRICE_MAX >= 100000000 ? numberWithCommas(PRICE_MAX) + '+' : numberWithCommas(PRICE_MAX));
                if (minLabel) minLabel.textContent = 'Min: ' + numberWithCommas(PRICE_MIN);
                if (maxLabel) maxLabel.textContent = 'Max: ' + (PRICE_MAX >= 100000000 ? numberWithCommas(PRICE_MAX) + '+' : numberWithCommas(PRICE_MAX));
            }

            // wire other filters
            ['searchText', 'dateFrom', 'dateTo'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.addEventListener('input', applyFilters);
            });

            document.querySelectorAll('.status-filter').forEach(cb => cb.addEventListener('change', applyFilters));

            // initial render
            applyFilters();
        });

        function numberWithCommas(x) {
            if (x === null || x === undefined) return '';
            return x.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        }

        function applyFilters() {
            const q = (document.getElementById('searchText')?.value || '').toLowerCase();
            // Parse formatted price inputs (they include commas and may have a trailing '+')
            const rawMin = document.getElementById('priceMin')?.value || '';
            const rawMax = document.getElementById('priceMax')?.value || '';
            const parseFormattedPrice = (s, fallback) => {
                if (!s) return fallback;
                // if it contains a + (e.g. "30,000,000+"), treat as upper bound (use PRICE_MAX)
                if (String(s).includes('+')) return PRICE_MAX;
                // strip non-numeric characters (commas, currency symbols)
                const n = Number(String(s).replace(/[^0-9.-]+/g, ''));
                return isNaN(n) ? fallback : n;
            };
            const minV = parseFormattedPrice(rawMin, PRICE_MIN);
            const maxV = parseFormattedPrice(rawMax, PRICE_MAX);
            const dateFrom = document.getElementById('dateFrom')?.value || null;
            const dateTo = document.getElementById('dateTo')?.value || null;
            // Determine selected LISTING statuses (For Sale / For Rent / Pending Sold / Sold)
            // If none selected, treat as 'All' (no status filter)
            const statusCheckedEls = Array.from(document.querySelectorAll('.status-filter:checked'));
            const hasStatusFilter = statusCheckedEls.length > 0;
            const statusCheckedLower = new Set(statusCheckedEls.map(i => String(i.value || '').toLowerCase()));

            const filtered = allProperties.filter(p => {
                // Listing status filter (uses p.Status values: For Sale / For Rent / Pending Sold / Sold)
                if (hasStatusFilter) {
                    const statusLower = String(p.Status || '').toLowerCase();
                    // If property has a known listing status, require it to be in selected set; otherwise don't filter it out.
                    if (statusLower && !statusCheckedLower.has(statusLower)) return false;
                }

                // price
                const price = Number(p.ListingPrice || 0);
                if (price < Math.min(minV, maxV) || price > Math.max(minV, maxV)) return false;
                // date
                if (dateFrom) {
                    if (!p.ListingDate) return false;
                    if (new Date(p.ListingDate) < new Date(dateFrom)) return false;
                }
                if (dateTo) {
                    if (!p.ListingDate) return false;
                    // include end date
                    const d = new Date(p.ListingDate);
                    const to = new Date(dateTo);
                    to.setHours(23,59,59,999);
                    if (d > to) return false;
                }
                // text search
                if (q) {
                    const hay = ((p.StreetAddress||'') + ' ' + (p.City||'') + ' ' + (p.Province||'') + ' ' + (p.PropertyType||'')).toLowerCase();
                    if (!hay.includes(q)) return false;
                }
                return true;
            });

            // render counts
            const summary = document.getElementById('filterResultsSummary');
            if (summary) {
                const byStatus = filtered.reduce((acc, cur) => { acc[cur.approval_status] = (acc[cur.approval_status]||0)+1; return acc; }, {});
                const pendingSoldCount = filtered.filter(p => (p.Status||'').toLowerCase() === 'pending sold').length;
                const soldCount = filtered.filter(p => (p.Status||'').toLowerCase() === 'sold').length;
                const pendingRentedCount = filtered.filter(p => (p.Status||'').toLowerCase() === 'pending rented').length;
                const rentedCount = filtered.filter(p => (p.Status||'').toLowerCase() === 'rented').length;
                summary.innerHTML = `<strong>${filtered.length}</strong> result(s) — Pending: ${byStatus['pending']||0}, Pending Sold: ${pendingSoldCount}, Approved: ${byStatus['approved']||0}, Rejected: ${byStatus['rejected']||0}, Sold: ${soldCount}, Pending Rented: ${pendingRentedCount}, Rented: ${rentedCount}`;
            }

            renderGrids(filtered);
        }

        // Pagination globals
        var PROP_PER_PAGE = 12;
        var propFilteredItems = {};
        var propCurrentPage = { all: 1, pending: 1, 'pending-sold': 1, approved: 1, rejected: 1, sold: 1, 'pending-rented': 1, rented: 1 };

        function renderGrids(filtered) {
            // Get all pre-rendered card wrappers
            const allCards = Array.from(document.querySelectorAll('#all-property-cards .property-card-wrapper'));
            
            // Create a map for quick lookup
            const cardMap = new Map();
            allCards.forEach(card => {
                const propertyId = card.getAttribute('data-property-id');
                cardMap.set(propertyId, card);
            });

            // Group items
            const groupByApproval = (status) => filtered.filter(p => {
                const sl = (p.Status||'').toLowerCase();
                return (p.approval_status||'') === status && sl !== 'sold' && sl !== 'pending sold' && sl !== 'rented' && sl !== 'pending rented';
            });
            const pendingSoldItems = filtered.filter(p => (p.Status||'').toLowerCase() === 'pending sold');
            const soldItems = filtered.filter(p => (p.Status||'').toLowerCase() === 'sold');
            const pendingRentedItems = filtered.filter(p => (p.Status||'').toLowerCase() === 'pending rented');
            const rentedItems = filtered.filter(p => (p.Status||'').toLowerCase() === 'rented');

            // Store filtered items per key and reset to page 1
            propFilteredItems = {
                all: filtered,
                pending: groupByApproval('pending'),
                'pending-sold': pendingSoldItems,
                approved: groupByApproval('approved'),
                rejected: groupByApproval('rejected'),
                sold: soldItems,
                'pending-rented': pendingRentedItems,
                rented: rentedItems
            };
            Object.keys(propCurrentPage).forEach(k => { propCurrentPage[k] = 1; });

            // Render each key
            Object.keys(propFilteredItems).forEach(key => {
                renderPropGridPage(key, cardMap);
            });
        }

        function renderPropGridPage(key, cardMap) {
            if (!cardMap) {
                // Rebuild cardMap if not passed (e.g., called from goToPropertyPage)
                const allCards = Array.from(document.querySelectorAll('#all-property-cards .property-card-wrapper'));
                cardMap = new Map();
                allCards.forEach(card => { cardMap.set(card.getAttribute('data-property-id'), card); });
            }

            const paneId = key === 'pending-sold' ? 'pending-sold-content' : key + '-content';
            const pane = document.getElementById(paneId);
            if (!pane) return;

            const items = propFilteredItems[key] || [];
            const total = items.length;
            const page = propCurrentPage[key] || 1;
            const start = (page - 1) * PROP_PER_PAGE;
            const end = start + PROP_PER_PAGE;
            const pageItems = items.slice(start, end);

            // Remove existing empty-state
            const existingEmpty = pane.querySelector('.empty-state');
            if (existingEmpty) existingEmpty.remove();

            // Get or create grid
            let grid = pane.querySelector('.properties-grid');
            if (!grid) {
                grid = document.createElement('div');
                grid.className = 'properties-grid';
                pane.insertBefore(grid, pane.querySelector('.pagination-bar'));
            }

            grid.innerHTML = '';

            if (!items.length) {
                grid.innerHTML = `<div class="empty-state" style="max-width:720px;width:100%;margin:0 1rem;">
                    <i class="bi bi-search empty-state-icon"></i>
                    <h4>No matching properties</h4>
                    <p class="text-muted">Try adjusting your filters.</p>
                </div>`;
                grid.style.display = 'flex';
                grid.style.justifyContent = 'center';
                grid.style.alignItems = 'center';
                grid.style.minHeight = '220px';
                grid.style.gridTemplateColumns = '';
                grid.style.gap = '';
            } else {
                pageItems.forEach(item => {
                    const card = cardMap.get(item.property_ID.toString());
                    if (card) grid.appendChild(card.cloneNode(true));
                });
                grid.style.display = 'grid';
                grid.style.gridTemplateColumns = 'repeat(auto-fill, minmax(380px,1fr))';
                grid.style.gap = '1.5rem';
                grid.style.justifyContent = '';
                grid.style.alignItems = '';
                grid.style.minHeight = '';
            }

            renderPropPagination(key, total);
        }

        function renderPropPagination(key, total) {
            const paginationId = key + '-pagination';
            const el = document.getElementById(paginationId);
            if (!el) return;

            const totalPages = Math.ceil(total / PROP_PER_PAGE);
            if (totalPages <= 1) { el.style.display = 'none'; return; }

            el.style.display = 'flex';
            const page = propCurrentPage[key] || 1;
            const startItem = (page - 1) * PROP_PER_PAGE + 1;
            const endItem = Math.min(page * PROP_PER_PAGE, total);

            const maxBtns = 5;
            let startPage = Math.max(1, page - Math.floor(maxBtns / 2));
            let endPage = Math.min(totalPages, startPage + maxBtns - 1);
            if (endPage - startPage < maxBtns - 1) startPage = Math.max(1, endPage - maxBtns + 1);

            let pages = '';
            if (startPage > 1) {
                pages += `<button class="page-btn" onclick="goToPropertyPage('${key}',1)">1</button>`;
                if (startPage > 2) pages += `<span class="page-ellipsis">…</span>`;
            }
            for (let i = startPage; i <= endPage; i++) {
                pages += `<button class="page-btn ${i === page ? 'active' : ''}" onclick="goToPropertyPage('${key}',${i})">${i}</button>`;
            }
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) pages += `<span class="page-ellipsis">…</span>`;
                pages += `<button class="page-btn" onclick="goToPropertyPage('${key}',${totalPages})">${totalPages}</button>`;
            }

            el.innerHTML = `
                <div class="pagination-info">Showing ${startItem}–${endItem} of ${total} properties</div>
                <div class="pagination-controls">
                    <button class="page-btn" onclick="goToPropertyPage('${key}',${page-1})" ${page <= 1 ? 'disabled' : ''}><i class="bi bi-chevron-left"></i></button>
                    ${pages}
                    <button class="page-btn" onclick="goToPropertyPage('${key}',${page+1})" ${page >= totalPages ? 'disabled' : ''}><i class="bi bi-chevron-right"></i></button>
                </div>`;
        }

        function goToPropertyPage(key, page) {
            const total = (propFilteredItems[key] || []).length;
            const totalPages = Math.ceil(total / PROP_PER_PAGE);
            if (page < 1 || page > totalPages) return;
            propCurrentPage[key] = page;
            renderPropGridPage(key, null);
            const paneId = key === 'pending-sold' ? 'pending-sold-content' : key + '-content';
            const pane = document.getElementById(paneId);
            if (pane) pane.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }



        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/[&<>"'`]/g, function (s) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":"&#39;","`":"&#x60;"})[s]; });
        }
        // Simple tab functionality enhancement
        document.addEventListener('DOMContentLoaded', function() {
            // Add smooth transitions to tab content
            const tabTriggerList = document.querySelectorAll('#propertyStatusTabs button[data-bs-toggle="tab"]');
            tabTriggerList.forEach(tabTrigger => {
                tabTrigger.addEventListener('shown.bs.tab', function(event) {
                    const targetContent = document.querySelector(event.target.getAttribute('data-bs-target'));
                    if (targetContent) {
                        targetContent.style.opacity = '0';
                        setTimeout(() => {
                            targetContent.style.transition = 'opacity 0.3s ease';
                            targetContent.style.opacity = '1';
                        }, 50);
                    }
                });
            });
        });
    </script>
    <script>
        // ===== COMPREHENSIVE FILTER SIDEBAR INTEGRATION =====
        // This integrates with the existing applyFilters and renderGrids functions
        
        var comprehensiveFilters = {
            search: '',
            priceMin: 0,
            priceMax: Infinity,
            propertyTypes: new Set(Array.from(document.querySelectorAll('.property-type-filter')).map(c => c.value)),
            bedrooms: null,
            bathrooms: null,
            sqftMin: null,
            sqftMax: null,
            yearMin: null,
            yearMax: null,
            city: '',
            county: '',
            statuses: new Set(['For Sale', 'For Rent', 'Pending Sold', 'Sold', 'Pending Rented', 'Rented']),
            approvalStatuses: new Set(['pending', 'approved', 'rejected']),
            parking: '',
            listedBy: ''
        };

        // Open/Close Filter Sidebar
        document.getElementById('openFilterSidebar')?.addEventListener('click', () => {
            document.getElementById('filterSidebar').classList.add('active');
        });

        document.getElementById('closeFilterBtn')?.addEventListener('click', () => {
            document.getElementById('filterSidebar').classList.remove('active');
        });

        document.getElementById('filterOverlay')?.addEventListener('click', () => {
            document.getElementById('filterSidebar').classList.remove('active');
        });

        document.getElementById('applyFiltersBtn')?.addEventListener('click', () => {
            document.getElementById('filterSidebar').classList.remove('active');
        });

        // Integrate comprehensive filters with existing system
        function applyComprehensiveFilters() {
            const filtered = allProperties.filter(property => {
                // Search
                if (comprehensiveFilters.search) {
                    const text = `${property.StreetAddress} ${property.City} ${property.ListingDescription || ''}`.toLowerCase();
                    if (!text.includes(comprehensiveFilters.search)) return false;
                }
                
                // Price
                const price = property.ListingPrice || 0;
                if (price < comprehensiveFilters.priceMin || (comprehensiveFilters.priceMax !== Infinity && price > comprehensiveFilters.priceMax)) {
                    return false;
                }
                
                // Property Type
                if (!comprehensiveFilters.propertyTypes.has(property.PropertyType)) return false;
                
                // Bedrooms
                if (comprehensiveFilters.bedrooms && property.Bedrooms < comprehensiveFilters.bedrooms) return false;
                
                // Bathrooms
                if (comprehensiveFilters.bathrooms && property.Bathrooms < comprehensiveFilters.bathrooms) return false;
                
                // Square Footage
                if (comprehensiveFilters.sqftMin && property.SquareFootage < comprehensiveFilters.sqftMin) return false;
                if (comprehensiveFilters.sqftMax && property.SquareFootage > comprehensiveFilters.sqftMax) return false;
                
                // Year Built
                if (comprehensiveFilters.yearMin && property.YearBuilt < comprehensiveFilters.yearMin) return false;
                if (comprehensiveFilters.yearMax && property.YearBuilt > comprehensiveFilters.yearMax) return false;
                
                // Location
                if (comprehensiveFilters.city && property.City !== comprehensiveFilters.city) return false;
                if (comprehensiveFilters.county && property.Barangay !== comprehensiveFilters.county) return false;
                
                // Status
                if (!comprehensiveFilters.statuses.has(property.Status)) return false;
                
                // Approval
                if (!comprehensiveFilters.approvalStatuses.has(property.approval_status)) return false;
                
                // Parking
                if (comprehensiveFilters.parking && property.ParkingType !== comprehensiveFilters.parking) return false;
                
                // Listing Date
                if (document.getElementById('listingDateFrom')?.value && property.ListingDate < document.getElementById('listingDateFrom').value) return false;
                if (document.getElementById('listingDateTo')?.value && property.ListingDate > document.getElementById('listingDateTo').value) return false;

                // Listed By
                if (comprehensiveFilters.listedBy && String(property.poster_account_id) !== String(comprehensiveFilters.listedBy)) return false;
                
                return true;
            });

            document.getElementById('filteredCount').textContent = filtered.length;
            
            const activeCount = countComprehensiveFilters();
            const badge = document.getElementById('filterCountBadge');
            if (badge) {
                if (activeCount > 0) {
                    badge.textContent = activeCount;
                    badge.style.display = 'inline-flex';
                } else {
                    badge.style.display = 'none';
                }
            }

            renderGrids(filtered);
        }

        function countComprehensiveFilters() {
            let count = 0;
            if (comprehensiveFilters.search) count++;
            if (comprehensiveFilters.priceMin > 0 || comprehensiveFilters.priceMax < Infinity) count++;
            if (comprehensiveFilters.propertyTypes.size < 4) count++;
            if (comprehensiveFilters.bedrooms) count++;
            if (comprehensiveFilters.bathrooms) count++;
            if (comprehensiveFilters.sqftMin || comprehensiveFilters.sqftMax) count++;
            if (comprehensiveFilters.yearMin || comprehensiveFilters.yearMax) count++;
            if (comprehensiveFilters.city) count++;
            if (comprehensiveFilters.county) count++;
            if (comprehensiveFilters.statuses.size < 6) count++;
            if (comprehensiveFilters.approvalStatuses.size < 3) count++;
            if (comprehensiveFilters.parking) count++;
            if (document.getElementById('listingDateFrom')?.value || document.getElementById('listingDateTo')?.value) count++;
            if (comprehensiveFilters.listedBy) count++;
            return count;
        }

        // Wire up all filter inputs
        document.getElementById('searchInput')?.addEventListener('input', (e) => {
            comprehensiveFilters.search = e.target.value.toLowerCase();
            applyComprehensiveFilters();
        });

        // price text inputs are display-only (formatted). Parsing/formating done via helpers when sliders change
        function parsePriceInput(str) {
            if (!str) return 0;
            return Number(String(str).replace(/[^0-9.-]+/g, '')) || 0;
        }

        function setPriceInputs(minVal, maxVal) {
            const minInput = document.getElementById('priceMin');
            const maxInput = document.getElementById('priceMax');
            if (minInput) minInput.value = numberWithCommas(minVal);
            if (maxInput) maxInput.value = (maxVal >= 100000000 ? numberWithCommas(maxVal) + '+' : numberWithCommas(maxVal));
        }

        // Draggable Price Range Sliders
        var priceMinSlider = document.getElementById('priceMinSlider');
        var priceMaxSlider = document.getElementById('priceMaxSlider');
        var priceMinInput = document.getElementById('priceMin');
        var priceMaxInput = document.getElementById('priceMax');
        var priceSliderRange = document.getElementById('priceSliderRange');

        function updatePriceSliderRange() {
            if (!priceMinSlider || !priceMaxSlider || !priceSliderRange) return;
            
            const minVal = parseInt(priceMinSlider.value);
            const maxVal = parseInt(priceMaxSlider.value);
            const minPercent = (minVal / priceMinSlider.max) * 100;
            const maxPercent = (maxVal / priceMaxSlider.max) * 100;
            
            priceSliderRange.style.left = minPercent + '%';
            priceSliderRange.style.width = (maxPercent - minPercent) + '%';
        }

        if (priceMinSlider) {
            priceMinSlider.addEventListener('input', (e) => {
                let minVal = parseInt(e.target.value);
                let maxVal = parseInt(priceMaxSlider.value);

                // prevent crossing: keep a minimum gap of 500,000
                const GAP = 500000;
                if (minVal > maxVal - GAP) {
                    minVal = maxVal - GAP;
                    e.target.value = minVal;
                }

                // update visible formatted inputs
                setPriceInputs(minVal, maxVal);

                // update internal values
                comprehensiveFilters.priceMin = minVal;
                // if maxVal equals slider max, treat as Infinity in the filter
                comprehensiveFilters.priceMax = (maxVal >= Number(priceMaxSlider.max)) ? Infinity : maxVal;

                updatePriceSliderRange();
                applyComprehensiveFilters();
            });
        }

        if (priceMaxSlider) {
            priceMaxSlider.addEventListener('input', (e) => {
                let maxVal = parseInt(e.target.value);
                let minVal = parseInt(priceMinSlider.value);

                const GAP = 500000;
                if (maxVal < minVal + GAP) {
                    maxVal = minVal + GAP;
                    e.target.value = maxVal;
                }

                setPriceInputs(minVal, maxVal);

                comprehensiveFilters.priceMin = minVal;
                comprehensiveFilters.priceMax = (maxVal >= Number(priceMaxSlider.max)) ? Infinity : maxVal;

                updatePriceSliderRange();
                applyComprehensiveFilters();
            });
        }

        // Initialize slider range
        updatePriceSliderRange();

        document.querySelectorAll('.property-type-filter').forEach(cb => {
            cb.addEventListener('change', () => {
                comprehensiveFilters.propertyTypes = new Set(Array.from(document.querySelectorAll('.property-type-filter:checked')).map(c => c.value));
                updateChipStates();
                applyComprehensiveFilters();
            });
        });

        document.getElementById('bedroomsFilter')?.addEventListener('change', (e) => {
            comprehensiveFilters.bedrooms = e.target.value ? Number(e.target.value) : null;
            applyComprehensiveFilters();
        });

        document.getElementById('bathroomsFilter')?.addEventListener('change', (e) => {
            comprehensiveFilters.bathrooms = e.target.value ? Number(e.target.value) : null;
            applyComprehensiveFilters();
        });

        document.getElementById('sqftMin')?.addEventListener('input', (e) => {
            comprehensiveFilters.sqftMin = e.target.value ? Number(e.target.value) : null;
            applyComprehensiveFilters();
        });

        document.getElementById('sqftMax')?.addEventListener('input', (e) => {
            comprehensiveFilters.sqftMax = e.target.value ? Number(e.target.value) : null;
            applyComprehensiveFilters();
        });

        document.getElementById('yearMin')?.addEventListener('input', (e) => {
            comprehensiveFilters.yearMin = e.target.value ? Number(e.target.value) : null;
            applyComprehensiveFilters();
        });

        document.getElementById('yearMax')?.addEventListener('input', (e) => {
            comprehensiveFilters.yearMax = e.target.value ? Number(e.target.value) : null;
            applyComprehensiveFilters();
        });

        document.getElementById('cityFilter')?.addEventListener('change', (e) => {
            comprehensiveFilters.city = e.target.value;
            applyComprehensiveFilters();
        });

        document.getElementById('countyFilter')?.addEventListener('change', (e) => {
            comprehensiveFilters.county = e.target.value;
            applyComprehensiveFilters();
        });

        document.querySelectorAll('.status-filter').forEach(cb => {
            cb.addEventListener('change', () => {
                comprehensiveFilters.statuses = new Set(Array.from(document.querySelectorAll('.status-filter:checked')).map(c => c.value));
                updateChipStates();
                applyComprehensiveFilters();
            });
        });

        document.querySelectorAll('.approval-filter').forEach(cb => {
            cb.addEventListener('change', () => {
                comprehensiveFilters.approvalStatuses = new Set(Array.from(document.querySelectorAll('.approval-filter:checked')).map(c => c.value));
                updateChipStates();
                applyComprehensiveFilters();
            });
        });

        document.getElementById('parkingFilter')?.addEventListener('change', (e) => {
            comprehensiveFilters.parking = e.target.value;
            applyComprehensiveFilters();
        });

        document.getElementById('listingDateFrom')?.addEventListener('change', applyComprehensiveFilters);
        document.getElementById('listingDateTo')?.addEventListener('change', applyComprehensiveFilters);

        document.getElementById('listedByFilter')?.addEventListener('change', (e) => {
            comprehensiveFilters.listedBy = e.target.value;
            applyComprehensiveFilters();
        });

        // Quick filters
        document.querySelectorAll('[data-price-range]').forEach(btn => {
            btn.addEventListener('click', () => {
                const [min, max] = btn.dataset.priceRange.split('-');
                const minVal = Number(min);
                const maxVal = Number(max);

                // set sliders
                const minSlider = document.getElementById('priceMinSlider');
                const maxSlider = document.getElementById('priceMaxSlider');
                if (minSlider && maxSlider) {
                    minSlider.value = minVal;
                    maxSlider.value = maxVal;
                }

                // update display
                setPriceInputs(minVal, maxVal);

                comprehensiveFilters.priceMin = minVal;
                comprehensiveFilters.priceMax = (maxVal >= Number(maxSlider.max)) ? Infinity : maxVal;

                updatePriceSliderRange();

                document.querySelectorAll('[data-price-range]').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                applyComprehensiveFilters();
            });
        });

        document.querySelectorAll('[data-year-range]').forEach(btn => {
            btn.addEventListener('click', () => {
                const [min, max] = btn.dataset.yearRange.split('-');
                document.getElementById('yearMin').value = min;
                document.getElementById('yearMax').value = max;
                comprehensiveFilters.yearMin = Number(min);
                comprehensiveFilters.yearMax = Number(max);
                document.querySelectorAll('[data-year-range]').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                applyComprehensiveFilters();
            });
        });

        // Clear filters
        document.getElementById('clearFiltersBtn')?.addEventListener('click', () => {
            document.querySelectorAll('#filterSidebar input[type="text"], #filterSidebar input[type="number"], #filterSidebar input[type="date"], #filterSidebar select').forEach(el => el.value = '');
            document.querySelectorAll('#filterSidebar input[type="checkbox"]').forEach(cb => cb.checked = true);
            document.querySelectorAll('.quick-filter-btn').forEach(btn => btn.classList.remove('active'));
            
            // Reset price sliders
            if (document.getElementById('priceMinSlider')) {
                const minSlider = document.getElementById('priceMinSlider');
                const maxSlider = document.getElementById('priceMaxSlider');
                if (minSlider && maxSlider) {
                    minSlider.value = PRICE_MIN;
                    maxSlider.value = PRICE_MAX;
                }
                setPriceInputs(PRICE_MIN, PRICE_MAX);
                updatePriceSliderRange();
            }
            
            comprehensiveFilters = {
                search: '',
                priceMin: 0,
                priceMax: Infinity,
                propertyTypes: new Set(Array.from(document.querySelectorAll('.property-type-filter')).map(c => c.value)),
                bedrooms: null,
                bathrooms: null,
                sqftMin: null,
                sqftMax: null,
                yearMin: null,
                yearMax: null,
                city: '',
                county: '',
                statuses: new Set(['For Sale', 'For Rent', 'Pending Sold', 'Sold', 'Pending Rented', 'Rented']),
                approvalStatuses: new Set(['pending', 'approved', 'rejected']),
                parking: '',
                listedBy: ''
            };
            
            updateChipStates();
            applyComprehensiveFilters();
        });

        function updateChipStates() {
            document.querySelectorAll('.filter-chip').forEach(chip => {
                const checkbox = chip.querySelector('input[type="checkbox"]');
                if (checkbox) {
                    chip.classList.toggle('active', checkbox.checked);
                }
            });
        }

        // Initialize
        setTimeout(() => {
            updateChipStates();
            document.getElementById('filteredCount').textContent = allProperties.length;
        }, 100);
    </script>

    <!-- SKELETON HYDRATION — Progressive Content Reveal -->
    <script>
    (function () {
        'use strict';

        var MIN_SKELETON_MS = 400;
        var skeletonStart   = Date.now();

        function hydrate() {
            var sk = document.getElementById('sk-screen');
            var pc = document.getElementById('page-content');

            if (!pc) return;
            if (!sk) {
                pc.style.cssText = 'display:block;opacity:1;';
                document.dispatchEvent(new Event('skeleton:hydrated'));
                return;
            }

            pc.style.display = 'block';
            pc.style.opacity = '0';

            requestAnimationFrame(function () {
                sk.style.transition = 'opacity 0.35s ease';
                sk.style.opacity    = '0';

                pc.style.transition = 'opacity 0.42s ease 0.1s';
                requestAnimationFrame(function () {
                    pc.style.opacity = '1';
                });
            });

            window.setTimeout(function () {
                if (sk && sk.parentNode) sk.parentNode.removeChild(sk);
                pc.style.transition = '';
                pc.style.opacity    = '';
                document.dispatchEvent(new Event('skeleton:hydrated'));
            }, 520);
        }

        function scheduleHydration() {
            var elapsed   = Date.now() - skeletonStart;
            var remaining = Math.max(0, MIN_SKELETON_MS - elapsed);
            if (remaining > 0) { window.setTimeout(hydrate, remaining); }
            else                { hydrate(); }
        }

        if (document.readyState === 'complete') {
            scheduleHydration();
        } else {
            window.addEventListener('load', scheduleHydration);
        }

    }());
    </script>

</body>
</html>
