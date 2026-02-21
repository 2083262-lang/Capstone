<?php
include '../connection.php';

// Get filter parameters
$city = isset($_GET['city']) ? $_GET['city'] : '';
$property_type = isset($_GET['property_type']) ? $_GET['property_type'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$min_price = isset($_GET['min_price']) ? (int)$_GET['min_price'] : 0;
$max_price = isset($_GET['max_price']) ? (int)$_GET['max_price'] : 999999999;
$bedrooms = isset($_GET['bedrooms']) ? (int)$_GET['bedrooms'] : 0;
$bathrooms = isset($_GET['bathrooms']) ? (int)$_GET['bathrooms'] : 0;
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'newest';
$partial = isset($_GET['partial']) ? $_GET['partial'] : '';

// Build SQL query with filters
$sql = "
    SELECT 
        p.property_ID, p.StreetAddress, p.City, p.State, p.PropertyType, 
        p.Bedrooms, p.Bathrooms, p.SquareFootage, p.ListingPrice, p.Status, p.Likes, p.ViewsCount,
        p.ParkingType, p.YearBuilt,
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
";

// Apply filters
if ($city) {
    $sql .= " AND p.City = '" . $conn->real_escape_string($city) . "'";
}
if ($property_type) {
    $sql .= " AND p.PropertyType = '" . $conn->real_escape_string($property_type) . "'";
}
if ($status) {
    $sql .= " AND p.Status = '" . $conn->real_escape_string($status) . "'";
}
if ($min_price > 0) {
    $sql .= " AND p.ListingPrice >= " . $min_price;
}
if ($max_price < 999999999) {
    $sql .= " AND p.ListingPrice <= " . $max_price;
}
if ($bedrooms > 0) {
    $sql .= " AND p.Bedrooms >= " . $bedrooms;
}
if ($bathrooms > 0) {
    $sql .= " AND p.Bathrooms >= " . $bathrooms;
}

// Add sorting
switch ($sort) {
    case 'price_low':
        $sql .= " ORDER BY p.ListingPrice ASC";
        break;
    case 'price_high':
        $sql .= " ORDER BY p.ListingPrice DESC";
        break;
    case 'newest':
    default:
        $sql .= " ORDER BY p.ListingDate DESC";
        break;
}

$properties_result = $conn->query($sql);
$properties = $properties_result->fetch_all(MYSQLI_ASSOC);

// Get filter options
$cities_result = $conn->query("SELECT DISTINCT City FROM property WHERE approval_status = 'approved' ORDER BY City ASC");
$cities = $cities_result->fetch_all(MYSQLI_ASSOC);

$types_result = $conn->query("SELECT DISTINCT PropertyType FROM property WHERE approval_status = 'approved' ORDER BY PropertyType ASC");
$types = $types_result->fetch_all(MYSQLI_ASSOC);

// Get dynamic price range bounds
$price_range_result = $conn->query("SELECT MIN(ListingPrice) AS minp, MAX(ListingPrice) AS maxp FROM property WHERE approval_status = 'approved' AND Status NOT IN ('Sold','Pending Sold')");
$price_range = $price_range_result ? $price_range_result->fetch_assoc() : ['minp' => 0, 'maxp' => 100000000];
$min_bound = isset($price_range['minp']) ? (int)$price_range['minp'] : 0;
$max_bound = isset($price_range['maxp']) ? (int)$price_range['maxp'] : 100000000;

$conn->close();
?>
<?php if ($partial === 'grid') : ?>
    <div class="results-header">
        <div class="results-count">
            <span><?php echo count($properties); ?></span> Properties Found
        </div>
        <div class="sort-dropdown">
            <span class="sort-label">Sort by:</span>
            <select class="sort-select" id="sortSelect">
                <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                <option value="price_low" <?php echo $sort === 'price_low' ? 'selected' : ''; ?>>Price: Low to High</option>
                <option value="price_high" <?php echo $sort === 'price_high' ? 'selected' : ''; ?>>Price: High to Low</option>
            </select>
        </div>
    </div>
    <?php if (empty($properties)): ?>
        <div class="no-results">
            <i class="bi bi-house-x-fill"></i>
            <h3>No Properties Found</h3>
            <p>Try adjusting your filters to see more results</p>
        </div>
    <?php else: ?>
        <div class="properties-grid">
            <?php foreach ($properties as $property): ?>
                <div class="property-card">
                    <div class="property-image-container">
                        <img src="../<?php echo htmlspecialchars($property['PhotoURL'] ?? 'images/placeholder.jpg'); ?>" 
                             class="property-image" alt="Property Image">
                        <div class="property-badge <?php echo $property['Status'] === 'For Rent' ? 'for-rent' : ''; ?>">
                            <?php echo htmlspecialchars($property['Status']); ?>
                        </div>
                        <div class="property-stats">
                            <div class="stat-badge views">
                                <i class="bi bi-eye-fill"></i>
                                <span><?php echo number_format($property['ViewsCount'] ?? 0); ?></span>
                            </div>
                            <div class="stat-badge likes">
                                <i class="bi bi-heart-fill"></i>
                                <span><?php echo number_format($property['Likes'] ?? 0); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="property-body">
                        <div class="property-price">₱<?php echo number_format($property['ListingPrice']); ?></div>
                        <div class="property-address">
                            <i class="bi bi-geo-alt-fill"></i>
                            <?php echo htmlspecialchars($property['StreetAddress']); ?>, <?php echo htmlspecialchars($property['City']); ?>
                        </div>
                        <div class="property-features">
                            <div class="feature-item">
                                <i class="bi bi-door-open-fill"></i>
                                <?php echo $property['Bedrooms']; ?> Beds
                            </div>
                            <div class="feature-item">
                                <i class="bi bi-droplet-fill"></i>
                                <?php echo $property['Bathrooms']; ?> Baths
                            </div>
                            <div class="feature-item">
                                <i class="bi bi-arrows-fullscreen"></i>
                                <?php echo number_format($property['SquareFootage']); ?> ft²
                            </div>
                        </div>
                        <div class="property-type"><?php echo htmlspecialchars($property['PropertyType']); ?></div>
                        <a href="property_details.php?id=<?php echo $property['property_ID']; ?>" class="view-details-btn">
                            View Details<i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php exit; endif; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Properties - HomeEstate Realty</title>
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
            --gray-600: #5a6c7d;
            --gray-700: #3d4f61;
            --gray-800: #253545;
            --gray-900: #1a1f24;
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

        .search-container {
            display: flex;
            min-height: calc(100vh - 80px);
            padding-top: 30px;
            max-width: 1800px;
            margin: 0 auto;
            gap: 20px;
        }

        /* Sidebar Filters */
        .filter-sidebar {
            width: 280px;
            background: linear-gradient(135deg, rgba(10, 10, 10, 0.35) 0%, rgba(15, 15, 15, 0.4) 100%);
            border: none;
            border-radius: 0;
            padding: 16px 14px;
            position: sticky;
            top: 90px;
            height: fit-content;
            overflow-y: auto;
            margin-left: 10px;
            box-shadow: none;
            backdrop-filter: blur(4px);
        }

        .filter-sidebar::-webkit-scrollbar {
            width: 6px;
        }

        .filter-sidebar::-webkit-scrollbar-track {
            background: rgba(37, 99, 235, 0.05);
            border-radius: 3px;
        }

        .filter-sidebar::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, var(--blue) 0%, var(--blue-dark) 100%);
            border-radius: 3px;
        }

        .filter-sidebar::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, var(--blue-light) 0%, var(--blue) 100%);
        }

        .filter-header {
            margin-bottom: 16px;
            padding-bottom: 0;
            border-bottom: none;
        }

        .filter-title {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 6px;
            text-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }

        .filter-subtitle {
            font-size: 0.8125rem;
            color: var(--gray-300);
            font-weight: 500;
        }

        .filter-group {
            margin-bottom: 16px;
            padding-bottom: 0;
            border-bottom: none;
        }

        .filter-group:last-child {
            border-bottom: none;
        }

        .filter-label {
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--white);
            margin-bottom: 8px;
            display: block;
        }

        .filter-select,
        .filter-input {
            width: 100%;
            padding: 10px 12px;
            background: linear-gradient(135deg, rgba(10, 10, 10, 0.55) 0%, rgba(15, 15, 15, 0.6) 100%);
            border: none;
            border-radius: 2px;
            color: var(--white);
            font-size: 0.875rem;
            transition-property: color, background-color;
            transition-duration: 0.15s;
            transition-timing-function: ease;
        }

        .filter-select:hover,
        .filter-input:hover {
            background: linear-gradient(135deg, rgba(15, 15, 15, 0.7) 0%, rgba(20, 20, 20, 0.75) 100%);
        }

        .filter-select:focus,
        .filter-input:focus {
            outline: none;
            box-shadow: none;
            background: linear-gradient(135deg, rgba(15, 15, 15, 0.75) 0%, rgba(22, 22, 22, 0.8) 100%);
        }
            color: var(--white);
            font-size: 0.9375rem;
            margin-bottom: 8px;
        }

        .filter-select:focus,
        .filter-input:focus {
            outline: none;
            border-color: var(--blue);
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.1);
        }

        .filter-select option {
            background-color: var(--black-light);
            color: var(--white);
            padding: 8px;
        }

        .price-range {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .filter-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .filter-btn {
            padding: 8px 14px;
            background: linear-gradient(135deg, rgba(10, 10, 10, 0.55) 0%, rgba(15, 15, 15, 0.6) 100%);
            border: none;
            border-radius: 2px;
            color: var(--gray-300);
            font-size: 0.875rem;
            cursor: pointer;
            font-weight: 600;
            transition: background-color 0.15s ease, color 0.15s ease;
        }

        .filter-btn:hover {
            background: linear-gradient(135deg, rgba(16, 16, 16, 0.7) 0%, rgba(22, 22, 22, 0.75) 100%);
            color: var(--white);
        }

        .filter-btn.active {
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 60%, var(--gold-dark) 100%);
            color: var(--black);
        }

        .clear-filters {
            width: 100%;
            padding: 10px;
            background: transparent;
            border: none;
            border-radius: 2px;
            color: var(--blue-light);
            font-size: 0.9375rem;
            font-weight: 600;
            cursor: pointer;
            margin-top: 12px;
            transition: color 0.15s ease;
        }

        .clear-filters:hover {
            color: var(--blue);
        }

        /* Properties Grid */
        .properties-content {
            flex: 1;
            padding: 10px;
            margin-right: 10px;
        }

        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid rgba(37, 99, 235, 0.15);
        }

        .results-count {
            font-size: 1.125rem;
            color: var(--white);
            font-weight: 600;
        }

        .results-count span {
            color: var(--gold);
            font-weight: 700;
        }

        .sort-dropdown {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sort-label {
            font-size: 0.875rem;
            color: var(--gray-400);
        }

        .sort-select {
            height: 40px;
            line-height: 40px;
            padding: 0 44px 0 12px;
            background: linear-gradient(135deg, rgba(10, 10, 10, 0.8) 0%, rgba(15, 15, 15, 0.9) 100%);
            border: 1px solid rgba(37, 99, 235, 0.2);
            border-radius: 10px;
            color: var(--white);
            font-size: 0.875rem;
            transition-property: border-color, box-shadow, color, background-color;
            transition-duration: 0.2s;
            transition-timing-function: ease;
            cursor: pointer;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 10 6'><path fill='%23ffffff' d='M0 0l5 6 5-6z'/></svg>");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 12px 8px;
            text-align: center;
            vertical-align: middle;
        }

        .sort-select:hover {
            border-color: rgba(37, 99, 235, 0.4);
            background: linear-gradient(135deg, rgba(15, 15, 15, 0.9) 0%, rgba(20, 20, 20, 0.95) 100%);
        }

        .sort-select:focus {
            outline: none;
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15),
                        inset 0 0 20px rgba(37, 99, 235, 0.08);
        }

        .sort-select option {
            background-color: var(--black-light);
            color: var(--white);
            padding: 8px 0;
            text-align: center;
            line-height: 32px;
        }

        .sort-select option:checked {
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 100%);
            color: var(--black);
        }

        /* Property Cards */
        .properties-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }

        .property-card {
            background: linear-gradient(135deg, rgba(26, 26, 26, 0.8) 0%, rgba(10, 10, 10, 0.9) 100%);
            border: 1px solid rgba(37, 99, 235, 0.15);
            border-radius: 4px;
            overflow: hidden;
            text-decoration: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
            position: relative;
        }

        .property-card::before {
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

        .property-card:hover {
            border-color: rgba(37, 99, 235, 0.3);
            box-shadow: 0 8px 24px rgba(37, 99, 235, 0.12);
        }

        .property-card:hover::before {
            opacity: 1;
        }

        .property-image-container {
            position: relative;
            height: 220px;
            overflow: hidden;
            background-color: var(--black);
        }

        .property-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .property-card:hover .property-image {
            transform: scale(1.05);
        }

        .property-stats {
            position: absolute;
            right: 12px;
            top: 12px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            z-index: 2;
        }

        .stat-badge {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            background: rgba(10, 10, 10, 0.85);
            backdrop-filter: blur(8px);
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--white);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .stat-badge i {
            font-size: 0.875rem;
        }

        .stat-badge.views i {
            color: var(--blue-light);
        }

        .stat-badge.likes {
            position: absolute;
            bottom: -170px;
            right: 0;
        }

        .stat-badge.likes i {
            color: #ef4444;
        }

        .property-badge {
            position: absolute;
            top: 12px;
            left: 12px;
            padding: 6px 12px;
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 50%, var(--gold-dark) 100%);
            color: var(--black);
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-radius: 2px;
            box-shadow: 0 4px 12px rgba(212, 175, 55, 0.4),
                        0 0 0 1px rgba(212, 175, 55, 0.3);
        }

        .property-badge.for-rent {
            background: linear-gradient(135deg, var(--blue-dark) 0%, var(--blue) 50%, var(--blue-dark) 100%);
            color: var(--white);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4),
                        0 0 0 1px rgba(37, 99, 235, 0.3);
        }

        .property-body {
            padding: 20px;
        }

        .property-price {
            font-size: 1.5rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 50%, var(--gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 12px;
            filter: drop-shadow(0 0 8px rgba(212, 175, 55, 0.2));
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .property-title {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 8px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .property-address {
            font-size: 0.9375rem;
            color: var(--gray-400);
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .property-address i {
            font-size: 0.875rem;
        }

        .property-features {
            display: flex;
            gap: 16px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .feature-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.875rem;
            color: var(--white);
        }

        .feature-item i {
            color: var(--gray-400);
        }

        .property-type {
            font-size: 0.8125rem;
            color: var(--gray-400);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 16px;
        }

        .view-details-btn {
            display: block;
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, var(--blue-dark) 0%, var(--blue) 100%);
            color: var(--white);
            text-align: center;
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 700;
            border-radius: 2px;
            transition: background 0.2s ease, transform 0.2s ease;
            border: 1px solid rgba(37, 99, 235, 0.3);
        }

        .view-details-btn:hover {
            background: linear-gradient(135deg, var(--blue) 0%, var(--blue-light) 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
            color: var(--white);
        }

        .view-details-btn i {
            margin-left: 6px;
        }

        .no-results {
            text-align: center;
            padding: 60px 16px;
            background: linear-gradient(135deg, rgba(26, 26, 26, 0.6) 0%, rgba(10, 10, 10, 0.75) 100%);
            border: none;
            border-radius: 4px;
        }

        /* Price slider (dual native range) */
        .price-slider-container {
            position: relative;
            height: 45px;
            margin-bottom: 1rem;
            margin-top: 0.5rem;
        }
        
        .price-slider-track {
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 6px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 3px;
            transform: translateY(-50%);
        }
        
        .price-slider-range {
            position: absolute;
            height: 100%;
            background: linear-gradient(90deg, var(--gold-dark), var(--gold));
            border-radius: 3px;
            transition: left 0.05s ease, width 0.05s ease;
        }
        
        .price-range-slider {
            position: absolute;
            width: 100%;
            height: 6px;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            pointer-events: none;
            -webkit-appearance: none;
            appearance: none;
            margin: 0;
        }
        
        .price-range-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 20px;
            height: 20px;
            border-radius: 2px;
            background: var(--gold);
            border: 2px solid var(--gold-dark);
            cursor: pointer;
            pointer-events: all;
            box-shadow: 0 2px 6px rgba(212, 175, 55, 0.4);
        }
        
        .price-range-slider::-webkit-slider-thumb:hover {
            box-shadow: 0 3px 10px rgba(212, 175, 55, 0.6);
            background: var(--gold-light);
        }
        
        .price-range-slider::-webkit-slider-thumb:active {
            box-shadow: 0 2px 8px rgba(212, 175, 55, 0.7);
        }
        
        .price-range-slider::-moz-range-thumb {
            width: 20px;
            height: 20px;
            border-radius: 2px;
            background: var(--gold);
            border: 2px solid var(--gold-dark);
            cursor: pointer;
            pointer-events: all;
            box-shadow: 0 2px 6px rgba(212, 175, 55, 0.4);
        }
        
        .price-range-slider::-moz-range-thumb:hover {
            box-shadow: 0 3px 10px rgba(212, 175, 55, 0.6);
            background: var(--gold-light);
        }
        
        .price-range-slider::-moz-range-thumb:active {
            box-shadow: 0 2px 8px rgba(212, 175, 55, 0.7);
        }
        
        .price-range-inputs {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            gap: 0.75rem;
            align-items: center;
        }
        
        .price-input {
            position: relative;
        }
        
        .price-input input {
            width: 100%;
            padding: 8px 10px 8px 26px;
            background: linear-gradient(135deg, rgba(10, 10, 10, 0.55) 0%, rgba(15, 15, 15, 0.6) 100%);
            border: none;
            border-radius: 2px;
            color: var(--white);
            font-size: 0.8125rem;
            font-weight: 600;
            text-align: left;
        }
        
        .price-input .currency-symbol {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gold);
            font-weight: 700;
            font-size: 0.75rem;
        }
        
        .range-divider {
            color: var(--gray-400);
            font-weight: 600;
            font-size: 0.875rem;
        }

        .no-results i {
            font-size: 4rem;
            color: var(--gray-600);
            margin-bottom: 24px;
        }

        .no-results h3 {
            font-size: 1.5rem;
            color: var(--white);
            margin-bottom: 12px;
        }

        .no-results p {
            font-size: 1rem;
            color: var(--gray-400);
        }

        /* Mobile Responsive */
        @media (max-width: 1024px) {
            .filter-sidebar {
                width: 280px;
            }

            .properties-grid {
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .search-container {
                padding-top: 90px;
            }

            .filter-sidebar {
                position: fixed;
                left: -100%;
                width: 280px;
                z-index: 1000;
                transition: left 0.3s ease;
                top: 80px;
                bottom: 0;
                margin-left: 0;
                border-radius: 0;
            }

            .filter-sidebar.show {
                left: 0;
            }

            .properties-content {
                margin-left: 0;
                margin-right: 0;
                padding: 10px;
            }

            .mobile-filter-toggle {
                position: fixed;
                bottom: 24px;
                left: 50%;
                transform: translateX(-50%);
                background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 50%, var(--gold-dark) 100%);
                color: var(--black);
                border: none;
                padding: 14px 32px;
                border-radius: 2px;
                font-weight: 700;
                z-index: 999;
                box-shadow: 0 4px 16px rgba(212, 175, 55, 0.3),
                            0 0 0 1px rgba(212, 175, 55, 0.2);
            }

            .filter-overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: rgba(0,0,0,0.7);
                z-index: 999;
            }

            .filter-overlay.show {
                display: block;
            }

            .properties-grid {
                grid-template-columns: 1fr;
            }

            .results-header {
                flex-direction: column;
                gap: 16px;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>

<!-- Include Navbar -->
<?php include 'navbar.php'; ?>

<div class="search-container">
    <!-- Filter Sidebar -->
    <aside class="filter-sidebar" id="filterSidebar">
        <div class="filter-header">
            <h2 class="filter-title">Filter Properties</h2>
            <p class="filter-subtitle">Refine your search</p>
        </div>

        <form method="GET" action="search_results.php" id="filterForm">
            <!-- Location Filter -->
            <div class="filter-group">
                <label class="filter-label">Location</label>
                <select name="city" class="filter-select" id="citySelect">
                    <option value="">All Cities</option>
                    <?php foreach ($cities as $c): ?>
                        <option value="<?php echo htmlspecialchars($c['City']); ?>" <?php echo $city === $c['City'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['City']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Property Type Filter -->
            <div class="filter-group">
                <label class="filter-label">Property Type</label>
                <select name="property_type" class="filter-select" id="typeSelect">
                    <option value="">All Types</option>
                    <?php foreach ($types as $t): ?>
                        <option value="<?php echo htmlspecialchars($t['PropertyType']); ?>" <?php echo $property_type === $t['PropertyType'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($t['PropertyType']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Status Filter -->
            <div class="filter-group">
                <label class="filter-label">Status</label>
                <div class="filter-buttons">
                    <button type="button" class="filter-btn <?php echo $status === '' ? 'active' : ''; ?>" onclick="setFilter('status', '')">All</button>
                    <button type="button" class="filter-btn <?php echo $status === 'For Sale' ? 'active' : ''; ?>" onclick="setFilter('status', 'For Sale')">For Sale</button>
                    <button type="button" class="filter-btn <?php echo $status === 'For Rent' ? 'active' : ''; ?>" onclick="setFilter('status', 'For Rent')">For Rent</button>
                </div>
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>" id="statusInput">
            </div>

            <!-- Price Range Filter -->
            <div class="filter-group">
                <label class="filter-label">Price Range</label>
                <div class="price-slider-container">
                    <div class="price-slider-track">
                        <div class="price-slider-range" id="priceSliderRange"></div>
                    </div>
                    <input type="range" id="priceMinSlider" class="price-range-slider" min="<?php echo $min_bound; ?>" max="<?php echo $max_bound; ?>" value="<?php echo $min_price > 0 ? $min_price : $min_bound; ?>" step="100000">
                    <input type="range" id="priceMaxSlider" class="price-range-slider" min="<?php echo $min_bound; ?>" max="<?php echo $max_bound; ?>" value="<?php echo $max_price < 999999999 ? $max_price : $max_bound; ?>" step="100000">
                </div>
                <div class="price-range-inputs">
                    <div class="price-input">
                        <span class="currency-symbol">₱</span>
                        <input type="text" id="priceMinDisplay" value="<?php echo number_format($min_price > 0 ? $min_price : $min_bound); ?>" readonly>
                    </div>
                    <span class="range-divider">—</span>
                    <div class="price-input">
                        <span class="currency-symbol">₱</span>
                        <input type="text" id="priceMaxDisplay" value="<?php echo number_format($max_price < 999999999 ? $max_price : $max_bound); ?>" readonly>
                    </div>
                </div>
                <input type="hidden" name="min_price" id="minPriceInput" value="<?php echo $min_price > 0 ? $min_price : $min_bound; ?>">
                <input type="hidden" name="max_price" id="maxPriceInput" value="<?php echo $max_price < 999999999 ? $max_price : $max_bound; ?>">
            </div>

            <!-- Bedrooms Filter -->
            <div class="filter-group">
                <label class="filter-label">Bedrooms</label>
                <div class="filter-buttons">
                    <button type="button" class="filter-btn <?php echo $bedrooms === 0 ? 'active' : ''; ?>" onclick="setFilter('bedrooms', 0)">Any</button>
                    <button type="button" class="filter-btn <?php echo $bedrooms === 1 ? 'active' : ''; ?>" onclick="setFilter('bedrooms', 1)">1+</button>
                    <button type="button" class="filter-btn <?php echo $bedrooms === 2 ? 'active' : ''; ?>" onclick="setFilter('bedrooms', 2)">2+</button>
                    <button type="button" class="filter-btn <?php echo $bedrooms === 3 ? 'active' : ''; ?>" onclick="setFilter('bedrooms', 3)">3+</button>
                    <button type="button" class="filter-btn <?php echo $bedrooms === 4 ? 'active' : ''; ?>" onclick="setFilter('bedrooms', 4)">4+</button>
                </div>
                <input type="hidden" name="bedrooms" value="<?php echo $bedrooms; ?>" id="bedroomsInput">
            </div>

            <!-- Bathrooms Filter -->
            <div class="filter-group">
                <label class="filter-label">Bathrooms</label>
                <div class="filter-buttons">
                    <button type="button" class="filter-btn <?php echo $bathrooms === 0 ? 'active' : ''; ?>" onclick="setFilter('bathrooms', 0)">Any</button>
                    <button type="button" class="filter-btn <?php echo $bathrooms === 1 ? 'active' : ''; ?>" onclick="setFilter('bathrooms', 1)">1+</button>
                    <button type="button" class="filter-btn <?php echo $bathrooms === 2 ? 'active' : ''; ?>" onclick="setFilter('bathrooms', 2)">2+</button>
                    <button type="button" class="filter-btn <?php echo $bathrooms === 3 ? 'active' : ''; ?>" onclick="setFilter('bathrooms', 3)">3+</button>
                </div>
                <input type="hidden" name="bathrooms" value="<?php echo $bathrooms; ?>" id="bathroomsInput">
            </div>

            <!-- Sort (hidden in sidebar, shown in header) -->
            <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>" id="sortInput">

            <button type="button" class="clear-filters" onclick="clearFilters()">
                <i class="bi bi-x-circle me-2"></i>Clear All Filters
            </button>
        </form>
    </aside>

    <!-- Properties Content -->
    <main class="properties-content">
        <div id="resultsArea">
        <!-- Results Header -->
        <div class="results-header">
            <div class="results-count">
                <span><?php echo count($properties); ?></span> Properties Found
            </div>
            <div class="sort-dropdown">
                <span class="sort-label">Sort by:</span>
                <select class="sort-select" onchange="setSort(this.value)" id="sortSelect">
                    <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                    <option value="price_low" <?php echo $sort === 'price_low' ? 'selected' : ''; ?>>Price: Low to High</option>
                    <option value="price_high" <?php echo $sort === 'price_high' ? 'selected' : ''; ?>>Price: High to Low</option>
                </select>
            </div>
        </div>

        <!-- Properties Grid -->
        <?php if (empty($properties)): ?>
            <div class="no-results">
                <i class="bi bi-house-x-fill"></i>
                <h3>No Properties Found</h3>
                <p>Try adjusting your filters to see more results</p>
            </div>
        <?php else: ?>
            <div class="properties-grid">
                <?php foreach ($properties as $property): ?>
                    <div class="property-card">
                        <div class="property-image-container">
                            <img src="../<?php echo htmlspecialchars($property['PhotoURL'] ?? 'images/placeholder.jpg'); ?>" 
                                 class="property-image" alt="Property Image">
                            <div class="property-badge <?php echo $property['Status'] === 'For Rent' ? 'for-rent' : ''; ?>">
                                <?php echo htmlspecialchars($property['Status']); ?>
                            </div>
                            <div class="property-stats">
                                <div class="stat-badge views">
                                    <i class="bi bi-eye-fill"></i>
                                    <span><?php echo number_format($property['ViewsCount'] ?? 0); ?></span>
                                </div>
                                <div class="stat-badge likes">
                                    <i class="bi bi-heart-fill"></i>
                                    <span><?php echo number_format($property['Likes'] ?? 0); ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="property-body">
                            <div class="property-price">₱<?php echo number_format($property['ListingPrice']); ?></div>
                            <div class="property-address">
                                <i class="bi bi-geo-alt-fill"></i>
                                <?php echo htmlspecialchars($property['StreetAddress']); ?>, <?php echo htmlspecialchars($property['City']); ?>
                            </div>
                            <div class="property-features">
                                <div class="feature-item">
                                    <i class="bi bi-door-open-fill"></i>
                                    <?php echo $property['Bedrooms']; ?> Beds
                                </div>
                                <div class="feature-item">
                                    <i class="bi bi-droplet-fill"></i>
                                    <?php echo $property['Bathrooms']; ?> Baths
                                </div>
                                <div class="feature-item">
                                    <i class="bi bi-arrows-fullscreen"></i>
                                    <?php echo number_format($property['SquareFootage']); ?> ft²
                                </div>
                            </div>
                            <div class="property-type"><?php echo htmlspecialchars($property['PropertyType']); ?></div>
                            <a href="property_details.php?id=<?php echo $property['property_ID']; ?>" class="view-details-btn">
                                View Details<i class="bi bi-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        </div>
    </main>
</div>

<!-- Mobile Filter Toggle -->
<button class="mobile-filter-toggle d-md-none" onclick="toggleFilters()">
    <i class="bi bi-funnel-fill me-2"></i>Filters
</button>

<!-- Filter Overlay (Mobile) -->
<div class="filter-overlay" id="filterOverlay" onclick="toggleFilters()"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Debounce helper
    function debounce(fn, delay) {
        let t; return function(...args){ clearTimeout(t); t = setTimeout(()=>fn.apply(this,args), delay); };
    }

    // Build query string from current controls
    function buildQuery() {
        const params = new URLSearchParams();
        const city = document.getElementById('citySelect').value;
        const type = document.getElementById('typeSelect').value;
        const status = document.getElementById('statusInput').value;
        const bedrooms = document.getElementById('bedroomsInput').value;
        const bathrooms = document.getElementById('bathroomsInput').value;
        const sort = document.getElementById('sortInput').value;
        const minPrice = document.getElementById('minPriceInput').value;
        const maxPrice = document.getElementById('maxPriceInput').value;

        if (city) params.set('city', city);
        if (type) params.set('property_type', type);
        if (status !== '') params.set('status', status);
        if (Number(bedrooms) > 0) params.set('bedrooms', bedrooms);
        if (Number(bathrooms) > 0) params.set('bathrooms', bathrooms);
        if (Number(minPrice) > 0) params.set('min_price', minPrice);
        if (Number(maxPrice) < 999999999) params.set('max_price', maxPrice);
        if (sort) params.set('sort', sort);
        params.set('partial', 'grid');
        return params.toString();
    }

    const updateResults = debounce(async function(){
        const qs = buildQuery();
        try {
            const res = await fetch('search_results.php?' + qs, { headers: { 'X-Requested-With': 'fetch' } });
            const html = await res.text();
            const container = document.getElementById('resultsArea');
            container.innerHTML = html;
            // Re-bind sort listener after replacement
            const sortSelectNew = document.getElementById('sortSelect');
            if (sortSelectNew) sortSelectNew.addEventListener('change', function(){ setSort(this.value); });
        } catch(e) { console.error('Update failed', e); }
    }, 150);

    // Store initial filter state (will be set on DOMContentLoaded)
    let initialFilters = null;

    function numberWithCommas(x) { return x.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ','); }

    // Filter button setter (status/bed/bath)
    function setFilter(name, value) {
        const input = document.getElementById(name + 'Input');
        input.value = value;
        // Refresh active state for the buttons in the corresponding filter group
        refreshButtonGroup(name + 'Input');
        updateResults();
    }

    // Helper to refresh active states for a button group tied to a hidden input
    function refreshButtonGroup(inputIdOrElement) {
        let inputEl = null;
        if (typeof inputIdOrElement === 'string') inputEl = document.getElementById(inputIdOrElement);
        else inputEl = inputIdOrElement;
        if (!inputEl) return;
        const val = String(inputEl.value);
        const group = inputEl.closest('.filter-group');
        if (!group) return;
        group.querySelectorAll('.filter-btn').forEach(btn => {
            const t = btn.textContent.trim();
            let btnVal = t.replace('+','').trim();
            if (t.toLowerCase() === 'any') btnVal = '0';
            if (t.toLowerCase() === 'all') btnVal = '';
            // Normalize: For status buttons like 'For Sale' keep text as-is
            // Compare string values
            if (String(btnVal) === val) btn.classList.add('active'); else btn.classList.remove('active');
        });
    }

    function setSort(value) {
        document.getElementById('sortInput').value = value;
        updateResults();
    }

    function clearFilters() {
        // If we captured an initial state, restore it
        if (initialFilters) {
            const citySel = document.getElementById('citySelect');
            const typeSel = document.getElementById('typeSelect');
            const statusIn = document.getElementById('statusInput');
            const bedroomsIn = document.getElementById('bedroomsInput');
            const bathroomsIn = document.getElementById('bathroomsInput');
            const sortIn = document.getElementById('sortInput');
            const priceMinSlider = document.getElementById('priceMinSlider');
            const priceMaxSlider = document.getElementById('priceMaxSlider');

            if (citySel) citySel.value = initialFilters.city;
            if (typeSel) typeSel.value = initialFilters.type;
            if (statusIn) statusIn.value = initialFilters.status;
            if (bedroomsIn) bedroomsIn.value = initialFilters.bedrooms;
            if (bathroomsIn) bathroomsIn.value = initialFilters.bathrooms;
            if (sortIn) sortIn.value = initialFilters.sort;

            if (priceMinSlider && priceMaxSlider) {
                priceMinSlider.value = initialFilters.min_price;
                priceMaxSlider.value = initialFilters.max_price;
                document.getElementById('minPriceInput').value = initialFilters.min_price;
                document.getElementById('maxPriceInput').value = initialFilters.max_price;
                document.getElementById('priceMinDisplay').value = numberWithCommas(Number(initialFilters.min_price));
                document.getElementById('priceMaxDisplay').value = numberWithCommas(Number(initialFilters.max_price));
                updatePriceSliderRange();
            }
            // Refresh button active states for status/bedrooms/bathrooms
            const statusInEl = document.getElementById('statusInput');
            if (statusInEl) {
                document.querySelectorAll('.filter-group .filter-buttons button').forEach(btn=>{
                    if (btn.textContent.trim().toLowerCase().includes('sale') && initialFilters.status==='For Sale') {
                        btn.classList.add('active');
                    } else if (btn.textContent.trim().toLowerCase().includes('rent') && initialFilters.status==='For Rent') {
                        btn.classList.add('active');
                    } else if (btn.textContent.trim().toLowerCase()==='all' && initialFilters.status==='') {
                        btn.classList.add('active');
                    } else {
                        btn.classList.remove('active');
                    }
                });
            }
            refreshButtonGroup('bedroomsInput');
            refreshButtonGroup('bathroomsInput');
        } else {
            // fallback: reset to full bounds
            const priceMinSlider = document.getElementById('priceMinSlider');
            const priceMaxSlider = document.getElementById('priceMaxSlider');
            if (priceMinSlider && priceMaxSlider) {
                priceMinSlider.value = priceMinSlider.min;
                priceMaxSlider.value = priceMaxSlider.max;
                document.getElementById('minPriceInput').value = priceMinSlider.min;
                document.getElementById('maxPriceInput').value = priceMaxSlider.max;
                document.getElementById('priceMinDisplay').value = numberWithCommas(Number(priceMinSlider.min));
                document.getElementById('priceMaxDisplay').value = numberWithCommas(Number(priceMaxSlider.max));
                updatePriceSliderRange();
            }
        }
        updateResults();
    }

    function toggleFilters() {
        const sidebar = document.getElementById('filterSidebar');
        const overlay = document.getElementById('filterOverlay');
        sidebar.classList.toggle('show');
        overlay.classList.toggle('show');
    }

    // Init dual range sliders
    document.addEventListener('DOMContentLoaded', function(){
        const priceMinSlider = document.getElementById('priceMinSlider');
        const priceMaxSlider = document.getElementById('priceMaxSlider');
        const priceSliderRange = document.getElementById('priceSliderRange');
        const minInput = document.getElementById('minPriceInput');
        const maxInput = document.getElementById('maxPriceInput');
        const minDisplay = document.getElementById('priceMinDisplay');
        const maxDisplay = document.getElementById('priceMaxDisplay');

        // numberWithCommas is defined globally to be reused by clear/reset

        function updatePriceSliderRange() {
            if (!priceMinSlider || !priceMaxSlider || !priceSliderRange) return;
            const minVal = parseInt(priceMinSlider.value);
            const maxVal = parseInt(priceMaxSlider.value);
            const minPercent = ((minVal - priceMinSlider.min) / (priceMinSlider.max - priceMinSlider.min)) * 100;
            const maxPercent = ((maxVal - priceMaxSlider.min) / (priceMaxSlider.max - priceMaxSlider.min)) * 100;
            priceSliderRange.style.left = minPercent + '%';
            priceSliderRange.style.width = (maxPercent - minPercent) + '%';
        }

        const debouncedUpdate = debounce(updateResults, 150);

        if (priceMinSlider) {
            priceMinSlider.addEventListener('input', (e) => {
                let minVal = parseInt(e.target.value);
                let maxVal = parseInt(priceMaxSlider.value);
                const GAP = 500000;
                if (minVal > maxVal - GAP) {
                    minVal = maxVal - GAP;
                    e.target.value = minVal;
                }
                minInput.value = minVal;
                minDisplay.value = numberWithCommas(minVal);
                updatePriceSliderRange();
                debouncedUpdate();
            });
        }

        if (priceMaxSlider) {
            priceMaxSlider.addEventListener('input', (e) => {
                let maxVal = parseInt(e.target.value);
                let minVal = parseInt(priceMinSlider.value);
                const GAP = 500000;
                if (maxVal < minVal + GAP) {
                    maxVal = minVal + GAP;
                    e.target.value = maxVal;
                }
                maxInput.value = maxVal;
                maxDisplay.value = numberWithCommas(maxVal);
                updatePriceSliderRange();
                debouncedUpdate();
            });
        }

        updatePriceSliderRange();

        // Selects
        const citySelect = document.getElementById('citySelect');
        const typeSelect = document.getElementById('typeSelect');
        const sortSelect = document.getElementById('sortSelect');
        if (citySelect) citySelect.addEventListener('change', updateResults);
        if (typeSelect) typeSelect.addEventListener('change', updateResults);
        if (sortSelect) sortSelect.addEventListener('change', function(){ setSort(this.value); });

        // Bedrooms/Bathrooms buttons handled via setFilter() calls inline

        // Capture initial state so Clear (Reset) restores defaults
        const statusInputEl = document.getElementById('statusInput');
        const bedroomsInputEl = document.getElementById('bedroomsInput');
        const bathroomsInputEl = document.getElementById('bathroomsInput');
        const sortInputEl = document.getElementById('sortInput');
        initialFilters = {
            city: citySelect ? citySelect.value : '',
            type: typeSelect ? typeSelect.value : '',
            status: statusInputEl ? statusInputEl.value : '',
            bedrooms: bedroomsInputEl ? bedroomsInputEl.value : 0,
            bathrooms: bathroomsInputEl ? bathroomsInputEl.value : 0,
            sort: sortInputEl ? sortInputEl.value : 'newest',
            min_price: priceMinSlider ? Number(priceMinSlider.value) : Number(document.getElementById('minPriceInput').value),
            max_price: priceMaxSlider ? Number(priceMaxSlider.value) : Number(document.getElementById('maxPriceInput').value)
        };
    });
</script>

</body>
</html>
