<?php
// admin_sidebar.php
include __DIR__ . '/logout_modal.php';
// Allow an including page to override the active item by setting $active_page before including this file
if (isset($active_page) && !empty($active_page)) {
    $current_page = $active_page;
} else {
    // Get the current page to highlight active menu item
    $current_page = basename($_SERVER['PHP_SELF']);
}

// Define menu items with their corresponding files and icons (Flaticon Uicons - Regular Straight)
$menu_items = [
    'admin_dashboard.php' => ['icon' => 'fi fi-rs-dashboard-monitor', 'title' => 'Dashboard'],
    'property.php' => ['icon' => 'fi fi-rs-apartment', 'title' => 'Properties'],
    'agent.php' => ['icon' => 'fi fi-rs-employees', 'title' => 'Agents'],
    'tour_requests.php' => ['icon' => 'fi fi-rs-map-marker-home', 'title' => 'Tour Requests'],
    'admin_property_sale_approvals.php' => ['icon' => 'fi fi-rs-sold-house', 'title' => 'Sale Approvals'],
    'admin_rental_approvals.php' => ['icon' => 'bi bi-house-check', 'title' => 'Rental Approvals'],
    'admin_rental_payments.php' => ['icon' => 'bi bi-cash-stack', 'title' => 'Rental Payments'],
    'admin_notifications.php' => ['icon' => 'fi fi-rs-bell', 'title' => 'Notifications'],
    'reports.php' => ['icon' => 'fi fi-rs-chart-pie-simple-circle-dollar', 'title' => 'Reports'],
];
?>

<!-- Ensure Bootstrap Icons are available on any page that includes the sidebar -->
<link rel="stylesheet" href="<?= ASSETS_CSS ?>bootstrap-icons.min.css">
<!-- Flaticon Uicons Regular Straight - used for sidebar navigation icons -->
<link rel="stylesheet" href="<?= ASSETS_CSS ?>uicons-regular-straight.css">
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
       ADMIN SIDEBAR STYLES
       ================================================ */
    .admin-sidebar {
        background: linear-gradient(180deg, var(--black-light) 0%, var(--black) 100%);
        color: var(--white);
        height: 100vh;
        position: fixed;
        top: 0;
        left: 0;
        width: var(--sidebar-width);
        overflow-y: scroll;
        z-index: 1000;
        box-shadow: 0 4px 24px rgba(0, 0, 0, 0.4);
        border-right: 1px solid rgba(37, 99, 235, 0.2);
        /* Hide scrollbar but keep scroll functionality */
        scrollbar-width: none; /* Firefox */
        -ms-overflow-style: none; /* IE/Edge */
    }

    .admin-sidebar::-webkit-scrollbar {
        width: 0 !important;
        height: 0 !important;
        background: transparent !important;
    }

    .admin-sidebar::-webkit-scrollbar-thumb {
        background: transparent !important;
    }

    .admin-sidebar::-webkit-scrollbar-track {
        background: transparent !important;
    }

    .sidebar-brand {
        padding: 1.5rem 1.5rem;
        text-align: center;
        border-bottom: 1px solid rgba(37, 99, 235, 0.2);
        background: linear-gradient(135deg, rgba(37, 99, 235, 0.08) 0%, rgba(212, 175, 55, 0.05) 100%);
        position: relative;
    }

    .sidebar-brand::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 1px;
        background: linear-gradient(90deg, transparent, var(--gold), transparent);
        opacity: 0.5;
    }

    .sidebar-brand img {
        max-width: 100px;
        height: auto;
        filter: brightness(1.1);
    }

    .sidebar-brand h4 {
        background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 50%, var(--gold) 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        font-weight: 700;
        font-size: 1.25rem;
        margin-top: 0.5rem;
        margin-bottom: 0;
        letter-spacing: 0.3px;
        filter: drop-shadow(0 0 8px rgba(212, 175, 55, 0.3));
    }

    .sidebar-brand .text-light {
        font-size: 0.75rem;
        color: var(--gray-400);
        margin-top: 0.25rem;
        font-weight: 500;
    }

    .sidebar-nav {
        padding: 1.5rem 0;
        flex: 1;
    }

    /* ================================================
       SIDEBAR NAVIGATION LINKS
       IMPORTANT: All .nav-link styles are scoped to .admin-sidebar to prevent
       conflicts with property tabs or other .nav-link elements on the page.
       Each page's tabs should define their own scoped .nav-link styles.
       ================================================ */
    .admin-sidebar .nav-item {
        margin-bottom: 0.25rem;
    }

    .admin-sidebar .nav-link {
        display: flex;
        align-items: center;
        padding: 1rem 1.5rem;
        color: var(--gray-300);
        text-decoration: none;
        font-weight: 500;
        font-size: 1rem;
        border-radius: 0;
        position: relative;
        border-left: 4px solid transparent;
    }

    .admin-sidebar .nav-link i {
        width: 20px;
        margin-right: 0.75rem;
        font-size: 1.1rem;
        text-align: center;
        display: inline-block;
        line-height: 1;
        vertical-align: middle;
    }

    .admin-sidebar .nav-link:hover {
        background: linear-gradient(90deg, rgba(37, 99, 235, 0.15), transparent);
        color: var(--white);
        border-left-color: var(--blue);
        padding-left: 2rem;
    }

    .admin-sidebar .nav-link.active {
        background: linear-gradient(90deg, var(--gold-dark) 0%, var(--gold) 50%, rgba(212, 175, 55, 0.8) 100%);
        color: var(--black);
        font-weight: 700;
        border-left-color: var(--gold);
        box-shadow: 0 4px 16px rgba(212, 175, 55, 0.25), inset 0 2px 8px rgba(0, 0, 0, 0.2);
    }

    .admin-sidebar .nav-link.active i {
        color: var(--black);
    }

    .sidebar-footer {
        padding: 0.5rem 0;
        border-top: 1px solid rgba(37, 99, 235, 0.2);
        background: rgba(10, 10, 10, 0.5);
        position: relative;
    }

    .sidebar-footer::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 1px;
        background: linear-gradient(90deg, transparent, var(--blue), transparent);
        opacity: 0.5;
    }

    .admin-sidebar .nav-link.logout-link {
        color: #ff6b6b;
        font-weight: 600;
    }

    .admin-sidebar .nav-link.logout-link:hover {
        background: linear-gradient(90deg, rgba(255, 107, 107, 0.15), transparent);
        color: #ff8a8a;
        border-left-color: #ff6b6b;
    }

    .admin-sidebar .nav-link.logout-link i {
        color: #ff6b6b;
    }

    /* Tablets (769px–1200px): sidebar hides off-screen, no toggle button */
    @media (max-width: 1200px) {
        .admin-sidebar {
            transform: translateX(-100%);
            width: 290px;
            z-index: 1050;
            transition: transform 0.3s ease;
        }
        .admin-sidebar.show {
            transform: translateX(0);
        }
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1049;
            display: none;
        }
        .sidebar-overlay.show {
            display: block;
        }
    }

    /* Mobile Responsive (phones ≤768px): same behaviour, toggle button visible */
    @media (max-width: 768px) {
        .admin-sidebar {
            transform: translateX(-100%);
            width: 290px;
            z-index: 1050;
        }
        .admin-sidebar.show {
            transform: translateX(0);
        }
    }

