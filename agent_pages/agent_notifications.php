<?php
session_start();
include '../connection.php';
require_once 'agent_notification_helper.php';

// Auth check
if (!isset($_SESSION['account_id']) || $_SESSION['user_role'] !== 'agent') {
    header("Location: ../login.php");
    exit();
}

$agent_account_id = (int)$_SESSION['account_id'];
$agent_username = $_SESSION['username'];

// ── Handle AJAX actions (POST) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    if ($action === 'mark_read' && !empty($_POST['notification_id'])) {
        $nid = (int)$_POST['notification_id'];
        $stmt = $conn->prepare("UPDATE agent_notifications SET is_read = 1 WHERE notification_id = ? AND agent_account_id = ?");
        $stmt->bind_param("ii", $nid, $agent_account_id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true]);
        exit();
    }
    
    if ($action === 'mark_all_read') {
        $stmt = $conn->prepare("UPDATE agent_notifications SET is_read = 1 WHERE agent_account_id = ? AND is_read = 0");
        $stmt->bind_param("i", $agent_account_id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true]);
        exit();
    }
    
    if ($action === 'delete' && !empty($_POST['notification_id'])) {
        $nid = (int)$_POST['notification_id'];
        $stmt = $conn->prepare("DELETE FROM agent_notifications WHERE notification_id = ? AND agent_account_id = ?");
        $stmt->bind_param("ii", $nid, $agent_account_id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true]);
        exit();
    }
    
    echo json_encode(['error' => 'Unknown action']);
    exit();
}

// ── Filters ──
$filter = $_GET['filter'] ?? 'all';
$type_filter = $_GET['type'] ?? 'all';

$where = ["agent_account_id = ?"];
$params = [$agent_account_id];
$types = "i";

if ($filter === 'unread') {
    $where[] = "is_read = 0";
} elseif ($filter === 'read') {
    $where[] = "is_read = 1";
}

if ($type_filter !== 'all') {
    $where[] = "notif_type = ?";
    $params[] = $type_filter;
    $types .= "s";
}

$where_clause = "WHERE " . implode(" AND ", $where);

// Fetch notifications
$sql = "SELECT notification_id, notif_type, reference_id, title, message, is_read, created_at FROM agent_notifications $where_clause ORDER BY created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$notifications = [];
while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}
$stmt->close();

// Counts
$count_sql = "SELECT 
    COUNT(*) as total,
    SUM(is_read = 0) as unread,
    SUM(notif_type LIKE 'tour%') as tours,
    SUM(notif_type LIKE 'property%') as properties,
    SUM(notif_type LIKE 'sale%' OR notif_type = 'commission_paid') as sales
    FROM agent_notifications WHERE agent_account_id = ?";
$stmt = $conn->prepare($count_sql);
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$counts = $stmt->get_result()->fetch_assoc();
$stmt->close();

$total_count = (int)($counts['total'] ?? 0);
$unread_count = (int)($counts['unread'] ?? 0);
$tour_count = (int)($counts['tours'] ?? 0);
$property_count = (int)($counts['properties'] ?? 0);
$sale_count = (int)($counts['sales'] ?? 0);

