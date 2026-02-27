<?php
include '../connection.php'; // Your database connection

// --- FETCH DATA FOR THE HOME PAGE ---
$properties_sql = "
    SELECT 
        p.property_ID, p.StreetAddress, p.City, p.Province, p.PropertyType, 
        p.Bedrooms, p.Bathrooms, p.SquareFootage, p.ListingPrice, p.Status, p.Likes,
        pi.PhotoURL,
        a.first_name, a.last_name
    FROM 
        property p
    LEFT JOIN 
        (SELECT property_ID, PhotoURL FROM property_images WHERE SortOrder = 1) pi ON p.property_ID = pi.property_ID
    JOIN 
        property_log pl ON p.property_ID = pl.property_id AND pl.action = 'CREATED'
    JOIN 
        accounts a ON pl.account_id = a.account_id
    WHERE 
        p.approval_status = 'approved' AND p.Status NOT IN ('Sold', 'Pending Sold')
    GROUP BY
        p.property_ID
    ORDER BY 
        p.ListingDate DESC
    LIMIT 9"; 

$properties_result = $conn->query($properties_sql);
$properties = $properties_result->fetch_all(MYSQLI_ASSOC);

// Fetch recently sold properties
$recently_sold_sql = "
    SELECT 
        p.property_ID, p.StreetAddress, p.City, p.Province, p.PropertyType, 
        p.Bedrooms, p.Bathrooms, p.SquareFootage, p.ListingPrice, p.Status, p.Likes,
        pi.PhotoURL,
        a.first_name, a.last_name,
        sv.reviewed_at as sold_date
    FROM 
        property p
    LEFT JOIN 
        (SELECT property_ID, PhotoURL FROM property_images WHERE SortOrder = 1) pi ON p.property_ID = pi.property_ID
    JOIN 
        property_log pl ON p.property_ID = pl.property_id AND pl.action = 'CREATED'
    JOIN 
        accounts a ON pl.account_id = a.account_id
    LEFT JOIN
        sale_verifications sv ON sv.property_id = p.property_ID AND sv.status = 'Approved'
    WHERE 
        p.approval_status = 'approved' AND p.Status = 'Sold'
    GROUP BY
        p.property_ID
    ORDER BY 
        sv.reviewed_at DESC
    LIMIT 6";

$recently_sold_result = $conn->query($recently_sold_sql);
$recently_sold_properties = $recently_sold_result->fetch_all(MYSQLI_ASSOC);

$cities_result = $conn->query("SELECT DISTINCT City FROM property WHERE approval_status = 'approved' ORDER BY City ASC");
$cities = $cities_result->fetch_all(MYSQLI_ASSOC);

// Get property counts by type and status
$sale_count_result = $conn->query("SELECT COUNT(*) as count FROM property WHERE Status = 'For Sale' AND approval_status = 'approved'");
$sale_count = $sale_count_result->fetch_assoc()['count'];

$rent_count_result = $conn->query("SELECT COUNT(*) as count FROM property WHERE Status = 'For Rent' AND approval_status = 'approved'");
$rent_count = $rent_count_result->fetch_assoc()['count'];

