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

// Handle mark as read
if (isset($_POST['mark_read']) && isset($_POST['notification_id'])) {
    $notification_id = (int)$_POST['notification_id'];
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id = ?");
    $stmt->bind_param("i", $notification_id);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true]);
    exit();
}

// Handle mark all as read
if (isset($_POST['mark_all_read'])) {
    $conn->query("UPDATE notifications SET is_read = 1");
    echo json_encode(['success' => true]);
    exit();
}

// Handle delete notification
if (isset($_POST['delete_notification']) && isset($_POST['notification_id'])) {
    $notification_id = (int)$_POST['notification_id'];
    $stmt = $conn->prepare("DELETE FROM notifications WHERE notification_id = ?");
    $stmt->bind_param("i", $notification_id);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true]);
    exit();
}

// Get filter parameters
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$type_filter = isset($_GET['type']) ? $_GET['type'] : 'all';

// Build query
$where_conditions = [];
if ($filter === 'unread') {
    $where_conditions[] = "(is_read = 0 OR is_read IS NULL)";
} elseif ($filter === 'read') {
    $where_conditions[] = "is_read = 1";
}

if ($type_filter !== 'all') {
    $where_conditions[] = "item_type = '" . $conn->real_escape_string($type_filter) . "'";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Fetch notifications
$sql = "SELECT notification_id, item_id, item_type, message, created_at, is_read FROM notifications $where_clause ORDER BY created_at DESC";
$result = $conn->query($sql);
$notifications = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
}

