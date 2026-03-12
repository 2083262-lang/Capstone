<?php
session_start();
include 'connection.php';
require_once __DIR__ . '/config/session_timeout.php';
require_once __DIR__ . '/config/paths.php';

if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Fetch amenities
$amenities = [];
$r = $conn->query("SELECT amenity_id, amenity_name FROM amenities ORDER BY amenity_name ASC");
if ($r) { while ($row = $r->fetch_assoc()) $amenities[] = $row; }

// Fetch specializations
$specializations = [];
$r = $conn->query("SELECT specialization_id, specialization_name FROM specializations ORDER BY specialization_name ASC");
if ($r) { while ($row = $r->fetch_assoc()) $specializations[] = $row; }

// Fetch property types
$property_types = [];
$r = $conn->query("SELECT property_type_id, type_name FROM property_types ORDER BY type_name ASC");
if ($r) { while ($row = $r->fetch_assoc()) $property_types[] = $row; }

$total_amenities = count($amenities);
$total_specializations = count($specializations);
$total_property_types = count($property_types);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="images/Logo.png" type="image/png">
    <link rel="shortcut icon" href="images/Logo.png" type="image/png">
    <title>System Settings - Admin Panel</title>
    <link href="<?= ASSETS_CSS ?>bootstrap.min.css" rel="stylesheet">
    <link href="<?= ASSETS_CSS ?>inter-font.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= ASSETS_CSS ?>fontawesome-all.min.css">
    <link rel="stylesheet" href="<?= ASSETS_CSS ?>bootstrap-icons.min.css">
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
        .page-header { background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; padding: 2rem 2.5rem; margin-bottom: 1.5rem; position: relative; overflow: hidden; }
        .page-header::before { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: radial-gradient(ellipse at top right, rgba(37,99,235,0.04) 0%, transparent 50%), radial-gradient(ellipse at bottom left, rgba(212,175,55,0.03) 0%, transparent 50%); pointer-events: none; }
        .page-header::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent); }
        .page-header-inner { position: relative; z-index: 2; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1.5rem; }
        .page-header h1 { font-size: 1.75rem; font-weight: 800; color: var(--text-primary); margin-bottom: 0.25rem; }
        .page-header .subtitle { color: var(--text-secondary); font-size: 0.95rem; }
        .page-header .header-badge { background: linear-gradient(135deg, var(--gold-dark), var(--gold)); color: #fff; font-size: 0.75rem; font-weight: 700; padding: 0.3rem 0.85rem; border-radius: 2px; text-transform: uppercase; letter-spacing: 0.5px; }

        /* ===== KPI STAT CARDS ===== */
        .kpi-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem; margin-bottom: 1.5rem; }
        .kpi-card { background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; padding: 1.75rem 1.5rem; position: relative; overflow: hidden; transition: all 0.3s ease; }
        .kpi-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, transparent, var(--blue), transparent); opacity: 0; transition: opacity 0.3s ease; }
        .kpi-card:hover { border-color: rgba(37,99,235,0.25); box-shadow: 0 8px 32px rgba(37,99,235,0.08); transform: translateY(-3px); }
        .kpi-card:hover::before { opacity: 1; }
        .kpi-card .kpi-icon { width: 40px; height: 40px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; margin-bottom: 0.75rem; }
        .kpi-icon.gold { background: linear-gradient(135deg, rgba(212,175,55,0.08), rgba(212,175,55,0.15)); color: var(--gold-dark); border: 1px solid rgba(212,175,55,0.2); }
        .kpi-icon.blue { background: linear-gradient(135deg, rgba(37,99,235,0.06), rgba(37,99,235,0.12)); color: var(--blue); border: 1px solid rgba(37,99,235,0.15); }
        .kpi-icon.green { background: linear-gradient(135deg, rgba(34,197,94,0.06), rgba(34,197,94,0.12)); color: #16a34a; border: 1px solid rgba(34,197,94,0.15); }
        .kpi-icon.cyan { background: linear-gradient(135deg, rgba(6,182,212,0.06), rgba(6,182,212,0.12)); color: #0891b2; border: 1px solid rgba(6,182,212,0.15); }
        .kpi-card .kpi-label { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: var(--text-secondary); margin-bottom: 0.25rem; }
        .kpi-card .kpi-value { font-size: 1.5rem; font-weight: 800; color: var(--text-primary); line-height: 1.2; }
        @media (max-width: 992px) { .kpi-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 768px) {
            .admin-content { padding: 1rem; }
            .page-header { padding: 1.25rem 1rem; }
            .page-header h1 { font-size: 1.3rem; }
            .page-header-inner { flex-direction: column; align-items: flex-start; gap: 0.75rem; }
            .kpi-grid { grid-template-columns: repeat(3, 1fr); gap: 0.75rem; }
            .kpi-card { padding: 1rem; }
            .settings-tabs .nav-tabs { overflow-x: auto; flex-wrap: nowrap; -webkit-overflow-scrolling: touch; }
            .settings-tabs .nav-link { white-space: nowrap; padding: 0.85rem 1rem; }
            .settings-tabs .tab-content { padding: 1rem; }
        }
        @media (max-width: 576px) {
            .admin-content { padding: 0.75rem; }
            .page-header { padding: 1rem; }
            .page-header h1 { font-size: 1.15rem; }
            .kpi-grid { grid-template-columns: 1fr 1fr 1fr; gap: 0.5rem; }
            .kpi-card { padding: 0.85rem; }
            .kpi-card .kpi-value { font-size: 1.15rem; }
            .settings-tabs .nav-link { padding: 0.75rem 0.85rem; font-size: 0.8rem; }
        }
        @media (max-width: 400px) {
            .kpi-grid { grid-template-columns: 1fr; }
        }

        /* ===== SETTINGS TABS ===== */
        .settings-tabs { background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; margin-bottom: 1.5rem; overflow: hidden; }
        .settings-tabs .nav-tabs { border: none; padding: 0.5rem 1rem 0; gap: 0; background: rgba(37,99,235,0.02); }
        .settings-tabs .nav-link { border: none; border-radius: 0; padding: 1rem 1.5rem; font-weight: 600; font-size: 0.88rem; color: var(--text-secondary); transition: all 0.3s ease; position: relative; background: transparent; display: flex; align-items: center; gap: 0.5rem; }
        .settings-tabs .nav-link:hover { color: var(--blue); background: rgba(37,99,235,0.04); }
        .settings-tabs .nav-link.active { color: var(--blue); background: transparent; }
        .settings-tabs .nav-link.active::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, var(--gold), var(--blue)); }
        .settings-tabs .tab-badge { font-size: 0.7rem; font-weight: 700; padding: 0.15rem 0.5rem; border-radius: 10px; background: rgba(37,99,235,0.1); color: var(--blue); }
        .settings-tabs .tab-content { padding: 1.5rem; }

        /* ===== SETTINGS CARD ===== */
        .settings-card { background: var(--card-bg); border: 1px solid rgba(37,99,235,0.1); border-radius: 4px; overflow: hidden; margin-bottom: 1.5rem; }
        .settings-card-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid rgba(37,99,235,0.08); display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; position: relative; }
        .settings-card-header::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 1px; background: linear-gradient(90deg, transparent, rgba(212,175,55,0.3), transparent); }
        .settings-card-header .header-left { display: flex; align-items: center; gap: 0.75rem; }
        .settings-card-header .header-left i { font-size: 1.1rem; color: var(--blue); }
        .settings-card-header .header-left h3 { font-size: 1rem; font-weight: 700; color: var(--text-primary); margin: 0; }
        .settings-card-header .header-left p { font-size: 0.8rem; color: var(--text-secondary); margin: 0; }
        .settings-card-body { padding: 1.5rem; }

        /* ===== ADD FORM ===== */
        .add-form { display: flex; gap: 0.75rem; margin-bottom: 1.5rem; }
        .add-form input { flex: 1; border: 1px solid rgba(37,99,235,0.2); border-radius: 4px; padding: 0.65rem 1rem; font-size: 0.9rem; font-family: 'Inter', sans-serif; transition: all 0.2s ease; }
        .add-form input:focus { outline: none; border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
        .add-form input::placeholder { color: #94a3b8; }

        /* ===== BUTTONS ===== */
        .btn-gold { background: linear-gradient(135deg, var(--gold-dark), var(--gold)); color: #fff; border: none; font-weight: 700; font-size: 0.85rem; padding: 0.6rem 1.25rem; border-radius: 4px; transition: all 0.3s ease; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; cursor: pointer; }
        .btn-gold:hover { background: linear-gradient(135deg, var(--gold), var(--gold-light)); color: #fff; transform: translateY(-1px); box-shadow: 0 4px 16px rgba(212,175,55,0.3); }
        .btn-blue { background: linear-gradient(135deg, var(--blue-dark), var(--blue)); color: #fff; border: none; font-weight: 700; font-size: 0.85rem; padding: 0.6rem 1.25rem; border-radius: 4px; transition: all 0.3s ease; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem; }
        .btn-blue:hover { background: linear-gradient(135deg, var(--blue), var(--blue-light)); color: #fff; transform: translateY(-1px); box-shadow: 0 4px 16px rgba(37,99,235,0.3); }

        /* ===== ITEM LIST (tags/chips style) ===== */
        .item-search { position: relative; margin-bottom: 1rem; }
        .item-search input { width: 100%; border: 1px solid rgba(37,99,235,0.15); border-radius: 4px; padding: 0.6rem 1rem 0.6rem 2.5rem; font-size: 0.88rem; font-family: 'Inter', sans-serif; }
        .item-search input:focus { outline: none; border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,0.08); }
        .item-search i { position: absolute; left: 0.85rem; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 0.85rem; }

        .items-grid { display: flex; flex-wrap: wrap; gap: 0.5rem; max-height: 400px; overflow-y: auto; padding: 0.25rem; }
        .item-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0.85rem;
            background: rgba(37, 99, 235, 0.04);
            border: 1px solid rgba(37, 99, 235, 0.12);
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--text-primary);
            transition: all 0.2s ease;
        }
        .item-chip:hover { border-color: rgba(37, 99, 235, 0.3); background: rgba(37, 99, 235, 0.08); }
        .item-chip .chip-name { white-space: nowrap; }
        .item-chip .chip-delete {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
            border: none;
            cursor: pointer;
            font-size: 0.7rem;
            transition: all 0.2s ease;
            padding: 0;
        }
        .item-chip .chip-delete:hover { background: #dc2626; color: #fff; transform: scale(1.15); }

        .item-count { font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.75rem; }

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
        .app-toast::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; }
        .app-toast.toast-success::before { background: linear-gradient(180deg, #d4af37, #b8941f); }
        .app-toast.toast-error::before   { background: linear-gradient(180deg, #ef4444, #dc2626); }
        .app-toast.toast-info::before    { background: linear-gradient(180deg, #2563eb, #1e40af); }
        .app-toast-icon { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 1rem; flex-shrink: 0; }
        .toast-success .app-toast-icon { background: rgba(212,175,55,0.12); color: #d4af37; }
        .toast-error   .app-toast-icon { background: rgba(239,68,68,0.1);   color: #ef4444; }
        .toast-info    .app-toast-icon { background: rgba(37,99,235,0.1);   color: #2563eb; }
        .app-toast-body  { flex: 1; min-width: 0; }
        .app-toast-title { font-size: 0.82rem; font-weight: 700; color: #111827; margin-bottom: 0.2rem; }
        .app-toast-msg   { font-size: 0.78rem; color: #6b7280; line-height: 1.4; word-break: break-word; }
        .app-toast-close { background: none; border: none; cursor: pointer; color: #9ca3af; font-size: 0.8rem; padding: 0; line-height: 1; flex-shrink: 0; transition: color .2s; }
        .app-toast-close:hover { color: #374151; }
        .app-toast-progress { position: absolute; bottom: 0; left: 0; height: 2px; border-radius: 0 0 0 12px; }
        .toast-success .app-toast-progress { background: linear-gradient(90deg, #d4af37, #b8941f); }
        .toast-error   .app-toast-progress { background: linear-gradient(90deg, #ef4444, #dc2626); }
        .toast-info    .app-toast-progress { background: linear-gradient(90deg, #2563eb, #1e40af); }
        @keyframes toast-progress { from { width: 100%; } to { width: 0%; } }

        .empty-state { text-align: center; padding: 2.5rem 1rem; color: var(--text-secondary); }
        .empty-state i { font-size: 2rem; opacity: 0.3; display: block; margin-bottom: 0.5rem; }



        @media (max-width: 768px) {
            .add-form { flex-direction: column; }
            .add-form input { width: 100%; }
            .settings-card-header { flex-direction: column; align-items: flex-start; }
            .settings-card-body { padding: 1rem; }
        }

        /* Delete confirmation */
        .delete-confirm-overlay {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 10000;
            display: flex; align-items: center; justify-content: center;
            backdrop-filter: blur(4px);
        }
        .delete-confirm-box {
            background: #fff;
            border-radius: 8px;
            padding: 2rem;
            max-width: 400px;
            width: 90%;
            box-shadow: 0 16px 48px rgba(0,0,0,0.2);
            text-align: center;
        }
        .delete-confirm-box h5 { font-weight: 700; margin-bottom: 0.5rem; }
        .delete-confirm-box p { color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 1.5rem; }
        .delete-confirm-box .btn-group-confirm { display: flex; gap: 0.75rem; justify-content: center; }
        .btn-cancel-del { background: #f1f5f9; color: var(--text-primary); border: 1px solid #e2e8f0; border-radius: 4px; padding: 0.5rem 1.25rem; font-weight: 600; font-size: 0.88rem; cursor: pointer; }
        .btn-cancel-del:hover { background: #e2e8f0; }
        .btn-confirm-del { background: #dc2626; color: #fff; border: none; border-radius: 4px; padding: 0.5rem 1.25rem; font-weight: 600; font-size: 0.88rem; cursor: pointer; }
        .btn-confirm-del:hover { background: #b91c1c; }

        /* ================================================================
           SKELETON SCREEN SYSTEM — Client-Side Rendering (CSR) Pattern
           Matches: admin_settings.php
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
        .sk-kpi-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:1rem; margin-bottom:1.5rem; }
        .sk-kpi-card { background:#fff; border-radius:4px; border:1px solid rgba(37,99,235,0.08); padding:1.25rem; display:flex; flex-direction:column; gap:0.6rem; }
        .sk-kpi-icon { width:40px; height:40px; border-radius:4px; flex-shrink:0; }
        .sk-tabs { background:#fff; border-radius:4px; border:1px solid rgba(37,99,235,0.08); padding:0.875rem 1.5rem; margin-bottom:0; display:flex; align-items:center; gap:0.75rem; min-height:56px; position:relative; overflow:hidden; }
        .sk-settings-card { background:#fff; border-radius:4px; border:1px solid rgba(37,99,235,0.08); overflow:hidden; }
        .sk-settings-card-header { padding:1.25rem 1.5rem; border-bottom:1px solid rgba(37,99,235,0.06); }
        .sk-settings-card-body { padding:1.5rem; }
        .sk-chips-wrap { display:grid; grid-template-columns:repeat(auto-fill, minmax(140px,1fr)); gap:0.5rem; margin-top:0.75rem; }
        .sk-line { display:block; border-radius:4px; }
        @media (max-width:992px) { .sk-kpi-grid { grid-template-columns:repeat(2,1fr); } }
        @media (max-width:576px) { .sk-kpi-grid { grid-template-columns:1fr 1fr; gap:0.5rem; } }
    </style>
</head>
<body>
    <?php $active_page = 'admin_settings.php'; include 'admin_sidebar.php'; ?>
    <?php include 'admin_navbar.php'; ?>

    <div class="admin-content">

        <noscript><style>
            #sk-screen    { display: none !important; }
            #page-content { display: block !important; opacity: 1 !important; }
        </style></noscript>

        <!-- ══════════════════════════════════════════════════════════
             SKELETON SCREEN — visible on first paint
        ══════════════════════════════════════════════════════════ -->
        <div id="sk-screen" role="presentation" aria-hidden="true">

            <!-- Page Header -->
            <div class="sk-page-header">
                <div class="sk-line sk-shimmer" style="width:170px;height:22px;margin-bottom:10px;"></div>
                <div class="sk-line sk-shimmer" style="width:360px;height:13px;"></div>
            </div>

            <!-- 3 KPI Cards -->
            <div class="sk-kpi-grid">
                <?php for ($i = 0; $i < 3; $i++): ?>
                <div class="sk-kpi-card">
                    <div class="sk-kpi-icon sk-shimmer"></div>
                    <div class="sk-line sk-shimmer" style="width:90px;height:11px;"></div>
                    <div class="sk-line sk-shimmer" style="width:45px;height:26px;"></div>
                </div>
                <?php endfor; ?>
            </div>

            <!-- Settings Tabs (3 tabs: Amenities, Specializations, Property Types) -->
            <div class="sk-tabs">
                <div class="sk-shimmer" style="width:95px;height:22px;border-radius:3px;"></div>
                <div class="sk-shimmer" style="width:130px;height:22px;border-radius:3px;"></div>
                <div class="sk-shimmer" style="width:120px;height:22px;border-radius:3px;"></div>
            </div>

            <!-- Settings Card (chip grid) -->
            <div class="sk-settings-card">
                <div class="sk-settings-card-header">
                    <div style="display:flex;align-items:center;gap:0.75rem;">
                        <div class="sk-shimmer" style="width:32px;height:32px;border-radius:6px;"></div>
                        <div style="display:flex;flex-direction:column;gap:0.35rem;">
                            <div class="sk-line sk-shimmer" style="width:150px;height:15px;"></div>
                            <div class="sk-line sk-shimmer" style="width:250px;height:12px;"></div>
                        </div>
                    </div>
                </div>
                <div class="sk-settings-card-body">
                    <!-- Add input row -->
                    <div style="display:flex;gap:0.75rem;margin-bottom:1rem;">
                        <div class="sk-shimmer" style="flex:1;height:40px;border-radius:4px;"></div>
                        <div class="sk-shimmer" style="width:130px;height:40px;border-radius:4px;"></div>
                    </div>
                    <!-- Search input -->
                    <div class="sk-shimmer" style="width:100%;height:38px;border-radius:4px;margin-bottom:1rem;"></div>
                    <!-- Chips grid -->
                    <div class="sk-chips-wrap">
                        <?php for ($i = 0; $i < 16; $i++): ?>
                        <div class="sk-shimmer" style="height:34px;border-radius:20px;"></div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

        </div><!-- /#sk-screen -->

        <div id="page-content">

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-inner">
                <div>
                    <h1>System Settings</h1>
                    <p class="subtitle">Manage amenities, specializations, and system configuration</p>
                </div>
                <span class="header-badge"><i class="bi bi-gear me-1"></i> Settings</span>
            </div>
        </div>

        <!-- KPI Stats -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-icon green"><i class="bi bi-check2-square"></i></div>
                <div class="kpi-label">Amenities</div>
                <div class="kpi-value"><?php echo $total_amenities; ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon cyan"><i class="bi bi-tags"></i></div>
                <div class="kpi-label">Specializations</div>
                <div class="kpi-value"><?php echo $total_specializations; ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon gold"><i class="bi bi-house-door"></i></div>
                <div class="kpi-label">Property Types</div>
                <div class="kpi-value"><?php echo $total_property_types; ?></div>
            </div>
        </div>

        <!-- Settings Tabs -->
        <div class="settings-tabs">
            <ul class="nav nav-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-amenities" type="button" role="tab">
                        <i class="bi bi-check2-square"></i> Amenities
                        <span class="tab-badge"><?php echo $total_amenities; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-specializations" type="button" role="tab">
                        <i class="bi bi-tags"></i> Specializations
                        <span class="tab-badge"><?php echo $total_specializations; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-property-types" type="button" role="tab">
                        <i class="bi bi-house-door"></i> Property Types
                        <span class="tab-badge"><?php echo $total_property_types; ?></span>
                    </button>
                </li>

            </ul>

            <div class="tab-content">
                <!-- === AMENITIES TAB === -->
                <div class="tab-pane fade show active" id="tab-amenities" role="tabpanel">
                    <div class="settings-card">
                        <div class="settings-card-header">
                            <div class="header-left">
                                <i class="bi bi-check2-square"></i>
                                <div>
                                    <h3>Property Amenities</h3>
                                    <p>Manage the amenities available for property listings</p>
                                </div>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <div class="add-form" id="amenityAddForm">
                                <input type="text" id="amenityInput" placeholder="Enter a new amenity name..." maxlength="100" autocomplete="off">
                                <button class="btn-blue" onclick="addItem('amenity')"><i class="bi bi-plus-lg"></i> Add Amenity</button>
                            </div>

                            <div class="item-search">
                                <i class="bi bi-search"></i>
                                <input type="text" id="amenitySearch" placeholder="Search amenities..." oninput="filterItems('amenity')">
                            </div>

                            <div class="items-grid" id="amenityGrid">
                                <?php foreach ($amenities as $a): ?>
                                    <div class="item-chip" data-id="<?php echo $a['amenity_id']; ?>" data-name="<?php echo htmlspecialchars(strtolower($a['amenity_name'])); ?>">
                                        <span class="chip-name"><?php echo htmlspecialchars($a['amenity_name']); ?></span>
                                        <button class="chip-delete" title="Delete" onclick="confirmDelete('amenity', <?php echo $a['amenity_id']; ?>, '<?php echo htmlspecialchars(addslashes($a['amenity_name'])); ?>')"><i class="bi bi-x"></i></button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="item-count" id="amenityCount"><?php echo $total_amenities; ?> amenities total</div>
                        </div>
                    </div>
                </div>

                <!-- === SPECIALIZATIONS TAB === -->
                <div class="tab-pane fade" id="tab-specializations" role="tabpanel">
                    <div class="settings-card">
                        <div class="settings-card-header">
                            <div class="header-left">
                                <i class="bi bi-tags"></i>
                                <div>
                                    <h3>Agent Specializations</h3>
                                    <p>Manage the specialization options available for agents</p>
                                </div>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <div class="add-form" id="specAddForm">
                                <input type="text" id="specInput" placeholder="Enter a new specialization name..." maxlength="100" autocomplete="off">
                                <button class="btn-blue" onclick="addItem('spec')"><i class="bi bi-plus-lg"></i> Add Specialization</button>
                            </div>

                            <div class="item-search">
                                <i class="bi bi-search"></i>
                                <input type="text" id="specSearch" placeholder="Search specializations..." oninput="filterItems('spec')">
                            </div>

                            <div class="items-grid" id="specGrid">
                                <?php foreach ($specializations as $s): ?>
                                    <div class="item-chip" data-id="<?php echo $s['specialization_id']; ?>" data-name="<?php echo htmlspecialchars(strtolower($s['specialization_name'])); ?>">
                                        <span class="chip-name"><?php echo htmlspecialchars($s['specialization_name']); ?></span>
                                        <button class="chip-delete" title="Delete" onclick="confirmDelete('spec', <?php echo $s['specialization_id']; ?>, '<?php echo htmlspecialchars(addslashes($s['specialization_name'])); ?>')"><i class="bi bi-x"></i></button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="item-count" id="specCount"><?php echo $total_specializations; ?> specializations total</div>
                        </div>
                    </div>
                </div>

                <!-- === PROPERTY TYPES TAB === -->
                <div class="tab-pane fade" id="tab-property-types" role="tabpanel">
                    <div class="settings-card">
                        <div class="settings-card-header">
                            <div class="header-left">
                                <i class="bi bi-house-door"></i>
                                <div>
                                    <h3>Property Types</h3>
                                    <p>Manage the property types available for property listings</p>
                                </div>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <div class="add-form" id="ptypeAddForm">
                                <input type="text" id="ptypeInput" placeholder="Enter a new property type name..." maxlength="100" autocomplete="off">
                                <button class="btn-blue" onclick="addItem('ptype')"><i class="bi bi-plus-lg"></i> Add Property Type</button>
                            </div>

                            <div class="item-search">
                                <i class="bi bi-search"></i>
                                <input type="text" id="ptypeSearch" placeholder="Search property types..." oninput="filterItems('ptype')">
                            </div>

                            <div class="items-grid" id="ptypeGrid">
                                <?php foreach ($property_types as $pt): ?>
                                    <div class="item-chip" data-id="<?php echo $pt['property_type_id']; ?>" data-name="<?php echo htmlspecialchars(strtolower($pt['type_name'])); ?>">
                                        <span class="chip-name"><?php echo htmlspecialchars($pt['type_name']); ?></span>
                                        <button class="chip-delete" title="Delete" onclick="confirmDelete('ptype', <?php echo $pt['property_type_id']; ?>, '<?php echo htmlspecialchars(addslashes($pt['type_name'])); ?>')"><i class="bi bi-x"></i></button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="item-count" id="ptypeCount"><?php echo $total_property_types; ?> property types total</div>
                        </div>
                    </div>
                </div>


            </div>
        </div>
    </div><!-- /#page-content -->
    </div>

    <!-- Delete Confirmation (dynamic) -->
    <div id="deleteConfirmOverlay" class="delete-confirm-overlay" style="display:none;">
        <div class="delete-confirm-box">
            <div style="width:48px;height:48px;border-radius:50%;background:rgba(239,68,68,0.1);display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
                <i class="bi bi-exclamation-triangle" style="font-size:1.3rem;color:#dc2626;"></i>
            </div>
            <h5>Delete <span id="deleteItemType"></span>?</h5>
            <p>Are you sure you want to delete "<strong id="deleteItemName"></strong>"? This action cannot be undone.</p>
            <div class="btn-group-confirm">
                <button class="btn-cancel-del" onclick="closeDeleteConfirm()">Cancel</button>
                <button class="btn-confirm-del" id="deleteConfirmBtn" onclick="executeDelete()"><i class="bi bi-trash me-1"></i> Delete</button>
            </div>
        </div>
    </div>

    <!-- Toast Container -->
    <div id="toastContainer"></div>

    <script src="<?= ASSETS_JS ?>bootstrap.bundle.min.js"></script>
    <script>
    var pendingDelete = { type: null, id: null, name: null };

    // ===== ADD ITEM =====
    function addItem(type) {
        const inputId = type === 'amenity' ? 'amenityInput' : (type === 'ptype' ? 'ptypeInput' : 'specInput');
        const input = document.getElementById(inputId);
        const name = input.value.trim();

        if (!name) { input.focus(); return; }
        if (name.length > 100) { showToast('error', 'Validation Error', 'Name must be 100 characters or less.'); return; }

        const fd = new FormData();
        fd.append('action', 'add');
        fd.append('type', type === 'amenity' ? 'amenity' : (type === 'ptype' ? 'property_type' : 'specialization'));
        fd.append('name', name);

        fetch('admin_settings_api.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    input.value = '';
                    showToast('success', 'Added', data.message);
                    // Add chip dynamically
                    const grid = document.getElementById(type === 'amenity' ? 'amenityGrid' : (type === 'ptype' ? 'ptypeGrid' : 'specGrid'));
                    const chip = document.createElement('div');
                    chip.className = 'item-chip';
                    chip.dataset.id = data.id;
                    chip.dataset.name = name.toLowerCase();
                    chip.innerHTML = `<span class="chip-name">${escapeHtml(name)}</span>
                        <button class="chip-delete" title="Delete" onclick="confirmDelete('${type}', ${data.id}, '${escapeHtml(name).replace(/'/g, "\\'")}')"><i class="bi bi-x"></i></button>`;
                    grid.appendChild(chip);
                    updateCount(type);
                    updateBadge(type);
                } else {
                    showToast('error', 'Error', data.message || 'Failed to add.');
                }
            })
            .catch(() => showToast('error', 'Error', 'Network error. Please try again.'));
    }

    // Allow Enter key to add
    document.getElementById('amenityInput').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addItem('amenity'); } });
    document.getElementById('specInput').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addItem('spec'); } });
    document.getElementById('ptypeInput').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addItem('ptype'); } });

    // ===== DELETE =====
    function confirmDelete(type, id, name) {
        pendingDelete = { type, id, name };
        document.getElementById('deleteItemType').textContent = type === 'amenity' ? 'Amenity' : (type === 'ptype' ? 'Property Type' : 'Specialization');
        document.getElementById('deleteItemName').textContent = name;
        document.getElementById('deleteConfirmOverlay').style.display = 'flex';
    }

    function closeDeleteConfirm() {
        document.getElementById('deleteConfirmOverlay').style.display = 'none';
        pendingDelete = { type: null, id: null, name: null };
    }

    function executeDelete() {
        if (!pendingDelete.type || !pendingDelete.id) return;

        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('type', pendingDelete.type === 'amenity' ? 'amenity' : (pendingDelete.type === 'ptype' ? 'property_type' : 'specialization'));
        fd.append('id', pendingDelete.id);

        fetch('admin_settings_api.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const grid = document.getElementById(pendingDelete.type === 'amenity' ? 'amenityGrid' : (pendingDelete.type === 'ptype' ? 'ptypeGrid' : 'specGrid'));
                    const chip = grid.querySelector(`.item-chip[data-id="${pendingDelete.id}"]`);
                    if (chip) {
                        chip.style.transition = 'all 0.3s ease';
                        chip.style.opacity = '0';
                        chip.style.transform = 'scale(0.8)';
                        setTimeout(() => { chip.remove(); updateCount(pendingDelete.type); updateBadge(pendingDelete.type); }, 300);
                    }
                    showToast('success', 'Deleted', data.message);
                } else {
                    showToast('error', 'Error', data.message || 'Failed to delete.');
                }
                closeDeleteConfirm();
            })
            .catch(() => { showToast('error', 'Error', 'Network error.'); closeDeleteConfirm(); });
    }

    // Close overlay on background click
    document.getElementById('deleteConfirmOverlay').addEventListener('click', function(e) {
        if (e.target === this) closeDeleteConfirm();
    });

    // ===== FILTER/SEARCH =====
    function filterItems(type) {
        const searchId = type === 'amenity' ? 'amenitySearch' : (type === 'ptype' ? 'ptypeSearch' : 'specSearch');
        const gridId = type === 'amenity' ? 'amenityGrid' : (type === 'ptype' ? 'ptypeGrid' : 'specGrid');
        const q = document.getElementById(searchId).value.toLowerCase().trim();
        const chips = document.getElementById(gridId).querySelectorAll('.item-chip');
        let visible = 0;
        chips.forEach(chip => {
            const match = chip.dataset.name.includes(q);
            chip.style.display = match ? '' : 'none';
            if (match) visible++;
        });
    }

    // ===== UTILITIES =====
    function updateCount(type) {
        const gridId = type === 'amenity' ? 'amenityGrid' : (type === 'ptype' ? 'ptypeGrid' : 'specGrid');
        const countId = type === 'amenity' ? 'amenityCount' : (type === 'ptype' ? 'ptypeCount' : 'specCount');
        const label = type === 'amenity' ? 'amenities' : (type === 'ptype' ? 'property types' : 'specializations');
        const total = document.getElementById(gridId).querySelectorAll('.item-chip').length;
        document.getElementById(countId).textContent = total + ' ' + label + ' total';
    }

    function updateBadge(type) {
        const gridId = type === 'amenity' ? 'amenityGrid' : (type === 'ptype' ? 'ptypeGrid' : 'specGrid');
        const total = document.getElementById(gridId).querySelectorAll('.item-chip').length;
        // Update the tab badge
        const tabIndex = type === 'amenity' ? 0 : (type === 'spec' ? 1 : 2);
        const badges = document.querySelectorAll('.settings-tabs .tab-badge');
        if (badges[tabIndex]) badges[tabIndex].textContent = total;
    }

    // ===== TOAST =====
    function showToast(type, title, message, duration) {
        duration = duration || 4500;
        const container = document.getElementById('toastContainer');
        const icons = { success: 'bi-check-circle-fill', error: 'bi-x-circle-fill', info: 'bi-info-circle-fill' };
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

    function escapeHtml(str) {
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }
    </script>

    <!-- ══════════════════════════════════════════════════════════
         SKELETON HYDRATION SCRIPT
         Waits for window 'load' (fonts + CSS ready) then
         cross-fades skeleton out and real content in.
    ══════════════════════════════════════════════════════════ -->
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
