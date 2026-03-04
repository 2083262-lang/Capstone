<?php
session_start();
include '../connection.php';
require_once __DIR__ . '/../config/paths.php';

// Check if the user is logged in AND their role is 'agent'
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'agent') {
    header("Location: ../login.php");
    exit();
}

$agent_account_id = $_SESSION['account_id'];
$agent_username = $_SESSION['username'];

// Fetch comprehensive agent data
$agent_query = "SELECT a.account_id, a.first_name, a.middle_name, a.last_name, a.phone_number, a.email, a.username, a.date_registered,
                       ai.license_number, COALESCE((SELECT GROUP_CONCAT(s.specialization_name ORDER BY s.specialization_name SEPARATOR ', ') FROM agent_specializations asp JOIN specializations s ON asp.specialization_id = s.specialization_id WHERE asp.agent_info_id = ai.agent_info_id), '') AS specialization, ai.years_experience, ai.bio, ai.profile_picture_url, ai.profile_completed, ai.is_approved
                FROM accounts a
                LEFT JOIN agent_information ai ON a.account_id = ai.account_id
                WHERE a.account_id = ?";
$stmt = $conn->prepare($agent_query);
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$agent_info = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Property stats
$stats_query = "SELECT 
    COUNT(*) as total_listings,
    COUNT(CASE WHEN approval_status = 'approved' AND Status NOT IN ('Sold','Pending Sold') THEN 1 END) as active_listings,
    COUNT(CASE WHEN Status = 'Sold' THEN 1 END) as total_sold,
    COALESCE(SUM(CASE WHEN approval_status = 'approved' THEN ViewsCount ELSE 0 END), 0) as total_views
    FROM property 
    WHERE property_ID IN (
        SELECT property_id FROM property_log WHERE account_id = ? AND action = 'CREATED'
    )";
$stmt = $conn->prepare($stats_query);
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Tour stats
$tour_stats_query = "SELECT 
    COUNT(CASE WHEN request_status = 'Completed' THEN 1 END) as completed_tours,
    COUNT(*) as total_tours
    FROM tour_requests 
    WHERE agent_account_id = ?";
$stmt = $conn->prepare($tour_stats_query);
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$tour_stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Commission stats
$commission_query = "SELECT 
    COALESCE(SUM(commission_amount), 0) as total_commission,
    COALESCE(SUM(CASE WHEN status = 'paid' THEN commission_amount ELSE 0 END), 0) as paid_commission
    FROM agent_commissions 
    WHERE agent_id = ?";
$stmt = $conn->prepare($commission_query);
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$commissions = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Sales volume
$sales_vol_query = "SELECT COALESCE(SUM(final_sale_price), 0) as total_sales_volume, COUNT(sale_id) as finalized_count
    FROM finalized_sales WHERE agent_id = ?";
$stmt = $conn->prepare($sales_vol_query);
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$sales_vol = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Load specialization options from DB (for the edit form)
$spec_result = $conn->query("SELECT specialization_id, specialization_name FROM specializations ORDER BY specialization_name ASC");
$specialization_options = [];
if ($spec_result) {
    while ($spec_row = $spec_result->fetch_assoc()) {
        $specialization_options[] = $spec_row['specialization_name'];
    }
}

$conn->close();

// Build profile image URL
$raw_profile = $agent_info['profile_picture_url'] ?? '';
$profile_image_src = BASE_URL . 'images/placeholder-avatar.svg';
if (!empty($raw_profile)) {
    if (preg_match('/^https?:\/\//i', $raw_profile)) {
        $profile_image_src = $raw_profile;
    } elseif (strpos($raw_profile, '/') === 0) {
        $profile_image_src = '..' . $raw_profile;
    } else {
        $profile_image_src = '../' . $raw_profile;
    }
}

// Helper values
$full_name = trim(($agent_info['first_name'] ?? '') . ' ' . ($agent_info['middle_name'] ?? '') . ' ' . ($agent_info['last_name'] ?? ''));
$member_since = isset($agent_info['date_registered']) ? date('F Y', strtotime($agent_info['date_registered'])) : 'N/A';
$member_since_full = isset($agent_info['date_registered']) ? date('F d, Y', strtotime($agent_info['date_registered'])) : 'N/A';
$specializations = !empty($agent_info['specialization']) ? array_map('trim', explode(',', $agent_info['specialization'])) : [];

// Specialization options for edit form (loaded from DB above)

