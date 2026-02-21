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
        // Absolute URL
        $profile_image_src = $raw_profile;
    } elseif (strpos($raw_profile, '/') === 0) {
        // Root-relative path e.g. /uploads/agents/... from agent_pages => prefix one level up
        $profile_image_src = '..' . $raw_profile;
    } else {
        // Relative path stored e.g. uploads/agents/...
        $profile_image_src = '../' . $raw_profile;
    }
}
?>

<style>
    /* Navbar */
        .navbar-custom {
        background: linear-gradient(90deg, #ffffff 0%, #fafbfc 100%);
        border-bottom: 1px solid var(--border-color);
        height: 70px;
        padding: 0 2.5rem;
        box-shadow: var(--shadow-light);
        position: sticky;
        top: 0;
        z-index: 1000;
    }

    .navbar-custom .container-fluid {
        display: flex;
        align-items: center;
        justify-content: space-between;
        max-width: 100%;
        padding: 0;
    }

    .navbar-custom .navbar-brand {
        flex: 0 0 auto;
        padding: 0;
        margin: 0;
    }

    .navbar-custom .navbar-brand img {
        height: 45px;
        filter: brightness(0.95);
    }

    /* Center Navigation */
    .navbar-center {
        position: absolute;
        left: 50%;
        transform: translateX(-50%);
        display: flex;
        gap: 0.5rem;
    }

    .navbar-center .nav-link {
        color: var(--primary-color);
        font-weight: 500;
        font-size: 0.95rem;
        transition: all 0.3s ease;
        padding: 0.6rem 1.5rem;
        border-radius: 8px;
        white-space: nowrap;
        position: relative;
    }

    .navbar-center .nav-link::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 50%;
        transform: translateX(-50%);
        width: 0;
        height: 2px;
        background: var(--secondary-color);
        transition: width 0.3s ease;
    }

    .navbar-center .nav-link:hover,
    .navbar-center .nav-link.active {
        color: var(--secondary-color);
        background: rgba(188, 158, 66, 0.08);
    }

    .navbar-center .nav-link.active::after {
        width: 60%;
    }

    /* Right Side Actions */
    .navbar-actions {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-left: auto;
    }

    .notification-btn {
        position: relative;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--primary-color);
        background: rgba(188, 158, 66, 0.08);
        transition: all 0.3s ease;
        cursor: pointer;
        border: none;
    }

    .notification-btn:hover {
        background: rgba(188, 158, 66, 0.15);
        color: var(--secondary-color);
        transform: scale(1.05);
    }

    .notification-badge {
        position: absolute;
        top: 5px;
        right: 5px;
        width: 8px;
        height: 8px;
        background: #dc3545;
        border-radius: 50%;
        border: 2px solid white;
    }

    /* Profile Dropdown */
    .profile-dropdown {
        position: relative;
    }

    .profile-toggle {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.4rem 0.8rem 0.4rem 0.4rem;
        background: rgba(188, 158, 66, 0.08);
        border-radius: 25px;
        cursor: pointer;
        transition: all 0.3s ease;
        border: none;
        color: var(--primary-color);
        font-weight: 500;
        font-size: 0.9rem;
    }

    .profile-toggle:hover {
        background: rgba(188, 158, 66, 0.15);
        transform: scale(1.02);
    }

    .profile-toggle img {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid var(--secondary-color);
    }

    .profile-toggle .dropdown-arrow {
        font-size: 0.75rem;
        transition: transform 0.3s ease;
    }

    .profile-dropdown.show .dropdown-arrow {
        transform: rotate(180deg);
    }

    .dropdown-menu {
        border: 1px solid var(--border-color);
        box-shadow: var(--shadow-medium);
        border-radius: 12px;
        padding: 0.5rem;
        margin-top: 0.5rem;
        min-width: 200px;
    }

    .dropdown-item {
        padding: 0.7rem 1rem;
        border-radius: 8px;
        transition: all 0.2s ease;
        color: var(--primary-color);
        font-weight: 500;
        font-size: 0.9rem;
    }

    .dropdown-item:hover {
        background: rgba(188, 158, 66, 0.1);
        color: var(--secondary-color);
    }

    .dropdown-item i {
        width: 20px;
        text-align: center;
        margin-right: 0.75rem;
    }

    .logout-custom {
        color: #dc3545 !important;
    }

    .logout-custom:hover {
        background: rgba(220, 53, 69, 0.1) !important;
    }

    /* Mobile Toggle */
    .navbar-toggler {
        border: none;
        padding: 0.5rem;
        border-radius: 8px;
    }

    .navbar-toggler:focus {
        box-shadow: none;
    }

    .navbar-toggler-icon {
        width: 24px;
        height: 2px;
        background: var(--primary-color);
        display: block;
        position: relative;
        transition: all 0.3s ease;
    }

    .navbar-toggler-icon::before,
    .navbar-toggler-icon::after {
        content: '';
        width: 24px;
        height: 2px;
        background: var(--primary-color);
        display: block;
        position: absolute;
        transition: all 0.3s ease;
    }

    .navbar-toggler-icon::before {
        top: -8px;
    }

    .navbar-toggler-icon::after {
        bottom: -8px;
    }

    /* Responsive */
    @media (max-width: 991px) {
        .navbar-custom {
            padding: 0 1rem;
        }

        .navbar-center {
            position: static;
            transform: none;
            flex-direction: column;
            width: 100%;
            padding: 1rem 0;
        }

        .navbar-collapse {
            margin-top: 1rem;
        }

        .navbar-actions {
            margin-top: 1rem;
            justify-content: center;
            width: 100%;
        }
    }
    @media (max-width: 768px) {
        .navbar-custom .navbar-brand img {
            height: 35px;
        }

        .profile-toggle span {
            display: none;
        }

        .navbar-center .nav-link {
            padding: 0.8rem 1rem;
        }
    }
