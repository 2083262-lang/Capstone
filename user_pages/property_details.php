<?php
include '../connection.php';
require_once __DIR__ . '/../config/paths.php';

// Initialize variables
$error_message = '';
$property_data = null;
$property_images = [];
$property_amenities = [];
$agent_info = null;
$rental_details = null;

// Get property ID from URL
$property_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($property_id <= 0) {
    $error_message = 'Invalid property ID provided.';
} else {
    // Fetch property details
    $property_sql = "
        SELECT
            p.property_ID, p.StreetAddress, p.City, p.Province, p.Barangay, p.PropertyType,
            p.YearBuilt, p.SquareFootage, p.LotSize, p.Bedrooms, p.Bathrooms,
            p.ListingPrice, p.Status, p.ListingDate, p.ListingDescription,
            p.ParkingType, p.MLSNumber, p.approval_status, p.Likes,
            COALESCE(p.ViewsCount,0) AS ViewsCount
        FROM property p
        WHERE p.property_ID = ? AND p.approval_status = 'approved'
    ";

    $stmt = $conn->prepare($property_sql);
    $stmt->bind_param("i", $property_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $property_data = $result->fetch_assoc();

        // View count is now handled client-side via AJAX (one-time per user per property)

        // If rental, fetch rental details
        if (isset($property_data['Status']) && trim($property_data['Status']) === 'For Rent') {
            $rd_sql = "SELECT monthly_rent, security_deposit, lease_term_months, furnishing, available_from FROM rental_details WHERE property_id = ? LIMIT 1";
            $stmt = $conn->prepare($rd_sql);
            $stmt->bind_param("i", $property_id);
            $stmt->execute();
            $rd_res = $stmt->get_result();
            if ($rd_res->num_rows > 0) {
                $rental_details = $rd_res->fetch_assoc();
            }
        }

        // Fetch property images
        $images_sql = "SELECT PhotoURL FROM property_images WHERE property_ID = ? ORDER BY SortOrder ASC";
        $stmt = $conn->prepare($images_sql);
        $stmt->bind_param("i", $property_id);
        $stmt->execute();
        $images_result = $stmt->get_result();
        $property_images = $images_result->fetch_all(MYSQLI_ASSOC);
        $property_images = array_column($property_images, 'PhotoURL');

        // Fetch floor images grouped by floor number
        $floor_images = [];
        $floor_images_sql = "SELECT floor_number, photo_url, sort_order FROM property_floor_images WHERE property_id = ? ORDER BY floor_number ASC, sort_order ASC";
        $stmt_floor = $conn->prepare($floor_images_sql);
        $stmt_floor->bind_param("i", $property_id);
        $stmt_floor->execute();
        $floor_result = $stmt_floor->get_result();
        while ($row = $floor_result->fetch_assoc()) {
            $floor_num = (int)$row['floor_number'];
            if (!isset($floor_images[$floor_num])) {
                $floor_images[$floor_num] = [];
            }
            $floor_images[$floor_num][] = $row['photo_url'];
        }
        $stmt_floor->close();

        // Fetch property amenities
        $amenities_sql = "
            SELECT a.amenity_name
            FROM amenities a
            JOIN property_amenities pa ON a.amenity_id = pa.amenity_id
            WHERE pa.property_id = ?
            ORDER BY a.amenity_name ASC
        ";
        $stmt = $conn->prepare($amenities_sql);
        $stmt->bind_param("i", $property_id);
        $stmt->execute();
        $amenities_result = $stmt->get_result();
        $property_amenities = $amenities_result->fetch_all(MYSQLI_ASSOC);
        $property_amenities = array_column($property_amenities, 'amenity_name');

        // Fetch agent information
        $agent_sql = "
            SELECT
                a.first_name, a.last_name, a.phone_number, a.email,
                COALESCE((SELECT GROUP_CONCAT(s.specialization_name ORDER BY s.specialization_name SEPARATOR ', ')
                          FROM agent_specializations asp
                          JOIN specializations s ON asp.specialization_id = s.specialization_id
                          WHERE asp.agent_info_id = ai.agent_info_id), '') AS specialization,
                ai.profile_picture_url, ai.license_number,
                adm.license_number AS admin_license,
                adm.profile_picture_url AS admin_profile_picture_url,
                ur.role_name AS user_role
            FROM accounts a
            JOIN property_log pl ON pl.account_id = a.account_id
            LEFT JOIN agent_information ai ON ai.account_id = a.account_id AND ai.is_approved = 1
            LEFT JOIN admin_information adm ON adm.account_id = a.account_id
            LEFT JOIN user_roles ur ON a.role_id = ur.role_id
            WHERE pl.property_id = ? AND pl.action = 'CREATED'
            LIMIT 1
        ";
        $stmt = $conn->prepare($agent_sql);
        $stmt->bind_param("i", $property_id);
        $stmt->execute();
        $agent_result = $stmt->get_result();

        if ($agent_result->num_rows > 0) {
            $agent_info = $agent_result->fetch_assoc();
            
            if ($agent_info['user_role'] === 'admin') {
                $agent_info['profile_picture_url'] = $agent_info['admin_profile_picture_url'];
                $agent_info['specialization'] = 'Licensed Real Estate Admin';
                $agent_info['license_number'] = $agent_info['admin_license'];
            }
        }
    } else {
        $error_message = 'Property not found or no longer available.';
    }

    $stmt->close();

    // Fetch similar properties (by type, price range, location, features)
    $similar_properties = [];
    if ($property_data) {
        $price_min = $property_data['ListingPrice'] * 0.7; // 30% lower
        $price_max = $property_data['ListingPrice'] * 1.3; // 30% higher
        
        $similar_sql = "
            SELECT 
                p.property_ID, p.StreetAddress, p.City, p.Province, p.PropertyType,
                p.Bedrooms, p.Bathrooms, p.SquareFootage, p.ListingPrice, p.Status,
                p.ListingDate, COALESCE(p.ViewsCount, 0) AS ViewsCount, COALESCE(p.Likes, 0) AS Likes,
                (SELECT pi.PhotoURL FROM property_images pi WHERE pi.property_ID = p.property_ID ORDER BY pi.SortOrder ASC LIMIT 1) AS PhotoURL
            FROM property p
            WHERE p.property_ID != ? 
                AND p.approval_status = 'approved'
                AND p.Status = ?
            ORDER BY 
                CASE WHEN p.PropertyType = ? THEN 0 ELSE 1 END,
                CASE WHEN p.City = ? THEN 0 ELSE 1 END,
                CASE WHEN p.ListingPrice BETWEEN ? AND ? THEN 0 ELSE 1 END,
                CASE WHEN ABS(p.Bedrooms - ?) <= 1 THEN 0 ELSE 1 END,
                RAND()
            LIMIT 4
        ";
        $stmt6 = $conn->prepare($similar_sql);
        $stmt6->bind_param(
            "isssddi", 
            $property_id, 
            $property_data['Status'], 
            $property_data['PropertyType'], 
            $property_data['City'], 
            $price_min, 
            $price_max, 
            $property_data['Bedrooms']
        );
        $stmt6->execute();
        $similar_result = $stmt6->get_result();
        $similar_properties = $similar_result->fetch_all(MYSQLI_ASSOC);
        $stmt6->close();
    }

    // Fetch price history for this property
    $price_history = [];
    $price_history_raw = []; // Raw data for chart
    if ($property_data) {
        $ph_sql = "SELECT event_date, event_type, price FROM price_history WHERE property_id = ? ORDER BY event_date ASC";
        $ph_stmt = $conn->prepare($ph_sql);
        if ($ph_stmt) {
            $ph_stmt->bind_param("i", $property_id);
            $ph_stmt->execute();
            $ph_result = $ph_stmt->get_result();
            $ph_raw = $ph_result->fetch_all(MYSQLI_ASSOC);
            $ph_stmt->close();

            // Build raw chart data (ascending order for chart)
            foreach ($ph_raw as $row) {
                $price_history_raw[] = [
                    'date' => $row['event_date'],
                    'price' => (float)$row['price'],
                    'event_type' => $row['event_type'],
                ];
            }

            // Build display data (descending for list, calculate % change)
            $ph_desc = array_reverse($ph_raw);
            for ($i = 0; $i < count($ph_desc); $i++) {
                $current_event = $ph_desc[$i];
                $previous_price = isset($ph_desc[$i + 1]) ? (float)$ph_desc[$i + 1]['price'] : null;
                $change_percentage = null;
                $change_direction = '';

                if ($previous_price && $previous_price > 0) {
                    $change = (((float)$current_event['price'] - $previous_price) / $previous_price) * 100;
                    $change_percentage = round($change, 2);
                    $change_direction = $change > 0 ? 'up' : ($change < 0 ? 'down' : '');
                }

                $price_history[] = [
                    'event_date' => date('M d, Y', strtotime($current_event['event_date'])),
                    'event_type' => $current_event['event_type'],
                    'price' => '₱' . number_format((float)$current_event['price'], 2),
                    'raw_price' => (float)$current_event['price'],
                    'change_percentage' => $change_percentage,
                    'change_direction' => $change_direction,
                ];
            }
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $property_data ? htmlspecialchars($property_data['StreetAddress']) . ' - ' . htmlspecialchars($property_data['City']) : 'Property Details'; ?> | HomeEstate Realty</title>
    <link href="<?= ASSETS_CSS ?>bootstrap.min.css" rel="stylesheet">
    <link href="<?= ASSETS_CSS ?>inter-font.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= ASSETS_CSS ?>bootstrap-icons.min.css">
    
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

        /* Breadcrumb */
        .breadcrumb-section {
            background: linear-gradient(135deg, rgba(10, 10, 10, 0.95) 0%, rgba(15, 15, 15, 0.98) 100%);
            padding: 20px 0;
            border-bottom: 1px solid rgba(37, 99, 235, 0.15);
            margin-top: 35px;
        }

        .breadcrumb {
            background: transparent;
            margin: 0;
            padding: 0;
            font-size: 0.875rem;
        }

        .breadcrumb-item {
            color: var(--gray-400);
        }

        .breadcrumb-item a {
            color: var(--blue-light);
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .breadcrumb-item a:hover {
            color: var(--blue);
        }

        .breadcrumb-item.active {
            color: var(--gray-300);
        }

        .breadcrumb-item + .breadcrumb-item::before {
            color: var(--gray-600);
        }

        /* Hero Section with Images */
        .property-hero {
            background: linear-gradient(135deg, rgba(26, 26, 26, 0.95) 0%, rgba(10, 10, 10, 0.98) 100%);
            padding: 60px 0;
            border-bottom: 1px solid rgba(37, 99, 235, 0.15);
        }

        .image-gallery-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 12px;
            height: 600px;
            border-radius: 4px;
            overflow: hidden;
        }

        .gallery-main {
            position: relative;
            overflow: hidden;
            background: var(--black);
            cursor: pointer;
        }

        .gallery-main img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .gallery-main:hover img {
            transform: scale(1.05);
        }

        .gallery-sidebar {
            display: grid;
            grid-template-rows: repeat(2, 1fr);
            gap: 12px;
        }

        .gallery-item {
            position: relative;
            overflow: hidden;
            background: var(--black);
            cursor: pointer;
            height: 100%;
            width: 100%;
        }

        .gallery-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .gallery-item:hover img {
            transform: scale(1.05);
        }

        .more-overlay {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.58);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 700;
            color: var(--white);
            letter-spacing: -0.5px;
            pointer-events: none;
        }

        .view-all-photos {
            position: absolute;
            bottom: 20px;
            right: 20px;
            background: rgba(10, 10, 10, 0.9);
            backdrop-filter: blur(8px);
            color: var(--white);
            padding: 12px 24px;
            border-radius: 2px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            z-index: 10;
        }

        .view-all-photos:hover {
            background: rgba(37, 99, 235, 0.9);
            border-color: var(--blue);
        }

        /* Floor Navigation Pills */
        .floor-pills {
            position: absolute;
            top: 20px;
            left: 20px;
            z-index: 10;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .floor-pill {
            padding: 10px 18px;
            background: rgba(10, 10, 10, 0.9);
            backdrop-filter: blur(8px);
            color: var(--white);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 2px;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .floor-pill:hover {
            background: rgba(37, 99, 235, 0.9);
            border-color: var(--blue);
        }

        .floor-pill.active {
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 100%);
            border-color: var(--gold);
            color: var(--black);
        }

        .floor-pill i {
            font-size: 1rem;
        }

        /* Property Header */
        .property-header {
            margin: 40px 0;
        }

        .property-title {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--white);
            margin-bottom: 16px;
            line-height: 1.2;
        }

        .property-address {
            font-size: 1.125rem;
            color: var(--gray-400);
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .property-meta {
            display: flex;
            gap: 32px;
            flex-wrap: wrap;
            align-items: center;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9375rem;
            color: var(--gray-300);
        }

        .meta-item i {
            color: var(--blue-light);
            font-size: 1.125rem;
        }

        .property-price {
            font-size: 3rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 50%, var(--gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            filter: drop-shadow(0 0 12px rgba(212, 175, 55, 0.3));
        }

        /* Content Grid */
        .property-content {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 40px;
            margin-top: 40px;
        }

        /* Main Content Sections */
        .content-card {
            background: linear-gradient(135deg, rgba(26, 26, 26, 0.8) 0%, rgba(10, 10, 10, 0.9) 100%);
            border: 1px solid rgba(37, 99, 235, 0.15);
            border-radius: 4px;
            padding: 32px;
            margin-bottom: 24px;
            position: relative;
        }

        .content-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--blue), transparent);
            opacity: 0.5;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .section-title i {
            color: var(--gold);
            font-size: 1.25rem;
        }

        .description-text {
            font-size: 1rem;
            line-height: 1.8;
            color: var(--gray-300);
        }

        /* Features Grid */
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .feature-box {
            background: rgba(37, 99, 235, 0.05);
            border: 1px solid rgba(37, 99, 235, 0.15);
            border-radius: 2px;
            padding: 20px;
            text-align: center;
            transition: all 0.2s ease;
        }

        .feature-box:hover {
            border-color: rgba(37, 99, 235, 0.3);
            box-shadow: 0 4px 16px rgba(37, 99, 235, 0.15);
        }

        .feature-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 100%);
            border-radius: 2px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 12px;
        }

        .feature-icon i {
            font-size: 24px;
            color: var(--black);
        }

        .feature-label {
            font-size: 0.75rem;
            color: var(--gray-400);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .feature-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--white);
        }

        /* Amenities */
        .amenities-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
        }

        .amenity-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background: rgba(212, 175, 55, 0.05);
            border-radius: 2px;
            font-size: 0.875rem;
            color: var(--gray-300);
        }

        .amenity-item i {
            color: var(--gold);
            font-size: 1rem;
        }

        /* Sidebar */
        .sticky-sidebar {
            position: sticky;
            top: 100px;
            align-self: start;
        }

        /* Agent Card */
        .agent-card {
            background: linear-gradient(135deg, rgba(26, 26, 26, 0.8) 0%, rgba(10, 10, 10, 0.9) 100%);
            border: 1px solid rgba(37, 99, 235, 0.15);
            border-radius: 4px;
            padding: 28px;
            text-align: center;
            margin-bottom: 24px;
        }

        .agent-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), transparent);
            opacity: 0.5;
        }

        .agent-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            margin: 0 auto 16px;
            border: 3px solid var(--gold);
            object-fit: cover;
        }

        .agent-name {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 4px;
        }

        .agent-title {
            font-size: 0.875rem;
            color: var(--gray-400);
            margin-bottom: 20px;
        }

        .contact-btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, var(--blue-dark) 0%, var(--blue) 100%);
            color: var(--white);
            border: none;
            border-radius: 2px;
            font-weight: 700;
            font-size: 0.9375rem;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-bottom: 12px;
        }

        .contact-btn:hover {
            background: linear-gradient(135deg, var(--blue) 0%, var(--blue-light) 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(37, 99, 235, 0.3);
        }

        .contact-btn i {
            margin-right: 8px;
        }

        /* Image Lightbox (Facebook-style) */
        .lightbox {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.96);
            z-index: 10000;
            align-items: center;
            justify-content: center;
        }

        .lightbox.active {
            display: flex;
        }

        .lightbox-content {
            position: relative;
            max-width: 90%;
            max-height: 90%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .lightbox-image {
            max-width: 100%;
            max-height: 90vh;
            object-fit: contain;
        }

        .lightbox-close {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 48px;
            height: 48px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            color: white;
            font-size: 24px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10001;
        }

        .lightbox-close:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .lightbox-prev,
        .lightbox-next {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 56px;
            height: 56px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            color: white;
            font-size: 24px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10001;
        }

        .lightbox-prev {
            left: 40px;
        }

        .lightbox-next {
            right: 40px;
        }

        .lightbox-prev:hover,
        .lightbox-next:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .lightbox-counter {
            position: absolute;
            bottom: 40px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.7);
            color: white;
            padding: 10px 20px;
            border-radius: 20px;
            font-size: 0.875rem;
            z-index: 10001;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .property-content {
                grid-template-columns: 1fr;
            }

            .sticky-sidebar {
                position: static;
            }
        }

        @media (max-width: 768px) {
            .image-gallery-grid {
                grid-template-columns: 1fr;
                height: auto;
            }

            .gallery-main {
                height: 400px;
            }

            .gallery-sidebar {
                grid-template-columns: repeat(2, 1fr);
                grid-template-rows: auto;
            }

            .gallery-item {
                height: 200px;
            }

            .property-title {
                font-size: 2rem;
            }

            .property-price {
                font-size: 2rem;
            }

            .features-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .lightbox-prev {
                left: 10px;
            }

            .lightbox-next {
                right: 10px;
            }
        }

        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 8px 16px;
            background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 50%, var(--gold-dark) 100%);
            color: var(--black);
            font-size: 0.875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-radius: 2px;
            margin-bottom: 16px;
        }

        .status-badge.for-rent {
            background: linear-gradient(135deg, var(--blue-dark) 0%, var(--blue) 50%, var(--blue-dark) 100%);
            color: var(--white);
        }

        /* Property Stats */
        .property-stats {
            display: flex;
            gap: 24px;
            padding: 20px 0;
            border-top: 1px solid rgba(37, 99, 235, 0.15);
            border-bottom: 1px solid rgba(37, 99, 235, 0.15);
            margin: 20px 0;
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .stat-item i {
            color: var(--blue-light);
        }

        .stat-item.likes i {
            color: #ef4444;
        }

        .stat-value {
            font-weight: 600;
            color: var(--white);
        }

        /* Like Button */
        .like-button-container {
            position: fixed;
            bottom: 40px;
            right: 40px;
            z-index: 1000;
        }

        .like-button {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, rgba(10, 10, 10, 0.95) 0%, rgba(26, 26, 26, 0.95) 100%);
            border: 2px solid rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
            position: relative;
            overflow: hidden;
        }

        .like-button::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(239, 68, 68, 0.3);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .like-button:hover {
            transform: translateY(-4px) scale(1.05);
            border-color: rgba(239, 68, 68, 0.5);
            box-shadow: 0 12px 32px rgba(239, 68, 68, 0.3);
        }

        .like-button:active::before {
            width: 100px;
            height: 100px;
        }

        .like-button.liked {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            border-color: #ef4444;
            box-shadow: 0 8px 24px rgba(239, 68, 68, 0.5);
        }

        .like-button.liked:hover {
            box-shadow: 0 12px 32px rgba(239, 68, 68, 0.6);
        }

        .like-button i {
            font-size: 28px;
            color: rgba(255, 255, 255, 0.7);
            transition: all 0.3s ease;
        }

        .like-button:hover i {
            color: #ef4444;
            transform: scale(1.1);
        }

        .like-button.liked i {
            color: var(--white);
            animation: heartBeat 0.6s ease;
        }

        .like-count {
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.8);
            font-weight: 700;
            margin-top: 2px;
        }

        .like-button.liked .like-count {
            color: var(--white);
        }

        /* Like count pulse animation for real-time updates */
        @keyframes likePulse {
            0%   { transform: scale(1); }
            40%  { transform: scale(1.25); color: var(--gold); }
            100% { transform: scale(1); }
        }
        .like-pulse {
            animation: likePulse 0.45s ease-out;
        }

        @keyframes heartBeat {
            0%, 100% { transform: scale(1); }
            10%, 30% { transform: scale(0.9); }
            20%, 40%, 60%, 80% { transform: scale(1.1); }
            50%, 70% { transform: scale(1.05); }
        }

        /* Like tooltip */
        .like-tooltip {
            position: absolute;
            bottom: 80px;
            right: 0;
            background: rgba(10, 10, 10, 0.95);
            color: var(--white);
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 0.875rem;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .like-button-container:hover .like-tooltip {
            opacity: 1;
        }

        /* Mobile adjustments */
        @media (max-width: 768px) {
            .like-button-container {
                bottom: 24px;
                right: 24px;
            }

            .like-button {
                width: 60px;
                height: 60px;
            }

            .like-button i {
                font-size: 24px;
            }
        }

        /* Similar Properties Section */
        .similar-properties-section {
            padding: 60px 0;
            border-top: 1px solid rgba(37, 99, 235, 0.15);
        }

        .similar-properties-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .similar-properties-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 8px;
        }

        .similar-properties-subtitle {
            font-size: 1rem;
            color: var(--gray-400);
        }

        .similar-properties-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 24px;
        }

        .similar-property-card {
            background: linear-gradient(135deg, rgba(26, 26, 26, 0.95) 0%, rgba(17, 17, 17, 0.98) 100%);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 4px;
            overflow: hidden;
            transition: all 0.2s ease;
            text-decoration: none;
            color: inherit;
            display: block;
        }

        .similar-property-card:hover {
            transform: translateY(-4px);
            border-color: rgba(37, 99, 235, 0.3);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
        }

        .similar-property-img {
            position: relative;
            height: 220px;
            overflow: hidden;
            background: var(--black);
        }

        .similar-property-img img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.2s ease;
        }

        .similar-property-card:hover .similar-property-img img {
            transform: scale(1.05);
        }

        .similar-property-badge {
            position: absolute;
            top: 12px;
            left: 12px;
            padding: 6px 14px;
            background: linear-gradient(135deg, var(--blue) 0%, var(--blue-dark) 100%);
            color: var(--white);
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-radius: 2px;
        }

        .similar-property-badge.for-rent {
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-dark) 100%);
            color: var(--black);
        }

        .similar-property-stats {
            position: absolute;
            bottom: 12px;
            right: 12px;
            display: flex;
            gap: 8px;
        }

        .similar-stat-badge {
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            background: rgba(0, 0, 0, 0.7);
            border-radius: 2px;
            font-size: 0.75rem;
            color: var(--white);
            font-weight: 600;
        }

        .similar-stat-badge i {
            font-size: 0.6875rem;
        }

        .similar-stat-badge.views i {
            color: var(--blue-light);
        }

        .similar-stat-badge.likes i {
            color: #ef4444;
        }

        .similar-property-body {
            padding: 20px;
        }

        .similar-property-price {
            font-size: 1.375rem;
            font-weight: 800;
            color: var(--gold);
            margin-bottom: 8px;
        }

        .similar-property-address {
            font-size: 0.875rem;
            color: var(--gray-300);
            display: flex;
            align-items: flex-start;
            gap: 6px;
            margin-bottom: 14px;
            line-height: 1.4;
        }

        .similar-property-address i {
            color: var(--blue-light);
            margin-top: 2px;
            flex-shrink: 0;
        }

        .similar-property-features {
            display: flex;
            gap: 16px;
            padding-top: 14px;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
        }

        .similar-feature {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.8125rem;
            color: var(--gray-400);
            font-weight: 500;
        }

        .similar-feature i {
            color: var(--blue-light);
            font-size: 0.875rem;
        }

        @media (max-width: 768px) {
            .similar-properties-grid {
                grid-template-columns: 1fr;
            }
        }

        /* ===== Price History Chart ===== */
        .ph-chart-wrapper {
            position: relative;
            width: 100%;
            height: 320px;
            margin-bottom: 20px;
        }

        .ph-chart-wrapper canvas {
            border-radius: 8px;
        }

        /* Summary stats row */
        .ph-stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
            margin-bottom: 24px;
        }

        .ph-stat-card {
            background: rgba(26, 26, 26, 0.6);
            border: 1px solid var(--black-border);
            border-radius: 8px;
            padding: 14px 16px;
            text-align: center;
            transition: border-color 0.25s, transform 0.25s;
        }

        .ph-stat-card:hover {
            border-color: rgba(212, 175, 55, 0.3);
            transform: translateY(-2px);
        }

        .ph-stat-label {
            font-size: 0.72rem;
            font-weight: 600;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .ph-stat-value {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--white);
        }

        .ph-stat-value.stat-up { color: #4ade80; }
        .ph-stat-value.stat-down { color: #f87171; }

        /* Date filter controls */
        .ph-filter-bar {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .ph-filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .ph-filter-label {
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--gray-400);
            white-space: nowrap;
        }

        .ph-date-input {
            background: rgba(10, 10, 10, 0.7);
            border: 1px solid var(--black-border);
            border-radius: 6px;
            color: var(--white);
            padding: 8px 12px;
            font-size: 0.82rem;
            font-family: 'Inter', sans-serif;
            font-weight: 500;
            outline: none;
            transition: border-color 0.25s, box-shadow 0.25s;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            min-width: 150px;
        }

        .ph-date-input:hover {
            border-color: rgba(212, 175, 55, 0.3);
        }

        .ph-date-input:focus {
            border-color: var(--gold);
            box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.15);
        }

        /* Webkit date picker customization */
        .ph-date-input::-webkit-calendar-picker-indicator {
            filter: invert(0.7) sepia(1) saturate(3) hue-rotate(10deg);
            cursor: pointer;
            opacity: 0.7;
            transition: opacity 0.2s;
        }

        .ph-date-input::-webkit-calendar-picker-indicator:hover {
            opacity: 1;
        }

        .ph-date-input::-webkit-datetime-edit-fields-wrapper {
            padding: 0;
        }

        .ph-date-input::-webkit-date-and-time-value {
            text-align: left;
        }

        .ph-filter-separator {
            color: var(--gray-500);
            font-size: 0.82rem;
            font-weight: 500;
        }

        .ph-filter-reset {
            background: transparent;
            border: 1px solid rgba(212, 175, 55, 0.25);
            border-radius: 6px;
            color: var(--gold);
            padding: 7px 14px;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.25s;
            display: flex;
            align-items: center;
            gap: 4px;
            font-family: 'Inter', sans-serif;
        }

        .ph-filter-reset:hover {
            background: rgba(212, 175, 55, 0.1);
            border-color: var(--gold);
        }

        .ph-no-data {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray-500);
            font-size: 0.9rem;
        }

        .ph-no-data i {
            font-size: 2rem;
            display: block;
            margin-bottom: 10px;
            color: var(--gray-600);
        }

        @media (max-width: 768px) {
            .ph-chart-wrapper {
                height: 240px;
            }
            .ph-filter-bar {
                flex-direction: column;
                align-items: stretch;
            }
            .ph-filter-group {
                width: 100%;
            }
            .ph-date-input {
                flex: 1;
                min-width: unset;
            }
            .ph-stats-row {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        /* ================================================================
           SKELETON SCREEN SYSTEM — Dark User Portal Theme
           CSR / Progressive Hydration
           ================================================================ */
        @keyframes sk-shimmer {
            0%   { background-position: -800px 0; }
            100% { background-position:  800px 0; }
        }
        .sk-shimmer {
            background: linear-gradient(
                90deg,
                rgba(255,255,255,0.03) 25%,
                rgba(255,255,255,0.06) 50%,
                rgba(255,255,255,0.03) 75%
            );
            background-size: 1600px 100%;
            animation: sk-shimmer 1.6s ease-in-out infinite;
            border-radius: 4px;
        }
        #page-content { display: none; }
        .sk-line { display: block; border-radius: 4px; }

        .sk-breadcrumb {
            background: linear-gradient(135deg, rgba(10,10,10,0.95) 0%, rgba(15,15,15,0.98) 100%);
            padding: 20px 0; margin-top: 35px;
            border-bottom: 1px solid rgba(37,99,235,0.15);
        }
        .sk-hero {
            background: linear-gradient(135deg, rgba(26,26,26,0.95) 0%, rgba(10,10,10,0.98) 100%);
            padding: 60px 0;
            border-bottom: 1px solid rgba(37,99,235,0.15);
        }
        .sk-gallery-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 12px;
            height: 600px;
            border-radius: 4px;
            overflow: hidden;
        }
        .sk-gallery-sidebar {
            display: grid;
            grid-template-rows: 1fr 1fr;
            gap: 12px;
        }
        .sk-prop-header {
            padding: 40px 20px 0;
        }
        .sk-content-grid {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 40px;
            margin-top: 40px;
            padding: 0 20px 80px;
        }
        .sk-card {
            background: linear-gradient(135deg, rgba(26,26,26,0.8) 0%, rgba(10,10,10,0.9) 100%);
            border: 1px solid rgba(37,99,235,0.15); border-radius: 4px;
            padding: 32px; margin-bottom: 32px; position: relative; overflow: hidden;
        }
        .sk-card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
            background: linear-gradient(90deg, var(--blue) 0%, var(--gold) 50%, var(--blue) 100%); opacity: 0.5;
        }
        .sk-features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 16px;
        }
        .sk-feature-box {
            background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.06);
            border-radius: 4px; padding: 24px 16px; text-align: center;
        }
        .sk-amenities {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
        }
        .sk-agent-card {
            background: linear-gradient(135deg, rgba(26,26,26,0.8) 0%, rgba(10,10,10,0.9) 100%);
            border: 1px solid rgba(37,99,235,0.15); border-radius: 4px;
            padding: 28px; margin-bottom: 24px; position: relative; overflow: hidden;
        }
        .sk-agent-card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
            background: linear-gradient(90deg, var(--gold) 0%, var(--blue) 100%); opacity: 0.5;
        }
        .sk-similar-section {
            padding: 60px 0 80px;
            border-top: 1px solid rgba(37,99,235,0.15);
        }
        .sk-similar-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 24px;
        }
        .sk-sim-card {
            background: linear-gradient(135deg, rgba(26,26,26,0.95) 0%, rgba(17,17,17,0.98) 100%);
            border: 1px solid rgba(255,255,255,0.08); border-radius: 4px; overflow: hidden;
        }
        @media (max-width: 1024px) {
            .sk-content-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .sk-gallery-grid { grid-template-columns: 1fr; height: 300px; }
            .sk-gallery-sidebar { display: none; }
            .sk-features-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<noscript><style>
    #sk-screen    { display: none !important; }
    #page-content { display: block !important; opacity: 1 !important; }
</style></noscript>

<!-- ════ SKELETON SCREEN ════ -->
<div id="sk-screen" role="presentation" aria-hidden="true">
    <!-- Breadcrumb skeleton -->
    <div class="sk-breadcrumb">
        <div class="container">
            <div style="display:flex;gap:8px;align-items:center;">
                <div class="sk-line sk-shimmer" style="width:50px;height:14px;"></div>
                <div class="sk-line sk-shimmer" style="width:8px;height:14px;"></div>
                <div class="sk-line sk-shimmer" style="width:80px;height:14px;"></div>
                <div class="sk-line sk-shimmer" style="width:8px;height:14px;"></div>
                <div class="sk-line sk-shimmer" style="width:130px;height:14px;"></div>
            </div>
        </div>
    </div>
    <!-- Property hero skeleton -->
    <div class="sk-hero">
        <div class="container">
            <div class="sk-gallery-grid">
                <div class="sk-shimmer" style="border-radius:0;"></div>
                <div class="sk-gallery-sidebar">
                    <div class="sk-shimmer" style="border-radius:0;"></div>
                    <div class="sk-shimmer" style="border-radius:0;"></div>
                </div>
            </div>
        </div>
    </div>
    <!-- Property header skeleton -->
    <div class="container">
        <div class="sk-prop-header">
            <div class="sk-line sk-shimmer" style="width:90px;height:26px;margin-bottom:16px;"></div>
            <div class="sk-line sk-shimmer" style="width:480px;max-width:90%;height:36px;margin-bottom:12px;"></div>
            <div class="sk-line sk-shimmer" style="width:280px;max-width:70%;height:16px;margin-bottom:20px;"></div>
            <div style="display:flex;align-items:center;gap:32px;">
                <div class="sk-line sk-shimmer" style="width:200px;height:32px;"></div>
                <div style="display:flex;gap:20px;">
                    <div class="sk-line sk-shimmer" style="width:80px;height:18px;"></div>
                    <div class="sk-line sk-shimmer" style="width:70px;height:18px;"></div>
                </div>
            </div>
        </div>
        <!-- Property content skeleton -->
        <div class="sk-content-grid">
            <!-- Main column -->
            <div>
                <!-- Features card -->
                <div class="sk-card">
                    <div class="sk-line sk-shimmer" style="width:180px;height:20px;margin-bottom:24px;"></div>
                    <div class="sk-features-grid">
                        <?php for ($f = 0; $f < 6; $f++): ?>
                        <div class="sk-feature-box">
                            <div class="sk-shimmer" style="width:48px;height:48px;border-radius:50%;margin:0 auto 12px;"></div>
                            <div class="sk-line sk-shimmer" style="width:60%;height:12px;margin:0 auto 8px;"></div>
                            <div class="sk-line sk-shimmer" style="width:45%;height:18px;margin:0 auto;"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>
                <!-- Description card -->
                <div class="sk-card">
                    <div class="sk-line sk-shimmer" style="width:200px;height:20px;margin-bottom:20px;"></div>
                    <div class="sk-line sk-shimmer" style="width:100%;height:14px;margin-bottom:8px;"></div>
                    <div class="sk-line sk-shimmer" style="width:98%;height:14px;margin-bottom:8px;"></div>
                    <div class="sk-line sk-shimmer" style="width:92%;height:14px;margin-bottom:8px;"></div>
                    <div class="sk-line sk-shimmer" style="width:85%;height:14px;margin-bottom:8px;"></div>
                    <div class="sk-line sk-shimmer" style="width:60%;height:14px;"></div>
                </div>
                <!-- Amenities card -->
                <div class="sk-card">
                    <div class="sk-line sk-shimmer" style="width:200px;height:20px;margin-bottom:20px;"></div>
                    <div class="sk-amenities">
                        <?php for ($am = 0; $am < 8; $am++): ?>
                        <div class="sk-shimmer" style="width:100%;height:38px;border-radius:4px;"></div>
                        <?php endfor; ?>
                    </div>
                </div>
                <!-- Property info card -->
                <div class="sk-card">
                    <div class="sk-line sk-shimmer" style="width:200px;height:20px;margin-bottom:20px;"></div>
                    <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:16px;">
                        <?php for ($pi = 0; $pi < 6; $pi++): ?>
                        <div>
                            <div class="sk-line sk-shimmer" style="width:90px;height:12px;margin-bottom:6px;"></div>
                            <div class="sk-line sk-shimmer" style="width:130px;height:16px;"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
            <!-- Sidebar -->
            <aside>
                <!-- Agent card skeleton -->
                <div class="sk-agent-card">
                    <div style="display:flex;gap:16px;align-items:center;margin-bottom:20px;">
                        <div class="sk-shimmer" style="width:72px;height:72px;border-radius:50%;flex-shrink:0;"></div>
                        <div style="flex:1;">
                            <div class="sk-line sk-shimmer" style="width:70%;height:18px;margin-bottom:8px;"></div>
                            <div class="sk-line sk-shimmer" style="width:55%;height:14px;"></div>
                        </div>
                    </div>
                    <div class="sk-shimmer" style="width:100%;height:48px;border-radius:2px;margin-bottom:16px;"></div>
                    <div class="sk-line sk-shimmer" style="width:140px;height:12px;margin-bottom:12px;"></div>
                    <?php for ($c = 0; $c < 2; $c++): ?>
                    <div style="display:flex;align-items:center;gap:12px;padding:12px;background:rgba(0,0,0,0.15);border-radius:6px;margin-bottom:10px;">
                        <div class="sk-shimmer" style="width:36px;height:36px;border-radius:6px;flex-shrink:0;"></div>
                        <div style="flex:1;">
                            <div class="sk-line sk-shimmer" style="width:40px;height:10px;margin-bottom:6px;"></div>
                            <div class="sk-line sk-shimmer" style="width:160px;max-width:90%;height:14px;"></div>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
                <!-- Tour card skeleton -->
                <div class="sk-card" style="margin-bottom:0;">
                    <div class="sk-line sk-shimmer" style="width:160px;height:18px;margin-bottom:12px;"></div>
                    <div class="sk-line sk-shimmer" style="width:100%;height:14px;margin-bottom:8px;"></div>
                    <div class="sk-line sk-shimmer" style="width:80%;height:14px;margin-bottom:20px;"></div>
                    <div class="sk-shimmer" style="width:100%;height:52px;border-radius:4px;"></div>
                </div>
            </aside>
        </div>
    </div>
    <!-- Similar properties skeleton -->
    <div class="sk-similar-section">
        <div class="container">
            <div style="text-align:center;margin-bottom:40px;">
                <div class="sk-line sk-shimmer" style="width:260px;height:28px;margin:0 auto 10px;"></div>
                <div class="sk-line sk-shimmer" style="width:360px;max-width:90%;height:16px;margin:0 auto;"></div>
            </div>
            <div class="sk-similar-grid">
                <?php for ($sp = 0; $sp < 4; $sp++): ?>
                <div class="sk-sim-card">
                    <div class="sk-shimmer" style="height:200px;width:100%;border-radius:0;"></div>
                    <div style="padding:20px;">
                        <div class="sk-line sk-shimmer" style="width:130px;height:20px;margin-bottom:10px;"></div>
                        <div class="sk-line sk-shimmer" style="width:90%;height:14px;margin-bottom:16px;"></div>
                        <div style="display:flex;gap:16px;padding-top:14px;border-top:1px solid rgba(255,255,255,0.06);">
                            <div class="sk-line sk-shimmer" style="width:60px;height:14px;"></div>
                            <div class="sk-line sk-shimmer" style="width:60px;height:14px;"></div>
                            <div class="sk-line sk-shimmer" style="width:70px;height:14px;"></div>
                        </div>
                    </div>
                </div>
                <?php endfor; ?>
            </div>
        </div>
    </div>
</div><!-- /#sk-screen -->

<div id="page-content">

<!-- Breadcrumb -->
<div class="breadcrumb-section">
    <div class="container">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                <li class="breadcrumb-item"><a href="search_results.php">Properties</a></li>
                <li class="breadcrumb-item active" aria-current="page">Property Details</li>
            </ol>
        </nav>
    </div>
</div>

<?php if ($error_message): ?>
    <div class="container" style="padding: 100px 0; text-align: center;">
        <div class="alert alert-danger" style="max-width: 600px; margin: 0 auto;">
            <i class="bi bi-exclamation-triangle-fill" style="font-size: 3rem; display: block; margin-bottom: 20px;"></i>
            <h3><?php echo htmlspecialchars($error_message); ?></h3>
            <a href="search_results.php" class="btn btn-primary mt-3">Browse Properties</a>
        </div>
    </div>
<?php else: ?>

<!-- Property Hero -->
<section class="property-hero">
    <div class="container">
        <!-- Image Gallery -->
        <div class="image-gallery-grid">
            <div class="gallery-main" onclick="openLightbox(0)">
                <img src="../<?php echo htmlspecialchars($property_images[0] ?? 'images/placeholder.jpg'); ?>" alt="Main property image" id="mainHeroImage">
                
                <!-- Floor Navigation Pills -->
                <div class="floor-pills">
                    <button class="floor-pill active" data-type="featured" onclick="event.stopPropagation(); switchHeroView('featured')">
                        <i class="bi bi-star-fill"></i>
                        Featured
                    </button>
                    <?php if (!empty($floor_images)): ?>
                        <?php foreach ($floor_images as $floor_num => $images): ?>
                            <button class="floor-pill" data-type="floor" data-floor="<?php echo $floor_num; ?>" onclick="event.stopPropagation(); switchHeroView('floor', <?php echo $floor_num; ?>)">
                                <i class="bi bi-building"></i>
                                Floor <?php echo $floor_num; ?>
                            </button>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="gallery-sidebar">
                <!-- Sidebar slot 1 (index 1) -->
                <div class="gallery-item" id="sidebarItem0" <?php if(count($property_images) >= 2): ?>onclick="openLightbox(1)"<?php else: ?>style="cursor:default;"<?php endif; ?>>
                    <?php if(count($property_images) >= 2): ?>
                        <img src="../<?php echo htmlspecialchars($property_images[1]); ?>" alt="Property image 2">
                    <?php else: ?>
                        <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:var(--black-lighter);">
                            <i class="bi bi-image" style="font-size:3rem;color:var(--gray-600);"></i>
                        </div>
                    <?php endif; ?>
                </div>
                <!-- Sidebar slot 2 (index 2) with +N more overlay -->
                <div class="gallery-item" id="sidebarItem1" <?php if(count($property_images) >= 3): ?>onclick="openLightbox(2)"<?php else: ?>style="cursor:default;"<?php endif; ?>>
                    <?php if(count($property_images) >= 3): ?>
                        <img src="../<?php echo htmlspecialchars($property_images[2]); ?>" alt="Property image 3">
                    <?php else: ?>
                        <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:var(--black-lighter);">
                            <i class="bi bi-image" style="font-size:3rem;color:var(--gray-600);"></i>
                        </div>
                    <?php endif; ?>
                    <div class="more-overlay" <?php echo count($property_images) > 3 ? '' : 'style="display:none;"'; ?>>
                        <?php if(count($property_images) > 3): ?>+<?php echo count($property_images) - 3; ?><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Property Content -->
<section class="container" style="padding: 40px 20px;">
    <div class="property-header">
        <span class="status-badge <?php echo $property_data['Status'] === 'For Rent' ? 'for-rent' : ''; ?>">
            <?php echo htmlspecialchars($property_data['Status']); ?>
        </span>
        <h1 class="property-title"><?php echo htmlspecialchars($property_data['StreetAddress']); ?></h1>
        <div class="property-address">
            <i class="bi bi-geo-alt-fill"></i>
            <?php echo htmlspecialchars($property_data['City'] . ', ' . $property_data['Province']); ?>
        </div>
        <div class="property-meta">
            <div class="property-price">₱<?php echo number_format($property_data['ListingPrice']); ?></div>
            <div class="property-stats">
                <div class="stat-item">
                    <i class="bi bi-eye-fill"></i>
                    <span class="stat-value"><?php echo number_format($property_data['ViewsCount']); ?></span>
                    <span style="color: var(--gray-400);">views</span>
                </div>
                <div class="stat-item likes">
                    <i class="bi bi-heart-fill"></i>
                    <span class="stat-value" id="statLikeCount"><?php echo number_format($property_data['Likes']); ?></span>
                    <span style="color: var(--gray-400);">likes</span>
                </div>
            </div>
        </div>
    </div>

    <div class="property-content">
        <!-- Main Content -->
        <div>
            <!-- Key Features -->
            <div class="content-card">
                <h2 class="section-title">
                    <i class="bi bi-house-door-fill"></i>
                    Property Features
                </h2>
                <div class="features-grid">
                    <div class="feature-box">
                        <div class="feature-icon">
                            <i class="bi bi-door-open-fill"></i>
                        </div>
                        <div class="feature-label">Bedrooms</div>
                        <div class="feature-value"><?php echo $property_data['Bedrooms']; ?></div>
                    </div>
                    <div class="feature-box">
                        <div class="feature-icon">
                            <i class="bi bi-droplet-fill"></i>
                        </div>
                        <div class="feature-label">Bathrooms</div>
                        <div class="feature-value"><?php echo $property_data['Bathrooms']; ?></div>
                    </div>
                    <div class="feature-box">
                        <div class="feature-icon">
                            <i class="bi bi-arrows-fullscreen"></i>
                        </div>
                        <div class="feature-label">Area</div>
                        <div class="feature-value"><?php echo number_format($property_data['SquareFootage']); ?> ft²</div>
                    </div>
                    <?php if($property_data['LotSize']): ?>
                    <div class="feature-box">
                        <div class="feature-icon">
                            <i class="bi bi-map"></i>
                        </div>
                        <div class="feature-label">Lot Size</div>
                        <div class="feature-value"><?php echo number_format($property_data['LotSize'], 2); ?> acres</div>
                    </div>
                    <?php endif; ?>
                    <div class="feature-box">
                        <div class="feature-icon">
                            <i class="bi bi-calendar-check"></i>
                        </div>
                        <div class="feature-label">Year Built</div>
                        <div class="feature-value"><?php echo $property_data['YearBuilt']; ?></div>
                    </div>
                    <div class="feature-box">
                        <div class="feature-icon">
                            <i class="bi bi-car-front-fill"></i>
                        </div>
                        <div class="feature-label">Parking</div>
                        <div class="feature-value" style="font-size: 0.875rem;"><?php echo htmlspecialchars($property_data['ParkingType']); ?></div>
                    </div>
                </div>
            </div>

            <!-- Description -->
            <div class="content-card">
                <h2 class="section-title">
                    <i class="bi bi-card-text"></i>
                    Property Description
                </h2>
                <p class="description-text"><?php echo nl2br(htmlspecialchars($property_data['ListingDescription'])); ?></p>
            </div>

            <!-- Amenities -->
            <?php if(!empty($property_amenities)): ?>
            <div class="content-card">
                <h2 class="section-title">
                    <i class="bi bi-stars"></i>
                    Amenities & Features
                </h2>
                <div class="amenities-list">
                    <?php foreach($property_amenities as $amenity): ?>
                        <div class="amenity-item">
                            <i class="bi bi-check-circle-fill"></i>
                            <span><?php echo htmlspecialchars($amenity); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Property Details -->
            <div class="content-card">
                <h2 class="section-title">
                    <i class="bi bi-info-circle-fill"></i>
                    Property Information
                </h2>
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px;">
                    <div>
                        <div style="color: var(--gray-400); font-size: 0.875rem; margin-bottom: 4px;">Property Type</div>
                        <div style="color: var(--white); font-weight: 600;"><?php echo htmlspecialchars($property_data['PropertyType']); ?></div>
                    </div>
                    <div>
                        <div style="color: var(--gray-400); font-size: 0.875rem; margin-bottom: 4px;">Barangay</div>
                        <div style="color: var(--white); font-weight: 600;"><?php echo htmlspecialchars($property_data['Barangay'] ?? 'N/A'); ?></div>
                    </div>
                    <div>
                        <div style="color: var(--gray-400); font-size: 0.875rem; margin-bottom: 4px;">Province</div>
                        <div style="color: var(--white); font-weight: 600;"><?php echo htmlspecialchars($property_data['Province']); ?></div>
                    </div>
                    <div>
                        <div style="color: var(--gray-400); font-size: 0.875rem; margin-bottom: 4px;">MLS Number</div>
                        <div style="color: var(--white); font-weight: 600;"><?php echo htmlspecialchars($property_data['MLSNumber']); ?></div>
                    </div>
                    <?php if (!empty($property_data['Source'])): ?>
                    <div>
                        <div style="color: var(--gray-400); font-size: 0.875rem; margin-bottom: 4px;">Source (MLS)</div>
                        <div style="color: var(--white); font-weight: 600;"><?php echo htmlspecialchars($property_data['Source']); ?></div>
                    </div>
                    <?php endif; ?>
                    <div>
                        <div style="color: var(--gray-400); font-size: 0.875rem; margin-bottom: 4px;">Listed Date</div>
                        <div style="color: var(--white); font-weight: 600;">
                            <?php 
                                $ld = $property_data['ListingDate'] ?? '';
                                if (!empty($ld) && $ld !== '0000-00-00') {
                                    $ts = strtotime($ld);
                                    if ($ts !== false) {
                                        echo date('M d, Y', $ts);
                                    } else {
                                        echo '<span style="color: var(--gray-500);">Not set</span>';
                                    }
                                } else {
                                    echo '<span style="color: var(--gray-500);">Not set</span>';
                                }
                            ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Price History -->
            <?php if (!empty($price_history)): ?>
            <div class="content-card">
                <h2 class="section-title">
                    <i class="bi bi-graph-up-arrow"></i>
                    Price History
                </h2>

                <!-- Summary Stats -->
                <div class="ph-stats-row" id="phStatsRow">
                    <!-- Filled by JS -->
                </div>

                <!-- Date Range Filter -->
                <div class="ph-filter-bar">
                    <div class="ph-filter-group">
                        <span class="ph-filter-label"><i class="bi bi-calendar3 me-1"></i>From</span>
                        <input type="date" class="ph-date-input" id="phDateFrom">
                    </div>
                    <span class="ph-filter-separator">—</span>
                    <div class="ph-filter-group">
                        <span class="ph-filter-label">To</span>
                        <input type="date" class="ph-date-input" id="phDateTo">
                    </div>
                    <button class="ph-filter-reset" id="phResetFilter" title="Reset date filter">
                        <i class="bi bi-arrow-counterclockwise"></i> Reset
                    </button>
                </div>

                <!-- Chart -->
                <div class="ph-chart-wrapper">
                    <canvas id="priceHistoryChart"></canvas>
                </div>
                <div class="ph-no-data" id="phNoData" style="display:none;">
                    <i class="bi bi-bar-chart-line"></i>
                    No price data found for the selected date range.
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <aside class="sticky-sidebar">
            <!-- Agent Card -->
            <?php if($agent_info): ?>
            <div class="agent-card">
                <img src="../<?php echo htmlspecialchars($agent_info['profile_picture_url'] ?? 'images/default-avatar.png'); ?>" 
                     alt="Agent photo" class="agent-avatar">
                <h3 class="agent-name"><?php echo htmlspecialchars($agent_info['first_name'] . ' ' . $agent_info['last_name']); ?></h3>
                <p class="agent-title"><?php echo htmlspecialchars($agent_info['specialization'] ?? 'Real Estate Professional'); ?></p>
                
                <?php if($agent_info['license_number']): ?>
                <div style="margin-bottom: 20px; padding: 10px; background: rgba(212, 175, 55, 0.1); border-radius: 2px;">
                    <div style="font-size: 0.75rem; color: var(--gray-400); margin-bottom: 4px;">License Number</div>
                    <div style="font-size: 0.875rem; color: var(--gold); font-weight: 600;"><?php echo htmlspecialchars($agent_info['license_number']); ?></div>
                </div>
                <?php endif; ?>

                <!-- Contact Information -->
                <div style="margin-bottom: 16px;">
                    <div style="font-size: 0.75rem; color: var(--gray-400); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; font-weight: 600;">Contact Information</div>

                    <a href="mailto:<?php echo htmlspecialchars($agent_info['email']); ?>" class="contact-card">
                        <div class="contact-icon"><i class="bi bi-envelope-fill"></i></div>
                        <div class="contact-details">
                            <div class="contact-label">Email</div>
                            <div class="contact-value"><?php echo htmlspecialchars($agent_info['email']); ?></div>
                        </div>
                    </a>

                    <a href="tel:<?php echo htmlspecialchars($agent_info['phone_number']); ?>" class="contact-card">
                        <div class="contact-icon"><i class="bi bi-telephone-fill"></i></div>
                        <div class="contact-details">
                            <div class="contact-label">Phone</div>
                            <div class="contact-value"><?php echo htmlspecialchars($agent_info['phone_number']); ?></div>
                        </div>
                    </a>

                    <style>
                    /* Contact cards: left-aligned, consistent spacing */
                    .contact-card {
                        display: flex;
                        align-items: center;
                        gap: 12px;
                        padding: 12px;
                        background: rgba(37, 99, 235, 0.06);
                        border: 1px solid rgba(37, 99, 235, 0.12);
                        border-radius: 6px;
                        text-decoration: none;
                        margin-bottom: 10px;
                        transition: background 0.18s ease, border-color 0.18s ease, transform 0.12s ease;
                    }
                    .contact-card:hover { background: rgba(37, 99, 235, 0.12); border-color: rgba(37, 99, 235, 0.25); transform: translateY(-1px); }
                    .contact-card .contact-icon { width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border-radius: 6px; background: rgba(37,99,235,0.04); }
                    .contact-card .contact-icon i { color: var(--blue-light); font-size: 1.125rem; }
                    .contact-card .contact-details { text-align: left; }
                    .contact-label { font-size: 0.75rem; color: var(--gray-400); margin-bottom: 2px; }
                    .contact-value { font-size: 0.95rem; color: var(--white); font-weight: 600; }
                    @media (max-width: 480px) {
                        .contact-card { gap: 10px; padding: 10px; }
                        .contact-card .contact-icon { width: 32px; height: 32px; }
                        .contact-value { font-size: 0.9rem; }
                    }
                    </style>
                </div>
            </div>
            <?php endif; ?>

            <!-- Request Tour Card -->
            <div class="content-card">
                <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--white); margin-bottom: 16px;">
                    <i class="bi bi-calendar-event" style="color: var(--gold);"></i> Schedule a Tour
                </h3>
                <p style="font-size: 0.875rem; color: var(--gray-400); margin-bottom: 20px;">
                    Interested in viewing this property? Request a tour and we'll contact you to confirm.
                </p>
                <button class="contact-btn" style="background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 100%);" data-bs-toggle="modal" data-bs-target="#requestTourModal">
                    <i class="bi bi-calendar-check"></i> Request Tour
                </button>
            </div>
        </aside>
    </div>
