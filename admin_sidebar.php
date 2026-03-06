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
        padding: 1rem 1.5rem;
        display: flex;
        align-items: center;
        justify-content: center;
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
        width: 48px;
        height: 48px;
        object-fit: contain;
        filter: brightness(1.1);
        flex-shrink: 0;
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


<style>
    /* PJAX page transition */
    .admin-content.pjax-loading {
        opacity: 0.4;
        pointer-events: none;
        transition: opacity 0.15s ease;
    }
</style>

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

        // ================================================
        // PJAX-STYLE NAVIGATION — prevents full page reload
        // Keeps sidebar persistent, only swaps content area
        // ================================================
        (function initPjax() {
            var pjaxActive = false; // prevent overlapping requests

            // Update sidebar active state
            function updateSidebarActive(url) {
                var page = url.split('/').pop().split('?')[0];
                sidebar.querySelectorAll('.sidebar-nav .nav-link').forEach(function(link) {
                    var linkPage = link.getAttribute('href').split('/').pop().split('?')[0];
                    if (linkPage === page ||
                        ((page === 'view_property.php' || page === 'property_tour_requests.php') && linkPage === 'property.php')) {
                        link.classList.add('active');
                    } else {
                        link.classList.remove('active');
                    }
                });
            }

            // Main PJAX load function
            function pjaxNavigate(url, pushState) {
                if (pjaxActive) return;
                pjaxActive = true;

                var contentEl = document.querySelector('.admin-content');
                if (contentEl) contentEl.classList.add('pjax-loading');

                fetch(url, { credentials: 'same-origin' })
                    .then(function(res) {
                        if (!res.ok) throw new Error(res.status);
                        return res.text();
                    })
                    .then(function(html) {
                        var parser = new DOMParser();
                        var newDoc = parser.parseFromString(html, 'text/html');

                        // 0. Clear existing toasts & remove old injected PJAX scripts
                        var toastContainer = document.getElementById('toastContainer');
                        if (toastContainer) toastContainer.innerHTML = '';
                        document.querySelectorAll('script[data-pjax-injected]').forEach(function(s) { s.remove(); });

                        // Navigation ID to guard stale callbacks
                        window.__pjaxNavId = (window.__pjaxNavId || 0) + 1;
                        var currentNavId = window.__pjaxNavId;

                        // 1. Replace <head> styles
                        document.head.querySelectorAll('style:not([data-pjax-keep])').forEach(function(s) { s.remove(); });
                        newDoc.head.querySelectorAll('style').forEach(function(s) {
                            document.head.appendChild(s.cloneNode(true));
                        });
                        var existingLinks = Array.from(document.head.querySelectorAll('link[rel="stylesheet"]')).map(function(l) { return l.getAttribute('href'); });
                        newDoc.head.querySelectorAll('link[rel="stylesheet"]').forEach(function(link) {
                            if (existingLinks.indexOf(link.getAttribute('href')) === -1) {
                                document.head.appendChild(link.cloneNode(true));
                            }
                        });

                        // 2. Replace document title
                        document.title = newDoc.title || document.title;

                        // 3. Replace ALL body content after the persistent .admin-navbar.
                        //    This covers .admin-content, modals placed outside it,
                        //    #toastContainer, and page inline scripts — fixing the
                        //    "classList of null" error caused by modal HTML from a previous
                        //    page lingering in the DOM after only swapping .admin-content.

                        // 3a. Collect post-navbar nodes from the fetched document
                        var fetchedNavbar = newDoc.querySelector('.admin-navbar');
                        var afterNavNodes = [];
                        if (fetchedNavbar) {
                            var fn = fetchedNavbar.nextSibling;
                            while (fn) { afterNavNodes.push(fn); fn = fn.nextSibling; }
                        }

                        // 3b. Remove all live DOM nodes that follow the persistent navbar
                        var liveNavbar = document.querySelector('.admin-navbar');
                        if (liveNavbar) {
                            while (liveNavbar.nextSibling) {
                                document.body.removeChild(liveNavbar.nextSibling);
                            }
                        }

                        // 4. Manually hydrate skeleton — window 'load' never fires in PJAX
                        // (handled below, after new nodes are inserted)

                        // 5. Update sidebar active state
                        updateSidebarActive(url);

                        // 6. Intercept event listeners registered by new page scripts so we can
                        //    control exactly when they fire instead of relying on browser events
                        //    that may not re-fire during PJAX navigation.
                        var origDocAEL = document.addEventListener.bind(document);
                        var origWinAEL = window.addEventListener.bind(window);
                        var skeletonListeners  = [];
                        var domReadyListeners  = [];

                        document.addEventListener = function(type, fn, opts) {
                            if (type === 'skeleton:hydrated') { skeletonListeners.push(fn); return; }
                            if (type === 'DOMContentLoaded')  { domReadyListeners.push(fn);  return; }
                            return origDocAEL(type, fn, opts);
                        };
                        window.addEventListener = function(type, fn, opts) {
                            // Discard window 'load' — hydration is handled manually (step after)
                            if (type === 'load') return;
                            return origWinAEL(type, fn, opts);
                        };

                        // 7. Insert post-navbar nodes from the fetched page.
                        //    Non-script elements (admin-content, modals, toastContainer …):
                        //      import into live doc, strip any nested <script> tags into a queue,
                        //      then append — scripts inside divs do NOT execute via importNode.
                        //    Script elements: controlled injection via createElement so that our
                        //      listener interception (step 6) captures their registrations.
                        //    Skip: sidebar PJAX script, skeleton hydrator, navbar script.
                        var scriptQueue = [];

                        afterNavNodes.forEach(function(node) {
                            if (node.nodeType === Node.TEXT_NODE || node.nodeType === Node.COMMENT_NODE) {
                                document.body.appendChild(document.importNode(node, false));
                                return;
                            }
                            if (node.nodeType !== Node.ELEMENT_NODE) return;

                            if (node.tagName === 'SCRIPT') {
                                scriptQueue.push(node);
                                return;
                            }

                            // HTML element (div, nav, etc.) — import deeply, extract nested scripts
                            var clone = document.importNode(node, true);
                            clone.querySelectorAll('script').forEach(function(s) {
                                scriptQueue.push(s);
                                s.parentNode.removeChild(s);
                            });
                            document.body.appendChild(clone);
                        });

                        // Process the script queue SEQUENTIALLY with active listener
                        // interception. External scripts (with src) must finish loading
                        // before the next script runs, because inline scripts may depend
                        // on libraries loaded by a preceding external script (e.g. Chart.js).
                        function injectNextScript(idx) {
                            // Base case: all scripts processed → finalize navigation
                            if (idx >= scriptQueue.length) {
                                // 8. Restore original event listener methods
                                document.addEventListener = origDocAEL;
                                window.addEventListener   = origWinAEL;

                                // Manually hydrate skeleton — window 'load' never fires in PJAX
                                var sk = document.getElementById('sk-screen');
                                var pc = document.getElementById('page-content');
                                if (sk) sk.remove();
                                if (pc) {
                                    pc.style.display  = 'block';
                                    pc.style.opacity  = '1';
                                    pc.style.transition = '';
                                }

                                // 9. Run captured DOMContentLoaded handlers immediately
                                domReadyListeners.forEach(function(fn) {
                                    try { fn(); } catch(e) { console.warn('PJAX DOMContentLoaded error:', e); }
                                });

                                // 10. Fire skeleton:hydrated listeners after a short delay
                                setTimeout(function() {
                                    if (window.__pjaxNavId !== currentNavId) return;
                                    skeletonListeners.forEach(function(fn) {
                                        try { fn(); } catch(e) { console.warn('PJAX skeleton:hydrated error:', e); }
                                    });
                                    skeletonListeners = [];
                                }, 200);

                                // 11. Push URL to browser history
                                if (pushState !== false) {
                                    history.pushState({ pjax: true }, '', url);
                                }

                                // 12. Scroll to top & close mobile sidebar
                                window.scrollTo(0, 0);
                                sidebar.classList.remove('show');
                                overlay.classList.remove('show');
                                pjaxActive = false;
                                return;
                            }

                            var oldScript = scriptQueue[idx];
                            var txt = oldScript.textContent || '';

                            // Skip known scripts
                            if (txt.indexOf('initPjax') !== -1) return injectNextScript(idx + 1);
                            if (txt.indexOf('sk-screen') !== -1 && txt.indexOf('scheduleHydration') !== -1) return injectNextScript(idx + 1);
                            if (txt.indexOf('adminNotifToggleBtn') !== -1) return injectNextScript(idx + 1);

                            var rawSrc = oldScript.getAttribute('src');
                            if (rawSrc) {
                                // Deduplicate external scripts by filename
                                var filename = rawSrc.split('/').pop().split('?')[0];
                                if (document.querySelector('script[src*="' + filename + '"]')) return injectNextScript(idx + 1);
                            }

                            var newScript = document.createElement('script');
                            newScript.setAttribute('data-pjax-injected', 'true');
                            Array.from(oldScript.attributes).forEach(function(attr) {
                                if (attr.name !== 'src' && attr.name !== 'data-pjax-injected') {
                                    newScript.setAttribute(attr.name, attr.value);
                                }
                            });

                            if (rawSrc) {
                                // External script: wait for load before continuing
                                newScript.src = new URL(rawSrc, url).href;
                                newScript.onload  = function() { injectNextScript(idx + 1); };
                                newScript.onerror = function() { console.warn('PJAX: failed to load', rawSrc); injectNextScript(idx + 1); };
                                document.body.appendChild(newScript);
                            } else {
                                // Inline script: executes synchronously on append
                                newScript.textContent = txt;
                                document.body.appendChild(newScript);
                                injectNextScript(idx + 1);
                            }
                        }

                        // Start sequential script injection
                        injectNextScript(0);
                    })
                    .catch(function(err) {
                        console.warn('PJAX failed, falling back to full navigation:', err);
                        pjaxActive = false;
                        window.location.href = url;
                    });
            }

            // Intercept sidebar link clicks
            sidebar.addEventListener('click', function(e) {
                var link = e.target.closest('.sidebar-nav .nav-link');
                if (!link) return;
                // Don't intercept logout or modal triggers
                if (link.classList.contains('logout-link')) return;
                if (link.getAttribute('data-bs-toggle')) return;

                var href = link.getAttribute('href');
                if (!href || href === '#') return;

                // Only handle same-origin navigation
                try {
                    var targetUrl = new URL(href, window.location.origin);
                    if (targetUrl.origin !== window.location.origin) return;
                } catch (err) { return; }

                e.preventDefault();
                e.stopPropagation(); // Prevent click from reaching Bootstrap modal/dropdown handlers

                // Clean up any lingering Bootstrap state before navigating
                document.querySelectorAll('.modal-backdrop').forEach(function(el) { el.remove(); });
                document.body.classList.remove('modal-open');
                document.body.style.removeProperty('overflow');
                document.body.style.removeProperty('padding-right');
                // Close any visible Bootstrap modals gracefully
                document.querySelectorAll('.modal.show').forEach(function(el) {
                    try { var inst = bootstrap.Modal.getInstance(el); if (inst) inst.hide(); } catch(x) {}
                });
                // Close any open Bootstrap dropdowns
                document.querySelectorAll('[data-bs-toggle="dropdown"].show, .dropdown-menu.show').forEach(function(el) {
                    el.classList.remove('show');
                    el.removeAttribute('aria-expanded');
                });

                // Don't navigate if already on this page
                if (href === window.location.href || href === window.location.pathname) return;

                pjaxNavigate(href, true);
            });

            // Handle browser back/forward buttons
            window.addEventListener('popstate', function(e) {
                if (e.state && e.state.pjax) {
                    pjaxNavigate(window.location.href, false);
                } else {
                    // If user goes back to a non-PJAX page, do full reload
                    window.location.reload();
                }
            });

            // Store initial state so popstate works for the first page too
            history.replaceState({ pjax: true }, '', window.location.href);
        })();
    });
</script>