// Fetch agent info for navbar
$agent_info_query = "SELECT ai.*, a.first_name, a.last_name, a.email FROM agent_information ai JOIN accounts a ON ai.account_id = a.account_id WHERE ai.account_id = ?";
$stmt = $conn->prepare($agent_info_query);
$stmt->bind_param("i", $agent_account_id);
$stmt->execute();
$agent_info = $stmt->get_result()->fetch_assoc();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - HomeEstate Realty</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
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
            --gray-300: #b8bec4;
            --gray-400: #9ca4ab;
            --gray-500: #7a8a99;
            --gray-600: #5d6d7d;
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
        ::-webkit-scrollbar-thumb { background: linear-gradient(180deg, var(--gold), var(--gold-dark)); border-radius: 4px; }

        .notif-page-content {
            padding: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* ===== Page Header ===== */
        .notif-page-hero {
            background: linear-gradient(135deg, var(--black) 0%, var(--black-lighter) 100%);
            border: 1px solid var(--card-border);
            border-radius: 4px;
            padding: 2rem 2.5rem;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }

        .notif-page-hero::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--gold), var(--blue), var(--gold));
        }

        .notif-page-hero h1 {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--gold);
            margin-bottom: 0.35rem;
        }

        .notif-page-hero p {
            color: var(--gray-400);
            font-size: 0.95rem;
            margin: 0;
        }

        /* ===== Stat Cards ===== */
        .notif-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .notif-stat-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 4px;
            padding: 1.25rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.25s ease;
        }

        .notif-stat-card:hover {
            border-color: var(--card-hover-border);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.3);
        }

        .notif-stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .notif-stat-icon.total      { background: rgba(37, 99, 235, 0.12); color: var(--blue-light); }
        .notif-stat-icon.unread     { background: rgba(239, 68, 68, 0.12); color: #ef4444; }
        .notif-stat-icon.tours      { background: rgba(13, 202, 240, 0.12); color: #0dcaf0; }
        .notif-stat-icon.properties { background: rgba(34, 197, 94, 0.12); color: #22c55e; }
        .notif-stat-icon.sales      { background: rgba(212, 175, 55, 0.12); color: var(--gold); }

        .notif-stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .notif-stat-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* ===== Filter Bar ===== */
        .notif-filter-bar {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 4px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .notif-filter-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--gray-400);
            white-space: nowrap;
        }

        .notif-filter-tabs {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .notif-filter-tab {
            padding: 0.45rem 1rem;
            border: 1px solid rgba(255,255,255,0.08);
            background: transparent;
            color: var(--gray-300);
            border-radius: 4px;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .notif-filter-tab:hover {
            background: rgba(37, 99, 235, 0.08);
            border-color: rgba(37, 99, 235, 0.3);
            color: var(--white);
        }

        .notif-filter-tab.active {
            background: var(--blue);
            border-color: var(--blue);
            color: var(--white);
        }

        .notif-filter-count {
            background: rgba(255,255,255,0.15);
            padding: 1px 7px;
            border-radius: 8px;
            font-size: 0.7rem;
            font-weight: 700;
        }

        .notif-filter-tab.active .notif-filter-count {
            background: rgba(255,255,255,0.3);
        }

        .notif-filter-divider {
            width: 1px;
            height: 24px;
            background: rgba(255,255,255,0.08);
            margin: 0 0.5rem;
        }

        /* ===== Notifications List ===== */
        .notif-list-container {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 4px;
            overflow: hidden;
        }

        .notif-list-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid rgba(255,255,255,0.06);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .notif-list-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--gray-300);
        }

        .notif-list-title span { color: var(--gold); }

        .btn-mark-all-read {
            background: rgba(212, 175, 55, 0.12);
            border: 1px solid rgba(212, 175, 55, 0.25);
            color: var(--gold);
            padding: 0.4rem 1rem;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-mark-all-read:hover {
            background: rgba(212, 175, 55, 0.2);
            border-color: var(--gold);
            transform: translateY(-1px);
        }

        /* Notification Row */
        .notif-row {
            display: flex;
            gap: 14px;
            padding: 1.1rem 1.5rem;
            border-bottom: 1px solid rgba(255,255,255,0.04);
            align-items: flex-start;
            transition: background 0.15s ease;
        }

        .notif-row:last-child { border-bottom: none; }

        .notif-row:hover { background: rgba(37, 99, 235, 0.04); }

        .notif-row.unread {
            background: rgba(212, 175, 55, 0.03);
            border-left: 3px solid var(--gold);
        }

        .notif-row.unread:hover { background: rgba(212, 175, 55, 0.06); }

        .notif-row-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .notif-row-icon.tour       { background: rgba(13, 202, 240, 0.12); color: #0dcaf0; }
        .notif-row-icon.approved   { background: rgba(34, 197, 94, 0.12); color: #22c55e; }
        .notif-row-icon.rejected   { background: rgba(239, 68, 68, 0.12); color: #ef4444; }
        .notif-row-icon.cancelled  { background: rgba(249, 115, 22, 0.12); color: #f97316; }
        .notif-row-icon.completed  { background: rgba(34, 197, 94, 0.12); color: #22c55e; }
        .notif-row-icon.sale       { background: rgba(212, 175, 55, 0.12); color: var(--gold); }
        .notif-row-icon.commission { background: rgba(168, 85, 247, 0.12); color: #a855f7; }
        .notif-row-icon.general    { background: rgba(37, 99, 235, 0.12); color: var(--blue-light); }

        .notif-row-body { flex: 1; min-width: 0; }

        .notif-row-title {
            font-size: 0.9rem;
            font-weight: 600;
            color: #d1d5db;
            margin-bottom: 2px;
        }

        .notif-row.unread .notif-row-title { color: var(--white); }

        .notif-row-msg {
            font-size: 0.82rem;
            color: var(--gray-500);
            line-height: 1.4;
            margin-bottom: 6px;
        }

        .notif-row-meta {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .notif-row-time {
            font-size: 0.75rem;
            color: var(--gray-600);
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .notif-type-pill {
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 2px 10px;
            border-radius: 10px;
        }

        .pill-tour       { background: rgba(13, 202, 240, 0.12); color: #0dcaf0; }
        .pill-property   { background: rgba(34, 197, 94, 0.12); color: #22c55e; }
        .pill-sale       { background: rgba(212, 175, 55, 0.12); color: var(--gold); }
        .pill-commission { background: rgba(168, 85, 247, 0.12); color: #a855f7; }
        .pill-general    { background: rgba(37, 99, 235, 0.12); color: var(--blue-light); }

        .notif-row-actions {
            display: flex;
            gap: 6px;
            align-items: center;
            flex-shrink: 0;
        }

        .notif-action-btn {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.2s ease;
        }

        .notif-action-btn.read-btn {
            background: rgba(34, 197, 94, 0.1);
            color: #22c55e;
        }

        .notif-action-btn.read-btn:hover {
            background: rgba(34, 197, 94, 0.2);
            transform: translateY(-1px);
        }

        .notif-action-btn.delete-btn {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }

        .notif-action-btn.delete-btn:hover {
            background: rgba(239, 68, 68, 0.2);
            transform: translateY(-1px);
        }

        .notif-unread-dot {
            width: 8px;
            height: 8px;
            background: var(--gold);
            border-radius: 50%;
            flex-shrink: 0;
            margin-top: 6px;
        }

        /* Empty state */
        .notif-empty-state {
            text-align: center;
            padding: 4rem 2rem;
        }

        .notif-empty-state i {
            font-size: 3.5rem;
            color: #374151;
            margin-bottom: 1rem;
            display: block;
        }

        .notif-empty-state h5 {
            font-weight: 700;
            color: var(--gray-300);
            margin-bottom: 0.5rem;
        }

        .notif-empty-state p {
            color: var(--gray-600);
            font-size: 0.9rem;
        }

        /* Active filters bar */
        .notif-active-filters {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-bottom: 1.25rem;
        }

        .notif-active-label {
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--gray-500);
        }

        .notif-active-badge {
            background: rgba(37, 99, 235, 0.15);
            border: 1px solid rgba(37, 99, 235, 0.3);
            color: var(--blue-light);
            padding: 0.3rem 0.75rem;
            border-radius: 15px;
            font-size: 0.78rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .notif-active-badge a {
            color: inherit;
            opacity: 0.7;
            transition: opacity 0.2s;
        }

        .notif-active-badge a:hover { opacity: 1; }

        .notif-clear-all {
            color: #ef4444;
            text-decoration: none;
            font-size: 0.78rem;
            font-weight: 600;
            padding: 0.3rem 0.75rem;
            border-radius: 4px;
            border: 1px solid rgba(239, 68, 68, 0.3);
            transition: all 0.2s;
        }

        .notif-clear-all:hover {
            background: rgba(239, 68, 68, 0.1);
            color: #f87171;
        }

        @media (max-width: 768px) {
            .notif-page-content { padding: 1rem; }
            .notif-page-hero { padding: 1.5rem; }
            .notif-page-hero h1 { font-size: 1.35rem; }
            .notif-stats { grid-template-columns: repeat(2, 1fr); }
            .notif-row { flex-wrap: wrap; }
            .notif-row-actions { width: 100%; justify-content: flex-end; margin-top: 8px; }
            .notif-filter-bar { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>
    <?php 
    $active_page = 'agent_notifications.php';
    include 'agent_navbar.php'; 
    include 'logout_agent_modal.php';
    ?>

    <div class="notif-page-content">
        <!-- Page Header -->
        <div class="notif-page-hero">
            <h1><i class="bi bi-bell-fill me-2"></i>Notifications</h1>
            <p>Stay updated with tour requests, property approvals, sales, and more</p>
        </div>

        <!-- Stat Cards -->
        <div class="notif-stats">
            <div class="notif-stat-card">
                <div class="notif-stat-icon total"><i class="bi bi-bell"></i></div>
                <div>
                    <div class="notif-stat-value"><?= $total_count ?></div>
                    <div class="notif-stat-label">Total</div>
                </div>
            </div>
            <div class="notif-stat-card">
                <div class="notif-stat-icon unread"><i class="bi bi-envelope"></i></div>
                <div>
                    <div class="notif-stat-value"><?= $unread_count ?></div>
                    <div class="notif-stat-label">Unread</div>
                </div>
            </div>
            <div class="notif-stat-card">
                <div class="notif-stat-icon tours"><i class="bi bi-calendar-check"></i></div>
                <div>
                    <div class="notif-stat-value"><?= $tour_count ?></div>
                    <div class="notif-stat-label">Tour Requests</div>
                </div>
            </div>
            <div class="notif-stat-card">
                <div class="notif-stat-icon properties"><i class="bi bi-building"></i></div>
                <div>
                    <div class="notif-stat-value"><?= $property_count ?></div>
                    <div class="notif-stat-label">Property Updates</div>
                </div>
            </div>
            <div class="notif-stat-card">
                <div class="notif-stat-icon sales"><i class="bi bi-cash-stack"></i></div>
                <div>
                    <div class="notif-stat-value"><?= $sale_count ?></div>
                    <div class="notif-stat-label">Sales & Commissions</div>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="notif-filter-bar">
            <span class="notif-filter-label"><i class="bi bi-funnel me-1"></i>Status:</span>
            <div class="notif-filter-tabs">
                <a href="?filter=all&type=<?= $type_filter ?>" class="notif-filter-tab <?= $filter === 'all' ? 'active' : '' ?>">All <span class="notif-filter-count"><?= $total_count ?></span></a>
                <a href="?filter=unread&type=<?= $type_filter ?>" class="notif-filter-tab <?= $filter === 'unread' ? 'active' : '' ?>">Unread <span class="notif-filter-count"><?= $unread_count ?></span></a>
                <a href="?filter=read&type=<?= $type_filter ?>" class="notif-filter-tab <?= $filter === 'read' ? 'active' : '' ?>">Read</a>
            </div>

            <div class="notif-filter-divider"></div>

            <span class="notif-filter-label">Type:</span>
            <div class="notif-filter-tabs">
                <a href="?filter=<?= $filter ?>&type=all" class="notif-filter-tab <?= $type_filter === 'all' ? 'active' : '' ?>">All</a>
                <a href="?filter=<?= $filter ?>&type=tour_new" class="notif-filter-tab <?= $type_filter === 'tour_new' ? 'active' : '' ?>"><i class="bi bi-calendar-plus me-1"></i>Tours</a>
                <a href="?filter=<?= $filter ?>&type=property_approved" class="notif-filter-tab <?= ($type_filter === 'property_approved' || $type_filter === 'property_rejected') ? 'active' : '' ?>"><i class="bi bi-building me-1"></i>Property</a>
                <a href="?filter=<?= $filter ?>&type=sale_approved" class="notif-filter-tab <?= ($type_filter === 'sale_approved' || $type_filter === 'sale_rejected') ? 'active' : '' ?>"><i class="bi bi-cash-stack me-1"></i>Sales</a>
            </div>
        </div>

        <!-- Active Filters -->
        <?php if ($filter !== 'all' || $type_filter !== 'all'): ?>
        <div class="notif-active-filters">
            <span class="notif-active-label">Active Filters:</span>
            <?php if ($filter !== 'all'): ?>
                <span class="notif-active-badge"><?= ucfirst($filter) ?> <a href="?filter=all&type=<?= $type_filter ?>"><i class="bi bi-x"></i></a></span>
            <?php endif; ?>
            <?php if ($type_filter !== 'all'): ?>
                <span class="notif-active-badge"><?= ucfirst(str_replace('_', ' ', $type_filter)) ?> <a href="?filter=<?= $filter ?>&type=all"><i class="bi bi-x"></i></a></span>
            <?php endif; ?>
            <a href="?" class="notif-clear-all">Clear All</a>
        </div>
        <?php endif; ?>

        <!-- Notifications List -->
        <div class="notif-list-container">
            <div class="notif-list-header">
                <div class="notif-list-title"><span><?= count($notifications) ?></span> notification<?= count($notifications) !== 1 ? 's' : '' ?></div>
                <?php if ($unread_count > 0): ?>
                    <button class="btn-mark-all-read" onclick="markAllRead()"><i class="bi bi-check2-all me-1"></i>Mark All Read</button>
                <?php endif; ?>
            </div>

            <?php if (empty($notifications)): ?>
                <div class="notif-empty-state">
                    <i class="bi bi-bell-slash"></i>
                    <h5>No Notifications</h5>
                    <p>
                        <?php if ($filter !== 'all' || $type_filter !== 'all'): ?>
                            No results match your current filters. Try adjusting them.
                        <?php else: ?>
                            You're all caught up! New notifications will appear here.
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <?php foreach ($notifications as $n): 
                    $is_unread = (int)$n['is_read'] === 0;
                    list($icon, $color) = getNotifIcon($n['notif_type']);
                    $time_ago = formatNotifTimeAgo($n['created_at']);
                    
                    // Pill class
                    $pill_class = 'pill-general';
                    if (strpos($n['notif_type'], 'tour') !== false) $pill_class = 'pill-tour';
                    elseif (strpos($n['notif_type'], 'property') !== false) $pill_class = 'pill-property';
                    elseif (strpos($n['notif_type'], 'sale') !== false) $pill_class = 'pill-sale';
                    elseif ($n['notif_type'] === 'commission_paid') $pill_class = 'pill-commission';
                    
                    $type_label = ucfirst(str_replace('_', ' ', $n['notif_type']));
                ?>
                    <div class="notif-row <?= $is_unread ? 'unread' : '' ?>" id="notif-<?= $n['notification_id'] ?>">
                        <?php if ($is_unread): ?><div class="notif-unread-dot"></div><?php endif; ?>
                        <div class="notif-row-icon <?= $color ?>"><i class="<?= $icon ?>"></i></div>
                        <div class="notif-row-body">
                            <div class="notif-row-title"><?= htmlspecialchars($n['title']) ?></div>
                            <div class="notif-row-msg"><?= htmlspecialchars($n['message']) ?></div>
                            <div class="notif-row-meta">
                                <span class="notif-row-time"><i class="bi bi-clock"></i> <?= $time_ago ?></span>
                                <span class="notif-type-pill <?= $pill_class ?>"><?= $type_label ?></span>
                            </div>
                        </div>
                        <div class="notif-row-actions">
                            <?php if ($is_unread): ?>
                                <button class="notif-action-btn read-btn" onclick="markRead(<?= $n['notification_id'] ?>)" title="Mark as read">
                                    <i class="bi bi-check-lg"></i>
                                </button>
                            <?php endif; ?>
                            <button class="notif-action-btn delete-btn" onclick="deleteNotif(<?= $n['notification_id'] ?>)" title="Delete">
                                <i class="bi bi-trash3"></i>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function markRead(id) {
        fetch(window.location.pathname, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=mark_read&notification_id=' + id
        })
        .then(r => r.json())
        .then(d => { if (d.success) location.reload(); });
    }

    function markAllRead() {
        fetch(window.location.pathname, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=mark_all_read'
        })
        .then(r => r.json())
        .then(d => { if (d.success) location.reload(); });
    }

    function deleteNotif(id) {
        const row = document.getElementById('notif-' + id);
        if (row) {
            row.style.transition = 'opacity 0.3s, transform 0.3s';
            row.style.opacity = '0';
            row.style.transform = 'translateX(30px)';
        }
        setTimeout(() => {
            fetch(window.location.pathname, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=delete&notification_id=' + id
            })
            .then(r => r.json())
            .then(d => { if (d.success) location.reload(); });
        }, 300);
    }
    </script>
</body>
</html>
<?php $conn->close(); ?>