</section>

<!-- Similar Properties Section -->
<?php if (!empty($similar_properties) && count($similar_properties) > 0): ?>
<section class="similar-properties-section">
    <div class="container">
        <div class="similar-properties-header">
            <h2 class="similar-properties-title">You Might Also Like</h2>
            <p class="similar-properties-subtitle">Explore more properties similar to this one</p>
        </div>

        <div class="similar-properties-grid">
            <?php foreach ($similar_properties as $sim_prop): ?>
                <a href="property_details.php?id=<?php echo $sim_prop['property_ID']; ?>" class="similar-property-card">
                    <div class="similar-property-img">
                        <img src="../<?php echo htmlspecialchars($sim_prop['PhotoURL'] ?? 'images/placeholder.jpg'); ?>" 
                             alt="Property" loading="lazy">
                        <div class="similar-property-badge <?php echo $sim_prop['Status'] === 'For Rent' ? 'for-rent' : ''; ?>">
                            <?php echo htmlspecialchars($sim_prop['Status']); ?>
                        </div>
                        <div class="similar-property-stats">
                            <div class="similar-stat-badge views">
                                <i class="bi bi-eye-fill"></i>
                                <?php echo number_format($sim_prop['ViewsCount']); ?>
                            </div>
                            <div class="similar-stat-badge likes">
                                <i class="bi bi-heart-fill"></i>
                                <?php echo number_format($sim_prop['Likes']); ?>
                            </div>
                        </div>
                    </div>
                    <div class="similar-property-body">
                        <div class="similar-property-price">₱<?php echo number_format($sim_prop['ListingPrice']); ?></div>
                        <div class="similar-property-address">
                            <i class="bi bi-geo-alt-fill"></i>
                            <span><?php echo htmlspecialchars($sim_prop['StreetAddress']); ?>, <?php echo htmlspecialchars($sim_prop['City']); ?></span>
                        </div>
                        <div class="similar-property-features">
                            <div class="similar-feature">
                                <i class="bi bi-door-open-fill"></i>
                                <?php echo $sim_prop['Bedrooms']; ?> Beds
                            </div>
                            <div class="similar-feature">
                                <i class="bi bi-droplet-fill"></i>
                                <?php echo $sim_prop['Bathrooms']; ?> Baths
                            </div>
                            <div class="similar-feature">
                                <i class="bi bi-arrows-fullscreen"></i>
                                <?php echo number_format($sim_prop['SquareFootage']); ?> ft²
                            </div>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Floating Like Button -->
