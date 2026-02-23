<?php
include '../connection.php';

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$location_filter = isset($_GET['location']) ? trim($_GET['location']) : '';

// Pagination settings
$agents_per_page = 12;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $agents_per_page;

// Fetch unique cities/locations from properties (where agents work)
$locations_sql = "SELECT DISTINCT p.City 
                 FROM property p 
                 JOIN property_log pl ON p.property_ID = pl.property_id 
                 WHERE pl.action = 'CREATED' AND p.City IS NOT NULL AND p.City != '' 
                 ORDER BY p.City ASC";
$loc_result = $conn->query($locations_sql);
$locations = [];
if ($loc_result) {
    while ($row = $loc_result->fetch_assoc()) {
        $locations[] = $row['City'];
    }
}

// Count total agents (for pagination)
$count_sql = "
    SELECT COUNT(DISTINCT a.account_id) as total
    FROM 
        accounts a
    JOIN 
        user_roles ur ON a.role_id = ur.role_id
    JOIN 
        agent_information ai ON a.account_id = ai.account_id
    WHERE 
        ur.role_name = 'agent' 
        AND ai.is_approved = 1 
        AND ai.profile_completed = 1
        AND a.is_active = 1
";

// Add search filter to count
if (!empty($search)) {
    $search_safe = $conn->real_escape_string($search);
    $count_sql .= " AND (a.first_name LIKE '%$search_safe%' 
                         OR a.last_name LIKE '%$search_safe%' 
                         OR CONCAT(a.first_name, ' ', a.last_name) LIKE '%$search_safe%'
                         OR ai.license_number LIKE '%$search_safe%'
                         OR ai.years_experience LIKE '%$search_safe%')";
}

// Add location filter to count (join with property_log to find agents in specific cities)
if (!empty($location_filter)) {
    $loc_safe = $conn->real_escape_string($location_filter);
    $count_sql .= " AND EXISTS (
        SELECT 1 FROM property_log pl 
        JOIN property p ON pl.property_id = p.property_ID 
        WHERE pl.account_id = a.account_id 
        AND pl.action = 'CREATED' 
        AND p.City = '$loc_safe'
    )";
}

$count_result = $conn->query($count_sql);
$total_agents = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_agents / $agents_per_page);

// Build the agents query with filters
$agents_sql = "
    SELECT DISTINCT
        a.account_id,
        a.first_name,
        a.last_name,
        a.email,
        a.phone_number,
        ai.license_number,
        ai.specialization,
        ai.years_experience,
        ai.bio,
        ai.profile_picture_url
    FROM 
        accounts a
    JOIN 
        user_roles ur ON a.role_id = ur.role_id
    JOIN 
        agent_information ai ON a.account_id = ai.account_id
    WHERE 
        ur.role_name = 'agent' 
        AND ai.is_approved = 1 
        AND ai.profile_completed = 1
        AND a.is_active = 1
";

// Add search filter
if (!empty($search)) {
    $search_safe = $conn->real_escape_string($search);
    $agents_sql .= " AND (a.first_name LIKE '%$search_safe%' 
                         OR a.last_name LIKE '%$search_safe%' 
                         OR CONCAT(a.first_name, ' ', a.last_name) LIKE '%$search_safe%'
                         OR ai.license_number LIKE '%$search_safe%'
                         OR ai.years_experience LIKE '%$search_safe%')";
}

// Add location filter
if (!empty($location_filter)) {
    $loc_safe = $conn->real_escape_string($location_filter);
    $agents_sql .= " AND EXISTS (
        SELECT 1 FROM property_log pl 
        JOIN property p ON pl.property_id = p.property_ID 
        WHERE pl.account_id = a.account_id 
        AND pl.action = 'CREATED' 
        AND p.City = '$loc_safe'
    )";
}

$agents_sql .= " ORDER BY ai.years_experience DESC, a.first_name ASC LIMIT $agents_per_page OFFSET $offset";

