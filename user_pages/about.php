<?php
include '../connection.php';

// Fetch stats for about page
$properties_count_result = $conn->query("SELECT COUNT(*) as count FROM property WHERE approval_status = 'approved'");
$properties_count = $properties_count_result->fetch_assoc()['count'];

$agents_count_result = $conn->query("SELECT COUNT(*) as count FROM agent_information WHERE is_approved = 1");
$agents_count = $agents_count_result->fetch_assoc()['count'];

$sold_count_result = $conn->query("SELECT COUNT(*) as count FROM property WHERE Status = 'Sold' AND approval_status = 'approved'");
$sold_count = $sold_count_result->fetch_assoc()['count'];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About Us - HomeEstate Realty</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

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
            --white: #ffffff;
            
            /* Semantic Grays */
            --gray-100: #e8e9eb;
            --gray-200: #d1d4d7;
            --gray-300: #b8bec4;
            --gray-400: #9ca4ab;
            --gray-500: #7a8a99;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background-color: var(--black);
            line-height: 1.6;
            color: var(--white);
            overflow-x: hidden;
        }

        /* Hero Section */
        .about-hero {
            min-height: 65vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--black) 0%, var(--black-lighter) 100%);
            position: relative;
            padding: 140px 20px 80px;
            border-bottom: 1px solid rgba(37, 99, 235, 0.2);
        }

        .about-hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: 
                radial-gradient(ellipse at top right, rgba(37, 99, 235, 0.08) 0%, transparent 50%),
                radial-gradient(ellipse at bottom left, rgba(212, 175, 55, 0.06) 0%, transparent 50%),
                linear-gradient(rgba(10, 10, 10, 0.88), rgba(10, 10, 10, 0.94)),
                url('../images/hero-bg2.jpg');
            background-size: cover;
            background-position: center;
        }

        .hero-content {
            position: relative;
            z-index: 2;
            text-align: center;
            max-width: 900px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        /* Ensure the bootstrap container inside hero centers its content */
        .about-hero .container {
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .hero-badge {
            display: inline-block;
            padding: 8px 20px;
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.1) 0%, rgba(37, 99, 235, 0.15) 100%);
            border: 1px solid rgba(37, 99, 235, 0.3);
            border-radius: 2px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: var(--blue-light);
            margin-bottom: 24px;
        }

        .hero-title {
            font-size: 4rem;
            font-weight: 800;
            margin-bottom: 24px;
            line-height: 1.1;
            color: var(--white);
            text-shadow: 0 2px 20px rgba(0,0,0,0.4);
        }

        .hero-title .gold-text {
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 50%, var(--gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero-subtitle {
            font-size: 1.25rem;
            color: var(--gray-300);
            line-height: 1.8;
            max-width: 700px;
            margin: 0 auto;
            text-align: center;
            text-justify: inter-word;
        }

        /* Story Section */
        .story-section {
            padding: 100px 20px;
            background: linear-gradient(180deg, var(--black-light) 0%, #0d0d0d 100%);
            position: relative;
        }

        .story-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 10% 20%, rgba(37, 99, 235, 0.04) 0%, transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(212, 175, 55, 0.04) 0%, transparent 40%);
            pointer-events: none;
        }

        .section-badge {
            display: inline-block;
            padding: 6px 16px;
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.08) 0%, rgba(37, 99, 235, 0.12) 100%);
            border: 1px solid rgba(37, 99, 235, 0.3);
            border-radius: 2px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: var(--blue-light);
            margin-bottom: 16px;
        }

        .section-title {
            font-size: 3rem;
            font-weight: 800;
            color: var(--white);
            margin-bottom: 24px;
        }

        .section-description {
            font-size: 1.125rem;
            color: var(--gray-300);
            line-height: 1.8;
            max-width: 800px;
            margin: 0 auto 60px;
            text-align: justify;
            text-justify: inter-word;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 24px;
            max-width: 900px;
            margin: 60px auto;
            position: relative;
            z-index: 1;
        }

        .stat-card {
            background: linear-gradient(135deg, rgba(26, 26, 26, 0.6) 0%, rgba(10, 10, 10, 0.8) 100%);
            border: 1px solid rgba(37, 99, 235, 0.15);
            border-radius: 4px;
            padding: 32px 24px;
            text-align: center;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            border-color: rgba(37, 99, 235, 0.35);
            box-shadow: 0 8px 24px rgba(37, 99, 235, 0.12);
            transform: translateY(-4px);
        }

        .stat-number {
            font-size: 3rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 8px;
            display: block;
        }

        .stat-label {
            font-size: 0.95rem;
            color: var(--gray-400);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Values Section */
        .values-section {
            padding: 100px 20px;
            background: var(--black);
            border-top: 1px solid rgba(212, 175, 55, 0.1);
        }

        .values-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 32px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .value-card {
            background: linear-gradient(135deg, rgba(26, 26, 26, 0.5) 0%, rgba(10, 10, 10, 0.7) 100%);
            border: 1px solid rgba(37, 99, 235, 0.12);
            border-radius: 4px;
            padding: 40px 32px;
            text-align: center;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .value-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--blue), transparent);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .value-card:hover {
            border-color: rgba(37, 99, 235, 0.3);
            box-shadow: 0 8px 24px rgba(37, 99, 235, 0.1);
            transform: translateY(-4px);
        }

        .value-card:hover::before {
            opacity: 1;
        }

        .value-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 24px;
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.12) 0%, rgba(37, 99, 235, 0.08) 100%);
            border: 1px solid rgba(37, 99, 235, 0.25);
            border-radius: 2px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: var(--blue-light);
        }

        .value-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 12px;
        }

        .value-description {
            font-size: 1rem;
            color: var(--gray-400);
            line-height: 1.6;
        }

        /* CTA Section */
        .cta-section {
            padding: 100px 20px;
            background: linear-gradient(135deg, var(--black-lighter) 0%, var(--black) 100%);
            border-top: 1px solid rgba(212, 175, 55, 0.15);
            text-align: center;
        }

        .cta-content {
            max-width: 700px;
            margin: 0 auto;
        }

        .cta-title {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--white);
            margin-bottom: 24px;
        }

        .cta-description {
            font-size: 1.125rem;
            color: var(--gray-300);
            margin-bottom: 40px;
            line-height: 1.7;
        }

        .btn-primary-gold {
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 50%, var(--gold-dark) 100%);
            color: var(--black);
            border: none;
            padding: 16px 40px;
            font-size: 16px;
            font-weight: 700;
            border-radius: 2px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 16px rgba(212, 175, 55, 0.25);
            transition: all 0.3s ease;
        }

        .btn-primary-gold:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(212, 175, 55, 0.4);
            color: var(--black);
        }

        /* Footer */
        .footer {
            background: var(--black-light);
            padding: 60px 20px 30px;
            border-top: 1px solid rgba(37, 99, 235, 0.15);
        }

        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
        }

        .footer-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 12px;
        }

        .footer-text {
            color: var(--gray-400);
            margin-bottom: 8px;
        }

        .footer-divider {
            height: 1px;
            background: rgba(37, 99, 235, 0.1);
            margin: 40px 0 30px;
        }

        .footer-bottom {
            text-align: center;
            color: var(--gray-500);
            font-size: 0.9rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .hero-title {
                font-size: 2.5rem;
            }

            .section-title {
                font-size: 2rem;
            }

            .cta-title {
                font-size: 1.75rem;
            }

            .values-grid,
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .about-hero {
                min-height: 55vh;
                padding: 120px 20px 60px;
            }
        }
    </style>