<div class="like-button-container">
    <div class="like-tooltip" id="likeTooltip">Click to like this property</div>
    <button class="like-button" id="likeButton" onclick="toggleLike()" aria-label="Like property">
        <i class="bi bi-heart-fill"></i>
        <span class="like-count" id="likeCount"><?php echo number_format($property_data['Likes']); ?></span>
    </button>
</div>

<!-- Image Lightbox -->
<div class="lightbox" id="lightbox" onclick="closeLightbox(event)">
    <button class="lightbox-close" onclick="closeLightbox(event)">
        <i class="bi bi-x"></i>
    </button>
    <button class="lightbox-prev" onclick="changeImage(-1, event)">
        <i class="bi bi-chevron-left"></i>
    </button>
    <div class="lightbox-content">
        <img src="" alt="Property image" class="lightbox-image" id="lightboxImage">
    </div>
    <button class="lightbox-next" onclick="changeImage(1, event)">
        <i class="bi bi-chevron-right"></i>
    </button>
    <div class="lightbox-counter" id="lightboxCounter"></div>
</div>

<?php endif; ?>

<!-- Request Tour Modal -->
<div class="modal fade" id="requestTourModal" tabindex="-1" aria-labelledby="requestTourModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 900px;">
        <div class="modal-content" style="background: linear-gradient(135deg, rgba(26, 26, 26, 0.98) 0%, rgba(10, 10, 10, 0.98) 100%); border: 1px solid rgba(37, 99, 235, 0.2); border-radius: 8px; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);">
            <div class="modal-header" style="border-bottom: 1px solid rgba(37, 99, 235, 0.15); padding: 28px 40px; background: linear-gradient(135deg, rgba(37, 99, 235, 0.08) 0%, rgba(212, 175, 55, 0.05) 100%);">
                <div>
                    <h5 class="modal-title" id="requestTourModalLabel" style="color: var(--white); font-weight: 700; font-size: 1.5rem; display: flex; align-items: center; gap: 12px; margin-bottom: 6px;">
                        <i class="bi bi-calendar-check" style="color: var(--gold); font-size: 1.75rem;"></i> Request a Tour
                    </h5>
                    <p style="color: var(--gray-400); margin: 0; font-size: 0.9rem;">Select your preferred date and time - we'll confirm shortly</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="filter: invert(1) brightness(2); opacity: 0.7; transition: opacity 0.2s;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.7'"></button>
            </div>
            <div class="modal-body" style="padding: 32px 40px;">
                <!-- Alert container -->
                <div id="tourRequestAlert" class="alert d-none" role="alert" style="border-radius: 6px; margin-bottom: 24px;"></div>

                <form id="tourRequestForm">
                    <input type="hidden" name="property_id" value="<?php echo $property_data['property_ID']; ?>">
                    
                    <!-- Tour Type Section -->
                    <div style="background: rgba(37, 99, 235, 0.06); border: 1px solid rgba(37, 99, 235, 0.15); border-radius: 6px; padding: 20px; margin-bottom: 28px;">
                        <label class="form-label" style="color: var(--white); font-weight: 700; font-size: 0.95rem; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                            <i class="bi bi-people" style="color: var(--gold);"></i> Tour Type
                        </label>
                        <div class="d-flex gap-4 align-items-start">
                            <label class="tour-type-option" style="flex: 1; cursor: pointer; position: relative;">
                                <input class="form-check-input" type="radio" name="tour_type" id="tourTypePrivate" value="private" checked style="display: none;">
                                <div class="tour-type-card" data-for="tourTypePrivate" style="background: rgba(255,255,255,0.05); border: 2px solid var(--gold); border-radius: 6px; padding: 16px; transition: all 0.2s; position: relative;">
                                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                                        <i class="bi bi-person-check" style="color: var(--gold); font-size: 1.5rem;"></i>
                                        <span style="color: var(--white); font-weight: 600; font-size: 1rem;">Private (1-on-1)</span>
                                        <i class="bi bi-check-circle-fill" style="color: var(--gold); font-size: 1.2rem; margin-left: auto;"></i>
                                    </div>
                                    <p style="color: var(--gray-400); font-size: 0.85rem; margin: 0; line-height: 1.4;">Personal tour with dedicated attention</p>
                                </div>
                            </label>
                            <label class="tour-type-option" style="flex: 1; cursor: pointer; position: relative;">
                                <input class="form-check-input" type="radio" name="tour_type" id="tourTypePublic" value="public" style="display: none;">
                                <div class="tour-type-card" data-for="tourTypePublic" style="background: rgba(255,255,255,0.05); border: 2px solid rgba(255,255,255,0.1); border-radius: 6px; padding: 16px; transition: all 0.2s; position: relative;">
                                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                                        <i class="bi bi-people" style="color: var(--blue-light); font-size: 1.5rem;"></i>
                                        <span style="color: var(--white); font-weight: 600; font-size: 1rem;">Public (Group)</span>
                                        <i class="bi bi-check-circle-fill" style="color: var(--gold); font-size: 1.2rem; margin-left: auto; display: none;"></i>
                                    </div>
                                    <p style="color: var(--gray-400); font-size: 0.85rem; margin: 0; line-height: 1.4;">Join other visitors at the same time</p>
                                </div>
                            </label>
                        </div>
                    </div>
                    
                    <!-- Date & Time Section -->
                    <div style="background: rgba(212, 175, 55, 0.06); border: 1px solid rgba(212, 175, 55, 0.15); border-radius: 6px; padding: 24px; margin-bottom: 28px;">
                        <label class="form-label" style="color: var(--white); font-weight: 700; font-size: 0.95rem; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
                            <i class="bi bi-calendar-event" style="color: var(--gold);"></i> Schedule
                        </label>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="tourDate" class="form-label" style="color: var(--gray-300); font-weight: 600; font-size: 0.875rem; margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
                                    <i class="bi bi-calendar3" style="color: var(--gold);"></i> Date <span style="color: #ff4444;">*</span>
                                </label>
                                <div style="position: relative;">
                                    <i class="bi bi-calendar-event" style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: white; font-size: 1.1rem; pointer-events: none; z-index: 10;"></i>
                                    <input type="date" class="form-control" id="tourDate" name="tour_date" required style="background: rgba(0,0,0,0.3); border: 1px solid rgba(255, 255, 255, 0.15); color: var(--white); border-radius: 6px; padding: 13px 16px 13px 45px; width: 100%; font-size: 0.95rem; transition: all 0.2s; color-scheme: dark;">
                                </div>
                                <div class="invalid-feedback" id="tourDateFeedback" style="font-size: 0.875rem; margin-top: 6px;"></div>
                            </div>
                            <div class="col-md-6">
                                <label for="tourTime" class="form-label" style="color: var(--gray-300); font-weight: 600; font-size: 0.875rem; margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
                                    <i class="bi bi-clock" style="color: var(--gold);"></i> Time <span style="color: #ff4444;">*</span>
                                </label>
                                <div style="position: relative;">
                                    <i class="bi bi-clock-fill" style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: white; font-size: 1.1rem; pointer-events: none; z-index: 10;"></i>
                                    <select class="form-select" id="tourTime" name="time" required style="background: rgba(0,0,0,0.3); border: 1px solid rgba(255, 255, 255, 0.15); color: var(--white); border-radius: 6px; padding: 13px 16px 13px 45px; width: 100%; font-size: 0.95rem; transition: all 0.2s; appearance: none; -webkit-appearance: none; -moz-appearance: none; background-image: url('data:image/svg+xml;charset=UTF-8,%3csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2712%27 height=%2712%27 viewBox=%270 0 12 12%27%3e%3cpath fill=%27white%27 d=%27M6 9L1 4h10z%27/%3e%3c/svg%3e'); background-repeat: no-repeat; background-position: right 16px center;">
                                        <option value="" style="background: var(--black);">Select a time</option>
                                        <option value="09:00:00" style="background: var(--black);">9:00 AM</option>
                                        <option value="10:00:00" style="background: var(--black);">10:00 AM</option>
                                        <option value="11:00:00" style="background: var(--black);">11:00 AM</option>
                                        <option value="13:00:00" style="background: var(--black);">1:00 PM</option>
                                        <option value="14:00:00" style="background: var(--black);">2:00 PM</option>
                                        <option value="15:00:00" style="background: var(--black);">3:00 PM</option>
                                        <option value="16:00:00" style="background: var(--black);">4:00 PM</option>
                                    </select>
                                </div>
                                <div class="invalid-feedback" id="tourTimeFeedback" style="font-size: 0.875rem; margin-top: 6px;"></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Contact Information Section -->
                    <div style="background: rgba(37, 99, 235, 0.06); border: 1px solid rgba(37, 99, 235, 0.15); border-radius: 6px; padding: 24px; margin-bottom: 24px;">
                        <label class="form-label" style="color: var(--white); font-weight: 700; font-size: 0.95rem; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
                            <i class="bi bi-person-vcard" style="color: var(--gold);"></i> Your Information
                        </label>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="tourName" class="form-label" style="color: var(--gray-300); font-weight: 600; font-size: 0.875rem; margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
                                    <i class="bi bi-person" style="color: var(--blue-light);"></i> Full Name <span style="color: #ff4444;">*</span>
                                </label>
                                <div style="position: relative;">
                                    <i class="bi bi-person-fill" style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: white; font-size: 1.1rem; pointer-events: none; z-index: 10;"></i>
                                    <input type="text" class="form-control" id="tourName" name="name" required placeholder="Enter your full name" style="background: rgba(0,0,0,0.3); border: 1px solid rgba(255, 255, 255, 0.15); color: var(--white); border-radius: 6px; padding: 13px 16px 13px 45px; width: 100%; font-size: 0.95rem; transition: all 0.2s;">
                                </div>
                                <div class="invalid-feedback" id="tourNameFeedback" style="font-size: 0.875rem; margin-top: 6px;"></div>
                            </div>
                            <div class="col-md-6">
                                <label for="tourPhone" class="form-label" style="color: var(--gray-300); font-weight: 600; font-size: 0.875rem; margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
                                    <i class="bi bi-telephone" style="color: var(--blue-light);"></i> Phone <span style="color: #ff4444;">*</span>
                                </label>
                                <div style="position: relative;">
                                    <i class="bi bi-telephone-fill" style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: white; font-size: 1.1rem; pointer-events: none; z-index: 10;"></i>
                                    <input type="tel" class="form-control" id="tourPhone" name="phone" required placeholder="Enter your phone number" style="background: rgba(0,0,0,0.3); border: 1px solid rgba(255, 255, 255, 0.15); color: var(--white); border-radius: 6px; padding: 13px 16px 13px 45px; width: 100%; font-size: 0.95rem; transition: all 0.2s;">
                                </div>
                                <div class="invalid-feedback" id="tourPhoneFeedback" style="font-size: 0.875rem; margin-top: 6px;"></div>
                            </div>
                            <div class="col-12">
                                <label for="tourEmail" class="form-label" style="color: var(--gray-300); font-weight: 600; font-size: 0.875rem; margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
                                    <i class="bi bi-envelope" style="color: var(--blue-light);"></i> Email <span style="color: #ff4444;">*</span>
                                </label>
                                <div style="position: relative;">
                                    <i class="bi bi-envelope-fill" style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: white; font-size: 1.1rem; pointer-events: none; z-index: 10;"></i>
                                    <input type="email" class="form-control" id="tourEmail" name="email" required placeholder="Enter your email address" style="background: rgba(0,0,0,0.3); border: 1px solid rgba(255, 255, 255, 0.15); color: var(--white); border-radius: 6px; padding: 13px 16px 13px 45px; width: 100%; font-size: 0.95rem; transition: all 0.2s;">
                                </div>
                                <div class="invalid-feedback" id="tourEmailFeedback" style="font-size: 0.875rem; margin-top: 6px;"></div>
                            </div>
                            <div class="col-12">
                                <label for="tourMessage" class="form-label" style="color: var(--gray-300); font-weight: 600; font-size: 0.875rem; margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
                                    <i class="bi bi-chat-left-text" style="color: var(--blue-light);"></i> Message <span style="color: var(--gray-500); font-weight: 400; font-size: 0.8rem;">(Optional)</span>
                                </label>
                                <div style="position: relative;">
                                    <i class="bi bi-chat-left-text-fill" style="position: absolute; left: 16px; top: 20px; color: white; font-size: 1.1rem; pointer-events: none; z-index: 10;"></i>
                                    <textarea class="form-control" id="tourMessage" name="message" rows="2" placeholder="Any specific requests or questions?" style="background: rgba(0,0,0,0.3); border: 1px solid rgba(255, 255, 255, 0.15); color: var(--white); border-radius: 6px; padding: 13px 16px 13px 45px; width: 100%; font-size: 0.95rem; resize: vertical; transition: all 0.2s;"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="w-100" style="background: linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 100%); color: var(--black); border: none; padding: 16px; font-weight: 700; border-radius: 6px; cursor: pointer; transition: all 0.3s ease; font-size: 1.05rem; display: flex; align-items: center; justify-content: center; gap: 10px; box-shadow: 0 4px 15px rgba(212, 175, 55, 0.3);" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 25px rgba(212, 175, 55, 0.4)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(212, 175, 55, 0.3)'">
                        <i class="bi bi-send-fill" style="font-size: 1.1rem;"></i>
                        Submit Tour Request
                    </button>
                    <p style="text-align: center; color: var(--gray-500); font-size: 0.8rem; margin: 12px 0 0 0;">
                        <i class="bi bi-shield-check" style="color: var(--blue-light);"></i> Your information is secure and will only be used to coordinate your tour
                    </p>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