$agent_count_result = $conn->query("SELECT COUNT(*) as count FROM agent_information WHERE is_approved = 1");
$agent_count = $agent_count_result->fetch_assoc()['count'];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HomeEstate Realty - Find Your Dream Home</title>
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

        /* Hero Introduction Section */
        .hero-intro {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--black) 0%, var(--black-lighter) 100%);
            position: relative;
            padding: 100px 20px 60px;
            border-bottom: 1px solid rgba(37, 99, 235, 0.2);
            box-shadow: inset 0 -1px 0 rgba(212, 175, 55, 0.1);
        }

        .hero-intro::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: 
                radial-gradient(ellipse at top right, rgba(37, 99, 235, 0.06) 0%, transparent 50%),
                radial-gradient(ellipse at bottom left, rgba(212, 175, 55, 0.04) 0%, transparent 50%),
                linear-gradient(rgba(10, 10, 10, 0.85), rgba(10, 10, 10, 0.92)),
                url('../images/hero-bg.jpg');
            background-size: cover;
            background-position: center;
        }

        .hero-intro-content {
            position: relative;
            z-index: 2;
            text-align: center;
            max-width: 900px;
        }

        /* Ensure hero is centered and description text is justified */
        .hero-intro {
            justify-content: center; /* center the hero block horizontally */
        }

        .hero-intro-content {
            text-align: center; /* headings and CTAs remain centered */
            max-width: 900px;
            margin-left: auto;
            margin-right: auto;
        }

        .hero-intro-description {
            text-align: justify; /* make paragraph text justified for readability */
            text-justify: inter-word;
            margin-left: 0;
            margin-right: 0;
        }

        .hero-cta-buttons {
            justify-content: center; /* ensure buttons are centered */
        }

        .hero-intro-badge {
            display: inline-block;
            padding: 8px 20px;
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.08) 0%, rgba(37, 99, 235, 0.12) 100%);
            border: 1px solid rgba(37, 99, 235, 0.3);
            border-radius: 2px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: var(--blue-light);
            margin-bottom: 24px;
            box-shadow: 0 0 20px rgba(37, 99, 235, 0.1);
        }

        .hero-intro-title {
            font-size: 4.5rem;
            font-weight: 800;
            margin-bottom: 24px;
            line-height: 1.1;
            color: var(--white);
            text-shadow: 0 2px 20px rgba(0,0,0,0.4);
        }



        .hero-intro-title .gold-text {
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 50%, var(--gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            filter: drop-shadow(0 0 12px rgba(212, 175, 55, 0.3));
        }

        .hero-intro-description {
            font-size: 1.25rem;
            color: var(--gray-300);
            margin-bottom: 48px;
            line-height: 1.8;
            max-width: 700px;
            margin-left: auto;
            margin-right: auto;
        }

        .hero-cta-buttons {
            display: flex;
            gap: 16px;
            justify-content: center;
            flex-wrap: wrap;
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
            box-shadow: 0 4px 16px rgba(212, 175, 55, 0.25), 
                        0 0 0 1px rgba(212, 175, 55, 0.2);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .btn-primary-gold::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s ease;
        }

        .btn-primary-gold:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(212, 175, 55, 0.4), 
                        0 0 0 1px rgba(212, 175, 55, 0.4),
                        0 0 30px rgba(212, 175, 55, 0.2);
            color: var(--black);
        }

        .btn-primary-gold:hover::before {
            left: 100%;
        }

        .btn-outline-gold {
            background: transparent;
            color: var(--gray-200);
            border: 1px solid rgba(37, 99, 235, 0.4);
            padding: 14px 40px;
            font-size: 16px;
            font-weight: 700;
            border-radius: 2px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            box-shadow: 0 0 0 rgba(37, 99, 235, 0),
                        inset 0 0 0 rgba(37, 99, 235, 0);
        }

        .btn-outline-gold:hover {
            color: var(--blue-light);
            border-color: var(--blue);
            background: rgba(37, 99, 235, 0.08);
            box-shadow: 0 4px 16px rgba(37, 99, 235, 0.2),
                        inset 0 0 20px rgba(37, 99, 235, 0.1);
        }
        }

        /* Features Section */
        .features-section {
            padding: 100px 20px;
            background: linear-gradient(180deg, var(--black-light) 0%, #0d0d0d 100%);
            border-bottom: 1px solid rgba(212, 175, 55, 0.1);
            position: relative;
        }

        .features-section::before {
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

        .section-header {
            text-align: center;
            margin-bottom: 80px;
            position: relative;
            z-index: 1;
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
            margin-top: 3rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 0 20px rgba(37, 99, 235, 0.1);
        }

        .section-title {
            font-size: 3rem;
            font-weight: 800;
            color: var(--white);
            margin-bottom: 16px;
            text-shadow: 0 2px 20px rgba(0, 0, 0, 0.4);
        }

        .section-description {
            font-size: 1.125rem;
            color: var(--gray-300);
            max-width: 600px;
            margin: 0 auto;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 32px;
            max-width: 1200px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        .feature-card {
            background: linear-gradient(135deg, rgba(26, 26, 26, 0.8) 0%, rgba(10, 10, 10, 0.9) 100%);
            border: 1px solid rgba(37, 99, 235, 0.15);
            border-radius: 4px;
            padding: 40px 32px;
            text-align: center;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .feature-card::before {
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

        .feature-card:hover {
            border-color: rgba(37, 99, 235, 0.35);
            box-shadow: 0 12px 40px rgba(37, 99, 235, 0.15),
                        inset 0 0 30px rgba(37, 99, 235, 0.05);
            transform: translateY(-6px);
        }

        .feature-card:hover::before {
            opacity: 1;
        }

        .feature-icon {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 50%, var(--gold-dark) 100%);
            border-radius: 2px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            box-shadow: 0 4px 20px rgba(212, 175, 55, 0.3),
                        0 0 0 1px rgba(212, 175, 55, 0.2);
            transition: all 0.3s ease;
        }

        .feature-card:hover .feature-icon {
            box-shadow: 0 8px 30px rgba(212, 175, 55, 0.5),
                        0 0 0 1px rgba(212, 175, 55, 0.4),
                        0 0 40px rgba(212, 175, 55, 0.2);
            transform: scale(1.05);
        }

        .feature-icon i {
            font-size: 32px;
            color: var(--black);
        }

        .feature-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 12px;
        }

        .feature-description {
            font-size: 1rem;
            color: var(--gray-400);
            line-height: 1.6;
        }

        /* Stats Section */
        .stats-section {
            background: linear-gradient(180deg, 
                var(--black) 0%, 
                #0c0c0c 50%, 
                var(--black) 100%);
            padding: 80px 20px;
            border-top: 1px solid rgba(37, 99, 235, 0.15);
            border-bottom: 1px solid rgba(37, 99, 235, 0.15);
            position: relative;
        }

        .stats-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 50%, rgba(37, 99, 235, 0.03) 0%, transparent 50%),
                radial-gradient(circle at 80% 50%, rgba(212, 175, 55, 0.03) 0%, transparent 50%);
            pointer-events: none;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 48px;
            max-width: 1200px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        .stat-item {
            text-align: center;
            padding: 24px;
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.03) 0%, rgba(212, 175, 55, 0.02) 100%);
            border-radius: 4px;
            border: 1px solid rgba(37, 99, 235, 0.1);
            transition: all 0.3s ease;
        }

        .stat-item:hover {
            border-color: rgba(37, 99, 235, 0.25);
            box-shadow: 0 8px 32px rgba(37, 99, 235, 0.15),
                        inset 0 0 20px rgba(37, 99, 235, 0.05);
            transform: translateY(-4px);
        }

        .stat-number {
            font-size: 3.5rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 50%, var(--gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            display: block;
            margin-bottom: 8px;
            filter: drop-shadow(0 0 8px rgba(212, 175, 55, 0.2));
        }

        .stat-label {
            font-size: 1rem;
            color: var(--gray-400);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* CTA Section */
        .cta-section {
            margin-top: 5rem;
            padding: 100px 20px;
            background: 
                radial-gradient(circle at 30% 50%, rgba(37, 99, 235, 0.08) 0%, transparent 50%),
                radial-gradient(circle at 70% 50%, rgba(212, 175, 55, 0.08) 0%, transparent 50%),
                linear-gradient(135deg, var(--black-light) 0%, var(--black) 100%);
            border-top: 1px solid rgba(37, 99, 235, 0.2);
            border-bottom: 1px solid rgba(212, 175, 55, 0.3);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .cta-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, 
                transparent 0%, 
                rgba(37, 99, 235, 0.5) 25%, 
                rgba(212, 175, 55, 0.5) 75%, 
                transparent 100%);
        }

        .cta-content {
            max-width: 800px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        .cta-title {
            font-size: 3rem;
            font-weight: 800;
            margin-bottom: 24px;
            background: linear-gradient(135deg, 
                var(--white) 0%, 
                var(--gray-100) 40%, 
                var(--gold-light) 70%, 
                var(--gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            text-shadow: 0 2px 20px rgba(0,0,0,0.3);
        }

        .cta-description {
            font-size: 1.25rem;
            color: var(--gray-300);
            margin-bottom: 48px;
            line-height: 1.7;
        }

        /* Footer */
        .footer {
            background: linear-gradient(180deg, #0d0d0d 0%, var(--black-light) 100%);
            color: var(--gray-400);
            padding: 60px 20px 30px;
            border-top: 1px solid rgba(37, 99, 235, 0.1);
            position: relative;
        }

        .footer::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 25% 30%, rgba(37, 99, 235, 0.02) 0%, transparent 50%),
                radial-gradient(circle at 75% 70%, rgba(212, 175, 55, 0.02) 0%, transparent 50%);
            pointer-events: none;
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 48px;
            max-width: 1200px;
            margin: 0 auto 40px;
            position: relative;
            z-index: 1;
        }

        .footer-section h5 {
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 20px;
            font-weight: 700;
            font-size: 1.125rem;
        }

        .footer-section p {
            color: var(--gray-400);
            line-height: 1.8;
        }

        .footer-section ul {
            list-style: none;
        }

        .footer-section ul li {
            margin-bottom: 12px;
        }

        .footer-section a {
            color: var(--gray-400);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .footer-section a:hover {
            color: var(--blue-light);
        }

        .footer-bottom {
            border-top: 1px solid rgba(37, 99, 235, 0.15);
            padding-top: 30px;
            text-align: center;
            color: var(--gray-600);
            max-width: 1200px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        .social-links {
            display: flex;
            gap: 16px;
        }

        .social-links a {
            width: 40px;
            height: 40px;
            border: 1px solid rgba(37, 99, 235, 0.2);
            border-radius: 2px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gray-400);
            font-size: 18px;
            transition: all 0.3s ease;
            background: rgba(37, 99, 235, 0.03);
        }

        .social-links a:hover {
            border-color: var(--blue);
            color: var(--blue-light);
            background: rgba(37, 99, 235, 0.1);
            box-shadow: 0 4px 16px rgba(37, 99, 235, 0.2);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .hero-intro {
                padding: 80px 20px 40px;
                min-height: 90vh;
            }

            .hero-intro-title {
                font-size: 2.5rem;
            }

            .hero-intro-description {
                font-size: 1rem;
            }

            .hero-cta-buttons {
                flex-direction: column;
            }

            .btn-primary-gold,
            .btn-outline-gold {
                width: 100%;
                justify-content: center;
            }

            .section-title {
                font-size: 2rem;
            }

            .features-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 32px;
            }

            .stat-number {
                font-size: 2.5rem;
            }

            .cta-title {
                font-size: 2rem;
            }

            .footer-content {
                grid-template-columns: 1fr;
                gap: 32px;
            }
        }

        @media (max-width: 576px) {
            .hero-intro-title {
                font-size: 2rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<!-- Include Navbar -->
<?php include 'navbar.php'; ?>

<main>
    <!-- Hero Introduction Section -->
    <section class="hero-intro">
        <div class="container">
            <div class="hero-intro-content">
                <div class="hero-intro-badge">Premium Real Estate Platform</div>
                
                <h1 class="hero-intro-title">
                    Discover Your Perfect <span class="gold-text">Home</span>
                </h1>
                
                <p class="hero-intro-description">
                    HomeEstate Realty connects you with exceptional homes in the most desirable locations. 
                    Whether you're buying, selling, or renting, we provide a seamless experience backed by expert agents and cutting-edge technology.
                </p>
                
                <div class="hero-cta-buttons">
                    <a href="search_results.php" class="btn-primary-gold">
                        <i class="bi bi-search"></i>
                        Explore Properties
                    </a>
                    <a href="about.php" class="btn-outline-gold">
                        <i class="bi bi-info-circle"></i>
                        Learn More
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="stats-section">
        <div class="container">
            <div class="stats-grid">
                <div class="stat-item">
                    <span class="stat-number"><?php echo number_format($sale_count); ?>+</span>
                    <span class="stat-label">For Sale</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number"><?php echo number_format($rent_count); ?>+</span>
                    <span class="stat-label">For Rent</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number"><?php echo number_format($agent_count); ?>+</span>
                    <span class="stat-label">Expert Agents</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number"><?php echo number_format($sale_count + $rent_count); ?>+</span>
                    <span class="stat-label">Total Listings</span>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="features-section">
        <div class="container">
            <div class="section-header">
                <div class="section-badge">Why Choose Us</div>
                <h2 class="section-title">Your Trusted Real Estate Partner</h2>
                <p class="section-description">
                    We combine cutting-edge technology with personalized service to deliver an unmatched real estate experience.
                </p>
            </div>
            
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="bi bi-house-heart"></i>
                    </div>
                    <h3 class="feature-title">Curated Listings</h3>
                    <p class="feature-description">
                        Every property is carefully vetted and verified by our team to ensure quality and authenticity.
                    </p>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="bi bi-people"></i>
                    </div>
                    <h3 class="feature-title">Expert Agents</h3>
                    <p class="feature-description">
                        Work with experienced, certified agents who understand your needs and local market dynamics.
                    </p>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="bi bi-shield-check"></i>
                    </div>
                    <h3 class="feature-title">Secure Transactions</h3>
                    <p class="feature-description">
                        Advanced security measures and verified documentation ensure safe, transparent transactions.
                    </p>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="bi bi-search"></i>
                    </div>
                    <h3 class="feature-title">Smart Search</h3>
                    <p class="feature-description">
                        Find your perfect property with powerful filters and intelligent recommendations.
                    </p>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="bi bi-graph-up-arrow"></i>
                    </div>
                    <h3 class="feature-title">Market Insights</h3>
                    <p class="feature-description">
                        Access real-time market data and trends to make informed investment decisions.
                    </p>
                </div>
                
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="bi bi-headset"></i>
                    </div>
                    <h3 class="feature-title">24/7 Support</h3>
                    <p class="feature-description">
                        Our dedicated support team is always available to assist you throughout your journey.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="cta-section">
        <div class="container">
            <div class="cta-content">
                <h2 class="cta-title">Ready to Find Your Dream Home?</h2>
                <p class="cta-description">
                    Join thousands of satisfied clients who found their perfect property with HomeEstate Realty. 
                    Start your journey today.
                </p>
                <div class="hero-cta-buttons">
                    <a href="search_results.php" class="btn-primary-gold">
                        <i class="bi bi-house-door"></i>
                        Browse Properties
                    </a>
                    <a href="agents.php" class="btn-outline-gold">
                        <i class="bi bi-telephone"></i>
                        Contact an Agent
                    </a>
                </div>
            </div>
        </div>
    </section>
</main>

<!-- Footer -->
<footer class="footer">
    <div class="container">
        <div class="footer-content">
            <div class="footer-section">
                <h5>HomeEstate Realty</h5>
                <p>Your trusted partner in finding the perfect home. We connect buyers, sellers, and renters with their dream properties through innovation and expertise.</p>
            </div>
            <div class="footer-section">
                <h5>Quick Links</h5>
                <ul class="list-unstyled">
                    <li><a href="search_results.php">Browse Properties</a></li>
                    <li><a href="search_results.php?status=For+Sale">Buy</a></li>
                    <li><a href="search_results.php?status=For+Rent">Rent</a></li>
                    <li><a href="about.php">About Us</a></li>
                </ul>
            </div>
            <div class="footer-section">
                <h5>Resources</h5>
                <ul class="list-unstyled">
                    <li><a href="#">Market Insights</a></li>
                    <li><a href="agents.php">Agent Directory</a></li>
                    <li><a href="#">Help Center</a></li>
                    <li><a href="#">Contact Support</a></li>
                </ul>
            </div>
            <div class="footer-section">
                <h5>Connect With Us</h5>
                <div class="social-links">
                    <a href="#" aria-label="Facebook"><i class="bi bi-facebook"></i></a>
                    <a href="#" aria-label="Twitter"><i class="bi bi-twitter"></i></a>
                    <a href="#" aria-label="Instagram"><i class="bi bi-instagram"></i></a>
                    <a href="#" aria-label="LinkedIn"><i class="bi bi-linkedin"></i></a>
                </div>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; <?php echo date('Y'); ?> HomeEstate Realty. All rights reserved.</p>
        </div>
    </div>
</footer>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Smooth scroll for anchor links (minimal implementation)
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            const href = this.getAttribute('href');
            if (href !== '#' && href.length > 1) {
                e.preventDefault();
                const target = document.querySelector(href);
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth' });
                }
            }
        });
    });
</script>

</body>
</html>