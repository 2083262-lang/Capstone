<?php
include '../connection.php';

// --- FETCH DATA FOR THE HOME PAGE ---

// Featured properties — latest approved listings
$featured_sql = "
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
    LIMIT 6";

$featured_result = $conn->query($featured_sql);
$featured_properties = $featured_result->fetch_all(MYSQLI_ASSOC);

// Cities for search dropdown
$cities_result = $conn->query("SELECT DISTINCT City FROM property WHERE approval_status = 'approved' ORDER BY City ASC");
$cities = $cities_result->fetch_all(MYSQLI_ASSOC);

// Property types for search
$types_result = $conn->query("SELECT type_name AS PropertyType FROM property_types ORDER BY type_name ASC");
$property_types = $types_result->fetch_all(MYSQLI_ASSOC);

// Counts
$sale_count_result = $conn->query("SELECT COUNT(*) as count FROM property WHERE Status = 'For Sale' AND approval_status = 'approved'");
$sale_count = $sale_count_result->fetch_assoc()['count'];

$rent_count_result = $conn->query("SELECT COUNT(*) as count FROM property WHERE Status = 'For Rent' AND approval_status = 'approved'");
$rent_count = $rent_count_result->fetch_assoc()['count'];

$agent_count_result = $conn->query("SELECT COUNT(*) as count FROM agent_information WHERE is_approved = 1");
$agent_count = $agent_count_result->fetch_assoc()['count'];