/* Tour Modal Enhancements */
#requestTourModal .modal-content {
    animation: modalSlideIn 0.3s ease-out;
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(-30px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

/* Tour Type Card Styling */
.tour-type-card {
    cursor: pointer;
    position: relative;
    overflow: hidden;
}

.tour-type-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, rgba(212, 175, 55, 0.1), transparent);
    opacity: 0;
    transition: opacity 0.3s;
}

.tour-type-card:hover::before {
    opacity: 1;
}

.tour-type-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
}

#tourTypePrivate:checked ~ .tour-type-card {
    border-color: var(--gold) !important;
    background: rgba(212, 175, 55, 0.1) !important;
}

#tourTypePublic:checked ~ .tour-type-card {
    border-color: var(--gold) !important;
    background: rgba(212, 175, 55, 0.1) !important;
}

/* Show/hide check icons */
#tourTypePrivate:checked ~ .tour-type-card .bi-check-circle-fill {
    display: inline-block !important;
}

#tourTypePrivate:not(:checked) ~ .tour-type-card .bi-check-circle-fill {
    display: none !important;
}

#tourTypePublic:checked ~ .tour-type-card .bi-check-circle-fill {
    display: inline-block !important;
}

#tourTypePublic:not(:checked) ~ .tour-type-card .bi-check-circle-fill {
    display: none !important;
}

