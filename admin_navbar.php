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
    $stmt = $conn->prepare("SELECT a.first_name, a.last_name, a.email, a.date_registered, ai.license_number, ai.specialization, ai.years_experience, ai.profile_picture_url FROM accounts a LEFT JOIN admin_information ai ON a.account_id = ai.account_id WHERE a.account_id = ? LIMIT 1");
    $stmt->bind_param("i", $account_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $first_name = $row['first_name'] ?? 'Admin';
        $last_name = $row['last_name'] ?? '';
        $user_email = $row['email'] ?? '';
        $date_registered = $row['date_registered'] ?? '';
        if (!empty($row['license_number']) || !empty($row['specialization']) || !empty($row['years_experience']) || !empty($row['profile_picture_url'])) {
            $admin_profile = [
                'license_number' => $row['license_number'] ?? null,
                'specialization' => $row['specialization'] ?? null,
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
if (isset($conn)) {
    // Count unread
    $notif_stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE is_read = 0 OR is_read IS NULL");
    $notif_stmt->execute();
    $notif_result = $notif_stmt->get_result();
    if ($notif_row = $notif_result->fetch_assoc()) {
        $unread_notifications = $notif_row['count'];
    }
    $notif_stmt->close();
    
    // Get recent 5 notifications
    $recent_stmt = $conn->prepare("SELECT notification_id, item_type, message, created_at, is_read FROM notifications ORDER BY created_at DESC LIMIT 5");
    $recent_stmt->execute();
    $recent_result = $recent_stmt->get_result();
    while ($notif = $recent_result->fetch_assoc()) {
        $recent_notifications[] = $notif;
    }
    $recent_stmt->close();
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

    /* Enhanced Profile Dropdown Styles */
    .profile-dropdown-menu {
        background: #fff;
        border: none;
        border-radius: 20px;
        box-shadow: 0 15px 50px rgba(0, 0, 0, 0.2);
        padding: 0;
        margin-top: 1rem;
        overflow: hidden;
        animation: dropdownSlideIn 0.3s ease-out;
    }

    @keyframes dropdownSlideIn {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .profile-dropdown-header {
        background: linear-gradient(135deg, #161209 0%, #2c241a 100%);
        padding: 1.5rem;
        position: relative;
        overflow: hidden;
    }

    .profile-dropdown-header::before {
        content: '';
        position: absolute;
        top: -50px;
        right: -50px;
        width: 150px;
        height: 150px;
        background: radial-gradient(circle, rgba(212, 175, 55, 0.2) 0%, transparent 70%);
        border-radius: 50%;
    }

    .profile-dropdown-header .profile-avatar-large {
        width: 64px;
        height: 64px;
        border-radius: 50%;
        object-fit: cover;
        border: 3px solid var(--gold);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3), 0 0 16px rgba(212, 175, 55, 0.4);
    }

    .profile-dropdown-header .profile-name {
        color: #fff;
        font-size: 1.1rem;
        font-weight: 700;
        margin: 0;
    }

    .profile-dropdown-header .profile-email {
        color: rgba(255, 255, 255, 0.8);
        font-size: 0.85rem;
        margin: 0;
    }

    .profile-dropdown-body {
        padding: 1.25rem;
        background: #fafbfc;
    }

    .profile-info-section {
        background: #fff;
        border-radius: 12px;
        padding: 1rem;
        margin-bottom: 1rem;
        border: 1px solid #e9ecef;
    }

    .profile-info-label {
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #6c757d;
        margin-bottom: 0.25rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .profile-info-label i {
        color: var(--gold);
        font-size: 0.85rem;
    }

    .profile-info-value {
        font-size: 0.95rem;
        font-weight: 600;
        color: #161209;
    }

    .profile-stats {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 0.75rem;
        margin-bottom: 1rem;
    }

    .profile-stat-item {
        background: #fff;
        border-radius: 10px;
        padding: 0.75rem;
        text-align: center;
        border: 1px solid #e9ecef;
        transition: all 0.3s ease;
    }

    .profile-stat-item:hover {
        border-color: var(--gold);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(212, 175, 55, 0.25);
    }

    .profile-stat-label {
        font-size: 0.7rem;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 0.25rem;
    }

    .profile-stat-value {
        font-size: 1.1rem;
        font-weight: 700;
        color: #161209;
    }

    .admin-profile-details {
        background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
        border-radius: 12px;
        padding: 1rem;
        margin-bottom: 1rem;
        border-left: 4px solid var(--gold);
    }

    .admin-profile-title {
        font-size: 0.8rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #161209;
        margin-bottom: 0.75rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .admin-profile-title i {
        color: var(--gold);
    }

    .admin-profile-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.5rem 0;
        border-bottom: 1px solid #e9ecef;
    }

    .admin-profile-item:last-child {
        border-bottom: none;
    }

    .admin-profile-item-label {
        font-size: 0.85rem;
        color: #6c757d;
        font-weight: 500;
    }

    .admin-profile-item-value {
        font-size: 0.9rem;
        font-weight: 600;
        color: #161209;
    }

    .profile-dropdown-footer {
        padding: 1rem 1.25rem;
        background: #fff;
        border-top: 1px solid #e9ecef;
    }

    .profile-action-btn {
        border-radius: 10px;
        font-weight: 600;
        font-size: 0.85rem;
        padding: 0.65rem 1rem;
        transition: all 0.3s ease;
        border: none;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
    }

    .profile-action-btn.btn-profile {
        background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 100%);
        color: var(--black);
        box-shadow: 0 4px 12px rgba(212, 175, 55, 0.3);
        font-weight: 700;
    }

    .profile-action-btn.btn-profile:hover {
        background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(212, 175, 55, 0.4);
    }

    .profile-action-btn.btn-logout {
        background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        color: #fff;
        box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
    }

    .profile-action-btn.btn-logout:hover {
        background: linear-gradient(135deg, #c82333 0%, #dc3545 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(220, 53, 69, 0.4);
    }

    .member-since-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        background: rgba(212, 175, 55, 0.1);
        color: var(--gold);
        padding: 0.4rem 0.8rem;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
    }

    .member-since-badge i {
        font-size: 0.8rem;
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
                            <?php if ($unread_notifications > 0): ?>
                                <span class="unread-count"><?php echo $unread_notifications; ?> new</span>
                            <?php endif; ?>
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
                                
                                // Determine icon based on item type
                                $icon_class = 'bi bi-bell';
                                $icon_wrapper_class = 'tour';
                                if (strpos($item_type, 'property') !== false) {
                                    $icon_class = 'bi bi-house';
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
                                
                                $time_ago = '';
                                if (!empty($notif['created_at'])) {
                                    $time_diff = time() - strtotime($notif['created_at']);
                                    if ($time_diff < 60) {
                                        $time_ago = 'Just now';
                                    } elseif ($time_diff < 3600) {
                                        $time_ago = floor($time_diff / 60) . ' minutes ago';
                                    } elseif ($time_diff < 86400) {
                                        $time_ago = floor($time_diff / 3600) . ' hours ago';
                                    } else {
                                        $time_ago = date('M d, Y', strtotime($notif['created_at']));
                                    }
                                }
                            ?>
                                <div class="dropdown-item <?php echo $is_unread ? 'unread' : ''; ?>">
                                    <div class="notification-icon-wrapper <?php echo $icon_wrapper_class; ?>">
                                        <i class="<?php echo $icon_class; ?>"></i>
                                    </div>
                                    <div class="notification-content">
                                        <div class="notification-message" title="<?php echo htmlspecialchars($notif['message'] ?? 'New notification'); ?>"><?php echo htmlspecialchars($notif['message'] ?? 'New notification'); ?></div>
                                        <div class="notification-time"><?php echo $time_ago; ?></div>
                                    </div>
                                    <div class="notification-actions-dropdown">
                                        <a href="admin_notifications.php?view_id=<?php echo urlencode($notif['notification_id']); ?>" class="btn btn-sm btn-outline-primary">View</a>
                                    </div>
                                </div>
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
                <div class="dropdown-menu dropdown-menu-end profile-dropdown-menu" style="min-width: 350px;">
                    <!-- Profile Header -->
                    <div class="profile-dropdown-header">
                        <div class="d-flex align-items-center gap-3">
                            <img src="<?php echo $avatar_src; ?>" alt="avatar" class="profile-avatar-large">
                            <div class="flex-grow-1">
                                <div class="profile-name"><?php echo htmlspecialchars($first_name . ' ' . ($last_name ?? '')); ?></div>
                                <div class="profile-email"><?php echo htmlspecialchars($user_email); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Profile Body -->
                    <div class="profile-dropdown-body">
                        <!-- Role, Member Since & License (if available) -->
                        <div class="profile-stats">
                            <div class="profile-stat-item">
                                <div class="profile-stat-label">Role</div>
                                <div class="profile-stat-value"><?php echo htmlspecialchars(ucfirst($user_role)); ?></div>
                            </div>
                            <div class="profile-stat-item">
                                <div class="profile-stat-label">Member Since</div>
                                <div class="profile-stat-value" style="font-size: 0.85rem;">
                                    <?php echo !empty($date_registered) ? date('M Y', strtotime($date_registered)) : 'N/A'; ?>
                                </div>
                            </div>
                            <div class="profile-stat-item">
                                <div class="profile-stat-label">License</div>
                                <div class="profile-stat-value" style="font-size: 0.95rem;">
                                    <?php echo (!empty($admin_profile['license_number'])) ? htmlspecialchars($admin_profile['license_number']) : 'N/A'; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Profile Footer -->
                    <div class="profile-dropdown-footer">
                        <div class="d-flex gap-2">
                            <a class="btn profile-action-btn btn-profile w-100" href="profile.php">
                                <i class="bi bi-person"></i>
                                My Profile
                            </a>
                            <a class="btn profile-action-btn btn-logout w-100" href="#" data-bs-toggle="modal" data-bs-target="#logoutModal">
                                <i class="bi bi-box-arrow-right"></i>
                                Logout
                            </a>
                        </div>
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