</head>
<body>

<!-- Include Navbar -->
<?php include 'navbar.php'; ?>

<!-- About Hero Section -->
<section class="about-hero">
    <div class="container">
        <div class="hero-content">
            <div class="hero-badge">About the Platform</div>
            <h1 class="hero-title">
                HomeEstate <span class="gold-text">Realty</span>
            </h1>
            <p class="hero-subtitle">
                A comprehensive digital platform designed to streamline property listings, 
                agent management, and client interactions in the modern real estate market.
            </p>
        </div>
    </div>
</section>

<!-- System Overview Section -->
<section class="story-section">
    <div class="container">
        <div class="text-center mb-5">
            <div class="section-badge">System Overview</div>
            <h2 class="section-title">What is HomeEstate Realty?</h2>
            <p class="section-description">
                HomeEstate Realty is an advanced real estate platform that connects property seekers 
                with professional agents and quality listings. Our system provides a seamless experience 
                for browsing properties, scheduling tours, and managing real estate transactions. 
                Whether you're searching for your dream home or listing properties as an agent, 
                our platform offers the tools and features you need for success.
            </p>
        </div>

        <!-- Platform Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-number"><?php echo number_format($properties_count); ?>+</span>
                <div class="stat-label">Active Listings</div>
            </div>
            <div class="stat-card">
                <span class="stat-number"><?php echo number_format($sold_count); ?>+</span>
                <div class="stat-label">Successful Transactions</div>
            </div>
            <div class="stat-card">
                <span class="stat-number"><?php echo number_format($agents_count); ?>+</span>
                <div class="stat-label">Verified Agents</div>
            </div>
        </div>
    </div>
