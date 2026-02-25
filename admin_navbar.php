<?php
// admin_navbar.php
// Ensure session is started and user info is available
include 'logout_modal.php';
if (!isset($_SESSION['username'])) {
    // Fallback if session not properly initialized
    $username = 'Admin';
    $user_role = 'admin';
} else {
    $username = $_SESSION['username'];
    $user_role = $_SESSION['user_role'] ?? 'admin';
}

// Get user's info and admin profile if available
$first_name = 'Admin';
$user_email = '';
$date_registered = '';
$admin_profile = null; // will hold admin_information if exists
if (isset($_SESSION['account_id']) && isset($conn)) {
    $account_id = (int)$_SESSION['account_id'];
    $stmt = $conn->prepare("SELECT a.first_name, a.last_name, a.email, a.date_registered, ai.license_number, ai.years_experience, ai.profile_picture_url FROM accounts a LEFT JOIN admin_information ai ON a.account_id = ai.account_id WHERE a.account_id = ? LIMIT 1");
    $stmt->bind_param("i", $account_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $first_name = $row['first_name'] ?? 'Admin';
        $last_name = $row['last_name'] ?? '';
        $user_email = $row['email'] ?? '';
        $date_registered = $row['date_registered'] ?? '';
        if (!empty($row['license_number']) || !empty($row['years_experience']) || !empty($row['profile_picture_url'])) {
            $admin_profile = [
                'license_number' => $row['license_number'] ?? null,
                'years_experience' => $row['years_experience'] ?? null,
                'profile_picture_url' => $row['profile_picture_url'] ?? null
            ];
        }
    }
    $stmt->close();
}