// Get counts
$total_count = $conn->query("SELECT COUNT(*) as count FROM notifications")->fetch_assoc()['count'];
$unread_count = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE is_read = 0 OR is_read IS NULL")->fetch_assoc()['count'];
$agent_count = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE item_type = 'agent'")->fetch_assoc()['count'];
$property_count = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE item_type IN ('property', 'property_sale')")->fetch_assoc()['count'];
$tour_count = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE item_type = 'tour'")->fetch_assoc()['count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --primary-color: #161209;
            --secondary-color: #bc9e42;
            --accent-color: #a08636;
            --bg-light: #f8f9fa;
            --border-color: #e0e0e0;
            --text-muted: #6c757d;
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

        /* Card Styles */
        .card {
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: box-shadow 0.2s ease;
        }
        
        .card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
        }

        .card-header {
            background-color: #fff;
            border-bottom: 1px solid var(--border-color);
            padding: 1.25rem 1.5rem;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Dashboard Header */
        .dashboard-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, #2a2318 100%);
            color: #fff;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
        }

        .dashboard-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .dashboard-header p {
            opacity: 0.9;
            margin-bottom: 0;
        }

        /* Stats Cards */
        .stat-card {
            background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
            border: 1px solid var(--border-color);
            border-left: 4px solid var(--secondary-color);
            border-radius: 12px;
            min-height: 120px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
        }

        .stat-card .card-body {
            padding: 1.5rem;
            display: flex;
            align-items: center;
        }

        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
            margin-right: 1rem;
        }

        .stat-icon.total {
            background: rgba(188, 158, 66, 0.1);
            color: var(--secondary-color);
        }

        .stat-icon.unread {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }

        .stat-icon.agent {
            background: rgba(13, 110, 253, 0.1);
            color: #0d6efd;
        }

        .stat-icon.property {
            background: rgba(25, 135, 84, 0.1);
            color: #198754;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
            line-height: 1.2;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0;
        }

        /* Filter Section */
        .filter-section {
            background: #fff;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .filter-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-color);
        }

        .filter-tabs {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .filter-tab {
            padding: 0.6rem 1.25rem;
            border: 1px solid var(--border-color);
            background: #fff;
            color: var(--primary-color);
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            transition: all 0.2s ease;
        }

        .filter-tab:hover {
            background: var(--bg-light);
            color: var(--primary-color);
            border-color: var(--secondary-color);
        }

        .filter-tab.active {
            background: var(--secondary-color);
            color: white;
            border-color: var(--secondary-color);
        }

        .filter-badge {
            background: rgba(0, 0, 0, 0.1);
            color: inherit;
            padding: 0.15rem 0.5rem;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .filter-tab.active .filter-badge {
            background: rgba(255, 255, 255, 0.3);
        }

        /* Notifications List */
        .notifications-container {
            background: #fff;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .notifications-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--bg-light);
        }

        .notifications-title {
            font-weight: 600;
            color: var(--primary-color);
            font-size: 1.1rem;
        }

        .btn-mark-all {
            background: var(--secondary-color);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-mark-all:hover {
            background: var(--accent-color);
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(188, 158, 66, 0.3);
        }

        .notification-item {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            gap: 1rem;
            align-items: flex-start;
            transition: background-color 0.2s ease;
        }

        .notification-item:last-child {
            border-bottom: none;
        }

        .notification-item:hover {
            background-color: var(--bg-light);
        }

        .notification-item.unread {
            background: rgba(188, 158, 66, 0.05);
            border-left: 4px solid var(--secondary-color);
        }

        .notification-item.unread:hover {
            background: rgba(188, 158, 66, 0.08);
        }

        .notification-icon-wrapper {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .notification-icon-wrapper.property {
            background: rgba(13, 110, 253, 0.1);
            color: #0d6efd;
        }

        .notification-icon-wrapper.agent {
            background: rgba(25, 135, 84, 0.1);
            color: #198754;
        }

        .notification-icon-wrapper.sale {
            background: rgba(188, 158, 66, 0.1);
            color: var(--secondary-color);
        }

        .notification-icon-wrapper.tour {
            background: rgba(13, 202, 240, 0.1);
            color: #0dcaf0;
        }

        .notification-content {
            flex: 1;
        }

        .notification-message {
            color: var(--primary-color);
            font-size: 0.95rem;
            line-height: 1.5;
            margin-bottom: 0.5rem;
        }

        .notification-meta {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .notification-time {
            color: var(--text-muted);
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .notification-type-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .type-property {
            background: rgba(13, 110, 253, 0.1);
            color: #0d6efd;
        }

        .type-agent {
            background: rgba(25, 135, 84, 0.1);
            color: #198754;
        }

        .type-property_sale {
            background: rgba(188, 158, 66, 0.1);
            color: var(--secondary-color);
        }

        .type-tour {
            background: rgba(13, 202, 240, 0.1);
            color: #0dcaf0;
        }

        .notification-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .btn-notification {
            padding: 0.5rem 0.75rem;
            border: none;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-read {
            background: var(--secondary-color);
            color: white;
        }

        .btn-read:hover {
            background: var(--accent-color);
            transform: translateY(-1px);
        }

        .btn-delete {
            background: #dc3545;
            color: white;
        }

        .btn-delete:hover {
            background: #c82333;
            transform: translateY(-1px);
        }

        .unread-indicator {
            width: 10px;
            height: 10px;
            background: var(--secondary-color);
            border-radius: 50%;
            flex-shrink: 0;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
        }

        .empty-icon {
            font-size: 4rem;
            color: var(--border-color);
            margin-bottom: 1rem;
        }

        .empty-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .empty-text {
            color: var(--text-muted);
        }

        /* Filter Button */
        .btn-filter {
            background: var(--secondary-color);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
        }

        .btn-filter:hover {
            background: var(--accent-color);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(188, 158, 66, 0.3);
        }

        /* Active Filters Display */
        .active-filters {
            background: #fff;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .active-filters-label {
            font-weight: 600;
            color: var(--primary-color);
            font-size: 0.9rem;
        }

        .active-filter-badge {
            background: var(--secondary-color);
            color: white;
            padding: 0.4rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .remove-filter {
            color: white;
            text-decoration: none;
            opacity: 0.8;
            margin-left: 0.25rem;
        }

        .remove-filter:hover {
            opacity: 1;
            color: white;
        }

        .clear-all-filters {
            color: #dc3545;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            padding: 0.4rem 0.75rem;
            border-radius: 6px;
            border: 1px solid #dc3545;
        }

        .clear-all-filters:hover {
            background: #dc3545;
            color: white;
            transform: translateY(-1px);
        }

        /* Filter Modal */
        .modal-content {
            border: none;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, #2a2318 100%);
            color: white;
            border-radius: 12px 12px 0 0;
            padding: 1.5rem;
            border-bottom: none;
        }

        .modal-title {
            font-weight: 700;
            font-size: 1.3rem;
        }

        .btn-close {
            filter: brightness(0) invert(1);
            opacity: 0.8;
        }

        .btn-close:hover {
            opacity: 1;
        }

        .modal-body {
            padding: 2rem;
            background: var(--bg-light);
        }

        .filter-group {
            margin-bottom: 2rem;
        }

        .filter-group-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 0.75rem;
        }

        .filter-option {
            position: relative;
        }

        .filter-option input[type="radio"] {
            display: none;
        }

        .filter-option label {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.75rem 1rem;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            color: var(--primary-color);
            background: #fff;
            text-align: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
        }

        .filter-option input[type="radio"]:checked + label {
            background: var(--secondary-color);
            color: white;
            border-color: var(--secondary-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(188, 158, 66, 0.3);
        }

        .filter-option label:hover {
            border-color: var(--secondary-color);
            transform: translateY(-1px);
        }

        .filter-count {
            background: rgba(0, 0, 0, 0.1);
            padding: 0.15rem 0.5rem;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .filter-option input[type="radio"]:checked + label .filter-count {
            background: rgba(255, 255, 255, 0.3);
        }

        .modal-footer {
            border-top: 1px solid var(--border-color);
            padding: 1.25rem 2rem;
            background: #fff;
            border-radius: 0 0 12px 12px;
        }

        .btn-apply-filter {
            background: var(--secondary-color);
            color: white;
            border: none;
            padding: 0.75rem 2rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-apply-filter:hover {
            background: var(--accent-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(188, 158, 66, 0.3);
        }

        .btn-reset-filter {
            background: transparent;
            color: var(--text-muted);
            border: 1px solid var(--border-color);
            padding: 0.75rem 2rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-reset-filter:hover {
            background: var(--bg-light);
            color: var(--primary-color);
            border-color: var(--primary-color);
        }

        /* Responsive - Already handled above */
        @media (max-width: 768px) {
            .page-title {
                font-size: 1.5rem;
            }

            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }

            .filter-tabs {
                flex-direction: column;
            }

            .filter-tab {
                width: 100%;
                justify-content: center;
            }

            .notification-item {
                padding: 1rem;
            }

            .notifications-header {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch;
            }

            .btn-mark-all {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <?php include 'admin_sidebar.php'; ?>
    <?php include 'admin_navbar.php'; ?>

    <div class="admin-content">
        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <h1><i class="bi bi-bell-fill me-2"></i>Notifications Center</h1>
            <p>Stay updated with all your system notifications and alerts</p>
        </div>

        <!-- Filter Button -->
        <div class="mb-4">
            <button class="btn-filter" data-bs-toggle="modal" data-bs-target="#filterModal">
                <i class="bi bi-funnel me-2"></i>Filter Notifications
            </button>
        </div>

        <!-- Stats Cards -->
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon total me-3">
                                <i class="bi bi-bell"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="stat-value"><?php echo $total_count; ?></div>
                                <div class="stat-label">Total Notifications</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon unread me-3">
                                <i class="bi bi-envelope"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="stat-value"><?php echo $unread_count; ?></div>
                                <div class="stat-label">Unread</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon agent me-3">
                                <i class="bi bi-person-badge"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="stat-value"><?php echo $agent_count; ?></div>
                                <div class="stat-label">Agent Requests</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon property me-3">
                                <i class="bi bi-buildings"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="stat-value"><?php echo $property_count; ?></div>
                                <div class="stat-label">Property Updates</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Active Filters Display -->
        <?php if ($filter !== 'all' || $type_filter !== 'all'): ?>
        <div class="active-filters">
            <span class="active-filters-label"><i class="bi bi-funnel me-2"></i>Active Filters:</span>
            <?php if ($filter !== 'all'): ?>
            <span class="active-filter-badge">
                Status: <?php echo ucfirst($filter); ?>
                <a href="?filter=all&type=<?php echo $type_filter; ?>" class="remove-filter"><i class="bi bi-x"></i></a>
            </span>
            <?php endif; ?>
            <?php if ($type_filter !== 'all'): ?>
            <span class="active-filter-badge">
                Type: <?php echo ucfirst($type_filter); ?>
                <a href="?filter=<?php echo $filter; ?>&type=all" class="remove-filter"><i class="bi bi-x"></i></a>
            </span>
            <?php endif; ?>
            <a href="?" class="clear-all-filters">Clear All</a>
        </div>
        <?php endif; ?>

        <!-- Notifications List -->
        <div class="card">
            <div class="notifications-header">
                <div class="notifications-title">
                    <i class="bi bi-list-ul me-2"></i><?php echo count($notifications); ?> notification(s)
                </div>
                <?php if ($unread_count > 0): ?>
                <button class="btn-mark-all" onclick="markAllAsRead()">
                    <i class="bi bi-check2-all me-1"></i>Mark All as Read
                </button>
                <?php endif; ?>
            </div>

            <?php if (empty($notifications)): ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="bi bi-bell-slash"></i>
                    </div>
                    <div class="empty-title">No Notifications Found</div>
                    <div class="empty-text">
                        <?php if ($filter !== 'all' || $type_filter !== 'all'): ?>
                            Try adjusting your filters to see more results
                        <?php else: ?>
                            You're all caught up! No new notifications at this time.
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($notifications as $notification): ?>
                    <?php
                    $is_unread = !isset($notification['is_read']) || (int)$notification['is_read'] === 0;
                    $item_type = $notification['item_type'] ?? 'general';
                    
                    // Determine icon based on item type
                    $icon_class = 'bi bi-bell';
                    $icon_wrapper_class = 'tour';
                    if (strpos($item_type, 'property') !== false) {
                        $icon_class = 'bi bi-buildings';
                        $icon_wrapper_class = 'property';
                    } elseif (strpos($item_type, 'agent') !== false) {
                        $icon_class = 'bi bi-person-badge';
                        $icon_wrapper_class = 'agent';
                    } elseif (strpos($item_type, 'sale') !== false) {
                        $icon_class = 'bi bi-currency-dollar';
                        $icon_wrapper_class = 'sale';
                    } elseif (strpos($item_type, 'tour') !== false) {
                        $icon_class = 'bi bi-calendar-check';
                        $icon_wrapper_class = 'tour';
                    }
                    
                    // Format time
                    $time_ago = '';
                    if (!empty($notification['created_at'])) {
                        $time_diff = time() - strtotime($notification['created_at']);
                        if ($time_diff < 60) {
                            $time_ago = 'Just now';
                        } elseif ($time_diff < 3600) {
                            $time_ago = floor($time_diff / 60) . ' min ago';
                        } elseif ($time_diff < 86400) {
                            $time_ago = floor($time_diff / 3600) . ' hr ago';
                        } else {
                            $time_ago = date('M d, Y', strtotime($notification['created_at']));
                        }
                    }
                    ?>
                    <div class="notification-item <?php echo $is_unread ? 'unread' : ''; ?>">
                        <div class="notification-icon-wrapper <?php echo $icon_wrapper_class; ?>">
                            <i class="<?php echo $icon_class; ?>"></i>
                        </div>
                        
                        <div class="notification-content">
                            <div class="notification-message">
                                <?php echo htmlspecialchars($notification['message'] ?? 'New notification'); ?>
                            </div>
                            <div class="notification-meta">
                                <span class="notification-time">
                                    <i class="bi bi-clock"></i> <?php echo $time_ago; ?>
                                </span>
                                <span class="notification-type-badge type-<?php echo $item_type; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $item_type)); ?>
                                </span>
                            </div>
                        </div>

                        <div class="notification-actions">
                            <?php if ($is_unread): ?>
                            <button class="btn-notification btn-read" onclick="markAsRead(<?php echo $notification['notification_id']; ?>)" title="Mark as read">
                                <i class="bi bi-check-lg"></i>
                            </button>
                            <?php endif; ?>
                            <button class="btn-notification btn-delete" onclick="deleteNotification(<?php echo $notification['notification_id']; ?>)" title="Delete">
                                <i class="bi bi-trash"></i>
                            </button>
                            <?php if ($is_unread): ?>
                            <div class="unread-indicator"></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filter Modal -->
    <div class="modal fade" id="filterModal" tabindex="-1" aria-labelledby="filterModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="filterModalLabel">
                        <i class="bi bi-funnel me-2"></i>Filter Notifications
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="filterForm" method="GET" action="">
                    <div class="modal-body">
                        <!-- Filter by Status -->
                        <div class="filter-group">
                            <div class="filter-group-title">
                                <i class="bi bi-check-circle"></i>
                                Filter by Status
                            </div>
                            <div class="filter-options">
                                <div class="filter-option">
                                    <input type="radio" name="filter" id="status-all" value="all" <?php echo $filter === 'all' ? 'checked' : ''; ?>>
                                    <label for="status-all">
                                        All
                                        <span class="filter-count"><?php echo $total_count; ?></span>
                                    </label>
                                </div>
                                <div class="filter-option">
                                    <input type="radio" name="filter" id="status-unread" value="unread" <?php echo $filter === 'unread' ? 'checked' : ''; ?>>
                                    <label for="status-unread">
                                        Unread
                                        <span class="filter-count"><?php echo $unread_count; ?></span>
                                    </label>
                                </div>
                                <div class="filter-option">
                                    <input type="radio" name="filter" id="status-read" value="read" <?php echo $filter === 'read' ? 'checked' : ''; ?>>
                                    <label for="status-read">
                                        Read
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Filter by Type -->
                        <div class="filter-group">
                            <div class="filter-group-title">
                                <i class="bi bi-tags"></i>
                                Filter by Type
                            </div>
                            <div class="filter-options">
                                <div class="filter-option">
                                    <input type="radio" name="type" id="type-all" value="all" <?php echo $type_filter === 'all' ? 'checked' : ''; ?>>
                                    <label for="type-all">
                                        <i class="bi bi-list-ul"></i>
                                        All
                                    </label>
                                </div>
                                <div class="filter-option">
                                    <input type="radio" name="type" id="type-agent" value="agent" <?php echo $type_filter === 'agent' ? 'checked' : ''; ?>>
                                    <label for="type-agent">
                                        <i class="bi bi-person-badge"></i>
                                        Agents
                                        <span class="filter-count"><?php echo $agent_count; ?></span>
                                    </label>
                                </div>
                                <div class="filter-option">
                                    <input type="radio" name="type" id="type-property" value="property" <?php echo $type_filter === 'property' ? 'checked' : ''; ?>>
                                    <label for="type-property">
                                        <i class="bi bi-buildings"></i>
                                        Properties
                                        <span class="filter-count"><?php echo $property_count; ?></span>
                                    </label>
                                </div>
                                <div class="filter-option">
                                    <input type="radio" name="type" id="type-tour" value="tour" <?php echo $type_filter === 'tour' ? 'checked' : ''; ?>>
                                    <label for="type-tour">
                                        <i class="bi bi-calendar-check"></i>
                                        Tours
                                        <span class="filter-count"><?php echo $tour_count; ?></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-reset-filter" onclick="resetFilters()">
                            <i class="bi bi-arrow-clockwise me-2"></i>Reset
                        </button>
                        <button type="submit" class="btn-apply-filter">
                            <i class="bi bi-check-lg me-2"></i>Apply Filters
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function resetFilters() {
            window.location.href = '?';
        }

        function markAsRead(notificationId) {
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'mark_read=1&notification_id=' + notificationId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            })
            .catch(error => console.error('Error:', error));
        }

        function markAllAsRead() {
            if (confirm('Mark all notifications as read?')) {
                fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'mark_all_read=1'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    }
                })
                .catch(error => console.error('Error:', error));
            }
        }

        function deleteNotification(notificationId) {
            if (confirm('Are you sure you want to delete this notification?')) {
                fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'delete_notification=1&notification_id=' + notificationId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    }
                })
                .catch(error => console.error('Error:', error));
            }
        }
    </script>
</body>
</html>
<?php $conn->close(); ?>