$sold_count_result = $conn->query("SELECT COUNT(*) as count FROM property WHERE Status = 'Sold' AND approval_status = 'approved'");
$sold_count = $sold_count_result->fetch_assoc()['count'];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HomeEstate Realty - Find a Place to Call Home</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        :root {
            --gold: #d4af37;
            --gold-light: #f4d03f;
            --gold-dark: #b8941f;
            --blue: #2563eb;
            --blue-light: #3b82f6;
            --blue-dark: #1e40af;
            --black: #0a0a0a;
            --black-light: #111111;
            --black-lighter: #1a1a1a;
            --black-border: #1f1f1f;
            --white: #ffffff;
            --gray-100: #e8e9eb;
            --gray-200: #d1d4d7;
            --gray-300: #b8bec4;
            --gray-400: #9ca4ab;
            --gray-500: #7a8a99;
            --gray-600: #5d6d7d;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background-color: var(--black);
            line-height: 1.6;
            color: var(--white);
            overflow-x: hidden;
        }

        /* ============================
           SCROLL REVEAL ANIMATION SYSTEM
        ============================ */
        .reveal {
            opacity: 0;
            transform: translateY(40px);
            transition: opacity 0.8s cubic-bezier(0.16, 1, 0.3, 1),
                        transform 0.8s cubic-bezier(0.16, 1, 0.3, 1);
            will-change: opacity, transform;
        }

        .reveal.reveal-left {
            transform: translateX(-50px);
        }

        .reveal.reveal-right {
            transform: translateX(50px);
        }

        .reveal.reveal-scale {
            transform: scale(0.92);
        }

        .reveal.is-visible {
            opacity: 1;
            transform: translateY(0) translateX(0) scale(1);
        }

        /* Stagger delays for children */
        .stagger-children .reveal:nth-child(1) { transition-delay: 0s; }
        .stagger-children .reveal:nth-child(2) { transition-delay: 0.1s; }
        .stagger-children .reveal:nth-child(3) { transition-delay: 0.2s; }
        .stagger-children .reveal:nth-child(4) { transition-delay: 0.3s; }
        .stagger-children .reveal:nth-child(5) { transition-delay: 0.4s; }
        .stagger-children .reveal:nth-child(6) { transition-delay: 0.5s; }

        /* Hero entrance cascade */
        .hero-welcome-inner > * {
            opacity: 0;
            transform: translateY(30px);
            animation: heroFadeUp 0.9s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        .hero-welcome-inner > *:nth-child(1) { animation-delay: 0.15s; }
        .hero-welcome-inner > *:nth-child(2) { animation-delay: 0.3s; }
        .hero-welcome-inner > *:nth-child(3) { animation-delay: 0.45s; }
        .hero-welcome-inner > *:nth-child(4) { animation-delay: 0.6s; }
        .hero-welcome-inner > *:nth-child(5) { animation-delay: 0.75s; }

        @keyframes heroFadeUp {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Subtle floating animation for hero background */
        @keyframes subtleFloat {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.03); }
        }

        .hero-welcome::before {
            animation: subtleFloat 20s ease-in-out infinite;
        }

        /* Section divider line */
        .section-divider {
            width: 60px;
            height: 2px;
            background: linear-gradient(90deg, var(--gold-dark), var(--gold), var(--gold-dark));
            margin: 20px auto 0;
            border-radius: 1px;
            opacity: 0.6;
        }

        /* Smooth link underline effect */
        .property-view-link i {
            transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .property-card:hover .property-view-link i {
            transform: translateX(4px);
        }

        /* ============================
           HERO — Warm Welcome
        ============================ */
        .hero-welcome {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            padding: 120px 20px 80px;
            border-bottom: 1px solid rgba(37, 99, 235, 0.2);
            box-shadow: inset 0 -1px 0 rgba(212, 175, 55, 0.1);
            overflow: hidden;
        }

        .hero-welcome::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background-image:
                radial-gradient(ellipse at top right, rgba(212, 175, 55, 0.05) 0%, transparent 50%),
                radial-gradient(ellipse at bottom left, rgba(37, 99, 235, 0.04) 0%, transparent 50%),
                linear-gradient(rgba(10, 10, 10, 0.82), rgba(10, 10, 10, 0.90)),
                /* IMAGE: A warm, softly-lit living room scene — cozy couch, warm golden 
                   lamplight, a family photo on a shelf, or a window view at sunset. 
                   The image should feel inviting, peaceful, and homey. */
                url('../images/HomePagePic1.jpg');
            background-size: cover;
            background-position: center;
        }

        .hero-welcome-inner {
            position: relative;
            z-index: 2;
            text-align: center;
            max-width: 820px;
            margin: 0 auto;
        }

        /* Soft ambient glow behind hero text */
        .hero-welcome-inner::before {
            content: '';
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 600px; height: 400px;
            background: radial-gradient(ellipse, rgba(212, 175, 55, 0.06) 0%, transparent 70%);
            pointer-events: none;
            z-index: -1;
        }

        .hero-welcome-badge {
            display: inline-block;
            padding: 8px 20px;
            background: linear-gradient(135deg, rgba(212, 175, 55, 0.08) 0%, rgba(212, 175, 55, 0.14) 100%);
            border: 1px solid rgba(212, 175, 55, 0.25);
            border-radius: 2px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: var(--gold);
            margin-bottom: 28px;
        }

        .hero-welcome-title {
            font-size: 4rem;
            font-weight: 800;
            margin-bottom: 24px;
            line-height: 1.15;
            color: var(--white);
            text-shadow: 0 2px 20px rgba(0,0,0,0.4);
        }

        .hero-welcome-title .gold-text {
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 50%, var(--gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            filter: drop-shadow(0 0 10px rgba(212, 175, 55, 0.25));
        }

        .hero-welcome-subtitle {
            font-size: 1.2rem;
            color: var(--gray-300);
            line-height: 1.85;
            max-width: 640px;
            margin: 0 auto 44px;
        }

        /* Search Bar in Hero */
        .hero-search-bar {
            max-width: 700px;
            margin: 0 auto 36px;
            background: rgba(17, 17, 17, 0.85);
            border: 1px solid rgba(37, 99, 235, 0.2);
            border-radius: 6px;
            padding: 8px;
            display: flex;
            gap: 8px;
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            transition: border-color 0.4s ease, box-shadow 0.4s ease;
        }

        .hero-search-bar:focus-within {
            border-color: rgba(37, 99, 235, 0.45);
            box-shadow: 0 4px 24px rgba(37, 99, 235, 0.12);
        }

        .hero-search-bar select,
        .hero-search-bar input[type="text"] {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 2px;
            color: var(--white);
            font-size: 0.9375rem;
            font-family: inherit;
            padding: 12px 16px;
            flex: 1;
            min-width: 0;
            transition: border-color 0.2s ease;
        }

        .hero-search-bar select:focus,
        .hero-search-bar input[type="text"]:focus {
            outline: none;
            border-color: rgba(37, 99, 235, 0.4);
        }

        .hero-search-bar select option {
            background: var(--black-light);
            color: var(--white);
        }

        .hero-search-btn {
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 50%, var(--gold-dark) 100%);
            color: var(--black);
            border: none;
            padding: 12px 28px;
            font-size: 15px;
            font-weight: 700;
            border-radius: 2px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 16px rgba(212, 175, 55, 0.25);
            transition: all 0.3s ease;
            white-space: nowrap;
        }

        .hero-search-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(212, 175, 55, 0.35);
        }

        .hero-quick-links {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .hero-quick-link {
            color: var(--gray-400);
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            padding: 6px 16px;
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 2px;
            transition: all 0.3s ease;
        }

        .hero-quick-link:hover {
            color: var(--blue-light);
            border-color: rgba(37, 99, 235, 0.3);
            background: rgba(37, 99, 235, 0.06);
        }

        /* ============================
           SECTION HELPERS (reused)
        ============================ */
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
            font-size: 2.75rem;
            font-weight: 800;
            color: var(--white);
            margin-bottom: 16px;
        }

        .section-title .gold-text {
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 50%, var(--gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .section-description {
            font-size: 1.1rem;
            color: var(--gray-300);
            max-width: 620px;
            margin: 0 auto;
            line-height: 1.75;
        }

        /* ============================
           BELONG SECTION — Emotional visual strip
        ============================ */
        .belong-section {
            padding: 100px 20px;
            background: linear-gradient(180deg, var(--black-light) 0%, var(--black) 100%);
            border-bottom: 1px solid rgba(212, 175, 55, 0.1);
            position: relative;
        }

        .belong-section::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background:
                radial-gradient(circle at 15% 50%, rgba(212, 175, 55, 0.03) 0%, transparent 45%),
                radial-gradient(circle at 85% 50%, rgba(37, 99, 235, 0.03) 0%, transparent 45%);
            pointer-events: none;
        }

        .belong-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 60px;
            max-width: 1100px;
            margin: 0 auto;
            align-items: center;
            position: relative;
            z-index: 1;
        }

        .belong-image-container {
            position: relative;
            border-radius: 4px;
            overflow: hidden;
            border: 1px solid rgba(37, 99, 235, 0.15);
        }

        .belong-image-container img {
            width: 100%;
            height: 420px;
            object-fit: cover;
            display: block;
            transition: transform 0.5s ease;
        }

        .belong-image-container:hover img {
            transform: scale(1.03);
        }

        .belong-image-overlay {
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 40%;
            background: linear-gradient(to top, rgba(10, 10, 10, 0.7), transparent);
            pointer-events: none;
        }

        .belong-text h2 {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--white);
            margin-bottom: 20px;
            line-height: 1.2;
        }

        .belong-text h2 .gold-text {
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 50%, var(--gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .belong-text p {
            font-size: 1.05rem;
            color: var(--gray-300);
            line-height: 1.85;
            margin-bottom: 32px;
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
            top: 0; left: -100%;
            width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.25), transparent);
            transition: left 0.5s ease;
        }

        .btn-primary-gold:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(212, 175, 55, 0.4),
                        0 0 0 1px rgba(212, 175, 55, 0.4);
            color: var(--black);
        }

        .btn-primary-gold:hover::before { left: 100%; }

        .btn-outline-blue {
            background: transparent;
            color: var(--gray-200);
            border: 1px solid rgba(37, 99, 235, 0.4);
            padding: 14px 36px;
            font-size: 16px;
            font-weight: 700;
            border-radius: 2px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .btn-outline-blue:hover {
            color: var(--blue-light);
            border-color: var(--blue);
            background: rgba(37, 99, 235, 0.08);
            box-shadow: 0 4px 16px rgba(37, 99, 235, 0.2);
        }

        /* ============================
           FEATURED PROPERTIES — Gentle showcase
        ============================ */
        .featured-section {
            padding: 100px 20px;
            background: linear-gradient(180deg, var(--black) 0%, var(--black-light) 50%, var(--black) 100%);
            position: relative;
            border-bottom: 1px solid rgba(37, 99, 235, 0.1);
        }

        .featured-section::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background:
                radial-gradient(circle at 20% 30%, rgba(37, 99, 235, 0.03) 0%, transparent 40%),
                radial-gradient(circle at 80% 70%, rgba(212, 175, 55, 0.03) 0%, transparent 40%);
            pointer-events: none;
        }

        .featured-header {
            text-align: center;
            margin-bottom: 60px;
            position: relative;
            z-index: 1;
        }

        .properties-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 28px;
            max-width: 1200px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        .property-card {
            background: linear-gradient(135deg, rgba(26, 26, 26, 0.6) 0%, rgba(10, 10, 10, 0.8) 100%);
            border: 1px solid rgba(37, 99, 235, 0.12);
            border-radius: 6px;
            overflow: hidden;
            transition: all 0.45s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
        }

        .property-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), transparent);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .property-card:hover {
            border-color: rgba(37, 99, 235, 0.3);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.35), 0 8px 24px rgba(37, 99, 235, 0.12);
            transform: translateY(-8px);
        }

        .property-card:hover::before { opacity: 1; }

        .property-card-link {
            text-decoration: none;
            color: inherit;
            display: block;
        }

        .property-image-wrap {
            position: relative;
            height: 220px;
            overflow: hidden;
        }

        .property-image-wrap img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.4s ease;
        }

        .property-card:hover .property-image-wrap img {
            transform: scale(1.05);
        }

        .property-status-badge {
            position: absolute;
            top: 14px; left: 14px;
            padding: 5px 14px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-radius: 2px;
            background: linear-gradient(135deg, var(--gold-dark), var(--gold));
            color: var(--black);
            box-shadow: 0 2px 8px rgba(212, 175, 55, 0.3);
        }

        .property-status-badge.for-rent {
            background: linear-gradient(135deg, var(--blue-dark), var(--blue));
            color: var(--white);
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.3);
        }

        .property-image-gradient {
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 50%;
            background: linear-gradient(to top, rgba(10, 10, 10, 0.8), transparent);
            pointer-events: none;
        }

        .property-card-body {
            padding: 20px 22px 24px;
        }

        .property-price {
            font-size: 1.35rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 6px;
        }

        .property-address {
            font-size: 0.9rem;
            color: var(--gray-400);
            margin-bottom: 14px;
            display: flex;
            align-items: flex-start;
            gap: 6px;
        }

        .property-address i {
            color: var(--blue-light);
            margin-top: 2px;
            flex-shrink: 0;
        }

        .property-features {
            display: flex;
            gap: 16px;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }

        .property-feat {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.8125rem;
            color: var(--gray-400);
        }

        .property-feat i {
            color: var(--gray-500);
            font-size: 0.85rem;
        }

        .property-card-foot {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 14px;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
        }

        .property-type-label {
            font-size: 0.8rem;
            color: var(--gray-500);
            font-weight: 500;
        }

        .property-view-link {
            font-size: 0.8rem;
            color: var(--blue-light);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
            transition: color 0.2s ease;
        }

        .property-card:hover .property-view-link {
            color: var(--gold);
        }

        .view-all-wrap {
            text-align: center;
            margin-top: 52px;
            position: relative;
            z-index: 1;
        }

        /* ============================
           JOURNEY SECTION — Reassuring steps
        ============================ */
        .journey-section {
            padding: 100px 20px;
            background: linear-gradient(180deg, var(--black-light) 0%, #0d0d0d 100%);
            border-bottom: 1px solid rgba(212, 175, 55, 0.1);
            position: relative;
        }

        .journey-section::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background:
                radial-gradient(circle at 10% 20%, rgba(37, 99, 235, 0.04) 0%, transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(212, 175, 55, 0.04) 0%, transparent 40%);
            pointer-events: none;
        }

        .journey-header {
            text-align: center;
            margin-bottom: 72px;
            position: relative;
            z-index: 1;
        }

        .journey-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 28px;
            max-width: 1140px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        .journey-card {
            background: linear-gradient(135deg, rgba(26, 26, 26, 0.5) 0%, rgba(10, 10, 10, 0.7) 100%);
            border: 1px solid rgba(37, 99, 235, 0.12);
            border-radius: 6px;
            padding: 40px 28px;
            text-align: center;
            transition: all 0.45s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            overflow: hidden;
        }

        .journey-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--blue), transparent);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .journey-card:hover {
            border-color: rgba(37, 99, 235, 0.3);
            box-shadow: 0 16px 44px rgba(0, 0, 0, 0.3), 0 6px 20px rgba(37, 99, 235, 0.12);
            transform: translateY(-8px);
        }

        .journey-card:hover::before { opacity: 1; }

        .journey-step-num {
            width: 48px;
            height: 48px;
            margin: 0 auto 20px;
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.12), rgba(37, 99, 235, 0.08));
            border: 1px solid rgba(37, 99, 235, 0.25);
            border-radius: 2px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--blue-light);
        }

        .journey-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 50%, var(--gold-dark) 100%);
            border-radius: 2px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            box-shadow: 0 4px 16px rgba(212, 175, 55, 0.25);
            transition: all 0.3s ease;
        }

        .journey-card:hover .journey-icon {
            box-shadow: 0 6px 24px rgba(212, 175, 55, 0.4);
            transform: scale(1.05);
        }

        .journey-icon i {
            font-size: 26px;
            color: var(--black);
        }

        .journey-card h3 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 10px;
        }

        .journey-card p {
            font-size: 0.95rem;
            color: var(--gray-400);
            line-height: 1.65;
        }

        /* ============================
           TRUST / COMFORT SECTION
        ============================ */
        .comfort-section {
            padding: 100px 20px;
            background: var(--black);
            border-bottom: 1px solid rgba(37, 99, 235, 0.15);
            position: relative;
        }

        .comfort-section::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background:
                radial-gradient(circle at 50% 50%, rgba(212, 175, 55, 0.03) 0%, transparent 50%);
            pointer-events: none;
        }

        .comfort-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 60px;
            max-width: 1100px;
            margin: 0 auto;
            align-items: center;
            position: relative;
            z-index: 1;
        }

        .comfort-text .section-badge { margin-bottom: 16px; }

        .comfort-text h2 {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--white);
            margin-bottom: 20px;
            line-height: 1.2;
        }

        .comfort-text p {
            font-size: 1.05rem;
            color: var(--gray-300);
            line-height: 1.85;
            margin-bottom: 24px;
        }

        .comfort-highlights {
            list-style: none;
            padding: 0;
            margin: 0 0 32px 0;
        }

        .comfort-highlights li {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 10px 0;
            font-size: 0.95rem;
            color: var(--gray-300);
        }

        .comfort-highlights li i {
            color: var(--gold);
            font-size: 1.1rem;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .comfort-image-container {
            position: relative;
            border-radius: 4px;
            overflow: hidden;
            border: 1px solid rgba(212, 175, 55, 0.15);
        }

        .comfort-image-container img {
            width: 100%;
            height: 440px;
            object-fit: cover;
            display: block;
            transition: transform 0.5s ease;
        }

        .comfort-image-container:hover img {
            transform: scale(1.03);
        }

        .comfort-image-overlay {
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 35%;
            background: linear-gradient(to top, rgba(10, 10, 10, 0.6), transparent);
            pointer-events: none;
        }

        /* ============================
           STATS SECTION — Gentle numbers
        ============================ */
        .stats-section {
            background: linear-gradient(180deg, var(--black) 0%, #0c0c0c 50%, var(--black) 100%);
            padding: 80px 20px;
            border-top: 1px solid rgba(37, 99, 235, 0.15);
            border-bottom: 1px solid rgba(37, 99, 235, 0.15);
            position: relative;
        }

        .stats-section::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background:
                radial-gradient(circle at 20% 50%, rgba(37, 99, 235, 0.03) 0%, transparent 50%),
                radial-gradient(circle at 80% 50%, rgba(212, 175, 55, 0.03) 0%, transparent 50%);
            pointer-events: none;
        }

        .stats-header {
            text-align: center;
            margin-bottom: 52px;
            position: relative;
            z-index: 1;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 32px;
            max-width: 1000px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        .stat-item {
            text-align: center;
            padding: 28px 20px;
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.03) 0%, rgba(212, 175, 55, 0.02) 100%);
            border-radius: 6px;
            border: 1px solid rgba(37, 99, 235, 0.1);
            transition: all 0.45s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .stat-item:hover {
            border-color: rgba(37, 99, 235, 0.25);
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.3), 0 6px 16px rgba(37, 99, 235, 0.12);
            transform: translateY(-6px);
        }

        .stat-number {
            font-size: 2.75rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 50%, var(--gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            display: block;
            margin-bottom: 6px;
            filter: drop-shadow(0 0 6px rgba(212, 175, 55, 0.15));
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--gray-400);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* ============================
           WARM CTA — Closing section
        ============================ */
        .warmcta-section {
            padding: 100px 20px;
            background:
                radial-gradient(circle at 30% 50%, rgba(212, 175, 55, 0.06) 0%, transparent 50%),
                radial-gradient(circle at 70% 50%, rgba(37, 99, 235, 0.06) 0%, transparent 50%),
                linear-gradient(135deg, var(--black-light) 0%, var(--black) 100%);
            border-top: 1px solid rgba(37, 99, 235, 0.2);
            border-bottom: 1px solid rgba(212, 175, 55, 0.2);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .warmcta-section::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 1px;
            background: linear-gradient(90deg,
                transparent 0%,
                rgba(37, 99, 235, 0.4) 25%,
                rgba(212, 175, 55, 0.4) 75%,
                transparent 100%);
        }

        .warmcta-content {
            max-width: 720px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        .warmcta-title {
            font-size: 2.75rem;
            font-weight: 800;
            margin-bottom: 20px;
            color: var(--white);
            line-height: 1.2;
        }

        .warmcta-title .gold-text {
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 50%, var(--gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .warmcta-description {
            font-size: 1.15rem;
            color: var(--gray-300);
            margin-bottom: 40px;
            line-height: 1.75;
        }

        .warmcta-buttons {
            display: flex;
            gap: 16px;
            justify-content: center;
            flex-wrap: wrap;
        }

        /* ============================
           FOOTER — Consistent
        ============================ */
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
            top: 0; left: 0; right: 0; bottom: 0;
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

        .footer-section ul { list-style: none; }
        .footer-section ul li { margin-bottom: 12px; }

        .footer-section a {
            color: var(--gray-400);
            text-decoration: none;
            transition: color 0.3s ease;
        }
        .footer-section a:hover { color: var(--blue-light); }

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
            width: 40px; height: 40px;
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

        /* ============================
           RESPONSIVE
        ============================ */
        @media (max-width: 992px) {
            .belong-grid,
            .comfort-grid {
                grid-template-columns: 1fr;
                gap: 40px;
            }

            .belong-image-container { order: -1; }

            .journey-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .hero-welcome {
                padding: 100px 20px 60px;
                min-height: 90vh;
            }

            .hero-welcome-title { font-size: 2.5rem; }
            .hero-welcome-subtitle { font-size: 1rem; }

            .hero-search-bar {
                flex-direction: column;
            }

            .hero-search-bar select,
            .hero-search-bar input[type="text"],
            .hero-search-btn {
                width: 100%;
                justify-content: center;
            }

            .section-title { font-size: 2rem; }

            .belong-text h2,
            .comfort-text h2 { font-size: 1.85rem; }

            .properties-grid {
                grid-template-columns: 1fr;
            }

            .journey-grid {
                grid-template-columns: 1fr 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 20px;
            }

            .warmcta-title { font-size: 2rem; }
            .warmcta-buttons { flex-direction: column; }
            .warmcta-buttons a { width: 100%; justify-content: center; }

            .footer-content {
                grid-template-columns: 1fr;
                gap: 32px;
            }
        }

        @media (max-width: 576px) {
            .hero-welcome-title { font-size: 2rem; }
            .stats-grid { grid-template-columns: 1fr; }
            .stat-number { font-size: 2.25rem; }
            .journey-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<!-- Include Navbar -->
<?php include 'navbar.php'; ?>

<main>

    <!-- ============================================================
         HERO — Warm, Welcoming, Emotional
    ============================================================ -->
    <section class="hero-welcome">
        <div class="container">
            <div class="hero-welcome-inner">

                <div class="hero-welcome-badge">Welcome to HomeEstate Realty</div>

                <h1 class="hero-welcome-title">
                    Find a Place to<br>Call <span class="gold-text">Home</span>
                </h1>

                <p class="hero-welcome-subtitle">
                    Everyone deserves a place where they feel safe, comfortable, and truly at peace. 
                    We're here to help you find that place — whether it's your first home, 
                    your next chapter, or a fresh start.
                </p>

                <!-- Simple Search Bar -->
                <form class="hero-search-bar" action="search_results.php" method="GET">
                    <select name="city">
                        <option value="">Any Location</option>
                        <?php foreach ($cities as $city): ?>
                            <option value="<?php echo htmlspecialchars($city['City']); ?>">
                                <?php echo htmlspecialchars($city['City']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="property_type">
                        <option value="">Any Type</option>
                        <?php foreach ($property_types as $type): ?>
                            <option value="<?php echo htmlspecialchars($type['PropertyType']); ?>">
                                <?php echo htmlspecialchars($type['PropertyType']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="status">
                        <option value="">Buy or Rent</option>
                        <option value="For Sale">For Sale</option>
                        <option value="For Rent">For Rent</option>
                    </select>
                    <button type="submit" class="hero-search-btn">
                        <i class="bi bi-search"></i>
                        Search
                    </button>
                </form>

                <div class="hero-quick-links">
                    <a href="search_results.php?status=For+Sale" class="hero-quick-link">Homes for Sale</a>
                    <a href="search_results.php?status=For+Rent" class="hero-quick-link">Homes for Rent</a>
                    <a href="agents.php" class="hero-quick-link">Meet Our Agents</a>
                </div>

            </div>
        </div>
    </section>

    <!-- ============================================================
         BELONG — "Find a place where you belong"
    ============================================================ -->
    <section class="belong-section">
        <div class="container">
            <div class="belong-grid">

                <div class="belong-image-container reveal reveal-left" data-parallax>
                    <!-- IMAGE: A warm family scene — a couple or family standing at 
                         the front door of a cozy home, golden hour sunlight, welcoming 
                         porch with plants. Should feel emotional, hopeful, and real. -->
                    <img src="../images/HomePagePic2.jpg" alt="A welcoming home with warm golden light">
                    <div class="belong-image-overlay"></div>
                </div>

                <div class="belong-text reveal reveal-right">
                    <div class="section-badge">Your Journey Starts Here</div>
                    <h2>Find a Place Where<br>You <span class="gold-text">Belong</span></h2>
                    <p>
                        A home is more than walls and a roof — it's where your story unfolds, 
                        where laughter fills the rooms, and where you feel most like yourself. 
                        We understand how personal this search is, and we're honored to be 
                        part of your journey.
                    </p>
                    <p>
                        Whether you're dreaming of a quiet neighborhood for your family, a cozy 
                        space of your own, or a new beginning in a new city — we're here to 
                        walk beside you every step of the way.
                    </p>
                    <a href="search_results.php" class="btn-primary-gold">
                        <i class="bi bi-house-door"></i>
                        Start Exploring
                    </a>
                </div>

            </div>
        </div>
    </section>

    <!-- ============================================================
         FEATURED PROPERTIES — Gentle, Inviting Showcase
    ============================================================ -->
    <?php if (!empty($featured_properties)): ?>
    <section class="featured-section">
        <div class="container">
            <div class="featured-header reveal">
                <div class="section-badge">Handpicked for You</div>
                <h2 class="section-title">Homes Waiting to Be <span class="gold-text">Loved</span></h2>
                <p class="section-description">
                    Each of these homes has its own story. Take a look — your next chapter might be just a click away.
                </p>
                <div class="section-divider"></div>
            </div>

            <div class="properties-grid stagger-children">
                <?php foreach ($featured_properties as $prop): ?>
                <div class="property-card reveal">
                    <a href="property_details.php?id=<?php echo $prop['property_ID']; ?>" class="property-card-link">
                        <div class="property-image-wrap">
                            <img src="../<?php echo htmlspecialchars($prop['PhotoURL'] ?? 'images/placeholder.jpg'); ?>" 
                                 alt="<?php echo htmlspecialchars($prop['StreetAddress']); ?>" loading="lazy">
                            <div class="property-status-badge <?php echo $prop['Status'] === 'For Rent' ? 'for-rent' : ''; ?>">
                                <?php echo htmlspecialchars($prop['Status']); ?>
                            </div>
                            <div class="property-image-gradient"></div>
                        </div>
                        <div class="property-card-body">
                            <div class="property-price"><?php echo chr(0xE2).chr(0x82).chr(0xB1); ?><?php echo number_format($prop['ListingPrice']); ?></div>
                            <div class="property-address">
                                <i class="bi bi-geo-alt-fill"></i>
                                <span><?php echo htmlspecialchars($prop['StreetAddress']); ?>, <?php echo htmlspecialchars($prop['City']); ?></span>
                            </div>
                            <div class="property-features">
                                <div class="property-feat">
                                    <i class="bi bi-door-open-fill"></i>
                                    <?php echo $prop['Bedrooms']; ?> Beds
                                </div>
                                <div class="property-feat">
                                    <i class="bi bi-droplet-fill"></i>
                                    <?php echo $prop['Bathrooms']; ?> Baths
                                </div>
                                <div class="property-feat">
                                    <i class="bi bi-arrows-fullscreen"></i>
                                    <?php echo number_format($prop['SquareFootage']); ?> sqft
                                </div>
                            </div>
                            <div class="property-card-foot">
                                <span class="property-type-label"><?php echo htmlspecialchars($prop['PropertyType']); ?></span>
                                <span class="property-view-link">View Home <i class="bi bi-arrow-right"></i></span>
                            </div>
                        </div>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="view-all-wrap reveal">
                <a href="search_results.php" class="btn-outline-blue">
                    <i class="bi bi-grid-3x3-gap"></i>
                    See All Available Homes
                </a>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- ============================================================
         JOURNEY — How We Help You (Reassuring Steps)
    ============================================================ -->
    <section class="journey-section">
        <div class="container">
            <div class="journey-header reveal">
                <div class="section-badge">How We Help</div>
                <h2 class="section-title">Your Path to <span class="gold-text">Home</span></h2>
                <p class="section-description">
                    Finding a home should feel exciting, not overwhelming. Here's how we make the journey simple and comforting.
                </p>
                <div class="section-divider"></div>
            </div>

            <div class="journey-grid stagger-children">
                <div class="journey-card reveal">
                    <div class="journey-icon">
                        <i class="bi bi-search-heart"></i>
                    </div>
                    <h3>Explore at Your Pace</h3>
                    <p>Browse homes with easy filters. No pressure, no rush — take your time finding a place that feels right.</p>
                </div>

                <div class="journey-card reveal">
                    <div class="journey-icon">
                        <i class="bi bi-calendar-heart"></i>
                    </div>
                    <h3>Schedule a Visit</h3>
                    <p>Found something you love? Book a tour directly. Step inside and see if it feels like home.</p>
                </div>

                <div class="journey-card reveal">
                    <div class="journey-icon">
                        <i class="bi bi-people"></i>
                    </div>
                    <h3>Talk to Real People</h3>
                    <p>Our friendly agents are here to listen, answer your questions, and guide you with care.</p>
                </div>

                <div class="journey-card reveal">
                    <div class="journey-icon">
                        <i class="bi bi-heart-fill"></i>
                    </div>
                    <h3>Save What You Love</h3>
                    <p>Bookmark the homes that speak to you. Build a shortlist and compare at your own pace.</p>
                </div>

                <div class="journey-card reveal">
                    <div class="journey-icon">
                        <i class="bi bi-file-earmark-check"></i>
                    </div>
                    <h3>Make It Official</h3>
                    <p>Ready to move forward? Your agent will guide you clearly through every step of the process.</p>
                </div>

                <div class="journey-card reveal">
                    <div class="journey-icon">
                        <i class="bi bi-key"></i>
                    </div>
                    <h3>Welcome Home</h3>
                    <p>When you've found the one, we'll help make the process smooth — so you can focus on settling in.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================
         COMFORT — Guidance & Trust Message
    ============================================================ -->
    <section class="comfort-section">
        <div class="container">
            <div class="comfort-grid">

                <div class="comfort-text reveal reveal-left">
                    <div class="section-badge">We're Here for You</div>
                    <h2>You're Not Searching<br>Alone</h2>
                    <p>
                        Looking for a home can feel like a big step. We want you to know that 
                        you don't have to navigate it by yourself. Our team is made up of real people 
                        who genuinely care about helping you find the right fit.
                    </p>
                    <ul class="comfort-highlights">
                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            <span>Verified agents who understand your neighborhood</span>
                        </li>
                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            <span>Every listing is reviewed for accuracy and honesty</span>
                        </li>
                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            <span>Tour scheduling that works around your life</span>
                        </li>
                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            <span>Transparent, straightforward communication</span>
                        </li>
                    </ul>
                    <a href="agents.php" class="btn-outline-blue">
                        <i class="bi bi-person-heart"></i>
                        Meet Our Agents
                    </a>
                </div>

                <div class="comfort-image-container reveal reveal-right" data-parallax>
                    <!-- IMAGE: A warm, professional scene — a smiling real estate agent 
                         having a friendly conversation with a couple at a kitchen table, 
                         or handing keys to a happy new homeowner. Natural light, genuine 
                         smiles, warm color palette. Should feel supportive and trustworthy. -->
                    <img src="../images/hero-bg.jpg" alt="Our agents are here to guide you home">
                    <div class="comfort-image-overlay"></div>
                </div>

            </div>
        </div>
    </section>

    <!-- ============================================================
         STATS — Gentle Community Numbers
    ============================================================ -->
    <section class="stats-section">
        <div class="container">
            <div class="stats-header reveal">
                <div class="section-badge">Our Community</div>
                <h2 class="section-title">Helping People Find <span class="gold-text">Home</span></h2>
                <div class="section-divider"></div>
            </div>
            <div class="stats-grid stagger-children">
                <div class="stat-item reveal">
                    <span class="stat-number" data-count="<?php echo $sale_count; ?>">0+</span>
                    <span class="stat-label">Homes for Sale</span>
                </div>
                <div class="stat-item reveal">
                    <span class="stat-number" data-count="<?php echo $rent_count; ?>">0+</span>
                    <span class="stat-label">Homes for Rent</span>
                </div>
                <div class="stat-item reveal">
                    <span class="stat-number" data-count="<?php echo $sold_count; ?>">0+</span>
                    <span class="stat-label">Families Settled</span>
                </div>
                <div class="stat-item reveal">
                    <span class="stat-number" data-count="<?php echo $agent_count; ?>">0+</span>
                    <span class="stat-label">Caring Agents</span>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================
         WARM CTA — Begin Your Journey
    ============================================================ -->
    <section class="warmcta-section">
        <div class="container">
            <div class="warmcta-content reveal reveal-scale">
                <h2 class="warmcta-title">Your Next Chapter<br>Starts <span class="gold-text">Here</span></h2>
                <p class="warmcta-description">
                    Wherever life takes you next, let us help you find the place that feels 
                    just right. Begin your search today — your future home is waiting.
                </p>
                <div class="warmcta-buttons">
                    <a href="search_results.php" class="btn-primary-gold">
                        <i class="bi bi-house-door"></i>
                        Browse Homes
                    </a>
                    <a href="agents.php" class="btn-outline-blue">
                        <i class="bi bi-chat-heart"></i>
                        Talk to an Agent
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
                <p>Helping people find not just a house, but a place where they truly belong. Your comfort and happiness is what guides everything we do.</p>
            </div>
            <div class="footer-section">
                <h5>Quick Links</h5>
                <ul class="list-unstyled">
                    <li><a href="search_results.php">Browse Homes</a></li>
                    <li><a href="search_results.php?status=For+Sale">Homes for Sale</a></li>
                    <li><a href="search_results.php?status=For+Rent">Homes for Rent</a></li>
                    <li><a href="about.php">About Us</a></li>
                </ul>
            </div>
            <div class="footer-section">
                <h5>Support</h5>
                <ul class="list-unstyled">
                    <li><a href="agents.php">Find an Agent</a></li>
                    <li><a href="about.php">How It Works</a></li>
                    <li><a href="#">Help Center</a></li>
                    <li><a href="#">Contact Us</a></li>
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
(function() {
    'use strict';

    /* =============================================
       1. SCROLL REVEAL — IntersectionObserver
    ============================================= */
    const revealElements = document.querySelectorAll('.reveal');

    const revealObserver = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');
                revealObserver.unobserve(entry.target);
            }
        });
    }, {
        threshold: 0.12,
        rootMargin: '0px 0px -40px 0px'
    });

    revealElements.forEach(function(el) {
        revealObserver.observe(el);
    });

    /* =============================================
       2. ANIMATED NUMBER COUNTER for Stats
    ============================================= */
    const counterElements = document.querySelectorAll('[data-count]');
    let countersDone = false;

    function animateCounter(el) {
        const target = parseInt(el.getAttribute('data-count'), 10);
        const duration = 1800;
        const startTime = performance.now();

        function easeOutExpo(t) {
            return t === 1 ? 1 : 1 - Math.pow(2, -10 * t);
        }

        function tick(now) {
            const elapsed = now - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const eased = easeOutExpo(progress);
            const current = Math.round(eased * target);
            el.textContent = current.toLocaleString() + '+';

            if (progress < 1) {
                requestAnimationFrame(tick);
            }
        }

        requestAnimationFrame(tick);
    }

    const statsObserver = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (entry.isIntersecting && !countersDone) {
                countersDone = true;
                counterElements.forEach(function(el) {
                    animateCounter(el);
                });
                statsObserver.disconnect();
            }
        });
    }, {
        threshold: 0.3
    });

    const statsSection = document.querySelector('.stats-section');
    if (statsSection) {
        statsObserver.observe(statsSection);
    }

    /* =============================================
       3. SUBTLE PARALLAX on marked images
    ============================================= */
    const parallaxElements = document.querySelectorAll('[data-parallax]');
    let parallaxTicking = false;

    function updateParallax() {
        const scrollY = window.scrollY;
        const viewH = window.innerHeight;

        parallaxElements.forEach(function(el) {
            const rect = el.getBoundingClientRect();
            if (rect.bottom > 0 && rect.top < viewH) {
                const center = rect.top + rect.height / 2;
                const offset = (center - viewH / 2) / viewH;
                const img = el.querySelector('img');
                if (img) {
                    img.style.transform = 'scale(1.06) translateY(' + (offset * -18) + 'px)';
                }
            }
        });
        parallaxTicking = false;
    }

    if (parallaxElements.length > 0) {
        window.addEventListener('scroll', function() {
            if (!parallaxTicking) {
                requestAnimationFrame(updateParallax);
                parallaxTicking = true;
            }
        }, { passive: true });
    }

    /* =============================================
       4. SMOOTH SCROLL for anchor links
    ============================================= */
    document.querySelectorAll('a[href^="#"]').forEach(function(anchor) {
        anchor.addEventListener('click', function(e) {
            var href = this.getAttribute('href');
            if (href !== '#' && href.length > 1) {
                e.preventDefault();
                var target = document.querySelector(href);
                if (target) {
                    var navH = document.querySelector('.navbar') ? document.querySelector('.navbar').offsetHeight : 0;
                    window.scrollTo({
                        top: target.offsetTop - navH - 20,
                        behavior: 'smooth'
                    });
                }
            }
        });
    });

})();
</script>

</body>
</html>