// Get unread notifications count and recent notifications
$unread_notifications = 0;
$recent_notifications = [];
if (isset($conn) && isset($_SESSION['account_id'])) {
    $nav_admin_id = (int)$_SESSION['account_id'];

    // Security: Only count/show tour notifications for properties managed by this admin
    $nav_tour_filter = "AND (n.item_type != 'tour' OR EXISTS (SELECT 1 FROM tour_requests tr JOIN property_log pl ON tr.property_id = pl.property_id AND pl.action = 'CREATED' WHERE tr.tour_id = n.item_id AND pl.account_id = $nav_admin_id))";

    // Count unread (filtered)
    $notif_count_result = $conn->query("SELECT COUNT(*) as count FROM notifications n WHERE (n.is_read = 0 OR n.is_read IS NULL) $nav_tour_filter");
    if ($notif_count_result && $notif_row = $notif_count_result->fetch_assoc()) {
        $unread_notifications = (int)$notif_row['count'];
    }

    // Check if new columns exist
    $navbar_has_new_cols = false;
    $col_chk = $conn->query("SHOW COLUMNS FROM notifications LIKE 'title'");
    if ($col_chk && $col_chk->num_rows > 0) $navbar_has_new_cols = true;

    // Get recent 5 notifications (prioritise unread first, filtered for admin-managed tour properties)
    if ($navbar_has_new_cols) {
        $recent_sql = "SELECT n.notification_id, n.item_id, n.item_type, n.title, n.message, n.category, n.priority, n.action_url, n.icon, n.created_at, n.is_read FROM notifications n WHERE 1=1 $nav_tour_filter ORDER BY n.is_read ASC, n.created_at DESC LIMIT 5";
    } else {
        $recent_sql = "SELECT n.notification_id, n.item_id, n.item_type, n.message, n.created_at, n.is_read FROM notifications n WHERE 1=1 $nav_tour_filter ORDER BY n.is_read ASC, n.created_at DESC LIMIT 5";
    }
    $recent_result = $conn->query($recent_sql);
    while ($notif = $recent_result->fetch_assoc()) {
        // Fill defaults for old schema
        if (!$navbar_has_new_cols) {
            $notif['title'] = '';
            $notif['category'] = 'update';
            $notif['priority'] = 'normal';
            $notif['action_url'] = null;
            $notif['icon'] = null;
            $notif['item_id'] = $notif['item_id'] ?? 0;
        }
        // Auto-derive missing fields
        if (empty($notif['title'])) {
            switch ($notif['item_type']) {
                case 'agent':        $notif['title'] = 'Agent Profile Submission'; break;
                case 'tour':         $notif['title'] = 'New Tour Request'; break;
                case 'property':     $notif['title'] = 'Property Update'; break;
                case 'property_sale':$notif['title'] = 'Sale Verification'; break;
                default:             $notif['title'] = 'Notification'; break;
            }
        }
        if (empty($notif['icon'])) {
            switch ($notif['item_type']) {
                case 'agent':        $notif['icon'] = 'bi-person-badge'; break;
                case 'tour':         $notif['icon'] = 'bi-calendar-check'; break;
                case 'property':     $notif['icon'] = 'bi-building'; break;
                case 'property_sale':$notif['icon'] = 'bi-cash-stack'; break;
                default:             $notif['icon'] = 'bi-bell'; break;
            }
        }
        if (empty($notif['action_url'])) {
            switch ($notif['item_type']) {
                case 'agent':        $notif['action_url'] = 'review_agent_details.php?id=' . ($notif['item_id'] ?? 0); break;
                case 'tour':         $notif['action_url'] = 'admin_tour_request_details.php?id=' . ($notif['item_id'] ?? 0); break;
                case 'property':     $notif['action_url'] = 'view_property.php?id=' . ($notif['item_id'] ?? 0); break;
                case 'property_sale':$notif['action_url'] = 'admin_property_sale_approvals.php'; break;
                default:             $notif['action_url'] = 'admin_notifications.php'; break;
            }
        }
        $recent_notifications[] = $notif;
    }

    // Live counts for navbar quick indicators
    $navbar_pending_actions = 0;
    $r = $conn->query("SELECT COUNT(*) as c FROM agent_information WHERE is_approved = 0");
    if ($r) $navbar_pending_actions += (int)$r->fetch_assoc()['c'];
    $r = $conn->query("SELECT COUNT(*) as c FROM property WHERE approval_status = 'pending'");
    if ($r) $navbar_pending_actions += (int)$r->fetch_assoc()['c'];
    $r = $conn->query("SELECT COUNT(*) as c FROM tour_requests WHERE request_status = 'Pending'");
    if ($r) $navbar_pending_actions += (int)$r->fetch_assoc()['c'];
    $r = $conn->query("SELECT COUNT(*) as c FROM sale_verifications WHERE status = 'Pending'");
    if ($r) $navbar_pending_actions += (int)$r->fetch_assoc()['c'];
}

// Define page titles for dynamic navbar title
$page_titles = [
    'admin_dashboard.php' => 'Dashboard Overview',
    'property.php' => 'Property Management',
    'view_property.php' => 'Property Details',
    'agent.php' => 'Agent Management',
    'clients.php' => 'Client Management',
    'tour_requests.php' => 'Tour Requests',
    'admin_property_sale_approvals.php' => 'Property Sale Approvals',
    'admin_notifications.php' => 'Notifications',
    'admin_profile.php' => 'My Profile',
    'admin_settings.php' => 'System Settings',
];

$current_page = basename($_SERVER['PHP_SELF']);
$page_title = isset($page_titles[$current_page]) ? $page_titles[$current_page] : 'Admin Panel';
?>

<!-- Ensure Bootstrap Icons are available on any page that includes the navbar -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<!-- Standardized Admin Layout CSS -->
<link rel="stylesheet" href="css/admin_layout.css">