$agents_result = $conn->query($agents_sql);
$agents = $agents_result->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Our Expert Agents | HomeEstate Realty</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        :root {
            /* Gold Palette */
            --gold: #d4af37;
            --gold-light: #f4d03f;
            --gold-dark: #b8941f;
            
            /* Blue Palette */
            --blue: #2563eb;
            --blue-light: #3b82f6;
            --blue-dark: #1e40af;
            
            /* Black Palette */
            --black: #0a0a0a;
            --black-light: #111111;
            --black-lighter: #1a1a1a;
            --black-border: #1f1f1f;
            
            /* Semantic Gray Scale */
            --white: #ffffff;
            --gray-50: #f8f9fa;
            --gray-100: #e9ecef;
            --gray-200: #dee2e6;
            --gray-300: #c5cdd5;
            --gray-400: #a0aab5;
            --gray-500: #7a8a99;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, var(--black) 0%, var(--black-lighter) 100%);
            line-height: 1.6;
            color: var(--white);
            overflow-x: hidden;
        }

        /* Hero Section */
        .agents-hero {
            min-height: 60vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--black) 0%, var(--black-lighter) 100%);
            position: relative;
            padding: 120px 20px 80px;
            border-bottom: 1px solid rgba(37, 99, 235, 0.2);
        }

        .agents-hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: 
                radial-gradient(ellipse at top right, rgba(37, 99, 235, 0.06) 0%, transparent 50%),
                radial-gradient(ellipse at bottom left, rgba(212, 175, 55, 0.04) 0%, transparent 50%);
        }

        .hero-content {
            position: relative;
            z-index: 2;
            text-align: center;
            max-width: 900px;
            margin: 0 auto;
        }

        .hero-badge {
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
        }

        .hero-title {
            font-size: 4rem;
            font-weight: 800;
            margin-bottom: 24px;
            line-height: 1.1;
            color: var(--white);
        }

        .hero-title .gold-text {
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 50%, var(--gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            filter: drop-shadow(0 0 12px rgba(212, 175, 55, 0.3));
        }

        .hero-description {
            font-size: 1.25rem;
            color: var(--gray-300);
            margin-bottom: 48px;
            line-height: 1.8;
        }

        /* Stats removed to simplify page layout */

        /* Agents Section */
        .agents-section {
            padding: 60px 20px 100px;
            background: linear-gradient(180deg, var(--black-light) 0%, #0d0d0d 100%);
            position: relative;
        }

        /* Search & Filter Bar */
        .filter-section {
            background: linear-gradient(135deg, rgba(26, 26, 26, 0.95) 0%, rgba(17, 17, 17, 0.98) 100%);
            border: 1px solid rgba(37, 99, 235, 0.15);
            border-radius: 4px;
            padding: 32px;
            margin-bottom: 48px;
            position: relative;
            overflow: hidden;
        }

        .filter-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--blue) 0%, var(--gold) 50%, var(--blue) 100%);
            opacity: 0.6;
        }

        .filter-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-title i {
            color: var(--blue-light);
            font-size: 1.5rem;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 16px;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .filter-label {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--gray-300);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filter-input,
        .filter-select {
            width: 100%;
            padding: 14px 16px;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 3px;
            color: var(--white);
            font-size: 0.9375rem;
            font-family: 'Inter', sans-serif;
            transition: all 0.2s ease;
        }

        .filter-input::placeholder {
            color: rgba(255, 255, 255, 0.4);
        }

        .filter-input:focus,
        .filter-select:focus {
            outline: none;
            border-color: var(--gold);
            box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.15);
            background: rgba(0, 0, 0, 0.4);
        }

        .filter-select {
            cursor: pointer;
        }

        .filter-select option {
            background: var(--black-lighter);
            color: var(--white);
            padding: 12px;
        }

        .clear-filters-btn {
            padding: 14px 28px;
            background: rgba(37, 99, 235, 0.1);
            border: 1px solid rgba(37, 99, 235, 0.3);
            border-radius: 3px;
            color: var(--blue-light);
            font-size: 0.9375rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            height: fit-content;
        }

        .clear-filters-btn:hover {
            background: rgba(37, 99, 235, 0.2);
            border-color: var(--blue);
            transform: translateY(-1px);
        }

        .results-count {
            font-size: 0.9375rem;
            color: var(--gray-400);
            margin-bottom: 24px;
            padding: 12px 16px;
            background: rgba(0, 0, 0, 0.2);
            border-left: 3px solid var(--gold);
            border-radius: 2px;
        }

        .results-count strong {
            color: var(--gold);
            font-weight: 700;
        }

        /* Pagination Styles */
        .pagination-container {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 64px;
            gap: 8px;
        }

        .pagination-btn {
            padding: 12px 18px;
            background: rgba(26, 26, 26, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 3px;
            color: var(--white);
            font-size: 0.9375rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .pagination-btn:hover:not(.disabled) {
            background: rgba(37, 99, 235, 0.15);
            border-color: var(--blue);
            color: var(--blue-light);
            transform: translateY(-1px);
        }

        .pagination-btn.active {
            background: linear-gradient(135deg, var(--blue) 0%, var(--blue-dark) 100%);
            border-color: var(--blue);
            color: var(--white);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }

        .pagination-btn.disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }

        .pagination-info {
            padding: 12px 20px;
            color: var(--gray-400);
            font-size: 0.875rem;
            font-weight: 500;
        }

        /* Agents Section */

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
            margin-bottom: 16px;
        }

        .section-title {
            font-size: 3rem;
            font-weight: 800;
            color: var(--white);
            margin-bottom: 16px;
        }

        .section-description {
            font-size: 1.125rem;
            color: var(--gray-300);
            max-width: 600px;
            margin: 0 auto;
        }

        /* Agent Cards Grid */
        .agents-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 32px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .agent-card {
            background: linear-gradient(135deg, rgba(26, 26, 26, 0.8) 0%, rgba(10, 10, 10, 0.9) 100%);
            border: 1px solid rgba(37, 99, 235, 0.15);
            border-radius: 4px;
            padding: 32px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .agent-card::before {
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

        .agent-card:hover {
            border-color: rgba(37, 99, 235, 0.35);
            transform: translateY(-6px);
            box-shadow: 0 12px 40px rgba(37, 99, 235, 0.15);
        }

        .agent-card:hover::before {
            opacity: 1;
        }

        .agent-header {
            display: flex;
            align-items: flex-start;
            gap: 20px;
            margin-bottom: 24px;
            padding-bottom: 24px;
            border-bottom: 1px solid rgba(37, 99, 235, 0.1);
            min-height: 104px;
        }

        .agent-avatar {
            width: 80px;
            height: 80px;
            border-radius: 4px;
            object-fit: cover;
            border: 2px solid rgba(37, 99, 235, 0.2);
            transition: all 0.3s ease;
            flex-shrink: 0;
        }

        .agent-card:hover .agent-avatar {
            border-color: var(--gold);
            box-shadow: 0 0 20px rgba(212, 175, 55, 0.3);
        }

        .agent-info {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 80px;
        }

        .agent-name {
            font-size: 1.375rem;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 4px;
            line-height: 1.3;
            min-height: 36px;
            display: flex;
            align-items: center;
        }

        .agent-specialization {
            font-size: 0.9375rem;
            color: var(--gold);
            font-weight: 500;
            margin-bottom: 8px;
            line-height: 1.4;
            min-height: 26px;
            display: flex;
            align-items: center;
        }

        .agent-experience {
            font-size: 0.875rem;
            color: var(--gray-400);
            display: flex;
            align-items: center;
            gap: 6px;
            min-height: 20px;
        }

        .agent-experience i {
            color: var(--blue-light);
        }

        .agent-bio {
            font-size: 0.9375rem;
            color: var(--gray-300);
            line-height: 1.7;
            margin-bottom: 24px;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
            height: 76px;
            flex-shrink: 0;
            text-align: justify;
            text-justify: inter-word;
            -webkit-hyphens: auto;
            -ms-hyphens: auto;
            hyphens: auto;
        }

        .agent-license {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px;
            background: rgba(212, 175, 55, 0.08);
            border: 1px solid rgba(212, 175, 55, 0.15);
            border-radius: 4px;
            margin-bottom: 20px;
            min-height: 64px;
            flex-shrink: 0;
        }

        .agent-license i {
            color: var(--gold);
            font-size: 1.125rem;
        }

        .agent-license-text {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .agent-license-label {
            font-size: 0.75rem;
            color: var(--gray-400);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 4px;
            line-height: 1.2;
        }

        .agent-license-number {
            font-size: 0.875rem;
            color: var(--gold);
            font-weight: 600;
            line-height: 1.3;
        }

        /* Contact Cards */
        .contact-cards {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: auto;
        }

        .contact-card {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: rgba(37, 99, 235, 0.06);
            border: 1px solid rgba(37, 99, 235, 0.12);
            border-radius: 6px;
            text-decoration: none;
            transition: all 0.2s ease;
            min-height: 60px;
        }

        .contact-card:hover {
            background: rgba(37, 99, 235, 0.12);
            border-color: rgba(37, 99, 235, 0.25);
            transform: translateY(-1px);
        }

        .contact-icon {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            background: rgba(37, 99, 235, 0.08);
            flex-shrink: 0;
        }

        .contact-icon i {
            color: var(--blue-light);
            font-size: 1.125rem;
        }

        .contact-details {
            text-align: left;
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .contact-label {
            font-size: 0.75rem;
            color: var(--gray-400);
            margin-bottom: 3px;
            line-height: 1.2;
        }

        .contact-value {
            font-size: 0.95rem;
            color: var(--white);
            font-weight: 600;
            line-height: 1.3;
            word-break: break-word;
        }

        /* View Profile Button */
        .view-profile-btn {
            width: 100%;
            padding: 14px 24px;
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 100%);
            border: none;
            border-radius: 4px;
            color: var(--black);
            font-size: 0.9375rem;
            font-weight: 700;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s ease;
            margin-top: 16px;
            box-shadow: 0 2px 8px rgba(212, 175, 55, 0.2);
        }

        .view-profile-btn:hover {
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(212, 175, 55, 0.4);
            color: var(--black);
        }

        .view-profile-btn i {
            font-size: 1rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            max-width: 600px;
            margin: 0 auto;
        }

        .empty-state-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.1) 0%, rgba(212, 175, 55, 0.1) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
        }

        .empty-state-icon i {
            font-size: 2.5rem;
            color: var(--gray-400);
        }

        .empty-state-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 12px;
        }

        .empty-state-description {
            font-size: 1rem;
            color: var(--gray-400);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .agents-hero {
                padding: 100px 20px 60px;
                min-height: 50vh;
            }

            .hero-title {
                font-size: 2.5rem;
            }

            .hero-description {
                font-size: 1rem;
            }

            .section-title {
                font-size: 2rem;
            }

            .agents-grid {
                grid-template-columns: 1fr;
                gap: 24px;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .filter-section {
                padding: 24px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 24px;
            }

            .stat-number {
                font-size: 2.5rem;
            }
        }

        @media (max-width: 576px) {
            .hero-title {
                font-size: 2rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .agent-header {
                flex-direction: column;
                text-align: center;
            }

            .agent-info {
                text-align: center;
            }

            .agent-experience {
                justify-content: center;
            }
        }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<!-- Hero Section -->
<section class="agents-hero">
    <div class="container">
        <div class="hero-content">
            <div class="hero-badge">Meet Our Team</div>
            
            <h1 class="hero-title">
                Our Expert <span class="gold-text">Agents</span>
            </h1>
            
            <p class="hero-description">
                Connect with experienced real estate professionals dedicated to helping you find your perfect property. 
                Our certified agents bring local market expertise and personalized service to every transaction.
            </p>
        </div>
    </div>
</section>

<!-- Stats section removed -->

<!-- Agents Section -->
<section class="agents-section">
    <div class="container">
        <!-- Search & Filter Section -->
        <div class="filter-section">
            <h2 class="filter-title">
                <i class="bi bi-funnel"></i>
                Find Your Perfect Agent
            </h2>
            <div class="filter-grid">
                <div class="filter-group">
                    <label class="filter-label" for="searchInput">
                        <i class="bi bi-search"></i> Search by Name, License, or Experience
                    </label>
                    <input 
                        type="text" 
                        id="searchInput" 
                        class="filter-input"
                        placeholder="Search agent name, license number, or years of experience..."
                        value="<?php echo htmlspecialchars($search); ?>"
                    >
                </div>
                <div class="filter-group">
                    <label class="filter-label" for="locationFilter">
                        <i class="bi bi-geo-alt"></i> Location
                    </label>
                    <select id="locationFilter" class="filter-select">
                        <option value="">All Locations</option>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?php echo htmlspecialchars($loc); ?>" <?php echo $location_filter === $loc ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($loc); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php if (!empty($search) || !empty($location_filter)): ?>
                <button class="clear-filters-btn" onclick="clearFilters()" style="margin-top: 16px;">
                    <i class="bi bi-x-circle"></i>
                    Clear Filters
                </button>
            <?php endif; ?>
        </div>

        <!-- Results Count -->
        <div class="results-count">
            Showing <strong><?php echo count($agents); ?></strong> of <strong><?php echo $total_agents; ?></strong> agent<?php echo $total_agents !== 1 ? 's' : ''; ?>
            <?php if ($total_pages > 1): ?>
                (Page <?php echo $current_page; ?> of <?php echo $total_pages; ?>)
            <?php endif; ?>
        </div>

        <?php if (count($agents) > 0): ?>
            <div class="agents-grid">
                <?php foreach ($agents as $agent): 
                    $full_name = htmlspecialchars(trim($agent['first_name'] . ' ' . $agent['last_name']));
                    $specialization = htmlspecialchars($agent['specialization'] ?? 'Real Estate Professional');
                    $years = (int)$agent['years_experience'];
                    $experience_text = $years === 0 ? 'New Agent' : ($years === 1 ? '1 Year Experience' : $years . ' Years Experience');
                    $bio = htmlspecialchars($agent['bio'] ?? 'Dedicated real estate professional committed to helping you find your perfect property.');
                    $license = htmlspecialchars($agent['license_number'] ?? 'N/A');
                    $email = htmlspecialchars($agent['email']);
                    $phone = htmlspecialchars($agent['phone_number']);
                    
                    // Profile picture
                    $profile_pic = !empty($agent['profile_picture_url']) 
                        ? '../' . htmlspecialchars($agent['profile_picture_url'])
                        : 'https://via.placeholder.com/80?text=' . strtoupper(substr($agent['first_name'], 0, 1));
                ?>
                <div class="agent-card">
                    <div class="agent-header">
                        <img src="<?php echo $profile_pic; ?>" alt="<?php echo $full_name; ?>" class="agent-avatar">
                        <div class="agent-info">
                            <h3 class="agent-name"><?php echo $full_name; ?></h3>
                            <div class="agent-experience">
                                <i class="bi bi-briefcase-fill"></i>
                                <span><?php echo $experience_text; ?></span>
                            </div>
                        </div>
                    </div>

                    <p class="agent-bio"><?php echo $bio; ?></p>

                    <div class="agent-license">
                        <i class="bi bi-patch-check-fill"></i>
                        <div class="agent-license-text">
                            <div class="agent-license-label">License Number</div>
                            <div class="agent-license-number"><?php echo $license; ?></div>
                        </div>
                    </div>

                    <div class="contact-cards">
                        <a href="mailto:<?php echo $email; ?>" class="contact-card">
                            <div class="contact-icon">
                                <i class="bi bi-envelope-fill"></i>
                            </div>
                            <div class="contact-details">
                                <div class="contact-label">Email</div>
                                <div class="contact-value"><?php echo $email; ?></div>
                            </div>
                        </a>

                        <a href="tel:<?php echo $phone; ?>" class="contact-card">
                            <div class="contact-icon">
                                <i class="bi bi-telephone-fill"></i>
                            </div>
                            <div class="contact-details">
                                <div class="contact-label">Phone</div>
                                <div class="contact-value"><?php echo $phone; ?></div>
                            </div>
                        </a>
                    </div>

                    <a href="agent_profile.php?id=<?php echo $agent['account_id']; ?>" class="view-profile-btn">
                        <i class="bi bi-person-badge"></i>
                        <span>View Profile</span>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination-container">
                    <?php if ($current_page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page - 1])); ?>" class="pagination-btn">
                            <i class="bi bi-chevron-left"></i> Previous
                        </a>
                    <?php else: ?>
                        <span class="pagination-btn disabled">
                            <i class="bi bi-chevron-left"></i> Previous
                        </span>
                    <?php endif; ?>

                    <?php
                    // Show page numbers with smart truncation
                    $start_page = max(1, $current_page - 2);
                    $end_page = min($total_pages, $current_page + 2);
                    
                    if ($start_page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" class="pagination-btn">1</a>
                        <?php if ($start_page > 2): ?>
                            <span class="pagination-info">...</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                           class="pagination-btn <?php echo $i === $current_page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($end_page < $total_pages): ?>
                        <?php if ($end_page < $total_pages - 1): ?>
                            <span class="pagination-info">...</span>
                        <?php endif; ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" class="pagination-btn"><?php echo $total_pages; ?></a>
                    <?php endif; ?>

                    <?php if ($current_page < $total_pages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page + 1])); ?>" class="pagination-btn">
                            Next <i class="bi bi-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="pagination-btn disabled">
                            Next <i class="bi bi-chevron-right"></i>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="bi bi-people"></i>
                </div>
                <h3 class="empty-state-title">No Agents Available</h3>
                <p class="empty-state-description">
                    We're currently onboarding new agents. Please check back soon!
                </p>
            </div>
        <?php endif; ?>
    </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Real-time search and filter functionality
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    const locationFilter = document.getElementById('locationFilter');
    
    // Debounce function for search input
    function debounce(func, delay) {
        let timeout;
        return function(...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), delay);
        };
    }
    
    // Apply filters function
    function applyFilters() {
        const search = searchInput.value.trim();
        const location = locationFilter.value;
        
        // Build URL with query parameters (excluding page to reset to page 1)
        const params = new URLSearchParams();
        if (search) params.set('search', search);
        if (location) params.set('location', location);
        params.set('page', '1'); // Reset to page 1 on filter change
        
        // Reload page with filters
        const url = params.toString() ? `?${params.toString()}` : window.location.pathname;
        window.location.href = url;
    }
    
    // Debounced search (triggers after 500ms of no typing)
    const debouncedSearch = debounce(applyFilters, 500);
    
    // Event listeners
    if (searchInput) {
        searchInput.addEventListener('input', debouncedSearch);
    }
    
    if (locationFilter) {
        locationFilter.addEventListener('change', applyFilters);
    }
});

// Clear all filters
function clearFilters() {
    window.location.href = window.location.pathname;
}
</script>

</body>
</html>