/* Input Placeholder Styling */
#requestTourModal input::placeholder,
#requestTourModal textarea::placeholder {
    color: rgba(255, 255, 255, 0.4) !important;
    opacity: 1 !important;
}

#requestTourModal input::-webkit-input-placeholder,
#requestTourModal textarea::-webkit-input-placeholder {
    color: rgba(255, 255, 255, 0.4) !important;
}

#requestTourModal input::-moz-placeholder,
#requestTourModal textarea::-moz-placeholder {
    color: rgba(255, 255, 255, 0.4) !important;
    opacity: 1 !important;
}

#requestTourModal input:-ms-input-placeholder,
#requestTourModal textarea:-ms-input-placeholder {
    color: rgba(255, 255, 255, 0.4) !important;
}

/* Enhanced Focus states */
#requestTourModal input:focus,
#requestTourModal select:focus,
#requestTourModal textarea:focus {
    outline: none !important;
    border-color: var(--gold) !important;
    box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.15) !important;
    background: rgba(0,0,0,0.4) !important;
    transform: translateY(-1px);
}

#requestTourModal input:hover,
#requestTourModal select:hover,
#requestTourModal textarea:hover {
    border-color: rgba(255, 255, 255, 0.25);
}

/* Form Labels */
#requestTourModal .form-label {
    font-weight: 600 !important;
    transition: color 0.2s;
}