</style>

<nav class="navbar navbar-expand-lg navbar-custom">
    <div class="container-fluid">
        <!-- Logo -->
        <a class="navbar-brand" href="agent_dashboard.php">
            <img src="https://via.placeholder.com/160x60/bc9e42/161209?text=PRESTIGE+LOGO" alt="Company Logo">
        </a>
        
        <!-- Mobile Toggle -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Collapsible Content -->
        <div class="collapse navbar-collapse" id="navbarNav">
            <!-- Center Navigation -->
            <ul class="navbar-nav navbar-center">
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'agent_dashboard.php' || !isset($active_page) ? 'active' : ''; ?>" href="agent_dashboard.php">Dashboard</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'agent_property.php' || (isset($active_page) && $active_page == 'agent_property.php') ? 'active' : ''; ?>" href="agent_property.php">My Properties</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'agent_tour_requests.php' || (isset($active_page) && $active_page == 'agent_tour_requests.php') ? 'active' : ''; ?>" href="agent_tour_requests.php">Requested Tours</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'agent_profile.php' || (isset($active_page) && $active_page == 'agent_profile.php') ? 'active' : ''; ?>" href="agent_profile.php">My Profile</a>
                </li>
            </ul>

            <!-- Right Side Actions -->
            <div class="navbar-actions">
                <!-- Notification Button -->
                <button class="notification-btn" type="button" title="Notifications">
                    <i class="fas fa-bell"></i>
                    <span class="notification-badge"></span>
                </button>

                <!-- Profile Dropdown -->
                <div class="dropdown profile-dropdown">
                    <button class="profile-toggle dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <img src="<?php echo htmlspecialchars($profile_image_src); ?>" alt="Profile" onerror="this.onerror=null;this.src='https://via.placeholder.com/40';">
                        <span><?php echo htmlspecialchars($agent_username); ?></span>
                       
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <a class="dropdown-item" href="agent_profile.php">
                                <i class="fas fa-user"></i>My Profile
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="agent_settings.php">
                                <i class="fas fa-cog"></i>Settings
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="logout-custom dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#logoutModal">
                                <i class="fas fa-sign-out-alt"></i>Logout
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</nav>