$active_page = 'agent_profile.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - HomeEstate Realty</title>
    <link href="<?= ASSETS_CSS ?>bootstrap.min.css" rel="stylesheet">
    <link href="<?= ASSETS_CSS ?>inter-font.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= ASSETS_CSS ?>fontawesome-all.min.css">
    <link rel="stylesheet" href="<?= ASSETS_CSS ?>bootstrap-icons.min.css">

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

        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: rgba(26, 26, 26, 0.4); }
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, var(--gold), var(--gold-dark));
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, var(--gold-light), var(--gold));
        }

        .profile-content {
            padding: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* ===== PROFILE HERO / COVER ===== */
        .profile-hero {
            position: relative;
            background: linear-gradient(135deg, var(--black) 0%, var(--black-lighter) 50%, var(--black) 100%);
            border: 1px solid var(--card-border);
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .profile-hero::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background:
                radial-gradient(ellipse at 20% 50%, rgba(212, 175, 55, 0.06) 0%, transparent 50%),
                radial-gradient(ellipse at 80% 20%, rgba(37, 99, 235, 0.08) 0%, transparent 50%),
                radial-gradient(ellipse at 60% 80%, rgba(212, 175, 55, 0.04) 0%, transparent 40%);
            pointer-events: none;
        }

        .profile-hero::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, transparent, var(--gold), var(--blue), var(--gold), transparent);
        }

        .profile-hero-inner {
            position: relative;
            z-index: 2;
            display: flex;
            align-items: center;
            gap: 2.5rem;
            padding: 3rem;
        }

        /* Profile Avatar */
        .profile-avatar-wrapper {
            position: relative;
            flex-shrink: 0;
        }

        .profile-avatar {
            width: 160px;
            height: 160px;
            border-radius: 4px;
            object-fit: cover;
            border: 3px solid rgba(212, 175, 55, 0.4);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(212, 175, 55, 0.15);
            transition: all 0.4s ease;
        }

        .profile-avatar:hover {
            border-color: var(--gold);
            box-shadow: 0 12px 40px rgba(212, 175, 55, 0.2), 0 0 30px rgba(212, 175, 55, 0.1);
            transform: scale(1.02);
        }

        .profile-status-badge {
            position: absolute;
            bottom: 8px;
            right: 8px;
            padding: 4px 12px;
            border-radius: 2px;
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }

        .profile-status-badge.approved {
            background: linear-gradient(135deg, #065f46, #047857);
            color: #34d399;
            border: 1px solid rgba(52, 211, 153, 0.3);
        }

        .profile-status-badge.pending {
            background: linear-gradient(135deg, #78350f, #92400e);
            color: #fbbf24;
            border: 1px solid rgba(251, 191, 36, 0.3);
        }

        /* Profile Info */
        .profile-hero-info {
            flex: 1;
        }

        .profile-hero-name {
            font-size: 2.2rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--white) 0%, var(--gray-100) 40%, var(--gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.25rem;
            line-height: 1.2;
        }

        .profile-hero-title {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .profile-hero-title .role-badge {
            font-size: 0.6rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--black);
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 50%, var(--gold-dark) 100%);
            padding: 3px 10px;
            border-radius: 2px;
        }

        .profile-hero-title .license-text {
            font-size: 0.85rem;
            color: var(--gray-400);
            font-weight: 500;
        }

        .profile-hero-title .license-text i {
            color: var(--blue-light);
            margin-right: 4px;
        }

        .profile-hero-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            margin-bottom: 1.25rem;
        }

        .profile-hero-meta .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            color: var(--gray-300);
        }

        .profile-hero-meta .meta-item i {
            color: var(--gold);
            font-size: 0.85rem;
            width: 18px;
            text-align: center;
        }

        .profile-hero-meta .meta-item a {
            color: var(--blue-light);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .profile-hero-meta .meta-item a:hover {
            color: var(--gold);
        }

        .profile-specializations {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .spec-tag {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 14px;
            background: rgba(37, 99, 235, 0.08);
            border: 1px solid rgba(37, 99, 235, 0.2);
            border-radius: 2px;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--blue-light);
            transition: all 0.3s ease;
        }

        .spec-tag:hover {
            background: rgba(37, 99, 235, 0.15);
            border-color: rgba(37, 99, 235, 0.4);
            transform: translateY(-1px);
        }

        .profile-hero-actions {
            margin-top: 1.5rem;
            display: flex;
            gap: 0.75rem;
        }

        .btn-edit-profile {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.65rem 1.5rem;
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 50%, var(--gold-dark) 100%);
            color: var(--black);
            border: none;
            border-radius: 2px;
            font-weight: 700;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            box-shadow: 0 4px 16px rgba(212, 175, 55, 0.3);
        }

        .btn-edit-profile:hover {
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 50%, var(--gold) 100%);
            box-shadow: 0 8px 28px rgba(212, 175, 55, 0.4), 0 0 15px rgba(212, 175, 55, 0.2);
            transform: translateY(-2px);
            color: var(--black);
        }

        .btn-edit-profile:active {
            transform: translateY(0);
        }

        /* ===== PERFORMANCE STATS BAR ===== */
        .stats-bar {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 4px;
            padding: 1.25rem 1.5rem;
            text-align: center;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--blue), transparent);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .stat-card:hover {
            border-color: var(--card-hover-border);
            transform: translateY(-3px);
            box-shadow: 0 8px 24px rgba(37, 99, 235, 0.12);
        }

        .stat-card:hover::before { opacity: 1; }

        .stat-card .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 4px;
            margin: 0 auto 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }

        .stat-icon.gold {
            background: linear-gradient(135deg, rgba(212, 175, 55, 0.1) 0%, rgba(212, 175, 55, 0.2) 100%);
            color: var(--gold);
            border: 1px solid rgba(212, 175, 55, 0.2);
        }

        .stat-icon.blue {
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.1) 0%, rgba(37, 99, 235, 0.2) 100%);
            color: var(--blue-light);
            border: 1px solid rgba(37, 99, 235, 0.2);
        }

        .stat-icon.green {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.1) 0%, rgba(34, 197, 94, 0.2) 100%);
            color: #22c55e;
            border: 1px solid rgba(34, 197, 94, 0.2);
        }

        .stat-icon.purple {
            background: linear-gradient(135deg, rgba(168, 85, 247, 0.1) 0%, rgba(168, 85, 247, 0.2) 100%);
            color: #a855f7;
            border: 1px solid rgba(168, 85, 247, 0.2);
        }

        .stat-card .stat-value {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--white);
            margin-bottom: 0.15rem;
        }

        .stat-card .stat-label {
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--gray-400);
        }

        /* ===== PROFILE SECTIONS (2-col layout) ===== */
        .profile-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .profile-panel {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 4px;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .profile-panel:hover {
            border-color: rgba(37, 99, 235, 0.25);
        }

        .profile-panel.full-width {
            grid-column: 1 / -1;
        }

        .profile-panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid rgba(37, 99, 235, 0.1);
        }

        .profile-panel-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--white);
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .profile-panel-title i {
            color: var(--gold);
        }

        .profile-panel-body {
            padding: 1.5rem;
        }

        /* Info Row */
        .info-row {
            display: flex;
            align-items: flex-start;
            padding: 0.85rem 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
        }

        .info-row:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .info-row:first-child {
            padding-top: 0;
        }

        .info-label {
            width: 140px;
            flex-shrink: 0;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--gray-400);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-label i {
            color: var(--blue-light);
            font-size: 0.8rem;
            width: 16px;
            text-align: center;
        }

        .info-value {
            font-size: 0.95rem;
            color: var(--gray-100);
            font-weight: 500;
            flex: 1;
            word-break: break-word;
        }

        .info-value.email-link {
            color: var(--blue-light);
        }

        /* Bio Section */
        .bio-text {
            font-size: 0.95rem;
            line-height: 1.8;
            color: var(--gray-200);
            font-weight: 400;
            text-align: justify;
        }

        .bio-text::first-letter {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--gold);
            float: left;
            margin-right: 4px;
            line-height: 1;
        }

        /* ===== EDIT PROFILE MODAL ===== */
        .edit-modal .modal-content {
            background: linear-gradient(180deg, #141414 0%, #0f0f0f 100%);
            border: 1px solid rgba(212, 175, 55, 0.2);
            border-radius: 4px;
            box-shadow: 0 24px 64px rgba(0, 0, 0, 0.8);
            color: var(--white);
        }

        .edit-modal .modal-header {
            background: linear-gradient(135deg, var(--black) 0%, var(--black-lighter) 100%);
            border-bottom: 1px solid rgba(212, 175, 55, 0.15);
            padding: 1.5rem 2rem;
            position: relative;
        }

        .edit-modal .modal-header::after {
            content: '';
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent);
        }

        .edit-modal .modal-title {
            font-weight: 800;
            font-size: 1.3rem;
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .edit-modal .modal-title i {
            -webkit-text-fill-color: var(--gold);
        }

        .edit-modal .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
            opacity: 0.5;
        }

        .edit-modal .btn-close:hover {
            opacity: 1;
        }

        .edit-modal .modal-body {
            padding: 2rem;
            max-height: 70vh;
            overflow-y: auto;
        }

        .edit-modal .modal-body::-webkit-scrollbar { width: 6px; }
        .edit-modal .modal-body::-webkit-scrollbar-track { background: transparent; }
        .edit-modal .modal-body::-webkit-scrollbar-thumb { background: rgba(212, 175, 55, 0.3); border-radius: 3px; }

        .edit-modal .modal-footer {
            border-top: 1px solid rgba(37, 99, 235, 0.1);
            padding: 1.25rem 2rem;
            background: rgba(10, 10, 10, 0.5);
        }

        /* Form styling in modal */
        .edit-modal .form-label {
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--gray-300);
            margin-bottom: 0.4rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .edit-modal .form-label i {
            color: var(--gold);
            margin-right: 4px;
        }

        .edit-modal .form-control,
        .edit-modal .form-select {
            background: rgba(26, 26, 26, 0.8);
            border: 1px solid rgba(37, 99, 235, 0.2);
            border-radius: 2px;
            color: var(--white);
            padding: 0.7rem 1rem;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }

        .edit-modal .form-control:focus,
        .edit-modal .form-select:focus {
            border-color: var(--gold);
            box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.1);
            background: rgba(26, 26, 26, 1);
            color: var(--white);
        }

        .edit-modal .form-control::placeholder {
            color: var(--gray-600);
        }

        .edit-modal textarea.form-control {
            resize: vertical;
            min-height: 120px;
        }

        .edit-modal .form-text {
            color: var(--gray-500);
            font-size: 0.78rem;
        }

        .edit-modal .form-section-title {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--gold);
            text-transform: uppercase;
            letter-spacing: 1.5px;
            padding-bottom: 0.5rem;
            margin-bottom: 1rem;
            border-bottom: 1px solid rgba(212, 175, 55, 0.15);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Specialization Checkboxes */
        .spec-checkbox-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.5rem;
        }

        .spec-checkbox-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0.75rem;
            background: rgba(26, 26, 26, 0.6);
            border: 1px solid rgba(37, 99, 235, 0.1);
            border-radius: 2px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .spec-checkbox-item:hover {
            border-color: rgba(37, 99, 235, 0.3);
            background: rgba(37, 99, 235, 0.05);
        }

        .spec-checkbox-item input[type="checkbox"] {
            accent-color: var(--gold);
            width: 16px;
            height: 16px;
            cursor: pointer;
        }

        .spec-checkbox-item label {
            font-size: 0.85rem;
            color: var(--gray-300);
            cursor: pointer;
            margin: 0;
            font-weight: 500;
        }

        .spec-checkbox-item input[type="checkbox"]:checked + label {
            color: var(--gold);
        }

        /* Profile picture upload preview */
        .avatar-upload-zone {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .avatar-preview {
            width: 100px;
            height: 100px;
            border-radius: 4px;
            object-fit: cover;
            border: 2px solid rgba(212, 175, 55, 0.3);
            background: rgba(26, 26, 26, 0.8);
        }

        .avatar-upload-info {
            flex: 1;
        }

        .avatar-upload-info .btn-choose-file {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.5rem 1rem;
            background: rgba(37, 99, 235, 0.1);
            border: 1px solid rgba(37, 99, 235, 0.25);
            border-radius: 2px;
            color: var(--blue-light);
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .avatar-upload-info .btn-choose-file:hover {
            background: rgba(37, 99, 235, 0.2);
            border-color: var(--blue-light);
        }

        .btn-save-profile {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.65rem 1.75rem;
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 50%, var(--gold-dark) 100%);
            color: var(--black);
            border: none;
            border-radius: 2px;
            font-weight: 700;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 16px rgba(212, 175, 55, 0.3);
        }

        .btn-save-profile:hover {
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 50%, var(--gold) 100%);
            box-shadow: 0 8px 28px rgba(212, 175, 55, 0.4);
            transform: translateY(-1px);
            color: var(--black);
        }

        .btn-cancel-edit {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.65rem 1.5rem;
            background: transparent;
            color: var(--gray-400);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 2px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-cancel-edit:hover {
            border-color: var(--gray-600);
            color: var(--gray-200);
            background: rgba(255, 255, 255, 0.03);
        }

        /* Toast / Alert */
        .profile-alert {
            position: fixed;
            top: 80px;
            right: 20px;
            z-index: 9999;
            min-width: 320px;
            max-width: 450px;
            border-radius: 4px;
            padding: 1rem 1.5rem;
            font-weight: 600;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.5);
            animation: slideInToast 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            transition: all 0.4s ease;
        }

        .profile-alert.success {
            background: linear-gradient(135deg, #065f46, #047857);
            border: 1px solid rgba(52, 211, 153, 0.3);
            color: #d1fae5;
        }

        .profile-alert.error {
            background: linear-gradient(135deg, #7f1d1d, #991b1b);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fecaca;
        }

        .profile-alert .alert-close {
            background: none;
            border: none;
            color: inherit;
            opacity: 0.7;
            cursor: pointer;
            margin-left: auto;
            font-size: 1.1rem;
        }

        .profile-alert .alert-close:hover { opacity: 1; }

        @keyframes slideInToast {
            from { opacity: 0; transform: translateX(40px); }
            to   { opacity: 1; transform: translateX(0); }
        }

        /* Loading spinner on save */
        .btn-save-profile .spinner-border {
            width: 1rem;
            height: 1rem;
            border-width: 0.15rem;
            display: none;
        }

        .btn-save-profile.saving .spinner-border {
            display: inline-block;
        }

        .btn-save-profile.saving .btn-text {
            display: none;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 992px) {
            .stats-bar {
                grid-template-columns: repeat(3, 1fr);
            }
            .profile-grid {
                grid-template-columns: 1fr;
            }
            .spec-checkbox-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .profile-hero-inner {
                flex-direction: column;
                text-align: center;
                padding: 2rem 1.5rem;
                gap: 1.5rem;
            }
            .profile-hero-meta {
                justify-content: center;
            }
            .profile-specializations {
                justify-content: center;
            }
            .profile-hero-actions {
                justify-content: center;
            }
            .profile-hero-name {
                font-size: 1.75rem;
            }
            .profile-hero-title {
                justify-content: center;
            }
            .stats-bar {
                grid-template-columns: repeat(2, 1fr);
            }
            .profile-content {
                padding: 1rem;
            }
            .avatar-upload-zone {
                flex-direction: column;
                align-items: flex-start;
            }
            .spec-checkbox-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .profile-avatar {
                width: 120px;
                height: 120px;
            }
            .stats-bar {
                grid-template-columns: 1fr;
            }
        }

        /* ================================================================
           SKELETON SCREEN SYSTEM — Dark Agent Portal Theme
           ================================================================ */
        @keyframes sk-shimmer {
            0%   { background-position: -800px 0; }
            100% { background-position:  800px 0; }
        }
        .sk-shimmer {
            background: linear-gradient(
                90deg,
                rgba(255,255,255,0.03) 25%,
                rgba(255,255,255,0.06) 50%,
                rgba(255,255,255,0.03) 75%
            );
            background-size: 1600px 100%;
            animation: sk-shimmer 1.6s ease-in-out infinite;
            border-radius: 4px;
        }
        #page-content { display: none; }

        /* Profile hero skeleton (mirrors .profile-hero) */
        .sk-profile-hero {
            background: linear-gradient(135deg, #0a0a0a 0%, #1a1a1a 100%);
            border: 1px solid rgba(37,99,235,0.15);
            border-radius: 4px;
            padding: 3rem;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 2.5rem;
            position: relative;
            overflow: hidden;
        }
        .sk-profile-hero::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, transparent, #d4af37, #2563eb, #d4af37, transparent);
        }

        /* Stats bar skeleton (5-col, mirrors .stats-bar) */
        .sk-stats-bar {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .sk-stat-card {
            background: linear-gradient(135deg, rgba(26,26,26,0.8) 0%, rgba(10,10,10,0.9) 100%);
            border: 1px solid rgba(37,99,235,0.15);
            border-radius: 4px;
            padding: 1.25rem 1.5rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.75rem;
        }

        /* Profile grid skeleton (mirrors .profile-grid 2-col) */
        .sk-profile-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .sk-profile-panel {
            background: linear-gradient(135deg, rgba(26,26,26,0.8) 0%, rgba(10,10,10,0.9) 100%);
            border: 1px solid rgba(37,99,235,0.15);
            border-radius: 4px;
            overflow: hidden;
        }
        .sk-panel-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid rgba(37,99,235,0.1);
        }
        .sk-panel-body { padding: 1.25rem; }
        .sk-info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.6rem 0;
            border-bottom: 1px solid rgba(37,99,235,0.06);
        }
        .sk-info-row:last-child { border-bottom: none; }
        .sk-line { display: block; border-radius: 4px; }

        /* Responsive */
        @media (max-width: 1024px) {
            .sk-stats-bar { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 768px) {
            .sk-stats-bar    { grid-template-columns: repeat(2, 1fr); }
            .sk-profile-grid { grid-template-columns: 1fr; }
            .sk-profile-hero { flex-direction: column; align-items: flex-start; padding: 1.5rem; }
        }
        @media (max-width: 576px) {
            .sk-stats-bar { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<?php include 'agent_navbar.php'; ?>

<noscript><style>
    #sk-screen    { display: none !important; }
    #page-content { display: block !important; opacity: 1 !important; }
</style></noscript>

<div id="sk-screen" role="presentation" aria-hidden="true">

    <div class="profile-content">

        <!-- Skeleton: Profile Hero -->
        <div class="sk-profile-hero">
            <!-- Avatar placeholder -->
            <div class="sk-shimmer" style="width:160px;height:160px;border-radius:4px;flex-shrink:0;"></div>
            <!-- Info placeholder -->
            <div style="flex:1;">
                <div class="sk-line sk-shimmer" style="width:300px;height:28px;margin-bottom:0.6rem;"></div>
                <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1rem;">
                    <div class="sk-shimmer" style="width:90px;height:18px;border-radius:2px;"></div>
                    <div class="sk-shimmer" style="width:150px;height:14px;border-radius:3px;"></div>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:1.5rem;margin-bottom:1.25rem;">
                    <div class="sk-shimmer" style="width:180px;height:13px;border-radius:3px;"></div>
                    <div class="sk-shimmer" style="width:120px;height:13px;border-radius:3px;"></div>
                    <div class="sk-shimmer" style="width:140px;height:13px;border-radius:3px;"></div>
                </div>
                <div style="display:flex;gap:0.5rem;margin-bottom:1.25rem;">
                    <div class="sk-shimmer" style="width:90px;height:24px;border-radius:3px;"></div>
                    <div class="sk-shimmer" style="width:110px;height:24px;border-radius:3px;"></div>
                    <div class="sk-shimmer" style="width:80px;height:24px;border-radius:3px;"></div>
                </div>
                <div class="sk-shimmer" style="width:130px;height:36px;border-radius:4px;"></div>
            </div>
        </div>

        <!-- Skeleton: Stats Bar (5 cards) -->
        <div class="sk-stats-bar">
            <?php for ($i = 0; $i < 5; $i++): ?>
            <div class="sk-stat-card">
                <div class="sk-shimmer" style="width:40px;height:40px;border-radius:4px;"></div>
                <div class="sk-line sk-shimmer" style="width:50%;height:22px;"></div>
                <div class="sk-line sk-shimmer" style="width:75%;height:11px;"></div>
            </div>
            <?php endfor; ?>
        </div>

        <!-- Skeleton: Profile Grid (3 panels in 2-col layout) -->
        <div class="sk-profile-grid">
            <?php for ($i = 0; $i < 3; $i++): ?>
            <div class="sk-profile-panel">
                <div class="sk-panel-header">
                    <div class="sk-line sk-shimmer" style="width:160px;height:15px;"></div>
                </div>
                <div class="sk-panel-body">
                    <?php for ($j = 0; $j < 5; $j++): ?>
                    <div class="sk-info-row">
                        <div class="sk-shimmer" style="width:35%;height:12px;border-radius:3px;"></div>
                        <div class="sk-shimmer" style="width:50%;height:12px;border-radius:3px;"></div>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endfor; ?>
        </div>

    </div>

</div><!-- /#sk-screen -->

<div id="page-content">
<div class="profile-content">

    <!-- ===== PROFILE HERO ===== -->
    <div class="profile-hero">
        <div class="profile-hero-inner">
            <!-- Avatar -->
            <div class="profile-avatar-wrapper">
                <img src="<?php echo htmlspecialchars($profile_image_src); ?>" alt="Agent Profile Photo" class="profile-avatar"
                     onerror="this.onerror=null;this.src='<?= BASE_URL ?>images/placeholder-avatar.svg';">
                <span class="profile-status-badge <?php echo ($agent_info['is_approved'] ?? 0) ? 'approved' : 'pending'; ?>">
                    <?php echo ($agent_info['is_approved'] ?? 0) ? 'Verified' : 'Pending Verification'; ?>
                </span>
            </div>

            <!-- Info -->
            <div class="profile-hero-info">
                <h1 class="profile-hero-name"><?php echo htmlspecialchars($full_name); ?></h1>
                <div class="profile-hero-title">
                    <span class="role-badge">Licensed Agent</span>
                    <?php if (!empty($agent_info['license_number'])): ?>
                        <span class="license-text"><i class="bi bi-patch-check-fill"></i> <?php echo htmlspecialchars($agent_info['license_number']); ?></span>
                    <?php endif; ?>
                </div>
                <div class="profile-hero-meta">
                    <div class="meta-item">
                        <i class="bi bi-envelope-fill"></i>
                        <a href="mailto:<?php echo htmlspecialchars($agent_info['email']); ?>"><?php echo htmlspecialchars($agent_info['email']); ?></a>
                    </div>
                    <div class="meta-item">
                        <i class="bi bi-telephone-fill"></i>
                        <?php echo htmlspecialchars($agent_info['phone_number'] ?? 'Not provided'); ?>
                    </div>
                    <div class="meta-item">
                        <i class="bi bi-calendar3"></i>
                        Member since <?php echo $member_since; ?>
                    </div>
                    <?php if (!empty($agent_info['years_experience'])): ?>
                        <div class="meta-item">
                            <i class="bi bi-award-fill"></i>
                            <?php echo (int)$agent_info['years_experience']; ?> Year<?php echo ((int)$agent_info['years_experience'] !== 1) ? 's' : ''; ?> Experience
                        </div>
                    <?php endif; ?>
                </div>
                <div class="profile-specializations">
                    <?php if (!empty($specializations)): ?>
                        <?php foreach ($specializations as $spec): ?>
                            <span class="spec-tag"><i class="bi bi-star-fill" style="font-size: 0.55rem;"></i> <?php echo htmlspecialchars($spec); ?></span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span class="spec-tag" style="color: var(--gray-500); border-color: rgba(255,255,255,0.08);">No specializations set</span>
                    <?php endif; ?>
                </div>
                <div class="profile-hero-actions">
                    <button class="btn-edit-profile" data-bs-toggle="modal" data-bs-target="#editProfileModal">
                        <i class="bi bi-pencil-square"></i> Edit Profile
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== PERFORMANCE STATS ===== -->
    <div class="stats-bar">
        <div class="stat-card">
            <div class="stat-icon gold"><i class="bi bi-building"></i></div>
            <div class="stat-value"><?php echo (int)$stats['active_listings']; ?></div>
            <div class="stat-label">Active Listings</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
            <div class="stat-value"><?php echo (int)$stats['total_sold']; ?></div>
            <div class="stat-label">Properties Sold</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon blue"><i class="bi bi-eye-fill"></i></div>
            <div class="stat-value"><?php echo number_format((int)$stats['total_views']); ?></div>
            <div class="stat-label">Total Views</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon purple"><i class="bi bi-calendar-check-fill"></i></div>
            <div class="stat-value"><?php echo (int)$tour_stats['completed_tours']; ?></div>
            <div class="stat-label">Tours Completed</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon gold"><i class="bi bi-cash-stack"></i></div>
            <div class="stat-value">₱<?php echo number_format((float)$sales_vol['total_sales_volume'], 0); ?></div>
            <div class="stat-label">Sales Volume</div>
        </div>
    </div>

    <!-- ===== DETAILS SECTIONS ===== -->
    <div class="profile-grid">

        <!-- Personal Information -->
        <div class="profile-panel">
            <div class="profile-panel-header">
                <div class="profile-panel-title"><i class="bi bi-person-vcard"></i> Personal Information</div>
            </div>
            <div class="profile-panel-body">
                <div class="info-row">
                    <div class="info-label"><i class="bi bi-person"></i> Full Name</div>
                    <div class="info-value"><?php echo htmlspecialchars($full_name); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label"><i class="bi bi-at"></i> Username</div>
                    <div class="info-value"><?php echo htmlspecialchars($agent_info['username']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label"><i class="bi bi-envelope"></i> Email</div>
                    <div class="info-value email-link"><?php echo htmlspecialchars($agent_info['email']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label"><i class="bi bi-phone"></i> Phone</div>
                    <div class="info-value"><?php echo htmlspecialchars($agent_info['phone_number'] ?? 'Not provided'); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label"><i class="bi bi-calendar3"></i> Joined</div>
                    <div class="info-value"><?php echo $member_since_full; ?></div>
                </div>
            </div>
        </div>

        <!-- Professional Details -->
        <div class="profile-panel">
            <div class="profile-panel-header">
                <div class="profile-panel-title"><i class="bi bi-briefcase"></i> Professional Details</div>
            </div>
            <div class="profile-panel-body">
                <div class="info-row">
                    <div class="info-label"><i class="bi bi-patch-check"></i> License #</div>
                    <div class="info-value"><?php echo htmlspecialchars($agent_info['license_number'] ?? 'Not set'); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label"><i class="bi bi-award"></i> Experience</div>
                    <div class="info-value">
                        <?php
                            $yrs = (int)($agent_info['years_experience'] ?? 0);
                            echo $yrs > 0 ? $yrs . ' Year' . ($yrs !== 1 ? 's' : '') : 'Not specified';
                        ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label"><i class="bi bi-shield-check"></i> Status</div>
                    <div class="info-value">
                        <?php if ($agent_info['is_approved'] ?? 0): ?>
                            <span style="color: #34d399; font-weight: 600;"><i class="bi bi-check-circle-fill me-1"></i>Verified & Approved</span>
                        <?php else: ?>
                            <span style="color: #fbbf24; font-weight: 600;"><i class="bi bi-clock-fill me-1"></i>Pending Verification</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label"><i class="bi bi-graph-up"></i> Listings</div>
                    <div class="info-value"><?php echo (int)$stats['total_listings']; ?> total &bull; <?php echo (int)$stats['active_listings']; ?> active</div>
                </div>
                <div class="info-row">
                    <div class="info-label"><i class="bi bi-wallet2"></i> Commission</div>
                    <div class="info-value">₱<?php echo number_format((float)$commissions['total_commission'], 2); ?></div>
                </div>
            </div>
        </div>

        <!-- About / Bio -->
        <div class="profile-panel full-width">
            <div class="profile-panel-header">
                <div class="profile-panel-title"><i class="bi bi-chat-quote"></i> About Me</div>
            </div>
            <div class="profile-panel-body">
                <?php if (!empty($agent_info['bio'])): ?>
                    <div class="bio-text"><?php echo nl2br(htmlspecialchars($agent_info['bio'])); ?></div>
                <?php else: ?>
                    <div style="color: var(--gray-500); font-style: italic;">No biography provided yet. Click "Edit Profile" to add your professional story.</div>
                <?php endif; ?>
            </div>
        </div>

    </div>

</div><!-- /.profile-content -->
</div><!-- /#page-content -->

<!-- ===== EDIT PROFILE MODAL ===== -->
<div class="modal fade edit-modal" id="editProfileModal" tabindex="-1" aria-labelledby="editProfileModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editProfileModalLabel"><i class="bi bi-pencil-square"></i> Edit Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editProfileForm" enctype="multipart/form-data">
                <div class="modal-body">

                    <!-- Profile Picture -->
                    <div class="form-section-title"><i class="bi bi-camera"></i> Profile Photo</div>
                    <div class="mb-4">
                        <div class="avatar-upload-zone">
                            <img id="avatarPreview" src="<?php echo htmlspecialchars($profile_image_src); ?>" alt="Preview" class="avatar-preview"
                                 onerror="this.onerror=null;this.src='<?= BASE_URL ?>images/placeholder-avatar.svg';">
                            <div class="avatar-upload-info">
                                <label class="btn-choose-file" for="profilePhotoInput">
                                    <i class="bi bi-cloud-arrow-up"></i> Choose Photo
                                </label>
                                <input type="file" id="profilePhotoInput" name="profile_picture" accept="image/jpeg,image/png,image/gif" style="display:none;">
                                <div class="form-text mt-2">JPG, PNG or GIF. Max 5MB. Recommended: 400×400px or larger.</div>
                            </div>
                        </div>
                    </div>

                    <!-- Personal Info -->
                    <div class="form-section-title"><i class="bi bi-person"></i> Personal Information</div>
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label"><i class="bi bi-person"></i> First Name</label>
                            <input type="text" class="form-control" name="first_name" value="<?php echo htmlspecialchars($agent_info['first_name'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><i class="bi bi-person"></i> Middle Name</label>
                            <input type="text" class="form-control" name="middle_name" value="<?php echo htmlspecialchars($agent_info['middle_name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><i class="bi bi-person"></i> Last Name</label>
                            <input type="text" class="form-control" name="last_name" value="<?php echo htmlspecialchars($agent_info['last_name'] ?? ''); ?>" required>
                        </div>
                    </div>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <label class="form-label"><i class="bi bi-envelope"></i> Email Address</label>
                            <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($agent_info['email'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><i class="bi bi-telephone"></i> Phone Number</label>
                            <input type="text" class="form-control" name="phone_number" value="<?php echo htmlspecialchars($agent_info['phone_number'] ?? ''); ?>" placeholder="+639XXXXXXXXX">
                        </div>
                    </div>

                    <!-- Professional Info -->
                    <div class="form-section-title"><i class="bi bi-briefcase"></i> Professional Information</div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><i class="bi bi-patch-check"></i> License Number</label>
                            <input type="text" class="form-control" name="license_number" value="<?php echo htmlspecialchars($agent_info['license_number'] ?? ''); ?>" required>
                            <div class="form-text">Your real estate broker/agent license number.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><i class="bi bi-clock-history"></i> Years of Experience</label>
                            <input type="number" class="form-control" name="years_experience" min="0" max="70" value="<?php echo (int)($agent_info['years_experience'] ?? 0); ?>">
                        </div>
                    </div>

                    <!-- Specializations -->
                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-stars"></i> Specializations</label>
                        <div class="spec-checkbox-grid">
                            <?php
                            $current_specs = array_map('trim', explode(',', $agent_info['specialization'] ?? ''));
                            foreach ($specialization_options as $opt):
                                $checked = in_array($opt, $current_specs) ? 'checked' : '';
                            ?>
                                <div class="spec-checkbox-item">
                                    <input type="checkbox" name="specialization[]" value="<?php echo htmlspecialchars($opt); ?>" id="spec_<?php echo md5($opt); ?>" <?php echo $checked; ?>>
                                    <label for="spec_<?php echo md5($opt); ?>"><?php echo htmlspecialchars($opt); ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text mt-1">Select all areas you specialize in.</div>
                    </div>

                    <!-- Bio -->
                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-chat-quote"></i> Professional Biography</label>
                        <textarea class="form-control" name="bio" rows="5" placeholder="Tell clients about your experience, approach, and what makes you stand out..."><?php echo htmlspecialchars($agent_info['bio'] ?? ''); ?></textarea>
                        <div class="form-text">Minimum 30 characters. This will be visible to potential clients.</div>
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel-edit" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg"></i> Cancel
                    </button>
                    <button type="submit" class="btn-save-profile" id="btnSaveProfile">
                        <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                        <span class="btn-text"><i class="bi bi-check-lg"></i> Save Changes</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'logout_agent_modal.php'; ?>

<script src="<?= ASSETS_JS ?>bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {

    // ===== Profile Photo Live Preview =====
    const photoInput = document.getElementById('profilePhotoInput');
    const avatarPreview = document.getElementById('avatarPreview');

    photoInput.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            const file = this.files[0];

            // Validate client-side
            const allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
            if (!allowedTypes.includes(file.type)) {
                showAlert('error', 'Invalid file type. Only JPG, PNG and GIF are allowed.');
                this.value = '';
                return;
            }
            if (file.size > 5 * 1024 * 1024) {
                showAlert('error', 'File size must be less than 5MB.');
                this.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onload = function(e) {
                avatarPreview.src = e.target.result;
            };
            reader.readAsDataURL(file);
        }
    });

    // ===== Form Submit via AJAX =====
    const editForm = document.getElementById('editProfileForm');
    const saveBtn = document.getElementById('btnSaveProfile');

    editForm.addEventListener('submit', function(e) {
        e.preventDefault();

        // Validate required fields
        const firstName = editForm.querySelector('[name="first_name"]').value.trim();
        const lastName = editForm.querySelector('[name="last_name"]').value.trim();
        const email = editForm.querySelector('[name="email"]').value.trim();
        const license = editForm.querySelector('[name="license_number"]').value.trim();
        const bio = editForm.querySelector('[name="bio"]').value.trim();

        if (!firstName || !lastName) {
            showAlert('error', 'First name and last name are required.');
            return;
        }
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            showAlert('error', 'Please enter a valid email address.');
            return;
        }
        if (!license || license.length < 5) {
            showAlert('error', 'License number must be at least 5 characters.');
            return;
        }
        if (bio && bio.length < 30) {
            showAlert('error', 'Biography must be at least 30 characters.');
            return;
        }

        // Check at least one specialization
        const specs = editForm.querySelectorAll('input[name="specialization[]"]:checked');
        if (specs.length === 0) {
            showAlert('error', 'Please select at least one specialization.');
            return;
        }

        // Set saving state
        saveBtn.classList.add('saving');
        saveBtn.disabled = true;

        const formData = new FormData(editForm);

        fetch('save_agent_profile.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            saveBtn.classList.remove('saving');
            saveBtn.disabled = false;

            if (data.success) {
                showAlert('success', data.message || 'Profile updated successfully!');
                // Close modal after brief delay and reload
                setTimeout(() => {
                    const modal = bootstrap.Modal.getInstance(document.getElementById('editProfileModal'));
                    if (modal) modal.hide();
                    location.reload();
                }, 1200);
            } else {
                showAlert('error', data.message || 'Failed to update profile.');
            }
        })
        .catch(error => {
            saveBtn.classList.remove('saving');
            saveBtn.disabled = false;
            showAlert('error', 'An error occurred. Please try again.');
            console.error('Profile update error:', error);
        });
    });

    // ===== Toast Alert =====
    function showAlert(type, message) {
        // Remove existing alerts
        document.querySelectorAll('.profile-alert').forEach(el => el.remove());

        const alert = document.createElement('div');
        alert.className = 'profile-alert ' + type;
        alert.innerHTML = `
            <i class="bi ${type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'}"></i>
            <span>${message}</span>
            <button class="alert-close" onclick="this.parentElement.remove()"><i class="bi bi-x-lg"></i></button>
        `;
        document.body.appendChild(alert);

        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (alert.parentElement) {
                alert.style.opacity = '0';
                alert.style.transform = 'translateX(40px)';
                setTimeout(() => alert.remove(), 400);
            }
        }, 5000);
    }

    // ===== Intersection Observer for fade-in animations =====
    // (Moved to skeleton:hydrated so elements animate into the visible content, not while skeleton is showing)

});
</script>

