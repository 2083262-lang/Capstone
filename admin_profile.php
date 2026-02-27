<?php
session_start();
include 'connection.php';

if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$account_id = (int)$_SESSION['account_id'];

// Fetch admin account + profile info
$stmt = $conn->prepare("
    SELECT a.account_id, a.first_name, a.middle_name, a.last_name, a.phone_number, a.email, a.username, a.date_registered, a.is_active, a.two_factor_enabled,
           ai.admin_info_id, ai.license_number, ai.specialization, ai.years_experience, ai.bio, ai.profile_picture_url, ai.profile_completed
    FROM accounts a
    LEFT JOIN admin_information ai ON a.account_id = ai.account_id
    WHERE a.account_id = ? LIMIT 1
");
$stmt->bind_param("i", $account_id);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Summary stats
$total_properties = 0;
$total_agents = 0;
$total_tours = 0;
$total_sales = 0;
$recent_logs = [];

$r = $conn->query("SELECT COUNT(*) AS c FROM property");
if ($r) $total_properties = $r->fetch_assoc()['c'];

$r = $conn->query("SELECT COUNT(*) AS c FROM agent_information WHERE profile_completed = 1");
if ($r) $total_agents = $r->fetch_assoc()['c'];

$r = $conn->query("SELECT COUNT(*) AS c FROM tour_requests");
if ($r) $total_tours = $r->fetch_assoc()['c'];

$r = $conn->query("SELECT COUNT(*) AS c FROM finalized_sales");
if ($r) $total_sales = $r->fetch_assoc()['c'];

// Recent admin activity logs
$log_stmt = $conn->prepare("SELECT action, action_type, description, log_timestamp FROM admin_logs WHERE admin_account_id = ? ORDER BY log_timestamp DESC LIMIT 10");
$log_stmt->bind_param("i", $account_id);
$log_stmt->execute();
$log_result = $log_stmt->get_result();
while ($log = $log_result->fetch_assoc()) {
    $recent_logs[] = $log;
}
$log_stmt->close();

// Recent status_log actions by this admin
$action_logs = [];
$action_stmt = $conn->prepare("
    SELECT sl.action, sl.item_type, sl.reason_message, sl.log_timestamp,
           CASE WHEN sl.item_type = 'agent' THEN CONCAT(ac.first_name, ' ', ac.last_name) ELSE CONCAT('Property #', sl.item_id) END AS item_label
    FROM status_log sl
    LEFT JOIN accounts ac ON sl.item_type = 'agent' AND sl.item_id = ac.account_id
    WHERE sl.action_by_account_id = ?
    ORDER BY sl.log_timestamp DESC LIMIT 10
");
$action_stmt->bind_param("i", $account_id);
$action_stmt->execute();
$action_result = $action_stmt->get_result();
while ($a = $action_result->fetch_assoc()) {
    $action_logs[] = $a;
}
$action_stmt->close();

// Fetch all specializations for multi-select
$all_specs = [];
$spec_r = $conn->query("SELECT specialization_id, specialization_name FROM specializations ORDER BY specialization_name ASC");
if ($spec_r) { while ($row = $spec_r->fetch_assoc()) $all_specs[] = $row; }

// Parse current admin specializations (stored as comma-separated names)
$current_specs_raw = $admin['specialization'] ?? '';
$current_specs = array_filter(array_map('trim', explode(',', $current_specs_raw)));

// Helper
$avatar_src = 'https://via.placeholder.com/150/2563eb/ffffff?text=' . strtoupper(substr($admin['first_name'] ?? 'A', 0, 1));
if (!empty($admin['profile_picture_url'])) {
    $avatar_src = htmlspecialchars($admin['profile_picture_url']);
}
$full_name = htmlspecialchars(trim(($admin['first_name'] ?? '') . ' ' . ($admin['middle_name'] ?? '') . ' ' . ($admin['last_name'] ?? '')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        /* ===== SAME LAYOUT AS property.php ===== */
        :root {
            --primary-color: #161209;
            --secondary-color: #bc9e42;
            --accent-color: #a08636;
            --bg-light: #f8f9fa;
            --border-color: #e0e0e0;
        }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-light); color: #212529; }
        .admin-sidebar { background: linear-gradient(180deg, #161209 0%, #1f1a0f 100%); color: #fff; height: 100vh; position: fixed; top: 0; left: 0; width: 290px; overflow-y: auto; z-index: 1000; box-shadow: 2px 0 10px rgba(0,0,0,0.1); }
        .admin-content { margin-left: 290px; padding: 2rem; min-height: 100vh; max-width: 1800px; }
        @media (max-width: 1200px) { .admin-content { margin-left: 0 !important; padding: 1.5rem; } }
        @media (max-width: 768px) { .admin-content { margin-left: 0 !important; padding: 1rem; } }

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
        .page-header::before { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: radial-gradient(ellipse at top right, rgba(37, 99, 235, 0.04) 0%, transparent 50%), radial-gradient(ellipse at bottom left, rgba(212, 175, 55, 0.03) 0%, transparent 50%); pointer-events: none; }
        .page-header::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent); }
        .page-header-inner { position: relative; z-index: 2; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1.5rem; }
        .page-header h1 { font-size: 1.75rem; font-weight: 800; color: var(--text-primary); margin-bottom: 0.25rem; }
        .page-header .subtitle { color: var(--text-secondary); font-size: 0.95rem; }
        .page-header .header-badge { background: linear-gradient(135deg, var(--gold-dark), var(--gold)); color: #fff; font-size: 0.75rem; font-weight: 700; padding: 0.3rem 0.85rem; border-radius: 2px; text-transform: uppercase; letter-spacing: 0.5px; }

        /* ===== PROFILE HERO CARD ===== */
        .profile-hero {
            background: var(--card-bg);
            border: 1px solid rgba(37, 99, 235, 0.1);
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        .profile-hero-cover {
            height: 140px;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 40%, #1e40af 100%);
            position: relative;
        }
        .profile-hero-cover::after {
            content: '';
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent);
        }
        .profile-hero-body {
            padding: 0 2.5rem 2rem;
            position: relative;
        }
        .profile-hero-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #fff;
            box-shadow: 0 8px 32px rgba(0,0,0,0.15), 0 0 0 3px rgba(37, 99, 235, 0.2);
            margin-top: -60px;
            position: relative;
            z-index: 2;
        }
        .profile-hero-info { display: flex; align-items: flex-start; gap: 2rem; flex-wrap: wrap; }
        .profile-hero-meta { flex: 1; min-width: 200px; padding-top: 0.5rem; }
        .profile-hero-name { font-size: 1.5rem; font-weight: 800; color: var(--text-primary); margin-bottom: 0.25rem; }
        .profile-hero-role {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.08), rgba(37, 99, 235, 0.15));
            color: var(--blue);
            font-size: 0.8rem;
            font-weight: 700;
            padding: 0.3rem 0.8rem;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: 1px solid rgba(37, 99, 235, 0.15);
        }
        .profile-hero-email { font-size: 0.9rem; color: var(--text-secondary); margin-top: 0.5rem; }
        .profile-hero-actions { display: flex; gap: 0.75rem; align-items: center; padding-top: 0.75rem; flex-wrap: wrap; }

        /* ===== KPI STAT CARDS ===== */
        .kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
        .kpi-card { background: var(--card-bg); border: 1px solid rgba(37, 99, 235, 0.1); border-radius: 4px; padding: 1.25rem; position: relative; overflow: hidden; transition: all 0.3s ease; }
        .kpi-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--blue), transparent); opacity: 0; transition: opacity 0.3s ease; }
        .kpi-card:hover { border-color: rgba(37, 99, 235, 0.25); box-shadow: 0 8px 32px rgba(37,99,235,0.08); transform: translateY(-3px); }
        .kpi-card:hover::before { opacity: 1; }
        .kpi-card .kpi-icon { width: 40px; height: 40px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; margin-bottom: 0.75rem; }
        .kpi-icon.gold { background: linear-gradient(135deg, rgba(212,175,55,0.08), rgba(212,175,55,0.15)); color: var(--gold-dark); border: 1px solid rgba(212,175,55,0.2); }
        .kpi-icon.blue { background: linear-gradient(135deg, rgba(37,99,235,0.06), rgba(37,99,235,0.12)); color: var(--blue); border: 1px solid rgba(37,99,235,0.15); }
        .kpi-icon.green { background: linear-gradient(135deg, rgba(34,197,94,0.06), rgba(34,197,94,0.12)); color: #16a34a; border: 1px solid rgba(34,197,94,0.15); }
        .kpi-icon.cyan { background: linear-gradient(135deg, rgba(6,182,212,0.06), rgba(6,182,212,0.12)); color: #0891b2; border: 1px solid rgba(6,182,212,0.15); }
        .kpi-card .kpi-label { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: var(--text-secondary); margin-bottom: 0.25rem; }
        .kpi-card .kpi-value { font-size: 1.5rem; font-weight: 800; color: var(--text-primary); line-height: 1.2; }

        @media (max-width: 992px) { .kpi-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 768px) {
            .admin-content { padding: 1rem; }
            .page-header { padding: 1.25rem 1rem; }
            .page-header h1 { font-size: 1.3rem; }
            .page-header-inner { flex-direction: column; align-items: flex-start; gap: 0.75rem; }
            .kpi-grid { grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
            .kpi-card { padding: 1rem; }
            .kpi-card .kpi-value { font-size: 1.25rem; }
        }
        @media (max-width: 576px) {
            .admin-content { padding: 0.75rem; }
            .page-header { padding: 1rem; }
            .page-header h1 { font-size: 1.15rem; }
            .kpi-grid { grid-template-columns: 1fr 1fr; gap: 0.5rem; }
            .kpi-card { padding: 0.85rem; }
            .kpi-card .kpi-value { font-size: 1.1rem; }
            .kpi-card .kpi-label { font-size: 0.65rem; }
        }

        /* ===== DETAIL CARDS ===== */
        .detail-card {
            background: var(--card-bg);
            border: 1px solid rgba(37, 99, 235, 0.1);
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        .detail-card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid rgba(37, 99, 235, 0.08);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
        }
        .detail-card-header::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 1px; background: linear-gradient(90deg, transparent, rgba(212, 175, 55, 0.3), transparent); }
        .detail-card-header i { font-size: 1.1rem; color: var(--blue); }
        .detail-card-header h3 { font-size: 1rem; font-weight: 700; color: var(--text-primary); margin: 0; }
        .detail-card-body { padding: 1.5rem; }

        /* Info Grid */
        .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.25rem; }
        @media (max-width: 768px) { .info-grid { grid-template-columns: 1fr; } }
        .info-item { }
        .info-item-label { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.8px; color: var(--text-secondary); margin-bottom: 0.3rem; display: flex; align-items: center; gap: 0.4rem; }
        .info-item-label i { font-size: 0.75rem; color: var(--gold-dark); }
        .info-item-value { font-size: 0.95rem; font-weight: 600; color: var(--text-primary); word-break: break-word; }

        /* Bio section */
        .bio-text { font-size: 0.95rem; line-height: 1.7; color: #475569; }

        /* Activity Log */
        .activity-list { list-style: none; padding: 0; margin: 0; }
        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1rem 0;
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }
        .activity-item:last-child { border-bottom: none; }
        .activity-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            flex-shrink: 0;
        }
        .activity-icon.login { background: rgba(37, 99, 235, 0.1); color: #2563eb; }
        .activity-icon.approved { background: rgba(34, 197, 94, 0.1); color: #16a34a; }
        .activity-icon.rejected { background: rgba(239, 68, 68, 0.1); color: #dc2626; }
        .activity-content { flex: 1; }
        .activity-title { font-size: 0.88rem; font-weight: 600; color: var(--text-primary); }
        .activity-time { font-size: 0.78rem; color: var(--text-secondary); }

        /* ===== BUTTONS ===== */
        .btn-gold {
            background: linear-gradient(135deg, var(--gold-dark), var(--gold));
            color: #fff;
            border: none;
            font-weight: 700;
            font-size: 0.85rem;
            padding: 0.6rem 1.25rem;
            border-radius: 4px;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-gold:hover { background: linear-gradient(135deg, var(--gold), var(--gold-light)); color: #fff; transform: translateY(-2px); box-shadow: 0 6px 20px rgba(212, 175, 55, 0.3); }
        .btn-outline-admin {
            border: 1px solid rgba(37, 99, 235, 0.3);
            color: var(--blue);
            background: transparent;
            font-weight: 600;
            font-size: 0.85rem;
            padding: 0.6rem 1.25rem;
            border-radius: 4px;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-outline-admin:hover { background: rgba(37, 99, 235, 0.08); border-color: var(--blue); color: var(--blue); }

        /* Status badges */
        .status-active { display: inline-flex; align-items: center; gap: 0.4rem; background: rgba(34, 197, 94, 0.1); color: #16a34a; font-size: 0.78rem; font-weight: 700; padding: 0.25rem 0.7rem; border-radius: 4px; border: 1px solid rgba(34, 197, 94, 0.2); }
        .status-inactive { display: inline-flex; align-items: center; gap: 0.4rem; background: rgba(239, 68, 68, 0.1); color: #dc2626; font-size: 0.78rem; font-weight: 700; padding: 0.25rem 0.7rem; border-radius: 4px; border: 1px solid rgba(239, 68, 68, 0.2); }
        .badge-2fa { display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.78rem; font-weight: 700; padding: 0.25rem 0.7rem; border-radius: 4px; }
        .badge-2fa.enabled { background: rgba(37, 99, 235, 0.1); color: #2563eb; border: 1px solid rgba(37, 99, 235, 0.2); }
        .badge-2fa.disabled { background: rgba(245, 158, 11, 0.1); color: #d97706; border: 1px solid rgba(245, 158, 11, 0.2); }

        .empty-state { text-align: center; padding: 2.5rem 1rem; color: var(--text-secondary); }
        .empty-state i { font-size: 2rem; opacity: 0.3; display: block; margin-bottom: 0.5rem; }

        /* ===== SEE MORE ===== */
        .activity-item.activity-hidden { display: none; }
        .see-more-btn {
            width: 100%;
            padding: 0.65rem;
            margin-top: 0.25rem;
            background: transparent;
            border: 1px dashed rgba(37, 99, 235, 0.25);
            border-radius: 4px;
            color: var(--blue);
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
        }
        .see-more-btn:hover { background: rgba(37, 99, 235, 0.05); border-color: var(--blue); }
        .see-more-btn i { transition: transform 0.25s ease; }
        .see-more-btn.expanded i { transform: rotate(180deg); }

        /* ===== EDIT PROFILE OVERLAY ===== */
        .edit-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 9999;
            display: none;
            justify-content: center;
            align-items: flex-start;
            padding: 2rem;
            overflow-y: auto;
        }
        .edit-overlay.active { display: flex; }
        .edit-modal {
            background: var(--card-bg);
            border: 1px solid rgba(37, 99, 235, 0.15);
            border-radius: 4px;
            width: 100%;
            max-width: 900px;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.25);
            animation: editSlideIn 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        @keyframes editSlideIn { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        .edit-modal-header {
            padding: 1.25rem 1.75rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(37, 99, 235, 0.08);
            position: relative;
        }
        .edit-modal-header::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent); }
        .edit-modal-header h3 { font-size: 1.1rem; font-weight: 700; color: var(--text-primary); margin: 0; display: flex; align-items: center; gap: 0.5rem; }
        .edit-modal-header h3 i { color: var(--blue); }
        .edit-modal-close {
            width: 32px; height: 32px;
            border: 1px solid rgba(0,0,0,0.1);
            border-radius: 4px;
            background: transparent;
            color: var(--text-secondary);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 1.1rem;
        }
        .edit-modal-close:hover { background: rgba(239,68,68,0.08); border-color: rgba(239,68,68,0.25); color: #dc2626; }
        .edit-modal-body { padding: 1.75rem; max-height: calc(100vh - 200px); overflow-y: auto; }
        .edit-section-title {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--blue);
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid rgba(37, 99, 235, 0.08);
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .edit-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem; }
        .edit-form-grid .full-width { grid-column: 1 / -1; }
        .edit-form-group label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 0.35rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .edit-form-group label .required { color: #dc2626; }
        .edit-form-group input,
        .edit-form-group textarea,
        .edit-form-group select {
            width: 100%;
            padding: 0.6rem 0.85rem;
            font-size: 0.9rem;
            font-family: 'Inter', sans-serif;
            border: 1px solid rgba(0,0,0,0.12);
            border-radius: 4px;
            background: #fff;
            color: var(--text-primary);
            transition: all 0.2s ease;
        }
        .edit-form-group input:focus,
        .edit-form-group textarea:focus,
        .edit-form-group select:focus {
            outline: none;
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        .edit-form-group textarea { resize: vertical; min-height: 90px; }

        /* Specialization multi-select chips */
        .spec-chips-wrap {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            padding: 0.75rem;
            background: rgba(37, 99, 235, 0.02);
            border: 1px solid rgba(0,0,0,0.12);
            border-radius: 4px;
            min-height: 54px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .spec-chips-wrap:focus-within {
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        .spec-chip input[type="checkbox"] { display: none; }
        .spec-chip label {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.35rem 0.8rem;
            font-size: 0.8rem;
            font-weight: 600;
            border: 1px solid rgba(37, 99, 235, 0.2);
            border-radius: 20px;
            background: #fff;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.18s ease;
            user-select: none;
            text-transform: none;
            letter-spacing: 0;
            margin: 0;
        }
        .spec-chip label:hover {
            border-color: var(--blue);
            color: var(--blue);
            background: rgba(37, 99, 235, 0.05);
        }
        .spec-chip input[type="checkbox"]:checked + label {
            background: linear-gradient(135deg, rgba(212,175,55,0.12), rgba(212,175,55,0.22));
            border-color: var(--gold-dark);
            color: var(--gold-dark);
            box-shadow: 0 2px 8px rgba(212,175,55,0.15);
        }
        .spec-chip input[type="checkbox"]:checked + label::before {
            content: '\2713';
            font-size: 0.7rem;
            font-weight: 800;
        }
        .spec-none-msg { font-size: 0.78rem; color: var(--text-secondary); font-style: italic; display: none; }
        .spec-none-msg.visible { display: block; }
        .edit-avatar-upload {
            display: flex;
            align-items: center;
            gap: 1.25rem;
            padding: 1rem;
            background: rgba(37, 99, 235, 0.03);
            border: 1px dashed rgba(37, 99, 235, 0.2);
            border-radius: 4px;
        }
        .edit-avatar-preview {
            width: 72px; height: 72px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #fff;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            flex-shrink: 0;
        }
        .edit-avatar-info { flex: 1; }
        .edit-avatar-info .upload-label {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            background: linear-gradient(135deg, var(--blue-dark), var(--blue));
            color: #fff;
            font-size: 0.78rem;
            font-weight: 600;
            padding: 0.45rem 0.9rem;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .edit-avatar-info .upload-label:hover { background: linear-gradient(135deg, var(--blue), var(--blue-light)); }
        .edit-avatar-info .upload-hint { font-size: 0.72rem; color: var(--text-secondary); margin-top: 0.35rem; }
        .edit-avatar-info input[type="file"] { display: none; }
        .edit-modal-footer {
            padding: 1.25rem 1.75rem;
            border-top: 1px solid rgba(37, 99, 235, 0.08);
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }
        .btn-cancel-edit {
            background: transparent;
            border: 1px solid rgba(0,0,0,0.15);
            color: var(--text-secondary);
            font-weight: 600;
            font-size: 0.85rem;
            padding: 0.6rem 1.25rem;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-cancel-edit:hover { border-color: rgba(0,0,0,0.3); color: var(--text-primary); }
        .btn-save-edit {
            background: linear-gradient(135deg, var(--gold-dark), var(--gold));
            color: #fff;
            border: none;
            font-weight: 700;
            font-size: 0.85rem;
            padding: 0.6rem 1.5rem;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-save-edit:hover { background: linear-gradient(135deg, var(--gold), var(--gold-light)); transform: translateY(-1px); box-shadow: 0 4px 15px rgba(212,175,55,0.3); }
        .btn-save-edit:disabled { opacity: 0.6; cursor: not-allowed; transform: none; box-shadow: none; }

        /* Toast */
        .profile-toast {
            position: fixed;
            top: 1.5rem;
            right: 1.5rem;
            z-index: 10001;
            padding: 0.85rem 1.25rem;
            border-radius: 4px;
            font-size: 0.88rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            transform: translateX(120%);
            transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .profile-toast.show { transform: translateX(0); }
        .profile-toast.success { background: #16a34a; color: #fff; }
        .profile-toast.error { background: #dc2626; color: #fff; }

        @media (max-width: 768px) {
            .edit-overlay { padding: 1rem; }
            .edit-modal-body { padding: 1.25rem; }
            .edit-form-grid { grid-template-columns: 1fr; }
            .edit-modal-header, .edit-modal-footer { padding: 1rem 1.25rem; }
        }
        @media (max-width: 576px) {
            .edit-overlay { padding: 0.5rem; }
            .edit-modal-body { padding: 1rem; }
            .edit-avatar-upload { flex-direction: column; text-align: center; }
        }

        @media (max-width: 768px) {
            .profile-hero-body { padding: 0 1.25rem 1.25rem; }
            .profile-hero-info { flex-direction: column; gap: 1rem; align-items: flex-start; }
            .profile-hero-actions { flex-wrap: wrap; }
            .profile-hero-actions .btn { flex: 1 1 auto; min-width: 0; justify-content: center; }
            .info-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 576px) {
            .profile-hero-body { padding: 0 0.85rem 0.85rem; }
            .profile-hero-avatar { width: 72px; height: 72px; font-size: 1.75rem; }
        }
    </style>
</head>
<body>
    <?php $active_page = 'admin_profile.php'; include 'admin_sidebar.php'; ?>
    <?php include 'admin_navbar.php'; ?>

    <div class="admin-content">
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-inner">
                <div>
                    <h1>My Profile</h1>
                    <p class="subtitle">View and manage your administrator account information</p>
                </div>
                <span class="header-badge">Administrator</span>
            </div>
        </div>

        <!-- Profile Hero Card -->
        <div class="profile-hero">
            <div class="profile-hero-cover"></div>
            <div class="profile-hero-body">
                <div class="profile-hero-info">
                    <img src="<?php echo $avatar_src; ?>" alt="Profile Photo" class="profile-hero-avatar">
                    <div class="profile-hero-meta">
                        <h2 class="profile-hero-name"><?php echo $full_name; ?></h2>
                        <span class="profile-hero-role"><i class="bi bi-shield-check"></i> System Administrator</span>
                        <p class="profile-hero-email"><i class="bi bi-envelope me-1"></i> <?php echo htmlspecialchars($admin['email'] ?? ''); ?></p>
                        <div class="d-flex align-items-center gap-2 flex-wrap mt-1">
                            <?php if (!empty($admin['is_active'])): ?>
                                <span class="status-active"><i class="bi bi-circle-fill" style="font-size:0.5rem"></i> Active</span>
                            <?php else: ?>
                                <span class="status-inactive"><i class="bi bi-circle-fill" style="font-size:0.5rem"></i> Inactive</span>
                            <?php endif; ?>
                            <span class="badge-2fa <?php echo !empty($admin['two_factor_enabled']) ? 'enabled' : 'disabled'; ?>">
                                <i class="bi bi-shield-lock"></i> 2FA <?php echo !empty($admin['two_factor_enabled']) ? 'Enabled' : 'Disabled'; ?>
                            </span>
                        </div>
                    </div>
                    <div class="profile-hero-actions">
                        <button type="button" class="btn-gold" id="openEditProfile"><i class="bi bi-pencil-square"></i> Edit Profile</button>
                        <a href="admin_settings.php" class="btn-outline-admin"><i class="bi bi-gear"></i> Settings</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- KPI Stats -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-icon blue"><i class="bi bi-building"></i></div>
                <div class="kpi-label">Total Properties</div>
                <div class="kpi-value"><?php echo $total_properties; ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon gold"><i class="bi bi-person-badge"></i></div>
                <div class="kpi-label">Active Agents</div>
                <div class="kpi-value"><?php echo $total_agents; ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon green"><i class="bi bi-calendar-check"></i></div>
                <div class="kpi-label">Tour Requests</div>
                <div class="kpi-value"><?php echo $total_tours; ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon cyan"><i class="bi bi-check-circle"></i></div>
                <div class="kpi-label">Finalized Sales</div>
                <div class="kpi-value"><?php echo $total_sales; ?></div>
            </div>
        </div>

        <div class="row">
            <!-- Left Column -->
            <div class="col-lg-7">
                <!-- Account Details -->
                <div class="detail-card">
                    <div class="detail-card-header">
                        <i class="bi bi-person-vcard"></i>
                        <h3>Account Information</h3>
                    </div>
                    <div class="detail-card-body">
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-item-label"><i class="bi bi-person"></i> Full Name</div>
                                <div class="info-item-value"><?php echo $full_name; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-item-label"><i class="bi bi-at"></i> Username</div>
                                <div class="info-item-value"><?php echo htmlspecialchars($admin['username'] ?? ''); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-item-label"><i class="bi bi-envelope"></i> Email Address</div>
                                <div class="info-item-value"><?php echo htmlspecialchars($admin['email'] ?? ''); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-item-label"><i class="bi bi-phone"></i> Phone Number</div>
                                <div class="info-item-value"><?php echo htmlspecialchars($admin['phone_number'] ?? 'Not provided'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-item-label"><i class="bi bi-calendar3"></i> Date Registered</div>
                                <div class="info-item-value"><?php echo !empty($admin['date_registered']) ? date('F d, Y', strtotime($admin['date_registered'])) : 'N/A'; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-item-label"><i class="bi bi-shield-check"></i> Account Status</div>
                                <div class="info-item-value">
                                    <?php if (!empty($admin['is_active'])): ?>
                                        <span class="status-active"><i class="bi bi-circle-fill" style="font-size:0.5rem"></i> Active</span>
                                    <?php else: ?>
                                        <span class="status-inactive"><i class="bi bi-circle-fill" style="font-size:0.5rem"></i> Inactive</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Professional Details -->
                <div class="detail-card">
                    <div class="detail-card-header">
                        <i class="bi bi-briefcase"></i>
                        <h3>Professional Details</h3>
                    </div>
                    <div class="detail-card-body">
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-item-label"><i class="bi bi-credit-card"></i> License Number</div>
                                <div class="info-item-value"><?php echo htmlspecialchars($admin['license_number'] ?? 'Not set'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-item-label"><i class="bi bi-graph-up-arrow"></i> Years of Experience</div>
                                <div class="info-item-value"><?php echo isset($admin['years_experience']) ? $admin['years_experience'] . ' year(s)' : 'Not set'; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-item-label"><i class="bi bi-star"></i> Specialization</div>
                                <div class="info-item-value"><?php echo htmlspecialchars($admin['specialization'] ?? 'Not set'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-item-label"><i class="bi bi-check-circle"></i> Profile Status</div>
                                <div class="info-item-value">
                                    <?php if (!empty($admin['profile_completed'])): ?>
                                        <span class="status-active"><i class="bi bi-circle-fill" style="font-size:0.5rem"></i> Completed</span>
                                    <?php else: ?>
                                        <span class="status-inactive"><i class="bi bi-circle-fill" style="font-size:0.5rem"></i> Incomplete</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php if (!empty($admin['bio'])): ?>
                            <hr style="border-color: rgba(37,99,235,0.08); margin: 1.5rem 0;">
                            <div class="info-item-label mb-2"><i class="bi bi-chat-quote"></i> Professional Bio</div>
                            <p class="bio-text"><?php echo nl2br(htmlspecialchars($admin['bio'])); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Column -->
            <div class="col-lg-5">
                <!-- Security Info -->
                <div class="detail-card">
                    <div class="detail-card-header">
                        <i class="bi bi-shield-lock"></i>
                        <h3>Security</h3>
                    </div>
                    <div class="detail-card-body">
                        <div class="info-grid" style="grid-template-columns: 1fr;">
                            <div class="info-item">
                                <div class="info-item-label"><i class="bi bi-key"></i> Two-Factor Authentication</div>
                                <div class="info-item-value">
                                    <span class="badge-2fa <?php echo !empty($admin['two_factor_enabled']) ? 'enabled' : 'disabled'; ?>">
                                        <i class="bi bi-shield-lock"></i> <?php echo !empty($admin['two_factor_enabled']) ? 'Enabled' : 'Disabled'; ?>
                                    </span>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="info-item-label"><i class="bi bi-clock-history"></i> Last Login</div>
                                <div class="info-item-value">
                                    <?php echo !empty($recent_logs[0]['log_timestamp']) ? date('M d, Y \a\t h:i A', strtotime($recent_logs[0]['log_timestamp'])) : 'N/A'; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Login Activity -->
                <div class="detail-card">
                    <div class="detail-card-header">
                        <i class="bi bi-clock-history"></i>
                        <h3>Recent Login Activity</h3>
                    </div>
                    <div class="detail-card-body" style="padding: 0.5rem 1.5rem;">
                        <?php if (empty($recent_logs)): ?>
                            <div class="empty-state"><i class="bi bi-clock"></i><p>No login activity yet</p></div>
                        <?php else: ?>
                            <ul class="activity-list" id="loginActivityList">
                                <?php foreach ($recent_logs as $i => $log): ?>
                                    <li class="activity-item<?php echo $i >= 4 ? ' activity-hidden' : ''; ?>">
                                        <div class="activity-icon login"><i class="bi bi-box-arrow-in-right"></i></div>
                                        <div class="activity-content">
                                            <div class="activity-title">Admin Login</div>
                                            <div class="activity-time"><?php echo date('M d, Y \a\t h:i A', strtotime($log['log_timestamp'])); ?></div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if (count($recent_logs) > 4): ?>
                                <button class="see-more-btn" id="loginSeeMoreBtn" onclick="toggleActivityList('loginActivityList', 'loginSeeMoreBtn', <?php echo count($recent_logs); ?>)">
                                    <i class="bi bi-chevron-down"></i>
                                    <span>See <?php echo count($recent_logs) - 4; ?> more</span>
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Admin Actions History -->
                <div class="detail-card">
                    <div class="detail-card-header">
                        <i class="bi bi-journal-check"></i>
                        <h3>Review Actions</h3>
                    </div>
                    <div class="detail-card-body" style="padding: 0.5rem 1.5rem;">
                        <?php if (empty($action_logs)): ?>
                            <div class="empty-state"><i class="bi bi-journal"></i><p>No review actions yet</p></div>
                        <?php else: ?>
                            <ul class="activity-list" id="reviewActionsList">
                                <?php foreach ($action_logs as $i => $al): ?>
                                    <li class="activity-item<?php echo $i >= 4 ? ' activity-hidden' : ''; ?>">
                                        <div class="activity-icon <?php echo htmlspecialchars($al['action']); ?>">
                                            <i class="bi bi-<?php echo $al['action'] === 'approved' ? 'check-lg' : 'x-lg'; ?>"></i>
                                        </div>
                                        <div class="activity-content">
                                            <div class="activity-title">
                                                <?php echo ucfirst(htmlspecialchars($al['action'])); ?>
                                                <?php echo htmlspecialchars($al['item_type']); ?>:
                                                <?php echo htmlspecialchars($al['item_label'] ?? ''); ?>
                                            </div>
                                            <div class="activity-time"><?php echo date('M d, Y \a\t h:i A', strtotime($al['log_timestamp'])); ?></div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if (count($action_logs) > 4): ?>
                                <button class="see-more-btn" id="reviewSeeMoreBtn" onclick="toggleActivityList('reviewActionsList', 'reviewSeeMoreBtn', <?php echo count($action_logs); ?>)">
                                    <i class="bi bi-chevron-down"></i>
                                    <span>See <?php echo count($action_logs) - 4; ?> more</span>
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Profile Overlay -->
    <div class="edit-overlay" id="editProfileOverlay">
        <div class="edit-modal">
            <div class="edit-modal-header">
                <h3><i class="bi bi-pencil-square"></i> Edit Profile</h3>
                <button type="button" class="edit-modal-close" id="closeEditProfile"><i class="bi bi-x-lg"></i></button>
            </div>
            <form id="editProfileForm" enctype="multipart/form-data">
                <div class="edit-modal-body">
                    <!-- Profile Photo -->
                    <div class="edit-section-title"><i class="bi bi-camera"></i> Profile Photo</div>
                    <div class="edit-avatar-upload" style="margin-bottom: 1.5rem;">
                        <img src="<?php echo $avatar_src; ?>" alt="Avatar" class="edit-avatar-preview" id="editAvatarPreview">
                        <div class="edit-avatar-info">
                            <label class="upload-label" for="editProfilePicture">
                                <i class="bi bi-cloud-upload"></i> Change Photo
                            </label>
                            <input type="file" id="editProfilePicture" name="profile_picture" accept="image/jpeg,image/png,image/gif">
                            <div class="upload-hint">JPG, PNG or GIF. Max 5MB.</div>
                        </div>
                    </div>

                    <!-- Account Information -->
                    <div class="edit-section-title"><i class="bi bi-person-vcard"></i> Account Information</div>
                    <div class="edit-form-grid">
                        <div class="edit-form-group">
                            <label for="editFirstName">First Name <span class="required">*</span></label>
                            <input type="text" id="editFirstName" name="first_name" value="<?php echo htmlspecialchars($admin['first_name'] ?? ''); ?>" required>
                        </div>
                        <div class="edit-form-group">
                            <label for="editMiddleName">Middle Name</label>
                            <input type="text" id="editMiddleName" name="middle_name" value="<?php echo htmlspecialchars($admin['middle_name'] ?? ''); ?>">
                        </div>
                        <div class="edit-form-group">
                            <label for="editLastName">Last Name <span class="required">*</span></label>
                            <input type="text" id="editLastName" name="last_name" value="<?php echo htmlspecialchars($admin['last_name'] ?? ''); ?>" required>
                        </div>
                        <div class="edit-form-group">
                            <label for="editPhone">Phone Number</label>
                            <input type="text" id="editPhone" name="phone_number" value="<?php echo htmlspecialchars($admin['phone_number'] ?? ''); ?>" placeholder="e.g. +63 912 345 6789">
                        </div>
                        <div class="edit-form-group full-width">
                            <label for="editEmail">Email Address <span class="required">*</span></label>
                            <input type="email" id="editEmail" name="email" value="<?php echo htmlspecialchars($admin['email'] ?? ''); ?>" required>
                        </div>
                    </div>

                    <!-- Professional Details -->
                    <div class="edit-section-title"><i class="bi bi-briefcase"></i> Professional Details</div>
                    <div class="edit-form-grid">
                        <div class="edit-form-group">
                            <label for="editLicense">License Number <span class="required">*</span></label>
                            <input type="text" id="editLicense" name="license_number" value="<?php echo htmlspecialchars($admin['license_number'] ?? ''); ?>" required>
                        </div>
                        <div class="edit-form-group">
                            <label for="editExperience">Years of Experience <span class="required">*</span></label>
                            <input type="number" id="editExperience" name="years_experience" value="<?php echo htmlspecialchars($admin['years_experience'] ?? ''); ?>" min="0" max="60" required>
                        </div>
                        <div class="edit-form-group full-width">
                            <label>Specialization <span class="required">*</span></label>
                            <input type="hidden" id="editSpecializationHidden" name="specialization" value="<?php echo htmlspecialchars($admin['specialization'] ?? ''); ?>">
                            <div class="spec-chips-wrap" id="specChipsWrap">
                                <?php foreach ($all_specs as $spec): ?>
                                    <?php
                                        $spec_name = $spec['specialization_name'];
                                        $is_checked = in_array($spec_name, $current_specs);
                                        $chip_id = 'spec_' . $spec['specialization_id'];
                                    ?>
                                    <div class="spec-chip">
                                        <input type="checkbox" id="<?php echo $chip_id; ?>" value="<?php echo htmlspecialchars($spec_name); ?>"<?php echo $is_checked ? ' checked' : ''; ?> onchange="updateSpecHidden()">
                                        <label for="<?php echo $chip_id; ?>"><?php echo htmlspecialchars($spec_name); ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <span class="spec-none-msg" id="specNoneMsg">Select at least one specialization.</span>
                        </div>
                        <div class="edit-form-group full-width">
                            <label for="editBio">Professional Bio <span class="required">*</span></label>
                            <textarea id="editBio" name="bio" rows="4" required><?php echo htmlspecialchars($admin['bio'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>
                <div class="edit-modal-footer">
                    <button type="button" class="btn-cancel-edit" id="cancelEditProfile">Cancel</button>
                    <button type="submit" class="btn-save-edit" id="saveEditProfile">
                        <i class="bi bi-check-lg"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Toast -->
    <div class="profile-toast" id="profileToast"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleActivityList(listId, btnId, total) {
            const list = document.getElementById(listId);
            const btn  = document.getElementById(btnId);
            const hidden = list.querySelectorAll('.activity-hidden');
            const expanded = btn.classList.contains('expanded');

            if (expanded) {
                hidden.forEach(item => item.style.display = 'none');
                btn.classList.remove('expanded');
                btn.querySelector('span').textContent = 'See ' + hidden.length + ' more';
            } else {
                hidden.forEach(item => item.style.display = 'flex');
                btn.classList.add('expanded');
                btn.querySelector('span').textContent = 'See less';
            }
        }

        // ===== EDIT PROFILE =====
        const overlay = document.getElementById('editProfileOverlay');
        const editForm = document.getElementById('editProfileForm');
        const fileInput = document.getElementById('editProfilePicture');
        const avatarPreview = document.getElementById('editAvatarPreview');

        function openEditProfile() {
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        function closeEditProfile() {
            overlay.classList.remove('active');
            document.body.style.overflow = '';
        }

        document.getElementById('openEditProfile').addEventListener('click', openEditProfile);
        document.getElementById('closeEditProfile').addEventListener('click', closeEditProfile);
        document.getElementById('cancelEditProfile').addEventListener('click', closeEditProfile);

        // Close on overlay background click
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) closeEditProfile();
        });

        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && overlay.classList.contains('active')) closeEditProfile();
        });

        // Image preview on file selection
        fileInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    avatarPreview.src = e.target.result;
                };
                reader.readAsDataURL(this.files[0]);
            }
        });

        // Show toast
        function showToast(message, type) {
            const toast = document.getElementById('profileToast');
            toast.className = 'profile-toast ' + type;
            toast.innerHTML = '<i class="bi bi-' + (type === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill') + '"></i> ' + message;
            toast.classList.add('show');
            setTimeout(function() {
                toast.classList.remove('show');
            }, 4000);
        }

        // Specialization chips → hidden input
        function updateSpecHidden() {
            const checked = document.querySelectorAll('#specChipsWrap .spec-chip input[type="checkbox"]:checked');
            const values = Array.from(checked).map(cb => cb.value);
            document.getElementById('editSpecializationHidden').value = values.join(', ');
            document.getElementById('specNoneMsg').classList.toggle('visible', values.length === 0);
        }

        // Form submission via AJAX
        editForm.addEventListener('submit', function(e) {
            e.preventDefault();

            // Validate specialization
            const specVal = document.getElementById('editSpecializationHidden').value.trim();
            if (!specVal) {
                document.getElementById('specNoneMsg').classList.add('visible');
                document.getElementById('specChipsWrap').scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }

            const saveBtn = document.getElementById('saveEditProfile');
            const originalText = saveBtn.innerHTML;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> Saving...';

            const formData = new FormData(editForm);

            fetch('save_admin_info.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Profile updated successfully!', 'success');
                    setTimeout(function() {
                        window.location.reload();
                    }, 1200);
                } else {
                    showToast(data.message || 'Failed to update profile.', 'error');
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = originalText;
                }
            })
            .catch(function() {
                showToast('An error occurred. Please try again.', 'error');
                saveBtn.disabled = false;
                saveBtn.innerHTML = originalText;
            });
        });
    </script>
    <style>\n        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }\n        .spin { animation: spin 1s linear infinite; }\n    </style>
</html>
