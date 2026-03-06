<?php
// Don't start session if already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set agent variables if not already set
if (!isset($agent_username) && isset($_SESSION['account_id'])) {
    include_once '../connection.php';
    
    // Fetch comprehensive agent info including profile picture
    $sql_agent_info = "SELECT a.first_name, a.last_name, a.username, ai.profile_picture_url 
                       FROM accounts a
                       LEFT JOIN agent_information ai ON a.account_id = ai.account_id
                       WHERE a.account_id = ?";
    $stmt_agent_info = $conn->prepare($sql_agent_info);
    $stmt_agent_info->bind_param("i", $_SESSION['account_id']);
    $stmt_agent_info->execute();
    $result_agent_info = $stmt_agent_info->get_result();
    $navbar_agent_info = $result_agent_info->fetch_assoc();
    $stmt_agent_info->close();
    
    $agent_username = $navbar_agent_info['username'] ?? 'Agent';
    if (!isset($agent_info)) {
        $agent_info = $navbar_agent_info;
    }
} elseif (!isset($agent_info) && isset($_SESSION['account_id'])) {
    // If agent_info is not set but agent_username is, fetch it
    include_once '../connection.php';
    $sql_profile = "SELECT ai.profile_picture_url FROM agent_information ai WHERE ai.account_id = ?";
    $stmt_profile = $conn->prepare($sql_profile);
    $stmt_profile->bind_param("i", $_SESSION['account_id']);
    $stmt_profile->execute();
    $result_profile = $stmt_profile->get_result();
    $agent_info = $result_profile->fetch_assoc();
    $stmt_profile->close();
}

// Build a robust profile image URL
$raw_profile = isset($agent_info['profile_picture_url']) ? $agent_info['profile_picture_url'] : '';
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

// Determine the display name for the agent
$navbar_display_name = '';
if (isset($agent_info['first_name']) && !empty($agent_info['first_name'])) {
    $navbar_display_name = $agent_info['first_name'];
    if (isset($agent_info['last_name']) && !empty($agent_info['last_name'])) {
        $navbar_display_name .= ' ' . substr($agent_info['last_name'], 0, 1) . '.';
    }
} else {
    $navbar_display_name = $agent_username ?? 'Agent';
}
?>

