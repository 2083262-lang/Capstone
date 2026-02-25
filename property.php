<?php
session_start();
include 'connection.php'; // Include your database connection

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
            a.first_name AS poster_first_name,
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
$pending_properties = array_filter($properties, fn($p) => ($p['approval_status'] ?? '') == 'pending' && !(isset($p['Status']) && in_array(strtolower(trim($p['Status'])), ['sold','pending sold'])));
$approved_properties = array_filter($properties, fn($p) => ($p['approval_status'] ?? '') == 'approved' && !(isset($p['Status']) && in_array(strtolower(trim($p['Status'])), ['sold','pending sold'])));
$rejected_properties = array_filter($properties, fn($p) => ($p['approval_status'] ?? '') == 'rejected' && !(isset($p['Status']) && in_array(strtolower(trim($p['Status'])), ['sold','pending sold'])));
// Pending Sold is represented by Status = 'Pending Sold'; Sold is Status = 'Sold'
$pending_sold_properties = array_filter($properties, fn($p) => isset($p['Status']) && strtolower(trim($p['Status'])) === 'pending sold');
$sold_properties = array_filter($properties, fn($p) => isset($p['Status']) && strtolower(trim($p['Status'])) === 'sold');


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Property Management - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
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
            .kpi-grid { grid-template-columns: repeat(3, 1fr); }
        }

        @media (max-width: 992px) {
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .properties-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .page-header { padding: 1.5rem; }
            .page-header h1 { font-size: 1.4rem; }
            .page-header-inner { flex-direction: column; align-items: flex-start; }
            .action-bar { flex-direction: column; align-items: flex-start; }
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .property-tabs .nav-link { padding: 0.65rem 0.75rem; font-size: 0.8rem; }
        }

        @media (max-width: 576px) {
            .kpi-grid { grid-template-columns: 1fr 1fr; }
            .property-tabs .nav-tabs { overflow-x: auto; flex-wrap: nowrap; }
            .property-tabs .nav-link { white-space: nowrap; }
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

        .filter-body::-webkit-scrollbar { width: 6px; }
        .filter-body::-webkit-scrollbar-track { background: transparent; }
        .filter-body::-webkit-scrollbar-thumb {
            background: var(--gold);
            border-radius: 3px;
        }
        .filter-body::-webkit-scrollbar-thumb:hover {
            background: var(--gold-dark);
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

    </style>
</head>
<body>

    <!-- Include Sidebar Component -->
    <?php include 'admin_sidebar.php'; ?>
    
    <!-- Include Navbar Component -->
    <?php include 'admin_navbar.php'; ?>

    <!-- Main Content Area -->
    <div class="admin-content">

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-inner">
                <div>
                    <h1><i class="bi bi-building me-2" style="color: var(--gold);"></i>Property Management</h1>
                    <p class="subtitle">Monitor, review, and manage all property listings across the platform</p>
                </div>
                <div>
                    <span class="header-badge"><i class="bi bi-shield-check me-1"></i> Admin Control Panel</span>
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
            </ul>

            <div class="tab-content" id="propertyStatusTabsContent">
                <!-- All Properties -->
                <div class="tab-pane fade show active" id="all-content" role="tabpanel">
                    <div class="properties-grid" id="all-grid"></div>
                </div>
                <!-- Pending Properties -->
                <div class="tab-pane fade" id="pending-content" role="tabpanel">
                    <div class="properties-grid" id="pending-grid"></div>
                </div>
                <!-- Pending Sold Properties -->
                <div class="tab-pane fade" id="pending-sold-content" role="tabpanel">
                    <div class="properties-grid" id="pending-sold-grid"></div>
                </div>
                <!-- Approved Properties -->
                <div class="tab-pane fade" id="approved-content" role="tabpanel">
                    <div class="properties-grid" id="approved-grid"></div>
                </div>
                <!-- Rejected Properties -->
                <div class="tab-pane fade" id="rejected-content" role="tabpanel">
                    <div class="properties-grid" id="rejected-grid"></div>
                </div>
                <!-- Sold Properties -->
                <div class="tab-pane fade" id="sold-content" role="tabpanel">
                    <div class="properties-grid" id="sold-grid"></div>
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
    </div>


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
                        <label class="filter-chip">
                            <input type="checkbox" class="property-type-filter" value="Single-Family Home" checked>
                            <span>Single-Family</span>
                        </label>
                        <label class="filter-chip">
                            <input type="checkbox" class="property-type-filter" value="Condominium" checked>
                            <span>Condo</span>
                        </label>
                        <label class="filter-chip">
                            <input type="checkbox" class="property-type-filter" value="Multi-Family" checked>
                            <span>Multi-Family</span>
                        </label>
                        <label class="filter-chip">
                            <input type="checkbox" class="property-type-filter" value="House" checked>
                            <span>House</span>
                        </label>
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
                            <label class="form-label" style="font-size: 0.875rem; font-weight: 600;">County</label>
                            <select id="countyFilter" class="filter-select">
                                <option value="">All Counties</option>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Embed properties data for client-side filtering
        const allProperties = <?php echo json_encode(array_map(function($p){
            // expose necessary fields to client for admin card display
            return [
                'property_ID' => $p['property_ID'] ?? null,
                'StreetAddress' => $p['StreetAddress'] ?? '',
                'City' => $p['City'] ?? '',
                'State' => $p['State'] ?? '',
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
            ];
        }, $properties)); ?>;

        // Determine price bounds
        const prices = allProperties.map(p => p.ListingPrice || 0);
        const PRICE_MIN = Math.min(...prices, 0);
        const PRICE_MAX = Math.max(...prices, 100000000);

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
                    const hay = ((p.StreetAddress||'') + ' ' + (p.City||'') + ' ' + (p.State||'') + ' ' + (p.PropertyType||'')).toLowerCase();
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
                summary.innerHTML = `<strong>${filtered.length}</strong> result(s) — Pending: ${byStatus['pending']||0}, Pending Sold: ${pendingSoldCount}, Approved: ${byStatus['approved']||0}, Rejected: ${byStatus['rejected']||0}, Sold: ${soldCount}`;
            }

            renderGrids(filtered);
        }

        function renderGrids(filtered) {
            // Get all pre-rendered card wrappers
            const allCards = Array.from(document.querySelectorAll('#all-property-cards .property-card-wrapper'));
            
            // Create a map for quick lookup
            const cardMap = new Map();
            allCards.forEach(card => {
                const propertyId = card.getAttribute('data-property-id');
                cardMap.set(propertyId, card);
            });

            const ensureGrid = (paneSelector) => {
                const pane = document.querySelector(paneSelector);
                if (!pane) return null;
                // remove any existing empty-state
                const existingEmpty = pane.querySelector('.empty-state');
                if (existingEmpty) existingEmpty.remove();
                let grid = pane.querySelector('.properties-grid');
                if (!grid) {
                    grid = document.createElement('div');
                    grid.className = 'properties-grid';
                    pane.appendChild(grid);
                }
                return grid;
            };

            const allContainer = ensureGrid('#all-content');
            const pendingContainer = ensureGrid('#pending-content');
            const approvedContainer = ensureGrid('#approved-content');
            const rejectedContainer = ensureGrid('#rejected-content');
            const soldContainer = ensureGrid('#sold-content');
            const pendingSoldContainer = ensureGrid('#pending-sold-content');

            // Group by approval_status for pending/approved/rejected (exclude sold and pending sold listings)
            const groupByApproval = (status) => filtered.filter(p => {
                const sl = (p.Status||'').toLowerCase();
                return (p.approval_status||'') === status && sl !== 'sold' && sl !== 'pending sold';
            });
            // Separate Pending Sold and Sold
            const pendingSoldItems = filtered.filter(p => (p.Status||'').toLowerCase() === 'pending sold');
            const soldItems = filtered.filter(p => (p.Status||'').toLowerCase() === 'sold');

            const fill = (container, items) => {
                if (!container) return;
                // Clear existing content
                container.innerHTML = '';
                if (!items.length) {
                    // Render the empty state centered vertically and horizontally
                    container.innerHTML = `<div class="empty-state" style="max-width:720px;width:100%;margin:0 1rem;">
                        <i class="bi bi-search empty-state-icon"></i>
                        <h4>No matching properties</h4>
                        <p class="text-muted">Try adjusting your filters.</p>
                    </div>`;
                    // Use flex layout to center the message within the pane
                    container.style.display = 'flex';
                    container.style.justifyContent = 'center';
                    container.style.alignItems = 'center';
                    container.style.minHeight = '220px';
                    // Clear grid-specific properties if previously set
                    container.style.gridTemplateColumns = '';
                    container.style.gap = '';
                    return;
                }
                // Append cloned cards
                items.forEach(item => {
                    const card = cardMap.get(item.property_ID.toString());
                    if (card) {
                        const clonedCard = card.cloneNode(true);
                        container.appendChild(clonedCard);
                    }
                });
                // Restore grid layout
                container.style.display = 'grid';
                container.style.gridTemplateColumns = 'repeat(auto-fill, minmax(380px,1fr))';
                container.style.gap = '1.5rem';
                // Clear any flex centering styles
                container.style.justifyContent = '';
                container.style.alignItems = '';
                container.style.minHeight = '';
            };

            fill(allContainer, filtered);
            fill(pendingContainer, groupByApproval('pending'));
            fill(pendingSoldContainer, pendingSoldItems);
            fill(approvedContainer, groupByApproval('approved'));
            fill(rejectedContainer, groupByApproval('rejected'));
            fill(soldContainer, soldItems);
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
        
        let comprehensiveFilters = {
            search: '',
            priceMin: 0,
            priceMax: Infinity,
            propertyTypes: new Set(['Single-Family Home', 'Condominium', 'Multi-Family', 'House']),
            bedrooms: null,
            bathrooms: null,
            sqftMin: null,
            sqftMax: null,
            yearMin: null,
            yearMax: null,
            city: '',
            county: '',
            statuses: new Set(['For Sale', 'For Rent', 'Pending Sold', 'Sold']),
            approvalStatuses: new Set(['pending', 'approved', 'rejected']),
            parking: ''
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
                if (comprehensiveFilters.county && property.County !== comprehensiveFilters.county) return false;
                
                // Status
                if (!comprehensiveFilters.statuses.has(property.Status)) return false;
                
                // Approval
                if (!comprehensiveFilters.approvalStatuses.has(property.approval_status)) return false;
                
                // Parking
                if (comprehensiveFilters.parking && property.ParkingType !== comprehensiveFilters.parking) return false;
                
                // Listing Date
                if (document.getElementById('listingDateFrom')?.value && property.ListingDate < document.getElementById('listingDateFrom').value) return false;
                if (document.getElementById('listingDateTo')?.value && property.ListingDate > document.getElementById('listingDateTo').value) return false;
                
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
            if (comprehensiveFilters.statuses.size < 4) count++;
            if (comprehensiveFilters.approvalStatuses.size < 3) count++;
            if (comprehensiveFilters.parking) count++;
            if (document.getElementById('listingDateFrom')?.value || document.getElementById('listingDateTo')?.value) count++;
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
        const priceMinSlider = document.getElementById('priceMinSlider');
        const priceMaxSlider = document.getElementById('priceMaxSlider');
        const priceMinInput = document.getElementById('priceMin');
        const priceMaxInput = document.getElementById('priceMax');
        const priceSliderRange = document.getElementById('priceSliderRange');

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
                propertyTypes: new Set(['Single-Family Home', 'Condominium', 'Multi-Family', 'House']),
                bedrooms: null,
                bathrooms: null,
                sqftMin: null,
                sqftMax: null,
                yearMin: null,
                yearMax: null,
                city: '',
                county: '',
                statuses: new Set(['For Sale', 'For Rent', 'Pending Sold', 'Sold']),
                approvalStatuses: new Set(['pending', 'approved', 'rejected']),
                parking: ''
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

</body>
</html>