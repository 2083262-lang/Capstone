<?php
session_start();
require_once 'connection.php';

// Check if admin is logged in
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
    header("Location: login.php");
    exit();
}

// ── AJAX Handlers ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    // Mark single notification as read
    if (isset($_POST['mark_read']) && isset($_POST['notification_id'])) {
        $nid = (int)$_POST['notification_id'];
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id = ?");
        $stmt->bind_param("i", $nid);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true]);
        exit();
    }

    // Mark all as read
    if (isset($_POST['mark_all_read'])) {
        $conn->query("UPDATE notifications SET is_read = 1");
        echo json_encode(['success' => true]);
        exit();
    }

    // Delete single notification
    if (isset($_POST['delete_notification']) && isset($_POST['notification_id'])) {
        $nid = (int)$_POST['notification_id'];
        $stmt = $conn->prepare("DELETE FROM notifications WHERE notification_id = ?");
        $stmt->bind_param("i", $nid);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true]);
        exit();
    }

    // Delete all read notifications
    if (isset($_POST['delete_all_read'])) {
        $conn->query("DELETE FROM notifications WHERE is_read = 1");
        echo json_encode(['success' => true]);
        exit();
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit();
}

// ── Filter Parameters ──────────────────────────────────────
$filter      = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$type_filter = isset($_GET['type'])   ? $_GET['type']   : 'all';
$priority_filter = isset($_GET['priority']) ? $_GET['priority'] : 'all';

// ── Build Query ────────────────────────────────────────────
// Security: Only show tour notifications for properties managed by the current admin.
// Non-tour notifications (agent, property, property_sale) are admin-global.
$admin_account_id = (int)$_SESSION['account_id'];

$where_conditions = [];
if ($filter === 'unread') {
    $where_conditions[] = "(n.is_read = 0 OR n.is_read IS NULL)";
} elseif ($filter === 'read') {
    $where_conditions[] = "n.is_read = 1";
}
if ($type_filter !== 'all') {
    $where_conditions[] = "n.item_type = '" . $conn->real_escape_string($type_filter) . "'";
}
if ($priority_filter !== 'all') {
    $where_conditions[] = "n.priority = '" . $conn->real_escape_string($priority_filter) . "'";
}

// Filter: exclude tour notifications for properties NOT managed by this admin
// A tour notification's item_id = tour_requests.tour_id → tour_requests.property_id → property_log.account_id
$where_conditions[] = "(
    n.item_type != 'tour'
    OR EXISTS (
        SELECT 1 FROM tour_requests tr
        JOIN property_log pl ON tr.property_id = pl.property_id AND pl.action = 'CREATED'
        WHERE tr.tour_id = n.item_id AND pl.account_id = $admin_account_id
    )
)";

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Check if new columns exist (migration may not have run yet)
$has_new_cols = false;
$col_check = $conn->query("SHOW COLUMNS FROM notifications LIKE 'title'");
if ($col_check && $col_check->num_rows > 0) {
    $has_new_cols = true;
}

if ($has_new_cols) {
    $sql = "SELECT n.notification_id, n.item_id, n.item_type, n.title, n.message, n.category, n.priority, n.action_url, n.icon, n.created_at, n.is_read
            FROM notifications n $where_clause ORDER BY n.is_read ASC, n.created_at DESC";
} else {
    $sql = "SELECT n.notification_id, n.item_id, n.item_type, n.message, n.created_at, n.is_read
            FROM notifications n $where_clause ORDER BY n.is_read ASC, n.created_at DESC";
}

$result = $conn->query($sql);
$notifications = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        // Fill defaults for old schema
        if (!$has_new_cols) {
            $row['title'] = '';
            $row['category'] = 'update';
            $row['priority'] = 'normal';
            $row['action_url'] = null;
            $row['icon'] = null;
        }
        // Auto-derive title if empty
        if (empty($row['title'])) {
            switch ($row['item_type']) {
                case 'agent':       $row['title'] = 'Agent Profile Submission'; break;
                case 'tour':        $row['title'] = 'New Tour Request'; break;
                case 'property':    $row['title'] = 'Property Update'; break;
                case 'property_sale': $row['title'] = 'Sale Verification'; break;
                default:            $row['title'] = 'Notification'; break;
            }
        }
        // Auto-derive action_url if empty
        if (empty($row['action_url'])) {
            switch ($row['item_type']) {
                case 'agent':       $row['action_url'] = 'review_agent_details.php?id=' . $row['item_id']; break;
                case 'tour':        $row['action_url'] = 'admin_tour_request_details.php?id=' . $row['item_id']; break;
                case 'property':    $row['action_url'] = 'view_property.php?id=' . $row['item_id']; break;
                case 'property_sale': $row['action_url'] = 'admin_property_sale_approvals.php'; break;
                default:            $row['action_url'] = '#'; break;
            }
        }
        // Auto-derive icon if empty
        if (empty($row['icon'])) {
            switch ($row['item_type']) {
                case 'agent':       $row['icon'] = 'bi-person-badge'; break;
                case 'tour':        $row['icon'] = 'bi-calendar-check'; break;
                case 'property':    $row['icon'] = 'bi-building'; break;
                case 'property_sale': $row['icon'] = 'bi-cash-stack'; break;
                default:            $row['icon'] = 'bi-bell'; break;
            }
        }
        $notifications[] = $row;
    }
}

// ── Aggregate Counts (filtered: tour notifs only for admin-managed properties) ──
$tour_filter_sql = "AND (n.item_type != 'tour' OR EXISTS (SELECT 1 FROM tour_requests tr JOIN property_log pl ON tr.property_id = pl.property_id AND pl.action = 'CREATED' WHERE tr.tour_id = n.item_id AND pl.account_id = $admin_account_id))";
$total_count   = (int)$conn->query("SELECT COUNT(*) as c FROM notifications n WHERE 1=1 $tour_filter_sql")->fetch_assoc()['c'];
$unread_count  = (int)$conn->query("SELECT COUNT(*) as c FROM notifications n WHERE (n.is_read = 0 OR n.is_read IS NULL) $tour_filter_sql")->fetch_assoc()['c'];
$agent_count   = (int)$conn->query("SELECT COUNT(*) as c FROM notifications n WHERE n.item_type = 'agent' $tour_filter_sql")->fetch_assoc()['c'];
$property_count= (int)$conn->query("SELECT COUNT(*) as c FROM notifications n WHERE n.item_type IN ('property','property_sale') $tour_filter_sql")->fetch_assoc()['c'];
$tour_count    = (int)$conn->query("SELECT COUNT(*) as c FROM notifications n WHERE n.item_type = 'tour' $tour_filter_sql")->fetch_assoc()['c'];

