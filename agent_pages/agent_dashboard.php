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

// Fetch agent information
$agent_info_query = "SELECT ai.*, a.first_name, a.last_name, a.email, a.phone_number, a.date_registered
                     FROM agent_information ai 
                     JOIN accounts a ON ai.account_id = a.account_id 
                     WHERE ai.account_id = ?";
$stmt = $conn->prepare($agent_info_query);
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$agent_info = $stmt->get_result()->fetch_assoc();
$stmt->close();

// -- Property Statistics --
$stats_query = "SELECT 
    COUNT(*) as total_listings,
    COUNT(CASE WHEN approval_status = 'approved' AND Status NOT IN ('Sold','Pending Sold') THEN 1 END) as active_listings,
    COUNT(CASE WHEN approval_status = 'pending' THEN 1 END) as pending_approval,
    COUNT(CASE WHEN Status = 'For Sale' AND approval_status = 'approved' THEN 1 END) as for_sale,
    COUNT(CASE WHEN Status = 'For Rent' AND approval_status = 'approved' THEN 1 END) as for_rent,
    COUNT(CASE WHEN Status = 'Sold' THEN 1 END) as total_sold,
    COALESCE(SUM(CASE WHEN approval_status = 'approved' THEN ViewsCount ELSE 0 END), 0) as total_views,
    COALESCE(SUM(CASE WHEN approval_status = 'approved' THEN Likes ELSE 0 END), 0) as total_likes,
    COALESCE(AVG(CASE WHEN approval_status = 'approved' AND Status NOT IN ('Sold','Pending Sold') THEN ListingPrice END), 0) as avg_listing_price
    FROM property 
    WHERE property_ID IN (
        SELECT property_id FROM property_log WHERE account_id = ? AND action = 'CREATED'
    )";
$stmt = $conn->prepare($stats_query);
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// -- Tour Request Statistics --
$tour_stats_query = "SELECT 
    COUNT(*) as total_tours,
    COUNT(CASE WHEN request_status = 'Pending' THEN 1 END) as pending_tours,
    COUNT(CASE WHEN request_status = 'Confirmed' THEN 1 END) as confirmed_tours,
    COUNT(CASE WHEN request_status = 'Completed' THEN 1 END) as completed_tours,
    COUNT(CASE WHEN request_status = 'Cancelled' THEN 1 END) as cancelled_tours,
    COUNT(CASE WHEN request_status = 'Rejected' THEN 1 END) as rejected_tours
    FROM tour_requests 
    WHERE agent_account_id = ?";
$stmt = $conn->prepare($tour_stats_query);
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$tour_stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// -- Commission Statistics --
$commission_query = "SELECT 
    COALESCE(SUM(commission_amount), 0) as total_commission,
    COALESCE(SUM(CASE WHEN status = 'paid' THEN commission_amount ELSE 0 END), 0) as paid_commission,
    COALESCE(SUM(CASE WHEN status IN ('pending','calculated') THEN commission_amount ELSE 0 END), 0) as unpaid_commission,
    COUNT(*) as commission_count
    FROM agent_commissions 
    WHERE agent_id = ?";
$stmt = $conn->prepare($commission_query);
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$commissions = $stmt->get_result()->fetch_assoc();
$stmt->close();

// -- Total Sales Volume (from finalized sales) --
$sales_volume_query = "SELECT 
    COALESCE(SUM(fs.final_sale_price), 0) as total_sales_volume,
    COUNT(fs.sale_id) as finalized_count
    FROM finalized_sales fs
    WHERE fs.agent_id = ?";
$stmt = $conn->prepare($sales_volume_query);
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$sales_volume = $stmt->get_result()->fetch_assoc();
$stmt->close();

// -- Upcoming Tours (Confirmed, future dates) --
$upcoming_tours_query = "SELECT tr.*, p.StreetAddress, p.City, p.PropertyType,
    (SELECT PhotoURL FROM property_images WHERE property_ID = p.property_ID ORDER BY SortOrder ASC LIMIT 1) as image
    FROM tour_requests tr
    JOIN property p ON tr.property_id = p.property_ID
    WHERE tr.agent_account_id = ? 
    AND tr.request_status = 'Confirmed' 
    AND tr.tour_date >= CURDATE()
    ORDER BY tr.tour_date ASC, tr.tour_time ASC
    LIMIT 5";
$stmt = $conn->prepare($upcoming_tours_query);
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$upcoming_tours = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// -- Pending Tour Requests --
$pending_tours_query = "SELECT tr.*, p.StreetAddress, p.City, p.PropertyType
    FROM tour_requests tr
    JOIN property p ON tr.property_id = p.property_ID
    WHERE tr.agent_account_id = ? AND tr.request_status = 'Pending'
    ORDER BY tr.requested_at DESC
    LIMIT 5";
$stmt = $conn->prepare($pending_tours_query);
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$pending_tours = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// -- Recent Properties --
$recent_properties_query = "SELECT p.*, 
    (SELECT PhotoURL FROM property_images WHERE property_ID = p.property_ID ORDER BY SortOrder ASC LIMIT 1) as image 
    FROM property p 
    WHERE p.property_ID IN (
        SELECT property_id FROM property_log WHERE account_id = ? AND action = 'CREATED'
    ) 
    AND p.approval_status = 'approved' AND p.Status NOT IN ('Sold','Pending Sold')
    ORDER BY p.ListingDate DESC 
    LIMIT 4";
$stmt = $conn->prepare($recent_properties_query);
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$recent_properties = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// -- Top Performing Properties (most views) --
$top_properties_query = "SELECT p.property_ID, p.StreetAddress, p.City, p.PropertyType, p.ListingPrice, 
    p.ViewsCount, p.Likes, p.Status, p.Bedrooms, p.Bathrooms, p.SquareFootage,
    (SELECT PhotoURL FROM property_images WHERE property_ID = p.property_ID ORDER BY SortOrder ASC LIMIT 1) as image
    FROM property p 
    WHERE p.property_ID IN (
        SELECT property_id FROM property_log WHERE account_id = ? AND action = 'CREATED'
    ) AND p.approval_status = 'approved'
    ORDER BY p.ViewsCount DESC
    LIMIT 3";
$stmt = $conn->prepare($top_properties_query);
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$top_properties = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// -- Recent Activity Logs --
$activity_query = "SELECT pl.*, p.StreetAddress, p.City 
    FROM property_log pl 
    JOIN property p ON pl.property_id = p.property_ID 
    WHERE pl.account_id = ? 
    ORDER BY pl.log_timestamp DESC 
    LIMIT 8";
$stmt = $conn->prepare($activity_query);
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$recent_activity = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// -- Pending Sale Verifications --
$pending_sales_query = "SELECT sv.*, p.StreetAddress, p.City, p.PropertyType
    FROM sale_verifications sv
    JOIN property p ON sv.property_id = p.property_ID
    WHERE sv.agent_id = ? AND sv.status = 'Pending'
    ORDER BY sv.submitted_at DESC
    LIMIT 5";
$stmt = $conn->prepare($pending_sales_query);
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$pending_sales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// -- Today's Confirmed Tours Count --
$today_tours_query = "SELECT COUNT(*) as today_count
    FROM tour_requests 
    WHERE agent_account_id = ? 
    AND request_status = 'Confirmed' 
    AND tour_date = CURDATE()";
$stmt = $conn->prepare($today_tours_query);
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$today_tours = $stmt->get_result()->fetch_assoc();
$stmt->close();

$conn->close();

// Helper: greeting based on time of day
$hour = (int) date('G');
if ($hour < 12) $greeting = 'Good Morning';
elseif ($hour < 17) $greeting = 'Good Afternoon';
else $greeting = 'Good Evening';