<!-- SKELETON HYDRATION — Progressive Content Reveal (Agent Portal) -->
<script>
(function () {
    'use strict';
    var MIN_SKELETON_MS = 400;
    var skeletonStart   = Date.now();
    function hydrate() {
        var elapsed   = Date.now() - skeletonStart;
        var remaining = Math.max(0, MIN_SKELETON_MS - elapsed);
        setTimeout(function () {
            var sk = document.getElementById('sk-screen');
            var pc = document.getElementById('page-content');
            if (!sk || !pc) return;
            pc.style.display    = 'block';
            pc.style.opacity    = '0';
            pc.style.transition = 'opacity 0.35s ease';
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    pc.style.opacity    = '1';
                    sk.style.transition = 'opacity 0.25s ease';
                    sk.style.opacity    = '0';
                    setTimeout(function () {
                        sk.style.display = 'none';
                        document.dispatchEvent(new CustomEvent('skeleton:hydrated'));
                    }, 260);
                });
            });
        }, remaining);
    }
    if (document.readyState === 'complete') { hydrate(); }
    else { window.addEventListener('load', hydrate); }
}());
</script>

<!-- FADE-IN ANIMATIONS — fire after skeleton hydrates -->
<script>
document.addEventListener('skeleton:hydrated', function () {
    var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1, rootMargin: '0px 0px -30px 0px' });

    document.querySelectorAll('.stat-card, .profile-panel').forEach(function (el, i) {
        el.style.opacity = '0';
        el.style.transform = 'translateY(15px)';
        el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
        el.style.transitionDelay = (i * 0.08) + 's';
        observer.observe(el);
    });
});
</script>
</body>
</html>
