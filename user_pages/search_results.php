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
$category = isset($_GET['category']) ? $_GET['category'] : 'all';
$search_query = isset($_GET['q']) ? trim($_GET['q']) : '';

// Build SQL query with filters
$sql = "
    SELECT 
        p.property_ID, p.StreetAddress, p.City, p.Province, p.PropertyType, 
        p.Bedrooms, p.Bathrooms, p.SquareFootage, p.ListingPrice, p.Status, p.Likes, p.ViewsCount,
        p.ParkingType, p.YearBuilt, p.ListingDate,
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
if ($search_query) {
    $escaped_q = $conn->real_escape_string($search_query);
    $sql .= " AND (p.StreetAddress LIKE '%$escaped_q%' OR p.City LIKE '%$escaped_q%' OR p.Province LIKE '%$escaped_q%' OR p.PropertyType LIKE '%$escaped_q%')";
}

// Add sorting based on category or sort param
if ($category === 'most_viewed') {
    $sql .= " ORDER BY p.ViewsCount DESC";
} elseif ($category === 'most_liked') {
    $sql .= " ORDER BY p.Likes DESC";
} elseif ($category === 'most_beds') {
    $sql .= " ORDER BY p.Bedrooms DESC, p.Bathrooms DESC";
} elseif ($category === 'for_sale') {
    if (!$status) $sql .= " AND p.Status = 'For Sale'";
    $sql .= " ORDER BY p.ListingDate DESC";
} elseif ($category === 'for_rent') {
    if (!$status) $sql .= " AND p.Status = 'For Rent'";
    $sql .= " ORDER BY p.ListingDate DESC";
} else {
    switch ($sort) {
        case 'price_low':
            $sql .= " ORDER BY p.ListingPrice ASC";
            break;
        case 'price_high':
            $sql .= " ORDER BY p.ListingPrice DESC";
            break;
        case 'most_viewed':
            $sql .= " ORDER BY p.ViewsCount DESC";
            break;
        case 'most_liked':
            $sql .= " ORDER BY p.Likes DESC";
            break;
        case 'newest':
        default:
            $sql .= " ORDER BY p.ListingDate DESC";
            break;
    }
}

$properties_result = $conn->query($sql);
$properties = $properties_result->fetch_all(MYSQLI_ASSOC);

// Get filter options
$cities_result = $conn->query("SELECT DISTINCT City FROM property WHERE approval_status = 'approved' ORDER BY City ASC");
$cities = $cities_result->fetch_all(MYSQLI_ASSOC);

$types_result = $conn->query("SELECT type_name AS PropertyType FROM property_types ORDER BY type_name ASC");
$types = $types_result->fetch_all(MYSQLI_ASSOC);

// Get dynamic price range bounds
$price_range_result = $conn->query("SELECT MIN(ListingPrice) AS minp, MAX(ListingPrice) AS maxp FROM property WHERE approval_status = 'approved' AND Status NOT IN ('Sold','Pending Sold')");
$price_range = $price_range_result ? $price_range_result->fetch_assoc() : ['minp' => 0, 'maxp' => 100000000];
$min_bound = isset($price_range['minp']) ? (int)$price_range['minp'] : 0;
$max_bound = isset($price_range['maxp']) ? (int)$price_range['maxp'] : 100000000;

// Count active filters
$active_filter_count = 0;
if ($category && $category !== 'all') $active_filter_count++;
if ($city) $active_filter_count++;
if ($property_type) $active_filter_count++;
if ($status) $active_filter_count++;
if ($min_price > 0) $active_filter_count++;
if ($max_price < 999999999) $active_filter_count++;
if ($bedrooms > 0) $active_filter_count++;
if ($bathrooms > 0) $active_filter_count++;