$agent_name = htmlspecialchars($agent_info['first_name'] ?? $agent_username);
$member_since = isset($agent_info['date_registered']) ? date('M Y', strtotime($agent_info['date_registered'])) : 'N/A';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agent Dashboard - HomeEstate Realty</title>
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
        .dashboard-content {
            padding: 2rem;
            max-width: 1440px;
            margin: 0 auto;
        }

        /* ===== WELCOME HERO ===== */
        .welcome-hero {
            background: linear-gradient(135deg, var(--black) 0%, var(--black-lighter) 100%);
            border: 1px solid var(--card-border);
            border-radius: 4px;
            padding: 2.5rem 3rem;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }

        .welcome-hero::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background:
                radial-gradient(ellipse at top right, rgba(37, 99, 235, 0.06) 0%, transparent 50%),
                radial-gradient(ellipse at bottom left, rgba(212, 175, 55, 0.04) 0%, transparent 50%);
            pointer-events: none;
        }

        .welcome-hero::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent);
        }

        .welcome-hero-inner {
            position: relative;
            z-index: 2;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1.5rem;
        }

        .welcome-text h1 {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 0.25rem;
            background: linear-gradient(135deg, var(--white) 0%, var(--gray-100) 50%, var(--gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .welcome-text .subtitle {
            color: var(--gray-400);
            font-size: 1rem;
            font-weight: 400;
        }

        .welcome-text .date-display {
            color: var(--gray-500);
            font-size: 0.85rem;
            margin-top: 0.25rem;
        }

        .welcome-brand {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .welcome-brand img {
            height: 50px;
            width: auto;
            filter: brightness(1.1) saturate(1.2);
        }

        .welcome-brand .brand-info {
            text-align: right;
        }

        .welcome-brand .brand-name {
            font-size: 1.2rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 50%, var(--gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .welcome-brand .brand-tagline {
            font-size: 0.7rem;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }

        /* ===== KPI STAT CARDS ===== */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.25rem;
            margin-bottom: 2rem;
        }

        .kpi-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 4px;
            padding: 1.5rem;
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
            transform: translateY(-4px);
        }

        .kpi-card:hover::before { opacity: 1; }

        .kpi-card .kpi-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .kpi-card .kpi-icon {
            width: 48px;
            height: 48px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
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

        .kpi-card .kpi-label {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--gray-400);
        }

        .kpi-card .kpi-value {
            font-size: 2rem;
            font-weight: 800;
            color: var(--white);
            line-height: 1.2;
        }

        .kpi-card .kpi-sub {
            font-size: 0.8rem;
            color: var(--gray-500);
            margin-top: 0.25rem;
        }

        /* ===== SECTION PANEL ===== */
        .panel {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 4px;
            margin-bottom: 1.5rem;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .panel:hover {
            border-color: rgba(37, 99, 235, 0.25);
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid rgba(37, 99, 235, 0.1);
        }

        .panel-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--white);
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .panel-title i { color: var(--gold); }

        .panel-action {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--blue-light);
            text-decoration: none;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .panel-action:hover {
            color: var(--gold);
            transform: translateX(3px);
        }

        .panel-body { padding: 1.5rem; }

        /* ===== QUICK ACTIONS ===== */
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
        }

        .qa-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.75rem;
            padding: 1.5rem 1rem;
            background: linear-gradient(135deg, rgba(26, 26, 26, 0.9) 0%, rgba(15, 15, 15, 0.95) 100%);
            border: 1px solid rgba(212, 175, 55, 0.15);
            border-radius: 4px;
            text-decoration: none;
            color: var(--gray-300);
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .qa-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at center, rgba(212, 175, 55, 0.1) 0%, transparent 70%);
            opacity: 0;
            transition: opacity 0.4s ease;
        }

        .qa-btn:hover::before {
            opacity: 1;
        }

        .qa-btn:hover {
            border-color: var(--gold);
            color: var(--gold);
            background: linear-gradient(135deg, rgba(212, 175, 55, 0.08) 0%, rgba(212, 175, 55, 0.03) 100%);
            transform: translateY(-4px);
            box-shadow: 0 12px 32px rgba(212, 175, 55, 0.2),
                        0 0 0 1px rgba(212, 175, 55, 0.2);
        }

        .qa-btn .qa-icon {
            width: 50px;
            height: 50px;
            border-radius: 4px;
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 50%, var(--gold-dark) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            color: var(--black);
            box-shadow: 0 4px 16px rgba(212, 175, 55, 0.3),
                        inset 0 1px 0 rgba(255, 255, 255, 0.2);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }

        .qa-btn:hover .qa-icon {
            box-shadow: 0 8px 28px rgba(212, 175, 55, 0.5),
                        0 0 20px rgba(212, 175, 55, 0.3),
                        inset 0 1px 0 rgba(255, 255, 255, 0.3);
            transform: scale(1.1) rotate(5deg);
        }

        /* ===== PROPERTY CARD ===== */
        .prop-card {
            background: rgba(26, 26, 26, 0.6);
            border: 1px solid rgba(37, 99, 235, 0.1);
            border-radius: 4px;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .prop-card:hover {
            border-color: rgba(37, 99, 235, 0.3);
            box-shadow: 0 8px 24px rgba(37, 99, 235, 0.1);
            transform: translateY(-3px);
        }

        .prop-card-img {
            width: 100%;
            height: 180px;
            object-fit: cover;
            background: rgba(26, 26, 26, 0.8);
        }

        .prop-card-body {
            padding: 1.25rem;
        }

        .prop-card-price {
            font-size: 1.25rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .prop-card-address {
            font-size: 0.85rem;
            color: var(--gray-400);
            margin-top: 0.25rem;
        }

        .prop-card-meta {
            display: flex;
            gap: 1rem;
            margin-top: 0.75rem;
            font-size: 0.8rem;
            color: var(--gray-500);
        }

        .prop-card-meta span {
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .prop-badge {
            display: inline-block;
            padding: 0.25rem 0.6rem;
            border-radius: 2px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .prop-badge.sale {
            background: rgba(34, 197, 94, 0.1);
            color: #22c55e;
            border: 1px solid rgba(34, 197, 94, 0.25);
        }

        .prop-badge.rent {
            background: rgba(37, 99, 235, 0.1);
            color: var(--blue-light);
            border: 1px solid rgba(37, 99, 235, 0.25);
        }

        .prop-badge.sold {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.25);
        }

        /* ===== TOP PROPERTY ROW ===== */
        .top-prop-item {
            display: flex;
            gap: 1rem;
            padding: 1rem;
            border-radius: 4px;
            border: 1px solid rgba(37, 99, 235, 0.08);
            transition: all 0.3s ease;
            margin-bottom: 0.75rem;
        }

        .top-prop-item:last-child { margin-bottom: 0; }

        .top-prop-item:hover {
            background: rgba(37, 99, 235, 0.03);
            border-color: rgba(37, 99, 235, 0.15);
        }

        .top-prop-img {
            width: 80px;
            height: 60px;
            object-fit: cover;
            border-radius: 4px;
            flex-shrink: 0;
            background: rgba(26, 26, 26, 0.8);
        }

        .top-prop-info { flex: 1; min-width: 0; }

        .top-prop-info .top-prop-title {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--white);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .top-prop-info .top-prop-loc {
            font-size: 0.8rem;
            color: var(--gray-500);
        }

        .top-prop-stats {
            display: flex;
            gap: 1rem;
            margin-top: 0.25rem;
            font-size: 0.8rem;
        }

        .top-prop-stats span {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            color: var(--gray-400);
        }

        .top-prop-stats span i { font-size: 0.75rem; }

        /* ===== TOUR LIST ===== */
        .tour-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border-radius: 4px;
            border: 1px solid rgba(37, 99, 235, 0.08);
            margin-bottom: 0.75rem;
            transition: all 0.3s ease;
        }

        .tour-item:last-child { margin-bottom: 0; }

        .tour-item:hover {
            background: rgba(37, 99, 235, 0.03);
            border-color: rgba(37, 99, 235, 0.15);
        }

        .tour-item-left {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex: 1;
            min-width: 0;
        }

        .tour-date-box {
            width: 52px;
            height: 52px;
            background: linear-gradient(135deg, rgba(212, 175, 55, 0.1) 0%, rgba(212, 175, 55, 0.05) 100%);
            border: 1px solid rgba(212, 175, 55, 0.2);
            border-radius: 4px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .tour-date-box .day {
            font-size: 1.2rem;
            font-weight: 800;
            color: var(--gold);
            line-height: 1;
        }

        .tour-date-box .month {
            font-size: 0.6rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--gold-dark);
        }

        .tour-details .tour-prop {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--white);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 250px;
        }

        .tour-details .tour-meta {
            font-size: 0.8rem;
            color: var(--gray-500);
        }

        .tour-time-badge {
            background: rgba(37, 99, 235, 0.1);
            color: var(--blue-light);
            border: 1px solid rgba(37, 99, 235, 0.2);
            padding: 0.25rem 0.6rem;
            border-radius: 2px;
            font-size: 0.75rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .tour-status-badge {
            padding: 0.25rem 0.6rem;
            border-radius: 2px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .tour-status-badge.pending {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.25);
        }

        .tour-status-badge.confirmed {
            background: rgba(34, 197, 94, 0.1);
            color: #22c55e;
            border: 1px solid rgba(34, 197, 94, 0.25);
        }

        /* ===== ACTIVITY TIMELINE ===== */
        .activity-item {
            display: flex;
            gap: 1rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid rgba(37, 99, 235, 0.06);
        }

        .activity-item:last-child { border-bottom: none; }

        .activity-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
            margin-top: 6px;
        }

        .activity-dot.created { background: #22c55e; box-shadow: 0 0 8px rgba(34, 197, 94, 0.4); }
        .activity-dot.updated { background: var(--blue-light); box-shadow: 0 0 8px rgba(59, 130, 246, 0.4); }
        .activity-dot.sold { background: var(--gold); box-shadow: 0 0 8px rgba(212, 175, 55, 0.4); }
        .activity-dot.deleted { background: #ef4444; box-shadow: 0 0 8px rgba(239, 68, 68, 0.4); }
        .activity-dot.rejected { background: #f97316; box-shadow: 0 0 8px rgba(249, 115, 22, 0.4); }

        .activity-info { flex: 1; }

        .activity-info .act-title {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--white);
        }

        .activity-info .act-desc {
            font-size: 0.8rem;
            color: var(--gray-500);
        }

        .activity-info .act-time {
            font-size: 0.75rem;
            color: var(--gray-600);
            margin-top: 0.15rem;
        }

        /* ===== PENDING SALE ITEM ===== */
        .sale-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border-radius: 4px;
            border: 1px solid rgba(245, 158, 11, 0.1);
            margin-bottom: 0.75rem;
            background: rgba(245, 158, 11, 0.02);
            transition: all 0.3s ease;
        }

        .sale-item:last-child { margin-bottom: 0; }

        .sale-item:hover {
            border-color: rgba(245, 158, 11, 0.2);
            background: rgba(245, 158, 11, 0.04);
        }

        .sale-item .sale-prop {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--white);
        }

        .sale-item .sale-detail {
            font-size: 0.8rem;
            color: var(--gray-500);
        }

        .sale-price {
            font-weight: 800;
            color: var(--gold);
            font-size: 0.95rem;
        }

        /* ===== EMPTY STATE ===== */
        .empty-state {
            text-align: center;
            padding: 2.5rem 1rem;
            color: var(--gray-500);
        }

        .empty-state i {
            font-size: 2.5rem;
            color: rgba(37, 99, 235, 0.3);
            margin-bottom: 0.75rem;
            display: block;
        }

        .empty-state p {
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }

        .btn-gold {
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 50%, var(--gold-dark) 100%);
            color: var(--white);
            border: none;
            padding: 14px 32px;
            font-size: 0.95rem;
            font-weight: 700;
            border-radius: 4px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
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

        .btn-gold:hover::before {
            left: 100%;
        }

        .btn-gold:active {
            transform: translateY(-1px) scale(0.98);
        }

        .btn-gold i {
            font-size: 1.1rem;
            color: var(--white);
            display: inline-flex;
            align-items: center;
            transition: transform 0.3s ease;
        }

        .btn-gold:hover i {
            transform: rotate(90deg);
        }
        
        .btn-gold span {
            display: inline-flex;
            align-items: center;
        }

        .btn-outline-blue {
            background: transparent;
            color: var(--blue-light);
            border: 1px solid rgba(37, 99, 235, 0.3);
            padding: 8px 20px;
            font-size: 0.85rem;
            font-weight: 600;
            border-radius: 2px;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .btn-outline-blue:hover {
            background: rgba(37, 99, 235, 0.08);
            border-color: var(--blue);
            color: var(--blue-light);
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 1200px) {
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 768px) {
            .dashboard-content { padding: 1rem; }
            .welcome-hero { padding: 1.5rem; }
            .welcome-hero-inner { flex-direction: column; text-align: center; }
            .welcome-brand { justify-content: center; }
            .welcome-brand .brand-info { text-align: center; }
            .welcome-text h1 { font-size: 1.5rem; }
            .kpi-grid { grid-template-columns: 1fr 1fr; gap: 0.75rem; }
            .kpi-card .kpi-value { font-size: 1.5rem; }
            .quick-actions-grid { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 480px) {
            .kpi-grid { grid-template-columns: 1fr; }
            .quick-actions-grid { grid-template-columns: 1fr 1fr; }
        }

        /* ================================================================
           SKELETON SCREEN SYSTEM — Dark Agent Portal Theme
           CSR / Progressive Hydration
           ================================================================ */

        /* ── Core shimmer animation (dark theme) ── */
        @keyframes sk-shimmer {
            0%   { background-position: -800px 0; }
            100% { background-position:  800px 0; }
        }
        .sk-shimmer {
            background: linear-gradient(90deg, rgba(255,255,255,0.03) 25%, rgba(255,255,255,0.06) 50%, rgba(255,255,255,0.03) 75%);
            background-size: 1600px 100%;
            animation: sk-shimmer 1.6s ease-in-out infinite;
            border-radius: 4px;
        }

        /* ── Real content: hidden until hydration reveals it ── */
        #page-content {
            display: none;
        }

        /* ── Skeleton component base styles (dark theme) ── */
        .sk-welcome-hero {
            background: linear-gradient(135deg, var(--black) 0%, var(--black-lighter) 100%);
            border: 1px solid var(--card-border);
            border-radius: 4px;
            padding: 2.5rem 3rem;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }
        .sk-welcome-hero::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent);
        }

        .sk-kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.25rem;
            margin-bottom: 2rem;
        }
        .sk-kpi-card {
            background: linear-gradient(135deg, rgba(26,26,26,0.8) 0%, rgba(10,10,10,0.9) 100%);
            border: 1px solid var(--card-border);
            border-radius: 4px;
            padding: 1.5rem;
            position: relative;
            overflow: hidden;
        }
        .sk-kpi-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }
        .sk-kpi-icon {
            width: 48px;
            height: 48px;
            border-radius: 4px;
            flex-shrink: 0;
        }

        .sk-panel {
            background: linear-gradient(135deg, rgba(26,26,26,0.8) 0%, rgba(10,10,10,0.9) 100%);
            border: 1px solid var(--card-border);
            border-radius: 4px;
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        .sk-panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid rgba(37,99,235,0.1);
        }
        .sk-panel-body {
            padding: 1.5rem;
        }

        .sk-qa-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
        }
        .sk-qa-btn {
            background: linear-gradient(135deg, rgba(26,26,26,0.9) 0%, rgba(15,15,15,0.95) 100%);
            border: 1px solid rgba(212,175,55,0.15);
            border-radius: 4px;
            padding: 1.5rem 1rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.75rem;
        }

        .sk-tour-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border-radius: 4px;
            border: 1px solid rgba(37,99,235,0.08);
            margin-bottom: 0.75rem;
        }
        .sk-tour-item:last-child { margin-bottom: 0; }

        .sk-prop-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }
        .sk-prop-card {
            background: rgba(26,26,26,0.6);
            border: 1px solid rgba(37,99,235,0.1);
            border-radius: 4px;
            overflow: hidden;
        }

        .sk-stat-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid rgba(37,99,235,0.08);
        }
        .sk-stat-row:last-child { border-bottom: none; }

        .sk-top-prop-item {
            display: flex;
            gap: 1rem;
            padding: 1rem;
            border-radius: 4px;
            border: 1px solid rgba(37,99,235,0.08);
            margin-bottom: 0.75rem;
        }
        .sk-top-prop-item:last-child { margin-bottom: 0; }

        .sk-activity-item {
            display: flex;
            gap: 1rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid rgba(37,99,235,0.06);
        }
        .sk-activity-item:last-child { border-bottom: none; }

        .sk-line { display: block; border-radius: 4px; }

        /* ================================================================
           TOAST NOTIFICATION SYSTEM — Dark Agent Portal Theme
           ================================================================ */
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
            background: linear-gradient(135deg, rgba(26,26,26,0.97) 0%, rgba(10,10,10,0.98) 100%);
            border: 1px solid rgba(37,99,235,0.15);
            border-radius: 12px;
            padding: 0.9rem 1.1rem;
            min-width: 300px;
            max-width: 400px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.5), 0 0 0 1px rgba(255,255,255,0.04);
            pointer-events: all;
            position: relative;
            overflow: hidden;
            animation: toast-in .35s cubic-bezier(.34,1.56,.64,1) forwards;
            backdrop-filter: blur(12px);
        }
        @keyframes toast-in  { from { opacity:0; transform: translateX(60px) scale(.95); } to { opacity:1; transform: translateX(0) scale(1); } }
        .app-toast.toast-out { animation: toast-out .3s ease forwards; }
        @keyframes toast-out { to { opacity:0; transform: translateX(60px) scale(.9); max-height:0; padding:0; margin:0; } }

        /* Left accent bar */
        .app-toast::before {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 3px;
        }
        .app-toast.toast-success::before { background: linear-gradient(180deg, #d4af37, #b8941f); }
        .app-toast.toast-error::before   { background: linear-gradient(180deg, #ef4444, #dc2626); }
        .app-toast.toast-info::before    { background: linear-gradient(180deg, #2563eb, #1e40af); }

        /* Icon badge */
        .app-toast-icon {
            width: 36px; height: 36px;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }
        .toast-success .app-toast-icon { background: rgba(212,175,55,0.15); color: #d4af37; }
        .toast-error   .app-toast-icon { background: rgba(239,68,68,0.12);  color: #ef4444; }
        .toast-info    .app-toast-icon { background: rgba(37,99,235,0.12);  color: #3b82f6; }

        /* Body text */
        .app-toast-body      { flex: 1; min-width: 0; }
        .app-toast-title     { font-size: 0.82rem; font-weight: 700; color: #f1f5f9; margin-bottom: 0.2rem; }
        .app-toast-msg       { font-size: 0.78rem; color: #9ca4ab; line-height: 1.4; word-break: break-word; }

        /* Close button */
        .app-toast-close {
            background: none; border: none; cursor: pointer;
            color: #5d6d7d; font-size: 0.8rem;
            padding: 0; line-height: 1;
            flex-shrink: 0;
            transition: color .2s;
        }
        .app-toast-close:hover { color: #f1f5f9; }

        /* Auto-dismiss progress bar */
        .app-toast-progress {
            position: absolute;
            bottom: 0; left: 0;
            height: 2px;
            border-radius: 0 0 0 12px;
        }
        .toast-success .app-toast-progress { background: linear-gradient(90deg, #d4af37, #b8941f); }
        .toast-error   .app-toast-progress { background: linear-gradient(90deg, #ef4444, #dc2626); }
        .toast-info    .app-toast-progress { background: linear-gradient(90deg, #2563eb, #1e40af); }
        @keyframes toast-progress { from { width: 100%; } to { width: 0%; } }

        /* ── Skeleton responsive ── */
        @media (max-width: 1200px) { .sk-kpi-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 768px) {
            .sk-kpi-grid { grid-template-columns: 1fr 1fr; gap: 0.75rem; }
            .sk-qa-grid { grid-template-columns: repeat(2, 1fr); }
            .sk-welcome-hero { padding: 1.5rem; }
            .sk-prop-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 480px) {
            .sk-kpi-grid { grid-template-columns: 1fr; }
            .sk-qa-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>

<?php
// Set this file as active in navbar
$active_page = 'agent_dashboard.php';
include 'agent_navbar.php';
?>

<div class="dashboard-content">

    <!-- NO-JS FALLBACK -->
    <noscript><style>
        #sk-screen    { display: none !important; }
        #page-content { display: block !important; opacity: 1 !important; }
    </style></noscript>

    <!-- SKELETON SCREEN -->
    <div id="sk-screen" role="presentation" aria-hidden="true">

        <!-- Welcome Hero Skeleton -->
        <div class="sk-welcome-hero">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1.5rem;">
                <div>
                    <div class="sk-line sk-shimmer" style="width:320px;height:28px;margin-bottom:10px;"></div>
                    <div class="sk-line sk-shimmer" style="width:280px;height:14px;margin-bottom:8px;"></div>
                    <div class="sk-line sk-shimmer" style="width:240px;height:12px;"></div>
                </div>
                <div style="display:flex;align-items:center;gap:1rem;">
                    <div>
                        <div class="sk-line sk-shimmer" style="width:140px;height:18px;margin-bottom:6px;margin-left:auto;"></div>
                        <div class="sk-line sk-shimmer" style="width:80px;height:10px;margin-left:auto;"></div>
                    </div>
                    <div class="sk-shimmer" style="width:50px;height:50px;border-radius:4px;"></div>
                </div>
            </div>
        </div>

        <!-- KPI Grid Skeleton -->
        <div class="sk-kpi-grid">
            <div class="sk-kpi-card">
                <div class="sk-kpi-header">
                    <div class="sk-line sk-shimmer" style="width:90px;height:10px;"></div>
                    <div class="sk-kpi-icon sk-shimmer"></div>
                </div>
                <div class="sk-line sk-shimmer" style="width:60px;height:28px;margin-bottom:8px;"></div>
                <div class="sk-line sk-shimmer" style="width:130px;height:11px;"></div>
            </div>
            <div class="sk-kpi-card">
                <div class="sk-kpi-header">
                    <div class="sk-line sk-shimmer" style="width:110px;height:10px;"></div>
                    <div class="sk-kpi-icon sk-shimmer"></div>
                </div>
                <div class="sk-line sk-shimmer" style="width:100px;height:28px;margin-bottom:8px;"></div>
                <div class="sk-line sk-shimmer" style="width:110px;height:11px;"></div>
            </div>
            <div class="sk-kpi-card">
                <div class="sk-kpi-header">
                    <div class="sk-line sk-shimmer" style="width:120px;height:10px;"></div>
                    <div class="sk-kpi-icon sk-shimmer"></div>
                </div>
                <div class="sk-line sk-shimmer" style="width:90px;height:28px;margin-bottom:8px;"></div>
                <div class="sk-line sk-shimmer" style="width:140px;height:11px;"></div>
            </div>
            <div class="sk-kpi-card">
                <div class="sk-kpi-header">
                    <div class="sk-line sk-shimmer" style="width:95px;height:10px;"></div>
                    <div class="sk-kpi-icon sk-shimmer"></div>
                </div>
                <div class="sk-line sk-shimmer" style="width:50px;height:28px;margin-bottom:8px;"></div>
                <div class="sk-line sk-shimmer" style="width:120px;height:11px;"></div>
            </div>
        </div>

        <!-- Quick Actions Skeleton -->
        <div class="sk-panel">
            <div class="sk-panel-header">
                <div class="sk-line sk-shimmer" style="width:130px;height:16px;"></div>
            </div>
            <div class="sk-panel-body">
                <div class="sk-qa-grid">
                    <div class="sk-qa-btn">
                        <div class="sk-shimmer" style="width:50px;height:50px;border-radius:4px;"></div>
                        <div class="sk-line sk-shimmer" style="width:90px;height:13px;"></div>
                    </div>
                    <div class="sk-qa-btn">
                        <div class="sk-shimmer" style="width:50px;height:50px;border-radius:4px;"></div>
                        <div class="sk-line sk-shimmer" style="width:85px;height:13px;"></div>
                    </div>
                    <div class="sk-qa-btn">
                        <div class="sk-shimmer" style="width:50px;height:50px;border-radius:4px;"></div>
                        <div class="sk-line sk-shimmer" style="width:95px;height:13px;"></div>
                    </div>
                    <div class="sk-qa-btn">
                        <div class="sk-shimmer" style="width:50px;height:50px;border-radius:4px;"></div>
                        <div class="sk-line sk-shimmer" style="width:88px;height:13px;"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Two-Column Layout Skeleton -->
        <div class="row g-4">
            <!-- Left Column -->
            <div class="col-lg-8">
                <!-- Upcoming Tours Skeleton -->
                <div class="sk-panel">
                    <div class="sk-panel-header">
                        <div class="sk-line sk-shimmer" style="width:140px;height:16px;"></div>
                        <div class="sk-line sk-shimmer" style="width:65px;height:13px;"></div>
                    </div>
                    <div class="sk-panel-body">
                        <div class="sk-tour-item">
                            <div style="display:flex;gap:1rem;align-items:center;flex:1;">
                                <div class="sk-shimmer" style="width:52px;height:52px;border-radius:4px;flex-shrink:0;"></div>
                                <div>
                                    <div class="sk-line sk-shimmer" style="width:180px;height:14px;margin-bottom:6px;"></div>
                                    <div class="sk-line sk-shimmer" style="width:140px;height:11px;"></div>
                                </div>
                            </div>
                            <div style="display:flex;gap:0.5rem;">
                                <div class="sk-shimmer" style="width:75px;height:26px;border-radius:2px;"></div>
                                <div class="sk-shimmer" style="width:70px;height:26px;border-radius:2px;"></div>
                            </div>
                        </div>
                        <div class="sk-tour-item">
                            <div style="display:flex;gap:1rem;align-items:center;flex:1;">
                                <div class="sk-shimmer" style="width:52px;height:52px;border-radius:4px;flex-shrink:0;"></div>
                                <div>
                                    <div class="sk-line sk-shimmer" style="width:200px;height:14px;margin-bottom:6px;"></div>
                                    <div class="sk-line sk-shimmer" style="width:120px;height:11px;"></div>
                                </div>
                            </div>
                            <div style="display:flex;gap:0.5rem;">
                                <div class="sk-shimmer" style="width:75px;height:26px;border-radius:2px;"></div>
                                <div class="sk-shimmer" style="width:70px;height:26px;border-radius:2px;"></div>
                            </div>
                        </div>
                        <div class="sk-tour-item">
                            <div style="display:flex;gap:1rem;align-items:center;flex:1;">
                                <div class="sk-shimmer" style="width:52px;height:52px;border-radius:4px;flex-shrink:0;"></div>
                                <div>
                                    <div class="sk-line sk-shimmer" style="width:160px;height:14px;margin-bottom:6px;"></div>
                                    <div class="sk-line sk-shimmer" style="width:150px;height:11px;"></div>
                                </div>
                            </div>
                            <div style="display:flex;gap:0.5rem;">
                                <div class="sk-shimmer" style="width:75px;height:26px;border-radius:2px;"></div>
                                <div class="sk-shimmer" style="width:70px;height:26px;border-radius:2px;"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Active Listings Skeleton -->
                <div class="sk-panel">
                    <div class="sk-panel-header">
                        <div class="sk-line sk-shimmer" style="width:120px;height:16px;"></div>
                        <div class="sk-line sk-shimmer" style="width:65px;height:13px;"></div>
                    </div>
                    <div class="sk-panel-body">
                        <div class="sk-prop-grid">
                            <div class="sk-prop-card">
                                <div class="sk-shimmer" style="width:100%;height:180px;"></div>
                                <div style="padding:1.25rem;">
                                    <div style="display:flex;justify-content:space-between;margin-bottom:8px;">
                                        <div class="sk-line sk-shimmer" style="width:100px;height:18px;"></div>
                                        <div class="sk-shimmer" style="width:55px;height:20px;border-radius:2px;"></div>
                                    </div>
                                    <div class="sk-line sk-shimmer" style="width:85%;height:12px;margin-bottom:10px;"></div>
                                    <div style="display:flex;gap:1rem;">
                                        <div class="sk-line sk-shimmer" style="width:40px;height:11px;"></div>
                                        <div class="sk-line sk-shimmer" style="width:40px;height:11px;"></div>
                                        <div class="sk-line sk-shimmer" style="width:60px;height:11px;"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="sk-prop-card">
                                <div class="sk-shimmer" style="width:100%;height:180px;"></div>
                                <div style="padding:1.25rem;">
                                    <div style="display:flex;justify-content:space-between;margin-bottom:8px;">
                                        <div class="sk-line sk-shimmer" style="width:110px;height:18px;"></div>
                                        <div class="sk-shimmer" style="width:50px;height:20px;border-radius:2px;"></div>
                                    </div>
                                    <div class="sk-line sk-shimmer" style="width:75%;height:12px;margin-bottom:10px;"></div>
                                    <div style="display:flex;gap:1rem;">
                                        <div class="sk-line sk-shimmer" style="width:40px;height:11px;"></div>
                                        <div class="sk-line sk-shimmer" style="width:40px;height:11px;"></div>
                                        <div class="sk-line sk-shimmer" style="width:60px;height:11px;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column -->
            <div class="col-lg-4">
                <!-- Portfolio Overview Skeleton -->
                <div class="sk-panel">
                    <div class="sk-panel-header">
                        <div class="sk-line sk-shimmer" style="width:140px;height:16px;"></div>
                    </div>
                    <div class="sk-panel-body">
                        <div class="sk-stat-row">
                            <div class="sk-line sk-shimmer" style="width:90px;height:12px;"></div>
                            <div class="sk-line sk-shimmer" style="width:30px;height:14px;"></div>
                        </div>
                        <div class="sk-stat-row">
                            <div class="sk-line sk-shimmer" style="width:100px;height:12px;"></div>
                            <div class="sk-line sk-shimmer" style="width:25px;height:14px;"></div>
                        </div>
                        <div class="sk-stat-row">
                            <div class="sk-line sk-shimmer" style="width:110px;height:12px;"></div>
                            <div class="sk-line sk-shimmer" style="width:28px;height:14px;"></div>
                        </div>
                        <div class="sk-stat-row">
                            <div class="sk-line sk-shimmer" style="width:80px;height:12px;"></div>
                            <div class="sk-line sk-shimmer" style="width:40px;height:14px;"></div>
                        </div>
                        <div class="sk-stat-row">
                            <div class="sk-line sk-shimmer" style="width:75px;height:12px;"></div>
                            <div class="sk-line sk-shimmer" style="width:35px;height:14px;"></div>
                        </div>
                        <div class="sk-stat-row">
                            <div class="sk-line sk-shimmer" style="width:105px;height:12px;"></div>
                            <div class="sk-line sk-shimmer" style="width:60px;height:14px;"></div>
                        </div>
                        <div class="sk-stat-row">
                            <div class="sk-line sk-shimmer" style="width:110px;height:12px;"></div>
                            <div class="sk-line sk-shimmer" style="width:28px;height:14px;"></div>
                        </div>
                    </div>
                </div>

                <!-- Top Performing Skeleton -->
                <div class="sk-panel">
                    <div class="sk-panel-header">
                        <div class="sk-line sk-shimmer" style="width:120px;height:16px;"></div>
                    </div>
                    <div class="sk-panel-body">
                        <div class="sk-top-prop-item">
                            <div class="sk-shimmer" style="width:80px;height:60px;border-radius:4px;flex-shrink:0;"></div>
                            <div style="flex:1;">
                                <div class="sk-line sk-shimmer" style="width:85%;height:13px;margin-bottom:6px;"></div>
                                <div class="sk-line sk-shimmer" style="width:60%;height:11px;margin-bottom:6px;"></div>
                                <div style="display:flex;gap:0.75rem;">
                                    <div class="sk-line sk-shimmer" style="width:40px;height:10px;"></div>
                                    <div class="sk-line sk-shimmer" style="width:35px;height:10px;"></div>
                                    <div class="sk-line sk-shimmer" style="width:65px;height:10px;"></div>
                                </div>
                            </div>
                        </div>
                        <div class="sk-top-prop-item">
                            <div class="sk-shimmer" style="width:80px;height:60px;border-radius:4px;flex-shrink:0;"></div>
                            <div style="flex:1;">
                                <div class="sk-line sk-shimmer" style="width:75%;height:13px;margin-bottom:6px;"></div>
                                <div class="sk-line sk-shimmer" style="width:55%;height:11px;margin-bottom:6px;"></div>
                                <div style="display:flex;gap:0.75rem;">
                                    <div class="sk-line sk-shimmer" style="width:40px;height:10px;"></div>
                                    <div class="sk-line sk-shimmer" style="width:35px;height:10px;"></div>
                                    <div class="sk-line sk-shimmer" style="width:65px;height:10px;"></div>
                                </div>
                            </div>
                        </div>
                        <div class="sk-top-prop-item">
                            <div class="sk-shimmer" style="width:80px;height:60px;border-radius:4px;flex-shrink:0;"></div>
                            <div style="flex:1;">
                                <div class="sk-line sk-shimmer" style="width:80%;height:13px;margin-bottom:6px;"></div>
                                <div class="sk-line sk-shimmer" style="width:50%;height:11px;margin-bottom:6px;"></div>
                                <div style="display:flex;gap:0.75rem;">
                                    <div class="sk-line sk-shimmer" style="width:40px;height:10px;"></div>
                                    <div class="sk-line sk-shimmer" style="width:35px;height:10px;"></div>
                                    <div class="sk-line sk-shimmer" style="width:65px;height:10px;"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity Skeleton -->
                <div class="sk-panel">
                    <div class="sk-panel-header">
                        <div class="sk-line sk-shimmer" style="width:120px;height:16px;"></div>
                    </div>
                    <div class="sk-panel-body">
                        <div class="sk-activity-item">
                            <div class="sk-shimmer" style="width:10px;height:10px;border-radius:50%;flex-shrink:0;margin-top:4px;"></div>
                            <div style="flex:1;">
                                <div class="sk-line sk-shimmer" style="width:140px;height:13px;margin-bottom:5px;"></div>
                                <div class="sk-line sk-shimmer" style="width:180px;height:11px;margin-bottom:4px;"></div>
                                <div class="sk-line sk-shimmer" style="width:120px;height:10px;"></div>
                            </div>
                        </div>
                        <div class="sk-activity-item">
                            <div class="sk-shimmer" style="width:10px;height:10px;border-radius:50%;flex-shrink:0;margin-top:4px;"></div>
                            <div style="flex:1;">
                                <div class="sk-line sk-shimmer" style="width:130px;height:13px;margin-bottom:5px;"></div>
                                <div class="sk-line sk-shimmer" style="width:160px;height:11px;margin-bottom:4px;"></div>
                                <div class="sk-line sk-shimmer" style="width:110px;height:10px;"></div>
                            </div>
                        </div>
                        <div class="sk-activity-item">
                            <div class="sk-shimmer" style="width:10px;height:10px;border-radius:50%;flex-shrink:0;margin-top:4px;"></div>
                            <div style="flex:1;">
                                <div class="sk-line sk-shimmer" style="width:150px;height:13px;margin-bottom:5px;"></div>
                                <div class="sk-line sk-shimmer" style="width:170px;height:11px;margin-bottom:4px;"></div>
                                <div class="sk-line sk-shimmer" style="width:130px;height:10px;"></div>
                            </div>
                        </div>
                        <div class="sk-activity-item">
                            <div class="sk-shimmer" style="width:10px;height:10px;border-radius:50%;flex-shrink:0;margin-top:4px;"></div>
                            <div style="flex:1;">
                                <div class="sk-line sk-shimmer" style="width:120px;height:13px;margin-bottom:5px;"></div>
                                <div class="sk-line sk-shimmer" style="width:150px;height:11px;margin-bottom:4px;"></div>
                                <div class="sk-line sk-shimmer" style="width:100px;height:10px;"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /#sk-screen -->

    <!-- REAL CONTENT (hidden until hydrated) -->
    <div id="page-content">

    <!-- Welcome Hero -->
    <div class="welcome-hero">
        <div class="welcome-hero-inner">
            <div class="welcome-text">
                <h1><?php echo $greeting; ?>, <?php echo $agent_name; ?></h1>
                <div class="subtitle">Here's your real estate performance overview</div>
                <div class="date-display">
                    <i class="bi bi-calendar3 me-1"></i>
                    <?php echo date('l, F j, Y'); ?>
                    &nbsp;&bull;&nbsp; Member since <?php echo $member_since; ?>
                </div>
            </div>
            <div class="welcome-brand">
                <div class="brand-info">
                    <div class="brand-name">HomeEstate Realty</div>
                    <div class="brand-tagline">Agent Portal</div>
                </div>
                <img src="../images/Logo.png" alt="HomeEstate Realty Logo">
            </div>
        </div>
    </div>

    <!-- KPI Stats Row -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-header">
                <div class="kpi-label">Active Listings</div>
                <div class="kpi-icon gold"><i class="bi bi-building"></i></div>
            </div>
            <div class="kpi-value"><?php echo $stats['active_listings'] ?? 0; ?></div>
            <div class="kpi-sub">
                <?php echo ($stats['for_sale'] ?? 0); ?> for sale &bull; <?php echo ($stats['for_rent'] ?? 0); ?> for rent
            </div>
        </div>

        <div class="kpi-card">
            <div class="kpi-header">
                <div class="kpi-label">Total Sales Volume</div>
                <div class="kpi-icon green"><i class="bi bi-cash-stack"></i></div>
            </div>
            <div class="kpi-value">₱<?php echo number_format($sales_volume['total_sales_volume'] ?? 0, 0); ?></div>
            <div class="kpi-sub">
                <?php echo $sales_volume['finalized_count'] ?? 0; ?> finalized sale<?php echo ($sales_volume['finalized_count'] ?? 0) != 1 ? 's' : ''; ?>
            </div>
        </div>

        <div class="kpi-card">
            <div class="kpi-header">
                <div class="kpi-label">Commission Earned</div>
                <div class="kpi-icon blue"><i class="bi bi-wallet2"></i></div>
            </div>
            <div class="kpi-value">₱<?php echo number_format($commissions['total_commission'] ?? 0, 0); ?></div>
            <div class="kpi-sub">
                ₱<?php echo number_format($commissions['paid_commission'] ?? 0, 0); ?> paid &bull;
                ₱<?php echo number_format($commissions['unpaid_commission'] ?? 0, 0); ?> pending
            </div>
        </div>

        <div class="kpi-card">
            <div class="kpi-header">
                <div class="kpi-label">Tour Requests</div>
                <div class="kpi-icon red"><i class="bi bi-calendar-event"></i></div>
            </div>
            <div class="kpi-value"><?php echo $tour_stats['total_tours'] ?? 0; ?></div>
            <div class="kpi-sub">
                <?php echo ($tour_stats['pending_tours'] ?? 0); ?> pending &bull; <?php echo ($tour_stats['confirmed_tours'] ?? 0); ?> confirmed
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="panel">
        <div class="panel-header">
            <div class="panel-title"><i class="bi bi-lightning-charge-fill"></i> Quick Actions</div>
        </div>
        <div class="panel-body">
            <div class="quick-actions-grid">
                <a href="add_property_process.php" class="qa-btn">
                    <div class="qa-icon"><i class="bi bi-plus-lg"></i></div>
                    Add New Listing
                </a>
                <a href="agent_property.php" class="qa-btn">
                    <div class="qa-icon"><i class="bi bi-house-door"></i></div>
                    My Properties
                </a>
                <a href="agent_tour_requests.php" class="qa-btn">
                    <div class="qa-icon"><i class="bi bi-calendar-check"></i></div>
                    Tour Requests
                </a>
                <a href="agent_commissions.php" class="qa-btn">
                    <div class="qa-icon"><i class="bi bi-wallet2"></i></div>
                    Commissions
                </a>
            </div>
        </div>
    </div>

    <!-- Two-Column Layout -->
    <div class="row g-4">

        <!-- Left Column -->
        <div class="col-lg-8">

            <!-- Upcoming Tours -->
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title"><i class="bi bi-calendar2-week"></i> Upcoming Tours</div>
                    <a href="agent_tour_requests.php" class="panel-action">
                        View All <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
                <div class="panel-body">
                    <?php if (empty($upcoming_tours)): ?>
                        <div class="empty-state">
                            <i class="bi bi-calendar-x"></i>
                            <p>No upcoming tours scheduled</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($upcoming_tours as $tour): ?>
                            <div class="tour-item">
                                <div class="tour-item-left">
                                    <div class="tour-date-box">
                                        <div class="day"><?php echo date('d', strtotime($tour['tour_date'])); ?></div>
                                        <div class="month"><?php echo date('M', strtotime($tour['tour_date'])); ?></div>
                                    </div>
                                    <div class="tour-details">
                                        <div class="tour-prop"><?php echo htmlspecialchars($tour['StreetAddress']); ?></div>
                                        <div class="tour-meta">
                                            <i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($tour['City']); ?>
                                            &bull; <?php echo htmlspecialchars($tour['user_name']); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="tour-time-badge">
                                        <i class="bi bi-clock me-1"></i><?php echo date('g:i A', strtotime($tour['tour_time'])); ?>
                                    </span>
                                    <span class="tour-status-badge confirmed">Confirmed</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Pending Tour Requests -->
            <?php if (!empty($pending_tours)): ?>
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title"><i class="bi bi-hourglass-split"></i> Pending Tour Requests</div>
                    <a href="agent_tour_requests.php?status=Pending" class="panel-action">
                        View All <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
                <div class="panel-body">
                    <?php foreach ($pending_tours as $pt): ?>
                        <div class="tour-item">
                            <div class="tour-item-left">
                                <div class="tour-date-box">
                                    <div class="day"><?php echo date('d', strtotime($pt['tour_date'])); ?></div>
                                    <div class="month"><?php echo date('M', strtotime($pt['tour_date'])); ?></div>
                                </div>
                                <div class="tour-details">
                                    <div class="tour-prop"><?php echo htmlspecialchars($pt['StreetAddress']); ?></div>
                                    <div class="tour-meta">
                                        <?php echo htmlspecialchars($pt['user_name']); ?> &bull;
                                        <?php echo htmlspecialchars($pt['user_email']); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="tour-time-badge">
                                    <i class="bi bi-clock me-1"></i><?php echo date('g:i A', strtotime($pt['tour_time'])); ?>
                                </span>
                                <span class="tour-status-badge pending">Pending</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Recent Active Listings -->
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title"><i class="bi bi-house-door"></i> Active Listings</div>
                    <a href="agent_property.php" class="panel-action">
                        View All <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
                <div class="panel-body">
                    <?php if (empty($recent_properties)): ?>
                        <div class="empty-state">
                            <i class="bi bi-house-slash"></i>
                            <p>No active listings yet. Add your first property to get started!</p>
                            <a href="add_property_process.php" class="btn-gold">
                                <i class="bi bi-plus-circle-fill"></i>
                                <span>Add Property</span>
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="row g-3">
                            <?php foreach ($recent_properties as $property): ?>
                                <div class="col-md-6">
                                    <div class="prop-card">
                                        <img src="../<?php echo htmlspecialchars($property['image'] ?? ''); ?>"
                                             alt="Property" class="prop-card-img"
                                             onerror="this.style.display='none'">
                                        <div class="prop-card-body">
                                            <div class="d-flex justify-content-between align-items-start mb-1">
                                                <div class="prop-card-price">
                                                    ₱<?php echo number_format($property['ListingPrice'], 0); ?>
                                                </div>
                                                <span class="prop-badge <?php echo $property['Status'] === 'For Sale' ? 'sale' : ($property['Status'] === 'Sold' ? 'sold' : 'rent'); ?>">
                                                    <?php echo htmlspecialchars($property['Status']); ?>
                                                </span>
                                            </div>
                                            <div class="prop-card-address">
                                                <i class="bi bi-geo-alt me-1"></i>
                                                <?php echo htmlspecialchars($property['StreetAddress'] . ', ' . $property['City']); ?>
                                            </div>
                                            <div class="prop-card-meta">
                                                <?php if ($property['Bedrooms']): ?>
                                                    <span><i class="bi bi-door-open"></i> <?php echo $property['Bedrooms']; ?> bd</span>
                                                <?php endif; ?>
                                                <?php if ($property['Bathrooms']): ?>
                                                    <span><i class="bi bi-droplet"></i> <?php echo $property['Bathrooms']; ?> ba</span>
                                                <?php endif; ?>
                                                <?php if ($property['SquareFootage']): ?>
                                                    <span><i class="bi bi-arrows-angle-expand"></i> <?php echo number_format($property['SquareFootage']); ?> sqft</span>
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
        </div>

        <!-- Right Column -->
        <div class="col-lg-4">

            <!-- Portfolio Overview Mini Stats -->
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title"><i class="bi bi-bar-chart-line"></i> Portfolio Overview</div>
                </div>
                <div class="panel-body">
                    <div class="d-flex justify-content-between align-items-center mb-3 pb-3" style="border-bottom: 1px solid rgba(37,99,235,0.08);">
                        <span style="color: var(--gray-400); font-size: 0.85rem;">Total Listings</span>
                        <span style="font-weight: 700; color: var(--white);"><?php echo $stats['total_listings'] ?? 0; ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-3 pb-3" style="border-bottom: 1px solid rgba(37,99,235,0.08);">
                        <span style="color: var(--gray-400); font-size: 0.85rem;">Properties Sold</span>
                        <span style="font-weight: 700; color: #22c55e;"><?php echo $stats['total_sold'] ?? 0; ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-3 pb-3" style="border-bottom: 1px solid rgba(37,99,235,0.08);">
                        <span style="color: var(--gray-400); font-size: 0.85rem;">Pending Approval</span>
                        <span style="font-weight: 700; color: #f59e0b;"><?php echo $stats['pending_approval'] ?? 0; ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-3 pb-3" style="border-bottom: 1px solid rgba(37,99,235,0.08);">
                        <span style="color: var(--gray-400); font-size: 0.85rem;">Total Views</span>
                        <span style="font-weight: 700; color: var(--blue-light);"><?php echo number_format($stats['total_views'] ?? 0); ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-3 pb-3" style="border-bottom: 1px solid rgba(37,99,235,0.08);">
                        <span style="color: var(--gray-400); font-size: 0.85rem;">Total Likes</span>
                        <span style="font-weight: 700; color: #ef4444;"><?php echo number_format($stats['total_likes'] ?? 0); ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-3 pb-3" style="border-bottom: 1px solid rgba(37,99,235,0.08);">
                        <span style="color: var(--gray-400); font-size: 0.85rem;">Avg. Listing Price</span>
                        <span style="font-weight: 700; color: var(--gold);">₱<?php echo number_format($stats['avg_listing_price'] ?? 0, 0); ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span style="color: var(--gray-400); font-size: 0.85rem;">Completed Tours</span>
                        <span style="font-weight: 700; color: #22c55e;"><?php echo $tour_stats['completed_tours'] ?? 0; ?></span>
                    </div>
                </div>
            </div>

            <!-- Top Performing Properties -->
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title"><i class="bi bi-trophy"></i> Top Performing</div>
                </div>
                <div class="panel-body">
                    <?php if (empty($top_properties)): ?>
                        <div class="empty-state">
                            <i class="bi bi-trophy"></i>
                            <p>No property data available yet</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($top_properties as $idx => $tp): ?>
                            <div class="top-prop-item">
                                <img src="../<?php echo htmlspecialchars($tp['image'] ?? ''); ?>"
                                     alt="" class="top-prop-img"
                                     onerror="this.style.background='rgba(37,99,235,0.08)'; this.style.display='flex';">
                                <div class="top-prop-info">
                                    <div class="top-prop-title"><?php echo htmlspecialchars($tp['StreetAddress']); ?></div>
                                    <div class="top-prop-loc"><?php echo htmlspecialchars($tp['City']); ?> &bull; <?php echo htmlspecialchars($tp['PropertyType']); ?></div>
                                    <div class="top-prop-stats">
                                        <span><i class="bi bi-eye"></i> <?php echo number_format($tp['ViewsCount']); ?></span>
                                        <span><i class="bi bi-heart"></i> <?php echo number_format($tp['Likes']); ?></span>
                                        <span style="color: var(--gold);"><i class="bi bi-tag"></i> ₱<?php echo number_format($tp['ListingPrice'], 0); ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Pending Sale Verifications -->
            <?php if (!empty($pending_sales)): ?>
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title"><i class="bi bi-clipboard-check"></i> Pending Sale Reviews</div>
                </div>
                <div class="panel-body">
                    <?php foreach ($pending_sales as $ps): ?>
                        <div class="sale-item">
                            <div>
                                <div class="sale-prop"><?php echo htmlspecialchars($ps['StreetAddress']); ?></div>
                                <div class="sale-detail">
                                    <?php echo htmlspecialchars($ps['City']); ?> &bull;
                                    Buyer: <?php echo htmlspecialchars($ps['buyer_name']); ?>
                                </div>
                            </div>
                            <div class="sale-price">₱<?php echo number_format($ps['sale_price'], 0); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Recent Activity -->
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title"><i class="bi bi-clock-history"></i> Recent Activity</div>
                </div>
                <div class="panel-body">
                    <?php if (empty($recent_activity)): ?>
                        <div class="empty-state">
                            <i class="bi bi-clock-history"></i>
                            <p>No recent activity to display</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recent_activity as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-dot <?php echo strtolower($activity['action']); ?>"></div>
                                <div class="activity-info">
                                    <div class="act-title">
                                        <?php
                                            $action_labels = [
                                                'CREATED' => 'Listed new property',
                                                'UPDATED' => 'Updated property',
                                                'DELETED' => 'Removed property',
                                                'SOLD' => 'Property sold',
                                                'REJECTED' => 'Property rejected'
                                            ];
                                            echo $action_labels[$activity['action']] ?? ucfirst(strtolower($activity['action']));
                                        ?>
                                    </div>
                                    <div class="act-desc">
                                        <?php echo htmlspecialchars($activity['StreetAddress'] . ', ' . $activity['City']); ?>
                                    </div>
                                    <div class="act-time">
                                        <?php echo date('M d, Y \a\t g:i A', strtotime($activity['log_timestamp'])); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

    </div><!-- /#page-content -->

</div><!-- /.dashboard-content -->

<?php include 'logout_agent_modal.php'; ?>

<!-- Toast Container -->
<div id="toastContainer"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ===== TOAST FUNCTIONS =====
function showToast(type, title, message, duration) {
    duration = duration || 4500;
    var container = document.getElementById('toastContainer');
    var icons = {
        success: 'bi-check-circle-fill',
        error:   'bi-x-circle-fill',
        info:    'bi-info-circle-fill'
    };
    var toast = document.createElement('div');
    toast.className = 'app-toast toast-' + type;
    toast.innerHTML =
        '<div class="app-toast-icon"><i class="bi ' + (icons[type] || icons.info) + '"></i></div>' +
        '<div class="app-toast-body">' +
            '<div class="app-toast-title">' + title + '</div>' +
            '<div class="app-toast-msg">' + message + '</div>' +
        '</div>' +
        '<button class="app-toast-close" onclick="dismissToast(this.closest(&quot;.app-toast&quot;))">&times;</button>' +
        '<div class="app-toast-progress" style="animation: toast-progress ' + duration + 'ms linear forwards;"></div>';
    container.appendChild(toast);
    var timer = setTimeout(function() { dismissToast(toast); }, duration);
    toast._timer = timer;
}
function dismissToast(toast) {
    if (!toast || toast._dismissed) return;
    toast._dismissed = true;
    clearTimeout(toast._timer);
    toast.classList.add('toast-out');
    setTimeout(function() { toast.remove(); }, 320);
}

// Visual animations + toasts fire AFTER skeleton hydration
document.addEventListener('skeleton:hydrated', function() {
    // Animate KPI values on load
    document.querySelectorAll('.kpi-value').forEach(el => {
        const text = el.textContent.trim();
        const hasPrefix = text.startsWith('₱');
        const numStr = text.replace(/[₱,]/g, '');
        const target = parseInt(numStr) || 0;
        if (target === 0) return;

        let current = 0;
        const duration = 1200;
        const startTime = performance.now();

        function animate(currentTime) {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            current = Math.floor(target * eased);

            el.textContent = (hasPrefix ? '₱' : '') + current.toLocaleString();

            if (progress < 1) {
                requestAnimationFrame(animate);
            } else {
                el.textContent = (hasPrefix ? '₱' : '') + target.toLocaleString();
            }
        }

        requestAnimationFrame(animate);
    });

    // Intersection Observer for fade-in animations
    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1, rootMargin: '0px 0px -30px 0px' });

    document.querySelectorAll('.kpi-card, .panel, .prop-card').forEach(el => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(15px)';
        el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
        observer.observe(el);
    });

    // Staggered animation for KPI cards
    document.querySelectorAll('.kpi-card').forEach((card, i) => {
        card.style.transitionDelay = (i * 0.1) + 's';
    });

    // ===== TOAST NOTIFICATIONS (staggered 600ms apart) =====
    var toastDelay = 0;
    var TOAST_GAP = 600;

    <?php if (($today_tours['today_count'] ?? 0) > 0): ?>
    setTimeout(function() {
        showToast('success', 'Today\'s Schedule',
            'You have <?= (int)$today_tours['today_count'] ?> confirmed tour<?= (int)$today_tours['today_count'] !== 1 ? "s" : "" ?> scheduled for today.', 6000);
    }, toastDelay);
    toastDelay += TOAST_GAP;
    <?php endif; ?>

    <?php if (($tour_stats['pending_tours'] ?? 0) > 0): ?>
    setTimeout(function() {
        showToast('info', 'Pending Tours',
            'You have <?= (int)$tour_stats['pending_tours'] ?> tour request<?= (int)$tour_stats['pending_tours'] !== 1 ? "s" : "" ?> awaiting your response.', 6000);
    }, toastDelay);
    toastDelay += TOAST_GAP;
    <?php endif; ?>

    <?php if (($stats['pending_approval'] ?? 0) > 0): ?>
    setTimeout(function() {
        showToast('info', 'Pending Approval',
            '<?= (int)$stats['pending_approval'] ?> propert<?= (int)$stats['pending_approval'] !== 1 ? "ies are" : "y is" ?> pending admin approval.', 5500);
    }, toastDelay);
    toastDelay += TOAST_GAP;
    <?php endif; ?>

    <?php if (count($pending_sales) > 0): ?>
    setTimeout(function() {
        showToast('info', 'Sale Verifications',
            '<?= count($pending_sales) ?> sale verification<?= count($pending_sales) !== 1 ? "s are" : " is" ?> currently under review.', 5500);
    }, toastDelay);
    toastDelay += TOAST_GAP;
    <?php endif; ?>

    <?php if (($commissions['unpaid_commission'] ?? 0) > 0): ?>
    setTimeout(function() {
        showToast('success', 'Pending Commissions',
            'You have ₱<?= number_format($commissions['unpaid_commission'], 0) ?> in pending commissions.', 5500);
    }, toastDelay);
    toastDelay += TOAST_GAP;
    <?php endif; ?>

    <?php if (($stats['active_listings'] ?? 0) == 0 && ($stats['total_listings'] ?? 0) == 0): ?>
    setTimeout(function() {
        showToast('info', 'Get Started',
            'Add your first property listing to begin building your portfolio!', 7000);
    }, toastDelay);
    toastDelay += TOAST_GAP;
    <?php endif; ?>
});
</script>

<!-- SKELETON HYDRATION — Progressive Content Reveal (Agent Portal) -->
<script>
(function () {
    'use strict';

    var MIN_SKELETON_MS = 400;
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

        if (remaining > 0) {
            window.setTimeout(hydrate, remaining);
        } else {
            hydrate();
        }
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