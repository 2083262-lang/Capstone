<?php
// admin_navbar.php
// Ensure session is started and user info is available
include __DIR__ . '/logout_modal.php';
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
            $base = defined('BASE_URL') ? BASE_URL : '';
            switch ($notif['item_type']) {
                case 'agent':        $notif['action_url'] = $base . 'review_agent_details.php?id=' . ($notif['item_id'] ?? 0); break;
                case 'tour':         $notif['action_url'] = $base . 'admin_tour_request_details.php?id=' . ($notif['item_id'] ?? 0); break;
                case 'property':     $notif['action_url'] = $base . 'view_property.php?id=' . ($notif['item_id'] ?? 0); break;
                case 'property_sale':$notif['action_url'] = $base . 'admin_property_sale_approvals.php'; break;
                default:             $notif['action_url'] = $base . 'admin_notifications.php'; break;
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
<link rel="stylesheet" href="<?= ASSETS_CSS ?>bootstrap-icons.min.css">
<!-- Standardized Admin Layout CSS -->
<link rel="stylesheet" href="<?= BASE_URL ?>css/admin_layout.css">

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
        display: none;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        animation: pulse 2s infinite;
    }

    .notification-badge.has-unread {
        display: flex;
    }

    @keyframes pulse {
        0% { transform: scale(1); opacity: 1; }
        50% { transform: scale(1.1); opacity: 0.8; }
        100% { transform: scale(1); opacity: 1; }
    }

    /* ===== Admin Notification Dropdown Panel (White Theme) ===== */
    .admin-notif-dropdown-panel {
        position: absolute;
        top: calc(100% + 10px);
        right: 0;
        width: 400px;
        max-height: 520px;
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        box-shadow: 0 16px 48px rgba(0,0,0,0.12), 0 4px 16px rgba(0,0,0,0.06);
        z-index: 2000;
        display: none;
        flex-direction: column;
        overflow: hidden;
    }

    .admin-notif-dropdown-panel.show {
        display: flex;
    }

    .admin-notif-dropdown-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 14px 18px;
        border-bottom: 1px solid #f0f0f0;
        background: #fafafa;
    }

    .admin-notif-dropdown-header h6 {
        margin: 0;
        font-size: 0.95rem;
        font-weight: 700;
        color: #1f2937;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .admin-notif-dropdown-header h6 i {
        color: var(--gold);
    }

    .admin-notif-unread-indicator {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 20px;
        height: 20px;
        padding: 0 6px;
        background: #dc3545;
        color: #fff;
        font-size: 0.7rem;
        font-weight: 700;
        border-radius: 10px;
        line-height: 1;
    }

    .admin-notif-mark-all-btn {
        background: none;
        border: none;
        color: var(--blue);
        font-size: 0.78rem;
        font-weight: 600;
        cursor: pointer;
        padding: 4px 8px;
        border-radius: 6px;
        transition: all 0.2s ease;
    }

    .admin-notif-mark-all-btn:hover {
        background: rgba(37, 99, 235, 0.08);
        color: var(--blue-light);
    }

    .admin-notif-dropdown-body {
        flex: 1;
        overflow-y: auto;
    }

    .admin-notif-item {
        display: flex;
        gap: 12px;
        padding: 12px 18px;
        border-bottom: 1px solid #f3f4f6;
        cursor: pointer;
        transition: background 0.15s ease;
        align-items: flex-start;
        text-decoration: none;
        color: inherit;
    }

    .admin-notif-item:hover {
        background: #f8fafc;
        color: inherit;
    }

    .admin-notif-item.unread {
        background: rgba(37, 99, 235, 0.03);
        border-left: 3px solid var(--blue);
    }

    .admin-notif-item.unread:hover {
        background: rgba(37, 99, 235, 0.06);
    }

    .admin-notif-item-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.15rem;
        flex-shrink: 0;
    }

    .admin-notif-item-icon.tour       { background: rgba(13, 202, 240, 0.1); color: #0891b2; }
    .admin-notif-item-icon.property    { background: rgba(37, 99, 235, 0.1); color: #2563eb; }
    .admin-notif-item-icon.agent       { background: rgba(34, 197, 94, 0.1); color: #16a34a; }
    .admin-notif-item-icon.sale        { background: rgba(212, 175, 55, 0.1); color: #b8941f; }
    .admin-notif-item-icon.property_sale { background: rgba(212, 175, 55, 0.1); color: #b8941f; }
    .admin-notif-item-icon.general     { background: rgba(100, 116, 139, 0.1); color: #64748b; }

    .admin-notif-item-body {
        flex: 1;
        min-width: 0;
    }

    .admin-notif-item-title {
        font-size: 0.85rem;
        font-weight: 600;
        color: #374151;
        margin-bottom: 2px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .admin-notif-item.unread .admin-notif-item-title { 
        color: #111827;
        font-weight: 700;
    }

    .admin-notif-item-msg {
        font-size: 0.78rem;
        color: #6b7280;
        line-height: 1.35;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .admin-notif-item-time {
        font-size: 0.7rem;
        color: #9ca3af;
        margin-top: 3px;
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .admin-notif-item-dot {
        width: 7px;
        height: 7px;
        background: var(--blue);
        border-radius: 50%;
        flex-shrink: 0;
        margin-top: 4px;
        box-shadow: 0 0 4px rgba(37, 99, 235, 0.4);
    }

    .admin-notif-item-priority {
        color: #d97706;
        font-size: 0.7rem;
        margin-right: 3px;
    }

    .admin-notif-dropdown-footer {
        padding: 10px 18px;
        border-top: 1px solid #f0f0f0;
        text-align: center;
        background: #fafafa;
    }

    .admin-notif-dropdown-footer a {
        color: var(--blue);
        font-size: 0.82rem;
        font-weight: 600;
        text-decoration: none;
        transition: color 0.2s ease;
    }

    .admin-notif-dropdown-footer a:hover {
        color: var(--blue-light);
    }

    .admin-notif-empty {
        padding: 40px 20px;
        text-align: center;
        color: #9ca3af;
    }

    .admin-notif-empty i {
        font-size: 2.5rem;
        color: #d1d5db;
        margin-bottom: 10px;
        display: block;
    }

    .admin-notif-empty p {
        font-size: 0.85rem;
        margin: 0;
    }

    @media (max-width: 480px) {
        .admin-notif-dropdown-panel {
            width: calc(100vw - 24px);
            right: -60px;
        }
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

    /* Tablet and below — show sidebar toggle, remove navbar left margin */
    @media (max-width: 1200px) {
        .admin-navbar {
            margin-left: 0;
        }

        .sidebar-toggle {
            display: block;
        }
    }

    /* Mobile Responsive */
    @media (max-width: 768px) {
        .admin-navbar {
            padding: 0 1rem;
        }

        .navbar-container {
            padding: 0;
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

        .navbar-site-name .site-logo {
            height: 28px;
            width: 28px;
        }

        .navbar-site-name .site-brand {
            font-size: 1rem;
        }

        .navbar-site-name .site-tagline {
            display: none;
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

    .navbar-site-name {
        display: flex;
        align-items: center;
        gap: 0.65rem;
        line-height: 1;
        user-select: none;
        text-decoration: none;
    }

    .navbar-site-name .site-logo {
        height: 36px;
        width: 36px;
        object-fit: contain;
        filter: brightness(1.1) saturate(1.1);
        flex-shrink: 0;
    }

    .navbar-site-name .site-text {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .navbar-site-name .site-brand {
        font-size: 1.2rem;
        font-weight: 800;
        letter-spacing: 0.3px;
        background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 50%, var(--gold) 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        filter: drop-shadow(0 0 10px rgba(212, 175, 55, 0.25));
    }

    .navbar-site-name .site-tagline {
        font-size: 0.62rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 1.8px;
        color: var(--gray-400);
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

            <!-- Site Name -->
            <span class="navbar-site-name">
                <img src="<?= BASE_URL ?>images/Logo.png" alt="HomeEstate Realty" class="site-logo">
                <span class="site-text">
                    <span class="site-brand">HomeEstate Realty</span>
                    <span class="site-tagline">Admin Panel</span>
                </span>
            </span>
        </div>

        <div class="navbar-right">
    
            <!-- Navbar Actions -->
            <div class="navbar-actions">
                <!-- Notifications (Custom Panel Dropdown) -->
                <div class="position-relative" id="adminNotifDropdownWrapper">
                    <button class="nav-icon" type="button" title="Notifications" id="adminNotifToggleBtn">
                        <i class="bi bi-bell"></i>
                        <span class="notification-badge <?php echo $unread_notifications > 0 ? 'has-unread' : ''; ?>" id="adminNotifBadge"><?php echo $unread_notifications > 0 ? ($unread_notifications > 99 ? '99+' : $unread_notifications) : ''; ?></span>
                    </button>
                    <div class="admin-notif-dropdown-panel" id="adminNotifDropdownPanel">
                        <div class="admin-notif-dropdown-header">
                            <h6>
                                <i class="bi bi-bell"></i>Notifications
                                <?php if ($unread_notifications > 0): ?>
                                    <span class="admin-notif-unread-indicator"><?php echo $unread_notifications; ?></span>
                                <?php endif; ?>
                            </h6>
                            <button class="admin-notif-mark-all-btn" id="adminNotifMarkAllBtn" title="Mark all as read">
                                <i class="bi bi-check2-all me-1"></i>Mark all read
                            </button>
                        </div>
                        <div class="admin-notif-dropdown-body" id="adminNotifDropdownBody">
                            <?php if (empty($recent_notifications)): ?>
                                <div class="admin-notif-empty">
                                    <i class="bi bi-bell-slash"></i>
                                    <p>No notifications yet</p>
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
                                    <a href="<?php echo htmlspecialchars($notif_action_url); ?>" class="admin-notif-item <?php echo $is_unread ? 'unread' : ''; ?>">
                                        <div class="admin-notif-item-icon <?php echo htmlspecialchars($icon_wrapper_class); ?>">
                                            <i class="bi <?php echo htmlspecialchars($notif_icon); ?>"></i>
                                        </div>
                                        <?php if ($is_unread): ?>
                                            <div class="admin-notif-item-dot"></div>
                                        <?php endif; ?>
                                        <div class="admin-notif-item-body">
                                            <div class="admin-notif-item-title">
                                                <?php if ($notif_priority === 'urgent' || $notif_priority === 'high'): ?>
                                                    <i class="bi bi-exclamation-circle-fill admin-notif-item-priority"></i>
                                                <?php endif; ?>
                                                <?php echo htmlspecialchars($notif_title); ?>
                                            </div>
                                            <div class="admin-notif-item-msg"><?php echo htmlspecialchars($notif['message'] ?? ''); ?></div>
                                            <div class="admin-notif-item-time"><i class="bi bi-clock"></i><?php echo $time_ago; ?></div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="admin-notif-dropdown-footer">
                            <a href="<?= BASE_URL ?>admin_notifications.php">View All Notifications <i class="bi bi-arrow-right ms-1"></i></a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- User Dropdown (Profile Card) -->
            <div class="user-dropdown dropdown">
                <a href="#" class="user-info dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <?php
                    $avatar_src = BASE_URL . 'images/placeholder-avatar.svg';
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
                        <a href="<?= BASE_URL ?>admin_profile.php" class="pdd-menu-item">
                            <i class="bi bi-person"></i> View Profile
                        </a>
                        <a href="<?= BASE_URL ?>admin_settings.php" class="pdd-menu-item">
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
        // ===== NOTIFICATION DROPDOWN PANEL LOGIC =====
        const adminNotifToggle = document.getElementById('adminNotifToggleBtn');
        const adminNotifPanel = document.getElementById('adminNotifDropdownPanel');
        const adminNotifBadge = document.getElementById('adminNotifBadge');
        const adminNotifWrapper = document.getElementById('adminNotifDropdownWrapper');
        const adminNotifMarkAllBtn = document.getElementById('adminNotifMarkAllBtn');
        let adminNotifOpen = false;

        // Toggle dropdown
        if (adminNotifToggle) {
            adminNotifToggle.addEventListener('click', function(e) {
                e.stopPropagation();
                adminNotifOpen = !adminNotifOpen;
                adminNotifPanel.classList.toggle('show', adminNotifOpen);
            });
        }

        // Close on outside click
        document.addEventListener('click', function(e) {
            if (adminNotifOpen && adminNotifWrapper && !adminNotifWrapper.contains(e.target)) {
                adminNotifOpen = false;
                adminNotifPanel.classList.remove('show');
            }
        });

        // Mark all read
        if (adminNotifMarkAllBtn) {
            adminNotifMarkAllBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                fetch('<?= BASE_URL ?>admin_notifications.php?mark_all_read=1', { method: 'GET' })
                    .then(() => {
                        // Remove unread styling from all items
                        document.querySelectorAll('.admin-notif-item.unread').forEach(item => {
                            item.classList.remove('unread');
                        });
                        // Remove dots
                        document.querySelectorAll('.admin-notif-item-dot').forEach(dot => dot.remove());
                        // Reset badge
                        if (adminNotifBadge) {
                            adminNotifBadge.textContent = '';
                            adminNotifBadge.classList.remove('has-unread');
                        }
                        // Remove unread indicator in header
                        const indicator = document.querySelector('.admin-notif-unread-indicator');
                        if (indicator) indicator.remove();
                    })
                    .catch(() => {});
            });
        }

        // Global search functionality
        const globalSearch = document.getElementById('globalSearch');
        if (globalSearch) {
            globalSearch.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    const searchTerm = this.value.trim();
                    if (searchTerm) {
                        window.location.href = `search_results.php?q=${encodeURIComponent(searchTerm)}`;
                    }
                }
            });

            let searchTimeout;
            globalSearch.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                const query = this.value.trim();
                
                if (query.length >= 2) {
                    searchTimeout = setTimeout(() => {
                        console.log('Searching for:', query);
                    }, 300);
                }
            });
        }

        // Highlight current page in any dynamic navigation
        const currentPage = '<?php echo $current_page; ?>';
        const navLinks = document.querySelectorAll('.nav-link');
        
        navLinks.forEach(link => {
            if (link.getAttribute('href') && link.getAttribute('href').includes(currentPage)) {
                link.classList.add('active');
            }
        });

        // Profile dropdown toggle (fallback to ensure dropdown opens)
        const userToggle = document.querySelector('.user-info.dropdown-toggle');
        const profileMenu = document.querySelector('.profile-dropdown-menu');
        const userDropdownWrapper = document.querySelector('.user-dropdown');
        let profileOpen = false;
        if (userToggle && profileMenu) {
            userToggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                profileOpen = !profileOpen;
                profileMenu.classList.toggle('show', profileOpen);
                userToggle.setAttribute('aria-expanded', profileOpen);
            });

            // Close profile dropdown on outside click
            document.addEventListener('click', function(e) {
                if (profileOpen && userDropdownWrapper && !userDropdownWrapper.contains(e.target)) {
                    profileOpen = false;
                    profileMenu.classList.remove('show');
                    userToggle.setAttribute('aria-expanded', 'false');
                }
            });
        }
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