</style>

<div class="admin-sidebar" id="adminSidebar">
    <!-- Brand/Logo Section -->
    <div class="sidebar-brand">
        <img src="<?= BASE_URL ?>images/Logo.png" alt="HomeEstate Realty Logo">
        <h4>HomeEstate Realty</h4>
        <small class="text-light">Admin Panel</small>
    </div>

    <!-- Navigation Menu -->
    <nav class="sidebar-nav">
        <?php foreach ($menu_items as $page => $item): ?>
            <div class="nav-item">
                <a href="<?= BASE_URL ?><?php echo $page; ?>" class="nav-link <?php echo ($current_page === $page || (in_array($current_page, ['view_property.php','property_tour_requests.php']) && $page === 'property.php')) ? 'active' : ''; ?>">
                    <i class="<?php echo $item['icon']; ?>"></i>
                    <?php echo $item['title']; ?>
                </a>
            </div>
        <?php endforeach; ?>
    </nav>

    <!-- Footer/Logout Section -->
    <div class="sidebar-footer">
        <div class="nav-item">
            <a href="#" class="nav-link logout-link" data-bs-toggle="modal" data-bs-target="#logoutModal">
                <i class="fi fi-rs-sign-out-alt"></i>
                Logout
            </a>
        </div>
    </div>
</div>

<!-- Mobile Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>


<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Mobile sidebar toggle functionality
        const sidebar = document.getElementById('adminSidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const toggleBtn = document.getElementById('sidebarToggle');

        if (toggleBtn) {
            toggleBtn.addEventListener('click', function() {
                sidebar.classList.toggle('show');
                overlay.classList.toggle('show');
            });
        }

        if (overlay) {
            overlay.addEventListener('click', function() {
                sidebar.classList.remove('show');
                overlay.classList.remove('show');
            });
        }

        // Close sidebar on window resize if above tablet breakpoint
        window.addEventListener('resize', function() {
            if (window.innerWidth > 1200) {
                sidebar.classList.remove('show');
                overlay.classList.remove('show');
            }
        });
    });
</script>