</section>

<!-- Key Features Section -->
<section class="values-section">
    <div class="container">
        <div class="text-center mb-5">
            <div class="section-badge">Platform Features</div>
            <h2 class="section-title">Powerful Features for Everyone</h2>
            <p class="section-description">
                Our system is designed with three distinct user roles, each with tailored features 
                to maximize efficiency and user satisfaction.
            </p>
        </div>

        <div class="values-grid">
            <div class="value-card">
                <div class="value-icon">
                    <i class="bi bi-search"></i>
                </div>
                <h3 class="value-title">Advanced Property Search</h3>
                <p class="value-description">
                    Smart filters for property type, price range, location, bedrooms, and more. 
                    Find your perfect property with precision and ease.
                </p>
            </div>

            <div class="value-card">
                <div class="value-icon">
                    <i class="bi bi-images"></i>
                </div>
                <h3 class="value-title">Interactive Galleries</h3>
                <p class="value-description">
                    High-quality image galleries with lightbox views, floor plan displays, and 
                    multiple image management for comprehensive property visualization.
                </p>
            </div>

            <div class="value-card">
                <div class="value-icon">
                    <i class="bi bi-calendar-check"></i>
                </div>
                <h3 class="value-title">Tour Scheduling</h3>
                <p class="value-description">
                    Request property tours directly through the platform. Agents can manage, 
                    accept, or reschedule tours with built-in conflict detection.
                </p>
            </div>

            <div class="value-card">
                <div class="value-icon">
                    <i class="bi bi-person-badge"></i>
                </div>
                <h3 class="value-title">Agent Management</h3>
                <p class="value-description">
                    Dedicated agent profiles with verification system, property portfolio management, 
                    and performance tracking capabilities.
                </p>
            </div>

            <div class="value-card">
                <div class="value-icon">
                    <i class="bi bi-shield-lock"></i>
                </div>
                    <h3 class="value-title">Quality Assurance</h3>
                <p class="value-description">
                    All listings and agents are verified to ensure you're working with 
                    trusted professionals and authentic properties.
                </p>
            </div>

            <div class="value-card">
                <div class="value-icon">
                    <i class="bi bi-file-earmark-text"></i>
                </div>
                    <h3 class="value-title">Easy Communication</h3>
                <p class="value-description">
                    Connect directly with agents and property owners through our streamlined 
                    contact and messaging system.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- How It Works Section -->
