<?php
session_start();
include 'connection.php';

// Check if the user is logged in AND their role is 'admin'
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: login.php");
    exit(); 
}

// Fetch all agents and their information, including the latest rejection reason
$sql = "SELECT
            a.account_id, a.first_name, a.middle_name, a.last_name,
            a.phone_number, a.email, a.date_registered, a.is_active,
            ai.license_number,
            COALESCE((SELECT GROUP_CONCAT(s.specialization_name ORDER BY s.specialization_name SEPARATOR ', ')
                      FROM agent_specializations asp
                      JOIN specializations s ON asp.specialization_id = s.specialization_id
                      WHERE asp.agent_info_id = ai.agent_info_id), '') AS specialization,
            ai.profile_picture_url,
            ai.profile_completed, ai.is_approved, ai.years_experience,
            (SELECT sl.reason_message 
             FROM status_log sl 
             WHERE sl.item_id = a.account_id 
               AND sl.item_type = 'agent' 
               AND sl.action = 'rejected' 
             ORDER BY sl.log_timestamp DESC 
             LIMIT 1) AS rejection_reason
        FROM
            accounts a
        LEFT JOIN
            agent_information ai ON a.account_id = ai.account_id
        WHERE
            a.role_id = 2
        ORDER BY
            a.date_registered DESC";

$result = $conn->query($sql);
$all_agents = $result->fetch_all(MYSQLI_ASSOC);

// Load specializations from DB for filter drawer
$spec_result = $conn->query("SELECT specialization_name FROM specializations ORDER BY specialization_name ASC");
$all_specializations = $spec_result ? $spec_result->fetch_all(MYSQLI_ASSOC) : [];