$conn->close();
?>
<?php if ($partial === 'grid') : ?>
    <div class="results-header">
        <div class="results-count">
            <span><?php echo count($properties); ?></span> Properties Found
            <?php if ($category && $category !== 'all'): ?>
                <span class="active-category-label"><?php echo ucwords(str_replace('_', ' ', $category)); ?></span>
            <?php endif; ?>
        </div>
        <div class="results-actions">
            <div class="sort-dropdown">
                <span class="sort-label">Sort by:</span>
                <select class="sort-select" id="sortSelect">
                    <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                    <option value="price_low" <?php echo $sort === 'price_low' ? 'selected' : ''; ?>>Price: Low to High</option>
                    <option value="price_high" <?php echo $sort === 'price_high' ? 'selected' : ''; ?>>Price: High to Low</option>
                    <option value="most_viewed" <?php echo $sort === 'most_viewed' ? 'selected' : ''; ?>>Most Viewed</option>
                    <option value="most_liked" <?php echo $sort === 'most_liked' ? 'selected' : ''; ?>>Most Liked</option>
                </select>
            </div>
            <button class="filter-toggle-btn" onclick="toggleDrawer()">
                <i class="bi bi-sliders"></i> Filters
                <span class="filter-badge-count" id="filterBadgeCount2" style="display:<?php echo $active_filter_count > 0 ? 'inline-flex' : 'none'; ?>"><?php echo $active_filter_count; ?></span>
            </button>
        </div>
    </div>
    <?php if (empty($properties)): ?>
        <div class="no-results">
            <i class="bi bi-house-x-fill"></i>
            <h3>No Properties Found</h3>
            <p>Try adjusting your filters or search to see more results</p>
        </div>
    <?php else: ?>
        <div class="properties-grid">
            <?php foreach ($properties as $property): ?>
                <div class="property-card">
                    <a href="property_details.php?id=<?php echo $property['property_ID']; ?>" class="property-card-link">
                        <div class="property-image-container">
                            <img src="../<?php echo htmlspecialchars($property['PhotoURL'] ?? 'images/placeholder.jpg'); ?>" 
                                 class="property-image" alt="Property Image" loading="lazy">
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
                            <div class="property-image-overlay"></div>
                        </div>
                        <div class="property-body">
                            <div class="property-price"><?php echo chr(0xE2).chr(0x82).chr(0xB1); ?><?php echo number_format($property['ListingPrice']); ?></div>
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
                                    <?php echo number_format($property['SquareFootage']); ?> ft
                                </div>
                            </div>
                            <div class="property-card-footer">
                                <div class="property-type"><?php echo htmlspecialchars($property['PropertyType']); ?></div>
                                <span class="view-details-link">View Details <i class="bi bi-arrow-right"></i></span>
                            </div>
                        </div>
                    </a>
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
            --gray-400: #a0aab5;
            --gray-500: #7a8a99;
            --gray-600: #5a6c7d;
            --gray-300: #c5cdd5;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, var(--black) 0%, var(--black-lighter) 100%);
            line-height: 1.6;
            color: var(--white);
            overflow-x: hidden;
        }

        .page-content {
            max-width: 1600px;
            margin: 0 auto;
            padding: 30px 24px 60px;
        }

        /* Category Buttons (in drawer) */
        .category-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        .category-btn {
            display: flex; align-items: center; justify-content: center; gap: 6px;
            padding: 10px 12px;
            background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08);
            border-radius: 8px; color: var(--gray-400); font-size: 0.8125rem;
            font-weight: 600; cursor: pointer; transition: all 0.15s;
            white-space: nowrap;
        }
        .category-btn:hover { 
            background: rgba(255,255,255,0.08); color: var(--white); 
            border-color: rgba(255,255,255,0.15);
            transform: translateY(-1px);
        }
        .category-btn.active {
            background: linear-gradient(135deg, var(--gold-dark), var(--gold));
            color: var(--black); border-color: var(--gold);
            box-shadow: 0 2px 8px rgba(212,175,55,0.3);
        }
        .category-btn.active i { color: var(--black); }
        .category-btn i { font-size: 0.85rem; }

        /* Results Header */
        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px; padding-bottom: 16px;
            border-bottom: 1px solid rgba(37,99,235,0.12);
        }
        .results-count {
            font-size: 0.875rem; color: var(--gray-400); font-weight: 500;
            white-space: nowrap;
        }
        .results-count span { color: var(--white); font-weight: 700; font-size: 1.125rem; }
        .active-category-label {
            display: block;
            color: var(--gray-500); font-weight: 500; font-size: 0.75rem;
            margin-top: 2px;
        }
        .results-actions {
            display: flex; align-items: center; gap: 8px;
        }
        .sort-dropdown { display: flex; align-items: center; gap: 8px; }
        .sort-label { font-size: 0.8125rem; color: var(--gray-500); white-space: nowrap; }
        .sort-select {
            height: 48px; padding: 0 40px 0 16px;
            background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1);
            border-radius: 8px; color: var(--white); font-size: 0.9375rem; font-weight: 500;
            cursor: pointer; -webkit-appearance: none; -moz-appearance: none; appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 10 6'><path fill='%23ffffff' d='M0 0l5 6 5-6z'/></svg>");
            background-repeat: no-repeat; background-position: right 12px center; background-size: 10px 6px;
            transition: border-color 0.15s, background 0.15s;
            min-width: 170px;
        }
        .sort-select:hover { border-color: rgba(255,255,255,0.2); background: rgba(255,255,255,0.06); }
        .sort-select:focus { outline: none; border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,0.12); }
        .sort-select option { background-color: var(--black-light); color: var(--white); }

        /* Filter Toggle Button */
        .filter-toggle-btn {
            display: flex; align-items: center; gap: 8px; height: 48px; padding: 0 18px;
            background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1);
            border-radius: 8px; color: var(--white); font-size: 0.9375rem; font-weight: 600;
            cursor: pointer; transition: all 0.15s; position: relative;
            white-space: nowrap;
        }
        .filter-toggle-btn:hover { background: rgba(37,99,235,0.12); border-color: rgba(37,99,235,0.3); }
        .filter-toggle-btn i { font-size: 1rem; }
        .filter-badge-count {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 22px; height: 22px; padding: 0 7px;
            background: var(--gold); color: var(--black); font-size: 0.75rem;
            font-weight: 700; border-radius: 11px;
        }

        /* Filter Drawer (Right Side) */
        .drawer-overlay {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.6); z-index: 1040;
            opacity: 0; visibility: hidden; transition: opacity 0.25s, visibility 0.25s;
        }
        .drawer-overlay.open { opacity: 1; visibility: visible; }
        .filter-drawer {
            position: fixed; top: 0; right: -420px; width: 400px; max-width: 90vw;
            height: 100vh; background: linear-gradient(180deg, #111 0%, #0a0a0a 100%);
            border-left: 1px solid rgba(37,99,235,0.15); z-index: 1050;
            display: flex; flex-direction: column; transition: right 0.3s ease; overflow: hidden;
        }
        .filter-drawer.open { right: 0; }
        .drawer-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 20px 24px; border-bottom: 1px solid rgba(255,255,255,0.08); flex-shrink: 0;
        }
        .drawer-header h3 {
            font-size: 1.125rem; font-weight: 700; color: var(--white);
            display: flex; align-items: center; gap: 10px;
        }
        .drawer-header h3 i { color: var(--gold); }
        .drawer-close {
            width: 36px; height: 36px; display: flex; align-items: center; justify-content: center;
            background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1);
            border-radius: 8px; color: var(--gray-400); font-size: 1.1rem; cursor: pointer;
            transition: all 0.15s;
        }
        .drawer-close:hover { background: rgba(239,68,68,0.15); border-color: rgba(239,68,68,0.3); color: #ef4444; }
        .drawer-body { flex: 1; overflow-y: auto; padding: 20px 24px; }
        .drawer-body::-webkit-scrollbar { width: 5px; }
        .drawer-body::-webkit-scrollbar-track { background: transparent; }
        .drawer-body::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 3px; }
        .drawer-footer {
            padding: 16px 24px; border-top: 1px solid rgba(255,255,255,0.08);
            display: flex; gap: 10px; flex-shrink: 0;
        }
        .drawer-footer .btn-apply {
            flex: 1; padding: 12px;
            background: linear-gradient(135deg, var(--gold-dark), var(--gold));
            border: none; border-radius: 8px; color: var(--black); font-weight: 700;
            font-size: 0.9rem; cursor: pointer; transition: all 0.2s;
        }
        .drawer-footer .btn-apply:hover { box-shadow: 0 4px 16px rgba(212,175,55,0.3); }
        .drawer-footer .btn-clear {
            padding: 12px 20px; background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.12); border-radius: 8px;
            color: var(--gray-300); font-weight: 600; font-size: 0.85rem;
            cursor: pointer; transition: all 0.15s;
        }
        .drawer-footer .btn-clear:hover { background: rgba(239,68,68,0.1); border-color: rgba(239,68,68,0.25); color: #ef4444; }

        /* Filter Groups */
        .filter-group { margin-bottom: 22px; }
        .filter-label {
            font-size: 0.8125rem; font-weight: 600; color: var(--gray-300);
            margin-bottom: 10px; display: flex; align-items: center; gap: 8px;
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .filter-label i { color: var(--gold); font-size: 0.9rem; }
        .filter-select {
            width: 100%; padding: 11px 14px; background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1); border-radius: 8px;
            color: var(--white); font-size: 0.875rem; transition: border-color 0.2s; cursor: pointer;
        }
        .filter-select:hover { border-color: rgba(255,255,255,0.2); }
        .filter-select:focus { outline: none; border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
        .filter-select option { background-color: var(--black-light); color: var(--white); }
        .filter-buttons { display: flex; gap: 6px; flex-wrap: wrap; }
        .filter-btn {
            padding: 8px 16px; background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1); border-radius: 8px;
            color: var(--gray-400); font-size: 0.8125rem; cursor: pointer;
            font-weight: 600; transition: all 0.15s;
        }
        .filter-btn:hover { background: rgba(255,255,255,0.1); color: var(--white); border-color: rgba(255,255,255,0.2); }
        .filter-btn.active { background: linear-gradient(135deg, var(--gold-dark), var(--gold)); color: var(--black); border-color: var(--gold); }

        /* Price Slider */
        .price-slider-container { position: relative; height: 40px; margin-bottom: 1rem; margin-top: 0.5rem; }
        .price-slider-track {
            position: absolute; top: 50%; left: 0; right: 0; height: 5px;
            background: rgba(255,255,255,0.08); border-radius: 3px; transform: translateY(-50%);
        }
        .price-slider-range { position: absolute; height: 100%; background: linear-gradient(90deg, var(--gold-dark), var(--gold)); border-radius: 3px; }
        .price-range-slider {
            position: absolute; width: 100%; height: 5px; top: 50%;
            transform: translateY(-50%); background: transparent;
            pointer-events: none; -webkit-appearance: none; appearance: none; margin: 0;
        }
        .price-range-slider::-webkit-slider-thumb {
            -webkit-appearance: none; width: 18px; height: 18px; border-radius: 50%;
            background: var(--gold); border: 2px solid var(--gold-dark); cursor: pointer;
            pointer-events: all; box-shadow: 0 2px 6px rgba(212,175,55,0.4);
        }
        .price-range-slider::-moz-range-thumb {
            width: 18px; height: 18px; border-radius: 50%;
            background: var(--gold); border: 2px solid var(--gold-dark); cursor: pointer;
            pointer-events: all; box-shadow: 0 2px 6px rgba(212,175,55,0.4);
        }
        .price-range-inputs { display: grid; grid-template-columns: 1fr auto 1fr; gap: 10px; align-items: center; }
        .price-input { position: relative; }
        .price-input input {
            width: 100%; padding: 9px 10px 9px 24px; background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1); border-radius: 6px;
            color: var(--white); font-size: 0.8125rem; font-weight: 600;
        }
        .price-input input:focus { outline: none; border-color: var(--blue); }
        .price-input .currency-symbol {
            position: absolute; left: 9px; top: 50%; transform: translateY(-50%);
            color: var(--gold); font-weight: 700; font-size: 0.75rem;
        }
        .range-divider { color: var(--gray-500); font-weight: 600; font-size: 0.8rem; }

        /* Active filter chips */
        .active-filters-bar { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
        .active-filters-bar:empty { margin-bottom: 0; }
        .filter-chip {
            display: flex; align-items: center; gap: 6px; padding: 6px 14px;
            background: rgba(37,99,235,0.1); border: 1px solid rgba(37,99,235,0.2);
            border-radius: 20px; color: var(--blue-light); font-size: 0.8rem; font-weight: 500;
        }
        .filter-chip .chip-remove { cursor: pointer; font-size: 0.9rem; opacity: 0.7; transition: opacity 0.15s; }
        .filter-chip .chip-remove:hover { opacity: 1; color: #ef4444; }

        /* Property Cards */
        .properties-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 18px;
        }
        .property-card {
            background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08);
            border-radius: 12px; overflow: hidden;
            transition: border-color 0.2s, box-shadow 0.2s, transform 0.2s; position: relative;
            content-visibility: auto;
            contain-intrinsic-size: auto 420px;
        }
        .property-card:hover {
            border-color: rgba(37,99,235,0.3); box-shadow: 0 12px 32px rgba(0,0,0,0.4);
            transform: translateY(-4px);
        }
        .property-card-link { text-decoration: none; color: inherit; display: block; }
        .property-image-container { position: relative; height: 220px; overflow: hidden; background-color: var(--black); }
        .property-image { width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s; will-change: transform; }
        .property-card:hover .property-image { transform: scale(1.06); }
        .property-image-overlay {
            position: absolute; bottom: 0; left: 0; right: 0; height: 60%;
            background: linear-gradient(transparent, rgba(0,0,0,0.5)); pointer-events: none;
        }
        .property-stats { position: absolute; right: 12px; top: 12px; display: flex; gap: 6px; z-index: 2; }
        .stat-badge {
            display: flex; align-items: center; gap: 5px; padding: 5px 10px;
            background: rgba(0,0,0,0.85); border-radius: 6px;
            font-size: 0.75rem; font-weight: 600; color: var(--white);
            border: 1px solid rgba(255,255,255,0.1);
        }
        .stat-badge.views i { color: var(--blue-light); }
        .stat-badge.likes i { color: #ef4444; }
        .property-badge {
            position: absolute; top: 12px; left: 12px; padding: 5px 12px;
            background: linear-gradient(135deg, var(--gold-dark), var(--gold));
            color: var(--black); font-size: 0.7rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px; border-radius: 6px; z-index: 2;
        }
        .property-badge.for-rent { background: linear-gradient(135deg, var(--blue-dark), var(--blue)); color: var(--white); }
        .property-body { padding: 18px 20px 20px; }
        .property-price {
            font-size: 1.4rem; font-weight: 800;
            background: linear-gradient(135deg, var(--gold), var(--gold-light));
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text; margin-bottom: 8px;
        }
        .property-address {
            font-size: 0.875rem; color: var(--gray-400); margin-bottom: 14px;
            display: flex; align-items: center; gap: 6px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .property-features { display: flex; gap: 14px; margin-bottom: 14px; flex-wrap: wrap; }
        .feature-item {
            display: flex; align-items: center; gap: 5px;
            font-size: 0.8125rem; color: var(--gray-300);
        }
        .feature-item i { color: var(--gray-500); font-size: 0.85rem; }
        .property-card-footer {
            display: flex; align-items: center; justify-content: space-between;
            padding-top: 14px; border-top: 1px solid rgba(255,255,255,0.06);
        }
        .property-type {
            font-size: 0.75rem; color: var(--gray-500);
            text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;
        }
        .view-details-link {
            font-size: 0.8125rem; color: var(--blue-light); font-weight: 600;
            display: flex; align-items: center; gap: 4px; transition: color 0.15s;
        }
        .property-card:hover .view-details-link { color: var(--gold); }
        .no-results {
            text-align: center; padding: 80px 20px;
            background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06); border-radius: 12px;
        }
        .no-results i { font-size: 4rem; color: var(--gray-600); margin-bottom: 20px; }
        .no-results h3 { font-size: 1.4rem; color: var(--white); margin-bottom: 10px; }
        .no-results p { color: var(--gray-400); }

        /* Responsive */
        @media (max-width: 968px) {
            .results-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            .results-actions { width: 100%; justify-content: flex-end; }
        }
        @media (max-width: 768px) {
            .page-content { padding: 20px 14px 60px; }
            .properties-grid { grid-template-columns: 1fr; }
            .results-header { gap: 10px; }
            .results-actions { width: 100%; justify-content: space-between; }
            .filter-drawer { width: 100%; max-width: 100vw; right: -100%; }
            .sort-label { display: none; }
            .sort-select { min-width: auto; }
            .category-buttons { grid-template-columns: 1fr; }
        }
        @media (min-width: 769px) and (max-width: 1024px) {
            .properties-grid { grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); }
        }
        @media (min-width: 1400px) {
            .properties-grid { grid-template-columns: repeat(4, 1fr); }
        }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<!-- Drawer Overlay -->