/* Invalid feedback styling */
#requestTourModal .invalid-feedback {
    display: block;
    color: #ff4444;
    font-weight: 500;
    background: rgba(255, 68, 68, 0.1);
    padding: 6px 10px;
    border-radius: 4px;
    margin-top: 8px;
}

#requestTourModal .form-control.is-invalid,
#requestTourModal .form-select.is-invalid {
    border-color: #ff4444 !important;
    box-shadow: 0 0 0 3px rgba(255, 68, 68, 0.15) !important;
}

/* Alert Styling */
#requestTourModal .alert {
    border-radius: 6px;
    border: none;
    font-weight: 500;
    padding: 14px 18px;
    display: flex;
    align-items: center;
    gap: 10px;
}

#requestTourModal .alert-success {
    background: linear-gradient(135deg, rgba(34, 197, 94, 0.15), rgba(34, 197, 94, 0.08));
    color: #4ade80;
    border-left: 3px solid #22c55e;
}

#requestTourModal .alert-danger {
    background: linear-gradient(135deg, rgba(239, 68, 68, 0.15), rgba(239, 68, 68, 0.08));
    color: #ff6b6b;
    border-left: 3px solid #ef4444;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    #requestTourModal .modal-dialog {
        max-width: 95% !important;
        margin: 10px auto;
    }
    
    #requestTourModal .modal-body {
        padding: 24px !important;
    }
    
    #requestTourModal .modal-header {
        padding: 20px 24px !important;
    }
    
    .tour-type-option {
        flex-direction: column !important;
    }
}
</style>