<section class="story-section" style="background: var(--black);">
    <div class="container">
        <div class="text-center mb-5">
            <div class="section-badge">User Workflow</div>
            <h2 class="section-title">How the System Works</h2>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="value-card">
                    <div class="value-icon">
                        <i class="bi bi-1-circle"></i>
                    </div>
                    <h3 class="value-title">For Property Seekers</h3>
                    <p class="value-description" style="text-align: left;">
                        • Browse properties without registration<br>
                        • Use advanced filters to narrow searches<br>
                        • View detailed property information & images<br>
                        • Like favorite properties for later<br>
                        • Request property tours<br>
                        • Contact agents directly
                    </p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="value-card">
                    <div class="value-icon">
                        <i class="bi bi-2-circle"></i>
                    </div>
                    <h3 class="value-title">For Real Estate Agents</h3>
                    <p class="value-description" style="text-align: left;">
                        • Register and submit verification documents<br>
                        • List new properties with images & details<br>
                        • Manage property portfolios<br>
                        • Handle tour requests and scheduling<br>
                        • Update property prices and status<br>
                        • Submit sale verification documents
                    </p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="value-card">
                    <div class="value-icon">
                        <i class="bi bi-3-circle"></i>
                    </div>
                    <h3 class="value-title">For Everyone</h3>
                    <p class="value-description" style="text-align: left;">
                        • User-friendly interface and navigation<br>
                        • Save favorite properties for later<br>
                        • Track property view history<br>
                        • Receive updates on new listings<br>
                        • Access from any device, anywhere<br>
                        • Get support when you need it
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Technology Stack Section -->
<section class="values-section" style="border-top: 1px solid rgba(37, 99, 235, 0.1);">
    <div class="container">
        <div class="text-center mb-5">
            <div class="section-badge">Why Choose Us</div>
            <h2 class="section-title">Built for Excellence</h2>
            <p class="section-description">
                Our platform is developed using modern web technologies to ensure reliability, 
                security, and optimal performance for all users.
            </p>
        </div>

        <div class="values-grid" style="grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));">
            <div class="value-card">
                <div class="value-icon">
                    <i class="bi bi-phone"></i>
                </div>
                <h3 class="value-title">Mobile Responsive</h3>
                <p class="value-description">
                    Access the platform seamlessly from any device—desktop, tablet, or smartphone.
                </p>
            </div>

            <div class="value-card">
                <div class="value-icon">
                    <i class="bi bi-lightning"></i>
                </div>
                <h3 class="value-title">Fast & Reliable</h3>
                <p class="value-description">
                    Optimized performance ensures quick loading times and smooth user experience.
                </p>
            </div>

            <div class="value-card">
                <div class="value-icon">
                    <i class="bi bi-shield-check"></i>
                </div>
                <h3 class="value-title">Secure Platform</h3>
                <p class="value-description">
                    Your data is protected with industry-standard security measures and encryption.
                </p>
            </div>

            <div class="value-card">
                <div class="value-icon">
                    <i class="bi bi-clock-history"></i>
                </div>
                <h3 class="value-title">24/7 Availability</h3>
                <p class="value-description">
                    Browse properties and manage listings anytime, from anywhere in the world.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- CTA Section -->
<section class="cta-section">
    <div class="container">
        <div class="cta-content">
            <h2 class="cta-title">Start Exploring Properties Today</h2>
            <p class="cta-description">
                Experience the power of our real estate management system. 
                Browse available properties and find your dream home.
            </p>
            <div style="display: flex; gap: 16px; justify-content: center; flex-wrap: wrap;">
                <a href="index.php" class="btn-primary-gold">
                    Browse Properties
                    <i class="bi bi-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>
</section>

<!-- Footer -->
<footer class="footer">
    <div class="footer-content">
        <div class="row">
            <div class="col-md-6">
                <h5 class="footer-title">HomeEstate Realty</h5>
                <p class="footer-text">Building dreams, creating legacies.</p>
                <p class="footer-text">Your trusted partner in real estate.</p>
            </div>
            <div class="col-md-6 text-md-end">
                <p class="footer-text">Contact us for inquiries</p>
                <p class="footer-text">Email: info@homeestate.com</p>
            </div>
        </div>
        <div class="footer-divider"></div>
        <div class="footer-bottom">
            <p>&copy; 2024 HomeEstate Realty. All rights reserved.</p>
        </div>
    </div>
</footer>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>