<?php
session_start();
include '../connection.php';

// Check if the user is logged in AND their role is 'agent'
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'agent') {
    header("Location: ../login.php");
    exit();
}
$agent_account_id = $_SESSION['account_id'];
$agent_username = $_SESSION['username'];

// --- Fetch Agent Info ---
$agent_info_sql = "
    SELECT a.first_name, a.last_name, a.username, ai.profile_picture_url
    FROM accounts a 
    JOIN agent_information ai ON a.account_id = ai.account_id
    WHERE a.account_id = ?";
$stmt_agent_info = $conn->prepare($agent_info_sql);
$stmt_agent_info->bind_param("i", $agent_account_id);
$stmt_agent_info->execute();
$agent = $stmt_agent_info->get_result()->fetch_assoc();
$stmt_agent_info->close();

// --- Fetch All Agent Properties with Stats ---
$properties_sql = "
    SELECT p.*, pi.PhotoURL,
           rd.monthly_rent AS rd_monthly_rent,
           rd.security_deposit AS rd_security_deposit,
           rd.lease_term_months AS rd_lease_term_months,
           rd.furnishing AS rd_furnishing,
           rd.available_from AS rd_available_from,
           (SELECT COUNT(*) FROM tour_requests tr WHERE tr.property_id = p.property_ID) as tour_count,
           (SELECT COUNT(*) FROM property_images pimg WHERE pimg.property_ID = p.property_ID) as photo_count
    FROM property p
    JOIN property_log pl ON p.property_ID = pl.property_id
    LEFT JOIN property_images pi ON p.property_ID = pi.property_ID AND pi.SortOrder = 1
    LEFT JOIN rental_details rd ON rd.property_id = p.property_ID
    WHERE pl.account_id = ? AND pl.action = 'CREATED'
    GROUP BY p.property_ID 
    ORDER BY p.ListingDate DESC";

$stmt_properties = $conn->prepare($properties_sql);
$stmt_properties->bind_param("i", $agent_account_id);
$stmt_properties->execute();
$result = $stmt_properties->get_result();
$all_properties = $result->fetch_all(MYSQLI_ASSOC);
$stmt_properties->close();

// Separate properties into categories
$approved_properties = array_filter($all_properties, fn($p) => $p['approval_status'] == 'approved' && $p['Status'] != 'Pending Sold' && $p['Status'] != 'Sold');
$pending_properties = array_filter($all_properties, fn($p) => $p['approval_status'] == 'pending');
$rejected_properties = array_filter($all_properties, fn($p) => $p['approval_status'] == 'rejected');
$pending_sold_properties = array_filter($all_properties, fn($p) => $p['Status'] == 'Pending Sold');
$sold_properties = array_filter($all_properties, fn($p) => $p['Status'] == 'Sold');

// Portfolio stats
$total_value = array_sum(array_map(fn($p) => $p['ListingPrice'], array_filter($all_properties, fn($p) => $p['approval_status'] == 'approved' && $p['Status'] != 'Sold')));
$total_views = array_sum(array_column($all_properties, 'ViewsCount'));
$total_likes = array_sum(array_column($all_properties, 'Likes'));

// Fetch amenities for the modal form
$amenities_result = $conn->query("SELECT * FROM amenities ORDER BY amenity_name");
$amenities = $amenities_result->fetch_all(MYSQLI_ASSOC);