// Categorize agents
$agents_pending_approval = array_filter($all_agents, fn($agent) => $agent['profile_completed'] && !$agent['is_approved'] && $agent['is_active']);
$agents_approved = array_filter($all_agents, fn($agent) => $agent['profile_completed'] && $agent['is_approved'] && $agent['is_active']);
$agents_needs_profile = array_filter($all_agents, fn($agent) => !$agent['profile_completed']);
$agents_rejected = array_filter($all_agents, fn($agent) => !$agent['is_active'] && !empty($agent['rejection_reason']));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agent Management - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        /* ================================================
           ADMIN AGENT PAGE
           Structure matches property.php exactly:
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
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }

        .page-header .subtitle {
            color: var(--text-secondary);
            font-size: 0.95rem;
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

        .kpi-icon.green {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.06) 0%, rgba(34, 197, 94, 0.12) 100%);
            color: #16a34a;
            border: 1px solid rgba(34, 197, 94, 0.15);
        }

        .kpi-icon.amber {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.06) 0%, rgba(245, 158, 11, 0.12) 100%);
            color: #d97706;
            border: 1px solid rgba(245, 158, 11, 0.15);
        }

        .kpi-icon.red {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.06) 0%, rgba(239, 68, 68, 0.12) 100%);
            color: #dc2626;
            border: 1px solid rgba(239, 68, 68, 0.15);
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
            color: var(--text-secondary);
            margin-bottom: 0.25rem;
        }

        .kpi-card .kpi-value {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text-primary);
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
            color: var(--text-primary);
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
            align-items: center;
        }

        /* ===== SEARCH BAR ===== */
        .search-container {
            position: relative;
            width: 340px;
        }

        .search-container input {
            width: 100%;
            padding: 0.6rem 2.5rem 0.6rem 2.5rem;
            border-radius: 4px;
            border: 1px solid #e2e8f0;
            background: #fff;
            transition: all 0.2s ease;
            font-size: 0.85rem;
            color: var(--text-primary);
            font-weight: 500;
        }

        .search-container input::placeholder { color: #94a3b8; }

        .search-container input:focus {
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.08);
            outline: none;
        }

        .search-container .search-icon {
            position: absolute;
            left: 0.85rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 0.9rem;
            pointer-events: none;
        }

        .search-clear {
            position: absolute;
            right: 0.65rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            cursor: pointer;
            font-size: 0.85rem;
            display: none;
            background: none;
            border: none;
            padding: 0.2rem;
            line-height: 1;
        }

        .search-clear:hover { color: #dc2626; }

        /* ===== TABS ===== */
        .agent-tabs {
            background: var(--card-bg);
            border: 1px solid rgba(37, 99, 235, 0.1);
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 1.5rem;
            position: relative;
        }

        .agent-tabs::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent);
            z-index: 5;
        }

        .agent-tabs .nav-tabs {
            border-bottom: 1px solid #e2e8f0;
            padding: 0.25rem 0.5rem 0;
            gap: 0.25rem;
            background: linear-gradient(180deg, #fafbfc 0%, var(--card-bg) 100%);
        }

        .agent-tabs .nav-item {
            margin-bottom: 0;
        }

        .agent-tabs .nav-link {
            border: none;
            border-bottom: 3px solid transparent;
            background: transparent;
            color: var(--text-secondary);
            font-weight: 600;
            font-size: 0.85rem;
            padding: 0.85rem 1.25rem;
            border-radius: 0;
        }

        .agent-tabs .nav-link:hover {
            color: var(--text-primary);
            background: rgba(37, 99, 235, 0.03);
            border-bottom-color: rgba(37, 99, 235, 0.2);
        }

        .agent-tabs .nav-link.active {
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
        .badge-incomplete { background: rgba(6, 182, 212, 0.1); color: #0891b2; border: 1px solid rgba(6, 182, 212, 0.15); }

        .tab-content { padding: 1.5rem; }

        /* ===== AGENT CARD GRID ===== */
        .agents-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
            gap: 1.25rem;
        }

        /* ===== AGENT CARD ===== */
        .agent-card {
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

        .agent-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, var(--gold), var(--blue));
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: 5;
        }

        .agent-card:hover {
            border-color: rgba(37, 99, 235, 0.25);
            box-shadow: 0 8px 32px rgba(37, 99, 235, 0.08);
            transform: translateY(-4px);
        }

        .agent-card:hover::before { opacity: 1; }

        /* Card Header Section */
        .agent-card .card-header-section {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            padding: 1.25rem 1.25rem 1rem;
            position: relative;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .agent-card .card-header-section::after {
            content: '';
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--gold), transparent);
            opacity: 0.4;
        }

        .agent-card .agent-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--gold);
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            flex-shrink: 0;
        }

        .agent-card .agent-header-info {
            flex: 1;
            min-width: 0;
        }

        .agent-card .agent-name {
            font-weight: 700;
            font-size: 1rem;
            color: #fff;
            margin: 0 0 0.15rem 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .agent-card .agent-specialty-text {
            font-size: 0.72rem;
            color: rgba(255,255,255,0.55);
            margin: 0;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            line-height: 1.4;
        }

        .agent-card .status-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.2rem 0.6rem;
            border-radius: 2px;
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            z-index: 3;
        }

        .status-badge.approved { background: rgba(34, 197, 94, 0.9); color: #fff; }
        .status-badge.pending { background: rgba(245, 158, 11, 0.9); color: #fff; }
        .status-badge.rejected { background: rgba(239, 68, 68, 0.9); color: #fff; }
        .status-badge.incomplete { background: rgba(6, 182, 212, 0.9); color: #fff; }

        /* Card Body */
        .agent-card .card-body-content {
            padding: 1rem 1.25rem;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .agent-card .info-row {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.45rem 0;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.8rem;
        }

        .agent-card .info-row:last-of-type {
            border-bottom: none;
        }

        .agent-card .info-row i {
            color: var(--gold);
            font-size: 0.75rem;
            width: 16px;
            text-align: center;
            flex-shrink: 0;
        }

        .agent-card .info-label {
            color: var(--text-secondary);
            font-weight: 500;
            min-width: 75px;
            font-size: 0.78rem;
        }

        .agent-card .info-value {
            color: var(--text-primary);
            font-weight: 600;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            flex: 1;
            text-align: right;
            font-size: 0.8rem;
        }

        /* Rejection */
        .agent-card .rejection-box {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.06) 0%, rgba(239, 68, 68, 0.03) 100%);
            border-left: 3px solid #dc2626;
            border-radius: 4px;
            padding: 0.5rem 0.75rem;
            margin-top: 0.6rem;
            font-size: 0.72rem;
            color: #991b1b;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .agent-card .rejection-box strong { color: #dc2626; }

        /* Footer */
        .agent-card .card-footer-section {
            margin-top: auto;
            padding-top: 0.75rem;
            border-top: 1px solid #e2e8f0;
        }

        .agent-card .meta-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.3rem;
            font-size: 0.72rem;
            color: #94a3b8;
            margin-bottom: 0.6rem;
        }

        .agent-card .meta-row i { color: #cbd5e1; }

        .agent-card .btn-manage {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            width: 100%;
            background: linear-gradient(135deg, var(--blue-dark) 0%, var(--blue) 100%);
            color: #fff;
            border: none;
            padding: 0.55rem;
            font-size: 0.78rem;
            font-weight: 700;
            border-radius: 4px;
            text-decoration: none;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.2);
        }

        .agent-card .btn-manage:hover {
            box-shadow: 0 4px 16px rgba(37, 99, 235, 0.3);
            transform: translateY(-1px);
            color: #fff;
        }

        /* ===== EMPTY STATE ===== */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--text-secondary);
        }

        .empty-state i {
            font-size: 3rem;
            color: rgba(37, 99, 235, 0.15);
            margin-bottom: 1rem;
            display: block;
        }

        .empty-state h4 {
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            font-size: 0.9rem;
            color: #94a3b8;
        }

        /* ===== SEARCH RESULTS ===== */
        .search-results-info {
            display: none;
            padding: 0.6rem 1rem;
            background: rgba(37, 99, 235, 0.04);
            border: 1px solid rgba(37, 99, 235, 0.1);
            border-radius: 4px;
            margin-bottom: 1rem;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .search-results-info .count { color: var(--blue); font-weight: 700; }
        .search-results-info .term { color: var(--gold-dark); font-weight: 700; }

        .no-results {
            text-align: center;
            padding: 2rem 1rem;
            display: none;
        }

        .no-results i {
            font-size: 2.5rem;
            color: rgba(37, 99, 235, 0.15);
            margin-bottom: 0.75rem;
            display: block;
        }

        .no-results h5 {
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.3rem;
        }

        .no-results p {
            font-size: 0.85rem;
            color: #94a3b8;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 1400px) {
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 992px) {
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .agents-grid { grid-template-columns: 1fr; }
            .search-container { width: 100%; }
        }

        @media (max-width: 768px) {
            .admin-content { padding: 1rem; }
            .page-header { padding: 1.25rem 1rem; }
            .page-header h1 { font-size: 1.3rem; }
            .page-header-inner { flex-direction: column; align-items: flex-start; gap: 0.75rem; }
            .action-bar { flex-direction: column; align-items: flex-start; gap: 0.5rem; }
            .action-bar > * { width: 100%; }
            .search-container { width: 100%; }
            .kpi-grid { grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
            .kpi-card { padding: 1rem; }
            .kpi-card .kpi-value { font-size: 1.25rem; }
            .agent-tabs .nav-tabs { overflow-x: auto; flex-wrap: nowrap; -webkit-overflow-scrolling: touch; }
            .agent-tabs .nav-link { padding: 0.65rem 0.85rem; font-size: 0.8rem; white-space: nowrap; }
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
            .agent-tabs .nav-link { padding: 0.55rem 0.7rem; font-size: 0.75rem; }
            .tab-badge { display: none; }
            .agents-grid { grid-template-columns: 1fr; }
        }

        /* ===== FILTER BUTTON & BADGE ===== */
        .btn-outline-admin {
            background: var(--card-bg);
            color: var(--text-secondary);
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
            white-space: nowrap;
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

        /* ===== FILTER SIDEBAR (Agent) ===== */
        .filter-sidebar {
            position: fixed;
            top: 0; right: 0;
            width: 100%; height: 100%;
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

        .filter-sidebar.active .filter-sidebar-content { transform: translateX(0); }

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

        .filter-header h4 i { color: var(--gold); font-size: 1.3rem; }

        .btn-close-filter {
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            color: #fff;
            width: 36px; height: 36px;
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
            color: var(--text-primary);
            margin-bottom: 1rem;
            padding-bottom: 0.6rem;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .filter-section-title i { color: var(--gold); font-size: 1rem; }

        .filter-search-box { position: relative; }

        .filter-search-box input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.75rem;
            border-radius: 4px;
            border: 1px solid #e2e8f0;
            background: #fff;
            font-size: 0.875rem;
            color: var(--text-primary);
            transition: all 0.2s ease;
        }

        .filter-search-box input::placeholder { color: #94a3b8; }

        .filter-search-box input:focus {
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.08);
            outline: none;
        }

        .filter-search-box i {
            position: absolute;
            left: 0.85rem; top: 50%;
            transform: translateY(-50%);
            color: #94a3b8; font-size: 1rem;
            pointer-events: none;
        }

        .filter-chips { display: flex; flex-wrap: wrap; gap: 0.5rem; }

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
            color: var(--text-primary);
        }

        .filter-chip:hover { background: #f8fafc; border-color: var(--gold); }

        .filter-chip.active {
            background: linear-gradient(135deg, var(--gold-dark), var(--gold));
            color: #fff;
            border-color: var(--gold);
            font-weight: 600;
        }

        .filter-chip input[type="checkbox"] {
            width: 16px; height: 16px;
            cursor: pointer;
            accent-color: var(--gold);
        }

        .filter-range-pair {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            gap: 0.75rem;
            align-items: center;
        }

        .filter-range-pair input {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border-radius: 4px;
            border: 1px solid #e2e8f0;
            background: #fff;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-primary);
            transition: all 0.2s ease;
        }

        .filter-range-pair input:focus {
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.08);
            outline: none;
        }

        .filter-range-pair .range-divider { color: #94a3b8; font-weight: 600; text-align: center; }

        .quick-filters { display: flex; gap: 0.5rem; margin-top: 0.6rem; flex-wrap: wrap; }

        .quick-filter-btn {
            padding: 0.4rem 0.85rem;
            border: 1px solid #e2e8f0;
            background: #fff;
            border-radius: 2px;
            font-size: 0.75rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            color: var(--text-primary);
        }

        .quick-filter-btn:hover { border-color: var(--gold); background: #fffbeb; }

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

        .filter-footer .btn:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }

        .filter-footer .btn-outline-secondary {
            background: #fff;
            border: 1px solid #e2e8f0;
            color: var(--text-secondary);
        }

        .filter-footer .btn-outline-secondary:hover {
            border-color: rgba(239, 68, 68, 0.3);
            color: #dc2626;
            background: rgba(239, 68, 68, 0.03);
        }

        .filter-footer .btn-primary {
            background: linear-gradient(135deg, var(--blue-dark), var(--blue));
            border: none; color: #fff;
        }

        .filter-footer .btn-primary:hover { box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25); }

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

        .filter-results-summary i { color: var(--blue); font-size: 1.25rem; }
        .filter-results-text { flex: 1; }
        .filter-results-count { font-size: 1.25rem; font-weight: 800; color: var(--blue); }
        .filter-results-label { font-size: 0.8rem; color: var(--text-secondary); }

        /* ===== PAGINATION ===== */
        .pagination-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 0 0.25rem;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .pagination-info {
            font-size: 0.8rem;
            color: var(--text-secondary);
            font-weight: 600;
        }

        .pagination-controls {
            display: flex;
            align-items: center;
            gap: 0.35rem;
        }

        .page-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 34px;
            height: 34px;
            padding: 0 0.65rem;
            border-radius: 4px;
            border: 1px solid #e2e8f0;
            background: #fff;
            color: var(--text-secondary);
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .page-btn:hover:not([disabled]) {
            border-color: var(--gold);
            color: var(--gold-dark);
            background: rgba(212, 175, 55, 0.05);
        }

        .page-btn.active {
            background: linear-gradient(135deg, var(--gold-dark), var(--gold));
            color: #fff;
            border-color: var(--gold);
        }

        .page-btn[disabled] { opacity: 0.4; cursor: not-allowed; }

        .page-ellipsis { color: #94a3b8; font-size: 0.8rem; padding: 0 0.25rem; }

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
        .toast-success .app-toast-icon { background: rgba(212,175,55,0.12); color: #d4af37; }
        .toast-error   .app-toast-icon { background: rgba(239,68,68,0.1);   color: #ef4444; }
        .toast-info    .app-toast-icon { background: rgba(37,99,235,0.1);   color: #2563eb; }
        .toast-warning .app-toast-icon { background: rgba(212,175,55,0.12); color: #d4af37; }
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
        .toast-success .app-toast-progress { background: linear-gradient(90deg, #d4af37, #b8941f); }
        .toast-error   .app-toast-progress { background: linear-gradient(90deg, #ef4444, #dc2626); }
        .toast-info    .app-toast-progress { background: linear-gradient(90deg, #2563eb, #1e40af); }
        .toast-warning .app-toast-progress { background: linear-gradient(90deg, #d4af37, #b8941f); }
        @keyframes toast-progress { from { width: 100%; } to { width: 0%; } }
    </style>
</head>
<body>

    <!-- Include Sidebar -->
    <?php include 'admin_sidebar.php'; ?>
    <!-- Include Navbar -->
    <?php include 'admin_navbar.php'; ?>

    <!-- Main Content Area -->
    <div class="admin-content">

        <?php
        $pending_agents_count = count($agents_pending_approval);
        if ($pending_agents_count > 0):
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                showToast(
                    'warning',
                    'Pending Agent Review',
                    '<?php echo $pending_agents_count; ?> <?php echo $pending_agents_count === 1 ? 'agent requires' : 'agents require'; ?> review and approval.',
                    6000
                );
            }, 600);
        });
        </script>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-inner">
                <div>
                    <h1>Agent Management</h1>
                    <p class="subtitle">Review applications, manage active agents, and oversee your team</p>
                </div>
            </div>
        </div>

        <!-- KPI Stat Cards -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-icon gold"><i class="bi bi-people-fill"></i></div>
                <div class="kpi-label">Total Agents</div>
                <div class="kpi-value"><?php echo count($all_agents); ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon amber"><i class="bi bi-clock-history"></i></div>
                <div class="kpi-label">Pending Approval</div>
                <div class="kpi-value"><?php echo count($agents_pending_approval); ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon green"><i class="bi bi-check-circle"></i></div>
                <div class="kpi-label">Approved</div>
                <div class="kpi-value"><?php echo count($agents_approved); ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon red"><i class="bi bi-x-circle"></i></div>
                <div class="kpi-label">Rejected</div>
                <div class="kpi-value"><?php echo count($agents_rejected); ?></div>
            </div>
        </div>

        <!-- Action Bar with Search -->
        <div class="action-bar">
            <h2 class="action-title">
                <i class="bi bi-people"></i>
                All Agents
            </h2>
            <div class="action-buttons">
                <div class="search-container">
                    <i class="bi bi-search search-icon"></i>
                    <input type="text" id="agentSearchInput" placeholder="Search by name, email, license...">
                    <button class="search-clear" id="searchClearBtn" title="Clear search">
                        <i class="bi bi-x-circle-fill"></i>
                    </button>
                </div>
                <button type="button" class="btn-outline-admin" id="openAgentFilterBtn">
                    <i class="bi bi-funnel"></i>
                    Filter Agents
                    <span class="filter-count-badge" id="agentFilterCountBadge" style="display:none;">0</span>
                </button>
            </div>
        </div>

        <!-- Agent Tabs -->
        <div class="agent-tabs">
            <ul class="nav nav-tabs" id="agentStatusTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending-content" type="button" role="tab">
                        <i class="bi bi-clock-history me-1"></i>
                        Pending
                        <span class="tab-badge badge-pending"><?php echo count($agents_pending_approval); ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="approved-tab" data-bs-toggle="tab" data-bs-target="#approved-content" type="button" role="tab">
                        <i class="bi bi-check-circle me-1"></i>
                        Approved
                        <span class="tab-badge badge-approved"><?php echo count($agents_approved); ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="rejected-tab" data-bs-toggle="tab" data-bs-target="#rejected-content" type="button" role="tab">
                        <i class="bi bi-x-circle me-1"></i>
                        Rejected
                        <span class="tab-badge badge-rejected"><?php echo count($agents_rejected); ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="incomplete-tab" data-bs-toggle="tab" data-bs-target="#incomplete-content" type="button" role="tab">
                        <i class="bi bi-person-exclamation me-1"></i>
                        Incomplete
                        <span class="tab-badge badge-incomplete"><?php echo count($agents_needs_profile); ?></span>
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="agentStatusTabsContent">
                <!-- Pending Approval -->
                <div class="tab-pane fade show active" id="pending-content" role="tabpanel">
                    <div class="search-results-info" id="pending-search-info"></div>
                    <?php if (empty($agents_pending_approval)): ?>
                        <div class="empty-state">
                            <i class="bi bi-clock-history"></i>
                            <h4>No Pending Agents</h4>
                            <p>No agents are currently awaiting approval.</p>
                        </div>
                    <?php else: ?>
                        <div class="agents-grid" id="pending-grid">
                            <?php foreach ($agents_pending_approval as $agent): ?>
                                <?php include 'admin_agent_card_template.php'; ?>
                            <?php endforeach; ?>
                        </div>
                        <div class="pagination-bar" id="pending-pagination" style="display:none;"></div>
                        <div class="no-results" id="pending-no-results">
                            <i class="bi bi-search"></i>
                            <h5>No Matches Found</h5>
                            <p>No pending agents match your search criteria.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Approved Agents -->
                <div class="tab-pane fade" id="approved-content" role="tabpanel">
                    <div class="search-results-info" id="approved-search-info"></div>
                    <?php if (empty($agents_approved)): ?>
                        <div class="empty-state">
                            <i class="bi bi-check-circle"></i>
                            <h4>No Approved Agents</h4>
                            <p>No agents have been approved yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="agents-grid" id="approved-grid">
                            <?php foreach ($agents_approved as $agent): ?>
                                <?php include 'admin_agent_card_template.php'; ?>
                            <?php endforeach; ?>
                        </div>
                        <div class="pagination-bar" id="approved-pagination" style="display:none;"></div>
                        <div class="no-results" id="approved-no-results">
                            <i class="bi bi-search"></i>
                            <h5>No Matches Found</h5>
                            <p>No approved agents match your search criteria.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Rejected Agents -->
                <div class="tab-pane fade" id="rejected-content" role="tabpanel">
                    <div class="search-results-info" id="rejected-search-info"></div>
                    <?php if (empty($agents_rejected)): ?>
                        <div class="empty-state">
                            <i class="bi bi-x-circle"></i>
                            <h4>No Rejected Agents</h4>
                            <p>No agents have been rejected.</p>
                        </div>
                    <?php else: ?>
                        <div class="agents-grid" id="rejected-grid">
                            <?php foreach ($agents_rejected as $agent): ?>
                                <?php include 'admin_agent_card_template.php'; ?>
                            <?php endforeach; ?>
                        </div>
                        <div class="pagination-bar" id="rejected-pagination" style="display:none;"></div>
                        <div class="no-results" id="rejected-no-results">
                            <i class="bi bi-search"></i>
                            <h5>No Matches Found</h5>
                            <p>No rejected agents match your search criteria.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Incomplete Profile -->
                <div class="tab-pane fade" id="incomplete-content" role="tabpanel">
                    <div class="search-results-info" id="incomplete-search-info"></div>
                    <?php if (empty($agents_needs_profile)): ?>
                        <div class="empty-state">
                            <i class="bi bi-person-exclamation"></i>
                            <h4>No Incomplete Profiles</h4>
                            <p>All agents have completed their profile information.</p>
                        </div>
                    <?php else: ?>
                        <div class="agents-grid" id="incomplete-grid">
                            <?php foreach ($agents_needs_profile as $agent): ?>
                                <?php include 'admin_agent_card_template.php'; ?>
                            <?php endforeach; ?>
                        </div>
                        <div class="pagination-bar" id="incomplete-pagination" style="display:none;"></div>
                        <div class="no-results" id="incomplete-no-results">
                            <i class="bi bi-search"></i>
                            <h5>No Matches Found</h5>
                            <p>No incomplete profiles match your search criteria.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- Toast Container -->
<div id="toastContainer"></div>

<!-- Agent Filter Sidebar -->
<div class="filter-sidebar" id="agentFilterSidebar">
    <div class="filter-sidebar-overlay" id="agentFilterOverlay"></div>
    <div class="filter-sidebar-content">
        <div class="filter-header">
            <h4><i class="bi bi-funnel-fill"></i>Advanced Filters</h4>
            <button class="btn-close-filter" id="closeAgentFilterBtn"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="filter-body">
            <!-- Search -->
            <div class="filter-section">
                <div class="filter-section-title"><i class="bi bi-search"></i>Search</div>
                <div class="filter-search-box">
                    <i class="bi bi-search"></i>
                    <input type="text" id="agentSearchFilter" placeholder="Name, email, license number...">
                </div>
            </div>
            <!-- Specialization -->
            <div class="filter-section">
                <div class="filter-section-title"><i class="bi bi-star-fill"></i>Specialization</div>
                <div class="filter-chips">
                    <?php foreach ($all_specializations as $spec_item): ?>
                    <label class="filter-chip"><input type="checkbox" class="specialization-filter" value="<?php echo htmlspecialchars($spec_item['specialization_name']); ?>"><span><?php echo htmlspecialchars($spec_item['specialization_name']); ?></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <!-- Years of Experience -->
            <div class="filter-section">
                <div class="filter-section-title"><i class="bi bi-clock-history"></i>Years of Experience</div>
                <div class="filter-range-pair">
                    <input type="number" id="expMin" placeholder="Min" min="0" max="60">
                    <span class="range-divider">—</span>
                    <input type="number" id="expMax" placeholder="Max" min="0" max="60">
                </div>
                <div class="quick-filters">
                    <button class="quick-filter-btn" data-exp-range="0-2">0–2 yrs</button>
                    <button class="quick-filter-btn" data-exp-range="3-5">3–5 yrs</button>
                    <button class="quick-filter-btn" data-exp-range="5-10">5–10 yrs</button>
                    <button class="quick-filter-btn" data-exp-range="10-99">10+ yrs</button>
                </div>
            </div>
            <!-- Registration Date -->
            <div class="filter-section">
                <div class="filter-section-title"><i class="bi bi-calendar-range"></i>Registration Date</div>
                <div class="filter-range-pair">
                    <input type="date" id="regFrom">
                    <span class="range-divider">—</span>
                    <input type="date" id="regTo">
                </div>
            </div>
            <!-- Results Summary -->
            <div class="filter-results-summary">
                <i class="bi bi-person-check-fill"></i>
                <div class="filter-results-text">
                    <div class="filter-results-count" id="agentFilteredCount">0</div>
                    <div class="filter-results-label">Agents match your criteria</div>
                </div>
            </div>
        </div>
        <div class="filter-footer">
            <button class="btn btn-outline-secondary" id="clearAgentFiltersBtn"><i class="bi bi-arrow-clockwise me-2"></i>Reset</button>
            <button class="btn btn-primary" id="applyAgentFiltersBtn"><i class="bi bi-check2 me-2"></i>Apply Filters</button>
        </div>
    </div>
</div>

<script>
// ===== TOAST =====
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

document.addEventListener('DOMContentLoaded', function() {
    const ITEMS_PER_PAGE = 9;
    const tabPanes = ['pending', 'approved', 'rejected', 'incomplete'];

    // Pagination state per tab
    const paginationState = {};
    tabPanes.forEach(key => { paginationState[key] = { page: 1, filteredCards: [] }; });

    // Filter state
    const filterState = {
        search: '',
        specialization: new Set(),
        expMin: null,
        expMax: null,
        regFrom: '',
        regTo: ''
    };

    // ===== FILTER APPLICATION =====
    function applyAgentFilters() {
        let totalVisible = 0;
        tabPanes.forEach(key => {
            const grid = document.getElementById(key + '-grid');
            if (!grid) {
                paginationState[key].filteredCards = [];
                renderAgentPage(key);
                return;
            }

            const cards = Array.from(grid.querySelectorAll('.agent-card'));
            const filtered = cards.filter(card => {
                // Text search
                if (filterState.search) {
                    const text = [
                        card.dataset.searchName || '',
                        card.dataset.searchEmail || '',
                        card.dataset.searchLicense || '',
                        card.dataset.searchSpecialty || '',
                        card.dataset.searchPhone || ''
                    ].join(' ').toLowerCase();
                    if (!text.includes(filterState.search)) return false;
                }

                // Specialization
                if (filterState.specialization.size > 0) {
                    const spec = (card.dataset.searchSpecialty || '').toLowerCase();
                    let matched = false;
                    filterState.specialization.forEach(s => { if (spec.includes(s.toLowerCase())) matched = true; });
                    if (!matched) return false;
                }

                // Experience range
                const exp = parseInt(card.dataset.experience || '0');
                if (filterState.expMin !== null && exp < filterState.expMin) return false;
                if (filterState.expMax !== null && exp > filterState.expMax) return false;

                // Registration date
                const reg = card.dataset.registered || '';
                if (filterState.regFrom && reg < filterState.regFrom) return false;
                if (filterState.regTo && reg > filterState.regTo) return false;

                return true;
            });

            // Hide all, store filtered
            cards.forEach(card => { card.style.display = 'none'; });
            paginationState[key].filteredCards = filtered;
            paginationState[key].page = 1;
            totalVisible += filtered.length;
            renderAgentPage(key);
        });

        updateSearchInfo();
        updateAgentFilterCountDisplay(totalVisible);
    }

    function renderAgentPage(key) {
        const state = paginationState[key];
        const filtered = state.filteredCards;
        const totalPages = Math.ceil(filtered.length / ITEMS_PER_PAGE);
        state.page = Math.max(1, Math.min(state.page, totalPages || 1));

        const start = (state.page - 1) * ITEMS_PER_PAGE;
        const end = start + ITEMS_PER_PAGE;

        filtered.forEach((card, i) => {
            card.style.display = (i >= start && i < end) ? '' : 'none';
        });

        // No-results visibility
        const noResults = document.getElementById(key + '-no-results');
        if (noResults) {
            const hasFilter = filterState.search || filterState.specialization.size > 0 ||
                              filterState.expMin !== null || filterState.expMax !== null ||
                              filterState.regFrom || filterState.regTo;
            noResults.style.display = (filtered.length === 0 && hasFilter) ? 'block' : 'none';
        }

        renderAgentPagination(key, filtered.length);
    }

    function renderAgentPagination(key, total) {
        const el = document.getElementById(key + '-pagination');
        if (!el) return;

        const state = paginationState[key];
        const totalPages = Math.ceil(total / ITEMS_PER_PAGE);

        if (totalPages <= 1) { el.style.display = 'none'; return; }

        el.style.display = 'flex';
        const start = (state.page - 1) * ITEMS_PER_PAGE + 1;
        const end = Math.min(state.page * ITEMS_PER_PAGE, total);

        const maxBtns = 5;
        let startPage = Math.max(1, state.page - Math.floor(maxBtns / 2));
        let endPage = Math.min(totalPages, startPage + maxBtns - 1);
        if (endPage - startPage < maxBtns - 1) startPage = Math.max(1, endPage - maxBtns + 1);

        let pages = ``;
        if (startPage > 1) {
            pages += `<button class="page-btn" data-page="1" data-pane="${key}">1</button>`;
            if (startPage > 2) pages += `<span class="page-ellipsis">…</span>`;
        }
        for (let i = startPage; i <= endPage; i++) {
            pages += `<button class="page-btn ${i === state.page ? 'active' : ''}" data-page="${i}" data-pane="${key}">${i}</button>`;
        }
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) pages += `<span class="page-ellipsis">…</span>`;
            pages += `<button class="page-btn" data-page="${totalPages}" data-pane="${key}">${totalPages}</button>`;
        }

        el.innerHTML = `
            <div class="pagination-info">Showing ${start}–${end} of ${total} agents</div>
            <div class="pagination-controls">
                <button class="page-btn" ${state.page <= 1 ? 'disabled' : ''} data-page="${state.page - 1}" data-pane="${key}"><i class="bi bi-chevron-left"></i></button>
                ${pages}
                <button class="page-btn" ${state.page >= totalPages ? 'disabled' : ''} data-page="${state.page + 1}" data-pane="${key}"><i class="bi bi-chevron-right"></i></button>
            </div>`;

        el.querySelectorAll('.page-btn:not([disabled])').forEach(btn => {
            btn.addEventListener('click', () => {
                const newPage = parseInt(btn.dataset.page);
                const pane = btn.dataset.pane;
                paginationState[pane].page = newPage;
                renderAgentPage(pane);
                const tabEl = document.getElementById(pane + '-content');
                if (tabEl) tabEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });
    }

    function updateSearchInfo() {
        tabPanes.forEach(key => {
            const searchInfo = document.getElementById(key + '-search-info');
            const hasFilter = filterState.search || filterState.specialization.size > 0 ||
                              filterState.expMin !== null || filterState.expMax !== null ||
                              filterState.regFrom || filterState.regTo;
            if (searchInfo) {
                if (hasFilter) {
                    const count = paginationState[key].filteredCards.length;
                    searchInfo.style.display = 'block';
                    searchInfo.innerHTML = `Showing <span class="count">${count}</span> result${count !== 1 ? 's' : ''}` +
                        (filterState.search ? ` for "<span class="term">${escapeHtml(filterState.search)}</span>"` : '');
                } else {
                    searchInfo.style.display = 'none';
                }
            }
        });
    }

    function updateAgentFilterCountDisplay(totalVisible) {
        const countEl = document.getElementById('agentFilteredCount');
        if (countEl) countEl.textContent = totalVisible;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    // Initialize — show all cards + setup initial pagination
    tabPanes.forEach(key => {
        const grid = document.getElementById(key + '-grid');
        if (grid) {
            const cards = Array.from(grid.querySelectorAll('.agent-card'));
            paginationState[key].filteredCards = cards;
            renderAgentPage(key);
        }
    });

    // ===== SEARCH INPUT =====
    const searchInput = document.getElementById('agentSearchInput');
    const searchClearBtn = document.getElementById('searchClearBtn');

    if (searchInput) {
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            filterState.search = this.value.trim().toLowerCase();
            if (searchClearBtn) searchClearBtn.style.display = filterState.search ? 'block' : 'none';
            // Sync with drawer search box
            const drawerSearch = document.getElementById('agentSearchFilter');
            if (drawerSearch && drawerSearch !== document.activeElement) drawerSearch.value = this.value;
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(applyAgentFilters, 200);
        });
        searchInput.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); clearTimeout(searchTimeout); applyAgentFilters(); }
        });
    }

    if (searchClearBtn) {
        searchClearBtn.addEventListener('click', function() {
            if (searchInput) searchInput.value = '';
            const drawerSearch = document.getElementById('agentSearchFilter');
            if (drawerSearch) drawerSearch.value = '';
            filterState.search = '';
            this.style.display = 'none';
            applyAgentFilters();
            if (searchInput) searchInput.focus();
        });
    }

    // ===== FILTER DRAWER =====
    document.getElementById('openAgentFilterBtn')?.addEventListener('click', () => {
        document.getElementById('agentFilterSidebar').classList.add('active');
    });

    document.getElementById('closeAgentFilterBtn')?.addEventListener('click', () => {
        document.getElementById('agentFilterSidebar').classList.remove('active');
    });

    document.getElementById('agentFilterOverlay')?.addEventListener('click', () => {
        document.getElementById('agentFilterSidebar').classList.remove('active');
    });

    document.getElementById('applyAgentFiltersBtn')?.addEventListener('click', () => {
        document.getElementById('agentFilterSidebar').classList.remove('active');
    });

    // Drawer search syncs with main search
    document.getElementById('agentSearchFilter')?.addEventListener('input', e => {
        filterState.search = e.target.value.toLowerCase();
        if (searchInput) searchInput.value = e.target.value;
        if (searchClearBtn) searchClearBtn.style.display = filterState.search ? 'block' : 'none';
        applyAgentFilters();
    });

    // Specialization chips
    document.querySelectorAll('.specialization-filter').forEach(cb => {
        cb.addEventListener('change', () => {
            filterState.specialization = new Set(
                Array.from(document.querySelectorAll('.specialization-filter:checked')).map(c => c.value)
            );
            updateChipStates();
            updateAgentBadge();
            applyAgentFilters();
        });
    });

    // Experience range
    document.getElementById('expMin')?.addEventListener('input', e => {
        filterState.expMin = e.target.value !== '' ? Number(e.target.value) : null;
        updateAgentBadge(); applyAgentFilters();
    });
    document.getElementById('expMax')?.addEventListener('input', e => {
        filterState.expMax = e.target.value !== '' ? Number(e.target.value) : null;
        updateAgentBadge(); applyAgentFilters();
    });

    // Registration date
    document.getElementById('regFrom')?.addEventListener('change', e => {
        filterState.regFrom = e.target.value; updateAgentBadge(); applyAgentFilters();
    });
    document.getElementById('regTo')?.addEventListener('change', e => {
        filterState.regTo = e.target.value; updateAgentBadge(); applyAgentFilters();
    });

    // Quick filters for experience
    document.querySelectorAll('[data-exp-range]').forEach(btn => {
        btn.addEventListener('click', () => {
            const [min, max] = btn.dataset.expRange.split('-').map(Number);
            const expMinEl = document.getElementById('expMin');
            const expMaxEl = document.getElementById('expMax');
            if (expMinEl) expMinEl.value = min;
            if (expMaxEl) expMaxEl.value = max;
            filterState.expMin = min;
            filterState.expMax = max;
            document.querySelectorAll('[data-exp-range]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            updateAgentBadge();
            applyAgentFilters();
        });
    });

    // Clear all filters
    document.getElementById('clearAgentFiltersBtn')?.addEventListener('click', () => {
        document.querySelectorAll('#agentFilterSidebar input[type="text"], #agentFilterSidebar input[type="number"], #agentFilterSidebar input[type="date"]').forEach(el => el.value = '');
        document.querySelectorAll('#agentFilterSidebar input[type="checkbox"]').forEach(cb => { cb.checked = false; });
        document.querySelectorAll('[data-exp-range]').forEach(b => b.classList.remove('active'));

        filterState.search = '';
        filterState.specialization = new Set();
        filterState.expMin = null;
        filterState.expMax = null;
        filterState.regFrom = '';
        filterState.regTo = '';

        if (searchInput) searchInput.value = '';
        if (searchClearBtn) searchClearBtn.style.display = 'none';

        updateChipStates();
        updateAgentBadge();
        applyAgentFilters();
    });

    function updateChipStates() {
        document.querySelectorAll('.filter-chip').forEach(chip => {
            const cb = chip.querySelector('input[type="checkbox"]');
            if (cb) chip.classList.toggle('active', cb.checked);
        });
    }

    function updateAgentBadge() {
        let count = 0;
        if (filterState.specialization.size > 0) count++;
        if (filterState.expMin !== null || filterState.expMax !== null) count++;
        if (filterState.regFrom || filterState.regTo) count++;
        const badge = document.getElementById('agentFilterCountBadge');
        if (badge) {
            badge.textContent = count;
            badge.style.display = count > 0 ? 'inline-flex' : 'none';
        }
    }

    // ===== ANIMATION =====
    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, { threshold: 0.1, rootMargin: '0px 0px -30px 0px' });

    document.querySelectorAll('.agent-card').forEach(card => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(15px)';
        card.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
        observer.observe(card);
    });

    // ===== KEYBOARD SHORTCUTS =====
    document.addEventListener('keydown', e => {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            if (searchInput) { searchInput.focus(); searchInput.select(); }
        }
    });
});
</script>
</body>
</html>