// ── "Today" vs "Earlier" grouping ──────────────────────────
$today_start = date('Y-m-d') . ' 00:00:00';
$today_notifications   = array_filter($notifications, fn($n) => $n['created_at'] >= $today_start);
$earlier_notifications = array_filter($notifications, fn($n) => $n['created_at'] < $today_start);

// ── Live Data Summaries (daily insights) ──────────────────
// Pending agent approvals
$pending_agents_count = 0;
$r = $conn->query("SELECT COUNT(*) as c FROM agent_information WHERE is_approved = 0");
if ($r) $pending_agents_count = (int)$r->fetch_assoc()['c'];

// Pending property approvals
$pending_properties_count = 0;
$r = $conn->query("SELECT COUNT(*) as c FROM property WHERE approval_status = 'pending'");
if ($r) $pending_properties_count = (int)$r->fetch_assoc()['c'];

// Pending tour requests
$pending_tours_count = 0;
$r = $conn->query("SELECT COUNT(*) as c FROM tour_requests WHERE request_status = 'Pending'");
if ($r) $pending_tours_count = (int)$r->fetch_assoc()['c'];

// Pending sale verifications
$pending_sales_count = 0;
$r = $conn->query("SELECT COUNT(*) as c FROM sale_verifications WHERE status = 'Pending'");
if ($r) $pending_sales_count = (int)$r->fetch_assoc()['c'];

// Tours happening today
$tours_today_count = 0;
$r = $conn->query("SELECT COUNT(*) as c FROM tour_requests WHERE tour_date = CURDATE() AND request_status IN ('Confirmed','Pending')");
if ($r) $tours_today_count = (int)$r->fetch_assoc()['c'];