// Fetch property types for the modal form and filter
$property_types_result = $conn->query("SELECT * FROM property_types ORDER BY type_name");
$property_types = $property_types_result->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Properties - HomeEstate Realty</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>
        :root {
            --gold: #d4af37;
            --gold-light: #f4d03f;
            --gold-dark: #b8941f;
            --blue: #2563eb;
            --blue-light: #3b82f6;
            --blue-dark: #1e40af;

            --black: #0a0a0a;
            --black-light: #111111;
            --black-lighter: #1a1a1a;
            --black-border: #1f1f1f;
            --white: #ffffff;

            --gray-50: #f8f9fa;
            --gray-100: #e8e9eb;
            --gray-200: #d1d4d7;
            --gray-300: #b8bec4;
            --gray-400: #9ca4ab;
            --gray-500: #7a8a99;
            --gray-600: #5d6d7d;
            --gray-700: #3f4b56;
            --gray-800: #2a3138;
            --gray-900: #1a1f24;

            --card-bg: linear-gradient(135deg, rgba(26, 26, 26, 0.8) 0%, rgba(10, 10, 10, 0.9) 100%);
            --card-border: rgba(37, 99, 235, 0.15);
            --card-hover-border: rgba(37, 99, 235, 0.35);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background-color: var(--black);
            color: var(--white);
            line-height: 1.6;
            overflow-x: hidden;
        }

        /* ===== SCROLLBAR ===== */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: rgba(26, 26, 26, 0.4); }
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, var(--gold), var(--gold-dark));
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, var(--gold-light), var(--gold));
        }

        /* ===== MAIN CONTENT ===== */
        .property-content {
            padding: 2rem;
            max-width: 1440px;
            margin: 0 auto;
        }

        /* ===== PAGE HEADER ===== */
        .page-header {
            background: linear-gradient(135deg, var(--black) 0%, var(--black-lighter) 100%);
            border: 1px solid var(--card-border);
            border-radius: 4px;
            padding: 2rem 2.5rem;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background:
                radial-gradient(ellipse at top right, rgba(37, 99, 235, 0.06) 0%, transparent 50%),
                radial-gradient(ellipse at bottom left, rgba(212, 175, 55, 0.04) 0%, transparent 50%);
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
            background: linear-gradient(135deg, var(--white) 0%, var(--gray-100) 50%, var(--gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.25rem;
        }

        .page-header .subtitle {
            color: var(--gray-400);
            font-size: 0.95rem;
        }

        .page-header .header-actions {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }

        /* ===== BUTTONS ===== */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            cursor: pointer;
            border: none;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        /* ===== KPI STAT CARDS ===== */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .kpi-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
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
            border-color: var(--card-hover-border);
            box-shadow: 0 8px 32px rgba(37, 99, 235, 0.12),
                        inset 0 0 20px rgba(37, 99, 235, 0.03);
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
            background: linear-gradient(135deg, rgba(212, 175, 55, 0.1) 0%, rgba(212, 175, 55, 0.2) 100%);
            color: var(--gold);
            border: 1px solid rgba(212, 175, 55, 0.2);
        }

        .kpi-icon.blue {
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.1) 0%, rgba(37, 99, 235, 0.2) 100%);
            color: var(--blue-light);
            border: 1px solid rgba(37, 99, 235, 0.2);
        }

        .kpi-icon.green {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.1) 0%, rgba(34, 197, 94, 0.2) 100%);
            color: #22c55e;
            border: 1px solid rgba(34, 197, 94, 0.2);
        }

        .kpi-icon.red {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1) 0%, rgba(239, 68, 68, 0.2) 100%);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .kpi-icon.amber {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1) 0%, rgba(245, 158, 11, 0.2) 100%);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .kpi-icon.cyan {
            background: linear-gradient(135deg, rgba(6, 182, 212, 0.1) 0%, rgba(6, 182, 212, 0.2) 100%);
            color: #06b6d4;
            border: 1px solid rgba(6, 182, 212, 0.2);
        }

        .kpi-card .kpi-label {
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--gray-400);
            margin-bottom: 0.25rem;
        }

        .kpi-card .kpi-value {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--white);
            line-height: 1.2;
        }

        /* ===== ALERT ===== */
        .alert-dark-custom {
            background: rgba(34, 197, 94, 0.06);
            border: 1px solid rgba(34, 197, 94, 0.2);
            color: #22c55e;
            border-radius: 4px;
        }
        .alert-dark-custom .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
        }
        .alert-danger-custom {
            background: rgba(239, 68, 68, 0.06);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #ef4444;
            border-radius: 4px;
        }
        .alert-danger-custom .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
        }
        .alert-warning-custom {
            background: rgba(245, 158, 11, 0.06);
            border: 1px solid rgba(245, 158, 11, 0.2);
            color: #f59e0b;
            border-radius: 4px;
        }
        .alert-warning-custom .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
        }

        /* ===== TABS ===== */
        .property-tabs {
            margin-bottom: 2rem;
        }

        .property-tabs .nav-tabs {
            border-bottom: 1px solid rgba(37, 99, 235, 0.1);
            gap: 0.25rem;
        }

        .property-tabs .nav-link {
            border: none;
            border-bottom: 3px solid transparent;
            background: transparent;
            color: var(--gray-400);
            font-weight: 600;
            font-size: 0.9rem;
            padding: 0.85rem 1.25rem;
            transition: all 0.3s ease;
            border-radius: 0;
        }

        .property-tabs .nav-link:hover {
            color: var(--white);
            background: rgba(37, 99, 235, 0.04);
            border-bottom-color: rgba(37, 99, 235, 0.3);
        }

        .property-tabs .nav-link.active {
            color: var(--gold);
            background: rgba(212, 175, 55, 0.04);
            border-bottom-color: var(--gold);
        }

        .property-tabs .tab-count {
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

        .tab-count.green { background: rgba(34, 197, 94, 0.1); color: #22c55e; border: 1px solid rgba(34, 197, 94, 0.2); }
        .tab-count.amber { background: rgba(245, 158, 11, 0.1); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.2); }
        .tab-count.red { background: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.2); }
        .tab-count.cyan { background: rgba(6, 182, 212, 0.1); color: #06b6d4; border: 1px solid rgba(6, 182, 212, 0.2); }
        .tab-count.dark { background: rgba(148, 163, 184, 0.1); color: #94a3b8; border: 1px solid rgba(148, 163, 184, 0.2); }

        /* ===== PROPERTY CARD (Dark Theme) ===== */
        .prop-card {
            background: rgba(26, 26, 26, 0.6);
            border: 1px solid rgba(37, 99, 235, 0.1);
            border-radius: 4px;
            overflow: hidden;
            transition: all 0.3s ease;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .prop-card:hover {
            border-color: rgba(37, 99, 235, 0.3);
            box-shadow: 0 8px 32px rgba(37, 99, 235, 0.12);
            transform: translateY(-4px);
        }

        .prop-card-img-wrap {
            position: relative;
            height: 200px;
            overflow: hidden;
        }

        .prop-card-img-wrap img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }

        .prop-card:hover .prop-card-img-wrap img {
            transform: scale(1.05);
        }

        .prop-card-img-wrap .overlay-gradient {
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 60%;
            background: linear-gradient(to top, rgba(10, 10, 10, 0.9) 0%, transparent 100%);
            pointer-events: none;
        }

        .prop-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            display: inline-block;
            padding: 0.25rem 0.65rem;
            border-radius: 2px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            z-index: 2;
        }

        .prop-badge.live { background: rgba(34, 197, 94, 0.15); color: #22c55e; border: 1px solid rgba(34, 197, 94, 0.3); }
        .prop-badge.pending { background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3); }
        .prop-badge.rejected { background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); }
        .prop-badge.pending-sold { background: rgba(6, 182, 212, 0.15); color: #06b6d4; border: 1px solid rgba(6, 182, 212, 0.3); }
        .prop-badge.sold { background: rgba(148, 163, 184, 0.15); color: #94a3b8; border: 1px solid rgba(148, 163, 184, 0.3); }

        .prop-type-badge {
            position: absolute;
            top: 12px;
            left: 12px;
            padding: 0.2rem 0.6rem;
            border-radius: 2px;
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            z-index: 2;
            background: rgba(10, 10, 10, 0.7);
            color: var(--gray-300);
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(4px);
        }

        .prop-price-overlay {
            position: absolute;
            bottom: 12px;
            left: 14px;
            z-index: 2;
        }

        .prop-price-overlay .price {
            font-size: 1.3rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .prop-price-overlay .price-suffix {
            font-size: 0.75rem;
            color: var(--gray-400);
            font-weight: 600;
            -webkit-text-fill-color: var(--gray-400);
        }

        .prop-card-body {
            padding: 1.25rem;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .prop-card-body .prop-address {
            font-weight: 700;
            font-size: 0.95rem;
            color: var(--white);
            margin-bottom: 0.2rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .prop-card-body .prop-location {
            font-size: 0.8rem;
            color: var(--gray-500);
            margin-bottom: 0.75rem;
        }
        
        .prop-card-body .prop-location i {
            color: var(--blue-light);
            margin-right: 0.3rem;
        }

        .prop-details-row {
            display: flex;
            gap: 1rem;
            padding: 0.75rem 0;
            border-top: 1px solid rgba(37, 99, 235, 0.08);
            border-bottom: 1px solid rgba(37, 99, 235, 0.08);
            margin-bottom: 0.75rem;
        }

        .prop-details-row .detail-item {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.8rem;
            color: var(--gray-400);
        }

        .prop-details-row .detail-item i {
            color: var(--gold);
            font-size: 0.75rem;
        }

        .prop-details-row .detail-item strong {
            color: var(--white);
            font-weight: 700;
        }

        .prop-stats-row {
            display: flex;
            gap: 1rem;
            margin-bottom: 0.75rem;
            font-size: 0.75rem;
            color: var(--gray-500);
        }

        .prop-stats-row span {
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .prop-stats-row span i {
            font-size: 0.7rem;
        }

        /* Rental Info */
        .rental-info {
            background: rgba(37, 99, 235, 0.04);
            border: 1px solid rgba(37, 99, 235, 0.1);
            border-radius: 4px;
            padding: 0.6rem 0.8rem;
            margin-bottom: 0.75rem;
            font-size: 0.75rem;
            color: var(--gray-400);
        }

        .rental-info .rental-tag {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .rental-info .rental-tag + .rental-tag::before {
            content: '•';
            margin-right: 0.3rem;
            color: var(--gray-600);
        }

        .prop-card-footer {
            margin-top: auto;
            display: flex;
            gap: 0.5rem;
        }

        .prop-card-footer .btn-view {
            flex: 1;
            background: transparent;
            color: var(--blue-light);
            border: 1px solid rgba(37, 99, 235, 0.25);
            padding: 0.5rem 0.75rem;
            font-size: 0.8rem;
            font-weight: 600;
            border-radius: 4px;
            text-decoration: none;
            text-align: center;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
        }

        .prop-card-footer .btn-view:hover {
            background: rgba(37, 99, 235, 0.08);
            border-color: var(--blue);
            color: var(--white);
        }

        .prop-card-footer .btn-sold {
            flex: 1;
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.1) 0%, rgba(34, 197, 94, 0.05) 100%);
            color: #22c55e;
            border: 1px solid rgba(34, 197, 94, 0.25);
            padding: 0.5rem 0.75rem;
            font-size: 0.8rem;
            font-weight: 600;
            border-radius: 4px;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
        }

        .prop-card-footer .btn-sold:hover {
            background: rgba(34, 197, 94, 0.15);
            border-color: #22c55e;
            color: #22c55e;
        }

        .prop-card-footer .sale-badge {
            flex: 1;
            padding: 0.5rem 0.75rem;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-radius: 4px;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
        }

        .sale-badge.verifying { background: rgba(6, 182, 212, 0.08); color: #06b6d4; border: 1px solid rgba(6, 182, 212, 0.2); }
        .sale-badge.completed { background: rgba(148, 163, 184, 0.08); color: #94a3b8; border: 1px solid rgba(148, 163, 184, 0.2); }

        /* ===== ADD PROPERTY BUTTON ===== */
        .btn-gold {
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 50%, var(--gold-dark) 100%);
            color: var(--white);
            border: none;
            padding: 12px 28px;
            font-size: 0.9rem;
            font-weight: 700;
            border-radius: 4px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 16px rgba(212, 175, 55, 0.3),
                        0 0 0 1px rgba(212, 175, 55, 0.3),
                        inset 0 1px 0 rgba(255, 255, 255, 0.2);
            position: relative;
            overflow: hidden;
        }

        .btn-gold::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s ease;
        }

        .btn-gold:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 8px 28px rgba(212, 175, 55, 0.5),
                        0 0 0 1px rgba(212, 175, 55, 0.5),
                        0 0 40px rgba(212, 175, 55, 0.3),
                        inset 0 1px 0 rgba(255, 255, 255, 0.3);
            color: var(--white);
        }

        .btn-gold:hover::before { left: 100%; }

        .btn-gold i {
            font-size: 1rem;
            color: var(--white);
            display: inline-flex;
            align-items: center;
            transition: transform 0.3s ease;
        }

        .btn-gold:hover i { transform: rotate(90deg); }

        .btn-gold span { display: inline-flex; align-items: center; }

        /* ===== EMPTY STATE ===== */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--gray-500);
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 4px;
        }

        .empty-state i {
            font-size: 3rem;
            color: rgba(37, 99, 235, 0.2);
            margin-bottom: 1rem;
            display: block;
        }

        .empty-state p {
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }

        /* ===== DARK MODAL ===== */
        .modal-dark .modal-content {
            background: linear-gradient(180deg, #141414 0%, #0f0f0f 100%);
            border: 1px solid rgba(37, 99, 235, 0.2);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.8);
            color: var(--white);
        }

        .modal-dark .modal-header {
            background: linear-gradient(180deg, #141414 0%, #111111 100%);
            border-bottom: 1px solid rgba(212, 175, 55, 0.2);
            padding: 1.25rem 1.5rem;
        }

        .modal-dark .modal-title {
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-dark .modal-title i { color: var(--gold); }

        .modal-dark .modal-header .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
        }

        .modal-dark .modal-body { 
            padding: 1.5rem; 
            overflow-y: auto;
            max-height: calc(100vh - 210px);
        }

        /* Custom Scrollbar for Modal */
        .modal-dark .modal-body::-webkit-scrollbar {
            width: 8px;
        }
        
        .modal-dark .modal-body::-webkit-scrollbar-track {
            background: rgba(26, 26, 26, 0.4);
            border-radius: 4px;
        }
        
        .modal-dark .modal-body::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, var(--gold) 0%, var(--gold-dark) 100%);
            border-radius: 4px;
            transition: background 0.2s ease;
        }
        
        .modal-dark .modal-body::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, var(--gold-light) 0%, var(--gold) 100%);
        }

        .modal-dark .modal-footer {
            border-top: 1px solid rgba(37, 99, 235, 0.1);
            padding: 1rem 1.5rem;
        }

        /* Modal form controls */
        .modal-dark .form-label {
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--gray-300);
        }

        .modal-dark .form-control,
        .modal-dark .form-select {
            background: rgba(26, 26, 26, 0.8);
            border: 1px solid rgba(37, 99, 235, 0.15);
            color: var(--white);
            border-radius: 4px;
            padding: 0.6rem 0.8rem;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .modal-dark .form-control:focus,
        .modal-dark .form-select:focus {
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
            background: rgba(26, 26, 26, 0.95);
        }

        .modal-dark .form-control::placeholder { color: var(--gray-600); }
        .modal-dark .form-text { color: var(--gray-500); }

        .modal-dark .form-control.is-invalid {
            border-color: #ef4444;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.15);
        }

        .modal-dark .input-group-text {
            background: rgba(37, 99, 235, 0.08);
            border: 1px solid rgba(37, 99, 235, 0.15);
            color: var(--gold);
            font-weight: 700;
        }

        /* Modal tabs */
        .modal-dark .nav-tabs {
            border-bottom: 1px solid rgba(37, 99, 235, 0.1);
        }

        .modal-dark .nav-tabs .nav-link {
            color: var(--gray-400);
            border: none;
            border-bottom: 2px solid transparent;
            background: transparent;
            font-weight: 600;
            font-size: 0.85rem;
            padding: 0.65rem 1rem;
            transition: all 0.3s ease;
        }

        .modal-dark .nav-tabs .nav-link:hover { color: var(--white); }

        .modal-dark .nav-tabs .nav-link.active {
            color: var(--gold);
            border-bottom-color: var(--gold);
            background: transparent;
        }

        .modal-dark .form-section-title {
            font-weight: 700;
            color: var(--white);
            margin-bottom: 1.25rem;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-dark .form-section-title::before {
            content: '';
            width: 3px;
            height: 18px;
            background: var(--gold);
            border-radius: 2px;
        }

        .modal-dark hr { border-color: rgba(37, 99, 235, 0.1); }

        /* Amenity grid */
        .modal-dark .amenity-grid { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .modal-dark .amenity-item .form-check-input { display: none; }

        .modal-dark .amenity-item .form-check-label {
            background: rgba(26, 26, 26, 0.8);
            border: 1px solid rgba(37, 99, 235, 0.15);
            padding: 0.375rem 0.85rem;
            border-radius: 2px;
            cursor: pointer;
            transition: all 0.2s ease;
            font-weight: 500;
            font-size: 0.8rem;
            color: var(--gray-400);
        }

        .modal-dark .amenity-item .form-check-label:hover {
            border-color: rgba(212, 175, 55, 0.3);
            color: var(--gold);
        }

        .modal-dark .amenity-item .form-check-input:checked + .form-check-label {
            background: linear-gradient(135deg, rgba(212, 175, 55, 0.15) 0%, rgba(212, 175, 55, 0.1) 100%);
            color: var(--gold);
            border-color: rgba(212, 175, 55, 0.4);
        }

        /* Image preview */
        .modal-dark .image-preview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 0.75rem;
        }

        .modal-dark .preview-image {
            width: 100%;
            height: 100px;
            object-fit: cover;
            border-radius: 4px;
            border: 1px solid rgba(37, 99, 235, 0.2);
        }

        /* Modal buttons */
        .modal-dark .btn-secondary {
            background: rgba(37, 99, 235, 0.08);
            border: 1px solid rgba(37, 99, 235, 0.2);
            color: var(--gray-300);
        }
        .modal-dark .btn-secondary:hover {
            background: rgba(37, 99, 235, 0.15);
            border-color: rgba(37, 99, 235, 0.3);
            color: var(--white);
        }

        .modal-dark .btn-brand {
            background: linear-gradient(135deg, var(--gold-dark), var(--gold));
            border: none;
            color: var(--white);
            font-weight: 700;
        }
        .modal-dark .btn-brand:hover {
            box-shadow: 0 4px 16px rgba(212, 175, 55, 0.3);
        }

        .modal-dark .btn-success {
            background: linear-gradient(135deg, #15803d, #22c55e);
            border: none;
            color: var(--white);
            font-weight: 700;
        }
        .modal-dark .btn-success:hover {
            box-shadow: 0 4px 16px rgba(34, 197, 94, 0.3);
        }

        .modal-dark .btn-outline-secondary {
            background: transparent;
            border: 1px solid rgba(37, 99, 235, 0.2);
            color: var(--gray-300);
        }
        .modal-dark .btn-outline-secondary:hover {
            background: rgba(37, 99, 235, 0.08);
            border-color: rgba(37, 99, 235, 0.3);
            color: var(--white);
        }

        .modal-dark .alert-info {
            background: rgba(37, 99, 235, 0.06);
            border: 1px solid rgba(37, 99, 235, 0.2);
            color: var(--blue-light);
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 1400px) {
            .kpi-grid { grid-template-columns: repeat(3, 1fr); }
        }

        @media (max-width: 992px) {
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 768px) {
            .property-content { padding: 1rem; }
            .page-header { padding: 1.5rem; }
            .page-header-inner { flex-direction: column; text-align: center; }
            .page-header h1 { font-size: 1.3rem; }
            .kpi-grid { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 576px) {
            .kpi-grid { grid-template-columns: 1fr; }
        }

        /* ===== RENTAL DETAILS SECTION ===== */
        .rental-section-modal {
            background: rgba(37, 99, 235, 0.04);
            border: 1px solid rgba(37, 99, 235, 0.15);
            border-radius: 4px;
            padding: 1.25rem;
            margin-top: 1rem;
        }
        .rental-section-modal .rental-section-label {
            color: var(--gold);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* ===== FLOOR IMAGES ===== */
        .floor-upload-card {
            background: rgba(26, 26, 26, 0.6);
            border: 1px solid var(--card-border);
            border-radius: 4px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        .floor-upload-card.error {
            border-color: rgba(239, 68, 68, 0.5);
        }
        .floor-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }
        .floor-title {
            color: var(--white);
            font-weight: 600;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .floor-title i { color: var(--blue-light); }
        .floor-badge {
            background: rgba(37, 99, 235, 0.12);
            color: var(--blue-light);
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.2rem 0.6rem;
            border-radius: 4px;
            border: 1px solid rgba(37, 99, 235, 0.2);
        }
        .floor-upload-area {
            border: 2px dashed rgba(37, 99, 235, 0.25);
            border-radius: 4px;
            padding: 1.5rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .floor-upload-area:hover {
            border-color: var(--blue);
            background: rgba(37, 99, 235, 0.04);
        }
        .floor-upload-area.has-files {
            border-color: rgba(34, 197, 94, 0.3);
            background: rgba(34, 197, 94, 0.04);
        }
        .floor-upload-icon {
            color: var(--blue-light);
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }
        .floor-upload-text {
            color: var(--gray-300);
            font-size: 0.85rem;
            font-weight: 500;
        }
        .floor-upload-subtext {
            color: var(--gray-500);
            font-size: 0.75rem;
        }
        .floor-preview-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.75rem;
        }
        .floor-preview-item {
            position: relative;
            width: 80px;
            height: 80px;
            border-radius: 4px;
            overflow: hidden;
            border: 1px solid var(--card-border);
        }
        .floor-preview-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .floor-image-info {
            display: none;
        }
        .remove-floor-image {
            position: absolute;
            top: 2px;
            right: 2px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: rgba(239, 68, 68, 0.8);
            color: white;
            border: none;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            padding: 0;
        }
        .remove-floor-image:hover {
            background: #ef4444;
        }

        /* Featured image upload area in modal */
        .featured-upload-area {
            border: 2px dashed rgba(212, 175, 55, 0.25);
            border-radius: 4px;
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .featured-upload-area:hover {
            border-color: var(--gold);
            background: rgba(212, 175, 55, 0.04);
        }
        .featured-upload-area i {
            color: var(--gold);
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        .featured-upload-area .upload-text {
            color: var(--gray-300);
            font-size: 0.9rem;
            font-weight: 500;
        }
        .featured-upload-area .upload-subtext {
            color: var(--gray-500);
            font-size: 0.8rem;
        }

        /* Label required/optional markers */
        .form-label .required { color: #ef4444; }
        .form-label .optional { color: #f59e0b; font-weight: 400; font-size: 0.8rem; }

        /* ===== PROPERTY FILTER SYSTEM ===== */
        .filter-sidebar {
            position: fixed;
            top: 0;
            right: 0;
            width: 100%;
            height: 100%;
            z-index: 9998;
            pointer-events: none;
        }

        .filter-sidebar.active { pointer-events: all; }

        .filter-sidebar-overlay {
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(4px);
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
        }

        .filter-sidebar.active .filter-sidebar-overlay {
            opacity: 1;
            pointer-events: all;
        }

        .filter-sidebar-content {
            position: absolute;
            top: 0; right: 0;
            width: 420px;
            max-width: 90vw;
            height: 100%;
            background: var(--black-light);
            border-left: 1px solid var(--card-border);
            box-shadow: -4px 0 40px rgba(0, 0, 0, 0.5);
            transform: translateX(100%);
            transition: transform 0.3s ease;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .filter-sidebar.active .filter-sidebar-content {
            transform: translateX(0);
        }

        /* Open Filter Button */
        .btn-dark-outline {
            background: transparent;
            border: 1px solid rgba(255,255,255,0.15);
            color: var(--gray-300);
            font-weight: 500;
            font-size: 0.875rem;
            padding: 0.6rem 1.25rem;
            border-radius: 4px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }

        .btn-dark-outline:hover {
            border-color: var(--blue);
            color: var(--white);
            background: rgba(37, 99, 235, 0.06);
        }

        .filter-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            height: 20px;
            padding: 0 0.4rem;
            font-size: 0.7rem;
            font-weight: 700;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--gold), var(--gold-dark));
            color: #000;
            margin-left: 0.5rem;
        }

        .filter-header {
            background: linear-gradient(135deg, var(--black) 0%, var(--black-lighter) 100%);
            padding: 1.25rem 1.75rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(212, 175, 55, 0.15);
            position: relative;
        }

        .filter-header::after {
            content: '';
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent);
        }

        .filter-header h4 {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--white);
            display: flex;
            align-items: center;
            margin-bottom: 0;
        }

        .filter-header h4 i {
            color: var(--gold);
        }

        .btn-close-sidebar {
            background: transparent;
            border: none;
            color: var(--gray-400);
            font-size: 1.5rem;
            cursor: pointer;
            transition: color 0.3s ease;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-close-sidebar:hover {
            color: var(--white);
            transform: rotate(90deg);
        }

        .filter-body {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem;
        }

        .filter-body::-webkit-scrollbar {
            width: 6px;
        }

        .filter-body::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.3);
        }

        .filter-body::-webkit-scrollbar-thumb {
            background: rgba(212, 175, 55, 0.4);
            border-radius: 3px;
        }

        .filter-body::-webkit-scrollbar-thumb:hover {
            background: rgba(212, 175, 55, 0.6);
        }

        .filter-group {
            margin-bottom: 1.5rem;
        }

        .filter-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gold);
            margin-bottom: 0.5rem;
        }

        .filter-input,
        .filter-select {
            background-color: var(--black-lighter);
            border: 1px solid rgba(255,255,255,0.1);
            color: var(--white);
            border-radius: 4px;
            padding: 0.7rem 1rem;
            font-weight: 500;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            width: 100%;
        }

        .filter-input:focus,
        .filter-select:focus {
            border-color: var(--blue);
            box-shadow: 0 0 0 0.2rem rgba(37, 99, 235, 0.15);
            background-color: var(--black-lighter);
            color: var(--white);
            outline: none;
        }

        .filter-select option {
            background-color: var(--black-lighter);
            color: var(--white);
        }

        .filter-input::placeholder {
            color: var(--gray-600);
            font-style: italic;
        }

        .filter-input[type="date"]::-webkit-calendar-picker-indicator {
            filter: invert(1);
            cursor: pointer;
        }

        .price-range-inputs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
        }

        .bed-bath-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
        }

        .filter-section-divider {
            margin: 2rem 0 1.5rem 0;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255,255,255,0.06);
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray-400);
        }

        .filter-section-divider i {
            color: var(--blue-light);
        }

        .filter-footer {
            background: linear-gradient(135deg, var(--black) 0%, var(--black-lighter) 100%);
            padding: 1.2rem 1.5rem;
            border-top: 1px solid rgba(212, 175, 55, 0.15);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .filter-footer .btn {
            padding: 0.6rem 1.2rem;
            font-size: 0.85rem;
            font-weight: 600;
            border-radius: 4px;
            transition: all 0.3s ease;
        }

        .btn-clear-filters {
            background: rgba(220, 38, 38, 0.1);
            border: 1px solid rgba(220, 38, 38, 0.3);
            color: #ef4444;
        }

        .btn-clear-filters:hover {
            background: rgba(220, 38, 38, 0.2);
            border-color: rgba(220, 38, 38, 0.5);
            color: #ef4444;
        }

        .filter-result-count {
            font-size: 0.85rem;
            color: var(--gray-400);
        }

        .filter-result-count strong {
            color: var(--gold);
            font-weight: 700;
        }

        .btn-dark-outline {
            background: rgba(37, 99, 235, 0.1);
            border: 1px solid rgba(37, 99, 235, 0.3);
            color: var(--white);
            padding: 0.7rem 1rem;
            border-radius: 4px;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-dark-outline:hover {
            background: rgba(37, 99, 235, 0.2);
            border-color: rgba(37, 99, 235, 0.5);
            color: var(--white);
            transform: translateY(-1px);
        }

        .filter-sort {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-sort label {
            font-size: 0.75rem;
            color: var(--gray-400);
            margin: 0;
        }

        .filter-sort select {
            background: rgba(10, 10, 10, 0.8);
            border: 1px solid rgba(37, 99, 235, 0.2);
            border-radius: 3px;
            color: var(--white);
            padding: 0.4rem 0.6rem;
            font-size: 0.8rem;
        }

        @media (max-width: 768px) {
            .filter-sidebar-content {
                width: 100%;
                max-width: 100vw;
            }
        }
    </style>
</head>
<body>

<?php
// Prepare variables for navbar
$agent_username = $agent['username'] ?? 'Agent';
$agent_info = [
    'first_name' => $agent['first_name'] ?? '',
    'last_name' => $agent['last_name'] ?? '',
    'profile_picture_url' => $agent['profile_picture_url'] ?? ''
];

// Set this file as active in navbar
$active_page = 'agent_property.php';
include 'agent_navbar.php';
?>

<main class="property-content">
    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-inner">
            <div>
                <h1><i class="bi bi-buildings me-2" style="font-size:1.5rem;"></i>My Properties</h1>
                <p class="subtitle">Manage your property listings, track performance, and handle sales</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-dark-outline" id="openFiltersBtn">
                    <i class="fas fa-filter me-2"></i>Filters & Search
                    <span class="filter-badge" id="activeFilterBadge" style="display: none;">0</span>
                </button>
                <a href="#" class="btn-gold" data-bs-toggle="modal" data-bs-target="#addPropertyModal">
                    <i class="bi bi-plus-circle-fill"></i>
                    <span>Add New Property</span>
                </a>
            </div>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-icon green"><i class="bi bi-patch-check-fill"></i></div>
            <div class="kpi-label">Active Listings</div>
            <div class="kpi-value"><?php echo count($approved_properties); ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon amber"><i class="bi bi-hourglass-split"></i></div>
            <div class="kpi-label">Pending Review</div>
            <div class="kpi-value"><?php echo count($pending_properties); ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon red"><i class="bi bi-x-circle-fill"></i></div>
            <div class="kpi-label">Rejected</div>
            <div class="kpi-value"><?php echo count($rejected_properties); ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon cyan"><i class="bi bi-currency-exchange"></i></div>
            <div class="kpi-label">Pending Sold</div>
            <div class="kpi-value"><?php echo count($pending_sold_properties); ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon gold"><i class="bi bi-trophy-fill"></i></div>
            <div class="kpi-label">Sold</div>
            <div class="kpi-value"><?php echo count($sold_properties); ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon blue"><i class="bi bi-collection-fill"></i></div>
            <div class="kpi-label">Total</div>
            <div class="kpi-value"><?php echo count($all_properties); ?></div>
        </div>
    </div>

    <!-- Session Alert -->
    <?php if (isset($_SESSION['message'])): ?>
        <div class="alert <?php echo $_SESSION['message_type'] === 'success' ? 'alert-dark-custom' : ($_SESSION['message_type'] === 'danger' ? 'alert-danger-custom' : 'alert-warning-custom'); ?> alert-dismissible fade show mb-4" role="alert">
            <i class="bi <?php echo $_SESSION['message_type'] === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'; ?> me-2"></i>
            <?php echo $_SESSION['message']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
    <?php endif; ?>

    <!-- Tabbed Listings -->
    <div class="property-tabs">
        <ul class="nav nav-tabs" id="propertyStatusTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="approved-tab" data-bs-toggle="tab" data-bs-target="#approved-content" type="button" role="tab">
                    Active <span class="tab-count green"><?php echo count($approved_properties); ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending-content" type="button" role="tab">
                    Pending Review <span class="tab-count amber"><?php echo count($pending_properties); ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="rejected-tab" data-bs-toggle="tab" data-bs-target="#rejected-content" type="button" role="tab">
                    Rejected <span class="tab-count red"><?php echo count($rejected_properties); ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="pending-sold-tab" data-bs-toggle="tab" data-bs-target="#pending-sold-content" type="button" role="tab">
                    Pending Sold <span class="tab-count cyan"><?php echo count($pending_sold_properties); ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="sold-tab" data-bs-toggle="tab" data-bs-target="#sold-content" type="button" role="tab">
                    Sold <span class="tab-count dark"><?php echo count($sold_properties); ?></span>
                </button>
            </li>
        </ul>

        <!-- ===== FILTER SIDEBAR ===== -->
        <div class="filter-sidebar" id="filterSidebar">
            <div class="filter-sidebar-overlay" id="filterOverlay"></div>
            <div class="filter-sidebar-content">
                <div class="filter-header">
                    <h4>
                        <i class="fas fa-filter me-2"></i>Filters & Search
                    </h4>
                    <button class="btn-close-sidebar" id="closeFiltersBtn">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <div class="filter-body">
                    <!-- Search Bar -->
                    <div class="filter-group">
                        <label class="filter-label">
                            <i class="fas fa-search me-2"></i> Search
                        </label>
                        <input 
                            type="text" 
                            id="filterSearch" 
                            class="filter-input" 
                            placeholder="Search by address, city, or property type..."
                            autocomplete="off"
                        >
                    </div>

                    <!-- Property Type Filter -->
                    <div class="filter-group">
                        <label class="filter-label">
                            <i class="fas fa-home me-2"></i> Property Type
                        </label>
                        <select id="filterPropertyType" class="filter-select">
                            <option value="">All Types</option>
                            <?php foreach ($property_types as $pt): ?>
                                <option value="<?php echo htmlspecialchars($pt['type_name']); ?>"><?php echo htmlspecialchars($pt['type_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Status Filter -->
                    <div class="filter-group">
                        <label class="filter-label">
                            <i class="fas fa-tag me-2"></i> Listing Status
                        </label>
                        <select id="filterStatus" class="filter-select">
                            <option value="">All Statuses</option>
                            <option value="For Sale">For Sale</option>
                            <option value="For Rent">For Rent</option>
                        </select>
                    </div>

                    <!-- City Filter -->
                    <div class="filter-group">
                        <label class="filter-label">
                            <i class="fas fa-map-marker-alt me-2"></i> City/Location
                        </label>
                        <select id="filterCity" class="filter-select">
                            <option value="">All Locations</option>
                            <?php
                            $cities = array_unique(array_column($all_properties, 'City'));
                            sort($cities);
                            foreach ($cities as $city):
                                if (!empty($city)):
                            ?>
                            <option value="<?php echo htmlspecialchars($city); ?>"><?php echo htmlspecialchars($city); ?></option>
                            <?php
                                endif;
                            endforeach;
                            ?>
                        </select>
                    </div>

                    <!-- Price Range Section -->
                    <div class="filter-section-divider">
                        <span><i class="fas fa-dollar-sign me-2"></i>Price Range</span>
                    </div>

                    <div class="price-range-inputs">
                        <div class="filter-group">
                            <label class="filter-label">Min Price</label>
                            <input type="number" id="filterPriceMin" class="filter-input" placeholder="₱ Min" min="0" step="100000">
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Max Price</label>
                            <input type="number" id="filterPriceMax" class="filter-input" placeholder="₱ Max" min="0" step="100000">
                        </div>
                    </div>

                    <!-- Bedrooms & Bathrooms Section -->
                    <div class="filter-section-divider">
                        <span><i class="fas fa-bed me-2"></i>Bedrooms & Bathrooms</span>
                    </div>

                    <div class="bed-bath-grid">
                        <div class="filter-group">
                            <label class="filter-label">Bedrooms</label>
                            <select id="filterBedrooms" class="filter-select">
                                <option value="">Any</option>
                                <option value="1">1+</option>
                                <option value="2">2+</option>
                                <option value="3">3+</option>
                                <option value="4">4+</option>
                                <option value="5">5+</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Bathrooms</label>
                            <select id="filterBathrooms" class="filter-select">
                                <option value="">Any</option>
                                <option value="1">1+</option>
                                <option value="2">2+</option>
                                <option value="3">3+</option>
                                <option value="4">4+</option>
                            </select>
                        </div>
                    </div>

                    <!-- Date Range Section -->
                    <div class="filter-section-divider">
                        <span><i class="fas fa-calendar-range me-2"></i>Listing Date Range</span>
                    </div>

                    <div class="filter-group">
                        <label class="filter-label">
                            <i class="fas fa-calendar-day me-2"></i> From Date
                        </label>
                        <input type="date" id="filterDateFrom" class="filter-input">
                    </div>

                    <div class="filter-group">
                        <label class="filter-label">
                            <i class="fas fa-calendar-day me-2"></i> To Date
                        </label>
                        <input type="date" id="filterDateTo" class="filter-input">
                    </div>

                    <!-- Sort Section -->
                    <div class="filter-section-divider">
                        <span><i class="fas fa-sort me-2"></i>Sort By</span>
                    </div>

                    <div class="filter-group">
                        <label class="filter-label">
                            <i class="fas fa-sort-amount-down me-2"></i> Order
                        </label>
                        <select id="filterSort" class="filter-select">
                            <option value="date-desc">Newest First</option>
                            <option value="date-asc">Oldest First</option>
                            <option value="price-desc">Highest Price</option>
                            <option value="price-asc">Lowest Price</option>
                            <option value="views-desc">Most Views</option>
                            <option value="likes-desc">Most Likes</option>
                        </select>
                    </div>

                    <!-- Clear Button -->
                    <div class="filter-group">
                        <button type="button" class="btn btn-dark-outline w-100" id="resetFiltersBtn">
                            <i class="fas fa-times me-2"></i>Clear All Filters
                        </button>
                    </div>
                </div>

                <!-- Filter Footer -->
                <div class="filter-footer">
                    <div class="filter-result-count">
                        <strong id="visibleCount">0</strong> of <strong id="totalCount">0</strong> properties
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-content pt-4" id="propertyStatusTabsContent">
            <!-- Active Tab -->
            <div class="tab-pane fade show active" id="approved-content" role="tabpanel">
                <?php if (empty($approved_properties)): ?>
                    <div class="empty-state">
                        <i class="bi bi-buildings"></i>
                        <p>No active listings yet. Start by adding your first property.</p>
                    </div>
                <?php else: ?>
                    <div class="row g-4">
                        <?php foreach ($approved_properties as $property): ?>
                            <?php include 'property_card_template.php'; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Pending Tab -->
            <div class="tab-pane fade" id="pending-content" role="tabpanel">
                <?php if (empty($pending_properties)): ?>
                    <div class="empty-state">
                        <i class="bi bi-hourglass"></i>
                        <p>No properties pending review at this time.</p>
                    </div>
                <?php else: ?>
                    <div class="row g-4">
                        <?php foreach ($pending_properties as $property): ?>
                            <?php include 'property_card_template.php'; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Rejected Tab -->
            <div class="tab-pane fade" id="rejected-content" role="tabpanel">
                <?php if (empty($rejected_properties)): ?>
                    <div class="empty-state">
                        <i class="bi bi-x-octagon"></i>
                        <p>No rejected properties.</p>
                    </div>
                <?php else: ?>
                    <div class="row g-4">
                        <?php foreach ($rejected_properties as $property): ?>
                            <?php include 'property_card_template.php'; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Pending Sold Tab -->
            <div class="tab-pane fade" id="pending-sold-content" role="tabpanel">
                <?php if (empty($pending_sold_properties)): ?>
                    <div class="empty-state">
                        <i class="bi bi-shield-check"></i>
                        <p>No properties with pending sale verification.</p>
                    </div>
                <?php else: ?>
                    <div class="row g-4">
                        <?php foreach ($pending_sold_properties as $property): ?>
                            <?php include 'property_card_template.php'; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Sold Tab -->
            <div class="tab-pane fade" id="sold-content" role="tabpanel">
                <?php if (empty($sold_properties)): ?>
                    <div class="empty-state">
                        <i class="bi bi-trophy"></i>
                        <p>No sold properties yet. Your first sale will appear here.</p>
                    </div>
                <?php else: ?>
                    <div class="row g-4">
                        <?php foreach ($sold_properties as $property): ?>
                            <?php include 'property_card_template.php'; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<!-- ===== ADD PROPERTY MODAL ===== -->
<div class="modal fade modal-dark" id="addPropertyModal" tabindex="-1" aria-labelledby="addPropertyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addPropertyModalLabel"><i class="bi bi-house-add-fill"></i> Create New Listing</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="add_property_process.php" method="POST" enctype="multipart/form-data" id="addPropertyForm">
                <div class="modal-body">
                    <ul class="nav nav-tabs" id="addPropertyTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="step1-tab" data-bs-toggle="tab" data-bs-target="#step1-content" type="button" role="tab"><b>Step 1:</b> Basic Info</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="step2-tab" data-bs-toggle="tab" data-bs-target="#step2-content" type="button" role="tab"><b>Step 2:</b> Details</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="step3-tab" data-bs-toggle="tab" data-bs-target="#step3-content" type="button" role="tab"><b>Step 3:</b> Features</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="step4-tab" data-bs-toggle="tab" data-bs-target="#step4-content" type="button" role="tab"><b>Step 4:</b> Media</button>
                        </li>
                    </ul>

                    <div class="tab-content py-4" id="addPropertyTabsContent">

                        <!-- ========== STEP 1: BASIC INFORMATION ========== -->
                        <div class="tab-pane fade show active" id="step1-content" role="tabpanel">
                            <h5 class="form-section-title">Property Location</h5>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Street Address <span class="required">*</span></label>
                                    <input type="text" class="form-control" name="StreetAddress" placeholder="e.g. 123 Main Street" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">City <span class="required">*</span></label>
                                    <input type="text" class="form-control" name="City" required>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Province <span class="required">*</span></label>
                                    <input type="text" class="form-control" name="Province" required placeholder="e.g., Cebu">
                                </div>
                                <div class="col-md-1">
                                    <label class="form-label">ZIP <span class="required">*</span></label>
                                    <input type="text" class="form-control" name="ZIP" required pattern="\d{4}" maxlength="4" inputmode="numeric" title="Enter a 4-digit PH postal code" placeholder="ZIP">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Barangay <span class="optional">(Optional)</span></label>
                                    <input type="text" class="form-control" name="Barangay" placeholder="e.g., Brgy. San Jose">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Property Type <span class="required">*</span></label>
                                    <select class="form-select" name="PropertyType" id="modalPropertyType" required>
                                        <option selected disabled value="">Select Property Type</option>
                                        <?php foreach ($property_types as $pt): ?>
                                            <option value="<?php echo htmlspecialchars($pt['type_name']); ?>"><?php echo htmlspecialchars($pt['type_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Status <span class="required">*</span></label>
                                    <select class="form-select" name="Status" id="modalStatus" required>
                                        <option selected disabled value="">Select Status</option>
                                        <option value="For Sale">For Sale</option>
                                        <option value="For Rent">For Rent</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- ========== STEP 2: PROPERTY DETAILS ========== -->
                        <div class="tab-pane fade" id="step2-content" role="tabpanel">
                            <h5 class="form-section-title">Property Details</h5>
                            <div class="row g-3">
                                <div class="col-md-2">
                                    <label class="form-label">Year Built <span class="optional">(Optional)</span></label>
                                    <input type="number" class="form-control" name="YearBuilt" min="1800" max="<?php echo date('Y') + 5; ?>" placeholder="e.g., 2020">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Floors <span class="optional">(Optional)</span></label>
                                    <input type="number" class="form-control" name="NumberOfFloors" id="modalNumberOfFloors" min="1" max="10" value="1">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Bedrooms <span class="optional">(Optional)</span></label>
                                    <input type="number" class="form-control" name="Bedrooms" min="0" placeholder="e.g., 3">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Bathrooms <span class="optional">(Optional)</span></label>
                                    <input type="number" class="form-control" name="Bathrooms" step="0.5" min="0" placeholder="e.g., 2.5">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Listing Date <span class="required">*</span></label>
                                    <input type="date" class="form-control" name="ListingDate" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                            <div class="row g-3 mt-1">
                                <div class="col-md-3">
                                    <label class="form-label" id="modalSquareFootageLabel">Square Footage (ft²) <span class="required">*</span></label>
                                    <input type="number" class="form-control" name="SquareFootage" id="modalSquareFootage" min="1" placeholder="e.g., 2500" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" id="modalLotSizeLabel">Lot Size (acres) <span class="optional">(Optional)</span></label>
                                    <input type="number" class="form-control" name="LotSize" id="modalLotSize" step="0.01" min="0" placeholder="e.g., 0.25">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Parking Type <span class="optional">(Optional)</span></label>
                                    <input type="text" class="form-control" name="ParkingType" placeholder="e.g., Garage, Driveway">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" id="modalPriceLabel">Listing Price <span class="required">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">₱</span>
                                        <input type="number" class="form-control" name="ListingPrice" id="modalListingPrice" step="0.01" min="0.01" placeholder="e.g., 500000" required>
                                    </div>
                                </div>
                            </div>

                            <hr class="my-4">
                            <h5 class="form-section-title">MLS Information</h5>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Source (MLS Name) <span class="required">*</span></label>
                                    <input type="text" class="form-control" name="Source" placeholder="e.g., Regional MLS" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">MLS Number <span class="required">*</span></label>
                                    <input type="text" class="form-control" name="MLSNumber" placeholder="e.g., MLS123456" required>
                                </div>
                            </div>

                            <!-- Rental Details (shown when Status = For Rent) -->
                            <div class="rental-section-modal d-none" id="modalRentalSection">
                                <div class="rental-section-label"><i class="bi bi-key-fill"></i> Rental Details</div>
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Security Deposit</label>
                                        <div class="input-group">
                                            <span class="input-group-text">₱</span>
                                            <input type="number" class="form-control" name="SecurityDeposit" id="modalSecurityDeposit" step="0.01" min="0" placeholder="e.g., 50000">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Lease Term (months)</label>
                                        <select class="form-select" name="LeaseTermMonths" id="modalLeaseTermMonths">
                                            <option value="">Select Lease Term</option>
                                            <option value="6">6 months</option>
                                            <option value="12">12 months</option>
                                            <option value="18">18 months</option>
                                            <option value="24">24 months</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Furnishing</label>
                                        <select class="form-select" name="Furnishing" id="modalFurnishing">
                                            <option value="">Select Furnishing</option>
                                            <option value="Unfurnished">Unfurnished</option>
                                            <option value="Semi-Furnished">Semi-Furnished</option>
                                            <option value="Fully Furnished">Fully Furnished</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Available From</label>
                                        <input type="date" class="form-control" name="AvailableFrom" id="modalAvailableFrom" min="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ========== STEP 3: DESCRIPTION & AMENITIES ========== -->
                        <div class="tab-pane fade" id="step3-content" role="tabpanel">
                            <h5 class="form-section-title">Property Description</h5>
                            <div class="mb-4">
                                <label class="form-label">Listing Description <span class="required">*</span></label>
                                <textarea class="form-control" name="ListingDescription" rows="5" placeholder="Describe the property features, location benefits, and unique selling points..." required></textarea>
                                <div class="form-text">Provide a detailed description to attract potential buyers or renters.</div>
                            </div>
                            <hr class="my-4">
                            <h5 class="form-section-title">Amenities & Features</h5>
                            <div class="amenity-grid">
                                <?php if (!empty($amenities)): ?>
                                    <?php foreach ($amenities as $amenity): ?>
                                        <div class="form-check form-check-inline amenity-item">
                                            <input class="form-check-input" type="checkbox" name="amenities[]" value="<?php echo htmlspecialchars($amenity['amenity_id']); ?>" id="modal_amenity_<?php echo htmlspecialchars($amenity['amenity_id']); ?>">
                                            <label class="form-check-label" for="modal_amenity_<?php echo htmlspecialchars($amenity['amenity_id']); ?>"><?php echo htmlspecialchars($amenity['amenity_name']); ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p style="color: var(--gray-500);">No amenities available to select.</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- ========== STEP 4: MEDIA ========== -->
                        <div class="tab-pane fade" id="step4-content" role="tabpanel">
                            <h5 class="form-section-title"><i class="bi bi-images me-2" style="color: var(--gold);"></i>Featured Property Photos</h5>
                            <p class="form-text mb-3">
                                <i class="bi bi-info-circle me-1"></i>
                                Upload general property images (exterior, interior, backyard, frontyard, etc.). Maximum 10 images, 10MB per file.
                            </p>
                            <div class="featured-upload-area" id="featuredUploadArea" onclick="document.getElementById('property_photos').click()">
                                <i class="bi bi-cloud-upload"></i>
                                <div class="upload-text">Click to upload featured images</div>
                                <div class="upload-subtext">JPG, PNG, GIF supported &bull; Max 10 images</div>
                            </div>
                            <input type="file" id="property_photos" name="property_photos[]" class="d-none" accept="image/jpeg,image/png,image/gif" multiple required>
                            <div class="image-preview-grid mt-3" id="featuredPreviewContainer"></div>

                            <hr class="my-4">
                            <h5 class="form-section-title"><i class="bi bi-layers me-2" style="color: var(--blue-light);"></i>Floor Images</h5>
                            <p class="form-text mb-3">
                                <i class="bi bi-info-circle me-1"></i>
                                Upload images for each floor of the property. The number of floors is based on the "Floors" field in Step 2.
                            </p>
                            <div id="modalFloorImagesContainer"></div>
                            <div class="text-center mt-3" id="modalNoFloorsMessage" style="display:none;">
                                <i class="bi bi-building" style="font-size: 1.5rem; color: var(--gray-500);"></i>
                                <p style="color: var(--gray-500);" class="mt-2 mb-0">Set the number of floors in Step 2 to enable floor-specific image uploads.</p>
                            </div>
                        </div>

                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-outline-secondary btn-prev d-none">Previous</button>
                    <button type="button" class="btn btn-brand btn-next">Next</button>
                    <button type="submit" class="btn btn-success btn-submit d-none"><i class="bi bi-send-fill me-1"></i>Submit for Approval</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== MARK AS SOLD MODAL ===== -->
<div class="modal fade modal-dark" id="markSoldModal" tabindex="-1" aria-labelledby="markSoldModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="markSoldModalLabel"><i class="bi bi-check-circle-fill"></i> Mark Property as Sold</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="markSoldForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="alert alert-info mb-4">
                        <i class="bi bi-info-circle-fill me-2"></i>
                        <strong>Sale Verification Process:</strong> Submit documents for admin review. Your property will be marked as sold once verified.
                    </div>
                    
                    <input type="hidden" id="propertyId" name="property_id">
                    
                    <div class="mb-3">
                        <label class="form-label">Property</label>
                        <p id="propertyTitle" class="mb-0" style="color: var(--gold); font-weight: 600;"></p>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="salePrice" class="form-label">Final Sale Price <span style="color: #ef4444;">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" class="form-control" id="salePrice" name="sale_price" step="0.01" min="0" required>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="saleDate" class="form-label">Sale Date <span style="color: #ef4444;">*</span></label>
                            <input type="date" class="form-control" id="saleDate" name="sale_date" max="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="buyerName" class="form-label">Buyer Name <span style="color: #ef4444;">*</span></label>
                        <input type="text" class="form-control" id="buyerName" name="buyer_name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="buyerContact" class="form-label">Buyer Contact</label>
                        <input type="text" class="form-control" id="buyerContact" name="buyer_contact" placeholder="Phone number or email">
                    </div>
                    
                    <div class="mb-3">
                        <label for="saleDocuments" class="form-label">
                            Sale Documents <span style="color: #ef4444;">*</span>
                            <small class="d-block" style="color: var(--gray-500); font-weight:400;">Upload deed of sale, contracts, or other proof documents</small>
                        </label>
                        <input type="file" class="form-control" id="saleDocuments" name="sale_documents[]" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required>
                        <div class="form-text">Allowed formats: PDF, Images (JPG, PNG), Word documents. Max 10MB per file.</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="additionalNotes" class="form-label">Additional Notes</label>
                        <textarea class="form-control" id="additionalNotes" name="additional_notes" rows="3" placeholder="Any additional information about the sale..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-upload me-1"></i>Submit for Verification
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'logout_agent_modal.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // ===== ADD PROPERTY MODAL =====
    const addPropertyModal = document.getElementById('addPropertyModal');
    if (addPropertyModal) {
        const tabTriggers = addPropertyModal.querySelectorAll('#addPropertyTabs button');
        const tabPanes = addPropertyModal.querySelectorAll('.tab-pane');
        const btnPrev = addPropertyModal.querySelector('.btn-prev');
        const btnNext = addPropertyModal.querySelector('.btn-next');
        const btnSubmit = addPropertyModal.querySelector('.btn-submit');
        let currentTab = 0;

        // --- Status / Rental Toggle ---
        const statusSelect = document.getElementById('modalStatus');
        const rentalSection = document.getElementById('modalRentalSection');
        const priceLabel = document.getElementById('modalPriceLabel');
        const priceInput = document.getElementById('modalListingPrice');
        const sqftInput = document.getElementById('modalSquareFootage');
        const lotInput = document.getElementById('modalLotSize');
        const sqftLabel = document.getElementById('modalSquareFootageLabel');
        const lotLabel = document.getElementById('modalLotSizeLabel');
        const rentalFields = [
            document.getElementById('modalSecurityDeposit'),
            document.getElementById('modalLeaseTermMonths'),
            document.getElementById('modalFurnishing'),
            document.getElementById('modalAvailableFrom')
        ];

        function toggleRentalFields() {
            const isForRent = statusSelect && statusSelect.value === 'For Rent';
            if (isForRent) {
                rentalSection.classList.remove('d-none');
                if (priceLabel) priceLabel.innerHTML = 'Monthly Rent <span class="required">*</span>';
                if (priceInput) priceInput.placeholder = 'e.g., 25000';
                rentalFields.forEach(el => { if (el) el.setAttribute('required', 'required'); });
            } else {
                rentalSection.classList.add('d-none');
                if (priceLabel) priceLabel.innerHTML = 'Listing Price <span class="required">*</span>';
                if (priceInput) priceInput.placeholder = 'e.g., 500000';
                rentalFields.forEach(el => { if (el) el.removeAttribute('required'); });
            }
            // SquareFootage is always required; LotSize is always optional
            if (sqftLabel) sqftLabel.innerHTML = 'Square Footage (ft²) <span class="required">*</span>';
            if (lotLabel) lotLabel.innerHTML = 'Lot Size (acres) <span class="optional">(Optional)</span>';
        }
        if (statusSelect) {
            statusSelect.addEventListener('change', toggleRentalFields);
            toggleRentalFields();
        }

        // --- Floor Images Management ---
        const floorsInput = document.getElementById('modalNumberOfFloors');
        const floorContainer = document.getElementById('modalFloorImagesContainer');
        const noFloorsMsg = document.getElementById('modalNoFloorsMessage');
        let floorFileInputs = {};

        function getFloorLabel(n) {
            const labels = {1:'First Floor',2:'Second Floor',3:'Third Floor',4:'Fourth Floor',5:'Fifth Floor',6:'Sixth Floor',7:'Seventh Floor',8:'Eighth Floor',9:'Ninth Floor',10:'Tenth Floor'};
            return labels[n] || ('Floor ' + n);
        }

        function generateFloorSections(count) {
            floorContainer.innerHTML = '';
            floorFileInputs = {};
            if (count < 1) { noFloorsMsg.style.display = 'block'; return; }
            noFloorsMsg.style.display = 'none';
            for (let i = 1; i <= count; i++) {
                const card = document.createElement('div');
                card.className = 'floor-upload-card';
                card.innerHTML = `
                    <div class="floor-header">
                        <div class="floor-title"><i class="bi bi-building"></i>${getFloorLabel(i)}</div>
                        <div class="floor-badge">Floor ${i}</div>
                    </div>
                    <div class="floor-upload-area" id="floorUploadArea_${i}" onclick="document.getElementById('floor_images_${i}').click()">
                        <i class="bi bi-cloud-arrow-up floor-upload-icon"></i>
                        <div class="floor-upload-text">Click to upload images for ${getFloorLabel(i)}</div>
                        <div class="floor-upload-subtext">Max 10 images per floor &bull; JPG, PNG, GIF (10MB each)</div>
                    </div>
                    <input type="file" id="floor_images_${i}" name="floor_images_${i}[]" class="d-none" accept="image/jpeg,image/png,image/gif" multiple>
                    <div class="floor-preview-grid" id="floorPreviewGrid_${i}"></div>
                `;
                floorContainer.appendChild(card);
                const input = document.getElementById('floor_images_' + i);
                floorFileInputs[i] = input;
                input.addEventListener('change', function(e) { handleFloorUpload(i, e.target.files); });
            }
        }

        function handleFloorUpload(floor, files) {
            const area = document.getElementById('floorUploadArea_' + floor);
            const grid = document.getElementById('floorPreviewGrid_' + floor);
            if (!files || files.length === 0) return;
            if (files.length > 10) alert('Maximum 10 images per floor. Only the first 10 will be used.');
            area.classList.add('has-files');
            grid.innerHTML = '';
            Array.from(files).slice(0, 10).forEach((file, idx) => {
                if (file.size > 25 * 1024 * 1024) { alert(file.name + ' exceeds 25MB.'); return; }
                const reader = new FileReader();
                reader.onload = function(e) {
                    const item = document.createElement('div');
                    item.className = 'floor-preview-item';
                    item.innerHTML = `<img src="${e.target.result}" class="floor-preview-image" alt=""><button type="button" class="remove-floor-image" onclick="removeFloorImage(${floor},${idx})" title="Remove"><i class="bi bi-x"></i></button>`;
                    grid.appendChild(item);
                };
                reader.readAsDataURL(file);
            });
        }

        window.removeFloorImage = function(floor, idx) {
            const input = floorFileInputs[floor];
            if (!input) return;
            const dt = new DataTransfer();
            Array.from(input.files).forEach((f, i) => { if (i !== idx) dt.items.add(f); });
            input.files = dt.files;
            handleFloorUpload(floor, input.files);
            if (dt.files.length === 0) {
                document.getElementById('floorUploadArea_' + floor).classList.remove('has-files');
            }
        };

        if (floorsInput) {
            floorsInput.addEventListener('input', function() {
                const c = parseInt(this.value) || 0;
                if (c >= 1 && c <= 10) generateFloorSections(c);
                else if (c > 10) { this.value = 10; generateFloorSections(10); }
                else generateFloorSections(0);
            });
            generateFloorSections(parseInt(floorsInput.value) || 1);
        }

        // --- Featured Photos Preview ---
        const featuredInput = document.getElementById('property_photos');
        const featuredPreview = document.getElementById('featuredPreviewContainer');
        const featuredArea = document.getElementById('featuredUploadArea');
        featuredInput.addEventListener('change', function(e) {
            featuredPreview.innerHTML = '';
            if (e.target.files && e.target.files.length > 0) {
                featuredArea.style.display = 'none';
                Array.from(e.target.files).forEach(file => {
                    const reader = new FileReader();
                    reader.onload = function(ev) {
                        const img = document.createElement('img');
                        img.src = ev.target.result;
                        img.classList.add('preview-image');
                        featuredPreview.appendChild(img);
                    };
                    reader.readAsDataURL(file);
                });
            } else {
                featuredArea.style.display = '';
            }
        });

        // --- Step Validation ---
        function validateStep(stepIndex) {
            let isValid = true;
            const pane = tabPanes[stepIndex];
            // Only validate visible required inputs (skip hidden rental fields)
            pane.querySelectorAll('[required]').forEach(input => {
                input.classList.remove('is-invalid');
                // Skip if parent section is hidden
                const rentalParent = input.closest('.rental-section-modal');
                if (rentalParent && rentalParent.classList.contains('d-none')) return;
                if (!input.value || !input.value.trim()) {
                    isValid = false;
                    input.classList.add('is-invalid');
                }
            });
            return isValid;
        }

        function updateButtons() {
            btnPrev.classList.toggle('d-none', currentTab === 0);
            btnNext.classList.toggle('d-none', currentTab === tabTriggers.length - 1);
            btnSubmit.classList.toggle('d-none', currentTab !== tabTriggers.length - 1);
        }

        btnNext.addEventListener('click', function () {
            if (!validateStep(currentTab)) return;
            if (currentTab < tabTriggers.length - 1) {
                currentTab++;
                new bootstrap.Tab(tabTriggers[currentTab]).show();
            }
        });

        btnPrev.addEventListener('click', function () {
            if (currentTab > 0) {
                currentTab--;
                new bootstrap.Tab(tabTriggers[currentTab]).show();
            }
        });

        tabTriggers.forEach((tab, index) => {
            tab.addEventListener('shown.bs.tab', function () {
                currentTab = index;
                updateButtons();
            });
        });

        // --- Form Submit Validation ---
        const addForm = document.getElementById('addPropertyForm');
        addForm.addEventListener('submit', function(e) {
            // Remove previous alerts
            addPropertyModal.querySelectorAll('.alert-modal-error').forEach(a => a.remove());

            const errors = [];

            // Check featured photos
            if (!featuredInput.files || featuredInput.files.length === 0) {
                errors.push('Please upload at least one featured property photo.');
            }

            // Check rental fields client-side
            const isRental = statusSelect && statusSelect.value === 'For Rent';
            if (isRental) {
                const dep = document.getElementById('modalSecurityDeposit');
                const lease = document.getElementById('modalLeaseTermMonths');
                const furn = document.getElementById('modalFurnishing');
                const avail = document.getElementById('modalAvailableFrom');
                const rent = document.getElementById('modalListingPrice');

                if (!dep.value || parseFloat(dep.value) < 0) errors.push('Security Deposit must be 0 or more.');
                if (!lease.value) errors.push('Lease Term is required for rentals.');
                if (!furn.value) errors.push('Furnishing is required for rentals.');
                if (!avail.value) errors.push('Available From date is required for rentals.');

                // Deposit cap
                const rentVal = parseFloat(rent.value) || 0;
                const depVal = parseFloat(dep.value) || 0;
                if (rentVal > 0 && depVal > rentVal * 12) {
                    errors.push('Security Deposit cannot exceed 12 months of rent (₱' + (rentVal * 12).toFixed(2) + ').');
                }
            }

            // Check floor images
            const floorCount = parseInt(floorsInput.value) || 0;
            if (floorCount > 0) {
                const missing = [];
                for (let i = 1; i <= floorCount; i++) {
                    const fi = document.getElementById('floor_images_' + i);
                    if (!fi || !fi.files || fi.files.length === 0) missing.push(getFloorLabel(i));
                }
                if (missing.length > 0) errors.push('Please upload at least one image for: ' + missing.join(', ') + '.');
            }

            if (errors.length > 0) {
                e.preventDefault();
                const alert = document.createElement('div');
                alert.className = 'alert alert-danger-custom alert-dismissible fade show alert-modal-error';
                alert.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-2"></i>' + errors.map(x => '&bull; ' + x).join('<br>') + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
                const body = addPropertyModal.querySelector('.modal-body');
                body.insertBefore(alert, body.firstChild);
                alert.scrollIntoView({behavior: 'smooth', block: 'center'});
            }
        });

        // --- Modal Reset ---
        addPropertyModal.addEventListener('hidden.bs.modal', function () {
            addForm.reset();
            featuredPreview.innerHTML = '';
            featuredArea.style.display = '';
            generateFloorSections(1);
            addPropertyModal.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
            addPropertyModal.querySelectorAll('.alert-modal-error').forEach(a => a.remove());
            toggleRentalFields();
            currentTab = 0;
            new bootstrap.Tab(tabTriggers[0]).show();
        });

        updateButtons();
    }

    // ===== MARK AS SOLD MODAL =====
    const markSoldModal = document.getElementById('markSoldModal');
    if (markSoldModal) {
        markSoldModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const propertyId = button.getAttribute('data-property-id');
            const propertyTitle = button.getAttribute('data-property-title');
            document.getElementById('propertyId').value = propertyId;
            document.getElementById('propertyTitle').textContent = propertyTitle;
        });

        const markSoldForm = document.getElementById('markSoldForm');
        markSoldForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-arrow-repeat spin me-1"></i>Submitting...';
            
            const formData = new FormData(this);
            
            fetch('mark_as_sold_process.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const alertDiv = document.createElement('div');
                    alertDiv.className = 'alert alert-dark-custom alert-dismissible fade show';
                    alertDiv.innerHTML = `
                        <i class="bi bi-check-circle-fill me-2"></i>
                        ${data.message}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    `;
                    const container = document.querySelector('main.property-content') || document.body;
                    container.insertBefore(alertDiv, container.firstChild);
                    
                    bootstrap.Modal.getInstance(markSoldModal).hide();
                    markSoldForm.reset();
                    
                    setTimeout(() => window.location.reload(), 2000);
                } else {
                    const alertDiv = document.createElement('div');
                    alertDiv.className = 'alert alert-danger-custom alert-dismissible fade show';
                    alertDiv.innerHTML = `
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        ${data.message}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    `;
                    const modalBody = markSoldModal.querySelector('.modal-body');
                    modalBody.insertBefore(alertDiv, modalBody.firstChild);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-danger-custom alert-dismissible fade show';
                alertDiv.innerHTML = `
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    An error occurred while submitting. Please try again.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                `;
                const modalBody = markSoldModal.querySelector('.modal-body');
                modalBody.insertBefore(alertDiv, modalBody.firstChild);
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        });

        markSoldModal.addEventListener('hidden.bs.modal', function () {
            const alerts = this.querySelectorAll('.alert');
            alerts.forEach(alert => alert.remove());
            markSoldForm.reset();
        });
    }

    // ===== PROPERTY FILTER SIDEBAR SYSTEM =====
    const filterSidebar = document.getElementById('filterSidebar');
    const filterOverlay = document.getElementById('filterOverlay');
    const openFiltersBtn = document.getElementById('openFiltersBtn');
    const closeFiltersBtn = document.getElementById('closeFiltersBtn');
    const resetFiltersBtn = document.getElementById('resetFiltersBtn');
    const filterSearch = document.getElementById('filterSearch');
    
    // All filter inputs
    const filterPropertyType = document.getElementById('filterPropertyType');
    const filterStatus = document.getElementById('filterStatus');
    const filterCity = document.getElementById('filterCity');
    const filterPriceMin = document.getElementById('filterPriceMin');
    const filterPriceMax = document.getElementById('filterPriceMax');
    const filterBedrooms = document.getElementById('filterBedrooms');
    const filterBathrooms = document.getElementById('filterBathrooms');
    const filterDateFrom = document.getElementById('filterDateFrom');
    const filterDateTo = document.getElementById('filterDateTo');
    const filterSort = document.getElementById('filterSort');
    
    // Count display
    const visibleCountEl = document.getElementById('visibleCount');
    const totalCountEl = document.getElementById('totalCount');

    // Open filter sidebar
    if (openFiltersBtn) {
        openFiltersBtn.addEventListener('click', function() {
            filterSidebar.classList.add('active');
            document.body.style.overflow = 'hidden';
        });
    }

    // Close filter sidebar
    function closeFilterSidebar() {
        filterSidebar.classList.remove('active');
        document.body.style.overflow = '';
    }

    if (closeFiltersBtn) {
        closeFiltersBtn.addEventListener('click', closeFilterSidebar);
    }

    if (filterOverlay) {
        filterOverlay.addEventListener('click', closeFilterSidebar);
    }

    // Close sidebar on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && filterSidebar.classList.contains('active')) {
            closeFilterSidebar();
        }
    });

    // Reset all filters
    if (resetFiltersBtn) {
        resetFiltersBtn.addEventListener('click', function() {
            filterSearch.value = '';
            filterPropertyType.value = '';
            filterStatus.value = '';
            filterCity.value = '';
            filterPriceMin.value = '';
            filterPriceMax.value = '';
            filterBedrooms.value = '';
            filterBathrooms.value = '';
            filterDateFrom.value = '';
            filterDateTo.value = '';
            filterSort.value = 'date-desc';
            applyFilters();
        });
    }

    // Apply filters function
    function applyFilters() {
        const activeTab = document.querySelector('.tab-pane.active');
        if (!activeTab) return;

        const propertyCards = activeTab.querySelectorAll('.col-md-6');
        let visibleCount = 0;
        const totalCount = propertyCards.length;

        const filters = {
            search: filterSearch.value.toLowerCase().trim(),
            propertyType: filterPropertyType.value.toLowerCase(),
            status: filterStatus.value.toLowerCase(),
            city: filterCity.value.toLowerCase(),
            priceMin: parseFloat(filterPriceMin.value) || 0,
            priceMax: parseFloat(filterPriceMax.value) || Infinity,
            bedrooms: parseInt(filterBedrooms.value) || 0,
            bathrooms: parseInt(filterBathrooms.value) || 0,
            dateFrom: filterDateFrom.value ? new Date(filterDateFrom.value) : null,
            dateTo: filterDateTo.value ? new Date(filterDateTo.value) : null,
            sort: filterSort.value
        };

        // Count active filters for badge
        let activeFilterCount = 0;
        if (filters.search) activeFilterCount++;
        if (filters.propertyType) activeFilterCount++;
        if (filters.status) activeFilterCount++;
        if (filters.city) activeFilterCount++;
        if (filterPriceMin.value) activeFilterCount++;
        if (filterPriceMax.value) activeFilterCount++;
        if (filters.bedrooms > 0) activeFilterCount++;
        if (filters.bathrooms > 0) activeFilterCount++;
        if (filters.dateFrom) activeFilterCount++;
        if (filters.dateTo) activeFilterCount++;

        // Update filter badge
        const filterBadge = document.getElementById('activeFilterBadge');
        if (filterBadge) {
            if (activeFilterCount > 0) {
                filterBadge.textContent = activeFilterCount;
                filterBadge.style.display = 'inline-flex';
            } else {
                filterBadge.style.display = 'none';
            }
        }

        // Convert NodeList to Array for sorting
        const cardsArray = Array.from(propertyCards);
        
        // Filter cards
        cardsArray.forEach(card => {
            const propertyType = (card.dataset.propertyType || '').toLowerCase();
            const status = (card.dataset.status || '').toLowerCase();
            const city = (card.dataset.city || '').toLowerCase();
            const price = parseFloat(card.dataset.price) || 0;
            const bedrooms = parseInt(card.dataset.bedrooms) || 0;
            const bathrooms = parseFloat(card.dataset.bathrooms) || 0;
            const listingDate = card.dataset.listingDate ? new Date(card.dataset.listingDate) : null;

            // Get text content for search
            const searchableText = (card.textContent || '').toLowerCase();

            let showCard = true;

            // Apply search filter
            if (filters.search && !searchableText.includes(filters.search)) showCard = false;

            // Apply other filters
            if (filters.propertyType && !propertyType.includes(filters.propertyType)) showCard = false;
            if (filters.status && !status.includes(filters.status)) showCard = false;
            if (filters.city && !city.includes(filters.city)) showCard = false;
            if (price < filters.priceMin || price > filters.priceMax) showCard = false;
            if (filters.bedrooms > 0 && bedrooms < filters.bedrooms) showCard = false;
            if (filters.bathrooms > 0 && bathrooms < filters.bathrooms) showCard = false;
            if (filters.dateFrom && listingDate && listingDate < filters.dateFrom) showCard = false;
            if (filters.dateTo && listingDate && listingDate > filters.dateTo) showCard = false;

            card.style.display = showCard ? '' : 'none';
            if (showCard) visibleCount++;
        });

        // Sort visible cards
        const visibleCards = cardsArray.filter(card => card.style.display !== 'none');
        
        visibleCards.sort((a, b) => {
            const sortBy = filters.sort;
            
            if (sortBy === 'date-desc') {
                const dateA = new Date(a.dataset.listingDate || 0);
                const dateB = new Date(b.dataset.listingDate || 0);
                return dateB - dateA;
            } else if (sortBy === 'date-asc') {
                const dateA = new Date(a.dataset.listingDate || 0);
                const dateB = new Date(b.dataset.listingDate || 0);
                return dateA - dateB;
            } else if (sortBy === 'price-desc') {
                return parseFloat(b.dataset.price || 0) - parseFloat(a.dataset.price || 0);
            } else if (sortBy === 'price-asc') {
                return parseFloat(a.dataset.price || 0) - parseFloat(b.dataset.price || 0);
            } else if (sortBy === 'views-desc') {
                return parseInt(b.dataset.views || 0) - parseInt(a.dataset.views || 0);
            } else if (sortBy === 'likes-desc') {
                return parseInt(b.dataset.likes || 0) - parseInt(a.dataset.likes || 0);
            }
            return 0;
        });

        // Re-append cards in sorted order
        const container = activeTab.querySelector('.row');
        if (container) {
            visibleCards.forEach(card => container.appendChild(card));
        }

        // Update counts
        if (visibleCountEl) visibleCountEl.textContent = visibleCount;
        if (totalCountEl) totalCountEl.textContent = totalCount;

        // Show/hide empty state
        let emptyState = activeTab.querySelector('.empty-state');
        const rowContainer = activeTab.querySelector('.row');
        
        if (visibleCount === 0 && totalCount > 0) {
            // Properties exist but all are filtered out
            if (!emptyState) {
                // Create temporary empty state for filter results
                emptyState = document.createElement('div');
                emptyState.className = 'empty-state';
                emptyState.innerHTML = '<i class="bi bi-funnel"></i><p>No properties match your filters. Try adjusting your search criteria.</p>';
                if (rowContainer) {
                    rowContainer.parentNode.insertBefore(emptyState, rowContainer);
                }
            } else {
                emptyState.style.display = 'block';
                const messageEl = emptyState.querySelector('p');
                if (messageEl && rowContainer) {
                    messageEl.textContent = 'No properties match your filters. Try adjusting your search criteria.';
                }
            }
            if (rowContainer) rowContainer.style.display = 'none';
        } else {
            // Has visible properties or no properties at all
            if (emptyState && rowContainer) {
                emptyState.style.display = 'none';
            }
            if (rowContainer) rowContainer.style.display = '';
        }
    }

    // Attach event listeners to all filter inputs
    [filterSearch, filterPropertyType, filterStatus, filterCity, filterPriceMin, filterPriceMax,
     filterBedrooms, filterBathrooms, filterDateFrom, filterDateTo, filterSort].forEach(input => {
        if (input) {
            input.addEventListener('change', applyFilters);
            if (input.type === 'number' || input.type === 'date' || input.type === 'text') {
                input.addEventListener('input', debounce(applyFilters, 300));
            }
        }
    });

    // Debounce function for input fields
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    // Apply filters when tab changes
    document.querySelectorAll('[data-bs-toggle=\"tab\"]').forEach(tab => {
        tab.addEventListener('shown.bs.tab', function() {
            setTimeout(applyFilters, 100);
        });
    });

    // Initial filter application
    setTimeout(() => {
        applyFilters();
    }, 300);
});
</script>
</body>
</html>
