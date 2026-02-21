<?php
// navbar.php - Reusable navbar component for user pages
// Note: CSS styles are included in the main page files
?>
<style>
    /* Navigation - Professional Gold, Black, Blue Color Harmony */
        .navbar {
            background: linear-gradient(180deg, #0f0f0f 0%, #111111 100%) !important;
            backdrop-filter: blur(10px);
            box-shadow: 0 2px 20px rgba(0,0,0,0.6), 0 1px 0 rgba(212, 175, 55, 0.1);
            padding: 1rem 0;
            border-bottom: 1px solid rgba(212, 175, 55, 0.15);
            transition: all 0.3s ease;
        }

        .navbar.scrolled {
            padding: 0.5rem 0;
            box-shadow: 0 4px 24px rgba(0,0,0,0.8), 0 1px 0 rgba(37, 99, 235, 0.2);
            border-bottom: 1px solid rgba(37, 99, 235, 0.3);
        }

        .navbar-brand {
            font-weight: 800;
            font-size: 1.5rem;
            color: #d4af37 !important;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            transition: all 0.3s ease;
        }

        .navbar-brand:hover {
            transform: translateY(-1px);
            filter: drop-shadow(0 0 8px rgba(212, 175, 55, 0.3));
        }

        .navbar-logo {
            height: 50px;
            width: auto;
            object-fit: contain;
            transition: transform 0.3s ease;
            filter: brightness(1.1) saturate(1.2);
        }

        .navbar-brand:hover .navbar-logo {
            transform: scale(1.03);
        }

        .brand-text {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
        }

        .brand-name {
            font-size: 1.4rem;
            font-weight: 800;
            background: linear-gradient(135deg, #d4af37 0%, #f4d03f 50%, #d4af37 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .brand-tagline {
            font-size: 0.7rem;
            font-weight: 500;
            color: #7a8a99;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }

        .nav-link {
            font-weight: 600;
            color: #b8bec4 !important;
            padding: 0.75rem 1.25rem !important;
            border-radius: 2px;
            transition: all 0.3s ease;
            position: relative;
            font-size: 1rem;
        }

        .nav-link::after {
            content: '';
            position: absolute;
            bottom: 8px;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 2px;
            background: linear-gradient(90deg, #2563eb 0%, #3b82f6 100%);
            transition: width 0.3s ease;
        }

        .nav-link:hover::after {
            width: 30px;
        }

        .nav-link.active::after {
            width: 30px;
            background: linear-gradient(90deg, #d4af37 0%, #f4d03f 100%);
        }

        .nav-link:hover {
            color: #ffffff !important;
            background-color: rgba(37, 99, 235, 0.08);
        }

        .nav-link.active {
            color: #d4af37 !important;
            background-color: rgba(212, 175, 55, 0.05);
        }

        .navbar-toggler {
            border: 1.5px solid rgba(37, 99, 235, 0.4);
            border-radius: 2px;
            padding: 0.5rem;
            transition: all 0.3s ease;
        }

        .navbar-toggler:hover {
            background-color: rgba(37, 99, 235, 0.1);
            border-color: #2563eb;
        }

        .navbar-toggler:focus {
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.2);
        }

        .navbar-toggler-icon {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='%232563eb' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
        }

        /* Hero Section - Professional Color Balance */
        .hero-section {
            background: linear-gradient(135deg, rgba(10, 10, 10, 0.92) 0%, rgba(17, 17, 17, 0.85) 100%), 
                        url('../images/hero-bg.jpg') center/cover no-repeat;
            min-height: 75vh;
            display: flex;
            align-items: center;
            position: relative;
            color: #fff;
            overflow: hidden;
            border-bottom: 1px solid rgba(37, 99, 235, 0.2);
            box-shadow: inset 0 -1px 0 rgba(212, 175, 55, 0.1);
        }

        .hero-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(ellipse at top, rgba(37, 99, 235, 0.08) 0%, transparent 50%),
                        radial-gradient(ellipse at bottom, rgba(212, 175, 55, 0.05) 0%, transparent 50%);
        }

        .hero-content {
            position: relative;
            z-index: 2;
            text-align: center;
        }

        .hero-title {
            font-size: 4rem;
            font-weight: 800;
            margin-bottom: 1.5rem;
            line-height: 1.2;
            text-shadow: 0 2px 12px rgba(0,0,0,0.4);
            background: linear-gradient(135deg, #ffffff 0%, #e8e8e8 50%, #d4af37 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero-subtitle {
            font-size: 1.4rem;
            font-weight: 400;
            margin-bottom: 3rem;
            opacity: 0.92;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
            color: #c5cdd5;
        }
</style>
<!-- Navigation -->
<nav class="navbar navbar-expand-lg sticky-top">
    <div class="container">
        <a class="navbar-brand" href="index.php">
            <img src="../images/Logo.png" alt="HomeEstate Realty Logo" class="navbar-logo">
            <div class="brand-text">
                <span class="brand-name">HomeEstate Realty</span>
                <span class="brand-tagline">Find Your Dream Home</span>
            </div>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>" href="index.php">Home</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'search_results.php' ? 'active' : ''; ?>" href="search_results.php">Properties</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'agents.php' ? 'active' : ''; ?>" href="agents.php">Agents</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'about.php' ? 'active' : ''; ?>" href="about.php">About</a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- Navbar JavaScript -->
<script>
    // Add mobile menu enhancements
    const navbarToggler = document.querySelector('.navbar-toggler');
    const navbarCollapse = document.querySelector('.navbar-collapse');

    if (navbarToggler && navbarCollapse) {
        navbarToggler.addEventListener('click', function() {
            setTimeout(() => {
                const isExpanded = navbarCollapse.classList.contains('show');
                navbarToggler.setAttribute('aria-expanded', isExpanded);
            }, 300);
        });
    }

    // Navbar scroll effect
    window.addEventListener('scroll', function() {
        const navbar = document.querySelector('.navbar');
        if (window.scrollY > 50) {
            navbar.classList.add('scrolled');
        } else {
            navbar.classList.remove('scrolled');
        }
    });

    // Smooth scroll for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            const href = this.getAttribute('href');
            if (href !== '#' && href !== '#navbarNav') {
                e.preventDefault();
                const target = document.querySelector(href);
                if (target) {
                    const navbarHeight = document.querySelector('.navbar').offsetHeight;
                    const targetPosition = target.offsetTop - navbarHeight - 20;
                    window.scrollTo({
                        top: targetPosition,
                        behavior: 'smooth'
                    });

                    // Close mobile menu if open
                    if (navbarCollapse.classList.contains('show')) {
                        navbarToggler.click();
                    }
                }
            }
        });
    });

    // Active nav link on scroll
    const sections = document.querySelectorAll('section[id]');
    window.addEventListener('scroll', function() {
        let current = '';
        sections.forEach(section => {
            const sectionTop = section.offsetTop;
            const sectionHeight = section.clientHeight;
            if (window.pageYOffset >= sectionTop - 100) {
                current = section.getAttribute('id');
            }
        });

        document.querySelectorAll('.nav-link').forEach(link => {
            link.classList.remove('active');
            if (link.getAttribute('href') === `#${current}`) {
                link.classList.add('active');
            }
        });
    });
</script>