// New listings this week
$new_listings_week = 0;
$r = $conn->query("SELECT COUNT(*) as c FROM property WHERE ListingDate >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
if ($r) $new_listings_week = (int)$r->fetch_assoc()['c'];

// Time-ago helper
function notif_time_ago($datetime) {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 172800) return 'Yesterday';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M d, Y', strtotime($datetime));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        /* ================================================
           ADMIN NOTIFICATIONS PAGE
           Design matches property.php exactly
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
            .admin-content { margin-left: 0 !important; padding: 1.5rem; }
        }
        @media (max-width: 768px) {
            .admin-content { margin-left: 0 !important; padding: 1rem; }
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

        /* ===== PAGE HEADER (matches property.php) ===== */
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
            position: relative; z-index: 2;
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 1.5rem;
        }
        .page-header h1 {
            font-size: 1.75rem; font-weight: 800;
            color: var(--text-primary); margin-bottom: 0.25rem;
        }
        .page-header .subtitle {
            color: var(--text-secondary); font-size: 0.95rem;
        }
        .page-header .header-badge {
            background: linear-gradient(135deg, var(--gold-dark), var(--gold));
            color: #fff; font-size: 0.75rem; font-weight: 700;
            padding: 0.3rem 0.85rem; border-radius: 2px;
            text-transform: uppercase; letter-spacing: 0.5px;
        }

        /* ===== KPI STAT CARDS (matches property.php) ===== */
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
            position: relative; overflow: hidden;
            transition: all 0.3s ease;
        }
        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; height: 2px;
            background: linear-gradient(90deg, transparent, var(--blue), transparent);
            opacity: 0; transition: opacity 0.3s ease;
        }
        .kpi-card:hover {
            border-color: rgba(37, 99, 235, 0.25);
            box-shadow: 0 8px 32px rgba(37, 99, 235, 0.08);
            transform: translateY(-3px);
        }
        .kpi-card:hover::before { opacity: 1; }
        .kpi-card .kpi-icon {
            width: 40px; height: 40px; border-radius: 4px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; margin-bottom: 0.75rem;
        }
        .kpi-icon.gold { background: linear-gradient(135deg, rgba(212,175,55,0.08), rgba(212,175,55,0.15)); color: var(--gold-dark); border: 1px solid rgba(212,175,55,0.2); }
        .kpi-icon.blue { background: linear-gradient(135deg, rgba(37,99,235,0.06), rgba(37,99,235,0.12)); color: var(--blue); border: 1px solid rgba(37,99,235,0.15); }
        .kpi-icon.green { background: linear-gradient(135deg, rgba(34,197,94,0.06), rgba(34,197,94,0.12)); color: #16a34a; border: 1px solid rgba(34,197,94,0.15); }
        .kpi-icon.red { background: linear-gradient(135deg, rgba(239,68,68,0.06), rgba(239,68,68,0.12)); color: #dc2626; border: 1px solid rgba(239,68,68,0.15); }
        .kpi-icon.amber { background: linear-gradient(135deg, rgba(245,158,11,0.06), rgba(245,158,11,0.12)); color: #d97706; border: 1px solid rgba(245,158,11,0.15); }
        .kpi-icon.cyan { background: linear-gradient(135deg, rgba(6,182,212,0.06), rgba(6,182,212,0.12)); color: #0891b2; border: 1px solid rgba(6,182,212,0.15); }
        .kpi-card .kpi-label {
            font-size: 0.7rem; font-weight: 600; text-transform: uppercase;
            letter-spacing: 1px; color: var(--text-secondary); margin-bottom: 0.25rem;
        }
        .kpi-card .kpi-value {
            font-size: 1.5rem; font-weight: 800; color: var(--text-primary); line-height: 1.2;
        }

        /* ===== ACTION BAR (matches property.php) ===== */
        .action-bar {
            background: var(--card-bg);
            border: 1px solid rgba(37, 99, 235, 0.1);
            border-radius: 4px;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 1rem;
            position: relative; overflow: hidden;
        }
        .action-bar::after {
            content: '';
            position: absolute; top: 0; left: 0; right: 0; height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent);
        }
        .action-title {
            font-size: 1.1rem; font-weight: 700; color: var(--text-primary);
            margin: 0; display: flex; align-items: center; gap: 0.5rem;
        }
        .action-title i { color: var(--gold-dark); }
        .action-buttons { display: flex; gap: 0.75rem; flex-wrap: wrap; }

        .btn-gold {
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 50%, var(--gold-dark) 100%);
            color: #fff; border: none; padding: 0.6rem 1.25rem;
            font-size: 0.85rem; font-weight: 700; border-radius: 4px;
            text-decoration: none; display: inline-flex; align-items: center;
            justify-content: center; gap: 0.5rem; transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(212,175,55,0.25);
            position: relative; overflow: hidden; cursor: pointer;
        }
        .btn-gold::before {
            content: '';
            position: absolute; top: 0; left: -100%; width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s ease;
        }
        .btn-gold:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(212,175,55,0.35); color: #fff; }
        .btn-gold:hover::before { left: 100%; }

        .btn-outline-admin {
            background: var(--card-bg); color: var(--text-secondary);
            border: 1px solid #e2e8f0; padding: 0.6rem 1.25rem;
            font-size: 0.85rem; font-weight: 600; border-radius: 4px;
            display: inline-flex; align-items: center; gap: 0.5rem;
            transition: all 0.3s ease; cursor: pointer; text-decoration: none;
        }
        .btn-outline-admin:hover { border-color: var(--blue); color: var(--blue); background: rgba(37,99,235,0.03); }

        .btn-outline-danger {
            background: var(--card-bg); color: #dc2626;
            border: 1px solid rgba(239,68,68,0.3); padding: 0.6rem 1.25rem;
            font-size: 0.85rem; font-weight: 600; border-radius: 4px;
            display: inline-flex; align-items: center; gap: 0.5rem;
            transition: all 0.3s ease; cursor: pointer; text-decoration: none;
        }
        .btn-outline-danger:hover { border-color: #dc2626; background: rgba(239,68,68,0.05); }

        /* ===== TABS (matches property.php) ===== */
        .notif-tabs {
            background: var(--card-bg);
            border: 1px solid rgba(37, 99, 235, 0.1);
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 1.5rem;
            position: relative;
        }
        .notif-tabs::after {
            content: '';
            position: absolute; top: 0; left: 0; right: 0; height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent);
            z-index: 5;
        }
        .notif-tabs .nav-tabs {
            border-bottom: 1px solid #e2e8f0;
            padding: 0.25rem 0.5rem 0; gap: 0.25rem;
            background: linear-gradient(180deg, #fafbfc, var(--card-bg));
        }
        .notif-tabs .nav-item { margin-bottom: 0; }
        .notif-tabs .nav-link {
            border: none; border-bottom: 3px solid transparent;
            background: transparent; color: var(--text-secondary);
            font-weight: 600; font-size: 0.85rem;
            padding: 0.85rem 1.25rem; border-radius: 0;
        }
        .notif-tabs .nav-link:hover {
            color: var(--text-primary); background: rgba(37,99,235,0.03);
            border-bottom-color: rgba(37,99,235,0.2);
        }
        .notif-tabs .nav-link.active {
            color: var(--gold-dark); background: rgba(212,175,55,0.03);
            border-bottom-color: var(--gold);
        }
        .tab-badge {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 22px; height: 22px; padding: 0 0.4rem;
            border-radius: 2px; font-size: 0.7rem; font-weight: 700; margin-left: 0.5rem;
        }
        .badge-total  { background: rgba(37,99,235,0.1); color: var(--blue); border: 1px solid rgba(37,99,235,0.15); }
        .badge-unread { background: rgba(239,68,68,0.1); color: #dc2626; border: 1px solid rgba(239,68,68,0.15); }
        .badge-agent  { background: rgba(34,197,94,0.1); color: #16a34a; border: 1px solid rgba(34,197,94,0.15); }
        .badge-property { background: rgba(37,99,235,0.1); color: var(--blue); border: 1px solid rgba(37,99,235,0.15); }
        .badge-tour   { background: rgba(6,182,212,0.1); color: #0891b2; border: 1px solid rgba(6,182,212,0.15); }
        .badge-sale   { background: rgba(212,175,55,0.1); color: var(--gold-dark); border: 1px solid rgba(212,175,55,0.15); }

        .tab-content { padding: 1.5rem; }

        /* ===== DAILY INSIGHTS PANEL ===== */
        .insights-panel {
            background: var(--card-bg);
            border: 1px solid rgba(37, 99, 235, 0.1);
            border-radius: 4px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
            position: relative; overflow: hidden;
        }
        .insights-panel::after {
            content: '';
            position: absolute; top: 0; left: 0; right: 0; height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), var(--blue), transparent);
        }
        .insights-title {
            font-size: 0.8rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 1px; color: var(--text-secondary); margin-bottom: 1rem;
            display: flex; align-items: center; gap: 0.5rem;
        }
        .insights-title i { color: var(--gold-dark); }
        .insights-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0.75rem;
        }
        .insight-item {
            display: flex; align-items: center; gap: 0.75rem;
            padding: 0.75rem 1rem; border-radius: 4px;
            border: 1px solid #e2e8f0; background: #fafbfc;
            transition: all 0.2s ease; text-decoration: none; color: inherit;
        }
        .insight-item:hover {
            border-color: rgba(37,99,235,0.25);
            box-shadow: 0 2px 8px rgba(37,99,235,0.06);
            transform: translateY(-1px);
            color: inherit;
        }
        .insight-icon {
            width: 36px; height: 36px; border-radius: 4px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; flex-shrink: 0;
        }
        .insight-icon.amber { background: rgba(245,158,11,0.1); color: #d97706; border: 1px solid rgba(245,158,11,0.15); }
        .insight-icon.blue  { background: rgba(37,99,235,0.08); color: var(--blue); border: 1px solid rgba(37,99,235,0.12); }
        .insight-icon.green { background: rgba(34,197,94,0.08); color: #16a34a; border: 1px solid rgba(34,197,94,0.12); }
        .insight-icon.red   { background: rgba(239,68,68,0.08); color: #dc2626; border: 1px solid rgba(239,68,68,0.12); }
        .insight-icon.gold  { background: rgba(212,175,55,0.08); color: var(--gold-dark); border: 1px solid rgba(212,175,55,0.12); }
        .insight-icon.cyan  { background: rgba(6,182,212,0.08); color: #0891b2; border: 1px solid rgba(6,182,212,0.12); }
        .insight-text { flex: 1; }
        .insight-text .insight-count {
            font-size: 1.1rem; font-weight: 800; color: var(--text-primary); line-height: 1.2;
        }
        .insight-text .insight-label {
            font-size: 0.72rem; color: var(--text-secondary); font-weight: 500;
        }

        /* ===== NOTIFICATION LIST ===== */
        .notif-list-container {
            background: var(--card-bg);
            border: 1px solid rgba(37, 99, 235, 0.1);
            border-radius: 4px;
            overflow: hidden;
            position: relative;
        }
        .notif-list-container::before {
            content: '';
            position: absolute; top: 0; left: 0; right: 0; height: 2px;
            background: linear-gradient(90deg, var(--gold), var(--blue));
            z-index: 5;
        }

        .notif-group-label {
            padding: 0.65rem 1.5rem;
            background: linear-gradient(180deg, #fafbfc, #f5f6f8);
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.72rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 1.2px;
            color: var(--text-secondary);
            display: flex; align-items: center; gap: 0.5rem;
        }
        .notif-group-label i { color: var(--gold-dark); font-size: 0.8rem; }

        .notif-item {
            display: flex; align-items: flex-start; gap: 1rem;
            padding: 1.15rem 1.5rem;
            border-bottom: 1px solid #f1f5f9;
            transition: all 0.2s ease;
            position: relative;
        }
        .notif-item:last-child { border-bottom: none; }
        .notif-item:hover { background: rgba(37, 99, 235, 0.02); }

        .notif-item.unread {
            background: rgba(37, 99, 235, 0.03);
            border-left: 3px solid var(--blue);
        }
        .notif-item.unread:hover { background: rgba(37, 99, 235, 0.06); }

        .notif-item-icon {
            width: 42px; height: 42px; border-radius: 4px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; font-size: 1.1rem;
        }
        .notif-item-icon.agent    { background: rgba(34,197,94,0.08); color: #16a34a; border: 1px solid rgba(34,197,94,0.15); }
        .notif-item-icon.tour     { background: rgba(6,182,212,0.08); color: #0891b2; border: 1px solid rgba(6,182,212,0.15); }
        .notif-item-icon.property { background: rgba(37,99,235,0.08); color: var(--blue); border: 1px solid rgba(37,99,235,0.15); }
        .notif-item-icon.sale, .notif-item-icon.property_sale {
            background: rgba(212,175,55,0.08); color: var(--gold-dark); border: 1px solid rgba(212,175,55,0.15);
        }
        .notif-item-icon.system   { background: rgba(100,116,139,0.08); color: #64748b; border: 1px solid rgba(100,116,139,0.15); }

        .notif-item-body { flex: 1; min-width: 0; }
        .notif-item-title {
            font-size: 0.9rem; font-weight: 700; color: var(--text-primary);
            margin-bottom: 0.15rem; display: flex; align-items: center; gap: 0.5rem;
        }
        .notif-item-message {
            font-size: 0.84rem; color: var(--text-secondary); line-height: 1.5;
            margin-bottom: 0.4rem;
        }
        .notif-item-meta {
            display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;
        }
        .notif-time {
            font-size: 0.72rem; color: #94a3b8;
            display: inline-flex; align-items: center; gap: 0.3rem;
        }
        .notif-type-tag {
            display: inline-flex; align-items: center; gap: 0.3rem;
            padding: 0.15rem 0.5rem; border-radius: 2px;
            font-size: 0.68rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .notif-type-tag.agent    { background: rgba(34,197,94,0.08); color: #16a34a; border: 1px solid rgba(34,197,94,0.12); }
        .notif-type-tag.tour     { background: rgba(6,182,212,0.08); color: #0891b2; border: 1px solid rgba(6,182,212,0.12); }
        .notif-type-tag.property { background: rgba(37,99,235,0.08); color: var(--blue); border: 1px solid rgba(37,99,235,0.12); }
        .notif-type-tag.property_sale { background: rgba(212,175,55,0.08); color: var(--gold-dark); border: 1px solid rgba(212,175,55,0.12); }

        .notif-priority-tag {
            display: inline-flex; align-items: center; gap: 0.25rem;
            padding: 0.1rem 0.45rem; border-radius: 2px;
            font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px;
        }
        .notif-priority-tag.urgent { background: rgba(239,68,68,0.1); color: #dc2626; border: 1px solid rgba(239,68,68,0.15); }
        .notif-priority-tag.high   { background: rgba(245,158,11,0.1); color: #d97706; border: 1px solid rgba(245,158,11,0.15); }
        .notif-priority-tag.normal { display: none; }
        .notif-priority-tag.low    { display: none; }

        .notif-item-actions {
            display: flex; flex-direction: column; gap: 0.4rem;
            flex-shrink: 0; align-items: flex-end;
        }
        .notif-action-btn {
            width: 32px; height: 32px; border-radius: 4px; border: 1px solid #e2e8f0;
            background: var(--card-bg); color: #94a3b8;
            display: inline-flex; align-items: center; justify-content: center;
            transition: all 0.2s ease; cursor: pointer; font-size: 0.85rem;
        }
        .notif-action-btn.btn-view:hover   { border-color: var(--blue); color: var(--blue); background: rgba(37,99,235,0.03); }
        .notif-action-btn.btn-check:hover  { border-color: #16a34a; color: #16a34a; background: rgba(34,197,94,0.03); }
        .notif-action-btn.btn-trash:hover  { border-color: #dc2626; color: #dc2626; background: rgba(239,68,68,0.03); }

        /* ===== EMPTY STATE ===== */
        .empty-state {
            text-align: center; padding: 3rem 1rem; color: var(--text-secondary);
        }
        .empty-state i {
            font-size: 3rem; color: rgba(37,99,235,0.15); margin-bottom: 1rem; display: block;
        }
        .empty-state h4 {
            font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;
        }
        .empty-state p { font-size: 0.9rem; color: #94a3b8; }

        /* ===== FILTER SIDEBAR (matches property.php) ===== */
        .filter-sidebar {
            position: fixed; top: 0; right: 0; width: 100%; height: 100%;
            z-index: 9999; pointer-events: none;
        }
        .filter-sidebar.active { pointer-events: all; }
        .filter-sidebar-overlay {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(15,23,42,0.4); opacity: 0;
            transition: opacity 0.2s ease; pointer-events: none;
        }
        .filter-sidebar.active .filter-sidebar-overlay { opacity: 1; pointer-events: all; }
        .filter-sidebar-content {
            position: absolute; top: 0; right: 0;
            width: 420px; max-width: 90vw; height: 100%;
            background: #fff; border-left: 1px solid rgba(37,99,235,0.15);
            box-shadow: -8px 0 32px rgba(15,23,42,0.1);
            transform: translateX(100%); transition: transform 0.25s ease;
            display: flex; flex-direction: column; overflow: hidden;
        }
        .filter-sidebar.active .filter-sidebar-content { transform: translateX(0); }
        .filter-header {
            background: linear-gradient(135deg, #0f172a, #1e293b);
            color: #fff; padding: 1.5rem 2rem;
            display: flex; align-items: center; justify-content: space-between;
            position: relative; overflow: hidden;
        }
        .filter-header::after {
            content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 2px;
            background: linear-gradient(90deg, var(--gold), var(--blue));
        }
        .filter-header h4 {
            font-weight: 700; font-size: 1.15rem;
            display: flex; align-items: center; gap: 0.75rem; margin: 0;
        }
        .filter-header h4 i { color: var(--gold); font-size: 1.3rem; }
        .btn-close-filter {
            background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2);
            color: #fff; width: 36px; height: 36px; border-radius: 4px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: all 0.2s ease; font-size: 1rem;
        }
        .btn-close-filter:hover { background: rgba(239,68,68,0.2); border-color: rgba(239,68,68,0.4); }
        .filter-body {
            flex: 1; overflow-y: auto; padding: 1.5rem; background: #f8fafc;
        }
        .filter-section {
            background: #fff; border-radius: 4px; padding: 1.25rem;
            margin-bottom: 1rem; border: 1px solid #e2e8f0;
        }
        .filter-section:last-child { margin-bottom: 0; }
        .filter-section-title {
            font-size: 0.75rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 1px; color: var(--text-secondary);
            margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem;
        }
        .filter-section-title i { color: var(--gold-dark); }
        .filter-option {
            display: flex; align-items: center; gap: 0.75rem;
            padding: 0.5rem 0.75rem; border-radius: 4px;
            cursor: pointer; transition: background 0.15s ease;
            margin-bottom: 0.25rem;
        }
        .filter-option:hover { background: rgba(37,99,235,0.03); }
        .filter-option input[type="radio"] {
            accent-color: var(--gold); width: 16px; height: 16px;
        }
        .filter-option label {
            cursor: pointer; font-size: 0.88rem; font-weight: 500;
            color: var(--text-primary); flex: 1;
            display: flex; align-items: center; justify-content: space-between;
        }
        .filter-option .filter-count {
            background: #f1f5f9; color: var(--text-secondary);
            padding: 0.1rem 0.5rem; border-radius: 2px;
            font-size: 0.72rem; font-weight: 700;
        }
        .filter-footer {
            padding: 1.25rem 1.5rem; border-top: 1px solid #e2e8f0;
            display: flex; gap: 0.75rem; background: #fff;
        }
        .filter-footer .btn-gold { flex: 1; justify-content: center; }
        .filter-footer .btn-outline-admin { flex: 0; }

        /* ===== ACTIVE FILTERS ===== */
        .active-filters {
            display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;
            margin-bottom: 1rem;
        }
        .active-filter-badge {
            display: inline-flex; align-items: center; gap: 0.4rem;
            padding: 0.35rem 0.65rem; border-radius: 2px;
            font-size: 0.75rem; font-weight: 600;
            background: rgba(37,99,235,0.06); color: var(--blue);
            border: 1px solid rgba(37,99,235,0.15);
        }
        .active-filter-badge .remove-filter {
            color: var(--blue); opacity: 0.6; cursor: pointer; text-decoration: none;
            transition: opacity 0.2s ease; font-size: 0.85rem;
        }
        .active-filter-badge .remove-filter:hover { opacity: 1; }
        .clear-all-filters {
            font-size: 0.75rem; font-weight: 600; color: #dc2626;
            text-decoration: none; cursor: pointer; margin-left: 0.5rem;
        }
        .clear-all-filters:hover { text-decoration: underline; }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 1400px) { .kpi-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 992px) { .kpi-grid { grid-template-columns: repeat(2, 1fr); } .insights-grid { grid-template-columns: repeat(2, 1fr); } }
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
            .notif-tabs .nav-tabs { overflow-x: auto; flex-wrap: nowrap; -webkit-overflow-scrolling: touch; }
            .notif-tabs .nav-link { padding: 0.65rem 0.85rem; font-size: 0.8rem; white-space: nowrap; }
            .notif-item { padding: 1rem; gap: 0.75rem; }
        }
        @media (max-width: 576px) {
            .admin-content { padding: 0.75rem; }
            .page-header { padding: 1rem; }
            .page-header h1 { font-size: 1.15rem; }
            .kpi-grid { grid-template-columns: 1fr 1fr; gap: 0.5rem; }
            .kpi-card { padding: 0.85rem; }
            .kpi-card .kpi-value { font-size: 1.1rem; }
            .kpi-card .kpi-label { font-size: 0.65rem; }
            .notif-tabs .nav-link { padding: 0.55rem 0.7rem; font-size: 0.75rem; }
            .tab-badge { display: none; }
            .insights-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include 'admin_sidebar.php'; ?>
    <?php include 'admin_navbar.php'; ?>

    <div class="admin-content">

        <!-- ═══ Page Header ═══ -->
        <div class="page-header">
            <div class="page-header-inner">
                <div>
                    <h1><i class="bi bi-bell" style="color: var(--gold-dark);"></i> Notifications</h1>
                    <div class="subtitle">Stay updated with system activity, requests, and important alerts</div>
                </div>
                <div class="d-flex gap-2 align-items-center">
                    <?php if ($unread_count > 0): ?>
                        <span class="header-badge"><i class="bi bi-envelope-exclamation me-1"></i><?php echo $unread_count; ?> Unread</span>
                    <?php endif; ?>
                    <span class="header-badge" style="background: linear-gradient(135deg, var(--blue-dark), var(--blue));">
                        <i class="bi bi-collection me-1"></i><?php echo $total_count; ?> Total
                    </span>
                </div>
            </div>
        </div>

        <!-- ═══ KPI Cards ═══ -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-icon gold"><i class="bi bi-bell"></i></div>
                <div class="kpi-label">Total</div>
                <div class="kpi-value"><?php echo $total_count; ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon red"><i class="bi bi-envelope-open"></i></div>
                <div class="kpi-label">Unread</div>
                <div class="kpi-value"><?php echo $unread_count; ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon green"><i class="bi bi-person-badge"></i></div>
                <div class="kpi-label">Agent</div>
                <div class="kpi-value"><?php echo $agent_count; ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon blue"><i class="bi bi-building"></i></div>
                <div class="kpi-label">Property</div>
                <div class="kpi-value"><?php echo $property_count; ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon cyan"><i class="bi bi-calendar-check"></i></div>
                <div class="kpi-label">Tours</div>
                <div class="kpi-value"><?php echo $tour_count; ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon amber"><i class="bi bi-cash-stack"></i></div>
                <div class="kpi-label">Sales</div>
                <div class="kpi-value"><?php echo $pending_sales_count; ?></div>
            </div>
        </div>

        <!-- ═══ Daily Insights Panel ═══ -->
        <div class="insights-panel">
            <div class="insights-title"><i class="bi bi-lightning-charge"></i> Today's Snapshot &mdash; <?php echo date('l, M d, Y'); ?></div>
            <div class="insights-grid">
                <?php if ($pending_agents_count > 0): ?>
                <a href="agent.php" class="insight-item">
                    <div class="insight-icon amber"><i class="bi bi-person-exclamation"></i></div>
                    <div class="insight-text">
                        <div class="insight-count"><?php echo $pending_agents_count; ?></div>
                        <div class="insight-label">Agent(s) Awaiting Approval</div>
                    </div>
                </a>
                <?php endif; ?>

                <?php if ($pending_properties_count > 0): ?>
                <a href="property.php" class="insight-item">
                    <div class="insight-icon blue"><i class="bi bi-building-exclamation"></i></div>
                    <div class="insight-text">
                        <div class="insight-count"><?php echo $pending_properties_count; ?></div>
                        <div class="insight-label">Property Listing(s) Pending Review</div>
                    </div>
                </a>
                <?php endif; ?>

                <?php if ($pending_tours_count > 0): ?>
                <a href="tour_requests.php" class="insight-item">
                    <div class="insight-icon cyan"><i class="bi bi-calendar-event"></i></div>
                    <div class="insight-text">
                        <div class="insight-count"><?php echo $pending_tours_count; ?></div>
                        <div class="insight-label">Tour Request(s) Pending</div>
                    </div>
                </a>
                <?php endif; ?>

                <?php if ($pending_sales_count > 0): ?>
                <a href="admin_property_sale_approvals.php" class="insight-item">
                    <div class="insight-icon gold"><i class="bi bi-cash-stack"></i></div>
                    <div class="insight-text">
                        <div class="insight-count"><?php echo $pending_sales_count; ?></div>
                        <div class="insight-label">Sale Verification(s) Pending</div>
                    </div>
                </a>
                <?php endif; ?>

                <?php if ($tours_today_count > 0): ?>
                <a href="tour_requests.php" class="insight-item">
                    <div class="insight-icon green"><i class="bi bi-calendar-date"></i></div>
                    <div class="insight-text">
                        <div class="insight-count"><?php echo $tours_today_count; ?></div>
                        <div class="insight-label">Tour(s) Scheduled Today</div>
                    </div>
                </a>
                <?php endif; ?>

                <?php if ($new_listings_week > 0): ?>
                <a href="property.php" class="insight-item">
                    <div class="insight-icon blue"><i class="bi bi-plus-circle"></i></div>
                    <div class="insight-text">
                        <div class="insight-count"><?php echo $new_listings_week; ?></div>
                        <div class="insight-label">New Listing(s) This Week</div>
                    </div>
                </a>
                <?php endif; ?>

                <?php if ($pending_agents_count == 0 && $pending_properties_count == 0 && $pending_tours_count == 0 && $pending_sales_count == 0 && $tours_today_count == 0 && $new_listings_week == 0): ?>
                <div class="insight-item" style="justify-content: center; color: var(--text-secondary); border-style: dashed;">
                    <i class="bi bi-check-circle me-2" style="color: #16a34a;"></i>
                    All clear! No pending items require your attention right now.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ═══ Action Bar ═══ -->
        <div class="action-bar">
            <h2 class="action-title">
                <i class="bi bi-inbox"></i> Notification Feed
            </h2>
            <div class="action-buttons">
                <?php if ($unread_count > 0): ?>
                    <button type="button" class="btn-gold" onclick="markAllAsRead()">
                        <i class="bi bi-check2-all"></i> Mark All Read
                    </button>
                <?php endif; ?>
                <button type="button" class="btn-outline-admin" onclick="openFilterSidebar()">
                    <i class="bi bi-funnel"></i> Filters
                    <?php
                    $active_filter_count = ($filter !== 'all' ? 1 : 0) + ($type_filter !== 'all' ? 1 : 0) + ($priority_filter !== 'all' ? 1 : 0);
                    if ($active_filter_count > 0): ?>
                        <span style="display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 6px;background:var(--blue);color:#fff;border-radius:10px;font-size:0.7rem;font-weight:700;"><?php echo $active_filter_count; ?></span>
                    <?php endif; ?>
                </button>
                <?php
                $read_count = $total_count - $unread_count;
                if ($read_count > 0): ?>
                    <button type="button" class="btn-outline-danger" onclick="deleteAllRead()">
                        <i class="bi bi-trash3"></i> Clear Read
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- ═══ Active Filters Display ═══ -->
        <?php if ($filter !== 'all' || $type_filter !== 'all' || $priority_filter !== 'all'): ?>
        <div class="active-filters">
            <?php if ($filter !== 'all'): ?>
                <span class="active-filter-badge">
                    <i class="bi bi-funnel"></i> Status: <?php echo ucfirst($filter); ?>
                    <a href="?filter=all&type=<?php echo urlencode($type_filter); ?>&priority=<?php echo urlencode($priority_filter); ?>" class="remove-filter"><i class="bi bi-x"></i></a>
                </span>
            <?php endif; ?>
            <?php if ($type_filter !== 'all'): ?>
                <span class="active-filter-badge">
                    <i class="bi bi-tag"></i> Type: <?php echo ucfirst(str_replace('_', ' ', $type_filter)); ?>
                    <a href="?filter=<?php echo urlencode($filter); ?>&type=all&priority=<?php echo urlencode($priority_filter); ?>" class="remove-filter"><i class="bi bi-x"></i></a>
                </span>
            <?php endif; ?>
            <?php if ($priority_filter !== 'all'): ?>
                <span class="active-filter-badge">
                    <i class="bi bi-flag"></i> Priority: <?php echo ucfirst($priority_filter); ?>
                    <a href="?filter=<?php echo urlencode($filter); ?>&type=<?php echo urlencode($type_filter); ?>&priority=all" class="remove-filter"><i class="bi bi-x"></i></a>
                </span>
            <?php endif; ?>
            <a href="?" class="clear-all-filters">Clear All</a>
        </div>
        <?php endif; ?>

        <!-- ═══ Tabs ═══ -->
        <div class="notif-tabs">
            <ul class="nav nav-tabs" id="notifTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <a class="nav-link <?php echo $type_filter === 'all' ? 'active' : ''; ?>"
                       href="?filter=<?php echo urlencode($filter); ?>&type=all&priority=<?php echo urlencode($priority_filter); ?>">
                        All <span class="tab-badge badge-total"><?php echo $total_count; ?></span>
                    </a>
                </li>
                <?php if ($unread_count > 0): ?>
                <li class="nav-item" role="presentation">
                    <a class="nav-link <?php echo $filter === 'unread' && $type_filter === 'all' ? 'active' : ''; ?>"
                       href="?filter=unread&type=all&priority=<?php echo urlencode($priority_filter); ?>">
                        Unread <span class="tab-badge badge-unread"><?php echo $unread_count; ?></span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($agent_count > 0): ?>
                <li class="nav-item" role="presentation">
                    <a class="nav-link <?php echo $type_filter === 'agent' ? 'active' : ''; ?>"
                       href="?filter=<?php echo urlencode($filter); ?>&type=agent&priority=<?php echo urlencode($priority_filter); ?>">
                        <i class="bi bi-person-badge me-1"></i>Agents <span class="tab-badge badge-agent"><?php echo $agent_count; ?></span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($property_count > 0): ?>
                <li class="nav-item" role="presentation">
                    <a class="nav-link <?php echo $type_filter === 'property' ? 'active' : ''; ?>"
                       href="?filter=<?php echo urlencode($filter); ?>&type=property&priority=<?php echo urlencode($priority_filter); ?>">
                        <i class="bi bi-building me-1"></i>Properties <span class="tab-badge badge-property"><?php echo $property_count; ?></span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($tour_count > 0): ?>
                <li class="nav-item" role="presentation">
                    <a class="nav-link <?php echo $type_filter === 'tour' ? 'active' : ''; ?>"
                       href="?filter=<?php echo urlencode($filter); ?>&type=tour&priority=<?php echo urlencode($priority_filter); ?>">
                        <i class="bi bi-calendar-check me-1"></i>Tours <span class="tab-badge badge-tour"><?php echo $tour_count; ?></span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>

            <div class="tab-content">
                <!-- Notification List -->
                <div class="notif-list-container">

                    <?php if (empty($notifications)): ?>
                        <div class="empty-state">
                            <i class="bi bi-bell-slash"></i>
                            <h4>No Notifications Found</h4>
                            <p>
                                <?php echo ($filter !== 'all' || $type_filter !== 'all' || $priority_filter !== 'all')
                                    ? 'Try adjusting your filters to see more results.'
                                    : "You're all caught up! No notifications at this time."; ?>
                            </p>
                        </div>
                    <?php else: ?>

                        <?php if (!empty($today_notifications)): ?>
                            <div class="notif-group-label"><i class="bi bi-sun"></i> Today</div>
                            <?php foreach ($today_notifications as $n): ?>
                                <?php echo render_notification_item($n); ?>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if (!empty($earlier_notifications)): ?>
                            <div class="notif-group-label"><i class="bi bi-clock-history"></i> Earlier</div>
                            <?php foreach ($earlier_notifications as $n): ?>
                                <?php echo render_notification_item($n); ?>
                            <?php endforeach; ?>
                        <?php endif; ?>

                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div><!-- /.admin-content -->

    <!-- ═══ Filter Sidebar ═══ -->
    <div class="filter-sidebar" id="filterSidebar">
        <div class="filter-sidebar-overlay" onclick="closeFilterSidebar()"></div>
        <div class="filter-sidebar-content">
            <div class="filter-header">
                <h4><i class="bi bi-funnel"></i> Filter Notifications</h4>
                <button class="btn-close-filter" onclick="closeFilterSidebar()"><i class="bi bi-x-lg"></i></button>
            </div>
            <form id="filterForm" method="GET" action="">
            <div class="filter-body">
                <!-- Status -->
                <div class="filter-section">
                    <div class="filter-section-title"><i class="bi bi-check-circle"></i> Read Status</div>
                    <?php foreach (['all' => 'All', 'unread' => 'Unread', 'read' => 'Read'] as $val => $label): ?>
                    <div class="filter-option">
                        <input type="radio" name="filter" id="f_status_<?php echo $val; ?>" value="<?php echo $val; ?>" <?php echo $filter === $val ? 'checked' : ''; ?>>
                        <label for="f_status_<?php echo $val; ?>">
                            <?php echo $label; ?>
                            <?php if ($val === 'all'): ?><span class="filter-count"><?php echo $total_count; ?></span><?php endif; ?>
                            <?php if ($val === 'unread'): ?><span class="filter-count"><?php echo $unread_count; ?></span><?php endif; ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <!-- Type -->
                <div class="filter-section">
                    <div class="filter-section-title"><i class="bi bi-tags"></i> Notification Type</div>
                    <?php
                    $types = [
                        'all' => ['All Types', $total_count, 'bi-list-ul'],
                        'agent' => ['Agent', $agent_count, 'bi-person-badge'],
                        'property' => ['Property', $property_count, 'bi-building'],
                        'tour' => ['Tour', $tour_count, 'bi-calendar-check'],
                        'property_sale' => ['Sale', $pending_sales_count, 'bi-cash-stack'],
                    ];
                    foreach ($types as $val => $info): ?>
                    <div class="filter-option">
                        <input type="radio" name="type" id="f_type_<?php echo $val; ?>" value="<?php echo $val; ?>" <?php echo $type_filter === $val ? 'checked' : ''; ?>>
                        <label for="f_type_<?php echo $val; ?>">
                            <span><i class="bi <?php echo $info[2]; ?> me-1"></i> <?php echo $info[0]; ?></span>
                            <span class="filter-count"><?php echo $info[1]; ?></span>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <!-- Priority -->
                <div class="filter-section">
                    <div class="filter-section-title"><i class="bi bi-flag"></i> Priority</div>
                    <?php foreach (['all' => 'All Priorities', 'urgent' => 'Urgent', 'high' => 'High', 'normal' => 'Normal', 'low' => 'Low'] as $val => $label): ?>
                    <div class="filter-option">
                        <input type="radio" name="priority" id="f_pri_<?php echo $val; ?>" value="<?php echo $val; ?>" <?php echo $priority_filter === $val ? 'checked' : ''; ?>>
                        <label for="f_pri_<?php echo $val; ?>"><?php echo $label; ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="filter-footer">
                <button type="button" class="btn-outline-admin" onclick="window.location.href='?'"><i class="bi bi-arrow-clockwise"></i> Reset</button>
                <button type="submit" class="btn-gold"><i class="bi bi-check-lg"></i> Apply Filters</button>
            </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ── Filter Sidebar ─────────────────────────────
        function openFilterSidebar()  { document.getElementById('filterSidebar').classList.add('active'); }
        function closeFilterSidebar() { document.getElementById('filterSidebar').classList.remove('active'); }
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeFilterSidebar(); });

        // ── AJAX helpers ───────────────────────────────
        function ajaxPost(data) {
            return fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: data
            }).then(r => r.json());
        }

        function markAsRead(id) {
            ajaxPost('mark_read=1&notification_id=' + id).then(d => { if (d.success) location.reload(); });
        }

        function markAllAsRead() {
            if (!confirm('Mark all notifications as read?')) return;
            ajaxPost('mark_all_read=1').then(d => { if (d.success) location.reload(); });
        }

        function deleteNotification(id) {
            if (!confirm('Delete this notification?')) return;
            ajaxPost('delete_notification=1&notification_id=' + id).then(d => { if (d.success) location.reload(); });
        }

        function deleteAllRead() {
            if (!confirm('Delete all read notifications? This cannot be undone.')) return;
            ajaxPost('delete_all_read=1').then(d => { if (d.success) location.reload(); });
        }
    </script>
</body>
</html>

<?php
// ── Render a single notification row ───────────────────
function render_notification_item($n) {
    $is_unread   = !isset($n['is_read']) || (int)$n['is_read'] === 0;
    $item_type   = $n['item_type'] ?? 'general';
    $icon        = $n['icon'] ?? 'bi-bell';
    $priority    = $n['priority'] ?? 'normal';
    $title       = htmlspecialchars($n['title'] ?? 'Notification');
    $message     = htmlspecialchars($n['message'] ?? '');
    $time_ago    = notif_time_ago($n['created_at']);
    $action_url  = htmlspecialchars($n['action_url'] ?? '#');
    $nid         = (int)$n['notification_id'];
    $type_label  = ucfirst(str_replace('_', ' ', $item_type));

    $unread_class = $is_unread ? ' unread' : '';

    $html  = '<div class="notif-item' . $unread_class . '" id="notif-' . $nid . '">';
    $html .= '  <div class="notif-item-icon ' . htmlspecialchars($item_type) . '"><i class="bi ' . htmlspecialchars($icon) . '"></i></div>';
    $html .= '  <div class="notif-item-body">';
    $html .= '    <div class="notif-item-title">' . $title;
    $html .= '      <span class="notif-priority-tag ' . htmlspecialchars($priority) . '">';
    if ($priority === 'urgent') $html .= '<i class="bi bi-exclamation-triangle-fill"></i> ';
    if ($priority === 'high')   $html .= '<i class="bi bi-flag-fill"></i> ';
    $html .= htmlspecialchars(ucfirst($priority)) . '</span>';
    $html .= '    </div>';
    $html .= '    <div class="notif-item-message">' . $message . '</div>';
    $html .= '    <div class="notif-item-meta">';
    $html .= '      <span class="notif-time"><i class="bi bi-clock"></i> ' . $time_ago . '</span>';
    $html .= '      <span class="notif-type-tag ' . htmlspecialchars($item_type) . '">' . $type_label . '</span>';
    $html .= '    </div>';
    $html .= '  </div>';
    $html .= '  <div class="notif-item-actions">';
    $html .= '    <a href="' . $action_url . '" class="notif-action-btn btn-view" title="View Details"><i class="bi bi-arrow-right"></i></a>';
    if ($is_unread) {
        $html .= '    <button class="notif-action-btn btn-check" onclick="markAsRead(' . $nid . ')" title="Mark as Read"><i class="bi bi-check-lg"></i></button>';
    }
    $html .= '    <button class="notif-action-btn btn-trash" onclick="deleteNotification(' . $nid . ')" title="Delete"><i class="bi bi-trash3"></i></button>';
    $html .= '  </div>';
    $html .= '</div>';

    return $html;
}

$conn->close();
?>