<div class="drawer-overlay" id="drawerOverlay" onclick="toggleDrawer()"></div>

<!-- Filter Drawer (Right Side) -->
<div class="filter-drawer" id="filterDrawer">
    <div class="drawer-header">
        <h3><i class="bi bi-sliders"></i> Filter Properties</h3>
        <button class="drawer-close" onclick="toggleDrawer()"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="drawer-body">
        <form id="filterForm" onsubmit="return false;">
            <div class="filter-group">
                <label class="filter-label"><i class="bi bi-grid-3x3-gap"></i> Categories</label>
                <div class="category-buttons">
                    <button type="button" class="category-btn <?php echo $category === 'most_viewed' ? 'active' : ''; ?>" data-category="most_viewed" onclick="setCategoryFilter('most_viewed')">
                        <i class="bi bi-eye-fill"></i> Most Viewed
                    </button>
                    <button type="button" class="category-btn <?php echo $category === 'most_liked' ? 'active' : ''; ?>" data-category="most_liked" onclick="setCategoryFilter('most_liked')">
                        <i class="bi bi-heart-fill"></i> Most Liked
                    </button>
                    <button type="button" class="category-btn <?php echo $category === 'most_beds' ? 'active' : ''; ?>" data-category="most_beds" onclick="setCategoryFilter('most_beds')">
                        <i class="bi bi-door-open-fill"></i> Most Beds
                    </button>
                    <button type="button" class="category-btn <?php echo $category === 'for_sale' ? 'active' : ''; ?>" data-category="for_sale" onclick="setCategoryFilter('for_sale')">
                        <i class="bi bi-house-door-fill"></i> For Sale
                    </button>
                    <button type="button" class="category-btn <?php echo $category === 'for_rent' ? 'active' : ''; ?>" data-category="for_rent" onclick="setCategoryFilter('for_rent')">
                        <i class="bi bi-key-fill"></i> For Rent
                    </button>
                </div>
            </div>
            <div class="filter-group">
                <label class="filter-label"><i class="bi bi-search"></i> Search</label>
                <input type="text" class="filter-select" id="searchInput" placeholder="Address, city, or type..." value="<?php echo htmlspecialchars($search_query); ?>" style="cursor: text;">
            </div>
            <div class="filter-group">
                <label class="filter-label"><i class="bi bi-geo-alt"></i> Location</label>
                <select class="filter-select" id="citySelect">
                    <option value="">All Cities</option>
                    <?php foreach ($cities as $c): ?>
                        <option value="<?php echo htmlspecialchars($c['City']); ?>" <?php echo $city === $c['City'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['City']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label class="filter-label"><i class="bi bi-building"></i> Property Type</label>
                <select class="filter-select" id="typeSelect">
                    <option value="">All Types</option>
                    <?php foreach ($types as $t): ?>
                        <option value="<?php echo htmlspecialchars($t['PropertyType']); ?>" <?php echo $property_type === $t['PropertyType'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($t['PropertyType']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label class="filter-label"><i class="bi bi-tag"></i> Status</label>
                <div class="filter-buttons">
                    <button type="button" class="filter-btn <?php echo $status === '' ? 'active' : ''; ?>" onclick="setFilter('status', '')">All</button>
                    <button type="button" class="filter-btn <?php echo $status === 'For Sale' ? 'active' : ''; ?>" onclick="setFilter('status', 'For Sale')">For Sale</button>
                    <button type="button" class="filter-btn <?php echo $status === 'For Rent' ? 'active' : ''; ?>" onclick="setFilter('status', 'For Rent')">For Rent</button>
                </div>
                <input type="hidden" id="statusInput" value="<?php echo htmlspecialchars($status); ?>">
            </div>
            <div class="filter-group">
                <label class="filter-label"><i class="bi bi-currency-exchange"></i> Price Range</label>
                <div class="price-slider-container">
                    <div class="price-slider-track">
                        <div class="price-slider-range" id="priceSliderRange"></div>
                    </div>
                    <input type="range" id="priceMinSlider" class="price-range-slider" min="<?php echo $min_bound; ?>" max="<?php echo $max_bound; ?>" value="<?php echo $min_price > 0 ? $min_price : $min_bound; ?>" step="100000">
                    <input type="range" id="priceMaxSlider" class="price-range-slider" min="<?php echo $min_bound; ?>" max="<?php echo $max_bound; ?>" value="<?php echo $max_price < 999999999 ? $max_price : $max_bound; ?>" step="100000">
                </div>
                <div class="price-range-inputs">
                    <div class="price-input">
                        <span class="currency-symbol">P</span>
                        <input type="text" id="priceMinDisplay" value="<?php echo number_format($min_price > 0 ? $min_price : $min_bound); ?>" readonly>
                    </div>
                    <span class="range-divider">-</span>
                    <div class="price-input">
                        <span class="currency-symbol">P</span>
                        <input type="text" id="priceMaxDisplay" value="<?php echo number_format($max_price < 999999999 ? $max_price : $max_bound); ?>" readonly>
                    </div>
                </div>
                <input type="hidden" id="minPriceInput" value="<?php echo $min_price > 0 ? $min_price : $min_bound; ?>">
                <input type="hidden" id="maxPriceInput" value="<?php echo $max_price < 999999999 ? $max_price : $max_bound; ?>">
            </div>
            <div class="filter-group">
                <label class="filter-label"><i class="bi bi-door-open"></i> Bedrooms</label>
                <div class="filter-buttons">
                    <button type="button" class="filter-btn <?php echo $bedrooms === 0 ? 'active' : ''; ?>" onclick="setFilter('bedrooms', 0)">Any</button>
                    <button type="button" class="filter-btn <?php echo $bedrooms === 1 ? 'active' : ''; ?>" onclick="setFilter('bedrooms', 1)">1+</button>
                    <button type="button" class="filter-btn <?php echo $bedrooms === 2 ? 'active' : ''; ?>" onclick="setFilter('bedrooms', 2)">2+</button>
                    <button type="button" class="filter-btn <?php echo $bedrooms === 3 ? 'active' : ''; ?>" onclick="setFilter('bedrooms', 3)">3+</button>
                    <button type="button" class="filter-btn <?php echo $bedrooms === 4 ? 'active' : ''; ?>" onclick="setFilter('bedrooms', 4)">4+</button>
                </div>
                <input type="hidden" id="bedroomsInput" value="<?php echo $bedrooms; ?>">
            </div>
            <div class="filter-group">
                <label class="filter-label"><i class="bi bi-droplet"></i> Bathrooms</label>
                <div class="filter-buttons">
                    <button type="button" class="filter-btn <?php echo $bathrooms === 0 ? 'active' : ''; ?>" onclick="setFilter('bathrooms', 0)">Any</button>
                    <button type="button" class="filter-btn <?php echo $bathrooms === 1 ? 'active' : ''; ?>" onclick="setFilter('bathrooms', 1)">1+</button>
                    <button type="button" class="filter-btn <?php echo $bathrooms === 2 ? 'active' : ''; ?>" onclick="setFilter('bathrooms', 2)">2+</button>
                    <button type="button" class="filter-btn <?php echo $bathrooms === 3 ? 'active' : ''; ?>" onclick="setFilter('bathrooms', 3)">3+</button>
                </div>
                <input type="hidden" id="bathroomsInput" value="<?php echo $bathrooms; ?>">
            </div>
            <input type="hidden" id="sortInput" value="<?php echo htmlspecialchars($sort); ?>">
            <input type="hidden" id="categoryInput" value="<?php echo htmlspecialchars($category); ?>">
        </form>
    </div>
    <div class="drawer-footer">
        <button class="btn-clear" onclick="clearFilters()"><i class="bi bi-x-circle me-1"></i> Reset</button>
        <button class="btn-apply" onclick="applyAndClose()"><i class="bi bi-check2 me-1"></i> Apply Filters</button>
    </div>
</div>

<!-- Page Content -->
<div class="page-content">
    <div class="active-filters-bar" id="activeFiltersBar"></div>

    <div id="resultsArea">
        <div class="results-header">
            <div class="results-count">
                <span><?php echo count($properties); ?></span> Properties Found
                <?php if ($category && $category !== 'all'): ?>
                    <span class="active-category-label"><?php echo ucwords(str_replace('_', ' ', $category)); ?></span>
                <?php endif; ?>
            </div>
            <div class="results-actions">
                <div class="sort-dropdown">
                    <span class="sort-label">Sort by:</span>
                    <select class="sort-select" id="sortSelect" onchange="setSort(this.value)">
                        <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                        <option value="price_low" <?php echo $sort === 'price_low' ? 'selected' : ''; ?>>Price: Low to High</option>
                        <option value="price_high" <?php echo $sort === 'price_high' ? 'selected' : ''; ?>>Price: High to Low</option>
                        <option value="most_viewed" <?php echo $sort === 'most_viewed' ? 'selected' : ''; ?>>Most Viewed</option>
                        <option value="most_liked" <?php echo $sort === 'most_liked' ? 'selected' : ''; ?>>Most Liked</option>
                    </select>
                </div>
                <button class="filter-toggle-btn" onclick="toggleDrawer()">
                    <i class="bi bi-sliders"></i> Filters
                    <span class="filter-badge-count" id="filterBadgeCount" style="display:<?php echo $active_filter_count > 0 ? 'inline-flex' : 'none'; ?>"><?php echo $active_filter_count; ?></span>
                </button>
            </div>
        </div>
        <?php if (empty($properties)): ?>
            <div class="no-results">
                <i class="bi bi-house-x-fill"></i>
                <h3>No Properties Found</h3>
                <p>Try adjusting your filters or search to see more results</p>
            </div>
        <?php else: ?>
            <div class="properties-grid">
                <?php foreach ($properties as $property): ?>
                    <div class="property-card">
                        <a href="property_details.php?id=<?php echo $property['property_ID']; ?>" class="property-card-link">
                            <div class="property-image-container">
                                <img src="../<?php echo htmlspecialchars($property['PhotoURL'] ?? 'images/placeholder.jpg'); ?>" class="property-image" alt="Property Image" loading="lazy">
                                <div class="property-badge <?php echo $property['Status'] === 'For Rent' ? 'for-rent' : ''; ?>">
                                    <?php echo htmlspecialchars($property['Status']); ?>
                                </div>
                                <div class="property-stats">
                                    <div class="stat-badge views"><i class="bi bi-eye-fill"></i><span><?php echo number_format($property['ViewsCount'] ?? 0); ?></span></div>
                                    <div class="stat-badge likes"><i class="bi bi-heart-fill"></i><span><?php echo number_format($property['Likes'] ?? 0); ?></span></div>
                                </div>
                                <div class="property-image-overlay"></div>
                            </div>
                            <div class="property-body">
                                <div class="property-price"><?php echo chr(0xE2).chr(0x82).chr(0xB1); ?><?php echo number_format($property['ListingPrice']); ?></div>
                                <div class="property-address">
                                    <i class="bi bi-geo-alt-fill"></i>
                                    <?php echo htmlspecialchars($property['StreetAddress']); ?>, <?php echo htmlspecialchars($property['City']); ?>
                                </div>
                                <div class="property-features">
                                    <div class="feature-item"><i class="bi bi-door-open-fill"></i> <?php echo $property['Bedrooms']; ?> Beds</div>
                                    <div class="feature-item"><i class="bi bi-droplet-fill"></i> <?php echo $property['Bathrooms']; ?> Baths</div>
                                    <div class="feature-item"><i class="bi bi-arrows-fullscreen"></i> <?php echo number_format($property['SquareFootage']); ?> ft</div>
                                </div>
                                <div class="property-card-footer">
                                    <div class="property-type"><?php echo htmlspecialchars($property['PropertyType']); ?></div>
                                    <span class="view-details-link">View Details <i class="bi bi-arrow-right"></i></span>
                                </div>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function debounce(fn, delay) {
        let t; return function(...a){ clearTimeout(t); t = setTimeout(()=>fn.apply(this,a), delay); };
    }
    function numberWithCommas(x) { return x.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ','); }

    function toggleDrawer() {
        const drawer = document.getElementById('filterDrawer');
        const overlay = document.getElementById('drawerOverlay');
        const isOpening = !drawer.classList.contains('open');
        
        if (isOpening) {
            // Calculate scrollbar width before hiding overflow
            const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
            document.body.style.overflow = 'hidden';
            document.body.style.paddingRight = scrollbarWidth + 'px';
        } else {
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        }
        
        drawer.classList.toggle('open');
        overlay.classList.toggle('open');
    }

    function applyAndClose() {
        updateResults();
        toggleDrawer();
    }

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
        const category = document.getElementById('categoryInput').value;
        const searchQ = document.getElementById('searchInput').value.trim();

        if (city) params.set('city', city);
        if (type) params.set('property_type', type);
        if (status !== '') params.set('status', status);
        if (Number(bedrooms) > 0) params.set('bedrooms', bedrooms);
        if (Number(bathrooms) > 0) params.set('bathrooms', bathrooms);
        if (Number(minPrice) > 0) params.set('min_price', minPrice);
        if (Number(maxPrice) < 999999999) params.set('max_price', maxPrice);
        if (sort) params.set('sort', sort);
        if (category && category !== 'all') params.set('category', category);
        if (searchQ) params.set('q', searchQ);
        params.set('partial', 'grid');
        return params.toString();
    }

    async function updateResults(){
        const qs = buildQuery();
        try {
            const res = await fetch('search_results.php?' + qs, { headers: { 'X-Requested-With': 'fetch' } });
            const html = await res.text();
            document.getElementById('resultsArea').innerHTML = html;
            const sortNew = document.getElementById('sortSelect');
            if (sortNew) sortNew.addEventListener('change', function(){ setSort(this.value); });
            
            // Update category buttons in drawer after AJAX
            const currentCategory = document.getElementById('categoryInput').value;
            document.querySelectorAll('.category-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.category === currentCategory);
            });
        } catch(e) { console.error('Update failed', e); }
        renderActiveFilters();
        updateFilterBadge();
    }

    function setFilter(name, value) {
        const input = document.getElementById(name + 'Input');
        input.value = value;
        refreshButtonGroup(name + 'Input');
        updateResults();
    }

    function refreshButtonGroup(inputId) {
        const inputEl = document.getElementById(inputId);
        if (!inputEl) return;
        const val = String(inputEl.value);
        const group = inputEl.closest('.filter-group');
        if (!group) return;
        group.querySelectorAll('.filter-btn').forEach(btn => {
            const t = btn.textContent.trim();
            let btnVal = t.replace('+','').trim();
            if (t.toLowerCase() === 'any') btnVal = '0';
            if (t.toLowerCase() === 'all') btnVal = '';
            (String(btnVal) === val) ? btn.classList.add('active') : btn.classList.remove('active');
        });
    }

    function setSort(value) {
        document.getElementById('sortInput').value = value;
        updateResults();
    }

    function setCategoryFilter(cat) {
        document.getElementById('categoryInput').value = cat;
        document.querySelectorAll('.category-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.category === cat);
        });
        updateResults();
    }

    function clearCategory() {
        document.getElementById('categoryInput').value = 'all';
        document.querySelectorAll('.category-btn').forEach(btn => {
            btn.classList.remove('active');
        });
    }

    function renderActiveFilters() {
        const bar = document.getElementById('activeFiltersBar');
        if (!bar) return;
        let chips = [];
        const city = document.getElementById('citySelect').value;
        const type = document.getElementById('typeSelect').value;
        const status = document.getElementById('statusInput').value;
        const beds = document.getElementById('bedroomsInput').value;
        const baths = document.getElementById('bathroomsInput').value;
        const searchQ = document.getElementById('searchInput').value.trim();
        const minP = Number(document.getElementById('minPriceInput').value);
        const maxP = Number(document.getElementById('maxPriceInput').value);
        const minBound = Number(document.getElementById('priceMinSlider').min);
        const maxBound = Number(document.getElementById('priceMaxSlider').max);

        const category = document.getElementById('categoryInput').value;
        if (category && category !== 'all') {
            const catNames = { most_viewed: 'Most Viewed', most_liked: 'Most Liked', most_beds: 'Most Beds', for_sale: 'For Sale', for_rent: 'For Rent' };
            chips.push({ label: catNames[category] || category, clear: () => { clearCategory(); updateResults(); } });
        }
        if (city) chips.push({ label: 'City: ' + city, clear: () => { document.getElementById('citySelect').value=''; updateResults(); } });
        if (type) chips.push({ label: 'Type: ' + type, clear: () => { document.getElementById('typeSelect').value=''; updateResults(); } });
        if (status) chips.push({ label: status, clear: () => { setFilter('status', ''); } });
        if (Number(beds) > 0) chips.push({ label: beds + '+ Beds', clear: () => { setFilter('bedrooms', 0); } });
        if (Number(baths) > 0) chips.push({ label: baths + '+ Baths', clear: () => { setFilter('bathrooms', 0); } });
        if (minP > minBound || maxP < maxBound) chips.push({ label: 'P' + numberWithCommas(minP) + ' - P' + numberWithCommas(maxP), clear: () => { resetPrice(); updateResults(); } });

        bar.innerHTML = chips.map((c, i) =>
            '<span class="filter-chip">' + c.label + ' <span class="chip-remove" data-idx="' + i + '"><i class="bi bi-x"></i></span></span>'
        ).join('');

        bar.querySelectorAll('.chip-remove').forEach((el, i) => {
            el.addEventListener('click', () => chips[i].clear());
        });
    }

    function updateFilterBadge() {
        let count = 0;
        const category = document.getElementById('categoryInput').value;
        if (category && category !== 'all') count++;
        if (document.getElementById('citySelect').value) count++;
        if (document.getElementById('typeSelect').value) count++;
        if (document.getElementById('statusInput').value) count++;
        if (Number(document.getElementById('bedroomsInput').value) > 0) count++;
        if (Number(document.getElementById('bathroomsInput').value) > 0) count++;
        const minP = Number(document.getElementById('minPriceInput').value);
        const maxP = Number(document.getElementById('maxPriceInput').value);
        const minBound = Number(document.getElementById('priceMinSlider').min);
        const maxBound = Number(document.getElementById('priceMaxSlider').max);
        if (minP > minBound || maxP < maxBound) count++;

        document.querySelectorAll('.filter-badge-count').forEach(el => {
            el.textContent = count;
            el.style.display = count > 0 ? 'inline-flex' : 'none';
        });
    }

    function updatePriceSliderRange() {
        const minSlider = document.getElementById('priceMinSlider');
        const maxSlider = document.getElementById('priceMaxSlider');
        const range = document.getElementById('priceSliderRange');
        if (!minSlider || !maxSlider || !range) return;
        const minVal = parseInt(minSlider.value);
        const maxVal = parseInt(maxSlider.value);
        const minPct = ((minVal - minSlider.min) / (minSlider.max - minSlider.min)) * 100;
        const maxPct = ((maxVal - maxSlider.min) / (maxSlider.max - maxSlider.min)) * 100;
        range.style.left = minPct + '%';
        range.style.width = (maxPct - minPct) + '%';
    }

    function resetPrice() {
        const minSlider = document.getElementById('priceMinSlider');
        const maxSlider = document.getElementById('priceMaxSlider');
        minSlider.value = minSlider.min;
        maxSlider.value = maxSlider.max;
        document.getElementById('minPriceInput').value = minSlider.min;
        document.getElementById('maxPriceInput').value = maxSlider.max;
        document.getElementById('priceMinDisplay').value = numberWithCommas(Number(minSlider.min));
        document.getElementById('priceMaxDisplay').value = numberWithCommas(Number(maxSlider.max));
        updatePriceSliderRange();
    }

    function clearFilters() {
        document.getElementById('citySelect').value = '';
        document.getElementById('typeSelect').value = '';
        document.getElementById('statusInput').value = '';
        document.getElementById('bedroomsInput').value = 0;
        document.getElementById('bathroomsInput').value = 0;
        document.getElementById('searchInput').value = '';
        clearCategory();
        resetPrice();
        refreshButtonGroup('statusInput');
        refreshButtonGroup('bedroomsInput');
        refreshButtonGroup('bathroomsInput');
        updateResults();
    }

    document.addEventListener('DOMContentLoaded', function(){
        const priceMinSlider = document.getElementById('priceMinSlider');
        const priceMaxSlider = document.getElementById('priceMaxSlider');
        const minInput = document.getElementById('minPriceInput');
        const maxInput = document.getElementById('maxPriceInput');
        const minDisplay = document.getElementById('priceMinDisplay');
        const maxDisplay = document.getElementById('priceMaxDisplay');
        const debouncedUpdate = debounce(updateResults, 250);

        if (priceMinSlider) {
            priceMinSlider.addEventListener('input', (e) => {
                let minVal = parseInt(e.target.value);
                let maxVal = parseInt(priceMaxSlider.value);
                const GAP = 500000;
                if (minVal > maxVal - GAP) { minVal = maxVal - GAP; e.target.value = minVal; }
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
                if (maxVal < minVal + GAP) { maxVal = minVal + GAP; e.target.value = maxVal; }
                maxInput.value = maxVal;
                maxDisplay.value = numberWithCommas(maxVal);
                updatePriceSliderRange();
                debouncedUpdate();
            });
        }

        updatePriceSliderRange();

        document.getElementById('citySelect').addEventListener('change', updateResults);
        document.getElementById('typeSelect').addEventListener('change', updateResults);

        const searchInput = document.getElementById('searchInput');
        const debouncedSearch = debounce(updateResults, 400);

        // Real-time search - trigger after 3+ characters or when cleared
        searchInput.addEventListener('input', function() {
            if (this.value.length >= 3 || this.value.length === 0) {
                debouncedSearch();
            }
        });

        renderActiveFilters();
        updateFilterBadge();
        
        // Sync category buttons on load
        const currentCategory = document.getElementById('categoryInput').value;
        document.querySelectorAll('.category-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.category === currentCategory);
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.getElementById('filterDrawer').classList.contains('open')) {
                toggleDrawer();
            }
        });

        // Real-time view count: intercept property card clicks
        document.addEventListener('click', function(e) {
            const link = e.target.closest('.property-card-link');
            if (!link) return;
            
            e.preventDefault();
            const href = link.getAttribute('href');
            const match = href.match(/id=(\d+)/);
            if (!match) { window.location.href = href; return; }
            
            const propId = parseInt(match[1]);
            const viewedProperties = JSON.parse(localStorage.getItem('viewedProperties') || '[]');
            
            if (!viewedProperties.includes(propId)) {
                // First time viewing - increment and update card in real-time
                const viewsSpan = link.querySelector('.stat-badge.views span');
                
                fetch('increment_property_view.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'property_id=' + propId
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success && viewsSpan) {
                        viewsSpan.textContent = data.views.toLocaleString();
                    }
                    if (data.success) {
                        viewedProperties.push(propId);
                        localStorage.setItem('viewedProperties', JSON.stringify(viewedProperties));
                    }
                })
                .catch(err => console.error('View count error:', err))
                .finally(() => {
                    setTimeout(() => { window.location.href = href; }, 200);
                });
            } else {
                // Already viewed - just navigate
                window.location.href = href;
            }
        });
    });
</script>

</body>
</html>