<style>
    :root {
        /* Primary Brand Colors */
        --gold: #d4af37;
        --gold-light: #f4d03f;
        --gold-dark: #b8941f;
        --blue: #2563eb;
        --blue-light: #3b82f6;
        --blue-dark: #1e40af;
        
        /* Neutral Palette */
        --black: #0a0a0a;
        --black-light: #111111;
        --black-lighter: #1a1a1a;
        --black-border: #1f1f1f;
        --white: #ffffff;
        
        /* Semantic Grays */
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
    }

    /* ================================================
       ADMIN NAVBAR STYLES
       ================================================ */
    .admin-navbar {
        background: linear-gradient(135deg, rgba(26, 26, 26, 0.95) 0%, rgba(10, 10, 10, 0.98) 100%);
        border-bottom: 1px solid rgba(37, 99, 235, 0.2);
        height: var(--navbar-height);
        margin-left: var(--sidebar-width);
        padding: 0;
        box-shadow: 0 4px 24px rgba(0, 0, 0, 0.3);
        position: sticky;
        top: 0;
        z-index: 999;
        backdrop-filter: blur(10px);
        transition: all 0.3s ease;
    }

    .admin-navbar::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 1px;
        background: linear-gradient(90deg, transparent, var(--gold), transparent);
        opacity: 0.3;
    }

    @media (max-width: 992px) {
        .admin-navbar {
            margin-left: 0;
        }
    }

    .navbar-container {
        display: flex;
        align-items: center;
        justify-content: space-between;
        height: 100%;
        padding: 0 2rem;
    }

    .navbar-left {
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .sidebar-toggle {
        background: none;
        border: none;
        color: var(--text-muted);
        font-size: 1.2rem;
        padding: 0.5rem;
        border-radius: 6px;
        transition: all 0.3s ease;
        display: none;
    }

    .sidebar-toggle:hover {
        background: rgba(37, 99, 235, 0.15);
        color: var(--blue-light);
    }

    .page-title {
        font-size: 1.4rem;
        font-weight: 700;
        color: var(--white);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .page-title i {
        color: var(--gold);
    }

    .breadcrumb-nav {
        font-size: 0.85rem;
        color: var(--text-muted);
        margin-top: 0.25rem;
    }

    .breadcrumb-nav a {
        color: var(--text-muted);
        text-decoration: none;
        transition: color 0.3s ease;
    }

    .breadcrumb-nav a:hover {
        color: var(--gold);
    }

    .navbar-right {
        display: flex;
        align-items: center;
        gap: 1.5rem;
    }

    .navbar-actions {
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .nav-icon {
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        height: 40px;
        width: 40px;
        padding: 0;
        color: var(--text-muted);
        text-decoration: none;
        border-radius: 8px;
        transition: all 0.15s ease;
        background: transparent;
        border: none;
        font-size: 1.05rem;
    }

    .nav-icon:hover {
        background: rgba(37, 99, 235, 0.15);
        color: var(--blue-light);
        transform: translateY(-1px);
    }

    .notification-badge {
        position: absolute;
        top: -6px;
        right: -6px;
        background: #dc3545;
        color: white;
        border-radius: 50%;
        width: 20px;
        height: 20px;
        font-size: 0.75rem;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        animation: pulse 2s infinite;
    }

    @keyframes pulse {
        0% { transform: scale(1); opacity: 1; }
        50% { transform: scale(1.1); opacity: 0.8; }
        100% { transform: scale(1); opacity: 1; }
    }

    .user-dropdown {
        position: relative;
    }

    .user-info {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.5rem 1rem;
        background: rgba(26, 26, 26, 0.8);
        border: 1px solid rgba(37, 99, 235, 0.3);
        border-radius: 25px;
        text-decoration: none;
        color: var(--white);
        transition: all 0.3s ease;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
    }

    .user-info:hover {
        background: rgba(37, 99, 235, 0.15);
        border-color: var(--blue);
        transform: translateY(-1px);
        box-shadow: 0 6px 16px rgba(37, 99, 235, 0.25);
        color: var(--white);
    }

    .user-avatar {
        width: 35px;
        height: 35px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid var(--gold);
        box-shadow: 0 0 8px rgba(212, 175, 55, 0.3);
    }

    .user-details {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
    }

    .user-name {
        font-weight: 600;
        font-size: 0.9rem;
        line-height: 1;
        margin-bottom: 0.1rem;
    }

    .user-role {
        font-size: 0.75rem;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .dropdown-toggle::after {
        margin-left: 0.5rem;
        color: var(--text-muted);
    }

    .dropdown-menu {
        border: none;
        border-radius: 16px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
        padding: 0;
        margin-top: 0.75rem;
        min-width: 350px;
        max-width: 400px;
        overflow: hidden;
        background: #fff;
        max-height: 500px;
        overflow-y: auto;
    }

    .dropdown-menu.notification-dropdown {
        min-width: 380px;
    }

    .notification-dropdown-header {
        background: linear-gradient(135deg, var(--primary-color) 0%, #2c241a 100%);
        color: white;
        padding: 1rem 1.25rem;
        font-weight: 700;
        font-size: 1rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .notification-dropdown-header .unread-count {
        background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 100%);
        color: var(--black);
        padding: 0.2rem 0.6rem;
        border-radius: 12px;
        font-size: 0.8rem;
        font-weight: 700;
        box-shadow: 0 2px 8px rgba(212, 175, 55, 0.3);
    }

    .dropdown-item {
        padding: 1rem 1.25rem;
        font-size: 0.9rem;
        color: var(--primary-color);
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
        transition: all 0.3s ease;
        border-bottom: 1px solid var(--border-color);
        text-decoration: none;
    }

    .dropdown-item:last-child {
        border-bottom: none;
    }

    .dropdown-item.unread {
        background: rgba(37, 99, 235, 0.05);
        border-left: 3px solid var(--blue);
    }

    .dropdown-item:hover {
        background: rgba(37, 99, 235, 0.1);
        color: var(--primary-color);
    }

    .notification-icon-wrapper {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
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
        background: rgba(212, 175, 55, 0.1);
        color: var(--gold);
    }

    .notification-icon-wrapper.tour {
        background: rgba(13, 202, 240, 0.1);
        color: #0dcaf0;
    }

    .notification-content {
        flex: 1;
    }

    .notification-actions-dropdown {
        margin-left: 0.5rem;
        display: flex;
        align-items: center;
    }

    .notification-content .notification-message {
        font-size: 0.9rem;
        line-height: 1.4;
        margin-bottom: 0.25rem;
        color: var(--primary-color);
        max-width: 220px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .notification-content .notification-time {
        font-size: 0.75rem;
        color: var(--text-muted);
    }

    .dropdown-footer {
        padding: 0.75rem 1.25rem;
        background: #f8f9fa;
        border-top: 1px solid var(--border-color);
        text-align: center;
    }

    .dropdown-footer .btn-view-all {
        display: block;
        width: 100%;
        padding: 0.5rem;
        background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 100%);
        color: var(--black);
        border-radius: 8px;
        font-weight: 600;
        font-size: 0.85rem;
        text-decoration: none;
        transition: all 0.3s ease;
        box-shadow: 0 2px 8px rgba(212, 175, 55, 0.2);
    }

    .dropdown-footer .btn-view-all:hover {
        background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 100%);
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(212, 175, 55, 0.3);
    }

    .empty-notifications {
        padding: 2rem 1.25rem;
        text-align: center;
        color: var(--text-muted);
    }

    .empty-notifications i {
        font-size: 2.5rem;
        margin-bottom: 0.5rem;
        opacity: 0.3;
    }

    .dropdown-divider {
        margin: 0.5rem 0;
        border-color: var(--border-color);
    }

    .dropdown-item.logout-item {
        color: #dc3545;
    }

    .dropdown-item.logout-item:hover {
        background: rgba(220, 53, 69, 0.1);
        color: #dc3545;
    }

    /* ===== CLEAN PROFILE DROPDOWN ===== */
    .profile-dropdown-menu {
        background: #fff;
        border: none;
        border-radius: 16px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.12), 0 4px 16px rgba(0, 0, 0, 0.08);
        padding: 0;
        margin-top: 0.75rem;
        overflow: hidden;
        min-width: 280px !important;
        max-width: 280px;
        opacity: 0;
        transform: translateY(-6px) scale(0.97);
        transform-origin: top right;
        transition: opacity 0.2s cubic-bezier(0.16, 1, 0.3, 1),
                    transform 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        pointer-events: none;
    }

    .profile-dropdown-menu.show {
        opacity: 1;
        transform: translateY(0) scale(1);
        pointer-events: auto;
    }

    .pdd-header {
        padding: 1.25rem 1.25rem 1rem;
        display: flex;
        align-items: center;
        gap: 0.85rem;
    }

    .pdd-avatar {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid var(--gold);
        flex-shrink: 0;
    }

    .pdd-header-info {
        flex: 1;
        min-width: 0;
    }

    .pdd-name {
        font-weight: 700;
        font-size: 0.95rem;
        color: #111;
        line-height: 1.3;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .pdd-role-badge {
        display: inline-flex;
        align-items: center;
        font-size: 0.6rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        padding: 0.15rem 0.5rem;
        border-radius: 4px;
        background: linear-gradient(135deg, var(--gold-dark), var(--gold));
        color: #111;
        line-height: 1.3;
    }

    .pdd-subtitle {
        font-size: 0.8rem;
        color: #6b7280;
        margin-top: 0.1rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .pdd-divider {
        height: 1px;
        background: #f0f0f0;
        margin: 0;
    }

    .pdd-section {
        padding: 0.5rem 0;
    }

    .pdd-menu-item {
        display: flex;
        align-items: center;
        gap: 0.85rem;
        padding: 0.7rem 1.25rem;
        text-decoration: none;
        color: #1f2937;
        font-size: 0.88rem;
        font-weight: 500;
        transition: background 0.15s ease, padding-left 0.2s ease;
        cursor: pointer;
    }

    .pdd-menu-item:hover {
        background: #f9fafb;
        color: #1f2937;
        padding-left: 1.4rem;
    }

    .pdd-menu-item i {
        font-size: 1.15rem;
        width: 22px;
        text-align: center;
        color: #6b7280;
        flex-shrink: 0;
        transition: color 0.15s ease;
    }

    .pdd-menu-item:hover i {
        color: var(--blue);
    }

    .pdd-menu-item.logout-item {
        color: #dc2626;
    }

    .pdd-menu-item.logout-item i {
        color: #dc2626;
    }

    .pdd-menu-item.logout-item:hover {
        background: rgba(220, 38, 38, 0.04);
    }

    /* Search Bar */
    .navbar-search {
        position: relative;
        max-width: 300px;
        width: 100%;
    }

    .search-input {
        width: 100%;
        padding: 0.5rem 1rem 0.5rem 2.5rem;
        border: 1px solid var(--border-color);
        border-radius: 20px;
        background: #fff;
        font-size: 0.9rem;
        transition: all 0.3s ease;
    }

    .search-input:focus {
        outline: none;
        border-color: var(--blue);
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
    }

    .search-icon {
        position: absolute;
        left: 0.75rem;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-muted);
        font-size: 0.9rem;
    }

    /* Mobile Responsive */
    @media (max-width: 768px) {
        .admin-navbar {
            margin-left: 0;
            padding: 0 1rem;
        }

        .navbar-container {
            padding: 0;
        }

        .sidebar-toggle {
            display: block;
        }

        .page-title {
            font-size: 1.2rem;
        }

        .navbar-search {
            display: none;
        }

        .user-details {
            display: none;
        }

        .navbar-actions {
            gap: 0.5rem;
        }
    }

    /* Theme Indicator */
    .theme-indicator {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: var(--gold);
        display: inline-block;
        margin-right: 0.5rem;
        box-shadow: 0 0 8px rgba(212, 175, 55, 0.5);
    }

    /* Quick Actions */
    .quick-actions {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .quick-action-btn {
        background: none;
        border: 1px solid var(--border-color);
        color: var(--text-muted);
        padding: 0.4rem 0.8rem;
        border-radius: 6px;
        font-size: 0.8rem;
        font-weight: 500;
        transition: all 0.3s ease;
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 0.3rem;
    }

    .quick-action-btn:hover {
        border-color: var(--blue);
        color: var(--blue-light);
        background: rgba(37, 99, 235, 0.1);
    }
</style>

<nav class="admin-navbar">
    <div class="navbar-container">
        <div class="navbar-left">
            <!-- Mobile Sidebar Toggle -->
            <button class="sidebar-toggle" id="sidebarToggle">
                <i class="bi bi-list"></i>
            </button>
            
            <!-- Page Title & Breadcrumb -->
            <div>
                <h1 class="page-title">
                    <span class="theme-indicator"></span>
                    <?php
                    // // Add appropriate icon based on current page
                    // $page_icons = [
                    //     'admin_dashboard.php' => 'fas fa-tachometer-alt',
                    //     'property.php' => 'fas fa-building',
                    //     'view_property.php' => 'fas fa-eye',
                    //     'agent.php' => 'fas fa-user-tie',
                    //     'clients.php' => 'fas fa-users',
                    //     'contracts.php' => 'fas fa-file-contract',
                    //     'reports.php' => 'fas fa-chart-line',
                    //     'settings.php' => 'fas fa-cog'
                    // ];
                    // if (isset($page_icons[$current_page])) {
                    //     echo '<i class="' . $page_icons[$current_page] . '"></i>';
                    // }
                    ?>
                    <?php echo $page_title; ?>
                </h1>
                <div class="breadcrumb-nav">
                    <a href="admin_dashboard.php">Dashboard</a>
                    <?php if ($current_page !== 'admin_dashboard.php'): ?>
                        <span class="mx-1">•</span>
                        <span><?php echo $page_titles[$current_page] ?? 'Current Page'; ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="navbar-right">
    
            <!-- Navbar Actions -->
            <div class="navbar-actions">
                <!-- Notifications -->
                <div class="dropdown">
                    <a href="#" class="nav-icon" title="Notifications" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-bell"></i>
                        <?php if ($unread_notifications > 0): ?>
                            <span class="notification-badge"><?php echo $unread_notifications; ?></span>
                        <?php endif; ?>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end notification-dropdown">
                        <!-- Notification Header -->
                        <div class="notification-dropdown-header">
                            <span><i class="bi bi-bell me-2"></i>Notifications</span>
                            <div class="d-flex align-items-center gap-2">
                                <?php if ($unread_notifications > 0): ?>
                                    <span class="unread-count"><?php echo $unread_notifications; ?> new</span>
                                <?php endif; ?>
                                <?php if (isset($navbar_pending_actions) && $navbar_pending_actions > 0): ?>
                                    <span class="unread-count" style="background: linear-gradient(135deg, #dc3545, #e74c5e);"><?php echo $navbar_pending_actions; ?> action<?php echo $navbar_pending_actions > 1 ? 's' : ''; ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Notification List -->
                        <?php if (empty($recent_notifications)): ?>
                            <div class="empty-notifications">
                                <i class="bi bi-bell-slash"></i>
                                <p class="mb-0">No notifications yet</p>
                            </div>
                        <?php else: ?>
                            <?php 
                            foreach ($recent_notifications as $notif):
                                $is_unread = !isset($notif['is_read']) || (int)$notif['is_read'] === 0;
                                $item_type = $notif['item_type'] ?? 'general';
                                $notif_icon = $notif['icon'] ?? 'bi-bell';
                                $notif_title = $notif['title'] ?? 'Notification';
                                $notif_action_url = $notif['action_url'] ?? 'admin_notifications.php';
                                $notif_priority = $notif['priority'] ?? 'normal';
                                
                                // Icon wrapper class
                                $icon_wrapper_class = $item_type;
                                if (strpos($item_type, 'property_sale') !== false) $icon_wrapper_class = 'sale';
                                elseif (strpos($item_type, 'property') !== false) $icon_wrapper_class = 'property';
                                
                                $time_ago = '';
                                if (!empty($notif['created_at'])) {
                                    $time_diff = time() - strtotime($notif['created_at']);
                                    if ($time_diff < 60) {
                                        $time_ago = 'Just now';
                                    } elseif ($time_diff < 3600) {
                                        $time_ago = floor($time_diff / 60) . 'm ago';
                                    } elseif ($time_diff < 86400) {
                                        $time_ago = floor($time_diff / 3600) . 'h ago';
                                    } elseif ($time_diff < 172800) {
                                        $time_ago = 'Yesterday';
                                    } else {
                                        $time_ago = date('M d', strtotime($notif['created_at']));
                                    }
                                }
                            ?>
                                <a href="<?php echo htmlspecialchars($notif_action_url); ?>" class="dropdown-item <?php echo $is_unread ? 'unread' : ''; ?>" style="text-decoration:none;">
                                    <div class="notification-icon-wrapper <?php echo htmlspecialchars($icon_wrapper_class); ?>">
                                        <i class="bi <?php echo htmlspecialchars($notif_icon); ?>"></i>
                                    </div>
                                    <div class="notification-content">
                                        <div class="notification-message" style="font-weight:<?php echo $is_unread ? '700' : '500'; ?>;" title="<?php echo htmlspecialchars($notif_title . ': ' . ($notif['message'] ?? '')); ?>">
                                            <?php if ($notif_priority === 'urgent' || $notif_priority === 'high'): ?>
                                                <i class="bi bi-exclamation-circle-fill" style="color:#d97706;font-size:0.7rem;margin-right:3px;"></i>
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars($notif_title); ?>
                                        </div>
                                        <div style="font-size:0.78rem;color:#64748b;max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                            <?php echo htmlspecialchars($notif['message'] ?? ''); ?>
                                        </div>
                                        <div class="notification-time">
                                            <i class="bi bi-clock" style="font-size:0.65rem;margin-right:2px;"></i><?php echo $time_ago; ?>
                                        </div>
                                    </div>
                                    <?php if ($is_unread): ?>
                                    <div style="width:8px;height:8px;border-radius:50%;background:var(--blue,#2563eb);flex-shrink:0;margin-left:auto;"></div>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <!-- View All Footer -->
                        <div class="dropdown-footer">
                            <a href="admin_notifications.php" class="btn-view-all">
                                <i class="bi bi-arrow-right me-2"></i>View All Notifications
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- User Dropdown (Profile Card) -->
            <div class="user-dropdown dropdown">
                <a href="#" class="user-info dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <?php
                    $avatar_src = 'https://via.placeholder.com/35/bc9e42/ffffff?text=' . strtoupper(substr($first_name, 0, 1));
                    if (!empty($admin_profile['profile_picture_url'])) {
                        $avatar_src = htmlspecialchars($admin_profile['profile_picture_url']);
                    }
                    ?>
                    <img src="<?php echo $avatar_src; ?>" alt="User Avatar" class="user-avatar">
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($first_name . ' ' . ($last_name ?? '')); ?></div>
                        <div class="user-role"><?php echo htmlspecialchars($user_role); ?></div>
                    </div>
                </a>
                <div class="dropdown-menu dropdown-menu-end profile-dropdown-menu">
                    <!-- Header -->
                    <div class="pdd-header">
                        <img src="<?php echo $avatar_src; ?>" alt="avatar" class="pdd-avatar">
                        <div class="pdd-header-info">
                            <div class="pdd-name">
                                <?php echo htmlspecialchars($first_name . ' ' . ($last_name ?? '')); ?>
                                <span class="pdd-role-badge"><?php echo htmlspecialchars(ucfirst($user_role)); ?></span>
                            </div>
                            <div class="pdd-subtitle"><?php echo htmlspecialchars($user_email); ?></div>
                        </div>
                    </div>

                    <div class="pdd-divider"></div>

                    <!-- Navigation -->
                    <div class="pdd-section">
                        <a href="admin_profile.php" class="pdd-menu-item">
                            <i class="bi bi-person"></i> View Profile
                        </a>
                        <a href="admin_settings.php" class="pdd-menu-item">
                            <i class="bi bi-gear"></i> Account Settings
                        </a>
                    </div>

                    <div class="pdd-divider"></div>

                    <!-- Logout -->
                    <div class="pdd-section">
                        <a href="#" class="pdd-menu-item logout-item" data-bs-toggle="modal" data-bs-target="#logoutModal">
                            <i class="bi bi-box-arrow-right"></i> Log Out
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</nav>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Global search functionality
        const globalSearch = document.getElementById('globalSearch');
        if (globalSearch) {
            globalSearch.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    const searchTerm = this.value.trim();
                    if (searchTerm) {
                        // Redirect to search page with query
                        window.location.href = `search_results.php?q=${encodeURIComponent(searchTerm)}`;
                    }
                }
            });

            // Add search suggestions functionality
            let searchTimeout;
            globalSearch.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                const query = this.value.trim();
                
                if (query.length >= 2) {
                    searchTimeout = setTimeout(() => {
                        // Add search suggestions logic here
                        console.log('Searching for:', query);
                    }, 300);
                }
            });
        }

        // Notification dropdown auto-close
        const notificationDropdowns = document.querySelectorAll('.nav-icon[data-bs-toggle="dropdown"]');
        notificationDropdowns.forEach(dropdown => {
            dropdown.addEventListener('click', function(e) {
                e.preventDefault();
                // Add notification loading logic here
            });
        });

        // Auto-hide navbar on scroll (optional)
        let lastScrollTop = 0;
        const navbar = document.querySelector('.admin-navbar');
        
        window.addEventListener('scroll', function() {
            const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
            
            if (scrollTop > lastScrollTop && scrollTop > 100) {
                // Scrolling down
                navbar.style.transform = 'translateY(-100%)';
            } else {
                // Scrolling up
                navbar.style.transform = 'translateY(0)';
            }
            
            lastScrollTop = scrollTop <= 0 ? 0 : scrollTop;
        });

        // Highlight current page in any dynamic navigation
        const currentPage = '<?php echo $current_page; ?>';
        const navLinks = document.querySelectorAll('.nav-link, .dropdown-item');
        
        navLinks.forEach(link => {
            if (link.getAttribute('href') && link.getAttribute('href').includes(currentPage)) {
                link.classList.add('active');
            }
        });
    });

    // Function to update user status or other real-time elements
    function updateUserStatus() {
        // This could update user online status, last activity, etc.
        const userRole = document.querySelector('.user-role');
        if (userRole) {
            const now = new Date();
            userRole.title = `Last active: ${now.toLocaleTimeString()}`;
        }
    }

    // Update every minute
    setInterval(updateUserStatus, 60000);

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl/Cmd + K for search
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            const searchInput = document.getElementById('globalSearch');
            if (searchInput) {
                searchInput.focus();
            }
        }
        
        // Ctrl/Cmd + D for dashboard
        if ((e.ctrlKey || e.metaKey) && e.key === 'd') {
            e.preventDefault();
            window.location.href = 'admin_dashboard.php';
        }
    });
</script>