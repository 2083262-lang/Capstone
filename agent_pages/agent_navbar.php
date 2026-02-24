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
$profile_image_src = 'https://via.placeholder.com/40';
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
        backdrop-filter: blur(10px);
        box-shadow: 0 2px 20px rgba(0,0,0,0.6), 0 1px 0 rgba(212, 175, 55, 0.1);
        padding: 0.75rem 0;
        border-bottom: 1px solid rgba(212, 175, 55, 0.15);
        transition: all 0.3s ease;
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
        top: 4px;
        right: 4px;
        width: 8px;
        height: 8px;
        background: #ef4444;
        border-radius: 50%;
        border: 2px solid #111111;
        box-shadow: 0 0 6px rgba(239, 68, 68, 0.5);
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
            </ul>

            <!-- Right Side Actions -->
            <div class="navbar-actions">
                <!-- Notification Button -->
                <button class="notification-btn" type="button" title="Notifications">
                    <i class="bi bi-bell-fill"></i>
                    <span class="notification-badge"></span>
                </button>

                <!-- Profile Dropdown -->
                <div class="dropdown profile-dropdown">
                    <button class="profile-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <img src="<?php echo htmlspecialchars($profile_image_src); ?>" alt="Profile" onerror="this.onerror=null;this.src='https://via.placeholder.com/40';"> 
                        <span><?php echo htmlspecialchars($navbar_display_name); ?></span>
                        <span class="agent-role-badge">AGENT</span>
                        <i class="bi bi-chevron-down dropdown-arrow"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <a class="dropdown-item" href="agent_dashboard.php">
                                <i class="bi bi-speedometer2"></i>Dashboard
                            </a>
                        </li>
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
    window.addEventListener('scroll', function() {
        const navbar = document.querySelector('.agent-navbar');
        if (navbar) {
            if (window.scrollY > 50) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        }
    });
</script>