</div><!-- /#page-content -->

<script src="<?= ASSETS_JS ?>bootstrap.bundle.min.js"></script>
<script src="<?= ASSETS_JS ?>chart.umd.min.js"></script>
<script src="<?= ASSETS_JS ?>chartjs-adapter-date-fns.bundle.min.js"></script>

<!-- SKELETON HYDRATION — Progressive Content Reveal (User Portal) -->
<script>
(function () {
    'use strict';
    var MIN_SKELETON_MS = 400;
    var skeletonStart = Date.now();
    function hydrate() {
        var sk = document.getElementById('sk-screen');
        var pc = document.getElementById('page-content');
        if (!sk || !pc) return;
        var elapsed = Date.now() - skeletonStart;
        var remaining = Math.max(0, MIN_SKELETON_MS - elapsed);
        setTimeout(function () {
            sk.style.transition = 'opacity 0.35s ease';
            sk.style.opacity = '0';
            setTimeout(function () {
                sk.style.display = 'none';
                pc.style.display = 'block';
                pc.style.opacity = '0';
                pc.style.transition = 'opacity 0.4s ease';
                requestAnimationFrame(function () {
                    requestAnimationFrame(function () {
                        pc.style.opacity = '1';
                        document.dispatchEvent(new Event('skeleton:hydrated'));
                    });
                });
            }, 360);
        }, remaining);
    }
    if (document.readyState === 'complete') { hydrate(); }
    else { window.addEventListener('load', hydrate); }
}());
</script>

<script>
// Property image data from PHP
const featuredImages = <?php echo json_encode($property_images); ?>;
const floorImages = <?php echo json_encode($floor_images); ?>;

// Gallery state
let currentImageIndex = 0;
let currentImages = featuredImages;
let currentHeroView = 'featured';
let currentHeroFloor = null;

// Update sidebar images and +N overlay
function updateSidebar(images) {
    const total = images.length;

    // --- Slot 0: images[1] ---
    const item0 = document.getElementById('sidebarItem0');
    if (item0) {
        if (total >= 2) {
            let img = item0.querySelector('img');
            if (img) {
                img.src = '../' + images[1];
            } else {
                item0.innerHTML = '<img src="../' + images[1] + '" alt="Property image">';
            }
            item0.style.cursor = 'pointer';
            item0.onclick = function() { openLightbox(1); };
        } else {
            item0.innerHTML = '<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:var(--black-lighter);"><i class="bi bi-image" style="font-size:3rem;color:var(--gray-600);"></i></div>';
            item0.style.cursor = 'default';
            item0.onclick = null;
        }
    }

    // --- Slot 1: images[2] + more-overlay ---
    const item1 = document.getElementById('sidebarItem1');
    if (item1) {
        if (total >= 3) {
            let img = item1.querySelector('img');
            if (img) {
                img.src = '../' + images[2];
            } else {
                item1.innerHTML = '<img src="../' + images[2] + '" alt="Property image"><div class="more-overlay" style="display:none;"></div>';
            }
            item1.style.cursor = 'pointer';
            item1.onclick = function() { openLightbox(2); };
        } else {
            item1.innerHTML = '<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:var(--black-lighter);"><i class="bi bi-image" style="font-size:3rem;color:var(--gray-600);"></i></div><div class="more-overlay" style="display:none;"></div>';
            item1.style.cursor = 'default';
            item1.onclick = null;
        }
        // Update +N overlay
        const overlay = item1.querySelector('.more-overlay');
        if (overlay) {
            if (total > 3) {
                overlay.textContent = '+' + (total - 3);
                overlay.style.display = 'flex';
            } else {
                overlay.style.display = 'none';
            }
        }
    }
}

// Switch hero view between featured and floor images
function switchHeroView(viewType, floorNum = null) {
    const mainImage = document.getElementById('mainHeroImage');
    const pills = document.querySelectorAll('.floor-pill');
    
    // Update active pill
    pills.forEach(pill => {
        if (viewType === 'featured' && pill.dataset.type === 'featured') {
            pill.classList.add('active');
        } else if (viewType === 'floor' && pill.dataset.type === 'floor' && parseInt(pill.dataset.floor) === floorNum) {
            pill.classList.add('active');
        } else {
            pill.classList.remove('active');
        }
    });
    
    currentHeroView = viewType;
    currentHeroFloor = floorNum;
    
    let imagesToShow = [];
    if (viewType === 'featured') {
        imagesToShow = featuredImages;
        currentImages = featuredImages;
    } else if (viewType === 'floor' && floorImages[floorNum]) {
        imagesToShow = floorImages[floorNum];
        currentImages = floorImages[floorNum];
    }
    
    if (imagesToShow.length > 0) {
        mainImage.src = '../' + imagesToShow[0];
    }

    // Sync sidebar and view-all button
    updateSidebar(imagesToShow);
}

// Lightbox functionality
function openLightbox(index) {
    currentImageIndex = index;
    updateLightboxImage();
    document.getElementById('lightbox').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeLightbox(event) {
    if (event.target.id === 'lightbox' || event.target.closest('.lightbox-close')) {
        event.stopPropagation();
        document.getElementById('lightbox').classList.remove('active');
        document.body.style.overflow = 'auto';
    }
}

function changeImage(direction, event) {
    event.stopPropagation();
    currentImageIndex += direction;
    if (currentImageIndex < 0) currentImageIndex = currentImages.length - 1;
    if (currentImageIndex >= currentImages.length) currentImageIndex = 0;
    updateLightboxImage();
}

function updateLightboxImage() {
    const img = document.getElementById('lightboxImage');
    const counter = document.getElementById('lightboxCounter');
    img.src = '../' + currentImages[currentImageIndex];
    counter.textContent = `${currentImageIndex + 1} / ${currentImages.length}`;
}

// Keyboard navigation
document.addEventListener('keydown', function(e) {
    const lightbox = document.getElementById('lightbox');
    if (lightbox.classList.contains('active')) {
        if (e.key === 'Escape') closeLightbox({ target: lightbox });
        if (e.key === 'ArrowLeft') changeImage(-1, e);
        if (e.key === 'ArrowRight') changeImage(1, e);
    }
});

// Like functionality
const propertyId = <?php echo $property_id; ?>;
const likeButton = document.getElementById('likeButton');
const likeCount = document.getElementById('likeCount');
const likeTooltip = document.getElementById('likeTooltip');
let isLiked = false;
let isProcessing = false;

// Check if user has already liked this property (using localStorage)
function checkLikeStatus() {
    const likedProperties = JSON.parse(localStorage.getItem('likedProperties') || '[]');
    isLiked = likedProperties.includes(propertyId);
    
    if (isLiked) {
        likeButton.classList.add('liked');
        likeTooltip.textContent = 'You liked this property';
    } else {
        likeButton.classList.remove('liked');
        likeTooltip.textContent = 'Click to like this property';
    }
}

// Toggle like status
function toggleLike() {
    if (isProcessing) return;
    
    const wasLiked = isLiked;
    isLiked = !isLiked;
    
    // Optimistic UI update
    const currentCount = parseInt(likeCount.textContent.replace(/,/g, ''));
    const statLike = document.getElementById('statLikeCount');
    if (isLiked) {
        likeButton.classList.add('liked');
        likeTooltip.textContent = 'You liked this property';
        const newVal = (currentCount + 1).toLocaleString();
        likeCount.textContent = newVal;
        if (statLike) statLike.textContent = newVal;
    } else {
        likeButton.classList.remove('liked');
        likeTooltip.textContent = 'Click to like this property';
        const newVal = Math.max(currentCount - 1, 0).toLocaleString();
        likeCount.textContent = newVal;
        if (statLike) statLike.textContent = newVal;
    }
    
    // Send action to server
    const action = isLiked ? 'like' : 'unlike';
    saveLikeToDatabase(action, wasLiked, currentCount);
}

// Save like/unlike to database via AJAX
function saveLikeToDatabase(action, previousLikedState, previousCount) {
    isProcessing = true;
    
    fetch('like_property.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'property_id=' + propertyId + '&action=' + action
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Sync both like count displays with the actual database value
            if (window._syncLikeCounts) {
                window._syncLikeCounts(data.likes);
            } else {
                likeCount.textContent = data.likes.toLocaleString();
            }
            
            // Update localStorage
            let likedProperties = JSON.parse(localStorage.getItem('likedProperties') || '[]');
            if (action === 'like') {
                if (!likedProperties.includes(propertyId)) {
                    likedProperties.push(propertyId);
                }
            } else {
                likedProperties = likedProperties.filter(id => id !== propertyId);
            }
            localStorage.setItem('likedProperties', JSON.stringify(likedProperties));
        } else {
            console.error('Failed to ' + action + ' property:', data.message);
            // Revert UI on error
            isLiked = previousLikedState;
            if (window._syncLikeCounts) {
                window._syncLikeCounts(previousCount);
            } else {
                likeCount.textContent = previousCount.toLocaleString();
            }
            if (previousLikedState) {
                likeButton.classList.add('liked');
                likeTooltip.textContent = 'You liked this property';
            } else {
                likeButton.classList.remove('liked');
                likeTooltip.textContent = 'Click to like this property';
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
        // Revert UI on error
        isLiked = previousLikedState;
        if (window._syncLikeCounts) {
            window._syncLikeCounts(previousCount);
        } else {
            likeCount.textContent = previousCount.toLocaleString();
        }
        if (previousLikedState) {
            likeButton.classList.add('liked');
            likeTooltip.textContent = 'You liked this property';
        } else {
            likeButton.classList.remove('liked');
            likeTooltip.textContent = 'Click to like this property';
        }
    })
    .finally(() => {
        isProcessing = false;
    });
}

// Initialize like status on page load
checkLikeStatus();

// ============================
// Real-time like count polling
// ============================
(function() {
    const POLL_INTERVAL = 5000; // 5 seconds
    const statLikeCount = document.getElementById('statLikeCount');
    let lastKnownLikes = parseInt((likeCount ? likeCount.textContent : '0').replace(/,/g, ''));

    function applyPulse(el) {
        if (!el) return;
        el.classList.remove('like-pulse');
        // Force reflow to restart animation
        void el.offsetWidth;
        el.classList.add('like-pulse');
    }

    function updateDisplayedLikes(newCount) {
        const formatted = newCount.toLocaleString();
        const changed = newCount !== lastKnownLikes;

        if (likeCount) likeCount.textContent = formatted;
        if (statLikeCount) statLikeCount.textContent = formatted;

        if (changed) {
            applyPulse(likeCount);
            applyPulse(statLikeCount);
        }
        lastKnownLikes = newCount;
    }

    function pollLikes() {
        // Skip polling while a like/unlike request is in flight
        if (isProcessing) return;

        fetch('get_likes.php?property_id=' + propertyId)
            .then(r => r.json())
            .then(data => {
                if (data.success && !isProcessing) {
                    updateDisplayedLikes(data.likes);
                }
            })
            .catch(() => { /* silent */ });
    }

    setInterval(pollLikes, POLL_INTERVAL);

    // Also expose helper so saveLikeToDatabase can sync both counts
    window._syncLikeCounts = updateDisplayedLikes;
})();

// Tour Type Card Selection Handler
document.addEventListener('DOMContentLoaded', function() {
    // Handle tour type card clicks
    const tourTypeCards = document.querySelectorAll('.tour-type-card');
    tourTypeCards.forEach(card => {
        card.addEventListener('click', function() {
            const radioId = this.getAttribute('data-for');
            const radio = document.getElementById(radioId);
            if (radio) {
                radio.checked = true;
                
                // Update visual state for all cards
                updateTourTypeCards();
            }
        });
    });
    
    // Also handle radio button changes directly
    const tourTypeRadios = document.querySelectorAll('input[name="tour_type"]');
    tourTypeRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            updateTourTypeCards();
        });
    });
    
    // Function to update tour type card appearances
    function updateTourTypeCards() {
        const privateRadio = document.getElementById('tourTypePrivate');
        const publicRadio = document.getElementById('tourTypePublic');
        const privateCard = document.querySelector('[data-for="tourTypePrivate"]');
        const publicCard = document.querySelector('[data-for="tourTypePublic"]');
        
        // Reset all cards
        [privateCard, publicCard].forEach(card => {
            if (card) {
                card.style.border = '2px solid rgba(255,255,255,0.1)';
                card.style.background = 'rgba(255,255,255,0.05)';
                const checkIcon = card.querySelector('.bi-check-circle-fill');
                if (checkIcon) checkIcon.style.display = 'none';
            }
        });
        
        // Highlight selected card
        if (privateRadio && privateRadio.checked && privateCard) {
            privateCard.style.border = '2px solid var(--gold)';
            privateCard.style.background = 'rgba(212, 175, 55, 0.1)';
            const checkIcon = privateCard.querySelector('.bi-check-circle-fill');
            if (checkIcon) checkIcon.style.display = 'inline-block';
        }
        
        if (publicRadio && publicRadio.checked && publicCard) {
            publicCard.style.border = '2px solid var(--gold)';
            publicCard.style.background = 'rgba(212, 175, 55, 0.1)';
            const checkIcon = publicCard.querySelector('.bi-check-circle-fill');
            if (checkIcon) checkIcon.style.display = 'inline-block';
        }
    }
    
    // Initialize on page load
    updateTourTypeCards();
});