<style>
    /* ===== AGENT NAVBAR - Dark Theme (Gold, Black, Blue) ===== */
    .agent-navbar {
        background: linear-gradient(180deg, #0f0f0f 0%, #111111 100%) !important;
        box-shadow: 0 2px 20px rgba(0,0,0,0.6), 0 1px 0 rgba(212, 175, 55, 0.1);
        padding: 0.75rem 0;
        border-bottom: 1px solid rgba(212, 175, 55, 0.15);
        transition: padding 0.3s ease, box-shadow 0.3s ease, border-color 0.3s ease;
        will-change: padding;
        position: sticky;
        top: 0;
        z-index: 1050;
    }

    .agent-navbar.scrolled {
        padding: 0.5rem 0;
        box-shadow: 0 4px 24px rgba(0,0,0,0.8), 0 1px 0 rgba(37, 99, 235, 0.2);
        border-bottom: 1px solid rgba(37, 99, 235, 0.3);
    }

    /* Brand / Logo */
    .agent-navbar .navbar-brand {
        font-weight: 800;
        font-size: 1.5rem;
        color: #d4af37 !important;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        transition: all 0.3s ease;
        padding: 0;
        margin: 0;
    }

    .agent-navbar .navbar-brand:hover {
        transform: translateY(-1px);
        filter: drop-shadow(0 0 8px rgba(212, 175, 55, 0.3));
    }

    .agent-navbar .navbar-logo {
        height: 45px;
        width: auto;
        object-fit: contain;
        transition: transform 0.3s ease;
        filter: brightness(1.1) saturate(1.2);
    }

    .agent-navbar .navbar-brand:hover .navbar-logo {
        transform: scale(1.03);
    }

    .agent-navbar .brand-text {
        display: flex;
        flex-direction: column;
        line-height: 1.2;
    }

    .agent-navbar .brand-name {
        font-size: 1.3rem;
        font-weight: 800;
        background: linear-gradient(135deg, #d4af37 0%, #f4d03f 50%, #d4af37 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .agent-navbar .brand-tagline {
        font-size: 0.65rem;
        font-weight: 600;
        color: #7a8a99;
        letter-spacing: 1.5px;
        text-transform: uppercase;
    }

    /* Center Navigation Links */
    .agent-navbar .navbar-center {
        position: absolute;
        left: 50%;
        transform: translateX(-50%);
        display: flex;
        gap: 0.25rem;
        list-style: none;
        margin: 0;
        padding: 0;
    }

    .agent-navbar .navbar-center .nav-link {
        font-weight: 600;
        color: #b8bec4 !important;
        padding: 0.65rem 1.25rem !important;
        border-radius: 2px;
        transition: all 0.3s ease;
        position: relative;
        font-size: 0.95rem;
        white-space: nowrap;
    }

    .agent-navbar .navbar-center .nav-link::after {
        content: '';
        position: absolute;
        bottom: 6px;
        left: 50%;
        transform: translateX(-50%);
        width: 0;
        height: 2px;
        background: linear-gradient(90deg, #2563eb 0%, #3b82f6 100%);
        transition: width 0.3s ease;
    }

    .agent-navbar .navbar-center .nav-link:hover::after {
        width: 30px;
    }

    .agent-navbar .navbar-center .nav-link.active::after {
        width: 30px;
        background: linear-gradient(90deg, #d4af37 0%, #f4d03f 100%);
    }

    .agent-navbar .navbar-center .nav-link:hover {
        color: #ffffff !important;
        background-color: rgba(37, 99, 235, 0.08);
    }

    .agent-navbar .navbar-center .nav-link.active {
        color: #d4af37 !important;
        background-color: rgba(212, 175, 55, 0.05);
    }

    /* Right Side Actions */
    .agent-navbar .navbar-actions {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-left: auto;
    }

    /* Notification Button */
    .agent-navbar .notification-btn {
        position: relative;
        width: 38px;
        height: 38px;
        border-radius: 2px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #b8bec4;
        background: rgba(37, 99, 235, 0.08);
        border: 1px solid rgba(37, 99, 235, 0.15);
        transition: all 0.3s ease;
        cursor: pointer;
        font-size: 0.95rem;
    }

    .agent-navbar .notification-btn:hover {
        background: rgba(37, 99, 235, 0.15);
        border-color: rgba(37, 99, 235, 0.3);
        color: #3b82f6;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.15);
    }

    .agent-navbar .notification-badge {
        position: absolute;
        top: 2px;
        right: 2px;
        min-width: 18px;
        height: 18px;
        background: #ef4444;
        border-radius: 9px;
        border: 2px solid #111111;
        box-shadow: 0 0 6px rgba(239, 68, 68, 0.5);
        font-size: 0.65rem;
        font-weight: 700;
        color: #fff;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 0 4px;
        line-height: 1;
    }

    .agent-navbar .notification-badge.has-unread {
        display: flex;
    }

    /* ===== Notification Dropdown Panel ===== */
    .notif-dropdown-panel {
        position: absolute;
        top: calc(100% + 10px);
        right: 0;
        width: 400px;
        max-height: 520px;
        background: linear-gradient(180deg, #141414 0%, #111111 100%);
        border: 1px solid rgba(37, 99, 235, 0.2);
        border-radius: 6px;
        box-shadow: 0 16px 48px rgba(0,0,0,0.7), 0 0 0 1px rgba(37, 99, 235, 0.1);
        z-index: 2000;
        display: none;
        flex-direction: column;
        overflow: hidden;
    }

    .notif-dropdown-panel.show {
        display: flex;
    }

    .notif-dropdown-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 14px 18px;
        border-bottom: 1px solid rgba(212, 175, 55, 0.15);
    }

    .notif-dropdown-header h6 {
        margin: 0;
        font-size: 0.95rem;
        font-weight: 700;
        color: #d4af37;
    }

    .notif-mark-all-btn {
        background: none;
        border: none;
        color: #3b82f6;
        font-size: 0.78rem;
        font-weight: 600;
        cursor: pointer;
        padding: 4px 8px;
        border-radius: 4px;
        transition: all 0.2s ease;
    }

    .notif-mark-all-btn:hover {
        background: rgba(37, 99, 235, 0.1);
        color: #60a5fa;
    }

    .notif-dropdown-body {
        flex: 1;
        overflow-y: auto;
        scrollbar-width: thin;
        scrollbar-color: rgba(212,175,55,0.3) transparent;
    }

    .notif-dropdown-body::-webkit-scrollbar { width: 5px; }
    .notif-dropdown-body::-webkit-scrollbar-track { background: transparent; }
    .notif-dropdown-body::-webkit-scrollbar-thumb { background: rgba(212,175,55,0.3); border-radius: 3px; }

    .notif-item {
        display: flex;
        gap: 12px;
        padding: 12px 18px;
        border-bottom: 1px solid rgba(255,255,255,0.04);
        cursor: pointer;
        transition: background 0.15s ease;
        align-items: flex-start;
    }

    .notif-item:hover {
        background: rgba(37, 99, 235, 0.06);
    }

    .notif-item.unread {
        background: rgba(212, 175, 55, 0.04);
        border-left: 3px solid #d4af37;
    }

    .notif-item.unread:hover {
        background: rgba(212, 175, 55, 0.08);
    }

    .notif-item-icon {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.95rem;
        flex-shrink: 0;
    }

    .notif-item-icon.tour       { background: rgba(13, 202, 240, 0.12); color: #0dcaf0; }
    .notif-item-icon.approved   { background: rgba(34, 197, 94, 0.12); color: #22c55e; }
    .notif-item-icon.rejected   { background: rgba(239, 68, 68, 0.12); color: #ef4444; }
    .notif-item-icon.cancelled  { background: rgba(249, 115, 22, 0.12); color: #f97316; }
    .notif-item-icon.completed  { background: rgba(34, 197, 94, 0.12); color: #22c55e; }
    .notif-item-icon.sale       { background: rgba(212, 175, 55, 0.12); color: #d4af37; }
    .notif-item-icon.commission { background: rgba(168, 85, 247, 0.12); color: #a855f7; }
    .notif-item-icon.general    { background: rgba(37, 99, 235, 0.12); color: #3b82f6; }

    .notif-item-body {
        flex: 1;
        min-width: 0;
    }

    .notif-item-title {
        font-size: 0.85rem;
        font-weight: 600;
        color: #e5e7eb;
        margin-bottom: 2px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .notif-item.unread .notif-item-title { color: #ffffff; }

    .notif-item-msg {
        font-size: 0.78rem;
        color: #9ca3af;
        line-height: 1.35;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .notif-item-time {
        font-size: 0.7rem;
        color: #6b7280;
        margin-top: 3px;
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .notif-item-dot {
        width: 7px;
        height: 7px;
        background: #d4af37;
        border-radius: 50%;
        flex-shrink: 0;
        margin-top: 4px;
    }

    .notif-dropdown-footer {
        padding: 10px 18px;
        border-top: 1px solid rgba(212, 175, 55, 0.15);
        text-align: center;
    }

    .notif-dropdown-footer a {
        color: #3b82f6;
        font-size: 0.82rem;
        font-weight: 600;
        text-decoration: none;
        transition: color 0.2s ease;
    }

    .notif-dropdown-footer a:hover {
        color: #60a5fa;
    }

    .notif-empty {
        padding: 40px 20px;
        text-align: center;
        color: #6b7280;
    }

    .notif-empty i {
        font-size: 2.5rem;
        color: #374151;
        margin-bottom: 10px;
        display: block;
    }

    .notif-empty p {
        font-size: 0.85rem;
        margin: 0;
    }

    @media (max-width: 480px) {
        .notif-dropdown-panel {
            width: calc(100vw - 24px);
            right: -60px;
        }
    }

    /* Profile Dropdown */
    .agent-navbar .profile-dropdown {
        position: relative;
    }

    .agent-navbar .profile-toggle {
        display: flex;
        align-items: center;
        gap: 0.65rem;
        padding: 0.3rem 0.75rem 0.3rem 0.3rem;
        background: rgba(212, 175, 55, 0.06);
        border: 1px solid rgba(212, 175, 55, 0.15);
        border-radius: 2px;
        cursor: pointer;
        transition: all 0.3s ease;
        color: #b8bec4;
        font-weight: 600;
        font-size: 0.85rem;
    }

    .agent-navbar .profile-toggle:hover {
        background: rgba(212, 175, 55, 0.12);
        border-color: rgba(212, 175, 55, 0.3);
        color: #d4af37;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(212, 175, 55, 0.1);
    }

    .agent-navbar .profile-toggle img {
        width: 32px;
        height: 32px;
        border-radius: 2px;
        object-fit: cover;
        border: 1.5px solid rgba(212, 175, 55, 0.4);
    }

    .agent-navbar .profile-toggle .dropdown-arrow {
        font-size: 0.65rem;
        transition: transform 0.3s ease;
        color: #7a8a99;
    }

    .agent-navbar .profile-dropdown.show .dropdown-arrow {
        transform: rotate(180deg);
    }

    /* Dropdown Menu */
    .agent-navbar .dropdown-menu {
        background: linear-gradient(180deg, #141414 0%, #111111 100%);
        border: 1px solid rgba(37, 99, 235, 0.2);
        box-shadow: 0 12px 40px rgba(0,0,0,0.6), 0 0 0 1px rgba(37, 99, 235, 0.1);
        border-radius: 4px;
        padding: 0.5rem;
        margin-top: 0.5rem;
        min-width: 210px;
    }

    .agent-navbar .dropdown-menu .dropdown-divider {
        border-color: rgba(37, 99, 235, 0.1);
        margin: 0.35rem 0;
    }

    .agent-navbar .dropdown-item {
        padding: 0.65rem 1rem;
        border-radius: 2px;
        transition: all 0.2s ease;
        color: #b8bec4;
        font-weight: 500;
        font-size: 0.9rem;
    }

    .agent-navbar .dropdown-item:hover {
        background: rgba(37, 99, 235, 0.08);
        color: #ffffff;
    }

    .agent-navbar .dropdown-item i {
        width: 20px;
        text-align: center;
        margin-right: 0.75rem;
        color: #7a8a99;
        font-size: 0.85rem;
    }

    .agent-navbar .dropdown-item:hover i {
        color: #d4af37;
    }

    .agent-navbar .dropdown-item.logout-custom {
        color: #ef4444 !important;
    }

    .agent-navbar .dropdown-item.logout-custom i {
        color: #ef4444;
    }

    .agent-navbar .dropdown-item.logout-custom:hover {
        background: rgba(239, 68, 68, 0.08) !important;
        color: #f87171 !important;
    }

    /* Agent Role Badge */
    .agent-role-badge {
        font-size: 0.6rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 1.5px;
        color: #0a0a0a;
        background: linear-gradient(135deg, #b8941f 0%, #d4af37 50%, #b8941f 100%);
        padding: 2px 8px;
        border-radius: 2px;
        line-height: 1.4;
    }

    /* Mobile Toggler */
    .agent-navbar .navbar-toggler {
        border: 1.5px solid rgba(37, 99, 235, 0.4);
        border-radius: 2px;
        padding: 0.5rem;
        transition: all 0.3s ease;
    }

    .agent-navbar .navbar-toggler:hover {
        background-color: rgba(37, 99, 235, 0.1);
        border-color: #2563eb;
    }

    .agent-navbar .navbar-toggler:focus {
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.2);
    }

    .agent-navbar .navbar-toggler-icon {
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='%232563eb' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
    }

    /* Responsive */
    @media (max-width: 991px) {
        .agent-navbar {
            padding: 0.6rem 0;
        }

        .agent-navbar .navbar-center {
            position: static;
            transform: none;
            flex-direction: column;
            width: 100%;
            padding: 1rem 0;
            gap: 0.15rem;
        }

        .agent-navbar .navbar-collapse {
            margin-top: 0.75rem;
            border-top: 1px solid rgba(37, 99, 235, 0.1);
            padding-top: 0.5rem;
        }

        .agent-navbar .navbar-actions {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid rgba(37, 99, 235, 0.1);
            justify-content: center;
            width: 100%;
        }
    }

    @media (max-width: 768px) {
        .agent-navbar .navbar-logo {
            height: 35px;
        }

        .agent-navbar .brand-name {
            font-size: 1.1rem;
        }

        .agent-navbar .brand-tagline {
            font-size: 0.55rem;
        }

        .agent-navbar .profile-toggle span {
            display: none;
        }

        .agent-navbar .navbar-center .nav-link {
            padding: 0.7rem 1rem !important;
        }
    }
</style>

<!-- Agent Navigation -->
<nav class="navbar navbar-expand-lg agent-navbar">
    <div class="container">
        <!-- Logo & Brand -->
        <a class="navbar-brand" href="agent_dashboard.php">
            <img src="../images/Logo.png" alt="HomeEstate Realty Logo" class="navbar-logo">
            <div class="brand-text">
                <span class="brand-name">HomeEstate Realty</span>
                <span class="brand-tagline">Agent Portal</span>
            </div>
        </a>

        <!-- Mobile Toggle -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#agentNavbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Collapsible Content -->
        <div class="collapse navbar-collapse" id="agentNavbarNav">
            <!-- Center Navigation -->
            <ul class="navbar-nav navbar-center">
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'agent_dashboard.php' || !isset($active_page) ? 'active' : ''; ?>" href="agent_dashboard.php">
                        <i class="bi bi-speedometer2 me-1 d-lg-none"></i>Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'agent_property.php' || (isset($active_page) && $active_page == 'agent_property.php') ? 'active' : ''; ?>" href="agent_property.php">
                        <i class="bi bi-building me-1 d-lg-none"></i>My Properties
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'agent_tour_requests.php' || (isset($active_page) && $active_page == 'agent_tour_requests.php') ? 'active' : ''; ?>" href="agent_tour_requests.php">
                        <i class="bi bi-calendar-check me-1 d-lg-none"></i>Tour Requests
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'agent_commissions.php' || (isset($active_page) && $active_page == 'agent_commissions.php') ? 'active' : ''; ?>" href="agent_commissions.php">
                        <i class="bi bi-wallet2 me-1 d-lg-none"></i>Commissions
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'agent_rental_payments.php' || (isset($active_page) && $active_page == 'agent_rental_payments.php') ? 'active' : ''; ?>" href="agent_rental_payments.php">
                        <i class="bi bi-receipt me-1 d-lg-none"></i>Rental Payments
                    </a>
                </li>
            </ul>

            <!-- Right Side Actions -->
            <div class="navbar-actions">
                <!-- Notification Button + Dropdown -->
                <div class="position-relative" id="notifDropdownWrapper">
                    <button class="notification-btn" type="button" title="Notifications" id="notifToggleBtn">
                        <i class="bi bi-bell-fill"></i>
                        <span class="notification-badge" id="notifBadge"></span>
                    </button>
                    <div class="notif-dropdown-panel" id="notifDropdownPanel">
                        <div class="notif-dropdown-header">
                            <h6><i class="bi bi-bell me-2"></i>Notifications</h6>
                            <button class="notif-mark-all-btn" id="notifMarkAllBtn" title="Mark all as read">
                                <i class="bi bi-check2-all me-1"></i>Mark all read
                            </button>
                        </div>
                        <div class="notif-dropdown-body" id="notifDropdownBody">
                            <div class="notif-empty">
                                <i class="bi bi-bell-slash"></i>
                                <p>Loading...</p>
                            </div>
                        </div>
                        <div class="notif-dropdown-footer">
                            <a href="agent_notifications.php">View All Notifications <i class="bi bi-arrow-right ms-1"></i></a>
                        </div>
                    </div>
                </div>

                <!-- Profile Dropdown -->
                <div class="dropdown profile-dropdown">
                    <button class="profile-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <img src="<?php echo htmlspecialchars($profile_image_src); ?>" alt="Profile" onerror="this.onerror=null;this.src='<?= BASE_URL ?>images/placeholder-avatar.svg';"> 
                        <span><?php echo htmlspecialchars($navbar_display_name); ?></span>
                        <span class="agent-role-badge">AGENT</span>
                        <i class="bi bi-chevron-down dropdown-arrow"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <a class="dropdown-item" href="agent_profile.php">
                                <i class="bi bi-person-circle"></i>My Profile
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="logout-custom dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#logoutModal">
                                <i class="bi bi-box-arrow-right"></i>Logout
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- Navbar Scroll Effect -->
<script>
    // Throttled via requestAnimationFrame to prevent scroll jank
    let _agentNavScrollTicking = false;
    window.addEventListener('scroll', function() {
        if (!_agentNavScrollTicking) {
            requestAnimationFrame(function() {
                const navbar = document.querySelector('.agent-navbar');
                if (navbar) {
                    if (window.scrollY > 50) {
                        navbar.classList.add('scrolled');
                    } else {
                        navbar.classList.remove('scrolled');
                    }
                }
                _agentNavScrollTicking = false;
            });
            _agentNavScrollTicking = true;
        }
    }, { passive: true });
</script>

<!-- Notification Dropdown Logic -->
<script>
(function() {
    const toggleBtn   = document.getElementById('notifToggleBtn');
    const panel       = document.getElementById('notifDropdownPanel');
    const badge       = document.getElementById('notifBadge');
    const body        = document.getElementById('notifDropdownBody');
    const markAllBtn  = document.getElementById('notifMarkAllBtn');
    const wrapper     = document.getElementById('notifDropdownWrapper');
    const API_URL     = 'agent_notifications_api.php';

    let isOpen = false;

    // Toggle dropdown
    toggleBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        isOpen = !isOpen;
        panel.classList.toggle('show', isOpen);
        if (isOpen) fetchNotifications();
    });

    // Close on outside click
    document.addEventListener('click', function(e) {
        if (isOpen && !wrapper.contains(e.target)) {
            isOpen = false;
            panel.classList.remove('show');
        }
    });

    // Mark all read
    markAllBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        fetch(API_URL + '?action=mark_all', { method: 'POST' })
            .then(r => r.json())
            .then(d => { if (d.success) fetchNotifications(); });
    });

    // Fetch notifications
    function fetchNotifications() {
        fetch(API_URL + '?action=fetch')
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;

                // Update badge
                if (data.unread > 0) {
                    badge.textContent = data.unread > 99 ? '99+' : data.unread;
                    badge.classList.add('has-unread');
                } else {
                    badge.textContent = '';
                    badge.classList.remove('has-unread');
                }

                // Render items
                if (data.notifications.length === 0) {
                    body.innerHTML = '<div class="notif-empty"><i class="bi bi-bell-slash"></i><p>No notifications yet</p></div>';
                    return;
                }

                let html = '';
                data.notifications.forEach(n => {
                    html += `
                        <div class="notif-item ${n.is_read ? '' : 'unread'}" data-id="${n.id}" onclick="window.__notifMarkRead(${n.id}, '${n.type}', ${n.ref_id || 'null'})">
                            <div class="notif-item-icon ${n.color}"><i class="${n.icon}"></i></div>
                            ${!n.is_read ? '<div class="notif-item-dot"></div>' : ''}
                            <div class="notif-item-body">
                                <div class="notif-item-title">${escHtml(n.title)}</div>
                                <div class="notif-item-msg">${escHtml(n.message)}</div>
                                <div class="notif-item-time"><i class="bi bi-clock"></i>${n.time_ago}</div>
                            </div>
                        </div>`;
                });
                body.innerHTML = html;
            })
            .catch(() => {
                body.innerHTML = '<div class="notif-empty"><i class="bi bi-exclamation-triangle"></i><p>Failed to load</p></div>';
            });
    }

    // Mark single as read + navigate
    window.__notifMarkRead = function(id, type, refId) {
        fetch(API_URL + '?action=mark_read', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'notification_id=' + id
        }).then(() => {
            // Navigate based on type
            const routes = {
                'tour_new': 'agent_tour_requests.php',
                'tour_cancelled': 'agent_tour_requests.php',
                'tour_completed': 'agent_tour_requests.php',
                'property_approved': 'agent_property.php',
                'property_rejected': 'agent_property.php',
                'sale_approved': 'agent_commissions.php',
                'sale_rejected': 'agent_property.php',
                'commission_paid': 'agent_commissions.php',
            };
            window.location.href = routes[type] || 'agent_notifications.php';
        });
    };

    function escHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Auto-poll badge every 30 seconds
    function pollBadge() {
        fetch(API_URL + '?action=fetch', {
            headers: { 'X-Background-Poll': '1' }
        })
            .then(r => {
                // If session expired, redirect to login
                if (r.status === 401) {
                    window.location.href = '../login.php?timeout=1';
                    return null;
                }
                return r.json();
            })
            .then(data => {
                if (!data || !data.success) return;
                if (data.unread > 0) {
                    badge.textContent = data.unread > 99 ? '99+' : data.unread;
                    badge.classList.add('has-unread');
                } else {
                    badge.textContent = '';
                    badge.classList.remove('has-unread');
                }
            }).catch(() => {});
    }

    // Initial badge load + poll
    pollBadge();
    setInterval(pollBadge, 30000);
})();
</script>