// Tour Request Form Handler
document.getElementById('tourRequestForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    const alertBox = document.getElementById('tourRequestAlert');
    const fieldMap = {
        tour_date: { input: '#tourDate', feedback: '#tourDateFeedback' },
        time: { input: '#tourTime', feedback: '#tourTimeFeedback' },
        name: { input: '#tourName', feedback: '#tourNameFeedback' },
        email: { input: '#tourEmail', feedback: '#tourEmailFeedback' },
        phone: { input: '#tourPhone', feedback: '#tourPhoneFeedback' }
    };

    // Clear previous validation states
    Object.values(fieldMap).forEach(({ input, feedback }) => {
        const el = document.querySelector(input);
        const fb = document.querySelector(feedback);
        if (el) el.classList.remove('is-invalid');
        if (fb) fb.textContent = '';
    });
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class=\"spinner-border spinner-border-sm me-2\"></span>Sending...';
    
    fetch('request_tour_process.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        // Ensure alert box is visible with proper styling
        if (alertBox) {
            alertBox.classList.remove('d-none', 'alert-success', 'alert-danger');
            alertBox.classList.add(data.success ? 'alert-success' : 'alert-danger');
            if (data.html) {
                alertBox.innerHTML = data.html;
            } else {
                alertBox.textContent = data.message || (data.success ? 'Request completed.' : 'There was a problem with your request.');
            }
        }

        // Apply per-field error messages if provided
        if (!data.success && data.errors) {
            Object.entries(data.errors).forEach(([key, msg]) => {
                const mapping = fieldMap[key];
                if (!mapping) return;
                const el = document.querySelector(mapping.input);
                const fb = document.querySelector(mapping.feedback);
                if (el) el.classList.add('is-invalid');
                if (fb) fb.textContent = msg;
            });
        }

        if (data.success) {
            submitBtn.innerHTML = '<i class=\"bi bi-check-circle-fill me-2\"></i>Request Sent!';
            submitBtn.style.background = 'linear-gradient(135deg, #28a745 0%, #20c997 100%)';

            // Reset form after brief confirmation
            setTimeout(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
                submitBtn.style.background = 'linear-gradient(135deg, var(--gold-dark) 0%, var(--gold) 100%)';
                // Keep success alert visible; clear form fields
                this.reset();
                // Clear validation states after reset
                Object.values(fieldMap).forEach(({ input, feedback }) => {
                    const el = document.querySelector(input);
                    const fb = document.querySelector(feedback);
                    if (el) el.classList.remove('is-invalid');
                    if (fb) fb.textContent = '';
                });
                
                // Close modal after 2 seconds
                setTimeout(() => {
                    const modal = bootstrap.Modal.getInstance(document.getElementById('requestTourModal'));
                    if (modal) modal.hide();
                    if (alertBox) alertBox.classList.add('d-none');
                }, 2000);
            }, 1500);
        } else {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        if (alertBox) {
            alertBox.classList.remove('d-none', 'alert-success');
            alertBox.classList.add('alert-danger');
            alertBox.textContent = 'An unexpected error occurred. Please try again.';
        }
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
});

// Set minimum date for tour date picker to today
const tourDateInput = document.getElementById('tourDate');
if (tourDateInput) {
    const today = new Date().toISOString().split('T')[0];
    tourDateInput.setAttribute('min', today);
}

// One-time view count increment per user per property
(function() {
    const viewedProperties = JSON.parse(localStorage.getItem('viewedProperties') || '[]');
    const viewsDisplay = document.querySelector('.stat-item .stat-value');
    
    if (!viewedProperties.includes(propertyId)) {
        // First time viewing - increment via AJAX
        fetch('increment_property_view.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'property_id=' + propertyId
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // Update displayed view count in real-time
                if (viewsDisplay) viewsDisplay.textContent = data.views.toLocaleString();
                // Mark as viewed so it won't increment again
                viewedProperties.push(propertyId);
                localStorage.setItem('viewedProperties', JSON.stringify(viewedProperties));
            }
        })
        .catch(err => console.error('View count error:', err));
    }
})();

// ============================
// Price History Chart
// ============================
(function() {
    const rawData = <?php echo json_encode($price_history_raw ?? []); ?>;
    if (!rawData || rawData.length === 0) return;

    const canvas = document.getElementById('priceHistoryChart');
    if (!canvas) return;

    const ctx = canvas.getContext('2d');
    const dateFromInput = document.getElementById('phDateFrom');
    const dateToInput = document.getElementById('phDateTo');
    const resetBtn = document.getElementById('phResetFilter');
    const noDataMsg = document.getElementById('phNoData');
    const chartWrapper = canvas.closest('.ph-chart-wrapper');
    const statsRow = document.getElementById('phStatsRow');

    // Today's date string for max attribute
    const todayStr = new Date().toISOString().split('T')[0];

    // Prevent future date selection
    dateFromInput.setAttribute('max', todayStr);
    dateToInput.setAttribute('max', todayStr);

    // Set default range from data
    const allDates = rawData.map(d => d.date);
    const minDate = allDates[0];
    const maxDate = allDates[allDates.length - 1];
    dateFromInput.value = minDate;
    dateToInput.value = maxDate > todayStr ? todayStr : maxDate;

    // Ensure "from" cannot be after "to" and vice versa
    dateFromInput.addEventListener('change', function() {
        if (dateToInput.value && this.value > dateToInput.value) {
            this.value = dateToInput.value;
        }
        dateToInput.setAttribute('min', this.value);
        updateChart();
    });

    dateToInput.addEventListener('change', function() {
        if (dateFromInput.value && this.value < dateFromInput.value) {
            this.value = dateFromInput.value;
        }
        dateFromInput.setAttribute('max', this.value > todayStr ? todayStr : this.value);
        updateChart();
    });

    resetBtn.addEventListener('click', function() {
        dateFromInput.value = minDate;
        dateToInput.value = maxDate > todayStr ? todayStr : maxDate;
        dateFromInput.setAttribute('max', todayStr);
        dateToInput.setAttribute('min', '');
        updateChart();
    });

    // Format peso
    function formatPeso(val) {
        return '\u20b1' + val.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // Filter data
    function getFilteredData() {
        const from = dateFromInput.value;
        const to = dateToInput.value;
        return rawData.filter(d => {
            if (from && d.date < from) return false;
            if (to && d.date > to) return false;
            return true;
        });
    }

    // Build stats
    function updateStats(data) {
        if (!statsRow) return;
        if (data.length === 0) {
            statsRow.innerHTML = '';
            return;
        }

        const prices = data.map(d => d.price);
        const latest = prices[prices.length - 1];
        const earliest = prices[0];
        const highest = Math.max(...prices);
        const lowest = Math.min(...prices);
        const overallChange = earliest > 0 ? ((latest - earliest) / earliest) * 100 : 0;
        const changeDir = overallChange > 0 ? 'stat-up' : (overallChange < 0 ? 'stat-down' : '');
        const changeIcon = overallChange > 0 ? '<i class="bi bi-arrow-up-short"></i>' : (overallChange < 0 ? '<i class="bi bi-arrow-down-short"></i>' : '');

        statsRow.innerHTML = `
            <div class="ph-stat-card">
                <div class="ph-stat-label">Current Price</div>
                <div class="ph-stat-value">${formatPeso(latest)}</div>
            </div>
            <div class="ph-stat-card">
                <div class="ph-stat-label">Highest</div>
                <div class="ph-stat-value" style="color:#4ade80;">${formatPeso(highest)}</div>
            </div>
            <div class="ph-stat-card">
                <div class="ph-stat-label">Lowest</div>
                <div class="ph-stat-value" style="color:#f87171;">${formatPeso(lowest)}</div>
            </div>
            <div class="ph-stat-card">
                <div class="ph-stat-label">Overall Change</div>
                <div class="ph-stat-value ${changeDir}">${changeIcon}${Math.abs(overallChange).toFixed(2)}%</div>
            </div>
        `;
    }

    // Chart instance
    let chart = null;

    function buildChart(data) {
        if (chart) chart.destroy();

        const labels = data.map(d => d.date);
        const prices = data.map(d => d.price);

        // Gradient fill
        const gradient = ctx.createLinearGradient(0, 0, 0, canvas.parentElement.clientHeight);
        gradient.addColorStop(0, 'rgba(212, 175, 55, 0.35)');
        gradient.addColorStop(0.5, 'rgba(212, 175, 55, 0.08)');
        gradient.addColorStop(1, 'rgba(212, 175, 55, 0)');

        chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Price',
                    data: prices,
                    borderColor: '#d4af37',
                    backgroundColor: gradient,
                    borderWidth: 2.5,
                    fill: true,
                    tension: 0.35,
                    pointBackgroundColor: '#d4af37',
                    pointBorderColor: '#0a0a0a',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 8,
                    pointHoverBackgroundColor: '#f4d03f',
                    pointHoverBorderColor: '#0a0a0a',
                    pointHoverBorderWidth: 3,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(10, 10, 10, 0.95)',
                        borderColor: 'rgba(212, 175, 55, 0.4)',
                        borderWidth: 1,
                        titleColor: '#d4af37',
                        bodyColor: '#ffffff',
                        titleFont: { size: 12, weight: '600', family: 'Inter' },
                        bodyFont: { size: 14, weight: '700', family: 'Inter' },
                        padding: 14,
                        cornerRadius: 8,
                        displayColors: false,
                        callbacks: {
                            title: function(items) {
                                const d = new Date(items[0].label + 'T00:00:00');
                                return d.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
                            },
                            label: function(item) {
                                return formatPeso(item.raw);
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        type: 'category',
                        grid: {
                            color: 'rgba(255, 255, 255, 0.04)',
                            drawBorder: false,
                        },
                        ticks: {
                            color: '#7a8a99',
                            font: { size: 11, family: 'Inter', weight: '500' },
                            maxRotation: 45,
                            callback: function(value, index) {
                                const dateStr = this.getLabelForValue(index);
                                const d = new Date(dateStr + 'T00:00:00');
                                return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                            }
                        },
                        border: { display: false }
                    },
                    y: {
                        grid: {
                            color: 'rgba(255, 255, 255, 0.04)',
                            drawBorder: false,
                        },
                        ticks: {
                            color: '#7a8a99',
                            font: { size: 11, family: 'Inter', weight: '500' },
                            callback: function(value) {
                                if (value >= 1000000) return '\u20b1' + (value / 1000000).toFixed(1) + 'M';
                                if (value >= 1000) return '\u20b1' + (value / 1000).toFixed(0) + 'K';
                                return '\u20b1' + value.toLocaleString();
                            },
                            maxTicksLimit: 6,
                        },
                        border: { display: false },
                        beginAtZero: false,
                    }
                },
                animation: {
                    duration: 700,
                    easing: 'easeInOutQuart',
                }
            }
        });
    }

    function updateChart() {
        const filtered = getFilteredData();
        updateStats(filtered);

        if (filtered.length === 0) {
            if (chart) chart.destroy();
            chart = null;
            chartWrapper.style.display = 'none';
            noDataMsg.style.display = 'block';
        } else {
            chartWrapper.style.display = 'block';
            noDataMsg.style.display = 'none';
            buildChart(filtered);
        }
    }

    // Initial render
    updateChart();
})();

</script>

</body>
</html>
